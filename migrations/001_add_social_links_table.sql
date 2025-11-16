-- ============================================================
-- Migration: Add Social Links Table
-- Description: Table to store multiple social media and review links per salon
-- ============================================================

USE salonlyft;

-- Create social_links table
CREATE TABLE IF NOT EXISTS coiffure_social_links (
    link_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,

    -- Link Details
    link_type ENUM('instagram', 'facebook', 'tiktok', 'google_reviews', 'yelp', 'twitter', 'linkedin', 'youtube', 'pinterest', 'custom') NOT NULL,
    link_url VARCHAR(1000) NOT NULL,
    display_name VARCHAR(255) NOT NULL,  -- Name to show to users (e.g., "Follow us on Instagram")
    description TEXT,  -- Optional description for custom links
    icon_name VARCHAR(100) NOT NULL DEFAULT 'default',  -- Icon identifier

    -- QR Code Data
    qr_code_data LONGTEXT,  -- Base64 encoded QR code image

    -- Display Order
    display_order INT UNSIGNED DEFAULT 0,  -- For sorting links

    -- Status
    is_active TINYINT(1) DEFAULT 1,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign Keys
    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    -- Indexes
    INDEX idx_social_salon (salon_id),
    INDEX idx_social_type (link_type),
    INDEX idx_social_active (is_active),
    INDEX idx_social_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
