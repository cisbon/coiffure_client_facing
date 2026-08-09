# Tablet App – Check-in, Registration & Digital Magazine

The in-salon tablet is a self-service kiosk. All wallet/pass features have been
removed — membership is now a status only, no downloadable card.

## Navigation & idle screen

- A sticky **left navigation rail** is always visible (icon rail, ~76px). A
  **burger** at the top expands it to show labels. Top→bottom the items are:
  **Stöbern**, **Check-In**, **Registrieren**, **Social & WLAN**, **AI Hairstyle**,
  **AI Eyebrows**.
- **Stöbern** (the digital magazine & shop) is the **start / idle screen**.
- The **Social & WLAN** label reads just **Social** unless the salon configured
  a guest-WiFi name AND password in the admin dashboard.
- **AI Hairstyle** opens the full-screen KI consultation overlay. Taking a photo
  shows the camera **fullscreen** (iOS-camera style: shutter/cancel hover at the
  bottom, a countdown badge top-left) and **auto-captures** after
  `timeout_autophoto_s` seconds (default 5, in `coiffure_global_settings`).
  The captured/uploaded photo is shown fullscreen with **Retake Photo** /
  **Continue**; the whole experience fits on one screen (no page scrolling). On
  the result the customer can **change only the hair colour** to re-run the same
  style in another colour.
- **AI Eyebrows** is the same overlay and the same four-step flow
  (photo → style → generating → result) with an eyebrow vocabulary: quick looks
  (Natural, Defined, Bold, Soft, Glam, Laminated), free-text **Describe**,
  **Shapes** (women's / men's brow shapes) and a **Custom** builder
  (thickness · shape · arch height · colour). The result screen offers
  **change only the brow colour**.

### AI image allowance

Generated images cost money, so every salon is metered. The rules live in one
pure function (`aiUsageEvaluate()` in `api/ai_usage_helpers.php`) and are
enforced server-side in `api/ai-consultation.php` **before** anything is sent
to OpenRouter — the tablet's own check is only there to explain the situation
early.

| Regime | Allowance | Past the limit |
|--------|-----------|----------------|
| Trial (`coiffure_salons.status = 'trial'`) | `ai_trial_image_limit` images for the whole trial (default 100) | Feature off. A trial is never billed. |
| Subscription | `ai_monthly_image_limit` images per calendar month (default 500) | The owner's `ai_overage_allowed` decides: stop, or keep generating at `ai_overage_price` per image — up to `ai_overage_monthly_cap` of extras per month. |

A limit of `0` means unlimited. `ai_feature_enabled` is the master switch, and
a suspended or deactivated salon is blocked regardless.

`ai_overage_monthly_cap` is the salon owner's ceiling on additional cost
(`0.00` = no cap). It is checked against what the *next* image would cost, not
what has already been spent, so it is a hard ceiling: a 20,00 € budget produces
at most 20,00 € of extras, never 20,01 €. Once it is reached the stylists switch
off for the rest of the month with the `overage_cap_reached` reason — kept
distinct from `monthly_limit_reached` because only one of the two is fixed by
raising the budget.

Only images that were actually delivered are counted — a failed generation
costs the salon nothing. Each one is booked as a row in
`coiffure_ai_image_usage` with the period and the price that applied at that
moment, so re-pricing a salon never rewrites past invoices.

When the allowance is gone the stylist popup shows one explanatory screen
(`<prefix>-step-blocked`) instead of the photo step, with copy per block reason
under `ai_limit.*` in the language files. The same screen appears if the limit
is hit mid-flow: `api/ai-consultation.php` answers `403` with
`code: "ai_limit_reached"` and the engine renders the reason rather than a
technical error.

Consumption and the overage choice live in the dashboard under
**Einstellungen → KI-Stilberatung**, with a summary card on the Übersicht. See
ADMIN_DASHBOARD.md.

Two rules keep this from taking the stylists down with it, both learned the hard
way:

- **`setCorsHeaders()` runs before the metering `require`s** in
  `api/ai-consultation.php`. The tablet is on `coiffureai.com` and the API on
  `clouedo.com`, so every call is cross-origin. A failure *above* that line —
  a helper file that did not make it onto the server, a syntax error — returns a
  500 with no CORS headers, which the browser cannot show to the page at all:
  `fetch()` simply rejects and Safari reports `Load failed`, with nothing in the
  response to explain it. The preflight fails the same way, so the POST is never
  even sent. Any *new* helper an endpoint requires belongs below the CORS call.
- **The metering helpers are optional at runtime.** `ai_usage_helpers.php` is
  pulled in with a guarded `include_once`; if it is absent the endpoint logs it
  and generates unmetered. Counting images is a business feature, generating
  them is the product.

The request deliberately carries no `Authorization` header: it would change the
CORS preflight, and some hosts strip or reject it. The tablet's session token
travels in the JSON body instead, which is what lets the server bill the salon
the tablet belongs to rather than trusting the posted `salon_id`.

### When an AI stylist shows an error

Every failure now names itself. Under the message on the result screen there is
a small grey technical line — that line is the diagnosis, and it is what to read
out or screenshot when reporting a problem.

| Message | Detail line | What it means |
|---|---|---|
| Die Anfrage … konnte nicht abgeschlossen werden | `TypeError: Failed to fetch · <url>` | The request never reached the API. Almost always CORS or DNS, not the salon's internet: check that `ALLOWED_ORIGINS` in `api/.env` contains the tablet's origin (`https://coiffureai.com`), and that `OPTIONS <url>` answers 200 with `Access-Control-Allow-Origin`. |
| Der Styling-Dienst hat zu lange nicht geantwortet | `AbortError after 90s · <url>` | The API accepted the request but never answered — usually OpenRouter or the host's execution limit. |
| Der Styling-Dienst antwortet nicht korrekt | `HTTP 500 · <first line of the body>` | The API answered with something that is not JSON: a PHP error page, a gateway error. The detail carries the beginning of the real body. |
| *(the API's own text)* | `HTTP 500` | The API answered properly and reported why — an OpenRouter refusal, a missing key, a model that cannot generate images. |
| Frisur konnte nicht generiert werden | `HTTP 200` | A success response arrived with no image in it. |

Checking CORS by hand:

```bash
curl -i -X OPTIONS https://clouedo.com/coiffure/api/ai-consultation.php \
  -H 'Origin: https://coiffureai.com' -H 'Access-Control-Request-Method: POST'
```

A `200` **with** an `Access-Control-Allow-Origin` line is healthy. The header
missing means `ALLOWED_ORIGINS` does not list the tablet's origin — the browser
then blocks every response from this API, and the page can only report that the
request did not complete.

### Adding or adapting an AI stylist

Both stylists run on one engine — `createAIStylist()` in `index.html`
(*AI STYLIST ENGINE* section). Nothing about the flow is duplicated; a stylist
is just a config entry plus markup that follows the id contract:

1. **`AI_STYLIST_CONFIGS`** (`index.html`) — add an entry with the DOM id
   `prefix`, its `consultationType`, the `modes` it offers, its `styles` map
   (style key → prompt text), `requiredCustomOptions`, and the two prompt
   builders (`buildCustomPrompt`, `buildColorPrompt`). Changing the wording the
   AI receives — for either stylist — happens here and nowhere else.
2. **Markup** — copy the `#brow-popup` block and rename `brow-` to your prefix.
   The engine expects `<prefix>-step-photo`, `-step-style`, `-step-generating`,
   `-step-results`, `-camera-*`, `-progress-step-1..3`, `-mode-<mode>`,
   `-<mode>-mode`, … (the full list is documented above the engine).
3. **Nav rail + router** — add a `nav-item` with `data-screen="<name>"` and one
   line in `showScreen()`.
4. **Server prompt** — add an entry to `CONSULTATION_PROMPTS` in
   `api/ai-consultation.php`, keyed by the config's `consultationType`. It
   decides what the model is told to keep unchanged (hair only, brows only, …).
5. **Translations** — add the stylist's namespace to `lang/de.json` **and**
   `lang/en.json`; the generic photo/camera strings can be reused from `kiosk.*`.

Inline `onclick` handlers are generated from the prefix
(`browOpenCamera()` → `kioskOpenCamera()` → …), so no per-stylist glue code is
needed beyond `open<X>Popup()` / `close<X>Popup()`.

**Session robustness:** salon tablets get a long-lived (30-day) session with
sliding renewal (`validateSession` extends it on use), so a kiosk used at least
every few weeks never gets logged out. The login page forwards an
already-authenticated tablet straight to the app from cached data (Back button
never strands it on the login form), branding is cached in `localStorage` and
re-applied instantly on reload, and returning from standby (`visibilitychange`)
refreshes salon data/branding without a re-login.
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
staff-search, auto-checkout) is stored in `coiffure_global_settings` (seconds)
and editable by an **admin** in the dashboard ("Globale Einstellungen"). The
kiosk loads them from `GET api/global-settings.php` at startup; code defaults
apply if the table is absent.

**Checked-in customer & check-out (staff-facing):** after a check-in the
nav-rail shows the current customer — initials (collapsed) or first name + last
initial (expanded), the **position in the current loyalty cycle**
`visits_in_cycle/threshold` (never the raw lifetime total — e.g. an 8th visit at
threshold 5 reads `3/5`, expanded `3/5 Besuche`), and the reward amount in green
(expanded `10 € Rabatt`) **only when this visit earned a discount** — either a
loyalty reward (every Nth visit) or the **yearly birthday reward**. The birthday
wishes + discount are shown only on the **first visit within the birthday window
per year** (`is_birthday_reward`, computed from `coiffure_visits`); the customer
never sees their lifetime visit count. A **Check-Out** button sits
below. Tapping the name opens a popup with the same welcome screen the customer
saw (progress bar + reward/birthday message). The customer is checked out when
(a) the Check-Out button is pressed, (b) the auto-checkout timeout elapses
(`timeout_autocheckout_s`, default 1800 s), or (c) another customer checks in.
The state survives an accidental tablet reload (localStorage, within the
auto-checkout window). The welcome payload carries `initials` / `last_name_initial`
for this display.

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

## 3. Trends slider ("Stöbern")

The start/idle screen keeps the promo **home-hero** and, below it, a
**full-screen auto-advancing image slider** backed by `coiffure_trends`
(`GET api/trends.php`, active rows ordered by `sort`). Each slide shows a
background image (`image_url`; a bare filename resolves under
`https://<site>/coiffure/images/`), a **left-aligned** title + body text, and —
when a `link` is set — a button that opens it in a **new browser tab**. It
auto-advances every `timeout_autoslide_trends_s` seconds (default 3,
`coiffure_global_settings`, admin-editable), **loops seamlessly** (cloned
first/last slides), and can be advanced/rewound by **horizontal swipe**. The
home screen locks vertical page scroll (`body.home-nolscroll`) — only the
slider scrolls, horizontally. The old Trends/Tipps/Shop tab switcher was removed.

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
| `index.html` | Home, check-in, browse screens + screen router + idle timer + the AI stylists |
| `migrations/027_ai_usage_limits.sql` + `api/apply_migration_027.php` | `coiffure_ai_image_usage` ledger + salon AI quota columns |
| `migrations/028_ai_overage_cap.sql` + `api/apply_migration_028.php` | `ai_overage_monthly_cap` (owner's ceiling on additional cost) |
| `api/ai_usage_helpers.php` | AI quota rules, usage accounting and overage pricing |
| `api/ai-usage.php` | consumption read (public for the tablet, full for the dashboard) + quota settings |
| `api/ai-consultation.php` | AI image generation, quota-checked and metered |
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
php api/apply_migration_027.php   # AI image quotas + usage ledger
php api/apply_migration_028.php   # monthly spend cap for AI overage

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
