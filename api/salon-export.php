<?php
/**
 * Salon data export (Administrator only)
 * -------------------------------------------------------------------
 *   GET salon-export.php?salon_id=N&format=json  → full export as JSON
 *   GET salon-export.php?salon_id=N&format=csv   → the customer table as CSV
 *
 * Produced before a salon is suspended or terminated, so the operator can hand
 * the salon its own data (GDPR Art. 20) and keep a record of what was held.
 *
 * The export is always audited -- taking a copy of every customer a salon has
 * is precisely the kind of action an audit trail exists for.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendErrorResponse('Method not allowed.', 405);
}

$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

$user = requireAuth($conn);
requireDashboardAccess($user);

// Platform-level: exporting a whole salon is not something the salon's own
// staff does through this endpoint (they have the per-customer export).
requirePlatformRole($user);

$salonId = (int)($_GET['salon_id'] ?? 0);
if ($salonId <= 0) {
    sendErrorResponse('salon_id is required.', 400);
}

$salon = loadSalon($conn, $salonId);
$format = strtolower((string)($_GET['format'] ?? 'json'));

logAdminAudit(
    $conn, $user, 'salon', $salonId, 'data_export',
    "Salon data exported ($format)", $salonId
);

if ($format === 'csv') {
    exportCsv($conn, $salon);
}

exportJson($conn, $salon);

function loadSalon(mysqli $conn, int $salonId): array
{
    $stmt = $conn->prepare('SELECT * FROM coiffure_salons WHERE salon_id = ?');
    if (!$stmt) {
        sendErrorResponse('Failed to load the salon.', 500);
    }
    $stmt->bind_param('i', $salonId);
    $stmt->execute();
    $salon = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$salon) {
        sendErrorResponse('Salon not found.', 404);
    }
    return $salon;
}

/** Every row of one table belonging to this salon. */
function tableRows(mysqli $conn, string $table, string $column, int $salonId, string $order = ''): array
{
    $check = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    if (!$check || $check->num_rows === 0) {
        return [];
    }

    $orderSql = $order !== '' ? " ORDER BY $order" : '';
    $stmt = $conn->prepare("SELECT * FROM $table WHERE $column = ?$orderSql");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $salonId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function exportJson(mysqli $conn, array $salon): void
{
    $salonId = (int)$salon['salon_id'];

    // Nothing that could re-authenticate anyone leaves the building.
    unset($salon['staff_pin'], $salon['staff_pin_hash'], $salon['wifi_password']);

    $customers = tableRows($conn, 'coiffure_customers', 'salon_id', $salonId, 'customer_id');

    $export = [
        'exported_at' => date('c'),
        'salon' => $salon,
        'customers' => $customers,
        'visits' => tableRows($conn, 'coiffure_visits', 'salon_id', $salonId, 'visit_id'),
        'social_links' => tableRows($conn, 'coiffure_social_links', 'salon_id', $salonId, 'display_order'),
        'employees' => tableRows($conn, 'coiffure_employees', 'salon_id', $salonId, 'employee_id'),
        'segments' => tableRows($conn, 'coiffure_segments', 'salon_id', $salonId, 'segment_id'),
        'campaigns' => tableRows($conn, 'coiffure_campaigns', 'salon_id', $salonId, 'campaign_id'),
        'opening_hours' => tableRows($conn, 'coiffure_salon_hours', 'salon_id', $salonId, 'weekday'),
        'consent_history' => tableRows($conn, 'coiffure_consent_history', 'salon_id', $salonId, 'history_id'),
        'counts' => [],
    ];

    // Users are included by identity only: no hashes, no session material.
    $users = [];
    $userStmt = $conn->prepare(
        "SELECT DISTINCT u.user_id, u.username, u.email, u.full_name, u.role, u.is_active, u.created_at
         FROM coiffure_users u
         LEFT JOIN coiffure_user_salons us ON us.user_id = u.user_id AND us.salon_id = ?
         WHERE us.salon_id IS NOT NULL OR u.salon_id = ?"
    );
    if ($userStmt) {
        $userStmt->bind_param('ii', $salonId, $salonId);
        $userStmt->execute();
        $users = $userStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $userStmt->close();
    }
    $export['users'] = $users;

    foreach ($export as $key => $value) {
        if (is_array($value) && $key !== 'salon' && $key !== 'counts') {
            $export['counts'][$key] = count($value);
        }
    }

    $filename = sprintf('salon-%d-export-%s.json', $salonId, date('Y-m-d'));

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function exportCsv(mysqli $conn, array $salon): void
{
    $salonId = (int)$salon['salon_id'];
    $customers = tableRows($conn, 'coiffure_customers', 'salon_id', $salonId, 'customer_id');

    $filename = sprintf('salon-%d-kunden-%s.csv', $salonId, date('Y-m-d'));

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // BOM so Excel opens UTF-8 correctly, matching insights.php's export.
    fwrite($out, "\xEF\xBB\xBF");

    if (empty($customers)) {
        fputcsv($out, ['customer_id']);
        fclose($out);
        exit;
    }

    fputcsv($out, array_keys($customers[0]), ';');
    foreach ($customers as $row) {
        fputcsv($out, array_values($row), ';');
    }
    fclose($out);
    exit;
}
