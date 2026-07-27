-- ============================================================
-- Migration 018: Salon settings (platform status, tablet, birthday, referral)
-- ============================================================
-- Backs the "Einstellungen" module of the admin dashboard. Everything a salon
-- owner can configure lives on coiffure_salons (the table already carries
-- branding from 007, WiFi from 011 and loyalty from 012), plus one child table
-- for opening hours.
--
-- Platform-level (Administrator sets these when creating the salon):
--   subdomain   unique prefix, auto-generated from the salon name
--   status      active | trial | suspended -- drives the Salons filter. Kept
--               separate from the existing is_active flag, which stays the
--               soft-delete marker used by salon-management.php.
--   currency    ISO 4217, used to format prices and invoices
--   website     shown on the tablet
--
-- Tablet (§3.3 "Tablet"):
--   tablet_headline / tablet_bg_image / tablet_bg_color   welcome screen
--   tablet_idle_timeout_s   per-salon override of the global idle return; NULL
--                           falls back to coiffure_global_settings
--   tablet_modules          JSON toggles, e.g. {"register":1,"checkin":1,"browse":1}
--
-- Refer-a-friend (§3.3 "Mitgliedschaft & Treue") and the birthday campaign
-- (§3.3 "Geburtstagskampagne") — the birthday body supports the same
-- {vorname}/{rabattcode}/{salonname} placeholders as regular campaigns.
--
-- Safe to run once. Use api/apply_migration_018.php for an idempotent runner.
-- ============================================================

ALTER TABLE coiffure_salons
    ADD COLUMN subdomain VARCHAR(63) DEFAULT NULL,
    ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active',
    ADD COLUMN currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    ADD COLUMN website VARCHAR(255) DEFAULT NULL,
    ADD COLUMN instagram_url VARCHAR(500) DEFAULT NULL,
    ADD COLUMN tablet_headline VARCHAR(255) DEFAULT NULL,
    ADD COLUMN tablet_bg_image VARCHAR(500) DEFAULT NULL,
    ADD COLUMN tablet_bg_color VARCHAR(7) DEFAULT NULL,
    ADD COLUMN tablet_idle_timeout_s INT UNSIGNED DEFAULT NULL,
    ADD COLUMN tablet_modules TEXT DEFAULT NULL,
    ADD COLUMN referral_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN referral_discount_value DECIMAL(6,2) NOT NULL DEFAULT 10.00,
    ADD COLUMN birthday_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN birthday_days_before INT UNSIGNED NOT NULL DEFAULT 7,
    ADD COLUMN birthday_subject VARCHAR(255) DEFAULT NULL,
    ADD COLUMN birthday_body TEXT DEFAULT NULL,
    ADD COLUMN birthday_discount_code VARCHAR(50) DEFAULT NULL,
    ADD COLUMN campaign_spam_limit INT UNSIGNED NOT NULL DEFAULT 4,
    ADD COLUMN campaign_spam_window_days INT UNSIGNED NOT NULL DEFAULT 30;

ALTER TABLE coiffure_salons
    ADD UNIQUE KEY unique_salon_subdomain (subdomain),
    ADD INDEX idx_salon_status (status);

-- Derive a starting subdomain for existing salons from the salon name.
UPDATE coiffure_salons
SET subdomain = LOWER(
        CONCAT(
            LEFT(REGEXP_REPLACE(salon_name, '[^A-Za-z0-9]+', '-'), 40),
            '-', salon_id
        )
    )
WHERE subdomain IS NULL;

-- Existing salons keep behaving as before: active unless soft-deleted.
UPDATE coiffure_salons SET status = 'suspended' WHERE is_active = 0;

-- ------------------------------------------------------------
-- Optional opening hours shown on the tablet (§3.3 "Öffnungszeiten")
--   weekday: 0 = Monday ... 6 = Sunday
--   is_closed = 1 means the day is shown as "Geschlossen" and the times are
--   ignored, which is why open_time/close_time stay nullable.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS coiffure_salon_hours (
    hours_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    open_time TIME DEFAULT NULL,
    close_time TIME DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    UNIQUE KEY unique_salon_weekday (salon_id, weekday)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- End of migration 018
-- ============================================================
