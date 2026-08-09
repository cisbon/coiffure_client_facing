<?php
/**
 * AI Virtual Styling Consultation API Endpoint
 * Integrates with Open Router API for AI-powered style generation.
 *
 * Serves every AI stylist in the tablet app. The optional `consultation_type`
 * field selects which transformation the model is asked for (see
 * CONSULTATION_PROMPTS below); it defaults to 'hairstyle' so older clients that
 * don't send the field keep working unchanged.
 */

require_once __DIR__ . '/config.php';

// Set CORS headers FIRST, before anything that could fail.
// A failure above this line (a helper that did not make it onto the server, a
// syntax error, a bad permission) produces a 500 with no CORS headers at all,
// which the browser cannot show to the page: fetch() just rejects and Safari
// reports the useless "Load failed". With the headers already sent, every
// later failure arrives as a readable JSON error instead.
setCorsHeaders();

// Handle the preflight before any other work.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Quota metering is a business feature; generating the image is the product.
 * If the metering helpers are not on the server (a partial deploy, say), log it
 * and run unmetered rather than taking the AI stylists down with them.
 */
$aiUsageAvailable = @include_once __DIR__ . '/ai_usage_helpers.php';
if (!$aiUsageAvailable || !function_exists('aiUsageSnapshot')) {
    error_log('ai-consultation: ai_usage_helpers.php unavailable — running without quota metering');
    $aiUsageAvailable = false;
}

/** Only needed to map a session onto its salons; same defensive treatment. */
$aiPermissionsAvailable = @include_once __DIR__ . '/permissions.php';
if (!$aiPermissionsAvailable || !function_exists('getAccessibleSalonIds')) {
    $aiPermissionsAvailable = false;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('Method not allowed. Use POST.', 405);
}

// Check if OpenRouter API key is configured
if (empty(OPENROUTER_API_KEY)) {
    sendErrorResponse('OpenRouter API key not configured', 500);
}

// Get request content type
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

// Parse request data (handle multipart/form-data for file upload)
$requestData = [];
$imageFile = null;
$imageBase64 = null;

if (strpos($contentType, 'multipart/form-data') !== false) {
    // Form data with file upload
    $requestData = $_POST;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $imageFile = $_FILES['image'];
    }
} elseif (strpos($contentType, 'application/json') !== false) {
    // JSON request with base64 image
    $jsonInput = file_get_contents('php://input');
    $requestData = json_decode($jsonInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        sendErrorResponse('Invalid JSON: ' . json_last_error_msg(), 400);
    }

    if (isset($requestData['image_base64'])) {
        $imageBase64 = $requestData['image_base64'];
    }
} else {
    $requestData = $_POST;
}

// Validate required fields
$requiredFields = ['style_prompt'];

$validation = validateRequiredFields($requestData, $requiredFields);
if ($validation !== null) {
    sendErrorResponse($validation['message'], 400, ['missing_fields' => $validation['missing_fields']]);
}

// Check if image is provided
if ($imageFile === null && $imageBase64 === null) {
    sendErrorResponse('Image is required (either as file upload or base64)', 400);
}

// Prompt template per stylist. Adding a stylist = adding one entry here plus a
// matching config in the front-end AI stylist engine. {style} is replaced with
// the customer's style request.
const CONSULTATION_PROMPTS = [
    'hairstyle' => "Generate a photo-realistic image of this same person with a new hairstyle: {style}\n\nKeep their face, skin tone, and features identical. Only change the hair.",
    'eyebrows'  => "Generate a photo-realistic image of this same person with new eyebrows: {style}\n\nKeep their face, skin tone, hair, make-up and all other features identical. Only change the eyebrows, and keep the result natural and well-groomed.",
];

// Sanitize input
$stylePrompt = sanitizeInput($requestData['style_prompt']);
$consultationType = sanitizeInput($requestData['consultation_type'] ?? 'hairstyle');
if (!isset(CONSULTATION_PROMPTS[$consultationType])) {
    sendErrorResponse('Unknown consultation_type: ' . $consultationType, 400);
}
$customerId = isset($requestData['customer_id']) ? (int)$requestData['customer_id'] : null;

// Validate and process image
$imageData = null;

if ($imageFile !== null) {
    // Validate uploaded file
    $validation = validateImageUpload($imageFile);
    if (!$validation['success']) {
        sendErrorResponse($validation['message'], 400);
    }

    // Read image file and convert to base64
    $imageContent = file_get_contents($imageFile['tmp_name']);
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $imageFile['tmp_name']);
    finfo_close($finfo);

    $imageData = 'data:' . $mimeType . ';base64,' . base64_encode($imageContent);

} elseif ($imageBase64 !== null) {
    // Validate base64 image format
    if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,/', $imageBase64)) {
        sendErrorResponse('Invalid image data format', 400);
    }

    // Check image size
    $imageSize = strlen($imageBase64);
    if ($imageSize > MAX_UPLOAD_SIZE * 1.5) { // Base64 is ~33% larger
        sendErrorResponse('Image data too large', 400);
    }

    $imageData = $imageBase64;
}

// Get database connection
$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

// ------------------------------------------------------------------
// Resolve the salon that pays for this image
// ------------------------------------------------------------------
// The endpoint stays unauthenticated (the kiosk runs without a customer
// login), but images cost money and are metered per salon, so a posted
// salon_id should not be able to spend another salon's allowance. When the
// tablet sends its session token the salon is taken from that session;
// otherwise we fall back to the posted id, as before.
//
// The token is read from the request body rather than an Authorization
// header on purpose: an Authorization header changes the CORS preflight and
// is stripped or rejected outright by some shared hosts, which would break
// image generation for everyone to harden a low-risk, insider-only case.
$salonId = (int)($requestData['salon_id'] ?? DEFAULT_SALON_ID);
$sessionUser = null;
$sessionTokenValue = $requestData['session_token'] ?? getSessionToken();
if ($sessionTokenValue && $aiPermissionsAvailable) {
    $sessionUser = validateSession($conn, $sessionTokenValue);
}
if ($sessionUser) {
    $accessible = getAccessibleSalonIds($conn, $sessionUser);
    if (!empty($accessible) && !in_array($salonId, $accessible, true)) {
        $salonId = $accessible[0];
    }
}
if ($salonId < 1) {
    $salonId = (int)DEFAULT_SALON_ID;
}

// ------------------------------------------------------------------
// Quota check — before spending anything at OpenRouter
// ------------------------------------------------------------------
// The snapshot is taken now and reused after the generation to book the
// image, so the decision that let this request through is the same one that
// prices it. Without the metering helpers there is no quota to check.
$quota = $aiUsageAvailable ? aiUsageSnapshot($conn, $salonId) : null;
if ($quota && !$quota['allowed']) {
    error_log("AI consultation blocked for salon $salonId: " . $quota['block_reason']);
    $conn->close();
    sendJsonResponse([
        'success' => ,
        'error' => 'AI image limit reached',
        'code' => 'ai_limit_reached',
        'block_reason' => $quota['block_reason'],
        'usage' => aiUsagePublicState($quota),
    ], 403);
}

// Generate unique session ID
$sessionId = generateSessionId();

// Create consultation record with pending status
$insertStmt = $conn->prepare(
    "INSERT INTO coiffure_ai_consultations
    (salon_id, customer_id, session_id, original_image_data, style_prompt, ai_model, status, ip_address, user_agent)
    VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)"
);

if (!$insertStmt) {
    sendErrorResponse('Database insert preparation failed', 500);
}

$aiModel = AI_MODEL;
$ipAddress = getClientIp();
$userAgent = getUserAgent();

$insertStmt->bind_param(
    "iissssss",
    $salonId,
    $customerId,
    $sessionId,
    $imageData,
    $stylePrompt,
    $aiModel,
    $ipAddress,
    $userAgent
);

if (!$insertStmt->execute()) {
    error_log("AI consultation insert failed: " . $insertStmt->error);
    sendErrorResponse('Failed to create consultation record', 500);
}

$consultationId = $insertStmt->insert_id;
$insertStmt->close();

// Update status to processing
$updateStmt = $conn->prepare("UPDATE coiffure_ai_consultations SET status = 'processing' WHERE consultation_id = ?");
$updateStmt->bind_param("i", $consultationId);
$updateStmt->execute();
$updateStmt->close();

// Log audit trail
logAudit($conn, 'ai_consultation', $consultationId, 'create', 'AI consultation started', 'ai_form');

// Prepare OpenRouter API request
$startTime = microtime(true);

// Log the request
error_log("=== AI CONSULTATION START ===");
error_log("Model: " . $aiModel);
error_log("Style Prompt: " . $stylePrompt);
error_log("Image Data Length: " . strlen($imageData));

// Validate that the model is an image generation model (OpenRouter models with input=image, output=image)
$imageGenerationModels = [
    'google/gemini-3-pro-image-preview',     // Nano Banana Pro - most advanced
    'openai/gpt-5-image-mini',               // GPT-5 Image Mini - efficient
    'openai/gpt-5-image',                    // GPT-5 Image - highest quality
    'google/gemini-2.5-flash-image',         // Nano Banana - GA version
    'google/gemini-2.5-flash-image-preview',  // Nano Banana - preview version
    'google/gemini-2.5-flash-image-preview',  // Nano Banana - preview version
    'qwen/qwen-image-3-pro',
    'google/gemini-3.1-flash-lite-image'
];

$isImageGenerationModel = false;
foreach ($imageGenerationModels as $genModel) {
    if (stripos($aiModel, $genModel) !== false || $aiModel === $genModel) {
        $isImageGenerationModel = true;
        break;
    }
}

if (!$isImageGenerationModel) {
    error_log("ERROR: Model '$aiModel' is NOT an image generation model!");
    error_log("Allowed image generation models: " . implode(', ', $imageGenerationModels));

    $failStmt = $conn->prepare(
        "UPDATE coiffure_ai_consultations SET status = 'failed', error_message = ?, processing_time_ms = 0 WHERE consultation_id = ?"
    );
    $errorMsg = "Model '$aiModel' cannot generate images. Please use an OpenRouter image generation model like: google/gemini-2.5-flash-image, openai/gpt-5-image-mini, or google/gemini-3-pro-image-preview";
    $failStmt->bind_param("si", $errorMsg, $consultationId);
    $failStmt->execute();
    $failStmt->close();
    $conn->close();
    sendErrorResponse($errorMsg, 400);
}

// Create the prompt for the requested transformation.
// Use simple, positive, action-oriented instructions.
$prompt = str_replace('{style}', $stylePrompt, CONSULTATION_PROMPTS[$consultationType]);

error_log("Consultation Type: " . $consultationType);
error_log("AI Prompt: " . $prompt);

// Verify uploaded image data
error_log("=== UPLOADED IMAGE VERIFICATION ===");
error_log("Image data length: " . strlen($imageData));
error_log("Image data start: " . substr($imageData, 0, 100));
$imageDataFormat = preg_match('/^data:image\/([a-z]+);base64,/', $imageData, $matches) ? $matches[1] : 'unknown';
error_log("Image format detected: " . $imageDataFormat);

// Gemini image models use chat/completions endpoint with messages format
// CRITICAL: Must include modalities parameter for image generation
// Send text prompt first, then the image
$apiPayload = [
    'model' => $aiModel,
    'modalities' => ['text', 'image'],  // Required for image generation/editing
    'messages' => [
        [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $prompt
                ],
                [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => $imageData
                    ]
                ]
            ]
        ]
    ],
    'max_tokens' => 2048,
    'temperature' => 0.7
];

error_log("=== API REQUEST PAYLOAD ===");
error_log("Model: " . $aiModel);
error_log("Modalities: " . json_encode($apiPayload['modalities']));
error_log("Message content items: " . count($apiPayload['messages'][0]['content']));
error_log("Content[0] type: " . $apiPayload['messages'][0]['content'][0]['type']);
error_log("Content[1] type: " . $apiPayload['messages'][0]['content'][1]['type']);
// Text is now at [0], image is at [1]
error_log("Content[1] has image_url: " . (isset($apiPayload['messages'][0]['content'][1]['image_url']['url']) ? 'YES' : 'NO'));
error_log("Image URL in payload length: " . strlen($apiPayload['messages'][0]['content'][1]['image_url']['url']));
error_log("Max tokens: " . $apiPayload['max_tokens']);
error_log("Temperature: " . $apiPayload['temperature']);
error_log("FULL PAYLOAD JSON: " . json_encode($apiPayload));

$ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'Content-Type: application/json',
        'HTTP-Referer: https://clouedo.com',
        'X-Title: Coiffure AI Virtual Consultation'
    ],
    CURLOPT_POSTFIELDS => json_encode($apiPayload),
    CURLOPT_TIMEOUT => 60
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlInfo = curl_getinfo($ch);
curl_close($ch);

$endTime = microtime(true);
$processingTime = round(($endTime - $startTime) * 1000);

error_log("=== CURL RESPONSE DETAILS ===");
error_log("API Response HTTP Code: " . $httpCode);
error_log("Processing Time: " . $processingTime . "ms");
error_log("Content Type: " . ($curlInfo['content_type'] ?? 'unknown'));
error_log("Total cURL time: " . ($curlInfo['total_time'] ?? 0) . " seconds");
error_log("Response size: " . strlen($response) . " bytes");
error_log("RAW API RESPONSE (first 5000 chars): " . substr($response, 0, 5000));

// Check for cURL errors
if ($response === false) {
    $errorMsg = 'API request failed: ' . $curlError;
    error_log("ERROR: " . $errorMsg);

    $failStmt = $conn->prepare(
        "UPDATE coiffure_ai_consultations SET status = 'failed', error_message = ?, processing_time_ms = ? WHERE consultation_id = ?"
    );
    $failStmt->bind_param("sii", $errorMsg, $processingTime, $consultationId);
    $failStmt->execute();
    $failStmt->close();
    $conn->close();
    sendErrorResponse($errorMsg, 500);
}

// Parse API response
$apiResponse = json_decode($response, true);
$jsonError = json_last_error();
error_log("=== JSON PARSING ===");
error_log("JSON decode error code: " . $jsonError);
if ($jsonError !== JSON_ERROR_NONE) {
    error_log("JSON ERROR: " . json_last_error_msg());
}
error_log("API Response is array: " . (is_array($apiResponse) ? 'YES' : 'NO'));
if (is_array($apiResponse)) {
    error_log("API Response Structure (keys): " . json_encode(array_keys($apiResponse)));
    error_log("Full API Response (formatted): " . json_encode($apiResponse, JSON_PRETTY_PRINT));
} else {
    error_log("API Response is NOT an array - type: " . gettype($apiResponse));
}

// Check for API errors
if ($httpCode !== 200) {
    $errorMsg = isset($apiResponse['error']['message']) ? $apiResponse['error']['message'] : 'Unknown API error';
    error_log("ERROR: API returned " . $httpCode . " - " . $errorMsg);
    error_log("Full Error Response: " . $response);

    $failStmt = $conn->prepare(
        "UPDATE coiffure_ai_consultations SET status = 'failed', error_message = ?, processing_time_ms = ?, ai_response_data = ? WHERE consultation_id = ?"
    );
    $responseJson = json_encode($apiResponse);
    $failStmt->bind_param("sisi", $errorMsg, $processingTime, $responseJson, $consultationId);
    $failStmt->execute();
    $failStmt->close();
    $conn->close();
    sendErrorResponse('AI service error: ' . $errorMsg, 500);
}

// Extract generated image from chat response
// According to OpenRouter docs, images are in message.images array
$generatedImageBase64 = null;
$tokensUsed = 0;
$textResponse = '';

error_log("=== PARSING API RESPONSE FOR IMAGES ===");
error_log("Checking if choices array exists: " . (isset($apiResponse['choices']) ? 'YES' : 'NO'));
if (isset($apiResponse['choices'])) {
    error_log("Number of choices: " . count($apiResponse['choices']));
    error_log("Checking if choices[0] exists: " . (isset($apiResponse['choices'][0]) ? 'YES' : 'NO'));
}

if (isset($apiResponse['choices'][0]['message'])) {
    $message = $apiResponse['choices'][0]['message'];
    error_log("MESSAGE FOUND - Full message object (formatted): " . json_encode($message, JSON_PRETTY_PRINT));
    error_log("Message keys: " . json_encode(array_keys($message)));
    error_log("Message role: " . ($message['role'] ?? 'NOT SET'));
    error_log("Message has 'content' key: " . (isset($message['content']) ? 'YES' : 'NO'));
    error_log("Message has 'images' key: " . (isset($message['images']) ? 'YES' : 'NO'));
    error_log("Message has 'refusal' key: " . (isset($message['refusal']) ? 'YES' : 'NO'));
    error_log("Message has 'reasoning' key: " . (isset($message['reasoning']) ? 'YES' : 'NO'));

    // Get text content if present
    if (isset($message['content'])) {
        $textResponse = is_string($message['content']) ? $message['content'] : json_encode($message['content']);
        error_log("Text content: " . substr($textResponse, 0, 200) . "...");
    }

    // Check for images array (OpenRouter image generation format)
    if (isset($message['images']) && is_array($message['images'])) {
        error_log("Found images array with " . count($message['images']) . " images");

        foreach ($message['images'] as $index => $image) {
            error_log("Image $index structure: " . json_encode(array_keys($image)));

            // Images are in format: { "type": "image_url", "image_url": { "url": "data:image/png;base64,..." } }
            if (isset($image['image_url']['url'])) {
                $imageUrl = $image['image_url']['url'];
                error_log("Found image URL: " . substr($imageUrl, 0, 100) . "...");

                // Check if it's a data URI (base64)
                if (strpos($imageUrl, 'data:image/') === 0) {
                    $generatedImageBase64 = $imageUrl;
                    error_log("SUCCESS! Got base64 image, length: " . strlen($imageUrl));
                    break; // Use first image
                } else {
                    // External URL - download it
                    error_log("Downloading external image URL...");
                    $imageContent = @file_get_contents($imageUrl);
                    if ($imageContent !== false) {
                        $generatedImageBase64 = 'data:image/png;base64,' . base64_encode($imageContent);
                        error_log("SUCCESS! Downloaded image (" . strlen($imageContent) . " bytes)");
                        break;
                    } else {
                        error_log("ERROR: Failed to download external image");
                    }
                }
            }
        }
    } else {
        error_log("WARNING: No images array in message!");
    }
} else {
    error_log("ERROR: No choices[0].message in response!");
}

if (isset($apiResponse['usage']['total_tokens'])) {
    $tokensUsed = $apiResponse['usage']['total_tokens'];
    error_log("Tokens Used: " . $tokensUsed);
}

error_log("Generated Image: " . ($generatedImageBase64 ? 'YES (' . strlen($generatedImageBase64) . ' chars)' : 'NO'));
error_log("Text Response: " . ($textResponse ? 'YES (' . strlen($textResponse) . ' chars)' : 'NO'));

// CRITICAL: If no image was generated, this is a failure
if (!$generatedImageBase64) {
    error_log("CRITICAL ERROR: No image was generated!");
    error_log("Full API Response: " . json_encode($apiResponse));

    // Create detailed error message showing what was returned
    $refusalReason = isset($apiResponse['choices'][0]['message']['refusal']) ? $apiResponse['choices'][0]['message']['refusal'] : null;
    $reasoning = isset($apiResponse['choices'][0]['message']['reasoning']) ? $apiResponse['choices'][0]['message']['reasoning'] : null;

    error_log("=== REFUSAL DETAILS ===");
    error_log("Refusal field type: " . gettype($refusalReason));
    error_log("Refusal value: " . json_encode($refusalReason));
    error_log("Reasoning field type: " . gettype($reasoning));
    error_log("Reasoning value: " . json_encode($reasoning));
    error_log("Text response type: " . gettype($textResponse));
    error_log("Text response: " . $textResponse);

    $errorDetails = [
        'message_keys' => isset($apiResponse['choices'][0]['message']) ? array_keys($apiResponse['choices'][0]['message']) : [],
        'has_images_array' => isset($apiResponse['choices'][0]['message']['images']),
        'has_content' => isset($apiResponse['choices'][0]['message']['content']),
        'refusal' => $refusalReason,
        'reasoning' => $reasoning,
        'content_preview' => substr($textResponse, 0, 200)
    ];
    error_log("Error details: " . json_encode($errorDetails));

    $failStmt = $conn->prepare(
        "UPDATE coiffure_ai_consultations SET status = 'failed', error_message = 'No image generated - model returned text only', processing_time_ms = ?, ai_response_data = ? WHERE consultation_id = ?"
    );
    $responseJson = json_encode($apiResponse);
    $failStmt->bind_param("isi", $processingTime, $responseJson, $consultationId);
    $failStmt->execute();
    $failStmt->close();
    $conn->close();

    // Return detailed error to help diagnose
    $errorMsg = 'Image generation failed. Model: ' . $aiModel;

    // Build detailed debug info
    $debugInfo = [
        'refusal_type' => gettype($refusalReason),
        'refusal_value' => $refusalReason,
        'reasoning_type' => gettype($reasoning),
        'reasoning_value' => $reasoning,
        'text_response' => substr($textResponse, 0, 300),
        'message_keys' => $errorDetails['message_keys']
    ];

    if ($refusalReason) {
        $errorMsg .= '. REFUSAL: ' . json_encode($refusalReason);
    } elseif ($reasoning) {
        $errorMsg .= '. REASONING: ' . json_encode($reasoning);
    } else {
        $errorMsg .= '. Response had ' . json_encode($errorDetails['message_keys']) . '. Text: ' . substr($textResponse, 0, 100);
    }

    $errorMsg .= '. DEBUG: ' . json_encode($debugInfo);
    sendErrorResponse($errorMsg, 500);
}

error_log("=== AI CONSULTATION END - SUCCESS ===");
$generatedContent = "Generated image for: {$stylePrompt}";

// Update consultation record with results
$generatedImageUrl = null; // We're using base64, not URLs
$completeStmt = $conn->prepare(
    "UPDATE coiffure_ai_consultations
    SET
        status = 'completed',
        ai_response_data = ?,
        generated_image_url = ?,
        processing_time_ms = ?,
        api_tokens_used = ?,
        completed_at = CURRENT_TIMESTAMP
    WHERE consultation_id = ?"
);

$responseJson = json_encode($apiResponse);
$completeStmt->bind_param("ssiii", $responseJson, $generatedImageUrl, $processingTime, $tokensUsed, $consultationId);

if (!$completeStmt->execute()) {
    error_log("Failed to update consultation: " . $completeStmt->error);
}

$completeStmt->close();

// Book the delivered image against the salon's allowance. Only successful
// generations are metered, and the pre-flight snapshot decides whether this
// one is included or billed as overage.
//
// This is deliberately the only database work after the OpenRouter call. The
// request has already been open for 15-30s by now, and every extra query here
// is time spent inside whatever execution limit the host imposes; the tablet
// re-reads the allowance when a stylist is opened anyway.
if ($aiUsageAvailable) {
    aiUsageRecord($conn, $salonId, $consultationId, $consultationType, $quota);
}
$conn->close();

// Return success response
sendJsonResponse([
    'success' => true,
    'message' => 'AI consultation completed successfully',
    'consultation_id' => $consultationId,
    'session_id' => $sessionId,
    'ai_response' => $generatedContent,
    'generated_image' => $generatedImageBase64,  // Frontend expects this field
    'generated_image_url' => $generatedImageUrl,
    'processing_time_ms' => $processingTime,
    'tokens_used' => $tokensUsed,
    'model_used' => $aiModel,
    // The pre-flight state plus the image just booked — accurate without a
    // second round of counting queries.
    'usage' => $quota ? array_merge(aiUsagePublicState($quota), [
        'used' => (int)$quota['used'] + 1,
        'remaining' => $quota['remaining'] === null ? null : max(0, (int)$quota['remaining'] - 1),
    ]) : null
], 200);
