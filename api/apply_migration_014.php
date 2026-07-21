<?php
/**
 * Apply Migration 014 - Salon connections (multi-store brands)
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS.
 * Run from the browser (once) or CLI:  php api/apply_migration_014.php
 */

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "Applying migration 014: Salon connections\n";
echo "==========================================\n";

$conn = getDbConnection();
if (!$conn) {
    echo "ERROR: Database connection failed\n";
    return;
}

$sql = "CREATE TABLE IF NOT EXISTS coiffure_salon_connections (
    connection_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id INT UNSIGNED NOT NULL,
    salon_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_conn_salon (salon_id),
    INDEX idx_conn_group (group_id),
    CONSTRAINT fk_conn_salon FOREIGN KEY (salon_id)
        REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    echo "  + Ensured table coiffure_salon_connections\n";
} else {
    // Retry without the FK in case of engine/permission constraints.
    $noFk = preg_replace('/,\s*CONSTRAINT fk_conn_salon[^)]*\)\s*ON DELETE CASCADE/s', '', $sql);
    if ($conn->query($noFk)) {
        echo "  + Ensured table coiffure_salon_connections (without FK)\n";
    } else {
        echo "  ! Failed: " . $conn->error . "\n";
    }
}

echo "\nSUCCESS: Migration 014 applied.\n";
$conn->close();
