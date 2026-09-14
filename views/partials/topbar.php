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
    // Superadmins act as an admin proxy — surface the acting admin's wallet.
    $walletUser = Auth::isSuperadmin() ? (User::find(Auth::actingAdminId()) ?: $user) : $user;
}
$topbarBalance = fmt_money(($walletUser ?? $user)['ewallet_balance'] ?? 0);
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
        <?php if ($isMember || Auth::isSuperadmin()): ?>
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

        <div class="dropdown">
            <button class="topbar-balance border-0 dropdown-toggle" type="button"
                data-bs-toggle="dropdown" aria-expanded="false"
                title="<?= e($user['username'] ?? '') ?>" style="cursor:pointer;">
                <span class="bal-label"><?= Auth::isAdmin() ? 'Admin' : 'User' ?></span>
                <span class="bal-amount">@<?= e($user['username'] ?? '') ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm rounded-3 py-1 mt-1" style="min-width:160px;font-size:.82rem;">
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2 py-1.5" href="<?= link_to(Auth::isAdmin() ? 'admin_settings' : 'profile') ?>">
                        <span style="font-size:.95rem;"><?= Auth::isAdmin() ? '⚙️' : '👤' ?></span>
                        <span><?= Auth::isAdmin() ? 'Settings' : 'Profile' ?></span>
                    </a>
                </li>
                <?php if (Auth::isSuperadmin()): ?>
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2 py-1.5" href="<?= link_to('slogin') ?>" target="_blank" rel="noopener">
                        <span style="font-size:.95rem;">⭐</span>
                        <span>Super Login</span>
                    </a>
                </li>
                <?php endif; ?>
                <li>
                    <hr class="dropdown-divider my-1">
                </li>
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2 py-1.5" href="<?= link_to('logout') ?>">
                        <span style="font-size:.95rem;">🚪</span>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </div>

        <?php if (Auth::isAdmin() && ($_GET['page'] ?? '') === 'admin_settings'): ?>
            <button class="btn btn-sm btn-outline-light" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#settingsOffcanvas"
                title="Settings navigation" style="font-size:1.1rem;line-height:1;">
                ⚙️
            </button>
        <?php endif; ?>
    </div>
</div>