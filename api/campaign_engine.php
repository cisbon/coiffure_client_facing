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
