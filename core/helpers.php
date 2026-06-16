<?php

/**
 * @file   core/helpers.php
 * @brief  Helper functions for the application
 */

// ── Output / Escaping ────────────────────────────────────────────────────────

// ── User Helper Functions ────────────────────────────────────────────────────

/**
 * Mask a payout account for display (e.g. 0917****3210, or T***...***xYZ).
 */
function mask_account(string $account): string
{
    $len = strlen($account);
    if ($len <= 6) return $account;
    // Show first 4 and last 4
    return substr($account, 0, 4) . str_repeat('*', max(2, $len - 8)) . substr($account, -4);
}

/**
 * Get a user by ID.
 * Returns the full user row as associative array, or null if not found.
 */
function getUserById(int $id): ?array
{
    $pdo = db();  // Uses your existing db() helper

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}

/**
 * HTML-escape a value for safe output.
 */
function e(mixed $val): string
{
    return htmlspecialchars((string) $val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Format a number as Philippine Peso.
 */
function fmt_money(float $n, bool $showSign = false): string
{
    $formatted = '₱' . number_format(abs($n), 2);
    if ($showSign && $n < 0) return '-' . $formatted;
    if ($showSign && $n > 0) return '+' . $formatted;
    return $formatted;
}

/**
 * Short number format (1.2K, 3.4M).
 */
function fmt_short(float $n): string
{
    if ($n >= 1_000_000) return number_format($n / 1_000_000, 1) . 'M';
    if ($n >= 1_000)     return number_format($n / 1_000, 1) . 'K';
    return (string)(int)$n;
}

/**
 * Format a timestamp as human-readable date.
 */
function fmt_date(?string $ts, string $format = 'M d, Y'): string
{
    if (!$ts) return '—';
    return date($format, strtotime($ts));
}

function fmt_datetime(?string $ts): string
{
    return fmt_date($ts, 'M d, Y h:i A');
}

// ── Seat Limit Helpers ──────────────────────────────────────────────────────

function seatsRemaining(): int
{
    // Read directly from DB to avoid stale cache
    $row = db()->prepare("SELECT value FROM settings WHERE key_name = 'seat_limit'");
    $row->execute();
    $limit = (int) ($row->fetchColumn() ?: '1000');
    $count = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn();
    return max(0, $limit - $count);
}

function isSeatLimitReached(): bool
{
    return seatsRemaining() <= 0;
}

// ── Navigation ────────────────────────────────────────────────────────────────

function redirect(string $path): never
{
    // Ensure session data is written before redirecting
    // Prevents flash messages from being lost on same-page redirects
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // $path should start with / (relative to APP_URL) or be a full URL
    if (str_starts_with($path, 'http')) {
        header('Location: ' . $path);
    } else {
        header('Location: ' . APP_URL . $path);
    }
    exit;
}

function current_page(): string
{
    return $_GET['page'] ?? 'login';
}

function is_page(string $page): bool
{
    return current_page() === $page;
}

// ── Flash Messages ────────────────────────────────────────────────────────────

/**
 * Set or get a flash message.
 * flash('success', 'Saved!') → sets
 * flash('success')           → gets and clears
 */
function flash(string $key, string $msg = ''): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if ($msg !== '') {
        $_SESSION['flash'][$key] = $msg;
        return '';
    }
    $val = $_SESSION['flash'][$key] ?? '';
    unset($_SESSION['flash'][$key]);
    return $val;
}

/**
 * Render all flash messages as HTML.
 */
function render_flash(): string
{
    // Map flash key → Bootstrap 5 alert class + icon
    $types = [
        'success' => ['alert-success', '✓'],
        'error'   => ['alert-danger',  '✕'],
        'info'    => ['alert-info',    'ℹ'],
        'warning' => ['alert-warning', '⚠'],
    ];
    $html = '';
    foreach ($types as $key => [$bsClass, $icon]) {
        $msg = flash($key);
        if ($msg) {
            $html .= sprintf(
                '<div class="alert %s d-flex align-items-center gap-2 mb-3" role="alert">' .
                    '<span>%s</span><span>%s</span></div>',
                $bsClass,
                $icon,
                e($msg)
            );
        }
    }
    return $html;
}

// ── CSRF Protection ───────────────────────────────────────────────────────────

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('Invalid security token. Please go back and try again.');
    }
}

// ── Registration Code Generator ───────────────────────────────────────────────

/**
 * Generate a registration code like ABCD-EFGH-JKLM.
 * Uses an unambiguous charset (no 0/O/1/I confusion).
 */
function generate_code(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $len   = strlen($chars);
    $parts = [];
    for ($i = 0; $i < 3; $i++) {
        $part = '';
        for ($j = 0; $j < 4; $j++) {
            $part .= $chars[random_int(0, $len - 1)];
        }
        $parts[] = $part;
    }
    return implode('-', $parts);
}

// ── Input Sanitization ────────────────────────────────────────────────────────

function sanitize_username(string $u): string
{
    return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $u));
}

function is_valid_username(string $u): bool
{
    return (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,39}$/', $u);
}

function is_valid_mobile(string $m): bool
{
    return (bool) preg_match('/^(09|\+639)\d{9}$/', $m);
}

// ── JSON Response ─────────────────────────────────────────────────────────────

function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Rate Limiting (session-based) ─────────────────────────────────────────────

function rate_limit_check(string $key, int $maxAttempts = 5, int $windowSeconds = 900): bool
{
    $attempts = $_SESSION['rl'][$key]['attempts'] ?? 0;
    $since    = $_SESSION['rl'][$key]['since']    ?? 0;

    // Reset window if expired
    if ((time() - $since) > $windowSeconds) {
        $_SESSION['rl'][$key] = ['attempts' => 0, 'since' => time()];
        return true; // allowed
    }

    return $attempts < $maxAttempts;
}

function rate_limit_hit(string $key): void
{
    if (!isset($_SESSION['rl'][$key])) {
        $_SESSION['rl'][$key] = ['attempts' => 0, 'since' => time()];
    }
    $_SESSION['rl'][$key]['attempts']++;
}

function rate_limit_clear(string $key): void
{
    unset($_SESSION['rl'][$key]);
}

// ── System Setting ────────────────────────────────────────────────────────────

function setting(string $key, string $default = ''): string
{
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $st = db()->prepare('SELECT value FROM settings WHERE key_name = ?');
        $st->execute([$key]);
        $value = $st->fetchColumn();
        // fetchColumn() returns false on no row; ?? only checks null, so convert false to null
        $cache[$key] = ($value === false ? null : $value) ?? $default;
    }
    return $cache[$key];
}

// ── Pagination ────────────────────────────────────────────────────────────────

function paginate(string $query, array $params, int $page, int $perPage = 20): array
{
    $pdo = db();

    // Count
    $countSql = preg_replace('/SELECT .+? FROM /is', 'SELECT COUNT(*) FROM ', $query, 1);
    $countSql = preg_replace('/ORDER BY .+$/is', '', $countSql);
    $st = $pdo->prepare($countSql);
    $st->execute($params);
    $total = (int) $st->fetchColumn();

    $totalPages = max(1, (int) ceil($total / $perPage));
    $page       = max(1, min($page, $totalPages));
    $offset     = ($page - 1) * $perPage;

    $st = $pdo->prepare($query . " LIMIT $perPage OFFSET $offset");
    $st->execute($params);

    return [
        'data'        => $st->fetchAll(),
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => $totalPages,
        'has_prev'    => $page > 1,
        'has_next'    => $page < $totalPages,
    ];
}

/**
 * Render pagination links as HTML.
 */
function pagination_links(array $p, string $baseUrl): string
{
    if ($p['total_pages'] <= 1) return '';
    $html = '<div class="pagination">';
    if ($p['has_prev']) {
        $html .= '<a href="' . $baseUrl . '&pg=' . ($p['page'] - 1) . '" class="page-btn">‹ Prev</a>';
    }
    $html .= '<span class="page-info">Page ' . $p['page'] . ' of ' . $p['total_pages'] . '</span>';
    if ($p['has_next']) {
        $html .= '<a href="' . $baseUrl . '&pg=' . ($p['page'] + 1) . '" class="page-btn">Next ›</a>';
    }
    $html .= '</div>';
    return $html;
}
