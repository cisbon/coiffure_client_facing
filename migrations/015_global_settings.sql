-- ============================================================
-- Migration 015: Global settings (key-value)
-- ============================================================
-- Application-wide settings editable by an administrator (role = 'admin').
-- Currently holds the check-in / kiosk timeout durations (in SECONDS) so an
-- admin can tune the auto-return and per-screen timeouts without a code change.
--
-- Key-value shape keeps it easy to add further global toggles later. Unknown
-- or missing keys fall back to code defaults in api/global-settings.php.
--
-- Safe to run once. Use api/apply_migration_015.php for an idempotent runner.
-- ============================================================

CREATE TABLE IF NOT EXISTS coiffure_global_settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the timeout defaults (seconds). INSERT IGNORE keeps existing values.
INSERT IGNORE INTO coiffure_global_settings (setting_key, setting_value) VALUES
    ('timeout_idle_return_s',      '30'),
    ('timeout_birthday_s',         '45'),
    ('timeout_autoconfirm_s',      '30'),
    ('timeout_namelist_s',         '30'),
    ('timeout_names_confirm_s',    '15'),
    ('timeout_phone_s',            '60'),
    ('timeout_welcome_success_s',  '8'),
    ('timeout_welcome_duplicate_s','5'),
    ('timeout_staff_pin_s',        '60'),
    ('timeout_staff_search_s',     '60'),
    ('timeout_autocheckout_s',     '1800');

-- ============================================================
-- End of migration 015
-- ============================================================
