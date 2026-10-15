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
    T::throws(InvalidArgumentException::class, fn () => ShopOrder::markDelivered($o, $ADMIN, null, null), 'delivered without POD refused');
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

$victimItemId  = (int) Fixtures::scalar("SELECT id FROM cart_items WHERE cart_id = ?", [$victimCart]);
    $attackerItemId = (int) Fixtures::scalar("SELECT id FROM cart_items WHERE cart_id = ?", [$attackerCart]);
    T::ok(
        $victimItemId > 0 && $attackerItemId > 0 && $victimItemId !== $attackerItemId,
        'victim and attacker hold distinct cart lines',
        "victim={$victimItemId} attacker={$attackerItemId}"
    );

    $qtyBefore = (int) Fixtures::scalar("SELECT quantity FROM cart_items WHERE id = ?", [$victimItemId]);
    T::eq(2, $qtyBefore, 'victim cart starts at qty 2');

    // The attacker posts the victim's item_id but only ever has their own cart.
    // Quantity is within stock so the assertion isolates OWNERSHIP rather than
    // tripping the stock guard.
    T::throws(
        InvalidArgumentException::class,
        static fn() => Cart::updateItemQuantity($victimItemId, 7, $attackerCart),
        'C3: updateItemQuantity refuses an item outside the caller\'s cart'
    );
    T::eq(
        $qtyBefore,
        (int) Fixtures::scalar("SELECT quantity FROM cart_items WHERE id = ?", [$victimItemId]),
        'C3: the victim\'s line quantity is unchanged'
    );

    T::ok(
        Cart::removeItemById($victimItemId, $attackerCart) === false,
        'C3: removeItemById reports refusal for an item outside the caller\'s cart'
    );
    T::eq(
        1,
        (int) Fixtures::scalar("SELECT COUNT(*) FROM cart_items WHERE id = ?", [$victimItemId]),
        'C3: the victim\'s cart line is still in place'
    );

    // And the owner can still do both, so the guard did not simply break the cart.
    T::ok(
        Cart::updateItemQuantity($victimItemId, 3, $victimCart),
        'C3: the owner can still set their own quantity'
    );
    T::eq(3, (int) Fixtures::scalar("SELECT quantity FROM cart_items WHERE id = ?", [$victimItemId]), 'C3: owner quantity applied');
    T::ok(
        Cart::removeItemById($victimItemId, $victimCart),
        'C3: the owner can still remove their own line'
    );
    T::eq(0, (int) Fixtures::scalar("SELECT COUNT(*) FROM cart_items WHERE id = ?", [$victimItemId]), 'C3: owner removal applied');
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
    $otherId = Fixtures::admin('t_other_admin');
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

        // Every admin endpoint that touches an order must be guarded in the
        // MODEL, not only in the controller (defect H6).
        T::throws(RuntimeException::class, static fn() => ShopOrder::onHold(
            $orderId, $adminId, 'self_hold', $adminId, date('Y-m-d H:i:s', time() + 3600)
        ), 'H6: onHold is model-guarded against self-review');

        T::throws(RuntimeException::class, static fn() => ShopOrder::cancel(
            $orderId, 'admin', $adminId, 'admin_cancel'
        ), 'H6: admin cancel is model-guarded against self-review');

        T::throws(RuntimeException::class, static fn() => ShopRefund::create(
            $orderId, 100.00, 'admin_cancel', 'ewallet', null, $adminId
        ), 'H6: refund creation is model-guarded against self-review');

        // A DIFFERENT admin must still be able to act — the guard is about
        // self-review, not about blocking admins generally. Open the refund as
        // that other admin, then come back and prove the self-admin still
        // cannot approve or deny it.
        Fixtures::loginAs($otherId, 'admin');
        $refundId = ShopRefund::create($orderId, 100.00, 'admin_cancel', 'ewallet', null, $otherId);
        T::ok($refundId > 0, 'H6: a different admin can still open a refund');

        Fixtures::loginAs($adminId, 'admin');
        T::throws(RuntimeException::class, static fn() => ShopRefund::approve($refundId, $adminId), 'H6: refund approval is model-guarded');
        T::throws(RuntimeException::class, static fn() => ShopRefund::deny($refundId, $adminId), 'H6: refund denial is model-guarded');

        // Settlement can only be attempted once the refund is approved, so the
        // other admin approves it first.
        Fixtures::loginAs($otherId, 'admin');
        T::ok(ShopRefund::approve($refundId, $otherId), 'H6: a different admin can still approve');
        Fixtures::loginAs($adminId, 'admin');
        T::throws(RuntimeException::class, static fn() => ShopRefund::markPaid($refundId, $adminId, 'RECEIPT-SELF'), 'H6: refund settlement is model-guarded');

        Fixtures::loginAs($otherId, 'admin');
        T::ok(
            ShopOrder::onHold($orderId, $otherId, 'stock_shortage', $otherId, date('Y-m-d H:i:s', time() + 3600)),
            'H6: a different admin can still hold the order'
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

    // ── The other direction: a lapsed correction window MUST still cancel ──
    // Without this half, a fix that simply stopped the timer would also pass.
    db()->prepare("UPDATE shop_orders SET correction_deadline = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?")->execute([$orderId]);
    ShopOrder::expireOverdue(200);
    T::eq(
        'cancelled',
        Fixtures::statusOf($orderId),
        'H3: a lapsed correction window still cancels the order'
    );
    $ev = Fixtures::lastEvent($orderId);
    T::ok(
        $ev['actor_type'] === 'system' && $ev['source'] === 'timer',
        'H3: the correction-window cancellation is attributed to the timer'
    );

    // ── A pending order is measured against payment_deadline, as before ──
    $p = Fixtures::pendingOrder($MEMBER, $pid, 1);
    db()->prepare("UPDATE shop_orders SET payment_deadline = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?")->execute([$p]);
    T::ok(ShopOrder::expireOverdue(200) >= 1, 'H3: a pending order past its deadline is still picked up');
    T::eq('cancelled', Fixtures::statusOf($p), 'H3: pending still expires on payment_deadline');

    // ── No deadline at all must never match ──
    $none = Fixtures::pendingOrder($MEMBER, $pid, 1);
    db()->prepare("UPDATE shop_orders SET payment_deadline = NULL WHERE id = ?")->execute([$none]);
    ShopOrder::expireOverdue(200);
    T::eq(
        'pending',
        Fixtures::statusOf($none),
        'H3: an order with no deadline is never expired'
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

T::group('M1 / M2 / M3 — proof race, re-reservation, refund blocking');

test('M1 — a failed proof upload leaves no orphan proof row', function (): void {
    $MEMBER = Fixtures::member();
    $ADMIN  = Fixtures::admin();
    $pid    = Fixtures::product('__M1__', 100.00, 10);
    $cartId = (int) Cart::getOrCreate($MEMBER)['id'];
    Cart::addItem($cartId, $pid, 1);
    $addr = ['billing_name' => 'M', 'shipping_name' => 'M', 'shipping_address' => '1 St'];
    $oid  = ShopOrder::createFromCart($MEMBER, $cartId, $addr, 'gcash', 'TEST-IDEM-M1');

    // Happy path: proof and status move together.
    T::ok(ShopOrder::submitProof($oid, $MEMBER, 'shop_proofs/ok.jpg', ['reference_no' => 'M1-A']), 'M1: proof accepted');
    T::eq('payment_review', Fixtures::statusOf($oid), 'M1: status moved to payment_review');
    T::eq(1, (int) Fixtures::scalar("SELECT COUNT(*) FROM shop_payment_proofs WHERE order_id = ?", [$oid]), 'M1: exactly one proof row');
    T::ok(ShopOrder::lastProofId() !== null, 'M1: lastProofId() reports the written proof');

    // Now the losing side of the race: the timer cancels the order, then the
    // buyer's upload arrives. Before the fix the INSERT had already happened, so
    // the cancelled order kept a proof claiming payment.
    // A fresh cart: createFromCart converted the first one.
    $cart2 = (int) Cart::getOrCreate($MEMBER)['id'];
    Cart::addItem($cart2, $pid, 1);
    $oid2 = ShopOrder::createFromCart($MEMBER, $cart2, $addr, 'gcash', 'TEST-IDEM-M1B');
    ShopOrder::cancel($oid2, 'system', null, 'payment_expired');
    T::ok(
        ShopOrder::submitProof($oid2, $MEMBER, 'shop_proofs/late.jpg', ['reference_no' => 'M1-B']) === false,
        'M1: upload after the timer cancelled is refused'
    );
    T::eq(
        0,
        (int) Fixtures::scalar("SELECT COUNT(*) FROM shop_payment_proofs WHERE order_id = ?", [$oid2]),
        'M1: the refused upload wrote NO proof row (no orphan on a cancelled order)'
    );
});

test('M2 — a re-upload cannot re-reserve goods that are gone', function (): void {
    $MEMBER = Fixtures::member();
    $ADMIN  = Fixtures::admin();
    // Stock of 1, one unit on the order.
    $pid    = Fixtures::product('__M2__', 100.00, 1);
    $cartId = (int) Cart::getOrCreate($MEMBER)['id'];
    Cart::addItem($cartId, $pid, 1);
    $addr = ['billing_name' => 'M2', 'shipping_name' => 'M2', 'shipping_address' => '1 St'];
    $oid  = ShopOrder::createFromCart($MEMBER, $cartId, $addr, 'gcash', 'TEST-IDEM-M2');

    ShopOrder::submitProof($oid, $MEMBER, 'shop_proofs/m2.jpg', ['reference_no' => 'M2-A']);
    T::ok(ShopOrder::rejectProof($oid, $ADMIN, 'unclear_proof'), 'M2: proof rejected');
    T::eq('payment_failed', Fixtures::statusOf($oid), 'M2: order is payment_failed');
    T::eq('released', Fixtures::itemState($oid), 'M2: rejection released the line, so it is sellable again');

    // Somebody else buys the unit while the buyer has their correction window.
    db()->prepare("UPDATE products SET stock = 0 WHERE id = ?")->execute([$pid]);

    T::ok(
        ShopOrder::submitProof($oid, $MEMBER, 'shop_proofs/m2b.jpg', ['reference_no' => 'M2-B']) === false,
        'M2: re-upload refused when the goods are no longer available'
    );
    T::eq('payment_failed', Fixtures::statusOf($oid), 'M2: the order stays payment_failed');
    T::eq('released', Fixtures::itemState($oid), 'M2: the line was NOT silently re-reserved');

    // With stock restored the same upload succeeds — the guard is about stock,
    // not about refusing re-uploads outright.
    db()->prepare("UPDATE products SET stock = 1 WHERE id = ?")->execute([$pid]);
    T::ok(
        ShopOrder::submitProof($oid, $MEMBER, 'shop_proofs/m2c.jpg', ['reference_no' => 'M2-C']),
        'M2: re-upload succeeds once the goods are available again'
    );
    T::eq('reserved', Fixtures::itemState($oid), 'M2: the line is re-reserved');
});

test('M3 — an APPROVED refund still blocks completion', function (): void {
    $MEMBER = Fixtures::member();
    $ADMIN  = Fixtures::admin();
    $pid    = Fixtures::product('__M3__', 100.00, 10);
    $oid    = Fixtures::pendingOrder($MEMBER, $pid, 1);
    foreach (['paid', 'packing', 'ready_to_ship', 'shipped', 'out_for_delivery', 'delivered'] as $to) {
        ShopOrder::transition($oid, $to, 'admin', $ADMIN);
    }
    T::eq('delivered', Fixtures::statusOf($oid), 'M3: order is delivered');

    $refundId = ShopRefund::create($oid, 100.00, 'admin_cancel', 'ewallet', null, $ADMIN);
    T::ok(ShopRefund::hasOpen($oid), 'M3: an open refund blocks completion');

    T::ok(ShopRefund::approve($refundId, $ADMIN), 'M3: refund approved');
    T::ok(
        ShopRefund::hasOpen($oid),
        'M3: an APPROVED refund still blocks completion (not just an open one)'
    );
    T::ok(
        ShopOrder::canMemberAct(ShopOrder::find($oid), 'confirm') === false,
        'M3: the Confirm-receipt button is hidden while a refund is approved'
    );
    T::ok(
        ShopOrder::confirmReceipt($oid, $MEMBER) === false,
        'M3: the buyer cannot confirm receipt over an approved refund'
    );

    db()->prepare("UPDATE shop_orders SET completion_due_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ?")->execute([$oid]);
    ShopOrder::autoComplete(200);
    T::eq(
        'delivered',
        Fixtures::statusOf($oid),
        'M3: the auto-complete timer also refuses while a refund is approved'
    );

    // Once the refund is settled the block lifts.
    ShopRefund::markPaid($refundId, $ADMIN, 'RECEIPT-M3');
    T::ok(!ShopRefund::hasOpen($oid), 'M3: a processed refund no longer blocks');
    T::ok(ShopOrder::confirmReceipt($oid, $MEMBER), 'M3: the buyer can now confirm receipt');
    T::eq('completed', Fixtures::statusOf($oid), 'M3: order completes');
});

T::group('Phase 7 — input bounds, resilience, N+1');

test('H5 — per_page is bounded by an allow-list', function (): void {
    $cases = [
        ['input' => '10000000', 'expect' => 10,  'why' => 'a huge value falls back to the default'],
        ['input' => '99999999999999999999', 'expect' => 10, 'why' => 'integer overflow falls back'],
        ['input' => '-5', 'expect' => 10,  'why' => 'negative falls back'],
        ['input' => 'abc', 'expect' => 10,  'why' => 'non-numeric falls back'],
        ['input' => '25', 'expect' => 25,  'why' => 'a listed value is honoured'],
        ['input' => '50', 'expect' => 50,  'why' => 'a listed value is honoured'],
        ['input' => '100', 'expect' => 100, 'why' => 'the maximum is honoured'],
        ['input' => '75', 'expect' => 10,  'why' => 'an unlisted value falls back'],
        ['input' => null, 'expect' => 10,  'why' => 'absent uses the default'],
    ];
    foreach ($cases as $c) {
        $_GET = $c['input'] === null ? [] : ['per_page' => $c['input']];
        $shown = $c['input'] === null ? '(absent)' : "'{$c['input']}'";
        T::eq($c['expect'], per_page(), "H5: per_page({$shown}) - {$c['why']}");
    }
    $_GET = [];

    // Nothing may exceed the largest allowed value.
    $_GET = ['per_page' => (string) PHP_INT_MAX];
    T::ok(per_page() <= 100, 'H5: per_page can never exceed the allow-list ceiling');
    $_GET = [];
});

test('Product::delete is refused, not fatal, when a cart references it', function (): void {
    $member = Fixtures::member();
    $owner  = Fixtures::member();
    $pid    = Fixtures::product('__DEL__', 10.00, 5);

    // No references: deletion succeeds.
    T::ok(Product::delete($pid), 'a product with no references deletes');

    // In someone's cart: refused, no exception.
    $pid2 = Fixtures::product('__DEL2__', 10.00, 5);
    $cartId = (int) Cart::getOrCreate($member)['id'];
    Cart::addItem($cartId, $pid2, 1);

    $threw = null;
    try {
        $ok = Product::delete($pid2);
    } catch (Throwable $e) {
        $threw = get_class($e);
        $ok = false;
    }
    T::ok($threw === null, 'a product in a cart does not raise a PDOException', (string) $threw);
    T::ok($ok === false, 'a product in a cart is refused');
    T::ok(Product::find($pid2) !== null, 'the referenced product still exists');

    // In an order: refused too.
    $pid3 = Fixtures::product('__DEL3__', 10.00, 5);
    Fixtures::pendingOrder($owner, $pid3, 1);
    T::ok(Product::delete($pid3) === false, 'a product in a shop order is refused');

    // A product nobody references still deletes after all that.
    $pid4 = Fixtures::product('__DEL4__', 10.00, 5);
    T::ok(Product::delete($pid4), 'an unreferenced product still deletes normally');
    T::ok(Product::find($pid4) === null, 'the deleted product is gone');
});

test('M7 — batched availability matches the per-product calculation', function (): void {
    $a = Fixtures::product('__A__', 10.00, 5);
    $b = Fixtures::product('__B__', 10.00, 5);
    $c = Fixtures::product('__C__', 10.00, 0);   // sold out

    $holder = Fixtures::member();
    Fixtures::pendingOrder($holder, $a, 3);       // reserves 3 of A

    $map = Product::availableStockMap([$a, $b, $c]);

    T::ok(array_key_exists($a, $map), 'M7: the map covers a reserved product');
    T::ok(array_key_exists($b, $map), 'M7: the map covers a plain product');
    T::ok(array_key_exists($c, $map), 'M7: the map covers a sold-out product');

    foreach ([$a, $b, $c] as $id) {
        T::eq(
            Product::availableStock($id),
            $map[$id],
            "M7: availableStockMap agrees with availableStock for product {$id}"
        );
    }
    T::eq(2, $map[$a], 'M7: three reserved units leave two available');
    T::eq(0, $map[$c], 'M7: a zero-stock product reports zero');
    T::eq([], Product::availableStockMap([]), 'M7: an empty id list short-circuits');
    T::eq([], Product::availableStockMap([999999]), 'M7: an unknown id is simply absent');
});

test('Phase 7 — payment references are normalised at the boundary', function (): void {
    $MEMBER = Fixtures::member();
    $ADMIN  = Fixtures::admin();
    $pid    = Fixtures::product('__REF7__', 100.00, 10);
    $cartId = (int) Cart::getOrCreate($MEMBER)['id'];
    Cart::addItem($cartId, $pid, 1);
    $addr = ['billing_name' => 'R7', 'shipping_name' => 'R7', 'shipping_address' => '1 St'];
    $oid  = ShopOrder::createFromCart($MEMBER, $cartId, $addr, 'gcash', 'TEST-IDEM-R7');

    ShopOrder::submitProof($oid, $MEMBER, 'shop_proofs/r7.jpg', [
        'reference_no' => "  gc-123_ab'; DROP TABLE users;--  ",
        'amount' => 100.0,
    ]);

    $stored = (string) Fixtures::scalar("SELECT reference_no FROM shop_payment_proofs WHERE order_id = ?", [$oid]);
    T::ok($stored !== '', 'the reference survived as a non-empty value');
    T::ok(!str_contains($stored, ';'), 'the reference carries no statement separator');
    T::ok(!str_contains($stored, ' '), 'the reference carries no whitespace');
    T::ok(
        (bool) preg_match('/^[A-Z0-9_-]+$/', $stored),
        'the reference is confined to the safe character set',
        $stored
    );
    T::ok(mb_strlen($stored) <= 40, 'the reference fits the column width', (string) mb_strlen($stored));

    // An input with nothing usable is rejected rather than silently emptied.
    T::throws(
        InvalidArgumentException::class,
        static fn() => ShopOrder::markPaid($oid, 'admin', 1, 100.0, "!!!"),
        'a reference with no usable characters is refused'
    );

    // Oversized input is truncated to the column width rather than 500-ing.
    $long = str_repeat('A', 200);
    T::eq(40, mb_strlen(clean_reference($long, 40)), 'an oversized reference is truncated to 40');
});

test('a database error is not misreported as a stock conflict', function (): void {
    // The narrow catch means only StockConflictException takes the audit-event
    // path; anything else propagates. Assert the class hierarchy that made the
    // old string-compare catch dangerous is no longer relied upon.
    T::ok(
        is_subclass_of(StockConflictException::class, RuntimeException::class),
        'StockConflictException is still a RuntimeException'
    );
    T::ok(
        !is_subclass_of(PDOException::class, StockConflictException::class),
        'a PDOException can never be mistaken for a stock conflict'
    );

    $src = (string) file_get_contents(REPO_ROOT . '/models/ShopOrder.php');
    // Match a real catch clause, not the explanatory comment that mentions one.
    T::ok(
        !preg_match('/^\s*}\s*catch\s*\(\s*RuntimeException/m', $src),
        'transition() no longer catches bare RuntimeException'
    );
    T::ok(
        (bool) preg_match('/catch\s*\(\s*StockConflictException\s+\$e\s*\)/', $src),
        'transition() catches StockConflictException specifically'
    );
    T::ok(
        !str_contains($src, "getMessage() === 'stock_conflict'"),
        'transition() no longer string-compares an exception message'
    );
});

T::group('Phase 6 — money-flow atomicity');

test('transition() joins a caller transaction instead of nesting one', function (): void {
    $m  = Fixtures::member();
    $oid = Fixtures::pendingOrder($m, Fixtures::product('__TX1__', 100.00, 5), 1);

    $pdo = db();
    T::ok(!$pdo->inTransaction(), 'no transaction is open before the test');

    $pdo->beginTransaction();
    // PDO throws "There is already an active transaction" on a nested begin.
    // Before Phase 6.1 this line was a fatal for any caller that already had a
    // unit open, which is precisely what placeOrder() needs.
    $threw = null;
    try {
        ShopOrder::markPaid($oid, 'system', null);
    } catch (Throwable $e) {
        $threw = $e->getMessage();
    }
    T::ok($threw === null, 'markPaid inside an open transaction does not fatal', (string) $threw);
    T::ok($pdo->inTransaction(), 'the caller still owns the transaction afterwards');
    $pdo->rollBack();
    T::ok(!$pdo->inTransaction(), 'the transaction closes cleanly');
});

test('a refusal inside a caller transaction does not discard the caller work', function (): void {
    $m   = Fixtures::member();
    $pid = Fixtures::product('__TX2__', 100.00, 5);
    $oid = Fixtures::pendingOrder($m, $pid, 1);

    $pdo = db();
    $pdo->beginTransaction();
    // Illegal source status makes the transition refuse (false, not throw).
    $pdo->prepare("UPDATE shop_orders SET status = 'cancelled' WHERE id = ?")->execute([$oid]);
    $ok = ShopOrder::markPaid($oid, 'system', null);
    T::ok($ok === false, 'markPaid refuses from an illegal source status');
    // The refusal rolled nothing back, because the transaction is not ours to
    // end. If it had, the caller's own uncommitted work would be gone.
    T::ok(
        $pdo->inTransaction(),
        'the refusal leaves the caller transaction open'
    );
    T::eq(
        'cancelled',
        Fixtures::statusOf($oid),
        "the caller's uncommitted change is still visible"
    );
    $pdo->rollBack();
});

test('failed e-wallet checkout persists nothing: no order, no debit, no cleared cart', function (): void {
    $m     = Fixtures::member(5000.00);
    $pid   = Fixtures::product('__TX3__', 250.00, 10);
    $cart  = Fixtures::cartWith($m, $pid, 2);
    $addr  = ['billing_name' => 'TX', 'shipping_name' => 'TX', 'shipping_address' => '1 St'];
    $before = (float) Fixtures::scalar('SELECT ewallet_balance FROM users WHERE id = ?', [$m]);
    $pdo   = db();

    // Replicate placeOrder()'s unit exactly: create + debit + paid, one
    // transaction. The paid transition is forced to refuse by parking the
    // order in a status the matrix will not move from.
    $pdo->beginTransaction();
    $orderId = ShopOrder::createFromCart($m, $cart, $addr, 'ewallet', 'IDEM-TX3');
    $pdo->prepare("UPDATE shop_orders SET status = 'cancelled' WHERE id = ?")->execute([$orderId]);
    $total = (float) Fixtures::scalar('SELECT total_price FROM shop_orders WHERE id = ?', [$orderId]);
    $debit = Ewallet::debitInternal($m, $total, $orderId, 'shop_order', 'tx');
    T::ok($debit, 'the debit itself succeeds');
    T::ok(
        ShopOrder::markPaid($orderId, 'system', null) === false,
        'the paid transition then refuses'
    );
    // This is the controller's catch block.
    $pdo->rollBack();

    T::eq(
        0,
        (int) Fixtures::scalar('SELECT COUNT(*) FROM shop_orders WHERE id = ?', [$orderId]),
        'the order row is gone'
    );
    T::eq(
        0,
        (int) Fixtures::scalar(
            "SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'shop_order' AND reference_id = ?",
            [$orderId]
        ),
        'no e-wallet debit row survived'
    );
    T::eq(
        $before,
        (float) Fixtures::scalar('SELECT ewallet_balance FROM users WHERE id = ?', [$m]),
        'the balance is exactly what it was'
    );
    T::eq(
        2,
        (int) Fixtures::scalar(
            'SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE cart_id = ? AND product_id = ?',
            [$cart, $pid]
        ),
        'the cart still holds both units'
    );
    T::eq(10, Fixtures::stockOf($pid), 'stock is untouched');
});

test('placeOrder() marks paid BEFORE it commits', function (): void {
    // The controller cannot be invoked here — redirect() is `never`/exit — so
    // the ordering that caused the defect is asserted structurally. The old
    // code read commit(); markPaid(); cart-clear, which left money debited
    // against a pending order whenever markPaid threw.
    $src  = (string) file_get_contents(REPO_ROOT . '/controllers/MemberController.php');
    $fn   = strpos($src, 'public function placeOrder()');
    $next = strpos($src, 'public function ', ($fn ?: 0) + 10);
    $body = substr($src, (int) $fn, (int) ($next - $fn));

    // Scope to the e-wallet branch ONLY. An earlier version of this test
    // searched the whole method, which passed even with the original bug
    // because placeOrder() has a second, unrelated $pdo->commit() further down
    // for the non-e-wallet path — so "some commit comes after markPaid" was
    // true no matter where the money path actually committed.
    $b0 = strpos($body, "if (\$method === 'ewallet')");
    $b1 = strpos($body, "'Order placed and paid", (int) $b0);
    T::ok($b0 !== false && $b1 !== false, 'the e-wallet branch can be located');
    $branch = substr($body, (int) $b0, (int) $b1 - (int) $b0);

    $pay   = strpos($branch, 'ShopOrder::markPaid($orderId');
    $clear = strpos($branch, 'Cart::clear(');
    $com   = strpos($branch, '$pdo->commit();', $pay === false ? 0 : $pay);

    T::ok($pay !== false, 'the e-wallet branch calls markPaid');
    T::ok($com !== false, 'the e-wallet branch commits');
    T::ok($pay < $com, 'markPaid runs BEFORE the commit, so debit and paid are one atomic unit');
    T::ok($clear !== false && $clear < $com, 'the cart is cleared before the commit, not after it');

    // No commit may sit between the debit and the paid transition — that gap
    // is the entire defect.
    $debit = strpos($branch, 'Ewallet::debitInternal(');
    T::ok($debit !== false, 'the e-wallet branch debits');
    $gap = substr($branch, (int) $debit, (int) $pay - (int) $debit);
    T::ok(
        !str_contains($gap, '$pdo->commit();'),
        'nothing commits between the debit and the paid transition'
    );

    // A refused payment must not leave the buyer thinking funds moved.
    T::ok(
        str_contains($branch, 'No funds have been taken.'),
        'a refused payment tells the buyer no funds moved'
    );
});

test('M4 — proof of delivery is written inside the transition, not after it', function (): void {
    $m    = Fixtures::member();
    $oid  = Fixtures::shippedOrder($m, Fixtures::product('__POD__', 100.00, 5));
    $ship = (int) Fixtures::scalar(
        "SELECT id FROM shop_shipments WHERE order_id = ? AND state = 'active' ORDER BY seq DESC LIMIT 1",
        [$oid]
    );
    T::ok($ship > 0, 'the order has an active shipment');

    T::ok(
        ShopOrder::markDelivered($oid, Fixtures::admin(), 'J. Doe', 'shop_proofs/pod.jpg'),
        'the delivered transition is accepted'
    );
    T::eq('delivered', Fixtures::statusOf($oid), 'the order is delivered');

    $pod = Fixtures::scalar('SELECT pod_receiver FROM shop_shipments WHERE id = ?', [$ship]);
    T::eq('J. Doe', (string) $pod, 'the POD receiver is stored on the shipment');

    // Structural: the write must no longer live in markDelivered() after the
    // transition returns. Ordering is the whole defect, so it is asserted.
    $src = (string) file_get_contents(REPO_ROOT . '/models/ShopOrder.php');
    $fn  = strpos($src, 'public static function markDelivered');
    $nx  = strpos($src, 'public static function reship', $fn);
    $body = substr($src, (int) $fn, (int) $nx - (int) $fn);

    T::ok(
        !str_contains($body, 'ShopShipment::savePod('),
        'markDelivered() no longer calls savePod itself'
    );
    T::ok(
        !str_contains($body, 'ShopShipment::activeFor('),
        'markDelivered() no longer looks the shipment up after the fact'
    );
    T::ok(
        str_contains($body, "'pod'         => [")
        || (bool) preg_match("/'pod'\s*=>\s*\[/", $body),
        'markDelivered() hands the POD to the transition instead'
    );
    T::ok(
        str_contains($src, "\$to === 'delivered' && isset(\$opts['pod'])"),
        'the POD write lives in the transition side-effect hook'
    );
});

test('ShopRefund::markPaid is one transaction: credit and status move together', function (): void {
    // Phase 6.3 asked this be confirmed, not rewritten: the method already
    // wraps both statements. What had to be re-verified is that the credit
    // cannot escape the transaction.
    $src = (string) file_get_contents(REPO_ROOT . '/models/Ewallet.php');
    $fn  = strpos($src, 'public static function credit');
    $nx  = strpos($src, 'public static function ', ($fn ?: 0) + 10);
    $credit = substr($src, (int) $fn, (int) $nx - (int) $fn);

    T::ok(
        !str_contains($credit, 'beginTransaction'),
        'Ewallet::credit() opens no transaction of its own, so it cannot commit early'
    );
    T::ok(
        !str_contains($credit, '->commit('),
        'Ewallet::credit() never commits the caller work'
    );
});

test('a refund cannot be paid twice', function (): void {
    $m   = Fixtures::member();
    $oid = Fixtures::cancelledOrder($m, Fixtures::product('__RF1__', 300.00, 5), 1);
    $admin = Fixtures::admin();

    $rid = Fixtures::refund($oid, 300.00, 'ewallet');
    $before = (float) Fixtures::scalar('SELECT ewallet_balance FROM users WHERE id = ?', [$m]);

    T::ok(ShopRefund::approve($rid, $admin), 'the refund is approved');
    T::ok(ShopRefund::markPaid($rid, $admin, 'RCPT-1'), 'the first payout succeeds');

    $after = (float) Fixtures::scalar('SELECT ewallet_balance FROM users WHERE id = ?', [$m]);
    T::eq($before + 300.00, $after, 'the wallet was credited exactly once');

    // Every replay must be a no-op.
    T::ok(ShopRefund::markPaid($rid, $admin, 'RCPT-1') === false, 'the second payout is refused');
    T::ok(ShopRefund::markPaid($rid, $admin, 'RCPT-2') === false, 'a third attempt is refused too');

    T::eq(
        $after,
        (float) Fixtures::scalar('SELECT ewallet_balance FROM users WHERE id = ?', [$m]),
        'the balance is unchanged by the replays'
    );
    T::eq(
        1,
        (int) Fixtures::scalar(
            "SELECT COUNT(*) FROM ewallet_ledger WHERE user_id = ? AND type = 'credit' AND ref_type = 'shop_order' AND amount = 300.00",
            [$m]
        ),
        'exactly one credit row exists for the refund'
    );
    T::eq('processed', (string) Fixtures::scalar('SELECT status FROM shop_refunds WHERE id = ?', [$rid]), 'the refund is processed');

    // WHAT THIS DOES NOT PROVE — stated because the distinction decides whether
    // the guard below is load-bearing.
    //
    // The sequential replays above are caught by the early
    // `status !== 'approved'` check, which runs before the transaction opens.
    // Disabling the rowCount guard entirely leaves every assertion above
    // PASSING — verified by mutation. So those assertions do NOT exercise the
    // race guard.
    //
    // The guard that actually matters is the concurrent one: two admins opening
    // the same approved refund at the same instant. That is defended by
    //   UPDATE ... WHERE id = ? AND status = 'approved'
    // plus rowCount checking, so the loser's UPDATE matches zero rows and its
    // transaction — including the credit already issued inside it — rolls back.
    // A single-process test cannot reproduce it: with one connection the loser
    // always re-reads 'processed' and short-circuits at the early check. It is
    // asserted structurally below instead.
    $src = (string) file_get_contents(REPO_ROOT . '/models/ShopRefund.php');
    T::ok(
        str_contains($src, "WHERE id = ? AND status = 'approved'"),
        'the payout UPDATE is conditional on the refund still being approved'
    );
    T::ok(
        (bool) preg_match('/rowCount\(\)\s*!==\s*1/', $src),
        'a zero-row UPDATE rolls back rather than reporting success'
    );
    T::ok(
        str_contains($src, "\$pdo->beginTransaction();"),
        'the credit and the status move inside one transaction'
    );
});

test('M6 — the refund payout route comes from the method enum, not free text', function (): void {
    $admin = Fixtures::admin();

    // A cash payout whose destination text mentions a wallet must NOT credit.
    $m1 = Fixtures::member();
    $o1 = Fixtures::cancelledOrder($m1, Fixtures::product('__RF2__', 200.00, 5), 1);
    $r1 = Fixtures::refund($o1, 200.00, 'cash', 'Cash payout to GCash wallet later');
    $b1 = (float) Fixtures::scalar('SELECT ewallet_balance FROM users WHERE id = ?', [$m1]);
    T::ok(ShopRefund::approve($r1, $admin), 'cash refund approved');
    T::ok(ShopRefund::markPaid($r1, $admin, 'RCPT-CASH'), 'the cash payout is processed');
    T::eq(
        $b1,
        (float) Fixtures::scalar('SELECT ewallet_balance FROM users WHERE id = ?', [$m1]),
        'free text mentioning a wallet no longer triggers a credit'
    );
    T::eq(0, (int) Fixtures::scalar("SELECT COUNT(*) FROM ewallet_ledger WHERE user_id = ? AND type = 'credit' AND amount = 200.00", [$m1]), 'no ledger credit for a cash payout');

    // An e-wallet refund with unrelated destination text still credits.
    $m2 = Fixtures::member();
    $o2 = Fixtures::cancelledOrder($m2, Fixtures::product('__RF3__', 200.00, 5), 1);
    $r2 = Fixtures::refund($o2, 200.00, 'ewallet', 'back to GCash');
    $b2 = (float) Fixtures::scalar('SELECT ewallet_balance FROM users WHERE id = ?', [$m2]);
    T::ok(ShopRefund::approve($r2, $admin), 'e-wallet refund approved');
    T::ok(ShopRefund::markPaid($r2, $admin, 'RCPT-WALLET'), 'the e-wallet payout is processed');
    T::eq(
        $b2 + 200.00,
        (float) Fixtures::scalar('SELECT ewallet_balance FROM users WHERE id = ?', [$m2]),
        'the e-wallet method still credits'
    );

    // And the string heuristic is gone from the source entirely. (Comments are
    // stripped first: the docblock above explains what was removed and quoting
    // it verbatim would make this assertion fail on its own documentation.)
    $src  = (string) file_get_contents(REPO_ROOT . '/models/ShopRefund.php');
    $code = (string) preg_replace('#/\*.*?\*/#s', '', $src);
    $code = (string) preg_replace('#//[^\n]*#', '', $code);
    T::ok(
        !str_contains($code, "str_contains(\$destination"),
        'the free-text wallet check is removed'
    );

    // The payout route must be an explicit, typed choice — and the choices the
    // UI offers must be exactly the ones the column accepts. Offering a value
    // outside the ENUM is "Data truncated for column 'method'", the same 500
    // shape as defect H4.
    $enum = (string) Fixtures::scalar(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_refunds' AND COLUMN_NAME = 'method'"
    );
    T::ok($enum !== '', 'the refund method column exists');
    preg_match_all("/'([a-z_]+)'/", $enum, $em);
    $enumValues = $em[1];
    T::ok(
        $enumValues === ShopRefund::METHODS,
        'ShopRefund::METHODS matches the column ENUM exactly',
        'enum=' . implode(',', $enumValues) . ' const=' . implode(',', ShopRefund::METHODS)
    );

    $form = (string) file_get_contents(REPO_ROOT . '/views/admin/shop_order.php');
    preg_match('/<select[^>]*name="method".*?<\/select>/s', $form, $sel);
    T::ok(!empty($sel[0]), 'the admin refund form offers a payout route');
    preg_match_all('/value="([a-z_]+)"/', $sel[0] ?? '', $opts);
    $formValues = $opts[1];
    sort($enumValues);
    sort($formValues);
    T::ok(
        $formValues === $enumValues,
        'every payout route the form offers is one the column accepts',
        'form=' . implode(',', $formValues)
    );

    // And the route must actually reach the row — not be silently defaulted.
    $mm = Fixtures::member();
    $oo = Fixtures::cancelledOrder($mm, Fixtures::product('__MM__', 50.00, 5), 1);
    $rr = ShopRefund::create($oo, 50.0, 'admin_cancel', 'GCash account 0917', null, null, 'manual');
    T::eq(
        'manual',
        (string) Fixtures::scalar('SELECT method FROM shop_refunds WHERE id = ?', [$rr]),
        'a non-wallet payout route is stored, not coerced to ewallet'
    );
    $beforeBal = (float) Fixtures::scalar('SELECT ewallet_balance FROM users WHERE id = ?', [$mm]);
    $aid = Fixtures::admin();
    ShopRefund::approve($rr, $aid);
    T::ok(ShopRefund::markPaid($rr, $aid, 'MM-1'), 'the manual payout is processed');
    T::eq(
        $beforeBal,
        (float) Fixtures::scalar('SELECT ewallet_balance FROM users WHERE id = ?', [$mm]),
        'a manual-route refund does not credit the wallet'
    );

    T::throws(
        InvalidArgumentException::class,
        static fn() => ShopRefund::create($oo, 10.0, 'admin_cancel', null, null, null, 'gcash'),
        'an unknown payout route is refused rather than truncated by MySQL'
    );

    // The controller wiring, which is where the gap actually lived. QA-11 calls
    // ShopRefund::create() directly, so it cannot catch a controller that
    // forgets to forward the route — and that omission is precisely the bug:
    // every UI-created refund silently defaulted to an e-wallet credit.
    $ctrl = (string) file_get_contents(REPO_ROOT . '/controllers/AdminController.php');
    $fn   = strpos($ctrl, 'public function orderRefund()');
    $nx   = strpos($ctrl, 'public function ', ($fn ?: 0) + 10);
    $body = substr($ctrl, (int) $fn, (int) $nx - (int) $fn);

    T::ok(
        str_contains($body, "\$_POST['method']"),
        'orderRefund() forwards the payout route from the form'
    );
    $call = strpos($body, 'ShopRefund::create(');
    T::ok($call !== false, 'orderRefund() creates a refund');
    // Bound the search by the call's own closing paren, not a fixed byte count:
    // an explanatory comment inside the argument list is longer than any window
    // worth guessing at.
    $close = strpos($body, ');', (int) $call);
    $seg   = substr($body, (int) $call, ($close === false ? strlen($body) : (int) $close) - (int) $call);
    T::ok(
        str_contains($seg, "\$_POST['method']"),
        'the route is passed INTO create(), not merely read nearby'
    );
    T::ok(
        !str_contains($seg, "'ewallet');"),
        'the call does not hardcode the route back to the column default'
    );
});

T::group('Phase 6 — ledger reconciliation and Strategy C');

test('every shop order reconciles against the e-wallet ledger', function (): void {
    // Phase 6.6.
    //
    // The sweep must build its own fixtures: test() calls resetState() before
    // AND after every case, so a "sweep everything at the end" test would find
    // an empty shop_orders table and pass by asserting nothing. That is the
    // failure mode of a reconciliation check — it always agrees, because it
    // never looks at anything.
    $admin = Fixtures::admin();
    $paidMember = Fixtures::member(5000.00);

    // 1. e-wallet checkout: exactly one debit for exactly the order total.
    $p1 = Fixtures::product('__RC1__', 120.00, 5);
    $c1 = Fixtures::cartWith($paidMember, $p1, 2);
    $addr = ['billing_name' => 'RC', 'shipping_name' => 'RC', 'shipping_address' => '1 St'];
    $o1 = ShopOrder::createFromCart($paidMember, $c1, $addr, 'ewallet', 'IDEM-RC1');
    $total1 = (float) Fixtures::scalar('SELECT total_price FROM shop_orders WHERE id = ?', [$o1]);
    T::ok(Ewallet::debitInternal($paidMember, $total1, $o1, 'shop_order', 'rc'), 'the e-wallet debit is taken');
    T::ok(ShopOrder::markPaid($o1, 'system', null), 'the e-wallet order is marked paid');

    // 2. gcash checkout: money never touches the wallet.
    $o2 = Fixtures::pendingOrder($paidMember, Fixtures::product('__RC2__', 90.00, 5), 1);

    // 3. a refunded e-wallet order: exactly one credit.
    $m3 = Fixtures::member();
    $o3 = Fixtures::cancelledOrder($m3, Fixtures::product('__RC3__', 80.00, 5), 1);
    $r3 = Fixtures::refund($o3, 80.00, 'ewallet');
    ShopRefund::approve($r3, $admin);
    ShopRefund::markPaid($r3, $admin, 'RCPT-A');

    // 4. a refunded cash order: no credit at all.
    $m4 = Fixtures::member();
    $o4 = Fixtures::cancelledOrder($m4, Fixtures::product('__RC4__', 70.00, 5), 1);
    $r4 = Fixtures::refund($o4, 70.00, 'cash', 'paid out at the branch');
    ShopRefund::approve($r4, $admin);
    ShopRefund::markPaid($r4, $admin, 'RCPT-B');

    // Now sweep every order that exists.
    $orders = db()->query("SELECT id, total_price, payment_method FROM shop_orders ORDER BY id")->fetchAll();
    T::ok(count($orders) >= 4, 'the sweep has orders to inspect', 'count=' . count($orders));

    $problems = [];
    foreach ($orders as $o) {
        $oid   = (int) $o['id'];
        $debit = (float) Fixtures::scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM ewallet_ledger
              WHERE ref_type = 'shop_order' AND reference_id = ? AND type = 'debit'",
            [$oid]
        );
        $debitCount = (int) Fixtures::scalar(
            "SELECT COUNT(*) FROM ewallet_ledger
              WHERE ref_type = 'shop_order' AND reference_id = ? AND type = 'debit'",
            [$oid]
        );
        $credit = (float) Fixtures::scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM ewallet_ledger
              WHERE ref_type = 'shop_order' AND reference_id = ? AND type = 'credit'",
            [$oid]
        );

        if ($o['payment_method'] === 'ewallet') {
            if ($debitCount !== 1) {
                $problems[] = "order {$oid}: {$debitCount} debits, expected 1";
            }
            if (abs($debit - (float) $o['total_price']) >= 0.005) {
                $problems[] = "order {$oid}: debited {$debit}, total is {$o['total_price']}";
            }
        } elseif ($debitCount !== 0) {
            $problems[] = "order {$oid}: {$o['payment_method']} order took a wallet debit";
        }

        // Credits must be fully explained by processed e-wallet refunds.
        $expected = (float) Fixtures::scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM shop_refunds
              WHERE order_id = ? AND status = 'processed' AND method = 'ewallet'",
            [$oid]
        );
        if (abs($credit - $expected) >= 0.005) {
            $problems[] = "order {$oid}: credited {$credit}, processed e-wallet refunds total {$expected}";
        }
    }
    T::ok($problems === [], 'every order reconciles', implode('; ', $problems));
});

test('Strategy C — shop code never reaches into the commission engine', function (): void {
    // Scoped to SHOP code. An earlier version scanned every model and
    // controller and flagged AdminController, which legitimately drives the
    // commission admin pages. Strategy C forbids the shop from borrowing the
    // binary engine, not the reverse.
    $shopFiles = [];
    foreach (glob(REPO_ROOT . '/models/*.php') ?: [] as $f) {
        $b = basename($f);
        if (str_starts_with($b, 'Shop') || $b === 'Cart.php' || $b === 'Product.php') {
            $shopFiles[$b] = (string) file_get_contents($f);
        }
    }

    // Controllers contribute only their shop methods.
    foreach (glob(REPO_ROOT . '/controllers/*.php') ?: [] as $f) {
        $src = (string) file_get_contents($f);
        if (preg_match_all('/function\s+(\w*[sS]hop\w*)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$name, $off]) {
                $end = strpos($src, "\n    public function ", $off);
                $shopFiles[basename($f) . '::' . $name] = substr(
                    $src,
                    (int) $off,
                    ($end === false ? strlen($src) : (int) $end) - (int) $off
                );
            }
        }
    }
    T::ok(count($shopFiles) > 5, 'shop surface was located', 'files=' . count($shopFiles));

    $hits = [];
    foreach ($shopFiles as $name => $src) {
        if (preg_match('/\bCommission::/', $src)) {
            $hits[] = $name;
        }
    }
    T::ok($hits === [], 'no shop code calls Commission::', implode(', ', $hits));

    $pv = [];
    foreach ($shopFiles as $name => $src) {
        if (preg_match('/PV[1-4]|point_value|pair_value/i', $src)) {
            $pv[] = $name;
        }
    }
    T::ok($pv === [], 'no PV / point-value strings in shop code', implode(', ', $pv));
});

test('Strategy C — shop_orders.status is only written through the state machine', function (): void {
    $offenders = [];
    foreach (array_merge(
        glob(REPO_ROOT . '/models/*.php') ?: [],
        glob(REPO_ROOT . '/controllers/*.php') ?: []
    ) as $f) {
        $base = basename($f);
        if ($base === 'ShopOrder.php') continue; // transition() / resume() live here

        $src = (string) file_get_contents($f);
        // A direct write of the status column outside the state machine.
        if (preg_match('/UPDATE\s+shop_orders\s+SET\s+(?:[^;]*,\s*)?status\s*=/i', $src)) {
            $offenders[] = $base;
        }
    }
    T::ok(
        $offenders === [],
        'no direct shop_orders.status UPDATE outside ShopOrder.php',
        implode(', ', $offenders)
    );

    $order = (string) file_get_contents(REPO_ROOT . '/models/ShopOrder.php');
    T::ok(
        substr_count($order, 'UPDATE shop_orders') >= 1,
        'ShopOrder.php still owns the conditional status write'
    );
});

test('referenceMap explains why a product cannot be deleted', function (): void {
    $m = Fixtures::member();
    $free  = Fixtures::product('__REF_FREE__', 50.00, 5);
    $bought = Fixtures::product('__REF_ORD__', 50.00, 5);
    $carts = Fixtures::product('__REF_CART__', 50.00, 5);

    // Batched for the whole "page" in one call, the way the admin view uses it.
    $map = Product::referenceMap([$free, $bought, $carts]);
    T::ok($map === [], 'nothing is referenced yet, so the map is empty');

    Fixtures::cancelledOrder($m, $bought, 1);
    $cartId = (int) Cart::getOrCreate($m)['id'];
    Cart::addItem($cartId, $carts, 1);

    $map = Product::referenceMap([$free, $bought, $carts]);
    T::ok(!array_key_exists($free, $map), 'an unreferenced product is absent from the map');
    T::eq(1, $map[$bought]['orders'] ?? 0, 'a bought product reports one order line');
    T::eq(0, $map[$bought]['carts'] ?? 0, 'a bought product reports no cart lines');
    T::eq(0, $map[$carts]['orders'] ?? 0, 'a cart-only product reports no order lines');
    T::eq(1, $map[$carts]['carts'] ?? 0, 'a cart-only product reports one cart line');

    // Both at once — the remedy text differs, so the map must distinguish them.
    $both = Fixtures::product('__REF_BOTH__', 50.00, 5);
    Fixtures::cancelledOrder($m, $both, 1);
    Cart::addItem($cartId, $both, 1);
    $map = Product::referenceMap([$both]);
    T::eq(1, $map[$both]['orders'] ?? 0, 'a product in both reports its order');
    T::eq(1, $map[$both]['carts'] ?? 0, 'a product in both reports its cart');

    T::eq([], Product::referenceMap([]), 'an empty id list short-circuits');
    T::eq([], Product::referenceMap([999999]), 'an unknown id is simply absent');
});

test('the admin delete flow: modal when free, toast when referenced', function (): void {
    $m = Fixtures::member();
    $blocked = Fixtures::product('__DELBLOCK__', 50.00, 5);
    Fixtures::cancelledOrder($m, $blocked, 1);

    $view = (string) file_get_contents(REPO_ROOT . '/views/admin/shop_products.php');
    $ctrl = (string) file_get_contents(REPO_ROOT . '/controllers/AdminController.php');
    $app  = (string) file_get_contents(REPO_ROOT . '/assets/js/app.js');

    // Blocked products must be marked WITHOUT a request, so the button can
    // explain itself instead of firing a doomed POST.
    T::ok(str_contains($ctrl, 'Product::referenceMap('), 'the page resolves references once, batched');
    T::ok(str_contains($view, 'data-blocked='), 'the row carries its blocked state');
    T::ok(str_contains($view, 'data-reason='), 'the row carries the reason text');
    T::ok(str_contains($view, 'aria-disabled="true"'), 'a referenced product uses aria-disabled');
    T::ok(!str_contains($view, 'confirm(\'Delete'), 'the native confirm() dialog is gone');

    // aria-disabled, not `disabled`: a truly disabled button is unfocusable
    // and swallows the click, so the member would never learn why.
    $blockedBtn = substr($view, strpos($view, 'disabled-look') ?: 0, 400);
    T::ok(!preg_match('/<button[^>]*\sdisabled(\s|=|>)/', $blockedBtn), 'the button is not natively disabled');

    // The blocked/modal/remove logic lives in the page's own inline script,
    // not in app.js — only showConfirm()'s size support is shared code.
    //
    // These assert the WIRING, not just that a string appears somewhere. An
    // earlier version checked for `showToast(form.dataset.reason` and
    // `js-row-removing`, and both survived deleting the behaviour, because the
    // toast line was still in the file and the CSS selector still named the
    // class. Asserting the guard and the classList.add is what actually
    // distinguishes working from broken.
    T::ok(
        str_contains($view, "if (action === 'delete' && form.dataset.blocked === '1')"),
        'a blocked delete short-circuits before any request is made'
    );
    T::ok(
        str_contains($view, "showToast(form.dataset.reason"),
        'a blocked delete shows the reason as a toast'
    );
    T::ok(
        str_contains($view, "size: 'sm'"),
        'the confirmation modal is small'
    );
    T::ok(
        str_contains($view, "row.classList.add('js-row-removing')"),
        'a deleted row is marked for the fade'
    );
    T::ok(str_contains($view, 'row.remove()'), 'the row is removed rather than reloading the page');
    T::ok(str_contains($view, "body.set('ajax', '1')"), 'the delete posts the ajax flag');
    T::ok(str_contains($app, 'modal-sm'), 'showConfirm supports a small size');

    // The server stays the authority — a member can add to a cart between the
    // render and the click, so the refusal must still exist and still report
    // WHICH reference blocked it. Matched against the escaped literal, which
    // is what is actually in the source.
    T::ok(str_contains($ctrl, "json_response(['ok' => \$ok"), 'the endpoint answers JSON for the AJAX path');
    foreach ([
        'has been bought and is in a cart',
        'this product has been bought',
        "is in a member\\'s cart",
    ] as $phrase) {
        T::ok(str_contains($ctrl, $phrase), "the refusal names the cause: {$phrase}");
    }

    // And the size option must not have changed the default for the ~20
    // existing callers.
    T::ok(str_contains($app, 'size = null'), 'showConfirm defaults to the current width');
    T::ok(
        !str_contains((string) preg_replace('#//[^\n]*#', '', $app), 'modal-dialog-centered modal-dialog-centered'),
        'the dialog class is not doubled up'
    );
});

test('disabling a product hides it from the shop and is reversible', function (): void {
    $m   = Fixtures::member();
    $pid = Fixtures::product('__DIS__', 60.00, 5);
    $cartId = (int) Cart::getOrCreate($m)['id'];

    T::ok(Product::setStatus($pid, 'inactive'), 'a product can be disabled');
    T::eq('inactive', (string) Fixtures::scalar('SELECT status FROM products WHERE id = ?', [$pid]), 'the row is inactive');

    // The two things that make disabling mean anything.
    $storefront = array_map('intval', array_column(Product::active(), 'id'));
    T::ok(!in_array($pid, $storefront, true), 'a disabled product leaves the storefront');
    T::throws(
        InvalidArgumentException::class,
        static fn() => Cart::addItem($cartId, $pid, 1),
        'a disabled product cannot be added to a cart'
    );

    // And it must not damage history: an existing order line is untouched.
    $oid = Fixtures::pendingOrder($m, $pid, 2);
    T::ok(Product::setStatus($pid, 'active'), 'a product can be enabled again');
    T::eq('active', (string) Fixtures::scalar('SELECT status FROM products WHERE id = ?', [$pid]), 'the row is active again');
    T::ok(
        in_array($pid, array_map('intval', array_column(Product::active(), 'id')), true),
        'it returns to the storefront'
    );
    T::eq(
        2,
        (int) Fixtures::scalar('SELECT quantity FROM shop_order_items WHERE order_id = ? AND product_id = ?', [$oid, $pid]),
        'the existing order line is untouched by the catalog change'
    );

    T::ok(Cart::addItem($cartId, $pid, 1), 'it can be added to a cart once re-enabled');

    // A typo must not reach the enum and come back as a 500.
    T::throws(
        InvalidArgumentException::class,
        static fn() => Product::setStatus($pid, 'discontinued'),
        'an unknown status is refused'
    );
    T::ok(Product::setStatus(999999, 'inactive') === false, 'an unknown product is refused');
});

test('the Disable button reuses the delete modal, toast and in-place update', function (): void {
    $view = (string) file_get_contents(REPO_ROOT . '/views/admin/shop_products.php');
    $ctrl = (string) file_get_contents(REPO_ROOT . '/controllers/AdminController.php');

    // The row-level Disable/Enable toggle was removed; status changes go
    // through the edit modal's Status dropdown. The endpoint stays — a
    // registered, admin-only route costs nothing and keeps non-JS posts and
    // any direct status post working.
    T::ok(!str_contains($view, "'Disable' : 'Enable'"), 'the row-level Disable button is gone from the view');
    T::ok(!str_contains($view, 'data-action="status"'), 'no status form is rendered per row');
    T::ok(str_contains($view, 'name="status"'), 'the edit modal still offers the Status field');

    T::ok(str_contains($view, '.js-product-action-form'), 'the delete form handler is present');
    T::ok(!str_contains($view, 'js-delete-product-form'), 'the old delete-only handler name is gone');
    T::ok(str_contains($view, 'function copyFor('), 'the modal wording is chosen per action');
    T::ok(!str_contains($view, 'function applyStatus('), 'no in-place status updater is left behind');

    // The wording must state that delete is not reversible.
    T::ok(str_contains($view, 'This cannot be undone'), 'the delete modal still says it is permanent');

    // Route + endpoint, admin-only, CSRF-checked, and sharing the AJAX contract.
    $routes = (string) file_get_contents(REPO_ROOT . '/index.php');
    T::ok(str_contains($routes, "'admin_product_status'"), 'the route exists');
    T::ok(
        (bool) preg_match("/'admin_product_status'\s*=>\s*\['AdminController',\s*'productStatus',\s*'admin'\]/", $routes),
        'the route is admin-only'
    );
    T::ok(str_contains($ctrl, 'public function productStatus'), 'the endpoint exists');
    T::ok(str_contains($ctrl, 'json_response([\'ok\' => $ok, \'message\' => $msg, \'status\' => $status])'), 'it returns the new status so the row can update');
    T::ok(str_contains($view, 'data-role="status-badge"'), 'the status badge is addressable for the in-place update');
});

exit(T::summary());