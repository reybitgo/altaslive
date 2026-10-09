<?php
/**
 * @file   views/admin/shop_products.php
 * @brief  Product catalog management (plan §5.14). Expects: $products
 *         (paginate()), $editProduct (?array), $pageTitle set in controller.
 */
?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h4 class="mb-0">Shop Products</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Manage the storefront catalog.</p>
      </div>
      <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal">+ New Product</button>
    </div>

    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th style="padding-left:1.25rem;">Product</th>
              <th class="text-end">Price</th>
              <th class="text-center">Stock</th>
              <th class="text-center">Reserved</th>
              <th class="text-center">Available</th>
              <th class="text-center">Status</th>
              <th class="text-end" style="padding-right:1.25rem;width:160px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($products['data'])): ?>
              <tr><td colspan="7" class="text-center py-5 text-muted">
                <div style="font-size:2rem;opacity:.3;">🛍️</div>
                <div class="mt-2">No products yet. Click <strong>+ New Product</strong> to create the first one.</div>
              </td></tr>
            <?php else: foreach ($products['data'] as $p):
              $reserved = Product::reservedStock((int) $p['id']);
              $available = max(0, (int) $p['stock'] - $reserved);
            ?>
              <tr>
                <td style="padding-left:1.25rem;">
                  <div class="d-flex align-items-center gap-2">
                    <?php if (!empty($p['image_url'])): ?>
                      <img src="<?= APP_URL ?>/uploads/<?= e($p['image_url']) ?>" alt="" style="width:38px;height:38px;object-fit:cover;border-radius:.4rem;">
                    <?php else: ?>
                      <div style="width:38px;height:38px;border-radius:.4rem;background:#f4f6fb;display:flex;align-items:center;justify-content:center;">🛍️</div>
                    <?php endif; ?>
                    <div>
                      <div class="fw-semibold"><?= e($p['name']) ?></div>
                      <?php if (!empty($p['sku'])): ?><div class="text-muted" style="font-size:.7rem;"><?= e($p['sku']) ?></div><?php endif; ?>
                    </div>
                  </div>
                </td>
                <td class="text-end font-mono"><?= fmt_money((float) $p['price']) ?></td>
                <td class="text-center font-mono"><?= (int) $p['stock'] ?></td>
                <td class="text-center font-mono text-warning" title="Held by active orders"><?= $reserved ?></td>
                <td class="text-center font-mono <?= $available <= 0 ? 'text-danger fw-bold' : 'text-success' ?>"><?= $available ?></td>
                <td class="text-center">
                  <?= $p['status'] === 'active'
                      ? '<span class="badge bg-success-subtle text-success">● Active</span>'
                      : '<span class="badge bg-secondary-subtle text-secondary">○ Inactive</span>' ?>
                </td>
                <td class="text-end" style="padding-right:1.25rem;">
                  <a href="<?= link_to('admin_shop', ['edit' => $p['id']]) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                  <form method="post" action="<?= link_to('admin_delete_product') ?>" class="d-inline"
                        onsubmit="return confirm('Delete this product?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($products['total_pages'] > 1): ?>
        <div class="card-footer"><?= pagination_links($products, link_to('admin_shop', ['per_page' => per_page()])) ?></div>
      <?php endif; ?>
    </div>

    <div class="text-muted mt-2" style="font-size:.75rem;">
      ℹ️ Stock is absolute inventory. It is deducted automatically when an order is handed to the courier;
      adjust it here for deliveries, write-offs, and recounts.
    </div>
  </div>
</div>

<!-- ═══ PRODUCT MODAL (create / edit) ═══ -->
<div class="modal fade" id="productModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="<?= link_to('admin_save_product') ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="product_id" value="<?= e($editProduct['id'] ?? '') ?>">
        <div class="modal-header">
          <h5 class="modal-title"><?= ($editProduct ?? null) ? '✏️ Edit Product' : '➕ New Product' ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Name *</label>
              <input type="text" name="name" class="form-control" maxlength="160" value="<?= e($editProduct['name'] ?? '') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">SKU</label>
              <input type="text" name="sku" class="form-control" maxlength="60" value="<?= e($editProduct['sku'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Price (₱) *</label>
              <input type="number" name="price" class="form-control" min="0" step="0.01" value="<?= e($editProduct['price'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Stock</label>
              <input type="number" name="stock" class="form-control" min="0" value="<?= e($editProduct['stock'] ?? 0) ?>">
              <div class="form-text">Absolute inventory — deducted at courier handoff.</div>
            </div>
            <div class="col-12">
              <label class="form-label">Short description</label>
              <input type="text" name="short_description" class="form-control" maxlength="255" value="<?= e($editProduct['short_description'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Full description</label>
              <textarea name="description" class="form-control" rows="4"><?= e($editProduct['description'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Image</label>
              <?php if (!empty($editProduct['image_url'])): ?>
                <div class="mb-2">
                  <img src="<?= APP_URL ?>/uploads/<?= e($editProduct['image_url']) ?>" alt="" style="width:72px;height:72px;object-fit:cover;border-radius:.5rem;">
                  <div class="form-check mt-1">
                    <input class="form-check-input" type="checkbox" name="remove_image" id="removeImage" value="1">
                    <label class="form-check-label" for="removeImage" style="font-size:.82rem;">Remove current image</label>
                  </div>
                </div>
              <?php endif; ?>
              <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
              <div class="form-text">JPG, PNG, GIF or WebP · max 5 MB<?= ($editProduct ?? null) ? ' · leave empty to keep the current image' : '' ?>.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="active" <?= (($editProduct['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>🟢 Active</option>
                <option value="inactive" <?= (($editProduct['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>⚪ Inactive</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <a href="<?= link_to('admin_shop') ?>" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</a>
          <button type="submit" class="btn btn-primary"><?= ($editProduct ?? null) ? '💾 Update Product' : '➕ Create Product' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php if ($editProduct ?? null): ?>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    new bootstrap.Modal(document.getElementById('productModal')).show();
  });
</script>
<?php endif; ?>
<?php require 'views/partials/footer.php'; ?>
