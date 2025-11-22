<?php
/**
 * Apply Migration 005 - Add preferred_language column to users table
 */

require_once __DIR__ . '/config.php';

echo "Applying migration 005: Add preferred_language to users table\n";

$conn = getDbConnection();
if (!$conn) {
    die("ERROR: Database connection failed\n");
}

// Check if column already exists
$checkQuery = "SHOW COLUMNS FROM coiffure_users LIKE 'preferred_language'";
$result = $conn->query($checkQuery);

if ($result->num_rows > 0) {
    echo "INFO: Column 'preferred_language' already exists. Skipping migration.\n";
    $conn->close();
    return;
}

echo "Adding 'preferred_language' column...\n";

// Add the column
$alterQuery = "ALTER TABLE coiffure_users ADD COLUMN preferred_language VARCHAR(5) DEFAULT 'de' AFTER role";
if (!$conn->query($alterQuery)) {
    die("ERROR: Failed to add column: " . $conn->error . "\n");
}

echo "SUCCESS: Column 'preferred_language' added.\n";

// Set default language for existing users
echo "Setting default language to 'de' for existing users...\n";
$updateQuery = "UPDATE coiffure_users SET preferred_language = 'de' WHERE preferred_language IS NULL";
if (!$conn->query($updateQuery)) {
    die("ERROR: Failed to update existing users: " . $conn->error . "\n");
}

echo "SUCCESS: Migration 005 applied successfully!\n";
echo "Users can now have individual language preferences.\n";

$conn->close();
