/**
 * Formatting Utilities
 */

/**
 * Format phone number for display
 * @param {string} phone - Raw phone number
 * @returns {string} Formatted phone number
 */
export function formatPhone(phone) {
  if (!phone) return '';

  // Remove all non-digit characters except +
  const cleaned = phone.replace(/[^\d+]/g, '');

  // German phone format
  if (cleaned.startsWith('+49')) {
    const number = cleaned.substring(3);
    if (number.length >= 10) {
      return `+49 ${number.slice(0, 3)} ${number.slice(3, 6)} ${number.slice(6)}`;
    }
  }

  return phone;
}

/**
 * Format date for display (German format)
 * @param {Date|string} date - Date to format
 * @returns {string} Formatted date (DD.MM.YYYY)
 */
export function formatDate(date) {
  if (!date) return '';

  const d = date instanceof Date ? date : new Date(date);

  if (isNaN(d.getTime())) return '';

  const day = String(d.getDate()).padStart(2, '0');
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const year = d.getFullYear();

  return `${day}.${month}.${year}`;
}

/**
 * Format datetime for display (German format)
 * @param {Date|string} date - Date to format
 * @returns {string} Formatted datetime (DD.MM.YYYY HH:MM)
 */
export function formatDateTime(date) {
  if (!date) return '';

  const d = date instanceof Date ? date : new Date(date);

  if (isNaN(d.getTime())) return '';

  const day = String(d.getDate()).padStart(2, '0');
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const year = d.getFullYear();
  const hours = String(d.getHours()).padStart(2, '0');
  const minutes = String(d.getMinutes()).padStart(2, '0');

  return `${day}.${month}.${year} ${hours}:${minutes}`;
}

/**
 * Format currency (Euro)
 * @param {number} amount - Amount in cents or euros
 * @param {boolean} inCents - Whether amount is in cents
 * @returns {string} Formatted currency
 */
export function formatCurrency(amount, inCents = false) {
  if (amount === null || amount === undefined) return '';

  const euros = inCents ? amount / 100 : amount;

  return new Intl.NumberFormat('de-DE', {
    style: 'currency',
    currency: 'EUR',
  }).format(euros);
}

/**
 * Truncate text with ellipsis
 * @param {string} text - Text to truncate
 * @param {number} maxLength - Maximum length
 * @returns {string} Truncated text
 */
export function truncate(text, maxLength = 50) {
  if (!text) return '';
  if (text.length <= maxLength) return text;
  return text.substring(0, maxLength - 3) + '...';
}

/**
 * Capitalize first letter
 * @param {string} text - Text to capitalize
 * @returns {string} Capitalized text
 */
export function capitalize(text) {
  if (!text) return '';
  return text.charAt(0).toUpperCase() + text.slice(1);
}

/**
 * Format name (proper case)
 * @param {string} name - Name to format
 * @returns {string} Formatted name
 */
export function formatName(name) {
  if (!name) return '';

  return name
    .trim()
    .split(/\s+/)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
    .join(' ');
}

export default {
  formatPhone,
  formatDate,
  formatDateTime,
  formatCurrency,
  truncate,
  capitalize,
  formatName,
};
