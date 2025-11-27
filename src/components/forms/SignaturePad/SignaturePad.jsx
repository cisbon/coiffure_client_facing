/**
 * SignaturePad Component
 * Digital signature canvas with touch support
 */

import { useRef, useEffect, useCallback, useState } from 'react';
import styles from './SignaturePad.module.css';
import Button from '../../common/Button';

export default function SignaturePad({
  onSignatureChange,
  label,
  error,
  clearLabel = 'Clear',
  className = '',
}) {
  const canvasRef = useRef(null);
  const [isDrawing, setIsDrawing] = useState(false);
  const [hasSignature, setHasSignature] = useState(false);

  // Initialize canvas
  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;

    const ctx = canvas.getContext('2d');

    // Set up high DPI canvas
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();

    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;

    ctx.scale(dpr, dpr);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#1F2937';
    ctx.lineWidth = 2;
  }, []);

  // Get canvas coordinates from event
  const getCoordinates = useCallback((e) => {
    const canvas = canvasRef.current;
    if (!canvas) return { x: 0, y: 0 };

    const rect = canvas.getBoundingClientRect();
    let x, y;

    if (e.touches && e.touches.length > 0) {
      x = e.touches[0].clientX - rect.left;
      y = e.touches[0].clientY - rect.top;
    } else {
      x = e.clientX - rect.left;
      y = e.clientY - rect.top;
    }

    return { x, y };
  }, []);

  // Start drawing
  const startDrawing = useCallback((e) => {
    e.preventDefault();
    const canvas = canvasRef.current;
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const { x, y } = getCoordinates(e);

    ctx.beginPath();
    ctx.moveTo(x, y);
    setIsDrawing(true);
  }, [getCoordinates]);

  // Draw
  const draw = useCallback((e) => {
    if (!isDrawing) return;
    e.preventDefault();

    const canvas = canvasRef.current;
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const { x, y } = getCoordinates(e);

    ctx.lineTo(x, y);
    ctx.stroke();
    setHasSignature(true);
  }, [isDrawing, getCoordinates]);

  // Stop drawing
  const stopDrawing = useCallback(() => {
    if (isDrawing) {
      setIsDrawing(false);

      // Export signature as base64
      const canvas = canvasRef.current;
      if (canvas && hasSignature && onSignatureChange) {
        const dataUrl = canvas.toDataURL('image/png');
        onSignatureChange(dataUrl);
      }
    }
  }, [isDrawing, hasSignature, onSignatureChange]);

  // Clear signature
  const clearSignature = useCallback(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    setHasSignature(false);

    if (onSignatureChange) {
      onSignatureChange(null);
    }
  }, [onSignatureChange]);

  return (
    <div className={`${styles.container} ${className}`}>
      {label && <label className={styles.label}>{label}</label>}

      <div className={`${styles.canvasContainer} ${error ? styles.error : ''}`}>
        <canvas
          ref={canvasRef}
          className={styles.canvas}
          onMouseDown={startDrawing}
          onMouseMove={draw}
          onMouseUp={stopDrawing}
          onMouseLeave={stopDrawing}
          onTouchStart={startDrawing}
          onTouchMove={draw}
          onTouchEnd={stopDrawing}
        />
      </div>

      <div className={styles.actions}>
        <Button
          type="button"
          variant="secondary"
          size="small"
          onClick={clearSignature}
          disabled={!hasSignature}
        >
          {clearLabel}
        </Button>
      </div>

      {error && <p className={styles.errorText}>{error}</p>}
    </div>
  );
}
