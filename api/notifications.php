<?php
/**
 * Notification centre
 * -------------------------------------------------------------------
 *   GET  notifications.php?action=list&limit=15   → recent + unread count
 *   GET  notifications.php?action=count           → unread count only
 *   POST notifications.php?action=read            → {notification_id}
 *   POST notifications.php?action=read_all        → mark everything read
 *   GET  notifications.php?action=prefs           → this user's preferences
 *   POST notifications.php?action=prefs           → {mode, events[]}
 *
 * Notifications belong to a user, not to a salon, so there is no salon scope
 * here: every query is bound to the caller's own user_id and a caller can only
 * ever see their own rows.
 *
 * A notification is stored as a translation key plus a JSON parameter bag
 * rather than a rendered sentence, so the same row reads correctly in German
 * and English -- the dashboard renders it through i18n.t(key, params).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';

/**
 * Notification types a user can subscribe to. Mirrored in the dashboard and in
 * notify.php. Declared before the dispatch below: a top-level `const` is
 * evaluated in source order, so one placed after the switch would not exist
 * yet when a handler runs.
 */
const NOTIFICATION_TYPES = [
    'registration', 'campaign_sent', 'birthday', 'user_invited',
    'subscription', 'system',
];

const PREF_MODES = ['off', 'instant', 'daily'];

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

if (!notificationsReady($conn)) {
    // The shell polls this on every load, so an unmigrated database must get a
    // valid empty answer rather than an error that lights up the console.
    sendJsonResponse([
        'success' => true,
        'notifications' => [],
        'unread_count' => 0,
        'migration_ready' => false,
    ], 200);
}

$userId = (int)$user['user_id'];
$action = $_GET['action'] ?? 'list';
$input = json_decode(file_get_contents('php://input'), true) ?: [];

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if ($action === 'count') handleCount($conn, $userId);
        if ($action === 'prefs') handleGetPrefs($conn, $userId);
        handleList($conn, $userId);
        break;
    case 'POST':
        if ($action === 'read')     handleMarkRead($conn, $userId, $input);
        if ($action === 'read_all') handleMarkAllRead($conn, $userId);
        if ($action === 'prefs')    handleSetPrefs($conn, $userId, $input);
        sendErrorResponse('Unknown action.', 400);
        break;
    default:
        sendErrorResponse('Method not allowed.', 405);
}

function notificationsReady(mysqli $conn): bool
{
    $res = $conn->query("SHOW TABLES LIKE 'coiffure_notifications'");
    return $res && $res->num_rows > 0;
}

function unreadCount(mysqli $conn, int $userId): int
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM coiffure_notifications
         WHERE user_id = ? AND read_at IS NULL'
    );
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0);
}

function handleCount(mysqli $conn, int $userId): void
{
    sendJsonResponse(['success' => true, 'unread_count' => unreadCount($conn, $userId)], 200);
}

function handleList(mysqli $conn, int $userId): void
{
    $limit = (int)($_GET['limit'] ?? 15);
    $limit = max(1, min(100, $limit));

    // LIMIT cannot be bound as a parameter in a prepared statement; the value
    // is clamped to an int above, so interpolating it is safe.
    $stmt = $conn->prepare(
        "SELECT notification_id, salon_id, type, title_key, params, link, read_at, created_at
         FROM coiffure_notifications
         WHERE user_id = ?
         ORDER BY created_at DESC, notification_id DESC
         LIMIT $limit"
    );
    if (!$stmt) {
        sendErrorResponse('Failed to load notifications.', 500);
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    sendJsonResponse([
        'success' => true,
        'notifications' => array_map(static fn($row) => [
            'notification_id' => (int)$row['notification_id'],
            'salon_id'   => $row['salon_id'] !== null ? (int)$row['salon_id'] : null,
            'type'       => $row['type'],
            'title_key'  => $row['title_key'],
            'params'     => $row['params'],
            'link'       => $row['link'],
            'read_at'    => $row['read_at'],
            'created_at' => $row['created_at'],
        ], $rows),
        'unread_count' => unreadCount($conn, $userId),
        'migration_ready' => true,
    ], 200);
}

function handleMarkRead(mysqli $conn, int $userId, array $input): void
{
    $notificationId = (int)($input['notification_id'] ?? 0);
    if ($notificationId <= 0) {
        sendErrorResponse('notification_id is required.', 400);
    }

    // The user_id in the WHERE clause is what stops one user marking another
    // user's notification read.
    $stmt = $conn->prepare(
        'UPDATE coiffure_notifications SET read_at = NOW()
         WHERE notification_id = ? AND user_id = ? AND read_at IS NULL'
    );
    if (!$stmt) {
        sendErrorResponse('Failed to update the notification.', 500);
    }
    $stmt->bind_param('ii', $notificationId, $userId);
    $stmt->execute();
    $stmt->close();

    sendJsonResponse(['success' => true, 'unread_count' => unreadCount($conn, $userId)], 200);
}

function handleMarkAllRead(mysqli $conn, int $userId): void
{
    $stmt = $conn->prepare(
        'UPDATE coiffure_notifications SET read_at = NOW()
         WHERE user_id = ? AND read_at IS NULL'
    );
    if (!$stmt) {
        sendErrorResponse('Failed to update the notifications.', 500);
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $marked = $stmt->affected_rows;
    $stmt->close();

    sendJsonResponse(['success' => true, 'marked' => $marked, 'unread_count' => 0], 200);
}

function handleGetPrefs(mysqli $conn, int $userId): void
{
    $stmt = $conn->prepare(
        'SELECT mode, events FROM coiffure_notification_prefs WHERE user_id = ?'
    );
    if (!$stmt) {
        sendErrorResponse('Failed to load the preferences.', 500);
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // No row yet means the defaults: in-dashboard only, every event type.
    sendJsonResponse([
        'success' => true,
        'prefs' => [
            'mode'   => $row['mode'] ?? 'off',
            'events' => $row ? (json_decode((string)$row['events'], true) ?: []) : NOTIFICATION_TYPES,
        ],
        'available_events' => NOTIFICATION_TYPES,
        'modes' => PREF_MODES,
    ], 200);
}

function handleSetPrefs(mysqli $conn, int $userId, array $input): void
{
    $mode = (string)($input['mode'] ?? 'off');
    if (!in_array($mode, PREF_MODES, true)) {
        sendErrorResponse('Bitte prüfen Sie Ihre Eingaben.', 422, [
            'fields' => ['mode' => 'Unbekannter Modus.'],
        ]);
    }

    $events = array_values(array_intersect((array)($input['events'] ?? []), NOTIFICATION_TYPES));
    $eventsJson = json_encode($events);

    $stmt = $conn->prepare(
        'INSERT INTO coiffure_notification_prefs (user_id, mode, events)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE mode = VALUES(mode), events = VALUES(events)'
    );
    if (!$stmt) {
        sendErrorResponse('Die Einstellungen konnten nicht gespeichert werden.', 500);
    }
    bindTyped($stmt, [$userId, $mode, $eventsJson]);
    if (!$stmt->execute()) {
        $stmt->close();
        sendErrorResponse('Die Einstellungen konnten nicht gespeichert werden.', 500);
    }
    $stmt->close();

    sendJsonResponse(['success' => true, 'prefs' => ['mode' => $mode, 'events' => $events]], 200);
}
