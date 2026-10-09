<?php
/**
 * @file   views/admin/shop_orders.php
 * @brief  Admin fulfilment queue (plan §5.15): 14 status tabs + all, live
 *         counts, next-action hints from the matrix. Expects: $orders,
 *         $counts, $status, $actionable.
 */
$tabs = array_merge(['payment_review', 'payment_failed', 'paid', 'packing', 'ready_to_ship',
                     'shipped', 'out_for_delivery', 'delivery_failed', 'returned_to_sender',
                     'delivered', 'on_hold', 'pending', 'completed', 'cancelled'], ['all']);

/** Next-action hint per status, computed from the matrix (§5.15). */
function shop_next_action(string $status): ?string
{
    return match ($status) {
        'payment_review'     => 'Verify or reject the proof',
        'payment_failed'     => 'Waiting for buyer re-upload',
        'paid'               => 'Start packing',
        'packing'            => 'Mark ready to ship',
        'ready_to_ship'      => 'Hand off to the courier',
        'shipped'            => 'Track until delivery',
        'out_for_delivery'   => 'Awaiting POD',
        'delivery_failed'    => 'Retry or return to sender',
        'returned_to_sender' => 'Reship or resolve',
        'delivered'          => 'Awaiting receipt / window',
        'on_hold'            => 'Resolve the hold',
        default              => null,
    };
}
?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h4 class="mb-0">Shop Orders</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Verify payments, pick and pack, hand off to the courier.</p>
      </div>
      <form method="post" action="<?= link_to('admin_shop_expire') ?>" class="d-inline">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline-secondary btn-sm" title="Run the expiry + auto-complete legs now">⏱ Run expiry</button>
      </form>
    </div>

    <!-- Status tabs -->
    <ul class="nav nav-tabs flex-wrap mb-3" style="font-size:.82rem;">
      <?php foreach ($tabs as $t): ?>
        <li class="nav-item">
          <a class="nav-link <?= $status === $t ? 'active' : '' ?>" href="<?= link_to('admin_shop_orders', ['status' => $t]) ?>">
            <?= e(shop_status_label($t === 'all' ? 'completed' : $t) === 'Unknown' ? ucfirst($t) : ($t === 'all' ? 'All' : shop_status_label($t))) ?>
            <span class="badge bg-<?= $counts[$t] ?? 0 > 0 ? 'primary' : 'secondary' ?> ms-1"><?= $counts[$t] ?? 0 ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th style="padding-left:1.25rem;">Order #</th>
              <th>Buyer</th>
              <th>Placed</th>
              <th class="text-end">Total</th>
              <th>Method</th>
              <th>Status</th>
              <th>Next action</th>
              <th class="text-end" style="padding-right:1.25rem;"></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($orders['data'])): ?>
              <tr><td colspan="8" class="text-center py-5 text-muted">
                <div style="font-size:2rem;opacity:.3;">📦</div>
                <div class="mt-2">No orders<?= $status !== 'all' ? ' in ' . e(shop_status_label($status)) : '' ?>.</div>
              </td></tr>
            <?php else: foreach ($orders['data'] as $o):
              $buyer = User::find((int) $o['member_id']);
            ?>
              <tr>
                <td style="padding-left:1.25rem;" class="fw-semibold"><?= e(shop_public_order_no((int) $o['id'], (string) $o['created_at'])) ?></td>
                <td style="font-size:.82rem;">
                  <?= e('@' . ($buyer['username'] ?? $o['member_id'])) ?>
                  <?php if (Auth::id() === (int) $o['member_id']): ?>
                    <span class="badge bg-dark ms-1" title="Your own order — actions are refused">self</span>
                  <?php endif; ?>
                </td>
                <td class="text-muted" style="font-size:.78rem;"><?= e(fmt_datetime((string) $o['created_at'])) ?></td>
                <td class="text-end font-mono"><?= fmt_money((float) $o['total_price']) ?></td>
                <td class="text-muted" style="font-size:.78rem;"><?= e(strtoupper((string) $o['payment_method'])) ?></td>
                <td><?php $tmp = $o; require __DIR__ . '/../partials/order_status_badge.php'; ?></td>
                <td class="text-muted" style="font-size:.76rem;"><?= e(shop_next_action((string) $o['status']) ?? '—') ?></td>
                <td class="text-end" style="padding-right:1.25rem;">
                  <a href="<?= link_to('admin_shop_order', ['id' => $o['id']]) ?>" class="btn btn-sm btn-outline-primary">Open</a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($orders['total_pages'] > 1): ?>
        <div class="card-footer"><?= pagination_links($orders, link_to('admin_shop_orders', ['status' => $status, 'per_page' => per_page()])) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require 'views/partials/footer.php'; ?>
