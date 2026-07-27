<?php
/**
 * Dashboard Home statistics
 * -------------------------------------------------------------------
 *   GET dashboard-stats.php[?salon_id=N|all][&range=8]
 *
 * Everything the Übersicht screen needs, in one round trip:
 *   kpis            today's check-ins, new registrations this week, active
 *                   members, birthdays in the next 7 days, campaigns sent this
 *                   month -- each with a trend against the previous period and
 *                   a sparkline series
 *   visits          daily check-in counts for the last N weeks, plus the same
 *                   data bucketed weekly (the UI toggles between them without
 *                   a second request)
 *   growth          new members vs. customers who have gone inactive, by week
 *   birthdays       the next five upcoming birthdays
 *   salons          per-salon totals, only when scope=all
 *
 * Access: view_insights, scoped by resolveSalonScopeList() so ?salon_id=all
 * aggregates exactly the salons the caller may see and nothing more.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendErrorResponse('Method not allowed. Use GET.', 405);
}

$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

$user = requireAuth($conn);
requireDashboardAccess($user);

$salonIds = resolveSalonScopeList($conn, $user, $_GET['salon_id'] ?? null);
requirePermission($conn, $user, 'view_insights', count($salonIds) === 1 ? $salonIds[0] : null);

/** Weeks of history for the charts. Clamped so a hand-edited URL cannot ask for years. */
$weeks = isset($_GET['range']) ? max(4, min(26, (int)$_GET['range'])) : 8;

/** Weeks without a visit before a customer counts as inactive (spec 3.1). */
const INACTIVE_WEEKS = 10;

$in = salonInClause($salonIds);

/**
 * Run a prepared statement scoped to the accessible salons.
 *
 * @param string $sql      must contain exactly one `IN {$in['sql']}` placeholder group
 * @param string $extraTypes types for any additional bound values
 * @param array  $extraArgs  the additional values, bound AFTER the salon ids
 */
function scopedQuery(mysqli $conn, array $in, string $sql, string $extraTypes = '', array $extraArgs = [])
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('dashboard-stats: prepare failed: ' . $conn->error . ' | ' . $sql);
        return null;
    }

    $types = $in['types'] . $extraTypes;
    $args = array_merge($in['values'], $extraArgs);
    if ($types !== '') {
        $stmt->bind_param($types, ...$args);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

function scalar(?array $rows, string $column, $fallback = 0)
{
    if (!$rows || !isset($rows[0][$column])) {
        return $fallback;
    }
    return $rows[0][$column];
}

/* ============================================================
   Daily check-in series
   ============================================================ */

$days = $weeks * 7;

$visitRows = scopedQuery(
    $conn,
    $in,
    "SELECT DATE(checked_in_at) AS day, COUNT(*) AS total
     FROM coiffure_visits
     WHERE salon_id IN {$in['sql']}
       AND checked_in_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
     GROUP BY DATE(checked_in_at)
     ORDER BY day",
    'i',
    [$days]
);

// Fill the gaps: a day with no check-ins must plot as 0, not vanish.
$visitsByDay = [];
foreach (($visitRows ?: []) as $row) {
    $visitsByDay[$row['day']] = (int)$row['total'];
}

$daily = [];
for ($offset = $days - 1; $offset >= 0; $offset--) {
    $date = date('Y-m-d', strtotime("-$offset day"));
    $daily[] = ['date' => $date, 'count' => $visitsByDay[$date] ?? 0];
}

// Weekly buckets, labelled by the Monday that starts each week.
$weekly = [];
foreach ($daily as $point) {
    $monday = date('Y-m-d', strtotime('monday this week', strtotime($point['date'])));
    if (!isset($weekly[$monday])) {
        $weekly[$monday] = 0;
    }
    $weekly[$monday] += $point['count'];
}
$weeklySeries = [];
foreach ($weekly as $weekStart => $count) {
    $weeklySeries[] = ['date' => $weekStart, 'count' => $count];
}

/* ============================================================
   KPI cards
   ============================================================ */

// --- today's check-ins, with yesterday as the comparison ---
$today = (int)scalar(scopedQuery(
    $conn,
    $in,
    "SELECT COUNT(*) AS c FROM coiffure_visits
     WHERE salon_id IN {$in['sql']} AND DATE(checked_in_at) = CURDATE()"
), 'c');

$yesterday = (int)scalar(scopedQuery(
    $conn,
    $in,
    "SELECT COUNT(*) AS c FROM coiffure_visits
     WHERE salon_id IN {$in['sql']} AND DATE(checked_in_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)"
), 'c');

// --- new registrations this week vs. last week ---
$newThisWeek = (int)scalar(scopedQuery(
    $conn,
    $in,
    "SELECT COUNT(*) AS c FROM coiffure_customers
     WHERE salon_id IN {$in['sql']} AND is_deleted = 0
       AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
), 'c');

$newPrevWeek = (int)scalar(scopedQuery(
    $conn,
    $in,
    "SELECT COUNT(*) AS c FROM coiffure_customers
     WHERE salon_id IN {$in['sql']} AND is_deleted = 0
       AND created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
       AND created_at <  DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
), 'c');

// --- active members: a member with at least one visit in the inactivity window ---
$activeMembers = (int)scalar(scopedQuery(
    $conn,
    $in,
    "SELECT COUNT(DISTINCT c.customer_id) AS c
     FROM coiffure_customers c
     JOIN coiffure_visits v ON v.customer_id = c.customer_id
     WHERE c.salon_id IN {$in['sql']} AND c.is_deleted = 0 AND c.is_member = 1
       AND v.checked_in_at >= DATE_SUB(CURDATE(), INTERVAL ? WEEK)",
    'i',
    [INACTIVE_WEEKS]
), 'c');

$totalMembers = (int)scalar(scopedQuery(
    $conn,
    $in,
    "SELECT COUNT(*) AS c FROM coiffure_customers
     WHERE salon_id IN {$in['sql']} AND is_deleted = 0 AND is_member = 1"
), 'c');

// --- birthdays in the next 7 days ---
// birth_day/birth_month are stored separately and the year is optional, so the
// comparison is built from a day-of-year style key that wraps at New Year.
$birthdayCount = (int)scalar(scopedQuery(
    $conn,
    $in,
    "SELECT COUNT(*) AS c FROM coiffure_customers
     WHERE salon_id IN {$in['sql']} AND is_deleted = 0
       AND birth_day IS NOT NULL AND birth_month IS NOT NULL
       AND (
            DATE_FORMAT(CONCAT(YEAR(CURDATE()), '-', LPAD(birth_month,2,'0'), '-', LPAD(birth_day,2,'0')), '%Y-%m-%d')
                BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
         OR DATE_FORMAT(CONCAT(YEAR(CURDATE())+1, '-', LPAD(birth_month,2,'0'), '-', LPAD(birth_day,2,'0')), '%Y-%m-%d')
                BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
       )"
), 'c');

// --- campaigns sent this month (0 until the campaigns module lands) ---
$campaignsSent = 0;
$campaignsPrev = 0;
if (migHasTable($conn, 'coiffure_campaigns')) {
    $campaignsSent = (int)scalar(scopedQuery(
        $conn,
        $in,
        "SELECT COUNT(*) AS c FROM coiffure_campaigns
         WHERE salon_id IN {$in['sql']} AND status = 'sent'
           AND completed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    ), 'c');

    $campaignsPrev = (int)scalar(scopedQuery(
        $conn,
        $in,
        "SELECT COUNT(*) AS c FROM coiffure_campaigns
         WHERE salon_id IN {$in['sql']} AND status = 'sent'
           AND completed_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
           AND completed_at <  DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    ), 'c');
}

/** Percentage change, or null when there is no baseline to compare against. */
function trend($current, $previous): ?float
{
    if ($previous == 0) {
        return $current > 0 ? null : 0.0;
    }
    return round((($current - $previous) / $previous) * 100, 1);
}

/** Last 14 daily counts, for the sparkline on the check-in card. */
$sparkVisits = array_map(
    static fn($p) => $p['count'],
    array_slice($daily, -14)
);

/* ============================================================
   Member growth: new members vs. newly inactive, by week
   ============================================================ */

$newMembersRows = scopedQuery(
    $conn,
    $in,
    "SELECT DATE_FORMAT(member_since, '%x-%v') AS wk, COUNT(*) AS total
     FROM coiffure_customers
     WHERE salon_id IN {$in['sql']} AND is_deleted = 0 AND is_member = 1
       AND member_since >= DATE_SUB(CURDATE(), INTERVAL ? WEEK)
     GROUP BY wk",
    'i',
    [$weeks]
);
$newMembersByWeek = [];
foreach (($newMembersRows ?: []) as $row) {
    $newMembersByWeek[$row['wk']] = (int)$row['total'];
}

// A customer "became inactive" INACTIVE_WEEKS after their last visit, not on
// the day of it -- so the bucket is last_visit + INACTIVE_WEEKS. Bucketing by
// the visit itself would always land outside the chart window (a lapsed
// customer's last visit is by definition older than the inactivity period) and
// the series would be permanently empty.
$inactiveRows = scopedQuery(
    $conn,
    $in,
    // INACTIVE_WEEKS is inlined rather than bound: scopedQuery() binds the
    // salon ids first, so a placeholder appearing before the IN clause would
    // take a salon id. It is a code constant, never request input.
    'SELECT DATE_FORMAT(DATE_ADD(last_visit, INTERVAL ' . INACTIVE_WEEKS . " WEEK), '%x-%v') AS wk,
            COUNT(*) AS total
     FROM (
        SELECT c.customer_id, MAX(v.checked_in_at) AS last_visit
        FROM coiffure_customers c
        JOIN coiffure_visits v ON v.customer_id = c.customer_id
        WHERE c.salon_id IN {$in['sql']} AND c.is_deleted = 0
        GROUP BY c.customer_id
        HAVING last_visit < DATE_SUB(CURDATE(), INTERVAL ? WEEK)
           AND last_visit >= DATE_SUB(CURDATE(), INTERVAL ? WEEK)
     ) AS lapsed
     GROUP BY wk",
    'ii',
    [INACTIVE_WEEKS, $weeks + INACTIVE_WEEKS]
);
$inactiveByWeek = [];
foreach (($inactiveRows ?: []) as $row) {
    $inactiveByWeek[$row['wk']] = (int)$row['total'];
}

$growth = [];
foreach ($weeklySeries as $point) {
    $key = date('o-W', strtotime($point['date']));
    // MySQL's %x-%v and PHP's o-W both use ISO weeks, but PHP zero-pads.
    $mysqlKey = date('o-', strtotime($point['date'])) . date('W', strtotime($point['date']));
    $growth[] = [
        'date'         => $point['date'],
        'new_members'  => $newMembersByWeek[$mysqlKey] ?? $newMembersByWeek[$key] ?? 0,
        'went_inactive' => $inactiveByWeek[$mysqlKey] ?? $inactiveByWeek[$key] ?? 0,
    ];
}

/* ============================================================
   Upcoming birthdays (next five)
   ============================================================ */

$birthdayRows = scopedQuery(
    $conn,
    $in,
    "SELECT customer_id, salon_id, full_name, first_name, email,
            birth_day, birth_month, birth_year,
            consent_email_marketing, consent_marketing,
            CASE
              WHEN (birth_month > MONTH(CURDATE()))
                OR (birth_month = MONTH(CURDATE()) AND birth_day >= DAY(CURDATE()))
              THEN DATE_FORMAT(CONCAT(YEAR(CURDATE()), '-', LPAD(birth_month,2,'0'), '-', LPAD(birth_day,2,'0')), '%Y-%m-%d')
              ELSE DATE_FORMAT(CONCAT(YEAR(CURDATE())+1, '-', LPAD(birth_month,2,'0'), '-', LPAD(birth_day,2,'0')), '%Y-%m-%d')
            END AS next_birthday
     FROM coiffure_customers
     WHERE salon_id IN {$in['sql']} AND is_deleted = 0
       AND birth_day IS NOT NULL AND birth_month IS NOT NULL
     ORDER BY next_birthday
     LIMIT 5"
);

// Has a birthday mail already gone out to them this year? Only answerable once
// the campaigns tables exist.
$sentThisYear = [];
if ($birthdayRows && migHasTable($conn, 'coiffure_campaign_recipients')) {
    $ids = array_map(static fn($r) => (int)$r['customer_id'], $birthdayRows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare(
        "SELECT DISTINCT r.customer_id
         FROM coiffure_campaign_recipients r
         JOIN coiffure_campaigns c ON c.campaign_id = r.campaign_id
         WHERE r.customer_id IN ($placeholders)
           AND c.auto_type = 'birthday'
           AND r.sent_at >= DATE_FORMAT(CURDATE(), '%Y-01-01')"
    );
    if ($stmt) {
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $sentThisYear[(int)$row['customer_id']] = true;
        }
        $stmt->close();
    }
}

$birthdays = [];
foreach (($birthdayRows ?: []) as $row) {
    $id = (int)$row['customer_id'];
    $birthdays[] = [
        'customer_id'   => $id,
        'salon_id'      => (int)$row['salon_id'],
        'name'          => $row['full_name'],
        'first_name'    => $row['first_name'],
        'email'         => $row['email'],
        'birth_day'     => (int)$row['birth_day'],
        'birth_month'   => (int)$row['birth_month'],
        'birth_year'    => $row['birth_year'] !== null ? (int)$row['birth_year'] : null,
        'next_birthday' => $row['next_birthday'],
        'days_until'    => (int)floor((strtotime($row['next_birthday']) - strtotime('today')) / 86400),
        // Only offer the send button when the customer actually consented.
        'can_email'     => (int)($row['consent_email_marketing'] ?? $row['consent_marketing'] ?? 0) === 1,
        'already_sent'  => isset($sentThisYear[$id]),
    ];
}

/* ============================================================
   Per-salon totals for the "Alle Salons" view
   ============================================================ */

$perSalon = [];
if (count($salonIds) > 1) {
    $perSalon = scopedQuery(
        $conn,
        $in,
        "SELECT s.salon_id, s.salon_name,
                (SELECT COUNT(*) FROM coiffure_customers c
                  WHERE c.salon_id = s.salon_id AND c.is_deleted = 0) AS customers,
                (SELECT COUNT(*) FROM coiffure_visits v
                  WHERE v.salon_id = s.salon_id
                    AND v.checked_in_at >= DATE_SUB(CURDATE(), INTERVAL ? WEEK)) AS visits
         FROM coiffure_salons s
         WHERE s.salon_id IN {$in['sql']}
         ORDER BY s.salon_name",
        'i',
        [$weeks]
    ) ?: [];

    $perSalon = array_map(static fn($r) => [
        'salon_id'   => (int)$r['salon_id'],
        'salon_name' => $r['salon_name'],
        'customers'  => (int)$r['customers'],
        'visits'     => (int)$r['visits'],
    ], $perSalon);
}

$totalCustomers = (int)scalar(scopedQuery(
    $conn,
    $in,
    "SELECT COUNT(*) AS c FROM coiffure_customers
     WHERE salon_id IN {$in['sql']} AND is_deleted = 0"
), 'c');

sendJsonResponse([
    'success' => true,
    'scope' => [
        'salon_ids'      => $salonIds,
        'is_aggregate'   => count($salonIds) > 1,
        'weeks'          => $weeks,
        'inactive_weeks' => INACTIVE_WEEKS,
    ],
    'kpis' => [
        'checkins_today' => [
            'value' => $today,
            'trend' => trend($today, $yesterday),
            'spark' => $sparkVisits,
        ],
        'new_registrations_week' => [
            'value' => $newThisWeek,
            'trend' => trend($newThisWeek, $newPrevWeek),
        ],
        'active_members' => [
            'value' => $activeMembers,
            'total' => $totalMembers,
        ],
        'birthdays_week' => [
            'value' => $birthdayCount,
        ],
        'campaigns_month' => [
            'value' => $campaignsSent,
            'trend' => trend($campaignsSent, $campaignsPrev),
        ],
        'total_customers' => [
            'value' => $totalCustomers,
        ],
    ],
    'visits' => [
        'daily'  => $daily,
        'weekly' => $weeklySeries,
    ],
    'growth'    => $growth,
    'birthdays' => $birthdays,
    'salons'    => $perSalon,
], 200);

/** Cheap table guard so the endpoint degrades before later migrations run. */
function migHasTable(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $safe = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '$safe'");
        $cache[$table] = $res && $res->num_rows > 0;
    }
    return $cache[$table];
}
