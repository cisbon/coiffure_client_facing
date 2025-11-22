<?php
/**
 * CORS Test Endpoint
 * Use this to verify CORS headers are being sent correctly
 */

require_once __DIR__ . '/config.php';

// Set CORS headers
setCorsHeaders();

// Log what we're doing
error_log("=== CORS TEST ===");
error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);
error_log("Origin: " . ($_SERVER['HTTP_ORIGIN'] ?? 'none'));
error_log("ALLOWED_ORIGINS config: " . ALLOWED_ORIGINS);

// Return test response
sendJsonResponse([
    'success' => true,
    'message' => 'CORS is working!',
    'origin' => $_SERVER['HTTP_ORIGIN'] ?? 'none',
    'method' => $_SERVER['REQUEST_METHOD'],
    'allowed_origins_config' => ALLOWED_ORIGINS,
    'timestamp' => date('Y-m-d H:i:s')
], 200);
