<?php
/**
 * Apply Migration 013 - Check-in analytics, settings audit log, phone lockouts
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS for each support table.
 * Run from the browser (once) or CLI:  php api/apply_migration_013.php
 */

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/migration_helpers.php';
requireMigrationAuth();  // admin session, MIGRATION_TOKEN or CLI only

header('Content-Type: text/plain; charset=utf-8');

echo "Applying migration 013: Check-in analytics / audit / lockouts\n";
echo "============================================================\n";

$conn = getDbConnection();
if (!$conn) {
    echo "ERROR: Database connection failed\n";
    return;
}

$statements = [
    'coiffure_checkin_events' => "
        CREATE TABLE IF NOT EXISTS coiffure_checkin_events (
            event_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            salon_id INT UNSIGNED NOT NULL,
            event_type VARCHAR(40) NOT NULL,
            customer_id INT UNSIGNED DEFAULT NULL,
            payload JSON DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_salon (salon_id),
            INDEX idx_event_type (event_type),
            INDEX idx_event_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'coiffure_settings_audit' => "
        CREATE TABLE IF NOT EXISTS coiffure_settings_audit (
            audit_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            salon_id INT UNSIGNED NOT NULL,
            changed_by INT UNSIGNED DEFAULT NULL,
            setting_key VARCHAR(64) NOT NULL,
            old_value VARCHAR(255) DEFAULT NULL,
            new_value VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_saudit_salon (salon_id),
            INDEX idx_saudit_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'coiffure_checkin_lockouts' => "
        CREATE TABLE IF NOT EXISTS coiffure_checkin_lockouts (
            lockout_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            salon_id INT UNSIGNED NOT NULL,
            scope VARCHAR(20) NOT NULL DEFAULT 'phone',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lockout_salon (salon_id),
            INDEX idx_lockout_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($statements as $table => $sql) {
    // JSON type is unavailable on very old MySQL; fall back to LONGTEXT.
    if ($conn->query($sql)) {
        echo "  + Ensured table $table\n";
    } else {
        if (stripos($conn->error, 'JSON') !== false) {
            $fallback = str_replace('payload JSON', 'payload LONGTEXT', $sql);
            if ($conn->query($fallback)) {
                echo "  + Ensured table $table (LONGTEXT payload fallback)\n";
                continue;
            }
        }
        echo "  ! Failed to create $table: " . $conn->error . "\n";
    }
}

echo "\nSUCCESS: Migration 013 applied.\n";
$conn->close();
