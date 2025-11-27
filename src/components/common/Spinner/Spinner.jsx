/**
 * Spinner Component
 * Loading indicator
 */

import styles from './Spinner.module.css';

export default function Spinner({
  size = 'medium',
  color = 'primary',
  className = '',
  label = 'Loading...',
}) {
  const classNames = [
    styles.spinner,
    styles[size],
    styles[color],
    className,
  ]
    .filter(Boolean)
    .join(' ');

  return (
    <div className={classNames} role="status" aria-label={label}>
      <div className={styles.circle}></div>
      <span className={styles.srOnly}>{label}</span>
    </div>
  );
}

// Full-page loading spinner
Spinner.Fullscreen = function FullscreenSpinner({ label = 'Loading...' }) {
  return (
    <div className={styles.fullscreen}>
      <Spinner size="large" label={label} />
      <p className={styles.label}>{label}</p>
    </div>
  );
};

// Inline spinner with text
Spinner.Inline = function InlineSpinner({ children, size = 'small' }) {
  return (
    <div className={styles.inline}>
      <Spinner size={size} />
      {children && <span>{children}</span>}
    </div>
  );
};
