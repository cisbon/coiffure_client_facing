/**
 * Customer Service
 * Handles all customer-related API operations
 */

import { api } from './api';
import { ENDPOINTS } from '../config/api';

/**
 * Create a new customer registration
 */
export async function createCustomer(customerData) {
  return api.post(ENDPOINTS.CUSTOMER, customerData);
}

/**
 * Get customer by ID
 */
export async function getCustomer(customerId) {
  return api.get(ENDPOINTS.CUSTOMER, { id: customerId });
}

/**
 * Get all customers for a salon
 */
export async function getCustomerEntries(salonId, options = {}) {
  return api.get(ENDPOINTS.CUSTOMER_ENTRIES, {
    salon_id: salonId,
    ...options,
  });
}

/**
 * Search customers
 */
export async function searchCustomers(salonId, query) {
  return api.get(ENDPOINTS.CUSTOMER_ENTRIES, {
    salon_id: salonId,
    search: query,
  });
}

export default {
  createCustomer,
  getCustomer,
  getCustomerEntries,
  searchCustomers,
};
