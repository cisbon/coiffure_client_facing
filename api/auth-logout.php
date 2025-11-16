<?php
/**
 * Logout API Endpoint
 * Destroys user session
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

// Get session token
$token = getSessionToken();

if (!$token) {
    sendErrorResponse('No session token provided', 400);
}

// Get user info before destroying session (for audit log)
$user = validateSession($conn, $token);

// Destroy session
$result = destroySession($conn, $token);

if (!$result) {
    sendErrorResponse('Failed to logout', 500);
}

// Log logout
if ($user) {
    logAudit($conn, 'login', $user['user_id'], 'logout', 'User logged out', $user['username']);
}

sendJsonResponse([
    'success' => true,
    'message' => 'Logout successful'
]);
