/**
 * Admin dashboard API client
 * ------------------------------------------------------------
 * Thin wrapper around fetch that centralises the four things every call in the
 * dashboard needs: the API base URL, the Bearer token, the current salon
 * scope, and consistent error handling.
 *
 * The frontend and the API live on different origins (GitHub Pages vs.
 * clouedo.com), so the session token travels in an Authorization header from
 * localStorage rather than in a cookie -- the same arrangement login.html and
 * index.html already use.
 */

export const API_BASE_URL = window.API_BASE_URL || 'https://clouedo.com/coiffure/api';

const TOKEN_KEY = 'session_token';
const USER_KEY = 'user_data';

/** Raised for any non-2xx response, carrying the parsed body. */
export class ApiError extends Error {
    constructor(message, status, body) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.body = body || {};
        this.details = (body && body.details) || {};
    }

    /** True when the failure is the server rejecting the caller's rights. */
    get isForbidden() {
        return this.status === 403;
    }

    get isUnauthorized() {
        return this.status === 401;
    }
}

export function getToken() {
    return localStorage.getItem(TOKEN_KEY);
}

export function getStoredUser() {
    try {
        return JSON.parse(localStorage.getItem(USER_KEY) || 'null');
    } catch {
        return null;
    }
}

export function clearSession() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
}

export function redirectToLogin() {
    clearSession();
    window.location.replace('login.html');
}

/**
 * The salon the dashboard is currently scoped to. Set by app.js when the user
 * picks one in the top bar; every request carries it so the server can scope
 * the query. The server never trusts it blindly -- resolveSalonScope() checks
 * the caller may actually reach that salon.
 */
let currentSalonId = null;

export function setSalonScope(salonId) {
    currentSalonId = salonId;
}

export function getSalonScope() {
    return currentSalonId;
}

/**
 * Perform an API request.
 *
 * @param {string} endpoint e.g. 'me.php' or 'insights.php?action=list'
 * @param {object} options
 *   method, body (auto-JSON unless FormData), headers,
 *   salonScope: false to omit the automatic salon_id parameter,
 *   raw: true to get the Response instead of parsed JSON (CSV export)
 */
export async function apiRequest(endpoint, options = {}) {
    const {
        method = 'GET',
        body,
        headers = {},
        salonScope = true,
        raw = false,
        signal,
    } = options;

    let url = `${API_BASE_URL}/${endpoint.replace(/^\//, '')}`;

    // Attach the salon scope unless the caller already set one explicitly.
    if (salonScope && currentSalonId !== null && !/[?&]salon_id=/.test(url)) {
        url += (url.includes('?') ? '&' : '?') + `salon_id=${encodeURIComponent(currentSalonId)}`;
    }

    const token = getToken();
    const requestHeaders = { ...headers };

    if (token) {
        requestHeaders['Authorization'] = `Bearer ${token}`;
    }

    let payload = body;
    if (body !== undefined && !(body instanceof FormData)) {
        requestHeaders['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }

    let response;
    try {
        response = await fetch(url, { method, headers: requestHeaders, body: payload, signal });
    } catch (error) {
        if (error.name === 'AbortError') throw error;
        // Network-level failure: no response at all.
        throw new ApiError(tr('admin.errors.network', 'Verbindung zum Server fehlgeschlagen.'), 0, {});
    }

    if (response.status === 401) {
        // The session expired or was revoked -- there is nothing useful the
        // current view can do, so bounce to the login page.
        redirectToLogin();
        throw new ApiError('Unauthorized', 401, {});
    }

    if (raw) {
        if (!response.ok) {
            throw new ApiError(`Request failed (${response.status})`, response.status, {});
        }
        return response;
    }

    let data = {};
    const text = await response.text();
    if (text) {
        try {
            data = JSON.parse(text);
        } catch {
            throw new ApiError(
                tr('admin.errors.bad_response', 'Unerwartete Antwort vom Server.'),
                response.status,
                { raw: text.slice(0, 500) }
            );
        }
    }

    if (!response.ok || data.success === false) {
        throw new ApiError(
            data.error || `Request failed (${response.status})`,
            response.status,
            data
        );
    }

    return data;
}

export const apiGet = (endpoint, options) =>
    apiRequest(endpoint, { ...options, method: 'GET' });

export const apiPost = (endpoint, body, options) =>
    apiRequest(endpoint, { ...options, method: 'POST', body });

export const apiPut = (endpoint, body, options) =>
    apiRequest(endpoint, { ...options, method: 'PUT', body });

export const apiDelete = (endpoint, options) =>
    apiRequest(endpoint, { ...options, method: 'DELETE' });

/**
 * Download a response as a file. Used by the CSV export, which returns
 * text/csv rather than JSON.
 */
export async function apiDownload(endpoint, filename, options = {}) {
    const response = await apiRequest(endpoint, { ...options, raw: true });
    const blob = await response.blob();

    // Prefer the server's filename when it sent one.
    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="?([^";]+)"?/);
    const name = match ? match[1] : filename;

    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

/**
 * Translate with a literal fallback.
 *
 * api.js can be imported before lang/de.json has finished loading (the very
 * first request races i18n.init), so it never assumes a translation exists.
 */
function tr(key, fallback) {
    if (typeof i18n !== 'undefined' && i18n.translations && Object.keys(i18n.translations).length) {
        const value = i18n.t(key);
        if (value !== key) return value;
    }
    return fallback;
}
