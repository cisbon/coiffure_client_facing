-- ============================================================
-- Migration 010: Customer gender + title
-- ============================================================
-- Adds two optional fields to customers, both asked on the tablet
-- registration form:
--   * gender  – clickable chips (female/male/diverse). Shown as a silhouette
--               avatar on the self-check-in name list so the customer can spot
--               their own profile at a glance.
--   * title   – academic/courtesy title (e.g. Dr., Prof.), empty by default.
--
-- Safe to run once. Use api/apply_migration_010.php for an idempotent runner.
-- ============================================================

ALTER TABLE coiffure_customers
    ADD COLUMN gender ENUM('female', 'male', 'diverse') DEFAULT NULL AFTER last_name,
    ADD COLUMN title VARCHAR(30) DEFAULT NULL AFTER gender;

-- ============================================================
-- End of migration 010
-- ============================================================
