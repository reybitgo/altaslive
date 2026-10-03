<?php

/**
 * @file   views/admin/packages.php
 * @brief  Package management UI — full-width table + modal form
 */
?>
<?php $pageTitle = 'Packages'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h4 class="mb-0">Packages</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Manage entry plans, bonuses, capping & DFI</p>
      </div>
      <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#packageModal" onclick="resetPackageForm()">
        + New Package
      </button>
    </div>

    <!-- Stats Row -->
    <?php
    $totalPkg  = $packages['total'] ?? 0;
    $activePkg = count(array_filter(Package::all() ?: [], fn($p) => $p['status'] === 'active'));
    $binaryEnabled = setting('binary_enabled', '1') === '1';
    ?>
    <div class="row g-2 mb-3">
      <div class="col-6 col-md-3">
        <div class="card p-3">
          <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Total Plans</div>
          <div class="fw-bold fs-5"><?= $totalPkg ?></div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card p-3">
          <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Active</div>
          <div class="fw-bold fs-5 text-success"><?= $activePkg ?></div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card p-3">
          <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Inactive</div>
          <div class="fw-bold fs-5 text-secondary"><?= $totalPkg - $activePkg ?></div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card p-3">
          <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Binary</div>
          <div class="fw-bold fs-5"><?= $binaryEnabled ? '<span class="text-success">On</span>' : '<span class="text-secondary">Off</span>' ?></div>
        </div>
      </div>
    </div>

    <!-- Full-width Packages Table -->
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="card-title">📦 Packages</span>
        <?php require 'views/partials/rows_per_page.php'; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="pkgTable">
          <thead style="background:#f8fafc;">
            <tr>
              <th style="padding-left:1.25rem;">Package</th>
              <th class="text-end">Entry Fee</th>
              <?php if ($binaryEnabled): ?><th class="text-end">Pairing Payout</th><?php endif; ?>
              <th class="text-end">Direct Ref</th>
              <th class="text-end">Lifetime Cap</th>
              <?php if (setting('dfi_enabled', '1') === '1'): ?><th class="text-center">DFI</th><?php endif; ?>
              <th class="text-center">Status</th>
              <th class="text-end" style="padding-right:1.25rem;width:100px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($packages['data'])): ?>
              <tr>
                <td colspan="<?= $binaryEnabled ? '8' : '7' ?>" class="text-center py-5 text-muted">
                  <div style="font-size:2rem;opacity:.3;margin-bottom:.5rem;">📦</div>
                  <div>No packages yet.</div>
                  <div style="font-size:.8rem;">Click <strong>+ New Package</strong> to create your first plan.</div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($packages['data'] as $pkg):
                $lifetimeCap = (float)$pkg['entry_fee'] * (float)$pkg['lifetime_cap_multiplier'];
                $hasDfi      = Package::hasDfi((int)$pkg['id']);
                $dfiAmount   = Package::dailyFixedIncome((int)$pkg['id']);
                $dfiPvPct    = (float)$pkg['dfi_pv_pct'];
              ?>
                <tr>
                  <td style="padding-left:1.25rem;">
                    <div class="fw-semibold"><?= e($pkg['name']) ?></div>
                    <div class="text-muted" style="font-size:.7rem;">ID: <?= (int)$pkg['id'] ?></div>
                  </td>
                  <td class="text-end font-mono"><?= fmt_money($pkg['entry_fee']) ?></td>
                  <?php if ($binaryEnabled): ?>
                    <?php $binaryPv = Package::binaryPackagePv((int)$pkg['id']); $pvRate = (float)setting('pv_per_peso_rate', '1.0000'); $pairPeso = $binaryPv * $pvRate; ?>
                    <td class="text-end">
                      <div class="font-mono" style="color:var(--success);">₱<?= number_format($pvRate, 4) ?></div>
                      <div class="text-muted" style="font-size:.65rem;">per binary PV (global rate)</div>
                      <div class="text-muted" style="font-size:.65rem;">≈ <?= fmt_money($pairPeso) ?>/pkg paired</div>
                    </td>
                  <?php endif; ?>
                  <?php $pkgPv = Package::packagePv((int)$pkg['id']); $directPeso = Package::directReferralBonus($pkgPv, (int)$pkg['id']); ?>
                  <td class="text-end">
                    <div class="font-mono"><?= (float)$pkg['direct_ref_pv_pct'] ?>%</div>
                    <div class="text-muted" style="font-size:.65rem;">≈ <?= fmt_money($directPeso) ?>/recruit</div>
                  </td>
                  <td class="text-end">
                    <div class="font-mono"><?= fmt_money($lifetimeCap) ?></div>
                    <div class="text-muted" style="font-size:.65rem;"><?= $pkg['lifetime_cap_multiplier'] ?>×</div>
                  </td>
                  <?php if (setting('dfi_enabled', '1') === '1'): ?>
                  <td class="text-center">
                    <?php if ($hasDfi): ?>
                      <div class="font-mono" style="font-size:.8rem;color:#db2777;"><?= fmt_money($dfiAmount) ?>/d</div>
                      <?php if ($dfiPvPct > 0): ?>
                        <div class="text-muted" style="font-size:.65rem;"><?= $dfiPvPct ?>% of PV · <?= (int)$pkg['daily_fixed_income_days'] ?> days</div>
                      <?php else: ?>
                        <div class="text-muted" style="font-size:.65rem;"><?= (int)$pkg['daily_fixed_income_days'] ?> days</div>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="text-muted" style="font-size:.8rem;">—</span>
                    <?php endif; ?>
                  </td>
                  <?php endif; ?>
                  <td class="text-center">
                    <?php if ($pkg['status'] === 'active'): ?>
                      <span class="badge bg-success-subtle text-success" style="font-size:.72rem;">● Active</span>
                    <?php else: ?>
                      <span class="badge bg-secondary-subtle text-secondary" style="font-size:.72rem;">○ Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end" style="padding-right:1.25rem;">
                    <a href="<?= APP_URL ?>/?page=admin_packages&edit=<?= (int)$pkg['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if (!empty($packages['total_pages']) && $packages['total_pages'] > 1): ?>
        <div class="card-footer"><?= pagination_links($packages, APP_URL . '/?page=admin_packages&per_page=' . per_page()) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     PACKAGE MODAL  (Create / Edit)
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="packageModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="packageModalTitle">➕ New Package</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="POST" action="<?= APP_URL ?>/?page=admin_save_package" id="packageForm">
          <?= csrf_field() ?>
          <input type="hidden" name="package_id" id="packageId" value="<?= e($editPkg['id'] ?? '') ?>">

          <!-- Basic Info -->
          <div class="mb-3">
            <label class="form-label">Package Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="pkgName" class="form-control" value="<?= e($editPkg['name'] ?? '') ?>" placeholder="e.g. Starter, Pro, Elite" required>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">Entry Fee (₱) <span class="text-danger">*</span></label>
              <input type="number" name="entry_fee" id="pkgEntryFee" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($editPkg['entry_fee'] ?? '') ?>" placeholder="10000.00" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Package PV</label>
              <div class="input-group">
                <input type="number" name="package_pv_rate" id="pkgPvRate" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($editPkg['package_pv_rate'] ?? 10.00) ?>">
                <span class="input-group-text">PV</span>
              </div>
              <div class="form-text">Package PV = <span id="pkgPvPreview" class="font-mono">0.00 PV</span> (direct/indirect/DFI basis)</div>
            </div>
          </div>

          <div class="row g-3 mb-3">
            <?php if ($binaryEnabled): ?>
            <div class="col-md-6">
              <label class="form-label">Binary PV Rate (%)</label>
              <div class="input-group">
                <input type="number" name="binary_pv_pct" id="pkgBinaryPvPct" class="form-control" inputmode="decimal" min="0" max="1000" step="0.01" value="<?= e($editPkg['binary_pv_pct'] ?? 20.00) ?>">
                <span class="input-group-text">%</span>
              </div>
              <div class="form-text">Binary PV = Package PV × <span id="binaryPvPctDisplay" class="font-mono"><?= e($editPkg['binary_pv_pct'] ?? 20) ?></span>% = <span id="binaryPvPreview" class="font-mono">₱0.00</span> in binary tree</div>
            </div>
            <?php endif; ?>
            <div class="col-md-6">
              <label class="form-label">Direct Referral (% of Package PV)</label>
              <div class="input-group">
                <input type="number" name="direct_ref_pv_pct" id="pkgDirectRefPct" class="form-control" inputmode="decimal" min="0" max="100" step="0.01" value="<?= e($editPkg['direct_ref_pv_pct'] ?? 0) ?>" placeholder="10.00">
                <span class="input-group-text">%</span>
              </div>
              <div class="form-text">≈ <span id="directRefPreview" class="font-mono">₱0.00</span> per direct recruit</div>
            </div>
          </div>

          <?php if ($binaryEnabled): ?>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">Daily Pair Cap (PV) <span class="text-danger">*</span></label>
              <input type="number" name="daily_pair_pv_cap" id="pkgPairCapPv" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($editPkg['daily_pair_pv_cap'] ?? '') ?>" required>
              <div class="form-text">Max paired PV per member per day</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Personal PV Requirement (PV)</label>
              <input type="number" name="personal_pv_requirement" id="pkgPersonalPvReq" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($editPkg['personal_pv_requirement'] ?? 0) ?>">
              <div class="form-text">Minimum Personal PV an upline must have to earn repeat-purchase indirect/PV bonuses. 0 = no gate.</div>
            </div>
          </div>
          <?php else: ?>
          <div class="mb-3">
            <label class="form-label">Personal PV Requirement (PV)</label>
            <input type="number" name="personal_pv_requirement" id="pkgPersonalPvReq" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($editPkg['personal_pv_requirement'] ?? 0) ?>">
            <div class="form-text">Minimum Personal PV an upline must have to earn repeat-purchase indirect/PV bonuses. 0 = no gate.</div>
          </div>
          <?php endif; ?>

          <!-- Lifetime Capping -->
          <div class="rounded p-3 mb-3" style="background:linear-gradient(135deg,#faf5ff 0%,#f3e8ff 100%);border:1px solid #e9d5ff;">
            <div class="d-flex align-items-center gap-2 mb-3" style="color:#7c3aed;">
              <span style="font-size:1rem;">🛡️</span>
              <span style="font-size:.82rem;font-weight:700;letter-spacing:.5px;text-transform:uppercase;">Lifetime Income Capping</span>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" style="color:#7c3aed;font-size:.8rem;font-weight:600;">Cap Multiplier</label>
                <div class="input-group">
                  <input type="number" name="lifetime_cap_multiplier" id="pkgCapMult" class="form-control" inputmode="decimal" min="1" max="20" step="0.01" value="<?= e($editPkg['lifetime_cap_multiplier'] ?? 3.00) ?>" required>
                  <span class="input-group-text">× entry</span>
                </div>
                <div class="form-text">Lifetime cap = Entry Fee × Multiplier</div>
              </div>
              <div class="col-md-6">
                <label class="form-label" style="color:#7c3aed;font-size:.8rem;font-weight:600;">Auto-Cap Preview</label>
                <div class="form-control font-mono fw-bold" style="background:#f5f3ff;border-color:#ddd6fe;color:#7c3aed;" id="capPreview">
                  <?= ($editPkg ?? null) ? fmt_money((float)($editPkg['entry_fee'] ?? 0) * (float)($editPkg['lifetime_cap_multiplier'] ?? 3.00)) : '₱0.00' ?>
                </div>
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label" style="color:#7c3aed;font-size:.8rem;font-weight:600;">Reactivation Fee (₱)</label>
                <input type="number" name="reactivation_fee" id="pkgReactivationFee" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($editPkg['reactivation_fee'] ?? 0) ?>" placeholder="10000.00">
                <div class="form-text">Fee to reactivate after capping</div>
              </div>
              <div class="col-md-6">
                <label class="form-label" style="color:#7c3aed;font-size:.8rem;font-weight:600;">Reactivation Window (days)</label>
                <input type="number" name="reactivation_window_days" id="pkgReactivationWindow" class="form-control" inputmode="numeric" min="1" max="365" value="<?= e($editPkg['reactivation_window_days'] ?? 15) ?>">
                <div class="form-text">Days before permanent deactivation</div>
              </div>
            </div>
          </div>

          <!-- Daily Fixed Income -->
          <div class="rounded p-3 mb-3" style="background:linear-gradient(135deg,#fdf2f8 0%,#fce7f3 100%);border:1px solid #fbcfe8;">
            <div class="d-flex align-items-center gap-2 mb-3" style="color:#db2777;">
              <span style="font-size:1rem;">📅</span>
              <span style="font-size:.82rem;font-weight:700;letter-spacing:.5px;text-transform:uppercase;">Daily Fixed Income</span>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" style="color:#db2777;font-size:.8rem;font-weight:600;">DFI (% of Package PV)</label>
                <div class="input-group">
                  <input type="number" name="dfi_pv_pct" id="pkgDfiPvPct" class="form-control" inputmode="decimal" min="0" max="100" step="0.01" value="<?= e($editPkg['dfi_pv_pct'] ?? 0) ?>" placeholder="0.00">
                  <span class="input-group-text">%</span>
                </div>
                <div class="form-text">If &gt; 0, overrides fixed amount. ≈ <span id="dfiPvPreview" class="font-mono">₱0.00</span>/day</div>
              </div>
              <div class="col-md-6">
                <label class="form-label" style="color:#db2777;font-size:.8rem;font-weight:600;">Or Fixed Daily Income (₱/day)</label>
                <input type="number" name="daily_fixed_income" id="pkgDfi" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($editPkg['daily_fixed_income'] ?? 0) ?>" placeholder="100.00">
                <div class="form-text">Used only when DFI % is 0</div>
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label" style="color:#db2777;font-size:.8rem;font-weight:600;">Max DFI Days</label>
                <input type="number" name="daily_fixed_income_days" id="pkgDfiDays" class="form-control" inputmode="numeric" min="0" max="1000" value="<?= e($editPkg['daily_fixed_income_days'] ?? 90) ?>">
                <div class="form-text">Set 0 to disable DFI for this package</div>
              </div>
            </div>
          </div>

          <!-- Status -->
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" id="pkgStatus" class="form-select">
              <option value="active" <?= (($editPkg['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>🟢 Active</option>
              <option value="inactive" <?= (($editPkg['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>⚪ Inactive</option>
            </select>
          </div>

          <?php if (setting('indirect_referral_enabled', '1') === '1'): ?>
            <!-- Indirect Referral Levels -->
            <div class="mb-0">
              <label class="form-label fw-bold">🔗 Indirect Referral Bonuses (10 Levels)</label>
              <div class="row g-2">
                <?php $lvls = $editPkg['indirect_levels'] ?? [];
                for ($lvl = 1; $lvl <= 10; $lvl++): ?>
                  <div class="col-6 col-md-4">
                    <label class="form-label" style="font-size:.72rem;">Level <?= $lvl ?></label>
                    <div class="input-group input-group-sm">
                      <input type="number" name="indirect_<?= $lvl ?>" id="indirect_<?= $lvl ?>" class="form-control" inputmode="decimal" min="0" max="100" step="0.01" value="<?= e($lvls[$lvl] ?? 0) ?>" placeholder="0.00">
                      <span class="input-group-text">%</span>
                    </div>
                  </div>
                <?php endfor; ?>
              </div>
              <div class="form-text mt-1">Set 0 to disable a level. Percentages are applied to the new member's package PV. Pesos depend on the global PV-per-peso rate. Total indirect ≈ <span id="indirectPreview" class="font-mono">₱0.00</span></div>
            </div>
          <?php endif; ?>
        </form>
      </div>
      <div class="modal-footer" style="border-top:1px solid #f1f5f9;">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" form="packageForm" id="pkgSubmitBtn">
          <?= ($editPkg ?? null) ? '💾 Update Package' : '➕ Create Package' ?>
        </button>
      </div>
    </div>
  </div>
</div>

<script>
  // ── Cap preview live update ──
  const entryInput = document.getElementById('pkgEntryFee');
  const multInput  = document.getElementById('pkgCapMult');
  const previewEl  = document.getElementById('capPreview');

  function updateCapPreview() {
    const entry = parseFloat(entryInput?.value) || 0;
    const mult  = parseFloat(multInput?.value)  || 0;
    previewEl.textContent = '₱' + (entry * mult).toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  if (entryInput) entryInput.addEventListener('input', updateCapPreview);
  if (multInput)  multInput.addEventListener('input', updateCapPreview);

  // ── Package PV basis preview live update ──
  const pvRateInput = document.getElementById('pkgPvRate');
  const pvPreviewEl = document.getElementById('pkgPvPreview');

  function updatePackagePVPreview() {
    const rate  = parseFloat(pvRateInput?.value) || 0;
    pvPreviewEl.textContent = rate.toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    }) + ' PV';
  }

  if (pvRateInput)   pvRateInput.addEventListener('input', updatePackagePVPreview);

  // ── Binary PV basis preview live update ──
  const binaryPvPctInput = document.getElementById('pkgBinaryPvPct');
  const binaryPvPreviewEl = document.getElementById('binaryPvPreview');
  const pvPerPesoRate = <?= (float)setting('pv_per_peso_rate', '1.0000') ?>;

  function updateBinaryPvPreview() {
    if (!binaryPvPreviewEl) return;
    const pvRate = parseFloat(pvRateInput?.value) || 0;
    const pct = parseFloat(binaryPvPctInput?.value) || 0;
    const binaryPv = pvRate * (pct / 100) * pvPerPesoRate;
    binaryPvPreviewEl.textContent = '₱' + binaryPv.toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  if (pvRateInput)      pvRateInput.addEventListener('input', updateBinaryPvPreview);
  if (binaryPvPctInput) binaryPvPctInput.addEventListener('input', updateBinaryPvPreview);

  // ── Direct referral preview live update ──
  const directRefInput = document.getElementById('pkgDirectRefPct');
  const directRefPreviewEl = document.getElementById('directRefPreview');

  function updateDirectRefPreview() {
    if (!directRefPreviewEl) return;
    const pvRate = parseFloat(pvRateInput?.value) || 0;
    const pct = parseFloat(directRefInput?.value) || 0;
    const packagePv = pvRate;
    const bonus = packagePv * (pct / 100) * pvPerPesoRate;
    directRefPreviewEl.textContent = '₱' + bonus.toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  if (pvRateInput)    pvRateInput.addEventListener('input', updateDirectRefPreview);
  if (directRefInput) directRefInput.addEventListener('input', updateDirectRefPreview);

  // ── DFI PV preview live update ──
  const dfiPvPctInput = document.getElementById('pkgDfiPvPct');
  const dfiPvPreviewEl = document.getElementById('dfiPvPreview');

  function updateDfiPvPreview() {
    if (!dfiPvPreviewEl) return;
    const pvRate = parseFloat(pvRateInput?.value) || 0;
    const pct = parseFloat(dfiPvPctInput?.value) || 0;
    const packagePv = pvRate;
    const dfi = packagePv * (pct / 100) * pvPerPesoRate;
    dfiPvPreviewEl.textContent = '₱' + dfi.toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  if (pvRateInput)   pvRateInput.addEventListener('input', updateDfiPvPreview);
  if (dfiPvPctInput) dfiPvPctInput.addEventListener('input', updateDfiPvPreview);

  // ── Indirect referral preview live update ──
  const indirectPreviewEl = document.getElementById('indirectPreview');

  function updateIndirectPreview() {
    if (!indirectPreviewEl) return;
    const pvRate = parseFloat(pvRateInput?.value) || 0;
    const packagePv = pvRate;
    let total = 0;
    for (let i = 1; i <= 10; i++) {
      const el = document.getElementById('indirect_' + i);
      const pct = parseFloat(el?.value) || 0;
      total += packagePv * (pct / 100) * pvPerPesoRate;
    }
    indirectPreviewEl.textContent = '₱' + total.toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  if (pvRateInput) pvRateInput.addEventListener('input', updateIndirectPreview);
  for (let i = 1; i <= 10; i++) {
    const el = document.getElementById('indirect_' + i);
    if (el) el.addEventListener('input', updateIndirectPreview);
  }

  // ── Reset form for "New Package" ──
  function resetPackageForm() {
    const form = document.getElementById('packageForm');
    form.reset();
    document.getElementById('packageModalTitle').textContent = '➕ New Package';
    document.getElementById('packageId').value = '';
    document.getElementById('pkgSubmitBtn').textContent = '➕ Create Package';
    previewEl.textContent = '₱0.00';
    updatePackagePVPreview();
    updateBinaryPvPreview();
    updateDirectRefPreview();
    updateDfiPvPreview();
    updateIndirectPreview();
    // Reset indirect levels
    for (let i = 1; i <= 10; i++) {
      const el = document.getElementById('indirect_' + i);
      if (el) el.value = '0.00';
    }
  }

  // ── Auto-open modal when editing (URL has ?edit=ID) ──
  <?php if ($editPkg): ?>
  document.addEventListener('DOMContentLoaded', function() {
    const modalEl = document.getElementById('packageModal');
    const modal = new bootstrap.Modal(modalEl);
    document.getElementById('packageModalTitle').textContent = '✏️ Edit Package';
    document.getElementById('pkgSubmitBtn').textContent = '💾 Update Package';
    updatePackagePVPreview();
    updateBinaryPvPreview();
    updateDirectRefPreview();
    updateDfiPvPreview();
    updateIndirectPreview();
    modal.show();
  });
  <?php endif; ?>

  // ── Loading state on submit ──
  document.getElementById('packageForm').addEventListener('submit', function() {
    const btn = document.getElementById('pkgSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving…';
  });
</script>

<?php require 'views/partials/footer.php'; ?>
