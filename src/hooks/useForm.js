/**
 * useForm Hook
 * Form state management with validation
 */

import { useState, useCallback, useMemo } from 'react';

/**
 * Hook for form state management
 * @param {object} initialValues - Initial form values
 * @param {function} validate - Validation function (returns object of errors)
 * @param {object} options - Additional options
 */
export function useForm(initialValues, validate, options = {}) {
  const { onSubmit, onChange } = options;

  const [values, setValues] = useState(initialValues);
  const [errors, setErrors] = useState({});
  const [touched, setTouched] = useState({});
  const [isSubmitting, setIsSubmitting] = useState(false);

  /**
   * Handle input change
   */
  const handleChange = useCallback((e) => {
    const { name, value, type, checked } = e.target;
    const newValue = type === 'checkbox' ? checked : value;

    setValues(prev => {
      const updated = { ...prev, [name]: newValue };
      onChange?.(updated);
      return updated;
    });

    // Clear error on change
    if (errors[name]) {
      setErrors(prev => ({ ...prev, [name]: undefined }));
    }
  }, [errors, onChange]);

  /**
   * Handle field blur
   */
  const handleBlur = useCallback((e) => {
    const { name } = e.target;
    setTouched(prev => ({ ...prev, [name]: true }));

    // Validate single field
    if (validate) {
      const validationErrors = validate(values);
      if (validationErrors[name]) {
        setErrors(prev => ({ ...prev, [name]: validationErrors[name] }));
      }
    }
  }, [values, validate]);

  /**
   * Set a single field value
   */
  const setFieldValue = useCallback((name, value) => {
    setValues(prev => ({ ...prev, [name]: value }));
  }, []);

  /**
   * Set a single field error
   */
  const setFieldError = useCallback((name, error) => {
    setErrors(prev => ({ ...prev, [name]: error }));
  }, []);

  /**
   * Set field as touched
   */
  const setFieldTouched = useCallback((name, isTouched = true) => {
    setTouched(prev => ({ ...prev, [name]: isTouched }));
  }, []);

  /**
   * Validate all fields
   */
  const validateForm = useCallback(() => {
    if (!validate) return {};

    const validationErrors = validate(values);
    setErrors(validationErrors);
    return validationErrors;
  }, [values, validate]);

  /**
   * Handle form submit
   */
  const handleSubmit = useCallback((submitFn) => async (e) => {
    e?.preventDefault();

    // Mark all fields as touched
    const allTouched = Object.keys(values).reduce(
      (acc, key) => ({ ...acc, [key]: true }),
      {}
    );
    setTouched(allTouched);

    // Validate
    const validationErrors = validateForm();

    if (Object.keys(validationErrors).some(key => validationErrors[key])) {
      return false;
    }

    // Submit
    setIsSubmitting(true);

    try {
      await (submitFn || onSubmit)?.(values);
      return true;
    } catch (err) {
      console.error('Form submission error:', err);
      return false;
    } finally {
      setIsSubmitting(false);
    }
  }, [values, validateForm, onSubmit]);

  /**
   * Reset form to initial values
   */
  const reset = useCallback(() => {
    setValues(initialValues);
    setErrors({});
    setTouched({});
    setIsSubmitting(false);
  }, [initialValues]);

  /**
   * Reset to specific values
   */
  const resetTo = useCallback((newValues) => {
    setValues(newValues);
    setErrors({});
    setTouched({});
    setIsSubmitting(false);
  }, []);

  /**
   * Check if form has any errors
   */
  const hasErrors = useMemo(() => {
    return Object.values(errors).some(Boolean);
  }, [errors]);

  /**
   * Check if form is valid
   */
  const isValid = useMemo(() => {
    if (!validate) return true;
    const validationErrors = validate(values);
    return !Object.values(validationErrors).some(Boolean);
  }, [values, validate]);

  /**
   * Check if a field has an error and is touched
   */
  const getFieldError = useCallback((name) => {
    return touched[name] ? errors[name] : undefined;
  }, [touched, errors]);

  return {
    values,
    errors,
    touched,
    isSubmitting,
    handleChange,
    handleBlur,
    handleSubmit,
    setFieldValue,
    setFieldError,
    setFieldTouched,
    validateForm,
    reset,
    resetTo,
    setValues,
    hasErrors,
    isValid,
    getFieldError,
  };
}

export default useForm;
