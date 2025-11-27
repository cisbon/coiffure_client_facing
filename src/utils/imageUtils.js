/**
 * Image Utility Functions
 */

import { IMAGE_CONSTRAINTS } from '../config/constants';

/**
 * Convert file to base64
 * @param {File} file - Image file
 * @returns {Promise<string>} Base64 string (with data URL prefix)
 */
export function fileToBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = (error) => reject(error);
    reader.readAsDataURL(file);
  });
}

/**
 * Remove data URL prefix from base64 string
 * @param {string} dataUrl - Data URL (e.g., "data:image/jpeg;base64,...")
 * @returns {string} Pure base64 string
 */
export function removeBase64Prefix(dataUrl) {
  if (!dataUrl) return '';
  const base64Index = dataUrl.indexOf('base64,');
  if (base64Index === -1) return dataUrl;
  return dataUrl.substring(base64Index + 7);
}

/**
 * Compress image to reduce file size
 * @param {string} dataUrl - Image data URL
 * @param {number} maxWidth - Maximum width in pixels
 * @param {number} quality - JPEG quality (0-1)
 * @returns {Promise<string>} Compressed image data URL
 */
export function compressImage(dataUrl, maxWidth = 1280, quality = IMAGE_CONSTRAINTS.COMPRESSION_QUALITY) {
  return new Promise((resolve) => {
    const img = new Image();
    img.onload = () => {
      const canvas = document.createElement('canvas');
      let width = img.width;
      let height = img.height;

      // Scale down if needed
      if (width > maxWidth) {
        height = (height * maxWidth) / width;
        width = maxWidth;
      }

      canvas.width = width;
      canvas.height = height;

      const ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0, width, height);

      resolve(canvas.toDataURL('image/jpeg', quality));
    };
    img.src = dataUrl;
  });
}

/**
 * Validate image file
 * @param {File} file - Image file to validate
 * @returns {{valid: boolean, error?: string}}
 */
export function validateImageFile(file) {
  if (!file) {
    return { valid: false, error: 'No file provided' };
  }

  // Check file type
  if (!IMAGE_CONSTRAINTS.ACCEPTED_TYPES.includes(file.type)) {
    return { valid: false, error: 'Invalid file type. Please use JPEG, PNG, or WebP.' };
  }

  // Check file size
  if (file.size > IMAGE_CONSTRAINTS.MAX_SIZE_BYTES) {
    return { valid: false, error: `File too large. Maximum size is ${IMAGE_CONSTRAINTS.MAX_SIZE_MB}MB.` };
  }

  return { valid: true };
}

/**
 * Process image file for API upload
 * @param {File} file - Image file
 * @returns {Promise<string>} Processed base64 string (without prefix)
 */
export async function processImageForUpload(file) {
  // Convert to base64
  const dataUrl = await fileToBase64(file);

  // Compress if needed
  const compressed = await compressImage(dataUrl);

  // Remove prefix for API
  return removeBase64Prefix(compressed);
}

/**
 * Process data URL for API upload
 * @param {string} dataUrl - Image data URL
 * @returns {Promise<string>} Processed base64 string (without prefix)
 */
export async function processDataUrlForUpload(dataUrl) {
  // Compress if needed
  const compressed = await compressImage(dataUrl);

  // Remove prefix for API
  return removeBase64Prefix(compressed);
}

export default {
  fileToBase64,
  removeBase64Prefix,
  compressImage,
  validateImageFile,
  processImageForUpload,
  processDataUrlForUpload,
};
