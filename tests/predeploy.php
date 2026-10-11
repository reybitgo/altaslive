<?php

/**
 * Pre-deploy gate — Phase 10 action #5.
 *
 * The suite has to be impossible to skip, or "Phases 2-8 cannot silently
 * regress" is a sentence in a document rather than a property of the repo.
 * Run this and read the exit code; anything non-zero means do not deploy.
 *
 *   php tests/predeploy.php            run the full suite
 *   php tests/predeploy.php --quick    skip the rebuild, reuse an existing
 *                                      test schema (faster, less thorough —
 *                                      it cannot catch drift in install.sql)
 *
 * It also re-checks the two invariants that are cheap to satisfy and easy to
 * break by accident, because both have been true by hand-maintenance so far:
 * the commission engine is untouched, and no credentials have been committed.
 *
 * @file   tests/predeploy.php
 */

declare(strict_types=1);

const EXIT_OK    = 0;
const EXIT_TESTS = 1;
const EXIT_HYGIENE = 2;

$root = dirname(__DIR__);
$problems = [];

// ── 1. hygiene checks that do not need a database ───────────────────────────

// Strategy C: the binary commission engine must not drift while the shop work
// is in flight. `git diff` is checked against the baseline tag when the repo is
// a checkout; outside git it is skipped rather than guessed at.
$commission = $root . '/core/Commission.php';
if (is_file($commission) && is_dir($root . '/.git')) {
    $tag = trim((string) @shell_exec(
        'git -C ' . escapeshellarg($root) . ' rev-parse --verify shop-pre-remediation 2>/dev/null'
    ));
    if ($tag !== '') {
        $diff = (string) @shell_exec(
            'git -C ' . escapeshellarg($root) . ' diff --stat ' . escapeshellarg($tag) . ' -- core/Commission.php 2>/dev/null'
        );
        if (trim($diff) !== '') {
            $problems[] = "core/Commission.php has drifted from the baseline:\n" . $diff;
        }
    }
} else {
    echo "  note: not a git checkout, or no baseline tag — skipped the Commission.php check\n";
}

// No live credentials committed. This greps tracked files only; the local
// config/db.php is the one place a real password is expected to live, and it
// is excluded because the check is about what would be PUBLISHED.
if (is_dir($root . '/.git')) {
    $tracked = (string) @shell_exec(
        'git -C ' . escapeshellarg($root) . ' ls-files 2>/dev/null'
    );
    $suspect = [];
    foreach (array_filter(array_map('trim', explode("\n", $tracked))) as $rel) {
        if (preg_match('#(^|/)(config/db\.php|AGENTS\.md|\.vscode/|uploads/)#', $rel)) {
            continue;
        }
        $path = $root . '/' . $rel;
        if (!is_file($path) || filesize($path) > 2_000_000) {
            continue;
        }
        $src = (string) file_get_contents($path);
        if (preg_match('/\$(?:dbPass|cronKey)\s*=\s*[\'"](?!\s*[\'"])[^\'"]{6,}/', $src)) {
            $suspect[] = $rel;
        }
    }
    if ($suspect) {
        $problems[] = "possible committed credentials in: " . implode(', ', $suspect);
    }
}

// ── 2. the suite ────────────────────────────────────────────────────────────

echo "\n";
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/run.php');
if (in_array('--quick', $argv, true)) {
    echo "  pre-deploy (quick: reusing the existing test schema)\n\n";
} else {
    echo "  pre-deploy (full)\n\n";
}

passthru($cmd, $code);
if ($code !== 0) {
    $problems[] = "the test suite exited {$code}";
}

if ($problems) {
    echo "\n  PRE-DEPLOY BLOCKED\n";
    foreach ($problems as $p) {
        echo "    - {$p}\n";
    }
    exit($problems === [] ? EXIT_OK : EXIT_HYGIENE);
}

echo "\n  PRE-DEPLOY OK\n";
exit(EXIT_OK);
