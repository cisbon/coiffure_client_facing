/**
 * Einstellungen (salon settings)
 * ------------------------------------------------------------
 * Sub-tabbed settings screen covering the six sections of spec 3.3. They are
 * split across three endpoints, by what each one needs rather than by topic:
 *
 *   Allgemein       salon-settings.php?section=general   (name, contact, website)
 *                   + salon-branding.php                 (logo upload, colours —
 *                     multipart, which is why it stays its own endpoint)
 *   Tablet          salon-settings.php?section=tablet
 *   Mitgliedschaft  loyalty-config.php (loyalty programme)
 *                   + salon-settings.php?section=membership (refer-a-friend)
 *   Geburtstag      salon-settings.php?section=birthday
 *   Social & QR     social-links.php (link CRUD + QR preview). Guest WiFi
 *                   lives on the Allgemein tab, with salon-branding.php.
 *   Öffnungszeiten  salon-settings.php?section=hours
 *   KI-Stilberatung ai-usage.php (consumption, overage choice, and — for
 *                   platform roles only — the limits themselves)
 */

import { API_BASE_URL, apiGet, apiPost, apiDelete, apiRequest, getToken } from '../api.js';
import {
    t, esc, el, modal, confirmDialog, pageHeader, subTabs, toastSuccess,
    toastError, toastApiError, showFormErrors, clearFormErrors, formValues,
    buttonBusy, skeletonRows, createTable, boolBadge, formatNumber, formatMoney,
} from '../ui.js';
import { usageBlock, usageState } from '../ai-usage.js';

const TABS = [
    { id: 'general', labelKey: 'admin.settings.tab_general' },
    { id: 'tablet', labelKey: 'admin.settings.tab_tablet' },
    { id: 'loyalty', labelKey: 'admin.settings.tab_loyalty' },
    { id: 'birthday', labelKey: 'admin.settings.tab_birthday' },
    { id: 'social', labelKey: 'admin.settings.tab_social' },
    { id: 'hours', labelKey: 'admin.settings.tab_hours' },
    { id: 'ai', labelKey: 'admin.settings.tab_ai' },
];

/**
 * The whole settings payload, fetched once per section render. Sections that
 * only touch salon-settings.php share it rather than each issuing their own
 * request for the same row.
 */
async function loadSettings(ctx) {
    return apiGet(`salon-settings.php?salon_id=${encodeURIComponent(ctx.salonId)}`, { salonScope: false });
}

/** POST one section back, mapping 422 field errors onto the form. */
async function saveSection(section, payload, form, button, ctx, onDone) {
    clearFormErrors(form);
    const reset = buttonBusy(button, t('admin.common.saving'));
    try {
        await apiPost(
            `salon-settings.php?section=${section}&salon_id=${encodeURIComponent(ctx.salonId)}`,
            payload,
            { salonScope: false }
        );
        reset();
        toastSuccess(t('admin.settings.saved'));
        if (onDone) onDone();
    } catch (error) {
        reset();
        if (error.details?.fields) showFormErrors(form, error.details.fields);
        else toastApiError(error);
    }
}

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
        if (id === 'tablet') await renderTablet(host, ctx);
        else if (id === 'loyalty') await renderLoyalty(host, ctx);
        else if (id === 'birthday') await renderBirthday(host, ctx);
        else if (id === 'social') await renderSocial(host, ctx);
        else if (id === 'hours') await renderHours(host, ctx);
        else if (id === 'ai') await renderAi(host, ctx);
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
    const [data, settings] = await Promise.all([
        apiGet(`salon-branding.php?salon_id=${encodeURIComponent(ctx.salonId)}`, { salonScope: false }),
        loadSettings(ctx),
    ]);
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
    host.appendChild(masterDataCard(settings.general || {}, ctx));
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

/** Salon master data (salon-settings.php?section=general). */
function masterDataCard(general, ctx) {
    const form = el(`
        <form id="general-form" class="card mb-6" novalidate>
            <div class="card-header"><h2>${esc(t('admin.settings.master_data'))}</h2></div>
            <div class="card-body">
                <div class="form-grid form-grid-2">
                    <div class="field">
                        <label class="field-label" for="salon_name">${esc(t('admin.settings.salon_name'))}<span class="req">*</span></label>
                        <input class="input" id="salon_name" name="salon_name" required value="${esc(general.salon_name || '')}">
                    </div>
                    <div class="field">
                        <label class="field-label" for="salon_email">${esc(t('admin.settings.salon_email'))}<span class="req">*</span></label>
                        <input class="input" id="salon_email" name="email" type="email" required value="${esc(general.email || '')}">
                    </div>
                    <div class="field">
                        <label class="field-label" for="salon_phone">${esc(t('admin.settings.salon_phone'))}</label>
                        <input class="input" id="salon_phone" name="phone" type="tel" value="${esc(general.phone || '')}">
                    </div>
                    <div class="field">
                        <label class="field-label" for="salon_website">${esc(t('admin.settings.salon_website'))}</label>
                        <input class="input" id="salon_website" name="website" type="url"
                               placeholder="https://..." value="${esc(general.website || '')}">
                    </div>
                    <div class="field">
                        <label class="field-label" for="salon_address">${esc(t('admin.settings.salon_address'))}</label>
                        <textarea class="textarea" id="salon_address" name="address" rows="2">${esc(general.address || '')}</textarea>
                    </div>
                    <div class="field">
                        <label class="field-label" for="default_language">${esc(t('admin.profile.salon_language'))}</label>
                        <select class="select" id="default_language" name="default_language">
                            <option value="de" ${(general.default_language || 'de') === 'de' ? 'selected' : ''}>Deutsch</option>
                            <option value="en" ${general.default_language === 'en' ? 'selected' : ''}>English</option>
                        </select>
                        <span class="field-hint">${esc(t('admin.profile.salon_language_hint'))}</span>
                    </div>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary">${esc(t('admin.common.save'))}</button>
            </div>
        </form>
    `);

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const values = formValues(form);
        saveSection('general', {
            salon_name: values.salon_name,
            email: values.email,
            phone: values.phone,
            address: values.address,
            website: values.website,
            default_language: values.default_language,
        }, form, form.querySelector('button[type=submit]'), ctx, () => ctx.reload());
    });

    return form;
}

/* ============================================================
   Tablet  (salon-settings.php?section=tablet)
   ============================================================ */

const TABLET_MODULES = [
    ['register', 'admin.settings.module_register', 'admin.settings.module_register_desc'],
    ['checkin', 'admin.settings.module_checkin', 'admin.settings.module_checkin_desc'],
    ['browse', 'admin.settings.module_browse', 'admin.settings.module_browse_desc'],
];

async function renderTablet(host, ctx) {
    const settings = await loadSettings(ctx);
    const tablet = settings.tablet || {};
    const modules = tablet.modules || { register: true, checkin: true, browse: true };

    const form = el(`
        <form id="tablet-form" novalidate>
            <div class="stack">
                <div class="card">
                    <div class="card-header"><h2>${esc(t('admin.settings.tablet_welcome'))}</h2></div>
                    <div class="card-body">
                        <div class="form-grid">
                            <div class="field">
                                <label class="field-label" for="headline">${esc(t('admin.settings.tablet_headline'))}</label>
                                <input class="input" id="headline" name="headline" maxlength="120"
                                       value="${esc(tablet.headline || '')}"
                                       placeholder="${esc(t('admin.settings.tablet_headline_placeholder'))}">
                                <span class="field-hint">${esc(t('admin.settings.tablet_headline_hint'))}</span>
                            </div>
                            <div class="form-grid form-grid-2">
                                <div class="field">
                                    <label class="field-label" for="bg_image">${esc(t('admin.settings.tablet_bg_image'))}</label>
                                    <input class="input" id="bg_image" name="bg_image" type="url"
                                           placeholder="https://..." value="${esc(tablet.bg_image || '')}">
                                    <span class="field-hint">${esc(t('admin.settings.tablet_bg_image_hint'))}</span>
                                </div>
                                <div class="field">
                                    <label class="field-label" for="bg_color_hex">${esc(t('admin.settings.tablet_bg_color'))}</label>
                                    <div class="color-field">
                                        <input type="color" id="bg_color" value="${esc(tablet.bg_color || '#FFFFFF')}"
                                               aria-label="${esc(t('admin.settings.tablet_bg_color'))}">
                                        <input class="input" type="text" id="bg_color_hex" name="bg_color"
                                               value="${esc(tablet.bg_color || '')}" maxlength="7" placeholder="#FFFFFF">
                                    </div>
                                    <span class="field-hint">${esc(t('admin.settings.tablet_bg_color_hint'))}</span>
                                </div>
                            </div>
                            <div class="field">
                                <label class="field-label" for="idle_timeout_s">${esc(t('admin.settings.tablet_idle'))}</label>
                                <input class="input" id="idle_timeout_s" name="idle_timeout_s" type="number"
                                       min="5" max="600" step="5" style="max-width:160px"
                                       value="${tablet.idle_timeout_s ?? ''}"
                                       placeholder="${esc(t('admin.settings.tablet_idle_default'))}">
                                <span class="field-hint">${esc(t('admin.settings.tablet_idle_hint'))}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h2>${esc(t('admin.settings.tablet_modules'))}</h2></div>
                    <div class="card-body">
                        <p class="card-hint mb-4">${esc(t('admin.settings.tablet_modules_hint'))}</p>
                        <div class="form-grid form-grid-2" id="module-boxes">
                            ${TABLET_MODULES.map(([key, labelKey, descKey]) => `
                                <label class="check">
                                    <input type="checkbox" name="module_${key}" ${modules[key] ? 'checked' : ''}>
                                    <span class="check-text">
                                        <span class="check-title">${esc(t(labelKey))}</span>
                                        <span class="check-desc">${esc(t(descKey))}</span>
                                    </span>
                                </label>
                            `).join('')}
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

    const picker = form.querySelector('#bg_color');
    const hex = form.querySelector('#bg_color_hex');
    picker.addEventListener('input', () => { hex.value = picker.value.toUpperCase(); });
    hex.addEventListener('input', () => {
        if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) picker.value = hex.value;
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        clearFormErrors(form);

        const modulesOut = {};
        TABLET_MODULES.forEach(([key]) => { modulesOut[key] = form.elements[`module_${key}`].checked; });

        // The server refuses this too; catching it here says why in place.
        if (!Object.values(modulesOut).some(Boolean)) {
            toastError(t('admin.settings.modules_min_error'));
            return;
        }

        const values = formValues(form);
        saveSection('tablet', {
            headline: values.headline,
            bg_image: values.bg_image,
            bg_color: values.bg_color,
            idle_timeout_s: values.idle_timeout_s || null,
            modules: modulesOut,
        }, form, form.querySelector('button[type=submit]'), ctx);
    });
}

/* ============================================================
   Geburtstagskampagne  (salon-settings.php?section=birthday)
   ============================================================ */

async function renderBirthday(host, ctx) {
    const settings = await loadSettings(ctx);
    const birthday = settings.birthday || {};

    const form = el(`
        <form id="birthday-form" novalidate>
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>${esc(t('admin.settings.birthday_title'))}</h2>
                        <p class="card-hint">${esc(t('admin.settings.birthday_hint'))}</p>
                    </div>
                </div>
                <div class="card-body">
                    <label class="switch mb-6">
                        <input type="checkbox" name="enabled" ${birthday.enabled ? 'checked' : ''}>
                        <span class="switch-track"></span>
                        <span class="switch-label">${esc(t('admin.settings.birthday_enabled'))}</span>
                    </label>

                    <div class="form-grid" id="birthday-fields">
                        <div class="form-grid form-grid-2">
                            <div class="field">
                                <label class="field-label" for="days_before">${esc(t('admin.settings.birthday_days'))}</label>
                                <input class="input" id="days_before" name="days_before" type="number"
                                       min="0" max="60" step="1" value="${Number(birthday.days_before ?? 7)}">
                                <span class="field-hint">${esc(t('admin.settings.birthday_days_hint'))}</span>
                            </div>
                            <div class="field">
                                <label class="field-label" for="discount_code">${esc(t('admin.settings.birthday_code'))}</label>
                                <input class="input" id="discount_code" name="discount_code" maxlength="40"
                                       value="${esc(birthday.discount_code || '')}"
                                       placeholder="${esc(t('admin.settings.birthday_code_placeholder'))}">
                                <span class="field-hint">${esc(t('admin.settings.birthday_code_hint'))}</span>
                            </div>
                        </div>
                        <div class="field">
                            <label class="field-label" for="subject">${esc(t('admin.settings.birthday_subject'))}</label>
                            <input class="input" id="subject" name="subject" maxlength="200"
                                   value="${esc(birthday.subject || '')}"
                                   placeholder="${esc(t('admin.settings.birthday_subject_placeholder'))}">
                        </div>
                        <div class="field">
                            <label class="field-label" for="body">${esc(t('admin.settings.birthday_body'))}</label>
                            <textarea class="textarea" id="body" name="body" rows="8">${esc(birthday.body || '')}</textarea>
                            <span class="field-hint">${esc(t('admin.settings.birthday_body_hint'))}</span>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">${esc(t('admin.common.save'))}</button>
                </div>
            </div>
        </form>
    `);

    host.innerHTML = '';
    host.appendChild(form);

    // Tokens the mailer substitutes at send time, insertable at the caret.
    const tokenRow = el(`<div class="row mt-2" style="gap:6px;flex-wrap:wrap"></div>`);
    tokenRow.appendChild(el(`<span class="text-xs text-muted">${esc(t('admin.campaigns.tokens'))}</span>`));
    ['vorname', 'salonname', 'rabattcode'].forEach((token) => {
        const chip = el(`<button type="button" class="chip btn-sm">{${esc(token)}}</button>`);
        chip.addEventListener('click', () => {
            const area = form.elements.body;
            const at = area.selectionStart ?? area.value.length;
            area.value = `${area.value.slice(0, at)}{${token}}${area.value.slice(at)}`;
            area.focus();
        });
        tokenRow.appendChild(chip);
    });
    form.querySelector('#body').parentElement.appendChild(tokenRow);

    const sync = () => {
        const on = form.elements.enabled.checked;
        const fields = form.querySelector('#birthday-fields');
        fields.style.opacity = on ? '1' : '0.45';
        fields.querySelectorAll('input, textarea').forEach((input) => { input.disabled = !on; });
    };
    form.elements.enabled.addEventListener('change', sync);
    sync();

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const values = formValues(form);
        saveSection('birthday', {
            enabled: form.elements.enabled.checked,
            days_before: Number(values.days_before) || 0,
            subject: values.subject,
            body: values.body,
            discount_code: values.discount_code,
        }, form, form.querySelector('button[type=submit]'), ctx);
    });
}

/* ============================================================
   Öffnungszeiten  (salon-settings.php?section=hours)
   ============================================================ */

async function renderHours(host, ctx) {
    const settings = await loadSettings(ctx);
    // The server always returns all seven days, Monday first.
    const week = settings.hours || [];

    const form = el(`
        <form id="hours-form" novalidate>
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>${esc(t('admin.settings.hours_title'))}</h2>
                        <p class="card-hint">${esc(t('admin.settings.hours_hint'))}</p>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" id="copy-first">${esc(t('admin.settings.hours_copy'))}</button>
                </div>
                <div class="card-body">
                    <div class="stack" style="gap:var(--sp-3)">
                        ${week.map((day) => `
                            <div class="row hours-row" data-weekday="${day.weekday}"
                                 style="gap:var(--sp-3);flex-wrap:wrap;align-items:center">
                                <strong class="text-sm" style="min-width:120px">${esc(t(`admin.weekdays.${day.weekday}`))}</strong>
                                <label class="switch" style="min-width:150px">
                                    <input type="checkbox" data-open ${day.is_closed ? '' : 'checked'}>
                                    <span class="switch-track"></span>
                                    <span class="switch-label">${esc(t('admin.settings.hours_open'))}</span>
                                </label>
                                <input class="input" type="time" data-from style="max-width:130px"
                                       value="${esc(day.open_time || '09:00')}"
                                       aria-label="${esc(t('admin.settings.hours_from'))}">
                                <span class="text-muted">–</span>
                                <input class="input" type="time" data-to style="max-width:130px"
                                       value="${esc(day.close_time || '18:00')}"
                                       aria-label="${esc(t('admin.settings.hours_to'))}">
                                <span class="field-error hidden" data-row-error></span>
                            </div>
                        `).join('')}
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">${esc(t('admin.common.save'))}</button>
                </div>
            </div>
        </form>
    `);

    host.innerHTML = '';
    host.appendChild(form);

    const rows = [...form.querySelectorAll('.hours-row')];

    const syncRow = (row) => {
        const open = row.querySelector('[data-open]').checked;
        row.querySelectorAll('[data-from], [data-to]').forEach((input) => { input.disabled = !open; });
    };
    rows.forEach((row) => {
        row.querySelector('[data-open]').addEventListener('change', () => syncRow(row));
        syncRow(row);
    });

    // Most salons keep the same hours all week; copying the first open day
    // beats typing the same two times seven times over.
    form.querySelector('#copy-first').addEventListener('click', () => {
        const source = rows.find((row) => row.querySelector('[data-open]').checked);
        if (!source) return;
        const from = source.querySelector('[data-from]').value;
        const to = source.querySelector('[data-to]').value;
        rows.forEach((row) => {
            if (row === source || !row.querySelector('[data-open]').checked) return;
            row.querySelector('[data-from]').value = from;
            row.querySelector('[data-to]').value = to;
        });
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();

        let valid = true;
        const hours = rows.map((row) => {
            const weekday = Number(row.dataset.weekday);
            const isClosed = !row.querySelector('[data-open]').checked;
            const openTime = row.querySelector('[data-from]').value;
            const closeTime = row.querySelector('[data-to]').value;
            const error = row.querySelector('[data-row-error]');

            error.classList.add('hidden');
            if (!isClosed && (!openTime || !closeTime || closeTime <= openTime)) {
                error.textContent = `⚠ ${t('admin.settings.hours_error')}`;
                error.classList.remove('hidden');
                valid = false;
            }

            return { weekday, is_closed: isClosed, open_time: openTime, close_time: closeTime };
        });

        if (!valid) {
            toastError(t('admin.settings.hours_error'));
            return;
        }

        saveSection('hours', { hours }, form, form.querySelector('button[type=submit]'), ctx);
    });
}

/* ============================================================
   Treueprogramm  (loyalty-config.php)
   ============================================================ */

async function renderLoyalty(host, ctx) {
    const [data, settings] = await Promise.all([
        apiGet(`loyalty-config.php?salon_id=${encodeURIComponent(ctx.salonId)}`, { salonScope: false }),
        loadSettings(ctx),
    ]);
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
    host.appendChild(referralCard(settings.membership || {}, ctx));

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

/**
 * Freunde werben (salon-settings.php?section=membership). Kept beside the
 * loyalty programme because both are the salon's membership perks, even though
 * they are stored by two different endpoints.
 */
function referralCard(membership, ctx) {
    const form = el(`
        <form id="referral-form" class="card mt-6" novalidate>
            <div class="card-header">
                <div>
                    <h2>${esc(t('admin.settings.referral_title'))}</h2>
                    <p class="card-hint">${esc(t('admin.settings.referral_hint'))}</p>
                </div>
            </div>
            <div class="card-body">
                <label class="switch mb-6">
                    <input type="checkbox" name="referral_enabled" ${membership.referral_enabled ? 'checked' : ''}>
                    <span class="switch-track"></span>
                    <span class="switch-label">${esc(t('admin.settings.referral_enabled'))}</span>
                </label>
                <div class="field" style="max-width:220px">
                    <label class="field-label" for="referral_discount_value">${esc(t('admin.settings.referral_value'))}</label>
                    <input class="input" id="referral_discount_value" name="referral_discount_value"
                           type="number" min="0" step="0.5"
                           value="${Number(membership.referral_discount_value ?? 10)}">
                    <span class="field-hint">${esc(t('admin.settings.referral_value_hint'))}</span>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary">${esc(t('admin.common.save'))}</button>
            </div>
        </form>
    `);

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const values = formValues(form);
        saveSection('membership', {
            referral_enabled: form.elements.referral_enabled.checked,
            referral_discount_value: Number(values.referral_discount_value) || 0,
        }, form, form.querySelector('button[type=submit]'), ctx);
    });

    return form;
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
    // social-links.php answers with `data`, which is also what index.html
    // reads. `links` is tolerated in case that ever changes.
    links = data.data || data.links || [];

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
                            // handlePut() reads link_id from the JSON body, not
                            // from the query string -- sending it only in the
                            // URL failed with "link_id is required".
                            await apiRequest('social-links.php', {
                                method: 'PUT',
                                body: { ...payload, link_id: link.link_id },
                                salonScope: false,
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

/* ============================================================
   KI-Stilberatung: consumption, overage choice, limits
   (ai-usage.php — see api/ai_usage_helpers.php for the rules)
   ============================================================ */

/** Stylist keys the ledger knows about, for the per-feature breakdown. */
const AI_TYPE_LABELS = {
    hairstyle: 'admin.ai_usage.type_hairstyle',
    eyebrows: 'admin.ai_usage.type_eyebrows',
};

async function renderAi(host, ctx) {
    const data = await apiGet(
        `ai-usage.php?salon_id=${encodeURIComponent(ctx.salonId)}&months=6`,
        { salonScope: false }
    );
    const usage = data.usage || {};

    host.innerHTML = '';
    const stack = el('<div class="stack"></div>');

    stack.appendChild(aiConsumptionCard(usage, data));
    stack.appendChild(aiOverageCard(usage, data, ctx));
    if (data.can_change_limits) stack.appendChild(aiLimitsCard(data.limits || {}, usage, ctx));
    if (data.history?.length) stack.appendChild(aiHistoryCard(data.history));

    host.appendChild(stack);
}

/** Where the salon stands right now. */
function aiConsumptionCard(usage, data) {
    const card = el(`
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>${esc(t('admin.ai_usage.current_title'))}</h2>
                    <p class="card-hint">${esc(t('admin.ai_usage.current_hint'))}</p>
                </div>
            </div>
            <div class="card-body"></div>
        </div>
    `);

    const body = card.querySelector('.card-body');
    body.appendChild(usageBlock(usage));

    // Which stylist is actually being used — useful when deciding whether the
    // allowance is well spent.
    if (data.by_type?.length) {
        const split = el('<div class="row mt-4" style="gap:var(--sp-5)"></div>');
        data.by_type.forEach((row) => {
            const label = AI_TYPE_LABELS[row.consultation_type]
                ? t(AI_TYPE_LABELS[row.consultation_type])
                : row.consultation_type;
            split.appendChild(el(`
                <span class="text-sm text-muted">
                    ${esc(label)}: <strong>${esc(formatNumber(row.images))}</strong>
                </span>
            `));
        });
        body.appendChild(split);
    }

    if (usageState(usage) === 'blocked') {
        body.appendChild(el(`
            <p class="text-sm mt-4" style="color:var(--danger-700)">
                ${esc(t(`admin.ai_usage.blocked_help_${usage.block_reason || 'feature_disabled'}`))}
            </p>
        `));
    }

    return card;
}

/**
 * The salon owner's own decision: stop at the monthly limit, or keep
 * generating and pay per extra image. Deliberately a two-option radio rather
 * than a checkbox — spending money should never be the quiet default.
 */
function aiOverageCard(usage, data, ctx) {
    const price = formatMoney(usage.overage_price || 0, usage.currency || 'EUR');
    const disabled = data.can_change_overage ? '' : 'disabled';

    const form = el(`
        <form id="ai-overage-form" novalidate>
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>${esc(t('admin.ai_usage.overage_title'))}</h2>
                        <p class="card-hint">${esc(t('admin.ai_usage.overage_hint'))}</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="stack-sm">
                        <label class="check">
                            <input type="radio" name="ai_overage_allowed" value="0" ${disabled}
                                   ${usage.overage_allowed ? '' : 'checked'}>
                            <span class="check-text">
                                <span class="check-title">${esc(t('admin.ai_usage.overage_off'))}</span>
                                <span class="check-desc">${esc(t('admin.ai_usage.overage_off_desc'))}</span>
                            </span>
                        </label>
                        <label class="check">
                            <input type="radio" name="ai_overage_allowed" value="1" ${disabled}
                                   ${usage.overage_allowed ? 'checked' : ''}>
                            <span class="check-text">
                                <span class="check-title">${esc(t('admin.ai_usage.overage_on', { price }))}</span>
                                <span class="check-desc">${esc(t('admin.ai_usage.overage_on_desc'))}</span>
                            </span>
                        </label>
                    </div>

                    <div class="field mt-4" id="ai-cap-field">
                        <label class="field-label" for="ai_overage_monthly_cap">${esc(t('admin.ai_usage.cap'))}</label>
                        <div class="row" style="gap:var(--sp-2)">
                            <input class="input" type="number" id="ai_overage_monthly_cap"
                                   name="ai_overage_monthly_cap" min="0" max="100000" step="0.50"
                                   style="max-width:160px" ${disabled}
                                   value="${esc(usage.overage_cap || '')}" placeholder="0.00">
                            <span class="text-sm text-muted">${esc(usage.currency || 'EUR')}</span>
                        </div>
                        <span class="field-hint">${esc(t('admin.ai_usage.cap_hint'))}</span>
                    </div>
                    ${usage.mode === 'trial'
                        ? `<p class="text-sm text-muted mt-4">${esc(t('admin.ai_usage.overage_trial_note'))}</p>`
                        : ''}
                </div>
                ${data.can_change_overage
                    ? `<div class="card-footer">
                           <button type="submit" class="btn btn-primary">${esc(t('admin.common.save'))}</button>
                       </div>`
                    : ''}
            </div>
        </form>
    `);

    // The budget only means anything once extras are switched on, so it greys
    // out with the "stop at the limit" option rather than sitting there
    // implying it does something.
    const capField = form.querySelector('#ai-cap-field');
    const capInput = form.querySelector('#ai_overage_monthly_cap');
    const syncCapField = () => {
        const on = form.elements.ai_overage_allowed.value === '1';
        capField.style.opacity = on ? '' : '0.55';
        if (data.can_change_overage) capInput.disabled = !on;
    };
    form.querySelectorAll('input[name="ai_overage_allowed"]')
        .forEach((radio) => radio.addEventListener('change', syncCapField));
    syncCapField();

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        const reset = buttonBusy(button, t('admin.common.saving'));
        try {
            await apiPost(
                `ai-usage.php?section=overage&salon_id=${encodeURIComponent(ctx.salonId)}`,
                {
                    ai_overage_allowed: form.elements.ai_overage_allowed.value === '1',
                    // Empty reads as "no cap", the same as an explicit 0.
                    ai_overage_monthly_cap: Number(capInput.value || 0),
                },
                { salonScope: false }
            );
            reset();
            toastSuccess(t('admin.settings.saved'));
            ctx.reload();
        } catch (error) {
            reset();
            toastApiError(error);
        }
    });

    return form;
}

/**
 * The commercial terms. Only a platform role sees this card: these are the
 * numbers the platform sells, not something a salon sets for itself.
 */
function aiLimitsCard(limits, usage, ctx) {
    const form = el(`
        <form id="ai-limits-form" novalidate>
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>${esc(t('admin.ai_usage.limits_title'))}</h2>
                        <p class="card-hint">${esc(t('admin.ai_usage.limits_hint'))}</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="form-grid form-grid-2">
                        <div class="field">
                            <label class="field-label" for="ai_trial_image_limit">${esc(t('admin.ai_usage.trial_limit'))}</label>
                            <input class="input" type="number" id="ai_trial_image_limit" name="ai_trial_image_limit"
                                   min="0" max="1000000" step="10" value="${esc(limits.ai_trial_image_limit ?? '')}"
                                   placeholder="100">
                            <span class="field-hint">${esc(t('admin.ai_usage.trial_limit_hint'))}</span>
                        </div>
                        <div class="field">
                            <label class="field-label" for="ai_monthly_image_limit">${esc(t('admin.ai_usage.monthly_limit'))}</label>
                            <input class="input" type="number" id="ai_monthly_image_limit" name="ai_monthly_image_limit"
                                   min="0" max="1000000" step="10" value="${esc(limits.ai_monthly_image_limit ?? '')}"
                                   placeholder="500">
                            <span class="field-hint">${esc(t('admin.ai_usage.monthly_limit_hint'))}</span>
                        </div>
                        <div class="field">
                            <label class="field-label" for="ai_overage_price">${esc(t('admin.ai_usage.price'))}</label>
                            <input class="input" type="number" id="ai_overage_price" name="ai_overage_price"
                                   min="0" max="100" step="0.0001" value="${esc(limits.ai_overage_price ?? 0.01)}">
                            <span class="field-hint">${esc(t('admin.ai_usage.price_hint'))}</span>
                        </div>
                    </div>
                    <label class="check mt-4">
                        <input type="checkbox" name="ai_feature_enabled" ${limits.ai_feature_enabled ? 'checked' : ''}>
                        <span class="check-text">
                            <span class="check-title">${esc(t('admin.ai_usage.feature_enabled'))}</span>
                            <span class="check-desc">${esc(t('admin.ai_usage.feature_enabled_desc'))}</span>
                        </span>
                    </label>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">${esc(t('admin.common.save'))}</button>
                </div>
            </div>
        </form>
    `);

    // An empty field means "leave this limit as it is" rather than "set it to
    // zero", which would silently mean unlimited.
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        const reset = buttonBusy(button, t('admin.common.saving'));

        const payload = {
            ai_feature_enabled: form.elements.ai_feature_enabled.checked,
            ai_overage_price: Number(form.elements.ai_overage_price.value || 0),
        };
        const trial = form.elements.ai_trial_image_limit.value;
        const monthly = form.elements.ai_monthly_image_limit.value;
        if (trial !== '') payload.ai_trial_image_limit = Number(trial);
        if (monthly !== '') payload.ai_monthly_image_limit = Number(monthly);

        try {
            await apiPost(
                `ai-usage.php?section=limits&salon_id=${encodeURIComponent(ctx.salonId)}`,
                payload,
                { salonScope: false }
            );
            reset();
            toastSuccess(t('admin.settings.saved'));
            ctx.reload();
        } catch (error) {
            reset();
            toastApiError(error);
        }
    });

    return form;
}

/** The last months, so a salon can see whether this month is unusual. */
function aiHistoryCard(history) {
    const card = el(`
        <div class="card">
            <div class="card-header"><h2>${esc(t('admin.ai_usage.history_title'))}</h2></div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>${esc(t('admin.ai_usage.col_period'))}</th>
                            <th>${esc(t('admin.ai_usage.col_images'))}</th>
                            <th>${esc(t('admin.ai_usage.col_overage'))}</th>
                            <th>${esc(t('admin.ai_usage.col_cost'))}</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${history.map((row) => `
                            <tr>
                                <td>${esc(row.period_label)}</td>
                                <td>${esc(formatNumber(row.images))}</td>
                                <td>${esc(formatNumber(row.overage_images))}</td>
                                <td>${esc(formatMoney(row.overage_cost, 'EUR'))}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        </div>
    `);

    return card;
}
