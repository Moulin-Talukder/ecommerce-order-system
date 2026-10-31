# Phase 17: Testing and Quality Assurance Report

**Date:** 2026-10-31
**Branch:** `main`, starting at `cc5420c` (Phase 16), clean and matching `origin/main`.

This report covers automated tests run locally against the MySQL test database (`ecommerce_order_system_test`). It does **not** replace staging verification of external services, HTTPS or browsers, or a professional penetration test. See the last two sections.

## 1. Baseline and final results

| | Before | After |
|---|---|---|
| Tests | 1,001 | **1,021** |
| Assertions | 4,100 | **5,015** |
| Failures / errors | 0 / 0 | **0 / 0** |
| Pint (`vendor/bin/pint --dirty --format agent`) | passed | **passed** |
| `npm run build` | succeeded | **succeeded** |

There were no pre-existing failures. No existing test was removed, skipped or weakened.

## 2. Modules inspected

| Area | Existing test files (tests) |
|---|---|
| Auth, access, security | AuthenticationTest (10), AdminAccessTest (10), SuperAdminSeederTest (5), SecurityHardeningTest (12, plus data sets) |
| Catalog | ProductManagementTest (36), ProductImageTest (19), ProductStockTest (26), CategoryManagementTest (34), BrandManagementTest (24), ImageUploaderTest (14), CategoryTreeTest (15) |
| Customers | CustomerManagementTest (36) |
| Orders and payments | CreateOrderTest (24), OrderStatusTest (14), OrderPaymentTest (13), OrderManagementTest (26), OrderOperationsTest (25), OrderTimelineTest (4), OrderCalculatorTest (8) |
| Courier (Steadfast) | SteadfastGatewayTest (13), BookShipmentTest (18), SyncShipmentTest (8), SteadfastWebhookTest (15), OrderCourierUiTest (12) |
| Dashboard and reports | DashboardMetricsTest (11), DashboardPageTest (16), DashboardPeriodTest (7), ReportsTest (24) |
| Storefront | CatalogTest (17), CartTest (20), CheckoutTest (23), ConfirmationTest (6) |
| Tracking and consent | GtmInstallationTest, EcommerceEventsTest (25), Ga4ReadinessTest, ConsentTest (18), SearchPrivacyTest (14), MetaPixelReadinessTest |
| Database | SchemaConstraintsTest (26), RelationshipsTest (11), ModelBehaviorTest (17) |

Counts are test methods; data providers add more cases. Static analysis: none installed (no PHPStan, Larastan or Psalm). Adding one needs approval.

## 3. Coverage assessment

The existing tests were strong per module:
- **Orders:** server-side totals, rollback of a failed order, oversell races under row locks, collisions, events after commit.
- **Courier:** credentials, network failures, uncertain bookings, duplicate prevention, webhook authentication.
- **Elsewhere:** payment overpay and over-refund limits, consent, tracking privacy, security headers.

**Gaps found:**
1. No **end-to-end lifecycle** test checking cross-module invariants (stock, money, ledger) after every step through the real HTTP endpoints.
2. No test **reconciling reports and the dashboard with the raw records** on a mixed, multi-period dataset. Earlier tests used small, hand-built cases.
3. No **sweep over the whole route table**. Authorization was tested per module, so a newly added route could miss its protection unnoticed.
4. **Stored XSS** was tested only for product pages and image alt text. **SQL injection** was tested only for LIKE wildcards in one search.

## 4. Tests added (`tests/Feature/QA/`, 20 tests, 915 assertions)

| File | What it proves |
|---|---|
| `OrderLifecycleConsistencyTest` (3) | **Full lifecycle:** checkout → confirm → process → ship → deliver → partial, failed and full payments → refund → return. After every step: stock = start + Σ movements; subtotal = Σ lines; total = subtotal − discount + delivery; `amount_paid` = payments − refunds; `payment_status` follows the ledger. Rejected actions (overpay, over-refund, illegal transitions, leaving a final status, paying a cancelled order) change nothing, and stock is never restored twice. **Last units:** two buyers compete for them and the second is refused, with no oversell. |
| `ReportReconciliationTest` (4 periods) | **Dataset:** a fixed seed generating 60 orders over 75 days (every status, source and method, discounted lines, payments, refunds and failed or pending attempts). **Checked against independent PHP sums:** every Sales, Orders, Products, Payments (collections and balances) and Customers figure, plus the dashboard, including across a month boundary. |
| `RouteProtectionSweepTest` (5) | **Middleware:** every admin route (47) has `web`, `auth` and `admin`. **Guests** are redirected to login from all of them. **Staff** get 403 on every admin write route, and no table changes. **CSRF stack:** every browser write route uses `web`. The only API route is the token-authenticated webhook. |
| `StoredXssTest` (2) | Script and attribute payloads saved in category, brand, product, customer, order, note and address fields render escaped on 13 admin pages (including reports) and 4 storefront pages. Reflected search and filter input is escaped. Confirmed by mutation: an unescaped product heading fails the test. |
| `SqlInjectionTest` (6 payloads) | Tautology, UNION, stacked DROP, time-based `SLEEP` and comment payloads in every admin search, the report filters, the dashboard range and storefront search. Each request returns 200, raises no SQL error, leaks no hashes, finishes in under 2.5s, matches nothing it shouldn't, and leaves all tables intact. |

## 5. Bugs found

**None.** All new tests passed once their test-side assumptions were corrected:
- `status()` clashes with a final PHPUnit method;
- cancel and return need a reason;
- order and payment rule violations show as error flashes, not validation errors;
- order URLs use the order number;
- the random seed needed guaranteed recent orders.

No application code changed in this phase.

**Observation (Info, from Phase 16):** brand and category status updates validate input before checking authorization. The sweep confirms Staff still get 403 with a valid payload and nothing changes.

## 6. Security regression (Phase 16)

These still pass:
- the headers (nosniff, frame protection, `frame-ancestors`, Referrer- and Permissions-Policy);
- HSTS on and off;
- trusted proxies;
- the per-IP login throttle;
- the dummy hash for unknown users;
- session regeneration and invalidation;
- `HttpOnly` / `SameSite` cookies;
- webhook authentication;
- upload re-encoding;
- authorization and access to other records' data.

New in this phase: route-wide authorization, stored and reflected XSS, and SQL injection.

## 7. Performance and database observations

- **Fixed queries:** dashboard and reports use a fixed number of queries per page. Tests compare 2 and 20 seeded orders and assert equal query counts. Lazy loading throws outside production, which catches N+1 queries in tests.
- **Indexes:** report and dashboard filters use the existing indexes: `orders(status|source|payment_status|customer_id, created_at)`, `orders(created_at)` and `payments(status, paid_at)`. None were added.
- **Constraints:** foreign keys, unique keys and CHECK constraints (amounts ≥ 0, quantity ≥ 1, payment amount > 0) are covered by `SchemaConstraintsTest`.
- **Transactions:** order creation, status changes and payments run in transactions with row locks. Courier calls run outside transactions, and that's tested.
- **Not measured:** query timings on production-sized data (100k+ orders). Report ranges are capped at 92 days, with no caching.

## 8. Remaining risks and limitations

- **Unpatched dependencies (from Phase 16, needs your approval):**
  - `livewire/livewire` 4.4.7 and `symfony/mime` 8.1.7 have advisories, not exploitable in the current code;
  - dev-only npm packages `concurrently` and `vite` have advisories.
- **No static analysis tool,** and no full script-blocking CSP.
- **Concurrency is tested at the database-lock level within one process.** Behaviour across real parallel web workers is not load-tested.
- **Courier tests use HTTP fakes.** No real Steadfast requests were made.
- **No real browser JavaScript testing** (Livewire interactions, GTM): the server output is tested, but not real browser execution.

## 9. Manual staging verification still required (not automated)

1. **GTM Preview / Tag Assistant:** consent defaults denied before `gtm.js`, and tags blocked until consent.
2. **GA4 DebugView:** page views and the six ecommerce events once each; one purchase per order; no `q`, email, phone or address.
3. **Meta Events Manager** (after GTM setup): nothing before Marketing consent, nothing on search or personal-data URLs, and `Purchase` with `eventID` = the order number.
4. **Steadfast sandbox or staging:** a real booking, webhook delivery with the token, and status sync.
5. **HTTPS:** `TRUSTED_PROXIES`, `SESSION_SECURE_COOKIE=true`, `Secure` cookies, HSTS (only once HTTPS covers the domain), and `APP_DEBUG=false`.
6. **Real phones:** checkout with the consent banner, and the reports tables.
7. **Shared cache** for login and checkout throttling across all app servers.
8. **Legal review** of consent and the privacy notice (Bangladesh PDPA 2026).

**Phase 17 is not fully verified** until these staging checks are done.
