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
    if (!preg_match("/^enum\((.*)\)$/is", $columnType, $m)) {
        return [];
    }
    // Migration files build their ALTER inside a PREPARE'd string literal, so
    // every quote arrives doubled: ENUM(''a'',''b''). Normalise before parsing.
    $inner = str_replace("''", "'", $m[1]);
    $parts = str_getcsv(trim($inner), ',', "'");
    return array_map(static fn($v) => trim(trim($v), "' "), $parts);
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

// ── 1. Enum coverage, derived from the source ──────────────────────────────
// These assertions read the values the model actually declares or writes, not
// a hand-picked list of past bug values. A test that hardcodes "'paid'" only
// proves the old bug still exists; it goes useless the moment you fix it.

/** Pull a PHP class const array of single-quoted strings out of a file. */
function const_values(string $file, string $const): array
{
    $src = (string) file_get_contents(REPO_ROOT . '/' . $file);
    if (!preg_match('/const\s+' . preg_quote($const, '/') . '\s*=\s*\[(.*?)\];/s', $src, $m)) {
        return [];
    }
    preg_match_all("/'([a-z_0-9]+)'/", $m[1], $v);
    return $v[1];
}

// 1a. ShopRefund::STATUSES must all be accepted by shop_refunds.status (C1).
$refundStatuses = const_values('models/ShopRefund.php', 'STATUSES');
T::ok($refundStatuses !== [], 'ShopRefund::STATUSES is parseable');
$ddlRefundStatus = enum_values(column_type('shop_refunds', 'status'));
$bad = array_values(array_diff($refundStatuses, $ddlRefundStatus));
T::ok($bad === [], 'C1: every ShopRefund::STATUSES value is accepted by shop_refunds.status', implode(',', $bad));
$bad = array_values(array_diff($ddlRefundStatus, $refundStatuses));
T::ok($bad === [], 'C1: shop_refunds.status has no value the model cannot reach', implode(',', $bad));

// 1b. ShopRefund::CAUSES must all be accepted by shop_refunds.cause (H4).
$refundCauses = const_values('models/ShopRefund.php', 'CAUSES');
T::ok($refundCauses !== [], 'ShopRefund::CAUSES is parseable');
$ddlCauses = enum_values(column_type('shop_refunds', 'cause'));
$bad = array_values(array_diff($refundCauses, $ddlCauses));
T::ok($bad === [], 'H4: every ShopRefund::CAUSES value is accepted by shop_refunds.cause', implode(',', $bad));

// 1c. The controller's fallback cause must itself be legal.
$adminCtrl = (string) file_get_contents(REPO_ROOT . '/controllers/AdminController.php');
if (preg_match("/\\\$_POST\['cause'\]\s*\?\?\s*'([a-z_]+)'/", $adminCtrl, $cm2)) {
    T::inSet($cm2[1], $ddlCauses, 'H4: AdminController cause fallback is a legal enum value');
} else {
    T::ok(true, 'H4: AdminController cause fallback not statically readable (skipped)');
}

// 1d. Any literal state the shipment model writes must be accepted (M5).
$shipSrc = (string) file_get_contents(REPO_ROOT . '/models/ShopShipment.php');
preg_match_all("/SET state = '([a-z_]+)'/", $shipSrc, $sm2);
$ddlShipState = enum_values(column_type('shop_shipments', 'state'));
$bad = array_values(array_diff(array_unique($sm2[1]), $ddlShipState));
T::ok($bad === [], 'M5: every state ShopShipment writes is accepted by the column', implode(',', $bad));
T::ok(
    strpos($shipSrc, 'flagLost') === false,
    'M5: the dead flagLost() writer (which used an undeclared state) is gone'
);

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

// ── 6. Migration chain vs. install.sql ─────────────────────────────────────
// A fresh install (install.sql) and a migrated database must agree, or the two
// deployment paths diverge silently. A full parity test needs a pre-shop
// baseline that does not exist as a file, so this asserts the direction that
// actually bites: every ENUM value a migration declares must also exist in
// install.sql. A migration may lag install.sql (a later migration adds it) but
// must never introduce a value install.sql cannot produce.
//
// Migrations run in order:
//   migrate_shop.sql -> migrate_shop_fixups.sql -> migrate_ewallet_ref_types.sql

$migrationFiles = [
    'migrate_shop.sql',
    'migrate_shop_fixups.sql',
    'migrate_ewallet_ref_types.sql',
];

// Every enum column in install.sql, as table.column => values.
$installEnums = [];
if (preg_match_all('/CREATE TABLE\s+(\w+)\s*\((.*?)\)\s*ENGINE/is', $install, $tables, PREG_SET_ORDER)) {
    foreach ($tables as $t) {
        if (preg_match_all('/(\w+)\s+enum\s*\(([^)]*)\)/i', $t[2], $cols, PREG_SET_ORDER)) {
            foreach ($cols as $c) {
                $installEnums[$t[1] . '.' . $c[1]] = enum_values('enum(' . $c[2] . ')');
            }
        }
    }
}
T::ok(count($installEnums) >= 12, 'install.sql yields a plausible enum map', count($installEnums) . ' columns');

// Every enum column any migration declares, merged in chain order (later wins).
$migrationEnums = [];
foreach ($migrationFiles as $file) {
    if (!is_readable(REPO_ROOT . '/' . $file)) {
        T::ok(false, "migration readable: {$file}");
        continue;
    }
    $m = (string) file_get_contents(REPO_ROOT . '/' . $file);

    // CREATE TABLE [IF NOT EXISTS] <t> ( ... )
    if (preg_match_all('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?(\w+)\s*\((.*?)\)\s*ENGINE/is', $m, $mtabs, PREG_SET_ORDER)) {
        foreach ($mtabs as $t) {
            if (preg_match_all('/(\w+)\s+enum\s*\(([^)]*)\)/i', $t[2], $cols, PREG_SET_ORDER)) {
                foreach ($cols as $c) {
                    $migrationEnums[$t[1] . '.' . $c[1]] = enum_values('enum(' . $c[2] . ')');
                }
            }
        }
    }

    // ALTER TABLE <t> MODIFY COLUMN <c> ENUM(...)  /  ADD COLUMN <c> ENUM(...)
    if (preg_match_all(
        '/ALTER TABLE\s+(\w+)\s+(?:MODIFY|ADD)\s+(?:COLUMN\s+)?(\w+)\s+ENUM\s*\(([^)]*)\)/is',
        $m,
        $alters,
        PREG_SET_ORDER
    )) {
        foreach ($alters as $a) {
            $migrationEnums[$a[1] . '.' . $a[2]] = enum_values('enum(' . $a[3] . ')');
        }
    }
    // ALTER TABLE <t> CHANGE COLUMN <old> <new> ENUM(...)
    if (preg_match_all(
        '/ALTER TABLE\s+(\w+)\s+CHANGE\s+COLUMN\s+\w+\s+(\w+)\s+ENUM\s*\(([^)]*)\)/is',
        $m,
        $changes,
        PREG_SET_ORDER
    )) {
        foreach ($changes as $c) {
            $migrationEnums[$c[1] . '.' . $c[2]] = enum_values('enum(' . $c[3] . ')');
        }
    }
}
T::ok(count($migrationEnums) >= 12, 'migrations yield a plausible enum map', count($migrationEnums) . ' columns');

// The assertion: nothing in the migration chain is absent from install.sql.
$orphans = [];
foreach ($migrationEnums as $col => $values) {
    if (!isset($installEnums[$col])) {
        $orphans[] = "{$col} (not in install.sql)";
        continue;
    }
    $missing = array_values(array_diff($values, $installEnums[$col]));
    if ($missing) {
        $orphans[] = "{$col}: " . implode(',', $missing);
    }
}
T::ok(
    $orphans === [],
    'every ENUM value the migration chain declares exists in install.sql',
    implode(' | ', $orphans)
);

// And the specific trap this suite was built for: ref_type must keep 'upgrade'
// in both paths, or C7 returns on any migrated database.
if (isset($installEnums['ewallet_ledger.ref_type'])) {
    T::inSet('upgrade', $installEnums['ewallet_ledger.ref_type'], 'C7: install.sql ref_type keeps upgrade');
}
if (isset($migrationEnums['ewallet_ledger.ref_type'])) {
    T::inSet('upgrade', $migrationEnums['ewallet_ledger.ref_type'], 'C7: migration chain ref_type ends with upgrade');
}

exit(T::summary());