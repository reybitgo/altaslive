<?php
/**
 * @file   views/member/shop_order.php
 * @brief  The buyer's order page (plan §5.11). Exactly one status-specific
 *         panel renders. Expects: $order, $events, $shipments, $activeShipment,
 *         $proofs, $refunds.
 */
$orderNo = shop_public_order_no((int) $order['id'], (string) $order['created_at']);
$status  = (string) $order['status'];
$deadline = $status === 'pending' ? $order['payment_deadline']
          : ($status === 'payment_failed' ? $order['correction_deadline'] : null);
$overdue = $deadline && strtotime((string) $deadline) < time();
?>
<?php $pageTitle = 'Order ' . $orderNo; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h4 class="mb-0">Order <?= e($orderNo) ?></h4>
        <div class="text-muted" style="font-size:.8rem;">Placed <?= e(fmt_datetime((string) $order['created_at'])) ?></div>
      </div>
      <div class="text-end">
        <?= shop_status_badge($status, null) ?>
        <div class="fw-bold mt-1"><?= fmt_money((float) $order['total_price']) ?></div>
        <div class="text-muted" style="font-size:.74rem;">via <?= e(strtoupper((string) $order['payment_method'])) ?></div>
      </div>
    </div>

    <div class="row g-3">
      <!-- ── Left: status panel + items ── -->
      <div class="col-lg-8">
        <div class="card mb-3">
          <div class="card-header"><span class="card-title">What's happening</span></div>
          <div class="card-body">
            <?php if ($status === 'pending'): ?>
              <h6>Pay for your order</h6>
              <p class="text-muted" style="font-size:.85rem;">Send <?= fmt_money((float) $order['total_price']) ?> using your chosen method, then upload the proof below. Submitting proof ends your option to cancel.</p>
              <?php if ($deadline): ?>
                <p class="<?= $overdue ? 'text-danger fw-bold' : 'text-muted' ?>" style="font-size:.8rem;">
                  <?= $overdue ? '⚠ Payment window has closed' : 'Pay by ' . e(fmt_datetime((string) $deadline)) ?>
                </p>
              <?php endif; ?>
              <?php if (ShopOrder::canMemberAct($order, 'proof') && !$overdue): ?>
                <form method="post" action="<?= link_to('shop_submit_proof') ?>" enctype="multipart/form-data" style="max-width:480px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                  <div class="mb-2"><label class="form-label">Proof image *</label><input type="file" name="proof_image" class="form-control" accept="image/*" required></div>
                  <div class="mb-2"><label class="form-label">Reference no.</label><input type="text" name="reference_no" class="form-control" maxlength="40"></div>
                  <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label">Amount sent</label><input type="number" name="amount_sent" class="form-control" step="0.01" value="<?= number_format((float) $order['total_price'], 2, '.', '') ?>"></div>
                    <div class="col-6"><label class="form-label">Transfer date</label><input type="date" name="transfer_date" class="form-control"></div>
                  </div>
                  <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" id="confirmLock" name="confirm_lock" value="1" required>
                    <label class="form-check-label" for="confirmLock" style="font-size:.82rem;">I understand submitting this proof ends my option to cancel this order.</label>
                  </div>
                  <button type="submit" class="btn btn-primary">Submit proof</button>
                </form>
              <?php elseif ($overdue): ?>
                <p class="text-muted" style="font-size:.85rem;">The payment window has closed. Contact support if you already sent the payment.</p>
              <?php endif; ?>

            <?php elseif ($status === 'payment_review'): ?>
              <h6>We're verifying your payment</h6>
              <p class="text-muted mb-0" style="font-size:.85rem;">Our staff check proofs within about <?= ShopOrder::SLA_REVIEW_HOURS ?> hours. You'll see the result on this page.</p>

            <?php elseif ($status === 'payment_failed'): ?>
              <h6 class="text-danger">Payment needs correction</h6>
              <?php $latest = $proofs ? end($proofs) : null; ?>
              <?php if ($latest && !empty($latest['reject_reason'])): ?>
                <p class="mb-1" style="font-size:.85rem;">Reason: <strong><?= e(str_replace('_', ' ', (string) $latest['reject_reason'])) ?></strong></p>
              <?php endif; ?>
              <?php if ($order['correction_deadline']): ?>
                <p class="text-muted" style="font-size:.8rem;">Re-upload by <strong><?= e(fmt_datetime((string) $order['correction_deadline'])) ?></strong>.</p>
              <?php endif; ?>
              <?php if (ShopOrder::canMemberAct($order, 'proof')): ?>
                <form method="post" action="<?= link_to('shop_submit_proof') ?>" enctype="multipart/form-data" style="max-width:480px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                  <div class="mb-2"><label class="form-label">New proof image *</label><input type="file" name="proof_image" class="form-control" accept="image/*" required></div>
                  <div class="mb-2"><label class="form-label">Reference no.</label><input type="text" name="reference_no" class="form-control" maxlength="40"></div>
                  <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" id="confirmLock2" name="confirm_lock" value="1" required>
                    <label class="form-check-label" for="confirmLock2" style="font-size:.82rem;">Submitting this proof ends my option to cancel.</label>
                  </div>
                  <button type="submit" class="btn btn-primary">Re-upload proof</button>
                </form>
              <?php endif; ?>

            <?php elseif (in_array($status, ['paid', 'packing', 'ready_to_ship'], true)): ?>
              <h6>We're preparing your order</h6>
              <p class="text-muted mb-0" style="font-size:.85rem;">Payment verified — your items are being picked and packed. Tracking appears here once it's handed to the courier.</p>

            <?php elseif (in_array($status, ['shipped', 'out_for_delivery'], true)): ?>
              <h6><?= $status === 'shipped' ? 'Shipped' : 'Out for delivery' ?></h6>
              <?php if ($activeShipment): ?>
                <p class="mb-0" style="font-size:.9rem;">
                  <strong><?= e((string) $activeShipment['courier']) ?></strong>
                  <span class="font-mono ms-2"><?= e((string) $activeShipment['tracking_number']) ?></span>
                </p>
                <?php if (!empty($activeShipment['expected_delivery_at'])): ?>
                  <p class="text-muted mb-0" style="font-size:.8rem;">Expected <?= e(fmt_date((string) $activeShipment['expected_delivery_at'])) ?></p>
                <?php endif; ?>
              <?php endif; ?>

            <?php elseif ($status === 'delivery_failed'): ?>
              <h6 class="text-danger">A delivery attempt failed</h6>
              <?php if ($activeShipment): ?>
                <p class="mb-1" style="font-size:.85rem;">Reason: <strong><?= e(str_replace('_', ' ', (string) ($activeShipment['fail_reason'] ?? 'not stated'))) ?></strong></p>
                <p class="text-muted mb-0" style="font-size:.8rem;">Attempt <?= (int) $activeShipment['attempts'] ?> of <?= (int) setting('shop_max_delivery_attempts', '3') ?>. Please make sure someone is available at the delivery address.</p>
              <?php endif; ?>

            <?php elseif ($status === 'returned_to_sender'): ?>
              <h6>The parcel is coming back to us</h6>
              <p class="text-muted mb-0" style="font-size:.8rem;">We'll contact you about reshipping. Response window closes <?= !empty($activeShipment['response_deadline']) ? e(fmt_date((string) $activeShipment['response_deadline'])) : 'soon' ?>.</p>

            <?php elseif ($status === 'delivered'): ?>
              <h6 class="text-success">Delivered</h6>
              <?php if ($activeShipment && !empty($activeShipment['pod_receiver'])): ?>
                <p class="mb-1" style="font-size:.85rem;">Received by <strong><?= e((string) $activeShipment['pod_receiver']) ?></strong></p>
              <?php endif; ?>
              <p class="text-muted" style="font-size:.8rem;">Confirm receipt by <strong><?= e(fmt_date((string) ($order['completion_due_at'] ?? ''))) ?></strong> — confirming ends the reporting window.</p>
              <?php if (ShopOrder::canMemberAct($order, 'confirm')): ?>
                <form method="post" action="<?= link_to('shop_confirm_receipt') ?>" onsubmit="return confirm('Confirm that you received this order? This ends the reporting window.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                  <button type="submit" class="btn btn-success">✅ Confirm receipt</button>
                </form>
              <?php endif; ?>

            <?php elseif ($status === 'on_hold'): ?>
              <h6>Order on hold</h6>
              <p class="text-muted mb-0" style="font-size:.85rem;">
                Reason: <?= e(str_replace('_', ' ', (string) ($order['hold_reason'] ?? 'under review'))) ?>.
                <?php if ($order['hold_deadline']): ?>We'll update you by <?= e(fmt_datetime((string) $order['hold_deadline'])) ?>.<?php endif; ?>
              </p>

            <?php elseif ($status === 'completed'): ?>
              <h6 class="text-success">Order complete</h6>
              <p class="text-muted mb-0" style="font-size:.85rem;">Thank you for shopping with us. This order is closed.</p>

            <?php elseif ($status === 'cancelled'): ?>
              <h6>Order cancelled</h6>
              <p class="text-muted mb-0" style="font-size:.85rem;">
                Reason: <?= e(str_replace('_', ' ', (string) ($order['cancelled_reason'] ?? 'cancelled'))) ?>.
                <?php if ($refunds): ?>A refund of <?= fmt_money((float) $refunds[0]['amount']) ?> is being processed (status: <?= e((string) $refunds[0]['status']) ?>).<?php endif; ?>
              </p>
            <?php endif; ?>
          </div>
        </div>

        <!-- Items -->
        <div class="card mb-3">
          <div class="card-header"><span class="card-title">Items</span></div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr><th style="padding-left:1.25rem;">Product</th><th class="text-end">Price</th><th class="text-center">Qty</th><th class="text-end" style="padding-right:1.25rem;">Total</th></tr></thead>
              <tbody>
                <?php foreach ($order['items'] as $it): ?>
                  <tr>
                    <td style="padding-left:1.25rem;">
                      <?= e((string) $it['product_name']) ?>
                      <?php if (!empty($it['product_sku'])): ?><span class="text-muted" style="font-size:.7rem;"> · <?= e((string) $it['product_sku']) ?></span><?php endif; ?>
                    </td>
                    <td class="text-end font-mono"><?= fmt_money((float) $it['unit_price']) ?></td>
                    <td class="text-center"><?= (int) $it['quantity'] ?></td>
                    <td class="text-end font-mono"><?= fmt_money((float) $it['total_price']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Cancel (own panel; only while the matrix allows it) -->
        <?php if (ShopOrder::canMemberAct($order, 'cancel')): ?>
          <div class="card border-danger-subtle">
            <div class="card-body d-flex justify-content-between align-items-center">
              <div style="font-size:.85rem;">Changed your mind? You can still cancel — stock goes back on sale immediately.</div>
              <form method="post" action="<?= link_to('shop_cancel_order') ?>" onsubmit="return confirm('Cancel this order?');">
                <?= csrf_field() ?>
                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm">Cancel order</button>
              </form>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- ── Right: timeline, shipment, address, proofs ── -->
      <div class="col-lg-4">
        <div class="card mb-3">
          <div class="card-header"><span class="card-title">History</span></div>
          <div class="card-body"><?php require __DIR__ . '/../partials/shop_order_timeline.php'; ?></div>
        </div>

        <div class="card mb-3">
          <div class="card-header"><span class="card-title">Delivery address</span></div>
          <div class="card-body" style="font-size:.85rem;">
            <div class="fw-semibold"><?= e((string) ($order['shipping_name'] ?? '')) ?></div>
            <div><?= e((string) ($order['shipping_address'] ?? '')) ?></div>
            <div><?= e(trim((string) ($order['shipping_city'] ?? '') . ' ' . (string) ($order['shipping_province'] ?? '') . ' ' . (string) ($order['shipping_postal'] ?? ''))) ?></div>
            <?php if (!empty($order['shipping_phone'])): ?><div class="text-muted"><?= e((string) $order['shipping_phone']) ?></div><?php endif; ?>
          </div>
        </div>

        <?php if ($proofs): ?>
          <div class="card mb-3">
            <div class="card-header"><span class="card-title">Payment proofs</span></div>
            <div class="card-body">
              <?php foreach ($proofs as $pf): ?>
                <div class="d-flex justify-content-between align-items-center border-bottom py-1" style="font-size:.8rem;">
                  <span>Attempt #<?= (int) $pf['attempt_no'] ?></span>
                  <span class="badge bg-<?= ['pending' => 'warning', 'verified' => 'success', 'rejected' => 'danger'][$pf['status']] ?? 'secondary' ?>"><?= e((string) $pf['status']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($refunds): ?>
          <div class="card">
            <div class="card-header"><span class="card-title">Refunds</span></div>
            <div class="card-body" style="font-size:.85rem;">
              <?php foreach ($refunds as $rf): ?>
                <div class="d-flex justify-content-between border-bottom py-1">
                  <span><?= fmt_money((float) $rf['amount']) ?></span>
                  <span class="badge bg-secondary"><?= e((string) $rf['status']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require 'views/partials/footer.php'; ?>
