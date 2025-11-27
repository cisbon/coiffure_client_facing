/**
 * Authentication Service
 * Handles user authentication state
 */

import { api } from './api';
import { ENDPOINTS } from '../config/api';
import { STORAGE_KEYS } from '../config/constants';

/**
 * Login user
 */
export async function login(username, password) {
  const response = await api.post(ENDPOINTS.AUTH_LOGIN, { username, password });

  if (response.success && response.token) {
    localStorage.setItem(STORAGE_KEYS.SESSION_TOKEN, response.token);
    localStorage.setItem(STORAGE_KEYS.USER_DATA, JSON.stringify(response.user));
    return response;
  }

  throw new Error(response.error || 'Login failed');
}

/**
 * Logout user
 */
export async function logout() {
  try {
    await api.post(ENDPOINTS.AUTH_LOGOUT, {});
  } finally {
    localStorage.removeItem(STORAGE_KEYS.SESSION_TOKEN);
    localStorage.removeItem(STORAGE_KEYS.USER_DATA);
  }
}

/**
 * Check if user is authenticated
 */
export function isAuthenticated() {
  const token = localStorage.getItem(STORAGE_KEYS.SESSION_TOKEN);
  const userData = localStorage.getItem(STORAGE_KEYS.USER_DATA);
  return !!(token && userData);
}

/**
 * Get current user data
 */
export function getCurrentUser() {
  const userData = localStorage.getItem(STORAGE_KEYS.USER_DATA);
  if (!userData) return null;

  try {
    return JSON.parse(userData);
  } catch {
    return null;
  }
}

/**
 * Get session token
 */
export function getToken() {
  return localStorage.getItem(STORAGE_KEYS.SESSION_TOKEN);
}

/**
 * Get user's salon ID
 */
export function getUserSalonId() {
  const user = getCurrentUser();
  if (!user) return null;

  // Check for assigned salons
  if (user.assigned_salons && user.assigned_salons.length > 0) {
    return user.assigned_salons[0].salon_id;
  }

  // Fallback to salon_id if available
  return user.salon_id || null;
}

export default {
  login,
  logout,
  isAuthenticated,
  getCurrentUser,
  getToken,
  getUserSalonId,
};
