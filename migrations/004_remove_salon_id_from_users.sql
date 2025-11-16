-- ============================================================
-- Migration: Remove salon_id column from users table
-- Description: Remove deprecated salon_id column, use only user_salons junction table
-- ============================================================

USE salonlyft;

-- Remove the salon_id column from coiffure_users table
-- All salon assignments are now managed through coiffure_user_salons junction table
ALTER TABLE coiffure_users DROP COLUMN salon_id;

-- ============================================================
-- End of Migration
-- ============================================================
