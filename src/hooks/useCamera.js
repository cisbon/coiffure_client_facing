/**
 * useCamera Hook
 * Manages camera access for photo capture
 */

import { useState, useRef, useCallback, useEffect } from 'react';
import { IMAGE_CONSTRAINTS } from '../config/constants';

/**
 * Hook for camera access and photo capture
 */
export function useCamera() {
  const videoRef = useRef(null);
  const canvasRef = useRef(null);
  const [stream, setStream] = useState(null);
  const [error, setError] = useState(null);
  const [isActive, setIsActive] = useState(false);
  const [facingMode, setFacingMode] = useState('user');

  /**
   * Start the camera
   */
  const startCamera = useCallback(async (mode = 'user') => {
    setError(null);
    setFacingMode(mode);

    try {
      // Stop any existing stream
      if (stream) {
        stream.getTracks().forEach(track => track.stop());
      }

      const mediaStream = await navigator.mediaDevices.getUserMedia({
        video: {
          facingMode: mode,
          width: { ideal: 1280 },
          height: { ideal: 720 },
        },
        audio: false,
      });

      if (videoRef.current) {
        videoRef.current.srcObject = mediaStream;
      }

      setStream(mediaStream);
      setIsActive(true);
      return true;
    } catch (err) {
      let errorMessage = 'Camera access denied. Please enable camera permissions.';

      if (err.name === 'NotAllowedError') {
        errorMessage = 'Camera permission denied. Please allow camera access in your browser settings.';
      } else if (err.name === 'NotFoundError') {
        errorMessage = 'No camera found on this device.';
      } else if (err.name === 'NotReadableError') {
        errorMessage = 'Camera is in use by another application.';
      }

      setError(errorMessage);
      setIsActive(false);
      return false;
    }
  }, [stream]);

  /**
   * Stop the camera
   */
  const stopCamera = useCallback(() => {
    if (stream) {
      stream.getTracks().forEach(track => track.stop());
      setStream(null);
    }
    setIsActive(false);

    if (videoRef.current) {
      videoRef.current.srcObject = null;
    }
  }, [stream]);

  /**
   * Capture photo from video stream
   * @returns {string|null} Base64 image data URL
   */
  const capturePhoto = useCallback(() => {
    if (!videoRef.current || !isActive) return null;

    const video = videoRef.current;
    const canvas = canvasRef.current || document.createElement('canvas');

    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;

    const ctx = canvas.getContext('2d');

    // Mirror image if using front camera
    if (facingMode === 'user') {
      ctx.translate(canvas.width, 0);
      ctx.scale(-1, 1);
    }

    ctx.drawImage(video, 0, 0);

    // Convert to JPEG with compression
    return canvas.toDataURL('image/jpeg', IMAGE_CONSTRAINTS.COMPRESSION_QUALITY);
  }, [isActive, facingMode]);

  /**
   * Toggle between front and back camera
   */
  const toggleCamera = useCallback(async () => {
    const newMode = facingMode === 'user' ? 'environment' : 'user';
    await startCamera(newMode);
  }, [facingMode, startCamera]);

  /**
   * Cleanup on unmount
   */
  useEffect(() => {
    return () => {
      if (stream) {
        stream.getTracks().forEach(track => track.stop());
      }
    };
  }, [stream]);

  return {
    videoRef,
    canvasRef,
    isActive,
    error,
    facingMode,
    startCamera,
    stopCamera,
    capturePhoto,
    toggleCamera,
  };
}

export default useCamera;
