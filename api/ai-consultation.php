<?php
/**
 * AI Virtual Hairstyle Consultation API Endpoint
 * Integrates with Open Router API for AI-powered hairstyle generation
 */

require_once __DIR__ . '/config.php';

// Set CORS headers
setCorsHeaders();

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

// Sanitize input
$stylePrompt = sanitizeInput($requestData['style_prompt']);
$salonId = $requestData['salon_id'] ?? DEFAULT_SALON_ID;
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
    'google/gemini-2.5-flash-image-preview'  // Nano Banana - preview version
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

// Create prompt for hairstyle transformation
$prompt = "Transform this person's hairstyle to: {$stylePrompt}. Generate a professional salon photograph showing them with this new hairstyle. Keep their face and features the same, only change the hairstyle. The result should look natural and professional, with high-quality lighting and a clean background. Photorealistic, front-facing portrait, salon quality.";

error_log("AI Prompt: " . $prompt);

// Gemini image models use chat/completions endpoint with messages format
$apiPayload = [
    'model' => $aiModel,
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
    'max_tokens' => 4096
];

error_log("API Request: Image Generation via Chat Completions");
error_log("Model: " . $aiModel);
error_log("Using chat/completions endpoint for Gemini image generation");

$ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'Content-Type: application/json',
        'HTTP-Referer: https://clouedo.com',
        'X-Title: SalonLyft Virtual Consultation'
    ],
    CURLOPT_POSTFIELDS => json_encode($apiPayload),
    CURLOPT_TIMEOUT => 60
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$endTime = microtime(true);
$processingTime = round(($endTime - $startTime) * 1000);

error_log("API Response HTTP Code: " . $httpCode);
error_log("Processing Time: " . $processingTime . "ms");

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
error_log("API Response Structure: " . json_encode(array_keys($apiResponse)));

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
$generatedImageBase64 = null;
$tokensUsed = 0;
$textResponse = '';

// Chat completions return content in choices[0].message.content
if (isset($apiResponse['choices'][0]['message']['content'])) {
    $content = $apiResponse['choices'][0]['message']['content'];
    error_log("Response content type: " . gettype($content));

    // Content can be a string or an array of content parts
    if (is_array($content)) {
        error_log("Response has " . count($content) . " content parts");

        // Loop through content parts looking for images
        foreach ($content as $part) {
            if (isset($part['type']) && $part['type'] === 'image_url') {
                if (isset($part['image_url']['url'])) {
                    $imageUrl = $part['image_url']['url'];
                    error_log("Found image URL in content parts: " . substr($imageUrl, 0, 100) . "...");

                    // Check if it's a data URI or external URL
                    if (strpos($imageUrl, 'data:image/') === 0) {
                        $generatedImageBase64 = $imageUrl;
                        error_log("Image is data URI, length: " . strlen($imageUrl));
                    } else {
                        // Download external URL
                        $imageContent = @file_get_contents($imageUrl);
                        if ($imageContent !== false) {
                            $generatedImageBase64 = 'data:image/png;base64,' . base64_encode($imageContent);
                            error_log("Downloaded and encoded image (" . strlen($imageContent) . " bytes)");
                        } else {
                            error_log("ERROR: Failed to download image from URL");
                        }
                    }
                    break;
                }
            } elseif (isset($part['type']) && $part['type'] === 'text') {
                $textResponse .= $part['text'] . "\n";
            }
        }
    } else {
        // Content is a string - this is the text response, not an image
        $textResponse = $content;
        error_log("Response is text only: " . substr($textResponse, 0, 200) . "...");
    }
} else {
    error_log("ERROR: No choices[0].message.content in response!");
    error_log("Response keys: " . json_encode(array_keys($apiResponse)));
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

    $failStmt = $conn->prepare(
        "UPDATE coiffure_ai_consultations SET status = 'failed', error_message = 'No image generated - model returned text only', processing_time_ms = ?, ai_response_data = ? WHERE consultation_id = ?"
    );
    $responseJson = json_encode($apiResponse);
    $failStmt->bind_param("isi", $processingTime, $responseJson, $consultationId);
    $failStmt->execute();
    $failStmt->close();
    $conn->close();
    sendErrorResponse('Image generation failed - model returned text instead of image. Text response: ' . substr($textResponse, 0, 200), 500);
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
    'model_used' => $aiModel
], 200);
