# MLM Binary System

## Requirements
- PHP 8.1+
- MySQL 8.0+
- Apache with `mod_rewrite` enabled
- (Optional) Cron access for midnight reset

---

## Installation

### 1. Place files
Copy the entire project folder to your web root:
```
/var/www/html/mlm/     ← Apache
htdocs/mlm/            ← XAMPP/WAMP
```

### 2. Create the database
```bash
mysql -u root -p -e "CREATE DATABASE mlm_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p mlm_db < install.sql
```

### 3. Configure database
Edit `config/db.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'mlm_db');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('APP_URL',  'http://yourdomain.com/mlm');
define('APP_ENV',  'production');
```

### 4. Set permissions
```bash
chmod 755 uploads/ exports/ logs/
```

### 5. Configure cron (midnight reset)
```bash
crontab -e
# Add this line:
0 0 * * * /usr/bin/php /var/www/html/mlm/cron/midnight_reset.php >> /var/www/html/mlm/logs/reset.log 2>&1
```

### 6. Enable Apache mod_rewrite
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```
Also ensure `AllowOverride All` is set in your Apache VirtualHost.

---

## First Login

**Admin account (CHANGE PASSWORD IMMEDIATELY):**
- Username: `admin`
- Password: `Admin@1234`

URL: `http://yourdomain.com/mlm/`

---

## How Commissions Work

| Event | What fires | When |
|-------|-----------|------|
| New member registers | Direct referral → sponsor | Instant |
| New member registers | Indirect referral → 10 upline sponsors | Instant |
| New member forms a pair | Pairing bonus → each ancestor in binary tree | Instant |
| Daily cap exceeded | Pairs flushed (lost forever) | Instant |
| **Midnight cron** | Reset `pairs_paid_today = 0` | Daily |

The cron **only resets the daily pair counter**. All money logic is real-time.

---

## File Structure

```
mlm/
├── index.php           ← Front controller / router
├── install.sql         ← Database schema + seed data
├── .htaccess           ← Apache rewrites + security
├── config/db.php       ← Database credentials
├── core/
│   ├── Auth.php        ← Session management
│   ├── Commission.php  ← Real-time commission engine
│   └── helpers.php     ← Utilities (fmt_money, csrf, etc.)
├── models/             ← User, Package, Code, Ewallet, Payout
├── controllers/        ← AuthController, MemberController, AdminController
├── views/
│   ├── auth/           ← login.php, register.php
│   ├── member/         ← dashboard, earnings, genealogy, profile, payout
│   ├── admin/          ← (Phase 5)
│   └── partials/       ← sidebar, topbar, head
├── assets/css/         ← main, auth, layout, components
├── cron/midnight_reset.php
├── uploads/            ← Profile photos (chmod 755)
└── logs/               ← Cron logs (chmod 755)
```

---

## Admin Quick Tasks

**Generate registration codes:**
Admin → Reg Codes → Generate Codes → Select package, quantity, price → Generate

**Process a payout:**
Admin → Payouts → [Approve] → Send GCash manually → [Mark Complete]

**Manually reset daily pair cap (testing):**
Admin → Settings → "Run Daily Reset Now"

---

## Phase Progress

- [x] Phase 1 — Database schema, config, core engine, all models
- [x] Phase 2 — Login, 3-step registration, AJAX validation
- [x] Phase 3 — Member dashboard (all 5 pages)
- [x] Phase 4 — Midnight reset cron
- [ ] Phase 5 — Admin dashboard (all pages)
- [ ] Phase 6 — Security hardening, email, polish
