<?php

/**
 * @file   views/partials/topbar.php
 * @brief  Topbar for member and admin pages
 */
?>
<?php
// === Load the correct user based on ?id= (for admin view) ===
if (isset($_GET['page']) && $_GET['page'] === 'admin_user_view' && !empty($_GET['id']) && Auth::isAdmin()) {
  $user = getUserById((int)$_GET['id']);

  if (!$user) {
    flash('error', 'User not found.');
    redirect('/?page=admin_users');
  }
} else {
  // Fallback: should not happen if routed correctly
  $user = Auth::user();
}
$topbarBalance = fmt_money($user['ewallet_balance'] ?? 0);
$initials      = strtoupper(substr($user['username'] ?? 'U', 0, 1));
$isMember      = ($user['role'] ?? '') === 'member';
?>
<div class="topbar-wrapper no-print">
  <!-- Hamburger (mobile only — triggers offcanvas) -->
  <button class="btn btn-sm btn-light d-lg-none me-1 border-0"
    type="button"
    data-bs-toggle="offcanvas"
    data-bs-target="#mobileSidebar"
    aria-controls="mobileSidebar"
    style="font-size:1.2rem;padding:.3rem .55rem;">
    ☰
  </button>

  <div class="topbar-title"><?= e($pageTitle ?? 'Dashboard') ?></div>

  <div class="d-flex align-items-center gap-2">
    <?php if ($isMember): ?>
      <div class="topbar-balance d-none d-sm-flex">
        <span class="bal-label">Balance</span>
        <span class="bal-amount" id="topbarBalance"><?= $topbarBalance ?></span>
      </div>
      <?php if (!empty($user['cd_active'])): ?>
        <div class="topbar-balance" style="background:linear-gradient(135deg,#fef3c7,#fffbeb);border-color:rgba(245,158,11,0.3);">
          <span class="bal-label" style="color:#d97706;">CD</span>
          <span class="bal-amount" style="color:#d97706;font-size:.8rem;">⏳ Active</span>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <a href="<?= APP_URL ?>/?page=<?= Auth::isAdmin() ? 'admin' : 'profile' ?>"
      class="topbar-balance" title="<?= e($user['username'] ?? '') ?>" style="text-decoration:none;">
      <span class="bal-label"><?= Auth::isAdmin() ? 'Admin' : 'User' ?></span>
      <span class="bal-amount">@<?= e($user['username'] ?? '') ?></span>
    </a>
  </div>
</div>