-- ============================================================
-- Migration 017: Granular permissions + user invitations
-- ============================================================
-- Until now access control was role-only (hasRole/requireRole/canManageSalon
-- in api/config.php). The admin dashboard needs a Customer Admin to hand a
-- Customer Admin Delegate a *subset* of rights, so delegates get explicit
-- permission grants on top of their role.
--
-- coiffure_user_permissions
--   One row per (user, salon, permission). Only consulted for role
--   'customer_admin_delegate' — every other role derives its permissions from
--   the matrix in api/permissions.php and needs no rows here.
--   permission is one of: manage_campaigns, view_insights, manage_products,
--   manage_magazine, manage_users, change_settings
--   (manage_products / manage_magazine are reserved: the matrix knows them so
--    no migration is needed when those modules land, but no UI grants them yet)
--
-- coiffure_user_invitations
--   Replaces the current practice of e-mailing a generated plaintext password
--   (api/salon-management.php sendOwnerWelcomeEmail). An invitation carries the
--   intended role, salon and permission set; the invitee sets their own
--   password via set-password.html + api/auth-set-password.php.
--   status: pending -> accepted, or expired/revoked.
--
-- Safe to run once. Use api/apply_migration_017.php for an idempotent runner.
-- ============================================================

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coiffure_user_invitations (
    invitation_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(128) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) DEFAULT NULL,
    role VARCHAR(40) NOT NULL,
    salon_id INT UNSIGNED DEFAULT NULL,
    permissions TEXT DEFAULT NULL,          -- JSON array of permission keys
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- End of migration 017
-- ============================================================
