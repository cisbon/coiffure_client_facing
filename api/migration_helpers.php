<?php
/**
 * Shared helpers for the apply_migration_*.php runners.
 * -------------------------------------------------------------------
 * Migrations 004-016 each inlined their own ensureColumn()/SHOW COLUMNS
 * guards. From 017 onwards the runners share these instead, so every runner
 * stays idempotent in the same way and prints the same plain-text report.
 *
 * Also provides requireMigrationAuth(): the runners are reachable over the
 * public internet, so they are gated behind an administrator session or the
 * MIGRATION_TOKEN env var. CLI invocations (php api/apply_migration_017.php)
 * are always allowed.
 */

require_once __DIR__ . '/config.php';

/**
 * Gate a migration runner.
 *
 * Allowed callers:
 *   - CLI (php_sapi_name() === 'cli')
 *   - a logged-in user with role 'admin'
 *   - a request carrying ?token=<MIGRATION_TOKEN> (or an X-Migration-Token header)
 *
 * Prints an error and stops the script for anyone else.
 */
function requireMigrationAuth(): void
{
    if (php_sapi_name() === 'cli') {
        return;
    }

    header('Content-Type: text/plain; charset=utf-8');

    $expected = getenv('MIGRATION_TOKEN') ?: '';
    $provided = $_GET['token']
        ?? $_SERVER['HTTP_X_MIGRATION_TOKEN']
        ?? '';

    if ($expected !== '' && is_string($provided) && hash_equals($expected, $provided)) {
        return;
    }

    // Fall back to an administrator session.
    $conn = getDbConnection();
    if ($conn) {
        $token = getSessionToken();
        $user = $token ? validateSession($conn, $token) : null;
        if ($user && $user['role'] === 'admin') {
            return;
        }
    }

    http_response_code(403);
    echo "Forbidden. Migrations require an administrator session or a valid MIGRATION_TOKEN.\n";
    exit;
}

/** Does a table exist in the current database? */
function migTableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$safe'");
    return $res && $res->num_rows > 0;
}

/** Does a column exist on a table? */
function migColumnExists(mysqli $conn, string $table, string $column): bool
{
    if (!migTableExists($conn, $table)) {
        return false;
    }
    $safeCol = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$safeCol'");
    return $res && $res->num_rows > 0;
}

/** Does an index exist on a table? */
function migIndexExists(mysqli $conn, string $table, string $index): bool
{
    if (!migTableExists($conn, $table)) {
        return false;
    }
    $safeIdx = $conn->real_escape_string($index);
    $res = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$safeIdx'");
    return $res && $res->num_rows > 0;
}

/** Run a CREATE TABLE IF NOT EXISTS and report what happened. */
function migEnsureTable(mysqli $conn, string $table, string $createSql): void
{
    if (migTableExists($conn, $table)) {
        echo "  - table $table already exists, skipping\n";
        return;
    }
    if ($conn->query($createSql)) {
        echo "  + created table $table\n";
    } else {
        echo "  ! failed to create $table: " . $conn->error . "\n";
    }
}

/** Add a column only when it is missing. */
function migEnsureColumn(mysqli $conn, string $table, string $column, string $definition): void
{
    if (!migTableExists($conn, $table)) {
        echo "  ! table $table does not exist, cannot add $column\n";
        return;
    }
    if (migColumnExists($conn, $table, $column)) {
        echo "  - $table.$column already exists, skipping\n";
        return;
    }
    if ($conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition")) {
        echo "  + added $table.$column\n";
    } else {
        echo "  ! failed to add $table.$column: " . $conn->error . "\n";
    }
}

/** Change a column definition unconditionally (MODIFY is naturally idempotent). */
function migModifyColumn(mysqli $conn, string $table, string $column, string $definition): void
{
    if (!migColumnExists($conn, $table, $column)) {
        echo "  ! $table.$column does not exist, cannot modify\n";
        return;
    }
    if ($conn->query("ALTER TABLE `$table` MODIFY COLUMN `$column` $definition")) {
        echo "  ~ modified $table.$column -> $definition\n";
    } else {
        echo "  ! failed to modify $table.$column: " . $conn->error . "\n";
    }
}

/** Add an index only when it is missing. */
function migEnsureIndex(mysqli $conn, string $table, string $index, string $definition): void
{
    if (!migTableExists($conn, $table)) {
        echo "  ! table $table does not exist, cannot add index $index\n";
        return;
    }
    if (migIndexExists($conn, $table, $index)) {
        echo "  - index $table.$index already exists, skipping\n";
        return;
    }
    if ($conn->query("ALTER TABLE `$table` ADD $definition")) {
        echo "  + added index $table.$index\n";
    } else {
        echo "  ! failed to add index $table.$index: " . $conn->error . "\n";
    }
}

/** Run a plain statement, reporting success or the MySQL error. */
function migRun(mysqli $conn, string $sql, string $label): void
{
    if ($conn->query($sql)) {
        echo "  + $label\n";
    } else {
        echo "  ! $label failed: " . $conn->error . "\n";
    }
}

/** Print the standard runner banner and return a connection, or null. */
function migStart(string $number, string $title): ?mysqli
{
    requireMigrationAuth();

    if (php_sapi_name() !== 'cli') {
        header('Content-Type: text/plain; charset=utf-8');
    }

    $line = "Applying migration $number: $title";
    echo $line . "\n";
    echo str_repeat('=', strlen($line)) . "\n";

    $conn = getDbConnection();
    if (!$conn) {
        echo "ERROR: Database connection failed\n";
        return null;
    }
    return $conn;
}

/** Print the standard runner footer. */
function migFinish(mysqli $conn, string $number): void
{
    echo "\nSUCCESS: Migration $number applied.\n";
    $conn->close();
}
