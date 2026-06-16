<?php

/**
 * @file   views/auth/register.php
 * @brief  Registration UI
 */
?>
<?php
$pageTitle      = 'Register Member — ' . setting('site_name', APP_NAME);
$isLoggedIn     = Auth::check();
$currentUser    = $isLoggedIn ? Auth::user() : null;
$packages       = $packages ?? [];
$canUseEwallet  = $canUseEwallet ?? false;
if ($isLoggedIn && !$canUseEwallet && !empty($packages)) {
  $minEntryFee   = min(array_map(fn($pkg) => (float)$pkg['entry_fee'], $packages));
  $canUseEwallet = (float)($currentUser['ewallet_balance'] ?? 0) >= $minEntryFee;
}
$prefillSponsor = $prefillSponsor ?? trim($_GET['sponsor'] ?? '');
$isReferralMode = ($isReferralMode ?? false);
$prefillUpline   = $prefillUpline   ?? '';
$prefillPosition = $prefillPosition ?? 'left';
$lockSponsor     = $isReferralMode;
// Auto-prefill sponsor with current user's username for both members AND admins
if ($isLoggedIn && !$prefillSponsor) {
  $prefillSponsor = $currentUser['username'];
}
?>
<?php if ($isLoggedIn): ?>
  <?php require 'views/partials/head.php'; ?>
  <!-- auth.css needed for step bar, position toggle, slot status -->
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/auth.css">
  <?php if (Auth::isAdmin()): ?>
    <?php require 'views/partials/sidebar_admin.php'; ?>
  <?php else: ?>
    <?php require 'views/partials/sidebar_member.php'; ?>
  <?php endif; ?>
  <div class="main-content">
    <?php require 'views/partials/topbar.php'; ?>
    <div class="page-content">
      <?= render_flash() ?>
      <div class="d-flex justify-content-center">
        <div style="width:100%;max-width:560px;">
        <?php else: ?>
          <!DOCTYPE html>
          <html lang="en">

          <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?= e($pageTitle) ?></title>
            <meta name="robots" content="noindex,nofollow">
            <link rel="icon" href="<?= APP_URL ?>/assets/img/favicon.png" type="image/png">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
            <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
            <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/auth.css">
          </head>

          <body>
            <div class="auth-page">
            <?php endif; ?>

            <div class="auth-card auth-card-wide <?= $isLoggedIn ? 'shadow' : '' ?>">

              <?php if ($isLoggedIn): ?>
                <!-- Logged-in header strip -->
                <div class="d-flex align-items-center gap-3 px-4 py-3 border-bottom" style="background:#f8fafd;">
                  <div style="width:38px;height:38px;border-radius:.625rem;background:var(--primary);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;">
                    <img src="<?= APP_URL ?>/assets/img/logo.png" style="width:24px;height:24px;object-fit:contain;" alt="">
                  </div>
                  <div class="flex-grow-1">
                    <div style="font-size:.875rem;font-weight:700;">Register New Member</div>
                    <div style="font-size:.72rem;color:var(--muted);">Registering as <strong>@<?= e($currentUser['username']) ?></strong></div>
                  </div>
                  <a href="<?= Auth::isAdmin() ? APP_URL . '/?page=admin' : APP_URL . '/?page=dashboard' ?>"
                    class="btn btn-sm btn-outline-secondary">✕ Cancel</a>
                </div>
              <?php else: ?>
                <!-- Guest header -->
                <div class="auth-header">
                  <div class="auth-logo"><img src="<?= APP_URL ?>/assets/img/logo.png" alt="Logo"></div>
                  <h1><?= e(setting('site_name', APP_NAME)) ?></h1>
                  <p>Create your member account</p>
                </div>
              <?php endif; ?>

              <!-- Step bar -->
              <div class="steps-bar" id="stepsBar">
                <?php if (!$isReferralMode): ?>
                <div class="reg-step active" id="step-ind-1">
                  <div class="step-dot">1</div>
                  <div class="step-text">Select Package</div>
                </div>
                <?php endif; ?>
                <div class="reg-step <?= $isReferralMode ? 'active' : '' ?>" id="step-ind-2">
                  <div class="step-dot"><?= $isReferralMode ? '1' : '2' ?></div>
                  <div class="step-text">Account Setup</div>
                </div>
                <div class="reg-step" id="step-ind-3">
                  <div class="step-dot"><?= $isReferralMode ? '2' : '3' ?></div>
                  <div class="step-text">Confirm</div>
                </div>
              </div>

              <div style="padding:0 2.25rem;" id="flashArea"><?= render_flash() ?></div>

              <form method="POST" action="<?= APP_URL ?>/?page=do_register" id="regForm">
                <?= csrf_field() ?>
                <?php if ($isReferralMode): ?>
                  <input type="hidden" name="referral_mode" value="1">
                <?php endif; ?>

                <!-- ── STEP 1 ── -->
                <div class="auth-body" id="step1" <?= $isReferralMode ? 'style="display:none;"' : '' ?>>
                  <?php if ($isLoggedIn): ?>
                    <p class="text-muted mb-3" style="font-size:.85rem;">Choose payment method and package for the new member.</p>

                    <!-- Payment Method Toggle (logged-in only) -->
                    <div class="mb-3">
                      <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                      <div class="position-toggle">
                        <div class="position-option">
                          <input type="radio" id="pay_code" name="payment_method" value="code" checked required>
                          <label class="position-label" for="pay_code">🎫 Code</label>
                        </div>
                        <div class="position-option">
                          <input type="radio" id="pay_ewallet" name="payment_method" value="ewallet">
                          <label class="position-label" for="pay_ewallet">💳 E-Wallet</label>
                        </div>
                      </div>
                    </div>
                  <?php else: ?>
                    <p class="text-muted mb-3" style="font-size:.85rem;">Enter your registration code to get started.</p>
                    <input type="hidden" name="payment_method" value="code">
                  <?php endif; ?>

                  <!-- Code Input -->
                  <div id="codeSection">
                    <div class="mb-3">
                      <label class="form-label">Registration Code <span class="text-danger">*</span></label>
                      <div class="input-group">
                        <input type="text" id="reg_code" name="reg_code" class="form-control font-mono"
                          placeholder="XXXX-XXXX-XXXX or CD-XXXX-XXXX-XXXX" maxlength="18"
                          style="text-transform:uppercase;letter-spacing:2px;font-size:1rem;" required>
                        <button type="button" class="btn btn-outline-primary" id="validateCodeBtn">Validate</button>
                      </div>
                      <div class="form-text" id="codeHint"></div>
                    </div>
                  </div>

                  <?php if ($isLoggedIn): ?>
                    <!-- Package Selector (E-Wallet) -->
                    <div id="packageSection" style="display:none;">
                      <?php if ($canUseEwallet): ?>
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
                          <strong><?= fmt_money((float)($currentUser['ewallet_balance'] ?? 0)) ?></strong>
                          (<?= fmt_money((float)($currentUser['withdrawable_balance'] ?? 0)) ?> withdrawable)
                        </div>
                      <?php else: ?>
                        <div class="alert alert-warning py-3 mb-3">
                          <div class="d-flex align-items-center gap-2">
                            <span style="font-size:1.25rem;">⚠️</span>
                            <div>
                              <strong>Insufficient E-Wallet Balance</strong>
                              <div style="font-size:.8rem;opacity:.9;">
                                Your current balance is <?= fmt_money((float)($currentUser['ewallet_balance'] ?? 0)) ?>.
                                The minimum entry fee is <?= fmt_money((float)(!empty($packages) ? min(array_map(fn($p) => (float)$p['entry_fee'], $packages)) : 0)) ?>.
                                Please top up or switch to registration code.
                              </div>
                            </div>
                          </div>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>

                  <div id="packageInfo" class="code-verified d-none">
                    <span style="font-size:1.2rem;">✅</span>
                    <div>
                      <div class="fw-bold" id="pkgName"></div>
                      <div style="font-size:.75rem;margin-top:2px;" id="pkgDetails"></div>
                    </div>
                  </div>
                  <input type="hidden" name="validated_code" id="validatedCode">
                  <button type="button" class="btn btn-primary w-100 btn-lg" id="toStep2Btn" disabled>Continue →</button>
                </div>

                <!-- ── STEP 2 ── -->
                <div class="auth-body" id="step2" <?= $isReferralMode ? '' : 'style="display:none;"' ?>>
                  <?php if ($isReferralMode): ?>
                    <div class="alert alert-info py-2 mb-3" style="font-size:.85rem;">
                      🔗 You are registering via a referral link. No payment is required now — you can activate your account later with a registration code or e-wallet.
                    </div>
                  <?php endif; ?>
                  <div class="mb-3">
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" id="username" name="username" class="form-control"
                      placeholder="3–40 chars, letters/numbers/_" minlength="3" maxlength="40"
                      autocomplete="off" required>
                    <div class="form-text" id="usernameHint"></div>
                  </div>
                  <div class="row g-3 mb-3">
                    <div class="col-md-6">
                      <label class="form-label">Password <span class="text-danger">*</span></label>
                      <div class="input-group">
                        <input type="password" id="password" name="password" class="form-control"
                          placeholder="Min. 8 characters" minlength="8" required>
                        <button type="button" class="btn btn-outline-secondary" onclick="togglePw('password',this)">👁</button>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                      <div class="input-group">
                        <input type="password" id="password_confirm" name="password_confirm"
                          class="form-control" placeholder="Repeat password" required>
                        <button type="button" class="btn btn-outline-secondary" onclick="togglePw('password_confirm',this)">👁</button>
                      </div>
                      <div class="form-text" id="pwMatchHint"></div>
                    </div>
                  </div>
                  <hr class="my-3">
                  <div class="mb-3">
                    <label class="form-label">Sponsor Username <span class="text-danger">*</span></label>
                    <input type="text" id="sponsor_username" name="sponsor_username"
                      class="form-control <?= $lockSponsor ? 'bg-light' : '' ?>"
                      placeholder="Sponsor's username"
                      value="<?= e($prefillSponsor) ?>"
                      <?= $lockSponsor ? 'readonly' : 'autocomplete="off"' ?> required>
                    <div class="form-text" id="sponsorHint"></div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Binary Upline Username <span class="text-danger">*</span></label>
                    <input type="text" id="upline_username" name="upline_username"
                      class="form-control"
                      placeholder="Upline in the binary tree"
                      value="<?= e($prefillUpline) ?>"
                      autocomplete="off" required>
                    <div class="form-text" id="uplineHint"></div>
                    <div id="slotStatus" class="slot-status d-none">
                      <span id="leftSlot">↙ Left: —</span>
                      <span id="rightSlot">↘ Right: —</span>
                    </div>
                  </div>
                  <div class="mb-4">
                    <label class="form-label">Binary Position <span class="text-danger">*</span></label>
                    <div class="position-toggle">
                      <div class="position-option">
                        <input type="radio" id="pos_left" name="binary_position" value="left"
                          <?= $prefillPosition === 'left' ? 'checked' : '' ?> required>
                        <label class="position-label" for="pos_left">↙ Left</label>
                      </div>
                      <div class="position-option">
                        <input type="radio" id="pos_right" name="binary_position" value="right"
                          <?= $prefillPosition === 'right' ? 'checked' : '' ?>>
                        <label class="position-label" for="pos_right">↘ Right</label>
                      </div>
                    </div>
                    <div class="form-text" id="positionHint"></div>
                  </div>
                  <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary" onclick="goStep(1)">← Back</button>
                    <button type="button" class="btn btn-primary flex-grow-1" id="toStep3Btn">Review →</button>
                  </div>
                </div>

                <!-- ── STEP 3 ── -->
                <div class="auth-body" id="step3" style="display:none;">
                  <p class="text-muted mb-3" style="font-size:.85rem;">Review before completing registration.</p>
                  <div class="card mb-3">
                    <div class="card-header"><span class="card-title">📋 Registration Summary</span></div>
                    <div class="card-body">
                      <table class="info-table">
                        <?php if (!$isReferralMode): ?>
                        <tr>
                          <td>Payment</td>
                          <td id="rev_payment">—</td>
                        </tr>
                        <tr id="revCodeRow">
                          <td>Code</td>
                          <td><span class="reg-code" id="rev_code">—</span></td>
                        </tr>
                        <tr>
                          <td>Package</td>
                          <td id="rev_package">—</td>
                        </tr>
                        <?php else: ?>
                        <tr>
                          <td>Activation</td>
                          <td><span class="badge bg-warning text-dark">Pending</span></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                          <td>Username</td>
                          <td id="rev_username" class="fw-bold">—</td>
                        </tr>
                        <tr>
                          <td>Sponsor</td>
                          <td id="rev_sponsor">—</td>
                        </tr>
                        <tr>
                          <td>Upline</td>
                          <td id="rev_upline">—</td>
                        </tr>
                        <tr>
                          <td>Position</td>
                          <td id="rev_position">—</td>
                        </tr>
                      </table>
                    </div>
                  </div>
                  <div class="alert alert-warning py-2 mb-3" style="font-size:.8rem;">
                    ⚠️ Binary position cannot be changed after registration.
                  </div>
                  <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary" onclick="goStep(2)">← Back</button>
                    <button type="submit" class="btn btn-primary flex-grow-1 btn-lg" id="submitBtn">
                      ✓ Complete Registration
                    </button>
                  </div>
                </div>

              </form>

              <?php if (!$isLoggedIn): ?>
                <div class="auth-footer">
                  Already have an account? <a href="<?= APP_URL ?>/?page=login">Sign in →</a>
                </div>
                <div class="auth-footer" style="border-top:none;padding-top:0;">
                  <a href="<?= APP_URL ?>/">← Back to Home</a>
                </div>
              <?php endif; ?>

            </div><!-- .auth-card -->

            <?php if ($isLoggedIn): ?>
            </div><!-- max-width wrapper -->
        </div><!-- d-flex justify-content-center -->
      </div><!-- page-content -->
    </div><!-- main-content -->
  <?php else: ?>
  </div><!-- auth-page -->
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($isLoggedIn): ?>
  <script src="<?= APP_URL ?>/assets/js/app.js"></script>
<?php endif; ?>
<script>
  const API = '<?= APP_URL ?>';
  const IS_LOGGED_IN = <?= $isLoggedIn ? 'true' : 'false' ?>;
  const CAN_USE_EWALLET = <?= ($isLoggedIn && ($canUseEwallet ?? false)) ? 'true' : 'false' ?>;
  const LOCKED_SPONSOR = <?= $lockSponsor ? 'true' : 'false' ?>;
  const PREFILL_SPONSOR = <?= json_encode($prefillSponsor) ?>;
  const PKG_COUNT = <?= (int)count($packages) ?>;
  const IS_REFERRAL_MODE = <?= $isReferralMode ? 'true' : 'false' ?>;
  const PREFILL_UPLINE = <?= json_encode($prefillUpline) ?>;
  const PREFILL_POSITION = <?= json_encode($prefillPosition) ?>;

  let codeData = {},
    selectedPkg = {},
    usernameOk = false,
    sponsorOk = false,
    uplineOk = false,
    slotData = {};

  function goStep(n) {
    const steps = IS_REFERRAL_MODE ? [2, 3] : [1, 2, 3];
    steps.forEach(i => {
      const el = document.getElementById('step' + i);
      if (el) el.style.display = i === n ? 'block' : 'none';
    });
    steps.forEach(i => {
      const el = document.getElementById('step-ind-' + i);
      if (!el) return;
      el.className = 'reg-step ' + (i < n ? 'done' : i === n ? 'active' : '');
    });
    const flash = document.getElementById('flashArea');
    if (flash) flash.innerHTML = '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function setHint(id, msg, ok) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = msg;
    el.className = 'form-text' + (ok === true ? ' text-success' : ok === false ? ' text-danger' : '');
  }

  function togglePw(id, btn) {
    const el = document.getElementById(id);
    el.type = el.type === 'password' ? 'text' : 'password';
    btn.textContent = el.type === 'password' ? '👁' : '🙈';
  }

  // ── Payment Method Toggle ─────────────────────────────────────
  function getPaymentMethod() {
    if (!IS_LOGGED_IN) return 'code';
    return document.querySelector('[name=payment_method]:checked')?.value || 'code';
  }

  function updateStep1State() {
    if (IS_REFERRAL_MODE) return; // Step 1 is skipped entirely in referral mode

    const method = getPaymentMethod();
    const codeSec = document.getElementById('codeSection');
    const pkgSec = document.getElementById('packageSection');
    const regCode = document.getElementById('reg_code');
    const pkgSel = document.getElementById('packageSelect');
    const toBtn = document.getElementById('toStep2Btn');

    if (method === 'code') {
      codeSec.style.display = 'block';
      if (pkgSec) pkgSec.style.display = 'none';
      regCode.required = true;
      if (pkgSel) pkgSel.required = false;
      toBtn.disabled = !document.getElementById('validatedCode').value;
    } else {
      codeSec.style.display = 'none';
      if (pkgSec) pkgSec.style.display = 'block';
      regCode.required = false;
      if (pkgSel) pkgSel.required = true;
      // If e-wallet balance is insufficient, keep Continue disabled
      if (!CAN_USE_EWALLET) {
        toBtn.disabled = true;
        return;
      }
      // Enable continue if single package, or if dropdown has a value
      if (PKG_COUNT === 1) {
        toBtn.disabled = false;
      } else {
        toBtn.disabled = !(pkgSel && pkgSel.value);
      }
    }
  }

  document.querySelectorAll('[name=payment_method]').forEach(r => {
    r.addEventListener('change', function() {
      resetCodeState();
      resetPackageState();
      updateStep1State();
    });
  });

  // ── Package Selector (E-Wallet) ───────────────────────────────
  const packageSelect = document.getElementById('packageSelect');
  if (packageSelect) {
    packageSelect.addEventListener('change', function() {
      const opt = this.options[this.selectedIndex];
      if (!this.value) {
        resetPackageState();
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
      document.getElementById('toStep2Btn').disabled = false;
    });
  }

  function resetPackageState() {
    selectedPkg = {};
    if (packageSelect) {
      packageSelect.selectedIndex = 0;
      document.getElementById('packageCard')?.classList.add('d-none');
    }
    setHint('packageHint', '', null);
  }

  // ── Code formatting & validation ──────────────────────────────
  document.getElementById('reg_code').addEventListener('input', function() {
    let raw = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
    let formatted = '';
    if (raw.startsWith('CD')) {
      // CD code: CD-XXXX-XXXX-XXXX (max 14 chars after CD)
      const body = raw.slice(2).slice(0, 12);
      const parts = [body.slice(0, 4), body.slice(4, 8), body.slice(8, 12)].filter(Boolean);
      formatted = 'CD' + (parts.length ? '-' + parts.join('-') : '');
    } else {
      // Regular code: XXXX-XXXX-XXXX
      const clean = raw.slice(0, 12);
      const parts = [clean.slice(0, 4), clean.slice(4, 8), clean.slice(8, 12)].filter(Boolean);
      formatted = parts.join('-');
    }
    this.value = formatted;
    resetCodeState();
  });

  function resetCodeState() {
    document.getElementById('packageInfo').classList.add('d-none');
    document.getElementById('validatedCode').value = '';
    setHint('codeHint', '', null);
    codeData = {};
    if (getPaymentMethod() === 'code') {
      document.getElementById('toStep2Btn').disabled = true;
    }
  }

  document.getElementById('validateCodeBtn').addEventListener('click', async function() {
    const code = document.getElementById('reg_code').value.trim();
    const isCdCode = code.startsWith('CD-');
    const minLen = isCdCode ? 17 : 14;
    if (code.length < minLen) {
      setHint('codeHint', isCdCode ? 'Enter a complete CD code (CD-XXXX-XXXX-XXXX)' : 'Enter a complete code (XXXX-XXXX-XXXX)', false);
      return;
    }
    this.disabled = true;
    this.textContent = '…';
    try {
      const fd = new FormData();
      fd.append('code', code);
      fd.append('csrf_token', document.querySelector('[name=csrf_token]').value);
      const data = await (await fetch(API + '/?page=validate_code', {
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
        document.getElementById('toStep2Btn').disabled = false;
        setHint('codeHint', '✓ Code is valid!', true);
      } else {
        setHint('codeHint', data.message || 'Invalid code.', false);
      }
    } catch (e) {
      setHint('codeHint', 'Network error.', false);
    }
    this.disabled = false;
    this.textContent = 'Validate';
  });

  const toStep2Btn = document.getElementById('toStep2Btn');
  if (toStep2Btn) {
    toStep2Btn.addEventListener('click', () => {
      const method = getPaymentMethod();
      if (method === 'code' && !document.getElementById('validatedCode').value) return;
      if (method === 'ewallet' && PKG_COUNT > 1 && !document.getElementById('packageSelect')?.value) return;

      goStep(2);
      if (PREFILL_SPONSOR) {
        const sField = document.getElementById('sponsor_username');
        if (LOCKED_SPONSOR) {
          sponsorOk = true;
        } else if (sField.value) {
          checkSponsor(sField.value);
        }
      }
    });
  }

  // ── Username ──────────────────────────────────────────────────
  let uTimer;
  document.getElementById('username').addEventListener('input', function() {
    usernameOk = false;
    clearTimeout(uTimer);
    const v = this.value.trim();
    if (v.length < 3) {
      setHint('usernameHint', 'At least 3 characters required.', null);
      return;
    }
    setHint('usernameHint', 'Checking…', null);
    uTimer = setTimeout(async () => {
      const data = await (await fetch(API + '/?page=check_username&username=' + encodeURIComponent(v))).json();
      usernameOk = data.available;
      setHint('usernameHint', data.message, data.available);
    }, 600);
  });

  // ── Password confirm ──────────────────────────────────────────
  document.getElementById('password_confirm').addEventListener('input', function() {
    const ok = document.getElementById('password').value === this.value;
    setHint('pwMatchHint', this.value ? (ok ? '✓ Passwords match.' : '✗ Passwords do not match.') : '', this.value ? ok : null);
  });

  // ── Sponsor ───────────────────────────────────────────────────
  let sTimer;
  if (!LOCKED_SPONSOR) {
    document.getElementById('sponsor_username').addEventListener('input', function() {
      sponsorOk = false;
      clearTimeout(sTimer);
      const v = this.value.trim();
      if (!v) {
        setHint('sponsorHint', '', null);
        return;
      }
      setHint('sponsorHint', 'Checking…', null);
      sTimer = setTimeout(() => checkSponsor(v), 600);
    });
    if (PREFILL_SPONSOR) {
      setTimeout(() => {
        const el = document.getElementById('sponsor_username');
        if (el && el.value) checkSponsor(el.value);
      }, 800);
    }
  }

  async function checkSponsor(v) {
    const data = await (await fetch(API + '/?page=check_username&username=' + encodeURIComponent(v))).json();
    sponsorOk = !data.available;
    setHint('sponsorHint', sponsorOk ? '✓ Sponsor @' + v + ' found.' : '✗ Sponsor not found.', sponsorOk);
  }

  // ── Upline + slot ─────────────────────────────────────────────
  let upTimer;
  document.getElementById('upline_username').addEventListener('input', function() {
    uplineOk = false;
    slotData = {};
    clearTimeout(upTimer);
    const v = this.value.trim();
    if (!v) {
      setHint('uplineHint', '', null);
      document.getElementById('slotStatus').classList.add('d-none');
      resetPosBtns();
      return;
    }
    setHint('uplineHint', 'Checking…', null);
    upTimer = setTimeout(() => checkUpline(v), 600);
  });

  document.querySelectorAll('[name=binary_position]').forEach(r => {
    r.addEventListener('change', function() {
      checkPos(this.value);
    });
  });

  async function checkUpline(v) {
    const pos = IS_REFERRAL_MODE ? PREFILL_POSITION : (document.querySelector('[name=binary_position]:checked')?.value || '');
    const data = await (await fetch(API + '/?page=check_upline&username=' + encodeURIComponent(v) + '&position=' + pos)).json();
    if (!data.valid) {
      setHint('uplineHint', '✗ ' + data.message, false);
      document.getElementById('slotStatus').classList.add('d-none');
      uplineOk = false;
      return;
    }
    slotData = data;
    uplineOk = true;
    setHint('uplineHint', '✓ Found @' + data.username, true);

    const ls = document.getElementById('leftSlot');
    const rs = document.getElementById('rightSlot');
    ls.textContent = '↙ Left: ' + (data.left_free ? '✓ Free' : '✗ Taken');
    rs.textContent = '↘ Right: ' + (data.right_free ? '✓ Free' : '✗ Taken');
    ls.className = data.left_free ? 'slot-free' : 'slot-taken';
    rs.className = data.right_free ? 'slot-free' : 'slot-taken';
    document.getElementById('slotStatus').classList.remove('d-none');

    document.getElementById('pos_left').disabled = !data.left_free;
    document.getElementById('pos_right').disabled = !data.right_free;

    const cur = document.querySelector('[name=binary_position]:checked')?.value;
    if (cur === 'left' && !data.left_free && data.right_free) document.getElementById('pos_right').checked = true;
    if (cur === 'right' && !data.right_free && data.left_free) document.getElementById('pos_left').checked = true;
    checkPos(document.querySelector('[name=binary_position]:checked')?.value || '');
  }

  function checkPos(pos) {
    if (IS_REFERRAL_MODE && !slotData.username) {
      setHint('positionHint', '✓ Position pre-selected by system.', true);
      return;
    }
    if (!slotData.username) {
      setHint('positionHint', '', null);
      return;
    }
    if (!pos) {
      setHint('positionHint', 'Please select a position.', null);
      return;
    }
    const free = pos === 'left' ? slotData.left_free : slotData.right_free;
    setHint('positionHint',
      (free ? '✓ ' : '✗ ') + pos.charAt(0).toUpperCase() + pos.slice(1) + ' slot is ' + (free ? 'available.' : 'taken.'),
      free);
  }

  function resetPosBtns() {
    document.getElementById('pos_left').disabled = false;
    document.getElementById('pos_right').disabled = false;
    setHint('positionHint', '', null);
  }

  // ── Step 2 → 3 ───────────────────────────────────────────────
  document.getElementById('toStep3Btn').addEventListener('click', function() {
    const pw = document.getElementById('password').value;
    const pwc = document.getElementById('password_confirm').value;
    const pos = document.querySelector('[name=binary_position]:checked')?.value;
    const sponsorVal = document.getElementById('sponsor_username').value.trim();

    if (!usernameOk) {
      setHint('usernameHint', 'Please choose a valid, available username.', false);
      return;
    }
    if (pw.length < 8) {
      alert('Password must be at least 8 characters.');
      return;
    }
    if (pw !== pwc) {
      setHint('pwMatchHint', 'Passwords do not match.', false);
      return;
    }
    if (!LOCKED_SPONSOR && !sponsorOk) {
      setHint('sponsorHint', 'Please enter a valid sponsor.', false);
      return;
    }
    if (LOCKED_SPONSOR) sponsorOk = true;
    if (!uplineOk) {
      setHint('uplineHint', 'Please enter a valid upline.', false);
      return;
    }
    if (!pos) {
      setHint('positionHint', 'Please select a position.', false);
      return;
    }
    const free = pos === 'left' ? slotData.left_free : slotData.right_free;
    if (!free) {
      setHint('positionHint', 'Selected position is taken. Choose another.', false);
      return;
    }

    // Populate review
    if (!IS_REFERRAL_MODE) {
      const method = getPaymentMethod();
      const revPay = document.getElementById('rev_payment');
      if (revPay) revPay.textContent = method === 'code' ? '🎫 Registration Code' : '💳 E-Wallet';
      const revCodeRow = document.getElementById('revCodeRow');
      if (revCodeRow) revCodeRow.style.display = method === 'code' ? 'table-row' : 'none';
      const revCode = document.getElementById('rev_code');
      if (revCode) revCode.textContent = document.getElementById('validatedCode').value || '—';
      const revPkg = document.getElementById('rev_package');
      if (revPkg) revPkg.textContent = method === 'code' ?
        (codeData.package_name || '—') :
        (selectedPkg.name || (PKG_COUNT === 1 ? document.querySelector('#packageSection .fw-bold')?.textContent : '—'));
    }
    const revUser = document.getElementById('rev_username');
    if (revUser) revUser.textContent = '@' + document.getElementById('username').value;
    const revSponsor = document.getElementById('rev_sponsor');
    if (revSponsor) revSponsor.textContent = '@' + sponsorVal;
    const revUpline = document.getElementById('rev_upline');
    if (revUpline) revUpline.textContent = '@' + document.getElementById('upline_username').value;
    const revPos = document.getElementById('rev_position');
    if (revPos) revPos.textContent = pos.charAt(0).toUpperCase() + pos.slice(1);
    goStep(3);
  });

  // ── Submit ────────────────────────────────────────────────────
  document.getElementById('regForm').addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating account…';
  });

  // ── Init ──────────────────────────────────────────────────────
  if (LOCKED_SPONSOR) {
    sponsorOk = true;
  }
  if (IS_REFERRAL_MODE) {
    // Pre-validate sponsor and upline for referral mode
    if (PREFILL_SPONSOR) {
      checkSponsor(PREFILL_SPONSOR);
    }
    if (PREFILL_UPLINE) {
      checkUpline(PREFILL_UPLINE);
    }
    // position is pre-selected; mark ok
    uplineOk = !!PREFILL_UPLINE;

    // Disable required on hidden step-1 fields so HTML5 validation doesn't block submission
    const regCode = document.getElementById('reg_code');
    if (regCode) regCode.required = false;
    const pkgSel = document.getElementById('packageSelect');
    if (pkgSel) pkgSel.required = false;
  }
  updateStep1State();
</script>
<?php if (!$isLoggedIn): ?>
  </body>

  </html>
<?php else: ?>
  <?php require 'views/partials/footer.php'; ?>
<?php endif; ?>