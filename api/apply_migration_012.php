<?php
/**
 * Apply Migration 012 - Per-salon loyalty configuration + staff PIN
 *
 * Idempotent: adds each column only if it does not already exist.
 * Run from the browser (once) or CLI:  php api/apply_migration_012.php
 */

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "Applying migration 012: Loyalty configuration + staff PIN\n";
echo "=========================================================\n";

$conn = getDbConnection();
if (!$conn) {
    echo "ERROR: Database connection failed\n";
    return;
}

function ensureSalonColumn(mysqli $conn, string $column, string $definition): void {
    $safe = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM coiffure_salons LIKE '$safe'");
    if ($res && $res->num_rows > 0) {
        echo "  - coiffure_salons.$column already exists, skipping\n";
        return;
    }
    if ($conn->query("ALTER TABLE coiffure_salons ADD COLUMN `$column` $definition")) {
        echo "  + Added coiffure_salons.$column\n";
    } else {
        echo "  ! Failed to add $column column: " . $conn->error . "\n";
    }
}

ensureSalonColumn($conn, 'loyalty_active', "TINYINT(1) NOT NULL DEFAULT 1");
ensureSalonColumn($conn, 'loyalty_visit_threshold', "INT UNSIGNED NOT NULL DEFAULT 5");
ensureSalonColumn($conn, 'loyalty_discount_type', "ENUM('fixed_eur','percentage') NOT NULL DEFAULT 'fixed_eur'");
ensureSalonColumn($conn, 'loyalty_discount_value', "DECIMAL(6,2) NOT NULL DEFAULT 10.00");
ensureSalonColumn($conn, 'loyalty_discount_label', "VARCHAR(50) DEFAULT NULL");
ensureSalonColumn($conn, 'staff_pin', "VARCHAR(8) NOT NULL DEFAULT '0000'");

echo "\nSUCCESS: Migration 012 applied.\n";
$conn->close();
