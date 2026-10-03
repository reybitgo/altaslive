<?php

/**
 * @file   views/admin/products.php
 * @brief  Product catalog management (Phase 5)
 */
?>
<?php $pageTitle = 'Products'; ?>
<?php require 'views/partials/head.php'; ?>
<style>
  .product-thumb-sm {
    width: 48px;
    height: 48px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
  }
  .product-thumb-placeholder {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #f8fafc;
    font-size: 1.25rem;
  }
  .product-thumb-md {
    width: 96px;
    height: 96px;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
  }
</style>
<?php require 'views/partials/sidebar_admin.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h4 class="mb-0">Products</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Manage repeat-purchase products and their PV values</p>
      </div>
      <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal" onclick="resetProductForm()">
        + New Product
      </button>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="card-title">🛍️ Products</span>
        <?php require 'views/partials/rows_per_page.php'; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead style="background:#f8fafc;">
            <tr>
              <th style="padding-left:1.25rem;width:70px;">Image</th>
              <th>Product</th>
              <th class="text-end">Price</th>
              <th class="text-end">Product PV</th>
              <th class="text-end">PV %</th>
              <th class="text-end">Eff. PV</th>
              <th class="text-center">Stock</th>
              <th class="text-center">Status</th>
              <th class="text-end" style="padding-right:1.25rem;width:120px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($products['data'])): ?>
              <tr>
                <td colspan="9" class="text-center py-5 text-muted">
                  <div style="font-size:2rem;opacity:.3;margin-bottom:.5rem;">🛍️</div>
                  <div>No products yet.</div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($products['data'] as $p): ?>
                <tr>
                  <td style="padding-left:1.25rem;">
                    <?php if (!empty($p['image_url'])): ?>
                      <a href="<?= APP_URL ?>/uploads/<?= e($p['image_url']) ?>" target="_blank" rel="noopener">
                        <img src="<?= APP_URL ?>/uploads/<?= e($p['image_url']) ?>" alt="<?= e($p['name']) ?>" class="product-thumb-sm" loading="lazy">
                      </a>
                    <?php else: ?>
                      <div class="product-thumb-sm product-thumb-placeholder">🛍️</div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="fw-semibold"><?= e($p['name']) ?></div>
                    <div class="text-muted" style="font-size:.7rem;">ID: <?= (int)$p['id'] ?></div>
                  </td>
                  <td class="text-end font-mono"><?= fmt_money($p['price']) ?></td>
                  <td class="text-end font-mono"><?= number_format((float)$p['product_pv'], 2) ?></td>
                  <td class="text-end font-mono"><?= number_format((float)$p['pv_value'], 2) ?>%</td>
                  <td class="text-end font-mono"><?= number_format((float)$p['product_pv'] * (float)$p['pv_value'] / 100, 2) ?></td>
                  <td class="text-center font-mono">
                    <?php $avail = Product::availableStock((int)$p['id']); ?>
                    <span class="badge bg-<?= $avail > 0 ? 'success' : ($avail === 0 && (int)$p['stock'] > 0 ? 'warning' : 'secondary') ?>-subtle text-<?= $avail > 0 ? 'success' : ($avail === 0 && (int)$p['stock'] > 0 ? 'warning' : 'secondary') ?>" style="font-size:.72rem;">
                      <?= $avail ?> / <?= (int)$p['stock'] ?>
                    </span>
                    <div class="small text-muted" style="font-size:.65rem;">available / total</div>
                  </td>
                  <td class="text-center">
                    <?php if ($p['status'] === 'active'): ?>
                      <span class="badge bg-success-subtle text-success" style="font-size:.72rem;">● Active</span>
                    <?php else: ?>
                      <span class="badge bg-secondary-subtle text-secondary" style="font-size:.72rem;">○ Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td class="action-cell">
                    <div class="action-buttons">
                      <a href="<?= APP_URL ?>/?page=admin_products&edit=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                      <form method="POST" action="<?= APP_URL ?>/?page=admin_delete_product" onsubmit="return confirm('Delete this product?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Del</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if (!empty($products['total_pages']) && $products['total_pages'] > 1): ?>
        <div class="card-footer"><?= pagination_links($products, APP_URL . '/?page=admin_products&per_page=' . per_page()) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Product Modal -->
<div class="modal fade" id="productModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="productModalTitle">➕ New Product</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="POST" action="<?= APP_URL ?>/?page=admin_save_product" id="productForm" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="product_id" id="productId" value="<?= e($editProduct['id'] ?? '') ?>">

          <div class="mb-3">
            <label class="form-label">Product Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="prodName" class="form-control" value="<?= e($editProduct['name'] ?? '') ?>" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Short Description</label>
            <textarea name="short_description" id="prodShortDesc" class="form-control" rows="2" maxlength="255" placeholder="Brief line shown on the product card"><?= e($editProduct['short_description'] ?? '') ?></textarea>
            <div class="form-text">Max 255 characters. Displayed on the member product card.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Full Description</label>
            <textarea name="description" id="prodDesc" class="form-control" rows="4" placeholder="Detailed description shown in the product popup"><?= e($editProduct['description'] ?? '') ?></textarea>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label">Price (₱) <span class="text-danger">*</span></label>
              <input type="number" name="price" id="prodPrice" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($editProduct['price'] ?? '') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Product PV <span class="text-danger">*</span></label>
              <input type="number" name="product_pv" id="prodProductPv" class="form-control" inputmode="decimal" min="0" step="0.01" value="<?= e($editProduct['product_pv'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">PV Value (%) <span class="text-danger">*</span></label>
              <input type="number" name="pv_value" id="prodPv" class="form-control" inputmode="decimal" min="0" max="100" step="0.01" value="<?= e($editProduct['pv_value'] ?? '100.00') ?>">
            </div>
          </div>
          <div class="mb-3">
            <div class="form-text">PV = Product PV × PV Value = <span id="prodPvPreview" class="font-mono">0.00PV ≈ ₱0.00</span></div>
          </div>

          <!-- Stock row -->
          <div class="mb-3">
            <label class="form-label">Stock <span class="text-danger">*</span></label>
            <div class="input-group" style="max-width:200px;">
              <button type="button" class="btn btn-outline-secondary" onclick="adjustStock(-1)">−</button>
              <input type="number" name="stock" id="prodStock" class="form-control text-center" inputmode="numeric" min="0" step="1" value="<?= (int)($editProduct['stock'] ?? 0) ?>" required>
              <button type="button" class="btn btn-outline-secondary" onclick="adjustStock(1)">+</button>
            </div>
            <div class="form-text">
              Total physical inventory. Orders reserve from this count but never modify it.
              Set to 0 to disallow purchases until restocked.
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" id="prodStatus" class="form-select">
              <option value="active" <?= (($editProduct['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>🟢 Active</option>
              <option value="inactive" <?= (($editProduct['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>⚪ Inactive</option>
            </select>
          </div>

          <?php if (setting('unilevel_product_enabled', '1') === '1'): ?>
          <hr class="my-3">
          <div class="mb-0">
            <label class="form-label fw-bold">📊 Unilevel Bonuses (10 Levels)</label>
            <div class="row g-2">
              <?php $ulvls = $editProduct['unilevel_levels'] ?? [];
              for ($lvl = 1; $lvl <= 10; $lvl++): ?>
                <div class="col-6 col-md-4">
                  <label class="form-label" style="font-size:.72rem;">Level <?= $lvl ?></label>
                  <div class="input-group input-group-sm">
                    <input type="number" name="unilevel_<?= $lvl ?>" id="unilevel_<?= $lvl ?>"
                           class="form-control" inputmode="decimal" min="0" max="100" step="0.01"
                           value="<?= e($ulvls[$lvl] ?? 0) ?>" placeholder="0.00">
                    <span class="input-group-text">%</span>
                  </div>
                </div>
              <?php endfor; ?>
            </div>
            <div class="form-text mt-1">
              Set 0 to disable a level. Percentages are applied to the product's effective PV.
              Each upline must also meet their package's Personal PV Requirement (PV Gate).
              Total unilevel ≈ <span id="unilevelPreview" class="font-mono">₱0.00</span>
            </div>
          </div>
          <hr class="my-3">
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Product Image</label>
            <input type="file" name="image" id="prodImage" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
            <div class="form-text">JPEG, PNG, GIF, WebP. Max 5 MB.</div>
            <div id="prodImagePreviewWrap" class="mt-2 <?= empty($editProduct['image_url']) ? 'd-none' : '' ?>">
              <div class="d-flex align-items-center gap-2">
                <a href="<?= APP_URL ?>/uploads/<?= e($editProduct['image_url'] ?? '') ?>" target="_blank" rel="noopener" id="prodImageLink">
                  <img id="prodImagePreview" src="<?= APP_URL ?>/uploads/<?= e($editProduct['image_url'] ?? '') ?>" alt="Preview" class="product-thumb-md">
                </a>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="remove_image" value="1" id="prodRemoveImage">
                  <label class="form-check-label" for="prodRemoveImage" style="font-size:.8rem;">Remove current image</label>
                </div>
              </div>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer" style="border-top:1px solid #f1f5f9;">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" form="productForm" id="prodSubmitBtn">
          <?= ($editProduct ?? null) ? '💾 Update Product' : '➕ Create Product' ?>
        </button>
      </div>
    </div>
  </div>
</div>

<script>
  function resetProductForm() {
    const form = document.getElementById('productForm');
    form.reset();

    // Explicitly clear fields so the form is blank even if the page was loaded in edit mode
    document.getElementById('prodName').value = '';
    document.getElementById('prodShortDesc').value = '';
    document.getElementById('prodDesc').value = '';
    document.getElementById('prodPrice').value = '';
    document.getElementById('prodProductPv').value = '';
    document.getElementById('prodPv').value = '100.00';
    document.getElementById('prodStatus').value = 'active';
    document.getElementById('prodStock').value = '0';
    document.getElementById('prodImage').value = '';
    document.getElementById('prodRemoveImage').checked = false;

    for (let lvl = 1; lvl <= 10; lvl++) {
      const el = document.getElementById('unilevel_' + lvl);
      if (el) el.value = '0';
    }

    document.getElementById('productModalTitle').textContent = '➕ New Product';
    document.getElementById('productId').value = '';
    document.getElementById('prodSubmitBtn').textContent = '➕ Create Product';
    const previewWrap = document.getElementById('prodImagePreviewWrap');
    if (previewWrap) {
      previewWrap.classList.add('d-none');
    }
  }

  function adjustStock(delta) {
    const input = document.getElementById('prodStock');
    let val = parseInt(input.value || '0', 10);
    val = Math.max(0, val + delta);
    input.value = val;
  }

  const prodImageInput = document.getElementById('prodImage');
  if (prodImageInput) {
    prodImageInput.addEventListener('change', function() {
      const file = this.files[0];
      const wrap = document.getElementById('prodImagePreviewWrap');
      const img = document.getElementById('prodImagePreview');
      const link = document.getElementById('prodImageLink');
      if (!file || !wrap || !img) return;
      const url = URL.createObjectURL(file);
      img.src = url;
      if (link) link.href = url;
      wrap.classList.remove('d-none');
    });
  }

  <?php if ($editProduct): ?>
  document.addEventListener('DOMContentLoaded', function() {
    const modalEl = document.getElementById('productModal');
    const modal = new bootstrap.Modal(modalEl);
    document.getElementById('productModalTitle').textContent = '✏️ Edit Product';
    document.getElementById('prodSubmitBtn').textContent = '💾 Update Product';
    modal.show();
    setTimeout(updateProductPvPreview, 50);
  });
  <?php endif; ?>

  // ── Product PV preview live update ──
  const prodPvInput = document.getElementById('prodProductPv');
  const prodPctInput = document.getElementById('prodPv');
  const prodPvPreviewEl = document.getElementById('prodPvPreview');
  const pvPerPesoRate = <?= (float)setting('pv_per_peso_rate', '1000.0000') ?>;

  function updateProductPvPreview() {
    const pv = parseFloat(prodPvInput?.value) || 0;
    const pct = parseFloat(prodPctInput?.value) || 0;
    const effPv = pv * (pct / 100);
    const peso = effPv * pvPerPesoRate;
    prodPvPreviewEl.textContent = effPv.toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    }) + 'PV ≈ ' + '₱' + peso.toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  if (prodPvInput)  prodPvInput.addEventListener('input', updateProductPvPreview);
  if (prodPctInput) prodPctInput.addEventListener('input', updateProductPvPreview);
  updateProductPvPreview();

  // ── Unilevel Bonus preview live update ──
  function updateUnilevelPreview() {
    const pv = parseFloat(prodPvInput?.value) || 0;
    const pct = parseFloat(prodPctInput?.value) || 0;
    const effPv = pv * (pct / 100);
    let totalPeso = 0;
    for (let lvl = 1; lvl <= 10; lvl++) {
      const input = document.getElementById('unilevel_' + lvl);
      const lvlPct = parseFloat(input?.value) || 0;
      totalPeso += effPv * (lvlPct / 100) * pvPerPesoRate;
    }
    const el = document.getElementById('unilevelPreview');
    if (el) {
      el.textContent = '₱' + totalPeso.toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    }
  }

  for (let lvl = 1; lvl <= 10; lvl++) {
    const el = document.getElementById('unilevel_' + lvl);
    if (el) el.addEventListener('input', updateUnilevelPreview);
  }
  if (prodPvInput)  prodPvInput.addEventListener('input', updateUnilevelPreview);
  if (prodPctInput) prodPctInput.addEventListener('input', updateUnilevelPreview);
  updateUnilevelPreview();

  document.getElementById('productForm').addEventListener('submit', function() {
    const btn = document.getElementById('prodSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving…';
  });
</script>

<?php require 'views/partials/footer.php'; ?>
