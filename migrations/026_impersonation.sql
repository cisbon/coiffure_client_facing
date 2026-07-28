-- ============================================================
-- Migration 026: Support impersonation
-- ============================================================
-- Records that a session was started by an administrator on behalf of a salon
-- account (api/impersonate.php).
--
-- The column is what makes an impersonated session distinguishable from a
-- normal one: validateSession() reads it, api/me.php reports it, and the
-- dashboard shows a permanent banner for as long as it is set. Without it a
-- support session would be indistinguishable from the salon owner's own, which
-- is exactly what an audit trail must not allow.
--
-- Deliberately NOT a foreign key with ON DELETE CASCADE: deleting an
-- administrator's account must not silently erase the record that they
-- impersonated someone.
--
-- Safe to run once. Use api/apply_migration_026.php for an idempotent runner.
-- ============================================================

ALTER TABLE coiffure_sessions
    ADD COLUMN impersonated_by INT UNSIGNED DEFAULT NULL COMMENT 'user_id of the admin who started this support session';

ALTER TABLE coiffure_sessions
    ADD INDEX idx_session_impersonated (impersonated_by);

-- ============================================================
-- End of migration 026
-- ============================================================
