<?php
/**
 * Current User Endpoint
 * -------------------------------------------------------------------
 *   GET me.php  → the signed-in user, their salons and effective permissions
 *
 * The dashboard calls this once on boot and uses the response to build the
 * sidebar. auth-login.php returns the same user shape, but the token in
 * localStorage outlives a single login: roles, salon assignments and delegate
 * permissions can all change while a session is alive, so the dashboard always
 * re-reads them here rather than trusting the cached user_data blob.
 *
 * The permission list is advisory for the UI only -- every other endpoint
 * re-checks with requirePermission()/resolveSalonScope().
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendErrorResponse('Method not allowed. Use GET.', 405);
}

$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

$user = requireAuth($conn);
requireDashboardAccess($user);

// ------------------------------------------------------------------
// Assigned salons (the junction table is the source of truth since 002)
// ------------------------------------------------------------------
$salons = [];
$salonIds = getAccessibleSalonIds($conn, $user);

if (!empty($salonIds)) {
    $in = salonInClause($salonIds);
    $sql = "SELECT salon_id, salon_name, default_language, primary_color, secondary_color,
                   logo_path, is_active
            FROM coiffure_salons
            WHERE salon_id IN {$in['sql']}
            ORDER BY salon_name";

    // status/subdomain only exist once migration 018 has run.
    $hasStatus = migrationColumnPresent($conn, 'coiffure_salons', 'status');
    if ($hasStatus) {
        $sql = str_replace('logo_path, is_active', 'logo_path, is_active, status, subdomain', $sql);
    }

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($in['types'], ...$in['values']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $salons[] = [
                'salon_id'         => (int)$row['salon_id'],
                'salon_name'       => $row['salon_name'],
                'default_language' => $row['default_language'] ?? 'de',
                'primary_color'    => $row['primary_color'] ?? null,
                'secondary_color'  => $row['secondary_color'] ?? null,
                'logo_path'        => $row['logo_path'] ?? null,
                'is_active'        => (int)($row['is_active'] ?? 1),
                'status'           => $row['status'] ?? 'active',
                'subdomain'        => $row['subdomain'] ?? null,
            ];
        }
        $stmt->close();
    }
}

// ------------------------------------------------------------------
// Permissions
//   'permissions'        union across all accessible salons (drives the sidebar)
//   'permissions_by_salon' per-salon grants, so switching salons in the top bar
//                        can re-gate the UI without another round trip. Only
//                        meaningful for customer_admin_delegate; for every other
//                        role the list is identical everywhere.
// ------------------------------------------------------------------
$permissions = getEffectivePermissions($conn, $user);

$permissionsBySalon = [];
if ($user['role'] === 'customer_admin_delegate') {
    foreach ($salonIds as $sid) {
        $permissionsBySalon[(string)$sid] = getEffectivePermissions($conn, $user, $sid);
    }
} else {
    foreach ($salonIds as $sid) {
        $permissionsBySalon[(string)$sid] = $permissions;
    }
}

// ------------------------------------------------------------------
// Impersonation banner: createUserSession() records the acting admin when a
// support session is started from the Salons drawer (see impersonate.php).
// ------------------------------------------------------------------
$impersonation = null;
if (!empty($user['impersonated_by'])) {
    $impersonation = [
        'active'            => true,
        'impersonated_by'   => $user['impersonated_by'],
    ];
}

sendJsonResponse([
    'success' => true,
    'user' => [
        'user_id'            => (int)$user['user_id'],
        'username'           => $user['username'],
        'email'              => $user['email'] ?? null,
        'full_name'          => $user['full_name'] ?? null,
        'role'               => $user['role'],
        'preferred_language' => $user['preferred_language'] ?? 'de',
        'salon_id'           => !empty($salonIds) ? $salonIds[0] : null,
    ],
    'salons'               => $salons,
    'permissions'          => array_values($permissions),
    'permissions_by_salon' => $permissionsBySalon,
    'is_platform_admin'    => in_array($user['role'], PLATFORM_ROLES, true),
    'impersonation'        => $impersonation,
], 200);

/**
 * Small local guard so this endpoint keeps working before migration 018 runs.
 */
function migrationColumnPresent(mysqli $conn, string $table, string $column): bool
{
    $safe = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$safe'");
    return $res && $res->num_rows > 0;
}
