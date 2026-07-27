-- ============================================================
-- Migration 025: Staff notes and tags on a customer
-- ============================================================
-- The customer profile panel in the dashboard (spec 3.5) shows, besides the
-- collected data, two things the salon maintains itself:
--
--   notes  free text the team can edit ("prefers cooler tones, allergic to X")
--   tags   comma-separated labels used for ad-hoc grouping; kept as a simple
--          string rather than a join table because the dashboard only ever
--          filters them with LIKE and a salon has a handful of them
--
-- Both are salon-authored, not customer-supplied, so they are deliberately
-- excluded from the consent-aware marketing export in api/insights.php.
--
-- Safe to run once. Use api/apply_migration_025.php for an idempotent runner.
-- ============================================================

ALTER TABLE coiffure_customers
    ADD COLUMN notes TEXT DEFAULT NULL,
    ADD COLUMN tags VARCHAR(255) DEFAULT NULL,
    ADD COLUMN notes_updated_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN notes_updated_by INT UNSIGNED DEFAULT NULL;

-- ============================================================
-- End of migration 025
-- ============================================================
