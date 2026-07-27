<?php
/**
 * Migration Manager API
 * -------------------------------------------------------------------
 * Lists the available migrations and runs one on demand.
 *
 *   GET migration-manager.php?action=list
 *   GET migration-manager.php?action=run&migration=017
 *
 * ACCESS: administrator session, or ?token=<MIGRATION_TOKEN>, or CLI.
 * This endpoint executes schema changes, so it must never be open to the
 * internet -- requireMigrationAuth() enforces that for both actions.
 */

require_once __DIR__ . '/migration_helpers.php';

requireMigrationAuth();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    listMigrations();
} elseif ($action === 'run') {
    runMigration();
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

/**
 * All migrations in migrations/, newest last.
 *
 * 'probe' describes how to tell whether a migration has already been applied,
 * since there is no migrations tracking table:
 *   ['table', 'name']            applied when the table exists
 *   ['column', 'table', 'name']  applied when the column exists
 *   ['absent_column', 't', 'c']  applied when the column is GONE (drops)
 *   ['query', 'sql']             applied when the query returns count = 0
 */
function migrationCatalogue(): array
{
    return [
        ['number' => '001', 'name' => 'Add Social Links Table',
         'description' => 'Create table for salon social media links',
         'file' => '001_add_social_links_table.sql',
         'probe' => ['table', 'coiffure_social_links']],

        ['number' => '002', 'name' => 'User Salons Many-to-Many',
         'description' => 'Create junction table for user-salon assignments',
         'file' => '002_user_salons_many_to_many.sql',
         'probe' => ['table', 'coiffure_user_salons']],

        ['number' => '003', 'name' => 'Rename Customer User Role',
         'description' => 'Rename customer_user to customer_facing_tablet_user',
         'file' => '003_rename_customer_user_role.sql',
         'probe' => ['query', "SELECT COUNT(*) AS count FROM coiffure_users WHERE role = 'customer_user'"]],

        ['number' => '004', 'name' => 'Remove Salon ID from Users',
         'description' => 'DO NOT APPLY -- validateSession() still selects coiffure_users.salon_id, '
                        . 'so dropping this column breaks all authentication.',
         'file' => '004_remove_salon_id_from_users.sql',
         'probe' => ['absent_column', 'coiffure_users', 'salon_id'],
         'blocked' => true],

        ['number' => '005', 'name' => 'Add User Language Preference',
         'description' => 'Add preferred_language column to users table',
         'file' => '005_add_user_language_preference.sql',
         // The original probe looked for 'language_preference'; the column is
         // actually called preferred_language, so 005 always read as unapplied.
         'probe' => ['column', 'coiffure_users', 'preferred_language']],

        ['number' => '006', 'name' => 'Add Salon Default Language',
         'description' => 'Add default language setting for customer-facing tablets',
         'file' => '006_add_salon_default_language.sql',
         'probe' => ['column', 'coiffure_salons', 'default_language']],

        ['number' => '007', 'name' => 'Add Salon Branding',
         'description' => 'Add logo and color customization for white-labeling',
         'file' => '007_add_salon_branding.sql',
         'probe' => ['column', 'coiffure_salons', 'logo_path']],

        ['number' => '008', 'name' => 'Membership Registration',
         'description' => 'Membership fields on customers + employees table',
         'file' => '008_membership_registration.sql',
         'probe' => ['table', 'coiffure_employees']],

        ['number' => '009', 'name' => 'Visits / Check-in',
         'description' => 'Check-in visit log',
         'file' => '009_visits_checkin.sql',
         'probe' => ['table', 'coiffure_visits']],

        ['number' => '010', 'name' => 'Add Gender',
         'description' => 'Gender and title on customers',
         'file' => '010_add_gender.sql',
         'probe' => ['column', 'coiffure_customers', 'gender']],

        ['number' => '011', 'name' => 'Add WiFi',
         'description' => 'Guest WiFi SSID and password per salon',
         'file' => '011_add_wifi.sql',
         'probe' => ['column', 'coiffure_salons', 'wifi_ssid']],

        ['number' => '012', 'name' => 'Loyalty Config',
         'description' => 'Per-salon loyalty program configuration + staff PIN',
         'file' => '012_loyalty_config.sql',
         'probe' => ['column', 'coiffure_salons', 'loyalty_visit_threshold']],

        ['number' => '013', 'name' => 'Check-in Analytics',
         'description' => 'Check-in events, settings audit and lockouts',
         'file' => '013_checkin_analytics.sql',
         'probe' => ['table', 'coiffure_checkin_events']],

        ['number' => '014', 'name' => 'Salon Connections',
         'description' => 'Group multi-store brands to share a customer base',
         'file' => '014_salon_connections.sql',
         'probe' => ['table', 'coiffure_salon_connections']],

        ['number' => '015', 'name' => 'Global Settings',
         'description' => 'Key-value global settings (kiosk timeouts)',
         'file' => '015_global_settings.sql',
         'probe' => ['table', 'coiffure_global_settings']],

        ['number' => '016', 'name' => 'Trends',
         'description' => 'Tablet home-screen magazine slider content',
         'file' => '016_trends.sql',
         'probe' => ['table', 'coiffure_trends']],

        ['number' => '017', 'name' => 'Admin RBAC',
         'description' => 'Granular permissions + user invitations',
         'file' => '017_admin_rbac.sql',
         'probe' => ['table', 'coiffure_user_permissions']],

        ['number' => '018', 'name' => 'Salon Settings',
         'description' => 'Salon status, subdomain, tablet, birthday and referral settings',
         'file' => '018_salon_settings.sql',
         'probe' => ['column', 'coiffure_salons', 'subdomain']],

        ['number' => '019', 'name' => 'Segments',
         'description' => 'Saved customer segments',
         'file' => '019_segments.sql',
         'probe' => ['table', 'coiffure_segments']],

        ['number' => '020', 'name' => 'Campaigns',
         'description' => 'One-time and automatic marketing campaigns',
         'file' => '020_campaigns.sql',
         'probe' => ['table', 'coiffure_campaigns']],

        ['number' => '021', 'name' => 'Notifications',
         'description' => 'Notification centre and per-user preferences',
         'file' => '021_notifications.sql',
         'probe' => ['table', 'coiffure_notifications']],

        ['number' => '022', 'name' => 'Billing',
         'description' => 'Subscription plans, salon subscriptions and invoices',
         'file' => '022_billing.sql',
         'probe' => ['table', 'coiffure_subscription_plans']],

        ['number' => '023', 'name' => 'White-Label',
         'description' => 'Per-salon custom domain, SMTP and colours',
         'file' => '023_whitelabel.sql',
         'probe' => ['table', 'coiffure_salon_whitelabel']],

        ['number' => '024', 'name' => 'Audit & Consent',
         'description' => 'Widen the audit log and add GDPR consent history',
         'file' => '024_audit_consent.sql',
         'probe' => ['table', 'coiffure_consent_history']],
    ];
}

function listMigrations()
{
    $conn = getDbConnection();
    if (!$conn) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        return;
    }

    $migrations = migrationCatalogue();
    foreach ($migrations as &$migration) {
        $migration['applied'] = checkMigrationApplied($conn, $migration['probe']);
        $migration['runner_exists'] = file_exists(__DIR__ . "/apply_migration_{$migration['number']}.php");
        unset($migration['probe']);
    }
    unset($migration);

    echo json_encode(['success' => true, 'migrations' => $migrations]);
    $conn->close();
}

function checkMigrationApplied(mysqli $conn, array $probe): bool
{
    switch ($probe[0]) {
        case 'table':
            return migTableExists($conn, $probe[1]);

        case 'column':
            return migColumnExists($conn, $probe[1], $probe[2]);

        case 'absent_column':
            return migTableExists($conn, $probe[1]) && !migColumnExists($conn, $probe[1], $probe[2]);

        case 'query':
            $result = $conn->query($probe[1]);
            if (!$result) {
                return false;
            }
            $row = $result->fetch_assoc();
            return (int)($row['count'] ?? 1) === 0;
    }

    return false;
}

function runMigration()
{
    $migrationNumber = $_GET['migration'] ?? '';

    if (empty($migrationNumber)) {
        echo json_encode(['success' => false, 'error' => 'Migration number required']);
        return;
    }

    if (!preg_match('/^\d{3}$/', $migrationNumber)) {
        echo json_encode(['success' => false, 'error' => 'Invalid migration number format']);
        return;
    }

    // Migration 004 drops coiffure_users.salon_id, which validateSession() still
    // selects -- running it locks everyone out. Refuse rather than warn.
    foreach (migrationCatalogue() as $entry) {
        if ($entry['number'] === $migrationNumber && !empty($entry['blocked'])) {
            echo json_encode([
                'success' => false,
                'error' => "Migration {$migrationNumber} is blocked: {$entry['description']}",
            ]);
            return;
        }
    }

    $scriptPath = __DIR__ . "/apply_migration_{$migrationNumber}.php";

    if (!file_exists($scriptPath)) {
        echo json_encode([
            'success' => false,
            'error' => "Migration script not found: apply_migration_{$migrationNumber}.php",
        ]);
        return;
    }

    ob_start();
    try {
        include $scriptPath;
        $output = ob_get_clean();

        // The runners emit plain text and set their own Content-Type; restore
        // ours now that their output is captured rather than sent.
        header('Content-Type: application/json');

        echo json_encode([
            'success' => true,
            'output' => $output,
            'migration' => $migrationNumber,
        ]);
    } catch (Throwable $e) {
        $output = ob_get_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'output' => $output,
        ]);
    }
}
