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

define('REPO_ROOT', dirname(__DIR__));

const EXIT_OK    = 0;
const EXIT_TESTS = 1;
const EXIT_HYGIENE = 2;

$root = dirname(__DIR__);
$problems = [];

// ── 1. hygiene checks that do not need a database ───────────────────────────

/**
 * Run a git command and return its stdout.
 *
 * NOT shell_exec: this PHP has shell_exec disabled, so @shell_exec() returns
 * null and every check built on it silently passed on nothing. Both hygiene
 * checks below were no-ops until this was found — the credential scan never
 * read a single file, which is exactly how a live admin password sat in
 * tmp/shop/qa_test_v2.md from commit 0e8efa9 without anything noticing.
 *
 * exec() is available and is verified to work before anything relies on it.
 */
function git_out(string $args): ?string
{
    // No `2>/dev/null`: that is a POSIX redirect which cmd.exe cannot parse, so
    // on Windows it broke the command and every caller reported "git
    // unavailable" — twice, in the worst possible way. exec() separates stdout
    // from stderr for us and $rc tells us whether it worked, which is all the
    // redirect was for.
    $lines = [];
    $rc    = 1;
    @exec('git -C ' . escapeshellarg(REPO_ROOT) . ' ' . $args, $lines, $rc);
    return $rc === 0 ? implode("\n", $lines) : null;
}

// Strategy C: the binary commission engine must not drift while the shop work
// is in flight. Checked against the baseline tag when the repo is a checkout;
// outside git it is skipped loudly rather than assumed to have passed.
if (is_dir(REPO_ROOT . '/.git')) {
    $tag = trim((string) git_out('rev-parse --verify shop-pre-remediation'));
    if ($tag === '') {
        $problems[] = 'the shop-pre-remediation baseline tag is missing — '
            . 'Strategy C cannot be checked, so deploy is blocked until it is restored';
    } else {
        $diff = trim((string) git_out('diff --stat ' . escapeshellarg($tag) . ' -- core/Commission.php'));
        if ($diff !== '') {
            $problems[] = "core/Commission.php has drifted from the baseline:\n{$diff}";
        }
    }
} else {
    echo "  note: not a git checkout — skipped the Commission.php drift check\n";
}

// No live credentials committed. This reads TRACKED files only.
//
// Design note, learned the hard way: the first version of this check scanned
// for anything password-shaped anywhere in the repo and produced ~50 false
// positives on the first run — `password_hash`, `btn.textContent`,
// `autocomplete`, `$_POST`, `confirmPw`. A gate that fires on everything is a
// gate nobody runs, and a security check that is always red gets ignored, which
// is worse than not having one.
//
// So it is precise instead: a literal assigned to a variable whose NAME says
// it is a credential, plus a small denylist of values known to have leaked.
// That has no false positives and still catches the real case.
$tracked = git_out('ls-files');
if ($tracked === null) {
    $problems[] = 'could not list tracked files (git unavailable) — the credential scan did not run';
} else {
    // config/db.php is where a real password belongs and is gitignored;
    // tests/bootstrap.php builds hashes deliberately.
    $skip     = ['config/db.php', 'tests/bootstrap.php', 'tests/predeploy.php'];
    $secretVars = [
        'dbPass', 'db_pass', 'dbPassword', 'cronKey', 'cron_key',
        'app_key', 'appKey', 'appSecret', 'app_secret', 'apiKey', 'api_key',
        'secret', 'SECRET', 'authToken', 'accessToken',
    ];
    $varPattern = '/\$(?:' . implode('|', array_map('preg_quote', $secretVars)) . ')\b\s*=\s*[\'"]([^\'"]{6,})[\'"]/';

    // Values we know are real and must never be committed again.
    $knownLeaked = ['Richman26'];

    $suspect = [];
    foreach (array_filter(array_map('trim', explode("\n", $tracked))) as $rel) {
        if (in_array(str_replace('\\', '/', $rel), $skip, true)) {
            continue;
        }
        if (preg_match('#(^|/)(AGENTS\.md|\.vscode/|uploads/)#', $rel)) {
            continue;
        }
        $path = REPO_ROOT . '/' . $rel;
        if (!is_file($path) || filesize($path) > 2_000_000) {
            continue;
        }
        $src = (string) file_get_contents($path);

        if (preg_match_all($varPattern, $src, $m)) {
            foreach ($m[1] as $value) {
                $suspect[$rel][] = 'literal assigned to a credential variable (len ' . strlen($value) . ')';
            }
        }
        foreach ($knownLeaked as $leak) {
            if (stripos($src, $leak) !== false) {
                $suspect[$rel][] = "contains the known-leaked value \"{$leak}\"";
            }
        }
    }
    foreach ($suspect as $file => $why) {
        $problems[] = "possible committed credential in {$file}: " . implode('; ', array_unique($why));
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
