<?php
/**
 * Salon settings
 * -------------------------------------------------------------------
 *   GET  salon-settings.php[?salon_id=N]   → every section in one payload
 *   POST salon-settings.php?section=…      → save one section
 *
 * Sections (spec 3.3):
 *   general     name, address, contact details, website
 *   tablet      welcome headline, background, idle timeout, module toggles
 *   membership  refer-a-friend (loyalty itself stays in loyalty-config.php,
 *               which the tablet also reads)
 *   birthday    automatic birthday mail: timing, subject, body, discount code
 *   hours       opening hours shown on the tablet
 *
 * Access: change_settings. Two neighbours own the rest, and this endpoint
 * deliberately does not duplicate them: salon-branding.php has the logo,
 * colours and guest WiFi (it needs a multipart upload; this endpoint is JSON
 * only), and social-links.php has the social links the tablet renders.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';

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
requirePermission($conn, $user, 'change_settings', $salonId);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    handleGet($conn, $salonId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    handleSave($conn, $salonId, $user, $_GET['section'] ?? '', $input);
}

sendErrorResponse('Method not allowed.', 405);

/** Columns added by migration 018; absent on a database that has not run it. */
function settingsColumnsReady(mysqli $conn): bool
{
    $res = $conn->query("SHOW COLUMNS FROM coiffure_salons LIKE 'tablet_modules'");
    return $res && $res->num_rows > 0;
}

function loadSalonRow(mysqli $conn, int $salonId): array
{
    $stmt = $conn->prepare('SELECT * FROM coiffure_salons WHERE salon_id = ?');
    if (!$stmt) {
        sendErrorResponse('Failed to load the salon.', 500);
    }
    $stmt->bind_param('i', $salonId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        sendErrorResponse('Salon not found.', 404);
    }
    return $row;
}

function handleGet(mysqli $conn, int $salonId): void
{
    $salon = loadSalonRow($conn, $salonId);
    $ready = settingsColumnsReady($conn);

    // tablet_modules is stored as JSON; default everything to on so a salon
    // that never touched the setting behaves as it always has.
    $modules = ['register' => true, 'checkin' => true, 'browse' => true];
    if ($ready && !empty($salon['tablet_modules'])) {
        $decoded = json_decode((string)$salon['tablet_modules'], true);
        if (is_array($decoded)) {
            foreach ($modules as $key => $default) {
                $modules[$key] = !empty($decoded[$key]);
            }
        }
    }

    $hours = [];
    if (tableExists($conn, 'coiffure_salon_hours')) {
        $stmt = $conn->prepare(
            'SELECT weekday, is_closed, open_time, close_time
             FROM coiffure_salon_hours WHERE salon_id = ? ORDER BY weekday'
        );
        if ($stmt) {
            $stmt->bind_param('i', $salonId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $hours[(int)$row['weekday']] = [
                    'weekday'    => (int)$row['weekday'],
                    'is_closed'  => (int)$row['is_closed'] === 1,
                    'open_time'  => $row['open_time'] ? substr($row['open_time'], 0, 5) : null,
                    'close_time' => $row['close_time'] ? substr($row['close_time'], 0, 5) : null,
                ];
            }
            $stmt->close();
        }
    }

    // Always return all seven days so the UI does not have to fill gaps.
    $week = [];
    for ($day = 0; $day < 7; $day++) {
        $week[] = $hours[$day] ?? [
            'weekday' => $day, 'is_closed' => $day === 6, 'open_time' => null, 'close_time' => null,
        ];
    }

    sendJsonResponse([
        'success' => true,
        'migration_ready' => $ready,
        'general' => [
            'salon_name' => $salon['salon_name'],
            'email'      => $salon['email'],
            'phone'      => $salon['phone'],
            'address'    => $salon['address'],
            'website'    => $salon['website'] ?? null,
            'subdomain'  => $salon['subdomain'] ?? null,
            'currency'   => $salon['currency'] ?? 'EUR',
            'default_language' => $salon['default_language'] ?? 'de',
        ],
        'tablet' => [
            'headline'      => $salon['tablet_headline'] ?? null,
            'bg_image'      => $salon['tablet_bg_image'] ?? null,
            'bg_color'      => $salon['tablet_bg_color'] ?? null,
            'idle_timeout_s' => isset($salon['tablet_idle_timeout_s']) ? (int)$salon['tablet_idle_timeout_s'] : null,
            'modules'       => $modules,
        ],
        'membership' => [
            'referral_enabled'        => (int)($salon['referral_enabled'] ?? 1) === 1,
            'referral_discount_value' => (float)($salon['referral_discount_value'] ?? 10),
            // Repeated from loyalty-config.php so the settings screen can show
            // the whole membership picture in one place, read-only here.
            'loyalty_active'          => (int)($salon['loyalty_active'] ?? 1) === 1,
            'loyalty_visit_threshold' => (int)($salon['loyalty_visit_threshold'] ?? 5),
        ],
        'birthday' => [
            'enabled'       => (int)($salon['birthday_enabled'] ?? 0) === 1,
            'days_before'   => (int)($salon['birthday_days_before'] ?? 7),
            'subject'       => $salon['birthday_subject'] ?? null,
            'body'          => $salon['birthday_body'] ?? null,
            'discount_code' => $salon['birthday_discount_code'] ?? null,
        ],
        'hours' => $week,
        'campaign_limits' => [
            'spam_limit'       => (int)($salon['campaign_spam_limit'] ?? 4),
            'spam_window_days' => (int)($salon['campaign_spam_window_days'] ?? 30),
        ],
    ], 200);
}

function handleSave(mysqli $conn, int $salonId, array $user, string $section, array $input): void
{
    if (!settingsColumnsReady($conn) && $section !== 'general') {
        sendErrorResponse('These settings need migration 018 to be applied first.', 503);
    }

    switch ($section) {
        case 'general':    saveGeneral($conn, $salonId, $user, $input); break;
        case 'tablet':     saveTablet($conn, $salonId, $user, $input); break;
        case 'membership': saveMembership($conn, $salonId, $user, $input); break;
        case 'birthday':   saveBirthday($conn, $salonId, $user, $input); break;
        case 'hours':      saveHours($conn, $salonId, $user, $input); break;
        default:           sendErrorResponse('Unknown section.', 400);
    }
}

/**
 * Update a set of columns on the salon row, recording the before/after of each
 * change in coiffure_settings_audit (the table the kiosk settings already use).
 */
function updateSalonColumns(mysqli $conn, int $salonId, array $user, array $columns, string $sectionLabel): void
{
    if (empty($columns)) {
        sendJsonResponse(['success' => true, 'changed' => 0], 200);
    }

    $before = loadSalonRow($conn, $salonId);

    $sets = [];
    $args = [];
    foreach ($columns as $column => $value) {
        // Skip anything the database does not have, so a partially migrated
        // install saves what it can instead of failing wholesale.
        if (!columnExists($conn, 'coiffure_salons', $column)) {
            continue;
        }
        $sets[] = "`$column` = ?";
        $args[] = $value;
    }

    if (empty($sets)) {
        sendErrorResponse('None of these settings exist on this database yet.', 503);
    }

    $args[] = $salonId;
    $stmt = $conn->prepare('UPDATE coiffure_salons SET ' . implode(', ', $sets) . ' WHERE salon_id = ?');
    if (!$stmt) {
        sendErrorResponse('Failed to save the settings.', 500);
    }
    bindTyped($stmt, $args);
    if (!$stmt->execute()) {
        $stmt->close();
        sendErrorResponse('Failed to save the settings.', 500);
    }
    $stmt->close();

    // Per-field audit trail, so a salon owner can see who changed what.
    $changed = 0;
    if (tableExists($conn, 'coiffure_settings_audit')) {
        $audit = $conn->prepare(
            'INSERT INTO coiffure_settings_audit (salon_id, changed_by, setting_key, old_value, new_value)
             VALUES (?, ?, ?, ?, ?)'
        );
        if ($audit) {
            foreach ($columns as $column => $value) {
                if (!columnExists($conn, 'coiffure_salons', $column)) {
                    continue;
                }
                $old = (string)($before[$column] ?? '');
                $new = (string)$value;
                if ($old === $new) {
                    continue;
                }
                // Never write a password into the audit trail.
                if (str_contains($column, 'password')) {
                    $old = '***';
                    $new = '***';
                }
                $changedBy = $user['username'] ?? 'system';
                $audit->bind_param('issss', $salonId, $changedBy, $column, $old, $new);
                $audit->execute();
                $changed++;
            }
            $audit->close();
        }
    }

    logAdminAudit($conn, $user, 'salon', $salonId, 'update', "Settings updated: $sectionLabel", $salonId);

    sendJsonResponse(['success' => true, 'changed' => $changed], 200);
}

/* ============================================================
   Sections
   ============================================================ */

function saveGeneral(mysqli $conn, int $salonId, array $user, array $input): void
{
    $errors = [];

    $name = trim((string)($input['salon_name'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $phone = trim((string)($input['phone'] ?? ''));

    if ($name === '') $errors['salon_name'] = 'Ein Salonname ist erforderlich.';
    if ($email === '') $errors['email'] = 'Eine E-Mail-Adresse ist erforderlich.';
    elseif (!validateEmail($email)) $errors['email'] = 'Bitte eine gültige E-Mail-Adresse angeben.';

    $website = trim((string)($input['website'] ?? ''));
    if ($website !== '' && !preg_match('#^https?://#i', $website)) {
        $errors['website'] = 'Die Website muss mit https:// beginnen.';
    }

    if ($errors) {
        sendErrorResponse('Bitte prüfen Sie Ihre Eingaben.', 422, ['fields' => $errors]);
    }

    updateSalonColumns($conn, $salonId, $user, [
        'salon_name' => $name,
        'email'      => $email,
        'phone'      => $phone,
        'address'    => trim((string)($input['address'] ?? '')),
        'website'    => $website,
        'default_language' => in_array($input['default_language'] ?? 'de', ['de', 'en'], true)
            ? $input['default_language'] : 'de',
    ], 'Allgemein');
}

function saveTablet(mysqli $conn, int $salonId, array $user, array $input): void
{
    $errors = [];

    $bgColor = trim((string)($input['bg_color'] ?? ''));
    if ($bgColor !== '' && !preg_match('/^#[0-9a-f]{6}$/i', $bgColor)) {
        $errors['bg_color'] = 'Bitte einen Farbwert im Format #RRGGBB angeben.';
    }

    $idle = $input['idle_timeout_s'] ?? null;
    if ($idle !== null && $idle !== '') {
        $idle = (int)$idle;
        if ($idle < 5 || $idle > 600) {
            $errors['idle_timeout_s'] = 'Bitte einen Wert zwischen 5 und 600 Sekunden angeben.';
        }
    } else {
        $idle = null;   // fall back to the platform-wide default
    }

    $bgImage = trim((string)($input['bg_image'] ?? ''));
    if ($bgImage !== '' && !preg_match('#^https?://#i', $bgImage)) {
        $errors['bg_image'] = 'Bitte eine vollständige URL angeben.';
    }

    $modules = is_array($input['modules'] ?? null) ? $input['modules'] : [];
    $normalised = [
        'register' => !empty($modules['register']),
        'checkin'  => !empty($modules['checkin']),
        'browse'   => !empty($modules['browse']),
    ];

    // Turning every module off would leave the tablet with nothing to show.
    if (!array_filter($normalised)) {
        $errors['modules'] = 'Mindestens ein Modul muss aktiv bleiben.';
    }

    if ($errors) {
        sendErrorResponse('Bitte prüfen Sie Ihre Eingaben.', 422, ['fields' => $errors]);
    }

    updateSalonColumns($conn, $salonId, $user, [
        'tablet_headline'       => trim((string)($input['headline'] ?? '')),
        'tablet_bg_image'       => $bgImage,
        'tablet_bg_color'       => $bgColor,
        'tablet_idle_timeout_s' => $idle,
        'tablet_modules'        => json_encode($normalised),
    ], 'Tablet');
}

function saveMembership(mysqli $conn, int $salonId, array $user, array $input): void
{
    $value = (float)($input['referral_discount_value'] ?? 0);
    if (!empty($input['referral_enabled']) && $value <= 0) {
        sendErrorResponse('Bitte prüfen Sie Ihre Eingaben.', 422, [
            'fields' => ['referral_discount_value' => 'Bitte einen Wert größer als 0 angeben.'],
        ]);
    }

    updateSalonColumns($conn, $salonId, $user, [
        'referral_enabled'        => !empty($input['referral_enabled']) ? 1 : 0,
        'referral_discount_value' => $value,
    ], 'Mitgliedschaft');
}

function saveBirthday(mysqli $conn, int $salonId, array $user, array $input): void
{
    $enabled = !empty($input['enabled']);
    $errors = [];

    $daysBefore = (int)($input['days_before'] ?? 0);
    if ($daysBefore < 0 || $daysBefore > 60) {
        $errors['days_before'] = 'Bitte einen Wert zwischen 0 und 60 Tagen angeben.';
    }

    $subject = trim((string)($input['subject'] ?? ''));
    $body = (string)($input['body'] ?? '');

    // Only demand a template when the campaign is actually switched on.
    if ($enabled) {
        if ($subject === '') $errors['subject'] = 'Ein Betreff ist erforderlich.';
        if (trim(strip_tags($body)) === '') $errors['body'] = 'Der Inhalt darf nicht leer sein.';
    }

    if ($errors) {
        sendErrorResponse('Bitte prüfen Sie Ihre Eingaben.', 422, ['fields' => $errors]);
    }

    updateSalonColumns($conn, $salonId, $user, [
        'birthday_enabled'       => $enabled ? 1 : 0,
        'birthday_days_before'   => $daysBefore,
        'birthday_subject'       => $subject,
        'birthday_body'          => $body,
        'birthday_discount_code' => trim((string)($input['discount_code'] ?? '')),
    ], 'Geburtstagskampagne');
}

function saveHours(mysqli $conn, int $salonId, array $user, array $input): void
{
    if (!tableExists($conn, 'coiffure_salon_hours')) {
        sendErrorResponse('Opening hours need migration 018 to be applied first.', 503);
    }

    $days = is_array($input['hours'] ?? null) ? $input['hours'] : [];
    $errors = [];

    foreach ($days as $index => $day) {
        $weekday = (int)($day['weekday'] ?? $index);
        if ($weekday < 0 || $weekday > 6) {
            continue;
        }
        if (!empty($day['is_closed'])) {
            continue;
        }
        $open = trim((string)($day['open_time'] ?? ''));
        $close = trim((string)($day['close_time'] ?? ''));

        if ($open === '' || $close === '') {
            $errors["hours_$weekday"] = 'Bitte Öffnungs- und Schließzeit angeben oder den Tag als geschlossen markieren.';
        } elseif ($close <= $open) {
            $errors["hours_$weekday"] = 'Die Schließzeit muss nach der Öffnungszeit liegen.';
        }
    }

    if ($errors) {
        sendErrorResponse('Bitte prüfen Sie Ihre Eingaben.', 422, ['fields' => $errors]);
    }

    $stmt = $conn->prepare(
        'INSERT INTO coiffure_salon_hours (salon_id, weekday, is_closed, open_time, close_time)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE is_closed = VALUES(is_closed),
                                 open_time = VALUES(open_time),
                                 close_time = VALUES(close_time)'
    );
    if (!$stmt) {
        sendErrorResponse('Failed to save the opening hours.', 500);
    }

    foreach ($days as $index => $day) {
        $weekday = (int)($day['weekday'] ?? $index);
        if ($weekday < 0 || $weekday > 6) {
            continue;
        }
        $isClosed = !empty($day['is_closed']) ? 1 : 0;
        $open = $isClosed ? null : (trim((string)($day['open_time'] ?? '')) ?: null);
        $close = $isClosed ? null : (trim((string)($day['close_time'] ?? '')) ?: null);

        bindTyped($stmt, [$salonId, $weekday, $isClosed, $open, $close]);
        $stmt->execute();
    }
    $stmt->close();

    logAdminAudit($conn, $user, 'salon', $salonId, 'update', 'Opening hours updated', $salonId);

    sendJsonResponse(['success' => true], 200);
}

/* ============================================================
   Guards
   ============================================================ */

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
