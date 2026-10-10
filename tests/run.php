<?php

/**
 * @file   tests/run.php
 * @brief  Test runner. Recreates the test schema, then runs every test file.
 *
 * Usage:
 *   php tests/run.php              # recreate schema, run all suites
 *   php tests/run.php --keep       # leave the schema in place afterwards
 *   php tests/run.php SchemaDrift  # run only matching files
 *
 * The schema is dropped and rebuilt from install.sql on every run, so a suite
 * can never inherit residue from an aborted previous run. The application
 * schema is never referenced: tests/bootstrap.php refuses to start unless the
 * target schema name contains "_test".
 */

// Tell bootstrap.php this process is about to (re)create the schema, so it
// does not refuse an empty target.
define('TEST_RUNNER_SEEDING', true);

require_once __DIR__ . '/bootstrap.php';

$argvOpts = array_slice($argv, 1);
$keep     = in_array('--keep', $argvOpts, true);
$only     = array_values(array_filter($argvOpts, static fn($a) => strpos($a, '--') !== 0));

// The version is read over a schema-less connection: the schema under test may
// not exist yet, which is precisely what this script is about to fix.
$probeVersion = (static function (): string {
    try {
        $c = new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        return (string) $c->query('SELECT VERSION()')->fetchColumn();
    } catch (Throwable $e) {
        return 'unreachable (' . $e->getMessage() . ')';
    }
})();

echo "\n";
echo "AltasLive test harness\n";
echo str_repeat('=', 72) . "\n";
echo "  schema : " . DB_NAME . "\n";
echo "  server : " . $probeVersion . "\n";
echo "  app db : " . DB_APP_NAME . " (never written to)\n";
echo str_repeat('=', 72) . "\n";

// ── Rebuild the schema from install.sql ─────────────────────────────────────
$install = REPO_ROOT . '/install.sql';
if (!is_readable($install)) {
    fwrite(STDERR, "install.sql not readable at {$install}\n");
    exit(2);
}

/**
 * Split a SQL script into statements.
 *
 * install.sql cannot be split on a bare ';' or ";\n": it is CRLF-terminated
 * and it contains semicolons inside both '--' comments (e.g.
 * "sku VARCHAR(64) NULL, -- optional; unique when set") and quoted strings.
 * This walks the text tracking quote state, drops comments, and splits only on
 * a semicolon that is outside quotes.
 *
 * @return string[]
 */
function sql_statements(string $sql): array
{
    $out   = [];
    $buf   = '';
    $len   = strlen($sql);
    $quote = null;   // "'", '"', '`' or null
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];

        if ($quote !== null) {
            $buf .= $c;
            if ($c === '\\' && $quote === "'" && $i + 1 < $len) {
                $buf .= $sql[++$i];   // escaped char inside a string
                continue;
            }
            if ($c === $quote) {
                // doubled quote is an escape, not a terminator
                if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                    $buf .= $sql[++$i];
                    continue;
                }
                $quote = null;
            }
            continue;
        }

        // outside quotes
        if ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c;
            $buf .= $c;
            continue;
        }
        if (($c === '-' && $i + 1 < $len && $sql[$i + 1] === '-')
            || ($c === '#' && ($i === 0 || $sql[$i - 1] === "\n"))) {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            $buf .= "\n";
            continue;
        }
        if ($c === ';') {
            $stmt = trim($buf);
            if ($stmt !== '') {
                $out[] = $stmt;
            }
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    $stmt = trim($buf);
    if ($stmt !== '') {
        $out[] = $stmt;
    }
    return $out;
}

echo "\n[1/3] rebuilding " . DB_NAME . " from install.sql ...\n";

// Seeding uses a SEPARATE connection. db() is a singleton already bound to the
// schema we are about to DROP, so every statement after that would fail with
// "Unknown database". The suites run as subprocesses and reconnect anyway.
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
$admin = static fn(): PDO => new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT),
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$admin()->exec("DROP DATABASE IF EXISTS `" . DB_NAME . "`");
$admin()->exec("CREATE DATABASE `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$sql = (string) file_get_contents($install);
$sql = preg_replace('/^\xEF\xBB\xBF/', '', (string) $sql);
$sql = preg_replace('/^[ \t]*CREATE DATABASE[^;]*;/mi', '', (string) $sql);
$sql = preg_replace('/^[ \t]*USE\s+\S+\s*;/mi', '', (string) $sql);
$sql = str_replace("\r\n", "\n", (string) $sql);
$statements = sql_statements((string) $sql);

$seed = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$loaded = 0;
foreach ($statements as $stmt) {
    try {
        $seed->exec($stmt);
        $loaded++;
    } catch (Throwable $e) {
        echo "      skipped: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 70)
            . "  [" . $e->getMessage() . "]\n";
    }
}
$pdo = $seed;
$tables = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
)->fetchColumn();
echo "      {$loaded} statements executed, {$tables} tables present\n";
if ($tables === 0) {
    fwrite(STDERR, "Schema is empty after load; aborting.\n");
    exit(2);
}

// ── Discover and run suites ─────────────────────────────────────────────────
$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);
if ($only) {
    $files = array_values(array_filter($files, static function ($f) use ($only) {
        foreach ($only as $needle) {
            if (stripos(basename($f), $needle) !== false) {
                return true;
            }
        }
        return false;
    }));
}
if (!$files) {
    fwrite(STDERR, "No *Test.php files found.\n");
    exit(2);
}

echo "\n[2/3] running " . count($files) . " suite(s) ...\n\n";
$php = PHP_BINARY;
$failed = [];

foreach ($files as $file) {
    $name = basename($file);
    $out  = [];
    $code = 0;
    exec(escapeshellcmd($php) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    // Strip ANSI colour so the summary line can be scanned.
    $plain = preg_replace('/\033\[[0-9;]*m/', '', $text);
    $m = [];
    preg_match('/(ALL GREEN|FAILED)\s+(\d+) assertions, (\d+) failed/', $plain, $m);

    if ($code === 0) {
        printf("  \033[32mPASS\033[0m  %-28s %s\n", $name, $m ? "{$m[2]} assertions" : 'ok');
    } else {
        $failed[] = $name;
        printf("  \033[31mFAIL\033[0m  %-28s %s\n", $name, $m ? "{$m[3]} of {$m[2]} failed" : "exit {$code}");
        foreach ($out as $line) {
            if (strpos($line, "\033[31mFAIL\033[0m") !== false) {
                echo '        ' . preg_replace('/\033\[[0-9;]*m/', '', $line) . "\n";
            }
        }
    }
}

// ── Teardown ────────────────────────────────────────────────────────────────
echo "\n[3/3] teardown ...\n";
if ($keep) {
    echo "      kept (--keep): " . DB_NAME . " is still present\n";
} else {
    db()->exec("DROP DATABASE IF EXISTS `" . DB_NAME . "`");
    echo "      dropped " . DB_NAME . "\n";
}

echo "\n" . str_repeat('=', 72) . "\n";
if ($failed) {
    echo "\033[31mSUITE FAILED\033[0m  " . count($failed) . " of " . count($files) . ": " . implode(', ', $failed) . "\n";
    echo str_repeat('=', 72) . "\n";
    exit(1);
}
echo "\033[32mALL SUITES PASS\033[0m  " . count($files) . " suite(s)\n";
echo str_repeat('=', 72) . "\n";
exit(0);