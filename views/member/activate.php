<?php
/**
 * @file   views/member/activate.php
 * @brief  Member activation page for pending referral-link accounts
 */
?>
<?php $pageTitle = 'Activate Account'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="row justify-content-center">
      <div class="col-12 col-md-8 col-lg-6">
        <!-- Pending Banner -->
        <div class="card mb-3 border-warning" style="border-width:2px;">
          <div class="card-body">
            <div class="d-flex align-items-center gap-3">
              <div style="font-size:2rem;">⏳</div>
              <div>
                <h5 class="fw-700 mb-0">Account Pending Activation</h5>
                <p class="text-muted mb-0" style="font-size:.8rem;">
                  Your binary position is reserved. Choose a package to activate and start earning.
                </p>
              </div>
            </div>
          </div>
        </div>

        <!-- Activation Form -->
        <div class="card">
          <div class="card-header"><span class="card-title">⚡ Activate Account</span></div>
          <div class="card-body">
            <form method="POST" action="<?= APP_URL ?>/?page=do_activate" id="activateForm">
              <?= csrf_field() ?>

              <!-- Payment Method -->
              <div class="mb-3">
                <label class="form-label fw-semibold" style="font-size:.85rem;">Payment Method</label>
                <div class="payment-methods">
                  <label class="payment-option" for="pay_code">
                    <input type="radio" id="pay_code" name="payment_method" value="code" checked required>
                    <span class="payment-radio"></span>
                    <span class="payment-icon">🎫</span>
                    <span class="payment-text">Code</span>
                  </label>
                  <?php if ($canUseEwallet): ?>
                  <label class="payment-option" for="pay_ewallet">
                    <input type="radio" id="pay_ewallet" name="payment_method" value="ewallet">
                    <span class="payment-radio"></span>
                    <span class="payment-icon">💳</span>
                    <span class="payment-text">E-Wallet</span>
                  </label>
                  <?php endif; ?>
                </div>
              </div>

              <style>
                .payment-methods {
                  display: flex;
                  flex-direction: column;
                  gap: 0.5rem;
                }
                .payment-option {
                  display: flex;
                  align-items: center;
                  gap: 0.625rem;
                  padding: 0.6rem 0.75rem;
                  border: 1.5px solid #e2e8f0;
                  border-radius: 0.55rem;
                  cursor: pointer;
                  transition: all 0.15s ease;
                  background: #fff;
                }
                .payment-option:hover {
                  border-color: #94a3b8;
                  background: #f8fafc;
                }
                .payment-option input[type="radio"] {
                  display: none;
                }
                .payment-radio {
                  width: 18px;
                  height: 18px;
                  border: 2px solid #cbd5e1;
                  border-radius: 50%;
                  display: flex;
                  align-items: center;
                  justify-content: center;
                  flex-shrink: 0;
                  transition: all 0.15s ease;
                }
                .payment-radio::after {
                  content: '';
                  width: 9px;
                  height: 9px;
                  border-radius: 50%;
                  background: #3b6ff0;
                  transform: scale(0);
                  transition: transform 0.15s ease;
                }
                .payment-option input[type="radio"]:checked + .payment-radio {
                  border-color: #3b6ff0;
                }
                .payment-option input[type="radio"]:checked + .payment-radio::after {
                  transform: scale(1);
                }
                .payment-option:has(input[type="radio"]:checked) {
                  border-color: #3b6ff0;
                  background: #f0f4ff;
                }
                .payment-icon {
                  font-size: 1.1rem;
                  line-height: 1;
                  flex-shrink: 0;
                }
                .payment-text {
                  font-size: 0.9rem;
                  font-weight: 500;
                  color: #1e293b;
                }
              </style>

              <!-- Code Input -->
              <div id="codeSection">
                <div class="mb-3">
                  <label class="form-label">Registration Code <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <input type="text" id="reg_code" name="reg_code" class="form-control font-mono"
                      placeholder="XXXX-XXXX-XXXX" maxlength="14"
                      style="text-transform:uppercase;letter-spacing:2px;font-size:1rem;" required>
                    <button type="button" class="btn btn-outline-primary" id="validateCodeBtn">Validate</button>
                  </div>
                  <div class="form-text" id="codeHint"></div>
                </div>
                <input type="hidden" name="validated_code" id="validatedCode">

                <div id="packageInfo" class="code-verified d-none">
                  <span class="verify-icon">✅</span>
                  <div class="verify-body">
                    <div class="verify-title" id="pkgName"></div>
                    <div class="verify-subtitle" id="pkgDetails"></div>
                  </div>
                </div>

                <style>
                  #packageInfo.code-verified {
                    display: flex;
                    align-items: flex-start;
                    gap: 0.625rem;
                    background: #ecfdf5;
                    border: 1px solid #bbf7d0;
                    border-radius: 0.55rem;
                    padding: 0.875rem 1rem;
                    margin-bottom: 1rem;
                  }
                  #packageInfo.code-verified .verify-icon {
                    font-size: 1.25rem;
                    line-height: 1.4;
                    flex-shrink: 0;
                    margin-top: 0;
                  }
                  #packageInfo.code-verified .verify-body {
                    display: flex;
                    flex-direction: column;
                    gap: 0.125rem;
                    min-width: 0;
                  }
                  #packageInfo.code-verified .verify-title {
                    font-weight: 700;
                    font-size: 0.95rem;
                    color: #065f46;
                    line-height: 1.4;
                  }
                  #packageInfo.code-verified .verify-subtitle {
                    font-size: 0.8rem;
                    color: #047857;
                    line-height: 1.4;
                  }
                </style>
              </div>

              <!-- E-Wallet Package Selector -->
              <?php if ($canUseEwallet): ?>
              <div id="packageSection" style="display:none;">
                <div class="mb-3">
                  <label class="form-label">Package <span class="text-danger">*</span></label>
                  <?php $pkgCount = count($packages); ?>
                  <?php if ($pkgCount === 1): ?>
                    <?php $pkg = $packages[0]; ?>
                    <input type="hidden" name="package_id" id="packageId" value="<?= (int)$pkg['id'] ?>">
                    <div class="card border-primary">
                      <div class="card-body">
                        <div class="fw-bold text-primary"><?= e($pkg['name']) ?></div>
                        <div style="font-size:.8rem;color:var(--muted);">
                          Entry: <?= fmt_money((float)$pkg['entry_fee']) ?> ·
                          Bonus: <?= fmt_money((float)$pkg['pairing_bonus']) ?> ·
                          Cap: <?= (int)$pkg['daily_pair_cap'] ?> pairs/day
                        </div>
                      </div>
                    </div>
                    <div class="form-text text-success">✓ Package auto-selected.</div>
                  <?php else: ?>
                    <select class="form-select" id="packageSelect" name="package_id">
                      <option value="">Select a package…</option>
                      <?php foreach ($packages as $pkg): ?>
                        <option value="<?= (int)$pkg['id'] ?>"
                          data-name="<?= e($pkg['name']) ?>"
                          data-fee="<?= fmt_money((float)$pkg['entry_fee']) ?>"
                          data-bonus="<?= fmt_money((float)$pkg['pairing_bonus']) ?>"
                          data-cap="<?= (int)$pkg['daily_pair_cap'] ?>">
                          <?= e($pkg['name']) ?> — <?= fmt_money((float)$pkg['entry_fee']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <div class="form-text" id="packageHint"></div>
                    <div id="packageCard" class="code-verified d-none mt-2">
                      <span style="font-size:1.2rem;">📦</span>
                      <div>
                        <div class="fw-bold" id="pkgCardName"></div>
                        <div style="font-size:.75rem;margin-top:2px;" id="pkgCardDetails"></div>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="alert alert-info py-2 mb-3" style="font-size:.8rem;">
                  💳 Your balance:
                  <strong><?= fmt_money((float)($user['ewallet_balance'] ?? 0)) ?></strong>
                  (<?= fmt_money((float)($user['withdrawable_balance'] ?? 0)) ?> withdrawable)
                </div>
              </div>
              <?php endif; ?>

              <button type="submit" class="btn btn-primary w-100 btn-lg" id="submitBtn" disabled>
                ⚡ Activate Account
              </button>
            </form>

            <a href="<?= APP_URL ?>/?page=dashboard" class="btn btn-link btn-sm w-100 mt-2">← Back to Dashboard</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const IS_EWALLET_ENABLED = <?= $canUseEwallet ? 'true' : 'false' ?>;
  const PKG_COUNT = <?= (int)count($packages) ?>;
  let codeData = {}, selectedPkg = {};

  function setHint(id, msg, ok) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = msg;
    el.className = 'form-text' + (ok === true ? ' text-success' : ok === false ? ' text-danger' : '');
  }

  function getPaymentMethod() {
    return document.querySelector('[name=payment_method]:checked')?.value || 'code';
  }

  function updateSubmitState() {
    const method = getPaymentMethod();
    const btn = document.getElementById('submitBtn');
    if (method === 'code') {
      btn.disabled = !document.getElementById('validatedCode').value;
    } else {
      if (PKG_COUNT === 1) {
        btn.disabled = false;
      } else {
        btn.disabled = !document.getElementById('packageSelect')?.value;
      }
    }
  }

  // Payment toggle
  document.querySelectorAll('[name=payment_method]').forEach(r => {
    r.addEventListener('change', function() {
      const method = this.value;
      const codeSec = document.getElementById('codeSection');
      const pkgSec = document.getElementById('packageSection');
      const regCode = document.getElementById('reg_code');
      const pkgSel = document.getElementById('packageSelect');

      if (method === 'code') {
        if (codeSec) codeSec.style.display = 'block';
        if (pkgSec) pkgSec.style.display = 'none';
        if (regCode) regCode.required = true;
        if (pkgSel) pkgSel.required = false;
      } else {
        if (codeSec) codeSec.style.display = 'none';
        if (pkgSec) pkgSec.style.display = 'block';
        if (regCode) regCode.required = false;
        if (pkgSel) pkgSel.required = true;
      }
      updateSubmitState();
    });
  });

  // Code formatting
  document.getElementById('reg_code').addEventListener('input', function() {
    const clean = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase().slice(0, 12);
    const parts = [clean.slice(0, 4), clean.slice(4, 8), clean.slice(8, 12)].filter(Boolean);
    this.value = parts.join('-');
    document.getElementById('validatedCode').value = '';
    document.getElementById('packageInfo').classList.add('d-none');
    setHint('codeHint', '', null);
    codeData = {};
    updateSubmitState();
  });

  // Validate code
  document.getElementById('validateCodeBtn').addEventListener('click', async function() {
    const code = document.getElementById('reg_code').value.trim();
    if (code.length < 14) {
      setHint('codeHint', 'Enter a complete code (XXXX-XXXX-XXXX)', false);
      return;
    }
    this.disabled = true;
    this.textContent = '…';
    try {
      const fd = new FormData();
      fd.append('code', code);
      fd.append('csrf_token', document.querySelector('[name=csrf_token]').value);
      const data = await (await fetch('<?= APP_URL ?>/?page=validate_code', {
        method: 'POST',
        body: fd
      })).json();
      if (data.valid) {
        codeData = data;
        document.getElementById('pkgName').textContent = data.package_name;
        document.getElementById('pkgDetails').textContent =
          'Entry: ' + data.entry_fee + ' · Bonus: ' + data.pairing_bonus + ' · Cap: ' + data.daily_cap + ' pairs/day';
        document.getElementById('packageInfo').classList.remove('d-none');
        document.getElementById('validatedCode').value = code;
        setHint('codeHint', '✓ Code is valid!', true);
      } else {
        setHint('codeHint', data.message || 'Invalid code.', false);
      }
    } catch (e) {
      setHint('codeHint', 'Network error.', false);
    }
    this.disabled = false;
    this.textContent = 'Validate';
    updateSubmitState();
  });

  // Package selector
  const packageSelect = document.getElementById('packageSelect');
  if (packageSelect) {
    packageSelect.addEventListener('change', function() {
      const opt = this.options[this.selectedIndex];
      if (!this.value) {
        selectedPkg = {};
        document.getElementById('packageCard')?.classList.add('d-none');
        setHint('packageHint', '', null);
        updateSubmitState();
        return;
      }
      selectedPkg = {
        id: this.value,
        name: opt.dataset.name,
        fee: opt.dataset.fee,
        bonus: opt.dataset.bonus,
        cap: opt.dataset.cap
      };
      document.getElementById('pkgCardName').textContent = selectedPkg.name;
      document.getElementById('pkgCardDetails').textContent =
        'Entry: ' + selectedPkg.fee + ' · Bonus: ' + selectedPkg.bonus + ' · Cap: ' + selectedPkg.cap + ' pairs/day';
      document.getElementById('packageCard').classList.remove('d-none');
      setHint('packageHint', '✓ Package selected.', true);
      updateSubmitState();
    });
  }

  // Submit spinner
  document.getElementById('activateForm').addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Activating…';
  });

  updateSubmitState();
})();
</script>
<?php require 'views/partials/footer.php'; ?>
