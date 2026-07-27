-- ============================================================
-- Migration 021: Notification centre
-- ============================================================
-- Backs the bell icon in the dashboard top bar. Notifications are generated
-- server-side (new registration, campaign sent, birthday tomorrow, ...) and
-- persisted per recipient user.
--
--   title_key / params  the notification is stored as a translation key plus a
--                       JSON parameter bag rather than a rendered sentence, so
--                       the same row reads correctly in German and English.
--                       Rendering happens client-side through i18n.t(key, params).
--   link                optional dashboard hash route, e.g. "#/kunden?id=42"
--   read_at             NULL = unread; drives the badge count.
--
--   coiffure_notification_prefs
--     One row per user. mode is instant | daily | off and decides whether the
--     notification is additionally e-mailed; events is a JSON array of the
--     notification types the user wants.
--
-- Safe to run once. Use api/apply_migration_021.php for an idempotent runner.
-- ============================================================

CREATE TABLE IF NOT EXISTS coiffure_notifications (
    notification_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    salon_id INT UNSIGNED DEFAULT NULL,
    type VARCHAR(40) NOT NULL,
    title_key VARCHAR(120) NOT NULL,
    params TEXT DEFAULT NULL,               -- JSON object for i18n interpolation
    link VARCHAR(255) DEFAULT NULL,
    read_at TIMESTAMP NULL DEFAULT NULL,
    emailed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES coiffure_users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    INDEX idx_notification_user_read (user_id, read_at),
    INDEX idx_notification_created (created_at),
    INDEX idx_notification_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coiffure_notification_prefs (
    pref_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    mode VARCHAR(20) NOT NULL DEFAULT 'off',   -- off | instant | daily
    events TEXT DEFAULT NULL,                  -- JSON array of notification types
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES coiffure_users(user_id) ON DELETE CASCADE,

    UNIQUE KEY unique_notification_pref_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- End of migration 021
-- ============================================================
