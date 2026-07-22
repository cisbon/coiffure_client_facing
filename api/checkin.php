<?php
/**
 * Self Check-in API Endpoint
 * -------------------------------------------------------------------
 * Powers the tablet kiosk check-in flow. A single entry point dispatches on
 * an `action` parameter so it works on flat PHP hosting without URL rewrites:
 *
 *   GET  checkin.php?action=candidates&day=DD&month=MM[&q=…][&salon_id=N]
 *        → members whose birthday is DD.MM  (id, first_name, last_initial, gender)
 *
 *   POST checkin.php   {"action":"confirm","customer_id":N}
 *        → logs a visit (once per calendar day) + returns the welcome payload
 *          (first name, dynamic loyalty progress, birthday/reward/referral flags)
 *
 *   POST checkin.php   {"action":"phone","phone_number":"…"}
 *        → phone fallback lookup + same welcome payload
 *
 *   POST checkin.php   {"action":"staff_verify","pin":"1234"}
 *        → verifies the salon staff PIN (guards the hidden staff check-in path)
 *
 *   POST checkin.php   {"action":"staff_search","pin":"1234","q":"…"}
 *        → staff-only search (full names) by name or phone
 *
 *   POST checkin.php   {"action":"staff_confirm","pin":"1234","customer_id":N}
 *        → logs a staff-initiated visit + welcome payload
 *
 *   POST checkin.php   {"action":"event","event_type":"…","customer_id":N,"payload":{…}}
 *        → append-only analytics (NO PII beyond customer_id)
 *
 * GDPR: candidate data is minimal (first name + last initial) and is only
 * returned once a birthday has been supplied. Failed-lookup details are never
 * tied to a person. The phone fallback only matches customers who stored a
 * number.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/loyalty_helpers.php';

setCorsHeaders();

$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

// coiffure_visits may not exist yet if migration 009 has not run.
function visitsTableExists(mysqli $conn): bool
{
    $res = $conn->query("SHOW TABLES LIKE 'coiffure_visits'");
    return $res && $res->num_rows > 0;
}

function tableExists(mysqli $conn, string $name): bool
{
    $safe = $conn->real_escape_string($name);
    $res = $conn->query("SHOW TABLES LIKE '$safe'");
    return $res && $res->num_rows > 0;
}

/**
 * Resolve the active salon: prefer the authenticated user's salon, then an
 * explicit salon_id parameter, then the configured default.
 */
function resolveSalonId(mysqli $conn, $requestData): int
{
    $token = getSessionToken();
    if ($token) {
        $user = validateSession($conn, $token);
        if ($user) {
            $stmt = $conn->prepare("SELECT salon_id FROM coiffure_user_salons WHERE user_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $user['user_id']);
                $stmt->execute();
                $r = $stmt->get_result();
                if ($r->num_rows > 0) {
                    $sid = (int)$r->fetch_assoc()['salon_id'];
                    $stmt->close();
                    return $sid;
                }
                $stmt->close();
            }
        }
    }
    $fromReq = $requestData['salon_id'] ?? ($_GET['salon_id'] ?? null);
    return (int)($fromReq ?? DEFAULT_SALON_ID);
}

/**
 * Salon ids assigned to the authenticated tablet user (empty when no session).
 * The tablet logs in with a per-salon account, so this is the trusted scope.
 */
function sessionSalonIds(mysqli $conn): array
{
    $token = getSessionToken();
    if (!$token) {
        return [];
    }
    $user = validateSession($conn, $token);
    if (!$user) {
        return [];
    }
    $ids = [];
    $stmt = $conn->prepare("SELECT salon_id FROM coiffure_user_salons WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user['user_id']);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $ids[] = (int)$row['salon_id'];
        }
        $stmt->close();
    }
    return $ids;
}

/**
 * Expand a set of salon ids with every salon they are connected to (same brand
 * group in coiffure_salon_connections). A salon not listed there stays alone.
 */
function expandConnectedSalons(mysqli $conn, array $salonIds): array
{
    $salonIds = array_values(array_unique(array_map('intval', $salonIds)));
    if (empty($salonIds) || !tableExists($conn, 'coiffure_salon_connections')) {
        return $salonIds;
    }
    $set = [];
    foreach ($salonIds as $sid) {
        $set[$sid] = true;
    }
    $inClause = implode(',', $salonIds);
    $sql = "SELECT s2.salon_id
            FROM coiffure_salon_connections s1
            JOIN coiffure_salon_connections s2 ON s1.group_id = s2.group_id
            WHERE s1.salon_id IN ($inClause)";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $set[(int)$row['salon_id']] = true;
        }
    }
    return array_keys($set);
}

/**
 * The authoritative set of salon ids a check-in may search/act on.
 *   - With a tablet session: that user's salon(s), expanded by brand connections.
 *     The client-supplied salon_id is IGNORED (cannot check in cross-salon).
 *   - Without a session (dev/public): the request/default salon, also expanded.
 */
function resolveAllowedSalonIds(mysqli $conn, $requestData): array
{
    $ids = sessionSalonIds($conn);
    if (empty($ids)) {
        $fromReq = $requestData['salon_id'] ?? ($_GET['salon_id'] ?? null);
        $ids = [(int)($fromReq ?? DEFAULT_SALON_ID)];
    }
    $expanded = expandConnectedSalons($conn, $ids);
    return !empty($expanded) ? $expanded : [(int)DEFAULT_SALON_ID];
}

/** Safe comma-separated IN list from server-derived integer salon ids. */
function salonInClause(array $salonIds): string
{
    return implode(',', array_map('intval', $salonIds));
}

/** Log a visit; tolerates a missing visits table (returns false, non-fatal). */
function logVisit(mysqli $conn, int $customerId, int $salonId, string $method): bool
{
    if (!visitsTableExists($conn)) {
        error_log('checkin: coiffure_visits table missing — run migration 009');
        return false;
    }
    // The enum only allows birthday|phone|manual; staff check-ins log as manual.
    $stored = in_array($method, ['birthday', 'phone', 'manual'], true) ? $method : 'manual';
    $stmt = $conn->prepare(
        "INSERT INTO coiffure_visits (customer_id, salon_id, checkin_method) VALUES (?, ?, ?)"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("iis", $customerId, $salonId, $stored);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/** Has this customer already checked in on the current calendar day? */
function checkedInToday(mysqli $conn, int $customerId): bool
{
    if (!visitsTableExists($conn)) {
        return false;
    }
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM coiffure_visits
         WHERE customer_id = ? AND DATE(checked_in_at) = CURDATE()"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("i", $customerId);
    $stmt->execute();
    $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $c > 0;
}

/** Total lifetime visit count for a customer. */
function countVisits(mysqli $conn, int $customerId): int
{
    if (!visitsTableExists($conn)) {
        return 0;
    }
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM coiffure_visits WHERE customer_id = ?");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param("i", $customerId);
    $stmt->execute();
    $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $c;
}

/** True when a day/month birthday falls within ±$window days of today (year-agnostic). */
function birthdayWithin(?int $day, ?int $month, int $window): bool
{
    if (!$day || !$month) {
        return false;
    }
    $year = (int)date('Y');
    // Build the birthday in the current year; guard invalid dates (e.g. 29.02).
    $ts = @mktime(12, 0, 0, $month, $day, $year);
    if ($ts === false) {
        return false;
    }
    $today = mktime(12, 0, 0, (int)date('n'), (int)date('j'), $year);
    foreach ([-1, 0, 1] as $yShift) {
        $cand = @mktime(12, 0, 0, $month, $day, $year + $yShift);
        if ($cand === false) {
            continue;
        }
        $diffDays = abs(($cand - $today) / 86400);
        if ($diffDays <= $window) {
            return true;
        }
    }
    return false;
}

/** Append a non-PII analytics event (best-effort, never fatal). */
function recordEvent(mysqli $conn, int $salonId, string $type, ?int $customerId, $payload): void
{
    if (!tableExists($conn, 'coiffure_checkin_events')) {
        return;
    }
    $json = null;
    if (is_array($payload) && !empty($payload)) {
        // Defensive: drop any obviously-PII keys a client might send.
        foreach (['name', 'first_name', 'last_name', 'full_name', 'phone', 'phone_number', 'email', 'birthday'] as $k) {
            unset($payload[$k]);
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    $cid = $customerId ?: null;
    $stmt = $conn->prepare(
        "INSERT INTO coiffure_checkin_events (salon_id, event_type, customer_id, payload) VALUES (?, ?, ?, ?)"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param("isis", $salonId, $type, $cid, $json);
    @$stmt->execute();
    $stmt->close();
}

/** Salon staff PIN (defaults to 0000 when the column is missing). */
function getSalonStaffPin(mysqli $conn, int $salonId): string
{
    $has = $conn->query("SHOW COLUMNS FROM coiffure_salons LIKE 'staff_pin'");
    if (!$has || $has->num_rows === 0) {
        return '0000';
    }
    $stmt = $conn->prepare("SELECT staff_pin FROM coiffure_salons WHERE salon_id = ? LIMIT 1");
    if (!$stmt) {
        return '0000';
    }
    $stmt->bind_param("i", $salonId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($row && $row['staff_pin'] !== '') ? (string)$row['staff_pin'] : '0000';
}

/** True while a recent (<5 min) staff lockout is in effect for this salon. */
function staffLockActive(mysqli $conn, int $salonId): bool
{
    if (!tableExists($conn, 'coiffure_checkin_lockouts')) {
        return false;
    }
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM coiffure_checkin_lockouts
         WHERE salon_id = ? AND scope = 'staff' AND created_at > (NOW() - INTERVAL 5 MINUTE)"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("i", $salonId);
    $stmt->execute();
    $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $c > 0;
}

function recordLockout(mysqli $conn, int $salonId, string $scope): void
{
    if (!tableExists($conn, 'coiffure_checkin_lockouts')) {
        return;
    }
    $stmt = $conn->prepare("INSERT INTO coiffure_checkin_lockouts (salon_id, scope) VALUES (?, ?)");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param("is", $salonId, $scope);
    @$stmt->execute();
    $stmt->close();
}

/**
 * Build the full welcome-screen payload for a resolved customer.
 * Logs the visit unless the customer already checked in today (duplicate).
 */
function buildWelcomePayload(mysqli $conn, array $customer, int $salonId, string $method): array
{
    $customerId = (int)$customer['customer_id'];

    $isDuplicate = checkedInToday($conn, $customerId);
    if (!$isDuplicate) {
        logVisit($conn, $customerId, $salonId, $method);
        logAudit($conn, 'customer', $customerId, 'read', "Self check-in ($method)", 'checkin_kiosk');
    }

    $visitCount = countVisits($conn, $customerId);
    $cfg = getLoyaltyConfig($conn, $salonId);
    $progress = getLoyaltyProgress($cfg, $visitCount);

    $firstName = $customer['first_name'] ?: trim(explode(' ', $customer['full_name'] ?? '')[0]);
    $lastName  = trim((string)($customer['last_name'] ?? ''));
    $mb = function_exists('mb_substr');
    $lastInitialChar = $lastName !== '' ? ($mb ? mb_substr($lastName, 0, 1) : substr($lastName, 0, 1)) : '';
    $lastNameInitial = $lastInitialChar !== '' ? $lastInitialChar . '.' : '';
    $firstInitial = $firstName !== '' ? ($mb ? mb_substr($firstName, 0, 1) : substr($firstName, 0, 1)) : '';
    $initials = strtoupper($firstInitial . $lastInitialChar);
    $referral = strtolower((string)($customer['referral_source'] ?? ''));
    $wasReferred = in_array($referral, ['empfehlung', 'referral', 'friend', 'freund'], true) ||
                   strpos($referral, 'empfehl') !== false;

    return [
        'success'          => true,
        'customer_id'      => $customerId,
        'first_name'       => $firstName,
        'last_name_initial' => $lastNameInitial,
        'initials'         => $initials,
        'method'           => $method,
        'is_duplicate'    => $isDuplicate,
        'is_first_visit'  => (!$isDuplicate && $visitCount === 1),
        'was_referred'    => $wasReferred,
        'is_birthday_week' => birthdayWithin(
            isset($customer['birth_day']) ? (int)$customer['birth_day'] : null,
            isset($customer['birth_month']) ? (int)$customer['birth_month'] : null,
            3
        ),
        'loyalty' => [
            'active'           => $cfg['loyalty_active'],
            'visit_count'      => $progress['visit_count'],
            'visit_threshold'  => $progress['visit_threshold'],
            'visits_in_cycle'  => $progress['visits_in_cycle'],
            'visits_remaining' => $progress['visits_remaining'],
            'percent'          => $progress['percent'],
            'is_reward_visit'  => $progress['is_reward_visit'],
            'discount_label'   => $progress['discount_label'],
        ],
    ];
}

// ------------------------------------------------------------------
// Parse request (JSON or form / query)
// ------------------------------------------------------------------
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$requestData = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (strpos($contentType, 'application/json') !== false) {
        $requestData = json_decode(file_get_contents('php://input'), true) ?: [];
    } else {
        $requestData = $_POST;
    }
}

$action = $_GET['action'] ?? ($requestData['action'] ?? '');

// Shared SELECT column list for a check-in candidate/customer.
$CUSTOMER_COLS = "customer_id, first_name, last_name, full_name, salon_id, birth_day, birth_month, referral_source";

// ==================================================================
// GET candidates
// ==================================================================
if ($action === 'candidates') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendErrorResponse('Use GET for candidates', 405);
    }

    $day   = (int)($_GET['day'] ?? 0);
    $month = (int)($_GET['month'] ?? 0);

    if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
        sendErrorResponse('Ungültiges Datum. Bitte Tag (1–31) und Monat (1–12) angeben.', 400);
    }

    $allowedSalons = resolveAllowedSalonIds($conn, []);
    $salonId = $allowedSalons[0];
    $salonIn = salonInClause($allowedSalons);
    $q = trim((string)($_GET['q'] ?? ''));

    $hasGender = false;
    $gc = $conn->query("SHOW COLUMNS FROM coiffure_customers LIKE 'gender'");
    if ($gc && $gc->num_rows > 0) {
        $hasGender = true;
    }
    $genderCol = $hasGender ? ', gender' : '';

    $sql = "SELECT customer_id, first_name, last_name{$genderCol}
            FROM coiffure_customers
            WHERE salon_id IN ($salonIn) AND birth_day = ? AND birth_month = ? AND is_deleted = 0";
    $types = "ii";
    $params = [$day, $month];

    if ($q !== '') {
        $sql .= " AND (first_name LIKE ? OR last_name LIKE ?)";
        $types .= "ss";
        $like = $q . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= " ORDER BY first_name ASC, last_name ASC LIMIT 50";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        sendErrorResponse('Database query preparation failed', 500);
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $candidates = [];
    while ($row = $res->fetch_assoc()) {
        $first = $row['first_name'];
        $last  = $row['last_name'];
        $lastInitial = '';
        if ($last) {
            $lastInitial = (function_exists('mb_substr') ? mb_substr($last, 0, 1) : substr($last, 0, 1)) . '.';
        }
        $candidates[] = [
            'id'                => (int)$row['customer_id'],
            'first_name'        => $first ?: '',
            'last_name_initial' => $lastInitial,
            'gender'            => $hasGender ? ($row['gender'] ?? null) : null,
        ];
    }
    $stmt->close();

    recordEvent($conn, $salonId, 'birthday_selected', null, [
        'day' => $day, 'month' => $month, 'result_count' => count($candidates),
    ]);
    $conn->close();

    sendJsonResponse([
        'success'    => true,
        'day'        => $day,
        'month'      => $month,
        'query'      => $q,
        'candidates' => $candidates,
    ], 200);
}

// ==================================================================
// POST confirm (by customer_id) — birthday path
// ==================================================================
if ($action === 'confirm') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Use POST for confirm', 405);
    }

    $customerId = (int)($requestData['customer_id'] ?? 0);
    if ($customerId < 1) {
        sendErrorResponse('customer_id erforderlich', 400);
    }

    $allowedSalons = resolveAllowedSalonIds($conn, $requestData);
    $salonId = $allowedSalons[0];
    $salonIn = salonInClause($allowedSalons);

    // Scope to the tablet's own (and connected) salons — a customer_id from any
    // other salon must not be checkable in here.
    $stmt = $conn->prepare(
        "SELECT {$CUSTOMER_COLS} FROM coiffure_customers
         WHERE customer_id = ? AND salon_id IN ($salonIn) AND is_deleted = 0 LIMIT 1"
    );
    $stmt->bind_param("i", $customerId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        $stmt->close();
        sendErrorResponse('Kein Eintrag gefunden.', 404);
    }
    $customer = $res->fetch_assoc();
    $stmt->close();

    $visitSalon = (int)($customer['salon_id'] ?: $salonId);
    $payload = buildWelcomePayload($conn, $customer, $visitSalon, 'birthday');
    recordEvent($conn, $visitSalon, $payload['is_duplicate'] ? 'checkin_duplicate' : 'checkin_completed',
        $customerId, ['method' => 'birthday']);
    $conn->close();

    sendJsonResponse($payload, 200);
}

// ==================================================================
// POST phone (fallback lookup)
// ==================================================================
if ($action === 'phone') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Use POST for phone', 405);
    }

    $raw = trim((string)($requestData['phone_number'] ?? ''));
    $digits = preg_replace('/\D/', '', $raw);

    if (strlen($digits) < 5) {
        sendErrorResponse('Bitte geben Sie eine gültige Telefonnummer ein.', 400);
    }

    $allowedSalons = resolveAllowedSalonIds($conn, $requestData);
    $salonId = $allowedSalons[0];
    $salonIn = salonInClause($allowedSalons);

    // Compare on digits only so stored formats (spaces, +, /, -, ., parens) match.
    // Match on the trailing digits to tolerate country-code differences.
    $sqlDigits = "SELECT {$CUSTOMER_COLS}
                  FROM coiffure_customers
                  WHERE salon_id IN ($salonIn) AND is_deleted = 0
                    AND (mobile IS NOT NULL OR phone IS NOT NULL)
                    AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                        COALESCE(mobile, phone), ' ', ''), '-', ''), '/', ''), '(', ''), ')', ''), '.', ''), '+', '')
                        LIKE CONCAT('%', ?)
                  LIMIT 5";
    $matches = [];
    $stmt = $conn->prepare($sqlDigits);
    if ($stmt) {
        $tail = substr($digits, -max(7, min(strlen($digits), 9)));
        $stmt->bind_param("s", $tail);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $matches[] = $row;
        }
        $stmt->close();
    }

    if (count($matches) === 0) {
        recordEvent($conn, $salonId, 'phone_lookup_failed', null, []);
        $conn->close();
        sendErrorResponse('Kein Eintrag gefunden. Bitte wenden Sie sich an das Personal.', 404);
    }

    if (count($matches) > 1) {
        // Ambiguous phone → hand a minimal (first name + last initial) list back
        // to the kiosk so the customer can disambiguate.
        $list = array_map(function ($row) {
            $last = $row['last_name'];
            $initial = $last ? ((function_exists('mb_substr') ? mb_substr($last, 0, 1) : substr($last, 0, 1)) . '.') : '';
            return [
                'id'                => (int)$row['customer_id'],
                'first_name'        => $row['first_name'] ?: '',
                'last_name_initial' => $initial,
                'gender'            => null,
            ];
        }, $matches);
        $conn->close();
        sendJsonResponse(['success' => true, 'multiple' => true, 'candidates' => $list], 200);
    }

    $customer = $matches[0];
    $visitSalon = (int)($customer['salon_id'] ?: $salonId);
    $payload = buildWelcomePayload($conn, $customer, $visitSalon, 'phone');
    recordEvent($conn, $visitSalon, $payload['is_duplicate'] ? 'checkin_duplicate' : 'checkin_completed',
        (int)$customer['customer_id'], ['method' => 'phone']);
    $conn->close();

    sendJsonResponse($payload, 200);
}

// ==================================================================
// POST staff_verify — check the salon staff PIN
// ==================================================================
if ($action === 'staff_verify') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Use POST', 405);
    }
    $salonId = resolveSalonId($conn, $requestData);

    if (staffLockActive($conn, $salonId)) {
        $conn->close();
        sendErrorResponse('Zu viele Versuche. Bitte warten Sie 5 Minuten.', 429, ['locked' => true]);
    }

    $pin = preg_replace('/\D/', '', (string)($requestData['pin'] ?? ''));
    $expected = getSalonStaffPin($conn, $salonId);

    if ($pin !== '' && hash_equals($expected, $pin)) {
        $conn->close();
        sendJsonResponse(['success' => true], 200);
    }

    // Wrong PIN. If the client signals this was the 3rd try, record a lockout.
    if (!empty($requestData['final_attempt'])) {
        recordLockout($conn, $salonId, 'staff');
        recordEvent($conn, $salonId, 'staff_lockout', null, []);
    }
    $conn->close();
    sendErrorResponse('Falsche PIN.', 401);
}

// ==================================================================
// POST staff_search — full-name search behind the staff PIN
// ==================================================================
if ($action === 'staff_search') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Use POST', 405);
    }
    $salonId = resolveSalonId($conn, $requestData);

    if (staffLockActive($conn, $salonId)) {
        $conn->close();
        sendErrorResponse('Zu viele Versuche. Bitte warten Sie 5 Minuten.', 429, ['locked' => true]);
    }

    $pin = preg_replace('/\D/', '', (string)($requestData['pin'] ?? ''));
    if (!hash_equals(getSalonStaffPin($conn, $salonId), $pin)) {
        $conn->close();
        sendErrorResponse('Nicht autorisiert.', 401);
    }

    $q = trim((string)($requestData['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        $conn->close();
        sendJsonResponse(['success' => true, 'results' => []], 200);
    }

    $salonIn = salonInClause(resolveAllowedSalonIds($conn, $requestData));
    $like = '%' . $q . '%';
    $digits = preg_replace('/\D/', '', $q);
    $sql = "SELECT customer_id, first_name, last_name, full_name
            FROM coiffure_customers
            WHERE salon_id IN ($salonIn) AND is_deleted = 0
              AND (full_name LIKE ? OR first_name LIKE ? OR last_name LIKE ?";
    $types = "sss";
    $params = [$like, $like, $like];
    if (strlen($digits) >= 3) {
        $sql .= " OR REPLACE(REPLACE(REPLACE(COALESCE(mobile, phone), ' ', ''), '-', ''), '+', '') LIKE ?";
        $types .= "s";
        $params[] = '%' . $digits . '%';
    }
    $sql .= ") ORDER BY full_name ASC LIMIT 15";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $results = [];
    while ($row = $res->fetch_assoc()) {
        $results[] = [
            'id'        => (int)$row['customer_id'],
            'full_name' => $row['full_name'] ?: trim($row['first_name'] . ' ' . $row['last_name']),
        ];
    }
    $stmt->close();
    $conn->close();
    sendJsonResponse(['success' => true, 'results' => $results], 200);
}

// ==================================================================
// POST staff_confirm — log a staff-initiated visit
// ==================================================================
if ($action === 'staff_confirm') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Use POST', 405);
    }
    $salonId = resolveSalonId($conn, $requestData);

    $pin = preg_replace('/\D/', '', (string)($requestData['pin'] ?? ''));
    if (!hash_equals(getSalonStaffPin($conn, $salonId), $pin)) {
        $conn->close();
        sendErrorResponse('Nicht autorisiert.', 401);
    }

    $customerId = (int)($requestData['customer_id'] ?? 0);
    if ($customerId < 1) {
        sendErrorResponse('customer_id erforderlich', 400);
    }

    $salonIn = salonInClause(resolveAllowedSalonIds($conn, $requestData));
    $stmt = $conn->prepare(
        "SELECT {$CUSTOMER_COLS} FROM coiffure_customers
         WHERE customer_id = ? AND salon_id IN ($salonIn) AND is_deleted = 0 LIMIT 1"
    );
    $stmt->bind_param("i", $customerId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        $stmt->close();
        sendErrorResponse('Kein Eintrag gefunden.', 404);
    }
    $customer = $res->fetch_assoc();
    $stmt->close();

    $visitSalon = (int)($customer['salon_id'] ?: $salonId);
    // 'staff' method is logged as 'manual' in the visits enum; analytics keeps 'staff'.
    $payload = buildWelcomePayload($conn, $customer, $visitSalon, 'manual');
    $payload['method'] = 'staff';
    recordEvent($conn, $visitSalon, $payload['is_duplicate'] ? 'checkin_duplicate' : 'checkin_completed',
        $customerId, ['method' => 'staff']);
    $conn->close();

    sendJsonResponse($payload, 200);
}

// ==================================================================
// POST event — append-only analytics (no PII beyond customer_id)
// ==================================================================
if ($action === 'event') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Use POST', 405);
    }
    $salonId = resolveSalonId($conn, $requestData);
    $type = trim((string)($requestData['event_type'] ?? ''));

    $allowed = [
        'checkin_started', 'birthday_selected', 'collision_detected',
        'phone_fallback_triggered', 'phone_lookup_failed', 'phone_lockout',
        'checkin_completed', 'checkin_duplicate', 'checkin_abandoned',
        'registration_from_fallback', 'staff_lockout',
    ];
    if (!in_array($type, $allowed, true)) {
        sendErrorResponse('Unbekanntes Event.', 400);
    }

    $customerId = isset($requestData['customer_id']) ? (int)$requestData['customer_id'] : null;
    $payload = isset($requestData['payload']) && is_array($requestData['payload']) ? $requestData['payload'] : [];

    // A client-reported phone lockout is also persisted server-side.
    if ($type === 'phone_lockout') {
        recordLockout($conn, $salonId, 'phone');
    }

    recordEvent($conn, $salonId, $type, $customerId, $payload);
    $conn->close();
    sendJsonResponse(['success' => true], 200);
}

// ------------------------------------------------------------------
sendErrorResponse('Unbekannte Aktion.', 400);
