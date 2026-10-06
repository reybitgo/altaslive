<?php class MemberController {
    public function shop(): void { Auth::guard('member'); $pageTitle="Shop"; require "views/partials/head.php"; echo "<div class=\"container py-4\"><h4>Shop</h4><p>Coming soon.</p></div>"; require "views/partials/footer.php"; }
    public function shopOrders(): void { Auth::guard('member'); $pageTitle="My Orders"; require "views/partials/head.php"; echo "<div class=\"container py-4\"><h4>My Orders</h4><p>Coming soon.</p></div>"; require "views/partials/footer.php"; }
    public function cart(): void { Auth::guard('member'); $pageTitle="Cart"; require "views/partials/head.php"; echo "<div class=\"container py-4\"><h4>Cart</h4><p>Coming soon.</p></div>"; require "views/partials/footer.php"; }
    public function addToCart(): void { Auth::guard('member'); csrf_verify(); flash("info","Add to cart stub"); redirect("/?page=shop"); }
    public function updateCartItem(): void { Auth::guard('member'); csrf_verify(); flash("info","Update cart stub"); redirect("/?page=cart"); }
    public function removeCartItem(): void { Auth::guard('member'); csrf_verify(); flash("info","Remove cart stub"); redirect("/?page=cart"); }
    public function checkout(): void { Auth::guard('member'); $pageTitle="Checkout"; require "views/partials/head.php"; echo "<div class=\"container py-4\"><h4>Checkout</h4><p>Coming soon.</p></div>"; require "views/partials/footer.php"; }
    public function placeOrder(): void { Auth::guard('member'); csrf_verify(); flash("info","Place order stub"); redirect("/?page=shop"); }
}
