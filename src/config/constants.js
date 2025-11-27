/**
 * App-wide constants
 */

// Supported languages
export const LANGUAGES = {
  DE: 'de',
  EN: 'en',
};

export const DEFAULT_LANGUAGE = LANGUAGES.DE;

// Social link types
export const SOCIAL_LINK_TYPES = {
  INSTAGRAM: 'instagram',
  FACEBOOK: 'facebook',
  TIKTOK: 'tiktok',
  GOOGLE_REVIEWS: 'google_reviews',
  YELP: 'yelp',
  TWITTER: 'twitter',
  LINKEDIN: 'linkedin',
  YOUTUBE: 'youtube',
  PINTEREST: 'pinterest',
  CUSTOM: 'custom',
};

// Style presets for AI consultation
export const STYLE_PRESETS = {
  BUSINESS: 'business',
  CASUAL: 'casual',
  TRENDY: 'trendy',
  CLASSIC: 'classic',
  EDGY: 'edgy',
  NATURAL: 'natural',
};

// Haircut options
export const HAIRCUTS = {
  WOMEN: [
    'pixie', 'bob', 'lob', 'shag', 'layers', 'butterfly',
    'blunt', 'updo', 'wolf',
  ],
  MEN: [
    'buzz', 'crew', 'fade', 'undercut', 'pompadour', 'quiff',
    'side_part', 'mullet', 'man_bun', 'french_crop', 'slick_back', 'textured_crop',
  ],
};

// Hair customization options
export const HAIR_OPTIONS = {
  LENGTH: ['short', 'medium', 'long'],
  TEXTURE: ['straight', 'wavy', 'curly', 'messy', 'sleek', 'voluminous'],
  BANGS: ['none', 'yes', 'side'],
  COLOR: ['natural', 'highlights', 'bold'],
};

// Local storage keys
export const STORAGE_KEYS = {
  SESSION_TOKEN: 'session_token',
  USER_DATA: 'user_data',
  APP_LANGUAGE: 'app_language',
  FORM_DRAFT: 'customer_form_draft',
};

// Touch target minimum size (WCAG)
export const TOUCH_TARGET_MIN = 44;

// Image constraints
export const IMAGE_CONSTRAINTS = {
  MAX_SIZE_MB: 5,
  MAX_SIZE_BYTES: 5 * 1024 * 1024,
  ACCEPTED_TYPES: ['image/jpeg', 'image/png', 'image/webp'],
  COMPRESSION_QUALITY: 0.8,
};

export default {
  LANGUAGES,
  DEFAULT_LANGUAGE,
  SOCIAL_LINK_TYPES,
  STYLE_PRESETS,
  HAIRCUTS,
  HAIR_OPTIONS,
  STORAGE_KEYS,
  TOUCH_TARGET_MIN,
  IMAGE_CONSTRAINTS,
};
