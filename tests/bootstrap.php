<?php

/**
 * @file   tests/bootstrap.php
 * @brief  Shared harness: test-DB config, autoloading, fixtures, assertions.
 *
 * SAFETY RULES (Phase 1, tmp/shop/remedy.md)
 *   1. This file REFUSES to run unless the target schema name contains "_test".
 *      There is no override and no environment variable that can defeat it.
 *   2. Every test case runs inside a transaction that is rolled back on exit,
 *      so fixtures never survive and no assertion can leave residue.
 *   3. Fixtures are INSERTed by this file. No test may reference a hardcoded
 *      user id, product id, or order id - resolve them through Fixtures::.
 *   4. Nothing here ever writes to the application schema.
 */

declare(strict_types=1);

define('REPO_ROOT', dirname(__DIR__));

// ── 1. Safety gate: refuse to point at a non-test schema ────────────────────
$testDbName = getenv('ALTAS_TEST_DB') ?: 'altas6_test';

if (stripos($testDbName, '_test') === false) {
    fwrite(STDERR, "REFUSING TO RUN.\n"
        . "  Target schema '{$testDbName}' does not contain '_test'.\n"
        . "  Set ALTAS_TEST_DB to a dedicated throwaway schema, e.g. altas6_test.\n");
    exit(2);
}

// ── 2. Point the app's own config at the test schema ─────────────────────────
// config/db.php reads these before defining DB_*; we pre-define them so the
// app code under test is untouched and simply resolves to the test schema.
define('DB_HOST', getenv('ALTAS_TEST_HOST') ?: 'localhost');
define('DB_PORT', getenv('ALTAS_TEST_PORT') ?: '3306');
define('DB_NAME', $testDbName);
define('DB_USER', getenv('ALTAS_TEST_USER') ?: 'u938213108_altas6_admin');
define('DB_PASS', getenv('ALTAS_TEST_PASS') !== false
    ? getenv('ALTAS_TEST_PASS')
    : '2v$J#?M^&F:');

// Reachability check. tests/run.php exists to CREATE the schema, so while it is
// preparing, an absent or empty target is expected and must not abort here —
// run.php owns that path and re-checks once it has loaded install.sql.
if (!defined('TEST_RUNNER_SEEDING')) {
    try {
        $probe = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $n = (int) $probe->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
        )->fetchColumn();
        if ($n === 0) {
            fwrite(STDERR, "REFUSING TO RUN.\n"
                . "  Schema '{$testDbName}' exists but has no tables.\n"
                . "  Build it with:  php tests/run.php\n");
            exit(2);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "REFUSING TO RUN.\n"
            . "  Cannot reach '{$testDbName}': " . $e->getMessage() . "\n"
            . "  Build it with:  php tests/run.php\n");
        exit(2);
    }
}

// ── 3. Load the application exactly as index.php does ───────────────────────
// Read the application schema's real name out of config/db.php BEFORE the
// pre-defined DB_NAME shadows it. The runner prints this so a log line always
// shows which schema the app uses and which one the tests are allowed to touch.
(function (): void {
    $src = (string) file_get_contents(REPO_ROOT . '/config/db.php');
    if (preg_match("/defined\('DB_NAME'\)\s*\|\|\s*define\(\s*'DB_NAME'\s*,\s*'([^']+)'/", $src, $m)) {
        define('DB_APP_NAME', $m[1]);
        return;
    }
    define('DB_APP_NAME', '(could not determine)');
})();

require_once REPO_ROOT . '/config/db.php';
require_once REPO_ROOT . '/core/helpers.php';

spl_autoload_register(function (string $class): void {
    foreach (['models/', 'controllers/', 'core/'] as $dir) {
        $file = REPO_ROOT . '/' . $dir . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

date_default_timezone_set('Asia/Manila');

/**
 * Test result collector. One process-wide instance so every helper file can
 * report without threading a return value back through the call stack.
 */
final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    /** @var string[] */
    public static array $failures = [];
    public static string $group = '(ungrouped)';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n\033[1m── {$name}\033[0m\n";
    }

    /** Assert truthy. */
    public static function ok(bool $cond, string $what, string $detail = ''): bool
    {
        if ($cond) {
            self::$pass++;
            echo "  \033[32mPASS\033[0m {$what}\n";
            return true;
        }
        self::$fail++;
        $msg = self::$group . ' :: ' . $what . ($detail !== '' ? "  ({$detail})" : '');
        self::$failures[] = $msg;
        echo "  \033[31mFAIL\033[0m {$what}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
        return false;
    }

    /** Assert strict equality. */
    public static function eq($expected, $actual, string $what): bool
    {
        return self::ok(
            $expected === $actual,
            $what,
            'expected ' . self::show($expected) . ', got ' . self::show($actual)
        );
    }

    /** Assert a value is contained in a set. */
    public static function inSet($needle, array $haystack, string $what): bool
    {
        return self::ok(
            in_array($needle, $haystack, true),
            $what,
            'got ' . self::show($needle) . ', allowed ' . implode('|', array_map([self::class, 'show'], $haystack))
        );
    }

    /** Assert a callable throws a given exception class. */
    public static function throws(string $class, callable $fn, string $what): bool
    {
        try {
            $fn();
        } catch (Throwable $e) {
            return self::ok(
                $e instanceof $class,
                $what,
                'threw ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 90)
            );
        }
        return self::ok(false, $what, 'did not throw');
    }

    private static function show($v): string
    {
        if (is_bool($v))  return $v ? 'true' : 'false';
        if ($v === null)   return 'null';
        if (is_array($v))  return 'array(' . count($v) . ')';
        return "'" . (string) $v . "'";
    }

    public static function summary(): int
    {
        $total = self::$pass + self::$fail;
        echo "\n" . str_repeat('=', 72) . "\n";
        if (self::$fail === 0) {
            echo "\033[32mALL GREEN\033[0m  {$total} assertions, 0 failed\n";
        } else {
            echo "\033[31mFAILED\033[0m  {$total} assertions, " . self::$fail . " failed\n\n";
            foreach (self::$failures as $f) {
                echo "  - {$f}\n";
            }
        }
        echo str_repeat('=', 72) . "\n";
        echo "schema: " . DB_NAME . "\n";
        return self::$fail === 0 ? 0 : 1;
    }
}

/**
 * Fixtures. Every test resolves ids through here; no hardcoded ids anywhere.
 * Each fixture row is created inside the test's transaction and vanishes on
 * rollback, so repeated runs are idempotent without any cleanup code.
 */
final class Fixtures
{
    /** A member with a known e-wallet balance. */
    public static function member(float $balance = 1000.00, string $username = null): int
    {
        $username = $username ?: ('t_member_' . bin2hex(random_bytes(4)));
        // password_hash is the real column name (users.password does not exist).
        db()->prepare(
            "INSERT INTO users (username, password_hash, full_name, role, status, ewallet_balance, withdrawable_balance)
             VALUES (?, ?, 'Test Member', 'member', 'active', ?, ?)"
        )->execute([$username, password_hash('test-only', PASSWORD_BCRYPT, ['cost' => 4]), $balance, $balance]);
        return (int) db()->lastInsertId();
    }

    /** An admin account. */
    public static function admin(string $username = null): int
    {
        $username = $username ?: ('t_admin_' . bin2hex(random_bytes(4)));
        db()->prepare(
            "INSERT INTO users (username, password_hash, full_name, role, status)
             VALUES (?, ?, 'Test Admin', 'admin', 'active')"
        )->execute([$username, password_hash('test-only', PASSWORD_BCRYPT, ['cost' => 4])]);
        return (int) db()->lastInsertId();
    }

    public static function product(string $name = null, float $price = 100.00, int $stock = 10): int
    {
        $name = $name ?: ('t_prod_' . bin2hex(random_bytes(4)));
        db()->prepare(
            "INSERT INTO products (sku, name, price, stock, status) VALUES (?, ?, ?, ?, 'active')"
        )->execute(['SKU-' . bin2hex(random_bytes(4)), $name, $price, $stock]);
        return (int) db()->lastInsertId();
    }

    /** A cart for a member, plus N units of a product. */
    public static function cartWith(int $memberId, int $productId, int $qty = 1): int
    {
        $cartId = (int) Cart::getOrCreate($memberId)['id'];
        Cart::addItem($cartId, $productId, $qty);
        return $cartId;
    }

    /**
     * A shop order with items, straight to `pending`, bypassing checkout.
     * Used by tests about transitions rather than about order creation.
     */
    public static function pendingOrder(int $memberId, int $productId, int $qty = 1, ?float $total = null): int
    {
        $prod = Product::find($productId);
        $total = $total ?? ((float) $prod['price'] * $qty);
        db()->prepare(
            "INSERT INTO shop_orders
               (order_no, member_id, total_price, payment_method, status, payment_deadline,
                billing_name, shipping_name, shipping_address)
             VALUES (?, ?, ?, 'gcash', 'pending', DATE_ADD(NOW(), INTERVAL 24 HOUR), 'T', 'T', 'T')"
        )->execute(['T' . bin2hex(random_bytes(8)), $memberId, $total]);
        $orderId = (int) db()->lastInsertId();
        db()->prepare(
            "INSERT INTO shop_order_items (order_id, product_id, product_name, quantity, unit_price, total_price, reservation_state)
             VALUES (?, ?, ?, ?, ?, ?, 'reserved')"
        )->execute([$orderId, $productId, $prod['name'], $qty, $prod['price'], $total]);
        return $orderId;
    }

    public static function itemState(int $orderId, ?int $productId = null): string
    {
        $sql = "SELECT reservation_state FROM shop_order_items WHERE order_id = ?";
        $p = [$orderId];
        if ($productId !== null) { $sql .= " AND product_id = ?"; $p[] = $productId; }
        $st = db()->prepare($sql);
        $st->execute($p);
        return (string) ($st->fetchColumn() ?: '');
    }

    public static function itemStates(int $orderId): array
    {
        $st = db()->prepare("SELECT reservation_state FROM shop_order_items WHERE order_id = ?");
        $st->execute([$orderId]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function statusOf(int $orderId): string
    {
        $st = db()->prepare("SELECT status FROM shop_orders WHERE id = ?");
        $st->execute([$orderId]);
        return (string) ($st->fetchColumn() ?: '');
    }

    public static function stockOf(int $productId): int
    {
        $st = db()->prepare("SELECT stock FROM products WHERE id = ?");
        $st->execute([$productId]);
        return (int) ($st->fetchColumn() ?: 0);
    }

    public static function versionOf(int $orderId): int
    {
        $st = db()->prepare("SELECT version FROM shop_orders WHERE id = ?");
        $st->execute([$orderId]);
        return (int) ($st->fetchColumn() ?: 0);
    }

    public static function eventCount(int $orderId): int
    {
        $st = db()->prepare("SELECT COUNT(*) FROM shop_order_events WHERE order_id = ?");
        $st->execute([$orderId]);
        return (int) $st->fetchColumn();
    }

    /** Raw scalar read, for assertions about columns the helpers don't cover. */
    public static function scalar(string $sql, array $params = [])
    {
        $st = db()->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    /**
     * Fake a logged-in session.
     *
     * Auth::check() reads $_SESSION directly, and CLI has none, so
     * ShopOrder::assertNotSelfReview() is inert unless a session exists. Setting
     * the two keys it reads is enough to exercise the segregation-of-duties
     * guard (H6) from the harness. Always pair with logout().
     */
    public static function loginAs(int $userId, string $role = 'member'): void
    {
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = $role;
    }

    public static function logout(): void
    {
        unset($_SESSION['user_id'], $_SESSION['user_role']);
    }

    public static function lastEvent(int $orderId): ?array
    {
        $st = db()->prepare("SELECT * FROM shop_order_events WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$orderId]);
        return $st->fetch() ?: null;
    }
}

/**
 * Reset every table the shop touches, leaving reference data (users, settings,
 * packages) alone.
 *
 * WHY NOT A TRANSACTION PER TEST: the code under test manages its own
 * transactions — ShopOrder::transition(), ShopOrder::createFromCart(),
 * ShopRefund::markPaid() and Ewallet::debitInternal() each call
 * $pdo->beginTransaction() themselves, and PDO has no nested transactions, so
 * an outer wrapper makes them throw "There is already an active transaction".
 * Savepoints do not help either, because the inner beginTransaction() still
 * fails.
 *
 * The app schema is never targeted (see the safety gate above) and this schema
 * is disposable, so truncation between cases is both safe and fast. Isolation
 * is by schema ownership, not by rollback.
 */
function resetState(): void
{
    $pdo = db();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ([
        'shop_order_events', 'shop_shipments', 'shop_order_items',
        'shop_payment_proofs', 'shop_refunds', 'shop_orders',
        'cart_items', 'carts', 'products',
        'ewallet_ledger', 'commissions', 'payout_requests',
    ] as $table) {
        $pdo->exec("TRUNCATE TABLE `{$table}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

/**
 * Run one test case. State is truncated before and after, so a case cannot
 * observe residue from an earlier one and cannot leave residue behind.
 */
function test(string $name, callable $fn): void
{
    resetState();
    try {
        $fn();
    } catch (Throwable $e) {
        T::ok(false, $name . ' :: uncaught ' . get_class($e), substr($e->getMessage(), 0, 160));
    } finally {
        resetState();
    }
}