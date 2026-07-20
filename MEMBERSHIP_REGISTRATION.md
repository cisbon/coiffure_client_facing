# Membership Registration & Digital Wallet Card

This document describes the membership-focused tablet registration refactor:
a "join the club" registration flow that issues a digital loyalty card
(Apple Wallet `.pkpass` / Google Wallet) and a branded welcome e-mail.

## Overview of the flow

1. Customer fills the single-page form on the in-salon tablet (`index.html`,
   Customer Onboarding tab).
2. `POST api/customer.php` validates + stores the record (GDPR-conditional
   address), and — if **Mitglied werden** was checked — mints a unique member
   ID and generates the wallet passes.
3. The tablet shows a large **QR code** linking to `GET /wallet/{memberId}`
   plus the social / WiFi screen.
4. A branded welcome e-mail is sent (membership variant includes the card +
   "Add to Wallet" button + QR).
5. On a phone, `api/wallet.php` serves the `.pkpass` (iOS) or redirects to the
   Google Wallet save link (Android); desktop gets a landing page with both.

## Files changed / added

| File | Purpose |
|------|---------|
| `migrations/008_membership_registration.sql` | Schema migration (raw SQL) |
| `api/apply_migration_008.php` | Idempotent PHP migration runner |
| `mysql_schema.sql` | Fresh-install schema updated with new columns + `coiffure_employees` |
| `api/customer.php` | Rewritten form handler (new fields, membership, wallet, e-mail) |
| `api/WalletGenerator.php` | `createApplePass()` / `createGooglePassLink()` / `getWalletUrl()` |
| `api/wallet.php` | `GET /wallet/{memberId}` delivery endpoint (UA-aware) |
| `api/mailer.php` | Branded HTML welcome / membership e-mail (mail() or SMTP) |
| `api/employees.php` | Stylist list for the "Wunsch-Stylist" dropdown |
| `index.html` | New membership form + post-submission wallet/social screen |
| `api/.env.example` | New `APP_PUBLIC_URL`, `MAIL_*`, `SMTP_*`, `WALLET_*` keys |
| `wallet/passes/` | Generated `.pkpass` files (git-ignored) |

## Database migration

Apply **one** of these:

```bash
# Option A — raw SQL (MySQL 8.0.29+ for "ADD COLUMN IF NOT EXISTS")
mysql -u USER -p salonlyft < migrations/008_membership_registration.sql

# Option B — idempotent PHP runner (works on any MySQL, safe to re-run)
php api/apply_migration_008.php
```

New `coiffure_customers` columns: `first_name`, `last_name`, `mobile`,
`birth_day/month/year`, `zip`, `city`, `address_street/zip/city`,
`consent_postal`, `consent_email_marketing`, `consent_sms_whatsapp`,
`is_member`, `member_id` (unique), `member_since`, `referral_source`,
`preferred_stylist_id`. `phone` becomes nullable.

New table `coiffure_employees` (id, salon_id, full_name, title,
display_order, is_active) drives the stylist dropdown.

## GDPR notes

- The postal address (`address_street/zip/city`) is **only** stored when the
  postal-consent checkbox is ticked; otherwise those fields are discarded
  server-side before the INSERT/UPDATE.
- All consents are unticked by default and logged with a timestamp +
  `policy_version_accepted`. Consent is recorded per channel
  (data processing / e-mail / SMS-WhatsApp / postal).
- Referral logic ("Freund werben") is displayed as a benefit only — not yet
  implemented.

## Configuration (`api/.env`)

```
APP_PUBLIC_URL=https://clouedo.com/coiffure     # builds wallet + logo links
MAIL_FROM=noreply@yourdomain.com                # welcome e-mail sender
# SMTP_HOST=...                                 # optional; else PHP mail()

# Apple Wallet signing (optional — unsigned dev pass generated without these)
WALLET_APPLE_CERT_PATH=/path/to/passtypeid.p12
WALLET_APPLE_CERT_PASSWORD=secret
WALLET_APPLE_WWDR_PATH=/path/to/AppleWWDRCA.pem
WALLET_APPLE_PASS_TYPE_ID=pass.your.identifier
WALLET_APPLE_TEAM_ID=ABCDE12345

# Google Wallet (optional — Google button hidden without these)
WALLET_GOOGLE_ISSUER_ID=3388000000012345678
WALLET_GOOGLE_CLASS_ID=3388000000012345678.coiffure_loyalty
WALLET_GOOGLE_SERVICE_ACCOUNT_JSON=/path/to/service-account.json
```

### Development vs. production wallet passes

- **Without certificates**, `WalletGenerator::createApplePass()` writes an
  **unsigned** `.pkpass` so the whole flow works locally. Real iPhones require
  a signed pass — set the `WALLET_APPLE_*` keys with a Pass Type ID
  certificate + the Apple WWDR intermediate to enable PKCS#7 signing.
- **Google Wallet** requires a service-account JSON and a pre-created Loyalty
  Class. When unset, `createGooglePassLink()` returns `null` and the endpoints
  gracefully omit the Google option.

## Wallet endpoint URL

By default the QR/e-mail point at a directly-working endpoint:

```
https://clouedo.com/coiffure/api/wallet.php?member_id=M25-XXXXXX
```

To use a pretty `/{slug}/wallet/{id}` URL, enable `mod_rewrite`
(see `wallet/.htaccess`) and set `WALLET_BASE_URL` to your site root.
