<?php
/**
 * Global Settings API
 * -------------------------------------------------------------------
 *   GET  global-settings.php
 *        → public read used by the tablet kiosk to load timeout durations
 *          (merged with code defaults).
 *
 *   POST global-settings.php   (role = admin ONLY, JSON or form)
 *        {settings: {timeout_birthday_s: 45, ...}}  (or flat key/value pairs)
 *        → validates each known key against its range, persists, and writes a
 *          coiffure_settings_audit row (salon_id 0 = global) per changed key.
 *
 * Only whitelisted keys are accepted; everything else is ignored. Values are
 * clamped/validated to sane ranges so a bad value can never break the kiosk.
 */

require_once __DIR__ . '/config.php';

setCorsHeaders();

// Known settings: key => [default, min, max] (all in seconds).
const GLOBAL_TIMEOUT_DEFS = [
    'timeout_idle_return_s'       => [30, 5, 600],
    'timeout_birthday_s'          => [45, 5, 600],
    'timeout_autoconfirm_s'       => [30, 5, 600],
    'timeout_namelist_s'          => [30, 5, 600],
    'timeout_names_confirm_s'     => [15, 3, 300],
    'timeout_phone_s'             => [60, 5, 600],
    'timeout_welcome_success_s'   => [8,  2, 120],
    'timeout_welcome_duplicate_s' => [5,  2, 120],
    'timeout_staff_pin_s'         => [60, 5, 600],
    'timeout_staff_search_s'      => [60, 5, 600],
];

function globalSettingsTableExists(mysqli $conn): bool
{
    $res = $conn->query("SHOW TABLES LIKE 'coiffure_global_settings'");
    return $res && $res->num_rows > 0;
}

/** All known settings merged with stored values (falls back to defaults). */
function getGlobalSettings(mysqli $conn): array
{
    $out = [];
    foreach (GLOBAL_TIMEOUT_DEFS as $k => [$def]) {
        $out[$k] = $def;
    }
    if (!globalSettingsTableExists($conn)) {
        return $out;
    }
    $res = $conn->query("SELECT setting_key, setting_value FROM coiffure_global_settings");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $k = $row['setting_key'];
            if (array_key_exists($k, GLOBAL_TIMEOUT_DEFS)) {
                $out[$k] = (int)$row['setting_value'];
            }
        }
    }
    return $out;
}

$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
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
    $settings = getGlobalSettings($conn);
    $conn->close();
    sendJsonResponse(['success' => true, 'settings' => $settings], 200);
}

// ==================================================================
// POST — admin-only save
// ==================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = requireAuth($conn);
    // Global settings are reserved for full administrators.
    if (!hasRole($user, ['admin'])) {
        sendErrorResponse('Nur Administratoren dürfen globale Einstellungen ändern.', 403);
    }

    if (!globalSettingsTableExists($conn)) {
        sendErrorResponse('coiffure_global_settings fehlt. Bitte Migration 015 ausführen.', 500, [
            'hint' => 'php api/apply_migration_015.php',
        ]);
    }

    // Accept either {settings: {...}} or flat key/value pairs.
    $incoming = isset($requestData['settings']) && is_array($requestData['settings'])
        ? $requestData['settings']
        : $requestData;

    $before = getGlobalSettings($conn);
    $changedBy = (int)($user['user_id'] ?? 0) ?: null;

    // Validate everything first so a bad value rejects the whole request.
    $applied = [];
    foreach (GLOBAL_TIMEOUT_DEFS as $key => [$def, $min, $max]) {
        if (!array_key_exists($key, $incoming)) {
            continue;
        }
        $val = (int)$incoming[$key];
        if ($val < $min || $val > $max) {
            sendErrorResponse("Wert für $key muss zwischen $min und $max Sekunden liegen.", 400);
        }
        $applied[$key] = $val;
    }

    // Persist.
    $upsert = $conn->prepare(
        "INSERT INTO coiffure_global_settings (setting_key, setting_value, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)"
    );
    foreach ($applied as $key => $val) {
        $valStr = (string)$val;
        $upsert->bind_param("ssi", $key, $valStr, $changedBy);
        if (!$upsert->execute()) {
            $err = $upsert->error;
            $upsert->close();
            sendErrorResponse('Speichern fehlgeschlagen: ' . $err, 500);
        }
    }
    $upsert->close();

    // Audit each changed key (salon_id 0 = global).
    if ($conn->query("SHOW TABLES LIKE 'coiffure_settings_audit'")->num_rows > 0) {
        $auditStmt = $conn->prepare(
            "INSERT INTO coiffure_settings_audit (salon_id, changed_by, setting_key, old_value, new_value)
             VALUES (0, ?, ?, ?, ?)"
        );
        if ($auditStmt) {
            foreach ($applied as $key => $val) {
                if ((int)$before[$key] === $val) {
                    continue;
                }
                $old = (string)$before[$key];
                $new = (string)$val;
                $auditStmt->bind_param("isss", $changedBy, $key, $old, $new);
                $auditStmt->execute();
            }
            $auditStmt->close();
        }
    }

    $after = getGlobalSettings($conn);
    $conn->close();
    sendJsonResponse(['success' => true, 'message' => 'Einstellungen gespeichert.', 'settings' => $after], 200);
}

sendErrorResponse('Method not allowed', 405);
