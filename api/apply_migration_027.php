<?php
/**
 * Apply Migration 027 - AI image quotas, usage ledger and overage billing
 *
 * Idempotent: each column and the table are created only when missing.
 * Run from the browser (admin session or ?token=<MIGRATION_TOKEN>) or CLI:
 *   php api/apply_migration_027.php
 */

require_once __DIR__ . '/migration_helpers.php';

$conn = migStart('027', 'AI image quotas, usage ledger and overage billing');
if (!$conn) {
    return;
}

// Per-salon commercial terms for the AI stylists.
migEnsureColumn($conn, 'coiffure_salons', 'ai_feature_enabled', 'TINYINT(1) NOT NULL DEFAULT 1');
migEnsureColumn($conn, 'coiffure_salons', 'ai_trial_image_limit', 'INT UNSIGNED NOT NULL DEFAULT 100');
migEnsureColumn($conn, 'coiffure_salons', 'ai_monthly_image_limit', 'INT UNSIGNED NOT NULL DEFAULT 500');
migEnsureColumn($conn, 'coiffure_salons', 'ai_overage_allowed', 'TINYINT(1) NOT NULL DEFAULT 0');
migEnsureColumn($conn, 'coiffure_salons', 'ai_overage_price', 'DECIMAL(8,4) NOT NULL DEFAULT 0.0100');

// The usage ledger: one row per successfully generated image.
migEnsureTable($conn, 'coiffure_ai_image_usage', "
    CREATE TABLE coiffure_ai_image_usage (
        usage_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        salon_id INT UNSIGNED NOT NULL,
        consultation_id INT UNSIGNED DEFAULT NULL,
        consultation_type VARCHAR(30) NOT NULL DEFAULT 'hairstyle',
        period_year SMALLINT UNSIGNED NOT NULL,
        period_month TINYINT UNSIGNED NOT NULL,
        quota_mode VARCHAR(20) NOT NULL DEFAULT 'subscription',
        billing_state VARCHAR(20) NOT NULL DEFAULT 'included',
        overage_price DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
        currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,
        FOREIGN KEY (consultation_id) REFERENCES coiffure_ai_consultations(consultation_id) ON DELETE SET NULL,
        INDEX idx_ai_usage_period (salon_id, period_year, period_month),
        INDEX idx_ai_usage_created (salon_id, created_at),
        INDEX idx_ai_usage_billing (salon_id, billing_state)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Salons that already used the AI stylists before this migration have no
// ledger rows. Backfill from the consultations table so the first invoice and
// the dashboard counter do not start from an artificial zero. Only completed
// consultations produced an image, and everything is booked as 'included' --
// nobody can be charged retroactively for usage that had no price attached.
if (migTableExists($conn, 'coiffure_ai_image_usage') && migTableExists($conn, 'coiffure_ai_consultations')) {
    $existing = $conn->query('SELECT COUNT(*) AS c FROM coiffure_ai_image_usage');
    $rows = $existing ? (int)$existing->fetch_assoc()['c'] : 0;

    if ($rows > 0) {
        echo "  - ledger already holds $rows row(s), skipping backfill\n";
    } else {
        migRun($conn, "
            INSERT INTO coiffure_ai_image_usage
                (salon_id, consultation_id, consultation_type, period_year, period_month,
                 quota_mode, billing_state, overage_price, currency, created_at)
            SELECT
                c.salon_id,
                c.consultation_id,
                'hairstyle',
                YEAR(c.created_at),
                MONTH(c.created_at),
                'subscription',
                'included',
                0.0000,
                COALESCE(s.currency, 'EUR'),
                c.created_at
            FROM coiffure_ai_consultations c
            JOIN coiffure_salons s ON s.salon_id = c.salon_id
            WHERE c.status = 'completed'
        ", 'backfilled the ledger from completed consultations');
    }
}

migFinish($conn, '027');
