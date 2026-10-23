# Shop — Guest Buyer Workflow: Frontend Checkout to Completion

> **Status: PROPOSED SPEC — reconciled against the source on 2026-10-18.**
> Unlike `buyer_workflow.md` (derived from executable source), the feature
> described here does not exist in code yet. Everything marked _(existing)_ or
> cited as `file:line` was checked against the repository snapshot
> (`altaslive`); routes, columns, settings, tables, helpers and classes
> marked _(new)_ are placeholder names. §15 records what the source review
> confirmed and what it changed in this spec; §16 lists the files the build
> touches and the tests to add. When the feature ships, re-check the citations
> and drop this banner.

A guest's-eye walkthrough of buying from the public frontend **without
registering first**: the visitor browses the storefront, fills in one checkout
form, places the order and — in that same step — receives a free account and is
signed in, landing on the normal member order page. From there the journey is
the member journey of `buyer_workflow.md`, through to `completed`.

This document covers only what is _different_ for a guest: entry, sponsor
capture, cart, checkout, the atomic register-and-order step and the handoff. It
does **not** repeat the 14-status order journey; it points into
`buyer_workflow.md` instead (D10).

Conventions: **BW §N** = section N of `buyer_workflow.md`; plain **§N** = a
section of this document; **D#** = a design decision (§1); _(new)_ = to be
built; _(existing)_ = reused unchanged.

Strategy C reminder: guest orders earn **zero** compensation, like every shop
order — no PV, no pairing/unilevel bonus, no CD split, no DFI;
`Commission`/`CapEngine` are never involved. A free (pending) registration
writes no commissions and increments no leg counts — everything defers to
activation (BW §1; `User::register()` runs `processBinaryPlacement()` with
`incrementCounts = false` and skips the referral bonuses when the status is
`pending`). `core/Commission.php` is **not modified** by this feature (§16).

---

## 1. What "guest" means here

A **guest** is a visitor with **no account yet** — not an anonymous buyer (D1).
Every guest checkout creates an account. There are no account-less orders:
proof upload, cancel, confirm-receipt, invoices and refunds are owner-scoped
member features behind `Auth::guard('member')` (BW §8).

|                         | Member journey (`buyer_workflow.md`)                   | Guest journey (this doc)                                      |
| ----------------------- | ------------------------------------------------------ | ------------------------------------------------------------- |
| Account before shopping | Required — free registration (BW §1)                   | **Not required** — created at order placement                 |
| Where they shop         | `?page=shop` (member layout, behind `guard('member')`) | `?page=store` _(new)_ (public layout)                         |
| Cart owner              | member id                                              | random cookie token → claimed by the new member               |
| Extra checkout fields   | —                                                      | username, password, sponsor; `billing_phone` becomes required |
| Payment methods         | five, incl. `ewallet`                                  | four manual methods only                                      |
| Order creation          | `createFromCart(memberId, …)`                          | the same call, inside the register-and-order transaction      |
| After placement         | order page `?page=shop_order&id=`                      | the same page, already signed in                              |

**Why one transaction.** The schema makes an owner mandatory before an order or
a cart can exist: `shop_orders.member_id` is `NOT NULL … ON DELETE RESTRICT`,
`carts.member_id` is `NOT NULL`, and `ShopOrder::createFromCart()` refuses to run
unless `Cart::getActive($memberId)` is the cart it was handed
(`models/ShopOrder.php:687`). "Place the order, _then_ register" therefore cannot
be done literally. Registration moves _inside_ the order's transaction:
register user → claim cart → create order → commit. Any failure rolls back all
of it. To the guest the rule still reads "order placed ⇒ account exists", and a
failed checkout never leaves an orphan user or an orphan order.

**"Successful" means placed, not paid** (D2). A brand-new account has a zero
wallet balance, so the guest pays by manual proof (BW §1, §7b), and proof upload
lives on the member order page — the guest has to be signed in the moment the
order exists. The account is created with the order (status `pending`) and
**outlives** it: if the order later expires or is cancelled, only the order dies
(`cron/shop_expiry.php` only ever acts on shop orders and their lines and events, never on users).

**Binary placement is not guaranteed.** Exactly as for a free registration made
from a referral link, a guest account is placed in the binary tree only when the
referral rule of D5 applies; otherwise it is created **unplaced** and chooses its
placement at activation. Either way it consumes one seat (§3).

**Unchanged:** the 14-status order state machine, events, invoices, refunds, the
expiry cron, admin screens, the member journey and `?page=register` — guest
checkout is an _additional_ way in, not a replacement.

### Design decisions in force

D4, D5 and D8 were refined during the source review (§15): the register form
turned out to behave differently from how they were first worded. Their intent —
"behave like free registration" — is unchanged.

| ID  | Decision                                                                                                                                                                                                                                                  | Detail     |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- |
| D1  | Guest = visitor without an account; every guest checkout creates one                                                                                                                                                                                      | §1         |
| D2  | Account created at order placement (`pending` order), atomically with it; survives an expired or cancelled order                                                                                                                                          | §1, §7, §8 |
| D3  | Credentials: username (suggested, editable) + password + confirm, in a "Create your account" block                                                                                                                                                        | §6         |
| D4  | Sponsor: from a referral link → cookie → prefilled and **read-only**; otherwise prefilled with the house default (`admin`) and editable. `guest_default_sponsor` _(new, default `admin`)_ makes the default configurable                                  | §2, §6     |
| D5  | Placement = the free-registration referral rule: under the sponsor's next free slot **only** when the sponsor came from a referral link, is a valid binary upline and has a free slot; otherwise **unplaced** until activation. No upline/position fields | §7         |
| D6  | Manual payment methods only; `ewallet` hidden and rejected server-side                                                                                                                                                                                    | §6         |
| D7  | Availability gate: `shop_enabled` + `free_registration_enabled` + seat available + new `guest_checkout_enabled` (default off); checked at render and again inside the transaction                                                                         | §3, §7     |
| D8  | Deviations from member checkout: `billing_phone` required and validated; address always saved (after commit, best-effort); account-creation notice                                                                                                        | §6, §7     |
| D9  | Returning members: "Log in & continue" merges the guest cart; signed-in users never see guest pages                                                                                                                                                       | §9         |
| D10 | Scope: guest front half in full; order page → `completed` by reference to `buyer_workflow.md`                                                                                                                                                             | §8         |

---

## 2. Entry and sponsor capture

Entry points:

- **Landing page** (`frontend/index.php`). `.htaccess` serves it at the root URL
  (any request without a `page=` parameter is rewritten to it) and it is
  deliberately standalone: it loads `config/db.php`, `core/helpers.php` and
  `models/Package.php`, but not `Auth`, and never starts a session. A **Shop**
  entry _(new)_ is therefore just a link — `<?= $base ?>/?page=store`, beside the
  existing `?page=login` and `?page=register` links
  (`frontend/index.php:1773-1774`) — rendered only while `guest_checkout_open()`
  _(new helper in `core/helpers.php`, §3)_ is true. The existing "Partner / Join"
  buttons and `?page=register` are untouched.
- **Shopper referral links** _(new)_: `?page=store&sponsor=<username>`. Members
  copy their existing register link from `views/member/genealogy.php:267`
  (`?page=register&sponsor=<username>&ref=1`); a **Shop link** _(new)_ is added
  beside it. Optionally `showRegister` also records the sponsor cookie when it
  sees `sponsor=`, so a referred visitor who wanders into the store keeps their
  sponsor.

Sponsor capture (D4):

| Aspect           | Detail                                                                                                                                                                                                                                                                                                                                                                                              |
| ---------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Capture          | Any `store*` request carrying `sponsor=<username>` stores the value in cookie `store_sponsor` _(new)_ — only if it passes `is_valid_username()`; anything else is ignored. httponly, SameSite=Lax, `secure` when `APP_ENV === 'production'`, path = the path component of `APP_URL`, 30 days _(proposed)_. The cookie's existence is what marks the sponsor as **"came from a referral link"** (D5) |
| Trust            | The cookie is **untrusted input — only a prefill**. It is re-validated (`User::findByUsername`) at checkout render and again inside the place-order transaction. A name that no longer exists is dropped and the guest is treated as having no referral                                                                                                                                             |
| Newest link wins | A later referral link overwrites the cookie                                                                                                                                                                                                                                                                                                                                                         |
| Referral sponsor | Prefilled and **read-only**, labelled "Referred by @name". This is what the register form does: `$lockSponsor` makes the field `readonly` in referral mode (`views/auth/register.php:24`, `:309-313`)                                                                                                                                                                                               |
| No referral      | Prefilled with `guest_default_sponsor` (default `admin`) and **editable** — the register form's guest default (`views/auth/register.php:29-32`: "Guests default to admin sponsor"). Setting it empty makes the field blank and required                                                                                                                                                             |
| Sponsor rule     | The sponsor must exist (`User::findByUsername`, error "Sponsor username not found."). That is the **only** sponsor rule in free registration today — no status or package check (`AuthController.php:348`)                                                                                                                                                                                          |

---

## 3. Availability gate (D7)

Order of checks on **every** `store*` request:

1. **Signed in?** → redirect to the member equivalent, merging any leftover guest
   cart first (§9). Guest pages are never shown to a signed-in user.
2. **Gate** — below.
3. The route's own logic.

`maintenance_mode = 1` needs nothing here: `index.php:52` already serves the 503
page to non-admins for every `?page=` route, `store*` included.

Guest checkout is `open` only when **all** hold:

| Condition            | Source                                                   | When closed                                                                                                                                                                        |
| -------------------- | -------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Shop on              | `shop_enabled = 1` (BW §3)                               | "The shop is currently closed." rendered in place — the member-side `guardShop()` flashes and bounces to the dashboard (`MemberController.php:1209`), but guests have no dashboard |
| Guest checkout on    | `guest_checkout_enabled = 1` _(new, default `0`)_        | `store*` routes redirect to the landing page; the landing **Shop** entry is hidden                                                                                                 |
| Free registration on | `free_registration_enabled = 1` (BW §1)                  | storefront stays browsable; add-to-cart and checkout are replaced by "Registration is closed right now — log in to shop" with a login link                                         |
| Seat available       | `isSeatLimitReached()` is false (`core/helpers.php:165`) | same message as above                                                                                                                                                              |

A guest checkout **is** a free registration under the hood, so it obeys every
gate the register form obeys. Two source facts shape the seat check:

- `seatsRemaining()` counts `users WHERE role = 'member'` against
  `settings.seat_limit`, read straight from the DB (`core/helpers.php:155-167`).
  **Every guest account consumes a seat**, placed or not.
- `index.php:254-257` blocks only `register`, `do_register` and `validate_code`
  when seats run out (a 403 page). `store*` routes are not in that list, so they
  run their own check.

`guest_checkout_open(): array` _(new, `core/helpers.php`)_ wraps the four
conditions and returns the verdict plus a reason code (`open`, `shop_closed`,
`disabled`, `registration_closed`, `seats_full`). It uses only `setting()` and
`isSeatLimitReached()`, so the standalone landing page can call it too. The gate
is evaluated three times:

- **Storefront / landing render** — decides what to show.
- **Checkout render** — blocks early with a message instead of letting the guest
  fill in the form.
- **Place order** — authoritative; re-checked **inside the transaction** (§7),
  because the last seat can be taken between render and submit and settings can
  flip mid-session. Forged POSTs while closed are refused: no user, no order.

Closing the gate never destroys guest carts; they wait until their TTL (§5).

---

## 4. Guest storefront — `?page=store`

- **Public layout** under `views/store/` _(new)_, not the member layout. The
  member partials assume a signed-in member: `topbar.php` reads `Auth::user()`
  and the wallet (`views/partials/topbar.php:20-31`), and `cart_offcanvas.php`
  returns immediately when nobody is signed in (`:8`). `head.php` is generic but
  hard-codes `noindex,nofollow`; whether the public storefront should be
  indexable is a product call, and the default stays `noindex` until decided.
- Shows `Product::active()` cards — the same catalogue and prices as
  `?page=shop` (BW §3) — with the cart badge fed by the guest cart (§5).
- The cart off-canvas gets a **guest variant** (or the existing partial is
  parameterised with a route map): it posts to `update_cart_item`,
  `remove_cart_item`, `checkout` and `cart` today
  (`views/partials/cart_offcanvas.php:189-258`), which are member routes.
- Add-to-cart uses the member pattern: a plain `<form method="post">` with
  `csrf_field()` (`views/member/shop.php:74-75`), upgraded to AJAX with
  `X-Requested-With: XMLHttpRequest` and `csrf_token` in the body
  (`cart_offcanvas.php:137-140`). Guests have a session and a CSRF token
  because `index.php:425` seeds `csrf_token()` on every request.
- Add-to-cart and checkout controls follow the gate (§3): open → normal;
  registration or seats closed → replaced by the closed message.
- No sponsor, account or login is needed to browse.

---

## 5. Guest cart

| Aspect  | Detail                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Owner   | A **random token, not a member id**: 32 random bytes → 64 hex chars in cookie `guest_cart` _(new)_ — httponly, SameSite=Lax, `secure` in production, path = path of `APP_URL`, lifetime `guest_cart_ttl_days` (default 7). Stored as `carts.guest_token`; `carts.member_id` stays `NULL` while it is a guest cart; exactly one of the two is set (§10)                                                                                                              |
| Created | Lazily, on the first `store_add_to_cart` — browsing creates nothing                                                                                                                                                                                                                                                                                                                                                                                                 |
| Logic   | Only three `Cart` methods are member-keyed: `getActive`, `getOrCreate`, `itemCountForMember`. Everything else already takes a `$cartId` — `addItem`, `getItems`, `getTotals`, `updateItemQuantity`, `removeItemById`, `validateStock`, `refreshPrices`, `markConverted` (`models/Cart.php`). The guest path adds `getActiveByToken`, `getOrCreateForToken`, `claimFor` and `mergeInto` _(new)_ and reuses the rest unchanged, so stock and price rules cannot drift |
| Stock   | The cart holds none (BW §4). Reservation happens only when the order is placed (§7)                                                                                                                                                                                                                                                                                                                                                                                 |

Operations mirror BW §4–§5 and `MemberController`'s cart verbs:

- `store_add_to_cart` — `product_id` + `quantity` (min 1), `csrf_verify()`; AJAX
  is detected by `X-Requested-With: XMLHttpRequest` and answers `{ok, count}`, or
  `{ok:false, error}` with HTTP 400; a normal POST flashes and redirects to
  `?page=store&cart=1`. An existing line for the same product is incremented
  (still stock-checked); over-stock makes `Cart::addItem()` throw
  `InvalidArgumentException` ("Insufficient stock. Requested N, only M
  available.") and nothing is written.
- `store_cart` — runs `Cart::validateStock()` and shows stale-price /
  out-of-stock warnings. +/- buttons clamp to the server-rendered `max`;
  `store_update_cart_item` / `store_remove_cart_item` answer JSON for XHR
  (`{ok, subtotal, count}` or `{ok:false,error}` with HTTP 400); the checkout
  button is disabled at 0 items; summary and badges refresh in the browser.
- **Ownership.** The guest equivalent of `requireOwnCart()`
  (`MemberController.php:1329`, defect C3): the cart is **always resolved from
  the cookie token**; a posted `item_id` is paired with that cart's id; the
  client never supplies a cart id. The token is never accepted from a URL or
  body and never logged.
- **Lifetime.** `carts.updated_at` only moves when the `carts` row itself is
  written, not when `cart_items` change — so every guest mutation also runs
  `UPDATE carts SET updated_at = NOW() WHERE id = ?`, making the TTL mean "idle
  time". Idle carts are deleted by a new leg of `cron/shop_expiry.php` (§10).
  Deleting has no inventory effect — carts hold no stock.
- **Creation cap.** Each new guest cart is a DB row any anonymous client can
  mint; creation is rate limited per IP (§11).
- **Cookies disabled** → no token can persist; add-to-cart answers "Please
  enable cookies to shop."

---

## 6. Guest checkout — `?page=store_checkout`

Server prerequisites (as `MemberController::checkout()`, `:1388`): gate `open`;
a non-empty active guest cart (else "Your cart is empty." → `?page=store`);
`Cart::refreshPrices()` (catalog price is authoritative) and
`Cart::validateStock()` — a stock problem bounces to `?page=store_cart` with the
first error.

Page, top to bottom:

1. **"Already have an account? Log in & continue"** strip → §9.
2. Order summary (items, total).
3. **Delivery details** — the same fields and markup as the member checkout
   (`views/member/checkout.php`) minus the saved-address picker, extracted into a
   shared partial `views/partials/checkout_delivery.php` _(new)_ so the two cannot
   diverge. The region/province/city/barangay picker is already a standalone
   partial with no `Auth` dependency (`views/partials/address_picker.php`; it
   calls the public PSGC API from the browser) and is reused as is.
4. **Create your account** _(new)_ — username, password, confirm password,
   sponsor.
5. **Payment method** — four manual methods, none pre-selected, `required`.
6. Terms checkbox and the account-creation notice. The single-submit guard and
   terms gate that today live inline in `checkout.php` move into a shared partial
   `views/partials/checkout_submit_js.php` _(new)_.
7. Hidden `idem` token (`uniqid('', true)`, as the member form) and a hidden
   honeypot field.

Fields posted to `?page=store_place_order` (names are the member form's, plus the
account fields):

| Field                                                                                                                          | Required        | Rule / note                                                                                                                                                                                                       |
| ------------------------------------------------------------------------------------------------------------------------------ | --------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `payment_method`                                                                                                               | yes             | one of `gcash`, `maya`, `usdt_trc20`, `usdt_bep20`. `ewallet` is not rendered and is **rejected by the guest handler** — `ShopOrder::PAYMENT_METHODS` still lists it, so the model will not refuse it for us (D6) |
| `billing_name`                                                                                                                 | yes             | reported as "billing name"; also becomes `users.full_name` — the admin member search matches username, full name and email only, so this is what lets support find the account (§12)                              |
| `billing_phone`                                                                                                                | **yes** (D8)    | optional for members, required for guests; must pass `is_valid_mobile()` (`09XXXXXXXXX` or `+639XXXXXXXXX`, `core/helpers.php:362`); copied to `users.mobile` (VARCHAR(20))                                       |
| `shipping_name`                                                                                                                | yes             | reported as "recipient name"; falls back to `billing_name` when absent, as `placeOrder` does                                                                                                                      |
| `shipping_phone`                                                                                                               | no              | as member checkout                                                                                                                                                                                                |
| `shipping_address`                                                                                                             | yes             | reported as "delivery address"                                                                                                                                                                                    |
| `shipping_region`, `shipping_province`, `shipping_city`, `shipping_barangay`, `shipping_postal` and the four `shipping_*_code` | no              | as member checkout; filled by the PSGC picker                                                                                                                                                                     |
| `notes_member`                                                                                                                 | no              |                                                                                                                                                                                                                   |
| `terms`                                                                                                                        | yes (`=== '1'`) | covers terms `v1` **and** account creation                                                                                                                                                                        |
| `username`                                                                                                                     | yes             | `strtolower(trim())`, then `is_valid_username()` (3–40 chars, letters/numbers/underscore, starts with a letter — `core/helpers.php`) and not already taken (`User::usernameExists`)                               |
| `password`, `password_confirm`                                                                                                 | yes             | at least 8 characters and identical; bcrypt cost 12                                                                                                                                                               |
| `sponsor_username`                                                                                                             | yes             | the register form's field name; prefilled per §2; must exist                                                                                                                                                      |
| `idem`                                                                                                                         | yes             | idempotency token                                                                                                                                                                                                 |
| `website` (honeypot)                                                                                                           | must be empty   | any value is treated as a failed attempt with a generic error                                                                                                                                                     |
| `save_address`, `address_label`                                                                                                | not posted      | the address is always saved (D8)                                                                                                                                                                                  |
| upline / position                                                                                                              | not posted      | resolved server-side (D5)                                                                                                                                                                                         |

Behaviour:

- **Why username + password (D3).** Login is username + password
  (`AuthController::doLogin`, `:16`), and the codebase has no mailer, SMS, OTP or
  self-service password reset anywhere — `users.email` is an optional,
  unverified profile field. Choosing credentials at checkout is therefore the
  minimum that needs no credential delivery. The guest is signed in straight
  after the order, exactly as after free registration (`AuthController.php:455`).
- **Username suggestion** (client-side convenience): once `billing_name` is typed
  and `username` is untouched, suggest a lowercase/underscore form of the name
  (prefixed with a letter if needed, clamped to 3–40), then call the existing
  availability endpoint — `?page=check_username&username=…`, role `any`,
  answering `{available, message}` (`AuthController::ajaxCheckUser`, `:513`).
  The server validates only what is posted.
- **Remembered input.** `shop_checkout_remember_input()` /
  `shop_checkout_old_input()` (`core/helpers.php:587-640`) are **whitelist-based**
  — address fields, notes and `payment_method` only — so a password can never be
  stashed by them. They lack `username` and `sponsor_username`, so guest variants
  `guest_checkout_remember_input()` / `guest_checkout_old_input()` _(new)_ extend
  the whitelist and still never include `password` or `password_confirm`. A guest
  has no address book, so there is no saved-address prefill.
- **Validation failures** write nothing, keep everything typed (except
  passwords) and name exactly what is wrong. Shop fields use the member wording —
  "Please fill in the billing name, recipient name, delivery address." — with
  "mobile number" added for a missing or invalid `billing_phone`. Account fields
  reuse the registration messages verbatim ("Username must be 3–40 characters,
  letters/numbers/underscore, start with a letter.", "Username is already
  taken.", "Password must be at least 8 characters.", "Passwords do not match.",
  "Sponsor username not found."), produced by one shared validator
  `User::validateNewMember()` _(new)_ extracted from the common-validation block
  of `AuthController::doRegister` so the two paths cannot drift.
- **Account-creation notice** _(proposed copy)_, directly above the submit
  button: "Placing this order creates your free account **@username**. You'll use
  it to upload your payment proof, track your order and download invoices." The
  terms label covers both the shop terms and account creation; have the wording
  reviewed against your privacy notice (Data Privacy Act of 2012).
- The submit button disables after the first click and the terms gate keeps it
  disabled until the box is ticked (the shared JS partial); the server re-checks
  `terms` regardless.

---

## 7. Placing the order — `?page=store_place_order`

Mirrors `MemberController::placeOrder()` (`:1423`) up to `createFromCart`. What is
new is that **registration and the cart claim run inside the same transaction**
as the order.

### 7.1 Pre-transaction checks (no locks, nothing written)

1. `csrf_verify()` — on a mismatch it `die`s with a plain 403 page
   (`core/helpers.php`), existing behaviour; honeypot empty; per-IP rate limits
   (§11).
2. **Signed in?** → `redirect('/?page=shop_orders')`; no second order (§9).
3. **Replay lookup** — compute the guest idempotency key
   `sha1('guest|' . cart_token . '|' . idem)` from the cookie token and the posted
   `idem`. This is the guest analogue of the member key
   `sha1(Auth::id() . '|' . cart_id . '|' . idem)`: no uid exists yet, and the cart
   id cannot be used because the claim in 7.2-4 nulls the token. If an order
   already carries this key (`shop_orders.idempotency_key` is `UNIQUE`) → replay
   response (§7.3); nothing is created.
4. Resolve the guest cart from the token; missing or empty → `?page=store_cart`.
5. Gate `open` (§3).
6. Validate input — shop fields as BW §6 plus the guest additions in §6, account
   fields through `User::validateNewMember()`; `payment_method` must be one of
   the four manual methods. Failure → `guest_checkout_remember_input($_POST)` →
   flash → back to `store_checkout`.
7. **Hash the password now** (`password_hash`, bcrypt cost 12 — a few hundred
   milliseconds) and pass the hash into registration, so the seat lock below is
   never held while hashing.

### 7.2 The transaction

0. `$pdo->beginTransaction()`.
1. **Seat lock and gate re-check.** `SELECT value FROM settings WHERE key_name =
'seat_limit' FOR UPDATE` (the row is seeded by `install.sql`) serialises
   concurrent guest checkouts at the seat boundary. Then re-run
   `guest_checkout_open()` — its `isSeatLimitReached()` read is the transaction's
   first consistent read, so it sees every committed registration. Closed →
   rollback, "Registration is closed right now". _Note:_ `doRegister` and
   `User::register()` check `isSeatLimitReached()` with no lock today, so a guest
   checkout racing a normal registration for the very last seat is not
   serialised; having the shared registration code take the same lock removes
   that.
2. **Cart refresh.** `Cart::refreshPrices($cartId)` then
   `Cart::validateStock($cartId)`. A problem rolls back and returns to
   `store_cart` with the first error (BW §6).
3. **Register**, through the shared registration code. `User::register()`
   (`models/User.php:11`) calls `$pdo->beginTransaction()` itself, and PDO cannot
   nest transactions, so it is changed to **adopt an open transaction** — the
   `$ownsTxn = !$pdo->inTransaction()` pattern that `ShopOrder::createFromCart()`
   and `transition()` already use — and to accept a precomputed `password_hash`
   and `registered_via`. The work it currently does _after_ its commit
   (`Commission::processBinaryPlacement()`, which for a pending member increments
   nothing, and the referral bonuses, skipped when pending) moves into
   `User::afterRegister()` _(new)_, which the guest handler runs after the outer
   commit.
   - **Sponsor and username:** validated by `User::validateNewMember()`;
     `users.username` is `UNIQUE`, so that constraint is the final authority — a
     SQLSTATE 23000 on insert is reported as "Username is already taken."
   - **Placement (D5):** if the sponsor **came from the referral cookie** and
     `User::isValidBinaryUpline($sponsorId)` (active, and either the primary admin
     or on a pairing package), compute the sponsor-anchored slot with
     `User::findNextBinarySlot($sponsorId)` — the breadth-first search that is a
     _private_ method of `AuthController` today (`:207`) and moves to `User` so
     both paths share it. Lock the chosen upline (`SELECT id FROM users WHERE id =
? FOR UPDATE`), re-check `User::isSlotFree()`, and recompute a few times if
     another registration won the slot. No referral, a sponsor who is not a valid
     binary upline, or a full tree → **unplaced**: `binary_parent_id` and
     `binary_position` stay `NULL`, as `doRegister` leaves a guest who has no
     `ref_link`/upline (`AuthController.php:387`). `users` has no unique index on
     `(binary_parent_id, binary_position)` and `register()`'s slot check is an
     unlocked `COUNT(*)`, which is why this step locks and re-checks (§10 suggests
     the index).
   - **Row written exactly as free registration (BW §1):** `status='pending'`,
     `reg_payment_method='pending'`, `package_id=NULL`, `reg_code_id=NULL`,
     `reg_paid_by=NULL`, `sponsor_id`, plus `registered_via='guest_checkout'`;
     profile columns `full_name` ← `billing_name` and `mobile` ← `billing_phone`.
   - **No commissions, no leg counts** — everything defers to activation.
4. **Claim the cart** — `UPDATE carts SET member_id = :uid, guest_token = NULL,
updated_at = NOW() WHERE id = :cid AND guest_token = :token AND member_id IS
NULL AND status = 'active'`. It must match exactly one row (the PDO connection
   uses `CLIENT_FOUND_ROWS`, so `rowCount()` counts matched rows), otherwise
   abort.
5. **Create the order** — `ShopOrder::createFromCart($uid, $cartId, $addr,
$method, $key)` **unchanged** (`models/ShopOrder.php:687`), with `$key` the
   guest key from 7.1-3. It adopts the open transaction; `Cart::getActive($uid)`
   now returns the claimed cart, so its ownership check passes; it re-prices,
   re-validates stock, inserts the order (`status='pending'`, `payment_deadline =
NOW() + shop_payment_deadline_hours`, default 24 h, terms `v1`, `order_no` =
   `AL` + yymmdd + zero-padded id), inserts every line `reserved` (**stock is
   now reserved**), marks the cart `converted` and logs the event `null →
pending` (reason `order_placed`, note "Order placed via <method>", actor
   `customer`).
6. **Commit.**

Any exception rolls back **all** of it: no user, no order, the cart is still a
guest cart, stock untouched. Errors follow `placeOrder`: an
`InvalidArgumentException` or `RuntimeException` shows its (buyer-safe) message,
anything else shows "Could not place the order. Please try again."; either way the
typed input comes back (no passwords) on `store_checkout`.

### 7.3 Replay and double-submit

- A second request from a browser that is already signed in is caught by 7.1-2:
  redirected, nothing created. The submit button also disables on first click.
- A **duplicate-key outcome** inside the transaction — the username, the cart
  claim matching 0 rows, or the idempotency key — rolls back and re-runs the
  replay lookup. Found → replay response; not found → the genuine error
  (username taken, or cart no longer available). `createFromCart` has its own
  fast path and 23000 handler, but they only protect the order insert; the guest
  handler's lookup runs first so a replay never registers a second account.
- **Replay response**: nothing is created; the guest is sent to `?page=login`
  with "Your order was already placed — sign in as @username to continue." This
  also covers a response lost after commit: the cookie token is still in the
  browser, the cart is already claimed, and the key still finds the order.

### 7.4 After commit

- `User::afterRegister()` (post-commit hooks), then `Auth::login(User::find($newId))`
  — `Auth::login()` takes the **user row**, not an id (`core/Auth.php:112`); it
  regenerates the session id, sets `user_id/user_role/username` and stamps
  `last_login`, exactly as the free-registration auto-login does
  (`AuthController.php:455`).
- **Save the address**, best effort: the member-side `maybeSaveCheckoutAddress()`
  (`MemberController.php:1557`) is private, runs _after_ commit, swallows
  failures and is keyed to `$_POST['save_address']`. It is extracted into a shared
  helper; guests always save, and `MemberAddress::create()` makes a member's
  first address the default.
- The `guest_cart` and `store_sponsor` cookies are cleared.
- Flash _(proposed copy)_: "Order placed. Upload your payment proof to continue.
  Your free account @username was created — sign in with it next time."
- Redirect to `?page=shop_order&id=<id>` — the order page (BW §8), status
  `pending`, proof form showing. `redirect()` calls `session_write_close()`, so
  login and flash are done before it. **The guest journey ends here; the member
  journey takes over (§8).**
- Should the login somehow fail after commit, redirect to `?page=login` with a
  flash naming the username; account and order are intact.

---

## 8. Handoff — from guest to ordinary member buyer

After §7 the guest _is_ an ordinary `pending` member holding a `pending` order;
account status `pending` is sufficient for the whole journey (BW §1). Everything
from here is `buyer_workflow.md`:

| Stage                                                        | Where                 | Guest-specific note                                                                                    |
| ------------------------------------------------------------ | --------------------- | ------------------------------------------------------------------------------------------------------ |
| Order page and actions (`proof` / `cancel` / `confirm`)      | BW §8                 | unchanged — `canMemberAct()` and the own-order checks apply to the new member                          |
| `pending`                                                    | BW §9.1               | 24 h payment window; the proof form is already on screen after the redirect                            |
| Proof → `payment_review` → `paid` / `payment_failed`         | BW §9.2–§9.5          | unchanged. The first order can never be e-wallet, so there is no auto-verify path                      |
| `packing` → `ready_to_ship` → `shipped` → `out_for_delivery` | BW §9.6–§9.8          | unchanged; stock is deducted once, at `shipped` (BW §9.7)                                              |
| `delivery_failed` / `returned_to_sender` / `on_hold`         | BW §9.9, §9.10, §9.14 | unchanged — reachability matters, which is why `billing_phone` is mandatory (D8)                       |
| `delivered` → `completed`                                    | BW §9.11–§9.12        | unchanged: buyer confirms, or the cron auto-completes after `shop_report_window_days`                  |
| `cancelled`                                                  | BW §9.13              | unchanged — and **the account survives** (D2)                                                          |
| Refunds                                                      | BW §10                | the first order is always manual-pay, so any refund settles out of band, never to an e-wallet          |
| Timers                                                       | BW §11                | unchanged — `shop_expiry.php` may cancel an unpaid order at its deadline but never touches the account |

Afterwards the buyer is a normal member: later orders go through `?page=shop`
(member cart; e-wallet only once funded) with the address saved at checkout
prefilled, and the dashboard shows the standard pending-activation prompt
(BW §1). Activation stays optional and never on the buying path; an **unplaced**
account (D5) picks its binary connection at activation, as any unplaced free
signup does.

---

## 9. Returning members and cart continuity (D9)

| Situation                                        | Behaviour                                                                                                                                                                                                                                                                                                                                                                                |
| ------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Guest with a cart clicks **"Log in & continue"** | `?page=login&next=checkout` — `views/auth/login.php` carries `next` as a hidden field, limited to a whitelist (`checkout`, `cart`). `doLogin` (`AuthController.php:16`) today redirects straight to the admin or member dashboard after `Auth::login($user)`; it is extended to merge the guest cart first and then honour `next` for members (admins still land on the admin dashboard) |
| Guest registers the normal way (`do_register`)   | After the existing auto-login (`AuthController.php:455`) the guest cart is merged into the new member's cart; destination unchanged (`?page=dashboard`); the flash adds "Your cart items were kept."                                                                                                                                                                                     |
| Signed-in user opens any `store*` URL            | Redirect to the member equivalent — `store`→`shop`, `store_cart`→`cart`, `store_checkout`→`checkout` — after merging any leftover guest cart. Admins and superadmins included: they pass `guard('member')` (BW §2). A POST to `store_place_order` redirects to `?page=shop_orders` instead                                                                                               |
| Suspended account                                | Login is refused as in BW §2; nothing merges                                                                                                                                                                                                                                                                                                                                             |

Merge rules (`Cart::mergeInto($userId)` _(new)_):

- Each guest line is added to the member's line for the same product (or creates
  it), **clamped to available stock** by the BW §4 rules (`Product::availableStock`);
  a line that cannot be added at all is dropped with a notice naming the product.
- The guest cart row is deleted (its items cascade) and the `guest_cart` cookie
  cleared.
- Destination is `?page=checkout` for a clean merge. If the member already had
  items, or any line was clamped or dropped, it is `?page=cart` with a flash
  explaining the merge, so quantities are reviewed before paying.
- Idempotent: a second call finds no guest cart and does nothing.

---

## 10. Settings and data changes

Settings are saved per admin tab through allow-lists in
`AdminController::saveSettings` (`:520-623`): every key must be listed under its
group, checkbox keys are listed in `$checkboxKeys` and clamped integers in
`$hourKeys` (`:596-597`). The three new keys join the `basics` group beside
`shop_enabled`, with fields in `views/admin/settings.php` next to the shop block
(`:68-93`) and seed rows in the settings block of `install.sql` (`:745-751`) and
in `migrate_guest_checkout.sql` _(new)_, the pattern `migrate_shop.sql` follows.

| Key                              | Default | Type in `saveSettings`             | Meaning                                                                                   |
| -------------------------------- | ------- | ---------------------------------- | ----------------------------------------------------------------------------------------- |
| `guest_checkout_enabled` _(new)_ | `0`     | checkbox                           | master switch for the whole guest path; ships **off**, turned on after QA                 |
| `guest_default_sponsor` _(new)_  | `admin` | text, must be an existing username | sponsor prefilled when there is no referral (D4); empty = the field is blank and required |
| `guest_cart_ttl_days` _(new)_    | `7`     | integer ≥ 1 (`$hourKeys`)          | guest cart and cookie lifetime, and the idle-cleanup threshold                            |

Existing settings that interact: `shop_enabled` and `free_registration_enabled`
(the gate, §3), `seat_limit`, `shop_payment_deadline_hours` (24) and the rest of
BW §11.

Data:

| Where                                                                     | Change                                                                                                                                                                                                                                                                                                                                                 |
| ------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `carts`                                                                   | make `member_id` nullable; add `guest_token CHAR(64) NULL` with a unique index. The FK to `users` (`ON DELETE CASCADE`) is unaffected by `NULL` rows. Invariant, enforced in code: exactly one of `member_id` / `guest_token` is set. There is no DB-level "one active cart per member" index today (only `idx_carts_member_status`), so none is added |
| `users`                                                                   | add `registered_via VARCHAR(20) NOT NULL DEFAULT 'register'`; guest checkout writes `'guest_checkout'`; existing rows take the default; `User::register()`'s `INSERT` gains the column                                                                                                                                                                 |
| `rate_limits` _(new table)_                                               | `key_name VARCHAR(120) PRIMARY KEY`, `hits INT UNSIGNED`, `window_start INT UNSIGNED` — the IP-keyed counters of §11, one atomic upsert per attempt                                                                                                                                                                                                    |
| `users` (recommended hardening)                                           | `UNIQUE KEY (binary_parent_id, binary_position)`. MySQL allows many `NULL` pairs, so unplaced members are unaffected; check production for duplicate pairs first. It would make the DB, not application locking, the authority on slots                                                                                                                |
| `shop_orders`, `shop_order_items`, `shop_order_events`, invoices, refunds | **no change** — `member_id` stays `NOT NULL`, `idempotency_key` stays `UNIQUE`                                                                                                                                                                                                                                                                         |
| `cron/shop_expiry.php`                                                    | new **leg 3**, between auto-complete and the overdue-hold alert: delete idle guest carts (`member_id IS NULL`, `updated_at` older than `guest_cart_ttl_days`; items cascade) and expired `rate_limits` rows, logged in the script's existing style                                                                                                     |
| `reset.php`                                                               | the DEV wipe deletes members (`reset.php:57`) and their carts cascade, but guest carts have no member to cascade from — add a delete of `member_id IS NULL` carts                                                                                                                                                                                      |
| `install.sql`                                                             | every change above lands here too: `tests/run.php` rebuilds the `*_test` schema from `install.sql`, not from the `migrate_*.sql` files                                                                                                                                                                                                                 |

Admin: an optional "guest checkout" badge on `views/admin/users.php` and
`user_view.php`, derived from `registered_via` — display only.

---

## 11. Abuse controls and security

Guest checkout removes the registration hurdle, so bots can burn seats, mint
accounts and reserve stock (24 h per order). Three facts about the existing
code decide how this is controlled:

- **The existing limiter is per session, not per IP.** `rate_limit_check()`,
  `rate_limit_hit()` and `rate_limit_clear()` keep their counters in
  `$_SESSION['rl']` (`core/helpers.php:379-405`; login keys them on the
  username). A client that drops its cookie gets a clean counter on every request,
  so it cannot protect a public endpoint that creates accounts. Guest checkout
  gets a new IP-keyed limiter instead.
- **`check_username` has no throttle at all today**, and guest checkout makes it
  reachable by anyone.
- **The only client-IP source in the app is `$_SERVER['REMOTE_ADDR']`**
  (`core/Auth.php:76`, `AuthController.php:92`, `ShopOrder.php:850`); there is no
  forwarded-header handling. Behind a CDN or reverse proxy it may be the proxy's
  address, in which case per-IP limits would throttle everyone together — verify
  on the production host before relying on them.

| Control                 | Detail                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| CSRF                    | every guest POST, via `csrf_verify()`; guests have a session and a token (`index.php:425`)                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| Honeypot                | hidden field must be empty. CAPTCHA is the escalation path if honeypot plus limits are not enough — no provider chosen                                                                                                                                                                                                                                                                                                                                                                                                                      |
| IP rate limiter _(new)_ | `ip_rate_limit($bucket, $max, $windowSeconds)` in `core/helpers.php`, an atomic upsert on `rate_limits` keyed `bucket:ip`. _Proposed starting points:_ `guest_place_attempt` 10 / IP / 15 min; `guest_place_success` 3 / IP / 24 h (counted on commit — caps junk accounts and 24 h stock holds); `guest_cart_create` 20 / IP / hour; `check_username` 30 / IP / 15 min. Shared networks (mobile carriers, offices) can trip per-IP caps — start generous and tune from logs                                                                |
| Unpaid accounts         | **not** auto-deleted in v1. An unplaced account holds a seat but no binary slot; a placed one holds both, which is structural, so revisit with real data                                                                                                                                                                                                                                                                                                                                                                                    |
| Token hygiene           | 256-bit random, httponly cookie only, never in URLs or logs, nulled on claim                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| Passwords               | never remembered (the remember-input helpers are whitelists), flashed, logged or placed in URLs; the hash is computed before the transaction and only stored by the registration code                                                                                                                                                                                                                                                                                                                                                       |
| Server authority        | prices, stock, payment method, gate and sponsor existence are re-checked server-side inside the transaction; hidden UI is never the only guard                                                                                                                                                                                                                                                                                                                                                                                              |
| Ownership               | after commit, every order action is protected by the existing own-order checks; guest carts are reachable only by token                                                                                                                                                                                                                                                                                                                                                                                                                     |
| Redirects               | `next` is a whitelist of internal routes — no open redirects                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| Enumeration             | the availability check reveals whether a username exists, as at registration today; it is now rate limited                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| Session cookie          | `Auth::start()` (`core/Auth.php:20-31`) sets `session.cookie_httponly`, `use_strict_mode`, `cookie_secure` and the `mlm_sess` name, but **nothing calls it** — `index.php:46` calls bare `session_start()`, so those flags depend on `php.ini` and the cookie is the default `PHPSESSID`. Guests now hold sessions before they log in, so wiring `Auth::start()` (or setting the same values before `session_start()`) is recommended with this feature; `Auth::login()` already calls `session_regenerate_id(true)`, which limits fixation |
| Consent                 | the notice and terms wording in §6                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |

---

## 12. Edge cases and failure matrix

| Scenario                                                                                 | Behaviour                                                                                                                                                                                                                                                                                                                                                            |
| ---------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Username taken (including a concurrent submit)                                           | rollback; "Username is already taken."; input kept, passwords not                                                                                                                                                                                                                                                                                                    |
| Sponsor does not exist                                                                   | "Sponsor username not found." at the sponsor field; nothing written                                                                                                                                                                                                                                                                                                  |
| Sponsor is suspended or deactivated                                                      | still accepted — free registration checks existence only (`AuthController.php:348`); tightening that is a separate change that would apply to both paths                                                                                                                                                                                                             |
| No referral, sponsor is not a valid binary upline, or the sponsor's tree is full         | account created **unplaced** (D5); placement is chosen at activation                                                                                                                                                                                                                                                                                                 |
| Two guests race for the same free slot                                                   | the loser re-computes after the upline lock (§7.2-3); if nothing is free it registers unplaced                                                                                                                                                                                                                                                                       |
| Two guests race for the last seat                                                        | serialised by the seat lock (§7.2-1); the loser sees "Registration is closed right now"                                                                                                                                                                                                                                                                              |
| Seat limit reached, or `free_registration_enabled` turned off, between render and submit | rollback; "Registration is closed right now — log in to shop"; cart kept; no order                                                                                                                                                                                                                                                                                   |
| `shop_enabled` turned off mid-checkout                                                   | rollback; closed message; cart kept                                                                                                                                                                                                                                                                                                                                  |
| Product sold out or short between cart and submit                                        | rollback; back to `store_cart` with the first error (BW §6)                                                                                                                                                                                                                                                                                                          |
| Double-click or refresh                                                                  | §7.3 — no second account, no second order                                                                                                                                                                                                                                                                                                                            |
| Browser Back after success                                                               | the user is now signed in, so `store*` redirects to the member side; the cart is already converted, so nothing resubmits                                                                                                                                                                                                                                             |
| CSRF mismatch or expired session                                                         | the existing plain 403 "Invalid security token. Please go back and try again."; the guest cart survives in its cookie, typed input does not                                                                                                                                                                                                                          |
| Forged `payment_method=ewallet`                                                          | rejected as an invalid method                                                                                                                                                                                                                                                                                                                                        |
| Forged POST while the gate is closed                                                     | refused; nothing written                                                                                                                                                                                                                                                                                                                                             |
| `maintenance_mode` on                                                                    | `store*` returns the 503 page to non-admins (`index.php:52`)                                                                                                                                                                                                                                                                                                         |
| Unpaid order                                                                             | cron cancels it at the deadline (`payment_expired`); the account stays, placed or unplaced as it was; the guest can sign in and shop from `?page=shop`                                                                                                                                                                                                               |
| Guest signs out and returns later                                                        | `?page=login` with username and password → `?page=shop_orders` → the order → proof, if the window is still open                                                                                                                                                                                                                                                      |
| Forgot username or password                                                              | no self-service recovery exists in the app. Support can reset a member's password from the user page (`admin_change_password`, `AdminController.php:201`) and find the account through the admin member search, which matches username, **full name** and email — not mobile. Hence `full_name` ← `billing_name` (§6), and the confirmation flash shows the username |
| Same person checks out twice as a guest                                                  | two accounts — duplicates are not detected, as in free registration today (BW §1)                                                                                                                                                                                                                                                                                    |
| Guest cart older than the TTL                                                            | deleted by cron; the visitor starts a new cart                                                                                                                                                                                                                                                                                                                       |
| `guest_checkout_enabled` switched off with live guest carts                              | carts wait until their TTL; `store*` redirects to the landing page; members unaffected                                                                                                                                                                                                                                                                               |
| Cookies disabled                                                                         | no cart can be held; "Please enable cookies to shop"                                                                                                                                                                                                                                                                                                                 |
| Login fails after commit                                                                 | redirect to `?page=login`, flash names the username; account and order intact                                                                                                                                                                                                                                                                                        |

---

## 13. Routes quick reference (guest)

| Route                                                                                                         | Handler                                                  | Purpose                                         |
| ------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------- | ----------------------------------------------- |
| `store`                                                                                                       | `GuestShopController::index` _(new)_                     | public storefront                               |
| `store_add_to_cart`                                                                                           | `addToCart` _(new)_                                      | add (AJAX/POST)                                 |
| `store_cart`, `store_update_cart_item`, `store_remove_cart_item`                                              | cart actions _(new)_                                     | manage the guest cart                           |
| `store_checkout`                                                                                              | `checkout` _(new)_                                       | delivery + account + payment form               |
| `store_place_order`                                                                                           | `placeOrder` _(new)_                                     | atomic register + order, then sign in           |
| `check_username`                                                                                              | `AuthController::ajaxCheckUser` _(existing, role `any`)_ | reused; now rate limited                        |
| `login` / `do_login`                                                                                          | `AuthController` — **extended**                          | `next` parameter; cart merge on success         |
| `do_register`                                                                                                 | `AuthController` — **extended**                          | cart merge on success                           |
| `shop_order`, `shop_submit_proof`, `shop_cancel_order`, `shop_confirm_receipt`, `shop_orders`, `shop_invoice` | _(existing, BW §12)_                                     | unchanged — where the guest lands and continues |

Role for every `store*` route: `any` (`index.php:408-423` applies no check to
`any`), so each action performs the signed-in redirect of §3 itself. All new
names above are placeholders; the route table to extend is at `index.php:272`.

---

## 14. Happy-path summary

```
landing / referral link (?page=store&sponsor=<username>)
        │  sponsor remembered in a cookie (read-only at checkout)
        ↓
store → store_add_to_cart → store_cart → store_checkout
       (token cart, no account)        (delivery + create account + manual pay)
        │                                  │
        │       "Log in & continue" ───────┴─→ login → merge cart → checkout (§9)
        ↓
store_place_order ── ONE transaction ─────────────────────────────
   gate + seat lock → refresh/validate cart
        → register (pending; placed only under a referral sponsor)
        → claim cart → createFromCart (order pending)
   any failure ⇒ full rollback: no user, no order
        ↓ commit
save address (best effort) → Auth::login → ?page=shop_order&id=…
        ↓                                 "Upload your payment proof"
        ↓                                 ── from here: buyer_workflow.md ──
manual:  pending → payment_review ─(admin verifies)→ paid
                          └─(reject)→ payment_failed →(re-upload)→ payment_review
paid → packing → ready_to_ship → shipped → out_for_delivery → delivered
                                                   ↓ (POD; delivery_failed ⇄ OFD)
delivered →(buyer confirms | cron auto-completes)→ completed
```

Terminal states are unchanged: `completed`, `cancelled`. Stock: reserved at
placement → committed at `paid` → deducted once at `shipped` → released on
`cancelled`/`payment_failed`; the guest cart reserves nothing. Account: created
`pending` at placement, placed or unplaced per D5, survives order expiry or
cancellation; activation stays optional and never on the buying path.

---

## 15. Source review results

This spec was first written from `buyer_workflow.md` alone, with six assumptions
flagged for verification. The repository snapshot answered all six; several
answers changed the design.

| Tag | Question                                                         | Finding                                                                                                                                                                                                                                                                                                                                     | Effect                                                                                                 |
| --- | ---------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| V1  | Does `frontend/index.php` share the app's bootstrap and session? | No. `.htaccess` rewrites the root URL to it; it loads `config/db.php`, `core/helpers.php` and `models/Package.php` but not `Auth`, and never starts a session. The app session starts in `index.php:46` with bare `session_start()`                                                                                                         | the Shop entry is a plain link; sponsor and cart cookies live in the `store*` routes (§2)              |
| V2  | Which `users` columns exist beyond the register form's?          | `full_name`, `email`, `mobile`, `address`, `photo` and the payout fields, all nullable, edited on the profile page; `User::register()` sets none of them                                                                                                                                                                                    | guest checkout writes `full_name` and `mobile`; no email field in v1 (§6, §7.2)                        |
| V3  | Registration internals                                           | `User::register()` opens its own transaction (cannot nest), checks the seat limit without a lock, hashes the password inside the transaction, and checks slots with an unlocked `COUNT(*)` and no unique index. Placement is **conditional** — see below. `findNextBinarySlot` is private to `AuthController`. `users.username` is `UNIQUE` | `register()` adopts an outer transaction; seat lock; slot lock-and-recheck; BFS moves to `User` (§7.2) |
| V4  | Any email/SMS or password reset?                                 | None anywhere. `users.email` is unverified. An admin **can** set a member's password and search members by username, full name and email                                                                                                                                                                                                    | recovery is admin-mediated (§12)                                                                       |
| V5  | `carts` schema                                                   | `member_id NOT NULL` with a cascading FK, plain `idx_carts_member_status` (no unique), `updated_at ON UPDATE CURRENT_TIMESTAMP`; `cart_items` is `UNIQUE (cart_id, product_id)`. Almost every `Cart` method already takes a `$cartId`                                                                                                       | small migration; reuse `Cart` (§5, §10)                                                                |
| V6  | Reuse points                                                     | `check_username` exists (role `any`, unthrottled); role `any` applies no check; remember-input helpers are whitelists; the PSGC picker is a standalone partial but the delivery markup and submit JS are inline in `checkout.php`; the rate limiter is per session; `Auth::login()` takes a user row                                        | shared partials, new limiter, helper variants (§6, §7, §11)                                            |

Findings that went beyond the six questions:

- **Placement (changed D5).** A guest who registers free is placed in the binary
  tree only when the registration came from a referral link _and_ the sponsor is
  a valid binary upline with a free slot (`AuthController.php:387`;
  `User::isValidBinaryUpline`). Everyone else registers **unplaced** and chooses a
  connection at activation. The first draft assumed "sponsor's next free slot"
  unconditionally.
- **Sponsor field (changed D4).** The register form defaults a guest's sponsor to
  `admin` (`views/auth/register.php:29-32`) and makes the field read-only in
  referral mode (`:24`, `:309-313`). The first draft said "required, no
  default" and "editable".
- **Phone (refined D8).** The member checkout has two phone fields,
  `billing_phone` and `shipping_phone`, and requires neither
  (`MemberController.php:1423`); the first draft assumed one `phone` field.
- **Address save (refined).** The member flow saves the address _after_ commit,
  best effort, via a private helper — not inside the transaction.
- **Rate limiting (new requirement).** The existing limiter is session-based and
  cannot throttle a cookie-less bot (§11).
- **Dead session hardening.** `Auth::start()` is never called (§11).
- **Tests do exist.** `AGENTS.md` says the repo has no automated tests; it does:
  `php tests/run.php` (§16).

Still open — to settle during the build:

1. Is `REMOTE_ADDR` the real client IP on the production host (§11)?
2. Does production hold duplicate `(binary_parent_id, binary_position)` pairs? If
   not, add the unique index (§10).
3. Should the public storefront be indexable by search engines (§4)?
4. Should checkout collect an optional email (unverified, for support lookup)?
5. Should every registration path take the seat lock, not just guest checkout
   (§7.2-1)?

---

## 16. Code touch list and tests

**New**

| File                                                             | Purpose                                                            |
| ---------------------------------------------------------------- | ------------------------------------------------------------------ |
| `controllers/GuestShopController.php`                            | the seven `store*` actions and their guard                         |
| `views/store/`                                                   | storefront, cart, checkout, public layout, guest cart off-canvas   |
| `views/partials/checkout_delivery.php`, `checkout_submit_js.php` | extracted from `views/member/checkout.php`, which switches to them |
| `migrate_guest_checkout.sql`                                     | carts, users, `rate_limits`, settings                              |
| `tests/GuestCheckoutTest.php`                                    | the suite below                                                    |

**Modified**

| File                                                          | Change                                                                                                                                                                                      |
| ------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `index.php`                                                   | seven routes in `$routes` (`:272`), role `any`                                                                                                                                              |
| `models/User.php`                                             | `register()` adopts an open transaction and accepts `password_hash` / `registered_via`; new `afterRegister()`, `validateNewMember()`; `findNextBinarySlot()` moved in from `AuthController` |
| `controllers/AuthController.php`                              | `doRegister` uses the shared validator and moved BFS, and merges the cart; `doLogin` merges the cart and honours `next`; optional sponsor cookie in `showRegister`                          |
| `models/Cart.php`                                             | `getActiveByToken`, `getOrCreateForToken`, `claimFor`, `mergeInto`, idle purge                                                                                                              |
| `core/helpers.php`                                            | `guest_checkout_open()`, guest remember/old-input helpers, `ip_rate_limit()`, shared address-save helper                                                                                    |
| `controllers/MemberController.php`                            | `maybeSaveCheckoutAddress()` delegates to the shared helper                                                                                                                                 |
| `controllers/AdminController.php`, `views/admin/settings.php` | the three settings; optional badge in the admin member views                                                                                                                                |
| `cron/shop_expiry.php`                                        | leg 3 (idle guest carts, expired `rate_limits`)                                                                                                                                             |
| `install.sql`, `reset.php`                                    | schema and seeds; also wipe guest carts                                                                                                                                                     |
| `frontend/index.php`, `views/member/genealogy.php`            | Shop links                                                                                                                                                                                  |
| `views/auth/login.php`                                        | hidden `next` field                                                                                                                                                                         |

**Not modified:** `core/Commission.php` — `tests/predeploy.php` diffs it against
the baseline tag and fails the deploy gate on any drift (`:97`) — and
`models/ShopOrder.php`; `createFromCart()` is reused as is.

**Tests to add** (run with `php tests/run.php GuestCheckout`; the harness rebuilds
a `*_test` schema from `install.sql`):

- atomicity: a failure after the user insert leaves no user, no order, and the
  cart still a guest cart;
- double submit and replay (incl. a lost response); concurrent same username;
- last seat; last stock unit;
- forged `ewallet`; forged POST while the gate is closed;
- cart-token isolation: guest A cannot read or touch guest B's cart;
- placement matrix: referral + binary sponsor → placed; no referral → unplaced;
  non-binary sponsor → unplaced; full tree → unplaced;
- merge clamping to available stock; merge idempotency;
- no password in session, flash or remembered input;
- per-IP limiter windows.

The existing `TemplateLintTest` scans every file under `views/` automatically
(no `e()` inside inline event handlers — use `js()`; no file-scope functions in
views), so the new views are covered the moment they exist; add them to
`ViewRenderTest` as well, and keep `SchemaDriftTest` green by updating
`install.sql` with every column.
