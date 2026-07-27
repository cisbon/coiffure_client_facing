/**
 * Kunden (customer insights & segmentation)
 * ------------------------------------------------------------
 * The customer list plus everything spec 3.5 asks for: a filter bar, saved
 * segments, quick presets for best and inactive customers, a slide-over profile
 * with the visit timeline and consent state, and a consent-aware CSV export.
 *
 * The filter object built here is exactly what api/insights.php understands and
 * what a segment stores, so a segment saved from this screen selects the same
 * people when a campaign is later sent to it.
 */

import { apiGet, apiPost, apiDelete, apiDownload } from '../api.js';
import {
    t, esc, el, pageHeader, createTable, drawer, modal, confirmDialog,
    toastSuccess, toastError, toastApiError, formatDate, formatDateTime,
    formatNumber, boolBadge, skeletonRows, emptyState, showFormErrors,
    clearFormErrors, formValues, buttonBusy, debounce, initials,
} from '../ui.js';

/** Live filter state; mirrors the shape api/customer_filters.php normalises. */
let filter = {};
let segments = [];
let customers = [];
let total = 0;
let sort = { key: 'created_at', dir: 'desc' };
let activeSegmentId = null;
/** The filter-bar container, so the result count can be refreshed after a load. */
let filterHostRef = null;

export async function render(container, ctx) {
    // Start clean whenever the view is entered so a stale filter never carries
    // over into a different salon.
    filter = {};
    activeSegmentId = null;
    sort = { key: 'created_at', dir: 'desc' };

    container.appendChild(pageHeader({
        title: t('admin.customers.title'),
        subtitle: ctx.isAllSalons ? t('admin.customers.subtitle_all') : t('admin.customers.subtitle'),
        actions: [
            {
                label: t('admin.customers.export'),
                icon: '⬇',
                onClick: () => openExportDialog(ctx),
            },
            {
                label: t('admin.customers.save_segment'),
                variant: 'primary',
                icon: '＋',
                onClick: () => openSaveSegmentDialog(ctx),
            },
        ],
    }));

    const host = el('<div class="stack"></div>');
    container.appendChild(host);

    const filterHost = el('<div></div>');
    const tableHost = el('<div></div>');
    filterHostRef = filterHost;
    host.appendChild(filterHost);
    host.appendChild(tableHost);

    tableHost.appendChild(skeletonRows(8));

    // Segments are per salon and meaningless in the aggregated view.
    if (!ctx.isAllSalons) {
        await loadSegments(ctx);
    }

    renderFilterBar(filterHost, tableHost, ctx);
    await loadCustomers(tableHost, ctx);
}

/* ============================================================
   Data
   ============================================================ */

function scopeQuery(ctx) {
    return ctx.isAllSalons ? 'salon_id=all' : `salon_id=${encodeURIComponent(ctx.salonId)}`;
}

/** Serialise the filter into query parameters insights.php understands. */
function filterQuery() {
    const params = new URLSearchParams();
    Object.entries(filter).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '' || value === false) return;
        params.set(key, value === true ? '1' : String(value));
    });
    return params.toString();
}

async function loadCustomers(tableHost, ctx) {
    tableHost.innerHTML = '';
    tableHost.appendChild(skeletonRows(8));

    try {
        const query = [
            scopeQuery(ctx),
            'action=list',
            `sort=${sort.key}`,
            `dir=${sort.dir}`,
            'per_page=500',
            filterQuery(),
        ].filter(Boolean).join('&');

        const data = await apiGet(`insights.php?${query}`, { salonScope: false });
        customers = data.customers || [];
        total = data.total || 0;
    } catch (error) {
        toastApiError(error);
        customers = [];
        total = 0;
    }

    tableHost.innerHTML = '';
    tableHost.appendChild(buildTable(ctx));

    // The count is only known after the request resolves; painting it during
    // renderFilterBar would always show the previous result.
    if (filterHostRef) updateCount(filterHostRef);
}

async function loadSegments(ctx) {
    try {
        const data = await apiGet(
            `segments.php?salon_id=${encodeURIComponent(ctx.salonId)}`,
            { salonScope: false }
        );
        segments = data.segments || [];
    } catch {
        // segments.php needs migration 019; the screen still works without it.
        segments = [];
    }
}

/* ============================================================
   Filter bar
   ============================================================ */

function renderFilterBar(host, tableHost, ctx) {
    host.innerHTML = '';

    const card = el(`
        <div class="card">
            <div class="card-body" style="padding-bottom:var(--sp-4)">
                <div class="row" style="margin-bottom:var(--sp-4)">
                    <div class="table-search" style="max-width:340px">
                        <span class="table-search-icon" aria-hidden="true">🔍</span>
                        <input type="search" class="input" id="f-search"
                               placeholder="${esc(t('admin.customers.search'))}"
                               aria-label="${esc(t('admin.customers.search'))}"
                               value="${esc(filter.search || '')}">
                    </div>
                    <span id="result-count" class="text-sm text-muted"></span>
                </div>

                <div class="row" id="preset-row" style="margin-bottom:var(--sp-4)"></div>

                <details id="advanced" ${hasAdvancedFilter() ? 'open' : ''}>
                    <summary style="cursor:pointer;font-size:0.86rem;font-weight:600;color:var(--brand-700);padding:var(--sp-2) 0">
                        ${esc(t('admin.customers.advanced_filters'))}
                    </summary>
                    <div class="form-grid form-grid-2" style="margin-top:var(--sp-4)">
                        <div class="field">
                            <label class="field-label" for="f-gender">${esc(t('admin.customers.gender'))}</label>
                            <select class="select" id="f-gender">
                                <option value="">${esc(t('admin.customers.any'))}</option>
                                <option value="female" ${filter.gender === 'female' ? 'selected' : ''}>${esc(t('admin.customers.gender_female'))}</option>
                                <option value="male" ${filter.gender === 'male' ? 'selected' : ''}>${esc(t('admin.customers.gender_male'))}</option>
                                <option value="diverse" ${filter.gender === 'diverse' ? 'selected' : ''}>${esc(t('admin.customers.gender_diverse'))}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label">${esc(t('admin.customers.age_range'))}</label>
                            <div class="row" style="flex-wrap:nowrap">
                                <input class="input" id="f-age-min" type="number" min="14" max="120"
                                       placeholder="${esc(t('admin.customers.from'))}" value="${esc(filter.age_min || '')}">
                                <input class="input" id="f-age-max" type="number" min="14" max="120"
                                       placeholder="${esc(t('admin.customers.to'))}" value="${esc(filter.age_max || '')}">
                            </div>
                            <span class="field-hint">${esc(t('admin.customers.age_hint'))}</span>
                        </div>
                        <div class="field">
                            <label class="field-label" for="f-zip">${esc(t('admin.customers.zip_area'))}</label>
                            <input class="input" id="f-zip" value="${esc(filter.zip || '')}"
                                   placeholder="${esc(t('admin.customers.zip_placeholder'))}">
                        </div>
                        <div class="field">
                            <label class="field-label" for="f-last-visit">${esc(t('admin.customers.last_visit'))}</label>
                            <select class="select" id="f-last-visit">
                                <option value="">${esc(t('admin.customers.any'))}</option>
                                <option value="in:2">${esc(t('admin.customers.visited_weeks', { weeks: 2 }))}</option>
                                <option value="in:6">${esc(t('admin.customers.visited_weeks', { weeks: 6 }))}</option>
                                <option value="out:6">${esc(t('admin.customers.not_visited_weeks', { weeks: 6 }))}</option>
                                <option value="out:10">${esc(t('admin.customers.not_visited_weeks', { weeks: 10 }))}</option>
                                <option value="out:26">${esc(t('admin.customers.not_visited_weeks', { weeks: 26 }))}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label" for="f-registered-from">${esc(t('admin.customers.registered_from'))}</label>
                            <input class="input" id="f-registered-from" type="date" value="${esc(filter.registered_from || '')}">
                        </div>
                        <div class="field">
                            <label class="field-label" for="f-registered-to">${esc(t('admin.customers.registered_to'))}</label>
                            <input class="input" id="f-registered-to" type="date" value="${esc(filter.registered_to || '')}">
                        </div>
                    </div>
                </details>
            </div>
        </div>
    `);

    host.appendChild(card);

    // Restore the last-visit dropdown from the filter.
    const lastVisit = card.querySelector('#f-last-visit');
    if (filter.visited_within_weeks) lastVisit.value = `in:${filter.visited_within_weeks}`;
    else if (filter.not_visited_within_weeks) lastVisit.value = `out:${filter.not_visited_within_weeks}`;

    const apply = () => {
        renderFilterBar(host, tableHost, ctx);
        loadCustomers(tableHost, ctx);
    };

    card.querySelector('#f-search').addEventListener('input', debounce((event) => {
        setFilter('search', event.target.value.trim());
        loadCustomers(tableHost, ctx);
        updateCount(host);
    }, 300));

    card.querySelector('#f-gender').addEventListener('change', (e) => { setFilter('gender', e.target.value); apply(); });
    card.querySelector('#f-zip').addEventListener('input', debounce((e) => { setFilter('zip', e.target.value.trim()); apply(); }, 350));
    card.querySelector('#f-age-min').addEventListener('change', (e) => { setFilter('age_min', e.target.value); apply(); });
    card.querySelector('#f-age-max').addEventListener('change', (e) => { setFilter('age_max', e.target.value); apply(); });
    card.querySelector('#f-registered-from').addEventListener('change', (e) => { setFilter('registered_from', e.target.value); apply(); });
    card.querySelector('#f-registered-to').addEventListener('change', (e) => { setFilter('registered_to', e.target.value); apply(); });

    lastVisit.addEventListener('change', (event) => {
        const value = event.target.value;
        delete filter.visited_within_weeks;
        delete filter.not_visited_within_weeks;
        if (value.startsWith('in:')) filter.visited_within_weeks = Number(value.slice(3));
        else if (value.startsWith('out:')) filter.not_visited_within_weeks = Number(value.slice(4));
        activeSegmentId = null;
        apply();
    });

    renderPresets(card.querySelector('#preset-row'), host, tableHost, ctx);
    updateCount(host);
}

function setFilter(key, value) {
    if (value === '' || value === null || value === undefined) delete filter[key];
    else filter[key] = value;
    // Editing a filter by hand means it is no longer "the segment".
    activeSegmentId = null;
}

function hasAdvancedFilter() {
    return ['gender', 'age_min', 'age_max', 'zip', 'registered_from', 'registered_to',
            'visited_within_weeks', 'not_visited_within_weeks']
        .some((key) => filter[key]);
}

function updateCount(host) {
    const node = host.querySelector('#result-count');
    if (node) node.textContent = t('admin.customers.matching', { count: formatNumber(total) });
}

/**
 * Quick presets and saved segments as one chip row: the presets the spec names
 * (all / members / best / inactive) plus whatever the salon saved.
 */
function renderPresets(row, host, tableHost, ctx) {
    row.innerHTML = '';

    const apply = (next, segmentId = null) => {
        filter = next;
        activeSegmentId = segmentId;
        renderFilterBar(host, tableHost, ctx);
        loadCustomers(tableHost, ctx);
    };

    const isActive = (predicate) => !activeSegmentId && predicate();

    const chips = [
        {
            label: t('admin.customers.preset_all'),
            active: isActive(() => Object.keys(filter).filter((k) => k !== 'search').length === 0),
            onClick: () => apply(filter.search ? { search: filter.search } : {}),
        },
        {
            label: t('admin.customers.preset_members'),
            active: isActive(() => filter.members_only === true),
            onClick: () => apply({ ...keepSearch(), members_only: true }),
        },
        {
            label: t('admin.customers.preset_best'),
            active: isActive(() => Number(filter.min_visits) === 5),
            onClick: () => {
                sort = { key: 'visits', dir: 'desc' };
                apply({ ...keepSearch(), min_visits: 5 });
            },
        },
        {
            label: t('admin.customers.preset_inactive_6'),
            active: isActive(() => Number(filter.not_visited_within_weeks) === 6),
            onClick: () => apply({ ...keepSearch(), not_visited_within_weeks: 6 }),
        },
        {
            label: t('admin.customers.preset_inactive_10'),
            active: isActive(() => Number(filter.not_visited_within_weeks) === 10),
            onClick: () => apply({ ...keepSearch(), not_visited_within_weeks: 10 }),
        },
    ];

    chips.forEach((chip) => {
        const node = el(`<button type="button" class="chip ${chip.active ? 'active' : ''}">${esc(chip.label)}</button>`);
        node.addEventListener('click', chip.onClick);
        row.appendChild(node);
    });

    // Saved segments (skip the ones duplicating a preset chip above).
    const custom = segments.filter((s) => !s.is_preset);
    if (custom.length) {
        row.appendChild(el('<span style="width:1px;height:24px;background:var(--border)"></span>'));

        custom.forEach((segment) => {
            const chip = el(`
                <button type="button" class="chip ${activeSegmentId === segment.segment_id ? 'active' : ''}"
                        title="${esc(segment.description || '')}">
                    ${esc(segment.name)} <span class="text-xs">${esc(formatNumber(segment.count))}</span>
                </button>
            `);
            chip.addEventListener('click', () => apply({ ...segment.filter }, segment.segment_id));

            // Right-click / long-press to delete a saved segment.
            chip.addEventListener('contextmenu', async (event) => {
                event.preventDefault();
                const ok = await confirmDialog({
                    title: t('admin.customers.delete_segment'),
                    message: t('admin.customers.delete_segment_confirm', { name: segment.name }),
                    confirmLabel: t('admin.common.delete'),
                });
                if (!ok) return;
                try {
                    await apiDelete(
                        `segments.php?salon_id=${encodeURIComponent(ctx.salonId)}&segment_id=${segment.segment_id}`,
                        { salonScope: false }
                    );
                    toastSuccess(t('admin.customers.segment_deleted'));
                    await loadSegments(ctx);
                    renderFilterBar(host, tableHost, ctx);
                } catch (error) {
                    toastApiError(error);
                }
            });

            row.appendChild(chip);
        });
    }

    function keepSearch() {
        return filter.search ? { search: filter.search } : {};
    }
}

/* ============================================================
   Table
   ============================================================ */

function buildTable(ctx) {
    const table = createTable({
        rows: customers,
        // Search is server-side (it must match against the full set, not the
        // page), so the table's own search box is left out.
        searchKeys: [],
        pageSize: 25,
        rowClick: (customer) => openProfile(customer.customer_id, ctx),
        empty: Object.keys(filter).length
            ? { art: 'search', title: t('admin.common.no_results'), text: t('admin.common.no_results_hint') }
            : { art: 'people', title: t('admin.customers.empty_title'), text: t('admin.customers.empty_text') },
        columns: [
            {
                key: 'full_name',
                labelKey: 'admin.customers.name',
                sortable: true,
                primary: true,
                render: (c) => `
                    <div class="row" style="flex-wrap:nowrap;gap:var(--sp-3)">
                        <span class="avatar" style="width:30px;height:30px;font-size:0.7rem;background:var(--brand-100);color:var(--brand-700)">${esc(initials(c.full_name))}</span>
                        <span>
                            <span class="cell-strong">${esc(c.full_name || '—')}</span>
                            <span class="text-xs text-muted" style="display:block">${esc(c.email || '')}</span>
                        </span>
                    </div>`,
            },
            {
                key: 'phone',
                labelKey: 'admin.customers.phone',
                render: (c) => esc(c.mobile || c.phone || '—'),
            },
            {
                key: 'age',
                labelKey: 'admin.customers.birthday',
                sortable: false,
                render: (c) => (c.birth_day && c.birth_month)
                    ? `${esc(String(c.birth_day).padStart(2, '0'))}.${esc(String(c.birth_month).padStart(2, '0'))}` +
                      (c.age ? ` <span class="text-xs text-muted">(${c.age})</span>` : '')
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
                render: (c) => boolBadge(c.is_member),
            },
            {
                key: 'last_visit',
                labelKey: 'admin.customers.last_visit_col',
                sortable: true,
                render: (c) => c.last_visit
                    ? `<span class="cell-muted">${esc(formatDate(c.last_visit))}</span>`
                    : `<span class="badge badge-neutral">${esc(t('admin.customers.never_visited'))}</span>`,
            },
            {
                key: 'visit_count',
                labelKey: 'admin.customers.visits',
                sortable: true,
                className: 'cell-num',
                render: (c) => `<span class="cell-strong">${esc(formatNumber(c.visit_count))}</span>`,
            },
        ],
        defaultSort: { key: sort.key === 'visits' ? 'visit_count' : sort.key, dir: sort.dir },
    });

    return table.element;
}

/* ============================================================
   Customer profile (slide-over)
   ============================================================ */

async function openProfile(customerId, ctx) {
    const panel = drawer({
        title: t('admin.customers.profile'),
        body: skeletonRows(8),
    });

    let data;
    try {
        data = await apiGet(
            `insights.php?${scopeQuery(ctx)}&action=profile&customer_id=${customerId}`,
            { salonScope: false }
        );
    } catch (error) {
        panel.body.innerHTML = '';
        panel.body.appendChild(emptyState({
            art: 'search',
            title: t('admin.errors.generic'),
            text: error?.message || '',
        }));
        return;
    }

    const c = data.customer;
    panel.element.querySelector('.modal-title').textContent = c.full_name;
    const subtitle = panel.element.querySelector('.modal-subtitle');
    const memberLabel = c.is_member
        ? t('admin.customers.member_since', { date: formatDate(c.member_since) })
        : t('admin.customers.not_member');
    if (subtitle) subtitle.textContent = memberLabel;
    else panel.element.querySelector('.modal-header-text')
        .appendChild(el(`<p class="modal-subtitle">${esc(memberLabel)}</p>`));

    panel.body.innerHTML = '';
    panel.body.appendChild(profileBody(data, ctx, panel));
}

function profileBody(data, ctx, panel) {
    const c = data.customer;
    const wrap = el('<div class="stack"></div>');

    // --- details ---
    wrap.appendChild(el(`
        <div class="card">
            <div class="card-body">
                <dl class="kv">
                    <dt>${esc(t('admin.customers.email'))}</dt><dd>${esc(c.email || '—')}</dd>
                    <dt>${esc(t('admin.customers.phone'))}</dt><dd>${esc(c.mobile || c.phone || '—')}</dd>
                    <dt>${esc(t('admin.customers.birthday'))}</dt>
                    <dd>${c.birth_day ? esc(`${String(c.birth_day).padStart(2, '0')}.${String(c.birth_month).padStart(2, '0')}.${c.birth_year || ''}`.replace(/\.$/, '')) : '—'}
                        ${c.age ? `<span class="text-xs text-muted"> (${c.age} ${esc(t('admin.customers.years'))})</span>` : ''}</dd>
                    <dt>${esc(t('admin.customers.location'))}</dt><dd>${esc([c.zip, c.city].filter(Boolean).join(' ') || '—')}</dd>
                    <dt>${esc(t('admin.customers.member'))}</dt><dd>${c.is_member ? esc(c.member_id || t('admin.common.yes')) : esc(t('admin.common.no'))}</dd>
                    <dt>${esc(t('admin.customers.registered'))}</dt><dd>${esc(formatDate(c.created_at))}</dd>
                    <dt>${esc(t('admin.customers.source'))}</dt><dd>${esc(c.referral_source || '—')}</dd>
                    <dt>${esc(t('admin.customers.visits'))}</dt><dd><strong>${esc(formatNumber(c.visit_count))}</strong></dd>
                </dl>
            </div>
        </div>
    `));

    // --- consent ---
    const consentRows = [
        ['email_marketing', 'admin.customers.consent_email'],
        ['sms_whatsapp', 'admin.customers.consent_sms'],
        ['postal', 'admin.customers.consent_postal'],
        ['data_processing', 'admin.customers.consent_data'],
    ];
    wrap.appendChild(el(`
        <div class="card">
            <div class="card-header"><h3>${esc(t('admin.customers.consent_title'))}</h3></div>
            <div class="card-body">
                <div class="stack" style="gap:var(--sp-3)">
                    ${consentRows.map(([key, labelKey]) => `
                        <div class="row" style="justify-content:space-between">
                            <span class="text-sm">${esc(t(labelKey))}</span>
                            ${boolBadge(c.consent[key])}
                        </div>`).join('')}
                </div>
                ${data.consent_history.length ? `
                    <p class="card-hint" style="margin-top:var(--sp-4)">
                        ${esc(t('admin.customers.consent_changes', { count: data.consent_history.length }))}
                    </p>` : ''}
            </div>
        </div>
    `));

    // --- notes & tags ---
    const notesCard = el(`
        <form class="card">
            <div class="card-header"><h3>${esc(t('admin.customers.notes_title'))}</h3></div>
            <div class="card-body">
                <div class="field">
                    <textarea class="textarea" name="notes" rows="4"
                              placeholder="${esc(t('admin.customers.notes_placeholder'))}">${esc(c.notes || '')}</textarea>
                </div>
                <div class="field mt-4">
                    <label class="field-label" for="tags">${esc(t('admin.customers.tags'))}</label>
                    <input class="input" id="tags" name="tags" value="${esc((c.tags || []).join(', '))}"
                           placeholder="${esc(t('admin.customers.tags_placeholder'))}">
                    <span class="field-hint">${esc(t('admin.customers.tags_hint'))}</span>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary btn-sm">${esc(t('admin.common.save'))}</button>
                ${c.notes_updated_at ? `<span class="text-xs text-muted" style="align-self:center">${esc(t('admin.customers.notes_updated', { date: formatDateTime(c.notes_updated_at) }))}</span>` : ''}
            </div>
        </form>
    `);

    notesCard.addEventListener('submit', async (event) => {
        event.preventDefault();
        const values = formValues(notesCard);
        const button = notesCard.querySelector('button[type=submit]');
        const reset = buttonBusy(button, t('admin.common.saving'));
        try {
            await apiPost(`insights.php?${scopeQuery(ctx)}&action=notes`, {
                customer_id: c.customer_id,
                notes: values.notes || '',
                tags: (values.tags || '').split(',').map((s) => s.trim()).filter(Boolean),
            }, { salonScope: false });
            toastSuccess(t('admin.customers.notes_saved'));
            reset();
        } catch (error) {
            reset();
            toastApiError(error);
        }
    });
    wrap.appendChild(notesCard);

    // --- campaign history ---
    if (data.campaigns.length) {
        wrap.appendChild(el(`
            <div class="card">
                <div class="card-header"><h3>${esc(t('admin.customers.campaign_history'))}</h3></div>
                <div class="card-body">
                    <div class="timeline">
                        ${data.campaigns.map((campaign) => `
                            <div class="timeline-item">
                                <div class="timeline-title">${esc(campaign.subject || campaign.name)}</div>
                                <div class="timeline-date">
                                    ${esc(formatDateTime(campaign.sent_at))}
                                    ${campaign.opened_at ? ` · ${esc(t('admin.customers.opened'))}` : ''}
                                    ${campaign.clicked_at ? ` · ${esc(t('admin.customers.clicked'))}` : ''}
                                </div>
                            </div>`).join('')}
                    </div>
                </div>
            </div>
        `));
    }

    // --- visit timeline ---
    wrap.appendChild(el(`
        <div class="card">
            <div class="card-header"><h3>${esc(t('admin.customers.visit_timeline'))}</h3></div>
            <div class="card-body">
                ${data.visits.length ? `
                    <div class="timeline">
                        ${data.visits.slice(0, 30).map((visit) => `
                            <div class="timeline-item">
                                <div class="timeline-title">${esc(formatDate(visit.checked_in_at))}</div>
                                <div class="timeline-date">${esc(formatDateTime(visit.checked_in_at))} · ${esc(t(`admin.customers.method_${visit.method}`))}</div>
                            </div>`).join('')}
                    </div>
                    ${data.visits.length > 30 ? `<p class="card-hint mt-4">${esc(t('admin.customers.more_visits', { count: data.visits.length - 30 }))}</p>` : ''}
                ` : `<p class="text-sm text-muted">${esc(t('admin.customers.no_visits'))}</p>`}
            </div>
        </div>
    `));

    return wrap;
}

/* ============================================================
   Save segment
   ============================================================ */

function openSaveSegmentDialog(ctx) {
    if (ctx.isAllSalons) {
        toastError(t('admin.customers.segment_needs_salon'));
        return;
    }

    const active = Object.keys(filter).filter((key) => key !== 'search');
    if (!active.length) {
        toastError(t('admin.customers.segment_needs_filter'));
        return;
    }

    const form = el(`
        <form novalidate>
            <div class="form-grid">
                <div class="alert alert-info">
                    <div>
                        <div class="alert-title">${esc(t('admin.customers.segment_current'))}</div>
                        <div class="text-sm">${esc(describeFilter())}</div>
                        <div class="text-sm mt-2"><strong>${esc(t('admin.customers.matching', { count: formatNumber(total) }))}</strong></div>
                    </div>
                </div>
                <div class="field">
                    <label class="field-label" for="name">${esc(t('admin.customers.segment_name'))}<span class="req">*</span></label>
                    <input class="input" id="name" name="name" required maxlength="120"
                           placeholder="${esc(t('admin.customers.segment_name_placeholder'))}">
                </div>
                <div class="field">
                    <label class="field-label" for="description">${esc(t('admin.customers.segment_description'))}</label>
                    <input class="input" id="description" name="description" maxlength="255">
                </div>
            </div>
        </form>
    `);

    modal({
        title: t('admin.customers.save_segment'),
        subtitle: t('admin.customers.save_segment_hint'),
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
                        // Search text is a transient lookup, not part of a segment.
                        const { search, ...persisted } = filter;
                        await apiPost(`segments.php?salon_id=${encodeURIComponent(ctx.salonId)}`, {
                            name: values.name,
                            description: values.description || '',
                            filter: persisted,
                        }, { salonScope: false });

                        toastSuccess(t('admin.customers.segment_saved'));
                        close();
                        ctx.reload();
                    } catch (error) {
                        reset();
                        if (error?.status === 409) {
                            showFormErrors(form, { name: error.message });
                        } else {
                            toastApiError(error);
                        }
                    }
                },
            },
        ],
    });
}

/** Human-readable summary of the active filter, for the save dialog. */
function describeFilter() {
    const parts = [];
    if (filter.members_only) parts.push(t('admin.customers.preset_members'));
    if (filter.gender) parts.push(t(`admin.customers.gender_${filter.gender}`));
    if (filter.age_min || filter.age_max) {
        parts.push(t('admin.customers.age_range') + ': ' + (filter.age_min || '…') + '–' + (filter.age_max || '…'));
    }
    if (filter.zip) parts.push(`${t('admin.customers.zip_area')} ${filter.zip}`);
    if (filter.visited_within_weeks) parts.push(t('admin.customers.visited_weeks', { weeks: filter.visited_within_weeks }));
    if (filter.not_visited_within_weeks) parts.push(t('admin.customers.not_visited_weeks', { weeks: filter.not_visited_within_weeks }));
    if (filter.min_visits) parts.push(t('admin.customers.min_visits', { count: filter.min_visits }));
    if (filter.registered_from || filter.registered_to) {
        parts.push(`${t('admin.customers.registered')}: ${filter.registered_from || '…'} – ${filter.registered_to || '…'}`);
    }
    return parts.length ? parts.join(' · ') : t('admin.customers.preset_all');
}

/* ============================================================
   Export
   ============================================================ */

function openExportDialog(ctx) {
    const body = el(`
        <div class="stack">
            <p class="text-sm text-muted">${esc(t('admin.customers.export_intro', { count: formatNumber(total) }))}</p>
            <label class="check">
                <input type="radio" name="scope" value="marketing" checked>
                <span class="check-text">
                    <span class="check-title">${esc(t('admin.customers.export_marketing'))}</span>
                    <span class="check-desc">${esc(t('admin.customers.export_marketing_desc'))}</span>
                </span>
            </label>
            <label class="check">
                <input type="radio" name="scope" value="internal">
                <span class="check-text">
                    <span class="check-title">${esc(t('admin.customers.export_internal'))}</span>
                    <span class="check-desc">${esc(t('admin.customers.export_internal_desc'))}</span>
                </span>
            </label>
            <div class="alert alert-warning">
                <span aria-hidden="true">⚠</span>
                <div class="text-sm">${esc(t('admin.customers.export_warning'))}</div>
            </div>
        </div>
    `);

    modal({
        title: t('admin.customers.export'),
        body,
        actions: [
            { label: t('admin.common.cancel'), variant: 'secondary', onClick: (close) => close() },
            {
                label: t('admin.customers.export_download'),
                variant: 'primary',
                onClick: async (close, button) => {
                    const scope = body.querySelector('input[name=scope]:checked').value;
                    const reset = buttonBusy(button, t('admin.customers.exporting'));
                    try {
                        const query = [scopeQuery(ctx), 'action=export', `scope=${scope}`, filterQuery()]
                            .filter(Boolean).join('&');
                        await apiDownload(
                            `insights.php?${query}`,
                            `kunden-${scope}-${new Date().toISOString().slice(0, 10)}.csv`,
                            { salonScope: false }
                        );
                        toastSuccess(t('admin.customers.export_done'));
                        close();
                    } catch (error) {
                        reset();
                        toastApiError(error);
                    }
                },
            },
        ],
    });
}
