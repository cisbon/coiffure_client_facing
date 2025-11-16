<?php
/**
 * SalonLyft API - Social Links Management
 * Handles CRUD operations for salon social media and review links
 */

require_once 'config.php';

// Set CORS headers
setCorsHeaders();

// Get database connection
$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Route the request
switch ($method) {
    case 'GET':
        handleGet($conn);
        break;
    case 'POST':
        handlePost($conn);
        break;
    case 'PUT':
        handlePut($conn);
        break;
    case 'DELETE':
        handleDelete($conn);
        break;
    default:
        sendErrorResponse('Method not allowed', 405);
}

/**
 * Handle GET request - List social links
 */
function handleGet($conn) {
    // Check if requesting for specific salon
    $salonId = isset($_GET['salon_id']) ? intval($_GET['salon_id']) : null;
    $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === 'true';

    // Build query
    $query = "SELECT
        link_id,
        salon_id,
        link_type,
        link_url,
        display_name,
        description,
        icon_name,
        qr_code_data,
        display_order,
        is_active,
        created_at,
        updated_at
    FROM coiffure_social_links";

    $conditions = [];
    $params = [];
    $types = '';

    if ($salonId) {
        $conditions[] = "salon_id = ?";
        $params[] = $salonId;
        $types .= 'i';
    }

    if (!$includeInactive) {
        $conditions[] = "is_active = 1";
    }

    if (count($conditions) > 0) {
        $query .= " WHERE " . implode(' AND ', $conditions);
    }

    $query .= " ORDER BY display_order ASC, created_at DESC";

    $stmt = $conn->prepare($query);

    if (!$stmt) {
        sendErrorResponse('Failed to prepare query', 500);
    }

    if (count($params) > 0) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        sendErrorResponse('Failed to fetch social links', 500);
    }

    $result = $stmt->get_result();
    $links = [];

    while ($row = $result->fetch_assoc()) {
        $links[] = $row;
    }

    $stmt->close();

    sendJsonResponse([
        'success' => true,
        'data' => $links,
        'count' => count($links)
    ]);
}

/**
 * Handle POST request - Create new social link
 */
function handlePost($conn) {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        sendErrorResponse('Invalid JSON input');
    }

    // Validate required fields
    $requiredFields = ['salon_id', 'link_type', 'link_url', 'display_name'];
    $validation = validateRequiredFields($data, $requiredFields);

    if ($validation) {
        sendErrorResponse($validation['message'], 400, $validation['missing_fields']);
    }

    // Sanitize inputs
    $salonId = intval($data['salon_id']);
    $linkType = $data['link_type'];
    $linkUrl = trim($data['link_url']);
    $displayName = trim($data['display_name']);
    $description = isset($data['description']) ? trim($data['description']) : null;
    $iconName = isset($data['icon_name']) ? trim($data['icon_name']) : $linkType;
    $displayOrder = isset($data['display_order']) ? intval($data['display_order']) : 0;

    // Validate URL
    if (!filter_var($linkUrl, FILTER_VALIDATE_URL)) {
        sendErrorResponse('Invalid URL format');
    }

    // Validate link type
    $validTypes = ['instagram', 'facebook', 'tiktok', 'google_reviews', 'yelp', 'twitter', 'linkedin', 'youtube', 'pinterest', 'custom'];
    if (!in_array($linkType, $validTypes)) {
        sendErrorResponse('Invalid link type');
    }

    // Generate QR code data (base64)
    $qrCodeData = generateQRCodeBase64($linkUrl);

    // Insert into database
    $stmt = $conn->prepare(
        "INSERT INTO coiffure_social_links
        (salon_id, link_type, link_url, display_name, description, icon_name, qr_code_data, display_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        sendErrorResponse('Failed to prepare insert statement', 500);
    }

    $stmt->bind_param(
        "issssssi",
        $salonId,
        $linkType,
        $linkUrl,
        $displayName,
        $description,
        $iconName,
        $qrCodeData,
        $displayOrder
    );

    if (!$stmt->execute()) {
        error_log("Failed to insert social link: " . $stmt->error);
        sendErrorResponse('Failed to create social link', 500);
    }

    $linkId = $stmt->insert_id;
    $stmt->close();

    // Log audit
    logAudit($conn, 'social_link', $linkId, 'create', "Created social link: $displayName", 'system');

    sendJsonResponse([
        'success' => true,
        'message' => 'Social link created successfully',
        'link_id' => $linkId,
        'qr_code_data' => $qrCodeData
    ], 201);
}

/**
 * Handle PUT request - Update social link
 */
function handlePut($conn) {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        sendErrorResponse('Invalid JSON input');
    }

    // Validate required fields
    if (!isset($data['link_id'])) {
        sendErrorResponse('link_id is required');
    }

    $linkId = intval($data['link_id']);

    // Build update query dynamically
    $updates = [];
    $params = [];
    $types = '';

    // Track if URL changed (need to regenerate QR code)
    $urlChanged = false;
    $newUrl = null;

    if (isset($data['link_type'])) {
        $validTypes = ['instagram', 'facebook', 'tiktok', 'google_reviews', 'yelp', 'twitter', 'linkedin', 'youtube', 'pinterest', 'custom'];
        if (!in_array($data['link_type'], $validTypes)) {
            sendErrorResponse('Invalid link type');
        }
        $updates[] = "link_type = ?";
        $params[] = $data['link_type'];
        $types .= 's';
    }

    if (isset($data['link_url'])) {
        $linkUrl = trim($data['link_url']);
        if (!filter_var($linkUrl, FILTER_VALIDATE_URL)) {
            sendErrorResponse('Invalid URL format');
        }
        $updates[] = "link_url = ?";
        $params[] = $linkUrl;
        $types .= 's';
        $urlChanged = true;
        $newUrl = $linkUrl;
    }

    if (isset($data['display_name'])) {
        $updates[] = "display_name = ?";
        $params[] = trim($data['display_name']);
        $types .= 's';
    }

    if (isset($data['description'])) {
        $updates[] = "description = ?";
        $params[] = trim($data['description']);
        $types .= 's';
    }

    if (isset($data['icon_name'])) {
        $updates[] = "icon_name = ?";
        $params[] = trim($data['icon_name']);
        $types .= 's';
    }

    if (isset($data['display_order'])) {
        $updates[] = "display_order = ?";
        $params[] = intval($data['display_order']);
        $types .= 'i';
    }

    if (isset($data['is_active'])) {
        $updates[] = "is_active = ?";
        $params[] = intval($data['is_active']);
        $types .= 'i';
    }

    // If URL changed, regenerate QR code
    if ($urlChanged) {
        $qrCodeData = generateQRCodeBase64($newUrl);
        $updates[] = "qr_code_data = ?";
        $params[] = $qrCodeData;
        $types .= 's';
    }

    if (empty($updates)) {
        sendErrorResponse('No fields to update');
    }

    // Add link_id to params
    $params[] = $linkId;
    $types .= 'i';

    $query = "UPDATE coiffure_social_links SET " . implode(', ', $updates) . " WHERE link_id = ?";

    $stmt = $conn->prepare($query);

    if (!$stmt) {
        sendErrorResponse('Failed to prepare update statement', 500);
    }

    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        error_log("Failed to update social link: " . $stmt->error);
        sendErrorResponse('Failed to update social link', 500);
    }

    if ($stmt->affected_rows === 0) {
        $stmt->close();
        sendErrorResponse('Social link not found or no changes made', 404);
    }

    $stmt->close();

    // Log audit
    logAudit($conn, 'social_link', $linkId, 'update', 'Updated social link', 'system');

    $response = [
        'success' => true,
        'message' => 'Social link updated successfully'
    ];

    if ($urlChanged) {
        $response['qr_code_data'] = $qrCodeData;
    }

    sendJsonResponse($response);
}

/**
 * Handle DELETE request - Delete social link
 */
function handleDelete($conn) {
    // Get link_id from query parameter
    if (!isset($_GET['link_id'])) {
        sendErrorResponse('link_id parameter is required');
    }

    $linkId = intval($_GET['link_id']);

    // Delete from database
    $stmt = $conn->prepare("DELETE FROM coiffure_social_links WHERE link_id = ?");

    if (!$stmt) {
        sendErrorResponse('Failed to prepare delete statement', 500);
    }

    $stmt->bind_param("i", $linkId);

    if (!$stmt->execute()) {
        error_log("Failed to delete social link: " . $stmt->error);
        sendErrorResponse('Failed to delete social link', 500);
    }

    if ($stmt->affected_rows === 0) {
        $stmt->close();
        sendErrorResponse('Social link not found', 404);
    }

    $stmt->close();

    // Log audit
    logAudit($conn, 'social_link', $linkId, 'delete', 'Deleted social link', 'system');

    sendJsonResponse([
        'success' => true,
        'message' => 'Social link deleted successfully'
    ]);
}

/**
 * Generate QR code as base64 data URL
 * Uses PHP GD library to create a simple QR code representation
 * For production, consider using a proper QR code library like phpqrcode or endroid/qr-code
 */
function generateQRCodeBase64($url) {
    // For now, return a placeholder
    // In production, you should use a proper QR code generation library
    // or call an external API
    return 'data:image/svg+xml;base64,' . base64_encode(generateQRCodeSVG($url));
}

/**
 * Generate simple QR code SVG
 * Note: This is a placeholder. For production use a proper QR code library.
 */
function generateQRCodeSVG($url) {
    // This is a simplified placeholder
    // In production, use libraries like phpqrcode or endroid/qr-code
    $encoded = urlencode($url);

    // Using Google Charts API as a fallback (note: this is deprecated but works for demo)
    // For production, use a PHP QR code library
    $qrApiUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . $encoded;

    // Return SVG that embeds the image
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 300" width="300" height="300">
        <rect width="300" height="300" fill="white"/>
        <image href="' . htmlspecialchars($qrApiUrl) . '" width="300" height="300"/>
    </svg>';
}
