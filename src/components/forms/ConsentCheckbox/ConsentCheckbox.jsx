/**
 * ConsentCheckbox Component
 * Checkbox for GDPR consent
 */

import styles from './ConsentCheckbox.module.css';

export default function ConsentCheckbox({
  id,
  name,
  label,
  checked = false,
  required = false,
  disabled = false,
  error,
  onChange,
  onBlur,
  className = '',
}) {
  return (
    <div className={`${styles.container} ${className}`}>
      <label className={styles.label}>
        <input
          type="checkbox"
          id={id || name}
          name={name}
          checked={checked}
          disabled={disabled}
          onChange={onChange}
          onBlur={onBlur}
          className={styles.checkbox}
          aria-invalid={!!error}
        />
        <span className={styles.checkmark}></span>
        <span className={styles.text}>
          {label}
          {required && <span className={styles.required}>*</span>}
        </span>
      </label>

      {error && <p className={styles.error}>{error}</p>}
    </div>
  );
}
