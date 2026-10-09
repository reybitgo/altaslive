<?php
/**
 * @file   views/member/shop_orders.php
 * @brief  Buyer's order history (plan §5.10). Expects: $orders (paginate()).
 */
?>
<?php $pageTitle = 'My Orders'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h4 class="mb-0">My Orders</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Everything you have ordered from the shop.</p>
      </div>
      <a href="<?= link_to('shop') ?>" class="btn btn-outline-primary btn-sm">🛍️ Continue shopping</a>
    </div>

    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th style="padding-left:1.25rem;">Order #</th>
              <th>Placed</th>
              <th class="text-end">Total</th>
              <th>Method</th>
              <th>Status</th>
              <th class="text-end" style="padding-right:1.25rem;"></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($orders['data'])): ?>
              <tr><td colspan="6" class="text-center py-5 text-muted">
                <div style="font-size:2rem;opacity:.3;">🧾</div>
                <div class="mt-2">No orders yet.</div>
                <a href="<?= link_to('shop') ?>" class="btn btn-primary btn-sm mt-2">Visit the Shop</a>
              </td></tr>
            <?php else: foreach ($orders['data'] as $o):
              $o['created_at'] = $o['created_at'] ?? null; ?>
              <tr>
                <td style="padding-left:1.25rem;" class="fw-semibold"><?= e(shop_public_order_no((int) $o['id'], (string) $o['created_at'])) ?></td>
                <td class="text-muted" style="font-size:.8rem;"><?= e(fmt_datetime((string) $o['created_at'])) ?></td>
                <td class="text-end font-mono"><?= fmt_money((float) $o['total_price']) ?></td>
                <td class="text-muted" style="font-size:.8rem;"><?= e(strtoupper((string) $o['payment_method'])) ?></td>
                <td><?php require __DIR__ . '/../partials/order_status_badge.php'; ?></td>
                <td class="text-end" style="padding-right:1.25rem;">
                  <?php if (ShopOrder::canMemberAct($o, 'proof')): ?>
                    <a href="<?= link_to('shop_order', ['id' => $o['id']]) ?>" class="btn btn-sm btn-warning">Upload proof</a>
                  <?php endif; ?>
                  <a href="<?= link_to('shop_order', ['id' => $o['id']]) ?>" class="btn btn-sm btn-outline-primary">View</a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($orders['total_pages'] > 1): ?>
        <div class="card-footer"><?= pagination_links($orders, link_to('shop_orders', ['per_page' => per_page()])) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require 'views/partials/footer.php'; ?>
