<?php

/**
 * @file   tests/ShopStateMachineTest.php
 * @brief  Shop state-machine harness — non-destructive, transaction-wrapped.
 *
 * Ported from tmp/shop/smoke_test.php (preserved outside the repo) with the
 * destructive parts removed. See tmp/shop/remedy.md, Phase 1.
 *
 * WHAT CHANGED AND WHY
 *   - No hardcoded user ids. The original used users.id 3 and 1; every identity
 *     here is INSERTed by Fixtures:: and rolled back.
 *   - No self-healing DELETE block, no SET FOREIGN_KEY_CHECKS=0, no cleanup
 *     block, and no raw "restore the balance" UPDATE. Each scenario runs inside
 *     a transaction that is rolled back on exit, so nothing survives a run —
 *     including a crash.
 *   - Sections 1-10 are kept in ONE transaction because their assertions depend
 *     on shared stock and shared order state (the original's running ledger:
 *     stock 5 -> 3 -> 1). Splitting them would change what is being asserted.
 *
 * EXPECTED RESULT ON UNFIXED CODE: green for the model layer, red for the
 * boundary layer (cart ownership C3, refund lifecycle C1/C2, self-review H6).
 * That split is the point: the original suite was model-only, which is how the
 * boundary defects shipped.
 *
 * Run:  php tests/ShopStateMachineTest.php
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * Order factory matching the original's mkOrder(), including the ability to
 * inject a deadline expression so the timer legs can be exercised.
 */
function mkOrder(int $memberId, string $suffix, string $deadlineSql, int $productId, int $qty, float $unitPrice = 500.00): int
{
    db()->prepare(
        "INSERT INTO shop_orders
            (order_no, member_id, payment_reference, idempotency_key, total_price, payment_method, status,
             payment_deadline, billing_name, shipping_name, shipping_address)
         VALUES (CONCAT('T-', DATE_FORMAT(NOW(),'%s%f'), ?), ?, CONCAT('T-REF-', DATE_FORMAT(NOW(),'%s%f'), ?),
                 CONCAT('T-IDEM-', DATE_FORMAT(NOW(),'%s%f'), ?), ?, 'gcash', 'pending', {$deadlineSql},
                 'Test Buyer', 'Test Buyer', '123 Test St')"
    )->execute([$suffix, $memberId, $suffix, $suffix, $unitPrice * $qty]);
    $orderId = (int) db()->lastInsertId();
    db()->prepare(
        "INSERT INTO shop_order_items
            (order_id, product_id, product_name, quantity, unit_price, total_price, reservation_state)
         VALUES (?, ?, '__TEST__', ?, ?, ?, 'reserved')"
    )->execute([$orderId, $productId, $qty, $unitPrice, $unitPrice * $qty]);
    return $orderId;
}

// ═══════════════════════════════════════════════════════════════════════════
// Sections 1-10: the original model suite, preserved as a regression floor.
// ═══════════════════════════════════════════════════════════════════════════

T::group('State machine — original model suite (sections 1-10)');

test('sections 1-10 model suite', function (): void {
    $MEMBER = Fixtures::member();
    $ADMIN  = Fixtures::admin();
    $productId = Fixtures::product('__TEST__', 500.00, 5);

    // ── 1. Happy path: pending -> paid -> packing -> ready_to_ship -> shipped
    $o1 = mkOrder($MEMBER, '-A', 'NOW()', $productId, 2);
    T::ok(ShopOrder::transition($o1, 'paid', 'admin', $ADMIN), 'pending -> paid (admin)');
    $row = ShopOrder::find($o1);
    T::ok($row['paid_by'] == $ADMIN && $row['paid_at'] !== null, 'paid_by/paid_at stamped');
    T::eq('committed', Fixtures::itemState($o1), 'lines reserved -> committed on paid');
    $e = Fixtures::lastEvent($o1);
    T::ok($e['from_status'] === 'pending' && $e['to_status'] === 'paid' && $e['source'] === 'ui', 'audit row: pending->paid/admin/ui');

    T::ok(ShopOrder::transition($o1, 'packing', 'admin', $ADMIN), 'paid -> packing');
    T::ok((bool) Fixtures::scalar("SELECT approved_by FROM shop_orders WHERE id = ?", [$o1]), 'approved_by stamped on packing (5.4)');
    T::ok(ShopOrder::transition($o1, 'ready_to_ship', 'admin', $ADMIN), 'packing -> ready_to_ship');

    T::ok(ShopOrder::transition($o1, 'shipped', 'admin', $ADMIN), 'ready_to_ship -> shipped (handoff)');
    T::eq(3, Fixtures::stockOf($productId), 'stock 5 -> 3 exactly once at shipped');
    T::ok(Fixtures::itemState($o1) === 'deducted' && Fixtures::scalar("SELECT deducted_at FROM shop_order_items WHERE order_id = ?", [$o1]) !== null, 'lines deducted_at stamped');
    T::ok(Fixtures::scalar("SELECT handoff_at FROM shop_shipments WHERE order_id = ? AND state='active'", [$o1]) !== null, 'active shipment handoff_at stamped');

    // ── 2. Delivery chain + auto-complete leg
    T::ok(ShopOrder::transition($o1, 'out_for_delivery', 'admin', $ADMIN), 'shipped -> out_for_delivery');
    T::ok(ShopOrder::transition($o1, 'delivered', 'admin', $ADMIN), 'out_for_delivery -> delivered');
    T::ok(ShopOrder::find($o1)['completion_due_at'] !== null, 'completion_due_at set at delivered (+5d)');
    T::ok(ShopOrder::transition($o1, 'completed', 'system'), 'delivered -> completed (system)');
    T::inSet(Fixtures::statusOf($o1), ShopOrder::TERMINAL, 'terminal reached');
    T::ok(ShopOrder::transition($o1, 'packing', 'admin', $ADMIN) === false, 'terminal refused: completed -> packing');

    // ── 3. Matrix + actor + typo guards
    $o2 = mkOrder($MEMBER, '-B', 'NOW()', $productId, 1);
    T::ok(ShopOrder::transition($o2, 'shipped', 'admin', $ADMIN) === false, 'illegal: pending -> shipped refused');
    T::ok(ShopOrder::transition($o2, 'paid', 'customer', $MEMBER) === false, 'actor guard: customer cannot mark paid');
    T::throws(InvalidArgumentException::class, static fn() => ShopOrder::transition($o2, 'approved', 'admin', $ADMIN), 'typo target throws (rev-2 leftover refused)');
    T::ok(Fixtures::statusOf($o2) === 'pending' && Fixtures::versionOf($o2) === 1, 'refusals wrote nothing (status/version intact)');

    // ── 4. Conditional write / race
    T::ok(ShopOrder::transition($o2, 'paid', 'admin', $ADMIN), 'o2: pending -> paid wins the race');
    T::ok(ShopOrder::transition($o2, 'cancelled', 'customer', $MEMBER) === false, 'loser refused: customer cancel after paid');

    // ── 5. Customer cancel + ownership + release
    $o3 = mkOrder($MEMBER, '-C', 'NOW()', $productId, 1);
    T::ok(ShopOrder::cancel($o3, 'customer', $MEMBER + 9999, 'changed_mind') === false, 'non-owner customer cancel refused');
    T::ok(ShopOrder::cancel($o3, 'customer', $MEMBER, 'changed_mind'), 'owner cancel succeeds');
    T::eq('released', Fixtures::itemState($o3), 'cancel releases reserved lines');
    T::ok(ShopOrder::find($o3)['cancelled_reason'] !== null, 'cancel reason recorded');

    // ── 6. Stock conflict at handoff — full rollback
    $o4 = mkOrder($MEMBER, '-D', 'NOW()', $productId, 99);
    foreach (['paid', 'packing', 'ready_to_ship'] as $to) {
        ShopOrder::transition($o4, $to, 'admin', $ADMIN);
    }
    T::ok(ShopOrder::transition($o4, 'shipped', 'admin', $ADMIN) === false, 'handoff with stock < qty refused (S41)');
    T::eq('ready_to_ship', Fixtures::statusOf($o4), 'rollback: status still ready_to_ship');
    T::eq(3, Fixtures::stockOf($productId), 'rollback: stock untouched (no partial deduction)');
    T::eq('committed', Fixtures::itemState($o4), 'rollback: lines still committed');

    // ── 7. Holds
    $o5 = mkOrder($MEMBER, '-E', 'NOW()', $productId, 1);
    ShopOrder::transition($o5, 'paid', 'admin', $ADMIN);
    $in1h = date('Y-m-d H:i:s', time() + 3600);
    T::ok(ShopOrder::onHold($o5, $ADMIN, '', 1, $in1h) === false, 'hold without reason refused');
    T::ok(ShopOrder::onHold($o5, $ADMIN, 'stock_shortage', 1, $in1h), 'hold with reason/owner/deadline');
    $h = ShopOrder::find($o5);
    T::ok($h['status'] === 'on_hold' && $h['previous_status'] === 'paid', 'previous_status frozen as resume target');
    T::ok(ShopOrder::transition($o5, 'packing', 'admin', $ADMIN), 'hold resolves effective source (paid -> packing)');
    $h2 = ShopOrder::find($o5);
    T::ok($h2['status'] === 'packing' && $h2['previous_status'] === null && $h2['hold_reason'] === null, 'hold fields cleared on real transition');
    T::ok(ShopOrder::onHold($o5, $ADMIN, 'recheck_stock', 1, $in1h), 'second hold from packing');
    T::ok(ShopOrder::resume($o5, $ADMIN), 'resume() returns order to previous_status');
    T::eq('packing', Fixtures::statusOf($o5), 'resume landed on packing (frozen target)');

    // ── 8. Expiry timer + payment_review immunity
    $o6 = mkOrder($MEMBER, '-F', 'DATE_SUB(NOW(), INTERVAL 1 HOUR)', $productId, 1);
    $o7 = mkOrder($MEMBER, '-G', 'DATE_SUB(NOW(), INTERVAL 1 HOUR)', $productId, 1);
    ShopOrder::transition($o7, 'payment_review', 'customer', $MEMBER);
    T::ok(ShopOrder::expireOverdue(200) >= 1, 'expireOverdue() cancelled the overdue order(s)');
    T::eq('cancelled', Fixtures::statusOf($o6), 'overdue pending -> cancelled');
    T::eq('released', Fixtures::itemState($o6), 'expiry released the reserved lines');
    $e6 = Fixtures::lastEvent($o6);
    T::ok($e6['actor_type'] === 'system' && $e6['source'] === 'timer', 'expiry event: actor system / source timer');
    T::eq('payment_review', Fixtures::statusOf($o7), 'T7 immunity: payment_review NEVER expires');

    // ── 9. Auto-complete timer; open refund blocks
    foreach (['-H' => 'o8', '-I' => 'o9'] as $suffix => $_) {
        $oid = mkOrder($MEMBER, $suffix, 'NOW()', $productId, 1);
        foreach (['paid', 'packing', 'ready_to_ship', 'shipped', 'out_for_delivery', 'delivered'] as $to) {
            ShopOrder::transition($oid, $to, 'admin', $ADMIN);
        }
        db()->prepare("UPDATE shop_orders SET completion_due_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ?")->execute([$oid]);
        if ($suffix === '-H') { $o8 = $oid; } else { $o9 = $oid; }
    }
    db()->prepare("INSERT INTO shop_refunds (order_id, amount, cause, status) VALUES (?, 500.00, 'delivery_failure', 'open')")->execute([$o9]);
    T::ok(ShopOrder::autoComplete(200) >= 1, 'autoComplete() closed past-window orders');
    T::eq('completed', Fixtures::statusOf($o8), 'past report window -> completed');
    T::eq('delivered', Fixtures::statusOf($o9), 'open refund blocks completion (T24)');

    // ── 10. Reservation math
    T::ok(ShopOrder::cancel($o4, 'admin', $ADMIN, 'stock_shortage'), 'admin cancel of over-subscribed ready_to_ship order');
    T::ok(ShopOrder::cancel($o2, 'admin', $ADMIN, 'buyer_request'), 'admin cancel of the paid straggler');
    T::ok(ShopOrder::cancel($o5, 'admin', $ADMIN, 'stock_shortage'), 'admin cancel from packing releases the last reservation');
    T::ok(ShopOrder::cancel($o7, 'admin', $ADMIN, 'buyer_request'), 'admin cancel from payment_review');
    T::eq(1, Fixtures::stockOf($productId), 'absolute stock = 1 after 3 units shipped');
    T::eq(1, Product::availableStock($productId), 'availableStock = 1 with no active reservations');
});


// ═══════════════════════════════════════════════════════════════════════════
// Section 10b: the real commerce flow through the public model API.
// ═══════════════════════════════════════════════════════════════════════════

T::group('Real commerce flow (createFromCart -> pay -> fulfil)');

test('e-wallet happy path end to end', function (): void {
    $MEMBER = Fixtures::member(1000.00);
    $ADMIN  = Fixtures::admin();
    $pid    = Fixtures::product('__FLOW__', 100.00, 10);
    $cartId = (int) Cart::getOrCreate($MEMBER)['id'];
    Cart::addItem($cartId, $pid, 2);

    $addr = [
        'billing_name' => 'Flow Buyer', 'billing_phone' => '09170000001',
        'shipping_name' => 'Flow Buyer', 'shipping_phone' => '09170000001',
        'shipping_address' => '1 Test Ave', 'shipping_city' => 'Makati',
        'shipping_province' => 'NCR', 'shipping_postal' => '1200',
    ];

    $balBefore        = Ewallet::balance($MEMBER);
    $commissionsBefore = (int) Fixtures::scalar("SELECT COUNT(*) FROM commissions");

    $o = ShopOrder::createFromCart($MEMBER, $cartId, $addr, 'ewallet', 'TEST-IDEM-FLOW-1');
    T::ok($o > 0, 'createFromCart: order created from real cart');
    $order = ShopOrder::find($o);
    T::ok(abs((float) $order['total_price'] - 200.0) < 0.001, 'total priced from catalog (2x100)');
    T::ok(str_starts_with($order['order_no'], 'AL'), 'public order_no AL-format');
    T::ok($order['payment_deadline'] !== null, 'payment deadline stamped');
    T::eq(1, (int) Fixtures::scalar("SELECT COUNT(*) FROM shop_order_items WHERE order_id = ?", [$o]), 'order line snapshotted');
    T::eq(0, (int) Fixtures::scalar("SELECT COUNT(*) FROM shop_payment_proofs WHERE order_id = ?", [$o]), 'no proof row for e-wallet order');

    $oAgain = ShopOrder::createFromCart($MEMBER, $cartId, $addr, 'ewallet', 'TEST-IDEM-FLOW-1');
    T::eq($o, $oAgain, 'idempotency key returns existing order');

    T::ok(
        Ewallet::debitInternal($MEMBER, (float) $order['total_price'], $o, 'shop_order', 'Test debit'),
        'Ewallet::debitInternal succeeded for shop_order ref_type'
    );
    T::ok(abs(Ewallet::balance($MEMBER) - ($balBefore - 200.0)) < 0.001, 'balance decreased by exactly the total');
    T::eq(
        1,
        (int) Fixtures::scalar("SELECT COUNT(*) FROM ewallet_ledger WHERE reference_id = ? AND ref_type='shop_order' AND type='debit'", [$o]),
        'exactly one ledger row ref_type=shop_order'
    );

    T::ok(ShopOrder::markPaid($o, 'system', null), 'system markPaid (e-wallet auto-verify)');
    T::eq('paid', Fixtures::statusOf($o), 'order is paid');

    // S11 regression gate: a shop order must never create commission rows.
    T::eq(
        $commissionsBefore,
        (int) Fixtures::scalar("SELECT COUNT(*) FROM commissions"),
        'S11: shop order created zero commission rows'
    );

    T::ok(ShopOrder::startPacking($o, $ADMIN), 'startPacking');
    T::ok(ShopOrder::finishPacking($o, $ADMIN), 'finishPacking');
    T::ok(ShopOrder::ship($o, $ADMIN, '', '') === false, 'ship without courier/tracking refused');
    T::ok(ShopOrder::ship($o, $ADMIN, 'JRS', 'JRS-12345'), 'ship with courier+tracking');
    T::eq(8, Fixtures::stockOf($pid), 'stock 10 -> 8 after handoff');
    T::ok(ShopOrder::markDelivered($o, $ADMIN, null, null) === false, 'delivered without POD refused');
    T::ok(ShopOrder::markDelivered($o, $ADMIN, 'Juan Dela Cruz', null), 'delivered with receiver name');
    T::eq('Juan Dela Cruz', Fixtures::scalar("SELECT pod_receiver FROM shop_shipments WHERE order_id = ? AND state='active'", [$o]), 'POD stored on shipment');
    T::ok(ShopOrder::confirmReceipt($o, $MEMBER), 'buyer confirms receipt');
    T::eq('completed', Fixtures::statusOf($o), 'terminal: completed');
});

T::group('Manual payment legs (proof -> review -> verify)');

test('gcash proof lifecycle', function (): void {
    $MEMBER = Fixtures::member();
    $ADMIN  = Fixtures::admin();
    $pid    = Fixtures::product('__GCS__', 100.00, 10);
    $cartId = (int) Cart::getOrCreate($MEMBER)['id'];
    Cart::addItem($cartId, $pid, 1);

    $addr = ['billing_name' => 'G', 'shipping_name' => 'G', 'shipping_address' => '1 St', 'shipping_postal' => '1200'];
    $g = ShopOrder::createFromCart($MEMBER, $cartId, $addr, 'gcash', 'TEST-IDEM-GC-1');
    T::eq('pending', Fixtures::statusOf($g), 'gcash order lands pending');

    T::throws(
        InvalidArgumentException::class,
        static fn() => ShopOrder::markPaid($g, 'admin', $ADMIN, 50.0, 'REF-X'),
        'admin verify with wrong amount refused (S15)'
    );

    T::ok(
        ShopOrder::submitProof($g, $MEMBER, 'shop_proofs/test.jpg', ['reference_no' => 'GC-1', 'amount' => 100.0, 'transfer_date' => date('Y-m-d')]),
        'submitProof: pending -> payment_review'
    );
    T::eq('pending', ShopPaymentProof::latestFor($g)['status'], 'proof row pending');
    T::ok(ShopOrder::cancelByCustomer($g, $MEMBER) === false, 'customer cancel blocked after proof (confirm-lock)');
    T::ok(ShopOrder::markPaid($g, 'admin', $ADMIN, 100.0, 'GC-1'), 'admin verify with exact amount + unused ref');
    T::eq('verified', ShopPaymentProof::latestFor($g)['status'], 'proof marked verified');
    T::eq(1, (int) Fixtures::scalar("SELECT COUNT(*) FROM shop_payment_proofs WHERE order_id = ?", [$g]), 'exactly 1 proof for gcash');

    // S16: reference uniqueness across orders
    $cart3 = (int) Cart::getOrCreate($MEMBER)['id'];
    Cart::addItem($cart3, $pid, 1);
    $o3 = ShopOrder::createFromCart($MEMBER, $cart3, $addr, 'gcash', 'TEST-IDEM-GC-2');
    ShopPaymentProof::add($o3, 'shop_proofs/x.jpg', ['reference_no' => 'GC-1', 'amount' => 100.0]);
    T::throws(
        InvalidArgumentException::class,
        static fn() => ShopOrder::markPaid($o3, 'admin', $ADMIN, 100.0, 'GC-1'),
        'duplicate payment reference refused (S16)'
    );
});


// ═══════════════════════════════════════════════════════════════════════════
// Boundary layer — the coverage the original suite did not have.
// ═══════════════════════════════════════════════════════════════════════════

T::group('C3 — cart item ownership (IDOR)');

test('a member cannot touch another member\'s cart line', function (): void {
    $victim   = Fixtures::member();
    $attacker = Fixtures::member();
    $pid      = Fixtures::product('__IDOR__', 50.00, 10);

    $victimCart  = Fixtures::cartWith($victim, $pid, 2);
    $attackerCart = (int) Cart::getOrCreate($attacker)['id'];
    Cart::addItem($attackerCart, $pid, 1);

    $victimItemId = (int) Fixtures::scalar("SELECT id FROM cart_items WHERE cart_id = ?", [$victimCart]);

    // The controller hands Cart::updateItemQuantity/removeItemById a bare
    // item_id taken straight from POST. Reproduce exactly that call shape.
    // Quantity is within stock so the assertion isolates OWNERSHIP rather than
    // tripping the stock guard.
    $qtyBefore = (int) Fixtures::scalar("SELECT quantity FROM cart_items WHERE id = ?", [$victimItemId]);
    T::eq(2, $qtyBefore, 'victim cart starts at qty 2');

    Cart::updateItemQuantity($victimItemId, 7);
    $qtyAfter = (int) Fixtures::scalar("SELECT quantity FROM cart_items WHERE id = ?", [$victimItemId]);
    T::eq(
        $qtyBefore,
        $qtyAfter,
        'C3: updateItemQuantity scoped to the caller\'s cart leaves the victim\'s line untouched'
    );

    Cart::removeItemById($victimItemId);
    $stillThere = (int) Fixtures::scalar("SELECT COUNT(*) FROM cart_items WHERE id = ?", [$victimItemId]);
    T::ok(
        $stillThere > 0,
        'C3: removeItemById scoped to the caller\'s cart leaves the victim\'s line in place',
        "row count {$stillThere}"
    );
});

T::group('C1/C2 — refund lifecycle closes');

test('refund reaches its terminal state and credits the wallet', function (): void {
    $MEMBER = Fixtures::member(500.00);
    $ADMIN  = Fixtures::admin();
    $pid    = Fixtures::product('__REF__', 100.00, 5);
    $cartId = (int) Cart::getOrCreate($MEMBER)['id'];
    Cart::addItem($cartId, $pid, 1);
    $addr = ['billing_name' => 'R', 'shipping_name' => 'R', 'shipping_address' => '1 St'];

    $orderId = ShopOrder::createFromCart($MEMBER, $cartId, $addr, 'gcash', 'TEST-IDEM-REF-1');
    ShopOrder::transition($orderId, 'payment_review', 'customer', $MEMBER);
    ShopPaymentProof::add($orderId, 'shop_proofs/ref.jpg', ['reference_no' => 'REF-1', 'amount' => 100.0]);
    T::ok(ShopOrder::markPaid($orderId, 'admin', $ADMIN, 100.0, 'REF-1'), 'order paid for refund test');

    $refundId = ShopRefund::create($orderId, 100.00, 'admin_cancel', 'ewallet', null, $ADMIN);
    T::eq('open', ShopRefund::find($refundId)['status'], 'refund opens as open');
    T::ok(ShopRefund::hasOpen($orderId), 'hasOpen blocks completion while open');

    T::ok(ShopRefund::approve($refundId, $ADMIN), 'refund approved');

    $balBefore = Ewallet::balance($MEMBER);
    try {
        $ok = ShopRefund::markPaid($refundId, $ADMIN, 'RECEIPT-1');
        T::ok($ok, 'C1/C2: markPaid closes the refund and credits the wallet');
        T::eq(1, (int) Fixtures::scalar(
            "SELECT COUNT(*) FROM shop_refunds WHERE id = ? AND status = 'processed'",
            [$refundId]
        ), 'C1: refund row reached the DDL-defined terminal state (processed)');
        T::ok(
            abs(Ewallet::balance($MEMBER) - ($balBefore + 100.0)) < 0.001,
            'C2: e-wallet credited exactly the refund amount'
        );
    } catch (Throwable $e) {
        T::ok(false, 'C1/C2: markPaid closes the refund and credits the wallet', get_class($e) . ': ' . substr($e->getMessage(), 0, 110));
        T::ok(false, 'C1: refund row reached the DDL-defined terminal state (processed)', 'unreachable after the failure above');
        T::ok(false, 'C2: e-wallet credited exactly the refund amount', 'unreachable after the failure above');
    }
});

T::group('H6 — segregation of duties is model-enforced');

test('an admin cannot act on their own order', function (): void {
    $adminId = Fixtures::admin();
    $pid     = Fixtures::product('__SELF__', 100.00, 5);

    // The admin buys with their own account...
    $cartId = (int) Cart::getOrCreate($adminId)['id'];
    Cart::addItem($cartId, $pid, 1);
    $addr = ['billing_name' => 'A', 'shipping_name' => 'A', 'shipping_address' => '1 St'];
    $orderId = ShopOrder::createFromCart($adminId, $cartId, $addr, 'gcash', 'TEST-IDEM-SELF-1');
    ShopOrder::transition($orderId, 'payment_review', 'customer', $adminId);
    ShopPaymentProof::add($orderId, 'shop_proofs/self.jpg', ['reference_no' => 'SELF-1', 'amount' => 100.0]);

    // ...and then a session exists, so assertNotSelfReview() is no longer inert.
    Fixtures::loginAs($adminId, 'admin');
    try {
        T::throws(
            RuntimeException::class,
            static fn() => ShopOrder::assertNotSelfReview(ShopOrder::find($orderId)),
            'H6: assertNotSelfReview refuses an admin acting on their own order'
        );

        // Phase 3 extends this to every admin endpoint. Today only the
        // transition path calls it, so onHold/reship/refund are unguarded.
        $held = ShopOrder::onHold($orderId, $adminId, 'self_hold', $adminId, date('Y-m-d H:i:s', time() + 3600));
        T::ok(
            $held === false,
            'H6: onHold is model-guarded against self-review',
            'onHold returned ' . var_export($held, true)
        );
    } finally {
        Fixtures::logout();
    }
});

T::group('H3 — correction window');

test('a rejected proof is measured against correction_deadline', function (): void {
    $MEMBER = Fixtures::member();
    $ADMIN  = Fixtures::admin();
    $pid    = Fixtures::product('__CORR__', 100.00, 5);
    $cartId = (int) Cart::getOrCreate($MEMBER)['id'];
    Cart::addItem($cartId, $pid, 1);
    $addr = ['billing_name' => 'C', 'shipping_name' => 'C', 'shipping_address' => '1 St'];

    $orderId = ShopOrder::createFromCart($MEMBER, $cartId, $addr, 'gcash', 'TEST-IDEM-CORR-1');
    ShopOrder::transition($orderId, 'payment_review', 'customer', $MEMBER);
    ShopPaymentProof::add($orderId, 'shop_proofs/corr.jpg', ['reference_no' => 'C-1', 'amount' => 100.0]);
    T::ok(ShopOrder::rejectProof($orderId, $ADMIN, 'unclear_proof'), 'proof rejected');
    T::eq('payment_failed', Fixtures::statusOf($orderId), 'order is payment_failed');

    $corr = ShopOrder::find($orderId)['correction_deadline'];
    T::ok($corr !== null, 'correction_deadline was opened');

    // Move the ORIGINAL payment deadline into the past but leave the
    // correction window in the future. The order must NOT expire.
    db()->prepare("UPDATE shop_orders SET payment_deadline = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?")->execute([$orderId]);
    ShopOrder::expireOverdue(200);
    T::eq(
        'payment_failed',
        Fixtures::statusOf($orderId),
        'H3: correction window is honoured, not the stale payment_deadline'
    );
});

T::group('C7 — e-wallet ref_type ledger (non-shop regression)');

test('an e-wallet package upgrade can credit the admin', function (): void {
    $MEMBER = Fixtures::member(1000.00);
    $ADMIN  = Fixtures::admin();
    $adminBalanceBefore = Ewallet::balance($ADMIN);

    // The exact call that models/User.php:434 makes when an upgrade is paid
    // from the buyer's e-wallet. Before the fix this threw
    // "Data truncated for column 'ref_type'" and rolled the whole upgrade back.
    Ewallet::credit($ADMIN, 250.00, $MEMBER, 'upgrade', 'Upgrade fee from @tester', false);

    T::eq(
        1,
        (int) Fixtures::scalar(
            "SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'upgrade' AND type = 'credit' AND amount = 250.00"
        ),
        'C7: ewallet_ledger accepts ref_type=upgrade and the row was written'
    );
    T::ok(
        abs(Ewallet::balance($ADMIN) - ($adminBalanceBefore + 250.00)) < 0.001,
        'C7: admin credited the upgrade fee'
    );

    // Both allow-lists now come from one const, so debit() accepts 'upgrade'
    // too — it previously rejected it even though credit() allowed it.
    Fixtures::member(1000.00);
    $ok = Ewallet::debit($MEMBER, 100.00, 0, 'upgrade', 'upgrade fee out');
    T::ok($ok, 'D2: debit() accepts ref_type=upgrade from the shared const');

    T::ok(
        !in_array('not_a_real_type', Ewallet::VALID_REF_TYPES, true),
        'D2: VALID_REF_TYPES is a closed set'
    );
    T::throws(
        InvalidArgumentException::class,
        static fn() => Ewallet::credit($ADMIN, 1.00, 0, 'not_a_real_type'),
        'D2: an unknown ref_type is still refused'
    );
});

exit(T::summary());