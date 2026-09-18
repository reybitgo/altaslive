<?php

/**
 * @file   tmp/binary_backfill/settle.php
 * @brief  One-off: settle all currently matched-but-unpaid binary pairing volume
 *         using Commission::settleAllPendingPairVolumes() — real-time catch-up.
 *         Same rules as live placement (CD split, lifetime cap, daily cap, flush).
 *
 *   php settle.php             # dry-run: projected payouts, no writes
 *   php settle.php --commit    # apply (transactional)
 */

require dirname(__DIR__, 2) . '/config/db.php';
require dirname(__DIR__, 2) . '/core/helpers.php';
require dirname(__DIR__, 2) . '/core/Commission.php';
require dirname(__DIR__, 2) . '/core/CapEngine.php';

spl_autoload_register(function (string $class): void {
    $file = dirname(__DIR__, 2) . '/models/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

$commit = in_array('--commit', $argv ?? [], true);

// Pre-run counters
$pdo = db();
$before = [
    'commissions'    => (int)$pdo->query('SELECT COUNT(*) FROM commissions')->fetchColumn(),
    'ewallet_ledger' => (int)$pdo->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn(),
    'ewallet_sum'    => (float)$pdo->query('SELECT COALESCE(SUM(ewallet_balance),0) FROM users')->fetchColumn(),
    'lifetime_sum'   => (float)$pdo->query('SELECT COALESCE(SUM(lifetime_earned),0) FROM users')->fetchColumn(),
];

$summary = Commission::settleAllPendingPairVolumes(null, $commit);

echo ($commit ? "COMMIT MODE\n" : "DRY-RUN: no writes. Re-run with --commit to apply.\n") . "\n";
echo 'Members checked: ' . $summary['checked'] . "\n";
echo 'Projected payouts: ' . fmt_money($summary['pay']) . "\n";
echo 'Projected flushed: ' . fmt_money($summary['flush']) . "\n";
if (empty($summary['users'])) {
    echo "No matched pairing volume to settle right now.\n";
    echo "(A single-leg spine like altas1..8 has no balanced legs, so LEAST(L,R) = 0 until\n";
    echo " right-leg volume lands under one of them — future placements pay in real time.)\n";
} else {
    echo "\nPer-user detail:\n";
    foreach ($summary['users'] as $id => $r) {
        $name = $pdo->query("SELECT username FROM users WHERE id = {$id}")->fetchColumn();
        echo "  #{$id} {$name}: settle=" . fmt_money($r['settle'])
           . " pay=" . fmt_money($r['pay']) . " flush=" . fmt_money($r['flush']) . "\n";
    }
}

if (!$commit) {
    exit(0);
}

$after = [
    'commissions'    => (int)$pdo->query('SELECT COUNT(*) FROM commissions')->fetchColumn(),
    'ewallet_ledger' => (int)$pdo->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn(),
    'ewallet_sum'    => (float)$pdo->query('SELECT COALESCE(SUM(ewallet_balance),0) FROM users')->fetchColumn(),
    'lifetime_sum'   => (float)$pdo->query('SELECT COALESCE(SUM(lifetime_earned),0) FROM users')->fetchColumn(),
];

echo "\n── Verification ──\n";
foreach (array_keys($before) as $k) {
    echo "{$k}: {$before[$k]} -> {$after[$k]}"
       . ((string)$before[$k] === (string)$after[$k] ? " (unchanged ✓)" : " (changed)") . "\n";
}