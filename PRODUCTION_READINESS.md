# Production Readiness Report

**Phase 18, 2026-10-31.** Release candidate: `main` (Phase 17 `3a962be` plus this documentation commit). For how to deploy, see [DEPLOYMENT.md](DEPLOYMENT.md).

## 1. Verdict

**Ready for staging deployment.** Production comes after the owner actions in §9.

- **What's done:** the code side is complete and passes 1,021 automated tests.
- **What's not:** production depends on things code can't prove — the server, HTTPS, credentials, the real Steadfast/GTM/GA4/Meta accounts, real devices, a legal review, and two pending dependency patches.
- Nothing has been deployed, and no external service has been verified.

Status labels used below:
- **Auto**: verified by automated tests.
- **Local**: verified on a local server or browser.
- **Staging**: needs staging verification.
- **Prod**: needs production verification.
- **Owner**: needs the owner's input.
- **N/A**: not applicable.

## 2. Production environment checklist

Fill these in on the server only. No real values belong in Git.

| Variable | Production value / guidance | Status |
|---|---|---|
| `APP_NAME` | Shop name (shown in titles and emails) | Owner |
| `APP_ENV` | `production` | Prod |
| `APP_DEBUG` | `false` (never `true` in production) | Prod |
| `APP_KEY` | `php artisan key:generate` **once** on the server; keep it in your secrets backup | Prod |
| `APP_URL` | `https://<your-domain>` | Owner |
| Timezone / currency | Fixed in code: `Asia/Dhaka` (`config/app.php`), `BDT` (`config/orders.php`) | Auto |
| `DB_CONNECTION` / `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `mysql`, with a **dedicated user** limited to this database (no SUPER, no FILE) | Owner |
| `SESSION_DRIVER` | `database` (default; shared across servers) | Auto |
| `SESSION_SECURE_COOKIE` | `true` | Prod |
| `SESSION_ENCRYPT` | `false` today. `true` adds protection but logs out existing sessions once (owner decision) | Owner |
| `SESSION_DOMAIN` | Empty unless subdomains must share the session | Owner |
| `CACHE_STORE` | `database` (default; shared, needed for throttling across servers) | Auto |
| `QUEUE_CONNECTION` | `database` (no queued work today) | N/A |
| `APP_MAINTENANCE_DRIVER` | `file` on one server; `cache` on several | Owner |
| `FILESYSTEM_DISK` | `local`; product images use the `public` disk | Auto |
| `LOG_CHANNEL` / `LOG_STACK` / `LOG_LEVEL` | `stack` / `daily` / `warning` (or `error`); `LOG_DAILY_DAYS` sets retention | Prod |
| `MAIL_*` | The app sends no email today; leave `MAIL_MAILER=log` | N/A |
| `TRUSTED_PROXIES` | Empty unless behind a TLS-terminating proxy or load balancer; then its IPs or CIDRs | Owner |
| `SECURITY_HSTS_MAX_AGE` | `0` until HTTPS covers the whole domain, then `31536000` | Prod |
| `ADMIN_NAME` / `ADMIN_EMAIL` / `ADMIN_PASSWORD` | For the one-time Super Admin seed. **Remove the password afterwards** | Owner |
| `STEADFAST_BASE_URL` | Default `https://portal.packzy.com/api/v1` (Steadfast's API endpoint) | Owner |
| `STEADFAST_API_KEY` / `STEADFAST_SECRET_KEY` | From the Steadfast merchant panel | Owner |
| `STEADFAST_WEBHOOK_TOKEN` | A long random string, also entered in Steadfast's webhook settings. The webhook refuses everything while it's empty | Owner |
| `STEADFAST_CONNECT_TIMEOUT` / `_TIMEOUT` / `_LOOKUP_RETRIES` | Defaults 5s / 20s / 2 are fine | Auto |
| `GTM_CONTAINER_ID` | The **production** container ID in production only. Empty means tracking is off and no banner shows | Owner |
| `STOREFRONT_CONSENT_VERSION` / `_LIFETIME_DAYS` | `1` / `180`; raise the version when the privacy notice changes | Owner |
| `PRIVACY_BUSINESS_NAME` / `_ADDRESS` / `PRIVACY_CONTACT` | The business details shown in the privacy notice | Owner |
| `MEDIA_IMAGE_MAX_KB` | Default 2048 (2 MB) | Auto |

`.env.example` documents every variable with placeholders only. In this phase I corrected its database section from SQLite to MySQL.

## 3. Database readiness

| Item | Finding | Status |
|---|---|---|
| Migrations | 16 migrations, all applied on dev, none pending; each has a `down()` method | Local |
| Engine / charset | InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`, strict mode | Auto |
| Foreign keys | Defined; orders/payments/stock movements use RESTRICT, so history can't be deleted | Auto (`SchemaConstraintsTest`) |
| Unique keys | `order_number`, `customers.phone`, product SKU and slug, a payment's gateway reference, courier invoice and consignment | Auto |
| CHECK constraints | Amounts ≥ 0, quantity ≥ 1, payment amount > 0, discount below price (needs MySQL ≥ 8.0.16) | Auto |
| Indexes | Cover order list, dashboard and report filters (status, source, payment status and customer, each with created_at; plus payments status and paid_at) | Auto (fixed query counts) |
| Transactions | Order creation, status changes and payments use transactions with row locks; courier calls run outside them | Auto |
| Connection limit | `max_connections` ≥ PHP-FPM workers × servers + 20 | Prod |
| Backups / restore | Procedure, frequency and retention in DEPLOYMENT.md §16 | Owner (set up and test a restore) |

## 4. Security verification checklist

| Control | Status |
|---|---|
| Login, logout and session regeneration; inactive accounts blocked; role checks | Auto |
| Login throttling: 5/min per email+IP and 20/min per IP; dummy hash for unknown users | Auto |
| Authorization on every admin route; Staff can't change anything; record-level access protection | Auto (route sweep) |
| CSRF on every browser write route; the only API route is the token-checked webhook | Auto |
| SQL injection and stored/reflected XSS | Auto |
| Upload validation and GD re-encoding | Auto |
| Security headers: nosniff, frame protection, `frame-ancestors`, Referrer- and Permissions-Policy | Auto + Local |
| HSTS, trusted proxies and `Secure` cookies behave correctly once configured | Auto (simulated) / **Prod** (real HTTPS) |
| `APP_DEBUG=false`, `expose_php=Off`, document root = `public/` | **Prod** |
| Consent defaults denied; Meta gated on Marketing consent; no search text or personal data in tracking | Auto / **Staging** (real GTM) |
| Dependency advisories | **Owner**: see §8 |

## 5. Production smoke test checklist

**Admin**

| Check | Status |
|---|---|
| Super Admin login and logout; wrong password refused; throttled after repeated failures | Auto / **Prod** |
| Manager can operate orders but not refund; Staff view-only | Auto / **Prod** (with real accounts) |
| Products (create with image, edit, stock adjustment), categories, brands | Auto / **Staging** |
| Customers (create, edit, block/unblock) | Auto / **Staging** |
| Orders: create, confirm, process, ship, deliver, cancel, return | Auto / **Staging** |
| Payments: partial, full, failed attempt, refund (Admin/Super Admin) | Auto / **Staging** |
| Dashboard and all five report tabs load; totals match the order list | Auto (reconciled) / **Staging** |

**Storefront**

| Check | Status |
|---|---|
| Home, category, product, search, cart, checkout, confirmation | Auto / **Prod** |
| Prices and totals calculated on the server; tampered input ignored | Auto |
| Order confirmation not repeated on refresh; duplicate submit gives the same order | Auto |
| Mobile layout of checkout and the consent banner | Local (headless Chrome, 390px) / **Staging** (real devices) |

**Business workflows**

| Check | Status |
|---|---|
| Order → stock deducted → cancel/return restores stock once | Auto (lifecycle test) / **Staging** |
| Courier booking (real Steadfast), duplicate protection | Auto (faked HTTP) / **Staging** |
| Delivery status by webhook and by manual sync | Auto (faked) / **Staging** |
| Report reconciliation against raw records | Auto |

**Security**

| Check | Status |
|---|---|
| HTTPS redirect, `Secure` cookies, HSTS | **Prod** |
| Security headers present | Auto / **Prod** |
| Unauthorized admin access refused | Auto / **Prod** |
| Webhook rejects a missing or wrong token | Auto / **Staging** |
| Generic error page, no stack traces | **Prod** |

**Tracking**

| Check | Status |
|---|---|
| Consent defaults denied before `gtm.js`; banner works | Auto / Local / **Staging** |
| GA4 events once each, one purchase per order (DebugView) | **Staging** |
| Meta blocked without Marketing consent and on search pages | Auto (contract) / **Staging** |
| No personal data in tracking events | Auto / **Staging** |

## 6. Mobile and browser readiness

- **Verified so far:** layouts were checked only in headless Chrome at a simulated 390px width (consent banner, checkout, report tabs), which found and fixed the banner covering checkout. **No real-device testing has been done.**
- **Manual tests needed**, in priority order:
  1. Checkout with the consent banner;
  2. The consent settings page;
  3. Admin order workflow (status, payment, refund forms);
  4. Report tables (horizontal scrolling).
- **Browsers:** Android Chrome, iOS Safari, desktop Chrome, Firefox and Edge.
- **Known:** wide report tables scroll horizontally on phones, by design.

## 7. External integrations and owner credentials

| Integration | Owner provides | Needs real verification |
|---|---|---|
| Steadfast | API key, secret key, a webhook token (generated by you), and the webhook URL registered in Steadfast as `https://<domain>/api/webhooks/steadfast` | Yes: sandbox or staging booking, webhook delivery, status sync |
| GTM | Separate **staging** and **production** container IDs | Yes: Preview / Tag Assistant |
| GA4 | Property and web stream; Measurement ID entered in GTM only (never in `.env`) | Yes: DebugView / Realtime |
| Meta Pixel | Pixel ID entered in GTM only; Advanced Matching and Automatic Events off | Yes: Events Manager test events |
| Consent and privacy | Business details; legally reviewed Bengali and English notice | Legal review |

The full GTM, GA4 and Meta setup steps are in the Phase 13–14 reports. No real bookings or tracking events have been sent.

## 8. Dependency advisories (unchanged; upgrades need approval)

| Package (installed) | Advisory | Fixed in | Production? | Risk if left / exploitable here? | Recommendation |
|---|---|---|---|---|---|
| `livewire/livewire` 4.4.7 | GHSA-mhf7-xp6r-6qrc, authorization bypass in `#[Authorize]` / `#[Json]` | 4.4.8 | Yes | Not exploitable: neither attribute is used | `composer update livewire/livewire`, then run the full suite |
| `symfony/mime` 8.1.7 | CVE-2026-45070, email header injection | 8.1.10 | Yes | Not exploitable: the app sends no email | `composer update symfony/mime`, then run the full suite |
| `concurrently` → `shell-quote` | Command injection (critical) | Available via `npm audit fix` | **No** (dev only) | Development machines and CI only | `npm audit fix`, then `npm run build` |
| `vite` → `launch-editor` | Command/argument injection (high) | Available via `npm audit fix` | **No** (dev only) | Only affects the dev server; production serves built files | Same as above |

Patch both production packages **before** go-live, even though neither advisory is exploitable today.

## 9. Owner actions before production

1. **Hosting:** choose it; provision the server, domain and TLS; confirm the web server.
2. **Database:** create the production database and a dedicated user; provide the credentials on the server only.
3. **Production `.env`:** fill it in per §2; run `key:generate` once; seed the Super Admin, then remove `ADMIN_PASSWORD`.
4. **Approve the dependency patches** (§8) so they can be applied and tested before go-live.
5. **Steadfast:** provide the credentials, register the webhook URL and token, and run a sandbox or staging booking.
6. **Tracking:** create the staging and production GTM containers, the GA4 property and the Meta Pixel; do the staging verification.
7. **Legal review** of consent and the privacy notice (Bangladesh PDPA 2026); fill in the business details.
8. **Backups:** set up database and image backups, plus an off-server copy; **test one restore**.
9. **Monitoring:** set up uptime, log and disk monitoring.
10. **Decide:**
    - `SESSION_ENCRYPT`;
    - HSTS timing;
    - whether search engines should skip cart, checkout and confirmation pages in `robots.txt` (admin and login are already marked `noindex`);
    - optional admin 2FA and a full CSP (Phase 16 proposals).
11. **Run the smoke tests** (§5) on staging, then on production after go-live.

## 10. Known limitations

- **Purchases are tracked by the browser only.** If the browser is blocked or closes early, the purchase event is lost; admin order records are the source of truth.
- **No server-side Conversions API, no admin 2FA, no full script-blocking CSP, no static analysis.**
- **No load testing.** Concurrency is verified with database locks in tests, not under real parallel traffic.
- **Product images are stored on the local disk.** A multi-server setup needs shared storage or S3 **[hosting decision]**.
