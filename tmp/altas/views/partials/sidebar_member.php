<?php

/**
 * @file   views/partials/sidebar_member.php
 * @brief  Sidebar for member pages
 */
?>
<?php
$cp      = current_page();
$user    = Auth::user();
$initial = strtoupper(substr($user['username'] ?? 'U', 0, 1));
$name    = $user['full_name'] ?: ('@' . $user['username']);
$view    = $_GET['view'] ?? '';

// Compute cart badge count
$cartBadge = 0;
if (class_exists('Cart') && Auth::check()) {
    $_cart = Cart::getOrCreate(Auth::id());
    if ($_cart && !empty($_cart['id'])) {
        $cartItems = Cart::getItems((int)$_cart['id']);
        $cartBadge = empty($cartItems) ? 0 : (int)array_sum(array_column($cartItems, 'quantity'));
    }
}

// Build nav items
$nav = [
  ['page' => 'dashboard', 'icon' => '🏠', 'label' => 'Dashboard',        'pages' => ['dashboard']],
  ['page' => 'earnings',  'icon' => '💰', 'label' => 'Earnings',         'pages' => ['earnings']],
  ['page' => 'repeat_purchases', 'icon' => '🛒', 'label' => 'Repeat Purchases', 'pages' => ['repeat_purchases']],
  ['page' => 'cart', 'icon' => '🛍️', 'label' => 'Cart', 'pages' => ['cart'], 'badge' => $cartBadge],
  ['page' => 'cap_status', 'icon' => '🛡️', 'label' => 'Lifetime Cap',     'pages' => ['cap_status']],
  ...(setting('dfi_enabled', '1') === '1' ? [
    ['page' => 'dfi_history', 'icon' => '📅', 'label' => 'DFI History', 'pages' => ['dfi_history']],
  ] : []),
  'SEPARATOR:Network',
  ...(setting('binary_enabled', '1') === '1' ? [
    ['page' => 'genealogy&view=binary',  'icon' => '🌳', 'label' => 'Binary Tree',      'pages' => ['genealogy'], 'view' => 'binary'],
  ] : []),
  ['page' => 'genealogy&view=referral', 'icon' => '👥', 'label' => 'Referral Network', 'pages' => ['genealogy'], 'view' => 'referral'],
  ...(setting('unilevel_product_enabled', '0') === '1' ? [
    ['page' => 'genealogy&view=product_unilevel', 'icon' => '📦', 'label' => 'Unilevel Network', 'pages' => ['genealogy'], 'view' => 'product_unilevel'],
  ] : []),
  ...(setting('royalty_enabled', '0') === '1' ? [
    ['page' => 'member_royalty', 'icon' => '⭐', 'label' => 'Royalty Bonus', 'pages' => ['member_royalty']],
  ] : []),
  'SEPARATOR:Account',
  ['page' => 'register&sponsor=' . $user['username'], 'icon' => '➕', 'label' => 'Register Member', 'pages' => ['register']],
  ['page' => 'ewallet_transfer', 'icon' => '💱', 'label' => 'Send Money', 'pages' => ['ewallet_transfer']],
  ['page' => 'payout',  'icon' => '💳', 'label' => 'Payouts',   'pages' => ['payout']],
  ['page' => 'profile', 'icon' => '⚙️', 'label' => 'Profile & Settings', 'pages' => ['profile']],
];

// Add Admin View link if the logged-in user is an admin browsing as member
if (Auth::isAdmin()) {
  $nav[] = 'SEPARATOR:Admin';
  $nav[] = ['page' => 'admin', 'icon' => '📊', 'label' => 'Admin View', 'pages' => []];
}

$nav[] = 'SEPARATOR:Site';
$nav[] = ['page' => '__frontend__', 'icon' => '🌐', 'label' => 'View Frontend', 'pages' => []];

function memberNavActive($item, $cp, $view)
{
  if (!isset($item['pages'])) return false;
  if (!in_array($cp, $item['pages'])) return false;
  if (isset($item['view'])) return $view === $item['view'];
  return true;
}

function renderSidebarNav($nav, $cp, $user, $view, $initial, $name)
{ ?>
  <div class="sidebar-brand">
    <div class="brand-icon">
      <img src="<?= APP_URL ?>/assets/img/logo.png" alt="Logo">
    </div>
    <div>
      <div class="brand-name"><?= e(setting('site_name', APP_NAME)) ?></div>
      <div class="brand-sub">Member Portal</div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <?php foreach ($nav as $item):
      if (is_string($item)) {
        echo '<div class="nav-section-label">' . e(substr($item, 10)) . '</div>';
        continue;
      }
      $active = memberNavActive($item, $cp, $view);
      $href   = $item['page'] === '__frontend__'
        ? APP_URL . '/'
        : APP_URL . '/?page=' . $item['page'];
      $target = $item['page'] === '__frontend__' ? ' target="_blank" rel="noopener"' : '';
    ?>
      <a href="<?= $href ?>" <?= $target ?> class="nav-item-link <?= $active ? 'active' : '' ?>">
        <span class="nav-icon"><?= $item['icon'] ?></span>
        <?= e($item['label']) ?>
        <?php if (!empty($item['badge'])): ?>
          <span class="badge rounded-pill ms-auto" style="font-size:.65rem;background:#dc3545;color:#fff;min-width:1.4rem;text-align:center;"><?= (int)$item['badge'] ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
    <a href="<?= APP_URL ?>/?page=logout" class="nav-item-link">
      <span class="nav-icon">🚪</span> Logout
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="user-avatar">
        <?php if (!empty($user['photo'])): ?>
          <img src="<?= APP_URL ?>/uploads/<?= e($user['photo']) ?>" alt="">
        <?php else: ?>
          <?= $initial ?>
        <?php endif; ?>
      </div>
      <div class="user-info">
        <div class="user-name"><?= e($name) ?></div>
        <div class="user-role"><?= e($user['package_name'] ?? 'Member') ?></div>
      </div>
    </div>
  </div>
<?php } ?>

<!-- Desktop sidebar (hidden on <lg) -->
<div class="sidebar d-none d-lg-flex flex-column">
  <?php renderSidebarNav($nav, $cp, $user, $view, $initial, $name); ?>
</div>

<!-- Mobile offcanvas sidebar -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel"
  style="width:var(--sidebar-w)!important;background:var(--sidebar-bg)!important;">
  <div class="offcanvas-header d-flex align-items-center" style="padding:.5rem 0 0;border:none;">
    <button type="button" class="btn-close btn-close-white ms-auto me-3 mt-2"
      data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body p-0 d-flex flex-column" style="overflow-y:auto;">
    <?php renderSidebarNav($nav, $cp, $user, $view, $initial, $name); ?>
  </div>
</div>