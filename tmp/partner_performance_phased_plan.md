# AltasLive — Partner Performance Plan
## A Phased, Self-Contained Implementation Plan for a Sustainable Rival to "Loyalty Reward"

| | |
|---|---|
| **Document** | Phased implementation plan (handoff-grade) |
| **Codebase** | `altaslive (2).zip` — custom PHP 8.1+ MLM binary app, MySQL 8, no framework |
| **Business source** | `altasfarm_sustainable_partner_model_plan.pdf` — *"Altas Farm: A More Sustainable Poultry Partner Model"* (8 pages, October 2026, planning proposal) |
| **Companion document** | `phased_implementation_plan.md` (previous plan — guest storefront / guest checkout / customer zones) |
| **Status** | Planning proposal for business, accounting and legal review. Pool caps, package values, and reserve targets are **proposed pilot settings**, not validated figures. |
| **Date basis** | October 2026 (Asia/Manila). First pilot quarter proposed: **2026Q4 (Oct 1 – Dec 31, 2026)**. |

---

### Scope of this plan

**In scope — build a brand-new compensation plan that rivals `Loyalty Reward` (DFI) in a more sustainable way:**

1. A quarterly **Partner Performance Bonus pool** computed from verified contribution margin on completed, non-refunded shop orders, capped by distributable cash (15%) and aggregate margin (20%), allocated pro-rata by verified contribution.
2. The full supporting machinery: cost snapshots, a contribution ledger, period lifecycle (open → closed → approved → paid), monthly provisional reporting, admin close/approval workflow with segregation of duties, member-facing dashboard with the four PDF-mandated states, payout through the existing e-wallet, and audit snapshots.
3. Repositioning the package catalogue as **order values** (8 tiers, Basic ₱5,000 → Platinum ₱1,000,000) with a free-partner registration path, and the frontend marketing switch that presents the new plan as the alternative to Loyalty Reward.
4. A pilot launch path (PDF roadmap Phase 2: Basic / Starter / Pro order sizes), a four-quarter validation calendar, and a decision gate for sunsetting DFI on legacy packages.

**Out of scope (deliberately excluded):**

- **The guest storefront / guest checkout / free-user customer zone** from the companion plan. The user direction for this cycle is *"let's leave out the shop for free users, for now."* Nothing in this plan builds guest access; free partners use normal member accounts. All P0–P7 guest-shop items remain parked in `phased_implementation_plan.md` for a later cycle.
- No changes to the binary pairing, direct-referral, indirect-referral, or CD (Commission-Deduct) engines. They keep running exactly as today on the packages that have them.
- No changes to the DFI engine's code path. DFI sunset (if and when it happens) is an operational toggle plus a communication exercise, gated behind the four-quarter pilot review (Phase P7).
- No revival of PV. `products.product_pv` / `pv_value` / `shop_orders.total_pv` / `shop_order_items.unit_pv` remain **inert** per the standing §0.2 decision in the shop plans. The new plan is margin-based, not PV-based.
- No service-reward categories (contracted delivery, sales support). The PDF allows them "only after a written rate schedule, proof requirements, and attribution rules are approved" — deferred until after the pilot (Appendix G, item G-4).

### How to read this plan

- **Every phase is self-contained.** Each phase restates its objective, entry state, decisions applied, work items, schema/settings changes, tests, acceptance criteria, and rollback. A engineer handed only Phase P*n* plus §2 (verified snapshot) can execute it without reading the rest.
- **Effort sizing:** **S** ≤ 1 day, **M** 2–5 days, **L** > 5 days, per work item and per phase. No calendar dates except the pilot-quarter anchor and the PDF's service-level targets (monthly 15th, close +30d, approval +45d, payment +60d).
- **Every code claim was verified against the actual zip contents.** References are `file:line`. Where the plan creates something new it is marked *(new)*.
- **Decision register (§4)** holds the numbered decisions `D1…D18`. Phases cite them; the register holds the full rationale, rejected alternatives, and reversibility notes.
- **Appendices A–G** carry the route inventory, complete DDL, settings keys, test matrix, risk register, operational calendar, and open items.

---

## 1. Executive summary

Today the platform's flagship member benefit — marketed as **"Loyalty Reward"** — is the **Daily Fixed Income (DFI)** engine: a package field `daily_fixed_income` paid every night per member for `daily_fixed_income_days` nights, credited by the midnight cron. It is popular because it is simple and feels certain. It is also the platform's least sustainable liability: the obligation accrues at a **fixed rate per member per night regardless of whether the farm sold anything, collected anything, or earned anything**. The only brakes are the per-member lifetime cap (`entry_fee × lifetime_cap_multiplier`, CapEngine) and a blunt seat-limit cutoff that already stops DFI entirely when registration is full (cron/midnight_reset.php:180-182). Every new DFI-enabled member adds a known fixed future payout the company must fund from something other than the member's own orders.

The attached business plan (*Altas Farm: A More Sustainable Poultry Partner Model*) proposes the replacement: **sell real poultry products and services at transparent prices; reward partners for verified economic contribution; pay only when the approved financial and cash tests permit it; never guarantee income or compensate recruitment alone.** Concretely, a quarterly **Partner Performance Bonus**:

- **Eligibility** follows completed, collected, non-refunded transactions with traceable attribution — not registration, not package purchase, not account count.
- **Contribution margin (CM)** per partner = net collected sales − direct product costs − variable fulfillment costs, with refunds and reversals subtracting back out.
- **Distributable cash** `D_q = max(0, min(A_q, C_q))` — the lesser of accounting earnings available (`A_q`) and unrestricted cash after obligations and reserves (`C_q`).
- **Pool** `P_q = min(15% × D_q, 20% × M_q)` where `M_q` is the quarter's aggregate net eligible contribution margin. If either input is zero, the pool is zero.
- **Allocation** `B_i = P_q × max(0, CM_i) / W_q` — pro-rata on positive partner-level margin only. A participant with zero eligible contribution receives nothing.
- **Cadence**: monthly provisional reports, quarterly settlement and approval, annual reconciliation. Member dashboard states: **Provisional → Under review → Approved → Paid**.

This plan implements that model in the AltasLive codebase in **eight phases (P0–P7)**, roughly two to three weeks of focused engineering plus a pilot quarter of live validation:

| Phase | Name | Effort |
|---|---|---|
| P0 | Foundations: alignment, data audits & legal prerequisites | S |
| P1 | Data layer: cost snapshots, performance tables, ENUM extensions | M |
| P2 | Eligibility & contribution-margin engine + monthly provisional reporting | M |
| P3 | Quarterly close, pool calculation & approval workflow | M |
| P4 | Payout leg & member experience | M |
| P5 | Catalogue repositioning & the "rival" switch | M |
| P6 | Monitoring, abuse controls & hardening | M |
| P7 | Pilot launch, four-quarter validation & DFI sunset path | S |

The build is designed so that **nothing user-visible or money-moving ships until P4**, phases P1–P3 are additive and inert until feature flags are flipped, and every phase has a rollback that restores the previous behavior with a single `migrate_*` reversal plus file revert. The existing DFI engine is never modified — the new plan rivals it in the catalogue and in marketing, and wins or loses on the four-quarter pilot evidence, not on code deletion.

---

## 2. Verified snapshot — what the codebase actually does today

Everything in this section was read directly from `altaslive (2).zip`. Line numbers refer to the files as shipped in the zip.

### 2.1 "Loyalty Reward" is the DFI engine

The marketing name and the engine are different labels for the same numbers:

- `frontend/index.php` renders the packages comparison table with a row labeled **"Loyalty reward"** showing `fmt_money($f['dfi_amount']) . '/day for ' . number_format($f['dfi_days']) . ' days'` from package fields `daily_fixed_income` / `daily_fixed_income_days`, and the FAQ copy: *"Selected entries include a loyalty reward as the farm's way of giving back to members who choose to support its continued growth."* The compliance section describes it as *"the farm's way of giving back… not presented as a fixed-income product"* — language chosen for legal distance from a guaranteed return, while the mechanics are literally a fixed daily amount.
- `frontend/script.js` renders the same row in the package detail modal as `"📅 Loyalty Reward"` with `fmtMoney(pkg.dfi_amount) + "/day for " + pkg.dfi_days + " days"`, next to `"🔒 Lifetime Income Cap"` (`fmtMoney(pkg.cap_amount)`).
- The engine is `core/DailyFixedIncome.php`. `processDailyPayout()` (core/DailyFixedIncome.php:26) selects every member satisfying **all** of: `role='member'`, `status='active'`, `cap_status='active'`, `dfi_active=1`, `cd_active=0`, `dfi_days_used < daily_fixed_income_days`, `daily_fixed_income > 0`, `dfi_enabled=1` (core/DailyFixedIncome.php:42-62). For each, inside its own transaction (core/DailyFixedIncome.php:84,133) it: inserts a `commissions` row `type='daily_fixed_income'` (:87-97), credits the e-wallet via `Ewallet::credit(..., 'commission', ...)` (:100-106), records against the lifetime cap `CapEngine::recordEarning` (:109), increments `users.dfi_days_used` (:112-116), and appends `daily_fixed_income_log` with a `UNIQUE (user_id, day_number)` guard against double-pay (:122-131; install.sql:586).
- The nightly trigger is `cron/midnight_reset.php` (crontab comment at :10). It resets `pairs_paid_today`/`pairs_volume_today` (:135), expires capped members past the reactivation window (:158-172), and runs DFI (:174-194). **When the seat limit is reached, DFI is skipped entirely** (:180-182) with the logged rationale *"no new joins are possible, so the company stops accruing new fixed-income liability"* — the codebase itself already treats DFI as a liability to be braked, which is the clearest possible evidence of the sustainability problem this plan solves.

### 2.2 The money machinery the new plan must integrate with

**Commission flow ordering (critical, AGENTS.md:49-56).** Every existing commission — pairing, direct, indirect, DFI — moves money in a fixed order: (1) **CD split** `CdStatus::fillBucket()` diverts part of the gross into the member's Commission-Deduct bucket; (2) **lifetime cap check** `CapEngine::canEarn()` trims the wallet portion (cap = `entry_fee × lifetime_cap_multiplier`, CapEngine.php:103; VIP `capping_bypass` skips it, :42-48; deactivated users earn nothing, :33-39); (3) **wallet credit** of only the allowed portion, with the blocked remainder recorded as `cap_deduction` / flushed rows in `commissions`. The new performance pool deliberately sits **outside** this ordering (see D8, §4).

**Commission records.** `commissions.type` is `ENUM('pairing','direct_referral','indirect_referral','daily_fixed_income')` (install.sql:475). `Commission::summary()` (:548-564) returns per-type totals including `total_dfi`; `Commission::history()` whitelists the same four types for its type filter (:586). Adding a fifth type means: ENUM migration + `summary()`/`history()` updates + drift-test coverage.

**E-wallet.** `Ewallet::VALID_REF_TYPES` (models/Ewallet.php:28-31) is the **single source of truth** for `ewallet_ledger.ref_type` and is asserted **bidirectionally** against the live ENUM by `tests/SchemaDriftTest.php` (:134-166), plus a call-site scan that greps every `Ewallet::credit/debit` literal (:168-197). The file's own header documents two historical defects (C2, C7) caused by hand-maintained lists drifting — this is why P1 extends the const, the DDL and the tests **in the same phase**. `Ewallet::credit()` takes a `$withdrawable` flag (:46-73); commissions currently credit withdrawable. `Payout::request()` enforces `min_payout` (default ₱500) and one pending request per member (models/Payout.php:11-32); admin completes payouts which debit the wallet (:100-136).

**CapEngine.** `canEarn`/`recordEarning`/`applyCap` (core/CapEngine.php:27-118, 203-213); `applyCap` also sets `dfi_active=0`, freezing the member's DFI day counter. Capped members expire to `perminact` after the package's reactivation window (:223-239). The performance pool does **not** pass through CapEngine (D8).

### 2.3 The shop: where eligible transactions come from

- **Order lifecycle**: 14 statuses (models/ShopOrder.php:19-23); `completed` and `cancelled` are terminal (:25). `ShopOrder::transition()` is the single writer of `shop_orders.status`, each move inside a transaction with an append-only `shop_order_events` row (file header, :7-12). Payment verification → `paid` issues an **immutable invoice snapshot** via `ShopInvoice::issue` inside the same transaction (applySideEffects, :450-456). Stock deducts at `shipped` (:397-427). `delivered` opens a report window (`completion_due_at`, :437-442); the timer cron auto-completes when the window closes **unless a refund is open or approved** (`autoComplete`, :1313-1340, blocking NOT EXISTS at :1320-1323).
- **Strategy C (models/ShopOrder.php:14-15):** *"nothing in this model may call into core/Commission.php or any other engine class. A shop order is pure retail — no PV, no bonuses, no CD."* The new plan respects this **literally**: the margin engine never hooks into ShopOrder. Instead, P1 adds a passive `unit_cost` snapshot column to `shop_order_items`, and the performance engine **derives** contributions at close time by querying completed orders — no engine call ever executes inside an order transition (D5, §4).
- **createFromCart** (models/ShopOrder.php:687-816): idempotency fast path on `idempotency_key` (:700-706), transaction-aware adoption of a caller's open transaction (:711-714), authoritative pricing re-read from the catalog (:721-722), and per-line **price snapshots** into `shop_order_items` (product_name, sku, unit_price; :770-785). P1 extends this INSERT with `unit_cost` — a data column, not an engine call.
- **Refunds**: `shop_refunds` lifecycle `open → approved → processed | denied | closed` (models/ShopRefund.php:26; install.sql:369). `ShopRefund::create()` has **no order-status guard** — an admin can open a refund on a `completed` order, which is exactly why the PDF's *"later reversals must reduce the attributed result"* requirement maps to reversal rows in the quarter the refund was **processed** (D4). E-wallet-method refunds credit the wallet with `ref_type='shop_order'` (:198-206). Open/approved refunds block `delivered → completed` (:15, :245-248).
- **Cost data does not exist yet.** `products` (install.sql:52-70) carries `price`, `stock`, `sku`, descriptions — and **no cost column**. The PV columns are inert by standing decision (§0.2 of the shop plans). Contribution margin therefore requires a new `products.unit_cost` plus order-time snapshots (P1) — this is the single biggest data prerequisite and the reason P0 audits it.
- **Who can order**: any logged-in `role='member'` account. `guardShop()` only checks the `shop_enabled` setting (MemberController.php:1209-1214); `placeOrder()` (:1423+) checks auth + CSRF + cart + address + terms — **it does not check user status**, so **pending (free-registered) members can already browse and buy**. The free-partner path the PDF requires ("a separate, free partner registration path… a package is not an entry fee") already exists end-to-end: `free_registration_enabled` setting (install.sql:745, default '1'), referral-mode registration creates `status='pending'` accounts with `package_id=0 → NULL` and `reg_payment_method='pending'` (AuthController.php:432-444), and those accounts log in and are nagged to activate (:456-457).

### 2.4 Packages, registration, and routes

- `packages` (install.sql:20-39): `entry_fee`, `pairing_bonus`, `daily_pair_cap`, `direct_ref_bonus`, `lifetime_cap_multiplier`, `reactivation_fee/window`, `daily_fixed_income(_days)`, plus the three v2 toggles `indirect_referral_enabled`, `dfi_enabled`, `pairing_enabled` (added by `migrate_package_toggles.sql`). `Package::plans()` (models/Package.php:251-262) computes the plan mask; `upgradeCompatible()` (:268-278) enforces source-plans ⊆ target-plans on upgrade. The install seed ships a single Starter package (₱10,000 entry, DFI ₱100/day × 90 days, install.sql:695-703) — the live catalogue in production is larger; P0 audits the real package table.
- `User::register()` (models/User.php:11-141) opens its **own transaction** (:18, commit :117) and fires commissions **after** commit (:123-138). `User::activate()` (:147-249) flips pending → active and fires commissions (:232-246). The new plan changes none of this.
- **Route table** (index.php:272-392): 42 routes — 40 `member`-role, 2 `any` (`ewallet_transfer*`, protected only by in-controller `Auth::check()`), plus guest/super/admin blocks. Role guards at :407-422. Seat-limit hard block on registration routes at :254-268. The new plan adds 11 routes (Appendix A) and modifies none of the existing ones structurally.
- **Settings**: simple `key_name/value` table (install.sql:628-632), read via `setting()` (helpers.php:408). `AdminController::saveSettings()` (AdminController.php:520-623) writes per-tab groups with checkbox/hour clamping — the pattern P3 extends for a new `performance` settings tab.

### 2.5 Tests (and the stale AGENTS.md claim)

`tests/run.php` rebuilds a disposable `*_test` schema from `install.sql` and runs every `*Test.php` suite (tests/run.php:127-218; `--keep` and name filters supported). Existing suites: `SchemaDriftTest` (ENUM ↔ PHP-literal agreement, both directions, plus call-site scanning), `ShopStateMachineTest`, `QaSuiteTest`, `ViewRenderTest`, `TemplateLintTest`, plus `predeploy.php`. **AGENTS.md:3 and :132 claim there is no automated test suite — that claim is stale and wrong**; every phase in this plan carries its test obligations in the real harness (Appendix D).

### 2.6 PDF → codebase mapping summary

| PDF concept (page) | Codebase reality |
|---|---|
| Loyalty Reward rival (this plan's driver) | DFI engine (§2.1) — fixed daily liability, cap- and seat-braked |
| "Packages as commercial order values" (p2-3) | `packages.entry_fee` stays the ladder anchor; P5 repositions tiers and adds a display-only `performance_enabled` flag (D12) |
| 8-tier catalogue Basic→Platinum (p3) | New/edited package rows via existing admin Packages UI (AdminController:295-423); no schema change beyond the display flag |
| Eligible activity = paid, fulfilled, non-refunded orders (p4) | `shop_orders.status='completed'` + `shop_refunds` reversals (§2.3) |
| Contribution margin per transaction (p4) | New: `products.unit_cost`, `shop_order_items.unit_cost` snapshot, `performance_contributions` ledger (P1-P2) |
| A_q / C_q manual finance inputs (p4) | New `performance_periods` columns, entered at close (P3) |
| P_q = min(15% D_q, 20% M_q) (p4) | New engine `core/PartnerPerformance.php` (P2-P3), caps stored per period from settings |
| B_i pro-rata on positive CM (p4) | `performance_allocations` (P3), rounding rule D7 |
| Monthly reporting / quarterly settlement (p5-6) | On-the-fly provisional views (P2) + period lifecycle (P3) |
| Dashboard states Provisional/Under review/Approved/Paid (p6) | `performance_allocations.state` + member view (P4) |
| Free partner registration (p2, p7) | Already exists (§2.3); P5 markets it |
| No income guarantees / PH legal review (p6-8) | Copy gates in P5; legal checklist in P0/P7 |

---

## 3. Target design — the Partner Performance Plan vs Loyalty Reward

### 3.1 The plan in one page

**Name.** Marketing name: **Partner Performance Plan** (member-facing: "Performance Rewards"). Internal keys: `performance_pool` (commission type, ledger ref type), `performance_*` (settings), `pp_` prefixes avoided to prevent collision with existing `packages`/`payout` vocabulary.

**Participation.** Any `role='member'` account — including free-registered pending partners — participates automatically through what their orders contribute. There is **no package requirement, no entry fee, and no package-based share** (PDF p2-3: *"No package-based right to a larger share of the partner pool"*, *"A package is a product order, not an entry fee or requirement to qualify for compensation"*). Staff accounts (admin/superadmin) do not earn (D13).

**Eligible transaction.** A shop order that reached `completed` within the quarter (report window closed, no open/approved refund blocking it — exactly the state the existing state machine already guarantees), attributed to `shop_orders.member_id`. Refunds **processed** in a quarter produce reversal rows against the same member in that quarter (D4).

**Contribution margin (per member, per quarter).**

```
CM_i = Σ over completed orders of:
         ( order.total_price                                   -- net collected sales (invoice-frozen)
           − Σ line (unit_cost_at_order × quantity)             -- direct product cost, snapshotted at order time
           − fulfillment_cost_per_order )                       -- flat setting, snapshotted into the period
       − Σ reversals ( processed refunds in the quarter )       -- reduces CM_i; can go negative
```

Negative-margin orders net against the member's other orders (PDF p4: *"Negative-margin transactions and later reversals must reduce the attributed result"*) — a member whose quarter nets negative is simply excluded from allocation by `max(0, CM_i)`, and their losses still count in `M_q` (the aggregate), which **shrinks the pool**. This is the anti-gaming core: money-losing volume cannot buy pool share.

**Pool calculation (at quarterly close).**

```
A_q  = earnings available for the bonus under the adopted accounting policy   (finance input)
C_q  = unrestricted cash after taxes, payables, debt, order fulfillment,
       next production cycle, and minimum operating reserve                   (finance input)
D_q  = max(0, min(A_q, C_q))                                                  (distributable cash)
M_q  = Σ confirmed CM_i across all members (negatives included)               (aggregate margin)
P_q  = min(15% × D_q, 20% × M_q)                                              (the pool; 0 if either input ≤ 0)
W_q  = Σ max(0, CM_i)                                                         (allocation denominator)
B_i  = round2(P_q × max(0, CM_i) / W_q)                                       (member bonus)
```

**Calendar (PDF p6 service targets, Asia/Manila).**

| When | Action | Output |
|---|---|---|
| By the 15th of each month | Monthly management report (on-the-fly view + CSV export, always labeled **Provisional**) | Quarter-to-date earned sales, refunds, CM, provisional pool estimate at current caps |
| Within 30 days after quarter-end | **Close**: admin locks the quarter, finance enters `A_q`/`C_q`, engine freezes the contribution ledger and computes `D_q`, `M_q`, `W_q`, `P_q`, and per-member allocations | Period status `closed`; member states flip to **Under review** |
| Within 45 days after quarter-end | **Approve**: superadmin reviews the snapshot and approves (segregation of duties, D9) | Period `approved`; member states **Approved** |
| Target within 60 days after quarter-end | **Pay**: admin executes; wallets credited; ledger rows written | Period `paid`; member states **Paid** |
| Year-end | Annual reconciliation against final accounts; documented corrections only | Adjustment rows (P6) |

**Member dashboard states** (PDF p6, verbatim semantics): `Provisional` (estimate in a monthly report; not payable) → `Under review` (awaiting quarter-end reconciliation or approval) → `Approved` (authorized for payment) → `Paid` (matched to an actual payment transaction).

### 3.2 Why this is more sustainable than Loyalty Reward — the liability math

**DFI (today).** One Starter-class member on a ₱10,000 entry with `daily_fixed_income=₱100 × 90 days` creates **₱9,000 of fixed future payout** the moment they activate — 90% of their entry fee — payable whether the farm has revenue, margin, or cash that month or not. The obligation is born at registration, grows every night at a fixed rate, and is funded by *nothing in particular*. The codebase already retreats from it: DFI stops entirely when seats fill (midnight_reset.php:180-182) and freezes when a member caps. 1,000 such members = ₱9,000,000 of committed payouts against zero committed revenue.

**Performance pool (proposed).** The liability created by a member is **at most 20% of the verifiable margin their own completed, non-refunded orders generate, and at most 15% of distributable cash that already exists**. Three structural properties follow:

1. **Self-funding.** `P_q ≤ 20% × M_q` means the pool can never exceed one-fifth of realized contribution margin; `P_q ≤ 15% × D_q` means it can never exceed 15% of cash that survived taxes, payables, fulfillment of outstanding orders, the next production cycle, and the reserve. A quarter with no profit pays nothing — automatically, by formula, with no manual intervention and no broken promise, because the member-facing copy never promised a number.
2. **Volume-honest.** A member who orders ₱50,000 of goods with ₱8,000 margin earns a share of at most ₱1,600 (20% × 8,000) *if the whole company passes its cash tests, and only pro-rata against every other contributor*. Compare DFI: the same member on a ₱10,000/₱100×90 package earns ₱9,000 regardless.
3. **Reversal-safe.** Refunds claw margin back out of the *current* quarter before that quarter's pool is approved; the payment state machine already guarantees no order completes with an open refund.

**Worked example (PDF p4, reproduced for handoff).** `D_q = ₱50,000`; 15% cash cap = ₱7,500. `M_q = ₱80,000`; 20% margin cap = ₱16,000. Pool = **₱7,500** (the lesser). Partner A with `CM_i = ₱8,000` against `W_q = ₱80,000` earns `7,500 × 8,000/80,000 = ₱750`. A partner with zero eligible contribution earns ₱0. The PDF's own caution applies: the illustrative "₱10,000 split ₱100 ways" only works when 100 participants actually hold equal eligible contribution — *active account count alone creates no entitlement*.

### 3.3 Guardrails inherited from the PDF (non-negotiable)

1. Customer advances and funds needed to complete outstanding orders are **not** distributable cash (they live in `C_q`'s deductions, entered by finance at close).
2. Internal wallet credits do not prove equivalent cash exists — the pool inputs come from finance figures, never from wallet balances.
3. **No new registration or package purchase may create money in the pool.** Registration and activation flows are untouched by this plan; `commissions` types for referral/binary remain exactly as they are.
4. No payout is approved when the legal, accounting, cash, or reserve tests fail — a quarter can be closed with `P_q = 0` and that outcome is normal, auditable, and communicated.
5. Every period keeps a transaction ledger (`performance_contributions`), a calculation snapshot (`performance_periods.snapshot_json`), an approval trail (`performance_events` + status columns), a payout record (`commissions` + `ewallet_ledger` + `performance_allocations.paid_at`), and an adjustment history (`kind='adjustment'` rows).
6. All member-facing copy states variable outcomes; nothing anywhere says "guaranteed", "fixed", or "daily".

### 3.4 What we are deliberately NOT building

- No per-member lifetime cap on pool earnings (D8 rationale in §4) — the cap lives on the **pool**, not the earner, because the pool is already bounded by realized margin and distributable cash. This is a *deliberate, documented deviation* from the CapEngine pattern, flagged in the risk register (E-7).
- No CD split on pool payouts — the CD bucket exists to recover entry fees from referral commissions; pool bonuses have no entry fee to recover (D8).
- No event-driven hooks inside `ShopOrder` (Strategy C preserved; D5).
- No sponsor-chain attribution — that would re-import recruitment pay through the back door (D3).
- No guest storefront (D1).

---

## 4. Decision register

Each decision is final for this plan unless the business overrides it before the phase that applies it ships. "Rev." notes how expensive it is to reverse later.

| # | Decision | Rationale & rejected alternatives | Rev. |
|---|---|---|---|
| **D1** | **Scope excludes the guest storefront / guest checkout / customer zones.** Free partners use normal member accounts (pending status), which already works (§2.3). | User direction: "leave out the shop for free users, for now." Keeps this plan's blast radius inside the compensation domain. Guest-shop work stays parked in the companion plan. | Trivial (scope doc only) |
| **D2** | **Naming:** member-facing "Performance Rewards"; document/business-facing "Partner Performance Plan"; machine keys `performance_pool`, `performance_*`. Avoid "DFI2", "loyalty", "daily" in any new string. | The PDF's positioning must not inherit DFI's fixed-income vocabulary; machine keys follow existing snake_case settings conventions. | Cheap (string pass) until P5 ships |
| **D3** | **Attribution = the placing member** (`shop_orders.member_id`), fixed at the moment the order is paid. **No sponsor/unilevel attribution.** Shared or brokered orders are handled as manual `adjustment` rows with a documented split note at close. | The PDF pays "partner-generated sales" and explicitly refuses recruitment compensation; sponsor attribution is a consumption unilevel by another name. Rejected: sponsor %, split attribution, order-level "referred by" field (adds fraud surface with no pilot value). Manual adjustments keep the policy human-auditable per PDF p4 (*"attribution policy must identify how shared orders are split"*). | Medium (re-derivation possible since contributions are derived, not event-written) |
| **D4** | **Eligibility window:** an order contributes to the quarter in which it reached `completed` (completion date, not payment date). Refunds contribute reversals to the quarter in which the refund reached `processed`. Both derived at close time from live tables. | Completion is the state the existing machine already guarantees is fulfilled-and-unblocked (§2.3); refunds post-completion are possible (ShopRefund::create has no status guard), so reversals must land where finance actually returned money. Rejected: payment-date windows (ignores fulfillment risk), event-time hooks (violates Strategy C). | Medium |
| **D5** | **Cost basis:** `products.unit_cost` (new, per-unit direct product cost, admin-maintained) snapshotted to `shop_order_items.unit_cost` at `createFromCart` time. Fulfillment cost: flat `performance_fulfillment_cost_per_order` setting, its applied value recorded in the period snapshot. ShopOrder gains **one data column**; no engine calls. | Matches the invoice-snapshot philosophy (price already snapshots per line); keeps historical margins stable when catalog costs change. Rejected: computing cost at close from current catalog (rewrites history), per-SKU fulfillment matrix (deferred, G-4). | Medium |
| **D6** | **Pool inputs are manual:** `A_q` and `C_q` are typed by finance at close, stored on the period row. Caps (15% / 20%) are copied from settings onto the period at close and are editable only before approval; the snapshot freezes everything used. | The PDF requires a qualified accountant to define earnings availability and unrestricted cash — the system must not fake accounting. Per-period cap storage makes each quarter's math reproducible even if global settings later change. | Cheap |
| **D7** | **Allocation & rounding:** `B_i = P_q × max(0, CM_i)/W_q`, half-up to centavo. After rounding, any residual (± a few centavos) goes to the member with the largest `CM_i` (tie → lowest `member_id`, deterministic). Members with `CM_i ≤ 0` get nothing. | Deterministic, auditable, matches the PDF formula exactly. Rejected: proportional remainder redistribution (complex, unauditable), company-keeps-remainder (fine but arbitrary). | Cheap |
| **D8** | **Pool payouts bypass CapEngine and CD entirely.** Payment = `Ewallet::credit(..., 'performance_pool', withdrawable=true)` + a `commissions` row `type='performance_pool'` (no `cap_deduction`). Pool earnings do **not** increment `lifetime_earned`. | The cap bounds a *return on an entry fee*; the pool has no entry fee and is already hard-capped at company level (`≤ 20% of realized margin, ≤ 15% of distributable cash`). Running pool bonuses through per-member caps would block exactly the members the plan wants to reward and adds no solvency protection. CD exists to recover entry fees — nothing to recover here. Documented deviation; risk E-7 tracks it. | Cheap (one code path) |
| **D9** | **Segregation of duties:** admin (or superadmin) prepares and closes the quarter; **only superadmin approves**; admin executes payment. Route roles: close `admin`, approve `super`, pay `admin`. Every action appends `performance_events`. | PDF p6: "Finance reviewer and authorized management approve." The codebase already has the superadmin role and a `super` route role (slogin). Rejected: two admin accounts convention (unenforceable). | Cheap |
| **D10** | **Monthly reporting is derived on the fly** — no durable monthly rows, no required cron. A member's "provisional" figure is always computed from live orders quarter-to-date. An optional monthly cron writes a one-line log snapshot for operations history only. | Monthly figures are explicitly "provisional… not payable" (PDF p6); persisting them would create stale numbers members screenshot and quote. Rejected: monthly snapshot table (double bookkeeping). | Cheap |
| **D11** | **Calendar quarters, Asia/Manila.** `performance_periods.period_key = '2026Q4'` etc.; first pilot period **2026Q4 (Oct 1 – Dec 31, 2026)**; periods are seeded, not auto-created, so finance controls the calendar. | Matches the PDF's October 2026 proposal and the app's Manila timezone. Auto-creation risks periods with no finance owner. | Cheap |
| **D12** | **Participation is not package-gated.** Any member-role account earns through contribution. A new `packages.performance_enabled` column is **display/positioning only** (which packages advertise the plan in the catalogue and frontend); it is deliberately **not** added to `Package::plans()` so it can never block an upgrade. | Direct implementation of PDF p3 (*"no package-based right to a larger share"*) while still letting the catalogue tell the rival story per tier. | Cheap |
| **D13** | **Staff and status handling:** orders placed by admin/superadmin accounts are excluded from contributions (staff buying is operations, not partnership). Suspended/deactivated members' contributions still count (the sale was real) but their allocation is held in `adjusted` state pending review at pay time. | Consistent with ShopRefund's self-review refusal philosophy (staff may not benefit from their own administrative actions). Withholding-not-deleting protects the ledger's honesty about what the company actually earned. | Cheap |
| **D14** | **DFI is untouched mechanically.** Sunset, if the pilot supports it, is per-package `dfi_enabled=0` toggles plus member communication — never code deletion during the pilot. Accrued cycles finish; no new cycles start on switched packages. | Zero-risk coexistence during the four-quarter test; the rival wins on evidence (P7 gate), not on force. | Cheap |
| **D15** | **Audit trail:** every period carries `snapshot_json` (full inputs, per-member CM table, caps, computed outputs) frozen at close; close/approve/pay/adjust all append `performance_events` rows; allocations link to their `commissions` row. | PDF p5: "Keep a transaction ledger, calculation snapshot, approval trail, payout record, and adjustment history for every period." Mirrors `shop_order_events` philosophy. | Cheap |
| **D16** | **Cost backfill is conservative:** existing products get `unit_cost` entered by admin before the first close; historical `shop_order_items` rows lacking a snapshot are backfilled **only** where finance supplies data; orders with unknown cost are excluded (`status='excluded', exclude_reason='cost_unknown'`) rather than guessed at zero. | A zero-cost guess would inflate margin and pay real money on fiction — the exact failure mode the PDF's "Pricing gate" exists to prevent. | Cheap |
| **D17** | **Money precision:** all new money columns `DECIMAL(12,2)` PHP (matching repo convention, AGENTS.md:44); caps `DECIMAL(5,2)` percent; share `DECIMAL(9,6)`. All math in PHP floats rounded via `round(...,2)` at boundaries only. | Consistency with the 12,2 columns everywhere else; avoids double-rounding drift. | Cheap |
| **D18** | **Legal gates before public positioning (P5):** partner compensation agreement, income-disclosure copy, and PH counsel review notes (RA 8799 securities analysis; RA 7394 Art. 53 chain-distribution analysis; DTI Seal of Legitimacy as voluntary benchmark) are drafted in P0 and must be **approved before** `performance_plan_enabled` flips to '1' in production. | PDF p6-8 makes legal review a launch precondition, not a follow-up. The frontend copy gate (no "daily", no "guaranteed") is testable (Appendix D). | Cheap |
---

## 5. Phase map & dependencies

```
P0  Foundations: alignment, audits, legal prerequisites      (S)
 │
 ├─▶ P1  Data layer: costs, tables, ENUMs                    (M)
 │     │
 │     ├─▶ P2  Margin engine + monthly provisional reporting (M)
 │     │     │
 │     │     └─▶ P3  Quarterly close & approval workflow     (M)
 │     │           │
 │     │           └─▶ P4  Payout leg & member experience    (M)  ── money can move from here on
 │     │                 │
 │     │                 ├─▶ P5  Catalogue & "rival" switch  (M)  ── public positioning (gated by D18)
 │     │                 │
 │     │                 └─▶ P6  Monitoring & hardening      (M)
 │     │
 │     └── (P1 also feeds P5's catalogue flag and P6's monitors)
 │
 └─▶ P7  Pilot launch, four-quarter validation, DFI sunset gate (S, starts after P5)
```

- **P0 → P1** is the only hard sequence into the data layer; P0's audits gate P1's backfill strategy (D16).
- **P1 → P2 → P3 → P4** is strictly sequential: each phase's tables/engine feed the next.
- **P5 and P6** are parallelizable after P4; P5 carries the D18 legal gate.
- **P7** is an operating phase, not a build phase: pilot quarter(s), validation calendar, sunset decision.
- Total build effort: **~3S + 4M + S(operating)** ≈ 4–6 engineering weeks before pilot, spreadable across two people.

| Phase | New files | Modified files | New tables | New routes |
|---|---|---|---|---|
| P0 | audit SQL scripts (tmp/performance/) | none | none | none |
| P1 | migrate_partner_performance.sql, tmp/performance/backfill_costs.php | install.sql, ShopOrder.php, Ewallet.php, Product.php, AdminController (product form), SchemaDriftTest | performance_periods, performance_contributions, performance_allocations, performance_events | none |
| P2 | core/PartnerPerformance.php, tests/PerformancePoolTest.php | AdminController, MemberController, views (admin report view) | none | admin_performance_report |
| P3 | views/admin/performance.php, views/admin/performance_period.php | AdminController, settings view | none | 6 (overview, period, close, void, approve, adjust) |
| P4 | views/member/performance.php | MemberController, Commission.php, sidebar_member.php, earnings view, dashboard | none | 3 (page + api + pay) |
| P5 | migrate_performance_catalogue.sql | Package.php, packages view, register/upgrade copy, frontend/index.php, frontend/script.js | none | none |
| P6 | views/admin/performance_monitor.php | AdminController, PerformancePoolTest | none | 1 |
| P7 | tmp/performance/pilot_q4_2026.md | none | none | none |

---

## 6. Phase P0 — Foundations: alignment, data audits & legal prerequisites

**Effort: S** (half a day of engineering audits + concurrent business/accounting/legal track)

### 6.1 Objective

Establish, in writing and in the database, everything P1–P7 assume: what the real production data looks like (packages, orders, refunds, costs — or their absence), what the pilot settings are, and that the legal/accounting homework the PDF demands has an owner and a deadline. P0 produces **no application changes** — only audit results, decisions confirmed, and documents drafted.

### 6.2 Entry state (assumed)

- The codebase is as described in §2 (verified against the zip).
- The PDF business plan is the agreed direction; its caps (15% / 20%) and catalogue values are proposals pending finance validation.
- Production runs a schema at least as new as `install.sql` + the shop migrations (`migrate_shop.sql` etc.); the exact migration state of production is confirmed in audit A-1.

### 6.3 Exit state (delivered)

1. All six audits (A-1…A-6) executed against a **production copy** (never live), results filed under `tmp/performance/audit_YYYY-MM-DD.md`.
2. Pilot settings sheet signed off: caps (15/20), fulfillment cost per order, reserve policy targets, first period (2026Q4), catalogue tier values and which tiers open at pilot (Basic/Starter/Pro per PDF roadmap).
3. Finance engagement confirmed: who computes and enters `A_q`/`C_q` each quarter, under what accounting policy (PDF p4 requirement), and who signs the approval.
4. Legal track opened: PH counsel review of the arrangement against RA 8799 and RA 7394 Art. 53; partner compensation agreement and income-disclosure copy drafted (approval can land any time before P5 flips the flag — D18).
5. Go/no-go for P1 recorded against the audit results.

### 6.4 Decisions applied

D2 (naming), D6 (manual finance inputs), D11 (2026Q4 anchor), D16 (conservative backfill), D18 (legal gates). D3/D4/D5 confirmed as computable given audit A-3/A-4 results.

### 6.5 Work items

| # | Item | Size | Detail |
|---|---|---|---|
| A-1 | **Migration-state audit** | S | Run each `migrate_*.sql`'s "is this applied?" probe (column existence checks) against the production copy; record the delta vs the zip's `install.sql`. P1's migration must be written to apply cleanly on top of the *actual* production state, not the ideal one. |
| A-2 | **Package audit** | S | Dump `packages` + `package_indirect_levels`: per package, note `entry_fee`, `daily_fixed_income(_days)`, `dfi_enabled`, and count of members per package. Output: the live DFI liability profile (members × rate × days remaining) — the baseline the rival plan must beat. Also list which package names map to the PDF's 8 tiers (renames vs new rows — P5 needs this). |
| A-3 | **Order & refund history audit** | S | Counts and sums by status over the trailing 4 quarters: `completed` orders with totals, refund volumes by state, **post-completion refunds** (refunds whose order was already `completed` — proves whether D4's reversal window is exercised in practice), and average order value. Also: orders placed by admin/superadmin accounts (D13 exclusion volume). |
| A-4 | **Cost-data inventory** | S | The critical one: `products` has **no cost column** (§2.3). For each active SKU, obtain from the business a defensible unit cost (chick/egg sourcing, feed, veterinary, mortality amortization, labor attributable per unit — PDF p5 waterfall line 2). SKUs without a defensible cost stay excluded until costed (D16). Output feeds the P1 backfill. |
| A-5 | **Member-state audit** | S | Counts: pending (free) members, active, capped, perminact, suspended, deactivated; pending members who already placed shop orders (evidence the free-partner path is used); members with `capping_bypass`. Establishes who would be pool-eligible on day one. |
| A-6 | **Cron & environment audit** | S | Confirm which crons actually run in production (midnight_reset, shop_expiry, fund_transfer_limit_reset — check `cron/logs/`), PHP version ≥ 8.1, MySQL ≥ 8.0 (JSON column type and `DECIMAL` behavior), disk for `cron/logs/`. P2's optional monthly logger rides the existing cron host. |
| A-7 | **Settings sheet + sign-off** | S | One page: `performance_cash_cap_pct=15.00`, `performance_margin_cap_pct=20.00`, `performance_fulfillment_cost_per_order` (pilot: ₱0.00 unless ops supplies a defensible flat figure), reserve targets (production cycle + 3–6 months fixed costs + emergency provision, PDF p5), first period 2026Q4, pilot tiers Basic/Starter/Pro. Signed by owner + finance. |
| A-8 | **Legal & accounting track** | S(eng) / external | Draft (counsel): partner compensation agreement (eligibility, margin calc, caps, attribution, disallowed conduct, closing, verification, payment, correction, dispute — PDF p6 list); income-disclosure text; note on RA 8799 (is a performance-bonus pool a "security"?) and RA 7394 Art. 53. Engage the qualified accountant on `A_q`/`C_q` definitions (PDF p4: classification and recording of the bonus). Nothing ships publicly until approved (D18). |

### 6.6 Schema & settings changes

None. (P0 is read-only against a production copy.)

### 6.7 Tests

None — audits are SQL readouts. Each audit script is saved under `tmp/performance/audit_*.sql` so it is re-runnable at any later phase as a regression readout.

### 6.8 Acceptance criteria

- [ ] All audits A-1…A-6 executed and filed; every number in §2 that P1+ depends on (order volumes, refund behavior, product count without cost data) re-confirmed on production data.
- [ ] Settings sheet A-7 signed; pilot tier values and caps recorded.
- [ ] Counsel and accountant engaged with the two PDF documents as briefs; owner and deadline recorded.
- [ ] Explicit go/no-go recorded for P1, with the D16 backfill list (which SKUs get costs, which stay excluded).

### 6.9 Rollback

Nothing to roll back — no application or data changes. If no-go: park the plan; the PDF remains the standing proposal.

---

## 7. Phase P1 — Data layer: cost snapshots, performance tables, ENUM extensions

**Effort: M**

### 7.1 Objective

Land every schema change the plan needs, in one migration, **inert**: new tables exist but nothing writes business data into them yet; new columns default safely; the two ENUM extensions (`commissions.type`, `ewallet_ledger.ref_type`) and their PHP constants move together with the drift test so the suite stays green throughout. After P1 the application behaves identically to today — but P2+ can build on stable ground.

### 7.2 Entry state (assumed)

- P0 exited go. Migration-state audit (A-1) known; cost inventory (A-4) available for the backfill.
- Production schema = `install.sql` + audited migration set.

### 7.3 Exit state (delivered)

1. `migrate_partner_performance.sql` applied to production (full DDL in Appendix B): `products.unit_cost`, `shop_order_items.unit_cost`, `performance_periods`, `performance_contributions`, `performance_allocations`, `performance_events`, ENUM extension on `commissions.type` (+`performance_pool`), ENUM extension on `ewallet_ledger.ref_type` (+`performance_pool`).
2. `Ewallet::VALID_REF_TYPES` includes `performance_pool`; `tests/SchemaDriftTest.php` extended to pin the new value **and** to pin the new tables' ENUMs against the engine's PHP constants; whole suite green (`php tests/run.php`).
3. New orders snapshot `unit_cost` into `shop_order_items` (createFromCart insert extended); historical rows backfilled only where A-4 supplied data (D16).
4. Admin product form collects `unit_cost` (with a visible "required for performance-plan eligibility" hint).
5. Nothing else changed: no route, no view, no cron, no member-visible difference.

### 7.4 Decisions applied

D5 (cost snapshots at order time), D16 (conservative backfill), D17 (precision), D8 (the new ENUM values exist precisely because payouts bypass commission ref types), D15 (period snapshot + events tables created here, used from P3).

### 7.5 Work items

| # | Item | Size | Detail |
|---|---|---|---|
| W-1 | **Write & apply the migration** | S | `migrate_partner_performance.sql` (Appendix B, verbatim). Follows the repo's flat `migrate_*.sql` convention (cf. `migrate_package_toggles.sql`). Idempotence note: MySQL 8 supports `ADD COLUMN IF NOT EXISTS` only from 8.0.29+; include guarded comments and apply once, like every prior migration. |
| W-2 | **install.sql updated for fresh installs** | S | Append the same DDL (adjusted to inline `CREATE TABLE` position after `daily_fixed_income_log`, and the ENUM values inline in `commissions`/`ewallet_ledger` definitions) so `tests/run.php`'s schema rebuild picks it up. Add the new settings keys (Appendix C) to the seed block with `performance_plan_enabled='0'`. |
| W-3 | **Ewallet const + drift test** | S | `Ewallet::VALID_REF_TYPES` (models/Ewallet.php:28-31) gains `'performance_pool'` — the file's own header comment documents why const, migration and test must move together (defects C2/C7). Extend `SchemaDriftTest` section 3's assertions to cover the new value implicitly (they diff const vs ENUM — the test needs no new logic, but **add** explicit assertions for `performance_periods.status`, `performance_contributions.kind/status`, `performance_allocations.state` ENUMs vs new engine constants (written in P2; to keep P1 green, declare the constants in P1 inside `core/PartnerPerformance.php` as a stub with constants only, no methods). |
| W-4 | **Cost snapshot in createFromCart** | S | Extend the `shop_order_items` INSERT (models/ShopOrder.php:770-785) to carry `unit_cost` read from the cart item join — `Cart::getItems()` must select `p.unit_cost` alongside `unit_price` (models/Cart.php, one SELECT column). Snapshot semantics identical to `unit_price` (authoritative catalog read inside the order transaction). **No engine call** — Strategy C intact (models/ShopOrder.php:14-15). |
| W-5 | **Product admin form** | S | `AdminController::saveShopProduct` (:1019) + `views/admin/shop_products.php`: new `unit_cost` field (numeric, ≥ 0, required-soft: empty stores 0.00 which D16's exclusion treats as "not costed" only when the order predates the backfill or SKU is uncosted — see W-6). `Product.php` save path passes it through. |
| W-6 | **Backfill script** | S | `tmp/performance/backfill_costs.php` (follows `tmp/invoices/backfill_*.php` conventions): (a) set `products.unit_cost` from the A-4 sheet; (b) backfill `shop_order_items.unit_cost` **only** for orders whose SKU appears in the sheet — computed as the **current** sheet cost (documented approximation; historical cost records rarely exist) — and stamp a marker in the script's log; (c) rows left at the sentinel `-1.00`? **No** — keep `0.00` default and instead have the *engine* (P2) treat `unit_cost = 0.00 AND created_at < migration_date` as `cost_unknown` → exclusion (D16). Sentinel columns are drift bait. |
| W-7 | **Suite green** | S | `php tests/run.php` — all suites incl. extended drift; add a `PerformanceSchemaTest` (or fold into SchemaDriftTest): the four new tables exist with expected columns/uniques; `commissions.type` accepts `performance_pool`; `ewallet_ledger.ref_type` accepts `performance_pool`. |

### 7.6 Pseudocode — the only code change in P1 (cost snapshot)

```php
// models/Cart.php — getItems(): add one column to the SELECT
//   ..., p.unit_cost AS unit_cost, ...
// models/ShopOrder.php — createFromCart(), item INSERT (≈ :770):
$ins = $pdo->prepare(
    "INSERT INTO shop_order_items
        (order_id, product_id, product_name, product_sku, quantity,
         unit_price, unit_cost, total_price, reservation_state)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'reserved')"
);
// per item: execute([...existing args..., (float)($i['unit_cost'] ?? 0.00), ...]);
```

### 7.7 Tests (Appendix D, rows D-1…D-8)

- Schema drift: const↔ENUM both directions incl. `performance_pool` (commissions + ledger).
- New-table shape: uniques `uq_perf_period_key`, `uq_perf_alloc (period_id, member_id)`, one-contribution-per-order unique, FK delete rules (SET NULL for order/refund links so ledger survives order deletion, RESTRICT none).
- Fresh `install.sql` + migration both yield the same `SHOW CREATE TABLE` for the four new tables.
- createFromCart snapshots `unit_cost` (create cart with costed product → assert item row).
- Backfill idempotent (run twice → same values, log says "0 updated" second time).

### 7.8 Acceptance criteria

- [ ] `php tests/run.php` green on a schema built from updated `install.sql` **and** on a schema built from old `install.sql` + `migrate_partner_performance.sql`.
- [ ] A new order placed in staging carries `unit_cost` on every item line.
- [ ] `products` admin form saves cost; uncosted SKUs listed for the business (D16 exclusion list).
- [ ] No route, view, cron, or member-visible behavior changed (diff review: only the files in §5's P1 row).

### 7.9 Rollback

Revert the five code files; run the reversal SQL (Appendix B §B.6: drop 4 tables, drop 2 columns, shrink 2 ENUMs). The ENUM shrink is safe only because nothing writes the new values yet — this is exactly why P1 ships them **before** any writer exists and P4 is the first phase allowed to write them.

---

## 8. Phase P2 — Eligibility & contribution-margin engine + monthly provisional reporting

**Effort: M**

### 8.1 Objective

Build `core/PartnerPerformance.php`: the single class that (a) derives eligible contributions and reversals from live shop data for any date window, (b) aggregates per-member CM, (c) projects a provisional pool from *assumed* `A_q`/`C_q` (monthly reporting only — real inputs arrive at close), and (d) exposes admin-facing monthly report views/CSV. **No money moves, no member-facing UI yet** — P2 is the calculation core plus the admin's monthly reporting tool, exercised against real data behind the flag.

### 8.2 Entry state (assumed)

- P1 shipped: tables, snapshots, ENUMs, drift-green. `performance_plan_enabled='0'`.
- Cost data for pilot SKUs entered (A-4/W-6); excluded SKUs known.

### 8.3 Exit state (delivered)

1. `core/PartnerPerformance.php` with the public API: `contributionWindow($fromDate, $toDate)`, `memberSummary($memberId, $fromDate, $toDate)`, `provisionalPool($fromDate, $toDate, ?float $assumeA, ?float $assumeC)`, `memberProvisional($memberId)`, plus the P1 constants (`STATUSES`, `KINDS`, `CONTRIB_STATUSES`, `ALLOCATION_STATES`).
2. Admin route `admin_performance_report` (role `admin`): monthly report page + CSV export, quarter-to-date, always headed **"Provisional — not payable"** (PDF p6 wording).
3. Optional `cron/performance_monthly.php`: on the 15th, appends a one-line quarter-to-date snapshot to `cron/logs/performance_YYYY.log` (D10 — operational memory only, no durable member-facing rows).
4. Full unit suite `tests/PerformancePoolTest.php` for the math (Appendix D rows D-9…D-22).

### 8.4 Decisions applied

D3 (placing-member attribution), D4 (completion/reversal windows), D5 (cost basis + flat fulfillment), D10 (on-the-fly monthly), D13 (staff exclusion), D16 (`cost_unknown` exclusion), D17 (rounding).

### 8.5 Eligibility & margin derivation (the heart of the engine)

**Eligible order set for window `[from, to)`:**

```sql
-- Contributions: completed in-window
SELECT o.id, o.member_id, o.total_price, o.completed_at, u.role
FROM shop_orders o
JOIN users u ON u.id = o.member_id
WHERE o.status = 'completed'
  AND o.completed_at >= :from AND o.completed_at < :to
  AND u.role = 'member'                      -- D13: staff excluded
```

**Per-order margin** (computed in PHP from the row set — the ledger row is only *written* at close, P3; P2 computes transiently):

```
line_cost      = Σ shop_order_items (unit_cost × quantity)      -- snapshot (D5)
cost_known     = every line has unit_cost > 0 OR order.created_at >= :migration_date
                 -- orders before P1 with unit_cost = 0.00 are "cost_unknown" (D16) unless backfilled
fulfillment    = performance_fulfillment_cost_per_order          -- setting, per order
net_collected  = total_price                                      -- invoice-frozen at paid (§2.3)
contribution   = cost_known ? (net_collected − line_cost − fulfillment) : EXCLUDE('cost_unknown')
```

**Reversals in-window** (D4 — the quarter the refund was *processed*):

```sql
SELECT r.order_id, r.amount, r.processed_at, o.member_id, u.role
FROM shop_refunds r
JOIN shop_orders o ON o.id = r.order_id
JOIN users u ON u.id = o.member_id
WHERE r.status = 'processed'
  AND r.processed_at >= :from AND r.processed_at < :to
  AND u.role = 'member'
```

`member CM_i = Σ contributions − Σ reversals` (may be negative). `M_q = Σ CM_i` (negatives drag it down). `W_q = Σ max(0, CM_i)`.

**Provisional pool projection** (monthly view only): with assumed inputs `A*`, `C*` (defaults: the values finance last entered for the *previous* closed period, or NULL → show the two cap legs separately without asserting a pool number):

```
D* = max(0, min(A*, C*))
P* = min(cash_cap_pct × D*, margin_cap_pct × M_q_running)
```

### 8.6 Work items

| # | Item | Size | Detail |
|---|---|---|---|
| W-1 | **Engine class** | M | `core/PartnerPerformance.php` *(new)*. Static methods, direct PDO via `db()`, no session/Auth dependency in computation (cron-safe), modeled on `DailyFixedIncome.php`'s style. Constants from P1. Pure functions over `(from, to)` windows; no writes anywhere in P2. |
| W-2 | **Index review** | S | The contribution query needs `shop_orders (status, completed_at)` — exists? `idx_shop_orders_member_status` covers `(member_id, status, created_at)` (install.sql:252); **add** `idx_shop_orders_status_completed (status, completed_at)` in a small follow-up migration or fold into P1's (preferred — note it in Appendix B). Reversals need `shop_refunds (status, processed_at)` — add `idx_shop_refunds_status_processed`. Both cheap at P1 volume. |
| W-3 | **Admin report page + CSV** | S | `AdminController::performanceReport()` *(new)* + `views/admin/performance_report.php` *(new)*: month/quarter picker, tables (per-member CM running totals, excluded orders with reasons, reversal rows), the two cap legs, and — only if `A*`/`C*` known — a projected pool with the PDF's worked-example formatting. CSV via `fputcsv` to `php://output` with `Content-Disposition` (pattern exists in `AdminController::exportCodes`, :460). Route: `admin_performance_report` → role `admin` (index.php admin block). |
| W-4 | **Monthly log cron (optional)** | S | `cron/performance_monthly.php` *(new)*, mirroring `midnight_reset.php`'s bootstrap (:21-68): autoload, `PartnerPerformance::provisionalPool(...)` for the current quarter, one log line. Crontab: `0 8 15 * *`. Failure is non-fatal — monthly figures remain derivable on demand (D10). |
| W-5 | **Flag gating** | S | Every public entry point (`performanceReport`, cron) returns early with a neutral "plan not enabled" notice when `performance_plan_enabled='0'`. The *engine* itself stays callable (tests, close-dry-runs) — only surfaces are gated. |

### 8.7 Pseudocode — member summary

```php
public static function memberSummary(int $memberId, string $from, string $to): array
{
    $rows = self::contributionWindow($from, $to);          // cached per call
    $mine = array_filter($rows['contributions'], fn($r) => (int)$r['member_id'] === $memberId);
    $revs = array_filter($rows['reversals'],    fn($r) => (int)$r['member_id'] === $memberId);

    $cm = 0.0; $excluded = [];
    foreach ($mine as $o) {
        if ($o['cost_unknown']) { $excluded[] = [$o['id'], 'cost_unknown']; continue; }
        $cm += $o['net_collected'] - $o['line_cost'] - $o['fulfillment'];
    }
    foreach ($revs as $r) { $cm -= (float)$r['amount']; }

    return ['cm' => round($cm, 2), 'orders' => count($mine),
            'reversals' => count($revs), 'excluded' => $excluded];
}
```

### 8.8 Tests (Appendix D rows D-9…D-22 — fixtures built via existing models)

- Margin math: costed order ₱10,000 / cost ₱6,000 / fulfillment ₱200 → CM ₱3,800.
- Cost-unknown exclusion: pre-migration order with `unit_cost=0.00` → excluded with reason; post-migration costed order with cost 0.00 by data entry → **included** with CM = full net (document the boundary explicitly).
- Reversal: completed Q4 order ₱3,000 CM; refund processed Q1 next year ₱1,500 → Q4 CM stays, Q1 CM −₱1,500.
- Negative netting: member with +₱2,000 and −₱2,500 orders → `CM_i = −₱500`, excluded from `W_q`, drags `M_q` by −₱500.
- Staff exclusion: admin-placed completed order contributes to neither `M_q` nor any member.
- `M_q`/`W_q` aggregation; provisional projection math against the PDF worked example (§3.2) reproduces ₱7,500 / ₱750 exactly.
- Determinism: same window twice → identical arrays (no writes, no NOW() drift inside window bounds).

### 8.9 Acceptance criteria

- [ ] `php tests/run.php` green; `PerformancePoolTest` covers all D-9…D-22 rows.
- [ ] Admin report renders on staging real data for the trailing quarter; CSV opens in Sheets; header row says "Provisional — not payable".
- [ ] Excluded orders listed with `cost_unknown` reasons match the D16 list from P0.
- [ ] No writes: run the report, then checksum `performance_*` tables (still empty) and `commissions`/`ewallet_ledger` (unchanged).

### 8.10 Rollback

Delete `core/PartnerPerformance.php`, the view, the route line, the cron file. Tables from P1 remain (harmless, empty). Re-run suite.

---

## 9. Phase P3 — Quarterly close, pool calculation & approval workflow

**Effort: M**

### 9.1 Objective

Turn the P2 engine into the **durable, auditable quarterly settlement**: the period lifecycle `open → closed → approved` (payment lands in P4), the close operation that freezes the contribution ledger into `performance_contributions` rows, the finance inputs `A_q`/`C_q`, the pool computation `D_q/M_q/W_q/P_q` with per-member allocations, superadmin-only approval (D9), and the full snapshot + event trail (D15). After P3, a quarter can be settled end-to-end on staging with zero wallet movement.

### 9.2 Entry state (assumed)

- P2 shipped and validated against real data; the trailing quarter's report reconciles with finance's own numbers (P0's accountant has seen the methodology).
- `performance_periods` table exists (P1) but is empty; first period row `2026Q4` will be seeded here.

### 9.3 Exit state (delivered)

1. Period lifecycle implemented and route-guarded: **prepare/close** (admin) → **approve** (superadmin only) → pay (P4). Status ENUM: `open, closed, approved, paid, void` — `void` exists for the "closed by mistake before approval" path only; an *approved* period is never voided, only adjusted (P6).
2. `performance_contributions` rows written (frozen) at close: one `contribution` row per eligible order, one `reversal` row per processed refund, with computed amounts and `status='confirmed'` (or `excluded` with reason).
3. `performance_allocations` rows computed at close: per member `cm_i`, `share_pct`, `bonus_amount`, `state='under_review'`.
4. Admin UI: periods overview (`admin_performance`) + period detail (`admin_performance_period`) with close wizard (review contributions → review exclusions → enter `A_q`/`C_q` → preview pool & allocations → confirm close), approval screen for superadmin, and the frozen snapshot viewer.
5. `performance_events` append-only trail: `seeded, closed, approved, voided, adjusted, paid` with actor + note.
6. Idempotence and re-entrancy: closing an already-closed period is refused; a void (pre-approval) deletes nothing — rows flip `status='void'`-equivalent via the period, contributions return to derivable state, and the period can be re-closed after fixes.

### 9.4 Decisions applied

D4/D5 (derivation windows frozen at close), D6 (manual inputs, per-period caps), D7 (rounding & remainder), D9 (segregation), D11 (seeded calendar quarters), D13 (suspended/deactivated → allocation `state='adjusted'` note, payment review), D15 (snapshot + events).

### 9.5 Close operation — exact sequence (single transaction)

```
closePeriod(periodId, adminId, A_q, C_q, cashCapPct?, marginCapPct?, note):
  1. tx begin; SELECT period FOR UPDATE; assert status='open'
  2. Derive window [starts_on 00:00, ends_on+1d 00:00) Asia/Manila (D11)
  3. contributionWindow(from, to)                          -- P2 engine, no writes inside
  4. Insert performance_contributions:
       contributions (kind='contribution', order_id UNIQUE, member_id,
         net_collected, product_cost, fulfillment_cost, contribution_margin,
         status='confirmed' | 'excluded' + exclude_reason)
       reversals (kind='reversal', refund_id UNIQUE, member_id,
         net_collected=0, contribution_margin=-amount, status='confirmed')
  5. Aggregate: M_q = Σ confirmed margins (incl. negatives)
               W_q = Σ max(0, per-member confirmed CM)
  6. D_q = max(0, min(A_q, C_q));  P_q = min(caps×D_q, caps×M_q)
  7. Insert performance_allocations per member with CM_i ≠ 0:
       cm_i, share_pct = max(0,cm_i)/W_q ×100 (DECIMAL 9,6),
       bonus_amount = round2(P_q × max(0,cm_i)/W_q),  remainder → largest CM_i (D7)
       state = 'under_review'   (suspended/deactivated member → 'adjusted' + note, D13)
  8. UPDATE period: status='closed', a_q, c_q, m_q, w_q, d_q, pool_amount,
       cash_cap_pct/margin_cap_pct (copied from settings if not overridden),
       closed_by/at, snapshot_json = {inputs, per-member table, exclusions,
       engine_version, settings_at_close}
  9. INSERT performance_events (period, 'closed', adminId, note)
 10. commit
```

**Approve** (superadmin): `UPDATE ... SET status='approved', approved_by/at WHERE id=? AND status='closed'` + event row + allocations stay `under_review` until payment flips them (P4) — the *member-visible* "Approved" state maps to the period status; allocation rows carry it too for query convenience (`state='approved'` set in the same statement).

### 9.6 Work items

| # | Item | Size | Detail |
|---|---|---|---|
| W-1 | **Engine: lifecycle methods** | M | `closePeriod`, `approvePeriod`, `voidPeriod` (pre-approval only), `periodOverview`, `periodDetail` in `core/PartnerPerformance.php`. All writes transactional; `SELECT ... FOR UPDATE` on the period row serializes concurrent closes. |
| W-2 | **Seed the pilot period** | S | Insert `performance_periods ('2026Q4', 2026-10-01, 2026-11-01… no — '2026-10-01'→'2026-12-31', status='open')` via migration tail or admin seed button. Only seeded periods can close (D11). |
| W-3 | **Admin overview page** | S | `AdminController::performance()` *(new)* + `views/admin/performance.php` *(new)*: period cards (status badge, M_q/W_q/D_q/P_q once closed, SLA countdown: close +30d, approve +45d, pay +60d from `ends_on` — targets displayed, not enforced), member-facing-state summary counts. |
| W-4 | **Period detail + close wizard** | M | `AdminController::performancePeriod()` *(new)* + `views/admin/performance_period.php` *(new)*: tabs — Contributions (paged, filter confirmed/excluded, per-row exclude/include toggle **pre-approval only**, reason required), Allocations (paged), Snapshot (formatted `snapshot_json`), Events (trail). Close form: `A_q`, `C_q`, optional cap overrides, note. Buttons respect status + role: close/void = admin; approve = superadmin only. |
| W-5 | **Routes + guards** | S | `admin_performance` (admin), `admin_performance_period` (admin), `admin_performance_close` (admin, POST+CSRF), `admin_performance_approve` (**super**, POST+CSRF — the route-table role makes the segregation structural, index.php:419-422), `admin_performance_void` (admin, POST+CSRF), `admin_performance_adjust` (admin, POST+CSRF — inserts manual adjustment rows pre- or post-approval; post-approval adjustments force allocation recompute rules per D7 within the same period only via explicit note). |
| W-6 | **Settings tab** | S | `views/admin/settings.php` new pane `performance` + `saveSettings` group (AdminController:525-589 pattern): `performance_plan_enabled` (checkbox), `performance_cash_cap_pct`, `performance_margin_cap_pct`, `performance_fulfillment_cost_per_order` (numeric clamp ≥ 0), `performance_report_reminder_day` (int 1–28). Saved values apply to *future* closes; closed periods keep their frozen caps (D6). |
| W-7 | **Tests** | M | Appendix D rows D-23…D-34: lifecycle state machine, FOR UPDATE serialization (two closes → one wins), idempotent refusal, snapshot completeness, remainder-to-largest rule, staff exclusion inside close, suspended-member `adjusted` allocation, void path restores derivability, approve-only-by-super guard (route level + model assert), caps frozen at close. |

### 9.7 Acceptance criteria

- [ ] Staging dry-run of the trailing quarter: close with test `A_q`/`C_q` reproduces the P2 report's `M_q`/`W_q` to the centavo; allocations sum to `P_q` (± the single D7 remainder row).
- [ ] Superadmin approval works; a plain-admin approve attempt is redirected with "Access denied" (route role).
- [ ] Snapshot JSON contains inputs, per-member table, exclusions, and settings-at-close; events trail shows seeded→closed→approved with actors.
- [ ] `performance_plan_enabled='0'` hides all admin pages behind the neutral notice (flag flip is a P5/P7 decision, never P3's).
- [ ] Suite green.

### 9.8 Rollback

No wallet or member-visible state exists yet: void the staging period (pre-approval path), or drop the route lines + views + engine methods; tables remain. Production rollback = revert files; closed periods are data, not behavior.

---

## 10. Phase P4 — Payout leg & member experience

**Effort: M**

### 10.1 Objective

Complete the money path (approve → pay → e-wallet → existing payout flow) and ship the member-facing experience: the Performance Rewards page with the four PDF states, the contribution ledger, earnings-page integration, and dashboard widget. This is the first phase where pool money can move; it is also the first phase members can see.

### 10.2 Entry state (assumed)

- P3 shipped; a staging period has been approved with a known allocation table.
- `performance_plan_enabled` still '0' in production (flips in P5/P7 per D18 gate).

### 10.3 Exit state (delivered)

1. `payPeriod(periodId, adminId)`: for every allocation `state IN ('approved')` and member not suspended/deactivated: one transaction per member — `commissions` row (`type='performance_pool'`, gross amount, description with period key + share %), `Ewallet::credit(memberId, amount, commissionId, 'performance_pool', note, withdrawable=true)`, allocation → `state='paid'`, `paid_at`, `commission_id`; period → `status='paid'` when all rows settle; `performance_events` row. No CapEngine, no CD (D8).
2. Member page `performance` (role member): current-quarter **Provisional** panel (live CM running total, projected share at last-known caps — clearly labeled estimate), period history table with state chips (Provisional / Under review / Approved / Paid / Adjusted), per-period drill-down (contributions, reversals, exclusions affecting them), and the standing disclosure copy (no guarantees; quarterly; PDF-based).
3. `api_performance_summary` JSON for the dashboard widget (mirrors `api_dfi_status` pattern, MemberController:429).
4. Earnings integration: `Commission::summary()` gains `total_performance`; `history()` whitelist gains `performance_pool`; earnings view gains the type filter option; sidebar gains the Performance Rewards item (views/partials/sidebar_member.php:16-22 block).
5. Full e2e test: register → order → complete → refund-reversal scenario → close → approve → pay → wallet + ledger + payout flow all reconcile.

### 10.4 Decisions applied

D2 (naming in all copy), D7 (paid amounts are the frozen allocation values), D8 (bypass cap/CD, withdrawable credit), D9 (admin executes pay after super approval), D10 (provisional panel derived live), D13 (suspended/deactivated held as `adjusted` — pay skips them, review path in P6), D15 (paid event + commission/ledger links).

### 10.5 Pay operation — exact sequence

```
payPeriod(periodId, adminId):
  assert period.status='approved'
  rows = allocations WHERE period_id AND state='approved' ORDER BY cm_i DESC   -- deterministic
  foreach row (per-member transaction, DFI-style — DailyFixedIncome.php:84):
      if member.status IN ('suspended','deactivated'):
          row.state='adjusted'; row.note='held: member status'; continue       -- D13
      tx:
        INSERT commissions (user_id, type='performance_pool', amount=bonus_amount,
                            description="Partner Performance Pool {period_key} — share {share_pct}%")
        Ewallet::credit(user_id, bonus_amount, commissionId, 'performance_pool',
                        "Performance bonus {period_key}", /*withdrawable*/ true)
        UPDATE performance_allocations SET state='paid', paid_at=NOW(), commission_id=?
      on member failure: log + continue (cron-grade resilience, DFI pattern :138-143)
  UPDATE period SET status='paid', paid_by, paid_at WHERE status='approved'
  INSERT performance_events ('paid', adminId, totals note)
```

Withdrawing afterwards uses the **existing** `payout` flow untouched (min ₱500, fees, USDT rails — models/Payout.php).

### 10.6 Work items

| # | Item | Size | Detail |
|---|---|---|---|
| W-1 | **Engine pay methods** | M | `payPeriod` + `memberDashboard($userId)` (period rows + live provisional) in `core/PartnerPerformance.php`. |
| W-2 | **Member controller + view** | M | `MemberController::performance()` + `apiPerformanceStatus()` *(new)*; `views/member/performance.php` *(new)* — follows `views/member/cap_status.php`/`dfi_history.php` layout conventions (head partial, sidebar, flash, cards). State chips reuse `shop_status_badge` styling approach or new CSS class in `assets/css/main.css`. |
| W-3 | **Routes + sidebar** | S | `performance` (member), `api_performance_summary` (member), `admin_performance_pay` (admin, POST+CSRF). Sidebar item after 'DFI History' (sidebar_member.php:20): `['page' => 'performance', 'icon' => '🌱', 'label' => 'Performance Rewards', 'pages' => ['performance']]`. |
| W-4 | **Earnings integration** | S | `Commission::summary()` (:548-564) add `total_performance` CASE; `history()` whitelist (:586) add `performance_pool`; `views/member/earnings.php` filter chips + summary card. |
| W-5 | **Dashboard widget** | S | `views/member/dashboard.php`: when plan enabled, a card: current-quarter provisional CM + last paid period bonus + link. Data from `apiPerformanceStatus` inline render (server-side, no JS dependency). |
| W-6 | **Copy & disclosures** | S | The four-state explainer, "provisional figures are estimates, not payable", "bonuses depend on company performance and distributable cash", dispute pointer. English, matching the site's register; no "daily"/"guaranteed" strings (lint-checked in tests). |
| W-7 | **Tests** | M | Appendix D rows D-35…D-46: pay math, ledger ref type, withdrawable balance increment, suspended-member hold, partial-failure continuation, period `paid` only when all rows settled, member view renders (ViewRenderTest extension), earnings filter, API shape, copy lint. |

### 10.7 Acceptance criteria

- [ ] E2e on staging: seeded members + orders + one processed refund → close → approve → pay → every wallet balance equals its allocation; `commissions` and `ewallet_ledger` rows link 1:1; payout request for the paid amount succeeds through the existing flow.
- [ ] Member page shows: provisional CM for the open quarter; the settled period with state chips; the member's own contribution lines including the reversal.
- [ ] `SchemaDriftTest` call-site scan passes with the new `Ewallet::credit(..., 'performance_pool', ...)` call site (it greps models/controllers — the call lives in `core/`, so **extend the scan's glob to `core/*.php`** — noted in Appendix D).
- [ ] With flag off: routes show neutral notice; nothing appears in sidebar/dashboard/earnings.
- [ ] Suite green.

### 10.8 Rollback

Flag off hides all member surfaces. For a mis-paid period (worst case): reverse per member — `Ewallet::debit`-equivalent correction is deliberately **not** automated; per PDF p6, corrections are documented adjustments (P6 flow) — debit via admin top-up negative is forbidden; use a follow-up period adjustment row + manual wallet correction under accountant sign-off, recorded in `performance_events`. Code rollback = revert files; data corrections are procedural by design.

---

## 11. Phase P5 — Catalogue repositioning & the "rival" switch

**Effort: M** — **Gated by D18**: nothing in this phase goes to production until the legal/accounting documents from P0 are approved.

### 11.1 Objective

Make the new plan *visible and competitive*: reposition the package catalogue as commercial order values per the PDF's 8-tier table, add the per-package display flag that lets the frontend tell the rival story, switch the public site's benefit presentation from "Loyalty Reward ₱X/day for N days" to the quarterly performance-pool pitch on performance-flagged tiers, and emphasize the free-partner registration path. DFI continues untouched on legacy packages (D14) — the rivalry plays out in the catalogue, not in code deletion.

### 11.2 Entry state (assumed)

- P4 shipped; member experience live behind the flag.
- Legal copy approved (D18): compensation agreement, income disclosure, RA 8799/7394 review notes on file.
- P0's A-2 package audit decided which live packages map to the PDF tiers (rename/adjust) and which are new rows.

### 11.3 Exit state (delivered)

1. `packages.performance_enabled` column (display-only, D12) + admin Packages UI toggle + `Package::save()` field — deliberately absent from `plans()` so upgrades never consult it.
2. The 8-tier catalogue configured in production (values from the signed A-7 sheet; PDF p3: Basic ₱5,000 / Starter ₱10,000 / Pro ₱20,000 / Elite ₱50,000 / Silver ₱100,000 / Gold ₱250,000 / Diamond ₱500,000 / Platinum ₱1,000,000). Pilot visibility: **Basic, Starter, Pro only** (PDF roadmap Phase 2); Elite→Platinum exist but `status='inactive'` until the four-quarter test passes (pricing gate honored — tiers without validated product specs stay closed, PDF p3).
3. New/edited tiers carry: `dfi_enabled=0` (no Loyalty Reward on rival tiers), pairing/indirect/direct per business decision per tier (the PDF plan itself pays no recruitment money — the business may still keep binary features on some tiers as *platform features*; the sheet decides, the code already supports per-package toggles).
4. Frontend switch: package cards/modals on performance-flagged tiers show "🌱 Partner Performance — quarterly pool share from verified farm-sales margin" instead of the "📅 Loyalty Reward" row; a new "How performance rewards work" section with the four states, the two caps (15% cash / 20% margin) explained in plain language, and the no-guarantee disclosure; FAQ/compliance sections updated (the old Loyalty Reward copy remains only for tiers that still have DFI).
5. Registration/activation copy updated: package picker describes tiers as **order values** ("what you order", not "what you earn"), with the free-partner path ("register free, order when ready") given equal prominence.
6. `performance_plan_enabled='1'` in production — the switch that makes P2–P4 surfaces live.

### 11.4 Decisions applied

D1 (free partners = member accounts; no guest build), D2 (naming/copy rules), D12 (display-only flag, not in `plans()`), D14 (DFI untouched; rival tiers `dfi_enabled=0`), D18 (gates this phase).

### 11.5 Work items

| # | Item | Size | Detail |
|---|---|---|---|
| W-1 | **Catalogue migration** | S | `migrate_performance_catalogue.sql` *(new)*: `ALTER TABLE packages ADD COLUMN performance_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER dfi_enabled;` + `UPDATE packages SET performance_enabled=1 WHERE id IN (…)` for the rival tiers per the signed sheet. Update `install.sql` (column + seed) for fresh installs. |
| W-2 | **Package model + admin UI** | S | `Package::save()` fields array (:111-128) adds `performance_enabled`; `AdminController::savePackage` (:318) passes it; `views/admin/packages.php` Commission Toggles block gains the toggle with helper text: "Positions this package's marketing on the Partner Performance Plan. Does not affect pool participation — that follows verified orders only." |
| W-3 | **Catalogue configuration** | S | Execute the tier edits via the existing admin Packages UI (no code): names, order values, toggles, inactive status for Elite→Platinum. Screenshot-filed for the record. |
| W-4 | **Frontend index + script.js** | M | `frontend/index.php`: (a) earnings-preview table row logic — for performance tiers render the performance row (pool caps + "share of quarterly pool"), for DFI tiers keep the existing Loyalty Reward row; (b) new "Partner Performance Plan" section: four states, monthly/quarterly cadence, caps, no-recruitment-pay statement, free-partner path CTA to `?page=register`; (c) FAQ + compliance paragraphs: replace the fixed-income-flavored "farm's way of giving back" wording **only on performance tiers** with variable-outcome language; the PDF p8 "Website positioning draft" is the approved source text. `frontend/script.js`: package modal gains the same conditional row (`pkg.performance_enabled`). `ajaxGetPackages` (AuthController:495-510) extended to return `performance_enabled` (and `dfi` fields if not already exposed for the modal). |
| W-5 | **Registration/upgrade copy** | S | `views/auth/register.php`, `views/member/upgrade.php`, `views/member/activate.php`: order-value framing, free-partner note, link to the member performance page. No numeric income promises anywhere. |
| W-6 | **The switch** | S | Admin settings → `performance_plan_enabled='1'` (P2–P4 surfaces go live). Coordinate with W-4 deploy in one release window; the flag and the copy must never be out of sync (flag on + old copy = hidden plan; flag off + new copy = broken promise — test both). |
| W-7 | **Tests** | S | Appendix D rows D-47…D-53: flag on/off renders, copy lint across `frontend/` + `views/` for forbidden strings ("guaranteed", "per day", "fixed income" outside DFI-legacy tiers), packages UI round-trip, `plans()` unchanged by the new flag (regression: `upgradeCompatible` behavior identical). |

### 11.6 Acceptance criteria

- [ ] Public site: Basic/Starter/Pro cards show the performance pitch and no DFI row; legacy DFI packages (if any remain active) unchanged.
- [ ] A free partner can register (existing flow), log in, and see the Performance Rewards page with the provisional explainer.
- [ ] `performance_plan_enabled='1'`; admin report + member page + dashboard widget live.
- [ ] Legal-approved copy deployed verbatim; copy-lint test green.
- [ ] Elite→Platinum present but inactive; attempting to register/activate with them is refused by existing package-status checks (upgrade path already validates `status='active'`, MemberController:900,1012).
- [ ] Suite green.

### 11.7 Rollback

Flag off + revert frontend files. Catalogue tier changes are admin-data (revert via UI per the A-2 sheet). The migration column is inert and can stay.

---

## 12. Phase P6 — Monitoring, abuse controls & hardening

**Effort: M** — can run in parallel with early pilot quarters; items are independent.

### 12.1 Objective

Give operations the instruments the PDF's risk table demands: a liability/health monitor, attribution review tooling for duplicate/fraud/self-dealing patterns, the year-end adjustment flow, and test/log hardening. P6 turns "we can pay a pool" into "we can run this for years and prove every peso."

### 12.2 Exit state (delivered)

1. **Admin monitor page** (`admin_performance_monitor`): quarter-to-date accrued provisional pool (at last caps), completed-order volume, refund rate, excluded-volume split by reason, per-member concentration (top 10 by W_q share — the PDF's "recruitment overwhelms retail" risk shows up as concentration), SLA clocks for open periods.
2. **Attribution review flags** on the period detail (P3 page): orders sharing shipping address/phone across ≥ 2 member accounts, single-account order velocity spikes, orders shipping to staff addresses. Flagged rows are *review suggestions* — an admin confirms/excludes with a reason pre-approval (post-approval only via adjustments).
3. **Year-end adjustment flow**: `kind='adjustment'` rows (manual, noted, actor-stamped) that add/subtract a member's CM in a **new** period context per the accountant's correction policy; allocations of an already-paid period are never rewritten — corrections land in the open period, per PDF p6 ("documented and proportionate, subject to a dispute process").
4. **Dispute pointer**: member performance page footer links the dispute process from the compensation agreement (P0 legal doc).
5. **Hardening**: `cron/logs/performance_*.log` rotation check; `PerformancePoolTest` extended with concurrency (two simulated closes) and the `core/` call-site scan fix from P4; `predeploy.php` gains a flag-consistency check (`performance_plan_enabled` vs frontend copy presence).

### 12.3 Work items

| # | Item | Size | Detail |
|---|---|---|---|
| W-1 | Monitor page + route | S | `AdminController::performanceMonitor()` + view; route `admin_performance_monitor` (admin). Queries reuse P2 engine aggregates — no new math. |
| W-2 | Review flags | M | SQL detectors (address/phone overlap via `shop_orders` shipping fields + `member_addresses`; velocity = per-member completed count vs trailing mean) surfaced on the period-detail Contributions tab with a "flagged" chip; exclude-with-reason actions already exist from P3 W-4. |
| W-3 | Adjustment flow | M | `admin_performance_adjust` (P3 route) gains its model method: insert `kind='adjustment'` row (member_id, ±margin, note, actor), recompute the **open** period's provisional figures; if the period is closed-but-unapproved, allow recompute only via void→re-close; if approved/paid → must target the next open period. Tests D-54…D-59. |
| W-4 | Hardening pass | S | Log rotation (`cron/logs/` monthly files pattern), `predeploy.php` checks, extend drift/copy lints; add `performance_events` retention note (append-only, never pruned — disk is trivial). |

### 12.4 Acceptance criteria

- [ ] Monitor page loads under 1s on a quarter of pilot-scale data; numbers reconcile with the report page.
- [ ] A seeded duplicate-address scenario is flagged on staging and excludable pre-approval.
- [ ] An adjustment lands in the open period, is visible to the member (as part of provisional CM), and never mutates a paid period.
- [ ] Suite green including new concurrency tests.

### 12.5 Rollback

Each work item is an additive admin surface; revert per-item. Adjustments are audit data — no code rollback removes them (by design).

---

## 13. Phase P7 — Pilot launch, four-quarter validation & DFI sunset path

**Effort: S** (build) — the phase is mostly *operating the plan*.

### 13.1 Objective

Launch the pilot per the PDF roadmap Phase 2, run monthly reporting and quarterly settlement for **four consecutive quarters** (2026Q4 → 2027Q3), validate against the PDF's exit conditions, and hold the DFI sunset decision against evidence.

### 13.2 Exit state (delivered)

1. The plan is live in production with `performance_plan_enabled='1'`, pilot tiers (Basic/Starter/Pro) selling, and free partners onboarded through the existing registration path.
2. Four quarters settled on the §13.4 calendar with the full audit chain (ledger → snapshot → approval → payout) intact for every period.
3. A four-quarter evidence pack (DFI liability vs pool paid, concentration, refund rates, reserve statements) filed under `tmp/performance/`.
4. A recorded DFI disposition decision (sunset / keep both / extend pilot) with member communication executed if sunset is chosen.
5. Roadmap Phase 4 scope (Elite→Platinum activation, service-reward categories per G-4) drafted as the next planning cycle.

### 13.3 Launch checklist (PDF p8, operationalized)

| # | Gate | Owner | Evidence |
|---|---|---|---|
| L-1 | Every pilot package (Basic/Starter/Pro) has a verified product/service specification and defensible price (pricing gate) | Business | A-4 sheet + catalogue screenshots |
| L-2 | Customer advances separated from earned revenue and distributable cash (accounting policy covers `C_q` deductions) | Accountant | Policy memo (P0 A-8) |
| L-3 | Contribution margin traceable to completed, verified transactions (engine + exclusions live) | Eng | P2–P4 test suite green on production data dry-run |
| L-4 | Reserve covers planned production + operating requirements | Finance | Reserve statement at each close |
| L-5 | Pool formula, eligibility, caps, attribution, dispute process documented & approved | Counsel + owner | Signed agreement (D18) |
| L-6 | Legal and accounting review completed | Counsel + accountant | RA 8799/7394 notes on file |
| L-7 | Monthly reporting and quarterly calculations tested against sample transactions | Eng + finance | P3 staging dry-run reconciliation to the centavo |
| L-8 | All marketing avoids fixed-income / guaranteed-return / recruitment-income promises | Owner | Copy-lint test + manual review sign-off |

**The PDF's decision rule stands for every payout:** *if the business cannot explain where every peso of a proposed bonus comes from, or cannot fulfill existing customer orders and maintain the reserve after paying it, the pool is not ready to be paid.* Operationally: if `A_q` or `C_q` is not delivered by finance by the close SLA, the period closes late or at `P_q = 0` — **never** with estimated inputs.

### 13.4 Four-quarter validation calendar (Asia/Manila)

| Quarter | Close by | Approve by | Pay target | Validation focus |
|---|---|---|---|---|
| 2026Q4 | Jan 30, 2027 | Feb 14, 2027 | Mar 1, 2027 | First live close; reconciliation of engine vs books; exclusion policy exercised |
| 2027Q1 | Apr 30, 2027 | May 15, 2027 | Jun 1, 2027 | Refund/reversal realism; concentration monitor; first member disputes (if any) |
| 2027Q2 | Jul 30, 2027 | Aug 14, 2027 | Sep 1, 2027 | Reserve adequacy under a real production cycle; cap settings review (15/20 still right?) |
| 2027Q3 | Oct 30, 2027 | Nov 14, 2027 | Dec 1, 2027 | Year-end dry run; sunset decision pack |

**Exit conditions (PDF roadmap Phase 3):** reserves remain intact; all bonuses auditable (snapshot → ledger → approval → payout 1:1); no fixed-return claims or recruitment pay (copy lint + marketing review). Only then does roadmap Phase 4 (open Elite→Platinum, consider service-reward categories with written rules — G-4) become eligible.

### 13.5 DFI sunset path (decision, not code)

At the 2027Q3 review, decide per-package DFI disposition with three options on the table:
1. **Sunset new cycles**: flip `dfi_enabled=0` on remaining DFI packages (one admin action per package, existing UI); accrued cycles run out naturally (`dfi_days_used` continues to its cap — the engine is untouched, D14); members get advance notice + the performance page as the successor benefit.
2. **Keep both** on specific tiers (catalogue shows both rows honestly).
3. **Extend pilot** another year with caps reviewed.

The decision is recorded as a new numbered decision (D19+) referencing the four-quarter evidence pack: DFI liability paid per quarter (from `daily_fixed_income_log` sums vs quarter revenue), pool paid per quarter (from `performance_periods.pool_amount`), concentration, refund rates, and reserve statements.

### 13.6 Work items

| # | Item | Size | Detail |
|---|---|---|---|
| W-1 | Evidence pack generator | S | `tmp/performance/pilot_pack.php`: per-quarter DFI-vs-pool comparison, concentration, exclusions — feeds the sunset decision. |
| W-2 | Ops runbook | S | The calendar table above + the close/approve/pay click-path + the `P_q=0` communication template, filed under `tmp/performance/`. |
| W-3 | Sunset execution (if chosen) | S | Admin UI toggles + member notice + FAQ update; no engine changes. |

### 13.7 Acceptance criteria (end of pilot)

- [ ] Four quarters settled on schedule (or with documented, communicated delays); every payout traceable end-to-end.
- [ ] Exit conditions met and recorded.
- [ ] DFI disposition decided with evidence; member communication shipped.
- [ ] Roadmap Phase 4 scope (higher tiers, service rewards) drafted as the next plan cycle.

### 13.8 Rollback

Operational phase — rollback is the sunset/keep decision itself. All code paths remain flag-gated; `performance_plan_enabled='0'` at any moment stops new closes/payments and hides member surfaces without touching settled data.

---

## 14. Cross-cutting concerns

### 14.1 Transactions & idempotency

- **Close** is one transaction over the period row (`SELECT ... FOR UPDATE` serializes), the ledger inserts, the allocations, and the snapshot. Re-close of a closed period is refused by the status assert; the unique keys (`uq_perf_contrib_order`, `uq_perf_reversal_refund`, `uq_perf_alloc`) make double-inserts impossible even under a race.
- **Pay** is per-member transactions inside a loop — a failing member must never block the queue (same resilience pattern as `DailyFixedIncome::processDailyPayout`, core/DailyFixedIncome.php:138-143). The period flips to `paid` only after the loop; partial runs are re-runnable (allocations still `approved` are the resume set).
- **No writes at all** in the monthly/provisional path (D10) — reporting is idempotent by construction.
- The engine never participates in shop-order transactions; Strategy C (models/ShopOrder.php:14-15) is never violated (D5).

### 14.2 Money precision & rounding

All money `DECIMAL(12,2)`, PHP floats only between DB reads and `round(x, 2)` at boundaries (D17). Percent caps `DECIMAL(5,2)`; share `DECIMAL(9,6)` stored for audit (displayed as %). Rounding happens exactly twice: allocation amounts at close, and nothing at pay (paid = stored allocation). The D7 remainder rule (largest `CM_i`, tie → lowest member id) makes `Σ B_i = P_q` exactly, centavo-accurate — the reconciliation assertion in tests D-27/D-40 proves it on every close.

### 14.3 Timezone & calendars

App timezone is Asia/Manila (config/db.php; crons re-set it, cron/midnight_reset.php:22). Quarter windows are computed from date strings in Manila; `completed_at`/`processed_at` are DATETIME columns written by the app's clock — consistent. DST does not exist in PH. Monthly report day, close/approve/pay targets are stored as settings/dates, displayed as targets not guarantees (PDF p6).

### 14.4 Security & access control

- New routes carry table roles (`member`, `admin`, `super`) — guards at index.php:407-422. All POST handlers `csrf_verify()`. Admin pages behind `Auth::guard('admin')`.
- **Segregation of duties is structural** (D9): approve is `super`-role — a plain admin cannot reach the method even with a forged URL; the model additionally asserts status transitions.
- The engine's computation path never touches `Auth`/session (cron-safe, test-safe); the only actor context is passed explicitly (`$adminId`).
- No new upload surfaces, no new JSON input parsing beyond `snapshot_json` **output** (generated, never read back into logic — the viewer only pretty-prints).
- SQL: prepared statements everywhere, matching repo convention (AGENTS.md:110).

### 14.5 Audit trail

Five PDF-mandated artifacts (p5), mapped: transaction ledger = `performance_contributions`; calculation snapshot = `performance_periods.snapshot_json` + frozen cap columns; approval trail = `performance_events` + status/actor stamps; payout record = `commissions` + `ewallet_ledger` + `performance_allocations.paid_at/commission_id`; adjustment history = `kind='adjustment'` rows + events. `performance_events` is append-only (no UPDATE/DELETE path written, mirroring `shop_order_events`, models/ShopOrder.php:9-11).

### 14.6 Performance & locking

Pilot volumes (P0 A-3 will confirm) are far below the thresholds where the window queries matter; the two new indexes (P2 W-2) cover the paths. Close takes a row lock on one period row for the duration of one quarter's derivation — sub-second at pilot scale. Pay locks per member row only. The monitor page aggregates with the same window queries; if it ever degrades, pre-aggregate into the monthly log (already optional).

### 14.7 Language & copy discipline

All member/admin copy in English (site register), governed by D2's vocabulary ban: no "daily", "guaranteed", "fixed income", "passive" on performance-plan surfaces. Enforced by a copy-lint test (D-49) so a marketing edit cannot silently reintroduce securities-flavored language — the exact risk the PDF's income-disclosure rules exist for.

---

## Appendix A — New & modified route inventory

All additions go into the existing `$routes` array (index.php:272-392). No existing route is modified.

| Route | Controller::method | Role | Phase | Notes |
|---|---|---|---|---|
| `performance` | MemberController::performance | member | P4 | Member Performance Rewards page |
| `api_performance_summary` | MemberController::apiPerformanceStatus | member | P4 | JSON for dashboard widget |
| `admin_performance_report` | AdminController::performanceReport | admin | P2 | Monthly provisional report + CSV |
| `admin_performance` | AdminController::performance | admin | P3 | Periods overview |
| `admin_performance_period` | AdminController::performancePeriod | admin | P3 | Period detail + close wizard |
| `admin_performance_close` | AdminController::performanceClose | admin | P3 | POST, CSRF; prepare/close |
| `admin_performance_void` | AdminController::performanceVoid | admin | P3 | POST, CSRF; pre-approval void only |
| `admin_performance_approve` | AdminController::performanceApprove | **super** | P3 | POST, CSRF; segregation of duties (D9) |
| `admin_performance_adjust` | AdminController::performanceAdjust | admin | P3/P6 | POST, CSRF; manual adjustment rows |
| `admin_performance_pay` | AdminController::performancePay | admin | P4 | POST, CSRF; executes approved pool |
| `admin_performance_monitor` | AdminController::performanceMonitor | admin | P6 | Health/concentration monitor |

Modified behaviors (no route change): `earnings` (new type filter), `dashboard` (widget), sidebar partial, `admin_settings` (new tab group), `ajaxGetPackages` (new field), `get_packages`-adjacent registration views (copy).

## Appendix B — Complete schema DDL

### B.1 `migrate_partner_performance.sql` (P1, applied once)

```sql
-- ============================================================
--  Partner Performance Plan — data layer (Phase P1)
--  Run once: mysql -u root -p DATABASE < migrate_partner_performance.sql
--  Inert by design: no writer ships until P4.
-- ============================================================

-- 1. Direct product cost (per unit) — the margin basis (D5)
ALTER TABLE products
  ADD COLUMN unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER price;

-- 2. Order-time cost snapshot, alongside the existing price snapshot
ALTER TABLE shop_order_items
  ADD COLUMN unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unit_price;

-- 3. Commission type for pool payouts (D8)
ALTER TABLE commissions
  MODIFY COLUMN type ENUM('pairing','direct_referral','indirect_referral',
                          'daily_fixed_income','performance_pool') NOT NULL;

-- 4. Ledger ref type for pool payouts (D8) — keep in sync with
--    Ewallet::VALID_REF_TYPES (asserted by tests/SchemaDriftTest.php)
ALTER TABLE ewallet_ledger
  MODIFY COLUMN ref_type ENUM('commission','payout','reactivation','transfer','topup',
                              'registration','upgrade','shop_order','performance_pool') NULL;

-- 5. Query indexes for the derivation windows (D4)
ALTER TABLE shop_orders
  ADD INDEX idx_shop_orders_status_completed (status, completed_at);
ALTER TABLE shop_refunds
  ADD INDEX idx_shop_refunds_status_processed (status, processed_at);

-- 6. Periods (one row per settled quarter; seeded, never auto-created — D11)
CREATE TABLE performance_periods (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period_key     CHAR(6)      NOT NULL,                     -- '2026Q4'
  starts_on      DATE         NOT NULL,
  ends_on        DATE         NOT NULL,
  status         ENUM('open','closed','approved','paid','void') NOT NULL DEFAULT 'open',
  a_q            DECIMAL(14,2) NULL,                        -- earnings available (finance input, D6)
  c_q            DECIMAL(14,2) NULL,                        -- unrestricted cash (finance input, D6)
  m_q            DECIMAL(14,2) NOT NULL DEFAULT 0.00,       -- aggregate net eligible CM
  w_q            DECIMAL(14,2) NOT NULL DEFAULT 0.00,       -- Σ max(0, CM_i)
  d_q            DECIMAL(14,2) NOT NULL DEFAULT 0.00,       -- max(0, min(a_q, c_q))
  pool_amount    DECIMAL(14,2) NOT NULL DEFAULT 0.00,       -- P_q
  cash_cap_pct   DECIMAL(5,2)  NOT NULL DEFAULT 15.00,      -- frozen at close (D6)
  margin_cap_pct DECIMAL(5,2)  NOT NULL DEFAULT 20.00,      -- frozen at close (D6)
  closed_by      INT UNSIGNED NULL, closed_at  DATETIME NULL,
  approved_by    INT UNSIGNED NULL, approved_at DATETIME NULL,
  paid_by        INT UNSIGNED NULL,  paid_at    DATETIME NULL,
  snapshot_json  JSON NULL,                                -- full calculation snapshot (D15)
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_perf_period_key (period_key),
  UNIQUE KEY uq_perf_period_bounds (starts_on, ends_on),
  FOREIGN KEY (closed_by)   REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (paid_by)     REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 7. Contribution ledger (frozen at close; reversals are negative-margin rows)
CREATE TABLE performance_contributions (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period_id           INT UNSIGNED NULL,        -- set at close; NULL never occurs (rows are born in a close)
  order_id            INT UNSIGNED NULL,        -- contribution rows
  refund_id           INT UNSIGNED NULL,        -- reversal rows
  member_id           INT UNSIGNED NOT NULL,    -- attribution: placing member (D3)
  kind                ENUM('contribution','reversal','adjustment') NOT NULL,
  net_collected       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  product_cost        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  fulfillment_cost    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  contribution_margin DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- negative for reversals
  status              ENUM('confirmed','excluded','void') NOT NULL DEFAULT 'confirmed',
  exclude_reason      VARCHAR(160) NULL,        -- 'cost_unknown' | review flags | manual
  reviewed_by         INT UNSIGNED NULL, reviewed_at DATETIME NULL,
  note                VARCHAR(255) NULL,        -- adjustment rationale
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_perf_contrib_order  (order_id, kind),
  UNIQUE KEY uq_perf_reversal_refund (refund_id),
  INDEX idx_perf_contrib_member (member_id, period_id),
  INDEX idx_perf_contrib_period (period_id, status),
  FOREIGN KEY (period_id)   REFERENCES performance_periods(id),
  FOREIGN KEY (order_id)    REFERENCES shop_orders(id)  ON DELETE SET NULL,
  FOREIGN KEY (refund_id)   REFERENCES shop_refunds(id) ON DELETE SET NULL,
  FOREIGN KEY (member_id)   REFERENCES users(id),
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 8. Per-member allocations (born at close; state machine P3/P4)
CREATE TABLE performance_allocations (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period_id     INT UNSIGNED NOT NULL,
  member_id     INT UNSIGNED NOT NULL,
  cm_i          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  share_pct     DECIMAL(9,6)  NOT NULL DEFAULT 0.000000,
  bonus_amount  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  state         ENUM('under_review','approved','paid','adjusted','zeroed') NOT NULL DEFAULT 'under_review',
  commission_id INT UNSIGNED NULL,          -- set at pay (D15)
  paid_at       DATETIME NULL,
  note          VARCHAR(255) NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_perf_alloc (period_id, member_id),
  FOREIGN KEY (period_id)     REFERENCES performance_periods(id),
  FOREIGN KEY (member_id)     REFERENCES users(id),
  FOREIGN KEY (commission_id) REFERENCES commissions(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 9. Append-only lifecycle events (D15)
CREATE TABLE performance_events (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period_id  INT UNSIGNED NOT NULL,
  actor_id   INT UNSIGNED NULL,
  action     ENUM('seeded','closed','approved','voided','adjusted','paid') NOT NULL,
  note       VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_perf_events_period (period_id, created_at),
  FOREIGN KEY (period_id) REFERENCES performance_periods(id),
  FOREIGN KEY (actor_id)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 10. Settings (idempotent upserts)
INSERT INTO settings (key_name, value) VALUES
  ('performance_plan_enabled',            '0')
ON DUPLICATE KEY UPDATE value = value;
-- (remaining keys inserted identically — see Appendix C)
```

### B.2 `install.sql` additions (P1, for fresh installs)

Inline the two new columns into `products` / `shop_order_items`; inline the two new ENUM values into `commissions.type` / `ewallet_ledger.ref_type`; append the four `CREATE TABLE`s after `daily_fixed_income_log`; append the two new indexes near the existing index block (install.sql:654-668); append Appendix C's settings to the seed `INSERT` (:719-752).

### B.3 `migrate_performance_catalogue.sql` (P5)

```sql
ALTER TABLE packages
  ADD COLUMN performance_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER dfi_enabled;
-- Tier flips per the signed A-7 sheet, e.g.:
-- UPDATE packages SET performance_enabled = 1, dfi_enabled = 0 WHERE id IN (…);
```

### B.4 Backfill script (P1 W-6)

`tmp/performance/backfill_costs.php` — reads `tmp/performance/cost_sheet.csv` (`sku,unit_cost`), updates `products.unit_cost`, backfills `shop_order_items.unit_cost` for matching SKUs (log per order), reports uncosted SKUs + pre-migration orders left at 0.00 (the D16 exclusion list). Idempotent; dry-run flag.

### B.5 Seed rows (P3 W-2)

```sql
INSERT INTO performance_periods (period_key, starts_on, ends_on, status)
VALUES ('2026Q4', '2026-10-01', '2026-12-31', 'open');
INSERT INTO performance_events (period_id, actor_id, action, note)
VALUES (LAST_INSERT_ID(), NULL, 'seeded', 'Pilot period per PDF roadmap Phase 2');
```

### B.6 Reversal SQL (rollback, P1 only — safe because no writer exists yet)

```sql
DROP TABLE IF EXISTS performance_events, performance_allocations,
                     performance_contributions, performance_periods;
ALTER TABLE shop_orders    DROP INDEX idx_shop_orders_status_completed;
ALTER TABLE shop_refunds   DROP INDEX idx_shop_refunds_status_processed;
ALTER TABLE products       DROP COLUMN unit_cost;
ALTER TABLE shop_order_items DROP COLUMN unit_cost;
ALTER TABLE commissions
  MODIFY COLUMN type ENUM('pairing','direct_referral','indirect_referral',
                          'daily_fixed_income') NOT NULL;
ALTER TABLE ewallet_ledger
  MODIFY COLUMN ref_type ENUM('commission','payout','reactivation','transfer','topup',
                              'registration','upgrade','shop_order') NULL;
```

## Appendix C — Settings keys

| Key | Default | Clamp/validation | Consumed by |
|---|---|---|---|
| `performance_plan_enabled` | `0` | checkbox ('1'/'0'), AdminController checkboxKeys pattern (:596) | All surfaces (P2–P6 gate) |
| `performance_cash_cap_pct` | `15.00` | numeric 0–100, 2dp | Pool math at close (frozen per period) |
| `performance_margin_cap_pct` | `20.00` | numeric 0–100, 2dp | Pool math at close (frozen per period) |
| `performance_fulfillment_cost_per_order` | `0.00` | numeric ≥ 0, 2dp | Margin per order (D5) |
| `performance_report_reminder_day` | `15` | int 1–28 | Monthly log cron + SLA display |
| `performance_close_target_days` | `30` | int ≥ 1 | SLA display only (targets, not gates) |
| `performance_approve_target_days` | `45` | int ≥ 1 | SLA display only |
| `performance_pay_target_days` | `60` | int ≥ 1 | SLA display only |

Existing settings relied upon, unchanged: `shop_enabled`, `free_registration_enabled`, `seat_limit`, `min_payout`, `maintenance_mode`.

## Appendix D — Test matrix

Run via `php tests/run.php` (suites auto-discovered; fixtures through real models, schema rebuilt per run — tests/run.php:127-218).

| Row | Suite / assertion | Phase |
|---|---|---|
| D-1 | SchemaDrift: `Ewallet::VALID_REF_TYPES` ↔ `ewallet_ledger.ref_type` both directions incl. `performance_pool` | P1 |
| D-2 | SchemaDrift: `commissions.type` accepts and covers `performance_pool` (add a Commission const, e.g. `Commission::TYPES`, and assert like ShopRefund::STATUSES) | P1 |
| D-3 | New-table shape: uniques, FK delete rules, ENUMs match engine constants (`PERIOD_STATUSES`, `KINDS`, `CONTRIB_STATUSES`, `ALLOCATION_STATES`) | P1 |
| D-4 | Fresh `install.sql` ≡ old install + migration (`SHOW CREATE TABLE` diff on the 4 tables + 2 enums) | P1 |
| D-5 | createFromCart snapshots `unit_cost` per line | P1 |
| D-6 | Product admin form round-trips `unit_cost` | P1 |
| D-7 | Backfill: idempotent; uncosted list correct | P1 |
| D-8 | No behavior change: existing suites still green | P1 |
| D-9 | Margin math: 10,000 − 6,000 − 200 = 3,800 | P2 |
| D-10 | `cost_unknown` exclusion (pre-migration 0.00 cost) vs post-migration zero-cost included | P2 |
| D-11 | Reversal lands in refund-processed quarter; original quarter untouched | P2 |
| D-12 | Negative member CM: excluded from W_q, drags M_q | P2 |
| D-13 | Staff orders excluded | P2 |
| D-14 | M_q/W_q aggregation over mixed fixture | P2 |
| D-15 | Provisional projection reproduces PDF worked example (§3.2) | P2 |
| D-16 | Window determinism (same window → same result) | P2 |
| D-17 | Report page/CSV gated by flag; CSV headers include "Provisional — not payable" | P2 |
| D-18 | Monthly cron writes log line; failure non-fatal | P2 |
| D-19..22 | (reserved: window edge cases — quarter boundaries, midnight, DST-free PH) | P2 |
| D-23 | Close: status open→closed; contributions+reversals+allocations rows written; sums reconcile to P2 derivation | P3 |
| D-24 | Close idempotence: second close refused | P3 |
| D-25 | Concurrency: two closes → one wins (FOR UPDATE) | P3 |
| D-26 | Caps frozen at close (change settings after close → period math unchanged) | P3 |
| D-27 | Allocation rounding: Σ B_i = P_q exactly (D7 remainder rule) | P3 |
| D-28 | Suspended member → allocation `adjusted`, excluded from pay set | P3 |
| D-29 | Snapshot JSON completeness (inputs, members, exclusions, settings-at-close) | P3 |
| D-30 | Approve: only `super` role passes route; model refuses non-closed | P3 |
| D-31 | Void: pre-approval only; period re-closable | P3 |
| D-32 | Events trail: seeded→closed→approved with actors | P3 |
| D-33 | A_q/C_q NULL or ≤ 0 → pool 0, allocations zeroed | P3 |
| D-34 | Settings tab: values persist; per-tab isolation (existing pattern) | P3 |
| D-35 | Pay: wallets credited withdrawable; commissions + ledger rows 1:1; allocation `paid` | P4 |
| D-36 | Pay: no CapEngine interaction (`lifetime_earned` unchanged, cap never triggered by pool) | P4 |
| D-37 | Pay: partial failure continues; re-run pays remainder; period `paid` only when complete | P4 |
| D-38 | Drift call-site scan extended to `core/*.php` finds `performance_pool` legal | P4 |
| D-39 | Member page: states render; provisional labeled; drill-down rows visible | P4 |
| D-40 | E2e: order→complete→refund→close→approve→pay→payout-request succeeds | P4 |
| D-41 | Earnings filter + summary row for `performance_pool` | P4 |
| D-42 | API shape (`api_performance_summary`) stable | P4 |
| D-43 | Flag off: no member surface (sidebar/dashboard/earnings/page) | P4 |
| D-44 | Copy lint: forbidden strings absent on performance surfaces | P4 |
| D-45..46 | (reserved: widget render, pagination) | P4 |
| D-47 | Packages UI round-trips `performance_enabled`; `plans()` unchanged (regression) | P5 |
| D-48 | Flag-on/off × old/new frontend copy sync matrix | P5 |
| D-49 | Copy lint over `frontend/` + `views/` (tier-scoped DFI wording allowed) | P5 |
| D-50 | Inactive tiers refuse registration/upgrade (existing guards hold) | P5 |
| D-51..53 | (reserved: registration copy, free-partner path, modal row) | P5 |
| D-54 | Monitor page reconciles with report numbers | P6 |
| D-55 | Duplicate-address detector flags fixture; excludable pre-approval | P6 |
| D-56 | Adjustment lands in open period; visible in member provisional | P6 |
| D-57 | Adjustment refuses to touch closed/approved/paid periods | P6 |
| D-58 | Velocity detector sanity | P6 |
| D-59 | predeploy flag-consistency check | P6 |

## Appendix E — Risk register

| # | Risk | L | I | Mitigation | Phase |
|---|---|---|---|---|---|
| E-1 | **Cost data missing or wrong** → margins inflated → pool overpays | M | H | D16 conservative exclusion; A-4 sheet sign-off; uncosted list visible in report & monitor | P0/P1 |
| E-2 | **A_q/C_q mis-entered** → pool exceeds real economics | L | H | Manual entry by finance only; snapshot freezes inputs; approve step reviews; D6 | P3 |
| E-3 | **Recruitment overwhelms retail** (pool gamed via many small self-orders) | M | M | Attribution is placing-member only (no sponsor pay); concentration monitor (P6 W-1); duplicate-address flags; PDF p7 risk row | P2/P6 |
| E-4 | **Refund after payment** claws back margin already paid | M | M | Reversals land in the *current* quarter (D4); pool pays ≤ 20% of margin so exposure ≤ the margin of the refunded orders; year-end adjustment flow for true-ups | P2/P6 |
| E-5 | **Legal reclassification** (pool treated as a security under RA 8799; Art. 53 chain-distribution exposure) | L | H | D18 gates all public copy; counsel review; performance-based design (no passive profit share, no recruitment pay); income disclosures | P0/P5 |
| E-6 | **Member expectations** ("where's my daily bonus?") | M | M | Four-state dashboard; provisional labeling; launch communication; DFI legacy continuity during pilot | P4/P5/P7 |
| E-7 | **Cap bypass deviation** (D8) draws scrutiny vs legacy systems | L | M | Documented rationale (pool self-capped at source); risk accepted in decision register; revisit at sunset review | P4/P7 |
| E-8 | **Concurrent closes / double pay** | L | H | FOR UPDATE serialization; status asserts; unique keys; per-member pay transactions | P3/P4 |
| E-9 | **SLA drift** (close/approve/pay targets missed) | M | M | SLA clocks on admin pages; `P_q=0` fallback is legitimate and communicated; ops runbook | P7 |
| E-10 | **Snapshot/ledger divergence** (audit failure) | L | H | Single-writer engine; D-23/D-29/D-40 reconciliation assertions on every close/pay | P3/P4 |
| E-11 | **Frontend/flag desync** (copy promises plan while flag off, or vice versa) | M | M | D-48 sync matrix test; single-release switch procedure (P5 W-6) | P5 |
| E-12 | **Cost snapshot drift** (catalog cost edited after orders placed) | M | L | Snapshots at order time (D5); historical stability by construction | P1 |

## Appendix F — Launch checklist & quarterly calendar

**Go-live gates:** L-1…L-8 (§13.2), all evidenced and filed. Flag flip procedure: deploy P5 release → verify D-48 matrix on staging → flip `performance_plan_enabled` → smoke: report page, member page, dashboard widget, registration copy → announce.

**Recurring calendar (Asia/Manila):**

| When | Who | What |
|---|---|---|
| 15th monthly | Ops | Monthly report generated + filed (provisional); log line written |
| Quarter-end +30d | Finance → admin | Finance delivers A_q/C_q; admin closes period (wizard) |
| +45d | Superadmin | Reviews snapshot; approves or withholds (with documented reason) |
| +60d | Admin | Executes pay; members notified; payout requests flow through existing rails |
| Year-end | Accountant | Annual reconciliation; adjustments land in the open period; caps & reserve reviewed |

**First cycle (pilot):** 2026Q4 closes by Jan 30, 2027; approves by Feb 14; pays by Mar 1. Four-quarter validation through 2027Q3; sunset decision at the 2027Q3 review (§13.4).

## Appendix G — Open items & follow-ups

| # | Item | Needs | Owner | Due |
|---|---|---|---|---|
| G-1 | Definitions memo for `A_q` / `C_q` under the adopted accounting policy (incl. bonus classification & tax treatment — PDF p4) | Qualified accountant | Finance | Before first close (Jan 2027) |
| G-2 | Reserve policy quantification (production cycle + 3–6 months fixed + emergency provision, validated against actual cash-conversion cycles — PDF p5) | Finance | Finance | 2026Q4 close |
| G-3 | Which legacy packages (if any) keep DFI through the pilot; member communication plan | Business decision | Owner | P5 sign-off |
| G-4 | Service-reward categories (contracted delivery, sales support): rate schedule, proof requirements, attribution rules | Written rules per PDF p4/p7 | Owner + counsel | After 4-quarter pilot |
| G-5 | Per-SKU fulfillment cost model (replaces flat per-order setting) | Ops cost data | Ops | Post-pilot |
| G-6 | Member statement export (per-quarter PDF-style calculation statement, PDF p6 "provide a calculation statement") | Design decision | Eng | P6/P7 backlog |
| G-7 | `performance_contributions.period_id` back-reference for adjustments that predate the next open period (edge: year-end correction filed before Q1 opens) | Design decision | Eng | Before year-end close |
| G-8 | Whether superadmin-only approval is workable given staffing (fallback: named second admin account with documented credential separation — weaker than D9, needs explicit risk acceptance) | Ops decision | Owner | P3 |
| G-9 | Seat-limit interplay: registration closes at `seat_limit` (index.php:254-268) — confirm the pilot's partner-growth target fits remaining seats, or plan a seat-limit revision (a business decision, not an engineering one) | Business decision | Owner | P5 |
| G-10 | Companion-plan revival: guest storefront/checkout work parked in `phased_implementation_plan.md` — schedule as its own cycle after the pilot stabilizes | Planning decision | Owner | Post-pilot |
