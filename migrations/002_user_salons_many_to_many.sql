-- ============================================================
-- Migration: Convert User-Salon relationship from N:1 to N:N
-- Description: Allow users to be assigned to multiple salons
-- ============================================================

USE salonlyft;

-- Create junction table for user-salon many-to-many relationship
CREATE TABLE IF NOT EXISTS coiffure_user_salons (
    user_salon_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    salon_id INT UNSIGNED NOT NULL,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Foreign Keys
    FOREIGN KEY (user_id) REFERENCES coiffure_users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    -- Ensure unique user-salon pairs
    UNIQUE KEY unique_user_salon (user_id, salon_id),

    -- Indexes
    INDEX idx_user_salons_user (user_id),
    INDEX idx_user_salons_salon (salon_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing data from coiffure_users.salon_id to junction table
INSERT INTO coiffure_user_salons (user_id, salon_id, created_at)
SELECT user_id, salon_id, created_at
FROM coiffure_users
WHERE salon_id IS NOT NULL
ON DUPLICATE KEY UPDATE user_id = user_id; -- Avoid duplicates if re-running

-- Note: We keep the salon_id column in coiffure_users for backward compatibility
-- It will be deprecated but not removed to avoid breaking existing queries
-- New code should use the junction table instead

-- ============================================================
-- End of Migration
-- ============================================================
