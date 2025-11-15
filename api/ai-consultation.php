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

$openRouterUrl = 'https://openrouter.ai/api/v1/chat/completions';

// Construct the prompt for hairstyle generation
$systemPrompt = "You are a professional hairstylist AI assistant. Analyze the uploaded photo and generate a new image showing the person with the requested hairstyle. Be creative and professional in your styling suggestions.";

$userPrompt = "Please show this person with the following hairstyle: " . $stylePrompt . ". Keep the person's face and features the same, only modify the hairstyle.";

// Prepare API payload
$apiPayload = [
    'model' => $aiModel,
    'messages' => [
        [
            'role' => 'system',
            'content' => $systemPrompt
        ],
        [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $userPrompt
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
    'max_tokens' => 1024,
    'temperature' => 0.7
];

// Make API request
$ch = curl_init($openRouterUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'Content-Type: application/json',
        'HTTP-Referer: https://salonlyft.app',
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
$processingTime = round(($endTime - $startTime) * 1000); // Convert to milliseconds

// Check for cURL errors
if ($response === false) {
    // Update consultation status to failed
    $errorMsg = 'API request failed: ' . $curlError;
    $failStmt = $conn->prepare(
        "UPDATE coiffure_ai_consultations
        SET status = 'failed', error_message = ?, processing_time_ms = ?
        WHERE consultation_id = ?"
    );
    $failStmt->bind_param("sii", $errorMsg, $processingTime, $consultationId);
    $failStmt->execute();
    $failStmt->close();

    $conn->close();
    sendErrorResponse('Failed to connect to AI service', 500);
}

// Parse API response
$apiResponse = json_decode($response, true);

// Check for API errors
if ($httpCode !== 200) {
    $errorMsg = isset($apiResponse['error']['message']) ? $apiResponse['error']['message'] : 'Unknown API error';

    // Update consultation status to failed
    $failStmt = $conn->prepare(
        "UPDATE coiffure_ai_consultations
        SET status = 'failed', error_message = ?, processing_time_ms = ?, ai_response_data = ?
        WHERE consultation_id = ?"
    );
    $responseJson = json_encode($apiResponse);
    $failStmt->bind_param("sisi", $errorMsg, $processingTime, $responseJson, $consultationId);
    $failStmt->execute();
    $failStmt->close();

    $conn->close();
    sendErrorResponse('AI service error: ' . $errorMsg, 500);
}

// Extract generated content from response
$generatedContent = null;
$tokensUsed = 0;

if (isset($apiResponse['choices'][0]['message']['content'])) {
    $generatedContent = $apiResponse['choices'][0]['message']['content'];
}

if (isset($apiResponse['usage']['total_tokens'])) {
    $tokensUsed = $apiResponse['usage']['total_tokens'];
}

// For vision models, the response might contain text description
// In a production environment, you'd use a model that can generate images
// For now, we'll store the text response
$generatedImageUrl = null;

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
    'generated_image_url' => $generatedImageUrl,
    'processing_time_ms' => $processingTime,
    'tokens_used' => $tokensUsed,
    'model_used' => $aiModel,
    'note' => 'The AI model provides hairstyle analysis and suggestions. For image generation, consider using DALL-E or Stable Diffusion models.'
], 200);
