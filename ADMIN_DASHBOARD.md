# Admin Dashboard

The back office at `admin-dashboard.html`: a bilingual, permission-aware
single-page app that a salon owner opens every morning and an administrator uses
to run the whole platform.

---

## Contents

- [Signing in and where you land](#signing-in-and-where-you-land)
- [Roles and permissions](#roles-and-permissions)
- [The screens](#the-screens)
- [Architecture](#architecture)
- [Translations](#translations)
- [Setting it up](#setting-it-up)
- [Cron](#cron)
- [Demo data](#demo-data)
- [Extending it](#extending-it)
- [Known gaps](#known-gaps)

---

## Signing in and where you land

`login.html` sends people to different places by role:

| Role | Lands on | What they are |
|---|---|---|
| `admin` | `admin-dashboard.html` | Platform administrator |
| `admin_delegate` | `admin-dashboard.html` | Platform support, minus billing |
| `customer_admin` | `admin-dashboard.html` | Salon owner |
| `customer_admin_delegate` | `admin-dashboard.html` | Salon staff, granular rights |
| `customer_facing_tablet_user` | `index.html` | The tablet kiosk |

An invited user never receives a password. They get a one-time link to
`set-password.html`, choose their own password, and are signed straight in.

---

## Roles and permissions

Two layers, and the server is always the authority. `js/admin/permissions.js`
only decides what to *show*; every endpoint re-checks with
`requirePermission()` from `api/permissions.php`.

**Salon permissions** — what a delegate can be granted per salon:

| Key | Unlocks |
|---|---|
| `view_insights` | Übersicht, Kunden, exports |
| `manage_campaigns` | Kampagnen |
| `manage_users` | Benutzer, invitations |
| `change_settings` | Einstellungen |
| `manage_products` | *reserved, not built* |
| `manage_magazine` | *reserved, not built* |

**Platform permissions** — `platform_billing`, `platform_config`,
`delete_admin`, `view_all_audit`.

Who gets what:

- `admin` — everything.
- `admin_delegate` — everything except the four platform keys. Can impersonate
  for support, cannot open Abrechnung, and **cannot see an administrator's rows
  in the audit log**.
- `customer_admin` — all six salon permissions, for their own salons.
- `customer_admin_delegate` — only what is granted in
  `coiffure_user_permissions`.

The two reserved keys appear in the invite dialog as disabled checkboxes with a
"bald verfügbar" hint. They are already in the server matrix, so enabling them
later needs no migration.

---

## The screens

| Sidebar (de) | Route | Module | Needs |
|---|---|---|---|
| Übersicht | `#/uebersicht` | `views/home.js` | `view_insights` |
| Kunden | `#/kunden` | `views/customers.js` | `view_insights` |
| Kampagnen | `#/kampagnen` | `views/campaigns.js` | `manage_campaigns` |
| Benutzer | `#/benutzer` | `views/users.js` | `manage_users` |
| Einstellungen | `#/einstellungen` | `views/settings.js` | `change_settings` |
| Protokoll | `#/protokoll` | `views/audit.js` | any dashboard role |
| Salons | `#/salons` | `views/salons.js` | platform role |
| Abrechnung | `#/abrechnung` | `views/billing.js` | `platform_billing` |
| White-Label | `#/white-label` | `views/whitelabel.js` | `platform_config` |
| Plattform | `#/plattform` | `views/platform-settings.js` | `platform_config` |
| Mein Profil | `#/profil` | `views/profile.js` | everyone |

Notes on the less obvious ones:

**Übersicht** — KPI cards with sparklines, an 8-week check-in chart with a
daily/weekly toggle, member growth against customers going inactive, and the
next five birthdays with a one-click birthday mail. With more than one salon the
top bar offers "Alle Salons", which aggregates.

**Kunden** — filter bar (membership, age, postcode, last visit, registration
window), saved segments, best-customer and inactive presets, a slide-over
profile with notes and tags, and a CSV export. The export is consent-aware: with
scope `marketing` the server forces `consent_email = true` and narrows the
columns, whatever the client asks for.

**Kampagnen** — a four-step wizard for one-time sends (recipients → content →
review → send), the four automatic types (birthday, we-miss-you, thank-you,
referral reminder), and a log of everything sent. A spam limit (default four
mails per 30 days) is enforced at review time, with a skip-or-send-anyway
choice.

**Einstellungen** — six sections: Allgemein (master data, logo, colours, WLAN),
Tablet (greeting, background, idle timeout, which modules the kiosk offers),
Treueprogramm (plus refer-a-friend), Geburtstag, Social & QR, Öffnungszeiten.
Every field change is written to `coiffure_settings_audit` with its before and
after value; anything whose column name contains "password" is masked.

**Protokoll** — the audit log, and a read-only GDPR consent trail. Consent
cannot be edited here on purpose: a record that could be rewritten proves
nothing.

**Abrechnung** — plans, subscriptions and invoices. There is no payment provider
wired in; this records what a salon owes so an operator can invoice outside the
system. Invoices open as a printable page rather than a PDF (no build step, no
PDF library, and every browser prints to PDF).

---

## Architecture

```
admin-dashboard.html      shell only: sidebar, topbar, <main id="view">, toasts
css/admin.css             design system (tokens, tables, forms, drawers, empty states)
js/admin/app.js           boot, hash router, salon context, language, bell, impersonation bar
js/admin/api.js           fetch wrapper (Bearer, salon scope, 401 → login, CSV download)
js/admin/permissions.js   client mirror of the matrix; drives the sidebar
js/admin/ui.js            table, modal, drawer, toast, confirmDialog, emptyState, charts
js/admin/views/*.js       one module per screen, each exporting render(container, ctx)
```

Constraints that shaped all of it:

1. **No build step.** GitHub Pages serves the repo as-is and the deploy action
   uploads only `./api/`. Plain ES modules and `<script>` tags — no bundler, no
   npm, no framework.
2. **Cross-origin.** The frontend and the API are on different hosts, so auth is
   `Authorization: Bearer <token>` from `localStorage`, never a cookie.
3. **Filesystem routing.** One `.php` file per endpoint; larger resources use
   `?action=` sub-dispatch.
4. **Migrations are hand-run.** `migrations/NNN_name.sql` plus an idempotent
   `api/apply_migration_NNN.php`.

A view is an ES module exporting `render(container, ctx)`, where `ctx` carries
`{ user, salon, salonId, salons, isAllSalons, permissions, reload }`. Views are
imported lazily by the router, so a screen a user cannot reach is never
downloaded.

CDN libraries — Chart.js, Quill, QRCode.js — all degrade gracefully. If the CDN
is unreachable the charts show a message, the campaign editor falls back to a
plain textarea, and the QR preview says so. Nothing breaks.

### Endpoints added for the dashboard

| File | Guard | Purpose |
|---|---|---|
| `permissions.php` | *(helper)* | matrix, `requirePermission`, `resolveSalonScope` |
| `me.php` | authed | user + salons + effective permissions |
| `dashboard-stats.php` | `view_insights` | KPIs, series, growth, birthdays |
| `insights.php` | `view_insights` | customer list, profile, consent-aware export |
| `customer_filters.php` | *(helper)* | the single filter→SQL implementation |
| `segments.php` | `view_insights` | saved filter combinations |
| `campaigns.php` | `manage_campaigns` | the whole campaign lifecycle |
| `campaign_engine.php` | *(helper)* | recipients, spam limit, discount codes, sending |
| `cron-campaigns.php` | `CRON_TOKEN` | scheduled and automatic sends |
| `salon-settings.php` | `change_settings` | the settings sections |
| `user-invite.php` | `manage_users` | invitations and granular grants |
| `auth-set-password.php` | invitation token | accept an invitation |
| `audit-log.php` | role-scoped | the audit log |
| `consent-history.php` | `view_insights` | GDPR consent trail (read only) |
| `consent_log.php` | *(helper)* | writes that trail |
| `notifications.php` | authed | bell list, read state, preferences |
| `notify.php` | *(helper)* | writes notifications |
| `billing.php` | `platform_billing` | plans, subscriptions, invoices |
| `whitelabel.php` | `platform_config` | domain, SMTP, colours, test mail |
| `impersonate.php` | platform role | support sessions |
| `salon-export.php` | platform role | GDPR export of a whole salon |
| `seed-demo.php` | admin or token | demo data |

`customer_filters.php` is deliberately the only place a customer filter becomes
SQL. A saved segment therefore selects the same people in the Kunden list, in
the CSV export and in a campaign send — three code paths would eventually
disagree.

### Impersonation

An administrator can open a salon's dashboard as its owner from the Salons
table. Three properties make it defensible:

1. Only a `customer_admin` or `customer_admin_delegate` can be impersonated, so
   it can never be an escalation path.
2. `coiffure_sessions.impersonated_by` records who started it. `me.php` reports
   it and the dashboard shows a banner for the whole session — an impersonated
   session never looks like a normal one.
3. It is audited *before* the token is handed out, and the session is
   short-lived and excluded from the sliding renewal that would otherwise
   stretch two hours into thirty days.

Ending it is just signing out; the administrator's own token is untouched.

---

## Translations

`js/i18n.js` already did what was needed, so it is reused unchanged: `t(key,
params)` with dot paths, `lang/de.json` and `lang/en.json`, `setLanguage()` with
no reload, and three-layer persistence (`localStorage.app_language` →
`user_data.preferred_language` → `POST user-settings.php`).

German is authored first; English mirrors it. Every user-visible string in
`js/admin/**`, `admin-dashboard.html`, `login.html` and `set-password.html` goes
through `t()`.

Run the guard before committing:

```bash
node scripts/check-translations.mjs
```

It asserts that de and en define exactly the same keys, that every key the
dashboard uses resolves in **both** files, and that no value is empty. Keys
built at runtime (``t(`admin.roles.${role}`)``) cannot be found by scanning, so
they are declared in `DYNAMIC_GROUPS` at the top of the script — add to that list
when you introduce a new one.

---

## Setting it up

1. **Apply the migrations.** In order, and skip 004:

   ```bash
   php api/apply_migration_017.php    # RBAC, invitations
   php api/apply_migration_018.php    # salon settings columns, opening hours
   php api/apply_migration_019.php    # segments
   php api/apply_migration_020.php    # campaigns
   php api/apply_migration_021.php    # notifications
   php api/apply_migration_022.php    # billing
   php api/apply_migration_023.php    # white-label
   php api/apply_migration_024.php    # audit widening, consent history
   php api/apply_migration_025.php    # customer notes and tags
   php api/apply_migration_026.php    # support impersonation
   ```

   Each runner is idempotent — running it twice is safe and reports what it
   skipped. From a browser they need an administrator session or
   `?token=<MIGRATION_TOKEN>`.

   **Never apply `004_remove_salon_id_from_users.sql`.** `validateSession()`
   still selects `coiffure_users.salon_id`; dropping it breaks all
   authentication.

2. **Set the environment.** In `api/.env`:

   ```ini
   MIGRATION_TOKEN=<long random string>
   CRON_TOKEN=<long random string>
   DASHBOARD_URL=https://coiffureai.com     # used to build invitation links

   SMTP_HOST=...
   SMTP_PORT=587
   SMTP_SECURE=tls
   SMTP_USER=...
   SMTP_PASS=...
   MAIL_FROM=noreply@coiffureai.com
   ```

   A salon with its own SMTP configured under White-Label overrides these.

3. **Check it.** Sign in as an administrator; the sidebar should show Salons,
   Abrechnung, White-Label and Plattform. Sign in as a salon owner; those four
   should be gone.

---

## Cron

Automatic campaigns and scheduled sends need one recurring call. Once an hour is
plenty — the runner is idempotent and will not send the same automatic campaign
to the same customer twice.

```cron
# Coiffure Digital — automatic and scheduled campaigns, hourly at :05
5 * * * * curl -fsS "https://clouedo.com/coiffure/api/cron-campaigns.php?token=YOUR_CRON_TOKEN" >/dev/null
```

Or from the CLI, if cron runs on the same host as the code:

```cron
5 * * * * /usr/bin/php /path/to/api/cron-campaigns.php >/dev/null 2>&1
```

What one run does:

1. Sends any one-time campaign whose scheduled time has passed.
2. For each salon, for each enabled automatic type, finds the customers who
   qualify today and sends to them.
3. Records every recipient, so nobody is ever mailed twice for the same
   automatic campaign.

The four automatic types:

| Type | Fires when |
|---|---|
| `birthday` | N days before a customer's birthday (N from the salon's settings) |
| `we_miss_you` | last visit longer ago than the configured window |
| `thank_you` | after a check-in, once per customer per period |
| `referral_reminder` | for members, when refer-a-friend is enabled |

A wrong or missing token returns 403 and does nothing. The Kampagnen screen also
has a "Jetzt ausführen" button that calls the same code path, which is the
quickest way to test the setup.

---

## Demo data

```bash
php api/seed-demo.php              # create, or report that it already exists
php api/seed-demo.php --reset      # delete and rebuild
```

Creates Salon Bella Vista with 45 customers, ~350 check-ins over eight weeks,
consent records, a subscription with two invoices, and a few unread
notifications — enough that every screen has something real on it.

| Account | Password | What it demonstrates |
|---|---|---|
| `bella_owner` | `demo1234` | full salon rights |
| `bella_delegate` | `demo1234` | granular rights — `view_insights` only |
| `bella_tablet` | `demo1234` | kiosk account, redirected to `index.html` |

Signing in as the delegate is the fastest way to see the permission layer: the
sidebar drops to three items, and a direct `#/kampagnen` hash shows "Kein
Zugriff" while the API returns 403 for the same request.

---

## Extending it

**A new screen.** Add a `views/<name>.js` exporting `render(container, ctx)`,
register the route in `ROUTES` in `app.js`, add the entry to `NAV` in
`permissions.js` with a `when` predicate, add the labels to both language files,
and run the translation check.

**A new permission.** Add the key to `SALON_PERMISSIONS` in **both**
`api/permissions.php` and `js/admin/permissions.js`, add
`admin.permissions.<key>` and `<key>_desc` to both language files, and guard the
endpoint with `requirePermission()`. The invite dialog picks it up
automatically.

**A new endpoint.** Follow the house shape: `require_once config.php` →
`setCorsHeaders()` → `getDbConnection()` → `requireAuth()` →
`resolveSalonScope()` → `requirePermission()` → `sendJsonResponse()`. Never
trust a client-supplied `salon_id`; `resolveSalonScope()` exists to check it.

**A new notification.** Add the type to `NOTIFY_TYPES` in `notify.php` and to
`NOTIFICATION_TYPES` in `notifications.php`, add `admin.notify.<key>` to both
language files and `admin.notifications.events.<type>` for the preference
checkbox, then call `notifySalonAdmins()`. Pass a translation key and a
parameter bag, never a rendered sentence — one row has to read correctly in both
languages.

Two things worth knowing before changing anything:

- `bindTyped()` in `api/permissions.php` derives a `bind_param` type string from
  the values. Prefer it over a hand-written one: a transposed character silently
  coerces a value rather than erroring, and that has caused real bugs here.
- A top-level `const` in PHP is evaluated in source order. Declare constants
  **above** the request dispatch, or a handler will run before they exist.

---

## Known gaps

Deliberate, and listed so nobody goes looking:

- **Produkte and Magazin are not built.** The tablet's Shop tab was removed
  earlier, so a product admin screen would feed nothing. Magazine content stays
  in `coiffure_trends`, curated directly in the database. Both permission keys
  exist server-side, so enabling them later needs no migration.
- **No payment provider.** Billing records what is owed; invoicing happens
  outside the system.
- **Domain verification is manual.** White-Label stores a custom domain and
  shows the DNS record to create, but nothing checks it automatically.
- **Notification e-mail modes are stored, not yet sent.** `instant` and `daily`
  are saved per user; the bell works, the digest sender does not exist yet.
- **`failed_login_attempts` and `locked_until` exist on `coiffure_users` with no
  code implementing the lockout** the README describes. Pre-existing, untouched.
