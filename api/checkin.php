<?php
/**
 * Self Check-in API Endpoint
 * -------------------------------------------------------------------
 * Powers the tablet kiosk check-in flow. A single entry point dispatches on
 * an `action` parameter so it works on flat PHP hosting without URL rewrites:
 *
 *   GET  checkin.php?action=candidates&day=DD&month=MM[&salon_id=N]
 *        → members whose birthday is DD.MM  (id, first_name, last_initial)
 *
 *   POST checkin.php   {"action":"confirm","customer_id":N}
 *        → logs a visit, returns {success, first_name}
 *
 *   POST checkin.php   {"action":"phone","phone_number":"..."}
 *        → looks up the customer by phone, logs a visit, returns {success, first_name}
 *
 * GDPR: candidate data is minimal (first name + last initial) and is only
 * returned once a birthday has been supplied. Check-in is a core in-salon
 * service (legitimate interest); membership/marketing flags are NOT required.
 * The phone fallback only matches customers who previously stored a number.
 */

require_once __DIR__ . '/config.php';

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

/** Log a visit; tolerates a missing visits table (returns false, non-fatal). */
function logVisit(mysqli $conn, int $customerId, int $salonId, string $method): bool
{
    if (!visitsTableExists($conn)) {
        error_log('checkin: coiffure_visits table missing — run migration 009');
        return false;
    }
    $stmt = $conn->prepare(
        "INSERT INTO coiffure_visits (customer_id, salon_id, checkin_method) VALUES (?, ?, ?)"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("iis", $customerId, $salonId, $method);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
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

    $salonId = resolveSalonId($conn, []);

    $stmt = $conn->prepare(
        "SELECT customer_id, first_name, last_name
         FROM coiffure_customers
         WHERE salon_id = ? AND birth_day = ? AND birth_month = ? AND is_deleted = 0
         ORDER BY first_name ASC, last_name ASC
         LIMIT 50"
    );
    if (!$stmt) {
        sendErrorResponse('Database query preparation failed', 500);
    }
    $stmt->bind_param("iii", $salonId, $day, $month);
    $stmt->execute();
    $res = $stmt->get_result();

    $candidates = [];
    while ($row = $res->fetch_assoc()) {
        // Fall back to splitting full_name if first/last are empty (legacy rows).
        $first = $row['first_name'];
        $last  = $row['last_name'];
        $lastInitial = '';
        if ($last) {
            $lastInitial = (function_exists('mb_substr') ? mb_substr($last, 0, 1) : substr($last, 0, 1)) . '.';
        }
        $candidates[] = [
            'id'                 => (int)$row['customer_id'],
            'first_name'         => $first ?: '',
            'last_name_initial'  => $lastInitial,
        ];
    }
    $stmt->close();
    $conn->close();

    sendJsonResponse([
        'success'    => true,
        'day'        => $day,
        'month'      => $month,
        'candidates' => $candidates,
    ], 200);
}

// ==================================================================
// POST confirm (by customer_id)
// ==================================================================
if ($action === 'confirm') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Use POST for confirm', 405);
    }

    $customerId = (int)($requestData['customer_id'] ?? 0);
    if ($customerId < 1) {
        sendErrorResponse('customer_id erforderlich', 400);
    }

    $salonId = resolveSalonId($conn, $requestData);

    $stmt = $conn->prepare(
        "SELECT customer_id, first_name, full_name, salon_id
         FROM coiffure_customers
         WHERE customer_id = ? AND is_deleted = 0 LIMIT 1"
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

    // Use the customer's own salon for the visit log.
    $visitSalon = (int)($customer['salon_id'] ?: $salonId);
    logVisit($conn, $customerId, $visitSalon, 'birthday');
    logAudit($conn, 'customer', $customerId, 'read', 'Self check-in (birthday)', 'checkin_kiosk');

    $firstName = $customer['first_name'] ?: trim(explode(' ', $customer['full_name'])[0]);
    $conn->close();

    sendJsonResponse(['success' => true, 'first_name' => $firstName], 200);
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

    $salonId = resolveSalonId($conn, $requestData);

    // Compare on digits only so stored formats (spaces, +, /, -, ., parens) match.
    // Match on the trailing digits to tolerate country-code differences.
    $customer = null;
    $sqlDigits = "SELECT customer_id, first_name, full_name, salon_id
                  FROM coiffure_customers
                  WHERE salon_id = ? AND is_deleted = 0
                    AND (mobile IS NOT NULL OR phone IS NOT NULL)
                    AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                        COALESCE(mobile, phone), ' ', ''), '-', ''), '/', ''), '(', ''), ')', ''), '.', ''), '+', '')
                        LIKE CONCAT('%', ?)
                  LIMIT 1";
    $stmt = $conn->prepare($sqlDigits);
    if ($stmt) {
        // Match on the last 7+ digits to be tolerant of country-code differences.
        $tail = substr($digits, -max(7, min(strlen($digits), 9)));
        $stmt->bind_param("is", $salonId, $tail);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows > 0) {
            $customer = $res->fetch_assoc();
        }
        $stmt->close();
    }

    if (!$customer) {
        $conn->close();
        sendErrorResponse('Kein Eintrag gefunden. Bitte wenden Sie sich an das Personal.', 404);
    }

    $customerId = (int)$customer['customer_id'];
    $visitSalon = (int)($customer['salon_id'] ?: $salonId);
    logVisit($conn, $customerId, $visitSalon, 'phone');
    logAudit($conn, 'customer', $customerId, 'read', 'Self check-in (phone)', 'checkin_kiosk');

    $firstName = $customer['first_name'] ?: trim(explode(' ', $customer['full_name'])[0]);
    $conn->close();

    sendJsonResponse(['success' => true, 'first_name' => $firstName], 200);
}

// ------------------------------------------------------------------
sendErrorResponse('Unbekannte Aktion. Erlaubt: candidates, confirm, phone.', 400);
