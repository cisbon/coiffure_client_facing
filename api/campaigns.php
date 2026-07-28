<?php
/**
 * Marketing campaigns
 * -------------------------------------------------------------------
 *   GET  campaigns.php?action=list                     → campaigns + log
 *   GET  campaigns.php?action=get&campaign_id=N
 *   GET  campaigns.php?action=recipients               → audience count + spam warning
 *   GET  campaigns.php?action=auto                     → the four automatic types
 *   GET  campaigns.php?action=customers&q=…            → picker for "einzelne Kunden"
 *   POST campaigns.php?action=save        {…}          → create or update a draft
 *   POST campaigns.php?action=preview     {subject, body, discount?}
 *   POST campaigns.php?action=send        {campaign_id}
 *   POST campaigns.php?action=schedule    {campaign_id, scheduled_at}
 *   POST campaigns.php?action=cancel      {campaign_id}
 *   POST campaigns.php?action=save_auto   {type, …}
 *   POST campaigns.php?action=send_birthday {customer_id}
 *
 * Access: manage_campaigns, scoped to one salon. The heavy lifting (audience
 * resolution, spam limit, discount codes, delivery) lives in
 * api/campaign_engine.php so the cron runner behaves identically.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/campaign_engine.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

$user = requireAuth($conn);
requireDashboardAccess($user);

$salonId = resolveSalonScope($conn, $user, $_GET['salon_id'] ?? null);
requirePermission($conn, $user, 'manage_campaigns', $salonId);

if (!campaignTablesExist($conn)) {
    sendErrorResponse('Campaigns are unavailable until migration 020 has been applied.', 503);
}

$action = $_GET['action'] ?? 'list';
$input = json_decode(file_get_contents('php://input'), true) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    switch ($action) {
        case 'list':       handleList($conn, $salonId); break;
        case 'get':        handleGet($conn, $salonId); break;
        case 'recipients': handleRecipients($conn, $salonId); break;
        case 'auto':       handleAutoList($conn, $salonId); break;
        case 'customers':  handleCustomerPicker($conn, $salonId); break;
        default:           sendErrorResponse('Unknown action.', 400);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($action) {
        case 'save':           handleSave($conn, $salonId, $user, $input); break;
        case 'preview':        handlePreview($conn, $salonId, $input); break;
        case 'send':           handleSend($conn, $salonId, $user, $input); break;
        case 'schedule':       handleSchedule($conn, $salonId, $user, $input); break;
        case 'cancel':         handleCancel($conn, $salonId, $user, $input); break;
        case 'save_auto':      handleSaveAuto($conn, $salonId, $user, $input); break;
        case 'send_birthday':  handleSendBirthday($conn, $salonId, $user, $input); break;
        case 'run_auto':       handleRunAuto($conn, $salonId, $user); break;
        default:               sendErrorResponse('Unknown action.', 400);
    }
}

sendErrorResponse('Method not allowed.', 405);

/* ============================================================
   Reads
   ============================================================ */

function campaignTablesExist(mysqli $conn): bool
{
    $res = $conn->query("SHOW TABLES LIKE 'coiffure_campaigns'");
    return $res && $res->num_rows > 0;
}

function handleList(mysqli $conn, int $salonId): void
{
    $stmt = $conn->prepare(
        'SELECT campaign_id, name, kind, auto_type, status, subject,
                recipient_type, recipient_count, sent_count, skipped_count, failed_count,
                open_count, click_count, discount_enabled, discount_code,
                scheduled_at, started_at, completed_at, created_at
         FROM coiffure_campaigns
         WHERE salon_id = ?
         ORDER BY COALESCE(completed_at, scheduled_at, created_at) DESC
         LIMIT 200'
    );
    if (!$stmt) {
        sendErrorResponse('Failed to load campaigns.', 500);
    }

    $stmt->bind_param('i', $salonId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $campaigns = array_map(static function ($row) {
        $sent = (int)$row['sent_count'];
        return [
            'campaign_id'     => (int)$row['campaign_id'],
            'name'            => $row['name'],
            'kind'            => $row['kind'],
            'auto_type'       => $row['auto_type'],
            'status'          => $row['status'],
            'subject'         => $row['subject'],
            'recipient_type'  => $row['recipient_type'],
            'recipient_count' => (int)$row['recipient_count'],
            'sent_count'      => $sent,
            'skipped_count'   => (int)$row['skipped_count'],
            'failed_count'    => (int)$row['failed_count'],
            'open_count'      => (int)$row['open_count'],
            'click_count'     => (int)$row['click_count'],
            // Rates are only meaningful once something was sent.
            'open_rate'       => $sent > 0 ? round(((int)$row['open_count'] / $sent) * 100, 1) : null,
            'click_rate'      => $sent > 0 ? round(((int)$row['click_count'] / $sent) * 100, 1) : null,
            'discount_enabled' => (int)$row['discount_enabled'] === 1,
            'discount_code'   => $row['discount_code'],
            'scheduled_at'    => $row['scheduled_at'],
            'completed_at'    => $row['completed_at'],
            'created_at'      => $row['created_at'],
        ];
    }, $rows);

    sendJsonResponse(['success' => true, 'campaigns' => $campaigns], 200);
}

function handleGet(mysqli $conn, int $salonId): void
{
    $campaign = loadCampaign($conn, (int)($_GET['campaign_id'] ?? 0), $salonId);
    sendJsonResponse(['success' => true, 'campaign' => $campaign], 200);
}

/**
 * Audience preview for step 1 and the review step: how many people would
 * receive this, and how many are over the salon's spam limit.
 */
function handleRecipients(mysqli $conn, int $salonId): void
{
    $type = $_GET['recipient_type'] ?? 'all';
    $ref = $_GET['recipient_ref'] ?? null;

    $salon = loadSalonForCampaign($conn, $salonId);
    $customers = resolveCampaignRecipients($conn, $salonId, $type, $ref);
    $split = applySpamLimit($conn, $salon, $customers);

    // How many were excluded purely for lack of consent, so the UI can explain
    // the difference between "customers" and "reachable customers".
    $totalCustomers = countCustomers($conn, [$salonId], []);

    sendJsonResponse([
        'success' => true,
        'total_customers'  => $totalCustomers,
        'reachable'        => count($customers),
        'within_limit'     => count($split['within']),
        'over_limit'       => count($split['over']),
        'spam_limit'       => $split['limit'],
        'spam_window_days' => $split['window'],
        'over_limit_names' => array_map(
            static fn($c) => ['name' => $c['full_name'], 'count' => $c['recent_mail_count'] ?? 0],
            array_slice($split['over'], 0, 10)
        ),
    ], 200);
}

/** Searchable customer list for the "einzelne Kunden auswählen" modal. */
function handleCustomerPicker(mysqli $conn, int $salonId): void
{
    $filter = ['consent_email' => true];
    if (!empty($_GET['q'])) {
        $filter['search'] = trim((string)$_GET['q']);
    }

    $result = buildCustomerQuery($conn, [$salonId], $filter, 'name', 'asc', 100);

    sendJsonResponse([
        'success' => true,
        'customers' => array_map(static fn($row) => [
            'customer_id' => (int)$row['customer_id'],
            'full_name'   => $row['full_name'],
            'email'       => $row['email'],
            'visit_count' => (int)$row['visit_count'],
        ], $result['rows']),
        'total' => $result['total'],
    ], 200);
}

function handleAutoList(mysqli $conn, int $salonId): void
{
    ensureAutoCampaigns($conn, $salonId);

    $stmt = $conn->prepare(
        'SELECT auto_id, type, enabled, trigger_value, trigger_unit, subject, body,
                discount_enabled, discount_code, discount_type, discount_value, last_run_at
         FROM coiffure_automatic_campaigns WHERE salon_id = ? ORDER BY type'
    );
    if (!$stmt) {
        sendErrorResponse('Failed to load automatic campaigns.', 500);
    }
    $stmt->bind_param('i', $salonId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $salon = loadSalonForCampaign($conn, $salonId);

    sendJsonResponse([
        'success' => true,
        'campaigns' => array_map(static fn($row) => [
            'auto_id'       => (int)$row['auto_id'],
            'type'          => $row['type'],
            'enabled'       => (int)$row['enabled'] === 1,
            'trigger_value' => (int)$row['trigger_value'],
            'trigger_unit'  => $row['trigger_unit'],
            'subject'       => $row['subject'],
            'body'          => $row['body'],
            'discount_enabled' => (int)$row['discount_enabled'] === 1,
            'discount_code' => $row['discount_code'],
            'discount_type' => $row['discount_type'],
            'discount_value' => $row['discount_value'] !== null ? (float)$row['discount_value'] : null,
            'last_run_at'   => $row['last_run_at'],
        ], $rows),
        // The birthday campaign's timing lives in the salon settings; the
        // campaigns screen shows it as a shortcut (spec 3.6).
        'birthday_settings' => [
            'enabled'      => (int)($salon['birthday_enabled'] ?? 0) === 1,
            'days_before'  => (int)($salon['birthday_days_before'] ?? 7),
            'subject'      => $salon['birthday_subject'] ?? null,
            'body'         => $salon['birthday_body'] ?? null,
            'discount_code' => $salon['birthday_discount_code'] ?? null,
        ],
    ], 200);
}

/* ============================================================
   Writes
   ============================================================ */

function loadCampaign(mysqli $conn, int $campaignId, int $salonId): array
{
    if ($campaignId <= 0) {
        sendErrorResponse('campaign_id is required.', 400);
    }

    $stmt = $conn->prepare('SELECT * FROM coiffure_campaigns WHERE campaign_id = ? AND salon_id = ?');
    if (!$stmt) {
        sendErrorResponse('Failed to load the campaign.', 500);
    }
    $stmt->bind_param('ii', $campaignId, $salonId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        sendErrorResponse('Campaign not found.', 404);
    }
    return $row;
}

function handleSave(mysqli $conn, int $salonId, array $user, array $input): void
{
    $errors = [];

    $name = trim((string)($input['name'] ?? ''));
    $subject = trim((string)($input['subject'] ?? ''));
    $body = (string)($input['body'] ?? '');

    if ($name === '') $errors['name'] = 'Ein Name ist erforderlich.';
    if ($subject === '') $errors['subject'] = 'Ein Betreff ist erforderlich.';
    if (trim(strip_tags($body)) === '') $errors['body'] = 'Der Inhalt darf nicht leer sein.';

    $recipientType = $input['recipient_type'] ?? 'all';
    if (!in_array($recipientType, ['all', 'members', 'segment', 'manual'], true)) {
        $errors['recipient_type'] = 'Unbekannte Empfängerauswahl.';
    }

    $recipientRef = null;
    if ($recipientType === 'segment') {
        $recipientRef = (string)(int)($input['recipient_ref'] ?? 0);
        if ((int)$recipientRef <= 0) $errors['recipient_ref'] = 'Bitte ein Segment wählen.';
    } elseif ($recipientType === 'manual') {
        $ids = array_values(array_filter(array_map('intval', (array)($input['recipient_ref'] ?? []))));
        if (empty($ids)) $errors['recipient_ref'] = 'Bitte mindestens eine Kundin oder einen Kunden wählen.';
        $recipientRef = json_encode($ids);
    }

    $discountEnabled = !empty($input['discount_enabled']) ? 1 : 0;
    $discountMode = $input['discount_mode'] ?? 'generic';
    $discountCode = trim((string)($input['discount_code'] ?? ''));
    $discountType = $input['discount_type'] ?? 'fixed_eur';
    $discountValue = (float)($input['discount_value'] ?? 0);

    if ($discountEnabled) {
        if (!in_array($discountMode, ['generic', 'unique'], true)) {
            $errors['discount_mode'] = 'Unbekannter Rabatt-Modus.';
        }
        if ($discountMode === 'generic' && $discountCode === '') {
            $errors['discount_code'] = 'Bitte einen Rabattcode angeben.';
        }
        if ($discountValue <= 0) {
            $errors['discount_value'] = 'Bitte einen Wert größer als 0 angeben.';
        }
    }

    if ($errors) {
        sendErrorResponse('Bitte prüfen Sie Ihre Eingaben.', 422, ['fields' => $errors]);
    }

    $skipOverLimit = array_key_exists('skip_over_limit', $input)
        ? (!empty($input['skip_over_limit']) ? 1 : 0)
        : 1;

    $campaignId = (int)($input['campaign_id'] ?? 0);

    if ($campaignId > 0) {
        $existing = loadCampaign($conn, $campaignId, $salonId);
        // A campaign that already went out is a record, not a draft.
        if (!in_array($existing['status'], ['draft', 'scheduled'], true)) {
            sendErrorResponse('Eine bereits gesendete Kampagne kann nicht mehr bearbeitet werden.', 409);
        }

        $stmt = $conn->prepare(
            'UPDATE coiffure_campaigns
             SET name = ?, subject = ?, body = ?, recipient_type = ?, recipient_ref = ?,
                 discount_enabled = ?, discount_mode = ?, discount_code = ?,
                 discount_type = ?, discount_value = ?, skip_over_limit = ?
             WHERE campaign_id = ? AND salon_id = ?'
        );
        bindTyped($stmt, [
            $name, $subject, $body, $recipientType, $recipientRef,
            (int)$discountEnabled, $discountMode, $discountCode,
            $discountType, (float)$discountValue, (int)$skipOverLimit,
            $campaignId, $salonId,
        ]);
        $stmt->execute();
        $stmt->close();

        logAdminAudit($conn, $user, 'campaign', $campaignId, 'update', "Campaign updated: $name", $salonId);
    } else {
        $createdBy = (int)$user['user_id'];
        $stmt = $conn->prepare(
            "INSERT INTO coiffure_campaigns
                (salon_id, name, kind, status, subject, body, recipient_type, recipient_ref,
                 discount_enabled, discount_mode, discount_code, discount_type, discount_value,
                 skip_over_limit, created_by)
             VALUES (?, ?, 'once', 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        bindTyped($stmt, [
            $salonId, $name, $subject, $body, $recipientType, $recipientRef,
            (int)$discountEnabled, $discountMode, $discountCode,
            $discountType, (float)$discountValue, (int)$skipOverLimit, $createdBy,
        ]);
        if (!$stmt->execute()) {
            $stmt->close();
            sendErrorResponse('Die Kampagne konnte nicht gespeichert werden.', 500);
        }
        $campaignId = $stmt->insert_id;
        $stmt->close();

        logAdminAudit($conn, $user, 'campaign', $campaignId, 'create', "Campaign created: $name", $salonId);
    }

    sendJsonResponse(['success' => true, 'campaign_id' => $campaignId], 200);
}

/**
 * Render one example mail exactly as a customer would receive it, using the
 * first reachable customer as the sample so the placeholders are filled in.
 */
function handlePreview(mysqli $conn, int $salonId, array $input): void
{
    $salon = loadSalonForCampaign($conn, $salonId);

    $sample = buildCustomerQuery($conn, [$salonId], ['consent_email' => true], 'name', 'asc', 1)['rows'][0] ?? [
        'first_name' => 'Anna',
        'last_name'  => 'Muster',
        'full_name'  => 'Anna Muster',
        'email'      => 'anna@example.test',
    ];

    $discountCode = null;
    if (!empty($input['discount_enabled'])) {
        $discountCode = ($input['discount_mode'] ?? 'generic') === 'unique'
            ? 'BEISPIEL-A1B2C3'
            : (string)($input['discount_code'] ?? '');
    }

    $tokens = [
        'vorname'    => $sample['first_name'] ?: $sample['full_name'],
        'nachname'   => $sample['last_name'] ?? '',
        'name'       => $sample['full_name'] ?? '',
        'salonname'  => $salon['salon_name'] ?? '',
        'rabattcode' => $discountCode ?? '',
    ];

    sendJsonResponse([
        'success' => true,
        'subject' => renderTemplate((string)($input['subject'] ?? ''), $tokens),
        'html'    => buildCampaignEmailHtml(
            $salon,
            renderTemplate((string)($input['body'] ?? ''), $tokens),
            $discountCode
        ),
        'sample_customer' => $sample['full_name'] ?? null,
    ], 200);
}

function handleSend(mysqli $conn, int $salonId, array $user, array $input): void
{
    $campaign = loadCampaign($conn, (int)($input['campaign_id'] ?? 0), $salonId);

    if (!in_array($campaign['status'], ['draft', 'scheduled'], true)) {
        sendErrorResponse('Diese Kampagne wurde bereits gesendet.', 409);
    }

    $result = executeCampaign($conn, $campaign);

    logAdminAudit(
        $conn,
        $user,
        'campaign',
        (int)$campaign['campaign_id'],
        'campaign_sent',
        sprintf('Sent: %d, skipped: %d, failed: %d', $result['sent'], $result['skipped'], $result['failed']),
        $salonId
    );

    sendJsonResponse(['success' => true] + $result, 200);
}

function handleSchedule(mysqli $conn, int $salonId, array $user, array $input): void
{
    $campaign = loadCampaign($conn, (int)($input['campaign_id'] ?? 0), $salonId);

    $when = trim((string)($input['scheduled_at'] ?? ''));
    $timestamp = strtotime($when);
    if (!$timestamp) {
        sendErrorResponse('Bitte einen gültigen Zeitpunkt angeben.', 422, ['fields' => ['scheduled_at' => 'Ungültiges Datum.']]);
    }
    if ($timestamp < time()) {
        sendErrorResponse('Der Zeitpunkt muss in der Zukunft liegen.', 422, ['fields' => ['scheduled_at' => 'Muss in der Zukunft liegen.']]);
    }

    $scheduledAt = date('Y-m-d H:i:s', $timestamp);
    markCampaignStatus($conn, (int)$campaign['campaign_id'], 'scheduled', ['scheduled_at' => $scheduledAt]);

    logAdminAudit(
        $conn, $user, 'campaign', (int)$campaign['campaign_id'],
        'update', "Scheduled for $scheduledAt", $salonId
    );

    sendJsonResponse(['success' => true, 'scheduled_at' => $scheduledAt], 200);
}

function handleCancel(mysqli $conn, int $salonId, array $user, array $input): void
{
    $campaign = loadCampaign($conn, (int)($input['campaign_id'] ?? 0), $salonId);

    if (!in_array($campaign['status'], ['draft', 'scheduled'], true)) {
        sendErrorResponse('Nur Entwürfe und geplante Kampagnen können abgebrochen werden.', 409);
    }

    markCampaignStatus($conn, (int)$campaign['campaign_id'], 'cancelled');
    logAdminAudit($conn, $user, 'campaign', (int)$campaign['campaign_id'], 'update', 'Cancelled', $salonId);

    sendJsonResponse(['success' => true], 200);
}

function handleSaveAuto(mysqli $conn, int $salonId, array $user, array $input): void
{
    $type = (string)($input['type'] ?? '');
    if (!in_array($type, AUTO_TYPES, true)) {
        sendErrorResponse('Unbekannter Kampagnentyp.', 400);
    }

    ensureAutoCampaigns($conn, $salonId);

    $enabled = !empty($input['enabled']) ? 1 : 0;
    $triggerValue = max(0, (int)($input['trigger_value'] ?? 0));
    $triggerUnit = in_array($input['trigger_unit'] ?? '', ['weeks', 'visits', 'days'], true)
        ? $input['trigger_unit']
        : defaultAutoTemplate($type)['trigger_unit'];

    $subject = trim((string)($input['subject'] ?? ''));
    $body = (string)($input['body'] ?? '');

    if ($enabled) {
        $errors = [];
        if ($subject === '') $errors['subject'] = 'Ein Betreff ist erforderlich.';
        if (trim(strip_tags($body)) === '') $errors['body'] = 'Der Inhalt darf nicht leer sein.';
        if ($type !== 'birthday' && $triggerValue <= 0) {
            $errors['trigger_value'] = 'Bitte einen Wert größer als 0 angeben.';
        }
        if ($errors) {
            sendErrorResponse('Bitte prüfen Sie Ihre Eingaben.', 422, ['fields' => $errors]);
        }
    }

    $discountEnabled = !empty($input['discount_enabled']) ? 1 : 0;
    $discountCode = trim((string)($input['discount_code'] ?? ''));
    $discountType = $input['discount_type'] ?? 'fixed_eur';
    $discountValue = (float)($input['discount_value'] ?? 0);
    $updatedBy = (int)$user['user_id'];

    $stmt = $conn->prepare(
        'UPDATE coiffure_automatic_campaigns
         SET enabled = ?, trigger_value = ?, trigger_unit = ?, subject = ?, body = ?,
             discount_enabled = ?, discount_code = ?, discount_type = ?, discount_value = ?,
             updated_by = ?
         WHERE salon_id = ? AND type = ?'
    );
    if (!$stmt) {
        sendErrorResponse('Speichern fehlgeschlagen.', 500);
    }
    $stmt->bind_param(
        'iisssissdiis',
        $enabled, $triggerValue, $triggerUnit, $subject, $body,
        $discountEnabled, $discountCode, $discountType, $discountValue,
        $updatedBy, $salonId, $type
    );
    $stmt->execute();
    $stmt->close();

    logAdminAudit(
        $conn, $user, 'campaign', 0, 'update',
        "Automatic campaign '$type' " . ($enabled ? 'enabled' : 'disabled'),
        $salonId
    );

    sendJsonResponse(['success' => true], 200);
}

/**
 * Send a single birthday mail on demand, from the Übersicht birthday card.
 * Recorded as a one-off campaign of kind 'auto' so it shows in the log and
 * counts towards the spam limit like any other mail.
 */
/**
 * Run this salon's automatic campaigns now.
 *
 * Deliberately not a call to cron-campaigns.php: that endpoint is guarded by
 * CRON_TOKEN, and a shared secret has no business in a browser. This runs the
 * same runSalonAutomatics() from campaign_engine.php behind an ordinary session
 * and scoped to one salon, so a manual run and the hourly cron cannot drift.
 *
 * Safe to press repeatedly: every automatic type checks
 * coiffure_campaign_recipients before sending, so nobody is mailed twice.
 */
function handleRunAuto(mysqli $conn, int $salonId, array $user): void
{
    $salon = loadSalonForCampaign($conn, $salonId);
    if (!$salon) {
        sendErrorResponse('Salon not found.', 404);
    }

    $sentByType = runSalonAutomatics($conn, $salon);
    $total = array_sum($sentByType);

    logAdminAudit(
        $conn, $user, 'campaign', $salonId, 'campaign_sent',
        "Automatic campaigns run manually: $total sent", $salonId
    );

    sendJsonResponse([
        'success' => true,
        'sent' => $total,
        'by_type' => $sentByType,
    ], 200);
}

function handleSendBirthday(mysqli $conn, int $salonId, array $user, array $input): void
{
    $customerId = (int)($input['customer_id'] ?? 0);
    if ($customerId <= 0) {
        sendErrorResponse('customer_id is required.', 400);
    }

    $customers = fetchCustomersByIds($conn, $salonId, [$customerId], true);
    if (empty($customers)) {
        sendErrorResponse('Diese Person hat dem Erhalt von Werbe-E-Mails nicht zugestimmt.', 403);
    }

    $salon = loadSalonForCampaign($conn, $salonId);

    // Prefer the salon's configured birthday template, else the built-in one.
    $template = defaultAutoTemplate('birthday');
    $subject = $salon['birthday_subject'] ?: $template['subject'];
    $body = $salon['birthday_body'] ?: $template['body'];
    $discountCode = $salon['birthday_discount_code'] ?: null;

    $name = 'Geburtstag: ' . $customers[0]['full_name'];
    $createdBy = (int)$user['user_id'];
    $discountEnabled = $discountCode ? 1 : 0;

    $stmt = $conn->prepare(
        "INSERT INTO coiffure_campaigns
            (salon_id, name, kind, auto_type, status, subject, body,
             recipient_type, recipient_ref, discount_enabled, discount_mode, discount_code,
             skip_over_limit, created_by)
         VALUES (?, ?, 'auto', 'birthday', 'draft', ?, ?, 'manual', ?, ?, 'generic', ?, 0, ?)"
    );
    if (!$stmt) {
        sendErrorResponse('Senden fehlgeschlagen.', 500);
    }

    $ref = json_encode([$customerId]);
    $stmt->bind_param('issssisi', $salonId, $name, $subject, $body, $ref, $discountEnabled, $discountCode, $createdBy);
    $stmt->execute();
    $campaignId = $stmt->insert_id;
    $stmt->close();

    $campaign = loadCampaign($conn, $campaignId, $salonId);
    $result = executeCampaign($conn, $campaign, $customers);

    logAdminAudit(
        $conn, $user, 'campaign', $campaignId, 'campaign_sent',
        'Birthday mail to ' . $customers[0]['full_name'], $salonId
    );

    if ($result['sent'] === 0) {
        sendErrorResponse('Die E-Mail konnte nicht versendet werden.', 500);
    }

    sendJsonResponse(['success' => true] + $result, 200);
}
