<?php

/**
 * @file   views/partials/cart_offcanvas.php
 * @brief  Global off-canvas cart drawer + interactions.
 *
 * Included via footer.php so the topbar 🛒 button works on every page
 * (member + admin). Self-contained: loads the current user's cart when the
 * including page has not already provided $cartItems / $cartTotals.
 */

// Only render for logged-in users.
if (!Auth::check()) {
    return;
}

if (!isset($cartItems) || !isset($cartTotals)) {
    $_cart     = Cart::getOrCreate(Auth::id());
    $cartItems  = Cart::getItems((int)$_cart['id']);
    $cartTotals = Cart::getTotals((int)$_cart['id']);
}

$itemCount = empty($cartItems) ? 0 : (int)array_sum(array_column($cartItems, 'quantity'));
$itemTotal = 0;
foreach ($cartItems as $ci) {
    $itemTotal += (float)$ci['unit_price'] * (int)$ci['quantity'];
}
?>

<!-- Off-canvas Cart Sidebar -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="cartOffcanvas" aria-labelledby="cartOffcanvasLabel">
  <div class="offcanvas-header border-bottom">
    <h5 class="offcanvas-title" id="cartOffcanvasLabel">
      <span class="d-flex align-items-center gap-2">
        🛒 Cart
        <?php if ($itemCount > 0): ?>
        <span class="badge bg-primary rounded-pill fs-6 fw-medium"><?= $itemCount ?></span>
        <?php endif; ?>
      </span>
    </h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body d-flex flex-column p-0">
    <?php if (empty($cartItems)): ?>
    <div class="d-flex flex-column align-items-center justify-content-center flex-grow-1 text-muted gap-2 p-4">
      <span style="font-size:3rem;">🛒</span>
      <p class="mb-0 fw-medium">Your cart is empty</p>
      <small>Browse products and add items to get started.</small>
      <a href="<?= APP_URL ?>/?page=repeat_purchases" class="btn btn-outline-primary btn-sm mt-2" data-bs-dismiss="offcanvas">Browse Products</a>
    </div>
    <?php else: ?>
    <div class="flex-grow-1 overflow-auto" id="cartItemsContainer">
      <?php foreach ($cartItems as $item):
        $available = Product::availableStock((int)$item['product_id']);
        $subtotal  = (float)$item['unit_price'] * (int)$item['quantity'];
      ?>
      <div class="cart-item px-3 py-3 border-bottom" data-item-id="<?= (int)$item['id'] ?>" data-unit-price="<?= (float)$item['unit_price'] ?>" data-unit-pv="<?= (float)$item['unit_pv'] ?>">
        <div class="d-flex gap-3">
          <?php if ($item['image_url']): ?>
          <div class="flex-shrink-0">
            <img src="<?= APP_URL ?>/uploads/<?= e($item['image_url']) ?>" alt="<?= e($item['product_name']) ?>" class="rounded-2" style="width:64px;height:64px;object-fit:cover;border:1px solid #eef1f8;">
          </div>
          <?php else: ?>
          <div class="flex-shrink-0 bg-light rounded-2 d-flex align-items-center justify-content-center" style="width:64px;height:64px;border:1px solid #eef1f8;">
            <span style="font-size:1.5rem;">🛍️</span>
          </div>
          <?php endif; ?>
          <div class="flex-grow-1 min-w-0">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <h6 class="mb-0 text-truncate"><?= e($item['product_name']) ?></h6>
              <button class="btn btn-sm p-0 border-0 text-muted cart-remove-btn" title="Remove" style="line-height:1;" data-item-id="<?= (int)$item['id'] ?>">✕</button>
            </div>
            <div class="small text-muted mt-1"><?= fmt_money((float)$item['unit_price']) ?> each</div>
            <div class="d-flex justify-content-between align-items-center mt-2">
              <div class="input-group input-group-sm border rounded-2" style="width:110px;">
                <button class="btn btn-sm border-0 px-2 fw-bold cart-qty-btn" type="button" data-dir="down">−</button>
                <input type="number" class="form-control border-0 text-center bg-transparent fw-semibold cart-qty-input" value="<?= (int)$item['quantity'] ?>" min="1" max="<?= $available ?>" style="font-size:.8rem;box-shadow:none;" data-item-id="<?= (int)$item['id'] ?>">
                <button class="btn btn-sm border-0 px-2 fw-bold cart-qty-btn" type="button" data-dir="up">+</button>
              </div>
              <span class="fw-semibold font-mono small cart-item-subtotal"><?= fmt_money($subtotal) ?></span>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="border-top bg-white p-3 shadow-sm" id="cartFooter">
      <div class="d-flex justify-content-between align-items-center mb-2 small text-muted">
        <span id="cartItemsLabel">Subtotal (<span id="cartItemsCount"><?= $itemCount ?></span> items)</span>
        <span class="fw-semibold font-mono" id="cartSubtotal"><?= fmt_money($itemTotal) ?></span>
      </div>
      <div class="d-flex justify-content-between align-items-center mb-3 small text-muted">
        <span>Total PV</span>
        <span class="fw-semibold font-mono text-success" id="cartTotalPv"><?= fmt_money((float)$cartTotals['total_pv']) ?></span>
      </div>
      <a href="<?= APP_URL ?>/?page=checkout" class="btn btn-primary w-100 d-flex align-items-center justify-content-between">
        <span>Proceed to Checkout</span>
        <span class="fw-bold" id="cartTotalPrice"><?= fmt_money((float)$cartTotals['total_price']) ?></span>
      </a>
      <button class="btn btn-link btn-sm text-muted w-100 mt-1 text-decoration-none" data-bs-dismiss="offcanvas">Continue Shopping</button>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function() {
  var cartEl = document.getElementById('cartOffcanvas');
  if (!cartEl) return;

  var csrfToken = '<?= csrf_token() ?>';

  function cartFetch(url, data, cb) {
    var fd = new FormData();
    for (var k in data) fd.append(k, data[k]);
    fetch(url, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken },
      body: fd
    }).then(function(r) {
      if (!r.ok) return r.json().then(function(j) { throw new Error(j.error || 'Request failed'); });
      return r.json();
    }).then(function(json) {
      cb(json);
    }).catch(function(err) {
      console.error(err);
    });
  }

  function updateFooter(totals) {
    var c = document.getElementById('cartItemsCount');
    var s = document.getElementById('cartSubtotal');
    var p = document.getElementById('cartTotalPv');
    var t = document.getElementById('cartTotalPrice');
    if (totals) {
      if (c) c.textContent = totals.total_items || 0;
      if (s) s.textContent = totals.total_price ? '₱' + parseFloat(totals.total_price).toFixed(2) : '₱0.00';
      if (p) p.textContent = totals.total_pv ? '₱' + parseFloat(totals.total_pv).toFixed(2) : '₱0.00';
      if (t) t.textContent = totals.total_price ? '₱' + parseFloat(totals.total_price).toFixed(2) : '₱0.00';
    }
  }

  function updateTopbarBadge(count) {
    var badges = document.querySelectorAll('.topbar-wrapper .badge');
    badges.forEach(function(b) {
      b.textContent = count;
      if (count < 1) b.style.display = 'none';
      else b.style.display = '';
    });
  }

  document.addEventListener('change', function(e) {
    var input = e.target.closest('.cart-qty-input');
    if (!input) return;
    var itemId = input.getAttribute('data-item-id');
    var qty = parseInt(input.value) || 1;
    var max = parseInt(input.getAttribute('max')) || 999;
    if (qty < 1) qty = 1;
    if (qty > max) qty = max;
    input.value = qty;
    cartFetch('<?= APP_URL ?>/?page=update_cart_item', { item_id: itemId, quantity: qty }, function(json) {
      if (json.totals) {
        updateFooter(json.totals);
        updateTopbarBadge(parseInt(json.totals.total_items) || 0);
      }
      var row = input.closest('.cart-item');
      if (row) {
        var price = parseFloat(row.getAttribute('data-unit-price')) || 0;
        var sub = row.querySelector('.cart-item-subtotal');
        if (sub) sub.textContent = '₱' + (price * qty).toFixed(2);
      }
    });
  });

  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.cart-qty-btn');
    if (!btn) return;
    var input = btn.parentNode.querySelector('.cart-qty-input');
    if (!input) return;
    var v = parseInt(input.value) || 1;
    var max = parseInt(input.getAttribute('max')) || 999;
    if (btn.getAttribute('data-dir') === 'down') { if (v > 1) input.value = v - 1; }
    else { if (v < max) input.value = v + 1; }
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });

  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.cart-remove-btn');
    if (!btn) return;
    var itemId = btn.getAttribute('data-item-id');
    var row = btn.closest('.cart-item');
    cartFetch('<?= APP_URL ?>/?page=remove_cart_item', { item_id: itemId }, function(json) {
      if (row) row.remove();
      if (json.totals) updateFooter(json.totals);
      var remaining = document.querySelectorAll('.cart-item');
      if (remaining.length < 1) {
        var container = document.getElementById('cartItemsContainer');
        if (container) {
          container.innerHTML = '<div class="d-flex flex-column align-items-center justify-content-center flex-grow-1 text-muted gap-2 p-4"><span style="font-size:3rem;">🛒</span><p class="mb-0 fw-medium">Your cart is empty</p><small>Browse products and add items to get started.</small><a href="<?= APP_URL ?>/?page=repeat_purchases" class="btn btn-outline-primary btn-sm mt-2" data-bs-dismiss="offcanvas">Browse Products</a></div>';
        }
        var footer = document.getElementById('cartFooter');
        if (footer) footer.remove();
        var headerBadge = document.querySelector('#cartOffcanvas .offcanvas-title .badge');
        if (headerBadge) headerBadge.remove();
      }
      updateTopbarBadge(json.totals ? (parseInt(json.totals.total_items) || 0) : 0);
    });
  });

  if (typeof bootstrap !== 'undefined') {
    var params = new URLSearchParams(location.search);
    if (params.has('cart') && params.get('cart') === '1') {
      var offcanvas = bootstrap.Offcanvas.getOrCreateInstance(cartEl);
      offcanvas.show();
      params.delete('cart');
      var newUrl = location.pathname + '?' + params.toString() + location.hash;
      history.replaceState(null, '', newUrl.replace(/\?$/, ''));
    }
  }
})();
</script>
