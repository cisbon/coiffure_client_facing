-- ============================================================
-- Migration 019: Saved customer segments
-- ============================================================
-- The Kunden view lets a salon narrow its customer list (membership, age,
-- postal code, last visit, registration window ...). A segment stores that
-- filter combination under a name so it can be reused and, more importantly,
-- picked as the recipient list of a campaign.
--
--   filter_json  the serialized filter object, exactly as the Kunden filter bar
--                produces it. Kept opaque on purpose so new filters do not
--                require a migration; api/insights.php is the single place that
--                interprets it.
--   is_preset    1 for the built-in presets ("Inaktiv 6 Wochen", "Beste Kunden")
--                seeded per salon; those cannot be deleted from the UI.
--
-- Safe to run once. Use api/apply_migration_019.php for an idempotent runner.
-- ============================================================

CREATE TABLE IF NOT EXISTS coiffure_segments (
    segment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    filter_json TEXT NOT NULL,
    is_preset TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    UNIQUE KEY unique_segment_name (salon_id, name),
    INDEX idx_segment_salon (salon_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- End of migration 019
-- ============================================================
