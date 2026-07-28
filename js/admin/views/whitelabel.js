/**
 * White-Label — Administrator only
 * ------------------------------------------------------------
 * Per-salon custom domain, outgoing mail server and brand colours, over
 * api/whitelabel.php.
 *
 * The SMTP password is write-only: the API reports whether one is stored but
 * never the value, so this screen shows "gesetzt" and leaves the field empty.
 * An empty field on save keeps the stored password, which is what makes it
 * safe to edit the from-address without re-entering credentials.
 */

import { apiGet, apiPost } from '../api.js';
import {
    t, esc, el, createTable, pageHeader, drawer, toastSuccess, toastApiError,
    formatDateTime, showFormErrors, clearFormErrors, formValues, buttonBusy,
    skeletonRows, forbiddenState, boolBadge,
} from '../ui.js';

export async function render(container, ctx) {
    container.appendChild(pageHeader({
        title: t('admin.whitelabel.title'),
        subtitle: t('admin.whitelabel.subtitle'),
    }));

    const host = el('<div></div>');
    container.appendChild(host);
    host.appendChild(skeletonRows(6));

    let salons = [];
    try {
        const data = await apiGet('whitelabel.php?action=list', { salonScope: false });
        salons = data.salons || [];
    } catch (error) {
        host.innerHTML = '';
        if (error.isForbidden) host.appendChild(forbiddenState());
        else toastApiError(error);
        return;
    }

    host.innerHTML = '';

    const table = createTable({
        rows: salons,
        searchKeys: ['salon_name', 'custom_domain', 'from_address'],
        searchPlaceholderKey: 'admin.whitelabel.search',
        defaultSort: { key: 'salon_name', dir: 'asc' },
        empty: {
            art: 'shop',
            title: t('admin.whitelabel.empty_title'),
            text: t('admin.whitelabel.empty_text'),
        },
        columns: [
            {
                key: 'salon_name',
                labelKey: 'admin.whitelabel.salon',
                sortable: true,
                primary: true,
                render: (row) => `<span class="cell-strong">${esc(row.salon_name)}</span>`,
            },
            {
                key: 'custom_domain',
                labelKey: 'admin.whitelabel.domain',
                sortable: true,
                render: (row) => (row.custom_domain
                    ? `<span class="cell-muted">${esc(row.custom_domain)}</span>
                       ${row.domain_verified
                            ? `<span class="badge badge-success">${esc(t('admin.whitelabel.verified'))}</span>`
                            : `<span class="badge badge-warning">${esc(t('admin.whitelabel.unverified'))}</span>`}`
                    : '<span class="cell-muted">—</span>'),
            },
            {
                key: 'from_address',
                labelKey: 'admin.whitelabel.from_address',
                sortable: true,
                render: (row) => `<span class="cell-muted">${esc(row.from_address || '—')}</span>`,
            },
            {
                key: 'has_smtp',
                labelKey: 'admin.whitelabel.smtp',
                sortable: true,
                sortValue: (row) => (row.has_smtp ? 1 : 0),
                render: (row) => boolBadge(row.has_smtp, 'admin.whitelabel.own_smtp', 'admin.whitelabel.default_smtp'),
            },
            {
                key: 'last_test_at',
                labelKey: 'admin.whitelabel.last_test',
                sortable: true,
                sortValue: (row) => row.last_test_at || '',
                render: (row) => {
                    if (!row.last_test_at) return '<span class="cell-muted">—</span>';
                    const badge = row.last_test_ok
                        ? `<span class="badge badge-success">${esc(t('admin.whitelabel.test_ok'))}</span>`
                        : `<span class="badge badge-danger">${esc(t('admin.whitelabel.test_failed'))}</span>`;
                    return `${badge}<div class="text-xs text-muted">${esc(formatDateTime(row.last_test_at))}</div>`;
                },
            },
            {
                key: 'actions',
                label: '',
                className: 'cell-actions',
                cardHidden: true,
                render: (row) => `<button type="button" class="btn btn-ghost btn-sm" data-edit="${row.salon_id}">${esc(t('admin.common.edit'))}</button>`,
            },
        ],
    });

    table.element.addEventListener('click', (event) => {
        const editId = event.target.closest('[data-edit]')?.dataset.edit;
        if (editId) openEditor(Number(editId), ctx);
    });

    host.appendChild(table.element);
}

async function openEditor(salonId, ctx) {
    let data;
    try {
        data = await apiGet(`whitelabel.php?salon_id=${salonId}`, { salonScope: false });
    } catch (error) {
        toastApiError(error);
        return;
    }

    const config = data.whitelabel || {};
    const form = el(`
        <form id="whitelabel-form" novalidate>
            <div class="stack">
                <div class="card">
                    <div class="card-header"><h2>${esc(t('admin.whitelabel.domain_section'))}</h2></div>
                    <div class="card-body">
                        <div class="field">
                            <label class="field-label" for="custom_domain">${esc(t('admin.whitelabel.domain'))}</label>
                            <input class="input" id="custom_domain" name="custom_domain"
                                   value="${esc(config.custom_domain || '')}"
                                   placeholder="dashboard.salon-beispiel.de" autocapitalize="none" spellcheck="false">
                            <span class="field-hint">${esc(t('admin.whitelabel.domain_hint'))}</span>
                        </div>
                        ${config.custom_domain ? `
                        <div class="alert alert-info mt-4">
                            <div>
                                <div class="alert-title">${esc(t('admin.whitelabel.dns_title'))}</div>
                                <div class="text-sm">${esc(t('admin.whitelabel.dns_text', { domain: config.custom_domain }))}</div>
                            </div>
                        </div>` : ''}
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2>${esc(t('admin.whitelabel.mail_section'))}</h2>
                            <p class="card-hint">${esc(t('admin.whitelabel.mail_hint'))}</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="form-grid form-grid-2">
                            <div class="field">
                                <label class="field-label" for="from_address">${esc(t('admin.whitelabel.from_address'))}</label>
                                <input class="input" id="from_address" name="from_address" type="email"
                                       value="${esc(config.from_address || '')}" placeholder="salon@beispiel.de">
                            </div>
                            <div class="field">
                                <label class="field-label" for="from_name">${esc(t('admin.whitelabel.from_name'))}</label>
                                <input class="input" id="from_name" name="from_name"
                                       value="${esc(config.from_name || '')}">
                            </div>
                            <div class="field">
                                <label class="field-label" for="smtp_host">${esc(t('admin.whitelabel.smtp_host'))}</label>
                                <input class="input" id="smtp_host" name="smtp_host"
                                       value="${esc(config.smtp_host || '')}" placeholder="smtp.beispiel.de"
                                       autocapitalize="none" spellcheck="false">
                                <span class="field-hint">${esc(t('admin.whitelabel.smtp_host_hint'))}</span>
                            </div>
                            <div class="form-grid form-grid-2">
                                <div class="field">
                                    <label class="field-label" for="smtp_port">${esc(t('admin.whitelabel.smtp_port'))}</label>
                                    <input class="input" id="smtp_port" name="smtp_port" type="number"
                                           min="1" max="65535" value="${Number(config.smtp_port || 587)}">
                                </div>
                                <div class="field">
                                    <label class="field-label" for="smtp_secure">${esc(t('admin.whitelabel.smtp_secure'))}</label>
                                    <select class="select" id="smtp_secure" name="smtp_secure">
                                        ${['tls', 'ssl', 'none'].map((mode) =>
                                            `<option value="${mode}" ${(config.smtp_secure || 'tls') === mode ? 'selected' : ''}>${esc(t(`admin.whitelabel.secure_${mode}`))}</option>`
                                        ).join('')}
                                    </select>
                                </div>
                            </div>
                            <div class="field">
                                <label class="field-label" for="smtp_username">${esc(t('admin.whitelabel.smtp_username'))}</label>
                                <input class="input" id="smtp_username" name="smtp_username"
                                       value="${esc(config.smtp_username || '')}" autocapitalize="none" spellcheck="false">
                            </div>
                            <div class="field">
                                <label class="field-label" for="smtp_password">${esc(t('admin.whitelabel.smtp_password'))}</label>
                                <input class="input" id="smtp_password" name="smtp_password" type="password"
                                       autocomplete="new-password" placeholder="${esc(config.smtp_password_set
                                           ? t('admin.whitelabel.password_stored')
                                           : t('admin.whitelabel.password_empty'))}">
                                <span class="field-hint">${esc(t('admin.whitelabel.password_hint'))}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h2>${esc(t('admin.whitelabel.colors_section'))}</h2></div>
                    <div class="card-body">
                        <p class="card-hint mb-4">${esc(t('admin.whitelabel.colors_hint'))}</p>
                        <div class="form-grid form-grid-2">
                            ${['primary_color', 'secondary_color'].map((field) => `
                                <div class="field">
                                    <label class="field-label" for="${field}_hex">${esc(t(`admin.whitelabel.${field}`))}</label>
                                    <div class="color-field">
                                        <input type="color" id="${field}" value="${esc(config[field] || '#2563EB')}"
                                               aria-label="${esc(t(`admin.whitelabel.${field}`))}">
                                        <input class="input" type="text" id="${field}_hex" name="${field}"
                                               value="${esc(config[field] || '')}" maxlength="7" placeholder="#RRGGBB">
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                </div>
            </div>
        </form>
    `);

    // Keep each picker and its hex box in step.
    ['primary_color', 'secondary_color'].forEach((field) => {
        const picker = form.querySelector(`#${field}`);
        const hex = form.querySelector(`#${field}_hex`);
        picker.addEventListener('input', () => { hex.value = picker.value.toUpperCase(); });
        hex.addEventListener('input', () => {
            if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) picker.value = hex.value;
        });
    });

    drawer({
        title: t('admin.whitelabel.title'),
        subtitle: data.salon?.salon_name || '',
        body: form,
        actions: [
            {
                label: t('admin.whitelabel.send_test'),
                variant: 'secondary',
                onClick: (close, button) => sendTest(salonId, button),
            },
            {
                label: t('admin.common.save'),
                variant: 'primary',
                onClick: async (close, button) => {
                    clearFormErrors(form);
                    const values = formValues(form);
                    const reset = buttonBusy(button, t('admin.common.saving'));
                    try {
                        await apiPost(`whitelabel.php?salon_id=${salonId}`, {
                            custom_domain: values.custom_domain,
                            from_address: values.from_address,
                            from_name: values.from_name,
                            smtp_host: values.smtp_host,
                            smtp_port: Number(values.smtp_port) || 587,
                            smtp_secure: values.smtp_secure,
                            smtp_username: values.smtp_username,
                            // Empty means "keep the stored password".
                            smtp_password: values.smtp_password,
                            primary_color: values.primary_color,
                            secondary_color: values.secondary_color,
                        }, { salonScope: false });
                        toastSuccess(t('admin.whitelabel.saved'));
                        close();
                        ctx.reload();
                    } catch (error) {
                        reset();
                        if (error.details?.fields) showFormErrors(form, error.details.fields);
                        else toastApiError(error);
                    }
                },
            },
        ],
    });
}

/**
 * Send a test mail through whatever this salon's configuration resolves to.
 * Save first: the test uses the stored configuration, not what is on screen.
 */
async function sendTest(salonId, button) {
    const reset = buttonBusy(button, t('admin.whitelabel.sending'));
    try {
        const result = await apiPost(`whitelabel.php?action=test_email&salon_id=${salonId}`, {}, { salonScope: false });
        reset();
        if (result.sent) {
            toastSuccess(t('admin.whitelabel.test_sent', { via: result.via || t('admin.whitelabel.default_smtp') }));
        } else {
            toastApiError(new Error(t('admin.whitelabel.test_failed_text')));
        }
    } catch (error) {
        reset();
        toastApiError(error);
    }
}
