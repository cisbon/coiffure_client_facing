-- ============================================================
-- Migration: Rename customer_user role to customer_facing_tablet_user
-- Description: More descriptive role name for tablet users
-- ============================================================

USE salonlyft;

-- Update the ENUM in coiffure_users table
ALTER TABLE coiffure_users
MODIFY COLUMN role ENUM(
    'admin',
    'admin_delegate',
    'customer_admin',
    'customer_admin_delegate',
    'customer_facing_tablet_user'
) NOT NULL;

-- Update existing data: rename customer_user to customer_facing_tablet_user
UPDATE coiffure_users
SET role = 'customer_facing_tablet_user'
WHERE role = 'customer_user';

-- ============================================================
-- End of Migration
-- ============================================================
