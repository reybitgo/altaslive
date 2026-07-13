<?php

/**
 * Automated test for CD Filter Earnings feature.
 *
 * Tests that CD-sourced members do not earn commissions for their uplines
 * while paid members continue to earn normally.
 *
 * Run: php tmp/cd/test_filter_earnings.php
 *       php tmp/cd/test_filter_earnings.php --verbose
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

$isVerbose = in_array('--verbose', $_SERVER['argv'] ?? [], true);

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
                if (!$a['ok']) {
                    $allOk = false;
                    break;
                }
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

function assert_gt(mixed $expected, mixed $actual, string $msg): array
{
    $ok = $actual > $expected;
    return ['ok' => $ok, 'msg' => $msg . " (expected > {$expected})", 'actual' => $actual];
}

// ── Output ─────────────────────────────────────────────────────────────────────
function print_result(array $result): void
{
    echo "\n";
    echo str_repeat("-", 72) . "\n";
    echo ($result['ok'] ? ' OK ' : ' FAIL ') . $result['name'] . "\n";
    echo str_repeat("-", 72) . "\n";

    foreach ($result['assertions'] as $i => $a) {
        $mark = $a['ok'] ? '  OK' : '  X';
        echo "{$mark} {$a['msg']}";
        if (!$a['ok'] && isset($a['expected'], $a['actual'])) {
            echo ' (expected: ' . json_encode($a['expected']) . ', got: ' . json_encode($a['actual']) . ')';
        } elseif (!$a['ok'] && isset($a['actual'])) {
            echo ' (got: ' . json_encode($a['actual']) . ')';
        }
        echo "\n";
    }
}

function print_summary(array $stats): void
{
    echo "\n";
    echo str_repeat("=", 72) . "\n";
    echo "  {$stats['passed']}/{$stats['total']} tests passed" . ($stats['failed'] > 0 ? ", {$stats['failed']} FAILED" : '') . "\n";
    echo str_repeat("=", 72) . "\n\n";
}

// ── DB Helpers ─────────────────────────────────────────────────────────────────
function td(): PDO
{
    return db();
}

function delete_test_data(): void
{
    $pdo = td();
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("DELETE FROM users WHERE username LIKE '_cdtest\_%'");
    $pdo->exec("DELETE FROM commissions WHERE description LIKE '%_cdtest\_%' OR description LIKE '%[TEST]%'");
    $pdo->exec("DELETE FROM reg_codes WHERE code LIKE 'TEST\_%'");
    $pdo->exec("DELETE FROM ewallet_ledger WHERE note LIKE '%_cdtest\_%'");
    $pdo->exec("DELETE cs FROM user_cd_status cs INNER JOIN users u ON u.id = cs.user_id WHERE u.username LIKE '_cdtest\_%'");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
}

function create_test_code(int $packageId, bool $isCd): array
{
    $pdo = td();
    $prefix = $isCd ? 'CD-' : '';
    $codeStr = 'TEST_' . ($isCd ? 'CD_' : 'PAID_') . bin2hex(random_bytes(4));
    $fullCode = $prefix . $codeStr;

    $pdo->prepare("
        INSERT INTO reg_codes (code, package_id, price, status, created_by, is_cd)
        VALUES (?, ?, 0.00, 'unused', 1, ?)
    ")->execute([$fullCode, $packageId, $isCd ? 1 : 0]);

    $id = (int)$pdo->lastInsertId();
    return ['id' => $id, 'code' => $fullCode];
}

function register_user(array $data): int
{
    return User::register($data);
}

function credit_ewallet(int $userId, float $amount): void
{
    $pdo = td();
    $pdo->prepare("UPDATE users SET ewallet_balance = ewallet_balance + ?, withdrawable_balance = withdrawable_balance + ? WHERE id = ?")
        ->execute([$amount, $amount, $userId]);
}

function get_commission_count(int $userId): int
{
    return (int)td()->prepare("SELECT COUNT(*) FROM commissions WHERE user_id = ? AND status = 'credited'")
        ->execute([$userId])
        ? (int)td()->query("SELECT COUNT(*) FROM commissions WHERE user_id = {$userId} AND status = 'credited'")->fetchColumn()
        : 0;
}

function get_commission_count_raw(): int
{
    return (int)td()->query("SELECT COUNT(*) FROM commissions WHERE description LIKE '%_cdtest\_%'")->fetchColumn();
}

function get_pairing_info(int $userId): array
{
    $st = td()->prepare("SELECT left_count, left_count_paid, right_count, right_count_paid, pairs_paid, pairs_paid_today FROM users WHERE id = ?");
    $st->execute([$userId]);
    return $st->fetch() ?: ['left_count' => 0, 'left_count_paid' => 0, 'right_count' => 0, 'right_count_paid' => 0, 'pairs_paid' => 0, 'pairs_paid_today' => 0];
}

function get_package_id(): int
{
    return (int)td()->query("SELECT id FROM packages WHERE status = 'active' ORDER BY entry_fee ASC LIMIT 1")->fetchColumn();
}

// ── Setup ──────────────────────────────────────────────────────────────────────
echo "Bootstrapping test environment...\n";
delete_test_data();

$packageId = get_package_id();
if (!$packageId) {
    echo "ERROR: No active package found. Create one via Admin -> Packages first.\n";
    exit(1);
}

$adminId = 1;

// Create codes
$nonCdCode = create_test_code($packageId, false);
echo "  Non-CD code: {$nonCdCode['code']} (id={$nonCdCode['id']})\n";
$cdCode = create_test_code($packageId, true);
echo "  CD code:     {$cdCode['code']} (id={$cdCode['id']})\n";

// Register the sponsor member
echo "  Registering sponsor...\n";
$sponsorId = register_user([
    'username'           => '_cdtest_sponsor',
    'password'           => 'TestPass123!',
    'package_id'         => $packageId,
    'reg_code_id'        => $nonCdCode['id'],
    'reg_payment_method' => 'code',
    'sponsor_id'         => null,
    'binary_parent_id'   => null,
    'binary_position'    => 'left',
    'pending'            => false,
]);
echo "  Sponsor ID: {$sponsorId}\n";

// Give sponsor e-wallet balance
credit_ewallet($sponsorId, 50000);
echo "  Sponsor e-wallet credited with 50000\n";

// ── Global tree state ──────────────────────────────────────────────────────────
// T1 and T2 establish root children.  All subsequent tests place their
// members deeper in the tree under these (or under dedicated sub-parents).
$tree = [];

// ══════════════════════════════════════════════════════════════════════════════
//  TEST 1: CD Registration Produces Zero Commissions
// ══════════════════════════════════════════════════════════════════════════════
test('T1: CD registration -> zero commissions to sponsor', function () use ($packageId, $cdCode, $sponsorId, &$tree) {
    $commCountBefore = get_commission_count($sponsorId);

    $newId = register_user([
        'username'           => '_cdtest_user1',
        'password'           => 'TestPass123!',
        'package_id'         => $packageId,
        'reg_code_id'        => $cdCode['id'],
        'reg_payment_method' => 'code',
        'sponsor_id'         => $sponsorId,
        'binary_parent_id'   => $sponsorId,
        'binary_position'    => 'left',
        'pending'            => false,
    ]);
    $tree['t1_cd'] = $newId;

    $commCountAfter = get_commission_count($sponsorId);
    $isPaid = User::isPaidMember($newId);

    return [
        assert_eq($commCountBefore, $commCountAfter, 'Sponsor commission count should not increase after CD registration'),
        assert_eq(false, $isPaid, 'isPaidMember() should return false for CD-registered user'),
    ];
});

// ══════════════════════════════════════════════════════════════════════════════
//  TEST 2: Non-CD (Paid) Registration Produces Commissions
// ══════════════════════════════════════════════════════════════════════════════
test('T2: Non-CD registration -> commissions generated', function () use ($packageId, $sponsorId, &$tree) {
    $freshCode = create_test_code($packageId, false);
    $commCountBefore = get_commission_count($sponsorId);

    $newId = register_user([
        'username'           => '_cdtest_user2',
        'password'           => 'TestPass123!',
        'package_id'         => $packageId,
        'reg_code_id'        => $freshCode['id'],
        'reg_payment_method' => 'code',
        'sponsor_id'         => $sponsorId,
        'binary_parent_id'   => $sponsorId,
        'binary_position'    => 'right',
        'pending'            => false,
    ]);
    $tree['t2_paid'] = $newId;

    $commCountAfter = get_commission_count($sponsorId);
    $isPaid = User::isPaidMember($newId);

    return [
        assert_gt($commCountBefore, $commCountAfter, 'Sponsor commission count should increase after non-CD (paid) registration'),
        assert_eq(true, $isPaid, 'isPaidMember() should return true for non-CD code registrant'),
    ];
});

// ══════════════════════════════════════════════════════════════════════════════
//  TEST 3: isPaidMember() -- All Registration Methods
//  Tree: under t1_cd (has free left/right) and t2_paid.left (free)
// ══════════════════════════════════════════════════════════════════════════════
test('T3: isPaidMember() returns correct values for all payment methods', function () use ($packageId, &$tree) {
    $cdUser = $tree['t1_cd'];
    $paidUser = $tree['t2_paid'];

    $freshCode = create_test_code($packageId, false);
    $codeUserId = register_user([
        'username'           => '_cdtest_paid_code',
        'password'           => 'TestPass123!',
        'package_id'         => $packageId,
        'reg_code_id'        => $freshCode['id'],
        'reg_payment_method' => 'code',
        'sponsor_id'         => $cdUser,
        'binary_parent_id'   => $cdUser,
        'binary_position'    => 'left',
        'pending'            => false,
    ]);
    $tree['t3_code'] = $codeUserId;

    $cdCode2 = create_test_code($packageId, true);
    $cdUserId = register_user([
        'username'           => '_cdtest_is_cd',
        'password'           => 'TestPass123!',
        'package_id'         => $packageId,
        'reg_code_id'        => $cdCode2['id'],
        'reg_payment_method' => 'code',
        'sponsor_id'         => $cdUser,
        'binary_parent_id'   => $cdUser,
        'binary_position'    => 'right',
        'pending'            => false,
    ]);
    $tree['t3_cd'] = $cdUserId;

    $pendingCode = create_test_code($packageId, false);
    $pendingUserId = register_user([
        'username'           => '_cdtest_pending',
        'password'           => 'TestPass123!',
        'package_id'         => $packageId,
        'reg_code_id'        => $pendingCode['id'],
        'reg_payment_method' => 'pending',
        'sponsor_id'         => $cdUser,
        'binary_parent_id'   => $paidUser,
        'binary_position'    => 'left',
        'pending'            => true,
    ]);
    $tree['t3_pending'] = $pendingUserId;

    return [
        assert_eq(true,  User::isPaidMember($codeUserId),    'Code-registered (non-CD) user -> isPaidMember = true'),
        assert_eq(false, User::isPaidMember($cdUserId),      'CD-registered user -> isPaidMember = false'),
        assert_eq(false, User::isPaidMember($pendingUserId), 'Pending (referral) user -> isPaidMember = false'),
    ];
});

// ══════════════════════════════════════════════════════════════════════════════
//  TEST 4: CD Binary Placement -- No Pairing Bonus
//  Tree: create t4_parent under t2_paid.right, then CD users at its L and R
// ══════════════════════════════════════════════════════════════════════════════
test('T4: CD binary placement increments leg counts but does NOT trigger pairing', function () use ($packageId, &$tree) {
    $paidUser = $tree['t2_paid'];

    // Create a paid anchor under paidUser.right
    $anchorCode = create_test_code($packageId, false);
    $anchorId = register_user([
        'username'           => '_cdtest_t4_anchor',
        'password'           => 'TestPass123!',
        'package_id'         => $packageId,
        'reg_code_id'        => $anchorCode['id'],
        'reg_payment_method' => 'code',
        'sponsor_id'         => $paidUser,
        'binary_parent_id'   => $paidUser,
        'binary_position'    => 'right',
        'pending'            => false,
    ]);
    $tree['t4_anchor'] = $anchorId;

    $before = get_pairing_info($anchorId);

    $cdLeftCode = create_test_code($packageId, true);
    register_user([
        'username'           => '_cdtest_t4_left',
        'password'           => 'TestPass123!',
        'package_id'         => $packageId,
        'reg_code_id'        => $cdLeftCode['id'],
        'reg_payment_method' => 'code',
        'sponsor_id'         => $anchorId,
        'binary_parent_id'   => $anchorId,
        'binary_position'    => 'left',
        'pending'            => false,
    ]);
    $afterLeft = get_pairing_info($anchorId);

    $cdRightCode = create_test_code($packageId, true);
    register_user([
        'username'           => '_cdtest_t4_right',
        'password'           => 'TestPass123!',
        'package_id'         => $packageId,
        'reg_code_id'        => $cdRightCode['id'],
        'reg_payment_method' => 'code',
        'sponsor_id'         => $anchorId,
        'binary_parent_id'   => $anchorId,
        'binary_position'    => 'right',
        'pending'            => false,
    ]);
    $afterRight = get_pairing_info($anchorId);

    return [
        assert_eq($before['left_count'] + 1,  $afterLeft['left_count'],  'Left count should increment after CD left placement'),
        assert_eq($before['right_count'] + 1, $afterRight['right_count'], 'Right count should increment after CD right placement'),
        assert_eq($before['pairs_paid'],       $afterRight['pairs_paid'],  'pairs_paid should NOT increase from CD-only placements'),
        assert_eq($before['pairs_paid_today'], $afterRight['pairs_paid_today'], 'pairs_paid_today should NOT increase'),
    ];
});

// ══════════════════════════════════════════════════════════════════════════════
//  TEST 5: Mixed Binary -- CD counts excluded from pairing bonus
//  Tree: create t5_anchor under t3_cd.left, place CD-left, then paid right
//  CD-sourced left count should NOT enable a pair when paid right is placed.
//  Only left_count_paid (non-CD bodies) contributes to min(left,right).
// ══════════════════════════════════════════════════════════════════════════════
test('T5: CD-sourced leg counts excluded from pairing bonus', function () use ($packageId, &$tree) {
    $parent = $tree['t3_cd'];

    $anchorCode = create_test_code($packageId, false);
    $anchorId = register_user([
        'username'           => '_cdtest_t5_anchor',
        'password'           => 'TestPass123!',
        'package_id'         => $packageId,
        'reg_code_id'        => $anchorCode['id'],
        'reg_payment_method' => 'code',
        'sponsor_id'         => $parent,
        'binary_parent_id'   => $parent,
        'binary_position'    => 'left',
        'pending'            => false,
    ]);
    $tree['t5_anchor'] = $anchorId;

    $before = get_pairing_info($anchorId);

    // Place CD at left -- increments left_count but NOT left_count_paid
    $cdLeftCode = create_test_code($packageId, true);
    register_user([
        'username'           => '_cdtest_t5_cd_left',
        'password'           => 'TestPass123!',
        'package_id'         => $packageId,
        'reg_code_id'        => $cdLeftCode['id'],
        'reg_payment_method' => 'code',
        'sponsor_id'         => $anchorId,
        'binary_parent_id'   => $anchorId,
        'binary_position'    => 'left',
        'pending'            => false,
    ]);
    $afterCd = get_pairing_info($anchorId);

    // Place paid at right -- increments right_count AND right_count_paid
    $paidCode = create_test_code($packageId, false);
    register_user([
        'username'           => '_cdtest_t5_paid_right',
        'password'           => 'TestPass123!',
        'package_id'         => $packageId,
        'reg_code_id'        => $paidCode['id'],
        'reg_payment_method' => 'code',
        'sponsor_id'         => $anchorId,
        'binary_parent_id'   => $anchorId,
        'binary_position'    => 'right',
        'pending'            => false,
    ]);
    $afterPaid = get_pairing_info($anchorId);

    return [
        // Total counts always increment
        assert_eq($before['left_count'] + 1,  $afterCd['left_count'],   'CD left placement increments left_count'),
        assert_eq($afterCd['right_count'] + 1, $afterPaid['right_count'], 'Paid right placement increments right_count'),
        // Paid counts: CD user does NOT contribute to left_count_paid
        assert_eq($before['left_count_paid'],   $afterCd['left_count_paid'],   'CD left placement does NOT increment left_count_paid'),
        // Paid user DOES contribute to right_count_paid
        assert_eq($afterCd['right_count_paid'] + 1, $afterPaid['right_count_paid'], 'Paid right placement increments right_count_paid'),
        // No pair fires because left_count_paid=0, so min(0,1)=0
        assert_eq($before['pairs_paid'],       $afterCd['pairs_paid'],   'CD left placement does NOT trigger pair'),
        assert_eq($afterCd['pairs_paid'],      $afterPaid['pairs_paid'], 'Paid right with only CD left does NOT trigger pair'),
    ];
});

// ══════════════════════════════════════════════════════════════════════════════
//  TEST 6: CD User Earns from Their Own Paid Downline (with CD split)
//  Tree: create CD user under t3_code.left, then paid under CD user
//  Source-only filter: CD user is the earner, new user is paid → commissions flow
// ══════════════════════════════════════════════════════════════════════════════
test('T6: CD user earns commissions from paid downline (with CD split)', function () use ($packageId, &$tree) {
    $parent = $tree['t3_code'];

    $cdDownlineCode = create_test_code($packageId, true);
    $cdUserId = register_user([
        'username'           => '_cdtest_earner',
        'password'           => 'TestPass123!',
        'package_id'         => $packageId,
        'reg_code_id'        => $cdDownlineCode['id'],
        'reg_payment_method' => 'code',
        'sponsor_id'         => $parent,
        'binary_parent_id'   => $parent,
        'binary_position'    => 'left',
        'pending'            => false,
    ]);
    $tree['t6_cd'] = $cdUserId;

    $cdStatus = CdStatus::getActive($cdUserId);
    $cdActiveBefore = $cdStatus !== null;
    $commBefore = get_commission_count($cdUserId);

    $paidUnderCdCode = create_test_code($packageId, false);
    register_user([
        'username'           => '_cdtest_paid_under_cd',
        'password'           => 'TestPass123!',
        'package_id'         => $packageId,
        'reg_code_id'        => $paidUnderCdCode['id'],
        'reg_payment_method' => 'code',
        'sponsor_id'         => $cdUserId,
        'binary_parent_id'   => $cdUserId,
        'binary_position'    => 'left',
        'pending'            => false,
    ]);

    $commAfter = get_commission_count($cdUserId);

    return [
        assert_eq(true, $cdActiveBefore, 'CD user should have active CD status after CD-code registration'),
        assert_gt($commBefore, $commAfter, 'CD user should earn commissions from paid downline (source-only filter)'),
    ];
});

// ══════════════════════════════════════════════════════════════════════════════
//  TEST 7: Admin-Assigned CD on Paid User -- isPaidMember Unchanged
//  Tree: paid user under t3_code.right, admin assigns CD, isPaidMember still true
//  Admin CD does not change reg_payment_method → source-only filter unaffected
// ══════════════════════════════════════════════════════════════════════════════
test('T7: Admin-assigned CD on paid user -- isPaidMember still true, commissions flow', function () use ($packageId, $adminId, &$tree) {
    $parent = $tree['t3_code'];

    $paidBeforeCdCode = create_test_code($packageId, false);
    $paidUserId = register_user([
        'username'           => '_cdtest_paid_before_cd',
        'password'           => 'TestPass123!',
        'package_id'         => $packageId,
        'reg_code_id'        => $paidBeforeCdCode['id'],
        'reg_payment_method' => 'code',
        'sponsor_id'         => $parent,
        'binary_parent_id'   => $parent,
        'binary_position'    => 'right',
        'pending'            => false,
    ]);
    $tree['t7_paid'] = $paidUserId;

    $beforeAssign = User::isPaidMember($paidUserId);
    CdStatus::assign($paidUserId, 50000, $adminId);
    $afterAssign = User::isPaidMember($paidUserId);

    $underPaidCode = create_test_code($packageId, false);
    $commBefore = get_commission_count($paidUserId);
    register_user([
        'username'           => '_cdtest_under_admin_cd',
        'password'           => 'TestPass123!',
        'package_id'         => $packageId,
        'reg_code_id'        => $underPaidCode['id'],
        'reg_payment_method' => 'code',
        'sponsor_id'         => $paidUserId,
        'binary_parent_id'   => $paidUserId,
        'binary_position'    => 'left',
        'pending'            => false,
    ]);
    $commAfter = get_commission_count($paidUserId);

    return [
        assert_eq(true, $beforeAssign, 'isPaidMember true before CD assignment (paid user)'),
        assert_eq(true, $afterAssign, 'isPaidMember still true after CD assignment (reg_payment_method unchanged)'),
        assert_gt($commBefore, $commAfter, 'User with admin-assigned CD still earns from paid downline (source-only filter)'),
    ];
});

// ══════════════════════════════════════════════════════════════════════════════
//  TEST 8: CD User Activated from Pending -- Still No Commissions
//  Tree: pending CD under t7_paid.right (free), activate, check no back-pay
// ══════════════════════════════════════════════════════════════════════════════
test('T8: CD pending activation should not create back-pay commissions', function () use ($packageId, &$tree) {
    $parent = $tree['t7_paid'];

    $pendingCdCode = create_test_code($packageId, true);
    $pendingUserId = register_user([
        'username'           => '_cdtest_pending_cd',
        'password'           => 'TestPass123!',
        'package_id'         => $packageId,
        'reg_code_id'        => $pendingCdCode['id'],
        'reg_payment_method' => 'pending',
        'sponsor_id'         => $parent,
        'binary_parent_id'   => $parent,
        'binary_position'    => 'right',
        'pending'            => true,
    ]);
    $tree['t8_pending'] = $pendingUserId;

    // Sponsor of the pending user's sponsor chain: track the upline
    $sponsorCommBefore = get_commission_count($parent);

    User::activate($pendingUserId, $packageId, $pendingCdCode['id'], 'pending');

    $sponsorCommAfter = get_commission_count($parent);
    $isPaid = User::isPaidMember($pendingUserId);

    return [
        assert_eq($sponsorCommBefore, $sponsorCommAfter, 'Upline should NOT earn commissions from CD pending user activation'),
        assert_eq(false, $isPaid, 'Activated CD user is still not a paid member'),
    ];
});

// ══════════════════════════════════════════════════════════════════════════════
//  TEST 9: Leg Count Accuracy
//  Verifies all test placements are reflected in leg counts
// ══════════════════════════════════════════════════════════════════════════════
test('T9: Leg counts accurately reflect all placements including CD', function () use ($sponsorId, &$tree) {
    $info = get_pairing_info($sponsorId);

    $pdo = td();
    $leftPlacements = (int)$pdo->query("
        SELECT COUNT(*) FROM users
        WHERE binary_parent_id = {$sponsorId}
          AND binary_position = 'left'
          AND username LIKE '_cdtest\\_%'
    ")->fetchColumn();

    $rightPlacements = (int)$pdo->query("
        SELECT COUNT(*) FROM users
        WHERE binary_parent_id = {$sponsorId}
          AND binary_position = 'right'
          AND username LIKE '_cdtest\\_%'
    ")->fetchColumn();

    $leftOk = $info['left_count'] >= $leftPlacements;
    $rightOk = $info['right_count'] >= $rightPlacements;
    $pairsLteArms = $info['pairs_paid'] <= min($info['left_count'], $info['right_count']);

    return [
        assert_true($leftOk, "left_count ({$info['left_count']}) >= test left placements ({$leftPlacements})"),
        assert_true($rightOk, "right_count ({$info['right_count']}) >= test right placements ({$rightPlacements})"),
        assert_true($pairsLteArms, "pairs_paid ({$info['pairs_paid']}) <= min(left,right) (" . min($info['left_count'], $info['right_count']) . ")"),
    ];
});

// ══════════════════════════════════════════════════════════════════════════════
//  TEST 10: E-Wallet Registration Never Auto-Assigns CD
//  Tree: ewallet user under t6_cd.right (free, t6_cd only has left child)
// ══════════════════════════════════════════════════════════════════════════════
test('T10: E-wallet registration -- isPaidMember=true, no auto CD', function () use ($packageId, $sponsorId, &$tree) {
    $cdUser = $tree['t6_cd'];

    $ewalletUserId = register_user([
        'username'           => '_cdtest_ewallet',
        'password'           => 'TestPass123!',
        'package_id'         => $packageId,
        'reg_code_id'        => null,
        'reg_payment_method' => 'ewallet',
        'reg_paid_by'        => $sponsorId,
        'paid_by_username'   => '_cdtest_sponsor',
        'sponsor_id'         => $sponsorId,
        'binary_parent_id'   => $cdUser,
        'binary_position'    => 'right',
        'pending'            => false,
    ]);

    $isPaid = User::isPaidMember($ewalletUserId);
    $cdStatus = CdStatus::getActive($ewalletUserId);

    return [
        assert_eq(true, $isPaid, 'E-wallet registrant -> isPaidMember = true'),
        assert_eq(null, $cdStatus, 'E-wallet registrant should NOT have auto-assigned CD'),
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

exit($stats['failed'] > 0 ? 1 : 0);
