-- ============================================================
-- Migration 023: White-label configuration per salon
-- ============================================================
-- Administrator-only settings that let a salon run the tablet under its own
-- domain, send mail from its own server and override the dashboard accent.
--
--   custom_domain     CNAME target the salon points at the platform; the
--                     dashboard shows the DNS instructions next to it.
--   domain_verified   set once a lookup confirms the CNAME.
--   smtp_*            per-salon outbound mail. When smtp_host is set,
--                     api/mailer.php uses these instead of the SMTP_* env
--                     defaults (see _sendHtmlMail's $smtpConfig argument).
--                     smtp_password is stored as written -- the mailer needs
--                     the cleartext to authenticate, so this column must never
--                     be exposed through an API response.
--   primary_color / secondary_color
--                     override the salon's branding colours (007) for the
--                     dashboard chrome and the salon's login screen.
--
-- Safe to run once. Use api/apply_migration_023.php for an idempotent runner.
-- ============================================================

CREATE TABLE IF NOT EXISTS coiffure_salon_whitelabel (
    whitelabel_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,

    custom_domain VARCHAR(255) DEFAULT NULL,
    domain_verified TINYINT(1) NOT NULL DEFAULT 0,

    smtp_host VARCHAR(255) DEFAULT NULL,
    smtp_port INT UNSIGNED DEFAULT 587,
    smtp_secure VARCHAR(10) DEFAULT 'tls',      -- tls | ssl | none
    smtp_username VARCHAR(255) DEFAULT NULL,
    smtp_password VARCHAR(255) DEFAULT NULL,    -- never returned by the API
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- End of migration 023
-- ============================================================
