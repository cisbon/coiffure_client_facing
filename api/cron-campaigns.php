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
 * The dashboard's "Jetzt ausführen" button does NOT call this endpoint -- that
 * would need the CRON_TOKEN in a browser. It calls campaigns.php?action=run_auto
 * behind a normal session, which runs runSalonAutomatics() from
 * campaign_engine.php: the same code, one salon, no shared secret.
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

    foreach (runSalonAutomatics($conn, $salon) as $type => $sent) {
        $totals[$type] = ($totals[$type] ?? 0) + $sent;
        echo sprintf("  %-18s (salon %d): %d sent\n", $type, $salonId, $sent);
    }
}

echo "\nDone in " . round(microtime(true) - $startedAt, 2) . "s: "
   . implode(', ', array_map(static fn($k, $v) => "$k $v", array_keys($totals), $totals)) . "\n";

$conn->close();
