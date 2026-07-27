<?php
/**
 * Mailer
 * -------------------------------------------------------------------
 * Sends the branded welcome / membership e-mail after registration.
 * Uses PHP mail() by default; if SMTP_* env vars are configured a very
 * small SMTP sender is used instead (no external dependency).
 *
 * Two variants:
 *   - Membership: welcome to the club + benefits (membership status only,
 *     no downloadable card / wallet pass)
 *   - Plain:      simple welcome / confirmation
 */

/**
 * Send the welcome e-mail.
 *
 * @param array $data Keys:
 *   to_email, first_name, salon_name,
 *   is_member (bool), member_id (string|null),
 *   member_since (string|null), primary_color, secondary_color,
 *   logo_url (public https URL, optional)
 * @return bool
 */
function sendWelcomeEmail(array $data)
{
    $to        = $data['to_email'];
    $firstName = $data['first_name'] ?: 'Kundin/Kunde';
    $salonName = $data['salon_name'] ?: 'unser Salon';
    $isMember  = !empty($data['is_member']);

    $subject = $isMember
        ? "Willkommen im {$salonName} Club"
        : "Willkommen bei {$salonName}";

    $html = $isMember
        ? buildMembershipEmailHtml($data)
        : buildPlainWelcomeEmailHtml($data);

    $fromEmail = getenv('MAIL_FROM') ?: ('noreply@' . _mailDomain());
    $fromName  = $salonName;

    return _sendHtmlMail($to, $subject, $html, $fromEmail, $fromName);
}

// =====================================================================
// Templates
// =====================================================================

function buildMembershipEmailHtml(array $d)
{
    $primary   = _hex($d['primary_color'] ?? '#9333EA');
    $secondary = _hex($d['secondary_color'] ?? '#EC4899');
    $firstName = _h($d['first_name'] ?: '');
    $salonName = _h($d['salon_name'] ?: 'unser Salon');
    $memberId  = _h($d['member_id'] ?? '');
    $since     = _h(_fmtDate($d['member_since'] ?? date('Y-m-d')));
    $logo      = _logoTag($d['logo_url'] ?? null, $salonName);

    // Loyalty copy is per-salon (falls back to the historical 10 €/5th visit).
    $loyLabel     = _h($d['loyalty_label'] ?? '10 €');
    $loyThreshold = (int)($d['loyalty_threshold'] ?? 5);
    $loyActive    = !array_key_exists('loyalty_active', $d) || !empty($d['loyalty_active']);
    $loyaltyBenefit = $loyActive
        ? "<p style=\"margin:0 0 6px 0;font-size:14px;\">🎁 {$loyLabel} Rabatt auf den {$loyThreshold}. Besuch</p>"
          . "<p style=\"margin:0 0 6px 0;font-size:14px;\">👥 Freunde werben lohnt sich – Sie beide erhalten {$loyLabel} Rabatt</p>"
        : '';

    $memberLine = $memberId !== ''
        ? "<tr><td style=\"padding:0 32px 8px 32px;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#6b7280;\">"
          . "Mitgliedsnummer: <strong>{$memberId}</strong> &nbsp;·&nbsp; Mitglied seit: <strong>{$since}</strong></td></tr>"
        : '';

    $header = _emailHeader($primary, $secondary, $logo, $salonName);
    $footer = _emailFooter($salonName);

    return $header . <<<HTML
    <tr><td style="padding:32px 32px 8px 32px;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
        <h1 style="margin:0 0 8px 0;font-size:22px;color:{$primary};">Willkommen im {$salonName} Club, {$firstName}! 🎉</h1>
        <p style="margin:0 0 4px 0;font-size:15px;line-height:1.6;">
            Schön, dass Sie dabei sind. Als Mitglied profitieren Sie ab sofort von allen Vorteilen –
            wir freuen uns auf Ihren nächsten Besuch.
        </p>
    </td></tr>

    {$memberLine}

    <tr><td style="padding:8px 32px 16px 32px;">
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#faf5ff;border-radius:12px;">
            <tr><td style="padding:18px 20px;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
                <p style="margin:0 0 10px 0;font-weight:bold;color:{$primary};">Ihre Vorteile</p>
                {$loyaltyBenefit}
                <p style="margin:0;font-size:14px;">✨ Exklusive Angebote &amp; Geburtstags-Überraschungen</p>
            </td></tr>
        </table>
    </td></tr>

    <tr><td style="padding:0 32px 24px 32px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#6b7280;line-height:1.6;">
        Wir freuen uns auf Ihren nächsten Besuch bei {$salonName}.
    </td></tr>
HTML
    . $footer;
}

function buildPlainWelcomeEmailHtml(array $d)
{
    $primary   = _hex($d['primary_color'] ?? '#9333EA');
    $secondary = _hex($d['secondary_color'] ?? '#EC4899');
    $firstName = _h($d['first_name'] ?: '');
    $salonName = _h($d['salon_name'] ?: 'unser Salon');
    $logo      = _logoTag($d['logo_url'] ?? null, $salonName);

    $loyLabel     = _h($d['loyalty_label'] ?? '10 €');
    $loyThreshold = (int)($d['loyalty_threshold'] ?? 5);
    $loyActive    = !array_key_exists('loyalty_active', $d) || !empty($d['loyalty_active']);
    $loyaltyNote  = $loyActive
        ? "<p style=\"margin:0;font-size:14px;line-height:1.6;color:#6b7280;\">"
          . "Übrigens: Als Mitglied in unserem Club erhalten Sie {$loyLabel} Rabatt auf jeden {$loyThreshold}. Besuch, "
          . "exklusive Angebote und Geburtstags-Überraschungen. Fragen Sie einfach beim nächsten Besuch nach.</p>"
        : '';

    $header = _emailHeader($primary, $secondary, $logo, $salonName);
    $footer = _emailFooter($salonName);

    return $header . <<<HTML
    <tr><td style="padding:32px;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
        <h1 style="margin:0 0 8px 0;font-size:22px;color:{$primary};">Hallo {$firstName}, willkommen!</h1>
        <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;">
            Vielen Dank für Ihre Registrierung bei <strong>{$salonName}</strong>. Wir freuen uns,
            Sie bald bei uns begrüßen zu dürfen.
        </p>
        {$loyaltyNote}
    </td></tr>
HTML
    . $footer;
}

// =====================================================================
// Layout helpers
// =====================================================================

function _emailHeader($primary, $secondary, $logoTag, $salonName)
{
    return <<<HTML
<!DOCTYPE html>
<html lang="de"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f3f4f6;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;">
    <tr><td style="background:linear-gradient(135deg,{$primary},{$secondary});padding:28px 32px;text-align:center;">
        {$logoTag}
    </td></tr>
HTML;
}

function _emailFooter($salonName)
{
    $salonName = _h($salonName);
    $year = date('Y');
    return <<<HTML
    <tr><td style="background:#111827;padding:20px 32px;text-align:center;font-family:Arial,Helvetica,sans-serif;">
        <p style="margin:0;font-size:12px;color:#9ca3af;">© {$year} {$salonName}</p>
        <p style="margin:6px 0 0 0;font-size:11px;color:#6b7280;">
            Diese E-Mail wurde aufgrund Ihrer Registrierung gesendet.
        </p>
    </td></tr>
</table>
</td></tr>
</table>
</body></html>
HTML;
}

function _logoTag($logoUrl, $salonName)
{
    $salonName = _h($salonName);
    if ($logoUrl) {
        $logoUrl = _h($logoUrl);
        return "<img src=\"{$logoUrl}\" alt=\"{$salonName}\" height=\"48\" style=\"max-height:48px;\">";
    }
    return "<span style=\"font-family:Arial,Helvetica,sans-serif;font-size:22px;font-weight:bold;color:#ffffff;\">{$salonName}</span>";
}

// =====================================================================
// Transport
// =====================================================================

/**
 * @param array|null $smtpConfig Optional per-salon SMTP override (white-label).
 *   Keys: host, port, secure, username, password. When null the SMTP_* env
 *   defaults are used, which is exactly the previous behaviour.
 */
function _sendHtmlMail($to, $subject, $html, $fromEmail, $fromName, ?array $smtpConfig = null)
{
    // Encode subject for non-ASCII (German umlauts).
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFrom    = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>';

    // SMTP path: a salon's own server when white-label configured one,
    // otherwise the platform default from the environment.
    if (!empty($smtpConfig['host'])) {
        return _sendViaSmtp($to, $encodedSubject, $html, $fromEmail, $encodedFrom, $smtpConfig);
    }
    if (getenv('SMTP_HOST')) {
        return _sendViaSmtp($to, $encodedSubject, $html, $fromEmail, $encodedFrom);
    }

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $headers[] = 'From: ' . $encodedFrom;
    $headers[] = 'Reply-To: ' . $fromEmail;

    $ok = @mail($to, $encodedSubject, $html, implode("\r\n", $headers));
    if (!$ok) {
        error_log("mailer: mail() failed for {$to}");
    }
    return $ok;
}

/**
 * Minimal SMTP sender (STARTTLS/SSL) — no external library required.
 * $config, when given, overrides the SMTP_* environment defaults so a salon can
 * send through its own server (see api/whitelabel.php).
 */
function _sendViaSmtp($to, $encodedSubject, $html, $fromEmail, $encodedFrom, ?array $config = null)
{
    $host   = $config['host']     ?? getenv('SMTP_HOST');
    $port   = (int)($config['port'] ?? (getenv('SMTP_PORT') ?: 587));
    $user   = $config['username'] ?? getenv('SMTP_USERNAME');
    $pass   = $config['password'] ?? getenv('SMTP_PASSWORD');
    $secure = strtolower($config['secure'] ?? (getenv('SMTP_SECURE') ?: 'tls')); // tls | ssl | none

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 15);
    if (!$fp) {
        error_log("mailer SMTP connect failed: $errstr ($errno)");
        return false;
    }

    stream_set_timeout($fp, 20);

    $read = function () use ($fp) { return fgets($fp, 1024); };

    /** Write everything, looping because a socket may accept a partial write. */
    $write = function ($data) use ($fp) {
        $total = strlen($data);
        $sent = 0;
        while ($sent < $total) {
            $bytes = @fwrite($fp, substr($data, $sent));
            if ($bytes === false || $bytes === 0) {
                return false;
            }
            $sent += $bytes;
        }
        return true;
    };

    $cmd = function ($c) use ($write) { return $write($c . "\r\n"); };

    /** Send a command and require the reply to start with an expected code. */
    $expect = function ($command, array $codes) use ($cmd, $read, $fp) {
        if ($command !== null && !$cmd($command)) {
            error_log('mailer SMTP: write failed for: ' . substr((string)$command, 0, 40));
            return false;
        }
        // Consume a multi-line reply; the last line has a space at offset 3.
        $line = $read();
        while ($line !== false && isset($line[3]) && $line[3] === '-') {
            $line = $read();
        }
        if ($line === false) {
            error_log('mailer SMTP: no reply to: ' . substr((string)$command, 0, 40));
            return false;
        }
        $code = (int)substr(ltrim($line), 0, 3);
        if (!in_array($code, $codes, true)) {
            error_log('mailer SMTP: unexpected reply "' . trim($line) . '" to: ' . substr((string)$command, 0, 40));
            return false;
        }
        return true;
    };

    $fail = function () use ($fp) {
        @fclose($fp);
        return false;
    };

    // Greeting
    if (!$expect(null, [220])) return $fail();
    if (!$expect('EHLO ' . _mailDomain(), [250])) return $fail();

    if ($secure === 'tls') {
        if (!$expect('STARTTLS', [220])) return $fail();
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log('mailer SMTP STARTTLS failed');
            return $fail();
        }
        if (!$expect('EHLO ' . _mailDomain(), [250])) return $fail();
    }

    if ($user) {
        if (!$expect('AUTH LOGIN', [334])) return $fail();
        if (!$expect(base64_encode($user), [334])) return $fail();
        if (!$expect(base64_encode((string)$pass), [235])) return $fail();
    }

    if (!$expect('MAIL FROM:<' . $fromEmail . '>', [250])) return $fail();
    if (!$expect('RCPT TO:<' . $to . '>', [250, 251])) return $fail();
    if (!$expect('DATA', [354])) return $fail();

    $headers  = "From: {$encodedFrom}\r\n";
    $headers .= "To: {$to}\r\n";
    $headers .= "Subject: {$encodedSubject}\r\n";
    $headers .= 'Date: ' . date('r') . "\r\n";
    $headers .= 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . _mailDomain() . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";

    // The templates are written with bare "\n". SMTP only recognises CRLF as a
    // line break, so without this the whole body counts as one line and any
    // mail longer than 1000 characters is refused with "line too long" -- while
    // the old code reported success regardless. quoted_printable_encode also
    // soft-wraps at 76 characters, which keeps every line well inside the limit.
    $body = quoted_printable_encode(str_replace(["\r\n", "\r", "\n"], "\n", $html));
    $body = str_replace("\n", "\r\n", $body);

    // RFC 5321 4.5.2: a line starting with "." must be escaped so it is not
    // mistaken for the end-of-data marker.
    $body = preg_replace('/^\./m', '..', $body);

    if (!$write($headers . $body . "\r\n.\r\n")) {
        error_log('mailer SMTP: writing the message body failed');
        return $fail();
    }
    if (!$expect(null, [250])) return $fail();

    $cmd('QUIT');
    fclose($fp);
    return true;
}

function _drain($fp)
{
    stream_set_timeout($fp, 2);
    while ($line = fgets($fp, 512)) {
        if (isset($line[3]) && $line[3] === ' ') break;
    }
}

function _mailDomain()
{
    $host = getenv('MAIL_DOMAIN') ?: ($_SERVER['SERVER_NAME'] ?? 'localhost');
    return $host;
}

function _h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function _hex($s)
{
    return preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$s) ? $s : '#9333EA';
}

function _fmtDate($ymd)
{
    $ts = strtotime($ymd);
    return $ts ? date('d.m.Y', $ts) : $ymd;
}

// =====================================================================
// Campaign & invitation mail (admin dashboard)
// =====================================================================

/**
 * Replace the placeholder tokens a salon can use in a campaign body.
 *
 * Supported: {vorname} {nachname} {name} {salonname} {rabattcode}
 * Unknown tokens are left untouched rather than blanked, so a typo is visible
 * in the preview instead of silently producing an empty gap in the sent mail.
 */
function renderTemplate(string $body, array $tokens): string
{
    $replacements = [];
    foreach ($tokens as $key => $value) {
        $replacements['{' . $key . '}'] = (string)$value;
    }
    return strtr($body, $replacements);
}

/**
 * Per-salon sender configuration, honouring white-label when it is set up.
 *
 * @return array{from_email:string, from_name:string, smtp:?array}
 */
function salonMailConfig(mysqli $conn, array $salon): array
{
    $fromEmail = getenv('MAIL_FROM') ?: ('noreply@' . _mailDomain());
    $fromName  = $salon['salon_name'] ?? 'Coiffure Digital';
    $smtp = null;

    $check = $conn->query("SHOW TABLES LIKE 'coiffure_salon_whitelabel'");
    if ($check && $check->num_rows > 0) {
        $stmt = $conn->prepare(
            'SELECT smtp_host, smtp_port, smtp_secure, smtp_username, smtp_password,
                    from_address, from_name
             FROM coiffure_salon_whitelabel WHERE salon_id = ?'
        );
        if ($stmt) {
            $stmt->bind_param('i', $salon['salon_id']);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                if (!empty($row['from_address'])) {
                    $fromEmail = $row['from_address'];
                }
                if (!empty($row['from_name'])) {
                    $fromName = $row['from_name'];
                }
                if (!empty($row['smtp_host'])) {
                    $smtp = [
                        'host'     => $row['smtp_host'],
                        'port'     => (int)($row['smtp_port'] ?: 587),
                        'secure'   => $row['smtp_secure'] ?: 'tls',
                        'username' => $row['smtp_username'],
                        'password' => $row['smtp_password'],
                    ];
                }
            }
        }
    }

    return ['from_email' => $fromEmail, 'from_name' => $fromName, 'smtp' => $smtp];
}

/**
 * Wrap a campaign body in the salon's branded shell.
 *
 * Reuses _emailHeader()/_emailFooter() so campaign mail looks like the welcome
 * mail the customer already received. The body is salon-authored HTML from the
 * editor and is inserted as-is; it is written by the salon for its own
 * customers, never by an end user.
 */
function buildCampaignEmailHtml(array $salon, string $bodyHtml, ?string $discountCode = null): string
{
    $primary = _hex($salon['primary_color'] ?? '#2563EB');
    $secondary = _hex($salon['secondary_color'] ?? '#0EA5E9');
    $salonName = $salon['salon_name'] ?? '';

    $logoUrl = $salon['logo_path'] ?? null;
    $header = _emailHeader($primary, $secondary, _logoTag($logoUrl, $salonName), $salonName);
    $footer = _emailFooter($salonName);

    $discountBlock = '';
    if ($discountCode) {
        $discountBlock = '
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0">
              <tr><td align="center">
                <div style="display:inline-block;padding:16px 28px;border:2px dashed ' . $primary . ';border-radius:10px;background:#f8fafc">
                  <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#64748b;margin-bottom:6px">
                    Ihr Rabattcode
                  </div>
                  <div style="font-size:24px;font-weight:700;letter-spacing:.06em;color:' . $primary . '">
                    ' . _h($discountCode) . '
                  </div>
                </div>
              </td></tr>
            </table>';
    }

    return $header
        . '<tr><td style="padding:8px 28px 0;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1f2937">'
        . $bodyHtml
        . $discountBlock
        . '</td></tr>'
        . $footer;
}

/**
 * Send one campaign mail.
 *
 * @param array $salon     salon row (name, colours, logo_path)
 * @param array $customer  customer row (email, first_name, full_name)
 * @param array $campaign  subject + body, with placeholders still in them
 * @return bool
 */
function sendCampaignEmail(mysqli $conn, array $salon, array $customer, array $campaign, ?string $discountCode = null): bool
{
    $to = trim((string)($customer['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $tokens = [
        'vorname'    => $customer['first_name'] ?: $customer['full_name'],
        'nachname'   => $customer['last_name'] ?? '',
        'name'       => $customer['full_name'] ?? '',
        'salonname'  => $salon['salon_name'] ?? '',
        'rabattcode' => $discountCode ?? '',
    ];

    $subject = renderTemplate((string)$campaign['subject'], $tokens);
    $body = renderTemplate((string)$campaign['body'], $tokens);
    $html = buildCampaignEmailHtml($salon, $body, $discountCode);

    $config = salonMailConfig($conn, $salon);

    return _sendHtmlMail($to, $subject, $html, $config['from_email'], $config['from_name'], $config['smtp']);
}

/**
 * Invitation mail: a link to choose a password, rather than a generated
 * password in cleartext.
 */
function sendInvitationEmail(mysqli $conn, array $salon, array $invitation, string $token): bool
{
    $dashboardUrl = rtrim(getenv('DASHBOARD_URL') ?: 'https://coiffureai.com', '/');
    $link = $dashboardUrl . '/set-password.html?token=' . urlencode($token);

    $salonName = $salon['salon_name'] ?? 'Coiffure Digital';
    $name = $invitation['full_name'] ?: $invitation['email'];
    $primary = _hex($salon['primary_color'] ?? '#2563EB');
    $secondary = _hex($salon['secondary_color'] ?? '#0EA5E9');

    $body = '
        <p>Hallo ' . _h($name) . ',</p>
        <p>Sie wurden eingeladen, das Dashboard von <strong>' . _h($salonName) . '</strong> zu nutzen.</p>
        <p>Bitte legen Sie über den folgenden Link Ihr eigenes Passwort fest:</p>
        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0">
          <tr><td align="center" style="border-radius:8px;background:' . $primary . '">
            <a href="' . _h($link) . '"
               style="display:inline-block;padding:14px 28px;font-family:Helvetica,Arial,sans-serif;
                      font-size:15px;font-weight:600;color:#ffffff;text-decoration:none">
              Passwort festlegen
            </a>
          </td></tr>
        </table>
        <p style="font-size:13px;color:#64748b">
          Der Link ist 7 Tage gültig. Falls Sie diese Einladung nicht erwartet haben,
          können Sie diese E-Mail ignorieren.
        </p>';

    $html = _emailHeader($primary, $secondary, _logoTag($salon['logo_path'] ?? null, $salonName), $salonName)
          . '<tr><td style="padding:8px 28px 0;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1f2937">'
          . $body
          . '</td></tr>'
          . _emailFooter($salonName);

    $config = salonMailConfig($conn, $salon);

    return _sendHtmlMail(
        $invitation['email'],
        'Ihr Zugang zu ' . $salonName,
        $html,
        $config['from_email'],
        $config['from_name'],
        $config['smtp']
    );
}
