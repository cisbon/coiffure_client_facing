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

// Validate that the model is an image generation model
$imageGenerationModels = [
    'black-forest-labs/flux-1.1-pro',
    'black-forest-labs/flux-1-pro',
    'black-forest-labs/flux-pro',
    'black-forest-labs/flux-dev',
    'stability-ai/stable-diffusion-xl',
    'stability-ai/sdxl-turbo',
    'openai/dall-e-3',
    'openai/dall-e-2',
    'midjourney/midjourney',
    'runway/gen-2'
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
    $errorMsg = "Model '$aiModel' cannot generate images. Please use an image generation model like: black-forest-labs/flux-1.1-pro, openai/dall-e-3, or stability-ai/stable-diffusion-xl";
    $failStmt->bind_param("si", $errorMsg, $consultationId);
    $failStmt->execute();
    $failStmt->close();
    $conn->close();
    sendErrorResponse($errorMsg, 400);
}

// Create prompt for hairstyle transformation
$prompt = "A professional salon photograph of a person with a {$stylePrompt} hairstyle. The person should look natural and professional, with high-quality lighting and a clean background. Photorealistic, front-facing portrait, salon quality.";

error_log("AI Prompt: " . $prompt);

// Prepare image generation API payload
$apiPayload = [
    'model' => $aiModel,
    'prompt' => $prompt,
    'n' => 1,
    'size' => '1024x1024'
];

// For models that support img2img (input image transformation)
if (stripos($aiModel, 'flux') !== false || stripos($aiModel, 'stable-diffusion') !== false) {
    $apiPayload['image'] = $imageData;
    $apiPayload['strength'] = 0.75; // How much to transform (0-1)
    error_log("Using img2img mode with strength 0.75");
}

error_log("API Request: Image Generation");
error_log("Model: " . $aiPayload['model']);
error_log("Prompt: " . $prompt);
error_log("Has input image: " . (isset($apiPayload['image']) ? 'YES' : 'NO'));

$ch = curl_init('https://openrouter.ai/api/v1/images/generations');
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

// Extract generated image from response
$generatedImageBase64 = null;
$tokensUsed = 0;

// Image generation responses come in 'data' array
if (isset($apiResponse['data']) && is_array($apiResponse['data']) && count($apiResponse['data']) > 0) {
    error_log("Response has 'data' array with " . count($apiResponse['data']) . " items");

    $imageData = $apiResponse['data'][0];

    // Check for base64 encoded image
    if (isset($imageData['b64_json'])) {
        $generatedImageBase64 = 'data:image/png;base64,' . $imageData['b64_json'];
        error_log("Found base64 image in data[0].b64_json");
    }
    // Check for image URL
    elseif (isset($imageData['url'])) {
        $imageUrl = $imageData['url'];
        error_log("Found image URL in data[0].url: " . $imageUrl);

        // Download and convert to base64
        $imageContent = @file_get_contents($imageUrl);
        if ($imageContent !== false) {
            $generatedImageBase64 = 'data:image/png;base64,' . base64_encode($imageContent);
            error_log("Successfully downloaded and encoded image (" . strlen($imageContent) . " bytes)");
        } else {
            error_log("ERROR: Failed to download image from URL");
        }
    }
} else {
    error_log("ERROR: No 'data' array found in response!");
    error_log("Response keys: " . json_encode(array_keys($apiResponse)));
}

if (isset($apiResponse['usage']['total_tokens'])) {
    $tokensUsed = $apiResponse['usage']['total_tokens'];
    error_log("Tokens Used: " . $tokensUsed);
}

error_log("Generated Image: " . ($generatedImageBase64 ? 'YES (' . strlen($generatedImageBase64) . ' chars)' : 'NO'));

// CRITICAL: If no image was generated, this is a failure
if (!$generatedImageBase64) {
    error_log("CRITICAL ERROR: No image was generated!");
    error_log("Full API Response: " . json_encode($apiResponse));

    $failStmt = $conn->prepare(
        "UPDATE coiffure_ai_consultations SET status = 'failed', error_message = 'No image generated', processing_time_ms = ?, ai_response_data = ? WHERE consultation_id = ?"
    );
    $responseJson = json_encode($apiResponse);
    $failStmt->bind_param("isi", $processingTime, $responseJson, $consultationId);
    $failStmt->execute();
    $failStmt->close();
    $conn->close();
    sendErrorResponse('Image generation failed - no image returned by AI model', 500);
}

error_log("=== AI CONSULTATION END - SUCCESS ===");
$generatedContent = "Generated image for: {$stylePrompt}";

// Update consultation record with results
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
