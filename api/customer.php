<?php
/**
 * Customer API Endpoint
 * -------------------------------------------------------------------
 * Handles the membership-focused, GDPR-compliant tablet registration.
 *
 * New in the membership refactor:
 *   - Split name, birthday, ZIP/city, optional postal address block
 *   - Channel-specific consents (e-mail marketing, SMS/WhatsApp, postal)
 *   - Membership opt-in → unique member ID (status only, no wallet pass)
 *   - Conditional welcome e-mail (membership vs. plain)
 *
 * GDPR: the postal address is ONLY stored when consent_postal is checked.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('Method not allowed. Use POST.', 405);
}

// ------------------------------------------------------------------
// Parse request
// ------------------------------------------------------------------
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $requestData = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendErrorResponse('Invalid JSON: ' . json_last_error_msg(), 400);
    }
} else {
    $requestData = $_POST;
}

// Backward compatibility: accept legacy single "full_name" by splitting it.
if (empty($requestData['first_name']) && !empty($requestData['full_name'])) {
    $parts = preg_split('/\s+/', trim($requestData['full_name']), 2);
    $requestData['first_name'] = $parts[0] ?? '';
    $requestData['last_name']  = $parts[1] ?? '';
}

// ------------------------------------------------------------------
// Required fields
// ------------------------------------------------------------------
$requiredFields = [
    'first_name',
    'last_name',
    'email',
    'birth_day',
    'birth_month',
    'zip',
    'city',
    'consent_data_processing',
    'policy_version',
];
$validation = validateRequiredFields($requestData, $requiredFields);
if ($validation !== null) {
    sendErrorResponse($validation['message'], 400, ['missing_fields' => $validation['missing_fields']]);
}

// ------------------------------------------------------------------
// Sanitize + validate
// ------------------------------------------------------------------
$firstName = sanitizeInput($requestData['first_name']);
$lastName  = sanitizeInput($requestData['last_name']);
$fullName  = trim($firstName . ' ' . $lastName);
$email     = sanitizeInput($requestData['email']);
$mobile    = !empty($requestData['mobile']) ? sanitizeInput($requestData['mobile']) : null;

$birthDay   = (int)$requestData['birth_day'];
$birthMonth = (int)$requestData['birth_month'];
$birthYear  = !empty($requestData['birth_year']) ? (int)$requestData['birth_year'] : null;

$zip  = sanitizeInput($requestData['zip']);
$city = sanitizeInput($requestData['city']);

// Consents
$consentDataProcessing = (bool)$requestData['consent_data_processing'];
$consentEmailMarketing = !empty($requestData['consent_email_marketing']);
$consentSmsWhatsapp    = !empty($requestData['consent_sms_whatsapp']);
$consentPostal         = !empty($requestData['consent_postal']);
$isMember              = !empty($requestData['is_member']);
// Legacy column kept in sync with e-mail marketing consent.
$consentMarketing = $consentEmailMarketing;
// Cancellation policy is no longer part of the form; keep column, accept if sent.
$consentCancellation = !empty($requestData['consent_cancellation_policy']);

$policyVersion   = sanitizeInput($requestData['policy_version']);
$referralSource  = !empty($requestData['referral_source']) ? sanitizeInput($requestData['referral_source']) : null;
$preferredStylist = !empty($requestData['preferred_stylist_id']) ? (int)$requestData['preferred_stylist_id'] : null;

// Postal address — only kept when the customer explicitly consented (GDPR).
$addressStreet = null;
$addressZip    = null;
$addressCity   = null;
if ($consentPostal) {
    $addressStreet = !empty($requestData['address_street']) ? sanitizeInput($requestData['address_street']) : null;
    $addressZip    = !empty($requestData['address_zip']) ? sanitizeInput($requestData['address_zip']) : $zip;
    $addressCity   = !empty($requestData['address_city']) ? sanitizeInput($requestData['address_city']) : $city;
}

// Validation
if (!validateEmail($email)) {
    sendErrorResponse('Invalid email address', 400);
}
if ($mobile !== null && $mobile !== '' && !validatePhone($mobile)) {
    sendErrorResponse('Invalid phone number', 400);
}
if ($birthDay < 1 || $birthDay > 31 || $birthMonth < 1 || $birthMonth > 12) {
    sendErrorResponse('Invalid birthday', 400);
}
if (!$consentDataProcessing) {
    sendErrorResponse('Data processing consent is required', 400);
}

// ------------------------------------------------------------------
// DB connection + salon resolution
// ------------------------------------------------------------------
$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

$salonId = null;
$token = getSessionToken();
if ($token) {
    $currentUser = validateSession($conn, $token);
    if ($currentUser) {
        $salonStmt = $conn->prepare(
            "SELECT salon_id FROM coiffure_user_salons WHERE user_id = ? LIMIT 1"
        );
        if ($salonStmt) {
            $salonStmt->bind_param("i", $currentUser['user_id']);
            $salonStmt->execute();
            $salonResult = $salonStmt->get_result();
            if ($salonResult->num_rows > 0) {
                $salonId = (int)$salonResult->fetch_assoc()['salon_id'];
            }
            $salonStmt->close();
        }
    }
}
if ($salonId === null) {
    $salonId = (int)($requestData['salon_id'] ?? DEFAULT_SALON_ID);
}

// Load salon details for e-mail branding.
$salon = getSalonBranding($conn, $salonId);

$ipAddress = getClientIp();
$userAgent = getUserAgent();
$dataProcessingPurpose = 'Kundenbeziehung, Terminverwaltung, Treueprogramm und – bei Einwilligung – postalische Angebote';
$dataRetentionUntil = calculateRetentionDate(3);

// ------------------------------------------------------------------
// Upsert customer
// ------------------------------------------------------------------
$stmt = $conn->prepare(
    "SELECT customer_id, member_id, is_member, member_since
     FROM coiffure_customers WHERE email = ? AND salon_id = ? AND is_deleted = 0"
);
$stmt->bind_param("si", $email, $salonId);
$stmt->execute();
$existing = $stmt->get_result();
$existingRow = $existing->num_rows > 0 ? $existing->fetch_assoc() : null;
$stmt->close();

// Determine membership id / since.
$memberId = null;
$memberSince = null;
if ($isMember) {
    if ($existingRow && !empty($existingRow['member_id'])) {
        // Preserve existing membership.
        $memberId = $existingRow['member_id'];
        $memberSince = $existingRow['member_since'] ?: date('Y-m-d');
    } else {
        $memberId = generateMemberId($conn, $salonId);
        $memberSince = date('Y-m-d');
    }
}

if ($existingRow) {
    $customerId = (int)$existingRow['customer_id'];

    $update = $conn->prepare(
        "UPDATE coiffure_customers SET
            full_name = ?, first_name = ?, last_name = ?,
            phone = ?, mobile = ?,
            birth_day = ?, birth_month = ?, birth_year = ?,
            zip = ?, city = ?,
            address_street = ?, address_zip = ?, address_city = ?, consent_postal = ?,
            consent_marketing = ?, consent_email_marketing = ?, consent_sms_whatsapp = ?,
            consent_data_processing = ?, consent_cancellation_policy = ?,
            consent_timestamp = CURRENT_TIMESTAMP, policy_version_accepted = ?,
            is_member = ?, member_id = ?, member_since = ?,
            referral_source = ?, preferred_stylist_id = ?,
            ip_address = ?, user_agent = ?, updated_at = CURRENT_TIMESTAMP
         WHERE customer_id = ?"
    );
    // Types: build carefully
    $update->bind_param(
        "sssssiiisssssiiiiiisisssissi",
        $fullName, $firstName, $lastName,
        $mobile, $mobile,
        $birthDay, $birthMonth, $birthYear,
        $zip, $city,
        $addressStreet, $addressZip, $addressCity, $consentPostal,
        $consentMarketing, $consentEmailMarketing, $consentSmsWhatsapp,
        $consentDataProcessing, $consentCancellation,
        $policyVersion,
        $isMember, $memberId, $memberSince,
        $referralSource, $preferredStylist,
        $ipAddress, $userAgent,
        $customerId
    );
    if (!$update->execute()) {
        error_log("Customer update failed: " . $update->error);
        sendErrorResponse('Failed to update customer data', 500);
    }
    $update->close();
    $action = 'updated';
    logAudit($conn, 'customer', $customerId, 'update', 'Registration updated', 'tablet_form');
} else {
    $insert = $conn->prepare(
        "INSERT INTO coiffure_customers
            (salon_id, full_name, first_name, last_name, email, phone, mobile,
             birth_day, birth_month, birth_year, zip, city,
             address_street, address_zip, address_city, consent_postal,
             consent_marketing, consent_email_marketing, consent_sms_whatsapp,
             consent_data_processing, consent_cancellation_policy,
             consent_timestamp, policy_version_accepted,
             is_member, member_id, member_since,
             referral_source, preferred_stylist_id,
             ip_address, user_agent, data_processing_purpose,
             gdpr_consent_notice_shown, data_retention_until)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                 CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)"
    );
    $insert->bind_param(
        "issssssiiisssssiiiiiisisssissss",
        $salonId, $fullName, $firstName, $lastName, $email, $mobile, $mobile,
        $birthDay, $birthMonth, $birthYear, $zip, $city,
        $addressStreet, $addressZip, $addressCity, $consentPostal,
        $consentMarketing, $consentEmailMarketing, $consentSmsWhatsapp,
        $consentDataProcessing, $consentCancellation,
        $policyVersion,
        $isMember, $memberId, $memberSince,
        $referralSource, $preferredStylist,
        $ipAddress, $userAgent, $dataProcessingPurpose,
        $dataRetentionUntil
    );
    if (!$insert->execute()) {
        error_log("Customer insert failed: " . $insert->error);
        sendErrorResponse('Failed to save customer data', 500);
    }
    $customerId = $insert->insert_id;
    $insert->close();
    $action = 'created';
    logAudit($conn, 'customer', $customerId, 'create', 'New customer registered', 'tablet_form');
}

// ------------------------------------------------------------------
// Branding assets for the welcome e-mail
// ------------------------------------------------------------------
$publicBase = rtrim(getenv('APP_PUBLIC_URL') ?: 'https://clouedo.com/coiffure', '/');
$logoPublicUrl = null;
if (!empty($salon['logo_path'])) {
    $logoPublicUrl = $publicBase . '/' . ltrim($salon['logo_path'], '/');
}

// ------------------------------------------------------------------
// Send welcome / membership e-mail (best-effort, never blocks response)
// ------------------------------------------------------------------
$emailSent = false;
try {
    $emailSent = sendWelcomeEmail([
        'to_email'        => $email,
        'first_name'      => $firstName,
        'salon_name'      => $salon['salon_name'] ?? 'unser Salon',
        'is_member'       => $isMember,
        'member_id'       => $memberId,
        'member_since'    => $memberSince,
        'primary_color'   => $salon['primary_color'] ?? '#9333EA',
        'secondary_color' => $salon['secondary_color'] ?? '#EC4899',
        'logo_url'        => $logoPublicUrl,
    ]);
} catch (Throwable $e) {
    error_log('Welcome email error: ' . $e->getMessage());
}

// ------------------------------------------------------------------
// Respond
// ------------------------------------------------------------------
$conn->close();

sendJsonResponse([
    'success'      => true,
    'message'      => $isMember
        ? 'Willkommen im Club! Ihre Mitgliedschaft ist aktiv.'
        : 'Registrierung erfolgreich.',
    'customer_id'  => $customerId,
    'action'       => $action,
    'is_member'    => $isMember,
    'member_id'    => $memberId,
    'member_since' => $memberSince,
    'email_sent'   => $emailSent,
    'salon_name'   => $salon['salon_name'] ?? null,
], $action === 'created' ? 201 : 200);

// ==================================================================
// Local helpers
// ==================================================================

/** Load salon branding fields, tolerating missing branding columns. */
function getSalonBranding(mysqli $conn, int $salonId): array
{
    $hasBranding = false;
    $check = $conn->query("SHOW COLUMNS FROM coiffure_salons LIKE 'primary_color'");
    if ($check && $check->num_rows > 0) {
        $hasBranding = true;
    }

    $columns = $hasBranding
        ? "salon_id, salon_name, logo_path, primary_color, secondary_color, background_color"
        : "salon_id, salon_name";

    $stmt = $conn->prepare("SELECT $columns FROM coiffure_salons WHERE salon_id = ?");
    $stmt->bind_param("i", $salonId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->num_rows > 0 ? $res->fetch_assoc() : ['salon_name' => 'Coiffure'];
    $stmt->close();
    return $row;
}

/** Generate a unique, human-friendly member ID (e.g. M25-A1B2C3). */
function generateMemberId(mysqli $conn, int $salonId): string
{
    for ($i = 0; $i < 8; $i++) {
        $candidate = 'M' . date('y') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $conn->prepare("SELECT 1 FROM coiffure_customers WHERE member_id = ? LIMIT 1");
        $stmt->bind_param("s", $candidate);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if (!$exists) {
            return $candidate;
        }
    }
    // Extremely unlikely fallback.
    return 'M' . date('y') . '-' . strtoupper(bin2hex(random_bytes(5)));
}
