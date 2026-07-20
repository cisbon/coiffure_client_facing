-- ============================================================
-- Coiffure AI Database Schema
-- Database: salonlyft
-- Description: GDPR-compliant salon management system
-- ============================================================

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS salonlyft
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE salonlyft;

-- ============================================================
-- Table: coiffure_salons
-- Description: Salon information and settings
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_salons (
    salon_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50),
    address TEXT,
    google_reviews_url VARCHAR(500),
    facebook_url VARCHAR(500),
    policy_version VARCHAR(20) NOT NULL DEFAULT '1.0',
    cancellation_policy TEXT,
    data_processing_policy TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,

    INDEX idx_salon_active (is_active),
    INDEX idx_salon_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_customers
-- Description: GDPR-compliant customer data storage
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_customers (
    customer_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED,

    -- Personal Information
    full_name VARCHAR(255) NOT NULL,
    first_name VARCHAR(120),
    last_name VARCHAR(120),
    gender ENUM('female', 'male', 'diverse') DEFAULT NULL,
    title VARCHAR(30) DEFAULT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50),                 -- legacy; kept in sync with mobile
    mobile VARCHAR(50),                -- optional, for appointment reminders

    -- Birthday (day + month collected on the form, year optional)
    birth_day TINYINT UNSIGNED,
    birth_month TINYINT UNSIGNED,
    birth_year SMALLINT UNSIGNED,

    -- Core location (always collected)
    zip VARCHAR(20),
    city VARCHAR(120),

    -- Optional postal address block (ONLY stored when consent_postal = 1)
    address_street VARCHAR(255),
    address_zip VARCHAR(20),
    address_city VARCHAR(120),
    consent_postal TINYINT(1) DEFAULT 0,

    -- GDPR Compliance Fields
    consent_marketing TINYINT(1) DEFAULT 0,           -- legacy general marketing
    consent_email_marketing TINYINT(1) DEFAULT 0,     -- e-mail offers + birthday
    consent_sms_whatsapp TINYINT(1) DEFAULT 0,        -- SMS/WhatsApp offers & reminders
    consent_data_processing TINYINT(1) NOT NULL DEFAULT 0,
    consent_cancellation_policy TINYINT(1) NOT NULL DEFAULT 0,
    consent_timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    policy_version_accepted VARCHAR(20) NOT NULL,

    -- Membership / loyalty
    is_member TINYINT(1) DEFAULT 0,
    member_id VARCHAR(64) UNIQUE,
    member_since DATE,

    -- Enrichment
    referral_source VARCHAR(50),        -- Google / Instagram / Empfehlung / ...
    preferred_stylist_id INT UNSIGNED,

    -- Signature
    signature_data LONGTEXT,  -- Base64 encoded signature image
    signature_timestamp TIMESTAMP,

    -- IP and User Agent for GDPR compliance
    ip_address VARCHAR(45),
    user_agent TEXT,

    -- GDPR Data Processing Record
    data_processing_purpose VARCHAR(500) DEFAULT 'Customer relationship management and appointment scheduling',
    gdpr_consent_notice_shown TINYINT(1) DEFAULT 1,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Data retention
    data_retention_until DATE,
    is_deleted TINYINT(1) DEFAULT 0,
    deleted_at TIMESTAMP NULL,

    -- Foreign Keys
    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    -- Indexes
    INDEX idx_customer_email (email),
    INDEX idx_customer_phone (phone),
    INDEX idx_customer_salon (salon_id),
    INDEX idx_customer_created (created_at),
    INDEX idx_customer_consent (consent_data_processing, is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_employees
-- Description: Salon stylists (populates the "Wunsch-Stylist" dropdown)
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_employees (
    employee_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,

    full_name VARCHAR(255) NOT NULL,
    title VARCHAR(120) DEFAULT NULL,
    display_order INT UNSIGNED DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    INDEX idx_employee_salon (salon_id),
    INDEX idx_employee_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_visits
-- Description: Self check-in log (tablet kiosk)
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_visits (
    visit_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    salon_id INT UNSIGNED NOT NULL,

    checkin_method ENUM('birthday', 'phone', 'manual') DEFAULT 'birthday',
    checked_in_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (customer_id) REFERENCES coiffure_customers(customer_id) ON DELETE CASCADE,
    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    INDEX idx_visit_customer (customer_id),
    INDEX idx_visit_salon (salon_id),
    INDEX idx_visit_checked_in (checked_in_at),
    INDEX idx_visit_customer_day (customer_id, checked_in_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_qr_codes
-- Description: Generated QR codes for reviews and social media
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_qr_codes (
    qr_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED,

    -- QR Code Details
    target_url VARCHAR(500) NOT NULL,
    qr_type ENUM('google_reviews', 'facebook', 'instagram', 'custom') NOT NULL,
    qr_code_data TEXT,  -- Base64 encoded QR code image or SVG

    -- Usage Tracking
    generation_count INT UNSIGNED DEFAULT 1,
    last_generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Metadata
    created_by VARCHAR(255),  -- Staff member or system
    notes TEXT,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,

    -- Foreign Keys
    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    -- Indexes
    INDEX idx_qr_salon (salon_id),
    INDEX idx_qr_type (qr_type),
    INDEX idx_qr_active (is_active),
    INDEX idx_qr_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_ai_consultations
-- Description: AI virtual hairstyle consultation sessions
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_ai_consultations (
    consultation_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED,
    customer_id INT UNSIGNED NULL,  -- Optional link to customer

    -- Consultation Details
    session_id VARCHAR(100) UNIQUE,  -- Unique session identifier
    original_image_path VARCHAR(500),  -- Path or URL to original uploaded image
    original_image_data LONGTEXT,  -- Base64 encoded original image (optional)

    -- AI Processing
    style_prompt TEXT NOT NULL,  -- User's style request
    ai_model VARCHAR(100) DEFAULT 'google/gemini-2.5-flash-image',
    ai_response_data LONGTEXT,  -- Full AI response JSON
    generated_image_url VARCHAR(500),  -- URL to generated result
    generated_image_data LONGTEXT,  -- Base64 encoded result image (optional)

    -- Processing Status
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    error_message TEXT,

    -- Performance Metrics
    processing_time_ms INT UNSIGNED,  -- Processing time in milliseconds
    api_tokens_used INT UNSIGNED,

    -- Metadata
    ip_address VARCHAR(45),
    user_agent TEXT,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,

    -- Foreign Keys
    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES coiffure_customers(customer_id) ON DELETE SET NULL,

    -- Indexes
    INDEX idx_consultation_session (session_id),
    INDEX idx_consultation_salon (salon_id),
    INDEX idx_consultation_customer (customer_id),
    INDEX idx_consultation_status (status),
    INDEX idx_consultation_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_audit_log
-- Description: GDPR compliance audit trail
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_audit_log (
    log_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Entity Information
    entity_type ENUM('customer', 'salon', 'qr_code', 'ai_consultation', 'user', 'login') NOT NULL,
    entity_id INT UNSIGNED NOT NULL,

    -- Action Details
    action ENUM('create', 'read', 'update', 'delete', 'consent_given', 'consent_withdrawn', 'data_export', 'data_deletion', 'login', 'logout', 'login_failed') NOT NULL,
    action_details TEXT,

    -- User Information
    performed_by VARCHAR(255),  -- User ID or 'system'
    ip_address VARCHAR(45),
    user_agent TEXT,

    -- Timestamp
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_users
-- Description: User accounts with role-based access control
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_users (
    user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NULL,  -- NULL for admin and admin_delegate

    -- Authentication
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,

    -- User Information
    full_name VARCHAR(255) NOT NULL,
    phone VARCHAR(50),

    -- Role-based Access Control
    role ENUM('admin', 'admin_delegate', 'customer_admin', 'customer_admin_delegate', 'customer_user') NOT NULL,

    -- Account Status
    is_active TINYINT(1) DEFAULT 1,
    email_verified TINYINT(1) DEFAULT 0,

    -- Account Management
    created_by INT UNSIGNED NULL,  -- User ID who created this account
    last_login TIMESTAMP NULL,
    last_login_ip VARCHAR(45),
    failed_login_attempts INT UNSIGNED DEFAULT 0,
    locked_until TIMESTAMP NULL,  -- Account lockout after too many failed attempts

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign Keys
    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES coiffure_users(user_id) ON DELETE SET NULL,

    -- Indexes
    INDEX idx_user_username (username),
    INDEX idx_user_email (email),
    INDEX idx_user_salon (salon_id),
    INDEX idx_user_role (role),
    INDEX idx_user_active (is_active),
    INDEX idx_user_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_sessions
-- Description: User session management for authentication
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_sessions (
    session_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,

    -- Session Token
    session_token VARCHAR(128) UNIQUE NOT NULL,

    -- Session Information
    ip_address VARCHAR(45),
    user_agent TEXT,

    -- Session Expiry
    expires_at TIMESTAMP NOT NULL,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Foreign Keys
    FOREIGN KEY (user_id) REFERENCES coiffure_users(user_id) ON DELETE CASCADE,

    -- Indexes
    INDEX idx_session_token (session_token),
    INDEX idx_session_user (user_id),
    INDEX idx_session_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Insert Default Salon (for testing/setup)
-- ============================================================
INSERT INTO coiffure_salons (
    salon_name,
    email,
    phone,
    policy_version,
    cancellation_policy,
    data_processing_policy
) VALUES (
    'Demo Salon',
    'info@demosalon.com',
    '+1234567890',
    '1.0',
    'Cancellations must be made at least 24 hours in advance. Late cancellations may incur a fee of 50% of the service cost.',
    'Your personal data will be processed for appointment management, service delivery, and customer relationship management. Data is stored securely and will not be shared with third parties without your consent.'
) ON DUPLICATE KEY UPDATE salon_name=salon_name;

-- ============================================================
-- Insert Default Admin User (for initial setup)
-- ============================================================
-- Default admin credentials:
-- Username: admin
-- Password: admin123 (CHANGE THIS IMMEDIATELY AFTER FIRST LOGIN!)
INSERT INTO coiffure_users (
    username,
    email,
    password_hash,
    full_name,
    role,
    is_active,
    email_verified
) VALUES (
    'admin',
    'admin@salonlyft.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',  -- Password: admin123
    'System Administrator',
    'admin',
    1,
    1
) ON DUPLICATE KEY UPDATE username=username;

-- ============================================================
-- Stored Procedures (Optional - for GDPR compliance)
-- ============================================================

-- Procedure to export customer data (GDPR Right to Access)
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS sp_export_customer_data(IN p_customer_id INT)
BEGIN
    SELECT * FROM coiffure_customers WHERE customer_id = p_customer_id;
    SELECT * FROM coiffure_ai_consultations WHERE customer_id = p_customer_id;
    SELECT * FROM coiffure_audit_log WHERE entity_type = 'customer' AND entity_id = p_customer_id;
END //
DELIMITER ;

-- Procedure to anonymize/delete customer data (GDPR Right to Erasure)
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS sp_delete_customer_data(IN p_customer_id INT)
BEGIN
    -- Mark as deleted instead of hard delete for audit purposes
    UPDATE coiffure_customers
    SET
        full_name = 'DELETED',
        email = CONCAT('deleted_', customer_id, '@deleted.local'),
        phone = 'DELETED',
        signature_data = NULL,
        is_deleted = 1,
        deleted_at = CURRENT_TIMESTAMP
    WHERE customer_id = p_customer_id;

    -- Log the deletion
    INSERT INTO coiffure_audit_log (entity_type, entity_id, action, performed_by)
    VALUES ('customer', p_customer_id, 'data_deletion', 'system');
END //
DELIMITER ;

-- ============================================================
-- Views for common queries
-- ============================================================

-- View: Active customers with valid consent
CREATE OR REPLACE VIEW vw_active_customers AS
SELECT
    c.*,
    s.salon_name
FROM coiffure_customers c
JOIN coiffure_salons s ON c.salon_id = s.salon_id
WHERE c.is_deleted = 0
  AND c.consent_data_processing = 1
  AND s.is_active = 1;

-- View: Recent AI consultations with customer info
CREATE OR REPLACE VIEW vw_recent_consultations AS
SELECT
    ac.*,
    c.full_name,
    c.email,
    s.salon_name
FROM coiffure_ai_consultations ac
LEFT JOIN coiffure_customers c ON ac.customer_id = c.customer_id
JOIN coiffure_salons s ON ac.salon_id = s.salon_id
ORDER BY ac.created_at DESC;

-- ============================================================
-- End of Schema
-- ============================================================
