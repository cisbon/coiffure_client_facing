/**
 * API Configuration
 */

export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'https://clouedo.com/coiffure/api';

export const ENDPOINTS = {
  // Authentication
  AUTH_LOGIN: '/auth-login.php',
  AUTH_LOGOUT: '/auth-logout.php',

  // Salon
  SALON_BRANDING: '/salon-branding.php',
  SALON_MANAGEMENT: '/salon-management.php',

  // Customer
  CUSTOMER: '/customer.php',
  CUSTOMER_ENTRIES: '/customer-entries.php',

  // Social Links
  SOCIAL_LINKS: '/social-links.php',

  // AI Consultation
  AI_CONSULTATION: '/ai-consultation.php',

  // User
  USER_SETTINGS: '/user-settings.php',
};

export default {
  API_BASE_URL,
  ENDPOINTS,
};
