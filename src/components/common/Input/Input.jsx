/**
 * Input Component
 * Reusable form input with label and error handling
 */

import { forwardRef } from 'react';
import styles from './Input.module.css';

const Input = forwardRef(function Input(
  {
    label,
    type = 'text',
    name,
    value,
    placeholder,
    error,
    helperText,
    required = false,
    disabled = false,
    onChange,
    onBlur,
    className = '',
    ...props
  },
  ref
) {
  const inputId = props.id || name;

  const inputClassNames = [
    styles.input,
    error && styles.inputError,
    disabled && styles.inputDisabled,
    className,
  ]
    .filter(Boolean)
    .join(' ');

  return (
    <div className={styles.container}>
      {label && (
        <label htmlFor={inputId} className={styles.label}>
          {label}
          {required && <span className={styles.required}>*</span>}
        </label>
      )}

      <input
        ref={ref}
        id={inputId}
        type={type}
        name={name}
        value={value}
        placeholder={placeholder}
        disabled={disabled}
        className={inputClassNames}
        onChange={onChange}
        onBlur={onBlur}
        aria-invalid={!!error}
        aria-describedby={error ? `${inputId}-error` : undefined}
        {...props}
      />

      {error && (
        <p id={`${inputId}-error`} className={styles.error} role="alert">
          {error}
        </p>
      )}

      {helperText && !error && (
        <p className={styles.helperText}>{helperText}</p>
      )}
    </div>
  );
});

export default Input;
