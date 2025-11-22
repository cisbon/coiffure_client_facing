-- Add branding/white-labeling columns to salons table
-- This allows each salon to customize logo and color scheme

ALTER TABLE coiffure_salons
ADD COLUMN logo_path VARCHAR(255) DEFAULT NULL AFTER default_language,
ADD COLUMN primary_color VARCHAR(7) DEFAULT '#9333EA' AFTER logo_path,
ADD COLUMN secondary_color VARCHAR(7) DEFAULT '#EC4899' AFTER primary_color,
ADD COLUMN background_color VARCHAR(7) DEFAULT '#FFFFFF' AFTER secondary_color,
ADD COLUMN button_color VARCHAR(7) DEFAULT '#9333EA' AFTER background_color,
ADD COLUMN text_color VARCHAR(7) DEFAULT '#1F2937' AFTER button_color;

-- Set default purple/pink brand colors for existing salons
-- Primary: Purple (#9333EA), Secondary: Pink (#EC4899)
UPDATE coiffure_salons SET
    primary_color = '#9333EA',
    secondary_color = '#EC4899',
    background_color = '#FFFFFF',
    button_color = '#9333EA',
    text_color = '#1F2937'
WHERE primary_color IS NULL;
