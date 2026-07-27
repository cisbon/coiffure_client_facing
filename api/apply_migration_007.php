<?php
/**
 * Apply Migration 007 - Add branding columns to salons table
 */

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/migration_helpers.php';
requireMigrationAuth();  // admin session, MIGRATION_TOKEN or CLI only

echo "Applying migration 007: Add branding/white-labeling to salons table\n";

$conn = getDbConnection();
if (!$conn) {
    echo "ERROR: Database connection failed\n";
    return;
}

// Check if columns already exist
$checkQuery = "SHOW COLUMNS FROM coiffure_salons LIKE 'logo_path'";
$result = $conn->query($checkQuery);

if ($result->num_rows > 0) {
    echo "INFO: Branding columns already exist. Skipping migration.\n";
    $conn->close();
    return;
}

echo "Adding branding columns (logo_path, primary_color, secondary_color, background_color, button_color, text_color)...\n";

// Add the columns
$alterQuery = "ALTER TABLE coiffure_salons
    ADD COLUMN logo_path VARCHAR(255) DEFAULT NULL AFTER default_language,
    ADD COLUMN primary_color VARCHAR(7) DEFAULT '#9333EA' AFTER logo_path,
    ADD COLUMN secondary_color VARCHAR(7) DEFAULT '#EC4899' AFTER primary_color,
    ADD COLUMN background_color VARCHAR(7) DEFAULT '#FFFFFF' AFTER secondary_color,
    ADD COLUMN button_color VARCHAR(7) DEFAULT '#9333EA' AFTER background_color,
    ADD COLUMN text_color VARCHAR(7) DEFAULT '#1F2937' AFTER button_color";

if (!$conn->query($alterQuery)) {
    echo "ERROR: Failed to add branding columns: " . $conn->error . "\n";
    $conn->close();
    return;
}

echo "SUCCESS: Branding columns added.\n";

// Set default colors for existing salons
echo "Setting default brand colors for existing salons...\n";
$updateQuery = "UPDATE coiffure_salons SET
    primary_color = '#9333EA',
    secondary_color = '#EC4899',
    background_color = '#FFFFFF',
    button_color = '#9333EA',
    text_color = '#1F2937'
WHERE primary_color IS NULL";

if (!$conn->query($updateQuery)) {
    echo "ERROR: Failed to update existing salons: " . $conn->error . "\n";
    $conn->close();
    return;
}

echo "SUCCESS: Migration 007 applied successfully!\n";
echo "Salons can now customize their logo and color scheme for white-labeling.\n";

$conn->close();
