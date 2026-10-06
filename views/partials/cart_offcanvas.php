<?php
if (!Auth::check()) return;
$cart = Cart::getOrCreate(Auth::id());
$cartItems = Cart::getItems((int)$cart['id']);
$cartTotals = Cart::getTotals((int)$cart['id']);
$itemCount = (int)$cartTotals['total_items'];
?>
<div class="offcanvas offcanvas-end" tabindex="-1" id="cartOffcanvas" aria-labelledby="cartOffcanvasLabel">
  <div class="offcanvas-header border-bottom">
    <h5 class="offcanvas-title" id="cartOffcanvasLabel">Cart <span class="badge bg-primary rounded-pill"><?php echo $itemCount; ?></span></h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body p-0 d-flex flex-column">
    <?php if (empty($cartItems)): ?>
      <div class="d-flex flex-column align-items-center justify-content-center flex-grow-1 text-muted p-4">
        <div style="font-size:3rem;line-height:1">🛒</div>
        <p class="mt-3 mb-2">Your cart is empty</p>
      </div>
    <?php else: ?>
      <div class="flex-grow-1 overflow-auto p-3" id="cartItemsContainer">
        <?php foreach ($cartItems as $item): $img = $item['image_url'] ? APP_URL.'/uploads/'.e($item['image_url']) : ''; ?>
          <div class="d-flex gap-3 pb-3 mb-3 border-bottom cart-item" data-item-id="<?php echo (int)$item['id']; ?>" data-unit-price="<?php echo (float)$item['unit_price']; ?>">
            <div class="flex-shrink-0" style="width:64px;height:64px;border:1px solid #eef1f8;border-radius:.5rem;overflow:hidden;background:#f8f9fb;display:flex;align-items:center;justify-content:center">
              <?php if ($img): ?><img src="<?php echo e($img); ?>" style="width:100%;height:100%;object-fit:cover"><?php else: ?><span style="font-size:1.5rem">🛒</span><?php endif; ?>
            </div>
            <div class="flex-grow-1 min-w-0">
              <div class="fw-semibold text-truncate"><?php echo e($item['product_name']); ?></div>
              <div class="small text-muted"><?php echo number_format((float)$item['unit_price'],2); ?> PHP</div>
              <div class="d-flex align-items-center justify-content-between mt-2">
                <div class="input-group input-group-sm" style="width:110px">
                  <button class="btn btn-sm border cart-qty-btn" data-dir="down" type="button">-</button>
                  <input type="number" class="form-control text-center cart-qty-input" data-item-id="<?php echo (int)$item['id']; ?>" value="<?php echo (int)$item['quantity']; ?>" min="1" max="<?php echo (int)$item['stock']; ?>">
                  <button class="btn btn-sm border cart-qty-btn" data-dir="up" type="button">+</button>
                </div>
                <button class="btn btn-sm btn-link text-danger cart-remove-btn" data-item-id="<?php echo (int)$item['id']; ?>" type="button">Remove</button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="border-top bg-white p-3 shadow-sm" id="cartFooter">
        <div class="d-flex justify-content-between mb-2">
          <span>Subtotal</span>
          <span id="cartSubtotal"><?php echo number_format((float)$cartTotals['total_price'],2); ?> PHP</span>
        </div>
        <div class="d-flex justify-content-between fw-semibold mb-3">
          <span>Total</span>
          <span id="cartTotalPrice"><?php echo number_format((float)$cartTotals['total_price'],2); ?> PHP</span>
        </div>
        <a href="<?php echo link_to('checkout'); ?>" class="btn btn-primary w-100">Checkout</a>
        <a href="<?php echo link_to('cart'); ?>" class="btn btn-link btn-sm w-100 mt-2">View full cart</a>
      </div>
    <?php endif; ?>
  </div>
</div>
