/**
 * AI stylist consumption — shared presentation
 * ------------------------------------------------------------
 * The Übersicht card and the Einstellungen tab must never disagree about how
 * many images a salon has used or what that costs, so both read the same
 * snapshot from api/ai-usage.php and render it through this module.
 *
 * The snapshot's shape is documented in api/ai_usage_helpers.php. The three
 * situations a salon owner cares about, in the wording of the spec:
 *
 *   200 / 500 images   no additional cost
 *   500 / 500 images   no additional cost — limit reached, feature disabled
 *   600 / 500 images   2.15 € additional cost
 */

import { t, esc, el, formatNumber, formatMoney } from './ui.js';

/** Fraction of the allowance consumed, 0…1. Unlimited reads as empty. */
export function usageRatio(usage) {
    if (!usage || usage.unlimited || !usage.limit) return 0;
    return Math.min(1, usage.used / usage.limit);
}

/**
 * How the salon stands, as one of four states. Everything visual (badge tone,
 * meter colour, wording) is derived from this, so the three views stay in sync.
 *
 *   blocked   the feature is off right now
 *   overage   past the limit, and the owner agreed to pay for the extras
 *   warning   80% or more of the allowance used
 *   ok        comfortably inside the allowance
 */
export function usageState(usage) {
    if (!usage) return 'ok';
    if (!usage.allowed) return 'blocked';
    if (usage.over_limit && usage.overage_allowed) return 'overage';
    if (!usage.unlimited && usageRatio(usage) >= 0.8) return 'warning';
    return 'ok';
}

const STATE_TONE = { blocked: 'danger', overage: 'warning', warning: 'warning', ok: 'success' };

/** "200 / 500" — or just the count when the salon has no limit. */
export function usageCountLabel(usage) {
    if (!usage) return '—';
    if (usage.unlimited) return formatNumber(usage.used);
    return `${formatNumber(usage.used)} / ${formatNumber(usage.limit)}`;
}

/** "no additional cost" or "2.15 € additional cost (15 images)". */
export function usageCostLabel(usage) {
    if (!usage || !usage.overage_count) return t('admin.ai_usage.no_extra_cost');
    return t('admin.ai_usage.extra_cost', {
        amount: formatMoney(usage.overage_cost, usage.currency || 'EUR'),
        count: formatNumber(usage.overage_count),
    });
}

/** The short status sentence shown next to the badge. */
export function usageStateLabel(usage) {
    switch (usageState(usage)) {
        case 'blocked':
            return t(`admin.ai_usage.state_${usage.block_reason || 'feature_disabled'}`);
        case 'overage':
            return t('admin.ai_usage.state_overage');
        case 'warning':
            return t('admin.ai_usage.state_warning');
        default:
            return usage && usage.unlimited
                ? t('admin.ai_usage.state_unlimited')
                : t('admin.ai_usage.state_ok');
    }
}

export function usageBadge(usage) {
    const state = usageState(usage);
    return el(
        `<span class="badge badge-${STATE_TONE[state]}">${esc(usageStateLabel(usage))}</span>`
    );
}

/** The allowance bar. Renders nothing meaningful for an unlimited salon. */
export function usageMeter(usage) {
    const state = usageState(usage);
    const percent = Math.round(usageRatio(usage) * 100);
    const tone = state === 'ok' ? '' : ` meter-${STATE_TONE[state]}`;

    return el(`
        <div class="meter" role="img"
             aria-label="${esc(t('admin.ai_usage.meter_label', { percent }))}">
            <div class="meter-fill${tone}" style="width:${percent}%"></div>
        </div>
    `);
}

/**
 * The full consumption block: count, badge, meter and cost line.
 *
 * @param {object} usage    snapshot from api/ai-usage.php
 * @param {object} [options]
 * @param {boolean} [options.compact] leaves out the period and mode footnote
 */
export function usageBlock(usage, options = {}) {
    const wrap = el('<div class="stack-sm"></div>');

    const head = el('<div class="row row-between" style="align-items:baseline;gap:var(--sp-3)"></div>');
    head.appendChild(el(
        `<span style="font-size:1.5rem;font-weight:680;line-height:1.1">${esc(usageCountLabel(usage))}</span>`
    ));
    head.appendChild(usageBadge(usage));
    wrap.appendChild(head);

    if (usage && !usage.unlimited) wrap.appendChild(usageMeter(usage));

    wrap.appendChild(el(
        `<div class="text-sm text-muted">${esc(usageCostLabel(usage))}</div>`
    ));

    if (!options.compact && usage) {
        // Which allowance is being spent — a trial's is a lifetime one, a
        // subscription's resets every month, and that difference matters.
        const scope = usage.mode === 'trial'
            ? t('admin.ai_usage.scope_trial')
            : t('admin.ai_usage.scope_month', { period: usage.period_label });
        wrap.appendChild(el(`<div class="text-xs text-subtle">${esc(scope)}</div>`));
    }

    return wrap;
}
