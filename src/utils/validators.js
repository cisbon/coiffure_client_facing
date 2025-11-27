/**
 * Form Validation Utilities
 */

/**
 * Email validation regex
 */
const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Phone validation regex (international format)
 */
const PHONE_REGEX = /^[+]?[(]?[0-9]{1,4}[)]?[-\s./0-9]*$/;

/**
 * Validate email address
 */
export function isValidEmail(email) {
  if (!email) return false;
  return EMAIL_REGEX.test(email.trim());
}

/**
 * Validate phone number
 */
export function isValidPhone(phone) {
  if (!phone) return false;
  const cleaned = phone.replace(/\s/g, '');
  return PHONE_REGEX.test(cleaned) && cleaned.length >= 7;
}

/**
 * Check if value is not empty
 */
export function isNotEmpty(value) {
  if (value === null || value === undefined) return false;
  if (typeof value === 'string') return value.trim().length > 0;
  if (typeof value === 'boolean') return true;
  return true;
}

/**
 * Validate minimum length
 */
export function hasMinLength(value, min) {
  if (!value) return false;
  return String(value).trim().length >= min;
}

/**
 * Validate maximum length
 */
export function hasMaxLength(value, max) {
  if (!value) return true;
  return String(value).trim().length <= max;
}

/**
 * Validate customer onboarding form
 */
export function validateOnboardingForm(values, t) {
  const errors = {};

  // Full name
  if (!isNotEmpty(values.full_name)) {
    errors.full_name = t ? t('validation.required') : 'This field is required';
  } else if (!hasMinLength(values.full_name, 2)) {
    errors.full_name = t ? t('validation.minLength', { min: 2 }) : 'At least 2 characters required';
  }

  // Email
  if (!isNotEmpty(values.email)) {
    errors.email = t ? t('validation.required') : 'This field is required';
  } else if (!isValidEmail(values.email)) {
    errors.email = t ? t('validation.invalidEmail') : 'Invalid email address';
  }

  // Phone
  if (!isNotEmpty(values.phone)) {
    errors.phone = t ? t('validation.required') : 'This field is required';
  } else if (!isValidPhone(values.phone)) {
    errors.phone = t ? t('validation.invalidPhone') : 'Invalid phone number';
  }

  // Data processing consent
  if (!values.consent_data_processing) {
    errors.consent_data_processing = t ? t('validation.consent_required') : 'Privacy consent is required';
  }

  // Cancellation policy consent
  if (!values.consent_cancellation_policy) {
    errors.consent_cancellation_policy = t ? t('validation.required') : 'This field is required';
  }

  // Signature
  if (!values.signature) {
    errors.signature = t ? t('validation.signature_required') : 'Signature is required';
  }

  return errors;
}

/**
 * Create a validator function with translations
 */
export function createValidator(validationFn, t) {
  return (values) => validationFn(values, t);
}

export default {
  isValidEmail,
  isValidPhone,
  isNotEmpty,
  hasMinLength,
  hasMaxLength,
  validateOnboardingForm,
  createValidator,
};
