<?php class AdminController {
    public function shopProducts(): void { Auth::guard("admin"); $pageTitle="Shop Products"; require "views/partials/head.php"; echo "<div class=\"container py-4\"><h4>Shop Products</h4><p>Coming soon.</p></div>"; require "views/partials/footer.php"; }
    public function saveShopProduct(): void { Auth::guard("admin"); csrf_verify(); flash("info","Save product stub"); redirect("/?page=admin_shop"); }
    public function deleteShopProduct(): void { Auth::guard("admin"); csrf_verify(); flash("info","Delete product stub"); redirect("/?page=admin_shop"); }
    public function shopOrders(): void { Auth::guard("admin"); $pageTitle="Shop Orders"; require "views/partials/head.php"; echo "<div class=\"container py-4\"><h4>Shop Orders</h4><p>Coming soon.</p></div>"; require "views/partials/footer.php"; }
    public function markOrderPaid(): void { Auth::guard("admin"); csrf_verify(); flash("info","Mark paid stub"); redirect("/?page=admin_shop_orders"); }
    public function approveOrder(): void { Auth::guard("admin"); csrf_verify(); flash("info","Approve stub"); redirect("/?page=admin_shop_orders"); }
    public function rejectOrder(): void { Auth::guard("admin"); csrf_verify(); flash("info","Reject stub"); redirect("/?page=admin_shop_orders"); }
}
