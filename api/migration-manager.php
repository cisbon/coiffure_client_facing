<?php
/**
 * Migration Manager API
 * Handles listing and running database migrations
 */

header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    listMigrations();
} elseif ($action === 'run') {
    runMigration();
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function listMigrations() {
    $conn = getDbConnection();
    if (!$conn) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        return;
    }

    // Define all migrations with metadata
    $migrations = [
        [
            'number' => '001',
            'name' => 'Add Social Links Table',
            'description' => 'Create table for salon social media links',
            'file' => '001_add_social_links_table.sql'
        ],
        [
            'number' => '002',
            'name' => 'User Salons Many-to-Many',
            'description' => 'Create junction table for user-salon assignments',
            'file' => '002_user_salons_many_to_many.sql'
        ],
        [
            'number' => '003',
            'name' => 'Rename Customer User Role',
            'description' => 'Update user role from customer to customer_facing_tablet_user',
            'file' => '003_rename_customer_user_role.sql'
        ],
        [
            'number' => '004',
            'name' => 'Remove Salon ID from Users',
            'description' => 'Remove deprecated salon_id column from users table',
            'file' => '004_remove_salon_id_from_users.sql'
        ],
        [
            'number' => '005',
            'name' => 'Add User Language Preference',
            'description' => 'Add language preference column to users table',
            'file' => '005_add_user_language_preference.sql'
        ],
        [
            'number' => '006',
            'name' => 'Add Salon Default Language',
            'description' => 'Add default language setting for customer-facing tablets',
            'file' => '006_add_salon_default_language.sql'
        ],
        [
            'number' => '007',
            'name' => 'Add Salon Branding',
            'description' => 'Add logo and color customization for white-labeling',
            'file' => '007_add_salon_branding.sql'
        ]
    ];

    // Check which migrations are already applied
    foreach ($migrations as &$migration) {
        $migration['applied'] = checkMigrationApplied($conn, $migration['number']);
    }

    echo json_encode([
        'success' => true,
        'migrations' => $migrations
    ]);

    $conn->close();
}

function checkMigrationApplied($conn, $number) {
    // Check specific columns based on migration number
    switch ($number) {
        case '001':
            $query = "SHOW TABLES LIKE 'coiffure_social_links'";
            break;
        case '002':
            $query = "SHOW TABLES LIKE 'coiffure_user_salons'";
            break;
        case '003':
            // Check if old 'customer' role still exists
            $query = "SELECT COUNT(*) as count FROM coiffure_users WHERE role = 'customer'";
            $result = $conn->query($query);
            if ($result) {
                $row = $result->fetch_assoc();
                return $row['count'] == 0; // Applied if no old role exists
            }
            return false;
        case '004':
            $query = "SHOW COLUMNS FROM coiffure_users LIKE 'salon_id'";
            $result = $conn->query($query);
            return $result && $result->num_rows == 0; // Applied if column doesn't exist
        case '005':
            $query = "SHOW COLUMNS FROM coiffure_users LIKE 'language_preference'";
            break;
        case '006':
            $query = "SHOW COLUMNS FROM coiffure_salons LIKE 'default_language'";
            break;
        case '007':
            $query = "SHOW COLUMNS FROM coiffure_salons LIKE 'logo_path'";
            break;
        default:
            return false;
    }

    $result = $conn->query($query);
    return $result && $result->num_rows > 0;
}

function runMigration() {
    $migrationNumber = $_GET['migration'] ?? '';

    if (empty($migrationNumber)) {
        echo json_encode(['success' => false, 'error' => 'Migration number required']);
        return;
    }

    // Security: Only allow migration numbers 001-999
    if (!preg_match('/^\d{3}$/', $migrationNumber)) {
        echo json_encode(['success' => false, 'error' => 'Invalid migration number format']);
        return;
    }

    $scriptPath = __DIR__ . "/apply_migration_{$migrationNumber}.php";

    if (!file_exists($scriptPath)) {
        echo json_encode(['success' => false, 'error' => "Migration script not found: apply_migration_{$migrationNumber}.php"]);
        return;
    }

    // Execute migration script and capture output
    ob_start();
    try {
        include $scriptPath;
        $output = ob_get_clean();

        echo json_encode([
            'success' => true,
            'output' => $output,
            'migration' => $migrationNumber
        ]);
    } catch (Exception $e) {
        $output = ob_get_clean();
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'output' => $output
        ]);
    }
}
