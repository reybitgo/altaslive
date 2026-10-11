<?php
/**
 * @file   views/member/checkout.php
 * @brief  Checkout: address snapshot + payment method + terms (plan §5.13).
 *         Expects: $cartId, $items, $totals, $paymentMethods.
 */
?>
<?php $pageTitle = 'Checkout'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <h4 class="mb-1">Checkout</h4>
    <p class="text-muted" style="font-size:.8rem;">Review your order and fill in the delivery details.</p>

    <form method="post" action="<?= link_to('place_order') ?>" id="checkoutForm">
      <?= csrf_field() ?>
      <input type="hidden" name="idem" value="<?= e(uniqid('', true)) ?>">

      <div class="row g-3">
        <!-- ── Left: address + payment ── -->
        <div class="col-lg-7">
          <div class="card mb-3">
            <div class="card-header"><span class="card-title">📍 Delivery details</span></div>
            <div class="card-body">
              <div class="row g-2">
                <div class="col-md-6">
                  <label class="form-label" for="shipping_name">Recipient name *</label>
                  <input type="text" id="shipping_name" name="shipping_name" class="form-control" maxlength="120" required
                         value="<?= e($old['shipping_name'] ?? (Auth::user()['full_name'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="shipping_phone">Recipient phone</label>
                  <input type="text" id="shipping_phone" name="shipping_phone" class="form-control" maxlength="40"
                         value="<?= e($old['shipping_phone'] ?? (Auth::user()['mobile'] ?? '')) ?>">
                </div>
                <div class="col-12">
                  <label class="form-label" for="shipping_address">Delivery address *</label>
                  <input type="text" id="shipping_address" name="shipping_address" class="form-control" maxlength="255" placeholder="House / street / barangay" required value="<?= e($old['shipping_address'] ?? '') ?>">
                </div>
                <div class="col-md-5">
                  <label class="form-label" for="shipping_city">City</label>
                  <input type="text" id="shipping_city" name="shipping_city" class="form-control" maxlength="80" value="<?= e($old['shipping_city'] ?? '') ?>">
                </div>
                <div class="col-md-5">
                  <label class="form-label" for="shipping_province">Province</label>
                  <input type="text" id="shipping_province" name="shipping_province" class="form-control" maxlength="80" value="<?= e($old['shipping_province'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                  <label class="form-label" for="shipping_postal">Postal</label>
                  <input type="text" id="shipping_postal" name="shipping_postal" class="form-control" maxlength="20" value="<?= e($old['shipping_postal'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="billing_name">Billing name</label>
                  <input type="text" id="billing_name" name="billing_name" class="form-control" maxlength="120"
                         value="<?= e($old['billing_name'] ?? (Auth::user()['full_name'] ?? '')) ?>">
                  <div class="form-text">Name shown on the payment records.</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="billing_phone">Billing phone</label>
                  <input type="text" id="billing_phone" name="billing_phone" class="form-control" maxlength="40" value="<?= e($old['billing_phone'] ?? '') ?>">
                </div>
                <div class="col-12">
                  <label class="form-label" for="notes_member">Delivery notes</label>
                  <textarea id="notes_member" name="notes_member" class="form-control" rows="2" maxlength="500" placeholder="Landmarks, preferred times…"><?= e($old['notes_member'] ?? '') ?></textarea>
                </div>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-header"><span class="card-title">💳 Payment method</span></div>
            <div class="card-body">
              <?php
              // Phase 8.7: the e-wallet option used to be checked by default
              // with no regard for the balance, so a member with nothing in
              // wallet could pick it, fill in every address field, press Place
              // order, and have the whole form discarded by the failure path.
              $ewalletBalance = Ewallet::balance(Auth::id());
              $ewalletEnough  = $ewalletBalance + 0.005 >= (float) $cartTotals['total_price'];
              // Anything the buyer typed on a failed attempt comes back with
              // them (see shop_checkout_old_input()).
              $old = shop_checkout_old_input();
              ?>
              <?php foreach ($paymentMethods as $key => $label): ?>
                <div class="form-check mb-2">
                  <?php
                  $isEwallet = $key === 'ewallet';
                  $blocked   = $isEwallet && !$ewalletEnough;
                  $chosen    = $old['payment_method'] ?? ($isEwallet ? 'ewallet' : '');
                  ?>
                  <input class="form-check-input" type="radio" name="payment_method" id="pm_<?= e($key) ?>"
                         value="<?= e($key) ?>"
                         <?= $chosen === $key ? 'checked' : '' ?>
                         <?= $blocked ? 'disabled data-requires-balance="1"' : '' ?>
                         required>
                  <label class="form-check-label<?= $blocked ? ' text-muted' : '' ?>" for="pm_<?= e($key) ?>"><?= e($label) ?>
                    <?php if ($isEwallet): ?>
                      <span class="text-muted" style="font-size:.78rem;">— balance <?= fmt_money($ewalletBalance) ?></span>
                      <?php if ($blocked): ?>
                        <span class="d-block text-danger" style="font-size:.78rem;">
                          Not enough e-wallet balance for this order — add funds or choose another method.
                        </span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </label>
                </div>
              <?php endforeach; ?>
              <div id="walletShortfall" class="text-danger small d-none">
                Your e-wallet balance does not cover this order.
              </div>
              <div class="form-text">E-wallet orders are paid instantly. For other methods you'll upload a payment proof after placing the order.</div>
            </div>
          </div>
        </div>

        <!-- ── Right: summary ── -->
        <div class="col-lg-5">
          <div class="card">
            <div class="card-header"><span class="card-title">Your order</span></div>
            <div class="card-body">
              <?php foreach ($items as $it): ?>
                <div class="d-flex justify-content-between border-bottom py-1" style="font-size:.85rem;">
                  <span><?= e($it['product_name']) ?> × <?= (int) $it['quantity'] ?></span>
                  <span class="font-mono"><?= fmt_money((float) $it['unit_price'] * (int) $it['quantity']) ?></span>
                </div>
              <?php endforeach; ?>
              <div class="d-flex justify-content-between fw-bold mt-2">
                <span>Total</span><span class="font-mono"><?= fmt_money((float) $totals['total_price']) ?></span>
              </div>
              <div class="text-muted mt-1" style="font-size:.75rem;">Total is what you pay. No shipping or service fee.</div>

              <div class="form-check mt-3">
                <input class="form-check-input" type="checkbox" name="terms" id="terms" value="1" required>
                <label class="form-check-label" for="terms" style="font-size:.8rem;">
                  I accept the terms: orders can be cancelled until payment is verified; after verification,
                  amounts are non-refundable except through the shop's own refund process.
                </label>
              </div>
            </div>
            <div class="card-footer">
              <button type="submit" class="btn btn-primary w-100" id="placeOrderBtn" data-busy-label="Placing order…">Place order</button>
              <a href="<?= link_to('cart') ?>" class="btn btn-link btn-sm w-100 mt-2" style="text-decoration:none;">← Back to cart</a>
            </div>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>
<script>
// Single-submit guard (M8).
//
// This previously only set a dataset flag and returned early on the second
// click, which stopped nothing: the listener never called preventDefault() and
// never disabled the button, so both clicks still submitted the form. The
// comment above it claimed the opposite.
//
// The form submit event is the right hook, not click — Enter in a text field
// submits without a click on the button at all.
(function () {
  var form = document.getElementById('checkoutForm');
  var btn  = document.getElementById('placeOrderBtn');
  if (!form || !btn) return;

  // E-wallet is disabled at render time when the balance is short. This is a
  // convenience only — placeOrder() re-checks the balance server-side inside
  // the transaction — but it stops the member filling in the whole form only
  // to be rejected at the last step.
  var wallet = document.querySelector('input[name="payment_method"][data-requires-balance="1"]');
  var shortfall = document.getElementById('walletShortfall');
  if (wallet && shortfall && wallet.checked) {
    // A disabled radio cannot stay checked; make sure nothing is pre-selected
    // into an invalid state, and say why.
    wallet.checked = false;
    shortfall.classList.remove('d-none');
  }

  var sent = false;
  form.addEventListener('submit', function (e) {
    if (wallet && wallet.checked) {
      e.preventDefault();
      shortfall.classList.remove('d-none');
      return;
    }
    if (sent) { e.preventDefault(); return; }
    sent = true;
    // Disable only the button, NOT the form: a disabled control's name/value
    // is omitted from the POST, which would strip every address field the
    // buyer just typed along with the payload.
    btn.disabled = true;
    if (btn.dataset.busyLabel) btn.textContent = btn.dataset.busyLabel;
  });

  // BFCache restores keep the DOM but drop the flag, stranding a dead button.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    sent = false;
    btn.disabled = false;
  });
})();
</script>
<?php require 'views/partials/footer.php'; ?>
