/**
 * Übersicht (dashboard home)
 * ------------------------------------------------------------
 * The screen a salon owner opens every morning: five KPI cards, the check-in
 * curve for the last eight weeks, member growth against customers going
 * inactive, and the next birthdays with a one-click birthday mail.
 *
 * Everything comes from a single api/dashboard-stats.php call, including both
 * the daily and weekly buckets, so the chart toggle is instant.
 */

import { apiGet, apiPost } from '../api.js';
import {
    t, esc, el, pageHeader, formatNumber, formatDate, toastApiError, toastSuccess,
    sparkline, cssVar, mergeChartOptions, emptyState, skeletonCards, initials,
    confirmDialog, buttonBusy,
} from '../ui.js';

/** Chart.js instances, destroyed before each re-render so nothing leaks. */
let charts = [];

function destroyCharts() {
    charts.forEach((chart) => {
        try {
            chart.destroy();
        } catch {
            /* already gone */
        }
    });
    charts = [];
}

export async function render(container, ctx) {
    destroyCharts();

    container.appendChild(pageHeader({
        title: ctx.isAllSalons
            ? t('admin.home.title_all')
            : t('admin.home.title', { salon: ctx.salon?.salon_name || '' }),
        subtitle: t('admin.home.subtitle'),
    }));

    const host = el('<div class="stack"></div>');
    container.appendChild(host);
    host.appendChild(skeletonCards(5));

    let data;
    try {
        const query = ctx.isAllSalons
            ? 'dashboard-stats.php?salon_id=all'
            : `dashboard-stats.php?salon_id=${encodeURIComponent(ctx.salonId)}`;
        data = await apiGet(query, { salonScope: false });
    } catch (error) {
        host.innerHTML = '';
        toastApiError(error);
        host.appendChild(emptyState({
            art: 'chart',
            title: t('admin.home.error_title'),
            text: error?.message || t('admin.errors.generic'),
        }));
        return;
    }

    host.innerHTML = '';
    host.appendChild(kpiRow(data, ctx));
    host.appendChild(chartsRow(data, ctx));

    if (ctx.isAllSalons && data.salons?.length) {
        host.appendChild(salonBreakdown(data, ctx));
    }

    host.appendChild(birthdayCard(data, ctx));

    // Chart.js is loaded with `defer`, so on a cold load it may not be ready
    // when the first render runs.
    whenChartReady(() => {
        drawVisitChart(data);
        drawGrowthChart(data);
    });

    // Sparklines are hand-drawn on canvas and need a laid-out element.
    requestAnimationFrame(() => {
        const canvas = host.querySelector('[data-spark="checkins"]');
        if (canvas) sparkline(canvas, data.kpis.checkins_today.spark || []);
    });
}

/**
 * Chart.js is loaded from a CDN with `defer`, so it may not be ready when the
 * first render runs -- and on a locked-down network it may never arrive. Poll
 * briefly, then replace the empty canvases with an explanation rather than
 * leaving two blank rectangles on the page.
 */
function whenChartReady(callback, attempt = 0) {
    if (typeof Chart !== 'undefined') {
        callback();
        return;
    }
    if (attempt > 40) {
        console.warn('Chart.js did not load; showing the chart fallback.');
        document.querySelectorAll('.chart-box').forEach((box) => {
            box.replaceChildren(el(`
                <div class="empty" style="padding:var(--sp-8) var(--sp-4)">
                    <div class="empty-text">${esc(t('admin.home.chart_unavailable'))}</div>
                </div>
            `));
        });
        return;
    }
    setTimeout(() => whenChartReady(callback, attempt + 1), 50);
}

/* ============================================================
   KPI cards
   ============================================================ */

function kpiRow(data, ctx) {
    const k = data.kpis;
    const row = el('<div class="grid grid-4"></div>');

    row.appendChild(kpiCard({
        label: t('admin.home.kpi_checkins_today'),
        value: formatNumber(k.checkins_today.value),
        icon: '✅',
        trend: k.checkins_today.trend,
        trendNote: t('admin.home.vs_yesterday'),
        spark: 'checkins',
    }));

    row.appendChild(kpiCard({
        label: t('admin.home.kpi_new_registrations'),
        value: formatNumber(k.new_registrations_week.value),
        icon: '📝',
        trend: k.new_registrations_week.trend,
        trendNote: t('admin.home.vs_last_week'),
    }));

    row.appendChild(kpiCard({
        label: t('admin.home.kpi_active_members'),
        value: formatNumber(k.active_members.value),
        icon: '⭐',
        note: t('admin.home.of_total_members', {
            total: formatNumber(k.active_members.total),
            weeks: data.scope.inactive_weeks,
        }),
    }));

    row.appendChild(kpiCard({
        label: t('admin.home.kpi_birthdays'),
        value: formatNumber(k.birthdays_week.value),
        icon: '🎂',
        note: t('admin.home.next_7_days'),
    }));

    row.appendChild(kpiCard({
        label: t('admin.home.kpi_campaigns'),
        value: formatNumber(k.campaigns_month.value),
        icon: '✉️',
        trend: k.campaigns_month.trend,
        trendNote: t('admin.home.vs_last_month'),
    }));

    row.appendChild(kpiCard({
        label: t('admin.home.kpi_total_customers'),
        value: formatNumber(k.total_customers.value),
        icon: '👥',
        note: ctx.isAllSalons ? t('admin.home.across_salons', { count: data.salons.length }) : '',
    }));

    return row;
}

function kpiCard({ label, value, icon, trend, trendNote, note, spark }) {
    const card = el(`
        <div class="kpi">
            <div class="kpi-head">
                <span class="kpi-label">${esc(label)}</span>
                <span class="kpi-icon" aria-hidden="true">${esc(icon)}</span>
            </div>
            <div class="kpi-value">${esc(value)}</div>
            <div class="kpi-foot"></div>
        </div>
    `);

    const foot = card.querySelector('.kpi-foot');

    // A null trend means there was no baseline to compare against (the previous
    // period was zero), which is different from "no change".
    if (trend !== null && trend !== undefined) {
        const direction = trend > 0 ? 'up' : (trend < 0 ? 'down' : 'flat');
        const arrow = direction === 'up' ? '▲' : (direction === 'down' ? '▼' : '—');
        foot.appendChild(el(
            `<span class="kpi-trend ${direction}">${arrow} ${esc(Math.abs(trend))}%</span>`
        ));
        if (trendNote) foot.appendChild(el(`<span class="kpi-trend-note">${esc(trendNote)}</span>`));
    } else if (note) {
        foot.appendChild(el(`<span class="kpi-trend-note">${esc(note)}</span>`));
    } else if (trendNote) {
        foot.appendChild(el(`<span class="kpi-trend-note">${esc(t('admin.home.no_comparison'))}</span>`));
    }

    if (spark) {
        card.appendChild(el(`<canvas class="kpi-spark" data-spark="${esc(spark)}" aria-hidden="true"></canvas>`));
    }

    return card;
}

/* ============================================================
   Charts
   ============================================================ */

function chartsRow(data) {
    const row = el('<div class="grid grid-2"></div>');

    const visits = el(`
        <div class="card">
            <div class="card-header">
                <h2>${esc(t('admin.home.visits_title'))}</h2>
            </div>
            <div class="card-body">
                <div class="chart-box"><canvas id="chart-visits"></canvas></div>
            </div>
        </div>
    `);

    // Daily / weekly toggle -- both series are already in hand.
    const toggle = el(`
        <div class="segmented" role="group">
            <button type="button" class="active" data-mode="daily">${esc(t('admin.home.daily'))}</button>
            <button type="button" data-mode="weekly">${esc(t('admin.home.weekly'))}</button>
        </div>
    `);
    toggle.addEventListener('click', (event) => {
        const button = event.target.closest('[data-mode]');
        if (!button) return;
        toggle.querySelectorAll('button').forEach((b) => b.classList.toggle('active', b === button));
        drawVisitChart(data, button.dataset.mode);
    });
    visits.querySelector('.card-header').appendChild(toggle);

    row.appendChild(visits);

    row.appendChild(el(`
        <div class="card">
            <div class="card-header">
                <h2>${esc(t('admin.home.growth_title'))}</h2>
            </div>
            <div class="card-body">
                <p class="card-hint" style="margin-bottom:var(--sp-4)">
                    ${esc(t('admin.home.growth_hint', { weeks: data.scope.inactive_weeks }))}
                </p>
                <div class="chart-box" style="height:260px"><canvas id="chart-growth"></canvas></div>
            </div>
        </div>
    `));

    return row;
}

function drawVisitChart(data, mode = 'daily') {
    const canvas = document.getElementById('chart-visits');
    if (!canvas || typeof Chart === 'undefined') return;

    const existing = Chart.getChart(canvas);
    if (existing) {
        existing.destroy();
        charts = charts.filter((c) => c !== existing);
    }

    const series = mode === 'weekly' ? data.visits.weekly : data.visits.daily;
    const accent = cssVar('--brand-500', '#3b82f6');

    const chart = new Chart(canvas, {
        type: 'line',
        data: {
            labels: series.map((p) => formatDate(p.date, { day: '2-digit', month: '2-digit' })),
            datasets: [{
                label: mode === 'weekly' ? t('admin.home.checkins_per_week') : t('admin.home.checkins_per_day'),
                data: series.map((p) => p.count),
                borderColor: accent,
                backgroundColor: `${accent}22`,
                borderWidth: 2,
                fill: true,
                tension: 0.32,
                pointRadius: mode === 'weekly' ? 3 : 0,
                pointHoverRadius: 5,
                pointBackgroundColor: accent,
            }],
        },
        options: mergeChartOptions({
            plugins: { legend: { display: false } },
        }),
    });

    charts.push(chart);
}

function drawGrowthChart(data) {
    const canvas = document.getElementById('chart-growth');
    if (!canvas || typeof Chart === 'undefined') return;

    const existing = Chart.getChart(canvas);
    if (existing) {
        existing.destroy();
        charts = charts.filter((c) => c !== existing);
    }

    const chart = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: data.growth.map((p) => formatDate(p.date, { day: '2-digit', month: '2-digit' })),
            datasets: [
                {
                    label: t('admin.home.new_members'),
                    data: data.growth.map((p) => p.new_members),
                    backgroundColor: cssVar('--success-500', '#10b981'),
                    borderRadius: 4,
                    maxBarThickness: 22,
                },
                {
                    label: t('admin.home.went_inactive'),
                    data: data.growth.map((p) => p.went_inactive),
                    backgroundColor: cssVar('--slate-300', '#cbd5e1'),
                    borderRadius: 4,
                    maxBarThickness: 22,
                },
            ],
        },
        options: mergeChartOptions(),
    });

    charts.push(chart);
}

/* ============================================================
   Multi-salon breakdown ("Alle Salons")
   ============================================================ */

function salonBreakdown(data, ctx) {
    const card = el(`
        <div class="card">
            <div class="card-header"><h2>${esc(t('admin.home.by_salon'))}</h2></div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>${esc(t('admin.salons.name'))}</th>
                            <th class="cell-num">${esc(t('admin.salons.customers'))}</th>
                            <th class="cell-num">${esc(t('admin.home.checkins_period', { weeks: data.scope.weeks }))}</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    `);

    const body = card.querySelector('tbody');
    data.salons.forEach((salon) => {
        const row = el(`
            <tr class="clickable">
                <td class="cell-strong">${esc(salon.salon_name)}</td>
                <td class="cell-num">${esc(formatNumber(salon.customers))}</td>
                <td class="cell-num">${esc(formatNumber(salon.visits))}</td>
            </tr>
        `);
        // Clicking a salon row switches the whole dashboard to that salon.
        row.addEventListener('click', () => {
            localStorage.setItem('admin_selected_salon', String(salon.salon_id));
            window.location.reload();
        });
        body.appendChild(row);
    });

    return card;
}

/* ============================================================
   Upcoming birthdays
   ============================================================ */

function birthdayCard(data, ctx) {
    const card = el(`
        <div class="card">
            <div class="card-header"><h2>${esc(t('admin.home.birthdays_title'))}</h2></div>
        </div>
    `);

    if (!data.birthdays.length) {
        card.appendChild(el('<div class="card-body"></div>')).appendChild(emptyState({
            art: 'people',
            title: t('admin.home.birthdays_empty'),
            text: t('admin.home.birthdays_empty_text'),
        }));
        return card;
    }

    const list = el('<div class="table-wrap"><table class="table"><tbody></tbody></table></div>');
    const body = list.querySelector('tbody');

    data.birthdays.forEach((person) => {
        const when = person.days_until === 0
            ? t('admin.home.today')
            : (person.days_until === 1
                ? t('admin.home.tomorrow')
                : t('admin.home.in_days', { count: person.days_until }));

        const row = el(`
            <tr>
                <td style="width:44px">
                    <span class="avatar" style="background:var(--brand-100);color:var(--brand-700)">${esc(initials(person.name))}</span>
                </td>
                <td>
                    <span class="cell-strong">${esc(person.name)}</span>
                    <div class="text-xs text-muted">${esc(person.email || '')}</div>
                </td>
                <td class="cell-muted">
                    ${esc(String(person.birth_day).padStart(2, '0'))}.${esc(String(person.birth_month).padStart(2, '0'))}
                    <div class="text-xs">${esc(when)}</div>
                </td>
                <td class="cell-actions"></td>
            </tr>
        `);

        const actions = row.querySelector('.cell-actions');

        if (person.already_sent) {
            actions.appendChild(el(`<span class="badge badge-success">${esc(t('admin.home.birthday_sent'))}</span>`));
        } else if (!person.can_email) {
            // No marketing consent: say so rather than offering a button that
            // would be refused server-side.
            actions.appendChild(el(
                `<span class="badge badge-neutral" title="${esc(t('admin.home.no_consent_hint'))}">${esc(t('admin.home.no_consent'))}</span>`
            ));
        } else {
            const button = el(
                `<button type="button" class="btn btn-secondary btn-sm">🎂 ${esc(t('admin.home.send_birthday'))}</button>`
            );
            button.addEventListener('click', () => sendBirthdayMail(person, button, ctx));
            actions.appendChild(button);
        }

        body.appendChild(row);
    });

    card.appendChild(list);

    const footer = el('<div class="card-footer"></div>');
    const link = el(`<button type="button" class="btn btn-ghost btn-sm">${esc(t('admin.home.all_customers'))} →</button>`);
    link.addEventListener('click', () => ctx.navigate('#/kunden'));
    footer.appendChild(link);
    card.appendChild(footer);

    return card;
}

async function sendBirthdayMail(person, button, ctx) {
    const confirmed = await confirmDialog({
        title: t('admin.home.send_birthday'),
        message: t('admin.home.send_birthday_confirm', { name: person.name, email: person.email }),
        confirmLabel: t('admin.home.send_now'),
        variant: 'primary',
    });
    if (!confirmed) return;

    const reset = buttonBusy(button, t('admin.home.sending'));
    try {
        await apiPost('campaigns.php?action=send_birthday', {
            customer_id: person.customer_id,
            salon_id: person.salon_id,
        }, { salonScope: false });

        toastSuccess(t('admin.home.birthday_sent_toast', { name: person.name }));
        ctx.reload();
    } catch (error) {
        reset();
        toastApiError(error);
    }
}
