<?php
/**
 * Apply Migration 022 - Subscription plans, salon subscriptions, invoices
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS guards + INSERT IGNORE for the
 * starter plans.
 * Run from the browser (admin session or ?token=<MIGRATION_TOKEN>) or CLI:
 *   php api/apply_migration_022.php
 */

require_once __DIR__ . '/migration_helpers.php';

$conn = migStart('022', 'Subscription plans, salon subscriptions, invoices');
if (!$conn) {
    return;
}

migEnsureTable($conn, 'coiffure_subscription_plans', "
    CREATE TABLE IF NOT EXISTS coiffure_subscription_plans (
        plan_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        description VARCHAR(500) DEFAULT NULL,
        monthly_price DECIMAL(8,2) NOT NULL DEFAULT 0.00,
        currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
        max_customers INT UNSIGNED NOT NULL DEFAULT 0,
        max_campaigns_per_month INT UNSIGNED NOT NULL DEFAULT 0,
        features TEXT DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_plan_name (name),
        INDEX idx_plan_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

migEnsureTable($conn, 'coiffure_salon_subscriptions', "
    CREATE TABLE IF NOT EXISTS coiffure_salon_subscriptions (
        subscription_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        salon_id INT UNSIGNED NOT NULL,
        plan_id INT UNSIGNED DEFAULT NULL,
        payment_status VARCHAR(20) NOT NULL DEFAULT 'active',
        trial_ends_at DATE DEFAULT NULL,
        started_at DATE DEFAULT NULL,
        cancelled_at DATE DEFAULT NULL,
        notes VARCHAR(500) DEFAULT NULL,
        updated_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,
        FOREIGN KEY (plan_id) REFERENCES coiffure_subscription_plans(plan_id) ON DELETE SET NULL,
        UNIQUE KEY unique_subscription_salon (salon_id),
        INDEX idx_subscription_status (payment_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

migEnsureTable($conn, 'coiffure_invoices', "
    CREATE TABLE IF NOT EXISTS coiffure_invoices (
        invoice_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(30) NOT NULL,
        salon_id INT UNSIGNED NOT NULL,
        plan_id INT UNSIGNED DEFAULT NULL,
        period_year SMALLINT UNSIGNED NOT NULL,
        period_month TINYINT UNSIGNED NOT NULL,
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        issued_at DATE DEFAULT NULL,
        paid_at DATE DEFAULT NULL,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,
        UNIQUE KEY unique_invoice_number (invoice_number),
        UNIQUE KEY unique_invoice_period (salon_id, period_year, period_month),
        INDEX idx_invoice_salon (salon_id),
        INDEX idx_invoice_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

migEnsureTable($conn, 'coiffure_invoice_items', "
    CREATE TABLE IF NOT EXISTS coiffure_invoice_items (
        item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT UNSIGNED NOT NULL,
        description VARCHAR(255) NOT NULL,
        quantity DECIMAL(8,2) NOT NULL DEFAULT 1.00,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        sort_order INT NOT NULL DEFAULT 0,
        FOREIGN KEY (invoice_id) REFERENCES coiffure_invoices(invoice_id) ON DELETE CASCADE,
        INDEX idx_item_invoice (invoice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

migRun(
    $conn,
    "INSERT IGNORE INTO coiffure_subscription_plans
        (name, description, monthly_price, max_customers, max_campaigns_per_month, features, sort_order)
     VALUES
        ('Starter', 'Für einzelne Salons mit bis zu 500 Kunden.', 29.00, 500, 4,
         '{\"advanced_insights\":false,\"white_label\":false,\"multi_salon\":false}', 1),
        ('Professional', 'Unbegrenzte Kunden, erweiterte Einblicke.', 79.00, 0, 20,
         '{\"advanced_insights\":true,\"white_label\":false,\"multi_salon\":false}', 2),
        ('Enterprise', 'Mehrere Standorte, White-Label und eigener Mailversand.', 149.00, 0, 0,
         '{\"advanced_insights\":true,\"white_label\":true,\"multi_salon\":true}', 3)",
    'seeded the starter subscription plans'
);

migFinish($conn, '022');
