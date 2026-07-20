<?php
/**
 * Apply Migration 008 - Membership-focused tablet registration
 *
 * Idempotent: checks each column / table / index before creating it, so it is
 * safe to run repeatedly and works on MySQL versions that do not support
 * "ADD COLUMN IF NOT EXISTS".
 *
 * Run from the browser (once) or CLI:  php api/apply_migration_008.php
 */

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "Applying migration 008: Membership registration\n";
echo "================================================\n";

$conn = getDbConnection();
if (!$conn) {
    echo "ERROR: Database connection failed\n";
    return;
}

/** Add a column only if it does not already exist. */
function ensureColumn(mysqli $conn, string $table, string $column, string $definition): void {
    $safeCol = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$safeCol'");
    if ($res && $res->num_rows > 0) {
        echo "  - $table.$column already exists, skipping\n";
        return;
    }
    if ($conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition")) {
        echo "  + Added $table.$column\n";
    } else {
        echo "  ! Failed to add $table.$column: " . $conn->error . "\n";
    }
}

/** Add an index only if it does not already exist. */
function ensureIndex(mysqli $conn, string $table, string $indexName, string $definition): void {
    $safeIdx = $conn->real_escape_string($indexName);
    $res = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$safeIdx'");
    if ($res && $res->num_rows > 0) {
        echo "  - Index $table.$indexName already exists, skipping\n";
        return;
    }
    if ($conn->query("ALTER TABLE `$table` ADD $definition")) {
        echo "  + Added index $table.$indexName\n";
    } else {
        echo "  ! Failed to add index $table.$indexName: " . $conn->error . "\n";
    }
}

// ------------------------------------------------------------
// 1. Employees table
// ------------------------------------------------------------
echo "\n[1] coiffure_employees table\n";
$createEmployees = "CREATE TABLE IF NOT EXISTS coiffure_employees (
    employee_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    title VARCHAR(120) DEFAULT NULL,
    display_order INT UNSIGNED DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,
    INDEX idx_employee_salon (salon_id),
    INDEX idx_employee_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
echo $conn->query($createEmployees) ? "  + coiffure_employees ready\n" : "  ! " . $conn->error . "\n";

// ------------------------------------------------------------
// 2. Customer columns
// ------------------------------------------------------------
echo "\n[2] coiffure_customers columns\n";
ensureColumn($conn, 'coiffure_customers', 'first_name', "VARCHAR(120) DEFAULT NULL AFTER full_name");
ensureColumn($conn, 'coiffure_customers', 'last_name',  "VARCHAR(120) DEFAULT NULL AFTER first_name");
ensureColumn($conn, 'coiffure_customers', 'mobile',     "VARCHAR(50) DEFAULT NULL AFTER phone");
ensureColumn($conn, 'coiffure_customers', 'birth_day',   "TINYINT UNSIGNED DEFAULT NULL AFTER mobile");
ensureColumn($conn, 'coiffure_customers', 'birth_month', "TINYINT UNSIGNED DEFAULT NULL AFTER birth_day");
ensureColumn($conn, 'coiffure_customers', 'birth_year',  "SMALLINT UNSIGNED DEFAULT NULL AFTER birth_month");
ensureColumn($conn, 'coiffure_customers', 'zip',  "VARCHAR(20) DEFAULT NULL AFTER birth_year");
ensureColumn($conn, 'coiffure_customers', 'city', "VARCHAR(120) DEFAULT NULL AFTER zip");
ensureColumn($conn, 'coiffure_customers', 'address_street', "VARCHAR(255) DEFAULT NULL AFTER city");
ensureColumn($conn, 'coiffure_customers', 'address_zip',    "VARCHAR(20) DEFAULT NULL AFTER address_street");
ensureColumn($conn, 'coiffure_customers', 'address_city',   "VARCHAR(120) DEFAULT NULL AFTER address_zip");
ensureColumn($conn, 'coiffure_customers', 'consent_postal', "TINYINT(1) DEFAULT 0 AFTER address_city");
ensureColumn($conn, 'coiffure_customers', 'consent_email_marketing', "TINYINT(1) DEFAULT 0 AFTER consent_marketing");
ensureColumn($conn, 'coiffure_customers', 'consent_sms_whatsapp',    "TINYINT(1) DEFAULT 0 AFTER consent_email_marketing");
ensureColumn($conn, 'coiffure_customers', 'is_member',    "TINYINT(1) DEFAULT 0 AFTER consent_sms_whatsapp");
ensureColumn($conn, 'coiffure_customers', 'member_id',    "VARCHAR(64) DEFAULT NULL AFTER is_member");
ensureColumn($conn, 'coiffure_customers', 'member_since', "DATE DEFAULT NULL AFTER member_id");
ensureColumn($conn, 'coiffure_customers', 'referral_source',      "VARCHAR(50) DEFAULT NULL AFTER member_since");
ensureColumn($conn, 'coiffure_customers', 'preferred_stylist_id', "INT UNSIGNED DEFAULT NULL AFTER referral_source");

// Make phone nullable (mobile is now optional in the form)
if ($conn->query("ALTER TABLE coiffure_customers MODIFY COLUMN phone VARCHAR(50) NULL")) {
    echo "  + phone column is now nullable\n";
} else {
    echo "  ! Could not modify phone column: " . $conn->error . "\n";
}

echo "\n[3] Indexes\n";
ensureIndex($conn, 'coiffure_customers', 'idx_customer_member_id', "UNIQUE INDEX idx_customer_member_id (member_id)");
ensureIndex($conn, 'coiffure_customers', 'idx_customer_stylist',   "INDEX idx_customer_stylist (preferred_stylist_id)");

// ------------------------------------------------------------
// 4. Seed demo stylists for salon 1
// ------------------------------------------------------------
echo "\n[4] Seed demo stylists (salon 1)\n";
$existing = $conn->query("SELECT COUNT(*) AS c FROM coiffure_employees WHERE salon_id = 1");
$count = $existing ? (int)$existing->fetch_assoc()['c'] : 1;
if ($count === 0) {
    $conn->query("INSERT INTO coiffure_employees (salon_id, full_name, title, display_order, is_active) VALUES
        (1, 'Anna', 'Stylistin', 1, 1),
        (1, 'Marco', 'Colorist', 2, 1),
        (1, 'Sophie', 'Stylistin', 3, 1)");
    echo "  + Seeded 3 demo stylists\n";
} else {
    echo "  - Salon 1 already has $count stylist(s), skipping seed\n";
}

echo "\nSUCCESS: Migration 008 applied.\n";
$conn->close();
