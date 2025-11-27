/**
 * Onboarding Module
 * Customer registration form handling
 */

import { API_BASE_URL } from '../config.js';
import { apiPost } from '../api.js';
import { getUserSalonId } from './auth.js';
import { isSignatureEmpty, getSignatureData } from './signature.js';

/**
 * Initialize onboarding form
 */
export function initOnboarding() {
  const form = document.getElementById('onboarding-form');
  if (!form) return;

  form.addEventListener('submit', handleFormSubmit);
}

/**
 * Handle form submission
 */
async function handleFormSubmit(e) {
  e.preventDefault();

  const form = e.target;
  const submitBtn = document.getElementById('onboarding-submit-btn');
  const messageDiv = document.getElementById('onboarding-message');

  // Get form values
  const fullName = form.full_name.value.trim();
  const email = form.email.value.trim();
  const phone = form.phone.value.trim();
  const consentDataProcessing = form.consent_data_processing.checked;
  const consentCancellation = form.consent_cancellation_policy.checked;
  const consentMarketing = form.consent_marketing?.checked || false;

  // Validation
  const errors = validateForm({
    fullName,
    email,
    phone,
    consentDataProcessing,
    consentCancellation,
  });

  if (errors.length > 0) {
    showMessage(messageDiv, errors.join('<br>'), 'error');
    return;
  }

  // Check signature
  if (isSignatureEmpty()) {
    showMessage(messageDiv, getTranslation('messages.signature_required'), 'error');
    return;
  }

  // Disable submit button
  const originalText = submitBtn.innerHTML;
  submitBtn.disabled = true;
  submitBtn.innerHTML = `
    <svg class="spinner inline h-5 w-5 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
    </svg>
    ${getTranslation('onboarding.submitting')}
  `;

  try {
    const response = await apiPost('/customer.php', {
      salon_id: getUserSalonId(),
      full_name: fullName,
      email: email,
      phone: phone,
      consent_data_processing: consentDataProcessing,
      consent_cancellation_policy: consentCancellation,
      consent_marketing: consentMarketing,
      signature: getSignatureData(),
    });

    if (response.success) {
      showMessage(messageDiv, getTranslation('messages.success'), 'success');
      form.reset();
      if (typeof window.clearSignature === 'function') {
        window.clearSignature();
      }
    } else {
      showMessage(messageDiv, response.error || getTranslation('messages.error'), 'error');
    }
  } catch (error) {
    console.error('Form submission error:', error);
    showMessage(messageDiv, getTranslation('messages.error'), 'error');
  } finally {
    submitBtn.disabled = false;
    submitBtn.innerHTML = originalText;
  }
}

/**
 * Validate form fields
 */
function validateForm({ fullName, email, phone, consentDataProcessing, consentCancellation }) {
  const errors = [];

  if (!fullName) {
    errors.push(getTranslation('messages.required_fields'));
  }

  if (!email || !isValidEmail(email)) {
    errors.push(getTranslation('messages.invalid_email'));
  }

  if (!phone || !isValidPhone(phone)) {
    errors.push(getTranslation('messages.invalid_phone'));
  }

  if (!consentDataProcessing || !consentCancellation) {
    errors.push(getTranslation('messages.consent_required'));
  }

  return errors;
}

/**
 * Validate email format
 */
function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

/**
 * Validate phone format
 */
function isValidPhone(phone) {
  const cleaned = phone.replace(/\s/g, '');
  return /^[+]?[(]?[0-9]{1,4}[)]?[-\s./0-9]*$/.test(cleaned) && cleaned.length >= 7;
}

/**
 * Show message to user
 */
function showMessage(element, message, type) {
  if (!element) return;

  element.classList.remove('hidden');
  element.className = `mt-4 p-4 rounded-lg ${
    type === 'success'
      ? 'bg-green-100 text-green-800 border border-green-200'
      : 'bg-red-100 text-red-800 border border-red-200'
  }`;
  element.innerHTML = message;

  // Auto-hide success messages
  if (type === 'success') {
    setTimeout(() => {
      element.classList.add('hidden');
    }, 5000);
  }
}

/**
 * Get translation (fallback to key if i18n not available)
 */
function getTranslation(key) {
  if (typeof i18n !== 'undefined' && typeof i18n.t === 'function') {
    return i18n.t(key);
  }
  // Fallback translations
  const fallbacks = {
    'messages.required_fields': 'Please fill out all required fields',
    'messages.invalid_email': 'Please enter a valid email address',
    'messages.invalid_phone': 'Please enter a valid phone number',
    'messages.consent_required': 'Privacy consent is required',
    'messages.signature_required': 'Signature is required',
    'messages.success': 'Success! Your registration has been submitted.',
    'messages.error': 'An error occurred. Please try again.',
    'onboarding.submitting': 'Submitting...',
  };
  return fallbacks[key] || key;
}

export default {
  initOnboarding,
};
