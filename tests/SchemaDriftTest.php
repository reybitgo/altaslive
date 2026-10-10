<?php

/**
 * @file   tests/SchemaDriftTest.php
 * @brief  Asserts the PHP literals agree with the DDL actually in the database.
 *
 * WHY THIS EXISTS
 *   C1, C2, H4 and M5 all shipped because a PHP string and a MySQL ENUM were
 *   changed independently and nothing compared them. Four of those bugs are
 *   invisible to a PHP linter and only surface at runtime as
 *   "Data truncated for column ...".
 *
 *   This test reads the live column definitions out of information_schema and
 *   checks the PHP source against them. It runs against the *_test schema, so
 *   it is safe to run repeatedly.
 *
 * EXPECTED RESULT ON UNFIXED CODE: 4 failures (C1, C2, H4, M5). Phase 2 turns
 * them green. See tmp/shop/remedy.md.
 *
 * Run:  php tests/SchemaDriftTest.php
 */

require_once __DIR__ . '/bootstrap.php';

T::group('Schema drift — PHP literals vs. live DDL');

/** Parse a MySQL enum() COLUMN_TYPE into an array of values. */
function enum_values(string $columnType): array
{
    if (!preg_match("/^enum\((.*)\)$/i", $columnType, $m)) {
        return [];
    }
    $parts = str_getcsv(trim($m[1]), ',', "'");
    return array_map(static fn($v) => trim(trim($v), "'"), $parts);
}

/** Read a column's declared type from the schema under test. */
function column_type(string $table, string $column): string
{
    return (string) Fixtures::scalar(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$table, $column]
    );
}

$src = static function(string $file): string {
    return (string) file_get_contents(REPO_ROOT . '/' . $file);
};

// ── 1. Enum coverage: every column the shop models write to must accept it ───

/**
 * Each case: [table, column, value the PHP writes, defect id]
 */
$enumCases = [
    ['shop_refunds',     'status',      'paid',             'C1'],
    ['ewallet_ledger',   'ref_type',    'shop_order',       'C2'],
    ['shop_refunds',     'cause',       'customer_request', 'H4'],
    ['shop_shipments',   'state',       'lost',             'M5'],
];

foreach ($enumCases as [$table, $column, $value, $id]) {
    $type = column_type($table, $column);
    if ($type === '') {
        T::ok(false, "{$id}: {$table}.{$column} exists", 'column not found in schema');
        continue;
    }
    $values = enum_values($type);
    T::inSet($value, $values, "{$id}: {$table}.{$column} accepts '{$value}'");
}

// ── 2. Every value the shop models WRITE must exist in the enum ──────────────
// Derived from the source rather than hand-listed, so a future status added to
// the matrix without a matching migration is caught automatically.

$shopOrderSrc = $src('models/ShopOrder.php');
if (preg_match("/public const STATUSES = \[(.*?)\];/s", $shopOrderSrc, $m)) {
    preg_match_all("/'([a-z_]+)'/", $m[1], $sm);
    $statuses = $sm[1];
    $ddl = enum_values(column_type('shop_orders', 'status'));
    T::eq(count($statuses), count($ddl), 'STATUSES count matches shop_orders.status ENUM size');
    $missing = array_values(array_diff($statuses, $ddl));
    T::ok($missing === [], 'every ShopOrder::STATUSES value exists in the DDL', implode(',', $missing));
} else {
    T::ok(false, 'ShopOrder::STATUSES is parseable');
}

if (preg_match("/'payment_method' => \[(.*?)\];/s", $shopOrderSrc, $m)) {
    preg_match_all("/'([a-z_0-9]+)'/", $m[1], $pm);
    $ddl = enum_values(column_type('shop_orders', 'payment_method'));
    $missing = array_values(array_diff($pm[1], $ddl));
    T::ok($missing === [], 'every PAYMENT_METHODS value exists in the DDL', implode(',', $missing));
}

// ── 3. The PHP allow-list vs. the column it inserts into ────────────────────
// C2 and C7 were the same drift in opposite directions: the column accepted a
// value the list rejected (shop_order), and the list accepted a value the
// column rejected (upgrade). One const now backs both, and this asserts it
// against the live ENUM in both directions.

$ewalletSrc = $src('models/Ewallet.php');
preg_match('/VALID_REF_TYPES\s*=\s*\[(.*?)\];/s', $ewalletSrc, $cm);
if (!T::ok(isset($cm[1]) && $cm[1] !== '', 'Ewallet::VALID_REF_TYPES is parseable')) {
    $const = [];
} else {
    preg_match_all("/'([a-z_0-9]+)'/", $cm[1], $cv);
    $const = $cv[1];
}

$ledgerDdl = enum_values(column_type('ewallet_ledger', 'ref_type'));

T::ok(
    array_values(array_diff($const, $ledgerDdl)) === [],
    'VALID_REF_TYPES contains only values the column accepts (C7 direction)',
    implode(',', array_values(array_diff($const, $ledgerDdl)))
);
T::ok(
    array_values(array_diff($ledgerDdl, $const)) === [],
    'VALID_REF_TYPES covers every value the column accepts (C2 direction)',
    implode(',', array_values(array_diff($ledgerDdl, $const)))
);

// Both credit() and debit() must route through the shared const, not keep a
// private list of their own.
T::eq(
    2,
    substr_count($ewalletSrc, 'self::assertRefType($refType);'),
    'credit() and debit() both validate via the shared helper'
);
T::ok(
    !preg_match('/\$valid\s*=\s*\[/', $ewalletSrc),
    'no hand-maintained $valid list remains in Ewallet'
);

// ── 3b. Every ref_type the application actually passes, vs. the column ───────
// Catches a call site that uses a value neither the const nor the column knows
// about, which the allow-list alone would not surface.

$callSites = [];
foreach (array_merge(glob(REPO_ROOT . '/models/*.php') ?: [], glob(REPO_ROOT . '/controllers/*.php') ?: []) as $file) {
    if (basename($file) === 'Ewallet.php') {
        continue;
    }
    $body = (string) file_get_contents($file);
    if (preg_match_all("/Ewallet::(?:credit|debitInternal|debit)\s*\((.*?)\);/s", $body, $hits)) {
        foreach ($hits[1] as $args) {
            preg_match_all("/'([a-z_0-9]+)'/", $args, $lits);
            foreach ($lits[1] as $lit) {
                // Only literal-looking ref_types; skip notes and unrelated words.
                if (in_array($lit, $const, true) || !in_array($lit, $ledgerDdl, true)) {
                    if (preg_match('/^(commission|payout|reactivation|transfer|topup|registration|upgrade|shop_order|upgrade_fee|cd)$/', $lit)) {
                        $callSites[$lit] = true;
                    }
                }
            }
        }
    }
}
$rejectedCallSites = array_values(array_diff(array_keys($callSites), $ledgerDdl));
T::ok(
    $rejectedCallSites === [],
    'every ref_type used at an Ewallet call site is accepted by the column',
    implode(',', $rejectedCallSites)
);

// ── 4. FK delete rules the code depends on ─────────────────────────────────

$restrict = Fixtures::scalar(
    "SELECT rc.DELETE_RULE
       FROM information_schema.REFERENTIAL_CONSTRAINTS rc
       JOIN information_schema.KEY_COLUMN_USAGE k
         ON k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
      WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = 'shop_refunds' AND k.COLUMN_NAME = 'order_id'"
);
T::ok(
    strtoupper((string) $restrict) !== 'RESTRICT',
    'M16: shop_orders is deletable when a refund row exists',
    "shop_refunds.order_id DELETE_RULE = " . ($restrict ?: 'unknown')
);

// ── 5. install.sql must not hardcode a schema name ─────────────────────────

$install = $src('install.sql');
preg_match_all('/^[ \t]*(CREATE DATABASE|USE)[ \t]+\S+.*$/mi', $install, $hm, PREG_OFFSET_CAPTURE);
$offending = [];
foreach ($hm[0] as $hit) {
    $offending[] = 'line ' . (substr_count(substr($install, 0, $hit[1]), "\n") + 1);
}
T::ok(
    $hm[0] === [],
    'install.sql contains no hardcoded CREATE DATABASE / USE',
    implode(', ', $offending)
);

// ── 6. Naming drift between the two install paths ───────────────────────────
// migrate_shop.sql created shipping_postcode; the plan named it shipping_postal.
// Both columns exist; the model must write the one that is actually used.

$orderSrc = $src('models/ShopOrder.php');
T::ok(
    strpos($orderSrc, 'shipping_postal') !== false,
    'ShopOrder writes shipping_postal (the plan DDL name)'
);
T::ok(
    strpos($orderSrc, 'shipping_postcode') === false,
    'ShopOrder does not still write shipping_postcode'
);

exit(T::summary());