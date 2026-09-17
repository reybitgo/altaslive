<?php

/**
 * @file   tmp/binary_backfill/import_packages.php
 * @brief  One-off: import package settings from tmp/pckgs/pckgs_binary.json.
 *         Matches packages by NAME only (no add, no rename, no delete).
 *         Dry-run by default. Use `--commit` to apply (transactional).
 *
 *   php import_packages.php            # print old -> new diff, no writes
 *   php import_packages.php --commit   # apply (transactional)
 */

require dirname(__DIR__, 2) . '/config/db.php';

$commit = in_array('--commit', $argv ?? [], true);
$jsonPath = dirname(__DIR__) . '/pckgs/pckgs_binary.json';

if (!is_file($jsonPath)) {
    fwrite(STDERR, "Missing {$jsonPath}\n");
    exit(1);
}
$items = json_decode((string)file_get_contents($jsonPath), true);
if (!is_array($items)) {
    fwrite(STDERR, "Invalid JSON in {$jsonPath}\n");
    exit(1);
}

// JSON key -> packages column map (types preserved from JSON)
const FIELD_MAP = [
    'entry_fee'               => 'entry',
    'pairing_bonus'           => 'pairBonus',
    'daily_pair_cap'          => 'pairCap',
    'direct_ref_bonus'        => 'directRef',
    'lifetime_cap_multiplier' => 'capMult',
    'reactivation_fee'        => 'reactFee',
    'reactivation_window_days'=> 'reactWin',
    'daily_fixed_income'      => 'dfi',
    'daily_fixed_income_days' => 'dfiDays',
];
const TOGGLE_MAP = [
    'pairing_enabled'            => ['json' => 'binary',   'default' => 1],
    'indirect_referral_enabled'  => ['json' => 'indirect', 'default' => 0],
    'dfi_enabled'                => ['json' => 'dfiOn',    'default' => 1],
];

const INT_COLS = ['daily_pair_cap', 'daily_fixed_income_days', 'reactivation_window_days'];

$pdo = db();

// name -> id
$nameToId = [];
foreach ($pdo->query('SELECT id, name FROM packages') as $r) {
    $nameToId[$r['name']] = (int)$r['id'];
}

$numCols  = array_keys(FIELD_MAP);
$togCols  = array_keys(TOGGLE_MAP);
$allCols  = array_merge($numCols, $togCols);

function normValue(string $col, $item): float|int
{
    $jsonKey = FIELD_MAP[$col] ?? null;
    if ($jsonKey !== null) {
        return in_array($col, INT_COLS, true)
            ? (int)($item[$jsonKey] ?? 0)
            : (float)($item[$jsonKey] ?? 0);
    }
    $cfg = TOGGLE_MAP[$col] ?? null;
    return $cfg ? (!empty($item[$cfg['json']]) ? 1 : 0) : 0;
}

$st = $pdo->prepare('SELECT * FROM packages WHERE id = ?');
$upd = $pdo->prepare(
    'UPDATE packages SET '
    . implode(', ', array_map(fn($c) => "{$c} = ?", $allCols))
    . ' WHERE id = ?'
);
$delLvl = $pdo->prepare('DELETE FROM package_indirect_levels WHERE package_id = ?');
$insLvl = $pdo->prepare(
    'INSERT INTO package_indirect_levels (package_id, level, bonus) VALUES (?, ?, ?)'
);

$matched = 0;
$skipped = 0;
$output = [];

foreach ($items as $item) {
    $name = trim((string)($item['name'] ?? ''));
    if (!isset($nameToId[$name])) {
        $skipped++;
        $output[] = "[SKIP] '{$name}' not found in DB (no add/rename).";
        continue;
    }
    $id   = $nameToId[$name];
    $rows = $st->execute([$id]) ? $st->fetchAll() : [];
    $old  = $rows[0] ?? null;

    $newVals = [];
    $diffs = [];
    foreach ($allCols as $col) {
        $newVals[] = $nv = normValue($col, $item);
        if ($old !== null) {
            $ov = $old[$col] ?? null;
            $differ = in_array($col, INT_COLS, true)
                ? (int)$ov !== (int)$nv
                : (float)$ov !== (float)$nv;
            if ($differ) {
                $diffs[] = "      {$col}: {$ov} -> {$nv}";
            }
        }
    }

    // Indirect levels: JSON levels[1..10]
    $levels = array_values((array)($item['levels'] ?? []));
    $levelDiffs = [];
    if ($old !== null) {
        $oldLvls = $pdo->query(
            "SELECT level, bonus FROM package_indirect_levels WHERE package_id = {$id} ORDER BY level"
        );
        foreach ($oldLvls as $lv) {
            $lvId = (int)$lv['level'];
            $newLv = isset($levels[$lvId - 1]) ? (float)$levels[$lvId - 1] : 0.0;
            if ((float)$lv['bonus'] !== $newLv) {
                $levelDiffs[] = "      level_{$lvId}: {$lv['bonus']} -> {$newLv}";
            }
        }
    }

    $matched++;
    $output[] = "{$name} (id={$id}):";
    if ($diffs)   { $output = array_merge($output, $diffs); }
    if (!$diffs)  { $output[] = "      (no numeric/toggle changes)"; }
    if ($levelDiffs) { $output = array_merge($output, $levelDiffs); }
    $output[] = '';

    if ($commit) {
        // Stash levels for the apply pass below
        $index[$id] = ['name' => $name, 'levels' => $levels];
        $updStmts[$id] = [$newVals, $id];
    }
}

echo "Matched {$matched} package(s). Skipped {$skipped} (not found).\n";
echo ($commit ? "COMMIT MODE - changes will be written.\n" : "DRY-RUN - no writes.\n") . "\n";
foreach ($output as $line) {
    echo $line . "\n";
}

if (!$commit || $matched < 1) {
    exit($commit ? 1 : 0);
}

// ── Apply (transactional) ──
$pdo->beginTransaction();
try {
    foreach ($updStmts ?? [] as [$vals, $id]) {
        $upd->execute([...$vals, $id]);

        $delLvl->execute([$id]);
        foreach ($index[$id]['levels'] ?? [] as $i => $bonus) {
            $insLvl->execute([$id, $i + 1, (float)$bonus]);
        }
    }
    $pdo->commit();
    echo "Committed package settings + indirect levels.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Rolled back: " . $e->getMessage() . "\n");
    exit(1);
}