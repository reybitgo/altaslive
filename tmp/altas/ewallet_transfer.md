# E-Wallet Transfer & Admin Top-Up — Implementation Plan

> **Status:** Pending review  
> **Scope:** Member-to-member/admin transfers with configurable fees, admin manual top-ups, unified monitoring, password confirmation, and transfer limits.

---

## 1. Database Changes

### 1.1 New Table: `ewallet_transfers`

```sql
CREATE TABLE ewallet_transfers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_id     INT UNSIGNED NOT NULL,
  recipient_id  INT UNSIGNED NOT NULL,
  amount        DECIMAL(12,2) NOT NULL,        -- gross amount entered by sender
  fee           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  net_amount    DECIMAL(12,2) NOT NULL,        -- amount recipient actually receives
  status        ENUM('completed','failed') NOT NULL DEFAULT 'completed',
  note          VARCHAR(255) NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sender_id)    REFERENCES users(id),
  FOREIGN KEY (recipient_id) REFERENCES users(id)
) ENGINE=InnoDB;
```

### 1.2 New Table: `ewallet_admin_topups`

```sql
CREATE TABLE ewallet_admin_topups (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id      INT UNSIGNED NOT NULL,         -- admin who performed the top-up
  recipient_id  INT UNSIGNED NOT NULL,         -- member who received the funds
  amount        DECIMAL(12,2) NOT NULL,
  note          VARCHAR(255) NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (admin_id)     REFERENCES users(id),
  FOREIGN KEY (recipient_id) REFERENCES users(id)
) ENGINE=InnoDB;
```

### 1.3 Update `ewallet_ledger.ref_type` ENUM

```sql
ALTER TABLE ewallet_ledger
  MODIFY COLUMN ref_type ENUM('commission','payout','reactivation','transfer','topup') NULL;
```

### 1.4 Settings Keys

| `key_name` | Default | Description |
|------------|---------|-------------|
| `ewallet_transfer_fee` | `0.00` | Flat fee in ₱ deducted from member senders per transfer |
| `ewallet_min_transfer` | `50.00` | Minimum amount allowed per transfer |
| `ewallet_transfer_daily_limit` | `5000.00` | Max ₱ a member can send per day |
| `ewallet_transfer_weekly_limit` | `20000.00` | Max ₱ a member can send per week |

> Admin transfers and admin top-ups are exempt from all limits and fees.

---

## 2. Admin Settings UI (`views/admin/settings.php`)

New **💱 E-Wallet Transfers** section:

```html
<hr class="my-3">
<p class="fw-bold mb-2" style="font-size:.82rem;">💱 E-Wallet Transfers</p>

<div class="row g-2 mb-3">
  <div class="col-6">
    <label class="form-label" style="font-weight:700;font-size:.75rem;">Transfer Fee (₱)</label>
    <input type="number" name="ewallet_transfer_fee" class="form-control" min="0" step="0.01" value="<?= e(setting('ewallet_transfer_fee', '0.00')) ?>">
    <div class="form-text">Deducted from member senders. Admin transfers are free.</div>
  </div>
  <div class="col-6">
    <label class="form-label" style="font-weight:700;font-size:.75rem;">Minimum Transfer (₱)</label>
    <input type="number" name="ewallet_min_transfer" class="form-control" min="0" step="0.01" value="<?= e(setting('ewallet_min_transfer', '50.00')) ?>">
  </div>
</div>

<div class="row g-2 mb-3">
  <div class="col-6">
    <label class="form-label" style="font-weight:700;font-size:.75rem;">Daily Limit (₱)</label>
    <input type="number" name="ewallet_transfer_daily_limit" class="form-control" min="0" step="0.01" value="<?= e(setting('ewallet_transfer_daily_limit', '5000.00')) ?>">
  </div>
  <div class="col-6">
    <label class="form-label" style="font-weight:700;font-size:.75rem;">Weekly Limit (₱)</label>
    <input type="number" name="ewallet_transfer_weekly_limit" class="form-control" min="0" step="0.01" value="<?= e(setting('ewallet_transfer_weekly_limit', '20000.00')) ?>">
  </div>
</div>
```

---

## 3. Business Logic

### 3.1 `Ewallet::transfer()` — Member & Admin

```php
public static function transfer(
    int $senderId,
    int $recipientId,
    float $amount,
    string $note = ''
): array {
    $amount = round(max(0, $amount), 2);
    $minTransfer = (float)setting('ewallet_min_transfer', '50.00');

    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Invalid amount.', 'transfer_id' => null];
    }
    if ($amount < $minTransfer) {
        return ['ok' => false, 'error' => 'Minimum transfer is ' . fmt_money($minTransfer) . '.', 'transfer_id' => null];
    }

    $sender    = User::find($senderId);
    $recipient = User::find($recipientId);
    $isAdmin   = ($sender['role'] ?? '') === 'admin';

    // Fee: only members pay
    $fee = $isAdmin ? 0.00 : round((float)setting('ewallet_transfer_fee', '0.00'), 2);
    $totalDebit = $amount + $fee;

    // Limits: only members are checked
    if (!$isAdmin) {
        $dailyLimit  = (float)setting('ewallet_transfer_daily_limit', '5000.00');
        $weeklyLimit = (float)setting('ewallet_transfer_weekly_limit', '20000.00');

        $sentToday = (float)db()->query("
            SELECT COALESCE(SUM(amount),0) FROM ewallet_transfers
            WHERE sender_id = {$senderId} AND status = 'completed' AND DATE(created_at) = CURDATE()
        ")->fetchColumn();

        $sentThisWeek = (float)db()->query("
            SELECT COALESCE(SUM(amount),0) FROM ewallet_transfers
            WHERE sender_id = {$senderId} AND status = 'completed'
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
        ")->fetchColumn();

        if (($sentToday + $amount) > $dailyLimit) {
            return ['ok' => false, 'error' => 'Daily transfer limit exceeded.', 'transfer_id' => null];
        }
        if (($sentThisWeek + $amount) > $weeklyLimit) {
            return ['ok' => false, 'error' => 'Weekly transfer limit exceeded.', 'transfer_id' => null];
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Lock sender and check balance
        $st = $pdo->prepare('SELECT ewallet_balance FROM users WHERE id = ? FOR UPDATE');
        $st->execute([$senderId]);
        $bal = (float)$st->fetchColumn();

        if ($bal < $totalDebit) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Insufficient balance.', 'transfer_id' => null];
        }

        // Debit sender
        $pdo->prepare('UPDATE users SET ewallet_balance = ewallet_balance - ? WHERE id = ?')
            ->execute([$totalDebit, $senderId]);

        // Credit recipient
        $pdo->prepare('UPDATE users SET ewallet_balance = ewallet_balance + ? WHERE id = ?')
            ->execute([$amount, $recipientId]);

        // Credit fee to admin/system account (primary admin, id = 1)
        if ($fee > 0) {
            $pdo->prepare('UPDATE users SET ewallet_balance = ewallet_balance + ? WHERE id = 1 AND role = "admin"')
                ->execute([$fee]);
        }

        // Record transfer
        $pdo->prepare("
            INSERT INTO ewallet_transfers
              (sender_id, recipient_id, amount, fee, net_amount, note)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$senderId, $recipientId, $amount, $fee, $amount, $note]);
        $transferId = (int)$pdo->lastInsertId();

        // Ledger: sender debit
        $senderBal = (float)$pdo->query("SELECT ewallet_balance FROM users WHERE id = {$senderId}")->fetchColumn();
        $pdo->prepare("
            INSERT INTO ewallet_ledger
              (user_id, type, amount, reference_id, ref_type, balance_after, note)
            VALUES (?, 'debit', ?, ?, 'transfer', ?, ?)
        ")->execute([$senderId, $totalDebit, $transferId, $senderBal,
            "Transfer to @{$recipient['username']}" . ($note ? " — {$note}" : '')
        ]);

        // Ledger: recipient credit
        $recipientBal = (float)$pdo->query("SELECT ewallet_balance FROM users WHERE id = {$recipientId}")->fetchColumn();
        $pdo->prepare("
            INSERT INTO ewallet_ledger
              (user_id, type, amount, reference_id, ref_type, balance_after, note)
            VALUES (?, 'credit', ?, ?, 'transfer', ?, ?)
        ")->execute([$recipientId, $amount, $transferId, $recipientBal,
            "Transfer from @{$sender['username']}" . ($note ? " — {$note}" : '')
        ]);

        // Ledger: fee credit to admin
        if ($fee > 0) {
            $adminBal = (float)$pdo->query("SELECT ewallet_balance FROM users WHERE id = 1")
                ->fetchColumn();
            $pdo->prepare("
                INSERT INTO ewallet_ledger
                  (user_id, type, amount, reference_id, ref_type, balance_after, note)
                VALUES (?, 'credit', ?, ?, 'transfer', ?, ?)
            ")->execute([1, $fee, $transferId, $adminBal,
                "Transfer fee from @{$sender['username']} to @{$recipient['username']}"
            ]);
        }

        $pdo->commit();
        return ['ok' => true, 'error' => null, 'transfer_id' => $transferId];
    } catch (\Exception $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Transfer failed. Please try again.', 'transfer_id' => null];
    }
}
```

### 3.2 `Ewallet::adminTopUp()` — Admin Only

```php
public static function adminTopUp(
    int $adminId,
    int $recipientId,
    float $amount,
    string $note = ''
): array {
    $amount = round(max(0, $amount), 2);
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Invalid amount.'];
    }

    $admin = User::find($adminId);
    if (!$admin || $admin['role'] !== 'admin') {
        return ['ok' => false, 'error' => 'Unauthorized.'];
    }

    $recipient = User::find($recipientId);
    if (!$recipient) {
        return ['ok' => false, 'error' => 'Recipient not found.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Credit recipient (no debit — funds created from thin air)
        $pdo->prepare('UPDATE users SET ewallet_balance = ewallet_balance + ? WHERE id = ?')
            ->execute([$amount, $recipientId]);

        // Record top-up
        $pdo->prepare("
            INSERT INTO ewallet_admin_topups (admin_id, recipient_id, amount, note)
            VALUES (?, ?, ?, ?)
        ")->execute([$adminId, $recipientId, $amount, $note]);
        $topupId = (int)$pdo->lastInsertId();

        // Ledger: recipient credit
        $recipientBal = (float)$pdo->query("SELECT ewallet_balance FROM users WHERE id = {$recipientId}")
            ->fetchColumn();
        $pdo->prepare("
            INSERT INTO ewallet_ledger
              (user_id, type, amount, reference_id, ref_type, balance_after, note)
            VALUES (?, 'credit', ?, ?, 'topup', ?, ?)
        ")->execute([$recipientId, $amount, $topupId, $recipientBal,
            "Admin top-up by @{$admin['username']}" . ($note ? " — {$note}" : '')
        ]);

        $pdo->commit();
        return ['ok' => true, 'error' => null, 'topup_id' => $topupId];
    } catch (\Exception $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Top-up failed.'];
    }
}
```

---

## 4. Transfer Page — Universal UI (`views/member/ewallet_transfer.php`)

### 4.1 Routes

```php
'ewallet_transfer'     => ['MemberController', 'ewalletTransfer',     'any'],
'do_ewallet_transfer'  => ['MemberController', 'doEwalletTransfer',   'any'],
```

### 4.2 Controller

```php
public function ewalletTransfer(): void
{
    Auth::check() or redirect('/?page=login');
    $user = Auth::user();
    $fee = Auth::isAdmin() ? 0.00 : (float)setting('ewallet_transfer_fee', '0.00');
    $min = (float)setting('ewallet_min_transfer', '50.00');
    $dailyLimit  = (float)setting('ewallet_transfer_daily_limit', '5000.00');
    $weeklyLimit = (float)setting('ewallet_transfer_weekly_limit', '20000.00');

    // Show recent transfers for this user
    $pdo = db();
    $recent = $pdo->prepare("
        SELECT t.*, su.username AS sender_username, ru.username AS recipient_username
        FROM ewallet_transfers t
        JOIN users su ON su.id = t.sender_id
        JOIN users ru ON ru.id = t.recipient_id
        WHERE t.sender_id = ? OR t.recipient_id = ?
        ORDER BY t.created_at DESC
        LIMIT 20
    ");
    $recent->execute([$user['id'], $user['id']]);

    require 'views/member/ewallet_transfer.php';
}

public function doEwalletTransfer(): void
{
    Auth::check() or redirect('/?page=login');
    csrf_verify();

    $senderId = Auth::id();
    $recipientUsername = trim($_POST['recipient'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    $password = $_POST['password'] ?? '';

    // Password confirmation
    if (!User::verifyPassword($senderId, $password)) {
        flash('error', 'Password confirmation is incorrect.');
        redirect('/?page=ewallet_transfer');
        return;
    }

    $recipient = User::findByUsername($recipientUsername);
    if (!$recipient) {
        flash('error', 'Recipient not found.');
        redirect('/?page=ewallet_transfer');
        return;
    }

    if ($recipient['id'] === $senderId) {
        flash('error', 'You cannot transfer to yourself.');
        redirect('/?page=ewallet_transfer');
        return;
    }

    $result = Ewallet::transfer($senderId, $recipient['id'], $amount, $note);

    if ($result['ok']) {
        flash('success', 'Transfer completed successfully.');
    } else {
        flash('error', $result['error']);
    }
    redirect('/?page=ewallet_transfer');
}
```

### 4.3 View Layout

```
┌─────────────────────────────────────────────┐
│  💱 Send Money / E-Wallet Transfer           │
├─────────────────────────────────────────────┤
│  Available Balance: ₱X,XXX.00               │
│  [Admin badge: No fee]  or  [Fee: ₱X]       │
├─────────────────────────────────────────────┤
│  Recipient: [@__________]  [Search/Verify]  │
│  Amount:    [₱__________]  min ₱50          │
│  Note:      [____________]  optional        │
│  Password:  [************]  confirm         │
│                                             │
│  You send: ₱100    Fee: ₱10    Total: ₱110  │
│  [ Send Transfer ]                          │
├─────────────────────────────────────────────┤
│  📋 Recent Transfers                        │
│  ─────────────────────────────────────────  │
│  You sent ₱100 to @john — ₱10 fee — 2m ago  │
│  You received ₱50 from @mary — 1h ago       │
└─────────────────────────────────────────────┘
```

---

## 5. Admin Top-Up Page (`views/admin/ewallet_topup.php`)

### 5.1 Route & Controller

```php
'admin_ewallet_topup'    => ['AdminController', 'ewalletTopUp',    'admin'],
'do_admin_ewallet_topup' => ['AdminController', 'doEwalletTopUp',  'admin'],
```

```php
public function ewalletTopUp(): void
{
    Auth::guard('admin');
    require 'views/admin/ewallet_topup.php';
}

public function doEwalletTopUp(): void
{
    Auth::guard('admin');
    csrf_verify();

    $adminId = Auth::id();
    $recipientUsername = trim($_POST['recipient'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    $recipient = User::findByUsername($recipientUsername);
    if (!$recipient) {
        flash('error', 'Recipient not found.');
        redirect('/?page=admin_ewallet_topup');
        return;
    }

    $result = Ewallet::adminTopUp($adminId, $recipient['id'], $amount, $note);

    flash($result['ok'] ? 'success' : 'error', $result['error'] ?? 'Top-up completed.');
    redirect('/?page=admin_ewallet_topup');
}
```

### 5.2 View Layout

```
┌─────────────────────────────────────────────┐
│  💰 Admin E-Wallet Top-Up                    │
├─────────────────────────────────────────────┤
│  Recipient: [@__________]                   │
│  Amount:    [₱__________]                   │
│  Note:      [____________]                  │
│  [ Top Up Account ]                         │
│                                             │
│  ⚠️ This creates funds. No debit source.    │
└─────────────────────────────────────────────┘
```

---

## 6. Admin Monitoring Page (`views/admin/ewallet_monitor.php`)

### 6.1 Route & Controller

```php
'admin_ewallet_monitor' => ['AdminController', 'ewalletMonitor', 'admin'],
```

```php
public function ewalletMonitor(): void
{
    Auth::guard('admin');
    $pdo = db();

    // 1. All transfers
    $transfers = $pdo->query("
        SELECT t.*, su.username AS sender_username, ru.username AS recipient_username
        FROM ewallet_transfers t
        JOIN users su ON su.id = t.sender_id
        JOIN users ru ON ru.id = t.recipient_id
        ORDER BY t.created_at DESC
        LIMIT 100
    ")->fetchAll();

    // 2. All admin top-ups
    $topups = $pdo->query("
        SELECT tu.*, au.username AS admin_username, ru.username AS recipient_username
        FROM ewallet_admin_topups tu
        JOIN users au ON au.id = tu.admin_id
        JOIN users ru ON ru.id = tu.recipient_id
        ORDER BY tu.created_at DESC
        LIMIT 100
    ")->fetchAll();

    // 3. Fee credits to admin (from ewallet_ledger)
    $fees = $pdo->query("
        SELECT l.*, t.sender_id, t.recipient_id,
               su.username AS sender_username, ru.username AS recipient_username
        FROM ewallet_ledger l
        JOIN ewallet_transfers t ON t.id = l.reference_id
        JOIN users su ON su.id = t.sender_id
        JOIN users ru ON ru.id = t.recipient_id
        WHERE l.user_id = 1 AND l.ref_type = 'transfer' AND l.type = 'credit'
          AND l.note LIKE '%fee%'
        ORDER BY l.created_at DESC
        LIMIT 100
    ")->fetchAll();

    // 4. Summary stats
    $stats = [
        'total_transfers'     => (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM ewallet_transfers WHERE status='completed'")->fetchColumn(),
        'total_fees'          => (float)$pdo->query("SELECT COALESCE(SUM(fee),0) FROM ewallet_transfers WHERE status='completed'")->fetchColumn(),
        'total_topups'        => (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM ewallet_admin_topups")->fetchColumn(),
        'transfer_count'      => (int)$pdo->query("SELECT COUNT(*) FROM ewallet_transfers WHERE status='completed'")->fetchColumn(),
        'topup_count'         => (int)$pdo->query("SELECT COUNT(*) FROM ewallet_admin_topups")->fetchColumn(),
    ];

    require 'views/admin/ewallet_monitor.php';
}
```

### 6.2 View Layout

```
┌─────────────────────────────────────────────────────────────┐
│  📊 E-Wallet Monitoring                                      │
├─────────────────────────────────────────────────────────────┤
│  [💱 Transfers] [💰 Top-Ups] [💸 Fee Credits]               │
├─────────────────────────────────────────────────────────────┤
│  Stat Cards:                                                 │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐          │
│  │ Total Sent  │ │ Total Fees  │ │ Total Top-Ups│          │
│  │ ₱XXX,XXX    │ │ ₱X,XXX      │ │ ₱XXX,XXX     │          │
│  └─────────────┘ └─────────────┘ └─────────────┘          │
├─────────────────────────────────────────────────────────────┤
│  Table (active tab):                                         │
│  Date | Type | From | To | Amount | Fee | Note | Admin      │
└─────────────────────────────────────────────────────────────┘
```

---

## 7. Sidebar Links

### Member Sidebar (`views/partials/sidebar_member.php`)
```php
['page' => 'ewallet_transfer', 'icon' => '💱', 'label' => 'Send Money', 'pages' => ['ewallet_transfer']],
```
Place under **Account** section, before or after Payouts.

### Admin Sidebar (`views/partials/sidebar_admin.php`)

Under **Finance**:
```php
<a href="<?= APP_URL ?>/?page=ewallet_transfer" class="nav-item-link <?= $cp === 'ewallet_transfer' ? 'active' : '' ?>">
  <span class="nav-icon">💱</span> E-Wallet Transfer
</a>
<a href="<?= APP_URL ?>/?page=admin_ewallet_topup" class="nav-item-link <?= $cp === 'admin_ewallet_topup' ? 'active' : '' ?>">
  <span class="nav-icon">💰</span> Top-Up Member
</a>
<a href="<?= APP_URL ?>/?page=admin_ewallet_monitor" class="nav-item-link <?= $cp === 'admin_ewallet_monitor' ? 'active' : '' ?>">
  <span class="nav-icon">📊</span> E-Wallet Monitor
</a>
```

---

## 8. Files to Modify / Create

| File | Action | Description |
|------|--------|-------------|
| `migrations/006_add_ewallet_transfer.sql` | **Create** | `ewallet_transfers`, `ewallet_admin_topups`, `ref_type` ENUM update |
| `install.sql` | **Modify** | Add both tables + ENUM update |
| `index.php` | **Modify** | 4 new routes |
| `models/Ewallet.php` | **Modify** | `transfer()`, `adminTopUp()` |
| `controllers/MemberController.php` | **Modify** | `ewalletTransfer()`, `doEwalletTransfer()` |
| `controllers/AdminController.php` | **Modify** | `ewalletTopUp()`, `doEwalletTopUp()`, `ewalletMonitor()` |
| `views/member/ewallet_transfer.php` | **Create** | Transfer form + history |
| `views/admin/ewallet_topup.php` | **Create** | Admin top-up form |
| `views/admin/ewallet_monitor.php` | **Create** | Monitoring dashboard with tabs |
| `views/admin/settings.php` | **Modify** | Transfer fee, min, daily, weekly limits |
| `views/partials/sidebar_member.php` | **Modify** | 💱 Send Money link |
| `views/partials/sidebar_admin.php` | **Modify** | 💱 Transfer, 💰 Top-Up, 📊 Monitor links |
| `views/admin/user_view.php` | **Modify** | 5th tab: 💱 Transfers |
| `reset.php` | **Modify** | Truncate `ewallet_transfers` and `ewallet_admin_topups` |

---

## 9. QA Test Cases

1. **Member → Member transfer** with fee=₱10, min=₱50. Sender debited ₱110, recipient +₱100, admin +₱10.
2. **Admin → Member transfer** — no fee, no limits.
3. **Member → Admin transfer** — allowed, fee applies if member sender.
4. **Below minimum** — blocked.
5. **Exceeds daily limit** — blocked.
6. **Exceeds weekly limit** — blocked.
7. **Wrong password** — blocked before any DB change.
8. **Self-transfer** — blocked.
9. **Insufficient balance** — blocked atomically.
10. **Admin top-up** — recipient credited, no source debit, logged in topups table.
11. **Monitoring page** — shows all 3 categories with correct totals.
12. **Concurrent transfers** — row lock prevents double-spend.
