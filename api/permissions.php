<?php
/**
 * Role & permission helpers for the admin dashboard
 * -------------------------------------------------------------------
 * config.php already provides role-level checks (hasRole, requireRole,
 * canManageSalon). This file adds the granular layer the dashboard needs:
 *
 *   - a single permission matrix, so "what may this user do" is answered in
 *     one place instead of being re-derived per endpoint
 *   - requirePermission(), the permission-level twin of requireRole()
 *   - resolveSalonScope(), which turns a client-supplied salon_id into a salon
 *     the caller is actually allowed to touch (or 403s)
 *
 * The frontend receives the same permission list from api/me.php and uses it to
 * build the sidebar, but the server remains the authority: every endpoint calls
 * requirePermission()/resolveSalonScope() regardless of what the UI shows.
 *
 * This file is a helper, not an endpoint -- it never emits output on include.
 */

require_once __DIR__ . '/config.php';

/**
 * Salon-scoped permissions. These are the six checkboxes a Customer Admin can
 * tick when inviting a Customer Admin Delegate (spec 3.4).
 *
 * manage_products / manage_magazine are recognised by the matrix but no module
 * ships with them yet -- they are listed here so enabling those modules later
 * needs no migration and no change to the permission plumbing.
 */
const SALON_PERMISSIONS = [
    'manage_campaigns',
    'view_insights',
    'manage_products',
    'manage_magazine',
    'manage_users',
    'change_settings',
];

/**
 * Platform-wide permissions. Administrators hold all of them; Admin Delegates
 * hold none, which is exactly the set of restrictions the spec lists for them:
 * no billing, no platform configuration, no deleting administrators, and no
 * visibility of other administrators' audit entries.
 */
const PLATFORM_PERMISSIONS = [
    'platform_billing',   // subscription plans, invoices
    'platform_config',    // global kiosk settings, white-label, SMTP
    'delete_admin',       // delete or demote an admin / admin_delegate
    'view_all_audit',     // see audit entries produced by administrators
];

/** Roles that administer the whole platform rather than a single salon. */
const PLATFORM_ROLES = ['admin', 'admin_delegate'];

/** Roles that administer one or more individual salons. */
const SALON_ADMIN_ROLES = ['customer_admin', 'customer_admin_delegate'];

/** Every role that may open the dashboard at all. */
const DASHBOARD_ROLES = ['admin', 'admin_delegate', 'customer_admin', 'customer_admin_delegate'];

/**
 * Compute the effective permission list for a user.
 *
 * - admin                     -> everything
 * - admin_delegate            -> every salon permission, no platform permission
 * - customer_admin            -> every salon permission (for its own salons)
 * - customer_admin_delegate   -> only what coiffure_user_permissions grants
 * - anything else (tablet)    -> nothing
 *
 * @param int|null $salonId When given, delegate grants are filtered to that
 *                          salon. Omit to get the union across all salons.
 * @return string[] permission keys
 */
function getEffectivePermissions(mysqli $conn, array $user, ?int $salonId = null): array
{
    $role = $user['role'] ?? '';

    if ($role === 'admin') {
        return array_merge(SALON_PERMISSIONS, PLATFORM_PERMISSIONS);
    }

    if ($role === 'admin_delegate') {
        // Full operational control, but none of the four platform powers.
        return SALON_PERMISSIONS;
    }

    if ($role === 'customer_admin') {
        return SALON_PERMISSIONS;
    }

    if ($role === 'customer_admin_delegate') {
        return getDelegatePermissions($conn, (int)$user['user_id'], $salonId);
    }

    return [];
}

/**
 * Read the explicit grants for a customer_admin_delegate.
 * Returns an empty list when the table is missing (migration 017 not yet run),
 * which fails closed rather than open.
 */
function getDelegatePermissions(mysqli $conn, int $userId, ?int $salonId = null): array
{
    $check = $conn->query("SHOW TABLES LIKE 'coiffure_user_permissions'");
    if (!$check || $check->num_rows === 0) {
        return [];
    }

    if ($salonId !== null) {
        $stmt = $conn->prepare(
            "SELECT DISTINCT permission FROM coiffure_user_permissions
             WHERE user_id = ? AND salon_id = ?"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $userId, $salonId);
    } else {
        $stmt = $conn->prepare(
            "SELECT DISTINCT permission FROM coiffure_user_permissions WHERE user_id = ?"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $userId);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $permissions = [];
    while ($row = $result->fetch_assoc()) {
        // Ignore anything not in the known matrix, so a stale row cannot widen
        // access after a permission key is retired.
        if (in_array($row['permission'], SALON_PERMISSIONS, true)) {
            $permissions[] = $row['permission'];
        }
    }
    $stmt->close();

    return $permissions;
}

/**
 * Does this user hold a permission?
 *
 * @param int|null $salonId Scope for delegate grants; omit for "in any salon".
 */
function hasPermission(mysqli $conn, array $user, string $permission, ?int $salonId = null): bool
{
    return in_array($permission, getEffectivePermissions($conn, $user, $salonId), true);
}

/**
 * Require a permission - sends 403 and exits when the user lacks it.
 * Mirrors requireRole() in config.php.
 */
function requirePermission(mysqli $conn, array $user, string $permission, ?int $salonId = null): void
{
    if (!hasPermission($conn, $user, $permission, $salonId)) {
        sendErrorResponse('Forbidden. Insufficient permissions.', 403, [
            'required_permission' => $permission,
        ]);
    }
}

/**
 * Require that the user may administer the platform (Administrator or Admin
 * Delegate). Used by the Salons module.
 */
function requirePlatformRole(array $user): void
{
    requireRole($user, PLATFORM_ROLES);
}

/**
 * Every salon id this user may act on.
 *
 * Platform roles get all non-deleted salons; salon roles get their assignments
 * from coiffure_user_salons (falling back to the legacy users.salon_id column,
 * same as canManageSalon()).
 *
 * @return int[]
 */
function getAccessibleSalonIds(mysqli $conn, array $user): array
{
    $role = $user['role'] ?? '';
    $ids = [];

    if (in_array($role, PLATFORM_ROLES, true)) {
        $result = $conn->query("SELECT salon_id FROM coiffure_salons ORDER BY salon_id");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $ids[] = (int)$row['salon_id'];
            }
        }
        return $ids;
    }

    $stmt = $conn->prepare(
        "SELECT salon_id FROM coiffure_user_salons WHERE user_id = ? ORDER BY salon_id"
    );
    if ($stmt) {
        $stmt->bind_param('i', $user['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['salon_id'];
        }
        $stmt->close();
    }

    if (empty($ids) && !empty($user['salon_id'])) {
        $ids[] = (int)$user['salon_id'];
    }

    return $ids;
}

/**
 * Turn a client-supplied salon_id into one this user may act on.
 *
 * Never trust the request: a Customer Admin passing someone else's salon_id
 * gets a 403, not their data. When no salon_id is supplied the user's first
 * assigned salon is used, which is what a single-salon owner always wants.
 *
 * @param mixed $requestedSalonId Raw value from $_GET/$_POST, or null.
 * @return int the salon id to operate on
 */
function resolveSalonScope(mysqli $conn, array $user, $requestedSalonId = null): int
{
    $accessible = getAccessibleSalonIds($conn, $user);

    if (empty($accessible)) {
        sendErrorResponse('No salon is assigned to this account.', 403);
    }

    if ($requestedSalonId === null || $requestedSalonId === '' || $requestedSalonId === 'null') {
        return $accessible[0];
    }

    $salonId = (int)$requestedSalonId;
    if ($salonId <= 0) {
        sendErrorResponse('Invalid salon_id.', 400);
    }

    if (!in_array($salonId, $accessible, true)) {
        sendErrorResponse('Forbidden. You do not have access to this salon.', 403);
    }

    return $salonId;
}

/**
 * Resolve an optional "all salons" scope.
 *
 * The dashboard home offers an aggregated view for users assigned to several
 * salons. Pass the raw ?salon_id= value: 'all' yields every accessible salon,
 * anything else yields the single resolved salon.
 *
 * @return int[]
 */
function resolveSalonScopeList(mysqli $conn, array $user, $requestedSalonId = null): array
{
    if ($requestedSalonId === 'all') {
        $accessible = getAccessibleSalonIds($conn, $user);
        if (empty($accessible)) {
            sendErrorResponse('No salon is assigned to this account.', 403);
        }
        return $accessible;
    }

    return [resolveSalonScope($conn, $user, $requestedSalonId)];
}

/**
 * Require that the caller may open the dashboard at all.
 * Tablet users are redirected to index.html by login.html; this is the
 * server-side counterpart.
 */
function requireDashboardAccess(array $user): void
{
    requireRole($user, DASHBOARD_ROLES);
}

/**
 * Build a `salon_id IN (?, ?, ...)` fragment plus its bind arguments.
 *
 * Every list endpoint needs this, and hand-rolling it invites an unparameterised
 * implode(). Returns ['sql' => '(?, ?)', 'types' => 'ii', 'values' => [1, 2]].
 */
function salonInClause(array $salonIds): array
{
    $salonIds = array_values(array_map('intval', $salonIds));
    if (empty($salonIds)) {
        // Impossible id keeps the query valid and returns no rows.
        $salonIds = [0];
    }

    return [
        'sql'    => '(' . implode(', ', array_fill(0, count($salonIds), '?')) . ')',
        'types'  => str_repeat('i', count($salonIds)),
        'values' => $salonIds,
    ];
}

/**
 * Audit helper that records the acting user's id, role and salon.
 *
 * config.php's logAudit() only stores a username string. Migration 024 widened
 * coiffure_audit_log and added salon_id / performed_by_id / performed_by_role so
 * the audit view can filter by salon and hide administrator actions from Admin
 * Delegates. Falls back to plain logAudit() when 024 has not been applied.
 */
function logAdminAudit(
    mysqli $conn,
    array $user,
    string $entityType,
    int $entityId,
    string $action,
    ?string $details = null,
    ?int $salonId = null
): void {
    static $hasNewColumns = null;

    if ($hasNewColumns === null) {
        $res = $conn->query("SHOW COLUMNS FROM coiffure_audit_log LIKE 'performed_by_id'");
        $hasNewColumns = $res && $res->num_rows > 0;
    }

    if (!$hasNewColumns) {
        logAudit($conn, $entityType, $entityId, $action, $details, $user['username'] ?? 'system');
        return;
    }

    $stmt = $conn->prepare(
        "INSERT INTO coiffure_audit_log
            (entity_type, entity_id, action, action_details, performed_by,
             performed_by_id, performed_by_role, salon_id, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        error_log('logAdminAudit: prepare failed: ' . $conn->error);
        return;
    }

    $performedBy = $user['username'] ?? 'system';
    $performedById = isset($user['user_id']) ? (int)$user['user_id'] : null;
    $role = $user['role'] ?? null;
    $ip = getClientIp();
    $ua = getUserAgent();

    // entity_type s, entity_id i, action s, details s, performed_by s,
    // performed_by_id i, performed_by_role s, salon_id i, ip s, user_agent s
    $stmt->bind_param(
        'sisssisiss',
        $entityType,
        $entityId,
        $action,
        $details,
        $performedBy,
        $performedById,
        $role,
        $salonId,
        $ip,
        $ua
    );

    if (!$stmt->execute()) {
        error_log('logAdminAudit: execute failed: ' . $stmt->error);
    }
    $stmt->close();
}
