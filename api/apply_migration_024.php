<?php
/**
 * Apply Migration 024 - Widen the audit log + GDPR consent history
 *
 * Idempotent: MODIFY COLUMN is naturally repeatable, columns and the table are
 * guarded.
 * Run from the browser (admin session or ?token=<MIGRATION_TOKEN>) or CLI:
 *   php api/apply_migration_024.php
 */

require_once __DIR__ . '/migration_helpers.php';

$conn = migStart('024', 'Widen the audit log + GDPR consent history');
if (!$conn) {
    return;
}

// The ENUMs were too narrow: any logAudit() with an unlisted value is silently
// dropped by MySQL in non-strict mode. This already affects 'update_language'
// in api/user-settings.php.
migModifyColumn($conn, 'coiffure_audit_log', 'entity_type', 'VARCHAR(40) NOT NULL');
migModifyColumn($conn, 'coiffure_audit_log', 'action', 'VARCHAR(40) NOT NULL');

migEnsureColumn($conn, 'coiffure_audit_log', 'salon_id', 'INT UNSIGNED DEFAULT NULL');
migEnsureColumn($conn, 'coiffure_audit_log', 'performed_by_id', 'INT UNSIGNED DEFAULT NULL');
migEnsureColumn($conn, 'coiffure_audit_log', 'performed_by_role', 'VARCHAR(40) DEFAULT NULL');

migEnsureIndex($conn, 'coiffure_audit_log', 'idx_audit_salon', 'INDEX idx_audit_salon (salon_id)');
migEnsureIndex($conn, 'coiffure_audit_log', 'idx_audit_performer', 'INDEX idx_audit_performer (performed_by_id)');
migEnsureIndex($conn, 'coiffure_audit_log', 'idx_audit_role', 'INDEX idx_audit_role (performed_by_role)');

migEnsureTable($conn, 'coiffure_consent_history', "
    CREATE TABLE IF NOT EXISTS coiffure_consent_history (
        history_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        salon_id INT UNSIGNED DEFAULT NULL,
        consent_field VARCHAR(60) NOT NULL,
        old_value VARCHAR(20) DEFAULT NULL,
        new_value VARCHAR(20) DEFAULT NULL,
        policy_version VARCHAR(20) DEFAULT NULL,
        source VARCHAR(40) DEFAULT NULL,
        changed_by VARCHAR(255) DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES coiffure_customers(customer_id) ON DELETE CASCADE,
        INDEX idx_consent_customer (customer_id, created_at),
        INDEX idx_consent_salon (salon_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

migFinish($conn, '024');
