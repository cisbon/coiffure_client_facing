/**
 * AI Consultation Service
 * Handles AI hairstyle generation via Gemini API
 */

import { api } from './api';
import { ENDPOINTS } from '../config/api';

/**
 * Generate AI hairstyle preview
 * @param {string} imageBase64 - Base64 encoded image (without data URL prefix)
 * @param {string} stylePrompt - Description of desired hairstyle
 * @param {string} salonId - Salon ID for tracking
 * @returns {Promise<{success: boolean, generated_image: string, recommendation: string}>}
 */
export async function generateHairstyle(imageBase64, stylePrompt, salonId) {
  const response = await api.post(ENDPOINTS.AI_CONSULTATION, {
    image_base64: imageBase64,
    style_prompt: stylePrompt,
    salon_id: salonId,
  });

  return response;
}

/**
 * Build style prompt from selections
 */
export function buildStylePrompt(selections) {
  const parts = [];

  if (selections.styleCategory) {
    parts.push(getStyleCategoryDescription(selections.styleCategory));
  }

  if (selections.haircut) {
    parts.push(selections.haircut);
  }

  if (selections.length) {
    parts.push(`${selections.length} length`);
  }

  if (selections.texture) {
    parts.push(`${selections.texture} texture`);
  }

  if (selections.bangs && selections.bangs !== 'none') {
    parts.push(selections.bangs === 'yes' ? 'with full bangs' : 'with side-swept bangs');
  }

  if (selections.color && selections.color !== 'natural') {
    parts.push(selections.color === 'highlights' ? 'with highlights' : 'with bold color');
  }

  if (selections.customDescription) {
    parts.push(selections.customDescription);
  }

  return parts.join(', ') || 'modern stylish hairstyle';
}

/**
 * Get description for style category
 */
function getStyleCategoryDescription(category) {
  const descriptions = {
    business: 'professional and polished business hairstyle',
    casual: 'relaxed and comfortable casual hairstyle',
    trendy: 'modern and fashionable trendy hairstyle',
    classic: 'timeless and elegant classic hairstyle',
    edgy: 'bold and daring edgy hairstyle',
    natural: 'easy and effortless natural hairstyle',
  };

  return descriptions[category] || category;
}

export default {
  generateHairstyle,
  buildStylePrompt,
};
