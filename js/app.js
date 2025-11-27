/**
 * Main Application Entry Point
 * Initializes all modules and starts the app
 */

import { checkAuthentication } from './modules/auth.js';
import { loadUserSalonAndBranding } from './modules/branding.js';
import { initTabs } from './modules/tabs.js';
import { initSignature } from './modules/signature.js';
import { initOnboarding } from './modules/onboarding.js';
import { initSocialLinks } from './modules/social.js';
import { initKioskMode } from './modules/ai-consultation.js';

/**
 * Initialize the application
 */
async function initApp() {
  console.log('════════════════════════════════════════════════════');
  console.log('🚀 COIFFURE AI - INITIALIZING');
  console.log('════════════════════════════════════════════════════');

  // Check authentication (redirects to login if not authenticated)
  if (!checkAuthentication()) {
    return;
  }

  console.log('✓ Authentication verified');

  // Initialize tabs
  initTabs();
  console.log('✓ Tabs initialized');

  // Initialize signature canvas
  initSignature();
  console.log('✓ Signature canvas initialized');

  // Initialize onboarding form
  initOnboarding();
  console.log('✓ Onboarding form initialized');

  // Initialize social links
  initSocialLinks();
  console.log('✓ Social links initialized');

  // Make kiosk init available globally
  window.initKioskMode = initKioskMode;
  console.log('✓ AI consultation ready');

  // Load salon branding (this also loads the salon's language)
  await loadUserSalonAndBranding();
  console.log('✓ Salon branding loaded');

  // Update language buttons
  updateLanguageButtons();

  console.log('════════════════════════════════════════════════════');
  console.log('✅ COIFFURE AI - READY');
  console.log('════════════════════════════════════════════════════');
}

/**
 * Update language button states
 */
function updateLanguageButtons() {
  if (typeof i18n === 'undefined') return;

  const currentLang = i18n.getCurrentLanguage();
  document.querySelectorAll('[id^="lang-"]').forEach((btn) => {
    const btnLang = btn.id.replace('lang-', '');
    if (btnLang === currentLang) {
      btn.classList.add('lang-active');
      btn.classList.remove('lang-inactive');
    } else {
      btn.classList.remove('lang-active');
      btn.classList.add('lang-inactive');
    }
  });
}

/**
 * Language switching function (made global for onclick)
 */
window.switchLanguage = async function (lang) {
  if (typeof i18n !== 'undefined') {
    await i18n.setLanguage(lang);
    updateLanguageButtons();
  }
};

// Initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initApp);
} else {
  initApp();
}

export default { initApp };
