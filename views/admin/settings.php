<?php

/**
 * @file   views/admin/settings.php
 * @brief  System settings UI — vertical tab layout
 */
?>
<?php $pageTitle = 'System Settings'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<style>
    .tab-pane {
        display: none;
    }

    .tab-pane.show.active {
        display: block;
    }

    .tab-pane .card {
        margin-bottom: 0;
    }
</style>
<div class="main-content">
    <?php require 'views/partials/topbar.php'; ?>
    <div class="page-content">
        <?= render_flash() ?>

        <!-- Hidden external forms (referenced via form="" attribute) -->
        <form method="POST" action="<?= APP_URL ?>/?page=save_profile" id="changePasswordForm" class="d-none"><?= csrf_field() ?></form>
        <form method="POST" action="<?= APP_URL ?>/?page=admin_manual_reset" id="manualResetForm" class="d-none"><?= csrf_field() ?></form>

        <div class="tab-content">

            <!-- ════════════════════════════════════════════
             TAB 1 — SITE BASICS
             ════════════════════════════════════════════ -->
            <div class="tab-pane fade show active" id="tabPane-basics" role="tabpanel">
                <form method="POST" action="<?= APP_URL ?>/?page=admin_save_settings">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="basics">
                    <div class="card">
                        <div class="card-header d-flex align-items-center gap-2">
                            <span style="width:28px;height:28px;background:var(--primary-light);border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">🌐</span>
                            <span class="card-title">Site Basics</span>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Site Name</label>
                                <input type="text" name="site_name" class="form-control" value="<?= e(setting('site_name')) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Site Tagline</label>
                                <input type="text" name="site_tagline" class="form-control" value="<?= e(setting('site_tagline')) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Contact Email</label>
                                <input type="email" name="contact_email" class="form-control" value="<?= e(setting('contact_email')) ?>">
                            </div>
                            <div class="mb-0">
                                <label class="form-label">Minimum Payout (₱)</label>
                                <input type="number" name="min_payout" class="form-control" min="0" step="0.01" value="<?= e(setting('min_payout', '500')) ?>">
                                <div class="form-text">Members cannot request below this amount</div>
                            </div>
                        </div>
                        <div class="card-footer border-top-0 pt-0">
                            <button type="submit" class="btn btn-primary w-100">💾 Save Settings</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ════════════════════════════════════════════
             TAB 2 — MAINTENANCE & SECURITY
             ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabPane-maint" role="tabpanel">
                <form method="POST" action="<?= APP_URL ?>/?page=admin_save_settings">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="maint">
                    <div class="card">
                        <div class="card-header d-flex align-items-center gap-2">
                            <span style="width:28px;height:28px;background:#fff7ed;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">🛡️</span>
                            <span class="card-title">Maintenance & Security</span>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Maintenance Mode</label>
                                <select name="maintenance_mode" class="form-select">
                                    <option value="0" <?= setting('maintenance_mode') === '0' ? 'selected' : '' ?>>🟢 Off — Site is live</option>
                                    <option value="1" <?= setting('maintenance_mode') === '1' ? 'selected' : '' ?>>🔴 On — Members see maintenance page</option>
                                </select>
                            </div>
                            <div class="mb-0">
                                <label class="form-label">🪑 Seat Limit</label>
                                <input type="number" name="seat_limit" class="form-control" min="1" step="1" value="<?= e(setting('seat_limit', '1000')) ?>">
                                <div class="form-text d-flex justify-content-between">
                                    <span>Maximum member accounts allowed</span>
                                    <span class="font-mono" style="font-size:.75rem;">
                                        <?= User::counts()['total'] ?? 0 ?> / <?= e(setting('seat_limit', '1000')) ?> seats
                                        <?php $remaining = seatsRemaining(); ?>
                                        <span class="badge <?= $remaining <= 50 ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' ?>" style="font-size:.7rem;margin-left:.25rem;">
                                            <?= $remaining ?> left
                                        </span>
                                    </span>
                                </div>
                            </div>
                            <div class="mb-0 mt-3">
                                <label class="form-label">🆓 Free Registration</label>
                                <select name="free_registration_enabled" class="form-select">
                                    <option value="1" <?= setting('free_registration_enabled', '1') === '1' ? 'selected' : '' ?>>🟢 On — Anyone can start free (pending) registration</option>
                                    <option value="0" <?= setting('free_registration_enabled', '1') !== '1' ? 'selected' : '' ?>>🔴 Off — Free registration disabled; codes/e-wallet only</option>
                                </select>
                                <div class="form-text">
                                    When Off, free/pending signups are blocked. Referral links then require immediate payment via a registration code or e-wallet.
                                </div>
                            </div>
                        </div>
                        <div class="card-footer border-top-0 pt-0">
                            <button type="submit" class="btn btn-primary w-100">💾 Save Settings</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ════════════════════════════════════════════
             TAB 3 — PAYMENTS
             ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabPane-payments" role="tabpanel">
                <form method="POST" action="<?= APP_URL ?>/?page=admin_save_settings">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="payments">
                    <div class="card">
                        <div class="card-header d-flex align-items-center gap-2">
                            <span style="width:28px;height:28px;background:#fef3c7;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">🔄</span>
                            <span class="card-title">Payments</span>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label" style="font-size:.8rem;font-weight:600;">Reactivation Payment Methods</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="reactivation_ewallet_enabled" id="reEwEnabled" value="1" <?= setting('reactivation_ewallet_enabled', '1') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="reEwEnabled">E-Wallet (deduct balance immediately)</label>
                                </div>
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" name="reactivation_external_enabled" id="reExtEnabled" value="1" <?= setting('reactivation_external_enabled', '1') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="reExtEnabled">External (GCash / Maya / USDT with admin approval)</label>
                                </div>
                            </div>
                            <hr class="my-3">
                            <p class="fw-bold mb-2" style="font-size:.82rem;">🏦 Admin Payment Accounts</p>
                            <div class="form-text mb-3" style="font-size:.75rem;">Members send external reactivation payments to these accounts.</div>
                            <div class="mb-3">
                                <label class="form-label" style="color:#0070d8;font-weight:700;font-size:.75rem;">
                                    <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/3/33/GCash_logo.svg/16px-GCash_logo.svg.png" alt="" style="height:14px;vertical-align:middle;margin-right:.25rem;">GCash Number
                                </label>
                                <input type="tel" name="gcash_number" class="form-control font-mono" placeholder="09XXXXXXXXX" value="<?= e(setting('gcash_number', '')) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="color:#48b0db;font-weight:700;font-size:.75rem;">
                                    <span style="color:#48b0db;margin-right:.25rem;">●</span>Maya Number
                                </label>
                                <input type="tel" name="maya_number" class="form-control font-mono" placeholder="09XXXXXXXXX" value="<?= e(setting('maya_number', '')) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="color:#26a17b;font-weight:700;font-size:.75rem;">₮ USDT TRC20 Address</label>
                                <input type="text" name="usdt_trc20_address" class="form-control font-mono" placeholder="T..." value="<?= e(setting('usdt_trc20_address', '')) ?>">
                                <div class="form-text">TRC20 addresses start with T and are 34 characters</div>
                            </div>
                            <div class="mb-0">
                                <label class="form-label" style="color:#f0b90b;font-weight:700;font-size:.75rem;">₮ USDT BEP20 Address</label>
                                <input type="text" name="usdt_bep20_address" class="form-control font-mono" placeholder="0x..." value="<?= e(setting('usdt_bep20_address', '')) ?>">
                                <div class="form-text">BEP20 addresses start with 0x and are 42 characters</div>
                            </div>
                        </div>
                        <div class="card-footer border-top-0 pt-0">
                            <button type="submit" class="btn btn-primary w-100">💾 Save Settings</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ════════════════════════════════════════════
             TAB 4 — E-WALLET TRANSFERS
             ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabPane-ewallet" role="tabpanel">
                <form method="POST" action="<?= APP_URL ?>/?page=admin_save_settings">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="ewallet">
                    <div class="card">
                        <div class="card-header d-flex align-items-center gap-2">
                            <span style="width:28px;height:28px;background:#eff6ff;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">💱</span>
                            <span class="card-title">E-Wallet Transfers</span>
                        </div>
                        <div class="card-body">
                            <div class="form-text mb-3" style="font-size:.75rem;">Configure member-to-member transfer rules and fees. Admin transfers are always free.</div>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label" style="font-weight:600;font-size:.78rem;">Transfer Fee (₱)</label>
                                    <input type="number" name="ewallet_transfer_fee" class="form-control" min="0" step="0.01" value="<?= e(setting('ewallet_transfer_fee', '0.00')) ?>">
                                    <div class="form-text" style="font-size:.7rem;">Flat fee per member transfer</div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label" style="font-weight:600;font-size:.78rem;">Minimum Transfer (₱)</label>
                                    <input type="number" name="ewallet_min_transfer" class="form-control" min="0" step="0.01" value="<?= e(setting('ewallet_min_transfer', '50.00')) ?>">
                                </div>
                            </div>
                            <div class="row g-2 mb-0">
                                <div class="col-6">
                                    <label class="form-label" style="font-weight:600;font-size:.78rem;">Daily Limit (₱)</label>
                                    <input type="number" name="ewallet_transfer_daily_limit" class="form-control" min="0" step="0.01" value="<?= e(setting('ewallet_transfer_daily_limit', '5000.00')) ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label" style="font-weight:600;font-size:.78rem;">Weekly Limit (₱)</label>
                                    <input type="number" name="ewallet_transfer_weekly_limit" class="form-control" min="0" step="0.01" value="<?= e(setting('ewallet_transfer_weekly_limit', '20000.00')) ?>">
                                </div>
                            </div>
                        </div>
                        <div class="card-footer border-top-0 pt-0">
                            <button type="submit" class="btn btn-primary w-100">💾 Save Settings</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ════════════════════════════════════════════
             TAB 5 — PAYOUT METHODS
             ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabPane-payouts" role="tabpanel">
                <form method="POST" action="<?= APP_URL ?>/?page=admin_save_settings">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="payouts">
                    <div class="card">
                        <div class="card-header d-flex align-items-center gap-2">
                            <span style="width:28px;height:28px;background:#eff6ff;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">💸</span>
                            <span class="card-title">Payout Methods & Fees</span>
                        </div>
                        <div class="card-body">
                            <div class="form-text mb-3" style="font-size:.75rem;">Deducted from requested amount before sending. Set to 0 to disable.</div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:.8rem;font-weight:600;">Available Methods</label>
                                <div class="form-text mb-2" style="font-size:.75rem;">Disable methods to hide them from members. USDT is always enabled.</div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="gcash_enabled" id="gcashEnabled" value="1" <?= setting('gcash_enabled', '1') === '1' ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="gcashEnabled" style="color:#0070d8;font-weight:600;font-size:.8rem;">
                                                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/3/33/GCash_logo.svg/16px-GCash_logo.svg.png" alt="" style="height:14px;vertical-align:middle;margin-right:.25rem;">GCash
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="maya_enabled" id="mayaEnabled" value="1" <?= setting('maya_enabled', '1') === '1' ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="mayaEnabled" style="color:#48b0db;font-weight:600;font-size:.8rem;">
                                                <span style="color:#48b0db;margin-right:.25rem;">●</span>Maya
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-3">
                                    <label class="form-label" style="color:#0070d8;font-weight:700;font-size:.75rem;">GCash %</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" name="service_fee_gcash" class="form-control" min="0" max="100" step="0.01" value="<?= e(setting('service_fee_gcash', '0')) ?>">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <div class="col-3">
                                    <label class="form-label" style="color:#48b0db;font-weight:700;font-size:.75rem;">Maya %</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" name="service_fee_maya" class="form-control" min="0" max="100" step="0.01" value="<?= e(setting('service_fee_maya', '0')) ?>">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <div class="col-3">
                                    <label class="form-label" style="color:#26a17b;font-weight:700;font-size:.75rem;">TRC20 %</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" name="service_fee_usdt_trc20" class="form-control" min="0" max="100" step="0.01" value="<?= e(setting('service_fee_usdt_trc20', '5')) ?>">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <div class="col-3">
                                    <label class="form-label" style="color:#f0b90b;font-weight:700;font-size:.75rem;">BEP20 %</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" name="service_fee_usdt_bep20" class="form-control" min="0" max="100" step="0.01" value="<?= e(setting('service_fee_usdt_bep20', '5')) ?>">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                            </div>
                            <div class="row g-2 mb-0">
                                <div class="col-6">
                                    <label class="form-label" style="color:#26a17b;font-weight:700;font-size:.75rem;">₮ TRC20 Gas Fee</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">USDT</span>
                                        <input type="number" name="usdt_trc20_gas_fee" class="form-control font-mono" min="0" step="0.0001" value="<?= e(setting('usdt_trc20_gas_fee', '2.50')) ?>">
                                    </div>
                                    <div class="form-text" style="font-size:.7rem;">Fixed TRC20 network fee (typically 1–3 USDT)</div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label" style="color:#f0b90b;font-weight:700;font-size:.75rem;">₮ BEP20 Gas Fee</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">USDT</span>
                                        <input type="number" name="usdt_bep20_gas_fee" class="form-control font-mono" min="0" step="0.000001" value="<?= e(setting('usdt_bep20_gas_fee', '0.05')) ?>">
                                    </div>
                                    <div class="form-text" style="font-size:.7rem;">Fixed BEP20 network fee (typically 0.01–0.10 USDT)</div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer border-top-0 pt-0">
                            <button type="submit" class="btn btn-primary w-100">💾 Save Settings</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ════════════════════════════════════════════
             TAB 6 — CHANGE PASSWORD (external form)
             ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabPane-password" role="tabpanel">
                <div class="card">
                    <div class="card-header d-flex align-items-center gap-2">
                        <span style="width:28px;height:28px;background:#fef2f2;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">🔒</span>
                        <span class="card-title">Change Admin Password</span>
                    </div>
                    <div class="card-body">
                        <div class="mb-3"><label class="form-label">Current Password</label>
                            <div class="input-group">
                                <input type="password" name="current_password" id="cur_pw" class="form-control" autocomplete="current-password" form="changePasswordForm">
                                <button type="button" class="btn btn-outline-secondary" onclick="togglePw('cur_pw',this)">👁</button>
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6"><label class="form-label">New Password</label>
                                <div class="input-group">
                                    <input type="password" name="new_password" id="new_pw" class="form-control" minlength="8" autocomplete="new-password" form="changePasswordForm">
                                    <button type="button" class="btn btn-outline-secondary" onclick="togglePw('new_pw',this)">👁</button>
                                </div>
                            </div>
                            <div class="col-md-6"><label class="form-label">Confirm New Password</label>
                                <div class="input-group">
                                    <input type="password" name="new_password_confirm" id="confirm_pw" class="form-control" autocomplete="new-password" form="changePasswordForm">
                                    <button type="button" class="btn btn-outline-secondary" onclick="togglePw('confirm_pw',this)">👁</button>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-outline-primary w-100" form="changePasswordForm">🔒 Update Password</button>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════════
             TAB 7 — DAILY CAP RESET
             ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabPane-reset" role="tabpanel">
                <form method="POST" action="<?= APP_URL ?>/?page=admin_save_settings">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group" value="reset">
                    <div class="card" style="margin-bottom:1rem;">
                        <div class="card-header d-flex align-items-center gap-2">
                            <span style="width:28px;height:28px;background:#eef2ff;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">🎯</span>
                            <span class="card-title">Default Lifetime Cap Multiplier</span>
                        </div>
                        <div class="card-body">
                            <label class="form-label">Default Lifetime Cap Multiplier</label>
                            <input type="number" name="default_cap_multiplier" class="form-control" min="0" step="0.01" value="<?= e(setting('default_cap_multiplier', '3.00')) ?>">
                            <div class="form-text">Lifetime cap = entry fee × multiplier. Default: 3.00</div>
                        </div>
                        <div class="card-footer border-top-0 pt-0">
                            <button type="submit" class="btn btn-primary w-100">💾 Save Settings</button>
                        </div>
                    </div>
                </form>

                <div class="card">
                    <div class="card-header d-flex align-items-center gap-2">
                        <span style="width:28px;height:28px;background:#fef3c7;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">⏱️</span>
                        <span class="card-title">Daily Pair Cap Reset</span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3" style="font-size:.85rem;line-height:1.7;">
                            The midnight cron resets <code>pairs_paid_today = 0</code> and <code>pairs_volume_today = 0</code> for all members, clearing the daily pairing (matched volume) cap so they can earn again tomorrow.
                        </p>
                        <div class="rounded p-3 mb-3" style="background:#f4f6fb;">
                            <div class="text-muted mb-1" style="font-size:.68rem;font-weight:700;letter-spacing:.5px;text-transform:uppercase;">Last Reset</div>
                            <div class="fw-600 font-mono" style="font-size:.875rem;"><?= setting('last_reset') ? fmt_datetime(setting('last_reset')) : 'Never run' ?></div>
                        </div>
                        <div class="rounded p-3 mb-3 font-mono" style="background:#f4f6fb;font-size:.75rem;color:var(--muted);">
                            Crontab:<br><strong style="color:#111;">0 0 * * * php /path/to/site/cron/midnight_reset.php</strong>
                        </div>
                        <?php if (setting('dfi_enabled', '1') === '1'): ?>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="trigger_dfi" id="triggerDfi" value="1" form="manualResetForm">
                                <label class="form-check-label" for="triggerDfi" style="font-size:.8rem;">Also trigger DFI payout now</label>
                            </div>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline-warning w-100"
                            onclick="showConfirm({title:'Run Daily Reset',message:'Reset pairs_paid_today = 0 and pairs_volume_today = 0 for ALL active members now? This simulates the midnight cron.',confirmText:'⟳ Run Reset',confirmClass:'btn-warning',formId:'manualResetForm'})">
                            ⟳ Run Daily Reset Now
                        </button>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════════
             TAB 8 — SYSTEM OVERVIEW (read-only)
             ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabPane-overview" role="tabpanel">
                <div class="card">
                    <div class="card-header d-flex align-items-center gap-2">
                        <span style="width:28px;height:28px;background:#f3f4f6;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">ℹ️</span>
                        <span class="card-title">System Overview</span>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-borderless mb-0" style="font-size:.82rem;">
                            <tr>
                                <td class="ps-3 text-muted" style="width:40%;">PHP Version</td>
                                <td class="font-mono pe-3"><?= PHP_VERSION ?></td>
                            </tr>
                            <tr style="background:#f8fafc;">
                                <td class="ps-3 text-muted">MySQL Version</td>
                                <td class="font-mono pe-3"><?= db()->query('SELECT VERSION()')->fetchColumn() ?></td>
                            </tr>
                            <tr>
                                <td class="ps-3 text-muted">Server Time</td>
                                <td class="font-mono pe-3"><?= date('Y-m-d H:i:s') ?></td>
                            </tr>
                            <tr style="background:#f8fafc;">
                                <td class="ps-3 text-muted">App URL</td>
                                <td class="font-mono pe-3" style="font-size:.72rem;word-break:break-all;"><?= APP_URL ?></td>
                            </tr>
                            <tr>
                                <td class="ps-3 text-muted">Environment</td>
                                <td class="pe-3"><span class="badge <?= APP_ENV === 'production' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' ?>" style="font-size:.72rem;"><?= APP_ENV ?></span></td>
                            </tr>
                            <tr style="background:#f8fafc;">
                                <td class="ps-3 text-muted">Members</td>
                                <td class="pe-3 font-mono"><?= User::counts()['total'] ?? 0 ?> / <?= e(setting('seat_limit', '1000')) ?></td>
                            </tr>
                            <tr>
                                <td class="ps-3 text-muted">Binary Status</td>
                                <td class="pe-3"><?php if (setting('binary_enabled', '1') === '1'): ?><span class="badge bg-success-subtle text-success" style="font-size:.72rem;">🟢 Enabled</span><?php else: ?><span class="badge bg-secondary-subtle text-secondary" style="font-size:.72rem;">⚪ Disabled</span><?php endif; ?></td>
                            </tr>
                            <tr style="background:#f8fafc;">
                                <td class="ps-3 text-muted">Maintenance</td>
                                <td class="pe-3"><?php if (setting('maintenance_mode') === '1'): ?><span class="badge bg-danger-subtle text-danger" style="font-size:.72rem;">🔴 On</span><?php else: ?><span class="badge bg-success-subtle text-success" style="font-size:.72rem;">🟢 Off</span><?php endif; ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

        </div><!-- /tab-content -->
    </div>
</div>

<script>
    function togglePw(id, btn) {
        var el = document.getElementById(id);
        el.type = el.type === 'password' ? 'text' : 'password';
        btn.textContent = el.type === 'password' ? '👁' : '🙈';
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Activate tab from URL hash on page load
        var hash = window.location.hash || '#tabPane-basics';
        var pane = document.querySelector(hash);
        if (pane) {
            document.querySelectorAll('.tab-pane').forEach(function(p) {
                p.classList.remove('show', 'active');
            });
            pane.classList.add('show', 'active');
        }
    });
</script>

<?php require 'views/partials/settings_offcanvas.php'; ?>
<?php require 'views/partials/footer.php'; ?>