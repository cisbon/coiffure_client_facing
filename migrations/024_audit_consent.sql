-- ============================================================
-- Migration 024: Widen the audit log + GDPR consent history
-- ============================================================
-- Part 1 -- fix a live bug.
--   coiffure_audit_log.entity_type and .action are ENUMs listing only the
--   handful of values that existed when the table was created. Any logAudit()
--   call with a value outside those lists is silently dropped by MySQL in
--   non-strict mode -- which is already happening today at
--   api/user-settings.php:113 ('update_language').
--   The admin dashboard adds many more action types (campaign_sent,
--   impersonate, invoice_created, permission_granted, ...), so both columns
--   become VARCHAR(40). Widening an ENUM to VARCHAR preserves every existing
--   row and every existing query.
--
--   Two columns are added at the same time so the audit view can filter by
--   salon and attribute an entry to a user id rather than only a username
--   string:
--     salon_id          NULL for platform-level actions
--     performed_by_id   FK-free on purpose: audit rows must survive the
--                       deletion of the user that caused them.
--     performed_by_role lets the Admin Delegate view exclude 'admin' actions
--                       without a join back to a possibly-deleted user.
--
-- Part 2 -- consent history.
--   coiffure_customers stores only the *current* consent flags. GDPR asks for
--   the history of changes, so every flip is appended here with the IP and
--   user-agent that caused it. Read-only in the UI.
--
-- Safe to run once. Use api/apply_migration_024.php for an idempotent runner.
-- ============================================================

ALTER TABLE coiffure_audit_log
    MODIFY COLUMN entity_type VARCHAR(40) NOT NULL,
    MODIFY COLUMN action VARCHAR(40) NOT NULL;

ALTER TABLE coiffure_audit_log
    ADD COLUMN salon_id INT UNSIGNED DEFAULT NULL,
    ADD COLUMN performed_by_id INT UNSIGNED DEFAULT NULL,
    ADD COLUMN performed_by_role VARCHAR(40) DEFAULT NULL;

ALTER TABLE coiffure_audit_log
    ADD INDEX idx_audit_salon (salon_id),
    ADD INDEX idx_audit_performer (performed_by_id),
    ADD INDEX idx_audit_role (performed_by_role);

CREATE TABLE IF NOT EXISTS coiffure_consent_history (
    history_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    salon_id INT UNSIGNED DEFAULT NULL,
    consent_field VARCHAR(60) NOT NULL,     -- e.g. consent_email_marketing
    old_value VARCHAR(20) DEFAULT NULL,
    new_value VARCHAR(20) DEFAULT NULL,
    policy_version VARCHAR(20) DEFAULT NULL,
    source VARCHAR(40) DEFAULT NULL,        -- tablet | dashboard | import
    changed_by VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (customer_id) REFERENCES coiffure_customers(customer_id) ON DELETE CASCADE,

    INDEX idx_consent_customer (customer_id, created_at),
    INDEX idx_consent_salon (salon_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- End of migration 024
-- ============================================================
