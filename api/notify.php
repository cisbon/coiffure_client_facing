<?php
/**
 * Notification writer
 * -------------------------------------------------------------------
 * Shared helper, not an endpoint. api/notifications.php reads what this
 * writes; the dashboard's bell renders it.
 *
 * A notification is stored as a translation key plus a JSON parameter bag
 * rather than a finished sentence, so one row reads correctly in German and in
 * English. Never pass a rendered string as the title.
 *
 * Every function here is best-effort: a failure to record a notification must
 * never fail the action that triggered it. That is why nothing throws and
 * nothing returns an error -- a salon owner losing a bell item is a nuisance,
 * losing the registration that caused it is not acceptable.
 */

require_once __DIR__ . '/config.php';

/** Notification types. Kept in step with NOTIFICATION_TYPES in notifications.php. */
const NOTIFY_TYPES = [
    'registration', 'campaign_sent', 'birthday', 'user_invited',
    'subscription', 'system',
];

function notificationsTableExists(mysqli $conn): bool
{
    static $exists = null;
    if ($exists === null) {
        $res = $conn->query("SHOW TABLES LIKE 'coiffure_notifications'");
        $exists = $res && $res->num_rows > 0;
    }
    return $exists;
}

/**
 * Write one notification for one user.
 *
 * @param array $params Interpolation values for the translation key.
 * @param string|null $link Dashboard hash route, e.g. '#/kunden?id=42'.
 */
function notifyUser(
    mysqli $conn,
    int $userId,
    ?int $salonId,
    string $type,
    string $titleKey,
    array $params = [],
    ?string $link = null
): bool {
    if (!notificationsTableExists($conn) || $userId <= 0) {
        return false;
    }
    if (!in_array($type, NOTIFY_TYPES, true)) {
        error_log("notifyUser: unknown notification type '$type'");
        return false;
    }

    $stmt = $conn->prepare(
        'INSERT INTO coiffure_notifications
            (user_id, salon_id, type, title_key, params, link)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('notifyUser: prepare failed: ' . $conn->error);
        return false;
    }

    $paramsJson = $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null;
    $types = 'iissss';
    $stmt->bind_param($types, $userId, $salonId, $type, $titleKey, $paramsJson, $link);
    $ok = $stmt->execute();
    if (!$ok) {
        error_log('notifyUser: insert failed: ' . $stmt->error);
    }
    $stmt->close();

    return $ok;
}

/**
 * Notify everyone who administers a salon.
 *
 * Delegates are included only when they hold the permission the notification
 * is about -- there is no point telling someone who cannot open the campaigns
 * screen that a campaign finished sending. A Customer Admin always qualifies,
 * since they hold every salon permission by role.
 *
 * @param string|null $requiresPermission A key from SALON_PERMISSIONS, or null
 *                                        for everyone attached to the salon.
 * @param int|null $exceptUserId Skip this user -- normally the person whose own
 *                               action triggered the notification.
 * @return int How many notifications were written.
 */
function notifySalonAdmins(
    mysqli $conn,
    int $salonId,
    string $type,
    string $titleKey,
    array $params = [],
    ?string $link = null,
    ?string $requiresPermission = null,
    ?int $exceptUserId = null
): int {
    if (!notificationsTableExists($conn) || $salonId <= 0) {
        return 0;
    }

    // Both sources of salon assignment, same fallback as getAccessibleSalonIds().
    $sql =
        "SELECT DISTINCT u.user_id, u.role
         FROM coiffure_users u
         LEFT JOIN coiffure_user_salons us ON us.user_id = u.user_id AND us.salon_id = ?
         WHERE u.is_active = 1
           AND u.role IN ('customer_admin', 'customer_admin_delegate')
           AND (us.salon_id IS NOT NULL OR u.salon_id = ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('notifySalonAdmins: prepare failed: ' . $conn->error);
        return 0;
    }
    $stmt->bind_param('ii', $salonId, $salonId);
    $stmt->execute();
    $recipients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $sent = 0;
    foreach ($recipients as $recipient) {
        $userId = (int)$recipient['user_id'];
        if ($exceptUserId !== null && $userId === $exceptUserId) {
            continue;
        }
        if ($requiresPermission !== null
            && $recipient['role'] === 'customer_admin_delegate'
            && !delegateHasPermission($conn, $userId, $salonId, $requiresPermission)) {
            continue;
        }
        if (notifyUser($conn, $userId, $salonId, $type, $titleKey, $params, $link)) {
            $sent++;
        }
    }

    return $sent;
}

/**
 * Whether a delegate holds one specific grant.
 *
 * permissions.php has getDelegatePermissions(), but notify.php is included by
 * endpoints that do not all load it, and this needs one row rather than the
 * whole set.
 */
function delegateHasPermission(mysqli $conn, int $userId, int $salonId, string $permission): bool
{
    $res = $conn->query("SHOW TABLES LIKE 'coiffure_user_permissions'");
    if (!$res || $res->num_rows === 0) {
        return false;
    }

    $stmt = $conn->prepare(
        'SELECT 1 FROM coiffure_user_permissions
         WHERE user_id = ? AND salon_id = ? AND permission = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iis', $userId, $salonId, $permission);
    $stmt->execute();
    $found = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $found;
}

/** Notify every platform administrator. Used for billing and system events. */
function notifyPlatformAdmins(
    mysqli $conn,
    string $type,
    string $titleKey,
    array $params = [],
    ?string $link = null,
    ?int $exceptUserId = null
): int {
    if (!notificationsTableExists($conn)) {
        return 0;
    }

    $result = $conn->query(
        "SELECT user_id FROM coiffure_users
         WHERE is_active = 1 AND role IN ('admin', 'admin_delegate')"
    );
    if (!$result) {
        return 0;
    }

    $sent = 0;
    while ($row = $result->fetch_assoc()) {
        $userId = (int)$row['user_id'];
        if ($exceptUserId !== null && $userId === $exceptUserId) {
            continue;
        }
        if (notifyUser($conn, $userId, null, $type, $titleKey, $params, $link)) {
            $sent++;
        }
    }

    return $sent;
}
