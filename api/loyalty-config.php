<?php
/**
 * Loyalty Configuration API
 * -------------------------------------------------------------------
 *   GET  loyalty-config.php?salon_id=N
 *        → public read used by the tablet welcome screen. Returns the salon's
 *          loyalty settings (active flag, threshold, discount type/value/label).
 *
 *   POST loyalty-config.php   (admin / customer_admin, JSON or form)
 *        {salon_id, loyalty_active, visit_threshold, discount_type,
 *         discount_value, discount_label, staff_pin?}
 *        → validates + persists, writes an entry to coiffure_settings_audit for
 *          every changed field, returns the effective config.
 *
 * The GET is intentionally unauthenticated (same posture as checkin.php) because
 * the kiosk runs without a customer login; it exposes only non-sensitive,
 * salon-owned marketing config.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/loyalty_helpers.php';

setCorsHeaders();

$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

if (!loyaltyColumnsExist($conn)) {
    sendErrorResponse('Loyalty columns missing. Please run migration 012.', 500, [
        'hint' => 'php api/apply_migration_012.php',
    ]);
}

// ------------------------------------------------------------------
// Parse request body (JSON or form)
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

// ==================================================================
// GET — public read for the kiosk
// ==================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $salonId = (int)($_GET['salon_id'] ?? DEFAULT_SALON_ID);
    if ($salonId < 1) {
        sendErrorResponse('salon_id erforderlich', 400);
    }

    $cfg = getLoyaltyConfig($conn, $salonId);
    $conn->close();

    sendJsonResponse([
        'success'         => true,
        'salon_id'        => $salonId,
        'loyalty_active'  => $cfg['loyalty_active'],
        'visit_threshold' => $cfg['visit_threshold'],
        'discount_type'   => $cfg['discount_type'],
        'discount_value'  => $cfg['discount_value'],
        'discount_label'  => $cfg['discount_label'],
    ], 200);
}

// ==================================================================
// POST — admin save
// ==================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = requireAuth($conn);
    if (!hasRole($user, ['admin', 'customer_admin'])) {
        sendErrorResponse('Insufficient permissions. Only admins can change loyalty settings.', 403);
    }

    $salonId = (int)($requestData['salon_id'] ?? 0);
    if ($salonId < 1) {
        sendErrorResponse('salon_id erforderlich', 400);
    }

    // --- Read current values first (for the audit diff) ---
    $before = getLoyaltyConfig($conn, $salonId);

    // --- Normalise + validate inputs ---
    $active = !empty($requestData['loyalty_active']) &&
              $requestData['loyalty_active'] !== 'false' &&
              $requestData['loyalty_active'] !== '0' ? 1 : 0;

    $threshold = (int)($requestData['visit_threshold'] ?? $before['visit_threshold']);
    if ($threshold < 2 || $threshold > 50) {
        sendErrorResponse('Besuchsschwelle muss zwischen 2 und 50 liegen.', 400);
    }

    $type = $requestData['discount_type'] ?? $before['discount_type'];
    if (!in_array($type, ['fixed_eur', 'percentage'], true)) {
        sendErrorResponse('Ungültiger Rabatt-Typ.', 400);
    }

    $value = (float)($requestData['discount_value'] ?? $before['discount_value']);
    if ($type === 'fixed_eur') {
        if ($value < 0.50 || $value > 500) {
            sendErrorResponse('Fester Rabatt muss zwischen 0,50 € und 500 € liegen.', 400);
        }
    } else {
        if ($value < 1 || $value > 100) {
            sendErrorResponse('Prozentualer Rabatt muss zwischen 1 % und 100 % liegen.', 400);
        }
    }

    $label = isset($requestData['discount_label']) ? trim((string)$requestData['discount_label']) : '';
    $labelParam = $label === '' ? null : mb_substr($label, 0, 50);

    // Optional staff PIN (4 digits). Only touched when explicitly provided.
    $updateStaffPin = false;
    $staffPin = null;
    if (isset($requestData['staff_pin']) && trim((string)$requestData['staff_pin']) !== '') {
        $staffPin = preg_replace('/\D/', '', (string)$requestData['staff_pin']);
        if (strlen($staffPin) !== 4) {
            sendErrorResponse('Die Personal-PIN muss aus genau 4 Ziffern bestehen.', 400);
        }
        $updateStaffPin = true;
    }

    // --- Persist ---
    if ($updateStaffPin) {
        $stmt = $conn->prepare(
            "UPDATE coiffure_salons
             SET loyalty_active = ?, loyalty_visit_threshold = ?, loyalty_discount_type = ?,
                 loyalty_discount_value = ?, loyalty_discount_label = ?, staff_pin = ?, updated_at = NOW()
             WHERE salon_id = ?"
        );
        $stmt->bind_param("iisdssi", $active, $threshold, $type, $value, $labelParam, $staffPin, $salonId);
    } else {
        $stmt = $conn->prepare(
            "UPDATE coiffure_salons
             SET loyalty_active = ?, loyalty_visit_threshold = ?, loyalty_discount_type = ?,
                 loyalty_discount_value = ?, loyalty_discount_label = ?, updated_at = NOW()
             WHERE salon_id = ?"
        );
        $stmt->bind_param("iisdsi", $active, $threshold, $type, $value, $labelParam, $salonId);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        sendErrorResponse('Speichern fehlgeschlagen: ' . $err, 500);
    }
    $stmt->close();

    // --- Audit every changed field ---
    $changedBy = (int)($user['user_id'] ?? 0) ?: null;
    $auditPairs = [
        'loyalty_active'   => [$before['loyalty_active'] ? '1' : '0', (string)$active],
        'visit_threshold'  => [(string)$before['visit_threshold'], (string)$threshold],
        'discount_type'    => [$before['discount_type'], $type],
        'discount_value'   => [rtrim(rtrim(number_format($before['discount_value'], 2, '.', ''), '0'), '.'),
                               rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.')],
    ];
    if ($updateStaffPin) {
        // Never log the PIN itself — record only that it changed.
        $auditPairs['staff_pin'] = ['****', '****'];
    }

    $auditExists = $conn->query("SHOW TABLES LIKE 'coiffure_settings_audit'");
    if ($auditExists && $auditExists->num_rows > 0) {
        $aStmt = $conn->prepare(
            "INSERT INTO coiffure_settings_audit (salon_id, changed_by, setting_key, old_value, new_value)
             VALUES (?, ?, ?, ?, ?)"
        );
        if ($aStmt) {
            foreach ($auditPairs as $key => [$old, $new]) {
                if ($old === $new && $key !== 'staff_pin') {
                    continue; // only log actual changes
                }
                $aStmt->bind_param("iisss", $salonId, $changedBy, $key, $old, $new);
                $aStmt->execute();
            }
            $aStmt->close();
        }
    }

    $after = getLoyaltyConfig($conn, $salonId);
    $conn->close();

    sendJsonResponse([
        'success'         => true,
        'message'         => 'Einstellungen gespeichert.',
        'loyalty_active'  => $after['loyalty_active'],
        'visit_threshold' => $after['visit_threshold'],
        'discount_type'   => $after['discount_type'],
        'discount_value'  => $after['discount_value'],
        'discount_label'  => $after['discount_label'],
    ], 200);
}

sendErrorResponse('Method not allowed', 405);
