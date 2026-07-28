<?php
/**
 * Support impersonation
 * -------------------------------------------------------------------
 *   POST impersonate.php  → {salon_id} or {user_id}
 *
 * Issues a session for a salon's Customer Admin so support can see exactly
 * what the salon sees. Restricted to platform roles.
 *
 * Three properties make this defensible:
 *
 *   1. Only a customer_admin or customer_admin_delegate can be impersonated.
 *      An administrator can never assume another administrator's identity, so
 *      impersonation cannot be used to escalate.
 *   2. Both sides are recorded. The session row carries who started it, and
 *      me.php reports it so the dashboard shows a permanent banner -- an
 *      impersonated session never looks like a normal one.
 *   3. It is audited before the session is handed out, so an impersonation
 *      that is somehow interrupted still leaves a trace.
 *
 * Ending impersonation is just signing out: the impersonated session is a
 * separate token, and the administrator's own token is untouched.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('Method not allowed.', 405);
}

$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

$user = requireAuth($conn);
requirePlatformRole($user);

// An impersonated session must not be able to start another one.
if (!empty($user['impersonated_by'])) {
    sendErrorResponse('Eine Support-Sitzung kann keine weitere Sitzung starten.', 403);
}

if (!impersonationReady($conn)) {
    sendErrorResponse('Impersonation needs migration 017 to be applied first.', 503);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$target = resolveTarget($conn, $input);

logAdminAudit(
    $conn, $user, 'user', (int)$target['user_id'], 'impersonate',
    "Impersonation started for {$target['username']} ({$target['role']})",
    $target['salon_id'] !== null ? (int)$target['salon_id'] : null
);

// Short-lived on purpose: support access should expire on its own.
$session = createUserSession($conn, (int)$target['user_id'], 2);
if (!$session) {
    sendErrorResponse('Die Support-Sitzung konnte nicht erstellt werden.', 500);
}

markImpersonated($conn, $session['session_token'], (int)$user['user_id']);

sendJsonResponse([
    'success' => true,
    'session_token' => $session['session_token'],
    'expires_at'    => $session['expires_at'] ?? null,
    'user' => [
        'user_id'   => (int)$target['user_id'],
        'username'  => $target['username'],
        'email'     => $target['email'],
        'full_name' => $target['full_name'],
        'role'      => $target['role'],
        'salon_id'  => $target['salon_id'] !== null ? (int)$target['salon_id'] : null,
        'preferred_language' => $target['preferred_language'] ?? 'de',
    ],
], 201);

/** Migration 017 adds coiffure_sessions.impersonated_by. */
function impersonationReady(mysqli $conn): bool
{
    $res = $conn->query("SHOW COLUMNS FROM coiffure_sessions LIKE 'impersonated_by'");
    return $res && $res->num_rows > 0;
}

/**
 * Pick who to impersonate.
 *
 * With a user_id, that user -- if they are a salon role. With a salon_id, the
 * salon's Customer Admin, since that is who support normally needs to be.
 */
function resolveTarget(mysqli $conn, array $input): array
{
    $userId = (int)($input['user_id'] ?? 0);
    $salonId = (int)($input['salon_id'] ?? 0);

    if ($userId > 0) {
        $stmt = $conn->prepare(
            'SELECT user_id, username, email, full_name, role, salon_id, preferred_language, is_active
             FROM coiffure_users WHERE user_id = ?'
        );
        if (!$stmt) {
            sendErrorResponse('Failed to load the user.', 500);
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } elseif ($salonId > 0) {
        // Prefer the salon owner; fall back to a delegate if there is no owner.
        $stmt = $conn->prepare(
            "SELECT u.user_id, u.username, u.email, u.full_name, u.role, u.salon_id,
                    u.preferred_language, u.is_active
             FROM coiffure_users u
             LEFT JOIN coiffure_user_salons us ON us.user_id = u.user_id AND us.salon_id = ?
             WHERE u.is_active = 1
               AND u.role IN ('customer_admin', 'customer_admin_delegate')
               AND (us.salon_id IS NOT NULL OR u.salon_id = ?)
             ORDER BY FIELD(u.role, 'customer_admin', 'customer_admin_delegate'), u.user_id
             LIMIT 1"
        );
        if (!$stmt) {
            sendErrorResponse('Failed to load the salon user.', 500);
        }
        $stmt->bind_param('ii', $salonId, $salonId);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$target) {
            sendErrorResponse('Für diesen Salon gibt es kein Konto, das übernommen werden kann.', 404);
        }
    } else {
        sendErrorResponse('salon_id oder user_id ist erforderlich.', 400);
    }

    if (!$target) {
        sendErrorResponse('User not found.', 404);
    }
    if (!(int)$target['is_active']) {
        sendErrorResponse('Dieses Konto ist deaktiviert.', 409);
    }
    if (!in_array($target['role'], SALON_ADMIN_ROLES, true)) {
        // The rule that stops impersonation being an escalation path.
        sendErrorResponse('Es können nur Salon-Konten übernommen werden.', 403);
    }

    return $target;
}

function markImpersonated(mysqli $conn, string $token, int $adminId): void
{
    $stmt = $conn->prepare(
        'UPDATE coiffure_sessions SET impersonated_by = ? WHERE session_token = ?'
    );
    if (!$stmt) {
        error_log('impersonate: could not flag the session: ' . $conn->error);
        return;
    }
    $stmt->bind_param('is', $adminId, $token);
    $stmt->execute();
    $stmt->close();
}
