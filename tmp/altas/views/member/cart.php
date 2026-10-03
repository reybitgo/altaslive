<?php $pageTitle = 'Cart'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>
<?php
// Pre-compute display values used by both the items list and the summary.
$itemCount = empty($cartItems) ? 0 : (int)array_sum(array_column($cartItems, 'quantity'));
$skuCount  = count($cartItems);
$subTotal  = (float)($cartTotals['total_price'] ?? 0);
$totalPv   = (float)($cartTotals['total_pv'] ?? 0);
?>
<style>
  /* Cart page — table-style line items with inline controls */
  .cart-line {
    padding: 1rem 1.1rem;
    border-bottom: 1px solid var(--bs-border-color);
    transition: background .15s ease;
  }
  .cart-line:last-child { border-bottom: none; }
  .cart-line:hover { background: #f8fafd; }

  .cart-thumb {
    width: 64px;
    height: 64px;
    object-fit: cover;
    border-radius: .65rem;
    border: 1px solid #e8ecf5;
    flex-shrink: 0;
  }
  .cart-thumb-ph {
    width: 64px; height: 64px;
    display: flex; align-items: center; justify-content: center;
    background: #f8fafc; border-radius: .65rem; border: 1px solid #e8ecf5;
    font-size: 1.5rem; flex-shrink: 0;
  }

  /* Quantity stepper */
  .qty-stepper {
    display: inline-flex;
    align-items: stretch;
    border: 1px solid #dde3ef;
    border-radius: .55rem;
    overflow: hidden;
    background: #fff;
  }
  .qty-stepper button {
    width: 32px;
    border: none;
    background: #f8fafd;
    color: var(--bs-body-color);
    font-weight: 700;
    font-size: 1rem;
    line-height: 1;
    cursor: pointer;
    transition: background .12s ease;
  }
  .qty-stepper button:hover:not(:disabled) { background: #eaf0fb; color: var(--primary); }
  .qty-stepper button:disabled { opacity: .4; cursor: not-allowed; }
  .qty-stepper input {
    width: 46px;
    border: none;
    border-left: 1px solid #dde3ef;
    border-right: 1px solid #dde3ef;
    text-align: center;
    font-weight: 600;
    font-family: var(--font-mono);
    font-size: .85rem;
    box-shadow: none;
    padding: 0;
    -moz-appearance: textfield;
  }
  .qty-stepper input::-webkit-outer-spin-button,
  .qty-stepper input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

  .remove-link {
    font-size: .76rem;
    color: var(--muted);
    text-decoration: none;
    cursor: pointer;
  }
  .remove-link:hover { color: var(--danger); }

  .stock-chip {
    font-size: .68rem;
    font-weight: 600;
    padding: .1rem .45rem;
    border-radius: 999px;
    background: #ecfdf5;
    color: var(--success);
    border: 1px solid #bbf7d0;
  }
  .stock-chip.low { background: #fffbeb; color: var(--warning); border-color: #fde68a; }

  /* Sticky summary highlight */
  .summary-total {
    background: linear-gradient(135deg, #f0f7ff, #eaf2ff);
    border: 1px solid rgba(59,111,240,.18);
    border-radius: .7rem;
    padding: .85rem 1rem;
  }

  /* Column header row for the line items */
  .cart-col-head {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 1rem;
    padding: .6rem 1.1rem;
    background: #f8fafd;
    border-bottom: 1px solid var(--bs-border-color);
    font-size: .66rem;
    font-weight: 700;
    letter-spacing: .6px;
    text-transform: uppercase;
    color: var(--muted);
  }
  @media (min-width: 768px) {
    .cart-col-head { grid-template-columns: 3fr 1.1fr 1fr .9fr; }
    .cart-col-head .col-qty { text-align: center; }
    .cart-col-head .col-total { text-align: right; }

    .cart-line { grid-template-columns: 3fr 1.1fr 1fr .9fr; }
    .cart-line > div { min-width: 0; }
  }
</style>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <!-- Breadcrumb + header -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
      <div>
        <div class="text-muted mb-1" style="font-size:.74rem;">
          <a href="<?= APP_URL ?>/?page=repeat_purchases" class="text-decoration-none">Shop</a>
          <span class="mx-1">/</span>
          <span class="fw-semibold text-dark">Cart</span>
        </div>
        <h4 class="mb-0 d-flex align-items-center gap-2">
          Shopping Cart
          <?php if ($itemCount > 0): ?>
            <span class="badge rounded-pill bg-light text-dark border" style="font-size:.7rem;"><?= $itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?></span>
          <?php endif; ?>
        </h4>
      </div>
      <?php if (!empty($cartItems)): ?>
        <a href="<?= APP_URL ?>/?page=repeat_purchases" class="btn btn-outline-secondary btn-sm">← Continue Shopping</a>
      <?php endif; ?>
    </div>

    <?php if (empty($cartItems)): ?>
      <!-- ── Empty state ───────────────────────────────────────── -->
      <div class="card">
        <div class="card-body text-center py-5">
          <div class="mx-auto mb-3 d-flex align-items-center justify-content-center"
               style="width:88px;height:88px;border-radius:50%;background:var(--primary-light);">
            <span style="font-size:2.6rem;">🛒</span>
          </div>
          <h5 class="fw-bold mb-1">Your cart is empty</h5>
          <p class="text-muted mb-3" style="font-size:.85rem;">Browse our products and add items to start earning Personal PV.</p>
          <a href="<?= APP_URL ?>/?page=repeat_purchases" class="btn btn-primary px-4">Browse Products</a>
        </div>
      </div>
    <?php else: ?>
      <div class="row g-3">
        <!-- ── Line items ─────────────────────────────────────── -->
        <div class="col-lg-8">
          <div class="card" id="cartItemsContainer">
            <!-- Column headers (desktop) -->
            <div class="cart-col-head d-none d-md-grid">
              <span>Product</span>
              <span class="col-price text-end">Price</span>
              <span class="col-qty">Quantity</span>
              <span class="col-total">Total</span>
            </div>

            <?php foreach ($cartItems as $item):
              $available = Product::availableStock((int)$item['product_id']);
              $subtotal  = (float)$item['unit_price'] * (int)$item['quantity'];
              $imgUrl    = !empty($item['image_url']) ? APP_URL . '/uploads/' . $item['image_url'] : '';
              $atMax     = (int)$item['quantity'] >= $available;
            ?>
            <div class="cart-line cart-item d-md-grid align-items-center"
                 style="gap:1rem;"
                 data-item-id="<?= (int)$item['id'] ?>"
                 data-unit-price="<?= (float)$item['unit_price'] ?>"
                 data-unit-pv="<?= (float)$item['unit_pv'] ?>">

              <!-- Product -->
              <div class="d-flex align-items-center gap-3 mb-2 mb-md-0">
                <?php if ($imgUrl): ?>
                  <img src="<?= e($imgUrl) ?>" alt="<?= e($item['product_name']) ?>" class="cart-thumb" loading="lazy">
                <?php else: ?>
                  <div class="cart-thumb-ph">🛍️</div>
                <?php endif; ?>
                <div class="min-w-0">
                  <div class="fw-semibold text-truncate"><?= e($item['product_name']) ?></div>
                  <div class="small text-muted">
                    <span class="font-mono"><?= fmt_money((float)$item['unit_price']) ?></span> / each
                  </div>
                  <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                    <span class="text-success small fw-medium"><?= number_format((float)$item['unit_pv'], 2) ?> PV</span>
                    <?php if ($available > 0): ?>
                      <span class="stock-chip <?= $available <= 5 ? 'low' : '' ?>"><?= $available ?> in stock</span>
                    <?php else: ?>
                      <span class="stock-chip low">Out of stock</span>
                    <?php endif; ?>
                    <a class="remove-link cart-remove-btn" data-item-id="<?= (int)$item['id'] ?>" title="Remove from cart">✕ Remove</a>
                  </div>
                </div>
              </div>

              <!-- Price (desktop only column; mobile shows it inline above) -->
              <div class="d-none d-md-block text-end font-mono small text-muted">
                <?= fmt_money((float)$item['unit_price']) ?>
              </div>

              <!-- Quantity stepper -->
              <div class="d-flex align-items-center justify-content-between justify-content-md-center mb-2 mb-md-0">
                <span class="d-md-none small text-muted fw-medium">Quantity</span>
                <div class="qty-stepper">
                  <button type="button" class="cart-qty-btn" data-dir="down" aria-label="Decrease quantity">−</button>
                  <input type="number" class="cart-qty-input form-control"
                         value="<?= (int)$item['quantity'] ?>" min="1"
                         max="<?= max(1, $available) ?>"
                         data-item-id="<?= (int)$item['id'] ?>"
                         aria-label="Quantity">
                  <button type="button" class="cart-qty-btn" data-dir="up" aria-label="Increase quantity" <?= $atMax ? 'disabled' : '' ?>>+</button>
                </div>
              </div>

              <!-- Line total -->
              <div class="d-flex align-items-center justify-content-between justify-content-md-block text-md-end">
                <span class="d-md-none small text-muted fw-medium">Total</span>
                <div>
                  <div class="fw-bold font-mono cart-item-subtotal"><?= fmt_money($subtotal) ?></div>
                  <div class="small text-success d-md-none"><?= number_format((float)$item['unit_pv'] * (int)$item['quantity'], 2) ?> PV</div>
                </div>
              </div>
            </div>
            <?php endforeach; ?>

            <!-- Card footer -->
            <div class="card-footer bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2">
              <a href="<?= APP_URL ?>/?page=repeat_purchases" class="btn btn-link btn-sm text-decoration-none ps-0">+ Add more items</a>
              <div class="small text-muted">
                <span class="fw-semibold text-dark"><?= $skuCount ?></span> distinct product<?= $skuCount === 1 ? '' : 's' ?>
                • <span class="fw-semibold text-dark"><?= $itemCount ?></span> total item<?= $itemCount === 1 ? '' : 's' ?>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Order summary (sticky) ─────────────────────────── -->
        <div class="col-lg-4" id="cartFooter">
          <div class="sticky-top" style="top:calc(var(--topbar-h) + 1rem);z-index:1020;">
            <div class="card">
              <div class="card-body p-3">
                <h6 class="fw-bold mb-3">Order Summary</h6>

                <div class="d-flex justify-content-between small mb-2">
                  <span class="text-muted">Items (<span id="cartItemsCount"><?= $itemCount ?></span>)</span>
                  <span class="font-mono" id="cartSubtotal"><?= fmt_money($subTotal) ?></span>
                </div>
                <div class="d-flex justify-content-between small mb-3">
                  <span class="text-muted">Total PV earned</span>
                  <span class="font-mono text-success" id="cartTotalPv"><?= number_format($totalPv, 2) ?> PV</span>
                </div>

                <hr class="my-2">

                <div class="summary-total d-flex justify-content-between align-items-center mb-3">
                  <span class="fw-bold">Total</span>
                  <span class="fw-bold font-mono fs-5" id="cartTotalPrice"><?= fmt_money($subTotal) ?></span>
                </div>

                <a href="<?= APP_URL ?>/?page=checkout" class="btn btn-primary w-100 py-2 fw-bold">
                  Proceed to Checkout →
                </a>
                <p class="text-center text-muted mt-2 mb-0" style="font-size:.72rem;">
                  💡 PV is finalized at checkout. Taxes/fees may apply.
                </p>
              </div>
            </div>

            <!-- Trust/assurances strip -->
            <div class="card mt-3">
              <div class="card-body p-3 d-flex flex-column gap-2">
                <div class="d-flex align-items-center gap-2 small">
                  <span style="font-size:1rem;">🔒</span>
                  <span class="text-muted">Secure checkout via your e-wallet or approved channels.</span>
                </div>
                <div class="d-flex align-items-center gap-2 small">
                  <span style="font-size:1rem;">⚡</span>
                  <span class="text-muted">PV credits to your binary leg upon approval.</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
// Page-specific enhancements layered on top of the global cart JS
// (cart_offcanvas.php handles quantity changes and live totals via the
// .cart-qty-*/.cart-remove-btn hooks + total element IDs).
//
// Removal: we intercept it here in the CAPTURE phase, stop it from reaching
// the global handler, show a confirmation, and perform our own removal fetch.
// This guarantees a single request and a clean confirm UX.
(function () {
  var CSRF = '<?= csrf_token() ?>';

  function removeItem(itemId, row) {
    var fd = new FormData();
    fd.append('item_id', itemId);
    fetch('<?= APP_URL ?>/?page=remove_cart_item', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF },
      body: fd
    }).then(function (r) { return r.json(); }).then(function (json) {
      if (row) row.remove();
      // Refresh totals + topbar badge to match the global helper's behaviour.
      if (json.totals) {
        var c = document.getElementById('cartItemsCount');
        var s = document.getElementById('cartSubtotal');
        var p = document.getElementById('cartTotalPv');
        var t = document.getElementById('cartTotalPrice');
        var n = parseInt(json.totals.total_items) || 0;
        var price = json.totals.total_price ? '₱' + parseFloat(json.totals.total_price).toFixed(2) : '₱0.00';
        var pv    = json.totals.total_pv    ? parseFloat(json.totals.total_pv).toFixed(2) + ' PV' : '0.00 PV';
        if (c) c.textContent = n;
        if (s) s.textContent = price;
        if (p) p.textContent = pv;
        if (t) t.textContent = price;
        document.querySelectorAll('.topbar-wrapper .badge').forEach(function (b) {
          b.textContent = n; b.style.display = n < 1 ? 'none' : '';
        });
      }
      if (typeof showToast === 'function') showToast('Item removed from cart', 'info');
    }).catch(function (err) { console.error(err); });
  }

  // Intercept removal BEFORE the global (bubbling) handler runs.
  document.addEventListener('click', function (e) {
    var link = e.target.closest('.cart-remove-btn');
    if (!link) return;
    e.preventDefault();
    e.stopPropagation();
    var itemId = link.getAttribute('data-item-id');
    var row = link.closest('.cart-item');

    if (typeof showConfirm !== 'function') { removeItem(itemId, row); return; }
    showConfirm({
      title: 'Remove item?',
      message: 'This product will be removed from your cart. You can add it again from the shop.',
      confirmText: 'Remove',
      confirmClass: 'btn-danger',
      onConfirm: function () { removeItem(itemId, row); }
    });
  }, true);

  // Keep the stepper buttons in sync with the input value
  // (disable − at 1, + at max stock).
  function syncStepper(input) {
    var max = parseInt(input.getAttribute('max')) || 999;
    var up = input.parentElement.querySelector('[data-dir="up"]');
    var down = input.parentElement.querySelector('[data-dir="down"]');
    if (up) up.disabled = parseInt(input.value) >= max;
    if (down) down.disabled = parseInt(input.value) <= 1;
  }
  document.addEventListener('change', function (e) {
    var input = e.target.closest('.cart-qty-input');
    if (input) syncStepper(input);
  });

  // Reload to the empty state once the last line item is removed, so the
  // layout (column headers, summary) is replaced cleanly.
  var observer = new MutationObserver(function () {
    if (document.querySelectorAll('.cart-item').length === 0) {
      window.location.reload();
    }
  });
  var list = document.getElementById('cartItemsContainer');
  if (list) observer.observe(list, { childList: true });
})();
</script>
