<?php
/**
 * Apply Migration 017 - Granular permissions + user invitations
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS guards.
 * Run from the browser (admin session or ?token=<MIGRATION_TOKEN>) or CLI:
 *   php api/apply_migration_017.php
 */

require_once __DIR__ . '/migration_helpers.php';

$conn = migStart('017', 'Granular permissions + user invitations');
if (!$conn) {
    return;
}

migEnsureTable($conn, 'coiffure_user_permissions', "
    CREATE TABLE IF NOT EXISTS coiffure_user_permissions (
        permission_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        salon_id INT UNSIGNED NOT NULL,
        permission VARCHAR(40) NOT NULL,
        granted_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES coiffure_users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,
        UNIQUE KEY unique_user_salon_permission (user_id, salon_id, permission),
        INDEX idx_perm_user (user_id),
        INDEX idx_perm_salon (salon_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

migEnsureTable($conn, 'coiffure_user_invitations', "
    CREATE TABLE IF NOT EXISTS coiffure_user_invitations (
        invitation_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(128) NOT NULL UNIQUE,
        email VARCHAR(255) NOT NULL,
        full_name VARCHAR(255) DEFAULT NULL,
        role VARCHAR(40) NOT NULL,
        salon_id INT UNSIGNED DEFAULT NULL,
        permissions TEXT DEFAULT NULL,
        invited_by INT UNSIGNED DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        expires_at TIMESTAMP NULL DEFAULT NULL,
        accepted_at TIMESTAMP NULL DEFAULT NULL,
        created_user_id INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,
        INDEX idx_invitation_email (email),
        INDEX idx_invitation_status (status),
        INDEX idx_invitation_salon (salon_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// force_password_change was only ever created by the broken PDO script in
// api/migrations/add_force_password_change.php; salon-management.php writes to
// it. Ensure it exists so onboarding does not fail on a fresh database.
migEnsureColumn($conn, 'coiffure_users', 'force_password_change', 'TINYINT(1) NOT NULL DEFAULT 0');

migFinish($conn, '017');
