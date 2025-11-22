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

// STEP 1: Analyze the photo with vision model
$analysisPrompt = "Analyze this person's face and hair. Describe their face shape, current hair length and texture, hair color, and skin tone. Be concise.";

$analysisPayload = [
    'model' => 'anthropic/claude-3-5-sonnet',
    'messages' => [
        [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $analysisPrompt
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
    'max_tokens' => 500
];

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
    CURLOPT_POSTFIELDS => json_encode($analysisPayload),
    CURLOPT_TIMEOUT => 30
]);

$analysisResponse = curl_exec($ch);
$analysisHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$analysisCurlError = curl_error($ch);
curl_close($ch);

if ($analysisResponse === false || $analysisHttpCode !== 200) {
    $errorMsg = 'Face analysis failed: ' . $analysisCurlError;
    $failStmt = $conn->prepare(
        "UPDATE coiffure_ai_consultations SET status = 'failed', error_message = ? WHERE consultation_id = ?"
    );
    $failStmt->bind_param("si", $errorMsg, $consultationId);
    $failStmt->execute();
    $failStmt->close();
    $conn->close();
    sendErrorResponse($errorMsg, 500);
}

$analysisResult = json_decode($analysisResponse, true);
$faceAnalysis = $analysisResult['choices'][0]['message']['content'] ?? 'Unable to analyze face';

// STEP 2: Generate image with the new hairstyle
$generationPrompt = "Professional salon photograph of a person with these features: {$faceAnalysis}. Transform their hairstyle to: {$stylePrompt}. Style: Professional photography, salon quality, natural lighting, photorealistic, front-facing portrait.";

$imageGenPayload = [
    'model' => $aiModel,
    'prompt' => $generationPrompt,
    'n' => 1,
    'size' => '1024x1024',
    'response_format' => 'url'
];

// For models that support img2img
if (strpos($aiModel, 'flux') !== false || strpos($aiModel, 'stable-diffusion') !== false) {
    $imageGenPayload['image'] = $imageData;
    $imageGenPayload['strength'] = 0.75;
}

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
    CURLOPT_POSTFIELDS => json_encode($imageGenPayload),
    CURLOPT_TIMEOUT => 60
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$endTime = microtime(true);
$processingTime = round(($endTime - $startTime) * 1000);

// Parse image generation response
$apiResponse = json_decode($response, true);
$generatedContent = $faceAnalysis;
$tokensUsed = 0;
$generatedImageUrl = null;
$generatedImageBase64 = null;

if ($httpCode === 200 && isset($apiResponse['data'][0])) {
    // Try to get generated image
    if (isset($apiResponse['data'][0]['b64_json'])) {
        $generatedImageBase64 = 'data:image/png;base64,' . $apiResponse['data'][0]['b64_json'];
    } elseif (isset($apiResponse['data'][0]['url'])) {
        $generatedImageUrl = $apiResponse['data'][0]['url'];

        // Download and convert to base64
        $imageContent = @file_get_contents($generatedImageUrl);
        if ($imageContent !== false) {
            $generatedImageBase64 = 'data:image/png;base64,' . base64_encode($imageContent);
        }
    }

    if (isset($apiResponse['usage']['total_tokens'])) {
        $tokensUsed = $apiResponse['usage']['total_tokens'];
    }
} else {
    // Image generation failed - log but continue with text analysis
    error_log("Image generation failed (HTTP $httpCode): " . ($response ?: $curlError));
}

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
