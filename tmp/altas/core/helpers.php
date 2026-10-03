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
 *
 * Accepts null defensively: SQL aggregates like SUM() return NULL on empty
 * result sets, and a stray NULL here used to crash the whole page with a
 * TypeError. Coerce it to 0.0 so a "no data yet" state renders as ₱0.00
 * instead of a fatal error.
 */
function fmt_money(float|null $n, bool $showSign = false): string
{
    $n = (float)($n ?? 0);
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

/**
 * Current rows-per-page value from the query string.
 * Defaults to 10 and is never lower than 5.
 */
function per_page(int $default = 10, int $min = 5): int
{
    $val = (int)($_GET['per_page'] ?? $default);
    return max($min, $val);
}

function paginate(string $query, array $params, int $page, int $perPage = 10): array
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
 * Upload an image to the uploads/ directory.
 *
 * @param array       $file     The $_FILES entry.
 * @param string      $subDir   Sub-directory under uploads/ (e.g. 'products').
 * @param string      $prefix   Filename prefix (e.g. 'product_3').
 * @param string|null $oldPath  Optional existing relative path to delete after a successful upload.
 * @param int         $maxBytes Max file size in bytes (default 5 MB).
 * @return string|null Relative path stored in DB, or null if no file was uploaded.
 * @throws InvalidArgumentException|RuntimeException on validation or move failure.
 */
function upload_image(array $file, string $subDir, string $prefix, ?string $oldPath = null, int $maxBytes = 5 * 1024 * 1024): ?string
{
    if (empty($file['tmp_name']) || (int)($file['error'] ?? 0) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed (error ' . (int)$file['error'] . ').');
    }

    $mime    = mime_content_type($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        throw new InvalidArgumentException('Invalid image type. Allowed: JPEG, PNG, GIF, WebP.');
    }

    if ((int)$file['size'] > $maxBytes) {
        throw new InvalidArgumentException('Image must be under ' . round($maxBytes / 1024 / 1024, 1) . ' MB.');
    }

    $uploadRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
    $dir        = $uploadRoot . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $subDir) . DIRECTORY_SEPARATOR;

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $ext  = $allowed[$mime];
    $name = $prefix . '_' . time() . '.' . $ext;
    $dest = $dir . $name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Failed to save uploaded image.');
    }

    if ($oldPath) {
        delete_uploaded_file($oldPath);
    }

    return $subDir . '/' . $name;
}

/**
 * Delete an uploaded file relative to the uploads/ directory.
 */
function delete_uploaded_file(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }

    $uploadRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
    $path       = $uploadRoot . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);

    if (file_exists($path)) {
        @unlink($path);
    }
}

/**
 * Render Bootstrap 5 pagination links.
 * Includes First/Prev, numbered pages with ellipsis, and Next/Last controls.
 */
function pagination_links(array $p, string $baseUrl): string
{
    $totalPages = (int) ($p['total_pages'] ?? 1);
    $page       = (int) ($p['page'] ?? 1);
    if ($totalPages <= 1) {
        return '';
    }

    $pages = _pagination_page_range($page, $totalPages);

    $html  = '<nav aria-label="Page navigation">';
    $html .= '<ul class="pagination pagination-sm justify-content-center mb-0 flex-wrap">';

    // First
    $firstDisabled = $page <= 1 ? ' disabled' : '';
    $firstHref     = $page <= 1 ? '' : ' href="' . $baseUrl . '&pg=1"';
    $html .= '<li class="page-item' . $firstDisabled . '">';
    if ($page <= 1) {
        $html .= '<span class="page-link" aria-label="First" aria-disabled="true"><span aria-hidden="true">&laquo;</span></span>';
    } else {
        $html .= '<a class="page-link"' . $firstHref . ' aria-label="First"><span aria-hidden="true">&laquo;</span></a>';
    }
    $html .= '</li>';

    // Previous
    $prevDisabled = $page <= 1 ? ' disabled' : '';
    $prevHref     = $page <= 1 ? '' : ' href="' . $baseUrl . '&pg=' . ($page - 1) . '"';
    $html .= '<li class="page-item' . $prevDisabled . '">';
    if ($page <= 1) {
        $html .= '<span class="page-link" aria-label="Previous" aria-disabled="true"><span aria-hidden="true">&lsaquo;</span></span>';
    } else {
        $html .= '<a class="page-link"' . $prevHref . ' aria-label="Previous"><span aria-hidden="true">&lsaquo;</span></a>';
    }
    $html .= '</li>';

    // Page numbers + ellipsis
    $lastShown = 0;
    foreach ($pages as $i) {
        if ($i - $lastShown > 1) {
            $html .= '<li class="page-item disabled"><span class="page-link" aria-disabled="true">&hellip;</span></li>';
        }
        if ($i === $page) {
            $html .= '<li class="page-item active" aria-current="page"><span class="page-link">' . $i . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '&pg=' . $i . '">' . $i . '</a></li>';
        }
        $lastShown = $i;
    }

    // Next
    $nextDisabled = $page >= $totalPages ? ' disabled' : '';
    $nextHref     = $page >= $totalPages ? '' : ' href="' . $baseUrl . '&pg=' . ($page + 1) . '"';
    $html .= '<li class="page-item' . $nextDisabled . '">';
    if ($page >= $totalPages) {
        $html .= '<span class="page-link" aria-label="Next" aria-disabled="true"><span aria-hidden="true">&rsaquo;</span></span>';
    } else {
        $html .= '<a class="page-link"' . $nextHref . ' aria-label="Next"><span aria-hidden="true">&rsaquo;</span></a>';
    }
    $html .= '</li>';

    // Last
    $lastDisabled = $page >= $totalPages ? ' disabled' : '';
    $lastHref     = $page >= $totalPages ? '' : ' href="' . $baseUrl . '&pg=' . $totalPages . '"';
    $html .= '<li class="page-item' . $lastDisabled . '">';
    if ($page >= $totalPages) {
        $html .= '<span class="page-link" aria-label="Last" aria-disabled="true"><span aria-hidden="true">&raquo;</span></span>';
    } else {
        $html .= '<a class="page-link"' . $lastHref . ' aria-label="Last"><span aria-hidden="true">&raquo;</span></a>';
    }
    $html .= '</li>';

    $html .= '</ul></nav>';
    return $html;
}

/**
 * Build a compact page-number range for pagination_links().
 */
function _pagination_page_range(int $current, int $total): array
{
    if ($total <= 7) {
        return range(1, $total);
    }

    $pages = [1, 2];

    $start = max(3, $current - 1);
    $end   = min($total - 2, $current + 1);

    for ($i = $start; $i <= $end; $i++) {
        $pages[] = $i;
    }

    $pages[] = $total - 1;
    $pages[] = $total;

    // Remove duplicates while preserving order
    $seen = [];
    $out  = [];
    foreach ($pages as $i) {
        if (!isset($seen[$i])) {
            $out[] = $i;
            $seen[$i] = true;
        }
    }
    return $out;
}
