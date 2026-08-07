-- ============================================================
-- Migration 027: AI image quotas, usage ledger and overage billing
-- ============================================================
-- The AI stylists (hairstyle, eyebrows, …) cost real money per generated
-- image, so every salon needs a metered allowance. Two regimes:
--
--   trial         coiffure_salons.status = 'trial'
--                 One lifetime allowance (ai_trial_image_limit, default 100).
--                 When it is used up the feature is switched off; there is
--                 deliberately no paid overage during a trial.
--
--   subscription  every other active salon
--                 ai_monthly_image_limit images per calendar month (default
--                 500). Beyond that the salon owner's own setting decides:
--                   ai_overage_allowed = 0  → feature off until the next month
--                                             (the salon can never be charged
--                                             more than its plan)
--                   ai_overage_allowed = 1  → generation continues and each
--                                             extra image is billed at
--                                             ai_overage_price
--
-- A limit of 0 means unlimited, matching coiffure_subscription_plans.
--
-- coiffure_ai_image_usage
--   One row per *successfully generated* image -- the billing record of truth.
--   It is deliberately separate from coiffure_ai_consultations: that table
--   also holds failed attempts and base64 image blobs and is expected to be
--   pruned, while this ledger is small, append-only and must survive.
--   period_year/period_month are stored rather than derived so a month's
--   invoice cannot shift when the server timezone changes, and the price that
--   applied at generation time is frozen on the row, so re-pricing later never
--   rewrites history.
--
-- Safe to run once. Use api/apply_migration_027.php for an idempotent runner.
-- ============================================================

ALTER TABLE coiffure_salons
    ADD COLUMN ai_feature_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN ai_trial_image_limit INT UNSIGNED NOT NULL DEFAULT 100,
    ADD COLUMN ai_monthly_image_limit INT UNSIGNED NOT NULL DEFAULT 500,
    ADD COLUMN ai_overage_allowed TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN ai_overage_price DECIMAL(8,4) NOT NULL DEFAULT 0.0100;

CREATE TABLE IF NOT EXISTS coiffure_ai_image_usage (
    usage_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    consultation_id INT UNSIGNED DEFAULT NULL,

    -- Which stylist produced the image ('hairstyle', 'eyebrows', …).
    consultation_type VARCHAR(30) NOT NULL DEFAULT 'hairstyle',

    -- Billing period this image counts towards.
    period_year SMALLINT UNSIGNED NOT NULL,
    period_month TINYINT UNSIGNED NOT NULL,

    -- Regime and price frozen at generation time.
    quota_mode VARCHAR(20) NOT NULL DEFAULT 'subscription',  -- trial | subscription
    billing_state VARCHAR(20) NOT NULL DEFAULT 'included',   -- included | overage
    overage_price DECIMAL(8,4) NOT NULL DEFAULT 0.0000,      -- charged for THIS image
    currency VARCHAR(3) NOT NULL DEFAULT 'EUR',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,
    FOREIGN KEY (consultation_id) REFERENCES coiffure_ai_consultations(consultation_id) ON DELETE SET NULL,

    INDEX idx_ai_usage_period (salon_id, period_year, period_month),
    INDEX idx_ai_usage_created (salon_id, created_at),
    INDEX idx_ai_usage_billing (salon_id, billing_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- End of migration 027
-- ============================================================
