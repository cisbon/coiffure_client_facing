<?php
/**
 * Apply Migration 026 - Support impersonation
 *
 * Idempotent: the column and index are added only when missing.
 * Run from the browser (admin session or ?token=<MIGRATION_TOKEN>) or CLI:
 *   php api/apply_migration_026.php
 */

require_once __DIR__ . '/migration_helpers.php';

$conn = migStart('026', 'Support impersonation');
if (!$conn) {
    return;
}

migEnsureColumn(
    $conn,
    'coiffure_sessions',
    'impersonated_by',
    "INT UNSIGNED DEFAULT NULL COMMENT 'user_id of the admin who started this support session'"
);

migEnsureIndex(
    $conn,
    'coiffure_sessions',
    'idx_session_impersonated',
    'INDEX idx_session_impersonated (impersonated_by)'
);

migFinish($conn, '026');
