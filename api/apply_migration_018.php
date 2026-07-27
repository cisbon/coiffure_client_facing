<?php
/**
 * Apply Migration 018 - Salon settings (status, tablet, birthday, referral)
 *
 * Idempotent: each column is added only when missing.
 * Run from the browser (admin session or ?token=<MIGRATION_TOKEN>) or CLI:
 *   php api/apply_migration_018.php
 */

require_once __DIR__ . '/migration_helpers.php';

$conn = migStart('018', 'Salon settings (status, tablet, birthday, referral)');
if (!$conn) {
    return;
}

$columns = [
    // Platform-level
    'subdomain'                 => 'VARCHAR(63) DEFAULT NULL',
    'status'                    => "VARCHAR(20) NOT NULL DEFAULT 'active'",
    'currency'                  => "VARCHAR(3) NOT NULL DEFAULT 'EUR'",
    'website'                   => 'VARCHAR(255) DEFAULT NULL',
    'instagram_url'             => 'VARCHAR(500) DEFAULT NULL',
    // Tablet
    'tablet_headline'           => 'VARCHAR(255) DEFAULT NULL',
    'tablet_bg_image'           => 'VARCHAR(500) DEFAULT NULL',
    'tablet_bg_color'           => 'VARCHAR(7) DEFAULT NULL',
    'tablet_idle_timeout_s'     => 'INT UNSIGNED DEFAULT NULL',
    'tablet_modules'            => 'TEXT DEFAULT NULL',
    // Refer a friend
    'referral_enabled'          => 'TINYINT(1) NOT NULL DEFAULT 1',
    'referral_discount_value'   => 'DECIMAL(6,2) NOT NULL DEFAULT 10.00',
    // Birthday campaign
    'birthday_enabled'          => 'TINYINT(1) NOT NULL DEFAULT 0',
    'birthday_days_before'      => 'INT UNSIGNED NOT NULL DEFAULT 7',
    'birthday_subject'          => 'VARCHAR(255) DEFAULT NULL',
    'birthday_body'             => 'TEXT DEFAULT NULL',
    'birthday_discount_code'    => 'VARCHAR(50) DEFAULT NULL',
    // Campaign guard rails
    'campaign_spam_limit'       => 'INT UNSIGNED NOT NULL DEFAULT 4',
    'campaign_spam_window_days' => 'INT UNSIGNED NOT NULL DEFAULT 30',
];

foreach ($columns as $column => $definition) {
    migEnsureColumn($conn, 'coiffure_salons', $column, $definition);
}

migEnsureIndex($conn, 'coiffure_salons', 'unique_salon_subdomain', 'UNIQUE KEY unique_salon_subdomain (subdomain)');
migEnsureIndex($conn, 'coiffure_salons', 'idx_salon_status', 'INDEX idx_salon_status (status)');

// Give existing salons a subdomain derived from their name. REGEXP_REPLACE
// needs MySQL 8 / MariaDB 10.0+; fall back to a plain slug when unavailable.
$seeded = $conn->query(
    "UPDATE coiffure_salons
     SET subdomain = LOWER(CONCAT(LEFT(REGEXP_REPLACE(salon_name, '[^A-Za-z0-9]+', '-'), 40), '-', salon_id))
     WHERE subdomain IS NULL"
);
if ($seeded) {
    echo "  + seeded subdomains for existing salons\n";
} else {
    $fallback = $conn->query(
        "UPDATE coiffure_salons SET subdomain = CONCAT('salon-', salon_id) WHERE subdomain IS NULL"
    );
    echo $fallback
        ? "  + seeded subdomains for existing salons (fallback slug)\n"
        : "  ! failed to seed subdomains: " . $conn->error . "\n";
}

migRun(
    $conn,
    "UPDATE coiffure_salons SET status = 'suspended' WHERE is_active = 0 AND status = 'active'",
    'aligned status with the existing is_active flag'
);

migEnsureTable($conn, 'coiffure_salon_hours', "
    CREATE TABLE IF NOT EXISTS coiffure_salon_hours (
        hours_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        salon_id INT UNSIGNED NOT NULL,
        weekday TINYINT UNSIGNED NOT NULL,
        is_closed TINYINT(1) NOT NULL DEFAULT 0,
        open_time TIME DEFAULT NULL,
        close_time TIME DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,
        UNIQUE KEY unique_salon_weekday (salon_id, weekday)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

migFinish($conn, '018');
