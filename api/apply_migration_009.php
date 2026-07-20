<?php
/**
 * Apply Migration 009 - Self check-in visits table
 *
 * Idempotent: creates coiffure_visits only if it does not already exist.
 * Run from the browser (once) or CLI:  php api/apply_migration_009.php
 */

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "Applying migration 009: Self check-in visits\n";
echo "============================================\n";

$conn = getDbConnection();
if (!$conn) {
    echo "ERROR: Database connection failed\n";
    return;
}

$create = "CREATE TABLE IF NOT EXISTS coiffure_visits (
    visit_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    salon_id INT UNSIGNED NOT NULL,
    checkin_method ENUM('birthday', 'phone', 'manual') DEFAULT 'birthday',
    checked_in_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES coiffure_customers(customer_id) ON DELETE CASCADE,
    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,
    INDEX idx_visit_customer (customer_id),
    INDEX idx_visit_salon (salon_id),
    INDEX idx_visit_checked_in (checked_in_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($create)) {
    echo "  + coiffure_visits ready\n";
} else {
    echo "  ! Failed to create coiffure_visits: " . $conn->error . "\n";
    $conn->close();
    return;
}

// Composite index (guard against re-adding).
$idxCheck = $conn->query("SHOW INDEX FROM coiffure_visits WHERE Key_name = 'idx_visit_customer_day'");
if ($idxCheck && $idxCheck->num_rows === 0) {
    if ($conn->query("CREATE INDEX idx_visit_customer_day ON coiffure_visits (customer_id, checked_in_at)")) {
        echo "  + Added idx_visit_customer_day\n";
    } else {
        echo "  ! Could not add idx_visit_customer_day: " . $conn->error . "\n";
    }
} else {
    echo "  - idx_visit_customer_day already exists, skipping\n";
}

echo "\nSUCCESS: Migration 009 applied.\n";
$conn->close();
