/**
 * Tabs Module
 * Handle tab navigation
 */

/**
 * Switch to a specific tab
 */
export function switchTab(tabName) {
  // Special handling for AI consultation - open fullscreen popup
  if (tabName === 'ai-consultation') {
    openKioskPopup();
    return;
  }

  // Hide all tab contents
  document.querySelectorAll('.tab-content').forEach(content => {
    content.classList.add('hidden');
  });

  // Remove active class from all tabs
  document.querySelectorAll('[id^="tab-"]').forEach(tab => {
    tab.classList.remove('tab-active');
    tab.classList.add('hover:bg-gray-100');
  });

  // Show selected tab content
  const contentEl = document.getElementById(`${tabName}-content`);
  if (contentEl) {
    contentEl.classList.remove('hidden');
  }

  // Add active class to selected tab
  const activeTab = document.getElementById(`tab-${tabName}`);
  if (activeTab) {
    activeTab.classList.add('tab-active');
    activeTab.classList.remove('hover:bg-gray-100');
  }
}

/**
 * Open fullscreen AI consultation (kiosk mode)
 */
export function openKioskPopup() {
  const popup = document.getElementById('kiosk-popup');
  if (popup) {
    popup.classList.remove('hidden');
    // Initialize kiosk mode if function exists
    if (typeof window.initKioskMode === 'function') {
      window.initKioskMode();
    }
  }
}

/**
 * Close fullscreen AI consultation popup
 */
export function closeKioskPopup() {
  const popup = document.getElementById('kiosk-popup');
  if (popup) {
    popup.classList.add('hidden');
  }
}

/**
 * Initialize tab click handlers
 */
export function initTabs() {
  // Make functions available globally for onclick handlers
  window.switchTab = switchTab;
  window.openKioskPopup = openKioskPopup;
  window.closeKioskPopup = closeKioskPopup;
}

export default {
  switchTab,
  openKioskPopup,
  closeKioskPopup,
  initTabs,
};
