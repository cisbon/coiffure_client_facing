/**
 * Client-side mirror of api/permissions.php
 * ------------------------------------------------------------
 * Used only to decide what to show: which sidebar items to render, which
 * buttons to enable. It is NOT a security boundary. Every endpoint re-checks
 * with requirePermission()/resolveSalonScope(), so a user who edits this file
 * or types a route by hand gains a broken screen, not data.
 *
 * The permission list itself comes from api/me.php rather than being derived
 * here, so the matrix lives in exactly one place (the server). This module
 * only interprets it.
 */

/** Salon-scoped permissions, matching SALON_PERMISSIONS in permissions.php. */
export const SALON_PERMISSIONS = [
    'manage_campaigns',
    'view_insights',
    'manage_products',
    'manage_magazine',
    'manage_users',
    'change_settings',
];

/** Platform permissions, matching PLATFORM_PERMISSIONS in permissions.php. */
export const PLATFORM_PERMISSIONS = [
    'platform_billing',
    'platform_config',
    'delete_admin',
    'view_all_audit',
];

/**
 * Permissions whose modules are not built yet. The invite dialog still shows
 * them (the spec lists all six checkboxes) but disabled, so a Customer Admin
 * can see what is coming without granting a right that leads nowhere.
 */
export const RESERVED_PERMISSIONS = ['manage_products', 'manage_magazine'];

export const PLATFORM_ROLES = ['admin', 'admin_delegate'];

export class Permissions {
    /**
     * @param {object} me the api/me.php response
     */
    constructor(me) {
        this.role = me?.user?.role || '';
        this.all = new Set(me?.permissions || []);
        this.bySalon = me?.permissions_by_salon || {};
        this.salonId = null;
    }

    /** Scope permission checks to one salon (used by the salon switcher). */
    setSalon(salonId) {
        this.salonId = salonId === null || salonId === 'all' ? null : String(salonId);
    }

    /**
     * Does the user hold this permission in the current scope?
     * With "Alle Salons" selected the union is used, which is what an
     * aggregated view needs.
     */
    can(permission) {
        if (this.salonId && this.bySalon[this.salonId]) {
            return this.bySalon[this.salonId].includes(permission);
        }
        return this.all.has(permission);
    }

    /** True if the user holds at least one of the given permissions. */
    canAny(...permissions) {
        return permissions.some((p) => this.can(p));
    }

    /**
     * Salon ids where this user holds the permission, ignoring the current
     * scope. Used where the question is "which salons may I do this in?"
     * rather than "may I do this here?" -- e.g. whether to offer a salon
     * chooser at all.
     *
     * @returns {string[]} salon ids as strings, matching permissions_by_salon
     */
    salonsWith(permission) {
        return Object.keys(this.bySalon)
            .filter((salonId) => (this.bySalon[salonId] || []).includes(permission));
    }

    is(...roles) {
        return roles.includes(this.role);
    }

    get isAdmin() {
        return this.role === 'admin';
    }

    get isPlatform() {
        return PLATFORM_ROLES.includes(this.role);
    }

    /**
     * Admin Delegates may not delete or demote administrators, change billing,
     * touch platform configuration, or read other administrators' audit
     * entries. Those four are exactly the platform permissions they lack, so
     * this is just a readable alias.
     */
    get canManagePlatformConfig() {
        return this.can('platform_config');
    }

    get canManageBilling() {
        return this.can('platform_billing');
    }

    get canViewAllAudit() {
        return this.can('view_all_audit');
    }

    get canDeleteAdmins() {
        return this.can('delete_admin');
    }
}

/**
 * The sidebar definition.
 *
 * Each item declares what it needs; buildNav() filters the tree so a user only
 * ever sees routes they can actually open. Sections collapse to nothing when
 * every child is filtered out.
 *
 * `when` receives the Permissions instance and returns a boolean.
 */
export const NAV = [
    {
        id: 'salon',
        labelKey: 'admin.nav.section_salon',
        items: [
            {
                id: 'home',
                route: '#/uebersicht',
                labelKey: 'admin.nav.home',
                icon: '📊',
                when: (p) => p.can('view_insights'),
            },
            {
                id: 'customers',
                route: '#/kunden',
                labelKey: 'admin.nav.customers',
                icon: '👥',
                when: (p) => p.can('view_insights'),
            },
            {
                id: 'campaigns',
                route: '#/kampagnen',
                labelKey: 'admin.nav.campaigns',
                icon: '✉️',
                when: (p) => p.can('manage_campaigns'),
            },
        ],
    },
    {
        id: 'manage',
        labelKey: 'admin.nav.section_manage',
        items: [
            {
                id: 'users',
                route: '#/benutzer',
                labelKey: 'admin.nav.users',
                icon: '🧑‍💼',
                when: (p) => p.can('manage_users'),
            },
            {
                id: 'settings',
                route: '#/einstellungen',
                labelKey: 'admin.nav.settings',
                icon: '⚙️',
                when: (p) => p.can('change_settings'),
            },
            {
                id: 'audit',
                route: '#/protokoll',
                labelKey: 'admin.nav.audit',
                icon: '📜',
                // Every dashboard role gets an audit view; the server decides
                // how much of it they are allowed to see.
                when: () => true,
            },
        ],
    },
    {
        id: 'platform',
        labelKey: 'admin.nav.section_platform',
        items: [
            {
                id: 'salons',
                route: '#/salons',
                labelKey: 'admin.nav.salons',
                icon: '🏪',
                when: (p) => p.isPlatform,
            },
            {
                id: 'billing',
                route: '#/abrechnung',
                labelKey: 'admin.nav.billing',
                icon: '💳',
                when: (p) => p.canManageBilling,
            },
            {
                id: 'whitelabel',
                route: '#/white-label',
                labelKey: 'admin.nav.whitelabel',
                icon: '🎨',
                when: (p) => p.canManagePlatformConfig,
            },
            {
                id: 'platform-settings',
                route: '#/plattform',
                labelKey: 'admin.nav.platform_settings',
                icon: '🛠️',
                when: (p) => p.canManagePlatformConfig,
            },
        ],
    },
];

/**
 * Filter NAV down to what this user may open.
 * @returns {Array} sections with at least one visible item
 */
export function buildNav(permissions) {
    return NAV
        .map((section) => ({
            ...section,
            items: section.items.filter((item) => item.when(permissions)),
        }))
        .filter((section) => section.items.length > 0);
}

/** The route a user should land on, given what they may see. */
export function defaultRoute(permissions) {
    const sections = buildNav(permissions);
    if (!sections.length) return '#/profil';
    return sections[0].items[0].route;
}

/** Look up the nav item owning a route, for highlighting and guarding. */
export function findNavItem(route) {
    const base = String(route || '').split('?')[0];
    for (const section of NAV) {
        for (const item of section.items) {
            if (item.route === base) return item;
        }
    }
    return null;
}
