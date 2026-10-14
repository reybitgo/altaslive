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

    <?php
    // Anything the buyer typed on a failed attempt comes back with them (see
    // shop_checkout_old_input()). On a fresh checkout ($old empty) we prefill
    // from the member's default saved address, falling back to the profile.
    $old = shop_checkout_old_input();
    $user = Auth::user();

    $defaultAddr = null;
    foreach ($addresses as $a) {
        if ((int) $a['is_default'] === 1) { $defaultAddr = $a; break; }
    }
    if (!$defaultAddr && !empty($addresses)) { $defaultAddr = $addresses[0]; }

    $fromAddr = empty($old) ? [
        'shipping_name'     => $defaultAddr['recipient_name'] ?? '',
        'shipping_phone'    => $defaultAddr['phone'] ?? '',
        'shipping_address'  => $defaultAddr['address_line'] ?? '',
        'shipping_city'     => $defaultAddr['city'] ?? '',
        'shipping_province' => $defaultAddr['province'] ?? '',
        'shipping_postal'   => $defaultAddr['postal'] ?? '',
    ] : [];

    $pick = static function (string $key, string $fallback = '') use ($old, $fromAddr): string {
        if (isset($old[$key]) && $old[$key] !== '') return (string) $old[$key];
        if (isset($fromAddr[$key]) && $fromAddr[$key] !== '') return (string) $fromAddr[$key];
        return $fallback;
    };
    ?>

    <form method="post" action="<?= link_to('place_order') ?>" id="checkoutForm">
      <?= csrf_field() ?>
      <input type="hidden" name="idem" value="<?= e(uniqid('', true)) ?>">

      <div class="row g-3">
        <!-- ── Left: address + payment ── -->
        <div class="col-lg-7">
          <div class="card mb-3">
            <div class="card-header"><span class="card-title">📍 Delivery details</span></div>
            <div class="card-body">
              <?php if (!empty($addresses)): ?>
                <div class="mb-3">
                  <label class="form-label" for="savedAddress">Pick a saved address</label>
                  <select id="savedAddress" class="form-select">
                    <option value="">— Fill in below —</option>
                    <?php foreach ($addresses as $a): ?>
                      <option value="<?= (int) $a['id'] ?>"
                              data-name="<?= e($a['recipient_name']) ?>"
                              data-phone="<?= e($a['phone']) ?>"
                              data-address="<?= e($a['address_line']) ?>"
                              data-city="<?= e($a['city']) ?>"
                              data-province="<?= e($a['province']) ?>"
                              data-postal="<?= e($a['postal']) ?>">
                        <?= e((!empty($a['label']) ? $a['label'] . ' — ' : '') . $a['recipient_name'] . ' · ' . $a['address_line']) ?><?= (int) $a['is_default'] === 1 ? ' (default)' : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="form-text">Selecting one fills the fields below — you can still edit them.</div>
                </div>
              <?php endif; ?>
              <div class="row g-2">
                <div class="col-md-6">
                  <label class="form-label" for="shipping_name">Recipient name *</label>
                  <input type="text" id="shipping_name" name="shipping_name" class="form-control" maxlength="120" required
                         value="<?= e($pick('shipping_name', $user['full_name'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="shipping_phone">Recipient phone</label>
                  <input type="text" id="shipping_phone" name="shipping_phone" class="form-control" maxlength="40"
                         value="<?= e($pick('shipping_phone', $user['mobile'] ?? '')) ?>">
                </div>
                <div class="col-12">
                  <label class="form-label" for="shipping_address">Delivery address *</label>
                  <input type="text" id="shipping_address" name="shipping_address" class="form-control" maxlength="255" placeholder="House / street / barangay" required value="<?= e($pick('shipping_address')) ?>">
                </div>
                <div class="col-md-5">
                  <label class="form-label" for="shipping_city">City</label>
                  <input type="text" id="shipping_city" name="shipping_city" class="form-control" maxlength="80" value="<?= e($pick('shipping_city')) ?>">
                </div>
                <div class="col-md-5">
                  <label class="form-label" for="shipping_province">Province</label>
                  <input type="text" id="shipping_province" name="shipping_province" class="form-control" maxlength="80" value="<?= e($pick('shipping_province')) ?>">
                </div>
                <div class="col-md-2">
                  <label class="form-label" for="shipping_postal">Postal</label>
                  <input type="text" id="shipping_postal" name="shipping_postal" class="form-control" maxlength="20" value="<?= e($pick('shipping_postal')) ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="billing_name">Billing name *</label>
                  <input type="text" id="billing_name" name="billing_name" class="form-control" maxlength="120" required
                         value="<?= e($pick('billing_name', $user['full_name'] ?? '')) ?>">
                  <div class="form-text">Name shown on the payment records.</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="billing_phone">Billing phone</label>
                  <input type="text" id="billing_phone" name="billing_phone" class="form-control" maxlength="40" value="<?= e($pick('billing_phone')) ?>">
                </div>
                <div class="col-12">
                  <label class="form-label" for="notes_member">Delivery notes</label>
                  <textarea id="notes_member" name="notes_member" class="form-control" rows="2" maxlength="500" placeholder="Landmarks, preferred times…"><?= e($old['notes_member'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                  <div class="form-check">
                    <?php /* Pre-ticked only when the member has no saved addresses yet. */ ?>
                    <input class="form-check-input" type="checkbox" name="save_address" id="save_address" value="1" <?= empty($addresses) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="save_address" style="font-size:.85rem;">
                      Save this address to my address book for next time.
                    </label>
                  </div>
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
              $ewalletEnough  = $ewalletBalance + 0.005 >= (float) $totals['total_price'];
              // ($old is read at the top of the file.)
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
              <?php /* The button is disabled until the terms checkbox is ticked.
                       admin-checked server-side too (placeOrder() refuses
                       without terms=1), this is the UI convenience that stops
                       the member pressing an order they haven't agreed to. JS
                       below binds the checkbox to this button's disabled state. */ ?>
              <button type="submit" class="btn btn-primary w-100" id="placeOrderBtn" data-busy-label="Placing order…" disabled
                      data-terms-label="Tick the terms checkbox to place your order"
                      aria-describedby="terms">Place order</button>
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

  // Terms gate (M8/bis): Place order stays disabled until the checkbox is
  // ticked. placeOrder() still refuses server-side without terms=1, so this
  // is a convenience, not the authority. The page (re)renders with the button
  // already disabled (previous failed attempts come back un-ticked), and a
  // BFCache restore re-derives the state from the checkbox below.
  var terms = document.getElementById('terms');
  var termsLabelText = 'Place order';
  var termsHintText = btn.dataset.termsLabel || 'Tick the terms checkbox to place your order';
  function syncTermsGate() {
    var ok = terms && terms.checked;
    btn.disabled = !ok || sent;
    btn.textContent = ok || sent ? termsLabelText : termsHintText;
  }
  if (terms) {
    terms.addEventListener('change', syncTermsGate);
    syncTermsGate();
  }

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
    if (!terms || !terms.checked) {
      // Belt and braces: a form submit can be scripted without the checkbox,
      // so refuse it here too rather than relying on the disabled attribute.
      e.preventDefault();
      syncTermsGate();
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
    syncTermsGate();   // re-derives disabled + label from the checkbox state
  });
})();

// Saved-address selector: picking one fills the delivery fields below. The
// member can keep editing after; this is a shortcut, not a lock.
(function () {
  var sel = document.getElementById('savedAddress');
  if (!sel) return;
  var map = {
    name:     'shipping_name',
    phone:    'shipping_phone',
    address:  'shipping_address',
    city:     'shipping_city',
    province: 'shipping_province',
    postal:   'shipping_postal'
  };
  sel.addEventListener('change', function () {
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) return;
    Object.keys(map).forEach(function (k) {
      var el = document.getElementById(map[k]);
      if (el) el.value = opt.dataset[k] || '';
    });
  });
})();
</script>
<?php require 'views/partials/footer.php'; ?>
