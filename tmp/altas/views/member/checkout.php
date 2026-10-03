<?php $pageTitle = 'Checkout'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
      <div>
        <h4 class="mb-0">Checkout</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Review your order and complete your purchase</p>
      </div>
    </div>

    <?php if (!empty($stockErrors)): ?>
      <div class="alert alert-danger d-flex align-items-start gap-2">
        <span style="font-size:1.25rem;">⚠️</span>
        <div>
          <strong>Stock issues detected:</strong>
          <ul class="mb-0 mt-1">
            <?php foreach ($stockErrors as $e): ?>
            <li><?= e($e) ?></li>
            <?php endforeach; ?>
          </ul>
          <a href="?page=repeat_purchases" class="alert-link mt-2 d-inline-block">Back to catalog</a>
        </div>
      </div>
    <?php endif; ?>

    <form method="post" action="?page=place_order" enctype="multipart/form-data" id="checkoutForm">
      <?= csrf_field() ?>

      <div class="row g-3">
        <!-- Left column: Order details -->
        <div class="col-lg-8">
          <!-- Order Summary -->
          <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
              <span class="fw-bold">🛒 Order Summary</span>
              <span class="badge bg-light text-dark border" style="font-size:.75rem;"><?= (int)$totals['total_items'] ?> item(s)</span>
            </div>
            <div class="card-body p-0">
              <?php $itemCount = count($items); $itemIdx = 0; foreach ($items as $item):
                $subtotal = (float)$item['quantity'] * (float)$item['unit_price'];
                $imgUrl = !empty($item['image_url']) ? APP_URL . '/uploads/' . e($item['image_url']) : '';
                $isLastItem = (++$itemIdx === $itemCount);
              ?>
              <div class="d-flex gap-3 p-3 <?= !$isLastItem ? 'border-bottom' : '' ?>">
                <?php if ($imgUrl): ?>
                  <img src="<?= $imgUrl ?>" alt="" class="flex-shrink-0 rounded-2" style="width:56px;height:56px;object-fit:cover;border:1px solid #e8ecf5;">
                <?php else: ?>
                  <div class="flex-shrink-0 rounded-2 d-flex align-items-center justify-content-center" style="width:56px;height:56px;border:1px solid #e8ecf5;background:#f8fafc;">
                    <span style="font-size:1.25rem;">🛍️</span>
                  </div>
                <?php endif; ?>
                <div class="flex-grow-1 min-w-0">
                  <div class="d-flex justify-content-between align-items-start gap-2">
                    <h6 class="mb-0 text-truncate"><?= e($item['product_name']) ?></h6>
                    <span class="fw-bold font-mono text-nowrap"><?= fmt_money($subtotal) ?></span>
                  </div>
                  <div class="d-flex flex-wrap gap-3 mt-1 small text-muted">
                    <span>Qty: <strong class="text-dark"><?= (int)$item['quantity'] ?></strong></span>
                    <span>Unit: <strong class="text-dark"><?= fmt_money((float)$item['unit_price']) ?></strong></span>
                    <span>PV: <strong class="text-success"><?= number_format((float)$item['unit_pv'], 2) ?> PV</strong></span>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <div class="card-footer bg-light">
              <div class="d-flex justify-content-between align-items-center small text-muted">
                <span>Total PV</span>
                <span class="fw-bold text-success font-mono"><?= number_format((float)$totals['total_pv'], 2) ?> PV</span>
              </div>
              <div class="d-flex justify-content-between align-items-center mt-1">
                <span class="fw-bold">Total</span>
                <span class="fw-bold font-mono fs-5"><?= fmt_money((float)$totals['total_price']) ?></span>
              </div>
            </div>
          </div>

          <?php if (!empty($showBinaryPosition)): ?>
          <!-- Binary Position -->
          <div class="card mb-3">
            <div class="card-header fw-bold">🌳 Binary Position</div>
            <div class="card-body">
              <p class="small text-muted mb-3">Choose which leg receives the product PV to help balance your binary tree.</p>
              <div class="row g-2">
                <div class="col-6">
                  <label class="binary-option d-flex align-items-center gap-3 border rounded-3 p-3 selected" style="cursor:pointer;">
                    <input type="radio" name="binary_position" value="left" class="form-check-input mt-0" checked style="flex-shrink:0;">
                    <div>
                      <div class="fw-bold">Left Leg</div>
                      <div class="small text-muted">Assign PV to left side</div>
                    </div>
                  </label>
                </div>
                <div class="col-6">
                  <label class="binary-option d-flex align-items-center gap-3 border rounded-3 p-3" style="cursor:pointer;">
                    <input type="radio" name="binary_position" value="right" class="form-check-input mt-0" style="flex-shrink:0;">
                    <div>
                      <div class="fw-bold">Right Leg</div>
                      <div class="small text-muted">Assign PV to right side</div>
                    </div>
                  </label>
                </div>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <!-- Payment Method -->
          <div class="card mb-3">
            <div class="card-header fw-bold">💳 Payment Method</div>
            <div class="card-body">
              <div class="row g-2">
                <?php if ($canUseEwallet): ?>
                <div class="col-6 col-md-4">
                  <label class="payment-option d-block border rounded-3 p-3 text-center selected" style="cursor:pointer;">
                    <input type="radio" name="payment_method" value="ewallet" class="d-none" checked>
                    <div class="mb-2" style="font-size:1.5rem;">💰</div>
                    <div class="fw-bold" style="font-size:.85rem;">E-Wallet</div>
                    <div class="small text-muted"><?= fmt_money($ewalletBalance) ?></div>
                    <?php if ((float)$totals['total_price'] > $ewalletBalance): ?>
                    <div class="small text-danger mt-1">Insufficient</div>
                    <?php endif; ?>
                  </label>
                </div>
                <?php endif; ?>
                <?php foreach ($methods as $key => $label):
                  $emoji = match($key) {
                    'gcash' => '📱',
                    'maya' => '💳',
                    'usdt_trc20' => '₮',
                    'usdt_bep20' => '₮',
                    default => '💳'
                  };
                ?>
                <div class="col-6 col-md-4">
                  <label class="payment-option d-block border rounded-3 p-3 text-center" style="cursor:pointer;">
                    <input type="radio" name="payment_method" value="<?= $key ?>" class="d-none"
                      <?= (!$canUseEwallet && $key === array_key_first($methods)) ? 'checked' : '' ?>>
                    <div class="mb-2" style="font-size:1.5rem;"><?= $emoji ?></div>
                    <div class="fw-bold" style="font-size:.85rem;"><?= e($label) ?></div>
                    <div class="small text-muted">Upload proof</div>
                  </label>
                </div>
                <?php endforeach; ?>
              </div>

              <div id="proofUploadSection" class="mt-3" style="display:none;">
                <label class="form-label fw-medium">📎 Upload Proof of Payment</label>
                <div class="border border-dashed rounded-3 p-4 text-center" id="dropZone" style="border-style:dashed!important;border-color:#c8d1e0!important;background:#fafbfc;">
                  <input type="file" name="proof_image" id="proofFile" class="form-control d-none" accept="image/jpeg,image/png,image/gif,image/webp">
                  <div style="font-size:2rem;opacity:.5;">📤</div>
                  <p class="mb-1 small text-muted">Click to upload or drag & drop</p>
                  <p class="mb-0" style="font-size:.72rem;color:#9ca3af;">JPEG, PNG, GIF, or WebP • Max 5 MB</p>
                  <div id="filePreview" class="mt-2 small fw-medium text-primary" style="display:none;"></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right column: sticky summary + submit -->
        <div class="col-lg-4">
          <div class="sticky-top" style="top:calc(var(--topbar-h) + 1rem);z-index:1020;">
            <div class="card mb-3">
              <div class="card-body">
                <h6 class="fw-bold mb-3">Order Total</h6>
                <div class="d-flex justify-content-between small text-muted mb-1">
                  <span>Subtotal</span>
                  <span class="font-mono"><?= fmt_money((float)$totals['total_price']) ?></span>
                </div>
                <div class="d-flex justify-content-between small text-muted mb-2">
                  <span>Total PV</span>
                  <span class="font-mono text-success"><?= number_format((float)$totals['total_pv'], 2) ?> PV</span>
                </div>
                <hr class="my-2">
                <div class="d-flex justify-content-between align-items-center">
                  <span class="fw-bold">Total</span>
                  <span class="fw-bold font-mono fs-4"><?= fmt_money((float)$totals['total_price']) ?></span>
                </div>
              </div>
            </div>

            <div class="card mb-3">
              <div class="card-body">
                <div class="form-check mb-3">
                  <input class="form-check-input" type="checkbox" id="termsCheck" required>
                  <label class="form-check-label" for="termsCheck" style="font-size:.82rem;">
                    I confirm the order details are correct and agree to the terms.
                  </label>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2 fw-bold" id="placeOrderBtn" disabled>
                  Place Order
                </button>
                <a href="?page=repeat_purchases" class="btn btn-outline-secondary w-100 mt-2 py-2" style="font-size:.85rem;">
                  ← Back to Catalog
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<style>
.payment-option, .binary-option {
  transition: all .15s ease;
  border-color: #dde3ef !important;
}
.payment-option:hover, .binary-option:hover {
  border-color: var(--primary) !important;
  background: #f0f7ff;
}
.payment-option.selected, .binary-option.selected {
  border-color: var(--primary) !important;
  background: #e6f0ff;
  box-shadow: 0 0 0 2px rgba(59,111,240,.2);
}
.payment-option.selected::after,
.binary-option.selected::after {
  content: "✓";
  position: absolute;
  top: .35rem;
  right: .5rem;
  width: 20px;
  height: 20px;
  background: var(--primary);
  color: #fff;
  border-radius: 50%;
  font-size: .7rem;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
}
.payment-option, .binary-option { position: relative; }

#dropZone:hover, #dropZone.dragover {
  border-color: var(--primary) !important;
  background: #f0f7ff !important;
}
</style>

<script>
(function() {
  var paymentRadios = document.querySelectorAll('[name=payment_method]');
  var binaryRadios = document.querySelectorAll('[name=binary_position]');
  var proofSection = document.getElementById('proofUploadSection');
  var termsCheck = document.getElementById('termsCheck');
  var placeBtn = document.getElementById('placeOrderBtn');
  var dropZone = document.getElementById('dropZone');
  var proofFile = document.getElementById('proofFile');
  var filePreview = document.getElementById('filePreview');

  function updateSelected(groupName, optionClass) {
    var radios = document.querySelectorAll('[name="' + groupName + '"]');
    radios.forEach(function(r) {
      var opt = r.closest('.' + optionClass);
      if (opt) opt.classList.toggle('selected', r.checked);
    });
  }

  paymentRadios.forEach(function(r) {
    r.addEventListener('change', function() {
      updateSelected('payment_method', 'payment-option');
      toggleProof();
    });
  });

  if (binaryRadios.length) {
    binaryRadios.forEach(function(r) {
      r.addEventListener('change', function() {
        updateSelected('binary_position', 'binary-option');
      });
    });
  }

  // Initialize selected states
  updateSelected('payment_method', 'payment-option');
  if (binaryRadios.length) {
    updateSelected('binary_position', 'binary-option');
  }

  function toggleProof() {
    var checked = document.querySelector('[name=payment_method]:checked');
    proofSection.style.display = checked && checked.value !== 'ewallet' ? 'block' : 'none';
  }

  toggleProof();

  termsCheck.addEventListener('change', function() {
    placeBtn.disabled = !this.checked;
  });

  // File drop zone
  if (dropZone && proofFile) {
    dropZone.addEventListener('click', function() { proofFile.click(); });
    dropZone.addEventListener('dragover', function(e) {
      e.preventDefault();
      dropZone.classList.add('dragover');
    });
    dropZone.addEventListener('dragleave', function() {
      dropZone.classList.remove('dragover');
    });
    dropZone.addEventListener('drop', function(e) {
      e.preventDefault();
      dropZone.classList.remove('dragover');
      if (e.dataTransfer.files.length) {
        proofFile.files = e.dataTransfer.files;
        showFileName(e.dataTransfer.files[0].name);
      }
    });
    proofFile.addEventListener('change', function() {
      if (this.files.length) showFileName(this.files[0].name);
    });
  }

  function showFileName(name) {
    if (filePreview) {
      filePreview.textContent = 'Selected: ' + name;
      filePreview.style.display = '';
    }
  }
})();
</script>
