# QA Test Guide — USDT TRC20 & BEP20 Payout Integration

> **Target audience:** beginner QA tester  
> **Environment:** local Apache/MySQL stack (e.g. Laragon) at `http://localhost/altaslive/`  
> **Goal:** verify that both USDT TRC20 and USDT BEP20 payouts work, that the live-rate/gas-fetch logic in `views/member/payout.php` behaves correctly, and that nothing else breaks.

---

## Table of Contents

1. [Prerequisites](#1-prerequisites)
2. [Quick Reference — Pages Under Test](#2-quick-reference--pages-under-test)
3. [Test Data Setup](#3-test-data-setup)
4. [Test Case 1 — Admin Settings](#4-test-case-1--admin-settings)
5. [Test Case 2 — Member Profile](#5-test-case-2--member-profile)
6. [Test Case 3 — Member Payout Page (rate/gas fetch)](#6-test-case-3--member-payout-page-rategas-fetch)
7. [Test Case 4 — Submit Payout Requests](#7-test-case-4--submit-payout-requests)
8. [Test Case 5 — Admin Payout Processing](#8-test-case-5--admin-payout-processing)
9. [Test Case 6 — Admin User View](#9-test-case-6--admin-user-view)
10. [Test Case 7 — Reactivation (optional)](#10-test-case-7--reactivation-optional)
11. [Test Case 8 — Edge Cases](#11-test-case-8--edge-cases)
12. [Cleanup SQL](#12-cleanup-sql)
13. [Bug Report Template](#13-bug-report-template)

---

## 1. Prerequisites

- Site is running at `http://localhost/altaslive/`.
- You can log in as **admin** and as a **member**.
- You have access to the database (phpMyAdmin, HeidiSQL, or MySQL CLI).
- You can open **Browser DevTools** (`F12`) → **Network** and **Console** tabs.
- The latest `migrate_usdt_bep20.sql` has been run on the test database.

### Default credentials (if not changed)

- Admin: `admin` / `Admin@1234`
- Member: create one using the steps below.

---

## 2. Quick Reference — Pages Under Test

| Role | Page | URL |
|------|------|-----|
| Admin | System Settings | `http://localhost/altaslive/?page=admin_settings` |
| Admin | Payout Requests | `http://localhost/altaslive/?page=admin_payouts` |
| Admin | Member Detail | `http://localhost/altaslive/?page=admin_user_view&id=<USER_ID>` |
| Member | Profile | `http://localhost/altaslive/?page=profile` |
| Member | Payouts | `http://localhost/altaslive/?page=payout` |
| Member | Reactivate | `http://localhost/altaslive/?page=reactivate` (only when capped) |

---

## 3. Test Data Setup

Run these SQL commands **before** testing. Replace `<DB_NAME>` with your actual database name (e.g. `u938213108_altas_db`).

### 3.1 Make sure the admin account exists

```sql
SELECT id, username, role, status FROM users WHERE role = 'admin';
```

If no admin exists, insert one (password is `Admin@1234`):

```sql
INSERT INTO users (username, password_hash, role, status, full_name, email)
VALUES (
  'admin',
  '$2y$12$h3j0mO9NbtMyLg6EsC4M6eGy6buk0zanOgPmFBIgaI8V5/CUbaYqq',
  'admin',
  'active',
  'System Administrator',
  'admin@mlm.local'
)
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash);
```

### 3.2 Create a test member

```sql
INSERT INTO users (username, password_hash, role, status, full_name, email, mobile, ewallet_balance, withdrawable_balance)
VALUES (
  'qa_member',
  '$2y$12$h3j0mO9NbtMyLg6EsC4M6eGy6buk0zanOgPmFBIgaI8V5/CUbaYqq', -- Admin@1234
  'member',
  'active',
  'QA Tester',
  'qa@test.com',
  '09123456789',
  20000.00,
  20000.00
)
ON DUPLICATE KEY UPDATE ewallet_balance = 20000.00, withdrawable_balance = 20000.00, status = 'active';

-- Get the member id
SELECT id, username, ewallet_balance, withdrawable_balance FROM users WHERE username = 'qa_member';
```

> **Note:** write down the `id` returned by the last query. It is referred to as `<MEMBER_ID>` below.

### 3.3 Make sure a package exists

```sql
SELECT id, name, entry_fee FROM packages LIMIT 1;
```

If empty, insert the default package:

```sql
INSERT INTO packages (
  name, entry_fee, pairing_bonus, daily_pair_cap, direct_ref_bonus,
  lifetime_cap_multiplier, reactivation_fee, reactivation_window_days,
  daily_fixed_income, daily_fixed_income_days, status
) VALUES (
  'Starter', 10000.00, 2000.00, 3, 500.00,
  3.00, 10000.00, 15,
  100.00, 90, 'active'
);
```

### 3.4 Assign the package to the test member

```sql
UPDATE users SET package_id = (SELECT id FROM packages ORDER BY id ASC LIMIT 1) WHERE username = 'qa_member';
```

### 3.5 Set the minimum payout low enough for testing

```sql
INSERT INTO settings (key_name, value) VALUES ('min_payout', '500')
ON DUPLICATE KEY UPDATE value = '500';
```

### 3.6 Configure admin USDT addresses and fees

Run this once. It sets realistic test values:

```sql
INSERT INTO settings (key_name, value) VALUES
  ('usdt_trc20_address',      'TExampleTrc20Address123456789012345678'),
  ('usdt_bep20_address',      '0xExampleBep20Address123456789012345678901'),
  ('service_fee_usdt_trc20',  '5'),
  ('service_fee_usdt_bep20',  '3'),
  ('usdt_trc20_gas_fee',      '2.50'),
  ('usdt_bep20_gas_fee',      '0.05')
ON DUPLICATE KEY UPDATE value = VALUES(value);
```

### 3.7 Verify settings

```sql
SELECT key_name, value FROM settings
WHERE key_name IN (
  'usdt_trc20_address','usdt_bep20_address',
  'service_fee_usdt_trc20','service_fee_usdt_bep20',
  'usdt_trc20_gas_fee','usdt_bep20_gas_fee',
  'min_payout'
);
```

### 3.8 Verify member columns exist

```sql
SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'users'
  AND COLUMN_NAME IN ('usdt_trc20_address','usdt_bep20_address');
```

### 3.9 Verify payout request columns exist

```sql
SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'payout_requests'
  AND COLUMN_NAME LIKE 'usdt_%';
```

Expected columns:

- `payout_method`
- `usdt_trc20_rate`
- `usdt_trc20_gas_fee`
- `usdt_trc20_amount`
- `usdt_bep20_rate`
- `usdt_bep20_gas_fee`
- `usdt_bep20_amount`

---

## 4. Test Case 1 — Admin Settings

### 4.1 Open the page

Log in as **admin** and go to:

```
http://localhost/altaslive/?page=admin_settings
```

### 4.2 What to verify

Scroll to the **Payout Service Fees** and **Admin Payment Accounts** sections.

You should see:

- GCash %, Maya %, **TRC20 %**, **BEP20 %**
- **USDT TRC20 Network Gas Fee**
- **USDT BEP20 Network Gas Fee**
- **USDT TRC20 Address**
- **USDT BEP20 Address**

### 4.3 Action

1. Change **TRC20 %** to `6`.
2. Change **BEP20 %** to `2`.
3. Change **USDT TRC20 Network Gas Fee** to `2.00`.
4. Change **USDT BEP20 Network Gas Fee** to `0.10`.
5. Change **USDT TRC20 Address** to `TTestTrc20AddressChanged12345678901234`.
6. Change **USDT BEP20 Address** to `0xTestBep20AddressChanged123456789012345678901`.
7. Click **Save Settings**.

### 4.4 Expected result

- Green success flash: "Settings saved."
- After the page reloads, all six values above are still showing the new values.

### 4.5 Verification SQL

```sql
SELECT key_name, value FROM settings
WHERE key_name IN (
  'usdt_trc20_address','usdt_bep20_address',
  'service_fee_usdt_trc20','service_fee_usdt_bep20',
  'usdt_trc20_gas_fee','usdt_bep20_gas_fee'
);
```

Expected:

- `usdt_trc20_address` = `TTestTrc20AddressChanged12345678901234`
- `usdt_bep20_address` = `0xTestBep20AddressChanged123456789012345678901`
- `service_fee_usdt_trc20` = `6`
- `service_fee_usdt_bep20` = `2`
- `usdt_trc20_gas_fee` = `2.00`
- `usdt_bep20_gas_fee` = `0.10`

### 4.6 Reset to baseline (optional)

```sql
INSERT INTO settings (key_name, value) VALUES
  ('usdt_trc20_address',      'TExampleTrc20Address123456789012345678'),
  ('usdt_bep20_address',      '0xExampleBep20Address123456789012345678901'),
  ('service_fee_usdt_trc20',  '5'),
  ('service_fee_usdt_bep20',  '3'),
  ('usdt_trc20_gas_fee',      '2.50'),
  ('usdt_bep20_gas_fee',      '0.05')
ON DUPLICATE KEY UPDATE value = VALUES(value);
```

---

## 5. Test Case 2 — Member Profile

### 5.1 Log in as the test member

- Username: `qa_member`
- Password: `Admin@1234`

### 5.2 Open profile

```
http://localhost/altaslive/?page=profile
```

### 5.3 Action

In the **Payout Information** section:

1. Enter **USDT TRC20 Address**: `TQaMemberTrc20Address1234567890123456`
2. Enter **USDT BEP20 Address**: `0xQaMemberBep20Address12345678901234567890`
3. Click **Save Changes**.

### 5.4 Expected result

- Green success flash: "Profile updated successfully."
- Both address fields still show the saved values after reload.

### 5.5 Verification SQL

```sql
SELECT username, usdt_trc20_address, usdt_bep20_address
FROM users WHERE username = 'qa_member';
```

---

## 6. Test Case 3 — Member Payout Page (rate/gas fetch)

### 6.1 Open the page

```
http://localhost/altaslive/?page=payout
```

### 6.2 Initial UI check

You should see:

- Balance card showing `₱20,000.00` withdrawable.
- Four method buttons: **GCash**, **Maya**, **USDT TRC20**, **USDT BEP20**.
- The method that has a saved address should show a masked preview, e.g. `TQaM...3456`.
- The account label, placeholder, and hint change when you switch methods.

### 6.3 Test method switching

1. Click **GCash** → label should read "GCash Number", placeholder `09XXXXXXXXX`.
2. Click **USDT TRC20** → label "USDT TRC20 Address", placeholder `T... (34 characters)`.
3. Click **USDT BEP20** → label "USDT BEP20 Address", placeholder `0x... (42 characters)`.
4. The saved address should auto-fill when a method with a saved address is selected.

### 6.4 Test live USDT/PHP rate fetch (DevTools)

The rate fetch runs automatically when the payout page loads with a USDT method active **and** the rate has not been cached yet. Both **USDT TRC20** and **USDT BEP20** share the same USDT/PHP rate.

To guarantee you see the network request:

1. Open DevTools (`F12`) → **Console** tab.
2. Clear any cached rate and reload the page:
   ```js
   localStorage.removeItem('usdt_rate_cache');
   location.reload();
   ```
3. After the page reloads, switch to the **Network** tab.
4. You should see a request to:
   ```
   https://api.coingecko.com/api/v3/simple/price?ids=tether&vs_currencies=php
   ```
5. The response status should be `200`.
6. In the **Console** tab, type:
   ```js
   JSON.parse(localStorage.getItem('usdt_rate_cache')).rate
   ```
   It should return a number (e.g. `58.12`).
7. Verify both hidden rate inputs are populated:
   ```js
   document.getElementById('usdtTrc20RateInput').value
   document.getElementById('usdtBep20RateInput').value
   ```
8. Switch to **USDT BEP20**. It uses the same rate, so no new CoinGecko request is needed.

### 6.5 Test live gas fee fetch (DevTools)

Both USDT networks now fetch a live gas estimate, but from different sources.

#### 6.5.1 TRC20 gas fee

The TRC20 gas fee fetch runs automatically when **USDT TRC20** is active **and** no cached gas fee exists.

1. Open DevTools (`F12`) → **Console** tab.
2. Clear any cached gas fee and reload the page:
   ```js
   localStorage.removeItem('usdt_trc20_gas_fee_cache');
   localStorage.removeItem('usdt_rate_cache');
   location.reload();
   ```
3. After the page reloads, make sure **USDT TRC20** is selected, then switch to the **Network** tab.
4. You should see a request to:
   ```
   https://api.trongrid.io/wallet/getchainparameters
   ```
   If that fails, a fallback request to:
   ```
   https://api.coingecko.com/api/v3/simple/price?ids=tron&vs_currencies=usd
   ```
5. In the **Console** tab, look for messages like:
   ```
   Gas fee from TRON network params: 1.2345 USDT
   Gas fee synced to DB: 1.2345 USDT
   ```
6. Check local storage:
   ```js
   JSON.parse(localStorage.getItem('usdt_trc20_gas_fee_cache')).fee
   ```

#### 6.5.2 BEP20 gas fee

The BEP20 gas fee fetch runs automatically when **USDT BEP20** is active **and** no cached gas fee exists. It uses the live BNB/USDT price from CoinGecko to estimate the cost of a USDT BEP20 transfer.

1. Open DevTools (`F12`) → **Console** tab.
2. Clear any cached gas fee and reload the page:
   ```js
   localStorage.removeItem('usdt_bep20_gas_fee_cache');
   localStorage.removeItem('usdt_rate_cache');
   location.reload();
   ```
3. Select **USDT BEP20**, then switch to the **Network** tab.
4. You should see a request to:
   ```
   https://api.coingecko.com/api/v3/simple/price?ids=binancecoin,tether&vs_currencies=usd
   ```
5. In the **Console** tab, look for messages like:
   ```
   BEP20 gas fee from CoinGecko BNB price: 0.000325 USDT
   BEP20 gas fee synced to DB: 0.000325 USDT
   ```
6. Check local storage:
   ```js
   JSON.parse(localStorage.getItem('usdt_bep20_gas_fee_cache')).fee
   ```
7. Select **USDT TRC20**. Verify in the **Network** tab that the BEP20 CoinGecko request (`?ids=binancecoin,tether&vs_currencies=usd`) is **not** repeated.
   - The BNB price request should only happen when **USDT BEP20** is active.
   - You may see a different CoinGecko request (`?ids=tron&vs_currencies=usd`) if the TRON API fails and TRC20 falls back to CoinGecko. That is the TRC20 fallback, not the BEP20 request.

### 6.6 Test fee preview for USDT TRC20

1. Select **USDT TRC20**.
2. Enter amount `1000`.
3. Wait 1–2 seconds for the rate/gas fetch.
4. A **Fee Preview** box should appear.

Expected math (with settings: fee 5%, gas 2.50 USDT, rate ~58):

- Requested Amount: `₱1,000.00`
- Service Fee (5%): `−₱50.00`
- TRC20 Gas Fee (2.50 USDT): `−₱145.00`
- You Receive: ~`13.88 USDT`

### 6.7 Test fee preview for USDT BEP20

1. Select **USDT BEP20**.
2. Enter amount `1000`.
3. Wait for the rate and BEP20 gas fetch.

Expected math (with settings: fee 3%, live gas ~0.0003 USDT, rate ~58):

- Requested Amount: `₱1,000.00`
- Service Fee (3%): `−₱30.00`
- BEP20 Gas Fee (~0.000300 USDT): `−₱0.02`
- You Receive: ~`16.72 USDT`

> **Key point:** BEP20 gas fee should be tiny compared to TRC20. The exact value depends on the live BNB price.

### 6.8 Test that only one rate input is populated

In **Console**, run:

```js
document.getElementById('usdtTrc20RateInput').value;
document.getElementById('usdtBep20RateInput').value;
```

Both should contain the same live rate when a USDT method is active. The server uses the selected method to decide which column to store it in.

---

## 7. Test Case 4 — Submit Payout Requests

### 7.1 Submit a USDT TRC20 payout request

1. On `?page=payout`, select **USDT TRC20**.
2. Make sure the address field is filled.
3. Enter amount `1000`.
4. Wait for the preview to calculate.
5. Click **Submit Payout Request**.

### 7.2 Expected result

- Green success flash: "Payout request submitted. Admin will process it shortly."
- A new row appears in **Payout History** showing:
  - Method: `USDT TRC20`
  - Requested (₱): `1,000.00`
  - Net / USDT: the calculated USDT amount
  - Account: masked TRC20 address
  - Status: `Pending`

### 7.3 Verification SQL

```sql
SELECT *
FROM payout_requests
WHERE user_id = (SELECT id FROM users WHERE username = 'qa_member')
ORDER BY requested_at DESC
LIMIT 1;
```

Expected:

- `payout_method` = `usdt_trc20`
- `payout_account` = the TRC20 address you entered
- `service_fee_pct` = `5.00`
- `service_fee_amount` = `50.00`
- `usdt_trc20_rate` > 0
- `usdt_trc20_gas_fee` = `2.50`
- `usdt_trc20_amount` > 0
- `usdt_bep20_rate` = `0.0000`
- `usdt_bep20_gas_fee` = `0.0000`
- `usdt_bep20_amount` = `0.0000`

### 7.4 Submit a USDT BEP20 payout request

A member can only have **one pending** payout at a time. First reject or complete the TRC20 request (see next section), or run:

```sql
UPDATE payout_requests
SET status = 'rejected', processed_at = NOW()
WHERE user_id = (SELECT id FROM users WHERE username = 'qa_member')
  AND status = 'pending';
```

Then:

1. On `?page=payout`, select **USDT BEP20**.
2. Make sure the BEP20 address field is filled.
3. Enter amount `1000`.
4. Click **Submit Payout Request**.

### 7.5 Verification SQL

```sql
SELECT *
FROM payout_requests
WHERE user_id = (SELECT id FROM users WHERE username = 'qa_member')
ORDER BY requested_at DESC
LIMIT 1;
```

Expected:

- `payout_method` = `usdt_bep20`
- `usdt_bep20_rate` > 0
- `usdt_bep20_gas_fee` > 0 (live value from CoinGecko BNB price)
- `usdt_bep20_amount` > 0
- `usdt_trc20_rate` = `0.0000`
- `usdt_trc20_gas_fee` = `0.0000`
- `usdt_trc20_amount` = `0.0000`

---

## 8. Test Case 5 — Admin Payout Processing

### 8.1 View pending request

Log in as **admin** and go to:

```
http://localhost/altaslive/?page=admin_payouts
```

You should see the pending request with:

- Method badge: `USDT BEP20` or `USDT TRC20`
- Correct account address
- Correct USDT amount and rate

### 8.2 Approve and complete

1. Click **✓ Approve**.
2. The status changes to `Approved` and the row disappears from the **Pending** tab.
3. Click the **Approved** tab at the top of the page to find the request.
4. Click **✅ Mark Complete**.
5. Confirm in the modal.

### 8.3 Expected result

- Status changes to `Completed`.
- The member's e-wallet balance is reduced by the requested amount.

### 8.4 Verification SQL

```sql
SELECT status, processed_at, processed_by, admin_note
FROM payout_requests
WHERE user_id = (SELECT id FROM users WHERE username = 'qa_member')
ORDER BY requested_at DESC
LIMIT 1;
```

```sql
SELECT username, ewallet_balance, withdrawable_balance
FROM users WHERE username = 'qa_member';
```

Expected: `ewallet_balance` and `withdrawable_balance` are reduced by `1000.00`.

---

## 9. Test Case 6 — Admin User View

### 9.1 Open member detail

As admin, go to:

```
http://localhost/altaslive/?page=admin_user_view&id=<MEMBER_ID>
```

### 9.2 Profile card check

In the **Profile** card you should see:

- USDT TRC20: the saved TRC20 address
- USDT BEP20: the saved BEP20 address

### 9.3 Payouts tab

Click the **Payouts** tab. You should see:

- Method badge for each request (`USDT TRC20`, `USDT BEP20`, or `GCash`)
- Account column showing the payout account
- Status badges

---

## 10. Test Case 7 — Reactivation (optional)

This only applies if the member is **capped**. You can cap the test member manually:

```sql
UPDATE users
SET cap_status = 'capped', capped_at = NOW()
WHERE username = 'qa_member';
```

Then log in as the member and go to:

```
http://localhost/altaslive/?page=reactivate
```

### 10.1 Expected result

You should see four payment method options:

- E-Wallet
- GCash
- Maya
- **USDT TRC20**
- **USDT BEP20**

### 10.2 Select USDT TRC20

- The admin TRC20 address should display.
- Upload a dummy proof image.
- Submit.

### 10.3 Select USDT BEP20

- The admin BEP20 address should display.
- Upload a dummy proof image.
- Submit.

### 10.4 Admin verification

As admin, go to:

```
http://localhost/altaslive/?page=admin_reactivations
```

Verify:

- Method badges show `USDT TRC20` and `USDT BEP20`.
- The correct admin account address is shown for each.

### 10.5 Uncap the member (cleanup)

```sql
UPDATE users
SET cap_status = 'active', capped_at = NULL
WHERE username = 'qa_member';
```

---

## 11. Test Case 8 — Edge Cases

### 11.1 No internet connection

1. Disconnect your computer from the internet (or block `api.coingecko.com` and `api.trongrid.io` in DevTools → Network → Block request URL). The BEP20 gas fee also calls `api.coingecko.com`, so blocking CoinGecko will affect both the rate and BEP20 gas.
2. Open `?page=payout`.
3. Select **USDT TRC20** and enter amount `1000`.

**Expected:**

- No JavaScript errors that break the page.
- The fee preview may not appear or may use the cached/default rate.
- Form submission still works (rate stored as `0` if never fetched).

### 11.2 Member has no saved address

```sql
UPDATE users SET usdt_trc20_address = NULL, usdt_bep20_address = NULL
WHERE username = 'qa_member';
```

1. Open `?page=payout`.
2. Both USDT buttons should show "No account saved" styling.
3. Try to submit with an empty account field.

**Expected:** red error flash: "Please enter your payout account details."

Restore addresses afterwards:

```sql
UPDATE users
SET usdt_trc20_address = 'TQaMemberTrc20Address1234567890123456',
    usdt_bep20_address = '0xQaMemberBep20Address12345678901234567890'
WHERE username = 'qa_member';
```

### 11.3 Insufficient withdrawable balance

```sql
UPDATE users
SET withdrawable_balance = 400
WHERE username = 'qa_member';
```

1. Open `?page=payout`.
2. Try to request `1000`.

**Expected:** error flash: "Withdrawable balance insufficient. You can withdraw up to ₱400.00."

Restore balance:

```sql
UPDATE users
SET ewallet_balance = 20000.00, withdrawable_balance = 20000.00
WHERE username = 'qa_member';
```

### 11.4 Below minimum payout

1. Set minimum payout to `500` (already done in setup).
2. Try to request `100`.

**Expected:** error flash: "Minimum payout is ₱500.00."

### 11.5 Only one pending request allowed

1. Submit a valid TRC20 request.
2. Without approving/rejecting it, try to submit another request.

**Expected:** error flash: "You already have a pending payout request."

Clean up:

```sql
UPDATE payout_requests
SET status = 'rejected', processed_at = NOW()
WHERE user_id = (SELECT id FROM users WHERE username = 'qa_member')
  AND status = 'pending';
```

---

## 12. Cleanup SQL

After testing, run this to remove test data:

```sql
-- Delete test payout requests
DELETE FROM payout_requests
WHERE user_id = (SELECT id FROM users WHERE username = 'qa_member');

-- Delete test reactivations
DELETE FROM reactivations
WHERE user_id = (SELECT id FROM users WHERE username = 'qa_member');

-- Delete test member
DELETE FROM users WHERE username = 'qa_member';

-- Optional: reset settings to defaults
INSERT INTO settings (key_name, value) VALUES
  ('usdt_trc20_address',      ''),
  ('usdt_bep20_address',      ''),
  ('service_fee_usdt_trc20',  '5'),
  ('service_fee_usdt_bep20',  '5'),
  ('usdt_trc20_gas_fee',      '2.50'),
  ('usdt_bep20_gas_fee',      '0.05'),
  ('min_payout',              '500')
ON DUPLICATE KEY UPDATE value = VALUES(value);
```

---

## 13. Bug Report Template

If something fails, copy and fill this template:

```
**Page:** e.g. http://localhost/altaslive/?page=payout
**Role:** member / admin
**Browser:** e.g. Chrome 126
**Steps:**
1. ...
2. ...
**Expected:** ...
**Actual:** ...
**Browser Console errors:** paste red errors here
**Network tab:** paste failing request URLs/statuses here
**SQL evidence:** paste relevant query result here
```

---

## Quick SQL cheat sheet

```sql
-- Member balance
SELECT username, ewallet_balance, withdrawable_balance FROM users WHERE username = 'qa_member';

-- Latest payout request
SELECT * FROM payout_requests
WHERE user_id = (SELECT id FROM users WHERE username = 'qa_member')
ORDER BY requested_at DESC LIMIT 1;

-- All payout requests for member
SELECT id, payout_method, amount, status, usdt_trc20_amount, usdt_bep20_amount
FROM payout_requests
WHERE user_id = (SELECT id FROM users WHERE username = 'qa_member');

-- Pending requests
SELECT * FROM payout_requests WHERE status = 'pending';

-- Current settings
SELECT key_name, value FROM settings
WHERE key_name LIKE '%usdt%';
```

---

*End of guide.*
