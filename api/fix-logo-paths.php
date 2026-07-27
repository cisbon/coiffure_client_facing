<?php
/**
 * Fix existing logo paths in database
 * Converts absolute server paths to relative paths
 */

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/migration_helpers.php';
requireMigrationAuth();  // admin session, MIGRATION_TOKEN or CLI only

header('Content-Type: text/plain');

$conn = getDbConnection();
if (!$conn) {
    echo "ERROR: Database connection failed\n";
    exit(1);
}

echo "Fixing logo paths in coiffure_salons table...\n\n";

// Get all salons with logo paths
$result = $conn->query("SELECT salon_id, salon_name, logo_path FROM coiffure_salons WHERE logo_path IS NOT NULL");

if (!$result) {
    echo "ERROR: Failed to query salons: " . $conn->error . "\n";
    exit(1);
}

$updated = 0;
$skipped = 0;

while ($row = $result->fetch_assoc()) {
    $salonId = $row['salon_id'];
    $salonName = $row['salon_name'];
    $oldPath = $row['logo_path'];

    // Check if path is already relative (doesn't start with /)
    if (strpos($oldPath, '/') !== 0) {
        echo "✓ Salon #{$salonId} ({$salonName}): Already relative path\n";
        echo "  Path: {$oldPath}\n\n";
        $skipped++;
        continue;
    }

    // Extract just the filename from the absolute path
    $filename = basename($oldPath);
    $newPath = 'uploads/logos/' . $filename;

    // Update the database
    $stmt = $conn->prepare("UPDATE coiffure_salons SET logo_path = ? WHERE salon_id = ?");
    $stmt->bind_param("si", $newPath, $salonId);

    if ($stmt->execute()) {
        echo "✓ Salon #{$salonId} ({$salonName}): Updated\n";
        echo "  Old: {$oldPath}\n";
        echo "  New: {$newPath}\n\n";
        $updated++;
    } else {
        echo "✗ Salon #{$salonId} ({$salonName}): Failed to update\n";
        echo "  Error: " . $stmt->error . "\n\n";
    }

    $stmt->close();
}

echo "---\n";
echo "Summary:\n";
echo "  Updated: {$updated}\n";
echo "  Skipped: {$skipped}\n";
echo "  Total:   " . ($updated + $skipped) . "\n";

$conn->close();
