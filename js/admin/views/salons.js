/**
 * Salons (Administrator & Admin Delegate)
 * ------------------------------------------------------------
 * Migrated from the salon block of the old admin-dashboard.js (loadSalons,
 * renderSalonsTable, openSalonModal, saveSalon, deleteSalon), rebuilt on the
 * shared table/modal primitives and fully translated.
 *
 * Two behaviour changes worth calling out:
 *
 *   1. The old "Salon Owner Account" and "Tablet Kiosk Account" sections
 *      existed in the markup but saveSalon() never read them, so no accounts
 *      were ever created. Those fields are now actually submitted.
 *   2. The owner is invited by e-mail and chooses their own password, rather
 *      than being given one typed here (which the API then mailed in the
 *      clear). The typed password now applies to the tablet kiosk only, which
 *      has no mailbox to invite.
 */

import { apiGet, apiPost, apiPut, apiDelete, apiDownload } from '../api.js';
import {
    t, esc, el, createTable, modal, confirmDialog, pageHeader, statusBadge,
    toastSuccess, toastError, toastApiError, formatDate, formatNumber, showFormErrors,
    clearFormErrors, formValues, buttonBusy, skeletonRows,
} from '../ui.js';

let salons = [];
let statusFilter = 'all';

export async function render(container, ctx) {
    container.appendChild(pageHeader({
        title: t('admin.salons.title'),
        subtitle: t('admin.salons.subtitle'),
        actions: [{
            label: t('admin.salons.add'),
            variant: 'primary',
            icon: '＋',
            onClick: () => openSalonModal(null, ctx),
        }],
    }));

    const host = el('<div></div>');
    container.appendChild(host);
    host.appendChild(skeletonRows(6));

    try {
        const data = await apiGet('salon-management.php', { salonScope: false });
        salons = data.salons || [];
    } catch (error) {
        toastApiError(error);
        salons = [];
    }

    host.innerHTML = '';
    host.appendChild(buildTable(ctx));
}

function buildTable(ctx) {
    // Status filter chips (spec 3.2: "Filter by status").
    const filters = el('<div class="row" style="margin-left:auto"></div>');
    const options = [
        ['all', t('admin.salons.filter_all')],
        ['active', t('admin.status.active')],
        ['trial', t('admin.status.trial')],
        ['suspended', t('admin.status.suspended')],
    ];

    let table;

    options.forEach(([value, label]) => {
        const count = value === 'all'
            ? salons.length
            : salons.filter((s) => salonStatus(s) === value).length;

        const chip = el(
            `<button type="button" class="chip ${statusFilter === value ? 'active' : ''}" data-status="${esc(value)}">
                ${esc(label)} <span class="text-xs">${count}</span>
            </button>`
        );
        chip.addEventListener('click', () => {
            statusFilter = value;
            filters.querySelectorAll('.chip').forEach((c) => {
                c.classList.toggle('active', c.dataset.status === value);
            });
            table.setRows(visibleSalons());
        });
        filters.appendChild(chip);
    });

    table = createTable({
        rows: visibleSalons(),
        searchKeys: ['salon_name', 'email', 'subdomain'],
        searchPlaceholderKey: 'admin.salons.search',
        toolbar: filters,
        defaultSort: { key: 'salon_name', dir: 'asc' },
        rowClick: (salon) => openSalonModal(salon.salon_id, ctx),
        empty: {
            art: 'shop',
            title: t('admin.salons.empty_title'),
            text: t('admin.salons.empty_text'),
            action: { label: t('admin.salons.add'), onClick: () => openSalonModal(null, ctx) },
        },
        columns: [
            {
                key: 'salon_name',
                labelKey: 'admin.salons.name',
                sortable: true,
                primary: true,
                render: (s) => `<span class="cell-strong">${esc(s.salon_name)}</span>`,
            },
            {
                key: 'subdomain',
                labelKey: 'admin.salons.subdomain',
                sortable: true,
                render: (s) => s.subdomain
                    ? `<span class="text-sm text-muted">${esc(s.subdomain)}</span>`
                    : '<span class="cell-muted">—</span>',
            },
            {
                key: 'status',
                labelKey: 'admin.salons.status',
                sortable: true,
                sortValue: (s) => salonStatus(s),
                render: (s) => statusBadge(salonStatus(s)),
            },
            {
                key: 'created_at',
                labelKey: 'admin.salons.created',
                sortable: true,
                sortValue: (s) => s.created_at || '',
                render: (s) => `<span class="cell-muted">${esc(formatDate(s.created_at))}</span>`,
            },
            {
                key: 'customer_count',
                labelKey: 'admin.salons.customers',
                sortable: true,
                className: 'cell-num',
                sortValue: (s) => Number(s.customer_count || 0),
                render: (s) => formatNumber(s.customer_count || 0),
            },
            {
                key: 'user_count',
                labelKey: 'admin.salons.users',
                sortable: true,
                className: 'cell-num',
                sortValue: (s) => Number(s.user_count || 0),
                render: (s) => formatNumber(s.user_count || 0),
            },
            {
                key: 'actions',
                label: '',
                className: 'cell-actions',
                cardHidden: true,
                render: (s) => `
                    <button type="button" class="btn btn-ghost btn-sm" data-edit="${s.salon_id}">${esc(t('admin.common.edit'))}</button>
                    <button type="button" class="btn btn-ghost btn-sm" data-support="${s.salon_id}">${esc(t('admin.impersonation.start'))}</button>
                    <button type="button" class="btn btn-ghost btn-sm" data-export="${s.salon_id}">${esc(t('admin.salons.export'))}</button>
                    <button type="button" class="btn btn-ghost btn-sm" style="color:var(--danger-600)" data-delete="${s.salon_id}">${esc(t('admin.common.delete'))}</button>
                `,
            },
        ],
    });

    // Delegated so the handlers survive re-renders (sort, search, paging).
    table.element.addEventListener('click', async (event) => {
        const editId = event.target.closest('[data-edit]')?.dataset.edit;
        if (editId) {
            event.stopPropagation();
            openSalonModal(Number(editId), ctx);
            return;
        }

        const supportId = event.target.closest('[data-support]')?.dataset.support;
        if (supportId) {
            event.stopPropagation();
            await startSupportSession(Number(supportId));
            return;
        }

        const exportId = event.target.closest('[data-export]')?.dataset.export;
        if (exportId) {
            event.stopPropagation();
            await exportSalon(Number(exportId));
            return;
        }

        const deleteId = event.target.closest('[data-delete]')?.dataset.delete;
        if (deleteId) {
            event.stopPropagation();
            await deleteSalon(Number(deleteId), ctx, table);
        }
    });

    return table.element;
}

/**
 * Take over a salon's account for support.
 *
 * The current session token is replaced, so the administrator is signed in as
 * the salon until they end the session -- which is why it is confirmed first
 * and why the dashboard shows a banner for the whole time.
 */
async function startSupportSession(salonId) {
    const salon = salons.find((s) => Number(s.salon_id) === Number(salonId));

    const confirmed = await confirmDialog({
        title: t('admin.impersonation.start'),
        message: t('admin.impersonation.start_confirm', { salon: salon?.salon_name || '' }),
        confirmLabel: t('admin.impersonation.start'),
        variant: 'primary',
    });
    if (!confirmed) return;

    try {
        const result = await apiPost('impersonate.php', { salon_id: salonId }, { salonScope: false });
        localStorage.setItem('session_token', result.session_token);
        localStorage.setItem('user_data', JSON.stringify(result.user));
        // Full reload rather than a re-render: everything the shell holds --
        // permissions, salon list, nav -- belongs to the other identity now.
        window.location.replace('admin-dashboard.html');
    } catch (error) {
        toastApiError(error);
    }
}

/** Download everything held about a salon, for a GDPR request or a handover. */
async function exportSalon(salonId) {
    const salon = salons.find((s) => Number(s.salon_id) === Number(salonId));

    const confirmed = await confirmDialog({
        title: t('admin.salons.export_title'),
        message: t('admin.salons.export_message', { salon: salon?.salon_name || '' }),
        confirmLabel: t('admin.salons.export'),
        variant: 'primary',
    });
    if (!confirmed) return;

    try {
        await apiDownload(
            `salon-export.php?salon_id=${salonId}&format=json`,
            `salon-${salonId}-export.json`,
            { salonScope: false }
        );
        toastSuccess(t('admin.salons.exported'));
    } catch (error) {
        toastApiError(error);
    }
}

/**
 * Prefer the explicit status column (migration 018); fall back to the legacy
 * is_active flag so the view still works before that migration is applied.
 */
function salonStatus(salon) {
    if (salon.status) return salon.status;
    return Number(salon.is_active) === 1 ? 'active' : 'suspended';
}

function visibleSalons() {
    if (statusFilter === 'all') return salons;
    return salons.filter((s) => salonStatus(s) === statusFilter);
}

/* ============================================================
   Add / edit
   ============================================================ */

function openSalonModal(salonId, ctx) {
    const salon = salonId ? salons.find((s) => Number(s.salon_id) === Number(salonId)) : null;
    const isNew = !salon;

    const form = el(`
        <form id="salon-form" novalidate>
            <div class="form-grid">
                <div class="form-grid form-grid-2">
                    <div class="field">
                        <label class="field-label" for="salon_name">${esc(t('admin.salons.name'))}<span class="req">*</span></label>
                        <input class="input" id="salon_name" name="salon_name" required
                               value="${esc(salon?.salon_name || '')}">
                    </div>
                    <div class="field">
                        <label class="field-label" for="email">${esc(t('admin.salons.email'))}<span class="req">*</span></label>
                        <input class="input" id="email" name="email" type="email" required
                               value="${esc(salon?.email || '')}">
                    </div>
                    <div class="field">
                        <label class="field-label" for="phone">${esc(t('admin.salons.phone'))}<span class="req">*</span></label>
                        <input class="input" id="phone" name="phone" type="tel" required
                               value="${esc(salon?.phone || '')}">
                    </div>
                    <div class="field">
                        <label class="field-label" for="default_language">${esc(t('admin.salons.default_language'))}</label>
                        <select class="select" id="default_language" name="default_language">
                            <option value="de" ${(salon?.default_language || 'de') === 'de' ? 'selected' : ''}>Deutsch</option>
                            <option value="en" ${salon?.default_language === 'en' ? 'selected' : ''}>English</option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field-label" for="subdomain">${esc(t('admin.salons.subdomain'))}</label>
                        <input class="input" id="subdomain" name="subdomain"
                               value="${esc(salon?.subdomain || '')}"
                               placeholder="${esc(t('admin.salons.subdomain_auto'))}">
                        <span class="field-hint">${esc(t('admin.salons.subdomain_hint'))}</span>
                    </div>
                    <div class="field">
                        <label class="field-label" for="currency">${esc(t('admin.salons.currency'))}</label>
                        <select class="select" id="currency" name="currency">
                            ${['EUR', 'CHF', 'GBP', 'USD'].map((code) =>
                                `<option value="${code}" ${(salon?.currency || 'EUR') === code ? 'selected' : ''}>${code}</option>`
                            ).join('')}
                        </select>
                    </div>
                </div>

                <div class="field">
                    <label class="field-label" for="address">${esc(t('admin.salons.address'))}</label>
                    <textarea class="textarea" id="address" name="address" rows="2">${esc(salon?.address || '')}</textarea>
                </div>

                <div class="form-grid form-grid-2">
                    <div class="field">
                        <label class="field-label" for="policy_version">${esc(t('admin.salons.policy_version'))}<span class="req">*</span></label>
                        <input class="input" id="policy_version" name="policy_version" required
                               value="${esc(salon?.policy_version || '1.0')}">
                    </div>
                    ${!isNew ? `
                    <div class="field">
                        <label class="field-label" for="status">${esc(t('admin.salons.status'))}</label>
                        <select class="select" id="status" name="status">
                            <option value="active" ${salonStatus(salon) === 'active' ? 'selected' : ''}>${esc(t('admin.status.active'))}</option>
                            <option value="trial" ${salonStatus(salon) === 'trial' ? 'selected' : ''}>${esc(t('admin.status.trial'))}</option>
                            <option value="suspended" ${salonStatus(salon) === 'suspended' ? 'selected' : ''}>${esc(t('admin.status.suspended'))}</option>
                        </select>
                    </div>` : ''}
                </div>

                ${isNew ? `
                <hr class="divider">
                <div>
                    <h3>${esc(t('admin.salons.owner_section'))}</h3>
                    <p class="card-hint">${esc(t('admin.salons.owner_hint'))}</p>
                </div>
                <div class="form-grid form-grid-2">
                    <div class="field">
                        <label class="field-label" for="owner_email">${esc(t('admin.salons.owner_email'))}<span class="req">*</span></label>
                        <input class="input" id="owner_email" name="owner_email" type="email" required
                               placeholder="inhaberin@salon.de">
                    </div>
                    <div class="field">
                        <label class="field-label" for="owner_full_name">${esc(t('admin.salons.owner_name'))}<span class="req">*</span></label>
                        <input class="input" id="owner_full_name" name="owner_full_name" required>
                    </div>
                    <div class="field">
                        <label class="field-label" for="tablet_username">${esc(t('admin.salons.tablet_username'))}<span class="req">*</span></label>
                        <input class="input" id="tablet_username" name="tablet_username" required
                               pattern="[a-zA-Z0-9_\\-]{3,50}" placeholder="salon_tablet">
                        <span class="field-hint">${esc(t('admin.salons.tablet_username_hint'))}</span>
                    </div>
                    <div class="field">
                        <label class="field-label" for="initial_password">${esc(t('admin.salons.tablet_password'))}<span class="req">*</span></label>
                        <input class="input" id="initial_password" name="initial_password" type="text"
                               minlength="8" required autocomplete="new-password">
                        <span class="field-hint">${esc(t('admin.salons.tablet_password_hint'))}</span>
                    </div>
                </div>` : ''}
            </div>
        </form>
    `);

    const dialog = modal({
        title: isNew ? t('admin.salons.add') : t('admin.salons.edit'),
        subtitle: isNew ? t('admin.salons.add_subtitle') : salon.salon_name,
        body: form,
        size: 'lg',
        actions: [
            { label: t('admin.common.cancel'), variant: 'secondary', onClick: (close) => close() },
            {
                label: t('admin.common.save'),
                variant: 'primary',
                onClick: (close, button) => submitSalon(form, salon, close, button, ctx),
            },
        ],
    });

    // Suggest a subdomain from the salon name while the operator types, but
    // never overwrite something they entered themselves.
    if (isNew) {
        const nameInput = form.elements.salon_name;
        const subdomainInput = form.elements.subdomain;
        let subdomainTouched = false;
        subdomainInput.addEventListener('input', () => { subdomainTouched = true; });
        nameInput.addEventListener('input', () => {
            if (subdomainTouched) return;
            subdomainInput.value = slugify(nameInput.value);
        });
    }

    return dialog;
}

function slugify(value) {
    return String(value)
        .toLowerCase()
        .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 40);
}

async function submitSalon(form, salon, close, button, ctx) {
    clearFormErrors(form);
    const values = formValues(form);
    const errors = {};

    if (!values.salon_name) errors.salon_name = t('admin.validation.required');
    if (!values.email) errors.email = t('admin.validation.required');
    else if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(values.email)) errors.email = t('admin.validation.email');
    if (!values.phone) errors.phone = t('admin.validation.required');
    if (!values.policy_version) errors.policy_version = t('admin.validation.required');

    if (values.subdomain && !/^[a-z0-9][a-z0-9-]{1,62}$/.test(values.subdomain)) {
        errors.subdomain = t('admin.validation.subdomain');
    }

    // Onboarding fields are only present when creating.
    if (!salon) {
        if (!values.owner_email) errors.owner_email = t('admin.validation.required');
        else if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(values.owner_email)) errors.owner_email = t('admin.validation.email');

        if (!values.owner_full_name || values.owner_full_name.length < 2) {
            errors.owner_full_name = t('admin.validation.required');
        }
        if (!values.tablet_username || !/^[a-zA-Z0-9_-]{3,50}$/.test(values.tablet_username)) {
            errors.tablet_username = t('admin.validation.username');
        }
        if (!values.initial_password || values.initial_password.length < 8) {
            errors.initial_password = t('admin.validation.password_min');
        }
    }

    if (Object.keys(errors).length) {
        showFormErrors(form, errors);
        return;
    }

    const reset = buttonBusy(button, t('admin.common.saving'));
    try {
        if (salon) {
            await apiPut(`salon-management.php?salon_id=${salon.salon_id}`, values, { salonScope: false });
            toastSuccess(t('admin.salons.updated'));
        } else {
            const result = await apiPost('salon-management.php', values, { salonScope: false });
            // The owner is invited rather than handed a password, so say what
            // happened to that invitation instead of a bare "created".
            const invitation = result.owner_invitation;
            if (result.owner_linked) {
                // The address already had a salon account, so they were simply
                // attached to this salon as well -- no invitation needed.
                toastSuccess(t('admin.salons.owner_linked', { email: result.owner_linked.email }));
            } else if (!invitation) {
                toastSuccess(t('admin.salons.created'));
            } else if (invitation.email_sent) {
                toastSuccess(t('admin.salons.invited', { email: invitation.email }));
            } else {
                toastError(t('admin.salons.invite_pending', { email: invitation.email }));
            }
        }
        close();
        ctx.reload();
    } catch (error) {
        reset();

        // Prefer what the server said the problem was. Guessing the field from
        // the message text put "Owner email already exists" on the salon's own
        // e-mail field, which sent people looking in the wrong place.
        if (error.details?.fields) {
            showFormErrors(form, error.details.fields);
            return;
        }

        const message = String(error?.message || '');
        if (/subdomain/i.test(message)) showFormErrors(form, { subdomain: message });
        else if (/owner/i.test(message)) showFormErrors(form, { owner_email: message });
        else if (/e-?mail/i.test(message)) showFormErrors(form, { email: message });
        else toastApiError(error);
    }
}

async function deleteSalon(salonId, ctx) {
    const salon = salons.find((s) => Number(s.salon_id) === Number(salonId));
    if (!salon) return;

    const confirmed = await confirmDialog({
        title: t('admin.salons.delete_title'),
        message: t('admin.salons.delete_message', { name: salon.salon_name }),
        confirmLabel: t('admin.common.delete'),
    });
    if (!confirmed) return;

    try {
        await apiDelete(`salon-management.php?salon_id=${salonId}`, { salonScope: false });
        toastSuccess(t('admin.salons.deleted'));
        ctx.reload();
    } catch (error) {
        toastApiError(error);
    }
}
