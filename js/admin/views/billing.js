/**
 * Abrechnung (billing and subscriptions) — Administrator only
 * ------------------------------------------------------------
 * Three sub-tabs over api/billing.php:
 *
 *   Übersicht   which salon is on which plan, and what it pays
 *   Tarife      the plan catalogue
 *   Rechnungen  invoices, created per salon and month
 *
 * An invoice opens as a printable page in a new window rather than a PDF: the
 * repo has no build step and no PDF library, and every browser prints to PDF.
 */

import { apiGet, apiPost } from '../api.js';
import {
    t, esc, el, createTable, modal, confirmDialog, pageHeader, subTabs,
    statusBadge, toastSuccess, toastApiError, formatDate, formatMoney,
    formatNumber, showFormErrors, clearFormErrors, formValues, buttonBusy,
    skeletonRows, forbiddenState, emptyState,
} from '../ui.js';

const TABS = [
    { id: 'overview', labelKey: 'admin.billing.tab_overview' },
    { id: 'plans', labelKey: 'admin.billing.tab_plans' },
    { id: 'invoices', labelKey: 'admin.billing.tab_invoices' },
];

let plans = [];

export async function render(container, ctx) {
    container.appendChild(pageHeader({
        title: t('admin.billing.title'),
        subtitle: t('admin.billing.subtitle'),
    }));

    const active = (window.location.hash.split('?')[1] || '').replace('tab=', '') || 'overview';
    const body = el('<div></div>');

    container.appendChild(subTabs(
        TABS.map((tab) => ({ id: tab.id, label: t(tab.labelKey) })),
        active,
        (id) => { window.location.hash = `#/abrechnung?tab=${id}`; }
    ));
    container.appendChild(body);

    body.appendChild(skeletonRows(6));

    try {
        // Every tab needs the plan list: the overview to name a salon's plan,
        // the assign dialog to offer one, the invoice dialog to price it.
        const planData = await apiGet('billing.php?action=plans', { salonScope: false });
        plans = planData.plans || [];

        if (active === 'plans') await renderPlans(body, ctx);
        else if (active === 'invoices') await renderInvoices(body, ctx);
        else await renderOverview(body, ctx);
    } catch (error) {
        body.innerHTML = '';
        if (error.isForbidden) body.appendChild(forbiddenState());
        else toastApiError(error);
    }
}

/* ============================================================
   Übersicht
   ============================================================ */

async function renderOverview(host, ctx) {
    const data = await apiGet('billing.php?action=overview', { salonScope: false });
    const summary = data.summary || { counts: {} };

    host.innerHTML = '';

    host.appendChild(el(`
        <div class="grid grid-4 mb-6">
            <div class="kpi">
                <div class="kpi-label">${esc(t('admin.billing.mrr'))}</div>
                <div class="kpi-value">${esc(formatMoney(summary.monthly_recurring || 0))}</div>
                <div class="kpi-hint">${esc(t('admin.billing.mrr_hint'))}</div>
            </div>
            <div class="kpi">
                <div class="kpi-label">${esc(t('admin.billing.paying'))}</div>
                <div class="kpi-value">${esc(formatNumber(summary.counts.active || 0))}</div>
                <div class="kpi-hint">${esc(t('admin.billing.of_total', { total: summary.total_salons || 0 }))}</div>
            </div>
            <div class="kpi">
                <div class="kpi-label">${esc(t('admin.billing.in_trial'))}</div>
                <div class="kpi-value">${esc(formatNumber(summary.counts.trial || 0))}</div>
            </div>
            <div class="kpi ${summary.counts.overdue ? 'kpi-warn' : ''}">
                <div class="kpi-label">${esc(t('admin.billing.overdue'))}</div>
                <div class="kpi-value">${esc(formatNumber(summary.counts.overdue || 0))}</div>
                <div class="kpi-hint">${esc(t('admin.billing.overdue_hint'))}</div>
            </div>
        </div>
    `));

    const table = createTable({
        rows: data.salons || [],
        searchKeys: ['salon_name', 'plan_name'],
        searchPlaceholderKey: 'admin.billing.search',
        defaultSort: { key: 'salon_name', dir: 'asc' },
        empty: {
            art: 'shop',
            title: t('admin.billing.empty_title'),
            text: t('admin.billing.empty_text'),
        },
        columns: [
            {
                key: 'salon_name',
                labelKey: 'admin.billing.salon',
                sortable: true,
                primary: true,
                render: (row) => `
                    <span class="cell-strong">${esc(row.salon_name)}</span>
                    <div class="text-xs text-muted">${esc(t('admin.billing.customers', { count: formatNumber(row.customer_count) }))}</div>
                `,
            },
            {
                key: 'plan_name',
                labelKey: 'admin.billing.plan',
                sortable: true,
                render: (row) => (row.plan_name
                    ? `<span class="badge badge-brand">${esc(row.plan_name)}</span>`
                    : `<span class="text-sm text-muted">${esc(t('admin.billing.no_plan'))}</span>`),
            },
            {
                key: 'monthly_price',
                labelKey: 'admin.billing.monthly',
                sortable: true,
                className: 'cell-num',
                sortValue: (row) => Number(row.monthly_price || 0),
                render: (row) => (row.monthly_price !== null
                    ? esc(formatMoney(row.monthly_price, row.currency))
                    : '—'),
            },
            {
                key: 'status',
                labelKey: 'admin.billing.status',
                sortable: true,
                render: (row) => (row.status === 'unassigned'
                    ? `<span class="badge badge-neutral">${esc(t('admin.billing.unassigned'))}</span>`
                    : statusBadge(row.status)),
            },
            {
                key: 'trial_ends_at',
                labelKey: 'admin.billing.trial_ends',
                sortable: true,
                sortValue: (row) => row.trial_ends_at || '',
                render: (row) => (row.trial_ends_at
                    ? `<span class="cell-muted">${esc(formatDate(row.trial_ends_at))}</span>`
                    : '—'),
            },
            {
                key: 'open_invoices',
                labelKey: 'admin.billing.open_invoices',
                sortable: true,
                className: 'cell-num',
                sortValue: (row) => Number(row.open_invoices || 0),
                render: (row) => (row.open_invoices
                    ? `<span class="badge badge-warning">${esc(formatNumber(row.open_invoices))}</span>`
                    : '<span class="cell-muted">0</span>'),
            },
            {
                key: 'actions',
                label: '',
                className: 'cell-actions',
                cardHidden: true,
                render: (row) => `
                    <button type="button" class="btn btn-ghost btn-sm" data-assign="${row.salon_id}">${esc(t('admin.billing.assign'))}</button>
                    <button type="button" class="btn btn-ghost btn-sm" data-invoice="${row.salon_id}">${esc(t('admin.billing.new_invoice'))}</button>
                `,
            },
        ],
    });

    table.element.addEventListener('click', (event) => {
        const assignId = event.target.closest('[data-assign]')?.dataset.assign;
        if (assignId) {
            const row = data.salons.find((s) => Number(s.salon_id) === Number(assignId));
            return openAssignDialog(row, ctx);
        }
        const invoiceId = event.target.closest('[data-invoice]')?.dataset.invoice;
        if (invoiceId) {
            const row = data.salons.find((s) => Number(s.salon_id) === Number(invoiceId));
            return openInvoiceDialog(row, ctx);
        }
    });

    host.appendChild(table.element);
}

function openAssignDialog(salon, ctx) {
    if (!salon) return;

    // A trial is stored as an active subscription with a future end date, so
    // the dialog offers it as a status even though the column has no such value.
    const currentStatus = salon.status === 'unassigned' ? 'active' : salon.status;

    const form = el(`
        <form id="assign-form" novalidate>
            <div class="form-grid">
                <div class="field">
                    <label class="field-label" for="plan_id">${esc(t('admin.billing.plan'))}</label>
                    <select class="select" id="plan_id" name="plan_id">
                        <option value="">${esc(t('admin.billing.no_plan'))}</option>
                        ${plans.map((plan) => `
                            <option value="${plan.plan_id}" ${Number(salon.plan_id) === Number(plan.plan_id) ? 'selected' : ''}>
                                ${esc(plan.name)} — ${esc(formatMoney(plan.monthly_price, plan.currency))}
                            </option>
                        `).join('')}
                    </select>
                </div>
                <div class="form-grid form-grid-2">
                    <div class="field">
                        <label class="field-label" for="payment_status">${esc(t('admin.billing.status'))}</label>
                        <select class="select" id="payment_status" name="payment_status">
                            ${['active', 'trial', 'overdue', 'cancelled'].map((status) =>
                                `<option value="${status}" ${currentStatus === status ? 'selected' : ''}>${esc(t(`admin.status.${status}`))}</option>`
                            ).join('')}
                        </select>
                    </div>
                    <div class="field">
                        <label class="field-label" for="trial_ends_at">${esc(t('admin.billing.trial_ends'))}</label>
                        <input class="input" type="date" id="trial_ends_at" name="trial_ends_at"
                               value="${esc(salon.trial_ends_at || '')}">
                        <span class="field-hint">${esc(t('admin.billing.trial_hint'))}</span>
                    </div>
                </div>
                <div class="field">
                    <label class="field-label" for="notes">${esc(t('admin.billing.notes'))}</label>
                    <textarea class="textarea" id="notes" name="notes" rows="2">${esc(salon.notes || '')}</textarea>
                </div>
            </div>
        </form>
    `);

    modal({
        title: t('admin.billing.assign'),
        subtitle: salon.salon_name,
        body: form,
        actions: [
            { label: t('admin.common.cancel'), variant: 'secondary', onClick: (close) => close() },
            {
                label: t('admin.common.save'),
                variant: 'primary',
                onClick: async (close, button) => {
                    const values = formValues(form);
                    const reset = buttonBusy(button, t('admin.common.saving'));
                    try {
                        await apiPost('billing.php?action=assign', {
                            salon_id: salon.salon_id,
                            plan_id: values.plan_id || null,
                            payment_status: values.payment_status,
                            trial_ends_at: values.trial_ends_at || null,
                            notes: values.notes || null,
                        }, { salonScope: false });
                        toastSuccess(t('admin.billing.assigned'));
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

/* ============================================================
   Tarife
   ============================================================ */

async function renderPlans(host, ctx) {
    host.innerHTML = '';

    const header = el(`<div class="row mb-4"><span class="text-sm text-muted" style="flex:1">${esc(t('admin.billing.plans_hint'))}</span></div>`);
    const add = el(`<button type="button" class="btn btn-primary">＋ ${esc(t('admin.billing.new_plan'))}</button>`);
    add.addEventListener('click', () => openPlanDialog(null, ctx));
    header.appendChild(add);
    host.appendChild(header);

    if (!plans.length) {
        host.appendChild(emptyState({
            art: 'chart',
            title: t('admin.billing.plans_empty_title'),
            text: t('admin.billing.plans_empty_text'),
            action: { label: t('admin.billing.new_plan'), onClick: () => openPlanDialog(null, ctx) },
        }));
        return;
    }

    const grid = el('<div class="grid grid-3"></div>');
    plans.forEach((plan) => {
        const card = el(`
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>${esc(plan.name)}</h2>
                        <p class="card-hint">${esc(plan.description || '')}</p>
                    </div>
                    ${plan.is_active
                        ? `<span class="badge badge-success">${esc(t('admin.status.active'))}</span>`
                        : `<span class="badge badge-neutral">${esc(t('admin.status.inactive'))}</span>`}
                </div>
                <div class="card-body">
                    <div style="font-size:1.6rem;font-weight:700">
                        ${esc(formatMoney(plan.monthly_price, plan.currency))}
                        <span class="text-sm text-muted" style="font-weight:400">${esc(t('admin.billing.per_month'))}</span>
                    </div>
                    <ul class="text-sm text-muted mt-4" style="list-style:none;padding:0;display:grid;gap:6px">
                        <li>${esc(plan.max_customers ? t('admin.billing.limit_customers', { count: formatNumber(plan.max_customers) }) : t('admin.billing.unlimited_customers'))}</li>
                        <li>${esc(plan.max_campaigns_per_month ? t('admin.billing.limit_campaigns', { count: plan.max_campaigns_per_month }) : t('admin.billing.unlimited_campaigns'))}</li>
                        <li>${esc(t('admin.billing.salons_on_plan', { count: plan.salon_count }))}</li>
                    </ul>
                </div>
                <div class="card-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-plan="${plan.plan_id}">${esc(t('admin.common.edit'))}</button>
                </div>
            </div>
        `);
        card.querySelector('[data-plan]').addEventListener('click', () => openPlanDialog(plan, ctx));
        grid.appendChild(card);
    });

    host.appendChild(grid);
}

function openPlanDialog(plan, ctx) {
    const form = el(`
        <form id="plan-form" novalidate>
            <div class="form-grid">
                <div class="field">
                    <label class="field-label" for="name">${esc(t('admin.billing.plan_name'))}<span class="req">*</span></label>
                    <input class="input" id="name" name="name" required value="${esc(plan?.name || '')}">
                </div>
                <div class="field">
                    <label class="field-label" for="description">${esc(t('admin.billing.plan_description'))}</label>
                    <input class="input" id="description" name="description" value="${esc(plan?.description || '')}">
                </div>
                <div class="form-grid form-grid-2">
                    <div class="field">
                        <label class="field-label" for="monthly_price">${esc(t('admin.billing.monthly'))}</label>
                        <input class="input" id="monthly_price" name="monthly_price" type="number"
                               min="0" step="0.01" value="${Number(plan?.monthly_price ?? 0)}">
                    </div>
                    <div class="field">
                        <label class="field-label" for="currency">${esc(t('admin.billing.currency'))}</label>
                        <select class="select" id="currency" name="currency">
                            ${['EUR', 'CHF'].map((code) =>
                                `<option value="${code}" ${(plan?.currency || 'EUR') === code ? 'selected' : ''}>${code}</option>`
                            ).join('')}
                        </select>
                    </div>
                    <div class="field">
                        <label class="field-label" for="max_customers">${esc(t('admin.billing.max_customers'))}</label>
                        <input class="input" id="max_customers" name="max_customers" type="number"
                               min="0" step="1" value="${Number(plan?.max_customers ?? 0)}">
                        <span class="field-hint">${esc(t('admin.billing.zero_unlimited'))}</span>
                    </div>
                    <div class="field">
                        <label class="field-label" for="max_campaigns_per_month">${esc(t('admin.billing.max_campaigns'))}</label>
                        <input class="input" id="max_campaigns_per_month" name="max_campaigns_per_month" type="number"
                               min="0" step="1" value="${Number(plan?.max_campaigns_per_month ?? 0)}">
                        <span class="field-hint">${esc(t('admin.billing.zero_unlimited'))}</span>
                    </div>
                </div>
                <label class="switch">
                    <input type="checkbox" name="is_active" ${plan === null || plan?.is_active ? 'checked' : ''}>
                    <span class="switch-track"></span>
                    <span class="switch-label">${esc(t('admin.billing.plan_active'))}</span>
                </label>
            </div>
        </form>
    `);

    modal({
        title: plan ? t('admin.billing.edit_plan') : t('admin.billing.new_plan'),
        body: form,
        actions: [
            { label: t('admin.common.cancel'), variant: 'secondary', onClick: (close) => close() },
            {
                label: t('admin.common.save'),
                variant: 'primary',
                onClick: async (close, button) => {
                    clearFormErrors(form);
                    const values = formValues(form);
                    if (!values.name) {
                        showFormErrors(form, { name: t('admin.validation.required') });
                        return;
                    }

                    const reset = buttonBusy(button, t('admin.common.saving'));
                    try {
                        await apiPost('billing.php?action=plan', {
                            plan_id: plan?.plan_id || 0,
                            name: values.name,
                            description: values.description,
                            monthly_price: Number(values.monthly_price) || 0,
                            currency: values.currency,
                            max_customers: Number(values.max_customers) || 0,
                            max_campaigns_per_month: Number(values.max_campaigns_per_month) || 0,
                            is_active: form.elements.is_active.checked,
                            sort_order: plan?.sort_order || 0,
                        }, { salonScope: false });
                        toastSuccess(t('admin.billing.plan_saved'));
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

/* ============================================================
   Rechnungen
   ============================================================ */

async function renderInvoices(host, ctx) {
    const data = await apiGet('billing.php?action=invoices', { salonScope: false });
    host.innerHTML = '';

    const table = createTable({
        rows: data.invoices || [],
        searchKeys: ['invoice_number', 'salon_name'],
        searchPlaceholderKey: 'admin.billing.invoice_search',
        defaultSort: { key: 'invoice_number', dir: 'desc' },
        empty: {
            art: 'mail',
            title: t('admin.billing.invoices_empty_title'),
            text: t('admin.billing.invoices_empty_text'),
        },
        columns: [
            {
                key: 'invoice_number',
                labelKey: 'admin.billing.invoice_number',
                sortable: true,
                primary: true,
                render: (row) => `<span class="cell-strong">${esc(row.invoice_number)}</span>`,
            },
            {
                key: 'salon_name',
                labelKey: 'admin.billing.salon',
                sortable: true,
                render: (row) => esc(row.salon_name || '—'),
            },
            {
                key: 'period',
                labelKey: 'admin.billing.period',
                sortable: true,
                sortValue: (row) => row.period_year * 100 + row.period_month,
                render: (row) => `<span class="cell-muted">${String(row.period_month).padStart(2, '0')}/${row.period_year}</span>`,
            },
            {
                key: 'total',
                labelKey: 'admin.billing.total',
                sortable: true,
                className: 'cell-num',
                sortValue: (row) => Number(row.total),
                render: (row) => `<span class="cell-strong">${esc(formatMoney(row.total, row.currency))}</span>`,
            },
            {
                key: 'status',
                labelKey: 'admin.billing.status',
                sortable: true,
                render: (row) => statusBadge(row.status),
            },
            {
                key: 'issued_at',
                labelKey: 'admin.billing.issued',
                sortable: true,
                sortValue: (row) => row.issued_at || '',
                render: (row) => `<span class="cell-muted">${esc(row.issued_at ? formatDate(row.issued_at) : '—')}</span>`,
            },
            {
                key: 'actions',
                label: '',
                className: 'cell-actions',
                cardHidden: true,
                render: (row) => `
                    <button type="button" class="btn btn-ghost btn-sm" data-print="${row.invoice_id}">${esc(t('admin.billing.print'))}</button>
                    ${row.status === 'open'
                        ? `<button type="button" class="btn btn-ghost btn-sm" data-paid="${row.invoice_id}">${esc(t('admin.billing.mark_paid'))}</button>`
                        : ''}
                `,
            },
        ],
    });

    table.element.addEventListener('click', async (event) => {
        const printId = event.target.closest('[data-print]')?.dataset.print;
        if (printId) return printInvoice(Number(printId));

        const paidId = event.target.closest('[data-paid]')?.dataset.paid;
        if (paidId) return markPaid(Number(paidId), ctx);
    });

    host.appendChild(table.element);
}

async function markPaid(invoiceId, ctx) {
    const confirmed = await confirmDialog({
        title: t('admin.billing.mark_paid_title'),
        message: t('admin.billing.mark_paid_message'),
        confirmLabel: t('admin.billing.mark_paid'),
        variant: 'primary',
    });
    if (!confirmed) return;

    try {
        await apiPost('billing.php?action=mark_paid', { invoice_id: invoiceId, status: 'paid' }, { salonScope: false });
        toastSuccess(t('admin.billing.marked_paid'));
        ctx.reload();
    } catch (error) {
        toastApiError(error);
    }
}

function openInvoiceDialog(salon, ctx) {
    if (!salon) return;

    const now = new Date();
    const form = el(`
        <form id="invoice-form" novalidate>
            <div class="form-grid">
                <div class="form-grid form-grid-2">
                    <div class="field">
                        <label class="field-label" for="period_month">${esc(t('admin.billing.month'))}</label>
                        <select class="select" id="period_month" name="period_month">
                            ${Array.from({ length: 12 }, (_, index) => index + 1).map((month) =>
                                `<option value="${month}" ${month === now.getMonth() + 1 ? 'selected' : ''}>${String(month).padStart(2, '0')}</option>`
                            ).join('')}
                        </select>
                    </div>
                    <div class="field">
                        <label class="field-label" for="period_year">${esc(t('admin.billing.year'))}</label>
                        <input class="input" id="period_year" name="period_year" type="number"
                               min="2020" max="2100" value="${now.getFullYear()}">
                    </div>
                    <div class="field">
                        <label class="field-label" for="tax_rate">${esc(t('admin.billing.tax_rate'))}</label>
                        <input class="input" id="tax_rate" name="tax_rate" type="number"
                               min="0" max="100" step="0.1" value="0">
                    </div>
                    <div class="field">
                        <label class="field-label" for="issued_at">${esc(t('admin.billing.issued'))}</label>
                        <input class="input" id="issued_at" name="issued_at" type="date"
                               value="${now.toISOString().slice(0, 10)}">
                    </div>
                </div>
                <p class="card-hint">${esc(salon.plan_name
                    ? t('admin.billing.invoice_from_plan', { plan: salon.plan_name })
                    : t('admin.billing.invoice_no_plan'))}</p>
            </div>
        </form>
    `);

    modal({
        title: t('admin.billing.new_invoice'),
        subtitle: salon.salon_name,
        body: form,
        actions: [
            { label: t('admin.common.cancel'), variant: 'secondary', onClick: (close) => close() },
            {
                label: t('admin.billing.create_invoice'),
                variant: 'primary',
                onClick: async (close, button) => {
                    const values = formValues(form);
                    const reset = buttonBusy(button, t('admin.common.saving'));
                    try {
                        const result = await apiPost('billing.php?action=create_invoice', {
                            salon_id: salon.salon_id,
                            period_year: Number(values.period_year),
                            period_month: Number(values.period_month),
                            tax_rate: Number(values.tax_rate) || 0,
                            issued_at: values.issued_at,
                        }, { salonScope: false });
                        toastSuccess(t('admin.billing.invoice_created', { number: result.invoice_number }));
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
 * Open an invoice as a printable page.
 *
 * Written into a new window rather than fetched as a PDF: there is no build
 * step and no PDF library here, and the browser's own print dialogue produces
 * a perfectly good PDF from clean HTML.
 */
async function printInvoice(invoiceId) {
    let data;
    try {
        data = await apiGet(`billing.php?action=invoice&id=${invoiceId}`, { salonScope: false });
    } catch (error) {
        toastApiError(error);
        return;
    }

    const invoice = data.invoice;
    const items = data.items || [];

    // Opened before the await elsewhere would give a popup blocker a reason to
    // step in; here the click is still the most recent user gesture.
    const printWindow = window.open('', '_blank', 'width=820,height=1000');
    if (!printWindow) {
        toastApiError(new Error(t('admin.billing.popup_blocked')));
        return;
    }

    const rows = items.map((item) => `
        <tr>
            <td>${esc(item.description)}</td>
            <td class="num">${esc(formatNumber(item.quantity))}</td>
            <td class="num">${esc(formatMoney(item.unit_price, invoice.currency))}</td>
            <td class="num">${esc(formatMoney(item.amount, invoice.currency))}</td>
        </tr>
    `).join('');

    printWindow.document.write(`
        <!DOCTYPE html>
        <html lang="de"><head><meta charset="utf-8">
        <title>${esc(invoice.invoice_number)}</title>
        <style>
            body { font-family: Inter, system-ui, sans-serif; color: #1F2937; margin: 40px; }
            h1 { font-size: 1.5rem; margin: 0 0 4px; }
            .muted { color: #6B7280; font-size: 0.9rem; }
            table { width: 100%; border-collapse: collapse; margin-top: 28px; }
            th, td { padding: 10px 8px; border-bottom: 1px solid #E5E7EB; text-align: left; }
            th { font-size: 0.78rem; text-transform: uppercase; letter-spacing: .05em; color: #6B7280; }
            .num { text-align: right; }
            .totals { margin-top: 18px; margin-left: auto; width: 280px; }
            .totals td { border: 0; padding: 4px 8px; }
            .grand { font-weight: 700; border-top: 2px solid #1F2937 !important; }
            @media print { body { margin: 0; } }
        </style></head><body>
            <h1>${esc(t('admin.billing.invoice'))} ${esc(invoice.invoice_number)}</h1>
            <div class="muted">
                ${esc(t('admin.billing.period'))}: ${String(invoice.period_month).padStart(2, '0')}/${invoice.period_year}
                &middot; ${esc(t('admin.billing.issued'))}: ${esc(invoice.issued_at ? formatDate(invoice.issued_at) : '—')}
            </div>
            <p style="margin-top:28px">
                <strong>${esc(invoice.salon_name || '')}</strong><br>
                ${esc(invoice.salon_address || '')}<br>
                ${esc(invoice.salon_email || '')}
            </p>
            <table>
                <thead><tr>
                    <th>${esc(t('admin.billing.description'))}</th>
                    <th class="num">${esc(t('admin.billing.quantity'))}</th>
                    <th class="num">${esc(t('admin.billing.unit_price'))}</th>
                    <th class="num">${esc(t('admin.billing.amount'))}</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>
            <table class="totals">
                <tr><td>${esc(t('admin.billing.subtotal'))}</td><td class="num">${esc(formatMoney(invoice.subtotal, invoice.currency))}</td></tr>
                <tr><td>${esc(t('admin.billing.tax'))} (${esc(formatNumber(invoice.tax_rate))}%)</td><td class="num">${esc(formatMoney(invoice.tax_amount, invoice.currency))}</td></tr>
                <tr class="grand"><td>${esc(t('admin.billing.total'))}</td><td class="num">${esc(formatMoney(invoice.total, invoice.currency))}</td></tr>
            </table>
        </body></html>
    `);
    printWindow.document.close();
    printWindow.focus();
}
