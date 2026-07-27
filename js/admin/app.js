/**
 * Admin dashboard shell
 * ------------------------------------------------------------
 * Boots the SPA: validates the session, loads the user's rights from
 * api/me.php, builds the sidebar from those rights, wires the top bar
 * (salon switcher, notifications, language, profile menu) and runs a
 * hash router that lazy-loads one module per view.
 *
 * Views are ES modules exporting `render(container, ctx)`. `ctx` carries
 * everything a view needs, so no view reaches into this file's state.
 */

import { apiGet, apiPost, getToken, redirectToLogin, setSalonScope, ApiError } from './api.js';
import { Permissions, buildNav, defaultRoute, findNavItem } from './permissions.js';
import {
    t, esc, el, initials, toastApiError, toastSuccess, forbiddenState,
    emptyState, skeletonCards, formatRelative, confirmDialog,
} from './ui.js';

/* ============================================================
   Route table
   ============================================================ */

const ROUTES = {
    '#/uebersicht': () => import('./views/home.js'),
    '#/kunden': () => import('./views/customers.js'),
    '#/kampagnen': () => import('./views/campaigns.js'),
    '#/benutzer': () => import('./views/users.js'),
    '#/einstellungen': () => import('./views/settings.js'),
    '#/protokoll': () => import('./views/audit.js'),
    '#/salons': () => import('./views/salons.js'),
    '#/abrechnung': () => import('./views/billing.js'),
    '#/white-label': () => import('./views/whitelabel.js'),
    '#/plattform': () => import('./views/platform-settings.js'),
    '#/profil': () => import('./views/profile.js'),
};

/* ============================================================
   Application state
   ============================================================ */

const state = {
    me: null,
    permissions: null,
    salons: [],
    /** Selected salon id, or 'all' for the aggregated multi-salon view. */
    salonId: null,
    currentRoute: null,
    notifications: [],
    unreadCount: 0,
};

/** Everything a view module receives. */
function viewContext() {
    return {
        user: state.me.user,
        salons: state.salons,
        salonId: state.salonId,
        salon: state.salons.find((s) => String(s.salon_id) === String(state.salonId)) || null,
        permissions: state.permissions,
        isAllSalons: state.salonId === 'all',
        t,
        navigate,
        reload: () => renderRoute(true),
    };
}

/* ============================================================
   Boot
   ============================================================ */

async function boot() {
    if (!getToken()) {
        redirectToLogin();
        return;
    }

    // Paint the language switcher and menus before data arrives so the shell
    // does not visibly reflow once /me responds.
    let me;
    try {
        me = await apiGet('me.php', { salonScope: false });
    } catch (error) {
        if (error instanceof ApiError && error.isForbidden) {
            // A tablet user reached the dashboard URL directly.
            document.getElementById('app').innerHTML = '';
            document.body.appendChild(el(`<div class="main"></div>`)).appendChild(
                emptyState({
                    art: 'lock',
                    title: t('admin.errors.no_dashboard_title'),
                    text: t('admin.errors.no_dashboard_text'),
                    action: { label: t('admin.common.logout'), onClick: logout },
                })
            );
            return;
        }
        // Anything else (network down, server error) leaves the user stuck on a
        // blank shell, so say what happened and offer a retry.
        showBootError(error);
        return;
    }

    state.me = me;
    state.permissions = new Permissions(me);
    state.salons = me.salons || [];

    await initLanguage(me);
    restoreSalonSelection();

    renderShell();
    renderRoute();
    refreshNotifications();

    // Keep the badge current without being noisy about it.
    setInterval(refreshNotifications, 120000);
}

function showBootError(error) {
    const main = document.querySelector('.main') || document.body;
    main.innerHTML = '';
    main.appendChild(
        emptyState({
            art: 'search',
            title: t('admin.errors.boot_title'),
            text: error?.message || t('admin.errors.generic'),
            action: { label: t('admin.common.retry'), onClick: () => window.location.reload() },
        })
    );
}

/**
 * Resolve the interface language.
 *
 * Order: the user's saved profile preference, then any local override, then
 * German. This mirrors js/i18n.js's own resolution but runs explicitly because
 * the dashboard sets skipI18nAutoInit.
 */
async function initLanguage(me) {
    const preferred = me.user?.preferred_language;
    const stored = localStorage.getItem('app_language');
    const lang = (preferred === 'de' || preferred === 'en')
        ? preferred
        : ((stored === 'de' || stored === 'en') ? stored : 'de');

    await i18n.loadLanguage(lang);
    document.documentElement.lang = lang;

    // Keep the cached user_data blob in step so a reload starts in the same
    // language without waiting for /me.
    try {
        const cached = JSON.parse(localStorage.getItem('user_data') || '{}');
        cached.preferred_language = lang;
        localStorage.setItem('user_data', JSON.stringify(cached));
    } catch {
        /* a corrupt cache is not worth failing boot over */
    }
}

/* ============================================================
   Salon scope
   ============================================================ */

const SALON_KEY = 'admin_selected_salon';

function restoreSalonSelection() {
    const saved = localStorage.getItem(SALON_KEY);
    const ids = state.salons.map((s) => String(s.salon_id));

    if (saved === 'all' && state.salons.length > 1) {
        selectSalon('all', false);
        return;
    }
    if (saved && ids.includes(saved)) {
        selectSalon(saved, false);
        return;
    }
    selectSalon(state.salons.length ? String(state.salons[0].salon_id) : null, false);
}

function selectSalon(salonId, rerender = true) {
    state.salonId = salonId;
    localStorage.setItem(SALON_KEY, salonId ?? '');

    // 'all' must not be sent as a salon_id -- the aggregate endpoints take
    // ?scope=all instead, so the scope parameter is cleared here.
    setSalonScope(salonId === 'all' ? null : salonId);
    state.permissions.setSalon(salonId);

    applySalonAccent();

    if (rerender) {
        renderShell();
        renderRoute(true);
    }
}

/**
 * Recolour the dashboard accent from the selected salon's primary colour.
 *
 * Only the --brand-* ramp moves; neutrals stay slate so the UI keeps its calm
 * blue/grey/white character whatever colour a salon picks.
 */
function applySalonAccent() {
    const root = document.documentElement;
    const salon = state.salons.find((s) => String(s.salon_id) === String(state.salonId));
    const color = salon?.primary_color;

    const RESET = ['--brand-50', '--brand-100', '--brand-200', '--brand-300',
                   '--brand-400', '--brand-500', '--brand-600', '--brand-700', '--brand-contrast'];

    if (!color || !/^#[0-9a-f]{6}$/i.test(color)) {
        RESET.forEach((name) => root.style.removeProperty(name));
        return;
    }

    root.style.setProperty('--brand-500', color);
    root.style.setProperty('--brand-600', shade(color, -12));
    root.style.setProperty('--brand-700', shade(color, -26));
    root.style.setProperty('--brand-400', shade(color, 14));
    root.style.setProperty('--brand-300', shade(color, 34));
    root.style.setProperty('--brand-200', shade(color, 58));
    root.style.setProperty('--brand-100', shade(color, 78));
    root.style.setProperty('--brand-50', shade(color, 90));
    root.style.setProperty('--brand-contrast', contrastOn(color));
}

/** Lighten (percent > 0) or darken (percent < 0) a hex colour. */
function shade(hex, percent) {
    const clean = hex.replace('#', '');
    const channels = [0, 2, 4].map((i) => parseInt(clean.slice(i, i + 2), 16));
    const shifted = channels.map((value) => {
        const target = percent > 0 ? 255 : 0;
        const amount = Math.abs(percent) / 100;
        return Math.round(value + (target - value) * amount);
    });
    return `#${shifted.map((v) => v.toString(16).padStart(2, '0')).join('')}`;
}

/**
 * Pick black or white text for a coloured fill, so a salon choosing a pale
 * accent still gets readable buttons.
 */
function contrastOn(hex) {
    const clean = hex.replace('#', '');
    const [r, g, b] = [0, 2, 4].map((i) => parseInt(clean.slice(i, i + 2), 16) / 255);
    const linear = [r, g, b].map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
    const luminance = 0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2];
    return luminance > 0.45 ? '#0f172a' : '#ffffff';
}

/* ============================================================
   Shell rendering
   ============================================================ */

function renderShell() {
    renderSidebar();
    renderTopbar();
    renderImpersonationBar();
}

function renderSidebar() {
    const nav = document.getElementById('sidebar-nav');
    const brand = document.getElementById('sidebar-brand');
    if (!nav || !brand) return;

    const salonName = state.salonId === 'all'
        ? t('admin.topbar.all_salons')
        : (state.salons.find((s) => String(s.salon_id) === String(state.salonId))?.salon_name
            || t('admin.app_name'));

    brand.innerHTML = `
        <div class="sidebar-logo" aria-hidden="true">${esc(initials(salonName))}</div>
        <div class="sidebar-brand-text">
            <div class="sidebar-brand-name">${esc(salonName)}</div>
            <div class="sidebar-brand-sub">${esc(t(`admin.roles.${state.me.user.role}`))}</div>
        </div>
    `;

    const sections = buildNav(state.permissions);
    const collapsed = getCollapsedSections();
    nav.innerHTML = '';

    sections.forEach((section) => {
        const isCollapsed = collapsed.includes(section.id);
        const node = el(`
            <div class="nav-section ${isCollapsed ? 'collapsed' : ''}" data-section="${esc(section.id)}">
                <button type="button" class="nav-section-toggle" aria-expanded="${!isCollapsed}">
                    <span>${esc(t(section.labelKey))}</span>
                    <span class="nav-section-caret" aria-hidden="true">▾</span>
                </button>
                <div class="nav-section-items"></div>
            </div>
        `);

        const items = node.querySelector('.nav-section-items');
        section.items.forEach((item) => {
            const link = el(`
                <a class="nav-item" href="${esc(item.route)}" data-route="${esc(item.route)}">
                    <span class="nav-item-icon" aria-hidden="true">${esc(item.icon)}</span>
                    <span class="nav-item-label">${esc(t(item.labelKey))}</span>
                </a>
            `);
            items.appendChild(link);
        });

        node.querySelector('.nav-section-toggle').addEventListener('click', () => {
            node.classList.toggle('collapsed');
            const isNowCollapsed = node.classList.contains('collapsed');
            node.querySelector('.nav-section-toggle').setAttribute('aria-expanded', String(!isNowCollapsed));
            setSectionCollapsed(section.id, isNowCollapsed);
        });

        nav.appendChild(node);
    });

    highlightActiveNav();
}

function getCollapsedSections() {
    try {
        return JSON.parse(localStorage.getItem('admin_nav_collapsed') || '[]');
    } catch {
        return [];
    }
}

function setSectionCollapsed(sectionId, collapsed) {
    const current = new Set(getCollapsedSections());
    if (collapsed) current.add(sectionId);
    else current.delete(sectionId);
    localStorage.setItem('admin_nav_collapsed', JSON.stringify([...current]));
}

function highlightActiveNav() {
    const active = (window.location.hash || '').split('?')[0];
    document.querySelectorAll('#sidebar-nav .nav-item').forEach((link) => {
        link.classList.toggle('active', link.dataset.route === active);
    });
}

function renderTopbar() {
    renderSalonSwitcher();
    renderLanguageSwitcher();
    renderAvatarMenu();
}

function renderSalonSwitcher() {
    const host = document.getElementById('salon-switcher');
    if (!host) return;

    // A single-salon owner has nothing to switch between, so the control is
    // simply not shown (spec 3.13).
    if (state.salons.length <= 1) {
        const only = state.salons[0];
        host.innerHTML = only
            ? `<div class="salon-switcher-btn" style="cursor:default">
                   <span class="salon-switcher-avatar" aria-hidden="true">${esc(initials(only.salon_name))}</span>
                   <span class="salon-switcher-text">
                       <span class="salon-switcher-name">${esc(only.salon_name)}</span>
                   </span>
               </div>`
            : '';
        return;
    }

    const current = state.salonId === 'all'
        ? { salon_name: t('admin.topbar.all_salons') }
        : state.salons.find((s) => String(s.salon_id) === String(state.salonId)) || state.salons[0];

    host.innerHTML = `
        <button type="button" class="salon-switcher-btn" aria-haspopup="true" aria-expanded="false">
            <span class="salon-switcher-avatar" aria-hidden="true">${esc(initials(current.salon_name))}</span>
            <span class="salon-switcher-text">
                <span class="salon-switcher-label">${esc(t('admin.topbar.salon'))}</span>
                <span class="salon-switcher-name">${esc(current.salon_name)}</span>
            </span>
            <span class="salon-switcher-caret" aria-hidden="true">▾</span>
        </button>
    `;

    const button = host.querySelector('button');
    button.addEventListener('click', (event) => {
        event.stopPropagation();
        toggleMenu(host, button, () => {
            const menu = el('<div class="menu menu-left" role="menu"></div>');
            menu.appendChild(el(`<div class="menu-label">${esc(t('admin.topbar.switch_salon'))}</div>`));

            state.salons.forEach((salon) => {
                const item = el(`
                    <button type="button" class="menu-item ${String(salon.salon_id) === String(state.salonId) ? 'active' : ''}" role="menuitem">
                        <span class="salon-switcher-avatar" aria-hidden="true">${esc(initials(salon.salon_name))}</span>
                        <span>${esc(salon.salon_name)}</span>
                    </button>
                `);
                item.addEventListener('click', () => {
                    closeMenus();
                    selectSalon(String(salon.salon_id));
                });
                menu.appendChild(item);
            });

            menu.appendChild(el('<div class="menu-divider"></div>'));
            const allItem = el(`
                <button type="button" class="menu-item ${state.salonId === 'all' ? 'active' : ''}" role="menuitem">
                    <span aria-hidden="true">🏢</span><span>${esc(t('admin.topbar.all_salons'))}</span>
                </button>
            `);
            allItem.addEventListener('click', () => {
                closeMenus();
                selectSalon('all');
            });
            menu.appendChild(allItem);

            return menu;
        });
    });
}

/* ---------- Language switcher ---------- */

const FLAGS = {
    de: `<svg class="lang-flag" viewBox="0 0 5 3" aria-hidden="true"><rect width="5" height="3" fill="#000"/><rect width="5" height="2" y="1" fill="#D00"/><rect width="5" height="1" y="2" fill="#FFCE00"/></svg>`,
    en: `<svg class="lang-flag" viewBox="0 0 60 30" aria-hidden="true"><clipPath id="ukc"><path d="M0 0v30h60V0z"/></clipPath><g clip-path="url(#ukc)"><path d="M0 0v30h60V0z" fill="#012169"/><path d="M0 0l60 30m0-30L0 30" stroke="#fff" stroke-width="6"/><path d="M0 0l60 30m0-30L0 30" stroke="#C8102E" stroke-width="4"/><path d="M30 0v30M0 15h60" stroke="#fff" stroke-width="10"/><path d="M30 0v30M0 15h60" stroke="#C8102E" stroke-width="6"/></g></svg>`,
};

const LANG_NAMES = { de: 'Deutsch', en: 'English' };

function renderLanguageSwitcher() {
    const host = document.getElementById('lang-switcher');
    if (!host) return;

    const current = i18n.currentLang || 'de';
    host.innerHTML = `
        <button type="button" class="lang-current" aria-haspopup="true" aria-expanded="false"
                aria-label="${esc(t('admin.topbar.language'))}">
            ${FLAGS[current] || FLAGS.de}
            <span class="lang-caret" aria-hidden="true">▾</span>
        </button>
    `;

    const button = host.querySelector('button');
    button.addEventListener('click', (event) => {
        event.stopPropagation();
        toggleMenu(host, button, () => {
            const menu = el('<div class="menu" role="menu"></div>');
            Object.keys(LANG_NAMES).forEach((code) => {
                const item = el(`
                    <button type="button" class="menu-item ${code === current ? 'active' : ''}" role="menuitem">
                        ${FLAGS[code]}<span>${esc(LANG_NAMES[code])}</span>
                    </button>
                `);
                item.addEventListener('click', () => {
                    closeMenus();
                    changeLanguage(code);
                });
                menu.appendChild(item);
            });
            return menu;
        });
    });
}

/**
 * Switch language with no page reload.
 *
 * i18n.setLanguage persists the choice (localStorage + profile), re-applies the
 * data-i18n attributes and then fires 'languageChanged'. The actual re-render
 * hangs off that event rather than off this function, so the UI also updates
 * when the language is changed from anywhere else -- another tab's i18n call,
 * the console, or a future settings screen.
 */
async function changeLanguage(lang) {
    await i18n.setLanguage(lang);
}

window.addEventListener('languageChanged', async (event) => {
    const lang = event.detail?.language || i18n.currentLang;
    document.documentElement.lang = lang;

    // Strings produced by t() at render time are baked into the DOM, so the
    // shell and the current view have to be rebuilt -- applyTranslations() only
    // refreshes elements carrying a data-i18n attribute.
    if (!state.me) return;
    renderShell();
    await renderRoute(true);
    i18n.applyTranslations();
});

/* ---------- Avatar / profile menu ---------- */

function renderAvatarMenu() {
    const host = document.getElementById('avatar-menu');
    if (!host) return;

    const user = state.me.user;
    host.innerHTML = `
        <button type="button" class="avatar-btn" aria-haspopup="true" aria-expanded="false"
                aria-label="${esc(t('admin.topbar.account'))}">
            <span class="avatar" aria-hidden="true">${esc(initials(user.full_name || user.username))}</span>
        </button>
    `;

    const button = host.querySelector('button');
    button.addEventListener('click', (event) => {
        event.stopPropagation();
        toggleMenu(host, button, () => {
            const menu = el(`
                <div class="menu" role="menu">
                    <div class="menu-label">${esc(user.full_name || user.username)}</div>
                    <div style="padding:0 12px 8px" class="text-xs text-muted">${esc(user.email || '')}</div>
                    <div class="menu-divider"></div>
                </div>
            `);

            const profile = el(`<button type="button" class="menu-item" role="menuitem"><span aria-hidden="true">👤</span><span>${esc(t('admin.nav.profile'))}</span></button>`);
            profile.addEventListener('click', () => { closeMenus(); navigate('#/profil'); });
            menu.appendChild(profile);

            menu.appendChild(el('<div class="menu-divider"></div>'));

            const out = el(`<button type="button" class="menu-item danger" role="menuitem"><span aria-hidden="true">⏻</span><span>${esc(t('admin.common.logout'))}</span></button>`);
            out.addEventListener('click', () => { closeMenus(); logout(); });
            menu.appendChild(out);

            return menu;
        });
    });
}

async function logout() {
    try {
        await apiPost('auth-logout.php', {}, { salonScope: false });
    } catch {
        // A failed logout call should still clear the client session.
    }
    redirectToLogin();
}

/* ---------- Impersonation banner ---------- */

function renderImpersonationBar() {
    const bar = document.getElementById('impersonation-bar');
    if (!bar) return;

    const impersonation = state.me.impersonation;
    if (!impersonation?.active) {
        bar.classList.add('hidden');
        bar.innerHTML = '';
        return;
    }

    bar.classList.remove('hidden');
    bar.innerHTML = `
        <span aria-hidden="true">👁</span>
        <span>${esc(t('admin.impersonation.banner', {
            user: state.me.user.full_name || state.me.user.username,
            admin: impersonation.impersonated_by,
        }))}</span>
    `;

    const end = el(`<button type="button" class="btn btn-secondary btn-sm">${esc(t('admin.impersonation.end'))}</button>`);
    end.addEventListener('click', async () => {
        const ok = await confirmDialog({
            title: t('admin.impersonation.end'),
            message: t('admin.impersonation.end_confirm'),
            confirmLabel: t('admin.impersonation.end'),
            variant: 'primary',
        });
        if (ok) logout();
    });
    bar.appendChild(end);
}

/* ============================================================
   Notifications
   ============================================================ */

async function refreshNotifications() {
    const bell = document.getElementById('notif-bell');
    if (!bell) return;

    try {
        const data = await apiGet('notifications.php?action=list&limit=15', { salonScope: false });
        state.notifications = data.notifications || [];
        state.unreadCount = data.unread_count || 0;
    } catch {
        // notifications.php is added in a later stage, and a transient failure
        // should never break the shell -- the bell simply shows no badge.
        state.notifications = [];
        state.unreadCount = 0;
    }

    const badge = bell.querySelector('.icon-btn-badge');
    if (state.unreadCount > 0) {
        if (badge) badge.textContent = state.unreadCount > 99 ? '99+' : String(state.unreadCount);
        else bell.appendChild(el(`<span class="icon-btn-badge">${state.unreadCount > 99 ? '99+' : state.unreadCount}</span>`));
        bell.setAttribute('aria-label', t('admin.notifications.unread', { count: state.unreadCount }));
    } else {
        badge?.remove();
        bell.setAttribute('aria-label', t('admin.notifications.title'));
    }
}

function openNotifications(host, button) {
    toggleMenu(host, button, () => {
        const menu = el('<div class="menu menu-wide" role="menu"></div>');
        const header = el(`
            <div class="row" style="padding:6px 10px 8px">
                <strong style="flex:1">${esc(t('admin.notifications.title'))}</strong>
            </div>
        `);

        if (state.unreadCount > 0) {
            const markAll = el(`<button type="button" class="btn btn-ghost btn-sm">${esc(t('admin.notifications.mark_all'))}</button>`);
            markAll.addEventListener('click', async (event) => {
                event.stopPropagation();
                try {
                    await apiPost('notifications.php?action=read_all', {}, { salonScope: false });
                    await refreshNotifications();
                    closeMenus();
                    toastSuccess(t('admin.notifications.marked_all'));
                } catch (error) {
                    toastApiError(error);
                }
            });
            header.appendChild(markAll);
        }
        menu.appendChild(header);
        menu.appendChild(el('<div class="menu-divider"></div>'));

        if (!state.notifications.length) {
            menu.appendChild(el(`
                <div style="padding:22px 14px;text-align:center" class="text-sm text-muted">
                    ${esc(t('admin.notifications.empty'))}
                </div>
            `));
            return menu;
        }

        const list = el('<div class="notif-list"></div>');
        state.notifications.forEach((notification) => {
            let params = {};
            try {
                params = notification.params ? JSON.parse(notification.params) : {};
            } catch {
                params = {};
            }

            const item = el(`
                <button type="button" class="notif-item ${notification.read_at ? '' : 'unread'}" role="menuitem">
                    <span class="notif-icon" aria-hidden="true">${esc(NOTIF_ICONS[notification.type] || '🔔')}</span>
                    <span class="notif-text">
                        ${esc(t(notification.title_key, params))}
                        <span class="notif-time">${esc(formatRelative(notification.created_at))}</span>
                    </span>
                </button>
            `);
            item.addEventListener('click', async () => {
                closeMenus();
                if (!notification.read_at) {
                    try {
                        await apiPost('notifications.php?action=read',
                            { notification_id: notification.notification_id }, { salonScope: false });
                        await refreshNotifications();
                    } catch {
                        /* marking read is best-effort */
                    }
                }
                if (notification.link) navigate(notification.link);
            });
            list.appendChild(item);
        });

        menu.appendChild(list);
        return menu;
    });
}

const NOTIF_ICONS = {
    registration: '🎉',
    campaign_sent: '✉️',
    birthday: '🎂',
    user_invited: '👤',
    subscription: '💳',
    system: '🔔',
};

/* ============================================================
   Menus (shared open/close behaviour)
   ============================================================ */

function closeMenus() {
    document.querySelectorAll('.menu').forEach((menu) => menu.remove());
    document.querySelectorAll('[aria-haspopup="true"]').forEach((button) => {
        button.setAttribute('aria-expanded', 'false');
    });
}

function toggleMenu(host, button, build) {
    const existing = host.querySelector('.menu');
    closeMenus();
    if (existing) return;

    const menu = build();
    host.appendChild(menu);
    button.setAttribute('aria-expanded', 'true');
    menu.addEventListener('click', (event) => event.stopPropagation());
}

document.addEventListener('click', closeMenus);
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeMenus();
});

/* ============================================================
   Router
   ============================================================ */

export function navigate(route) {
    if (window.location.hash === route) renderRoute(true);
    else window.location.hash = route;
}

let renderToken = 0;

async function renderRoute(force = false) {
    const hash = window.location.hash || '';
    const base = hash.split('?')[0];

    if (!base || !ROUTES[base]) {
        const fallback = defaultRoute(state.permissions);
        if (base !== fallback) {
            window.location.replace(fallback);
            return;
        }
    }

    if (!force && state.currentRoute === hash) return;
    state.currentRoute = hash;

    highlightActiveNav();
    closeSidebar();

    const container = document.getElementById('view');
    if (!container) return;

    // Guard against a slow module resolving after the user moved on.
    const token = ++renderToken;

    // Client-side gate. The server enforces the same rule; this only avoids
    // rendering a screen whose every request would 403.
    const navItem = findNavItem(base);
    if (navItem && !navItem.when(state.permissions)) {
        container.innerHTML = '';
        container.appendChild(forbiddenState());
        return;
    }

    container.innerHTML = '';
    container.appendChild(skeletonCards(4));

    let module;
    try {
        module = await ROUTES[base]();
    } catch (error) {
        if (token !== renderToken) return;
        console.error('Failed to load view module', base, error);
        container.innerHTML = '';
        container.appendChild(emptyState({
            art: 'search',
            title: t('admin.errors.view_unavailable_title'),
            text: t('admin.errors.view_unavailable_text'),
        }));
        return;
    }

    if (token !== renderToken) return;

    container.innerHTML = '';
    try {
        await module.render(container, viewContext());
    } catch (error) {
        if (token !== renderToken) return;
        console.error('View render failed', base, error);
        container.innerHTML = '';
        container.appendChild(emptyState({
            art: 'search',
            title: t('admin.errors.view_failed_title'),
            text: error?.message || t('admin.errors.generic'),
        }));
        return;
    }

    // Views may include data-i18n markup alongside t() output.
    i18n.applyTranslations();
    window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
}

window.addEventListener('hashchange', () => renderRoute());

/* ============================================================
   Mobile sidebar
   ============================================================ */

function openSidebar() {
    document.querySelector('.sidebar')?.classList.add('open');
    if (!document.querySelector('.sidebar-backdrop')) {
        const backdrop = el('<div class="sidebar-backdrop"></div>');
        backdrop.addEventListener('click', closeSidebar);
        document.body.appendChild(backdrop);
    }
}

function closeSidebar() {
    document.querySelector('.sidebar')?.classList.remove('open');
    document.querySelector('.sidebar-backdrop')?.remove();
}

/* ============================================================
   Wire-up
   ============================================================ */

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('topbar-menu')?.addEventListener('click', (event) => {
        event.stopPropagation();
        const sidebar = document.querySelector('.sidebar');
        if (sidebar?.classList.contains('open')) closeSidebar();
        else openSidebar();
    });

    const bellHost = document.getElementById('notif-host');
    const bell = document.getElementById('notif-bell');
    bell?.addEventListener('click', (event) => {
        event.stopPropagation();
        openNotifications(bellHost, bell);
    });

    // Sidebar links are real anchors so middle-click and copy-link work; the
    // hashchange listener does the actual routing.
    document.getElementById('sidebar-nav')?.addEventListener('click', (event) => {
        if (event.target.closest('.nav-item')) closeSidebar();
    });

    boot();
});
