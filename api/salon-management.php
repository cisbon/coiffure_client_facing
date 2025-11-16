<?php
/**
 * Salon Management API Endpoint
 * Handles CRUD operations for salons (admin and admin_delegate only)
 */

require_once __DIR__ . '/config.php';

// Set CORS headers
setCorsHeaders();

// Get database connection
$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

// Require authentication
$currentUser = requireAuth($conn);

// Only admin and admin_delegate can manage salons
requireRole($currentUser, ['admin', 'admin_delegate']);

// Get request method and parse input
$method = $_SERVER['REQUEST_METHOD'];
$salonId = isset($_GET['salon_id']) ? (int)$_GET['salon_id'] : null;

// Parse JSON input for POST/PUT
$requestData = [];
if (in_array($method, ['POST', 'PUT'])) {
    $jsonInput = file_get_contents('php://input');
    $requestData = json_decode($jsonInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        sendErrorResponse('Invalid JSON: ' . json_last_error_msg(), 400);
    }
}

// Route based on HTTP method
switch ($method) {
    case 'GET':
        handleGetSalons($conn, $salonId);
        break;

    case 'POST':
        handleCreateSalon($conn, $currentUser, $requestData);
        break;

    case 'PUT':
        handleUpdateSalon($conn, $currentUser, $salonId, $requestData);
        break;

    case 'DELETE':
        handleDeleteSalon($conn, $currentUser, $salonId);
        break;

    default:
        sendErrorResponse('Method not allowed', 405);
}

/**
 * Get salons
 */
function handleGetSalons($conn, $salonId) {
    if ($salonId) {
        // Get single salon
        $stmt = $conn->prepare(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM coiffure_users WHERE salon_id = s.salon_id AND is_active = 1) as user_count,
                    (SELECT COUNT(*) FROM coiffure_customers WHERE salon_id = s.salon_id AND is_deleted = 0) as customer_count
             FROM coiffure_salons s
             WHERE salon_id = ?"
        );

        if (!$stmt) {
            sendErrorResponse('Failed to fetch salon', 500);
        }

        $stmt->bind_param("i", $salonId);

        if (!$stmt->execute()) {
            sendErrorResponse('Failed to fetch salon', 500);
        }

        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            sendErrorResponse('Salon not found', 404);
        }

        $salon = $result->fetch_assoc();
        $stmt->close();

        sendJsonResponse([
            'success' => true,
            'salon' => $salon
        ]);
    } else {
        // Get all salons
        $query = "SELECT s.*,
                        (SELECT COUNT(*) FROM coiffure_users WHERE salon_id = s.salon_id AND is_active = 1) as user_count,
                        (SELECT COUNT(*) FROM coiffure_customers WHERE salon_id = s.salon_id AND is_deleted = 0) as customer_count
                  FROM coiffure_salons s";

        // Apply filters
        $conditions = [];
        $params = [];
        $types = '';

        if (isset($_GET['is_active'])) {
            $conditions[] = "s.is_active = ?";
            $params[] = (int)$_GET['is_active'];
            $types .= 'i';
        }

        if (!empty($conditions)) {
            $query .= " WHERE " . implode(' AND ', $conditions);
        }

        $query .= " ORDER BY s.created_at DESC";

        $stmt = $conn->prepare($query);

        if (!$stmt) {
            sendErrorResponse('Failed to fetch salons', 500);
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            sendErrorResponse('Failed to fetch salons', 500);
        }

        $result = $stmt->get_result();
        $salons = [];

        while ($row = $result->fetch_assoc()) {
            $salons[] = $row;
        }

        $stmt->close();

        sendJsonResponse([
            'success' => true,
            'salons' => $salons,
            'count' => count($salons)
        ]);
    }
}

/**
 * Create salon
 */
function handleCreateSalon($conn, $currentUser, $data) {
    // Validate required fields
    $requiredFields = ['salon_name', 'email', 'phone', 'policy_version'];
    $validation = validateRequiredFields($data, $requiredFields);
    if ($validation !== null) {
        sendErrorResponse($validation['message'], 400, ['missing_fields' => $validation['missing_fields']]);
    }

    $salonName = trim($data['salon_name']);
    $email = trim($data['email']);
    $phone = trim($data['phone']);
    $address = isset($data['address']) ? trim($data['address']) : null;
    $googleReviewsUrl = isset($data['google_reviews_url']) ? trim($data['google_reviews_url']) : null;
    $facebookUrl = isset($data['facebook_url']) ? trim($data['facebook_url']) : null;
    $policyVersion = trim($data['policy_version']);
    $cancellationPolicy = isset($data['cancellation_policy']) ? trim($data['cancellation_policy']) : null;
    $dataProcessingPolicy = isset($data['data_processing_policy']) ? trim($data['data_processing_policy']) : null;
    $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

    // Validate email
    if (!validateEmail($email)) {
        sendErrorResponse('Invalid email address', 400);
    }

    // Validate phone
    if (!validatePhone($phone)) {
        sendErrorResponse('Invalid phone number', 400);
    }

    // Insert salon
    $stmt = $conn->prepare(
        "INSERT INTO coiffure_salons
        (salon_name, email, phone, address, google_reviews_url, facebook_url,
         policy_version, cancellation_policy, data_processing_policy, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        error_log("Failed to prepare salon insert: " . $conn->error);
        sendErrorResponse('Failed to create salon', 500);
    }

    $stmt->bind_param(
        "sssssssssi",
        $salonName,
        $email,
        $phone,
        $address,
        $googleReviewsUrl,
        $facebookUrl,
        $policyVersion,
        $cancellationPolicy,
        $dataProcessingPolicy,
        $isActive
    );

    if (!$stmt->execute()) {
        error_log("Failed to create salon: " . $stmt->error);
        sendErrorResponse('Failed to create salon', 500);
    }

    $newSalonId = $stmt->insert_id;
    $stmt->close();

    // Log audit
    logAudit($conn, 'salon', $newSalonId, 'create', "Salon created: $salonName", $currentUser['username']);

    sendJsonResponse([
        'success' => true,
        'message' => 'Salon created successfully',
        'salon_id' => $newSalonId
    ], 201);
}

/**
 * Update salon
 */
function handleUpdateSalon($conn, $currentUser, $salonId, $data) {
    if (!$salonId) {
        sendErrorResponse('Salon ID required', 400);
    }

    // Check if salon exists
    $checkStmt = $conn->prepare("SELECT salon_id FROM coiffure_salons WHERE salon_id = ?");
    if (!$checkStmt) {
        sendErrorResponse('Failed to fetch salon', 500);
    }

    $checkStmt->bind_param("i", $salonId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        $checkStmt->close();
        sendErrorResponse('Salon not found', 404);
    }
    $checkStmt->close();

    // Build update query dynamically
    $updates = [];
    $params = [];
    $types = '';

    $allowedFields = [
        'salon_name' => 's',
        'email' => 's',
        'phone' => 's',
        'address' => 's',
        'google_reviews_url' => 's',
        'facebook_url' => 's',
        'policy_version' => 's',
        'cancellation_policy' => 's',
        'data_processing_policy' => 's',
        'is_active' => 'i'
    ];

    foreach ($allowedFields as $field => $type) {
        if (isset($data[$field])) {
            // Validate email
            if ($field === 'email' && !validateEmail($data[$field])) {
                sendErrorResponse('Invalid email address', 400);
            }

            // Validate phone
            if ($field === 'phone' && !validatePhone($data[$field])) {
                sendErrorResponse('Invalid phone number', 400);
            }

            $updates[] = "$field = ?";
            $params[] = $type === 'i' ? (int)$data[$field] : trim($data[$field]);
            $types .= $type;
        }
    }

    if (empty($updates)) {
        sendErrorResponse('No fields to update', 400);
    }

    // Add salon_id to params
    $params[] = $salonId;
    $types .= 'i';

    // Execute update
    $query = "UPDATE coiffure_salons SET " . implode(', ', $updates) . " WHERE salon_id = ?";
    $updateStmt = $conn->prepare($query);

    if (!$updateStmt) {
        error_log("Failed to prepare salon update: " . $conn->error);
        sendErrorResponse('Failed to update salon', 500);
    }

    $updateStmt->bind_param($types, ...$params);

    if (!$updateStmt->execute()) {
        error_log("Failed to update salon: " . $updateStmt->error);
        sendErrorResponse('Failed to update salon', 500);
    }

    $updateStmt->close();

    // Log audit
    logAudit($conn, 'salon', $salonId, 'update', "Salon updated", $currentUser['username']);

    sendJsonResponse([
        'success' => true,
        'message' => 'Salon updated successfully'
    ]);
}

/**
 * Delete (deactivate) salon
 */
function handleDeleteSalon($conn, $currentUser, $salonId) {
    if (!$salonId) {
        sendErrorResponse('Salon ID required', 400);
    }

    // Check if salon exists
    $checkStmt = $conn->prepare("SELECT salon_name FROM coiffure_salons WHERE salon_id = ?");
    if (!$checkStmt) {
        sendErrorResponse('Failed to fetch salon', 500);
    }

    $checkStmt->bind_param("i", $salonId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        $checkStmt->close();
        sendErrorResponse('Salon not found', 404);
    }

    $salon = $checkResult->fetch_assoc();
    $checkStmt->close();

    // Deactivate salon instead of hard delete
    $deleteStmt = $conn->prepare("UPDATE coiffure_salons SET is_active = 0 WHERE salon_id = ?");

    if (!$deleteStmt) {
        sendErrorResponse('Failed to delete salon', 500);
    }

    $deleteStmt->bind_param("i", $salonId);

    if (!$deleteStmt->execute()) {
        error_log("Failed to delete salon: " . $deleteStmt->error);
        sendErrorResponse('Failed to delete salon', 500);
    }

    $deleteStmt->close();

    // Also deactivate all users from this salon
    $userStmt = $conn->prepare("UPDATE coiffure_users SET is_active = 0 WHERE salon_id = ?");
    if ($userStmt) {
        $userStmt->bind_param("i", $salonId);
        $userStmt->execute();
        $userStmt->close();
    }

    // Log audit
    logAudit($conn, 'salon', $salonId, 'delete', "Salon deactivated: " . $salon['salon_name'], $currentUser['username']);

    sendJsonResponse([
        'success' => true,
        'message' => 'Salon deleted successfully'
    ]);
}
