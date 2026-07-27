<?php
/**
 * Apply Migration 025 - Staff notes and tags on a customer
 *
 * Idempotent: each column is added only when missing.
 * Run from the browser (admin session or ?token=<MIGRATION_TOKEN>) or CLI:
 *   php api/apply_migration_025.php
 */

require_once __DIR__ . '/migration_helpers.php';

$conn = migStart('025', 'Staff notes and tags on a customer');
if (!$conn) {
    return;
}

migEnsureColumn($conn, 'coiffure_customers', 'notes', 'TEXT DEFAULT NULL');
migEnsureColumn($conn, 'coiffure_customers', 'tags', 'VARCHAR(255) DEFAULT NULL');
migEnsureColumn($conn, 'coiffure_customers', 'notes_updated_at', 'TIMESTAMP NULL DEFAULT NULL');
migEnsureColumn($conn, 'coiffure_customers', 'notes_updated_by', 'INT UNSIGNED DEFAULT NULL');

migFinish($conn, '025');
