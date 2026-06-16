<?php

/**
 * @file   views/partials/sidebar_admin.php
 * @brief  Sidebar for admin pages
 */
?>
<?php
$cp = current_page();
$user = Auth::user();
$initial = strtoupper(substr($user['username'] ?? 'A', 0, 1));

$pendingPayouts = (int)db()->query("SELECT COUNT(*) FROM payout_requests WHERE status='pending'")->fetchColumn();
$pendingMembers = (int)db()->query("SELECT COUNT(*) FROM users WHERE role='member' AND status='pending'")->fetchColumn();

function renderAdminNav($cp, $user, $initial, $pendingPayouts, $pendingMembers)
{ ?>
  <div class="sidebar-brand">
    <div class="brand-icon">
      <img src="<?= APP_URL ?>/assets/img/logo.png" alt="Logo">
    </div>
    <div>
      <div class="brand-name"><?= e(setting('site_name', APP_NAME)) ?></div>
      <div class="brand-sub">Admin Panel</div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section-label">Overview</div>
    <a href="<?= APP_URL ?>/?page=admin" class="nav-item-link <?= $cp === 'admin' ? 'active' : '' ?>">
      <span class="nav-icon">📊</span> Dashboard
    </a>

    <div class="nav-section-label">Management</div>
    <a href="<?= APP_URL ?>/?page=admin_users" class="nav-item-link <?= in_array($cp, ['admin_users', 'admin_user_view']) ? 'active' : '' ?>">
      <span class="nav-icon">👥</span> Members
      <?php if ($pendingMembers): ?><span class="nav-badge"><?= $pendingMembers ?></span><?php endif; ?>
    </a>
    <a href="<?= APP_URL ?>/?page=admin_packages" class="nav-item-link <?= $cp === 'admin_packages' ? 'active' : '' ?>">
      <span class="nav-icon">📦</span> Packages
    </a>
    <a href="<?= APP_URL ?>/?page=admin_codes" class="nav-item-link <?= $cp === 'admin_codes' ? 'active' : '' ?>">
      <span class="nav-icon">🎟️</span> Reg Codes
    </a>
    <a href="<?= APP_URL ?>/?page=register" class="nav-item-link <?= $cp === 'register' ? 'active' : '' ?>">
      <span class="nav-icon">➕</span> Register Member
    </a>

    <div class="nav-section-label">Finance</div>
    <a href="<?= APP_URL ?>/?page=admin_payouts" class="nav-item-link <?= $cp === 'admin_payouts' ? 'active' : '' ?>">
      <span class="nav-icon">💸</span> Payouts
      <?php if ($pendingPayouts): ?><span class="nav-badge"><?= $pendingPayouts ?></span><?php endif; ?>
    </a>
    <a href="<?= APP_URL ?>/?page=ewallet_transfer" class="nav-item-link <?= $cp === 'ewallet_transfer' ? 'active' : '' ?>">
      <span class="nav-icon">💱</span> E-Wallet Transfer
    </a>
    <a href="<?= APP_URL ?>/?page=admin_ewallet_topup" class="nav-item-link <?= $cp === 'admin_ewallet_topup' ? 'active' : '' ?>">
      <span class="nav-icon">💰</span> Top-Up Member
    </a>
    <a href="<?= APP_URL ?>/?page=admin_ewallet_monitor" class="nav-item-link <?= $cp === 'admin_ewallet_monitor' ? 'active' : '' ?>">
      <span class="nav-icon">📊</span> E-Wallet Monitor
    </a>

    <div class="nav-section-label">Monitoring</div>
    <a href="<?= APP_URL ?>/?page=admin_cap_monitor" class="nav-item-link <?= $cp === 'admin_cap_monitor' ? 'active' : '' ?>">
      <span class="nav-icon">🛡️</span> Cap Monitor
    </a>
    <a href="<?= APP_URL ?>/?page=admin_dfi" class="nav-item-link <?= $cp === 'admin_dfi' ? 'active' : '' ?>">
      <span class="nav-icon">📅</span> DFI Admin
    </a>
    <a href="<?= APP_URL ?>/?page=admin_reactivations" class="nav-item-link <?= $cp === 'admin_reactivations' ? 'active' : '' ?>">
      <span class="nav-icon">🔄</span> Reactivation Log
    </a>

    <div class="nav-section-label">System</div>
    <a href="<?= APP_URL ?>/?page=admin_settings" class="nav-item-link <?= $cp === 'admin_settings' ? 'active' : '' ?>">
      <span class="nav-icon">⚙️</span> Settings
    </a>
    <a href="<?= APP_URL ?>/?page=dashboard" class="nav-item-link">
      <span class="nav-icon">👤</span> Member View
    </a>
    <a href="<?= APP_URL ?>/" class="nav-item-link" target="_blank" rel="noopener">
      <span class="nav-icon">🌐</span> View Frontend
    </a>
    <a href="<?= APP_URL ?>/?page=logout" class="nav-item-link">
      <span class="nav-icon">🚪</span> Logout
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="user-avatar" style="background:rgba(59,111,240,.25);color:#8fb4ff;">
        <?= $initial ?>
      </div>
      <div class="user-info">
        <div class="user-name"><?= e($user['full_name'] ?: $user['username']) ?></div>
        <div class="user-role">Administrator</div>
      </div>
    </div>
  </div>
<?php } ?>

<!-- Desktop sidebar -->
<div class="sidebar d-none d-lg-flex flex-column">
  <?php renderAdminNav($cp, $user, $initial, $pendingPayouts, $pendingMembers); ?>
</div>

<!-- Mobile offcanvas -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="mobileSidebar"
  style="width:var(--sidebar-w)!important;background:var(--sidebar-bg)!important;">
  <div class="offcanvas-header" style="padding:.5rem 0 0;border:none;">
    <button type="button" class="btn-close btn-close-white ms-auto me-3 mt-2"
      data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body p-0 d-flex flex-column" style="overflow-y:auto;">
    <?php renderAdminNav($cp, $user, $initial, $pendingPayouts, $pendingMembers); ?>
  </div>
</div>