/**
 * Social Links Module
 * Display social media links with QR codes
 */

import { apiGet } from '../api.js';
import { getUserSalonId } from './auth.js';

// Social platform configurations
const SOCIAL_PLATFORMS = {
  instagram: { name: 'Instagram', icon: '📷', color: '#E1306C' },
  facebook: { name: 'Facebook', icon: '📘', color: '#1877F2' },
  tiktok: { name: 'TikTok', icon: '🎵', color: '#000000' },
  google_reviews: { name: 'Google Reviews', icon: '⭐', color: '#4285F4' },
  yelp: { name: 'Yelp', icon: '🔴', color: '#D32323' },
  twitter: { name: 'Twitter', icon: '🐦', color: '#1DA1F2' },
  youtube: { name: 'YouTube', icon: '▶️', color: '#FF0000' },
  linkedin: { name: 'LinkedIn', icon: '💼', color: '#0077B5' },
  custom: { name: 'Custom', icon: '🔗', color: '#6B7280' },
};

/**
 * Initialize social links section
 */
export function initSocialLinks() {
  loadSocialLinks();
}

/**
 * Load social links from API
 */
export async function loadSocialLinks() {
  const loadingEl = document.getElementById('social-loading');
  const gridEl = document.getElementById('social-links-grid');
  const noLinksEl = document.getElementById('social-no-links');

  if (!gridEl) return;

  // Show loading
  if (loadingEl) loadingEl.classList.remove('hidden');
  if (gridEl) gridEl.classList.add('hidden');
  if (noLinksEl) noLinksEl.classList.add('hidden');

  try {
    const salonId = getUserSalonId();
    const data = await apiGet('/social-links.php', {
      salon_id: salonId,
      include_inactive: false,
    });

    if (loadingEl) loadingEl.classList.add('hidden');

    if (data.success && data.links && data.links.length > 0) {
      renderSocialLinks(data.links);
      gridEl.classList.remove('hidden');
    } else {
      if (noLinksEl) noLinksEl.classList.remove('hidden');
    }
  } catch (error) {
    console.error('Error loading social links:', error);
    if (loadingEl) loadingEl.classList.add('hidden');
    if (noLinksEl) noLinksEl.classList.remove('hidden');
  }
}

/**
 * Render social links to grid
 */
function renderSocialLinks(links) {
  const gridEl = document.getElementById('social-links-grid');
  if (!gridEl) return;

  gridEl.innerHTML = links
    .map((link) => {
      const platform = SOCIAL_PLATFORMS[link.link_type] || SOCIAL_PLATFORMS.custom;
      return `
        <div class="social-link-card bg-white rounded-xl shadow-md p-6 text-center hover:shadow-lg transition-shadow cursor-pointer"
             onclick="showSocialQRModal('${escapeHtml(link.link_url)}', '${escapeHtml(link.display_name || platform.name)}')">
          <div class="text-4xl mb-3">${platform.icon}</div>
          <h3 class="font-semibold text-gray-800 mb-1">${escapeHtml(link.display_name || platform.name)}</h3>
          ${link.description ? `<p class="text-sm text-gray-500">${escapeHtml(link.description)}</p>` : ''}
          <div class="mt-4 text-xs text-purple-600" data-i18n="social.scan_to_visit">Scan to visit</div>
        </div>
      `;
    })
    .join('');

  // Make show modal function available globally
  window.showSocialQRModal = showSocialQRModal;
  window.closeSocialQRModal = closeSocialQRModal;
}

/**
 * Show QR code modal for a social link
 */
export function showSocialQRModal(url, name) {
  // Create modal if it doesn't exist
  let modal = document.getElementById('social-qr-modal');
  if (!modal) {
    modal = createQRModal();
    document.body.appendChild(modal);
  }

  // Update modal content
  const titleEl = modal.querySelector('.modal-title');
  const qrContainer = modal.querySelector('.qr-container');

  if (titleEl) titleEl.textContent = name;

  // Generate QR code
  if (qrContainer && typeof QRCode !== 'undefined') {
    qrContainer.innerHTML = '';
    new QRCode(qrContainer, {
      text: url,
      width: 256,
      height: 256,
      colorDark: '#000000',
      colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.H,
    });
  }

  modal.classList.remove('hidden');
}

/**
 * Close QR modal
 */
export function closeSocialQRModal() {
  const modal = document.getElementById('social-qr-modal');
  if (modal) {
    modal.classList.add('hidden');
  }
}

/**
 * Create QR code modal element
 */
function createQRModal() {
  const modal = document.createElement('div');
  modal.id = 'social-qr-modal';
  modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden';
  modal.onclick = (e) => {
    if (e.target === modal) closeSocialQRModal();
  };

  modal.innerHTML = `
    <div class="bg-white rounded-2xl p-8 max-w-sm w-full mx-4 text-center">
      <h3 class="modal-title text-xl font-bold text-gray-800 mb-6"></h3>
      <div class="qr-container flex justify-center mb-6"></div>
      <p class="text-gray-600 mb-4" data-i18n="social_qr_modal.description">
        Point your camera at the QR code to open the link
      </p>
      <button onclick="closeSocialQRModal()"
              class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors"
              data-i18n="social_qr_modal.close">
        Close
      </button>
    </div>
  `;

  return modal;
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(str) {
  if (!str) return '';
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

export default {
  initSocialLinks,
  loadSocialLinks,
  showSocialQRModal,
  closeSocialQRModal,
};
