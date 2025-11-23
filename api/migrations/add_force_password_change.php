<?php
/**
 * Migration: Add force_password_change field to users table
 *
 * This migration adds a flag to force users to change their password on first login.
 * Used for newly created salon owner and tablet accounts.
 */

require_once __DIR__ . '/../config.php';

function up_add_force_password_change($pdo) {
    try {
        // Check if column already exists
        $stmt = $pdo->query("SHOW COLUMNS FROM coiffure_users LIKE 'force_password_change'");
        if ($stmt->rowCount() > 0) {
            echo "Column 'force_password_change' already exists. Skipping.\n";
            return true;
        }

        // Add force_password_change column
        $sql = "ALTER TABLE coiffure_users
                ADD COLUMN force_password_change BOOLEAN DEFAULT FALSE AFTER password_hash";

        $pdo->exec($sql);

        echo "Successfully added 'force_password_change' column to coiffure_users table.\n";
        return true;

    } catch (PDOException $e) {
        echo "Error in migration: " . $e->getMessage() . "\n";
        return false;
    }
}

function down_add_force_password_change($pdo) {
    try {
        $sql = "ALTER TABLE coiffure_users DROP COLUMN IF EXISTS force_password_change";
        $pdo->exec($sql);

        echo "Successfully removed 'force_password_change' column from coiffure_users table.\n";
        return true;

    } catch (PDOException $e) {
        echo "Error in rollback: " . $e->getMessage() . "\n";
        return false;
    }
}

// Run migration if executed directly
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    $action = $argv[1] ?? 'up';

    if ($action === 'up') {
        $result = up_add_force_password_change($pdo);
        exit($result ? 0 : 1);
    } elseif ($action === 'down') {
        $result = down_add_force_password_change($pdo);
        exit($result ? 0 : 1);
    } else {
        echo "Usage: php " . basename(__FILE__) . " [up|down]\n";
        exit(1);
    }
}
