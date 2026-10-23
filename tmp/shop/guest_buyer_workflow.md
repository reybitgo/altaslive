# Shop — Guest Buyer Workflow: Frontend Checkout to Completion

> **Status: PROPOSED SPEC — drafted 2026-10-18.** Unlike `buyer_workflow.md`
> (derived from executable source), nothing in this document exists in code
> yet. Routes, columns, settings and classes marked *(new)* are placeholder
> names. Items tagged **[V#]** are assumptions to verify against the source
> before building (§15). Once the feature ships, reconcile this file with the
> code and remove this banner.

A guest's-eye walkthrough of buying from the public frontend **without
registering first**: the visitor browses the storefront, fills in one checkout
form, places the order and — in that same step — receives a free account and is
signed in, landing on the normal member order page. From there the journey is
the member journey of `buyer_workflow.md`, through to `completed`.

This document covers only what is *different* for a guest: entry, sponsor
capture, cart, checkout, the atomic register-and-order step and the handoff. It
does **not** repeat the 14-status order journey; it points into
`buyer_workflow.md` instead (D10).

Conventions: **BW §N** = section N of `buyer_workflow.md`; plain **§N** = a
section of this document; **D#** = a design decision (§1); *(new)* = to be
built; *(existing)* = reused unchanged.

Strategy C reminder: guest orders earn **zero** compensation, like every shop
order — no PV, no pairing/unilevel bonus, no CD split, no DFI;
`Commission`/`CapEngine` are never involved. The only MLM effect is structural:
the new free account takes a slot in its sponsor's binary tree. Free
registration writes no commissions — everything defers to activation (BW §1).

---

## 1. What "guest" means here

A **guest** is a visitor with **no account yet** — not an anonymous buyer (D1).
Every guest checkout creates an account. There are no account-less orders:
proof upload, cancel, confirm-receipt, invoices and refunds are owner-scoped
member features behind `Auth::guard('member')` (BW §8).

| | Member journey (`buyer_workflow.md`) | Guest journey (this doc) |
|---|---|---|
| Account before shopping | Required — free registration (BW §1) | **Not required** — created at order placement |
| Where they shop | `?page=shop` (member layout, behind `guard('member')`) | `?page=store` *(new)* (public layout) |
| Cart owner | member id | random cookie token → claimed by the new member |
| Extra checkout fields | — | username, password, sponsor; phone becomes required |
| Payment methods | five, incl. `ewallet` | four manual methods only |
| Order creation | `createFromCart(memberId, …)` | the same call, inside the register-and-order transaction |
| After placement | order page `?page=shop_order&id=` | the same page, already signed in |

**Why one transaction.** Carts, `createFromCart(memberId, …)`, the idempotency
key (`sha1(uid|cart|idem)`) and every order action need a member id, so "place
the order, *then* register" cannot be done literally. Registration therefore
moves *inside* the order's transaction: register user → claim cart → create
order → commit. Any failure rolls back all of it. To the guest the rule still
reads "order placed ⇒ account exists", and a failed checkout never leaves an
orphan user or an orphan order.

**"Successful" means placed, not paid** (D2). A brand-new account has a zero
wallet balance, so the guest pays by manual proof (BW §1, §7b), and proof upload
lives on the member order page — the guest has to be signed in the moment the
order exists. The account is created with the order (status `pending`) and
**outlives** it: if the order later expires or is cancelled, only the order dies.

**Unchanged:** the 14-status order state machine, events, invoices, refunds, the
expiry cron, admin screens, the member journey and `?page=register` — guest
checkout is an *additional* way in, not a replacement.

### Design decisions in force

| ID | Decision | Detail |
|---|---|---|
| D1 | Guest = visitor without an account; every guest checkout creates one | §1 |
| D2 | Account created at order placement (`pending` order), atomically with it; survives an expired or cancelled order | §1, §7, §8 |
| D3 | Credentials: username (suggested, editable) + password + confirm, in a "Create your account" block | §6 |
| D4 | Sponsor: referral link → cookie → prefilled, editable; none → required field; optional `guest_default_sponsor` fallback (off by default) | §2, §6 |
| D5 | Placement: sponsor's next free slot, same rule as free registration; no upline/position fields | §7 |
| D6 | Manual payment methods only; `ewallet` hidden and rejected server-side | §6 |
| D7 | Availability gate: `shop_enabled` + `free_registration_enabled` + seat available + new `guest_checkout_enabled` (default off); checked at render and again inside the transaction | §3, §7 |
| D8 | Deviations from member checkout: phone required; address auto-saved; account-creation notice | §6 |
| D9 | Returning members: "Log in & continue" merges the guest cart; signed-in users never see guest pages | §9 |
| D10 | Scope: guest front half in full; order page → `completed` by reference to `buyer_workflow.md` | §8 |

---

## 2. Entry and sponsor capture

Entry points:

- **Landing page** (`frontend/index.php`): a **Shop** entry *(new)* linking to
  `?page=store`, shown only while the gate is `open` (§3) **[V1]**. The existing
  "Partner / Join" buttons and `?page=register` are untouched.
- **Shopper referral links** *(new)*: `?page=store&sponsor=<username>`. The
  existing `?page=register&ref=1&sponsor=<username>` link is unchanged.

Sponsor capture (D4):

| Aspect | Detail |
|---|---|
| Capture | Any `store*` request carrying `sponsor=<username>` stores the value in cookie `store_sponsor` *(new)* — only if it matches the username pattern (BW §1); anything else is ignored. Cookie is httponly, SameSite=Lax, secure in production, 30 days *(proposed)* |
| Trust | The cookie is **untrusted input — only a prefill**. It is re-validated at checkout render and again inside the place-order transaction |
| Newest link wins | A later referral link overwrites the cookie. This is safe because the sponsor is always shown prefilled **and editable** at checkout: the guest confirms who sponsors them, exactly like the register form's sponsor field (BW §1) |
| No referral | Cookie absent → the sponsor field is blank and **required** (sponsor is mandatory even for free registration, BW §1). If `guest_default_sponsor` is set, the field is prefilled with that username instead — still visible and editable |
| Invalid value | A cookie naming a missing or ineligible sponsor renders a blank field with a hint, not an error. Eligibility is whatever the registration rules say (shared core, §7) |

---

## 3. Availability gate (D7)

Order of checks on **every** `store*` request:

1. **Signed in?** → redirect to the member equivalent, merging any leftover guest
   cart first (§9). Guest pages are never shown to a signed-in user.
2. **Gate** — below.
3. The route's own logic.

Guest checkout is `open` only when **all** hold:

| Condition | Source | When closed |
|---|---|---|
| Shop on | `shop_enabled = 1` (BW §3) | "The shop is currently closed." rendered in place (guests have no dashboard to bounce to) |
| Guest checkout on | `guest_checkout_enabled = 1` *(new, default `0`)* | `store*` routes redirect to the landing page; the landing **Shop** entry is hidden |
| Free registration on | `free_registration_enabled = 1` (BW §1) | storefront stays browsable; add-to-cart and checkout are replaced by "Registration is closed right now — log in to shop" with a login link |
| Seat available | `isSeatLimitReached()` is false (BW §1) | same message as above |

A guest checkout **is** a free registration under the hood, so it obeys every
gate the register form obeys. The gate is evaluated three times:

- **Storefront / landing render** — decides what to show.
- **Checkout render** — blocks early with a message instead of letting the guest
  fill in the form.
- **Place order** — authoritative; re-checked **inside the transaction** (§7),
  because the last seat can be taken between render and submit and settings can
  flip mid-session. Forged POSTs while closed are refused: no user, no order.

Closing the gate never destroys guest carts; they wait until their TTL (§5).

---

## 4. Guest storefront — `?page=store`

- Public (frontend) layout, not the member layout. Shows `Product::active()`
  cards — the same catalogue and prices as `?page=shop` (BW §3) — with the cart
  badge fed by the guest cart (§5).
- Add-to-cart controls follow the gate (§3): open → normal; registration or
  seats closed → replaced by the closed message.
- No sponsor, account or login is needed to browse.

---

## 5. Guest cart

| Aspect | Detail |
|---|---|
| Owner | A **random token, not a member id**: 32 random bytes → 64 hex chars in cookie `guest_cart` *(new)* — httponly, SameSite=Lax, secure in production, lifetime `guest_cart_ttl_days` (default 7). Stored as `carts.guest_token`; `carts.member_id` stays `NULL` while it is a guest cart; exactly one of the two is set (§10) **[V5]** |
| Created | Lazily, on the first `store_add_to_cart` — browsing creates nothing |
| Logic | The **same `Cart` methods** as the member side — `addItem` (stock validated against *available* stock), `refreshPrices`, `validateStock`, `getTotals` — only the owner lookup differs (token instead of member id). No parallel implementation, so stock and price rules cannot drift |
| Stock | The cart holds none (BW §4). Reservation happens only when the order is placed (§7) |

Operations mirror BW §4–§5:

- `store_add_to_cart` — `product_id` + `quantity` (min 1), CSRF-protected; AJAX
  JSON `{ok, count}` or normal POST (flash + redirect to `?page=store_cart`). An
  existing line for the same product is incremented (still stock-checked);
  over-stock throws → "Insufficient stock…", nothing written.
- `store_cart` — runs `Cart::validateStock()` and shows stale-price /
  out-of-stock warnings. +/- buttons clamp to the server-rendered `max`;
  `store_update_cart_item` / `store_remove_cart_item` answer JSON for XHR
  (`{ok, subtotal, count}` or `{ok:false,error}` with HTTP 400); the checkout
  button is disabled at 0 items; summary and badges refresh in the browser.
- **Ownership.** The guest equivalent of `requireOwnCart()` (defect C3): the
  cart is **always resolved from the cookie token**; a posted `item_id` is
  paired with that cart's id; the client never supplies a cart id. The token is
  never accepted from a URL or body and never logged.
- **Lifetime.** Every mutation bumps `updated_at`. Carts untouched for longer
  than `guest_cart_ttl_days` are deleted by a new step in
  `cron/shop_expiry.php` (§10). Deleting has no inventory effect — carts hold no
  stock.
- **Cookies disabled** → no token can persist; add-to-cart answers "Please
  enable cookies to shop."

---

## 6. Guest checkout — `?page=store_checkout`

Server prerequisites (as BW §6): gate `open`; non-empty guest cart;
`Cart::refreshPrices()` (catalog price is authoritative) and
`Cart::validateStock()` — stock problems bounce to `?page=store_cart` with the
first error.

Page, top to bottom:

1. **"Already have an account? Log in & continue"** strip → §9.
2. Order summary (items, total).
3. **Delivery details** — the same address form and partial as the member
   checkout (region / province / city / barangay with PSGC codes), shared so the
   two cannot diverge.
4. **Create your account** *(new)* — username, password, confirm password,
   sponsor.
5. **Payment method** — four manual methods only (D6).
6. Notes (optional).
7. Terms checkbox and the account-creation notice.
8. Hidden `idem` token and a hidden honeypot field.

Fields posted to `?page=store_place_order`:

| Field | Required | Rule / note |
|---|---|---|
| `payment_method` | yes | one of `gcash`, `maya`, `usdt_trc20`, `usdt_bep20`. `ewallet` is not rendered and is **rejected server-side** — a new account has a zero balance (BW §1) |
| `billing_name` | yes | as member checkout; also fills the account's display name where `users` has one **[V2]** |
| `shipping_name`, `shipping_address` | yes | as member checkout |
| `phone` | **yes** (D8) | optional for members, required for guests — the only contact channel a guest account has **[V4]** |
| barangay / city / province / region / postal (+ PSGC codes) | as member checkout | same rules, same partial |
| `notes_member` | no | |
| `terms` | yes (`=1`) | covers terms `v1` **and** account creation |
| `username` | yes | registration rule (BW §1): 3–40 chars, letters/numbers/underscore, starts with a letter; trimmed and lowercased like login (BW §2); unique |
| `password`, `password_confirm` | yes | ≥ 8 chars and matching; bcrypt cost 12 on write, same as registration |
| `sponsor` | yes | prefilled per §2; validated by the registration core |
| `idem` | yes | idempotency token (BW §6) |
| honeypot (`website`) | must be empty | any value is treated as a failed attempt with a generic error |
| upline / position | not posted | resolved server-side from the sponsor (D5) |
| `save_address`, `address_label` | not posted | the address is **always** saved to the new account (D8) |

Behaviour:

- **Why username + password (D3).** Login is username/password (BW §2) and no
  email or SMS channel is documented **[V4]**, so choosing credentials at
  checkout is the minimum that needs no credential delivery. The guest is signed
  in straight after the order, exactly as after free registration.
- **Username suggestion** (client-side convenience): once `billing_name` is typed
  and `username` is untouched, suggest a lowercase/underscore form of the name
  (prefixed with a letter if needed, clamped to 3–40), then run the existing AJAX
  availability check (BW §1) **[V6]**. The server validates only what is posted.
- **Prefill order**: remembered one-shot old input (after a failed submit) →
  blank. A guest has no address book. `shop_checkout_remember_input()` must
  **never retain `password` or `password_confirm`** **[V6]**.
- **Validation failures** write nothing, keep everything typed (except
  passwords) and name exactly what is missing or invalid — "billing name",
  "recipient name", "delivery address", "phone", "username", "password",
  "sponsor" — as BW §6 does. A taken username shows at the username field.
- **Account-creation notice** *(proposed copy)*, directly above the submit
  button: "Placing this order creates your free account **@username**. You'll use
  it to upload your payment proof, track your order and download invoices." The
  terms label covers both the shop terms and account creation; have the wording
  reviewed against your privacy notice (Data Privacy Act of 2012).
- The submit button disables after the first click.

---

## 7. Placing the order — `?page=store_place_order`

Identical to BW §7 from `createFromCart` onward. What is new is everything
before it: one DB transaction now wraps **register + claim cart + save address +
create order**.

### 7.1 Pre-transaction checks (no locks, nothing written)

1. CSRF verified; honeypot empty; rate limits (§11).
2. **Signed in?** → redirect to the member side; no second order (§9).
3. **Replay lookup** — compute the guest idempotency key
   `sha1('guest'|cart_token|idem)` from the cookie token and the posted `idem`.
   This is the guest analogue of `sha1(uid|cart|idem)`: no uid exists yet, and the
   cart id cannot be used because the claim in 7.2-4 nulls the token. If an order
   already carries this key → replay response (§7.3); nothing is created.
4. Resolve the guest cart from the token; missing or empty → `?page=store_cart`.
5. Gate `open` (§3).
6. Validate input — shop fields as BW §6 and account fields as §6;
   `payment_method` must be one of the four manual methods. Failure →
   `shop_checkout_remember_input()` (minus passwords) → back to `store_checkout`
   with the field-naming message.

### 7.2 The transaction

1. **Re-check the gate** — `shop_enabled`, `guest_checkout_enabled`,
   `free_registration_enabled`, `isSeatLimitReached()` — inside the transaction
   and serialised with other registrations, so the last seat cannot be taken
   twice **[V3]**. Closed → rollback, "Registration is closed right now".
2. **Cart refresh** — `Cart::refreshPrices($cartId)` then
   `Cart::validateStock($cartId)`. A problem rolls back and returns to
   `store_cart` with the first error (BW §6).
3. **Register** — via the **shared registration core**, extracted from the
   free-registration block of `AuthController` so the two paths can never drift.
   It must join the outer transaction rather than open its own **[V3]**.
   - Sponsor validated (D4); username uniqueness enforced by the unique
     constraint as the final authority **[V3]**.
   - **Placement = the sponsor's next free slot** (D5), resolved under a lock or
     a unique-index retry so two guests cannot take the same slot **[V3]**.
   - Row written exactly as free registration (BW §1): `status='pending'`,
     `reg_payment_method='pending'`, `package_id=NULL`, `reg_code_id=NULL`, plus
     `registered_via='guest_checkout'`; password bcrypt cost 12; profile fields
     from checkout where the columns exist **[V2]**.
   - **No commissions, no leg counts** — everything defers to activation.
4. **Claim the cart** — `UPDATE carts SET member_id=:uid, guest_token=NULL WHERE
   id=:cid AND guest_token=:token AND member_id IS NULL`. It must affect exactly
   one row, otherwise abort.
5. **Save the address** — the posted delivery fields become the new member's
   default `MemberAddress` (D8).
6. **Create the order** — `ShopOrder::createFromCart(uid, cartId, addr, method,
   key)` **unchanged** (BW §7), with `key` = the guest key from 7.1-3: prices
   re-read, stock re-validated, `shop_orders.status='pending'`,
   `payment_deadline = NOW() + shop_payment_deadline_hours` (default 24 h), terms
   `v1`, `order_no` = `AL` + yymmdd + zero-padded id, every line `reserved`
   (**stock is now reserved**), cart marked converted, event `null → pending`
   reason `order_placed` actor `customer`.
7. **Commit.**

Any exception rolls back **all** of it: no user, no order, the cart is still a
guest cart, stock untouched. The checkout re-renders with the remembered input
(no passwords).

### 7.3 Replay and double-submit

- A second request from a browser that is already signed in is caught by 7.1-2:
  redirected, nothing created. The submit button also disables on first click.
- A **duplicate-key outcome** inside the transaction — the username, the cart
  claim affecting 0 rows, or the idempotency key — rolls back and re-runs the
  replay lookup. Found → replay response; not found → the genuine error
  (username taken, or cart no longer available).
- **Replay response**: nothing is created; the guest is sent to `?page=login`
  with "Your order was already placed — sign in as @username to continue." This
  also covers a response lost after commit: the cookie token is still in the
  browser, the cart is already claimed, and the key still finds the order.

### 7.4 After commit

- `Auth::login($newUserId)` — regenerates the session id, sets
  `user_id/user_role/username`, stamps `last_login` (as free registration,
  BW §1).
- The `guest_cart` and `store_sponsor` cookies are cleared.
- Flash *(proposed copy)*: "Order placed. Upload your payment proof to continue.
  Your free account @username was created — sign in with it next time."
- Redirect to `?page=shop_order&id=<id>` — the order page (BW §8), status
  `pending`, proof form showing. **The guest journey ends here; the member
  journey takes over (§8).**
- Should the login somehow fail after commit, redirect to `?page=login` with a
  flash naming the username; account and order are intact.

---

## 8. Handoff — from guest to ordinary member buyer

After §7 the guest *is* an ordinary `pending` member holding a `pending` order;
account status `pending` is sufficient for the whole journey (BW §1). Everything
from here is `buyer_workflow.md`:

| Stage | Where | Guest-specific note |
|---|---|---|
| Order page and actions (`proof` / `cancel` / `confirm`) | BW §8 | unchanged — `canMemberAct()` and the own-order checks apply to the new member |
| `pending` | BW §9.1 | 24 h payment window; the proof form is already on screen after the redirect |
| Proof → `payment_review` → `paid` / `payment_failed` | BW §9.2–§9.5 | unchanged. The first order can never be e-wallet, so there is no auto-verify path |
| `packing` → `ready_to_ship` → `shipped` → `out_for_delivery` | BW §9.6–§9.8 | unchanged; stock is deducted once, at `shipped` (BW §9.7) |
| `delivery_failed` / `returned_to_sender` / `on_hold` | BW §9.9, §9.10, §9.14 | unchanged — reachability matters, which is why phone is mandatory (D8) |
| `delivered` → `completed` | BW §9.11–§9.12 | unchanged: buyer confirms, or the cron auto-completes after `shop_report_window_days` |
| `cancelled` | BW §9.13 | unchanged — and **the account survives** (D2) |
| Refunds | BW §10 | the first order is always manual-pay, so any refund settles out of band, never to an e-wallet |
| Timers | BW §11 | unchanged — `shop_expiry.php` may cancel an unpaid order at its deadline but never touches the account |

Afterwards the buyer is a normal member: later orders go through `?page=shop`
(member cart; e-wallet only once funded) with the address saved at checkout
prefilled, and the dashboard shows the standard pending-activation prompt
(BW §1). Activation stays optional and never on the buying path.

---

## 9. Returning members and cart continuity (D9)

| Situation | Behaviour |
|---|---|
| Guest with a cart clicks **"Log in & continue"** | `?page=login&next=checkout` (`next` limited to a whitelist of internal routes). On `do_login` success: merge the guest cart into the member's active cart, then go to `?page=checkout` |
| Guest registers the normal way (`do_register`) | After the existing auto-login (BW §1) the guest cart is merged into the new member's cart; destination unchanged (`?page=dashboard`); the flash adds "Your cart items were kept." |
| Signed-in user opens any `store*` URL | Redirect to the member equivalent — `store`→`shop`, `store_cart`→`cart`, `store_checkout`→`checkout` — after merging any leftover guest cart. Admins and superadmins included: they pass `guard('member')` (BW §2) |
| Suspended account | Login is refused as in BW §2; nothing merges |

Merge rules (`Cart::mergeInto($userId)` *(new)*):

- Each guest line is added to the member's line for the same product (or creates
  it), **clamped to available stock** by the BW §4 rules; a line that cannot be
  added at all is dropped with a notice naming the product.
- The guest cart row is deleted and the `guest_cart` cookie cleared.
- Destination is `?page=checkout` for a clean merge. If the member already had
  items, or any line was clamped or dropped, it is `?page=cart` with a flash
  explaining the merge, so quantities are reviewed before paying.
- Idempotent: a second call finds no guest cart and does nothing.

---

## 10. Settings and data changes

Settings (admin, alongside the shop settings block at `AdminController:531`):

| Key | Default | Meaning |
|---|---|---|
| `guest_checkout_enabled` *(new)* | `0` | master switch for the whole guest path; ships **off**, turned on after QA |
| `guest_default_sponsor` *(new)* | empty | optional house-sponsor username that prefills the sponsor field when there is no referral; empty = the field is required with no prefill |
| `guest_cart_ttl_days` *(new)* | `7` | guest cart and cookie lifetime, and the cleanup threshold |

Existing settings that interact: `shop_enabled` and `free_registration_enabled`
(the gate, §3), `shop_payment_deadline_hours` (24) and the rest of BW §11.

Data:

| Where | Change |
|---|---|
| `carts` | add `guest_token` (CHAR(64), NULL, unique); make `member_id` nullable; invariant: exactly one of the two is set; `updated_at` drives TTL cleanup **[V5]** |
| `users` | add `registered_via` (VARCHAR(20), NOT NULL, default `'register'`); guest checkout writes `'guest_checkout'`; existing rows are backfilled `'register'` |
| `shop_orders`, `shop_order_items`, `shop_order_events`, invoices, refunds | **no change** (optionally put `guest_checkout` in the first event's note, if the events table carries a free-text note — BW §9.7 suggests it does) |
| `cron/shop_expiry.php` | new step 4: delete guest carts past `guest_cart_ttl_days` (runs every 15 min with the existing steps, BW §11) |

Admin: an optional "guest checkout" badge on user rows and order detail, derived
from `registered_via` — display only.

---

## 11. Abuse controls and security

Guest checkout removes the registration hurdle, so bots can burn seats and
binary slots and reserve stock (24 h per order). Controls:

| Control | Detail |
|---|---|
| CSRF | every guest POST (guests have sessions, so tokens work) |
| Honeypot | hidden field must be empty. CAPTCHA is the escalation path if honeypot plus limits are not enough — no provider chosen |
| Rate limits | via `rate_limit_check`, as login uses it (BW §2). *Proposed starting points:* place-order attempts 10 / IP / 15 min; **successful** guest checkouts 3 / IP / 24 h; username-availability AJAX 30 / IP / 15 min. Shared IPs (mobile carriers, offices) can trip per-IP caps — start generous and tune from logs |
| Unpaid accounts | **not** auto-deleted in v1 — tree placement is structural; revisit with real data |
| Token hygiene | 256-bit random, httponly cookie only, never in URLs or logs, nulled on claim |
| Passwords | never remembered, flashed, logged or placed in URLs; hashed only inside the shared registration core |
| Server authority | prices, stock, payment method, gate and sponsor eligibility are re-checked server-side inside the transaction; hidden UI is never the only guard |
| Ownership | after commit, every order action is protected by the existing own-order checks; guest carts are reachable only by token |
| Redirects | `next` is a whitelist of internal routes — no open redirects |
| Enumeration | the availability check reveals whether a username exists, as at registration today; it is rate limited |
| Consent | the notice and terms wording in §6 |

---

## 12. Edge cases and failure matrix

| Scenario | Behaviour |
|---|---|
| Username taken (including a concurrent submit) | rollback; error at the username field; input kept, passwords not |
| Sponsor missing, invalid or ineligible | error at the sponsor field; nothing written |
| Seat limit reached, or `free_registration_enabled` turned off, between render and submit | rollback; "Registration is closed right now — log in to shop"; cart kept; no order |
| `shop_enabled` turned off mid-checkout | rollback; closed message; cart kept |
| Product sold out or short between cart and submit | rollback; back to `store_cart` with the first error (BW §6) |
| Double-click or refresh | §7.3 — no second account, no second order |
| Browser Back after success | the user is now signed in, so `store*` redirects to the member side; the cart is already converted, so nothing resubmits |
| Forged `payment_method=ewallet` | rejected as an invalid method |
| Forged POST while the gate is closed | refused; nothing written |
| Unpaid order | cron cancels it at the deadline (`payment_expired`); the account stays; the guest can sign in and shop from `?page=shop` |
| Guest signs out and returns later | `?page=login` with username and password → `?page=shop_orders` → the order → proof, if the window is still open |
| Forgot username or password | no self-service recovery is documented **[V4]**; the confirmation flash shows the username; support can look the account up by name or phone |
| Same person checks out twice as a guest | two accounts — duplicates are not detected, as in free registration today (BW §1) |
| Referral cookie names a sponsor who is later suspended | blank prefill plus hint; submit needs a valid sponsor |
| Guest cart older than the TTL | deleted by cron; the visitor starts a new cart |
| `guest_checkout_enabled` switched off with live guest carts | carts wait until their TTL; `store*` redirects to the landing page; members unaffected |
| Cookies disabled | no cart can be held; "Please enable cookies to shop" |
| Login fails after commit | redirect to `?page=login`, flash names the username; account and order intact |

---

## 13. Routes quick reference (guest)

| Route | Handler | Purpose |
|---|---|---|
| `store` | `GuestShopController::index` *(new)* | public storefront |
| `store_add_to_cart` | `addToCart` *(new)* | add (AJAX/POST) |
| `store_cart`, `store_update_cart_item`, `store_remove_cart_item` | cart ops *(new)* | manage the guest cart |
| `store_checkout` | `checkout` *(new)* | delivery + account + payment form |
| `store_place_order` | `placeOrder` *(new)* | atomic register + order, then sign in |
| username availability (AJAX) | *(existing, BW §1)* | reused; rate limited |
| `login` / `do_login` | `AuthController` — **extended** | `next` parameter; cart merge on success |
| `do_register` | `AuthController` — **extended** | cart merge on success |
| `shop_order`, `shop_submit_proof`, `shop_cancel_order`, `shop_confirm_receipt`, `shop_orders`, `shop_invoice` | *(existing, BW §12)* | unchanged — where the guest lands and continues |

Role for `store*`: `any`, with the signed-in redirect of §3 **[V6]**. All new
names above are placeholders.

---

## 14. Happy-path summary

```
landing / referral link (?page=store&sponsor=<username>)
        │  sponsor remembered in a cookie
        ↓
store → store_add_to_cart → store_cart → store_checkout
       (token cart, no account)        (delivery + create account + manual pay)
        │                                  │
        │       "Log in & continue" ───────┴─→ login → merge cart → checkout (§9)
        ↓
store_place_order ── ONE transaction ─────────────────────────────
   gate → refresh/validate cart → register (pending, next free slot)
        → claim cart → save address → createFromCart (order pending)
   any failure ⇒ full rollback: no user, no order
        ↓ commit
Auth::login → ?page=shop_order&id=…  "Upload your payment proof"
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
`pending` at placement, survives order expiry or cancellation; activation stays
optional and never on the buying path.

---

## 15. Verification checklist (before building)

This spec was written from `buyer_workflow.md` alone. These items need the
source:

| Tag | Verify |
|---|---|
| **V1** | **Frontend wiring.** Does `frontend/index.php` run inside the app's bootstrap and share the `mlm_sess` cookie, domain and models? Guest cart, sponsor capture and auto-login depend on it. If it is static or separate, the **Shop** entry is a plain link into `index.php?page=store` and capture happens there |
| **V2** | **`users` columns.** Which profile columns exist beyond username, password, sponsor and placement (name, phone, email)? That decides what "registration data from checkout" actually populates — the register form collects none of these today |
| **V3** | **Registration internals.** Who resolves the sponsor's next free slot (server-side, under what locking)? Does `User::register()` open its own transaction (it would have to join the outer one)? What does `isSeatLimitReached()` count, and can it be serialised with the insert? Is username uniqueness a DB constraint? |
| **V4** | **Contact and recovery.** Is there any email/SMS sending or password reset? `buyer_workflow.md` shows none, which sets how recoverable a guest account is |
| **V5** | **`carts` schema.** The names `carts`, `member_id` and `updated_at` are assumed from the `Cart` class — confirm them, plus existing unique indexes (e.g. one active cart per member), `member_id` nullability, and any foreign keys a nullable owner would break |
| **V6** | **Reuse points.** The username-availability endpoint (route, rate limiting), `shop_checkout_remember_input()` (password exclusion), router semantics of role `any`, and where the address and payment partials can be extracted |
