<?php
/**
 * Apply Migration 019 - Saved customer segments
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS guard.
 * Run from the browser (admin session or ?token=<MIGRATION_TOKEN>) or CLI:
 *   php api/apply_migration_019.php
 */

require_once __DIR__ . '/migration_helpers.php';

$conn = migStart('019', 'Saved customer segments');
if (!$conn) {
    return;
}

migEnsureTable($conn, 'coiffure_segments', "
    CREATE TABLE IF NOT EXISTS coiffure_segments (
        segment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        salon_id INT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        description VARCHAR(255) DEFAULT NULL,
        filter_json TEXT NOT NULL,
        is_preset TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,
        UNIQUE KEY unique_segment_name (salon_id, name),
        INDEX idx_segment_salon (salon_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

migFinish($conn, '019');
