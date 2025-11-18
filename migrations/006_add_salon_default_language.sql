-- Add default language setting to salons table
-- This controls the language shown to customers on tablets

ALTER TABLE coiffure_salons
ADD COLUMN default_language VARCHAR(5) DEFAULT 'de' AFTER is_active;

-- Update existing salons to have German as default
UPDATE coiffure_salons SET default_language = 'de' WHERE default_language IS NULL;
