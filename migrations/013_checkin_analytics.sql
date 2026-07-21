-- ============================================================
-- Migration 013: Check-in analytics, settings audit log, phone lockouts
-- ============================================================
-- Three privacy-conscious support tables for the rebuilt kiosk check-in:
--
--   coiffure_checkin_events   append-only analytics stream. NEVER stores PII
--                             beyond customer_id (a UUID-like integer key).
--   coiffure_settings_audit   who changed which salon setting, old → new value.
--   coiffure_checkin_lockouts brute-force protection for the phone fallback.
--                             Stores salon_id + timestamp only (no phone number).
--
-- Safe to run once. Use api/apply_migration_013.php for an idempotent runner.
-- ============================================================

CREATE TABLE IF NOT EXISTS coiffure_checkin_events (
    event_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    event_type VARCHAR(40) NOT NULL,   -- checkin_started, birthday_selected, ...
    customer_id INT UNSIGNED DEFAULT NULL,
    payload JSON DEFAULT NULL,          -- non-PII contextual data
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_event_salon (salon_id),
    INDEX idx_event_type (event_type),
    INDEX idx_event_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coiffure_settings_audit (
    audit_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    changed_by INT UNSIGNED DEFAULT NULL,   -- user_id of the salon owner/admin
    setting_key VARCHAR(64) NOT NULL,
    old_value VARCHAR(255) DEFAULT NULL,
    new_value VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_saudit_salon (salon_id),
    INDEX idx_saudit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coiffure_checkin_lockouts (
    lockout_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    scope VARCHAR(20) NOT NULL DEFAULT 'phone', -- 'phone' | 'staff'
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_lockout_salon (salon_id),
    INDEX idx_lockout_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- End of migration 013
-- ============================================================
