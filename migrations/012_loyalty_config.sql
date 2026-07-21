-- ============================================================
-- Migration 012: Per-salon loyalty program configuration + staff PIN
-- ============================================================
-- Makes the loyalty program fully configurable per salon (previously the
-- "10 € Rabatt auf den 5. Besuch" values were hardcoded in the frontend and
-- e-mail templates). Adds the tablet staff-override PIN.
--
-- Columns:
--   loyalty_active          on/off switch for the whole loyalty program
--   loyalty_visit_threshold every Nth visit earns the reward (2–50)
--   loyalty_discount_type    'fixed_eur' | 'percentage'
--   loyalty_discount_value   amount (€) or percent, depending on the type
--   loyalty_discount_label   optional custom label (falls back to a computed
--                            "10 €" / "15 %" string when NULL)
--   staff_pin                4-digit PIN for the hidden tablet staff check-in
--
-- Safe to run once. Use api/apply_migration_012.php for an idempotent runner.
-- ============================================================

ALTER TABLE coiffure_salons
    ADD COLUMN loyalty_active TINYINT(1) NOT NULL DEFAULT 1 AFTER wifi_password,
    ADD COLUMN loyalty_visit_threshold INT UNSIGNED NOT NULL DEFAULT 5 AFTER loyalty_active,
    ADD COLUMN loyalty_discount_type ENUM('fixed_eur','percentage') NOT NULL DEFAULT 'fixed_eur' AFTER loyalty_visit_threshold,
    ADD COLUMN loyalty_discount_value DECIMAL(6,2) NOT NULL DEFAULT 10.00 AFTER loyalty_discount_type,
    ADD COLUMN loyalty_discount_label VARCHAR(50) DEFAULT NULL AFTER loyalty_discount_value,
    ADD COLUMN staff_pin VARCHAR(8) NOT NULL DEFAULT '0000' AFTER loyalty_discount_label;

-- ============================================================
-- End of migration 012
-- ============================================================
