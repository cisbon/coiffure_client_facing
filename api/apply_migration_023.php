<?php
/**
 * Apply Migration 023 - White-label configuration per salon
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS guard.
 * Run from the browser (admin session or ?token=<MIGRATION_TOKEN>) or CLI:
 *   php api/apply_migration_023.php
 */

require_once __DIR__ . '/migration_helpers.php';

$conn = migStart('023', 'White-label configuration per salon');
if (!$conn) {
    return;
}

migEnsureTable($conn, 'coiffure_salon_whitelabel', "
    CREATE TABLE IF NOT EXISTS coiffure_salon_whitelabel (
        whitelabel_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        salon_id INT UNSIGNED NOT NULL,
        custom_domain VARCHAR(255) DEFAULT NULL,
        domain_verified TINYINT(1) NOT NULL DEFAULT 0,
        smtp_host VARCHAR(255) DEFAULT NULL,
        smtp_port INT UNSIGNED DEFAULT 587,
        smtp_secure VARCHAR(10) DEFAULT 'tls',
        smtp_username VARCHAR(255) DEFAULT NULL,
        smtp_password VARCHAR(255) DEFAULT NULL,
        from_address VARCHAR(255) DEFAULT NULL,
        from_name VARCHAR(255) DEFAULT NULL,
        last_test_at TIMESTAMP NULL DEFAULT NULL,
        last_test_ok TINYINT(1) DEFAULT NULL,
        primary_color VARCHAR(7) DEFAULT NULL,
        secondary_color VARCHAR(7) DEFAULT NULL,
        updated_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,
        UNIQUE KEY unique_whitelabel_salon (salon_id),
        UNIQUE KEY unique_whitelabel_domain (custom_domain)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

migFinish($conn, '023');
