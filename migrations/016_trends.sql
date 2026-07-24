-- ============================================================
-- Migration 016: Trends slider content (coiffure_trends)
-- ============================================================
-- Backs the auto-sliding image slider on the tablet home screen. Each row is a
-- full-screen slide (background image + title + body text + optional link).
--   sort       display order (ascending)
--   image_url  background image; a bare filename resolves under
--              https://<site>/coiffure/images/ (e.g. "01.jpg")
--   link       optional; when set the slide shows a button opening it in a new tab
--   active     1 = shown, 0 = hidden
--   gender     target audience (female/male/diverse); NULL = everyone
--
-- Safe to run once. Use api/apply_migration_016.php for an idempotent runner.
-- ============================================================

CREATE TABLE IF NOT EXISTS coiffure_trends (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title TEXT,
    `text` TEXT,
    sort INT(11) DEFAULT 0,
    image_url TEXT,
    link TEXT DEFAULT NULL,
    active TINYINT(1) DEFAULT 1,
    gender TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_trends_active_sort (active, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- End of migration 016
-- ============================================================
