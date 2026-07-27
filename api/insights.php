<?php
/**
 * Customer Insights & Segmentation
 * -------------------------------------------------------------------
 *   GET insights.php?action=list     [filters…][&sort=][&dir=][&page=][&per_page=]
 *   GET insights.php?action=count    [filters…]            → matching count only
 *   GET insights.php?action=profile&customer_id=N          → full profile panel
 *   GET insights.php?action=export   [filters…][&scope=marketing|internal]
 *
 * Filters are read from the query string and normalised by
 * api/customer_filters.php, which is the single source of truth for how a
 * filter becomes SQL -- the same builder later resolves campaign recipients, so
 * a segment always selects the same people wherever it is used.
 *
 * Access: view_insights. The export is consent-aware: scope=marketing (the
 * default) returns only customers who consented to marketing e-mail and omits
 * fields they did not agree to share. scope=internal is the full record for the
 * salon's own use and is recorded in the audit log either way.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/customer_filters.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    sendErrorResponse('Method not allowed. Use GET or POST.', 405);
}

$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

$user = requireAuth($conn);
requireDashboardAccess($user);

$salonIds = resolveSalonScopeList($conn, $user, $_GET['salon_id'] ?? null);
requirePermission($conn, $user, 'view_insights', count($salonIds) === 1 ? $salonIds[0] : null);

$action = $_GET['action'] ?? 'list';

if ($method === 'POST') {
    // The only write this endpoint offers: the salon's own notes and tags on a
    // customer. Everything the customer supplied stays read-only here.
    if ($action !== 'notes') {
        sendErrorResponse('Unknown action for POST. Use notes.', 400);
    }
    handleSaveNotes($conn, $salonIds, $user);
}

switch ($action) {
    case 'list':
        handleList($conn, $salonIds);
        break;
    case 'count':
        handleCount($conn, $salonIds);
        break;
    case 'profile':
        handleProfile($conn, $salonIds, $user);
        break;
    case 'export':
        handleExport($conn, $salonIds, $user);
        break;
    default:
        sendErrorResponse('Unknown action. Use list, count, profile or export.', 400);
}

/**
 * Read the filter out of the query string.
 * A segment_id shortcut loads a saved filter instead of spelling it out.
 */
function filterFromRequest(mysqli $conn, array $salonIds): array
{
    if (!empty($_GET['segment_id'])) {
        $stmt = $conn->prepare(
            'SELECT filter_json, salon_id FROM coiffure_segments WHERE segment_id = ?'
        );
        if ($stmt) {
            $stmt->bind_param('i', $_GET['segment_id']);
            $stmt->execute();
            $segment = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // A segment belongs to one salon; refuse to run someone else's.
            if (!$segment || !in_array((int)$segment['salon_id'], $salonIds, true)) {
                sendErrorResponse('Segment not found.', 404);
            }
            return normaliseCustomerFilter($segment['filter_json']);
        }
    }

    return normaliseCustomerFilter($_GET);
}

function handleList(mysqli $conn, array $salonIds): void
{
    $filter = filterFromRequest($conn, $salonIds);

    $sort = $_GET['sort'] ?? 'created_at';
    $dir = $_GET['dir'] ?? 'desc';
    $perPage = isset($_GET['per_page']) ? max(1, min(500, (int)$_GET['per_page'])) : 50;
    $page = max(1, (int)($_GET['page'] ?? 1));

    $result = buildCustomerQuery(
        $conn,
        $salonIds,
        $filter,
        $sort,
        $dir,
        $perPage,
        ($page - 1) * $perPage
    );

    sendJsonResponse([
        'success'   => true,
        'customers' => array_map('presentCustomer', $result['rows']),
        'total'     => $result['total'],
        'page'      => $page,
        'per_page'  => $perPage,
        'filter'    => $filter,
    ], 200);
}

function handleCount(mysqli $conn, array $salonIds): void
{
    $filter = filterFromRequest($conn, $salonIds);

    sendJsonResponse([
        'success' => true,
        'total'   => countCustomers($conn, $salonIds, $filter),
        'filter'  => $filter,
    ], 200);
}

/**
 * Full profile for the slide-over panel: details, visit timeline, consent
 * state, campaign history and the salon's notes.
 */
function handleProfile(mysqli $conn, array $salonIds, array $user): void
{
    $customerId = (int)($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) {
        sendErrorResponse('customer_id is required.', 400);
    }

    $clause = buildCustomerWhere($salonIds, []);

    // notes/tags arrive with migration 025; select them only when present so
    // the panel still opens on a database that has not been migrated yet.
    $extra = columnExists($conn, 'coiffure_customers', 'notes')
        ? ', c.notes, c.tags, c.notes_updated_at'
        : '';

    $stmt = $conn->prepare(
        'SELECT ' . CUSTOMER_SELECT . $extra . "
         FROM coiffure_customers c
         WHERE {$clause['where']} AND c.customer_id = ?"
    );
    if (!$stmt) {
        sendErrorResponse('Failed to load customer.', 500);
    }

    $stmt->bind_param($clause['types'] . 'i', ...array_merge($clause['args'], [$customerId]));
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        // Either it does not exist or it belongs to another salon -- same answer.
        sendErrorResponse('Customer not found.', 404);
    }

    $customer = presentCustomer($row);
    $customer['notes'] = $row['notes'] ?? null;
    $customer['tags'] = array_values(array_filter(array_map(
        'trim',
        explode(',', (string)($row['tags'] ?? ''))
    )));
    $customer['notes_updated_at'] = $row['notes_updated_at'] ?? null;

    // --- visit timeline ---
    $visits = [];
    $stmt = $conn->prepare(
        'SELECT visit_id, checked_in_at, checkin_method
         FROM coiffure_visits WHERE customer_id = ?
         ORDER BY checked_in_at DESC LIMIT 100'
    );
    if ($stmt) {
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($visit = $result->fetch_assoc()) {
            $visits[] = [
                'visit_id'    => (int)$visit['visit_id'],
                'checked_in_at' => $visit['checked_in_at'],
                'method'      => $visit['checkin_method'],
            ];
        }
        $stmt->close();
    }

    // --- campaign history (empty until the campaigns module lands) ---
    $campaigns = [];
    if (tableExists($conn, 'coiffure_campaign_recipients')) {
        $stmt = $conn->prepare(
            "SELECT c.campaign_id, c.name, c.subject, c.kind, c.auto_type,
                    r.status, r.sent_at, r.opened_at, r.clicked_at, r.discount_code
             FROM coiffure_campaign_recipients r
             JOIN coiffure_campaigns c ON c.campaign_id = r.campaign_id
             WHERE r.customer_id = ?
             ORDER BY r.sent_at DESC, r.recipient_id DESC
             LIMIT 50"
        );
        if ($stmt) {
            $stmt->bind_param('i', $customerId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($campaign = $result->fetch_assoc()) {
                $campaigns[] = $campaign;
            }
            $stmt->close();
        }
    }

    // --- consent change history ---
    $consentHistory = [];
    if (tableExists($conn, 'coiffure_consent_history')) {
        $stmt = $conn->prepare(
            'SELECT consent_field, old_value, new_value, source, ip_address, created_at
             FROM coiffure_consent_history
             WHERE customer_id = ? ORDER BY created_at DESC LIMIT 50'
        );
        if ($stmt) {
            $stmt->bind_param('i', $customerId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($entry = $result->fetch_assoc()) {
                $consentHistory[] = $entry;
            }
            $stmt->close();
        }
    }

    sendJsonResponse([
        'success'         => true,
        'customer'        => $customer,
        'visits'          => $visits,
        'campaigns'       => $campaigns,
        'consent_history' => $consentHistory,
    ], 200);
}

/**
 * CSV export.
 *
 * scope=marketing (default): only customers who consented to marketing e-mail,
 * and only the fields needed to run a campaign. Postal address is included
 * solely for customers who ticked consent_postal.
 * scope=internal: the salon's own full record.
 */
function handleExport(mysqli $conn, array $salonIds, array $user): void
{
    $filter = filterFromRequest($conn, $salonIds);
    $scope = ($_GET['scope'] ?? 'marketing') === 'internal' ? 'internal' : 'marketing';

    if ($scope === 'marketing') {
        // Force the consent condition on rather than trusting the caller.
        $filter['consent_email'] = true;
    }

    $result = buildCustomerQuery($conn, $salonIds, $filter, $_GET['sort'] ?? 'created_at', $_GET['dir'] ?? 'desc');
    $rows = $result['rows'];

    $columns = $scope === 'internal'
        ? ['customer_id', 'full_name', 'first_name', 'last_name', 'gender', 'title',
           'email', 'phone', 'mobile', 'birth_day', 'birth_month', 'birth_year',
           'zip', 'city', 'is_member', 'member_id', 'member_since',
           'referral_source', 'visit_count', 'last_visit', 'created_at',
           'consent_email_marketing', 'consent_sms_whatsapp']
        : ['full_name', 'first_name', 'last_name', 'email',
           'birth_day', 'birth_month', 'zip', 'city', 'is_member', 'visit_count', 'last_visit'];

    $filename = sprintf('kunden-%s-%s.csv', $scope, date('Y-m-d'));

    // Audit before streaming: a data export is a GDPR-relevant action.
    logAdminAudit(
        $conn,
        $user,
        'customer',
        0,
        'data_export',
        sprintf('CSV export (%s): %d rows, filter %s', $scope, count($rows), json_encode($filter)),
        count($salonIds) === 1 ? $salonIds[0] : null
    );

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');

    // BOM so Excel opens the umlauts correctly.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $columns, ';');

    foreach ($rows as $row) {
        $line = [];
        foreach ($columns as $column) {
            $value = $row[$column] ?? '';
            if ($column === 'is_member') {
                $value = (int)$value === 1 ? 'ja' : 'nein';
            }
            if (str_starts_with($column, 'consent_')) {
                $value = (int)$value === 1 ? 'ja' : 'nein';
            }
            $line[] = $value;
        }
        fputcsv($out, $line, ';');
    }

    fclose($out);
    exit;
}

/**
 * Save the salon's own notes and tags for a customer.
 * Requires view_insights (the permission that grants the customer screen) and
 * is scoped so a salon can only annotate its own customers.
 */
function handleSaveNotes(mysqli $conn, array $salonIds, array $user): void
{
    if (!columnExists($conn, 'coiffure_customers', 'notes')) {
        sendErrorResponse('Notes are unavailable until migration 025 has been applied.', 503);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $customerId = (int)($input['customer_id'] ?? 0);
    if ($customerId <= 0) {
        sendErrorResponse('customer_id is required.', 400);
    }

    // Confirm the customer belongs to a salon this user may reach.
    $clause = buildCustomerWhere($salonIds, []);
    $check = $conn->prepare(
        "SELECT c.customer_id, c.salon_id FROM coiffure_customers c
         WHERE {$clause['where']} AND c.customer_id = ?"
    );
    if (!$check) {
        sendErrorResponse('Failed to save notes.', 500);
    }
    $check->bind_param($clause['types'] . 'i', ...array_merge($clause['args'], [$customerId]));
    $check->execute();
    $target = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$target) {
        sendErrorResponse('Customer not found.', 404);
    }

    $notes = isset($input['notes']) ? trim((string)$input['notes']) : '';
    $tags = $input['tags'] ?? [];
    if (is_string($tags)) {
        $tags = explode(',', $tags);
    }
    $tags = array_values(array_filter(array_map('trim', (array)$tags)));
    $tagString = mb_substr(implode(',', $tags), 0, 255);

    $stmt = $conn->prepare(
        'UPDATE coiffure_customers
         SET notes = ?, tags = ?, notes_updated_at = NOW(), notes_updated_by = ?
         WHERE customer_id = ?'
    );
    if (!$stmt) {
        sendErrorResponse('Failed to save notes.', 500);
    }

    $userId = (int)$user['user_id'];
    $stmt->bind_param('ssii', $notes, $tagString, $userId, $customerId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        sendErrorResponse('Failed to save notes.', 500);
    }

    logAdminAudit(
        $conn,
        $user,
        'customer',
        $customerId,
        'update',
        'Notes/tags updated',
        (int)$target['salon_id']
    );

    sendJsonResponse(['success' => true, 'notes' => $notes, 'tags' => $tags], 200);
}

function tableExists(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $safe = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '$safe'");
        $cache[$table] = $res && $res->num_rows > 0;
    }
    return $cache[$table];
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = "$table.$column";
    if (!isset($cache[$key])) {
        $safe = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$safe'");
        $cache[$key] = $res && $res->num_rows > 0;
    }
    return $cache[$key];
}
