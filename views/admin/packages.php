<?php

/**
 * @file   views/admin/packages.php
 * @brief  Package management UI (v2 with Capping + DFI)
 */
?>
<?php $pageTitle = 'Packages'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>
    <div class="row g-3">
      <!-- Package list -->
      <div class="col-12 col-lg-5">
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span class="card-title">📦 All Packages</span>
            <a href="<?= APP_URL ?>/?page=admin_packages" class="btn btn-primary btn-sm">+ New</a>
          </div>
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Entry</th>
                  <th>Pair</th>
                  <th>Cap</th>
                  <th>DFI</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($packages)): ?>
                  <tr>
                    <td colspan="7" class="text-center py-4 text-muted">No packages yet.</td>
                  </tr>
                  <?php else: foreach ($packages as $pkg):
                    $lifetimeCap = (float)$pkg['entry_fee'] * (float)$pkg['lifetime_cap_multiplier'];
                    $hasDfi = (float)$pkg['daily_fixed_income'] > 0;
                  ?>
                    <tr>
                      <td class="fw-bold"><?= e($pkg['name']) ?></td>
                      <td class="font-mono"><?= fmt_money($pkg['entry_fee']) ?></td>
                      <td class="td-green font-mono"><?= fmt_money($pkg['pairing_bonus']) ?></td>
                      <td>
                        <div class="font-mono" style="font-size:.75rem;"><?= fmt_money($lifetimeCap) ?></div>
                        <div class="text-muted" style="font-size:.65rem;"><?= $pkg['lifetime_cap_multiplier'] ?>× entry</div>
                      </td>
                      <td>
                        <?php if ($hasDfi): ?>
                          <div class="font-mono" style="font-size:.75rem;color:var(--pink);">₱<?= number_format($pkg['daily_fixed_income'], 0) ?>/d</div>
                          <div class="text-muted" style="font-size:.65rem;"><?= $pkg['daily_fixed_income_days'] ?> days</div>
                        <?php else: ?>
                          <span class="text-muted" style="font-size:.75rem;">—</span>
                        <?php endif; ?>
                      </td>
                      <td><span class="badge <?= $pkg['status'] === 'active' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>"><?= ucfirst($pkg['status']) ?></span></td>
                      <td><a href="<?= APP_URL ?>/?page=admin_packages&edit=<?= $pkg['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a></td>
                    </tr>
                <?php endforeach;
                endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Create / Edit form -->
      <div class="col-12 col-lg-7">
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span class="card-title"><?= ($editPkg ?? null) ? '✏️ Edit Package' : '➕ New Package' ?></span>
            <?php if ($editPkg ?? null): ?><a href="<?= APP_URL ?>/?page=admin_packages" class="btn btn-sm btn-outline-secondary">✕ Cancel</a><?php endif; ?>
          </div>
          <div class="card-body">
            <form method="POST" action="<?= APP_URL ?>/?page=admin_save_package">
              <?= csrf_field() ?>
              <?php if ($editPkg ?? null): ?><input type="hidden" name="package_id" value="<?= ($editPkg['id'] ?? '') ?>"><?php endif; ?>

              <!-- Basic Info -->
              <div class="mb-3">
                <label class="form-label">Package Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" value="<?= e($editPkg['name'] ?? '') ?>" placeholder="e.g. Starter, Pro, Elite" required>
              </div>

              <div class="row g-3 mb-3">
                <div class="col-md-6">
                  <label class="form-label">Entry Fee (₱) <span class="text-danger">*</span></label>
                  <input type="number" name="entry_fee" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($editPkg['entry_fee'] ?? '') ?>" placeholder="10000.00" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Pairing Bonus (₱) <span class="text-danger">*</span></label>
                  <input type="number" name="pairing_bonus" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($editPkg['pairing_bonus'] ?? '') ?>" placeholder="2000.00" required>
                  <div class="form-text">Per pair paid out</div>
                </div>
              </div>

              <div class="row g-3 mb-3">
                <div class="col-md-6">
                  <label class="form-label">Daily Pair Cap <span class="text-danger">*</span></label>
                  <input type="number" name="daily_pair_cap" class="form-control" inputmode="numeric" min="1" max="100" value="<?= e($editPkg['daily_pair_cap'] ?? 3) ?>" required>
                  <div class="form-text">Flush-out limit per member per day</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Direct Referral Bonus (₱)</label>
                  <input type="number" name="direct_ref_bonus" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($editPkg['direct_ref_bonus'] ?? 0) ?>" placeholder="500.00">
                  <div class="form-text">Paid once to sponsor on join</div>
                </div>
              </div>

              <!-- ═══ LIFETIME INCOME CAPPING (v2) ═══ -->
              <div class="mb-3" style="border:1px solid var(--purple-border);border-radius:12px;padding:1rem;background:linear-gradient(135deg,var(--surface-1) 0%,#161d30 100%);">
                <div class="d-flex align-items-center gap-2 mb-3" style="color:var(--purple);">
                  <i class="bi bi-shield-lock-fill"></i>
                  <span style="font-size:13px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;">Lifetime Income Capping</span>
                </div>

                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label" style="color:var(--purple);">Cap Multiplier</label>
                    <div class="input-group">
                      <input type="number" name="lifetime_cap_multiplier" class="form-control" inputmode="decimal" min="1" max="20" step="0.01" value="<?= e($editPkg['lifetime_cap_multiplier'] ?? 3.00) ?>" required>
                      <span class="input-group-text">× entry fee</span>
                    </div>
                    <div class="form-text">Lifetime cap = Entry Fee × Multiplier</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" style="color:var(--purple);">Auto-Cap Preview</label>
                    <div class="form-control" style="background:var(--surface-3);border-color:var(--purple-border);color:var(--purple);font-family:var(--font-mono);font-weight:700;" id="capPreview">
                      <?php if ($editPkg ?? null): ?>
                        <?= fmt_money((float)($editPkg['entry_fee'] ?? 0) * (float)($editPkg['lifetime_cap_multiplier'] ?? 3.00)) ?>
                      <?php else: ?>
                        ₱0.00
                      <?php endif; ?>
                    </div>
                    <div class="form-text">Calculated automatically</div>
                  </div>
                </div>

                <div class="row g-3 mt-1">
                  <div class="col-md-6">
                    <label class="form-label" style="color:var(--purple);">Reactivation Fee (₱)</label>
                    <input type="number" name="reactivation_fee" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($editPkg['reactivation_fee'] ?? 0) ?>" placeholder="10000.00">
                    <div class="form-text">Fee to reactivate after capping</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" style="color:var(--purple);">Reactivation Window (days)</label>
                    <input type="number" name="reactivation_window_days" class="form-control" inputmode="numeric" min="1" max="365" value="<?= e($editPkg['reactivation_window_days'] ?? 15) ?>">
                    <div class="form-text">Days to reactivate before permanent deactivation</div>
                  </div>
                </div>
              </div>

              <!-- ═══ DAILY FIXED INCOME (v2) ═══ -->
              <div class="mb-3" style="border:1px solid var(--pink-border);border-radius:12px;padding:1rem;background:linear-gradient(135deg,var(--surface-1) 0%,#161d30 100%);">
                <div class="d-flex align-items-center gap-2 mb-3" style="color:var(--pink);">
                  <i class="bi bi-calendar-week-fill"></i>
                  <span style="font-size:13px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;">Daily Fixed Income</span>
                </div>

                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label" style="color:var(--pink);">Daily Fixed Income (₱/day)</label>
                    <input type="number" name="daily_fixed_income" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($editPkg['daily_fixed_income'] ?? 0) ?>" placeholder="100.00">
                    <div class="form-text">Set 0 to disable DFI for this package</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" style="color:var(--pink);">Max DFI Days</label>
                    <input type="number" name="daily_fixed_income_days" class="form-control" inputmode="numeric" min="1" max="1000" value="<?= e($editPkg['daily_fixed_income_days'] ?? 90) ?>">
                    <div class="form-text">Maximum days of fixed income per member</div>
                  </div>
                </div>
              </div>

              <!-- Status -->
              <div class="mb-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                  <option value="active" <?= (($editPkg['status'] ?? 'active') === 'active')  ? 'selected' : '' ?>>Active</option>
                  <option value="inactive" <?= (($editPkg['status'] ?? '') === 'inactive')       ? 'selected' : '' ?>>Inactive</option>
                </select>
              </div>

              <?php if (setting('indirect_referral_enabled', '1') === '1'): ?>
                <!-- Indirect Referral Levels -->
                <div class="mb-3">
                  <label class="form-label fw-bold">🔗 Indirect Referral Bonuses (10 Levels)</label>
                  <div class="row g-2">
                    <?php $lvls = $editPkg['indirect_levels'] ?? [];
                    for ($lvl = 1; $lvl <= 10; $lvl++): ?>
                      <div class="col-6 col-md-4 col-lg-6 col-xl-4">
                        <label class="form-label" style="font-size:.72rem;">Level <?= $lvl ?></label>
                        <div class="input-group input-group-sm">
                          <span class="input-group-text">₱</span>
                          <input type="number" name="indirect_<?= $lvl ?>" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($lvls[$lvl] ?? 0) ?>" placeholder="0.00">
                        </div>
                      </div>
                    <?php endfor; ?>
                  </div>
                  <div class="form-text mt-1">Set 0 to disable a level. Paid once to each upline sponsor on member join.</div>
                </div>
              <?php endif; ?>

              <button type="submit" class="btn btn-primary w-100 btn-lg">
                <?= ($editPkg ?? null) ? '💾 Update Package' : '➕ Create Package' ?>
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  // Auto-update cap preview when entry fee or multiplier changes
  document.querySelector('input[name="entry_fee"]').addEventListener('input', updateCapPreview);
  document.querySelector('input[name="lifetime_cap_multiplier"]').addEventListener('input', updateCapPreview);

  function updateCapPreview() {
    const entry = parseFloat(document.querySelector('input[name="entry_fee"]').value) || 0;
    const mult = parseFloat(document.querySelector('input[name="lifetime_cap_multiplier"]').value) || 0;
    const cap = entry * mult;
    document.getElementById('capPreview').textContent = '₱' + cap.toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }
</script>

<?php require 'views/partials/footer.php'; ?>