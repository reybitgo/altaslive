# Shop — Buyer Workflow: Free Registration to Completion

A buyer's-eye walkthrough of the physical-goods shop: every screen, action,
status, deadline and side effect from **free registration** through signing in
to the order reaching `completed`. Derived from executable source (routes in
`index.php`, `AuthController` registration block, `MemberController` shop
block, `ShopOrder` state machine, member views, cron).

Strategy C reminder: shop orders earn **zero** compensation — no PV, no
pairing/unilevel bonus, no CD split, no DFI. `Commission`/`CapEngine` are never
involved. The only money figures are `shop_orders.total_price` (what the buyer
pays) and any refund amount.

---

## 1. Free registration — the minimum requirement to shop

The journey starts before login: a visitor creates a **free** account. Free
registration is what makes someone a buyer at all — it is the *minimum*
account state the shop requires. Package activation unlocks *earning*, not
*buying*.

| Step | Detail |
|---|---|
| Route | `?page=register` → `AuthController::showRegister()` (role `any` — guests and logged-in registrars); POST `?page=do_register` |
| Entry points | "Partner / Join" buttons on the landing page (`frontend/index.php`); referral links `?page=register&ref=1&sponsor=<username>` |
| Gates | Seat limit (`isSeatLimitReached()` → 403 `register_closed`); setting `free_registration_enabled` (default `1`) — when off, the free option is hidden and forged POSTs are silently refused (`AuthController.php:281`) |
| Form | username (3–40 chars, letters/numbers/underscore, starts with a letter; AJAX availability check), password ≥ 8 + confirm, **sponsor username (mandatory even for free)**; optional binary upline/position prefilled from the sponsor's next free slot |
| Account written | `User::register()`: `status = 'pending'` (the not-yet-activated state), `reg_payment_method = 'pending'`, `package_id = NULL`, `reg_code_id = NULL` — no package, no fee, no code |
| Commissions | **None.** No leg counts, no direct/indirect referral bonus — everything defers to activation (`models/User.php:127`) |
| Guest success | Auto-logged-in (`Auth::login`), flash "Welcome! Your account is pending activation. Activate now to unlock earning features." → `?page=dashboard`. **No separate login step needed** — the new buyer is already inside |
| Logged-in registrar | Session restored to the registrar; flash "@user registered — Awaiting activation." |

Why `pending` is enough to buy:

- `Auth::guard('member')` (the guard on every shop route) only force-logs-out
  `suspended` accounts (`core/Auth.php:262`) — `pending` passes.
- Nothing else in the shop stack checks `users.status`; `guardShop()` only
  checks the `shop_enabled` setting.
- A fresh pending member can therefore browse, add to cart, check out and pay
  via **GCash / Maya / USDT proof upload** immediately. The e-wallet method is
  technically selectable but fails at debit (zero balance) — the usable path
  for a brand-new buyer is the manual-proof one.

Activation (`?page=activate`, registration code or e-wallet —
`MemberController::activate`) is the *later*, optional step that flips
`pending → active`, assigns a package and starts commissions. It is
deliberately **not** on the buying path.

---

## 2. Login

| Step | Detail |
|---|---|
| Route | `?page=login` → `AuthController::showLogin()` (guest); POST `?page=do_login` |
| Input | username (lowercased/trimmed), password; CSRF verified |
| Rate limit | 5 failed attempts per username per 15 min (`rate_limit_check('login_…', 5, 900)`) |
| Password | bcrypt `password_verify` against `users.password_hash` (cost 12) |
| Blocked | `status = 'suspended'` → "Your account has been suspended." (no session) |
| Success | `Auth::login()` regenerates the session id, sets `user_id/user_role/username`, stamps `last_login`; members redirect to `?page=dashboard`, admins to `?page=admin` |
| Session | cookie `mlm_sess`, httponly, strict mode, secure in production |

Notes:

- A brand-new user who just registered free (§1) is already logged in — this
  screen is for **returning** members (and for buyers who signed out between
  visits).
- A suspended account already logged in is kicked at the next request:
  `Auth::guard()` calls `Auth::logout()` when `Auth::user()['status'] === 'suspended'` (`core/Auth.php:262`).
- `Auth::guard('member')` (used by every shop page) admits `member`, `admin`
  and `superadmin` — admins can browse and buy like anyone else, but
  `ShopOrder::assertNotSelfReview()` blocks them from verifying/packing/
  shipping/completing/cancelling/refunding **their own** orders.

---

## 3. Entering the shop

- Route `?page=shop` → `MemberController::shop()`.
- **Master switch:** `guardShop()` checks `setting('shop_enabled','1')`. If the
  shop is off, the buyer is flashed "The shop is currently closed." and sent
  back to the dashboard. The switch gates `shop`, `cart`, `checkout`,
  `place_order` and `add_to_cart`. It does **not** gate `shop_orders` /
  `shop_order` — an existing order stays viewable while the shop is closed, and
  the expiry cron keeps running regardless (`cron/shop_expiry.php:92`).
- Screen shows `Product::active()` cards, plus cart badge count from the
  buyer's active cart (`Cart::getActive` + `Cart::getTotals`).

---

## 4. Add to cart

Route `?page=add_to_cart` (`addToCart`), CSRF-protected, shop switch enforced.

- Form posts `product_id` + `quantity` (min 1). Works as AJAX (JSON
  `{ok, count}`) or normal POST (flash + redirect back to `?page=shop&cart=1`).
- `Cart::getOrCreate(memberId)` — one active cart per member.
- `Cart::addItem` validates against **available** stock: `products.stock`
  minus quantities already held by `reserved`/`committed` lines of live orders
  (`Product::availableStockMap` / `reservedStock`). Over-stock throws
  `InvalidArgumentException` → "Insufficient stock…" — nothing is written.
- Existing line for the same product → quantity incremented (still
  stock-checked), not a duplicate row.
- The cart itself holds **no stock** — reservation only happens when the order
  is placed (`shop_order_items.reservation_state = 'reserved'`).

Cart badges (topbar + sidebar) update from the returned `count`.

---

## 5. Cart page — `?page=cart`

Server: `cart()` loads active cart, items, totals and runs
`Cart::validateStock($cartId)` — stale-price/out-of-stock warnings render here
before checkout.

Client behaviour (`views/member/cart.php`):

- **+/- quantity buttons:** clamped to the server-rendered `max` (available
  stock) client-side; over-stock taps toast "Insufficient stock. Requested X,
  only Y available." and are never POSTed. On `change`, posts
  `?page=update_cart_item` (`item_id`, `quantity`); failure reverts the input
  to the last server-confirmed value.
- **Remove:** posts `?page=remove_cart_item`; when the cart hits 0 items the
  checkout button is disabled client-side too.
- Ownership: every mutation goes through `requireOwnCart()` — the posted
  `item_id` is always paired with the caller's own active cart id (defect C3).
- Live summary: subtotal / total / item count recalculated in-browser, cart
  badges refreshed.

Both endpoints answer JSON for XHR (`{ok, subtotal, count}` or
`{ok:false,error}` with HTTP 400).

---

## 6. Checkout — `?page=checkout`

Server (`checkout()`):

- Requires a non-empty active cart; re-runs `Cart::refreshPrices()` (catalog
  price is authoritative — a cart line never bills at a stale price) and
  `Cart::validateStock()`; stock problems bounce back to `?page=cart` with the
  first error.
- Renders item list, total, the five payment methods
  (`ewallet`, `gcash`, `maya`, `usdt_trc20`, `usdt_bep20`) and the address
  form. Address prefill order: remembered one-shot old input
  (`shop_checkout_old_input()` — used after a failed submit) → saved address
  book (`MemberAddress::forMember`, default address first) → blank form.

Form fields posted to `?page=place_order`:

- `payment_method`, `billing_name` (required), `shipping_name` (required),
  `shipping_address` (required), phone, barangay/city/province/region/postal
  (+ PSGC codes), `notes_member`, `terms=1` (required), optional
  `save_address=1` + `address_label`, `idem` (idempotency token).

Validation failures keep everything typed: `shop_checkout_remember_input($_POST)`
then redirect back to checkout with a message naming exactly the missing fields
("billing name", "recipient name", "delivery address").

---

## 7. Placing the order — `?page=place_order`

One DB transaction wraps **create + (maybe) wallet debit + paid transition**:

1. `ShopOrder::createFromCart(memberId, cartId, addr, method, sha1(uid|cart|idem))`
   - Idempotency: a repeated key returns the existing order (unique-index race
     resolves to the winner's id) — no double order, no double charge.
   - Prices re-read from cart (already refreshed); stock re-validated; total =
     Σ(unit_price × qty).
   - Inserts `shop_orders` with `status='pending'`, `payment_deadline =
     NOW() + shop_payment_deadline_hours` (default **24 h**), terms `v1`
     accepted.
   - `order_no` = `AL` + yymmdd + zero-padded id (`shop_public_order_no`).
   - Every line inserted with `reservation_state='reserved'` — **stock is now
     reserved** (other buyers' availability drops accordingly).
   - Cart marked converted; event `null → pending`,
     reason `order_placed`, actor `customer`.

### 7a. E-wallet payment (instant)

- `FOR UPDATE` lock + replay guard: if the order isn't still `pending` or a
  `shop_order` debit already exists, commit as a no-op ("Order already
  processed") — the race can't double-charge.
- `Ewallet::debitInternal(total, orderId, 'shop_order')`; insufficient balance
  → `RuntimeException`, whole transaction rolls back (order, cart untouched).
- `ShopOrder::markPaid(orderId, 'system')` runs **inside** the same
  transaction: `pending → paid` (actor `system`, reason
  `ewallet_auto_verified`), side effects: lines `reserved → committed`,
  **invoice issued** (immutable snapshot, `ShopInvoice::issue`).
- Cart cleared, commit. Buyer lands on the order page: "Order placed and paid.
  Thank you!" — the buyer's journey skips straight to **paid**.
- Practically this path is for **funded, activated members** — a fresh
  `pending` account (§1) has a zero balance, so manual proof (7b) is the route.

### 7b. Manual payment (GCash / Maya / USDT)

- Order commits as `pending`; cart converted; buyer redirected with
  "Order placed. Upload your payment proof to continue."
- Failures at any point roll back everything and re-render checkout with the
  remembered input.

---

## 8. The order page — `?page=shop_order&id=`

Every buyer-visible transition happens from this page (`views/member/shop_order.php`).
It shows:

- Status badge (`shop_status_badge` — label + tone shared with admin), total,
  payment method, "Placed <time>".
- **Exactly one status-specific panel** ("What's happening") — the only place
  buyer action buttons appear.
- Items table, delivery address, payment-proof list (attempt # + status),
  refund list (amount + status), and the **History timeline**
  (`shop_order_timeline` partial, client-side paginated 5/10/20/50).
- Invoice button appears once the order is past the payment legs
  (hidden for `pending`/`payment_review`/`payment_failed`/`cancelled`).

Action visibility is `ShopOrder::canMemberAct()` (server re-checks in each
handler — hidden buttons are never the only guard):

| Action | Visible when |
|---|---|
| `proof` upload | status `pending` or `payment_failed`, deadline not passed, attempts < `shop_max_proof_attempts` (default 3) |
| `cancel` | status `pending` or `payment_failed` |
| `confirm` receipt | status `delivered` **and** no open/approved refund |

---

## 9. Status-by-status journey (all 14)

Transition authority: only `ShopOrder::transition()` writes `shop_orders.status`
(conditional `UPDATE … WHERE status = ?` race guard, `version` bump, one
append-only `shop_order_events` row in the same transaction).

### 9.1 `pending` — awaiting payment

- **Who sees:** buyer panel: "Pay for your order — send ₱X via <method>, then
  upload the proof. Submitting proof ends your option to cancel."
- **Deadline:** `payment_deadline` (default 24 h) shown; overdue shows "⚠
  Payment window has closed" and the upload form disappears (contact support).
- **Buyer actions:** upload proof (`?page=shop_submit_proof`) or cancel
  (`?page=shop_cancel_order`).
- **Exits:** proof → `payment_review`; e-wallet path never rests here
  (§7a); buyer cancel → `cancelled`; admin cancel → `cancelled`;
  timer past deadline → `cancelled` (reason `payment_expired`);
  admin → `on_hold`.

### 9.2 Proof upload — `pending`/`payment_failed` → `payment_review`

Route `shop_submit_proof` (`submitShopProof`), multipart:

- Validates: own order; window open (status + deadline + attempt cap);
  image is JPG/PNG/GIF/WebP ≤ 5 MB → stored under `uploads/shop_proofs/`.
- Optional claim fields: `reference_no` (≤40), `amount_sent`, `transfer_date`.
- `ShopOrder::submitProof()` writes the proof row **as a transition guard** —
  proof insert and status change commit together or not at all (no orphan
  proofs racing the expiry cron, defect M1).
- Success: "Proof submitted. We will verify your payment shortly."
- Re-upload after rejection also re-reserves the released lines, refusing the
  whole re-entry (throws `StockConflictException`) if stock sold out meanwhile.
- **`payment_deadline` is never modified** — but `payment_review` is exempt
  from expiry anyway (§9.3).

### 9.3 `payment_review` — staff verifying

- Buyer panel: "We're verifying your payment — staff check proofs within about
  24 hours (`SLA_REVIEW_HOURS`)." No buttons.
- **Never auto-expires** (T7): `expireOverdue()` only scans
  `pending`/`payment_failed`.
- Admin actions on `?page=admin_shop_order`:
  - **Verify** (`paid`): exact-amount match required (no tolerance), payment
    reference required and unique across all orders/proofs; proof marked
    `verified`; → §9.4.
  - **Reject** (`payment_failed`): proof marked `rejected` with a reason;
    correction window opens (§9.5).
  - **Revert from paid** exists too (`revertPaidToReview`) — but only while
    still `paid`, i.e. before packing; it voids the invoice (`paid_reverted`).

### 9.4 `paid` — payment confirmed

- Entered by admin verify or e-wallet auto-verify (`pending → paid` or
  `payment_review → paid`; actors `admin`/`system`).
- Side effects: lines `reserved → committed`; invoice snapshot issued
  (printable at `?page=shop_invoice&id=`); `paid_by`/`paid_at` stamped.
- Buyer panel: "We're preparing your order — payment verified, items are being
  picked and packed. Tracking appears here once handed to the courier."
  (same panel covers `packing` and `ready_to_ship`).
- Buyer has **no cancel button** from here — only admin/system may cancel
  (and admin cancel of a paid order auto-opens a refund, §9.13).

### 9.5 `payment_failed` — proof rejected, correction window

- Side effect: `correction_deadline = NOW() + shop_correction_window_hours`
  (default 24 h).
- Buyer panel: "Payment needs correction" + the rejection reason + "Re-upload
  by <deadline>."
- Reservation: released back to stock while the window runs (S39); a re-upload
  re-reserves (or refuses, §9.2).
- Exits: re-upload → `payment_review`; buyer cancel → `cancelled`; timer past
  `correction_deadline` → `cancelled` (`payment_expired`); admin hold/cancel.
- Deadline reading quirk is handled by `EXPIRY_DEADLINE_SQL`: for
  `payment_failed` the **correction** deadline governs (defect H3 fix).

### 9.6 `packing` → `ready_to_ship` — warehouse

- `paid → packing` (`startPacking`), admin; `packing → ready_to_ship`
  (`finishPacking`), admin — guard: order still has ≥1 line.
- Buyer sees the shared "preparing your order" panel; no actions.
- Re-entry points: reship (`returned_to_sender → packing`) and hold resume can
  land back here.

### 9.7 `shipped` — courier handoff (**the one stock deduction**)

- Admin posts courier + tracking (+ optional expected date) →
  `ShopOrder::ship()`: upserts the active shipment (idempotent by order+seq),
  then `ready_to_ship → shipped`.
- Side effects: per-line conditional `products.stock -= qty`
  (`WHERE stock >= qty`); any short line throws `StockConflictException` →
  **transition refused, everything rolled back**, and a no-op audit row with
  reason `stock_conflict` appears in the timeline. Lines already deducted
  (reship of returned goods) are only re-stamped — stock deducted **exactly
  once** ever.
- `handoff_at` stamped on the shipment; event note "courier · tracking".
- Buyer panel switches to tracking view: courier, tracking number, expected
  delivery date.

### 9.8 `out_for_delivery`

- From `shipped` or `delivery_failed` (admin or system/timer). From
  `delivery_failed` the attempt cap applies (§9.9).
- Buyer sees courier/tracking panel; `out_for_delivery_at` stamped.

### 9.9 `delivery_failed` — attempt failed

- Admin records fault (`customer`/`carrier`/`shop`) + reason; increments the
  shipment's `attempts`. Cap = `shop_max_delivery_attempts` (default 3): at
  cap the transition to `delivery_failed` is refused — only RTS or delivered
  remain.
- Side effect at cap: `response_deadline = now + 10 days`
  (`RTS_RESPONSE_DAYS`).
- Buyer panel: "A delivery attempt failed — Reason… Attempt N of M. Please
  make sure someone is available." No buyer buttons; keeping reachable is the
  implicit ask.
- Exits: `out_for_delivery` (retry, while under cap),
  `returned_to_sender`, or `delivered`.

### 9.10 `returned_to_sender`

- From `shipped`/`out_for_delivery`/`delivery_failed` (admin or system).
- Side effects: active shipment closed, `response_deadline = now + 10 days`.
- Buyer panel: "The parcel is coming back to us — we'll contact you about
  reshipping. Response window closes <date>."
- Resolution is the admin's: **reship** (`ShopOrder::reship()` — v1 allows one
  reship max, `seq > 2` refused; goods reused, stock never re-added, lines back
  to `reserved`, shop absorbs cost, no fee, new shipment linked via
  `reship_of`) → back to `packing`; or admin cancel → `cancelled` (refund may
  open).

### 9.11 `delivered`

- Admin marks delivered; **POD is mandatory** — receiver name or POD photo
  (missing both throws a validation error, not a silent failure). POD is
  written inside the transition's transaction (M4) onto the active shipment.
- Side effect: `completion_due_at = NOW() + shop_report_window_days`
  (default **5 days**); shipment gets `report_window_end`.
- Buyer panel: "Delivered — Received by <name>. Confirm receipt by <date> —
  confirming ends the reporting window."
- Buyer action: **Confirm receipt** (`?page=shop_confirm_receipt`) — modal
  confirm, then `delivered → completed` if no open/approved refund
  (`ShopRefund::hasOpen` blocks it, T24).
- Auto-close: if the window lapses with no open refund, the cron completes it
  for them (`ShopOrder::autoComplete`, source `timer`).

### 9.12 `completed` — terminal

- Entered by buyer confirm or timer. `completed_by`/`completed_at` stamped.
- Buyer panel: "Order complete — thank you for shopping with us. This order is
  closed." Invoice remains printable.

### 9.13 `cancelled` — terminal

Buyer-side paths:

- **Buyer cancel** (`shop_cancel_order`, from `pending` or `payment_failed`
  only): modal confirm ("This cannot be undone"; copy notes e-wallet refunds
  are automatic for `payment_method = 'ewallet'`). Stock released
  (`reserved → released`) so availability recovers immediately; invoice voided
  (`order_cancelled`).
- **Timer:** `pending`/`payment_failed` past their deadline → `cancelled`
  reason `payment_expired`, same release.
- **Admin** may cancel from any pre-handoff state
  (`pending, payment_review, payment_failed, paid, packing, ready_to_ship,
  on_hold, returned_to_sender`); when the source is
  `paid/packing/ready_to_ship/on_hold/returned_to_sender` a **refund task is
  auto-created** for the full total (cause `admin_cancel`).

Buyer panel shows the reason plus any refund task's amount/status.

### 9.14 `on_hold` — paused (admin decision, never a timer)

- From `pending … ready_to_ship`, `returned_to_sender`; admin only; reason,
  staff owner and deadline all mandatory (no owner-less limbo).
- `previous_status` frozen as resume target. Buyer panel: "Order on hold —
  reason… We'll update you by <deadline>." Resume re-validates the frozen
  target against the matrix; hold fields cleared on any exit.
- Stale holds are **not** expired by cron — overdue holds only surface as a
  log warning for staff (`cron/shop_expiry.php:112`).

---

## 10. Refunds (buyer view)

- Task lifecycle: `open → approved → processed` (or `denied`); e-wallet method
  credits the wallet through the ledger inside `markPaid()`, receipt ref =
  credit-note no `CN-<order_no>-<id>` issued at approval; other methods settle
  out of band.
- Open (`open`/`approved`) refunds **block** `delivered → completed` (T24).
- Buyer sees refund rows (amount + status) on the order page; cancelled orders
  show "A refund of ₱X is being processed (status: …)".

## 11. Timers & settings affecting the buyer

Cron `cron/shop_expiry.php` every 15 min (manual button `admin_shop_expire`):

1. `expireOverdue(200)` — cancels overdue `pending`/`payment_failed` (never
   `payment_review`); events `source='timer'`, `actor_type='system'`.
2. `autoComplete(200)` — completes `delivered` past `completion_due_at` with no
   open refund.
3. Overdue holds → log warning only.

Settings (admin, `AdminController:531`): `shop_enabled`,
`shop_payment_deadline_hours` (24), `shop_correction_window_hours` (24),
`shop_max_proof_attempts` (3), `shop_report_window_days` (5),
`shop_max_delivery_attempts` (3). Plus `free_registration_enabled` (§1).

## 12. Routes quick reference (buyer)

| Route | Handler | Purpose |
|---|---|---|
| `register` / `do_register` | `AuthController` | free registration → `pending` account (§1) |
| `login` / `do_login` | `AuthController` | sign in (returning members) |
| `shop` | `MemberController::shop` | storefront |
| `add_to_cart` | `addToCart` | add (AJAX/POST) |
| `cart`, `update_cart_item`, `remove_cart_item` | cart ops | manage cart |
| `checkout` | `checkout` | address + payment form |
| `place_order` | `placeOrder` | create order (+ e-wallet pay) |
| `shop_submit_proof` | `submitShopProof` | upload/re-upload proof |
| `shop_cancel_order` | `cancelShopOrder` | cancel (pending/payment_failed) |
| `shop_confirm_receipt` | `confirmShopReceipt` | delivered → completed |
| `shop_orders` | `shopOrders` | my orders list |
| `shop_order` | `shopOrder` | order detail + actions |
| `shop_invoice` | `shopInvoice` | printable invoice |

## 13. Happy-path summary

```
register (free, requires sponsor) ──→ account: pending ──→ auto-login ──┐
                                                                        │
login (returning member) ───────────────────────────────────────────────┤
                                                                        ↓
                     shop → add_to_cart → cart → checkout → place_order
                        ├─ ewallet:  order pending ─(debit+auto-verify)→ paid
                        └─ manual:   order pending → payment_review ─(admin verifies)→ paid
                                             └─(reject)→ payment_failed →(re-upload)→ payment_review
paid → packing → ready_to_ship → shipped → out_for_delivery → delivered
                                                    ↓ (POD; attempts may loop
                                                    ↓  delivery_failed ⇄ OFD)
delivered →(buyer confirms | cron auto-completes)→ completed
```

Terminal states: `completed`, `cancelled`. Stock: reserved at placement →
committed at `paid` → deducted once at `shipped` → released on
`cancelled`/`payment_failed`. Account status `pending` (§1) is sufficient for
the entire journey; activation to `active` only unlocks commissions and a
funded e-wallet, never buying.
