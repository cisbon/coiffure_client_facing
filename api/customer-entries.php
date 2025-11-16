<?php
/**
 * SalonLyft API - Customer Entries
 * Fetch customer onboarding entries with search/filter capabilities
 */

require_once 'config.php';

// Set CORS headers
setCorsHeaders();

// Get database connection
$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

// Check authentication (optional but recommended for filtering)
$currentUser = null;
$token = getSessionToken();
if ($token) {
    $currentUser = validateSession($conn, $token);
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendErrorResponse('Method not allowed', 405);
}

// Get query parameters
$salonId = isset($_GET['salon_id']) ? intval($_GET['salon_id']) : null;
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$includeDeleted = isset($_GET['include_deleted']) && $_GET['include_deleted'] === 'true';

// Check if user is customer_admin or customer_admin_delegate or customer_facing_tablet_user
$isCustomerRole = $currentUser && in_array($currentUser['role'], ['customer_admin', 'customer_admin_delegate', 'customer_facing_tablet_user']);

// Build the SQL query
$query = "SELECT
    customer_id,
    salon_id,
    full_name,
    email,
    phone,
    consent_marketing,
    consent_data_processing,
    consent_cancellation_policy,
    consent_timestamp,
    policy_version_accepted,
    created_at,
    updated_at,
    is_deleted
FROM coiffure_customers";

$conditions = [];
$params = [];
$types = '';

// If customer role, only show entries for their assigned salons
if ($isCustomerRole) {
    $conditions[] = "EXISTS (
        SELECT 1 FROM coiffure_user_salons us
        WHERE us.salon_id = coiffure_customers.salon_id
        AND us.user_id = ?
    )";
    $params[] = $currentUser['user_id'];
    $types .= 'i';
}

// Filter by salon_id if provided
if ($salonId) {
    $conditions[] = "salon_id = ?";
    $params[] = $salonId;
    $types .= 'i';
}

// Filter out deleted customers unless explicitly requested
if (!$includeDeleted) {
    $conditions[] = "is_deleted = 0";
}

// Add search filter if provided
if (!empty($searchQuery)) {
    $searchPattern = '%' . $searchQuery . '%';
    $conditions[] = "(full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $types .= 'sss';
}

// Add WHERE clause if we have conditions
if (count($conditions) > 0) {
    $query .= " WHERE " . implode(' AND ', $conditions);
}

// Order by most recent first
$query .= " ORDER BY created_at DESC";

// Prepare and execute statement
$stmt = $conn->prepare($query);

if (!$stmt) {
    error_log("Failed to prepare customer entries query: " . $conn->error);
    sendErrorResponse('Failed to prepare query', 500);
}

// Bind parameters if we have any
if (count($params) > 0) {
    $stmt->bind_param($types, ...$params);
}

if (!$stmt->execute()) {
    error_log("Failed to execute customer entries query: " . $stmt->error);
    sendErrorResponse('Failed to fetch customer entries', 500);
}

$result = $stmt->get_result();
$customers = [];

while ($row = $result->fetch_assoc()) {
    // Format the data for response
    $customers[] = [
        'customer_id' => $row['customer_id'],
        'salon_id' => $row['salon_id'],
        'full_name' => $row['full_name'],
        'email' => $row['email'],
        'phone' => $row['phone'],
        'consent_marketing' => (bool)$row['consent_marketing'],
        'consent_data_processing' => (bool)$row['consent_data_processing'],
        'consent_cancellation_policy' => (bool)$row['consent_cancellation_policy'],
        'consent_timestamp' => $row['consent_timestamp'],
        'policy_version' => $row['policy_version_accepted'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'is_deleted' => (bool)$row['is_deleted']
    ];
}

$stmt->close();

// Return response
sendJsonResponse([
    'success' => true,
    'data' => $customers,
    'count' => count($customers),
    'search_query' => $searchQuery,
    'salon_id' => $salonId
]);
