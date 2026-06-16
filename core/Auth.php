<?php

/**
 * @file   core/Auth.php
 * @brief  Authentication management class
 */
class Auth
{
    // ── Session Bootstrap ─────────────────────────────────────────────────────

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_strict_mode', 1);
            if (APP_ENV === 'production') {
                ini_set('session.cookie_secure', 1);
            }
            session_name('mlm_sess');
            session_start();
        }
    }

    // ── Login / Logout ────────────────────────────────────────────────────────

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id']   = (int) $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['username']  = $user['username'];

        // Update last_login
        db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
            ->execute([$user['id']]);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $p['path'],
                $p['domain'],
                $p['secure'],
                $p['httponly']
            );
        }
        session_destroy();
        redirect('/');
    }

    // ── Status Checks ─────────────────────────────────────────────────────────

    public static function check(): bool
    {
        return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
    }

    public static function isAdmin(): bool
    {
        return self::check() && $_SESSION['user_role'] === 'admin';
    }

    public static function isMember(): bool
    {
        return self::check() && $_SESSION['user_role'] === 'member';
    }

    public static function id(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    // ── Current User ──────────────────────────────────────────────────────────

    /**
     * Returns the full user row, cached for the request.
     */
    public static function user(): array
    {
        if (!self::check()) return [];

        static $user = null;
        if ($user === null) {
            $st = db()->prepare(
                'SELECT u.*, p.name AS package_name, p.pairing_bonus, p.daily_pair_cap,
                        p.direct_ref_bonus,
                        sp.username AS sponsor_username,
                        bp.username AS binary_parent_username
                 FROM   users u
                 LEFT JOIN packages p  ON p.id  = u.package_id
                 LEFT JOIN users sp    ON sp.id = u.sponsor_id
                 LEFT JOIN users bp    ON bp.id = u.binary_parent_id
                 WHERE  u.id = ?'
            );
            $st->execute([self::id()]);
            $user = $st->fetch() ?: [];
        }
        return $user;
    }

    // ── Guards ────────────────────────────────────────────────────────────────

    /**
     * Ensure current request is authenticated.
     * $role: 'member' | 'admin' | 'any' | 'guest'
     */
    public static function guard(string $role = 'member'): void
    {
        if ($role === 'guest') {
            if (self::check()) {
                redirect(self::isAdmin() ? '/?page=admin' : '/?page=dashboard');
            }
            return;
        }

        if (!self::check()) {
            flash('error', 'Please log in to continue.');
            redirect('/?page=login');
        }

        if ($role === 'admin' && !self::isAdmin()) {
            flash('error', 'Access denied.');
            redirect('/?page=dashboard');
        }

        // Check account status
        $u = self::user();
        if (($u['status'] ?? '') === 'suspended') {
            self::logout(); // clears session
        }
    }
}
