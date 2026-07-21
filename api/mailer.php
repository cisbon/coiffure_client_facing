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

function _sendHtmlMail($to, $subject, $html, $fromEmail, $fromName)
{
    // Encode subject for non-ASCII (German umlauts).
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFrom    = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>';

    // SMTP path (optional).
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

/** Minimal SMTP sender (STARTTLS/SSL) — no external library required. */
function _sendViaSmtp($to, $encodedSubject, $html, $fromEmail, $encodedFrom)
{
    $host = getenv('SMTP_HOST');
    $port = (int)(getenv('SMTP_PORT') ?: 587);
    $user = getenv('SMTP_USERNAME');
    $pass = getenv('SMTP_PASSWORD');
    $secure = strtolower(getenv('SMTP_SECURE') ?: 'tls'); // tls | ssl | none

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 15);
    if (!$fp) {
        error_log("mailer SMTP connect failed: $errstr ($errno)");
        return false;
    }

    $read = function () use ($fp) { return fgets($fp, 512); };
    $cmd  = function ($c) use ($fp) { fputs($fp, $c . "\r\n"); };

    $read();
    $cmd('EHLO ' . _mailDomain()); _drain($fp);

    if ($secure === 'tls') {
        $cmd('STARTTLS'); $read();
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log('mailer SMTP STARTTLS failed');
            fclose($fp); return false;
        }
        $cmd('EHLO ' . _mailDomain()); _drain($fp);
    }

    if ($user) {
        $cmd('AUTH LOGIN'); $read();
        $cmd(base64_encode($user)); $read();
        $cmd(base64_encode($pass)); $read();
    }

    $cmd('MAIL FROM:<' . $fromEmail . '>'); $read();
    $cmd('RCPT TO:<' . $to . '>'); $read();
    $cmd('DATA'); $read();

    $message  = "From: {$encodedFrom}\r\n";
    $message .= "To: {$to}\r\n";
    $message .= "Subject: {$encodedSubject}\r\n";
    $message .= "MIME-Version: 1.0\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $html . "\r\n.";
    $cmd($message); $read();

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
