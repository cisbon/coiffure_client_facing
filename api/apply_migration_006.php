<?php
/**
 * Apply Migration 006 - Add default_language column to salons table
 */

require_once __DIR__ . '/config.php';

echo "Applying migration 006: Add default_language to salons table\n";

$conn = getDbConnection();
if (!$conn) {
    die("ERROR: Database connection failed\n");
}

// Check if column already exists
$checkQuery = "SHOW COLUMNS FROM coiffure_salons LIKE 'default_language'";
$result = $conn->query($checkQuery);

if ($result->num_rows > 0) {
    echo "INFO: Column 'default_language' already exists. Skipping migration.\n";
    exit(0);
}

echo "Adding 'default_language' column...\n";

// Add the column
$alterQuery = "ALTER TABLE coiffure_salons ADD COLUMN default_language VARCHAR(5) DEFAULT 'de' AFTER is_active";
if (!$conn->query($alterQuery)) {
    die("ERROR: Failed to add column: " . $conn->error . "\n");
}

echo "SUCCESS: Column 'default_language' added.\n";

// Set default language for existing salons
echo "Setting default language to 'de' for existing salons...\n";
$updateQuery = "UPDATE coiffure_salons SET default_language = 'de' WHERE default_language IS NULL";
if (!$conn->query($updateQuery)) {
    die("ERROR: Failed to update existing salons: " . $conn->error . "\n");
}

echo "SUCCESS: Migration 006 applied successfully!\n";
echo "All salons now have default_language set to 'de'\n";

$conn->close();
