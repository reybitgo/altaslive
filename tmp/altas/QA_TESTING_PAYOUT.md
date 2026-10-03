# Payout Methods QA Testing Guide

**Version:** 1.3  
**Scope:** Full payout workflow for all three methods — GCash, Maya, USDT TRC20 · Service fees · Live USDT rate  
**Environment:** `http://localhost/altas/` · Database: `u938213108_altas_db`

> **v1.1** Added Maya + USDT TRC20 payout methods.  
> **v1.2** Added service fee per method, TRC20 gas fee, live USDT/PHP rate widget (CoinGecko), fee breakdown preview in request form, Net / USDT column in member history and admin payouts, exact USDT amount in admin send instruction box and Mark Complete modal.  
> **v1.3** TRC20 gas fee is now **dynamically fetched** from live APIs (TRON network params via `api.trongrid.io`, fallback to CoinGecko TRX price). Fetched value is persisted to the DB `settings.usdt_gas_fee` automatically in background. 15-min localStorage cache prevents API hammering. Admin Settings value updates without manual intervention.

---

## BEFORE YOU START

### Prerequisites

Before running any test, confirm the following:

| Check                    | How to verify                                          | Expected                                                                                                                                 |
| ------------------------ | ------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------- |
| DB migration ran         | phpMyAdmin → `payout_requests` table → check columns   | `payout_method`, `payout_account`, `service_fee_pct`, `service_fee_amount`, `usdt_rate`, `usdt_gas_fee`, `usdt_amount` columns all exist |
| `users` table updated    | phpMyAdmin → `users` table → check columns             | `maya_number` and `usdt_address` columns exist                                                                                           |
| Service fee settings set | Admin → Settings → Service Fees card                   | GCash fee, Maya fee, USDT fee %, Gas fee configured                                                                                      |
| USDT rate API reachable  | Browser → open `/?page=payout` as member, select USDT  | "Live Rate" or cached rate appears in fee preview                                                                                        |
| Gas fee API reachable    | DevTools Network tab when USDT selected on payout page | Request to `api.trongrid.io` or `api.coingecko.com/...tron` is visible                                                                   |
| Laragon running          | Laragon UI                                             | Apache = green, MySQL = green                                                                                                            |
| Test member has balance  | Log in as `member1`, check dashboard                   | E-wallet shows > ₱500                                                                                                                    |

If the DB columns are missing, run `migrate_payout_methods.sql` in phpMyAdmin before continuing.

---

### Test Accounts

| Role        | Username  | Password     |
| ----------- | --------- | ------------ |
| Admin       | `admin`   | `Admin@1234` |
| Test Member | `member1` | `Test@1234`  |

### Test Payout Account Details

Use these fake but correctly formatted values:

| Method     | Test Value                           | Notes                                |
| ---------- | ------------------------------------ | ------------------------------------ |
| GCash      | `09171234567`                        | Standard PH mobile format            |
| Maya       | `09281234567`                        | Standard PH mobile format            |
| USDT TRC20 | `TN8dqFnGBcP8sYcKEkMvHrwJqZ6kLmX9pQ` | 34-char TRC20 format starting with T |

---

### Status Legend

```
[ ] — Not tested
[P] — PASS
[F] — FAIL  (write what happened in the Notes column)
[S] — SKIP
```

---

## SECTION 0 — ADMIN SETTINGS: SERVICE FEES

> **Run this section first** — fee settings affect all downstream tests.

### TC-S01 · Service Fees Card Displays in Admin Settings

| #   | Step                                           | Expected Result                                                                 | Status |
| --- | ---------------------------------------------- | ------------------------------------------------------------------------------- | ------ |
| 1   | Log in as admin, go to `/?page=admin_settings` | Settings page loads                                                             | [P]    |
| 2   | Find the **Service Fees** card                 | Card is visible                                                                 | [P]    |
| 3   | Check fields present                           | **GCash Fee (%)**, **Maya Fee (%)**, **USDT Fee (%)**, **TRC20 Gas Fee (USDT)** | [P]    |
| 4   | Check current values                           | GCash: 0, Maya: 0, USDT: 5, Gas: 2.50 (or as configured)                        | [P]    |

---

### TC-S02 · Save GCash Service Fee

| #   | Step                                                | Expected Result                                                 | Status |
| --- | --------------------------------------------------- | --------------------------------------------------------------- | ------ |
| 1   | Set **GCash Fee (%)** to `3`                        | —                                                               | [P]    |
| 2   | Click **💾 Save Settings**                          | Flash: **"Settings saved."**                                    | [P]    |
| 3   | Refresh page                                        | GCash Fee shows `3`                                             | [P]    |
| 4   | Go to member payout page, select GCash, enter `500` | Fee preview: "Service Fee (3%) −₱15.00" · "You Receive ₱485.00" | [P]    |
| 5   | Reset GCash fee back to `0` after testing           | —                                                               | [P]    |

---

### TC-S03 · Save Maya Service Fee

| #   | Step                                        | Expected Result                                                 | Status |
| --- | ------------------------------------------- | --------------------------------------------------------------- | ------ |
| 1   | Set **Maya Fee (%)** to `3`                 | —                                                               | [P]    |
| 2   | Save                                        | Flash: success                                                  | [P]    |
| 3   | Go to payout page, select Maya, enter `500` | Fee preview: "Service Fee (3%) −₱15.00" · "You Receive ₱485.00" | [P]    |
| 4   | Reset Maya fee back to `0` after testing    | —                                                               | [P]    |

---

### TC-S04 · Save USDT Service Fee and Gas Fee

| #   | Step                                                        | Expected Result                                                                    | Status |
| --- | ----------------------------------------------------------- | ---------------------------------------------------------------------------------- | ------ |
| 1   | Set **USDT Fee (%)** to `5` and **TRC20 Gas Fee** to `2.50` | —                                                                                  | [P]    |
| 2   | Save                                                        | Flash: success                                                                     | [P]    |
| 3   | Go to payout page, select USDT, enter `1000`                | Fee preview shows: Service Fee (5%) −₱50.00 · Gas Fee (2.50 USDT) · USDT to Wallet | [P]    |
| 4   | Check that gas fee is subtracted in USDT units (not PHP)    | The gas is shown as "2.50 USDT" with its PHP equivalent                            | [P]    |

---

### TC-S05 · Zero Fee Shows No Deduction

| #   | Step                                      | Expected Result                                                | Status |
| --- | ----------------------------------------- | -------------------------------------------------------------- | ------ |
| 1   | Set all fees to `0`                       | Save                                                           | [P]    |
| 2   | Select GCash, enter `500`                 | Fee preview row hidden or shows ₱500.00 net with no deductions | [P]    |
| 3   | Restore fees to desired production values | —                                                              | [P]    |

---

## SECTION 1 — PROFILE: SAVING PAYOUT ACCOUNTS

### TC-P01 · Profile Page Displays All Three Payout Fields

| #   | Step                                        | Expected Result                                                       | Status |
| --- | ------------------------------------------- | --------------------------------------------------------------------- | ------ |
| 1   | Log in as `member1`                         | Dashboard loads                                                       | [P]    |
| 2   | Click **Profile & Settings** in the sidebar | Profile page loads                                                    | [P]    |
| 3   | Scroll to the **Payout Information** card   | Card is visible                                                       | [P]    |
| 4   | Check the card has three fields             | **GCash Number**, **Maya Number**, **USDT TRC20 Address** all present | [P]    |
| 5   | Check GCash label                           | Shows GCash icon/label                                                | [P]    |
| 6   | Check Maya label                            | Shows Maya label with blue dot                                        | [P]    |
| 7   | Check USDT label                            | Shows ₮ symbol with green accent                                      | [P]    |
| 8   | Check USDT field                            | Has `font-mono` styling and placeholder `T...`                        | [P]    |
| 9   | Check USDT hint text                        | Shows "TRC20 addresses start with T and are 34 characters."           | [P]    |

---

### TC-P02 · Save GCash Number

| #   | Step                                   | Expected Result                            | Status |
| --- | -------------------------------------- | ------------------------------------------ | ------ |
| 1   | On Profile page, clear the GCash field | Field is empty                             | [P]    |
| 2   | Type `09171234567` in GCash field      | Number appears                             | [P]    |
| 3   | Leave Maya and USDT fields blank       | —                                          | [P]    |
| 4   | Click **💾 Save Changes**              | Flash: **"Profile updated successfully."** | [P]    |
| 5   | Refresh the page                       | GCash field shows `09171234567`            | [P]    |
| 6   | Maya and USDT fields are still blank   | Not overwritten                            | [P]    |

---

### TC-P03 · Save Maya Number

| #   | Step                                              | Expected Result                            | Status |
| --- | ------------------------------------------------- | ------------------------------------------ | ------ |
| 1   | On Profile page, type `09281234567` in Maya field | Number appears                             | [P]    |
| 2   | Leave GCash unchanged                             | Still shows `09171234567`                  | [P]    |
| 3   | Click **💾 Save Changes**                         | Flash: **"Profile updated successfully."** | [P]    |
| 4   | Refresh the page                                  | Maya field shows `09281234567`             | [P]    |
| 5   | GCash field still shows `09171234567`             | Not overwritten                            | [P]    |

---

### TC-P04 · Save USDT TRC20 Address

| #   | Step                                                                     | Expected Result                                                  | Status |
| --- | ------------------------------------------------------------------------ | ---------------------------------------------------------------- | ------ |
| 1   | On Profile page, type `TN8dqFnGBcP8sYcKEkMvHrwJqZ6kLmX9pQ` in USDT field | Address appears in monospace font                                | [P]    |
| 2   | Click **💾 Save Changes**                                                | Flash: **"Profile updated successfully."**                       | [P]    |
| 3   | Refresh the page                                                         | USDT field shows the full address                                | [P]    |
| 4   | Verify all three fields are now saved                                    | GCash: `09171234567` · Maya: `09281234567` · USDT: `TN8d...X9pQ` | [P]    |

---

### TC-P05 · Save All Three at Once

| #   | Step                                                                                  | Expected Result                            | Status |
| --- | ------------------------------------------------------------------------------------- | ------------------------------------------ | ------ |
| 1   | Clear all three payout fields                                                         | All blank                                  | [P]    |
| 2   | Enter all three simultaneously: GCash `09171234567`, Maya `09281234567`, USDT address | —                                          | [P]    |
| 3   | Click **💾 Save Changes**                                                             | Flash: **"Profile updated successfully."** | [P]    |
| 4   | Refresh page                                                                          | All three fields populated correctly       | [P]    |

---

### TC-P06 · Partial Save Does Not Wipe Other Methods

**What we are testing:** Saving only one method does not clear the others.

| #   | Step                                         | Expected Result                       | Status |
| --- | -------------------------------------------- | ------------------------------------- | ------ |
| 1   | Ensure all three are saved from TC-P05       | —                                     | [P]    |
| 2   | Change ONLY the GCash field to `09170000000` | —                                     | [P]    |
| 3   | Click **💾 Save Changes**                    | Flash: success                        | [P]    |
| 4   | Refresh page                                 | GCash shows `09170000000`             | [P]    |
| 5   | Check Maya field                             | Still shows `09281234567` — unchanged | [P]    |
| 6   | Check USDT field                             | Still shows full address — unchanged  | [P]    |
| 7   | Change GCash back to `09171234567`           | Restored                              | [P]    |

---

## SECTION 2 — PAYOUT REQUEST: GCASH METHOD

### TC-G01 · GCash Method Button Displays Correctly

| #   | Step                                                      | Expected Result                                                        | Status |
| --- | --------------------------------------------------------- | ---------------------------------------------------------------------- | ------ |
| 1   | Ensure `member1` has GCash `09171234567` saved in profile | —                                                                      | [P]    |
| 2   | Go to `/?page=payout`                                     | Payout page loads                                                      | [P]    |
| 3   | Find the **Payout Method** selector                       | Three buttons visible: GCash, Maya, USDT TRC20                         | [P]    |
| 4   | Check GCash button styling                                | Blue accent, selected by default                                       | [P]    |
| 5   | Check GCash button shows masked account                   | Shows `0917****7567` (or similar masked format)                        | [P]    |
| 6   | Check the Account field below                             | Pre-filled with `09171234567`, labelled "GCash Number"                 | [P]    |
| 7   | Check placeholder text                                    | `09XXXXXXXXX`                                                          | [P]    |
| 8   | Enter amount `500` in the Amount field                    | Fee preview box appears below the amount field                         | [P]    |
| 9   | Check fee preview — if GCash fee is 0%                    | Shows "You Receive ₱500.00" with no deductions                         | [P]    |
| 10  | Set GCash service fee to 5% in Admin Settings, return     | Fee preview shows "Service Fee (5%) −₱25.00" and "You Receive ₱475.00" | [P]    |

---

### TC-G02 · GCash — Submit Payout Request

| #   | Step                                     | Expected Result                                                                   | Status |
| --- | ---------------------------------------- | --------------------------------------------------------------------------------- | ------ |
| 1   | On Payout page, ensure GCash is selected | Blue GCash button is active                                                       | [P]    |
| 2   | Note current balance                     | ₱**\_\_**                                                                         | [P]    |
| 3   | Enter amount `500`                       | Hint shows Max: ₱X                                                                | [P]    |
| 4   | Verify account field shows `09171234567` | —                                                                                 | [P]    |
| 5   | Click **Submit Payout Request**          | Flash: **"Payout request submitted. Admin will process it shortly."**             | [P]    |
| 6   | Check payout history table               | New row with Amount ₱500.00, Method badge **GCash** (blue), Account `09171234567` | [P]    |
| 7   | Check **Net / USDT** column for this row | If fee > 0: shows net PHP after fee + "Fee: ₱X" below · If fee = 0: shows ₱500.00 | [P]    |
| 8   | Check status badge                       | Shows **"Pending"** in yellow                                                     | [P]    |
| 9   | Check the request form area              | Replaced by pending request message                                               | [P]    |

---

### TC-G03 · GCash — Admin Approves

| #   | Step                                          | Expected Result                                                | Status |
| --- | --------------------------------------------- | -------------------------------------------------------------- | ------ |
| 1   | Log in as admin, go to `/?page=admin_payouts` | Payouts page loads                                             | [P]    |
| 2   | Find the pending GCash request for member1    | Row visible in Pending tab                                     | [P]    |
| 3   | Check **Requested (₱)** column                | Shows ₱500.00                                                  | [P]    |
| 4   | Check **Net / USDT** column                   | If fee = 0: ₱500.00 · If fee > 0: net amount + fee line        | [P]    |
| 5   | Check Method column                           | Shows **GCash** badge in blue                                  | [P]    |
| 6   | Check Account column                          | Shows `09171234567` with 📋 copy button                        | [P]    |
| 7   | Click **📋** copy button                      | Toast: "Copied: 09171234567"                                   | [P]    |
| 8   | Click **✓ Approve**                           | Confirmation modal opens                                       | [P]    |
| 9   | Read modal description                        | Mentions "GCash" and the phone number                          | [P]    |
| 10  | Click **✓ Approve** in modal                  | Flash: **"Payout approved."**                                  | [P]    |
| 11  | Click **✅ Approved** tab                     | Request appears here                                           | [P]    |
| 12  | Check the send instruction box                | Shows "Send via GCash to" + `09171234567` + **net PHP amount** | [P]    |
| 13  | If fee was 0%: net amount shown               | ₱500.00                                                        | [P]    |
| 14  | If fee was 5%: net + breakdown shown          | ₱475.00 · "From ₱500 · Fee ₱25.00"                             | [P]    |

---

### TC-G04 · GCash — Admin Marks Complete

| #   | Step                                                  | Expected Result                                                          | Status |
| --- | ----------------------------------------------------- | ------------------------------------------------------------------------ | ------ |
| 1   | Note member1's current balance (admin user view)      | ₱**\_\_**                                                                | [P]    |
| 2   | On approved request, click **✅ Mark Complete**       | Confirmation modal opens                                                 | [P]    |
| 3   | Read modal description                                | Shows net PHP amount (e.g. ₱475.00 if 5% fee) via GCash to `09171234567` | [P]    |
| 4   | Type note: `Sent via GCash`                           | —                                                                        | [P]    |
| 5   | Click **✅ Mark Complete** in modal                   | Flash: **"Payout marked as completed. E-wallet deducted."**              | [P]    |
| 6   | Click **💚 Completed** tab                            | Request now listed here                                                  | [P]    |
| 7   | Go to member1 user view                               | Balance is ₱500 less than before                                         | [P]    |
| 8   | Log in as member1, go to Payouts                      | Request shows **"Completed"** status                                     | [P]    |
| 9   | Method and Account columns show GCash / `09171234567` | Correct                                                                  | [P]    |

---

### TC-G05 · GCash — Admin Rejects

| #   | Step                                         | Expected Result                             | Status |
| --- | -------------------------------------------- | ------------------------------------------- | ------ |
| 1   | Submit a new GCash payout request as member1 | Pending created                             | [P]    |
| 2   | In admin, click **✕ Reject** on the request  | Modal opens                                 | [P]    |
| 3   | Type rejection reason: `Test rejection`      | —                                           | [P]    |
| 4   | Click **✕ Reject** in modal                  | Flash: **"Payout rejected."**               | [P]    |
| 5   | Click **❌ Rejected** tab                    | Request listed here with admin note         | [P]    |
| 6   | Check member1's balance                      | Unchanged — balance NOT deducted            | [P]    |
| 7   | Log in as member1, go to Payouts             | Status shows **"Rejected"** with admin note | [P]    |

---

## SECTION 3 — PAYOUT REQUEST: MAYA METHOD

### TC-M01 · Maya Method Button Displays Correctly

| #   | Step                                          | Expected Result                      | Status |
| --- | --------------------------------------------- | ------------------------------------ | ------ |
| 1   | Ensure `member1` has Maya `09281234567` saved | —                                    | [P]    |
| 2   | Go to `/?page=payout`                         | Payout page loads                    | [P]    |
| 3   | Find the Maya button                          | Visible and enabled (not greyed out) | [P]    |
| 4   | Check Maya button accent color                | Teal/blue (`#48b0db`)                | [P]    |
| 5   | Check Maya button shows masked number         | Shows `0928****4567`                 | [P]    |

---

### TC-M02 · Switching to Maya Method

| #   | Step                                      | Expected Result                           | Status |
| --- | ----------------------------------------- | ----------------------------------------- | ------ |
| 1   | On Payout page, click the **Maya** button | Maya button becomes active (teal accent)  | [P]    |
| 2   | Check account input label                 | Changes to **"Maya Number"**              | [P]    |
| 3   | Check account input value                 | Shows `09281234567` (from profile)        | [P]    |
| 4   | Check hint text                           | "Funds will be sent to this Maya number." | [P]    |
| 5   | Check GCash button                        | Becomes inactive                          | [P]    |

---

### TC-M03 · Maya — Submit Payout Request

| #   | Step                                     | Expected Result                                                  | Status |
| --- | ---------------------------------------- | ---------------------------------------------------------------- | ------ |
| 1   | Select **Maya** method                   | Maya button active                                               | [P]    |
| 2   | Note current balance                     | ₱**\_\_**                                                        | [P]    |
| 3   | Enter amount `500`                       | Hint shows max                                                   | [P]    |
| 4   | Verify account field shows `09281234567` | —                                                                | [P]    |
| 5   | Click **Submit Payout Request**          | Flash: success message                                           | [P]    |
| 6   | Check payout history table               | New row with Method badge **Maya** (teal), Account `09281234567` | [P]    |
| 7   | Status shows **"Pending"**               | —                                                                | [P]    |

---

### TC-M04 · Maya — Admin Approves and Completes

| #   | Step                                            | Expected Result                                             | Status |
| --- | ----------------------------------------------- | ----------------------------------------------------------- | ------ |
| 1   | In admin payouts, find the pending Maya request | —                                                           | [P]    |
| 2   | Check Method column                             | Shows **Maya** badge in teal                                | [P]    |
| 3   | Check Account column                            | Shows `09281234567`                                         | [P]    |
| 4   | Click **✓ Approve**                             | Modal opens with Maya mentioned                             | [P]    |
| 5   | Approve                                         | Flash: **"Payout approved."**                               | [P]    |
| 6   | Check send instruction box                      | Shows "Send via Maya to" with `09281234567`                 | [P]    |
| 7   | Instruction box accent color                    | Teal — matches Maya brand                                   | [P]    |
| 8   | Click **✅ Mark Complete**                      | Modal confirms Maya + number                                | [P]    |
| 9   | Complete with note `Sent via Maya`              | Flash: **"Payout marked as completed. E-wallet deducted."** | [P]    |
| 10  | Check member1 balance                           | Deducted ₱500                                               | [P]    |
| 11  | Check ledger entry (admin user view)            | Note reads "Sent via Maya"                                  | [P]    |

---

## SECTION 4 — PAYOUT REQUEST: USDT TRC20 METHOD

### TC-U01 · USDT Method Button Displays Correctly

| #   | Step                                                                         | Expected Result                         | Status |
| --- | ---------------------------------------------------------------------------- | --------------------------------------- | ------ |
| 1   | Ensure `member1` has USDT address `TN8dqFnGBcP8sYcKEkMvHrwJqZ6kLmX9pQ` saved | —                                       | [P]    |
| 2   | Go to `/?page=payout`                                                        | Payout page loads                       | [P]    |
| 3   | Find the **USDT TRC20** button                                               | Visible and enabled                     | [P]    |
| 4   | Check USDT button accent color                                               | Green (`#26a17b` — Tether green)        | [P]    |
| 5   | Check USDT button shows masked address                                       | Shows `TN8d****X9pQ` (first 4 + last 4) | [P]    |

---

### TC-U02 · Switching to USDT Method

| #   | Step                                            | Expected Result                                   | Status |
| --- | ----------------------------------------------- | ------------------------------------------------- | ------ |
| 1   | On Payout page, click the **USDT TRC20** button | USDT button becomes active (green accent)         | [P]    |
| 2   | Check account input label                       | Changes to **"USDT TRC20 Address"**               | [P]    |
| 3   | Check account input value                       | Full address `TN8dqFnGBcP8sYcKEkMvHrwJqZ6kLmX9pQ` | [P]    |
| 4   | Check input styling                             | Monospace font (`font-mono`)                      | [P]    |
| 5   | Check hint text                                 | "USDT will be sent to this TRC20 wallet address." | [P]    |
| 6   | Check input type                                | `type="text"` — not `type="tel"`                  | [P]    |

---

### TC-U03 · USDT — Submit Payout Request

| #   | Step                                              | Expected Result                                                       | Status |
| --- | ------------------------------------------------- | --------------------------------------------------------------------- | ------ |
| 1   | Select **USDT TRC20** method                      | USDT button active                                                    | [P]    |
| 2   | Note current balance                              | ₱**\_\_**                                                             | [P]    |
| 3   | Enter amount `500`                                | Fee preview box appears                                               | [P]    |
| 4   | Check **Live Rate** in the fee preview            | Shows "₱XX.XX / USDT" — fetched from CoinGecko or cached              | [P]    |
| 5   | Check **Service Fee** row in preview              | Shows USDT fee % (e.g. 5%) and peso deduction                         | [P]    |
| 6   | Check **TRC20 Gas Fee** row in preview            | Shows gas USDT amount (e.g. 2.50 USDT) and peso equivalent            | [P]    |
| 7   | Check **You Receive** row                         | Shows USDT amount (e.g. `4.3218 USDT`)                                | [P]    |
| 8   | Check **USDT to Wallet** green box                | Displays the USDT amount in large font                                | [P]    |
| 9   | Verify account field shows the full TRC20 address | —                                                                     | [P]    |
| 10  | Click **Submit Payout Request**                   | Flash: success                                                        | [P]    |
| 11  | Check payout history table                        | New row with Method badge **USDT TRC20** (green), full wallet address | [P]    |
| 12  | Check **Net / USDT** column for this row          | Shows USDT amount (e.g. `4.3218 USDT`) + "@ ₱XX.XX" rate below        | [P]    |

---

### TC-U04 · USDT — Admin Approves and Completes

| #   | Step                                            | Expected Result                                                    | Status |
| --- | ----------------------------------------------- | ------------------------------------------------------------------ | ------ |
| 1   | In admin payouts, find the pending USDT request | —                                                                  | [P]    |
| 2   | Check **Requested (₱)** column                  | Shows ₱500.00                                                      | [P]    |
| 3   | Check **Net / USDT** column                     | Shows USDT amount in green (e.g. `4.3218 USDT`) + rate below       | [P]    |
| 4   | Check fee breakdown line in Net column          | "Fee ₱25.00 · Gas 2.50 USDT" shown below the USDT amount           | [P]    |
| 5   | Check Method column                             | Shows **USDT TRC20** badge in green                                | [P]    |
| 6   | Click **📋** copy on the wallet address         | Toast: "Copied: TN8dq..."                                          | [P]    |
| 7   | Click **✓ Approve**                             | Modal opens mentioning USDT TRC20                                  | [P]    |
| 8   | Approve                                         | Flash: **"Payout approved."**                                      | [P]    |
| 9   | Check send instruction box on Approved tab      | Shows **"Transfer USDT to"** with full wallet address              | [P]    |
| 10  | Check **exact USDT amount** in send box         | Shows `4.3218 USDT` in large green font (NOT the ₱500 PHP amount)  | [P]    |
| 11  | Check fee breakdown line in send box            | Shows "From ₱500.00 · Fee ₱25.00 · Gas 2.50 USDT"                  | [P]    |
| 12  | Instruction box accent color                    | Green (`#26a17b`)                                                  | [P]    |
| 13  | Click **✅ Mark Complete**                      | Modal opens — confirm description shows USDT amount not PHP amount | [P]    |
| 14  | Read modal: **"Confirm you've sent X USDT"**    | Shows `4.3218 USDT` — this is the exact amount to send on-chain    | [P]    |
| 15  | Complete with note `USDT transferred on chain`  | Flash: **"Payout marked as completed. E-wallet deducted."**        | [P]    |
| 16  | Check member1 balance                           | Deducted ₱500 (full requested amount deducted, not net)            | [P]    |
| 17  | Check ledger entry                              | Note reads "USDT transferred on chain"                             | [P]    |

---

## SECTION 5 — METHOD SELECTOR EDGE CASES

### TC-E01 · Greyed Out When No Account Saved

| #   | Step                                                  | Expected Result                                                       | Status |
| --- | ----------------------------------------------------- | --------------------------------------------------------------------- | ------ |
| 1   | On Profile page, clear the Maya number field and save | Flash: success                                                        | [P]    |
| 2   | Go to Payout page                                     | —                                                                     | [P]    |
| 3   | Check the Maya button                                 | Appears greyed out — not clickable                                    | [P]    |
| 4   | Hover over the greyed Maya button                     | Tooltip: "No account saved for this method — set it in Profile first" | [P]    |
| 5   | Try clicking it                                       | Nothing happens                                                       | [P]    |
| 6   | Go back to Profile, re-save Maya number               | —                                                                     | [P]    |
| 7   | Return to Payout page                                 | Maya button is now active                                             | [P]    |

---

### TC-E02 · Switching Methods Updates All Fields

| #   | Step                                              | Expected Result                                             | Status |
| --- | ------------------------------------------------- | ----------------------------------------------------------- | ------ |
| 1   | Click **GCash**                                   | Label: "GCash Number", value: `09171234567`                 | [P]    |
| 2   | Click **Maya**                                    | Label: "Maya Number", value: `09281234567`                  | [P]    |
| 3   | Click **USDT TRC20**                              | Label: "USDT TRC20 Address", monospace font, wallet address | [P]    |
| 4   | Click back to **GCash**                           | Returns to GCash Number + `09171234567`                     | [P]    |
| 5   | No previous method value persists after switching | [ ]                                                         | [P]    |

---

### TC-E03 · Amount Validation Works on All Methods

| #   | Step                                          | Expected Result                   | Status |
| --- | --------------------------------------------- | --------------------------------- | ------ |
| 1   | Select GCash, type `100`                      | Hint: "Minimum is ₱500.00" in red | [P]    |
| 2   | Switch to Maya, type `100`                    | Same validation                   | [P]    |
| 3   | Switch to USDT, type amount exceeding balance | Hint: "Exceeds balance" in red    | [P]    |
| 4   | Type valid amount on any method               | Normal hint text                  | [P]    |

---

### TC-E04 · Duplicate Request Prevention Applies to All Methods

| #   | Step                                          | Expected Result                                         | Status |
| --- | --------------------------------------------- | ------------------------------------------------------- | ------ |
| 1   | Submit a GCash payout request (leave pending) | Request created                                         | [P]    |
| 2   | Switch to Maya and try to submit              | Error: **"You already have a pending payout request."** | [P]    |
| 3   | Switch to USDT and try to submit              | Same error                                              | [P]    |
| 4   | Admin rejects the pending request             | Member can submit again                                 | [P]    |
| 5   | Submit a Maya request                         | Succeeds                                                | [P]    |

---

### TC-E05 · Manual Account Override (One-Off Payout)

| #   | Step                                                  | Expected Result                       | Status |
| --- | ----------------------------------------------------- | ------------------------------------- | ------ |
| 1   | Select GCash, clear account field, type `09990000000` | —                                     | [P]    |
| 2   | Submit the request                                    | Succeeds                              | [P]    |
| 3   | Check payout history                                  | Shows `09990000000`                   | [P]    |
| 4   | Check profile GCash field                             | Still `09171234567` — NOT overwritten | [P]    |

---

## SECTION 6 — ADMIN PAYOUTS VIEW

### TC-A01 · Method and Account Columns Present

| #   | Step                                     | Expected Result                                                                           | Status |
| --- | ---------------------------------------- | ----------------------------------------------------------------------------------------- | ------ |
| 1   | Admin → `/?page=admin_payouts` → All tab | —                                                                                         | [P]    |
| 2   | Check table columns                      | #, Member, **Requested (₱)**, **Net / USDT**, Method, Account, Requested, Status, Actions | [P]    |
| 3   | GCash rows                               | Blue **GCash** badge                                                                      | [P]    |
| 4   | Maya rows                                | Teal **Maya** badge                                                                       | [P]    |
| 5   | USDT rows                                | Green **USDT TRC20** badge                                                                | [P]    |
| 6   | 📋 copy on each row                      | Copies correct account to clipboard                                                       | [P]    |

---

### TC-A02 · Correct Send Instructions Per Method

| #   | Step                     | Expected Result                                                                        | Status |
| --- | ------------------------ | -------------------------------------------------------------------------------------- | ------ |
| 1   | GCash approved row       | "Send via GCash to" + `09XXXXXXXXX` + **net PHP amount** (e.g. ₱475.00 if 5% fee)      | [P]    |
| 2   | Maya approved row        | "Send via Maya to" + `09XXXXXXXXX` + **net PHP amount**                                | [P]    |
| 3   | USDT approved row        | **"Transfer USDT to"** + wallet address + **exact USDT in large font** + fee breakdown | [P]    |
| 4   | USDT Mark Complete modal | Shows "Confirm you've sent X.XXXX USDT" — not the PHP amount                           | [P]    |

---

### TC-A03 · Tab Filtering Still Works

| #   | Step          | Expected Result                 | Status |
| --- | ------------- | ------------------------------- | ------ |
| 1   | Pending tab   | Only pending requests           | [P]    |
| 2   | Approved tab  | Only approved + method badges   | [P]    |
| 3   | Completed tab | Only completed + method badges  | [P]    |
| 4   | Rejected tab  | Only rejected                   | [P]    |
| 5   | All tab       | All requests with method badges | [P]    |

---

## SECTION 7 — DATABASE INTEGRITY

### TC-D01 · Verify DB Records via phpMyAdmin

| #   | Check                                     | How to verify                     | Expected                                                                               |
| --- | ----------------------------------------- | --------------------------------- | -------------------------------------------------------------------------------------- |
| 1   | `payout_requests` — payout_method column  | Browse rows                       | Values: 'gcash', 'maya', or 'usdt'                                                     |
| 2   | `payout_requests` — payout_account column | Browse rows                       | Contains correct account per row                                                       |
| 3   | GCash requests                            | Filter by `payout_method='gcash'` | `payout_account` = phone number                                                        |
| 4   | Maya requests                             | Filter by `payout_method='maya'`  | `payout_account` = Maya number                                                         |
| 5   | USDT requests                             | Filter by `payout_method='usdt'`  | `payout_account` starts with T, 34 chars                                               |
| 6   | `payout_requests` — service_fee_pct       | Browse rows                       | Matches the service fee % set in admin settings at time of request                     |
| 7   | `payout_requests` — service_fee_amount    | Browse rows                       | = amount × fee% / 100                                                                  |
| 8   | `payout_requests` — usdt_rate             | Browse USDT rows                  | PHP per 1 USDT at time of request (e.g. 58.24)                                         |
| 9   | `payout_requests` — usdt_gas_fee          | Browse USDT rows                  | Gas fee in USDT at time of request (e.g. 2.50)                                         |
| 10  | `payout_requests` — usdt_amount           | Browse USDT rows                  | Exact USDT amount to send (net PHP after fee and gas, converted)                       |
| 11  | `ewallet_ledger` — completed payout notes | Browse rows                       | Note shows "Payout via GCASH/MAYA/USDT ..."                                            |
| 12  | `settings` table                          | Browse rows                       | `service_fee_gcash`, `service_fee_maya`, `service_fee_usdt`, `usdt_gas_fee` rows exist |
| 13  | `users` table                             | Check member1                     | `maya_number` and `usdt_address` columns populated                                     |

---

## SECTION 8 — MEMBER PAYOUT HISTORY

### TC-H01 · History Table Shows Method and Account

| #   | Step                                     | Expected Result                                                                           | Status |
| --- | ---------------------------------------- | ----------------------------------------------------------------------------------------- | ------ |
| 1   | Log in as member1, go to `/?page=payout` | —                                                                                         | [ ]    |
| 2   | Check history table columns              | Requested · Requested (₱) · **Net / USDT** · Method · Account · Status · Processed · Note | [ ]    |
| 3   | GCash completed row — Net/USDT column    | Net PHP after fee (e.g. ₱475.00) + "Fee: ₱25.00" — or ₱500.00 if no fee                   | [ ]    |
| 4   | Maya completed row — Net/USDT column     | Same as GCash pattern                                                                     | [ ]    |
| 5   | USDT completed row — Net/USDT column     | USDT amount (e.g. `4.3218 USDT`) + "@ ₱XX.XX" rate line below                             | [ ]    |
| 6   | GCash completed row — Method badge       | Blue **GCash** badge                                                                      | [ ]    |
| 7   | Maya completed row — Method badge        | Teal **Maya** badge                                                                       | [ ]    |
| 8   | USDT completed row — Method badge        | Green **USDT TRC20** badge, full wallet address                                           | [ ]    |
| 9   | Admin note visible                       | Shows note entered by admin                                                               | [ ]    |

---

## SECTION 9 — LIVE USDT RATE & DYNAMIC GAS FEE

> Both the USDT/PHP rate and the TRC20 gas fee are now fetched live on every payout page load. They run in parallel and cache independently.

---

### TC-R01 · USDT/PHP Rate Fetched from CoinGecko on Page Load

| #   | Step                                     | Expected Result                                             | Status |
| --- | ---------------------------------------- | ----------------------------------------------------------- | ------ |
| 1   | Log in as member1, go to `/?page=payout` | —                                                           | [ ]    |
| 2   | Select **USDT TRC20** method             | —                                                           | [ ]    |
| 3   | Enter any amount (e.g. `500`)            | Fee preview appears                                         | [ ]    |
| 4   | Check the rate line in fee preview       | Shows "@ ₱XX.XX / USDT" — realistic rate (typically ₱55–65) | [ ]    |
| 5   | Open browser DevTools → Network tab      | Request to `api.coingecko.com` (ids=tether) visible         | [ ]    |

---

### TC-R02 · USDT/PHP Rate Cached for 5 Minutes

| #   | Step                                           | Expected Result                                                    | Status |
| --- | ---------------------------------------------- | ------------------------------------------------------------------ | ------ |
| 1   | Note the current rate shown in the fee preview | ₱**\_\_** per USDT                                                 | [ ]    |
| 2   | Refresh the page within 5 minutes              | Same rate shown — no new CoinGecko request                         | [ ]    |
| 3   | Check DevTools → Network tab                   | No new request to coingecko.com on refresh                         | [ ]    |
| 4   | DevTools → Application → Local Storage         | `usdt_rate_cache` key exists with `rate` + `ts` (timestamp) fields | [ ]    |

---

### TC-R03 · Rate Locked at Request Submission

| #   | Step                                           | Expected Result                                     | Status |
| --- | ---------------------------------------------- | --------------------------------------------------- | ------ |
| 1   | Select USDT, note the rate shown (e.g. ₱58.24) | ₱**\_\_**                                           | [ ]    |
| 2   | Submit the payout request                      | Success                                             | [ ]    |
| 3   | phpMyAdmin → `payout_requests` row             | `usdt_rate` column = rate shown at submission       | [ ]    |
| 4   | Rate is immutable after submission             | Future rate changes do NOT alter historical records | [ ]    |

---

### TC-R04 · TRC20 Gas Fee Fetched from TRON Network (Strategy 1)

| #   | Step                                                                   | Expected Result                                      | Status |
| --- | ---------------------------------------------------------------------- | ---------------------------------------------------- | ------ |
| 1   | Open DevTools → Network tab                                            | —                                                    | [ ]    |
| 2   | Go to payout page, select USDT method                                  | —                                                    | [ ]    |
| 3   | Look for a POST request to `api.trongrid.io/wallet/getchainparameters` | Request fired within seconds of page load            | [ ]    |
| 4   | Check browser console                                                  | Log: `Gas fee from TRON network params: X.XXXX USDT` | [ ]    |
| 5   | Check gas fee shown in fee preview                                     | Shows the live-derived amount (typically 1.5–3 USDT) | [ ]    |

---

### TC-R05 · TRC20 Gas Fee Fallback to CoinGecko TRX Price (Strategy 2)

| #   | Step                                                   | Expected Result                                                | Status |
| --- | ------------------------------------------------------ | -------------------------------------------------------------- | ------ |
| 1   | In DevTools → Network, block `api.trongrid.io`         | —                                                              | [ ]    |
| 2   | Clear localStorage key `usdt_gas_fee_cache`            | —                                                              | [ ]    |
| 3   | Refresh payout page, select USDT                       | —                                                              | [ ]    |
| 4   | Watch DevTools Network tab                             | Request to `api.coingecko.com` (ids=tron) is made instead      | [ ]    |
| 5   | Check console                                          | Log: `Gas fee calculated via CoinGecko TRX price: X.XXXX USDT` | [ ]    |
| 6   | Fee preview updates with the CoinGecko-derived gas fee | —                                                              | [ ]    |
| 7   | Unblock `api.trongrid.io`                              | —                                                              | [ ]    |

---

### TC-R06 · Gas Fee Cached for 15 Minutes

| #   | Step                                             | Expected Result                                          | Status |
| --- | ------------------------------------------------ | -------------------------------------------------------- | ------ |
| 1   | After a successful gas fee fetch, note the value | X.XXXX USDT                                              | [ ]    |
| 2   | Refresh the page within 15 minutes               | Same gas fee shown — no new TRON/CoinGecko request       | [ ]    |
| 3   | DevTools → Application → Local Storage           | `usdt_gas_fee_cache` key exists with `fee` + `ts` fields | [ ]    |
| 4   | After 15 minutes, refresh page                   | New API request fires and cache updates                  | [ ]    |

---

### TC-R07 · Gas Fee Auto-Synced to Database

> This is the key new behaviour — the fetched gas fee is persisted to `settings.usdt_gas_fee` automatically.

| #   | Step                                                       | Expected Result                                                              | Status |
| --- | ---------------------------------------------------------- | ---------------------------------------------------------------------------- | ------ |
| 1   | In phpMyAdmin, note current `settings.usdt_gas_fee` value  | **\_\_**                                                                     | [ ]    |
| 2   | Clear localStorage `usdt_gas_fee_cache`                    | Forces a fresh API fetch on next load                                        | [ ]    |
| 3   | Log in as member, go to payout page, select USDT           | Gas fee API fires                                                            | [ ]    |
| 4   | Check browser console                                      | Log: `Gas fee synced to DB: X.XXXX USDT`                                     | [ ]    |
| 5   | Refresh phpMyAdmin → `settings` table → `usdt_gas_fee` row | Value updated to the live-fetched amount                                     | [ ]    |
| 6   | Go to Admin → Settings                                     | **TRC20 Gas Fee** field reflects the new live value                          | [ ]    |
| 7   | Check that DB was NOT updated if value unchanged           | Clear cache, reload — if API returns same fee, console shows no "synced" log | [ ]    |

---

### TC-R08 · Gas Fee DB Sync — Value Bounds Validation

| #   | Step                                                        | Expected Result                                               | Status |
| --- | ----------------------------------------------------------- | ------------------------------------------------------------- | ------ |
| 1   | Manually POST to `/?page=update_usdt_gas` with `{"fee": 0}` | Response: `{"ok":false,"error":"Invalid fee value."}`         | [ ]    |
| 2   | POST with `{"fee": 100}` (above 50 USDT cap)                | Response: `{"ok":false,"error":"Invalid fee value."}`         | [ ]    |
| 3   | POST with same value currently in DB                        | Response: `{"ok":true,"updated":false}` (no DB write)         | [ ]    |
| 4   | POST with a valid new value (e.g. `{"fee": 1.8}`)           | Response: `{"ok":true,"updated":true,"fee":1.8}` + DB updated | [ ]    |

---

### TC-R09 · Both APIs Offline — Graceful Fallback

| #   | Step                                                             | Expected Result                                                                     | Status |
| --- | ---------------------------------------------------------------- | ----------------------------------------------------------------------------------- | ------ |
| 1   | Block both `api.trongrid.io` and `api.coingecko.com` in DevTools | —                                                                                   | [ ]    |
| 2   | Clear both localStorage cache keys                               | —                                                                                   | [ ]    |
| 3   | Refresh payout page, select USDT, enter amount                   | Fee preview still renders using last DB value                                       | [ ]    |
| 4   | Check console                                                    | Logs: `TRON API failed…` then `All APIs failed. Using persisted fallback fee: X.XX` | [ ]    |
| 5   | No crash or JS error thrown                                      | Page remains fully functional                                                       | [ ]    |
| 6   | `settings.usdt_gas_fee` in DB unchanged                          | Fallback does NOT overwrite DB with a null/zero value                               | [ ]    |
| 7   | Unblock both APIs                                                | —                                                                                   | [ ]    |

---

## FINAL QA CHECKLIST

| #   | Critical Function                                                | GCash | Maya | USDT |
| --- | ---------------------------------------------------------------- | ----- | ---- | ---- |
| 1   | Service fee % saved in admin settings                            | [ ]   | [ ]  | [ ]  |
| 2   | Fee preview widget shows correct deduction before submit         | [ ]   | [ ]  | [ ]  |
| 3   | USDT/PHP rate fetched from CoinGecko and cached (5 min)          | N/A   | N/A  | [ ]  |
| 4   | USDT/PHP rate locked to request row in DB (`usdt_rate` column)   | N/A   | N/A  | [ ]  |
| 5   | TRC20 gas fee fetched live from TRON network (Strategy 1)        | N/A   | N/A  | [ ]  |
| 6   | Gas fee fallback to CoinGecko TRX price when TRON API fails      | N/A   | N/A  | [ ]  |
| 7   | Gas fee cached locally for 15 minutes                            | N/A   | N/A  | [ ]  |
| 8   | Gas fee auto-persisted to `settings.usdt_gas_fee` when changed   | N/A   | N/A  | [ ]  |
| 9   | DB not updated when fetched gas fee equals current value         | N/A   | N/A  | [ ]  |
| 10  | Both APIs offline → graceful fallback, no crash, no DB overwrite | N/A   | N/A  | [ ]  |
| 11  | Account saved correctly in profile                               | [ ]   | [ ]  | [ ]  |
| 12  | Method button visible and styled correctly                       | [ ]   | [ ]  | [ ]  |
| 13  | Selecting method updates account field + label                   | [ ]   | [ ]  | [ ]  |
| 14  | Payout request submitted and fee/USDT amount saved to DB         | [ ]   | [ ]  | [ ]  |
| 15  | Net / USDT column correct in member payout history               | [ ]   | [ ]  | [ ]  |
| 16  | Admin Requested (₱) and Net / USDT columns correct               | [ ]   | [ ]  | [ ]  |
| 17  | Admin send box shows exact net PHP or exact USDT amount          | [ ]   | [ ]  | [ ]  |
| 18  | Mark Complete modal shows USDT amount (not PHP) for USDT payouts | N/A   | N/A  | [ ]  |
| 19  | Copy button copies correct account                               | [ ]   | [ ]  | [ ]  |
| 20  | Mark Complete deducts e-wallet correctly                         | [ ]   | [ ]  | [ ]  |
| 21  | Ledger note shows correct method                                 | [ ]   | [ ]  | [ ]  |
| 22  | Reject does NOT deduct balance                                   | [ ]   | [ ]  | [ ]  |
| 23  | Greyed button when no account saved                              | [ ]   | N/A  | N/A  |
| 24  | Duplicate request prevention works                               | [ ]   | [ ]  | [ ]  |

---

## BUG REPORT TEMPLATE

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
BUG REPORT
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Test Case:      TC-XXX
Payout Method:  GCash / Maya / USDT TRC20 / All
Severity:       Critical / High / Medium / Low
Date Found:
Found By:

Steps to Reproduce:
1.
2.
3.

Expected Result:

Actual Result:

Screenshot / phpMyAdmin data:

Status: Open / Fixed / Verified
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

| Severity     | Meaning                                                                        |
| ------------ | ------------------------------------------------------------------------------ |
| **Critical** | Wrong amount deducted, wrong account shown, balance not deducted on completion |
| **High**     | A method button fails, modal shows wrong method, copy button wrong value       |
| **Medium**   | Wrong colour badge, label doesn't switch, hint text wrong                      |
| **Low**      | Minor text, alignment, or masked format issue                                  |

---

_Payout Methods QA Guide — FarmBouy MLM System_  
_70+ test cases · 10 sections · Methods: GCash · Maya · USDT TRC20 · Service Fees · Live USDT Rate · Dynamic TRC20 Gas Fee_
