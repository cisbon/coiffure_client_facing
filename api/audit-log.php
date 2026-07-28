<?php
/**
 * Audit log
 * -------------------------------------------------------------------
 *   GET audit-log.php?action=list    → filterable log
 *   GET audit-log.php?action=filters → the values the filter bar offers
 *
 * Filters: from, to, entity_type, action, performed_by, q, page, per_page.
 *
 * Visibility is decided here, not by the caller:
 *
 *   admin                    everything
 *   admin_delegate           everything EXCEPT actions performed by an
 *                            administrator -- a delegate must not be able to
 *                            audit the people who supervise them
 *   customer_admin           their own salons only
 *   customer_admin_delegate  their own salons only
 *
 * The salon roles additionally never see platform-level rows (salon_id NULL),
 * which is where billing, plan changes and cross-salon actions land.
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

$action = $_GET['action'] ?? 'list';

if ($action === 'filters') {
    handleFilters($conn, $user);
}

handleList($conn, $user);

/**
 * The WHERE fragment that limits a caller to what their role may see.
 *
 * @return array ['sql' => string, 'values' => array]
 */
function visibilityScope(mysqli $conn, array $user): array
{
    $role = $user['role'] ?? '';

    if ($role === 'admin') {
        return ['sql' => '1 = 1', 'values' => []];
    }

    if ($role === 'admin_delegate') {
        // Two sources of "this was an administrator". performed_by_role is
        // authoritative, but config.php's older logAudit() only ever wrote a
        // username -- and it is still what records logins. Without the second
        // clause every administrator sign-in would be visible here.
        return [
            'sql' => "(
                (a.performed_by_role IS NOT NULL AND a.performed_by_role <> 'admin')
                OR (a.performed_by_role IS NULL AND NOT EXISTS (
                        SELECT 1 FROM coiffure_users u
                        WHERE u.username = a.performed_by AND u.role = 'admin'))
            )",
            'values' => [],
        ];
    }

    $salonIds = getAccessibleSalonIds($conn, $user);
    if (empty($salonIds)) {
        sendErrorResponse('No salon is assigned to this account.', 403);
    }
    $in = salonInClause($salonIds);

    // salon_id IS NULL is a platform-level row, which a salon role never sees.
    return ['sql' => "a.salon_id IN {$in['sql']}", 'values' => $in['values']];
}

/** True once migration 024 has widened the table and added the new columns. */
function auditColumnsReady(mysqli $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        $res = $conn->query("SHOW COLUMNS FROM coiffure_audit_log LIKE 'performed_by_role'");
        $ready = $res && $res->num_rows > 0;
    }
    return $ready;
}

function handleList(mysqli $conn, array $user): void
{
    if (!auditColumnsReady($conn)) {
        sendErrorResponse('The audit view needs migration 024 to be applied first.', 503);
    }

    $scope = visibilityScope($conn, $user);
    $where = [$scope['sql']];
    $values = $scope['values'];

    // --- filters -------------------------------------------------
    $from = trim((string)($_GET['from'] ?? ''));
    if ($from !== '') {
        $where[] = 'a.created_at >= ?';
        $values[] = $from . ' 00:00:00';
    }

    $to = trim((string)($_GET['to'] ?? ''));
    if ($to !== '') {
        $where[] = 'a.created_at <= ?';
        $values[] = $to . ' 23:59:59';
    }

    $entityType = trim((string)($_GET['entity_type'] ?? ''));
    if ($entityType !== '') {
        $where[] = 'a.entity_type = ?';
        $values[] = $entityType;
    }

    $actionFilter = trim((string)($_GET['action_type'] ?? ''));
    if ($actionFilter !== '') {
        $where[] = 'a.action = ?';
        $values[] = $actionFilter;
    }

    $performedBy = trim((string)($_GET['performed_by'] ?? ''));
    if ($performedBy !== '') {
        $where[] = 'a.performed_by = ?';
        $values[] = $performedBy;
    }

    $search = trim((string)($_GET['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(a.action_details LIKE ? OR a.performed_by LIKE ?)';
        $values[] = "%$search%";
        $values[] = "%$search%";
    }

    // A salon role may narrow further within what it already sees; the scope
    // clause above still applies, so this cannot widen anything.
    $salonFilter = trim((string)($_GET['salon_id'] ?? ''));
    if ($salonFilter !== '' && $salonFilter !== 'all') {
        $where[] = 'a.salon_id = ?';
        $values[] = (int)$salonFilter;
    }

    $whereSql = implode(' AND ', $where);

    // --- count ---------------------------------------------------
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM coiffure_audit_log a WHERE $whereSql");
    if (!$countStmt) {
        sendErrorResponse('Failed to read the audit log.', 500);
    }
    bindTyped($countStmt, $values);
    $countStmt->execute();
    $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    // --- page ----------------------------------------------------
    $perPage = max(10, min(200, (int)($_GET['per_page'] ?? 50)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    // LIMIT/OFFSET are clamped ints, which mysqli will not bind as parameters.
    $stmt = $conn->prepare(
        "SELECT a.log_id, a.entity_type, a.entity_id, a.action, a.action_details,
                a.performed_by, a.performed_by_role, a.salon_id, a.ip_address, a.created_at,
                s.salon_name
         FROM coiffure_audit_log a
         LEFT JOIN coiffure_salons s ON s.salon_id = a.salon_id
         WHERE $whereSql
         ORDER BY a.created_at DESC, a.log_id DESC
         LIMIT $perPage OFFSET $offset"
    );
    if (!$stmt) {
        sendErrorResponse('Failed to read the audit log.', 500);
    }
    bindTyped($stmt, $values);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    sendJsonResponse([
        'success' => true,
        'entries' => array_map(static fn($row) => [
            'log_id'         => (int)$row['log_id'],
            'entity_type'    => $row['entity_type'],
            'entity_id'      => (int)$row['entity_id'],
            'action'         => $row['action'],
            'action_details' => $row['action_details'],
            'performed_by'   => $row['performed_by'],
            'performed_by_role' => $row['performed_by_role'],
            'salon_id'       => $row['salon_id'] !== null ? (int)$row['salon_id'] : null,
            'salon_name'     => $row['salon_name'],
            'ip_address'     => $row['ip_address'],
            'created_at'     => $row['created_at'],
        ], $rows),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int)ceil($total / $perPage),
    ], 200);
}

/**
 * Distinct values for the filter dropdowns, restricted to the same scope as the
 * list -- otherwise the dropdown would leak the existence of actions the caller
 * cannot see.
 */
function handleFilters(mysqli $conn, array $user): void
{
    if (!auditColumnsReady($conn)) {
        sendErrorResponse('The audit view needs migration 024 to be applied first.', 503);
    }

    $scope = visibilityScope($conn, $user);
    $out = ['success' => true];

    foreach (['entity_type' => 'entity_types', 'action' => 'actions', 'performed_by' => 'performers'] as $column => $key) {
        $stmt = $conn->prepare(
            "SELECT DISTINCT a.$column AS value
             FROM coiffure_audit_log a
             WHERE {$scope['sql']} AND a.$column IS NOT NULL AND a.$column <> ''
             ORDER BY value
             LIMIT 200"
        );
        if (!$stmt) {
            $out[$key] = [];
            continue;
        }
        bindTyped($stmt, $scope['values']);
        $stmt->execute();
        $result = $stmt->get_result();

        $values = [];
        while ($row = $result->fetch_assoc()) {
            $values[] = $row['value'];
        }
        $stmt->close();
        $out[$key] = $values;
    }

    sendJsonResponse($out, 200);
}
