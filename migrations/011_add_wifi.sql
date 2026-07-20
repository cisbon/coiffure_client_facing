-- ============================================================
-- Migration 011: Salon guest WiFi
-- ============================================================
-- Adds optional guest-WiFi credentials to each salon. When both a name and a
-- password are set, the tablet shows a "Social & WLAN" entry with a WiFi QR
-- code + credentials; otherwise the entry is just "Social".
--
-- Safe to run once. Use api/apply_migration_011.php for an idempotent runner.
-- ============================================================

ALTER TABLE coiffure_salons
    ADD COLUMN wifi_ssid VARCHAR(255) DEFAULT NULL AFTER facebook_url,
    ADD COLUMN wifi_password VARCHAR(255) DEFAULT NULL AFTER wifi_ssid;

-- ============================================================
-- End of migration 011
-- ============================================================
