<?php

/**
 * @file   tests/TemplateLintTest.php
 * @brief  Static checks on views and JS that a linter cannot see.
 *
 * Currently one rule, added with defect C5 (tmp/shop/remedy.md):
 *
 *   RULE-1  No e() (an HTML escaper) may appear inside an inline event
 *           handler. The browser HTML-decodes an attribute BEFORE the JS
 *           parser runs, so e()'s &#039; becomes ' again and the string
 *           literal breaks out. A payment reference of
 *               ');alert(document.cookie);//
 *           stored via the admin "verify payment" form therefore executed in
 *           the admin's browser.
 *
 *           Use js() for a whole JS argument:
 *               onclick="copyText(<?= js($v) ?>)"     <- no manual quotes
 *
 *           KNOWN EXCEPTIONS (see the allow-list below): several pre-existing
 *           non-shop views embed e() mid-string, where the value is part of a
 *           larger JS expression and js() would double-quote it. Those are
 *           tracked as C5b in tmp/shop/remedy.md and are excluded here so this
 *           suite stays green while the backlog is worked down.
 *
 * Run:  php tests/run.php TemplateLint
 */

require_once __DIR__ . '/bootstrap.php';

T::group('Template lint');

/** Files where the pattern is known and tracked as C5b, with the reason. */
const KNOWN_C5B = [
    'views/admin/codes.php'        => 'admin-generated code, single copyText arg',
    'views/admin/payouts.php'      => "user-supplied gcash/usdt account is the real risk here",
    'views/admin/reactivations.php' => 'user-supplied account number',
    'views/admin/user_view.php'    => 'username is regex-locked, but infix inside showConfirm()',
    'views/member/payout.php'      => 'saved payout method label',
];

$viewFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(REPO_ROOT . '/views'));
foreach ($it as $f) {
    if (!$f->isDir() && $f->getExtension() === 'php') {
        $viewFiles[] = $f->getPathname();
    }
}
sort($viewFiles);

T::ok(count($viewFiles) > 20, 'view files discovered', count($viewFiles) . ' files');

// ── RULE-1 ──────────────────────────────────────────────────────────────────
$violations = [];
$known      = [];

foreach ($viewFiles as $path) {
    // Normalise to forward slashes so the allow-list keys match on Windows too.
    $rel   = str_replace('\\', '/', ltrim(str_replace(REPO_ROOT . '/', '', $path), '/'));
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $i => $line) {
        if (!preg_match('/\bon[a-z]+\s*=\s*"/i', $line)) {
            continue;
        }
        if (!preg_match('/<\?=\s*e\(/', $line)) {
            continue;
        }
        $entry = $rel . ':' . ($i + 1);
        if (isset(KNOWN_C5B[$rel])) {
            $known[] = $entry;
        } else {
            $violations[] = $entry;
        }
    }
}

T::ok(
    $violations === [],
    'RULE-1: no e() inside an inline event handler outside the C5b backlog',
    implode(', ', $violations)
);

// The allow-list must stay honest: a file may only be exempt while it really
// still contains the pattern.
$stale = [];
foreach (KNOWN_C5B as $rel => $_why) {
    $path = REPO_ROOT . '/' . $rel;
    if (!is_readable($path)) {
        $stale[] = "{$rel} (file missing)";
        continue;
    }
    $src = (string) file_get_contents($path);
    if (!preg_match('/\bon[a-z]+\s*=\s*"[^"]*<\?=\s*e\(/i', $src)) {
        $stale[] = "{$rel} (no longer matches — drop it from KNOWN_C5B)";
    }
}
T::ok(
    $stale === [],
    'the C5b allow-list has no stale entries',
    implode(', ', $stale)
);

// ── RULE-2 ──────────────────────────────────────────────────────────────────
// js() emits a complete JS literal including its own quotes, so a template that
// ALSO wraps it in quotes produces a string-of-a-string. This is the mistake a
// blanket search-and-replace makes, so guard it.
$doubleQuoted = [];
foreach ($viewFiles as $path) {
    $rel = ltrim(str_replace(REPO_ROOT . '/', '', $path), '/');
    $src = (string) file_get_contents($path);
    if (preg_match_all('/[\'"]<\?=\s*js\([^)]*\)\s*\?>[\'"]/', $src, $m)) {
        foreach ($m[0] as $hit) {
            $doubleQuoted[] = $rel . ': ' . trim($hit);
        }
    }
}
T::ok(
    $doubleQuoted === [],
    'RULE-2: js() is never wrapped in manual quotes',
    implode(' | ', $doubleQuoted)
);

// ── RULE-3 ──────────────────────────────────────────────────────────────────
// The shop's own safety invariant: only ShopOrder::transition() and resume()
// may write shop_orders.status. Cheap to check, and it is the rule the whole
// state machine rests on.
$writers = [];
foreach (array_merge(glob(REPO_ROOT . '/models/*.php') ?: [], glob(REPO_ROOT . '/controllers/*.php') ?: []) as $file) {
    $rel = ltrim(str_replace(REPO_ROOT . '/', '', $file), '/');
    $src = (string) file_get_contents($file);
    if (preg_match_all('/UPDATE\s+shop_orders[\s\S]{0,80}?SET\s+status/i', $src, $m)) {
        $writers[] = $rel;
    }
}
$unexpected = array_values(array_diff(array_unique($writers), ['models/ShopOrder.php']));
T::ok(
    $unexpected === [],
    'RULE-3: only ShopOrder.php writes shop_orders.status',
    implode(', ', $unexpected)
);

// ── RULE-4 ──────────────────────────────────────────────────────────────────
// Strategy C: no shop code may call the commission engine, and the inert PV
// columns must never be referenced from PHP.
$engineCalls = [];
$pvRefs      = [];
foreach (array_merge(glob(REPO_ROOT . '/models/*.php') ?: [], glob(REPO_ROOT . '/controllers/*.php') ?: []) as $file) {
    $rel = ltrim(str_replace(REPO_ROOT . '/', '', $file), '/');
    if (str_contains($rel, 'controllers/MemberController.php') || str_contains($rel, 'controllers/AdminController.php')) {
        // Controllers legitimately call Commission for the dashboard; the rule
        // is that SHOP code does not. Handled by the shop-model scan below.
        continue;
    }
    if (str_starts_with(basename($rel), 'Shop') || $rel === 'models/Cart.php' || $rel === 'models/Product.php') {
        $src = (string) file_get_contents($file);
        if (preg_match('/Commission::/', $src)) {
            $engineCalls[] = $rel;
        }
        if (preg_match('/\b(product_pv|pv_value|total_pv|unit_pv)\b/', $src)) {
            $pvRefs[] = $rel;
        }
    }
}
T::ok($engineCalls === [], 'RULE-4: no shop model calls the commission engine', implode(', ', $engineCalls));
T::ok($pvRefs === [], 'RULE-4: no shop model references the inert PV columns', implode(', ', $pvRefs));

echo "\n";
echo "  C5b backlog (tracked, exempt): " . count($known) . " site(s)\n";
foreach ($known as $k) {
    echo "    - {$k}\n";
}

exit(T::summary());