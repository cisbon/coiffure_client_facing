<?php
/**
 * Campaign cron runner
 * -------------------------------------------------------------------
 *   curl "https://<host>/coiffure/api/cron-campaigns.php?token=<CRON_TOKEN>"
 *   php api/cron-campaigns.php            (CLI, no token needed)
 *
 * Does two things on every run, for every active salon:
 *   1. sends any one-time campaign whose scheduled_at has passed
 *   2. evaluates the four automatic campaign types and mails whoever became
 *      due since the last run
 *
 * Run it hourly. It is safe to run more often: every automatic type checks
 * coiffure_campaign_recipients before sending, so nobody is mailed twice, and
 * the salon's spam limit applies here exactly as it does to a manual send.
 *
 * The dashboard's "Jetzt ausführen" button hits the same endpoint with
 * ?salon_id=N to run just that salon.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/campaign_engine.php';

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');

    $expected = getenv('CRON_TOKEN') ?: '';
    $provided = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';

    // No token configured means the endpoint stays shut rather than open.
    if ($expected === '' || !is_string($provided) || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo "Forbidden. A valid CRON_TOKEN is required.\n";
        exit;
    }
}

$conn = getDbConnection();
if (!$conn) {
    http_response_code(500);
    echo "Database connection failed\n";
    exit;
}

$tablesReady = $conn->query("SHOW TABLES LIKE 'coiffure_automatic_campaigns'");
if (!$tablesReady || $tablesReady->num_rows === 0) {
    echo "Campaign tables are not present; run migration 020 first.\n";
    exit;
}

$startedAt = microtime(true);
$onlySalon = isset($_GET['salon_id']) ? (int)$_GET['salon_id'] : null;

echo "Campaign run " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('=', 40) . "\n";

$totals = ['scheduled' => 0, 'birthday' => 0, 'we_miss_you' => 0, 'thank_you' => 0, 'referral_reminder' => 0];

/* ============================================================
   1. Scheduled one-time campaigns
   ============================================================ */

$sql = "SELECT * FROM coiffure_campaigns
        WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW()"
     . ($onlySalon ? ' AND salon_id = ' . $onlySalon : '');

$due = $conn->query($sql);
while ($due && $campaign = $due->fetch_assoc()) {
    $result = executeCampaign($conn, $campaign);
    $totals['scheduled'] += $result['sent'];
    echo sprintf(
        "  scheduled '%s' (salon %d): sent %d, skipped %d, failed %d\n",
        $campaign['name'],
        $campaign['salon_id'],
        $result['sent'],
        $result['skipped'],
        $result['failed']
    );
}

/* ============================================================
   2. Automatic campaigns per salon
   ============================================================ */

$salonSql = 'SELECT * FROM coiffure_salons WHERE is_active = 1'
          . ($onlySalon ? ' AND salon_id = ' . $onlySalon : '');
$salons = $conn->query($salonSql);

while ($salons && $salon = $salons->fetch_assoc()) {
    $salonId = (int)$salon['salon_id'];

    $stmt = $conn->prepare('SELECT * FROM coiffure_automatic_campaigns WHERE salon_id = ? AND enabled = 1');
    if (!$stmt) {
        continue;
    }
    $stmt->bind_param('i', $salonId);
    $stmt->execute();
    $autos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($autos as $auto) {
        $type = $auto['type'];
        $candidates = findAutoCandidates($conn, $salon, $auto);

        if (empty($candidates)) {
            continue;
        }

        $sent = sendAutoCampaign($conn, $salon, $auto, $candidates);
        $totals[$type] = ($totals[$type] ?? 0) + $sent;

        echo sprintf("  %-18s (salon %d): %d sent\n", $type, $salonId, $sent);

        $update = $conn->prepare('UPDATE coiffure_automatic_campaigns SET last_run_at = NOW() WHERE auto_id = ?');
        if ($update) {
            $update->bind_param('i', $auto['auto_id']);
            $update->execute();
            $update->close();
        }
    }
}

echo "\nDone in " . round(microtime(true) - $startedAt, 2) . "s: "
   . implode(', ', array_map(static fn($k, $v) => "$k $v", array_keys($totals), $totals)) . "\n";

$conn->close();

/* ============================================================
   Candidate selection per automatic type
   ============================================================ */

/**
 * Who is due for this automatic campaign right now?
 *
 * Each type excludes anybody who already received this campaign type inside the
 * relevant period, so repeated runs are harmless.
 */
function findAutoCandidates(mysqli $conn, array $salon, array $auto): array
{
    $salonId = (int)$salon['salon_id'];
    $value = (int)$auto['trigger_value'];

    switch ($auto['type']) {
        case 'birthday':
            return birthdayCandidates($conn, $salon);

        case 'we_miss_you':
            // No visit for N weeks, and not already reminded in the last 90 days.
            return sqlCandidates(
                $conn,
                $salonId,
                "AND NOT EXISTS (
                     SELECT 1 FROM coiffure_visits v
                     WHERE v.customer_id = c.customer_id
                       AND v.checked_in_at >= DATE_SUB(CURDATE(), INTERVAL ? WEEK))
                 AND EXISTS (
                     SELECT 1 FROM coiffure_visits v2 WHERE v2.customer_id = c.customer_id)
                 AND NOT EXISTS (
                     SELECT 1 FROM coiffure_campaign_recipients r
                     JOIN coiffure_campaigns cc ON cc.campaign_id = r.campaign_id
                     WHERE r.customer_id = c.customer_id AND cc.auto_type = 'we_miss_you'
                       AND r.sent_at >= DATE_SUB(NOW(), INTERVAL 90 DAY))",
                'i',
                [$value]
            );

        case 'thank_you':
            // Reached exactly the Nth visit and never thanked before.
            return sqlCandidates(
                $conn,
                $salonId,
                "AND (SELECT COUNT(*) FROM coiffure_visits v WHERE v.customer_id = c.customer_id) >= ?
                 AND NOT EXISTS (
                     SELECT 1 FROM coiffure_campaign_recipients r
                     JOIN coiffure_campaigns cc ON cc.campaign_id = r.campaign_id
                     WHERE r.customer_id = c.customer_id AND cc.auto_type = 'thank_you')",
                'i',
                [$value]
            );

        case 'referral_reminder':
            // Members who signed up N days ago and have not referred anybody.
            // referral_source records how a customer heard about the salon, so
            // "nobody named them" is the closest signal available.
            return sqlCandidates(
                $conn,
                $salonId,
                "AND c.is_member = 1
                 AND c.member_since IS NOT NULL
                 AND c.member_since <= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                 AND NOT EXISTS (
                     SELECT 1 FROM coiffure_customers ref
                     WHERE ref.salon_id = c.salon_id AND ref.referral_source = 'Empfehlung'
                       AND ref.created_at >= c.member_since)
                 AND NOT EXISTS (
                     SELECT 1 FROM coiffure_campaign_recipients r
                     JOIN coiffure_campaigns cc ON cc.campaign_id = r.campaign_id
                     WHERE r.customer_id = c.customer_id AND cc.auto_type = 'referral_reminder')",
                'i',
                [$value]
            );
    }

    return [];
}

/**
 * Birthday candidates: the salon's configured days_before window, and not
 * already wished this calendar year.
 */
function birthdayCandidates(mysqli $conn, array $salon): array
{
    // The birthday campaign takes its timing from the salon settings (spec 3.3),
    // not from the automatic-campaign row.
    if ((int)($salon['birthday_enabled'] ?? 0) !== 1) {
        return [];
    }

    $daysBefore = max(0, (int)($salon['birthday_days_before'] ?? 0));

    return sqlCandidates(
        $conn,
        (int)$salon['salon_id'],
        "AND c.birth_day IS NOT NULL AND c.birth_month IS NOT NULL
         AND DATE_FORMAT(
                 CONCAT(YEAR(CURDATE()), '-', LPAD(c.birth_month,2,'0'), '-', LPAD(c.birth_day,2,'0')),
                 '%Y-%m-%d'
             ) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
         AND NOT EXISTS (
             SELECT 1 FROM coiffure_campaign_recipients r
             JOIN coiffure_campaigns cc ON cc.campaign_id = r.campaign_id
             WHERE r.customer_id = c.customer_id AND cc.auto_type = 'birthday'
               AND r.sent_at >= DATE_FORMAT(CURDATE(), '%Y-01-01'))",
        'i',
        [$daysBefore]
    );
}

/**
 * Customers of a salon matching an extra condition, always restricted to
 * marketing consent.
 */
function sqlCandidates(mysqli $conn, int $salonId, string $extraWhere, string $types = '', array $args = []): array
{
    $sql = 'SELECT ' . CUSTOMER_SELECT . "
            FROM coiffure_customers c
            WHERE c.salon_id = ? AND c.is_deleted = 0
              AND (c.consent_email_marketing = 1 OR c.consent_marketing = 1)
              $extraWhere
            LIMIT 500";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('cron-campaigns sqlCandidates prepare failed: ' . $conn->error);
        return [];
    }

    $stmt->bind_param('i' . $types, ...array_merge([$salonId], $args));
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/**
 * Record one campaign row for this run and deliver it, so an automatic send
 * appears in the campaign log beside the manual ones.
 */
function sendAutoCampaign(mysqli $conn, array $salon, array $auto, array $candidates): int
{
    $salonId = (int)$salon['salon_id'];
    $type = $auto['type'];

    // The birthday template lives in the salon settings; the others on the row.
    $subject = $type === 'birthday'
        ? ($salon['birthday_subject'] ?: $auto['subject'])
        : $auto['subject'];
    $body = $type === 'birthday'
        ? ($salon['birthday_body'] ?: $auto['body'])
        : $auto['body'];
    $discountCode = $type === 'birthday'
        ? ($salon['birthday_discount_code'] ?: $auto['discount_code'])
        : $auto['discount_code'];

    if (trim((string)$subject) === '' || trim(strip_tags((string)$body)) === '') {
        return 0;
    }

    $name = sprintf('%s – %s', autoTypeLabel($type), date('d.m.Y'));
    $ids = json_encode(array_map(static fn($c) => (int)$c['customer_id'], $candidates));
    $discountEnabled = $discountCode ? 1 : 0;

    $stmt = $conn->prepare(
        "INSERT INTO coiffure_campaigns
            (salon_id, name, kind, auto_type, status, subject, body,
             recipient_type, recipient_ref, discount_enabled, discount_mode, discount_code,
             discount_type, discount_value, skip_over_limit)
         VALUES (?, ?, 'auto', ?, 'draft', ?, ?, 'manual', ?, ?, 'generic', ?, ?, ?, 1)"
    );
    if (!$stmt) {
        error_log('cron-campaigns: campaign insert failed: ' . $conn->error);
        return 0;
    }

    $discountType = $auto['discount_type'] ?: 'fixed_eur';
    $discountValue = (float)($auto['discount_value'] ?: 0);

    // salon_id i, name s, auto_type s, subject s, body s, recipient_ref s,
    // discount_enabled i, discount_code s, discount_type s, discount_value d
    $stmt->bind_param(
        'isssssissd',
        $salonId, $name, $type, $subject, $body, $ids,
        $discountEnabled, $discountCode, $discountType, $discountValue
    );
    if (!$stmt->execute()) {
        error_log('cron-campaigns: campaign insert failed: ' . $stmt->error);
        $stmt->close();
        return 0;
    }
    $campaignId = $stmt->insert_id;
    $stmt->close();

    $campaignResult = $conn->query('SELECT * FROM coiffure_campaigns WHERE campaign_id = ' . (int)$campaignId);
    $campaign = $campaignResult ? $campaignResult->fetch_assoc() : null;
    if (!$campaign) {
        return 0;
    }

    $result = executeCampaign($conn, $campaign, $candidates);
    return $result['sent'];
}

function autoTypeLabel(string $type): string
{
    switch ($type) {
        case 'birthday':          return 'Geburtstagskampagne';
        case 'we_miss_you':       return 'Wir vermissen Dich';
        case 'thank_you':         return 'Dankeschön';
        case 'referral_reminder': return 'Freund werben';
        default:                  return $type;
    }
}
