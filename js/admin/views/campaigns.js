/**
 * Kampagnen
 * ------------------------------------------------------------
 * Three sub-tabs, matching spec 3.6:
 *   Einmal-Kampagne        a four-step wizard (recipients → compose → discount → review)
 *   Automatische Kampagnen the four predefined types with a toggle and an editor
 *   Protokoll              the send history with open/click rates
 *
 * The recipient count and the spam-limit warning always come from the server
 * (campaigns.php?action=recipients) rather than being computed here, so what the
 * review step promises is what the send actually does.
 */

import { apiGet, apiPost } from '../api.js';
import {
    t, esc, el, pageHeader, subTabs, createTable, modal, confirmDialog,
    toastSuccess, toastError, toastApiError, formatDateTime, formatNumber,
    statusBadge, skeletonRows, emptyState, showFormErrors, clearFormErrors,
    formValues, buttonBusy, debounce,
} from '../ui.js';

const TABS = [
    { id: 'once', labelKey: 'admin.campaigns.tab_once' },
    { id: 'auto', labelKey: 'admin.campaigns.tab_auto' },
    { id: 'log',  labelKey: 'admin.campaigns.tab_log' },
];

/** Draft state for the wizard, reset whenever it is opened. */
let draft = null;
let segments = [];
let editorInstance = null;

export async function render(container, ctx) {
    if (ctx.isAllSalons) {
        container.appendChild(pageHeader({ title: t('admin.campaigns.title') }));
        container.appendChild(emptyState({
            art: 'mail',
            title: t('admin.campaigns.pick_salon_title'),
            text: t('admin.campaigns.pick_salon_text'),
        }));
        return;
    }

    const active = (window.location.hash.split('?')[1] || '').replace('tab=', '') || 'once';

    container.appendChild(pageHeader({
        title: t('admin.campaigns.title'),
        subtitle: t('admin.campaigns.subtitle'),
        actions: active === 'once'
            ? [{
                label: t('admin.campaigns.new'),
                variant: 'primary',
                icon: '＋',
                onClick: () => openWizard(ctx),
            }]
            : [],
    }));

    const body = el('<div></div>');
    container.appendChild(subTabs(
        TABS.map((tab) => ({ id: tab.id, label: t(tab.labelKey) })),
        active,
        (id) => { window.location.hash = `#/kampagnen?tab=${id}`; }
    ));
    container.appendChild(body);

    body.appendChild(skeletonRows(6));

    try {
        if (active === 'auto') await renderAuto(body, ctx);
        else if (active === 'log') await renderLog(body, ctx, true);
        else await renderOnce(body, ctx);
    } catch (error) {
        body.innerHTML = '';
        toastApiError(error);
    }
}

function scope(ctx) {
    return `salon_id=${encodeURIComponent(ctx.salonId)}`;
}

/* ============================================================
   Einmal-Kampagne: list of drafts + the wizard entry point
   ============================================================ */

async function renderOnce(host, ctx) {
    const data = await apiGet(`campaigns.php?${scope(ctx)}&action=list`, { salonScope: false });
    const campaigns = (data.campaigns || []).filter((c) => c.kind === 'once');

    host.innerHTML = '';

    if (!campaigns.length) {
        host.appendChild(emptyState({
            art: 'mail',
            title: t('admin.campaigns.empty_title'),
            text: t('admin.campaigns.empty_text'),
            action: { label: t('admin.campaigns.new'), onClick: () => openWizard(ctx) },
        }));
        return;
    }

    host.appendChild(campaignTable(campaigns, ctx).element);
}

function campaignTable(campaigns, ctx) {
    const table = createTable({
        rows: campaigns,
        searchKeys: ['name', 'subject'],
        searchPlaceholderKey: 'admin.campaigns.search',
        defaultSort: { key: 'created_at', dir: 'desc' },
        empty: { art: 'mail', title: t('admin.campaigns.empty_title') },
        columns: [
            {
                key: 'name',
                labelKey: 'admin.campaigns.name',
                sortable: true,
                primary: true,
                render: (c) => `
                    <span class="cell-strong">${esc(c.name)}</span>
                    <div class="text-xs text-muted">${esc(c.subject)}</div>`,
            },
            {
                key: 'status',
                labelKey: 'admin.campaigns.status',
                sortable: true,
                render: (c) => statusBadge(c.status),
            },
            {
                key: 'recipient_count',
                labelKey: 'admin.campaigns.recipients',
                sortable: true,
                className: 'cell-num',
                render: (c) => formatNumber(c.sent_count || c.recipient_count || 0),
            },
            {
                key: 'open_rate',
                labelKey: 'admin.campaigns.open_rate',
                sortable: true,
                className: 'cell-num',
                render: (c) => c.open_rate === null
                    ? '<span class="cell-muted">—</span>'
                    : `${esc(c.open_rate)} %`,
            },
            {
                key: 'completed_at',
                labelKey: 'admin.campaigns.sent_at',
                sortable: true,
                render: (c) => c.completed_at
                    ? `<span class="cell-muted">${esc(formatDateTime(c.completed_at))}</span>`
                    : (c.scheduled_at
                        ? `<span class="badge badge-info">${esc(formatDateTime(c.scheduled_at))}</span>`
                        : '<span class="cell-muted">—</span>'),
            },
            {
                key: 'actions',
                label: '',
                className: 'cell-actions',
                cardHidden: true,
                render: (c) => (c.status === 'draft' || c.status === 'scheduled')
                    ? `<button type="button" class="btn btn-ghost btn-sm" data-edit="${c.campaign_id}">${esc(t('admin.common.edit'))}</button>
                       <button type="button" class="btn btn-ghost btn-sm" style="color:var(--danger-600)" data-cancel="${c.campaign_id}">${esc(t('admin.campaigns.cancel_campaign'))}</button>`
                    : '',
            },
        ],
    });

    table.element.addEventListener('click', async (event) => {
        const editId = event.target.closest('[data-edit]')?.dataset.edit;
        if (editId) return openWizard(ctx, Number(editId));

        const cancelId = event.target.closest('[data-cancel]')?.dataset.cancel;
        if (cancelId) {
            const ok = await confirmDialog({
                title: t('admin.campaigns.cancel_campaign'),
                message: t('admin.campaigns.cancel_confirm'),
                confirmLabel: t('admin.campaigns.cancel_campaign'),
            });
            if (!ok) return;
            try {
                await apiPost(`campaigns.php?${scope(ctx)}&action=cancel`,
                    { campaign_id: Number(cancelId) }, { salonScope: false });
                toastSuccess(t('admin.campaigns.cancelled'));
                ctx.reload();
            } catch (error) {
                toastApiError(error);
            }
        }
    });

    return table;
}

/* ============================================================
   The four-step wizard
   ============================================================ */

async function openWizard(ctx, campaignId = null) {
    draft = {
        campaign_id: campaignId,
        name: '',
        subject: '',
        body: '',
        recipient_type: 'all',
        recipient_ref: null,
        manual_ids: [],
        discount_enabled: false,
        discount_mode: 'generic',
        discount_code: '',
        discount_type: 'percentage',
        discount_value: 10,
        skip_over_limit: true,
    };

    // Segments feed the "Bestimmtes Segment" option.
    try {
        const data = await apiGet(`segments.php?${scope(ctx)}`, { salonScope: false });
        segments = data.segments || [];
    } catch {
        segments = [];
    }

    if (campaignId) {
        try {
            const data = await apiGet(`campaigns.php?${scope(ctx)}&action=get&campaign_id=${campaignId}`,
                { salonScope: false });
            const c = data.campaign;
            Object.assign(draft, {
                name: c.name,
                subject: c.subject,
                body: c.body,
                recipient_type: c.recipient_type,
                recipient_ref: c.recipient_ref,
                manual_ids: c.recipient_type === 'manual' ? JSON.parse(c.recipient_ref || '[]') : [],
                discount_enabled: Number(c.discount_enabled) === 1,
                discount_mode: c.discount_mode || 'generic',
                discount_code: c.discount_code || '',
                discount_type: c.discount_type || 'percentage',
                discount_value: Number(c.discount_value || 10),
                skip_over_limit: Number(c.skip_over_limit) === 1,
            });
        } catch (error) {
            toastApiError(error);
            return;
        }
    }

    const body = el('<div></div>');
    const dialog = modal({
        title: campaignId ? t('admin.campaigns.edit') : t('admin.campaigns.new'),
        body,
        size: 'xl',
        onClose: () => { editorInstance = null; },
    });

    renderStep(1, body, dialog, ctx);
}

const STEP_COUNT = 4;

function renderStep(step, host, dialog, ctx) {
    host.innerHTML = '';

    // Progress indicator so the four steps read as one flow.
    const labels = [
        t('admin.campaigns.step_recipients'),
        t('admin.campaigns.step_compose'),
        t('admin.campaigns.step_discount'),
        t('admin.campaigns.step_review'),
    ];
    host.appendChild(el(`
        <div class="row" style="gap:var(--sp-2);margin-bottom:var(--sp-6)">
            ${labels.map((label, index) => {
                const n = index + 1;
                const state = n === step ? 'active' : (n < step ? 'done' : '');
                return `<div class="chip ${state === 'active' ? 'active' : ''}"
                             style="${state === 'done' ? 'opacity:.7' : ''};cursor:default">
                            ${n < step ? '✓' : n}. ${esc(label)}
                        </div>`;
            }).join('')}
        </div>
    `));

    const content = el('<div></div>');
    host.appendChild(content);

    if (step === 1) stepRecipients(content, ctx);
    else if (step === 2) stepCompose(content, ctx);
    else if (step === 3) stepDiscount(content, ctx);
    else stepReview(content, ctx);

    // Footer navigation, rebuilt per step.
    const footer = el('<div class="modal-footer" style="margin:var(--sp-6) calc(-1 * var(--sp-6)) calc(-1 * var(--sp-6))"></div>');

    if (step > 1) {
        const back = el(`<button type="button" class="btn btn-secondary">${esc(t('admin.campaigns.back'))}</button>`);
        back.addEventListener('click', () => renderStep(step - 1, host, dialog, ctx));
        footer.appendChild(back);
    }

    if (step < STEP_COUNT) {
        const next = el(`<button type="button" class="btn btn-primary">${esc(t('admin.campaigns.next'))}</button>`);
        next.addEventListener('click', async () => {
            if (await validateStep(step, content)) renderStep(step + 1, host, dialog, ctx);
        });
        footer.appendChild(next);
    }

    host.appendChild(footer);

    if (step === STEP_COUNT) {
        renderReviewActions(footer, dialog, ctx);
    }
}

/** Pull the current step's inputs into the draft and check them. */
async function validateStep(step, content) {
    if (step === 2) {
        const form = content.querySelector('form');
        clearFormErrors(form);
        const values = formValues(form);

        draft.name = values.name || '';
        draft.subject = values.subject || '';
        draft.body = editorInstance ? editorInstance.root.innerHTML : (values.body || '');

        const errors = {};
        if (!draft.name) errors.name = t('admin.validation.required');
        if (!draft.subject) errors.subject = t('admin.validation.required');
        if (!draft.body || !draft.body.replace(/<[^>]*>/g, '').trim()) {
            errors.body = t('admin.validation.required');
        }
        if (Object.keys(errors).length) {
            showFormErrors(form, errors);
            return false;
        }
    }

    if (step === 3) {
        const form = content.querySelector('form');
        clearFormErrors(form);
        const values = formValues(form);

        draft.discount_enabled = form.elements.discount_enabled.checked;
        if (draft.discount_enabled) {
            draft.discount_mode = values.discount_mode || 'generic';
            draft.discount_code = values.discount_code || '';
            draft.discount_type = values.discount_type || 'percentage';
            draft.discount_value = Number(values.discount_value) || 0;

            const errors = {};
            if (draft.discount_mode === 'generic' && !draft.discount_code) {
                errors.discount_code = t('admin.validation.required');
            }
            if (draft.discount_value <= 0) errors.discount_value = t('admin.validation.positive');
            if (Object.keys(errors).length) {
                showFormErrors(form, errors);
                return false;
            }
        }
    }

    if (step === 1 && draft.recipient_type === 'segment' && !draft.recipient_ref) {
        toastError(t('admin.campaigns.pick_segment'));
        return false;
    }
    if (step === 1 && draft.recipient_type === 'manual' && !draft.manual_ids.length) {
        toastError(t('admin.campaigns.pick_customers'));
        return false;
    }

    return true;
}

/* ---------- Step 1: recipients ---------- */

function stepRecipients(host, ctx) {
    const options = [
        ['all', t('admin.campaigns.recipients_all'), t('admin.campaigns.recipients_all_desc')],
        ['members', t('admin.campaigns.recipients_members'), t('admin.campaigns.recipients_members_desc')],
        ['segment', t('admin.campaigns.recipients_segment'), t('admin.campaigns.recipients_segment_desc')],
        ['manual', t('admin.campaigns.recipients_manual'), t('admin.campaigns.recipients_manual_desc')],
    ];

    host.appendChild(el(`
        <div class="stack">
            ${options.map(([value, title, desc]) => `
                <label class="check">
                    <input type="radio" name="recipient_type" value="${esc(value)}" ${draft.recipient_type === value ? 'checked' : ''}>
                    <span class="check-text">
                        <span class="check-title">${esc(title)}</span>
                        <span class="check-desc">${esc(desc)}</span>
                    </span>
                </label>`).join('')}
            <div id="segment-picker" class="hidden"></div>
            <div id="manual-picker" class="hidden"></div>
            <div id="recipient-summary" class="alert alert-info"></div>
        </div>
    `));

    const segmentPicker = host.querySelector('#segment-picker');
    const manualPicker = host.querySelector('#manual-picker');

    // Segment dropdown, with each segment's live count.
    segmentPicker.appendChild(el(`
        <div class="field">
            <label class="field-label" for="segment_id">${esc(t('admin.campaigns.choose_segment'))}</label>
            <select class="select" id="segment_id">
                <option value="">${esc(t('admin.campaigns.choose_segment'))}</option>
                ${segments.map((s) =>
                    `<option value="${s.segment_id}" ${String(draft.recipient_ref) === String(s.segment_id) ? 'selected' : ''}>
                        ${esc(s.name)} (${formatNumber(s.count)})
                     </option>`).join('')}
            </select>
        </div>
    `));

    manualPicker.appendChild(el(`
        <div class="field">
            <button type="button" class="btn btn-secondary" id="pick-customers">
                ${esc(t('admin.campaigns.pick_customers_button'))}
            </button>
            <span class="field-hint" id="manual-count">${
                draft.manual_ids.length
                    ? esc(t('admin.campaigns.manual_selected', { count: draft.manual_ids.length }))
                    : esc(t('admin.campaigns.manual_none'))
            }</span>
        </div>
    `));

    const sync = () => {
        segmentPicker.classList.toggle('hidden', draft.recipient_type !== 'segment');
        manualPicker.classList.toggle('hidden', draft.recipient_type !== 'manual');
        refreshRecipientSummary(host, ctx);
    };

    host.querySelectorAll('input[name=recipient_type]').forEach((radio) => {
        radio.addEventListener('change', () => {
            draft.recipient_type = radio.value;
            sync();
        });
    });

    host.querySelector('#segment_id').addEventListener('change', (event) => {
        draft.recipient_ref = event.target.value || null;
        refreshRecipientSummary(host, ctx);
    });

    host.querySelector('#pick-customers').addEventListener('click', () => openCustomerPicker(host, ctx));

    sync();
}

/** Ask the server how many people this selection reaches. */
async function refreshRecipientSummary(host, ctx) {
    const box = host.querySelector('#recipient-summary');
    if (!box) return;
    box.innerHTML = `<span class="spinner"></span>`;

    const params = new URLSearchParams({ action: 'recipients', recipient_type: draft.recipient_type });
    if (draft.recipient_type === 'segment' && draft.recipient_ref) {
        params.set('recipient_ref', draft.recipient_ref);
    }
    if (draft.recipient_type === 'manual') {
        params.set('recipient_ref', JSON.stringify(draft.manual_ids));
    }

    try {
        const data = await apiGet(`campaigns.php?${scope(ctx)}&${params}`, { salonScope: false });
        draft._summary = data;

        box.innerHTML = `
            <div>
                <div class="alert-title">${esc(t('admin.campaigns.will_reach', { count: formatNumber(data.reachable) }))}</div>
                <div class="text-sm">${esc(t('admin.campaigns.consent_note', {
                    total: formatNumber(data.total_customers),
                    reachable: formatNumber(data.reachable),
                }))}</div>
            </div>`;
    } catch (error) {
        box.innerHTML = `<div class="text-sm">${esc(error?.message || t('admin.errors.generic'))}</div>`;
    }
}

function openCustomerPicker(host, ctx) {
    const selected = new Set(draft.manual_ids.map(Number));

    const body = el(`
        <div class="stack">
            <div class="table-search">
                <span class="table-search-icon" aria-hidden="true">🔍</span>
                <input type="search" class="input" id="picker-search" placeholder="${esc(t('admin.customers.search'))}">
            </div>
            <div id="picker-list" style="max-height:340px;overflow-y:auto"></div>
        </div>
    `);

    const list = body.querySelector('#picker-list');

    const load = async (query = '') => {
        list.innerHTML = '<div class="skeleton skeleton-row"></div>'.repeat(4);
        try {
            const data = await apiGet(
                `campaigns.php?${scope(ctx)}&action=customers&q=${encodeURIComponent(query)}`,
                { salonScope: false }
            );
            list.innerHTML = '';
            (data.customers || []).forEach((customer) => {
                const row = el(`
                    <label class="check" style="margin-bottom:6px">
                        <input type="checkbox" value="${customer.customer_id}" ${selected.has(customer.customer_id) ? 'checked' : ''}>
                        <span class="check-text">
                            <span class="check-title">${esc(customer.full_name)}</span>
                            <span class="check-desc">${esc(customer.email)} · ${esc(t('admin.customers.visits'))}: ${customer.visit_count}</span>
                        </span>
                    </label>
                `);
                row.querySelector('input').addEventListener('change', (event) => {
                    if (event.target.checked) selected.add(customer.customer_id);
                    else selected.delete(customer.customer_id);
                });
                list.appendChild(row);
            });
            if (!data.customers?.length) {
                list.appendChild(el(`<p class="text-sm text-muted">${esc(t('admin.common.no_results'))}</p>`));
            }
        } catch (error) {
            list.innerHTML = '';
            toastApiError(error);
        }
    };

    body.querySelector('#picker-search').addEventListener('input', debounce((e) => load(e.target.value), 300));
    load();

    modal({
        title: t('admin.campaigns.pick_customers_button'),
        subtitle: t('admin.campaigns.picker_hint'),
        body,
        size: 'lg',
        actions: [
            { label: t('admin.common.cancel'), variant: 'secondary', onClick: (close) => close() },
            {
                label: t('admin.common.confirm'),
                variant: 'primary',
                onClick: (close) => {
                    draft.manual_ids = [...selected];
                    const label = host.querySelector('#manual-count');
                    if (label) {
                        label.textContent = draft.manual_ids.length
                            ? t('admin.campaigns.manual_selected', { count: draft.manual_ids.length })
                            : t('admin.campaigns.manual_none');
                    }
                    refreshRecipientSummary(host, ctx);
                    close();
                },
            },
        ],
    });
}

/* ---------- Step 2: compose ---------- */

function stepCompose(host, ctx) {
    const form = el(`
        <form novalidate>
            <div class="form-grid">
                <div class="field">
                    <label class="field-label" for="name">${esc(t('admin.campaigns.name'))}<span class="req">*</span></label>
                    <input class="input" id="name" name="name" value="${esc(draft.name)}"
                           placeholder="${esc(t('admin.campaigns.name_placeholder'))}">
                    <span class="field-hint">${esc(t('admin.campaigns.name_hint'))}</span>
                </div>
                <div class="field">
                    <label class="field-label" for="subject">${esc(t('admin.campaigns.subject'))}<span class="req">*</span></label>
                    <input class="input" id="subject" name="subject" value="${esc(draft.subject)}"
                           placeholder="${esc(t('admin.campaigns.subject_placeholder'))}">
                </div>
                <div class="field">
                    <label class="field-label">${esc(t('admin.campaigns.body'))}<span class="req">*</span></label>
                    <div id="editor-host"></div>
                    <textarea name="body" class="textarea hidden">${esc(draft.body)}</textarea>
                    <div class="row mt-2" id="token-row">
                        <span class="text-xs text-muted">${esc(t('admin.campaigns.tokens'))}</span>
                    </div>
                </div>
            </div>
        </form>
    `);

    host.appendChild(form);

    // Placeholder chips insert at the cursor.
    const tokenRow = form.querySelector('#token-row');
    ['vorname', 'salonname', 'rabattcode'].forEach((token) => {
        const chip = el(`<button type="button" class="chip btn-sm">{${esc(token)}}</button>`);
        chip.addEventListener('click', () => insertToken(form, `{${token}}`));
        tokenRow.appendChild(chip);
    });

    const preview = el(`<button type="button" class="btn btn-secondary btn-sm">👁 ${esc(t('admin.campaigns.preview'))}</button>`);
    preview.addEventListener('click', () => openPreview(form, ctx));
    tokenRow.appendChild(preview);

    setupEditor(form);
}

/**
 * Quill when it is available, a plain textarea when it is not. The CDN can be
 * unreachable, and losing the rich editor must not stop a salon writing a mail.
 */
function setupEditor(form) {
    const editorHost = form.querySelector('#editor-host');
    const textarea = form.querySelector('textarea[name=body]');

    if (typeof Quill === 'undefined') {
        textarea.classList.remove('hidden');
        textarea.rows = 12;
        editorHost.appendChild(el(
            `<p class="field-hint">${esc(t('admin.campaigns.editor_fallback'))}</p>`
        ));
        editorInstance = null;
        return;
    }

    editorHost.style.background = 'var(--surface)';
    editorHost.style.borderRadius = 'var(--r-md)';
    const target = el('<div style="min-height:220px"></div>');
    editorHost.appendChild(target);

    editorInstance = new Quill(target, {
        theme: 'snow',
        placeholder: t('admin.campaigns.body_placeholder'),
        modules: {
            toolbar: [
                [{ header: [2, 3, false] }],
                ['bold', 'italic', 'underline'],
                [{ list: 'ordered' }, { list: 'bullet' }],
                ['link', 'image'],
                ['clean'],
            ],
        },
    });

    if (draft.body) editorInstance.root.innerHTML = draft.body;
}

function insertToken(form, token) {
    if (editorInstance) {
        const range = editorInstance.getSelection(true);
        editorInstance.insertText(range ? range.index : editorInstance.getLength(), token);
        return;
    }
    const textarea = form.querySelector('textarea[name=body]');
    const start = textarea.selectionStart ?? textarea.value.length;
    textarea.value = textarea.value.slice(0, start) + token + textarea.value.slice(start);
    textarea.focus();
}

async function openPreview(form, ctx) {
    const values = formValues(form);
    const body = editorInstance ? editorInstance.root.innerHTML : values.body;

    const host = el('<div><div class="skeleton skeleton-row"></div></div>');
    modal({
        title: t('admin.campaigns.preview'),
        subtitle: t('admin.campaigns.preview_hint'),
        body: host,
        size: 'lg',
        actions: [{ label: t('admin.common.close'), variant: 'secondary', onClick: (close) => close() }],
    });

    try {
        const data = await apiPost(`campaigns.php?${scope(ctx)}&action=preview`, {
            subject: values.subject,
            body,
            discount_enabled: draft.discount_enabled,
            discount_mode: draft.discount_mode,
            discount_code: draft.discount_code,
        }, { salonScope: false });

        host.innerHTML = '';
        host.appendChild(el(`
            <div class="stack">
                <div class="kv">
                    <dt>${esc(t('admin.campaigns.subject'))}</dt><dd><strong>${esc(data.subject)}</strong></dd>
                    <dt>${esc(t('admin.campaigns.preview_sample'))}</dt><dd>${esc(data.sample_customer || '—')}</dd>
                </div>
            </div>
        `));

        // The rendered mail goes in a sandboxed iframe: it is salon-authored
        // HTML with its own styles, which must not leak into the dashboard.
        const frame = el('<iframe title="preview" sandbox="" style="width:100%;height:460px;border:1px solid var(--border);border-radius:var(--r-md);background:#fff"></iframe>');
        host.appendChild(frame);
        frame.srcdoc = data.html;
    } catch (error) {
        host.innerHTML = '';
        host.appendChild(el(`<p class="text-sm">${esc(error?.message || t('admin.errors.generic'))}</p>`));
    }
}

/* ---------- Step 3: discount ---------- */

function stepDiscount(host) {
    const form = el(`
        <form novalidate>
            <div class="stack">
                <label class="switch">
                    <input type="checkbox" name="discount_enabled" ${draft.discount_enabled ? 'checked' : ''}>
                    <span class="switch-track"></span>
                    <span class="switch-label">${esc(t('admin.campaigns.add_discount'))}</span>
                </label>

                <div id="discount-fields" class="${draft.discount_enabled ? '' : 'hidden'}">
                    <div class="stack">
                        <label class="check">
                            <input type="radio" name="discount_mode" value="generic" ${draft.discount_mode !== 'unique' ? 'checked' : ''}>
                            <span class="check-text">
                                <span class="check-title">${esc(t('admin.campaigns.discount_generic'))}</span>
                                <span class="check-desc">${esc(t('admin.campaigns.discount_generic_desc'))}</span>
                            </span>
                        </label>
                        <label class="check">
                            <input type="radio" name="discount_mode" value="unique" ${draft.discount_mode === 'unique' ? 'checked' : ''}>
                            <span class="check-text">
                                <span class="check-title">${esc(t('admin.campaigns.discount_unique'))}</span>
                                <span class="check-desc">${esc(t('admin.campaigns.discount_unique_desc'))}</span>
                            </span>
                        </label>

                        <div class="form-grid form-grid-2">
                            <div class="field">
                                <label class="field-label" for="discount_code" id="code-label">${esc(t('admin.campaigns.discount_code'))}</label>
                                <input class="input" id="discount_code" name="discount_code" value="${esc(draft.discount_code)}"
                                       placeholder="SOMMER10" style="text-transform:uppercase">
                            </div>
                            <div class="field">
                                <label class="field-label" for="discount_type">${esc(t('admin.settings.loyalty_discount_type'))}</label>
                                <select class="select" id="discount_type" name="discount_type">
                                    <option value="percentage" ${draft.discount_type === 'percentage' ? 'selected' : ''}>${esc(t('admin.settings.loyalty_percentage'))}</option>
                                    <option value="fixed_eur" ${draft.discount_type === 'fixed_eur' ? 'selected' : ''}>${esc(t('admin.settings.loyalty_fixed'))}</option>
                                </select>
                            </div>
                            <div class="field">
                                <label class="field-label" for="discount_value">${esc(t('admin.settings.loyalty_discount_value'))}</label>
                                <input class="input" id="discount_value" name="discount_value" type="number" min="0.5" step="0.5"
                                       value="${esc(draft.discount_value)}">
                            </div>
                        </div>
                        <p class="card-hint">${esc(t('admin.campaigns.discount_token_hint'))}</p>
                    </div>
                </div>
            </div>
        </form>
    `);

    host.appendChild(form);

    const fields = form.querySelector('#discount-fields');
    form.elements.discount_enabled.addEventListener('change', (event) => {
        fields.classList.toggle('hidden', !event.target.checked);
    });

    // With unique codes the entered value becomes a prefix, not the code.
    const label = form.querySelector('#code-label');
    const syncMode = () => {
        const unique = form.querySelector('input[name=discount_mode]:checked').value === 'unique';
        label.textContent = unique ? t('admin.campaigns.discount_prefix') : t('admin.campaigns.discount_code');
    };
    form.querySelectorAll('input[name=discount_mode]').forEach((r) => r.addEventListener('change', syncMode));
    syncMode();
}

/* ---------- Step 4: review ---------- */

function stepReview(host, ctx) {
    const summary = draft._summary || {};
    const overLimit = summary.over_limit || 0;

    const recipientLabel = {
        all: t('admin.campaigns.recipients_all'),
        members: t('admin.campaigns.recipients_members'),
        segment: (segments.find((s) => String(s.segment_id) === String(draft.recipient_ref))?.name) || t('admin.campaigns.recipients_segment'),
        manual: t('admin.campaigns.manual_selected', { count: draft.manual_ids.length }),
    }[draft.recipient_type];

    host.appendChild(el(`
        <div class="stack">
            <div class="card">
                <div class="card-body">
                    <dl class="kv">
                        <dt>${esc(t('admin.campaigns.name'))}</dt><dd><strong>${esc(draft.name)}</strong></dd>
                        <dt>${esc(t('admin.campaigns.recipients'))}</dt>
                        <dd>${esc(recipientLabel)} — <strong>${esc(formatNumber(overLimit && draft.skip_over_limit ? summary.within_limit : summary.reachable || 0))}</strong> ${esc(t('admin.campaigns.people'))}</dd>
                        <dt>${esc(t('admin.campaigns.subject'))}</dt><dd>${esc(draft.subject)}</dd>
                        <dt>${esc(t('admin.campaigns.discount'))}</dt>
                        <dd>${draft.discount_enabled
                            ? esc(`${draft.discount_mode === 'unique' ? t('admin.campaigns.discount_unique') : draft.discount_code} · ${draft.discount_value}${draft.discount_type === 'percentage' ? ' %' : ' €'}`)
                            : esc(t('admin.common.no'))}</dd>
                    </dl>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3>${esc(t('admin.campaigns.body_preview'))}</h3></div>
                <div class="card-body">
                    <div style="border:1px solid var(--border);border-radius:var(--r-md);padding:var(--sp-4);max-height:220px;overflow:auto">
                        ${draft.body}
                    </div>
                </div>
            </div>

            <div id="limit-warning"></div>
        </div>
    `));

    // The spam-limit warning is the important part of this step.
    const warning = host.querySelector('#limit-warning');
    if (overLimit > 0) {
        warning.appendChild(el(`
            <div class="alert alert-warning">
                <span aria-hidden="true">⚠</span>
                <div>
                    <div class="alert-title">${esc(t('admin.campaigns.limit_title', {
                        count: overLimit, limit: summary.spam_limit, days: summary.spam_window_days,
                    }))}</div>
                    <div class="text-sm">${esc(t('admin.campaigns.limit_text'))}</div>
                    <div class="stack mt-4" style="gap:var(--sp-2)">
                        <label class="check">
                            <input type="radio" name="limit_choice" value="skip" ${draft.skip_over_limit ? 'checked' : ''}>
                            <span class="check-text">
                                <span class="check-title">${esc(t('admin.campaigns.limit_skip', { count: overLimit }))}</span>
                            </span>
                        </label>
                        <label class="check">
                            <input type="radio" name="limit_choice" value="send" ${draft.skip_over_limit ? '' : 'checked'}>
                            <span class="check-text">
                                <span class="check-title">${esc(t('admin.campaigns.limit_send_anyway'))}</span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>
        `));

        warning.querySelectorAll('input[name=limit_choice]').forEach((radio) => {
            radio.addEventListener('change', () => {
                draft.skip_over_limit = radio.value === 'skip';
                renderStep(4, host.parentElement, null, ctx);
            });
        });
    }
}

function renderReviewActions(footer, dialog, ctx) {
    const schedule = el(`<button type="button" class="btn btn-secondary">🕐 ${esc(t('admin.campaigns.schedule'))}</button>`);
    schedule.addEventListener('click', () => openScheduleDialog(dialog, ctx));
    footer.appendChild(schedule);

    const send = el(`<button type="button" class="btn btn-primary">${esc(t('admin.campaigns.send_now'))}</button>`);
    send.addEventListener('click', async (event) => {
        const count = draft.skip_over_limit
            ? (draft._summary?.within_limit ?? 0)
            : (draft._summary?.reachable ?? 0);

        const ok = await confirmDialog({
            title: t('admin.campaigns.send_now'),
            message: t('admin.campaigns.send_confirm', { count: formatNumber(count) }),
            confirmLabel: t('admin.campaigns.send_now'),
            variant: 'primary',
        });
        if (!ok) return;

        const reset = buttonBusy(event.target, t('admin.campaigns.sending'));
        try {
            const campaignId = await saveDraft(ctx);
            const result = await apiPost(`campaigns.php?${scope(ctx)}&action=send`,
                { campaign_id: campaignId }, { salonScope: false });

            toastSuccess(t('admin.campaigns.sent_toast', {
                sent: result.sent, skipped: result.skipped, failed: result.failed,
            }));
            dialog.close();
            ctx.reload();
        } catch (error) {
            reset();
            toastApiError(error);
        }
    });
    footer.appendChild(send);
}

function openScheduleDialog(dialog, ctx) {
    const body = el(`
        <div class="field">
            <label class="field-label" for="scheduled_at">${esc(t('admin.campaigns.schedule_when'))}</label>
            <input class="input" id="scheduled_at" type="datetime-local">
            <span class="field-hint">${esc(t('admin.campaigns.schedule_hint'))}</span>
        </div>
    `);

    modal({
        title: t('admin.campaigns.schedule'),
        body,
        actions: [
            { label: t('admin.common.cancel'), variant: 'secondary', onClick: (close) => close() },
            {
                label: t('admin.campaigns.schedule'),
                variant: 'primary',
                onClick: async (close, button) => {
                    const value = body.querySelector('#scheduled_at').value;
                    if (!value) {
                        toastError(t('admin.validation.required'));
                        return;
                    }
                    const reset = buttonBusy(button, t('admin.common.saving'));
                    try {
                        const campaignId = await saveDraft(ctx);
                        await apiPost(`campaigns.php?${scope(ctx)}&action=schedule`, {
                            campaign_id: campaignId,
                            scheduled_at: value.replace('T', ' ') + ':00',
                        }, { salonScope: false });
                        toastSuccess(t('admin.campaigns.scheduled_toast'));
                        close();
                        dialog.close();
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

/** Persist the draft and return its id. */
async function saveDraft(ctx) {
    const payload = {
        campaign_id: draft.campaign_id,
        name: draft.name,
        subject: draft.subject,
        body: draft.body,
        recipient_type: draft.recipient_type,
        recipient_ref: draft.recipient_type === 'manual' ? draft.manual_ids : draft.recipient_ref,
        discount_enabled: draft.discount_enabled,
        discount_mode: draft.discount_mode,
        discount_code: draft.discount_code,
        discount_type: draft.discount_type,
        discount_value: draft.discount_value,
        skip_over_limit: draft.skip_over_limit,
    };

    const data = await apiPost(`campaigns.php?${scope(ctx)}&action=save`, payload, { salonScope: false });
    draft.campaign_id = data.campaign_id;
    return data.campaign_id;
}

/* ============================================================
   Automatic campaigns
   ============================================================ */

const AUTO_ICONS = {
    birthday: '🎂',
    we_miss_you: '💌',
    thank_you: '🙏',
    referral_reminder: '👯',
};

async function renderAuto(host, ctx) {
    const data = await apiGet(`campaigns.php?${scope(ctx)}&action=auto`, { salonScope: false });
    host.innerHTML = '';

    const grid = el('<div class="grid grid-2"></div>');

    (data.campaigns || []).forEach((auto) => {
        const isBirthday = auto.type === 'birthday';
        const card = el(`
            <div class="card">
                <div class="card-header">
                    <span style="font-size:1.4rem" aria-hidden="true">${esc(AUTO_ICONS[auto.type] || '✉️')}</span>
                    <h3>${esc(t(`admin.campaigns.auto_${auto.type}`))}</h3>
                </div>
                <div class="card-body">
                    <p class="card-hint">${esc(t(`admin.campaigns.auto_${auto.type}_desc`))}</p>
                    <p class="text-sm mt-4"><strong>${esc(triggerLabel(auto))}</strong></p>
                    ${auto.last_run_at
                        ? `<p class="text-xs text-muted mt-2">${esc(t('admin.campaigns.last_run', { date: formatDateTime(auto.last_run_at) }))}</p>`
                        : ''}
                    ${isBirthday
                        ? `<p class="text-xs text-muted mt-2">${esc(t('admin.campaigns.birthday_from_settings'))}</p>`
                        : ''}
                </div>
                <div class="card-footer">
                    <label class="switch">
                        <input type="checkbox" ${auto.enabled ? 'checked' : ''} data-toggle="${esc(auto.type)}">
                        <span class="switch-track"></span>
                        <span class="switch-label">${esc(auto.enabled ? t('admin.status.active') : t('admin.status.inactive'))}</span>
                    </label>
                    <span style="flex:1"></span>
                    <button type="button" class="btn btn-secondary btn-sm" data-edit="${esc(auto.type)}">
                        ${esc(t('admin.common.edit'))}
                    </button>
                </div>
            </div>
        `);

        card.querySelector('[data-toggle]').addEventListener('change', async (event) => {
            try {
                await apiPost(`campaigns.php?${scope(ctx)}&action=save_auto`, {
                    ...auto,
                    enabled: event.target.checked,
                }, { salonScope: false });
                toastSuccess(event.target.checked
                    ? t('admin.campaigns.auto_enabled')
                    : t('admin.campaigns.auto_disabled'));
                ctx.reload();
            } catch (error) {
                event.target.checked = !event.target.checked;
                toastApiError(error);
            }
        });

        card.querySelector('[data-edit]').addEventListener('click', () => openAutoEditor(auto, ctx));
        grid.appendChild(card);
    });

    host.appendChild(grid);

    // Manual trigger, so a salon can see the effect without waiting for cron.
    const runRow = el(`
        <div class="card mt-6">
            <div class="card-body row">
                <div style="flex:1">
                    <div class="text-sm"><strong>${esc(t('admin.campaigns.cron_title'))}</strong></div>
                    <div class="text-xs text-muted">${esc(t('admin.campaigns.cron_hint'))}</div>
                </div>
            </div>
        </div>
    `);
    host.appendChild(runRow);
}

function triggerLabel(auto) {
    if (auto.type === 'birthday') return t('admin.campaigns.trigger_birthday');
    if (auto.trigger_unit === 'visits') return t('admin.campaigns.trigger_visits', { count: auto.trigger_value });
    if (auto.trigger_unit === 'days') return t('admin.campaigns.trigger_days', { count: auto.trigger_value });
    return t('admin.campaigns.trigger_weeks', { count: auto.trigger_value });
}

function openAutoEditor(auto, ctx) {
    const isBirthday = auto.type === 'birthday';

    const form = el(`
        <form novalidate>
            <div class="form-grid">
                ${!isBirthday ? `
                <div class="field">
                    <label class="field-label" for="trigger_value">${esc(t(`admin.campaigns.trigger_label_${auto.trigger_unit}`))}</label>
                    <input class="input" id="trigger_value" name="trigger_value" type="number" min="1"
                           value="${esc(auto.trigger_value)}" style="max-width:160px">
                </div>` : `
                <div class="alert alert-info">
                    <div class="text-sm">${esc(t('admin.campaigns.birthday_from_settings'))}</div>
                </div>`}
                <div class="field">
                    <label class="field-label" for="subject">${esc(t('admin.campaigns.subject'))}<span class="req">*</span></label>
                    <input class="input" id="subject" name="subject" value="${esc(auto.subject || '')}">
                </div>
                <div class="field">
                    <label class="field-label" for="body">${esc(t('admin.campaigns.body'))}<span class="req">*</span></label>
                    <textarea class="textarea" id="body" name="body" rows="8">${esc(auto.body || '')}</textarea>
                    <span class="field-hint">${esc(t('admin.campaigns.tokens'))} {vorname} {salonname} {rabattcode}</span>
                </div>
                <label class="switch">
                    <input type="checkbox" name="discount_enabled" ${auto.discount_enabled ? 'checked' : ''}>
                    <span class="switch-track"></span>
                    <span class="switch-label">${esc(t('admin.campaigns.add_discount'))}</span>
                </label>
                <div class="field">
                    <label class="field-label" for="discount_code">${esc(t('admin.campaigns.discount_code'))}</label>
                    <input class="input" id="discount_code" name="discount_code" value="${esc(auto.discount_code || '')}">
                </div>
            </div>
        </form>
    `);

    modal({
        title: t(`admin.campaigns.auto_${auto.type}`),
        subtitle: t('admin.campaigns.auto_edit_hint'),
        body: form,
        size: 'lg',
        actions: [
            { label: t('admin.common.cancel'), variant: 'secondary', onClick: (close) => close() },
            {
                label: t('admin.common.save'),
                variant: 'primary',
                onClick: async (close, button) => {
                    clearFormErrors(form);
                    const values = formValues(form);

                    const errors = {};
                    if (!values.subject) errors.subject = t('admin.validation.required');
                    if (!values.body || !values.body.trim()) errors.body = t('admin.validation.required');
                    if (Object.keys(errors).length) {
                        showFormErrors(form, errors);
                        return;
                    }

                    const reset = buttonBusy(button, t('admin.common.saving'));
                    try {
                        await apiPost(`campaigns.php?${scope(ctx)}&action=save_auto`, {
                            type: auto.type,
                            enabled: auto.enabled,
                            trigger_value: Number(values.trigger_value) || auto.trigger_value,
                            trigger_unit: auto.trigger_unit,
                            subject: values.subject,
                            body: values.body,
                            discount_enabled: form.elements.discount_enabled.checked,
                            discount_code: values.discount_code || '',
                            discount_type: auto.discount_type || 'fixed_eur',
                            discount_value: auto.discount_value || 0,
                        }, { salonScope: false });

                        toastSuccess(t('admin.campaigns.auto_saved'));
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

/* ============================================================
   Campaign log
   ============================================================ */

async function renderLog(host, ctx) {
    const data = await apiGet(`campaigns.php?${scope(ctx)}&action=list`, { salonScope: false });
    const sent = (data.campaigns || []).filter((c) => c.status !== 'draft');

    host.innerHTML = '';

    if (!sent.length) {
        host.appendChild(emptyState({
            art: 'mail',
            title: t('admin.campaigns.log_empty'),
            text: t('admin.campaigns.log_empty_text'),
        }));
        return;
    }

    const table = createTable({
        rows: sent,
        searchKeys: ['name', 'subject'],
        searchPlaceholderKey: 'admin.campaigns.search',
        defaultSort: { key: 'completed_at', dir: 'desc' },
        columns: [
            {
                key: 'name',
                labelKey: 'admin.campaigns.name',
                sortable: true,
                primary: true,
                render: (c) => `
                    <span class="cell-strong">${esc(c.name)}</span>
                    <div class="text-xs text-muted">${esc(c.subject)}</div>`,
            },
            {
                key: 'kind',
                labelKey: 'admin.campaigns.type',
                sortable: true,
                render: (c) => c.kind === 'auto'
                    ? `<span class="badge badge-info">${esc(t(`admin.campaigns.auto_${c.auto_type}`))}</span>`
                    : `<span class="badge badge-neutral">${esc(t('admin.campaigns.type_once'))}</span>`,
            },
            { key: 'status', labelKey: 'admin.campaigns.status', sortable: true, render: (c) => statusBadge(c.status) },
            {
                key: 'sent_count',
                labelKey: 'admin.campaigns.sent_count',
                sortable: true,
                className: 'cell-num',
                render: (c) => `${esc(formatNumber(c.sent_count))}` +
                    (c.skipped_count ? ` <span class="text-xs text-muted">(+${c.skipped_count} ${esc(t('admin.campaigns.skipped'))})</span>` : '') +
                    (c.failed_count ? ` <span class="text-xs" style="color:var(--danger-600)">(${c.failed_count} ${esc(t('admin.campaigns.failed'))})</span>` : ''),
            },
            {
                key: 'open_rate',
                labelKey: 'admin.campaigns.open_rate',
                sortable: true,
                className: 'cell-num',
                render: (c) => c.open_rate === null ? '<span class="cell-muted">—</span>' : `${esc(c.open_rate)} %`,
            },
            {
                key: 'click_rate',
                labelKey: 'admin.campaigns.click_rate',
                sortable: true,
                className: 'cell-num',
                render: (c) => c.click_rate === null ? '<span class="cell-muted">—</span>' : `${esc(c.click_rate)} %`,
            },
            {
                key: 'completed_at',
                labelKey: 'admin.campaigns.sent_at',
                sortable: true,
                render: (c) => `<span class="cell-muted">${esc(formatDateTime(c.completed_at || c.created_at))}</span>`,
            },
        ],
    });

    host.appendChild(table.element);
}
