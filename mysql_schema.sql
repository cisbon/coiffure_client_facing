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
    wifi_ssid VARCHAR(255) DEFAULT NULL,
    wifi_password VARCHAR(255) DEFAULT NULL,
    -- Per-salon loyalty program config (migration 012)
    loyalty_active TINYINT(1) NOT NULL DEFAULT 1,
    loyalty_visit_threshold INT UNSIGNED NOT NULL DEFAULT 5,
    loyalty_discount_type ENUM('fixed_eur','percentage') NOT NULL DEFAULT 'fixed_eur',
    loyalty_discount_value DECIMAL(6,2) NOT NULL DEFAULT 10.00,
    loyalty_discount_label VARCHAR(50) DEFAULT NULL,
    staff_pin VARCHAR(8) NOT NULL DEFAULT '0000',
    policy_version VARCHAR(20) NOT NULL DEFAULT '1.0',
    cancellation_policy TEXT,
    data_processing_policy TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,

    -- Default interface language for the tablet (migration 006)
    default_language VARCHAR(5) DEFAULT 'de',

    -- Branding shown on the tablet and in e-mails (migration 007)
    logo_path VARCHAR(255) DEFAULT NULL,
    primary_color VARCHAR(7) DEFAULT '#9333EA',
    secondary_color VARCHAR(7) DEFAULT '#EC4899',
    background_color VARCHAR(7) DEFAULT '#FFFFFF',
    button_color VARCHAR(7) DEFAULT '#9333EA',
    text_color VARCHAR(7) DEFAULT '#1F2937',

    -- Platform-level salon record (migration 018)
    subdomain VARCHAR(63) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',   -- active | trial | suspended
    currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    website VARCHAR(255) DEFAULT NULL,
    instagram_url VARCHAR(500) DEFAULT NULL,

    -- Tablet welcome screen and module toggles (migration 018)
    tablet_headline VARCHAR(255) DEFAULT NULL,
    tablet_bg_image VARCHAR(500) DEFAULT NULL,
    tablet_bg_color VARCHAR(7) DEFAULT NULL,
    tablet_idle_timeout_s INT UNSIGNED DEFAULT NULL,  -- NULL = use global setting
    tablet_modules TEXT DEFAULT NULL,                 -- JSON: register/checkin/browse

    -- Refer a friend (migration 018)
    referral_enabled TINYINT(1) NOT NULL DEFAULT 1,
    referral_discount_value DECIMAL(6,2) NOT NULL DEFAULT 10.00,

    -- Automatic birthday campaign (migration 018)
    birthday_enabled TINYINT(1) NOT NULL DEFAULT 0,
    birthday_days_before INT UNSIGNED NOT NULL DEFAULT 7,
    birthday_subject VARCHAR(255) DEFAULT NULL,
    birthday_body TEXT DEFAULT NULL,
    birthday_discount_code VARCHAR(50) DEFAULT NULL,

    -- Campaign guard rails (migration 018)
    campaign_spam_limit INT UNSIGNED NOT NULL DEFAULT 4,
    campaign_spam_window_days INT UNSIGNED NOT NULL DEFAULT 30,

    -- AI stylist quotas and overage billing (migrations 027 + 028).
    -- A limit of 0 means unlimited. Trial salons (status = 'trial') spend the
    -- lifetime ai_trial_image_limit and are cut off when it is used up;
    -- everyone else gets ai_monthly_image_limit images per calendar month and
    -- may continue past it only when the owner enabled ai_overage_allowed, at
    -- ai_overage_price per extra image -- up to ai_overage_monthly_cap of
    -- extras per month (0.00 = no cap), after which the feature switches off
    -- for the rest of the month.
    ai_feature_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ai_trial_image_limit INT UNSIGNED NOT NULL DEFAULT 100,
    ai_monthly_image_limit INT UNSIGNED NOT NULL DEFAULT 500,
    ai_overage_allowed TINYINT(1) NOT NULL DEFAULT 0,
    ai_overage_price DECIMAL(8,4) NOT NULL DEFAULT 0.0100,
    ai_overage_monthly_cap DECIMAL(8,2) NOT NULL DEFAULT 0.00,

    UNIQUE KEY unique_salon_subdomain (subdomain),
    INDEX idx_salon_active (is_active),
    INDEX idx_salon_status (status),
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

    -- Staff-authored notes and tags (migration 025). Salon-authored, not
    -- customer-supplied, so excluded from the consent-aware marketing export.
    notes TEXT DEFAULT NULL,
    tags VARCHAR(255) DEFAULT NULL,
    notes_updated_at TIMESTAMP NULL DEFAULT NULL,
    notes_updated_by INT UNSIGNED DEFAULT NULL,

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
-- Table: coiffure_checkin_events  (migration 013)
-- Description: Privacy-conscious check-in analytics (no PII beyond customer_id)
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_checkin_events (
    event_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    customer_id INT UNSIGNED DEFAULT NULL,
    payload JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_event_salon (salon_id),
    INDEX idx_event_type (event_type),
    INDEX idx_event_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_settings_audit  (migration 013)
-- Description: Audit trail for salon setting changes (old → new value)
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_settings_audit (
    audit_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    changed_by INT UNSIGNED DEFAULT NULL,
    setting_key VARCHAR(64) NOT NULL,
    old_value VARCHAR(255) DEFAULT NULL,
    new_value VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_saudit_salon (salon_id),
    INDEX idx_saudit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_checkin_lockouts  (migration 013)
-- Description: Brute-force protection log (salon_id + timestamp only, no PII)
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_checkin_lockouts (
    lockout_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    scope VARCHAR(20) NOT NULL DEFAULT 'phone',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_lockout_salon (salon_id),
    INDEX idx_lockout_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_salon_connections  (migration 014)
-- Description: Groups salons of the same brand so they SHARE their customer
-- base for self check-in. Salons with the same group_id are connected.
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
-- Table: coiffure_global_settings  (migration 015)
-- Description: App-wide settings (key-value), admin-editable. Holds the
-- kiosk/check-in timeout durations (seconds). Seed via migration 015.
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_global_settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
-- Table: coiffure_ai_image_usage  (migration 027)
-- Description: Billing ledger for the AI stylists -- one row per successfully
--              generated image. Separate from coiffure_ai_consultations
--              (which also holds failures and base64 blobs and is pruned):
--              this ledger is append-only and must survive. The price that
--              applied at generation time is frozen on the row, so re-pricing
--              a salon never rewrites past invoices.
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_ai_image_usage (
    usage_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    consultation_id INT UNSIGNED DEFAULT NULL,

    -- Which stylist produced the image ('hairstyle', 'eyebrows', …).
    consultation_type VARCHAR(30) NOT NULL DEFAULT 'hairstyle',

    -- Billing period this image counts towards.
    period_year SMALLINT UNSIGNED NOT NULL,
    period_month TINYINT UNSIGNED NOT NULL,

    quota_mode VARCHAR(20) NOT NULL DEFAULT 'subscription',  -- trial | subscription
    billing_state VARCHAR(20) NOT NULL DEFAULT 'included',   -- included | overage
    overage_price DECIMAL(8,4) NOT NULL DEFAULT 0.0000,      -- charged for THIS image
    currency VARCHAR(3) NOT NULL DEFAULT 'EUR',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,
    FOREIGN KEY (consultation_id) REFERENCES coiffure_ai_consultations(consultation_id) ON DELETE SET NULL,

    INDEX idx_ai_usage_period (salon_id, period_year, period_month),
    INDEX idx_ai_usage_created (salon_id, created_at),
    INDEX idx_ai_usage_billing (salon_id, billing_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_audit_log
-- Description: GDPR compliance audit trail
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_audit_log (
    log_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Entity Information
    -- Widened from ENUM to VARCHAR by migration 024: an unlisted ENUM value is
    -- silently dropped by MySQL in non-strict mode, so new action types were
    -- never recorded.
    entity_type VARCHAR(40) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,

    -- Action Details
    action VARCHAR(40) NOT NULL,
    action_details TEXT,

    -- User Information
    performed_by VARCHAR(255),  -- Username or 'system'
    ip_address VARCHAR(45),
    user_agent TEXT,

    -- Attribution for the dashboard audit view (migration 024).
    -- performed_by_id is intentionally not a foreign key: audit rows must
    -- survive deletion of the user that caused them.
    salon_id INT UNSIGNED DEFAULT NULL,          -- NULL = platform-level action
    performed_by_id INT UNSIGNED DEFAULT NULL,
    performed_by_role VARCHAR(40) DEFAULT NULL,  -- lets the delegate view hide admin actions

    -- Timestamp
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_salon (salon_id),
    INDEX idx_audit_performer (performed_by_id),
    INDEX idx_audit_role (performed_by_role)
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
    -- 'customer_user' was renamed to 'customer_facing_tablet_user' by migration 003
    role ENUM('admin', 'admin_delegate', 'customer_admin', 'customer_admin_delegate', 'customer_facing_tablet_user') NOT NULL,

    -- Account Status
    is_active TINYINT(1) DEFAULT 1,
    email_verified TINYINT(1) DEFAULT 0,

    -- Interface language (migration 005)
    preferred_language VARCHAR(5) DEFAULT 'de',

    -- Force a password reset on next login (used by salon onboarding)
    force_password_change TINYINT(1) NOT NULL DEFAULT 0,

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

    -- Support impersonation (migration 026). Deliberately not a foreign key:
    -- deleting an administrator must not erase the record that they
    -- impersonated a salon account.
    impersonated_by INT UNSIGNED DEFAULT NULL COMMENT 'user_id of the admin who started this support session',

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Foreign Keys
    FOREIGN KEY (user_id) REFERENCES coiffure_users(user_id) ON DELETE CASCADE,

    -- Indexes
    INDEX idx_session_token (session_token),
    INDEX idx_session_user (user_id),
    INDEX idx_session_expires (expires_at),
    INDEX idx_session_impersonated (impersonated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_user_salons  (migration 002)
-- Description: Many-to-many assignment of users to salons. This is the source
--              of truth; coiffure_users.salon_id is kept only for backward
--              compatibility (validateSession() still selects it).
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_user_salons (
    user_salon_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    salon_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES coiffure_users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    UNIQUE KEY unique_user_salon (user_id, salon_id),
    INDEX idx_user_salons_user (user_id),
    INDEX idx_user_salons_salon (salon_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_social_links  (migration 001)
-- Description: Social and review links shown on the tablet, with QR data
-- ============================================================
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

-- ============================================================
-- Table: coiffure_trends  (migration 016)
-- Description: Slides for the tablet home-screen magazine slider. Curated
--              directly in the database -- there is no admin UI for this yet.
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
-- Table: coiffure_user_permissions  (migration 017)
-- Description: Granular grants for customer_admin_delegate users. Every other
--              role derives its permissions from the matrix in
--              api/permissions.php and needs no rows here.
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_user_permissions (
    permission_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    salon_id INT UNSIGNED NOT NULL,
    permission VARCHAR(40) NOT NULL,
    granted_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES coiffure_users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    UNIQUE KEY unique_user_salon_permission (user_id, salon_id, permission),
    INDEX idx_perm_user (user_id),
    INDEX idx_perm_salon (salon_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_user_invitations  (migration 017)
-- Description: Invite a user by e-mail and let them choose their own password,
--              instead of mailing a generated one in cleartext.
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_user_invitations (
    invitation_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(128) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) DEFAULT NULL,
    role VARCHAR(40) NOT NULL,
    salon_id INT UNSIGNED DEFAULT NULL,
    permissions TEXT DEFAULT NULL,          -- JSON array of permission keys
    invited_by INT UNSIGNED DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    expires_at TIMESTAMP NULL DEFAULT NULL,
    accepted_at TIMESTAMP NULL DEFAULT NULL,
    created_user_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    INDEX idx_invitation_email (email),
    INDEX idx_invitation_status (status),
    INDEX idx_invitation_salon (salon_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_salon_hours  (migration 018)
-- Description: Optional opening hours shown on the tablet.
--              weekday: 0 = Monday ... 6 = Sunday
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_salon_hours (
    hours_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    open_time TIME DEFAULT NULL,
    close_time TIME DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    UNIQUE KEY unique_salon_weekday (salon_id, weekday)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_segments  (migration 019)
-- Description: A named, reusable customer filter. filter_json is kept opaque
--              so new filters need no migration; api/insights.php is the only
--              place that interprets it.
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
-- Table: coiffure_campaigns  (migration 020)
-- Description: One row per one-time campaign and per run of an automatic one,
--              so the campaign log is a single table.
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_campaigns (
    campaign_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    name VARCHAR(180) NOT NULL,
    kind VARCHAR(10) NOT NULL DEFAULT 'once',      -- once | auto
    auto_type VARCHAR(40) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',   -- draft|scheduled|sending|sent|cancelled|failed

    recipient_type VARCHAR(20) NOT NULL DEFAULT 'all',  -- all|members|segment|manual
    recipient_ref TEXT DEFAULT NULL,               -- segment_id, or JSON customer ids
    recipient_count INT UNSIGNED NOT NULL DEFAULT 0,

    subject VARCHAR(255) NOT NULL,
    body MEDIUMTEXT NOT NULL,

    discount_enabled TINYINT(1) NOT NULL DEFAULT 0,
    discount_mode VARCHAR(10) DEFAULT NULL,        -- generic | unique
    discount_code VARCHAR(50) DEFAULT NULL,
    discount_type VARCHAR(20) DEFAULT NULL,        -- fixed_eur | percentage
    discount_value DECIMAL(6,2) DEFAULT NULL,

    skip_over_limit TINYINT(1) NOT NULL DEFAULT 1,
    scheduled_at TIMESTAMP NULL DEFAULT NULL,
    started_at TIMESTAMP NULL DEFAULT NULL,
    completed_at TIMESTAMP NULL DEFAULT NULL,

    sent_count INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    open_count INT UNSIGNED NOT NULL DEFAULT 0,
    click_count INT UNSIGNED NOT NULL DEFAULT 0,

    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    INDEX idx_campaign_salon (salon_id),
    INDEX idx_campaign_status (status),
    INDEX idx_campaign_scheduled (status, scheduled_at),
    INDEX idx_campaign_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_campaign_recipients  (migration 020)
-- Description: Per-customer delivery record. Also the source of truth for the
--              spam limit, hence the (customer_id, sent_at) index.
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_campaign_recipients (
    recipient_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    salon_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',  -- pending|sent|skipped_limit|skipped_no_consent|failed
    discount_code VARCHAR(50) DEFAULT NULL,
    error_message VARCHAR(255) DEFAULT NULL,
    tracking_token VARCHAR(64) DEFAULT NULL,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    opened_at TIMESTAMP NULL DEFAULT NULL,
    clicked_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (campaign_id) REFERENCES coiffure_campaigns(campaign_id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES coiffure_customers(customer_id) ON DELETE CASCADE,

    UNIQUE KEY unique_campaign_customer (campaign_id, customer_id),
    INDEX idx_recipient_campaign (campaign_id),
    INDEX idx_recipient_customer_sent (customer_id, sent_at),
    INDEX idx_recipient_token (tracking_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_automatic_campaigns  (migration 020)
-- Description: One row per (salon, type) for birthday, we_miss_you, thank_you
--              and referral_reminder. trigger_unit is weeks|visits|days.
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_automatic_campaigns (
    auto_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    type VARCHAR(40) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,

    trigger_value INT UNSIGNED NOT NULL DEFAULT 10,
    trigger_unit VARCHAR(10) NOT NULL DEFAULT 'weeks',

    subject VARCHAR(255) DEFAULT NULL,
    body MEDIUMTEXT DEFAULT NULL,

    discount_enabled TINYINT(1) NOT NULL DEFAULT 0,
    discount_code VARCHAR(50) DEFAULT NULL,
    discount_type VARCHAR(20) DEFAULT NULL,
    discount_value DECIMAL(6,2) DEFAULT NULL,

    last_run_at TIMESTAMP NULL DEFAULT NULL,
    updated_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    UNIQUE KEY unique_salon_auto_type (salon_id, type),
    INDEX idx_auto_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_discount_codes  (migration 020)
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_discount_codes (
    code_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    code VARCHAR(50) NOT NULL,
    campaign_id INT UNSIGNED DEFAULT NULL,
    customer_id INT UNSIGNED DEFAULT NULL,
    discount_type VARCHAR(20) NOT NULL DEFAULT 'fixed_eur',
    discount_value DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    valid_until DATE DEFAULT NULL,
    redeemed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    UNIQUE KEY unique_salon_code (salon_id, code),
    INDEX idx_code_campaign (campaign_id),
    INDEX idx_code_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_notifications  (migration 021)
-- Description: Stored as a translation key + JSON params rather than a
--              rendered sentence, so one row reads correctly in de and en.
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_notifications (
    notification_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    salon_id INT UNSIGNED DEFAULT NULL,
    type VARCHAR(40) NOT NULL,
    title_key VARCHAR(120) NOT NULL,
    params TEXT DEFAULT NULL,
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

-- ============================================================
-- Table: coiffure_notification_prefs  (migration 021)
-- ============================================================
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
-- Table: coiffure_subscription_plans  (migration 022)
-- Description: max_customers / max_campaigns_per_month use 0 for unlimited.
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_subscription_plans (
    plan_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    monthly_price DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    max_customers INT UNSIGNED NOT NULL DEFAULT 0,
    max_campaigns_per_month INT UNSIGNED NOT NULL DEFAULT 0,
    features TEXT DEFAULT NULL,                -- JSON object of feature flags
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_plan_name (name),
    INDEX idx_plan_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_salon_subscriptions  (migration 022)
-- Description: Manual tracking only -- there is no payment gateway.
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_salon_subscriptions (
    subscription_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED DEFAULT NULL,
    payment_status VARCHAR(20) NOT NULL DEFAULT 'active',   -- active|overdue|cancelled
    trial_ends_at DATE DEFAULT NULL,
    started_at DATE DEFAULT NULL,
    cancelled_at DATE DEFAULT NULL,
    notes VARCHAR(500) DEFAULT NULL,
    updated_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES coiffure_subscription_plans(plan_id) ON DELETE SET NULL,

    UNIQUE KEY unique_subscription_salon (salon_id),
    INDEX idx_subscription_status (payment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_invoices  (migration 022)
-- Description: invoice_number is the human-facing running number generated in
--              api/billing.php; invoice_id stays the surrogate key.
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_invoices (
    invoice_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(30) NOT NULL,
    salon_id INT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED DEFAULT NULL,
    period_year SMALLINT UNSIGNED NOT NULL,
    period_month TINYINT UNSIGNED NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    status VARCHAR(20) NOT NULL DEFAULT 'open',   -- open | paid | cancelled
    issued_at DATE DEFAULT NULL,
    paid_at DATE DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    UNIQUE KEY unique_invoice_number (invoice_number),
    UNIQUE KEY unique_invoice_period (salon_id, period_year, period_month),
    INDEX idx_invoice_salon (salon_id),
    INDEX idx_invoice_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_invoice_items  (migration 022)
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_invoice_items (
    item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(8,2) NOT NULL DEFAULT 1.00,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    sort_order INT NOT NULL DEFAULT 0,

    FOREIGN KEY (invoice_id) REFERENCES coiffure_invoices(invoice_id) ON DELETE CASCADE,

    INDEX idx_item_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_salon_whitelabel  (migration 023)
-- Description: Administrator-only. smtp_password is stored as written because
--              the mailer needs the cleartext to authenticate -- it must never
--              be returned through an API response.
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_salon_whitelabel (
    whitelabel_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salon_id INT UNSIGNED NOT NULL,

    custom_domain VARCHAR(255) DEFAULT NULL,
    domain_verified TINYINT(1) NOT NULL DEFAULT 0,

    smtp_host VARCHAR(255) DEFAULT NULL,
    smtp_port INT UNSIGNED DEFAULT 587,
    smtp_secure VARCHAR(10) DEFAULT 'tls',      -- tls | ssl | none
    smtp_username VARCHAR(255) DEFAULT NULL,
    smtp_password VARCHAR(255) DEFAULT NULL,
    from_address VARCHAR(255) DEFAULT NULL,
    from_name VARCHAR(255) DEFAULT NULL,
    last_test_at TIMESTAMP NULL DEFAULT NULL,
    last_test_ok TINYINT(1) DEFAULT NULL,

    primary_color VARCHAR(7) DEFAULT NULL,
    secondary_color VARCHAR(7) DEFAULT NULL,

    updated_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (salon_id) REFERENCES coiffure_salons(salon_id) ON DELETE CASCADE,

    UNIQUE KEY unique_whitelabel_salon (salon_id),
    UNIQUE KEY unique_whitelabel_domain (custom_domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: coiffure_consent_history  (migration 024)
-- Description: coiffure_customers holds only the current consent flags; GDPR
--              asks for the history, so every flip is appended here. Read-only.
-- ============================================================
CREATE TABLE IF NOT EXISTS coiffure_consent_history (
    history_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    salon_id INT UNSIGNED DEFAULT NULL,
    consent_field VARCHAR(60) NOT NULL,     -- e.g. consent_email_marketing
    old_value VARCHAR(20) DEFAULT NULL,
    new_value VARCHAR(20) DEFAULT NULL,
    policy_version VARCHAR(20) DEFAULT NULL,
    source VARCHAR(40) DEFAULT NULL,        -- tablet | dashboard | import
    changed_by VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (customer_id) REFERENCES coiffure_customers(customer_id) ON DELETE CASCADE,

    INDEX idx_consent_customer (customer_id, created_at),
    INDEX idx_consent_salon (salon_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Seed: subscription plans (migration 022)
-- ============================================================
INSERT IGNORE INTO coiffure_subscription_plans
    (name, description, monthly_price, max_customers, max_campaigns_per_month, features, sort_order)
VALUES
    ('Starter', 'Für einzelne Salons mit bis zu 500 Kunden.', 29.00, 500, 4,
     '{"advanced_insights":false,"white_label":false,"multi_salon":false}', 1),
    ('Professional', 'Unbegrenzte Kunden, erweiterte Einblicke.', 79.00, 0, 20,
     '{"advanced_insights":true,"white_label":false,"multi_salon":false}', 2),
    ('Enterprise', 'Mehrere Standorte, White-Label und eigener Mailversand.', 149.00, 0, 0,
     '{"advanced_insights":true,"white_label":true,"multi_salon":true}', 3);

-- ============================================================
-- Insert Default Salon (for testing/setup)
-- ============================================================
-- Guarded with NOT EXISTS rather than ON DUPLICATE KEY UPDATE: there is no
-- unique key on salon_name, so the ON DUPLICATE clause never fires and running
-- this file a second time against an existing database would quietly add
-- another "Demo Salon" to the salon list.
INSERT INTO coiffure_salons (
    salon_name,
    email,
    phone,
    policy_version,
    cancellation_policy,
    data_processing_policy
)
SELECT
    'Demo Salon',
    'info@demosalon.com',
    '+1234567890',
    '1.0',
    'Cancellations must be made at least 24 hours in advance. Late cancellations may incur a fee of 50% of the service cost.',
    'Your personal data will be processed for appointment management, service delivery, and customer relationship management. Data is stored securely and will not be shared with third parties without your consent.'
WHERE NOT EXISTS (
    SELECT 1 FROM coiffure_salons WHERE salon_name = 'Demo Salon'
);

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
