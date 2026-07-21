-- ============================================================
-- Migration 014: Salon connections (multi-store brands)
-- ============================================================
-- Lets an administrator group several salons of the same brand so they SHARE
-- their customer base for self check-in. Salons sharing a `group_id` are
-- connected; a check-in then searches customers across every connected salon.
-- A salon that is NOT listed here is only ever scoped to itself.
--
-- A salon may belong to at most one group (UNIQUE on salon_id).
--
-- Safe to run once. Use api/apply_migration_014.php for an idempotent runner.
-- ============================================================

CREATE TABLE IF NOT EXISTS coiffure_salon_connections (
    connection_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id INT UNSIGNED NOT NULL,
    salon_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_conn_salon (salon_id),
    INDEX idx_conn_group (group_id),

    CONSTRAINT fk_conn_salon FOREIGN KEY (salon_id)
        REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- End of migration 014
-- ============================================================
