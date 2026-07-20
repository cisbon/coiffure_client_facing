<?php
/**
 * WalletGenerator
 * -------------------------------------------------------------------
 * Generates digital membership ("Treuekarte") passes for Apple Wallet
 * (.pkpass) and Google Wallet (Save-to-Wallet JWT link).
 *
 * Both platforms need issuer credentials that differ per deployment, so
 * every path/certificate is read from configuration (see api/.env.example,
 * keys prefixed WALLET_*). When credentials are missing the generator
 * degrades gracefully:
 *   - Apple:  an *unsigned* .pkpass is still written to wallet/passes so the
 *             flow works in development. Real devices require a signed pass;
 *             configure WALLET_APPLE_* to enable signing.
 *   - Google: createGooglePassLink() returns null and the wallet landing
 *             page simply omits the Google button.
 *
 * The public entry point for customers is the /wallet/{memberId} endpoint
 * (api/wallet.php) whose URL is what we encode in the on-screen QR code and
 * the welcome e-mail.
 *
 * @property array $memberData Expected keys:
 *   member_id, first_name, last_name, full_name, member_since (Y-m-d),
 *   salon_name, salon_slug, logo_path (absolute fs path, optional),
 *   primary_color / background_color (hex, optional)
 */
class WalletGenerator
{
    /** @var string Directory where generated .pkpass files are stored. */
    private $passesDir;

    /** @var string Public base URL, e.g. https://coiffure.digital */
    private $baseUrl;

    public function __construct()
    {
        $this->passesDir = getenv('WALLET_PASSES_DIR') ?: (__DIR__ . '/../wallet/passes');
        // Pretty base URL (e.g. https://coiffure.digital) when rewrite rules
        // are configured; otherwise we fall back to a query-string endpoint.
        $this->baseUrl = rtrim(getenv('WALLET_BASE_URL') ?: '', '/');

        if (!is_dir($this->passesDir)) {
            @mkdir($this->passesDir, 0755, true);
        }
    }

    // =================================================================
    // Public URLs
    // =================================================================

    /**
     * Public, device-agnostic URL that adds the card to the customer's
     * wallet. This is what the QR code and e-mail button point to.
     *
     * If WALLET_BASE_URL is set we emit the pretty "/{slug}/wallet/{id}"
     * form (needs the rewrite rule in wallet/.htaccess). Otherwise we emit
     * a directly-working query-string endpoint under APP_PUBLIC_URL so the
     * MVP works with no server rewrite configuration.
     */
    public function getWalletUrl($memberId, $salonSlug = null)
    {
        if ($this->baseUrl !== '') {
            $slug = $salonSlug ? '/' . rawurlencode($salonSlug) : '';
            return $this->baseUrl . $slug . '/wallet/' . rawurlencode($memberId);
        }
        $appBase = rtrim(getenv('APP_PUBLIC_URL') ?: 'https://clouedo.com/coiffure', '/');
        return $appBase . '/api/wallet.php?member_id=' . rawurlencode($memberId);
    }

    // =================================================================
    // Apple Wallet
    // =================================================================

    /**
     * Build a .pkpass for Apple Wallet.
     *
     * @param array $memberData
     * @return string|null Absolute path to the generated .pkpass, or null on failure.
     */
    public function createApplePass(array $memberData)
    {
        if (!class_exists('ZipArchive')) {
            error_log('WalletGenerator: ZipArchive not available, cannot build .pkpass');
            return null;
        }

        $passTypeId = getenv('WALLET_APPLE_PASS_TYPE_ID') ?: 'pass.digital.coiffure.membership';
        $teamId     = getenv('WALLET_APPLE_TEAM_ID') ?: 'TEAMID0000';
        $orgName    = $memberData['salon_name'] ?? 'Coiffure';

        $primary    = $this->hexToRgbString($memberData['primary_color'] ?? '#9333EA');
        $memberName = trim(($memberData['first_name'] ?? '') . ' ' . ($memberData['last_name'] ?? ''));
        if ($memberName === '') {
            $memberName = $memberData['full_name'] ?? 'Mitglied';
        }
        $memberSince = $this->formatDate($memberData['member_since'] ?? date('Y-m-d'));

        // pass.json — a "storeCard" style loyalty pass.
        $pass = [
            'formatVersion'      => 1,
            'passTypeIdentifier' => $passTypeId,
            'serialNumber'       => (string)$memberData['member_id'],
            'teamIdentifier'     => $teamId,
            'organizationName'   => $orgName,
            'description'        => $orgName . ' Treuekarte',
            'logoText'           => $orgName,
            'backgroundColor'    => $primary,
            'foregroundColor'    => 'rgb(255, 255, 255)',
            'labelColor'         => 'rgb(255, 255, 255)',
            'barcodes'           => [[
                'format'          => 'PKBarcodeFormatQR',
                'message'         => (string)$memberData['member_id'],
                'messageEncoding' => 'iso-8859-1',
                'altText'         => (string)$memberData['member_id'],
            ]],
            'storeCard' => [
                'primaryFields' => [[
                    'key'   => 'member',
                    'label' => 'Mitglied',
                    'value' => $memberName,
                ]],
                'secondaryFields' => [[
                    'key'   => 'since',
                    'label' => 'Mitglied seit',
                    'value' => $memberSince,
                ]],
                'auxiliaryFields' => [[
                    'key'   => 'card',
                    'label' => 'Karte',
                    'value' => 'Treuekarte',
                ]],
                'backFields' => [[
                    'key'   => 'benefits',
                    'label' => 'Ihre Vorteile',
                    'value' => "• 10 € Rabatt auf jeden 5. Besuch\n• Freund werben: Sie beide erhalten 10 € Rabatt\n• Exklusive Angebote & Geburtstags-Überraschungen",
                ], [
                    'key'   => 'memberid',
                    'label' => 'Mitgliedsnummer',
                    'value' => (string)$memberData['member_id'],
                ]],
            ],
        ];

        // Assemble the files that go into the zip.
        $files = [];
        $files['pass.json'] = json_encode($pass, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Include the salon logo as icon/logo if we have a readable image.
        $logoPath = $memberData['logo_path'] ?? null;
        if ($logoPath && is_file($logoPath) && is_readable($logoPath)) {
            $img = @file_get_contents($logoPath);
            if ($img !== false) {
                $files['icon.png'] = $img;
                $files['logo.png'] = $img;
            }
        }

        // manifest.json = SHA1 of every file.
        $manifest = [];
        foreach ($files as $name => $content) {
            $manifest[$name] = sha1($content);
        }
        $files['manifest.json'] = json_encode($manifest, JSON_UNESCAPED_SLASHES);

        // signature (PKCS#7 detached). Only when certs are configured.
        $signature = $this->signManifest($files['manifest.json']);
        if ($signature !== null) {
            $files['signature'] = $signature;
        } else {
            error_log('WalletGenerator: Apple pass signing skipped (certificates not configured). '
                . 'Writing UNSIGNED .pkpass for development.');
        }

        $outPath = $this->passesDir . '/' . $this->safeName($memberData['member_id']) . '.pkpass';
        $zip = new ZipArchive();
        if ($zip->open($outPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            error_log('WalletGenerator: could not open zip for writing at ' . $outPath);
            return null;
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $outPath;
    }

    /**
     * PKCS#7-sign the manifest with the Apple Pass Type ID certificate.
     * Returns the raw DER signature, or null when certs are unavailable.
     */
    private function signManifest($manifestJson)
    {
        $certPath = getenv('WALLET_APPLE_CERT_PATH');       // .p12 or .pem certificate
        $certPass = getenv('WALLET_APPLE_CERT_PASSWORD') ?: '';
        $wwdrPath = getenv('WALLET_APPLE_WWDR_PATH');       // Apple WWDR intermediate (PEM)

        if (!$certPath || !is_file($certPath) || !$wwdrPath || !is_file($wwdrPath)) {
            return null;
        }
        if (!function_exists('openssl_pkcs7_sign')) {
            error_log('WalletGenerator: openssl_pkcs7_sign unavailable');
            return null;
        }

        // Load certificate + private key (support .p12 bundle or separate PEM).
        $certData = file_get_contents($certPath);
        $cert = null;
        $pkey = null;

        if (openssl_pkcs12_read($certData, $p12, $certPass)) {
            $cert = $p12['cert'];
            $pkey = [$p12['pkey'], $certPass];
        } else {
            // Assume PEM containing both cert and key.
            $cert = $certData;
            $pkey = [$certData, $certPass];
        }

        $tmpManifest = tempnam(sys_get_temp_dir(), 'wm_');
        $tmpSig      = tempnam(sys_get_temp_dir(), 'ws_');
        file_put_contents($tmpManifest, $manifestJson);

        $ok = openssl_pkcs7_sign(
            $tmpManifest,
            $tmpSig,
            $cert,
            $pkey,
            [],
            PKCS7_BINARY | PKCS7_DETACHED,
            $wwdrPath
        );

        $signature = null;
        if ($ok) {
            // openssl writes a PEM/SMIME structure; extract the DER body.
            $signed = file_get_contents($tmpSig);
            $signature = $this->pemSignatureToDer($signed);
        } else {
            error_log('WalletGenerator: openssl_pkcs7_sign failed: ' . openssl_error_string());
        }

        @unlink($tmpManifest);
        @unlink($tmpSig);
        return $signature;
    }

    /** Extract the base64 DER blob from openssl's SMIME output. */
    private function pemSignatureToDer($smime)
    {
        // The signature body sits after the headers / blank line.
        $parts = preg_split('/\n\s*\n/', $smime, 2);
        $body = isset($parts[1]) ? $parts[1] : $smime;
        // Strip any trailing MIME boundary lines.
        $body = preg_replace('/\n--.*$/s', '', $body);
        $der = base64_decode(preg_replace('/\s+/', '', $body), true);
        return $der !== false ? $der : null;
    }

    // =================================================================
    // Google Wallet
    // =================================================================

    /**
     * Build a "Save to Google Wallet" link (a signed JWT) for a loyalty card.
     *
     * @param array $memberData
     * @return string|null The https://pay.google.com/gp/v/save/{jwt} URL,
     *                     or null when Google credentials are not configured.
     */
    public function createGooglePassLink(array $memberData)
    {
        $issuerId   = getenv('WALLET_GOOGLE_ISSUER_ID');
        $classId    = getenv('WALLET_GOOGLE_CLASS_ID');           // {issuerId}.{class}
        $saJsonPath = getenv('WALLET_GOOGLE_SERVICE_ACCOUNT_JSON');

        if (!$issuerId || !$classId || !$saJsonPath || !is_file($saJsonPath)) {
            return null;
        }

        $sa = json_decode(file_get_contents($saJsonPath), true);
        if (!$sa || empty($sa['client_email']) || empty($sa['private_key'])) {
            error_log('WalletGenerator: invalid Google service account JSON');
            return null;
        }

        $objectId   = $issuerId . '.' . $this->safeName($memberData['member_id']);
        $memberName = trim(($memberData['first_name'] ?? '') . ' ' . ($memberData['last_name'] ?? ''));
        if ($memberName === '') {
            $memberName = $memberData['full_name'] ?? 'Mitglied';
        }
        $hex = $memberData['primary_color'] ?? '#9333EA';

        $loyaltyObject = [
            'id'                => $objectId,
            'classId'           => $classId,
            'state'             => 'ACTIVE',
            'accountId'         => (string)$memberData['member_id'],
            'accountName'       => $memberName,
            'hexBackgroundColor'=> $hex,
            'barcode'           => [
                'type'  => 'QR_CODE',
                'value' => (string)$memberData['member_id'],
            ],
            'textModulesData'   => [[
                'header' => 'Mitglied seit',
                'body'   => $this->formatDate($memberData['member_since'] ?? date('Y-m-d')),
                'id'     => 'since',
            ]],
        ];

        $claims = [
            'iss'     => $sa['client_email'],
            'aud'     => 'google',
            'typ'     => 'savetowallet',
            'iat'     => time(),
            'payload' => [
                'loyaltyObjects' => [$loyaltyObject],
            ],
        ];

        $jwt = $this->signJwtRs256($claims, $sa['private_key']);
        if ($jwt === null) {
            return null;
        }
        return 'https://pay.google.com/gp/v/save/' . $jwt;
    }

    /** Sign a JWT with RS256 using a PEM private key. */
    private function signJwtRs256(array $claims, $privateKeyPem)
    {
        if (!function_exists('openssl_sign')) {
            return null;
        }
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $segments = [
            $this->base64Url(json_encode($header, JSON_UNESCAPED_SLASHES)),
            $this->base64Url(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
        $signingInput = implode('.', $segments);

        $signature = '';
        if (!openssl_sign($signingInput, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256)) {
            error_log('WalletGenerator: openssl_sign failed for Google JWT');
            return null;
        }
        $segments[] = $this->base64Url($signature);
        return implode('.', $segments);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function base64Url($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function safeName($value)
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$value);
    }

    private function hexToRgbString($hex)
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) {
            return 'rgb(147, 51, 234)';
        }
        return sprintf(
            'rgb(%d, %d, %d)',
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2))
        );
    }

    private function formatDate($ymd)
    {
        $ts = strtotime($ymd);
        return $ts ? date('d.m.Y', $ts) : $ymd;
    }
}
