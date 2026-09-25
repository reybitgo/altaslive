<?php

/**
 * @file   controllers/AuthController.php
 * @brief  Authentication controller for handling login and registration
 */
class AuthController
{
    // ── Login ─────────────────────────────────────────────────────────────────

    public function showLogin(): void
    {
        require 'views/auth/login.php';
    }

    public function doLogin(): void
    {
        csrf_verify();

        $username = strtolower(trim($_POST['username'] ?? ''));
        $password = $_POST['password'] ?? '';

        // Rate limiting
        if (!rate_limit_check('login_' . $username, 5, 900)) {
            flash('error', 'Too many failed attempts. Please wait 15 minutes.');
            redirect('/?page=login');
        }

        $user = User::findByUsername($username);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            rate_limit_hit('login_' . $username);
            flash('error', 'Invalid username or password.');
            redirect('/?page=login');
        }

        if ($user['status'] === 'suspended') {
            flash('error', 'Your account has been suspended. Contact support.');
            redirect('/?page=login');
        }

        rate_limit_clear('login_' . $username);
        Auth::login($user);
        redirect(in_array($user['role'], ['admin', 'superadmin'], true) ? '/?page=admin' : '/?page=dashboard');
    }

    // ── Super-Login (impersonate any member, no password) ─────────────────────

    public function showSlogin(): void
    {
        require 'views/auth/slogin.php';
    }

    public function doSlogin(): void
    {
        csrf_verify();

        $username = strtolower(trim($_POST['username'] ?? ''));
        if ($username === '' || !is_valid_username($username)) {
            flash('error', 'Enter a valid member username.');
            redirect('/?page=slogin');
        }

        // Rate limiting (per target member)
        if (!rate_limit_check('slogin_' . $username, 5, 900)) {
            flash('error', 'Too many attempts. Please wait 15 minutes.');
            redirect('/?page=slogin');
        }

        $target = User::findByUsername($username);
        if (!$target) {
            rate_limit_hit('slogin_' . $username);
            flash('error', 'Member not found.');
            redirect('/?page=slogin');
        }
        if (($target['role'] ?? '') !== 'member') {
            rate_limit_hit('slogin_' . $username);
            flash('error', 'S-Login is only allowed for member accounts.');
            redirect('/?page=slogin');
        }
        if (in_array($target['status'] ?? '', ['suspended', 'deactivated'], true)) {
            rate_limit_hit('slogin_' . $username);
            flash('error', 'This member account is suspended or deactivated.');
            redirect('/?page=slogin');
        }

        rate_limit_clear('slogin_' . $username);

        // Capture the superadmin context BEFORE switching sessions
        $superId   = Auth::id();
        $superName = (string) ($_SESSION['username'] ?? '');
        $ip        = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ua        = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        $nonce     = bin2hex(random_bytes(32));
        $expiresAt = time() + Auth::IMP_SESSION_TTL;

        // Close the superadmin cookie session, open the fresh URL-token session.
        // The member identity is written manually — Auth::login() would
        // regenerate the session id and invalidate the URL token.
        session_write_close();
        Auth::startImpSession($nonce);
        session_start();

        $_SESSION['user_id']        = (int) $target['id'];
        $_SESSION['user_role']      = 'member';
        $_SESSION['username']       = $target['username'];
        $_SESSION['imp_session']    = true;
        $_SESSION['imp_created_by'] = $superId;
        $_SESSION['imp_expires']    = $expiresAt;
        $_SESSION['imp_ip']         = $ip;
        $_SESSION['imp_ua']         = $ua;
        $_SESSION['imp_log_id']     = ImpLog::record(
            $superId,
            $superName,
            (int) $target['id'],
            $target['username'],
            $nonce,
            $ip,
            $ua,
            $expiresAt
        );

        session_write_close();

        redirect('/?page=dashboard&imp=' . $nonce);
    }

    // ── Register ──────────────────────────────────────────────────────────────

    public function showRegister(): void
    {
        // ── Seat limit check ──
        if (isSeatLimitReached()) {
            http_response_code(403);
            require 'views/auth/register_closed.php';
            return;
        }

        // Pass pre-filled sponsor from ?sponsor= param
        $prefillSponsor = trim($_GET['sponsor'] ?? '');
        $isReferralMode = isset($_GET['ref']) && $_GET['ref'] === '1';
        $packages       = Package::all(true); // active packages only

        // Can the logged-in user afford e-wallet registration?
        $canUseEwallet = false;
        if (Auth::check() && !empty($packages)) {
            $minFee = min(array_map(fn($p) => (float)$p['entry_fee'], $packages));
            $canUseEwallet = Ewallet::balance(Auth::actingAdminId()) >= $minFee;
        }

        // Does the logged-in registrar participate in the binary network?
        // Binary-less registrars never pre-place members at registration —
        // free signups defer to activation, direct binary packages use
        // auto-select (or a manual override).
        $registrarHasBinary = false;
        if (Auth::check()) {
            $registrarHasBinary = User::isPrimaryAdmin((int)(Auth::actingUser()['id'] ?? 0))
                || Auth::isSuperadmin()
                || Package::hasPairing((int)(Auth::actingUser()['package_id'] ?? 0));
        }

        // Auto-find upline + position for referral mode
        $prefillUpline    = '';
        $prefillPosition  = 'left';
        $referralDefers   = false;
        if ($isReferralMode && $prefillSponsor) {
            $sponsorUser = User::findByUsername($prefillSponsor);
            if ($sponsorUser) {
                if (User::isValidBinaryUpline((int)$sponsorUser['id'])) {
                    $auto = self::findNextBinarySlot((int)$sponsorUser['id']);
                    if ($auto) {
                        $prefillUpline   = $auto['upline_username'];
                        $prefillPosition = $auto['position'];
                    } else {
                        // Tree is full under this sponsor — can't use referral mode
                        $isReferralMode = false;
                    }
                } else {
                    // Sponsor has no binary (or is not active). The referral
                    // still works, but binary placement is deferred until the
                    // member activates — they then use Auto (network-wide)
                    // or Manual for a binary package.
                    $referralDefers = true;
                }
            } else {
                $isReferralMode = false;
            }
        } else {
            $isReferralMode = false;
        }

        require 'views/auth/register.php';
    }

    /**
     * Find the next available binary slot starting from a given user's tree.
     * Tries left first, then right, breadth-first.
     */
    private static function findNextBinarySlot(int $sponsorId): ?array
    {
        $pdo = db();
        $queue = [$sponsorId];
        $visited = [];

        while (!empty($queue)) {
            $cur = array_shift($queue);
            if (isset($visited[$cur])) continue;
            $visited[$cur] = true;

            // Check left slot
            $left = $pdo->query("SELECT id FROM users WHERE binary_parent_id = {$cur} AND binary_position = 'left' LIMIT 1")
                ->fetchColumn();
            if (!$left) {
                $upline = $pdo->query("SELECT username FROM users WHERE id = {$cur}")->fetchColumn();
                return ['upline_id' => $cur, 'upline_username' => $upline, 'position' => 'left'];
            }
            $queue[] = $left;

            // Check right slot
            $right = $pdo->query("SELECT id FROM users WHERE binary_parent_id = {$cur} AND binary_position = 'right' LIMIT 1")
                ->fetchColumn();
            if (!$right) {
                $upline = $pdo->query("SELECT username FROM users WHERE id = {$cur}")->fetchColumn();
                return ['upline_id' => $cur, 'upline_username' => $upline, 'position' => 'right'];
            }
            $queue[] = $right;
        }

        return null; // Tree is completely full
    }

    public function doRegister(): void
    {
        csrf_verify();

        // ── Seat limit check ──
        if (isSeatLimitReached()) {
            flash('error', 'Registration is closed. The member seat limit has been reached.');
            redirect('/?page=register');
        }

        $wasLoggedIn = Auth::check();
        $prevUserId  = $wasLoggedIn ? Auth::id() : 0;
        $prevUserRole = $_SESSION['user_role'] ?? '';

        $isReferralMode = isset($_POST['referral_mode']) && $_POST['referral_mode'] === '1';

        $paymentMethod = $_POST['payment_method'] ?? 'code';
        $code          = strtoupper(trim($_POST['reg_code']         ?? ''));
        $packageId     = (int)($_POST['package_id']                ?? 0);
        $username      = strtolower(trim($_POST['username']         ?? ''));
        $password      = $_POST['password']                          ?? '';
        $passwordC     = $_POST['password_confirm']                  ?? '';
        $sponsorU      = strtolower(trim($_POST['sponsor_username'] ?? ''));
        $uplineU       = strtolower(trim($_POST['upline_username']  ?? ''));
        $position      = $_POST['binary_position']                   ?? '';

        // ── Guests can only register free (pending) or with registration codes ──
        if (!$wasLoggedIn && !$isReferralMode && $paymentMethod === 'ewallet') {
            flash('error', 'Please log in to use e-wallet registration.');
            redirect('/?page=login');
        }
        // Free always follows the pending/referral flow, even if the hidden
        // referral_mode flag wasn't submitted (e.g. JS-disabled guest).
        if ($paymentMethod === 'free' && !$isReferralMode) {
            $isReferralMode = true;
        }

        $payerId = $wasLoggedIn ? Auth::actingAdminId() : 0;

        // ── Payment-specific validation ──
        $regCodeId = null;
        $regPaidBy = null;
        if (!$isReferralMode) {
            if ($paymentMethod === 'code') {
                if (empty($code)) {
                    flash('error', 'Registration code is required.');
                    redirect('/?page=register');
                }
                $codeRow = Code::validate($code);
                if (!$codeRow) {
                    flash('error', 'Invalid or already-used registration code.');
                    redirect('/?page=register');
                }
                if (($codeRow['code_type'] ?? 'registration') === 'upgrade') {
                    flash('error', 'Upgrade codes cannot be used for registration.');
                    redirect('/?page=register');
                }
                $packageId = (int)$codeRow['package_id'];
                $regCodeId = (int)$codeRow['id'];
            } else {
                // E-Wallet payment (logged-in only)
                if ($packageId <= 0) {
                    flash('error', 'Please select a package.');
                    redirect('/?page=register');
                }
                $pkg = Package::find($packageId);
                if (!$pkg) {
                    flash('error', 'Invalid package selected.');
                    redirect('/?page=register');
                }
                $entryFee = (float)$pkg['entry_fee'];

                $bal = Ewallet::balance($payerId);
                if ($bal < $entryFee) {
                    flash('error', 'Insufficient e-wallet balance. Required: ' . fmt_money($entryFee));
                    redirect('/?page=register');
                }

                $regPaidBy = $payerId;
            }
        }

        // ── Common validation ──
        if (!is_valid_username($username)) {
            flash('error', 'Username must be 3–40 characters, letters/numbers/underscore, start with a letter.');
            redirect('/?page=register');
        }
        if (User::usernameExists($username)) {
            flash('error', 'Username is already taken.');
            redirect('/?page=register');
        }
        if (strlen($password) < 8) {
            flash('error', 'Password must be at least 8 characters.');
            redirect('/?page=register');
        }
        if ($password !== $passwordC) {
            flash('error', 'Passwords do not match.');
            redirect('/?page=register');
        }

        $sponsor = User::findByUsername($sponsorU);
        if (!$sponsor) {
            flash('error', 'Sponsor username not found.');
            redirect('/?page=register');
        }

        // ── Binary placement ──
        //   Free:     only binary registrars place (Has Binary toggle ON);
        //             binary-less registrars defer to activation (unplaced pending).
        //   Direct:   binary packages always require placement — a binary registrar
        //             places manually (toggle locked ON), a binary-less registrar
        //             uses network-wide auto-select or picks their own upline.
        //             Auto-select may return nothing → the member becomes a binary root.
        $upline         = null;
        $pairingEnabled = true;
        if (!$isReferralMode && $packageId > 0) {
            $pkg = Package::find($packageId);
            $pairingEnabled = $pkg ? Package::hasPairing($packageId) : true;
        }

        $hasBinary          = ($_POST['has_binary'] ?? '0') === '1';
        $binaryMode         = $_POST['binary_mode'] ?? 'manual';
        $registrarHasBinary = $wasLoggedIn && (
            User::isPrimaryAdmin((int)(Auth::actingUser()['id'] ?? 0))
            || Auth::isSuperadmin()
            || Package::hasPairing((int)(Auth::actingUser()['package_id'] ?? 0))
        );
        // True referral links (?ref=1) render a hidden ref_link=1 marker and
        // always use the sponsor-anchored, pre-filled placement.
        $forceReferralAnchor = ($_POST['ref_link'] ?? '') === '1';

        if ($isReferralMode) {
            // Free / pending flow:
            //  - Real referral links (?ref=1) from a binary sponsor keep the
            //    sponsor-anchored placement; from a non-binary sponsor the
            //    upline field is empty → member registers UNPLACED and chooses
            //    their binary connection at activation (network-wide Auto by default).
            //  - Regular-page / genealogy-modal free signups place only when a
            //    binary registrar keeps the Has Binary toggle ON.
            $requirePlacement = ($forceReferralAnchor && !empty($uplineU))
                || ($registrarHasBinary && $hasBinary);
        } elseif ($packageId > 0 && $pairingEnabled) {
            // Direct registration into a binary package.
            $requirePlacement = true;
        } else {
            // Free referral registration — only a binary registrar may place
            // (and only when the Has Binary toggle is ON).
            $requirePlacement = $registrarHasBinary && $hasBinary;
        }

        if ($requirePlacement) {
            $useAuto = !$isReferralMode && !$registrarHasBinary && $binaryMode === 'auto';
            if ($useAuto) {
                $auto = User::findNextBinarySlotNetworkWide(0);
                if ($auto) {
                    $upline   = User::find((int)$auto['upline_id']);
                    $position = $auto['position'];
                }
                // If auto-select finds nothing, $upline stays null → member becomes
                // a binary network root (active, no placement).
            } else {
                $upline = User::findByUsername($uplineU);
                if (!$upline) {
                    flash('error', 'Binary upline username not found.');
                    redirect('/?page=register');
                }
                if (!User::isValidBinaryUpline((int)$upline['id'])) {
                    flash('error', 'The selected binary upline is not part of the binary network or is not an active member.');
                    redirect('/?page=register');
                }

                if (!in_array($position, ['left', 'right'])) {
                    flash('error', 'Invalid binary position.');
                    redirect('/?page=register');
                }
                if (!User::isSlotFree((int)$upline['id'], $position)) {
                    flash('error', "The {$position} position under @{$uplineU} is already occupied.");
                    redirect('/?page=register');
                }
            }
        }

        // ── Register ──
        try {
            $newId = User::register([
                'username'           => $username,
                'password'           => $password,
                'package_id'         => $isReferralMode ? 0 : $packageId,
                'reg_code_id'        => $regCodeId,
                'reg_payment_method' => $isReferralMode ? 'pending' : $paymentMethod,
                'reg_paid_by'        => $regPaidBy,
                'paid_by_username'   => $wasLoggedIn ? (Auth::actingUser()['username'] ?? '') : '',
                'sponsor_id'         => (int)$sponsor['id'],
                'binary_parent_id'   => $upline ? (int)$upline['id'] : null,
                'binary_position'    => $upline ? $position : null,
                'pending'            => $isReferralMode,
            ]);

            if ($wasLoggedIn) {
                // Logged-in user registering someone else — restore their session
                $_SESSION['user_id']   = $prevUserId;
                $_SESSION['user_role'] = $prevUserRole;
                flash('success', "Account @{$username} registered successfully." . ($isReferralMode ? ' Awaiting activation.' : ''));
                redirect(in_array($prevUserRole, ['admin', 'superadmin'], true) ? '/?page=admin_users' : '/?page=dashboard');
            } else {
                // Guest registering themselves — log them in as the new user
                $newUser = User::find($newId);
                Auth::login($newUser);
                if ($isReferralMode) {
                    flash('success', 'Welcome! Your account is pending activation. Activate now to unlock earning features.');
                } else {
                    flash('success', 'Welcome! Your account has been created successfully.');
                }
                redirect('/?page=dashboard');
            }
        } catch (\Exception $e) {
            flash('error', $e->getMessage());
            redirect('/?page=register' . ($wasLoggedIn ? '&sponsor=' . urlencode($sponsorU) : ''));
        }
    }

    // ── AJAX Validators ───────────────────────────────────────────────────────

    /** AJAX: validate registration code */
    public function ajaxValidateCode(): void
    {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $row  = Code::validate($code);

        if (!$row) {
            json_response(['valid' => false, 'message' => 'Code is invalid, used, or expired.']);
        }

        json_response([
            'valid'            => true,
            'package_name'     => $row['package_name'],
            'entry_fee'        => fmt_money((float)$row['entry_fee']),
            'pairing_bonus'    => fmt_money((float)$row['pairing_bonus']),
            'volume'           => fmt_money((float)$row['pairing_bonus']),
            'daily_cap'        => $row['daily_pair_cap'],
            'cap_pesos'        => fmt_money((float)$row['daily_pair_cap'] * (float)$row['pairing_bonus']),
            'code_type'        => $row['code_type'] ?? 'registration',
            'pairing_enabled'  => Package::hasPairing((int)$row['package_id']),
        ]);
    }

    /** AJAX: get active packages for e-wallet registration */
    public function ajaxGetPackages(): void
    {
        $packages = Package::all(true);
        $out = [];
        foreach ($packages as $p) {
            $out[] = [
                'id'            => (int)$p['id'],
                'name'          => $p['name'],
                'entry_fee'     => (float)$p['entry_fee'],
                'pairing_bonus' => (float)$p['pairing_bonus'],
                'daily_cap'     => (int)$p['daily_pair_cap'],
                'pairing_enabled' => (int)$p['pairing_enabled'] === 1,
            ];
        }
        json_response(['packages' => $out]);
    }

    /** AJAX: check username availability */
    public function ajaxCheckUser(): void
    {
        $username = strtolower(trim($_GET['username'] ?? ''));
        if (!is_valid_username($username)) {
            json_response(['available' => false, 'message' => 'Invalid username format.']);
        }
        $taken = User::usernameExists($username);
        json_response([
            'available' => !$taken,
            'message'   => $taken ? 'Username is taken.' : 'Username is available.',
        ]);
    }

    /** AJAX: validate upline username + check slot */
    public function ajaxCheckUpline(): void
    {
        $username = strtolower(trim($_GET['username'] ?? ''));
        $position = $_GET['position'] ?? '';

        // Only active pairing members (or the default admin root) can host binary placements
        $user = User::findByUsername($username);
        if (!$user) {
            json_response(['valid' => false, 'message' => 'User not found.']);
        }
        if (!User::isValidBinaryUpline((int)$user['id'])) {
            json_response(['valid' => false, 'message' => 'That member is not an active binary upline.']);
        }

        $leftFree  = User::isSlotFree((int)$user['id'], 'left');
        $rightFree = User::isSlotFree((int)$user['id'], 'right');

        json_response([
            'valid'      => true,
            'username'   => $user['username'],
            'left_free'  => $leftFree,
            'right_free' => $rightFree,
            'slot_ok'    => $position ? ($position === 'left' ? $leftFree : $rightFree) : null,
            'message'    => "Found @{$user['username']} — Left: " . ($leftFree ? '✓ Free' : '✗ Taken') . ', Right: ' . ($rightFree ? '✓ Free' : '✗ Taken'),
        ]);
    }

    /** AJAX: suggest the next network-wide binary slot (preview only — the
     *  authoritative run always happens server-side at do_register/do_activate). */
    public function ajaxAutoSelectUpline(): void
    {
        $excludeId = max(0, (int)($_GET['exclude_id'] ?? 0));
        $slot      = User::findNextBinarySlotNetworkWide($excludeId);

        if (!$slot) {
            json_response([
                'valid'   => false,
                'message' => 'No binary position is currently available. The member will register as a binary network root.',
            ]);
        }

        json_response([
            'valid'           => true,
            'upline_username' => $slot['upline_username'],
            'position'        => $slot['position'],
        ]);
    }

    // ── Logout ────────────────────────────────────────────────────────────────

    public function logout(): void
    {
        // Impersonated member tabs destroy only their own URL-token session and
        // bounce back to the S-Login screen (the superadmin's cookie session is
        // untouched, so that tab can log into another member immediately).
        $isImp    = !empty($_SESSION['imp_session']);
        $impLogId = (int) ($_SESSION['imp_log_id'] ?? 0);

        if ($isImp) {
            ImpLog::mark($impLogId, 'logged_out');
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
            redirect('/?page=slogin');
        }

        Auth::logout();
    }
}
