/**
 * Einstellungen (salon settings)
 * ------------------------------------------------------------
 * Sub-tabbed settings screen. Three sections are migrated from the old
 * admin-dashboard.js and keep their existing endpoints:
 *
 *   Allgemein       salon-branding.php  (logo upload, five colours, guest WiFi)
 *   Treueprogramm   loyalty-config.php  (threshold, discount, staff PIN)
 *   Social & QR     social-links.php    (link CRUD + QR preview)
 *
 * The Tablet, Geburtstagskampagne and Öffnungszeiten sections join them once
 * api/salon-settings.php lands; the tab list below is where they slot in.
 */

import { API_BASE_URL, apiGet, apiPost, apiDelete, apiRequest, getToken } from '../api.js';
import {
    t, esc, el, modal, confirmDialog, pageHeader, subTabs, toastSuccess,
    toastError, toastApiError, showFormErrors, clearFormErrors, formValues,
    buttonBusy, skeletonRows, createTable, boolBadge,
} from '../ui.js';

const TABS = [
    { id: 'general', labelKey: 'admin.settings.tab_general' },
    { id: 'loyalty', labelKey: 'admin.settings.tab_loyalty' },
    { id: 'social', labelKey: 'admin.settings.tab_social' },
];

export async function render(container, ctx) {
    if (ctx.isAllSalons) {
        // Settings are per salon; the aggregated view has nothing to edit.
        container.appendChild(pageHeader({ title: t('admin.settings.title') }));
        const { emptyState } = await import('../ui.js');
        container.appendChild(emptyState({
            art: 'shop',
            title: t('admin.settings.pick_salon_title'),
            text: t('admin.settings.pick_salon_text'),
        }));
        return;
    }

    container.appendChild(pageHeader({
        title: t('admin.settings.title'),
        subtitle: ctx.salon ? ctx.salon.salon_name : '',
    }));

    const active = (window.location.hash.split('?')[1] || '').replace('tab=', '') || 'general';
    const body = el('<div></div>');

    container.appendChild(subTabs(
        TABS.map((tab) => ({ id: tab.id, label: t(tab.labelKey) })),
        active,
        (id) => { window.location.hash = `#/einstellungen?tab=${id}`; }
    ));
    container.appendChild(body);

    await renderSection(active, body, ctx);
}

async function renderSection(id, host, ctx) {
    host.innerHTML = '';
    host.appendChild(skeletonRows(6));

    try {
        if (id === 'loyalty') await renderLoyalty(host, ctx);
        else if (id === 'social') await renderSocial(host, ctx);
        else await renderGeneral(host, ctx);
    } catch (error) {
        host.innerHTML = '';
        toastApiError(error);
    }
}

/* ============================================================
   Allgemein: logo, colours, guest WiFi  (salon-branding.php)
   ============================================================ */

const DEFAULT_COLORS = {
    primary_color: '#9333EA',
    secondary_color: '#EC4899',
    background_color: '#FFFFFF',
    button_color: '#9333EA',
    text_color: '#1F2937',
};

const COLOR_FIELDS = [
    ['primary_color', 'admin.settings.color_primary', 'admin.settings.color_primary_hint'],
    ['secondary_color', 'admin.settings.color_secondary', 'admin.settings.color_secondary_hint'],
    ['background_color', 'admin.settings.color_background', 'admin.settings.color_background_hint'],
    ['button_color', 'admin.settings.color_button', 'admin.settings.color_button_hint'],
    ['text_color', 'admin.settings.color_text', 'admin.settings.color_text_hint'],
];

async function renderGeneral(host, ctx) {
    const data = await apiGet(`salon-branding.php?salon_id=${encodeURIComponent(ctx.salonId)}`, { salonScope: false });
    const branding = data.branding || {};

    /** A stored logo_path is relative to the API host, not to this page. */
    const logoUrl = branding.logo_path
        ? (/^https?:\/\//.test(branding.logo_path)
            ? branding.logo_path
            : `${API_BASE_URL.replace(/\/api\/?$/, '')}/${branding.logo_path.replace(/^\//, '')}`)
        : null;

    const form = el(`
        <form id="branding-form" novalidate>
            <div class="stack">
                <div class="card">
                    <div class="card-header"><h2>${esc(t('admin.settings.logo'))}</h2></div>
                    <div class="card-body">
                        <div class="row" style="gap:var(--sp-6);align-items:flex-start">
                            <div id="logo-preview"
                                 style="width:132px;height:132px;border:1px dashed var(--border-strong);border-radius:var(--r-lg);display:grid;place-items:center;background:var(--surface-2);overflow:hidden;flex-shrink:0">
                                ${logoUrl
                                    ? `<img src="${esc(logoUrl)}" alt="" style="width:100%;height:100%;object-fit:contain">`
                                    : `<span class="text-xs text-muted" style="text-align:center;padding:0 8px">${esc(t('admin.settings.no_logo'))}</span>`}
                            </div>
                            <div class="field" style="flex:1;min-width:240px">
                                <label class="field-label" for="logo">${esc(t('admin.settings.upload_logo'))}</label>
                                <input class="input" type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp,image/gif">
                                <span class="field-hint">${esc(t('admin.settings.logo_hint'))}</span>
                                ${logoUrl ? `<button type="button" class="btn btn-danger-soft btn-sm mt-2" id="remove-logo" style="align-self:flex-start">${esc(t('admin.settings.remove_logo'))}</button>` : ''}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2>${esc(t('admin.settings.colors'))}</h2>
                        <button type="button" class="btn btn-ghost btn-sm" id="reset-colors">${esc(t('admin.settings.reset_colors'))}</button>
                    </div>
                    <div class="card-body">
                        <div class="form-grid form-grid-2">
                            ${COLOR_FIELDS.map(([key, labelKey, hintKey]) => {
                                const value = branding[key] || DEFAULT_COLORS[key];
                                return `
                                <div class="field">
                                    <label class="field-label" for="${key}_hex">${esc(t(labelKey))}</label>
                                    <div class="color-field">
                                        <input type="color" id="${key}" value="${esc(value)}" aria-label="${esc(t(labelKey))}">
                                        <input class="input" type="text" id="${key}_hex" name="${key}"
                                               value="${esc(value)}" pattern="#[0-9a-fA-F]{6}" maxlength="7">
                                    </div>
                                    <span class="field-hint">${esc(t(hintKey))}</span>
                                </div>`;
                            }).join('')}
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2>${esc(t('admin.settings.wifi'))}</h2>
                    </div>
                    <div class="card-body">
                        <p class="card-hint mb-4">${esc(t('admin.settings.wifi_hint'))}</p>
                        <div class="form-grid form-grid-2">
                            <div class="field">
                                <label class="field-label" for="wifi_ssid">${esc(t('admin.settings.wifi_ssid'))}</label>
                                <input class="input" id="wifi_ssid" name="wifi_ssid" value="${esc(branding.wifi_ssid || '')}">
                            </div>
                            <div class="field">
                                <label class="field-label" for="wifi_password">${esc(t('admin.settings.wifi_password'))}</label>
                                <input class="input" id="wifi_password" name="wifi_password" value="${esc(branding.wifi_password || '')}">
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary">${esc(t('admin.common.save'))}</button>
                    </div>
                </div>
            </div>
        </form>
    `);

    host.innerHTML = '';
    host.appendChild(form);

    // Keep each colour picker and its hex box in step.
    COLOR_FIELDS.forEach(([key]) => {
        const picker = form.querySelector(`#${key}`);
        const hex = form.querySelector(`#${key}_hex`);
        picker.addEventListener('input', () => { hex.value = picker.value.toUpperCase(); });
        hex.addEventListener('input', () => {
            if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) picker.value = hex.value;
        });
    });

    form.querySelector('#reset-colors')?.addEventListener('click', () => {
        COLOR_FIELDS.forEach(([key]) => {
            form.querySelector(`#${key}`).value = DEFAULT_COLORS[key];
            form.querySelector(`#${key}_hex`).value = DEFAULT_COLORS[key];
        });
    });

    let removeLogo = false;
    form.querySelector('#remove-logo')?.addEventListener('click', (event) => {
        removeLogo = true;
        form.querySelector('#logo-preview').innerHTML =
            `<span class="text-xs text-muted">${esc(t('admin.settings.no_logo'))}</span>`;
        event.target.remove();
    });

    form.querySelector('#logo')?.addEventListener('change', (event) => {
        const file = event.target.files[0];
        if (!file) return;
        if (!file.type.startsWith('image/')) {
            toastError(t('admin.settings.logo_type_error'));
            event.target.value = '';
            return;
        }
        if (file.size > 2 * 1024 * 1024) {
            toastError(t('admin.settings.logo_size_error'));
            event.target.value = '';
            return;
        }
        removeLogo = false;
        const reader = new FileReader();
        reader.onload = (e) => {
            form.querySelector('#logo-preview').innerHTML =
                `<img src="${e.target.result}" alt="" style="width:100%;height:100%;object-fit:contain">`;
        };
        reader.readAsDataURL(file);
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFormErrors(form);

        const errors = {};
        COLOR_FIELDS.forEach(([key]) => {
            const value = form.querySelector(`#${key}_hex`).value;
            if (!/^#[0-9a-fA-F]{6}$/.test(value)) errors[key] = t('admin.validation.color');
        });
        if (Object.keys(errors).length) {
            showFormErrors(form, errors);
            return;
        }

        // salon-branding.php takes multipart because of the logo upload.
        const payload = new FormData();
        payload.append('salon_id', ctx.salonId);
        COLOR_FIELDS.forEach(([key]) => payload.append(key, form.querySelector(`#${key}_hex`).value));
        payload.append('wifi_ssid', form.elements.wifi_ssid.value.trim());
        payload.append('wifi_password', form.elements.wifi_password.value.trim());

        const file = form.querySelector('#logo').files[0];
        if (file) payload.append('logo', file);
        else if (removeLogo) payload.append('remove_logo', 'true');

        const button = form.querySelector('button[type=submit]');
        const reset = buttonBusy(button, t('admin.common.saving'));
        try {
            await apiRequest('salon-branding.php', { method: 'POST', body: payload, salonScope: false });
            toastSuccess(t('admin.settings.saved'));
            ctx.reload();
        } catch (error) {
            reset();
            toastApiError(error);
        }
    });
}

/* ============================================================
   Treueprogramm  (loyalty-config.php)
   ============================================================ */

async function renderLoyalty(host, ctx) {
    const data = await apiGet(`loyalty-config.php?salon_id=${encodeURIComponent(ctx.salonId)}`, { salonScope: false });
    const config = data.loyalty || data.config || data;

    const active = Number(config.loyalty_active ?? 1) === 1;
    const threshold = Number(config.visit_threshold ?? config.loyalty_visit_threshold ?? 5);
    const type = config.discount_type || config.loyalty_discount_type || 'fixed_eur';
    const value = Number(config.discount_value ?? config.loyalty_discount_value ?? 10);
    const label = config.discount_label || config.loyalty_discount_label || '';

    const form = el(`
        <form id="loyalty-form" novalidate>
            <div class="grid grid-2">
                <div class="card">
                    <div class="card-header"><h2>${esc(t('admin.settings.loyalty_title'))}</h2></div>
                    <div class="card-body">
                        <p class="card-hint mb-6">${esc(t('admin.settings.loyalty_hint'))}</p>

                        <label class="switch mb-6">
                            <input type="checkbox" name="loyalty_active" ${active ? 'checked' : ''}>
                            <span class="switch-track"></span>
                            <span class="switch-label">${esc(t('admin.settings.loyalty_active'))}</span>
                        </label>

                        <div class="form-grid" id="loyalty-fields">
                            <div class="field">
                                <label class="field-label" for="visit_threshold">${esc(t('admin.settings.loyalty_threshold'))}</label>
                                <input class="input" id="visit_threshold" name="visit_threshold" type="number"
                                       min="2" max="50" step="1" value="${threshold}">
                                <span class="field-hint">${esc(t('admin.settings.loyalty_threshold_hint'))}</span>
                            </div>
                            <div class="form-grid form-grid-2">
                                <div class="field">
                                    <label class="field-label" for="discount_type">${esc(t('admin.settings.loyalty_discount_type'))}</label>
                                    <select class="select" id="discount_type" name="discount_type">
                                        <option value="fixed_eur" ${type === 'fixed_eur' ? 'selected' : ''}>${esc(t('admin.settings.loyalty_fixed'))}</option>
                                        <option value="percentage" ${type === 'percentage' ? 'selected' : ''}>${esc(t('admin.settings.loyalty_percentage'))}</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label class="field-label" for="discount_value">${esc(t('admin.settings.loyalty_discount_value'))}</label>
                                    <input class="input" id="discount_value" name="discount_value" type="number"
                                           min="0.5" step="0.5" value="${value}">
                                </div>
                            </div>
                            <div class="field">
                                <label class="field-label" for="discount_label">${esc(t('admin.settings.loyalty_label'))}</label>
                                <input class="input" id="discount_label" name="discount_label" maxlength="50"
                                       value="${esc(label)}" placeholder="${esc(t('admin.settings.loyalty_label_placeholder'))}">
                                <span class="field-hint">${esc(t('admin.settings.loyalty_label_hint'))}</span>
                            </div>
                            <div class="field">
                                <label class="field-label" for="staff_pin">${esc(t('admin.settings.staff_pin'))}</label>
                                <input class="input" id="staff_pin" name="staff_pin" inputmode="numeric"
                                       pattern="[0-9]*" maxlength="4" autocomplete="off" placeholder="••••" style="max-width:140px">
                                <span class="field-hint">${esc(t('admin.settings.staff_pin_hint'))}</span>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary">${esc(t('admin.common.save'))}</button>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h2>${esc(t('admin.settings.loyalty_preview'))}</h2></div>
                    <div class="card-body">
                        <p class="text-sm text-muted mb-4">${esc(t('admin.settings.loyalty_preview_hint'))}</p>
                        <div style="border:1px solid var(--border);border-radius:var(--r-lg);padding:var(--sp-6);text-align:center">
                            <p style="font-weight:650;font-size:1.05rem">Hallo Anna!</p>
                            <div id="loyalty-preview" class="mt-4" style="text-align:left"></div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    `);

    host.innerHTML = '';
    host.appendChild(form);

    const updatePreview = () => {
        const values = formValues(form);
        const box = form.querySelector('#loyalty-preview');
        const isActive = form.elements.loyalty_active.checked;

        form.querySelector('#loyalty-fields').style.opacity = isActive ? '1' : '0.45';
        form.querySelectorAll('#loyalty-fields input, #loyalty-fields select')
            .forEach((input) => { input.disabled = !isActive; });

        if (!isActive) {
            box.innerHTML = `<p class="text-sm text-muted">${esc(t('admin.settings.loyalty_preview_inactive'))}</p>`;
            return;
        }

        const n = Number(values.visit_threshold) || 5;
        const discount = values.discount_label
            || (values.discount_type === 'percentage'
                ? `${Number(values.discount_value)} %`
                : `${Number(values.discount_value).toLocaleString('de-DE')} €`);
        const current = Math.max(1, Math.min(n - 2, Math.ceil(n / 2)));
        const percent = Math.round((current / n) * 100);

        box.innerHTML = `
            <div class="row" style="justify-content:space-between;margin-bottom:6px">
                <strong class="text-sm">${esc(t('admin.settings.loyalty_preview_visit', { current, total: n }))}</strong>
                <span class="text-xs text-muted">${percent}%</span>
            </div>
            <div style="height:16px;border-radius:8px;background:var(--slate-200);overflow:hidden">
                <div style="height:100%;width:${percent}%;border-radius:8px;background:linear-gradient(90deg,#22C55E,#16A34A)"></div>
            </div>
            <p class="text-sm mt-2">${esc(t('admin.settings.loyalty_preview_caption', { count: n - current, discount }))}</p>
        `;
    };

    form.addEventListener('input', updatePreview);
    form.addEventListener('change', updatePreview);
    updatePreview();

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFormErrors(form);

        const values = formValues(form);
        const errors = {};
        const threshold = Number(values.visit_threshold);
        if (!Number.isInteger(threshold) || threshold < 2 || threshold > 50) {
            errors.visit_threshold = t('admin.validation.threshold');
        }
        if (!(Number(values.discount_value) > 0)) {
            errors.discount_value = t('admin.validation.positive');
        }
        const pin = String(values.staff_pin || '').trim();
        if (pin && !/^\d{4}$/.test(pin)) {
            errors.staff_pin = t('admin.validation.pin');
        }
        if (Object.keys(errors).length) {
            showFormErrors(form, errors);
            return;
        }

        const payload = {
            salon_id: ctx.salonId,
            loyalty_active: form.elements.loyalty_active.checked ? 1 : 0,
            visit_threshold: threshold,
            discount_type: values.discount_type,
            discount_value: Number(values.discount_value),
            discount_label: values.discount_label || '',
        };
        // An empty PIN box means "keep the current PIN", so it is only sent
        // when the operator actually typed one.
        if (pin) payload.staff_pin = pin;

        const button = form.querySelector('button[type=submit]');
        const reset = buttonBusy(button, t('admin.common.saving'));
        try {
            await apiPost('loyalty-config.php', payload, { salonScope: false });
            toastSuccess(t('admin.settings.saved'));
            reset();
            form.elements.staff_pin.value = '';
        } catch (error) {
            reset();
            toastApiError(error);
        }
    });
}

/* ============================================================
   Social & QR  (social-links.php)
   ============================================================ */

const LINK_TYPES = [
    'instagram', 'facebook', 'tiktok', 'google_reviews', 'yelp',
    'twitter', 'linkedin', 'youtube', 'pinterest', 'custom',
];

let links = [];

async function renderSocial(host, ctx) {
    const data = await apiGet(
        `social-links.php?salon_id=${encodeURIComponent(ctx.salonId)}&include_inactive=true`,
        { salonScope: false }
    );
    links = data.links || [];

    host.innerHTML = '';

    const header = el(`<div class="row mb-4"><span class="text-sm text-muted" style="flex:1">${esc(t('admin.settings.social_hint'))}</span></div>`);
    const addButton = el(`<button type="button" class="btn btn-primary">＋ ${esc(t('admin.settings.social_add'))}</button>`);
    addButton.addEventListener('click', () => openLinkModal(null, ctx));
    header.appendChild(addButton);
    host.appendChild(header);

    const table = createTable({
        rows: links,
        searchKeys: ['display_name', 'link_url'],
        searchPlaceholderKey: 'admin.settings.social_search',
        defaultSort: { key: 'display_order', dir: 'asc' },
        empty: {
            art: 'mail',
            title: t('admin.settings.social_empty_title'),
            text: t('admin.settings.social_empty_text'),
            action: { label: t('admin.settings.social_add'), onClick: () => openLinkModal(null, ctx) },
        },
        columns: [
            {
                key: 'display_name',
                labelKey: 'admin.settings.social_name',
                sortable: true,
                primary: true,
                render: (l) => `<span class="cell-strong">${esc(l.display_name)}</span>`,
            },
            {
                key: 'link_type',
                labelKey: 'admin.settings.social_type',
                sortable: true,
                render: (l) => `<span class="badge badge-brand">${esc(t(`admin.settings.social_types.${l.link_type}`))}</span>`,
            },
            {
                key: 'link_url',
                labelKey: 'admin.settings.social_url',
                render: (l) => `<a href="${esc(l.link_url)}" target="_blank" rel="noopener" class="text-sm">${esc(truncate(l.link_url, 44))}</a>`,
            },
            {
                key: 'display_order',
                labelKey: 'admin.settings.social_order',
                sortable: true,
                className: 'cell-num',
                sortValue: (l) => Number(l.display_order || 0),
            },
            {
                key: 'is_active',
                labelKey: 'admin.settings.social_status',
                sortable: true,
                sortValue: (l) => (Number(l.is_active) ? 1 : 0),
                render: (l) => boolBadge(Number(l.is_active) === 1, 'admin.status.active', 'admin.status.inactive'),
            },
            {
                key: 'actions',
                label: '',
                className: 'cell-actions',
                cardHidden: true,
                render: (l) => `
                    <button type="button" class="btn btn-ghost btn-sm" data-qr="${l.link_id}">${esc(t('admin.settings.social_qr'))}</button>
                    <button type="button" class="btn btn-ghost btn-sm" data-edit="${l.link_id}">${esc(t('admin.common.edit'))}</button>
                    <button type="button" class="btn btn-ghost btn-sm" style="color:var(--danger-600)" data-delete="${l.link_id}">${esc(t('admin.common.delete'))}</button>
                `,
            },
        ],
    });

    table.element.addEventListener('click', async (event) => {
        const qrId = event.target.closest('[data-qr]')?.dataset.qr;
        if (qrId) return showQrCode(Number(qrId));

        const editId = event.target.closest('[data-edit]')?.dataset.edit;
        if (editId) return openLinkModal(Number(editId), ctx);

        const deleteId = event.target.closest('[data-delete]')?.dataset.delete;
        if (deleteId) return deleteLink(Number(deleteId), ctx);
    });

    host.appendChild(table.element);
}

function truncate(value, max) {
    const text = String(value || '');
    return text.length > max ? `${text.slice(0, max - 1)}…` : text;
}

function openLinkModal(linkId, ctx) {
    const link = linkId ? links.find((l) => Number(l.link_id) === Number(linkId)) : null;

    const form = el(`
        <form id="link-form" novalidate>
            <div class="form-grid">
                <div class="form-grid form-grid-2">
                    <div class="field">
                        <label class="field-label" for="link_type">${esc(t('admin.settings.social_type'))}<span class="req">*</span></label>
                        <select class="select" id="link_type" name="link_type" required>
                            ${LINK_TYPES.map((type) =>
                                `<option value="${type}" ${link?.link_type === type ? 'selected' : ''}>${esc(t(`admin.settings.social_types.${type}`))}</option>`
                            ).join('')}
                        </select>
                    </div>
                    <div class="field">
                        <label class="field-label" for="display_order">${esc(t('admin.settings.social_order'))}</label>
                        <input class="input" id="display_order" name="display_order" type="number" min="0"
                               value="${Number(link?.display_order || 0)}">
                    </div>
                </div>
                <div class="field">
                    <label class="field-label" for="display_name">${esc(t('admin.settings.social_name'))}<span class="req">*</span></label>
                    <input class="input" id="display_name" name="display_name" required
                           value="${esc(link?.display_name || '')}"
                           placeholder="${esc(t('admin.settings.social_name_placeholder'))}">
                </div>
                <div class="field">
                    <label class="field-label" for="link_url">${esc(t('admin.settings.social_url'))}<span class="req">*</span></label>
                    <input class="input" id="link_url" name="link_url" type="url" required
                           value="${esc(link?.link_url || '')}" placeholder="https://...">
                </div>
                <div class="field">
                    <label class="field-label" for="description">${esc(t('admin.settings.social_description'))}</label>
                    <textarea class="textarea" id="description" name="description" rows="2">${esc(link?.description || '')}</textarea>
                </div>
                <label class="switch">
                    <input type="checkbox" name="is_active" ${link === null || Number(link?.is_active) === 1 ? 'checked' : ''}>
                    <span class="switch-track"></span>
                    <span class="switch-label">${esc(t('admin.settings.social_active'))}</span>
                </label>
            </div>
        </form>
    `);

    modal({
        title: link ? t('admin.settings.social_edit') : t('admin.settings.social_add'),
        body: form,
        actions: [
            { label: t('admin.common.cancel'), variant: 'secondary', onClick: (close) => close() },
            {
                label: t('admin.common.save'),
                variant: 'primary',
                onClick: async (close, button) => {
                    clearFormErrors(form);
                    const values = formValues(form);
                    const errors = {};

                    if (!values.display_name) errors.display_name = t('admin.validation.required');
                    if (!values.link_url) errors.link_url = t('admin.validation.required');
                    else if (!/^https?:\/\/.+/.test(values.link_url)) errors.link_url = t('admin.validation.url');

                    if (Object.keys(errors).length) {
                        showFormErrors(form, errors);
                        return;
                    }

                    const payload = {
                        salon_id: ctx.salonId,
                        link_type: values.link_type,
                        link_url: values.link_url,
                        display_name: values.display_name,
                        description: values.description || '',
                        display_order: Number(values.display_order) || 0,
                        is_active: form.elements.is_active.checked ? 1 : 0,
                    };

                    const reset = buttonBusy(button, t('admin.common.saving'));
                    try {
                        if (link) {
                            await apiRequest(`social-links.php?link_id=${link.link_id}`, {
                                method: 'PUT', body: payload, salonScope: false,
                            });
                        } else {
                            await apiPost('social-links.php', payload, { salonScope: false });
                        }
                        toastSuccess(t('admin.settings.saved'));
                        close();
                        ctx.reload();
                    } catch (error) {
                        reset();
                        toastApiError(error);
                    }
                },
            },
        ],
    });
}

async function deleteLink(linkId, ctx) {
    const link = links.find((l) => Number(l.link_id) === Number(linkId));
    const confirmed = await confirmDialog({
        title: t('admin.settings.social_delete_title'),
        message: t('admin.settings.social_delete_message', { name: link?.display_name || '' }),
        confirmLabel: t('admin.common.delete'),
    });
    if (!confirmed) return;

    try {
        await apiDelete(`social-links.php?link_id=${linkId}`, { salonScope: false });
        toastSuccess(t('admin.settings.social_deleted'));
        ctx.reload();
    } catch (error) {
        toastApiError(error);
    }
}

/**
 * QR preview. QRCode.js is loaded from the page (deferred), so guard against it
 * not having arrived yet rather than throwing inside the modal.
 */
function showQrCode(linkId) {
    const link = links.find((l) => Number(l.link_id) === Number(linkId));
    if (!link) return;

    const body = el(`
        <div style="text-align:center">
            <div id="qr-target" style="display:inline-block;padding:var(--sp-4);background:#fff;border-radius:var(--r-md);border:1px solid var(--border)"></div>
            <p class="text-sm text-muted mt-4" style="word-break:break-all">${esc(link.link_url)}</p>
        </div>
    `);

    modal({
        title: t('admin.settings.social_qr'),
        subtitle: link.display_name,
        body,
        actions: [{ label: t('admin.common.close'), variant: 'secondary', onClick: (close) => close() }],
    });

    const target = body.querySelector('#qr-target');
    if (typeof QRCode === 'undefined') {
        target.innerHTML = `<p class="text-sm text-muted">${esc(t('admin.settings.qr_unavailable'))}</p>`;
        return;
    }
    // eslint-disable-next-line no-new
    new QRCode(target, { text: link.link_url, width: 240, height: 240, correctLevel: QRCode.CorrectLevel.H });
}
