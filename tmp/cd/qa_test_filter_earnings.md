# QA Test: CD Filter Earnings

**Feature**: CD-sourced members should not earn commissions for their uplines. Only "paid" members (bought a package via non-CD code or e-wallet) trigger commissions.

**Tester**: __________  
**Date**: __________  
**Environment**: `http://localhost/altaslive/`

---

## Setup

### Pre-requisites

| # | Item | Done? |
|---|------|-------|
| 1 | Logged in as **admin** (`admin` / `Admin@1234`) | ☐ |
| 2 | At least **2 active packages** exist (Admin → Packages) | ☐ |
| 3 | At least **1 non-CD registration code** exists (Admin → Reg Codes → Generate → uncheck "CD Code") | ☐ |
| 4 | At least **1 CD registration code** exists (Admin → Reg Codes → Generate → check "CD Code") | ☐ |
| 5 | A **sponsor member** is active (e.g. `member1`) with a package | ☐ |

> **Tip**: Note the exact code strings — you'll need them during registration.

### How to generate codes

1. Go to `/?page=admin_codes`
2. Click **Generate Codes**
3. Select a package, quantity = 1, price = 0.00
4. **For non-CD**: leave "CD Code" unchecked → code looks like `ABCD-EFGH-JKLM`
5. **For CD**: check "CD Code" → code looks like `CD-ABCD-EFGH-JKLM`

---

## Test 1: CD Registration Produces Zero Commissions

**Goal**: Verify that registering via a CD code generates no commissions for anyone.

| Step | Action | Expected Result | Pass/Fail |
|------|--------|----------------|-----------|
| 1.1 | Note sponsor's current commissions: go to `/?page=admin_user_view&id=SPONSOR_ID` → Commissions tab, count rows | Count known | ☐ |
| 1.2 | Open an **incognito/private** browser window | | |
| 1.3 | Go to `http://localhost/altaslive/?page=register` | Registration page loads | ☐ |
| 1.4 | Enter sponsor username from setup, leave position as Left | | ☐ |
| 1.5 | Enter a **CD code** (starts with `CD-`) for the code field | Code accepted | ☐ |
| 1.6 | Fill in: username `cdtest1`, password `Test1234!`, name `CD Test One`, mobile `09171234511` | | ☐ |
| 1.7 | Select a package | | ☐ |
| 1.8 | Click Register | Success: "Registration successful!" | ☐ |
| 1.9 | Go back to sponsor's Commissions tab and refresh | **No new commission rows** | ☐ |
| 1.10 | Delete `cdtest1` (via Admin → Members → search → Suspend is not enough; run `reset.php` or delete via DB) or create fresh for next test | | ☐ |

> **If test 1.9 shows new commissions**: The CD filter is **broken**. Log the commission type and amount.

---

## Test 2: Non-CD (Paid) Registration Produces Normal Commissions

**Goal**: Verify that registering with a normal code still generates commissions (proves the feature didn't break paid flow).

| Step | Action | Expected Result | Pass/Fail |
|------|--------|----------------|-----------|
| 2.1 | Note sponsor's commission count again | Count known | ☐ |
| 2.2 | Open a **new** incognito window | | |
| 2.3 | Register a new user with the **non-CD code** (no `CD-` prefix): username `paidtest1`, password `Test1234!` | Success | ☐ |
| 2.4 | Refresh sponsor's Commissions tab | **At least 1 new commission row** (direct referral + pairing) | ☐ |
| 2.5 | Verify commission types: "Direct referral bonus" and "Pairing" are present | Both types appear | ☐ |

> **If test 2.4 shows zero commissions**: The feature broke the paid flow. **STOP** and debug.

---

## Test 3: E-Wallet Registration Produces Normal Commissions

**Goal**: Verify that e-wallet-paid registrations still generate commissions.

| Step | Action | Expected Result | Pass/Fail |
|------|--------|----------------|-----------|
| 3.1 | Log in as **sponsor member** (not admin) | | ☐ |
| 3.2 | Go to `/?page=register&sponsor=SPONSOR_USERNAME` | Registration page with sponsor pre-filled | ☐ |
| 3.3 | Switch payment method to **E-Wallet** (should appear if balance is sufficient) | | ☐ |
| 3.4 | Fill in: username `ewallet1`, name `Ewallet Test`, mobile `09171234522` | | ☐ |
| 3.5 | Select a package and complete registration | Success | ☐ |
| 3.6 | Log out, log back in as **admin** | | ☐ |
| 3.7 | Check sponsor's Commissions tab | **New commissions present** | ☐ |

---

## Test 4: CD User Binary Placement — No Pairing Bonus

**Goal**: Verify that placing a CD user under an upline does NOT trigger a pairing bonus, even when it creates a pair.

| Step | Action | Expected Result | Pass/Fail |
|------|--------|----------------|-----------|
| 4.1 | As admin, view the **sponsor member's** genealogy at `/?page=admin_user_view&id=SPONSOR_ID` (note: not a tab, but there's no admin genealogy page — use member perspective or check Binary Placement card) | See `left_count` and `right_count` | ☐ |
| 4.2 | Note current values for `left_count`, `right_count`, `pairs_paid`, `pairs_paid_today` | Baseline known | ☐ |
| 4.3 | Register a **CD user** on the **LEFT** of sponsor (use a second CD code) as `cdleft1` | Success | ☐ |
| 4.4 | Refresh and check sponsor's leg counts | `left_count` incremented by 1 | ☐ |
| 4.5 | Register a **CD user** on the **RIGHT** of sponsor (use a third CD code) as `cdright1` | Success | ☐ |
| 4.6 | Refresh and check sponsor's leg counts | `right_count` incremented by 1 | ☐ |
| 4.7 | Check `pairs_paid` and `pairs_paid_today` | **Should still be the same as baseline** (no new pairs) | ☐ |
| 4.8 | Check sponsor's Commissions tab | **No new pairing bonus rows** | ☐ |

> **If test 4.7 or 4.8 shows new pairs/commissions**: The binary pairing filter is **broken**.

---

## Test 5: Mixed Binary Tree — Paid Placement Triggers Pair

**Goal**: Verify that after CD placements, a paid placement correctly triggers a pair.

| Step | Action | Expected Result | Pass/Fail |
|------|--------|----------------|-----------|
| 5.1 | Using baseline from Test 4, note `pairs_paid` | e.g. 0 | ☐ |
| 5.2 | Register a **paid (non-CD) user** on the LEFT of sponsor via new incognito window: `paidleft1` | Success | ☐ |
| 5.3 | Refresh and check `pairs_paid` | **Incremented by 1** (left_count was already >0 from CD, right_count from CD) | ☐ |
| 5.4 | Check Commissions tab for sponsor | **New pairing bonus present** | ☐ |
| 5.5 | Check `pairs_paid_today` | Also incremented by 1 | ☐ |

> **This proves**: CD placements build leg counts but don't pay out. A single paid placement on the smaller leg triggers the pair.

---

## Test 6: CD User's Own Downline — Paid Members Still Earn Them Money

**Goal**: Verify that a CD user still earns commissions from their **paid** downline (even though the CD user themselves won't trigger commissions for their own upline).

| Step | Action | Expected Result | Pass/Fail |
|------|--------|----------------|-----------|
| 6.1 | Note `cdleft1`'s current commissions (view as admin → user view → Commissions tab) | 0 | ☐ |
| 6.2 | Register a **paid (non-CD) user** as direct referral of `cdleft1`: open incognito, go to `/?page=register&sponsor=cdleft1` | | ☐ |
| 6.3 | Use a non-CD code, fill username `cdleft1_paid` | Success | ☐ |
| 6.4 | Check `cdleft1`'s Commissions tab | **New direct referral bonus present** | ☐ |
| 6.5 | Verify the amount was split: check `cdleft1`'s CD Status card (admin user view) | CD bucket `filled_amount` increased | ☐ |

> **If test 6.4 shows no commission**: The CD user's ability to earn from paid downline is broken.

---

## Test 7: Admin-Manual CD Assignment — Existing Downline Unaffected

**Goal**: Verify that manually assigning CD to a previously-paid user does not retroactively break commissions from their existing downline.

| Step | Action | Expected Result | Pass/Fail |
|------|--------|----------------|-----------|
| 7.1 | Pick a **paid member** (e.g. `paidtest1` from Test 2) who has `cd_active = 0` | | ☐ |
| 7.2 | As admin, go to that member's view → CD Status card → **Assign CD** with a target (e.g. 1000) | "Commission-Deduct status assigned successfully" | ☐ |
| 7.3 | Verify `cd_active = 1` in the CD Status card | Shows "Active" badge | ☐ |
| 7.4 | Register a **new paid user** under this member (use non-CD code): `under_paidcd1` | Success | ☐ |
| 7.5 | Check the member's Commissions tab | **New commissions present** (they still earn from paid downline) | ☐ |
| 7.6 | Verify the commission shows CD split: the description mentions "... to CD, ... to wallet" | CD split noted | ☐ |

---

## Test 8: CD User Activated from Pending — Still No Commissions

**Goal**: Verify that activating a CD user who registered via referral link (pending status) still does not trigger back-pay commissions.

| Step | Action | Expected Result | Pass/Fail |
|------|--------|----------------|-----------|
| 8.1 | Open incognito and register a user via referral link: go to `/?page=register&sponsor=SPONSOR&ref=1` | Registration shows "pending" or similar message | ☐ |
| 8.2 | Use username `pendingcd1`, password `Test1234!`, use a **CD code** | User created as `pending` | ☐ |
| 8.3 | As admin, go to `/?page=admin_user_view&id=PENDINGCD1_ID` | Shows status `pending` | ☐ |
| 8.4 | Check sponsor's Commissions tab | **No new commissions** (pending already blocked) | ☐ |
| 8.5 | As admin, **activate** `pendingcd1` (click the Activate button) | "activated successfully" | ☐ |
| 8.6 | Refresh sponsor's Commissions tab | **Still no new commissions** (isPaidMember blocks it) | ☐ |

---

## Test 9: Leg Count Accuracy After All Operations

**Goal**: Verify that the binary tree leg counts remain accurate regardless of CD status.

| Step | Action | Expected Result | Pass/Fail |
|------|--------|----------------|-----------|
| 9.1 | As admin, view **sponsor member's** Binary Placement info | | ☐ |
| 9.2 | Note `left_count` and `right_count` | Counts known | ☐ |
| 9.3 | Add up all direct placements under sponsor (both CD and paid) | Total matches `left_count + right_count` | ☐ |
| 9.4 | Verify `pairs_paid` equals **only the pairs triggered by paid placements** | `pairs_paid` < min(`left_count`, `right_count`) if CD-only placements exist on both sides | ☐ |

---

## Test 10: Edge — CD Code with E-Wallet Registration (Should Not Happen)

**Goal**: CD is only auto-assigned via CD codes. E-wallet registration should never auto-assign CD.

| Step | Action | Expected Result | Pass/Fail |
|------|--------|----------------|-----------|
| 10.1 | Log in as a member with sufficient e-wallet balance | | ☐ |
| 10.2 | Register a new member via **E-Wallet** payment | Success | ☐ |
| 10.3 | As admin, check the new member's CD Status card | **No active CD** (cd_active = 0) | ☐ |
| 10.4 | Check that `isPaidMember()` returns `true` for this user | They should be "paid" | ☐ |

---

## Regression Check: Existing Features Still Work

| # | Feature | How to Verify | Pass/Fail |
|---|---------|---------------|-----------|
| R1 | Lifetime cap still blocks earnings | Register a paid user under a capped member; verify no commission reaches wallet | ☐ |
| R2 | Daily pair cap still flushes | Register multiple paid pairs under a non-bypass member until daily cap exceeded; verify flushed pairs | ☐ |
| R3 | CD bucket completion auto-removes CD | Fill a CD bucket completely (via commissions or adjust in DB); verify `cd_active` flips to 0 | ☐ |
| R4 | DFI still excludes CD users | Run midnight reset; verify CD user gets no DFI entry | ☐ |
| R5 | Admin can manually complete/cancel CD | Go to admin user view → CD card → Mark Complete / Cancel | ☐ |

---

## Summary

| Test | Description | Result |
|------|-------------|--------|
| 1 | CD registration → zero commissions | ☐ Pass / ☐ Fail |
| 2 | Non-CD registration → normal commissions | ☐ Pass / ☐ Fail |
| 3 | E-wallet registration → normal commissions | ☐ Pass / ☐ Fail |
| 4 | CD binary placement → no pairing bonus | ☐ Pass / ☐ Fail |
| 5 | Mixed binary → paid triggers pair | ☐ Pass / ☐ Fail |
| 6 | CD user earns from paid downline | ☐ Pass / ☐ Fail |
| 7 | Admin-assigned CD → existing downline unaffected | ☐ Pass / ☐ Fail |
| 8 | CD user activated from pending → still no commissions | ☐ Pass / ☐ Fail |
| 9 | Leg counts accurate after all operations | ☐ Pass / ☐ Fail |
| 10 | E-wallet registration never auto-assigns CD | ☐ Pass / ☐ Fail |
| R1–R5 | Regression checks | ☐ Pass / ☐ Fail |

**Overall**: ☐ All Passed &nbsp;&nbsp; ☐ Some Failed (see notes)

---

## Notes / Bugs Found

```
```
