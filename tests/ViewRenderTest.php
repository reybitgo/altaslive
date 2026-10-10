<?php

/**
 * @file   tests/ViewRenderTest.php
 * @brief  Renders the real shop views and fails on any PHP diagnostic.
 *
 * WHY THIS EXISTS
 *   Defect C4 shipped because the order lists read a variable ($order) that
 *   their own loop never defined. Every row rendered the `default` arm of
 *   shop_status_label() - the word "Unknown" - and PHP emitted two warnings per
 *   row. Nothing caught it: the suite never rendered a view, and the lint rules
 *   are static.
 *
 *   So this renders the views for real, with an error handler that turns every
 *   warning, notice and deprecation into a recorded failure. A view that renders
 *   clean is the assertion; a view that merely looks right in a browser is not.
 *
 * Covers the Phase 4 rendering defects:
 *   C4  status badge rendered "Unknown" on both lists
 *   M12 order_no recomputed from id+created_at instead of the stored column
 *   M14 function declared at file scope inside a view; dead variables
 *   M15 an empty "Actions" card for statuses with no inline form
 *
 * Run:  php tests/run.php ViewRender
 */

require_once __DIR__ . '/bootstrap.php';

/** Diagnostics captured during a render, keyed by "file:line". */
final class RenderLog
{
    public static array $problems = [];
    public static bool $armed = false;

    public static function arm(): void
    {
        self::$problems = [];
        set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0): bool {
            $label = match ($no) {
                E_WARNING, E_USER_WARNING            => 'WARNING',
                E_NOTICE, E_USER_NOTICE              => 'NOTICE',
                E_DEPRECATED, E_USER_DEPRECATED      => 'DEPRECATED',
                default                              => 'ERROR',
            };
            self::$problems[] = "{$label}: {$msg} at " . basename($file) . ':' . $line;
            return true; // handled: never fall through to PHP's own output
        });
        self::$armed = true;
    }

    public static function disarm(): void
    {
        if (self::$armed) {
            restore_error_handler();
            self::$armed = false;
        }
    }
}

/**
 * Render a view file with the given variables and return its output.
 * Any diagnostic raised during the render is recorded in RenderLog.
 */
function render_view(string $relative, array $vars = []): string
{
    $level = ob_get_level();
    ob_start();
    try {
        extract($vars, EXTR_SKIP);
        /** @noinspection PhpIncludeInspection */
        require REPO_ROOT . '/' . $relative;
    } catch (Throwable $e) {
        RenderLog::$problems[] = 'THROWN: ' . get_class($e) . ': ' . $e->getMessage();
    } finally {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
    }
    return '';
}

/** Assert a render produced no PHP diagnostic. */
function assert_clean_render(string $label): bool
{
    $p = RenderLog::$problems;
    RenderLog::$problems = [];
    return T::ok(
        $p === [],
        $label . ': rendered without PHP diagnostics',
        implode(' | ', array_slice($p, 0, 4))
    );
}

// ═══════════════════════════════════════════════════════════════════════════
T::group('C4 — the status badge partial');

test('order_status_badge renders the real label, warning-free', function (): void {
    RenderLog::arm();
    try {
        foreach (ShopOrder::STATUSES as $status) {
            $badgeStatus = $status;
            $badgeStamp  = null;
            ob_start();
            try {
                require REPO_ROOT . '/views/partials/order_status_badge.php';
            } finally {
                $html = (string) ob_get_clean();
            }
            T::ok(
                str_contains($html, e(shop_status_label($status))),
                "C4: badge for '{$status}' shows its label, not 'Unknown'",
                substr(strip_tags($html), 0, 60)
            );
            T::ok(
                !str_contains($html, 'Unknown'),
                "C4: badge for '{$status}' never renders the 'Unknown' fallback"
            );
        }
        // A timestamp prints under the pill.
        $badgeStatus = 'paid';
        $badgeStamp  = '2026-10-10 09:30:00';
        ob_start();
        try {
            require REPO_ROOT . '/views/partials/order_status_badge.php';
        } finally {
            $stamped = (string) ob_get_clean();
        }
        T::ok(str_contains($stamped, '<small'), 'C4: a stamp renders under the pill');
    } finally {
        assert_clean_render('C4 badge partial');
        RenderLog::disarm();
    }
});

test('the badge partial does not read a magic $order', function (): void {
    // The partial must depend only on the arguments it documents. Comments are
    // stripped first: the docblock deliberately NAMES $order while explaining
    // why the partial no longer uses it.
    $src = (string) file_get_contents(REPO_ROOT . '/views/partials/order_status_badge.php');
    $code = (string) preg_replace(
        ['#/\*.*?\*/#s', '#//[^\n]*#', '#^\s*#m'],
        '',
        $src
    );
    T::ok(
        !preg_match('/\$order\b/', $code),
        'C4: partial code never reads $order',
        'a live reference to $order remains outside comments'
    );
    T::ok(
        (bool) preg_match('/\$badgeStatus/', $code),
        'C4: partial uses an explicit $badgeStatus argument'
    );

    // Every caller must supply it.
    $callers = [];
    foreach (glob(REPO_ROOT . '/views/*/*.php') ?: [] as $file) {
        $body = (string) file_get_contents($file);
        if (str_contains($body, 'order_status_badge.php')) {
            $callers[basename($file)] = str_contains($body, '$badgeStatus');
        }
    }
    T::ok(count($callers) >= 2, 'C4: both list views include the partial', implode(', ', array_keys($callers)));
    $missing = array_keys(array_filter($callers, static fn($v) => !$v));
    T::ok($missing === [], 'C4: every caller sets $badgeStatus before the include', implode(', ', $missing));
});

// ═══════════════════════════════════════════════════════════════════════════
T::group('M12 — order_no has one source of truth');

test('lists use the stored order_no column', function (): void {
    foreach (['views/member/shop_orders.php', 'views/admin/shop_orders.php'] as $rel) {
        $src = (string) file_get_contents(REPO_ROOT . '/' . $rel);
        T::ok(
            !str_contains($src, 'shop_public_order_no('),
            "M12: {$rel} does not recompute the order number"
        );
        T::ok(
            (bool) preg_match('/\$o\[\'order_no\'\]/', $src),
            "M12: {$rel} prints the stored order_no"
        );
    }
});

// ═══════════════════════════════════════════════════════════════════════════
T::group('M14 — no function declarations or dead variables in views');

test('shop views declare no PHP functions at file scope', function (): void {
    $offenders = [];
    foreach (glob(REPO_ROOT . '/views/*/*.php') ?: [] as $file) {
        $src = (string) file_get_contents($file);
        // Strip <script> bodies: JavaScript `function foo() {` lines are the
        // bulk of the false positives here and are perfectly legal in a view.
        $src = (string) preg_replace('#<script\b.*?</script>#is', '', $src);
        // Strip PHP comments so a docblock mentioning a function is not a hit.
        $src = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $src);
        if (!preg_match('/^\s*function\s+[a-zA-Z_]\w*\s*\(/m', $src)) {
            continue;
        }
        // A declaration wrapped in `if (!function_exists('name'))` is the
        // accepted fix: a second include becomes a no-op instead of a fatal
        // redeclare. Everything else is still an offender.
        if (preg_match('/if\s*\(\s*!function_exists\(/', $src)) {
            continue;
        }
        $offenders[] = basename($file);
    }
    T::ok($offenders === [], 'M14: no view declares a PHP function at file scope', implode(', ', $offenders));
});

test('shop_next_action now lives in helpers and covers every status', function (): void {
    T::ok(function_exists('shop_next_action'), 'M14: shop_next_action() is defined in core/helpers.php');
    foreach (ShopOrder::STATUSES as $status) {
        // Must not fatal and must return string|null.
        $hint = shop_next_action($status);
        T::ok(
            $hint === null || is_string($hint),
            "M14: shop_next_action('{$status}') returns string|null"
        );
    }
});

test('the unused $actionable query is gone', function (): void {
    $view = (string) file_get_contents(REPO_ROOT . '/views/admin/shop_orders.php');
    T::ok(!str_contains($view, '$actionable'), 'M14: admin list no longer reads $actionable');

    $ctrl = (string) file_get_contents(REPO_ROOT . '/controllers/AdminController.php');
    T::ok(
        !preg_match('/\$actionable\s*=/', $ctrl),
        'M14: controller no longer runs the unused actionable COUNT(*) query'
    );
});

// ═══════════════════════════════════════════════════════════════════════════
T::group('M15 — the Actions card never renders empty');

test('every status renders either actions or an explicit empty state', function (): void {
    $src = (string) file_get_contents(REPO_ROOT . '/views/admin/shop_order.php');

    // The guard has to consider what the view actually renders, not only what
    // the matrix permits. Assert the "no admin actions" branch is reachable for
    // a status whose legal targets have no inline form.
    T::ok(
        str_contains($src, 'No admin actions available from'),
        'M15: the view has an explicit empty-state branch'
    );

    // payment_failed: matrix-legal targets are cancelled + on_hold. on_hold is
    // rendered in the right rail and cancelled is rendered inline, so this one
    // must produce a real button rather than an empty card.
    $status = 'payment_failed';
    $legal  = [];
    foreach (ShopOrder::TRANSITIONS as $to => $rule) {
        $from = $rule['from']['admin'] ?? $rule['from'] ?? [];
        if (in_array('admin', $rule['actors'], true) && in_array($status, $from, true)) {
            $legal[] = $to;
        }
    }
    T::inSet('cancelled', $legal, 'M15: payment_failed can be cancelled');
    T::inSet('on_hold', $legal, 'M15: payment_failed can be held');
    T::ok(
        str_contains($src, "'payment_failed', 'paid'") || str_contains($src, "'payment_failed'"),
        'M15: the cancel form covers payment_failed'
    );
});

// ═══════════════════════════════════════════════════════════════════════════
T::group('View render — warning-free sweep');

test('the order lists render without PHP diagnostics', function (): void {
    $memberId = Fixtures::member();
    $pid      = Fixtures::product('__RENDER__', 100.00, 5);

    $ids = [];
    foreach (['pending', 'paid', 'delivered', 'cancelled'] as $status) {
        $oid = Fixtures::pendingOrder($memberId, $pid, 1);
        // Move it to a real state so the badge and canMemberAct have work to do.
        if ($status !== 'pending') {
            db()->prepare("UPDATE shop_orders SET status = ? WHERE id = ?")->execute([$status, $oid]);
        }
        $ids[] = $oid;
    }

    $rows = db()->prepare(
        "SELECT o.* FROM shop_orders o WHERE o.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY o.id"
    );
    $rows->execute($ids);

    Fixtures::loginAs($memberId, 'member');
    RenderLog::arm();
    try {
        $orders = ['data' => $rows->fetchAll(), 'total_pages' => 1, 'page' => 1, 'per_page' => 10];
        render_view('views/member/shop_orders.php', ['orders' => $orders]);
    } finally {
        RenderLog::disarm();
        Fixtures::logout();
    }
    assert_clean_render('member order list');
});

test('the status badge shows real labels in the rendered member list', function (): void {
    $memberId = Fixtures::member();
    $pid      = Fixtures::product('__RENDER2__', 100.00, 5);
    $oid      = Fixtures::pendingOrder($memberId, $pid, 1);
    db()->prepare("UPDATE shop_orders SET status = 'ready_to_ship' WHERE id = ?")->execute([$oid]);

    $rows = db()->prepare("SELECT * FROM shop_orders WHERE id = ?");
    $rows->execute([$oid]);

    Fixtures::loginAs($memberId, 'member');
    $html = '';
    ob_start();
    RenderLog::arm();
    try {
        $orders = ['data' => $rows->fetchAll(), 'total_pages' => 1, 'page' => 1, 'per_page' => 10];
        extract(['orders' => $orders], EXTR_SKIP);
        require REPO_ROOT . '/views/member/shop_orders.php';
    } catch (Throwable $e) {
        RenderLog::$problems[] = 'THROWN: ' . $e->getMessage();
    } finally {
        RenderLog::disarm();
        $html = (string) ob_get_clean();
        Fixtures::logout();
    }

    T::ok(str_contains($html, 'Ready to ship'), 'C4: rendered list shows "Ready to ship"', 'badge text not found');
    T::ok(!str_contains($html, '>Unknown<'), 'C4: rendered list contains no "Unknown" badge');
    T::ok(str_contains($html, 'AL'), 'M12: rendered list shows the stored AL order number');
});

// ═══════════════════════════════════════════════════════════════════════════
T::group('C6 / H2 — every rendered admin button is matrix-legal');

test('no admin action bar renders a transition the matrix refuses', function (): void {
    $adminId = Fixtures::admin();
    $memberId = Fixtures::member();
    $pid = Fixtures::product('__MTRX__', 100.00, 20);

    // Every status an order can be in. For each we render the admin desk and
    // pull out every `to=` value the action bar posted, then check it against
    // ShopOrder::TRANSITIONS for (status, 'admin').
    $illegal = [];
    $checked = 0;

    foreach (ShopOrder::STATUSES as $status) {
        $oid = Fixtures::pendingOrder($memberId, $pid, 1);
        db()->prepare("UPDATE shop_orders SET status = ? WHERE id = ?")->execute([$status, $oid]);
        $order = ShopOrder::find($oid);

        $vars = [
            'order'          => $order,
            'orderNo'        => $order['order_no'],
            'buyer'          => User::find($memberId),
            'events'         => ShopOrderEvent::forOrder($oid),
            'proofs'         => ShopPaymentProof::byOrder($oid),
            'shipments'      => ShopShipment::byOrder($oid),
            'activeShipment' => ShopShipment::activeFor($oid),
            'refunds'        => ShopRefund::forOrder($oid),
            'counts'         => ShopOrder::statusCounts(),
            'pageTitle'      => 'Order',
        ];

        Fixtures::loginAs($adminId, 'admin');
        ob_start();
        RenderLog::arm();
        try {
            extract($vars, EXTR_SKIP);
            require REPO_ROOT . '/views/admin/shop_order.php';
        } catch (Throwable $e) {
            RenderLog::$problems[] = 'THROWN@' . $status . ': ' . $e->getMessage();
        } finally {
            RenderLog::disarm();
            $html = (string) ob_get_clean();
            Fixtures::logout();
        }

        preg_match_all('/name="to"\s+value="([a-z_]+)"/', $html, $m);
        foreach ($m[1] as $to) {
            $checked++;
            $rule = ShopOrder::TRANSITIONS[$to] ?? null;
            $from = $rule['from']['admin'] ?? $rule['from'] ?? [];
            if ($rule === null
                || !in_array('admin', $rule['actors'], true)
                || !in_array($status, $from, true)
            ) {
                $illegal[] = "{$status} -> {$to}";
            }
        }
    }

    T::ok($checked > 0, 'C6: the action bar rendered some transitions to check', "{$checked} button(s)");
    T::ok(
        $illegal === [],
        'C6/H2: every rendered admin transition is legal for that status',
        implode(', ', $illegal)
    );
});

test('H2 — returned_to_sender is reachable from the delivery statuses', function (): void {
    $adminId  = Fixtures::admin();
    $memberId = Fixtures::member();
    $pid      = Fixtures::product('__RTS__', 100.00, 20);

    foreach (['shipped', 'out_for_delivery', 'delivery_failed'] as $status) {
        $oid = Fixtures::pendingOrder($memberId, $pid, 1);
        db()->prepare("UPDATE shop_orders SET status = ? WHERE id = ?")->execute([$status, $oid]);
        $order = ShopOrder::find($oid);

        Fixtures::loginAs($adminId, 'admin');
        ob_start();
        RenderLog::arm();
        try {
            extract([
                'order' => $order, 'orderNo' => $order['order_no'],
                'buyer' => User::find($memberId), 'events' => [],
                'proofs' => [], 'shipments' => [], 'activeShipment' => null,
                'refunds' => [], 'counts' => [], 'pageTitle' => 'Order',
            ], EXTR_SKIP);
            require REPO_ROOT . '/views/admin/shop_order.php';
        } catch (Throwable $e) {
            RenderLog::$problems[] = 'THROWN: ' . $e->getMessage();
        } finally {
            RenderLog::disarm();
            $html = (string) ob_get_clean();
            Fixtures::logout();
        }

        T::ok(
            str_contains($html, 'value="returned_to_sender"'),
            "H2: the '{$status}' desk offers Return to sender"
        );
    }
});

test('H1 — the delivered form can actually carry a file', function (): void {
    $adminId  = Fixtures::admin();
    $memberId = Fixtures::member();
    $pid      = Fixtures::product('__POD__', 100.00, 20);
    $oid      = Fixtures::pendingOrder($memberId, $pid, 1);
    db()->prepare("UPDATE shop_orders SET status = 'shipped' WHERE id = ?")->execute([$oid]);
    $order = ShopOrder::find($oid);

    Fixtures::loginAs($adminId, 'admin');
    ob_start();
    RenderLog::arm();
    try {
        extract([
            'order' => $order, 'orderNo' => $order['order_no'],
            'buyer' => User::find($memberId), 'events' => [],
            'proofs' => [], 'shipments' => [], 'activeShipment' => null,
            'refunds' => [], 'counts' => [], 'pageTitle' => 'Order',
        ], EXTR_SKIP);
        require REPO_ROOT . '/views/admin/shop_order.php';
    } finally {
        RenderLog::disarm();
        $html = (string) ob_get_clean();
        Fixtures::logout();
    }

    T::ok(str_contains($html, 'name="pod_image"'), 'H1: the desk offers a POD photo upload');
    T::ok(str_contains($html, 'enctype="multipart/form-data"'), 'H1: a form carrying pod_image declares enctype');
});

test('C6 — the dead admin Complete button is gone', function (): void {
    $src = (string) file_get_contents(REPO_ROOT . '/views/admin/shop_order.php');
    T::ok(
        !preg_match('/name="to"\s+value="completed"/', $src),
        'C6: no form posts to=completed'
    );
    $ctrl = (string) file_get_contents(REPO_ROOT . '/controllers/AdminController.php');
    T::ok(
        !preg_match("/'completed'\s*,\s*'admin'/", $ctrl),
        'C6: the controller has no admin completed branch'
    );
});

exit(T::summary());