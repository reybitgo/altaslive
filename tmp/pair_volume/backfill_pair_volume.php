<?php

/**
 * One-time backfill of the volume-based pairing columns.
 *
 * Computes for every member:
 *   - left_pair_volume / right_pair_volume  = sum of pairing_bonus of all
 *     paid, active, non-CD descendants in that leg (matching the new engine's
 *     contribution rules: CD-sourced / pending bodies contribute nothing).
 *   - pairs_volume_paid = min(left_pair_volume, right_pair_volume)
 *     (all historical matched volume treated as paid — aligned with the
 *     decision to keep already-credited amounts as-is).
 *
 * MUST be run BEFORE shipping the new volume-based engine, otherwise the first
 * placement after deploy would double-settle.
 *
 * For an online DB, run on a maintenance window. Columns default to 0.00 so a
 * partial/failed run is not destructive to the legacy counters.
 *
 * Run:  php tmp/pair_volume/backfill_pair_volume.php
 *       php tmp/pair_volume/backfill_pair_volume.php --dry-run
 *       php tmp/pair_volume/backfill_pair_volume.php --verbose
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

$dryRun  = in_array('--dry-run', $_SERVER['argv'] ?? [], true);
$verbose = in_array('--verbose', $_SERVER['argv'] ?? [], true);

// ── Load datastore ─────────────────────────────────────────────────────────────
$pdo = db();

echo "Loading users / packages / reg codes...\n";

$users = $pdo->query(
    "SELECT id, username, binary_parent_id, binary_position, status,
            reg_payment_method, reg_code_id, package_id
     FROM users"
)->fetchAll();

$pkgs = [];
foreach ($pdo->query("SELECT id, pairing_bonus FROM packages")->fetchAll() as $p) {
    $pkgs[(int)$p['id']] = (float)$p['pairing_bonus'];
}

$cdCodes = [];
foreach ($pdo->query("SELECT id FROM reg_codes WHERE code_type = 'cd'") as $c) {
    $cdCodes[(int)$c['id']] = true;
}

function is_paid_member(array $u, array $cdCodes): bool
{
    if ($u['status'] !== 'active') return false;
    if (($u['reg_payment_method'] ?? '') === 'pending') return false;
    if (($u['reg_payment_method'] ?? '') === 'code' && isset($cdCodes[(int)$u['reg_code_id']])) return false;
    return true;
}

// Contributed volume of a member = their own package pairing_bonus if paid
$vol = [];
foreach ($users as $u) {
    $uid = (int)$u['id'];
    $pid = $u['package_id'] ?? null;
    $vol[$uid] = (is_paid_member($u, $cdCodes) && $pid && isset($pkgs[(int)$pid]))
        ? $pkgs[(int)$pid]
        : 0.00;
}

// Children map: parentId -> list of ['id' => childId, 'side' => 'left'|'right']
$children = [];
foreach ($users as $u) {
    if ($u['binary_parent_id'] !== null && $u['binary_parent_id'] !== '' ) {
        $parent = (int)$u['binary_parent_id'];
        $children[$parent][] = [
            'id'   => (int)$u['id'],
            'side' => $u['binary_position'],
        ];
    }
}

echo "Walking tree to accumulate per-leg volumes...\n";

// Iterative post-order: subtree_vol[node] = vol[node] + sum(subtree_vol[children])
$subtree = [];
foreach ($users as $u) {
    $subtree[(int)$u['id']] = 0.00;
}

// Stack-based post-order over all nodes to avoid deep recursion
$visited = [];
$stack   = [];
foreach ($users as $u) {
    $uid = (int)$u['id'];
    if (isset($visited[$uid])) continue;
    $stack[] = ['id' => $uid, 'expand' => false];
    while ($stack) {
        $node = array_pop($stack);
        if ($node['expand']) {
            $sum = $vol[$node['id']] ?? 0.00;
            foreach ($children[$node['id']] ?? [] as $c) {
                $sum += $subtree[$c['id']] ?? 0.00;
            }
            $subtree[$node['id']] = round($sum, 2);
            $visited[$node['id']] = true;
        } else {
            if (isset($visited[$node['id']])) continue;
            $stack[] = ['id' => $node['id'], 'expand' => true];
            foreach ($children[$node['id']] ?? [] as $c) {
                if (!isset($visited[$c['id']])) {
                    $stack[] = ['id' => $c['id'], 'expand' => false];
                }
            }
        }
    }
}

// ── Build update set ────────────────────────────────────────────────────────────
echo "Building update set...\n";

$rows  = [];
$totalVol = 0.00;
$totalPaid = 0.00;
foreach ($users as $u) {
    $uid = (int)$u['id'];
    // only members with a package in the binary system matter
    if (!$u['package_id']) continue;

    $left  = 0.00;
    $right = 0.00;
    foreach ($children[$uid] ?? [] as $c) {
        $v = $subtree[$c['id']] ?? 0.00;
        if ($c['side'] === 'left') {
            $left += $v;
        } elseif ($c['side'] === 'right') {
            $right += $v;
        }
    }
    $left  = round($left, 2);
    $right = round($right, 2);
    $paid  = round(min($left, $right), 2);

    $totalVol  += $left + $right;
    $totalPaid += $paid;
    $rows[] = [
        'id'      => $uid,
        'left'    => $left,
        'right'   => $right,
        'paid'    => $paid,
        'username' => $u['username'] ?? $u['id'],
    ];
    if ($verbose) {
        printf(
            "  #%d %-24s L=%10.2f R=%10.2f paid=%10.2f\n",
            $uid, $u['username'] ?? $uid, $left, $right, $paid
        );
    }
}

echo "\n";
echo str_repeat('=', 72) . "\n";
printf("Members computed     : %d\n", count($rows));
printf("Total leg volume     : %s\n", number_format($totalVol, 2));
printf("Total matched (paid) : %s\n", number_format($totalPaid, 2));
echo str_repeat('=', 72) . "\n";

if ($dryRun) {
    echo "\nDRY RUN — no updates applied.\n";
    exit(0);
}

// ── Apply ───────────────────────────────────────────────────────────────────────
echo "\nApplying updates...\n";
$upd = $pdo->prepare("
    UPDATE users
    SET left_pair_volume   = ?,
        right_pair_volume  = ?,
        pairs_volume_paid  = ?
    WHERE id = ?
");
$pdo->beginTransaction();
try {
    $count = 0;
    foreach ($rows as $r) {
        $upd->execute([$r['left'], $r['right'], $r['paid'], $r['id']]);
        $count++;
    }
    $pdo->commit();
    echo "Applied to {$count} member(s)... done.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

$invariant = (int)$pdo->query(
    "SELECT COUNT(*) FROM users
     WHERE left_pair_volume IS NOT NULL
       AND pairs_volume_paid > (LEAST(left_pair_volume, right_pair_volume) + 0.001)"
)->fetchColumn();
echo ($invariant === 0)
    ? "Verification passed — pairs_volume_paid <= min(left,right) for all rows.\n"
    : "WARNING: {$invariant} row(s) violate the invariant!\n";

echo "Backfill complete.\n";