# 🧪 Phase 6 QA Testing Guide — Admin Monitoring, Reporting & Final Integration

> **Prerequisites:** Complete Phases 1-5 testing first. Ensure at least one member is `capped`, one is `perminact`, one has completed reactivations, and DFI is enabled.

---

## Test 6.1 — Cap Monitor Page

**Goal:** Verify admin cap monitoring shows real-time cap status across members.

### 6.1.1 Load Cap Monitor
1. Log in as **admin**.
2. In the left sidebar, click **🛡️ Cap Monitor**.
3. **Expected:** Page loads with 3 stat cards at the top showing:
   - Active Members (green)
   - Capped Members (orange)
   - Permanently Inactive (red)

### 6.1.2 Verify Counts Match Database
1. In a new tab or terminal, run:
   ```sql
   SELECT cap_status, COUNT(*) FROM users WHERE role='member' GROUP BY cap_status;
   ```
2. **Expected:** The numbers shown on the stat cards match your database exactly.

### 6.1.3 Filter by Status
1. Click the **Active** filter badge at the top of the table.
2. **Expected:** Only members with `Active` cap status are shown. Page URL changes to `?status=active`.
3. Click **Capped** filter.
4. **Expected:** Only `Capped` members shown.
5. Click **Permanently Inactive** filter.
6. **Expected:** Only `Permanently Inactive` members shown.
7. Click **All** to reset.

### 6.1.4 Verify Lifetime Cap Column
1. Look at the **Lifetime Cap** column in the table.
2. For any member, verify: `Lifetime Cap` = `Package Entry Fee` × `Lifetime Cap Multiplier`.
3. Example: Package entry fee = ₱1,000, multiplier = 3.00 → Lifetime Cap should show ₱3,000.

### 6.1.5 Verify Lifetime Earned vs Cap
1. For a **Capped** member in the table:
   - **Lifetime Earned** should equal or exceed the **Lifetime Cap**.
   - Cap status should show an orange badge.
2. For a **Permanently Inactive** member:
   - Cap status should show a red badge.

---

## Test 6.2 — Admin Dashboard New Stats

**Goal:** Verify 4 new stat cards appear on the admin dashboard with correct data.

### 6.2.1 Load Dashboard
1. As admin, click **Dashboard** in the sidebar.
2. **Expected:** You see 8 stat cards in total:
   - Row 1: Members, Codes, Packages, Payouts (existing)
   - Row 2: Capped Members, Permanently Inactive, Reactivation Revenue, DFI Paid Today (new)

### 6.2.2 Verify Capped Members Card
1. Look at the **Capped Members** card (orange icon).
2. Run this SQL to verify:
   ```sql
   SELECT COUNT(*) FROM users WHERE role='member' AND cap_status='capped';
   ```
3. **Expected:** Card number matches SQL result.

### 6.2.3 Verify Permanently Inactive Card
1. Look at the **Permanently Inactive** card (red icon).
2. Run this SQL to verify:
   ```sql
   SELECT COUNT(*) FROM users WHERE role='member' AND cap_status='perminact';
   ```
3. **Expected:** Card number matches SQL result.

### 6.2.4 Verify Reactivation Revenue Card
1. Look at the **Reactivation Revenue** card (purple icon).
2. Run this SQL to verify:
   ```sql
   SELECT IFNULL(SUM(amount_paid), 0) FROM reactivations WHERE status='completed';
   ```
3. **Expected:** Card amount matches SQL result, formatted as currency.

### 6.2.5 Verify DFI Paid Today Card
1. Look at the **DFI Paid Today** card (blue icon).
2. Run this SQL to verify:
   ```sql
   SELECT IFNULL(SUM(amount), 0) FROM commissions 
   WHERE type='daily_fixed_income' AND DATE(created_at)=CURDATE();
   ```
3. **Expected:** Card amount matches SQL result, formatted as currency.

---

## Test 6.3 — User View Cap & DFI Tab

**Goal:** Verify the new 4th tab on member detail shows cap and DFI data.

### 6.3.1 Navigate to Member Detail
1. As admin, go to **Members** → click any member's **View** button.
2. **Expected:** You see 4 tabs: 💰 Commissions, 📒 E-Wallet Ledger, 💳 Payouts, 🛡️ Cap & DFI.

### 6.3.2 View Cap & DFI Tab
1. Click the **🛡️ Cap & DFI** tab.
2. **Expected:** The tab shows 4 sections:
   - **Lifetime Cap Progress** — progress bar showing `lifetime_earned` / `lifetime_cap`
   - **DFI Status** — days used / total days, daily rate, total DFI earned
   - **Reactivation History** — table with date, fee, method, status
   - **Cap-Triggered Commissions** — table showing commissions that were reduced by the cap

### 6.3.3 Verify Lifetime Cap Progress Bar
1. For a member with package entry fee ₱1,000 and multiplier 3:
   - If earned ₱0, the bar should be at 0% (gray).
   - If earned ₱1,500, the bar should be at 50% (green).
   - If earned ₱3,000+, the bar should be at 100% (red/orange) with "Capped" label.

### 6.3.4 Verify Reactivation History Table
1. For a member who has reactivated:
   - The table should show each reactivation with Requested Date, Fee, Method, Status.
   - Status should be a colored badge (Pending = yellow, Completed = green, Rejected = red).
2. For a member who never reactivated:
   - **Expected:** Table shows "No reactivation history found."

### 6.3.5 Verify Cap-Triggered Commissions
1. For a member who has been capped:
   - The table should show commissions with `cap_deduction > 0`.
   - Each row shows: Date, Type, Original Amount, Deduction, Final Amount.
2. For a member who was never capped:
   - **Expected:** Table shows "No cap-triggered commissions."

---

## Test 6.4 — Settings Compensation Plan Section

**Goal:** Verify new compensation plan settings are editable and persisted.

### 6.4.1 Load Settings Page
1. As admin, go to **Settings**.
2. Scroll to the bottom.
3. **Expected:** A section titled **📋 Compensation Plan** appears with:
   - Default Cap Multiplier input
   - Reactivation Payment Methods dropdown

### 6.4.2 Change Default Cap Multiplier
1. Change the **Default Cap Multiplier** from `3.00` to `4.00`.
2. Click **Save Settings**.
3. Reload the page.
4. **Expected:** The multiplier input now shows `4.00`.

### 6.4.3 Change Reactivation Payment Methods
1. Change the **Reactivation Payment Methods** dropdown to **E-Wallet Only**.
2. Click **Save Settings**.
3. Reload the page.
4. **Expected:** Dropdown now shows **E-Wallet Only** selected.

### 6.4.4 Verify Persistence in Database
1. Run:
   ```sql
   SELECT `key_name`, `value` FROM settings WHERE `key_name` IN ('default_cap_multiplier', 'reactivation_methods');
   ```
2. **Expected:** Values match what you saved in the UI.

---

## Test 6.5 — End-to-End Integration

**Goal:** Verify all Phase 6 features work together in a realistic scenario.

### 6.5.1 Scenario: Capped Member → Reactivation → Cap Monitor Update
1. Find or create a member who is **capped** (lifetime earned ≥ lifetime cap).
2. Note the **Capped Members** count on the admin dashboard.
3. As that member, request a reactivation using e-wallet.
4. As admin, confirm the reactivation in **Reactivations**.
5. Go to **Cap Monitor**.
6. **Expected:**
   - The member's cap status now shows **Active** (not Capped).
   - The **Capped Members** count on the dashboard decreased by 1.
   - The **Reactivation Revenue** card increased by the fee amount.
   - The member's **Cap & DFI** tab now shows a green progress bar (earned < cap).

### 6.5.2 Scenario: DFI Today Card Updates
1. Note the **DFI Paid Today** amount on the admin dashboard.
2. Run the DFI cron (or wait until midnight reset):
   ```bash
   php cron/midnight_reset.php
   ```
3. Reload the admin dashboard.
4. **Expected:** The **DFI Paid Today** card shows the total DFI paid in this run.

---

## Regression Tests

Run these to ensure existing features still work:

### R6.1 Member Registration
- Register a new member.
- **Expected:** Member gets `cap_status='active'`, `lifetime_earned=0`.

### R6.2 Pairing with Cap
- As a capped member, trigger a pairing commission.
- **Expected:** Commission is blocked, `total_cap_blocked` increases.

### R6.3 DFI Payout
- As a member with DFI days remaining, run midnight reset.
- **Expected:** DFI commission is recorded, DFI days used increments.

### R6.4 Admin Reactivation Confirm/Reject
- As admin, confirm and reject reactivations.
- **Expected:** No database errors. Status updates correctly.

---

## Common Issues & Fixes

| Issue | Cause | Fix |
|-------|-------|-----|
| Cap Monitor shows 0 for all stats | No members in database | Register test members first |
| DFI Paid Today is 0 | DFI cron not run yet | Run `php cron/midnight_reset.php` |
| Reactivation Revenue is 0 | No completed reactivations | Complete a reactivation first |
| Cap & DFI tab is empty | Member has no cap/DFI history | Normal for new members |
| Settings not saving | Missing settings keys | `INSERT` in `saveSettings()` auto-creates them |

---

## Test Completion Checklist

- [ ] 6.1.1 — Cap Monitor page loads
- [ ] 6.1.2 — Stats match database
- [ ] 6.1.3 — Status filters work
- [ ] 6.1.4 — Lifetime Cap column correct
- [ ] 6.1.5 — Capped/perminact members shown correctly
- [ ] 6.2.1 — Dashboard shows 8 stat cards
- [ ] 6.2.2 — Capped Members card matches DB
- [ ] 6.2.3 — Permanently Inactive card matches DB
- [ ] 6.2.4 — Reactivation Revenue card matches DB
- [ ] 6.2.5 — DFI Paid Today card matches DB
- [ ] 6.3.1 — 4 tabs visible on member detail
- [ ] 6.3.2 — Cap & DFI tab shows all 4 sections
- [ ] 6.3.3 — Progress bar shows correct percentage
- [ ] 6.3.4 — Reactivation history table correct
- [ ] 6.3.5 — Cap-triggered commissions table correct
- [ ] 6.4.1 — Compensation Plan section visible
- [ ] 6.4.2 — Default Cap Multiplier saves
- [ ] 6.4.3 — Reactivation Methods saves
- [ ] 6.4.4 — Database values match UI
- [ ] 6.5.1 — Cap Monitor updates after reactivation
- [ ] 6.5.2 — DFI Today updates after cron
- [ ] R6.1 — New member gets active cap status
- [ ] R6.2 — Capped member pairing blocked
- [ ] R6.3 — DFI pays correctly
- [ ] R6.4 — Reactivation confirm/reject works

**All tests passing?** ✅ Phase 6 is complete! The lifetime capping system is fully operational.
