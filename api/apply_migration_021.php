<?php
/**
 * Apply Migration 021 - Notification centre
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS guards.
 * Run from the browser (admin session or ?token=<MIGRATION_TOKEN>) or CLI:
 *   php api/apply_migration_021.php
 */

require_once __DIR__ . '/migration_helpers.php';

$conn = migStart('021', 'Notification centre');
if (!$conn) {
    return;
}

migEnsureTable($conn, 'coiffure_notifications', "
    CREATE TABLE IF NOT EXISTS coiffure_notifications (
        notification_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        salon_id INT UNSIGNED DEFAULT NULL,
        type VARCHAR(40) NOT NULL,
        title_key VARCHAR(120) NOT NULL,
        params TEXT DEFAULT NULL,
        link VARCHAR(255) DEFAULT NULL,
        read_at TIMESTAMP NULL DEFAULT NULL,
        emailed_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES coiffure_users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,
        INDEX idx_notification_user_read (user_id, read_at),
        INDEX idx_notification_created (created_at),
        INDEX idx_notification_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

migEnsureTable($conn, 'coiffure_notification_prefs', "
    CREATE TABLE IF NOT EXISTS coiffure_notification_prefs (
        pref_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        mode VARCHAR(20) NOT NULL DEFAULT 'off',
        events TEXT DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES coiffure_users(user_id) ON DELETE CASCADE,
        UNIQUE KEY unique_notification_pref_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

migFinish($conn, '021');
