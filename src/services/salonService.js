/**
 * Salon Service
 * Handles salon-related API operations
 */

import { api } from './api';
import { ENDPOINTS } from '../config/api';

/**
 * Get salon branding (logo, colors, etc.)
 */
export async function getSalonBranding(salonId) {
  return api.get(ENDPOINTS.SALON_BRANDING, { salon_id: salonId });
}

/**
 * Get salon details
 */
export async function getSalonDetails(salonId) {
  return api.get(ENDPOINTS.SALON_MANAGEMENT, { id: salonId });
}

/**
 * Get user's assigned salons
 */
export async function getUserSalons() {
  return api.get(ENDPOINTS.SALON_MANAGEMENT);
}

/**
 * Get social links for a salon
 */
export async function getSocialLinks(salonId) {
  return api.get(ENDPOINTS.SOCIAL_LINKS, {
    salon_id: salonId,
    include_inactive: false,
  });
}

export default {
  getSalonBranding,
  getSalonDetails,
  getUserSalons,
  getSocialLinks,
};
