<?php
$pageTitle = 'Upgrade Package';
require 'views/partials/head.php';
require 'views/partials/sidebar_member.php';

$currentPlans = $currentPlans ?? Package::plans((int)($current['id'] ?? 0));
$curPairing = (bool)($currentPlans['binary'] ?? false);
$selectedTarget = $selectedTarget ?? ($targets[0] ?? null);
$selectedPackageId = (int)($selectedPackageId ?? ($selectedTarget['id'] ?? 0));
$selectedPaymentMethod = ($selectedPaymentMethod ?? '') === 'ewallet' ? 'ewallet' : 'code';
$selectedUpline = $selectedUpline ?? null;
$selectedUplinePosition = in_array($selectedUplinePosition ?? '', ['left', 'right'], true) ? $selectedUplinePosition : '';
$binaryPlacementPreserved = (bool)($binaryPlacementPreserved ?? false);
$balance = (float)($balance ?? 0);
$packageData = array_map(fn($pkg) => [
    'id' => (int)$pkg['id'],
    'name' => (string)$pkg['name'],
    'entry' => (float)$pkg['entry_fee'],
    'diff' => (float)$pkg['diff'],
    'pairing' => (bool)($pkg['plans']['binary'] ?? false),
], $targets);
?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content upgrade-page">
    <?= render_flash() ?>

    <header class="upgrade-page-header">
      <div>
        <div class="upgrade-eyebrow">Membership</div>
        <h1>Upgrade package</h1>
        <p>Move to a higher package and pay only the difference in entry fee.</p>
      </div>
      <a href="<?= link_to('dashboard') ?>" class="btn btn-light upgrade-back-button">
        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
        Back to dashboard
      </a>
    </header>

    <section class="card upgrade-current-card mb-3" aria-labelledby="currentPackageTitle">
      <div class="card-body">
        <div class="upgrade-current-icon" aria-hidden="true">
          <i class="fa-solid fa-layer-group"></i>
        </div>
        <div class="upgrade-current-copy">
          <div class="upgrade-section-label">Current package</div>
          <h2 id="currentPackageTitle"><?= e($current['name'] ?? '—') ?></h2>
          <div class="upgrade-current-meta">
            <span>Entry fee <strong><?= fmt_money($curFee ?? 0) ?></strong></span>
            <span class="upgrade-dot" aria-hidden="true"></span>
            <span><?= $curPairing ? 'Binary network' : 'Non-binary package' ?></span>
          </div>
        </div>
        <span class="badge <?= $curPairing ? 'upgrade-badge-success' : 'upgrade-badge-neutral' ?>">
          <i class="fa-solid <?= $curPairing ? 'fa-sitemap' : 'fa-user-group' ?>" aria-hidden="true"></i>
          <?= $curPairing ? 'Binary enabled' : 'Non-binary' ?>
        </span>
      </div>
    </section>

    <?php if (empty($targets)): ?>
      <section class="card upgrade-empty-state">
        <div class="card-body">
          <div class="upgrade-empty-icon" aria-hidden="true">
            <i class="fa-solid fa-check"></i>
          </div>
          <h2>No compatible upgrades available</h2>
          <p>You are currently on the highest compatible package, or no higher package is available right now.</p>
          <a href="<?= link_to('dashboard') ?>" class="btn btn-primary">
            <i class="fa-solid fa-house" aria-hidden="true"></i>
            Return to dashboard
          </a>
        </div>
      </section>
    <?php else: ?>
      <div class="row g-3 g-xxl-4 align-items-start">
        <div class="col-12 col-xxl-7">
          <section class="card upgrade-panel" aria-labelledby="packageSelectionTitle">
            <div class="card-header upgrade-card-header">
              <div>
                <h2 class="card-title" id="packageSelectionTitle">Choose an upgrade</h2>
                <p>Select one package to continue.</p>
              </div>
              <span class="upgrade-count-badge"><?= count($targets) ?> available</span>
            </div>
            <div class="card-body">
              <div class="upgrade-info-banner">
                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                <span>Only packages that preserve your current Binary, Indirect Referral, and DFI benefits are shown.</span>
              </div>

              <fieldset class="upgrade-fieldset">
                <legend class="visually-hidden">Available upgrade packages</legend>
                <div class="upgrade-option-list">
                  <?php foreach ($targets as $pkg):
                    $pkgPlans = $pkg['plans'] ?? Package::plans((int)$pkg['id']);
                    $isSelected = (int)$pkg['id'] === $selectedPackageId;
                  ?>
                    <label class="upgrade-option<?= $isSelected ? ' is-selected' : '' ?>"
                      data-id="<?= (int)$pkg['id'] ?>"
                      data-name="<?= e($pkg['name']) ?>"
                      data-entry="<?= (float)$pkg['entry_fee'] ?>"
                      data-diff="<?= (float)$pkg['diff'] ?>"
                      data-pairing="<?= !empty($pkgPlans['binary']) ? '1' : '0' ?>">
                      <input class="upgrade-choice-input upgrade-package-radio" type="radio"
                        name="upgrade_pkg" value="<?= (int)$pkg['id'] ?>"
                        id="upg<?= (int)$pkg['id'] ?>" <?= $isSelected ? 'checked' : '' ?>>
                      <span class="upgrade-radio-mark" aria-hidden="true"></span>
                      <span class="upgrade-option-body">
                        <span class="upgrade-option-heading">
                          <span class="upgrade-package-name"><?= e($pkg['name']) ?></span>
                          <span class="upgrade-entry-fee">Entry <?= fmt_money((float)$pkg['entry_fee']) ?></span>
                        </span>
                        <span class="upgrade-plan-badges">
                          <span class="badge <?= !empty($pkgPlans['binary']) ? 'upgrade-badge-success' : 'upgrade-badge-neutral' ?>">
                            <?= !empty($pkgPlans['binary']) ? 'Binary' : 'Non-binary' ?>
                          </span>
                          <?php if (!empty($pkgPlans['indirect'])): ?>
                            <span class="badge upgrade-badge-info">Indirect referral</span>
                          <?php endif; ?>
                          <?php if (!empty($pkgPlans['dfi'])): ?>
                            <span class="badge upgrade-badge-warning">DFI enabled</span>
                          <?php endif; ?>
                        </span>
                        <span class="upgrade-benefits">
                          <?php if (!empty($pkgPlans['binary'])): ?>
                            <span><i class="fa-solid fa-link" aria-hidden="true"></i> Pair bonus <strong><?= fmt_money((float)$pkg['pairing_bonus']) ?></strong></span>
                            <span><i class="fa-solid fa-chart-simple" aria-hidden="true"></i> Daily pair cap <strong><?= (int)$pkg['daily_pair_cap'] ?></strong></span>
                          <?php endif; ?>
                          <span><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Direct bonus <strong><?= fmt_money((float)$pkg['direct_ref_bonus']) ?></strong></span>
                          <?php if (!empty($pkgPlans['dfi'])): ?>
                            <span><i class="fa-solid fa-calendar-day" aria-hidden="true"></i> DFI <strong><?= fmt_money((float)$pkg['daily_fixed_income']) ?>/day</strong></span>
                          <?php endif; ?>
                        </span>
                      </span>
                      <span class="upgrade-option-price">
                        <span>Upgrade fee</span>
                        <strong><?= fmt_money((float)$pkg['diff']) ?></strong>
                        <small>Difference only</small>
                      </span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </fieldset>
            </div>
          </section>

          <section id="binaryPlacementCard" class="card upgrade-panel mt-3 d-none" aria-labelledby="binaryPlacementTitle" aria-hidden="true">
            <div class="card-header upgrade-card-header">
              <div>
                <h2 class="card-title" id="binaryPlacementTitle">Binary placement</h2>
                <p>Choose an available position in the network.</p>
              </div>
              <span class="upgrade-step-badge">Required</span>
            </div>
            <div class="card-body">
              <div class="upgrade-info-banner upgrade-info-banner-primary">
                <i class="fa-solid fa-sitemap" aria-hidden="true"></i>
                <span>This package joins the binary network. Select an active upline with an open position.</span>
              </div>

              <div class="upgrade-form-section">
                <label class="form-label" for="uplineSearch">Binary upline</label>
                <div class="upgrade-search-wrap">
                  <div class="upgrade-search-control">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <input type="text" id="uplineSearch" class="form-control"
                      placeholder="Type a member username" autocomplete="off"
                      autocapitalize="characters" spellcheck="false"
                      role="combobox" aria-autocomplete="list" aria-expanded="false"
                      aria-controls="uplineResults" aria-describedby="uplineStatus">
                    <button type="button" class="upgrade-search-clear" id="clearUplineSearch"
                      aria-label="Clear upline search" hidden>
                      <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                  </div>
                  <div id="uplineResults" class="upgrade-upline-results" role="listbox" hidden></div>
                </div>
                <div class="form-text upgrade-status" id="uplineStatus" role="status" aria-live="polite">Enter at least 2 characters to search.</div>
              </div>

              <div class="upgrade-selected-upline" id="selectedUplineBox">
                <div class="upgrade-selected-upline-icon" aria-hidden="true">
                  <i class="fa-solid fa-user-group"></i>
                </div>
                <div class="upgrade-selected-upline-copy">
                  <strong id="selectedUplineName">No upline selected</strong>
                  <span id="selectedUplineMeta">Search and select an eligible member.</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="clearUplineBtn" hidden>Change</button>
              </div>

              <fieldset class="upgrade-form-section mb-0">
                <legend class="form-label">Binary position</legend>
                <div class="upgrade-position-grid">
                  <label class="upgrade-position-option" for="upg_pos_left">
                    <input class="upgrade-choice-input upgrade-position-input" type="radio"
                      id="upg_pos_left" name="upg_binary_position" value="left" disabled>
                    <span class="upgrade-radio-mark" aria-hidden="true"></span>
                    <span class="upgrade-position-copy">
                      <strong><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Left position</strong>
                      <small id="upgLeftAvailability">Select an upline first</small>
                    </span>
                  </label>
                  <label class="upgrade-position-option" for="upg_pos_right">
                    <input class="upgrade-choice-input upgrade-position-input" type="radio"
                      id="upg_pos_right" name="upg_binary_position" value="right" disabled>
                    <span class="upgrade-radio-mark" aria-hidden="true"></span>
                    <span class="upgrade-position-copy">
                      <strong><i class="fa-solid fa-arrow-right" aria-hidden="true"></i> Right position</strong>
                      <small id="upgRightAvailability">Select an upline first</small>
                    </span>
                  </label>
                </div>
                <div class="form-text upgrade-status" id="upgPosHint" role="status" aria-live="polite"></div>
              </fieldset>
            </div>
          </section>
        </div>

        <div class="col-12 col-xxl-5">
          <section class="card upgrade-panel" aria-labelledby="paymentTitle">
            <div class="card-header upgrade-card-header">
              <div>
                <h2 class="card-title" id="paymentTitle">Payment</h2>
                <p>Review and confirm your upgrade.</p>
              </div>
              <i class="fa-solid fa-credit-card upgrade-header-icon" aria-hidden="true"></i>
            </div>
            <div class="card-body">
              <form method="POST" action="<?= link_to('do_upgrade') ?>" id="upgradeForm">
                <?= csrf_field() ?>
                <input type="hidden" name="package_id" id="upgPackageId" value="<?= $selectedPackageId ?>">
                <input type="hidden" name="binary_upline_id" id="upgUplineId" value="<?= (int)($selectedUpline['id'] ?? 0) ?>">
                <input type="hidden" name="binary_position" id="upgPosition" value="<?= e($selectedUplinePosition) ?>">

                <div class="upgrade-selected-package">
                  <div class="upgrade-selected-package-top">
                    <span>Selected upgrade</span>
                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                  </div>
                  <strong id="upgSelectedPackageName"><?= e($selectedTarget['name'] ?? '') ?></strong>
                  <div class="upgrade-selected-package-meta">
                    <span>New entry <b><?= fmt_money((float)($selectedTarget['entry_fee'] ?? 0)) ?></b></span>
                    <span id="upgSelectedDifference">Fee <?= fmt_money((float)($selectedTarget['diff'] ?? 0)) ?></span>
                  </div>
                </div>

                <fieldset class="upgrade-form-section">
                  <legend class="form-label">Payment method</legend>
                  <div class="upgrade-payment-options">
                    <label class="upgrade-payment-option" id="walletPaymentOption" for="pay_ewlg">
                      <input class="upgrade-choice-input upgrade-payment-radio" type="radio"
                        name="payment_method" id="pay_ewlg" value="ewallet" required>
                      <span class="upgrade-radio-mark" aria-hidden="true"></span>
                      <span class="upgrade-payment-icon" aria-hidden="true"><i class="fa-solid fa-wallet"></i></span>
                      <span class="upgrade-payment-copy">
                        <strong>E-Wallet</strong>
                        <small>Available balance <b><?= fmt_money($balance) ?></b></small>
                        <span class="upgrade-payment-status" id="walletPaymentStatus" role="status" aria-live="polite"></span>
                      </span>
                    </label>
                    <label class="upgrade-payment-option" for="pay_upcode">
                      <input class="upgrade-choice-input upgrade-payment-radio" type="radio"
                        name="payment_method" id="pay_upcode" value="code" required>
                      <span class="upgrade-radio-mark" aria-hidden="true"></span>
                      <span class="upgrade-payment-icon" aria-hidden="true"><i class="fa-solid fa-ticket"></i></span>
                      <span class="upgrade-payment-copy">
                        <strong>Upgrade code</strong>
                        <small>Use a code issued for the selected package.</small>
                      </span>
                    </label>
                  </div>
                  <div class="upgrade-payment-notice" id="paymentNotice" role="status" aria-live="polite" hidden></div>
                </fieldset>

                <div class="upgrade-cost-summary" role="group" aria-label="Upgrade cost summary">
                  <div class="upgrade-cost-row">
                    <span>Current entry fee</span>
                    <strong><?= fmt_money($curFee ?? 0) ?></strong>
                  </div>
                  <div class="upgrade-cost-row">
                    <span>New entry fee</span>
                    <strong id="upgEntryFee"><?= fmt_money((float)($selectedTarget['entry_fee'] ?? 0)) ?></strong>
                  </div>
                  <div class="upgrade-cost-row upgrade-cost-total">
                    <span>You pay</span>
                    <strong id="upgFee"><?= fmt_money((float)($selectedTarget['diff'] ?? 0)) ?></strong>
                  </div>
                  <div class="upgrade-cost-row" id="walletRemainingRow">
                    <span>Wallet after upgrade</span>
                    <strong id="upgWalletRemaining">—</strong>
                  </div>
                </div>

                <div class="upgrade-form-section" id="codeInputSection" hidden>
                  <label class="form-label" for="upgrade_code">Upgrade code</label>
                  <div class="upgrade-code-control">
                    <input type="text" name="upgrade_code" id="upgrade_code" class="form-control font-mono"
                      placeholder="UP-XXXX-XXXX-XXXX" maxlength="17" autocomplete="off"
                      autocapitalize="characters" spellcheck="false" aria-describedby="codeHint">
                    <button type="button" class="btn btn-outline-primary" id="validateCodeBtn">
                      <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                      Validate
                    </button>
                  </div>
                  <div class="form-text upgrade-status" id="codeHint" role="status" aria-live="polite">Enter a code for the selected package.</div>
                </div>

                <button type="submit" class="btn btn-primary w-100 upgrade-submit" id="upgSubmitBtn" disabled>
                  <i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i>
                  Confirm upgrade
                </button>
                <div class="upgrade-block-hint" id="upgBlockHint" role="status" aria-live="polite"></div>
                <p class="upgrade-fine-print">
                  Only the difference in entry fees is charged. Package downgrades are not allowed.
                </p>
              </form>
            </div>
          </section>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($targets)): ?>
<script>
(() => {
  const form = document.getElementById('upgradeForm');
  if (!form) return;

  const packages = <?= json_encode($packageData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const packageById = new Map(packages.map(pkg => [Number(pkg.id), pkg]));
  const currentPackageName = <?= json_encode($current['name'] ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const initialPackageId = <?= $selectedPackageId ?>;
  const initialUpline = <?= json_encode($selectedUpline, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const initialUplinePosition = <?= json_encode($selectedUplinePosition, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const currentPairing = <?= $curPairing ? 'true' : 'false' ?>;
  const binaryPlacementPreserved = <?= $binaryPlacementPreserved ? 'true' : 'false' ?>;
  const walletBalance = <?= json_encode((float)$balance) ?>;
  const uplinesApi = <?= json_encode(link_to('api_binary_uplines'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const codeApi = <?= json_encode(link_to('api_validate_upgrade_code'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const csrfToken = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  const packageInputs = Array.from(document.querySelectorAll('.upgrade-package-radio'));
  const packageOptions = Array.from(document.querySelectorAll('.upgrade-option'));
  const packageIdInput = document.getElementById('upgPackageId');
  const selectedPackageName = document.getElementById('upgSelectedPackageName');
  const selectedDifference = document.getElementById('upgSelectedDifference');
  const entryFeeOutput = document.getElementById('upgEntryFee');
  const feeOutput = document.getElementById('upgFee');
  const walletRemainingOutput = document.getElementById('upgWalletRemaining');
  const walletRemainingRow = document.getElementById('walletRemainingRow');
  const walletInput = document.getElementById('pay_ewlg');
  const codePaymentInput = document.getElementById('pay_upcode');
  const walletPaymentOption = document.getElementById('walletPaymentOption');
  const walletPaymentStatus = document.getElementById('walletPaymentStatus');
  const paymentNotice = document.getElementById('paymentNotice');
  const codeSection = document.getElementById('codeInputSection');
  const codeInput = document.getElementById('upgrade_code');
  const validateCodeButton = document.getElementById('validateCodeBtn');
  const codeHint = document.getElementById('codeHint');
  const submitButton = document.getElementById('upgSubmitBtn');
  const blockHint = document.getElementById('upgBlockHint');
  const binaryCard = document.getElementById('binaryPlacementCard');
  const uplineSearch = document.getElementById('uplineSearch');
  const uplineResults = document.getElementById('uplineResults');
  const uplineStatus = document.getElementById('uplineStatus');
  const clearUplineSearchButton = document.getElementById('clearUplineSearch');
  const clearUplineButton = document.getElementById('clearUplineBtn');
  const selectedUplineName = document.getElementById('selectedUplineName');
  const selectedUplineMeta = document.getElementById('selectedUplineMeta');
  const uplineIdInput = document.getElementById('upgUplineId');
  const positionInput = document.getElementById('upgPosition');
  const positionInputs = Array.from(document.querySelectorAll('.upgrade-position-input'));
  const leftAvailability = document.getElementById('upgLeftAvailability');
  const rightAvailability = document.getElementById('upgRightAvailability');
  const positionHint = document.getElementById('upgPosHint');

  let currentPackage = packageById.get(initialPackageId) || null;
  let needsPlacement = false;
  let selectedUpline = initialUpline;
  let selectedUplinePosition = initialUplinePosition;
  let validatedCode = '';
  let validatedPackageId = 0;
  let codeValidateSequence = 0;
  let codeValidating = false;
  let walletAvailable = false;
  let searchTimer = null;
  let searchSequence = 0;
  let searchController = null;
  let resultItems = [];
  let activeResultIndex = -1;

  const fmtMoney = value => '₱' + Number(value || 0).toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });

  const escapeHtml = value => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const setHint = (element, message, state = '') => {
    element.textContent = message;
    element.classList.remove('is-success', 'is-error');
    if (state) element.classList.add(`is-${state}`);
  };

  const getPaymentMethod = () => codePaymentInput.checked ? 'code' : 'ewallet';

  const updatePaymentClasses = () => {
    walletPaymentOption.classList.toggle('is-selected', walletInput.checked);
    walletPaymentOption.classList.toggle('is-disabled', walletInput.disabled);
    document.getElementById('pay_upcode').closest('.upgrade-payment-option').classList.toggle('is-selected', codePaymentInput.checked);
  };

  const updateWalletAvailability = (showNotice = false) => {
    const fee = currentPackage ? Number(currentPackage.diff) : 0;
    walletAvailable = walletBalance + 0.005 >= fee;
    walletInput.disabled = !walletAvailable;
    walletPaymentOption.classList.toggle('is-disabled', !walletAvailable);

    if (walletAvailable) {
      walletPaymentStatus.textContent = `Covers this upgrade with ${fmtMoney(walletBalance - fee)} remaining.`;
      walletPaymentStatus.classList.remove('is-error');
      walletPaymentStatus.classList.add('is-success');
    } else {
      walletPaymentStatus.textContent = `Short by ${fmtMoney(Math.max(0, fee - walletBalance))}.`;
      walletPaymentStatus.classList.remove('is-success');
      walletPaymentStatus.classList.add('is-error');
    }

    if (walletInput.checked && !walletAvailable) {
      codePaymentInput.checked = true;
      paymentNotice.textContent = 'E-Wallet cannot cover this package. An upgrade code is selected instead.';
      paymentNotice.hidden = false;
    } else if (!showNotice) {
      paymentNotice.hidden = true;
    }

    walletRemainingRow.hidden = getPaymentMethod() !== 'ewallet';
    walletRemainingOutput.textContent = walletAvailable ? fmtMoney(Math.max(0, walletBalance - fee)) : 'Insufficient balance';
  };

  const currentCode = () => codeInput.value.trim().toUpperCase();

  const isCodeValidated = () => Boolean(currentPackage)
    && validatedCode !== ''
    && validatedCode === currentCode()
    && validatedPackageId === Number(currentPackage.id);

  const resetCodeValidation = (message = 'Enter a code for the selected package.', invalidatePending = true) => {
    validatedCode = '';
    validatedPackageId = 0;
    if (invalidatePending) {
      codeValidateSequence += 1;
      codeValidating = false;
      validateCodeButton.disabled = false;
      validateCodeButton.removeAttribute('aria-busy');
    }
    setHint(codeHint, message);
  };

  const submitBlockedReason = () => {
    if (!currentPackage) return 'No upgrade package is available.';
    if (getPaymentMethod() === 'ewallet' && !walletAvailable) return 'Your e-wallet balance cannot cover this upgrade.';
    if (getPaymentMethod() === 'code' && codeValidating) return 'Checking your upgrade code…';
    if (getPaymentMethod() === 'code' && !isCodeValidated()) {
      return validatedCode !== '' ? 'The upgrade code changed. Validate it again.' : 'Validate your upgrade code to continue.';
    }
    if (needsPlacement && !selectedUpline) return 'Select a binary upline to continue.';
    if (needsPlacement && !selectedUplinePosition) return 'Select an available binary position to continue.';
    return '';
  };

  const updateSubmitState = () => {
    let ready = Boolean(currentPackage);
    if (ready && getPaymentMethod() === 'code' && !isCodeValidated()) ready = false;
    if (ready && needsPlacement && (!selectedUpline || !selectedUplinePosition)) ready = false;
    if (ready && getPaymentMethod() === 'ewallet' && !walletAvailable) ready = false;

    submitButton.disabled = !ready;
    submitButton.setAttribute('aria-disabled', ready ? 'false' : 'true');
    submitButton.title = ready ? '' : submitBlockedReason();

    const reason = ready ? '' : submitBlockedReason();
    blockHint.textContent = reason;
    blockHint.hidden = reason === '';
  };

  const updatePaymentUI = () => {
    const method = getPaymentMethod();
    codeSection.hidden = method !== 'code';
    codeInput.required = method === 'code';
    walletRemainingRow.hidden = method !== 'ewallet';
    if (method === 'ewallet' && walletAvailable) {
      walletRemainingOutput.textContent = fmtMoney(Math.max(0, walletBalance - Number(currentPackage.diff)));
    } else {
      walletRemainingOutput.textContent = '—';
    }
    updatePaymentClasses();
    updateSubmitState();
  };

  const updateSelectedUpline = () => {
    if (!selectedUpline) {
      selectedUplineName.textContent = 'No upline selected';
      selectedUplineMeta.textContent = 'Search and select an eligible member.';
      clearUplineButton.hidden = true;
      return;
    }

    selectedUplineName.textContent = `@${selectedUpline.username}`;
    const slots = [
      selectedUpline.left_free ? 'Left available' : 'Left full',
      selectedUpline.right_free ? 'Right available' : 'Right full'
    ].join(' · ');
    selectedUplineMeta.textContent = `${selectedUpline.package} · ${slots}`;
    clearUplineButton.hidden = false;
  };

  const updatePositionAvailability = () => {
    const leftInput = document.getElementById('upg_pos_left');
    const rightInput = document.getElementById('upg_pos_right');
    leftInput.disabled = !selectedUpline || !selectedUpline.left_free;
    rightInput.disabled = !selectedUpline || !selectedUpline.right_free;
    leftAvailability.textContent = selectedUpline ? (selectedUpline.left_free ? 'Position is available' : 'Position is occupied') : 'Select an upline first';
    rightAvailability.textContent = selectedUpline ? (selectedUpline.right_free ? 'Position is available' : 'Position is occupied') : 'Select an upline first';
    document.getElementById('upg_pos_left').closest('.upgrade-position-option').classList.toggle('is-disabled', leftInput.disabled);
    document.getElementById('upg_pos_right').closest('.upgrade-position-option').classList.toggle('is-disabled', rightInput.disabled);
    positionInputs.forEach(input => {
      input.closest('.upgrade-position-option').classList.toggle('is-selected', input.checked);
    });
  };

  const clearUplineSelection = () => {
    selectedUpline = null;
    selectedUplinePosition = '';
    uplineIdInput.value = '';
    positionInput.value = '';
    positionInputs.forEach(input => { input.checked = false; });
    updateSelectedUpline();
    updatePositionAvailability();
    setHint(positionHint, '');
  };

  const applyUplineSelection = (upline, position = '') => {
    selectedUpline = upline;
    selectedUplinePosition = '';
    uplineIdInput.value = upline ? Number(upline.id) : '';
    positionInputs.forEach(input => { input.checked = false; });
    updateSelectedUpline();
    updatePositionAvailability();

    if (upline && ['left', 'right'].includes(position)) {
      const input = document.getElementById(`upg_pos_${position}`);
      if (input && !input.disabled) {
        input.checked = true;
        selectedUplinePosition = position;
        positionInput.value = position;
      }
    }
    setHint(positionHint, selectedUplinePosition ? 'Position selected.' : 'Select Left or Right.');
  };

  const closeUplineResults = () => {
    clearTimeout(searchTimer);
    if (searchController) {
      searchController.abort();
      searchController = null;
    }
    searchSequence += 1;
    uplineResults.hidden = true;
    uplineSearch.setAttribute('aria-expanded', 'false');
    uplineSearch.removeAttribute('aria-activedescendant');
    resultItems = [];
    activeResultIndex = -1;
  };

  const selectPackage = packageId => {
    const selected = packageById.get(Number(packageId));
    if (!selected) return;

    currentPackage = selected;
    packageIdInput.value = String(selected.id);
    packageInputs.forEach(input => { input.checked = Number(input.value) === Number(selected.id); });
    packageOptions.forEach(option => option.classList.toggle('is-selected', Number(option.dataset.id) === Number(selected.id)));

    selectedPackageName.textContent = selected.name;
    selectedDifference.textContent = `Fee ${fmtMoney(selected.diff)}`;
    entryFeeOutput.textContent = fmtMoney(selected.entry);
    feeOutput.textContent = fmtMoney(selected.diff);

    needsPlacement = !currentPairing && Boolean(selected.pairing) && !binaryPlacementPreserved;
    binaryCard.classList.toggle('d-none', !needsPlacement);
    binaryCard.setAttribute('aria-hidden', needsPlacement ? 'false' : 'true');
    uplineSearch.disabled = !needsPlacement;

    if (!needsPlacement) {
      clearUplineSelection();
      closeUplineResults();
      uplineSearch.value = '';
      clearUplineSearchButton.hidden = true;
    }

    resetCodeValidation();
    updateWalletAvailability();
    updatePaymentUI();
  };

  packageInputs.forEach(input => {
    input.addEventListener('change', () => {
      if (input.checked) selectPackage(input.value);
    });
  });

  walletInput.addEventListener('change', () => {
    paymentNotice.hidden = true;
    updatePaymentUI();
  });

  codePaymentInput.addEventListener('change', () => {
    paymentNotice.hidden = true;
    updatePaymentUI();
  });

  codeInput.addEventListener('input', () => {
    const clean = codeInput.value.replace(/[^A-Z0-9]/gi, '').toUpperCase().slice(0, 14);
    const parts = [
      clean.slice(0, 2),
      clean.slice(2, 6),
      clean.slice(6, 10),
      clean.slice(10, 14)
    ].filter(Boolean);
    codeInput.value = parts.join('-');
    resetCodeValidation();
  });

  const validateCode = async () => {
    if (!currentPackage) return;
    const code = currentCode();
    if (code.length !== 17) {
      resetCodeValidation('Enter the complete code, including UP- and all hyphens.');
      codeInput.focus();
      return;
    }

    const sequence = ++codeValidateSequence;
    const packageId = Number(currentPackage.id);
    codeValidating = true;
    validateCodeButton.disabled = true;
    validateCodeButton.setAttribute('aria-busy', 'true');
    setHint(codeHint, 'Checking this upgrade code…');
    updateSubmitState();

    try {
      const body = new FormData();
      body.append('code', code);
      body.append('package_id', String(packageId));
      body.append('csrf_token', csrfToken);
      const response = await fetch(codeApi, {
        method: 'POST',
        body,
        headers: { 'Accept': 'application/json' }
      });
      const data = await response.json();
      if (sequence !== codeValidateSequence) return;
      if (!response.ok || !data.valid) {
        resetCodeValidation(data.message || 'This upgrade code is invalid or does not match the selected package.', false);
        return;
      }
      if (currentCode() !== code || Number(packageIdInput.value) !== packageId) {
        resetCodeValidation('The package or code changed. Validate the code again.', false);
        return;
      }
      validatedCode = code;
      validatedPackageId = packageId;
      setHint(codeHint, `Code verified for ${data.package_name}.`, 'success');
    } catch (error) {
      if (sequence !== codeValidateSequence) return;
      resetCodeValidation('Unable to validate the code right now. Check your connection and try again.', false);
    } finally {
      if (sequence === codeValidateSequence) {
        codeValidating = false;
        validateCodeButton.disabled = false;
        validateCodeButton.removeAttribute('aria-busy');
        updateSubmitState();
      }
    }
  };

  validateCodeButton.addEventListener('click', validateCode);
  codeInput.addEventListener('blur', () => {
    if (currentCode().length === 17 && !isCodeValidated()) validateCode();
  });

  const setActiveResult = index => {
    if (!resultItems.length) return;
    activeResultIndex = (index + resultItems.length) % resultItems.length;
    resultItems.forEach((item, itemIndex) => {
      const active = itemIndex === activeResultIndex;
      item.classList.toggle('is-active', active);
      item.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    const active = resultItems[activeResultIndex];
    uplineSearch.setAttribute('aria-activedescendant', active.id);
    active.scrollIntoView({ block: 'nearest' });
  };

  const renderUplineResults = candidates => {
    uplineResults.replaceChildren();
    resultItems = [];
    activeResultIndex = -1;

    if (!candidates.length) {
      closeUplineResults();
      return;
    }

    candidates.forEach(candidate => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'upgrade-upline-result';
      button.id = `uplineOption-${candidate.id}`;
      button.setAttribute('role', 'option');
      button.setAttribute('aria-selected', 'false');
      button.tabIndex = -1;

      const name = document.createElement('strong');
      name.textContent = `@${candidate.username}`;
      const meta = document.createElement('span');
      const slots = [
        candidate.left_free ? 'Left available' : 'Left full',
        candidate.right_free ? 'Right available' : 'Right full'
      ].join(' · ');
      meta.textContent = `${candidate.package} · ${slots}`;
      button.append(name, meta);
      button.addEventListener('mousedown', event => event.preventDefault());
      button.addEventListener('click', () => {
        applyUplineSelection(candidate);
        uplineSearch.value = '';
        clearUplineSearchButton.hidden = true;
        closeUplineResults();
        setHint(uplineStatus, `Selected @${candidate.username}. Choose an available position.`, 'success');
        updateSubmitState();
      });
      uplineResults.appendChild(button);
      resultItems.push(button);
    });

    uplineResults.hidden = false;
    uplineSearch.setAttribute('aria-expanded', 'true');
  };

  const searchUplines = async query => {
    const sequence = ++searchSequence;
    if (searchController) searchController.abort();
    searchController = new AbortController();
    clearUplineSearchButton.hidden = false;
    setHint(uplineStatus, 'Searching for eligible uplines…');

    try {
      const separator = uplinesApi.includes('?') ? '&' : '?';
      const response = await fetch(`${uplinesApi}${separator}q=${encodeURIComponent(query)}`, {
        signal: searchController.signal,
        headers: { 'Accept': 'application/json' }
      });
      if (!response.ok) throw new Error('Search failed');
      const data = await response.json();
      if (sequence !== searchSequence) return;
      const candidates = Array.isArray(data.candidates) ? data.candidates : [];
      renderUplineResults(candidates);
      setHint(uplineStatus, candidates.length
        ? `${candidates.length} eligible upline${candidates.length === 1 ? '' : 's'} found.`
        : 'No eligible uplines found.', candidates.length ? 'success' : '');
    } catch (error) {
      if (error.name === 'AbortError') return;
      setHint(uplineStatus, 'Unable to search uplines right now. Please try again.', 'error');
      closeUplineResults();
    }
  };

  uplineSearch.addEventListener('input', () => {
    clearTimeout(searchTimer);
    const query = uplineSearch.value.trim();
    clearUplineSearchButton.hidden = query.length === 0;
    searchSequence += 1;
    if (searchController) searchController.abort();

    if (selectedUpline && query) clearUplineSelection();
    if (query.length < 2) {
      closeUplineResults();
      setHint(uplineStatus, 'Enter at least 2 characters to search.');
      return;
    }
    searchTimer = setTimeout(() => searchUplines(query), 400);
  });

  uplineSearch.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      event.preventDefault();
      closeUplineResults();
      return;
    }
    if (uplineResults.hidden) return;
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActiveResult(activeResultIndex + 1);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActiveResult(activeResultIndex - 1);
    } else if (event.key === 'Enter' && activeResultIndex >= 0) {
      event.preventDefault();
      resultItems[activeResultIndex]?.click();
    }
  });

  clearUplineSearchButton.addEventListener('click', () => {
    uplineSearch.value = '';
    clearUplineSearchButton.hidden = true;
    closeUplineResults();
    setHint(uplineStatus, 'Enter at least 2 characters to search.');
    uplineSearch.focus();
  });

  clearUplineButton.addEventListener('click', () => {
    clearUplineSelection();
    closeUplineResults();
    setHint(uplineStatus, 'Search and select another upline.');
    uplineSearch.focus();
  });

  positionInputs.forEach(input => {
    input.addEventListener('change', () => {
      selectedUplinePosition = input.value;
      positionInput.value = input.value;
      updatePositionAvailability();
      setHint(positionHint, 'Position selected.', 'success');
      updateSubmitState();
    });
  });

  document.addEventListener('click', event => {
    if (!event.target.closest('.upgrade-search-wrap')) closeUplineResults();
  });

  form.addEventListener('submit', event => {
    event.preventDefault();
    if (submitButton.disabled || !currentPackage || document.querySelector('.modal.show')) return;

    if (getPaymentMethod() === 'code') {
      if (codeValidating) {
        setHint(codeHint, 'Still checking your upgrade code. Please wait a moment.', 'error');
        return;
      }
      if (!isCodeValidated()) {
        resetCodeValidation('Validate your upgrade code before continuing.');
        codeInput.focus();
        return;
      }
    }

    const method = getPaymentMethod() === 'code' ? 'Upgrade code' : 'E-Wallet';
    let placement = 'Not required for this package';
    if (binaryPlacementPreserved && !currentPairing && currentPackage.pairing) {
      placement = 'Existing binary position retained';
    } else if (needsPlacement && selectedUpline) {
      placement = `@${selectedUpline.username} · ${selectedUplinePosition === 'left' ? 'Left' : 'Right'} position`;
    }
    const message = `
      <div class="upgrade-confirm-summary">
        <div><span>Current package</span><strong>${escapeHtml(currentPackageName)}</strong></div>
        <div><span>New package</span><strong>${escapeHtml(currentPackage.name)}</strong></div>
        <div><span>Amount</span><strong>${fmtMoney(currentPackage.diff)}</strong></div>
        <div><span>Payment</span><strong>${method}</strong></div>
        <div><span>Placement</span><strong>${escapeHtml(placement)}</strong></div>
      </div>`;

    if (typeof showConfirm !== 'function') {
      submitButton.disabled = true;
      submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Processing…';
      form.submit();
      return;
    }

    showConfirm({
      title: 'Confirm package upgrade',
      message,
      confirmText: 'Upgrade now',
      confirmClass: 'btn-primary',
      onConfirm: () => {
        submitButton.disabled = true;
        submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Processing…';
        form.submit();
      }
    });
  });

  selectPackage(initialPackageId);
  if (initialUpline) {
    applyUplineSelection(initialUpline, initialUplinePosition);
  } else {
    clearUplineSelection();
  }
  <?php if ($selectedPaymentMethod === 'ewallet'): ?>
    walletInput.checked = true;
  <?php else: ?>
    codePaymentInput.checked = true;
  <?php endif; ?>
  updateWalletAvailability();
  updatePaymentUI();
  updateSubmitState();
})();
</script>
<?php endif; ?>

<style>
.upgrade-page {
  max-width: 1480px;
  margin: 0 auto;
}
.upgrade-page-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}
.upgrade-page-header h1 {
  margin: 0.15rem 0 0.35rem;
  font-size: clamp(1.45rem, 3vw, 2rem);
  font-weight: 800;
  letter-spacing: -0.035em;
}
.upgrade-page-header p {
  margin: 0;
  color: var(--muted);
  font-size: 0.9rem;
}
.upgrade-eyebrow,
.upgrade-section-label {
  color: var(--primary);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
.upgrade-back-button {
  flex-shrink: 0;
  border: 1px solid var(--bs-border-color);
  color: #475569;
}
.upgrade-back-button:hover {
  color: var(--primary);
  border-color: rgba(59, 111, 240, 0.35);
  background: var(--primary-light);
}
.upgrade-current-card {
  border-color: rgba(59, 111, 240, 0.18);
  background: linear-gradient(135deg, #fff 0%, #f7faff 100%);
}
.upgrade-current-card .card-body {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 1rem;
  padding: 1.15rem 1.25rem;
}
.upgrade-current-icon {
  width: 52px;
  height: 52px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.9rem;
  color: #fff;
  background: linear-gradient(135deg, var(--primary), #6b93f7);
  box-shadow: 0 8px 18px rgba(59, 111, 240, 0.2);
  font-size: 1.2rem;
}
.upgrade-current-copy {
  min-width: 0;
}
.upgrade-current-copy h2 {
  margin: 0.15rem 0 0.2rem;
  font-size: 1.15rem;
  font-weight: 800;
  overflow-wrap: anywhere;
}
.upgrade-current-meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.45rem;
  color: var(--muted);
  font-size: 0.8rem;
}
.upgrade-current-meta strong {
  color: #334155;
  font-family: var(--font-mono);
}
.upgrade-dot {
  width: 3px;
  height: 3px;
  border-radius: 50%;
  background: #94a3b8;
}
.upgrade-badge-success,
.upgrade-badge-neutral,
.upgrade-badge-info,
.upgrade-badge-warning {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.4rem 0.6rem;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 700;
  white-space: nowrap;
}
.upgrade-badge-success {
  color: #087443;
  background: #eafaf2;
  border: 1px solid #bcebcf;
}
.upgrade-badge-neutral {
  color: #526175;
  background: #f1f5f9;
  border: 1px solid #dce3ec;
}
.upgrade-badge-info {
  color: #0e7490;
  background: #ecfeff;
  border: 1px solid #a5f3fc;
}
.upgrade-badge-warning {
  color: #b45309;
  background: #fffbeb;
  border: 1px solid #fde68a;
}
.upgrade-empty-state .card-body {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: clamp(2.5rem, 7vw, 5rem) 1.25rem;
  text-align: center;
}
.upgrade-empty-icon {
  width: 68px;
  height: 68px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 1rem;
  border-radius: 50%;
  color: var(--success);
  background: #eafaf2;
  font-size: 1.75rem;
}
.upgrade-empty-state h2 {
  margin-bottom: 0.5rem;
  font-size: 1.25rem;
  font-weight: 800;
}
.upgrade-empty-state p {
  max-width: 520px;
  margin-bottom: 1.25rem;
  color: var(--muted);
  font-size: 0.9rem;
}
.upgrade-panel {
  overflow: visible;
}
.upgrade-panel .card-body {
  padding: 1.15rem;
}
.upgrade-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  min-height: 68px;
  padding: 0.9rem 1.15rem;
}
.upgrade-card-header h2 {
  font-size: 0.95rem;
}
.upgrade-card-header p {
  margin: 0.2rem 0 0;
  color: var(--muted);
  font-size: 0.76rem;
}
.upgrade-count-badge,
.upgrade-step-badge {
  flex-shrink: 0;
  padding: 0.35rem 0.6rem;
  border-radius: 999px;
  color: var(--primary);
  background: var(--primary-light);
  font-size: 0.7rem;
  font-weight: 700;
}
.upgrade-step-badge {
  color: #b45309;
  background: #fffbeb;
}
.upgrade-header-icon {
  color: var(--primary);
  font-size: 1.1rem;
}
.upgrade-info-banner {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  margin-bottom: 1rem;
  padding: 0.75rem 0.85rem;
  border: 1px solid #dbe6f7;
  border-radius: 0.75rem;
  color: #526175;
  background: #f7faff;
  font-size: 0.78rem;
  line-height: 1.5;
}
.upgrade-info-banner i {
  margin-top: 0.1rem;
  color: var(--primary);
}
.upgrade-info-banner-primary {
  color: #31517c;
  background: #f3f7ff;
  border-color: #c9dafc;
}
.upgrade-fieldset {
  min-width: 0;
  margin: 0;
  padding: 0;
  border: 0;
}
.upgrade-option-list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.upgrade-option,
.upgrade-payment-option,
.upgrade-position-option {
  position: relative;
  cursor: pointer;
}
.upgrade-option {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: start;
  gap: 0.8rem;
  min-width: 0;
  padding: 1rem;
  border: 1.5px solid #dfe5ef;
  border-radius: 0.85rem;
  background: #fff;
  transition: border-color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
}
.upgrade-option:hover {
  border-color: #a9bce8;
  background: #fbfcff;
}
.upgrade-option.is-selected {
  border-color: var(--primary);
  background: #f7faff;
  box-shadow: 0 0 0 3px rgba(59, 111, 240, 0.09);
}
.upgrade-choice-input {
  position: absolute;
  width: 1px;
  height: 1px;
  margin: -1px;
  padding: 0;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
  border: 0;
  opacity: 0;
}
.upgrade-radio-mark {
  position: relative;
  width: 20px;
  height: 20px;
  flex: 0 0 20px;
  margin-top: 0.05rem;
  border: 2px solid #b7c2d3;
  border-radius: 50%;
  background: #fff;
  transition: border-color 0.15s ease, background 0.15s ease;
}
.upgrade-radio-mark::after {
  content: '';
  position: absolute;
  inset: 4px;
  border-radius: 50%;
  background: #fff;
  transform: scale(0);
  transition: transform 0.15s ease;
}
.upgrade-choice-input:checked + .upgrade-radio-mark {
  border-color: var(--primary);
  background: var(--primary);
}
.upgrade-choice-input:checked + .upgrade-radio-mark::after {
  transform: scale(1);
}
.upgrade-choice-input:focus-visible + .upgrade-radio-mark {
  outline: 3px solid rgba(59, 111, 240, 0.25);
  outline-offset: 2px;
}
.upgrade-option-body {
  min-width: 0;
}
.upgrade-option-heading {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 0.35rem 0.6rem;
}
.upgrade-package-name {
  min-width: 0;
  color: #172033;
  font-size: 0.98rem;
  font-weight: 800;
  line-height: 1.4;
  overflow-wrap: anywhere;
}
.upgrade-entry-fee {
  color: var(--muted);
  font-size: 0.72rem;
}
.upgrade-plan-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin-top: 0.55rem;
}
.upgrade-benefits {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.45rem 0.75rem;
  margin-top: 0.75rem;
  color: #64748b;
  font-size: 0.72rem;
}
.upgrade-benefits > span {
  min-width: 0;
  overflow-wrap: anywhere;
}
.upgrade-benefits i {
  width: 14px;
  margin-right: 0.2rem;
  color: #7b8ba5;
}
.upgrade-benefits strong {
  color: #334155;
}
.upgrade-option-price {
  min-width: 112px;
  padding-left: 0.8rem;
  border-left: 1px solid #e4e9f1;
  text-align: right;
}
.upgrade-option-price > span,
.upgrade-option-price small {
  display: block;
  color: var(--muted);
  font-size: 0.66rem;
}
.upgrade-option-price > span {
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}
.upgrade-option-price strong {
  display: block;
  margin: 0.2rem 0;
  color: var(--success);
  font-family: var(--font-mono);
  font-size: 1rem;
  overflow-wrap: anywhere;
}
.upgrade-form-section {
  min-width: 0;
  margin-bottom: 1rem;
}
.upgrade-form-section > .form-label {
  font-size: 0.84rem;
}
.upgrade-search-wrap {
  position: relative;
  min-width: 0;
}
.upgrade-search-control {
  position: relative;
  display: flex;
  align-items: center;
}
.upgrade-search-control > i {
  position: absolute;
  left: 0.9rem;
  z-index: 1;
  color: #8290a6;
  pointer-events: none;
}
.upgrade-search-control .form-control {
  min-height: 48px;
  padding-left: 2.6rem;
  padding-right: 3rem;
  font-size: 1rem;
}
.upgrade-search-clear {
  position: absolute;
  right: 0.3rem;
  width: 40px;
  height: 40px;
  border: 0;
  border-radius: 0.55rem;
  color: #64748b;
  background: transparent;
}
.upgrade-search-clear:hover {
  color: var(--danger);
  background: #fff1f2;
}
.upgrade-upline-results {
  position: absolute;
  z-index: 1020;
  top: calc(100% + 0.35rem);
  left: 0;
  width: 100%;
  max-height: 260px;
  overflow-y: auto;
  padding: 0.35rem;
  border: 1px solid #dce3ee;
  border-radius: 0.75rem;
  background: #fff;
  box-shadow: 0 14px 34px rgba(15, 23, 42, 0.15);
}
.upgrade-upline-result {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  padding: 0.7rem 0.75rem;
  border: 0;
  border-radius: 0.55rem;
  color: #334155;
  background: #fff;
  text-align: left;
}
.upgrade-upline-result:hover,
.upgrade-upline-result.is-active {
  color: #1e3a8a;
  background: #eff6ff;
}
.upgrade-upline-result strong {
  min-width: 0;
  font-size: 0.84rem;
  overflow-wrap: anywhere;
}
.upgrade-upline-result span,
.upgrade-upline-empty {
  min-width: 0;
  color: var(--muted);
  font-size: 0.72rem;
  overflow-wrap: anywhere;
}
.upgrade-upline-empty {
  padding: 0.8rem;
  text-align: center;
}
.upgrade-selected-upline {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 1rem;
  padding: 0.85rem;
  border: 1px solid #dbe3ef;
  border-radius: 0.75rem;
  background: #f8fafd;
}
.upgrade-selected-upline-icon {
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.65rem;
  color: var(--primary);
  background: #eaf1ff;
}
.upgrade-selected-upline-copy {
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.upgrade-selected-upline-copy strong {
  color: #26364d;
  font-size: 0.84rem;
  overflow-wrap: anywhere;
}
.upgrade-selected-upline-copy span {
  color: var(--muted);
  font-size: 0.7rem;
  overflow-wrap: anywhere;
}
.upgrade-position-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.65rem;
}
.upgrade-position-option {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  min-height: 64px;
  padding: 0.75rem;
  border: 1.5px solid #dfe5ef;
  border-radius: 0.75rem;
  background: #fff;
  transition: border-color 0.15s ease, background 0.15s ease;
}
.upgrade-position-option:hover:not(.is-disabled) {
  border-color: #a9bce8;
}
.upgrade-position-option.is-selected,
.upgrade-position-option:has(input:checked) {
  border-color: var(--primary);
  background: #f7faff;
}
.upgrade-position-option.is-disabled {
  cursor: not-allowed;
  opacity: 0.55;
  background: #f8fafc;
}
.upgrade-position-copy {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}
.upgrade-position-copy strong {
  color: #334155;
  font-size: 0.8rem;
}
.upgrade-position-copy small {
  color: var(--muted);
  font-size: 0.68rem;
}
.upgrade-selected-package {
  padding: 1rem;
  border: 1px solid rgba(59, 111, 240, 0.2);
  border-radius: 0.85rem;
  color: #fff;
  background: linear-gradient(135deg, #2f61d7, #4c82f4);
  box-shadow: 0 10px 24px rgba(59, 111, 240, 0.18);
}
.upgrade-selected-package-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.35rem;
  color: rgba(255, 255, 255, 0.75);
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}
.upgrade-selected-package > strong {
  display: block;
  font-size: 1.12rem;
  font-weight: 800;
  line-height: 1.35;
  overflow-wrap: anywhere;
}
.upgrade-selected-package-meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 0.35rem 0.75rem;
  margin-top: 0.5rem;
  color: rgba(255, 255, 255, 0.82);
  font-size: 0.72rem;
}
.upgrade-selected-package-meta b {
  color: #fff;
  font-family: var(--font-mono);
}
.upgrade-payment-options {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}
.upgrade-payment-option {
  display: grid;
  grid-template-columns: auto auto minmax(0, 1fr);
  align-items: center;
  gap: 0.7rem;
  min-height: 76px;
  padding: 0.8rem;
  border: 1.5px solid #dfe5ef;
  border-radius: 0.8rem;
  background: #fff;
  transition: border-color 0.15s ease, background 0.15s ease, opacity 0.15s ease;
}
.upgrade-payment-option:hover:not(.is-disabled) {
  border-color: #a9bce8;
}
.upgrade-payment-option.is-selected {
  border-color: var(--primary);
  background: #f7faff;
  box-shadow: 0 0 0 3px rgba(59, 111, 240, 0.08);
}
.upgrade-payment-option.is-disabled {
  cursor: not-allowed;
  opacity: 0.62;
  background: #f8fafc;
}
.upgrade-payment-icon {
  width: 38px;
  height: 38px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.65rem;
  color: var(--primary);
  background: #eaf1ff;
}
.upgrade-payment-copy {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.12rem;
}
.upgrade-payment-copy strong {
  color: #26364d;
  font-size: 0.86rem;
}
.upgrade-payment-copy small {
  color: var(--muted);
  font-size: 0.7rem;
  overflow-wrap: anywhere;
}
.upgrade-payment-copy small b {
  color: #475569;
  font-family: var(--font-mono);
}
.upgrade-payment-status {
  color: #64748b;
  font-size: 0.68rem;
  font-weight: 600;
}
.upgrade-payment-status.is-success {
  color: #087443;
}
.upgrade-payment-status.is-error {
  color: var(--danger);
}
.upgrade-payment-notice {
  margin-top: 0.65rem;
  padding: 0.65rem 0.75rem;
  border: 1px solid #fed7aa;
  border-radius: 0.65rem;
  color: #9a3412;
  background: #fff7ed;
  font-size: 0.72rem;
  line-height: 1.45;
}
.upgrade-cost-summary {
  margin: 1rem 0;
  padding: 0.2rem 0.9rem;
  border: 1px solid #e0e6ef;
  border-radius: 0.8rem;
  background: #fafbfd;
}
.upgrade-cost-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.75rem;
  min-height: 46px;
  padding: 0.55rem 0;
  border-bottom: 1px solid #e8edf4;
  color: #64748b;
  font-size: 0.77rem;
}
.upgrade-cost-row:last-child {
  border-bottom: 0;
}
.upgrade-cost-row strong {
  color: #334155;
  font-family: var(--font-mono);
  text-align: right;
  overflow-wrap: anywhere;
}
.upgrade-cost-total {
  color: #1e293b;
  font-weight: 800;
}
.upgrade-cost-total strong {
  color: var(--success);
  font-size: 1.05rem;
}
.upgrade-code-control {
  display: flex;
  gap: 0.5rem;
  min-width: 0;
}
.upgrade-code-control .form-control {
  min-width: 0;
  min-height: 48px;
  flex: 1 1 auto;
  font-size: 1rem;
}
.upgrade-code-control .btn {
  min-height: 48px;
  flex: 0 0 auto;
}
.upgrade-status {
  margin-top: 0.4rem;
  font-size: 0.74rem;
  line-height: 1.45;
}
.upgrade-status.is-success {
  color: #087443;
}
.upgrade-status.is-error {
  color: var(--danger);
}
.upgrade-submit {
  min-height: 52px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  font-size: 0.92rem;
}
.upgrade-block-hint {
  display: flex;
  align-items: flex-start;
  gap: 0.4rem;
  margin-top: 0.55rem;
  color: var(--muted);
  font-size: 0.74rem;
  line-height: 1.45;
  text-align: center;
  justify-content: center;
}
.upgrade-fine-print {
  margin: 0.7rem 0 0;
  color: var(--muted);
  font-size: 0.7rem;
  line-height: 1.5;
  text-align: center;
}
.upgrade-confirm-summary {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}
.upgrade-confirm-summary > div {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 1rem;
}
.upgrade-confirm-summary span {
  color: var(--muted);
}
.upgrade-confirm-summary strong {
  color: #1e293b;
  text-align: right;
  overflow-wrap: anywhere;
}
.upgrade-page [hidden] {
  display: none !important;
}
@media (max-width: 767.98px) {
  .upgrade-page-header {
    align-items: flex-start;
    flex-direction: column;
  }
  .upgrade-page-header p {
    font-size: 0.82rem;
  }
  .upgrade-back-button {
    width: 100%;
    min-height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
  }
  .upgrade-current-card .card-body {
    grid-template-columns: auto minmax(0, 1fr);
    padding: 1rem;
  }
  .upgrade-current-card .upgrade-badge-success,
  .upgrade-current-card .upgrade-badge-neutral {
    grid-column: 1 / -1;
    justify-self: start;
  }
  .upgrade-panel .card-body {
    padding: 1rem;
  }
  .upgrade-card-header {
    min-height: 64px;
    padding: 0.85rem 1rem;
  }
  .upgrade-option {
    grid-template-columns: auto minmax(0, 1fr);
    padding: 0.9rem;
  }
  .upgrade-option-price {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    min-width: 0;
    padding: 0.7rem 0 0;
    border-top: 1px solid #e4e9f1;
    border-left: 0;
    text-align: left;
  }
  .upgrade-option-price strong {
    margin: 0;
    font-size: 1.05rem;
  }
  .upgrade-option-price small {
    display: none;
  }
  .upgrade-selected-upline {
    grid-template-columns: auto minmax(0, 1fr);
  }
  .upgrade-selected-upline .btn {
    grid-column: 1 / -1;
    width: 100%;
    min-height: 44px;
  }
  .upgrade-position-grid {
    grid-template-columns: 1fr;
  }
  .upgrade-payment-option {
    min-height: 82px;
  }
  .upgrade-cost-row {
    grid-template-columns: minmax(0, 1fr);
    align-items: start;
    gap: 0.2rem;
    min-height: 0;
    padding: 0.7rem 0;
  }
  .upgrade-cost-row strong {
    max-width: 100%;
    text-align: left;
    font-size: 0.98rem;
  }
  .upgrade-cost-total strong {
    font-size: 1.15rem;
  }
  .upgrade-confirm-summary > div {
    grid-template-columns: 1fr;
    gap: 0.1rem;
  }
  .upgrade-confirm-summary strong {
    text-align: left;
  }
}
@media (max-width: 575.98px) {
  .upgrade-current-icon {
    width: 46px;
    height: 46px;
  }
  .upgrade-current-meta {
    align-items: flex-start;
    flex-direction: column;
    gap: 0.15rem;
  }
  .upgrade-dot {
    display: none;
  }
  .upgrade-card-header p {
    max-width: 220px;
  }
  .upgrade-benefits {
    grid-template-columns: 1fr;
  }
  .upgrade-code-control {
    display: grid;
    grid-template-columns: 1fr;
  }
  .upgrade-code-control .btn {
    width: 100%;
  }
  .upgrade-selected-package-meta {
    align-items: flex-start;
    flex-direction: column;
  }
}
@media (prefers-reduced-motion: reduce) {
  .upgrade-page *,
  .upgrade-page *::before,
  .upgrade-page *::after {
    scroll-behavior: auto !important;
    transition: none !important;
  }
}
</style>
<?php require 'views/partials/footer.php'; ?>
