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

Birthday-first identification with a phone fallback:

1. **Birthday** – large Tag (1–31) and Monat (Januar–Dezember) pickers; the
   **Weiter** button is disabled until both are chosen.
2. **Name selection** – `GET api/checkin.php?action=candidates&day=DD&month=MM[&q=…]`
   returns members with that birthday
   (`{id, first_name, last_name_initial, gender}`). Each card shows a
   gender silhouette avatar so the customer spots their own profile at a glance.
   - 0 results → "Kein Eintrag gefunden" + phone-fallback button.
   - 1 result → "Sind Sie … ?" confirmation (with avatar).
   - 2+ results → touchable cards + a **name filter** (`q`) that re-queries the
     backend on first- OR last-name prefix. This disambiguates people who share
     a birthday, first name AND last initial without ever sending the full list
     of surnames to the client.
3. **Confirm** – `POST api/checkin.php {"action":"confirm","customer_id":N}`
   logs a visit and returns the first name for the welcome animation.
   The success screen auto-returns home after 3 s.
4. **Phone fallback** – numeric keypad → `POST api/checkin.php`
   `{"action":"phone","phone_number":"…"}`. Matches on trailing digits so
   stored formats (spaces, +, /, -, ., parens) still resolve. Only customers
   who previously stored a number can be found this way.

**GDPR:** candidate data is minimal (first name + last initial) and only
returned after a birthday is entered — a legitimate-interest identification
step. Membership/marketing flags are NOT required to check in.

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
| `api/checkin.php` | candidates / confirm / phone check-in actions |
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
