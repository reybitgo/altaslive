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

/**
 * The C5b backlog. EMPTY as of 2026-10-10 — every one of the 20 sites was
 * fixed rather than excepted. Keep the array (and the staleness check below)
 * so a future exemption has to be a deliberate, justified act.
 *
 * Each entry would need: the file, and the reason it is acceptable.
 */
const KNOWN_C5B = [];

/**
 * Values interpolated into a template literal that is then assigned to
 * innerHTML must be escaped at the sink. Server-side encoding protects the
 * attribute only; it does not survive JavaScript decoding.
 */
const HTML_SINK_KEYWORDS = ['innerHTML', 'insertAdjacentHTML', 'outerHTML'];

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

// ── RULE-5 ──────────────────────────────────────────────────────────────────
// A `${...}` interpolation inside a template literal that reaches innerHTML is
// an injection sink unless the value is escHtml()'d (or comes from escHtml()).
// The static markup around it is developer-authored and is fine.
//
// This is the sink that actually made C5b exploitable: fixing only the
// onclick attribute would not have helped, because the member-supplied GCash
// address flowed straight into modalDesc.innerHTML.

$unEscapedSinks = [];
$phpFiles = array_merge(
    glob(REPO_ROOT . '/views/*/*.php') ?: [],
    glob(REPO_ROOT . '/assets/js/*.js') ?: []
);
foreach ($phpFiles as $file) {
    $rel = str_replace('\\', '/', ltrim(str_replace(REPO_ROOT . '/', '', $file), '/'));
    $src = (string) file_get_contents($file);

    // Find `${ident}` or `${ident.prop}` interpolations that are NOT wrapped.
    if (!preg_match_all('/\$\{([A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z_$][A-Za-z0-9_$]*)*)\}/', $src, $m, PREG_OFFSET_CAPTURE)) {
        continue;
    }

    // Only care about ones near an HTML sink: within the same template literal
    // that is later assigned to innerHTML, or inside a string handed to
    // showConfirm() (which itself injects).
    $sinkLines = [];
    foreach (preg_split('/\R/', $src) as $n => $line) {
        foreach (HTML_SINK_KEYWORDS as $kw) {
            if (stripos($line, $kw) !== false) {
                $sinkLines[$n + 1] = true;
            }
        }
        if (preg_match('/showConfirm\s*\(/', $line)) {
            $sinkLines[$n + 1] = true;
        }
    }
    if (!$sinkLines) {
        continue;
    }

    foreach ($m[0] as $hit) {
        $expr  = $hit[0];
        $ident = $m[1][array_search($hit, $m[0], true)][0];
        $lineNo = substr_count(substr($src, 0, $hit[1]), "\n") + 1;

        // Is this interpolation plausibly reaching a sink?
        //
        // This used to be a fixed +/-6 line window around a sink. That is a
        // guess about formatting, and it silently failed: showToast()'s message
        // line drifted 8 lines above its insertAdjacentHTML call once the file
        // grew comments, dropped out of the window, and reverting its escaping
        // passed the suite. A lint rule that depends on how far a line sits from
        // a keyword is not a lint rule.
        //
        // JS: the whole file is checked. These files are small and any
        // interpolation into a template literal in a file that also writes to
        // the DOM deserves to be escaped or explicitly exempted.
        // PHP views: the window is kept, because `${...}` in a view is far more
        // often an unrelated PHP string interpolation.
        $near = str_ends_with($rel, '.js');
        if (!$near) {
            for ($l = $lineNo - 6; $l <= $lineNo + 6; $l++) {
                if (isset($sinkLines[$l])) { $near = true; break; }
            }
        }
        if (!$near) {
            continue;
        }
        // Already escaped?
        //
        // This used to search a +/-400 character window around the hit for the
        // string "escHtml(<ident>)", and to skip the hit entirely if the window
        // contained "textContent" anywhere. Both were wrong in the same
        // direction: an unrelated escHtml() or textContent 200 characters away
        // silently cleared a real sink. That is how showConfirm()'s unescaped
        // ${message} and ${title} survived review — the escHtml() calls beside
        // them in the same template literal happened to sit in the window.
        //
        // The check is now local to the interpolation's own line, and the one
        // genuine exemption is declared explicitly below instead of being
        // reached by accident.
        $lineText = explode("\n", $src)[$lineNo - 1] ?? '';
        if (stripos($lineText, 'escHtml(' . $ident) !== false
            || stripos($lineText, 'escHtml(' . ltrim($ident, '$') . ')') !== false
            || preg_match('/\$\{\s*escHtml\(/', $lineText)
        ) {
            continue;
        }

        // Deliberate, documented exemption: showConfirm()'s `message` and
        // `title` are a trusted-HTML contract (see the docblock in app.js).
        //
        // Scoped to showConfirm's function BODY, not to the identifier name. A
        // file+identifier exemption looked equivalent but was not: showToast
        // has a `message` too, so the exemption quietly un-guarded it — and
        // reverting showToast's escaping passed the suite. Verified by
        // mutation, which is the only reason this is worth saying.
        if ($rel === 'assets/js/app.js') {
            $sc = strpos($src, 'function showConfirm(');
            if ($sc !== false) {
                $en = strpos($src, "\nfunction ", $sc + 10);
                $end = $en === false ? strlen($src) : $en;
                $bare = ltrim($ident, '$');
                if ($hit[1] > $sc && $hit[1] < $end && in_array($bare, ['message', 'title'], true)) {
                    continue;
                }
            }
        }

        $unEscapedSinks[] = $rel . ':' . $lineNo . ' ' . $expr;
    }
}
T::ok(
    $unEscapedSinks === [],
    'RULE-5: values reaching an innerHTML sink are escHtml()-escaped',
    implode(' | ', array_slice($unEscapedSinks, 0, 8))
);

echo "\n";
echo "  C5b backlog (tracked, exempt): " . count($known) . " site(s)\n";
foreach ($known as $k) {
    echo "    - {$k}\n";
}

T::group('Accessibility invariants');

/**
 * Phase 8.6. Every visible form control needs an accessible name.
 *
 * A `<label>` with no `for` renders correctly and is read by nobody: the input
 * it visually belongs to has no programmatic name, so a screen reader announces
 * "edit text, blank". Nine checkout fields were in that state, including the
 * delivery address — the one field on the page that actually matters.
 */
test('every checkout field has a label bound to it by id', function (): void {
    $src = (string) file_get_contents(REPO_ROOT . '/views/member/checkout.php');

    // label[for] -> the id must exist on a control.
    preg_match_all('/<label[^>]*\bfor="([A-Za-z0-9_]+)"/', $src, $m);
    $dangling = [];
    foreach ($m[1] as $id) {
        if (!preg_match('/\bid="' . preg_quote($id, '/') . '"/', $src)) {
            $dangling[] = $id;
        }
    }
    T::ok($dangling === [], 'no checkout label points at a missing id', implode(', ', $dangling));
    // 9 delivery/billing fields. The payment-method labels are not counted
    // here: their `for` is an interpolated per-method id, which a static id
    // pattern cannot match. That one is asserted separately below.
    //
    // (Note for the next editor: never write a literal PHP close tag in a
    // line comment in this file. It ends PHP mode even inside a "//" comment,
    // so the rest of the file silently becomes inline HTML and the parser
    // reports an unclosed brace hundreds of lines away. Block comments are
    // unaffected.)
    T::ok(count($m[1]) >= 9, 'checkout labels are actually present', 'count=' . count($m[1]));
    // The payment-method label and its radio both build their id from the same
    // per-method key, so they are matched by prefix rather than by a static id
    // pattern. (A regex with [^>]* cannot do this: the label's own class
    // attribute contains an interpolated PHP close tag, so the character class
    // stops at that ">" long before reaching `for=`.)
    T::ok(str_contains($src, 'for="pm_'), 'the dynamic payment-method label is bound to its radio');
    T::ok(str_contains($src, 'id="pm_'), 'the payment-method radios carry the matching id prefix');

    // The delivery fields specifically, so a future template rewrite cannot
    // quietly drop them again.
    foreach (['shipping_name', 'shipping_address', 'shipping_city', 'billing_name', 'notes_member'] as $f) {
        T::ok(
            (bool) preg_match('/<label[^>]*for="' . $f . '"/', $src),
            "the {$f} field has a bound label"
        );
    }
});

test('aria references resolve to real elements', function (): void {
    // aria-labelledby="mobileSidebarLabel" pointed at an id that existed
    // nowhere, so the offcanvas announced itself as unlabelled. An unresolvable
    // ARIA reference is worse than none: it is a promise the page does not keep.
    $files = [
        REPO_ROOT . '/views/partials/sidebar_member.php',
        REPO_ROOT . '/views/partials/sidebar_admin.php',
        REPO_ROOT . '/views/partials/cart_offcanvas.php',
        REPO_ROOT . '/views/partials/settings_offcanvas.php',
    ];
    $dangling = [];
    foreach ($files as $f) {
        $src = (string) file_get_contents($f);
        preg_match_all('/aria-labelledby="([A-Za-z0-9_]+)"/', $src, $m);
        foreach ($m[1] as $id) {
            if (!preg_match('/\bid="' . preg_quote($id, '/') . '"/', $src)) {
                $dangling[] = basename($f) . '#' . $id;
            }
        }
    }
    T::ok($dangling === [], 'every aria-labelledby resolves within its file', implode(', ', $dangling));
});

test('icon-only controls carry an accessible name', function (): void {
    // The quantity steppers are literally "-" and "+". Without aria-label a
    // screen reader announces "minus, button" with no indication of what it
    // steps or which line it belongs to.
    foreach (['views/partials/cart_offcanvas.php', 'views/member/cart.php'] as $rel) {
        $src = (string) file_get_contents(REPO_ROOT . '/' . $rel);
        preg_match_all('/<button[^>]*class="[^"]*cart-qty-btn[^"]*"[^>]*>/', $src, $m);
        T::ok(count($m[0]) > 0, "{$rel} renders quantity steppers", 'count=' . count($m[0]));

        $unlabelled = [];
        foreach ($m[0] as $tag) {
            if (!str_contains($tag, 'aria-label=')) {
                $unlabelled[] = trim($tag);
            }
        }
        T::ok($unlabelled === [], "every stepper in {$rel} has an aria-label", implode(' ', $unlabelled));
    }
});

test('the selected status tab is marked with aria-current', function (): void {
    // Deliberately NOT role="tablist": these links navigate by URL rather than
    // swapping panels, so ARIA tabs would promise tabpanels that do not exist.
    $src = (string) file_get_contents(REPO_ROOT . '/views/admin/shop_orders.php');
    T::ok(
        str_contains($src, 'aria-current="page"'),
        'the active status tab is exposed to assistive tech'
    );
    // Comments are stripped first. An earlier version of this assertion matched
    // its OWN explanatory comment, which named the attribute it was forbidding —
    // the same self-trip that hit the XSS rule over a `${message}` in a comment.
    $markup = (string) preg_replace('/<!--.*?-->/s', '', $src);
    T::ok(
        !str_contains($markup, 'role="tablist"'),
        'URL-navigating filters are not mislabelled as ARIA tabs'
    );
});

T::group('Loading invariants');

/**
 * index.php autoloads ONLY models/ and controllers/. Everything in core/ is
 * loaded by explicit require_once lines at the top of the file.
 *
 * That split is invisible to the test suite, because tests/bootstrap.php
 * registers an autoloader that sweeps core/ as well — so a new core class can
 * be used by a model, pass every test, and still fatal in production with
 * "Class not found" on the first request that reaches that code path.
 * StockConflictException was exactly that bug.
 */
test('every core/ class is required by the production bootstrap', function (): void {
    $index = (string) file_get_contents(REPO_ROOT . '/index.php');

    T::ok(
        str_contains($index, "require_once 'core/StockConflictException.php';"),
        'index.php explicitly loads core/StockConflictException.php'
    );

    // Sweep the whole core/ directory, so the next file added is covered too.
    $cores = glob(REPO_ROOT . '/core/*.php') ?: [];
    T::ok(count($cores) > 0, 'core/ contains at least one class file');

    $missing = [];
    foreach ($cores as $file) {
        $name = basename($file, '.php');
        if ($name === 'helpers') {
            continue; // function-only file, already required
        }
        if (!str_contains($index, "core/{$name}.php")) {
            $missing[] = $name;
        }
    }
    T::ok(
        $missing === [],
        'every core/*.php file is loaded by index.php',
        $missing ? 'missing: ' . implode(', ', $missing) : 'all present'
    );

    // The autoloader must not be widened to cover core/ as a shortcut: doing so
    // would mask the next missing require instead of surfacing it.
    T::ok(
        !str_contains($index, "['models/', 'controllers/', 'core/']"),
        'index.php autoloader is still scoped to models/ and controllers/ only'
    );
});

exit(T::summary());