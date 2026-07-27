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

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    echo '  ! seeding failed, rolled back: ' . $e->getMessage() . "\n";
    migFinish($conn, 'demo');
    return;
}

echo "\n  Sign in with:\n";
echo "    owner     bella.owner    / " . DEMO_PASSWORD . "   (Salon-Inhaber, full rights)\n";
echo "    delegate  bella.delegate / " . DEMO_PASSWORD . "   (only 'Einblicke sehen')\n";
echo "    tablet    bella.tablet   / " . DEMO_PASSWORD . "   (kiosk, no dashboard)\n";

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

    $people = [
        ['bella.owner',    'owner@bella-vista.de',    'Bella Fischer',  'customer_admin'],
        ['bella.delegate', 'delegate@bella-vista.de', 'Timo Krüger',    'customer_admin_delegate'],
        ['bella.tablet',   'tablet@bella-vista.de',   'Bella Vista Tablet', 'customer_facing_tablet_user'],
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

        $email = sprintf('%s.%s%d@example.test', strtolower($firstName), strtolower(rtrim($lastName, 'ß')), $i);
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
