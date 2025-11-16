<?php
/**
 * Login API Endpoint
 * Authenticates users and creates sessions
 */

require_once __DIR__ . '/config.php';

// Set CORS headers
setCorsHeaders();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('Method not allowed. Use POST.', 405);
}

// Get database connection
$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

// Clean up expired sessions periodically (10% chance)
if (rand(1, 10) === 1) {
    cleanExpiredSessions($conn);
}

// Parse JSON input
$jsonInput = file_get_contents('php://input');
$data = json_decode($jsonInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    sendErrorResponse('Invalid JSON: ' . json_last_error_msg(), 400);
}

// Validate required fields
$requiredFields = ['username', 'password'];
$validation = validateRequiredFields($data, $requiredFields);
if ($validation !== null) {
    sendErrorResponse($validation['message'], 400, ['missing_fields' => $validation['missing_fields']]);
}

$username = trim($data['username']);
$password = $data['password'];

// Check if account is locked
if (isAccountLocked($conn, $username)) {
    logAudit($conn, 'login', 0, 'login_failed', "Account locked: $username", 'system');
    sendErrorResponse('Account is temporarily locked due to multiple failed login attempts. Please try again in 15 minutes.', 403);
}

// Get user from database
$stmt = $conn->prepare(
    "SELECT user_id, username, email, password_hash, full_name, role, salon_id, is_active, email_verified
    FROM coiffure_users
    WHERE (username = ? OR email = ?) AND is_active = 1"
);

if (!$stmt) {
    error_log("Failed to prepare user query: " . $conn->error);
    sendErrorResponse('Login failed', 500);
}

$stmt->bind_param("ss", $username, $username);

if (!$stmt->execute()) {
    error_log("Failed to execute user query: " . $stmt->error);
    sendErrorResponse('Login failed', 500);
}

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    recordFailedLogin($conn, $username);
    logAudit($conn, 'login', 0, 'login_failed', "User not found: $username", 'system');
    sendErrorResponse('Invalid username or password', 401);
}

$user = $result->fetch_assoc();
$stmt->close();

// Verify password
if (!verifyPassword($password, $user['password_hash'])) {
    recordFailedLogin($conn, $username);
    logAudit($conn, 'login', $user['user_id'], 'login_failed', 'Invalid password', $user['username']);
    sendErrorResponse('Invalid username or password', 401);
}

// Check if user is active
if (!$user['is_active']) {
    logAudit($conn, 'login', $user['user_id'], 'login_failed', 'Inactive account', $user['username']);
    sendErrorResponse('Account is inactive. Please contact administrator.', 403);
}

// Create session
$session = createUserSession($conn, $user['user_id']);

if (!$session) {
    error_log("Failed to create session for user " . $user['user_id']);
    sendErrorResponse('Failed to create session', 500);
}

// Log successful login
logAudit($conn, 'login', $user['user_id'], 'login', 'Successful login', $user['username']);

// Get salon name if applicable
$salonName = null;
if ($user['salon_id']) {
    $salonStmt = $conn->prepare("SELECT salon_name FROM coiffure_salons WHERE salon_id = ?");
    if ($salonStmt) {
        $salonStmt->bind_param("i", $user['salon_id']);
        $salonStmt->execute();
        $salonResult = $salonStmt->get_result();
        if ($salonResult->num_rows > 0) {
            $salon = $salonResult->fetch_assoc();
            $salonName = $salon['salon_name'];
        }
        $salonStmt->close();
    }
}

// Return success with session token and user data
sendJsonResponse([
    'success' => true,
    'message' => 'Login successful',
    'session_token' => $session['session_token'],
    'expires_at' => $session['expires_at'],
    'user' => [
        'user_id' => $user['user_id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
        'salon_id' => $user['salon_id'],
        'salon_name' => $salonName,
        'email_verified' => (bool)$user['email_verified']
    ]
]);
