<?php
/**
 * User Settings API
 * Handles user preferences like language selection
 */

require_once 'config.php';

// Set CORS headers
setCorsHeaders();

// Get database connection
$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

// Require authentication
$currentUser = requireAuth($conn);

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Route the request
switch ($method) {
    case 'GET':
        handleGet($conn, $currentUser);
        break;
    case 'PUT':
    case 'POST':
        handleUpdate($conn, $currentUser);
        break;
    default:
        sendErrorResponse('Method not allowed', 405);
}

/**
 * Get user settings
 */
function handleGet($conn, $currentUser) {
    $stmt = $conn->prepare(
        "SELECT preferred_language FROM coiffure_users WHERE user_id = ?"
    );

    if (!$stmt) {
        sendErrorResponse('Database query failed', 500);
    }

    $stmt->bind_param("i", $currentUser['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        sendErrorResponse('User not found', 404);
    }

    $settings = $result->fetch_assoc();
    $stmt->close();

    sendJsonResponse([
        'success' => true,
        'settings' => [
            'language' => $settings['preferred_language'] ?? 'de'
        ]
    ]);
}

/**
 * Update user settings
 */
function handleUpdate($conn, $currentUser) {
    // Get request body
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        sendErrorResponse('Invalid JSON', 400);
    }

    // Validate language
    if (isset($data['language'])) {
        $language = $data['language'];

        // Only allow 'de' or 'en'
        if (!in_array($language, ['de', 'en'])) {
            sendErrorResponse('Invalid language. Must be "de" or "en"', 400);
        }

        // Update user's language preference
        $stmt = $conn->prepare(
            "UPDATE coiffure_users SET preferred_language = ? WHERE user_id = ?"
        );

        if (!$stmt) {
            sendErrorResponse('Database update failed', 500);
        }

        $stmt->bind_param("si", $language, $currentUser['user_id']);

        if (!$stmt->execute()) {
            $stmt->close();
            sendErrorResponse('Failed to update language preference', 500);
        }

        $stmt->close();

        // Log audit trail
        logAudit($conn, 'user', $currentUser['user_id'], 'update_language',
                 "Language changed to: $language", $currentUser['username']);

        sendJsonResponse([
            'success' => true,
            'message' => 'Language preference updated successfully',
            'language' => $language
        ]);
    } else {
        sendErrorResponse('No settings provided', 400);
    }
}

$conn->close();
