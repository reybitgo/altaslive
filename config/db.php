<?php

/**
 * @file   config/db.php
 * @brief  Database configuration
 */

// ── Database Configuration ──────────────────────────────────────────────────
// Copy this file and fill in your real credentials.
// Never commit real credentials to version control.

// ── Timezone ──────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Manila');

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'u938213108_altas2_db');
define('DB_USER', 'u938213108_altas2_admin');
define('DB_PASS', '2v$J#?M^&F:');

// ── Application Configuration ───────────────────────────────────────────────
define('APP_URL',  'http://localhost/altaslive');  // No trailing slash
define('APP_NAME', 'Live Altas Farm');
define('APP_ENV',  'development');            // 'development' | 'production'

// ── Error display ────────────────────────────────────────────────────────────
if (APP_ENV === 'development') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
}

/**
 * Returns a singleton PDO connection.
 * Usage: db()->prepare(...)
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4;CLIENT_FOUND_ROWS=true',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (PDOException $e) {
            if (APP_ENV === 'development') {
                die('<pre>Database connection failed: ' . $e->getMessage() . '</pre>');
            }
            die('Service temporarily unavailable. Please try again later.');
        }
    }

    return $pdo;
}
