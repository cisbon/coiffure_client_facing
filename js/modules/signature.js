/**
 * Signature Canvas Module
 * Digital signature functionality
 */

let canvas = null;
let ctx = null;
let isDrawing = false;

/**
 * Initialize signature canvas
 */
export function initSignature() {
  canvas = document.getElementById('signature-canvas');
  if (!canvas) return;

  ctx = canvas.getContext('2d');
  resizeCanvas();

  // Event listeners for drawing
  canvas.addEventListener('mousedown', startDrawing);
  canvas.addEventListener('mousemove', draw);
  canvas.addEventListener('mouseup', stopDrawing);
  canvas.addEventListener('mouseout', stopDrawing);

  // Touch support
  canvas.addEventListener('touchstart', startDrawing);
  canvas.addEventListener('touchmove', draw);
  canvas.addEventListener('touchend', stopDrawing);

  // Resize handler
  window.addEventListener('resize', resizeCanvas);

  // Make clear function available globally
  window.clearSignature = clearSignature;
}

/**
 * Resize canvas to match container
 */
function resizeCanvas() {
  if (!canvas) return;
  const rect = canvas.getBoundingClientRect();
  canvas.width = rect.width;
  canvas.height = rect.height;
}

/**
 * Start drawing
 */
function startDrawing(e) {
  isDrawing = true;
  const rect = canvas.getBoundingClientRect();
  const x = (e.clientX || e.touches?.[0]?.clientX) - rect.left;
  const y = (e.clientY || e.touches?.[0]?.clientY) - rect.top;
  ctx.beginPath();
  ctx.moveTo(x, y);
}

/**
 * Draw on canvas
 */
function draw(e) {
  if (!isDrawing) return;
  e.preventDefault();

  const rect = canvas.getBoundingClientRect();
  const x = (e.clientX || e.touches?.[0]?.clientX) - rect.left;
  const y = (e.clientY || e.touches?.[0]?.clientY) - rect.top;

  ctx.lineWidth = 2;
  ctx.lineCap = 'round';
  ctx.strokeStyle = '#1F2937';
  ctx.lineTo(x, y);
  ctx.stroke();
}

/**
 * Stop drawing
 */
function stopDrawing() {
  isDrawing = false;
}

/**
 * Clear signature
 */
export function clearSignature() {
  if (!ctx || !canvas) return;
  ctx.clearRect(0, 0, canvas.width, canvas.height);
}

/**
 * Check if signature is empty
 */
export function isSignatureEmpty() {
  if (!canvas) return true;
  const blank = document.createElement('canvas');
  blank.width = canvas.width;
  blank.height = canvas.height;
  return canvas.toDataURL() === blank.toDataURL();
}

/**
 * Get signature as base64 data URL
 */
export function getSignatureData() {
  if (!canvas || isSignatureEmpty()) return null;
  return canvas.toDataURL('image/png');
}

export default {
  initSignature,
  clearSignature,
  isSignatureEmpty,
  getSignatureData,
};
