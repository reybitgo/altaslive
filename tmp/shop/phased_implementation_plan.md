# Altas Live — Phased Implementation Plan

## Guest Storefront, Guest Checkout, and the Customer/Partner Zone

|                             |                                                                                                                                                                                                                       |
| --------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Status**                  | Proposed — pre-build plan (all decisions D1–D22 settled, see §2)                                                                                                                                                      |
| **Derived from**            | The full planning dialog (`dialog.txt`), `tmp/shop/guest_buyer_workflow_v4.md` (the guest spec), `tmp/shop/buyer_workflow.md` (the member spec), and a fresh pass over the repository snapshot in `altaslive (2).zip` |
| **Verified against source** | Yes. Every `file:line` citation in this plan was re-checked against the snapshot. Identifiers that do not exist yet are marked _(new)_.                                                                               |
| **Scope**                   | Three integrated workstreams: (1) guest storefront & cart, (2) guest checkout with atomic registration, (3) the customer/partner zone split — delivered as eight phases, P0–P7                                        |
| **Companion docs**          | `guest_buyer_workflow.md` (v4, updated per phase), `customer_zone_workflow.md` _(new, written in P0)_, `buyer_workflow.md` (reconciled in P7)                                                                         |

---

## 0. How to read this plan

### 0.1 The self-containment contract

Every phase section restates everything needed to execute it without reading the
planning dialog or the other phases:

1. **Goal** — what this phase delivers, in one paragraph.
2. **Entry state / Exit state** — what exists when the phase starts and when it
   ends, so the dependency on the previous phase is explicit.
3. **Decisions applied** — the D-numbers from §2 this phase implements.
4. **Work items** — file-and-route level tasks. Existing files are cited with
   real paths; new files, columns, routes, settings and cookies are marked
   _(new)_.
5. **Schema & settings** — DDL and settings keys this phase adds (all inert
   until a later phase or flag uses them).
6. **Tests** — what must pass before the phase can be called done (full matrix
   in Appendix C).
7. **Acceptance criteria** — a checklist a reviewer can tick off.
8. **Rollout & rollback** — which flag (if any) gates the phase and how to turn
   it back off.
9. **Effort** — S / M / L relative sizing. No calendar dates by design; the
   sequence and dependencies are the contract, not the clock.
10. **Risks carried** — the phase-specific entries from Appendix D.

### 0.2 Conventions

- `file:line` citations refer to the repository snapshot (`altaslive (2).zip`).
  Line numbers will drift as code is written; the citation anchors the _intent_,
  re-locate with a search when the line has moved.
- _(new)_ marks anything that does not exist in the snapshot: routes, columns,
  settings, cookies, files, helper functions.
- D-numbers (D1–D22) refer to the decision register in §2. Primes (D3′, D4′,
  D7′) are revisions that supersede the original wording; supersessions are
  stated in the register.
- "Customer" = a `role='member'` user with `status='pending'`. "Partner" = any
  other member (active, capped, suspended, deactivated) plus admin/superadmin.
  "Guest" = an anonymous visitor holding no account (D1, D16).
- The member shop journey after order placement (the 14-status state machine,
  proof upload, refunds, timers) is **out of scope for re-implementation** and
  referenced as `buyer_workflow.md` §8–§11 (D10). Nothing in this plan modifies
  it except the shell around it.

### 0.3 Build order at a glance

```
P0  Alignment & pre-flight ─────────── audits, decisions, spec baselines        (S)
P1  Shared template foundation ─────── shop_ctx()/shop_link(), partials, diff   (M)
P2  Data & cart token layer ────────── schema, Cart token methods, cron, flags  (M)
P3  Guest storefront & cart ────────── Milestone 1: anonymous shopping     ──► guest_shop_enabled
P4  Customer/partner zone ──────────── boundary, shells, activation move   ──► customer_zone_enabled
P5  Guest checkout & registration ──── Milestone 2: atomic register+order ──► guest_checkout_enabled
P6  Hardening, continuity & polish ─── AJAX add, session hardening, funnel      (M)
P7  Rollout, monitoring & docs ─────── staged enablement, spec reconciliation   (S)
```

---

## 1. Current system snapshot (verified)

Facts this plan builds on, each re-verified in the snapshot. These are the
anchors for the refactor work; if any of them is wrong, the affected phase must
be re-scoped before it starts.

**Routing and guards.** All requests flow through `index.php` (front
controller). The route table at `index.php:272-392` maps `?page=` to
`[Controller, method, role]` with roles `guest | member | admin | super | any`.
There are exactly **40 routes with role `member`**, plus
`ewallet_transfer` / `do_ewallet_transfer` with role `any` whose only protection
is `Auth::check()` inside the controller (`MemberController.php:1076,1100`). Of
the 40 member routes, **21 are customer-side** (16 shop + `profile`,
`save_profile` + `activate`, `do_activate`, `get_packages`) and **19 are
partner-side** (dashboard, earnings, genealogy/tree, payouts, upgrade,
reactivation, cap/DFI/cd APIs). Full inventory in Appendix A. There is no
`pending`-status gating anywhere beyond activation itself
(`MemberController.php:608,643`) — a free member can currently reach every
member route.

**Seat limit.** `seatsRemaining()` counts **all** members regardless of status
(`core/helpers.php:161` — `COUNT(*) FROM users WHERE role='member'`), so a
pending (free) registration consumes a seat today. The router hard-blocks
`register | do_register | validate_code` when the limit is reached
(`index.php:255-268`), and `User::register()` re-checks it
(`models/User.php:13`). D18 moves seat consumption to activation.

**Registration.** `AuthController::doRegister` (`AuthController.php:240`) is a
monolith: username rules (`is_valid_username`, 3–40 chars), uniqueness, password
≥ 8 + confirm, sponsor lookup, payment (code / e-wallet / free), and binary
placement. Placement is **conditional**: a free referral registration is placed
only when it came from a real referral link whose sponsor is a valid binary
upline with a free slot; everyone else registers **unplaced** and chooses a
binary connection at activation (`AuthController.php:379-396`,
`User::isValidBinaryUpline` at `models/User.php:641`). The sponsor-anchored BFS
(`findNextBinarySlot`) is **private to `AuthController`**
(`AuthController.php:207`); the network-wide BFS lives on `User`
(`findNextBinarySlotNetworkWide`, `models/User.php:675`). `User::register()`
(`models/User.php:11`) **opens its own transaction** (`beginTransaction` at
`:18`) — it cannot be nested inside the guest-checkout transaction without
refactoring. It sets **no profile columns** (name/phone stay NULL at
registration). The register form defaults a guest's sponsor to the string
`admin` (`views/auth/register.php:29-31`).

**Shop core.** `ShopOrder::createFromCart(memberId, cartId, addr, method,
idemKey)` (`models/ShopOrder.php:687`) is the single order-creation path. It is
already **transaction-aware** — it adopts a caller's open transaction
(`ShopOrder.php:711-714`), re-reads prices and stock inside the transaction, and
has an idempotency fast path on `shop_orders.idempotency_key` (`:700-706`).
Member `placeOrder` (`MemberController.php:1423`) wraps it in one transaction
with the idempotency key `sha1(uid|cart|idem)` (`:1486`), debits e-wallet and
marks paid inside the same unit for e-wallet orders, saves the address
best-effort **after** commit (`maybeSaveCheckoutAddress`), and preserves typed
input on failure via `shop_checkout_remember_input()` (`core/helpers.php:614`).
`guardShop()` checks `shop_enabled` (`MemberController.php:1209`); order pages
are owner-scoped via `resolveOwnOrder()` (`:1217`). Payment methods:
`ewallet, gcash, maya, usdt_trc20, usdt_bep20` (`ShopOrder.php:678`).

**Cart.** `carts` is member-owned: `member_id INT UNSIGNED NOT NULL` with
cascading FK, `status ENUM('active','abandoned','converted')`, index
`(member_id, status)` (`install.sql:182-190`). `cart_items` is
`UNIQUE (cart_id, product_id)` (`install.sql:455`). All `Cart` methods
(`getActive`, `addItem`, `validateStock`, `refreshPrices`,
`models/Cart.php:4,19,119,133`) already take a `$cartId`, so token ownership is
a lookup change, not a logic fork. `carts.status` already contains `abandoned` —
no enum change needed for the funnel.

**Session & security.** The app session is started with bare `session_start()`
(`index.php:46`). `Auth::start()` (`core/Auth.php:20`) exists — it would set
`session_name('mlm_sess')` — but is **never called**; invoking it as-is would
rename the cookie and sign everyone out (spec §15). CSRF is seeded for every
route (`index.php:425`) and enforced via `csrf_verify()` on POST handlers. Rate
limiting exists but is **session-based** (`$_SESSION['rl']`,
`core/helpers.php:379-404`) — it cannot throttle a cookie-less bot, so the
guest work needs a DB-backed limiter. Super-Login impersonation rides a URL
nonce session and always lands on `/?page=dashboard&imp=…`
(`AuthController.php:126`).

**Users schema (V2, resolved).** `users` (`install.sql:73-130`) has nullable
profile columns `full_name`, `email`, `mobile`, `address`, `photo`, plus payout
fields; `status ENUM('active','suspended','pending','deactivated')`; no
`registered_via` (added in P2). No email/SMS sending or password reset exists
anywhere (V4) — recovery is admin-mediated.

**Views and coupling (V6, resolved).** Member views hard-code the same sidebar
and topbar. The shop-facing templates are lightly coupled to the member:
`shop.php` (158 lines), `cart.php` (224), `checkout.php` (392), the cart
off-canvas partial (288, included from `views/partials/footer.php`), plus
`shop_orders.php` (152), `shop_order.php` (373), `profile.php` (461),
`activate.php` (611). Member-specific chrome is essentially the sidebar +
topbar; `.main-content { margin-left: var(--sidebar-w) }` is the only layout
rule to neutralize (`assets/css/layout.css:211-212`). The PSGC address picker is
already a partial (`views/partials/address_picker.php`); the checkout delivery
markup and submit script are inline in `checkout.php`. Seven templates serve
customers: `shop`, `cart`, `checkout`, `shop_orders`, `shop_order`, `profile`,
`activate` — the invoice (`views/shop/invoice.php`) is already standalone.

**Landing page (V1, resolved).** `frontend/index.php` loads
`config/db.php`, `core/helpers.php` and `models/Package.php` but **not**
`core/Auth.php`, and never starts a session. The storefront therefore lives
inside the routed app as `?page=store` (D21); the landing page gets a plain
Shop link.

**Ops.** `cron/shop_expiry.php` runs two legs — expire overdue
pending/payment_failed orders (`ShopOrder::expireOverdue(200)`) and
auto-complete delivered orders — with file logging under `cron/logs/`. No guest
cart leg exists yet.

**Tests.** The repo has a real harness despite `AGENTS.md` claiming otherwise:
`php tests/run.php` boots `tests/bootstrap.php` and runs
`ShopStateMachineTest`, `ViewRenderTest`, `QaSuiteTest`, `TemplateLintTest`,
`SchemaDriftTest` (schema ↔ `install.sql` drift), plus `tests/predeploy.php`.
This is the regression net every phase runs against.

**Specs.** `tmp/shop/guest_buyer_workflow_v4.md` (1,056 lines, §1–§17) is the
current guest spec: shared templates (§4), token cart (§5), checkout (§6),
atomic placement (§7), continuity (§9), settings/data (§10), abuse (§11), edge
matrix (§12), routes (§13). Its §15 already answers V1–V6 against this snapshot
and lists eight open items (Appendix E). It does **not** yet contain the
customer-zone decisions D16–D22 — P0 folds those in.

---

## 2. Decision register (D1–D22, final wording)

Revised decisions carry a prime; superseded wording is noted inline. These are
settled — a phase may not re-open them; changes go through a new D-number.

| #   | Decision                              | Final wording                                                                                                                                                                                                                                                                                                                                                                                                    |
| --- | ------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D1  | Guest definition                      | A guest is a visitor without an account. Every guest checkout creates an account; anonymous orders (null-owner) are rejected — proof upload, cancel, confirm-receipt and invoices all require an owner.                                                                                                                                                                                                          |
| D2  | Account creation timing               | The account is created at **order placement**, in the same transaction as the order. Any failure rolls back both — no orphan accounts, no orphan orders. If the order later expires or is cancelled, the **account survives** and only the order dies.                                                                                                                                                           |
| D3′ | Credentials _(revises D3)_            | Credentials stay at checkout but lean: username auto-suggested from the recipient name (visible, editable, same 3–40 rules), **one** password field with show/hide, ≥ 8 chars, no confirm field. The no-credentials-at-checkout alternative (random password + gated choose-password step) is deferred until SMS verification exists, because with no recovery channel a guest who closes the tab is locked out. |
| D4′ | Sponsor _(revises D4)_                | Referral cookie (30 days) → read-only "Referred by @x". Otherwise the sponsor is the **primary admin resolved by id** (`User::primaryAdminId()`, `models/User.php:618`) — no sponsor field is shown; an optional "I was referred by someone" link reveals one. The `guest_default_sponsor` setting is dropped.                                                                                                   |
| D5  | Placement _(superseded twice)_        | Original "sponsor's next free slot" was corrected by the source review (placement is conditional today) and then **superseded by D19**: customers are never placed at registration.                                                                                                                                                                                                                              |
| D6  | Guest payment methods                 | GCash, Maya, USDT TRC20, USDT BEP20 only. E-wallet is hidden in the UI **and** rejected server-side. Extended by D20 to customers.                                                                                                                                                                                                                                                                               |
| D7′ | Availability gates _(revises D7)_     | Two tiers. **Tier 1 (storefront + cart):** `shop_enabled` + `guest_shop_enabled` _(new)_. **Tier 2 (account-creating checkout):** tier 1 + `guest_checkout_enabled` _(new)_ + `free_registration_enabled`. Checked at render and again inside the transaction. The seat condition was removed from tier 2 by D18.                                                                                                |
| D8  | Guest deviations from member checkout | Mobile number required (the only contact channel for a guest), address auto-saved to the new account's address book, and a visible notice that ordering creates a free account (terms checkbox covers it).                                                                                                                                                                                                       |
| D9  | Returning members                     | "Log in & continue" from the guest cart merges the guest cart into the member cart; the user lands on `?page=cart` (not checkout) when the member already had items or any line was clamped. Logged-in users hitting `store*` routes are redirected to the member equivalents. The same merge runs after normal free registration.                                                                               |
| D10 | Document scope                        | The guest spec covers the front half in full; the order page onward references `buyer_workflow.md` §8–§11. No second copy of the 14 statuses.                                                                                                                                                                                                                                                                    |
| D11 | Parity scope                          | Guests get the same product grid, mini-cart drawer, quantity controls, cart page and checkout look from the **same templates**. They do not get e-wallet, saved addresses, wallet/avatar chrome, or sidebar navigation.                                                                                                                                                                                          |
| D12 | Architecture                          | Separate guest routes (`store*`) and a thin `GuestShopController` _(new)_ over the shared templates. Member routes are untouched — the member handlers use `Auth::id()` throughout and loosening them risks the member flow for no visible gain.                                                                                                                                                                 |
| D13 | Storefront location                   | A dedicated `?page=store` inside the app in the member-style look (D21), linked from the landing page. A featured-products strip on the landing page is backlog, not scope.                                                                                                                                                                                                                                      |
| D14 | Checkout field budget                 | One Recipient block (name + mobile) feeds billing and shipping; then address picker + street, payment method, terms; notes collapsed. ≈ 8–9 visible inputs. Mobile stays required (couriers; only contact channel).                                                                                                                                                                                              |
| D15 | Attribution                           | **Last-click**, 30-day sponsor cookie, referral sponsor displayed read-only. First-click lock is the documented fallback if referral disputes appear.                                                                                                                                                                                                                                                            |
| D16 | Customer / partner rule               | Customer = `role='member' AND status='pending'`. Partner = every other member (so capped/deactivated members keep reactivation access) plus admin/superadmin.                                                                                                                                                                                                                                                    |
| D17 | "Affiliate" meaning                   | **Branding only** (Reading A, confirmed by the owner): the customer tier may be _marketed_ as an affiliate-style experience, but customers earn nothing and no commission code is built. A real affiliate program (Reading B) is a separate future project; the optional hook of storing a referrer id per order is backlog.                                                                                     |
| D18 | Seat semantics                        | The seat limit counts **partners** (non-pending members), not all members. Activation consumes a seat — `doActivate` gets a seat check; free registration and guest checkout are **not** seat-gated.                                                                                                                                                                                                             |
| D19 | Placement at activation               | Customers are never placed in the binary tree at registration; placement happens at **activation** (network-wide auto-select by default, mirroring the existing deferred-placement pattern). Existing placed pending members are grandfathered: they keep their slot and must activate with a pairing-enabled package (`MemberController.php:613-620,650`).                                                      |
| D20 | E-wallet scope                        | E-wallet at checkout is partner-only (hidden + server-rejected for customers and guests). E-wallet transfers are between partners; wallet-holding customers are handled per the P0 audit (grandfathering path).                                                                                                                                                                                                  |
| D21 | "Frontend" meaning                    | The storefront zone lives **inside the routed app** (sessions available), not in the standalone `frontend/index.php` landing file, which has no session. Member-style look first; landing-site theme polish is backlog.                                                                                                                                                                                          |
| D22 | Partners' shop                        | Unchanged. Partners keep Shop, Cart and Orders inside the dashboard shell exactly as today.                                                                                                                                                                                                                                                                                                                      |

---

## 3. Build principles

1. **Refactor before feature, always inert first.** Any step that touches
   live member behaviour (P1) or live registration (P5's service extraction) is
   shipped as a pure, no-behaviour-change refactor guarded by a render diff or
   the existing suite, before the feature that needs it lands on top.
2. **Schema is inert until a flag says otherwise.** Migrations (P2) add columns
   and tables nothing reads yet; every user-visible change ships behind a
   settings flag defaulting to `0`. Rollback of a phase is a flag flip, not a
   restore.
3. **One code path per rule.** Stock, pricing, cart arithmetic, order creation
   and registration validation each keep a single implementation; guests and
   customers reach it through thin wrappers, never through a copy.
4. **Shared templates, separate controllers.** Views are context-aware (one
   template set, two shells); routes stay split (`store*` vs member) so member
   guards are never loosened (D12).
5. **Atomic where money or identity is created.** Account + cart claim + order
   commit or roll back together (D2); this rides the transaction-awareness
   already present in `ShopOrder::createFromCart` (`ShopOrder.php:711-714`).
6. **Every phase ships its own tests and its own exit state.** No phase ends
   with "works on my machine" — the acceptance checklist (per phase, Appendix C)
   gates the next phase's entry state.
7. **Docs trail the build, then catch up.** Specs are updated to "proposed"
   before a phase and reconciled to "derived from executable source" after it
   (P0 and P7 own the bookends).

### 3.1 Dependency graph

```
P0  decisions + audits + specs
 └─►P1  shop_ctx()/shop_link() + shell passthrough + partials + render diff
     └─►P2  schema + Cart token methods + cron + flags + admin toggles
         ├─►P3  guest storefront & cart (uses P1 templates, P2 token cart)
         │        ships behind guest_shop_enabled
         └─►P4  customer/partner zone (uses P1 shells, P2 flags)
                  ships behind customer_zone_enabled
             └─►P5  guest checkout (uses P1 partials, P2 claim/merge/limits,
                  │   P3 store routes, P4 account-zone landing, P5a service extract)
                  │   ships behind guest_checkout_enabled
                  └─►P6  hardening & polish (AJAX add, session, funnel)
                       └─►P7  staged enablement + doc reconciliation
```

Notes on the graph: P3 and P4 are independent of each other and may run in
parallel after P2; P5 requires both. P4 before P5 is deliberate — the account
that guest checkout creates must land in an account zone that already exists
(D16, D21). The enablement order in P7 (shop → zone → checkout) matches the
risk gradient, not the build order.

---

## 4. Phase 0 — Alignment & pre-flight

**Effort: S** · _No product code. Produces decisions, audit numbers, and spec
baselines. Everything downstream cites this phase's outputs._

### 4.1 Goal

Freeze the decision register, measure the existing data that the customer-zone
split will disturb, settle the environment questions that change hardening
choices, and bring the two spec documents to a state where P1–P7 can each be
executed from the spec + this plan alone.

### 4.2 Entry / Exit state

- **Entry:** codebase as snapshotted; `guest_buyer_workflow_v4.md` reflects
  D1–D15 but **not** D16–D22; D17 answered by the owner (branding only).
- **Exit:** decision register signed off (this plan §2 is the record); the three
  data audits run and their numbers recorded; each of the eight open items is
  either answered or assigned a default with a revisit trigger;
  `guest_buyer_workflow.md` updated for D16–D22; `customer_zone_workflow.md`
  skeleton written; `php tests/run.php` green on an unmodified checkout as the
  baseline.

### 4.3 Decisions applied

All of them — this phase is where D1–D22 (and the D17 confirmation) become
binding.

### 4.4 Work items

1. **Sign off the register.** Walk §2 with the owner. Any change becomes a new
   D-number with a note; primes and supersessions stay traceable.
2. **Run the pre-flight data audits** (against production data or the freshest
   dump available) and record the counts — they drive P4's grandfathering:

   ```sql
   -- A1: customers who already sponsor someone (they lose the referral UI in P4)
   SELECT COUNT(*) FROM users s WHERE s.role='member' AND s.status='pending'
     AND EXISTS (SELECT 1 FROM users d WHERE d.sponsor_id = s.id);

   -- A2: customers already placed in the binary tree (grandfathered slot, D19)
   SELECT COUNT(*) FROM users WHERE role='member' AND status='pending'
     AND binary_parent_id IS NOT NULL;

   -- A3: customers holding e-wallet funds (D20 grandfathering path)
   SELECT COUNT(*) FROM users WHERE role='member' AND status='pending'
     AND ewallet_balance > 0;
   ```

3. **Settle the environment open items** (from spec §15; each gets an answer or
   a default + revisit trigger — see Appendix E for the full list with owners):
   - Is `REMOTE_ADDR` the real client IP on the production host (behind a proxy
     the P2/P5 IP limiters key on the wrong value — check for
     `X-Forwarded-For` handling or fix at the load balancer)?
   - Is production HTTPS-only (gates the P6 cookie hardening)?
   - Does production hold duplicate `(binary_parent_id, binary_position)`
     pairs? If clean, add the unique index in P2 (Appendix B).
   - Should the public storefront be indexable (robots meta on `store*`)?
   - Should guest checkout collect an optional email (unverified, support
     lookup only)?
   - Should the seat lock apply to every registration path, not just guest
     checkout (P5a builds it once — adopting it everywhere is cheap then)?
   - Session cookie flags without renaming `mlm_sess` (P6).
   - Last-click vs first-click attribution — default last-click (D15), revisit
     on first referral dispute.
4. **Update `tmp/shop/guest_buyer_workflow.md`** for D16–D22: landing becomes
   the storefront order page / account home; the seat condition drops out of the
   tier-2 gate (§3); guest accounts are always unplaced (§7); §9 and §13 gain
   the `account`/`partner` roles; a short "customer zone" cross-reference to
   the new spec.
5. **Write `tmp/shop/customer_zone_workflow.md` (skeleton)**: the two-audiences
   model (D16), route split table (from Appendix A), shells, activation move
   (D18/D19), e-wallet scope (D20), grandfathering rules (fed by A1–A3),
   admin-visible changes. Marked _proposed_ until P4 reconciles it.
6. **Baseline the harness.** `php tests/run.php` green; record the suite's
   output as the reference every later phase diffs against.

### 4.5 Schema & settings

None.

### 4.6 Tests

No code — the only check is that the existing suite is green at baseline and
that the audits produce numbers (even if the numbers are zero).

### 4.7 Acceptance criteria

- [ ] §2 register signed; D17 recorded as branding-only per owner.
- [ ] A1/A2/A3 counts recorded in `customer_zone_workflow.md`; each nonzero
      count has a written grandfathering rule for P4.
- [ ] All eight open items answered or defaulted with revisit triggers
      (Appendix E).
- [ ] Guest spec updated for D16–D22; customer-zone spec skeleton exists.
- [ ] `php tests/run.php` green on the untouched tree; baseline output saved.

### 4.8 Rollout & rollback

Nothing to roll out. The deliverables are documents and decisions.

### 4.9 Risks carried

- Audit numbers arrive late → P4 grandfathering stays generic (R-07).
- `REMOTE_ADDR` unresolved → P2/P5 limiters ship with a config override for the
  trusted-proxy header and a note in ops runbook (R-10).

---

## 5. Phase 1 — Shared template foundation (no behaviour change)

**Effort: M** · _Pure refactor. Zero schema change, zero new routes, zero
visible difference for members. Creates the seams P3/P4/P5 build on._

### 5.1 Goal

Make the shop, cart, off-canvas and checkout templates **context-aware** so the
same files can render for a member (today, unchanged), a guest (P3), and a
signed-in customer (P4), and extract the checkout pieces both the member and
guest forms share. Prove nothing changed with a render diff.

### 5.2 Entry / Exit state

- **Entry:** P0 complete. Templates hard-code member routes and
  `Auth::id()`-owned carts; checkout's delivery markup and submit script are
  inline; the sidebar+topbar chrome is hard-required at the top of each member
  view.
- **Exit:** a `shop_ctx()` helper decides _who is shopping_ (member mode /
  store mode) and _who owns the cart_ (member id or guest token);
  `shop_link($route)` resolves route names per context; `shell_begin()` /
  `shell_end()` _(new)_ wrap every shop-facing template (in member mode they
  render exactly the old sidebar + topbar + `.main-content` markup); the
  checkout delivery block, submit script and a Recipient partial are extracted;
  a before/after render diff of member shop, cart, checkout and off-canvas is
  byte-stable (or diffs only in trailing whitespace).

### 5.3 Decisions applied

D11 (parity from shared templates), D12 (views shared, controllers separate),
D14 (Recipient partial), D21 (zone inside the app).

### 5.4 Work items

1. **`shop_ctx()` and `shop_link()` _(new)_** — `core/helpers.php` (or a small
   `core/ShopContext.php` _(new)_ if the owner prefers a class):
   - `shop_ctx(): array` returns `['mode' => 'member'|'store', 'user' => row|null,
'cart_owner' => ['type'=>'member','id'=>N] | ['type'=>'token','token'=>hex]]`.
     In P1 only `member` mode exists; `store` mode arrives with P3.
   - `shop_link(string $route): string` maps logical route names
     (`shop`, `add_to_cart`, `cart`, `update_cart_item`, `remove_cart_item`,
     `checkout`, `place_order`) to `member` URLs today and to `store*` URLs in
     store mode (P3). Templates stop hard-coding `?page=` strings.
2. **Make the four shared views context-aware** — replace direct member
   references with ctx calls:
   - `views/member/shop.php` (158 lines): route links → `shop_link()`;
     cart-count lookup → ctx owner.
   - `views/member/cart.php` (224): same, plus the off-canvas trigger
     (`?cart=1`) stays as-is.
   - `views/partials/cart_offcanvas.php` (288, included by
     `views/partials/footer.php`): links and owner only — the dialog measured
     the member-specific lines at ~13, mostly route names and "who owns the
     cart".
   - `views/member/checkout.php` (392): extract into partials (next item); the
     e-wallet block and saved-addresses list become
     `<?php if (shop_ctx()['mode'] === 'member' && /* partner check, P4 */ true): ?>`
     sections — in P1 the condition is always true, P4/P5 tighten it.
3. **Extract checkout partials _(new files)_**:
   - `views/partials/checkout_delivery.php` _(new)_ — the address/PSGC block,
     reusing the existing `views/partials/address_picker.php`.
   - `views/partials/checkout_submit.php` _(new)_ — the submit script and
     idempotency-key JS.
   - `views/partials/checkout_recipient.php` _(new)_ — the Recipient block
     (name + mobile) that D14 uses for the guest form. The member form is **not
     restructured** in P1; the partial exists so P5 can include it.
4. **`shell_begin($title)` / `shell_end()` _(new)_** —
   `views/partials/shell_begin.php` / `shell_end.php` _(new)_: in P1 they render
   exactly the current `sidebar_member.php` + `topbar.php` + open/close of
   `.main-content` (so the seven shop-facing member templates in P4 can switch
   shells without further edits). Adopt them in `shop.php` and `cart.php` now
   (the two templates P3 reuses first); the other five follow in P4.
5. **Render-diff harness _(new)_** — extend `tests/ViewRenderTest.php`: render
   `shop`, `cart`, `checkout`, off-canvas as a member **before** the refactor
   (commit the fixtures), render **after**, and assert equality (ignore
   whitespace). This is the "prove member pages are unchanged" gate; run the
   full suite (`php tests/run.php`) after.

### 5.5 Schema & settings

None.

### 5.6 Tests

- Render diff: member shop/cart/checkout/off-canvas identical before/after.
- `php tests/run.php` fully green (ShopStateMachine, QaSuite, TemplateLint,
  SchemaDrift unaffected).
- Manual smoke: add-to-cart → cart → checkout as a member; drawer opens via
  `?cart=1` exactly as before (the P6 AJAX work has not landed yet).

### 5.7 Acceptance criteria

- [ ] `shop_ctx()` / `shop_link()` exist and every route literal in the four
      shared views goes through them.
- [ ] Delivery/submit/Recipient partials exist; member checkout includes the
      first two and renders identically.
- [ ] `shell_begin/shell_end` adopted in `shop.php` + `cart.php`; visual result
      unchanged.
- [ ] Render-diff fixtures committed and passing; full suite green.
- [ ] No new routes, no schema change, no settings change.

### 5.8 Rollout & rollback

Ships directly (it is invisible). Rollback = revert the commits; no data or
flags involved.

### 5.9 Risks carried

- Refactor touches live member pages (R-01). Mitigation: the render diff plus
  the suite; the changes are mechanical (route literals → helper calls).
- Partial extraction subtly changes checkout JS behaviour (R-02). Mitigation:
  the submit partial is moved verbatim; TemplateLint + manual checkout smoke.

---

## 6. Phase 2 — Data & cart token layer (inert)

**Effort: M** · _Schema + model methods + cron + admin toggles. Nothing
user-visible changes: no route reads the token yet, and every new flag defaults
to off._

### 6.1 Goal

Give carts the ability to be owned by a random guest token instead of a member
id, add the columns and tables the later phases need (`registered_via`,
DB-backed rate limits, funnel marker, four settings), extend `Cart` with the
token lifecycle methods, add the idle-cart cron leg, and expose the flags in
the admin settings UI.

### 6.2 Entry / Exit state

- **Entry:** P1 merged; suite green with render diff.
- **Exit:** `carts` can hold a `guest_token` with a nullable `member_id`;
  `Cart::getActiveByToken/getOrCreateForToken/claimFor/mergeInto` exist and are
  unit-tested; `users.registered_via` exists; a `rate_limits` table backs new
  DB-backed limiter helpers; `cron/shop_expiry.php` marks idle guest carts
  `abandoned` and purges them after 90 days; `guest_shop_enabled`,
  `guest_checkout_enabled`, `guest_cart_ttl_days`, `customer_zone_enabled`
  exist in `settings` (all `0` except the TTL) and render as toggles in
  Admin → Settings; `install.sql` matches (`SchemaDriftTest` green). Still no
  behaviour change anywhere.

### 6.3 Decisions applied

D7′ (flag inventory), D15 (attribution cookie groundworks — no, cookie lands in
P3; here only the TTL setting), D18 (seat counting changes **later** in P4 —
this phase only stages settings), D2 (claim/merge primitives the atomic
checkout needs).

### 6.4 Work items

1. **Migration `migrate_guest_shop.sql` _(new)_** (and mirrored into
   `install.sql`; the repo keeps numbered migrations under `tmp/altas/migrations/`
   and root-level `migrate_*.sql` — follow the root-level convention):

   ```sql
   -- carts: token ownership (guest carts) + funnel marker
   ALTER TABLE carts
     MODIFY member_id INT UNSIGNED NULL,
     ADD COLUMN guest_token      CHAR(64) NULL AFTER member_id,
     ADD COLUMN guest_started_at TIMESTAMP NULL AFTER guest_token,
     ADD UNIQUE KEY idx_carts_guest_token (guest_token),
     ADD KEY idx_carts_guest_idle (guest_token, status, updated_at);
   -- (member_id NULL + existing FK stays; a member-owned row simply never
   --  sets guest_token. Existing rows untouched.)

   -- users: registration provenance (D2, analytics, support)
   ALTER TABLE users
     ADD COLUMN registered_via VARCHAR(30) NULL AFTER status;
   -- values: NULL (legacy) | 'register' | 'guest_checkout'

   -- DB-backed rate limits (the session limiter can't see cookie-less bots)
   CREATE TABLE rate_limits (
     id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
     bucket_key    VARCHAR(191) NOT NULL,          -- e.g. 'gco|ip|1.2.3.4'
     hits          INT UNSIGNED NOT NULL DEFAULT 0,
     window_started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
     UNIQUE KEY idx_rl_bucket (bucket_key)
   ) ENGINE=InnoDB;

   -- settings (all inert until P3/P4/P5 read them)
   INSERT INTO settings (key_name, value) VALUES
     ('guest_shop_enabled',      '0'),
     ('guest_checkout_enabled',  '0'),
     ('customer_zone_enabled',   '0'),
     ('guest_cart_ttl_days',     '14');
   ```

   Plus, if P0's duplicate check came back clean, the placement-unique index
   from the spec §10: `ALTER TABLE users ADD UNIQUE KEY idx_binary_slot
(binary_parent_id, binary_position)` — NULLs don't collide in MySQL unique
   indexes, so unplaced members are unaffected.

2. **DB-backed limiter helpers _(new)_** — `core/helpers.php`:
   `rate_limit_db(string $bucket, int $max, int $windowSec): bool` and
   `rate_limit_db_hit(string $bucket): void`, using `INSERT … ON DUPLICATE KEY
UPDATE` for atomic window rotation. Session limiter stays untouched for the
   login/slogin flows that already use it.
3. **Cart token lifecycle _(new)_** — `models/Cart.php`:
   - `getActiveByToken(string $token): ?array`
   - `getOrCreateForToken(string $token): array` — sets `guest_started_at` on
     create; reuses the existing `status='active'` semantics.
   - `claimFor(int $cartId, int $userId): void` — `member_id = $userId`,
     `guest_token = NULL` (keeps status `active`). Used inside the P5
     transaction.
   - `mergeInto(int $userId, int $fromCartId): array` — line-by-line merge into
     the member's active cart via the existing `addItem` (so every quantity cap
     and stock rule applies), quantity **clamped to available stock**, returns
     a report `{merged, clamped[]}` and marks the source cart `converted`.
4. **Cron leg** — `cron/shop_expiry.php`: after the existing two legs, add
   "Leg 3: guest carts" — `UPDATE carts SET status='abandoned' WHERE
guest_token IS NOT NULL AND status='active' AND updated_at < NOW() -
INTERVAL guest_cart_ttl_days DAY` (read the setting once per run), and purge
   `abandoned` guest carts older than 90 days (`guest_started_at` +
   `updated_at`). Log counts like the other legs.
5. **Admin settings UI** — `AdminController::saveSettings` key list
   (`AdminController.php:531-596`) + `views/admin/settings.php`: add the three
   toggles (checkbox pattern like `shop_enabled` at `views/admin/settings.php:68`)
   and the TTL as a number input, with helper text ("Guest storefront",
   "Guest checkout (creates accounts)", "Customer zone split").
6. **Funnel marker note** — no code beyond `guest_started_at`: the funnel
   (started → ordered → abandoned) is computed from existing columns in P3/P7
   queries; no new table.

### 6.5 Schema & settings

Everything in item 1 above. Nothing else.

### 6.6 Tests

- `Cart` token lifecycle: create → get → add items → claim → member-owned;
  two tokens are isolated (no cross-visibility).
- `mergeInto`: merge into empty member cart; merge with clamping when member
  cart + guest line exceed stock; merge marks source `converted`.
- Cron leg: fixture cart older than TTL becomes `abandoned`; >90-day abandoned
  row purged; member carts untouched.
- `SchemaDriftTest` green (migration ↔ `install.sql` in sync).
- Full suite green (nothing reads the new columns yet — proof of inertness).

### 6.7 Acceptance criteria

- [ ] Migration applies cleanly to a copy of production data (run on the P0
      dump) and `install.sql` matches.
- [ ] Token cart methods unit-tested including isolation and clamping.
- [ ] Cron leg tested; `cron/README.md`-style logging consistent with existing
      legs.
- [ ] Four settings visible in Admin → Settings, defaults as specified.
- [ ] `php tests/run.php` green; zero behaviour change (all flags off).

### 6.8 Rollout & rollback

Deploy the migration + code any time after P1. Rollback: the DDL is additive —
leaving it in place after a code rollback is harmless; to fully reverse, drop
the columns/table/keys and delete the four settings rows.

### 6.9 Risks carried

- `carts.member_id` nullability interacts with the FK `ON DELETE CASCADE`
  (R-05): a NULL `member_id` simply never fires the FK; verified in test.
- `rate_limits` writes add a hot-row per bucket (R-11): buckets are
  per-IP/per-mobile/per-purpose keys with short windows; acceptable volume for
  this platform, monitor in P7.

---

## 7. Phase 3 — Guest storefront & cart (Milestone 1)

**Effort: L** · _First user-visible phase, and the engagement deliverable:
anonymous visitors get the full member shopping experience. Ships behind
`guest_shop_enabled`._

### 7.1 Goal

A visitor without an account can browse `?page=store`, add to cart, adjust
quantities, and see the same grid, badges ("N left", "Sold out"), mini-cart
drawer and cart page a member sees — rendered from the **same templates** in
store mode. The cart lives on an httponly token cookie and holds no stock. The
cart's checkout button reads **"Join to continue"** until P5, bridging to
register/login with the cart merged on the other side.

### 7.2 Entry / Exit state

- **Entry:** P1 templates are context-aware; P2 token cart + flags exist;
  `guest_shop_enabled` still `0`.
- **Exit:** five `store*` routes live (`store`, `store_add_to_cart`,
  `store_cart`, `store_update_cart_item`, `store_remove_cart_item`); guest cart
  cookie (14 days, httponly, samesite=Lax) + sponsor cookie (30 days,
  last-click) work; the landing page links to the Shop; a DB-backed IP limiter
  caps guest cart creation; funnel counts are computable; logged-in users who
  hit `store*` are redirected to the member equivalents; enabling
  `guest_shop_enabled` turns the whole thing on with no further deploy.

### 7.3 Decisions applied

D1 (guest ≠ anonymous buyer — carting only here, ordering in P5), D4′/D15
(sponsor cookie capture, last-click 30-day), D7′ tier 1, D9 (merge on
register/login), D11–D13 (parity, architecture, location), D21 (inside the
app).

### 7.4 Work items

1. **`controllers/GuestShopController.php` _(new)_** — thin handlers, one guard
   at the top of each:

   ```php
   private function guardStore(): void {
       if (setting('shop_enabled','1') !== '1' || setting('guest_shop_enabled','0') !== '1') {
           flash('error', 'The shop is currently closed.');
           redirect('/?page=login');   // guests have no dashboard to fall back to
       }
       if (Auth::check()) { redirect(shop_link('shop')); }  // D9: members use their pages
   }
   private function guestCart(): array {
       $token = $_COOKIE['guest_cart'] ?? '';
       if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
           if (!rate_limit_db('gcart|ip|' . client_ip(), 20, 86400)) { /* 429 page */ }
           $token = bin2hex(random_bytes(32));
           setcookie('guest_cart', $token, [
               'expires' => time() + 86400*(int)setting('guest_cart_ttl_days','14'),
               'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
           ]); // secure => true once P0 confirms HTTPS-only
       }
       return Cart::getOrCreateForToken($token);
   }
   ```

   Handlers: `store` (grid, `Product::active()`), `store_add_to_cart`,
   `store_cart`, `store_update_cart_item`, `store_remove_cart_item` — each
   mirrors its `MemberController` counterpart (`shop` `:1232`, `cart` `:1277`,
   `addToCart` `:1297`, `updateCartItem`, `removeCartItem`) but resolves the
   cart from the token and renders in store mode. POST verbs call
   `csrf_verify()` (the CSRF token is seeded for all routes at
   `index.php:425`). `client_ip()` _(new helper)_ wraps `REMOTE_ADDR` (or the
   trusted-proxy header per P0's answer).

2. **Routes _(new)_** — append to the route table in `index.php` with role
   `'any'` (the router's `any` applies no check; the controller's
   `guardStore()` owns both the flag and the logged-in redirect):

   ```php
   'store'               => ['GuestShopController', 'store',            'any'],
   'store_add_to_cart'   => ['GuestShopController', 'addToCart',        'any'],
   'store_cart'          => ['GuestShopController', 'cart',             'any'],
   'store_update_cart_item' => ['GuestShopController', 'updateCartItem','any'],
   'store_remove_cart_item' => ['GuestShopController', 'removeCartItem','any'],
   ```

3. **Guest shell** — `shell_begin/shell_end` gain a `store` mode: brand +
   minimal nav + cart button (with count) + "Log in" and "Become a partner"
   CTAs; no sidebar, no wallet/avatar chrome (D11). One CSS modifier:
   `.main-content--full { margin-left: 0; }` next to
   `assets/css/layout.css:211-212`. New partials
   `views/partials/store_header.php` / `store_footer.php` _(new)_.
4. **Store-mode rendering** — the P1 `shop_ctx()` now returns `store` mode on
   `store*` routes; `shop.php`, `cart.php` and the off-canvas render through it.
   Buttons that a guest cannot use are swapped by ctx: checkout → "Join to
   continue" linking `/?page=register&ref=1&sponsor=<cookie sponsor>` when a
   sponsor cookie exists, else `/?page=register`; stock badges, quantity
   steppers, drawer behaviour identical (D11).
5. **Sponsor capture** — on `store*` GETs, `?sponsor=<username>` (validated
   against `User::findByUsername`) sets the 30-day `guest_sponsor` cookie,
   last-click (D15). Display is P5's job; here it is only captured.
6. **Cart merge on the far side of join/login (D9)** — after successful
   `doRegister` (`AuthController.php:240` area, at the post-register redirect)
   and after successful `doLogin`: if a `guest_cart` cookie exists, run
   `Cart::mergeInto($newUserId, $guestCartId)`; on any clamp, flash
   "Some quantities were reduced to available stock"; delete the cookie and
   mark the cart `converted`. (Merge helper from P2; no new logic.)
7. **Landing page link** — `frontend/index.php`: "Shop" nav item →
   `/?page=store` (plain link; the landing has no session, V1).
8. **Funnel queries _(new, docs only)_** — record in the spec:
   started = `COUNT(carts WHERE guest_started_at IS NOT NULL)` in period;
   ordered = claimed (`member_id NOT NULL AND status='converted'`);
   abandoned = cron-marked; conversion = ordered/started. No new table.

### 7.5 Schema & settings

Uses P2's columns/flags; adds nothing. Reads `guest_shop_enabled`,
`guest_cart_ttl_days`.

### 7.6 Tests

- Guest can: land on `?page=store`, add N items, drawer shows them, adjust
  quantities on the cart page, remove items, and the cart survives a browser
  restart (cookie) for 14 days.
- Token isolation: two different tokens never see each other's carts.
- Logged-in member hitting any `store*` route → redirected to the member
  equivalent with their own cart.
- Flags: `guest_shop_enabled=0` → every `store*` route redirects/closes;
  `shop_enabled=0` → same (tier-1 gate, D7′).
- Cart creation IP cap: 21st cart from one IP in 24 h is refused (DB limiter).
- Merge on register and on login, including the clamping flash and
  `converted` marking.
- `store*` pages are functional without JavaScript (POST + redirect + `?cart=1`
  drawer open — the current member mechanism, P6 upgrades it).
- Full suite green (member routes untouched).

### 7.7 Acceptance criteria

- [ ] All five routes + guard behaviour verified; guest experience visually
      matches the member shop (side-by-side screenshot review).
- [ ] Merge works both after register and after login (D9), with clamp notice.
- [ ] IP cap and 14-day TTL active; sponsor cookie set from `?sponsor=`.
- [ ] Landing page shows the Shop link.
- [ ] With `guest_shop_enabled=0`, nothing about member behaviour differs from
      today's snapshot.
- [ ] Suite green; render diff still green for member mode.

### 7.8 Rollout & rollback

Enable `guest_shop_enabled = 1` in Admin → Settings (first flag to go live —
P7 defines the soak). Rollback: set it back to `0`; guests lose the store link
target; any existing token carts simply idle out via the cron leg.

### 7.9 Risks carried

- Open add-to-cart lets anonymous clients write cart rows (R-09): capped by the
  IP limiter and reaped by the cron; carts hold no stock so there is no
  inventory denial surface (Appendix D).
- Every no-referral join is sponsored by the primary admin (D4′) — the admin's
  direct downline share grows (R-12); acceptable per the owner, monitor in P7.
- `REMOTE_ADDR`-dependent limiter (R-10) — resolved in P0 or shipped with the
  trusted-proxy override.

---

## 8. Phase 4 — Customer/partner zone

**Effort: L** · _The biggest behavioural change of the program: free members
stop seeing the MLM dashboard and get a storefront "account zone"; the
dashboard becomes partner + admin only. Ships behind `customer_zone_enabled`._

### 8.1 Goal

Split the member area into two zones by user type (D16): **customers**
(pending members) get the storefront shell — shop, cart, checkout, orders,
invoices, profile & addresses, activation — with a new account home that
carries the "Activate Now" prompt; **partners** keep the dashboard exactly as
today (D22). Activation moves into the customer zone and becomes the moment a
seat is consumed (D18) and binary placement happens (D19). E-wallet checkout
and Send Money become partner-only (D20). Nineteen routes plus the two
transfer routes switch to a new `partner` role; the 21 customer routes switch
to a new `account` role.

### 8.2 Entry / Exit state

- **Entry:** P1 shells exist (adopted in `shop.php`, `cart.php`); P2 flags
  exist (`customer_zone_enabled = 0`); P0 audits A1–A3 recorded with
  grandfathering rules.
- **Exit:** `Auth::isCustomer()/isPartner()/homeFor()` exist; router roles
  `account` and `partner` are enforced; the seven customer templates render in
  the storefront shell for customers and the dashboard shell for partners; the
  account home exists at `?page=account_home`; `doActivate` checks the seat
  limit (partner-count semantics) and places unplaced customers at activation;
  Super-Login lands customers on the account home; the admin users list shows
  "Customers" as the renamed pending filter; with
  `customer_zone_enabled = 0` the app behaves byte-for-byte like today.

### 8.3 Decisions applied

D16 (rule), D17 (branding-only naming — the customer zone may say "affiliate"
in copy), D18 (seats at activation), D19 (placement at activation,
grandfathering), D20 (e-wallet partner-only), D21 (storefront zone inside the
app), D22 (partners unchanged).

### 8.4 Work items

1. **Identity helpers _(new)_** — `core/Auth.php` next to `isMember()`
   (`core/Auth.php:160`):

   ```php
   public static function isCustomer(): bool  // role member AND status pending
   public static function isPartner(): bool   // role member AND status != pending
   public static function homeFor(array $user): string
   //   admin/super → /?page=admin ; partner → /?page=dashboard ;
   //   customer    → customer_zone_enabled ? /?page=account_home : /?page=dashboard
   ```

   `homeFor()` centralizes every landing decision; impersonation and the
   unknown-page fallback use it too.

2. **Router roles _(new)_** — extend the guard block at `index.php:407-422`:

   ```php
   if ($role === 'account' && !Auth::check()) { flash → login; }
   if ($role === 'partner' && (!Auth::check() || (Auth::isMember() && !Auth::isPartner()))) {
       flash('info', 'Partner tools unlock when you activate your account.');
       redirect(Auth::homeFor(Auth::user()));   // customer → account home
   }
   ```

   Both zones can be active while `customer_zone_enabled = 0`: in that mode
   `isPartner()` returns true for every signed-in member, so behaviour is
   identical to today (that is the flag's exact meaning: whether pending
   members are second-class). Implement the flag **inside** `isPartner()` /
   `isCustomer()` — one place, zero scattered conditionals.

3. **Switch the route table** (Appendix A is the authoritative list):
   - 21 customer routes `'member'` → `'account'` (16 shop + `profile`,
     `save_profile` + `activate`, `do_activate`, `get_packages`).
   - 19 partner routes + `ewallet_transfer` + `do_ewallet_transfer` →
     `'partner'` (Send Money finally has a real guard — today it is
     `Auth::check()` only, `MemberController.php:1078,1102`).
   - Unknown-page fallback (`index.php:399-402`) and the `guest`-role redirect
     (`:408-410`) → `Auth::homeFor()`.
4. **Shells on the seven customer templates** — adopt
   `shell_begin/shell_end` in `checkout.php`, `shop_orders.php`,
   `shop_order.php`, `profile.php`, `activate.php` (they are already in
   `shop.php`/`cart.php` from P1). Shell choice by ctx: customers get the
   storefront shell (P3's, plus an account menu: Orders, Profile,
   Activate, Logout); partners keep the dashboard shell (D22). The invoice
   (`views/shop/invoice.php`) stays standalone.
5. **Account home _(new)_** — route `account_home` →
   `MemberController::accountHome()` _(new)_ + `views/store/account_home.php`
   _(new)_: order summary cards (recent orders, awaiting proof), the
   "Activate Now" primary CTA (the existing pending banner's replacement —
   the old banner in `dashboard.php` dies with the split), and partner-teaser
   copy ("Become a partner" → `?page=activate`) — this is where D17's
   affiliate-flavoured branding lives if the owner wants it. Login/register
   redirects (`AuthController::doLogin`, `doRegister`) send customers here via
   `homeFor()`.
6. **Activation in the zone (D18, D19)** — `MemberController::doActivate`
   (`:635`) changes:
   - **Seat check with partner semantics:** `seatsRemaining()`
     (`core/helpers.php:160-162`) changes its count to
     `WHERE role='member' AND status <> 'pending'`; `doActivate` refuses
     activation when the limit is reached (flash + stay on activate). Free
     registration stops consuming seats: the router hard-block
     (`index.php:255-268`) and `User::register()`'s check
     (`models/User.php:13`) remain as a **total-network safety ceiling** but
     are no longer the customer gate — P0's open item 5 decides whether the
     register-page block stays keyed to the old or new semantics (default:
     new).
   - **Placement at activation:** an unplaced customer activating with a
     pairing-enabled package gets `User::findNextBinarySlotNetworkWide()`
     auto-placement (the pattern `AuthController.php:399-407` already uses for
     binary-less registrars), with the existing manual-override option on the
     activate page. Grandfathered placed customers keep the current rule:
     pairing-enabled packages only (`MemberController.php:613-620,650,668`).
7. **E-wallet scope (D20)** — member `checkout` (`MemberController.php:1411-1417`)
   hides the ewallet option when `Auth::isCustomer()`; `placeOrder` rejects
   `method === 'ewallet'` server-side for customers (same defence P5 uses for
   guests). `Ewallet::transfer` gains a partner-to-partner rule; wallet-holding
   customers per audit A3 get the P0-decided path (default: admin-mediated
   withdrawal via the existing admin top-up/monitor tools, no customer-side
   transfer UI).
8. **UI hygiene** — `views/member/profile.php`: wrap the Payout Information
   section (GCash/Maya/USDT) in `if (Auth::isPartner())`; genealogy "Register
   Member" affordances are partner-only already by the route switch; remove
   the now-dead pending banner from `views/member/dashboard.php`; sidebar
   (`views/partials/sidebar_member.php`) shows the shop group for customers in
   the storefront shell instead (P3's store nav).
9. **Impersonation landing** — `AuthController::doSlogin` redirect
   (`AuthController.php:126`) → `Auth::homeFor($target)` so a Super-Login into
   a customer opens the account home, not the forbidden dashboard.
10. **Admin surface** — `views/admin/users.php`: rename the "Pending
    Activation" filter chip to "Customers", add a count badge;
    `registered_via` (P2) shown in `user_view.php` when present. No new admin
    routes.

### 8.5 Schema & settings

Reads `customer_zone_enabled` (P2). The `seatsRemaining()` semantic change is
code-only. No DDL.

### 8.6 Tests

- **Role-by-route matrix test _(new, `tests/RouteMatrixTest.php`)_** — for
  every route in Appendix A × actor {anonymous, customer, partner, admin} ×
  flag {0, 1}: expected outcome (200 / redirect target / flash). This is the
  safety net for the 42-route change and stays in the suite forever.
- Shell render tests: the seven templates render with the storefront shell for
  a customer and the dashboard shell for a partner (ViewRenderTest extension).
- Activation: unplaced customer + binary package → placed network-wide (D19);
  grandfathered placed customer still restricted to pairing packages; seat
  limit (partner-count) blocks activation and the flash is correct; a pending
  registration **does not** consume a seat anymore.
- E-wallet: customer checkout hides ewallet and `place_order` POST with
  `payment_method=ewallet` is rejected server-side; transfer between customer
  and anyone is refused.
- Impersonation: Super-Login as customer lands on account home; as partner,
  dashboard.
- Flag off: every actor's page access matches the pre-phase snapshot (matrix
  asserts both flag states).

### 8.7 Acceptance criteria

- [ ] Matrix test green in both flag states; Send Money unreachable for
      customers.
- [ ] Customer sees: account home, shop/cart/checkout/orders/invoice/profile/
      addresses, activation — nothing else; every partner URL redirects with
      the "activate" flash.
- [ ] Partner experience byte-identical to today (render diff on dashboard,
      shop, checkout as partner).
- [ ] Activation consumes the seat and places the customer; grandfathering
      rules verified against audit A2's live rows (or fixtures reproducing
      them).
- [ ] Admin list shows "Customers"; Super-Login lands correctly.
- [ ] `php tests/run.php` green.

### 8.8 Rollout & rollback

Enable `customer_zone_enabled = 1` (second flag, after the P3 soak — P7).
Rollback: flag to `0` — every pending member instantly regains today's
dashboard experience; nothing else to undo (the route roles stay, they are
flag-aware through `isPartner()`).

### 8.9 Risks carried

- Blast radius of the 42-route role change (R-03) — matrix test + dual-state
  assertions + the flag.
- Existing members' expectations: pending members lose the dashboard they had
  (R-06) — announce before enabling; the account home must be genuinely useful
  (orders + activation), which P4 delivers.
- Grandfathering edge cases from A1–A3 (R-07): pending sponsors (keep
  sponsorship data, lose the referral UI), placed pendings (rule above),
  wallet holders (admin-mediated path).
- Seat semantics change (R-08): the limit now means "partner capacity";
  communicate the new number meaning in admin copy.

---

## 9. Phase 5 — Guest checkout & atomic registration (Milestone 2)

**Effort: L** · _The original feature: a guest places an order and becomes a
pending customer in one transaction. Two sub-steps: 5a extracts the
registration service (pure refactor, no behaviour change), 5b builds the guest
checkout on top. Ships behind `guest_checkout_enabled`._

### 9.1 Goal

A guest who has filled the cart can check out: one Recipient block + address +
manual payment + a lean account block (auto-suggested username, one password).
Submitting runs **one transaction**: gates → register the user (pending,
unplaced, sponsored per D4′) → claim the token cart →
`ShopOrder::createFromCart` → commit → `Auth::login` → land on the order page
("Upload your payment proof") with a one-time "Your login" card. Any failure
rolls back everything. Double-submits and lost responses land on the same
order via cart-token idempotency.

### 9.2 Entry / Exit state

- **Entry:** P3 store routes + token cart live; P4 account zone live (the new
  account lands there); `guest_checkout_enabled = 0`.
- **Exit:** `store_checkout` and `store_place_order` routes work end-to-end;
  the registration core lives in a service that adopts an outer transaction;
  checkout rate limits (3/IP, 2/mobile per 24 h), username-check throttle and
  a honeypot are active; `GuestCheckoutTest` covers the failure matrix; the
  flag still defaults off.

### 9.3 Decisions applied

D1, D2 (atomic account+order), D3′ (lean credentials), D4′ (sponsor), D6
(manual payments only), D7′ tier 2, D8 (mobile required, notice, address
auto-save), D14 (field budget), D19 (unplaced), D18 (no seat gate).

### 9.4 Work items — 5a: registration service extraction (refactor first)

1. **`core/RegistrationService.php` _(new)_** (or `User::registerNewMember()`
   — owner's call; a service keeps `User` pure): move the validation + creation
   core out of `AuthController::doRegister` (`:240-470` area):
   - `validateNewMember(array $in): array` — username (`is_valid_username`,
     `User::usernameExists`), password ≥ 8, sponsor resolution, payment
     validity. Returns a normalized payload; throws buyer-safe errors.
   - `create(array $data): int` — today's `User::register()` body
     (`models/User.php:11`) with two changes, both flagged in the spec:
     **(a)** transaction-aware like `ShopOrder::createFromCart`
     (`ShopOrder.php:711-714`): `$ownsTxn = !$pdo->inTransaction()`; **(b)**
     the seat check becomes lock-aware (`SELECT ... FOR UPDATE` on the count or
     an atomic `INSERT ... SELECT WHERE count < limit` pattern) — P0 open item
     5 decides whether `doRegister` adopts the lock too (default: yes).
   - Move `findNextBinarySlot` from private `AuthController.php:207` to
     `public static User::findNextBinarySlot(int $sponsorId)` beside
     `findNextBinarySlotNetworkWide` (`models/User.php:675`); the controller
     delegates. (P4's activation placement already uses the network-wide
     variant.)
   - Slot race hardening: the unlocked `COUNT(*)` slot check
     (`models/User.php:64-72`) gains the P2 unique index
     `(binary_parent_id, binary_position)` as its real guard (catch duplicate-
     key → "position taken" error); the unique index on `username` already
     exists.
   - `AuthController::doRegister` becomes a thin caller; the register page's
     behaviour, messages and redirects are unchanged.
   - **Regression gate:** full suite + a manual pass of every register mode
     (free, referral, code, e-wallet-by-registrar) before 5b starts.

### 9.5 Work items — 5b: the guest checkout

2. **`store_checkout` (GET)** — `GuestShopController::checkout()` _(new)_:
   tier-2 gate (D7′: `shop_enabled` + `guest_shop_enabled` +
   `guest_checkout_enabled` + `free_registration_enabled`; seat check **not**
   included, D18) checked at render; if tier 2 fails but tier 1 passes, the
   cart's button stays "Join to continue" (P3 behaviour). Render the shared
   checkout in store mode:
   - **Recipient block** (D14, the P1 partial): full name + mobile → feeds
     `billing_name/billing_phone` and `shipping_name/shipping_phone`.
   - Address picker (`views/partials/address_picker.php`) + street line; no
     saved-addresses list (D11).
   - Payment methods: `gcash, maya, usdt_trc20, usdt_bep20` only (D6) —
     ewallet absent from the array the template iterates
     (`MemberController.php:1411-1417` pattern).
   - **Account block** (D3′): username input pre-suggested from the recipient
     name (slugified, truncated to 40, editable; availability via the existing
     `check_username` route — role `any`, `index.php:280` — now throttled,
     item 5), one password field with show/hide, ≥ 8 chars; honeypot field
     _(new)_ (`<input name="company" class="d-none" tabindex="-1">`).
   - **Sponsor block** (D4′): sponsor cookie → read-only "Referred by @x";
     no cookie → nothing shown + "I was referred by someone" toggle revealing
     one field (validated like the register form's).
   - Notice: "Ordering creates your free account" + terms checkbox (D8);
     total ≈ 8–9 visible inputs (D14).
3. **`store_place_order` (POST)** — `GuestShopController::placeOrder()`:

   ```php
   guardStore(); tier2Gate();                       // D7′ — again, server-side
   csrf_verify(); honeypot_check();                 // silent-drop bots
   $limits = rate_limit_db('gcheckout|ip|'.client_ip(), 3, 86400)
          && rate_limit_db('gcheckout|mob|'.hash('sha256',$mobile), 2, 86400);
   $token = guest_cart_token(); $cart = Cart::getActiveByToken($token);
   $idem  = sha1('guest|' . $token . '|' . $cart['id'] . '|' . ($_POST['idem'] ?? ''));
   // (a) idem fast-path FIRST — before any username work (see race note)
   if ($existing = ShopOrder::findByIdeKey($idem)) { /* already theirs → login */ }  // findByIdeKey *(new)*

   $pdo = db(); $pdo->beginTransaction();
   try {
       $payload = RegistrationService::validateNewMember([...guest form...]);
       $userId  = RegistrationService::create([   // adopts this txn (5a)
           'pending' => true,                      // D16 customer
           'registered_via' => 'guest_checkout',   // P2 column
           'sponsor_id' => $cookieSponsorId ?? User::primaryAdminId(),  // D4′
           'binary_parent_id' => null, 'binary_position' => null,       // D19
           'full_name' => $recipientName, 'mobile' => $mobile,          // V2 columns
       ]);
       Cart::claimFor((int)$cart['id'], $userId);  // P2: member_id=uid, token=NULL
       $orderId = ShopOrder::createFromCart(       // adopts the same txn
           $userId, (int)$cart['id'], $addr, $method, $idem);
       $pdo->commit();
   } catch (Throwable $e) {
       if ($pdo->inTransaction()) $pdo->rollBack();   // no user, no order, no claim
       shop_checkout_remember_input($_POST); flash('error', buyerSafe($e));
       redirect('/?page=store_checkout');
   }
   Auth::login(User::find($userId));                 // session starts here
   $this->maybeSaveCheckoutAddress($addr);           // best-effort, post-commit (D8)
   $_SESSION['guest_welcome'] = ['username' => $username];   // own key: flash carries one string
   redirect('/?page=shop_order&id=' . $orderId);
   ```

   - **Username race:** if `create()` throws a duplicate-`username` error,
     re-check the idem key first (a double-submit whose first attempt
     committed); if no order exists, surface "username taken" with the form
     remembered.
   - **Lost response after commit:** the claim nulled the token, but the idem
     key embeds the token _string_, so a retry of the same POST recomputes the
     same key, finds the order, and redirects to login (the account exists —
     D2).
   - **Welcome card:** `views/member/shop_order.php` shows a one-time "Your
     login — username @x" card when `$_SESSION['guest_welcome']` is set, then
     unsets it; the username is also repeated in the flash (no recovery channel
     — make it unmissable, V4).
   - `buyerSafe($e)` maps `InvalidArgumentException | RuntimeException` (the
     `createFromCart` contract, `ShopOrder.php:685`) to the message, everything
     else to a generic line — same shape as `MemberController.php:1537-1548`.

4. **Order page as confirmation** — the P4 account-zone `shop_order` page
   already carries proof upload, instructions and (P5) the welcome card; the
   post-order "Activate Now" nudge lives on the account home. No new
   confirmation screen (D10, and the Baymard-aligned "account prompt after the
   order" pattern).
5. **`check_username` throttle** — add
   `rate_limit_db('uname|ip|'.client_ip(), 10, 900)` inside
   `AuthController::ajaxCheckUser`; response shape unchanged.
6. **Routes _(new)_**:

   ```php
   'store_checkout'    => ['GuestShopController', 'checkout',   'any'],
   'store_place_order' => ['GuestShopController', 'placeOrder', 'any'],
   ```

### 9.6 Schema & settings

Reads `guest_checkout_enabled` (P2). No new DDL. Tunables marked _(new)_:
checkout caps 3/IP/24 h and 2/mobile/24 h, username-check 10/15 min — all
proposals to tune from P7 data.

### 9.7 Tests (`tests/GuestCheckoutTest.php` _(new)_)

- Happy path: cart → checkout → place → account exists (pending, unplaced,
  `registered_via='guest_checkout'`, sponsor = cookie or primary admin,
  `full_name`/`mobile` set) + order `pending` + logged-in order page + welcome
  card once.
- **Rollback-on-failure:** force failures at each step (invalid input, stock
  changed between render and POST — `Cart::validateStock` throws, payment
  method forged to `ewallet`, DB error) → **no** user row, **no** order row,
  cart still token-owned and intact.
- Double-submit (same idem): second POST lands on the same order; no second
  user.
- Same-username race: two concurrent posts, different guests, same username →
  one succeeds, one gets "username taken" (or lands on its own order if it was
  a double-submit).
- Lost-response replay: recompute idem after commit → login redirect.
- Tier-2 gate permutations: each flag off → button degrades to "Join to
  continue"; forged POST to `store_place_order` refused.
- Rate limits: 4th checkout success from one IP refused; 3rd from one mobile
  refused; honeypot filled → silent drop.
- E-wallet forged POST rejected server-side (D6).
- Last stock unit: two guests, one unit → one order, other gets the stock
  error with remembered input.
- Merge clamping (from P3) re-verified post-checkout paths.

### 9.8 Acceptance criteria

- [ ] 5a refactor shipped separately with zero register-behaviour change
      (suite + manual matrix of register modes).
- [ ] Every test above green; welcome card shows once; username visible.
- [ ] Manual end-to-end on staging: guest buys, uploads proof, admin
      transitions the order to completed — the `buyer_workflow.md` §8–§11
      journey works unchanged for the guest-born account.
- [ ] Flag still `0` in production at phase close (enablement is P7).

### 9.9 Rollout & rollback

Enable `guest_checkout_enabled = 1` last (P7). Rollback: flag → `0`; the
storefront and cart keep working (tier 1), checkout button reverts to "Join to
continue"; accounts already created remain ordinary pending members.

### 9.10 Risks carried

- `User::register()` refactor touches every registration path (R-04) — 5a is
  its own shippable commit with the register-mode matrix as its gate.
- Junk accounts: a guest account is only created together with a real order
  attempt, capped 3/IP + 2/mobile per day (R-09); accounts are **not**
  auto-deleted when the order expires (D2 — placement-free now, deletion stays
  out of scope).
- 24-hour stock holds by pending guest orders (R-09): the existing expiry cron
  releases them; caps bound the exposure.
- No recovery channel (R-13): username surfaced twice (card + flash), mobile
  required; admin password reset exists (`AdminController` user tools); SMS
  recovery is backlog (P7).

---

## 10. Phase 6 — Hardening, continuity & polish

**Effort: M** · _Quality pass over everything P3–P5 shipped, plus the
session-hardening item that has been parked since the source review. Mostly
small, independent items — safe to interleave._

### 10.1 Goal

Close the remaining security and UX gaps: add-to-cart without a page reload
(shared — members benefit too), hardened session cookie flags, the attribution
disclosure line, the partners' Shop share link, impersonation re-verification,
and the funnel report that P7's rollout review reads.

### 10.2 Entry / Exit state

- **Entry:** P5 complete (guest checkout working behind its flag); all three
  flags may still be off in production.
- **Exit:** add-to-cart is an AJAX call with button state change + drawer open
  (with the POST+redirect fallback retained); `index.php:46` uses a hardened
  session start (correct cookie flags, **no** cookie rename — nobody is signed
  out); the sponsor-cookie disclosure line renders in the storefront footer;
  genealogy shows the Shop share link; Super-Login verified again for both
  zones; a funnel report (SQL + admin notes, or a tiny admin widget if the
  owner wants one) exists.

### 10.3 Decisions applied

D11 (drawer parity incl. AJAX feedback — the research gap the member shop
also had), D15 (disclosure), D22 (partner-side links), plus spec §11 hardening
items.

### 10.4 Work items

1. **AJAX add-to-cart (shared).** `views/member/shop.php` grid buttons and the
   off-canvas quantity steppers post via `fetch()` with the `X-Requested-With`
   header — `MemberController::addToCart` already has an XMLHttpRequest branch
   (`MemberController.php:1308`); extend `GuestShopController` symmetrically.
   Button state → "Added ✓" + badge bump + drawer opens (no reload). The
   no-JS fallback (POST + redirect + `?cart=1`) stays, so progressive
   enhancement only. Test: `tests/js/` gains a case alongside
   `showconfirm.test.js`.
2. **Session hardening.** Replace bare `session_start()` (`index.php:46`) with
   cookie params via `session_set_cookie_params([...])` — `httponly`,
   `samesite=Lax`, `secure` (P0 confirms HTTPS-only), `session.use_strict_mode`
   — **without** `session_name('mlm_sess')`: `Auth::start()`
   (`core/Auth.php:20-28`) is updated to do exactly this and is then the one
   call site. No cookie rename, no mass logout (spec §15 open item 8).
3. **Attribution disclosure.** Storefront footer line: "A referral cookie
   remembers who introduced you for 30 days" — plain-language, links the
   privacy page. This is a disclosure checklist item for the privacy owner,
   not legal advice (Appendix D, R-14).
4. **Partners' Shop link.** `views/member/genealogy.php`: beside the referral
   link, a "Share the shop" link to `/?page=store?sponsor=<username>` —
   partners without a package still share the storefront (the sponsor cookie
   mechanism handles attribution; the admin default covers the rest).
5. **Impersonation re-check.** Re-run the P4 matrix cases for Super-Login
   (`slogin`/`do_slogin`, `AuthController.php:126`) after all phases; imp
   sessions must survive the new redirects (`redirect()` already preserves
   `imp=`, `core/helpers.php:183-189`).
6. **Funnel report.** The P3 queries, run over the first soak period:
   guest carts started / claimed / abandoned / purged; guest checkout
   conversion; join-to-continue bridge usage; guest-born accounts that later
   activated. Deliverable: numbers in the P7 review; optionally a small admin
   widget later.
7. **Backlog formally parked (not built here):** featured-products strip on
   the landing page (D13), landing-theme restyle (D21), SMS verification →
   mobile login + recovery + deferred-password flow (D3′), Reading-B affiliate
   program (D17), optional referrer-on-order hook.

### 10.5 Schema & settings

None (all behavioural).

### 10.6 Tests

- JS: add-to-cart fetch path + fallback path (no-JS simulation).
- Session: cookie flags present in the response headers; existing sessions
  still valid after deploy (staging proof with a pre-deploy cookie).
- Matrix re-run: full route matrix + impersonation cases green.
- Funnel queries return sane numbers on fixture data.

### 10.7 Acceptance criteria

- [ ] Add-to-cart without reload on both member shop and store; badge + drawer
      correct; fallback intact.
- [ ] Hardened session live; no logout wave (staging-verified); `Auth::start()`
      is the single call site.
- [ ] Disclosure line + Shop share link shipped; impersonation matrix green.
- [ ] Funnel numbers produced for the P7 review.

### 10.8 Rollout & rollback

Ships behind no flag (invisible-or-better changes); the AJAX path could be
feature-detected naturally by the fallback. Rollback: revert; fallback resumes.

### 10.9 Risks carried

- Session hardening signs someone out if the cookie name changes — it must not
  (R-15); staging proof required.
- AJAX drawer regressions on old mobile browsers — fallback keeps them
  shopping (R-02).

---

## 11. Phase 7 — Rollout, monitoring & doc reconciliation

**Effort: S** · _Turn the flags on in order, watch, and make the docs true
again._

### 11.1 Goal

Staged enablement with soak windows and explicit verification gates per flag,
a metrics review against the goals that motivated the whole program
(engagement first, then conversion, then activation), and the spec set
reconciled to "derived from executable source".

### 11.2 Entry / Exit state

- **Entry:** P1–P6 all merged; suite green; staging has exercised all three
  flags together.
- **Exit:** flags enabled in the order below with soak windows; success
  metrics recorded; `guest_buyer_workflow.md`, `customer_zone_workflow.md` and
  `buyer_workflow.md` reconciled line-by-line against the finished code; a
  retrospective written; backlog groomed.

### 11.3 Decisions applied

D7′ and D16 become live via the two guest flags and the zone flag; D15's
attribution and D18's seat semantics are reviewed against production data;
D17's branding is confirmed in the shipped copy; D10's doc-scope rule is
enforced by the reconciliation itself.

### 11.4 Flag enablement order

| Step | Flag                         | Precondition gate                                                                              | Soak                                              | Rollback trigger                                         |
| ---- | ---------------------------- | ---------------------------------------------------------------------------------------------- | ------------------------------------------------- | -------------------------------------------------------- |
| 1    | `guest_shop_enabled = 1`     | P3 acceptance complete; cron leg observed on staging                                           | ≥ 1 week: watch cart-creation rate vs IP cap hits | cart rows or limiter misbehaving → flag 0                |
| 2    | `customer_zone_enabled = 1`  | P4 acceptance complete; **member announcement sent** (pending members lose the dashboard view) | ≥ 1 week: watch support tickets + activation rate | complaints spike / matrix regression → flag 0            |
| 3    | `guest_checkout_enabled = 1` | P5 acceptance complete; funnel from steps 1–2 reviewed; caps tuned                             | ongoing                                           | junk-account or fraud signal → flag 0 (storefront stays) |

The order matches the risk gradient: anonymous browsing (reversible,
harmless), existing-member experience change (needs comms), money-and-account
creation (needs the first two's data).

### 11.5 Work items

1. **Enable per the table above**, recording the verification checklist at
   each gate (acceptance criteria of the corresponding phase).
2. **Metrics review** after each soak: funnel (started → ordered →
   abandoned), checkout conversion, join-to-continue bridge usage,
   guest-born-account activation rate, junk-account rate (accounts whose only
   order expired), support tickets for locked-out guests (username shown
   twice should keep this near zero), admin direct-downline share (R-12).
3. **Doc reconciliation:**
   - `guest_buyer_workflow.md`: update to the built state — every _(new)_
     placeholder resolved to the real name, §10/§13 match the shipped
     schema/routes, §15 open items closed or carried with owners.
   - `customer_zone_workflow.md`: finalize from the P4 build (zones, roles,
     grandfathering outcomes, admin surface).
   - `buyer_workflow.md`: §1 (free registration as the only shop entry) and
     §3 (entering the shop) gain the storefront/guest paths and the
     customer/partner split; §8–§11 verified unchanged.
   - `AGENTS.md`/`README.md`: correct the "no automated tests" claim;
     document the flags and the cron's third leg.
4. **Retrospective + backlog grooming:** what each phase's actual effort was
   vs S/M/L; what the data says about caps and TTLs (tune
   `guest_cart_ttl_days`, checkout caps); schedule the parked items (SMS,
   featured strip, Reading B if the owner ever wants it).

### 11.6 Acceptance criteria

- [ ] All three flags live in the order specified, each with its gate
      checklist recorded.
- [ ] Metrics snapshot attached to the retrospective.
- [ ] All three workflow docs re-verified against the code (the "derived from
      executable source" bar) — citations re-pointed at final line numbers.
- [ ] Backlog list with owners (or explicit "never" marks).

### 11.7 Rollout & rollback

This phase **is** the rollout. Global rollback = the three flags in reverse
order; the system degrades gracefully at each step (checkout → join-bridge →
storefront hidden → members-only, exactly today's behaviour).

### 11.8 Risks carried

- Enabling step 2 without announcing (R-06) — comms is a gate condition, not a
  nice-to-have.
- Metrics misread (e.g., low guest conversion blamed on the form when the
  traffic is bot-filtered) — P6's funnel definitions are the shared source of
  truth.

---

## 12. Cross-cutting concerns

**Security model.** CSRF: every POST verb (member, store, guest checkout)
calls `csrf_verify()`; the token is seeded for all routes (`index.php:425`).
Ownership: guest carts are addressed by a 64-hex httponly cookie token and
every handler re-resolves the cart from the token (no client-supplied ids);
member order pages stay owner-scoped (`resolveOwnOrder`,
`MemberController.php:1217`). Payment: e-wallet is hidden **and** rejected
server-side for guests and customers (D6, D20) — forged POSTs are a test case,
not a hope. Impersonation: URL-nonce sessions ride through the new redirects
unchanged (`core/helpers.php:183-189`).

**Abuse posture (OWASP "denial of inventory").** Carts hold no stock — the
system's design already makes add-to-cart hoarding harmless; the real exposure
is the 24-hour stock hold a `pending` order takes, which is bounded by the
checkout caps (3/IP/24 h, 2/mobile/24 h) rather than by any add-to-cart
friction. No CAPTCHA on add-to-cart (it kills conversion and protects
nothing); honeypot on checkout; DB-backed buckets because the session limiter
(`core/helpers.php:379-404`) is invisible to cookie-less bots. All numeric
caps are _(new)_ proposals to be tuned from P7 data; all of them key on
`client_ip()` _(new)_, whose proxy correctness P0 settles.

**Privacy & consent (PH Data Privacy Act).** The checkout states plainly that
ordering creates an account; the terms checkbox covers it; the sponsor cookie
gets a plain-language disclosure line (P6). A basket-contents cookie is
generally exempt from consent under UK/EU guidance, but that is **not**
Philippine law — this plan treats those as a disclosure checklist for whoever
owns the privacy notice, not as legal advice (R-14). No email is required
(D3′ decision trail); the optional-email open item is P0's to settle.

**Two checkouts, one truth.** The drift risk between member checkout and
guest checkout is structurally contained: both render from the same partials
(P1), both place orders exclusively through `ShopOrder::createFromCart`, and
both validate stock/prices inside the transaction via the same `Cart` methods.
A change to delivery validation or payment handling lands in the partials and
reaches both. The register path is likewise single-sourced through
`RegistrationService` (P5a) for member, referral and guest registration.

**No recovery channel.** Until SMS exists (backlog), a guest who forgets
their username has admin-mediated recovery only. Mitigations shipped: username
shown twice post-checkout, mobile required on the account, admin can search by
name/mobile and reset passwords (verified in the source review, V4).

**Performance.** New writes per guest action: one `rate_limits` upsert and
cart-row writes already bounded by the caps; the funnel adds no tables. The
cron's third leg is a single indexed UPDATE per run. No new hot paths on
member pages.

---

## Appendix A — Route inventory (authoritative)

Current table: `index.php:272-392`. "Target role" is the state after the phase
listed; `account` and `partner` are the new roles from P4. Routes not listed
here (auth: `login`, `do_login`, `register`, `do_register`, `validate_code`,
`check_username`, `check_upline`, `auto_select_upline`, `logout`; super:
`slogin`, `do_slogin`; all `admin*` routes) are unchanged by this program —
except `check_username`, which gains a DB throttle in P5.

### A.1 Customer zone (21 routes → role `account`, P4)

| #   | Route                  | Handler                                | Today  | Target  |
| --- | ---------------------- | -------------------------------------- | ------ | ------- |
| 1   | `shop`                 | `MemberController::shop`               | member | account |
| 2   | `shop_orders`          | `MemberController::shopOrders`         | member | account |
| 3   | `shop_order`           | `MemberController::shopOrder`          | member | account |
| 4   | `shop_invoice`         | `MemberController::shopInvoice`        | member | account |
| 5   | `cart`                 | `MemberController::cart`               | member | account |
| 6   | `add_to_cart`          | `MemberController::addToCart`          | member | account |
| 7   | `update_cart_item`     | `MemberController::updateCartItem`     | member | account |
| 8   | `remove_cart_item`     | `MemberController::removeCartItem`     | member | account |
| 9   | `checkout`             | `MemberController::checkout`           | member | account |
| 10  | `place_order`          | `MemberController::placeOrder`         | member | account |
| 11  | `shop_submit_proof`    | `MemberController::submitShopProof`    | member | account |
| 12  | `shop_cancel_order`    | `MemberController::cancelShopOrder`    | member | account |
| 13  | `shop_confirm_receipt` | `MemberController::confirmShopReceipt` | member | account |
| 14  | `shop_address_save`    | `MemberController::saveAddress`        | member | account |
| 15  | `shop_address_delete`  | `MemberController::deleteAddress`      | member | account |
| 16  | `shop_address_default` | `MemberController::setDefaultAddress`  | member | account |
| 17  | `profile`              | `MemberController::profile`            | member | account |
| 18  | `save_profile`         | `MemberController::saveProfile`        | member | account |
| 19  | `activate`             | `MemberController::activate`           | member | account |
| 20  | `do_activate`          | `MemberController::doActivate`         | member | account |
| 21  | `get_packages`         | `AuthController::ajaxGetPackages`      | member | account |

### A.2 Partner zone (19 + 2 routes → role `partner`, P4)

| #   | Route                       | Handler                                     | Today                            | Target  |
| --- | --------------------------- | ------------------------------------------- | -------------------------------- | ------- |
| 22  | `dashboard`                 | `MemberController::dashboard`               | member                           | partner |
| 23  | `earnings`                  | `MemberController::earnings`                | member                           | partner |
| 24  | `genealogy`                 | `MemberController::genealogy`               | member                           | partner |
| 25  | `api_binary_tree`           | `MemberController::apiBinaryTree`           | member                           | partner |
| 26  | `payout`                    | `MemberController::payout`                  | member                           | partner |
| 27  | `request_payout`            | `MemberController::requestPayout`           | member                           | partner |
| 28  | `update_usdt_gas`           | `AdminController::updateUsdtGas`            | member                           | partner |
| 29  | `update_usdt_bep20_gas`     | `AdminController::updateUsdtBep20Gas`       | member                           | partner |
| 30  | `cap_status`                | `MemberController::capStatus`               | member                           | partner |
| 31  | `dfi_history`               | `MemberController::dfiHistory`              | member                           | partner |
| 32  | `reactivate`                | `MemberController::reactivate`              | member                           | partner |
| 33  | `do_reactivate`             | `MemberController::doReactivate`            | member                           | partner |
| 34  | `api_cap_status`            | `MemberController::apiCapStatus`            | member                           | partner |
| 35  | `api_dfi_status`            | `MemberController::apiDfiStatus`            | member                           | partner |
| 36  | `upgrade`                   | `MemberController::showUpgrade`             | member                           | partner |
| 37  | `do_upgrade`                | `MemberController::doUpgrade`               | member                           | partner |
| 38  | `api_validate_upgrade_code` | `MemberController::ajaxValidateUpgradeCode` | member                           | partner |
| 39  | `api_binary_uplines`        | `MemberController::ajaxBinaryUplines`       | member                           | partner |
| 40  | `api_cd_status`             | `MemberController::apiCdStatus`             | member                           | partner |
| 41  | `ewallet_transfer`          | `MemberController::ewalletTransfer`         | any (controller `Auth::check()`) | partner |
| 42  | `do_ewallet_transfer`       | `MemberController::doEwalletTransfer`       | any (controller `Auth::check()`) | partner |

### A.3 New routes

| Route                    | Handler                               | Role              | Phase | Purpose                 |
| ------------------------ | ------------------------------------- | ----------------- | ----- | ----------------------- |
| `store`                  | `GuestShopController::store`          | any (guardStore)  | P3    | guest storefront grid   |
| `store_add_to_cart`      | `GuestShopController::addToCart`      | any               | P3    | guest cart verb         |
| `store_cart`             | `GuestShopController::cart`           | any               | P3    | guest cart page         |
| `store_update_cart_item` | `GuestShopController::updateCartItem` | any               | P3    | guest cart verb         |
| `store_remove_cart_item` | `GuestShopController::removeCartItem` | any               | P3    | guest cart verb         |
| `account_home`           | `MemberController::accountHome`       | account           | P4    | customer landing page   |
| `store_checkout`         | `GuestShopController::checkout`       | any (tier-2 gate) | P5    | guest checkout form     |
| `store_place_order`      | `GuestShopController::placeOrder`     | any (tier-2 gate) | P5    | atomic register + order |

---

## Appendix B — Schema & settings changes (complete)

All DDL lands in P2 as one additive migration (`migrate_guest_shop.sql`
_(new)_) mirrored into `install.sql`; `SchemaDriftTest` enforces the sync.
Nothing later adds DDL.

| Object                             | Change                                                                        | Type   | Used by             |
| ---------------------------------- | ----------------------------------------------------------------------------- | ------ | ------------------- |
| `carts.member_id`                  | `NOT NULL` → `NULL`                                                           | modify | P3/P5 token carts   |
| `carts.guest_token`                | `CHAR(64) NULL` + `UNIQUE`                                                    | add    | P3/P5               |
| `carts.guest_started_at`           | `TIMESTAMP NULL`                                                              | add    | funnel (P3/P7)      |
| `carts`                            | `KEY idx_carts_guest_idle (guest_token, status, updated_at)`                  | add    | P2 cron leg         |
| `users.registered_via`             | `VARCHAR(30) NULL` (`NULL` legacy, `register`, `guest_checkout`)              | add    | P5, admin (P4)      |
| `users`                            | `UNIQUE (binary_parent_id, binary_position)` — only if P0 finds no duplicates | add    | P5a slot-race guard |
| `rate_limits` _(new table)_        | `bucket_key UNIQUE, hits, window_started_at`                                  | add    | P3/P5 limiters      |
| `settings: guest_shop_enabled`     | `'0'`                                                                         | add    | tier-1 gate (D7′)   |
| `settings: guest_checkout_enabled` | `'0'`                                                                         | add    | tier-2 gate (D7′)   |
| `settings: customer_zone_enabled`  | `'0'`                                                                         | add    | zone split (D16)    |
| `settings: guest_cart_ttl_days`    | `'14'`                                                                        | add    | cart TTL + cron     |

Deliberately **not** added: `guest_default_sponsor` (dropped by D4′ — the
default is the primary admin by id), any guest-orders or funnel table (the
funnel reads existing `carts` columns), any commission/affiliate table (D17 is
branding-only).

---

## Appendix C — Test matrix

Every phase runs the full existing suite (`php tests/run.php`:
`ShopStateMachineTest`, `ViewRenderTest`, `QaSuiteTest`, `TemplateLintTest`,
`SchemaDriftTest`) on top of its own cases. New artefacts in **bold**.

| Phase | Case                                                                                     | Proves                           |
| ----- | ---------------------------------------------------------------------------------------- | -------------------------------- |
| P0    | baseline suite run                                                                       | reference output for later diffs |
| P1    | **render diff** member shop/cart/checkout/off-canvas                                     | refactor is invisible            |
| P1    | TemplateLint + full suite                                                                | no template/lint regressions     |
| P2    | **Cart token lifecycle** (create/get/claim/merge, isolation)                             | token ownership works            |
| P2    | **mergeInto clamping**                                                                   | stock rules apply on merge       |
| P2    | **cron leg** (idle → abandoned → purge; member carts untouched)                          | reaper is safe                   |
| P2    | SchemaDriftTest                                                                          | migration ↔ install.sql in sync  |
| P3    | guest CRUD journey incl. cookie persistence (14 d)                                       | Milestone-1 UX                   |
| P3    | token isolation                                                                          | no cross-cart leakage            |
| P3    | logged-in redirect on `store*`                                                           | D9 boundary                      |
| P3    | tier-1 gate off (both flags)                                                             | kill-switch works                |
| P3    | cart-creation IP cap (21st refused)                                                      | abuse bound                      |
| P3    | merge after register **and** login, clamp flash                                          | D9 bridge                        |
| P3    | no-JS fallback (POST + `?cart=1`)                                                        | progressive enhancement          |
| P4    | **RouteMatrixTest**: 42 routes × 4 actors × flag on/off                                  | role change is provably safe     |
| P4    | shell renders: customer vs partner                                                       | two shells, one template set     |
| P4    | activation: seat check (partner-count), placement (D19), grandfather rules (A2 fixtures) | D18/D19                          |
| P4    | pending registration no longer consumes a seat                                           | D18                              |
| P4    | e-wallet hidden + forged-POST rejected for customer                                      | D20                              |
| P4    | Super-Login landings (customer → home, partner → dashboard)                              | impersonation                    |
| P5a   | register-mode matrix (free, referral, code, e-wallet registrar) — unchanged behaviour    | extraction is pure refactor      |
| P5    | **GuestCheckoutTest happy path** (account + order + login + welcome card)                | D1/D2/D3′/D4′/D14                |
| P5    | rollback at each step → no user, no order, cart intact                                   | D2 atomicity                     |
| P5    | double-submit (same idem) → same order                                                   | idempotency                      |
| P5    | username race (two guests, one name)                                                     | graceful loser                   |
| P5    | lost-response replay → login redirect                                                    | token-keyed idem                 |
| P5    | tier-2 gate permutations + forged `ewallet` POST                                         | D6/D7′                           |
| P5    | checkout caps (4th IP / 3rd mobile refused), honeypot drop                               | abuse bound                      |
| P5    | last-stock-unit contention                                                               | stock safety                     |
| P6    | AJAX add + fallback; session cookie flags; no logout wave                                | hardening                        |
| P6    | matrix re-run + funnel queries on fixtures                                               | regression + observability       |
| P7    | acceptance checklists per flag gate                                                      | rollout discipline               |

---

## Appendix D — Risk register

Impact × likelihood, owning phase, mitigation. "Monitor" items surface in P7's
metrics review.

| ID   | Risk                                                           | I   | L   | Phase   | Mitigation                                                                                     |
| ---- | -------------------------------------------------------------- | --- | --- | ------- | ---------------------------------------------------------------------------------------------- |
| R-01 | Reuse refactor touches live member pages                       | H   | M   | P1      | render diff, full suite, mechanical-only changes                                               |
| R-02 | Checkout partial extraction changes JS behaviour               | M   | M   | P1/P6   | verbatim moves, TemplateLint, manual checkout smoke                                            |
| R-03 | 42-route role change breaks an access path                     | H   | M   | P4      | RouteMatrixTest in both flag states; flag rollback                                             |
| R-04 | `User::register()` refactor breaks a registration mode         | H   | M   | P5a     | separate commit; register-mode matrix gate before 5b                                           |
| R-05 | `carts.member_id` nullability vs FK/cascade                    | M   | L   | P2      | migration rehearsed on prod copy; FK inert on NULL                                             |
| R-06 | Existing pending members lose the dashboard                    | M   | H   | P4      | announcement is a gate condition; account home must be useful                                  |
| R-07 | Grandfathering edge cases (A1 sponsors, A2 placed, A3 wallets) | M   | M   | P4      | audit-driven rules, fixtures reproducing live rows                                             |
| R-08 | Seat-limit semantics change confuses ops                       | M   | M   | P4      | admin copy explains "partner capacity"; tests both ways                                        |
| R-09 | Junk accounts / 24 h stock holds via open checkout             | M   | M   | P3/P5   | caps 3/IP + 2/mobile; expiry cron releases holds; monitor; no auto-delete (D2)                 |
| R-10 | `REMOTE_ADDR` is a proxy IP in production                      | M   | ?   | P0      | settle before P3; ship trusted-proxy override knob                                             |
| R-11 | `rate_limits` hot-row contention                               | L   | L   | P2      | per-bucket keys, short windows; monitor                                                        |
| R-12 | Admin direct-downline share grows (D4′ default sponsor)        | L   | H   | P3/P5   | accepted by owner; monitor share in P7                                                         |
| R-13 | Guest forgets username, no self-recovery                       | M   | M   | P5      | username shown twice, mobile required, admin reset (V4); SMS backlog                           |
| R-14 | PH Data Privacy Act consent/cookie obligations                 | M   | L   | P0/P6   | plain-language notice, terms checkbox, privacy-owner review — not legal advice                 |
| R-15 | Session hardening signs users out                              | H   | L   | P6      | no cookie rename; staging proof with pre-deploy cookie                                         |
| R-16 | Member and guest checkout drift apart over time                | M   | L   | ongoing | shared partials (P1), single order path (`createFromCart`), single `RegistrationService` (P5a) |

---

## Appendix E — Flag & rollout playbook

### E.1 Flags

| Flag                     | Default | Gates                              | Introduced | Enabled (P7) | Rollback effect                                               |
| ------------------------ | ------- | ---------------------------------- | ---------- | ------------ | ------------------------------------------------------------- |
| `guest_shop_enabled`     | 0       | storefront + cart (tier 1, D7′)    | P2         | step 1       | `store*` closes; landing link dead-ends; token carts idle out |
| `customer_zone_enabled`  | 0       | customer/partner split (D16)       | P2         | step 2       | pending members regain the dashboard instantly                |
| `guest_checkout_enabled` | 0       | account-creating checkout (tier 2) | P2         | step 3       | checkout button reverts to "Join to continue"                 |

Enablement is always a settings toggle (Admin → Settings, P2 UI) — no deploy
needed for any flip, in either direction. Full retreat = three flags to `0` in
reverse order, restoring today's behaviour exactly.

### E.2 Pre-flight SQL audits (P0)

```sql
-- A1 pending sponsors
SELECT COUNT(*) FROM users s WHERE s.role='member' AND s.status='pending'
  AND EXISTS (SELECT 1 FROM users d WHERE d.sponsor_id = s.id);
-- A2 pending members already placed in the binary tree
SELECT COUNT(*) FROM users WHERE role='member' AND status='pending'
  AND binary_parent_id IS NOT NULL;
-- A3 pending members holding e-wallet funds
SELECT COUNT(*) FROM users WHERE role='member' AND status='pending'
  AND ewallet_balance > 0;
```

### E.3 Open items from spec §15 — disposition

| #   | Item                                            | Disposition                                                        |
| --- | ----------------------------------------------- | ------------------------------------------------------------------ |
| 1   | `REMOTE_ADDR` realness                          | P0 answers; default = trusted-proxy override knob in `client_ip()` |
| 2   | Duplicate `(binary_parent_id, binary_position)` | P0 checks; if clean → unique index in P2 (Appendix B)              |
| 3   | Storefront indexability                         | P0 decides; default = storefront indexable, app pages unchanged    |
| 4   | Optional email at guest checkout                | P0 decides; default = not in v1                                    |
| 5   | Seat lock on all registration paths             | P5a builds it; default = adopt everywhere                          |
| 6   | First-click vs last-click attribution           | Default last-click (D15); revisit on first dispute                 |
| 7   | Deferred-password alternative                   | Parked until SMS verification exists (P6 backlog)                  |
| 8   | Session cookie flags without renaming           | P6, with staging proof (R-15)                                      |

---

## Document map

| Document                    | Lives                           | Written/updated                                                                       | End state                                                 |
| --------------------------- | ------------------------------- | ------------------------------------------------------------------------------------- | --------------------------------------------------------- |
| This plan                   | `phased_implementation_plan.md` | now                                                                                   | the build contract (D-register + phases + appendices)     |
| `guest_buyer_workflow.md`   | `tmp/shop/`                     | v4 exists; P0 folds in D16–D22; each phase updates its sections; P7 reconciles        | derived from executable source                            |
| `customer_zone_workflow.md` | `tmp/shop/`                     | skeleton in P0; finalized in P4; reconciled in P7                                     | derived from executable source                            |
| `buyer_workflow.md`         | `tmp/shop/`                     | §1/§3 updated in P7 (storefront + guest entry, zone split); §8–§11 verified unchanged | still the single source for the order journey             |
| `AGENTS.md` / `README.md`   | root                            | P7                                                                                    | flags documented, tests claim corrected, cron leg 3 noted |
