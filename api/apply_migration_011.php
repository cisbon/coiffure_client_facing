<?php
/**
 * Apply Migration 011 - Salon guest WiFi
 *
 * Idempotent: adds the WiFi columns only if they do not already exist.
 * Run from the browser (once) or CLI:  php api/apply_migration_011.php
 */

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/migration_helpers.php';
requireMigrationAuth();  // admin session, MIGRATION_TOKEN or CLI only

header('Content-Type: text/plain; charset=utf-8');

echo "Applying migration 011: Salon guest WiFi\n";
echo "========================================\n";

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

ensureSalonColumn($conn, 'wifi_ssid', "VARCHAR(255) DEFAULT NULL AFTER facebook_url");
ensureSalonColumn($conn, 'wifi_password', "VARCHAR(255) DEFAULT NULL AFTER wifi_ssid");

echo "\nSUCCESS: Migration 011 applied.\n";
$conn->close();
