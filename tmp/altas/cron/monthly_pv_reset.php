<?php

/**
 * @file   cron/monthly_pv_reset.php
 * @brief  Monthly cron that resets members' Personal PV to 0 (Phase 5).
 *
 * Crontab: 0 0 1 * * /usr/bin/php /var/www/html/altasfarm/cron/monthly_pv_reset.php
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';

$logDir  = __DIR__ . '/logs';
$logFile = $logDir . '/pv_reset_' . date('Y-m') . '.log';

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

function log_line(string $level, string $message, string $logFile): void
{
    $ts   = date('Y-m-d H:i:s T');
    $line = "[{$ts}] [{$level}] {$message}" . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

try {
    $pdo = db();

    // Snapshot before reset
    $before = (float)$pdo->query("SELECT COALESCE(SUM(personal_pv), 0) FROM users WHERE role = 'member'")->fetchColumn();

    // Reset Personal PV for all members
    $affected = $pdo->exec("UPDATE users SET personal_pv = 0.00 WHERE role = 'member'");

    $after = (float)$pdo->query("SELECT COALESCE(SUM(personal_pv), 0) FROM users WHERE role = 'member'")->fetchColumn();

    log_line('INFO ', 'Monthly Personal PV reset started.', $logFile);
    log_line('OK   ', "Reset {$affected} member(s). Total PV before: {$before}, after: {$after}.", $logFile);

    exit(0);
} catch (\Exception $e) {
    log_line('ERROR', 'Monthly PV reset failed: ' . $e->getMessage(), $logFile);
    exit(1);
}
