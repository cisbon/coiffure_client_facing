<?php
/**
 * Apply Migration 015 - Global settings (key-value)
 *
 * Idempotent: creates the table if missing and seeds default timeout values
 * with INSERT IGNORE (existing values are preserved).
 * Run from the browser (once) or CLI:  php api/apply_migration_015.php
 */

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "Applying migration 015: Global settings\n";
echo "========================================\n";

$conn = getDbConnection();
if (!$conn) {
    echo "ERROR: Database connection failed\n";
    return;
}

$create = "CREATE TABLE IF NOT EXISTS coiffure_global_settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($create)) {
    echo "  + Ensured table coiffure_global_settings\n";
} else {
    echo "  ! Failed to create table: " . $conn->error . "\n";
    $conn->close();
    return;
}

$defaults = [
    'timeout_idle_return_s'       => '30',
    'timeout_birthday_s'          => '45',
    'timeout_autoconfirm_s'       => '30',
    'timeout_namelist_s'          => '30',
    'timeout_names_confirm_s'     => '15',
    'timeout_phone_s'             => '60',
    'timeout_welcome_success_s'   => '8',
    'timeout_welcome_duplicate_s' => '5',
    'timeout_staff_pin_s'         => '60',
    'timeout_staff_search_s'      => '60',
    'timeout_autocheckout_s'      => '1800',
    'timeout_autophoto_s'         => '5',
];

$stmt = $conn->prepare("INSERT IGNORE INTO coiffure_global_settings (setting_key, setting_value) VALUES (?, ?)");
foreach ($defaults as $k => $v) {
    $stmt->bind_param("ss", $k, $v);
    $stmt->execute();
    echo "  + Seeded (if absent) $k = $v\n";
}
$stmt->close();

echo "\nSUCCESS: Migration 015 applied.\n";
$conn->close();
