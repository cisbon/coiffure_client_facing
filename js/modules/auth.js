/**
 * Authentication Module
 * Handles user session and authentication state
 */

// Session state
let sessionToken = localStorage.getItem('session_token');
let currentUser = null;
let userSalonId = null;

// Try to parse stored user data
try {
  const userData = localStorage.getItem('user_data');
  if (userData) {
    currentUser = JSON.parse(userData);
  }
} catch (e) {
  console.warn('Failed to parse user data from localStorage');
}

/**
 * Get the current session token
 */
export function getSessionToken() {
  return sessionToken;
}

/**
 * Get the current user
 */
export function getCurrentUser() {
  return currentUser;
}

/**
 * Get the user's salon ID
 */
export function getUserSalonId() {
  return userSalonId;
}

/**
 * Set the user's salon ID
 */
export function setUserSalonId(salonId) {
  userSalonId = salonId;
}

/**
 * Check if user is authenticated
 */
export function isAuthenticated() {
  return !!(sessionToken && currentUser);
}

/**
 * Check authentication and redirect if not logged in
 */
export function checkAuthentication() {
  sessionToken = localStorage.getItem('session_token');
  const userDataStr = localStorage.getItem('user_data');

  if (!sessionToken || !userDataStr) {
    window.location.href = 'login.html';
    return false;
  }

  try {
    currentUser = JSON.parse(userDataStr);
  } catch (e) {
    window.location.href = 'login.html';
    return false;
  }

  return true;
}

/**
 * Logout the current user
 */
export function logout() {
  localStorage.removeItem('session_token');
  localStorage.removeItem('user_data');
  sessionToken = null;
  currentUser = null;
  userSalonId = null;
  window.location.href = 'login.html';
}

export default {
  getSessionToken,
  getCurrentUser,
  getUserSalonId,
  setUserSalonId,
  isAuthenticated,
  checkAuthentication,
  logout,
};
