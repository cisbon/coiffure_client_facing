/**
 * QRCode Component
 * Wrapper around qrcode.react library
 */

import { QRCodeSVG } from 'qrcode.react';
import styles from './QRCode.module.css';

export default function QRCode({
  value,
  size = 200,
  level = 'M',
  includeMargin = true,
  bgColor = '#FFFFFF',
  fgColor = '#000000',
  className = '',
  onClick,
}) {
  if (!value) {
    return null;
  }

  return (
    <div
      className={`${styles.container} ${className}`}
      onClick={onClick}
      role={onClick ? 'button' : undefined}
      tabIndex={onClick ? 0 : undefined}
    >
      <QRCodeSVG
        value={value}
        size={size}
        level={level}
        includeMargin={includeMargin}
        bgColor={bgColor}
        fgColor={fgColor}
        className={styles.qrcode}
      />
    </div>
  );
}

// QR Code with label
QRCode.WithLabel = function QRCodeWithLabel({
  value,
  label,
  size = 200,
  onClick,
  className = '',
}) {
  return (
    <div className={`${styles.labeled} ${className}`}>
      <QRCode
        value={value}
        size={size}
        onClick={onClick}
      />
      {label && <p className={styles.label}>{label}</p>}
    </div>
  );
};
