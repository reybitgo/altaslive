# Implementation Plan â€” Add-to-Cart & Admin Product Management (sync tmp/altas â†’ altaslive)

| Field | Value |
|---|---|
| **Target** | `C:\laragon\www\altaslive` (the live app: routes, models, controllers, views, migrations) |
| **Source of truth (richer impl.)** | `C:\laragon\www\altaslive\tmp\altas` (PV-era branch with cart/checkout/products) |
| **Scope** | Product catalog (admin CRUD), member shop, cart, checkout, repeat-purchase orders, product unilevel bonus |
| **Stack** | PHP 8.1+, MySQL 8, `?page=` router in `index.php`, no framework / no test suite / no linter |
| **Hard constraint** | Existing commission engine must not regress (see Â§7) |
| **Document date** | 2026-10-06 |

---

## 0. Executive summary â€” read this before touching code

The two trees are **not** the same codebase generation:

* `tmp/altas` is a **PV-native** engine: `packages.package_pv_rate`, `binary_pv_pct`, `pairing_pv_pct`,
  `direct_ref_pv_pct`, `dfi_pv_pct`, `users.{left_pv,right_pv,paired_pv,personal_pv,group_pv,flushed_pv}`,
  `pv_transactions`, `Package::packagePv()/pairingBonus()/binaryPackagePv()`, and a
  `setting('pv_per_peso_rate')` = 1000.0000 converter (1 PV = â‚±1,000).
* `altaslive` is the **legacy peso/count** engine: `packages.{pairing_bonus, daily_pair_cap, direct_ref_bonus}`,
  `users.{left_count_paid, right_count_paid, left_pair_volume, right_pair_volume, pairs_volume_paid,
  pairs_volume_flushed, pairs_volume_today}`, no PV columns anywhere, no `pv_transactions`, no
  `pv_per_peso_rate`. Pairing pays **â‚± directly**, not PVÃ—rate.

Therefore **do NOT copy `tmp/altas/core/Commission.php`, `models/Package.php`, or `install.sql` wholesale.**
Copying them would delete/replace the live peso engine and break registration, pairing, CD, DFI,
reactivation and every existing member balance.

### Chosen strategy â€” "peso-PV bridge" (minimal, additive, non-destructive)

Keep the product **admin UX and data model** of `tmp/altas` (product / cart / order tables, 10 unilevel
levels, image uploads, stock reservation, admin approvals), but **re-express product PV in pesos** so it
plugs into the existing live engine:

| tmp/altas concept | altoslive implementation in this plan |
|---|---|
| `products.product_pv` (PV units) | Same column name, **interpreted as â‚±** (bonus pool per unit) |
| `products.pv_value` (% of base PV) | Same â€” `effPv = product_pv * (pv_value / 100)` in **â‚±** |
| `setting('pv_per_peso_rate')` Ã— PV | **Removed â€” multiplier is 1.0** |
| `users.left_pv/right_pv/paired_pv` | Reuse live `left_pair_volume/right_pair_volume/pairs_volume_paid` |
| `pv_transactions` table | **Not ported** (audit-only; optional Phase 7b) |
| `users.personal_pv`, `users.group_pv` | **Added** (2 columns) â€” only to support the PV gate + display |
| `Royalty::processRepeatPurchase()` | **Not ported** (out of scope) |
| `setting('binary_enabled')` gate | **Not added** â€” live `processBinaryPlacement()` has no such gate and must stay that way |
| `Commission::processBinaryPV()` | **Not added.** New sibling named `processBinaryVolume()` so the live `processBinaryPlacement()` body is untouched |
| `Commission::processProductPV()` | **Added**, peso-based, calls the *live* private helpers `creditPairing()` / `recordFlush()` |
| `Commission::processProductUnilevel()` | **Added**, mirrors the live CD â†’ cap â†’ credit ordering |

Everything else is a straight port with the adaptation notes in Â§5.

---

## 1. Current state audit

### 1.1 Already present in `altaslive` (do not recreate)

| Area | Existing |
|---|---|
| Models | `CdStatus.php`, `Code.php`, `Ewallet.php`, `ImpLog.php`, `Package.php`, `Payout.php`, `User.php` |
| Core | `Auth.php`, `CapEngine.php`, `Commission.php`, `DailyFixedIncome.php`, `Reactivation.php`, `helpers.php` |
| Helpers | `e fmt_money fmt_date fmt_datetime redirect link_to flash csrf_* setting paginate pagination_links json_response rate_limit_* mask_account getUserById is_page current_page is_imp_token` |
| Assets | `assets/js/app.js` (**byte-identical to tmp/altas** â€” already exports `showToast`, `showConfirm({title,message,confirmText,confirmClass,formId,onConfirm})`, `confirmSubmit`, `copyText`) |
| Views | `views/partials/{head,footer,topbar,sidebar_member,sidebar_admin,settings_offcanvas}.php` + full member/admin page set |
| Migrations | `migrate_deactivated_status.sql`, `migrate_package_image.sql`, `migrate_package_toggles.sql`, `migrate_paid_leg_counts.sql`, `migrate_pair_volume.sql`, `migrate_superadmin.sql`, `migrate_usdt_bep20.sql` |
| Upload infra | `uploads/packages/`, `uploads/reactivation_proofs/` dirs; inline upload code in `AdminController::savePackage()` (lines 377â€“416) |
| Extras not in tmp/altas | Super-Login impersonation (`imp=` URL token), `link_to()`, seat limit, free registration, `upgrade` flow, CD bucket, `CapEngine`, `withdrawable_balance` |

### 1.2 Missing in `altaslive` (must be added)

| Layer | Missing artifacts |
|---|---|
| **Models** | `models/Product.php`, `models/Cart.php`, `models/RepeatPurchaseOrder.php`, (`models/RepeatPurchase.php` â€” deprecated shim, optional) |
| **Controllers** | `MemberController::{repeatPurchases, cart, addToCart, updateCartItem, removeCartItem, checkout, placeOrder}`; `AdminController::{products, saveProduct, deleteProduct, repeatPurchaseOrders, markRepeatOrderPaid, approveRepeatOrder, rejectRepeatOrder}` |
| **Views** | `views/member/{repeat_purchases,cart,checkout}.php`, `views/admin/{products,repeat_purchases}.php`, `views/partials/cart_offcanvas.php`, `views/partials/rows_per_page.php` |
| **Routes** | 7 member + 7 admin `?page=` entries (Â§3.3) |
| **Helpers** | `per_page()`, `upload_image()`, `delete_uploaded_file()` â€” **none of these exist** (see Â§5.4) |
| **Schema** | `products`, `product_unilevel_levels`, `carts`, `cart_items`, `repeat_purchase_orders`, `repeat_purchase_order_items`, `commissions.type` + `cd_ledger.type` enum value `unilevel_product`, `packages.personal_pv_requirement`, `users.personal_pv`, `users.group_pv`, settings `binary_repeat_enabled` / `unilevel_product_enabled` |
| **Core** | `Commission::processProductPV()`, `Commission::processProductUnilevel()`, `Commission::processBinaryVolume()`, `Commission::meetsPersonalPvRequirement()` |
| **Nav** | Sidebar member entries (Shop / Cart w/ badge), admin sidebar entries (Products, Repeat Purchases), topbar cart button + badge |
| **Uploads** | `uploads/products/`, `uploads/repeat_purchase_proofs/` (created on demand by `upload_image()` / `mkdir`) |

### 1.3 Corrections to the assumed brief

| Assumption | Reality in `altaslive` |
|---|---|
| "`upload_image` helper exists in altaslive" | âŒ **It does not.** Image upload is inline in `AdminController::savePackage()` (lines 377â€“416) and `Reactivation`. `upload_image()` / `delete_uploaded_file()` must be **added** to `core/helpers.php` (Phase 3). |
| "`paginate` signature / `per_page`" | `paginate(string $query, array $params, int $page, int $perPage = 20)` â€” default is **20** in live, **10** in tmp. Every live call site passes `$perPage` explicitly, so the default is safe; **keep 20** and add `per_page(int $default = 10, int $min = 5)` for new code only. |
| "PV amounts rendered as money" | Confirmed, and **only in `cart_offcanvas.php`**: line 94 `fmt_money((float)$cartTotals['total_pv'])` and line 138 `'â‚±' + parseFloat(totals.total_pv)`. `cart.php` (264), `checkout.php` (73, 177), `admin/products.php` (96), `admin/repeat_purchases.php` (240), `member/repeat_purchases.php` (172) all render `number_format(...) . ' PV'` correctly. |
| "tmp/altas helpers available in live" | tmp/altas has **no** `link_to()`, **no** impersonation. Live has both. All ported URLs must be rebuilt with `link_to()` (Â§5.2). |
| "Duplicate DOM IDs" | Confirmed â€” 6 IDs collide (Â§5.1). |

---

## 2. Schema differences (tmp/altas vs altaslive)

### 2.1 Objects present in tmp/altas, absent in altaslive

| Object | tmp/altas source | Live equivalent |
|---|---|---|
| `products` | `install.sql:63â€“76`; `migrations/021`, `024`, `025`, `029` | âŒ none |
| `product_unilevel_levels` | `install.sql:52â€“60`; `migrations/030` | âŒ none |
| `carts` / `cart_items` | `install.sql:79â€“101`; `migrations/027_add_cart_and_order_tables.sql` | âŒ none |
| `repeat_purchase_orders` / `_order_items` | `install.sql:104â€“133`; `migrations/027` | âŒ none (legacy `repeat_purchases` was dropped in tmp by migration 027 â€” **not needed here**, fresh table) |
| `pv_transactions` | `install.sql:242â€“263` | âŒ **deliberately not ported** |
| `royalty_pool` + `core/Royalty.php` | tmp only | âŒ out of scope |
| `packages.package_pv_rate / binary_pv_pct / pairing_pv_pct / daily_pair_pv_cap / direct_ref_pv_pct / dfi_pv_pct` | tmp `install.sql:10â€“36`; migrations 014â€“023, 028 | âŒ live uses `pairing_bonus` / `daily_pair_cap` / `direct_ref_bonus` / `daily_fixed_income` â€” **keep live columns** |
| `packages.personal_pv_requirement` | tmp `install.sql:35`; migration 027 step 2 | âŒ to be added (this plan) |
| `users.{left_pv,right_pv,paired_pv,paired_pv_today,flushed_pv,personal_pv,group_pv,rank_royalty}` | tmp `install.sql:56â€“64` | live has volume columns instead; **only `personal_pv` + `group_pv` are added** |
| `package_indirect_levels.pv_pct` | tmp | âŒ live uses `bonus` (â‚±) â€” keep |

### 2.2 Enum / settings drift

| Enum / setting | tmp/altas | altaslive today | Action |
|---|---|---|---|
| `commissions.type` | `pairing, direct_referral, indirect_referral, daily_fixed_income, unilevel_product, royalty` | `pairing, direct_referral, indirect_referral, daily_fixed_income` | add `unilevel_product` (do **not** add `royalty`) |
| `cd_ledger.type` | `pairing, direct_referral, indirect_referral, unilevel_product` | `pairing, direct_referral, indirect_referral` | add `unilevel_product` |
| `repeat_purchase_orders.payment_method` | `ewallet, gcash, maya, usdt_trc20, usdt_bep20` | n/a | matches live `payout_requests`/`reactivations` method set â€” OK as-is |
| `repeat_purchase_orders.status` | `pending, paid, approved, rejected, cancelled` | n/a | OK as-is |
| `users.status` | `active, suspended, pending` | `active, suspended, pending, deactivated` | keep live |
| `settings.binary_repeat_enabled` | `'1'` | âŒ | add, seed `1` |
| `settings.unilevel_product_enabled` | `'1'` | âŒ | add, seed `1` |
| `settings.binary_enabled` | `'1'` (gates `processBinaryPlacement`) | âŒ (gating is `Package::hasPairing()` + `pairing_enabled`) | **do not add** â€” adding it without also wiring live gates would be a silent no-op |
| `settings.pv_per_peso_rate` | `1000.0000` | âŒ | **do not add** (peso-PV bridge) |
| `settings.indirect_referral_enabled` | global | live uses `packages.indirect_referral_enabled` | keep live |
| `settings.dfi_enabled` | global | live uses `packages.dfi_enabled` | keep live |

### 2.3 Migration naming convention

`altaslive` uses root-level `migrate_*.sql` (idempotent `information_schema` guarded `ALTER`s), **not**
`tmp/altas/migrations/NNN_*.sql`. Follow the live convention: **one** new file
`migrate_products_cart.sql` that is safe to run twice.

---

## 3. Ordered implementation checklist

Phases are sequential; each ends with its own verification gate (Phase 7). Do not start a phase before the
previous gate passes.

---

### Phase 1 â€” Schema

**1.1 Create `migrate_products_cart.sql`** (root of `altaslive`, next to the other `migrate_*.sql`)

Idempotent, in this exact order (FK ordering matters â€” `product_unilevel_levels`/`cart_items` reference
`products`):

1. `CREATE TABLE IF NOT EXISTS products` â€” columns **final shape for live** (do *not* replay migrations
   021â†’024â†’025â†’027â†’029 separately):
   `id, name VARCHAR(120), price DECIMAL(12,2), product_pv DECIMAL(14,2) DEFAULT 0.00, pv_value DECIMAL(14,2) DEFAULT 100.00,
   image_url VARCHAR(255) NULL, stock INT UNSIGNED NOT NULL DEFAULT 0, short_description VARCHAR(255) NULL,
   description TEXT NULL, status ENUM('active','inactive') DEFAULT 'active', created_at, updated_at`
2. `CREATE TABLE IF NOT EXISTS product_unilevel_levels` â€” `id, product_id, level TINYINT UNSIGNED, pv_pct DECIMAL(5,2) DEFAULT 0.00`,
   `UNIQUE KEY uq_product_level (product_id, level)`, `INDEX idx_product_level (product_id, level)`,
   FK â†’ `products(id)` ON DELETE CASCADE.
3. `ALTER TABLE packages ADD COLUMN personal_pv_requirement DECIMAL(14,2) NOT NULL DEFAULT 0.00` (guarded).
4. `ALTER TABLE users ADD COLUMN personal_pv DECIMAL(14,2) NOT NULL DEFAULT 0.00`,
   `ADD COLUMN group_pv DECIMAL(14,2) NOT NULL DEFAULT 0.00` (guarded, one statement).
5. `CREATE TABLE IF NOT EXISTS carts` â€” `id, member_id, status ENUM('active','abandoned','converted') DEFAULT 'active', created_at, updated_at`, FK â†’ `users(id)` CASCADE.
6. `CREATE TABLE IF NOT EXISTS cart_items` â€” `id, cart_id, product_id, quantity, unit_price DECIMAL(12,2), unit_pv DECIMAL(14,2), added_at, updated_at`,
   `UNIQUE KEY uq_cart_product (cart_id, product_id)`, FKs â†’ `carts(id)` CASCADE, `products(id)` RESTRICT.
7. `CREATE TABLE IF NOT EXISTS repeat_purchase_orders` â€” as tmp/altas (`binary_position ENUM('left','right')`,
   `payment_method ENUM('ewallet','gcash','maya','usdt_trc20','usdt_bep20')`, `proof_image`,
   `status ENUM('pending','paid','approved','rejected','cancelled')`, `approved_by`, `approved_at`, `paid_at`).
8. `CREATE TABLE IF NOT EXISTS repeat_purchase_order_items` â€” as tmp/altas (8 numeric columns).
9. `ALTER TABLE commissions MODIFY COLUMN type ENUM('pairing','direct_referral','indirect_referral','daily_fixed_income','unilevel_product') NOT NULL`.
10. `ALTER TABLE cd_ledger MODIFY COLUMN type ENUM('pairing','direct_referral','indirect_referral','unilevel_product') NOT NULL`.
11. Seed settings idempotently: `binary_repeat_enabled='1'`, `unilevel_product_enabled='1'`.
12. Trailing `SELECT` sanity block (commented) â€” counts of each new table.

> **Gate 1.1:** `mysql -u USER -p DB < migrate_products_cart.sql` runs twice with no error; the second run is a no-op.

**1.2 Edit `install.sql`** (fresh installs must ship the same shape)

* Insert `CREATE TABLE product_unilevel_levels` **before** `products` (FK dependency) and
  `products`/`carts`/`cart_items`/`repeat_purchase_orders`/`repeat_purchase_order_items` after
  `package_indirect_levels` (i.e. between line 40 `) ENGINE=InnoDB;` and line 43 `CREATE TABLE users`).
  Copy the final column shapes from 1.1 â€” do **not** paste tmp/altas' column block verbatim.
* `users` CREATE TABLE: add the two PV columns in the "PV / volume" neighbourhood of
  `pairs_paid_today` (after line 62) with the same comments used in the migration.
* `packages` CREATE TABLE: add `personal_pv_requirement DECIMAL(14,2) NOT NULL DEFAULT 0.00` before
  `status` (line 27).
* `commissions` (line 127) and `cd_ledger` (line 263) ENUMs: add `'unilevel_product'` last.
* Settings seed block (after line 351 `);`): append `('binary_repeat_enabled','1'), ('unilevel_product_enabled','1')`.
* Add indexes to the trailing `ALTER TABLE â€¦ ADD INDEX` block (lines 308â€“321):
  `ALTER TABLE products ADD INDEX idx_status (status, name);`,
  `ALTER TABLE cart_items ADD INDEX idx_product (product_id);`,
  `ALTER TABLE repeat_purchase_orders ADD INDEX idx_member_status (member_id, status, created_at);`,
  `ALTER TABLE repeat_purchase_orders ADD INDEX idx_status (status, created_at);`.

> **Gate 1.2:** `php -S` / Laragon fresh import of `install.sql` succeeds; `SHOW CREATE TABLE products;`
> matches the migration output.

---

### Phase 2 â€” Models

All three are **static, PDO-only, no framework** â€” copy verbatim then adapt.

**2.1 Create `models/Product.php`** (from `tmp/altas/models/Product.php`, 156 lines)

Copy as-is, then these edits:

* `reservedStock()` (line ~37) â€” keep; it counts `status IN ('pending','paid','approved')` order lines. No change.
* `unilevelProductBonus()` (line ~74) â€” **DELETE**. It multiplies by `setting('pv_per_peso_rate')`, which does not exist in live. Callers compute `$effPv * ($pvPct/100)` directly (ratio 1.0).
* `delete()` (line ~145) â€” keep; it calls `delete_uploaded_file()` (Phase 3) and refuses deletion while
  `repeat_purchase_order_items` rows exist. Harden the count query with a bound param
  (`prepare`/`bindValue`) instead of string interpolation.
* `save()` (line ~88) â€” keep as-is (bulk field list + unilevel level rewrite of levels 1â€“10).

**2.2 Create `models/Cart.php`** (from `tmp/altas/models/Cart.php`, 248 lines) â€” copy **as-is**, no
adaptations. All methods are engine-agnostic. Note `addItem()`/`updateQuantity()` compute
`unit_pv = product_pv * (pv_value/100)` â€” in live those are â‚±.

**2.3 Create `models/RepeatPurchaseOrder.php`** (from `tmp/altas/models/RepeatPurchaseOrder.php`, 228 lines)

Copy as-is with two edits:

* `paginate()` is `protected static` in tmp â€” keep it `protected` (do not widen to `public`; `pending/paid/approved/all` are the public facade).
* `approve()` line ~193 calls `Commission::processProductPV($orderId)` â€” keep the name (Phase 6 provides
  the peso version).
* `markPaid()` sets `status='paid'` **without** setting `paid_at`. **Add `paid_at = NOW()`** to that UPDATE
  (live `repeat_purchase_orders.paid_at` is otherwise always NULL, and the e-wallet auto-approve path in
  `placeOrder` is the only writer).

**2.4 (Optional) `models/RepeatPurchase.php`** â€” skip. tmp marks it `@deprecated`; nothing in the ported
views/controllers references it. If a stale link exists, add a thin subclass instead.

> **Gate 2:** `php -l` on all three; grep for `pv_per_peso_rate` in `models/` returns nothing.

---

### Phase 3 â€” Helpers + routes

**3.1 Edit `core/helpers.php`** (456 lines)

| Insert | Location | Content |
|---|---|---|
| `per_page(int $default = 10, int $min = 5): int` | after `setting()` closes (line 346), before the `// â”€â”€ Pagination â”€â”€` banner (line 348) | port verbatim from tmp `helpers.php:305â€“309` |
| `upload_image(array $file, string $subDir, string $prefix, ?string $oldPath = null, int $maxBytes = 5*1024*1024): ?string` | after `paginate()` closes (line 377), before `pagination_links()` docblock (line 379) | port verbatim from tmp `helpers.php:351â€“397` |
| `delete_uploaded_file(?string $relativePath): void` | same block, immediately after `upload_image()` | port verbatim from tmp `helpers.php:402â€“414` |

* **Do not touch** `paginate()` (line 350) â€” keep `$perPage = 20`.
* **Do not touch** `link_to()` (158), `redirect()` (101), `csrf_*`, `setting()`, `flash()`.
* Optional follow-up (separate commit, Phase 7b): refactor `AdminController::savePackage()` lines 377â€“416
  to call `upload_image($_FILES['package_image'], 'packages', 'package_'.($id ?: 'new'), $existing['image'] ?? null)`.
  Keep its stricter MIME allow-list (jpg/png/webp, no gif) â€” do not silently loosen package uploads to
  match the helper's gif support.

**3.2 Create upload directories** (or let `upload_image()` `mkdir` them):

```
uploads/products/
uploads/repeat_purchase_proofs/
```

Add `uploads/products/*` + `uploads/repeat_purchase_proofs/*` to `.gitignore` (`/uploads` is already ignored).

**3.3 Edit `index.php` route table** (array spans lines 271â€“357)

Insert **member** block after line 314 (`'api_binary_uplines' â€¦`), before the `// â”€â”€ Admin â”€â”€` banner (316):

```
'repeat_purchases'  => ['MemberController', 'repeatPurchases', 'member'],
'cart'              => ['MemberController', 'cart',            'member'],
'add_to_cart'       => ['MemberController', 'addToCart',       'member'],
'update_cart_item'  => ['MemberController', 'updateCartItem',  'member'],
'remove_cart_item'  => ['MemberController', 'removeCartItem',  'member'],
'checkout'          => ['MemberController', 'checkout',        'member'],
'place_order'       => ['MemberController', 'placeOrder',      'member'],
```

Insert **admin** block after line 323 (`'admin_save_package' â€¦`), before `'admin_codes'` (324):

```
'admin_products'                   => ['AdminController', 'products',            'admin'],
'admin_save_product'               => ['AdminController', 'saveProduct',         'admin'],
'admin_delete_product'             => ['AdminController', 'deleteProduct',       'admin'],
'admin_repeat_purchases'           => ['AdminController', 'repeatPurchaseOrders','admin'],
'admin_mark_repeat_purchases'      => ['AdminController', 'markRepeatOrderPaid', 'admin'],
'admin_approve_repeat_purchase'    => ['AdminController', 'approveRepeatOrder',  'admin'],
'admin_reject_repeat_purchase'     => ['AdminController', 'rejectRepeatOrder',   'admin'],
```

* Keep the tmp/altas spelling (plural `admin_mark_repeat_purchases`, singular `admin_approve_repeat_purchase` /
  `admin_reject_repeat_purchase`) so ported views need no edits. Do **not** "fix" the inconsistency in this pass.
* Do **not** add `binary_enabled`, `member_royalty`, `?cart=1` handling, or `_show_frontend` handling â€”
  the last one already exists in live `.htaccess` + `frontend/index.php`.

> **Gate 3:** `php -l index.php core/helpers.php`; `grep -c "cart_offcanvas"` still 0 (partial comes in Phase 5).

---

### Phase 4 â€” Controllers

**4.1 Edit `controllers/MemberController.php`** (1117 lines) â€” insert 7 methods between line 132
(`earnings()` closes) and line 134 (`resolveBinaryRoot()` docblock).

Port from `tmp/altas/controllers/MemberController.php:141â€“405` with these adaptations:

| Method | Line (tmp) | Adaptation for live |
|---|---|---|
| `repeatPurchases()` | 141â€“157 | keep; `$pageTitle` then `head/member view/footer` (live convention) |
| `cart()` | 164â€“176 | keep verbatim |
| `addToCart()` | 178â€“195 | redirect target `redirect('/?page=repeat_purchases&cart=1')` â€” **keep** (auto-open drawer, Â§5.5) |
| `updateCartItem()` | 197â€“231 | replace the hand-rolled `header()/echo json_encode()/exit` with `json_response([...])` (live helper at `helpers.php:296`) for both success and error branches; keep the `X-Requested-With` AJAX detection |
| `removeCartItem()` | 233â€“257 | same `json_response()` swap; wrap `Cart::removeItemById()` in try/catch like the update handler |
| `checkout()` | 259â€“288 | keep; `Ewallet::balance()`, `setting('gcash_enabled'/'maya_enabled'/'usdt_trc20_address'/'usdt_bep20_address')`, `Cart::validateStock()`, `$showBinaryPosition = setting('binary_repeat_enabled','1')==='1'` all resolve in live |
| `placeOrder()` | 290â€“405 | **4 required edits** (see below) |

`placeOrder()` required edits:

1. **Proof upload â†’ helper.** Replace lines 331â€“355 (manual `finfo` + `move_uploaded_file` into
   `uploads/repeat_purchase_proofs/`) with `upload_image($_FILES['proof_image'], 'repeat_purchase_proofs',
   'proof_'.$memberId)`. Live helper accepts gif; if you want tmp's stricter set (no gif), validate
   `mime_content_type()` before calling. Store the returned relative path in `$proofImage`.
2. **E-wallet debit â†’ `Ewallet::debit()`.** Replace lines 365â€“379. The tmp code does a raw
   `UPDATE users SET ewallet_balance = ewallet_balance - ?`, which leaves `withdrawable_balance`
   untouched â€” the member could then withdraw money they already spent. Use
   `Ewallet::debit($memberId, $totalPrice, $orderId, 'registration', "Payment for repeat purchase order #{$orderId}")`
   (`ref_type 'registration'` is in the live whitelist at `Ewallet.php:60`), inside the existing transaction.
   It returns `false` on insufficient balance â†’ throw.
3. **Auto-approve stays.** Keep lines 381â€“388 (`status='approved'`, `paid_at`, then
   `Commission::processProductPV($orderId)`) â€” but move the approve+distribute *after* the successful debit
   and keep it inside the transaction.
4. **Cart clear.** Keep `Cart::clear($cartId)`; add `Cart::markConverted()` is already done inside
   `createFromCart()` â€” do not call it twice.

**4.2 Edit `controllers/AdminController.php`** (943 lines) â€” insert 7 methods between line 421
(`savePackage()` closes) and line 423 (`// â”€â”€ Registration Codes â”€â”€` banner).

Port from `tmp/altas/controllers/AdminController.php:309â€“488` with these adaptations:

| Method | Line (tmp) | Adaptation |
|---|---|---|
| `products()` | 309â€“320 | `$perPage = per_page();` (new helper). `Product::allPaginated()` internally calls `paginate(..., $perPage)`. Keep `?edit=` handling. |
| `saveProduct()` | 322â€“401 | keep validation block; `upload_image($_FILES['image'], 'products', 'product_'.($id ?: 'new'), â€¦)` + `delete_uploaded_file()` now exist (Phase 3) â€” no change needed |
| `deleteProduct()` | 403â€“420 | keep |
| `repeatPurchaseOrders()` | 426â€“443 | `$perPage = per_page();`; status tabs `pending\|paid\|approved\|all` |
| `markRepeatOrderPaid()` | 445â€“458 | keep; redirect `&status=paid` |
| `approveRepeatOrder()` | 460â€“473 | keep |
| `rejectRepeatOrder()` | 475â€“488 | keep |

**4.3 Edit `AdminController::saveSettings()`** (lines 520â€“569)

* Add `'binary_repeat_enabled'` and `'unilevel_product_enabled'` to the `$allowed` array (ends line 552).
* Add both keys to the checkbox-toggle branch at line 559 so unchecking persists `'0'`:

```php
if (in_array($key, ['gcash_enabled','maya_enabled','reactivation_ewallet_enabled',
                    'reactivation_external_enabled','binary_repeat_enabled',
                    'unilevel_product_enabled'], true)) { â€¦ }
```

* `per_page()`/settings are unrelated â€” no other change.

**4.4 (Optional) Edit `AdminController::savePackage()`** to add a `personal_pv_requirement` input and pass
it into `Package::save()` (also add the key to `Package::save()`'s `$fields`, `models/Package.php:60â€“77`).
Without this the column exists but is only settable via SQL. Ship as a separate commit.

> **Gate 4:** `php -l controllers/MemberController.php controllers/AdminController.php`; grep the route
> table for all 14 new keys.

---

### Phase 5 â€” Views + partials

Copy, then adapt. **Every ported view must be URL-normalized to `link_to()`** (Â§5.2).

| # | File | Source | Action |
|---|---|---|---|
| 5.1 | `views/partials/cart_offcanvas.php` | tmp (221 lines) | Copy â†’ **fix 2 PV-as-money bugs** (lines 94, 138) â†’ `link_to()` for the 2 fetch URLs (161, 192) and the "Browse Products" link (49, 199) |
| 5.2 | `views/partials/footer.php` | live (16 lines) | **Edit** â€” add `<?php require __DIR__ . '/cart_offcanvas.php'; ?>` after the `app.js` include (line 11), before `<div id="toastContainer">` |
| 5.3 | `views/partials/topbar.php` | live (104 lines) | **Edit** â€” insert tmp's cart-count PHP block + ðŸ›’ offcanvas trigger button before line 89 (`if (Auth::isAdmin() â€¦ admin_settings`) |
| 5.4 | `views/partials/sidebar_member.php` | live (147 lines) | **Edit** â€” add `$cartBadge` computation after line 13; add 2 nav entries after `'earnings'` (line 18): Shop (`repeat_purchases`) and Cart (`cart`, `'badge' => $cartBadge`) â†’ `renderSidebarNav()` must render the badge chip (currently lines 100â€“104 have no badge branch) |
| 5.5 | `views/partials/sidebar_admin.php` | live (123 lines) | **Edit** â€” add Products + Repeat Purchases entries next to Packages/Codes; mirror the `badge` support only if you reuse it |
| 5.6 | `views/partials/rows_per_page.php` | tmp (22 lines) | Copy â†’ depends on `per_page()` (Phase 3). **Do not retro-fit the 10 existing views in this phase** |
| 5.7 | `views/member/repeat_purchases.php` | tmp (265 lines) | Copy â†’ `link_to()` (form action line 111, breadcrumb/back links, `pagination_links` line 199) |
| 5.8 | `views/member/cart.php` | tmp (387 lines) | Copy â†’ **namespace the 6 colliding DOM IDs** (Â§5.1) â†’ `link_to()` |
| 5.9 | `views/member/checkout.php` | tmp (330 lines) | Copy â†’ `link_to()` for form action (line 30) + back links (25, 198) |
| 5.10 | `views/admin/products.php` | tmp (381 lines) | Copy â†’ `link_to()` (13 links/actions incl. `?edit=`, `admin_delete_product`, `admin_save_product`); replace `pv_per_peso_rate` at line 326 with `const pvPerPesoRate = 1.0;` (bridge) and relabel line 178/224 "â‰ˆ â‚±" strings if you want them honest |
| 5.11 | `views/admin/repeat_purchases.php` | tmp (470 lines) | Copy â†’ `link_to()` for tabs (116/124/132/140/158/161/164/167/197), 3 POST action endpoints (308/321/334/346), pagination (376) |
| 5.12 | `views/admin/settings.php` | live (existing) | **Edit** â€” add 2 checkboxes next to the existing commission-plan toggles; mirror live's markup style (this view already renders `binary_enabled` at line 442 for status display) |

`link_to()` conversion rule for ported files:

| tmp/altas | altoslive |
|---|---|
| `<?= APP_URL ?>/?page=admin_products&edit=3` | `<?= link_to('admin_products', ['edit' => 3]) ?>` |
| `action="?page=add_to_cart"` | `action="<?= link_to('add_to_cart') ?>"` |
| `'<?= APP_URL ?>/?page=update_cart_item'` (JS fetch) | `'<?= link_to('update_cart_item') ?>'` |
| `APP_URL . '/?page=admin_repeat_purchases&status=' . e($status) . '&per_page=' . per_page()` | `link_to('admin_repeat_purchases', ['status' => $status, 'per_page' => per_page()])` |

> **Gate 5:** `php -l` all 7 new views; grep for `APP_URL ?>/?page=` inside the 7 files â†’ must be **0 hits**.

---

### Phase 6 â€” Core / commission updates (highest risk)

**Rule: additive only.** The live `Commission.php` (602 lines) keeps every existing method body byte-identical.

**6.1 `core/Commission.php` â€” 4 additions, 0 modifications**

| New member | Where | Notes |
|---|---|---|
| `meetsPersonalPvRequirement(int $userId): bool` (private) | insert before the `// â”€â”€ PRIVATE HELPERS â”€â”€` banner at line 417 | `Package::find($user['package_id'])['personal_pv_requirement'] <= 0` â‡’ `true`; else `$user['personal_pv'] >= $req` |
| `processBinaryVolume(int $sourceUserId, float $amount, ?string $buyerSide = null): void` (public) | insert after `processBinaryPlacement()` closes (line 169) | **peso sibling of tmp's `processBinaryPV()`, deliberately NOT named `processBinaryPV`** (Phase 1). Walks `binary_parent_id`/`binary_position` from `$sourceUserId`; first hop uses `$buyerSide` (checkout's `binary_position`) |
| `applyBinaryVolume(int $ancestorId, ?string $side, float $amount, int $sourceUserId): void` (private) | next to `processBinaryVolume()` | mirrors live `processBinaryPlacement()` lines 98â€“156 **verbatim in structure**: `left_pair_volume`/`right_pair_volume` += amount, skip entirely when `!CapEngine::isActiveForPairs($ancestorId)`, `available = min(left,right)`, `processed = pairs_volume_paid + pairs_volume_flushed`, daily cap via `daily_cap_bypass` else `daily_pair_cap * pairing_bonus`, `creditPairing($id, $payNow, 1, $sourceUserId)`, `recordFlush($id, $flushNow, $sourceUserId)`, one atomic `UPDATE â€¦ pairs_volume_paid/pairs_volume_flushed/pairs_volume_today`. **Reuse live `creditPairing()` (421) and `recordFlush()` (497) â€” do not copy tmp's PV variants.** |
| `processProductPV(int $orderId): void` (public) | after `processIndirectReferral()` closes (line 415) | peso version of tmp `Commission.php:449â€“502`; see 6.2 |
| `processProductUnilevel(int $orderId): void` (public) | after `processProductPV()` | peso version of tmp `Commission.php:508â€“626`; see 6.3 |

**6.2 `processProductPV()` contract (peso bridge)**

```
guard: order exists && status === 'approved' && total_pv > 0 && buyer status === 'active'
1) users.personal_pv  += $totalPv            (buyer)
2) walk sponsor chain (visited-set, as live processIndirectReferral lines 298â€“321):
   for each active upline passing meetsPersonalPvRequirement(): users.group_pv += $totalPv
3) if setting('binary_repeat_enabled','1') === '1':
   self::processBinaryVolume($memberId, $totalPv, $order['binary_position']);
4) self::processProductUnilevel($orderId);
-- NO Royalty::processRepeatPurchase()  (core/Royalty.php does not exist in live)
```

**6.3 `processProductUnilevel()` contract**

Identical control flow to tmp `Commission.php:508â€“626`, with four substitutions:

* `$rate = (float)setting('pv_per_peso_rate','1.0000');` â†’ **deleted**; `$bonus = $effPv * ($pvPct / 100);`
* per-line-item loop over `repeat_purchase_order_items.total_pv` (unchanged)
* the 7-step credit block is a **copy of live `processDirectReferral()` lines 204â€“266** (CD split â†’ `CapEngine::canEarn` â†’ `INSERT commissions` GROSS â†’ `Ewallet::credit(..., 'commission', ...)` â†’ `recordCapBlocked` â†’ `CdStatus::recordLedger` â†’ `CapEngine::recordEarning`)
* `'unilevel_product'` must be a valid `commissions.type` **and** `cd_ledger.type` value â†’ Phase 1 step 9/10. `Commission::recordCapBlocked()` (line 514) writes `$type` straight into `commissions.type`, so a missing enum value = fatal SQL error on the flush path.
* Gate order per upline: `status === 'active'` **and** `meetsPersonalPvRequirement()` **and** `$pvPct > 0`; the walk continues upward past every skipped upline.

**6.4 `models/Package.php`** â€” add `personalPvRequirement(int $packageId): float` (4 lines) so Phase 6.1 reads the gate through the model rather than `Package::find()` in a loop.

**6.5 Cron** â€” **no changes.** Product volume flows through the existing
`pairs_volume_paid` / `pairs_volume_today` counters that `cron/midnight_reset.php` already resets. No
`monthly_pv_reset.php`, no `pv_transactions` resets.

> **Gate 6:** `php -l core/Commission.php`; `git diff --stat core/Commission.php` must show **only
> additions** (`+N / -0`); `Select-String` confirms `processBinaryPV` absent and `processBinaryPlacement`
> unchanged (`git diff` empty for that hunk).

---

### Phase 7 â€” QA + smoke tests

**7.1 Static checks** â€” run Â§5 commands.

**7.2 Unit-ish SQL checks**

```sql
-- enum parity after migration
SHOW COLUMNS FROM commissions LIKE 'type';
SHOW COLUMNS FROM cd_ledger  LIKE 'type';
SHOW COLUMNS FROM packages LIKE 'personal_pv_requirement';
SHOW COLUMNS FROM users LIKE '%_pv';
SELECT key_name, value FROM settings WHERE key_name IN ('binary_repeat_enabled','unilevel_product_enabled');
```

**7.3 Manual smoke matrix** (member = paid account with an upline; admin = `admin`/`Admin@1234`)

| # | Step | Expected |
|---|---|---|
| S1 | Admin â†’ `?page=admin_products` â†’ Create product (name, price 500, Product PV 50, PV% 100, stock 5, upload JPG, unilevel L1=10%) | Row appears; file lands in `uploads/products/product_new_<ts>.jpg`; `product_unilevel_levels` has 10 rows |
| S2 | Edit that product, change price, save with no new file | Old image preserved (`$existing['image_url']` kept); no duplicate file |
| S3 | Check "Remove current image" + save | DB `image_url` NULL **and** file deleted from disk |
| S4 | Delete a product that has order history | Error flash: "Cannot delete product: it has existing repeat-purchase records." |
| S5 | Member â†’ `?page=repeat_purchases` â†’ Add to Cart qty 2 | Redirect to `&cart=1`; drawer auto-opens; badge = 2 |
| S6 | `?page=cart` â†’ stepper `+` / `âˆ’`, then `âœ• Remove` | Totals + badge update live; remove shows **confirm modal** and fires **exactly one** POST; page reloads to empty state at 0 items; **no duplicate-ID console errors** |
| S7 | Offcanvas on an unrelated page (`?page=dashboard`) â†’ qty + | Offcanvas totals update; page totals untouched |
| S8 | `?page=checkout` â†’ e-wallet with sufficient balance | Order `approved`, `paid_at` set, `ewallet_balance` **and** `withdrawable_balance` both reduced, `ewallet_ledger` debit row with `ref_type='registration'` |
| S9 | Order with GCash + proof image | Order `pending`, file in `uploads/repeat_purchase_proofs/`, reservation counted in `Product::availableStock()` |
| S10 | Admin `?page=admin_repeat_purchases&status=pending` â†’ Mark Paid â†’ Approve | 3 endpoints work with the confirm modal; on Approve: `commissions.type='unilevel_product'` rows for the 10-level walk (levels with pct>0 only), `cd_ledger` rows when the upline has CD, `lifetime_earned`/`cap_status` updated via `CapEngine` |
| S11 | Capped upline receives product unilevel bonus | Wallet credit blocked, `commissions` row `status='flushed'` + `cap_deduction` set (cap wins over wallet) |
| S12 | Re-approve an already-approved order | Flash: "Order is already approved."; **no duplicate commissions** |
| S13 | Super-Login (`sadmin` â†’ impersonate member) then open `?page=cart` / add to cart | `imp=` token preserved on every nav link **and** every JS `fetch()` â€” if it drops, S-Login escapes to a normal session |
| S14 | Rows-per-page on `?page=admin_products&per_page=25` | 25 rows; tab/filter params survive; `pg` resets to 1 |
| S15 | Full regression: register a member, check pairing fires, CD bucket drains, DFI runs on manual reset | Identical to pre-change behaviour |

**7.4 Rollback** â€” `git checkout -- core/Commission.php controllers/ views/ index.sql`â€¦ plus DB:
`DROP TABLE cart_items, carts, repeat_purchase_order_items, repeat_purchase_orders, product_unilevel_levels, products;`
and `ALTER TABLE commissions MODIFY type ENUM('pairing','direct_referral','indirect_referral','daily_fixed_income');` (same for `cd_ledger`). Take a `mysqldump` before Phase 1.

**7.5 Phase 7b â€” optional, separate commits (NOT in the minimal plan)**

1. `pv_transactions` audit table + `Commission::recordPvTransaction()` mirror of tmp lines 632â€“644.
2. Replace the 10 duplicated inline rows-per-page forms in live views with `rows_per_page.php`.
3. Refactor `savePackage()` / `Reactivation` to use `upload_image()`.
4. Full PV engine migration (`package_pv_rate`, `pv_per_peso_rate`, `pv_transactions`, royalty) â€” a
   separate multi-day project, **not** this one.

---

## 4. File manifest (every file touched)

**Create (9)**

```
migrate_products_cart.sql
models/Product.php
models/Cart.php
models/RepeatPurchaseOrder.php
views/partials/cart_offcanvas.php
views/partials/rows_per_page.php
views/member/repeat_purchases.php
views/member/cart.php
views/member/checkout.php
views/admin/products.php
views/admin/repeat_purchases.php
```

**Edit (9)**

```
install.sql                                    1.2  (6 insert points)
index.php                                      3.3  (2 insert points: after 314, after 323)
core/helpers.php                               3.1  (3 insert points: after 346, after 377 Ã—2)
core/Commission.php                            6.1  (4 additions, 0 modifications)
models/Package.php                             6.4  (+1 method)
controllers/MemberController.php               4.1  (insert at 132/134)
controllers/AdminController.php                4.2/4.3  (insert at 421/423; $allowed 525â€“552; checkbox list 559)
views/partials/footer.php                      5.2  (insert at line 11)
views/partials/topbar.php                      5.3  (insert before line 89)
views/partials/sidebar_member.php              5.4  (nav + badge render)
views/partials/sidebar_admin.php               5.5  (nav)
views/admin/settings.php                       5.12 (2 checkboxes)
```

**Do not touch:** `assets/**` (already byte-identical where shared), `core/{Auth,CapEngine,DailyFixedIncome,Reactivation}.php`,
`models/{CdStatus,Code,Ewallet,ImpLog,Payout,User}.php`, `core/DailyFixedIncome.php`, `cron/**`,
`index.php` maintenance/seat-limit blocks (lines 47â€“270).

---

## 5. Critical parity points

### 5.1 Duplicate DOM IDs â€” `cart.php` vs `cart_offcanvas.php` (MUST FIX)

`views/partials/footer.php` includes the offcanvas **globally**, and `MemberController::cart()` also renders
`head â†’ member/cart.php â†’ footer`. So `?page=cart` emits **two** elements for each of these 6 IDs:

| ID | `cart.php` (tmp) | `cart_offcanvas.php` (tmp) | Effect |
|---|---|---|---|
| `cartItemsContainer` | line 165 | line 52 | `MutationObserver` (cart.php:384) observes the *page* list; offcanvas's own empty-state rewrite (line 197) hits the wrong node |
| `cartFooter` | line 252 | line 87 | offcanvas's `footer.remove()` (line 202) kills the page summary column |
| `cartItemsCount` | line 259 | line 89 | `getElementById` returns the **page** node â†’ offcanvas badge never updates |
| `cartSubtotal` | line 260 | line 90 | ditto |
| `cartTotalPv` | line 264 | line 94 | ditto |
| `cartTotalPrice` | line 271 | line 98 | ditto |

Fix (namespace the page, leave the global script alone): in the ported `views/member/cart.php` rename
`cartItemsContainer`â†’`cartPageItems`, `cartFooter`â†’`cartPageFooter`, `cartItemsCount`â†’`cartPageItemsCount`,
`cartSubtotal`â†’`cartPageSubtotal`, `cartTotalPv`â†’`cartPageTotalPv`, `cartTotalPrice`â†’`cartPageTotalPrice`,
and update the 5 references inside its inline `<script>` (lines 325â€“335) plus the observer at 384.
Verify with the duplicate-ID grep in Â§6.

### 5.2 Confirm-modal: local vs global

* **Global**: `assets/js/app.js:39` `showConfirm(opts)` â€” already present and identical in live. Opts:
  `title, message, confirmText, confirmClass, formId, onConfirm`.
* **Local**: `views/admin/repeat_purchases.php:383â€“470` ships its own `#actionConfirmModal` driven by
  `data-confirm-title/-message/-btn-text/-btn-class` + `.confirm-action-form` + a `show.bs.modal` interceptor.

Both work; they are simply **two different confirm systems in one codebase**. Ship the local modal as-is
(it is self-contained and doesn't touch `app.js`), but note in code review that a follow-up should unify on
`showConfirm({... onConfirm: () => form.submit()})`. Also note `views/admin/products.php:114` uses a **native**
`onsubmit="return confirm('Delete this product?')"` â€” a third style. Do not attempt to unify in this pass.

### 5.3 `admin/repeat_purchases.php` â€” 3 action endpoints

| UI button (tmp lines) | Form action | Route key | Model call |
|---|---|---|---|
| "Mark as Paid" (316, on `pending`) | `admin_mark_repeat_purchases` (308) | `admin_mark_repeat_purchases` | `RepeatPurchaseOrder::markPaid($id, Auth::id())` |
| "Approve" (342, on `paid`) | `admin_approve_repeat_purchase` (334) | `admin_approve_repeat_purchase` | `RepeatPurchaseOrder::approve($id, Auth::id())` â†’ `Commission::processProductPV()` |
| "Reject" (329 on `pending`, 354 on `paid`) | `admin_reject_repeat_purchase` (321, 346) | `admin_reject_repeat_purchase` | `RepeatPurchaseOrder::reject($id, Auth::id())` |

All three are `POST` + `csrf_verify()` + `flash()` + `redirect()`. Route keys are inconsistently plural/singular
in tmp/altas â€” reproduce exactly (Phase 3.3) so no view edits are needed. Stock is **never** decremented:
`Product::availableStock()` derives availability from `products.stock` minus `reservedStock()`
(`pending|paid|approved` lines), so approving does not change availability but `rejected` frees it.

### 5.4 Capture-phase remove button in `cart.php`

`views/member/cart.php:345â€“361` registers a **capture-phase** (`addEventListener(..., true)`) click handler on
`.cart-remove-btn` and calls `e.preventDefault(); e.stopPropagation();` â€” this deliberately pre-empts the
global bubbling handler in `cart_offcanvas.php:187â€“208` so exactly **one** `remove_cart_item` POST fires and
a confirm modal is shown first. It falls back to direct removal when `showConfirm` is undefined (line 353).
Because both files render the same `.cart-remove-btn` class, **preserving the capture phase is mandatory** â€”
if it regresses to bubble phase, clicking remove fires two POSTs (double decrement / "not found" flash).

### 5.5 `?cart=1` auto-open

`MemberController::addToCart()` redirects to `/?page=repeat_purchases&cart=1` (tmp line 194);
`cart_offcanvas.php:210â€“219` reads `params.get('cart')==='1'` after `bootstrap` loads, calls
`bootstrap.Offcanvas.getOrCreateInstance(cartEl).show()`, then strips `cart` from the URL via
`history.replaceState`. Keep both halves â€” a redirect without the JS is a silent no-op, and the JS without
the redirect never fires. Also keep the `location.pathname + '?' + â€¦ + location.hash` rebuild intact:
with impersonation the pathname stays `/altaslive/` and `imp=` survives in the query string.

### 5.6 `rows_per_page` uses GET with hidden mirrors

`views/partials/rows_per_page.php` (22 lines) renders `<form method="GET" action="<?= APP_URL ?>/">` and
re-emits **every** current `$_GET` key as a hidden input, **except** `per_page` and `pg` (line 12 skips
non-scalars and those two keys). Consequence: changing rows-per-page resets to page 1 but keeps `page`,
`status`, `tab`, `edit`, `view`, `imp`, `q`, `pkg`. Keep the exclusion list exactly â€” including `pg`, which
must be dropped or the selector would submit a stale page number. Its `<label for="rowsPerPage">` appears
twice (lines 15 and 21): valid HTML, harmless, keep for visual parity.

### 5.7 Image uploads

| Purpose | Directory | Helper call | Access pattern in views |
|---|---|---|---|
| Product image | `uploads/products/` | `upload_image($_FILES['image'], 'products', 'product_'.($id ?: 'new'), $oldPath)` | `APP_URL . '/uploads/' . $image_url` |
| Order proof | `uploads/repeat_purchase_proofs/` | `upload_image($_FILES['proof_image'], 'repeat_purchase_proofs', 'proof_'.$memberId)` | `APP_URL . '/uploads/' . $proof_image` |
| (existing) package | `uploads/packages/` | inline code in `savePackage()` | `APP_URL . '/uploads/' . $pkg['image']` |

DB stores **relative** paths (`products/product_7_1781590505.jpg`); `upload_image()` prefixes
`$subDir . '/' . $name`. `delete_uploaded_file()` resolves relative to `uploads/` and `@unlink`s. Filenames are
`{prefix}_{time()}.{ext}` (no `mt_rand`, unlike `savePackage()`'s inline version â€” collisions are possible if
two products are saved in the same second; acceptable, but note it). `.htaccess` already serves `uploads/`
as real files; no new rules needed.

### 5.8 PV rendered as money â€” exact fix list

| File | Line | Broken | Fix |
|---|---|---|---|
| `views/partials/cart_offcanvas.php` | 94 | `<?= fmt_money((float)$cartTotals['total_pv']) ?>` | `<?= number_format((float)$cartTotals['total_pv'], 2) ?> PV` |
| `views/partials/cart_offcanvas.php` | 138 | `'â‚±' + parseFloat(totals.total_pv).toFixed(2)` | `parseFloat(totals.total_pv).toFixed(2) + ' PV'` |

(Consistency check: `cart.php:331` in the capture-phase script already writes PV as `'0.00 PV'`, and
`cart.php:264` renders `number_format($totalPv,2) . ' PV'` â€” so the offcanvas is the outlier, and after the
fix the two components agree.)

---

## 6. Verification commands

Run from `C:\laragon\www\altaslive` (PowerShell; drop the `Select-String`â†’`grep -n` swap on Linux).

**V1 â€” Lint every touched PHP file (gate for Phases 2â€“6)**

```powershell
php -l index.php; php -l core/helpers.php; php -l core/Commission.php
php -l controllers/MemberController.php; php -l controllers/AdminController.php
php -l models/Product.php; php -l models/Cart.php; php -l models/RepeatPurchaseOrder.php
Get-ChildItem views -Recurse -Filter *.php | Where-Object { $_.LastWriteTime -gt (Get-Date).AddHours(-6) } | ForEach-Object { php -l $_.FullName }
```

**V2 â€” All 14 routes registered, and every route target method exists**

```powershell
Select-String -Path index.php -Pattern "'cart'|'add_to_cart'|'update_cart_item'|'remove_cart_item'|'checkout'|'place_order'|'repeat_purchases'|'admin_products'|'admin_save_product'|'admin_delete_product'|'admin_repeat_purchases'|'admin_mark_repeat_purchases'|'admin_approve_repeat_purchase'|'admin_reject_repeat_purchase'" | ForEach-Object { "$($_.LineNumber): $($_.Line.Trim())" }
Select-String -Path controllers\MemberController.php -Pattern "function (repeatPurchases|cart|addToCart|updateCartItem|removeCartItem|checkout|placeOrder)\("
Select-String -Path controllers\AdminController.php  -Pattern "function (products|saveProduct|deleteProduct|repeatPurchaseOrders|markRepeatOrderPaid|approveRepeatOrder|rejectRepeatOrder)\("
```

**V3 â€” No undefined helpers / no PV-as-money / no duplicate cart IDs (the three parity traps)**

```powershell
Select-String -Path views -Recurse -Pattern "per_page\(|upload_image\(|delete_uploaded_file\(" | Group-Object { ($_.Line -replace '.*?(per_page|upload_image|delete_uploaded_file)\(.*','$1') } | Select-Object Name,Count
Select-String -Path core\helpers.php -Pattern "function (per_page|upload_image|delete_uploaded_file)\("
Select-String -Path views\partials\cart_offcanvas.php -Pattern "fmt_money\(\(float\)\$cartTotals\['total_pv'\]\)|'â‚±' \+ parseFloat\(totals\.total_pv\)"
Select-String -Path views\member\cart.php -Pattern 'id="cart(ItemsContainer|Footer|ItemsCount|Subtotal|TotalPv|TotalPrice)"'
Select-String -Path views -Recurse -Pattern "APP_URL \?>/\?page=" | Measure-Object   # must be 0 in ported views
Select-String -Path models,core,controllers,views -Recurse -Pattern "pv_per_peso_rate"  # must be 0
```

**V4 â€” Commission engine is additive-only (the Â§7 regression guard)**

```powershell
git diff --numstat core/Commission.php          # second number must be 0
Select-String -Path core\Commission.php -Pattern "function (processBinaryPlacement|processDirectReferral|processIndirectReferral|processBinaryVolume|processProductPV|processProductUnilevel|meetsPersonalPvRequirement)\("
Select-String -Path core\Commission.php -Pattern "processBinaryPV"   # must be 0 hits
git diff core/Commission.php | Select-String -Pattern "^-" | Where-Object { $_ -notmatch '^---' }   # must be empty
```

**V5 â€” Add-to-cart / admin-save smoke test (browser + SQL)**

```
1) admin  : /?page=admin_products          â†’ create product (price 500, product_pv 50, pv_value 100, stock 5, L1 10%) â†’ expect flash "Product created."
2) member : /?page=repeat_purchases        â†’ Add to Cart (qty 2) â†’ expect redirect ?cart=1, drawer opens, badge 2
3) SQL    : SELECT quantity,unit_price,unit_pv FROM cart_items ORDER BY id DESC LIMIT 1;   -- 2 | 500.00 | 50.00
4) admin  : /?page=admin_save_product (POST, CSRF) â†’ expect "Product updated." and uploads/products/product_<id>_<ts>.jpg
5) SQL    : SELECT COUNT(*) FROM product_unilevel_levels WHERE product_id=<id>;           -- 10
```

---

## 7. Non-regression contract (highest priority)

**The following must remain byte-identical in `core/Commission.php` unless a bug is proven:**

| Member | Live lines | Why |
|---|---|---|
| `processBinaryPlacement()` | 28â€“169 | Registration-time placement, CD/paid leg counts, `left_count_paid`/`right_count_paid`, `left_pair_volume`/`right_pair_volume`, daily-cap + flush math. Product volume must **reuse** this math via `applyBinaryVolume()`, never re-enter this method with a product source (it hardcodes `newVolume` from `packages.pairing_bonus` at lines 51â€“59 and would double-count). |
| `processDirectReferral()` | 177â€“267 | Reference template for the new unilevel credit block. |
| `processIndirectReferral()` | 275â€“~420 | 10-level sponsor-chain walk + `recordCapBlocked(..., 'indirect_referral', ..., $lvl)`. Do not merge product unilevel into it â€” different commission type, different gate, different base. |
| `creditPairing()` | 421â€“495 | CD â†’ cap â†’ `commissions(pairing, GROSS)` â†’ `Ewallet::credit` â†’ `CdStatus::recordLedger` â†’ `CapEngine::recordEarning`. |
| `recordFlush()` | 497â€“509 | `status='flushed'` audit row for daily-cap overflow. |
| `recordCapBlocked()` | 514â€“542 | **Only** the `match ($type)` description arm may gain a `'unilevel_product'` case; its default arm already covers it. The `$type` value is inserted into `commissions.type` â€” Phase 1 must ship the enum change first. |
| `summary()`, `recent()`, `history()`, `capBlockedHistory()` | 545â€“602 | Read-only reporting. If they `GROUP BY type`, no change needed (`unilevel_product` simply appears). |

**Add, never replace:** `meetsPersonalPvRequirement()`, `processBinaryVolume()`, `applyBinaryVolume()`,
`processProductPV()`, `processProductUnilevel()`. No PV columns from tmp/altas
(`left_pv`/`right_pv`/`paired_pv`/`flushed_pv`) are introduced; no `pv_transactions` writes; no `Royalty` class;
no `pv_per_peso_rate`; no `binary_enabled` global gate. If a future task wants the full PV engine, that is
Phase 7b.4 and it must land as its own reviewed change with a migration plan for existing balances.

---

## 8. Explicitly out of scope

`core/Royalty.php` + `royalty_pool` + rank columns + `member_royalty` route/view Â·
`genealogy.php?view=product_unilevel` tab (referenced by tmp's sidebar line 41) Â·
`pv_transactions` Â· `package_pv_rate`/`binary_pv_pct`/`pairing_pv_pct`/`direct_ref_pv_pct`/`dfi_pv_pct` Â·
`cron/monthly_pv_reset.php` Â· `?bypass=` maintenance token Â· `SHOW_FRONTEND` toggle Â·
retro-fitting `rows_per_page.php` into the 10 existing paginated views Â· unifying the three confirm-modal styles.

---

## 9. Risk register

| Risk | Sev | Mitigation |
|---|---|---|
| Copying tmp `Commission.php` wholesale destroys the peso engine | **Critical** | Â§0/Â§7 contract; `git diff --numstat` gate (V4) shows `-0` |
| `products.product_pv` interpreted as â‚± instead of PV units surprises admins migrating data | High | Document in the admin form helper text ("Product PV (â‚± base)"), name the preview `â‰ˆ â‚±` without a 1000Ã— factor, and state the rule in the commit message |
| `personal_pv_requirement` gate silently blocks all uplines | High | Column defaults `0.00` â‡’ gate disabled; `meetsPersonalPvRequirement()` returns `true` first thing when `$req <= 0` |
| `unilevel_product` missing from `commissions`/`cd_ledger` ENUM â‡’ fatal on flush path | High | Migration steps 9/10 ship in Phase 1, before any code |
| Duplicate DOM IDs break live totals on `?page=cart` | Med | Â§5.1 rename + V3 grep |
| Raw `ewallet_balance` UPDATE leaves `withdrawable_balance` inflated | High | Use `Ewallet::debit()` (Â§4.1 edit 2) |
| `?page=` URLs dropped from JS `fetch()` in ported views break Super-Login | High | `link_to()` conversion rule + S13 smoke test |
| `products.stock` never decremented â†’ oversell | Med | Reservation model is intentional (`reservedStock()` counts `pending|paid|approved`); document that `rejected`/`cancelled` frees stock |
| Two `product_<id>_<ts>.*` uploads collide in the same second | Low | Cosmetic only; optional `mt_rand()` suffix, mirroring `savePackage()` |
| `Product::delete()` refuses deletes once orders exist | Low | Intended FK `RESTRICT` behaviour; surfaced as a friendly flash |
| `per_page()` default 10 vs `paginate()` default 20 | Low | New code always passes `per_page()` explicitly; `paginate()` signature untouched |

---

*Implementation Plan â€” Add-to-Cart & Admin Product Management (sync tmp/altas â†’ altaslive) â€” prepared 2026-10-06.*
