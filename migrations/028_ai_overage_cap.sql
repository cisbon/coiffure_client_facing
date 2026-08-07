-- ============================================================
-- Migration 028: Monthly spend cap for AI overage
-- ============================================================
-- Migration 027 gave a salon two options once the monthly image allowance is
-- used up: stop, or keep generating at ai_overage_price per image. The second
-- option had no ceiling, so a busy month could produce a bill nobody expected.
--
-- ai_overage_monthly_cap is that ceiling, in the salon's currency, and it
-- belongs to the salon owner rather than the platform: it is their spending
-- decision. Once the extras booked in the current calendar month would exceed
-- it, the AI stylists switch off for the rest of the month exactly as if the
-- owner had never allowed extras.
--
-- The check is made *before* an image is generated, so the cap is a hard
-- ceiling and is never overshot: a 20.00 cap can produce 19.99 of extras, never
-- 20.01.
--
-- 0.00 means no cap, matching the "0 = unlimited" convention used by the image
-- limits. It is the default only because the column has to have one; the
-- dashboard prompts for a real value whenever extras are switched on.
--
-- Safe to run once. Use api/apply_migration_028.php for an idempotent runner.
-- ============================================================

ALTER TABLE coiffure_salons
    ADD COLUMN ai_overage_monthly_cap DECIMAL(8,2) NOT NULL DEFAULT 0.00;

-- ============================================================
-- End of migration 028
-- ============================================================
