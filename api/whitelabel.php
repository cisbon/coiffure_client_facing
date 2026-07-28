<?php
/**
 * White-label configuration (Administrator only)
 * -------------------------------------------------------------------
 *   GET  whitelabel.php?salon_id=N        → this salon's configuration
 *   GET  whitelabel.php?action=list       → every salon, configured or not
 *   POST whitelabel.php?salon_id=N        → save
 *   POST whitelabel.php?action=test_email → send a test through the salon's SMTP
 *
 * Guarded by platform_config: white-labelling changes the domain a salon is
 * reachable at and the server its mail leaves from, so it is not something a
 * salon configures for itself.
 *
 * smtp_password is write-only. It is accepted on save, never returned on read,
 * and never written to the audit trail -- the API only ever reports whether one
 * is set. Sending an empty password field keeps the stored one, so saving an
 * unrelated field cannot silently wipe the credentials.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/mailer.php';

/**
 * Declared before the dispatch below: a top-level `const` is evaluated in
 * source order, so one placed after it would not exist yet when a handler runs.
 */
const SMTP_MODES = ['none', 'tls', 'ssl'];

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
requirePermission($conn, $user, 'platform_config');

if (!whitelabelReady($conn)) {
    sendErrorResponse('White-label needs migration 023 to be applied first.', 503);
}

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'list') {
        handleList($conn);
    }
    handleGet($conn, requireSalonId($_GET['salon_id'] ?? null));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'test_email') {
        handleTestEmail($conn, $user, $input);
    }
    handleSave($conn, $user, requireSalonId($_GET['salon_id'] ?? ($input['salon_id'] ?? null)), $input);
}

sendErrorResponse('Method not allowed.', 405);

function whitelabelReady(mysqli $conn): bool
{
    $res = $conn->query("SHOW TABLES LIKE 'coiffure_salon_whitelabel'");
    return $res && $res->num_rows > 0;
}

/**
 * A platform admin reaches every salon, so there is no scope resolution here --
 * but the salon still has to exist.
 */
function requireSalonId($value): int
{
    $salonId = (int)$value;
    if ($salonId <= 0) {
        sendErrorResponse('salon_id is required.', 400);
    }
    return $salonId;
}

function handleList(mysqli $conn): void
{
    $result = $conn->query(
        'SELECT s.salon_id, s.salon_name,
                w.custom_domain, w.domain_verified, w.smtp_host, w.from_address,
                w.primary_color, w.secondary_color, w.last_test_at, w.last_test_ok
         FROM coiffure_salons s
         LEFT JOIN coiffure_salon_whitelabel w ON w.salon_id = s.salon_id
         ORDER BY s.salon_name'
    );
    if (!$result) {
        sendErrorResponse('Failed to load the white-label configuration.', 500);
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'salon_id'   => (int)$row['salon_id'],
            'salon_name' => $row['salon_name'],
            'custom_domain'   => $row['custom_domain'],
            'domain_verified' => (bool)$row['domain_verified'],
            'has_smtp'   => !empty($row['smtp_host']),
            'from_address'    => $row['from_address'],
            'primary_color'   => $row['primary_color'],
            'secondary_color' => $row['secondary_color'],
            'last_test_at' => $row['last_test_at'],
            'last_test_ok' => $row['last_test_ok'] === null ? null : (bool)$row['last_test_ok'],
            'configured' => !empty($row['custom_domain']) || !empty($row['smtp_host'])
                            || !empty($row['primary_color']),
        ];
    }

    sendJsonResponse(['success' => true, 'salons' => $rows], 200);
}

function loadConfig(mysqli $conn, int $salonId): ?array
{
    $stmt = $conn->prepare('SELECT * FROM coiffure_salon_whitelabel WHERE salon_id = ?');
    if (!$stmt) {
        sendErrorResponse('Failed to load the configuration.', 500);
    }
    $stmt->bind_param('i', $salonId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function handleGet(mysqli $conn, int $salonId): void
{
    $salonStmt = $conn->prepare('SELECT salon_id, salon_name FROM coiffure_salons WHERE salon_id = ?');
    if (!$salonStmt) {
        sendErrorResponse('Failed to load the salon.', 500);
    }
    $salonStmt->bind_param('i', $salonId);
    $salonStmt->execute();
    $salon = $salonStmt->get_result()->fetch_assoc();
    $salonStmt->close();

    if (!$salon) {
        sendErrorResponse('Salon not found.', 404);
    }

    $row = loadConfig($conn, $salonId);

    sendJsonResponse([
        'success' => true,
        'salon' => ['salon_id' => (int)$salon['salon_id'], 'salon_name' => $salon['salon_name']],
        'whitelabel' => [
            'custom_domain'   => $row['custom_domain'] ?? null,
            'domain_verified' => (bool)($row['domain_verified'] ?? false),
            'smtp_host'     => $row['smtp_host'] ?? null,
            'smtp_port'     => isset($row['smtp_port']) ? (int)$row['smtp_port'] : 587,
            'smtp_secure'   => $row['smtp_secure'] ?? 'tls',
            'smtp_username' => $row['smtp_username'] ?? null,
            // Write-only: the dashboard shows "gesetzt", never the value.
            'smtp_password_set' => !empty($row['smtp_password']),
            'from_address'  => $row['from_address'] ?? null,
            'from_name'     => $row['from_name'] ?? null,
            'primary_color'   => $row['primary_color'] ?? null,
            'secondary_color' => $row['secondary_color'] ?? null,
            'last_test_at' => $row['last_test_at'] ?? null,
            'last_test_ok' => isset($row['last_test_ok']) && $row['last_test_ok'] !== null
                ? (bool)$row['last_test_ok'] : null,
        ],
    ], 200);
}

function handleSave(mysqli $conn, array $user, int $salonId, array $input): void
{
    $errors = [];

    $domain = strtolower(trim((string)($input['custom_domain'] ?? '')));
    if ($domain !== '') {
        // Hostname only: a scheme or a path here would produce links that do
        // not resolve, and the value is used to build URLs.
        if (!preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            $errors['custom_domain'] = 'Bitte eine Domain ohne https:// und ohne Pfad angeben.';
        } elseif (domainTaken($conn, $domain, $salonId)) {
            $errors['custom_domain'] = 'Diese Domain ist bereits einem anderen Salon zugeordnet.';
        }
    }

    $smtpHost = trim((string)($input['smtp_host'] ?? ''));
    $smtpPort = (int)($input['smtp_port'] ?? 587);
    if ($smtpHost !== '' && ($smtpPort < 1 || $smtpPort > 65535)) {
        $errors['smtp_port'] = 'Bitte einen Port zwischen 1 und 65535 angeben.';
    }

    $smtpSecure = (string)($input['smtp_secure'] ?? 'tls');
    if (!in_array($smtpSecure, SMTP_MODES, true)) {
        $errors['smtp_secure'] = 'Unbekannte Verschlüsselung.';
    }

    $fromAddress = trim((string)($input['from_address'] ?? ''));
    if ($fromAddress !== '' && !validateEmail($fromAddress)) {
        $errors['from_address'] = 'Bitte eine gültige E-Mail-Adresse angeben.';
    }

    foreach (['primary_color', 'secondary_color'] as $field) {
        $value = trim((string)($input[$field] ?? ''));
        if ($value !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            $errors[$field] = 'Bitte eine Farbe im Format #RRGGBB angeben.';
        }
    }

    if ($errors) {
        sendErrorResponse('Bitte prüfen Sie Ihre Eingaben.', 422, ['fields' => $errors]);
    }

    $existing = loadConfig($conn, $salonId);

    // An empty password field means "leave it as it is", so that editing the
    // from-address does not wipe working credentials.
    $password = (string)($input['smtp_password'] ?? '');
    if ($password === '') {
        $password = $existing['smtp_password'] ?? null;
    }

    // Changing the domain invalidates any previous verification.
    $verified = (int)($existing['domain_verified'] ?? 0);
    if (($existing['custom_domain'] ?? null) !== ($domain ?: null)) {
        $verified = 0;
    }

    $updatedBy = (int)$user['user_id'];
    $args = [
        $salonId,
        $domain ?: null,
        $verified,
        $smtpHost ?: null,
        $smtpPort,
        $smtpSecure,
        trim((string)($input['smtp_username'] ?? '')) ?: null,
        $password,
        $fromAddress ?: null,
        trim((string)($input['from_name'] ?? '')) ?: null,
        trim((string)($input['primary_color'] ?? '')) ?: null,
        trim((string)($input['secondary_color'] ?? '')) ?: null,
        $updatedBy,
    ];

    $stmt = $conn->prepare(
        'INSERT INTO coiffure_salon_whitelabel
            (salon_id, custom_domain, domain_verified, smtp_host, smtp_port, smtp_secure,
             smtp_username, smtp_password, from_address, from_name,
             primary_color, secondary_color, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            custom_domain = VALUES(custom_domain), domain_verified = VALUES(domain_verified),
            smtp_host = VALUES(smtp_host), smtp_port = VALUES(smtp_port),
            smtp_secure = VALUES(smtp_secure), smtp_username = VALUES(smtp_username),
            smtp_password = VALUES(smtp_password), from_address = VALUES(from_address),
            from_name = VALUES(from_name), primary_color = VALUES(primary_color),
            secondary_color = VALUES(secondary_color), updated_by = VALUES(updated_by)'
    );
    if (!$stmt) {
        sendErrorResponse('Die Konfiguration konnte nicht gespeichert werden.', 500);
    }
    bindTyped($stmt, $args);
    if (!$stmt->execute()) {
        $stmt->close();
        sendErrorResponse('Die Konfiguration konnte nicht gespeichert werden.', 500);
    }
    $stmt->close();

    // Deliberately describes what changed without naming any credential.
    logAdminAudit(
        $conn, $user, 'salon', $salonId, 'whitelabel_changed',
        'White-label updated: domain=' . ($domain ?: '—') . ', smtp=' . ($smtpHost ?: '—'),
        $salonId
    );

    handleGet($conn, $salonId);
}

function domainTaken(mysqli $conn, string $domain, int $salonId): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM coiffure_salon_whitelabel WHERE custom_domain = ? AND salon_id <> ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('si', $domain, $salonId);
    $stmt->execute();
    $taken = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $taken;
}

/**
 * Send a test message through whatever this salon's configuration resolves to.
 *
 * Uses the same salonMailConfig() path as a real campaign, so a passing test
 * means campaigns will actually leave -- testing a separate code path would
 * prove nothing.
 */
function handleTestEmail(mysqli $conn, array $user, array $input): void
{
    $salonId = requireSalonId($_GET['salon_id'] ?? ($input['salon_id'] ?? null));

    $recipient = trim((string)($input['email'] ?? ($user['email'] ?? '')));
    if ($recipient === '' || !validateEmail($recipient)) {
        sendErrorResponse('Bitte prüfen Sie Ihre Eingaben.', 422, [
            'fields' => ['email' => 'Bitte eine gültige E-Mail-Adresse angeben.'],
        ]);
    }

    $salonStmt = $conn->prepare('SELECT * FROM coiffure_salons WHERE salon_id = ?');
    if (!$salonStmt) {
        sendErrorResponse('Failed to load the salon.', 500);
    }
    $salonStmt->bind_param('i', $salonId);
    $salonStmt->execute();
    $salon = $salonStmt->get_result()->fetch_assoc();
    $salonStmt->close();

    if (!$salon) {
        sendErrorResponse('Salon not found.', 404);
    }

    $config = salonMailConfig($conn, $salon);
    $subject = 'Testnachricht – ' . ($salon['salon_name'] ?? 'Coiffure Digital');

    // Wrapped in the salon's own branded shell, so the test also shows what a
    // campaign from this salon will look like.
    $body = '<p>Diese Nachricht bestätigt, dass der E-Mail-Versand für <strong>'
        . htmlspecialchars((string)$salon['salon_name'], ENT_QUOTES, 'UTF-8')
        . '</strong> funktioniert.</p>'
        . '<p style="color:#6B7280;font-size:13px">Gesendet über: '
        . htmlspecialchars($config['smtp'] ? (string)$config['smtp']['host'] : 'Standardversand', ENT_QUOTES, 'UTF-8')
        . '</p>';
    $html = buildCampaignEmailHtml($salon, $body);

    $ok = false;
    try {
        $ok = _sendHtmlMail(
            $recipient, $subject, $html,
            $config['from_email'], $config['from_name'], $config['smtp']
        );
    } catch (Throwable $e) {
        error_log('whitelabel test_email: ' . $e->getMessage());
    }

    // Record the outcome so the screen can show when it was last checked.
    $update = $conn->prepare(
        'UPDATE coiffure_salon_whitelabel SET last_test_at = NOW(), last_test_ok = ? WHERE salon_id = ?'
    );
    if ($update) {
        $flag = $ok ? 1 : 0;
        $update->bind_param('ii', $flag, $salonId);
        $update->execute();
        $update->close();
    }

    logAdminAudit($conn, $user, 'salon', $salonId, 'whitelabel_test',
        'Test mail to ' . $recipient . ': ' . ($ok ? 'ok' : 'failed'), $salonId);

    sendJsonResponse([
        'success' => true,
        'sent' => $ok,
        'via'  => $config['smtp'] ? $config['smtp']['host'] : null,
        'from' => $config['from_email'],
    ], 200);
}
