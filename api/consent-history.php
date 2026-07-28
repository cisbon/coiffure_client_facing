<?php
/**
 * GDPR consent trail (read only)
 * -------------------------------------------------------------------
 *   GET consent-history.php                  → recent consent changes
 *   GET consent-history.php?customer_id=N    → one customer's full trail
 *
 * Deliberately read only. A consent record exists to prove what a customer
 * agreed to and when; an endpoint that could edit it would defeat its own
 * purpose. Changes are written by whatever collects the consent -- the tablet
 * registration flow and the dashboard customer profile.
 *
 * Access: view_insights, scoped to the caller's salons. There is no way to
 * request another salon's trail: the salon list comes from the session, and a
 * customer_id that belongs elsewhere returns 404.
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

$salonIds = resolveSalonScopeList($conn, $user, $_GET['salon_id'] ?? null);
foreach ($salonIds as $salonId) {
    requirePermission($conn, $user, 'view_insights', $salonId);
}

$check = $conn->query("SHOW TABLES LIKE 'coiffure_consent_history'");
if (!$check || $check->num_rows === 0) {
    sendErrorResponse('The consent trail needs migration 024 to be applied first.', 503);
}

$customerId = (int)($_GET['customer_id'] ?? 0);
if ($customerId > 0) {
    handleCustomer($conn, $salonIds, $customerId);
}

handleList($conn, $salonIds);

/**
 * Consent rows carry their own salon_id, but rows written before that column
 * was populated have NULL, so the customer is joined in and used as the
 * authority. That join is also what keeps the query inside the caller's salons.
 */
function handleList(mysqli $conn, array $salonIds): void
{
    $in = salonInClause($salonIds);
    $where = ["c.salon_id IN {$in['sql']}"];
    $values = $in['values'];

    $field = trim((string)($_GET['consent_field'] ?? ''));
    if ($field !== '') {
        $where[] = 'h.consent_field = ?';
        $values[] = $field;
    }

    $from = trim((string)($_GET['from'] ?? ''));
    if ($from !== '') {
        $where[] = 'h.created_at >= ?';
        $values[] = $from . ' 00:00:00';
    }

    $to = trim((string)($_GET['to'] ?? ''));
    if ($to !== '') {
        $where[] = 'h.created_at <= ?';
        $values[] = $to . ' 23:59:59';
    }

    $search = trim((string)($_GET['q'] ?? ''));
    if ($search !== '') {
        $where[] = "(CONCAT_WS(' ', c.first_name, c.last_name) LIKE ? OR c.email LIKE ?)";
        $values[] = "%$search%";
        $values[] = "%$search%";
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM coiffure_consent_history h
         JOIN coiffure_customers c ON c.customer_id = h.customer_id
         WHERE $whereSql"
    );
    if (!$countStmt) {
        sendErrorResponse('Failed to read the consent trail.', 500);
    }
    bindTyped($countStmt, $values);
    $countStmt->execute();
    $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $perPage = max(10, min(200, (int)($_GET['per_page'] ?? 50)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $stmt = $conn->prepare(
        "SELECT h.history_id, h.customer_id, h.consent_field, h.old_value, h.new_value,
                h.policy_version, h.source, h.changed_by, h.ip_address, h.created_at,
                c.first_name, c.last_name, c.email, c.salon_id
         FROM coiffure_consent_history h
         JOIN coiffure_customers c ON c.customer_id = h.customer_id
         WHERE $whereSql
         ORDER BY h.created_at DESC, h.history_id DESC
         LIMIT $perPage OFFSET $offset"
    );
    if (!$stmt) {
        sendErrorResponse('Failed to read the consent trail.', 500);
    }
    bindTyped($stmt, $values);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    sendJsonResponse([
        'success'  => true,
        'entries'  => array_map('presentConsentRow', $rows),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int)ceil($total / $perPage),
    ], 200);
}

function handleCustomer(mysqli $conn, array $salonIds, int $customerId): void
{
    $in = salonInClause($salonIds);

    $stmt = $conn->prepare(
        "SELECT customer_id, first_name, last_name, email, salon_id,
                consent_data_processing, consent_email_marketing, consent_sms_whatsapp,
                consent_postal, consent_marketing,
                policy_version_accepted, consent_timestamp, created_at
         FROM coiffure_customers
         WHERE customer_id = ? AND salon_id IN {$in['sql']}"
    );
    if (!$stmt) {
        sendErrorResponse('Failed to read the customer.', 500);
    }
    bindTyped($stmt, array_merge([$customerId], $in['values']));
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$customer) {
        sendErrorResponse('Customer not found.', 404);
    }

    $historyStmt = $conn->prepare(
        'SELECT history_id, customer_id, consent_field, old_value, new_value,
                policy_version, source, changed_by, ip_address, created_at
         FROM coiffure_consent_history
         WHERE customer_id = ?
         ORDER BY created_at DESC, history_id DESC
         LIMIT 200'
    );
    if (!$historyStmt) {
        sendErrorResponse('Failed to read the consent trail.', 500);
    }
    $historyStmt->bind_param('i', $customerId);
    $historyStmt->execute();
    $rows = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $historyStmt->close();

    sendJsonResponse([
        'success' => true,
        'customer' => [
            'customer_id' => (int)$customer['customer_id'],
            'name'  => trim($customer['first_name'] . ' ' . $customer['last_name']),
            'email' => $customer['email'],
            'current' => [
                'consent_data_processing' => (bool)$customer['consent_data_processing'],
                'consent_email_marketing' => (bool)$customer['consent_email_marketing'],
                'consent_sms_whatsapp'    => (bool)$customer['consent_sms_whatsapp'],
                'consent_postal'          => (bool)$customer['consent_postal'],
                'consent_marketing'       => (bool)$customer['consent_marketing'],
            ],
            'policy_version'    => $customer['policy_version_accepted'],
            'consent_timestamp' => $customer['consent_timestamp'],
            'registered_at'     => $customer['created_at'],
        ],
        'entries' => array_map(static function ($row) {
            $row['first_name'] = null;
            $row['last_name'] = null;
            $row['email'] = null;
            return presentConsentRow($row);
        }, $rows),
    ], 200);
}

function presentConsentRow(array $row): array
{
    $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

    return [
        'history_id'     => (int)$row['history_id'],
        'customer_id'    => (int)$row['customer_id'],
        'customer_name'  => $name !== '' ? $name : null,
        'customer_email' => $row['email'] ?? null,
        'consent_field'  => $row['consent_field'],
        // Stored as free-form strings ('1'/'0', 'true'/'false'); normalise so
        // the dashboard can render a yes/no badge without guessing.
        'old_value'      => normaliseConsentValue($row['old_value']),
        'new_value'      => normaliseConsentValue($row['new_value']),
        'policy_version' => $row['policy_version'],
        'source'         => $row['source'],
        'changed_by'     => $row['changed_by'],
        'ip_address'     => $row['ip_address'],
        'created_at'     => $row['created_at'],
    ];
}

function normaliseConsentValue($value): ?bool
{
    if ($value === null || $value === '') {
        return null;
    }
    return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'ja'], true);
}
