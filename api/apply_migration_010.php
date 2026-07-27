<?php
/**
 * Apply Migration 010 - Customer gender
 *
 * Idempotent: adds the gender column only if it does not already exist.
 * Run from the browser (once) or CLI:  php api/apply_migration_010.php
 */

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/migration_helpers.php';
requireMigrationAuth();  // admin session, MIGRATION_TOKEN or CLI only

header('Content-Type: text/plain; charset=utf-8');

echo "Applying migration 010: Customer gender + title\n";
echo "===============================================\n";

$conn = getDbConnection();
if (!$conn) {
    echo "ERROR: Database connection failed\n";
    return;
}

function ensureCustomerColumn(mysqli $conn, string $column, string $definition): void {
    $safe = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM coiffure_customers LIKE '$safe'");
    if ($res && $res->num_rows > 0) {
        echo "  - coiffure_customers.$column already exists, skipping\n";
        return;
    }
    if ($conn->query("ALTER TABLE coiffure_customers ADD COLUMN `$column` $definition")) {
        echo "  + Added coiffure_customers.$column\n";
    } else {
        echo "  ! Failed to add $column column: " . $conn->error . "\n";
    }
}

ensureCustomerColumn($conn, 'gender', "ENUM('female','male','diverse') DEFAULT NULL AFTER last_name");
ensureCustomerColumn($conn, 'title', "VARCHAR(30) DEFAULT NULL AFTER gender");

echo "\nSUCCESS: Migration 010 applied.\n";
$conn->close();
