<?php
/**
 * Coiffure AI API Configuration
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

/**
 * Never let an endpoint answer with an empty 500.
 * -------------------------------------------------------------------
 * With display_errors off, an uncaught Error or a fatal produced a blank
 * response body: the browser saw "500" and nothing else, which says nothing
 * about what actually broke. Worse, a `catch (Exception $e)` does not catch an
 * Error at all (undefined function, wrong argument count, type error), so a
 * handler could die mid-transaction without even rolling back.
 *
 * These two handlers guarantee a JSON body with the real reason. The message
 * and exception class are always included -- the caller is an authenticated
 * administrator and the existing catch blocks already returned as much -- while
 * the file, line and trace are only added with APP_DEBUG=true.
 */
if (php_sapi_name() !== 'cli') {
    set_exception_handler(function ($e) {
        _sendFatalJson(get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    });

    register_shutdown_function(function () {
        $error = error_get_last();
        if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        _sendFatalJson('FatalError', $error['message'], $error['file'], $error['line'], null);
    });
}

/**
 * Emit the error as JSON, unless a response has already gone out.
 * Deliberately does not use sendJsonResponse(): this runs when things are
 * already broken and must not depend on anything further down the file.
 */
function _sendFatalJson($type, $message, $file, $line, $trace) {
    error_log(sprintf('[api] %s: %s in %s:%d', $type, $message, $file, $line));

    if (headers_sent()) {
        return;
    }

    // A partially written body would produce invalid JSON.
    if (ob_get_length()) {
        @ob_clean();
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');

    $payload = [
        'success' => false,
        'error'   => $message !== '' ? $message : 'Internal server error',
        'details' => ['type' => $type],
    ];

    if (getenv('APP_DEBUG') === 'true') {
        $payload['details']['file'] = $file;
        $payload['details']['line'] = $line;
        if ($trace !== null) {
            $payload['details']['trace'] = explode("\n", $trace);
        }
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
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

    // Debug logging
    error_log("CORS Debug - Origin: " . ($origin ?? 'NULL'));
    error_log("CORS Debug - Allowed Origins: " . $allowedOrigins);
    error_log("CORS Debug - Request Method: " . $_SERVER['REQUEST_METHOD']);

    // Check if origin is allowed
    // NOTE: Cannot use '*' with Access-Control-Allow-Credentials: true
    // So when ALLOWED_ORIGINS is '*', we echo back the requesting origin
    if ($allowedOrigins === '*') {
        if ($origin) {
            header("Access-Control-Allow-Origin: $origin");
            error_log("CORS Debug - Set header: Access-Control-Allow-Origin: $origin");
        } else {
            error_log("CORS Debug - WARNING: No origin provided, cannot set CORS header!");
        }
    } else {
        $allowed = array_map('trim', explode(',', $allowedOrigins));
        if (in_array($origin, $allowed)) {
            header("Access-Control-Allow-Origin: $origin");
            error_log("CORS Debug - Set header: Access-Control-Allow-Origin: $origin");
        } else {
            error_log("CORS Debug - WARNING: Origin not in allowed list!");
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

// ============================================================
// AUTHENTICATION & SESSION MANAGEMENT
// ============================================================

/**
 * Hash password using bcrypt
 * @param string $password Plain text password
 * @return string Hashed password
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify password against hash
 * @param string $password Plain text password
 * @param string $hash Hashed password
 * @return bool True if password matches
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Generate secure random session token
 * @return string Session token
 */
function generateSessionToken() {
    return bin2hex(random_bytes(64)); // 128 character hex string
}

/**
 * Create user session
 * @param mysqli $conn Database connection
 * @param int $userId User ID
 * @param int $expiryHours Hours until session expires (default 24)
 * @return array|null Session data or null on failure
 */
function createUserSession($conn, $userId, $expiryHours = 24) {
    try {
        // Generate session token
        $token = generateSessionToken();
        $expiresAt = date('Y-m-d H:i:s', strtotime("+$expiryHours hours"));
        $ip = getClientIp();
        $userAgent = getUserAgent();

        // Insert session
        $stmt = $conn->prepare(
            "INSERT INTO coiffure_sessions (user_id, session_token, ip_address, user_agent, expires_at)
            VALUES (?, ?, ?, ?, ?)"
        );

        if (!$stmt) {
            error_log("Failed to prepare session insert: " . $conn->error);
            return null;
        }

        $stmt->bind_param("issss", $userId, $token, $ip, $userAgent, $expiresAt);

        if (!$stmt->execute()) {
            error_log("Failed to create session: " . $stmt->error);
            $stmt->close();
            return null;
        }

        $sessionId = $stmt->insert_id;
        $stmt->close();

        // Update user's last login
        $updateStmt = $conn->prepare(
            "UPDATE coiffure_users SET last_login = NOW(), last_login_ip = ? WHERE user_id = ?"
        );
        if ($updateStmt) {
            $updateStmt->bind_param("si", $ip, $userId);
            $updateStmt->execute();
            $updateStmt->close();
        }

        return [
            'session_id' => $sessionId,
            'session_token' => $token,
            'expires_at' => $expiresAt
        ];
    } catch (Exception $e) {
        error_log("Session creation exception: " . $e->getMessage());
        return null;
    }
}

/**
 * Whether migration 026 has added coiffure_sessions.impersonated_by.
 * Cached: this is checked on every authenticated request.
 */
function sessionsHaveImpersonation($conn) {
    static $exists = null;
    if ($exists === null) {
        $result = $conn->query("SHOW COLUMNS FROM coiffure_sessions LIKE 'impersonated_by'");
        $exists = $result && $result->num_rows > 0;
    }
    return $exists;
}

/**
 * Validate session token and return user data
 * @param mysqli $conn Database connection
 * @param string $token Session token
 * @return array|null User data if valid, null otherwise
 */
function validateSession($conn, $token) {
    try {
        if (empty($token)) {
            return null;
        }

        // Support sessions carry the administrator who started them
        // (migration 026). Selected only when the column exists, so an
        // unmigrated database still authenticates rather than failing every
        // request with an unknown-column error.
        $impersonationColumn = sessionsHaveImpersonation($conn)
            ? 's.impersonated_by,'
            : 'NULL AS impersonated_by,';

        // Get session and user data
        $stmt = $conn->prepare(
            "SELECT s.session_id, s.user_id, s.expires_at, s.ip_address, $impersonationColumn
                    u.username, u.email, u.full_name, u.role, u.salon_id, u.is_active
            FROM coiffure_sessions s
            JOIN coiffure_users u ON s.user_id = u.user_id
            WHERE s.session_token = ? AND s.expires_at > NOW()"
        );

        if (!$stmt) {
            error_log("Failed to prepare session validation: " . $conn->error);
            return null;
        }

        $stmt->bind_param("s", $token);

        if (!$stmt->execute()) {
            error_log("Failed to validate session: " . $stmt->error);
            $stmt->close();
            return null;
        }

        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            return null;
        }

        $session = $result->fetch_assoc();
        $stmt->close();

        // Check if user is active
        if (!$session['is_active']) {
            return null;
        }

        // Sliding renewal: keep actively-used (kiosk) sessions alive so a tablet
        // that is used at least once every few weeks never gets logged out. Only
        // writes when the expiry has drifted, to avoid a write on every request.
        //
        // Support sessions are excluded: impersonate.php issues them with a
        // deliberately short expiry, and renewing them to 30 days would turn a
        // two-hour support window into a month of access.
        if (empty($session['impersonated_by'])) {
            $renew = $conn->prepare(
                "UPDATE coiffure_sessions
                 SET expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
                 WHERE session_id = ? AND expires_at < DATE_ADD(NOW(), INTERVAL 29 DAY)"
            );
            if ($renew) {
                $renew->bind_param("i", $session['session_id']);
                @$renew->execute();
                $renew->close();
            }
        }

        // Optional: Check if IP matches (can be disabled for mobile users)
        // if ($session['ip_address'] !== getClientIp()) {
        //     error_log("Session IP mismatch for user " . $session['user_id']);
        //     return null;
        // }

        return $session;
    } catch (Exception $e) {
        error_log("Session validation exception: " . $e->getMessage());
        return null;
    }
}

/**
 * Destroy user session
 * @param mysqli $conn Database connection
 * @param string $token Session token
 * @return bool Success status
 */
function destroySession($conn, $token) {
    try {
        $stmt = $conn->prepare("DELETE FROM coiffure_sessions WHERE session_token = ?");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("s", $token);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    } catch (Exception $e) {
        error_log("Session destruction exception: " . $e->getMessage());
        return false;
    }
}

/**
 * Clean up expired sessions
 * @param mysqli $conn Database connection
 * @return int Number of sessions deleted
 */
function cleanExpiredSessions($conn) {
    try {
        $stmt = $conn->prepare("DELETE FROM coiffure_sessions WHERE expires_at < NOW()");

        if (!$stmt) {
            return 0;
        }

        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected;
    } catch (Exception $e) {
        error_log("Session cleanup exception: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get session token from request headers or cookie
 * @return string|null Session token or null
 */
function getSessionToken() {
    // Check Authorization header first (Bearer token)
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $matches[1];
        }
    }

    // Check custom X-Session-Token header
    if (isset($_SERVER['HTTP_X_SESSION_TOKEN'])) {
        return $_SERVER['HTTP_X_SESSION_TOKEN'];
    }

    // Check cookie
    if (isset($_COOKIE['session_token'])) {
        return $_COOKIE['session_token'];
    }

    return null;
}

/**
 * Require authentication - validates session and returns user data
 * Sends error response and exits if not authenticated
 * @param mysqli $conn Database connection
 * @return array User data
 */
function requireAuth($conn) {
    $token = getSessionToken();
    $user = validateSession($conn, $token);

    if (!$user) {
        sendErrorResponse('Unauthorized. Please login.', 401);
    }

    return $user;
}

/**
 * Check if user has required role
 * @param array $user User data from session
 * @param array $allowedRoles Array of allowed role names
 * @return bool True if user has required role
 */
function hasRole($user, $allowedRoles) {
    if (!is_array($allowedRoles)) {
        $allowedRoles = [$allowedRoles];
    }

    return in_array($user['role'], $allowedRoles);
}

/**
 * Require specific role(s) - sends error and exits if user doesn't have role
 * @param array $user User data from session
 * @param array|string $allowedRoles Role name(s) required
 */
function requireRole($user, $allowedRoles) {
    if (!hasRole($user, $allowedRoles)) {
        sendErrorResponse('Forbidden. Insufficient permissions.', 403);
    }
}

/**
 * Check if user can manage salon (for customer_admin and customer_admin_delegate)
 * @param array $user User data from session
 * @param int $salonId Salon ID to check
 * @param mysqli|null $conn Optional database connection for checking junction table
 * @return bool True if user can manage this salon
 */
function canManageSalon($user, $salonId, $conn = null) {
    // Admin and admin_delegate can manage all salons
    if (in_array($user['role'], ['admin', 'admin_delegate'])) {
        return true;
    }

    // Customer admins can only manage their assigned salons
    if (in_array($user['role'], ['customer_admin', 'customer_admin_delegate'])) {
        // If we have a connection, check the junction table
        if ($conn && $conn instanceof mysqli && $conn->ping()) {
            $stmt = $conn->prepare(
                "SELECT 1 FROM coiffure_user_salons WHERE user_id = ? AND salon_id = ? LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param("ii", $user['user_id'], $salonId);
                $stmt->execute();
                $result = $stmt->get_result();
                $hasAccess = $result->num_rows > 0;
                $stmt->close();
                return $hasAccess;
            }
        }

        // Fallback to old salon_id column for backward compatibility
        return isset($user['salon_id']) && $user['salon_id'] == $salonId;
    }

    return false;
}

