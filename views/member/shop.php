<?php
/**
 * @file   views/member/shop.php
 * @brief  Storefront catalog (plan §5.9). Expects: $products, $cartCount.
 */
?>
<?php $pageTitle = 'Shop'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h4 class="mb-0">Shop</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Browse our products and order for delivery.</p>
      </div>
      <a href="<?= link_to('cart') ?>" class="btn btn-outline-primary">🛒 View Cart
        <?php if ($cartCount > 0): ?><span class="badge bg-primary ms-1"><?= $cartCount ?></span><?php endif; ?>
      </a>
    </div>

    <?php if (empty($products)): ?>
      <div class="card"><div class="card-body text-center py-5 text-muted">
        <div style="font-size:2.5rem;opacity:.35;">🛍️</div>
        <div class="mt-2">No products are available right now. Check back soon.</div>
      </div></div>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach ($products as $p):
          $img     = !empty($p['image_url']) ? APP_URL . '/uploads/' . e($p['image_url']) : null;
          $avail   = Product::availableStock((int) $p['id']);
          $soldOut = $avail <= 0;
        ?>
          <div class="col-6 col-md-4 col-lg-3">
            <div class="card h-100 d-flex flex-column">
              <?php if ($img): ?>
                <img src="<?= $img ?>" alt="<?= e($p['name']) ?>" class="card-img-top" style="height:170px;object-fit:cover;">
              <?php else: ?>
                <div class="card-img-top d-flex align-items-center justify-content-center text-muted" style="height:170px;background:#f4f6fb;font-size:2.5rem;">🛍️</div>
              <?php endif; ?>
              <div class="card-body d-flex flex-column">
                <h6 class="mb-1"><?= e($p['name']) ?></h6>
                <?php if (!empty($p['short_description'])): ?>
                  <p class="text-muted mb-2" style="font-size:.78rem;"><?= e($p['short_description']) ?></p>
                <?php endif; ?>
                <div class="mt-auto">
                  <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><?= fmt_money((float) $p['price']) ?></span>
                    <?php if ($soldOut): ?>
                      <span class="badge bg-secondary">Sold out</span>
                    <?php elseif ($avail <= 5): ?>
                      <span class="badge bg-warning text-dark"><?= $avail ?> left</span>
                    <?php else: ?>
                      <span class="badge bg-success-subtle text-success">In stock</span>
                    <?php endif; ?>
                  </div>
                  <?php if (!$soldOut): ?>
                    <form method="post" action="<?= link_to('add_to_cart') ?>" class="mt-2">
                      <?= csrf_field() ?>
                      <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                      <div class="input-group input-group-sm">
                        <input type="number" name="quantity" class="form-control text-center" value="1" min="1" max="<?= $avail ?>" style="max-width:70px;">
                        <button type="submit" class="btn btn-primary">Add</button>
                      </div>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Product description modal (single shared instance) -->
      <div class="modal fade" id="productDescModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="productDescTitle"></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="productDescBody"></div>
          </div>
        </div>
      </div>
      <?php if (!empty($products)): ?>
      <script>
        document.addEventListener('DOMContentLoaded', function () {
          var data = <?= json_encode(array_map(function ($p) {
              return ['id' => (int) $p['id'], 'name' => $p['name'], 'desc' => (string) ($p['description'] ?? '')];
          }, $products), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
          document.querySelectorAll('[data-desc-product]').forEach(function (btn) {
            btn.addEventListener('click', function () {
              var p = data.find(function (x) { return x.id === parseInt(btn.dataset.descProduct, 10); });
              if (!p) return;
              document.getElementById('productDescTitle').textContent = p.name;
              document.getElementById('productDescBody').textContent = p.desc || 'No description yet.';
              new bootstrap.Modal(document.getElementById('productDescModal')).show();
            });
          });
        });
      </script>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php require 'views/partials/footer.php'; ?>
