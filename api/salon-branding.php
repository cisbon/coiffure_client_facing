<?php
/**
 * Salon Branding API
 * Handles logo upload and color scheme customization for white-labeling
 */

// Enable error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/config.php';

    // Handle CORS
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    // Get database connection
    $conn = getDbConnection();
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        exit();
    }

    // Use standard authentication from config.php
    $user = requireAuth($conn);
    $userId = $user['user_id'];
    $userRole = $user['role'];

// Handle GET request - Fetch salon branding (allowed for all authenticated users)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $salonId = $_GET['salon_id'] ?? null;

    if (empty($salonId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Salon ID required']);
        exit();
    }

    // First check if branding columns exist
    $checkColumns = $conn->query("SHOW COLUMNS FROM coiffure_salons LIKE 'logo_path'");
    if ($checkColumns->num_rows === 0) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Branding columns do not exist in database. Please run migration 007.',
            'hint' => 'Go to api/run-migration.php and run migration 007'
        ]);
        exit();
    }

    $stmt = $conn->prepare("
        SELECT
            salon_id,
            salon_name,
            logo_path,
            primary_color,
            secondary_color,
            background_color,
            button_color,
            text_color
        FROM coiffure_salons
        WHERE salon_id = ?
    ");

    if (!$stmt) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Database prepare error: ' . $conn->error,
            'sql_error' => $conn->error,
            'errno' => $conn->errno
        ]);
        exit();
    }

    $stmt->bind_param("i", $salonId);

    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database execute error: ' . $stmt->error]);
        exit();
    }

    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Salon not found']);
        exit();
    }

    $branding = $result->fetch_assoc();

    // Convert logo path to relative URL if exists
    if (!empty($branding['logo_path'])) {
        $branding['logo_url'] = 'uploads/logos/' . basename($branding['logo_path']);
    } else {
        $branding['logo_url'] = null;
    }

    echo json_encode([
        'success' => true,
        'branding' => $branding
    ]);
    exit();
}

// Handle POST request - Update salon branding (admin and customer_admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only admin and customer_admin users can update salon branding
    if (!hasRole($user, ['admin', 'customer_admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Insufficient permissions. Only admins can update salon branding.']);
        exit();
    }

    $salonId = $_POST['salon_id'] ?? null;

    if (empty($salonId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Salon ID required']);
        exit();
    }

    // Validate salon exists
    $stmt = $conn->prepare("SELECT salon_id, logo_path FROM coiffure_salons WHERE salon_id = ?");
    $stmt->bind_param("i", $salonId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Salon not found']);
        exit();
    }

    $salon = $result->fetch_assoc();
    $oldLogoPath = $salon['logo_path'];

    // Handle logo upload
    $logoPath = $oldLogoPath;

    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        // Create uploads directory if it doesn't exist
        $uploadDir = __DIR__ . '/../uploads/logos/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Validate file
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($fileInfo, $_FILES['logo']['tmp_name']);
        finfo_close($fileInfo);

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mimeType, $allowedTypes)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed.']);
            exit();
        }

        // Validate file size (max 2MB)
        if ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'File size exceeds 2MB limit']);
            exit();
        }

        // Generate unique filename
        $extension = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $filename = 'salon_' . $salonId . '_' . time() . '.' . $extension;
        $targetPath = $uploadDir . $filename;

        // Move uploaded file
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $targetPath)) {
            // Delete old logo if exists (handle both absolute and relative paths)
            if (!empty($oldLogoPath)) {
                $oldLogoAbsolutePath = strpos($oldLogoPath, '/') === 0 ? $oldLogoPath : __DIR__ . '/../' . $oldLogoPath;
                if (file_exists($oldLogoAbsolutePath)) {
                    unlink($oldLogoAbsolutePath);
                }
            }

            // Store relative path in database (not absolute server path)
            $logoPath = 'uploads/logos/' . $filename;
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to upload logo']);
            exit();
        }
    }

    // Handle logo removal
    if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === 'true') {
        if (!empty($oldLogoPath)) {
            // Handle both absolute and relative paths
            $oldLogoAbsolutePath = strpos($oldLogoPath, '/') === 0 ? $oldLogoPath : __DIR__ . '/../' . $oldLogoPath;
            if (file_exists($oldLogoAbsolutePath)) {
                unlink($oldLogoAbsolutePath);
            }
        }
        $logoPath = null;
    }

    // Get colors from POST data
    $primaryColor = $_POST['primary_color'] ?? '#9333EA';
    $secondaryColor = $_POST['secondary_color'] ?? '#EC4899';
    $backgroundColor = $_POST['background_color'] ?? '#FFFFFF';
    $buttonColor = $_POST['button_color'] ?? '#9333EA';
    $textColor = $_POST['text_color'] ?? '#1F2937';

    // Validate hex colors
    $hexPattern = '/^#[0-9A-Fa-f]{6}$/';
    if (!preg_match($hexPattern, $primaryColor) ||
        !preg_match($hexPattern, $secondaryColor) ||
        !preg_match($hexPattern, $backgroundColor) ||
        !preg_match($hexPattern, $buttonColor) ||
        !preg_match($hexPattern, $textColor)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid color format. Use 6-digit hex codes.']);
        exit();
    }

    // Update salon branding
    $stmt = $conn->prepare("
        UPDATE coiffure_salons
        SET
            logo_path = ?,
            primary_color = ?,
            secondary_color = ?,
            background_color = ?,
            button_color = ?,
            text_color = ?,
            updated_at = NOW()
        WHERE salon_id = ?
    ");

    $stmt->bind_param("ssssssi",
        $logoPath,
        $primaryColor,
        $secondaryColor,
        $backgroundColor,
        $buttonColor,
        $textColor,
        $salonId
    );

    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Salon branding updated successfully',
            'logo_path' => $logoPath,
            'logo_url' => $logoPath ? 'uploads/logos/' . basename($logoPath) : null
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to update salon branding: ' . $stmt->error]);
    }

    $stmt->close();
    $conn->close();
    exit();
}

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);

} catch (Exception $e) {
    error_log("Salon Branding API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
} catch (Error $e) {
    error_log("Salon Branding API Fatal Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Fatal error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
