<?php
/**
 * QR Code Generation API Endpoint
 * Generates and tracks QR codes for reviews and social media
 */

require_once __DIR__ . '/config.php';

// Set CORS headers
setCorsHeaders();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('Method not allowed. Use POST.', 405);
}

// Get request content type
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

// Parse request data
$requestData = null;
if (strpos($contentType, 'application/json') !== false) {
    // JSON request
    $jsonInput = file_get_contents('php://input');
    $requestData = json_decode($jsonInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        sendErrorResponse('Invalid JSON: ' . json_last_error_msg(), 400);
    }
} else {
    // Form data
    $requestData = $_POST;
}

// Validate required fields
$requiredFields = ['target_url', 'qr_type'];

$validation = validateRequiredFields($requestData, $requiredFields);
if ($validation !== null) {
    sendErrorResponse($validation['message'], 400, ['missing_fields' => $validation['missing_fields']]);
}

// Sanitize input
$targetUrl = trim($requestData['target_url']);
$qrType = sanitizeInput($requestData['qr_type']);
$salonId = $requestData['salon_id'] ?? DEFAULT_SALON_ID;
$notes = isset($requestData['notes']) ? sanitizeInput($requestData['notes']) : null;
$createdBy = isset($requestData['created_by']) ? sanitizeInput($requestData['created_by']) : 'system';

// Validate URL
if (!filter_var($targetUrl, FILTER_VALIDATE_URL)) {
    sendErrorResponse('Invalid URL format', 400);
}

// Validate QR type
$validTypes = ['google_reviews', 'facebook', 'instagram', 'custom'];
if (!in_array($qrType, $validTypes)) {
    sendErrorResponse('Invalid QR type. Allowed types: ' . implode(', ', $validTypes), 400);
}

// Get database connection
$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

// Check if QR code already exists for this URL and salon
$stmt = $conn->prepare(
    "SELECT qr_id, generation_count FROM coiffure_qr_codes
    WHERE target_url = ? AND salon_id = ? AND qr_type = ? AND is_active = 1"
);

if (!$stmt) {
    sendErrorResponse('Database query preparation failed', 500);
}

$stmt->bind_param("sis", $targetUrl, $salonId, $qrType);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // QR code exists - update generation count
    $existingQr = $result->fetch_assoc();
    $qrId = $existingQr['qr_id'];
    $generationCount = $existingQr['generation_count'] + 1;
    $stmt->close();

    // Update generation count
    $updateStmt = $conn->prepare(
        "UPDATE coiffure_qr_codes
        SET generation_count = ?,
            last_generated_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE qr_id = ?"
    );

    if (!$updateStmt) {
        sendErrorResponse('Database update preparation failed', 500);
    }

    $updateStmt->bind_param("ii", $generationCount, $qrId);

    if (!$updateStmt->execute()) {
        error_log("QR code update failed: " . $updateStmt->error);
        sendErrorResponse('Failed to update QR code data', 500);
    }

    $updateStmt->close();

    // Log audit trail
    logAudit($conn, 'qr_code', $qrId, 'read', 'QR code regenerated', $createdBy);

    sendJsonResponse([
        'success' => true,
        'message' => 'QR code retrieved (already exists)',
        'qr_id' => $qrId,
        'target_url' => $targetUrl,
        'qr_type' => $qrType,
        'generation_count' => $generationCount,
        'action' => 'retrieved'
    ], 200);

} else {
    // New QR code - insert
    $stmt->close();

    $insertStmt = $conn->prepare(
        "INSERT INTO coiffure_qr_codes
        (salon_id, target_url, qr_type, generation_count, created_by, notes, last_generated_at)
        VALUES (?, ?, ?, 1, ?, ?, CURRENT_TIMESTAMP)"
    );

    if (!$insertStmt) {
        sendErrorResponse('Database insert preparation failed', 500);
    }

    $insertStmt->bind_param(
        "issss",
        $salonId,
        $targetUrl,
        $qrType,
        $createdBy,
        $notes
    );

    if (!$insertStmt->execute()) {
        error_log("QR code insert failed: " . $insertStmt->error);
        sendErrorResponse('Failed to save QR code data', 500);
    }

    $qrId = $insertStmt->insert_id;
    $insertStmt->close();

    // Log audit trail
    logAudit($conn, 'qr_code', $qrId, 'create', 'New QR code created', $createdBy);

    sendJsonResponse([
        'success' => true,
        'message' => 'QR code data saved successfully',
        'qr_id' => $qrId,
        'target_url' => $targetUrl,
        'qr_type' => $qrType,
        'generation_count' => 1,
        'action' => 'created'
    ], 201);
}

$conn->close();
