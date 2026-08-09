#!/usr/bin/env node
/**
 * Translation guard for the admin dashboard.
 * ------------------------------------------------------------
 * There is no build step and no test runner in this project, so nothing would
 * otherwise catch a translation key that is used but never defined -- it would
 * simply render the raw key ("admin.users.title") to a salon owner. This script
 * is the check.
 *
 * It asserts three things:
 *   1. lang/de.json and lang/en.json define exactly the same keys.
 *   2. Every key used by the dashboard resolves in BOTH files.
 *   3. No translation value is left empty.
 *
 * Keys are collected from:
 *   - t('...') and i18n.t('...') calls in js/admin/
 *   - data-i18n / data-i18n-placeholder / data-i18n-html attributes in the HTML
 *   - string literals passed as labelKey / searchPlaceholderKey / titleKey
 *   - the dynamic groups listed in DYNAMIC_GROUPS below, which are built at
 *     runtime (`admin.roles.${role}`) and so cannot be found by scanning
 *
 * Usage: node scripts/check-translations.mjs
 * Exits non-zero when something is missing, so it can gate a commit.
 */

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');

/** Files scanned for translation keys. */
const HTML_FILES = ['admin-dashboard.html', 'login.html', 'set-password.html'];
const JS_DIRS = ['js/admin'];

/**
 * Keys assembled at runtime from a variable, e.g. t(`admin.roles.${role}`).
 * A scanner cannot see these, so the full set is declared here and checked.
 */
const DYNAMIC_GROUPS = {
    'admin.roles': [
        'admin', 'admin_delegate', 'customer_admin',
        'customer_admin_delegate', 'customer_facing_tablet_user',
    ],
    'admin.status': [
        'active', 'inactive', 'trial', 'suspended', 'draft', 'scheduled',
        'sending', 'sent', 'cancelled', 'failed', 'open', 'paid', 'overdue',
        'pending', 'accepted', 'expired', 'revoked',
    ],
    'admin.permissions': [
        'manage_campaigns', 'manage_campaigns_desc',
        'view_insights', 'view_insights_desc',
        'manage_products', 'manage_products_desc',
        'manage_magazine', 'manage_magazine_desc',
        'manage_users', 'manage_users_desc',
        'change_settings', 'change_settings_desc',
    ],
    'admin.settings.social_types': [
        'instagram', 'facebook', 'tiktok', 'google_reviews', 'yelp',
        'twitter', 'linkedin', 'youtube', 'pinterest', 'custom',
    ],
    // Monday-first, matching coiffure_salon_hours.weekday.
    'admin.weekdays': ['0', '1', '2', '3', '4', '5', '6'],
    // Audit values are free-form strings written by many endpoints. The view
    // falls back to the raw value, so this list is what SHOULD be translated,
    // and the check catches a label that was added on one side only.
    'admin.audit.entities': [
        'customer', 'user', 'salon', 'visit', 'login', 'campaign',
        'billing', 'settings', 'employee',
    ],
    'admin.audit.actions': [
        'create', 'update', 'delete', 'login', 'logout', 'export', 'data_export',
        'campaign_sent', 'user_invited', 'permission_granted', 'impersonate',
        'invoice_created', 'subscription_changed', 'whitelabel_changed',
        'whitelabel_test', 'update_language', 'settings_changed',
    ],
    'admin.audit.consents': [
        'consent_data_processing', 'consent_email_marketing', 'consent_marketing',
        'consent_sms_whatsapp', 'consent_postal', 'consent_cancellation_policy',
    ],
    'admin.audit.sources': ['tablet', 'dashboard', 'import'],
    // NOTIFICATION_TYPES in api/notifications.php.
    'admin.notifications.events': [
        'registration', 'campaign_sent', 'birthday', 'user_invited',
        'subscription', 'system',
    ],
    // Rendered from coiffure_notifications.title_key, so never a literal here.
    'admin.notify': [
        'registration', 'campaign_sent', 'user_invited',
        'subscription_changed', 'birthday',
    ],
    'admin.whitelabel': ['primary_color', 'secondary_color', 'secure_tls', 'secure_ssl', 'secure_none'],
    // Block reasons from aiUsageSnapshot() in api/ai_usage_helpers.php, used
    // as `admin.ai_usage.state_${reason}` and `..._blocked_help_${reason}`.
    'admin.ai_usage': [
        'state_trial_limit_reached', 'state_monthly_limit_reached',
        'state_overage_cap_reached', 'state_feature_disabled', 'state_salon_suspended',
        'blocked_help_trial_limit_reached', 'blocked_help_monthly_limit_reached',
        'blocked_help_overage_cap_reached', 'blocked_help_feature_disabled',
        'blocked_help_salon_suspended',
        'type_hairstyle', 'type_eyebrows',
    ],
};

/* ============================================================
   Helpers
   ============================================================ */

function walk(dir) {
    const out = [];
    for (const entry of readdirSync(dir)) {
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) out.push(...walk(full));
        else if (full.endsWith('.js')) out.push(full);
    }
    return out;
}

/** Flatten {a: {b: 'x'}} into {'a.b': 'x'}. */
function flatten(object, prefix = '') {
    const out = {};
    for (const [key, value] of Object.entries(object)) {
        const path = `${prefix}${key}`;
        if (value && typeof value === 'object' && !Array.isArray(value)) {
            Object.assign(out, flatten(value, `${path}.`));
        } else {
            out[path] = value;
        }
    }
    return out;
}

/** Collect every statically discoverable admin.* key, with where it came from. */
function collectUsedKeys() {
    const used = new Map();   // key -> Set(files)
    const add = (key, file) => {
        if (!key.startsWith('admin.')) return;
        if (!used.has(key)) used.set(key, new Set());
        used.get(key).add(file);
    };

    const patterns = [
        // t('admin.x'), i18n.t("admin.x")
        /\bt\(\s*['"]([a-zA-Z0-9_.]+)['"]/g,
        // labelKey: 'admin.x', searchPlaceholderKey: "admin.x", titleKey: ...
        /(?:labelKey|searchPlaceholderKey|titleKey)\s*:\s*['"]([a-zA-Z0-9_.]+)['"]/g,
        // bare 'admin.x' literals (FIELDS tables, statusBadge second argument)
        /['"](admin\.[a-zA-Z0-9_.]+)['"]/g,
    ];

    for (const dir of JS_DIRS) {
        for (const file of walk(join(ROOT, dir))) {
            const source = readFileSync(file, 'utf8');
            const label = relative(ROOT, file);
            for (const pattern of patterns) {
                for (const match of source.matchAll(pattern)) add(match[1], label);
            }
        }
    }

    for (const name of HTML_FILES) {
        const source = readFileSync(join(ROOT, name), 'utf8');
        const attribute = /data-i18n(?:-placeholder|-html|-title)?="([a-zA-Z0-9_.]+)"/g;
        for (const match of source.matchAll(attribute)) add(match[1], name);
        // login.html calls a local t() with a literal fallback
        for (const match of source.matchAll(/\bt\(\s*['"]([a-zA-Z0-9_.]+)['"]/g)) add(match[1], name);
    }

    for (const [base, leaves] of Object.entries(DYNAMIC_GROUPS)) {
        for (const leaf of leaves) add(`${base}.${leaf}`, 'dynamic');
    }

    return used;
}

/* ============================================================
   Checks
   ============================================================ */

const de = flatten(JSON.parse(readFileSync(join(ROOT, 'lang/de.json'), 'utf8')));
const en = flatten(JSON.parse(readFileSync(join(ROOT, 'lang/en.json'), 'utf8')));
const used = collectUsedKeys();

const problems = [];

// 1. de/en parity
const onlyDe = Object.keys(de).filter((k) => !(k in en)).sort();
const onlyEn = Object.keys(en).filter((k) => !(k in de)).sort();
if (onlyDe.length) problems.push(`Keys in de.json but not en.json:\n  ${onlyDe.join('\n  ')}`);
if (onlyEn.length) problems.push(`Keys in en.json but not de.json:\n  ${onlyEn.join('\n  ')}`);

// 2. every used key resolves in both languages
const missing = [];
for (const [key, files] of [...used].sort()) {
    const where = [...files].join(', ');
    if (!(key in de)) missing.push(`  ${key}  (de.json)  used in: ${where}`);
    else if (typeof de[key] !== 'string') missing.push(`  ${key}  (de.json is not a string)  used in: ${where}`);
    if (!(key in en)) missing.push(`  ${key}  (en.json)  used in: ${where}`);
    else if (typeof en[key] !== 'string') missing.push(`  ${key}  (en.json is not a string)  used in: ${where}`);
}
if (missing.length) problems.push(`Missing translations:\n${missing.join('\n')}`);

// 3. no empty values
const empty = Object.entries({ ...flatEntries('de', de), ...flatEntries('en', en) })
    .filter(([, value]) => typeof value === 'string' && value.trim() === '')
    .map(([label]) => `  ${label}`);
if (empty.length) problems.push(`Empty translation values:\n${empty.join('\n')}`);

function flatEntries(lang, table) {
    const out = {};
    for (const [key, value] of Object.entries(table)) out[`${lang}:${key}`] = value;
    return out;
}

/* ============================================================
   Report
   ============================================================ */

if (problems.length) {
    console.error('✗ Translation check failed\n');
    console.error(problems.join('\n\n'));
    process.exit(1);
}

const adminKeys = Object.keys(de).filter((k) => k.startsWith('admin.')).length;
console.log('✓ Translation check passed');
console.log(`  ${Object.keys(de).length} keys, identical in de and en`);
console.log(`  ${adminKeys} of them in the admin.* dashboard namespace`);
console.log(`  ${used.size} keys referenced by the dashboard, all resolved`);
