/**
 * Benutzer (user management)
 * ------------------------------------------------------------
 * Migrated from the user block of the old admin-dashboard.js (loadUsers,
 * renderUsersTable, openUserModal, saveUser, deleteUser), rebuilt on the shared
 * primitives and fully translated.
 *
 * Two ways to add someone, deliberately kept apart (spec 3.4):
 *
 *   Einladen   the normal path for a person. api/user-invite.php mails a
 *              one-time link; the invitee chooses their own password through
 *              set-password.html, so no password ever travels by e-mail.
 *   Anlegen    creates an account with a password typed here. Kept for the
 *              shared tablet kiosk account, which has no mailbox of its own.
 */

import { apiGet, apiPost, apiPut, apiDelete } from '../api.js';
import { SALON_PERMISSIONS, RESERVED_PERMISSIONS } from '../permissions.js';
import {
    t, esc, el, createTable, modal, confirmDialog, pageHeader, boolBadge,
    statusBadge, toastSuccess, toastError, toastApiError, formatDate,
    formatDateTime, showFormErrors, clearFormErrors, formValues, buttonBusy,
    skeletonRows,
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

/**
 * Roles that can be invited by e-mail. The kiosk account is excluded on
 * purpose: it is a shared device login with no mailbox, so it goes through the
 * "Anlegen" dialog instead.
 */
function invitableRoles(permissions) {
    return assignableRoles(permissions).filter((role) => role !== 'customer_facing_tablet_user');
}

/**
 * Salon ids where this user may manage users.
 *
 * Reads permissions_by_salon through the Permissions helper, but tolerates its
 * absence: js/admin/** are separate ES modules with no cache busting, so a
 * browser can briefly hold a stale permissions.js against a fresh users.js. A
 * blank dialog would be a baffling way to find that out.
 */
function manageableSalonIds(ctx) {
    if (typeof ctx.permissions.salonsWith === 'function') {
        return ctx.permissions.salonsWith('manage_users');
    }
    const bySalon = ctx.permissions.bySalon || {};
    return Object.keys(bySalon).filter((id) => (bySalon[id] || []).includes('manage_users'));
}

let users = [];
let invitations = [];

export async function render(container, ctx) {
    // Invitations belong to exactly one salon, so they are only offered once a
    // salon is picked in the top bar.
    const canInvite = !ctx.isAllSalons && Boolean(ctx.salonId);

    const actions = [];
    if (canInvite) {
        actions.push({
            label: t('admin.users.invite'),
            variant: 'primary',
            icon: '✉',
            onClick: () => openInviteModal(ctx),
        });
    }
    actions.push({
        label: t('admin.users.add'),
        variant: canInvite ? 'secondary' : 'primary',
        icon: '＋',
        onClick: () => openUserModal(null, ctx),
    });

    container.appendChild(pageHeader({
        title: t('admin.users.title'),
        subtitle: t('admin.users.subtitle'),
        actions,
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

    invitations = [];
    if (canInvite) {
        try {
            const data = await apiGet(
                `user-invite.php?salon_id=${encodeURIComponent(ctx.salonId)}`,
                { salonScope: false }
            );
            invitations = data.invitations || [];
        } catch {
            // A database without migration 017, or a caller without
            // manage_users, simply gets no invitation panel -- the user list
            // above is still perfectly usable.
        }
    }

    host.innerHTML = '';
    host.appendChild(buildTable(ctx));
    if (canInvite) {
        host.appendChild(buildInvitations(ctx));
    }
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
   Offene Einladungen
   ============================================================ */

function buildInvitations(ctx) {
    const card = el(`
        <div class="card mt-6">
            <div class="card-header">
                <div>
                    <h2>${esc(t('admin.users.invitations_title'))}</h2>
                    <p class="card-hint">${esc(t('admin.users.invitations_hint'))}</p>
                </div>
            </div>
            <div class="card-body" id="invitation-body"></div>
        </div>
    `);

    const body = card.querySelector('#invitation-body');

    if (!invitations.length) {
        body.appendChild(el(
            `<p class="text-sm text-muted">${esc(t('admin.users.invitations_empty'))}</p>`
        ));
        return card;
    }

    const table = createTable({
        rows: invitations,
        pageSize: 10,
        searchKeys: ['email', 'full_name'],
        searchPlaceholderKey: 'admin.users.invitations_search',
        defaultSort: { key: 'created_at', dir: 'desc' },
        columns: [
            {
                key: 'email',
                labelKey: 'admin.users.email',
                sortable: true,
                primary: true,
                render: (inv) => `
                    <span class="cell-strong">${esc(inv.email)}</span>
                    <div class="text-xs text-muted">${esc(inv.full_name || '')}</div>
                `,
            },
            {
                key: 'role',
                labelKey: 'admin.users.role',
                sortable: true,
                render: (inv) => `<span class="badge badge-brand">${esc(t(`admin.roles.${inv.role}`))}</span>`,
            },
            {
                key: 'status',
                labelKey: 'admin.users.status',
                sortable: true,
                render: (inv) => statusBadge(inv.status),
            },
            {
                key: 'expires_at',
                labelKey: 'admin.users.invitation_expires',
                sortable: true,
                sortValue: (inv) => inv.expires_at || '',
                render: (inv) => `<span class="cell-muted">${esc(inv.expires_at ? formatDate(inv.expires_at) : '—')}</span>`,
            },
            {
                key: 'created_at',
                labelKey: 'admin.users.invitation_sent',
                sortable: true,
                sortValue: (inv) => inv.created_at || '',
                render: (inv) => `<span class="cell-muted">${esc(formatDateTime(inv.created_at))}</span>`,
            },
            {
                key: 'actions',
                label: '',
                className: 'cell-actions',
                cardHidden: true,
                render: (inv) => (inv.status === 'accepted' ? '' : `
                    <button type="button" class="btn btn-ghost btn-sm" data-resend="${inv.invitation_id}">${esc(t('admin.users.resend'))}</button>
                    <button type="button" class="btn btn-ghost btn-sm" style="color:var(--danger-600)" data-revoke="${inv.invitation_id}">${esc(t('admin.users.revoke'))}</button>
                `),
            },
        ],
    });

    table.element.addEventListener('click', async (event) => {
        const resendId = event.target.closest('[data-resend]')?.dataset.resend;
        if (resendId) return resendInvitation(Number(resendId), ctx);

        const revokeId = event.target.closest('[data-revoke]')?.dataset.revoke;
        if (revokeId) return revokeInvitation(Number(revokeId), ctx);
    });

    body.appendChild(table.element);
    return card;
}

function openInviteModal(ctx) {
    const roles = invitableRoles(ctx.permissions);

    const form = el(`
        <form id="invite-form" novalidate>
            <div class="form-grid">
                <div class="form-grid form-grid-2">
                    <div class="field">
                        <label class="field-label" for="invite_email">${esc(t('admin.users.email'))}<span class="req">*</span></label>
                        <input class="input" id="invite_email" name="email" type="email" required
                               autocapitalize="none" spellcheck="false">
                    </div>
                    <div class="field">
                        <label class="field-label" for="invite_name">${esc(t('admin.users.name'))}<span class="req">*</span></label>
                        <input class="input" id="invite_name" name="full_name" required>
                    </div>
                </div>
                <div class="field">
                    <label class="field-label" for="invite_role">${esc(t('admin.users.role'))}<span class="req">*</span></label>
                    <select class="select" id="invite_role" name="role" required>
                        ${roles.map((role) =>
                            `<option value="${role}" ${role === 'customer_admin_delegate' ? 'selected' : ''}>${esc(t(`admin.roles.${role}`))}</option>`
                        ).join('')}
                    </select>
                    <span class="field-hint">${esc(t('admin.users.invite_role_hint'))}</span>
                </div>

                <div id="invite-permissions">
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
        title: t('admin.users.invite'),
        subtitle: t('admin.users.invite_subtitle', { salon: ctx.salon?.salon_name || '' }),
        body: form,
        size: 'lg',
        actions: [
            { label: t('admin.common.cancel'), variant: 'secondary', onClick: (close) => close() },
            {
                label: t('admin.users.invite_send'),
                variant: 'primary',
                onClick: (close, button) => submitInvite(form, close, button, ctx),
            },
        ],
    });

    // Granular rights only exist for a Customer Admin Delegate; every other
    // role derives its permissions from the role itself.
    const roleSelect = form.elements.role;
    const block = form.querySelector('#invite-permissions');
    const sync = () => block.classList.toggle('hidden', roleSelect.value !== 'customer_admin_delegate');
    roleSelect.addEventListener('change', sync);
    sync();
}

async function submitInvite(form, close, button, ctx) {
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

    const payload = {
        email: values.email,
        full_name: values.full_name,
        role: values.role,
        permissions: [...form.querySelectorAll('input[name="permissions"]:checked')].map((b) => b.value),
    };

    const reset = buttonBusy(button, t('admin.common.saving'));
    try {
        const result = await apiPost(
            `user-invite.php?salon_id=${encodeURIComponent(ctx.salonId)}`,
            payload,
            { salonScope: false }
        );
        close();
        announceInvitation(result, values.email, ctx);
    } catch (error) {
        reset();
        if (error.details?.fields) showFormErrors(form, error.details.fields);
        else toastApiError(error);
    }
}

/**
 * Report the outcome. When the mail went out a toast is enough; when it did
 * not, the link is shown so the admin can pass it on some other way rather
 * than being stuck with an invitation nobody can use.
 */
function announceInvitation(result, email, ctx) {
    if (result.email_sent) {
        toastSuccess(t('admin.users.invite_sent', { email }));
        ctx.reload();
        return;
    }

    const body = el(`
        <div>
            <div class="alert alert-warning mb-4">
                <div>
                    <div class="alert-title">${esc(t('admin.users.invite_mail_failed_title'))}</div>
                    <div class="text-sm">${esc(t('admin.users.invite_mail_failed_text', { email }))}</div>
                </div>
            </div>
            <div class="field">
                <label class="field-label" for="invite-link">${esc(t('admin.users.invite_link'))}</label>
                <input class="input" id="invite-link" readonly value="${esc(result.accept_url || '')}">
                <span class="field-hint">${esc(t('admin.users.invite_link_hint'))}</span>
            </div>
        </div>
    `);

    modal({
        title: t('admin.users.invite'),
        body,
        actions: [
            {
                label: t('admin.users.copy_link'),
                variant: 'secondary',
                onClick: async () => {
                    const input = body.querySelector('#invite-link');
                    input.select();
                    try {
                        await navigator.clipboard.writeText(input.value);
                        toastSuccess(t('admin.users.link_copied'));
                    } catch {
                        // Clipboard access can be refused; the text is selected
                        // either way, so Ctrl+C still works.
                        toastError(t('admin.users.copy_failed'));
                    }
                },
            },
            { label: t('admin.common.close'), variant: 'primary', onClick: (close) => { close(); ctx.reload(); } },
        ],
    });
}

async function resendInvitation(invitationId, ctx) {
    const invitation = invitations.find((i) => Number(i.invitation_id) === Number(invitationId));
    try {
        const result = await apiPost(
            `user-invite.php?action=resend&salon_id=${encodeURIComponent(ctx.salonId)}`,
            { invitation_id: invitationId },
            { salonScope: false }
        );
        announceInvitation(result, invitation?.email || '', ctx);
    } catch (error) {
        toastApiError(error);
    }
}

async function revokeInvitation(invitationId, ctx) {
    const invitation = invitations.find((i) => Number(i.invitation_id) === Number(invitationId));

    const confirmed = await confirmDialog({
        title: t('admin.users.revoke_title'),
        message: t('admin.users.revoke_message', { email: invitation?.email || '' }),
        confirmLabel: t('admin.users.revoke'),
    });
    if (!confirmed) return;

    try {
        await apiDelete(
            `user-invite.php?invitation_id=${invitationId}&salon_id=${encodeURIComponent(ctx.salonId)}`,
            { salonScope: false }
        );
        toastSuccess(t('admin.users.revoked'));
        ctx.reload();
    } catch (error) {
        toastApiError(error);
    }
}

/* ============================================================
   Add / edit
   ============================================================ */

/**
 * A button that does nothing is the worst failure mode there is: an exception
 * while building the dialog left no modal and no message. Report it instead.
 */
function openUserModal(userId, ctx) {
    try {
        buildUserModal(userId, ctx);
    } catch (error) {
        console.error('openUserModal failed', error);
        toastError(t('admin.errors.generic'));
    }
}

function buildUserModal(userId, ctx) {
    const user = userId ? users.find((u) => Number(u.user_id) === Number(userId)) : null;
    const isNew = !user;
    const roles = assignableRoles(ctx.permissions);

    // Which salons may this user place someone in?
    //
    //   admin / admin_delegate  every salon, always -- they administer the
    //                           platform, so the chooser is never hidden from
    //                           them even when only one salon exists yet.
    //   salon roles             the salons where they hold manage_users.
    //
    // The dropdown appears when that set has more than one entry; with exactly
    // one there is nothing to choose, so it is shown read-only. A dropdown
    // defaulting to "Kein Salon" invited people to create a user belonging to
    // nobody. The server re-checks both the permission and the salon, so this
    // is presentation only.
    const manageableSalons = ctx.permissions.isPlatform
        ? ctx.salons
        : ctx.salons.filter((s) => manageableSalonIds(ctx).includes(String(s.salon_id)));
    const manageableIds = manageableSalons.map((s) => String(s.salon_id));
    const canChooseSalon = ctx.permissions.isPlatform || manageableSalons.length > 1;

    // Editing keeps the user's own salon; creating defaults to the one in the
    // top bar, falling back to the only salon they manage.
    const assignedSalonId = user?.salon_id
        ?? (manageableIds.includes(String(ctx.salonId))
            ? ctx.salonId
            // With "Alle Salons" in the top bar an administrator has no salon
            // in scope. Preselecting whichever happens to be first would let a
            // mis-click file someone under the wrong salon, so they start on
            // "Kein Salon" and pick deliberately. A salon role has only one
            // candidate, so there is nothing to mis-pick.
            : (ctx.permissions.isPlatform ? null : (manageableSalons[0]?.salon_id ?? null)));
    const assignedSalonName =
        ctx.salons.find((s) => String(s.salon_id) === String(assignedSalonId))?.salon_name
        || user?.salon_name
        || ctx.salon?.salon_name
        || '—';

    const salonOptions = manageableSalons.map((s) =>
        `<option value="${s.salon_id}" ${String(assignedSalonId) === String(s.salon_id) ? 'selected' : ''}>${esc(s.salon_name)}</option>`
    ).join('');

    const form = el(`
        <form id="user-form" novalidate>
            <div class="form-grid">
                <div class="form-grid form-grid-2">
                    <div class="field">
                        <label class="field-label" for="username">${esc(t('admin.users.username'))}<span class="req">*</span></label>
                        <input class="input" id="username" name="username" required
                               pattern="[a-zA-Z0-9_\\-]{3,50}"
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
                    ${canChooseSalon ? `
                    <div class="field">
                        <label class="field-label" for="salon_id">${esc(t('admin.users.salon'))}</label>
                        <select class="select" id="salon_id" name="salon_id">
                            ${ctx.permissions.isPlatform
                                ? `<option value="" ${assignedSalonId ? '' : 'selected'}>${esc(t('admin.users.no_salon'))}</option>`
                                : ''}
                            ${salonOptions}
                        </select>
                        <span class="field-hint">${esc(t('admin.users.salon_hint'))}</span>
                    </div>` : `
                    <div class="field">
                        <span class="field-label">${esc(t('admin.users.salon'))}</span>
                        <p class="field-static">${esc(assignedSalonName)}</p>
                        <span class="field-hint">${esc(t('admin.users.salon_fixed_hint'))}</span>
                    </div>`}
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
    if (user?.role === 'customer_admin_delegate' && !ctx.isAllSalons && ctx.salonId) {
        loadUserPermissions(user.user_id, form, ctx);
    }
}

async function loadUserPermissions(userId, form, ctx) {
    try {
        const data = await apiGet(
            `user-invite.php?action=permissions&user_id=${userId}&salon_id=${encodeURIComponent(ctx.salonId)}`,
            { salonScope: false }
        );
        const granted = data.permissions || [];
        form.querySelectorAll('input[name="permissions"]').forEach((box) => {
            box.checked = granted.includes(box.value);
        });
    } catch {
        // A database without migration 017 has no grants to show; the boxes
        // simply start unchecked rather than blocking the dialog.
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

    // The dropdown is only rendered when there is more than one salon to
    // choose from; otherwise the single manageable salon is used, which is
    // also what the server assigns.
    const manageableIds = manageableSalonIds(ctx);
    const salonId = form.elements.salon_id
        ? (form.elements.salon_id.value || null)
        : (user?.salon_id
            ?? (manageableIds.includes(String(ctx.salonId)) ? ctx.salonId : manageableIds[0])
            ?? null);

    // Salon-bound roles need a salon; platform roles must not have one.
    const needsSalon = ['customer_admin', 'customer_admin_delegate', 'customer_facing_tablet_user'];
    if (needsSalon.includes(values.role) && !salonId) {
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
        salon_id: salonId,
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

        // Grants live in their own table, so they are a second call.
        if (values.role === 'customer_admin_delegate' && userId && salonId) {
            try {
                await apiPost(
                    `user-invite.php?action=permissions&salon_id=${encodeURIComponent(salonId)}`,
                    { user_id: userId, permissions },
                    { salonScope: false }
                );
            } catch (error) {
                // The account itself saved, so this is not a failed save -- but
                // the operator must know the rights did not stick.
                toastError(t('admin.users.permissions_failed'));
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
