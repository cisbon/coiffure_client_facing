# Tablet App – Check-in, Registration & Digital Magazine

The in-salon tablet is a self-service kiosk. All wallet/pass features have been
removed — membership is now a status only, no downloadable card.

## Navigation & idle screen

- A sticky **left navigation rail** is always visible (icon rail, ~76px). A
  **burger** at the top expands it to show labels. Top→bottom the items are:
  **Stöbern**, **Check-In**, **Registrieren**, **Social & WLAN**, **AI Hairstyle**.
- **Stöbern** (the digital magazine & shop) is the **start / idle screen**.
- The **Social & WLAN** label reads just **Social** unless the salon configured
  a guest-WiFi name AND password in the admin dashboard.
- **AI Hairstyle** opens the existing full-screen KI consultation overlay.
- The content area is centered (max-w-5xl) with modest horizontal padding,
  tuned for landscape tablet readability.
- After 30 s of inactivity (except during an active check-in or a
  partially-filled registration) the tablet auto-returns to Stöbern.

## 1. Self check-in

A conversation-style, birthday-first flow with a phone fallback. There are no
"Weiter"/"Submit" buttons on the birthday screen — the flow auto-proceeds.

1. **Birthday** – two custom touch **scroll wheels** (Tag 1–31, Monat
   Jan–Dez). No native `<select>`, no year, no keyboard. Momentum + snap +
   a soft haptic tick per item. When both wheels have a value, the finger is
   off, and 800 ms have passed, the lookup fires automatically.
   `GET api/checkin.php?action=candidates&day=DD&month=MM[&q=…]` returns
   `{id, first_name, last_name_initial, gender}`.
2. **Routing** (frontend, on the result count):
   - `0` → phone fallback (`no_match`)
   - `1` → auto-confirm ("Sind Sie … ?")
   - `2–8`, no first-name+initial collision → name list (each card → inline
     "Sind Sie … ?" confirm sub-step)
   - collision, or `>8` → phone fallback (`name_collision` / `too_many_results`)
3. **Confirm** – `POST api/checkin.php {"action":"confirm","customer_id":N}`
   logs the visit (once per calendar day) and returns the **welcome payload**:
   first name, dynamic loyalty progress, and `is_duplicate` / `is_birthday_week`
   / `is_reward_visit` / `was_referred` flags.
4. **Phone fallback** – custom numeric keypad (1–9, 0, ⌫, +). `POST
   api/checkin.php {"action":"phone","phone_number":"…"}` matches on trailing
   digits (tolerant of spaces/+/-/etc.). An ambiguous number returns a minimal
   name list. After **3 failed lookups** input locks for the session and a
   `phone_lockout` event is logged (salon_id + timestamp only). A single match
   returns the welcome payload.
5. **Welcome** – one screen for every path. Shows the greeting, the **dynamic
   loyalty progress bar** (100 % from the salon's config — no hardcoded values),
   one contextual message (birthday week → reward visit → referral), and
   auto-returns to Stöbern (8 s, or 5 s for a duplicate check-in).

The check-in runs as a **fullscreen takeover** (fixed overlay, body scroll
locked, an ✕ top-right returns to Stöbern). This keeps the custom scroll wheels
immediately scrollable on iPad Safari, where a competing page-scroll region
would otherwise steal the first touch. A fixed header shows a **dynamic progress
indicator** (Geburtstag → Check-In, with a Telefonnummer step inserted only when
the phone fallback is used), the **language switch** (DE/EN, also available in
the AI fullscreen overlay), and the single timeout bar directly beneath it.

**Localisation:** the customer-facing kiosk (check-in, registration form, browse
hero/tabs, guest-WiFi card) is fully translated via `data-i18n` /
`data-i18n-html` / `data-i18n-placeholder` against `lang/de.json` + `lang/en.json`;
the DE/EN switch re-renders everything live.

**Configurable timeouts:** every timeout (idle-return, birthday, auto-confirm,
name list, name sub-step, phone, welcome-success, welcome-duplicate, staff-PIN,
staff-search) is stored in `coiffure_global_settings` (seconds) and editable by
an **admin** in the dashboard ("Globale Einstellungen"). The kiosk loads them
from `GET api/global-settings.php` at startup; code defaults apply if the table
is absent.

**Staff override:** a 3 s long-press on the **Check-In** rail item opens a
4-digit PIN pad (`staff_pin`, per salon, default `0000`). After the correct PIN
staff get a full-name search (`staff_search`) and can check a customer in
(`staff_confirm`, logged as a `manual` visit). 3 wrong PINs lock staff mode for
5 minutes.

**Analytics:** each session fires non-PII events (`checkin_started`,
`birthday_selected`, `collision_detected`, `phone_fallback_triggered`,
`phone_lookup_failed`, `phone_lockout`, `checkin_completed`, `checkin_duplicate`,
…) via `POST api/checkin.php {"action":"event", …}` into `coiffure_checkin_events`.
Only `customer_id` (an integer key) may accompany an event — never a name,
phone or birthday.

**Salon scope (important):** the tablet logs in with a per-salon account, so a
check-in only ever searches customers of the **logged-in salon** — the client
cannot check in a customer from another salon. For multi-store brands, an
administrator groups the stores in `coiffure_salon_connections` (same
`group_id`); connected salons then share their customer base, so a check-in
searches across all of them. A salon that is not listed there stays scoped to
itself. The server derives the allowed salon set from the **session** (not from
the client-supplied `salon_id`).

```sql
-- Connect salon 1 and salon 2 as one brand (group 100), admin only:
INSERT INTO coiffure_salon_connections (group_id, salon_id) VALUES (100, 1), (100, 2);
```

**Duplicate check-in:** a customer can only log one visit per calendar day.
Checking in again the same day shows "Sie sind heute bereits eingecheckt" and
does **not** increase the visit count (so the loyalty reward can't be gamed).
The next day it counts again — there is no check-out step.

**GDPR:** candidate data is minimal (first name + last initial) and only
returned after a birthday is entered — a legitimate-interest identification
step. Failed-lookup details are never tied to a person. Membership/marketing
flags are NOT required to check in.

## 1a. Loyalty program (per salon, configurable)

The loyalty program is configured per salon in the admin dashboard
("**Treueprogramm**" tab) and stored on `coiffure_salons`:
`loyalty_active`, `loyalty_visit_threshold` (2–50), `loyalty_discount_type`
(`fixed_eur`|`percentage`), `loyalty_discount_value`, `loyalty_discount_label`
(optional override). The tablet reads it via
`GET api/loyalty-config.php?salon_id=N` (public) and renders the welcome
progress bar and the marketing copy from it — nothing is hardcoded. Owner edits
`POST` to the same endpoint (admin only); every changed field is written to
`coiffure_settings_audit`. Changing the threshold never resets a customer's
visit count — the modulo maths simply counts against the new threshold going
forward.

## 2. Registration

The membership sign-up (wallet-free). Key fields:

- **Anrede** (mandatory) as chips: **Frau / Herr / Divers** (stored as
  female/male/diverse; drives the check-in avatar).
- **Titel** (optional): **Dr. / Prof. / Prof. Dr.** chips — single-select but
  deselectable (tap a chip again to clear it). On the same line as Anrede.
- **Geburtstag** is a native `<input type="date">` (iOS/iPad shows a wheel),
  **mandatory including the year**, capped at today. The value is split into
  birth_day / birth_month / birth_year on submit.
- **Mobilnummer** is **mandatory** and **numeric-only** (numeric keyboard,
  non-digits stripped as you type; placeholder `0170 1234567`).
- The membership opt-in reads *"…und profitiere von allen Vorteilen."* and is
  **checked by default** (marketing/DSGVO consents stay unchecked).
- **PLZ / Ort** appear only once (mandatory section); the optional postal-address
  block reuses them, so no duplicate fields.

After a successful submit the tablet shows the Social/WiFi screen (with a short
welcome banner), then idles back to Stöbern.

## Guest WiFi

Salons set an optional guest-WiFi **name + password** in the admin dashboard
("Salon Profile & Branding" → *Gäste-WLAN*). When both are present, the tablet's
Social screen shows a **WiFi card** with a scannable WiFi QR code
(`WIFI:T:WPA;S:…;P:…;;`) and the credentials, and the nav item is labelled
**Social & WLAN** (otherwise just **Social**). Stored via `salon-branding.php`
(GET returns `wifi_ssid`/`wifi_password`; POST accepts them).

## 3. Digital magazine & shop ("Stöbern")

Three tabs:

- **Trends** – `GET api/content.php?type=trend` (articles + embedded videos).
- **Tipps** – `GET api/content.php?type=tip`.
- **Shop** – `GET api/products.php` (in-salon catalogue; no cart/checkout —
  the product detail shows a "Fragen Sie Ihr Stylisten-Team" prompt).

MVP content is served from `data/trends.json` and `data/products.json`. The
endpoints are DB-ready, so a `coiffure_content` / `coiffure_products` table can
back them later (managed via the web access) without changing the response shape.

## Files

| File | Purpose |
|------|---------|
| `migrations/009_visits_checkin.sql` + `api/apply_migration_009.php` | `coiffure_visits` table |
| `migrations/010_add_gender.sql` + `api/apply_migration_010.php` | customer `gender` + `title` columns |
| `migrations/011_add_wifi.sql` + `api/apply_migration_011.php` | salon `wifi_ssid` + `wifi_password` columns |
| `migrations/012_loyalty_config.sql` + `api/apply_migration_012.php` | salon loyalty columns + `staff_pin` |
| `migrations/013_checkin_analytics.sql` + `api/apply_migration_013.php` | `coiffure_checkin_events`, `coiffure_settings_audit`, `coiffure_checkin_lockouts` |
| `migrations/014_salon_connections.sql` + `api/apply_migration_014.php` | `coiffure_salon_connections` (multi-store brands) |
| `migrations/015_global_settings.sql` + `api/apply_migration_015.php` | `coiffure_global_settings` (admin-editable kiosk timeouts) |
| `api/global-settings.php` | global timeout config read (public) / write (admin only) |
| `api/checkin.php` | candidates / confirm / phone / staff / event actions |
| `api/loyalty-config.php` + `api/loyalty_helpers.php` | loyalty config read/write + shared progress maths |
| `api/content.php` | Trends & Tipps content (reads `data/trends.json`) |
| `api/products.php` | Shop catalogue (reads `data/products.json`) |
| `data/trends.json`, `data/products.json` | Demo content & products |
| `index.html` | Home, check-in, browse screens + screen router + idle timer |
| `api/customer.php`, `api/mailer.php` | Registration + welcome e-mail (wallet removed) |

Removed: `api/WalletGenerator.php`, `api/wallet.php`, `wallet/`, and all
`WALLET_*` env keys.

## Database

The check-in feature needs the `coiffure_visits` table and relies on the
`birth_day` / `birth_month` columns from migration 008.

```bash
# Idempotent runners (safe to re-run, work on any MySQL)
php api/apply_migration_009.php   # coiffure_visits table
php api/apply_migration_010.php   # customer gender + title columns
php api/apply_migration_011.php   # salon guest-WiFi columns
php api/apply_migration_012.php   # per-salon loyalty config + staff PIN
php api/apply_migration_013.php   # checkin analytics / audit / lockout tables
php api/apply_migration_014.php   # salon connections (multi-store brands)
php api/apply_migration_015.php   # global settings (kiosk timeouts)

# …or raw SQL
mysql -u USER -p salonlyft < migrations/009_visits_checkin.sql
mysql -u USER -p salonlyft < migrations/010_add_gender.sql
mysql -u USER -p salonlyft < migrations/011_add_wifi.sql
```

`coiffure_visits`: `visit_id, customer_id, salon_id, checkin_method
(birthday|phone|manual), checked_in_at`, indexed on `customer_id` and
`checked_in_at` for analytics.

Migration 010 adds `gender ENUM('female','male','diverse')` and
`title VARCHAR(30)` to `coiffure_customers` (both optional).

## Manual installation steps

1. Deploy the updated files (no new Composer dependencies — the passes library
   is gone).
2. Run `php api/apply_migration_009.php`, `php api/apply_migration_010.php` and
   `php api/apply_migration_011.php` once (migration 008 must already be applied
   for the birthday columns).
3. Ensure `data/trends.json` and `data/products.json` are web-readable by PHP.
4. Configure `api/.env` as before (`APP_PUBLIC_URL`, `MAIL_*`, optional
   `SMTP_*`). No wallet configuration is required anymore.
5. Open the tablet on `index.html`; the home screen is the default idle state.

## Privacy policy note

The privacy policy should state that the birthday is used for identification
during self check-in, and that the stored phone number may be used as a
fallback identifier — both minimal, legitimate-interest processing.
