# VIP Capping Bypass — Implementation Reference

> **Status:** ✅ Implemented  
> **Feature:** Admin can grant VIP privilege to active members, bypassing lifetime income cap. A separate optional toggle disables the daily pair cap.

---

## Database Schema

**`users` table additions:**

| Column | Type | Default | Meaning |
|--------|------|---------|---------|
| `capping_bypass` | `TINYINT(1)` | `0` | `1` = lifetime cap bypassed (VIP) |
| `daily_cap_bypass` | `TINYINT(1)` | `0` | `1` = daily pair cap bypassed |

**Migration:** `migrations/005_add_vip_capping_bypass.sql`

---

## Admin User View (`views/admin/user_view.php`)

Two toggle buttons appear in the header row, next to Suspend/Activate:

| Button | Condition | Action |
|--------|-----------|--------|
| **🏆 VIP** (yellow) | `capping_bypass = 1` | Click → removes VIP |
| **⭐ Grant VIP** (outline yellow) | `cap_status = 'active'` | Click → grants VIP |
| **⭐ Grant VIP** (disabled grey) | `cap_status ≠ 'active'` | Disabled with tooltip |
| **⚡ Daily Cap Off** (info) | `daily_cap_bypass = 1` | Click → re-enables daily cap |
| **⚡ Daily Cap On** (outline info) | `capping_bypass = 1` only | Click → disables daily cap |

> **Rule:** Daily cap toggle only appears when VIP is active. Only active members can receive VIP.

---

## Routes (`index.php`)

```php
'admin_toggle_vip'       => ['AdminController', 'toggleVipBypass',      'admin'],
'admin_toggle_daily_cap' => ['AdminController', 'toggleDailyCapBypass', 'admin'],
```

---

## Controller Actions (`controllers/AdminController.php`)

### `toggleVipBypass()`
- Validates member exists and `role = 'member'`
- **Blocks VIP grant** if `cap_status ≠ 'active'` (capped/perminact members cannot get VIP)
- Updates `capping_bypass = 1` or `0`
- Flashes success/error, redirects back to `admin_user_view`

### `toggleDailyCapBypass()`
- Updates `daily_cap_bypass = 1` or `0`
- No status restriction

---

## Business Logic

### `core/CapEngine.php`

**`canEarn()`** — VIP early return:
```php
if (!empty($status['capping_bypass'])) {
    return ['allowed' => round($amount, 2), 'blocked' => 0.00, 'status' => 'vip'];
}
```

**`recordEarning()`** — Skip `applyCap()` for VIPs:
```php
$bypass = (int)db()->query("SELECT capping_bypass FROM users WHERE id = {$userId}")->fetchColumn();
if (!$bypass) {
    self::applyCap($userId);
}
```

**`getCapStatus()`** — Returns `capping_bypass` and `daily_cap_bypass` in the array.

### `core/Commission.php` (Pairing Bonus)

Daily cap bypass in binary tree walk:
```php
if (!empty($ancestor['daily_cap_bypass'])) {
    $capRemaining = $newPairs; // unlimited
} else {
    $capRemaining = (int)$ancestor['daily_pair_cap'] - (int)$ancestor['pairs_paid_today'];
}
```

---

## Member Dashboard (`views/member/dashboard.php`)

### VIP Banner
Shown when `$user['capping_bypass'] == 1`:
- 🏆 icon + "VIP Privilege Active"
- "Your account has unlimited earning potential. Lifetime income capping is bypassed."
- If `daily_cap_bypass` also on: "Daily pair cap is also disabled."
- Yellow/gold VIP badge

### Lifetime Cap Widget
- **VIP active:** Shows ♾️ "Unlimited Earnings" instead of progress bar
- **Normal:** Progress bar + earned/remaining as before
- Cap Status line shows "⚡ Daily Off" badge if `daily_cap_bypass = 1`

---

## Member Cap Status Page (`views/member/cap_status.php`)

- VIP badge shown at top of card when active
- Status badge shows "🏆 VIP — Unlimited Earnings" instead of Active/Capped/Inactive
- Timeline current state shows "🏆 VIP — Cap Bypassed"

---

## Admin Cap Monitor (`views/admin/cap_monitor.php`)

Table shows small badges next to usernames:
- `🏆 VIP` (yellow/black) when `capping_bypass = 1`
- `⚡ Daily` (info) when `daily_cap_bypass = 1`

---

## Reset Behavior (`reset.php`)

Both columns reset to `0` during database reset:
```sql
UPDATE users SET ... capping_bypass = 0, daily_cap_bypass = 0, ...
```

---

## Files Modified

| File | Change |
|------|--------|
| `migrations/005_add_vip_capping_bypass.sql` | New migration |
| `install.sql` | Added columns to `CREATE TABLE users` |
| `index.php` | Two new routes |
| `controllers/AdminController.php` | `toggleVipBypass()`, `toggleDailyCapBypass()` |
| `core/CapEngine.php` | Bypass checks in `canEarn()`, `recordEarning()`, `getCapStatus()` |
| `core/Commission.php` | `daily_cap_bypass` in pairing bonus logic |
| `views/admin/user_view.php` | VIP + daily cap toggle buttons |
| `views/admin/cap_monitor.php` | VIP/daily badges in table |
| `views/member/dashboard.php` | VIP banner, unlimited cap widget |
| `views/member/cap_status.php` | VIP indicators |
| `reset.php` | Reset both bypass flags to 0 |
