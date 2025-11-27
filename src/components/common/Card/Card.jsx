/**
 * Card Component
 * Reusable card container with variants
 */

import styles from './Card.module.css';

export default function Card({
  children,
  variant = 'default',
  padding = 'medium',
  className = '',
  onClick,
  ...props
}) {
  const classNames = [
    styles.card,
    styles[variant],
    styles[`padding-${padding}`],
    onClick && styles.clickable,
    className,
  ]
    .filter(Boolean)
    .join(' ');

  return (
    <div
      className={classNames}
      onClick={onClick}
      role={onClick ? 'button' : undefined}
      tabIndex={onClick ? 0 : undefined}
      {...props}
    >
      {children}
    </div>
  );
}

// Card Header
Card.Header = function CardHeader({ children, className = '' }) {
  return (
    <div className={`${styles.header} ${className}`}>
      {children}
    </div>
  );
};

// Card Title
Card.Title = function CardTitle({ children, className = '' }) {
  return (
    <h3 className={`${styles.title} ${className}`}>
      {children}
    </h3>
  );
};

// Card Body
Card.Body = function CardBody({ children, className = '' }) {
  return (
    <div className={`${styles.body} ${className}`}>
      {children}
    </div>
  );
};

// Card Footer
Card.Footer = function CardFooter({ children, className = '' }) {
  return (
    <div className={`${styles.footer} ${className}`}>
      {children}
    </div>
  );
};
