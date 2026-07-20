-- ============================================================
-- Migration 008: Membership-focused tablet registration
-- ============================================================
-- Adds:
--   * Split name (first/last), birthday, ZIP/city core fields
--   * GDPR-conditional postal address block + postal consent
--   * Separate e-mail / SMS-WhatsApp marketing consents
--   * Membership flag + unique member ID + "member since" date
--   * Enrichment fields: referral source + preferred stylist
--   * coiffure_employees table (populates the "Wunsch-Stylist" dropdown)
--
-- Safe to run once. Uses IF NOT EXISTS guards where MySQL supports them.
-- On MySQL < 8.0.29 (no "ADD COLUMN IF NOT EXISTS"), remove the
-- "IF NOT EXISTS" tokens or run api/apply_migration_008.php which checks
-- for each column before adding it.
-- ============================================================

-- ------------------------------------------------------------
-- 1. New table: salon employees / stylists
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS coiffure_employees (
    employee_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,

    full_name VARCHAR(255) NOT NULL,
    title VARCHAR(120) DEFAULT NULL,          -- e.g. "Stylistin", "Colorist"
    display_order INT UNSIGNED DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    INDEX idx_employee_salon (salon_id),
    INDEX idx_employee_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Extend coiffure_customers
-- ------------------------------------------------------------
-- Split identity
ALTER TABLE coiffure_customers
    ADD COLUMN IF NOT EXISTS first_name VARCHAR(120) DEFAULT NULL AFTER full_name,
    ADD COLUMN IF NOT EXISTS last_name  VARCHAR(120) DEFAULT NULL AFTER first_name,
    ADD COLUMN IF NOT EXISTS mobile     VARCHAR(50)  DEFAULT NULL AFTER phone;

-- Birthday (day + month required by form, year optional)
ALTER TABLE coiffure_customers
    ADD COLUMN IF NOT EXISTS birth_day   TINYINT UNSIGNED DEFAULT NULL AFTER mobile,
    ADD COLUMN IF NOT EXISTS birth_month TINYINT UNSIGNED DEFAULT NULL AFTER birth_day,
    ADD COLUMN IF NOT EXISTS birth_year  SMALLINT UNSIGNED DEFAULT NULL AFTER birth_month;

-- Core ZIP / city (always collected)
ALTER TABLE coiffure_customers
    ADD COLUMN IF NOT EXISTS zip  VARCHAR(20)  DEFAULT NULL AFTER birth_year,
    ADD COLUMN IF NOT EXISTS city VARCHAR(120) DEFAULT NULL AFTER zip;

-- Optional postal address block (only stored when consent_postal = 1)
ALTER TABLE coiffure_customers
    ADD COLUMN IF NOT EXISTS address_street VARCHAR(255) DEFAULT NULL AFTER city,
    ADD COLUMN IF NOT EXISTS address_zip    VARCHAR(20)  DEFAULT NULL AFTER address_street,
    ADD COLUMN IF NOT EXISTS address_city   VARCHAR(120) DEFAULT NULL AFTER address_zip,
    ADD COLUMN IF NOT EXISTS consent_postal TINYINT(1)   DEFAULT 0    AFTER address_city;

-- Channel-specific marketing consents (consent_marketing kept for back-compat)
ALTER TABLE coiffure_customers
    ADD COLUMN IF NOT EXISTS consent_email_marketing TINYINT(1) DEFAULT 0 AFTER consent_marketing,
    ADD COLUMN IF NOT EXISTS consent_sms_whatsapp    TINYINT(1) DEFAULT 0 AFTER consent_email_marketing;

-- Membership
ALTER TABLE coiffure_customers
    ADD COLUMN IF NOT EXISTS is_member    TINYINT(1)  DEFAULT 0    AFTER consent_sms_whatsapp,
    ADD COLUMN IF NOT EXISTS member_id    VARCHAR(64) DEFAULT NULL AFTER is_member,
    ADD COLUMN IF NOT EXISTS member_since DATE        DEFAULT NULL AFTER member_id;

-- Enrichment
ALTER TABLE coiffure_customers
    ADD COLUMN IF NOT EXISTS referral_source     VARCHAR(50)  DEFAULT NULL AFTER member_since,
    ADD COLUMN IF NOT EXISTS preferred_stylist_id INT UNSIGNED DEFAULT NULL AFTER referral_source;

-- Phone was NOT NULL; the new form makes the mobile number optional.
ALTER TABLE coiffure_customers
    MODIFY COLUMN phone VARCHAR(50) NULL;

-- Unique member IDs (partial-ish: NULLs allowed, non-NULL must be unique)
ALTER TABLE coiffure_customers
    ADD UNIQUE INDEX idx_customer_member_id (member_id);

-- Helpful index for the stylist FK lookups
ALTER TABLE coiffure_customers
    ADD INDEX idx_customer_stylist (preferred_stylist_id);

-- ------------------------------------------------------------
-- 3. Seed demo stylists for the default salon (id = 1)
-- ------------------------------------------------------------
INSERT INTO coiffure_employees (salon_id, full_name, title, display_order, is_active)
SELECT 1, 'Kein Wunsch – egal', NULL, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM coiffure_employees WHERE salon_id = 1);

INSERT INTO coiffure_employees (salon_id, full_name, title, display_order, is_active)
SELECT 1, 'Anna', 'Stylistin', 1, 1
WHERE (SELECT COUNT(*) FROM coiffure_employees WHERE salon_id = 1) = 1;

INSERT INTO coiffure_employees (salon_id, full_name, title, display_order, is_active)
SELECT 1, 'Marco', 'Colorist', 2, 1
WHERE (SELECT COUNT(*) FROM coiffure_employees WHERE salon_id = 1) = 2;

INSERT INTO coiffure_employees (salon_id, full_name, title, display_order, is_active)
SELECT 1, 'Sophie', 'Stylistin', 3, 1
WHERE (SELECT COUNT(*) FROM coiffure_employees WHERE salon_id = 1) = 3;

-- ============================================================
-- End of migration 008
-- ============================================================
