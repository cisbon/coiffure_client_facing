/**
 * Plattform (global kiosk settings, Administrator only)
 * ------------------------------------------------------------
 * Migrated from the global-settings block of the old admin-dashboard.js.
 * These are the tablet check-in timeouts stored in coiffure_global_settings and
 * served to every kiosk by api/global-settings.php. They apply platform-wide,
 * which is why only a full administrator may change them (POST on that endpoint
 * is restricted to role 'admin').
 */

import { apiGet, apiPost } from '../api.js';
import {
    t, esc, el, pageHeader, toastSuccess, toastApiError, showFormErrors,
    clearFormErrors, formValues, buttonBusy, skeletonRows, forbiddenState,
} from '../ui.js';

/**
 * Setting key -> [label, hint, min, max]. The bounds mirror the whitelist in
 * api/global-settings.php so the form rejects what the server would reject.
 */
const FIELDS = {
    timeout_idle_return_s:       ['admin.platform.idle_return', 5, 600],
    timeout_birthday_s:          ['admin.platform.birthday', 5, 600],
    timeout_autoconfirm_s:       ['admin.platform.autoconfirm', 5, 600],
    timeout_namelist_s:          ['admin.platform.namelist', 5, 600],
    timeout_names_confirm_s:     ['admin.platform.names_confirm', 3, 300],
    timeout_phone_s:             ['admin.platform.phone', 5, 600],
    timeout_welcome_success_s:   ['admin.platform.welcome_success', 2, 120],
    timeout_welcome_duplicate_s: ['admin.platform.welcome_duplicate', 2, 120],
    timeout_staff_pin_s:         ['admin.platform.staff_pin', 5, 600],
    timeout_staff_search_s:      ['admin.platform.staff_search', 5, 600],
    timeout_autocheckout_s:      ['admin.platform.autocheckout', 60, 86400],
    timeout_autophoto_s:         ['admin.platform.autophoto', 1, 60],
    timeout_autoslide_trends_s:  ['admin.platform.autoslide', 1, 60],
};

export async function render(container, ctx) {
    container.appendChild(pageHeader({
        title: t('admin.platform.title'),
        subtitle: t('admin.platform.subtitle'),
    }));

    // Server-enforced too; this keeps an Admin Delegate from seeing a form
    // whose save would always 403.
    if (!ctx.permissions.canManagePlatformConfig) {
        container.appendChild(forbiddenState());
        return;
    }

    const host = el('<div></div>');
    container.appendChild(host);
    host.appendChild(skeletonRows(8));

    let settings = {};
    try {
        const data = await apiGet('global-settings.php', { salonScope: false });
        settings = data.settings || data;
    } catch (error) {
        toastApiError(error);
    }

    host.innerHTML = '';
    host.appendChild(buildForm(settings));
}

function buildForm(settings) {
    const form = el(`
        <form id="platform-form" novalidate>
            <div class="card">
                <div class="card-header">
                    <h2>${esc(t('admin.platform.timeouts'))}</h2>
                </div>
                <div class="card-body">
                    <p class="card-hint mb-6">${esc(t('admin.platform.timeouts_hint'))}</p>
                    <div class="form-grid form-grid-2">
                        ${Object.entries(FIELDS).map(([key, [labelKey, min, max]]) => `
                            <div class="field">
                                <label class="field-label" for="${key}">${esc(t(labelKey))}</label>
                                <input class="input" id="${key}" name="${key}" type="number"
                                       min="${min}" max="${max}" step="1"
                                       value="${esc(settings[key] ?? '')}">
                                <span class="field-hint">${esc(t('admin.platform.range', { min, max }))}</span>
                            </div>
                        `).join('')}
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">${esc(t('admin.common.save'))}</button>
                    <button type="button" class="btn btn-secondary" id="reload">${esc(t('admin.common.reset'))}</button>
                </div>
            </div>
        </form>
    `);

    form.querySelector('#reload').addEventListener('click', () => window.location.reload());

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFormErrors(form);

        const values = formValues(form);
        const errors = {};
        const payload = {};

        Object.entries(FIELDS).forEach(([key, [, min, max]]) => {
            const raw = values[key];
            if (raw === '' || raw === undefined) return;   // leave untouched
            const number = Number(raw);
            if (!Number.isInteger(number) || number < min || number > max) {
                errors[key] = t('admin.platform.range', { min, max });
            } else {
                payload[key] = number;
            }
        });

        if (Object.keys(errors).length) {
            showFormErrors(form, errors);
            return;
        }

        const button = form.querySelector('button[type=submit]');
        const reset = buttonBusy(button, t('admin.common.saving'));
        try {
            await apiPost('global-settings.php', payload, { salonScope: false });
            toastSuccess(t('admin.platform.saved'));
            reset();
        } catch (error) {
            reset();
            toastApiError(error);
        }
    });

    return form;
}
