<?php

/**
 * @file   views/admin/settings.php
 * @brief  System settings UI — vertical tab layout
 */
?>
<?php $pageTitle = 'System Settings'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<?php $memberCount = (int)(User::counts()['total'] ?? 0); ?>
<style>
  .tab-pane { display: none; }
  .tab-pane.show.active { display: block; }
  .tab-pane .card { margin-bottom: 0; }
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
                <div class="mb-3">
                  <label class="form-label">Maintenance Bypass Token</label>
                  <input type="text" name="maintenance_bypass_token" class="form-control font-mono" value="<?= e(setting('maintenance_bypass_token', '')) ?>" placeholder="Leave empty to disable bypass">
                  <div class="form-text">Append <code>?bypass=TOKEN</code> to the login URL when maintenance is on. Keep this token strong.</div>
                </div>
                <div class="rounded p-3 mb-3" style="background:#fef2f2;border:1px solid #fecaca;">
                  <div class="d-flex align-items-start gap-2">
                    <span style="font-size:.9rem;flex-shrink:0;margin-top:1px;">🔒</span>
                    <div style="font-size:.78rem;color:#991b1b;line-height:1.6;">
                      <strong>Locked out?</strong> Access phpMyAdmin and run:
                      <code style="background:#fee2e2;padding:.1rem .35rem;border-radius:.25rem;">UPDATE settings SET value='0' WHERE key_name='maintenance_mode'</code>
                    </div>
                  </div>
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
              </div>
              <div class="card-footer border-top-0 pt-0">
                <button type="submit" class="btn btn-primary w-100">💾 Save Settings</button>
              </div>
            </div>
          </form>
        </div>

        <!-- ════════════════════════════════════════════
             TAB 3 — COMPENSATION PLAN
             ════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tabPane-comp_plan" role="tabpanel">
          <form method="POST" action="<?= APP_URL ?>/?page=admin_save_settings">
            <?= csrf_field() ?>
            <input type="hidden" name="group" value="comp_plan">
            <div class="card">
              <div class="card-header d-flex align-items-center gap-2">
                <span style="width:28px;height:28px;background:#f0fdf4;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">📋</span>
                <span class="card-title">Compensation Plan</span>
              </div>
              <div class="card-body">
                <!-- Binary -->
                <div class="rounded p-3 mb-3" style="background:#f8fafc;border:1px solid #e2e8f0;">
                  <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="binary_enabled" id="binaryEnabled" value="1" <?= setting('binary_enabled', '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="binaryEnabled" style="font-weight:700;font-size:.85rem;">Enable Binary Pairing Bonuses</label>
                  </div>
                  <div style="font-size:.78rem;color:var(--muted);line-height:1.6;padding-left:2.4rem;">
                    When disabled, no pairing bonuses are paid and binary placement is hidden during registration.
                    <?php if ($memberCount > 0): ?>
                      <br><span class="text-warning">⚠️ <?= $memberCount ?> member(s) exist — disable is blocked until <strong>reset.php</strong> is run.</span>
                    <?php else: ?>
                      <br><span class="text-success">✓ Clean system — binary can be disabled safely.</span>
                    <?php endif; ?>
                  </div>
                </div>
                <!-- Binary Repeat Purchase -->
                <div class="rounded p-3 mb-3" style="background:#f8fafc;border:1px solid #e2e8f0;">
                  <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="binary_repeat_enabled" id="binaryRepeatEnabled" value="1" <?= setting('binary_repeat_enabled', '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="binaryRepeatEnabled" style="font-weight:700;font-size:.85rem;">Enable Binary Repeat Purchase</label>
                  </div>
                  <div style="font-size:.78rem;color:var(--muted);line-height:1.6;padding-left:2.4rem;">
                    When enabled, members can choose Left or Right leg during checkout and product PV earns binary pairing bonuses. When disabled, the Binary Position selector is hidden and product PV does not trigger binary pairing. Toggleable anytime.
                  </div>
                </div>
                <!-- Indirect Referral -->
                <div class="rounded p-3 mb-3" style="background:#f8fafc;border:1px solid #e2e8f0;">
                  <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="indirect_referral_enabled" id="indirectRefEnabled" value="1" <?= setting('indirect_referral_enabled', '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="indirectRefEnabled" style="font-weight:700;font-size:.85rem;">Enable Indirect Referral (Unilevel) Bonuses</label>
                  </div>
                  <div style="font-size:.78rem;color:var(--muted);line-height:1.6;padding-left:2.4rem;">
                    When disabled, no unilevel bonuses are paid and all indirect referral UI is hidden.
                    <?php if ($memberCount > 0): ?>
                      <br><span class="text-warning">⚠️ <?= $memberCount ?> member(s) exist — disable is blocked until <strong>reset.php</strong> is run.</span>
                    <?php else: ?>
                      <br><span class="text-success">✓ Clean system — indirect referral can be disabled safely.</span>
                    <?php endif; ?>
                  </div>
                </div>
                <!-- Unilevel Product Bonus -->
                <div class="rounded p-3 mb-3" style="background:#f8fafc;border:1px solid #e2e8f0;">
                  <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="unilevel_product_enabled" id="unilevelProductEnabled" value="1" <?= setting('unilevel_product_enabled', '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="unilevelProductEnabled" style="font-weight:700;font-size:.85rem;">Enable Unilevel Product Bonus</label>
                  </div>
                  <div style="font-size:.78rem;color:var(--muted);line-height:1.6;padding-left:2.4rem;">
                    When enabled, upline sponsors earn a 10-level unilevel cash bonus on each product purchase (gated by each upline's Personal PV Requirement). When disabled, the Unilevel Bonus section is hidden from the product edit form and no unilevel commissions are processed for product purchases.
                  </div>
                </div>
                <!-- Daily Fixed Income -->
                <div class="rounded p-3 mb-3" style="background:#f8fafc;border:1px solid #e2e8f0;">
                  <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" name="dfi_enabled" id="dfiEnabled" value="1" <?= setting('dfi_enabled', '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="dfiEnabled" style="font-weight:700;font-size:.85rem;">Enable Daily Fixed Income</label>
                  </div>
                  <div style="font-size:.78rem;color:var(--muted);line-height:1.6;padding-left:2.4rem;margin-top:.25rem;">
                    When disabled, no daily fixed income payouts are processed for any member.
                  </div>
                </div>
                <!-- Cap Multiplier -->
                <div class="mb-3">
                  <label class="form-label">Default Lifetime Cap Multiplier</label>
                  <input type="number" name="default_cap_multiplier" class="form-control" min="0" step="0.01" value="<?= e(setting('default_cap_multiplier', '3.00')) ?>">
                  <div class="form-text">Lifetime cap = entry fee × multiplier</div>
                </div>
                <!-- PV per Peso Rate -->
                <div class="rounded p-3" style="background:linear-gradient(135deg,#eef2ff 0%,#e0e7ff 100%);border:1px solid #c7d2fe;">
                  <div class="d-flex align-items-center gap-2 mb-2" style="color:#4f46e5;">
                    <span style="font-size:.9rem;">💎</span>
                    <span style="font-size:.82rem;font-weight:700;letter-spacing:.5px;text-transform:uppercase;">PV Conversion Rate</span>
                  </div>
                  <label class="form-label" style="color:#4f46e5;font-size:.8rem;font-weight:600;">PV per Peso Rate</label>
                  <div class="input-group">
                    <span class="input-group-text">₱</span>
                    <input type="number" name="pv_per_peso_rate" class="form-control font-mono" inputmode="decimal" min="0.0001" step="0.0001" value="<?= e(setting('pv_per_peso_rate', '1000.0000')) ?>">
                    <span class="input-group-text">per 1 PV</span>
                  </div>
                  <div class="form-text">Pesos paid per 1 PV when converting PV-based bonuses.</div>
                </div>
              </div>
              <div class="card-footer border-top-0 pt-0">
                <button type="submit" class="btn btn-primary w-100">💾 Save Settings</button>
              </div>
            </div>
          </form>
        </div>

        <!-- ════════════════════════════════════════════
             TAB 4 — ROYALTY BONUS
             ════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tabPane-royalty" role="tabpanel">
          <form method="POST" action="<?= APP_URL ?>/?page=admin_save_settings">
            <?= csrf_field() ?>
            <input type="hidden" name="group" value="royalty">
            <div class="card">
              <div class="card-header d-flex align-items-center gap-2">
                <span style="width:28px;height:28px;background:var(--royalty-dim,#fef3c7);border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">⭐</span>
                <span class="card-title">Royalty Bonus — Pool/Share Model</span>
              </div>
              <div class="card-body">
                <div class="rounded p-3 mb-3" style="background:#f8fafc;border:1px solid #e2e8f0;">
                  <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="royalty_enabled" id="royaltyEnabled" value="1" <?= setting('royalty_enabled', '0') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="royaltyEnabled" style="font-weight:700;font-size:.85rem;">Enable Royalty Bonus</label>
                  </div>
                  <div style="font-size:.78rem;color:var(--muted);line-height:1.6;padding-left:2.4rem;">
                    When enabled, a % of monthly repeat purchase sales funds the royalty pool, distributed monthly to qualifying ranks.
                  </div>
                </div>

                <!-- Pool Configuration -->
                <div class="rounded p-3 mb-3" style="background:#eff6ff;border:1px solid #bfdbfe;">
                  <h6 class="fw-700 mb-3" style="color:#1e40af;">🏦 Pool Configuration</h6>
                  <div class="row g-3">
                    <div class="col-md-6">
                      <label class="form-label" style="font-size:.8rem;">Pool Rate (% of monthly repeat sales)</label>
                      <div class="input-group">
                        <input type="number" name="royalty_pool_rate" class="form-control" min="0" max="100" step="0.01" value="<?= e(setting('royalty_pool_rate', '10.00')) ?>">
                        <span class="input-group-text">%</span>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label" style="font-size:.8rem;">Minimum Pool Threshold (₱)</label>
                      <div class="input-group">
                        <span class="input-group-text">₱</span>
                        <input type="number" name="royalty_min_pool" class="form-control" min="0" step="0.01" value="<?= e(setting('royalty_min_pool', '500.00')) ?>">
                      </div>
                      <div class="form-text">Pool is forfeited if below this amount.</div>
                    </div>
                  </div>
                </div>

                <!-- Rank Rate Allocation -->
                <div class="rounded p-3 mb-3" style="background:#f0fdf4;border:1px solid #bbf7d0;">
                  <h6 class="fw-700 mb-3" style="color:#166534;">📊 Rank Rate Allocation (must sum to 100%)</h6>
                  <div class="row g-3">
                    <?php $rr = [
                      'supervisor' => ['🥉 Supervisor', setting('royalty_supervisor_rate', '25')],
                      'manager'    => ['🥈 Manager',    setting('royalty_manager_rate', '25')],
                      'director'   => ['🥇 Director',   setting('royalty_director_rate', '25')],
                      'chairman'   => ['👑 Chairman',   setting('royalty_chairman_rate', '25')],
                    ]; ?>
                    <?php foreach ($rr as $rk => [$label, $val]): ?>
                    <div class="col-md-3">
                      <label class="form-label" style="font-size:.8rem;"><?= $label ?></label>
                      <div class="input-group">
                        <input type="number" name="royalty_<?= $rk ?>_rate" class="form-control royalty-rank-rate" min="0" max="100" step="0.01" value="<?= e($val) ?>" data-rank="<?= $rk ?>">
                        <span class="input-group-text">%</span>
                      </div>
                    </div>
                    <?php endforeach; ?>
                  </div>
                  <div class="mt-2">
                    <span class="form-text">Sum: </span>
                    <span id="royaltyRateSum" style="font-weight:700;"></span>
                  </div>
                </div>

                <!-- Qualification Gates -->
                <div class="rounded p-3 mb-3" style="background:#fffbeb;border:1px solid #fde68a;">
                  <h6 class="fw-700 mb-3" style="color:#92400e;">🟡 Rank Qualification Gates</h6>
                  <div class="table-responsive">
                    <table class="table table-bordered mb-0" style="font-size:.82rem;">
                      <thead>
                        <tr>
                          <th>Rank</th>
                          <th>Setting</th>
                          <th style="width:120px;">Value</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr>
                          <td>QA</td>
                          <td>Minimum Directs</td>
                          <td><input type="number" name="royalty_qa_directs" class="form-control form-control-sm" min="0" max="99" value="<?= e(setting('royalty_qa_directs', '3')) ?>"></td>
                        </tr>
                        <tr>
                          <td>QA</td>
                          <td>Personal PV Gate (OR)</td>
                          <td><input type="number" name="royalty_qa_personal_pv" class="form-control form-control-sm" min="0" step="10" value="<?= e(setting('royalty_qa_personal_pv', '200')) ?>"></td>
                        </tr>
                        <tr>
                          <td>QA</td>
                          <td>Group PV Gate (OR)</td>
                          <td><input type="number" name="royalty_qa_group_pv" class="form-control form-control-sm" min="0" step="100" value="<?= e(setting('royalty_qa_group_pv', '1000')) ?>"></td>
                        </tr>
                        <tr>
                          <td>Supervisor</td>
                          <td>Minimum Directs</td>
                          <td><input type="number" name="royalty_spv_directs" class="form-control form-control-sm" min="0" max="99" value="<?= e(setting('royalty_spv_directs', '10')) ?>"></td>
                        </tr>
                        <tr>
                          <td>Supervisor</td>
                          <td>Minimum QA Legs</td>
                          <td><input type="number" name="royalty_spv_qa_legs" class="form-control form-control-sm" min="0" max="99" value="<?= e(setting('royalty_spv_qa_legs', '5')) ?>"></td>
                        </tr>
                        <tr>
                          <td>Manager</td>
                          <td>Minimum Supervisor Legs</td>
                          <td><input type="number" name="royalty_mgr_sup_legs" class="form-control form-control-sm" min="0" max="99" value="<?= e(setting('royalty_mgr_sup_legs', '3')) ?>"></td>
                        </tr>
                        <tr>
                          <td>Director</td>
                          <td>Minimum Manager Legs</td>
                          <td><input type="number" name="royalty_dir_mgr_legs" class="form-control form-control-sm" min="0" max="99" value="<?= e(setting('royalty_dir_mgr_legs', '3')) ?>"></td>
                        </tr>
                        <tr>
                          <td>Chairman</td>
                          <td>Minimum Director Legs</td>
                          <td><input type="number" name="royalty_chm_dir_legs" class="form-control form-control-sm" min="0" max="99" value="<?= e(setting('royalty_chm_dir_legs', '3')) ?>"></td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                  <div class="form-text mt-2">OR gate for QA: member qualifies if they meet personal PV OR group PV threshold.</div>
                </div>
              </div>
              <div class="card-footer border-top-0 pt-0">
                <button type="submit" class="btn btn-primary w-100" id="royaltySaveBtn">💾 Save Settings</button>
              </div>
            </div>
          </form>
        </div>

        <script>
        // ── Rank rate sum validation ───────────────────────────────────────────
        (function() {
            const inputs = document.querySelectorAll('.royalty-rank-rate');
            const sumEl  = document.getElementById('royaltyRateSum');
            const saveBtn = document.getElementById('royaltySaveBtn');

            function updateSum() {
                let sum = 0;
                inputs.forEach(el => sum += parseFloat(el.value) || 0);
                sumEl.textContent = sum.toFixed(2) + '%';
                sumEl.style.color = Math.abs(sum - 100) <= 0.01 ? '#166534' : '#dc2626';
                if (saveBtn) {
                    saveBtn.textContent = Math.abs(sum - 100) <= 0.01 ? '💾 Save Settings' : '⚠️ Rates must sum to 100%';
                }
            }

            inputs.forEach(el => el.addEventListener('input', updateSum));
            updateSum();

            // Block form submit if sum != 100
            const form = inputs[0]?.closest('form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    let sum = 0;
                    inputs.forEach(el => sum += parseFloat(el.value) || 0);
                    if (Math.abs(sum - 100) > 0.01) {
                        e.preventDefault();
                        alert('Rank rates must sum to 100%. Current sum: ' + sum.toFixed(2));
                    }
                });
            }
        })();
        </script>

        <!-- ════════════════════════════════════════════
             TAB 5 — REACTIVATION
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
                  <label class="form-label" style="font-size:.8rem;font-weight:600;">Payment Methods</label>
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
             TAB 5 — E-WALLET TRANSFERS
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
                <div class="row g-2 mb-3">
                  <div class="col-6">
                    <label class="form-label" style="font-weight:600;font-size:.78rem;">Transfer Fee (₱)</label>
                    <input type="number" name="ewallet_transfer_fee" class="form-control" min="0" step="0.01" value="<?= e(setting('ewallet_transfer_fee', '0.00')) ?>">
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
             TAB 6 — PAYOUT METHODS
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
             TAB 7 — CHANGE PASSWORD (external form)
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
             TAB 9 — DAILY CAP RESET (external form)
             ════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tabPane-reset" role="tabpanel">
          <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
              <span style="width:28px;height:28px;background:#fef3c7;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">⏱️</span>
              <span class="card-title">Daily Pair Cap Reset</span>
            </div>
            <div class="card-body">
              <p class="text-muted mb-3" style="font-size:.85rem;line-height:1.7;">
                The midnight cron resets <code>paired_pv_today = 0</code> for all members, clearing the daily paired-PV cap.
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
                onclick="showConfirm({title:'Run Daily Reset',message:'Reset paired_pv_today = 0 for ALL active members now?',confirmText:'⟳ Run Reset',confirmClass:'btn-warning',formId:'manualResetForm'})">
                ⟳ Run Daily Reset Now
              </button>
            </div>
          </div>
        </div>

        <!-- ════════════════════════════════════════════
             TAB 10 — SYSTEM OVERVIEW (read-only)
             ════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tabPane-overview" role="tabpanel">
          <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
              <span style="width:28px;height:28px;background:#f3f4f6;border-radius:.45rem;display:flex;align-items:center;justify-content:center;font-size:.85rem;">ℹ️</span>
              <span class="card-title">System Overview</span>
            </div>
            <div class="card-body p-0">
              <table class="table table-borderless mb-0" style="font-size:.82rem;">
                <tr><td class="ps-3 text-muted" style="width:40%;">PHP Version</td><td class="font-mono pe-3"><?= PHP_VERSION ?></td></tr>
                <tr style="background:#f8fafc;"><td class="ps-3 text-muted">MySQL Version</td><td class="font-mono pe-3"><?= db()->query('SELECT VERSION()')->fetchColumn() ?></td></tr>
                <tr><td class="ps-3 text-muted">Server Time</td><td class="font-mono pe-3"><?= date('Y-m-d H:i:s') ?></td></tr>
                <tr style="background:#f8fafc;"><td class="ps-3 text-muted">App URL</td><td class="font-mono pe-3" style="font-size:.72rem;word-break:break-all;"><?= APP_URL ?></td></tr>
                <tr><td class="ps-3 text-muted">Environment</td><td class="pe-3"><span class="badge <?= APP_ENV === 'production' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' ?>" style="font-size:.72rem;"><?= APP_ENV ?></span></td></tr>
                <tr style="background:#f8fafc;"><td class="ps-3 text-muted">Members</td><td class="pe-3 font-mono"><?= User::counts()['total'] ?? 0 ?> / <?= e(setting('seat_limit', '1000')) ?></td></tr>
                <tr><td class="ps-3 text-muted">Binary Status</td><td class="pe-3"><?php if (setting('binary_enabled', '1') === '1'): ?><span class="badge bg-success-subtle text-success" style="font-size:.72rem;">🟢 Enabled</span><?php else: ?><span class="badge bg-secondary-subtle text-secondary" style="font-size:.72rem;">⚪ Disabled</span><?php endif; ?></td></tr>
                <tr style="background:#f8fafc;"><td class="ps-3 text-muted">Maintenance</td><td class="pe-3"><?php if (setting('maintenance_mode') === '1'): ?><span class="badge bg-danger-subtle text-danger" style="font-size:.72rem;">🔴 On</span><?php else: ?><span class="badge bg-success-subtle text-success" style="font-size:.72rem;">🟢 Off</span><?php endif; ?></td></tr>
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

document.addEventListener('DOMContentLoaded', function () {
  // Activate tab from URL hash on page load
  var hash = window.location.hash || '#tabPane-basics';
  var pane = document.querySelector(hash);
  if (pane) {
    document.querySelectorAll('.tab-pane').forEach(function (p) {
      p.classList.remove('show', 'active');
    });
    pane.classList.add('show', 'active');
  }
});
</script>

<?php require 'views/partials/settings_offcanvas.php'; ?>
<?php require 'views/partials/footer.php'; ?>
