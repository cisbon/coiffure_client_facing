/**
 * Button Component
 * Reusable button with variants and loading state
 */

import { forwardRef } from 'react';
import styles from './Button.module.css';

const Button = forwardRef(function Button(
  {
    children,
    variant = 'primary',
    size = 'medium',
    loading = false,
    disabled = false,
    fullWidth = false,
    icon = null,
    iconPosition = 'left',
    onClick,
    type = 'button',
    className = '',
    ...props
  },
  ref
) {
  const classNames = [
    styles.button,
    styles[variant],
    styles[size],
    fullWidth && styles.fullWidth,
    loading && styles.loading,
    className,
  ]
    .filter(Boolean)
    .join(' ');

  return (
    <button
      ref={ref}
      type={type}
      className={classNames}
      disabled={disabled || loading}
      onClick={onClick}
      {...props}
    >
      {loading ? (
        <span className={styles.spinner} />
      ) : (
        <>
          {icon && iconPosition === 'left' && <span className={styles.icon}>{icon}</span>}
          {children && <span className={styles.text}>{children}</span>}
          {icon && iconPosition === 'right' && <span className={styles.icon}>{icon}</span>}
        </>
      )}
    </button>
  );
});

export default Button;
