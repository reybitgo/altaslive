<?php

/**
 * Automated test for the VOLUME-BASED binary pairing engine (v3).
 *
 * Verifies:
 *  - qapro1 scenario: left leg 2500+2500 vs right Starter 1500 → settle ₱1,500
 *    (NOT ₱3,000), unmatched left volume carries over.
 *  - Incremental carryover settlement on subsequent right placements.
 *  - Daily cap in pesos: daily_pair_cap × own pairing_bonus.
 *  - CD-sourced members contribute 0 volume and never settle.
 *  - Legacy count columns still increment; legacy pairs_paid stays frozen.
 *
 * Tree built:
 *   qapro1 (QA Pro 3000, cap 3)
 *   ├─ left:  qanoindirect (2500)
 *   │   ├─ left: qanoindirect2 (2500)
 *   │   └─ right: pvtest_cdbody (CD — 0 volume)
 *   └─ right: starter1 (1500) → starter2 (1500) → starter3 (1500)
 *
 * Run: php tmp/pair_volume/test_pair_volume.php
 *
 * NOTE: Requires migrate_pair_volume.sql to have been applied.
 */

// ── Bootstrap ──────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/helpers.php';

spl_autoload_register(function (string $class): void {
    foreach ([__DIR__ . '/../../models/', __DIR__ . '/../../core/'] as $dir) {
        $file = $dir . $class . '.php';
        if (file_exists($file)) { require_once $file; return; }
    }
});

// ── Test Runner ────────────────────────────────────────────────────────────────
$tests = [];

function test(string $name, callable $fn): void
{
    global $tests;
    $tests[] = ['name' => $name, 'fn' => $fn];
}

function run_tests(): array
{
    global $tests;
    $passed = 0;
    $failed = 0;
    $results = [];

    foreach ($tests as $t) {
        try {
            $assertions = $t['fn']();
            $allOk = true;
            foreach ($assertions as $a) {
                if (!$a['ok']) { $allOk = false; break; }
            }
            if ($allOk) {
                $passed++;
                $results[] = ['name' => $t['name'], 'ok' => true, 'assertions' => $assertions];
            } else {
                $failed++;
                $results[] = ['name' => $t['name'], 'ok' => false, 'assertions' => $assertions];
            }
        } catch (Throwable $e) {
            $failed++;
            $results[] = [
                'name' => $t['name'],
                'ok' => false,
                'assertions' => [['ok' => false, 'msg' => 'EXCEPTION: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()]],
            ];
        }
    }

    return ['results' => $results, 'passed' => $passed, 'failed' => $failed, 'total' => count($tests)];
}

function assert_true(mixed $value, string $msg): array
{
    return ['ok' => (bool)$value, 'msg' => $msg, 'actual' => $value];
}

function assert_eq(mixed $expected, mixed $actual, string $msg): array
{
    $ok = $expected === $actual;
    return ['ok' => $ok, 'msg' => $msg, 'expected' => $expected, 'actual' => $actual];
}

function print_result(array $result): void
{
    echo "\n" . str_repeat("-", 72) . "\n";
    echo ($result['ok'] ? ' OK ' : ' FAIL ') . $result['name'] . "\n";
    echo str_repeat("-", 72) . "\n";

    foreach ($result['assertions'] as $a) {
        $mark = $a['ok'] ? '  OK' : '  X';
        echo "{$mark} {$a['msg']}";
        if (!$a['ok'] && isset($a['expected'], $a['actual'])) {
            echo ' (expected: ' . var_export($a['expected'], true) . ', got: ' . var_export($a['actual'], true) . ')';
        } elseif (!$a['ok'] && isset($a['actual'])) {
            echo ' (got: ' . var_export($a['actual'], true) . ')';
        }
        echo "\n";
    }
}

function print_summary(array $stats): void
{
    echo "\n" . str_repeat("=", 72) . "\n";
    echo "  {$stats['passed']}/{$stats['total']} tests passed" . ($stats['failed'] > 0 ? ", {$stats['failed']} FAILED" : '') . "\n";
    echo str_repeat("=", 72) . "\n\n";
}

// ── DB Helpers ─────────────────────────────────────────────────────────────────
function td(): PDO { return db(); }

function id_of(string $username): int
{
    $st = td()->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([$username]);
    return (int)$st->fetchColumn();
}

function delete_test_data(): void
{
    $pdo = td();
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("DELETE FROM commissions WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'pvtest\\_%')");
    $pdo->exec("DELETE FROM ewallet_ledger WHERE note LIKE '%pvtest\\_%'");
    $pdo->exec("DELETE cs FROM user_cd_status cs INNER JOIN users u ON u.id = cs.user_id WHERE u.username LIKE 'pvtest\\_%'");
    $pdo->exec("DELETE FROM users WHERE username LIKE 'pvtest\\_%'");
    $pdo->exec("DELETE FROM reg_codes WHERE code LIKE 'PVTEST\\_%'");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
}

function create_code(int $packageId, bool $isCd): array
{
    $pdo = td();
    $codeStr = 'PVTEST_' . ($isCd ? 'CD_' : 'PAID_') . bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO reg_codes (code, package_id, price, status, created_by, code_type, expires_at) VALUES (?, ?, 0.00, 'unused', 1, ?, DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
        ->execute([$codeStr, $packageId, $isCd ? 'cd' : 'registration']);
    return ['id' => (int)$pdo->lastInsertId(), 'code' => $codeStr];
}

function reg(array $data): int
{
    return User::register($data);
}

function reg_root(string $username, int $packageId, int $codeId): int
{
    // Freestanding root (binary_parent_id NULL) — same pattern as the existing
    // qaretail rows. Placing its downline only walks within the test subtree,
    // so the live tree's counters are never touched.
    $pdo = td();
    $pdo->prepare("
        INSERT INTO users
          (username, password_hash, package_id, reg_code_id, reg_payment_method,
           sponsor_id, binary_parent_id, binary_position, status)
        VALUES (?, ?, ?, ?, 'code', ?, NULL, NULL, 'active')
    ")->execute([
        $username,
        password_hash('TestPass123!', PASSWORD_BCRYPT, ['cost' => 12]),
        $packageId,
        $codeId,
        1,
    ]);
    return (int)$pdo->lastInsertId();
}

function get_vol(int $userId): array
{
    $st = td()->prepare("
        SELECT left_pair_volume, right_pair_volume,
               pairs_volume_paid, pairs_volume_today,
               pairs_paid, pairs_paid_today,
               left_count, right_count
        FROM users WHERE id = ?
    ");
    $st->execute([$userId]);
    $row = $st->fetch();
    return $row ?: [
        'left_pair_volume' => 0, 'right_pair_volume' => 0,
        'pairs_volume_paid' => 0, 'pairs_volume_today' => 0,
        'pairs_paid' => 0, 'pairs_paid_today' => 0,
        'left_count' => 0, 'right_count' => 0,
    ];
}

function pairing_credited(int $userId, float $amount): bool
{
    $st = td()->prepare("
        SELECT COUNT(*) FROM commissions
        WHERE user_id = ? AND type = 'pairing' AND status = 'credited'
          AND ABS(amount - ?) < 0.001
    ");
    $st->execute([$userId, $amount]);
    return (int)$st->fetchColumn() > 0;
}

function pairing_total(int $userId): float
{
    $st = td()->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM commissions
        WHERE user_id = ? AND type = 'pairing' AND status = 'credited'
    ");
    $st->execute([$userId]);
    return (float)$st->fetchColumn();
}

function pairing_flushed_count(int $userId): int
{
    $st = td()->prepare("
        SELECT COUNT(*) FROM commissions
        WHERE user_id = ? AND type = 'pairing' AND status = 'flushed'
          AND description LIKE 'Matched volume%'
    ");
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

// ── Package fixtures ───────────────────────────────────────────────────────────
function upsert_package(int $id, string $name, float $fee, float $pair, int $cap, int $pairingEnabled = 1): void
{
    td()->prepare("
        INSERT INTO packages
          (id, name, entry_fee, pairing_bonus, daily_pair_cap, direct_ref_bonus,
           lifetime_cap_multiplier, reactivation_fee, reactivation_window_days,
           daily_fixed_income, daily_fixed_income_days, status, pairing_enabled)
        VALUES (?, ?, ?, ?, ?, 0.00, 1.00, ?, 15, 0.00, 0, 'active', ?)
        ON DUPLICATE KEY UPDATE
          name = VALUES(name), entry_fee = VALUES(entry_fee),
          pairing_bonus = VALUES(pairing_bonus), daily_pair_cap = VALUES(daily_pair_cap),
          status = 'active', pairing_enabled = VALUES(pairing_enabled)
    ")->execute([$id, $name, $fee, $pair, $cap, $fee, $pairingEnabled]);
}

// ── Setup ──────────────────────────────────────────────────────────────────────
echo "Bootstrapping test environment...\n";
delete_test_data();

upsert_package(901, 'PV QA Pro',        25000.00, 3000.00, 3, 1);
upsert_package(902, 'PV QA NoIndirect', 20000.00, 2500.00, 3, 1);
upsert_package(903, 'PV Starter',       10000.00, 1500.00, 5, 1);
upsert_package(904, 'PV QA Retail',     15000.00, 0.00,    0, 0);

$proCode     = create_code(901, false);
$noInd1      = create_code(902, false);
$noInd2      = create_code(902, false);
$starter1    = create_code(903, false);
$starter2    = create_code(903, false);
$starter3    = create_code(903, false);
$cdCode      = create_code(903, true);

// ══════════════════════════════════════════════════════════════════════════════
//  T1: qapro1 scenario — volume-based settlement
//  Expect: left_volume=5000, right_volume=1500, paid=1500 (NOT 3000)
// ══════════════════════════════════════════════════════════════════════════════
test('T1: qapro1 earns matched volume ₱1,500 (not ₱3,000)', function () use ($proCode, $noInd1, $noInd2, $starter1) {
    $qapro1 = reg_root('pvtest_qapro1', 901, $proCode['id']);

    $left = reg([
        'username' => 'pvtest_qanoindirect', 'password' => 'TestPass123!',
        'package_id' => 902, 'reg_code_id' => $noInd1['id'], 'reg_payment_method' => 'code',
        'sponsor_id' => $qapro1, 'binary_parent_id' => $qapro1, 'binary_position' => 'left', 'pending' => false,
    ]);

    reg([
        'username' => 'pvtest_qanoindirect2', 'password' => 'TestPass123!',
        'package_id' => 902, 'reg_code_id' => $noInd2['id'], 'reg_payment_method' => 'code',
        'sponsor_id' => $left, 'binary_parent_id' => $left, 'binary_position' => 'left', 'pending' => false,
    ]);

    reg([
        'username' => 'pvtest_starter1', 'password' => 'TestPass123!',
        'package_id' => 903, 'reg_code_id' => $starter1['id'], 'reg_payment_method' => 'code',
        'sponsor_id' => $qapro1, 'binary_parent_id' => $qapro1, 'binary_position' => 'right', 'pending' => false,
    ]);

    $v = get_vol($qapro1);

    return [
        assert_eq(5000.00, (float)$v['left_pair_volume'],  'left_pair_volume = 2500 + 2500 = 5000'),
        assert_eq(1500.00, (float)$v['right_pair_volume'], 'right_pair_volume = starter1 = 1500'),
        assert_eq(1500.00, (float)$v['pairs_volume_paid'], 'pairs_volume_paid = min(5000,1500) = 1500'),
        assert_true(pairing_credited($qapro1, 1500.00),    'commissions has a ₱1,500.00 credited pairing record'),
        assert_true(!pairing_credited($qapro1, 3000.00),   'NO ₱3,000.00 pairing record (old bug)'),
        assert_eq(0, (int)$v['pairs_paid'],                'legacy pairs_paid frozen at 0'),
        assert_eq(2, (int)$v['left_count'],                'left_count = 2 members'),
        assert_eq(1, (int)$v['right_count'],               'right_count = 1 member'),
    ];
});

// ══════════════════════════════════════════════════════════════════════════════
//  T2: Carryover — starter2 under starter1.right settles the next ₱1,500
//  right volume 1500→3000; min(5000,3000)=3000; paid 1500 → new = 1500
// ══════════════════════════════════════════════════════════════════════════════
test('T2: unmatched left volume carries over for later settlements', function () use ($starter2) {
    $qapro1   = id_of('pvtest_qapro1');
    $starter1 = id_of('pvtest_starter1');

    reg([
        'username' => 'pvtest_starter2', 'password' => 'TestPass123!',
        'package_id' => 903, 'reg_code_id' => $starter2['id'], 'reg_payment_method' => 'code',
        'sponsor_id' => $qapro1, 'binary_parent_id' => $starter1, 'binary_position' => 'right', 'pending' => false,
    ]);

    $v = get_vol($qapro1);

    return [
        assert_eq(3000.00, (float)$v['right_pair_volume'], 'right_pair_volume = 1500 + 1500 = 3000'),
        assert_eq(3000.00, (float)$v['pairs_volume_paid'], 'pairs_volume_paid = min(5000,3000) = 3000'),
        assert_true(abs(pairing_total($qapro1) - 3000.00) < 0.001, 'carryover settled → total credited = ₱3,000.00'),
        assert_eq(3000.00, (float)$v['pairs_volume_today'],'pairs_volume_today = 3000'),
    ];
});

// ══════════════════════════════════════════════════════════════════════════════
//  T3: Daily cap in pesos — QA Pro cap = 3 × ₱3,000 = ₱9,000/day
//  starter3 under starter2.right → right 3000→4500; new=min(5000,4500)-3000=1500
//  cap_remaining = 9000-3000 = 6000 → fully paid (1500), no flush
// ══════════════════════════════════════════════════════════════════════════════
test('T3: daily cap is daily_pair_cap x own pairing_bonus pesos', function () use ($starter3) {
    $qapro1    = id_of('pvtest_qapro1');
    $starter2  = id_of('pvtest_starter2');

    reg([
        'username' => 'pvtest_starter3', 'password' => 'TestPass123!',
        'package_id' => 903, 'reg_code_id' => $starter3['id'], 'reg_payment_method' => 'code',
        'sponsor_id' => $qapro1, 'binary_parent_id' => $starter2, 'binary_position' => 'right', 'pending' => false,
    ]);

    $v = get_vol($qapro1);

    return [
        assert_eq(4500.00, (float)$v['right_pair_volume'], 'right volume now 4500'),
        assert_eq(4500.00, (float)$v['pairs_volume_paid'], 'all within ₱9,000 daily cap → fully paid'),
        assert_eq(0, pairing_flushed_count($qapro1),       'no flush recorded (under daily cap)'),
        assert_eq(4500.00, (float)$v['pairs_volume_today'],'pairs_volume_today = 4500 (< 9000 cap)'),
    ];
});

// ══════════════════════════════════════════════════════════════════════════════
//  T4: CD body contributes zero volume and never settles
//  CD body at qanoindirect.right → qapro1.left_count 2→3, left volume stays 5000
// ══════════════════════════════════════════════════════════════════════════════
test('T4: CD-sourced member contributes 0 volume to ancestors', function () use ($cdCode) {
    $qapro1       = id_of('pvtest_qapro1');
    $qanoindirect = id_of('pvtest_qanoindirect');
    $before       = get_vol($qapro1);

    reg([
        'username' => 'pvtest_cdbody', 'password' => 'TestPass123!',
        'package_id' => 903, 'reg_code_id' => $cdCode['id'], 'reg_payment_method' => 'code',
        'sponsor_id' => $qapro1, 'binary_parent_id' => $qanoindirect, 'binary_position' => 'right', 'pending' => false,
    ]);

    $after = get_vol($qapro1);

    return [
        assert_eq(5000.00, (float)$after['left_pair_volume'], 'left volume unchanged (CD adds 0)'),
        assert_eq($before['pairs_volume_paid'], $after['pairs_volume_paid'], 'no settlement triggered by CD body'),
        assert_eq((int)$before['left_count'] + 1, (int)$after['left_count'], 'left_count incremented by CD body'),
        assert_eq(4500.00, (float)$after['right_pair_volume'], 'right volume unchanged'),
    ];
});

// ══════════════════════════════════════════════════════════════════════════════
//  RUN
// ══════════════════════════════════════════════════════════════════════════════
$stats = run_tests();

foreach ($stats['results'] as $r) {
    print_result($r);
}

print_summary($stats);

// ── Cleanup ──────────────────────────────────────────────────────────────────
echo "Cleaning up test data...\n";
delete_test_data();
echo "Done.\n";

if ($stats['failed'] > 0) {
    exit(1);
}