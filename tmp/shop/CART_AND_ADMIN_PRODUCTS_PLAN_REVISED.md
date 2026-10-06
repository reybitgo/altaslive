# Implementation Plan — Shop Storefront: Products, Cart, Checkout & Full Order Lifecycle (sync tmp/altas → altaslive)

| Field | Value |
|---|---|
| **Target** | `C:\laragon\www\altaslive` (the live app: routes, models, controllers, views, migrations) |
| **Source of truth (richer impl.)** | `C:\laragon\www\altaslive\tmp\altas` (PV-era branch with cart/checkout/products) |
| **Workflow spec** | `tmp/order_workflow/OrderWorkflowGuideCheckouttoCompletion.md` (committed `0b38a77`) — the T1–T24 rules this plan implements |
| **Scope** | Product catalog (admin CRUD), member **and admin** storefront (`shop`), cart page, checkout, **14-status order lifecycle**, admin fulfilment desk |
| **Strategy** | **(C) Pure storefront — product orders pay ZERO compensation** (no binary PV, no product unilevel, no royalty) |
| **Order model** | **14-status industry state machine** (§0.5) with one transition handler, append-only event log, shipment records, holds, deadlines |
| **Stock model** | **Reservation** — `products.stock` is absolute inventory; availability = `stock − Σ(lines in reservation_state reserved/committed)`; deducted exactly once at courier handoff |
| **Fees** | **None.** No shipping fee, no service fee, no reship fee, no payment tolerance. `total_price` is the only money figure on an order. |
| **Naming** | "Repeat Purchases" is retired. Everything is **`shop`** (`shop`, `cart`, `checkout`, `shop_orders`, `admin_shop`, `admin_shop_orders`) |
| **Access** | **Admins can shop.** Shop routes stay `'member'` role (`Auth::guard('member')` admits admins — verified `core/Auth.php:233`). Admin self-review of their own order is refused. |
| **Stack** | PHP 8.1+, MySQL 8, `?page=` router in `index.php`, no framework / no test suite / no linter |
| **Hard constraint** | The commission engine must be **byte-identical** — `git diff core/Commission.php` must be empty (see §7) |
| **Document date** | 2026-10-06 (revision 3 — rev 2's 5-state flow replaced by the full 14-status machine) |

> **What changed from revision 2**
> 1. `shop_orders.status` goes from a 5-value ENUM to the **14-value** industry ENUM (§0.5, §2.3).
> 2. `rejected` and `approved` are **gone**. `rejected` is split into `payment_review` (proof under review) → `payment_failed` (proof rejected, correction window open) → back to `payment_review`, or → `cancelled`. `approved` becomes `paid` (money verified) and the fulfilment chain `packing → ready_to_ship → shipped → out_for_delivery → delivered → completed`.
> 3. **Four new tables**: `shop_order_events`, `shop_shipments`, `shop_payment_proofs`, `shop_refunds` (plus `shop_orders` / `shop_order_items` / `products` widened).
> 4. `reviewed_by` is **split** into `paid_by` / `approved_by` / `packed_by` / `shipped_by` / `delivered_by` / `completed_by` / `cancelled_by`, each with its own `*_at`.
> 5. New behaviours: **holds** (`on_hold` + `previous_status` resume target), **deadlines** (`payment_deadline`, `correction_deadline`, `completion_due_at`, `hold_deadline`, shipment `response_deadline`), **customer cancellation**, **Confirm receipt**, **reship**, **delivery failure → returned_to_sender**, and an **expiry cron hook**.
> 6. `product_pv` / `pv_value` are now **kept as inert columns** (present, defaulted, never read or written) instead of omitted.
> 7. **Admins can shop** — rev 2 hid the cart affordance from admins; that is reversed.
> 8. **No fees** is now an explicit rule (§0.4), which also removes the reship-fee / tolerance-write-off columns the workflow guide would otherwise add.

---

## 0. Executive summary — read this before touching code

The two trees are **not** the same codebase generation:

* `tmp/altas` is a **PV-native** engine: `packages.package_pv_rate`, `binary_pv_pct`, `pairing_pv_pct`,
  `direct_ref_pv_pct`, `dfi_pv_pct`, `users.{left_pv,right_pv,paired_pv,personal_pv,group_pv,flushed_pv}`,
  `pv_transactions`, `Package::packagePv()`, and a `setting('pv_per_peso_rate')` = 1000.0000 converter.
* `altaslive` is the **legacy peso/count** engine: `packages.{pairing_bonus, daily_pair_cap, direct_ref_bonus}`,
  `users.{left_count_paid, right_count_paid, left_pair_volume, right_pair_volume, pairs_volume_paid,
  pairs_volume_flushed, pairs_volume_today}`, no PV columns anywhere, no `pv_transactions`, no
  `pv_per_peso_rate`. Pairing pays **₱ directly**, not PV×rate.

Therefore **do NOT copy `tmp/altas/core/Commission.php`, `tmp/altas/models/Package.php`, or
`tmp/altas/install.sql` wholesale.**

### 0.1 Chosen strategy — **(C) Pure storefront, zero product compensation**

The MLM compensation plan is **unchanged**. A shop order is a pure retail transaction: it moves money, it
reserves and then deducts inventory, and it produces **no PV, no binary volume, no unilevel bonus, no
royalty, no CD fill, and no cap accounting of any kind**. This is the lowest-risk scope available because it
requires **zero edits to the engine**.

| tmp/altas concept | Decision in this plan |
|---|---|
| `Commission::processProductPV()` | **Not ported** |
| `Commission::processBinaryVolume()` / `applyBinaryVolume()` | **Not ported** |
| `Commission::processProductUnilevel()` | **Not ported** (option 2 skipped) |
| `Royalty::processRepeatPurchase()` + `royalty_pool` | **Not ported** (option 3 skipped) |
| `Commission::meetsPersonalPvRequirement()` | **Not ported** |
| `products.product_pv`, `products.pv_value` | **Ported INERT** — columns exist, default `0.00`, never read, never written, never rendered (§0.2) |
| `shop_orders.total_pv`, `shop_order_items.unit_pv` | **Ported INERT** — same rule |
| `product_unilevel_levels` (10 levels) | **Not ported** |
| `packages.personal_pv_requirement` | **Not ported** (no PV gate exists) |
| `users.personal_pv`, `users.group_pv` | **Not ported** |
| `setting('pv_per_peso_rate')` | **Not ported** — no bridge of any kind |
| `settings.binary_repeat_enabled`, `settings.unilevel_product_enabled` | **Not ported** |
| `commissions.type` ENUM `'unilevel_product'` | **Not ported** — ENUM untouched |
| `cd_ledger.type` ENUM `'unilevel_product'` | **Not ported** — ENUM untouched |
| `pv_transactions` audit table | **Not ported** |
| `pv_per_peso_rate` "≈ ₱" admin preview | **Deleted** |
| `repeat_purchase_orders` / `repeat_purchase_order_items` | **Renamed** → `shop_orders` / `shop_order_items` |
| Membership "Repeat Purchases" nav/page | **Renamed** → Shop (§3.3, §5.4) |
| — | **New:** `settings.shop_enabled` — master on/off switch for the storefront |
| — | **New:** 5 lifecycle settings (§2.2) driving the deadlines/SLAs |
| — | **New:** `shop_order_events`, `shop_shipments`, `shop_payment_proofs`, `shop_refunds` |

Everything that survives is a straight port with the adaptation notes in §3.

**Consequence for the engine:** `core/Commission.php`, `core/CapEngine.php`, `models/Package.php`,
`models/CdStatus.php`, `core/DailyFixedIncome.php`, `core/Reactivation.php`, and `install.sql`'s
`commissions`/`cd_ledger`/`users`/`packages` blocks all stay **byte-identical**. `ShopOrder::transition()`
never calls into `Commission` — a fulfilment transition is a pure status write plus a stock/ledger write.
That is the whole point of strategy C.

### 0.2 Inert PV columns — exact rule

`product_pv`, `pv_value`, `shop_orders.total_pv`, `shop_order_items.unit_pv` are created so the schema
stays forward-compatible, and are governed by four hard rules:

1. **No PHP file may contain the strings** `product_pv`, `pv_value`, `total_pv`, `unit_pv`. Not in a
   SELECT, not in an INSERT, not in a comment. They exist **only** in `migrate_shop.sql` and `install.sql`
   as DDL, each carrying `COMMENT 'INERT ...'`.
2. `Product::save()` must **not** accept a `product_pv` / `pv_value` key — its field list is explicit, so
   an unrecognised POST key is dropped silently. No form input exists for them.
3. `ShopOrder::createFromCart()` does **not** insert `total_pv`; the `shop_order_items` INSERT omits
   `unit_pv`. Both columns therefore stay at their `DEFAULT 0.00` forever.
4. Verification greps (§6 V3) must return **0 hits** across `core/`, `models/`, `controllers/`, `views/`.
   A hit means someone wired a PV value in — treat it as a Critical defect (§9).

### 0.3 Stock — reservation model, with a single deduction point

Rev 2's reservation model is kept. The lifecycle gets one new, explicit step: the deduction now happens
**at courier handoff** instead of "manually, by hand, whenever".

| Fact | Rule |
|---|---|
| `products.stock` | **Absolute physical inventory.** Set by admin. Mutated by exactly one code path: the handoff transition `ready_to_ship → shipped` (§0.5 T13). |
| `shop_order_items.reservation_state` | The single source of truth for availability: `reserved` → `committed` → `deducted` → (`released` \| `written_off`). |
| `Product::reservedStock($id)` | `SUM(quantity)` where `reservation_state IN ('reserved','committed')`. |
| `Product::availableStock($id)` | `max(0, stock − reservedStock($id))` |
| Why `reservation_state` and not a status IN-list | A 14-value ENUM makes a status filter a moving target — every future status means auditing the list. The reservation state is explicit, and `ShopOrder::transition()` is the only writer, so it cannot drift. A status IN-list is kept in the SQL as a **defensive secondary filter** (§5.2). |
| Deduction at T13 | `UPDATE products SET stock = stock - :qty WHERE id = :id AND stock >= :qty`, per line, inside the transition transaction. Zero rows affected → the transition is **refused** and an audit event records `stock_conflict`. |
| `cancelled` | Sets every non-terminal line to `released` → availability rises immediately. |
| `returned_to_sender` | Lines stay `deducted` (goods are physically back but **not yet sellable**). Restock or reuse is an explicit admin action: a restock sets `released`; a reship re-enters `packing` and flips the line back to `reserved` against the (unchanged) `stock`. |
| `completed` | Lines stay `deducted`. Terminal. |
| Oversell window | Two checkouts can both pass `validateStock()` before either order exists. **Narrowed, not closed**: the decisive guard is the `stock >= qty` conditional at T13, and the availability read is advisory. Accepted residual risk, unchanged from rev 2. |

`reservation_state` values: `reserved`, `committed`, `deducted`, `released`, `written_off`.
**There is no `restocked` state** — a restocked line is `released` (available again), full stop.

### 0.4 Fees — none

| Would-be fee | Decision |
|---|---|
| Shipping / courier fee | **None.** No column. `total_price = Σ(unit_price × qty)` and nothing else. |
| Service fee / handling fee | **None.** (Contrast `payout_requests.service_fee_pct` / `service_fee_amount`, `install.sql:188–189` — **do not** copy that pattern here.) |
| Payment-method fee | **None.** No GCash/Maya/USDT fee on a shop order. |
| Reship fee | **None collected.** The workflow guide charges a reship fee when a delivery failure is the customer's fault (its §9.4). Here the shop absorbs it: a reship creates a new shipment with `reship_of = <old id>` and **no** payment record, because there is no fee to verify. |
| Payment tolerance | **None.** T5 requires `amount_sent === total_price` exactly. No write-off column, no manager threshold. |
| Tax | **None.** No `tax_pct` column. |

The only money columns on a shop order are `total_price` (orders), `unit_price` / `total_price` (lines),
`amount` (refunds), and `amount_sent` (proofs — a *claim*, compared against `total_price`, never charged from).

### 0.5 The 14-status order machine

Status values, in lifecycle order, with the workflow-guide transition that enters each:

| # | Status | Meaning | Entered by | Waiting on | Holds stock? |
|---|---|---|---|---|---|
| 1 | `pending` | Order placed, stock reserved, awaiting payment | T1 (customer) | Customer, before `payment_deadline` | **yes** (`reserved`) |
| 2 | `payment_review` | Proof submitted, awaiting verification | T2 (customer) | Admin, within review SLA | **yes** |
| 3 | `payment_failed` | Proof rejected; correction window open | T3 (admin) | Customer: re-upload or cancel | **yes** |
| 4 | `paid` | Payment verified; reservation committed | T5 (admin) / e-wallet auto | Warehouse | **yes** (`committed`) |
| 5 | `packing` | Being picked and packed | T9 (admin) | Warehouse | **yes** |
| 6 | `ready_to_ship` | Packed, labelled, awaiting courier pickup | T10 (admin) | Courier | **yes** |
| 7 | `shipped` | Handed to carrier; **stock deducted** | T13 (admin) | Courier | no (`deducted`) |
| 8 | `out_for_delivery` | Courier delivering today | T14 (admin) | Carrier | no |
| 9 | `delivery_failed` | An attempt failed; fault recorded | T16 (admin) | Customer + staff | no |
| 10 | `returned_to_sender` | Parcel returning / back at shop | T18 (admin) | Customer decides on reship | no |
| 11 | `delivered` | POD stored; report window open | T15 (admin) / auto | Customer or timer | no |
| 12 | `completed` | Closed, read-only — **terminal** | T24 (customer / system) | nobody | no |
| 13 | `cancelled` | Closed without delivery — **terminal** | T6 customer / T7 timer / T8 admin | nobody | no (`released`) |
| 14 | `on_hold` | Paused: reason + owner + deadline; `previous_status` = resume target | T11 (admin) | Named owner | **yes** (follows `previous_status`) |

**Migration map from rev 2 / tmp** (needed only if rev-2 rows ever exist):

| Old value | New value |
|---|---|
| `pending` | `pending` (unchanged) |
| `paid` (marked by admin) | `payment_review` |
| `approved` | `paid` |
| `rejected` | `payment_failed` |
| `cancelled` | `cancelled` (unchanged) |

Since the shop does not exist in production yet, `migrate_shop.sql` ships the 14-value ENUM directly and
this map is documentation only (§2.5 gives the `ALTER … MODIFY` for the case where rev 2 ran first).

**Transition matrix.** This is the single source of truth, encoded as a const in `models/ShopOrder.php`
(§3.2) and enforced by `ShopOrder::transition()`. **No code path writes `status` directly.**

| To | Allowed from | Actor | Key guards |
|---|---|---|---|
| `payment_review` | `pending`, `payment_failed` | customer | attempts < `shop_max_proof_attempts`; before `payment_deadline` (from `pending`) or before `correction_deadline` (from `payment_failed`); valid file |
| `payment_failed` | `payment_review` | admin | reason code + amount received; self-review refused |
| `paid` | `payment_review`, `pending` | admin, system | reference unused by another order; `amount_sent = total_price`; the `pending → paid` leg is **e-wallet only** |
| `cancelled` | `pending`, `payment_failed` | customer | owner only; same conditional write as the proof upload, so that race has exactly one winner |
| `cancelled` | `pending`, `payment_failed` | system | deadline passed (T7); **never** from `payment_review` |
| `cancelled` | `pending`, `payment_review`, `payment_failed`, `paid`, `packing`, `ready_to_ship`, `on_hold`, `returned_to_sender` | admin | shop-side reason code (never "customer request"); no refund settled without a receipt |
| `on_hold` | any pre-handoff state listed above minus `cancelled` | admin | reason + owner + deadline; `previous_status` frozen |
| `packing` | `paid`, `ready_to_ship`, `returned_to_sender`, `delivered`, `on_hold` | admin | on `on_hold`, `previous_status` must be one of these |
| `ready_to_ship` | `packing` | admin | every line present |
| `shipped` | `ready_to_ship` | admin | courier + tracking set; **per-line `stock >= qty` deduction succeeds** |
| `out_for_delivery` | `shipped`, `delivery_failed` | admin, system | `attempts < shop_max_delivery_attempts` on the retry leg |
| `delivery_failed` | `shipped`, `out_for_delivery` | admin, system | fail reason + `fault ∈ {customer, carrier, shop}`; `attempts` +1 |
| `returned_to_sender` | `shipped`, `out_for_delivery`, `delivery_failed` | admin, system | attempts exhausted, or intercept confirmed; closes the active shipment |
| `delivered` | `shipped`, `out_for_delivery`, `delivery_failed` | admin, system | POD stored (receiver or `pod_image`); sets `completion_due_at = NOW() + shop_report_window_days` |
| `completed` | `delivered` | customer, system | no `shop_refunds` row in state `open`/`approved`; self-confirm ends reporting |
| *(revert)* `payment_review` | `paid` | admin | only before `packing`; reason code; the customer's cancel stays closed |

**`on_hold` resume** is not a transition: `ShopOrder::resume()` writes `status = previous_status`,
clears the hold fields, then re-validates the resume target against the matrix. A hold whose deadline passes
with no resolution is closed by an admin as `cancelled` with reason `customer_unreachable` (T8) — the
timer never auto-resumes.

**Why `delivered` is not terminal.** Nothing irreversible attaches to it: stock was deducted at `shipped`,
so a reship can pull the order back to `packing` without undoing money or inventory. Only `completed` and
`cancelled` are terminal.

### 0.6 Deliberate omissions from the workflow guide

The guide is a complete commerce spec. These parts are **not** in this plan, and each omission is a table
or column that does not get created:

| Guide feature | Omitted because | Where it would attach |
|---|---|---|
| Problem reports (T22, T23) | Needs its own table, SLA queue, and remedy policy; out of scope for v1 | `shop_reports` + an admin `delivered → packing` action |
| `stock_reservations` table | Per-line `reservation_state` on `shop_order_items` carries the same facts | already covered (§0.3) |
| `awaiting_funds` payment state | Proof rows exist but with a 3-value `outcome`; the funds-recheck state is dropped | `shop_payment_proofs.outcome` + one enum value |
| `shipment_events` table / carrier webhooks | No courier integration exists; every transition is a manual admin entry | a new table + an `api_carrier_webhook` route |
| `reason_codes` table | Reason codes are a fixed const map inside `ShopOrder` | a settings-backed table later |
| `notification_outbox` | No mail/SMS sender exists in this codebase | a `ShopNotice` service |
| `reconciliation_tasks` | Needs a bank-statement feed | a new table + a daily report |
| Roles/permissions matrix (guide §11.6) | Live has exactly three roles (`users.role` ENUM `member`, `admin`, `superadmin` — `install.sql:47`); the guide's 6 roles do not map | `users.role` expansion |
| Idempotency-key DB uniqueness, webhooks, signed URLs | No external API surface in v1 | shop phase 2 |
| `out_for_delivery` | **Kept.** If the chosen courier never reports it, drop the value from the ENUM and drop T14 — it is one `ALTER` and one matrix row | §2.4 |

---

## 1. Current state audit

### 1.1 Already present in `altaslive` (do not recreate)

| Area | Existing |
|---|---|
| Models | `CdStatus.php`, `Code.php`, `Ewallet.php`, `ImpLog.php`, `Package.php`, `Payout.php`, `User.php` |
| Core | `Auth.php`, `CapEngine.php`, `Commission.php`, `DailyFixedIncome.php`, `Reactivation.php`, `helpers.php` |
| Helpers | `e fmt_money fmt_short fmt_date fmt_datetime redirect link_to flash render_flash csrf_* setting paginate pagination_links json_response rate_limit_* mask_account getUserById is_page current_page is_imp_session seatsRemaining` (`core/helpers.php`, **456 lines**) |
| Money movement | `Ewallet::debitInternal(int $userId, float $amount, int $refId, string $refType, string $note = ''): bool` — `models/Ewallet.php:98`. Spends **non-withdrawable funds first**, then withdrawable. Returns `false` on insufficient balance. Used by `User.php:38`, `User.php:421`, `Reactivation.php:169`, `MemberController.php:610`. **This is the pattern a shop purchase must follow.** |
| Assets | `assets/js/app.js` — exports `showToast` (12), `showConfirm(opts)` (39), `confirmSubmit` (87), `copyText` (117) |
| Views | `views/partials/{head,footer,topbar,sidebar_member,sidebar_admin,settings_offcanvas}.php` + full member/admin page set |
| Migrations | `migrate_deactivated_status.sql`, `migrate_package_image.sql`, `migrate_package_toggles.sql`, `migrate_paid_leg_counts.sql`, `migrate_pair_volume.sql`, `migrate_superadmin.sql`, `migrate_usdt_bep20.sql` |
| Upload infra | `uploads/packages/`, `uploads/reactivation_proofs/` dirs; inline upload code in `AdminController::savePackage()` (lines 377–416) and `core/Reactivation.php` |
| Manual-cron precedent | `?page=admin_manual_reset` (`index.php:331`) + `cron/midnight_reset.php` — the exact pattern Phase 6 reuses for the expiry job |
| Extras not in tmp/altas | Super-Login impersonation (`imp=` URL token), `link_to()`, seat limit, free registration, `upgrade` flow, CD bucket, `CapEngine`, `withdrawable_balance` |

### 1.2 Missing in `altaslive` (must be added)

| Layer | Missing artifacts |
|---|---|
| **Models** | `models/Product.php`, `models/Cart.php`, `models/ShopOrder.php`, `models/ShopOrderEvent.php`, `models/ShopShipment.php`, `models/ShopPaymentProof.php`, `models/ShopRefund.php` |
| **Controllers** | `MemberController::{shop, shopOrders, shopOrder, cart, addToCart, updateCartItem, removeCartItem, checkout, placeOrder, submitShopProof, cancelShopOrder, confirmShopReceipt}`; `AdminController::{shop, saveProduct, deleteProduct, shopOrders, shopOrder, orderTransition, orderHold, orderResume, orderProofView, orderShipment, orderReship, orderRefund, shopExpire}` |
| **Views** | `views/member/{shop,shop_orders,shop_order,cart,checkout}.php`, `views/admin/{shop,shop_orders,shop_order}.php`, `views/partials/{cart_offcanvas,rows_per_page,order_status_badge,shop_order_timeline}.php` |
| **Routes** | 12 member + 13 admin `?page=` entries (§3.3) |
| **Helpers** | `per_page()`, `upload_image()`, `delete_uploaded_file()`, `shop_status_label()`, `shop_status_tone()`, `shop_status_badge()`, `shop_public_order_no()` — **none of these exist** (see §1.3) |
| **Schema** | `products`, `carts`, `cart_items`, `shop_orders`, `shop_order_items`, `shop_order_events`, `shop_shipments`, `shop_payment_proofs`, `shop_refunds`; `ewallet_ledger.ref_type` ENUM gains `'shop_order'`; 6 new settings keys |
| **Nav** | Member sidebar entries (Shop / My Orders / Cart w/ badge), admin sidebar section (Products / Orders w/ badge), topbar 🛒 button + badge — **all shown to admins too** |
| **Uploads** | `uploads/products/`, `uploads/shop_proofs/`, `uploads/shipments/` (created on demand by `upload_image()` / `mkdir`) |
| **Cron** | `cron/shop_expiry.php` (new file; `cron/midnight_reset.php` stays untouched) |
| **Engine** | **Nothing.** `core/Commission.php` and friends are untouched. |

### 1.3 Corrections to the assumed brief (verified against live source)

| Assumption | Reality in `altaslive` |
|---|---|
| "`upload_image` helper exists in altaslive" | ❌ **It does not.** Upload code is inline in `AdminController::savePackage()` (377–416) and `core/Reactivation.php`. `upload_image()` / `delete_uploaded_file()` must be **added** to `core/helpers.php` (Phase 3.1). |
| "`per_page()` exists" | ❌ Does not exist. Must be added (Phase 3.1). |
| "`paginate` signature / `per_page`" | `paginate(string $query, array $params, int $page, int $perPage = 20)` at `core/helpers.php:350` — default is **20** in live, **10** in tmp. Every live call site passes `$perPage` explicitly, so the default is safe; **keep 20** and add `per_page(int $default = 10, int $min = 5)` for new code only. |
| "E-wallet can be debited with an arbitrary `ref_type`" | ❌ **Not quite.** `Ewallet::debit()` validates against a fixed list (`models/Ewallet.php:60`, throws at `:62`) and `ewallet_ledger.ref_type` is a **MySQL ENUM** (`install.sql:147`). Using `'shop_order'` therefore requires an ENUM migration (Phase 1.1 step 10). `Ewallet::debitInternal()` (`models/Ewallet.php:98`) does **not** validate the string, but the ENUM still rejects it. |
| "Admin can charge the member with a raw `UPDATE users SET ewallet_balance …`" | ❌ tmp's `placeOrder()` does exactly that, which leaves `withdrawable_balance` inflated (member could withdraw money they already spent). Live's own convention for member-paid fees is `Ewallet::debitInternal()`. **Use `debitInternal()`.** |
| "tmp/altas helpers available in live" | tmp/altas has **no** `link_to()`, **no** impersonation. Live has both. All ported **member** URLs must be rebuilt with `link_to()` (§5.7). Live's `views/partials/sidebar_admin.php` itself hardcodes `APP_URL . '/?page=…'` — follow that existing convention for admin nav rather than introducing `link_to()` there (§5.5). |
| "Duplicate DOM IDs" | Confirmed — tmp has 6 collisions between `cart.php` and `cart_offcanvas.php`; 5 survive once `cartTotalPv` is deleted (§5.1). |
| "`?page=cart` will be reachable by admins" | Yes, and **that is now the intent**. `Auth::guard('member')` (`core/Auth.php:233`) only requires a logged-in session — it does **not** block admins (`Auth::isAdmin()` is a separate check at `:141`). Rev 2 hid the cart button from admins; revision 3 shows it to everyone (§3.3 Phase 5.3, §5.8). |
| "Admin insert point in `AdminController.php` is line 429" | ❌ Off by 8. `savePackage()` closes at **line 421** (`}`), blank 422, `// ── Registration Codes ──` banner at **423**. Insert between 421 and 423. |
| "`shop_orders` needs only 5 statuses" | ❌ Superseded — 14 values, §0.5. |

---

## 2. Schema

### 2.1 Objects present in tmp/altas, absent in altaslive

| Object | tmp/altas source | Live equivalent | Decision |
|---|---|---|---|
| `products` | `tmp/altas/install.sql:63–76`; migrations 021, 024, 025, 029 | ❌ none | **Port, with `product_pv` + `pv_value` kept INERT** (§0.2); add `sku` (optional) |
| `carts` / `cart_items` | `tmp/altas/install.sql:79–101`; migration 027 | ❌ none | **Port, with `cart_items.unit_pv` kept INERT** |
| `repeat_purchase_orders` / `_order_items` | `tmp/altas/install.sql:104–133`; migration 027 | ❌ none | **Port as `shop_orders` / `shop_order_items`, widened to the 14-status shape** |
| `product_unilevel_levels` | `tmp/altas/install.sql:52–60`; migration 030 | ❌ none | **Not ported** |
| `pv_transactions` | `tmp/altas/install.sql:242–263` | ❌ none | **Not ported** |
| `royalty_pool` + `core/Royalty.php` | tmp only | ❌ none | **Not ported** |
| `packages.package_pv_rate / binary_pv_pct / pairing_pv_pct / daily_pair_pv_cap / direct_ref_pv_pct / dfi_pv_pct` | tmp `install.sql:10–36` | ❌ live uses `pairing_bonus` / `daily_pair_cap` / `direct_ref_bonus` / `daily_fixed_income` | **Not ported — keep live columns** |
| `packages.personal_pv_requirement` | tmp migration 027 step 2 | ❌ none | **Not ported** |
| `users.{personal_pv, group_pv, left_pv, right_pv, paired_pv, flushed_pv}` | tmp `install.sql:56–64` | live has volume columns instead | **Not ported** |
| `package_indirect_levels.pv_pct` | tmp | ❌ live uses `bonus` (₱) | **Not ported — keep live** |
| — | ❌ | ❌ | **New:** `shop_order_events`, `shop_shipments`, `shop_payment_proofs`, `shop_refunds` |

### 2.2 Enum / settings drift

| Enum / setting | tmp/altas | altaslive today | Action |
|---|---|---|---|
| `commissions.type` | `…, unilevel_product, royalty` | `pairing, direct_referral, indirect_referral, daily_fixed_income` (`install.sql:124`) | **NO CHANGE** |
| `cd_ledger.type` | `…, unilevel_product` | `pairing, direct_referral, indirect_referral` | **NO CHANGE** |
| `ewallet_ledger.ref_type` | `commission, payout, reactivation, transfer, topup, registration` | same (`install.sql:147`) | **add `'shop_order'`** — required, the ENUM rejects anything else |
| `shop_orders.payment_method` | `ewallet, gcash, maya, usdt_trc20, usdt_bep20` | n/a | matches live `payout_requests.payout_method` / `reactivations.payment_method` — as-is |
| `shop_orders.status` | `pending, paid, approved, rejected, cancelled` | n/a | **REPLACED** → 14 values (§0.5, §2.3) |
| `shop_order_items.reservation_state` | ❌ | ❌ | **new ENUM** `reserved, committed, deducted, released, written_off` |
| `shop_order_events.actor_type` | ❌ | ❌ | **new ENUM** `customer, admin, system, carrier` |
| `shop_order_events.source` | ❌ | ❌ | **new ENUM** `ui, api, timer, carrier, system` |
| `shop_shipments.state` | ❌ | ❌ | **new ENUM** `active, closed, lost` |
| `shop_shipments.fault` | ❌ | ❌ | **new ENUM** `customer, carrier, shop` (nullable) |
| `shop_payment_proofs.outcome` | ❌ | ❌ | **new ENUM** `pending, verified, rejected` |
| `shop_refunds.state` | ❌ | ❌ | **new ENUM** `open, approved, paid, cancelled` |
| `users.status` | `active, suspended, pending` | `active, suspended, pending, deactivated` | **keep live** |
| `users.role` | ❌ | `member, admin, superadmin` | **keep live** (admins can shop as buyers) |
| `settings.shop_enabled` | ❌ | ❌ | **add, seed `'1'`** |
| `settings.shop_payment_deadline_hours` | ❌ | ❌ | **add, seed `'24'`** |
| `settings.shop_correction_window_hours` | ❌ | ❌ | **add, seed `'24'`** |
| `settings.shop_max_proof_attempts` | ❌ | ❌ | **add, seed `'3'`** |
| `settings.shop_report_window_days` | ❌ | ❌ | **add, seed `'5'`** |
| `settings.shop_max_delivery_attempts` | ❌ | ❌ | **add, seed `'3'`** |
| `settings.binary_repeat_enabled` | `'1'` | ❌ | **do not add** |
| `settings.unilevel_product_enabled` | `'1'` | ❌ | **do not add** |
| `settings.binary_enabled` | `'1'` | ❌ (gating is `Package::hasPairing()` + `pairing_enabled`) | **do not add** |
| `settings.pv_per_peso_rate` | `1000.0000` | ❌ | **do not add** |
| `settings.indirect_referral_enabled` | global | live uses `packages.indirect_referral_enabled` | keep live |
| `settings.dfi_enabled` | global | live uses `packages.dfi_enabled` | keep live |

Review SLA, packing SLA, hold deadline, and the return-to-sender response window are **hardcoded constants**
in `ShopOrder` (`SLA_REVIEW_HOURS = 24`, `SLA_PICK_HOURS = 24`, `RTS_RESPONSE_DAYS = 10`), not settings.
Rationale: they are alerts, not policy gates, and every extra setting is another thing to misconfigure.
Tunable later if real data justifies it.

### 2.3 Final DDL — 9 tables

Order matters: `products` → `carts` → `shop_orders` → `shop_order_items` → `shop_order_events` →
`shop_shipments` → `shop_payment_proofs` → `shop_refunds` → `cart_items`.

**1. `products`** — identical to rev 2 except the two inert PV columns and an optional `sku`:

```sql
CREATE TABLE IF NOT EXISTS products (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name               VARCHAR(120)   NOT NULL,
  sku                VARCHAR(60)    NULL,
  price              DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  stock              INT UNSIGNED   NOT NULL DEFAULT 0
                     COMMENT 'Absolute physical inventory; deducted once at courier handoff',
  product_pv         DECIMAL(12,2)  NOT NULL DEFAULT 0.00
                     COMMENT 'INERT (strategy C) - never read, never written, never rendered',
  pv_value           DECIMAL(12,2)  NOT NULL DEFAULT 0.00
                     COMMENT 'INERT (strategy C) - never read, never written, never rendered',
  image_url          VARCHAR(255)   NULL,
  short_description  VARCHAR(255)   NULL,
  description        TEXT           NULL,
  status             ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at         TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status, name)
) ENGINE=InnoDB;
```

**2. `carts`** — unchanged from rev 2:

```sql
CREATE TABLE IF NOT EXISTS carts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id  INT UNSIGNED NOT NULL,
  status     ENUM('active','abandoned','converted') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_member_status (member_id, status),
  FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

**3. `shop_orders`** — the centrepiece. `reviewed_by` / `reviewed_at` are gone, replaced by seven
actor/timestamp pairs plus `version`, hold fields, deadlines, and an address snapshot.

```sql
CREATE TABLE IF NOT EXISTS shop_orders (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no          VARCHAR(20)  NOT NULL COMMENT 'Public order number shown to the buyer',
  member_id         INT UNSIGNED NOT NULL,
  total_price       DECIMAL(12,2) NOT NULL,
  total_pv          DECIMAL(12,2) NOT NULL DEFAULT 0.00
                    COMMENT 'INERT (strategy C) - never read, never written, never rendered',
  payment_method    ENUM('ewallet','gcash','maya','usdt_trc20','usdt_bep20') NOT NULL,
  payment_reference VARCHAR(40) NULL COMMENT 'Buyer-declared; uniqueness checked in code at T5',
  status            ENUM('pending','payment_review','payment_failed','paid','packing','ready_to_ship',
                        'shipped','out_for_delivery','delivery_failed','returned_to_sender',
                        'delivered','completed','cancelled','on_hold') NOT NULL DEFAULT 'pending',
  previous_status   VARCHAR(24) NULL COMMENT 'Status frozen while on_hold; the resume target',
  version           INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Incremented on every transition',
  cancel_reason     VARCHAR(120) NULL,
  admin_note        TEXT NULL,

  -- Address snapshot (frozen at T1; no edits after T9 except by staff under a hold)
  recipient_name    VARCHAR(120) NULL,
  recipient_phone   VARCHAR(40)  NULL,
  address_line1     VARCHAR(180) NULL,
  address_line2     VARCHAR(180) NULL,
  city              VARCHAR(80)  NULL,
  province          VARCHAR(80)  NULL,
  postal_code       VARCHAR(20)  NULL,
  delivery_notes    VARCHAR(255) NULL,

  -- Deadlines (computed server-side from settings, never from the browser)
  payment_deadline     DATETIME NULL COMMENT 'Set at T1; T7 expires against it',
  correction_deadline  DATETIME NULL COMMENT 'Set at T3; T7 expires against it',
  completion_due_at    DATETIME NULL COMMENT 'Set at T15 = delivered + report window; T24 fires against it',

  -- Hold
  hold_reason       VARCHAR(64)  NULL,
  hold_owner        INT UNSIGNED NULL,
  hold_deadline     DATETIME    NULL,

  -- Actors + timestamps, one pair per transition family
  paid_by           INT UNSIGNED NULL, paid_at       DATETIME NULL,
  approved_by       INT UNSIGNED NULL, approved_at   DATETIME NULL,
  packed_by         INT UNSIGNED NULL, packed_at     DATETIME NULL,
  shipped_by        INT UNSIGNED NULL, shipped_at    DATETIME NULL,
  delivered_by      INT UNSIGNED NULL, delivered_at  DATETIME NULL,
  completed_by      INT UNSIGNED NULL, completed_at  DATETIME NULL,
  cancelled_by      INT UNSIGNED NULL, cancelled_at  DATETIME NULL,

  idempotency_key   VARCHAR(64) NULL COMMENT 'Checkout double-submit guard',
  terms_version     VARCHAR(16) NULL,
  terms_accepted_at DATETIME    NULL,
  created_at        TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_order_no (order_no),
  INDEX idx_member_status (member_id, status, created_at),
  INDEX idx_status_created (status, created_at),
  INDEX idx_payment_expiry (status, payment_deadline),
  INDEX idx_completion_due (status, completion_due_at),
  FOREIGN KEY (member_id)   REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (hold_owner)  REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (paid_by)      REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (approved_by)  REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (packed_by)    REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (shipped_by)   REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (delivered_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
```

Notes:
* `order_no` format: `'AL' . date('ymd') . str_pad((string) $id, 5, '0', STR_PAD_LEFT)` — assigned after the
  INSERT using `lastInsertId()` and written back inside the same transaction. It reveals no volume (it is
  date + zero-padded id, not a sequence) and is unique because `id` is. Wrapped in a helper
  (`shop_public_order_no()`, Phase 3.1) so the format lives in one place.
* `idempotency_key` is **not** `UNIQUE` in v1 — see §9 for the reasoning (cancelled orders keep their key
  for traceability, so a unique index would need careful partial-index logic MySQL lacks). It is a plain
  index via a follow-up `ALTER`, checked with a `SELECT` in `createFromCart()`.
* `payment_reference` uniqueness is checked in `ShopOrder::markPaid()`
  (`SELECT id FROM shop_orders WHERE payment_reference = ? AND id <> ? AND payment_reference IS NOT NULL`)
  rather than by a DB constraint, so a cancelled-but-traceable reference can be reported as a conflict
  (a late transfer must still be refundable) without a constraint violation crashing the transaction.
* Actor columns stay `NULL` for customer / timer / carrier actors; `shop_order_events.actor_type` is the
  authoritative record of who acted.

**4. `shop_order_items`** — adds the product-name/SKU snapshot and the reservation state:

```sql
CREATE TABLE IF NOT EXISTS shop_order_items (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id          INT UNSIGNED NOT NULL,
  product_id        INT UNSIGNED NOT NULL,
  product_name      VARCHAR(120) NOT NULL COMMENT 'Snapshot at placement; catalog edits never rewrite history',
  product_sku       VARCHAR(60)  NULL,
  quantity          INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price        DECIMAL(12,2) NOT NULL,
  total_price       DECIMAL(12,2) NOT NULL,
  unit_pv           DECIMAL(12,2) NOT NULL DEFAULT 0.00
                    COMMENT 'INERT (strategy C) - never read, never written, never rendered',
  reservation_state ENUM('reserved','committed','deducted','released','written_off')
                    NOT NULL DEFAULT 'reserved',
  deducted_at       DATETIME NULL COMMENT 'Set when reservation_state becomes deducted',
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_order (order_id),
  INDEX idx_product_state (product_id, reservation_state),
  FOREIGN KEY (order_id)   REFERENCES shop_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)   ON DELETE RESTRICT
) ENGINE=InnoDB;
```

**5. `shop_order_events`** — append-only audit. Every transition writes exactly one row, inside the same
transaction as the status change. There is **no** `UPDATE` or `DELETE` path in the codebase and no model
method that offers one.

```sql
CREATE TABLE IF NOT EXISTS shop_order_events (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id    INT UNSIGNED NOT NULL,
  from_status VARCHAR(24) NULL COMMENT 'NULL only for the creating T1 event',
  to_status   VARCHAR(24) NOT NULL,
  actor_type  ENUM('customer','admin','system','carrier') NOT NULL DEFAULT 'system',
  actor_id    INT UNSIGNED NULL,
  reason_code VARCHAR(64)  NULL,
  source      ENUM('ui','api','timer','carrier','system') NOT NULL DEFAULT 'ui',
  note        VARCHAR(255) NULL,
  payload     JSON NULL COMMENT 'Raw manual/carrier entry payload; never parsed for business logic',
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_order_created (order_id, created_at),
  INDEX idx_to_status (to_status, created_at),
  FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

Do **not** add an FK on `actor_id` — a buyer account can be deleted while its events must survive as
history, and the order cascades with it anyway, so the FK buys nothing.

**6. `shop_shipments`** — one row per parcel. One parcel per order in v1, but the table supports a reship
(`seq = 2`, `reship_of = 1`), so a second parcel is a **new row**, not an overwrite.

```sql
CREATE TABLE IF NOT EXISTS shop_shipments (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id             INT UNSIGNED NOT NULL,
  seq                  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  courier              VARCHAR(80)  NULL,
  tracking_number      VARCHAR(80)  NULL,
  state                ENUM('active','closed','lost') NOT NULL DEFAULT 'active'
                       COMMENT 'Only the active shipment may move the order',
  attempts             TINYINT UNSIGNED NOT NULL DEFAULT 0,
  fault                ENUM('customer','carrier','shop') NULL COMMENT 'Who caused the latest failure',
  fail_reason          VARCHAR(64)  NULL,
  label_image          VARCHAR(255) NULL,
  handoff_at           DATETIME     NULL COMMENT 'T13; the moment stock is deducted',
  out_for_delivery_at  DATETIME     NULL,
  delivered_at         DATETIME     NULL,
  expected_delivery_at DATETIME     NULL,
  pod_receiver         VARCHAR(120) NULL,
  pod_image            VARCHAR(255) NULL,
  pod_signature        VARCHAR(255) NULL,
  report_window_end    DATETIME     NULL COMMENT 'Mirrors shop_orders.completion_due_at for the detail view',
  response_deadline    DATETIME     NULL COMMENT 'Set at T18; buyer has N days to request a reship',
  reship_of            INT UNSIGNED NULL,
  carrier_last_event_at DATETIME    NULL COMMENT 'Older manual/carrier events are ignored if they arrive',
  closed_at            DATETIME     NULL,
  created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_order_seq (order_id, seq),
  INDEX idx_order_state (order_id, state),
  INDEX idx_tracking (tracking_number),
  FOREIGN KEY (order_id)  REFERENCES shop_orders(id)  ON DELETE CASCADE,
  FOREIGN KEY (reship_of) REFERENCES shop_shipments(id) ON DELETE SET NULL
) ENGINE=InnoDB;
```

**7. `shop_payment_proofs`** — one row per upload attempt. `shop_orders` no longer carries a single
`proof_image`: the buyer can re-upload up to `shop_max_proof_attempts` times and staff need the history.

```sql
CREATE TABLE IF NOT EXISTS shop_payment_proofs (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      INT UNSIGNED NOT NULL,
  attempt_no    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  proof_image   VARCHAR(255) NOT NULL,
  reference_no  VARCHAR(40)  NULL COMMENT 'Buyer-declared; matched against the bank record by staff',
  amount_sent   DECIMAL(12,2) NULL COMMENT 'A claim. Must equal shop_orders.total_price at T5',
  transfer_date DATE         NULL,
  outcome       ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  reason_code   VARCHAR(64)  NULL,
  reviewed_by   INT UNSIGNED NULL,
  reviewed_at   DATETIME     NULL,
  uploaded_by   INT UNSIGNED NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_order_attempt (order_id, attempt_no),
  INDEX idx_outcome (outcome, created_at),
  FOREIGN KEY (order_id)    REFERENCES shop_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
```

**8. `shop_refunds`** — a refund task. Per the workflow guide a refund is **closed only by a receipt**
(`receipt_ref`), and an open refund **blocks** `delivered → completed`. Money back to a member's e-wallet is
`Ewallet::credit()`; money back to GCash/Maya/USDT is a manual transfer whose reference goes here.

```sql
CREATE TABLE IF NOT EXISTS shop_refunds (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id     INT UNSIGNED NOT NULL,
  amount       DECIMAL(12,2) NOT NULL,
  cause        VARCHAR(64)  NOT NULL
               COMMENT 'out_of_stock, undeliverable, declined, lost_shipment, duplicate_payment, customer_request',
  destination  VARCHAR(120) NULL COMMENT 'Account the money goes back to; defaults to the pay method',
  state        ENUM('open','approved','paid','cancelled') NOT NULL DEFAULT 'open',
  receipt_ref  VARCHAR(80)  NULL COMMENT 'Required to reach state=paid',
  approved_by  INT UNSIGNED NULL,
  promised_at  DATETIME     NULL COMMENT 'Shown to the buyer; every promise states a date',
  completed_at DATETIME     NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_order (order_id),
  INDEX idx_state (state, created_at),
  FOREIGN KEY (order_id)   REFERENCES shop_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
```

**9. `cart_items`** — unchanged from rev 2 except `unit_pv` stays inert:

```sql
CREATE TABLE IF NOT EXISTS cart_items (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cart_id    INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity   INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL COMMENT 'Refreshed from products.price at checkout',
  unit_pv    DECIMAL(12,2) NOT NULL DEFAULT 0.00
             COMMENT 'INERT (strategy C) - never read, never written, never rendered',
  added_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cart_product (cart_id, product_id),
  INDEX idx_product (product_id),
  FOREIGN KEY (cart_id)    REFERENCES carts(id)    ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
```

### 2.4 `migrate_shop.sql` — exact step order

`altaslive` uses root-level `migrate_*.sql` (idempotent, `information_schema`-guarded `ALTER`s), **not**
`tmp/altas/migrations/NNN_*.sql`. Follow the live convention: **one** new file `migrate_shop.sql`, safe to run
twice, in this order:

| Step | Statement | Guard |
|---|---|---|
| 1 | `CREATE TABLE IF NOT EXISTS products` | — |
| 2 | `CREATE TABLE IF NOT EXISTS carts` | — |
| 3 | `CREATE TABLE IF NOT EXISTS shop_orders` (14-value status ENUM) | — |
| 4 | `CREATE TABLE IF NOT EXISTS shop_order_items` | — |
| 5 | `CREATE TABLE IF NOT EXISTS shop_order_events` | — |
| 6 | `CREATE TABLE IF NOT EXISTS shop_shipments` | — |
| 7 | `CREATE TABLE IF NOT EXISTS shop_payment_proofs` | — |
| 8 | `CREATE TABLE IF NOT EXISTS shop_refunds` | — |
| 9 | `CREATE TABLE IF NOT EXISTS cart_items` | — |
| 10 | `ALTER TABLE ewallet_ledger MODIFY COLUMN ref_type ENUM('commission','payout','reactivation','transfer','topup','registration','shop_order') NULL` | `information_schema.COLUMNS` check, same style as `migrate_usdt_bep20.sql:7–19` |
| 11 | Seed 6 settings with `INSERT … ON DUPLICATE KEY UPDATE value = value` | — |
| 12 | Trailing commented `SELECT` sanity block | — |

FK ordering matters: `products` and `carts` reference `users`; `shop_order_items` references both
`shop_orders` and `products`; `shop_shipments.reship_of` self-references (MySQL resolves that within a
single `CREATE TABLE`). All nine tables go in after step 2 so the FK graph is acyclic.

> **Gate 1.1:** `mysql -u USER -p DB < migrate_shop.sql` runs twice with no error; the second run is a
> no-op (all `IF NOT EXISTS`, all `ALTER`s guarded, all seeds `ON DUPLICATE KEY`).

### 2.5 Migration notes

**Order of operations.** Ship the migration **before** any code. `ewallet_ledger.ref_type` rejecting
`'shop_order'` is a fatal SQL error at checkout, and the 14-value `shop_orders.status` ENUM must exist before
the first order is placed.

**Fresh installs.** `install.sql` gets the same nine `CREATE TABLE` blocks (Phase 1.2). The migration and the
install file must agree exactly — verify with `SHOW CREATE TABLE` diffs, not by eye.

**If revision 2 was already deployed** (rev 2 never shipped, but be ready), the following statements map the
old data. They are **not** part of `migrate_shop.sql`; put them in a separate `migrate_shop_v2_to_v3.sql`
only if rev-2 rows exist.

```sql
-- 1. Widen the ENUM first (the UPDATEs below depend on the new values existing)
ALTER TABLE shop_orders
  MODIFY COLUMN status ENUM('pending','payment_review','payment_failed','paid','packing',
                           'ready_to_ship','shipped','out_for_delivery','delivery_failed',
                           'returned_to_sender','delivered','completed','cancelled','on_hold')
  NOT NULL DEFAULT 'pending';

-- 2. Map old values to new
UPDATE shop_orders SET status = 'payment_review' WHERE status = 'paid';
UPDATE shop_orders SET status = 'paid'          WHERE status = 'approved';
UPDATE shop_orders SET status = 'payment_failed' WHERE status = 'rejected';

-- 3. Seed the deadlines the old rows never had
UPDATE shop_orders SET payment_deadline = DATE_ADD(created_at, INTERVAL 24 HOUR)
  WHERE status IN ('pending','payment_review','payment_failed') AND payment_deadline IS NULL;

-- 4. Pre-existing open orders that rev 2 had reserved are now paid/committed
UPDATE shop_order_items oi
  JOIN shop_orders o ON o.id = oi.order_id
  SET oi.reservation_state = 'committed'
  WHERE o.status IN ('paid','packing','ready_to_ship') AND oi.reservation_state = 'reserved';

-- 5. Backfill order_no for pre-existing rows (PHP loop), then add the UNIQUE index
UPDATE shop_orders SET order_no = 'LEGACY-' . id WHERE order_no IS NULL OR order_no = '';
```

Rev 2's `reviewed_by` / `reviewed_at` / `paid_at` columns have no direct successor for the *rejected* case
(`payment_failed`). Map them forward as: `paid_at` → `paid_at`; `reviewed_by` → `paid_by` **only** where the
new status is `paid`; `reviewed_by` → `cancelled_by` where the new status is `cancelled`. Drop `reviewed_by`
in a final, separate statement once the mapping is verified with a `SELECT status, reviewed_by, …` audit.

**Backout.** §7.4 has the full rollback SQL.

---

## 3. Ordered implementation checklist

Phases are sequential; each ends with its own verification gate. Do not start a phase before the previous
gate passes.

---

### Phase 1 — Schema

**1.1 Create `migrate_shop.sql`** (root of `altaslive`, next to the other `migrate_*.sql`) — DDL verbatim
from §2.3, step order from §2.4.

**1.2 Edit `install.sql`** (402 lines — fresh installs must ship the same shape)

* **Do NOT touch** `packages`, `users`, `commissions`, `cd_ledger`, or any `*_pv*` block — they do not exist
  in live and are not being added.
* Insert `CREATE TABLE products` **after `package_indirect_levels`** (which ends at line 40 `) ENGINE=InnoDB;`)
  and **before** `-- ─── USERS ───` (line 42). It has no FK dependencies, so it can go early.
* Insert `CREATE TABLE carts`, `shop_orders`, `shop_order_items`, `shop_order_events`, `shop_shipments`,
  `shop_payment_proofs`, `shop_refunds`, `cart_items` **after line 121**
  (`ALTER TABLE users ADD FOREIGN KEY (reg_code_id) …`) and **before** `-- ─── COMMISSIONS ───` (line 123).
  They must come after `users` because of their FKs, and in the §2.3 order because of theirs.
* `ewallet_ledger` (line 147): append `'shop_order'` to the `ref_type` ENUM and extend the trailing comment.
* Settings seed block — the `INSERT INTO settings` list ends at line 398 with `('seat_limit', '0');`. Append
  before the `;`: `('shop_enabled','1'), ('shop_payment_deadline_hours','24'),
  ('shop_correction_window_hours','24'), ('shop_max_proof_attempts','3'),
  ('shop_report_window_days','5'), ('shop_max_delivery_attempts','3')`.
* Do **not** add the new tables' indexes to the trailing `ALTER TABLE … ADD INDEX` block (lines 308–321) —
  the new `CREATE TABLE`s declare them inline and MySQL rejects a duplicate index name.

> **Gate 1.2:** Laragon/`php -S` fresh import of `install.sql` succeeds; `SHOW CREATE TABLE shop_orders;`
> and `SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'` match the migration output.

---

### Phase 2 — Models

All seven are **static, PDO-only, no framework** — port then strip.

**2.1 Create `models/Product.php`** (from `tmp/altas/models/Product.php`, 156 lines)

* `find()`, `all()`, `allPaginated()`, `active()` — keep as-is.
* `reservedStock(int $productId): int` — keep, retargeted to the reservation state (§0.3):

  ```php
  "SELECT COALESCE(SUM(oi.quantity), 0)
     FROM shop_order_items oi
     JOIN shop_orders o ON o.id = oi.order_id
    WHERE oi.product_id = ?
      AND oi.reservation_state IN ('reserved','committed')
      AND o.status IN ('pending','payment_review','payment_failed','paid','packing',
                       'ready_to_ship','on_hold')"
  ```

  Two filters, deliberately. `reservation_state` is the contract; the status list is a **defensive secondary
  filter** that makes a stale `reserved` row on a cancelled order harmless. Leave a one-line comment saying
  so, or a future reader will "simplify" one away and re-open the bug.
* `availableStock(int $productId): int` — keep.
* **DELETE** `getUnilevelLevels()`, `withUnilevelLevels()`, `unilevelProductBonus()`.
* `save(array $data, ?int $id = null): int` — keep the explicit bulk field list minus `product_pv` /
  `pv_value`, and **delete** the 10-row `product_unilevel_levels` rewrite block (tmp lines 129–137). Add
  `sku` to the field list. Because the field list is explicit, a POSTed `product_pv` is silently dropped —
  which is exactly what §0.2 requires.
* `delete(int $id): bool` — keep; it calls `delete_uploaded_file()` (Phase 3) and refuses deletion while
  `shop_order_items` rows exist. **Harden** the count query with a bound param (`prepare`/`bindValue`)
  instead of tmp's string interpolation.

**2.2 Create `models/Cart.php`** (from `tmp/altas/models/Cart.php`, 248 lines)

Copy as-is, then:

* `addItem()` — drop `$unitPv` and the `unit_pv = ?` fragment from both the UPDATE and the INSERT (the
  column keeps its `0.00` default — inert, §0.2).
* `updateQuantity()` — same; it refreshes `unit_price` from `products.price`, which is correct and stays.
* `getTotals()` — drop `COALESCE(SUM(quantity * unit_pv), 0) AS total_pv` and the `'total_pv'` fallback key.
* `getItem()` / `getItems()` — drop `p.pv_value AS current_pv` from the SELECT list.
* **Add** `refreshPrices(int $cartId): void` (~6 lines) — `UPDATE cart_items ci JOIN products p ON p.id =
  ci.product_id SET ci.unit_price = p.price WHERE ci.cart_id = ?`. Called at the top of `checkout()` and
  `placeOrder()` so an admin price change can never be baked into a placed order at the stale cart price.
* Everything else (`getOrCreate`, `getActive`, `removeItem`, `isEmpty`, `abandon`, `markConverted`, `clear`,
  `updateItemQuantity`, `removeItemById`, `itemCountForMember`, `validateStock`) is engine-agnostic — keep.

**2.3 Create `models/ShopOrder.php`** (from `tmp/altas/models/RepeatPurchaseOrder.php`, 228 lines) —
**the centrepiece**

Rename class + file; retarget `repeat_purchase_orders` → `shop_orders` and
`repeat_purchase_order_items` → `shop_order_items`. Keep `find`, `findWithItems`, `forMember`, and
`protected static paginate`. Replace rev 2's four hardcoded status helpers (`pending()`, `paid()`,
`approved()`, `all()`) with `byStatus(string $status, int $page, int $perPage)`, `allPaginated(...)`, and
`statusCounts(): array` (returns one row per status with a count — drives the admin tabs and the sidebar
badge; one `GROUP BY status` query, not 14).

**Class constants** (§0.5 is the authority; these must match it exactly):

```php
public const STATUSES = [
    'pending', 'payment_review', 'payment_failed', 'paid', 'packing', 'ready_to_ship',
    'shipped', 'out_for_delivery', 'delivery_failed', 'returned_to_sender',
    'delivered', 'completed', 'cancelled', 'on_hold',
];

public const TERMINAL = ['completed', 'cancelled'];

/** Alert-only SLAs, not policy gates (§2.2). */
public const SLA_REVIEW_HOURS  = 24;
public const SLA_PICK_HOURS    = 24;
public const RTS_RESPONSE_DAYS = 10;

/** to => [from => [...allowed source statuses...], actors => [...]] */
public const TRANSITIONS = [
    'payment_review'      => ['from' => ['pending', 'payment_failed'],
                              'actors' => ['customer']],
    'payment_failed'      => ['from' => ['payment_review'],
                              'actors' => ['admin']],
    'paid'                => ['from' => ['payment_review', 'pending'],
                              'actors' => ['admin', 'system']],
    'cancelled'           => ['from' => ['pending', 'payment_failed', 'paid', 'packing',
                                        'ready_to_ship', 'on_hold', 'returned_to_sender'],
                              'actors' => ['customer', 'admin', 'system']],
    'on_hold'             => ['from' => ['pending', 'payment_review', 'payment_failed', 'paid',
                                        'packing', 'ready_to_ship', 'returned_to_sender'],
                              'actors' => ['admin']],
    'packing'             => ['from' => ['paid', 'ready_to_ship', 'returned_to_sender',
                                        'delivered', 'on_hold'],
                              'actors' => ['admin']],
    'ready_to_ship'       => ['from' => ['packing'],
                              'actors' => ['admin']],
    'shipped'             => ['from' => ['ready_to_ship'],
                              'actors' => ['admin']],
    'out_for_delivery'    => ['from' => ['shipped', 'delivery_failed'],
                              'actors' => ['admin', 'system']],
    'delivery_failed'     => ['from' => ['shipped', 'out_for_delivery'],
                              'actors' => ['admin', 'system']],
    'returned_to_sender'  => ['from' => ['shipped', 'out_for_delivery', 'delivery_failed'],
                              'actors' => ['admin', 'system']],
    'delivered'           => ['from' => ['shipped', 'out_for_delivery', 'delivery_failed'],
                              'actors' => ['admin', 'system']],
    'completed'           => ['from' => ['delivered'],
                              'actors' => ['customer', 'system']],
];
```

`ShopOrder::revertPaidToReview()` is a **method, not a matrix row** — it targets `payment_review` and would
collide in the array (guide §5.6, the `paid → payment_review` revert).

**The one writer of `status`:**

```php
public static function transition(int $orderId, string $to, string $actorType,
                                  ?int $actorId = null, array $opts = []): bool
```

Steps, all inside one transaction:
1. `SELECT … FOR UPDATE` the order row.
2. Resolve the *effective* source status: if `status === 'on_hold'`, use `previous_status` for the legality check.
3. Assert `$to` exists in `TRANSITIONS`; else throw `InvalidArgumentException` (a typo in a route must not silently no-op).
4. Assert the effective source is in `$to`'s `from` list; else return `false` (refused, not an error).
5. Assert `$actorType` is in `$to`'s `actors`; else throw.
6. Run the named guard callbacks from `$opts['guards']` (e.g. `assertAmountMatchesTotal`,
   `assertProofFile`, `assertStockAvailable`, `assertNoOpenRefund`); any false → return `false`.
7. Build the `SET` clause from the actor/timestamp pair for `$to`: `paid`→`paid_by`/`paid_at`,
   `packing`→`approved_by`/`approved_at`, `ready_to_ship`→`packed_by`/`packed_at`,
   `shipped`→`shipped_by`/`shipped_at`, `delivered`→`delivered_by`/`delivered_at`,
   `completed`→`completed_by`/`completed_at`, `cancelled`→`cancelled_by`/`cancelled_at`.
   Customer/timer actors leave the `*_by` column `NULL`.
8. `UPDATE shop_orders SET status = ?, version = version + 1, … WHERE id = ? AND status = ?` with the
   **status the caller saw**. Require exactly **1** affected row; **0** means another actor won → return
   `false` (conditional write, guide §11.1).
9. Apply side effects for `$to` (reservation state, stock deduction, shipment fields, deadlines).
10. Insert the `shop_order_events` row.
11. `commit()`.

**Method list:**

| Method | Behaviour |
|---|---|
| `createFromCart()` | `(int $memberId, int $cartId, array $addr, string $paymentMethod, ?string $idempotencyKey = null): int`. **Drops** `$binaryPosition`. Sets `order_no`, `status='pending'`, `payment_deadline = NOW() + shop_payment_deadline_hours`, the address snapshot, `terms_version`/`terms_accepted_at`, `version = 1`. Inserts lines **without** `total_pv`/`unit_pv` (§0.2), with `reservation_state='reserved'` and `product_name`/`product_sku` snapshots. Writes the T1 event. If `$idempotencyKey` already exists, return the existing order's id instead of inserting. `Cart::markConverted($cartId)` stays. |
| `transition()` | As above. The **only** place `UPDATE shop_orders SET status` appears. |
| `onHold()` | `(int $orderId, int $adminId, string $reason, int $ownerId, string $deadline)` — freezes `previous_status = status`, then `transition('on_hold', 'admin')` with the hold fields. Reason, owner, and deadline are all mandatory: an owner-less, deadline-less hold is permanent limbo, which the guide explicitly forbids. |
| `resume()` | `(int $orderId, int $adminId): bool` — `status = previous_status`, clears hold fields, then re-validates the resume target against `TRANSITIONS`; `false` if unreachable (e.g. the shipment was flagged lost meanwhile). |
| `submitProof()` | Guard: from `pending` (before `payment_deadline`) or `payment_failed` (before `correction_deadline`), `attemptCount < shop_max_proof_attempts`. Inserts a `shop_payment_proofs` row with `attempt_no = count+1`, then `transition('payment_review', 'customer')`. Does **not** clear `payment_deadline` — `payment_review` is exempt from expiry (§0.5 T7), so leaving it preserves the audit trail. |
| `rejectProof()` | `(int $orderId, int $adminId, string $reasonCode, ?float $amountReceived = null)` — marks the latest proof `rejected`, sets `correction_deadline = NOW() + shop_correction_window_hours HOUR`, then `transition('payment_failed')`. On the attempt cap, `transition('on_hold')` with reason `proof_attempts_exhausted` instead. |
| `markPaid()` | Guards: `amount_sent === total_price`; `payment_reference` unused by any other order. Flips every line `reserved → committed`, marks the proof `verified` with `reviewed_by`, then `transition('paid')`. tmp never set `paid_at` and wrongly used the `approved_*` columns — both fixed. |
| `startPacking()` | `paid → packing` (the address is already snapshotted, so this is the *documented* lock point, not an enforcement one). |
| `finishPacking()` | `packing → ready_to_ship`. Guard: every line present. |
| `ship()` | Guards: `courier` + `tracking_number` set. Creates/updates the active `shop_shipments` row, sets `handoff_at`, then per line `UPDATE products SET stock = stock - :qty WHERE id = :id AND stock >= :qty` and `UPDATE shop_order_items SET reservation_state='deducted', deducted_at = NOW() WHERE …`. **Any zero-row result aborts the whole transaction** and returns `false`. |
| `markOutForDelivery()` | `shipped → out_for_delivery`; stamps `out_for_delivery_at`. |
| `markDelivered()` | Guards: a POD field (`pod_receiver` or `pod_image`). Sets `delivered_at`, `pod_*`, `report_window_end`, and `completion_due_at = NOW() + shop_report_window_days DAY`; closes the delivery watchdog. |
| `markDeliveryFailed()` | Guards: `fault ∈ {customer, carrier, shop}` + `fail_reason`. Increments `attempts`; sets `response_deadline` when `attempts >= shop_max_delivery_attempts`. |
| `returnToSender()` | Closes the active shipment (`state='closed'`, `closed_at`), sets `response_deadline = NOW() + RTS_RESPONSE_DAYS`. |
| `reship()` | Guards: from `returned_to_sender`, and `seq < 2` without an admin override flag. Closes the old shipment, creates a new row with `seq+1`, `reship_of = old id`, `attempts = 0`, flips the order's lines `deducted → reserved` (goods are reused; stock was never re-added), then `transition('packing')`. **No fee, no payment record** (§0.4). |
| `cancel()` | `(int $orderId, string $actorType, ?int $actorId, string $reasonCode)`. Customer/system cancel sets every non-terminal line to `released`. Admin cancel additionally opens a `shop_refunds` row when the order was ever `paid` (`total_price`, cause from the reason code). |
| `confirmReceipt()` | Customer-initiated `delivered → completed`; guard: `!ShopRefund::hasOpen($orderId)`. |
| `expireOverdue()` | `(int $limit = 200): int` — used by cron and the manual button (Phase 6). Candidates: `status IN ('pending','payment_failed') AND COALESCE(payment_deadline, correction_deadline) < NOW()`. Each runs through `cancel(actorType: 'system', reason: 'payment_expired')`, which is a conditional transition — so a proof uploaded a moment earlier wins the race. **Never** selects `payment_review`. |
| `autoComplete()` | `(int $limit = 200): int` — `status='delivered' AND completion_due_at < NOW() AND no open refund` → `transition('completed', 'system')`. |
| `assertNotSelfReview()` | Guard helper: if `Auth::isAdmin() && (int)$order['member_id'] === Auth::id()`, throw — admins cannot verify, pack, ship, or complete their own orders (§5.9). |
| `canMemberAct()` | `(array $order, string $act): bool` for `cancel` / `proof` / `confirm` — the single predicate the member views use to decide whether to render an action. The server re-checks in the handler, so hiding a button is never the only guard. |
| **No `approve()`** | rev 2's `approve()` is replaced by `startPacking()` / `finishPacking()`. **No `reject()`**. **No `Commission::` call anywhere** — the absence of that single line *is* strategy C. |

**2.4 Create `models/ShopOrderEvent.php`** (~60 lines, new)

* `log(int $orderId, ?string $from, string $to, string $actorType, ?int $actorId, array $opts = []): int`
* `forOrder(int $orderId): array` — chronological; feeds the member timeline and the admin audit panel.
* `recent(int $limit = 50): array` — newest first; an optional site-wide activity feed.
* No `update()`, no `delete()`. Add a docblock saying so, because a future "clean up old events" task will
  otherwise try.

**2.5 Create `models/ShopShipment.php`** (~110 lines, new)

* `activeFor(int $orderId): ?array` — `state='active'` ordered by `seq DESC LIMIT 1`. The **only** row that
  may move the order.
* `forOrder(int $orderId): array` — all shipments, for the detail view (a reship shows both).
* `create(int $orderId, array $data): int`, `touch(int $id, array $data): void`,
  `close(int $id, string $state = 'closed'): void`
* `flagLost(int $id, int $adminId): void` — sets `state='lost'`; unlocks admin cancel and reship (§0.5).
* `upsertTracking(int $orderId, string $courier, string $tracking, ?string $expectedAt = null): int`
* `recordAttempt(int $id, string $fault, string $reason): void` — `attempts = attempts + 1`
* `savePod(int $id, array $pod): void`
* `nextSeq(int $orderId): int` — `SELECT COALESCE(MAX(seq), 0) + 1`
* **No method may write `shop_orders.status`** — shipments hold facts; the order's status moves only through
  `ShopOrder::transition()`.

**2.6 Create `models/ShopPaymentProof.php`** (~80 lines, new)

* `forOrder(int $orderId): array`, `latest(int $orderId): ?array`
* `add(int $orderId, int $uploadedBy, string $image, array $claim): int`
* `markVerified(int $proofId, int $adminId): void` /
  `markRejected(int $proofId, int $adminId, string $reason): void`
* `attemptCount(int $orderId): int`

**2.7 Create `models/ShopRefund.php`** (~70 lines, new)

* `forOrder(int $orderId): array`, `open(int $orderId): int`
* `create(int $orderId, float $amount, string $cause, ?string $destination = null, ?string $promisedAt = null): int`
* `approve(int $id, int $adminId): void`
* `markPaid(int $id, string $receiptRef): void` — **refuses** an empty `receipt_ref` (the guide's
  "closed only by a receipt" rule). When `destination` is the buyer's e-wallet, this also calls
  `Ewallet::credit($memberId, $amount, $orderId, 'shop_order', 'Shop order refund')`.
* `hasOpen(int $orderId): bool` — blocks `delivered → completed`.

**2.8 (Skipped) `models/RepeatPurchase.php`** — tmp marks it `@deprecated`; nothing in the ported
views/controllers references it. **Do not port it.**

> **Gate 2:** `php -l` on all seven; and this must return **zero** hits across `models/`, `core/`,
> `controllers/`, `views/`:
> `Select-String -Path models,core,controllers,views -Recurse -Pattern "product_pv|pv_value|unit_pv|total_pv|pv_per_peso_rate|processProductPV|processProductUnilevel|processBinaryVolume|repeat_purchase|product_unilevel|unilevelProductBonus|reviewed_by"`
> (`reviewed_by` must be **0** everywhere — `shop_payment_proofs.reviewed_by` is a *new* legitimate column,
> so scope that alternative to `ShopOrder.php` when checking, or expect exactly the `ShopPaymentProof.php`
> hits. `approved_by` is likewise legitimate on `shop_orders`.)
>
> **Token choices matter** — do not grep for bare `unilevel` or `binary_position` globally. Live legitimately
> contains `unilevel` (as the *indirect referral* marketing label in `core/CapEngine.php:10`,
> `frontend/index.php:2501/2696`, `frontend/script.js:189`) and `users.binary_position` (registration, upgrade,
> genealogy, activate views — 31 hits). Grep those two **shop-file-scoped** instead (§6 V3).
>
> **Gate 2b — matrix sanity:** `count(ShopOrder::TRANSITIONS) === 13`, every `from` value is a member of
> `ShopOrder::STATUSES`, every `actors` value is in `['customer','admin','system','carrier']`, and
> `ShopOrder::TERMINAL` values appear in **no** `from` list. A typo here is a runtime refusal, not a lint
> error — grep the file for `'reshiped'`, `'payment_fail'`, `'readytoship'`, `'outfordelivery'`.

---

### Phase 3 — Helpers + routes

**3.1 Edit `core/helpers.php`** (456 lines)

| Insert | Location | Content |
|---|---|---|
| `per_page(int $default = 10, int $min = 5): int` | after `setting()` closes (line 346), before the `// ── Pagination ──` banner (line 348) | port verbatim from tmp `core/helpers.php:301–309` |
| `upload_image(array $file, string $subDir, string $prefix, ?string $oldPath = null, int $maxBytes = 5*1024*1024): ?string` | after `paginate()` closes (line 377), before the `pagination_links()` docblock (line 379) | port verbatim from tmp `core/helpers.php:340–397` |
| `delete_uploaded_file(?string $relativePath): void` | same block, immediately after `upload_image()` | port verbatim from tmp `core/helpers.php:399–414` |
| `shop_status_label(string $status): string` | after the `delete_uploaded_file()` block | 14-entry `match()` → human label (`Ready to ship`, `Out for delivery`, …) |
| `shop_status_tone(string $status): string` | same block | 14-entry `match()` → Bootstrap token (`success`, `warning`, `danger`, `info`, `secondary`, `dark`) so the member and admin badges always agree |
| `shop_status_badge(string $status, ?string $stamp = null): string` | same block | ~5 lines: `'<span class="badge bg-' . shop_status_tone($s) . '">' . e(shop_status_label($s)) . '</span>'`, optionally with `<small>` + `fmt_date()` |
| `shop_public_order_no(int $id, string $createdAt): string` | same block | `'AL' . date('ymd', strtotime($createdAt)) . str_pad((string) $id, 5, '0', STR_PAD_LEFT)` |

* **Do not touch** `paginate()` (line 350) — keep `$perPage = 20`.
* **Do not touch** `link_to()` (158), `redirect()` (101), `csrf_*`, `setting()`, `flash()`, `json_response()` (296).
* Optional follow-up (separate commit, §7.6): refactor `AdminController::savePackage()` lines 377–416 to call
  `upload_image()`. Keep its stricter MIME allow-list (jpg/png/webp, no gif) — do not silently loosen
  package uploads to match the helper's gif support.

**3.2 Create upload directories** (or let `upload_image()` `mkdir` them):

```
uploads/products/
uploads/shop_proofs/
uploads/shipments/
```

`/uploads` is already gitignored; no `.gitignore` change needed.

**3.3 Edit `index.php` route table** (array spans lines **271–357**)

Insert the **member** block after line 314 (`'api_binary_uplines' …`), before the `// ── Admin ──` banner (316):

```
'shop'                 => ['MemberController',  'shop',              'member'],
'shop_orders'          => ['MemberController',  'shopOrders',        'member'],
'shop_order'           => ['MemberController',  'shopOrder',         'member'],
'cart'                 => ['MemberController',  'cart',              'member'],
'add_to_cart'          => ['MemberController',  'addToCart',         'member'],
'update_cart_item'     => ['MemberController',  'updateCartItem',    'member'],
'remove_cart_item'     => ['MemberController',  'removeCartItem',    'member'],
'checkout'             => ['MemberController',  'checkout',          'member'],
'place_order'          => ['MemberController',  'placeOrder',        'member'],
'shop_submit_proof'    => ['MemberController',  'submitShopProof',   'member'],
'shop_cancel_order'    => ['MemberController',  'cancelShopOrder',   'member'],
'shop_confirm_receipt' => ['MemberController',  'confirmShopReceipt','member'],
```

Insert the **admin** block after line 342 (`'admin_toggle_daily_cap' …`), before the
`// ── Commission-Deduct (CD) admin actions` banner (344):

```
'admin_shop'             => ['AdminController', 'shop',            'admin'],
'admin_save_product'     => ['AdminController', 'saveProduct',     'admin'],
'admin_delete_product'   => ['AdminController', 'deleteProduct',   'admin'],
'admin_shop_orders'      => ['AdminController', 'shopOrders',      'admin'],
'admin_shop_order'       => ['AdminController', 'shopOrder',       'admin'],
'admin_order_transition' => ['AdminController', 'orderTransition', 'admin'],
'admin_order_hold'       => ['AdminController', 'orderHold',       'admin'],
'admin_order_resume'     => ['AdminController', 'orderResume',     'admin'],
'admin_order_proof'      => ['AdminController', 'orderProofView',  'admin'],
'admin_order_shipment'   => ['AdminController', 'orderShipment',   'admin'],
'admin_order_reship'     => ['AdminController', 'orderReship',     'admin'],
'admin_order_refund'     => ['AdminController', 'orderRefund',     'admin'],
'admin_shop_expire'      => ['AdminController', 'shopExpire',      'admin'],
```

Design notes:

* **One transition endpoint.** `admin_order_transition` is a single POST handler taking `to`, `order_id`, and
  transition-specific fields. This is the guide's principle 2 ("one transition handler") made literal in
  the router: there is **no** `admin_mark_order_paid`, **no** `admin_approve_order`, **no**
  `admin_reject_order`, **no** `admin_ship_order`. It also means the action buttons in
  `views/admin/shop_order.php` are generated from the matrix rather than hand-written, so a new status needs
  one matrix row and no new route.
* **Role stays `'member'`, not `'any'`.** `Auth::guard('member')` (line 233) admits anyone with a session,
  which is exactly what "admins can shop" needs. Do **not** add `Auth::isAdmin()` exclusion logic to any
  member shop route.
* Naming rationale: the vocabulary is `shop`; the shape follows the live router (`admin_<resource>`,
  `admin_<verb>_<resource>`). `admin_shop` is the catalog, `admin_shop_orders` the list, `admin_shop_order`
  the single-order desk — mirroring the existing `admin_payouts` / `admin_payout_action` pair.
* Do **not** add `binary_enabled`, `member_royalty`, `?cart=1` handling, or `_show_frontend` handling —
  the last one already exists in live `.htaccess` + `frontend/index.php`.

> **Gate 3:** `php -l index.php core/helpers.php`; all 25 route keys present (§6 V2);
> `Select-String -Path index.php -Pattern "cart_offcanvas"` still 0 (the partial arrives in Phase 5).

---

### Phase 4 — Controllers

**4.1 Edit `controllers/MemberController.php`** (1117 lines) — insert 12 methods between line 132
(`earnings()` closes) and line 134 (`resolveBinaryRoot()` docblock).

Port from `tmp/altas/controllers/MemberController.php:141–405`, renamed per §3.3, with these adaptations:

| Method | tmp lines | Adaptation for live |
|---|---|---|
| `shop()` | 141–157 (was `repeatPurchases`) | **Split**: keep only `Product::active()` + the catalog grid. **Drop** `$history`, `RepeatPurchaseOrder::forMember()`, and `$page` — those move to `shopOrders()`. `$pageTitle = 'Shop'` |
| `shopOrders()` | — | **New**, carved out of tmp `repeatPurchases()`: `ShopOrder::forMember(Auth::id(), $page, per_page())`, `$pageTitle = 'My Orders'`. **Not** gated by `guardShop()` — a buyer must always be able to read their own past orders. |
| `shopOrder()` | — | **New.** `ShopOrder::findWithItems((int)$_GET['id'])` + ownership check (`$order['member_id'] !== Auth::id()` → flash + redirect) + `ShopOrderEvent::forOrder()` + `ShopShipment::forOrder()` + `ShopPaymentProof::forOrder()` + `ShopRefund::forOrder()`. Renders the status pill, the timeline, the shipment/tracking block, the POD, the address snapshot, and the three member actions (cancel / upload proof / confirm receipt), each rendered **only** when `ShopOrder::canMemberAct($order, …)` allows. |
| `cart()` | 164–176 | keep verbatim |
| `addToCart()` | 178–195 | redirect target → `redirect('/?page=shop&cart=1')` (auto-open drawer, §5.6) |
| `updateCartItem()` | 197–231 | replace the hand-rolled `header()`/`echo json_encode()`/`exit` with `json_response([...])` (`helpers.php:296`) on **both** branches; keep the `X-Requested-With` detection; non-AJAX redirect → `/?page=cart` |
| `removeCartItem()` | 233–257 | same `json_response()` swap; wrap `Cart::removeItemById()` in try/catch like the update handler |
| `checkout()` | 259–288 | keep, plus: **`Cart::refreshPrices($cartId)` first**, then re-read `getTotals()`; **delete** `$showBinaryPosition`; `Cart::validateStock()` stays. **Add** the address form (recipient name, phone, line1, line2, city, province, postal code, notes) with `csrf_field()`, plus the terms checkbox (`terms_version = 'v1'`). |
| `placeOrder()` | 290–405 | **6 required edits** (below) |
| `submitShopProof()` | — | **New.** POST: `order_id`, `proof_image` (→ `upload_image(…, 'shop_proofs', 'proof_'.$memberId)`), `reference_no`, `amount_sent`, `transfer_date`, and the explicit `confirm_lock` checkbox ("Submitting proof ends your option to cancel"). Calls `ShopOrder::submitProof()`. Guarded by ownership + `csrf_verify()`. |
| `cancelShopOrder()` | — | **New.** POST: `order_id`, optional reason. Calls `ShopOrder::cancel('customer', Auth::id(), $reason)`. Refusal messages come from the matrix, not a hand-written list, so a direct API call gets the same answer as the hidden button (§0.5 T6). |
| `confirmShopReceipt()` | — | **New.** POST: `order_id`. Calls `ShopOrder::confirmReceipt()`. Button copy must say it ends reporting. |

Add one private guard used by `shop()`, `cart()`, `addToCart()`, `checkout()`, `placeOrder()`,
`submitShopProof()`, `cancelShopOrder()`, `confirmShopReceipt()`:

```php
/** Storefront master switch (settings.shop_enabled). */
private function guardShop(): void
{
    if (setting('shop_enabled', '1') === '1') return;
    flash('error', 'The shop is currently closed.');
    redirect('/?page=dashboard');
}
```

`shop_orders()` and `shop_order()` are **not** gated — a buyer must always be able to read their own past
orders, even after the shop closes.

`placeOrder()` required edits:

1. **Delete the binary-position block** (tmp lines 311–314) and the `$binaryPosition` argument at the
   `createFromCart()` call site (tmp line 361). There is no leg choice at checkout any more.
2. **Address snapshot** — collect the POSTed address, pass it to `ShopOrder::createFromCart()`. Never trust a
   browser-computed total; `total_price` comes from `Cart::getTotals()` after `refreshPrices()`.
3. **Idempotency** — derive `$idempotencyKey` from `sha1($memberId . '|' . $cartId . '|' . ($_POST['idem'] ?? ''))`
   and pass it to `createFromCart()`; a repeat returns the existing order instead of creating a second one
   (§0.5 T1, guard 4).
4. **Proof upload → helper.** Replace tmp lines 329–355 (manual `finfo` + `move_uploaded_file()` into
   `uploads/repeat_purchase_proofs/`) with `upload_image($_FILES['proof_image'], 'shop_proofs',
   'proof_'.$memberId)`. The helper accepts gif; if you want tmp's stricter set (no gif), validate
   `mime_content_type()` before calling. Store the returned relative path in `$proofImage`.
5. **E-wallet debit → `Ewallet::debitInternal()`.** Replace tmp lines 365–379 (raw
   `UPDATE users SET ewallet_balance = ewallet_balance - ?` **plus** the hand-rolled `ewallet_ledger`
   INSERT). The raw UPDATE leaves `withdrawable_balance` untouched, so the buyer could withdraw money already
   spent. Use `Ewallet::debitInternal($memberId, $totalPrice, $orderId, 'shop_order', "Shop order #{$orderNo}")`
   — this writes the ledger row, spends non-withdrawable funds first, and returns `false` on insufficiency →
   `throw`. Call it **inside** the existing transaction.
6. **External methods land in `pending`; e-wallet lands in `paid`.** Delete the "auto-approve" block
   (tmp lines 381–386) **and its `Commission::processProductPV($orderId)` call**. Instead: e-wallet orders
   call `ShopOrder::transition($orderId, 'paid', 'system', null, ['guards' => ['autoVerified']])` — the money
   already moved through the system's own ledger, so it needs no human review. External orders stay `pending`
   with flash **"Order placed. Upload your payment proof to continue."** Do **not** create a
   `shop_payment_proofs` row for an e-wallet order; there is nothing to review.
   `Cart::clear($cartId)` stays; `Cart::markConverted()` is already inside `createFromCart()` — do not call
   it twice.

**4.2 Edit `controllers/AdminController.php`** (943 lines) — insert 13 methods between line 421
(`savePackage()` closes) and line 423 (`// ── Registration Codes ──` banner).

Port from `tmp/altas/controllers/AdminController.php:309–488`:

| Method | tmp lines | Adaptation |
|---|---|---|
| `shop()` | 309–320 | `$pageTitle` is set **inside the view** (live admin convention, cf. `views/admin/packages.php:8`) — do not set it here. `$perPage = per_page();`. `?edit=` → `Product::find()` (not `withUnilevelLevels()`). |
| `saveProduct()` | 322–401 | keep validation for `name` + `price`; **delete** the `product_pv` / `pv_value` / `unilevel_{1..10}` validation blocks and the whole `$data['unilevel_levels']` assembly (tmp 343–354, 360–367, 373). Add optional `sku`. `upload_image($_FILES['image'], 'products', 'product_'.($id ?: 'new'), …)` + `delete_uploaded_file()` now exist (Phase 3). Redirects → `/?page=admin_shop`. |
| `deleteProduct()` | 403–420 | keep; flash copy → "Cannot delete product: it has existing shop orders." |
| `shopOrders()` | 426–443 | tabs become the **14 statuses plus `all`**, each with a live count from `ShopOrder::statusCounts()`. Default tab is `payment_review` (the queue that needs work), not `pending`. `$pageTitle` set in the view. |
| `shopOrder()` | — | **New.** The single-order fulfilment desk: header (`order_no`, buyer, total, method, reference); the **action bar generated from `ShopOrder::TRANSITIONS`** (each button a POST to `admin_order_transition` with `to=`); the timeline from `ShopOrderEvent::forOrder()`; the proof list with `admin_order_proof` thumbnails; the shipment block (`admin_order_shipment` form: courier, tracking, POD, fault, attempts); the refund block; and the address snapshot. |
| `orderTransition()` | — | **New, and the most important method in this plan.** `csrf_verify()` → `ShopOrder::assertNotSelfReview()` → `ShopOrder::transition((int)$_POST['order_id'], $_POST['to'], 'admin', Auth::id(), $opts)` → `flash()` → redirect to `/?page=admin_shop_order&id=…`. `$opts` collects `reason_code`, `tracking_number`, `courier`, `fault`, `fail_reason`, `pod_receiver`, `pod_image`, `expected_delivery_at`. **A `false` return means another actor won the race** → flash "This order just changed. Reload and try again." and redirect; never retry silently. |
| `orderHold()` | — | **New.** POST: `reason_code`, `hold_owner` (a `users.id`), `hold_deadline`. All three mandatory. |
| `orderResume()` | — | **New.** POST → `ShopOrder::resume()`. Refuses with a flash if `previous_status` is no longer reachable. |
| `orderProofView()` | — | **New.** Streams one `shop_payment_proofs` file through a PHP handler with `Auth::guard('admin')` + `readfile()`. Do **not** link proofs by direct URL — `.htaccess` serves `uploads/` as static files (line 55–58), so a guessed path would leak. Log the view in `shop_order_events` with `note = 'proof viewed'`. |
| `orderShipment()` | — | **New.** POST: courier / tracking / expected delivery / POD receiver / POD image (`uploads/shipments/`) / fault + fail reason. Writes the **active** shipment row only; never creates one implicitly. |
| `orderReship()` | — | **New.** POST → `ShopOrder::reship()`. UI copy states plainly that the shop absorbs the reship cost (§0.4), so nobody later "fixes" it by charging the buyer. |
| `orderRefund()` | — | **New.** POST: `amount`, `cause`, `destination`, `promised_at`, then `approve` / `mark_paid` (with a mandatory `receipt_ref`). Refuses `mark_paid` without one. |
| `shopExpire()` | — | **New.** POST → runs `ShopOrder::expireOverdue()` then `ShopOrder::autoComplete()`, flashes the counts. Mirrors `?page=admin_manual_reset` (line 331) exactly — the same "cron also runs on a schedule, but there is a button" pattern. |

**4.3 Edit `AdminController::saveSettings()`** (lines 520–569)

* Add `'shop_enabled'` to the `$allowed` array (ends line 552).
* Add it to the checkbox-toggle branch at line 559 so unchecking persists `'0'`:

```php
if (in_array($key, ['gcash_enabled','maya_enabled','reactivation_ewallet_enabled',
                    'reactivation_external_enabled','shop_enabled'], true)) { … }
```

* The five numeric shop keys (`shop_payment_deadline_hours`, `shop_correction_window_hours`,
  `shop_max_proof_attempts`, `shop_report_window_days`, `shop_max_delivery_attempts`) go in the numeric
  branch with `floatval()` + `max(1, …)`. **Clamp them in the controller**: a `0` payment deadline would
  make every order expire on arrival, and an unbounded attempt count would let a buyer loop proofs forever.

**4.4 No `savePackage()` change.** There is no `personal_pv_requirement` field and no product↔package link in
this scope. `models/Package.php` is **not edited at all** — that is a Phase 7 invariant, not an omission.

> **Gate 4:** `php -l controllers/MemberController.php controllers/AdminController.php`; all 25 route keys in
> the table (§6 V2); `git diff --stat models/Package.php core/Commission.php core/CapEngine.php
> models/CdStatus.php core/DailyFixedIncome.php core/Reactivation.php cron/midnight_reset.php` → **empty**.

---

### Phase 5 — Views + partials

Copy, then strip. **Every ported member view must be URL-normalized to `link_to()`** (§5.7).

| # | File | Source | Action |
|---|---|---|---|
| 5.1 | `views/partials/cart_offcanvas.php` | tmp (221 lines) | Copy → **delete all PV** (`data-unit-pv` line 57, the `Total PV` row lines 92–95, `updateFooter()` PV line 133/138) → **replace `Cart::getOrCreate()` (line 18) with `Cart::getActive()`** so merely loading a page never INSERTs a cart row for every logged-in user incl. admins; treat `null` as empty → `link_to('shop')` for the two "Browse Products" links (49, 199) and `link_to('update_cart_item')` / `link_to('remove_cart_item')` for the two `fetch()` URLs (161, 192) |
| 5.2 | `views/partials/footer.php` | live (16 lines) | **Edit** — add `<?php require __DIR__ . '/cart_offcanvas.php'; ?>` after the `app.js` include (line 11), before `<div id="toastContainer">`. Wrap in `<?php if (Auth::check()): ?>` — a logged-out visitor on `?page=login` must not get a cart drawer that would issue an AJAX POST with no session. |
| 5.3 | `views/partials/topbar.php` | live (104 lines) | **Edit** — insert tmp's 🛒 button + count badge **inside the existing `<div class="d-flex align-items-center gap-2">` (line 41) before line 89** (`if (Auth::isAdmin() … admin_settings`). The badge element **must** carry `class="badge"` and live inside `.topbar-wrapper`, because `cart_offcanvas.php`'s `updateTopbarBadge()` targets `.topbar-wrapper .badge`. **Revision 3 change:** wrap in `<?php if (Auth::check()): ?>`, **not** `if ($isMember)` — **admins see the cart too** (§0). |
| 5.4 | `views/partials/sidebar_member.php` | live (147 lines) | **Edit** — compute `$cartBadge = Cart::itemCountForMember($user['id'])` after line 13; add 3 entries after `'payout'` (line 56): `🛍️ Shop` → `shop`, `🧾 My Orders` → `shop_orders`, `🛒 Cart` → `cart` with `'badge' => $cartBadge`; **add badge rendering** to `renderSidebarNav()` — copy the markup of `sidebar_admin.php:37` (`<span class="nav-badge">…</span>`) inside the `<a>` at lines 100–104 |
| 5.5 | `views/partials/sidebar_admin.php` | live (123 lines) | **Edit** — add `$pendingShopOrders` (count of `status IN ('payment_review','payment_failed','paid','packing','ready_to_ship','delivery_failed')` — the **actionable** count, not just `payment_review`) next to lines 13–14; add a `Shop` section after the `Management` group (after line 47) with `admin_shop` (Products) and `admin_shop_orders` (Orders, with the `nav-badge` actionable count). Use `APP_URL . '/?page=…'` — matching the existing file's style, **not** `link_to()`. Extend `renderAdminNav()`'s signature with `$pendingShopOrders` and both call sites (110, 121). |
| 5.6 | `views/partials/rows_per_page.php` | tmp (22 lines) | Copy → depends on `per_page()` (Phase 3). **Do not retro-fit the 10 existing views in this phase** |
| 5.7 | `views/partials/order_status_badge.php` | **new** (~8 lines) | `<?= shop_status_badge($order['status'], $order['created_at']) ?>` plus an optional `<small>` of the timestamp for that status. Included by 4 views; it is the single reason the labels cannot drift between the member and admin pages. |
| 5.8 | `views/partials/shop_order_timeline.php` | **new** (~45 lines) | Renders `ShopOrderEvent::forOrder($orderId)` as a vertical timeline: dot, `from → to` in plain language, actor (`You` / `Admin` / `System`), reason code, relative time. **Shared by the member detail page and the admin desk**, so a buyer and staff read the same history. Guard every field with `e()`; `payload` is **not** rendered — only offered to admins inside a `<details>` block. |
| 5.9 | `views/member/shop.php` | tmp `views/member/repeat_purchases.php` lines 1–122 + 205–265 | Copy → `$pageTitle = 'Shop'`; h4 `Shop`; subtitle **"Browse our products and order for store pickup."**; **delete** `$effPv` / `$pvFmt` (67–68), `data-pv` (81, 89), the `PV Value` card row (100–103), the `PV Value` modal row (220–223) and `productModalPv` JS (245); `link_to()` for the form action (111) and everything else; add a `🛒 View Cart` / `link_to('cart')` button in the header row (53–58) |
| 5.10 | `views/member/shop_orders.php` | tmp `views/member/repeat_purchases.php` lines 1–13 + 124–203 | **New file.** `$pageTitle = 'My Orders'`; h5 `My Orders`; **delete** the `Total PV` column (135) and its `<td>` (172); header cell count 6 → 6 (an `Order #` column replaces the PV one). **Revision 3 changes:** the plain `Status` cell becomes `order_status_badge.php`; each row links to `link_to('shop_order', ['id' => $o['id']])`; rows in `pending`/`payment_failed` show inline "Upload proof" / "Cancel" buttons, rows in `delivered` show "Confirm receipt" — each rendered via `ShopOrder::canMemberAct()`; `pagination_links($history, link_to('shop_orders', ['per_page' => per_page()]))` |
| 5.11 | `views/member/shop_order.php` | **new** (~180 lines) | **The buyer's order page.** Header: `order_no`, `shop_status_badge()`, total, payment method + reference. Timeline partial. **Exactly one** status-specific panel renders: `pending` → "Upload your proof" form (reference / amount / date / file) + the `confirm_lock` checkbox; `payment_review` → "We're verifying your payment" + the review SLA; `payment_failed` → reason code + "Re-upload" / "Cancel order" + the correction deadline; `paid`/`packing`/`ready_to_ship` → "We're preparing your order"; `shipped`/`out_for_delivery` → courier + tracking + expected date; `delivery_failed` → reason + "Confirm you'll be available" + the phone/address correction prompt; `returned_to_sender` → explanation + the response deadline; `delivered` → POD summary + "Confirm receipt" (copy: *this ends reporting*) + the report-window end date; `on_hold` → reason + owner + deadline; `completed`/`cancelled` → closed notice. Then the shipment block, the address snapshot, and the refund block if any. |
| 5.12 | `views/member/cart.php` | tmp (387 lines) | Copy → **namespace the 5 surviving colliding DOM IDs** (§5.1) → `link_to()` → **delete all PV** (`$totalPv` line 8, `data-unit-pv` 184, the per-line PV span 199, the mobile PV div 234, the `Total PV earned` summary row 263–264, the JS PV write 327/331, and the "PV is finalized at checkout" / "PV credits to your binary leg" notes 278/292 — replace with "Prices are confirmed at checkout.") |
| 5.13 | `views/member/checkout.php` | tmp (330 lines) | Copy → `link_to()` for the form action (30) and back links (25, 198) → **delete the Binary Position card (82–106)**, its `.binary-option` CSS (211–241), and its JS (252, 275–288) → **delete all PV** (64, 72–73, 176–177). **Add:** the address block (recipient name, phone, line1, line2, city, province, postal code, notes) with `csrf_field()`; the terms checkbox (`value="v1"`, copy covering the cancel rule, no-refund-after-payment, and the shop's right to decline); a hidden `idem` input seeded with `uniqid()` for the idempotency key; a **"no fees"** note under the total — *"Total is what you pay. No shipping or service fee."* — so the absence of a fee line is deliberate on the page rather than looking like a bug. |
| 5.14 | `views/admin/shop.php` | tmp `views/admin/products.php` (381 lines) | Copy → `$pageTitle = 'Shop Products'` at line 8 (live convention); h4 `Shop Products`; subtitle "Manage the storefront catalog". **Update the help text** to describe the new stock model: "Stock is absolute inventory. It is deducted automatically when an order is handed to the courier; adjust it here for deliveries, write-offs, and recounts." — rev 2's "availability never changes on approval" copy is now **wrong**. **delete** the `Product PV` / `PV %` / `Eff. PV` table headers (61–63) + cells (94–96), the `product_pv` + `pv_value` inputs (169–178), the whole Unilevel block (203–224) and its JS (268–269, 276, 318–372) — including `const pvPerPesoRate = <?= … ?>`, the last `pv_per_peso_rate` reference in the codebase; add the `sku` input; `link_to()` for all 13 links/actions (`?edit=`, `admin_delete_product`, `admin_save_product`) |
| 5.15 | `views/admin/shop_orders.php` | tmp `views/admin/repeat_purchases.php` (470 lines) | Copy → `$pageTitle = 'Shop Orders'`; h4 `Shop Orders`; subtitle "Verify payments, pick and pack, hand off to the courier". **delete** `.order-amount .pv` CSS (64), the "PV This Page" stat (149–150), the per-order PV line (240), and the "No PV will be distributed" / "Approve Order & Distribute PV" confirm copy (323, 335–336, 348). **Revision 3 changes:** the 4 tab links become 14 + `all`, generated from `ShopOrder::STATUSES` with live counts; each row renders `order_status_badge.php` plus a **next-action hint** computed from the matrix (e.g. `paid → "Start packing"`); the three legacy POST forms are replaced by one form posting to `admin_order_transition`; `link_to()` for tabs, the form action, and pagination |
| 5.16 | `views/admin/shop_order.php` | **new** (~260 lines) | **The fulfilment desk.** Header (`order_no`, buyer, total, method, reference, copy button via `copyText`). Action bar generated from `ShopOrder::TRANSITIONS`: for the current status, list the legal targets; each is a POST to `admin_order_transition` with `to=`, wrapped in the local `#actionConfirmModal` (§5.2) so irreversible legs (cancel, mark lost, reship) confirm first. Extra blocks: **Hold** form (reason + owner select + deadline) and a **Resume** button when `on_hold`; **Proofs** list with thumbnails linking to `admin_order_proof`, each with Verify / Reject (Reject requires a reason code); **Shipment** form (courier, tracking, expected date, POD receiver, POD image upload, fail reason + fault select, attempts read-only); **Reship** button when `returned_to_sender`; **Refunds** table (amount, cause, state, receipt) with Approve / Mark-paid buttons; **Admin note** textarea; the shared timeline partial plus a `<details>` raw-`payload` viewer. |
| 5.17 | `views/admin/settings.php` | live (478 lines) | **Edit** — add a `shop_enabled` checkbox into **TAB 1 — SITE BASICS**, as a new `<div class="mb-3">` between the Contact Email block (56–59) and the Minimum Payout block (60–64), mirroring the markup style of `reactivation_ewallet_enabled` (line 141): label "Enable Shop", help text "Members and staff can browse the catalog and place orders". **Add a new sub-block** for the five numeric lifecycle settings (TAB 1, directly below) as a 2-column input grid, each with inline help text naming the workflow rule it drives. |

`link_to()` conversion rule for ported **member** views and JS:

| tmp/altas | altoslive |
|---|---|
| `<?= APP_URL ?>/?page=add_to_cart` | `<?= link_to('add_to_cart') ?>` |
| `<?= APP_URL ?>/?page=repeat_purchases` | `<?= link_to('shop') ?>` |
| `<?= APP_URL ?>/?page=repeat_purchases&cart=1` | `<?= link_to('shop', ['cart' => 1]) ?>` |
| `'<?= APP_URL ?>/?page=update_cart_item'` (JS fetch) | `'<?= link_to('update_cart_item') ?>'` |
| `APP_URL . '/?page=repeat_purchases&per_page=' . per_page()` | `link_to('shop_orders', ['per_page' => per_page()])` |

Admin **views** (`views/admin/shop*.php`) use `link_to()` too — the admin sidebar's `APP_URL` style is a
sidebar-only quirk, not a view convention. Justification: if a superadmin ever S-Logs into a member and
lands on a URL rendered by an admin view, `link_to()` keeps the `imp=` token; the admin sidebar is never
rendered inside an impersonated session. **Exception:** `views/partials/sidebar_admin.php` keeps its
existing `APP_URL` style (§5.5).

> **Gate 5:** `php -l` on all 12 new views + the 5 edited views/partials (`footer`, `topbar`,
> `sidebar_member`, `sidebar_admin`, `admin/settings`). Then two greps:
>
> *Across the whole `views/` tree* (all are 0 hits in live today, so any hit is a regression):
> `APP_URL \?>/\?page=` **in the 7 ported shop files only**, `repeat_purchase`, `Repeat Purchase`,
> `product_pv`, `pv_value`, `unit_pv`, `total_pv`, `\bPV\b`, `product_unilevel`, `unilevelProductBonus`.
>
> *Scoped to the 7 ported shop files + 3 models* (bare `binary_position` / `unilevel` are legitimate
> elsewhere — see Gate 2):
> `binary_position`, `unilevel`, `Royalty`.

---

### Phase 6 — Cron + expiry hook

**6.1 Create `cron/shop_expiry.php`** (~90 lines, new file — clone `cron/midnight_reset.php`'s preamble
exactly: `date_default_timezone_set('Asia/Manila')`, `require_once … config/db.php`, `require_once …
core/helpers.php`, the same `spl_autoload_register()` closure over `['models/','controllers/']`).

Jobs, each a conditional transition through the model (never a raw `UPDATE`):

1. `ShopOrder::expireOverdue(200)` — `pending`/`payment_failed` past their deadline → `cancelled`, reason
   `payment_expired`, lines → `released`. **Never** selects `payment_review`.
2. `ShopOrder::autoComplete(200)` — `delivered` past `completion_due_at` with no open refund → `completed`.
3. Expire stale holds? **No** — a hold without a resolution is an admin decision, not a timer decision. The
   admin dashboard surfaces overdue holds instead (§5.10).

Every event row gets `source = 'timer'`, `actor_type = 'system'`. Log one summary line per run in the same
style as `cron/midnight_reset.php`'s log file.

**Crontab entry** (documented here, installed on the server — not a repo change):

```bash
*/15 * * * * /usr/bin/php /var/www/html/altaslive/cron/shop_expiry.php >> /.../cron/logs/shop_expiry_$(date +\%Y-\%m).log 2>&1
```

Every 15 minutes, because the shortest deadline it acts on is the 24-hour correction window and
`payment_deadline` is hours-scale. **Do not** fold this into `cron/midnight_reset.php` — that file is
byte-frozen by the §7 contract, and mixing an hourly job into a midnight job means one bug takes out both.

**6.2 `.htaccess` — no change needed.** `cron/` is already blocked for direct web access
(`.htaccess:66` `RewriteRule ^(config|core|models|controllers|cron|logs)/ - [F,L]`), so the new script is
CLI-only. Do **not** add a `<Files>` exception for it (unlike `midnight_reset.php`/`test_cron.php`, which are
web-triggerable fallbacks). If a web fallback is ever wanted, mirror the `midnight_reset.php` block
(`.htaccess:26–33`) exactly — localhost-only plus a cron key — and treat it as a separate change.

**6.3 Update `AGENTS.md`** (local-only; `AGENTS.md` is gitignored — see the note in that file's own footer).
Add `cron/shop_expiry.php` to the Architecture tree and the cron example block. Nothing to commit.

> **Gate 6:** `php -l cron/shop_expiry.php`; `git diff --numstat cron/midnight_reset.php cron/fund_transfer_limit_reset.php`
> → `0 0`; run the script once against a DB with one deliberately overdue `pending` order and assert the
> order is `cancelled`, its lines are `released`, and exactly one `shop_order_events` row exists with
> `source='timer'`.

---

### Phase 7 — Engine-safety verification (replaces the old "commission updates" phase)

**Rule: zero engine changes.** Unlike earlier revisions, there is nothing to *add* to `core/Commission.php`.
This phase exists purely to prove that.

| File | Expected diff | Why it must be zero |
|---|---|---|
| `core/Commission.php` | **empty** | No shop code path may enter the engine. `ShopOrder::transition()` performs no distribution. |
| `models/Package.php` | **empty** | No product package linkage, no PV requirement. |
| `core/CapEngine.php`, `models/CdStatus.php` | **empty** | No CD fill, no cap check on shop money. |
| `core/DailyFixedIncome.php`, `core/Reactivation.php` | **empty** | Untouched. |
| `install.sql` — `packages`, `users`, `commissions`, `cd_ledger` blocks | **empty** | No new columns, no new ENUM values. |
| `cron/midnight_reset.php`, `cron/fund_transfer_limit_reset.php` | **empty** | Shop volume never touches `pairs_paid_today` / `pairs_volume_today` / `lifetime_earned`, so the midnight job needs no change. The new expiry job is a **separate file** (Phase 6). |
| `ewallet_ledger` ENUM | **+ `'shop_order'`** | The one intentional ENUM change (Phase 1.1 step 10 / 1.2). |
| `models/Ewallet.php` | **empty** | `debitInternal()` already accepts an arbitrary `refType`; no method needs a new case. |

The one place the engine *is* touched is money **out of** a buyer's wallet
(`Ewallet::debitInternal(..., 'shop_order', ...)`) and money **back** via `Ewallet::credit(..., 'shop_order', ...)`
on a refund — ordinary ledger entries, not commissions.

> **Gate 7:**
> ```powershell
> git diff --numstat core/Commission.php core/CapEngine.php core/Auth.php core/DailyFixedIncome.php core/Reactivation.php models/Package.php models/CdStatus.php models/Ewallet.php cron/midnight_reset.php cron/fund_transfer_limit_reset.php
> # every line must read "0<TAB>0<TAB>path"
> git diff install.sql | Select-String -Pattern "personal_pv|group_pv|product_unilevel"
> # must be empty
> Select-String -Path core,models,controllers,views -Recurse -Pattern "processProductPV|processProductUnilevel|processBinaryVolume|meetsPersonalPvRequirement|pv_per_peso_rate"
> # must be 0 hits
> ```

---

### Phase 8 — QA + smoke tests

**8.1 Static checks** — run the §6 commands.

**8.2 Unit-ish SQL checks**

```sql
-- enum parity after migration
SHOW COLUMNS FROM ewallet_ledger  LIKE 'ref_type';    -- must include 'shop_order'
SHOW COLUMNS FROM shop_orders     LIKE 'status';      -- must be 14 values, exactly §0.5
SHOW COLUMNS FROM shop_orders     LIKE '%_by';        -- must be 7 rows: paid/approved/packed/shipped/delivered/completed/cancelled (+ hold_owner)
SHOW COLUMNS FROM shop_orders     LIKE '%_deadline';  -- payment_deadline, correction_deadline, hold_deadline
SHOW COLUMNS FROM shop_orders     LIKE '%due_at';     -- completion_due_at
SHOW COLUMNS FROM shop_orders     LIKE 'reviewed%';   -- must be 0 rows
SHOW COLUMNS FROM commissions     LIKE 'type';        -- must be UNCHANGED (4 values)
SHOW COLUMNS FROM cd_ledger       LIKE 'type';        -- must be UNCHANGED (3 values)
SHOW COLUMNS FROM users           LIKE '%_pv';        -- must be 0 rows
SHOW COLUMNS FROM packages        LIKE 'personal_pv%';-- must be 0 rows
SHOW TABLES LIKE 'product_unilevel_levels';           -- must be 0 rows
SHOW TABLES LIKE 'shop_%';                           -- must list exactly the 7 shop tables
SELECT key_name, value FROM settings WHERE key_name LIKE 'shop_%' ORDER BY key_name;  -- 6 rows
-- inert PV columns exist but are never written
SELECT COUNT(*) FROM shop_orders WHERE total_pv <> 0;        -- must be 0
SELECT COUNT(*) FROM shop_order_items WHERE unit_pv <> 0;    -- must be 0
SELECT COUNT(*) FROM products WHERE product_pv <> 0 OR pv_value <> 0;  -- must be 0
```

**8.3 Manual smoke matrix** (buyer = paid account; admin = `admin`/`Admin@1234`)

Catalog + cart:

| # | Step | Expected |
|---|---|---|
| S1 | Admin → `?page=admin_shop` → Create product (name, sku, price 500, stock 5, upload JPG, short + long description) | Row appears; file lands in `uploads/products/product_new_<ts>.jpg`; **no** PV/unilevel inputs exist on the form |
| S2 | Edit that product, change price, save with no new file | Old image preserved; no duplicate file |
| S3 | Check "Remove current image" + save | DB `image_url` NULL **and** file deleted from disk |
| S4 | Delete a product that has order history | Flash: "Cannot delete product: it has existing shop orders." |
| S5 | **Admin (not a member) opens `?page=shop`** | Catalog renders; the 🛒 button and Cart sidebar entry are visible (**rev 2 hid these**) |
| S6 | Admin → `?page=cart` → stepper `+` / `−`, then `✕ Remove` | Works identically to a member; badge updates; exactly one POST per action; **no duplicate-ID console errors** |
| S7 | Offcanvas on an unrelated page (`?page=dashboard`) → qty `+` | Offcanvas totals update; page totals untouched |
| S8 | Member → `?page=shop` → Add to Cart qty 2 | Redirect to `&cart=1`; drawer auto-opens; topbar badge = 2; sidebar Cart badge = 2 |
| S9 | Admin changes a product price **after** it sits in a cart, then checks out | Order uses the **new** price (`Cart::refreshPrices()`), not the stale cart price |

Payment + the state machine:

| # | Step | Expected |
|---|---|---|
| S10 | `?page=checkout` → e-wallet with sufficient balance | Order `paid`; `paid_at` set; `ewallet_balance` reduced; `withdrawable_balance` reduced **only** by the portion drawn from withdrawable funds; exactly one `ewallet_ledger` row `ref_type='shop_order'`; **no** `shop_payment_proofs` row |
| S11 | **Regression assertion:** after S10 | `SELECT COUNT(*) FROM commissions WHERE created_at > NOW() - INTERVAL 5 MINUTE` = **0**; `cd_ledger` unchanged; `pairs_volume_paid` / `lifetime_earned` / `left_pair_volume` / `right_pair_volume` unchanged for buyer *and* every upline |
| S12 | Order with GCash → place | Order `pending`; `payment_deadline` set to +24h; flash asks for proof; line counted by `Product::reservedStock()` |
| S13 | Upload proof with the `confirm_lock` box ticked | Order `payment_review`; `shop_payment_proofs` row `attempt_no=1` `outcome='pending'`; **Cancel button disappears** on both pages |
| S14 | **Direct API call** to `shop_cancel_order` on a `payment_review` order | Refused with a flash — the guard is server-side, not just a hidden button |
| S15 | Admin verifies (`to=paid`) with `amount_sent` ≠ `total_price` | Refused; flash names both figures |
| S16 | Admin verifies with the reference already used by another order | Refused; flash "Reference already used" |
| S17 | Admin rejects the proof with reason `amount_short` | Order `payment_failed`; `correction_deadline` = +24h; proof row `outcome='rejected'`; re-upload enabled |
| S18 | Buyer re-uploads (attempt 2), then attempt 3 | Accepted up to `shop_max_proof_attempts`; on the cap the order moves to `on_hold` with reason `proof_attempts_exhausted` |
| S19 | Admin → `?page=admin_shop_order` → action bar on a `payment_review` order | Only the legal targets are rendered: **Verify payment** / **Reject proof** / **Hold** / **Cancel** |
| S20 | Advance `pending → payment_review → paid → packing → ready_to_ship` | Each step writes one `shop_order_events` row; `paid_by`, `approved_by`, `packed_by` populated; lines go `reserved → committed` |
| S21 | `ready_to_ship → shipped` with tracking + courier | Shipment row created (`seq=1`, `state='active'`, `handoff_at`); `products.stock` drops 5 → 3; lines → `deducted`; `shipped_at`/`shipped_by` set |
| S22 | Try `shipped` again, or call `admin_order_transition?to=ready_to_ship` on a `paid` order | Refused — the matrix is enforced server-side, and the conditional `UPDATE` returns "someone else won" |
| S23 | `shipped → out_for_delivery → delivery_failed` (fault `customer`, reason `recipient_unavailable`) | `attempts` = 1; fault recorded; member page shows the reason + the "confirm availability" prompt |
| S24 | `delivery_failed → out_for_delivery` twice more | Refused at the 3rd attempt (`shop_max_delivery_attempts`); `response_deadline` set |
| S25 | `→ returned_to_sender` | Active shipment `state='closed'`; `response_deadline` = +10d; lines stay `deducted` |
| S26 | Admin → **Reship** | New shipment `seq=2`, `reship_of=<old id>`, `attempts=0`; order back to `packing`; lines `deducted → reserved`; `products.stock` **unchanged** (goods reused); **no** fee charged, **no** refund row |
| S27 | Admin marks `delivered` **without** a POD field | Refused |
| S28 | Mark `delivered` with a receiver name | Order `delivered`; `completion_due_at` = +5d; `report_window_end` mirrored on the shipment |
| S29 | Buyer presses **Confirm receipt** | Order `completed`; `completed_at`/`completed_by` set; **terminal** — every further transition is refused |
| S30 | Open a `shop_refunds` row (`state='open'`) on a `delivered` order, then Confirm receipt | Refused: "a refund is still open on this order" |
| S31 | Admin places an order, then tries to verify it | `ShopOrder::assertNotSelfReview()` refuses — admins can shop but cannot self-approve (§5.9) |

Holds, expiry, cron:

| # | Step | Expected |
|---|---|---|
| S32 | Admin → Hold on a `paid` order with reason `stock_shortage`, owner, deadline | Order `on_hold`; `previous_status='paid'`; timers effectively paused; **Cancel** still available (it follows `previous_status`) |
| S33 | Hold with **no owner** or **no deadline** | Form refuses; no hold created |
| S34 | Resume | Status returns to `paid`; hold fields cleared; one event row |
| S35 | Set a `pending` order's `payment_deadline` to the past, run `?page=admin_shop_expire` | Order `cancelled` (reason `payment_expired`); lines → `released`; one event with `source='timer'` |
| S36 | Put a `payment_review` order's `payment_deadline` in the past, run the expiry job | Order **stays** `payment_review` — T7 must never fire from review |
| S37 | Upload a proof in one tab while the expiry job runs | Exactly one outcome wins (conditional write), never both |
| S38 | Run `cron/shop_expiry.php` twice in a row | Second run is a no-op for already-excluded statuses |

Reservations, isolation, regression:

| # | Step | Expected |
|---|---|---|
| S39 | Reject/cancel an order, then check the product's availability | Availability **increases** by that quantity (reservation released) |
| S40 | Reserve all 5 units with pending orders, then try to add the 6th | "Insufficient stock. Requested 6, only 0 available."; button disabled on the catalog grid |
| S41 | Reserve all 5, then admin ships a different order for 5 | `shipped` is **refused** if any per-line `stock >= qty` check fails; no partial deduction is committed |
| S42 | Set `shop_enabled = '0'`, reload `?page=shop`, `add_to_cart`, `checkout`, `shop_submit_proof` | All flash "The shop is currently closed." and redirect to the dashboard; `?page=shop_orders` and `?page=shop_order` still work |
| S43 | Set `shop_payment_deadline_hours = 0` in admin settings | Clamped to `1` — never persisted as `0` |
| S44 | Super-Login (`sadmin` → impersonate member) then open `?page=shop` / `?page=cart` / `?page=shop_order` / add to cart / upload proof | `imp=` token preserved on every nav link **and** every JS `fetch()` — if it drops, S-Login escapes to a normal session |
| S45 | Rows-per-page on `?page=admin_shop_orders&per_page=25` | 25 rows; tab/`status` params survive; `pg` resets to 1 |
| S46 | Full regression: register a member, confirm pairing fires, CD bucket drains, DFI runs on manual reset | Identical to pre-change behaviour |
| S47 | `git diff --numstat` on the engine files | `0 0` on every one |

**8.4 Rollback**

```
git checkout -- core/ controllers/ views/ models/ index.php install.sql
git rm migrate_shop.sql cron/shop_expiry.php
```
plus DB (**take a `mysqldump` before Phase 1**):
```sql
DROP TABLE IF EXISTS shop_order_events, shop_payment_proofs, shop_refunds, shop_shipments,
                       shop_order_items, cart_items, carts, shop_orders, products;
ALTER TABLE ewallet_ledger MODIFY COLUMN ref_type
  ENUM('commission','payout','reactivation','transfer','topup','registration') NULL;
DELETE FROM settings WHERE key_name LIKE 'shop\_%';
rmdir uploads/shop_proofs uploads/products uploads/shipments 2>/dev/null;
```
If rev 2 had been deployed, reverse §2.5 first (`ALTER … MODIFY status ENUM('pending','paid','approved','rejected','cancelled')` with the reverse `UPDATE`s) — dropping the tables makes that moot, so in practice rev-2 rollback is just the `DROP`s.

**8.5 Optional follow-ups (separate commits, NOT in this plan)**

1. Credit shop revenue to a designated account on `paid` — `Ewallet::credit($revenueUserId, $total, $orderId, 'shop_order', …)`. Currently the money leaves the buyer's wallet with no destination account (known accounting gap, §9).
2. Problem reports (`shop_reports` + T22/T23) — the only route by which a "delivered but never received" parcel surfaces before completion.
3. Partial / multi-parcel shipments — `shop_shipments` already supports it; only the status derivation is missing.
4. Courier integration — `shop_shipments.carrier_last_event_at` and a `shop_shipment_events` table are the landing spots; manual entry stays as the fallback (the guide requires a manual fallback regardless).
5. Refactor `savePackage()` / `Reactivation` to use `upload_image()`.
6. Replace the 10 duplicated inline rows-per-page forms with `rows_per_page.php`.
7. Product variants, categories/search, guest checkout, coupons, invoice PDF.
8. A full PV engine migration (`package_pv_rate`, `pv_per_peso_rate`, `pv_transactions`, unilevel, royalty) — a separate multi-day project. It would require its own migration plan for existing balances and **would invalidate strategy C entirely**. Not a continuation of this plan.

---

## 4. File manifest (every file touched)

**Create (21)**

```
migrate_shop.sql
cron/shop_expiry.php
models/Product.php
models/Cart.php
models/ShopOrder.php
models/ShopOrderEvent.php
models/ShopShipment.php
models/ShopPaymentProof.php
models/ShopRefund.php
views/partials/cart_offcanvas.php
views/partials/rows_per_page.php
views/partials/order_status_badge.php
views/partials/shop_order_timeline.php
views/member/shop.php
views/member/shop_orders.php
views/member/shop_order.php
views/member/cart.php
views/member/checkout.php
views/admin/shop.php
views/admin/shop_orders.php
views/admin/shop_order.php
```

**Edit (10)**

```
install.sql                       1.2   (2 CREATE blocks, 1 ENUM, 6 setting seeds)
index.php                         3.3   (2 insert points: after 314, after 342 — 25 new routes)
core/helpers.php                  3.1   (7 inserts: after 346, after 377 ×5)
controllers/MemberController.php  4.1   (insert 12 methods at 132/134)
controllers/AdminController.php   4.2/4.3 (insert 13 methods at 421/423; $allowed 525–552; checkbox list 559)
views/partials/footer.php         5.2   (insert at line 11)
views/partials/topbar.php         5.3   (insert inside line 41, before line 89)
views/partials/sidebar_member.php 5.4   (nav + badge render)
views/partials/sidebar_admin.php  5.5   (nav + actionable badge)
views/admin/settings.php          5.17  (1 checkbox + 5 numeric inputs in TAB 1)
```

**Local-only (not committed — `AGENTS.md` is gitignored):** `AGENTS.md` cron + architecture notes (Phase 6.3).

> 10 files are edited, 21 are created. The list above is authoritative.

**Must not change (Phase 7 gate):** `core/Commission.php`, `core/CapEngine.php`, `core/Auth.php`,
`core/DailyFixedIncome.php`, `core/Reactivation.php`, `models/Package.php`, `models/CdStatus.php`,
`models/{Code,Ewallet,ImpLog,Payout,User}.php`, `cron/midnight_reset.php`,
`cron/fund_transfer_limit_reset.php`, `.htaccess`, `assets/**`, `frontend/**`,
`index.php` maintenance / seat-limit blocks (lines 47–270).

---

## 5. Critical parity points

### 5.1 Duplicate DOM IDs — `cart.php` vs `cart_offcanvas.php` (MUST FIX)

`views/partials/footer.php` includes the offcanvas **globally**, and `MemberController::cart()` also renders
`head → member/cart.php → footer`. So `?page=cart` emits **two** elements for each of these IDs (tmp has
**six** collisions; strategy C deletes `cartTotalPv`, leaving **five**):

| ID | `cart.php` (tmp) | `cart_offcanvas.php` | Effect |
|---|---|---|---|
| `cartItemsContainer` | line 165 | line 52 | the page's `MutationObserver` (`cart.php:384`) observes the *page* list; the offcanvas's empty-state rewrite (line 197) hits the wrong node |
| `cartFooter` | line 252 | line 87 | the offcanvas's `footer.remove()` (line 202) kills the page summary column |
| `cartItemsCount` | line 259 | line 89 | `getElementById` returns the **page** node → offcanvas badge never updates |
| `cartSubtotal` | line 260 | line 90 | ditto |
| `cartTotalPv` | line 264 | line 94 | **gone** — the PV row is deleted in both files (§5.12), so this row no longer collides |
| `cartTotalPrice` | line 271 | line 98 | ditto |

Fix (namespace the page, leave the global script alone): in the ported `views/member/cart.php` rename
`cartItemsContainer`→`cartPageItems`, `cartFooter`→`cartPageFooter`, `cartItemsCount`→`cartPageItemsCount`,
`cartSubtotal`→`cartPageSubtotal`, `cartTotalPrice`→`cartPageTotalPrice`, and update the inline `<script>`
references plus the observer. **`cartTotalPv` needs no rename — delete it** (§5.12). Verify with the
duplicate-ID grep in §6 (V3), which expects **0** un-namespaced hits in `cart.php` and **5** in the offcanvas.

### 5.2 `Product::reservedStock()` — why there are two filters

`shop_order_items.reservation_state IN ('reserved','committed')` is the contract; the `shop_orders.status IN
(...)` list is a defensive secondary filter. Both exist on purpose:

* The reservation state alone would count a stale `reserved` row left behind by a bug on a cancelled order,
  permanently shrinking availability. The status filter makes that harmless.
* The status filter alone would need auditing every time a status is added or renamed. The reservation state
  does not change when the machine grows.

Leave the one-line comment in `Product::reservedStock()` saying exactly this, or a future reader will
"simplify" one away and re-open the bug. `on_hold` is in the status list because it inherits its
`previous_status`'s behaviour; a hold whose `previous_status` is a post-handoff state is a hold on a
shipped parcel, which holds no stock — the *reservation state* on its lines is already `deducted`, so the
sum is correct regardless.

### 5.3 `admin/shop_order.php` — one endpoint, not four

There is exactly one POST endpoint for status changes: `admin_order_transition`, carrying `to=`. The action
bar is generated from `ShopOrder::TRANSITIONS`:

```php
foreach (ShopOrder::TRANSITIONS as $to => $rule) {
    if (!in_array($effectiveStatus, $rule['from'], true)) continue;
    if (!in_array('admin', $rule['actors'], true)) continue;
    // render a button: <form method="post" action="<?= link_to('admin_order_transition') ?>">
    //   <input type="hidden" name="csrf_token" …> <input type="hidden" name="to" value="<?= $to ?>">
    //   <input type="hidden" name="order_id" value="…"> <button …><?= shop_status_label($to) ?></button>
}
```

Consequences worth stating: the action bar can never show an illegal transition (it is generated from the
same const the guard reads); adding a status means one matrix row, not one route plus one handler plus one
form; and `AdminController::orderTransition()` is the only place an admin can move an order. Legacy rev-2
buttons (`Mark as Paid`, `Approve`, `Reject`) are **deleted**, not ported.

### 5.4 Actor-column map — which `*_by` fires on which target

One source of truth, `ShopOrder`'s private const, consumed by `transition()` step 7:

| Target status | `*_by` / `*_at` pair | Note |
|---|---|---|
| `payment_review` | *(none)* | customer upload; the proof row's `uploaded_by` is the actor |
| `payment_failed` | *(none)* | the proof row's `reviewed_by` is the actor |
| `paid` | `paid_by` / `paid_at` | e-wallet auto-path leaves `paid_by` NULL (`actor_type='system'`) |
| `packing` | `approved_by` / `approved_at` | this is rev 2's `approved`, repurposed as the fulfilment-release gate |
| `ready_to_ship` | `packed_by` / `packed_at` | |
| `shipped` | `shipped_by` / `shipped_at` | also the stock-deduction moment |
| `delivered` | `delivered_by` / `delivered_at` | |
| `completed` | `completed_by` / `completed_at` | |
| `cancelled` | `cancelled_by` / `cancelled_at` | customer/system paths leave `cancelled_by` NULL |
| `on_hold` / `payment_review` (revert) | *(none)* | the reason code + event row carry the actor |

### 5.5 Confirm-modal: local vs global

* **Global**: `assets/js/app.js:39` `showConfirm(opts)` — already present and identical in live. Opts: `title, message, confirmText, confirmClass, formId, onConfirm`.
* **Local**: tmp `views/admin/repeat_purchases.php:383–470` ships its own `#actionConfirmModal` driven by `data-confirm-title/-message/-btn-text/-btn-class` + `.confirm-action-form` + a `show.bs.modal` interceptor.

Both work; they are simply **two different confirm systems in one codebase**. Ship the local modal as-is in
`views/admin/shop_order.php` (it is self-contained and doesn't touch `app.js`) and note in code review that a
follow-up should unify on `showConfirm({... onConfirm: () => form.submit()})`. tmp's `views/admin/products.php:114`
uses a **native** `onsubmit="return confirm('Delete this product?')"` — a third style. Do not unify in this pass.

The new single-endpoint action bar makes the local modal *more* valuable, not less: every irreversible leg
(cancel, mark lost, reship) goes through the same generated form and therefore the same confirm path.

### 5.6 `?cart=1` auto-open

`MemberController::addToCart()` redirects to `/?page=shop&cart=1`; `cart_offcanvas.php:210–219` reads
`params.get('cart')==='1'` after `bootstrap` loads, calls `bootstrap.Offcanvas.getOrCreateInstance(cartEl).show()`,
then strips `cart` from the URL via `history.replaceState`. Keep both halves — a redirect without the JS is a
silent no-op, and the JS without the redirect never fires. Also keep the `location.pathname + '?' + … +
location.hash` rebuild intact: with impersonation the pathname stays `/altaslive/` and `imp=` survives in the
query string.

### 5.7 `link_to()` conversion rule

Defined in Phase 5 and repeated here because it is the single most likely thing to miss when copying a view:
**every ported member URL and every JS `fetch()` URL** goes through `link_to()`. `link_to()` (`helpers.php:158`)
re-adds `imp=` when `is_imp_session()`. Missing one breaks Super-Login silently — the link still works, but
drops the impersonation and lands the superadmin in their own session.

Admin **views** also use `link_to()`; only `views/partials/sidebar_admin.php` keeps its pre-existing
`APP_URL . '/?page=…'` style, because that is how the whole file already works (see Phase 5.5).

### 5.8 Admins-can-shop — the four things that must change

Rev 2 assumed admins would see the catalog but not the cart. Revision 3 makes admins first-class buyers:

1. **Routes stay `'member'`.** `Auth::guard('member')` (`core/Auth.php:233`) admits any session; adding an
   `Auth::isAdmin()` exclusion is the one change that would break this. Do not add one.
2. **`topbar.php` guard changes** from `if ($isMember)` to `if (Auth::check())` (Phase 5.3). The cart button
   and badge must render for admins.
3. **`sidebar_member.php` is irrelevant to admins** — they use `sidebar_admin.php`. The admin sidebar already
   gains the Shop section in Phase 5.5; add `🛍️ View Storefront` there too, pointing at `link_to('shop')`,
   so an admin has a route into their own cart without hand-typing the URL.
4. **`cart_offcanvas.php` uses `Cart::getActive()`**, not `getOrCreate()` (Phase 5.1) — otherwise every admin
   page view INSERTs an empty `carts` row.

E-wallet purchases by staff use `Ewallet::debitInternal()` exactly like a member's; the ledger records the
buyer's own `user_id`. Nothing in `Ewallet` needs to know the buyer is staff.

### 5.9 Segregation of duties — an admin may buy but may not self-approve

`ShopOrder::assertNotSelfReview()` refuses any admin transition when `$order['member_id'] === Auth::id()`.
This is the one place "admins can shop" needs a guard: without it, the admin who places an order is also the
admin who verifies the payment, packs it, ships it, and completes it — a single person moving an order
through all seven actor columns and an unbounded refund. The guard is in the model, so every entry point
(UI, cron, future API) gets it.

Two further consequences of "admins can shop" to keep in mind:

* The admin orders list must not hide an admin's own order from them (they need to see its status); it must
  only hide the **action buttons**. Render the row, suppress the actions.
* `?page=admin_shop_orders&status=…` tabs driven by `statusCounts()` include staff orders. That is correct
  and desirable — the *actionable* badge count (Phase 5.5) should still exclude a self-reviewable order
  so the badge reflects real work, or it will sit at 1 forever on an admin's own order.

### 5.10 Where each timer surfaces in the UI

Deadlines are stored data (§0.5) but they are worthless if nobody sees them. Each one gets exactly one
visible surface:

| Timer | Surfaced in |
|---|---|
| `payment_deadline` | member `shop_order.php` (`pending` panel) **and** admin `shop_order.php` header (red when past) |
| `correction_deadline` | member `shop_order.php` (`payment_failed` panel) |
| `SLA_REVIEW_HOURS` | admin `shop_orders.php` — a "waiting Nh" hint next to each `payment_review` row |
| `SLA_PICK_HOURS` | admin `shop_order.php` — "picked Nh ago" on `packing` |
| `SLA_PICK_HOURS` (pickup leg) | admin `shop_orders.php` — "ready to ship Nh ago" on `ready_to_ship` |
| `hold_deadline` | admin `shop_order.php` hold block **and** the member page's `on_hold` panel |
| `shop_max_delivery_attempts` / `response_deadline` | admin `shop_order.php` shipment block; member `delivery_failed` / `returned_to_sender` panels |
| `report_window_end` / `completion_due_at` | member `delivered` panel ("Confirm receipt by <date> — this ends reporting") |

The member-facing copy must always state three things per the guide's principle 12: the current state in
plain language, the next step, and the consequence of the buyer's action. The `pending` panel's proof-upload
button in particular carries the line "Submitting proof ends your option to cancel."

### 5.11 Image uploads

| Purpose | Directory | Helper call | Access pattern |
|---|---|---|---|
| Product image | `uploads/products/` | `upload_image($_FILES['image'], 'products', 'product_'.($id ?: 'new'), $oldPath)` | `APP_URL . '/uploads/' . $image_url` |
| Payment proof | `uploads/shop_proofs/` | `upload_image($_FILES['proof_image'], 'shop_proofs', 'proof_'.$memberId)` | **never** a direct link — `AdminController::orderProofView()` streams it after `Auth::guard('admin')` |
| Shipment label / POD | `uploads/shipments/` | `upload_image($_FILES['pod_image'], 'shipments', 'pod_'.$orderId)` | same: streamed through an authenticated handler |

DB stores **relative** paths (`products/product_7_1781590505.jpg`); `upload_image()` prefixes
`$subDir . '/' . $name`. `delete_uploaded_file()` resolves relative to `uploads/` and `@unlink`s. Filenames are
`{prefix}_{time()}.{ext}` (no `mt_rand`, unlike `savePackage()`'s inline version — two uploads in the same
second could collide; acceptable, note it).

**`.htaccess` serves `uploads/` as real static files** (lines 55–58: "Allow direct access to real files and
directories (assets, uploads)"). That makes `uploads/shop_proofs/` and `uploads/shipments/` **public by
default** — filenames embed the member id and a timestamp, which is weak protection. v1 accepts this and
documents it; if it must not be public, move those two directories outside the web root in a follow-up (the
guide's §11.7 requires private storage). Product images stay public — they must render in `<img>` tags.

### 5.12 PV removal checklist (strategy C)

There is no "PV rendered as money" bug to fix any more — the fix is **deletion**. Every one of these must be
gone from the ported files, or a member will read PV that the system never grants:

| File | Lines | What to remove |
|---|---|---|
| `views/partials/cart_offcanvas.php` | 57 | `data-unit-pv="…"` attribute |
| | 92–95 | the `Total PV` summary row + `#cartTotalPv` |
| | 133, 138 | `updateFooter()` PV branch |
| `views/member/cart.php` | 8, 184, 199, 234 | `$totalPv`, `data-unit-pv`, per-line PV span, mobile PV div |
| | 263–264 | `Total PV earned` summary row + `#cartTotalPv` |
| | 278, 292 | "PV is finalized at checkout" / "PV credits to your binary leg" |
| | 327, 331 | the JS PV write |
| `views/member/checkout.php` | 64, 72–73, 176–177 | per-line PV, `Total PV` (summary + right rail) |
| | 82–106, 211–241, 252, 275–288 | the entire Binary Position card + CSS + JS |
| `views/member/shop.php` | 67–68, 81, 89, 100–103, 220–223, 245 | `$effPv`/`$pvFmt`, `data-pv`, `PV Value` card row + modal row + JS |
| `views/member/shop_orders.php` | 135, 172 | `Total PV` column header + cell (replaced by the `Order #` column) |
| `views/admin/shop.php` | 61–63, 94–96, 169–178, 203–224, 268–269, 276, 318–372 | PV table columns, PV inputs, whole Unilevel block, all related JS incl. `pv_per_peso_rate` |
| `views/admin/shop_orders.php` | 64, 149–150, 240, 323, 335–336, 348 | `.pv` CSS, "PV This Page" stat, per-order PV, all "distribute PV" confirm copy |
| `models/Product.php` | 79–84, 129–137 | `unilevelProductBonus()`, the level rewrite in `save()` |
| `models/Cart.php` | 57, 63, 66, 70–73, 106, 122, 152, 158 | `$unitPv`, the `unit_pv` UPDATE/INSERT fragments, `current_pv`, `total_pv` in `getTotals()` + its fallback key |
| `models/ShopOrder.php` | 148, 152–160, 165–172, 213 | `total_pv`, the `$binaryPosition` param, the `Commission::processProductPV()` call |

The **columns** `product_pv`, `pv_value`, `total_pv`, `unit_pv` stay in the DDL (§0.2) but their names must
not appear in any PHP file. This checklist is about the PHP.

---

## 6. Verification commands

Run from `C:\laragon\www\altaslive` (PowerShell; drop the `Select-String` → `grep -n` swap on Linux).

**V1 — Lint every touched PHP file (gate for Phases 2–5)**

```powershell
php -l index.php; php -l core/helpers.php
php -l controllers/MemberController.php; php -l controllers/AdminController.php
php -l models/Product.php; php -l models/Cart.php; php -l models/ShopOrder.php
php -l models/ShopOrderEvent.php; php -l models/ShopShipment.php
php -l models/ShopPaymentProof.php; php -l models/ShopRefund.php
php -l cron/shop_expiry.php
Get-ChildItem views -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

**V2 — All 25 routes registered, and every route target method exists**

```powershell
$memberRoutes = 'shop','shop_orders','shop_order','cart','add_to_cart','update_cart_item',
               'remove_cart_item','checkout','place_order','shop_submit_proof',
               'shop_cancel_order','shop_confirm_receipt'
$adminRoutes  = 'admin_shop','admin_save_product','admin_delete_product','admin_shop_orders',
               'admin_shop_order','admin_order_transition','admin_order_hold','admin_order_resume',
               'admin_order_proof','admin_order_shipment','admin_order_reship','admin_order_refund',
               'admin_shop_expire'
($memberRoutes + $adminRoutes) | ForEach-Object {
  if (-not (Select-String -Path index.php -Pattern "'$_'\s*=>")) { "MISSING ROUTE: $_" }
}
Select-String -Path controllers\MemberController.php -Pattern "function (shop|shopOrders|shopOrder|cart|addToCart|updateCartItem|removeCartItem|checkout|placeOrder|submitShopProof|cancelShopOrder|confirmShopReceipt)\("
Select-String -Path controllers\AdminController.php  -Pattern "function (shop|saveProduct|deleteProduct|shopOrders|shopOrder|orderTransition|orderHold|orderResume|orderProofView|orderShipment|orderReship|orderRefund|shopExpire)\("
# must be EMPTY: no direct status writes outside ShopOrder::transition()
Select-String -Path controllers,views,models -Recurse -Pattern "UPDATE shop_orders SET status" -Context 0,1
```

**V3 — No undefined helpers, no leftover PV, no duplicate cart IDs (the four parity traps)**

```powershell
Select-String -Path core\helpers.php -Pattern "function (per_page|upload_image|delete_uploaded_file|shop_status_label|shop_status_tone|shop_status_badge|shop_public_order_no)\("
Select-String -Path views\member\cart.php -Pattern 'id="cart(ItemsContainer|Footer|ItemsCount|Subtotal|TotalPrice)"'    # must be 0 (all namespaced)
Select-String -Path views\partials\cart_offcanvas.php -Pattern 'id="cart(ItemsContainer|Footer|ItemsCount|Subtotal|TotalPrice)"'  # must be 5
Select-String -Path models,core,controllers,views -Recurse -Pattern "product_pv|pv_value|unit_pv|total_pv|pv_per_peso_rate|processProductPV|processProductUnilevel|processBinaryVolume|repeat_purchase|product_unilevel|unilevelProductBonus"  # must be 0
Select-String -Path models\Product.php,models\Cart.php,models\ShopOrder.php,views\member\shop.php,views\member\shop_orders.php,views\member\shop_order.php,views\member\cart.php,views\member\checkout.php,views\admin\shop.php,views\admin\shop_orders.php,views\admin\shop_order.php -Pattern "binary_position|unilevel|Royalty"   # must be 0 (scoped: bare terms are legit elsewhere)
Select-String -Path views\member\shop.php,views\member\shop_orders.php,views\member\shop_order.php,views\member\cart.php,views\member\checkout.php,views\admin\shop.php,views\admin\shop_orders.php,views\admin\shop_order.php -Pattern "APP_URL \?>/\?page=" | Measure-Object   # must be 0
Select-String -Path views -Recurse -Pattern "Repeat Purchase"   # must be 0
# every status literal in PHP must be one of the 14
Select-String -Path models\ShopOrder.php,core\helpers.php -Pattern "'(pending|payment_review|payment_failed|paid|packing|ready_to_ship|shipped|out_for_delivery|delivery_failed|returned_to_sender|delivered|completed|cancelled|on_hold)'" -AllMatches | ForEach-Object { $_.Matches.Value } | Sort-Object -Unique
# must be exactly the 14 values — a stray 'approved' or 'rejected' means a rev-2 leftover
```

**V4 — Engine untouched (the §7 regression guard)**

```powershell
git diff --numstat core/Commission.php core/CapEngine.php core/Auth.php core/DailyFixedIncome.php core/Reactivation.php models/Package.php models/CdStatus.php models/Ewallet.php cron/midnight_reset.php cron/fund_transfer_limit_reset.php
# every line must read "0<TAB>0<TAB>path"
git diff install.sql | Select-String -Pattern "personal_pv|group_pv|product_unilevel"
# must be empty
Select-String -Path core,models,controllers,views -Recurse -Pattern "processProductPV|processProductUnilevel|processBinaryVolume|meetsPersonalPvRequirement"
# must be 0 hits
git status --short models/Ewallet.php .htaccess assets frontend   # must be unchanged too
```

**V5 — Transition-matrix smoke test (browser + SQL)**

```
1) admin  : /?page=admin_shop                 → create product (price 500, stock 5) → flash "Product created."
2) member : /?page=shop                       → Add to Cart (qty 2) → redirect ?cart=1, drawer opens, badge 2
3) SQL    : SELECT quantity, unit_price FROM cart_items ORDER BY id DESC LIMIT 1;   -- 2 | 500.00
4) admin  : /?page=admin_shop_orders          → open an order → action bar shows ONLY legal targets
5) admin  : POST admin_order_transition to=payment_review? (no) → walk pending → payment_review → paid
6) SQL    : SELECT status, previous_status, version, paid_by, paid_at FROM shop_orders ORDER BY id DESC LIMIT 1;
           -- paid | NULL | 2 | 1 | <ts>
7) admin  : to=packing, to=ready_to_ship, to=shipped (tracking + courier required)
8) SQL    : SELECT stock FROM products WHERE id = <id>;                    -- 3 (was 5)
9) SQL    : SELECT reservation_state FROM shop_order_items;                -- deducted
10) SQL    : SELECT from_status,to_status,actor_type,source FROM shop_order_events ORDER BY id;
           -- one row per step, actor_type admin until the system steps
11) SQL    : SELECT COUNT(*) FROM commissions WHERE created_at > NOW() - INTERVAL 10 MINUTE;   -- MUST be 0
12) admin  : try to=shipped again            → refused, "This order just changed."
```

---

## 7. Non-regression contract (highest priority)

**The following must remain byte-identical unless a bug is proven:**

| Member | Live lines | Why |
|---|---|---|
| `Commission::processBinaryPlacement()` | 28–169 | Registration-time placement, CD/paid leg counts, `left_count_paid`/`right_count_paid`, `left_pair_volume`/`right_pair_volume`, daily-cap + flush math. **A shop order must never enter this method** — it hardcodes `newVolume` from `packages.pairing_bonus` (lines 51–59) and would double-count. |
| `Commission::processDirectReferral()` | 177–267 | Registration referral bonus. Unrelated to the shop. |
| `Commission::processIndirectReferral()` | 275–~420 | 10-level sponsor-chain walk. The shop must **not** reuse this for product bonuses (there are none) nor add a `product` arm. |
| `Commission::creditPairing()` | 421–495 | CD → cap → `commissions(pairing, GROSS)` → `Ewallet::credit` → `CdStatus::recordLedger` → `CapEngine::recordEarning`. |
| `Commission::recordFlush()` | 497–509 | `status='flushed'` audit row for daily-cap overflow. |
| `Commission::recordCapBlocked()` | 514–542 | `match ($type)` description arm. **No new case** — the shop writes no `commissions` rows, so no new `$type` and no ENUM change. |
| `Commission::summary/recent/history/capBlockedHistory()` | 545–602 | Read-only reporting. Untouched; the shop contributes no rows. |
| `CapEngine`, `CdStatus`, `Package`, `DailyFixedIncome`, `Reactivation` | whole files | Zero shop interaction. |
| `install.sql` — `packages`, `users`, `commissions`, `cd_ledger` | blocks | Zero new columns, zero new ENUM values. |
| `cron/midnight_reset.php`, `cron/fund_transfer_limit_reset.php` | whole files | Shop volume never touches `pairs_paid_today`, `pairs_volume_today`, `ewallet_sent_today`, or `lifetime_earned`. The shop's own expiry job is a **new** file (Phase 6), not an edit to these. |
| `.htaccess` | whole file | `uploads/` is already public static; the new cron script is CLI-only and already blocked. |
| `models/Ewallet.php` | whole file | `debitInternal()` takes an arbitrary `refType`; `ref_type` is an ENUM, handled by one `ALTER`. |

**The only sanctioned interaction with existing infrastructure** is `Ewallet::debitInternal()` moving money
*out of* a buyer's wallet for an e-wallet-paid order, and `Ewallet::credit()` moving money *back* on a refund
— ordinary ledger entries with `ref_type = 'shop_order'`, not commissions, covered by Phase 1.1 step 10.

**Assertion that must hold after any shop order, for the buyer and every upline:**

```
users.lifetime_earned          unchanged
users.cap_status / capped_at   unchanged
users.left_pair_volume / right_pair_volume / pairs_volume_paid / pairs_volume_today   unchanged
user_cd_status.cd_active, cd_bucket, cd_ledger    unchanged
commissions                    no new rows
ewallet_ledger                 exactly one new DEBIT row (e-wallet orders only)
ewallet_balance / withdrawable_balance   decreased by the amount spent, no more, no less
```

**Assertion that must hold after any shop order, for the shop tables:**

```
shop_orders.version            incremented by exactly 1 per transition
shop_order_events              exactly one new row per transition, source + actor_type populated
shop_order_items.reservation_state  consistent with shop_orders.status (§0.5)
products.stock                 decremented exactly once, only at ready_to_ship → shipped
total_pv / unit_pv / product_pv / pv_value   still 0.00 everywhere
```

---

## 8. Explicitly out of scope

**Compensation (all of it):** any PV concept in the shop · any *use* of `product_pv` / `pv_value` /
`total_pv` / `unit_pv` (they exist inert, §0.2) · `product_unilevel_levels` · product unilevel bonuses ·
`Commission::processProductPV()` / `processProductUnilevel()` / `processBinaryVolume()` /
`meetsPersonalPvRequirement()` · `Royalty` + `royalty_pool` + rank columns + `member_royalty` route/view ·
`pv_transactions` · `pv_per_peso_rate` · `binary_repeat_enabled` / `unilevel_product_enabled` /
`binary_enabled` settings · `packages.personal_pv_requirement` · `users.personal_pv` / `group_pv` ·
`genealogy.php?view=product_unilevel` · `package_pv_rate` / `binary_pv_pct` / `pairing_pv_pct` /
`direct_ref_pv_pct` / `dfi_pv_pct` · `cron/monthly_pv_reset.php` · adding a case to
`Commission::recordCapBlocked()`'s `match`.

**Order lifecycle:** problem reports (T22/T23) · partial / multi-parcel shipments · returns of faulty goods ·
carrier API webhooks and `shop_shipment_events` · lost-parcel claims · reconciliation tasks ·
notification outbox / email / SMS · customer address editing after handoff · order edits after placement ·
COD · card / gateway payments · guest checkout · order invoice / receipt PDF · product variants ·
categories / tags / search · product rating/reviews · coupon / discount codes.

**Money:** any fee of any kind (shipping, service, reship, payment tolerance, tax — §0.4) · shop revenue
crediting to a designated account (§8.5 item 1) · split or partial shipments · partial refunds.

**Infrastructure:** retro-fitting `rows_per_page.php` into the 10 existing paginated views · unifying the
three confirm-modal styles · expanding `users.role` to the guide's six roles · `?bypass=` maintenance token ·
`SHOW_FRONTEND` toggle · a web-triggerable fallback for `cron/shop_expiry.php` (CLI only for now).

---

## 9. Risk register

| Risk | Sev | Mitigation |
|---|---|---|
| Copying tmp's `Commission.php` / `Package.php` / `install.sql` wholesale destroys the peso engine | **Critical** | §0/§7 contract; `git diff --numstat` gate (V4) must show `0 0` |
| A leftover `Commission::processProductPV()` call in `ShopOrder::transition()` silently re-introduces binary PV | **Critical** | Grep gate (V3/V4) + smoke **S11** (`SELECT COUNT(*) FROM commissions` = 0) + V5 step 11 |
| An inert PV column gets wired in by a well-meaning edit (e.g. someone adds `product_pv` to `Product::save()`) | **Critical** | §0.2's four rules; the V3 grep is `must be 0` across all PHP; §8.2 asserts the columns are still `0.00` |
| `ewallet_ledger.ref_type` ENUM rejects `'shop_order'` → fatal SQL error at checkout | **High** | Phase 1.1 step 10 ships **before** any code; §8.2 asserts the ENUM |
| Raw `UPDATE users SET ewallet_balance` leaves `withdrawable_balance` inflated → buyer withdraws money already spent | **High** | Use `Ewallet::debitInternal()` (Phase 4.1 edit 5) |
| A transition matrix typo (e.g. `'reshiped'`) makes a legal transition silently un-reachable in production | **High** | Gate 2b cross-checks `TRANSITIONS` against `STATUSES`; V3's status-literal dump must show exactly 14 values |
| A status added to `TRANSITIONS` but not to the views' `match()` in `shop_status_*` helpers → blank badge | Med | The helpers take a `string` and have a `default => 'Unknown'` arm; the Gate-5 grep for exactly 14 literals in `core/helpers.php` catches the mismatch |
| Two admins click "Verify" at once → both flashes say success, one audit trail lies | **High** | The conditional `UPDATE … WHERE id = ? AND status = ?` (§0.5 / V2 grep) makes the loser get `false` and a "this order just changed" flash; smoke **S22** |
| `products.stock` decremented twice for one order (e.g. a re-shipped order) | **High** | The deduction lives only inside `transition('shipped')`, which is reachable only from `ready_to_ship`; a reship re-enters `packing`, not `shipped`, and flips lines `deducted → reserved` so exactly one deduction ever happens per physical parcel |
| A reship re-enters `packing` but the goods were never re-added to `stock`, so availability over-counts | Med | By design — the goods are physically back and allocated, not sellable (§0.3). Admin restock is an explicit action (`released`) |
| Expired `pending` orders hold stock forever if the cron is never installed | **High** | `?page=admin_shop_expire` button mirrors `admin_manual_reset` so the job is never only-cron; documented in the crontab block (Phase 6.1) and `AGENTS.md` |
| `cron/shop_expiry.php` folded into `midnight_reset.php` by a later edit, breaking the §7 freeze and running hourly work at midnight | Med | Phase 6 states the separation explicitly; §7 lists both cron files as byte-frozen; V4 checks them |
| Expiry fires on an order whose proof landed a second earlier | Med | `expireOverdue()` runs each candidate through the same conditional transition, and **never** selects `payment_review`; smoke **S37** |
| `cart_offcanvas.php` calls `Cart::getOrCreate()` on every page view → one `carts` row per logged-in user per session, incl. admins | Med | Use `Cart::getActive()` in the partial (Phase 5.1 item 1) |
| ~5 extra queries/page (badge ×2, offcanvas ×2, status counts ×1) on every page | Low | Acceptable for a storefront; `statusCounts()` is one `GROUP BY`; consolidate the badge queries if slow-query logs complain |
| Stale cart price baked into a placed order | Med | `Cart::refreshPrices()` at the top of `checkout()` + `placeOrder()` (Phase 2.2); smoke **S9** |
| Duplicate DOM IDs break live totals on `?page=cart` | Med | §5.1 rename + V3 grep |
| `?page=` URLs dropped from JS `fetch()` in ported views break Super-Login | High | `link_to()` conversion rule (§5.7) + smoke **S44** |
| An admin can buy, pack, ship, and refund their own order unchallenged | **High** | `ShopOrder::assertNotSelfReview()` in the model, so every entry point gets it; smoke **S31** |
| Proof / POD images are publicly fetchable by guessed URL (`uploads/` is static in `.htaccess`) | Med | Accepted for v1 and documented (§5.11); `orderProofView()` streams through an authenticated handler for the UI path; moving the directories outside the web root is the follow-up |
| Oversell between two simultaneous checkouts | Low | **Intentional** — reservation model, §0.3; the `stock >= qty` guard at T13 is the backstop |
| "Sold out" lingers because approval does not reduce availability | Low | **Resolved** — stock is now deducted at `shipped` (§0.3); the admin products help text was updated in Phase 5.14 |
| Two `product_<id>_<ts>.*` uploads collide in the same second | Low | Cosmetic only; optional `mt_rand()` suffix mirroring `savePackage()` |
| `Product::delete()` refuses deletes once orders exist | Low | Intended FK `RESTRICT` behaviour, surfaced as a friendly flash |
| `per_page()` default 10 vs `paginate()` default 20 | Low | New code always passes `per_page()` explicitly; `paginate()` signature untouched |
| An admin types `0` (or a negative) into a deadline setting and every order expires on arrival | Med | Clamped with `max(1, …)` in `saveSettings()` (Phase 4.3); smoke **S43** |
| A member cancels from a status where the button was hidden by a stale page render | Low | The matrix check is server-side in `ShopOrder::cancel()`, and the conditional `UPDATE` settles the upload-vs-cancel race; smoke **S14** calls the endpoint directly |
| The 14 status tabs wrap onto two rows and blow out the admin layout | Low | Use the existing tab markup with `flex-wrap`; if it is ugly, collapse the six rarely-used post-delivery statuses into a "More" dropdown |
| Shop revenue leaves the e-wallet with no destination account | Med | **Known, accepted** accounting gap; §8.5 item 1 is the follow-up. Do not invent a credit target silently |

---

*Implementation Plan — Shop Storefront (products, cart, checkout, 14-status order lifecycle) — strategy
**(C) pure storefront, zero product compensation**, reservation stock model with deduction at courier
handoff, no fees, admins can shop, `shop` naming — revision 3, 2026-10-06. Supersedes revision 2's 5-state
flow (`pending`, `paid`, `approved`, `rejected`, `cancelled`).*
