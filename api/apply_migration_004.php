<?php
/**
 * Apply Migration 004 - Remove salon_id column from users table
 */

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/migration_helpers.php';
requireMigrationAuth();  // admin session, MIGRATION_TOKEN or CLI only

echo "Applying migration 004: Remove salon_id column from users table\n";

$conn = getDbConnection();
if (!$conn) {
    echo "ERROR: Database connection failed\n";
    return;
}

// Check if column still exists
$checkQuery = "SHOW COLUMNS FROM coiffure_users LIKE 'salon_id'";
$result = $conn->query($checkQuery);

if ($result->num_rows === 0) {
    echo "INFO: Column 'salon_id' already removed. Skipping migration.\n";
    $conn->close();
    return;
}

echo "Removing 'salon_id' column from coiffure_users table...\n";

// Remove the column
$alterQuery = "ALTER TABLE coiffure_users DROP COLUMN salon_id";
if (!$conn->query($alterQuery)) {
    echo "ERROR: Failed to remove column: " . $conn->error . "\n";
    $conn->close();
    return;
}

echo "SUCCESS: Column 'salon_id' removed from coiffure_users table.\n";
echo "SUCCESS: Migration 004 applied successfully!\n";
echo "All salon assignments are now managed through the coiffure_user_salons junction table.\n";

$conn->close();
