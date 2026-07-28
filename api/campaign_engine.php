<?php
/**
 * Campaign engine
 * -------------------------------------------------------------------
 * Resolving recipients, enforcing the spam limit, generating discount codes and
 * actually sending. Shared by:
 *   campaigns.php        the dashboard (preview, count, send now)
 *   cron-campaigns.php   scheduled sends and the four automatic campaign types
 *
 * Both paths must behave identically -- a campaign previewed in the UI has to
 * reach the same people when the cron sends it -- so the logic lives here and
 * neither endpoint reimplements it.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/customer_filters.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/notify.php';

/** The four automatic campaign types from spec 3.6. */
const AUTO_TYPES = ['birthday', 'we_miss_you', 'thank_you', 'referral_reminder'];

/** Recipient statuses recorded per customer. */
const RECIPIENT_SENT = 'sent';
const RECIPIENT_SKIPPED_LIMIT = 'skipped_limit';
const RECIPIENT_SKIPPED_CONSENT = 'skipped_no_consent';
const RECIPIENT_FAILED = 'failed';

/**
 * Load a salon row with everything the mailer and the limits need.
 */
function loadSalonForCampaign(mysqli $conn, int $salonId): ?array
{
    $result = $conn->query('SELECT * FROM coiffure_salons WHERE salon_id = ' . (int)$salonId);
    return $result ? ($result->fetch_assoc() ?: null) : null;
}

/**
 * Resolve a campaign's audience.
 *
 * recipient_type:
 *   all      every customer of the salon who may receive marketing e-mail
 *   members  the same, restricted to members
 *   segment  recipient_ref is a segment_id
 *   manual   recipient_ref is a JSON array of customer ids
 *
 * Marketing consent is applied in every case: a campaign may never reach
 * somebody who did not opt in, whatever the UI asked for.
 *
 * @return array list of customer rows
 */
function resolveCampaignRecipients(mysqli $conn, int $salonId, string $type, $ref): array
{
    $filter = ['consent_email' => true];

    switch ($type) {
        case 'members':
            $filter['members_only'] = true;
            break;

        case 'segment':
            $segmentId = (int)$ref;
            $stmt = $conn->prepare(
                'SELECT filter_json FROM coiffure_segments WHERE segment_id = ? AND salon_id = ?'
            );
            if ($stmt) {
                $stmt->bind_param('ii', $segmentId, $salonId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    // The segment's own rules, plus the non-negotiable consent one.
                    $filter = array_merge(normaliseCustomerFilter($row['filter_json']), $filter);
                }
            }
            break;

        case 'manual':
            $ids = is_array($ref) ? $ref : json_decode((string)$ref, true);
            $ids = array_values(array_filter(array_map('intval', (array)$ids)));
            if (empty($ids)) {
                return [];
            }
            return fetchCustomersByIds($conn, $salonId, $ids, true);

        case 'all':
        default:
            break;
    }

    $result = buildCustomerQuery($conn, [$salonId], $filter, 'name', 'asc');
    return $result['rows'];
}

/** Fetch specific customers, optionally enforcing marketing consent. */
function fetchCustomersByIds(mysqli $conn, int $salonId, array $ids, bool $requireConsent): array
{
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $consentClause = $requireConsent
        ? ' AND (c.consent_email_marketing = 1 OR c.consent_marketing = 1)'
        : '';

    $sql = 'SELECT ' . CUSTOMER_SELECT . "
            FROM coiffure_customers c
            WHERE c.salon_id = ? AND c.is_deleted = 0
              AND c.customer_id IN ($placeholders)"
            . $consentClause;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('fetchCustomersByIds: prepare failed: ' . $conn->error);
        return [];
    }

    $stmt->bind_param('i' . str_repeat('i', count($ids)), ...array_merge([$salonId], $ids));
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/**
 * How many campaign mails each of these customers received inside the salon's
 * spam window. Used to warn before sending and to skip while sending.
 *
 * @return array<int,int> customer_id => count
 */
function recentMailCounts(mysqli $conn, array $customerIds, int $windowDays): array
{
    if (empty($customerIds)) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($customerIds), '?'));
    $stmt = $conn->prepare(
        "SELECT customer_id, COUNT(*) AS sent_count
         FROM coiffure_campaign_recipients
         WHERE customer_id IN ($placeholders)
           AND status = 'sent'
           AND sent_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
         GROUP BY customer_id"
    );
    if (!$stmt) {
        error_log('recentMailCounts: prepare failed: ' . $conn->error);
        return [];
    }

    $args = array_merge(array_map('intval', $customerIds), [$windowDays]);
    $stmt->bind_param(str_repeat('i', count($customerIds)) . 'i', ...$args);
    $stmt->execute();
    $result = $stmt->get_result();

    $counts = [];
    while ($row = $result->fetch_assoc()) {
        $counts[(int)$row['customer_id']] = (int)$row['sent_count'];
    }
    $stmt->close();

    return $counts;
}

/**
 * Split an audience into those under the spam limit and those over it.
 *
 * @return array{within:array, over:array, limit:int, window:int}
 */
function applySpamLimit(mysqli $conn, array $salon, array $customers): array
{
    $limit = (int)($salon['campaign_spam_limit'] ?? 4);
    $window = (int)($salon['campaign_spam_window_days'] ?? 30);

    // A limit of 0 disables the guard entirely.
    if ($limit <= 0) {
        return ['within' => $customers, 'over' => [], 'limit' => 0, 'window' => $window];
    }

    $ids = array_map(static fn($c) => (int)$c['customer_id'], $customers);
    $counts = recentMailCounts($conn, $ids, $window);

    $within = [];
    $over = [];
    foreach ($customers as $customer) {
        $sent = $counts[(int)$customer['customer_id']] ?? 0;
        if ($sent >= $limit) {
            $customer['recent_mail_count'] = $sent;
            $over[] = $customer;
        } else {
            $within[] = $customer;
        }
    }

    return ['within' => $within, 'over' => $over, 'limit' => $limit, 'window' => $window];
}

/**
 * Generate a discount code that does not collide inside the salon.
 * Ambiguous characters (0/O, 1/I) are left out so codes can be read aloud.
 */
function generateDiscountCode(mysqli $conn, int $salonId, string $prefix = ''): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $prefix));

    for ($attempt = 0; $attempt < 12; $attempt++) {
        $random = '';
        for ($i = 0; $i < 6; $i++) {
            $random .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $code = ($prefix !== '' ? substr($prefix, 0, 10) . '-' : '') . $random;

        $stmt = $conn->prepare(
            'SELECT 1 FROM coiffure_discount_codes WHERE salon_id = ? AND code = ? LIMIT 1'
        );
        if (!$stmt) {
            return $code;
        }
        $stmt->bind_param('is', $salonId, $code);
        $stmt->execute();
        $taken = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$taken) {
            return $code;
        }
    }

    // Extremely unlikely; fall back to something guaranteed unique.
    return strtoupper(bin2hex(random_bytes(5)));
}

function storeDiscountCode(
    mysqli $conn,
    int $salonId,
    string $code,
    ?int $campaignId,
    ?int $customerId,
    string $type,
    float $value
): void {
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO coiffure_discount_codes
            (salon_id, code, campaign_id, customer_id, discount_type, discount_value)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('isiisd', $salonId, $code, $campaignId, $customerId, $type, $value);
    $stmt->execute();
    $stmt->close();
}

/**
 * Send a campaign to its audience and record every recipient.
 *
 * This is the only place mail actually goes out. It writes one
 * coiffure_campaign_recipients row per customer -- including the skipped ones,
 * so the campaign log explains why somebody did not receive it.
 *
 * @param array $campaign a coiffure_campaigns row
 * @return array{sent:int, skipped:int, failed:int}
 */
function executeCampaign(mysqli $conn, array $campaign, ?array $audience = null): array
{
    $salonId = (int)$campaign['salon_id'];
    $campaignId = (int)$campaign['campaign_id'];

    $salon = loadSalonForCampaign($conn, $salonId);
    if (!$salon) {
        return ['sent' => 0, 'skipped' => 0, 'failed' => 0];
    }

    $customers = $audience ?? resolveCampaignRecipients(
        $conn,
        $salonId,
        $campaign['recipient_type'],
        $campaign['recipient_ref']
    );

    $split = applySpamLimit($conn, $salon, $customers);

    // skip_over_limit is the choice made in the review step: skip the
    // over-limit customers, or send to everyone anyway.
    $recipients = (int)$campaign['skip_over_limit'] === 1
        ? $split['within']
        : array_merge($split['within'], $split['over']);

    $skipped = (int)$campaign['skip_over_limit'] === 1 ? count($split['over']) : 0;

    markCampaignStatus($conn, $campaignId, 'sending', ['started_at' => date('Y-m-d H:i:s')]);

    // Record the skipped ones so the log can explain the gap.
    if ($skipped > 0) {
        foreach ($split['over'] as $customer) {
            recordRecipient($conn, $campaignId, $salonId, $customer, RECIPIENT_SKIPPED_LIMIT, null, null);
        }
    }

    $sent = 0;
    $failed = 0;

    foreach ($recipients as $customer) {
        $discountCode = null;

        if ((int)$campaign['discount_enabled'] === 1) {
            if ($campaign['discount_mode'] === 'unique') {
                $discountCode = generateDiscountCode($conn, $salonId, (string)$campaign['discount_code']);
                storeDiscountCode(
                    $conn,
                    $salonId,
                    $discountCode,
                    $campaignId,
                    (int)$customer['customer_id'],
                    (string)($campaign['discount_type'] ?: 'fixed_eur'),
                    (float)($campaign['discount_value'] ?: 0)
                );
            } else {
                $discountCode = (string)$campaign['discount_code'];
            }
        }

        $ok = sendCampaignEmail($conn, $salon, $customer, $campaign, $discountCode);

        if ($ok) {
            $sent++;
            recordRecipient($conn, $campaignId, $salonId, $customer, RECIPIENT_SENT, $discountCode, null);
        } else {
            $failed++;
            recordRecipient(
                $conn,
                $campaignId,
                $salonId,
                $customer,
                RECIPIENT_FAILED,
                $discountCode,
                'Mail transport reported a failure'
            );
        }
    }

    markCampaignStatus($conn, $campaignId, $failed > 0 && $sent === 0 ? 'failed' : 'sent', [
        'completed_at'    => date('Y-m-d H:i:s'),
        'sent_count'      => $sent,
        'skipped_count'   => $skipped,
        'failed_count'    => $failed,
        'recipient_count' => count($recipients) + $skipped,
    ]);

    // A campaign often runs from cron, hours after it was scheduled, so the
    // result has to find its way back to the people who scheduled it.
    notifySalonAdmins(
        $conn,
        $salonId,
        'campaign_sent',
        'admin.notify.campaign_sent',
        ['name' => (string)($campaign['name'] ?? ''), 'count' => $sent],
        '#/kampagnen?tab=log',
        'manage_campaigns'
    );

    return ['sent' => $sent, 'skipped' => $skipped, 'failed' => $failed];
}

/** One delivery record per customer, whatever the outcome. */
function recordRecipient(
    mysqli $conn,
    int $campaignId,
    int $salonId,
    array $customer,
    string $status,
    ?string $discountCode,
    ?string $error
): void {
    $stmt = $conn->prepare(
        'INSERT INTO coiffure_campaign_recipients
            (campaign_id, customer_id, salon_id, email, status, discount_code, error_message, tracking_token, sent_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), sent_at = VALUES(sent_at),
                                 discount_code = VALUES(discount_code), error_message = VALUES(error_message)'
    );
    if (!$stmt) {
        error_log('recordRecipient: prepare failed: ' . $conn->error);
        return;
    }

    $customerId = (int)$customer['customer_id'];
    $email = (string)$customer['email'];
    $token = bin2hex(random_bytes(16));
    $sentAt = $status === RECIPIENT_SENT ? date('Y-m-d H:i:s') : null;

    // campaign_id i, customer_id i, salon_id i, then six string columns.
    $stmt->bind_param(
        'iiissssss',
        $campaignId, $customerId, $salonId, $email, $status, $discountCode, $error, $token, $sentAt
    );
    $stmt->execute();
    $stmt->close();
}

/** Update a campaign's status plus any counter columns. */
function markCampaignStatus(mysqli $conn, int $campaignId, string $status, array $fields = []): void
{
    $sets = ['status = ?'];
    $types = 's';
    $args = [$status];

    foreach ($fields as $column => $value) {
        $sets[] = "`$column` = ?";
        $types .= is_int($value) ? 'i' : 's';
        $args[] = $value;
    }

    $sql = 'UPDATE coiffure_campaigns SET ' . implode(', ', $sets) . ' WHERE campaign_id = ?';
    $types .= 'i';
    $args[] = $campaignId;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('markCampaignStatus: prepare failed: ' . $conn->error);
        return;
    }
    $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $stmt->close();
}

/** Default subject/body for each automatic campaign type, in German. */
function defaultAutoTemplate(string $type): array
{
    switch ($type) {
        case 'birthday':
            return [
                'subject' => 'Alles Gute zum Geburtstag, {vorname}!',
                'body' => "<p>Liebe/r {vorname},</p>"
                        . "<p>wir wünschen dir alles Gute zum Geburtstag! Als kleines Geschenk "
                        . "erwartet dich bei deinem nächsten Besuch eine Überraschung.</p>"
                        . "<p>Dein Team von {salonname}</p>",
                'trigger_value' => 0,
                'trigger_unit' => 'days',
            ];

        case 'we_miss_you':
            return [
                'subject' => 'Wir vermissen Dich, {vorname}!',
                'body' => "<p>Hallo {vorname},</p>"
                        . "<p>wir haben dich eine Weile nicht gesehen und würden uns freuen, "
                        . "dich bald wieder bei uns begrüßen zu dürfen.</p>"
                        . "<p>Bis bald, dein Team von {salonname}</p>",
                'trigger_value' => 10,
                'trigger_unit' => 'weeks',
            ];

        case 'thank_you':
            return [
                'subject' => 'Danke für deine Treue, {vorname}!',
                'body' => "<p>Hallo {vorname},</p>"
                        . "<p>du warst nun schon mehrfach bei uns – dafür möchten wir uns herzlich "
                        . "bedanken.</p>"
                        . "<p>Dein Team von {salonname}</p>",
                'trigger_value' => 5,
                'trigger_unit' => 'visits',
            ];

        case 'referral_reminder':
        default:
            return [
                'subject' => 'Freunde werben und gemeinsam sparen',
                'body' => "<p>Hallo {vorname},</p>"
                        . "<p>wusstest du, dass du und deine Freundin oder dein Freund beide "
                        . "profitieren, wenn ihr uns weiterempfehlt?</p>"
                        . "<p>Dein Team von {salonname}</p>",
                'trigger_value' => 30,
                'trigger_unit' => 'days',
            ];
    }
}

/** Ensure a salon has a row for each automatic campaign type. */
function ensureAutoCampaigns(mysqli $conn, int $salonId): void
{
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO coiffure_automatic_campaigns
            (salon_id, type, enabled, trigger_value, trigger_unit, subject, body)
         VALUES (?, ?, 0, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return;
    }

    foreach (AUTO_TYPES as $type) {
        $template = defaultAutoTemplate($type);
        // salon_id i, type s, trigger_value i, then three strings.
        $stmt->bind_param(
            'isisss',
            $salonId,
            $type,
            $template['trigger_value'],
            $template['trigger_unit'],
            $template['subject'],
            $template['body']
        );
        $stmt->execute();
    }

    $stmt->close();
}

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

/**
 * Run every enabled automatic campaign for one salon.
 *
 * Shared by the cron runner and by the dashboard's "Jetzt ausführen" button, so
 * a manual run and a scheduled one cannot drift apart.
 *
 * @return array type => number sent
 */
function runSalonAutomatics(mysqli $conn, array $salon): array
{
    $salonId = (int)$salon['salon_id'];
    $sentByType = [];

    $stmt = $conn->prepare('SELECT * FROM coiffure_automatic_campaigns WHERE salon_id = ? AND enabled = 1');
    if (!$stmt) {
        return $sentByType;
    }
    $stmt->bind_param('i', $salonId);
    $stmt->execute();
    $autos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($autos as $auto) {
        $candidates = findAutoCandidates($conn, $salon, $auto);
        if (empty($candidates)) {
            continue;
        }

        $sentByType[$auto['type']] = ($sentByType[$auto['type']] ?? 0)
            + sendAutoCampaign($conn, $salon, $auto, $candidates);

        $update = $conn->prepare('UPDATE coiffure_automatic_campaigns SET last_run_at = NOW() WHERE auto_id = ?');
        if ($update) {
            $update->bind_param('i', $auto['auto_id']);
            $update->execute();
            $update->close();
        }
    }

    return $sentByType;
}

