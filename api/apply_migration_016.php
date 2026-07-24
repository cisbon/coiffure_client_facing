<?php
/**
 * Apply Migration 016 - Trends slider content (coiffure_trends)
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS.
 * Run from the browser (once) or CLI:  php api/apply_migration_016.php
 */

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "Applying migration 016: coiffure_trends\n";
echo "========================================\n";

$conn = getDbConnection();
if (!$conn) {
    echo "ERROR: Database connection failed\n";
    return;
}

$sql = "CREATE TABLE IF NOT EXISTS coiffure_trends (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title TEXT,
    `text` TEXT,
    sort INT(11) DEFAULT 0,
    image_url TEXT,
    link TEXT DEFAULT NULL,
    active TINYINT(1) DEFAULT 1,
    gender TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_trends_active_sort (active, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    echo "  + Ensured table coiffure_trends\n";
} else {
    echo "  ! Failed to create coiffure_trends: " . $conn->error . "\n";
}

echo "\nSUCCESS: Migration 016 applied.\n";
$conn->close();
