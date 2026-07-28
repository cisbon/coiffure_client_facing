<?php
/**
 * Demo data seeder
 * -------------------------------------------------------------------
 *   php api/seed-demo.php                       (CLI)
 *   GET seed-demo.php?token=<MIGRATION_TOKEN>   (or an administrator session)
 *
 * Creates a complete, believable salon so the dashboard can be demonstrated
 * immediately: a salon, its owner and a restricted delegate, ~45 customers with
 * varied ages, postcodes, membership and consent, and ~8 weeks of check-ins
 * weighted towards weekends the way a real salon looks. A few customers have a
 * birthday in the coming week so the Übersicht birthday card is populated.
 *
 * Idempotent: re-running finds the existing demo salon by its subdomain and
 * leaves it alone unless ?reset=1 is passed, which deletes and rebuilds it.
 *
 * This writes real rows, so it is gated exactly like the migration runners.
 */

require_once __DIR__ . '/migration_helpers.php';

$conn = migStart('demo', 'Demo salon, users, customers and visits');
if (!$conn) {
    return;
}

const DEMO_SUBDOMAIN = 'bella-vista-demo';
const DEMO_PASSWORD = 'demo1234';

$reset = isset($_GET['reset']) || in_array('--reset', $argv ?? [], true);

/* ============================================================
   Salon
   ============================================================ */

$existing = null;
if (migColumnExists($conn, 'coiffure_salons', 'subdomain')) {
    $stmt = $conn->prepare('SELECT salon_id FROM coiffure_salons WHERE subdomain = ?');
    $sub = DEMO_SUBDOMAIN;
    $stmt->bind_param('s', $sub);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($existing && !$reset) {
    echo "  - demo salon already exists (salon_id {$existing['salon_id']})\n";
    echo "    pass ?reset=1 (or --reset) to delete and rebuild it\n";
    migFinish($conn, 'demo');
    return;
}

if ($existing && $reset) {
    // FKs cascade from the salon, so this clears customers, visits and users.
    $conn->query('DELETE FROM coiffure_salons WHERE salon_id = ' . (int)$existing['salon_id']);
    echo "  ~ removed the previous demo salon\n";
}

$conn->begin_transaction();

try {
    $salonId = insertSalon($conn);
    echo "  + salon 'Salon Bella Vista' (salon_id $salonId)\n";

    [$ownerId, $delegateId, $tabletId] = insertUsers($conn, $salonId);
    echo "  + users: owner, delegate (view_insights only), tablet kiosk\n";

    $customerIds = insertCustomers($conn, $salonId);
    echo '  + ' . count($customerIds) . " customers\n";

    $visitCount = insertVisits($conn, $salonId, $customerIds);
    echo "  + $visitCount check-ins across the last 8 weeks\n";

    $consentRows = insertConsentHistory($conn, $salonId, $customerIds);
    echo "  + $consentRows consent records (Protokoll → Einwilligungen)\n";

    $billing = insertBilling($conn, $salonId);
    echo "  + $billing\n";

    $notifications = insertNotifications($conn, $salonId, $ownerId, $delegateId);
    echo "  + $notifications notifications for the owner\n";

    $autos = insertAutoCampaigns($conn, $salonId);
    echo "  + $autos\n";

    $campaign = insertPastCampaign($conn, $salonId, $ownerId, $customerIds);
    echo "  + $campaign\n";

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    echo '  ! seeding failed, rolled back: ' . $e->getMessage() . "\n";
    migFinish($conn, 'demo');
    return;
}

echo "\n  Sign in with:\n";
echo "    owner     bella_owner    / " . DEMO_PASSWORD . "   (Salon-Inhaber, full rights)\n";
echo "    delegate  bella_delegate / " . DEMO_PASSWORD . "   (only 'Einblicke sehen')\n";
echo "    tablet    bella_tablet   / " . DEMO_PASSWORD . "   (kiosk, no dashboard)\n";

migFinish($conn, 'demo');

/* ============================================================
   Builders
   ============================================================ */

function insertSalon(mysqli $conn): int
{
    $columns = [
        'salon_name'  => 'Salon Bella Vista',
        'email'       => 'hallo@bella-vista.de',
        'phone'       => '+49 30 1234567',
        'address'     => "Kastanienallee 42\n10435 Berlin",
        'policy_version' => '1.0',
    ];

    // Optional columns, present only after the matching migration.
    $optional = [
        'subdomain'        => DEMO_SUBDOMAIN,
        'status'           => 'active',
        'currency'         => 'EUR',
        'website'          => 'https://bella-vista.de',
        'default_language' => 'de',
        'primary_color'    => '#2563EB',
        'secondary_color'  => '#0EA5E9',
        'birthday_enabled' => 1,
        'birthday_days_before' => 7,
        'birthday_subject' => 'Alles Gute zum Geburtstag, {vorname}!',
        'birthday_body'    => "Liebe/r {vorname},\n\nwir wünschen dir alles Gute zum Geburtstag! "
                            . "Mit dem Code {rabattcode} bekommst du diesen Monat 20 % auf deinen Besuch.\n\n"
                            . "Dein Team von {salonname}",
        'birthday_discount_code' => 'GEBURTSTAG20',
    ];

    foreach ($optional as $column => $value) {
        if (migColumnExists($conn, 'coiffure_salons', $column)) {
            $columns[$column] = $value;
        }
    }

    $names = array_keys($columns);
    $sql = 'INSERT INTO coiffure_salons (' . implode(', ', $names) . ') VALUES ('
         . implode(', ', array_fill(0, count($names), '?')) . ')';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('salon insert prepare failed: ' . $conn->error);
    }

    $values = array_values($columns);
    $stmt->bind_param(str_repeat('s', count($values)), ...$values);
    if (!$stmt->execute()) {
        throw new RuntimeException('salon insert failed: ' . $stmt->error);
    }
    $salonId = $stmt->insert_id;
    $stmt->close();

    return $salonId;
}

function insertUsers(mysqli $conn, int $salonId): array
{
    $hash = password_hash(DEMO_PASSWORD, PASSWORD_BCRYPT, ['cost' => 12]);

    // Underscores, not dots: the platform's own username rule is
    // [a-zA-Z0-9_-]{3,50}, so a dotted name could be seeded but never created
    // or edited through the dashboard afterwards.
    $people = [
        ['bella_owner',    'owner@bella-vista.de',    'Bella Fischer',  'customer_admin'],
        ['bella_delegate', 'delegate@bella-vista.de', 'Timo Krüger',    'customer_admin_delegate'],
        ['bella_tablet',   'tablet@bella-vista.de',   'Bella Vista Tablet', 'customer_facing_tablet_user'],
    ];

    $ids = [];
    foreach ($people as [$username, $email, $fullName, $role]) {
        $stmt = $conn->prepare(
            'INSERT INTO coiffure_users (username, email, password_hash, full_name, role, salon_id, is_active, email_verified)
             VALUES (?, ?, ?, ?, ?, ?, 1, 1)'
        );
        if (!$stmt) {
            throw new RuntimeException('user insert prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('sssssi', $username, $email, $hash, $fullName, $role, $salonId);
        if (!$stmt->execute()) {
            throw new RuntimeException("user insert failed for $username: " . $stmt->error);
        }
        $userId = $stmt->insert_id;
        $stmt->close();

        $link = $conn->prepare('INSERT IGNORE INTO coiffure_user_salons (user_id, salon_id) VALUES (?, ?)');
        $link->bind_param('ii', $userId, $salonId);
        $link->execute();
        $link->close();

        $ids[] = $userId;
    }

    // The delegate demonstrates the granular permission layer: insights only,
    // so the sidebar hides campaigns, users and settings for them.
    if (migTableExists($conn, 'coiffure_user_permissions')) {
        $stmt = $conn->prepare(
            'INSERT IGNORE INTO coiffure_user_permissions (user_id, salon_id, permission, granted_by)
             VALUES (?, ?, ?, ?)'
        );
        $permission = 'view_insights';
        $stmt->bind_param('iisi', $ids[1], $salonId, $permission, $ids[0]);
        $stmt->execute();
        $stmt->close();
    }

    return $ids;
}

function insertCustomers(mysqli $conn, int $salonId): array
{
    $first = ['Anna', 'Sarah', 'Julia', 'Lena', 'Marie', 'Laura', 'Sophie', 'Nina', 'Clara', 'Emma',
              'Michael', 'Thomas', 'Stefan', 'Daniel', 'Jonas', 'Felix', 'Lukas', 'Tim', 'Paul', 'Jan',
              'Katrin', 'Sandra', 'Melanie', 'Christina', 'Vanessa', 'Franziska', 'Hannah', 'Leonie',
              'Andreas', 'Martin', 'Christian', 'Sebastian', 'Alexander', 'Philipp', 'Nico',
              'Miriam', 'Jasmin', 'Carolin', 'Elena', 'Theresa', 'Robert', 'Markus', 'Dennis', 'Kevin', 'Sven'];

    $last = ['Schmidt', 'Müller', 'Weber', 'Fischer', 'Wagner', 'Becker', 'Hoffmann', 'Schulz',
             'Koch', 'Richter', 'Klein', 'Wolf', 'Neumann', 'Schwarz', 'Zimmermann', 'Braun',
             'Krüger', 'Hartmann', 'Lange', 'Werner', 'Krause', 'Meier', 'Lehmann', 'Schmitt',
             'Köhler', 'Herrmann', 'Walter', 'König', 'Mayer', 'Huber'];

    // Berlin postcodes, so the PLZ filter has something meaningful to slice.
    $areas = [
        ['10115', 'Berlin'], ['10119', 'Berlin'], ['10435', 'Berlin'], ['10437', 'Berlin'],
        ['10557', 'Berlin'], ['10623', 'Berlin'], ['12043', 'Berlin'], ['12047', 'Berlin'],
        ['13355', 'Berlin'], ['14059', 'Berlin'],
    ];

    $sources = ['Google', 'Instagram', 'Empfehlung', 'Laufkundschaft', 'Facebook'];

    $stmt = $conn->prepare(
        'INSERT INTO coiffure_customers
            (salon_id, full_name, first_name, last_name, gender, email, phone, mobile,
             birth_day, birth_month, birth_year, zip, city,
             consent_data_processing, consent_marketing, consent_email_marketing, consent_sms_whatsapp,
             policy_version_accepted, is_member, member_id, member_since, referral_source, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('customer insert prepare failed: ' . $conn->error);
    }

    $ids = [];
    $total = 45;

    for ($i = 0; $i < $total; $i++) {
        $firstName = $first[$i % count($first)];
        $lastName = $last[($i * 7) % count($last)];
        $fullName = "$firstName $lastName";

        // Roughly the mix a salon sees rather than an even split.
        $gender = $i % 10 < 7 ? 'female' : ($i % 10 < 9 ? 'male' : 'diverse');

        // Ages 19-68 so the age-range filter has spread.
        $age = 19 + (($i * 13) % 50);
        $birthYear = (int)date('Y') - $age;

        // The first six get a birthday inside the coming week so the Übersicht
        // birthday card and the automatic birthday campaign have real targets.
        if ($i < 6) {
            $when = strtotime('+' . ($i + 1) . ' day');
        } else {
            $when = strtotime('2000-01-01 +' . (($i * 37) % 365) . ' day');
        }
        $birthDay = (int)date('j', $when);
        $birthMonth = (int)date('n', $when);

        [$zip, $city] = $areas[$i % count($areas)];

        // ~70% consent to marketing e-mail; the rest exercise the
        // consent-aware export and the campaign recipient filter.
        $consentEmail = ($i % 10) < 7 ? 1 : 0;
        $consentSms = ($i % 4) === 0 ? 1 : 0;

        // ~60% are members.
        $isMember = ($i % 5) < 3 ? 1 : 0;
        $memberId = $isMember ? sprintf('M%02d-%s', (int)date('y'), strtoupper(substr(md5("demo$i"), 0, 6))) : null;

        // Registered over the past ~10 months, newest first few.
        $daysAgo = (int)(($i * 6.5) + ($i % 5));
        $createdAt = date('Y-m-d H:i:s', strtotime("-$daysAgo day"));
        $memberSince = $isMember ? date('Y-m-d', strtotime("-$daysAgo day")) : null;

        // Transliterate umlauts: an address like "köhler@…" is not a valid
        // e-mail local part and would be rejected before sending.
        $email = sprintf(
            '%s.%s%d@example.test',
            asciiSlug($firstName),
            asciiSlug($lastName),
            $i
        );
        $phone = sprintf('+4915%08d', 1000000 + $i * 137);
        $policy = '1.0';
        $source = $sources[$i % count($sources)];

        $args = [
            $salonId, $fullName, $firstName, $lastName, $gender, $email, $phone, $phone,
            $birthDay, $birthMonth, $birthYear, $zip, $city,
            $consentEmail, $consentEmail, $consentSms,
            $policy, $isMember, $memberId, $memberSince, $source, $createdAt,
        ];

        // Derive the type string from the values so the two can never drift.
        $types = '';
        foreach ($args as $value) {
            $types .= is_int($value) ? 'i' : 's';
        }

        $stmt->bind_param($types, ...$args);
        if (!$stmt->execute()) {
            throw new RuntimeException("customer insert failed for $fullName: " . $stmt->error);
        }
        $ids[] = $stmt->insert_id;
    }

    $stmt->close();
    return $ids;
}

function insertVisits(mysqli $conn, int $salonId, array $customerIds): int
{
    $stmt = $conn->prepare(
        'INSERT INTO coiffure_visits (customer_id, salon_id, checkin_method, checked_in_at)
         VALUES (?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('visit insert prepare failed: ' . $conn->error);
    }

    $count = 0;
    $methods = ['birthday', 'phone', 'manual'];

    // A handful of customers whose last visit was 11-17 weeks ago. They are the
    // "went inactive" series on the growth chart and the population the
    // "Wir vermissen Dich" campaign targets -- without them both features would
    // look broken in a demo.
    $lapsed = array_slice($customerIds, -8);
    foreach ($lapsed as $index => $customerId) {
        $weeksAgo = 11 + ($index % 7);
        for ($visit = 0; $visit < 2 + ($index % 3); $visit++) {
            $daysBack = ($weeksAgo * 7) + ($visit * 9);
            $checkedIn = date('Y-m-d H:i:s', strtotime("-$daysBack day 11:30"));
            $method = $methods[$index % 3];
            $stmt->bind_param('iiss', $customerId, $salonId, $method, $checkedIn);
            if ($stmt->execute()) {
                $count++;
            }
        }
    }

    // Walk the last 8 weeks day by day and give each day a plausible number of
    // check-ins: busier Thursday-Saturday, closed Sunday.
    for ($daysAgo = 56; $daysAgo >= 0; $daysAgo--) {
        $timestamp = strtotime("-$daysAgo day");
        $weekday = (int)date('N', $timestamp);   // 1 Mon .. 7 Sun

        if ($weekday === 7) {
            continue;   // closed on Sundays
        }

        $base = match ($weekday) {
            4, 5 => 8,   // Thu, Fri
            6    => 10,  // Sat
            default => 5,
        };
        // Deterministic jitter so repeated seeds look the same.
        $visitsToday = max(1, $base + (($daysAgo * 7) % 5) - 2);

        for ($v = 0; $v < $visitsToday; $v++) {
            // Bias towards the first two thirds of the list so some customers
            // are clearly "best customers" and the tail looks inactive.
            $index = (($daysAgo * 13) + ($v * 29)) % (int)max(1, count($customerIds) * 0.75);
            $customerId = $customerIds[$index];

            $hour = 9 + (($v * 3) % 9);
            $minute = ($v * 17) % 60;
            $checkedIn = date('Y-m-d', $timestamp) . sprintf(' %02d:%02d:00', $hour, $minute);
            $method = $methods[($daysAgo + $v) % 3];

            $stmt->bind_param('iiss', $customerId, $salonId, $method, $checkedIn);
            if ($stmt->execute()) {
                $count++;
            }
        }
    }

    $stmt->close();
    return $count;
}

/**
 * A consent record for every customer, plus a handful of later changes.
 *
 * Without this the Einwilligungen tab is empty on a fresh demo even though the
 * customers plainly have consent flags -- the trail only fills going forward,
 * so a seeded salon needs its history seeded too.
 */
function insertConsentHistory(mysqli $conn, int $salonId, array $customerIds): int
{
    if (!migTableExists($conn, 'coiffure_consent_history')) {
        return 0;
    }

    $stmt = $conn->prepare(
        'INSERT INTO coiffure_consent_history
            (customer_id, salon_id, consent_field, old_value, new_value,
             policy_version, source, changed_by, ip_address, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('consent insert prepare failed: ' . $conn->error);
    }

    $written = 0;
    foreach (array_values($customerIds) as $index => $customerId) {
        // What they agreed to at registration.
        $registered = date('Y-m-d H:i:s', strtotime('-' . (int)(($index * 6.5) + ($index % 5)) . ' day'));
        $marketing = ($index % 10) < 7 ? '1' : '0';

        $grants = [
            ['consent_data_processing', null, '1'],
            ['consent_email_marketing', null, $marketing],
            ['consent_sms_whatsapp', null, ($index % 4) === 0 ? '1' : '0'],
        ];

        foreach ($grants as [$field, $old, $new]) {
            $source = 'tablet';
            $changedBy = 'tablet_form';
            $policy = '1.0';
            $ip = '192.168.178.' . (20 + ($index % 200));
            $stmt->bind_param(
                'iissssssss',
                $customerId, $salonId, $field, $old, $new, $policy, $source, $changedBy, $ip, $registered
            );
            if ($stmt->execute()) {
                $written++;
            }
        }

        // Every seventh customer later withdrew marketing consent, so the trail
        // shows a real change and not only a wall of initial grants.
        if ($index % 7 === 3 && $marketing === '1') {
            $withdrawnAt = date('Y-m-d H:i:s', strtotime('-' . (5 + ($index % 20)) . ' day'));
            $field = 'consent_email_marketing';
            $old = '1';
            $new = '0';
            $policy = '1.0';
            $source = 'dashboard';
            $changedBy = 'bella_owner';
            $ip = '192.168.178.10';
            $stmt->bind_param(
                'iissssssss',
                $customerId, $salonId, $field, $old, $new, $policy, $source, $changedBy, $ip, $withdrawnAt
            );
            if ($stmt->execute()) {
                $written++;
            }
        }
    }

    $stmt->close();
    return $written;
}

/**
 * A subscription and two invoices, so the Abrechnung screen has something to
 * show. The plans themselves come from migration 022.
 */
function insertBilling(mysqli $conn, int $salonId): string
{
    if (!migTableExists($conn, 'coiffure_salon_subscriptions')) {
        return 'billing skipped (migration 022 not applied)';
    }

    // Middle plan: expensive enough to be interesting, not the top tier.
    $planRow = $conn->query(
        'SELECT plan_id, name, monthly_price, currency FROM coiffure_subscription_plans
         WHERE is_active = 1 ORDER BY sort_order, monthly_price LIMIT 1 OFFSET 1'
    );
    $plan = $planRow ? $planRow->fetch_assoc() : null;
    if (!$plan) {
        return 'billing skipped (no subscription plans)';
    }

    $planId = (int)$plan['plan_id'];
    $startedAt = date('Y-m-d', strtotime('-8 month'));

    $stmt = $conn->prepare(
        "INSERT INTO coiffure_salon_subscriptions
            (salon_id, plan_id, payment_status, started_at, notes)
         VALUES (?, ?, 'active', ?, 'Demo-Abonnement')
         ON DUPLICATE KEY UPDATE plan_id = VALUES(plan_id)"
    );
    if ($stmt) {
        $stmt->bind_param('iis', $salonId, $planId, $startedAt);
        $stmt->execute();
        $stmt->close();
    }

    if (!migTableExists($conn, 'coiffure_invoices')) {
        return "subscription on the '{$plan['name']}' plan";
    }

    // Last month paid, this month still open -- one of each status.
    $invoices = 0;
    foreach ([['-2 month', 'paid'], ['-1 month', 'open']] as $offset => $spec) {
        [$when, $status] = $spec;
        $year = (int)date('Y', strtotime($when));
        $month = (int)date('n', strtotime($when));
        $number = sprintf('%d-%04d', $year, 9000 + $offset);   // demo range
        $subtotal = (float)$plan['monthly_price'];
        $taxRate = 19.0;
        $taxAmount = round($subtotal * $taxRate / 100, 2);
        $total = round($subtotal + $taxAmount, 2);
        $currency = $plan['currency'];
        $issuedAt = date('Y-m-01', strtotime($when));
        $paidAt = $status === 'paid' ? date('Y-m-05', strtotime($when)) : null;

        $insert = $conn->prepare(
            'INSERT INTO coiffure_invoices
                (invoice_number, salon_id, plan_id, period_year, period_month,
                 subtotal, tax_rate, tax_amount, total, currency, status, issued_at, paid_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$insert) {
            continue;
        }
        $insert->bind_param(
            'siiiddddsssss',
            $number, $salonId, $planId, $year, $month,
            $subtotal, $taxRate, $taxAmount, $total, $currency, $status, $issuedAt, $paidAt
        );
        if (!$insert->execute()) {
            $insert->close();
            continue;
        }
        $invoiceId = $insert->insert_id;
        $insert->close();
        $invoices++;

        $item = $conn->prepare(
            'INSERT INTO coiffure_invoice_items
                (invoice_id, description, quantity, unit_price, amount, sort_order)
             VALUES (?, ?, 1, ?, ?, 0)'
        );
        if ($item) {
            $description = sprintf('%s – %02d/%d', $plan['name'], $month, $year);
            $item->bind_param('isdd', $invoiceId, $description, $subtotal, $subtotal);
            $item->execute();
            $item->close();
        }
    }

    return "subscription on the '{$plan['name']}' plan and $invoices invoices";
}

/**
 * A few unread notifications so the bell has a badge on first sign-in.
 * Stored as translation keys, exactly as the live writers do.
 */
function insertNotifications(mysqli $conn, int $salonId, int $ownerId, int $delegateId): int
{
    if (!migTableExists($conn, 'coiffure_notifications')) {
        return 0;
    }

    $stmt = $conn->prepare(
        'INSERT INTO coiffure_notifications
            (user_id, salon_id, type, title_key, params, link, read_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('notification insert prepare failed: ' . $conn->error);
    }

    $items = [
        ['registration', 'admin.notify.registration',
         ['name' => 'Emma Zimmermann'], '#/kunden', null, '-2 hour'],
        ['campaign_sent', 'admin.notify.campaign_sent',
         ['name' => 'Frühlingsaktion', 'count' => 28], '#/kampagnen?tab=log', null, '-1 day'],
        ['registration', 'admin.notify.registration',
         ['name' => 'Paul Werner'], '#/kunden', null, '-2 day'],
        // One already read, so the list is not uniformly bold.
        ['user_invited', 'admin.notify.user_invited',
         ['name' => 'Timo Krüger'], '#/benutzer', '-3 day', '-3 day'],
    ];

    $written = 0;
    foreach ($items as [$type, $key, $params, $link, $readOffset, $createdOffset]) {
        $paramsJson = json_encode($params, JSON_UNESCAPED_UNICODE);
        $readAt = $readOffset ? date('Y-m-d H:i:s', strtotime($readOffset)) : null;
        $createdAt = date('Y-m-d H:i:s', strtotime($createdOffset));

        $stmt->bind_param(
            'iissssss',
            $ownerId, $salonId, $type, $key, $paramsJson, $link, $readAt, $createdAt
        );
        if ($stmt->execute()) {
            $written++;
        }
    }

    // The delegate holds view_insights, so they get the registration only --
    // the same rule notifySalonAdmins() applies at runtime.
    $type = 'registration';
    $key = 'admin.notify.registration';
    $paramsJson = json_encode(['name' => 'Emma Zimmermann'], JSON_UNESCAPED_UNICODE);
    $link = '#/kunden';
    $readAt = null;
    $createdAt = date('Y-m-d H:i:s', strtotime('-2 hour'));
    $stmt->bind_param('iissssss', $delegateId, $salonId, $type, $key, $paramsJson, $link, $readAt, $createdAt);
    if ($stmt->execute()) {
        $written++;
    }

    $stmt->close();
    return $written;
}

/**
 * The four automatic campaign types, with birthday and we-miss-you switched on.
 *
 * ensureAutoCampaigns() creates them on first visit to the Kampagnen screen,
 * but a demo should already have something enabled -- otherwise "Jetzt
 * ausführen" reports nothing due and looks broken.
 */
function insertAutoCampaigns(mysqli $conn, int $salonId): string
{
    if (!migTableExists($conn, 'coiffure_automatic_campaigns')) {
        return 'automatic campaigns skipped (migration 020 not applied)';
    }

    require_once __DIR__ . '/campaign_engine.php';
    ensureAutoCampaigns($conn, $salonId);

    // Two of the four on, so the screen shows both states.
    $stmt = $conn->prepare(
        "UPDATE coiffure_automatic_campaigns SET enabled = 1
         WHERE salon_id = ? AND type IN ('birthday', 'we_miss_you')"
    );
    if ($stmt) {
        $stmt->bind_param('i', $salonId);
        $stmt->execute();
        $stmt->close();
    }

    return 'automatic campaigns (Geburtstag and Wir vermissen Dich enabled)';
}

/**
 * One completed campaign with its recipients, so the Kampagnen screen and its
 * log are not empty on a fresh demo.
 *
 * No mail is sent -- this writes the record of a send that happened three weeks
 * ago, including opens and clicks, so the log shows realistic numbers.
 */
function insertPastCampaign(mysqli $conn, int $salonId, int $ownerId, array $customerIds): string
{
    if (!migTableExists($conn, 'coiffure_campaigns')) {
        return 'campaign log skipped (migration 020 not applied)';
    }

    $sentAt = date('Y-m-d H:i:s', strtotime('-21 day'));

    // Only customers who consented to marketing e-mail, exactly as a real send
    // would resolve them.
    $stmt = $conn->prepare(
        'SELECT customer_id, email FROM coiffure_customers
         WHERE salon_id = ? AND is_deleted = 0
           AND (consent_email_marketing = 1 OR consent_marketing = 1)'
    );
    if (!$stmt) {
        return 'campaign log skipped';
    }
    $stmt->bind_param('i', $salonId);
    $stmt->execute();
    $recipients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($recipients)) {
        return 'campaign log skipped (no consenting customers)';
    }

    $name = 'Frühlingsaktion';
    $subject = 'Frühlingsfrisur gefällig, {vorname}?';
    $body = '<p>Hallo {vorname},</p><p>der Frühling ist da – und mit ihm unsere neue Farbaktion. '
          . 'Mit dem Code <strong>{rabattcode}</strong> bekommst du diesen Monat 15 % auf jede Coloration.</p>'
          . '<p>Wir freuen uns auf dich!<br>Dein Team von {salonname}</p>';
    $code = 'FRUEHLING15';
    $count = count($recipients);

    $insert = $conn->prepare(
        "INSERT INTO coiffure_campaigns
            (salon_id, name, kind, status, recipient_type, recipient_count,
             subject, body, discount_enabled, discount_mode, discount_code,
             discount_type, discount_value, started_at, completed_at,
             sent_count, open_count, click_count, created_by, created_at)
         VALUES (?, ?, 'once', 'sent', 'all', ?, ?, ?, 1, 'generic', ?,
                 'percentage', 15.00, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$insert) {
        return 'campaign log skipped';
    }

    // Plausible engagement: ~45% opened, ~12% clicked.
    $opens = (int)round($count * 0.45);
    $clicks = (int)round($count * 0.12);

    $insert->bind_param(
        'isisssssiiiis',
        $salonId, $name, $count, $subject, $body, $code,
        $sentAt, $sentAt, $count, $opens, $clicks, $ownerId, $sentAt
    );
    if (!$insert->execute()) {
        $insert->close();
        return 'campaign log skipped: ' . $insert->error;
    }
    $campaignId = $insert->insert_id;
    $insert->close();

    $recipientStmt = $conn->prepare(
        "INSERT INTO coiffure_campaign_recipients
            (campaign_id, customer_id, salon_id, email, status, discount_code,
             tracking_token, sent_at, opened_at, clicked_at)
         VALUES (?, ?, ?, ?, 'sent', ?, ?, ?, ?, ?)"
    );
    if (!$recipientStmt) {
        return "campaign 'Frühlingsaktion' in the log";
    }

    foreach (array_values($recipients) as $index => $recipient) {
        $customerId = (int)$recipient['customer_id'];
        $email = (string)$recipient['email'];
        $token = bin2hex(random_bytes(16));
        $openedAt = $index < $opens ? date('Y-m-d H:i:s', strtotime('-21 day +' . (2 + $index % 30) . ' hour')) : null;
        $clickedAt = $index < $clicks ? date('Y-m-d H:i:s', strtotime('-21 day +' . (3 + $index % 30) . ' hour')) : null;

        $recipientStmt->bind_param(
            'iiissssss',
            $campaignId, $customerId, $salonId, $email, $code, $token, $sentAt, $openedAt, $clickedAt
        );
        $recipientStmt->execute();
    }
    $recipientStmt->close();

    return "campaign 'Frühlingsaktion' sent to $count recipients ($opens opens, $clicks clicks)";
}

/** Lowercase ASCII form of a German name, safe for an e-mail local part. */
function asciiSlug(string $value): string
{
    $map = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue'];
    $value = strtr($value, $map);
    $value = strtolower($value);
    return preg_replace('/[^a-z0-9]/', '', $value);
}
