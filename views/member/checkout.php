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

    <form method="post" action="<?= link_to('place_order') ?>">
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
                  <label class="form-label">Recipient name *</label>
                  <input type="text" name="shipping_name" class="form-control" maxlength="120" required
                         value="<?= e(Auth::user()['full_name'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Recipient phone</label>
                  <input type="text" name="shipping_phone" class="form-control" maxlength="40"
                         value="<?= e(Auth::user()['mobile'] ?? '') ?>">
                </div>
                <div class="col-12">
                  <label class="form-label">Delivery address *</label>
                  <input type="text" name="shipping_address" class="form-control" maxlength="255" placeholder="House / street / barangay" required>
                </div>
                <div class="col-md-5">
                  <label class="form-label">City</label>
                  <input type="text" name="shipping_city" class="form-control" maxlength="80">
                </div>
                <div class="col-md-5">
                  <label class="form-label">Province</label>
                  <input type="text" name="shipping_province" class="form-control" maxlength="80">
                </div>
                <div class="col-md-2">
                  <label class="form-label">Postal</label>
                  <input type="text" name="shipping_postal" class="form-control" maxlength="20">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Billing name</label>
                  <input type="text" name="billing_name" class="form-control" maxlength="120"
                         value="<?= e(Auth::user()['full_name'] ?? '') ?>">
                  <div class="form-text">Name shown on the payment records.</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Billing phone</label>
                  <input type="text" name="billing_phone" class="form-control" maxlength="40">
                </div>
                <div class="col-12">
                  <label class="form-label">Delivery notes</label>
                  <textarea name="notes_member" class="form-control" rows="2" maxlength="500" placeholder="Landmarks, preferred times…"></textarea>
                </div>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-header"><span class="card-title">💳 Payment method</span></div>
            <div class="card-body">
              <?php foreach ($paymentMethods as $key => $label): ?>
                <div class="form-check mb-2">
                  <input class="form-check-input" type="radio" name="payment_method" id="pm_<?= e($key) ?>"
                         value="<?= e($key) ?>" <?= $key === 'ewallet' ? 'checked' : '' ?> required>
                  <label class="form-check-label" for="pm_<?= e($key) ?>"><?= e($label) ?>
                    <?php if ($key === 'ewallet'): ?>
                      <span class="text-muted" style="font-size:.78rem;">— balance <?= fmt_money(Ewallet::balance(Auth::id())) ?></span>
                    <?php endif; ?>
                  </label>
                </div>
              <?php endforeach; ?>
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
              <button type="submit" class="btn btn-primary w-100" id="placeOrderBtn">Place order</button>
              <a href="<?= link_to('cart') ?>" class="btn btn-link btn-sm w-100 mt-2" style="text-decoration:none;">← Back to cart</a>
            </div>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>
<script>
// Single-submit guard for the idempotency story: even without the server key,
// a double click must not fire two POSTs.
document.getElementById('placeOrderBtn')?.addEventListener('click', function () {
  if (this.dataset.busy) return;
  this.dataset.busy = '1';
});
</script>
<?php require 'views/partials/footer.php'; ?>
