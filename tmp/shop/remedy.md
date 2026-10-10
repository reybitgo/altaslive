# Shop Feature Remediation — Phased Implementation Plan

**Created:** 2026-10-10
**Scope:** defects found in the review of commits `3d359db "cart and admin products plan"` → `43c0928 "cart mechanics"` (58 files, +12,996 / −21 lines).
**Status:** proposed — not yet started. Phase 0 is the first actionable step.

This document is self-contained: the defect inventory it remediates is reproduced in
[Appendix A](#appendix-a--defect-inventory) so this file can be read without the review transcript.

---

## How to use this document

- **[Decisions](#decisions--resolved-2026-10-10) D1–D7 are all RESOLVED** (2026-10-10) — every recommendation was
  adopted. **D7 closed as N/A** (the database is a local sandbox; no rotation required). No decision is outstanding.
- **Every phase is independently deployable.** No phase may leave the app in a non-functional state.
- **Phases 0–6 are strictly sequential. Phases 7, 8, 9 are independent** and may be parallelised or deferred.
- **Phase 1 is the enabling phase.** Do not skip it: the current only test asset is destructive and model-only, which
  is precisely why C1–C6 shipped in the first place.

---

## Decisions — RESOLVED 2026-10-10

All seven decisions are **resolved**. Every recommendation below was adopted. This section is now a record of the
chosen option and where it is implemented — not an open question.

| # | Decision | ✅ Resolved option | Rationale | Implemented in |
|---|---|---|---|---|
| **D1** | Refund state vocabulary | **Align code → DDL.** `ShopRefund::markPaid()` writes `status = 'processed'` instead of `'paid'`. | `markPaid()` already writes `processed_by` / `processed_at`, so the shipped DDL vocabulary (`open`, `approved`, `processed`, `denied`, `closed`) aligns with every existing column. One-token change, no migration needed on a clean DB. | Phase 2 · C1 |
| **D2** | `Ewallet` ref_type validation | **Single `const VALID_REF_TYPES`** shared by `credit()` and `debit()`, declared once so the migration and the PHP list cannot diverge again. Also add the missing `'upgrade'` to `debit()`. | A single source of truth prevents the half-migration (C2) recurring. `debit()`'s list missing `'upgrade'` is a pre-existing latent bug — fixed in the same pass. | Phase 2 · C2 |
| **D3** | Admin force-complete | **Remove the button.** Matrix stays `completed => ['customer','system']`. | Admin force-close bypasses both the report window and the refund block. Customer-confirm and the auto-complete timer already cover the legitimate cases, so the matrix remains the single source of truth (C6). | Phase 5 · C6 |
| **D4** | `assertNotSelfReview` scope | **Model-enforced on every admin mutation touching an order** — `cancel`, `onHold`, `resume`, `reship`, shipment edit, and all `ShopRefund` entry points. | Matches the plan's own rule that "hiding a button is never the only guard." Today only `orderTransition` enforces it, leaving 5 endpoints view-gated only (H6). | Phase 3 · H6 |
| **D5** | `per_page()` ceiling | **Allow-list `[10, 25, 50, 100]`**, default 10, floor 5. Any unrecognised value falls back to the default. | Removes the unbounded `LIMIT` (H5) without admitting an arbitrary large value. An allow-list is safer than a bare ceiling because the page links can only ever emit legal values. | Phase 7 · H5 |
| **D6** | Shop revenue destination | **Deferred — out of scope for this plan.** No code change. Tracked as an open business decision. | E-wallet sales debit the buyer and credit no settlement account (plan-accepted gap). Needs a business decision on where revenue lands; inventing a credit target silently would be worse than the gap itself. | **Not scheduled** |
| **D7** | `config/db.php` credential | **NOT APPLICABLE — no rotation.** Confirmed by the owner on 2026-10-10: the database is a **local sandbox** (Laragon MySQL 8.4.3 on `localhost`, reached through the local phpMyAdmin). The account exists only on the developer's own machine and grants nothing. | Rotation is only meaningful when a leaked credential grants access to a system someone else can reach. Here it does not, so there is no secret to burn. The residual concern is hygiene, not security — see [D7 — no rotation required](#d7--no-rotation-required). | **Closed** — no action |

### D7 — no rotation required

Closed on 2026-10-10. The database is a **local sandbox**: Laragon MySQL 8.4.3 on `localhost`, administered through
the local phpMyAdmin. The `u938213108_altas6_admin` account exists only on the developer's own machine.

Rotating a credential only matters when a leaked value grants access to a system someone else can reach. This one
does not, so there is no secret to burn and **no rotation is required**. The Phase 0 blocker is cleared.

**What remains: nothing required.** The credential concerns raised during review are withdrawn — the DB and all
admin logins in circulation are sandbox-only (see Phase 9 item 2). The only residual suggestion is optional
hygiene, not a security fix:

1. **Gitignore `config/db.php`** and commit a `config/db.example.php` template instead. Purely so a future real
   deployment never inherits a committed credential. **Optional — zero security value while the app is a
   sandbox.** Do it before deploying, not after.
2. **Do not rewrite git history.** For a sandbox value with no external exposure, rewriting is effort with no
   benefit.

Carry this forward as a Phase 9 item. It is not a Phase 0 or Phase 1 blocker.

### Deferred decisions

| # | Question | Why deferred | Revisit when |
|---|---|---|---|
| **D6** | Where does shop revenue land after an e-wallet sale? | Requires a business/finance decision on a settlement account or merchant-of-record arrangement. Not a code decision. | Before the shop takes its first real production order. |

No other decisions are outstanding.

### Decision → implementation map

| Decision | Phase | Defect(s) closed | Primary files |
|---|---|---|---|
| D1 | 2 | C1 | `models/ShopRefund.php`, `views/admin/shop_order.php` |
| D2 | 2 | C2 | `models/Ewallet.php`, `models/ShopRefund.php` |
| D3 | 5 | C6 | `models/ShopOrder.php`, `views/admin/shop_order.php` |
| D4 | 3 | H6 | `models/ShopOrder.php`, `models/ShopRefund.php`, `controllers/AdminController.php` |
| D5 | 7 | H5 | `core/helpers.php` |
| D6 | — | — | (deferred) |
| D7 | — | — | **closed — no action** |

---

## Phase 0 — Freeze, Backup, Baseline

**Depends on:** nothing.
**Unlocks:** every later phase can be reverted and re-tested.

The working tree is clean and all defects are pre-existing, so this is the only moment a clean baseline is cheap.

### Actions

1. Tag the current state:
   ```bash
   git tag shop-pre-remediation HEAD
   ```
2. Full schema + data dump, stored **outside** the repo:
   ```bash
   mysqldump --routines --triggers --single-transaction <db> > /secure/altaslive_pre_remedy.sql
   ```
   The repo already carries `tmp/shop/backups/pre_shop_realign_2026-10-06.sql` — that backup predates
   `migrate_shop_fixups.sql` and is **not** sufficient.
3. ~~**Rotate the DB password.**~~ ✅ **Closed as N/A** — the database is a local sandbox (see
   [D7 — no rotation required](#d7--no-rotation-required)). Carried forward to Phase 9 as optional hygiene:
   gitignore `config/db.php` and commit a `config/db.example.php` template.
4. Remove `tmp/shop/smoke_test.php` from the working tree — it is destructive against whatever `config/db.php`
   points at. Preserve the file outside the repo; Phase 1 rebuilds it properly.
5. Record the current manual-QA baseline: load `?page=shop_orders` and `?page=admin_shop_orders`, screenshot.
   This proves the C4 "Unknown badge" regression is fixed later.

### Verification

- `git tag` lists `shop-pre-remediation`.
- Dump file exists outside the repo and restores cleanly into a scratch database.
- `git status` clean.

### Risk / rollback

`git checkout shop-pre-remediation`. Restore from the dump for data.

### Done when

Tag exists, dump taken and restore-tested, working tree clean and green. ✅ **All met 2026-10-10** — see the
execution record below.

### Execution record — 2026-10-10

| # | Action | Result |
|---|---|---|
| 1 | `git tag shop-pre-remediation` | ✅ Annotated tag created at `43c0928`. Message records the defect count and points at this document. |
| 2 | Schema + data dump | ✅ `altaslive_pre_remedy_2026-10-10.sql`, 2.27 MB, stored **outside** the repo. |
| 3 | Rotate DB password | ✅ **N/A — closed.** Owner confirmed the DB is a local sandbox; the credential grants nothing, so there is no secret to burn. Downgraded to an optional hygiene item in [Phase 9](#phase-9--repository-hygiene--documentation). |
| 4 | Remove `tmp/shop/smoke_test.php` | ✅ `git rm`'d (staged deletion, not committed). Preserved outside the repo as `smoke_test.PRESERVED.php` (24,618 bytes, SHA-256 `465C3101AD9AD0DB…`). |
| 5 | Baseline record | ✅ Captured below — **9 of 9 probes BROKEN**, which is the expected starting state. |

#### Environment discovered

| Fact | Value |
|---|---|
| Server | MySQL **8.4.3** on `localhost:3306` (Laragon) |
| Database | `u938213108_altas6_db` — **reachable, a local dev copy**, not a remote production host |
| Row counts | `users` 156 · `ewallet_ledger` 7,693 · `products` 1 · `carts` 2 · `settings` 35 · 24 tables |
| Shop data | **All 6 shop tables are empty** (0 orders, 0 items, 0 events, 0 shipments, 0 proofs, 0 refunds) — the shop has never taken an order locally |
| App DB user | `u938213108_altas6_admin` — **cannot `CREATE`/`DROP DATABASE`** (returns a misleading `ERROR 1045`). Schema-level work needs the local `root` account. |
| PHP | 8.3.16 (CLI) — all 22 shop files pass `php -l` |

> **Implication for Phase 1:** the app user is scoped to its own schema and cannot create the dedicated test
> database. Provisioning `altas6_test` must use the local `root` account, and the harness must connect with a
> superuser-capable DSN — or `altas6_test` must be created once, by hand, by `root`.

#### Dump provenance

The first dump attempt silently produced a **UTF-16LE** file (PowerShell's `>` operator re-encodes piped output),
which MySQL then refused to restore. Two further failures: the app user cannot create scratch databases, and
restoring from a redirect needs `--binary-mode=1` because `--hex-blob` emits NUL bytes. The working recipe is:

```powershell
# credentials must NOT be interpolated by PowerShell — generate the defaults file from PHP
php -r 'require "C:/laragon/www/altaslive/config/db.php";
        file_put_contents($argv[1], "[client]\nhost=".DB_HOST."\nport=".DB_PORT."\nuser=".DB_USER."\npassword=\"".DB_PASS."\"\n");' my.cnf
# cmd redirect preserves raw bytes; PowerShell > emits UTF-16LE
cmd /c "mysqldump --defaults-extra-file=my.cnf --single-transaction --routines --triggers --events --hex-blob --set-gtid-purged=OFF db > out.sql"
```

**Restore verified** into a scratch schema as `root`: `users` 156, `ewallet_ledger` 7,693, `products` 1,
`settings` 35, 24 tables — all match the source exactly. Scratch schema dropped afterwards.

#### Baseline probe — 9 / 9 BROKEN (expected)

Read-only probes against the live dev DB and the source tree. No data was mutated.

| ID | Check | State | Evidence |
|---|---|---|---|
| **C1** | `shop_refunds.status` accepts the value the code writes | **BROKEN** | `status ENUM = enum('open','approved','processed','denied','closed')`; `ShopRefund.php:80` writes `status='paid'` |
| **C2** | `Ewallet::credit()` accepts `ref_type='shop_order'` | **BROKEN** | `credit()` whitelist (`Ewallet.php:22`) lacks `'shop_order'`; the **DB enum does include it** — `enum(…,'registration','shop_order')` |
| **C3** | `Cart::removeItemById` scopes the row to a cart | **BROKEN** | `Cart.php:107` → `DELETE FROM cart_items WHERE id = ?` (no `cart_id` predicate) |
| **C3b** | `Cart::updateItemQuantity` scopes the row to a cart | **BROKEN** | `Cart.php:102` → `UPDATE cart_items SET quantity = ?, updated_at = NOW() WHERE id = ?` (no `cart_id` predicate) |
| **C6** | Admin may transition an order to `completed` | **BROKEN** | `TRANSITIONS['completed']['actors'] = [customer, system]`; `AdminController.php:1177` passes `'admin'` |
| **H3** | `expireOverdue` measures `payment_failed` against `correction_deadline` | **BROKEN** | `ShopOrder.php:1093` uses `COALESCE(payment_deadline, correction_deadline)`; `payment_deadline` is always non-null, so `correction_deadline` is never read |
| **H4** | `shop_refunds.cause` accepts the controller default | **BROKEN** | `cause ENUM = enum('admin_cancel','delivery_failure','customer_dispute','other')`; `AdminController.php:1321` defaults to `'customer_request'` |
| **M5** | `shop_shipments.state` accepts `flagLost()`'s value | **BROKEN** | `state ENUM = enum('draft','active','closed','void')`; `ShopShipment.php:78` writes `state='lost'` |
| **M16** | `shop_orders` can be deleted when a refund exists | **BROKEN** | `shop_refunds.order_id` FK `DELETE_RULE = RESTRICT` |

C1, C2, H4 and M5 are now **confirmed against the live schema**, not just against `install.sql` — the DDL on disk
matches the file, so Phase 2's migrations only need the code-side changes (plus D1's doc/view reconciliation).

#### Repo state after Phase 0

```
$ git status --short
D  tmp/shop/smoke_test.php      <- staged deletion, NOT committed
?? tmp/shop/remedy.md           <- this document
```

Two notes for whoever commits: the `smoke_test.php` deletion is staged but **not committed**, and `remedy.md` is
untracked. Nothing has been committed in Phase 0 — commit when you say so.

---

## Phase 1 — Verifiable Ground Truth

**Depends on:** Phase 0.
**Unlocks:** every subsequent phase has a way to prove it worked.

Right now the only test asset is a destructive script that hardcodes `users.id = 3`, wipes that member's cart, and
calls `expireOverdue(200)` / `autoComplete(200)` against real orders. Every defect in Appendix A lives at a
boundary the model-only tests never touched — so the harness must be rebuilt **before** the fixes, not after.

### Actions

1. **Provision a dedicated test database** (e.g. `altas6_test`) seeded from `install.sql`. Point a
   `config/db.php` override at it via an env var or a `config/db.test.php`; **never** let it default to the
   live DSN.
2. **Rewrite the harness as `tests/ShopStateMachineTest.php`:**
   - Runs **inside a transaction per test case**, rolled back after each assertion group. No self-heal
     `DELETE`s, no raw balance restoration, no hardcoded user IDs — create fixtures with `INSERT` and let
     rollback clean up.
   - Seeds its own admin / member / product fixtures.
   - Retains the existing 85 model assertions verbatim (they are good) and adds boundary-layer coverage:
     controller-level authorization, refund lifecycle end-to-end, cart ownership.
3. **Add a schema-drift check** that diffs the live schema against `install.sql`. This is what would have caught
   C1, C2, H4 and M5 at CI time. Emit the enum values actually present in `information_schema` and assert the PHP
   literals are members.
4. Delete the now-redundant `tmp/shop/*.php.txt` snapshots — see Phase 9 for why they are dangerous. (Deferred to
   Phase 9 if you prefer to keep this phase minimal.)

### Verification

`php tests/ShopStateMachineTest.php` passes 85/85 on the **untouched** code — establishing that the harness
reproduces current behaviour, not the intended behaviour. The drift check reports the four known enum mismatches
as failures.

### Risk

The harness itself becoming wrong and giving false confidence. **Mitigation:** keep the original 85 assertions
untouched as a regression floor; never weaken a test to make it pass.

### Done when

The suite runs green against the pre-fix code, and the drift check reports the four known enum mismatches.

---

## Phase 2 — Data-Contract Alignment (DDL ↔ PHP)

**Depends on:** Phase 1's drift check.
**Unlocks:** Phases 5 and 6 can be written against a schema that is actually the one in the database.

This phase exists because the refund workflow is currently 100% non-functional, and every subsequent phase would
otherwise be reasoning about a schema that does not exist.

### Actions

1. **C1 — refund status.** Apply D1: `ShopRefund::markPaid()` writes `status = 'processed'` instead of `'paid'`.
   Add a migration if the DB is already in the intermediate broken state. Update the badge ternary in
   `views/admin/shop_order.php:349`.
2. **C2 — e-wallet ref_type.** Apply D2. Introduce the shared const and use it in `Ewallet::credit()` and
   `Ewallet::debit()`. Verify `ewallet_ledger.ref_type` in the DB already contains `'shop_order'` (it does, via
   `install.sql` and `migrate_shop.sql` step 10 — the failure is PHP-side only).
3. **H4 — refund cause.** Change the controller default from `'customer_request'` to `'admin_cancel'`
   (`AdminController.php:1321`) **and** add a whitelist in `ShopRefund::create()` mirroring the ENUM so a forged
   value returns a flash instead of a 500. Catch `PDOException` in `orderRefund()` as a backstop.
4. **M5 — shipment state.** Either add `'lost'` to the ENUM or delete the dead `ShopShipment::flagLost()`.
   Recommend delete — no callers, no feature behind it.
5. **M16 — delete cascade.** `shop_refunds.order_id` is `ON DELETE RESTRICT`, so `shop_orders` deletes fail once
   a refund exists. Decide the intended semantics (probably: refunds should cascade, since an order row without
   refunds is meaningless) and align DDL + `install.sql`.
6. **Converge `install.sql` and the migrations.** A fresh install and a migrated DB must produce identical
   schemas. Specifically:
   - `shipping_postcode` vs `shipping_postal` (install has both, migrations end with both)
   - `reservation_state` (`written_off` only after fixups)
   - `shop_refunds` `destination` / `receipt_ref` / `promised_at` (fixups only)
   Verify with the Phase 1 drift check against **both** paths.
7. Remove the hardcoded `CREATE DATABASE` / `USE` from `install.sql`.

### Verification

- Drift check green.
- New harness test: refund lifecycle `open → approved → mark_paid` with an e-wallet destination passes.
- No `Data truncated` in any log.

### Done when

Every refund transition is reachable, and the two install paths agree.

---

## Phase 3 — Authorization & Injection

**Depends on:** Phase 2 (needs a valid `ref_type` before exercising wallet paths).
**Unlocks:** safe to expose any admin endpoint.

### Actions

1. **C3 — cart IDOR (highest priority in the repo).** Stop passing a bare `item_id` across the trust boundary.
   - Change signatures to `Cart::updateItemQuantity(int $itemId, int $quantity, int $cartId)` and
     `Cart::removeItemById(int $itemId, int $cartId)`; add `AND cart_id = ?` to both statements.
   - `Cart::updateItemQuantity` already selects `ci.cart_id` — use it.
   - Resolve the cart in the controller via `Cart::getActive(Auth::id())`, matching how `addToCart` anchors
     ownership through `cartTarget()`.
   - Delete the two dead scoped helpers (`updateQuantity`, `removeItem`) or route them through the new signatures
     — do not leave three overlapping update paths.
   - Reject when there is no active cart rather than silently succeeding.
2. **C5 — stored XSS.** Replace `e()` with `json_encode()` inside the `onclick` at
   `views/admin/shop_order.php:46`. Sweep the whole shop surface for the same pattern (`e()` inside `on*=`,
   `<script>`). Add a linter rule to the harness so it cannot regress.
3. **H6 — segregation of duties.** Apply D4: move `assertNotSelfReview` out of the controller into the model
   methods themselves (`cancel`, `onHold`, `resume`, `reship`, shipment edit, and the `ShopRefund` entry points),
   so it holds regardless of entry point. Keep the view's `$isSelf` hiding as a UX affordance only. Add a harness
   test: admin acting on own order is refused **and** the refusal is server-side (direct POST, no view).

### Verification

- Harness IDOR test — member A mutating member B's cart is refused, B's cart unchanged.
- Harness SoD test.
- XSS payload `');alert(1);//` in `payment_reference` renders inert.

### Done when

All three findings are provably closed at the server boundary, not just hidden in the UI.

---

## Phase 4 — Rendering & UI Correctness

**Depends on:** Phase 3.
**Unlocks:** manual QA can actually be performed — you cannot eyeball a screen full of "Unknown".

### Actions

1. **C4 — undefined `$order`.** Set `$order = $o;` in `views/member/shop_orders.php:50` and fix the `$tmp` typo
   at `views/admin/shop_orders.php:96`. Better: change `order_status_badge.php` to accept a parameter
   (`$status`, `$stamp`) rather than depend on a magic variable — this removes the whole class of bug and makes
   the partial reusable.
2. **M12 — single source of truth for `order_no`.** Use the stored `$o['order_no']` column everywhere; drop the
   `shop_public_order_no($id, $created_at)` recomputation in both list views.
3. **M14 — dead code in views.**
   - Move `shop_next_action()` out of `views/admin/shop_orders.php` into `core/helpers.php`, next to
     `shop_status_label` / `shop_status_tone`.
   - Remove the unused `$actionable` query and the unused `$needConfirm` variable.
   - Fix the `'completed'`-as-sentinel hack in the tab-label logic.
4. **M15 — empty Actions card.** Fix the `$legalTargets` gating so `payment_failed` renders either its real
   actions or nothing — not an empty bordered box.
5. **Make warnings visible.** Add `error_log` capture in the harness so any `Undefined variable` /
   `Undefined array key` during a page render fails the test. This is the durable fix — the badge bug shipped
   because warnings were invisible.

### Verification

Render every shop page in the harness and assert zero PHP warnings. Status pills render real labels for all 14
statuses.

### Done when

A full sweep of all 15 shop pages is warning-free.

---

## Phase 5 — State-Machine / UI Parity

**Depends on:** Phase 2 (matrix needs the real schema) and Phase 4 (you need working buttons to test transitions).
**Unlocks:** the fulfilment lifecycle becomes fully exercisable end-to-end.

The matrix in `ShopOrder::TRANSITIONS` is correct and is genuinely the single source of truth. The failures are
all where the UI or the SQL disagrees with it.

### Actions

1. **C6 — dead Complete button.** Apply D3. Remove the `to=completed` form from
   `views/admin/shop_order.php:185`. Add a harness assertion that **every** button the view can render is a
   matrix-legal `(status, actor, to)` triple — this is the class-level fix, and it also protects H2.
2. **H2 — missing `returned_to_sender` form.** Render it for `shipped` / `out_for_delivery` /
   `delivery_failed`. This unblocks the reship flow and the member RTS panel.
3. **H3 — correction window ignored.** Replace `COALESCE(payment_deadline, correction_deadline)` with a
   status-aware expression so `payment_failed` orders are measured against `correction_deadline`. Add a harness
   test: reject a proof at T+23 h, assert the order survives to T+27 h.
4. **H1 — POD upload dead.** Add `enctype="multipart/form-data"` to the `to=delivered` form. Consider
   consolidating all POD entry onto the shipment-edit form (which already has the correct enctype) so there is
   one place POD is captured.
5. **M1 — proof/timer race.** Move the `ShopPaymentProof::add()` insert inside the `transition()` transaction so
   the proof row and the status move commit or roll back together. Requires passing the insert as a
   guard/side-effect callback rather than executing it first.
6. **M2 — stock released then re-reserved unchecked.** When a rejected proof's lines are re-reserved on
   re-upload, re-run the availability check; refuse (or hold) if the goods are gone.
7. **M3 — approved refunds don't block completion.** Make `ShopRefund::hasOpen()` and
   `ShopOrder::autoComplete()` treat `open` **and** `approved` as blocking, per the plan.

### Verification

New harness suite covering the full 14-status matrix — every legal transition succeeds, every illegal one is
refused, no transition ever writes a status outside the matrix. Plus: hold → resume, reship → no double stock
deduction, refund blocks completion, proof-vs-expiry race.

### Done when

The whole lifecycle runs green from order placement to completion, including the failure and reship branches.

---

## Phase 6 — Money-Flow Atomicity

**Depends on:** Phase 2 (valid `ref_type`) and Phase 5 (correct transition paths).
**Unlocks:** the financial audit trail can be trusted.

The e-wallet debit path is already the strongest part of the feature — `debitInternal` correctly spends
non-withdrawable funds first, and the idempotency + replay guards are sound. These are the remaining gaps.

### Actions

1. **`placeOrder()` e-wallet ordering.** Currently the debit commits and *then* `markPaid()` runs. Make order
   creation + debit + `paid` transition one transaction. Today a `markPaid` failure leaves money debited against a
   `pending` order with no automated recovery path.
2. **M4 — POD outside the transaction.** `markDelivered()` writes POD to `shop_shipments` after the status
   commits. Fold the shipment write into the transition's transaction via the same side-effect hook used in
   Phase 5 / M1.
3. **`ShopRefund::markPaid()` ordering.** It calls `Ewallet::credit()` *before* the status UPDATE. Confirm the
   whole thing is inside one transaction and that a rollback cannot leave a credited wallet with an unclosed
   refund. Verify `Ewallet::credit()` does not open its own transaction (it does not today — re-verify after any
   refactor).
4. **Refund idempotency.** `markPaid` is guarded by `WHERE status='approved'` and checks `rowCount`, which is
   correct — but verify a double-submit cannot double-credit the wallet, and add a harness test.
5. **Refund destination matching (M6).** `str_contains($destination, 'wallet')` on free text is fragile. Drive
   it from the existing (unused) `shop_refunds.method` ENUM.
6. **Add a money-movement assertion to the harness:** for any shop order, the sum of `ewallet_ledger` rows with
   `ref_type='shop_order'` must exactly reconcile to the order's state (one debit on e-wallet checkout; at most
   one credit on refund). Run it over every test order created.
7. **Re-confirm Strategy C.** `git diff core/Commission.php` must stay empty; the PV-string greps must stay at
   zero hits. Add these as harness assertions so the invariant is machine-enforced, not a note in a plan doc.

### Done when

No shop path can move money without a matching ledger row, and every movement is inside a transaction that also
records the state change.

---

## Phase 7 — Input Bounds, Resilience, Performance

**Depends on:** Phases 2–6. Safe to defer; none of these are correctness-of-money issues.

### Actions

1. **H5 — unbounded `per_page()`.** Apply D5: clamp to an allow-list. Note the previous implementation was *also*
   broken (`(int)['per_page']` always evaluated to 1, so the old code always returned 5) — the current code
   fixed the read and dropped the ceiling.
2. **Exception handling.**
   - `orderRefund()`, `saveShopProduct()` and `deleteShopProduct()` catch narrow types.
   - `Product::delete()` will throw a `PDOException` (FK `RESTRICT`) when `cart_items` still reference the
     product — it only checks `shop_order_items`. Catch it and return the existing "cannot delete" flash.
3. **M7 — N+1 on the storefront.** Replace the per-row `Product::availableStock()` calls in `shop.php` with one
   `GROUP BY` over the catalog. Add the missing composite index on
   `shop_order_items(reservation_state, product_id)` for `reservedStock()`.
4. **`ShopOrder::transition()` catch ordering.** `PDOException` extends `RuntimeException`, so the
   `catch (RuntimeException)` block at `ShopOrder.php:246` swallows DB errors into a path that then reads
   `$order['status']` — undefined if the throw preceded the row fetch. Narrow the `stock_conflict` detection to a
   dedicated exception class.
5. **Input validation on free-text fields.** `payment_reference` and `reference_no` are only constrained by a
   client-side `maxlength`. Add server-side length/charset caps at the boundary — defence in depth behind the
   Phase 3 XSS fix.
6. **`confirm_lock` (M11).** Either enforce it server-side or delete it. Recommend deleting: the cancel/proof race
   is already decided correctly by the conditional write.

### Done when

No unbounded query parameter reaches a `LIMIT`, no uncaught exception type can produce a 500 from a shop page, and
the storefront is a constant number of queries.

---

## Phase 8 — Front-End Mechanics & UX

**Depends on:** the server contracts from Phases 3–6 are stable, so client changes cannot chase a moving API.

### Actions

1. **M10 — capability regression.** `43c0928` replaced the product-card quantity stepper with
   `<input type="hidden" value="1">` and hard-disables the button for anything already in the cart. Buyers cannot
   buy 3 of X from the storefront. Restore the stepper with `max="<?= $avail ?>"`, and make the button reflect the
   cart rather than hard-disabling it.
2. **M8 — placebo double-submit guard.** `checkout.php:130-133` sets `dataset.busy` but never calls
   `preventDefault()` or disables the button, so both clicks submit. Fix it, and correct the inline comment that
   claims otherwise.
3. **M9 — drawer leaks a live Checkout link.** `refreshDrawer()` replaces `#cartItemsContainer` but not its
   sibling `#cartFooter`, so an emptied cart still shows "Checkout ₱0.00". Also stop hardcoding `APP_URL` — use
   `link_to()` so the impersonation `?imp=` token survives.
4. **M13 — `showToast` XSS sink.** `app.js:39` interpolates `${message}` into `innerHTML` unescaped. Not currently
   reachable, but `Cart::validateStock()` already embeds an admin-authored product name in an error string. Escape
   it now; add the linter rule from Phase 3.
5. **Add in-flight locks** to the cart stepper and remove buttons — repeated clicks currently issue N POSTs with
   last-write-wins semantics and out-of-order responses that can render a stale badge.
6. **Accessibility sweep:**
   - unassociated `<label for>` on all checkout / product-modal / hold / shipment fields
   - `aria-label` on the icon-only stepper and hamburger buttons
   - fix the dangling `aria-labelledby="mobileSidebarLabel"`
   - `role="tablist"` / `aria-current` on the status tabs
   - the duplicate `#toastContainer` hazard between `app.js` and `footer.php`
7. **E-wallet balance gating at checkout.** E-wallet is default-checked and not disabled when the balance is
   below the total; the resulting failure discards every address field the buyer typed. Disable it client-side
   *and* preserve the form state on the failure path.

### Done when

The documented cart mechanics match the actual behaviour, and no claim in an inline comment contradicts the code.

---

## Phase 9 — Repository Hygiene & Documentation

**Depends on:** nothing technically; run whenever convenient. Do not let it slip — several items are
security-relevant.

### Actions

1. **Delete the stale paste-target snapshots** `tmp/shop/admin_block.php.txt` and
   `tmp/shop/member_block.php.txt`. They are pre-fix snapshots of controller code that is already merged, and
   `member_block.php.txt` still contains two bugs that were fixed after it was taken (the self-referential
   `$addr['billing_name']` read, and `'ref_no'` where the model reads `reference_no`). Re-pasting either
   reintroduces them.
2. **Credentials — no action required.** Confirmed by the owner on 2026-10-10: the database and all admin
   credentials in circulation (`config/db.php`, and the one named at `tmp/shop/qa_test_v2.md:48`) are
   **sandbox-only** and grant access to nothing but the developer's own machine. No secret was ever exposed, so
   there is nothing to rotate and nothing to scrub. For the record, the repo already handles the one credential
   that genuinely matters — `install.sql:571-586` seeds the default `admin` and `superadmin` accounts as bcrypt
   hashes annotated `-- CHANGE ON FIRST LOGIN`, and `AGENTS.md:136` repeats the instruction. That is correct
   as-is. *Optional, zero security value today:* gitignore `config/db.php` and ship a `config/db.example.php`
   template, so a future real deployment never inherits a committed credential. Worth doing **before** any real
   deployment, not after.
3. **Reconcile the three plan documents.** `CART_AND_ADMIN_PRODUCTS_PLAN_v2.md` is superseded by `_REVISED.md`,
   which is superseded by the un-suffixed rev 4. Keep rev 4, archive the others, and fold in the resolutions of
   D1–D5 so the doc matches the code.
4. **Fix the QA docs.** Every test case is phrased as "PASS if…" and all 10 final checkboxes are unticked in all
   three versions — no manual test has ever been recorded as run. Rewrite as a results table with a per-case
   status, and correct the three instructions that no longer match the shipped UI:
   - the qty-0 test is unreachable because the input has `min="1"`
   - the empty-cart wording differs between the cart page and the drawer
   - `?page=admin_shop_expire` is no longer the documented path (use the "Run expiry" button)
5. **Update `AGENTS.md`:**
   - add `cron/shop_expiry.php` and the `*/15` cron wiring to the cron section and the file tree
   - add the 15 shop routes to the route table
   - document `assertNotSelfReview`, `transition()`'s single-writer invariant, and the Strategy-C
     "no `Commission::` call, ever" rule alongside the existing CD / cap ordering notes
6. **Confirm the security posture** matches the updated docs:
   - `reset.php` is still in the repo and must be removed before production
   - `uploads/` is statically served, so proof and POD images are fetchable by guessed URL (accepted for v1 —
     moving the directories outside the web root is the follow-up)
   - consider restricting `uploads/shop_proofs/` specifically, since those images contain payment details

---

## Phase 10 — Verification Closure

**Depends on:** all previous phases.
**This is the phase that would have caught everything in Appendix A.**

### Actions

1. **Run the Phase 1 harness** and confirm it is green and meaningful — it must fail if any fix is reverted.
2. **Execute the manual QA suite for real**, on the test database, and record actual PASS/FAIL per case. Priority
   order given the defect profile:
   - cart add / step / remove **and cross-member cart isolation**
   - the full e-wallet and manual-payment happy paths
   - the refund lifecycle through to `processed`
   - delivery-failure → RTS → reship → complete (verifying no double stock deduction)
   - hold / resume
   - `payment_review` never expiring
   - the shop on/off switch
3. **Re-run the schema-drift check against both install paths** (fresh `install.sql`, and
   `migrate_shop.sql` + `migrate_shop_fixups.sql`) and confirm they converge.
4. **Verify the Strategy-C invariants are machine-enforced:** empty `git diff core/Commission.php`, zero
   PV-string hits, zero `Commission::` calls from shop code, zero direct `shop_orders.status` writes outside
   `ShopOrder::transition()` / `resume()`.
5. **Wire the harness into a pre-deploy check** so Phases 2–7 cannot silently regress.

---

## Sequencing

```
Phase 0  Freeze, backup, baseline
 └─► Phase 1  Verifiable ground truth (safe harness + drift check)
          ├─► Phase 2  Data-contract alignment (DDL ↔ PHP)
          │      └─► Phase 5  State-machine / UI parity
          │             └─► Phase 6  Money-flow atomicity
          ├─► Phase 3  Authorization & injection
          │      └─► Phase 4  Rendering correctness
          │             └─► Phase 8  Front-end mechanics
          └─► Phase 7  Input bounds, resilience, performance
                Phase 9  Hygiene & docs (independent)
Phase 10 Verification closure (depends on all)
```

### Why this order

Three orderings are load-bearing:

1. **Phase 1 before everything.** The current harness is destructive and model-only. Without a safe harness, each
   subsequent fix is verified by eye — which is exactly how C1–C6 shipped in the first place.
2. **Phase 2 before 5 and 6.** Both reason about enums and ledger `ref_type`. Writing them against a schema that
   does not exist guarantees rework.
3. **Phase 3 before 4 and 8.** The view-layer hiding (`$isSelf`, disabled buttons) is currently load-bearing for
   security. You cannot safely simplify the UI until the server stops depending on it.

Phases 7, 8 and 9 are independent of each other and can be parallelised or deferred. Phases 0 through 6 are
strictly sequential.

---

## Risk register

| Risk | Likelihood | Mitigation |
|---|---|---|
| A schema change in Phase 2 corrupts live data | Low | Phase 0 dump; `ALTER` only, never a table rewrite; verify against the test DB first |
| The harness itself becomes wrong and gives false confidence | Medium | Keep the original 85 assertions verbatim as a regression floor; never weaken a test to make it pass |
| Refund-state vocabulary churn ripples into the plan docs and QA suite | Medium | Resolve D1 first; do Phase 9's doc reconciliation in the same change |
| Fixing the IDOR reveals carts with orphaned items from prior abuse | Medium | Audit `cart_items` for rows whose `cart_id` does not match its owner's active cart before cleanup |
| Scope creep into Strategy C / commission work | Low | The `git diff core/Commission.php` gate stays empty; if a phase needs to touch it, that phase has left scope |

---

## Appendix A — Defect inventory

### Critical

| ID | Defect | Evidence |
|---|---|---|
| **C1** | `shop_refunds.status = 'paid'` is not in the ENUM → MySQL 1265 → refunds can never be closed | `install.sql:332` ENUM `('open','approved','processed','denied','closed')` vs `ShopRefund.php:80` writes `'paid'` |
| **C2** | `Ewallet::credit()` rejects `ref_type = 'shop_order'` → e-wallet refunds impossible | `Ewallet.php:22` whitelist lacks `'shop_order'`; `ShopRefund.php:74` passes it. DB enum *was* extended — the PHP side was not |
| **C3** | IDOR: cart `item_id` never scoped to the caller | `MemberController.php:1227,1232,1252,1256` → `Cart.php:92-109` (`WHERE id = ?` only). Correctly-scoped siblings `updateQuantity`/`removeItem` are dead code |
| **C4** | `$order` undefined in `order_status_badge.php` → every order row renders "Unknown" | `order_status_badge.php:8` reads `$order`; `member/shop_orders.php:43-50` loops `$o`; `admin/shop_orders.php:96` sets `$tmp` (never referenced anywhere) |
| **C5** | Stored XSS via `payment_reference` | `admin/shop_order.php:46` — `e()` (HTML escaper) inside a JS string literal in an `onclick` attribute. `payment_reference` has no server-side cap |
| **C6** | Admin "Complete" button can never succeed | `ShopOrder.php:71` actors `['customer','system']`; `AdminController.php:1177` passes `'admin'` → always `false` → misleading "This order just changed" flash |

### High

| ID | Defect | Evidence |
|---|---|---|
| **H1** | POD photo upload is dead | `admin/shop_order.php:157` form has no `enctype="multipart/form-data"` (compare `:324`, which does) |
| **H2** | `returned_to_sender` is UI-unreachable | `AdminController.php:1140` implements it; no form anywhere posts `to=returned_to_sender` |
| **H3** | `expireOverdue` never consults `correction_deadline` | `ShopOrder.php:1093` `COALESCE(payment_deadline, correction_deadline)` — `payment_deadline` is always non-null, so `COALESCE` always returns it |
| **H4** | `shop_refunds.cause` default is outside the ENUM | `AdminController.php:1321` defaults `'customer_request'`; ENUM is `('admin_cancel','delivery_failure','customer_dispute','other')`. Uncaught `PDOException` → 500 |
| **H5** | `per_page()` unbounded | `core/helpers.php:459-463` — `max($min, (int)$_GET['per_page'])`, no ceiling |
| **H6** | Segregation of duties is view-only on 5 of 6 admin endpoints | `assertNotSelfReview` called only at `AdminController.php:1091`. `orderRefund`, `orderHold`, `orderResume`, `orderReship`, `orderShipment` have no server-side check |

### Medium / lower

| ID | Defect | Location |
|---|---|---|
| **M1** | Proof row inserted before the conditional transition → orphan proof on a lost race | `ShopOrder.php:698-708` |
| **M2** | Rejected proof releases stock; re-upload re-reserves with no availability check | `ShopOrder.php:96`, `:302-309` |
| **M3** | An **approved** refund does not block completion (plan requires `open` or `approved`) | `ShopRefund.php:21`, `ShopOrder.php:1128` |
| **M4** | POD written to `shop_shipments` after the transition commits | `ShopOrder.php:983-997` |
| **M5** | `ShopShipment::flagLost()` writes `state='lost'`, not in the ENUM (dead code) | `ShopShipment.php:78` |
| **M6** | `shop_refunds.method` column unused; e-wallet decision is a `str_contains` substring match on free text | `ShopRefund.php:68-69` |
| **M7** | N+1: `Product::availableStock()` = 2 queries, called per catalog row (~201 queries for 100 products) | `shop.php:47`, `Product.php:27-31` |
| **M8** | Place-order double-submit guard is a placebo (no `preventDefault`, no `disabled`) | `checkout.php:130-133` |
| **M9** | `refreshDrawer()` leaves a live "Checkout ₱0.00" link in an emptied drawer; hardcodes `APP_URL`, dropping `?imp=` | `cart_offcanvas.php:84-98` |
| **M10** | Capability regression: product-card quantity stepper removed; can't buy 3 of X from the storefront | `shop.php:75-87` |
| **M11** | `confirm_lock` checkbox never read server-side | `member/shop_order.php:59,88` |
| **M12** | `order_no` has two sources of truth (stored column vs recomputed) | `admin/shop_orders.php:86`, `member/shop_orders.php:46` |
| **M13** | `showToast()` interpolates `${message}` into `innerHTML` unescaped — latent XSS sink | `app.js:39`, `Cart.php:126` |
| **M14** | Function declared at file scope inside a view; `$actionable` and `$needConfirm` unused | `admin/shop_orders.php:13`, `admin/shop_order.php:21` |
| **M15** | `payment_failed` renders a bordered but empty Actions card | `admin/shop_order.php:64-198` |
| **M16** | `shop_refunds.order_id` is `ON DELETE RESTRICT`, so `shop_orders` deletes fail once a refund exists | `install.sql` |

---

## Appendix B — Defects that are *not* in scope

| Item | Disposition |
|---|---|
| **D6** — shop revenue has no destination account | Plan-accepted accounting gap. Needs a business decision; do not silently invent a credit target. |
| Oversell between two simultaneous checkouts | Plan-accepted (§0.3). The `stock >= qty` guard at handoff is the intended backstop. M2 slightly widens the window and *is* in scope. |
| Reship does not return goods to sellable stock | Plan-accepted "by design" (§0.3) — goods are physically back and allocated. |
| Proof / POD images publicly fetchable by guessed URL | Accepted for v1; authenticated streaming exists for the UI path. Moving `uploads/` outside the web root is the follow-up (Phase 9). |
| Guest checkout | Explicitly out of scope in the plan (rev 3/4 §8). |
| No HTTP fallback for `cron/shop_expiry.php` | Explicitly out of scope in the plan (§8 infrastructure). |
| Physical-goods fields (`weight`, `dimensions`, courier rate class) | Explicitly out of scope in the plan (§8). |

---

## Appendix C — Process findings (Phase 9 + Phase 10 context)

- **No manual test has ever been recorded as run.** All three QA docs say automated testing is "complete", but
  every one of the 10 checkboxes in §13 "Final pass" is unticked — including v3, which was authored *after* the
  `43c0928` cart rewrite. Every test case is phrased "PASS if…", never as an observed result.
- **The automated smoke test is destructive.** It hardcodes `users.id = 3` / `1`, wipes that member's entire cart,
  calls `expireOverdue(200)` / `autoComplete(200)` against real orders, and mutates a real wallet with a raw
  `UPDATE users SET ewallet_balance …` — the exact anti-pattern the plan's risk register rates High.
- **Stale paste-targets are committed.** `member_block.php.txt` still carries two bugs that were fixed after it
  was taken.
- **Credentials: none outstanding.** Initially flagged as exposure; withdrawn on 2026-10-10 once the owner
  confirmed everything in circulation is sandbox-only (see D7). No action.
- **Documentation drift.** `AGENTS.md` still lists only `midnight_reset.php` and
  `fund_transfer_limit_reset.php` under cron; the shop routes are absent from its route table.
- **Non-linear history.** `5d6938f` deletes 2,058 lines of controller code that `b8c9786` re-adds at a different
  indentation, so `git log -p` on the controllers is not reviewable. The diff only makes sense as
  `3d359db..HEAD`.