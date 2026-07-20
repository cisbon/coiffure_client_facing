<?php
/**
 * Wallet delivery endpoint  ->  GET /wallet/{memberId}
 * -------------------------------------------------------------------
 * Serves the digital membership card. Behaviour depends on the client:
 *
 *   - iOS / macOS (Apple Wallet):  streams the signed .pkpass file
 *     (application/vnd.apple.pkpass) so Wallet opens it directly.
 *   - Android with a configured Google Wallet issuer:  302-redirects to
 *     the "Save to Google Wallet" link.
 *   - Everything else / desktop:  a small branded HTML landing page with
 *     both "Add to Apple Wallet" and "Add to Google Wallet" buttons.
 *
 * Member ID comes from either PATH_INFO (/wallet.php/M25-XXXX via the
 * rewrite) or the ?member_id= query parameter.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/WalletGenerator.php';

// Resolve member ID from path or query.
$memberId = $_GET['member_id'] ?? null;
if (!$memberId && !empty($_SERVER['PATH_INFO'])) {
    $memberId = trim($_SERVER['PATH_INFO'], '/');
}
$memberId = $memberId ? preg_replace('/[^A-Za-z0-9_\-]/', '', $memberId) : null;

if (!$memberId) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Missing member ID';
    exit;
}

$conn = getDbConnection();
if (!$conn) {
    http_response_code(500);
    echo 'Database connection failed';
    exit;
}

// Look up the member + their salon branding.
$stmt = $conn->prepare(
    "SELECT c.customer_id, c.first_name, c.last_name, c.full_name, c.member_id,
            c.member_since, c.is_member,
            s.salon_id, s.salon_name
     FROM coiffure_customers c
     JOIN coiffure_salons s ON c.salon_id = s.salon_id
     WHERE c.member_id = ? AND c.is_deleted = 0 LIMIT 1"
);
$stmt->bind_param("s", $memberId);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    $stmt->close();
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mitgliedskarte nicht gefunden.';
    exit;
}
$member = $res->fetch_assoc();
$stmt->close();

// Load branding colours + logo (tolerate missing branding columns).
$branding = ['primary_color' => '#9333EA', 'secondary_color' => '#EC4899', 'logo_path' => null];
$check = $conn->query("SHOW COLUMNS FROM coiffure_salons LIKE 'primary_color'");
if ($check && $check->num_rows > 0) {
    $bStmt = $conn->prepare(
        "SELECT primary_color, secondary_color, logo_path FROM coiffure_salons WHERE salon_id = ?"
    );
    $bStmt->bind_param("i", $member['salon_id']);
    $bStmt->execute();
    $bRes = $bStmt->get_result();
    if ($bRes->num_rows > 0) {
        $branding = array_merge($branding, $bRes->fetch_assoc());
    }
    $bStmt->close();
}
$conn->close();

$publicBase = rtrim(getenv('APP_PUBLIC_URL') ?: 'https://clouedo.com/coiffure', '/');
$logoFsPath = null;
$logoUrl = null;
if (!empty($branding['logo_path'])) {
    $logoFsPath = __DIR__ . '/../' . ltrim($branding['logo_path'], '/');
    $logoUrl = $publicBase . '/' . ltrim($branding['logo_path'], '/');
}

$memberData = [
    'member_id'     => $member['member_id'],
    'first_name'    => $member['first_name'],
    'last_name'     => $member['last_name'],
    'full_name'     => $member['full_name'],
    'member_since'  => $member['member_since'],
    'salon_name'    => $member['salon_name'],
    'logo_path'     => $logoFsPath,
    'primary_color' => $branding['primary_color'],
];

$generator = new WalletGenerator();
$ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
$isApple   = (bool)preg_match('/iphone|ipad|ipod|macintosh|mac os x/', $ua);
$isAndroid = strpos($ua, 'android') !== false;

$googleLink = $generator->createGooglePassLink($memberData);

// ------- Apple: stream the .pkpass -------
$deliver = $_GET['platform'] ?? null; // allow explicit ?platform=apple|google
if ($deliver === 'apple' || ($deliver === null && $isApple)) {
    $passPath = $generator->createApplePass($memberData);
    if ($passPath && is_file($passPath)) {
        header('Content-Type: application/vnd.apple.pkpass');
        header('Content-Disposition: attachment; filename="treuekarte.pkpass"');
        header('Content-Length: ' . filesize($passPath));
        readfile($passPath);
        exit;
    }
    // Fall through to landing page if generation failed.
}

// ------- Android: redirect to Google Wallet save link -------
if (($deliver === 'google' || ($deliver === null && $isAndroid)) && $googleLink) {
    header('Location: ' . $googleLink, true, 302);
    exit;
}

// ------- Fallback: branded landing page with both buttons -------
$primary   = preg_match('/^#[0-9A-Fa-f]{6}$/', $branding['primary_color']) ? $branding['primary_color'] : '#9333EA';
$secondary = preg_match('/^#[0-9A-Fa-f]{6}$/', $branding['secondary_color']) ? $branding['secondary_color'] : '#EC4899';
$salonName = htmlspecialchars($member['salon_name'], ENT_QUOTES, 'UTF-8');
$name      = htmlspecialchars(trim($member['first_name'] . ' ' . $member['last_name']), ENT_QUOTES, 'UTF-8');
$mid       = htmlspecialchars($member['member_id'], ENT_QUOTES, 'UTF-8');
$since     = $member['member_since'] ? date('d.m.Y', strtotime($member['member_since'])) : '';
$selfUrl   = htmlspecialchars(strtok($_SERVER['REQUEST_URI'] ?? '', '?'), ENT_QUOTES, 'UTF-8');
$appleUrl  = $selfUrl . '?member_id=' . rawurlencode($member['member_id']) . '&platform=apple';
$logoImg   = $logoUrl
    ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES) . '" alt="' . $salonName . '" style="max-height:56px;">'
    : '<div style="font-size:24px;font-weight:800;color:#fff;">' . $salonName . '</div>';

$googleBtn = $googleLink
    ? '<a class="btn google" href="' . htmlspecialchars($googleLink, ENT_QUOTES) . '">Zu Google Wallet hinzufügen</a>'
    : '';

header('Content-Type: text/html; charset=utf-8');
echo <<<HTML
<!DOCTYPE html>
<html lang="de"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Treuekarte – {$salonName}</title>
<style>
  * { box-sizing: border-box; }
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
         background:#f3f4f6; color:#1f2937; }
  .wrap { max-width:480px; margin:0 auto; padding:24px 16px; }
  .card { background:#fff; border-radius:20px; overflow:hidden; box-shadow:0 8px 30px rgba(0,0,0,.08); }
  .head { background:linear-gradient(135deg,{$primary},{$secondary}); padding:28px; text-align:center; }
  .body { padding:24px; text-align:center; }
  h1 { font-size:20px; margin:0 0 4px; color:{$primary}; }
  .muted { color:#6b7280; font-size:13px; }
  .pill { display:inline-block; background:#faf5ff; color:{$primary}; font-weight:700;
          padding:8px 14px; border-radius:999px; margin:12px 0; }
  .btn { display:block; text-decoration:none; font-weight:700; font-size:16px;
         padding:15px; border-radius:12px; margin:12px 0; }
  .btn.apple { background:#000; color:#fff; }
  .btn.google { background:{$primary}; color:#fff; }
</style></head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="head">{$logoImg}</div>
      <div class="body">
        <h1>Ihre digitale Treuekarte</h1>
        <p class="muted">{$name}</p>
        <div class="pill">Mitgliedsnummer: {$mid}</div>
        <p class="muted">Mitglied seit {$since}</p>
        <a class="btn apple" href="{$appleUrl}">Zu Apple Wallet hinzufügen</a>
        {$googleBtn}
        <p class="muted" style="margin-top:16px;">
          Öffnen Sie diese Seite auf Ihrem Smartphone, um die Karte zu Ihrer Wallet hinzuzufügen.
        </p>
      </div>
    </div>
  </div>
</body></html>
HTML;
