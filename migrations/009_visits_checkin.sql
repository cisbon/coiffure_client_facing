-- ============================================================
-- Migration 009: Self check-in visits
-- ============================================================
-- Adds the coiffure_visits table used by the tablet self-check-in kiosk.
-- Each successful check-in (via birthday+name selection or phone fallback)
-- logs one row for future analytics.
--
-- Birthday columns (birth_day / birth_month) already exist from migration 008
-- and are what the candidates lookup filters on.
--
-- Safe to run once. Use api/apply_migration_009.php for an idempotent runner.
-- ============================================================

CREATE TABLE IF NOT EXISTS coiffure_visits (
    visit_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    salon_id INT UNSIGNED NOT NULL,

    -- How the customer identified themselves at the kiosk
    checkin_method ENUM('birthday', 'phone', 'manual') DEFAULT 'birthday',

    checked_in_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (customer_id) REFERENCES coiffure_customers(customer_id) ON DELETE CASCADE,
    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    INDEX idx_visit_customer (customer_id),
    INDEX idx_visit_salon (salon_id),
    INDEX idx_visit_checked_in (checked_in_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Helpful composite index for "did this member already check in today" queries.
CREATE INDEX idx_visit_customer_day ON coiffure_visits (customer_id, checked_in_at);

-- ============================================================
-- End of migration 009
-- ============================================================
