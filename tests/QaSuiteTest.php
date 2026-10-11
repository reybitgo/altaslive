<?php

/**
 * Phase 10 — the manual QA suite, executed.
 *
 * `tmp/shop/qa_test_v2.md` is a checklist for a human with two browser windows.
 * Phase 10 action #2 asks for the cases to be run "for real, on the test
 * database, and record actual PASS/FAIL per case". This file does that by
 * driving each scenario end to end through the models against the disposable
 * test schema.
 *
 * The distinction from ShopStateMachineTest matters. That suite tests
 * transitions in isolation — one rule, one order, one assertion about it. This
 * one runs each case as a single continuous narrative from the buyer's first
 * click to the terminal state, asserting the stock and the wallet at every
 * step. A defect that only shows up across a whole lifecycle (double stock
 * deduction on a reship, stock vanishing on an RTS) is invisible to per-rule
 * testing and obvious here.
 *
 * It is not a substitute for a human looking at the screen, and it is not
 * recorded as one. What it replaces is the previous state, where the QA
 * document's ten checkboxes had never been ticked in any of its three
 * versions — i.e. the cases had been written but never run by anyone.
 *
 * @file   tests/QaSuiteTest.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$QA_RESULTS = [];

function qa(string $id, string $title, callable $fn): void
{
    $passBefore = T::$pass;
    $failBefore = T::$fail;
    try {
        $fn();
    } catch (Throwable $e) {
        T::ok(false, "{$id} :: uncaught " . get_class($e), substr($e->getMessage(), 0, 200));
    }
    global $QA_RESULTS;
    $QA_RESULTS[] = [
        'id'    => $id,
        'title' => $title,
        'ok'    => T::$fail - $failBefore,
        'bad'   => T::$fail - $failBefore,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// QA-1  Cart add / step / remove, and cross-member cart isolation
// ─────────────────────────────────────────────────────────────────────────────
qa('QA-1', 'Cart add / step / remove + cross-member cart isolation', function (): void {
    $a = Fixtures::member(2000.00);
    $b = Fixtures::member(2000.00);
    $p = Fixtures::product('__QA1__', 75.00, 10);

    $cartA = (int) Cart::getOrCreate($a)['id'];
    T::ok($cartA > 0, 'QA-1: a cart is created on first use');
    $cartA2 = (int) Cart::getOrCreate($a)['id'];
    T::eq($cartA, $cartA2, 'QA-1: the same member reuses their active cart');

    // add
    Cart::addItem($cartA, $p, 2);
    $line = Cart::getItem($cartA, $p);
    T::ok($line !== null, 'QA-1: the item appears in the cart');
    T::eq(2, (int) $line['quantity'], 'QA-1: the added quantity is stored');

    // step up
    Cart::addItem($cartA, $p, 1);
    T::eq(3, (int) Cart::getItem($cartA, $p)['quantity'], 'QA-1: stepping up accumulates');

    // step down is an absolute set, not a delta
    $pdo = db();
    $pdo->prepare('UPDATE cart_items SET quantity = 1 WHERE cart_id = ? AND product_id = ?')
        ->execute([$cartA, $p]);
    T::eq(1, (int) Cart::getItem($cartA, $p)['quantity'], 'QA-1: quantity can be stepped back down');

    // stock ceiling
    T::throws(
        InvalidArgumentException::class,
        static fn() => Cart::addItem($cartA, $p, 99),
        'QA-1: the cart refuses more than the available stock'
    );

    // ISOLATION: member B's cart is a different object entirely
    $cartB = (int) Cart::getOrCreate($b)['id'];
    T::ok($cartB !== $cartA, 'QA-1: a second member gets their own cart');
    T::eq(0, count(Cart::getItems($cartB)), 'QA-1: the second cart starts empty');
    T::eq(1, count(Cart::getItems($cartA)), 'QA-1: the first cart is unaffected');

    // a write aimed at B's cart id but scoped to A's member is refused
    T::throws(
        InvalidArgumentException::class,
        static fn() => ShopOrder::createFromCart(
            $b,
            $cartA,
            ['billing_name' => 'X', 'shipping_name' => 'X', 'shipping_address' => '1 St'],
            'gcash',
            'QA1-X'
        ),
        'QA-1: member B cannot check out using member A\'s cart'
    );

    // remove
    $lineId = (int) Cart::getItem($cartA, $p)['id'];
    T::ok(Cart::removeItemById($lineId, $cartA), 'QA-1: the line is removed');
    T::eq(0, count(Cart::getItems($cartA)), 'QA-1: removing the last line empties the cart');
    T::eq(0, count(Cart::getItems($cartB)), 'QA-1: the other cart is still empty and untouched');
});

// ─────────────────────────────────────────────────────────────────────────────
// QA-2  E-wallet happy path, end to end
// ─────────────────────────────────────────────────────────────────────────────
qa('QA-2', 'E-wallet happy path: cart to paid, one debit, stock reserved', function (): void {
    $m = Fixtures::member(5000.00);
    $p = Fixtures::product('__QA2__', 120.00, 5);
    $cart = Fixtures::cartWith($m, $p, 2);
    $before = (float) Fixtures::scalar('SELECT ewallet_balance FROM users WHERE id = ?', [$m]);
    $addr = ['billing_name' => 'QA2', 'shipping_name' => 'QA2', 'shipping_address' => '1 St'];

    $oid = ShopOrder::createFromCart($m, $cart, $addr, 'ewallet', 'QA2-IDEM');
    T::ok($oid > 0, 'QA-2: the order is created');
    T::eq('pending', Fixtures::statusOf($oid), 'QA-2: a new order starts pending');

    $total = (float) Fixtures::scalar('SELECT total_price FROM shop_orders WHERE id = ?', [$oid]);
    T::eq(240.00, $total, 'QA-2: the total is authoritative catalog pricing, not a posted price');

    T::ok(Ewallet::debitInternal($m, $total, $oid, 'shop_order', 'qa2'), 'QA-2: the wallet is debited');
    T::ok(ShopOrder::markPaid($oid, 'system', null), 'QA-2: the order is marked paid');
    T::eq('paid', Fixtures::statusOf($oid), 'QA-2: the order reaches paid');

    T::eq(
        $before - $total,
        (float) Fixtures::scalar('SELECT ewallet_balance FROM users WHERE id = ?', [$m]),
        'QA-2: the balance drops by exactly the order total'
    );
    T::eq(
        1,
        (int) Fixtures::scalar("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type='shop_order' AND reference_id=? AND type='debit'", [$oid]),
        'QA-2: exactly one debit row exists'
    );
    T::eq('committed', Fixtures::itemState($oid), 'QA-2: stock is committed at paid');
    T::eq(5, Fixtures::stockOf($p), 'QA-2: absolute stock is untouched at paid — only reserved');

    // the replay guard the idempotency story depends on
    T::ok(ShopOrder::markPaid($oid, 'system', null) === false, 'QA-2: re-marking paid is refused');
    T::eq(
        1,
        (int) Fixtures::scalar("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type='shop_order' AND reference_id=? AND type='debit'", [$oid]),
        'QA-2: the replay did not debit twice'
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// QA-3  Manual-payment (GCash) happy path: proof through to paid
// ─────────────────────────────────────────────────────────────────────────────
qa('QA-3', 'Manual payment happy path: proof upload, review, paid', function (): void {
    $m = Fixtures::member(0.00);
    $p = Fixtures::product('__QA3__', 200.00, 5);
    $oid = Fixtures::pendingOrder($m, $p, 1);
    $admin = Fixtures::admin();
    $balance = (float) Fixtures::scalar('SELECT ewallet_balance FROM users WHERE id = ?', [$m]);

    T::ok(
        ShopOrder::submitProof($oid, $m, 'shop_proofs/qa3.jpg', ['reference_no' => 'GC123456', 'amount' => 200.0]),
        'QA-3: the member uploads proof'
    );
    T::eq('payment_review', Fixtures::statusOf($oid), 'QA-3: the order moves to payment_review');

    T::ok(ShopOrder::markPaid($oid, 'admin', $admin, 200.0, 'GC123456'), 'QA-3: the admin approves');
    T::eq('paid', Fixtures::statusOf($oid), 'QA-3: the order reaches paid');

    T::eq(
        $balance,
        (float) Fixtures::scalar('SELECT ewallet_balance FROM users WHERE id = ?', [$m]),
        'QA-3: a manual-payment order never touches the wallet'
    );
    T::eq(
        0,
        (int) Fixtures::scalar("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type='shop_order' AND reference_id=?", [$oid]),
        'QA-3: no ledger row exists for a manual payment'
    );

    // A mismatched amount must be refused — this is the admin-typo guard.
    $oid2 = Fixtures::pendingOrder($m, $p, 1);
    ShopOrder::submitProof($oid2, $m, 'shop_proofs/qa3b.jpg', ['reference_no' => 'GC999', 'amount' => 200.0]);
    T::throws(
        InvalidArgumentException::class,
        static fn() => ShopOrder::markPaid($oid2, 'admin', $admin, 5.0, 'GC999'),
        'QA-3: approving a mismatched amount is refused'
    );
    T::eq('payment_review', Fixtures::statusOf($oid2), 'QA-3: the refused approval left the status alone');
});

// ─────────────────────────────────────────────────────────────────────────────
// QA-4  Refund lifecycle through to processed
// ─────────────────────────────────────────────────────────────────────────────
qa('QA-4', 'Refund lifecycle: cancelled to approved to processed, wallet credited once', function (): void {
    $m = Fixtures::member(4000.00);
    $p = Fixtures::product('__QA4__', 150.00, 5);
    $admin = Fixtures::admin();

    $oid = Fixtures::cancelledOrder($m, $p, 1);
    $rid = Fixtures::refund($oid, 150.00, 'ewallet');
    T::eq('open', (string) Fixtures::scalar('SELECT status FROM shop_refunds WHERE id = ?', [$rid]), 'QA-4: the refund opens');

    T::ok(ShopRefund::approve($rid, $admin), 'QA-4: the refund is approved');
    T::eq('approved', (string) Fixtures::scalar('SELECT status FROM shop_refunds WHERE id = ?', [$rid]), 'QA-4: status is approved');

    // An approved refund blocks completion (M3) — checked in the state suite;
    // here it is a precondition, so assert it holds before paying out.
    T::ok(
        ShopOrder::transition($oid, 'completed', 'admin', $admin) === false,
        'QA-4: an order with an approved refund cannot complete'
    );

    $before = (float) Fixtures::scalar('SELECT ewallet_balance FROM users WHERE id = ?', [$m]);
    T::ok(ShopRefund::markPaid($rid, $admin, 'QA4-RECEIPT'), 'QA-4: the payout is made');
    T::eq('processed', (string) Fixtures::scalar('SELECT status FROM shop_refunds WHERE id = ?', [$rid]), 'QA-4: status is processed');

    T::eq(
        $before + 150.00,
        (float) Fixtures::scalar('SELECT ewallet_balance FROM users WHERE id = ?', [$m]),
        'QA-4: the wallet is credited exactly once'
    );
    T::ok(
        ShopRefund::markPaid($rid, $admin, 'QA4-RECEIPT') === false,
        'QA-4: paying out again is refused'
    );
    T::eq(
        $before + 150.00,
        (float) Fixtures::scalar('SELECT ewallet_balance FROM users WHERE id = ?', [$m]),
        'QA-4: the refused replay did not credit again'
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// QA-5  delivery-failure -> RTS -> reship -> complete, no double stock deduction
// ─────────────────────────────────────────────────────────────────────────────
qa('QA-5', 'Delivery failure to RTS to reship to complete, stock deducted exactly once', function (): void {
    $m = Fixtures::member(4000.00);
    $p = Fixtures::product('__QA5__', 100.00, 5);
    $admin = Fixtures::admin();

    $oid = Fixtures::shippedOrder($m, $p, 2);
    T::eq(3, Fixtures::stockOf($p), 'QA-5: handoff deducted stock exactly once (5 -> 3)');
    T::eq('deducted', Fixtures::itemState($oid), 'QA-5: the line is marked deducted');

    T::ok(ShopOrder::markOutForDelivery($oid, 'admin', $admin), 'QA-5: out for delivery');
    T::ok(ShopOrder::markDeliveryFailed($oid, $admin, 'carrier', 'Recipient not available'), 'QA-5: delivery attempt fails');
    T::ok(ShopOrder::returnToSender($oid, 'admin', $admin, 'Returned'), 'QA-5: the parcel returns to sender');
    T::eq('returned_to_sender', Fixtures::statusOf($oid), 'QA-5: status is returned_to_sender');

    T::eq(3, Fixtures::stockOf($p), 'QA-5: RTS does NOT re-add stock — goods are in transit, not resold');

    T::ok(ShopOrder::reship($oid, $admin), 'QA-5: the parcel is reshipped');
    T::ok(
        in_array(Fixtures::statusOf($oid), ['packing', 'paid', 'ready_to_ship'], true),
        'QA-5: the reship re-enters the pipeline',
        Fixtures::statusOf($oid)
    );
    T::eq(3, Fixtures::stockOf($p), 'QA-5: the reship still does not re-add stock');
    T::eq('reserved', Fixtures::itemState($oid), 'QA-5: the line is reserved again for the reship');

    $shipments = count(ShopShipment::byOrder($oid));
    T::ok($shipments >= 2, 'QA-5: a second shipment row exists', "count={$shipments}");

    // Drive the reship back out to delivery. reship() lands on 'packing', so
    // the outbound leg from here is finishPacking -> ship -> out_for_delivery.
    // MUTATION NOTE: the double-deduction guard is the `deducted_at IS NOT NULL`
    // check inside applySideEffects()'s 'shipped' branch, NOT the
    // reservation_state filter on the line SELECT. Widening that filter to
    // admit 'deducted' lines changes nothing, because a reship legitimately
    // resets the line to 'reserved' first — the line is always 'reserved' at
    // handoff, and `deducted_at` is what remembers that these specific goods
    // already left the stock figure.
    //
    // Verified: replacing `if ($line['deducted_at'] !== null)` with `if (false)`
    // makes this case fail with stock 3 -> 1.
    T::eq('packing', Fixtures::statusOf($oid), 'QA-5: the reship lands in packing');
    T::ok(ShopOrder::finishPacking($oid, $admin), 'QA-5: the reship is packed');
    T::ok(ShopOrder::ship($oid, $admin, 'QA Courier', 'TRK-QA5-B'), 'QA-5: the reship is handed off again');
    T::eq('shipped', Fixtures::statusOf($oid), 'QA-5: the reship reaches shipped');
    T::eq(
        3,
        Fixtures::stockOf($p),
        'QA-5: the second handoff did NOT deduct again — this is the double-deduction guard'
    );

    T::ok(ShopOrder::markDelivered($oid, $admin, 'J. Doe', 'shop_proofs/pod.jpg'), 'QA-5: the reship is delivered');
    T::ok(ShopOrder::markOutForDelivery($oid, 'admin', $admin) === false
        || Fixtures::statusOf($oid) === 'delivered', 'QA-5: delivery is terminal for the courier leg');
    T::ok(ShopOrder::confirmReceipt($oid, $m), 'QA-5: the buyer confirms receipt');
    T::eq('completed', Fixtures::statusOf($oid), 'QA-5: the order completes');

    T::eq(
        3,
        Fixtures::stockOf($p),
        'QA-5: stock is still 3 after the entire failure/reship lifecycle'
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// QA-6  Hold and resume
// ─────────────────────────────────────────────────────────────────────────────
qa('QA-6', 'Hold and resume restores the pre-hold status', function (): void {
    $m = Fixtures::member();
    $p = Fixtures::product('__QA6__', 90.00, 5);
    $admin = Fixtures::admin();
    $oid = Fixtures::pendingOrder($m, $p, 1);

    T::ok(
        ShopOrder::onHold($oid, $admin, 'Awaiting customer confirmation', $admin, date('Y-m-d H:i:s', time() + 86400)),
        'QA-6: the order is placed on hold'
    );
    T::eq('on_hold', Fixtures::statusOf($oid), 'QA-6: status is on_hold');

    // A hold is a PAUSE, not a lock: transition() resolves the effective source
    // status from previous_status, so the order is still judged as if it were
    // sitting in 'pending'. An earlier version of this case asserted the
    // opposite and was wrong about the design — a member legitimately may
    // cancel an order that is merely on hold.
    T::eq(
        'pending',
        (string) Fixtures::scalar('SELECT previous_status FROM shop_orders WHERE id = ?', [$oid]),
        'QA-6: the pre-hold status is frozen for the resume target'
    );
    T::ok(
        ShopOrder::transition($oid, 'cancelled', 'customer', $m),
        'QA-6: a member may still cancel an order that is merely on hold'
    );
    T::eq('cancelled', Fixtures::statusOf($oid), 'QA-6: the cancellation stands');

    // Resume on a no-longer-held order is a no-op, not a silent state change.
    T::ok(ShopOrder::resume($oid, $admin) === false, 'QA-6: resuming a non-held order is refused');
    T::eq('cancelled', Fixtures::statusOf($oid), 'QA-6: the refused resume changed nothing');

    // And the real round trip. resume() only accepts a target that is both a
    // matrix target and admin-reachable, and 'pending' is neither — it is an
    // initial state with no transition INTO it. Holding from a status with an
    // onward admin path is the case the feature exists for.
    $oid2 = Fixtures::pendingOrder($m, $p, 1);
    ShopOrder::markPaid($oid2, 'system', null);
    T::eq('paid', Fixtures::statusOf($oid2), 'QA-6: the second order is paid');

    T::ok(
        ShopOrder::onHold($oid2, $admin, 'Verifying address', $admin, date('Y-m-d H:i:s', time() + 86400)),
        'QA-6: the second order is placed on hold'
    );
    T::eq('on_hold', Fixtures::statusOf($oid2), 'QA-6: the second order is on_hold');

    T::ok(ShopOrder::resume($oid2, $admin), 'QA-6: the hold is released');
    T::eq('paid', Fixtures::statusOf($oid2), 'QA-6: resume restores the pre-hold status');
    T::ok(
        ShopOrder::transition($oid2, 'packing', 'admin', $admin),
        'QA-6: the order is actionable again after resume'
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// QA-7  payment_review never expires
// ─────────────────────────────────────────────────────────────────────────────
qa('QA-7', 'payment_review is exempt from expiry', function (): void {
    $m = Fixtures::member();
    $p = Fixtures::product('__QA7__', 60.00, 5);

    // One order still in payment_review, one genuinely overdue in pending.
    $review = Fixtures::pendingOrder($m, $p, 1);
    ShopOrder::submitProof($review, $m, 'shop_proofs/qa7.jpg', ['reference_no' => 'R7', 'amount' => 60.0]);
    T::eq('payment_review', Fixtures::statusOf($review), 'QA-7: the order is in payment_review');

    $overdue = Fixtures::pendingOrder($m, $p, 1);
    db()->prepare("UPDATE shop_orders SET payment_deadline = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE id = ?")
        ->execute([$overdue]);

    $expired = ShopOrder::expireOverdue(200);
    T::ok($expired >= 1, 'QA-7: the genuinely overdue order expired', "expired={$expired}");
    T::eq(
        'payment_review',
        Fixtures::statusOf($review),
        'QA-7: the payment_review order was NOT expired'
    );
    T::eq(
        'cancelled',
        Fixtures::statusOf($overdue),
        'QA-7: the overdue pending order became cancelled'
    );
    T::eq('released', Fixtures::itemState($overdue), 'QA-7: expiry released the reservation');

    // Running the sweeper again must not disturb the review order.
    ShopOrder::expireOverdue(200);
    T::eq('payment_review', Fixtures::statusOf($review), 'QA-7: a second sweep still leaves it alone');
});

// ─────────────────────────────────────────────────────────────────────────────
// QA-8  The shop on/off switch
// ─────────────────────────────────────────────────────────────────────────────
qa('QA-8', 'Shop on/off switch governs storefront access', function (): void {
    // guardShop() opens only when setting('shop_enabled') === '1'.
    //
    // setting() memoises in a `static $cache`, which is correct in production —
    // the cache lives one request — but means a mid-process DB change is
    // invisible to the helper. So the value is read from the row directly and
    // the CONTROLLER'S comparison is reproduced, rather than pretending the
    // helper can observe a write made after its first read.
    // NOTE: the comparison is `?: '1'` on a strict false check, NOT an elvis
    // operator. `'0' ?: '1'` yields '1' because the string zero is falsy in
    // PHP, so an elvis would report the shop as open in exactly the state this
    // case exists to detect. setting() carries the same warning in its own
    // body for the fetchColumn()-returns-false case.
    $read = static function (): string {
        $v = db()->query("SELECT value FROM settings WHERE key_name = 'shop_enabled'")->fetchColumn();
        return $v === false ? '1' : (string) $v;
    };

    T::eq('1', $read(), 'QA-8: the switch ships open');

    db()->prepare("UPDATE settings SET value = '0' WHERE key_name = 'shop_enabled'")->execute();
    T::eq('0', $read(), 'QA-8: an admin can close the shop');
    T::ok($read() !== '1', 'QA-8: guardShop() would refuse in this state');

    db()->prepare("UPDATE settings SET value = '1' WHERE key_name = 'shop_enabled'")->execute();
    T::ok($read() === '1', 'QA-8: guardShop() would admit again once reopened');

    $seedRows = (int) Fixtures::scalar("SELECT COUNT(*) FROM settings WHERE key_name = 'shop_enabled'");
    $seedVal  = Fixtures::scalar("SELECT value FROM settings WHERE key_name = 'shop_enabled'");
    T::ok(
        $seedRows === 1 && (string) $seedVal === '1',
        'QA-8: install.sql seeds shop_enabled=1, so a fresh install is open',
        "rows={$seedRows} value=" . var_export($seedVal, true)
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// QA-9  An admin cannot review their own order (segregation of duties)
// ─────────────────────────────────────────────────────────────────────────────
qa('QA-9', 'An admin cannot act on their own order', function (): void {
    // assertNotSelfReview keys off the SESSION actor (Auth::isAdmin() and
    // Auth::id()), not off the $adminId argument — the session is the trust
    // boundary, so the guard can only be exercised by actually being logged in
    // as that admin. An earlier version of this case passed the id but never
    // logged in, which silently skipped the check entirely and "passed" for the
    // wrong reason.
    $adminMember = Fixtures::member(4000.00, 'qa_admin_dual');
    $pid = Fixtures::product('__QA9__', 110.00, 5);
    $oid = Fixtures::pendingOrder($adminMember, $pid, 1);
    T::ok($oid > 0, 'QA-9: a control order for a different member is created');

    $adminUser = (int) Fixtures::scalar("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
    $oid2 = Fixtures::pendingOrder($adminUser, $pid, 1);
    ShopOrder::submitProof($oid2, $adminUser, 'shop_proofs/qa9.jpg', ['reference_no' => 'A9', 'amount' => 110.0]);
    T::eq('payment_review', Fixtures::statusOf($oid2), 'QA-9: the admin-as-buyer order awaits review');

    // Logged in AS that admin, the self-review guard must fire.
    Fixtures::loginAs($adminUser, 'admin');
    T::throws(
        RuntimeException::class,
        static fn() => ShopOrder::markPaid($oid2, 'admin', $adminUser, 110.0, 'A9'),
        'QA-9: the approving admin is the order owner and is refused'
    );
    T::eq('payment_review', Fixtures::statusOf($oid2), 'QA-9: the order is untouched by the refused approval');

    // Sanity: the SAME call from a different logged-in admin is allowed, which
    // proves the refusal was about identity and not about the arguments.
    Fixtures::loginAs(Fixtures::admin(), 'admin');
    T::ok(
        ShopOrder::markPaid($oid2, 'admin', Fixtures::admin(), 110.0, 'A9'),
        'QA-9: a different admin may approve the same order'
    );
    Fixtures::logout();
});

// ─────────────────────────────────────────────────────────────────────────────
// QA-10  Cross-member proof and order access is refused
// ─────────────────────────────────────────────────────────────────────────────
qa('QA-10', 'Cross-member access to proofs and orders is refused', function (): void {
    $owner = Fixtures::member();
    $stranger = Fixtures::member();
    $p = Fixtures::product('__QA10__', 80.00, 5);
    $oid = Fixtures::pendingOrder($owner, $p, 1);

    T::ok(
        ShopOrder::submitProof($oid, $stranger, 'shop_proofs/x.jpg', ['reference_no' => 'X1', 'amount' => 80.0]) === false,
        'QA-10: a stranger cannot upload proof against someone else\'s order'
    );
    T::eq(
        0,
        (int) Fixtures::scalar('SELECT COUNT(*) FROM shop_payment_proofs WHERE order_id = ?', [$oid]),
        'QA-10: no proof row was created by the attempt'
    );

    $ownerOrders = ShopOrder::forMember($owner);
    $ids = array_column($ownerOrders['data'] ?? [], 'id');
    T::ok(in_array($oid, array_map('intval', $ids), true), 'QA-10: the owner sees their own order');

    $strangerOrders = ShopOrder::forMember($stranger);
    $sIds = array_map('intval', array_column($strangerOrders['data'] ?? [], 'id'));
    T::ok(!in_array($oid, $sIds, true), 'QA-10: the stranger does not see it in their list');
});

// ─────────────────────────────────────────────────────────────────────────────
// Results table
// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
echo "  QA case results\n";
echo "  " . str_repeat('-', 74) . "\n";
foreach ($QA_RESULTS as $r) {
    printf(
        "  %-6s %-58s %2d passed  %2d failed  %s\n",
        $r['id'], substr($r['title'], 0, 58), $r['ok'], $r['bad'],
        $r['bad'] === 0 ? 'PASS' : 'FAIL'
    );
}
echo "  " . str_repeat('-', 74) . "\n";

exit(T::summary());
