# AltasLive MLM Binary System

A custom PHP 8.1+ MLM binary-network platform with commission engines, lifetime income capping, daily fixed income, e-wallet, and a full admin panel. Built on vanilla PHP with MySQL 8.0+ via PDO, Apache `mod_rewrite`, and Bootstrap 5. No frameworks.

---

## Table of Contents

- [System Overview](#system-overview)
- [Features](#features)
- [Technology Stack](#technology-stack)
- [Requirements](#requirements)
- [Installation](#installation)
- [Architecture](#architecture)
- [How Commissions Work](#how-commissions-work)
- [Commission Flow & CD Split](#commission-flow--cd-split)
- [Lifetime Income Cap](#lifetime-income-cap)
- [Daily Fixed Income (DFI)](#daily-fixed-income-dfi)
- [Reactivation](#reactivation)
- [E-Wallet](#e-wallet)
- [Package System](#package-system)
- [Superadmin / Impersonation](#superadmin--impersonation)
- [Cron Jobs](#cron-jobs)
- [Admin Panel](#admin-panel)
- [Member Panel](#member-panel)
- [Security](#security)
- [File Structure](#file-structure)
- [Default Credentials](#default-credentials)

---

## System Overview

AltasLive is a **binary-tree MLM platform** where each member occupies a node in a binary tree (left/right legs). Members earn income through:

1. **Binary Pairing Bonuses** — matched volume from left/right legs
2. **Direct Referral Bonuses** — immediate bonus for direct recruits
3. **Unilevel Indirect Referral Bonuses** — generational bonuses up the sponsor chain (10 levels)
4. **Daily Fixed Income (DFI)** — passive daily payouts on eligible packages

All earnings pass through a **Commission-Deduct (CD) split** and a **lifetime income cap** before reaching the e-wallet.

---

## Features

### Member Features
- **Dashboard** — earnings overview, wallet balance, cap status, genealogy summary, recent activity
- **Binary Genealogy Tree** — interactive D3.js tree visualization with zoom/pan, node details modal
- **Earnings History** — paginated commission history filtered by type
- **E-Wallet** — balance tracking, withdrawable vs. total balance, full ledger
- **E-Wallet Transfer** — send funds to other members with fee and daily/weekly limits
- **Payout Request** — request withdrawal via GCash, Maya, USDT (TRC20/BEP20), bank transfer
- **Cap Status Page** — lifetime cap progress bar, remaining earnings, cap history
- **DFI History** — daily fixed income payout log with day counter
- **Profile Management** — edit name, contact, bank/e-wallet details, profile photo upload
- **Package Upgrade** — upgrade to a higher package with code-based or e-wallet payment
- **Account Reactivation** — reactivate capped accounts via e-wallet or external payment (GCash/Maya/USDT)
- **Activation Page** — activate pending accounts via registration code

### Admin Features
- **Admin Dashboard** — total members, active/capped/permanent counts, DFI stats, commissions today, revenue
- **User Management** — list, search, view, toggle status, deactivate users; VIP/daily-cap bypass toggles
- **Package Management** — create/edit packages with full commission configuration
- **Registration Codes** — generate batch codes, export to CSV, per-package tracking
- **Payout Management** — review pending payout requests, approve/reject with notes
- **Cap Monitor** — global cap status distribution, reactivation stats
- **DFI Admin** — daily fixed income statistics and member tracking
- **Reactivation Management** — review/confirm/reject pending reactivation requests
- **E-Wallet Monitor** — admin top-up, transfer monitoring, ledger overview
- **E-Wallet Top-Up** — credit any member's e-wallet directly
- **Commission-Deduct (CD) Management** — assign, complete, cancel, edit CD targets
- **Settings** — site name, maintenance mode, seat limit, transfer limits, fees, cron management, manual reset
- **Superadmin S-Login** — impersonate any member account for support/troubleshooting

### Registration & Onboarding
- **3-Step Registration** — validate code → choose upline & position → fill personal details
- **AJAX Validation** — real-time username check, code validation, upline lookup
- **Seat Limit** — configurable maximum member count; blocks registration when reached
- **Maintenance Mode** — toggle-able maintenance page with contact info

---

## Technology Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.1+ (vanilla, no framework) |
| Database | MySQL 8.0+ via PDO |
| Frontend | Bootstrap 5, Font Awesome 6, D3.js (genealogy tree) |
| Web Server | Apache with `mod_rewrite` |
| Auth | Session-based with CSRF tokens, bcrypt hashing (cost 12) |
| Currency | Philippine Peso (₱), USDT TRC20/BEP20 support |
| Timezone | Asia/Manila |

---

## Requirements

- PHP 8.1+
- MySQL 8.0+
- Apache with `mod_rewrite` enabled
- Cron access (for midnight reset and DFI payouts)

---

## Installation

### 1. Place files

Copy the project to your web root:

```
/var/www/html/altaslive/     ← Apache
htdocs/altaslive/            ← XAMPP/WAMP
```

### 2. Create the database

```bash
mysql -u root -p -e "CREATE DATABASE altaslive CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p altaslive < install.sql
```

For existing databases, run any pending migrations:

```bash
mysql -u root -p altaslive < migrate_usdt_bep20.sql
mysql -u root -p altaslive < migrate_superadmin.sql
mysql -u root -p altaslive < migrate_deactivated_status.sql
mysql -u root -p altaslive < migrate_package_toggles.sql
mysql -u root -p altaslive < migrate_paid_leg_counts.sql
mysql -u root -p altaslive < migrate_pair_volume.sql
```

### 3. Configure database

Edit `config/db.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'altaslive');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('APP_URL',  'http://yourdomain.com/altaslive');
define('APP_ENV',  'production');  // or 'development'
```

### 4. Set permissions

```bash
chmod 755 uploads/ cron/logs/ logs/
```

### 5. Enable Apache mod_rewrite

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

Ensure `AllowOverride All` is set in your Apache VirtualHost config.

### 6. Configure cron jobs

```bash
crontab -e

# Midnight reset — DFI payout, daily pair cap reset, capped user expiry
0 0 * * * /usr/bin/php /var/www/html/altaslive/cron/midnight_reset.php >> /var/www/html/altaslive/cron/logs/reset_$(date +\%Y-\%m).log 2>&1

# Fund transfer limit reset (daily + weekly on Mondays)
0 0 * * * /usr/bin/php /var/www/html/altaslive/cron/fund_transfer_limit_reset.php
```

---

## Architecture

```
index.php                    ← Front controller (all requests route through here)
├── config/db.php            ← DB credentials, APP_URL, APP_ENV, timezone
├── core/
│   ├── Auth.php             ← Session management, login/logout, impersonation
│   ├── helpers.php          ← Utility functions (fmt_money, csrf, paginate, etc.)
│   ├── Commission.php       ← Binary placement, direct/indirect referrals, pairing bonuses
│   ├── CapEngine.php        ← Lifetime income cap checking and enforcement
│   ├── DailyFixedIncome.php ← DFI payout engine
│   └── Reactivation.php     ← Capped account reactivation flows
├── models/
│   ├── User.php             ← User CRUD, package assignment
│   ├── Package.php          ← Package management, indirect referral levels
│   ├── Code.php             ← Registration code generation/validation
│   ├── Ewallet.php          ← E-wallet credit/debit/transfer/ledger
│   ├── Payout.php           ← Payout request management
│   ├── CdStatus.php         ← Commission-Deduct bucket management
│   └── ImpLog.php           ← Superadmin impersonation audit log
├── controllers/
│   ├── AuthController.php   ← Login, registration, code validation, super-login
│   ├── MemberController.php ← Dashboard, genealogy, earnings, wallet, payout, cap, DFI, upgrade
│   └── AdminController.php  ← All admin pages and actions
├── views/
│   ├── auth/                ← login, register, register_closed, slogin
│   ├── member/              ← dashboard, genealogy, earnings, profile, payout, cap_status, dfi_history, reactivate, activate, upgrade, ewallet_transfer
│   ├── admin/               ← dashboard, users, user_view, packages, codes, payouts, cap_monitor, dfi_admin, reactivations, ewallet_monitor, ewallet_topup, settings
│   └── partials/            ← head, topbar, sidebar_member, sidebar_admin, footer, settings_offcanvas
├── cron/
│   ├── midnight_reset.php   ← DFI payout, cap expiry, daily pair reset
│   ├── fund_transfer_limit_reset.php ← Daily/weekly transfer limit reset
│   └── test_cron.php        ← Cron verification tool
├── frontend/index.php       ← Landing page (public-facing)
├── sim/index.php            ← Binary profit simulator
├── install.sql              ← Full database schema + seed data
└── reset.php                ← DEV UTILITY — wipes member data (remove in production)
```

---

## How Commissions Work

| Event | Commission Type | Recipient | Timing |
|-------|----------------|-----------|--------|
| New paid member registers | Direct Referral | Sponsor | Instant |
| New paid member registers | Indirect Referral (up to 10 levels) | Upline sponsors via sponsor chain | Instant |
| New paid member placed in binary tree | Binary Pairing Bonus | Each ancestor with matched volume | Instant |
| Daily cron runs | Daily Fixed Income (DFI) | Eligible active members | Daily at midnight |
| Daily pair cap exceeded | Pairing flush | Nobody — volume permanently lost | Instant |

**Key rules:**
- CD-sourced or pending members **increment leg counts** but **do not trigger** any commissions
- Capped/permanently inactive members earn nothing and are skipped in pairing
- Deactivated members earn nothing
- All commissions pass through CD split → lifetime cap → e-wallet

---

## Commission Flow & CD Split

Every commission follows this pipeline:

```
Commission Amount
    │
    ▼
┌─────────────────────┐
│  CD Split (Source)   │  ← Some portion fills the Commission-Deduct bucket
│  CdStatus::fillBucket()│
└────────┬────────────┘
         │ wallet portion
         ▼
┌─────────────────────┐
│  Lifetime Cap Check  │  ← CapEngine::canEarn() checks against lifetime cap
│  CapEngine::canEarn()│
└────────┬────────────┘
         │ allowed amount
         ▼
┌─────────────────────┐
│  E-Wallet Credit     │  ← Only the allowed portion reaches the wallet
│  Ewallet::credit()   │
└─────────────────────┘
```

Any amounts blocked by CD or cap are recorded in the `commissions` table with status `flushed` for audit.

---

## Lifetime Income Cap

Each package defines a **lifetime cap** = `entry_fee × lifetime_cap_multiplier`.

| State | Meaning | Behavior |
|-------|---------|----------|
| `active` | Under cap, earning | All commissions processed normally |
| `capped` | Cap reached | Earning stops; DFI paused; within reactivation window |
| `perminact` | Window expired | No reactivation possible; permanently done |

**VIP bypass:** `capping_bypass` column on `users` — allows unlimited lifetime earnings.

**Daily cap bypass:** `daily_cap_bypass` column — allows unlimited pairing bonus per day.

---

## Daily Fixed Income (DFI)

Eligible members receive a fixed daily payout for a configured number of days.

**Eligibility criteria:**
- `role = 'member'`, `status = 'active'`, `cap_status = 'active'`
- `dfi_active = 1`, `cd_active = 0`
- `dfi_days_used < package.daily_fixed_income_days`
- `package.daily_fixed_income > 0` and `package.dfi_enabled = 1`

**DFI & Cap interaction:**
- Active, under cap → full DFI paid, counts toward lifetime cap
- Near cap → partial DFI paid, cap triggered
- Capped → DFI skipped, days counter paused
- Reactivated → `dfi_days_used` resets to 0, fresh cycle starts

---

## Reactivation

When a member hits their lifetime cap, they can reactivate within a configurable window.

| Payment Method | Flow |
|---------------|------|
| E-Wallet | Immediate debit → account reset |
| GCash / Maya / USDT TRC20 / USDT BEP20 | Upload proof → pending → admin confirms → reset |

**Reactivation resets:** `lifetime_earned` to 0, `cap_status` to `active`, `dfi_days_used` to 0, `dfi_active` to 1.

---

## E-Wallet

Each user has two balances:
- **`ewallet_balance`** — total e-wallet funds
- **`withdrawable_balance`** — subset available for external withdrawal

| Operation | Balance Behavior |
|-----------|-----------------|
| Commission credit | Both balances increment |
| External transfer | Both balances decrement (non-withdrawable spent first) |
| Internal debit (reactivation) | Both balances decrement |
| Admin top-up | Only total balance increments (non-withdrawable) |

**Transfer limits:** Configurable daily and weekly limits for members; admins transfer for free.

---

## Package System

Packages define the commission structure for each member tier:

- `entry_fee` — registration/upgrade cost
- `pairing_bonus` — peso amount per matched pair
- `daily_pair_cap` — max pairs payable per day
- `direct_ref_bonus` — direct referral bonus amount
- `daily_fixed_income` — DFI daily payout amount
- `daily_fixed_income_days` — how many days DFI runs
- `lifetime_cap_multiplier` — cap = entry_fee × this value
- `reactivation_fee` — fee to reactivate after cap
- `reactivation_window_days` — days to reactivate before permanent
- `indirect_referral` — unilevel generational bonus levels (up to 10)
- `pairing_enabled`, `dfi_enabled` — per-package feature toggles

---

## Superadmin / Impersonation

Superadmins can impersonate any member account via **S-Login**:

- Opens a separate browser tab with a URL-based session token (`?imp=<nonce>`)
- Does not disturb the superadmin's own session
- IP and User-Agent locked for security
- 8-hour session TTL
- All actions logged to `imp_logs` table
- Superadmin wallet operations are attributed to the primary admin account

---

## Cron Jobs

### Midnight Reset (`cron/midnight_reset.php`)

Runs daily at midnight (Asia/Manila):
1. **DFI Payout** — processes daily fixed income for all eligible members
2. **Cap Expiry** — moves capped members past their reactivation window to `perminact`
3. **Daily Pair Reset** — resets `pairs_volume_today = 0` for all members

### Fund Transfer Limit Reset (`cron/fund_transfer_limit_reset.php`)

- **Daily:** resets `ewallet_sent_today = 0`
- **Weekly (Mondays):** resets `ewallet_sent_this_week = 0`

Both cron scripts are also accessible via HTTP with a key parameter for environments without CLI cron access.

---

## Admin Panel

| Page | Description |
|------|-------------|
| Dashboard | Overview stats: members, active/capped/permanent, DFI paid, commissions, revenue |
| Users | Paginated member list with search, status toggle, deactivate, VIP/daily-cap bypass |
| User View | Individual member detail: wallet, commissions, genealogy info, CD status |
| Packages | Create/edit packages with full commission config |
| Codes | Generate registration codes in batches, export to CSV |
| Payouts | Review/approve/reject payout requests |
| Cap Monitor | Cap status distribution, reactivation stats |
| DFI Admin | Daily fixed income statistics |
| Reactivations | Review pending reactivation requests, confirm/reject with notes |
| E-Wallet Monitor | Transfer history, ledger overview |
| E-Wallet Top-Up | Credit any member's wallet directly |
| Settings | Site config, maintenance mode, seat limit, transfer limits, manual reset |

---

## Member Panel

| Page | Description |
|------|-------------|
| Dashboard | Earnings summary, wallet balance, cap status, recent activity, genealogy preview |
| Genealogy | Interactive D3.js binary tree with zoom/pan, node details |
| Earnings | Full commission history with type filters and pagination |
| Profile | Edit name, contact info, bank/e-wallet details, profile photo |
| Payout | Request withdrawal, choose payment method, view payout history |
| Cap Status | Lifetime cap progress, remaining earnings, reactivation option |
| DFI History | Daily fixed income payout log |
| Reactivate | Reactivate capped account via e-wallet or external payment |
| Activate | Activate pending account with registration code |
| Upgrade | Upgrade to a higher package |
| E-Wallet Transfer | Send funds to another member |

---

## Security

- **CSRF Protection** — `csrf_token()` / `csrf_verify()` on all POST handlers
- **Password Hashing** — bcrypt with cost factor 12
- **Session Security** — `HttpOnly`, `Strict Mode`, `Secure` (production), session regeneration on login
- **Rate Limiting** — session-based login attempt limiter (5 attempts / 15 min)
- **Input Validation** — username regex, mobile number validation, HTML escaping
- **Apache `.htaccess`** — blocks direct access to `config/`, `core/`, `models/`, `controllers/`, `cron/`, `logs/`
- **Row Locking** — `SELECT ... FOR UPDATE` on e-wallet balance checks for atomicity
- **Impersonation Guards** — IP + User-Agent locking, TTL expiry, audit logging

---

## Default Credentials

| Role | Username | Password |
|------|----------|----------|
| Admin | `admin` | `Admin@1234` |

**Change the admin password immediately after first login.**

---

## License

Proprietary. All rights reserved.
