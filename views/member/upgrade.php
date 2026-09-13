<?php

/**
 * @file   views/member/upgrade.php
 * @brief  Package upgrade page (diff-only payment, optional binary placement)
 */
$pageTitle = 'Upgrade Package';
require 'views/partials/head.php';
require 'views/partials/sidebar_member.php';
?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="row g-3 mb-3">
      <div class="col-12">
        <div class="card stat-card">
          <div class="card-body py-4">
            <div class="d-flex flex-wrap align-items-center gap-4">
              <div class="d-flex align-items-center gap-3">
                <div style="width:52px;height:52px;border-radius:.875rem;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:1.4rem;">⬆️</div>
                <div>
                  <div class="stat-label">Current Package</div>
                  <div style="font-size:1.3rem;font-weight:800;"><?= e($current['name'] ?? '—') ?></div>
                  <div class="text-muted" style="font-size:.78rem;">Entry Fee: <?= fmt_money($curFee ?? 0) ?></div>
                </div>
              </div>
              <div class="ms-auto">
                <span class="badge <?= $curPairing ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>">
                  <?= $curPairing ? '🌳 Binary Network' : '👥 Non-Binary Package' ?>
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php if (empty($targets)): ?>
      <div class="card">
        <div class="card-body text-center py-5 text-muted">
          <div style="font-size:2.5rem;">🎉</div>
          <p class="mt-2 mb-0">You are already on the highest package. No upgrades available.</p>
        </div>
      </div>
    <?php else: ?>
      <div class="row g-3">
        <div class="col-12 col-lg-7">
          <div class="card">
            <div class="card-header"><span class="card-title">🚀 Select Upgrade Package</span></div>
            <div class="card-body">
              <?php foreach ($targets as $pkg):
                $targetPairing = Package::hasPairing((int)$pkg['id']);
              ?>
                <div class="border rounded-3 p-3 mb-3 upgrade-option"
                  data-id="<?= (int)$pkg['id'] ?>"
                  data-name="<?= e($pkg['name']) ?>"
                  data-diff="<?= (float)$pkg['diff'] ?>"
                  data-current="<?= fmt_money((float)$pkg['entry_fee']) ?>" style="cursor:pointer;">
                  <div class="d-flex align-items-center gap-3">
                    <div class="form-check mb-0">
                      <input class="form-check-input upgrade-radio" type="radio" name="upgrade_pkg" value="<?= (int)$pkg['id'] ?>" id="upg<?= (int)$pkg['id'] ?>">
                    </div>
                    <div class="flex-grow-1">
                      <div class="fw-bold"><?= e($pkg['name']) ?> <span class="text-muted fw-normal" style="font-size:.72rem;">(entry <?= fmt_money((float)$pkg['entry_fee']) ?>)</span></div>
                      <div style="display:flex;gap:8px;margin-top:4px;align-items:center;">
                        <span class="badge bg-primary-subtle text-primary" style="font-size:.68rem;">New Entry <?= fmt_money((float)$pkg['entry_fee']) ?></span>
                        <span class="badge <?= $targetPairing ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>" style="font-size:.68rem;"><?= $targetPairing ? '🌳 Binary' : '👥 Non-Binary' ?></span>
                        <?php if (Package::hasDfiToggle((int)$pkg['id']) && (float)$pkg['daily_fixed_income'] > 0): ?>
                          <span class="badge bg-danger-subtle text-danger" style="font-size:.68rem;">📅 DFI</span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="text-end">
                      <div class="text-muted" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.5px;">To Pay</div>
                      <div class="fw-bold text-success font-mono" style="font-size:1.05rem;"><?= fmt_money((float)$pkg['diff']) ?></div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Binary placement (non-binary → pairing only) -->
          <div id="binaryPlacementCard" class="card d-none">
            <div class="card-header"><span class="card-title">🌳 Join the Binary Network</span></div>
            <div class="card-body">
              <div class="alert alert-info py-2 mb-3" style="font-size:.8rem;">
                This package is part of the binary network. Select where your position will be placed.
              </div>
              <div class="mb-3">
                <label class="form-label">Search Binary Upline</label>
                <input type="text" id="uplineSearch" class="form-control" placeholder="Type a member username…" autocomplete="off">
                <div class="form-text">Only active members on a binary package with an available slot are shown.</div>
              </div>
              <div id="uplineResults" class="dropdown-menu show w-100" style="max-height:220px;overflow-y:auto;display:none;"></div>
              <div class="row g-3 mt-1">
                <div class="col-12">
                  <div class="form-control bg-light" id="selectedUplineBox">No upline selected.</div>
                </div>
                <div class="col-12">
                  <label class="form-label">Binary Position</label>
                  <div class="position-toggle" style="grid-template-columns:1fr 1fr;">
                    <div class="position-option">
                      <input type="radio" id="upg_pos_left" name="upg_binary_position" value="left">
                      <label class="position-label" for="upg_pos_left">↙ Left</label>
                    </div>
                    <div class="position-option">
                      <input type="radio" id="upg_pos_right" name="upg_binary_position" value="right">
                      <label class="position-label" for="upg_pos_right">↘ Right</label>
                    </div>
                  </div>
                  <div class="form-text" id="upgPosHint"></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-5">
          <div class="card">
            <div class="card-header"><span class="card-title">💳 Payment</span></div>
            <div class="card-body">
              <form method="POST" action="<?= link_to('do_upgrade') ?>" id="upgradeForm">
                <?= csrf_field() ?>
                <input type="hidden" name="package_id" id="upgPackageId" value="">
                <input type="hidden" name="binary_upline_id" id="upgUplineId" value="">
                <input type="hidden" name="binary_position" id="upgPosition" value="">

                <div class="mb-2 d-flex justify-content-between align-items-center">
                  <span class="text-muted" style="font-size:.78rem;">Your E-Wallet Balance</span>
                  <span class="font-mono fw-bold"><?= fmt_money($balance) ?></span>
                </div>
                <div class="card mb-3 p-3">
                  <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2">
                    <span>Upgrade Fee</span>
                    <span class="font-mono fw-bold" id="upgFee">—</span>
                  </div>
                  <div class="d-flex justify-content-between align-items-center">
                    <span>You Pay</span>
                    <span class="font-mono fw-bold text-success" id="upgPay">—</span>
                  </div>
                </div>

                <div class="mb-3">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="radio" name="payment_method" id="pay_ewlg" value="ewallet" <?= $balance > 0 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="pay_ewlg">💳 Pay from E-Wallet</label>
                  </div>
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="radio" name="payment_method" id="pay_upcode" value="code" <?= $balance > 0 ? '' : 'checked' ?>>
                    <label class="form-check-label" for="pay_upcode">🎫 Upgrade Code</label>
                  </div>
                </div>

                <div id="codeInputSection" class="mb-3" style="display:none;">
                  <label class="form-label">Upgrade Code</label>
                  <input type="text" name="upgrade_code" id="upgrade_code" class="form-control font-mono" placeholder="UP-XXXX-XXXX-XXXX" maxlength="18" style="text-transform:uppercase;letter-spacing:2px;">
                </div>

                <button type="submit" class="btn btn-primary w-100 btn-lg" id="upgSubmitBtn" disabled>Upgrade Package</button>
                <div class="form-text mt-2">Only the difference in entry fee is charged. You keep the current package's value.</div>
              </form>
            </div>
          </div>
          <div class="alert alert-warning py-2" style="font-size:.78rem;">
            ⚠️ Setup permissions: admin approved payment may be asked for external methods. Downgrades are not allowed.
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
  const PACKAGE_INFO = <?= json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'pairing_enabled' => Package::hasPairing((int)$p['id'])], $targets) ) ?>;
  const APP = '<?= APP_URL ?>';
  const API_UPLINES = '<?= link_to('api_binary_uplines') ?>';
  const CSRF = '<?= csrf_token() ?>';
  let currentPairing = <?= $curPairing ? 'true' : 'false' ?>;
  let selectedUpline = null;
  let selectedUplinePos = '';

  function fmtMoney(v) {
    return '₱' + Number(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function enableSubmit() {
    const binCard = document.getElementById('binaryPlacementCard');
    const needsBin = !binCard.classList.contains('d-none');
    if (needsBin && !selectedUpline) {
      document.getElementById('upgSubmitBtn').disabled = true;
      return;
    }
    if (needsBin && !selectedUplinePos) {
      document.getElementById('upgSubmitBtn').disabled = true;
      return;
    }
    document.getElementById('upgSubmitBtn').disabled = false;
  }

  const upgOptions = document.querySelectorAll('.upgrade-option');
  upgOptions.forEach(opt => {
    opt.addEventListener('click', function() {
      const radio = this.querySelector('.upgrade-radio');
      if (radio) radio.checked = true;
      upgOptions.forEach(o => o.classList.remove('border-primary'));
      this.classList.add('border-primary');
      const id = parseInt(this.dataset.id);
      document.getElementById('upgPackageId').value = id;
      const diff = parseFloat(this.dataset.diff);
      document.getElementById('upgFee').textContent = fmtMoney(diff);
      document.getElementById('upgPay').textContent = fmtMoney(diff);
      const pkg = PACKAGE_INFO.find(p => p.id === id);
      const needsPlacement = !currentPairing && pkg && pkg.pairing_enabled;
      document.getElementById('binaryPlacementCard').classList.toggle('d-none', !needsPlacement);
      if (!needsPlacement) {
        selectedUpline = null;
        selectedUplinePos = '';
        document.getElementById('selectedUplineBox').textContent = 'No upline selected.';
        document.querySelectorAll('[name=upg_binary_position]').forEach(r => r.checked = false);
        const hidden = document.getElementById('upgUplineId');
        if (hidden) hidden.value = '';
      }
      enableSubmit();
    });
  });

  // Payment method toggle
  const payEw = document.getElementById('pay_ewlg');
  const payCode = document.getElementById('pay_upcode');
  const codeInput = document.getElementById('codeInputSection');
  function updatePaymentUI() {
    const method = payEw.checked ? 'ewallet' : 'code';
    codeInput.style.display = method === 'code' ? 'block' : 'none';
  }
  payEw.addEventListener('change', updatePaymentUI);
  payCode.addEventListener('change', updatePaymentUI);
  updatePaymentUI();

  // Binary upline search
  const searchBox = document.getElementById('uplineSearch');
  const resultsBox = document.getElementById('uplineResults');
  let upTimer;
  searchBox.addEventListener('input', function() {
    clearTimeout(upTimer);
    const q = this.value.trim();
    if (q.length < 2) { resultsBox.style.display = 'none'; return; }
    upTimer = setTimeout(async () => {
      const d = await (await fetch(API_UPLINES + '&q=' + encodeURIComponent(q))).json();
      resultsBox.innerHTML = '';
      const cands = d.candidates || [];
      if (!cands.length) {
        resultsBox.innerHTML = '<div class="dropdown-item text-muted">No matching members with available slots.</div>';
      } else {
        cands.forEach(c => {
          const el = document.createElement('button');
          el.type = 'button';
          el.className = 'dropdown-item';
          el.innerHTML = '<div class="fw-bold">@' + c.username + '</div>' +
            '<div class="text-muted" style="font-size:.72rem;">' + c.package + ' · Left: ' + (c.left_free ? '✓' : '✗') + ' · Right: ' + (c.right_free ? '✓' : '✗') + '</div>';
          el.addEventListener('click', function() {
            selectedUpline = c;
            selectedUplinePos = '';
            const h = document.getElementById('upgUplineId');
            if (h) h.value = c.id;
            document.getElementById('selectedUplineBox').textContent = 'Upline: @' + c.username + ' (must pick a position)';
            resultsBox.style.display = 'none';
            searchBox.value = '';
            // Enable available position radios only
            document.getElementById('upg_pos_left').disabled = !c.left_free;
            document.getElementById('upg_pos_right').disabled = !c.right_free;
            document.querySelectorAll('[name=upg_binary_position]').forEach(r => r.checked = false);
            document.getElementById('upgPosHint').textContent = 'Select Left or Right.';
            enableSubmit();
          });
          resultsBox.appendChild(el);
        });
      }
      resultsBox.style.display = 'block';
    }, 400);
  });

  document.querySelectorAll('[name=upg_binary_position]').forEach(r => {
    r.addEventListener('change', function() {
      selectedUplinePos = this.value;
      document.getElementById('upgPosHint').textContent = '';
      const h = document.getElementById('upgPosition');
      if (h) h.value = this.value;
      enableSubmit();
    });
  });
</script>

<style>
  .upgrade-option:hover { border-color: var(--primary); }
</style>
<?php require 'views/partials/footer.php'; ?>