<?php
/**
 * @file   views/partials/cart_offcanvas.php
 * @brief  Global off-canvas cart drawer, included from footer.php for any
 *         logged-in session (members AND admins, §5.8). Uses Cart::getActive()
 *         so a page view never INSERTs a cart row (§5.1).
 */
if (!Auth::check()) return;
$cart = Cart::getActive(Auth::id());
$cartItems  = $cart ? Cart::getItems((int) $cart['id']) : [];
$cartTotals = $cart ? Cart::getTotals((int) $cart['id']) : ['total_price' => 0, 'total_items' => 0];
$itemCount  = (int) $cartTotals['total_items'];
?>
<div class="offcanvas offcanvas-end" tabindex="-1" id="cartOffcanvas" aria-labelledby="cartOffcanvasLabel">
  <div class="offcanvas-header border-bottom">
    <h5 class="offcanvas-title" id="cartOffcanvasLabel">
      🛒 Cart <span class="badge bg-primary rounded-pill" id="offcanvasCount"><?= $itemCount ?></span>
    </h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body p-0 d-flex flex-column">
    <?php if (empty($cartItems)): ?>
      <div class="d-flex flex-column align-items-center justify-content-center flex-grow-1 text-muted p-4">
        <div style="font-size:3rem;line-height:1">🛒</div>
        <p class="mt-3 mb-2">Your cart is empty</p>
        <a href="<?= link_to('shop') ?>" class="btn btn-primary btn-sm">Browse Products</a>
      </div>
    <?php else: ?>
      <div class="flex-grow-1 overflow-auto p-3" id="cartItemsContainer">
        <?php foreach ($cartItems as $item): $img = $item['image_url'] ? APP_URL.'/uploads/'.e($item['image_url']) : ''; ?>
          <div class="d-flex gap-3 pb-3 mb-3 border-bottom cart-item" data-item-id="<?= (int) $item['id'] ?>" data-unit-price="<?= (float) $item['unit_price'] ?>">
            <div class="flex-shrink-0" style="width:64px;height:64px;border:1px solid #eef1f8;border-radius:.5rem;overflow:hidden;background:#f8f9fb;display:flex;align-items:center;justify-content:center">
              <?php if ($img): ?><img src="<?= e($img) ?>" style="width:100%;height:100%;object-fit:cover"><?php else: ?><span style="font-size:1.5rem">🛒</span><?php endif; ?>
            </div>
            <div class="flex-grow-1 min-w-0">
              <div class="fw-semibold text-truncate"><?= e($item['product_name']) ?></div>
              <div class="small text-muted"><?= number_format((float) $item['unit_price'], 2) ?> PHP</div>
              <div class="d-flex align-items-center justify-content-between mt-2">
                <div class="input-group input-group-sm" style="width:110px">
                  <button class="btn btn-sm border cart-qty-btn" data-dir="down" type="button">-</button>
                  <input type="number" class="form-control text-center cart-qty-input" data-item-id="<?= (int) $item['id'] ?>" value="<?= (int) $item['quantity'] ?>" min="1" max="<?= (int) $item['stock'] ?>">
                  <button class="btn btn-sm border cart-qty-btn" data-dir="up" type="button">+</button>
                </div>
                <button class="btn btn-sm btn-link text-danger cart-remove-btn" data-item-id="<?= (int) $item['id'] ?>" type="button">Remove</button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="border-top bg-white p-3 shadow-sm" id="cartFooter">
        <div class="d-flex justify-content-between mb-2">
          <span>Subtotal</span>
          <span id="cartSubtotal"><?= number_format((float) $cartTotals['total_price'], 2) ?> PHP</span>
        </div>
        <div class="d-flex justify-content-between fw-semibold mb-3">
          <span>Total</span>
          <span id="cartTotalPrice"><?= number_format((float) $cartTotals['total_price'], 2) ?> PHP</span>
        </div>
        <a href="<?= link_to('checkout') ?>" class="btn btn-primary w-100">Checkout</a>          <a href="<?= link_to('cart') ?>" class="btn btn-link btn-sm w-100 mt-2" style="text-decoration:none;">View full cart</a>
      </div>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  var CSRF = '<?= e(function_exists('csrf_token') ? csrf_token() : '') ?>';

  // Seed the last server-confirmed quantity on every drawer line so a failed
  // update can roll the input back to a value that actually exists server-side.
  document.querySelectorAll('#cartOffcanvas .cart-qty-input').forEach(function (i) { i.dataset.qty = i.value; });

  function updateTopbarBadge(count) {
    var el = document.querySelector('.topbar-wrapper .topbar-cart-badge');
    if (el) el.textContent = count;
  }

  function updateFooter(subtotal) {
    var sub = document.getElementById('cartSubtotal');
    var tot = document.getElementById('cartTotalPrice');
    if (sub) sub.textContent = subtotal + ' PHP';
    if (tot) tot.textContent = subtotal + ' PHP';
  }

  function refreshDrawer() {
    // No full reload: redraw the empty-state / Browse Products link in place.
    var container = document.getElementById('cartItemsContainer');
    if (!container) return;
    if (document.querySelector('#cartItemsContainer .cart-item')) return; // items still present
    container.innerHTML =
      '<div class="d-flex flex-column align-items-center justify-content-center flex-grow-1 text-muted p-4">' +
      '<div style="font-size:3rem;line-height:1">🛒</div>' +
      '<p class="mt-3 mb-2">Your cart is empty</p>' +
      '<a href="' + '<?= APP_URL ?>' + '/?page=shop" class="btn btn-primary btn-sm">Browse Products</a>' +
      '</div>';
    updateFooter(0);
    updateTopbarBadge(0);
    document.getElementById('offcanvasCount').textContent = 0;
  }

  function post(url, data) {
    var body = new URLSearchParams(data);
    body.set('csrf_token', CSRF);
    return fetch(url, {
      method: 'POST',
      headers: {'X-Requested-With': 'XMLHttpRequest'},
      body: body
    }).then(function (r) { return r.json(); });
  }

  // §5.12: this script owns ONLY the offcanvas drawer controls. The full cart
  // page (?page=cart) renders the same .cart-qty-btn/.cart-item classes with its
  // own handlers — without this guard a single click is handled twice (two
  // increments, two POSTs). Everything outside #cartOffcanvas is not ours.
  var drawerEl = document.getElementById('cartOffcanvas');
  function inDrawer(el) { return !!(drawerEl && drawerEl.contains(el)); }

  document.addEventListener('click', function (e) {
    var qtyBtn = e.target.closest('.cart-qty-btn');
    if (qtyBtn) {
      if (!inDrawer(qtyBtn)) return;
      var wrap  = qtyBtn.closest('.cart-item');
      var input = wrap.querySelector('.cart-qty-input');
      var cur   = parseInt(input.value, 10) || 1;
      var stock = parseInt(input.max, 10) || 0;  // server-rendered stock ceiling
      var next;
      if (qtyBtn.dataset.dir === 'up') {
        next = cur + 1;
        if (stock > 0 && next > stock) {
          // Stock ceiling: quantity stays at stock and the member is told
          // why — no POST of a value the server would reject anyway.
          next = Math.min(cur, stock);
          if (window.showToast) showToast('Insufficient stock. Requested ' + (cur + 1) + ', only ' + stock + ' available.', 'error');
          if (next === cur) return;  // already at/below ceiling — display-only, nothing to save
        }
      } else {
        next = Math.max(1, cur - 1);
      }
      input.value = next;
      post('<?= link_to('update_cart_item') ?>', {item_id: wrap.dataset.itemId, quantity: next})
        .then(function (res) {
          if (!res.ok) {
            if (window.showToast) showToast(res.error || 'Update failed', 'error');
            input.value = input.dataset.qty || input.defaultValue;  // server kept the old quantity
            return;
          }
          input.dataset.qty = String(next);
          updateFooter(res.subtotal);
          updateTopbarBadge(res.count);
          document.getElementById('offcanvasCount').textContent = res.count;
        });
      return;
    }

    var rm = e.target.closest('.cart-remove-btn');
    if (rm) {
      if (!inDrawer(rm)) return;
      var row = rm.closest('.cart-item');
      post('<?= link_to('remove_cart_item') ?>', {item_id: row.dataset.itemId})
        .then(function (res) {
          if (!res.ok) { if (window.showToast) showToast(res.error || 'Remove failed', 'error'); return; }
          row.remove();
          updateFooter(res.subtotal);
          updateTopbarBadge(res.count);
          document.getElementById('offcanvasCount').textContent = res.count;
          if (!document.querySelector('#cartItemsContainer .cart-item')) {
            refreshDrawer();
          }
        });
    }
  });

  document.addEventListener('change', function (e) {
    if (!e.target.classList.contains('cart-qty-input')) return;
    if (!inDrawer(e.target)) return;
    var wrap = e.target.closest('.cart-item');
    var input = e.target;
    var v = Math.max(1, parseInt(input.value, 10) || 1);
    var stock = parseInt(input.max, 10) || 0;
    // Typed over the stock ceiling: hold at stock + notify, don't POST.
    if (stock > 0 && v > stock) {
      input.value = stock;
      if (window.showToast) showToast('Insufficient stock. Requested ' + v + ', only ' + stock + ' available.', 'error');
      return;
    }
    input.value = v;
    post('<?= link_to('update_cart_item') ?>', {item_id: wrap.dataset.itemId, quantity: v})
      .then(function (res) {
        if (!res.ok) {
          if (window.showToast) showToast(res.error || 'Update failed', 'error');
          input.value = input.dataset.qty || input.defaultValue;  // server kept the old quantity
          return;
        }
        input.dataset.qty = String(v);
        updateFooter(res.subtotal);
        updateTopbarBadge(res.count);
        document.getElementById('offcanvasCount').textContent = res.count;
      });
  });

  // ?cart=1 auto-open (§5.6): redirect without this JS is a silent no-op.
  document.addEventListener('DOMContentLoaded', function () {
    var params = new URLSearchParams(location.search);
    if (params.get('cart') === '1') {
      var el = document.getElementById('cartOffcanvas');
      if (el && window.bootstrap) {
        bootstrap.Offcanvas.getOrCreateInstance(el).show();
      }
      params.delete('cart');
      var qs = params.toString();
      history.replaceState(null, '', location.pathname + (qs ? '?' + qs : '') + location.hash);
    }
  });
})();
</script>
