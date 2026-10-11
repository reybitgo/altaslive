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
| App DB user | `u938213108_altas6_admin` — holds `SUPER … WITH GRANT OPTION` on `*.*` locally, so it can create/drop schemas and see all 45 local databases. Effectively root on this machine. **Not a finding** — sandbox, shared dev box. |
| PHP | 8.3.16 (CLI) — all 22 shop files pass `php -l` |

> **Correction (2026-10-10).** An earlier revision of this note claimed the app user *cannot* `CREATE`/`DROP
> DATABASE`, based on a `ERROR 1045` seen during Phase 0. That was **wrong** — the error was caused by
> PowerShell mangling the password in the my.cnf defaults file, not by a privilege limit. Re-tested in Phase 1:
> the app user creates and drops schemas without complaint. Phase 1 therefore provisions `altas6_test` **without**
> needing `root`, and the harness can run on the same credentials the app uses.

#### Dump provenance

The first dump attempt silently produced a **UTF-16LE** file (PowerShell's `>` operator re-encodes piped output),
which MySQL then refused to restore. Two further failures: the restore failed on NUL bytes emitted by
`--hex-blob` (needs `--binary-mode=1`), and every attempt to authenticate failed because PowerShell expanded
`$J` inside the here-string that built the my.cnf, silently corrupting the password. Generating the defaults file
from PHP fixed the auth problem. The working recipe:

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
| **H3** | ✅ **FIXED 2026-10-10.** `expireOverdue` used `COALESCE(payment_deadline, correction_deadline)`; since `payment_deadline` is always set, `correction_deadline` was **never read** and buyers lost most of their correction window. | **Fix:** status-aware `EXPIRY_DEADLINE_SQL`; `payment_failed` measured against `correction_deadline`. Verified on 5 synthetic cases. |
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

1. ~~**Provision a dedicated test database** (`altas6_test`).~~ ✅ Done. **Requires no superuser** — see the
   execution record; the Phase 0 privilege note was wrong. Also `GRANT`ed to the app user so the harness runs on
   the same credentials the app uses.
2. ✅ **Rebuilt as `tests/`:**
   - `bootstrap.php` — safety gate, config override, autoload, assertions, fixtures, isolation
   - `SchemaDriftTest.php` — PHP literals vs. live DDL
   - `ShopStateMachineTest.php` — 56 ported model regressions + 5 boundary suites
   - `run.php` — schema rebuild, subprocess execution, aggregation, teardown
3. **Isolation is by schema, not by transaction.** The original plan specified a transaction wrapper per test; that
   is impossible here because the code under test opens its own transactions and PDO has no nesting. `run.php`
   drops and rebuilds the schema from `install.sql` on every run, and `resetState()` truncates between cases.
4. **Schema-drift check** — done first, per recommendation. Reads `information_schema` and asserts PHP literals
   are members. Reports **9** failures, not the 4 predicted, and surfaced **C7** (see below).
5. ✅ Deleted `tmp/shop/admin_block.php.txt` and `tmp/shop/member_block.php.txt`.

### Verification

- `php tests/run.php` → deterministic; model layer green, boundary layer red on the exact defects.
- App schema byte-identical to the Phase 0 baseline after multiple runs.
- `ALTAS_TEST_DB=<app schema> php tests/SchemaDriftTest.php` → refuses, exit 2, writes nothing.

### Risk

The harness itself becoming wrong and giving false confidence. **Mitigation:** the 56 original model assertions are
ported verbatim as a regression floor; never weaken a test to make it pass.

### Done when

✅ Suite runs green against the pre-fix code (model layer) and the drift check reports the known mismatches —
plus three it discovered on its own. ✅ **Met
2026-10-10** — see the execution record below. Note it reports **nine**, not four: it found more than expected.

### Execution record — 2026-10-10

#### What was built

| File | Purpose |
|---|---|
| `tests/bootstrap.php` | Safety gate, config override, autoloading, `T` assertion collector, `Fixtures`, `resetState()`, `test()` |
| `tests/SchemaDriftTest.php` | 16 assertions comparing PHP literals against live DDL |
| `tests/ShopStateMachineTest.php` | 101 assertions: the 56 model regressions from the old smoke test, plus 5 boundary suites |
| `tests/run.php` | Rebuilds the schema from `install.sql`, runs each suite in a subprocess, aggregates, tears down |

**Current result — deterministic across consecutive runs:**

```
SchemaDriftTest.php        16 assertions,  9 failed
ShopStateMachineTest.php  101 assertions,  7 failed
                         ──────────────────────────────
                         117 assertions, 16 failed
```

#### A NEW CRITICAL DEFECT, OUTSIDE THE SHOP (C7)

Building the drift check first paid for itself immediately. It found a **live, fatal bug in the core MLM
upgrade path** that has nothing to do with the shop:

```
models/User.php:434   Ewallet::credit($adminId, $diff, $userId, 'upgrade', …)
models/Ewallet.php:22 $valid includes 'upgrade'
DB                    ewallet_ledger.ref_type = enum(…,'registration','shop_order')   ← no 'upgrade'
```

MySQL rejects it:

```
ERROR 1265 (01000): Data truncated for column 'ref_type' at row 1
```

**Consequence:** every e-wallet-funded package upgrade (`?page=do_upgrade`) fails. `debitInternal()` at
`User.php:421` succeeds first (it has no allow-list), then the admin credit at `:434` throws, and because the pair
sits inside a transaction the whole upgrade rolls back. Corroborating evidence: `users` has 149 rows with a
`package_id`, yet `SELECT ref_type, COUNT(*) FROM ewallet_ledger GROUP BY ref_type` returns **zero** `upgrade`
rows — consistent with no e-wallet upgrade ever having completed.

This is the same class as C2 (PHP literal drifted from the DDL) but in the opposite direction: C2 is a value the
column accepts and PHP rejects; **C7 is a value PHP accepts and the column rejects.** The drift check now asserts
the union of allow-lists *and* every call-site literal against the column, so both directions are covered.

**Phase 2 gains an item:** add `'upgrade'` to the `ewallet_ledger.ref_type` ENUM, or stop crediting with that
ref_type. This should be triaged ahead of the shop work — it blocks revenue, not just a storefront.

#### Boundary coverage — the split that proves the point

| Suite | Result | Meaning |
|---|---|---|
| Sections 1–10 model suite (ported verbatim) | **56 / 56 green** | the regression floor holds; the state machine is sound |
| Real commerce flow (createFromCart → pay → fulfil) | **green** | order creation, e-wallet payment, fulfilment, POD, confirm receipt |
| GCash proof lifecycle + S15/S16 | **green** | exact-amount and duplicate-reference guards work |
| C3 cart ownership | **2 failed** | IDOR reproduces |
| C1/C2 refund lifecycle | **3 failed** | refunds cannot close |
| H6 self-review | **1 failed** | `onHold` is unguarded |
| H3 correction window | **1 failed** | expired against the stale `payment_deadline` |

The model layer is green and every boundary suite is red. That is precisely the gap the old suite had: it was
model-only, which is how these defects shipped.

#### Isolation: why not a transaction per test

The plan originally specified a transaction wrapper per test. **That does not work**, and the harness uses schema
isolation instead:

- `ShopOrder::transition()`, `ShopOrder::createFromCart()`, `ShopRefund::markPaid()` and `Ewallet::debitInternal()`
  each call `$pdo->beginTransaction()` themselves.
- PDO has no nested transactions, so an outer wrapper makes every one of them throw
  `There is already an active transaction`.
- Savepoints do not help either — the inner `beginTransaction()` still fails.

The code under test manages its own transactions, so isolation is by **schema ownership**: the schema is dropped
and rebuilt from `install.sql` on each run, and `resetState()` truncates the shop/cart/ledger tables between
cases. The intent of the original requirement — non-destructive — is satisfied more completely than a rollback
would, because a crash mid-run leaves nothing behind at all.

#### Safety properties — verified, not asserted

| Check | Result |
|---|---|
| App schema after 3 harness runs | `users` 156 · `ewallet_ledger` 7,693 · `shop_orders` 0 · `carts` 2 · `products` 1 — **identical to the Phase 0 baseline** |
| `ALTAS_TEST_DB=u938213108_altas6_db php tests/SchemaDriftTest.php` | **REFUSING TO RUN**, exit 2, nothing written |
| Two consecutive `php tests/run.php` | identical results — deterministic |
| `tests/run.php` teardown | drops `altas6_test`; `--keep` retains it |

The gate is a hard `exit(2)` on a schema name that does not contain `_test`, evaluated before any connection is
used for work. There is no flag that disables it.

#### Environment corrections

- **The app DB user CAN create/drop schemas.** It holds `SUPER … WITH GRANT OPTION` on `*.*` locally. The
  Phase 0 note claiming otherwise was wrong — that `ERROR 1045` was PowerShell mangling the password in the my.cnf
  file, not a privilege limit. Provisioning needed no superuser after all.
- **`config/db.php` now honours pre-defined constants** (`defined('DB_NAME') || define(…)`). Without this the
  harness cannot point the app at a test schema without editing the file. Runtime behaviour is unchanged.
- **`install.sql` has 24 tables and converges exactly with the migrated schema** — all 15 shop ENUMs MATCH
  between `altas6_test` and the live app schema. Phase 2's "converge the two install paths" item is therefore
  largely already satisfied; what remains is removing the hardcoded schema name.

#### Files changed outside `tests/`

| File | Change | Why |
|---|---|---|
| `config/db.php` | `define()` → `defined() \|\| define()` for the five DB constants | Lets the harness retarget the app without editing this file. No behaviour change in normal operation. |
| `tmp/shop/admin_block.php.txt` | **deleted** | Stale paste-target; still contained two bugs fixed after it was taken |
| `tmp/shop/member_block.php.txt` | **deleted** | Same |

#### Done when

✅ Suite runs green against the pre-fix code (model layer) and the drift check reports the known mismatches —
plus three it discovered on its own.

---

## Phase 2 — Data-Contract Alignment (DDL ↔ PHP)

**Depends on:** Phase 1's drift check.
**Unlocks:** Phases 5 and 6 can be written against a schema that is actually the one in the database.

This phase exists because the refund workflow is currently 100% non-functional, and every subsequent phase would
otherwise be reasoning about a schema that does not exist.

### Actions

1. ✅ **C7 — `ewallet_ledger.ref_type` accepts `'upgrade'`** (folded in early, 2026-10-10, ahead of the shop work).
2. **C1 — refund status.** Apply D1: `ShopRefund::markPaid()` writes `status = 'processed'` instead of `'paid'`.
   Add a migration if the DB is already in the intermediate broken state. Update the badge ternary in
   `views/admin/shop_order.php:349`.
3. **C2 — e-wallet ref_type.** Apply D2. Introduce the shared const and use it in `Ewallet::credit()` and
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

Every refund transition is reachable, and the two install paths agree. ✅ **Met 2026-10-10** — see below.

### Execution record — 2026-10-10

| # | Defect | Change | Verified |
|---|---|---|---|
| 1 | **C1** refund status | `ShopRefund::markPaid()` now writes `status = 'processed'` (D1). Added `ShopRefund::STATUSES` const. Admin badge ternary at `views/admin/shop_order.php:349` updated to match. | Refund lifecycle suite went green end to end: open → approved → processed, wallet credited exactly once. |
| 2 | **C2** e-wallet ref_type | Resolved as a consequence of C1 + the C7 commit. No further change. | The refund path no longer reaches the ref_type guard. |
| 3 | **H4** refund cause | `ShopRefund::CAUSES` const + whitelist in `create()`, so an unknown cause throws `InvalidArgumentException` instead of reaching MySQL. `AdminController` default `'customer_request'` → `'admin_cancel'` (the old value was never in the ENUM). `orderRefund()` gained a `Throwable` backstop so no constraint violation can 500. | Drift test asserts the const and the controller fallback are both legal. |
| 4 | **M5** shipment state | Deleted the dead `ShopShipment::flagLost()` — no callers, and it wrote a `state` value the ENUM does not accept. | Drift test now derives state writes from source rather than hardcoding `'lost'`. |
| 5 | **M16** refund FK | **New** `migrate_shop_refunds_cascade.sql`: `shop_refunds.order_id` RESTRICT → CASCADE, matching the other four shop children. The constraint name is resolved from `information_schema` because MySQL auto-generates it, and the replacement is left unnamed so MySQL can pick a free one (it refuses to reuse a name inside the same ALTER). | Migration applied and re-run twice — idempotent. Deleted a real order that had an open refund: succeeded. All five shop children now CASCADE. |
| 6 | — `install.sql` schema name | Removed the hardcoded `CREATE DATABASE` / `USE`. The header now documents the caller-supplied-schema usage. | Drift test asserts no hardcoded `CREATE DATABASE`/`USE` remains. `tests/run.php` already relied on this. |

**Suite movement — 12 failures → 4 failures:**

| Suite | Before | After |
|---|---|---|
| `SchemaDriftTest.php` | 5 of 16 failed | **0 of 25 — GREEN** |
| `ShopStateMachineTest.php` | 7 of 106 failed | **4 of 106 failed** |

The four remaining failures are all later-phase scope: **C3** ×2 and **H6** → Phase 3; **H3** → Phase 5.

#### The drift test had to be rewritten, not just re-run

Three of its assertions hardcoded the *old broken values* (`'paid'`, `'customer_request'`, `'lost'`). Fixing the
code made those assertions report failures for values the code no longer uses — a test that only proves the old
bug still exists. They are now **derived from the source**: the test reads `ShopRefund::STATUSES`,
`ShopRefund::CAUSES`, and the state literals `ShopShipment` actually writes, then compares each against the live
DDL in both directions. That stays meaningful after the fix instead of expiring with it.

Assertion count also rose 16 → 25, from two new sections:

- **Cause fallback** — the controller's default cause must itself be a legal enum value.
- **Migration chain vs. install.sql** — parses `migrate_shop.sql` → `migrate_shop_fixups.sql` →
  `migrate_ewallet_ref_types.sql` and asserts every ENUM value a migration declares also exists in
  `install.sql`. A migration may lag `install.sql` (a later one fills the gap) but must never introduce a value
  a fresh install cannot produce — the C1 direction.

This section caught a real trap while being written: `migrate_shop.sql` sets `ref_type` *without* `'upgrade'`, so
a deployment that ran the shop migrations but not `migrate_ewallet_ref_types.sql` would have reintroduced C7. The
test now pins `'upgrade'` on both paths.

### Execution record — C7 folded in early (2026-10-10)

Taken ahead of the rest of Phase 2 because it blocks revenue rather than a storefront.

| Change | Detail |
|---|---|
| **New** `migrate_ewallet_ref_types.sql` | Adds `'upgrade'` to `ewallet_ledger.ref_type`, `information_schema`-guarded so it is a no-op on re-run. Documents the full failure mode in the file header. |
| `install.sql:394` | Column now `ENUM(…,'registration','upgrade','shop_order')`, with a comment pointing at `Ewallet::VALID_REF_TYPES`. |
| `models/Ewallet.php` | **D2 applied.** Two hand-maintained `$valid` arrays (which had drifted from the column in *opposite* directions) replaced by one `public const VALID_REF_TYPES` plus a private `assertRefType()` helper. `credit()` and `debit()` both route through it. |

**Verified:**

- Migration applied to the live dev schema; `enum(…,'registration','upgrade','shop_order')`. Re-run twice —
  idempotent, no error.
- The statement that used to fail now succeeds:
  `INSERT INTO ewallet_ledger (…, ref_type) VALUES (…, 'upgrade')` → **row inserted**.
- New harness suite `C7 — e-wallet ref_type ledger` reproduces the exact `User.php:434` call and asserts the row
  lands, the admin is credited, `debit()` now accepts `'upgrade'` too, and an unknown type is still refused.
- Drift-test ref_type failures: **4 → 0** (both directions now clean).

**Suite movement — 16 failures → 12 failures:**

| Suite | Before | After |
|---|---|---|
| `SchemaDriftTest.php` | 9 of 16 | **5 of 16** |
| `ShopStateMachineTest.php` | 7 of 101 | **7 of 106** (5 new C7 assertions, all passing) |

The remaining `SchemaDriftTest` failures are C1, H4, M5, M16 and the `install.sql` hardcoded schema name — all
still Phase 2 scope.

**Note on the C2 symptom changing.** The refund test now fails with
`1265 Data truncated for column 'status'` instead of `Invalid ref_type: shop_order`. That is the correct layering:
fixing C2 let the wallet credit succeed, so execution now reaches the *next* barrier, which is C1. Both are Phase 2;
neither is masked.

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

✅ All three findings provably closed at the server boundary, not just hidden in the UI. **Met 2026-10-10** —
see below.

### Execution record — 2026-10-10

#### C3 — cart IDOR (the live-exploitable one)

`cart_items.id` is a sequential `AUTO_INCREMENT` shared by every member, and `Auth::guard('member')` also admits
admins, so an id proves nothing about ownership.

| Change | Detail |
|---|---|
| `models/Cart.php` | `updateItemQuantity()` and `removeItemById()` now **require** `$cartId`; every statement carries `AND cart_id = ?`. Both return `rowCount() > 0`, so "not found" and "not yours" are now distinguishable from success. |
| `models/Cart.php` | Deleted `updateQuantity()` and `removeItem()` — already cart-scoped, but with **zero callers** anywhere in the live code. Three overlapping mutation paths were a trap for the next reader. |
| `controllers/MemberController.php` | New `requireOwnCart()` resolves `Cart::getActive(Auth::id())` once per request and redirects if there is no cart. Both verbs use it. |
| Harness | 7 assertions: attacker using the victim's `item_id` is refused, the victim's line is provably untouched, **and** the owner can still update and remove. Both directions, so the guard cannot "pass" by breaking the cart. |

#### C5 — stored XSS

`e()` is `htmlspecialchars(..., ENT_QUOTES)`. The browser HTML-decodes an attribute **before** the JS parser runs,
so `&#039;` becomes `'` and the literal breaks out. `payment_reference` is buyer-supplied, `varchar(40)`, with no
server-side character cap — so `');alert(document.cookie);//` stored via the admin verify-payment form executed in
the admin's browser.

| Change | Detail |
|---|---|
| `core/helpers.php` | New `js()` helper. Two layers are required: `JSON_HEX_APOS\|QUOT\|TAG\|AMP` escapes everything inside the value, **and** `htmlspecialchars` escapes the enclosing quotes `json_encode` adds — bare `json_encode` emits raw `"` and would terminate a double-quoted attribute. |
| `views/admin/shop_order.php:46` | `copyText('<?= e($x) ?>')` → `copyText(<?= js($x) ?>)`. Note the manual quotes go too: `js()` returns a complete literal. |

#### C5b — NEW: 20 more instances, several genuinely exploitable

The Phase 3 action said "sweep the whole surface". That sweep found **20 further `e()`-inside-`on*=` sites across
5 files**, not in the shop:

| File | Sites | Exploitable? |
|---|---|---|
| `views/admin/payouts.php` | 8 | **Yes.** `$account` is the user's GCash/USDT address — `varchar(100)`, user-set via profile, no format cap. |
| `views/admin/reactivations.php` | 2 | **Yes.** Same account column. |
| `views/admin/user_view.php` | 8 | No. `is_valid_username()` enforces `^[a-zA-Z][a-zA-Z0-9_]{2,39}$` on the registration path, so a username cannot contain a quote. |
| `views/admin/codes.php` | 1 | Unlikely — admin-generated codes. |
| `views/member/payout.php` | 1 | Method label from a fixed set. |

**Left unfixed on purpose.** These are not whole-argument positions — they embed a value *inside* a larger JS
expression (`payoutAction('approve', …, '<?= e($account) ?>', …)`), where `js()` would produce a string-inside-a-string.
The correct fix is to build the whole call as one `json_encode` object, or move the value to a `data-*` attribute
and read it from JS. That is a real refactor of five admin views, not a one-line substitution, so it is tracked
separately rather than smuggled into Phase 3.

An automated blanket `e(` → `js(` replacement was attempted and **reverted**: it broke every one of those sites by
double-quoting. `tests/TemplateLintTest.php` RULE-2 now guards against exactly that mistake recurring.

#### H6 — segregation of duties

`assertNotSelfReview()` existed only inside `AdminController::orderTransition()`. Five sibling endpoints —
`orderHold`, `orderResume`, `orderReship`, `orderShipment`, `orderRefund` — had the view's hidden `$isSelf` button
as their only protection.

| Change | Detail |
|---|---|
| `models/ShopOrder.php` | Guard added to `onHold()`, `resume()`, and `cancel()` (admin actor only — a customer cancelling their own order stays legal). |
| `models/ShopRefund.php` | New private `assertNotSelfReview(int $memberId)` + `buyerOf()`. Applied to `create()`, `approve()`, `markPaid()`, `deny()`. |
| `controllers/AdminController.php` | `orderShipment()` now resolves the order and calls the guard. `orderHold` / `orderResume` / `orderReship` catch the new `RuntimeException` and flash the reason instead of 500-ing. |
| Harness | 9 assertions. Self-admin is refused on every path; **a different admin can still hold, open, approve and settle** — so the guard is proven to be about self-review, not about blocking admins. |

The view's `$isSelf` hiding is kept, but it is now purely a UX affordance.

#### New suite: `tests/TemplateLintTest.php`

| Rule | Guards against |
|---|---|
| RULE-1 | `e()` inside an inline event handler (C5). Exemptions are an explicit allow-list with a reason each, and a **staleness check** fails if an exempted file no longer matches — so the list cannot silently rot. |
| RULE-2 | `js()` wrapped in manual quotes — the mistake the reverted blanket replacement made. |
| RULE-3 | Anything other than `ShopOrder.php` writing `shop_orders.status` — the single-writer invariant the whole state machine rests on. |
| RULE-4 | Strategy C: no shop model calls `Commission::`, and none references the inert PV columns. |

**Suite movement — 4 failures → 1:**

| Suite | Before | After |
|---|---|---|
| `SchemaDriftTest.php` | 0 of 25 failed | 0 of 25 — green |
| `TemplateLintTest.php` | *(new)* | **0 of 7 — green** |
| `ShopStateMachineTest.php` | 4 of 106 failed | **1 of 121 failed** |

The single remaining failure is **H3** (the expiry timer ignores `correction_deadline`), which is Phase 5 scope.

### Addendum — H3 closed 2026-10-10

Taken ahead of Phase 4 because it was the only remaining functional bug in the shop and the only red test.

**The bug.** `ShopOrder::expireOverdue()` selected candidates with:

```sql
AND COALESCE(payment_deadline, correction_deadline) < NOW()
```

`payment_deadline` is set at order creation and is **never cleared** — deliberately, because `payment_review` is
exempt from expiry (§0.5 T7) and the original stamp is part of the audit trail. So `COALESCE` always returned
`payment_deadline`, and `correction_deadline` was **never read at all**.

A buyer whose proof was rejected near the payment deadline lost most of the 24 h correction window the code had
just opened for them: they were cancelled at the original deadline rather than the correction one.

**The fix.** A status-aware deadline expression, extracted to a named constant so the `WHERE` and the `ORDER BY`
cannot drift apart:

```php
private const EXPIRY_DEADLINE_SQL =
    "COALESCE(CASE WHEN status = 'payment_failed' THEN correction_deadline END, payment_deadline)";
```

`pending` keeps using `payment_deadline`; `payment_failed` uses `correction_deadline`, falling back to
`payment_deadline` if it is somehow null. An `IS NOT NULL` guard was added so an order with no deadline at all is
never picked up — previously a null deadline could never satisfy `< NOW()` either, but stating it removes the
reliance on that.

**Verified on synthetic rows against the live schema** (inserted, checked, rolled back — 0 rows left):

| Order | Status | `payment_deadline` | `correction_deadline` | Should expire | Result |
|---|---|---|---|---|---|
| A | `payment_failed` | past | future | no | ✅ **this was the bug** |
| B | `payment_failed` | past | past | yes | ✅ still expires |
| C | `pending` | past | — | yes | ✅ unchanged |
| D | `pending` | future | — | no | ✅ unchanged |
| E | `pending` | null | — | no | ✅ `NULL`, excluded by the guard |

**Harness extended to both directions.** The original assertion only proved the timer does not *over*-cancel, which
a fix that simply disabled the timer would also satisfy. Three cases added: a lapsed correction window **must**
cancel; a pending order still expires on `payment_deadline`; an order with no deadline is never expired.

**Suite result: all green for the first time.**

| Suite | Assertions | Failures |
|---|---|---|
| `SchemaDriftTest.php` | 25 | 0 |
| `ShopStateMachineTest.php` | 126 | 0 |
| `TemplateLintTest.php` | 8 | 0 |
| **Total** | **159** | **0** |

Taken as a separate pass after Phase 3 committed, because it is the live-exploitable item.

#### The attribute was only half the bug

Fixing `onclick="…'<?= e($account) ?>'…"` alone would **not** have closed this. Both files funnel their values into a
DOM sink:

```js
document.getElementById('payoutModalDesc').innerHTML = cfg.desc;
// cfg.desc = `… Send funds via <strong>${method}</strong> to <code>${account}</code> …`
```

A member sets their own GCash number to `<img src=x onerror=…>`; an admin opens the payouts page and clicks
Approve, and the payload executes **in the admin's session**. Server-side escaping protects the *attribute*; it
does not survive JavaScript decoding into `innerHTML`. Two distinct layers had to be fixed.

#### Layers applied

| Layer | Where | Helper |
|---|---|---|
| HTML attribute | `payouts.php`, `reactivations.php`, `codes.php`, `user_view.php`, `member/payout.php` | `js()` / `jsargs()` / `jsstr()` |
| DOM sink (`innerHTML`) | the inline `<script>` blocks, plus `showConfirm()` in `app.js` | new `escHtml()` in `app.js` |

New helpers in `core/helpers.php`, each with one clear contract:

- **`js($v)`** — a complete JS literal, attribute-safe. For a whole argument.
- **`jsargs([...])`** — several values as a JS argument list. Composes `js()`, so the rules stay in one place.
- **`jsstr($v)`** — escaped *content* for embedding inside an already-quoted JS string, keeping markup live. Only
  for templates that build a larger string around the value.
- **`escHtml($v)`** (JS) — escape at the `innerHTML` boundary. Static markup around it stays markup.

#### Markup-carrying variables had to be restructured

`codes.php` held ready-made HTML in a variable:

```js
const typeLabel = codeType === 'cd' ? '<span class="text-warning">CD</span>' : …;
`… <strong>${typeLabel}</strong> …`
```

That cannot be escaped at the sink without destroying it — which is precisely how these sinks go wrong. Split into
a label (text) and a class name, with the markup moved into the template:

```js
const typeLabel = codeType === 'cd' ? 'CD' : …;
const typeClass = codeType === 'cd' ? 'text-warning' : …;
`… <strong class="${escHtml(typeClass)}">${escHtml(typeLabel)}</strong> …`
```

The colour is preserved and the rule is satisfiable honestly.

#### Also swept

RULE-5 flagged two further sinks found while fixing the above: `genealogy.php` echoing a caught `e.message`
(which can contain a URL built from user input) into `innerHTML`, and `showConfirm()` interpolating
`confirmClass` / `confirmText` / `id` unescaped. Both are defence-in-depth — no current caller passes
attacker-controlled data — and both are now escaped.

#### Allow-list emptied

`KNOWN_C5B` is now `[]`. The array and its staleness check are retained deliberately: a future exemption has to be
a justified act, and a file that no longer matches fails the suite rather than sitting there silently.

**Verification:** 0 remaining `e()` inside an event handler · 0 `jsstr()` outside a handler · 16 `escHtml()` call
sites · RULE-1 and RULE-5 both green.

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

✅ A full sweep of all shop pages is warning-free. **Met 2026-10-10** — see below.

### Execution record — 2026-10-10

#### New suite: `tests/ViewRenderTest.php` (64 assertions)

The durable fix the plan asked for. Defect C4 shipped because the order lists read a variable their own loop never
defined, and nothing caught it: the suite never rendered a view, and the lint rules are static. This one **renders the
real views** with an error handler that turns every warning, notice and deprecation into a recorded failure.

Four of my own assertions were wrong on first run, and each was a genuine correction rather than a loosening:

| Assertion | First run said | Reality |
|---|---|---|
| "partial never reads `$order`" | failed | My docblock *names* `$order` while explaining why it is no longer used. Comments are now stripped before the check. |
| "no view declares a function at file scope" | 17 files | Almost all were **JavaScript** inside `<script>` tags. `<script>` bodies are now stripped first. |
| "rendered without PHP diagnostics" | 4× `session_start()` warnings | A harness artefact: under CLI, PHP refuses to start a session once the reporter has echoed a line. `bootstrap.php` now opens it before any output, so the assertion stays meaningful. |
| "shows the stored AL order number" | failed | The fixture generated `order_no = 'T' . hex()`, not a real `AL…` number. `Fixtures::pendingOrder()` now mints a realistic one, exactly as `createFromCart()` does. |

#### C4 — the badge partial

`order_status_badge.php` read `$order` directly, but both list views loop their rows as `$o`. Every row hit the
`default` arm of `shop_status_label()` — the word **"Unknown"** in a grey badge — with two PHP warnings per row, on
*both* the member and admin order lists.

The fix is at the root rather than at the call sites: the partial now takes **explicit arguments**.

```php
$badgeStatus = (string) $o['status'];
$badgeStamp  = $o['created_at'] ?? null;
require __DIR__ . '/../partials/order_status_badge.php';
```

A partial that reads a variable it does not receive is invisible to every static check, so the dependency is now
stated. Asserted three ways: the partial's code never references `$order`, it documents `$badgeStatus`, and every
caller sets it before including. All 14 statuses are rendered and each must show its real label and never "Unknown".

#### M12 — one source of truth for `order_no`

Both lists recomputed the number from `id + created_at` while the controller read the stored column — two sources
that could diverge. Both now print `$o['order_no']`. The rendered output is asserted to contain a real `AL…`
number.

#### M14 — dead code in views

- `shop_next_action()` moved from `views/admin/shop_orders.php` to `core/helpers.php` next to the other shop
  helpers. A function declared at file scope in a view means a second include anywhere is a fatal
  `Cannot redeclare`. The test asserts it lives in helpers and returns `string|null` for all 14 statuses.
- The `$actionable` `COUNT(*)` in `AdminController::shopOrders()` is gone. It ran on every page load for a badge the
  view never rendered.
- The `'completed'`-as-sentinel hack in the tab labels is replaced with a direct `status === 'all' ? 'All' : …`.

#### M14 (extended) — three more real instances

The lint rule found the same pattern in layout partials the shop pages include:

- `views/partials/sidebar_admin.php` — `renderAdminNav()`
- `views/partials/sidebar_member.php` — `memberNavActive()`, `renderSidebarNav()`

These render large HTML blocks, so moving them to `helpers.php` would just relocate the problem. They are guarded
instead:

```php
if (!function_exists('renderAdminNav')) {
function renderAdminNav(...) { ?>
```

The body is only emitted when the function is **called**, so the rendered markup is unchanged — a second include
becomes a no-op instead of a fatal. The lint rule accepts a `function_exists`-guarded declaration and still fails on
any other.

#### M15 — the empty Actions card

Rather than act on the earlier analysis, the view is now **rendered** for `payment_failed` and asserted to contain
real actions. Both matrix-legal targets are covered: `cancelled` renders inline, `on_hold` in the right rail, and the
explicit "No admin actions available from …" branch exists for statuses with nothing to do. The earlier claim that
this produced an empty bordered card is superseded — the render test is the authority.

**Suite result — all four green:**

| Suite | Assertions | Failures |
|---|---|---|
| `SchemaDriftTest.php` | 25 | 0 |
| `ShopStateMachineTest.php` | 126 | 0 |
| `TemplateLintTest.php` | 8 | 0 |
| `ViewRenderTest.php` | 64 | 0 |
| **Total** | **223** | **0** |

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
3. ✅ **H3 — correction window ignored.** ~~`COALESCE(payment_deadline, correction_deadline)`~~ **Closed
   2026-10-10, ahead of Phase 4** — see the Phase 3 addendum. Status-aware `EXPIRY_DEADLINE_SQL`; harness asserts
   both directions plus the null-deadline case.
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

✅ The whole lifecycle runs green from order placement to completion, including the failure and reship branches.
**Met 2026-10-10** — see below. H3 closed early in the Phase 3 addendum; the other six here.

### Execution record — 2026-10-10

| Defect | Change |
|---|---|
| **C6** | `to=completed` form removed from the action bar and the dead `case 'completed'` branch removed from `AdminController::orderTransition()` (decision D3 — the matrix stays the single source of truth). |
| **H1** | `enctype="multipart/form-data"` added to the `to=delivered` form, so `$_FILES['pod_image']` is no longer always empty and a POD **photo** can actually satisfy the guard. |
| **H2** | New `to=returned_to_sender` form on `shipped` / `out_for_delivery` / `delivery_failed`. That status was reachable only by a hand-crafted POST, which also made the Reship button dead. |
| **M1** | `ShopOrder::submitProof()` writes the proof row from inside `transition()`'s transaction, via a guard. |
| **M2** | Re-reserving a rejected proof's released lines now re-checks per-line availability and throws `stock_conflict` if any unit has since been sold. |
| **M3** | `ShopRefund::BLOCKING_STATUSES = ['open','approved']`, used by both `hasOpen()` and `ShopOrder::autoComplete()`'s `NOT EXISTS`. |

#### The matrix assertion the plan asked for

> "add a harness assertion that **every** button the view can render is a matrix-legal `(status, actor, to)` triple"

Implemented as a render test: for each of the 14 statuses, render the admin desk, extract every `name="to"` value
from the action bar, and check it against `ShopOrder::TRANSITIONS` for `(status, 'admin')`.

Coverage measured, not assumed — **23 buttons across 11 statuses**:

| Status | Buttons rendered |
|---|---|
| `pending` | cancelled |
| `payment_review` | paid, payment_failed |
| `payment_failed` | cancelled |
| `paid` | packing, payment_review, cancelled |
| `packing` | ready_to_ship, cancelled |
| `ready_to_ship` | shipped, cancelled |
| `shipped` | out_for_delivery, delivery_failed, delivered, **returned_to_sender** |
| `out_for_delivery` | delivery_failed, delivered, **returned_to_sender** |
| `delivery_failed` | out_for_delivery, delivered, **returned_to_sender** |
| `returned_to_sender` | cancelled *(plus the reship form, which posts to `admin_order_reship`)* |
| `on_hold` | cancelled |
| `delivered`, `completed`, `cancelled` | **none** — correct |

`delivered` rendering **zero** buttons is the C6 fix proven: it previously rendered the Complete button, which the
matrix refused every time.

**A negative check was attempted and was inconclusive**, so it is recorded rather than claimed: re-introducing the
`to=completed` form mid-file broke the render, so the test found 0 buttons rather than 1 illegal one. The coverage
table above is the actual evidence.

#### M1 — what the fix prevents

The proof `INSERT` used to run **before** the status change, outside any transaction. Against the expiry timer that
left a real window: if the timer won the race between the two, `transition()` returned `false`, the order was
cancelled, and it kept an orphan `shop_payment_proofs` row claiming a payment that no longer mattered. The plan
asked for "exactly one winner"; moving the insert into the transaction is what makes that true. A refused upload is
now asserted to leave **zero** proof rows.

#### M2 — why this was an oversell

Rejecting a proof **releases** the reservation, so for the length of the correction window those units were sellable
to everyone else. The old code re-reserved them unconditionally. Because the order exists by then, no later
availability read can catch it. The guard re-checks per line, excluding this order's own reservation, and refuses
with `stock_conflict`. Asserted both ways: refused when the goods are gone, **and accepted again once stock is
restored** — so the guard is demonstrably about stock rather than refusing re-uploads outright.

#### M3 — one list, two callers

An approved-but-unsettled refund is money promised to the buyer and not yet returned; completing the order underneath
it hides that. Both `canMemberAct()` and `autoComplete()` previously checked only `status='open'`, so either the
buyer or the timer could close an order with a refund in `approved`. One constant now drives both. Asserted across the
whole arc: open blocks → approved **still** blocks → settled releases the block.

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
records the state change. **Met 2026-10-10** — see below.

### Execution record — 2026-10-10

> **This phase had been skipped.** It carried no execution record and no commit, even though Phase 7 lists it as a
> prerequisite and the sequencing diagram calls Phases 0–6 "strictly sequential". Found while planning Phase 8, not
> by a test.

#### 6.1 — `placeOrder()` debited money outside the unit that recorded the order

The live defect, confirmed by reading the code rather than the plan:

```php
Ewallet::debitInternal(...);                    // money debited, ledger row written
$pdo->commit();                                 // COMMITTED
ShopOrder::markPaid($orderId, 'system', null);  // outside the transaction
Cart::clear(...);                               // never reached on failure
```

Any failure inside `markPaid()` — a stock conflict at reservation, a database error — left the member debited against
a `pending` order with their cart intact. The `catch` block could not undo it: **rolling back an already-committed
transaction is a no-op**, so the buyer was shown "Could not place the order" while their balance was already reduced.

`transition()` is now transaction-aware (`$ownsTxn`, the same pattern `createFromCart()` already used) so the paid
transition joins the caller's unit instead of opening its own, and `markPaid()` returning `false` now raises rather
than being ignored. Order, debit, paid transition and cart clear are one commit.

Two consequences of making `transition()` transaction-aware, both handled:

- **A refusal must not roll back the caller's transaction.** `markDelivered`/`markPaid` guard blocks now read
  `if ($ownsTxn) $pdo->rollBack();`. Unconditional, a refused transition would have discarded the caller's
  uncommitted work.
- **The stock-conflict audit row is only written when we own the transaction.** It is deliberately written *after* the
  rollback so it survives; under a caller's transaction the refusal is the caller's to report.

#### 6.2 — M4: proof of delivery was written after the status committed

`markDelivered()` called `transition()` — which committed — and *then* called `ShopShipment::savePod()`. A POD write
that failed left an order sitting in `delivered` with no proof of delivery and nothing to retry from: the status was
durable, the evidence was not. POD is the compliance artifact for the entire fulfilment leg, so it now moves through
the transition's `applySideEffects()` hook, inside the same transaction. `markDelivered()` hands the POD over and
does nothing after the call.

#### 6.3 — refund payout ordering: confirmed sound, not rewritten

The plan asked this be verified rather than assumed, and it is: `ShopRefund::markPaid()` already wraps both the
credit and the status write in one transaction, and `Ewallet::credit()` opens no transaction of its own, so the
credit cannot escape the unit. Both facts are now asserted rather than assumed.

#### 6.5 — M6: the payout route was decided by free text

`str_contains(strtolower($destination), 'wallet')` decided who got credited, on an unconstrained `VARCHAR(120)`.
`shop_refunds.method ENUM('ewallet','cash','bank','manual')` existed and was **never read**. Now the enum decides;
only an explicit `ewallet` method credits the wallet.

The mutation run showed the heuristic was fragile in *both* directions, not just the one the plan named: with it
restored, a cash payout whose destination text read "Cash payout to GCash wallet later" credited the wallet, **and**
an `ewallet` refund whose destination read "back to GCash" credited nothing.

#### 6.6 — ledger reconciliation

Every order's `ewallet_ledger` movement is reconciled against its state: exactly one debit equal to the order total
for an e-wallet checkout, none for any other method, and credits fully explained by *processed* `method='ewallet'`
refunds.

**The first version of this test was worthless and passed by asserting nothing.** It swept all orders "at the end of
the suite" — but `test()` calls `resetState()` before *and* after every case, so it found an empty `shop_orders`
table and reported `orders=0`. A reconciliation check that never looks at anything always agrees. It now builds its
own four money paths inside the test.

#### 6.7 — Strategy C, machine-enforced

Zero `Commission::` calls and zero PV / point-value strings in shop code, and no direct `shop_orders.status` UPDATE
outside `ShopOrder.php`. `git diff core/Commission.php` is empty.

Scoped deliberately: an earlier version scanned every model and controller and flagged `AdminController`, which
legitimately drives the commission admin pages. Strategy C forbids the *shop* from borrowing the binary engine, not
the reverse.

#### An idempotency test that was passing for the wrong reason

Mutation-testing the refund guard produced a surprise: **disabling the `rowCount` guard entirely left the suite
green.**

The sequential replay assertions were being caught by the early `status !== 'approved'` check, which runs before the
transaction opens. The guard that actually matters is the concurrent one — two admins opening the same approved
refund in the same instant — which is defended by `UPDATE ... WHERE id = ? AND status = 'approved'` plus the
rowCount check, so the loser's UPDATE matches zero rows and its transaction, including the credit already issued
inside it, rolls back.

A single-process test **cannot** reproduce that race: with one connection the loser always re-reads `processed` and
short-circuits at the early check. Rather than leave a test whose name overstates what it proves, the sequential
assertions are kept for what they genuinely cover and the race guard is asserted structurally — with the limitation
written into the test itself, so the next reader is not misled.

| Suite | Assertions | Failures |
|---|---|---|
| `SchemaDriftTest.php` | 25 | 0 |
| `ShopStateMachineTest.php` | 258 | 0 |
| `TemplateLintTest.php` | 12 | 0 |
| `ViewRenderTest.php` | 73 | 0 |
| **Total** | **368** | **0** |

Every fix this phase was mutation-verified — each was re-introduced and the suite watched to fail. Three of those
runs found defects in my own tests, not in the code: the scoped-commit blind spot in the 6.1 ordering assertion, the
empty-table reconciliation sweep, and the idempotency test passing for the wrong reason.

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

✅ No unbounded query parameter reaches a `LIMIT`, no uncaught exception type can produce a 500 from a shop page,
and the storefront is a constant number of queries. **Met 2026-10-10** — see below.

### Execution record — 2026-10-10

#### H5 — `per_page()` allow-list (decision D5)

`max($min, (int)$_GET['per_page'])` fed a user-supplied value straight into a SQL `LIMIT` with no ceiling, so
`?per_page=99999999` produced an unbounded `SELECT` on any list page. Replaced with an allow-list
`[10, 25, 50, 100]`; anything unlisted degrades to the default rather than to an arbitrary number.

Nine cases asserted, including the ones a bare ceiling would miss: integer overflow, negative, non-numeric, and an
in-list value that is not a round number. Asserted separately that `per_page()` can never exceed the ceiling.

> The version before that was `(int)(['per_page'] ?? $default)` — casting a literal *array* to `1`, so it always
> returned the floor of 5 and the page-size control never worked at all. Three generations of this one function,
> each broken differently.

#### `Product::delete()` could 500

It checked only `shop_order_items`. A product sitting in anyone's **cart** hit the `cart_items` foreign key and
raised an uncaught `PDOException` on the admin products page. Now both referencing tables are checked, the controller
has a `Throwable` backstop, and the flash explains the cart case instead of claiming only orders.

A cart line is deliberately **not** auto-removed: that is a side effect the caller did not ask for, so the line blocks
the delete and the admin removes it first. Asserted all four cases — unreferenced deletes, cart-referenced refused
without an exception, order-referenced refused, and an unreferenced product still deleting normally afterwards.

#### The stock-conflict catch was swallowing database errors

`transition()` detected a stock conflict by string-comparing `$e->getMessage() === 'stock_conflict'` inside a
`catch (RuntimeException)`. **`PDOException extends RuntimeException`**, so every database error — a dead
connection, a constraint violation — was diverted into that branch, misreported as a stock problem, and the handler
then read `$order['status']`, which is undefined if the throw happened before the row was fetched.

Now a dedicated `core/StockConflictException.php`, with a docblock explaining exactly why the string comparison
existed and why it was wrong. The catch is `catch (StockConflictException $e)`; everything else propagates. Asserted
that the class hierarchy the old catch relied on is no longer in play, and that no `getMessage()` comparison remains.

#### M7 — storefront N+1

`views/member/shop.php` called `Product::availableStock()` inside the catalog loop: a `find()` plus a three-table
join per card, so a 100-product catalog issued ~201 queries on page load. Added `Product::availableStockMap()` — two
queries for the entire catalog regardless of size — and the view now computes availability once before the loop.

Correctness is asserted rather than assumed: the batched map is compared against the per-product calculation for a
reserved product, a plain product and a sold-out one, plus the empty-list and unknown-id edge cases.

#### Server-side reference caps

`payment_reference` and `reference_no` are `VARCHAR(40)` with only a client-side `maxlength` — which is not a
constraint. New `clean_reference()` normalises to `[A-Z0-9_-]` and truncates to the column width, called from
inside the model so every caller is covered. A reference with no usable characters now raises
`InvalidArgumentException` instead of silently becoming `NULL`.

Asserted end to end: a reference of `"  gc-123_ab'; DROP TABLE users;--  "` is stored confined to the safe
character set, no statement separator, no whitespace, within 40 characters.

#### M11 — `confirm_lock` removed

The checkbox was never read server-side — a POST without it was accepted exactly like one with it. The cancel/proof
race is decided by the conditional write inside `transition()`, so the checkbox was decoration implying a protection
that did not exist. **Removed rather than enforced**: adding server enforcement for a promise the state machine
already keeps would imply the protection depends on the box. The explanatory text is retained as a plain note in
both upload forms. Zero `confirm_lock` references remain.

**Suite result:**

| Suite | Assertions | Failures |
|---|---|---|
| `SchemaDriftTest.php` | 25 | 0 |
| `ShopStateMachineTest.php` | 189 | 0 |
| `TemplateLintTest.php` | 12 | 0 |
| `ViewRenderTest.php` | 73 | 0 |
| **Total** | **299** | **0** |

#### A loading bug the suite could not see

`index.php` autoloads **only** `models/` and `controllers/`; everything in `core/` is loaded by explicit
`require_once` lines. The new `StockConflictException` was therefore **not** loaded in production — the whole suite
passed green while `transition()` would have fataled with "Class not found" on the first handoff request that hit a
stock conflict.

The test suite cannot catch this, because `tests/bootstrap.php` registers an autoloader that sweeps `core/` as well,
so the class always resolves under test and never in production. Found by reading `index.php` before committing, not
by a failing test.

Added a lint invariant that sweeps the whole `core/` directory rather than just the current file, so the next class
added is covered automatically. It also asserts the autoloader is **not** widened to cover `core/` — that shortcut
would mask the next missing require instead of surfacing it.

Mutation-verified: deleting the `require_once` line makes the suite fail with
`missing: StockConflictException`. A rule that has never failed is not a rule.

Two of my own assertions were wrong on first run and are recorded rather than quietly fixed:

- The `per_page` table was built as associative arrays and destructured **positionally**, so `$in` received the
  literal key `'per_page'` and the assertion compared the wrong things. The function was correct throughout.
- The "no bare `catch (RuntimeException)`" assertion matched my own **explanatory comment**, which mentions the old
  catch by name. Scoped to a real catch clause.

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
**Met 2026-10-10** — see below.

### Execution record — 2026-10-10

#### M10 — the storefront could not sell more than one unit

`43c0928` replaced the product-card stepper with `<input type="hidden" value="1">` and hard-disabled the button for
anything already in the cart, so a buyer could add exactly one of each product and could not top the cart up from the
storefront at all. Restored as a real stepper with `max="<?= $avail ?>"`, and the button now reads **Add more** when
the product is already in the cart rather than being dead.

The client control is a convenience and never the authority: `Cart::addItem()` rejects `quantity < 1` and re-checks
the **cumulative** quantity against availability server-side.

#### M8 — the double-submit guard was placebo

```js
// a double click must not fire two POSTs
if (this.dataset.busy) return;
this.dataset.busy = '1';
```

Nothing called `preventDefault()` and nothing disabled the button, so both clicks submitted. The comment asserted a
guarantee the code did not provide.

Now hooked on the form's `submit` event rather than the button's `click` — Enter in a text field submits without ever
touching the button — with the button disabled and a busy label. **Only the button is disabled, never the form:** a
disabled control's name/value pair is omitted from the POST, which would have stripped every address field the buyer
had just typed. A `pageshow` handler clears the flag on a BFCache restore, which would otherwise strand a dead button.

#### M9 — the drawer leaked a live Checkout link at ₱0.00

`refreshDrawer()` replaced `#cartItemsContainer` but not its sibling `#cartFooter`, so an emptied cart still offered a
working Checkout button. The actions are now hidden and a note explains why — done by visibility rather than by
replacing `innerHTML`, so the totals nodes `updateFooter()` writes to survive.

The same function hardcoded `'<?= APP_URL ?>'`, which silently drops the `?imp=` impersonation token: a superadmin
browsing as a member would lose the impersonated session on navigation. Now `link_to('shop')`.

#### M13 — `showToast()` was an XSS sink, and the linter that should have caught it was not

`${message}` was interpolated into `insertAdjacentHTML` unescaped. Callers pass text carrying database content — a
product name out of `Cart::validateStock()` — so this was reachable the moment one of those paths reported an error,
rather than merely latent. `${type}` was also unescaped inside a `class` attribute; it is now drawn from an
allow-list *and* escaped.

Fixing it exposed something worse. **The RULE-5 linter had been passing for the wrong reasons:**

- It cleared any interpolation whose ±400-character neighbourhood happened to contain an unrelated `escHtml()` call, or
  the word `textContent`. That is how `showConfirm()`'s genuinely unescaped `${message}` and `${title}` survived.
- Its proximity test was a fixed ±6 line window. Once `showToast()` grew explanatory comments, the message line sat
  **8 lines** above its `insertAdjacentHTML` call, dropped out of the window, and reverting the escaping passed the
  suite.

A rule that depends on how far a line sits from a keyword is not a rule. The escape check is now local to the
interpolation's own line, and JS files are checked in full rather than through a window.

Rewriting it this way initially made the rule a **complete no-op** — reverting `showToast()`'s escaping passed. The
cause was my first attempt at the exemption: a `file + identifier` exemption, which read as equivalent to what it
replaced but silently covered `showToast`'s `message` too, since it has one too. The exemption is now scoped to
`showConfirm`'s function body. Both directions are mutation-verified: reverting the toast escaping fails, and
unescaping the exempted `title` passes.

`showConfirm()`'s `message` is left alone deliberately — it is a **trusted-HTML contract**, not an oversight. Callers
embed developer markup (`suspend <strong>@alice</strong>`) and escaping it wholesale would print those tags as
literal text. Every untrusted fragment must be escaped by the caller; the inline `onclick=` callers use `jsstr()`,
which escapes for the JS literal *and* runs `htmlspecialchars()`, and usernames are separately validated. The contract
is now documented in the function's docblock and the exemption is explicit and greppable rather than an accident of
distance.

#### In-flight locks on the cart controls

Repeated clicks fired N POSTs against one row with last-write-wins on the server and no ordering guarantee on the way
back, so responses could land out of order and render a quantity that disagreed with the badge. Locks are keyed **per
cart-item row**, so editing one line never blocks another, and both branches now have a `.catch()` — previously a
network failure left the input showing a value the server never stored.

#### Accessibility sweep

- Nine checkout fields had `<label>` with no `for`, so the inputs had **no accessible name at all** — a screen reader
  announced "edit text, blank" for the entire delivery block, address included.
- The quantity steppers are literally `-` and `+`; they now name the product they step.
- `aria-labelledby="mobileSidebarLabel"` pointed at an id that existed nowhere, so the offcanvas announced itself as
  unlabelled. An unresolvable ARIA reference is worse than none — it is a promise the page does not keep.
- The admin status filters are links that navigate by URL, so they get `aria-current="page"` and explicitly **not**
  `role="tablist"`, which would promise tabpanels that do not exist and break arrow-key navigation.
- `#toastContainer` is defined in `footer.php` and created by `app.js` only if absent — the plan listed this as a
  duplicate-id hazard, but the existing `getElementById` guard already handles it correctly. No change needed.

#### E-wallet gating and form-state preservation

The e-wallet option was checked by default regardless of balance. A member with an empty wallet could select it, fill
in every address field, press Place order, and have the whole form discarded by the failure redirect. The option is
now disabled at render time when the balance is short and no longer pre-selected in that case; the server still
re-checks inside the transaction.

All four failure paths in `placeOrder()` — including the two early validation redirects — now stash the form first, and
`shop_checkout_old_input()` restores it for exactly one render through a **whitelist**, so this cannot become a
general-purpose "echo the POST back" hole. Profile-prefilled fields prefer what the buyer actually typed.

#### Two traps this phase walked into, both recorded because they recur

- A `//` comment containing a literal PHP close tag ends PHP mode. Everything after it silently becomes inline HTML
  and the parser reports an unclosed brace hundreds of lines away — twice, the second time in the very comment
  warning about the first.
- Two assertions matched their own explanatory comments: one naming the attribute it forbade (`role="tablist"`), one
  naming the interpolation it forbade (`${message}`). Both are now stripped or reworded, because a lint rule that
  reads its own documentation will eventually forbid the documentation.

| Suite | Assertions | Failures |
|---|---|---|
| `SchemaDriftTest.php` | 25 | 0 |
| `ShopStateMachineTest.php` | 258 | 0 |
| `TemplateLintTest.php` | 28 | 0 |
| `ViewRenderTest.php` | 73 | 0 |
| **Total** | **384** | **0** |

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

### Execution record — 2026-10-10

`tmp/shop/qa_test_v2.md` is a checklist for a human with two browser windows, and Phase 9 records that its ten
final checkboxes had never been ticked in any of its three versions — the cases had been written but never run by
anyone. This phase runs them.

**`tests/QaSuiteTest.php`** drives each case end to end through the models against the disposable test schema. The
distinction from `ShopStateMachineTest` is the point: that suite tests one transition rule at a time, where a defect
that only appears across a whole lifecycle — stock vanishing on a reship, a refund crediting twice — is invisible. This
one walks each order from creation to its terminal state and asserts the stock and the wallet at every step.

It is **not** a substitute for a human looking at the screen and is not recorded as one. What it replaces is the prior
state, where nothing had been run at all.

| Case | Scenario | Checks | Result |
|---|---|---|---|
| QA-1 | cart add / step / remove + cross-member cart isolation | 16 | **PASS** |
| QA-2 | e-wallet happy path: cart → paid, one debit, stock reserved | 12 | **PASS** |
| QA-3 | manual payment: proof upload, review, paid, mismatch refused | 10 | **PASS** |
| QA-4 | refund lifecycle → processed, wallet credited exactly once | 10 | **PASS** |
| QA-5 | delivery-failure → RTS → reship → complete, no double deduction | 15 | **PASS** |
| QA-6 | hold / resume | 11 | **PASS** |
| QA-7 | `payment_review` never expires | 7 | **PASS** |
| QA-8 | shop on/off switch | 5 | **PASS** |
| QA-9 | an admin cannot review their own order | 5 | **PASS** |
| QA-10 | cross-member access to proofs and orders is refused | 5 | **PASS** |

#### QA-5 is the case the defect profile was built around

The double-stock-deduction guard is the `deducted_at IS NOT NULL` check inside `applySideEffects()`'s `shipped`
branch — **not** the `reservation_state` filter on the line SELECT. Widening that filter to admit `'deducted'` lines
changes nothing, because a reship legitimately resets the line to `reserved` first: at handoff the line is *always*
`reserved`, and `deducted_at` is the only thing that remembers these specific goods already left the stock figure.

That was found by mutation, not by reading. Replacing the guard with `if (false)` makes QA-5 fail with stock `3 → 1`:

```
FAIL QA-5: the second handoff did NOT deduct again — this is the double-deduction guard  (expected '3', got '1')
FAIL QA-5: stock is still 3 after the entire failure/reship lifecycle               (expected '3', got '1')
```

#### Five cases failed on first run. Four were my bugs; one was a wrong assumption about the design.

- `Cart::removeItem()` does not exist — it is `removeItemById($itemId, $cartId)`, cart-scoped by design.
- `paginate()` returns `['data' => …]`, so the order rows are one level down.
- The settings primary key is `key_name`, not `key`.
- `markDeliveryFailed()` validates `fault` against `customer|carrier|shop`; I passed `courier`.
- **QA-8 was a genuine bug in my own assertion, and a nasty one.** I read the switch with
  `fetchColumn() ?: '1'`. The string `'0'` is falsy in PHP, so the elvis operator returned `'1'` — reporting the shop
  as **open in exactly the state the case exists to detect**. `setting()` carries the same warning in its own body for
  the related `fetchColumn() === false` case.

Two cases were also passing for the wrong reason and had to be rewritten:

- **QA-9 passed without testing anything.** `assertNotSelfReview()` keys off the **session** actor
  (`Auth::isAdmin()` and `Auth::id()`), not off the `$adminId` argument. My first version passed the id but never
  logged in, so the guard was skipped entirely and the case went green. It now logs in as the admin, asserts the
  refusal, and then proves the refusal was about *identity* by showing a different admin may approve the same order.
- **QA-6 asserted the opposite of the design.** A hold is a pause, not a lock: `transition()` resolves the effective
  source from `previous_status`, so a member legitimately may cancel an order that is merely on hold. Separately,
  `resume()` only accepts a target that is both a matrix target and admin-reachable, and `pending` is neither — it is an
  initial state with no transition *into* it, so holding from `pending` cannot round-trip by design.

#### Pre-deploy gate (action #5)

`tests/predeploy.php` runs the suite and re-checks the two invariants that are cheap to satisfy and easy to break by
accident: `core/Commission.php` must not have drifted from the `shop-pre-remediation` baseline, and no live credential
may be committed outside `config/db.php`. Exit 0 to deploy, non-zero to stop.

Mutation-verified: with the double-deduction guard disabled it exits **2**; restored, it exits **0**.

#### Remaining in Phase 10

Action #3 (schema drift across both install paths) is already covered by `SchemaDriftTest.php`'s migration-chain
comparison, and #4 by the Strategy-C assertions added in Phase 6.7. Both re-run green.

#### The committed cron key — accepted, not fixed

`midnight_reset.php` assigns a weak hardcoded default to its `$cronKey` variable, guarding the HTTP
midnight-reset endpoint. The literal is deliberately NOT reproduced here: quoting it would put a second copy of
the secret in a second tracked file, and the pre-deploy scan would then flag this document as the leak rather
than the source. Read the value from the file if you need it. The adjacent comment already says CHANGE THIS, so this is a known weakness rather than a forgotten
secret. **Accepted 2026-10-11** rather than fixed: rotating it changes deployment behaviour, and `.htaccess`
already restricts that file to localhost.

The acceptance is deliberately *not* a suppression. `ACCEPTED_ISSUES` in `tests/predeploy.php` keeps the gate
green while **printing the finding on every single run**, under a `KNOWN ISSUES` heading that ends with "the gate
is green, but nothing here is hidden." A silent skip would be the same bug the file already had once — a check
that reports success while proving nothing.

Three properties, each mutation-verified:

| Behaviour | Verified by | Result |
|---|---|---|
| A **new** unaccepted credential still blocks | injecting a second DB password variable | exit **2**, `PRE-DEPLOY BLOCKED` |
| An accepted entry that no longer matches is **stale** | pointing an entry at `README.md` | printed as `STALE ... remove this entry`, exit 0 |
| An entry cannot be added bare | `TemplateLintTest` requires `why`, `accepted`, `review_by` | suite fails without them |

While adding the denylist for the value that leaked from `qa_test_v2.md`, the scan flagged **its own denylist**:
the needle written out whole also appeared in the test asserting the denylist worked. The literal is now assembled
at runtime (`'Richman' . '26'`) so it exists contiguously in no tracked file — which is the state actually wanted,
and the gate that caught it is the reason to trust it now.
**Phase 9 is untouched** — repository hygiene and documentation, including rewriting the three QA documents whose
checkboxes have never been ticked.

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
| The harness itself becomes wrong and gives false confidence | Medium | Keep the 56 ported model assertions verbatim as a regression floor; never weaken a test to make it pass. |
| Refund-state vocabulary churn ripples into the plan docs and QA suite | Medium | Resolve D1 first; do Phase 9's doc reconciliation in the same change |
| Fixing the IDOR reveals carts with orphaned items from prior abuse | Medium | Audit `cart_items` for rows whose `cart_id` does not match its owner's active cart before cleanup |
| Scope creep into Strategy C / commission work | Low | The `git diff core/Commission.php` gate stays empty; if a phase needs to touch it, that phase has left scope |

---

## Appendix A — Defect inventory

### Critical

| ID | Defect | Evidence |
|---|---|---|
| **C1** | ✅ **FIXED 2026-10-10.** `ShopRefund::markPaid()` wrote `status='paid'`, outside the ENUM → `ERROR 1265` → refunds could never be closed. | Was: `install.sql` ENUM `('open','approved','processed','denied','closed')` vs `ShopRefund.php:80` writing `'paid'`. **Fix (D1):** writes `'processed'`; `ShopRefund::STATUSES` const added; admin badge updated. |
| **C2** | ✅ **FIXED 2026-10-10** (with C7). `Ewallet::credit()` rejected `ref_type='shop_order'` → e-wallet refunds were impossible. | `Ewallet.php:22` whitelist lacks `'shop_order'`; `ShopRefund.php:74` passes it. DB enum *was* extended — the PHP side was not |
| **C3** | ✅ **FIXED 2026-10-10.** IDOR: cart `item_id` was never scoped to the caller, so any authenticated session could rewrite or delete any member's cart line by walking the shared AUTO_INCREMENT. | **Fix:** both mutators now require `$cartId` and carry `AND cart_id = ?`; controller resolves the caller's cart via `requireOwnCart()`; two dead unscoped helpers deleted. | `MemberController.php:1227,1232,1252,1256` → `Cart.php:92-109` (`WHERE id = ?` only). Correctly-scoped siblings `updateQuantity`/`removeItem` are dead code |
| **C4** | ✅ **FIXED 2026-10-10.** `$order` was undefined in `order_status_badge.php` because both list views loop rows as `$o`, so every order row rendered "Unknown" with two warnings per row. | **Fix:** the partial now takes explicit `$badgeStatus` / `$badgeStamp`; both callers set them. All 14 statuses asserted to render their real label. | `order_status_badge.php:8` reads `$order`; `member/shop_orders.php:43-50` loops `$o`; `admin/shop_orders.php:96` sets `$tmp` (never referenced anywhere) |
| **C5** | ✅ **FIXED 2026-10-10.** Stored XSS via `payment_reference`: `e()` HTML-decodes back to a quote before the JS parser runs. | **Fix:** new `js()` helper (JSON_HEX_* + htmlspecialchars); `admin/shop_order.php:46` now `copyText(<?= js($x) ?>)`. |
| **C5b** | ✅ **FIXED 2026-10-10.** 20 further `e()`-inside-`on*=` sites in `payouts.php`, `reactivations.php`, `user_view.php`, `codes.php`, `payout.php`. `payouts.php` and `reactivations.php` carry the user-set GCash/USDT account and **were exploitable**. | See the Phase 3 addendum below — the attribute was only half the problem; there was also an `innerHTML` sink. All 20 fixed; `tests/TemplateLintTest.php` KNOWN_C5B is now **empty**. | `admin/shop_order.php:46` — `e()` (HTML escaper) inside a JS string literal in an `onclick` attribute. `payment_reference` has no server-side cap |
| **C6** | ✅ **FIXED 2026-10-10.** The admin "Complete" button could never succeed: `TRANSITIONS['completed'].actors` is `[customer,system]` and the controller passed `'admin'`. | **Fix (D3):** the button and its controller branch are removed; the matrix stays the single source of truth. A render test now asserts every rendered button is matrix-legal — 23 buttons across 11 statuses. | `ShopOrder.php:71` actors `['customer','system']`; `AdminController.php:1177` passes `'admin'` → always `false` → misleading "This order just changed" flash |
| **C7** | ✅ **FIXED 2026-10-10.** `Ewallet::credit()` accepted `ref_type='upgrade'`, which the ledger ENUM rejected → **every e-wallet package upgrade failed** with `ERROR 1265`, rolling back the whole upgrade. | `models/User.php:434` credited with `'upgrade'`; the ENUM omitted it. **Fix:** `migrate_ewallet_ref_types.sql` adds the value; `install.sql` updated; `Ewallet::VALID_REF_TYPES` (D2) is now the single allow-list behind both `credit()` and `debit()` |

### High

| ID | Defect | Evidence |
|---|---|---|
| **H1** | ✅ **FIXED 2026-10-10.** The POD photo upload was dead: the `to=delivered` form had no `enctype`, so `$_FILES` was always empty and only a typed receiver name could satisfy the guard. | `enctype="multipart/form-data"` added and asserted. | `admin/shop_order.php:157` form has no `enctype="multipart/form-data"` (compare `:324`, which does) |
| **H2** | ✅ **FIXED 2026-10-10.** `returned_to_sender` was implemented and matrix-legal but had **no form**, making the status and the Reship button unreachable. | New `to=returned_to_sender` form on `shipped` / `out_for_delivery` / `delivery_failed`, asserted to render. | `AdminController.php:1140` implements it; no form anywhere posts `to=returned_to_sender` |
| **H3** | ✅ **FIXED 2026-10-10.** `expireOverdue` never consulted `correction_deadline`: the query used `COALESCE(payment_deadline, correction_deadline)` and `payment_deadline` is always set, so the correction window was dead code. | **Fix:** status-aware `EXPIRY_DEADLINE_SQL`. Verified on 5 synthetic cases covering both directions and the null-deadline edge. |
| **H4** | ✅ **FIXED 2026-10-10.** The `shop_refunds.cause` default was outside the ENUM, so a forged or omitted value produced an uncaught `PDOException` (500). | `AdminController.php:1321` defaulted `'customer_request'`; ENUM is `('admin_cancel','delivery_failure','customer_dispute','other')`. Uncaught `PDOException` → 500 |
| **H5** | `per_page()` unbounded | `core/helpers.php:459-463` — `max($min, (int)$_GET['per_page'])`, no ceiling |
| **H6** | ✅ **FIXED 2026-10-10.** Segregation of duties was view-only on 5 of 6 admin endpoints. | **Fix:** guard moved into the model — `ShopOrder::onHold/resume/cancel` and all four `ShopRefund` entry points; `orderShipment` guarded in the controller. A different admin is still permitted, asserted. | `assertNotSelfReview` called only at `AdminController.php:1091`. `orderRefund`, `orderHold`, `orderResume`, `orderReship`, `orderShipment` have no server-side check |

### Medium / lower

| ID | Defect | Location |
|---|---|---|
| **M1** | ✅ **FIXED 2026-10-10.** The proof row was INSERTed outside the transaction, so a lost race against the expiry timer left a cancelled order carrying an orphan proof. | The insert now runs from a guard inside `transition()`; a refused upload is asserted to write zero proof rows. | `ShopOrder.php:698-708` |
| **M2** | ✅ **FIXED 2026-10-10.** A rejected proof released the reservation, and re-upload re-reserved it with no availability check — an oversell no later read could catch. | Re-checks per line and refuses with `stock_conflict`; asserted refused when stock is gone and accepted once restored. | `ShopOrder.php:96`, `:302-309` |
| **M3** | ✅ **FIXED 2026-10-10.** An **approved** refund did not block completion: both checks tested only `status='open'`, so the buyer or the timer could close an order with money still owed. | `ShopRefund::BLOCKING_STATUSES = ['open','approved']` drives both `hasOpen()` and `autoComplete()`. | `ShopRefund.php:21`, `ShopOrder.php:1128` |
| **M4** | POD written to `shop_shipments` after the transition commits | `ShopOrder.php:983-997` |
| **M5** | ✅ **FIXED 2026-10-10.** `ShopShipment::flagLost()` wrote `state='lost'`, not in the ENUM. Dead code with no callers. | **Fix:** deleted. Drift test now derives state writes from source. |
| **M6** | `shop_refunds.method` column unused; e-wallet decision is a `str_contains` substring match on free text | `ShopRefund.php:68-69` |
| **M7** | N+1: `Product::availableStock()` = 2 queries, called per catalog row (~201 queries for 100 products) | `shop.php:47`, `Product.php:27-31` |
| **M8** | Place-order double-submit guard is a placebo (no `preventDefault`, no `disabled`) | `checkout.php:130-133` |
| **M9** | `refreshDrawer()` leaves a live "Checkout ₱0.00" link in an emptied drawer; hardcodes `APP_URL`, dropping `?imp=` | `cart_offcanvas.php:84-98` |
| **M10** | Capability regression: product-card quantity stepper removed; can't buy 3 of X from the storefront | `shop.php:75-87` |
| **M11** | `confirm_lock` checkbox never read server-side | `member/shop_order.php:59,88` |
| **M12** | ✅ **FIXED 2026-10-10.** `order_no` had two sources of truth: the stored column and a recomputation from `id + created_at`. | Both lists now print `$o['order_no']`; the rendered output is asserted to contain a real `AL…` number. | `admin/shop_orders.php:86`, `member/shop_orders.php:46` |
| **M13** | `showToast()` interpolates `${message}` into `innerHTML` unescaped — latent XSS sink | `app.js:39`, `Cart.php:126` |
| **M14** | ✅ **FIXED 2026-10-10.** A function was declared at file scope inside a view, and `$actionable` / `$needConfirm` were unused. | **Fix:** `shop_next_action()` moved to `core/helpers.php`; the unused `COUNT(*)` removed from the controller; three more instances in the sidebar partials guarded with `function_exists`. | `admin/shop_orders.php:13`, `admin/shop_order.php:21` |
| **M15** | `payment_failed` renders a bordered but empty Actions card | `admin/shop_order.php:64-198` |
| **M16** | ✅ **FIXED 2026-10-10.** `shop_refunds.order_id` was `ON DELETE RESTRICT` while all four sibling shop children cascaded, so orders could never be deleted once a refund existed. | **Fix:** `migrate_shop_refunds_cascade.sql` → CASCADE; `install.sql` updated. |

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