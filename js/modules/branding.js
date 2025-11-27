/**
 * Branding Module
 * Handles salon branding (colors, logo, name)
 */

import { API_BASE_URL } from '../config.js';
import { apiGet } from '../api.js';
import { getUserSalonId, setUserSalonId } from './auth.js';
import { DEFAULT_COLORS } from '../config.js';

/**
 * Fetch user's salon assignments and load branding
 */
export async function loadUserSalonAndBranding() {
  console.log('════════════════════════════════════════════════════');
  console.log('🔍 FETCHING SALON ASSIGNMENTS');
  console.log('════════════════════════════════════════════════════');

  try {
    const data = await apiGet('/salon-management.php');
    console.log('Salon assignments response:', data);

    if (data.success && data.salons && data.salons.length > 0) {
      const salon = data.salons[0];
      setUserSalonId(salon.salon_id);

      console.log('✅ User assigned to salon:', salon.salon_name);

      // Load salon's default language
      const salonLanguage = salon.default_language || 'de';
      if (typeof i18n !== 'undefined') {
        await i18n.loadLanguage(salonLanguage);
        i18n.applyTranslations();
      }

      // Load and apply branding
      await loadSalonBranding(salon.salon_id);
    } else {
      console.warn('⚠️ No salon assignments found, using default');
      setUserSalonId(1);
      await loadSalonBranding(1);
    }
  } catch (error) {
    console.error('❌ Error fetching salon assignments:', error);
    setUserSalonId(1);
    await loadSalonBranding(1);
  }
}

/**
 * Load branding for a specific salon
 */
export async function loadSalonBranding(salonId) {
  console.log('════════════════════════════════════════════════════');
  console.log('🎨 LOADING SALON BRANDING for salon:', salonId);
  console.log('════════════════════════════════════════════════════');

  if (!salonId) {
    console.error('❌ No salon ID provided');
    return;
  }

  try {
    const data = await apiGet('/salon-branding.php', { salon_id: salonId });
    console.log('Branding response:', data);

    if (data.success && data.branding) {
      console.log('✅ Branding loaded successfully');
      applySalonBranding(data.branding);
    } else {
      console.warn('⚠️ Failed to load branding, using defaults');
    }
  } catch (error) {
    console.error('❌ Error loading branding:', error);
  }
}

/**
 * Apply salon branding to the page
 */
export function applySalonBranding(branding) {
  console.log('✨ APPLYING SALON BRANDING');

  const root = document.documentElement;

  // Set CSS variables
  if (branding.primary_color) {
    root.style.setProperty('--color-primary', branding.primary_color);
  }
  if (branding.secondary_color) {
    root.style.setProperty('--color-secondary', branding.secondary_color);
  }
  if (branding.background_color) {
    root.style.setProperty('--color-background', branding.background_color);
    document.body.style.setProperty('background-color', branding.background_color, 'important');
  }
  if (branding.button_color) {
    root.style.setProperty('--color-button', branding.button_color);
  }
  if (branding.text_color) {
    root.style.setProperty('--color-text', branding.text_color);
  }

  // Inject dynamic CSS
  injectBrandingCSS(branding);

  // Apply salon name
  if (branding.salon_name) {
    const titleEl = document.getElementById('salon-title');
    if (titleEl) {
      titleEl.textContent = branding.salon_name;
      titleEl.style.color = branding.primary_color || DEFAULT_COLORS.primary;
    }
  }

  // Apply logo
  if (branding.logo_url) {
    let fullLogoUrl = branding.logo_url;
    if (!fullLogoUrl.startsWith('http')) {
      fullLogoUrl = 'https://clouedo.com/coiffure/' + branding.logo_url;
    }

    document.querySelectorAll('.salon-logo').forEach(logoEl => {
      logoEl.src = fullLogoUrl;
      logoEl.classList.remove('hidden');
    });
  }

  console.log('✅ BRANDING APPLICATION COMPLETE');
}

/**
 * Inject branding CSS overrides
 */
function injectBrandingCSS(branding) {
  let styleEl = document.getElementById('salon-branding-styles');
  if (!styleEl) {
    styleEl = document.createElement('style');
    styleEl.id = 'salon-branding-styles';
    document.head.appendChild(styleEl);
  }

  const buttonColor = branding.button_color || DEFAULT_COLORS.button;
  const secondaryColor = branding.secondary_color || branding.button_color || DEFAULT_COLORS.secondary;
  const hoverColor = adjustBrightness(buttonColor, -20);
  const backgroundColor = branding.background_color || DEFAULT_COLORS.background;
  const textColor = branding.text_color || DEFAULT_COLORS.text;

  styleEl.textContent = `
    /* Override Tailwind purple button classes */
    .bg-purple-600, .bg-purple-700,
    button.bg-purple-600, button.bg-purple-700 {
      background-color: ${buttonColor} !important;
    }
    .bg-purple-600:hover, .bg-purple-700:hover,
    button.bg-purple-600:hover, button.bg-purple-700:hover {
      background-color: ${hoverColor} !important;
    }

    /* Tab active gradient */
    .tab-active {
      background: linear-gradient(to right, ${buttonColor}, ${secondaryColor}) !important;
    }

    /* Submit buttons with gradients */
    .bg-gradient-to-r.from-purple-600,
    button.bg-gradient-to-r.from-purple-600,
    #onboarding-submit-btn, #ai-submit-btn, #wizard-generate-btn {
      background: linear-gradient(to right, ${buttonColor}, ${secondaryColor}) !important;
    }
    .bg-gradient-to-r.from-purple-600:hover,
    button.bg-gradient-to-r.from-purple-600:hover {
      background: linear-gradient(to right, ${hoverColor}, ${adjustBrightness(secondaryColor, -20)}) !important;
    }

    /* Camera/upload buttons */
    .bg-gray-600 { background-color: ${textColor} !important; }
    .bg-gray-600:hover { background-color: ${adjustBrightness(textColor, -20)} !important; }

    /* Body background */
    body { background-color: ${backgroundColor} !important; }

    /* Language buttons */
    #lang-de:hover, #lang-en:hover { background-color: ${buttonColor} !important; }
    #lang-de.bg-purple-600, #lang-en.bg-purple-600 { background-color: ${buttonColor} !important; }
  `;
}

/**
 * Adjust color brightness
 */
function adjustBrightness(hex, percent) {
  const num = parseInt(hex.replace('#', ''), 16);
  const amt = Math.round(2.55 * percent);
  const R = (num >> 16) + amt;
  const G = ((num >> 8) & 0x00ff) + amt;
  const B = (num & 0x0000ff) + amt;
  return '#' + (
    0x1000000 +
    (R < 255 ? (R < 1 ? 0 : R) : 255) * 0x10000 +
    (G < 255 ? (G < 1 ? 0 : G) : 255) * 0x100 +
    (B < 255 ? (B < 1 ? 0 : B) : 255)
  ).toString(16).slice(1);
}

export default {
  loadUserSalonAndBranding,
  loadSalonBranding,
  applySalonBranding,
};
