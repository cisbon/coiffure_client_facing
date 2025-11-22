<?php
/**
 * Salon Branding API
 * Handles logo upload and color scheme customization for white-labeling
 */

header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

// Handle CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Verify authentication
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (empty($authHeader) || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$sessionToken = $matches[1];

// Verify session and get user
$conn = getDbConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

$stmt = $conn->prepare("SELECT user_id, role FROM coiffure_users WHERE session_token = ? AND session_expiry > NOW()");
$stmt->bind_param("s", $sessionToken);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired session']);
    exit();
}

$user = $result->fetch_assoc();
$userId = $user['user_id'];
$userRole = $user['role'];

// Only admin users can manage salon branding
if ($userRole !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit();
}

// Handle GET request - Fetch salon branding
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $salonId = $_GET['salon_id'] ?? null;

    if (empty($salonId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Salon ID required']);
        exit();
    }

    $stmt = $conn->prepare("
        SELECT
            salon_id,
            name,
            logo_path,
            primary_color,
            secondary_color,
            background_color,
            button_color,
            text_color
        FROM coiffure_salons
        WHERE salon_id = ?
    ");
    $stmt->bind_param("i", $salonId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Salon not found']);
        exit();
    }

    $branding = $result->fetch_assoc();

    // Convert logo path to full URL if exists
    if (!empty($branding['logo_path'])) {
        $branding['logo_url'] = '/uploads/logos/' . basename($branding['logo_path']);
    } else {
        $branding['logo_url'] = null;
    }

    echo json_encode([
        'success' => true,
        'branding' => $branding
    ]);
    exit();
}

// Handle POST request - Update salon branding
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            // Delete old logo if exists
            if (!empty($oldLogoPath) && file_exists($oldLogoPath)) {
                unlink($oldLogoPath);
            }

            $logoPath = $targetPath;
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to upload logo']);
            exit();
        }
    }

    // Handle logo removal
    if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === 'true') {
        if (!empty($oldLogoPath) && file_exists($oldLogoPath)) {
            unlink($oldLogoPath);
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
            'logo_url' => $logoPath ? '/uploads/logos/' . basename($logoPath) : null
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
