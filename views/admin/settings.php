<?php

/**
 * @file   views/admin/settings.php
 * @brief  System settings UI
 */
?>
<?php $pageTitle = 'System Settings'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>
    <div class="row g-3">
      <!-- Left col -->
      <div class="col-12 col-lg-6 d-flex flex-column gap-3">
        <div class="card">
          <div class="card-header"><span class="card-title">🌐 General Settings</span></div>
          <div class="card-body">
            <form method="POST" action="<?= APP_URL ?>/?page=admin_save_settings">
              <?= csrf_field() ?>
              <div class="mb-3"><label class="form-label">Site Name</label><input type="text" name="site_name" class="form-control" value="<?= e(setting('site_name')) ?>"></div>
              <div class="mb-3"><label class="form-label">Site Tagline</label><input type="text" name="site_tagline" class="form-control" value="<?= e(setting('site_tagline')) ?>"></div>
              <div class="mb-3"><label class="form-label">Contact Email</label><input type="email" name="contact_email" class="form-control" value="<?= e(setting('contact_email')) ?>"></div>
              <div class="mb-3">
                <label class="form-label">Minimum Payout (₱)</label>
                <input type="number" name="min_payout" class="form-control" min="0" step="0.01" value="<?= e(setting('min_payout', '500')) ?>">
                <div class="form-text">Members cannot request below this amount</div>
              </div>

              <hr class="my-3">
              <p class="fw-bold mb-2" style="font-size:.82rem;">💸 Payout Service Fees</p>
              <div class="form-text mb-3">Deducted from the requested amount before sending. Set to 0 to disable for any method.</div>

              <!-- Payout Method Toggles -->
              <div class="mb-3">
                <label class="form-label fw-bold" style="font-size:.82rem;">🏦 Available Payout Methods</label>
                <div class="form-text mb-2">Disable methods to hide them from members. USDT is always enabled.</div>
                <div class="row g-2 mb-3">
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
                <div class="alert alert-info py-2" style="font-size:.75rem;">
                  <strong>ℹ Note:</strong> Disabling a method hides it from members entirely — they cannot select it for payouts or edit saved account details.
                </div>
              </div>

              <div class="row g-2 mb-3">
                <div class="col-4">
                  <label class="form-label" style="color:#0070d8;font-weight:700;font-size:.75rem;">GCash %</label>
                  <div class="input-group input-group-sm">
                    <input type="number" name="service_fee_gcash" class="form-control" min="0" max="100" step="0.01" value="<?= e(setting('service_fee_gcash', '0')) ?>">
                    <span class="input-group-text">%</span>
                  </div>
                </div>
                <div class="col-4">
                  <label class="form-label" style="color:#48b0db;font-weight:700;font-size:.75rem;">Maya %</label>
                  <div class="input-group input-group-sm">
                    <input type="number" name="service_fee_maya" class="form-control" min="0" max="100" step="0.01" value="<?= e(setting('service_fee_maya', '0')) ?>">
                    <span class="input-group-text">%</span>
                  </div>
                </div>
                <div class="col-4">
                  <label class="form-label" style="color:#26a17b;font-weight:700;font-size:.75rem;">USDT %</label>
                  <div class="input-group input-group-sm">
                    <input type="number" name="service_fee_usdt" class="form-control" min="0" max="100" step="0.01" value="<?= e(setting('service_fee_usdt', '5')) ?>">
                    <span class="input-group-text">%</span>
                  </div>
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label" style="color:#26a17b;font-weight:700;font-size:.8rem;">₮ USDT TRC20 Network Gas Fee</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text">USDT</span>
                  <input type="number" name="usdt_gas_fee" class="form-control font-mono" min="0" step="0.0001" value="<?= e(setting('usdt_gas_fee', '2.50')) ?>">
                </div>
                <div class="form-text">Fixed TRC20 network fee deducted from USDT payout (typically 1–3 USDT)</div>
              </div>
              <div class="mb-3">
                <label class="form-label">Maintenance Mode</label>
                <select name="maintenance_mode" class="form-select">
                  <option value="0" <?= setting('maintenance_mode') === '0' ? 'selected' : '' ?>>Off — Site is live</option>
                  <option value="1" <?= setting('maintenance_mode') === '1' ? 'selected' : '' ?>>On — Members see maintenance page</option>
                </select>
              </div>

              <div class="mb-3">
                <label class="form-label">🪑 Seat Limit (Hard Member Cap)</label>
                <input type="number" name="seat_limit" class="form-control" min="1" step="1" value="<?= e(setting('seat_limit', '1000')) ?>">
                <div class="form-text">
                  Maximum member accounts allowed. When reached, registration closes permanently.
                  Current members: <strong><?= User::counts()['total'] ?? 0 ?></strong> ·
                  Remaining seats: <strong><?= seatsRemaining() ?></strong>
                </div>
              </div>

              <hr class="my-3">
              <p class="fw-bold mb-2" style="font-size:.82rem;">📋 Compensation Plan Defaults</p>
              <div class="form-text mb-3">Default values applied to new packages. Can be overridden per package.</div>
              <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="indirect_referral_enabled" id="indirectRefEnabled" value="1" <?= setting('indirect_referral_enabled', '1') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="indirectRefEnabled" style="font-weight:600;font-size:.8rem;">
                  Enable Indirect Referral (Unilevel) Bonuses
                </label>
              </div>
              <div class="form-text mb-3">When disabled, no unilevel bonuses are paid and all indirect referral UI is hidden from members.</div>
              <div class="mb-3">
                <label class="form-label">Default Lifetime Cap Multiplier</label>
                <input type="number" name="default_cap_multiplier" class="form-control" min="0" step="0.01" value="<?= e(setting('default_cap_multiplier', '3.00')) ?>">
                <div class="form-text">Lifetime cap = entry fee × multiplier. Default: 3.00</div>
              </div>
              <div class="mb-3">
                <label class="form-label">Reactivation Payment Methods</label>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="reactivation_ewallet_enabled" id="reEwEnabled" value="1" <?= setting('reactivation_ewallet_enabled', '1') === '1' ? 'checked' : '' ?>>
                  <label class="form-check-label" for="reEwEnabled">E-Wallet (deduct balance immediately)</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="reactivation_external_enabled" id="reExtEnabled" value="1" <?= setting('reactivation_external_enabled', '1') === '1' ? 'checked' : '' ?>>
                  <label class="form-check-label" for="reExtEnabled">External (GCash / Maya / USDT with admin approval)</label>
                </div>
              </div>

              <hr class="my-3">
              <p class="fw-bold mb-2" style="font-size:.82rem;">🏦 Admin Payment Accounts (for Reactivation)</p>
              <div class="form-text mb-3">Members send external reactivation payments to these accounts. Displayed on the reactivation page.</div>
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
                <input type="text" name="usdt_address" class="form-control font-mono" placeholder="T..." value="<?= e(setting('usdt_address', '')) ?>">
                <div class="form-text">TRC20 addresses start with T and are 34 characters.</div>
              </div>

              <hr class="my-3">
              <p class="fw-bold mb-2" style="font-size:.82rem;">📅 Daily Fixed Income (DFI)</p>
              <div class="form-text mb-3">Controls the Daily Fixed Income payout system.</div>
              <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="dfi_enabled" id="dfiEnabled" value="1" <?= setting('dfi_enabled', '1') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="dfiEnabled" style="font-weight:600;font-size:.8rem;">
                  Enable DFI payouts
                </label>
              </div>

              <hr class="my-3">
              <p class="fw-bold mb-2" style="font-size:.82rem;">💱 E-Wallet Transfers</p>
              <div class="form-text mb-3">Configure member-to-member transfer rules and fees. Admin transfers are always free.</div>

              <div class="row g-2 mb-3">
                <div class="col-6">
                  <label class="form-label" style="font-weight:700;font-size:.75rem;">Transfer Fee (₱)</label>
                  <input type="number" name="ewallet_transfer_fee" class="form-control" min="0" step="0.01" value="<?= e(setting('ewallet_transfer_fee', '0.00')) ?>">
                  <div class="form-text">Flat fee per member transfer</div>
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

              <button type="submit" class="btn btn-primary w-100">💾 Save Settings</button>
            </form>
          </div>
        </div>

        <!-- Admin password -->
        <div class="card">
          <div class="card-header"><span class="card-title">🔒 Change Admin Password</span></div>
          <div class="card-body">
            <form method="POST" action="<?= APP_URL ?>/?page=save_profile">
              <?= csrf_field() ?>
              <div class="mb-3"><label class="form-label">Current Password</label><input type="password" name="current_password" class="form-control" autocomplete="current-password"></div>
              <div class="mb-3"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control" minlength="8" autocomplete="new-password"></div>
              <div class="mb-3"><label class="form-label">Confirm New Password</label><input type="password" name="new_password_confirm" class="form-control" autocomplete="new-password"></div>
              <button type="submit" class="btn btn-primary w-100">🔒 Update Password</button>
            </form>
          </div>
        </div>
      </div>

      <!-- Right col -->
      <div class="col-12 col-lg-6 d-flex flex-column gap-3">
        <!-- Daily reset -->
        <div class="card">
          <div class="card-header"><span class="card-title">⏱️ Daily Pair Cap Reset</span></div>
          <div class="card-body">
            <p class="text-muted mb-3" style="font-size:.85rem;line-height:1.7;">
              The midnight cron resets <code>pairs_paid_today = 0</code> for all members, clearing the daily pairing cap so they can earn again tomorrow.
            </p>
            <div class="rounded p-3 mb-3" style="background:#f4f6fb;">
              <div class="text-muted mb-1" style="font-size:.68rem;font-weight:700;letter-spacing:.5px;text-transform:uppercase;">Last Reset</div>
              <div class="fw-600 font-mono" style="font-size:.875rem;"><?= setting('last_reset') ? fmt_datetime(setting('last_reset')) : 'Never run' ?></div>
            </div>
            <div class="rounded p-3 mb-3 font-mono" style="background:#f4f6fb;font-size:.75rem;color:var(--muted);">
              Crontab:<br><strong style="color:#111;">0 0 * * * php /path/to/site/cron/midnight_reset.php</strong>
            </div>
            <a href="<?= APP_URL ?>/midnight_reset.php?key=secret12345678"
               target="_blank"
               class="btn btn-outline-warning w-100">
              ⟳ Run Daily Reset Now
            </a>
          </div>
        </div>

        <!-- System info -->
        <div class="card">
          <div class="card-header"><span class="card-title">ℹ System Info</span></div>
          <div class="card-body">
            <table class="info-table">
              <tr>
                <td>PHP Version</td>
                <td class="font-mono"><?= PHP_VERSION ?></td>
              </tr>
              <tr>
                <td>MySQL Version</td>
                <td class="font-mono"><?= db()->query('SELECT VERSION()')->fetchColumn() ?></td>
              </tr>
              <tr>
                <td>Server Time</td>
                <td class="font-mono"><?= date('Y-m-d H:i:s') ?></td>
              </tr>
              <tr>
                <td>App URL</td>
                <td class="font-mono" style="font-size:.72rem;word-break:break-all;"><?= APP_URL ?></td>
              </tr>
              <tr>
                <td>Environment</td>
                <td><span class="badge <?= APP_ENV === 'production' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' ?>"><?= APP_ENV ?></span></td>
              </tr>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require 'views/partials/footer.php'; ?>