/**
 * Protokoll (audit log + GDPR consent trail)
 * ------------------------------------------------------------
 * Two sub-tabs over two read-only endpoints:
 *
 *   Aktivitäten   audit-log.php        who did what, filterable
 *   Einwilligungen consent-history.php what a customer agreed to, and when
 *
 * Both are paginated server-side rather than through createTable: an audit log
 * grows without bound, and pulling the whole thing into the browser to page it
 * client-side would stop working exactly when the log becomes interesting.
 *
 * Neither view can edit anything. That is the point of a log.
 */

import { apiGet } from '../api.js';
import {
    t, esc, el, pageHeader, subTabs, emptyState, skeletonRows, toastApiError,
    formatDateTime, boolBadge, debounce, forbiddenState,
} from '../ui.js';

const TABS = [
    { id: 'activity', labelKey: 'admin.audit.tab_activity' },
    { id: 'consent', labelKey: 'admin.audit.tab_consent' },
];

const PER_PAGE = 50;

export async function render(container, ctx) {
    container.appendChild(pageHeader({
        title: t('admin.audit.title'),
        subtitle: t('admin.audit.subtitle'),
    }));

    const active = (window.location.hash.split('?')[1] || '').replace('tab=', '') || 'activity';
    const body = el('<div></div>');

    container.appendChild(subTabs(
        TABS.map((tab) => ({ id: tab.id, label: t(tab.labelKey) })),
        active,
        (id) => { window.location.hash = `#/protokoll?tab=${id}`; }
    ));
    container.appendChild(body);

    if (active === 'consent') await renderConsent(body, ctx);
    else await renderActivity(body, ctx);
}

/* ============================================================
   Aktivitäten
   ============================================================ */

async function renderActivity(host, ctx) {
    const state = { page: 1, filters: {} };

    const filterBar = el(`
        <form class="filter-bar mb-4" id="audit-filters">
            <div class="field">
                <label class="field-label" for="f-from">${esc(t('admin.audit.from'))}</label>
                <input class="input" type="date" id="f-from" name="from">
            </div>
            <div class="field">
                <label class="field-label" for="f-to">${esc(t('admin.audit.to'))}</label>
                <input class="input" type="date" id="f-to" name="to">
            </div>
            <div class="field">
                <label class="field-label" for="f-entity">${esc(t('admin.audit.entity'))}</label>
                <select class="select" id="f-entity" name="entity_type">
                    <option value="">${esc(t('admin.audit.all'))}</option>
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="f-action">${esc(t('admin.audit.action'))}</label>
                <select class="select" id="f-action" name="action_type">
                    <option value="">${esc(t('admin.audit.all'))}</option>
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="f-user">${esc(t('admin.audit.performer'))}</label>
                <select class="select" id="f-user" name="performed_by">
                    <option value="">${esc(t('admin.audit.all'))}</option>
                </select>
            </div>
            ${ctx.permissions.isPlatform ? `
            <div class="field">
                <label class="field-label" for="f-salon">${esc(t('admin.audit.salon'))}</label>
                <select class="select" id="f-salon" name="salon_id">
                    <option value="">${esc(t('admin.audit.all'))}</option>
                    ${ctx.salons.map((salon) =>
                        `<option value="${salon.salon_id}">${esc(salon.salon_name)}</option>`
                    ).join('')}
                </select>
            </div>` : ''}
            <div class="field" style="flex:1;min-width:200px">
                <label class="field-label" for="f-q">${esc(t('admin.common.search'))}</label>
                <input class="input" type="search" id="f-q" name="q"
                       placeholder="${esc(t('admin.audit.search'))}">
            </div>
            <button type="button" class="btn btn-ghost btn-sm" id="f-reset">${esc(t('admin.common.reset'))}</button>
        </form>
    `);

    const results = el('<div></div>');
    host.innerHTML = '';
    host.appendChild(filterBar);
    host.appendChild(results);
    results.appendChild(skeletonRows(8));

    // The dropdown values come from the log itself, already narrowed to what
    // this caller may see.
    try {
        const filters = await apiGet('audit-log.php?action=filters', { salonScope: false });
        fillSelect(filterBar.querySelector('#f-entity'), filters.entity_types, auditEntityLabel);
        fillSelect(filterBar.querySelector('#f-action'), filters.actions, auditActionLabel);
        fillSelect(filterBar.querySelector('#f-user'), filters.performers);
    } catch (error) {
        if (error.isForbidden) {
            results.innerHTML = '';
            results.appendChild(forbiddenState());
            return;
        }
        // A missing filter list is survivable: the log itself still loads.
    }

    const load = async () => {
        results.innerHTML = '';
        results.appendChild(skeletonRows(8));

        const query = new URLSearchParams({ page: state.page, per_page: PER_PAGE });
        Object.entries(state.filters).forEach(([key, value]) => {
            if (value) query.set(key, value);
        });

        // No salon_id by default. The server already limits a salon role to
        // their own salons, and platform-level rows (billing, plan changes,
        // anything cross-salon) carry no salon_id at all -- narrowing by the
        // top-bar salon would hide exactly the rows an administrator opens
        // this screen to find. The dropdown above is the way to narrow.
        try {
            const data = await apiGet(`audit-log.php?${query}`, { salonScope: false });
            results.innerHTML = '';
            results.appendChild(activityTable(data, ctx));
            results.appendChild(pager(data, (page) => { state.page = page; load(); }));
        } catch (error) {
            results.innerHTML = '';
            if (error.isForbidden) results.appendChild(forbiddenState());
            else toastApiError(error);
        }
    };

    const apply = () => {
        state.page = 1;
        state.filters = {
            from: filterBar.elements.from.value,
            to: filterBar.elements.to.value,
            entity_type: filterBar.elements.entity_type.value,
            action_type: filterBar.elements.action_type.value,
            performed_by: filterBar.elements.performed_by.value,
            salon_id: filterBar.elements.salon_id?.value || '',
            q: filterBar.elements.q.value.trim(),
        };
        load();
    };

    filterBar.addEventListener('change', apply);
    filterBar.querySelector('#f-q').addEventListener('input', debounce(apply, 350));
    filterBar.querySelector('#f-reset').addEventListener('click', () => {
        filterBar.reset();
        apply();
    });
    filterBar.addEventListener('submit', (event) => event.preventDefault());

    await load();
}

function fillSelect(select, values, label) {
    (values || []).forEach((value) => {
        const text = label ? label(value) : value;
        select.appendChild(el(`<option value="${esc(value)}">${esc(text)}</option>`));
    });
}

function activityTable(data, ctx) {
    if (!data.entries.length) {
        return emptyState({
            art: 'search',
            title: t('admin.audit.empty_title'),
            text: t('admin.audit.empty_text'),
        });
    }

    const showSalon = ctx.isAllSalons || ctx.permissions.isPlatform;

    // Rendered directly rather than through createTable: the rows are already
    // sorted and paged by the server, so client-side sorting would only sort
    // the page you happen to be looking at.
    const table = el(`
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>${esc(t('admin.audit.when'))}</th>
                        <th>${esc(t('admin.audit.performer'))}</th>
                        <th>${esc(t('admin.audit.action'))}</th>
                        <th>${esc(t('admin.audit.entity'))}</th>
                        ${showSalon ? `<th>${esc(t('admin.audit.salon'))}</th>` : ''}
                        <th>${esc(t('admin.audit.details'))}</th>
                        <th>${esc(t('admin.audit.ip'))}</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    `);

    const tbody = table.querySelector('tbody');
    data.entries.forEach((entry) => {
        tbody.appendChild(el(`
            <tr>
                <td class="cell-muted" style="white-space:nowrap">${esc(formatDateTime(entry.created_at))}</td>
                <td>
                    <span class="cell-strong">${esc(entry.performed_by || '—')}</span>
                    ${entry.performed_by_role
                        ? `<div class="text-xs text-muted">${esc(t(`admin.roles.${entry.performed_by_role}`))}</div>`
                        : ''}
                </td>
                <td><span class="badge badge-neutral">${esc(auditActionLabel(entry.action))}</span></td>
                <td class="cell-muted">${esc(auditEntityLabel(entry.entity_type))} #${Number(entry.entity_id)}</td>
                ${showSalon ? `<td class="cell-muted">${esc(entry.salon_name || '—')}</td>` : ''}
                <td>${esc(entry.action_details || '')}</td>
                <td class="cell-muted text-xs">${esc(entry.ip_address || '')}</td>
            </tr>
        `));
    });

    return table;
}

/**
 * Audit values are free-form strings written by many endpoints, so a
 * translation is used when one exists and the raw value is shown otherwise --
 * an unknown action must still be readable rather than rendering a key.
 */
function auditActionLabel(action) {
    const key = `admin.audit.actions.${action}`;
    const label = t(key);
    return label === key ? action : label;
}

function auditEntityLabel(entity) {
    const key = `admin.audit.entities.${entity}`;
    const label = t(key);
    return label === key ? entity : label;
}

function pager(data, onPage) {
    const row = el(`
        <div class="row mt-4" style="justify-content:space-between">
            <span class="text-sm text-muted">
                ${esc(t('admin.audit.page_of', { page: data.page, pages: Math.max(1, data.pages), total: data.total }))}
            </span>
            <div class="row" style="gap:6px"></div>
        </div>
    `);

    const buttons = row.querySelector('div');

    const previous = el(`<button type="button" class="btn btn-secondary btn-sm">‹ ${esc(t('admin.audit.previous'))}</button>`);
    previous.disabled = data.page <= 1;
    previous.addEventListener('click', () => onPage(data.page - 1));

    const next = el(`<button type="button" class="btn btn-secondary btn-sm">${esc(t('admin.audit.next'))} ›</button>`);
    next.disabled = data.page >= data.pages;
    next.addEventListener('click', () => onPage(data.page + 1));

    buttons.appendChild(previous);
    buttons.appendChild(next);
    return row;
}

/* ============================================================
   Einwilligungen (GDPR consent trail)
   ============================================================ */

/** Mirrors CONSENT_FIELDS in api/consent_log.php. */
const CONSENT_FIELDS = [
    'consent_data_processing',
    'consent_email_marketing',
    'consent_marketing',
    'consent_sms_whatsapp',
    'consent_postal',
    'consent_cancellation_policy',
];

async function renderConsent(host, ctx) {
    const state = { page: 1, filters: {} };

    const filterBar = el(`
        <form class="filter-bar mb-4" id="consent-filters">
            <div class="field">
                <label class="field-label" for="c-from">${esc(t('admin.audit.from'))}</label>
                <input class="input" type="date" id="c-from" name="from">
            </div>
            <div class="field">
                <label class="field-label" for="c-to">${esc(t('admin.audit.to'))}</label>
                <input class="input" type="date" id="c-to" name="to">
            </div>
            <div class="field">
                <label class="field-label" for="c-field">${esc(t('admin.audit.consent_field'))}</label>
                <select class="select" id="c-field" name="consent_field">
                    <option value="">${esc(t('admin.audit.all'))}</option>
                    ${CONSENT_FIELDS.map((field) =>
                        `<option value="${esc(field)}">${esc(t(`admin.audit.consents.${field}`))}</option>`
                    ).join('')}
                </select>
            </div>
            <div class="field" style="flex:1;min-width:200px">
                <label class="field-label" for="c-q">${esc(t('admin.common.search'))}</label>
                <input class="input" type="search" id="c-q" name="q"
                       placeholder="${esc(t('admin.audit.consent_search'))}">
            </div>
            <button type="button" class="btn btn-ghost btn-sm" id="c-reset">${esc(t('admin.common.reset'))}</button>
        </form>
    `);

    const note = el(`
        <div class="alert alert-info mb-4">
            <div>
                <div class="alert-title">${esc(t('admin.audit.consent_note_title'))}</div>
                <div class="text-sm">${esc(t('admin.audit.consent_note_text'))}</div>
            </div>
        </div>
    `);

    const results = el('<div></div>');
    host.innerHTML = '';
    host.appendChild(note);
    host.appendChild(filterBar);
    host.appendChild(results);

    const load = async () => {
        results.innerHTML = '';
        results.appendChild(skeletonRows(8));

        const query = new URLSearchParams({ page: state.page, per_page: PER_PAGE });
        Object.entries(state.filters).forEach(([key, value]) => {
            if (value) query.set(key, value);
        });
        query.set('salon_id', ctx.isAllSalons ? 'all' : ctx.salonId);

        try {
            const data = await apiGet(`consent-history.php?${query}`, { salonScope: false });
            results.innerHTML = '';
            results.appendChild(consentTable(data));
            results.appendChild(pager(data, (page) => { state.page = page; load(); }));
        } catch (error) {
            results.innerHTML = '';
            if (error.isForbidden) results.appendChild(forbiddenState());
            else toastApiError(error);
        }
    };

    const apply = () => {
        state.page = 1;
        state.filters = {
            from: filterBar.elements.from.value,
            to: filterBar.elements.to.value,
            consent_field: filterBar.elements.consent_field.value,
            q: filterBar.elements.q.value.trim(),
        };
        load();
    };

    filterBar.addEventListener('change', apply);
    filterBar.querySelector('#c-q').addEventListener('input', debounce(apply, 350));
    filterBar.querySelector('#c-reset').addEventListener('click', () => { filterBar.reset(); apply(); });
    filterBar.addEventListener('submit', (event) => event.preventDefault());

    await load();
}

function consentTable(data) {
    if (!data.entries.length) {
        return emptyState({
            art: 'lock',
            title: t('admin.audit.consent_empty_title'),
            text: t('admin.audit.consent_empty_text'),
        });
    }

    const table = el(`
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>${esc(t('admin.audit.when'))}</th>
                        <th>${esc(t('admin.audit.customer'))}</th>
                        <th>${esc(t('admin.audit.consent_field'))}</th>
                        <th>${esc(t('admin.audit.change'))}</th>
                        <th>${esc(t('admin.audit.source'))}</th>
                        <th>${esc(t('admin.audit.policy'))}</th>
                        <th>${esc(t('admin.audit.ip'))}</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    `);

    const tbody = table.querySelector('tbody');
    data.entries.forEach((entry) => {
        tbody.appendChild(el(`
            <tr>
                <td class="cell-muted" style="white-space:nowrap">${esc(formatDateTime(entry.created_at))}</td>
                <td>
                    <span class="cell-strong">${esc(entry.customer_name || `#${entry.customer_id}`)}</span>
                    ${entry.customer_email ? `<div class="text-xs text-muted">${esc(entry.customer_email)}</div>` : ''}
                </td>
                <td class="cell-muted">${esc(consentFieldLabel(entry.consent_field))}</td>
                <td style="white-space:nowrap">
                    ${consentValue(entry.old_value)} → ${consentValue(entry.new_value)}
                </td>
                <td class="cell-muted">${esc(entry.source ? t(`admin.audit.sources.${entry.source}`) : '—')}</td>
                <td class="cell-muted text-xs">${esc(entry.policy_version || '—')}</td>
                <td class="cell-muted text-xs">${esc(entry.ip_address || '')}</td>
            </tr>
        `));
    });

    return table;
}

function consentFieldLabel(field) {
    const key = `admin.audit.consents.${field}`;
    const label = t(key);
    return label === key ? field : label;
}

function consentValue(value) {
    if (value === null || value === undefined) {
        return `<span class="badge badge-neutral">—</span>`;
    }
    return boolBadge(value, 'admin.audit.granted', 'admin.audit.withdrawn');
}
