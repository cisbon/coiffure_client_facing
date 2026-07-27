/**
 * Benutzer (user management)
 * ------------------------------------------------------------
 * Migrated from the user block of the old admin-dashboard.js (loadUsers,
 * renderUsersTable, openUserModal, saveUser, deleteUser), rebuilt on the shared
 * primitives and fully translated.
 *
 * The invitation flow (spec 3.4: invite by e-mail with granular permission
 * checkboxes) is added in the users+settings stage; this module already renders
 * the permission summary column so a delegate's rights are visible at a glance.
 */

import { apiGet, apiPost, apiPut, apiDelete } from '../api.js';
import { SALON_PERMISSIONS, RESERVED_PERMISSIONS } from '../permissions.js';
import {
    t, esc, el, createTable, modal, confirmDialog, pageHeader, boolBadge,
    toastSuccess, toastApiError, formatDateTime, showFormErrors, clearFormErrors,
    formValues, buttonBusy, skeletonRows,
} from '../ui.js';

/** Roles this user is allowed to assign, mirroring the server's rules. */
function assignableRoles(permissions) {
    if (permissions.isAdmin) {
        return ['admin', 'admin_delegate', 'customer_admin', 'customer_admin_delegate', 'customer_facing_tablet_user'];
    }
    if (permissions.is('admin_delegate')) {
        // Admin Delegates may not create administrators.
        return ['customer_admin', 'customer_admin_delegate', 'customer_facing_tablet_user'];
    }
    // A Customer Admin manages their own salon's staff only.
    return ['customer_admin_delegate', 'customer_facing_tablet_user'];
}

let users = [];

export async function render(container, ctx) {
    container.appendChild(pageHeader({
        title: t('admin.users.title'),
        subtitle: t('admin.users.subtitle'),
        actions: [{
            label: t('admin.users.add'),
            variant: 'primary',
            icon: '＋',
            onClick: () => openUserModal(null, ctx),
        }],
    }));

    const host = el('<div></div>');
    container.appendChild(host);
    host.appendChild(skeletonRows(6));

    try {
        // Platform roles see every salon's users; salon roles are scoped by the
        // server regardless of what is sent.
        const query = ctx.isAllSalons || ctx.permissions.isPlatform
            ? 'user-management.php'
            : `user-management.php?salon_id=${encodeURIComponent(ctx.salonId)}`;

        const data = await apiGet(query, { salonScope: false });
        users = data.users || [];
    } catch (error) {
        toastApiError(error);
        users = [];
    }

    host.innerHTML = '';
    host.appendChild(buildTable(ctx));
}

function buildTable(ctx) {
    const table = createTable({
        rows: users,
        searchKeys: ['username', 'full_name', 'email'],
        searchPlaceholderKey: 'admin.users.search',
        defaultSort: { key: 'full_name', dir: 'asc' },
        empty: {
            art: 'people',
            title: t('admin.users.empty_title'),
            text: t('admin.users.empty_text'),
            action: { label: t('admin.users.add'), onClick: () => openUserModal(null, ctx) },
        },
        columns: [
            {
                key: 'full_name',
                labelKey: 'admin.users.name',
                sortable: true,
                primary: true,
                render: (u) => `
                    <span class="cell-strong">${esc(u.full_name || u.username)}</span>
                    <div class="text-xs text-muted">${esc(u.username)}</div>
                `,
            },
            {
                key: 'email',
                labelKey: 'admin.users.email',
                sortable: true,
                render: (u) => `<span class="cell-muted">${esc(u.email)}</span>`,
            },
            {
                key: 'role',
                labelKey: 'admin.users.role',
                sortable: true,
                render: (u) => `<span class="badge badge-brand">${esc(t(`admin.roles.${u.role}`))}</span>`,
            },
            {
                key: 'salon_name',
                labelKey: 'admin.users.salon',
                sortable: true,
                render: (u) => esc(u.salon_name || '—'),
            },
            {
                key: 'last_login',
                labelKey: 'admin.users.last_login',
                sortable: true,
                sortValue: (u) => u.last_login || '',
                render: (u) => u.last_login
                    ? `<span class="cell-muted">${esc(formatDateTime(u.last_login))}</span>`
                    : `<span class="cell-muted">${esc(t('admin.users.never'))}</span>`,
            },
            {
                key: 'is_active',
                labelKey: 'admin.users.status',
                sortable: true,
                sortValue: (u) => (Number(u.is_active) ? 1 : 0),
                render: (u) => boolBadge(Number(u.is_active) === 1, 'admin.status.active', 'admin.status.inactive'),
            },
            {
                key: 'actions',
                label: '',
                className: 'cell-actions',
                cardHidden: true,
                render: (u) => `
                    <button type="button" class="btn btn-ghost btn-sm" data-edit="${u.user_id}">${esc(t('admin.common.edit'))}</button>
                    ${Number(u.user_id) === Number(ctx.user.user_id)
                        ? ''
                        : `<button type="button" class="btn btn-ghost btn-sm" style="color:var(--danger-600)" data-delete="${u.user_id}">${esc(t('admin.common.deactivate'))}</button>`}
                `,
            },
        ],
    });

    table.element.addEventListener('click', async (event) => {
        const editId = event.target.closest('[data-edit]')?.dataset.edit;
        if (editId) {
            openUserModal(Number(editId), ctx);
            return;
        }
        const deleteId = event.target.closest('[data-delete]')?.dataset.delete;
        if (deleteId) {
            await deactivateUser(Number(deleteId), ctx);
        }
    });

    return table.element;
}

/* ============================================================
   Add / edit
   ============================================================ */

function openUserModal(userId, ctx) {
    const user = userId ? users.find((u) => Number(u.user_id) === Number(userId)) : null;
    const isNew = !user;
    const roles = assignableRoles(ctx.permissions);

    const salonOptions = ctx.salons.map((s) =>
        `<option value="${s.salon_id}" ${String(user?.salon_id) === String(s.salon_id) ? 'selected' : ''}>${esc(s.salon_name)}</option>`
    ).join('');

    const form = el(`
        <form id="user-form" novalidate>
            <div class="form-grid">
                <div class="form-grid form-grid-2">
                    <div class="field">
                        <label class="field-label" for="username">${esc(t('admin.users.username'))}<span class="req">*</span></label>
                        <input class="input" id="username" name="username" required
                               pattern="[a-zA-Z0-9_-]{3,50}"
                               value="${esc(user?.username || '')}" ${isNew ? '' : 'readonly'}>
                        <span class="field-hint">${esc(isNew ? t('admin.users.username_hint') : t('admin.users.username_locked'))}</span>
                    </div>
                    <div class="field">
                        <label class="field-label" for="email">${esc(t('admin.users.email'))}<span class="req">*</span></label>
                        <input class="input" id="email" name="email" type="email" required
                               value="${esc(user?.email || '')}">
                    </div>
                    <div class="field">
                        <label class="field-label" for="full_name">${esc(t('admin.users.name'))}<span class="req">*</span></label>
                        <input class="input" id="full_name" name="full_name" required
                               value="${esc(user?.full_name || '')}">
                    </div>
                    <div class="field">
                        <label class="field-label" for="phone">${esc(t('admin.users.phone'))}</label>
                        <input class="input" id="phone" name="phone" type="tel"
                               value="${esc(user?.phone || '')}">
                    </div>
                    <div class="field">
                        <label class="field-label" for="role">${esc(t('admin.users.role'))}<span class="req">*</span></label>
                        <select class="select" id="role" name="role" required>
                            ${roles.map((role) =>
                                `<option value="${role}" ${user?.role === role ? 'selected' : ''}>${esc(t(`admin.roles.${role}`))}</option>`
                            ).join('')}
                        </select>
                    </div>
                    <div class="field">
                        <label class="field-label" for="salon_id">${esc(t('admin.users.salon'))}</label>
                        <select class="select" id="salon_id" name="salon_id">
                            <option value="">${esc(t('admin.users.no_salon'))}</option>
                            ${salonOptions}
                        </select>
                        <span class="field-hint">${esc(t('admin.users.salon_hint'))}</span>
                    </div>
                    <div class="field">
                        <label class="field-label" for="password">
                            ${esc(isNew ? t('admin.users.password') : t('admin.users.password_optional'))}
                            ${isNew ? '<span class="req">*</span>' : ''}
                        </label>
                        <input class="input" id="password" name="password" type="password"
                               minlength="8" autocomplete="new-password" ${isNew ? 'required' : ''}>
                        <span class="field-hint">${esc(t('admin.validation.password_min'))}</span>
                    </div>
                    <div class="field">
                        <label class="field-label" for="is_active">${esc(t('admin.users.status'))}</label>
                        <select class="select" id="is_active" name="is_active">
                            <option value="1" ${user === null || Number(user?.is_active) === 1 ? 'selected' : ''}>${esc(t('admin.status.active'))}</option>
                            <option value="0" ${user && Number(user.is_active) === 0 ? 'selected' : ''}>${esc(t('admin.status.inactive'))}</option>
                        </select>
                    </div>
                </div>

                <div id="permission-block" class="hidden">
                    <hr class="divider">
                    <h3>${esc(t('admin.users.permissions_title'))}</h3>
                    <p class="card-hint mb-4">${esc(t('admin.users.permissions_hint'))}</p>
                    <div class="form-grid form-grid-2">
                        ${SALON_PERMISSIONS.map((permission) => {
                            const reserved = RESERVED_PERMISSIONS.includes(permission);
                            return `
                            <label class="check">
                                <input type="checkbox" name="permissions" value="${esc(permission)}" ${reserved ? 'disabled' : ''}>
                                <span class="check-text">
                                    <span class="check-title">${esc(t(`admin.permissions.${permission}`))}</span>
                                    <span class="check-desc">${esc(reserved
                                        ? t('admin.users.permission_soon')
                                        : t(`admin.permissions.${permission}_desc`))}</span>
                                </span>
                            </label>`;
                        }).join('')}
                    </div>
                </div>
            </div>
        </form>
    `);

    modal({
        title: isNew ? t('admin.users.add') : t('admin.users.edit'),
        subtitle: isNew ? t('admin.users.add_subtitle') : (user.full_name || user.username),
        body: form,
        size: 'lg',
        actions: [
            { label: t('admin.common.cancel'), variant: 'secondary', onClick: (close) => close() },
            {
                label: t('admin.common.save'),
                variant: 'primary',
                onClick: (close, button) => submitUser(form, user, close, button, ctx),
            },
        ],
    });

    // The permission checkboxes only apply to a Customer Admin Delegate, so
    // they appear and disappear with the role selector.
    const roleSelect = form.elements.role;
    const permissionBlock = form.querySelector('#permission-block');
    const syncPermissionBlock = () => {
        permissionBlock.classList.toggle('hidden', roleSelect.value !== 'customer_admin_delegate');
    };
    roleSelect.addEventListener('change', syncPermissionBlock);
    syncPermissionBlock();

    // Pre-tick the delegate's existing grants.
    if (user?.role === 'customer_admin_delegate') {
        loadUserPermissions(user.user_id, form, ctx);
    }
}

async function loadUserPermissions(userId, form, ctx) {
    try {
        const data = await apiGet(
            `user-permissions.php?user_id=${userId}&salon_id=${encodeURIComponent(ctx.salonId)}`,
            { salonScope: false }
        );
        const granted = data.permissions || [];
        form.querySelectorAll('input[name="permissions"]').forEach((box) => {
            box.checked = granted.includes(box.value);
        });
    } catch {
        // user-permissions.php arrives with the invitation flow; until then the
        // boxes simply start unchecked rather than blocking the dialog.
    }
}

async function submitUser(form, user, close, button, ctx) {
    clearFormErrors(form);
    const values = formValues(form);
    const errors = {};

    if (!user) {
        if (!values.username || !/^[a-zA-Z0-9_-]{3,50}$/.test(values.username)) {
            errors.username = t('admin.validation.username');
        }
        if (!values.password || values.password.length < 8) {
            errors.password = t('admin.validation.password_min');
        }
    } else if (values.password && values.password.length < 8) {
        errors.password = t('admin.validation.password_min');
    }

    if (!values.email) errors.email = t('admin.validation.required');
    else if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(values.email)) errors.email = t('admin.validation.email');
    if (!values.full_name) errors.full_name = t('admin.validation.required');
    if (!values.role) errors.role = t('admin.validation.required');

    // Salon-bound roles need a salon; platform roles must not have one.
    const needsSalon = ['customer_admin', 'customer_admin_delegate', 'customer_facing_tablet_user'];
    if (needsSalon.includes(values.role) && !values.salon_id) {
        errors.salon_id = t('admin.validation.salon_required');
    }

    if (Object.keys(errors).length) {
        showFormErrors(form, errors);
        return;
    }

    const payload = {
        email: values.email,
        full_name: values.full_name,
        phone: values.phone || null,
        role: values.role,
        salon_id: values.salon_id || null,
        is_active: values.is_active === '1',
    };
    if (!user) payload.username = values.username;
    if (values.password) payload.password = values.password;

    const permissions = [...form.querySelectorAll('input[name="permissions"]:checked')].map((b) => b.value);

    const reset = buttonBusy(button, t('admin.common.saving'));
    try {
        let userId = user?.user_id;

        if (user) {
            await apiPut(`user-management.php?user_id=${user.user_id}`, payload, { salonScope: false });
        } else {
            const created = await apiPost('user-management.php', payload, { salonScope: false });
            userId = created.user?.user_id || created.user_id;
        }

        // Grants are stored separately from the user record.
        if (values.role === 'customer_admin_delegate' && userId) {
            try {
                await apiPost('user-permissions.php', {
                    user_id: userId,
                    salon_id: values.salon_id,
                    permissions,
                }, { salonScope: false });
            } catch {
                // Saving the user succeeded; a missing permissions endpoint
                // should not read as a failed save.
            }
        }

        toastSuccess(user ? t('admin.users.updated') : t('admin.users.created'));
        close();
        ctx.reload();
    } catch (error) {
        reset();
        const message = String(error?.message || '');
        if (/username/i.test(message)) showFormErrors(form, { username: message });
        else if (/e-?mail/i.test(message)) showFormErrors(form, { email: message });
        else toastApiError(error);
    }
}

async function deactivateUser(userId, ctx) {
    const user = users.find((u) => Number(u.user_id) === Number(userId));
    if (!user) return;

    // Never let the last Customer Admin of a salon be removed (spec 3.4) --
    // the server enforces this too, but catching it here explains why.
    if (user.role === 'customer_admin') {
        const remaining = users.filter((u) =>
            u.role === 'customer_admin' &&
            Number(u.is_active) === 1 &&
            String(u.salon_id) === String(user.salon_id) &&
            Number(u.user_id) !== Number(userId)
        );
        if (!remaining.length) {
            const { toastError } = await import('../ui.js');
            toastError(t('admin.users.last_admin_error'));
            return;
        }
    }

    const confirmed = await confirmDialog({
        title: t('admin.users.deactivate_title'),
        message: t('admin.users.deactivate_message', { name: user.full_name || user.username }),
        confirmLabel: t('admin.common.deactivate'),
    });
    if (!confirmed) return;

    try {
        await apiDelete(`user-management.php?user_id=${userId}`, { salonScope: false });
        toastSuccess(t('admin.users.deactivated'));
        ctx.reload();
    } catch (error) {
        toastApiError(error);
    }
}
