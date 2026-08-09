<?php
/**
 * Apply Migration 028 - Monthly spend cap for AI overage
 *
 * Idempotent: the column is added only when missing.
 * Run from the browser (admin session or ?token=<MIGRATION_TOKEN>) or CLI:
 *   php api/apply_migration_028.php
 */

require_once __DIR__ . '/migration_helpers.php';

$conn = migStart('028', 'Monthly spend cap for AI overage');
if (!$conn) {
    return;
}

migEnsureColumn($conn, 'coiffure_salons', 'ai_overage_monthly_cap', 'DECIMAL(8,2) NOT NULL DEFAULT 0.00');

migFinish($conn, '028');
