<?php
/**
 * User Management API Endpoint
 * Handles CRUD operations for users with role-based permissions
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

// Get request method and parse input
$method = $_SERVER['REQUEST_METHOD'];
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

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
        handleGetUsers($conn, $currentUser, $userId);
        break;

    case 'POST':
        handleCreateUser($conn, $currentUser, $requestData);
        break;

    case 'PUT':
        handleUpdateUser($conn, $currentUser, $userId, $requestData);
        break;

    case 'DELETE':
        handleDeleteUser($conn, $currentUser, $userId);
        break;

    default:
        sendErrorResponse('Method not allowed', 405);
}

/**
 * Get users - with filtering based on role
 */
function handleGetUsers($conn, $currentUser, $userId) {
    // If user_id provided, get specific user
    if ($userId) {
        getSingleUser($conn, $currentUser, $userId);
        return;
    }

    // Build query based on user role
    $query = "SELECT u.user_id, u.username, u.email, u.full_name, u.phone, u.role,
                     u.salon_id, s.salon_name, u.is_active, u.email_verified,
                     u.last_login, u.created_at, u.created_by
              FROM coiffure_users u
              LEFT JOIN coiffure_salons s ON u.salon_id = s.salon_id
              WHERE 1=1";

    $params = [];
    $types = '';

    // Filter based on role
    if ($currentUser['role'] === 'customer_admin' || $currentUser['role'] === 'customer_admin_delegate') {
        // Can only see users from their own salon
        $query .= " AND u.salon_id = ?";
        $params[] = $currentUser['salon_id'];
        $types .= 'i';
    }

    // Apply optional filters from query parameters
    if (isset($_GET['salon_id']) && in_array($currentUser['role'], ['admin', 'admin_delegate'])) {
        $query .= " AND u.salon_id = ?";
        $params[] = (int)$_GET['salon_id'];
        $types .= 'i';
    }

    if (isset($_GET['role'])) {
        $query .= " AND u.role = ?";
        $params[] = $_GET['role'];
        $types .= 's';
    }

    if (isset($_GET['is_active'])) {
        $query .= " AND u.is_active = ?";
        $params[] = (int)$_GET['is_active'];
        $types .= 'i';
    }

    $query .= " ORDER BY u.created_at DESC";

    // Prepare and execute
    $stmt = $conn->prepare($query);

    if (!$stmt) {
        error_log("Failed to prepare get users query: " . $conn->error);
        sendErrorResponse('Failed to fetch users', 500);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        error_log("Failed to execute get users query: " . $stmt->error);
        sendErrorResponse('Failed to fetch users', 500);
    }

    $result = $stmt->get_result();
    $users = [];

    while ($row = $result->fetch_assoc()) {
        $users[] = [
            'user_id' => $row['user_id'],
            'username' => $row['username'],
            'email' => $row['email'],
            'full_name' => $row['full_name'],
            'phone' => $row['phone'],
            'role' => $row['role'],
            'salon_id' => $row['salon_id'],
            'salon_name' => $row['salon_name'],
            'is_active' => (bool)$row['is_active'],
            'email_verified' => (bool)$row['email_verified'],
            'last_login' => $row['last_login'],
            'created_at' => $row['created_at'],
            'created_by' => $row['created_by']
        ];
    }

    $stmt->close();

    sendJsonResponse([
        'success' => true,
        'users' => $users,
        'count' => count($users)
    ]);
}

/**
 * Get single user
 */
function getSingleUser($conn, $currentUser, $userId) {
    $stmt = $conn->prepare(
        "SELECT u.user_id, u.username, u.email, u.full_name, u.phone, u.role,
                u.salon_id, s.salon_name, u.is_active, u.email_verified,
                u.last_login, u.last_login_ip, u.created_at, u.updated_at, u.created_by
         FROM coiffure_users u
         LEFT JOIN coiffure_salons s ON u.salon_id = s.salon_id
         WHERE u.user_id = ?"
    );

    if (!$stmt) {
        sendErrorResponse('Failed to fetch user', 500);
    }

    $stmt->bind_param("i", $userId);

    if (!$stmt->execute()) {
        sendErrorResponse('Failed to fetch user', 500);
    }

    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        sendErrorResponse('User not found', 404);
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    // Check permissions to view this user
    if ($currentUser['role'] === 'customer_admin' || $currentUser['role'] === 'customer_admin_delegate') {
        if ($user['salon_id'] != $currentUser['salon_id']) {
            sendErrorResponse('Forbidden. Cannot view users from other salons.', 403);
        }
    }

    sendJsonResponse([
        'success' => true,
        'user' => [
            'user_id' => $user['user_id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
            'phone' => $user['phone'],
            'role' => $user['role'],
            'salon_id' => $user['salon_id'],
            'salon_name' => $user['salon_name'],
            'is_active' => (bool)$user['is_active'],
            'email_verified' => (bool)$user['email_verified'],
            'last_login' => $user['last_login'],
            'last_login_ip' => $user['last_login_ip'],
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at'],
            'created_by' => $user['created_by']
        ]
    ]);
}

/**
 * Create new user
 */
function handleCreateUser($conn, $currentUser, $data) {
    // Validate required fields
    $requiredFields = ['username', 'email', 'password', 'full_name', 'role'];
    $validation = validateRequiredFields($data, $requiredFields);
    if ($validation !== null) {
        sendErrorResponse($validation['message'], 400, ['missing_fields' => $validation['missing_fields']]);
    }

    $username = trim($data['username']);
    $email = trim($data['email']);
    $password = $data['password'];
    $fullName = trim($data['full_name']);
    $phone = isset($data['phone']) ? trim($data['phone']) : null;
    $role = $data['role'];
    $salonId = isset($data['salon_id']) ? (int)$data['salon_id'] : null;
    $isActive = isset($data['is_active']) ? (bool)$data['is_active'] : true;

    // Validate role permissions
    validateUserCreationPermissions($currentUser, $role, $salonId);

    // Validate email
    if (!validateEmail($email)) {
        sendErrorResponse('Invalid email address', 400);
    }

    // Validate username (alphanumeric, underscore, hyphen, 3-50 chars)
    if (!preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $username)) {
        sendErrorResponse('Username must be 3-50 characters and contain only letters, numbers, underscores, and hyphens', 400);
    }

    // Validate password strength
    if (strlen($password) < 8) {
        sendErrorResponse('Password must be at least 8 characters long', 400);
    }

    // Check if username or email already exists
    $checkStmt = $conn->prepare("SELECT user_id FROM coiffure_users WHERE username = ? OR email = ?");
    if ($checkStmt) {
        $checkStmt->bind_param("ss", $username, $email);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        if ($checkResult->num_rows > 0) {
            $checkStmt->close();
            sendErrorResponse('Username or email already exists', 400);
        }
        $checkStmt->close();
    }

    // Hash password
    $passwordHash = hashPassword($password);

    // Insert user
    $stmt = $conn->prepare(
        "INSERT INTO coiffure_users
        (username, email, password_hash, full_name, phone, role, salon_id, is_active, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        error_log("Failed to prepare user insert: " . $conn->error);
        sendErrorResponse('Failed to create user', 500);
    }

    $createdBy = $currentUser['user_id'];
    $stmt->bind_param(
        "ssssssiis",
        $username,
        $email,
        $passwordHash,
        $fullName,
        $phone,
        $role,
        $salonId,
        $isActive,
        $createdBy
    );

    if (!$stmt->execute()) {
        error_log("Failed to create user: " . $stmt->error);
        sendErrorResponse('Failed to create user', 500);
    }

    $newUserId = $stmt->insert_id;
    $stmt->close();

    // coiffure_user_salons is what auth-login.php and getAccessibleSalonIds()
    // read, so a user created here has to be linked there too -- otherwise they
    // sign in with no salon assigned at all.
    linkUserToSalon($conn, $newUserId, $salonId, null);

    // Log audit
    logAudit($conn, 'user', $newUserId, 'create', "User created: $username (role: $role)", $currentUser['username']);

    sendJsonResponse([
        'success' => true,
        'message' => 'User created successfully',
        'user_id' => $newUserId
    ], 201);
}

/**
 * Update user
 */
function handleUpdateUser($conn, $currentUser, $userId, $data) {
    if (!$userId) {
        sendErrorResponse('User ID required', 400);
    }

    // Get existing user
    $stmt = $conn->prepare("SELECT * FROM coiffure_users WHERE user_id = ?");
    if (!$stmt) {
        sendErrorResponse('Failed to fetch user', 500);
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        sendErrorResponse('User not found', 404);
    }

    $existingUser = $result->fetch_assoc();
    $stmt->close();

    // Check permissions
    validateUserUpdatePermissions($currentUser, $existingUser);

    // Build update query dynamically
    $updates = [];
    $params = [];
    $types = '';

    if (isset($data['email'])) {
        if (!validateEmail($data['email'])) {
            sendErrorResponse('Invalid email address', 400);
        }
        $updates[] = "email = ?";
        $params[] = trim($data['email']);
        $types .= 's';
    }

    if (isset($data['full_name'])) {
        $updates[] = "full_name = ?";
        $params[] = trim($data['full_name']);
        $types .= 's';
    }

    if (isset($data['phone'])) {
        $updates[] = "phone = ?";
        $params[] = trim($data['phone']);
        $types .= 's';
    }

    if (isset($data['is_active']) && in_array($currentUser['role'], ['admin', 'admin_delegate', 'customer_admin'])) {
        $updates[] = "is_active = ?";
        $params[] = (int)$data['is_active'];
        $types .= 'i';
    }

    if (isset($data['role']) && in_array($currentUser['role'], ['admin', 'admin_delegate'])) {
        validateUserCreationPermissions($currentUser, $data['role'], $existingUser['salon_id']);
        $updates[] = "role = ?";
        $params[] = $data['role'];
        $types .= 's';
    }

    if (isset($data['password'])) {
        if (strlen($data['password']) < 8) {
            sendErrorResponse('Password must be at least 8 characters long', 400);
        }
        $updates[] = "password_hash = ?";
        $params[] = hashPassword($data['password']);
        $types .= 's';
    }

    if (empty($updates)) {
        sendErrorResponse('No fields to update', 400);
    }

    // Add user_id to params
    $params[] = $userId;
    $types .= 'i';

    // Execute update
    $query = "UPDATE coiffure_users SET " . implode(', ', $updates) . " WHERE user_id = ?";
    $updateStmt = $conn->prepare($query);

    if (!$updateStmt) {
        error_log("Failed to prepare user update: " . $conn->error);
        sendErrorResponse('Failed to update user', 500);
    }

    $updateStmt->bind_param($types, ...$params);

    if (!$updateStmt->execute()) {
        error_log("Failed to update user: " . $updateStmt->error);
        sendErrorResponse('Failed to update user', 500);
    }

    $updateStmt->close();

    // Keep the junction table in step when the assignment moved.
    if (array_key_exists('salon_id', $data)) {
        linkUserToSalon(
            $conn,
            $userId,
            $data['salon_id'] !== null && $data['salon_id'] !== '' ? (int)$data['salon_id'] : null,
            $existingUser['salon_id'] !== null ? (int)$existingUser['salon_id'] : null
        );
    }

    // Log audit
    logAudit($conn, 'user', $userId, 'update', "User updated: " . $existingUser['username'], $currentUser['username']);

    sendJsonResponse([
        'success' => true,
        'message' => 'User updated successfully'
    ]);
}

/**
 * Mirror a user's primary salon into coiffure_user_salons.
 *
 * Only the pair that actually moved is touched: a user may be assigned to
 * several salons through the multi-salon feature, and those extra rows must
 * survive an edit made through this form, which only ever sets one salon.
 */
function linkUserToSalon($conn, $userId, $salonId, $previousSalonId) {
    if ($previousSalonId && $previousSalonId !== $salonId) {
        $unlink = $conn->prepare(
            "DELETE FROM coiffure_user_salons WHERE user_id = ? AND salon_id = ?"
        );
        if ($unlink) {
            $unlink->bind_param("ii", $userId, $previousSalonId);
            $unlink->execute();
            $unlink->close();
        }
    }

    if (!$salonId) {
        return;
    }

    $link = $conn->prepare(
        "INSERT IGNORE INTO coiffure_user_salons (user_id, salon_id) VALUES (?, ?)"
    );
    if ($link) {
        $link->bind_param("ii", $userId, $salonId);
        $link->execute();
        $link->close();
    }
}

/**
 * Delete (deactivate) user
 */
function handleDeleteUser($conn, $currentUser, $userId) {
    if (!$userId) {
        sendErrorResponse('User ID required', 400);
    }

    // Get existing user
    $stmt = $conn->prepare("SELECT * FROM coiffure_users WHERE user_id = ?");
    if (!$stmt) {
        sendErrorResponse('Failed to fetch user', 500);
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        sendErrorResponse('User not found', 404);
    }

    $existingUser = $result->fetch_assoc();
    $stmt->close();

    // Check permissions
    validateUserUpdatePermissions($currentUser, $existingUser);

    // Prevent deleting yourself
    if ($userId == $currentUser['user_id']) {
        sendErrorResponse('Cannot delete your own account', 400);
    }

    // Deactivate user instead of hard delete
    $deleteStmt = $conn->prepare("UPDATE coiffure_users SET is_active = 0 WHERE user_id = ?");

    if (!$deleteStmt) {
        sendErrorResponse('Failed to delete user', 500);
    }

    $deleteStmt->bind_param("i", $userId);

    if (!$deleteStmt->execute()) {
        error_log("Failed to delete user: " . $deleteStmt->error);
        sendErrorResponse('Failed to delete user', 500);
    }

    $deleteStmt->close();

    // Also destroy all active sessions
    $sessionStmt = $conn->prepare("DELETE FROM coiffure_sessions WHERE user_id = ?");
    if ($sessionStmt) {
        $sessionStmt->bind_param("i", $userId);
        $sessionStmt->execute();
        $sessionStmt->close();
    }

    // Log audit
    logAudit($conn, 'user', $userId, 'delete', "User deactivated: " . $existingUser['username'], $currentUser['username']);

    sendJsonResponse([
        'success' => true,
        'message' => 'User deleted successfully'
    ]);
}

/**
 * Validate permissions for creating a user
 */
function validateUserCreationPermissions($currentUser, $newUserRole, $newUserSalonId) {
    // admin can create any user
    if ($currentUser['role'] === 'admin') {
        return;
    }

    // admin_delegate can create any user except admin and admin_delegate
    if ($currentUser['role'] === 'admin_delegate') {
        if (in_array($newUserRole, ['admin', 'admin_delegate'])) {
            sendErrorResponse('Forbidden. Cannot create admin or admin_delegate users.', 403);
        }
        return;
    }

    // customer_admin can only create users for their salon
    if ($currentUser['role'] === 'customer_admin') {
        if ($newUserSalonId != $currentUser['salon_id']) {
            sendErrorResponse('Forbidden. Can only create users for your own salon.', 403);
        }
        // 'customer_user' was renamed to 'customer_facing_tablet_user' by
        // migration 003, so the old value is no longer a valid enum member --
        // allowing it here meant a customer_admin could not create the tablet
        // account the UI offers, and creating one would have failed at insert.
        if (!in_array($newUserRole, ['customer_admin_delegate', 'customer_facing_tablet_user'])) {
            sendErrorResponse('Forbidden. Can only create customer_admin_delegate and customer_facing_tablet_user roles.', 403);
        }
        return;
    }

    // customer_admin_delegate and customer_user cannot create users
    sendErrorResponse('Forbidden. Insufficient permissions to create users.', 403);
}

/**
 * Validate permissions for updating a user
 */
function validateUserUpdatePermissions($currentUser, $targetUser) {
    // admin can update any user
    if ($currentUser['role'] === 'admin') {
        return;
    }

    // admin_delegate can update any user except admin
    if ($currentUser['role'] === 'admin_delegate') {
        if ($targetUser['role'] === 'admin') {
            sendErrorResponse('Forbidden. Cannot modify admin users.', 403);
        }
        return;
    }

    // customer_admin can only update users from their salon
    if ($currentUser['role'] === 'customer_admin') {
        if ($targetUser['salon_id'] != $currentUser['salon_id']) {
            sendErrorResponse('Forbidden. Can only modify users from your own salon.', 403);
        }
        if ($targetUser['role'] === 'customer_admin' && $targetUser['user_id'] != $currentUser['user_id']) {
            sendErrorResponse('Forbidden. Cannot modify other customer_admin users.', 403);
        }
        return;
    }

    // customer_admin_delegate can only update themselves
    if ($currentUser['role'] === 'customer_admin_delegate') {
        if ($targetUser['user_id'] != $currentUser['user_id']) {
            sendErrorResponse('Forbidden. Can only modify your own account.', 403);
        }
        return;
    }

    sendErrorResponse('Forbidden. Insufficient permissions to modify users.', 403);
}
