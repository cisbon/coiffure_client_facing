<?php
/**
 * SalonLyft API Configuration
 * Database connection and utility functions
 */

// Enable error reporting in development
if (getenv('APP_DEBUG') === 'true') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Load environment variables from .env file
function loadEnv($path = __DIR__ . '/.env') {
    if (!file_exists($path)) {
        // Try .env.example for development
        $path = __DIR__ . '/.env.example';
        if (!file_exists($path)) {
            error_log("ERROR: .env file not found");
            return false;
        }
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove quotes if present
            if (preg_match('/^["\'](.*)["\']\s*$/', $value, $matches)) {
                $value = $matches[1];
            }

            if (!getenv($key)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
    return true;
}

// Load environment variables
loadEnv();

// Database configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'salonlyft');
define('DB_USER', getenv('DB_USERNAME') ?: 'root');
define('DB_PASS', getenv('DB_PASSWORD') ?: '');

// Application configuration
define('OPENROUTER_API_KEY', getenv('OPENROUTER_API_KEY') ?: '');
define('AI_MODEL', getenv('AI_MODEL') ?: 'google/gemini-2.5-flash-image');
define('DEFAULT_SALON_ID', getenv('DEFAULT_SALON_ID') ?: 1);
define('ALLOWED_ORIGINS', getenv('ALLOWED_ORIGINS') ?: '*');

// File upload settings
define('MAX_UPLOAD_SIZE', getenv('MAX_UPLOAD_SIZE') ?: 5242880); // 5MB
define('ALLOWED_IMAGE_TYPES', explode(',', getenv('ALLOWED_IMAGE_TYPES') ?: 'image/jpeg,image/png,image/jpg,image/webp'));

/**
 * Get database connection using MySQLi
 * @return mysqli|null Database connection or null on failure
 */
function getDbConnection() {
    static $connection = null;

    if ($connection !== null) {
        // Check if connection is still alive
        if ($connection->ping()) {
            return $connection;
        }
    }

    try {
        // Create connection with error reporting disabled initially
        mysqli_report(MYSQLI_REPORT_OFF);

        $connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);

        // Check connection
        if ($connection->connect_error) {
            error_log("Database connection failed: " . $connection->connect_error);
            return null;
        }

        // Set charset to UTF-8
        if (!$connection->set_charset("utf8mb4")) {
            error_log("Error loading character set utf8mb4: " . $connection->error);
            return null;
        }

        return $connection;
    } catch (Exception $e) {
        error_log("Database connection exception: " . $e->getMessage());
        return null;
    }
}

/**
 * Set CORS headers
 * @param string|null $origin Specific origin to allow, or null to use env config
 */
function setCorsHeaders($origin = null) {
    $allowedOrigins = ALLOWED_ORIGINS;

    if ($origin === null && isset($_SERVER['HTTP_ORIGIN'])) {
        $origin = $_SERVER['HTTP_ORIGIN'];
    }

    // Check if origin is allowed
    if ($allowedOrigins === '*') {
        header('Access-Control-Allow-Origin: *');
    } else {
        $allowed = array_map('trim', explode(',', $allowedOrigins));
        if (in_array($origin, $allowed)) {
            header("Access-Control-Allow-Origin: $origin");
        }
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400'); // 24 hours

    // Handle preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

/**
 * Send JSON response
 * @param array $data Response data
 * @param int $statusCode HTTP status code
 */
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * Send error response
 * @param string $message Error message
 * @param int $statusCode HTTP status code
 * @param array $details Additional error details
 */
function sendErrorResponse($message, $statusCode = 400, $details = []) {
    $response = [
        'success' => false,
        'error' => $message
    ];

    if (!empty($details)) {
        $response['details'] = $details;
    }

    sendJsonResponse($response, $statusCode);
}

/**
 * Validate required fields in request data
 * @param array $data Request data
 * @param array $requiredFields List of required field names
 * @return array|null Null if valid, error details array if invalid
 */
function validateRequiredFields($data, $requiredFields) {
    $missing = [];

    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            $missing[] = $field;
        }
    }

    if (!empty($missing)) {
        return [
            'message' => 'Missing required fields',
            'missing_fields' => $missing
        ];
    }

    return null;
}

/**
 * Sanitize input string
 * @param string $input Input string
 * @return string Sanitized string
 */
function sanitizeInput($input) {
    $input = trim($input);
    $input = stripslashes($input);
    $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    return $input;
}

/**
 * Validate email address
 * @param string $email Email address
 * @return bool True if valid, false otherwise
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (basic validation)
 * @param string $phone Phone number
 * @return bool True if valid, false otherwise
 */
function validatePhone($phone) {
    // Remove common formatting characters
    $phone = preg_replace('/[\s\-\(\)\.]/', '', $phone);
    // Check if it contains only digits and + (for international)
    return preg_match('/^\+?[0-9]{8,15}$/', $phone);
}

/**
 * Get client IP address
 * @return string Client IP address
 */
function getClientIp() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Get first IP if multiple
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    }
}

/**
 * Get client user agent
 * @return string Client user agent
 */
function getUserAgent() {
    return $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
}

/**
 * Log audit trail
 * @param mysqli $conn Database connection
 * @param string $entityType Entity type (customer, salon, etc.)
 * @param int $entityId Entity ID
 * @param string $action Action performed
 * @param string|null $details Action details
 * @param string|null $performedBy Who performed the action
 * @return bool Success status
 */
function logAudit($conn, $entityType, $entityId, $action, $details = null, $performedBy = 'system') {
    try {
        // Validate connection
        if (!$conn || !($conn instanceof mysqli) || !$conn->ping()) {
            error_log("Invalid database connection for audit log");
            return false;
        }

        $stmt = $conn->prepare(
            "INSERT INTO coiffure_audit_log
            (entity_type, entity_id, action, action_details, performed_by, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        if (!$stmt) {
            error_log("Failed to prepare audit log statement: " . $conn->error);
            return false;
        }

        $ip = getClientIp();
        $userAgent = getUserAgent();

        $stmt->bind_param(
            "sisssss",
            $entityType,
            $entityId,
            $action,
            $details,
            $performedBy,
            $ip,
            $userAgent
        );

        $result = $stmt->execute();

        if (!$result) {
            error_log("Failed to log audit: " . $stmt->error);
        }

        $stmt->close();
        return $result;
    } catch (Exception $e) {
        // Catch any exceptions and log them, but don't let audit logging break the application
        error_log("Audit log exception: " . $e->getMessage());
        return false;
    } catch (Error $e) {
        // Catch PHP 7+ errors as well
        error_log("Audit log error: " . $e->getMessage());
        return false;
    }
}

/**
 * Validate image upload
 * @param array $file File from $_FILES
 * @return array Success status and message/path
 */
function validateImageUpload($file) {
    // Check if file was uploaded
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success' => false, 'message' => 'Invalid file upload'];
    }

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];

        return [
            'success' => false,
            'message' => $errors[$file['error']] ?? 'Unknown upload error'
        ];
    }

    // Check file size
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return [
            'success' => false,
            'message' => 'File size exceeds maximum allowed (' . (MAX_UPLOAD_SIZE / 1048576) . 'MB)'
        ];
    }

    // Validate MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, ALLOWED_IMAGE_TYPES)) {
        return [
            'success' => false,
            'message' => 'Invalid file type. Allowed types: ' . implode(', ', ALLOWED_IMAGE_TYPES)
        ];
    }

    return ['success' => true];
}

/**
 * Generate unique session ID
 * @return string Unique session ID
 */
function generateSessionId() {
    return uniqid('session_', true) . '_' . bin2hex(random_bytes(8));
}

/**
 * Calculate data retention date (GDPR - typically 2-7 years)
 * @param int $years Number of years to retain data
 * @return string Date in Y-m-d format
 */
function calculateRetentionDate($years = 3) {
    return date('Y-m-d', strtotime("+$years years"));
}
