<?php
/**
 * Shop state-machine smoke test (run once, then delete).
 * Exercises ShopOrder::transition() and the timer jobs against the live dev DB
 * with throwaway rows, mirroring the plan's §8.3 / V5 expectations.
 */
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/helpers.php';
spl_autoload_register(function (string $class): void {
    foreach (['models/', 'controllers/'] as $dir) {
        $f = __DIR__ . '/../../' . $dir . $class . '.php';
        if (file_exists($f)) { require_once $f; return; }
    }
});

$pdo = db();
$pass = 0; $fail = 0;
function check(bool $cond, string $label, int &$pass, int &$fail): void {
    if ($cond) { $pass++; echo "PASS  {$label}\n"; }
    else { $fail++; echo "FAIL  {$label}\n"; }
}

// ── Setup (self-healing: remove leftovers from an aborted prior run) ──────────
// Children first, then the product (products.id is FK-RESTRICTed by items).
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
$pdo->exec("DELETE FROM shop_order_events WHERE order_id IN (SELECT id FROM (SELECT id FROM shop_orders WHERE order_no LIKE 'SMOKE-%') x)");
$pdo->exec("DELETE FROM shop_shipments  WHERE order_id IN (SELECT id FROM (SELECT id FROM shop_orders WHERE order_no LIKE 'SMOKE-%') x)");
$pdo->exec("DELETE FROM shop_order_items WHERE order_id IN (SELECT id FROM (SELECT id FROM shop_orders WHERE order_no LIKE 'SMOKE-%') x)");
$pdo->exec("DELETE FROM shop_refunds   WHERE order_id IN (SELECT id FROM (SELECT id FROM shop_orders WHERE order_no LIKE 'SMOKE-%') x)");
$pdo->exec("DELETE FROM shop_orders WHERE order_no LIKE 'SMOKE-%'");
$pdo->exec("DELETE FROM products WHERE sku='SMOKE-SKU' AND name='__SMOKE_TEST__'");
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");
$pdo->prepare("INSERT INTO products (name, sku, price, stock) VALUES ('__SMOKE_TEST__', 'SMOKE-SKU', 500.00, 5)")->execute();
$productId = (int)$pdo->lastInsertId();

function mkOrder(PDO $pdo, int $memberId, string $suffix, string $deadlineSql, int $productId, int $qty): int {
    $pdo->prepare("INSERT INTO shop_orders
        (order_no, member_id, payment_reference, idempotency_key, total_price, payment_method, status,
         payment_deadline, billing_name, shipping_name, shipping_address)
        VALUES (CONCAT('SMOKE-', DATE_FORMAT(NOW(),'%s%f'), ?), ?, CONCAT('SMOKE-REF-', DATE_FORMAT(NOW(),'%s%f'), ?),
                CONCAT('SMOKE-IDEM-', DATE_FORMAT(NOW(),'%s%f'), ?), ?, 'gcash', 'pending', {$deadlineSql},
                'Smoke Buyer', 'Smoke Buyer', '123 Test St')")->execute([$suffix, $memberId, $suffix, $suffix, 500.00 * $qty]);
    $orderId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO shop_order_items (order_id, product_id, product_name, quantity, unit_price, total_price, reservation_state)
                   VALUES (?, ?, '__SMOKE_TEST__', ?, 500.00, ?, 'reserved')")->execute([$orderId, $productId, $qty, 500.00 * $qty]);
    return $orderId;
}

function itemState(PDO $pdo, int $orderId): array {
    return $pdo->query("SELECT reservation_state, deducted_at FROM shop_order_items WHERE order_id = {$orderId}")->fetch();
}
function lastEvent(PDO $pdo, int $orderId): array {
    return $pdo->query("SELECT from_status, to_status, actor_type, source FROM shop_order_events WHERE order_id = {$orderId} ORDER BY id DESC LIMIT 1")->fetch() ?: [];
}
function statusOf(PDO $pdo, int $orderId): string {
    return (string)$pdo->query("SELECT status FROM shop_orders WHERE id = {$orderId}")->fetchColumn();
}
function stockOf(PDO $pdo, int $productId): int {
    return (int)$pdo->query("SELECT stock FROM products WHERE id = {$productId}")->fetchColumn();
}

$MEMBER = 3; // altas02
$ADMIN  = 1;

// ═══ 1. Happy path: pending → paid → packing → ready_to_ship → shipped        ═══
$o1 = mkOrder($pdo, $MEMBER, '-A', 'NOW()', $productId, 2);
check(ShopOrder::transition($o1, 'paid', 'admin', $ADMIN), 'pending → paid (admin)', $pass, $fail);
$row = ShopOrder::find($o1);
check($row['paid_by'] == $ADMIN && $row['paid_at'] !== null, 'paid_by/paid_at stamped', $pass, $fail);
check(itemState($pdo, $o1)['reservation_state'] === 'committed', 'lines reserved → committed on paid', $pass, $fail);
$e = lastEvent($pdo, $o1);
check($e['from_status'] === 'pending' && $e['to_status'] === 'paid' && $e['source'] === 'ui', 'audit row: pending→paid/admin/ui', $pass, $fail);

check(ShopOrder::transition($o1, 'packing', 'admin', $ADMIN), 'paid → packing', $pass, $fail);
check((bool)$pdo->query("SELECT approved_by FROM shop_orders WHERE id = {$o1}")->fetchColumn(), 'approved_by stamped on packing (§5.4)', $pass, $fail);
check(ShopOrder::transition($o1, 'ready_to_ship', 'admin', $ADMIN), 'packing → ready_to_ship', $pass, $fail);

check(ShopOrder::transition($o1, 'shipped', 'admin', $ADMIN), 'ready_to_ship → shipped (handoff)', $pass, $fail);
check(stockOf($pdo, $productId) === 3, 'stock 5 → 3 exactly once at shipped', $pass, $fail);
check(itemState($pdo, $o1)['reservation_state'] === 'deducted' && itemState($pdo, $o1)['deducted_at'] !== null, 'lines deducted_at stamped', $pass, $fail);
check($pdo->query("SELECT handoff_at FROM shop_shipments WHERE order_id = {$o1} AND state='active'")->fetchColumn() !== null, 'active shipment handoff_at stamped', $pass, $fail);

// ═══ 2. Delivery chain + auto-complete leg                                    ═══
check(ShopOrder::transition($o1, 'out_for_delivery', 'admin', $ADMIN), 'shipped → out_for_delivery', $pass, $fail);
check(ShopOrder::transition($o1, 'delivered', 'admin', $ADMIN), 'out_for_delivery → delivered', $pass, $fail);
$due = ShopOrder::find($o1)['completion_due_at'];
check($due !== null, 'completion_due_at set at delivered (+5d)', $pass, $fail);
check(ShopOrder::transition($o1, 'completed', 'system'), 'delivered → completed (system)', $pass, $fail);
check(in_array(statusOf($pdo, $o1), ShopOrder::TERMINAL, true), 'terminal reached', $pass, $fail);
check(ShopOrder::transition($o1, 'packing', 'admin', $ADMIN) === false, 'terminal refused: completed → packing', $pass, $fail);

// ═══ 3. Matrix + actor + typo guards                                          ═══
$o2 = mkOrder($pdo, $MEMBER, '-B', 'NOW()', $productId, 1);
check(ShopOrder::transition($o2, 'shipped', 'admin', $ADMIN) === false, 'illegal: pending → shipped refused', $pass, $fail);
check(ShopOrder::transition($o2, 'paid', 'customer', $MEMBER) === false, 'actor guard: customer cannot mark paid', $pass, $fail);
$threw = false;
try { ShopOrder::transition($o2, 'approved', 'admin', $ADMIN); } catch (InvalidArgumentException $ex) { $threw = true; }
check($threw, 'typo target throws InvalidArgumentException (rev-2 leftover refused)', $pass, $fail);
check(statusOf($pdo, $o2) === 'pending' && (int)$pdo->query("SELECT version FROM shop_orders WHERE id = {$o2}")->fetchColumn() === 1, 'refusals wrote nothing (status/version intact)', $pass, $fail);

// ═══ 4. Conditional write / race (V5 step 12)                                 ═══
check(ShopOrder::transition($o2, 'paid', 'admin', $ADMIN), 'o2: pending → paid wins the race', $pass, $fail);
check(ShopOrder::transition($o2, 'cancelled', 'customer', $MEMBER) === false, 'loser refused: customer cancel after paid', $pass, $fail);

// ═══ 5. Customer cancel + ownership + release                                 ═══
$o3 = mkOrder($pdo, $MEMBER, '-C', 'NOW()', $productId, 1);
check(ShopOrder::cancel($o3, 'customer', 4, 'changed_mind') === false, 'non-owner customer cancel refused', $pass, $fail);
check(ShopOrder::cancel($o3, 'customer', $MEMBER, 'changed_mind'), 'owner cancel succeeds', $pass, $fail);
check(itemState($pdo, $o3)['reservation_state'] === 'released', 'cancel releases reserved lines', $pass, $fail);
check(ShopOrder::find($o3)['cancelled_reason'] !== null, 'cancel reason recorded', $pass, $fail);

// ═══ 6. Stock conflict at handoff — full rollback                             ═══
$o4 = mkOrder($pdo, $MEMBER, '-D', 'NOW()', $productId, 99);
foreach ([['paid','admin'], ['packing','admin'], ['ready_to_ship','admin']] as [$to, $actor]) {
    ShopOrder::transition($o4, $to, $actor, $ADMIN);
}
check(ShopOrder::transition($o4, 'shipped', 'admin', $ADMIN) === false, 'handoff with stock < qty refused (S41)', $pass, $fail);
check(statusOf($pdo, $o4) === 'ready_to_ship', 'rollback: status still ready_to_ship', $pass, $fail);
check(stockOf($pdo, $productId) === 3, 'rollback: stock untouched (no partial deduction)', $pass, $fail);
check(itemState($pdo, $o4)['reservation_state'] === 'committed', 'rollback: lines still committed', $pass, $fail);

// ═══ 7. Holds: freeze, resume, deadline-less refusal                          ═══
$o5 = mkOrder($pdo, $MEMBER, '-E', 'NOW()', $productId, 1);
ShopOrder::transition($o5, 'paid', 'admin', $ADMIN);
check(ShopOrder::onHold($o5, $ADMIN, '', 1, date('Y-m-d H:i:s', time() + 3600)) === false, 'hold without reason refused', $pass, $fail);
check(ShopOrder::onHold($o5, $ADMIN, 'stock_shortage', 1, date('Y-m-d H:i:s', time() + 3600)), 'hold with reason/owner/deadline', $pass, $fail);
$h = ShopOrder::find($o5);
check($h['status'] === 'on_hold' && $h['previous_status'] === 'paid', 'previous_status frozen as resume target', $pass, $fail);
// From a paid-side hold the only legal admin move is packing (paid ∈ packing.from);
// shipped would be illegal — the hold resolves its effective source to 'paid'.
check(ShopOrder::transition($o5, 'packing', 'admin', $ADMIN), 'hold resolves effective source (paid → packing)', $pass, $fail);
$h2 = ShopOrder::find($o5);
check($h2['status'] === 'packing' && $h2['previous_status'] === null && $h2['hold_reason'] === null, 'hold fields cleared on real transition', $pass, $fail);
// Put back on hold, then resume() back to the frozen target (now packing).
check(ShopOrder::onHold($o5, $ADMIN, 'recheck_stock', 1, date('Y-m-d H:i:s', time() + 3600)), 'second hold from packing', $pass, $fail);
check(ShopOrder::resume($o5, $ADMIN), 'resume() returns order to previous_status', $pass, $fail);
check(statusOf($pdo, $o5) === 'packing', 'resume landed on packing (frozen target)', $pass, $fail);

// ═══ 8. Expiry timer (Gate 6): overdue cancel + payment_review immunity       ═══
$o6 = mkOrder($pdo, $MEMBER, '-F', 'DATE_SUB(NOW(), INTERVAL 1 HOUR)', $productId, 1);
$o7 = mkOrder($pdo, $MEMBER, '-G', 'DATE_SUB(NOW(), INTERVAL 1 HOUR)', $productId, 1);
ShopOrder::transition($o7, 'payment_review', 'customer', $MEMBER); // proof submitted, past deadline still
$expiredCount = ShopOrder::expireOverdue(200);
check($expiredCount >= 1, 'expireOverdue() cancelled the overdue order(s)', $pass, $fail);
check(statusOf($pdo, $o6) === 'cancelled', 'overdue pending → cancelled', $pass, $fail);
check(itemState($pdo, $o6)['reservation_state'] === 'released', 'expiry released the reserved lines', $pass, $fail);
$e6 = lastEvent($pdo, $o6);
check($e6['actor_type'] === 'system' && $e6['source'] === 'timer', 'expiry event: actor system / source timer (Gate 6)', $pass, $fail);
check(statusOf($pdo, $o7) === 'payment_review', 'T7 immunity: payment_review NEVER expires', $pass, $fail);

// ═══ 9. Auto-complete timer: past-window completes; open refund blocks        ═══
$o8 = mkOrder($pdo, $MEMBER, '-H', 'NOW()', $productId, 1);
foreach (['paid','packing','ready_to_ship','shipped','out_for_delivery','delivered'] as $to) {
    ShopOrder::transition($o8, $to, 'admin', $ADMIN);
}
$pdo->prepare("UPDATE shop_orders SET completion_due_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ?")->execute([$o8]);
$o9 = mkOrder($pdo, $MEMBER, '-I', 'NOW()', $productId, 1);
foreach (['paid','packing','ready_to_ship','shipped','out_for_delivery','delivered'] as $to) {
    ShopOrder::transition($o9, $to, 'admin', $ADMIN);
}
$pdo->prepare("UPDATE shop_orders SET completion_due_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ?")->execute([$o9]);
$pdo->prepare("INSERT INTO shop_refunds (order_id, amount, cause, status) VALUES (?, 500.00, 'delivery_failure', 'open')")->execute([$o9]);
$completedCount = ShopOrder::autoComplete(200);
check($completedCount >= 1, 'autoComplete() closed past-window orders', $pass, $fail);
check(statusOf($pdo, $o8) === 'completed', 'past report window → completed', $pass, $fail);
check(statusOf($pdo, $o9) === 'delivered', 'open refund blocks completion (§0.5 T24)', $pass, $fail);

// ═══ 10. Reservation math stays consistent after everything                   ═══
// The over-subscribed phantom (o4, qty 99, refused at handoff) legitimately
// holds its committed reservation (§0.3 accepted risk) until staff resolves
// it — an admin cancel releases the lines. Cancel both stragglers first.
check(ShopOrder::cancel($o4, 'admin', $ADMIN, 'stock_shortage'), 'admin cancel of over-subscribed ready_to_ship order', $pass, $fail);
check(ShopOrder::cancel($o2, 'admin', $ADMIN, 'buyer_request'), 'admin cancel of the paid straggler', $pass, $fail);
// Final ledger: 3 units shipped in total (o1 qty 2 + o8 + o9) → stock = 1.
// Release the last active reservations via admin cancel — o5 (packing) and
// o7 (payment_review, proof-exempt from expiry; only an admin can cancel it,
// per the §0.5 per-actor matrix) — then availability must equal absolute
// stock exactly (§0.3).
check(ShopOrder::cancel($o5, 'admin', $ADMIN, 'stock_shortage'), 'admin cancel from packing releases the last reservation', $pass, $fail);
check(ShopOrder::cancel($o7, 'admin', $ADMIN, 'buyer_request'), 'admin cancel from payment_review (§0.5 admin list)', $pass, $fail);
$stock = stockOf($pdo, $productId);
check($stock === 1, "absolute stock = 1 after 3 units shipped (got {$stock})", $pass, $fail);
$avail = Product::availableStock($productId);
check($avail === 1, "availableStock = 1 with no active reservations (got {$avail})", $pass, $fail);

// ═══ 10b. REAL COMMERCE FLOW through the actual model API ═══
// Place an order via ShopOrder::createFromCart using a real cart + product,
// pay via e-wallet (debitInternal + system markPaid), fulfil to delivered,
// and confirm receipt as the buyer. Then a gcash order with proof legs.
function realFlow(PDO $pdo, int &$pass, int &$fail): void {
    $MEMBER = 3; $ADMIN = 1;

    // self-healing: remove a previous aborted flow run
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $old = $pdo->query("SELECT id FROM products WHERE sku='SMOKE-FLOW'")->fetchColumn();
    if ($old) {
        foreach ($pdo->query("SELECT id FROM shop_orders WHERE order_no LIKE 'SMOKE-%' OR idempotency_key LIKE 'SMOKE-IDEM-FLOW-%'")->fetchAll(PDO::FETCH_COLUMN) as $oid) {
            $pdo->exec("DELETE FROM shop_order_events WHERE order_id = {$oid}");
            $pdo->exec("DELETE FROM shop_shipments WHERE order_id = {$oid}");
            $pdo->exec("DELETE FROM shop_order_items WHERE order_id = {$oid}");
            $pdo->exec("DELETE FROM shop_payment_proofs WHERE order_id = {$oid}");
            $pdo->exec("DELETE FROM shop_refunds WHERE order_id = {$oid}");
            $pdo->exec("DELETE FROM shop_orders WHERE id = {$oid}");
            $pdo->exec("DELETE FROM ewallet_ledger WHERE reference_id = {$oid} AND ref_type = 'shop_order'");
        }
        $pdo->exec("DELETE FROM cart_items WHERE cart_id IN (SELECT id FROM carts WHERE member_id = 3)");
        $pdo->exec("DELETE FROM carts WHERE member_id = 3");
        $pdo->exec("DELETE FROM products WHERE id = {$old}");
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

    // product + cart
    $pdo->prepare("INSERT INTO products (name, sku, price, stock) VALUES ('__SMOKE_FLOW__', 'SMOKE-FLOW', 100.00, 10)")->execute();
    $pid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO carts (member_id, status) VALUES (?, 'active')")->execute([$MEMBER]);
    $cartId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO cart_items (cart_id, product_id, quantity, unit_price) VALUES (?, ?, 2, 100.00)")->execute([$cartId, $pid]);

    $addr = [
        'billing_name' => 'Flow Buyer', 'billing_phone' => '09170000001',
        'shipping_name' => 'Flow Buyer', 'shipping_phone' => '09170000001',
        'shipping_address' => '1 Test Ave', 'shipping_city' => 'Makati',
        'shipping_province' => 'NCR', 'shipping_postal' => '1200',
    ];

    // ── E-wallet happy path ──
    $balBefore = Ewallet::balance($MEMBER);
    $commissionsBefore = (int)$pdo->query("SELECT COUNT(*) FROM commissions")->fetchColumn();
    $o = ShopOrder::createFromCart($MEMBER, $cartId, $addr, 'ewallet', 'SMOKE-IDEM-FLOW-1');
    check($o > 0, 'createFromCart: order created from real cart', $pass, $fail);
    $order = ShopOrder::find($o);
    check(abs((float)$order['total_price'] - 200.0) < 0.001, 'total priced from catalog (2×100)', $pass, $fail);
    check(str_starts_with($order['order_no'], 'AL'), 'public order_no AL-format', $pass, $fail);
    check($order['payment_deadline'] !== null, 'payment deadline stamped (+48h setting)', $pass, $fail);
    check((int)$pdo->query("SELECT COUNT(*) FROM shop_order_items WHERE order_id = {$o}")->fetchColumn() === 1, 'order line snapshotted', $pass, $fail);
    check((int)$pdo->query("SELECT COUNT(*) FROM shop_payment_proofs WHERE order_id = {$o}")->fetchColumn() === 0, 'no proof row for e-wallet order', $pass, $fail);

    // idempotency: same key returns the same order
    $o2 = ShopOrder::createFromCart($MEMBER, $cartId, $addr, 'ewallet', 'SMOKE-IDEM-FLOW-1');
    check($o2 === $o, 'idempotency key returns existing order', $pass, $fail);

    // e-wallet pay through placeOrder's exact pattern (debit inside txn, then system markPaid)
    $pdo->beginTransaction();
    $debitOk = Ewallet::debitInternal($MEMBER, (float)$order['total_price'], $o, 'shop_order', 'Smoke flow debit');
    if ($debitOk) { $pdo->commit(); } else { $pdo->rollBack(); }
    check($debitOk, 'Ewallet::debitInternal succeeded for shop_order ref_type', $pass, $fail);
    check(abs(Ewallet::balance($MEMBER) - ($balBefore - 200.0)) < 0.001, 'balance decreased by exactly the total', $pass, $fail);
    check((int)$pdo->query("SELECT COUNT(*) FROM ewallet_ledger WHERE reference_id = {$o} AND ref_type='shop_order' AND type='debit'")->fetchColumn() === 1, 'exactly one ledger row ref_type=shop_order', $pass, $fail);
    check(ShopOrder::markPaid($o, 'system', null), 'system markPaid (e-wallet auto-verify)', $pass, $fail);
    check(statusOf($pdo, $o) === 'paid', 'order is paid', $pass, $fail);

    // commission regression (S11): NO new commission rows appear from shop money
    $commissionsAfter = (int)$pdo->query("SELECT COUNT(*) FROM commissions")->fetchColumn();
    check($commissionsAfter === $commissionsBefore, 'S11: shop order created zero commission rows', $pass, $fail);

    // fulfilment chain (Auth is not loaded in CLI: assertNotSelfReview skips)

    // fulfilment chain
    check(ShopOrder::startPacking($o, $ADMIN), 'startPacking', $pass, $fail);
    check(ShopOrder::finishPacking($o, $ADMIN), 'finishPacking', $pass, $fail);
    check(ShopOrder::ship($o, $ADMIN, '', '') === false, 'ship without courier/tracking refused', $pass, $fail);
    check(ShopOrder::ship($o, $ADMIN, 'JRS', 'JRS-12345'), 'ship with courier+tracking', $pass, $fail);
    check(stockOf($pdo, $pid) === 8, 'stock 10 → 8 after handoff', $pass, $fail);
    check(ShopOrder::markDelivered($o, $ADMIN, null, null) === false, 'delivered without POD refused', $pass, $fail);
    check(ShopOrder::markDelivered($o, $ADMIN, 'Juan Dela Cruz', null), 'delivered with receiver name', $pass, $fail);
    check($pdo->query("SELECT pod_receiver FROM shop_shipments WHERE order_id = {$o} AND state='active'")->fetchColumn() === 'Juan Dela Cruz', 'POD stored on shipment', $pass, $fail);
    check(ShopOrder::confirmReceipt($o, $MEMBER), 'buyer confirms receipt', $pass, $fail);
    check(statusOf($pdo, $o) === 'completed', 'terminal: completed', $pass, $fail);

    // ── GCash order: proof legs + reject + correction window ──
    $pdo->prepare("INSERT INTO carts (member_id, status) VALUES (?, 'active')")->execute([$MEMBER]);
    $cart2 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO cart_items (cart_id, product_id, quantity, unit_price) VALUES (?, ?, 1, 100.00)")->execute([$cart2, $pid]);
    $g = ShopOrder::createFromCart($MEMBER, $cart2, $addr, 'gcash', 'SMOKE-IDEM-FLOW-2');
    check(statusOf($pdo, $g) === 'pending', 'gcash order lands pending', $pass, $fail);
    $mismatchThrew = false;
    try { ShopOrder::markPaid($g, 'admin', $ADMIN, 50.0, 'REF-X'); }
    catch (InvalidArgumentException $e) { $mismatchThrew = str_contains($e->getMessage(), 'Amount mismatch'); }
    check($mismatchThrew, 'admin verify with wrong amount refused (S15)', $pass, $fail);
    // proof leg via model with a fake stored file (no HTTP upload)
    check(ShopOrder::submitProof($g, $MEMBER, 'shop_proofs/proof_3_smoke.jpg', ['reference_no' => 'GC-1', 'amount' => 100.0, 'transfer_date' => date('Y-m-d')]), 'submitProof: pending → payment_review', $pass, $fail);
    check(ShopPaymentProof::latestFor($g)['status'] === 'pending', 'proof row pending', $pass, $fail);
    check(ShopOrder::cancelByCustomer($g, $MEMBER) === false, 'customer cancel blocked after proof (confirm-lock rule)', $pass, $fail);
    check(ShopOrder::markPaid($g, 'admin', $ADMIN, 100.0, 'GC-1'), 'admin verify with exact amount + unused ref', $pass, $fail);
    check(ShopPaymentProof::latestFor($g)['status'] === 'verified', 'proof marked verified', $pass, $fail);
    check((int)$pdo->query("SELECT COUNT(*) FROM shop_payment_proofs WHERE order_id = {$g}")->fetchColumn() === 1, 'no proof for e-wallet, exactly 1 for gcash', $pass, $fail);

    // reference uniqueness
    $dupThrew = false;
    try {
        $pdo->prepare("INSERT INTO carts (member_id, status) VALUES (?, 'active')")->execute([$MEMBER]);
        $cart3 = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO cart_items (cart_id, product_id, quantity, unit_price) VALUES (?, ?, 1, 100.00)")->execute([$cart3, $pid]);
        $o3 = ShopOrder::createFromCart($MEMBER, $cart3, $addr, 'gcash', 'SMOKE-IDEM-FLOW-3');
        ShopPaymentProof::add($o3, 'shop_proofs/x.jpg', ['reference_no' => 'GC-1', 'amount' => 100.0]);
        ShopOrder::markPaid($o3, 'admin', $ADMIN, 100.0, 'GC-1');
    } catch (InvalidArgumentException $e) { $dupThrew = str_contains($e->getMessage(), 'Reference already used'); }
    check($dupThrew, 'duplicate payment reference refused (S16)', $pass, $fail);

    // cleanup flow rows
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    foreach ([$o, $g, $o3] as $id) {
        $pdo->exec("DELETE FROM shop_order_events WHERE order_id = {$id}");
        $pdo->exec("DELETE FROM shop_shipments WHERE order_id = {$id}");
        $pdo->exec("DELETE FROM shop_order_items WHERE order_id = {$id}");
        $pdo->exec("DELETE FROM shop_payment_proofs WHERE order_id = {$id}");
        $pdo->exec("DELETE FROM shop_refunds WHERE order_id = {$id}");
        $pdo->exec("DELETE FROM shop_orders WHERE id = {$id}");
        $pdo->exec("DELETE FROM ewallet_ledger WHERE reference_id = {$id} AND ref_type = 'shop_order'");
    }
    $pdo->exec("DELETE FROM cart_items WHERE cart_id IN ({$cartId}, {$cart2}, {$cart3})");
    $pdo->exec("DELETE FROM carts WHERE id IN ({$cartId}, {$cart2}, {$cart3})");
    $pdo->exec("DELETE FROM products WHERE id = {$pid}");
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    // restore buyer balance (debitInternal takes from both columns)
    $delta = 200.0;
    $pdo->prepare("UPDATE users SET ewallet_balance = ewallet_balance + ?, withdrawable_balance = withdrawable_balance + ? WHERE id = ?")->execute([$delta, $delta, $MEMBER]);
}
realFlow($pdo, $pass, $fail);

// ═══ Cleanup ═══════════════════════════════════════════════════════════════════
$ids = implode(',', [$o1, $o2, $o3, $o4, $o5, $o6, $o7, $o8, $o9]);
$pdo->exec("DELETE FROM shop_order_events WHERE order_id IN ({$ids})");
$pdo->exec("DELETE FROM shop_shipments WHERE order_id IN ({$ids})");
$pdo->exec("DELETE FROM shop_order_items WHERE order_id IN ({$ids})");
$pdo->exec("DELETE FROM shop_refunds WHERE order_id IN ({$ids})");
$pdo->exec("DELETE FROM shop_orders WHERE id IN ({$ids})");
$pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$productId]);
echo "\ncleanup: smoke rows removed\n";

echo "\n==== RESULT: {$pass} passed, {$fail} failed ====\n";
exit($fail === 0 ? 0 : 1);
