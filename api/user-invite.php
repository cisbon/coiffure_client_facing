<?php
/**
 * User invitations and granular permissions
 * -------------------------------------------------------------------
 *   GET    user-invite.php                       → pending invitations
 *   POST   user-invite.php                       → invite {email, full_name, role, permissions[]}
 *   POST   user-invite.php?action=resend         → {invitation_id}
 *   DELETE user-invite.php?invitation_id=N       → revoke
 *
 *   GET    user-invite.php?action=permissions&user_id=N  → a delegate's grants
 *   POST   user-invite.php?action=permissions            → {user_id, permissions[]}
 *
 * Invitations carry a token; the invitee chooses their own password through
 * set-password.html + api/auth-set-password.php. Nothing ever mails a password,
 * which is what the old salon onboarding did.
 *
 * Access: manage_users, scoped to one salon.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/notify.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

$user = requireAuth($conn);
requireDashboardAccess($user);

$salonId = resolveSalonScope($conn, $user, $_GET['salon_id'] ?? null);
requirePermission($conn, $user, 'manage_users', $salonId);

if (!invitationsReady($conn)) {
    sendErrorResponse('Invitations are unavailable until migration 017 has been applied.', 503);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];

if ($action === 'permissions') {
    if ($method === 'GET')  handleGetPermissions($conn, $salonId);
    if ($method === 'POST') handleSetPermissions($conn, $salonId, $user, $input);
    sendErrorResponse('Method not allowed.', 405);
}

switch ($method) {
    case 'GET':
        handleList($conn, $salonId);
        break;
    case 'POST':
        if ($action === 'resend') handleResend($conn, $salonId, $user, $input);
        handleCreate($conn, $salonId, $user, $input);
        break;
    case 'DELETE':
        handleRevoke($conn, $salonId, $user);
        break;
    default:
        sendErrorResponse('Method not allowed.', 405);
}

function invitationsReady(mysqli $conn): bool
{
    $res = $conn->query("SHOW TABLES LIKE 'coiffure_user_invitations'");
    return $res && $res->num_rows > 0;
}

/** Roles this caller may invite, mirroring validateUserCreationPermissions(). */
function invitableRoles(array $user): array
{
    if ($user['role'] === 'admin') {
        return ['admin', 'admin_delegate', 'customer_admin', 'customer_admin_delegate', 'customer_facing_tablet_user'];
    }
    if ($user['role'] === 'admin_delegate') {
        return ['customer_admin', 'customer_admin_delegate', 'customer_facing_tablet_user'];
    }
    return ['customer_admin_delegate', 'customer_facing_tablet_user'];
}

function handleList(mysqli $conn, int $salonId): void
{
    // Expire anything past its date before listing, so the UI never offers to
    // resend something the accept endpoint would refuse.
    $conn->query(
        "UPDATE coiffure_user_invitations
         SET status = 'expired'
         WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < NOW()"
    );

    $stmt = $conn->prepare(
        'SELECT invitation_id, email, full_name, role, permissions, status,
                expires_at, accepted_at, created_at
         FROM coiffure_user_invitations
         WHERE salon_id = ?
         ORDER BY created_at DESC LIMIT 100'
    );
    if (!$stmt) {
        sendErrorResponse('Failed to load invitations.', 500);
    }
    $stmt->bind_param('i', $salonId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    sendJsonResponse([
        'success' => true,
        'invitations' => array_map(static fn($row) => [
            'invitation_id' => (int)$row['invitation_id'],
            'email'         => $row['email'],
            'full_name'     => $row['full_name'],
            'role'          => $row['role'],
            'permissions'   => json_decode((string)$row['permissions'], true) ?: [],
            'status'        => $row['status'],
            'expires_at'    => $row['expires_at'],
            'accepted_at'   => $row['accepted_at'],
            'created_at'    => $row['created_at'],
        ], $rows),
    ], 200);
}

function handleCreate(mysqli $conn, int $salonId, array $user, array $input): void
{
    $errors = [];

    $email = strtolower(trim((string)($input['email'] ?? '')));
    $fullName = trim((string)($input['full_name'] ?? ''));
    $role = (string)($input['role'] ?? 'customer_admin_delegate');

    if ($email === '') $errors['email'] = 'Eine E-Mail-Adresse ist erforderlich.';
    elseif (!validateEmail($email)) $errors['email'] = 'Bitte eine gültige E-Mail-Adresse angeben.';

    if ($fullName === '') $errors['full_name'] = 'Ein Name ist erforderlich.';

    if (!in_array($role, invitableRoles($user), true)) {
        $errors['role'] = 'Diese Rolle dürfen Sie nicht vergeben.';
    }

    // An address that already has an account cannot be invited again.
    if ($email !== '' && empty($errors['email'])) {
        $check = $conn->prepare('SELECT user_id FROM coiffure_users WHERE email = ?');
        if ($check) {
            $check->bind_param('s', $email);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $errors['email'] = 'Für diese E-Mail-Adresse existiert bereits ein Konto.';
            }
            $check->close();
        }

        $pending = $conn->prepare(
            "SELECT invitation_id FROM coiffure_user_invitations
             WHERE email = ? AND salon_id = ? AND status = 'pending'"
        );
        if ($pending) {
            $pending->bind_param('si', $email, $salonId);
            $pending->execute();
            if ($pending->get_result()->num_rows > 0) {
                $errors['email'] = 'Für diese Adresse ist bereits eine Einladung offen.';
            }
            $pending->close();
        }
    }

    if ($errors) {
        sendErrorResponse('Bitte prüfen Sie Ihre Eingaben.', 422, ['fields' => $errors]);
    }

    // Only a delegate carries granular grants; other roles derive them.
    $permissions = [];
    if ($role === 'customer_admin_delegate') {
        $permissions = array_values(array_intersect(
            (array)($input['permissions'] ?? []),
            SALON_PERMISSIONS
        ));
    }

    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
    $permissionsJson = json_encode($permissions);
    $invitedBy = (int)$user['user_id'];

    $stmt = $conn->prepare(
        "INSERT INTO coiffure_user_invitations
            (token, email, full_name, role, salon_id, permissions, invited_by, status, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)"
    );
    if (!$stmt) {
        sendErrorResponse('Die Einladung konnte nicht erstellt werden.', 500);
    }
    bindTyped($stmt, [$token, $email, $fullName, $role, $salonId, $permissionsJson, $invitedBy, $expiresAt]);
    if (!$stmt->execute()) {
        $stmt->close();
        sendErrorResponse('Die Einladung konnte nicht erstellt werden.', 500);
    }
    $invitationId = $stmt->insert_id;
    $stmt->close();

    $sent = deliverInvitation($conn, $salonId, [
        'email' => $email, 'full_name' => $fullName,
    ], $token);

    logAdminAudit(
        $conn, $user, 'user', $invitationId, 'user_invited',
        "Invited $email as $role", $salonId
    );

    // The other administrators of this salon should see that the team changed,
    // without the inviter being told about their own action.
    notifySalonAdmins(
        $conn, $salonId, 'user_invited', 'admin.notify.user_invited',
        ['name' => $fullName], '#/benutzer', 'manage_users', (int)$user['user_id']
    );

    sendJsonResponse([
        'success' => true,
        'invitation_id' => $invitationId,
        'email_sent' => $sent,
        // Surfaced so an admin can pass the link on by hand when mail is down.
        'accept_url' => invitationUrl($token),
    ], 201);
}

function handleResend(mysqli $conn, int $salonId, array $user, array $input): void
{
    $invitationId = (int)($input['invitation_id'] ?? 0);
    $invitation = loadInvitation($conn, $invitationId, $salonId);

    if ($invitation['status'] !== 'pending') {
        sendErrorResponse('Nur offene Einladungen können erneut gesendet werden.', 409);
    }

    // Refresh the window so a resent invitation is usable for another week.
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
    $stmt = $conn->prepare('UPDATE coiffure_user_invitations SET expires_at = ? WHERE invitation_id = ?');
    if ($stmt) {
        bindTyped($stmt, [$expiresAt, $invitationId]);
        $stmt->execute();
        $stmt->close();
    }

    $sent = deliverInvitation($conn, $salonId, $invitation, $invitation['token']);

    logAdminAudit($conn, $user, 'user', $invitationId, 'user_invited',
        "Invitation resent to {$invitation['email']}", $salonId);

    sendJsonResponse([
        'success' => true,
        'email_sent' => $sent,
        'accept_url' => invitationUrl($invitation['token']),
    ], 200);
}

function handleRevoke(mysqli $conn, int $salonId, array $user): void
{
    $invitationId = (int)($_GET['invitation_id'] ?? 0);
    $invitation = loadInvitation($conn, $invitationId, $salonId);

    if ($invitation['status'] === 'accepted') {
        sendErrorResponse('Eine bereits angenommene Einladung kann nicht zurückgezogen werden.', 409);
    }

    $stmt = $conn->prepare(
        "UPDATE coiffure_user_invitations SET status = 'revoked' WHERE invitation_id = ? AND salon_id = ?"
    );
    if ($stmt) {
        bindTyped($stmt, [$invitationId, $salonId]);
        $stmt->execute();
        $stmt->close();
    }

    logAdminAudit($conn, $user, 'user', $invitationId, 'update',
        "Invitation revoked for {$invitation['email']}", $salonId);

    sendJsonResponse(['success' => true], 200);
}

function loadInvitation(mysqli $conn, int $invitationId, int $salonId): array
{
    if ($invitationId <= 0) {
        sendErrorResponse('invitation_id is required.', 400);
    }
    $stmt = $conn->prepare(
        'SELECT * FROM coiffure_user_invitations WHERE invitation_id = ? AND salon_id = ?'
    );
    if (!$stmt) {
        sendErrorResponse('Failed to load the invitation.', 500);
    }
    $stmt->bind_param('ii', $invitationId, $salonId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        sendErrorResponse('Invitation not found.', 404);
    }
    return $row;
}

function invitationUrl(string $token): string
{
    $base = rtrim(getenv('DASHBOARD_URL') ?: 'https://coiffureai.com', '/');
    return $base . '/set-password.html?token=' . urlencode($token);
}

function deliverInvitation(mysqli $conn, int $salonId, array $invitation, string $token): bool
{
    $result = $conn->query('SELECT * FROM coiffure_salons WHERE salon_id = ' . (int)$salonId);
    $salon = $result ? $result->fetch_assoc() : null;
    if (!$salon) {
        return false;
    }

    try {
        return sendInvitationEmail($conn, $salon, $invitation, $token);
    } catch (Throwable $e) {
        error_log('user-invite: sending failed: ' . $e->getMessage());
        return false;
    }
}

/* ============================================================
   Granular permissions for a Customer Admin Delegate
   ============================================================ */

function handleGetPermissions(mysqli $conn, int $salonId): void
{
    $userId = (int)($_GET['user_id'] ?? 0);
    if ($userId <= 0) {
        sendErrorResponse('user_id is required.', 400);
    }

    $stmt = $conn->prepare(
        'SELECT permission FROM coiffure_user_permissions WHERE user_id = ? AND salon_id = ?'
    );
    if (!$stmt) {
        sendErrorResponse('Failed to load permissions.', 500);
    }
    $stmt->bind_param('ii', $userId, $salonId);
    $stmt->execute();
    $result = $stmt->get_result();

    $permissions = [];
    while ($row = $result->fetch_assoc()) {
        $permissions[] = $row['permission'];
    }
    $stmt->close();

    sendJsonResponse(['success' => true, 'permissions' => $permissions], 200);
}

function handleSetPermissions(mysqli $conn, int $salonId, array $user, array $input): void
{
    $userId = (int)($input['user_id'] ?? 0);
    if ($userId <= 0) {
        sendErrorResponse('user_id is required.', 400);
    }

    // The target must belong to a salon this caller administers. Accounts
    // created before the junction table existed only carry users.salon_id, so
    // both sources count -- the same fallback getAccessibleSalonIds() applies.
    $check = $conn->prepare(
        'SELECT u.user_id, u.role FROM coiffure_users u
         LEFT JOIN coiffure_user_salons us ON us.user_id = u.user_id AND us.salon_id = ?
         WHERE u.user_id = ? AND (us.salon_id IS NOT NULL OR u.salon_id = ?)
         LIMIT 1'
    );
    if (!$check) {
        sendErrorResponse('Failed to save permissions.', 500);
    }
    $check->bind_param('iii', $salonId, $userId, $salonId);
    $check->execute();
    $target = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$target) {
        sendErrorResponse('Dieser Benutzer gehört nicht zu diesem Salon.', 404);
    }
    if ($target['role'] !== 'customer_admin_delegate') {
        sendErrorResponse('Berechtigungen lassen sich nur für Salon-Mitarbeiter setzen.', 400);
    }

    $requested = array_values(array_intersect((array)($input['permissions'] ?? []), SALON_PERMISSIONS));

    // Replace the whole set: the UI always submits the full checkbox state, so
    // an unchecked box has to remove the grant.
    $conn->begin_transaction();
    try {
        $delete = $conn->prepare(
            'DELETE FROM coiffure_user_permissions WHERE user_id = ? AND salon_id = ?'
        );
        $delete->bind_param('ii', $userId, $salonId);
        $delete->execute();
        $delete->close();

        if (!empty($requested)) {
            $insert = $conn->prepare(
                'INSERT INTO coiffure_user_permissions (user_id, salon_id, permission, granted_by)
                 VALUES (?, ?, ?, ?)'
            );
            $grantedBy = (int)$user['user_id'];
            foreach ($requested as $permission) {
                bindTyped($insert, [$userId, $salonId, $permission, $grantedBy]);
                $insert->execute();
            }
            $insert->close();
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('user-invite permissions: ' . $e->getMessage());
        sendErrorResponse('Die Berechtigungen konnten nicht gespeichert werden.', 500);
    }

    logAdminAudit(
        $conn, $user, 'user', $userId, 'permission_granted',
        'Permissions set to: ' . (implode(', ', $requested) ?: 'none'),
        $salonId
    );

    sendJsonResponse(['success' => true, 'permissions' => $requested], 200);
}
