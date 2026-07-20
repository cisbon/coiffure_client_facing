<?php
/**
 * Employees API Endpoint
 * Returns the active stylist list for a salon (used to populate the
 * "Wunsch-Stylist" dropdown on the tablet registration form).
 *
 * GET /api/employees.php?salon_id=1
 */

require_once __DIR__ . '/config.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendErrorResponse('Method not allowed. Use GET.', 405);
}

$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

// This endpoint is public (the tablet form may be shown before login),
// but we still resolve salon_id from the session when available.
$salonId = null;
$token = getSessionToken();
if ($token) {
    $currentUser = validateSession($conn, $token);
    if ($currentUser) {
        $salonStmt = $conn->prepare(
            "SELECT salon_id FROM coiffure_user_salons WHERE user_id = ? LIMIT 1"
        );
        if ($salonStmt) {
            $salonStmt->bind_param("i", $currentUser['user_id']);
            $salonStmt->execute();
            $salonResult = $salonStmt->get_result();
            if ($salonResult->num_rows > 0) {
                $salonId = (int)$salonResult->fetch_assoc()['salon_id'];
            }
            $salonStmt->close();
        }
    }
}

if ($salonId === null) {
    $salonId = (int)($_GET['salon_id'] ?? DEFAULT_SALON_ID);
}

// Guard: table may not exist yet if migration 008 has not been applied.
$tableCheck = $conn->query("SHOW TABLES LIKE 'coiffure_employees'");
if (!$tableCheck || $tableCheck->num_rows === 0) {
    sendJsonResponse(['success' => true, 'employees' => []], 200);
}

$stmt = $conn->prepare(
    "SELECT employee_id, full_name, title
     FROM coiffure_employees
     WHERE salon_id = ? AND is_active = 1
     ORDER BY display_order ASC, full_name ASC"
);
if (!$stmt) {
    sendErrorResponse('Database query preparation failed', 500);
}

$stmt->bind_param("i", $salonId);
$stmt->execute();
$result = $stmt->get_result();

$employees = [];
while ($row = $result->fetch_assoc()) {
    $employees[] = [
        'employee_id' => (int)$row['employee_id'],
        'full_name'   => $row['full_name'],
        'title'       => $row['title'],
    ];
}
$stmt->close();
$conn->close();

sendJsonResponse(['success' => true, 'salon_id' => $salonId, 'employees' => $employees], 200);
