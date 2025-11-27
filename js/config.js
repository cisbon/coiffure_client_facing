/**
 * Configuration Module
 * API endpoints and app settings
 */

export const API_BASE_URL = 'https://clouedo.com/coiffure/api';
export const POLICY_VERSION = '1.0';

// API Endpoints
export const ENDPOINTS = {
  AUTH_LOGIN: '/auth-login.php',
  AUTH_LOGOUT: '/auth-logout.php',
  SALON_MANAGEMENT: '/salon-management.php',
  SALON_BRANDING: '/salon-branding.php',
  CUSTOMER: '/customer.php',
  SOCIAL_LINKS: '/social-links.php',
  AI_CONSULTATION: '/ai-consultation.php',
};

// Default branding colors
export const DEFAULT_COLORS = {
  primary: '#9333EA',
  secondary: '#EC4899',
  background: '#FFFFFF',
  button: '#9333EA',
  text: '#1F2937',
};

export default {
  API_BASE_URL,
  POLICY_VERSION,
  ENDPOINTS,
  DEFAULT_COLORS,
};
