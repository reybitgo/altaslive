<?php

/**
 * @file   tmp/binary_backfill/backfill_binary.php
 * @brief  One-off: retroactively encode binary placement for active paid members
 *         that were registered while binary was disabled (binary_parent_id IS NULL).
 *
 *         Placement mirrors the live registration algorithm:
 *           AuthController::findNextBinarySlot(sponsor) — left-first BFS free slot.
 *
 *         STRUCTURE-ONLY: processBinaryPlacement(..., payOut=false) increments leg
 *         counts + paid counts + pair volume up the chain but settles NO pairing
 *         money. Unmatched volume carries forward and pays out on future placements
 *         via the normal engine (deliberate deferral).
 *
 *   php backfill_binary.php            # dry-run: print target + placement plan
 *   php backfill_binary.php --commit   # backup -> apply (transactional) -> verify
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

/**
 * Live-placement replica: shallowest free slot under $sponsorId, left first.
 * $occupied tracks slots already claimed earlier in THIS run (dry-run / commit).
 */
function findNextBinarySlot(int $sponsorId, array &$occupied): ?array
{
    $pdo  = db();
    $queue = [$sponsorId];
    $visited = [];

    while (!empty($queue)) {
        $cur = array_shift($queue);
        if (isset($visited[$cur])) continue;
        $visited[$cur] = true;

        foreach (['left', 'right'] as $side) {
            $key = $cur . ':' . $side;
            if (isset($occupied[$key])) continue;

            $st = $pdo->prepare(
                'SELECT id FROM users WHERE binary_parent_id = ? AND binary_position = ? LIMIT 1'
            );
            $st->execute([$cur, $side]);
            $child = $st->fetchColumn();
            if (!$child) {
                return ['upline_id' => $cur, 'position' => $side];
            }
            $queue[] = (int)$child;
        }
    }
    return null;
}

$pdo = db();

// ── Targets: active paid members with no binary placement yet ──
$candidates = $pdo->query(
    "SELECT id, username, package_id, sponsor_id
     FROM users
     WHERE role = 'member' AND status = 'active'
       AND binary_parent_id IS NULL
       AND reg_payment_method <> 'pending'
       AND sponsor_id > 0
     ORDER BY id"
)->fetchAll();

if (empty($candidates)) {
    echo "No active paid members are missing binary placement. Nothing to do.\n";
    exit(0);
}

$nameCache = [];
function usernameById(int $id): string
{
    global $nameCache;
    if (!isset($nameCache[$id])) {
        $st = db()->prepare('SELECT username FROM users WHERE id = ?');
        $st->execute([$id]);
        $nameCache[$id] = (string)($st->fetchColumn() ?: '?');
    }
    return $nameCache[$id];
}

// ── Dry-run / plan simulation ──
$occupied = [];
$plan = [];
foreach ($candidates as $c) {
    $id = (int)$c['id'];
    $slot = findNextBinarySlot((int)$c['sponsor_id'], $occupied);
    if (!$slot) {
        $plan[$id] = null;
        echo "[WARN] No free binary slot under sponsor #{$c['sponsor_id']} for #{$id} {$c['username']}. Skipping.\n";
        continue;
    }
    $plan[$id] = $slot;
    $occupied[$slot['upline_id'] . ':' . $slot['position']] = true;
}

echo 'Targets: ' . count($candidates) . "\n";
foreach ($candidates as $c) {
    echo "  #{$c['id']} {$c['username']}  sponsor=#{$c['sponsor_id']}  pkg=#{$c['package_id']}\n";
}
echo "\nPlacement plan:\n";
$placeable = 0;
foreach ($candidates as $c) {
    $id = (int)$c['id'];
    $slot = $plan[$id];
    if (!$slot) {
        echo "  #{$id} {$c['username']}: SKIPPED (no free slot)\n";
        continue;
    }
    $placeable++;
    echo "  #{$id} {$c['username']} -> under #{$slot['upline_id']} "
       . usernameById($slot['upline_id']) . " ({$slot['position']})\n";
}
echo "\n{$placeable} of " . count($candidates) . " can be placed.\n";

if (!$commit) {
    echo "\nDRY-RUN: no writes. Re-run with --commit to apply.\n";
    exit(0);
}

// ── Commit mode ──
$stamp = date('Ymd_His');
echo "\nBacking up: ";
$pdo->exec("CREATE TABLE users_backup_{$stamp}    AS SELECT * FROM users");
$pdo->exec("CREATE TABLE packages_backup_{$stamp} AS SELECT * FROM packages");
echo "users_backup_{$stamp}, packages_backup_{$stamp}\n";

// Pre-commit sanity counters (verification)
$countsBefore = [
    'commissions'    => (int)$pdo->query('SELECT COUNT(*) FROM commissions')->fetchColumn(),
    'ewallet_ledger' => (int)$pdo->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn(),
    'ewallet_sum'    => (float)$pdo->query('SELECT COALESCE(SUM(ewallet_balance),0) FROM users')->fetchColumn(),
];

// Decision: migrate altas2 (143) Pro -> Starter (same ₱10k entry, binary ON)
$mig = $pdo->prepare('UPDATE users SET package_id = ? WHERE id = ? AND package_id = ?');
$mig->execute([1, 143, 3]);
$migrated = $mig->rowCount();
echo $migrated
    ? "Migrated altas2 (#143): Pro -> Starter.\n"
    : "altas2 migration: no change (not on Pro or already Starter).\n";

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare('UPDATE users SET binary_parent_id = ?, binary_position = ? WHERE id = ?');
    $placed = 0;
    foreach ($candidates as $c) {
        $id   = (int)$c['id'];
        $slot = $plan[$id];
        if (!$slot) continue;

        $upd->execute([$slot['upline_id'], $slot['position'], $id]);
        Commission::processBinaryPlacement($id, $slot['upline_id'], $slot['position'], true, false);
        $placed++;
        echo "  placed #{$id} {$c['username']} under #{$slot['upline_id']} ({$slot['position']})\n";
    }
    if ($placed === 0) {
        echo "Nothing to place; rolling back transaction (backups remain).\n";
        $pdo->rollBack();
        exit(0);
    }
    $pdo->commit();
    echo "Committed {$placed} placement(s).\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Rolled back: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Verification ──
echo "\n── Verification ──\n";
foreach ($pdo->query(
    "SELECT u.id, u.username, u.binary_parent_id, u.binary_position,
            u.left_count, u.right_count, u.left_count_paid, u.right_count_paid,
            u.left_pair_volume, u.right_pair_volume,
            u.pairs_paid, u.pairs_volume_paid, u.pairs_volume_today
     FROM users u
     WHERE u.id IN (142,143,144,145,146,147,148,149)
     ORDER BY u.id"
) as $r) {
    echo implode(' | ', [
        "#{$r['id']} {$r['username']}",
        'parent=' . var_export($r['binary_parent_id'], true),
        'pos=' . var_export($r['binary_position'], true),
        "L={$r['left_count']}/paid{$r['left_count_paid']}/vol{$r['left_pair_volume']}",
        "R={$r['right_count']}/paid{$r['right_count_paid']}/vol{$r['right_pair_volume']}",
        "pairs_paid={$r['pairs_paid']} vol_paid={$r['pairs_volume_paid']} today={$r['pairs_volume_today']}",
    ]) . "\n";
}

$countsAfter = [
    'commissions'    => (int)$pdo->query('SELECT COUNT(*) FROM commissions')->fetchColumn(),
    'ewallet_ledger' => (int)$pdo->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn(),
    'ewallet_sum'    => (float)$pdo->query('SELECT COALESCE(SUM(ewallet_balance),0) FROM users')->fetchColumn(),
];
echo "\ncommissions:   {$countsBefore['commissions']} -> {$countsAfter['commissions']} "
   . ($countsBefore['commissions'] === $countsAfter['commissions'] ? "(unchanged ✓)" : "(CHANGED!)") . "\n";
echo "ewallet_ledger: {$countsBefore['ewallet_ledger']} -> {$countsAfter['ewallet_ledger']} "
   . ($countsBefore['ewallet_ledger'] === $countsAfter['ewallet_ledger'] ? "(unchanged ✓)" : "(CHANGED!)") . "\n";
echo "ewallet sum:    " . number_format($countsBefore['ewallet_sum'], 2) . " -> " . number_format($countsAfter['ewallet_sum'], 2) . "\n";
echo "\nRollback if needed: restore from users_backup_{$stamp} / packages_backup_{$stamp}.\n";