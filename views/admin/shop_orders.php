<?php
/**
 * @file   views/admin/shop_orders.php
 * @brief  Admin fulfilment queue (plan §5.15): 14 status tabs + all, live
 *         counts, next-action hints from the matrix. Expects: $orders,
 *         $counts, $status.
 */
/* Tab order follows the order-state sequence (ShopOrder::STATUSES): an order
   moves pending → payment_review → paid → … → delivered → completed/cancelled,
   so the queue reads left-to-right in the same lifecycle order rather than in
   whatever order the statuses happened to be listed. The model constant is
   the single source of truth, so adding a status there re-orders the tabs
   automatically without touching this view. */
$tabs = array_merge(ShopOrder::STATUSES, ['all']);
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

    <!-- Status tabs. Phase 8.6: these navigate by URL rather than swapping
         panels, so they are links inside a nav, not ARIA tabs. role="tablist"
         would promise tabpanels that do not exist and would break keyboard
         arrow-key navigation. aria-current="page" is what actually tells a
         screen reader which one is selected. -->
    <ul class="nav nav-tabs flex-wrap mb-3" style="font-size:.82rem;">
      <?php foreach ($tabs as $t): ?>
        <li class="nav-item">
          <a class="nav-link <?= $status === $t ? 'active' : '' ?>"
             href="<?= link_to('admin_shop_orders', ['status' => $t]) ?>"
             <?= $status === $t ? 'aria-current="page"' : '' ?>>
            <?= $t === 'all' ? 'All' : e(shop_status_label($t)) ?>
            <span class="badge bg-<?= ($counts[$t] ?? 0) > 0 ? 'primary' : 'secondary' ?> ms-1"><?= $counts[$t] ?? 0 ?></span>
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
                <td style="padding-left:1.25rem;" class="fw-semibold"><?= e($o['order_no']) ?></td>
                <td style="font-size:.82rem;">
                  <?= e('@' . ($buyer['username'] ?? $o['member_id'])) ?>
                  <?php if (Auth::id() === (int) $o['member_id']): ?>
                    <span class="badge bg-dark ms-1" title="Your own order — actions are refused">self</span>
                  <?php endif; ?>
                </td>
                <td class="text-muted" style="font-size:.78rem;"><?= e(fmt_datetime((string) $o['created_at'])) ?></td>
                <td class="text-end font-mono"><?= fmt_money((float) $o['total_price']) ?></td>
                <td class="text-muted" style="font-size:.78rem;"><?= e(strtoupper((string) $o['payment_method'])) ?></td>
                <td><?php
                  $badgeStatus = (string) $o['status'];
                  $badgeStamp  = $o['created_at'] ?? null;
                  require __DIR__ . '/../partials/order_status_badge.php';
                ?></td>
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
