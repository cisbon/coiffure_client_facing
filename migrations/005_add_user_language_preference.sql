-- Add language preference column to users table
-- Default to 'de' (German) as specified

ALTER TABLE coiffure_users
ADD COLUMN preferred_language VARCHAR(5) DEFAULT 'de' AFTER role;

-- Update existing users to have German as default
UPDATE coiffure_users SET preferred_language = 'de' WHERE preferred_language IS NULL;
