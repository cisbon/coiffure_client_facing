/**
 * AI Consultation Module
 * Hairstyle AI consultation with camera capture
 */

import { API_BASE_URL } from '../config.js';
import { apiPost } from '../api.js';
import { getUserSalonId } from './auth.js';

// State
let currentStep = 'photo';
let capturedImage = null;
let kioskStream = null;

// Style presets
const STYLE_PRESETS = {
  business: 'professional and polished business hairstyle, neat and sophisticated',
  casual: 'relaxed and comfortable casual hairstyle, easy to maintain',
  trendy: 'modern and fashionable trendy hairstyle, Instagram-worthy',
  classic: 'timeless and elegant classic hairstyle, sophisticated',
  edgy: 'bold and daring edgy hairstyle, unique and creative',
  natural: 'easy and effortless natural hairstyle, minimal styling',
};

// Haircuts
const HAIRCUTS = {
  women: ['pixie', 'bob', 'lob', 'shag', 'layers', 'butterfly', 'blunt', 'updo', 'wolf'],
  men: ['buzz', 'crew', 'fade', 'undercut', 'pompadour', 'quiff', 'side_part', 'mullet', 'man_bun'],
};

/**
 * Initialize kiosk mode
 */
export function initKioskMode() {
  resetKiosk();
  updateKioskProgress();

  // Make functions available globally for onclick handlers
  window.kioskOpenCamera = kioskOpenCamera;
  window.kioskCloseCamera = kioskCloseCamera;
  window.kioskCapturePhoto = kioskCapturePhoto;
  window.kioskRetakePhoto = kioskRetakePhoto;
  window.kioskGoToStyleSelection = kioskGoToStyleSelection;
  window.kioskSwitchStyleMode = kioskSwitchStyleMode;
  window.kioskSelectStyle = kioskSelectStyle;
  window.kioskSelectCustomDescription = kioskSelectCustomDescription;
  window.kioskGenerateCustomStyle = kioskGenerateCustomStyle;
  window.kioskSelectGender = kioskSelectGender;
  window.kioskHandleImageSelect = kioskHandleImageSelect;
  window.initKioskMode = initKioskMode;
}

/**
 * Reset kiosk state
 */
function resetKiosk() {
  currentStep = 'photo';
  capturedImage = null;

  // Stop any running camera
  if (kioskStream) {
    kioskStream.getTracks().forEach((track) => track.stop());
    kioskStream = null;
  }

  // Reset UI
  showKioskStep('photo');
  document.getElementById('kiosk-photo-options')?.classList.remove('hidden');
  document.getElementById('kiosk-camera-view')?.classList.add('hidden');
  document.getElementById('kiosk-photo-preview')?.classList.add('hidden');
}

/**
 * Update progress indicator
 */
function updateKioskProgress() {
  const steps = ['photo', 'style', 'result'];
  const currentIndex = steps.indexOf(currentStep);

  steps.forEach((step, index) => {
    const stepEl = document.getElementById(`progress-step-${index + 1}`);
    const lineEl = document.getElementById(`progress-line-${index}`);

    if (stepEl) {
      if (index < currentIndex) {
        stepEl.classList.remove('opacity-50');
        stepEl.querySelector('div').classList.add('bg-green-500');
        stepEl.querySelector('div').classList.remove('bg-purple-600', 'bg-gray-300');
      } else if (index === currentIndex) {
        stepEl.classList.remove('opacity-50');
        stepEl.querySelector('div').classList.add('bg-purple-600');
        stepEl.querySelector('div').classList.remove('bg-green-500', 'bg-gray-300');
      } else {
        stepEl.classList.add('opacity-50');
        stepEl.querySelector('div').classList.add('bg-gray-300');
        stepEl.querySelector('div').classList.remove('bg-purple-600', 'bg-green-500');
      }
    }

    if (lineEl && index > 0) {
      if (index <= currentIndex) {
        lineEl.classList.add('bg-purple-600');
        lineEl.classList.remove('bg-gray-300');
      } else {
        lineEl.classList.add('bg-gray-300');
        lineEl.classList.remove('bg-purple-600');
      }
    }
  });
}

/**
 * Show a specific kiosk step
 */
function showKioskStep(step) {
  currentStep = step;

  ['photo', 'style', 'result', 'loading'].forEach((s) => {
    const el = document.getElementById(`kiosk-step-${s}`);
    if (el) {
      el.classList.toggle('hidden', s !== step);
    }
  });

  updateKioskProgress();
}

/**
 * Open camera
 */
export async function kioskOpenCamera() {
  try {
    kioskStream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
      audio: false,
    });

    const video = document.getElementById('kiosk-camera-video');
    if (video) {
      video.srcObject = kioskStream;
    }

    document.getElementById('kiosk-photo-options')?.classList.add('hidden');
    document.getElementById('kiosk-camera-view')?.classList.remove('hidden');
  } catch (error) {
    console.error('Camera access error:', error);
    alert('Camera access denied. Please enable camera permissions.');
  }
}

/**
 * Close camera
 */
export function kioskCloseCamera() {
  if (kioskStream) {
    kioskStream.getTracks().forEach((track) => track.stop());
    kioskStream = null;
  }

  document.getElementById('kiosk-camera-view')?.classList.add('hidden');
  document.getElementById('kiosk-photo-options')?.classList.remove('hidden');
}

/**
 * Capture photo from camera
 */
export function kioskCapturePhoto() {
  const video = document.getElementById('kiosk-camera-video');
  const canvas = document.getElementById('kiosk-camera-canvas');

  if (!video || !canvas) return;

  canvas.width = video.videoWidth;
  canvas.height = video.videoHeight;

  const ctx = canvas.getContext('2d');
  // Mirror the image for front camera
  ctx.translate(canvas.width, 0);
  ctx.scale(-1, 1);
  ctx.drawImage(video, 0, 0);

  capturedImage = canvas.toDataURL('image/jpeg', 0.8);

  // Stop camera
  kioskCloseCamera();

  // Show preview
  const previewImg = document.getElementById('kiosk-preview-image');
  if (previewImg) {
    previewImg.src = capturedImage;
  }

  document.getElementById('kiosk-photo-preview')?.classList.remove('hidden');
}

/**
 * Handle image file selection
 */
export function kioskHandleImageSelect(event) {
  const file = event.target.files?.[0];
  if (!file) return;

  const reader = new FileReader();
  reader.onload = (e) => {
    capturedImage = e.target.result;

    const previewImg = document.getElementById('kiosk-preview-image');
    if (previewImg) {
      previewImg.src = capturedImage;
    }

    document.getElementById('kiosk-photo-options')?.classList.add('hidden');
    document.getElementById('kiosk-photo-preview')?.classList.remove('hidden');
  };
  reader.readAsDataURL(file);
}

/**
 * Retake photo
 */
export function kioskRetakePhoto() {
  capturedImage = null;
  document.getElementById('kiosk-photo-preview')?.classList.add('hidden');
  document.getElementById('kiosk-photo-options')?.classList.remove('hidden');
}

/**
 * Go to style selection
 */
export function kioskGoToStyleSelection() {
  if (!capturedImage) return;
  showKioskStep('style');
}

/**
 * Switch style mode (quick, describe, haircuts, custom)
 */
export function kioskSwitchStyleMode(mode) {
  const modes = ['quick', 'describe', 'haircuts', 'custom'];

  modes.forEach((m) => {
    const btn = document.getElementById(`kiosk-mode-${m}`);
    const content = document.getElementById(`kiosk-${m}-mode`);

    if (btn) {
      btn.classList.toggle('bg-purple-600', m === mode);
      btn.classList.toggle('text-white', m === mode);
      btn.classList.toggle('bg-gray-200', m !== mode);
      btn.classList.toggle('text-gray-700', m !== mode);
    }

    if (content) {
      content.classList.toggle('hidden', m !== mode);
    }
  });
}

/**
 * Select a quick style
 */
export async function kioskSelectStyle(styleName) {
  const prompt = STYLE_PRESETS[styleName] || styleName;
  await performAIConsultation(prompt);
}

/**
 * Select custom description
 */
export async function kioskSelectCustomDescription() {
  const textarea = document.getElementById('kiosk-style-description');
  const prompt = textarea?.value?.trim();

  if (!prompt) {
    alert('Please describe the hairstyle you want');
    return;
  }

  await performAIConsultation(prompt);
}

/**
 * Select haircut gender
 */
export function kioskSelectGender(gender) {
  ['women', 'men'].forEach((g) => {
    const btn = document.getElementById(`kiosk-gender-${g}`);
    const content = document.getElementById(`kiosk-${g}-haircuts`);

    if (btn) {
      btn.classList.toggle('bg-purple-600', g === gender);
      btn.classList.toggle('text-white', g === gender);
      btn.classList.toggle('bg-gray-200', g !== gender);
      btn.classList.toggle('text-gray-700', g !== gender);
    }

    if (content) {
      content.classList.toggle('hidden', g !== gender);
    }
  });
}

/**
 * Perform AI consultation
 */
async function performAIConsultation(stylePrompt) {
  if (!capturedImage) {
    alert('Please take a photo first');
    return;
  }

  // Show loading
  showKioskStep('loading');

  try {
    // Extract base64 data (remove data URL prefix)
    const base64Data = capturedImage.split(',')[1];

    const response = await apiPost('/ai-consultation.php', {
      image_base64: base64Data,
      style_prompt: stylePrompt,
      salon_id: getUserSalonId(),
    });

    if (response.success && response.generated_image) {
      // Show result
      const resultImg = document.getElementById('kiosk-result-image');
      if (resultImg) {
        resultImg.src = `data:image/jpeg;base64,${response.generated_image}`;
      }

      const recommendationEl = document.getElementById('kiosk-recommendation');
      if (recommendationEl && response.recommendation) {
        recommendationEl.textContent = response.recommendation;
        recommendationEl.parentElement?.classList.remove('hidden');
      }

      showKioskStep('result');
    } else {
      throw new Error(response.error || 'AI generation failed');
    }
  } catch (error) {
    console.error('AI consultation error:', error);
    alert('AI generation failed. Please try again.');
    showKioskStep('style');
  }
}

export default {
  initKioskMode,
  kioskOpenCamera,
  kioskCloseCamera,
  kioskCapturePhoto,
  kioskHandleImageSelect,
  kioskRetakePhoto,
  kioskGoToStyleSelection,
  kioskSwitchStyleMode,
  kioskSelectStyle,
  kioskSelectCustomDescription,
  kioskSelectGender,
};
