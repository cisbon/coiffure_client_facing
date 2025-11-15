<?php
/**
 * Customer API Endpoint
 * Handles GDPR-compliant customer onboarding and data storage
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
$requiredFields = [
    'full_name',
    'email',
    'phone',
    'consent_data_processing',
    'consent_cancellation_policy',
    'policy_version'
];

$validation = validateRequiredFields($requestData, $requiredFields);
if ($validation !== null) {
    sendErrorResponse($validation['message'], 400, ['missing_fields' => $validation['missing_fields']]);
}

// Sanitize and validate input
$fullName = sanitizeInput($requestData['full_name']);
$email = sanitizeInput($requestData['email']);
$phone = sanitizeInput($requestData['phone']);
$consentMarketing = isset($requestData['consent_marketing']) ? (bool)$requestData['consent_marketing'] : false;
$consentDataProcessing = (bool)$requestData['consent_data_processing'];
$consentCancellationPolicy = (bool)$requestData['consent_cancellation_policy'];
$policyVersion = sanitizeInput($requestData['policy_version']);
$signatureData = $requestData['signature_data'] ?? null;
$salonId = $requestData['salon_id'] ?? DEFAULT_SALON_ID;

// Validate email
if (!validateEmail($email)) {
    sendErrorResponse('Invalid email address', 400);
}

// Validate phone
if (!validatePhone($phone)) {
    sendErrorResponse('Invalid phone number', 400);
}

// Validate GDPR consent
if (!$consentDataProcessing) {
    sendErrorResponse('Data processing consent is required', 400);
}

if (!$consentCancellationPolicy) {
    sendErrorResponse('Cancellation policy consent is required', 400);
}

// Validate signature data if provided
if ($signatureData !== null && !empty($signatureData)) {
    // Check if it's a valid base64 image
    if (!preg_match('/^data:image\/(png|jpeg|jpg);base64,/', $signatureData)) {
        sendErrorResponse('Invalid signature data format', 400);
    }

    // Check signature data size (max 1MB for signature)
    $signatureSize = strlen($signatureData);
    if ($signatureSize > 1048576) {
        sendErrorResponse('Signature data too large', 400);
    }
}

// Get database connection
$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

// Check if customer already exists (by email)
$stmt = $conn->prepare("SELECT customer_id, full_name FROM coiffure_customers WHERE email = ? AND salon_id = ? AND is_deleted = 0");
if (!$stmt) {
    sendErrorResponse('Database query preparation failed', 500);
}

$stmt->bind_param("si", $email, $salonId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // Customer exists - update consent
    $existingCustomer = $result->fetch_assoc();
    $customerId = $existingCustomer['customer_id'];
    $stmt->close();

    // Update customer consent
    $updateStmt = $conn->prepare(
        "UPDATE coiffure_customers
        SET
            full_name = ?,
            phone = ?,
            consent_marketing = ?,
            consent_data_processing = ?,
            consent_cancellation_policy = ?,
            consent_timestamp = CURRENT_TIMESTAMP,
            policy_version_accepted = ?,
            signature_data = ?,
            signature_timestamp = ?,
            ip_address = ?,
            user_agent = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE customer_id = ?"
    );

    if (!$updateStmt) {
        sendErrorResponse('Database update preparation failed', 500);
    }

    $signatureTimestamp = $signatureData ? date('Y-m-d H:i:s') : null;
    $ipAddress = getClientIp();
    $userAgent = getUserAgent();

    $updateStmt->bind_param(
        "ssiissssssi",
        $fullName,
        $phone,
        $consentMarketing,
        $consentDataProcessing,
        $consentCancellationPolicy,
        $policyVersion,
        $signatureData,
        $signatureTimestamp,
        $ipAddress,
        $userAgent,
        $customerId
    );

    if (!$updateStmt->execute()) {
        error_log("Customer update failed: " . $updateStmt->error);
        sendErrorResponse('Failed to update customer data', 500);
    }

    $updateStmt->close();

    // Log audit trail
    logAudit($conn, 'customer', $customerId, 'update', 'Consent updated', 'customer_form');

    sendJsonResponse([
        'success' => true,
        'message' => 'Customer consent updated successfully',
        'customer_id' => $customerId,
        'action' => 'updated'
    ], 200);

} else {
    // New customer - insert
    $stmt->close();

    $insertStmt = $conn->prepare(
        "INSERT INTO coiffure_customers
        (
            salon_id,
            full_name,
            email,
            phone,
            consent_marketing,
            consent_data_processing,
            consent_cancellation_policy,
            consent_timestamp,
            policy_version_accepted,
            signature_data,
            signature_timestamp,
            ip_address,
            user_agent,
            data_processing_purpose,
            gdpr_consent_notice_shown,
            data_retention_until
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?, 1, ?)"
    );

    if (!$insertStmt) {
        sendErrorResponse('Database insert preparation failed', 500);
    }

    $signatureTimestamp = $signatureData ? date('Y-m-d H:i:s') : null;
    $ipAddress = getClientIp();
    $userAgent = getUserAgent();
    $dataProcessingPurpose = 'Customer relationship management, appointment scheduling, and service delivery';
    $dataRetentionUntil = calculateRetentionDate(3); // 3 years retention

    $insertStmt->bind_param(
        "isssiiisssssss",
        $salonId,
        $fullName,
        $email,
        $phone,
        $consentMarketing,
        $consentDataProcessing,
        $consentCancellationPolicy,
        $policyVersion,
        $signatureData,
        $signatureTimestamp,
        $ipAddress,
        $userAgent,
        $dataProcessingPurpose,
        $dataRetentionUntil
    );

    if (!$insertStmt->execute()) {
        error_log("Customer insert failed: " . $insertStmt->error);
        sendErrorResponse('Failed to save customer data', 500);
    }

    $customerId = $insertStmt->insert_id;
    $insertStmt->close();

    // Log audit trail
    logAudit($conn, 'customer', $customerId, 'create', 'New customer registered', 'customer_form');

    sendJsonResponse([
        'success' => true,
        'message' => 'Customer registered successfully',
        'customer_id' => $customerId,
        'action' => 'created',
        'data_retention_until' => $dataRetentionUntil
    ], 201);
}

$conn->close();
