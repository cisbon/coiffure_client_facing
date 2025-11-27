/**
 * OnboardingPage
 * Customer registration form with GDPR consent
 */

import { useState, useCallback } from 'react';
import { useLanguage } from '../context/LanguageContext';
import { useSalon } from '../context/SalonContext';
import { useCustomer } from '../context/CustomerContext';
import { useForm } from '../hooks/useForm';
import { createCustomer } from '../services/customerService';
import { validateOnboardingForm, createValidator } from '../utils/validators';

import Card from '../components/common/Card';
import Input from '../components/common/Input';
import Button from '../components/common/Button';
import ConsentCheckbox from '../components/forms/ConsentCheckbox';
import SignaturePad from '../components/forms/SignaturePad';

import styles from './OnboardingPage.module.css';

const initialFormValues = {
  full_name: '',
  email: '',
  phone: '',
  consent_data_processing: false,
  consent_cancellation_policy: false,
  consent_marketing: false,
  signature: null,
};

export default function OnboardingPage() {
  const { t } = useLanguage();
  const { salonId } = useSalon();
  const { setCustomer, saveFormDraft, clearFormDraft, formDraft } = useCustomer();

  const [submitStatus, setSubmitStatus] = useState({ success: false, error: null });

  // Create validator with translations
  const validate = useCallback(
    (values) => validateOnboardingForm(values, t),
    [t]
  );

  // Initialize form with draft if available
  const initialValues = formDraft || initialFormValues;

  const {
    values,
    errors,
    touched,
    isSubmitting,
    handleChange,
    handleBlur,
    handleSubmit,
    setFieldValue,
    getFieldError,
    reset,
  } = useForm(initialValues, validate, {
    onChange: saveFormDraft,
  });

  // Handle form submission
  const onSubmit = async (formValues) => {
    setSubmitStatus({ success: false, error: null });

    try {
      const customerData = {
        salon_id: salonId,
        full_name: formValues.full_name.trim(),
        email: formValues.email.trim(),
        phone: formValues.phone.trim(),
        consent_data_processing: formValues.consent_data_processing,
        consent_cancellation_policy: formValues.consent_cancellation_policy,
        consent_marketing: formValues.consent_marketing,
        signature: formValues.signature,
      };

      const response = await createCustomer(customerData);

      if (response.success) {
        setCustomer(response.customer);
        clearFormDraft();
        setSubmitStatus({ success: true, error: null });
        reset();
      } else {
        throw new Error(response.error || 'Failed to submit registration');
      }
    } catch (error) {
      setSubmitStatus({
        success: false,
        error: error.message || t('errors.server'),
      });
    }
  };

  // Handle signature change
  const handleSignatureChange = (signatureData) => {
    setFieldValue('signature', signatureData);
  };

  // If submission was successful, show success message
  if (submitStatus.success) {
    return (
      <Card padding="large">
        <div className={styles.successContainer}>
          <div className={styles.successIcon}>✓</div>
          <h2 className={styles.successTitle}>{t('onboarding.success')}</h2>
          <p className={styles.successMessage}>{t('onboarding.success_message')}</p>
          <Button
            onClick={() => setSubmitStatus({ success: false, error: null })}
            variant="primary"
          >
            {t('common.next')}
          </Button>
        </div>
      </Card>
    );
  }

  return (
    <Card padding="large">
      <h2 className={styles.title}>{t('onboarding.title')}</h2>

      <form onSubmit={handleSubmit(onSubmit)} className={styles.form}>
        {/* Personal Information */}
        <section className={styles.section}>
          <h3 className={styles.sectionTitle}>Personal Information</h3>
          <div className={styles.grid}>
            <Input
              label={t('onboarding.full_name')}
              name="full_name"
              value={values.full_name}
              placeholder={t('onboarding.full_name_placeholder')}
              error={getFieldError('full_name')}
              required
              onChange={handleChange}
              onBlur={handleBlur}
            />

            <Input
              label={t('onboarding.email')}
              type="email"
              name="email"
              value={values.email}
              placeholder={t('onboarding.email_placeholder')}
              error={getFieldError('email')}
              required
              onChange={handleChange}
              onBlur={handleBlur}
            />

            <div className={styles.fullWidth}>
              <Input
                label={t('onboarding.phone')}
                type="tel"
                name="phone"
                value={values.phone}
                placeholder={t('onboarding.phone_placeholder')}
                error={getFieldError('phone')}
                required
                onChange={handleChange}
                onBlur={handleBlur}
              />
            </div>
          </div>
        </section>

        {/* GDPR Consent */}
        <section className={styles.section}>
          <h3 className={styles.sectionTitle}>{t('onboarding.gdpr_title')}</h3>

          <div className={styles.gdprNotice}>
            <strong>{t('onboarding.gdpr_notice_title')}</strong>
            <p>{t('onboarding.gdpr_notice')}</p>
          </div>

          <div className={styles.checkboxGroup}>
            <ConsentCheckbox
              name="consent_data_processing"
              label={t('onboarding.consent_data_processing')}
              checked={values.consent_data_processing}
              required
              error={getFieldError('consent_data_processing')}
              onChange={handleChange}
              onBlur={handleBlur}
            />

            <ConsentCheckbox
              name="consent_cancellation_policy"
              label={t('onboarding.consent_cancellation')}
              checked={values.consent_cancellation_policy}
              required
              error={getFieldError('consent_cancellation_policy')}
              onChange={handleChange}
              onBlur={handleBlur}
            />

            <ConsentCheckbox
              name="consent_marketing"
              label={t('onboarding.consent_marketing')}
              checked={values.consent_marketing}
              onChange={handleChange}
            />
          </div>
        </section>

        {/* Signature */}
        <section className={styles.section}>
          <h3 className={styles.sectionTitle}>{t('onboarding.signature_title')}</h3>
          <SignaturePad
            label={t('onboarding.signature_description')}
            clearLabel={t('onboarding.signature_clear')}
            error={getFieldError('signature')}
            onSignatureChange={handleSignatureChange}
          />
        </section>

        {/* Error Message */}
        {submitStatus.error && (
          <div className={styles.errorMessage}>
            {submitStatus.error}
          </div>
        )}

        {/* Submit Button */}
        <Button
          type="submit"
          variant="primary"
          size="large"
          fullWidth
          loading={isSubmitting}
        >
          {isSubmitting ? t('onboarding.submitting') : t('onboarding.submit_button')}
        </Button>
      </form>
    </Card>
  );
}
