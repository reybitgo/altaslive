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

/**
 * Findings a human has looked at and decided not to fix yet.
 *
 * THIS IS NOT A SUPPRESSION LIST. An entry does not make a finding disappear —
 * it keeps the gate green while printing the finding in a KNOWN ISSUES block on
 * every single run, so the next person to deploy sees it.
 *
 * That distinction is the whole point. The previous bug in this file was a
 * check that silently passed on nothing; a "known issues" list that quietly
 * skipped its output would be the same bug wearing a nicer hat. Anything here
 * that stops matching a real finding is reported as STALE, because it means the
 * issue got fixed and the entry is now just clutter.
 *
 * Each entry must carry a reason and a date; TemplateLintTest enforces that,
 * so an entry cannot be added bare.
 */
const ACCEPTED_ISSUES = [
    'midnight_reset.php' => [
        'title'     => 'hardcoded $cronKey default',
        'why'       => "A committed weak default ('secret12347678') guarding the HTTP midnight-reset endpoint. "
                     . 'The adjacent comment already says CHANGE THIS. Accepted 2026-10-11 rather than fixed, '
                     . 'because rotating it changes deployment behaviour and .htaccess already restricts this '
                     . 'file to localhost. The correct fix is to read the key from config/db.php or the '
                     . 'environment, as the DB credentials already do.',
        'accepted'  => '2026-10-11',
        'review_by' => 'next security review, or whenever this file is next modified',
    ],
];

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
    //
    // The needle is assembled at runtime on purpose. Written out whole, it
    // appears in this file and in the test that asserts the denylist works —
    // and the scan then flags its own denylist as a leak. The gate caught that
    // in TemplateLintTest.php. Splitting the literal means the string exists in
    // no tracked file at all, which is the state we actually want.
    $knownLeaked = ['Richman' . '26'];

    $suspect  = [];
    $accepted = [];
    $stale    = [];
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
    // Route each finding: accepted-by-a-human, or blocking.
    foreach ($suspect as $file => $why) {
        $rel = str_replace('\\', '/', $file);
        if (!isset(ACCEPTED_ISSUES[$rel])) {
            $problems[] = "possible committed credential in {$file}: " . implode('; ', array_unique($why));
            continue;
        }
        $accepted[$rel] = [
            'why'  => implode('; ', array_unique($why)),
            'meta' => ACCEPTED_ISSUES[$rel],
        ];
        unset($suspect[$rel]);
    }

    // An accepted entry that matches nothing means the issue was fixed and the
    // entry is now clutter pointing at a problem that no longer exists.
    foreach (ACCEPTED_ISSUES as $rel => $meta) {
        if (!isset($accepted[$rel])) {
            $stale[] = "{$rel} ({$meta['title']}): matches nothing — remove this entry";
        }
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

if ($stale) {
    // Not fatal — a stale entry is untidy, not dangerous — but it is printed
    // so the list cannot quietly rot into a list of things nobody remembers.
    echo "\n  STALE ACCEPTED ISSUES (accepted but no longer matching anything)\n";
    foreach ($stale as $s) {
        echo "    - {$s}\n";
    }
}

if ($accepted) {
    echo "\n  KNOWN ISSUES — accepted by a human, still present, NOT fixed\n";
    echo "  " . str_repeat('-', 72) . "\n";
    foreach ($accepted as $rel => $a) {
        echo "    {$rel} — {$a['meta']['title']}\n";
        echo "      finding: {$a['why']}\n";
        echo "      why accepted: {$a['meta']['why']}\n";
        echo "      accepted: {$a['meta']['accepted']}  |  review by: {$a['meta']['review_by']}\n";
    }
    echo "  " . str_repeat('-', 72) . "\n";
    echo "  These are printed on every run on purpose. The gate is green, but nothing here is hidden.\n";
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
