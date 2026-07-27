-- ============================================================
-- Migration 020: Marketing campaigns (one-time + automatic)
-- ============================================================
-- Backs the "Kampagnen" module.
--
-- coiffure_campaigns
--   One row per one-time campaign, and one row per *run* of an automatic
--   campaign, so the campaign log is a single table.
--   kind        'once' | 'auto'
--   status      draft | scheduled | sending | sent | cancelled | failed
--   recipient_* how the audience was chosen (all | members | segment | manual);
--               recipient_ref holds the segment_id or a JSON array of customer
--               ids depending on recipient_type.
--   discount_*  optional code attached to the mail. discount_mode 'generic'
--               reuses discount_code for everyone; 'unique' generates one code
--               per recipient into coiffure_discount_codes.
--   Open/click counters are denormalised so the log renders without a join.
--
-- coiffure_campaign_recipients
--   The per-customer delivery record. Also the source of truth for the spam
--   limit ("max N mails per customer per 30 days", configurable per salon in
--   migration 018), so it is indexed on (customer_id, sent_at).
--   status: pending | sent | skipped_limit | skipped_no_consent | failed
--
-- coiffure_automatic_campaigns
--   One row per (salon, type) for the four predefined types:
--     birthday        -- mirrors the salon's birthday settings (018)
--     we_miss_you     -- fires trigger_value weeks after the last visit
--     thank_you       -- fires on the trigger_value-th visit
--     referral_reminder -- fires trigger_value days after membership signup
--                          when the member has not referred anyone yet
--   trigger_unit is 'weeks' | 'visits' | 'days' to match the type.
--
-- coiffure_discount_codes
--   Issued codes, unique per salon, optionally bound to one customer and one
--   campaign. redeemed_at is filled in manually for now (no POS integration).
--
-- Safe to run once. Use api/apply_migration_020.php for an idempotent runner.
-- ============================================================

CREATE TABLE IF NOT EXISTS coiffure_campaigns (
    campaign_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    name VARCHAR(180) NOT NULL,
    kind VARCHAR(10) NOT NULL DEFAULT 'once',      -- once | auto
    auto_type VARCHAR(40) DEFAULT NULL,            -- set when kind = 'auto'
    status VARCHAR(20) NOT NULL DEFAULT 'draft',

    recipient_type VARCHAR(20) NOT NULL DEFAULT 'all',  -- all|members|segment|manual
    recipient_ref TEXT DEFAULT NULL,               -- segment_id, or JSON customer ids
    recipient_count INT UNSIGNED NOT NULL DEFAULT 0,

    subject VARCHAR(255) NOT NULL,
    body MEDIUMTEXT NOT NULL,

    discount_enabled TINYINT(1) NOT NULL DEFAULT 0,
    discount_mode VARCHAR(10) DEFAULT NULL,        -- generic | unique
    discount_code VARCHAR(50) DEFAULT NULL,
    discount_type VARCHAR(20) DEFAULT NULL,        -- fixed_eur | percentage
    discount_value DECIMAL(6,2) DEFAULT NULL,

    skip_over_limit TINYINT(1) NOT NULL DEFAULT 1,
    scheduled_at TIMESTAMP NULL DEFAULT NULL,
    started_at TIMESTAMP NULL DEFAULT NULL,
    completed_at TIMESTAMP NULL DEFAULT NULL,

    sent_count INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    open_count INT UNSIGNED NOT NULL DEFAULT 0,
    click_count INT UNSIGNED NOT NULL DEFAULT 0,

    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    INDEX idx_campaign_salon (salon_id),
    INDEX idx_campaign_status (status),
    INDEX idx_campaign_scheduled (status, scheduled_at),
    INDEX idx_campaign_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coiffure_campaign_recipients (
    recipient_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    salon_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    discount_code VARCHAR(50) DEFAULT NULL,
    error_message VARCHAR(255) DEFAULT NULL,
    tracking_token VARCHAR(64) DEFAULT NULL,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    opened_at TIMESTAMP NULL DEFAULT NULL,
    clicked_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (campaign_id) REFERENCES coiffure_campaigns(campaign_id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES coiffure_customers(customer_id) ON DELETE CASCADE,

    UNIQUE KEY unique_campaign_customer (campaign_id, customer_id),
    INDEX idx_recipient_campaign (campaign_id),
    -- the spam-limit lookup: "how many mails did this customer get since X"
    INDEX idx_recipient_customer_sent (customer_id, sent_at),
    INDEX idx_recipient_token (tracking_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coiffure_automatic_campaigns (
    auto_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    type VARCHAR(40) NOT NULL,   -- birthday|we_miss_you|thank_you|referral_reminder
    enabled TINYINT(1) NOT NULL DEFAULT 0,

    trigger_value INT UNSIGNED NOT NULL DEFAULT 10,
    trigger_unit VARCHAR(10) NOT NULL DEFAULT 'weeks',   -- weeks | visits | days

    subject VARCHAR(255) DEFAULT NULL,
    body MEDIUMTEXT DEFAULT NULL,

    discount_enabled TINYINT(1) NOT NULL DEFAULT 0,
    discount_code VARCHAR(50) DEFAULT NULL,
    discount_type VARCHAR(20) DEFAULT NULL,
    discount_value DECIMAL(6,2) DEFAULT NULL,

    last_run_at TIMESTAMP NULL DEFAULT NULL,
    updated_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    UNIQUE KEY unique_salon_auto_type (salon_id, type),
    INDEX idx_auto_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coiffure_discount_codes (
    code_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    code VARCHAR(50) NOT NULL,
    campaign_id INT UNSIGNED DEFAULT NULL,
    customer_id INT UNSIGNED DEFAULT NULL,
    discount_type VARCHAR(20) NOT NULL DEFAULT 'fixed_eur',
    discount_value DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    valid_until DATE DEFAULT NULL,
    redeemed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    UNIQUE KEY unique_salon_code (salon_id, code),
    INDEX idx_code_campaign (campaign_id),
    INDEX idx_code_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- End of migration 020
-- ============================================================
