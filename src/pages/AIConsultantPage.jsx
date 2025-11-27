/**
 * AIConsultantPage
 * AI-powered hairstyle consultation with photo capture
 */

import { useState, useCallback } from 'react';
import { useLanguage } from '../context/LanguageContext';
import { useSalon } from '../context/SalonContext';
import { useCamera } from '../hooks/useCamera';
import { generateHairstyle, buildStylePrompt } from '../services/aiService';
import { processDataUrlForUpload, validateImageFile, fileToBase64 } from '../utils/imageUtils';

import Button from '../components/common/Button';
import Spinner from '../components/common/Spinner';

import styles from './AIConsultantPage.module.css';

// Steps in the kiosk flow
const STEPS = {
  PHOTO: 'photo',
  STYLE: 'style',
  RESULT: 'result',
};

// Style categories
const STYLE_CATEGORIES = [
  { id: 'business', icon: '💼' },
  { id: 'casual', icon: '👔' },
  { id: 'trendy', icon: '✨' },
  { id: 'classic', icon: '👑' },
  { id: 'edgy', icon: '🎸' },
  { id: 'natural', icon: '🌿' },
];

// Style modes
const STYLE_MODES = {
  QUICK: 'quick',
  DESCRIBE: 'describe',
  HAIRCUTS: 'haircuts',
  CUSTOM: 'custom',
};

// Haircuts
const WOMEN_HAIRCUTS = ['pixie', 'bob', 'lob', 'shag', 'layers', 'butterfly', 'blunt', 'updo', 'wolf'];
const MEN_HAIRCUTS = ['buzz', 'crew', 'fade', 'undercut', 'pompadour', 'quiff', 'side_part', 'mullet', 'man_bun'];

export default function AIConsultantPage() {
  const { t } = useLanguage();
  const { salonId } = useSalon();
  const camera = useCamera();

  // State
  const [currentStep, setCurrentStep] = useState(STEPS.PHOTO);
  const [capturedImage, setCapturedImage] = useState(null);
  const [styleMode, setStyleMode] = useState(STYLE_MODES.QUICK);
  const [selectedGender, setSelectedGender] = useState('women');
  const [customDescription, setCustomDescription] = useState('');
  const [isGenerating, setIsGenerating] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState(null);

  // Handle camera open
  const handleOpenCamera = async () => {
    await camera.startCamera('user');
  };

  // Handle photo capture
  const handleCapture = () => {
    const photo = camera.capturePhoto();
    if (photo) {
      setCapturedImage(photo);
      camera.stopCamera();
    }
  };

  // Handle file upload
  const handleFileUpload = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;

    const validation = validateImageFile(file);
    if (!validation.valid) {
      setError(validation.error);
      return;
    }

    const dataUrl = await fileToBase64(file);
    setCapturedImage(dataUrl);
  };

  // Handle retake
  const handleRetake = () => {
    setCapturedImage(null);
    setError(null);
  };

  // Continue to style selection
  const handleContinueToStyle = () => {
    setCurrentStep(STEPS.STYLE);
  };

  // Handle style selection
  const handleSelectStyle = async (styleId) => {
    await generateWithPrompt(buildStylePrompt({ styleCategory: styleId }));
  };

  // Handle custom description submit
  const handleDescriptionSubmit = async () => {
    if (!customDescription.trim()) return;
    await generateWithPrompt(customDescription);
  };

  // Handle haircut selection
  const handleHaircutSelect = async (haircut) => {
    await generateWithPrompt(buildStylePrompt({ haircut }));
  };

  // Generate AI hairstyle
  const generateWithPrompt = useCallback(async (prompt) => {
    if (!capturedImage) return;

    setIsGenerating(true);
    setError(null);

    try {
      const imageBase64 = await processDataUrlForUpload(capturedImage);
      const response = await generateHairstyle(imageBase64, prompt, salonId);

      if (response.success) {
        setResult({
          image: response.generated_image,
          recommendation: response.recommendation,
        });
        setCurrentStep(STEPS.RESULT);
      } else {
        throw new Error(response.error || t('errors.ai_generation'));
      }
    } catch (err) {
      setError(err.message || t('errors.ai_generation'));
    } finally {
      setIsGenerating(false);
    }
  }, [capturedImage, salonId, t]);

  // Handle try different style
  const handleTryDifferent = () => {
    setResult(null);
    setCurrentStep(STEPS.STYLE);
  };

  // Handle start over
  const handleStartOver = () => {
    setCapturedImage(null);
    setResult(null);
    setCustomDescription('');
    setError(null);
    setCurrentStep(STEPS.PHOTO);
  };

  // Render progress indicator
  const renderProgress = () => (
    <div className={styles.progress}>
      {Object.values(STEPS).map((step, index) => (
        <div key={step} className={styles.progressStep}>
          {index > 0 && (
            <div className={`${styles.progressLine} ${
              Object.values(STEPS).indexOf(currentStep) > index - 1 ? styles.completed : ''
            }`} />
          )}
          <div className={`${styles.progressDot} ${
            currentStep === step ? styles.active : ''
          } ${
            Object.values(STEPS).indexOf(currentStep) > index ? styles.completed : ''
          }`}>
            {index + 1}
          </div>
          <span className={styles.progressLabel}>
            {t(`kiosk.step_${step}`)}
          </span>
        </div>
      ))}
    </div>
  );

  // Render photo step
  const renderPhotoStep = () => (
    <div className={styles.stepContent}>
      <div className={styles.stepHeader}>
        <span className={styles.stepEmoji}>📸</span>
        <h2 className={styles.stepTitle}>{t('kiosk.photo_title')}</h2>
        <p className={styles.stepSubtitle}>{t('kiosk.photo_subtitle')}</p>
      </div>

      {!camera.isActive && !capturedImage && (
        <div className={styles.photoOptions}>
          <Button
            variant="primary"
            size="large"
            fullWidth
            onClick={handleOpenCamera}
            icon={<span>📷</span>}
          >
            {t('kiosk.take_photo')}
          </Button>

          <label className={styles.uploadButton}>
            <input
              type="file"
              accept="image/*"
              onChange={handleFileUpload}
              className={styles.fileInput}
            />
            <Button
              variant="secondary"
              size="large"
              fullWidth
              icon={<span>📤</span>}
              as="span"
            >
              {t('kiosk.upload_photo')}
            </Button>
          </label>
        </div>
      )}

      {camera.isActive && (
        <div className={styles.cameraContainer}>
          <video
            ref={camera.videoRef}
            autoPlay
            playsInline
            muted
            className={styles.cameraVideo}
          />
          <div className={styles.cameraActions}>
            <Button variant="primary" size="large" onClick={handleCapture}>
              {t('kiosk.capture')}
            </Button>
            <Button variant="secondary" onClick={() => camera.stopCamera()}>
              {t('kiosk.cancel')}
            </Button>
          </div>
        </div>
      )}

      {capturedImage && (
        <div className={styles.previewContainer}>
          <div className={styles.photoPreview}>
            <img src={capturedImage} alt="Captured" />
          </div>
          <div className={styles.previewActions}>
            <Button variant="secondary" onClick={handleRetake}>
              {t('kiosk.retake')}
            </Button>
            <Button variant="primary" size="large" onClick={handleContinueToStyle}>
              {t('kiosk.continue')}
            </Button>
          </div>
        </div>
      )}

      {camera.error && (
        <div className={styles.error}>{camera.error}</div>
      )}
    </div>
  );

  // Render style step
  const renderStyleStep = () => (
    <div className={styles.stepContent}>
      <div className={styles.stepHeader}>
        <span className={styles.stepEmoji}>✨</span>
        <h2 className={styles.stepTitle}>{t('kiosk.style_title')}</h2>
      </div>

      {/* Mode selector */}
      <div className={styles.modeSelector}>
        {Object.values(STYLE_MODES).map((mode) => (
          <button
            key={mode}
            className={`${styles.modeButton} ${styleMode === mode ? styles.active : ''}`}
            onClick={() => setStyleMode(mode)}
          >
            {t(`kiosk.mode_${mode}`)}
          </button>
        ))}
      </div>

      {/* Quick styles */}
      {styleMode === STYLE_MODES.QUICK && (
        <div className={styles.styleGrid}>
          {STYLE_CATEGORIES.map((style) => (
            <button
              key={style.id}
              className={styles.styleCard}
              onClick={() => handleSelectStyle(style.id)}
            >
              <span className={styles.styleIcon}>{style.icon}</span>
              <span className={styles.styleName}>{t(`kiosk.style_${style.id}`)}</span>
              <span className={styles.styleDesc}>{t(`kiosk.style_${style.id}_desc`)}</span>
            </button>
          ))}
        </div>
      )}

      {/* Describe */}
      {styleMode === STYLE_MODES.DESCRIBE && (
        <div className={styles.describeMode}>
          <p className={styles.describeHint}>{t('kiosk.describe_hint')}</p>
          <textarea
            value={customDescription}
            onChange={(e) => setCustomDescription(e.target.value)}
            placeholder={t('kiosk.describe_placeholder')}
            className={styles.describeInput}
            rows={4}
          />
          <Button
            variant="primary"
            size="large"
            fullWidth
            onClick={handleDescriptionSubmit}
            disabled={!customDescription.trim()}
          >
            {t('kiosk.generate')}
          </Button>
        </div>
      )}

      {/* Haircuts */}
      {styleMode === STYLE_MODES.HAIRCUTS && (
        <div className={styles.haircutsMode}>
          <div className={styles.genderToggle}>
            <button
              className={`${styles.genderButton} ${selectedGender === 'women' ? styles.active : ''}`}
              onClick={() => setSelectedGender('women')}
            >
              {t('kiosk.haircuts_women')}
            </button>
            <button
              className={`${styles.genderButton} ${selectedGender === 'men' ? styles.active : ''}`}
              onClick={() => setSelectedGender('men')}
            >
              {t('kiosk.haircuts_men')}
            </button>
          </div>

          <div className={styles.haircutGrid}>
            {(selectedGender === 'women' ? WOMEN_HAIRCUTS : MEN_HAIRCUTS).map((cut) => (
              <button
                key={cut}
                className={styles.haircutButton}
                onClick={() => handleHaircutSelect(cut)}
              >
                {t(`kiosk.haircut_${cut}`)}
              </button>
            ))}
          </div>
        </div>
      )}

      {/* Back button */}
      <div className={styles.backAction}>
        <Button variant="ghost" onClick={() => setCurrentStep(STEPS.PHOTO)}>
          {t('kiosk.back')}
        </Button>
      </div>
    </div>
  );

  // Render result step
  const renderResultStep = () => (
    <div className={styles.stepContent}>
      <div className={styles.stepHeader}>
        <span className={styles.stepEmoji}>🎉</span>
        <h2 className={styles.stepTitle}>{t('kiosk.results_title')}</h2>
      </div>

      {result && (
        <div className={styles.resultContent}>
          <div className={styles.resultImage}>
            <img
              src={`data:image/jpeg;base64,${result.image}`}
              alt="Generated hairstyle"
            />
          </div>

          {result.recommendation && (
            <div className={styles.recommendation}>
              <h3>{t('kiosk.results_recommendation')}</h3>
              <p>{result.recommendation}</p>
            </div>
          )}

          <div className={styles.resultActions}>
            <Button variant="secondary" onClick={handleTryDifferent}>
              {t('kiosk.try_different')}
            </Button>
            <Button variant="primary" onClick={handleStartOver}>
              {t('kiosk.start_over')}
            </Button>
          </div>
        </div>
      )}
    </div>
  );

  // Render loading state
  if (isGenerating) {
    return (
      <div className={styles.fullscreen}>
        <div className={styles.generatingContainer}>
          <Spinner size="large" />
          <h2>{t('kiosk.generating_title')}</h2>
          <p>{t('kiosk.generating_subtitle')}</p>
        </div>
      </div>
    );
  }

  return (
    <div className={styles.fullscreen}>
      {/* Close button */}
      <button className={styles.closeButton} onClick={handleStartOver}>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>

      {/* Progress */}
      {renderProgress()}

      {/* Content */}
      <div className={styles.content}>
        {currentStep === STEPS.PHOTO && renderPhotoStep()}
        {currentStep === STEPS.STYLE && renderStyleStep()}
        {currentStep === STEPS.RESULT && renderResultStep()}
      </div>

      {/* Error display */}
      {error && (
        <div className={styles.errorOverlay}>
          <div className={styles.errorBox}>
            <p>{error}</p>
            <Button variant="primary" onClick={() => setError(null)}>
              {t('common.retry')}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
