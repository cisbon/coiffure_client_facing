/**
 * Kunden (customer list)
 * ------------------------------------------------------------
 * Migrated from loadCustomerEntries/renderCustomerEntriesTable in the old
 * admin-dashboard.js, rebuilt on the shared table and translated.
 *
 * This is the list view only. The segmentation filter bar, saved segments,
 * best/inactive presets, the slide-over customer profile and the consent-aware
 * CSV export arrive with api/insights.php in the insights stage; this module is
 * the surface they extend.
 */

import { apiGet } from '../api.js';
import {
    t, esc, el, createTable, pageHeader, boolBadge, toastApiError,
    formatDate, skeletonRows,
} from '../ui.js';

let customers = [];

export async function render(container, ctx) {
    container.appendChild(pageHeader({
        title: t('admin.customers.title'),
        subtitle: ctx.isAllSalons
            ? t('admin.customers.subtitle_all')
            : t('admin.customers.subtitle'),
    }));

    const host = el('<div></div>');
    container.appendChild(host);
    host.appendChild(skeletonRows(8));

    try {
        const query = ctx.isAllSalons
            ? 'customer-entries.php'
            : `customer-entries.php?salon_id=${encodeURIComponent(ctx.salonId)}`;
        const data = await apiGet(query, { salonScope: false });
        customers = data.customers || data.entries || [];
    } catch (error) {
        toastApiError(error);
        customers = [];
    }

    host.innerHTML = '';
    host.appendChild(buildTable(ctx));
}

function buildTable(ctx) {
    const table = createTable({
        rows: customers,
        searchKeys: ['full_name', 'email', 'phone', 'zip', 'city'],
        searchPlaceholderKey: 'admin.customers.search',
        pageSize: 25,
        defaultSort: { key: 'created_at', dir: 'desc' },
        empty: {
            art: 'people',
            title: t('admin.customers.empty_title'),
            text: t('admin.customers.empty_text'),
        },
        columns: [
            {
                key: 'full_name',
                labelKey: 'admin.customers.name',
                sortable: true,
                primary: true,
                render: (c) => `<span class="cell-strong">${esc(c.full_name || '—')}</span>`,
            },
            {
                key: 'email',
                labelKey: 'admin.customers.email',
                sortable: true,
                render: (c) => `<span class="cell-muted">${esc(c.email || '—')}</span>`,
            },
            {
                key: 'phone',
                labelKey: 'admin.customers.phone',
                render: (c) => esc(c.mobile || c.phone || '—'),
            },
            {
                key: 'birthday',
                labelKey: 'admin.customers.birthday',
                sortable: true,
                sortValue: (c) => (Number(c.birth_month || 0) * 100) + Number(c.birth_day || 0),
                render: (c) => (c.birth_day && c.birth_month)
                    ? esc(`${String(c.birth_day).padStart(2, '0')}.${String(c.birth_month).padStart(2, '0')}.${c.birth_year || ''}`.replace(/\.$/, ''))
                    : '<span class="cell-muted">—</span>',
            },
            {
                key: 'zip',
                labelKey: 'admin.customers.location',
                sortable: true,
                render: (c) => esc([c.zip, c.city].filter(Boolean).join(' ') || '—'),
            },
            {
                key: 'is_member',
                labelKey: 'admin.customers.member',
                sortable: true,
                sortValue: (c) => (Number(c.is_member) ? 1 : 0),
                render: (c) => boolBadge(Number(c.is_member) === 1),
            },
            {
                key: 'consent_email_marketing',
                labelKey: 'admin.customers.consent_marketing',
                sortable: true,
                sortValue: (c) => (Number(c.consent_email_marketing ?? c.consent_marketing) ? 1 : 0),
                render: (c) => boolBadge(Number(c.consent_email_marketing ?? c.consent_marketing) === 1),
            },
            {
                key: 'created_at',
                labelKey: 'admin.customers.registered',
                sortable: true,
                sortValue: (c) => c.created_at || '',
                render: (c) => `<span class="cell-muted">${esc(formatDate(c.created_at))}</span>`,
            },
        ],
    });

    return table.element;
}
