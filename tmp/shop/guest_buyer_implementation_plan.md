# Customer Zone & Guest Storefront — Phased Implementation Plan

> **Status: PLAN ONLY — no repository file has been modified.** Prepared
> 2026-10-21 from the uploaded snapshot (`altaslive.zip`). Every `file:line`
> reference cites that snapshot; if the code has drifted, re-locate by the
> symbol name given beside the line number.
>
> Companion documents: `buyer_workflow.md` (the member journey, derived from
> source) and `guest_buyer_workflow.md` (the guest journey, a proposed spec).
> This plan turns both into an ordered build and adds the customer-zone
> decisions (D16–D22) that the guest spec does not yet contain.

---

## 1. What is being built

Two linked changes, delivered as one dependency-ordered roadmap:

- **A. Customer zone.** Free members — *customers*, `users.status = 'pending'`
  — stop using the backend dashboard. They live in a **storefront zone**: shop,
  cart, checkout, orders, invoices, profile and addresses, and activation. The
  dashboard becomes the home of **partners** (everyone who has activated) and
  admins.
- **B. Guest storefront.** An anonymous visitor browses the same shop, fills a
  cookie-based cart, and checkout creates a customer account **atomically with
  the order**. The cart bridges into either way of joining (guest checkout, or
  normal registration with a package).

**Why.** Many visitors want products, not the network. Letting them shop first
and activate at their own pace engages prospective package buyers without
asking for commitment up front.

**Unchanged by design:** Strategy C (shop orders earn zero compensation), the
14-status order state machine, `core/Commission.php`, `ShopOrder::createFromCart()`,
the admin area, and the member journey for partners.

**Non-goals.** A commission-bearing affiliate programme (D17 — "affiliate" is
branding only); SMS/OTP verification and login by mobile; the deferred-password
alternative; any change to compensation rules.

---

## 2. How to read and execute this plan

- **Phases run in order, P0 → P11.** Each phase *builds on* the previous one and
  lists exactly what it depends on.
- **Each phase is self-contained.** Its *Context you need* section restates the
  facts, anchors and decisions required to do the work, so it can be handed to
  an engineer (or an AI agent) without the rest of the document. Repetition
  between phases is deliberate.
- **Each phase ends in a shippable state.** All new behaviour sits behind four
  settings that default to OFF (§5). Deploying any phase with the flags off must
  leave every page byte-identical to the previous phase; the Phase 0 snapshot
  tool proves it. Production behaviour changes only at the three **go-live
  gates** (A after P4, B after P6, C after P9).
- **What travels with a phase.** Hand an engineer the phase plus §7 (engineering
  rules R1–R14) and §8 (verification toolkit) — phases cite them by number — and,
  where a phase names one, Appendix A, B, F or G. Everything else a phase needs is
  restated inside it.
- **Every phase uses the same template:** Goal · Depends on · Context you need ·
  Scope · Tasks · Tests · Acceptance criteria · Rollout and rollback · Hand-off.
- **Markers.** *(new)* = to be created; names are placeholders you may rename
  consistently. *(existing)* = verified in the snapshot.
- **Sizing** is relative (S/M/L), not a time estimate.
- **Process per phase:** one branch and one PR (`cz-p<N>-<slug>`), tagged
  `cz-p<N>` on merge. Take a DB backup before P1 and P7. Paste into the PR: the
  `php -l` output, `php tests/run.php`, `php tests/predeploy.php`, and the
  snapshot diff.
- **Global definition of done** (applies to every phase, not repeated below):
  code reviewed; every changed PHP file passes `php -l`; `php tests/run.php`
  green; `php tests/predeploy.php` green; flag-off snapshot diff empty;
  Appendix C updated if a decision moved.

---

## 3. Glossary

| Term | Meaning |
|---|---|
| **Customer** | A signed-in member with `status = 'pending'` (never activated). Zone ON: shops and manages orders in the storefront zone only |
| **Partner** | Any other signed-in user: activated members (whatever their later status or cap state) and admins/superadmins. Uses the dashboard |
| **Visitor / guest** | Not signed in. Has no account until checkout |
| **Storefront zone** | The customer-facing pages, served by `index.php?page=…` in a no-sidebar shell. *Not* the standalone landing file `frontend/index.php`, which has no sessions |
| **Shell** | The page chrome around a template: `dashboard` (sidebar + topbar, today's) or `store` (storefront topbar, no sidebar) |
| **Context** | Who a shop template renders for: `member` (signed in) or `guest` (cookie cart) |
| **Tier-1 / Tier-2 gate** | Tier 1 = guest browsing and cart are open. Tier 2 = the account-creating checkout is open |
| **Claim** | Atomically assigning a guest cart to a newly created member inside the order transaction |
| **Merge** | Moving a guest cart's lines into a member's existing cart at sign-in / registration |
| **Unplaced** | A member with `binary_parent_id` and `binary_position` both `NULL`; placement is chosen at activation |
| **Primary admin** | `User::primaryAdminId()` (`models/User.php:618`), the first admin by id; id 1 on a normal install |

---

## 4. Decision register (final)

| ID | Decision | Applied in |
|---|---|---|
| D1 | A guest is a visitor without an account; checkout creates one (no account-less orders) | P8 |
| D2 | The account is created at order placement (`pending` order), atomically; it survives an expired or cancelled order | P8 |
| D3′ | Credentials stay in checkout but minimal: username auto-suggested, **one** password field with show/hide, no confirm field, no rule beyond 8 characters | P8 |
| D4′ | Sponsor: referral cookie → read-only "Referred by @x"; otherwise the primary admin, resolved by id, with no field shown; an optional "I was referred by someone" link reveals one. No default-sponsor setting | P6, P8 |
| D5 | *Superseded by D19* — guest accounts are always unplaced | — |
| D6 | Guests pay by the four manual methods only; `ewallet` is hidden and rejected server-side | P8 |
| D7′ | Two-tier gate. Tier 1: `shop_enabled` + `guest_shop_enabled`. Tier 2 additionally: `guest_checkout_enabled`, `customer_zone_enabled`, `free_registration_enabled`. When tier 2 is closed the cart button reads "Join to continue". Seats are **not** a gate (D18) | P6, P8 |
| D8 | Mobile number required and validated (`is_valid_mobile`); address always saved (after commit, best effort); account-creation notice shown | P8 |
| D9 | "Log in & continue" merges the guest cart; signed-in users never see guest pages | P6 |
| D10 | Docs: guest front half in full; order page → `completed` by reference to `buyer_workflow.md` | P11 |
| D11 | Parity: same product grid, drawer, cart page and checkout look and behaviour, from shared templates; no e-wallet, saved addresses, wallet chrome or sidebar for guests | P2, P5 |
| D12 | A separate thin guest controller and `store*` routes over shared templates; member routes untouched | P5, P6 |
| D13 | Where it lives: a dedicated `?page=store`, linked from the landing page | P6 |
| D14 | Field budget: one Recipient block (name + mobile) feeds billing and shipping; about nine visible inputs | P8 |
| D15 | Attribution: last click wins, 30-day cookie, referral sponsor read-only | P6 |
| D16 | Customer = `status 'pending'`; partner = every other signed-in user | P1, P3 |
| D17 | "Affiliate" = Reading A: branding of the customer tier. No links, earnings or new engine. A commission programme would be a separate project | — |
| D18 | Seats: count partners only; activation takes the seat; customers and guest checkout are not seat-gated | P4 |
| D19 | Customers are never placed in the binary tree; placement happens at activation. Existing placed customers keep their slots | P4 |
| D20 | E-wallet: transfers only between partners; e-wallet at checkout only for partners | P4 |
| D21 | "Frontend" = a storefront zone inside the app, member-style look; a landing-site theme is a later polish | P2 |
| D22 | Partners keep Shop, Cart and Orders in the dashboard shell (no change for them) | P2 |

---

## 5. Settings (feature flags) and enablement order

| Key | Default | Introduced | Effect when OFF |
|---|---|---|---|
| `customer_zone_enabled` | `0` | P1 | Everything behaves as today: pending members use the dashboard |
| `guest_shop_enabled` | `0` | P1 | `store*` routes redirect to the landing page; the landing Shop link is hidden |
| `guest_checkout_enabled` | `0` | P1 | The cart's checkout button reads "Join to continue"; `store_checkout` / `store_place_order` refuse |
| `guest_cart_ttl_days` | `14` | P1 | (not a switch) guest cart and cookie lifetime, and the idle-cleanup threshold |

**Enable in this order, one gate at a time, after the stated phase:**
1. `customer_zone_enabled` — **Gate A**, after P4.
2. `guest_shop_enabled` — **Gate B**, after P6.
3. `guest_checkout_enabled` — **Gate C**, after P9. It **requires** gate A: the
   accounts it creates are customers, and they land in the customer zone.

Turning a flag OFF restores the previous behaviour immediately; no data needs
undoing (accounts and orders already created are ordinary rows).

P9 may add one more setting that is **not** a flag — `trusted_proxy_header` — only
if the P0 check finds the site behind a reverse proxy.

---

## 6. Phase map

| Phase | Title | Size | Risk | Ships | Go-live gate |
|---|---|---|---|---|---|
| **P0** | Baseline, pre-flight and decision freeze | S | none (no code) | — | — |
| **P1** | Foundation: schema, flags, inert primitives | M | low | dark | — |
| **P2** | Storefront shell and customer account home | M | low–medium (7 member templates change) | dark | — |
| **P3** | Access control and redirects | M | medium (router, guards) | dark | — |
| **P4** | Customer-zone business rules | M | medium (seats, placement, wallet) | dark | **Gate A** |
| **P5** | Shop context and guest cart layer | M | low–medium | dark | — |
| **P6** | Guest shop and cart | L | medium | dark | **Gate B** |
| **P7** | Registration core refactor | M | **high** (every registration path) | behaviour-preserving | — |
| **P8** | Guest checkout with atomic registration | L | high | dark | — |
| **P9** | Hardening and observability | M | medium | — | **Gate C** |
| **P10** | Admin tooling and reporting | S–M | low | — | — |
| **P11** | Rollout, documentation and clean-up | S | low | — | — |

"Dark" = merged and deployed, inert until its flag is enabled.

```
P0 ─ P1 ─ P2 ─ P3 ─ P4 ═══► Gate A  customer_zone_enabled
                      │
                      └─ P5 ─ P6 ═══► Gate B  guest_shop_enabled
                                 │
                                 └─ P7 ─ P8 ─ P9 ═══► Gate C  guest_checkout_enabled (needs A)
                                                 └─ P10 ─ P11
```

---

## 7. Engineering rules that apply to every phase

These come from the codebase itself; each is the cause of a specific mistake
this plan is designed to avoid.

| # | Rule | Source |
|---|---|---|
| R1 | **SQL**: prepared statements only (`db()`). The PDO connection uses `ATTR_EMULATE_PREPARES = false`, so a **named placeholder cannot be reused** in one statement — use positional `?` or distinct names. `CLIENT_FOUND_ROWS` is on, so `rowCount()` after `UPDATE` is the number of *matched* rows | `config/db.php` |
| R2 | **Output**: escape with `e()` (`core/helpers.php:42`); pass values into inline JS/attributes with `js()` (`:108`), never `e()`; no file-scope function declarations in views | `tests/TemplateLintTest.php` rules |
| R3 | **POST**: call `csrf_verify()` (`:320`) first — on failure it `die`s with a plain 403. Guests get a token too, because `index.php:425` seeds `csrf_token()` on every request | helpers, index.php |
| R4 | **Control flow**: `redirect()` (`:172`) calls `session_write_close()` and **exits**. Nothing after it runs, and the test harness cannot execute any controller path that redirects. Therefore keep controllers thin and put decisions in pure functions or service classes that return results — those are what tests cover | helpers, tests |
| R5 | **Schema**: every change goes into **both** `install.sql` (the test schema is built from it) **and** a `migrate_*.sql`. Migrations are run-once `ALTER`s; seed settings with `ON DUPLICATE KEY UPDATE key_name = key_name` so a re-run never overwrites an admin's choice. `SchemaDriftTest` must stay green | `tests/run.php`, `migrate_shop.sql` |
| R6 | **Settings** are saved through per-tab allow-lists: a key not listed in `AdminController::saveSettings` (`:520-623`) is silently dropped. Checkboxes go in `$checkboxKeys`, clamped integers in `$hourKeys` (`:596-597`) | AdminController |
| R7 | **Frozen**: `core/Commission.php` (the pre-deploy gate diffs it against git tag `shop-pre-remediation`, `tests/predeploy.php:92-99` — never move or delete that tag), the order state machine, and `ShopOrder::createFromCart()` (reuse as-is) | predeploy |
| R8 | **Autoload**: the app and the cron script autoload only `models/` and `controllers/` (`index.php:28-36`, `cron/shop_expiry.php:33-41`); `core/` classes are `require_once`d by hand (`index.php:16-26`); the test bootstrap autoloads `core/` too. **Put every new class in `models/` (or `controllers/`)** so it loads in all three places | index.php, cron |
| R9 | **Context limits**: the cron script loads only `config/db.php` and `core/helpers.php`; the landing page `frontend/index.php` loads those plus `models/Package.php` and never starts a session. Any helper they call (`guest_shop_open()`, cart purge) must not touch `Auth` or `$_SESSION` | cron, frontend |
| R10 | **`Auth::user()` caches in a function-static for the whole process** (`core/Auth.php:213-233`) and is not keyed by user id. Decide on a *row* with the pure `is_customer_user(array $row)`; `Auth::isCustomer()` is only a thin wrapper. Tests that switch identity must not depend on `Auth::user()` | Auth |
| R11 | **Sessions / Super-Login**: the superadmin impersonation tab uses session name `mlm_imp` with a URL nonce as the session id (`index.php:38-50`). Never call `Auth::start()` as it stands — it renames the cookie to `mlm_sess`, which signs every current user out and clashes with that branch. Redirects must go through `redirect()`, which preserves `imp` | index.php, Auth |
| R12 | **Flag discipline**: every new branch is guarded by a flag, and with all flags off the output must be byte-identical to the previous phase | this plan |
| R13 | **Copy**: customer-facing text is neutral — no income claims or promises about earnings | compliance |
| R14 | **Time and money**: `Asia/Manila`, amounts through `fmt_money()` | AGENTS.md |

---

## 8. Verification toolkit

| What | Command / method | When |
|---|---|---|
| Syntax | `php -l <file>` for every changed PHP file (or `find . -name '*.php' -not -path './tests/*' -print0 \| xargs -0 -n1 php -l`) | every PR |
| Unit/integration | `php tests/run.php` (all) · `php tests/run.php <SuiteNameFragment>` (one). The DB schema name **must contain `_test`**; the runner rebuilds it from `install.sql` | every PR |
| Deploy gate | `php tests/predeploy.php` — includes the `core/Commission.php` drift check and the committed-credentials scan | every PR |
| Flag-off equivalence | the Phase 0 snapshot tool: render the page set for a pending member, an active member and an admin, diff against the stored baseline | every phase P1–P8 |
| Controller / router behaviour | the manual QA matrix (Appendix F) and, for P6/P8, a `php -S` + `curl` cookie-jar smoke script (outline inside those phases). The harness cannot call redirecting controllers (R4) | P3, P4, P6, P8 |
| SQL | the verification queries listed in each phase | after each migration |

**Harness facts** (so tests are written correctly): suites are files
`tests/*Test.php` discovered by glob; a test is `test('name', function () { … });`
using `T::ok`, `T::eq`, `T::inSet`, `T::throws`; `Fixtures::member/admin/product/cartWith/…`
build data and `Fixtures::loginAs` fakes a session; `resetState()` truncates
**only shop tables** (`shop_*`, `cart_items`, `carts`, `products`,
`member_addresses`, ledgers) — **not `users` or `settings`**, so a test that
creates users or flips a setting must clean up after itself; view tests use
`RenderLog::arm()` and `render_view()` and fail on any PHP warning.

---

## 9. Cross-cutting risk register

| Risk | Where it bites | Mitigation |
|---|---|---|
| A parse error in `index.php`, `helpers.php` or `User.php` takes the whole site down | every phase | `php -l` in the PR; deploy dark; keep the previous release ready to restore |
| Template edits change what members see | P2, P5, P8 | the flag-off snapshot diff must be empty |
| The registration refactor regresses a signup path | P7 | isolated phase, regression suite for all registration modes, quiet-window deploy, staging smoke |
| Bots mint carts and accounts, or hold stock for 24 h | P6, P8, P9 | DB-backed per-IP and per-mobile limits, honeypot, no CAPTCHA on add-to-cart, caps counted on commit |
| `REMOTE_ADDR` is a proxy address, so per-IP limits throttle everyone | P0, P9 | verify in P0; add a trusted-proxy helper in P9 if needed |
| Session changes sign everyone out | P9 | `ini_set` flags only, no cookie rename (R11) |
| Existing customers have downlines, slots, or wallet balances | P0, P4 | audits (Appendix A) and a grandfathering runbook before Gate A |
| The seat limit now counts partners only — a business-rule change | P4 | announce it; copy in admin says "partner seats" |
| Privacy and cookie disclosure | P6, P8, P9 | final notice wording before gates B and C; sponsor cookie disclosed separately from the cart cookie |
| Scope creep into an affiliate commission programme | any | D17 — out of scope; log requests separately |
| Super-Login interplay | P3, P9 | all redirects via `redirect()`; QA row in Appendix F |

---

## Phase 0 — Baseline, pre-flight and decision freeze

### Goal
Create a measurable "before" and remove unknowns, so every later phase can prove
it changed nothing it should not have. **No application code changes.**

### Depends on
Nothing.

### Context you need
- `php tests/run.php` rebuilds a throwaway database from `install.sql`; the
  schema name **must contain `_test`**. `php tests/predeploy.php` is the deploy
  gate: it also diffs `core/Commission.php` against git tag
  `shop-pre-remediation` (`tests/predeploy.php:92-99`) and scans tracked files
  for committed credentials. `AGENTS.md` says the repo has no automated tests;
  that is out of date.
- Pending members today can reach all 40 `member` routes. Only `activate` /
  `doActivate` are pending-only (`MemberController.php:608`, `:643`). Seats count
  every `role = 'member'` row, pending or not (`core/helpers.php:155-167`).
- `frontend/index.php` is served at the root URL by `.htaccess` and starts no
  session. All app pages go through `index.php?page=…`.
- The only client-IP source in the app is `$_SERVER['REMOTE_ADDR']`
  (`core/Auth.php:76`, `controllers/AuthController.php:92`, `models/ShopOrder.php:850`).
- The uploaded snapshot contained `config/db.php` and `midnight_reset.php` with
  hard-coded secrets (`predeploy` already lists a weak committed cron key as an
  accepted issue).
- The Super-Login impersonation tab uses session name `mlm_imp` and a URL nonce
  as the session id (`index.php:38-50`).

### Scope
**In:** branches and tags, baseline runs, a snapshot tool (test tooling only),
data audits, environment checks, decisions, privacy wording, comms.
**Out:** any change under `controllers/`, `models/`, `core/`, `views/`,
`assets/`, `cron/`, `config/`.

### Tasks

**0.1 Branches and tags.**
Tag the current release commit `pre-customer-zone`. Create the integration
branch `feature/customer-zone-guest-store`; each phase is a branch
`cz-p<N>-<slug>` merged into it in order. Do not touch tag
`shop-pre-remediation` (R7).

**0.2 Green baseline.**
Create a database named like `altas_test`. Run `php tests/run.php` and
`php tests/predeploy.php`. Save both outputs under `docs/baseline/` and note the
accepted-issue list `predeploy` prints. If anything is already red, document it
now — later phases must not be blamed for it.

**0.3 Snapshot tool** *(new: `tests/tools/snapshot_http.sh` + `tests/tools/seed_snapshot.sql`)*.
Purpose: capture the **real rendered output** (router + controller + view) so
later phases can prove flag-off equivalence.
- Seed a staging database with: one pending member `snap_customer`, one active
  member `snap_partner` (with a package), one admin, two products, a cart with two
  lines for each member, one `pending` order and one `shipped` order, and one saved
  address. Use fixed ids and fixed timestamps.
- Start `php -S 127.0.0.1:8080` against it. For each user, log in with `curl`
  (cookie jar; fetch `?page=login`, extract the CSRF token, POST `do_login`).
- Fetch this page set per user and store HTML under
  `tests/snapshots/<label>/<user>/<page>.html`: `dashboard`, `profile`, `shop`,
  `cart`, `checkout`, `shop_orders`, `shop_order&id=<pending order>`,
  `activate` (customer only), `genealogy`, `earnings`.
- **Normalise volatile output** before saving: CSRF token values, the `idem`
  token, session ids, `imp=` nonces, timestamps and "x minutes ago" strings.
- Usage: `snapshot_http.sh <label>`; compare with `diff -ru tests/snapshots/p0
  tests/snapshots/<label>`.
- **Acceptance for the tool itself:** two consecutive runs with the same label
  produce an empty diff.

**0.4 Data audits** (Appendix A). Run on a production copy and record counts.
Every non-zero result needs a written policy now:

| Audit | If non-zero |
|---|---|
| Customers who sponsor someone | They keep their sponsor links; the referral UI disappears. Decide whether support contacts them |
| Customers already placed in the binary tree | They keep their slots (D19); they remain restricted to binary packages at activation, as today |
| Customers holding e-wallet funds | Decide per account: activate them, or let admin move the funds, *before* Gate A — they will have no wallet UI |
| Customers with orders | Nothing to do; orders stay in the customer zone |
| Duplicate `(binary_parent_id, binary_position)` pairs | Fix before ever adding the optional unique index (P10) |

**0.5 Environment checks.**
- PHP version, PDO MySQL and `mbstring` present; record `session.cookie_httponly`,
  `session.use_strict_mode`, `session.cookie_secure`, `session.gc_maxlifetime`.
- Production is HTTPS-only (needed before `cookie_secure` can be enabled in P9).
- **`REMOTE_ADDR` check:** upload a throwaway PHP file that prints
  `REMOTE_ADDR`, `HTTP_X_FORWARDED_FOR` and `HTTP_CF_CONNECTING_IP`; request it
  from a known public IP; compare; delete the file. Record the verdict: *direct*,
  or *behind a proxy via header X*. P9 depends on it.
- `cron/shop_expiry.php` is running every 15 minutes (check its log).
- The DB user may `ALTER TABLE` / `CREATE TABLE`; a backup restores cleanly;
  staging mirrors production (HTTPS, proxy/CDN, cron).
- If the secrets in `config/db.php` / `midnight_reset.php` ever left a trusted
  machine (the snapshot was shared), rotate them.

**0.6 Decision freeze.** Sign off D1–D22 (§4) and settle the open items with
these defaults unless the owner decides otherwise: D15 last click; storefront
`noindex`; no optional email at checkout; no unique index on binary slots; the
deferred-password alternative and SMS verification stay out of scope. Record the
decisions in a dated decision log (`docs/decisions.md`).

**0.7 Privacy and communications.** Draft the final wording for the
account-creation notice, the cart-cookie and sponsor-cookie disclosure, and the
neutral-copy rule (R13). Brief support: customers vs partners; a customer's
password is reset by an admin with `admin_change_password`
(`AdminController.php:201`); the admin member search matches username, full
name and email (not mobile). Schedule maintenance windows for P1 and P7.

### Tests
None to add. Run the existing suites and the snapshot tool.

### Acceptance criteria
- `php tests/run.php` and `php tests/predeploy.php` outputs saved; any red result documented.
- Snapshot baseline `p0` exists and is reproducible (empty diff on a re-run).
- Audit counts recorded, each non-zero one with a policy.
- Environment verdicts recorded: `REMOTE_ADDR`, HTTPS, cron, backup/restore.
- Decision log signed; open items resolved or defaulted.

### Rollout and rollback
Nothing is deployed. Nothing to roll back.

### Hand-off
A frozen decision set, a reproducible baseline to diff against, known data
realities, and an environment verdict (notably `REMOTE_ADDR`) that P1–P9 rely on.

---

## Phase 1 — Foundation: schema, flags and inert primitives

### Goal
Add every database change, setting and small helper that later phases need, so
none of them ever waits on schema or primitives. **Nothing user-visible changes.**

### Depends on
P0 (baseline, snapshots, decision log).

### Context you need
- Settings live in `settings(key_name PK, value TEXT)`; `setting()` is cached
  per request (`core/helpers.php:408`) and `setting_forget()` (`:433`) clears it.
- `install.sql` landmarks: `users` `:73`, `carts` `:182`, `cart_items` `:455`,
  `settings` `:628`, setting seeds `:745-751` (e.g. `free_registration_enabled`).
- `carts`: `member_id INT UNSIGNED NOT NULL` with an FK to `users`
  (`ON DELETE CASCADE`), `status ENUM('active','abandoned','converted')`,
  `created_at`, `updated_at ... ON UPDATE CURRENT_TIMESTAMP`, plain index
  `idx_carts_member_status`. **`models/Cart.php` is the only code that queries
  `carts`.** `users.username` is `UNIQUE`; there is no `registered_via` column.
- Migration style: run-once `ALTER`s and `CREATE TABLE IF NOT EXISTS`; settings
  seeded in `migrate_shop.sql:231-238` with `ON DUPLICATE KEY UPDATE value = VALUES(value)`
  — **do not copy that clause** for new flags (R5).
- Admin settings: per-tab allow-lists in `AdminController::saveSettings`
  (`:520-623`), `$checkboxKeys`/`$hourKeys` (`:596-597`); the `basics` group holds
  the shop keys; the view block is `views/admin/settings.php:66-96`.
- `reset.php:57` deletes members; their carts cascade, guest carts would not.
- Test harness: `resetState()` (`tests/bootstrap.php:431-442`) truncates only
  shop tables; it does not touch `users` or `settings` (§8).
- `is_valid_mobile()` is `/^(09|\+639)\d{9}$/` (`core/helpers.php:362`).
- `Auth::user()` caches per process (R10); `Auth::ADMIN_ROLES` is a public
  constant; `Auth::check()` is `core/Auth.php:145`, `isMember()` `:160`.

### Scope
**In:** migration, `install.sql`, helpers (`customer_zone_enabled`,
`is_customer_user`, `landing_url_for`, `normalize_mobile`, `db_rate_limit_check`,
`db_rate_limit_hit`), `Auth::isCustomer` / `isPartner`, admin settings UI,
`reset.php`, test-harness tweaks, `FoundationTest`.
**Out:** any call site of the new helpers in application code; any route, view or
controller change.

### Tasks

**1.1 Migration** `migrate_customer_zone_guest_store.sql` *(new)*. Match the
engine and charset of the neighbouring tables.

```sql
-- carts: guest ownership + funnel marker
ALTER TABLE carts
  MODIFY member_id INT UNSIGNED NULL,
  ADD COLUMN guest_token      CHAR(64) NULL,
  ADD COLUMN guest_started_at DATETIME NULL,
  ADD UNIQUE KEY uq_carts_guest_token (guest_token);

-- users: provenance of the account
ALTER TABLE users
  ADD COLUMN registered_via VARCHAR(20) NOT NULL DEFAULT 'register';

-- DB-backed rate limiter (the existing one is per-session — see P9)
CREATE TABLE IF NOT EXISTS rate_limits (
  key_name     VARCHAR(120) NOT NULL PRIMARY KEY,
  hits         INT UNSIGNED NOT NULL DEFAULT 0,
  window_start INT UNSIGNED NOT NULL,
  KEY idx_rate_limits_window (window_start)
);

-- flags: all OFF; a re-run must never overwrite an admin's choice
INSERT INTO settings (key_name, value) VALUES
  ('customer_zone_enabled', '0'),
  ('guest_shop_enabled',    '0'),
  ('guest_checkout_enabled','0'),
  ('guest_cart_ttl_days',   '14')
ON DUPLICATE KEY UPDATE key_name = key_name;
```

Verification queries after running it:
`SHOW COLUMNS FROM carts LIKE 'guest_token';`
`SHOW COLUMNS FROM carts LIKE 'member_id';` (`Null = YES`)
`SHOW COLUMNS FROM users LIKE 'registered_via';` (default `register`)
`SELECT key_name, value FROM settings WHERE key_name IN ('customer_zone_enabled','guest_shop_enabled','guest_checkout_enabled','guest_cart_ttl_days');`

Down migration (only while no guest carts or guest-created accounts exist):

```sql
DELETE FROM carts WHERE member_id IS NULL;
ALTER TABLE carts DROP INDEX uq_carts_guest_token,
  DROP COLUMN guest_started_at, DROP COLUMN guest_token,
  MODIFY member_id INT UNSIGNED NOT NULL;
ALTER TABLE users DROP COLUMN registered_via;
DROP TABLE IF EXISTS rate_limits;
DELETE FROM settings WHERE key_name IN
  ('customer_zone_enabled','guest_shop_enabled','guest_checkout_enabled','guest_cart_ttl_days');
```

Test the migration on staging first: confirm the `carts` → `users` FK survives
the `MODIFY member_id … NULL`.

**1.2 `install.sql`** — mirror the migration exactly: the `carts` DDL (`:182`),
the `users` DDL (`:73`), a new `rate_limits` table beside `settings` (`:628`),
and four seed rows in the settings block (`:745-751`). `SchemaDriftTest` and the
rebuilt test schema depend on this.

**1.3 Helpers** in `core/helpers.php` *(new; place them after the seat helpers,
before the Navigation section)*. All inert — nothing calls them yet.

```php
function customer_zone_enabled(): bool
{
    return setting('customer_zone_enabled', '0') === '1';
}

/** A member who has never activated (a free account). Pure: takes a users row. */
function is_customer_user(array $user): bool
{
    return ($user['role'] ?? '') === 'member' && ($user['status'] ?? '') === 'pending';
}

/** Where a signed-in user belongs after login / registration / a bounced request. */
function landing_url_for(array $user): string
{
    if (in_array($user['role'] ?? '', Auth::ADMIN_ROLES, true)) {
        return '/?page=admin';
    }
    if (customer_zone_enabled() && is_customer_user($user)) {
        return '/?page=account';
    }
    return '/?page=dashboard';
}

/** '+639171234567' / '0917 123 4567' → '09171234567'; '' when not a PH mobile. */
function normalize_mobile(string $raw): string
{
    $d = preg_replace('/[\s\-\(\)\.]/', '', $raw);
    if (preg_match('/^\+639(\d{9})$/', $d, $m)) {
        $d = '09' . $m[1];
    }
    return preg_match('/^09\d{9}$/', $d) ? $d : '';
}

/** Read-only: true while $key has fewer than $max hits in its current window. */
function db_rate_limit_check(string $key, int $max, int $windowSeconds, ?int $now = null): bool
{
    $now = $now ?? time();
    $st  = db()->prepare('SELECT hits, window_start FROM rate_limits WHERE key_name = ?');
    $st->execute([$key]);
    $row = $st->fetch();
    if (!$row || (int) $row['window_start'] <= $now - $windowSeconds) {
        return true;
    }
    return (int) $row['hits'] < $max;
}

/** Count one event for $key (atomic; starts a new fixed window when the old one expired). */
function db_rate_limit_hit(string $key, int $windowSeconds, ?int $now = null): void
{
    $now    = $now ?? time();
    $cutoff = $now - $windowSeconds;
    // R1: native prepares — positional placeholders, none reused by name.
    db()->prepare(
        'INSERT INTO rate_limits (key_name, hits, window_start) VALUES (?, 1, ?)
         ON DUPLICATE KEY UPDATE
           hits         = IF(window_start <= ?, 1, hits + 1),
           window_start = IF(window_start <= ?, ?, window_start)'
    )->execute([$key, $now, $cutoff, $cutoff, $now]);
}
```

Keys are `bucket:ip` or `bucket:sha1(normalised mobile)`; never store a raw
mobile number. In `core/Auth.php`, next to `isMember()` (`:160`):

```php
public static function isCustomer(): bool { return self::check() && is_customer_user(self::user()); }
public static function isPartner():  bool { return self::check() && !self::isCustomer(); }
```

**1.4 Admin settings.** In `AdminController::saveSettings`: add the four keys to
the `basics` group allow-list; add the three flags to `$checkboxKeys`; add
`guest_cart_ttl_days` to `$hourKeys` (clamped to ≥ 1). In
`views/admin/settings.php`, add a card "Customer zone & guest storefront" below
the shop block with three switches and the TTL input. Under the switches put the
enablement order from §5 as helper text, and a warning that
`guest_checkout_enabled` requires `customer_zone_enabled`.

**1.5 `reset.php`** (`:57`): after the member delete also run
`DELETE FROM carts WHERE member_id IS NULL` and `TRUNCATE rate_limits`.

**1.6 Test harness.** Add `rate_limits` to the table list in `resetState()`
(`tests/bootstrap.php:431-442`). Add `Fixtures::setting(string $key, string $value): callable`
that writes the setting, calls `setting_forget($key)`, and returns a closure that
restores the previous value — tests that flip a flag must call it in a `finally`.
Add a cleanup convention: tests create users with a `zt_` username prefix and
delete them (`DELETE FROM users WHERE username LIKE 'zt\_%'`) at the end, because
`resetState()` leaves `users` alone.

**1.7 `tests/FoundationTest.php`** *(new)*.

### Tests
| Case | Asserts |
|---|---|
| schema | `carts.guest_token` and `guest_started_at` exist; `carts.member_id` is nullable; `uq_carts_guest_token` exists; `users.registered_via` defaults to `register`; `rate_limits` exists |
| flags | the four settings exist with default `0 / 0 / 0 / 14` on a fresh install |
| `is_customer_user` | member+pending → true; member+active → false; admin+pending → false; empty array → false |
| `landing_url_for` | zone OFF: customer → dashboard, partner → dashboard, admin → admin. Zone ON: customer → `/?page=account`, others unchanged |
| `normalize_mobile` | `+639171234567`, `0917 123 4567`, `0917-123-4567` → `09171234567`; `12345`, `+6391712345`, `''` → `''` |
| `db_rate_limit_*` | under the cap → allowed; at the cap → denied; after the window passes (inject `$now`) → allowed and the counter restarts; two keys do not interfere |
| `Auth::isCustomer` / `isPartner` | one integration test per process (R10): log in a pending member → customer, not partner; use `is_customer_user` for the rest |

### Acceptance criteria
- `php -l` clean; `php tests/run.php` green including `FoundationTest`; `SchemaDriftTest` green; `predeploy` green.
- Migration applied on staging; verification queries show the expected shapes.
- Admin settings page shows the four controls, all OFF/14; saving persists them.
- **Snapshot diff against `p0` is empty.**

### Rollout and rollback
Back up the database; apply the migration in a maintenance window; deploy the
code. Roll back with the down migration and by reverting the PR.

### Hand-off
Schema, four flags (OFF), and tested primitives exist. Nothing in the running
application calls them yet.

---

## Phase 2 — Storefront shell and customer account home

### Goal
Build the place customers will live: a **storefront shell** that the seven
customer-facing templates can render in, and a customer **account home** page.
Still inert unless the flag is on, and **no access restrictions yet**.

### Depends on
P1 (`customer_zone_enabled()`, `is_customer_user()`, `landing_url_for()`,
`Auth::isCustomer()`).

### Context you need
- **Seven templates serve customers:** `views/member/shop.php`, `cart.php`,
  `checkout.php`, `shop_orders.php`, `shop_order.php`, `profile.php`,
  `activate.php`. Each contains the same four-line shell, byte for byte:
  ```php
  <?php require 'views/partials/head.php'; ?>
  <?php require 'views/partials/sidebar_member.php'; ?>
  <div class="main-content">
    <?php require 'views/partials/topbar.php'; ?>
  ```
  (`checkout.php:9-12`, `shop_order.php:15-18`; locate by pattern in the others.)
  All 17 member views share this shell; the invoice (`views/shop/invoice.php`) and
  refund receipt are standalone and need no change.
- `views/partials/topbar.php` shows a wallet balance chip for members, the page
  title, a user dropdown and the cart button (`.topbar-cart-badge`,
  `data-bs-target="#cartOffcanvas"`, around `:105-110`). Customers must not see
  the wallet chip.
- `views/partials/footer.php:13` includes `cart_offcanvas.php` on every page; it
  works for any signed-in user (`Auth::check()`, `Cart::getActive(Auth::id())`)
  and updates `.topbar-cart-badge` / `.sidebar-cart-badge` / `#shopViewCartBadge`.
- `head.php` takes `$pageTitle`, hard-codes `noindex,nofollow`, and loads
  `shop.css` when `$pageTitle === 'Shop'`.
- CSS: `.main-content` has `margin-left: var(--sidebar-w)`; `.topbar-wrapper` is
  `position: sticky` inside it, so one modifier class is enough
  (`assets/css/layout.css`; it already zeroes the offset under 992 px).
- Handlers for the templates: `shop` (`MemberController.php:1232`), `shopOrders`
  (`:1243`), `shopOrder` (`:1251`), `cart` (`:1277`), `checkout` (`:1388`),
  `profile` (`:20`), `activate` (`:603`). The orders list uses
  `ShopOrder::allForMember($uid)` — a plain list, newest first (`:581`).
- `?page=account` does not exist yet. Roles/guards are P3's job: in this phase the
  new route is registered as role `member`.
- Test rules: `TemplateLintTest` scans every file in `views/` (RULE-1: use `js()`
  not `e()` in inline handlers; no file-scope functions); `ViewRenderTest`
  renders templates with `RenderLog::arm()` and fails on any PHP warning.
- The dashboard's pending banner (`views/member/dashboard.php:27-44`) stays until P11.

### Scope
**In:** shell helpers, the storefront topbar, the CSS modifier, switching the
seven templates, the account route/controller/view, render tests.
**Out:** redirects, guards, role changes, seat/placement/wallet rules, anything
guest-related.

### Tasks

**2.1 Shell helpers** in `core/helpers.php`:

```php
/** 'store' (no sidebar, storefront topbar) or 'dashboard' (today's shell). */
function shop_shell(): string
{
    if (!empty($GLOBALS['SHOP_SHELL'])) {                    // forced by a controller (guests, P6)
        return $GLOBALS['SHOP_SHELL'] === 'store' ? 'store' : 'dashboard';
    }
    return (customer_zone_enabled() && Auth::isCustomer()) ? 'store' : 'dashboard';
}

/** $slot: 'sidebar' | 'topbar'. */
function shell_partial(string $slot): string
{
    $store = shop_shell() === 'store';
    if ($slot === 'sidebar') {
        return $store ? 'views/partials/shell_none.php' : 'views/partials/sidebar_member.php';
    }
    return $store ? 'views/partials/store_topbar.php' : 'views/partials/topbar.php';
}

function shell_main_class(): string
{
    return shop_shell() === 'store' ? ' no-sidebar' : '';
}
```

**2.2 Partials** *(new)*:
- `views/partials/shell_none.php` — a file containing only a PHP comment.
- `views/partials/store_topbar.php` — a sticky `.topbar-wrapper` with: brand
  (logo + site name → `link_to('shop')`); nav links **Shop** (`shop`) and **My
  Orders** (`shop_orders`) with an active state taken from `$pageTitle`; on the
  right the **cart button** copied from `topbar.php` (keep `.topbar-cart-badge`
  and `data-bs-target="#cartOffcanvas"` so the existing drawer script works), a
  **Become a partner** button (`link_to('activate')`), and a user dropdown
  (Account `account`, Profile `profile`, Log out `logout`). **No wallet chip, no
  hamburger.** Escape everything with `e()`.

**2.3 CSS** in `assets/css/layout.css`, after the `.main-content` rule:
`.main-content.no-sidebar { margin-left: 0; }` and
`.main-content.no-sidebar .page-content { width: 100%; max-width: 1200px; margin: 0 auto; }`;
plus a few lines styling the storefront nav links and the "Become a partner"
button using existing design tokens.

**2.4 Switch the seven templates.** In each, replace exactly these three lines
(leave the `head.php` line alone):

```php
<?php require shell_partial('sidebar'); ?>
<div class="main-content<?= shell_main_class() ?>">
  <?php require shell_partial('topbar'); ?>
```

With the flag OFF, `shop_shell()` is `'dashboard'`, the partial paths are the
originals and the `class` attribute is unchanged, so output is byte-identical.

**2.5 Account home.**
- Route `'account' => ['MemberController', 'account', 'member']` in `index.php`'s
  route table (role becomes `account` in P3).
- `MemberController::account()` *(new, next to `dashboard()` at `:9`)*:
  `Auth::guard('member')`; load `$user = Auth::user()`; **if not a customer,
  `redirect(landing_url_for($user))`**; `$orders = array_slice(ShopOrder::allForMember((int)$user['id']), 0, 5)`;
  `$cartCount = Cart::itemCountForMember(...)`; `$pageTitle = 'My Account'`;
  `require 'views/member/account.php'`.
- `views/member/account.php` *(new)*, using the same four-line shell: a welcome
  header (full name or `@username`, "Free account"); a **Your orders** card
  (order number via `shop_public_order_no`, date, status badge, total via
  `fmt_money`, link to `shop_order&id=`; empty state with a Shop button; "View
  all orders"); a **Shop** card; a **Profile & addresses** card; and a **Become a
  partner** card — neutral copy (R13), the sponsor's username if one exists, and a
  button to `activate`. Render `render_flash()` at the top.

### Tests
- `ViewRenderTest` additions: seed a pending member (`zt_` prefix), `Fixtures::loginAs`,
  then for each of the seven templates plus `account.php` render once with
  `$GLOBALS['SHOP_SHELL'] = 'store'` and once with `'dashboard'`; build each
  template's variable set by reading the controller method listed above.
  `assert_clean_render` on all. Store shell: output contains `no-sidebar` and
  `topbar-cart-badge`, and does **not** contain the sidebar markup
  (`sidebar-brand`). Dashboard shell: contains the sidebar markup.
- `TemplateLintTest` stays green with the two new views and one new partial.
- Pure test for the helpers: `shell_partial()` / `shell_main_class()` under a
  forced shell.

### Acceptance criteria
- `php -l`, `php tests/run.php`, `php tests/predeploy.php` green.
- **Flag OFF: snapshot diff against `p0` is empty** for every page and user.
- Flag ON in staging, signed in as (or Super-Login as) a pending member: the seven
  pages and `?page=account` render in the storefront shell with no wallet chip,
  no sidebar, a working cart drawer and badge. A partner still sees the dashboard shell.
- Dashboard pages and URLs are still reachable by a customer (restrictions arrive in P3).

### Rollout and rollback
Deploy with the flag OFF. Roll back by reverting the PR (the migration from P1
is untouched).

### Hand-off
Customers have a storefront shell and an account home. Nobody is routed there yet.

---

## Phase 3 — Access control and redirects

### Goal
Enforce the customer/partner boundary: two new router roles, every route placed
on the right side of it, and every "where do I land?" redirect made zone-aware.
Decision logic lives in a pure, tested function. Still behind the flag.

### Depends on
P2 (storefront shell, `account` route and page).

### Context you need
- **Router** (`index.php`): the default-route redirect for signed-in visitors
  `:399-401`; the guest bounce `:408` (signed-in users are redirected to the
  dashboard/admin except for `register` / `do_register`); the `member` check
  `:411` (signed-in only); the `admin` and `super` checks after it. The route
  table starts at `:272` and holds 98 routes: admin 45, **member 40**, any 9,
  guest 2, super 2. A role of `any` applies no check at all.
- **`Auth::guard($role)`** (`core/Auth.php:242`): for `guest` it redirects a
  signed-in user to admin/dashboard; otherwise it flashes and redirects to login
  when signed out, checks `admin`, and logs a `suspended` user out.
- **Login redirects** (`controllers/AuthController.php`): `doLogin` (`:16`) ends
  with `redirect(admin ? '/?page=admin' : '/?page=dashboard')` (`:44`).
  `doSlogin` (`:54`, Super-Login) ends with
  `redirect('/?page=dashboard&imp=' . $nonce)` (`:126`). `doRegister` (`:240`)
  redirects a self-registering guest to `/?page=dashboard` after `Auth::login`
  (`:455`) and restores the session of a signed-in registrar. `showRegister` is
  at `:131`.
- `ewallet_transfer` / `do_ewallet_transfer` are role `any` and rely on
  `Auth::check()` inside the handlers (`MemberController.php:1076`, `:1100`).
- `flash('info', …)` is rendered by `render_flash()` (`core/helpers.php:278`).
- **Seat-limit block** `index.php:254-257` (register routes) — not touched here.
- The 40 `member` routes split 21 / 19 (Appendix B). Pending members can reach
  all of them today; only `activate` / `doActivate` check `status` (`:608`, `:643`).
- R4: controllers cannot be run by the harness. R10: `Auth::user()` is cached for
  the process. Both force the decision logic into a pure function.
- Autoload: put new classes in `models/` (R8).

### Scope
**In:** `Access` class; `Auth::guard` roles `account` and `partner`; router role
handling; re-roling the route table; login / registration / impersonation
redirects; stopping customers from acting as registrars; tests; QA matrix.
**Out:** seats, placement, wallet, profile (P4); everything guest-related.

### Tasks

**3.1 `models/Access.php`** *(new)* — one pure decision function and one pure
landing function.

```php
final class Access
{
    /** Where a signed-in user belongs. Single source of truth for landing_url_for(). */
    public static function landing(array $user, bool $zoneOn): string
    {
        if (in_array($user['role'] ?? '', Auth::ADMIN_ROLES, true)) return '/?page=admin';
        if ($zoneOn && is_customer_user($user))                       return '/?page=account';
        return '/?page=dashboard';
    }

    /**
     * Redirect target for a request that may not proceed, or null when it may.
     * $user is a users row, or [] when signed out.
     */
    public static function redirectFor(string $role, string $page, array $user, bool $zoneOn): ?string
    {
        $in = !empty($user['id']);
        switch ($role) {
            case 'any':    return null;
            case 'guest':  return ($in && !in_array($page, ['register', 'do_register'], true))
                                  ? self::landing($user, $zoneOn) : null;
            case 'member':
            case 'account': return $in ? null : '/?page=login';
            case 'partner':
                if (!$in) return '/?page=login';
                return ($zoneOn && is_customer_user($user)) ? '/?page=account' : null;
            case 'admin':  return !$in ? '/?page=login'
                                  : (in_array($user['role'] ?? '', Auth::ADMIN_ROLES, true) ? null : self::landing($user, $zoneOn));
            case 'super':  return ($user['role'] ?? '') === 'superadmin' ? null : '/?page=dashboard';
        }
        return null;
    }
}
```
Make `landing_url_for()` (P1) delegate to `Access::landing(…, customer_zone_enabled())`.
**Before coding `admin` / `super`, read the current router lines and copy their
exact redirect targets** — the function must reproduce today's behaviour when
the flag is OFF (the flag-off oracle test below enforces it).

**3.2 `Auth::guard()`** (`core/Auth.php:242`): accept `account` (same as
`member`) and `partner` (same as `member`, then
`if (customer_zone_enabled() && is_customer_user($u)) { flash('info', 'That area is for partners. Activate your account to unlock it.'); redirect('/?page=account'); }`).
Replace the hard-coded dashboard redirects in the `guest` and `admin` branches
with `landing_url_for(self::user())`.

**3.3 `index.php`** — replace the inline role checks (`:408-422`) with one call:
`$to = Access::redirectFor($role, $page, Auth::check() ? Auth::user() : [], customer_zone_enabled());`
and, when non-null, `flash()` then `redirect($to)`. **Preserve the existing flash
texts** for the existing cases (login required, access denied) and use the new
"That area is for partners…" text for the `partner` → account case. Replace the
default-route redirect (`:399-401`) with `redirect(landing_url_for(Auth::user()))`.

**3.4 Route table** — change the third element of each route (list in Appendix B):
21 routes `member → account`; 19 routes `member → partner`;
`ewallet_transfer` and `do_ewallet_transfer` `any → partner`; and the P2 route
`account` becomes role `account`. After this no route has role `member`; the
router still accepts it (a new route must choose `account` or `partner`
deliberately). With the flag OFF both new roles behave exactly like `member`.

**3.5 `AuthController`:**
- `doLogin` (`:44`): `redirect(landing_url_for($user));`
- `doSlogin` (`:126`): `redirect(landing_url_for($target) . '&imp=' . $nonce);`
  (`landing_url_for` returns a `/?page=…` string, so the `&imp=` suffix is valid).
- `showRegister` (top) and `doRegister` (after `csrf_verify()`): if the zone is ON
  and the signed-in user is a customer, `flash('info', 'Registering members is for partners. Activate your account to unlock it.')`
  and `redirect('/?page=account')`.
- `doRegister` self-registration success (`:455`): `redirect(landing_url_for($newUser));`
  and, when the zone is ON, the flash reads "Welcome! Your free account is ready.
  Activate any time to become a partner." (existing text otherwise).

**3.6 Dashboard handlers** keep their `Auth::guard('member')` calls; the router
role now does the gating, so no handler logic changes.

### Tests
**`tests/CustomerZoneAccessTest.php`** *(new)*:
- **Matrix:** `Access::redirectFor` for roles `{guest, any, member, account, partner, admin, super}` × users `{visitor, customer, partner, admin, superadmin}` × zone `{off, on}` × pages including `register`. Assert exact targets.
- **Flag-off oracle:** write the *legacy* decision table (the current router's behaviour) as a test fixture and assert `redirectFor(…, false)` equals it for every cell, with `account` and `partner` treated as `member`.
- **Landing:** `landing_url_for` equals `Access::landing` for every user type and flag state.
- **Route inventory:** read `index.php`, regex out every `'name' => ['Controller','method','role']`; assert every role is in the allowed set; assert the `account` set, the `partner` set and the two wallet routes equal Appendix B; assert **zero** routes still have role `member`.

### Acceptance criteria
- `php -l`, `php tests/run.php`, `php tests/predeploy.php` green.
- **Flag OFF: snapshot diff against `p0` is empty** and the oracle test passes.
- Flag ON (staging) — manual matrix (Appendix F): a customer opening any partner route lands on `?page=account` with the info flash; a partner opening `?page=account` lands on the dashboard; an admin on the admin area; a visitor on login; logging in lands each user type in the right place; Super-Login as a customer lands on `?page=account&imp=…`; a customer cannot open the Register Member page; no redirect loops.
- `ewallet_transfer` still redirects a signed-out visitor to login.

### Rollout and rollback
Deploy with the flag OFF. Do **not** enable the flag in production before P4.
Roll back by reverting the PR.

### Hand-off
The boundary exists and is enforced, flag-gated and tested. The customer zone is
reachable but its business rules (seats, placement, wallet, profile) are still the
old ones.

---

## Phase 4 — Customer-zone business rules  ·  **Gate A**

### Goal
Finish the customer-zone semantics — seats, placement, wallet, profile,
activation and admin labels — and open **Gate A** (`customer_zone_enabled`).

### Depends on
P3 (roles, redirects, `Access`).

### Context you need
- **Seats:** `seatsRemaining()` (`core/helpers.php:155`) counts every
  `role = 'member'` row against `settings.seat_limit`; `isSeatLimitReached()` is
  `:165`. Consumers: `index.php:254-257` (blocks `register`, `do_register`,
  `validate_code` with a 403), `AuthController` (`showRegister` ~`:134`,
  `doRegister` ~`:245`), `User::register()` (`models/User.php:13`),
  `views/member/dashboard.php:154`, admin views, and `frontend/index.php`.
- **Activation:** `doActivate` (`MemberController.php:635`) requires
  `status === 'pending'` (`:643`), accepts a registration code or e-wallet, and
  on success redirects to `/?page=dashboard`. A *placed* pending member is limited
  to binary packages; an unplaced one chooses a connection at activation.
- **Placement code:** `showRegister`'s referral block (`AuthController.php:~174-198`)
  prefills an upline via `User::isValidBinaryUpline` and `findNextBinarySlot`
  (`:207`); `doRegister` computes `$requirePlacement` (`:387`).
- **Wallet:** `doEwalletTransfer` (`MemberController.php:1100`) looks up the
  recipient by username with no status check; `checkout()` (`:1388`) builds the
  payment-method list including `ewallet`; `placeOrder` (`:1423`) has the e-wallet
  branch. The wallet balance chip exists only in the dashboard topbar.
- **Profile:** `profile()` `:20`, `saveProfile()` `:28`; the "Payout Information"
  section (GCash, Maya, USDT TRC20/BEP20) is in `views/member/profile.php`;
  `User::updateProfile` applies an allow-list — **verify in this phase how it
  treats keys that are absent** (it must leave them untouched).
- **Dashboard pending banner** `views/member/dashboard.php:27-44` (removed in P11).
- **Admin labels:** `views/admin/dashboard.php:33` ("Pending Activation"),
  `views/partials/sidebar_admin.php` (pending badge), `views/admin/users.php`.
- R4: extract each decision into a pure function so the harness can test it.
- Neutral customer copy only (R13).

### Scope
**In:** seats (D18), placement (D19), wallet (D20), profile split, activation UX,
admin labels, grandfathering runbook, Gate A.
**Out:** guests; the guest checkout's own behaviour (P8).

### Tasks

**4.1 Seats (D18).**
- `seatsRemaining()`: when `customer_zone_enabled()`, count
  `WHERE role = 'member' AND status <> 'pending'`.
- `User::register()` (`:13`): the seat check applies only when the new account
  takes a seat: `$takesSeat = !(customer_zone_enabled() && !empty($data['pending']));`
  then `if ($takesSeat && isSeatLimitReached()) …`.
- New pure function `activation_block_reason(array $user, bool $zoneOn, bool $seatsFull): ?string`
  in `models/Access.php`; `doActivate` calls it and flashes + redirects to
  `/?page=activate` when it returns a message ("Partner seats are full right now.
  Please contact support.").
- Admin copy: label seat counters "Partner seats".
- **Documented limitation:** `index.php:254-257` still blocks the register pages
  outright when partner seats are full.

**4.2 Placement (D19).**
- New pure function `registration_places_member(bool $isFreeMode, bool $registrarHasBinary, bool $hasUpline, bool $zoneOn): bool`
  (zone ON and free mode → `false`; otherwise today's expression
  `($forceReferralAnchor && $hasUpline) || ($registrarHasBinary && $hasBinary)`).
  `doRegister` uses it for `$requirePlacement` (`:387`).
- `showRegister`: when the zone is ON and `$isReferralMode`, set
  `$referralDefers = true` and skip the upline prefill, so the page says placement
  happens at activation.
- Existing placed customers are untouched.

**4.3 Wallet (D20).**
- Pure `ewallet_recipient_allowed(array $recipient, bool $zoneOn): bool` (false for
  a customer when the zone is ON). `doEwalletTransfer` rejects with the generic
  "Recipient not found." (do not reveal why).
- Pure `payment_methods_for(array $user, bool $zoneOn): array` — customers get the
  four manual methods, partners all five. `checkout()` uses it and passes
  `$allowEwallet` to the view, which hides the e-wallet option and balance when
  false; `placeOrder` rejects `ewallet` for customers server-side.

**4.4 Profile.**
- Pure `profile_payout_keys(): array` (the GCash / Maya / USDT field names).
  `saveProfile` (`:28`): for a customer with the zone ON, `unset` those keys from
  `$data` before calling `User::updateProfile`. If `updateProfile` writes absent
  keys as empty, change it to **skip absent keys** and cover that with a test.
- `profile.php`: wrap the Payout Information section in
  `<?php if (!(customer_zone_enabled() && Auth::isCustomer())): ?> … <?php endif; ?>`.

**4.5 Activation UX.** `activate.php` copy for customers in neutral wording: what
activation is, how to obtain a registration code (text supplied by the owner), and
that it unlocks the dashboard. Entry points already exist (account home card,
storefront topbar button). `doActivate`'s success redirect stays the dashboard.

**4.6 Admin labels.** Helper `member_status_label(string $status): string`
(`pending` → "Customer" when the zone is ON, otherwise today's label). Use it at
`views/admin/dashboard.php:33`, the admin sidebar badge, and the status filter and
cells in `views/admin/users.php`.

**4.7 Grandfathering runbook** (run before Gate A, using the P0 audit counts):
(a) customers who sponsor others keep their sponsor links — confirm nothing in
activation bonuses depends on the sponsor being active; (b) customers holding
wallet funds are activated or have funds moved by an admin, and the action is
logged; (c) optionally show existing customers a one-time notice on their first
login after Gate A ("Your orders and account are now in My Account").

### Tests
**`tests/CustomerZoneRulesTest.php`** *(new)*:
- Seats: zone OFF counts every member; ON excludes pending; boundary at the limit; `User::register` of a pending account with seats full succeeds when ON and throws when OFF; a paid registration is blocked when full in both modes.
- `activation_block_reason`: seats full + zone ON → message; zone OFF → null; not pending → existing behaviour.
- `registration_places_member` — full truth table (3 booleans × zone flag).
- `ewallet_recipient_allowed`; `payment_methods_for` (customer vs partner vs admin, zone on/off).
- `User::updateProfile` leaves absent keys untouched; payout columns of a customer survive a `saveProfile`-shaped update.
- `member_status_label` zone on/off.
- `ViewRenderTest`: `profile.php` for a customer (store shell) has no "Payout Information"; for a partner it does; `checkout.php` for a customer has no e-wallet option.

### Acceptance criteria
- `php -l`, `php tests/run.php`, `php tests/predeploy.php` green; **flag-OFF snapshot diff against `p0` empty**.
- Flag ON in staging, manual matrix (Appendix F): a customer completes **shop → cart → checkout (manual payment) → order → proof upload → activation by code** without ever seeing the dashboard; the dashboard, earnings, network, payouts and Send Money are unreachable; a partner's experience is unchanged; activation with seats full shows the message; a free registration from a referral link creates an **unplaced** customer.

### Gate A — enabling `customer_zone_enabled` in production
Checklist: P0–P4 merged and deployed dark; audits handled per the runbook;
support briefed; Appendix F matrix passed on staging with the flag ON for at
least 3–5 days; privacy/copy reviewed; rollback rehearsed (flag OFF restores
today's behaviour); a low-traffic window chosen. Enable, then watch login errors,
redirect loops and support tickets for 48 hours.

### Rollout and rollback
Flag-controlled. Rollback = set the flag OFF; customers see the dashboard again.
No data to undo.

### Hand-off
The customer zone is feature-complete and may be live. Seats count partners;
customers are never placed; wallet and payout features are partner-only.

---

## Phase 5 — Shop context and guest cart layer (backend only)

### Goal
Make the shop templates context-aware (member or guest) and add the token-cart
model, cookie helpers and cron cleanup. **No guest routes or UI yet.**

### Depends on
P4 (customer zone complete); P1 (schema, `db_rate_limit_*`, cookie-related flags).

### Context you need
- **`Cart` model** (`models/Cart.php`): only `getActive`, `getOrCreate` and
  `itemCountForMember` are member-keyed. Everything else already takes a `$cartId`:
  `addItem`, `getItems`, `getTotals`, `updateItemQuantity` (zero quantity removes
  the line, `:84`), `removeItemById`, `validateStock`, `refreshPrices`,
  `markConverted`, `abandon` (`:60`). Carts hold no stock; `addItem` throws
  `InvalidArgumentException` on over-stock; `Product::availableStock($productId)`
  is `models/Product.php:89`.
- **`ShopOrder::createFromCart`** (`models/ShopOrder.php:687`) refuses to run
  unless `Cart::getActive($memberId)` is the given cart, adopts an open
  transaction (`$ownsTxn`), and dedupes on the unique `idempotency_key`.
- **Template coupling** (coarse grep): `shop.php` ≈ 5 of 159 lines (add form
  `:74-75`, cart link, `Cart::getItems`); `cart.php` ≈ 7 of 225; `cart_offcanvas.php`
  ≈ 13 of 289 — early return when signed out (`:8`), `Cart::getActive(Auth::id())`
  (`:9`), route names (`:189-258`), auto-open on `?cart=1` (`:274-280`);
  `views/partials/footer.php:13` includes the drawer on every page.
- The member shop adds with a plain POST that redirects to `?page=shop&cart=1`.
- `link_to($page, $params)` is `core/helpers.php:229`.
- `carts.updated_at` moves only when the `carts` row itself is written; changes to
  `cart_items` do not touch it.
- **Cron** (`cron/shop_expiry.php`): loads only config + helpers + the models
  autoloader (R9); leg 1 (expire overdue) ≈ `:96`, leg 2 (auto-complete) ≈ `:110`,
  then the overdue-hold alert, then the summary ≈ `:124`.
- Cookie facts: the app may live in a sub-folder (`APP_URL` path); use
  `APP_ENV === 'production'` for the `secure` flag; `setcookie()` with an options
  array needs PHP ≥ 7.3.
- R1 (positional placeholders, `rowCount` = matched rows), R9, R10.

### Scope
**In:** `shop_ctx` / `shop_link`; making `shop.php`, `cart.php`,
`cart_offcanvas.php` context-aware; `Cart` token methods; cookie helpers; cron
leg 3; tests. **Out:** `GuestShopController`, routes, guest topbar, gates.

### Tasks

**5.1 Context helpers** *(core/helpers.php)*:

```php
function shop_ctx(): string { return ($GLOBALS['SHOP_CTX'] ?? '') === 'guest' ? 'guest' : 'member'; }

function shop_link(string $name, array $params = []): string
{
    static $map = [   // name => [member route, guest route]
        'shop'             => ['shop',             'store'],
        'cart'             => ['cart',             'store_cart'],
        'checkout'         => ['checkout',         'store_checkout'],
        'add_to_cart'      => ['add_to_cart',      'store_add_to_cart'],
        'update_cart_item' => ['update_cart_item', 'store_update_cart_item'],
        'remove_cart_item' => ['remove_cart_item', 'store_remove_cart_item'],
        'place_order'      => ['place_order',      'store_place_order'],
    ];
    return link_to($map[$name][shop_ctx() === 'guest' ? 1 : 0], $params);
}
```

**5.2 Template edits.** In `shop.php`, `cart.php` and `cart_offcanvas.php` replace
each `link_to('<name>')` for the seven names above with `shop_link('<name>')`.
In the drawer, replace the early return with
`if (!Auth::check() && shop_ctx() !== 'guest') return;` and load its cart through
a new helper `shop_cart_row(): ?array` (member: `Cart::getActive(Auth::id())`;
guest: `Cart::getActiveByToken(guest_cart_token())`). **Member output must stay
byte-identical.**

**5.3 `Cart` additions** *(new methods; every statement uses positional `?`)*:
- `getActiveByToken(string $token): ?array` —
  `SELECT * FROM carts WHERE guest_token = ? AND member_id IS NULL AND status = 'active' LIMIT 1`.
- `getOrCreateForToken(string $token): array` — select; otherwise
  `INSERT INTO carts (member_id, guest_token, guest_started_at, status) VALUES (NULL, ?, NOW(), 'active')`;
  on a duplicate-token error re-select.
- `touch(int $cartId): void` — `UPDATE carts SET updated_at = NOW() WHERE id = ?`.
- `itemCountForToken(string $token): int`.
- `claimFor(int $cartId, string $token, int $memberId): bool` —
  `UPDATE carts SET member_id = ?, guest_token = NULL, updated_at = NOW() WHERE id = ? AND guest_token = ? AND member_id IS NULL AND status = 'active'`;
  return `rowCount() === 1`.
- `mergeInto(int $memberId, string $token): array` — returns
  `['merged' => int, 'clamped' => int, 'dropped' => string[], 'memberHadItems' => bool]`
  (`memberHadItems` is true when the member's cart already had lines before the
  merge; P6 uses it to choose between the cart page and checkout). Algorithm: load the
  guest cart (none → zeros); `$member = Cart::getOrCreate($memberId)`; for each
  guest line compute `$want = existing member qty + guest qty` and
  `$take = min($want, Product::availableStock($productId))`; if `$take <= existing`
  the line is dropped (record the product name); otherwise upsert the quantity to
  `$take` and count it as clamped when `$take < $want`; finally delete the guest
  cart (lines cascade). Idempotent: a second call finds no guest cart.
- `purgeIdleGuestCarts(int $ttlDays): array` — (1) select ids of
  `member_id IS NULL AND status = 'active' AND updated_at < NOW() - INTERVAL ? DAY`,
  delete their `cart_items`, set them `abandoned`; (2) delete
  `member_id IS NULL AND status = 'abandoned' AND updated_at < NOW() - INTERVAL 90 DAY`.
  Returns `['abandoned' => n, 'purged' => n]`.

**5.4 Cookie helpers** *(core/helpers.php; no `Auth`, no session — R9)*:
`new_guest_token()` = `bin2hex(random_bytes(32))`;
`guest_cart_token()` returns the `guest_cart` cookie only if it matches
`/^[a-f0-9]{64}$/`, else `''`; `guest_cart_cookie_set($token, $ttlDays)` and
`guest_cart_cookie_clear()`; `sponsor_cookie_get()` (valid per `is_valid_username()`
or `''`), `sponsor_cookie_set($username)` (30 days) and `sponsor_cookie_clear()`.
Options for every cookie: `path` = the path component of `APP_URL` (or `/`),
`secure` = `APP_ENV === 'production'`, `httponly` = true, `samesite` = `Lax`.

**5.5 Cron leg 3** in `cron/shop_expiry.php`, after leg 2 and before the
overdue-hold alert, inside the same try/catch style as the other legs: call
`Cart::purgeIdleGuestCarts((int) setting('guest_cart_ttl_days', '14'))`, then
`DELETE FROM rate_limits WHERE window_start < ?` (now minus two days), and write one
log line with the counts in the script's existing format.

### Tests
**`tests/GuestCartTest.php`** *(new)*, plus render parity:
- Token cart isolation: A cannot read or change B's cart; an empty or malformed token returns `null`.
- `Cart::addItem` / `validateStock` / `getTotals` work on a guest cart id and enforce stock.
- `claimFor`: succeeds once and nulls the token; a second call, a wrong token and a claimed cart all return `false`; afterwards `Cart::getActive($memberId)` returns that cart.
- `mergeInto`: increments an existing line, clamps to stock, drops a line with no stock, creates the member cart when absent, reports `memberHadItems` correctly, is idempotent, deletes the guest cart.
- `purgeIdleGuestCarts`: idle → `abandoned` and emptied; old abandoned rows purged; recent and member carts untouched.
- `guest_cart_token()` accepts a 64-hex value and rejects everything else.
- **Render parity:** render `shop.php`, `cart.php` and the drawer in the member context (output equals the P0 snapshot) and in the guest context (`$GLOBALS['SHOP_CTX'] = 'guest'`, `SHOP_SHELL = 'store'`) with no warnings; assert guest output links to `store_*` routes.

### Acceptance criteria
- `php -l`, `php tests/run.php`, `php tests/predeploy.php` green.
- **Flag-OFF snapshot diff against `p0` is empty** (member templates unchanged).
- Cron dry-run on staging purges a seeded idle guest cart and logs the counts.
- No route, controller or visible page was added.

### Rollout and rollback
Deploy dark. Roll back by reverting the PR; the cron leg is harmless if reverted.

### Hand-off
Templates can render for a guest; a tested token cart (create, claim, merge,
purge) and cookie helpers exist; nothing exposes them yet.

---

## Phase 6 — Guest shop and cart  ·  **Gate B**

### Goal
Ship the engagement feature on its own: anonymous visitors browse the shop and
fill a cart in the storefront shell, with the cart following them into either
way of joining. **No accounts are created in this phase.**

### Depends on
P5 (shop context, token cart, cookie helpers, cron cleanup) and P2 (storefront shell).

### Context you need
- **Router:** role `any` applies no check; the route table starts at
  `index.php:272`. New routes are role `any`; each action does its own signed-in
  redirect (Access cannot do it, because `any` is unchecked).
- **Context switches:** `shop_ctx()` reads `$GLOBALS['SHOP_CTX']`; `shop_shell()`
  reads `$GLOBALS['SHOP_SHELL']` (P5 / P2). A guest request sets both to
  `'guest'` / `'store'` before rendering any shared template.
- **Member cart handlers to mirror** (`MemberController.php`): `shop` `:1232`,
  `cart` `:1277`, `addToCart` `:1297`, `requireOwnCart` `:1329` (defect C3: the
  cart is resolved server-side, never taken from the client), `updateCartItem`
  `:1339`, `removeCartItem` `:1364`. Behaviours: AJAX is detected by
  `X-Requested-With: XMLHttpRequest`; add answers `{ok, count}` or
  `{ok:false, error}` with HTTP 400; update/remove answer `{ok, subtotal, count}`
  or `{ok:false, error}` with HTTP 400; a non-AJAX add flashes and redirects to
  `?page=shop&cart=1` (which auto-opens the drawer); quantity is at least 1;
  `Cart::addItem` throws `InvalidArgumentException` ("Insufficient stock…") and
  writes nothing. `json_response()` is `core/helpers.php:369`.
- **Login / registration** (`AuthController.php`): `showLogin` `:11`, `doLogin`
  `:16` (success redirect `:44`), `doRegister` `:240` (a guest's self-registration
  signs in with `Auth::login($newUser)` near `:455`). The login form is
  `views/auth/login.php` (CSRF field plus username and password inputs).
- **Landing page:** `frontend/index.php:1773-1774` holds the `?page=login` and
  `?page=register` links; the file loads `config/db.php`, `core/helpers.php` and
  `models/Package.php` only — **no `Auth`, no session** (R9).
- **Referral link UI:** `views/member/genealogy.php:267` shows
  `?page=register&sponsor=<username>&ref=1`.
- **Sponsor cookie rules (D15):** last click wins; cookie value must pass
  `is_valid_username()` **and** name an existing user; 30 days; the cookie marks
  the sponsor as coming from a referral link.
- **Gate tier 1 (D7′):** `shop_enabled` **and** `guest_shop_enabled`.
- `CSRF`: guests have tokens (`index.php:425`); `csrf_verify()` dies with a plain 403.
- `REMOTE_ADDR` is the only IP source (P0 verdict decides whether it is trustworthy).
- R1, R3, R4, R9.

### Scope
**In:** gate helpers; `GuestShopController` (index, add, cart, update, remove);
five routes; guest branch of the storefront topbar; the cart page's checkout
button states; sponsor-cookie capture; cart continuity at login and registration;
landing and genealogy links; tests; QA; Gate B.
**Out:** checkout and place order (P8); the registration refactor (P7).

### Tasks

**6.1 Gate helpers** *(core/helpers.php; no `Auth`, no session — R9)*:

```php
/** Tier 1 — guest browsing and cart. */
function guest_shop_open(): array
{
    if (setting('shop_enabled', '1') !== '1')       return ['open' => false, 'reason' => 'shop_closed'];
    if (setting('guest_shop_enabled', '0') !== '1') return ['open' => false, 'reason' => 'disabled'];
    return ['open' => true, 'reason' => 'open'];
}

/** Tier 2 — completed in P8. Until then it reports closed, so the cart shows "Join to continue". */
function guest_checkout_open(): array
{
    $t1 = guest_shop_open();
    return $t1['open'] ? ['open' => false, 'reason' => 'checkout_disabled'] : $t1;
}

/** One place to read the client IP; P9 may harden it (trusted proxy). */
function client_ip(): string { return (string) ($_SERVER['REMOTE_ADDR'] ?? ''); }
```
If the P0 check found a reverse proxy, implement the trusted-header logic in
`client_ip()` **now**, before Gate B (it is what the cart-creation cap keys on).

**6.2 `controllers/GuestShopController.php`** *(new)*. Every action starts with
the same prologue, in this order:
1. `$GLOBALS['SHOP_CTX'] = 'guest'; $GLOBALS['SHOP_SHELL'] = 'store';`
2. **Signed in?** Merge any leftover guest cart
   (`Cart::mergeInto(Auth::id(), guest_cart_token())`, then `guest_cart_cookie_clear()`)
   and redirect to the member equivalent: `store`→`shop`, `store_cart`→`cart`;
   POST add/update/remove → `cart`.
3. **Sponsor capture** (before the gate, so a referral link is never lost even
   while the storefront is closed): if `$_GET['sponsor']` passes `is_valid_username()`
   and `User::findByUsername()` finds it → `sponsor_cookie_set($username)` (last
   click wins).
4. **Tier-1 gate:** `shop_closed` → render the page in place with "The shop is
   currently closed."; `disabled` → redirect to the landing page (`/`).

Actions (mirror the member handlers listed above; prepare the *same view
variables* by reading each member method):
- `index()` — `Product::active()`, the token cart's items and count,
  `$pageTitle = 'Shop'`, `require 'views/member/shop.php'`.
- `cart()` — token cart; `Cart::refreshPrices` / `validateStock` warnings;
  `require 'views/member/cart.php'`; empty state when there is no cart.
- `addToCart()` (POST) — `csrf_verify()`; use the cookie token, or create one
  (`new_guest_token()` + `guest_cart_cookie_set($token, $ttlDays)`) **only if the
  cart-creation cap allows**: `db_rate_limit_check('guest_cart_create:'.client_ip(), 20, 3600)`
  then `db_rate_limit_hit(...)` when a cart is actually created. Then
  `Cart::getOrCreateForToken`, `Cart::addItem`, `Cart::touch`. Respond with JSON
  for AJAX, or flash and redirect to `?page=store&cart=1`.
- `updateCartItem()`, `removeCartItem()` (POST) — the cart is **always resolved
  from the cookie token**; the posted item id is paired with *that* cart's id; the
  client never supplies a cart id; the token is never read from a URL or body.
  `Cart::updateItemQuantity` / `removeItemById` already scope by cart id. Then
  `Cart::touch`; JSON or redirect to `store_cart`.

**6.3 Routes** — five entries in `index.php`, role `any`: `store`,
`store_add_to_cart`, `store_cart`, `store_update_cart_item`, `store_remove_cart_item`.

**6.4 Guest topbar.** In `views/partials/store_topbar.php` add a signed-out
branch: **Log in** (`link_to('login')`), **Become a partner**
(`link_to('register')` with `&ref=1&sponsor=<cookie sponsor>` appended when
`sponsor_cookie_get()` is non-empty) and the cart button, with the badge count from
`Cart::itemCountForToken(guest_cart_token())`. No user dropdown. The signed-in
branch is unchanged.

**6.5 Cart page button states.** In `cart.php`, when `shop_ctx() === 'guest'`,
decide the button from `guest_checkout_open()`: open → the normal Checkout button
to `shop_link('checkout')`; **any other reason** → **"Join to continue"**
linking to `?page=register` (with the sponsor parameters above) and a line
"Your cart will be kept." Member behaviour is unchanged.

**6.6 Optional sponsor capture on the register page.** In `showRegister`
(`AuthController.php:131`), when `$_GET['sponsor']` is valid and exists, also
call `sponsor_cookie_set()`, so a referred visitor who wanders into the store
keeps their sponsor.

**6.7 Continuity (D9).**
- `views/auth/login.php`: a hidden `next` input beside the CSRF field. `showLogin`
  (`:11`) accepts `$_GET['next']` **only if it is in the whitelist
  `['checkout', 'cart']`** and echoes it with `e()`.
- `doLogin`: after `Auth::login($user)`, if `guest_cart_token()` is non-empty call
  `Cart::mergeInto((int)$user['id'], guest_cart_token())` and
  `guest_cart_cookie_clear()`. Destination: admin → admin area; else if `next` is
  whitelisted — `cart` when the merge clamped or dropped anything or the member
  already had items, otherwise `checkout`; else `landing_url_for($user)`. Flash
  what was clamped or dropped.
- `doRegister`, self-registration branch, immediately after `Auth::login($newUser)`:
  merge the guest cart, clear the cookie, and append "Your cart items were kept."
  to the success flash. The destination stays `landing_url_for($newUser)`.
- This is what makes **"Join to continue"** work: the cart arrives in the new
  account, whether it is a free customer or a package-paying partner.

**6.8 Links.** `frontend/index.php:1773-1774`: add a **Shop** link
(`<?= $base ?>/?page=store`) rendered only when `guest_shop_open()['open']`.
`views/member/genealogy.php:267`: add a "Shop link" copy button beside the
referral link: `?page=store&sponsor=<the member's username>`.

### Tests
- `GuestCartTest` (extend): `Cart::removeItemById` and `updateItemQuantity` refuse an item id that belongs to a *different* cart (ownership); a full add → update → remove sequence on a guest cart id.
- `guest_shop_open()` matrix: shop on/off × `guest_shop_enabled` on/off → the four verdicts.
- `ViewRenderTest`: `store_topbar.php` signed-out; `cart.php` in the guest context for each tier-2 verdict (open → Checkout button; every other reason → "Join to continue" linking to `register`).
- `CustomerZoneAccessTest` route inventory: the five `store*` routes exist with role `any`.
- Manual QA and a `curl` smoke script (below).

**Smoke script outline** (`php -S 127.0.0.1:8080`, curl cookie jar): GET `store`;
extract the CSRF token; POST `store_add_to_cart` (assert the `guest_cart` cookie is
set with `HttpOnly` and `SameSite=Lax`); GET `store_cart` (assert the line);
POST `store_update_cart_item`; POST `store_remove_cart_item`; add again; log in
with the same cookie jar and assert the cart moved to the member and the guest
cookie is gone; repeat with registration; assert the 21st cart creation within an
hour from one IP is refused.

### Acceptance criteria
- `php -l`, `php tests/run.php`, `php tests/predeploy.php` green; **flag-OFF snapshot diff against `p0` empty** (the landing page keeps hiding the Shop link).
- Flag ON in staging (Appendix F): a visitor browses, adds, edits and removes; stock errors show; the badge and drawer behave like the member shop; the cookie carries `HttpOnly` / `SameSite=Lax` / the right path; the cart survives a browser restart within the TTL; "Join to continue" reaches the register page with the cart merged afterwards; "Log in & continue" merges and lands on cart/checkout per the rule; a signed-in user opening `store*` is redirected to the member page after a merge; a closed shop shows the closed message.
- No account or order is created by anything in this phase.

### Gate B — enabling `guest_shop_enabled` in production
Checklist: P0–P6 merged; the `REMOTE_ADDR` verdict handled (`client_ip()`); the
storefront stays `noindex` unless the owner decided otherwise; the privacy notice
discloses the cart cookie and, separately, the sponsor cookie; monitoring queries
ready (guest carts created per day, cap hits, abandoned count); staging soak with
the flag ON; rollback rehearsed (flag OFF hides the Shop link and redirects
`store*` to the landing page). Enable, then watch cart-creation volume and cap hits
for 48 hours.

### Rollout and rollback
Flag-controlled. Rollback = flag OFF; guest carts simply age out through the cron leg.

### Hand-off
A live guest storefront with a persistent cart, sponsor attribution, and carts that
follow visitors into any account. The checkout button reads "Join to continue".

---

## Phase 7 — Registration core refactor (behaviour-preserving)

### Goal
Make `User::register()` usable inside an outer transaction, split out its
post-commit work, and extract the shared validator — **with zero change in
behaviour for every existing registration path.**

### Depends on
P6. (Technically independent of the guest work, but it is the riskiest edit in the
plan, so it is isolated and done only once the guest shop is stable.)

### Context you need
- **`User::register()`** (`models/User.php:11-141`):
  `isSeatLimitReached()` check at `:13` (zone-aware after P4);
  `$pdo->beginTransaction()` near the top; sponsor / upline / slot validation (the
  slot check is an unlocked `COUNT(*)` — there is no unique index on
  `(binary_parent_id, binary_position)`); `password_hash(…, PASSWORD_BCRYPT, ['cost' => 12])`
  **inside** the transaction; the `INSERT` of `username, password_hash, package_id,
  reg_code_id, reg_payment_method, reg_paid_by, sponsor_id, binary_parent_id,
  binary_position, status` (`:77-95`); `$pdo->commit()` (`:117`);
  `catch (\Exception $e) { $pdo->rollBack(); throw $e; }` (`:118-121`); then
  **post-commit** work (`:123-138`): `Commission::processBinaryPlacement($newId,
  $parent, $pos, $status !== 'pending')` and, for non-pending members, the referral
  bonuses. For a pending member this does not increment leg counts and pays
  nothing.
- **The pattern to copy:** `ShopOrder::createFromCart()` and `ShopOrder::transition()`
  start with `$ownsTxn = !$pdo->inTransaction();` and only begin/commit/roll back
  when they own the transaction.
- **`doRegister`'s common validation** (`AuthController.php:240`): username format
  (`is_valid_username`) — "Username must be 3–40 characters, letters/numbers/underscore, start with a letter."; uniqueness (`User::usernameExists`) —
  "Username is already taken."; password length ≥ 8 — "Password must be at least 8 characters."; confirm — "Passwords do not match."; sponsor exists (`User::findByUsername`) — "Sponsor username not found." Read the exact strings and order in the source and keep them verbatim.
- `users.username` is `UNIQUE` (the final authority).
- R1 (positional placeholders), R7 (`core/Commission.php` frozen).

### Scope
**In:** transaction adoption, `password_hash` / `registered_via` parameters,
`afterRegister()`, `validateNewMember()`, `doRegister` using the validator, a
regression suite. **Out:** guest checkout; any change to commission logic.

### Tasks

**7.1 Transaction adoption in `User::register()`.**

```php
$pdo     = db();
$ownsTxn = !$pdo->inTransaction();
if ($ownsTxn) { $pdo->beginTransaction(); }
try {
    // … existing body, unchanged, except:
    $hash = $data['password_hash'] ?? password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    // INSERT gains: registered_via = $data['registered_via'] ?? 'register'
    $newId = (int) $pdo->lastInsertId();
    if ($ownsTxn) { $pdo->commit(); }
} catch (\Exception $e) {                 // keep \Exception: the original did not catch \Error
    if ($ownsTxn && $pdo->inTransaction()) { $pdo->rollBack(); }
    throw $e;
}
if ($ownsTxn) { self::afterRegister($newId, $data, $status); }
return $newId;
```
When joined to an outer transaction, `register()` neither commits nor rolls back;
the **caller** commits, then calls `afterRegister()`.

**7.2 `User::afterRegister(int $newId, array $data, string $status): void`**
*(new)* — the post-commit block (`:123-138`) moved verbatim, reading
`$data['binary_parent_id']`, `$data['binary_position']`, sponsor and package from
`$data`. Behaviour must be identical: same calls, same arguments, same order.

**7.3 `User::validateNewMember(array $in, bool $requireConfirm = true): array`**
*(new)* — returns `['errors' => string[], 'sponsor' => ?array]`, running the
checks above in the same order with the same messages; `$requireConfirm = false`
skips the "Passwords do not match." check (guests have no confirm field).
`doRegister` replaces its inline block with a call and keeps its current
flash-and-redirect behaviour for the first error.

### Tests
**`tests/RegistrationRefactorTest.php`** *(new)* — regression first:
- `register()` for each existing mode: registration code (paid, active), e-wallet, free unplaced, free placed under a binary sponsor (zone OFF), seat limit full (zone OFF) → the same exception text, duplicate username → the same message; assert the stored row fields (`status`, `reg_payment_method`, `package_id`, `sponsor_id`, `binary_*`).
- **Transaction adoption:** open an outer transaction, call `register()`, assert `inTransaction()` is still true; roll back → the user row is gone; commit → present. Called without an outer transaction it commits by itself and still runs `afterRegister`.
- `password_hash` parameter is stored as given; `registered_via` is stored when supplied and defaults to `register`.
- `afterRegister`: a pending member produces no leg-count change; an active member with a binary parent still increments counts, exactly as before (compare against a pre-refactor expectation).
- `validateNewMember`: golden strings for every error; `requireConfirm = false` skips the mismatch error; a valid input returns the sponsor row.

### Acceptance criteria
- `php -l`, the whole suite and `predeploy` green — **`core/Commission.php` unchanged**.
- Staging smoke: register by code, by e-wallet, free from a referral link, and as a signed-in registrar; each produces the same rows and redirects as before the phase.
- Code review confirms `doRegister` still shows the same messages in the same order.

### Rollout and rollback
Back up the database and deploy in a quiet window; watch registration errors for
24 hours. Roll back by reverting the PR (no schema is involved).

### Hand-off
`User::register()` can run inside an outer transaction and accepts a precomputed
hash and a provenance value; `validateNewMember()` is shared. All existing
registrations behave exactly as before.

---

## Phase 8 — Guest checkout with atomic registration

### Goal
Add the guest checkout page and the **atomic register-and-order** step: one
transaction that creates the customer account, claims the cart and places the
order. The orchestration lives in a testable service; the controller stays thin.

### Depends on
P7 (joinable `register()`, `validateNewMember()`), P6 (guest shop, merges), P5
(token cart, `claimFor`, limiter), P4 (D18 seats and D19 placement already in force).

### Context you need
- **Member `placeOrder`** (`MemberController.php:1423`): requires billing name,
  recipient name, delivery address and `terms === '1'`; uses the `idem` token;
  idempotency key `sha1(Auth::id() . '|' . $cart['id'] . '|' . $_POST['idem'])`;
  wraps `ShopOrder::createFromCart` in a transaction; catches
  `InvalidArgumentException | RuntimeException` (shows the message) and
  `Throwable` ("Could not place the order. Please try again."); saves the address
  **after** commit through `maybeSaveCheckoutAddress` (`:1557`, best effort);
  success flash "Order placed. Upload your payment proof to continue." and
  redirect to `?page=shop_order&id=`.
- **`ShopOrder::createFromCart($memberId, $cartId, $addr, $method, $key)`**
  (`models/ShopOrder.php:687`): adopts an open transaction; requires
  `Cart::getActive($memberId)` to be that cart; re-prices; re-validates stock;
  inserts the order `pending` with `payment_deadline = NOW() + shop_payment_deadline_hours`
  (default 24 h), terms `v1`, items `reserved` (**stock is held from here**),
  calls `markConverted`, logs the event `null → pending` (`order_placed`); dedupes
  on the unique `idempotency_key`. `$addr` keys: `billing_name`, `billing_phone`,
  `shipping_name`, `shipping_phone`, `shipping_address`, `shipping_city`,
  `shipping_province`, `shipping_postal`, `notes_member`. `PAYMENT_METHODS`
  still lists `ewallet`, so the guest path must refuse it itself.
- **Member checkout form** (`views/member/checkout.php`): delivery card `:64-155`,
  payment card `:156-201`, order-summary card `:202-237`, address-picker partial
  `:241` (standalone, no `Auth`), inline script `:242-391` (single-submit guard,
  terms gate). Fields: `payment_method`, `billing_name`, `billing_phone`,
  `shipping_name`, `shipping_phone`, `shipping_address`, `shipping_region`,
  `shipping_province`, `shipping_city`, `shipping_barangay`, `shipping_postal`,
  four `shipping_*_code`, `notes_member`, `terms`, `idem`, `save_address`,
  `address_label`.
- **Remember-input helpers** (`core/helpers.php:587`, `:614`) are whitelists; a
  password can never be stashed by them.
- **Registration inputs** `User::register()` needs for a free account (read the
  array `doRegister` builds, `AuthController.php:~430-445`): `username`, `package_id`
  (0), `reg_code_id` (null), `reg_payment_method` (`'pending'`), `reg_paid_by`
  (null), `paid_by_username` (null), `sponsor_id`, `binary_parent_id` (null),
  `binary_position` (null), `pending` (true) — plus, after P7, `password_hash` and
  `registered_via`. `register()` does **not** set `full_name` or `mobile`.
- **Validators:** `is_valid_mobile` (`:362`) — use `normalize_mobile()` first;
  `is_valid_username` (`:357`); the availability endpoint `check_username`
  (`AuthController::ajaxCheckUser`, `:513`) answers `{available, message}`.
- **Session/login:** `Auth::login(array $user)` regenerates the session id and
  sets `user_id/user_role/username`; `redirect()` exits (R4); the flash helper
  carries one string per key, so the welcome card uses `$_SESSION['guest_welcome']`.
- **Order page** `views/member/shop_order.php`: shell lines `:15-18`,
  `render_flash()` near `:20`.
- **Decisions in force:** D1–D4′, D6, D7′, D8, D14; D18 (customers are not seat-gated
  and no seat lock is needed); D19 (the account is **always unplaced**);
  D3′ (one password field, no confirm).
- `MemberAddress::create($memberId, $data)` makes a member's first address the default.
- R1, R3, R4, R8 (new classes live in `models/`).

### Scope
**In:** shared checkout partials; the real tier-2 gate; guest input helpers and
the shared address-save helper; the `GuestCheckout` service; two controller
actions and two routes; the guest checkout view; the welcome card; abuse caps;
tests; QA. **Out:** CAPTCHA, SMS, deferred password.

### Tasks

**8.1 Extract shared checkout partials** from `views/member/checkout.php`:
`views/partials/checkout_summary.php` (summary card, `:202-237`, including the
"Total is what you pay. No shipping or service fee." line),
`checkout_payment.php` (payment card, `:156-201`; parameters `$methods`,
`$allowEwallet`, `$walletBalance`) and `checkout_submit_js.php` (script,
`:242-391`). The member template now `require`s them. **The member checkout
snapshot must stay identical.**

**8.2 Tier-2 gate (replaces the P6 stub):**

```php
function guest_checkout_open(): array
{
    $t1 = guest_shop_open();
    if (!$t1['open']) return $t1;
    if (setting('customer_zone_enabled', '0') !== '1')    return ['open' => false, 'reason' => 'zone_disabled'];
    if (setting('guest_checkout_enabled', '0') !== '1')   return ['open' => false, 'reason' => 'checkout_disabled'];
    if (setting('free_registration_enabled', '1') !== '1') return ['open' => false, 'reason' => 'registration_closed'];
    return ['open' => true, 'reason' => 'open'];
}
```
**Seats are not a condition** (D18). Any non-open reason makes the cart show "Join to continue".

**8.3 Helpers** *(core/helpers.php)*:
`guest_checkout_old_input()` and `guest_checkout_remember_input(array $post)` —
whitelist = the member helpers' fields plus `shipping_name`, `shipping_phone`,
`username`, `sponsor_username`; **never `password`**.
`save_checkout_address(int $uid, array $addr, bool $always): void` — extracted from
`maybeSaveCheckoutAddress` (`:1557`): best effort, swallows failures, honours the
member form's `save_address` unless `$always`; the member method delegates to it.

**8.4 `models/GuestCheckout.php`** *(new)* — a service that **never redirects,
flashes, echoes or touches the session**:

```php
final class GuestCheckout
{
    /** Test seam: called inside the transaction right after the user is created. */
    public static ?callable $afterRegisterHook = null;

    /**
     * @return array{ok:true,order_id:int,user_id:int,username:string}
     *       | array{ok:false,code:string,message:string,fields?:array,username?:string}
     * codes: replay, gate_closed, cart_missing, invalid, rate_limited, stock,
     *        username_taken, error
     */
    public static function place(array $in, string $token, string $ip, ?string $refSponsor): array
```

*Pre-transaction (nothing written except the attempt counter):*
1. Honeypot `$in['website']` non-empty → `invalid` (generic).
2. `db_rate_limit_check('guest_place_attempt:'.$ip, 10, 900)` else `rate_limited`; then `db_rate_limit_hit(...)`.
3. **Replay:** `$key = sha1('guest|' . $token . '|' . $in['idem'])`; if `shop_orders.idempotency_key` already holds it → `replay` with the owner's username. (The cart id cannot be used for the key: the claim below nulls the token.)
4. `Cart::getActiveByToken($token)`; none or empty → `cart_missing`.
5. `guest_checkout_open()` not open → `gate_closed`.
6. **Validate:** `shipping_name` (full name), `normalize_mobile($in['shipping_phone'])` ≠ `''`, `shipping_address`, `terms === '1'`, `payment_method` ∈ `gcash|maya|usdt_trc20|usdt_bep20` (anything else, including `ewallet` → `invalid`); account fields through `User::validateNewMember(…, false)`; mirror `shipping_name` / `shipping_phone` into `billing_name` / `billing_phone`. **Resolve the sponsor:** `$refSponsor` if it still exists → else the typed `sponsor_username` if it exists (a typed name that does not exist is an error) → else `User::primaryAdminId()`.
7. **Caps (check only):** `db_rate_limit_check('guest_place_success:'.$ip, 3, 86400)` and `db_rate_limit_check('guest_place_success_phone:'.sha1($mobile), 2, 86400)` else `rate_limited`.
8. `$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])` — **before** the transaction.

*The transaction:*

```php
$pdo = db(); $pdo->beginTransaction();
try {
    Cart::refreshPrices($cartId);
    $errs = Cart::validateStock($cartId);                 // string[]
    if ($errs) { throw new InvalidArgumentException($errs[0]); }
    $uid = User::register([ /* free-account array from Context, plus */
        'password_hash' => $hash, 'sponsor_id' => $sponsorId,
        'pending' => true, 'registered_via' => 'guest_checkout' ]);
    $pdo->prepare('UPDATE users SET full_name = ?, mobile = ? WHERE id = ?')
        ->execute([$fullName, $mobile, $uid]);            // columns register() does not set
    if (self::$afterRegisterHook) { (self::$afterRegisterHook)($uid); }
    if (!Cart::claimFor($cartId, $token, $uid)) { throw new RuntimeException('Your cart is no longer available.'); }
    $orderId = ShopOrder::createFromCart($uid, $cartId, $addr, $method, $key);   // unchanged
    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    // map: SQLSTATE 23000 on users.username → username_taken;
    //      SQLSTATE 23000 on idempotency_key → re-run the replay lookup → replay;
    //      InvalidArgumentException → stock (message); RuntimeException → its message;
    //      anything else → error
}
```
Any exception rolls back everything: no user, no order, the cart is still a guest
cart, stock untouched.

*Post-commit (DB only, in the service):* `User::afterRegister(...)`;
`db_rate_limit_hit('guest_place_success:'.$ip, 86400)` and the per-mobile bucket;
`save_checkout_address($uid, $addr, true)` inside its own try/catch. Return
`ok` with `order_id`, `user_id`, `username`.

**8.5 Controller** — `GuestShopController::checkout()` and `placeOrder()`:
- `checkout()`: prologue (P6); tier-2 gate — if closed, redirect to `store_cart`
  (the page shows "Join to continue"); non-empty cart; `Cart::refreshPrices` and
  `validateStock` (bounce to `store_cart` with the first error); build the view
  variables (`$items`, `$totals`, `$old = guest_checkout_old_input()`, the cookie
  sponsor's name if it exists, the four manual `$methods`); `require 'views/store/checkout.php'`.
- `placeOrder()` (POST): prologue; `csrf_verify()`; if signed in → redirect to
  `shop_orders`; call `GuestCheckout::place(...)`; translate the result:
  `ok` → `Auth::login(User::find($userId))`, `$_SESSION['guest_welcome'] = ['username' => $username]`,
  `guest_cart_cookie_clear()`, `sponsor_cookie_clear()`, flash "Order placed.
  Upload your payment proof to continue.", `redirect('/?page=shop_order&id=' . $orderId)`;
  `replay` → flash "Your order was already placed — sign in as @username to
  continue." and redirect to `login`; `invalid` → `guest_checkout_remember_input($_POST)`,
  flash the messages, redirect to `store_checkout`; `rate_limited` → the generic
  "Too many orders from this connection or number today. Please try again tomorrow
  or log in."; `gate_closed` → redirect to `store_cart`; `cart_missing` → redirect to
  `store`; `stock` → redirect to `store_cart` with the message; `username_taken` →
  remember input and flash "Username is already taken."; `error` → generic message.
  If `Auth::login` somehow fails after commit, redirect to `login` with a flash that
  names the username — the account and order are intact.

**8.6 `views/store/checkout.php`** *(new)*, from top to bottom: a "Already have an
account? **Log in & continue**" strip (`login&next=checkout`); the shared summary
partial; **Delivery** — the Recipient block (`shipping_name` "Full name",
`shipping_phone` "Mobile number") then the existing address-picker partial,
`shipping_address` and postal code; **Your login** — `username` (prefilled by script
from the full name once typed, then checked with `check_username`) and one
`password` input with a show/hide toggle (no confirm field), and the sponsor line
("Referred by @x" read-only when a cookie sponsor exists, otherwise an "I was
referred by someone" link that reveals `sponsor_username`); the shared payment
partial (four manual methods, none pre-selected); a collapsed "Add a note"; the
terms checkbox with the account-creation notice — "Placing this order creates your
free account **@username**…" — and submit; hidden `idem` (`uniqid('', true)`) and a
hidden honeypot `website`. Use `e()` / `js()` correctly (R2). Visible inputs: about
nine (D14).

**8.7 Welcome card** — in `views/member/shop_order.php` directly after
`render_flash()` (`:20`): if `$_SESSION['guest_welcome']` is set, render "Your
account **@username** is ready. Sign in with the password you just chose. You can
track this order any time under My Orders.", then `unset` the key so it shows once.

**8.8 Routes** — `store_checkout` and `store_place_order` in `index.php`, role `any`.

### Tests
**`tests/GuestCheckoutTest.php`** *(new, service level — users created with the
`zt_` prefix and removed afterwards; flags restored with `Fixtures::setting`)*:
- **Happy path:** a `pending` user with `reg_payment_method = 'pending'`,
  `registered_via = 'guest_checkout'`, `sponsor_id` = primary admin,
  `binary_parent_id` and `binary_position` NULL, `full_name` and `mobile` set; the
  cart `converted` with `member_id` set and no token; an order `pending` with the
  idempotency key, lines `reserved` and a `null → pending` event; the address saved
  as the default; `is_customer_user()` true; `landing_url_for()` = account;
  `seatsRemaining()` unchanged with the zone ON (D18).
- **Atomicity:** (a) stock gone → no user, cart still a guest cart, stock untouched; (b) the `$afterRegisterHook` throws → everything rolled back; (c) `claimFor` forced to fail → rolled back.
- **Replay:** second call with the same token and `idem` → `replay`, no second user or order; a replay after the cart was claimed (the lost-response case) also returns `replay`.
- **Username:** an existing username → `username_taken`; a concurrent insert simulated through the hook → mapped to `username_taken`; nothing written either way.
- **Sponsor matrix:** cookie sponsor exists; cookie sponsor deleted (falls back); typed sponsor exists; typed sponsor missing (error); none → primary admin.
- **Forged input:** `payment_method = ewallet` → `invalid`; invalid mobile → `invalid`; honeypot filled → `invalid`; missing terms → `invalid`; mobile normalisation stored as `09…`.
- **Caps:** the fourth success from one IP and the third from one mobile are `rate_limited`; the eleventh attempt in 15 minutes is `rate_limited`; windows reset when time is injected.
- **Gate:** every closed reason → `gate_closed`.
- **No password anywhere:** `guest_checkout_remember_input()` excludes it; no result array contains it.
- `ViewRenderTest`: `views/store/checkout.php` renders clean in the guest context — no e-wallet option, a honeypot, a password input without a confirm field; the member `checkout.php` output is unchanged.

**Smoke script outline** (`php -S` + curl): GET `store`, add a product, GET
`store_checkout` (extract CSRF and `idem`), POST `store_place_order`, follow the
redirect with the same cookie jar, assert the order page shows "Order placed" and
the one-time card, assert the cookie jar now has a session, assert the database
rows, assert a second POST with the same `idem` does not create a second order.

### Acceptance criteria
- `php -l`, the whole suite and `predeploy` green; **flag-OFF snapshot diff against `p0` empty**; the **member checkout snapshot unchanged** after the partial extraction.
- Flag ON in staging with all three flags: the full guest journey works end to end; a customer lands on the order page in the storefront shell; the "Your login" card shows once; later logins land on the account home; the order proceeds through proof upload exactly as a member's; the unpaid order expires at its deadline and the account remains.
- Replays, double submits, lost responses, username collisions, stock races and every cap behave as specified; nothing is written on any failure path.

### Rollout and rollback
Deploy dark. Do **not** enable `guest_checkout_enabled` until P9 is done (Gate C).
Rollback = flag OFF; accounts and orders already created are ordinary rows.

### Hand-off
Guest checkout is complete and tested behind its flag. Remaining: cross-cutting
hardening, then Gate C.

---

## Phase 9 — Hardening and observability  ·  **Gate C**

### Goal
Close the cross-cutting security gaps the new public surface exposes, prove the
abuse controls under load, finalise privacy wording, and open **Gate C**
(`guest_checkout_enabled`).

### Depends on
P8 (guest checkout complete). Gate A must already be open: guest checkout creates
customers.

### Context you need
- **The existing limiter is per session.** `rate_limit_check()`, `rate_limit_hit()`
  and `rate_limit_clear()` keep counters in `$_SESSION['rl']`
  (`core/helpers.php:379-405`); a client that drops its cookie starts with a clean
  counter. The DB-backed limiter (`db_rate_limit_*`, P1) is what guards the guest
  path. **`check_username` has no throttle at all** (`AuthController.php:513`) and
  the guest checkout makes it reachable by anyone.
- **`client_ip()`** (P6) returns `REMOTE_ADDR`. The P0 verdict says whether that is
  the real client address.
- **Sessions:** `index.php:46` calls a bare `session_start()`. `Auth::start()`
  (`core/Auth.php:20-31`) would set `session.cookie_httponly`, `use_strict_mode` and
  (in production) `cookie_secure`, but **nothing calls it**, and it also renames the
  cookie to `mlm_sess`. The Super-Login branch (`index.php:38-50`) starts its own
  session named `mlm_imp` from a URL nonce with `use_strict_mode` deliberately off.
  `Auth::login()` already calls `session_regenerate_id(true)`.
- **Cookies:** `guest_cart` (14 days by default) and `store_sponsor` (30 days),
  both `HttpOnly`, `SameSite=Lax`, `secure` in production (P5).
- Logs: the web layer writes to the PHP error log; cron writes to `cron/logs`.
- Privacy: the Philippine Data Privacy Act of 2012 applies (this plan is not legal
  advice — whoever owns the privacy notice signs the wording off).
- `maintenance_mode` already blocks `store*` for non-admins (`index.php:52`).

### Scope
**In:** session cookie flags, the `check_username` throttle, a trusted-proxy
`client_ip()`, a CAPTCHA hook (interface only), structured logging, privacy copy,
a security review, a load/soak test, Gate C. **Out:** a CAPTCHA provider, SMS.

### Tasks

**9.1 Session cookie flags — without renaming the cookie.** In `index.php`,
immediately before `session_start()` (`:46`) and **only when the request is not an
impersonation request**, set `session.cookie_httponly = 1`,
`session.use_strict_mode = 1`, `session.cookie_samesite = Lax`, and
`session.cookie_secure = 1` when `APP_ENV === 'production'` **and** the request is
HTTPS. Keep the cookie name `PHPSESSID`. Do **not** call `Auth::start()` (R11).
Detect HTTPS from `$_SERVER['HTTPS']` / port 443, and from
`HTTP_X_FORWARDED_PROTO` only when the P0 verdict says that header is trustworthy.

**9.2 Throttle `check_username`.** At the top of `ajaxCheckUser` (`:513`):
`db_rate_limit_check('check_username:'.client_ip(), 30, 900)`; when denied answer
`{available:false, message:'Too many checks. Please wait a moment.'}`; otherwise
`db_rate_limit_hit(...)`. Extract the decision as a pure
`username_check_allowed(string $ip): bool` so it can be tested.

**9.3 Trusted-proxy `client_ip()`.** Only if P0 found a proxy: add settings
`trusted_proxy_header` (e.g. `HTTP_CF_CONNECTING_IP`) to the allow-list, and make
`client_ip(?array $server = null)` return that header's value **only if it passes
`filter_var($v, FILTER_VALIDATE_IP)`**, otherwise `REMOTE_ADDR`. Taking `$server`
as a parameter makes it testable. If P0 found a direct connection, leave it as is
and document that in the decision log.

**9.4 CAPTCHA hook.** Add `captcha_verify(): bool` (returns `true` for now) and call
it from `GuestShopController::placeOrder()` before `GuestCheckout::place()`.
Document the escalation rule — e.g. enable a provider when cap hits exceed an
agreed daily number — but do not choose or build a provider here.

**9.5 Structured logging.** `guest_log(string $event, array $ctx = [])` writes
`error_log('[guest] ' . json_encode(...))` for: `cart_created`, `cap_hit`
(with the bucket name), `place_ok`, `place_replay`, `place_invalid`,
`place_error`. Context contains **hashed** identifiers only (`substr(sha1($ip), 0, 10)`,
never the mobile, username or password).

**9.6 Privacy and consent.** Publish final wording for: the account-creation
notice at checkout; the cookie disclosure — the cart cookie remembers a basket for
14 days, the sponsor cookie remembers who referred the visitor for 30 days;
data retention — abandoned guest carts are purged after 90 days, `rate_limits`
rows after 2 days, guest-created accounts are kept like any other account. Have the
notice owner approve it before Gate C.

**9.7 Security review checklist** (record a pass/fail per row in the PR):

| Control | Check |
|---|---|
| CSRF | every guest POST calls `csrf_verify()`; guests receive a token |
| IDOR | guest carts and items are reachable only through the cookie token; the client never supplies a cart id |
| Session fixation | `Auth::login()` regenerates the id; strict mode on (9.1) |
| Open redirect | `next` is whitelisted to `checkout` / `cart`; no other redirect takes user input |
| Enumeration | `check_username` throttled (9.2); login messages unchanged |
| XSS | all output through `e()`; script arguments through `js()`; `TemplateLintTest` green |
| SQL injection | prepared statements only (R1) |
| Mass assignment | remember-input helpers are whitelists; the service reads named fields only |
| Brute force / abuse | caps on attempts, successes per IP and per hashed mobile, cart creation, username checks |
| Secrets | passwords never logged, flashed, remembered or placed in URLs; bcrypt cost 12 |
| Errors | no stack traces or SQL in user-visible messages |

**9.8 Load and soak test.** Script (curl or a small PHP CLI) to: create 500 guest
carts from 10 source addresses (caps must engage); run 20 parallel checkouts with
distinct usernames and then 5 with the same username (exactly one wins); measure
the cron leg's runtime and table growth. Run it on staging only.

**9.9 Monitoring queries** (save in `docs/runbook.md`): guest carts created per day
(`guest_started_at`); active vs abandoned guest carts; guest-created accounts per
day (`registered_via = 'guest_checkout'`); orders from them; cap-hit counts from
the log.

### Tests
- `client_ip()` with a crafted `$server` array: header trusted and valid → used; invalid → falls back; header absent → `REMOTE_ADDR`.
- `username_check_allowed`: the 31st call in 15 minutes is denied; injected time resets the window.
- `guest_log` output contains no password, mobile or username (feed it sample context).
- Manual: after 9.1, log in and out as customer, partner and admin; confirm existing sessions survive the deploy (cookie name unchanged); confirm a Super-Login tab still works; confirm the cookie flags in the browser.

### Acceptance criteria
- `php -l`, the whole suite and `predeploy` green; flag-OFF snapshot diff against `p0` empty.
- Security checklist complete; load test passed with caps engaging and no duplicate users.
- Privacy and cookie wording approved and live.

### Gate C — enabling `guest_checkout_enabled` in production
Checklist: P0–P9 merged; Gate A open and stable; `REMOTE_ADDR` / proxy handled;
session flags verified with no mass sign-out; privacy wording live; caps tuned from
staging data (adjust the proposed 10 / 3 / 2 / 20 / 30 numbers if needed); logging
and monitoring queries in place; security checklist signed; the P8 end-to-end QA
passed on staging with all three flags ON; support briefed on guest accounts
(lookup by name, password reset by an admin); rollback rehearsed (flag OFF turns
the cart button back to "Join to continue"). Enable during a low-traffic window and
watch caps, errors and stock holds for 48 hours, then weekly for a month.

### Rollout and rollback
Deploy P9 first (dark), then flip the flag at the gate. Rollback = flag OFF.

### Hand-off
The guest storefront is complete, hardened and observable; all three gates can be open.

---

## Phase 10 — Admin tooling and reporting

### Goal
Give admins the visibility they need to run a two-audience system: who is a
customer, who arrived through guest checkout, how the guest funnel performs, and
how many partner seats remain.

### Depends on
P9 (stable, observable guest checkout). Reads `registered_via` and the cart funnel
markers from P1 / P5.

### Context you need
- Admin member list: `views/admin/users.php` (status filter and labels around
  `:17` and `:40`); `User::allMembers` searches `u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?`
  — **not mobile** — which matters because guest checkout stores `full_name` and
  `mobile`.
- Admin dashboard: `views/admin/dashboard.php:33` (the "Pending Activation" tile,
  already relabelled by `member_status_label()` in P4); the admin sidebar badge in
  `views/partials/sidebar_admin.php`; the user detail view
  `views/admin/user_view.php`; admin password reset `AdminController::changePassword`
  (`:201`).
- Data: `users.registered_via` (`register` / `guest_checkout`), `users.status`,
  `users.mobile`; `carts.guest_started_at`, `carts.status`
  (`active` / `abandoned` / `converted`); seats via `seatsRemaining()` (partners
  only when the zone is ON, P4).
- R2 (escape output), R4 (put queries in model methods that tests can call).

### Scope
**In:** list filters and badges, mobile search, user-view fields, dashboard cards
and funnel queries, an optional order-detail badge, tests. **Out:** new exports,
new admin actions, any schema change.

### Tasks

**10.1 Member list.** Add a segment filter — *All / Customers / Partners* (pending
vs not) — and a "Registered via" filter; show a small "Guest checkout" badge on
rows where `registered_via = 'guest_checkout'`. Reuse `member_status_label()`.

**10.2 Search by mobile.** In `User::allMembers`, extend the search predicate with
`OR u.mobile LIKE ?` (add the parameter positionally — R1).

**10.3 User view.** Show Customer/Partner, "Registered via", sponsor, mobile, and
"Orders: n" for customers.

**10.4 Dashboard cards** — queries as model methods (`AdminStats::…` in `models/`):
- *Customers vs partners:* `SELECT status = 'pending' AS is_customer, COUNT(*) FROM users WHERE role = 'member' GROUP BY is_customer`.
- *Partner seats:* `seat_limit` minus the partner count.
- *Guest funnel (last 30 days):* started = carts with `guest_started_at` in range;
  ordered = of those, `status = 'converted'`; expired = `status = 'abandoned'`.
  (Carts merged into an existing account on sign-in are deleted and are not counted.)
- *Guest-origin accounts:* `COUNT(*)` where `registered_via = 'guest_checkout'`, and how
  many of them have since activated (`status <> 'pending'`).

**10.5 Order detail (optional).** A "Guest checkout" badge when the buyer's
`registered_via = 'guest_checkout'` and the order is their earliest.

### Tests
- Each stats query on seeded data (customers, partners, guest carts in each status) returns the expected counts, and the funnel's date window is honoured.
- `User::allMembers` finds a member by a fragment of their mobile number; the existing username / name / email searches still work.
- `ViewRenderTest`: the member list renders with the badge and filters without warnings; `TemplateLintTest` green.

### Acceptance criteria
- Suite and `predeploy` green; flag-OFF snapshot diff empty for non-admin pages.
- An admin can find a guest customer by name or mobile number, see how they arrived, reset their password, and read the funnel card.

### Rollout and rollback
Ordinary deploy; read-only features. Roll back by reverting the PR.

### Hand-off
Admins can see, search, filter and measure the new audience.

---

## Phase 11 — Rollout, documentation and clean-up

### Goal
Run the staged enablement with explicit abort criteria, bring every document in
line with the code, and decide what to retire.

### Depends on
P10 (everything built). Requires the gates to be opened in order A → B → C.

### Context you need
- Enablement order and flag semantics: §5.
- Documents that describe the old behaviour: `buyer_workflow.md` §1 (free
  registration → dashboard), `guest_buyer_workflow.md` (a proposed spec written
  before D16–D22), `AGENTS.md` (claims there are no tests; no mention of the new
  roles, flags or helpers), `cron/README.md` (cron legs).
- The dashboard pending banner `views/member/dashboard.php:27-44` becomes dead
  code once customers cannot reach the dashboard.
- Every new branch is behind a flag, so each gate is reversible without data work.

### Scope
**In:** runbooks, documentation, dead-code removal, post-launch reviews.
**Out:** new features (log them in the backlog).

### Tasks

**11.1 Gate runbooks** — one block per gate in `docs/runbook.md`:

| Gate | Pre-checks | Steps | Watch | Abort if |
|---|---|---|---|---|
| **A** `customer_zone_enabled` | P4 checklist done; audits handled; support briefed | enable in a quiet window; verify as a customer, a partner and an admin | login errors, redirect loops, support tickets, activation attempts | any customer can reach a partner page, or partners are redirected away from the dashboard |
| **B** `guest_shop_enabled` | P6 checklist; cookie disclosure live | enable; add one product to a cart as a visitor | guest carts per day, cap hits, abandoned ratio | cart-creation spikes that the cap does not contain |
| **C** `guest_checkout_enabled` | P9 checklist; A open | enable; place one real order as a test visitor | accounts created, order errors, cap hits, stock held by pending guest orders | duplicate accounts, orders without accounts or accounts without orders, abuse the caps do not contain |

**11.2 Documentation.**
- `guest_buyer_workflow.md`: update to the final design — remove the placement
  section (D19: always unplaced), the seat gate (D18), and the "lands on the
  dashboard" assumptions; keep the checkout field table and the replay design.
- New `customer_zone_workflow.md`: roles, the two zones, redirect rules, the
  business rules (seats, placement, wallet, profile), activation, admin labels.
- `buyer_workflow.md`: §1 and §3 note that free members use the storefront zone when
  the flag is on; link to the two documents above.
- `AGENTS.md`: the test suite exists (`php tests/run.php`, `predeploy`); the new
  roles `account` / `partner`; the four settings; the helper index; the autoload
  rule (R8); the session note (R11).
- `cron/README.md`: describe leg 3.
- Admin help text for the three switches and their order.

**11.3 Clean-up** — only after every gate has been open and stable for at least two
weeks: remove the dead pending banner from `dashboard.php`; delete any duplicated
helper left over from the extractions. **Keep the four settings as permanent kill
switches** — they cost nothing; retire them (and their `else` branches) only after
a quarter of stable operation, in a separate "flag retirement" PR.

**11.4 Post-launch reviews** at +2 and +6 weeks: funnel conversion (visitors →
carts → orders → activations); cap hits and tuning; stock-hold impact; support
tickets about credentials; seat usage. Revisit the deferred decisions: D15
(last vs first click), storefront indexability, an optional email field, SMS
verification and login by mobile, the deferred-password alternative, a featured
products strip on the landing page, the landing-site theme (D21).

**11.5 Backlog (explicitly not in this plan):** a commission-bearing affiliate
programme (D17 Reading B); SMS/OTP verification; a CAPTCHA provider; a unique index
on `(binary_parent_id, binary_position)` (only after the P0 audit shows no
duplicates); referrer-per-order recording.

### Tests
- A final full run: `php tests/run.php`, `php tests/predeploy.php`, the snapshot tool with every flag OFF (must equal `p0`), and with every flag ON for the manual matrix.
- A documentation check: every file path, route and helper named in the new documents exists (grep).

### Acceptance criteria
- All three gates opened in order, each with its watch period passed.
- The documents match the code; `AGENTS.md` is accurate.
- Dead code removed only after the stability window.

### Rollout and rollback
Each gate is reversible by its flag. Documentation and clean-up PRs are ordinary reverts.

### Hand-off
The system runs in the new shape, is documented, and has a reviewed backlog.

---

## Appendix A — Pre-flight data audits (run on a production copy in P0)

```sql
-- A1  customers who sponsor someone
SELECT COUNT(*) FROM users s
 WHERE s.role = 'member' AND s.status = 'pending'
   AND EXISTS (SELECT 1 FROM users d WHERE d.sponsor_id = s.id);

-- A2  customers already placed in the binary tree
SELECT COUNT(*) FROM users
 WHERE role = 'member' AND status = 'pending' AND binary_parent_id IS NOT NULL;

-- A3  customers holding e-wallet funds
SELECT COUNT(*) FROM users
 WHERE role = 'member' AND status = 'pending' AND ewallet_balance > 0;

-- A4  customers with shop orders
SELECT COUNT(DISTINCT o.member_id) FROM shop_orders o
  JOIN users u ON u.id = o.member_id WHERE u.status = 'pending';

-- A5  duplicate binary slots (must be empty before ever adding a unique index)
SELECT binary_parent_id, binary_position, COUNT(*) AS c FROM users
 WHERE binary_parent_id IS NOT NULL
 GROUP BY binary_parent_id, binary_position HAVING c > 1;

-- A6  seats: customers vs everyone else
SELECT status, COUNT(*) FROM users WHERE role = 'member' GROUP BY status;

-- A7  carts by status (baseline before guest carts exist)
SELECT status, COUNT(*) FROM carts GROUP BY status;
```

## Appendix B — Route classification (the 40 `member` routes, plus the wallet pair)

| New role | Routes | Count |
|---|---|---|
| `account` (customers **and** partners) | `get_packages`, `profile`, `save_profile`, `activate`, `do_activate`, `shop`, `shop_orders`, `shop_order`, `shop_invoice`, `cart`, `add_to_cart`, `update_cart_item`, `remove_cart_item`, `checkout`, `place_order`, `shop_submit_proof`, `shop_cancel_order`, `shop_confirm_receipt`, `shop_address_save`, `shop_address_delete`, `shop_address_default` | 21 |
| `partner` | `dashboard`, `earnings`, `cap_status`, `dfi_history`, `api_cap_status`, `api_dfi_status`, `api_cd_status`, `genealogy`, `api_binary_tree`, `api_binary_uplines`, `payout`, `request_payout`, `update_usdt_gas`, `update_usdt_bep20_gas`, `upgrade`, `do_upgrade`, `api_validate_upgrade_code`, `reactivate`, `do_reactivate` | 19 |
| `partner` (was `any`) | `ewallet_transfer`, `do_ewallet_transfer` | 2 |
| `account` (new, P2) | `account` | 1 |
| `any` (new, P6 / P8) | `store`, `store_add_to_cart`, `store_cart`, `store_update_cart_item`, `store_remove_cart_item`, `store_checkout`, `store_place_order` | 7 |
| unchanged | `guest` (2), `any` auth routes (`register`, `do_register`, `validate_code`, `check_username`, `check_upline`, `auto_select_upline`, `logout`), `admin` (45), `super` (2) | — |

After P3 **no route has role `member`**; the router still accepts the role.

## Appendix C — Decisions realised per phase

| Phase | Decisions it realises |
|---|---|
| P1 | D16 (primitives) |
| P2 | D11, D21, D22 |
| P3 | D16 |
| P4 | D17 (branding only), D18, D19, D20 |
| P5 | D11, D12 |
| P6 | D4′ (cookie, default), D7′ (tier 1), D9, D12, D13, D15 |
| P7 | — (enables P8) |
| P8 | D1, D2, D3′, D4′, D6, D7′ (tier 2), D8, D14; D5 superseded |
| P9 | R11 and the privacy items |
| P10 | visibility of D16 / D18 |
| P11 | D10 |

If a decision changes, update §4 and this table in the same PR.

## Appendix D — Test inventory

| Suite (file under `tests/`) | Phase | Covers |
|---|---|---|
| `FoundationTest.php` *(new)* | P1 | schema, flag defaults, `is_customer_user`, `landing_url_for`, `normalize_mobile`, DB limiter, `Auth::isCustomer` |
| `ViewRenderTest.php` *(extended)* | P2, P4, P5, P6, P8, P10 | the seven templates and the account page in both shells; payout section; e-wallet option; guest context renders; guest checkout; admin list |
| `CustomerZoneAccessTest.php` *(new)* | P3, P6 | `Access` matrix, flag-off oracle, route inventory |
| `CustomerZoneRulesTest.php` *(new)* | P4 | seats, placement, wallet, profile, activation block, labels |
| `GuestCartTest.php` *(new)* | P5, P6 | token isolation, claim, merge, purge, ownership, cookie token validation |
| `RegistrationRefactorTest.php` *(new)* | P7 | regression of every registration mode, transaction adoption, validator |
| `GuestCheckoutTest.php` *(new)* | P8 | the service: happy path, atomicity, replay, usernames, sponsors, forged input, caps, gates |
| hardening tests (add to `FoundationTest` or a new `HardeningTest.php`) | P9 | `client_ip`, `username_check_allowed`, `guest_log` |
| `AdminStatsTest.php` *(new)* | P10 | funnel and count queries, mobile search |
| existing suites, unchanged | all | `SchemaDriftTest`, `TemplateLintTest`, `ShopStateMachineTest`, `QaSuiteTest`, … must stay green |

## Appendix E — File touch index

| File | Phases |
|---|---|
| `install.sql`, `migrate_customer_zone_guest_store.sql` | P1 |
| `core/helpers.php` | P1, P2, P4, P5, P6, P8, P9 |
| `core/Auth.php` | P1, P3 |
| `index.php` | P2 (account route), P3 (roles, redirects, route table), P6 (store routes), P8 (checkout routes), P9 (session flags) |
| `models/Access.php` *(new)* | P3, P4 |
| `models/Cart.php` | P5 |
| `models/User.php` | P4 (seat check, profile), P7 (register, afterRegister, validator), P10 (mobile search) |
| `models/GuestCheckout.php` *(new)* | P8 |
| `models/AdminStats.php` *(new)* | P10 |
| `controllers/AuthController.php` | P3, P4, P6, P9 |
| `controllers/MemberController.php` | P2 (account), P4 (activation, wallet, profile, checkout), P8 (address helper delegate) |
| `controllers/GuestShopController.php` *(new)* | P6, P8 |
| `controllers/AdminController.php`, `views/admin/*` | P1 (settings), P4 (labels), P9 (proxy setting), P10 |
| `views/member/{shop,cart,checkout,shop_orders,shop_order,profile,activate}.php` | P2 (shell), P4 (profile, checkout), P5 (links), P8 (partials, welcome card) |
| `views/member/account.php` *(new)* | P2 |
| `views/partials/{shell_none,store_topbar}.php` *(new)* | P2, P6 |
| `views/partials/cart_offcanvas.php`, `footer.php` | P5 |
| `views/partials/{checkout_summary,checkout_payment,checkout_submit_js}.php` *(new)* | P8 |
| `views/store/checkout.php` *(new)* | P8 |
| `views/auth/login.php` | P6 |
| `frontend/index.php`, `views/member/genealogy.php` | P6 |
| `assets/css/layout.css` | P2 |
| `cron/shop_expiry.php` | P5 |
| `reset.php` | P1 |
| `tests/bootstrap.php`, `tests/*Test.php`, `tests/tools/*` | P0, P1, then every phase |
| `core/Commission.php` | **never** |

## Appendix F — Manual QA matrix

"Zone OFF" must equal today's behaviour exactly.

| # | Scenario | Zone OFF | Zone ON |
|---|---|---|---|
| 1 | Visitor opens `?page=dashboard` | login page | login page |
| 2 | Customer logs in | dashboard | `?page=account` |
| 3 | Customer opens `earnings`, `genealogy`, `payout`, `upgrade`, `reactivate` | page renders | `?page=account` with the info flash |
| 4 | Customer opens `ewallet_transfer` | page renders | `?page=account` with the info flash |
| 5 | Customer opens shop, cart, checkout, orders, an order, profile, activate | dashboard shell | storefront shell: no sidebar, no wallet chip, working cart drawer |
| 6 | Signed-in customer opens `?page=register` | registrar form | `?page=account` with the info flash |
| 7 | Partner opens `?page=account` | redirected to the dashboard | redirected to the dashboard |
| 8 | Admin opens `?page=account` | redirected to the admin area | redirected to the admin area |
| 9 | Partner shops | dashboard shell; e-wallet offered | unchanged |
| 10 | Customer at checkout | e-wallet offered | four manual methods only; a forged `ewallet` POST is refused |
| 11 | Customer profile | payout section present | payout section hidden; saving does not erase stored payout values |
| 12 | Customer activates with a code | becomes active, lands on the dashboard | same; with partner seats full: "Partner seats are full…" |
| 13 | Free registration from a referral link | member placed as before | member **unplaced**; lands on `?page=account` |
| 14 | Super-Login as a customer | lands on the dashboard | lands on `?page=account&imp=…` |
| 15 | Send Money to a customer | works | "Recipient not found." |
| 16 | Visitor opens `?page=store` with `guest_shop_enabled` OFF | — | redirected to the landing page; no Shop link on it |
| 17 | Visitor browses, adds, edits, removes (flag B ON) | — | works; badge and drawer behave like the member shop; the cookie is `HttpOnly`, `SameSite=Lax`, scoped to the app path |
| 18 | 21st guest cart created within an hour from one address | — | refused with a clear message |
| 19 | Cart with checkout closed | — | "Join to continue" → register with the sponsor parameters; the cart arrives after registering |
| 20 | "Log in & continue" with a cart | — | merged; lands on checkout, or on the cart when quantities were clamped or the member already had items |
| 21 | Signed-in user opens `store*` | — | redirected to the member page after a merge |
| 22 | Guest checkout end to end (flag C ON) | — | account created (customer, unplaced, sponsor = cookie sponsor or admin); lands on the order page with the one-time "Your login" card; cart and sponsor cookies cleared |
| 23 | Refresh, Back, or a second POST after success | — | no second order or account |
| 24 | Username taken, invalid mobile, missing terms | — | field errors; input kept except the password |
| 25 | A product sells out between cart and submit | — | back to the cart with the message; nothing created |
| 26 | Third order from the same mobile number in 24 h | — | generic "Too many orders…" message |
| 27 | Unpaid guest order passes its deadline | — | cancelled by cron; the account remains and can sign in |
| 28 | Customer forgets the password | — | an admin resets it from the user page; the member search finds them by name or mobile |

## Appendix G — Rollback matrix

| Phase | How to undo | Data concerns |
|---|---|---|
| P0 | nothing to undo | — |
| P1 | revert the PR; run the down migration | down migration is safe only while no guest carts or guest-created accounts exist |
| P2 | revert the PR | none |
| P3 | revert the PR, or leave the flag OFF | none |
| P4 | flag OFF restores seat counting, placement, wallet and profile behaviour; or revert | accounts created while ON stay valid; unplaced customers simply remain unplaced |
| P5 | revert the PR | idle guest carts are purged by cron regardless |
| P6 | `guest_shop_enabled` OFF | guest carts age out through the cron leg |
| P7 | revert the PR | none (no schema change) |
| P8 | `guest_checkout_enabled` OFF | accounts and orders already created are ordinary rows |
| P9 | revert individual items; session flags revert by removing the `ini_set` lines | none |
| P10 | revert the PR | none |
| P11 | revert docs / clean-up PRs | none |
