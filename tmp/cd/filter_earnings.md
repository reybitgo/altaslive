# CD Filter Earnings — Design & Implementation

## Core Rule

**Commissions are blocked only when the *newly-placed member* (source) has a CD-sourced registration.** The check is purely source-side — it looks at how the person being registered/placed paid, not at the earner's CD status.

If the source is paid (non-CD code, e-wallet), commissions flow to all uplines even if some ancestors have CD status. The earner-side CD split (`CdStatus::fillBucket()`) handles commission deduction for CD users who earn.

Leg counts always increment regardless of CD status.

---

## `isPaidMember()` — The Gate

`models/User.php`:

```php
public static function isPaidMember(int $userId): bool
{
    $st = db()->prepare("
        SELECT u.reg_payment_method, COALESCE(c.is_cd, 0) AS is_cd
        FROM users u
        LEFT JOIN reg_codes c ON c.id = u.reg_code_id
        WHERE u.id = ?
    ");
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) return false;
    return $row['reg_payment_method'] !== 'pending'
        && !($row['reg_payment_method'] === 'code' && (int)$row['is_cd'] === 1);
}
```

Returns `false` (not paid, block commissions) when:
1. `reg_payment_method = 'pending'` — referral registration, no payment
2. `reg_payment_method = 'code'` AND the linked `reg_code.is_cd = 1` — CD-code registration

Returns `true` (paid, allow commissions) when:
1. `reg_payment_method = 'ewallet'`
2. `reg_payment_method = 'code'` AND `reg_code.is_cd = 0`
3. `reg_payment_method = 'code'` AND no reg_code linked

---

## Three Commission Gates

All three paths check `User::isPaidMember($newUserId)` — the **source**, not the earner:

| Path | File:Line | Gate |
|------|-----------|------|
| Direct referral | `core/Commission.php:146` | `if (!User::isPaidMember($newUserId)) return;` |
| Indirect referral | `core/Commission.php:230` | `if (!User::isPaidMember($newUserId)) return;` |
| Binary pairing | `core/Commission.php:33,74` | `$newUserIsPaid = $newUserIsActive && User::isPaidMember($newUserId);` then `if ($newUserIsPaid && $ancestor)` |

---

## Leg Counts Always Increment

`processBinaryPlacement()` updates `left_count` / `right_count` on every ancestor regardless of CD status. Binary tree structure is preserved.

---

## Admin-Assigned CD

`CdStatus::assign()` sets `users.cd_active = 1` but **never touches `reg_payment_method` or `reg_code_id`**. Since `isPaidMember()` checks registration method only, admin-assigned CD on a previously-paid user does not change their `isPaidMember()` status — uplines continue to earn from them.

The earner-side CD split (`CdStatus::fillBucket()`) handles deductions for recouping their CD target.

---

## Relationship to Existing Systems

| System | Behavior |
|--------|----------|
| **CD split** (`CdStatus::fillBucket()`) | Unchanged — earner-side split for any commissions a CD user earns |
| **Lifetime cap** (`CapEngine`) | Unchanged — wallet portion still capped after CD split |
| **DFI** (`DailyFixedIncome.php:66`) | Already excludes `cd_active = 1` — aligned |
| **E-wallet registration** | No CD code → `isPaidMember()` = true |
| **Pending activation** | `User::activate()` now auto-assigns CD for CD-code pending users → `cd_active = 1` for views, but `isPaidMember()` still returns false via `reg_payment_method = 'pending'` |

---

## Edge Cases

| Scenario | `isPaidMember()` | Commissions |
|----------|-----------------|-------------|
| CD-code registration | `false` (`reg_payment_method=code`, `is_cd=1`) | **Blocked** (source check) |
| E-wallet registration | `true` (`reg_payment_method=ewallet`) | Flow normally |
| Non-CD code registration | `true` (`reg_payment_method=code`, `is_cd=0`) | Flow normally |
| Pending (referral) user | `false` (`reg_payment_method=pending`) | **Blocked** |
| Admin assigns CD to paid user | `true` (unchanged — `reg_payment_method` not touched) | Flow (CD split earner-side) |
| CD user has paid downline | Source is paid (`isPaidMember=true`) | Flow to CD user + CD split earner-side |
| Pending CD-code user activated | `false` (`reg_payment_method=pending`) | **Blocked** (User::activate() auto-assigns CD) |

---

## Files Changed

| File | Change |
|------|--------|
| `models/User.php:259-272` | `isPaidMember()` — checks `reg_payment_method` + `reg_codes.is_cd` |
| `core/Commission.php:146` | Early return in `processDirectReferral()` |
| `core/Commission.php:230` | Early return in `processIndirectReferral()` |
| `core/Commission.php:33,74` | Binary pairing gated on `$newUserIsPaid` |
| `models/User.php:171-182` | `activate()` now auto-assigns CD for CD-code pending users |
| `tmp/cd/test_filter_earnings.php` | 10 test cases covering all scenarios |
