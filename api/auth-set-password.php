<?php
/**
 * Accept an invitation and choose a password
 * -------------------------------------------------------------------
 *   GET  auth-set-password.php?token=…   → who the invitation is for
 *   POST auth-set-password.php           → {token, username, password}
 *
 * Deliberately unauthenticated: the token IS the credential. It is 64 hex
 * characters from random_bytes, single use, and expires after seven days.
 *
 * On success the user account is created (or, for an existing account being
 * re-invited, its password is reset), the delegate's granular permissions from
 * the invitation are written, and a session is returned so the invitee lands
 * straight in the dashboard.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

$check = $conn->query("SHOW TABLES LIKE 'coiffure_user_invitations'");
if (!$check || $check->num_rows === 0) {
    sendErrorResponse('Invitations are unavailable until migration 017 has been applied.', 503);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    handleInspect($conn);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleAccept($conn);
}

sendErrorResponse('Method not allowed.', 405);

/**
 * Load a usable invitation, or fail.
 * Every rejection returns the same shape so the endpoint cannot be used to
 * enumerate which tokens exist.
 */
function requireValidInvitation(mysqli $conn, string $token): array
{
    if (strlen($token) < 32) {
        sendErrorResponse('Diese Einladung ist ungültig oder abgelaufen.', 404);
    }

    $stmt = $conn->prepare(
        'SELECT i.*, s.salon_name
         FROM coiffure_user_invitations i
         LEFT JOIN coiffure_salons s ON s.salon_id = i.salon_id
         WHERE i.token = ?'
    );
    if (!$stmt) {
        sendErrorResponse('Failed to read the invitation.', 500);
    }
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $invitation = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$invitation
        || $invitation['status'] !== 'pending'
        || ($invitation['expires_at'] && strtotime($invitation['expires_at']) < time())) {
        sendErrorResponse('Diese Einladung ist ungültig oder abgelaufen.', 404);
    }

    return $invitation;
}

function handleInspect(mysqli $conn): void
{
    $invitation = requireValidInvitation($conn, (string)($_GET['token'] ?? ''));

    sendJsonResponse([
        'success' => true,
        'invitation' => [
            'email'      => $invitation['email'],
            'full_name'  => $invitation['full_name'],
            'role'       => $invitation['role'],
            'salon_name' => $invitation['salon_name'],
            'expires_at' => $invitation['expires_at'],
            // A sensible default the invitee can overwrite.
            'suggested_username' => suggestUsername($conn, (string)$invitation['email']),
        ],
    ], 200);
}

function suggestUsername(mysqli $conn, string $email): string
{
    $base = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', explode('@', $email)[0]));
    if (strlen($base) < 3) {
        $base = 'benutzer';
    }
    $base = substr($base, 0, 40);

    $candidate = $base;
    for ($suffix = 1; $suffix < 100; $suffix++) {
        $stmt = $conn->prepare('SELECT 1 FROM coiffure_users WHERE username = ? LIMIT 1');
        if (!$stmt) {
            return $candidate;
        }
        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        $taken = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$taken) {
            return $candidate;
        }
        $candidate = $base . $suffix;
    }

    return $base . random_int(100, 999);
}

function handleAccept(mysqli $conn): void
{
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $invitation = requireValidInvitation($conn, (string)($input['token'] ?? ''));

    $errors = [];
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if (!preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $username)) {
        $errors['username'] = '3–50 Zeichen, nur Buchstaben, Zahlen, _ und -.';
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'Mindestens 8 Zeichen.';
    }

    if (empty($errors['username'])) {
        $stmt = $conn->prepare('SELECT 1 FROM coiffure_users WHERE username = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $username);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $errors['username'] = 'Dieser Benutzername ist bereits vergeben.';
            }
            $stmt->close();
        }
    }

    if ($errors) {
        sendErrorResponse('Bitte prüfen Sie Ihre Eingaben.', 422, ['fields' => $errors]);
    }

    $conn->begin_transaction();

    try {
        $hash = hashPassword($password);
        $email = (string)$invitation['email'];
        $fullName = (string)($invitation['full_name'] ?: $username);
        $role = (string)$invitation['role'];
        $salonId = $invitation['salon_id'] !== null ? (int)$invitation['salon_id'] : null;

        $stmt = $conn->prepare(
            'INSERT INTO coiffure_users
                (username, email, password_hash, full_name, role, salon_id, is_active, email_verified, created_by)
             VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('user insert prepare failed: ' . $conn->error);
        }
        $invitedBy = $invitation['invited_by'] !== null ? (int)$invitation['invited_by'] : null;
        bindTyped($stmt, [$username, $email, $hash, $fullName, $role, $salonId, $invitedBy]);
        if (!$stmt->execute()) {
            throw new RuntimeException('user insert failed: ' . $stmt->error);
        }
        $userId = $stmt->insert_id;
        $stmt->close();

        // The junction table is the source of truth for salon access.
        if ($salonId) {
            $link = $conn->prepare(
                'INSERT IGNORE INTO coiffure_user_salons (user_id, salon_id) VALUES (?, ?)'
            );
            if ($link) {
                $link->bind_param('ii', $userId, $salonId);
                $link->execute();
                $link->close();
            }
        }

        // Carry over the permissions chosen when the invitation was sent.
        $permissions = json_decode((string)$invitation['permissions'], true) ?: [];
        $permissions = array_values(array_intersect($permissions, SALON_PERMISSIONS));

        if ($salonId && $role === 'customer_admin_delegate' && $permissions) {
            $insert = $conn->prepare(
                'INSERT IGNORE INTO coiffure_user_permissions (user_id, salon_id, permission, granted_by)
                 VALUES (?, ?, ?, ?)'
            );
            if ($insert) {
                foreach ($permissions as $permission) {
                    bindTyped($insert, [$userId, $salonId, $permission, $invitedBy]);
                    $insert->execute();
                }
                $insert->close();
            }
        }

        // Burn the token: single use.
        $close = $conn->prepare(
            "UPDATE coiffure_user_invitations
             SET status = 'accepted', accepted_at = NOW(), created_user_id = ?
             WHERE invitation_id = ?"
        );
        if ($close) {
            $invitationId = (int)$invitation['invitation_id'];
            $close->bind_param('ii', $userId, $invitationId);
            $close->execute();
            $close->close();
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('auth-set-password: ' . $e->getMessage());
        sendErrorResponse('Das Konto konnte nicht angelegt werden.', 500);
    }

    logAudit($conn, 'user', $userId, 'create', "Invitation accepted: $username", $username);

    // Sign them straight in, so accepting the invitation lands in the dashboard.
    $session = createUserSession($conn, $userId, 24 * 30);

    sendJsonResponse([
        'success' => true,
        'session_token' => $session['session_token'] ?? null,
        'expires_at'    => $session['expires_at'] ?? null,
        'user' => [
            'user_id'   => $userId,
            'username'  => $username,
            'email'     => $invitation['email'],
            'full_name' => $invitation['full_name'],
            'role'      => $invitation['role'],
            'salon_id'  => $invitation['salon_id'],
            'preferred_language' => 'de',
        ],
    ], 201);
}
