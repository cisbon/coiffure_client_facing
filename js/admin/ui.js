/**
 * Shared UI primitives for the admin dashboard
 * ------------------------------------------------------------
 * Every view builds its markup from these, so sorting, pagination, validation,
 * empty states and the mobile card fallback behave identically everywhere and
 * are fixed in one place.
 *
 * All user-visible text goes through t(), and everything interpolated into
 * HTML goes through esc() -- customer names and salon names are user input.
 */

/* ============================================================
   Translation + escaping
   ============================================================ */

/**
 * Translate a key. Thin wrapper over the tablet app's global i18n object so
 * the dashboard reuses the same translation files and language switching.
 */
export function t(key, params) {
    if (typeof i18n === 'undefined' || !i18n.translations) return key;
    return i18n.t(key, params || {});
}

/** Escape text for interpolation into an HTML string. */
export function esc(value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/** Build an element from an HTML string. */
export function el(html) {
    const template = document.createElement('template');
    template.innerHTML = html.trim();
    return template.content.firstElementChild;
}

/* ============================================================
   Formatting
   ============================================================ */

function locale() {
    return (typeof i18n !== 'undefined' && i18n.currentLang === 'en') ? 'en-GB' : 'de-DE';
}

export function formatDate(value, options) {
    if (!value) return '—';
    const date = value instanceof Date ? value : new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleDateString(locale(), options || { day: '2-digit', month: '2-digit', year: 'numeric' });
}

export function formatDateTime(value) {
    if (!value) return '—';
    const date = value instanceof Date ? value : new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleString(locale(), {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

/** "vor 3 Tagen" / "3 days ago" for notification and audit timestamps. */
export function formatRelative(value) {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return '—';

    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const units = [
        ['year', 31536000], ['month', 2592000], ['week', 604800],
        ['day', 86400], ['hour', 3600], ['minute', 60],
    ];
    const rtf = new Intl.RelativeTimeFormat(locale(), { numeric: 'auto' });

    for (const [unit, secondsPerUnit] of units) {
        if (Math.abs(seconds) >= secondsPerUnit) {
            return rtf.format(Math.round(seconds / secondsPerUnit), unit);
        }
    }
    return rtf.format(Math.round(seconds), 'second');
}

export function formatNumber(value) {
    const number = Number(value);
    return Number.isFinite(number) ? number.toLocaleString(locale()) : '—';
}

export function formatMoney(value, currency = 'EUR') {
    const number = Number(value);
    if (!Number.isFinite(number)) return '—';
    return number.toLocaleString(locale(), { style: 'currency', currency });
}

/** Initials for an avatar, e.g. "Anna Schmidt" -> "AS". */
export function initials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

/** Debounce, used by the table search boxes. */
export function debounce(fn, wait = 250) {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), wait);
    };
}

/* ============================================================
   Toasts
   ============================================================ */

function toastStack() {
    let stack = document.getElementById('toast-stack');
    if (!stack) {
        stack = el('<div class="toast-stack" id="toast-stack" role="status" aria-live="polite"></div>');
        document.body.appendChild(stack);
    }
    return stack;
}

const TOAST_ICONS = { success: '✓', error: '✕', warning: '!', info: 'ℹ' };

export function toast(message, type = 'info', duration = 4500) {
    const node = el(`
        <div class="toast toast-${esc(type)}">
            <span class="toast-icon">${esc(TOAST_ICONS[type] || TOAST_ICONS.info)}</span>
            <span class="toast-text">${esc(message)}</span>
            <button type="button" class="toast-close" aria-label="${esc(t('admin.common.close'))}">✕</button>
        </div>
    `);

    const dismiss = () => {
        node.classList.add('leaving');
        setTimeout(() => node.remove(), 180);
    };

    node.querySelector('.toast-close').addEventListener('click', dismiss);
    toastStack().appendChild(node);

    if (duration > 0) setTimeout(dismiss, duration);
    return dismiss;
}

export const toastSuccess = (message) => toast(message, 'success');
export const toastError = (message) => toast(message, 'error', 7000);

/**
 * Report a failed API call. Permission failures get a specific message so the
 * user understands it is a rights problem, not a bug.
 */
export function toastApiError(error) {
    if (error?.name === 'AbortError') return;
    if (error?.isForbidden) {
        toastError(t('admin.errors.forbidden'));
        return;
    }
    toastError(error?.message || t('admin.errors.generic'));
}

/* ============================================================
   Modal
   ============================================================ */

let openLayers = [];

function pushLayer(overlay, onClose) {
    openLayers.push({ overlay, onClose });
    document.body.style.overflow = 'hidden';
}

function popLayer(overlay) {
    openLayers = openLayers.filter((layer) => layer.overlay !== overlay);
    overlay.remove();
    if (!openLayers.length) document.body.style.overflow = '';
}

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape' || !openLayers.length) return;
    const top = openLayers[openLayers.length - 1];
    top.onClose();
});

/**
 * Open a modal.
 *
 * @param {object} config
 *   title, subtitle, body (Element|string), size ('', 'lg', 'xl'),
 *   actions: [{ label, variant, onClick(close), type, id }]
 *   onClose, dismissible (default true)
 * @returns {{close: Function, element: HTMLElement}}
 */
export function modal({ title, subtitle, body, size = '', actions = [], onClose, dismissible = true }) {
    const overlay = el('<div class="overlay" role="dialog" aria-modal="true"></div>');
    const box = el(`
        <div class="modal ${size ? `modal-${esc(size)}` : ''}">
            <div class="modal-header">
                <div class="modal-header-text">
                    <h2 class="modal-title">${esc(title || '')}</h2>
                    ${subtitle ? `<p class="modal-subtitle">${esc(subtitle)}</p>` : ''}
                </div>
                ${dismissible ? `<button type="button" class="modal-close" aria-label="${esc(t('admin.common.close'))}">✕</button>` : ''}
            </div>
            <div class="modal-body"></div>
        </div>
    `);

    const bodyNode = box.querySelector('.modal-body');
    if (body instanceof Element) bodyNode.appendChild(body);
    else if (body) bodyNode.innerHTML = body;

    const close = () => {
        popLayer(overlay);
        if (onClose) onClose();
    };

    if (actions.length) {
        const footer = el('<div class="modal-footer"></div>');
        actions.forEach((action) => {
            const button = el(
                `<button type="${esc(action.type || 'button')}" class="btn btn-${esc(action.variant || 'secondary')}">${esc(action.label)}</button>`
            );
            if (action.id) button.id = action.id;
            button.addEventListener('click', () => action.onClick?.(close, button));
            footer.appendChild(button);
        });
        box.appendChild(footer);
    }

    if (dismissible) {
        box.querySelector('.modal-close')?.addEventListener('click', close);
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) close();
        });
    }

    overlay.appendChild(box);
    document.body.appendChild(overlay);
    pushLayer(overlay, dismissible ? close : () => {});

    // Focus the first sensible control so keyboard users land inside.
    setTimeout(() => {
        const target = box.querySelector('input:not([type=hidden]), select, textarea, .btn-primary');
        target?.focus();
    }, 50);

    return { close, element: box };
}

/**
 * Confirmation dialog for destructive or irreversible actions
 * (delete, suspend, send campaign).
 *
 * @returns {Promise<boolean>} true when the user confirms
 */
export function confirmDialog({ title, message, confirmLabel, cancelLabel, variant = 'danger' }) {
    return new Promise((resolve) => {
        let settled = false;
        const settle = (value) => {
            if (settled) return;
            settled = true;
            resolve(value);
        };

        modal({
            title: title || t('admin.common.confirm_title'),
            body: `<p class="text-muted">${esc(message || '')}</p>`,
            onClose: () => settle(false),
            actions: [
                {
                    label: cancelLabel || t('admin.common.cancel'),
                    variant: 'secondary',
                    onClick: (close) => { settle(false); close(); },
                },
                {
                    label: confirmLabel || t('admin.common.confirm'),
                    variant,
                    onClick: (close) => { settle(true); close(); },
                },
            ],
        });
    });
}

/* ============================================================
   Drawer (slide-over panel)
   ============================================================ */

export function drawer({ title, subtitle, body, actions = [], onClose }) {
    const overlay = el('<div class="overlay drawer-overlay" role="dialog" aria-modal="true"></div>');
    const panel = el(`
        <div class="drawer">
            <div class="drawer-header">
                <div class="modal-header-text">
                    <h2 class="modal-title">${esc(title || '')}</h2>
                    ${subtitle ? `<p class="modal-subtitle">${esc(subtitle)}</p>` : ''}
                </div>
                <button type="button" class="modal-close" aria-label="${esc(t('admin.common.close'))}">✕</button>
            </div>
            <div class="drawer-body"></div>
        </div>
    `);

    const bodyNode = panel.querySelector('.drawer-body');
    if (body instanceof Element) bodyNode.appendChild(body);
    else if (body) bodyNode.innerHTML = body;

    const close = () => {
        popLayer(overlay);
        if (onClose) onClose();
    };

    if (actions.length) {
        const footer = el('<div class="drawer-footer"></div>');
        actions.forEach((action) => {
            const button = el(
                `<button type="button" class="btn btn-${esc(action.variant || 'secondary')}">${esc(action.label)}</button>`
            );
            button.addEventListener('click', () => action.onClick?.(close, button));
            footer.appendChild(button);
        });
        panel.appendChild(footer);
    }

    panel.querySelector('.modal-close').addEventListener('click', close);
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) close();
    });

    overlay.appendChild(panel);
    document.body.appendChild(overlay);
    pushLayer(overlay, close);

    return { close, element: panel, body: bodyNode };
}

/* ============================================================
   Empty state
   ============================================================ */

/**
 * Friendly empty state with an illustration and a call to action.
 * The artwork is inline SVG -- the repo ships no image assets and the page
 * must stay self-contained.
 */
export function emptyState({ art = 'people', title, text, action }) {
    const node = el(`
        <div class="empty">
            ${EMPTY_ART[art] || EMPTY_ART.people}
            <div>
                <div class="empty-title">${esc(title || '')}</div>
                ${text ? `<p class="empty-text mt-2">${esc(text)}</p>` : ''}
            </div>
        </div>
    `);

    if (action) {
        const button = el(`<button type="button" class="btn btn-primary">${esc(action.label)}</button>`);
        button.addEventListener('click', action.onClick);
        node.appendChild(button);
    }

    return node;
}

const EMPTY_ART = {
    people: `<svg class="empty-art" viewBox="0 0 120 120" fill="none" aria-hidden="true">
        <circle cx="60" cy="60" r="56" fill="var(--brand-50)"/>
        <circle cx="47" cy="48" r="15" fill="var(--brand-200)"/>
        <path d="M22 92c0-14 11-25 25-25s25 11 25 25" fill="var(--brand-200)"/>
        <circle cx="79" cy="55" r="12" fill="var(--brand-400)"/>
        <path d="M59 92c0-11 9-20 20-20s20 9 20 20" fill="var(--brand-400)"/>
    </svg>`,
    mail: `<svg class="empty-art" viewBox="0 0 120 120" fill="none" aria-hidden="true">
        <circle cx="60" cy="60" r="56" fill="var(--brand-50)"/>
        <rect x="30" y="42" width="60" height="40" rx="6" fill="var(--brand-200)"/>
        <path d="M30 48l30 21 30-21" stroke="var(--brand-500)" stroke-width="4" fill="none" stroke-linecap="round"/>
    </svg>`,
    chart: `<svg class="empty-art" viewBox="0 0 120 120" fill="none" aria-hidden="true">
        <circle cx="60" cy="60" r="56" fill="var(--brand-50)"/>
        <rect x="34" y="62" width="12" height="26" rx="3" fill="var(--brand-200)"/>
        <rect x="54" y="48" width="12" height="40" rx="3" fill="var(--brand-400)"/>
        <rect x="74" y="36" width="12" height="52" rx="3" fill="var(--brand-600)"/>
    </svg>`,
    search: `<svg class="empty-art" viewBox="0 0 120 120" fill="none" aria-hidden="true">
        <circle cx="60" cy="60" r="56" fill="var(--brand-50)"/>
        <circle cx="55" cy="55" r="18" stroke="var(--brand-400)" stroke-width="5" fill="none"/>
        <path d="M68 68l14 14" stroke="var(--brand-600)" stroke-width="6" stroke-linecap="round"/>
    </svg>`,
    shop: `<svg class="empty-art" viewBox="0 0 120 120" fill="none" aria-hidden="true">
        <circle cx="60" cy="60" r="56" fill="var(--brand-50)"/>
        <rect x="34" y="50" width="52" height="38" rx="5" fill="var(--brand-200)"/>
        <path d="M48 50V42a12 12 0 0124 0v8" stroke="var(--brand-500)" stroke-width="4" fill="none"/>
    </svg>`,
    lock: `<svg class="empty-art" viewBox="0 0 120 120" fill="none" aria-hidden="true">
        <circle cx="60" cy="60" r="56" fill="var(--slate-100)"/>
        <rect x="40" y="56" width="40" height="32" rx="6" fill="var(--slate-300)"/>
        <path d="M49 56v-9a11 11 0 0122 0v9" stroke="var(--slate-500)" stroke-width="4" fill="none"/>
    </svg>`,
};

/* ============================================================
   Loading placeholders
   ============================================================ */

export function skeletonRows(count = 5) {
    return el(`<div class="card-pad">${'<div class="skeleton skeleton-row"></div>'.repeat(count)}</div>`);
}

export function skeletonCards(count = 4) {
    return el(
        `<div class="grid grid-4">${'<div class="skeleton skeleton-card"></div>'.repeat(count)}</div>`
    );
}

/* ============================================================
   Forms
   ============================================================ */

/**
 * Show validation errors inline and as a summary above the form, which is what
 * the spec asks for. Clears any previous errors first.
 *
 * @param {HTMLFormElement} form
 * @param {object} errors field name -> message
 * @param {string} summaryTitle
 */
export function showFormErrors(form, errors, summaryTitle) {
    clearFormErrors(form);

    const entries = Object.entries(errors || {});
    if (!entries.length) return;

    const messages = [];

    entries.forEach(([field, message]) => {
        const input = form.elements[field];
        const control = input instanceof RadioNodeList ? input[0] : input;
        if (control) {
            control.classList.add('is-invalid');
            control.setAttribute('aria-invalid', 'true');

            const wrapper = control.closest('.field');
            if (wrapper) {
                wrapper.appendChild(el(`<p class="field-error" data-form-error>⚠ ${esc(message)}</p>`));
            }
        }
        messages.push(message);
    });

    const summary = el(`
        <div class="form-summary" data-form-summary role="alert" tabindex="-1">
            <span aria-hidden="true">⚠</span>
            <div>
                <strong>${esc(summaryTitle || t('admin.errors.form_summary'))}</strong>
                <ul>${messages.map((m) => `<li>${esc(m)}</li>`).join('')}</ul>
            </div>
        </div>
    `);
    form.prepend(summary);
    summary.focus();
    summary.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

export function clearFormErrors(form) {
    form.querySelectorAll('[data-form-error]').forEach((node) => node.remove());
    form.querySelectorAll('[data-form-summary]').forEach((node) => node.remove());
    form.querySelectorAll('.is-invalid').forEach((node) => {
        node.classList.remove('is-invalid');
        node.removeAttribute('aria-invalid');
    });
}

/** Read a form into a plain object, trimming strings. */
export function formValues(form) {
    const values = {};
    new FormData(form).forEach((value, key) => {
        if (key in values) {
            if (!Array.isArray(values[key])) values[key] = [values[key]];
            values[key].push(typeof value === 'string' ? value.trim() : value);
        } else {
            values[key] = typeof value === 'string' ? value.trim() : value;
        }
    });
    // FormData omits unchecked boxes; surface them as false so callers can
    // distinguish "off" from "absent".
    form.querySelectorAll('input[type=checkbox][name]').forEach((box) => {
        if (!box.checked && !(box.name in values)) values[box.name] = false;
        else if (box.checked && values[box.name] === 'on') values[box.name] = true;
    });
    return values;
}

/**
 * Put a button into a loading state and return a reset function, so a
 * double-click cannot fire the same save twice.
 */
export function buttonBusy(button, label) {
    if (!button) return () => {};
    const original = button.innerHTML;
    button.disabled = true;
    button.innerHTML = `<span class="spinner"></span>${label ? ` ${esc(label)}` : ''}`;
    return () => {
        button.disabled = false;
        button.innerHTML = original;
    };
}

/* ============================================================
   Badges
   ============================================================ */

const STATUS_VARIANTS = {
    active: 'success', trial: 'info', suspended: 'danger',
    sent: 'success', draft: 'neutral', scheduled: 'info',
    sending: 'warning', cancelled: 'neutral', failed: 'danger',
    open: 'warning', paid: 'success', overdue: 'danger',
    pending: 'warning', accepted: 'success', expired: 'neutral', revoked: 'neutral',
};

export function statusBadge(status, labelKey) {
    const variant = STATUS_VARIANTS[status] || 'neutral';
    const label = labelKey ? t(labelKey) : t(`admin.status.${status}`);
    return `<span class="badge badge-${variant}"><span class="badge-dot"></span>${esc(label)}</span>`;
}

export function boolBadge(value, trueKey = 'admin.common.yes', falseKey = 'admin.common.no') {
    return value
        ? `<span class="badge badge-success">${esc(t(trueKey))}</span>`
        : `<span class="badge badge-neutral">${esc(t(falseKey))}</span>`;
}

/* ============================================================
   Table
   ============================================================ */

/**
 * A sortable, searchable, paginated table that collapses to cards on small
 * screens (the same rows, rendered differently by CSS -- both are always in the
 * DOM so no JS runs on resize).
 *
 * @param {object} config
 *   columns: [{ key, labelKey|label, sortable, sortValue(row), render(row) -> html,
 *               className, cardHidden, primary }]
 *   rows, searchKeys, pageSize, rowClick(row), searchPlaceholderKey,
 *   toolbar: Element appended to the toolbar, emptyState config,
 *   defaultSort: { key, dir }
 * @returns {{ element, setRows, getFiltered }}
 */
export function createTable(config) {
    const {
        columns,
        rows = [],
        searchKeys = [],
        pageSize = 25,
        rowClick,
        searchPlaceholderKey = 'admin.common.search',
        toolbar,
        empty,
        defaultSort,
    } = config;

    let allRows = rows;
    let query = '';
    let sortKey = defaultSort?.key || null;
    let sortDir = defaultSort?.dir || 'asc';
    let page = 1;

    const root = el('<div class="card"></div>');

    const toolbarNode = el(`
        <div class="table-toolbar">
            ${searchKeys.length ? `
                <div class="table-search">
                    <span class="table-search-icon" aria-hidden="true">🔍</span>
                    <input type="search" class="input" data-table-search
                           placeholder="${esc(t(searchPlaceholderKey))}"
                           aria-label="${esc(t(searchPlaceholderKey))}">
                </div>` : ''}
        </div>
    `);
    if (toolbar) toolbarNode.appendChild(toolbar);
    if (searchKeys.length || toolbar) root.appendChild(toolbarNode);

    const content = el('<div data-table-content></div>');
    root.appendChild(content);

    const searchInput = toolbarNode.querySelector('[data-table-search]');
    searchInput?.addEventListener('input', debounce((event) => {
        query = event.target.value.trim().toLowerCase();
        page = 1;
        render();
    }, 200));

    function filtered() {
        let result = allRows;

        if (query && searchKeys.length) {
            result = result.filter((row) =>
                searchKeys.some((key) => String(row[key] ?? '').toLowerCase().includes(query))
            );
        }

        if (sortKey) {
            const column = columns.find((c) => c.key === sortKey);
            const valueOf = column?.sortValue || ((row) => row[sortKey]);
            // Copy before sorting: allRows belongs to the caller.
            result = [...result].sort((a, b) => {
                const av = valueOf(a);
                const bv = valueOf(b);
                if (av === bv) return 0;
                if (av === null || av === undefined || av === '') return 1;
                if (bv === null || bv === undefined || bv === '') return -1;

                const comparison = (typeof av === 'number' && typeof bv === 'number')
                    ? av - bv
                    : String(av).localeCompare(String(bv), locale(), { numeric: true, sensitivity: 'base' });

                return sortDir === 'asc' ? comparison : -comparison;
            });
        }

        return result;
    }

    function columnLabel(column) {
        return column.labelKey ? t(column.labelKey) : (column.label || '');
    }

    function render() {
        const data = filtered();
        content.innerHTML = '';

        if (!data.length) {
            const isSearching = Boolean(query);
            content.appendChild(emptyState(isSearching
                ? {
                    art: 'search',
                    title: t('admin.common.no_results'),
                    text: t('admin.common.no_results_hint'),
                }
                : (empty || { art: 'people', title: t('admin.common.empty') })));
            return;
        }

        const totalPages = Math.max(1, Math.ceil(data.length / pageSize));
        if (page > totalPages) page = totalPages;
        const pageRows = data.slice((page - 1) * pageSize, page * pageSize);

        // --- Desktop table ---
        const head = columns.map((column) => {
            const sorted = sortKey === column.key;
            const arrow = sorted ? (sortDir === 'asc' ? '▲' : '▼') : '↕';
            return `<th class="${column.sortable ? 'sortable' : ''} ${sorted ? 'sorted' : ''} ${esc(column.className || '')}"
                        ${column.sortable ? `data-sort="${esc(column.key)}" role="button" tabindex="0"` : ''}>
                        ${esc(columnLabel(column))}${column.sortable ? `<span class="sort-arrow">${arrow}</span>` : ''}
                    </th>`;
        }).join('');

        const bodyRows = pageRows.map((row, index) => {
            const cells = columns.map((column) =>
                `<td class="${esc(column.className || '')}">${column.render ? column.render(row) : esc(row[column.key] ?? '—')}</td>`
            ).join('');
            return `<tr class="${rowClick ? 'clickable' : ''}" data-row="${index}">${cells}</tr>`;
        }).join('');

        content.appendChild(el(`
            <div class="table-wrap">
                <table class="table"><thead><tr>${head}</tr></thead><tbody>${bodyRows}</tbody></table>
            </div>
        `));

        // --- Mobile cards (same rows) ---
        const primaryColumn = columns.find((c) => c.primary) || columns[0];
        const cards = pageRows.map((row, index) => {
            const detail = columns
                .filter((c) => c !== primaryColumn && !c.cardHidden && c.key !== 'actions')
                .map((column) => `
                    <div class="tcard-row">
                        <span class="tcard-key">${esc(columnLabel(column))}</span>
                        <span class="tcard-val">${column.render ? column.render(row) : esc(row[column.key] ?? '—')}</span>
                    </div>`)
                .join('');

            const actionsColumn = columns.find((c) => c.key === 'actions');
            const actions = actionsColumn
                ? `<div class="tcard-actions">${actionsColumn.render(row)}</div>`
                : '';

            return `
                <div class="tcard ${rowClick ? 'clickable' : ''}" data-row="${index}">
                    <div class="tcard-title">${primaryColumn.render ? primaryColumn.render(row) : esc(row[primaryColumn.key] ?? '')}</div>
                    ${detail}
                    ${actions}
                </div>`;
        }).join('');

        content.appendChild(el(`<div class="table-cards">${cards}</div>`));

        // --- Pagination ---
        if (data.length > pageSize) {
            content.appendChild(buildPagination(data.length, totalPages));
        } else {
            content.appendChild(el(`
                <div class="table-pagination">
                    <span>${esc(t('admin.common.showing_all', { count: data.length }))}</span>
                </div>
            `));
        }

        wireEvents(pageRows);
    }

    function buildPagination(total, totalPages) {
        const from = (page - 1) * pageSize + 1;
        const to = Math.min(page * pageSize, total);

        // Window the page buttons so 200 pages do not render 200 buttons.
        const numbers = [];
        const push = (n) => { if (!numbers.includes(n) && n >= 1 && n <= totalPages) numbers.push(n); };
        push(1);
        for (let n = page - 1; n <= page + 1; n += 1) push(n);
        push(totalPages);
        numbers.sort((a, b) => a - b);

        let buttons = '';
        let previous = 0;
        numbers.forEach((n) => {
            if (n - previous > 1) buttons += '<span class="text-subtle">…</span>';
            buttons += `<button type="button" class="page-btn ${n === page ? 'active' : ''}" data-page="${n}">${n}</button>`;
            previous = n;
        });

        const node = el(`
            <div class="table-pagination">
                <span>${esc(t('admin.common.showing_range', { from, to, total }))}</span>
                <span class="spacer"></span>
                <button type="button" class="page-btn" data-page="${page - 1}" ${page === 1 ? 'disabled' : ''}>‹</button>
                ${buttons}
                <button type="button" class="page-btn" data-page="${page + 1}" ${page === totalPages ? 'disabled' : ''}>›</button>
            </div>
        `);

        node.querySelectorAll('[data-page]').forEach((button) => {
            button.addEventListener('click', () => {
                const target = Number(button.dataset.page);
                if (target >= 1 && target <= totalPages && target !== page) {
                    page = target;
                    render();
                    root.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            });
        });

        return node;
    }

    function wireEvents(pageRows) {
        content.querySelectorAll('th[data-sort]').forEach((header) => {
            const activate = () => {
                const key = header.dataset.sort;
                if (sortKey === key) {
                    sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    sortKey = key;
                    sortDir = 'asc';
                }
                render();
            };
            header.addEventListener('click', activate);
            header.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    activate();
                }
            });
        });

        if (!rowClick) return;

        content.querySelectorAll('[data-row]').forEach((node) => {
            node.addEventListener('click', (event) => {
                // Let buttons and links inside a row do their own thing.
                if (event.target.closest('button, a, input, select')) return;
                rowClick(pageRows[Number(node.dataset.row)]);
            });
        });
    }

    render();

    return {
        element: root,
        setRows(next) {
            allRows = next;
            page = 1;
            render();
        },
        getFiltered: filtered,
        refresh: render,
    };
}

/* ============================================================
   Charts
   ============================================================ */

/** Read a CSS custom property so charts follow the salon accent. */
export function cssVar(name, fallback = '') {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return value || fallback;
}

/**
 * Shared Chart.js defaults so every chart in the dashboard reads as one
 * system: no chart junk, muted gridlines, tabular tooltips.
 */
export function chartDefaults() {
    const gridColor = cssVar('--slate-200', '#e2e8f0');
    const textColor = cssVar('--slate-500', '#64748b');

    return {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                display: true,
                position: 'top',
                align: 'end',
                labels: {
                    boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle',
                    color: textColor, font: { family: cssVar('--font'), size: 12 },
                },
            },
            tooltip: {
                backgroundColor: cssVar('--slate-900', '#0f172a'),
                padding: 12,
                cornerRadius: 8,
                titleFont: { family: cssVar('--font'), size: 12, weight: '600' },
                bodyFont: { family: cssVar('--font'), size: 12 },
                displayColors: true,
                boxWidth: 8, boxHeight: 8, usePointStyle: true,
            },
        },
        scales: {
            x: {
                grid: { display: false },
                border: { color: gridColor },
                ticks: { color: textColor, font: { family: cssVar('--font'), size: 11 }, maxRotation: 0, autoSkipPadding: 16 },
            },
            y: {
                beginAtZero: true,
                grid: { color: gridColor, drawTicks: false },
                border: { display: false },
                ticks: { color: textColor, font: { family: cssVar('--font'), size: 11 }, precision: 0, padding: 8 },
            },
        },
    };
}

/** Deep-merge chart options over the shared defaults. */
export function mergeChartOptions(overrides = {}) {
    const merge = (base, extra) => {
        const result = { ...base };
        Object.entries(extra).forEach(([key, value]) => {
            result[key] = (value && typeof value === 'object' && !Array.isArray(value) && base[key])
                ? merge(base[key], value)
                : value;
        });
        return result;
    };
    return merge(chartDefaults(), overrides);
}

/**
 * Draw a tiny sparkline into a canvas without Chart.js -- KPI cards render a
 * dozen of these and a full chart instance each would be wasteful.
 */
export function sparkline(canvas, values, color) {
    if (!canvas || !values || values.length < 2) return;

    const ratio = window.devicePixelRatio || 1;
    const width = canvas.clientWidth || 120;
    const height = canvas.clientHeight || 30;

    canvas.width = width * ratio;
    canvas.height = height * ratio;

    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    ctx.scale(ratio, ratio);
    ctx.clearRect(0, 0, width, height);

    const max = Math.max(...values);
    const min = Math.min(...values);
    const span = max - min || 1;
    const stepX = width / (values.length - 1);
    const pointAt = (value, index) => [
        index * stepX,
        height - 2 - ((value - min) / span) * (height - 4),
    ];

    const stroke = color || cssVar('--brand-500', '#3b82f6');

    // Soft fill under the line.
    ctx.beginPath();
    values.forEach((value, index) => {
        const [x, y] = pointAt(value, index);
        if (index === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
    });
    ctx.lineTo(width, height);
    ctx.lineTo(0, height);
    ctx.closePath();
    ctx.fillStyle = hexToRgba(stroke, 0.12);
    ctx.fill();

    ctx.beginPath();
    values.forEach((value, index) => {
        const [x, y] = pointAt(value, index);
        if (index === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
    });
    ctx.strokeStyle = stroke;
    ctx.lineWidth = 2;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';
    ctx.stroke();
}

function hexToRgba(hex, alpha) {
    const clean = String(hex).replace('#', '').trim();
    if (clean.length !== 6) return `rgba(59, 130, 246, ${alpha})`;
    const r = parseInt(clean.slice(0, 2), 16);
    const g = parseInt(clean.slice(2, 4), 16);
    const b = parseInt(clean.slice(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

/* ============================================================
   Page scaffolding
   ============================================================ */

/** Standard page header with a title, optional subtitle and action buttons. */
export function pageHeader({ title, subtitle, actions = [] }) {
    const node = el(`
        <div class="page-header">
            <div class="page-header-text">
                <h1 class="page-title">${esc(title)}</h1>
                ${subtitle ? `<p class="page-subtitle">${esc(subtitle)}</p>` : ''}
            </div>
        </div>
    `);

    if (actions.length) {
        const box = el('<div class="page-actions"></div>');
        actions.forEach((action) => {
            const button = el(
                `<button type="button" class="btn btn-${esc(action.variant || 'secondary')}">${action.icon ? `${esc(action.icon)} ` : ''}${esc(action.label)}</button>`
            );
            button.addEventListener('click', () => action.onClick(button));
            box.appendChild(button);
        });
        node.appendChild(box);
    }

    return node;
}

/**
 * Screen shown when a route exists but the user may not open it -- reachable by
 * typing a hash directly. The API returns 403 for the same request; this is the
 * friendly version.
 */
export function forbiddenState() {
    return emptyState({
        art: 'lock',
        title: t('admin.errors.no_access_title'),
        text: t('admin.errors.no_access_text'),
    });
}

/** Sub-tab bar used inside views (campaigns, settings, audit). */
export function subTabs(tabs, activeId, onSelect) {
    const node = el('<div class="subtabs" role="tablist"></div>');
    tabs.forEach((tab) => {
        const button = el(
            `<button type="button" role="tab" class="subtab ${tab.id === activeId ? 'active' : ''}"
                     aria-selected="${tab.id === activeId}">${esc(tab.label)}</button>`
        );
        button.addEventListener('click', () => onSelect(tab.id));
        node.appendChild(button);
    });
    return node;
}
