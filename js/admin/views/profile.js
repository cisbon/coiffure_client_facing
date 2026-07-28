/**
 * Mein Profil
 * ------------------------------------------------------------
 * Migrated from the profile tab of the old admin-dashboard.js (updateProfile,
 * changePassword) plus the salon default-language selector that lived in an
 * inline <script> at the bottom of admin-dashboard.html.
 *
 * Interface language is deliberately not duplicated here: it is the flag
 * switcher in the top bar, which persists through js/i18n.js. This page sets
 * the *salon's* default language, which is what the tablet boots into.
 */

import { apiGet, apiPost, apiPut } from '../api.js';
import {
    t, esc, el, pageHeader, toastSuccess, toastApiError, showFormErrors,
    clearFormErrors, formValues, buttonBusy,
} from '../ui.js';

export async function render(container, ctx) {
    container.appendChild(pageHeader({
        title: t('admin.profile.title'),
        subtitle: t('admin.profile.subtitle'),
    }));

    const stack = el('<div class="stack"></div>');
    container.appendChild(stack);

    stack.appendChild(profileCard(ctx));
    stack.appendChild(passwordCard(ctx));
    stack.appendChild(await notificationCard());

    // Changing what the tablet boots into is a salon setting, so it is only
    // offered to someone who may change settings for the selected salon.
    if (ctx.salon && ctx.permissions.can('change_settings')) {
        stack.appendChild(salonLanguageCard(ctx));
    }
}

function profileCard(ctx) {
    const user = ctx.user;

    const form = el(`
        <form class="card" novalidate>
            <div class="card-header"><h2>${esc(t('admin.profile.account'))}</h2></div>
            <div class="card-body">
                <div class="form-grid form-grid-2">
                    <div class="field">
                        <label class="field-label" for="username">${esc(t('admin.users.username'))}</label>
                        <input class="input" id="username" value="${esc(user.username)}" readonly>
                        <span class="field-hint">${esc(t('admin.users.username_locked'))}</span>
                    </div>
                    <div class="field">
                        <label class="field-label" for="email">${esc(t('admin.users.email'))}<span class="req">*</span></label>
                        <input class="input" id="email" name="email" type="email" required value="${esc(user.email || '')}">
                    </div>
                    <div class="field">
                        <label class="field-label" for="full_name">${esc(t('admin.users.name'))}<span class="req">*</span></label>
                        <input class="input" id="full_name" name="full_name" required value="${esc(user.full_name || '')}">
                    </div>
                    <div class="field">
                        <label class="field-label" for="phone">${esc(t('admin.users.phone'))}</label>
                        <input class="input" id="phone" name="phone" type="tel" value="${esc(user.phone || '')}">
                    </div>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary">${esc(t('admin.common.save'))}</button>
            </div>
        </form>
    `);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFormErrors(form);

        const values = formValues(form);
        const errors = {};
        if (!values.email) errors.email = t('admin.validation.required');
        else if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(values.email)) errors.email = t('admin.validation.email');
        if (!values.full_name) errors.full_name = t('admin.validation.required');

        if (Object.keys(errors).length) {
            showFormErrors(form, errors);
            return;
        }

        const button = form.querySelector('button[type=submit]');
        const reset = buttonBusy(button, t('admin.common.saving'));
        try {
            await apiPut(`user-management.php?user_id=${ctx.user.user_id}`, {
                email: values.email,
                full_name: values.full_name,
                phone: values.phone || null,
            }, { salonScope: false });

            // Keep the cached blob in step so the avatar and menu update
            // without a reload.
            try {
                const cached = JSON.parse(localStorage.getItem('user_data') || '{}');
                Object.assign(cached, { email: values.email, full_name: values.full_name });
                localStorage.setItem('user_data', JSON.stringify(cached));
            } catch { /* non-fatal */ }

            toastSuccess(t('admin.profile.saved'));
            reset();
        } catch (error) {
            reset();
            toastApiError(error);
        }
    });

    return form;
}

/**
 * Notification preferences (spec: Notifications Centre).
 *
 * "mode" decides whether a notification is ALSO e-mailed; the bell in the top
 * bar always shows everything either way, so switching e-mail off never means
 * missing something -- only that it does not arrive twice.
 */
async function notificationCard() {
    const card = el(`
        <form id="notif-form" class="card" novalidate>
            <div class="card-header">
                <div>
                    <h2>${esc(t('admin.notifications.prefs_title'))}</h2>
                    <p class="card-hint">${esc(t('admin.notifications.prefs_hint'))}</p>
                </div>
            </div>
            <div class="card-body" id="notif-body">
                <div class="skeleton skeleton-row"></div>
            </div>
        </form>
    `);

    const body = card.querySelector('#notif-body');

    let prefs = { mode: 'off', events: [] };
    let available = [];
    try {
        const data = await apiGet('notifications.php?action=prefs', { salonScope: false });
        prefs = data.prefs || prefs;
        available = data.available_events || [];
    } catch {
        // Without migration 021 there are no preferences to set; hiding the
        // card beats showing one that cannot save.
        card.classList.add('hidden');
        return card;
    }

    body.innerHTML = `
        <div class="field" style="max-width:320px">
            <label class="field-label" for="notif-mode">${esc(t('admin.notifications.mode'))}</label>
            <select class="select" id="notif-mode" name="mode">
                ${['off', 'instant', 'daily'].map((mode) =>
                    `<option value="${mode}" ${prefs.mode === mode ? 'selected' : ''}>${esc(t(`admin.notifications.mode_${mode}`))}</option>`
                ).join('')}
            </select>
            <span class="field-hint">${esc(t('admin.notifications.mode_hint'))}</span>
        </div>
        <hr class="divider">
        <p class="card-hint mb-4">${esc(t('admin.notifications.events_hint'))}</p>
        <div class="form-grid form-grid-2">
            ${available.map((event) => `
                <label class="check">
                    <input type="checkbox" name="events" value="${esc(event)}" ${prefs.events.includes(event) ? 'checked' : ''}>
                    <span class="check-text">
                        <span class="check-title">${esc(t(`admin.notifications.events.${event}`))}</span>
                    </span>
                </label>
            `).join('')}
        </div>
    `;

    const footer = el(`
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">${esc(t('admin.common.save'))}</button>
        </div>
    `);
    card.appendChild(footer);

    card.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = footer.querySelector('button');
        const reset = buttonBusy(button, t('admin.common.saving'));
        try {
            await apiPost('notifications.php?action=prefs', {
                mode: card.elements.mode.value,
                events: [...card.querySelectorAll('input[name="events"]:checked')].map((box) => box.value),
            }, { salonScope: false });
            reset();
            toastSuccess(t('admin.notifications.prefs_saved'));
        } catch (error) {
            reset();
            toastApiError(error);
        }
    });

    return card;
}

function passwordCard(ctx) {
    const form = el(`
        <form class="card" novalidate>
            <div class="card-header"><h2>${esc(t('admin.profile.password'))}</h2></div>
            <div class="card-body">
                <div class="form-grid form-grid-2">
                    <div class="field">
                        <label class="field-label" for="password">${esc(t('admin.profile.new_password'))}<span class="req">*</span></label>
                        <input class="input" id="password" name="password" type="password"
                               minlength="8" required autocomplete="new-password">
                        <span class="field-hint">${esc(t('admin.validation.password_min'))}</span>
                    </div>
                    <div class="field">
                        <label class="field-label" for="confirm">${esc(t('admin.profile.confirm_password'))}<span class="req">*</span></label>
                        <input class="input" id="confirm" name="confirm" type="password"
                               required autocomplete="new-password">
                    </div>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary">${esc(t('admin.profile.change_password'))}</button>
            </div>
        </form>
    `);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFormErrors(form);

        const values = formValues(form);
        const errors = {};
        if (!values.password || values.password.length < 8) {
            errors.password = t('admin.validation.password_min');
        }
        if (values.password !== values.confirm) {
            errors.confirm = t('admin.validation.password_match');
        }
        if (Object.keys(errors).length) {
            showFormErrors(form, errors);
            return;
        }

        const button = form.querySelector('button[type=submit]');
        const reset = buttonBusy(button, t('admin.common.saving'));
        try {
            await apiPut(`user-management.php?user_id=${ctx.user.user_id}`,
                { password: values.password }, { salonScope: false });
            toastSuccess(t('admin.profile.password_changed'));
            form.reset();
            reset();
        } catch (error) {
            reset();
            toastApiError(error);
        }
    });

    return form;
}

function salonLanguageCard(ctx) {
    const current = ctx.salon.default_language || 'de';

    const form = el(`
        <form class="card" novalidate>
            <div class="card-header"><h2>${esc(t('admin.profile.salon_language'))}</h2></div>
            <div class="card-body">
                <p class="card-hint mb-4">${esc(t('admin.profile.salon_language_hint'))}</p>
                <div class="field" style="max-width:320px">
                    <label class="field-label" for="default_language">${esc(t('admin.salons.default_language'))}</label>
                    <select class="select" id="default_language" name="default_language">
                        <option value="de" ${current === 'de' ? 'selected' : ''}>Deutsch</option>
                        <option value="en" ${current === 'en' ? 'selected' : ''}>English</option>
                    </select>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary">${esc(t('admin.common.save'))}</button>
            </div>
        </form>
    `);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const values = formValues(form);
        const button = form.querySelector('button[type=submit]');
        const reset = buttonBusy(button, t('admin.common.saving'));
        try {
            await apiPut(`salon-management.php?salon_id=${ctx.salon.salon_id}`,
                { default_language: values.default_language }, { salonScope: false });
            toastSuccess(t('admin.profile.salon_language_saved'));
            reset();
        } catch (error) {
            reset();
            toastApiError(error);
        }
    });

    return form;
}
