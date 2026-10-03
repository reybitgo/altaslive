<?php

/**
 * @file   controllers/AdminController.php
 * @brief  Admin controller for handling admin-specific actions
 */
class AdminController
{
    public function dashboard(): void
    {
        Auth::guard('admin');
        $memberCounts  = User::counts();
        $codeStat      = Code::stats();
        $pendingPayout = Payout::pendingTotal();
        $totalPaid     = Payout::totalPaid();
        $pendingList   = Payout::all(1, 'pending')['data'];

        // v2: Cap & DFI stat cards
        $pdo = db();
        $v2Stats = [
            'capped'        => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND cap_status='capped'")->fetchColumn(),
            'perminact'     => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND cap_status='perminact'")->fetchColumn(),
            'react_revenue' => (float)$pdo->query("SELECT COALESCE(SUM(amount_paid),0) FROM reactivations WHERE status='completed'")->fetchColumn(),
            'dfi_today'     => (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM daily_fixed_income_log WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
            'pending_repeat_purchases' => (int)$pdo->query("SELECT COUNT(*) FROM repeat_purchase_orders WHERE status='pending'")->fetchColumn(),
        ];

        require 'views/admin/dashboard.php';
    }

    public function users(): void
    {
        Auth::guard('admin');
        $page     = max(1, (int)($_GET['pg'] ?? 1));
        $perPage  = per_page();
        $search   = trim($_GET['q']      ?? '');
        $status   = $_GET['status']      ?? '';
        $pkgId    = (int)($_GET['pkg']   ?? 0);
        $packages = Package::all();
        $result   = User::allMembers($page, $search, $status, $pkgId, $perPage);
        require 'views/admin/users.php';
    }

    public function viewUser(): void
    {
        Auth::guard('admin');
        $id   = (int)($_GET['id'] ?? 0);
        $user = User::find($id);
        if (!$user) {
            flash('error', 'User not found.');
            redirect('/?page=admin_users');
        }

        $summary  = Commission::summary($id);
        $perPage  = per_page();
        $payouts  = Payout::forUser($id, max(1, (int)($_GET['pg_payout'] ?? 1)), $perPage);
        $commHist = Commission::history($id, max(1, (int)($_GET['pg_comm'] ?? 1)), $perPage);
        $ledger   = Ewallet::ledger($id, max(1, (int)($_GET['pg_ledger'] ?? 1)), $perPage);
        $pairingStatus = User::todayPairingStatus($id);
        $cdStatus = CdStatus::getActive($id);
        $cdHistory = CdStatus::history($id);

        // v2: Cap & DFI data for admin user view tab
        $capStatus = User::getCapStatus($id);
        $dfiStatus = DailyFixedIncome::getMemberDFIStatus($id);
        $reactivationHistory = Reactivation::getReactivationHistory($id, max(1, (int)($_GET['pg_react'] ?? 1)), $perPage);
        $capBlocked = Commission::capBlockedHistory($id, max(1, (int)($_GET['pg_cap'] ?? 1)), $perPage);

        // Transfer history for e-wallet tab
        $transferHistory = Ewallet::userTransferHistory($id, max(1, (int)($_GET['pg_transfer'] ?? 1)), $perPage);

        require 'views/admin/user_view.php';
    }

    public function toggleUser(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $id   = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash('error', 'Invalid user ID.');
            redirect('/?page=admin_users');
            return;
        }

        $user = User::find($id);
        if (!$user || $user['role'] === 'admin') {
            flash('error', 'Invalid user or cannot modify administrator account.');
            redirect('/?page=admin_users');
            return;
        }

        $newStatus = $user['status'] === 'active' ? 'suspended' : 'active';

        $pdo = db();
        $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
        $success = $stmt->execute([$newStatus, $id]);

        if ($success) {
            $action = ($newStatus === 'active') ? 'activated' : 'suspended';
            flash('success', "User @{$user['username']} has been {$action} successfully.");
        } else {
            flash('error', 'Failed to update user status. Please try again.');
        }

        // Return JSON only for AJAX requests
        if (
            isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        ) {
            json_response(['ok' => $success, 'status' => $newStatus]);
        }

        // ── Smart Redirect Logic ─────────────────────────────────────
        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        // If came from user view page → stay on that user's view
        if (strpos($referer, 'admin_user_view') !== false && strpos($referer, "id={$id}") !== false) {
            redirect("/?page=admin_user_view&id={$id}");
        }

        // Otherwise (from members list or anywhere else) → go back to members list
        redirect('/?page=admin_users');
    }

    /**
     * Called from payout.php JS when live TRC20 gas fee differs from DB value.
     * Updates the setting only if the rounded value actually changed (max 2 decimals).
     * Accessible to logged-in members so the payout page can call it without admin session.
     */
    public function updateUsdtGas(): void
    {
        // Require JSON POST
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true);
        $fee  = isset($body['fee']) ? round((float)$body['fee'], 4) : null;

        if ($fee === null || $fee <= 0 || $fee > 50) {
            json_response(['ok' => false, 'error' => 'Invalid fee value.'], 400);
        }

        $current = round((float)setting('usdt_trc20_gas_fee', '2.50'), 4);

        // Only write if value actually changed (avoid unnecessary DB writes)
        if (abs($fee - $current) < 0.0001) {
            json_response(['ok' => true, 'updated' => false, 'fee' => $fee]);
        }

        db()->prepare("UPDATE settings SET value = ? WHERE key_name = 'usdt_trc20_gas_fee'")
            ->execute([(string)$fee]);

        json_response(['ok' => true, 'updated' => true, 'fee' => $fee, 'previous' => $current]);
    }

    /**
     * Called from payout.php JS when live BEP20 gas fee differs from DB value.
     * Accessible to logged-in members so the payout page can call it without admin session.
     */
    public function updateUsdtBep20Gas(): void
    {
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true);
        $fee  = isset($body['fee']) ? round((float)$body['fee'], 4) : null;

        if ($fee === null || $fee <= 0 || $fee > 50) {
            json_response(['ok' => false, 'error' => 'Invalid fee value.'], 400);
        }

        $current = round((float)setting('usdt_bep20_gas_fee', '0.05'), 4);

        if (abs($fee - $current) < 0.0001) {
            json_response(['ok' => true, 'updated' => false, 'fee' => $fee]);
        }

        db()->prepare("UPDATE settings SET value = ? WHERE key_name = 'usdt_bep20_gas_fee'")
            ->execute([(string)$fee]);

        json_response(['ok' => true, 'updated' => true, 'fee' => $fee, 'previous' => $current]);
    }

    public function packages(): void
    {
        Auth::guard('admin');
        $page     = max(1, (int)($_GET['pg'] ?? 1));
        $perPage  = per_page();
        $packages = Package::allPaginated($page, $perPage);
        $editPkg  = null;
        if (isset($_GET['edit'])) {
            $editPkg = Package::withLevels((int)$_GET['edit']);
        }
        require 'views/admin/packages.php';
    }

    public function savePackage(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $id   = (int)($_POST['package_id'] ?? 0);
        $data = [
            'name'              => trim($_POST['name']              ?? ''),
            'entry_fee'         => (float)($_POST['entry_fee']      ?? 0),
            'package_pv_rate'   => (float)($_POST['package_pv_rate'] ?? 10.00),
            'binary_pv_pct'     => (float)($_POST['binary_pv_pct']   ?? 20.00),
            'daily_pair_pv_cap' => (float)($_POST['daily_pair_pv_cap'] ?? 0),
            'direct_ref_pv_pct' => (float)($_POST['direct_ref_pv_pct'] ?? 0),
            'status'            => $_POST['status'] ?? 'active',
            'indirect_levels'  => [],
            // NEW v2 fields
            'lifetime_cap_multiplier'  => (float)($_POST['lifetime_cap_multiplier']  ?? 3.00),
            'reactivation_fee'         => (float)($_POST['reactivation_fee']         ?? 0),
            'reactivation_window_days' => (int)($_POST['reactivation_window_days']    ?? 15),
            'daily_fixed_income'       => (float)($_POST['daily_fixed_income']       ?? 0),
            'daily_fixed_income_days'  => (int)($_POST['daily_fixed_income_days']    ?? 90),
            'dfi_pv_pct'               => (float)($_POST['dfi_pv_pct']               ?? 0),
            'personal_pv_requirement'  => (float)($_POST['personal_pv_requirement']  ?? 0.00),
        ];

        for ($lvl = 1; $lvl <= 10; $lvl++) {
            $data['indirect_levels'][$lvl] = (float)($_POST["indirect_{$lvl}"] ?? 0);
        }

        // When binary is disabled, the binary/cap inputs are not rendered.
        // Preserve existing values on edit so they are not silently zeroed.
        if ($id && !isset($_POST['binary_pv_pct'])) {
            $existing = Package::find($id);
            if ($existing) {
                $data['binary_pv_pct']     = (float)$existing['binary_pv_pct'];
                $data['daily_pair_pv_cap'] = (float)$existing['daily_pair_pv_cap'];
            }
        }

        // When indirect referrals are disabled, the 10-level inputs are not rendered.
        // Preserve existing percentages on edit.
        if ($id && !isset($_POST['indirect_1'])) {
            $existingLevels = Package::getIndirectLevels($id);
            if (!empty($existingLevels)) {
                $data['indirect_levels'] = $existingLevels;
            }
        }

        // Build back-URL so validation errors return to the correct form (edit or new)
        $backUrl = $id
            ? '/?page=admin_packages&edit=' . $id
            : '/?page=admin_packages';

        if (!$data['name'] || $data['entry_fee'] <= 0) {
            flash('error', 'Package name and entry fee are required.');
            redirect($backUrl);
        }
        if ($data['package_pv_rate'] < 0) {
            flash('error', 'Package PV cannot be negative.');
            redirect($backUrl);
        }
        if ($data['binary_pv_pct'] < 0 || $data['binary_pv_pct'] > 1000) {
            flash('error', 'Binary PV percentage must be between 0 and 1000.');
            redirect($backUrl);
        }
        if ($data['daily_pair_pv_cap'] < 0) {
            flash('error', 'Daily pair PV cap cannot be negative.');
            redirect($backUrl);
        }
        if ($data['direct_ref_pv_pct'] < 0 || $data['direct_ref_pv_pct'] > 100) {
            flash('error', 'Direct referral percentage must be between 0 and 100.');
            redirect($backUrl);
        }
        foreach ($data['indirect_levels'] as $lvl => $pct) {
            if ($pct < 0 || $pct > 100) {
                flash('error', "Indirect level {$lvl} percentage must be between 0 and 100.");
                redirect($backUrl);
            }
        }
        // Validate v2 fields
        if ($data['lifetime_cap_multiplier'] < 1) {
            flash('error', 'Lifetime cap multiplier must be at least 1.0.');
            redirect($backUrl);
        }
        if ($data['reactivation_window_days'] < 1) {
            flash('error', 'Reactivation window must be at least 1 day.');
            redirect($backUrl);
        }
        if ($data['daily_fixed_income'] < 0) {
            flash('error', 'Daily fixed income cannot be negative.');
            redirect($backUrl);
        }
        if ($data['dfi_pv_pct'] < 0 || $data['dfi_pv_pct'] > 100) {
            flash('error', 'DFI PV percentage must be between 0 and 100.');
            redirect($backUrl);
        }
        if ($data['daily_fixed_income_days'] < 0) {
            flash('error', 'Max DFI days cannot be negative.');
            redirect($backUrl);
        }
        if ($data['personal_pv_requirement'] < 0) {
            flash('error', 'Personal PV requirement cannot be negative.');
            redirect($backUrl);
        }

        Package::save($data, $id ?: null);
        flash('success', $id ? 'Package updated with v2 settings.' : 'Package created with v2 settings.');
        redirect('/?page=admin_packages');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  PRODUCTS
    // ══════════════════════════════════════════════════════════════════════════

    public function products(): void
    {
        Auth::guard('admin');
        $page        = max(1, (int)($_GET['pg'] ?? 1));
        $perPage     = per_page();
        $products    = Product::allPaginated($page, $perPage);
        $editProduct = null;
        if (isset($_GET['edit'])) {
            $editProduct = Product::withUnilevelLevels((int)$_GET['edit']);
        }
        require 'views/admin/products.php';
    }

    public function saveProduct(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $id       = (int)($_POST['product_id'] ?? 0);
        $backUrl  = $id ? '/?page=admin_products&edit=' . $id : '/?page=admin_products';
        $existing = $id ? Product::find($id) : null;

        $data = [
            'name'             => trim($_POST['name'] ?? ''),
            'price'            => (float)($_POST['price'] ?? 0),
            'product_pv'       => (float)($_POST['product_pv'] ?? 0),
            'pv_value'         => (float)($_POST['pv_value'] ?? 100.00),
            'stock'            => max(0, (int)($_POST['stock'] ?? 0)),
            'short_description'=> trim($_POST['short_description'] ?? ''),
            'description'      => trim($_POST['description'] ?? ''),
            'status'           => $_POST['status'] ?? 'active',
            'image_url'        => $existing['image_url'] ?? null,
        ];

        $data['unilevel_levels'] = [];
        for ($lvl = 1; $lvl <= 10; $lvl++) {
            $data['unilevel_levels'][$lvl] = (float)($_POST["unilevel_{$lvl}"] ?? 0);
        }

        // Preserve existing levels when the section was not rendered (toggle disabled)
        if ($id && !isset($_POST['unilevel_1'])) {
            $existingLevels = Product::getUnilevelLevels($id);
            if (!empty($existingLevels)) {
                $data['unilevel_levels'] = $existingLevels;
            }
        }

        if (!$data['name'] || $data['price'] <= 0) {
            flash('error', 'Product name and price are required.');
            redirect($backUrl);
        }
        if ($data['product_pv'] < 0) {
            flash('error', 'Product PV cannot be negative.');
            redirect($backUrl);
        }
        if ($data['pv_value'] < 0 || $data['pv_value'] > 100) {
            flash('error', 'PV Value (%) must be between 0 and 100.');
            redirect($backUrl);
        }
        foreach ($data['unilevel_levels'] as $lvl => $pct) {
            if ($pct < 0 || $pct > 100) {
                flash('error', "Unilevel Level {$lvl} must be between 0 and 100.");
                redirect($backUrl);
            }
        }

        // Handle product image upload/removal
        $removeImage = !empty($_POST['remove_image']);

        if (!empty($_FILES['image']['tmp_name'])) {
            try {
                $uploaded = upload_image(
                    $_FILES['image'],
                    'products',
                    'product_' . ($id ?: 'new'),
                    $removeImage ? null : ($existing['image_url'] ?? null)
                );
                if ($uploaded !== null) {
                    $data['image_url'] = $uploaded;
                }
            } catch (Throwable $e) {
                flash('error', $e->getMessage());
                redirect($backUrl);
            }
        } elseif ($removeImage) {
            delete_uploaded_file($existing['image_url'] ?? null);
            $data['image_url'] = null;
        }

        Product::save($data, $id ?: null);
        flash('success', $id ? 'Product updated.' : 'Product created.');
        redirect('/?page=admin_products');
    }

    public function deleteProduct(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash('error', 'Invalid product ID.');
            redirect('/?page=admin_products');
        }

        if (Product::delete($id)) {
            flash('success', 'Product deleted.');
        } else {
            flash('error', 'Cannot delete product: it has existing repeat-purchase records.');
        }
        redirect('/?page=admin_products');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  REPEAT PURCHASE ORDERS
    // ══════════════════════════════════════════════════════════════════════════

    public function repeatPurchaseOrders(): void
    {
        Auth::guard('admin');

        $page    = max(1, (int)($_GET['pg'] ?? 1));
        $status  = $_GET['status'] ?? 'pending';
        $perPage = per_page();

        $result = match ($status) {
            'paid'     => RepeatPurchaseOrder::paid($page, $perPage),
            'approved' => RepeatPurchaseOrder::approved($page, $perPage),
            'all'      => RepeatPurchaseOrder::all($page, $perPage),
            default    => RepeatPurchaseOrder::pending($page, $perPage),
        };

        $pageTitle = 'Repeat Purchase Orders';
        require 'views/admin/repeat_purchases.php';
    }

    public function markRepeatOrderPaid(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $orderId = (int)($_POST['id'] ?? 0);
        try {
            RepeatPurchaseOrder::markPaid($orderId, Auth::id());
            flash('success', 'Order marked as paid.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/?page=admin_repeat_purchases&status=paid');
    }

    public function approveRepeatOrder(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $orderId = (int)($_POST['id'] ?? 0);
        try {
            RepeatPurchaseOrder::approve($orderId, Auth::id());
            flash('success', 'Order approved and PV distributed.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/?page=admin_repeat_purchases');
    }

    public function rejectRepeatOrder(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $orderId = (int)($_POST['id'] ?? 0);
        try {
            RepeatPurchaseOrder::reject($orderId, Auth::id());
            flash('success', 'Order rejected.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/?page=admin_repeat_purchases');
    }

    // ── Registration Codes ────────────────────────────────────────────────────

    public function codes(): void
    {
        Auth::guard('admin');
        $page     = max(1, (int)($_GET['pg']  ?? 1));
        $perPage  = per_page();
        $status   = $_GET['status']            ?? '';
        $pkgId    = (int)($_GET['pkg']         ?? 0);
        $packages = Package::all(true);
        $codes    = Code::all($page, $status, $pkgId, $perPage);
        $stats    = Code::stats();
        require 'views/admin/codes.php';
    }

    public function generateCodes(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $pkgId    = (int)($_POST['package_id'] ?? 0);
        $qty      = min(500, max(1, (int)($_POST['quantity'] ?? 1)));
        $price    = (float)($_POST['price']    ?? 0);
        $expires  = trim($_POST['expires_at']  ?? '');
        $isCd     = !empty($_POST['is_cd']);

        if (!$pkgId || $price <= 0) {
            flash('error', 'Package and price are required.');
            redirect('/?page=admin_codes');
        }

        $generated = Code::generate($pkgId, $qty, $price, $expires ?: null, Auth::id(), $isCd);
        flash('success', count($generated) . ' code(s) generated' . ($isCd ? ' (CD)' : '') . ' successfully.');
        redirect('/?page=admin_codes');
    }

    public function exportCodes(): void
    {
        Auth::guard('admin');
        $status = $_GET['status'] ?? '';
        $pkgId  = (int)($_GET['pkg'] ?? 0);
        Code::exportCSV($status, $pkgId);
    }

    // ── Payouts ───────────────────────────────────────────────────────────────

    public function payouts(): void
    {
        Auth::guard('admin');
        $page    = max(1, (int)($_GET['pg']     ?? 1));
        $perPage = per_page();
        $status  = $_GET['status']               ?? 'pending';
        $result  = Payout::all($page, $status, $perPage);
        require 'views/admin/payouts.php';
    }

    public function payoutAction(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $action   = $_POST['action']    ?? '';
        $id       = (int)($_POST['id']  ?? 0);
        $note     = trim($_POST['note'] ?? '');
        $adminId  = Auth::id();

        switch ($action) {
            case 'approve':
                $ok = Payout::approve($id, $adminId);
                flash($ok ? 'success' : 'error', $ok ? 'Payout approved.' : 'Could not approve.');
                break;
            case 'reject':
                $ok = Payout::reject($id, $adminId, $note);
                flash($ok ? 'success' : 'error', $ok ? 'Payout rejected.' : 'Could not reject.');
                break;
            case 'complete':
                $result = Payout::complete($id, $adminId, $note);
                flash(
                    $result['ok'] ? 'success' : 'error',
                    $result['ok'] ? 'Payout marked as completed. E-wallet deducted.' : $result['error']
                );
                break;
            default:
                flash('error', 'Unknown action.');
        }
        redirect('/?page=admin_payouts');
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    public function settings(): void
    {
        Auth::guard('admin');
        require 'views/admin/settings.php';
    }

    public function saveSettings(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $group = $_POST['group'] ?? '';

        $groupKeys = [
            'basics'       => ['site_name', 'site_tagline', 'contact_email', 'min_payout'],
            'maint'        => ['maintenance_mode', 'maintenance_bypass_token', 'seat_limit'],
            'comp_plan'    => ['binary_enabled', 'binary_repeat_enabled', 'indirect_referral_enabled', 'unilevel_product_enabled', 'default_cap_multiplier', 'pv_per_peso_rate', 'dfi_enabled'],
            'royalty'      => ['royalty_enabled', 'royalty_pool_rate', 'royalty_min_pool', 'royalty_supervisor_rate', 'royalty_manager_rate', 'royalty_director_rate', 'royalty_chairman_rate', 'royalty_qa_directs', 'royalty_qa_personal_pv', 'royalty_qa_group_pv', 'royalty_spv_directs', 'royalty_spv_qa_legs', 'royalty_mgr_sup_legs', 'royalty_dir_mgr_legs', 'royalty_chm_dir_legs'],
            'payments'     => ['reactivation_ewallet_enabled', 'reactivation_external_enabled', 'gcash_number', 'maya_number', 'usdt_trc20_address', 'usdt_bep20_address'],
            'ewallet'      => ['ewallet_transfer_fee', 'ewallet_min_transfer', 'ewallet_transfer_daily_limit', 'ewallet_transfer_weekly_limit'],
            'payouts'      => ['gcash_enabled', 'maya_enabled', 'service_fee_gcash', 'service_fee_maya', 'service_fee_usdt_trc20', 'service_fee_usdt_bep20', 'usdt_trc20_gas_fee', 'usdt_bep20_gas_fee'],
        ];

        if (!isset($groupKeys[$group])) {
            flash('error', 'Invalid settings group.');
            redirect('/?page=admin_settings');
            return;
        }

        $allowed = $groupKeys[$group];

        // Validate rank rates sum to 100 (royalty group only)
        if ($group === 'royalty') {
            $rankRateKeys = ['royalty_supervisor_rate', 'royalty_manager_rate', 'royalty_director_rate', 'royalty_chairman_rate'];
            $sum = 0;
            foreach ($rankRateKeys as $key) {
                $sum += (float) ($_POST[$key] ?? 0);
            }
            if (abs($sum - 100) > 0.01) {
                flash('error', "Rank rates must sum to 100 (current: {$sum}). No settings saved.");
                redirect('/?page=admin_settings#tabPane-royalty');
                return;
            }
        }

        $pdo = db();
        $st  = $pdo->prepare("INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");

        $checkboxKeys = ['gcash_enabled', 'maya_enabled', 'dfi_enabled', 'reactivation_ewallet_enabled', 'reactivation_external_enabled', 'indirect_referral_enabled', 'unilevel_product_enabled', 'binary_enabled', 'binary_repeat_enabled', 'royalty_enabled'];

        foreach ($allowed as $key) {
            if (in_array($key, $checkboxKeys, true)) {
                $value = isset($_POST[$key]) && $_POST[$key] === '1' ? '1' : '0';

                if ($key === 'binary_enabled') {
                    $currentValue = (string) db()->query("SELECT value FROM settings WHERE key_name = 'binary_enabled'")->fetchColumn();
                    if ($value !== $currentValue) {
                        $memberCount = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn();
                        if ($memberCount > 0) {
                            flash('error', 'Cannot change binary pairing: ' . $memberCount . ' member(s) already exist in the system. Run reset.php to clear all members first, then toggle binary before anyone registers.');
                            redirect('/?page=admin_settings#tabPane-comp_plan');
                        }
                    }
                }

                if ($key === 'indirect_referral_enabled') {
                    $currentValue = (string) db()->query("SELECT value FROM settings WHERE key_name = 'indirect_referral_enabled'")->fetchColumn();
                    if ($value !== $currentValue) {
                        $memberCount = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn();
                        if ($memberCount > 0) {
                            flash('error', 'Cannot change indirect referral: ' . $memberCount . ' member(s) already exist in the system. Run reset.php to clear all members first, then toggle indirect referral before anyone registers.');
                            redirect('/?page=admin_settings#tabPane-comp_plan');
                        }
                    }
                }

                if ($key === 'unilevel_product_enabled') {
                    $currentValue = (string) db()->query("SELECT value FROM settings WHERE key_name = 'unilevel_product_enabled'")->fetchColumn();
                    if ($value !== $currentValue) {
                        $memberCount = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn();
                        if ($memberCount > 0 && $value === '0') {
                            flash('warning', 'Cannot disable Unilevel Product Bonus — ' . $memberCount . ' member(s) already exist. Existing bonuses already paid remain; no new bonuses will be processed.');
                        }
                    }
                }

                $st->execute([$key, $value]);
            } elseif (isset($_POST[$key])) {
                $st->execute([$key, trim($_POST[$key])]);
            }
        }

        flash('success', 'Settings saved.');
        redirect('/?page=admin_settings#tabPane-' . $group);
    }

    public function manualReset(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $affected = db()->exec("UPDATE users SET paired_pv_today = 0 WHERE role = 'member'");
        db()->exec("UPDATE users SET pairs_paid_today = 0 WHERE role = 'member'");
        db()->prepare("UPDATE settings SET value = ? WHERE key_name = 'last_reset'")
            ->execute([date('Y-m-d H:i:s')]);

        $msg = "Daily paired-PV counter reset for {$affected} member(s).";

        // v3: Optional DFI trigger
        if (setting('dfi_enabled', '1') === '1' && isset($_POST['trigger_dfi']) && $_POST['trigger_dfi'] === '1') {
            $dfiResult = DailyFixedIncome::processDailyPayout();
            if (($dfiResult['reason'] ?? '') === 'disabled') {
                $msg .= ' DFI is currently disabled.';
            } else {
                $msg .= " DFI: ₱" . number_format($dfiResult['paid'], 2)
                     . " paid to {$dfiResult['processed']} member(s),"
                     . " {$dfiResult['skipped']} skipped.";
            }
        }

        flash('success', $msg);
        redirect('/?page=admin_settings');
    }

    public function capMonitor(): void
    {
        Auth::guard('admin');
        $pdo = db();

        $stats = [
            'active'    => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND cap_status='active'")->fetchColumn(),
            'capped'    => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND cap_status='capped'")->fetchColumn(),
            'perminact' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND cap_status='perminact'")->fetchColumn(),
        ];

        $page    = max(1, (int)($_GET['pg'] ?? 1));
        $perPage = per_page();
        $status  = $_GET['status'] ?? '';

        $where = "u.role='member'";
        $params = [];
        if ($status && in_array($status, ['active', 'capped', 'perminact'])) {
            $where .= " AND u.cap_status = ?";
            $params[] = $status;
        }

        $result = paginate(
            "SELECT u.*, p.name AS package_name, p.entry_fee, p.lifetime_cap_multiplier,
                    (p.entry_fee * p.lifetime_cap_multiplier) AS lifetime_cap
             FROM users u
             LEFT JOIN packages p ON p.id = u.package_id
             WHERE {$where}
             ORDER BY u.cap_status DESC, u.lifetime_earned DESC",
            $params,
            $page,
            $perPage
        );

        require 'views/admin/cap_monitor.php';
    }

    public function dfiAdmin(): void
    {
        Auth::guard('admin');
        if (setting('dfi_enabled', '1') !== '1') {
            flash('info', 'Daily Fixed Income is disabled. Enable it in Compensation Plan settings.');
            redirect('/?page=admin_settings#tabPane-comp_plan');
        }
        $pdo = db();

        $todayDfi = (float)$pdo->query("
            SELECT COALESCE(SUM(amount), 0) 
            FROM daily_fixed_income_log 
            WHERE DATE(created_at) = CURDATE()
        ")->fetchColumn();

        $totalDfi = (float)$pdo->query("
            SELECT COALESCE(SUM(amount), 0) 
            FROM daily_fixed_income_log
        ")->fetchColumn();

        $totalMembers = (int)$pdo->query("
            SELECT COUNT(DISTINCT user_id) 
            FROM daily_fixed_income_log
        ")->fetchColumn();

        require 'views/admin/dfi_admin.php';
    }

    public function toggleVipBypass(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $id     = (int)($_POST['id'] ?? 0);
        $bypass = (int)($_POST['bypass'] ?? 0);

        if ($id <= 0) {
            flash('error', 'Invalid user ID.');
            redirect('/?page=admin_users');
            return;
        }

        $user = User::find($id);
        if (!$user || $user['role'] !== 'member') {
            flash('error', 'Member not found.');
            redirect('/?page=admin_users');
            return;
        }

        // Only active members can receive VIP
        if ($bypass && $user['cap_status'] !== 'active') {
            flash('error', 'Only active members can be granted VIP privilege.');
            redirect('/?page=admin_user_view&id=' . $id);
            return;
        }

        db()->prepare("UPDATE users SET capping_bypass = ? WHERE id = ?")
            ->execute([$bypass ? 1 : 0, $id]);

        flash('success', $bypass ? 'VIP privilege granted.' : 'VIP privilege removed.');
        redirect('/?page=admin_user_view&id=' . $id);
    }

    public function toggleDailyCapBypass(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $id     = (int)($_POST['id'] ?? 0);
        $bypass = (int)($_POST['bypass'] ?? 0);

        if ($id <= 0) {
            flash('error', 'Invalid user ID.');
            redirect('/?page=admin_users');
            return;
        }

        db()->prepare("UPDATE users SET daily_cap_bypass = ? WHERE id = ? AND role = 'member'")
            ->execute([$bypass ? 1 : 0, $id]);

        flash('success', $bypass ? 'Daily cap bypass enabled.' : 'Daily cap bypass disabled.');
        redirect('/?page=admin_user_view&id=' . $id);
    }

    public function changePassword(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $userId      = (int)($_POST['user_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPw   = $_POST['new_password_confirm'] ?? '';

        if ($userId <= 0) {
            flash('error', 'Invalid member ID.');
            redirect('/?page=admin_users');
        }

        $user = User::find($userId);
        if (!$user || $user['role'] !== 'member') {
            flash('error', 'Member not found.');
            redirect("/?page=admin_user_view&id={$userId}");
        }

        if (strlen($newPassword) < 8) {
            flash('error', 'New password must be at least 8 characters.');
            redirect("/?page=admin_user_view&id={$userId}");
        }

        if ($newPassword !== $confirmPw) {
            flash('error', 'New passwords do not match.');
            redirect("/?page=admin_user_view&id={$userId}");
        }

        $updated = User::updatePassword($userId, $newPassword);

        if ($updated) {
            flash('success', "Password for @{$user['username']} has been changed successfully.");
        } else {
            flash('error', 'Failed to update password. Please try again.');
        }

        redirect("/?page=admin_user_view&id={$userId}");
    }

    public function reactivations(): void
    {
        Auth::guard('admin');
        $page    = max(1, (int)($_GET['pg'] ?? 1));
        $perPage = per_page();
        $status  = $_GET['status'] ?? '';
        $result  = Reactivation::all($page, $status, $perPage);

        $totalRevenue = Reactivation::completedTotal();
        $pendingTotal = Reactivation::pendingTotal();

        // Fetch admin payment details for reactivation display
        $pdo = db();
        $adminPayment = [];
        foreach (['gcash_number','maya_number','usdt_trc20_address','usdt_bep20_address'] as $k) {
            $adminPayment[$k] = $pdo->query("SELECT value FROM settings WHERE key_name='{$k}'")->fetchColumn() ?: '';
        }

        require 'views/admin/reactivations.php';
    }

    public function reactivationAction(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $action   = $_POST['action']    ?? '';
        $id       = (int)($_POST['id']  ?? 0);
        $note     = trim($_POST['note'] ?? '');
        $adminId  = Auth::id();

        switch ($action) {
            case 'confirm':
                $result = Reactivation::confirm($id, $adminId, $note);
                flash(
                    $result['ok'] ? 'success' : 'error',
                    $result['ok'] ? $result['message'] : $result['error']
                );
                break;
            case 'reject':
                $ok = Reactivation::reject($id, $adminId, $note);
                flash($ok ? 'success' : 'error', $ok ? 'Reactivation rejected.' : 'Could not reject.');
                break;
            default:
                flash('error', 'Unknown action.');
        }
        redirect('/?page=admin_reactivations');
    }

    // ── E-Wallet Top-Up & Monitor ──────────────────────────────────────────

    public function ewalletTopUp(): void
    {
        Auth::guard('admin');
        require 'views/admin/ewallet_topup.php';
    }

    public function doEwalletTopUp(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $adminId = Auth::id();
        $recipientUsername = trim($_POST['recipient'] ?? '');
        $amount = (float) ($_POST['amount'] ?? 0);
        $note = trim($_POST['note'] ?? '');

        $recipient = User::findByUsername($recipientUsername);
        if (!$recipient) {
            flash('error', 'Recipient not found.');
            redirect('/?page=admin_ewallet_topup');
            return;
        }

        $result = Ewallet::adminTopUp($adminId, $recipient['id'], $amount, $note);

        flash($result['ok'] ? 'success' : 'error', $result['error'] ?? 'Top-up completed successfully.');
        redirect('/?page=admin_ewallet_topup');
    }

    public function ewalletMonitor(): void
    {
        Auth::guard('admin');
        $pdo = db();

        $tab     = $_GET['tab'] ?? 'transfers';
        $page    = max(1, (int)($_GET['pg'] ?? 1));
        $perPage = per_page();

        $transfers = Ewallet::transferHistory($tab === 'transfers' ? $page : 1, $perPage);
        $topups    = Ewallet::topUpHistory($tab === 'topups' ? $page : 1, $perPage);
        $fees      = Ewallet::feeLedger($tab === 'fees' ? $page : 1, $perPage);

        $stats = [
            'total_transfers' => (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM ewallet_transfers WHERE status='completed'")->fetchColumn(),
            'total_fees'      => (float) $pdo->query("SELECT COALESCE(SUM(fee),0) FROM ewallet_transfers WHERE status='completed'")->fetchColumn(),
            'total_topups'    => (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM ewallet_admin_topups")->fetchColumn(),
            'transfer_count'  => (int)   $pdo->query("SELECT COUNT(*) FROM ewallet_transfers WHERE status='completed'")->fetchColumn(),
            'topup_count'     => (int)   $pdo->query("SELECT COUNT(*) FROM ewallet_admin_topups")->fetchColumn(),
            'system_withdrawable' => (float) $pdo->query("SELECT COALESCE(SUM(withdrawable_balance),0) FROM users WHERE role='member'")->fetchColumn(),
            'system_non_withdrawable' => (float) $pdo->query("SELECT COALESCE(SUM(ewallet_balance - withdrawable_balance),0) FROM users WHERE role='member'")->fetchColumn(),
        ];

        require 'views/admin/ewallet_monitor.php';
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  COMMISSION-DEDUCT (CD) ADMIN ACTIONS
    // ══════════════════════════════════════════════════════════════════════════

    public function assignCd(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $userId = (int)($_POST['user_id'] ?? 0);
        $target = (float)($_POST['target_amount'] ?? 0);

        if ($userId <= 0 || $target <= 0) {
            flash('error', 'Invalid user or target amount.');
            redirect('/?page=admin_user_view&id=' . $userId);
            return;
        }

        try {
            CdStatus::assign($userId, $target, Auth::id());
            flash('success', 'Commission-Deduct status assigned successfully.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }

        redirect('/?page=admin_user_view&id=' . $userId);
    }

    public function completeCd(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $userId = (int)($_POST['user_id'] ?? 0);
        $cd = CdStatus::getActive($userId);

        if (!$cd) {
            flash('error', 'No active CD found for this user.');
            redirect('/?page=admin_user_view&id=' . $userId);
            return;
        }

        CdStatus::complete((int)$cd['id'], $userId);
        flash('success', 'CD status marked as completed.');
        redirect('/?page=admin_user_view&id=' . $userId);
    }

    public function cancelCd(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $userId = (int)($_POST['user_id'] ?? 0);
        $cd = CdStatus::getActive($userId);

        if (!$cd) {
            flash('error', 'No active CD found for this user.');
            redirect('/?page=admin_user_view&id=' . $userId);
            return;
        }

        CdStatus::cancel((int)$cd['id'], $userId, trim($_POST['reason'] ?? ''));
        flash('success', 'CD status cancelled. Filled amount is forfeited.');
        redirect('/?page=admin_user_view&id=' . $userId);
    }

    public function editCdTarget(): void
    {
        Auth::guard('admin');
        csrf_verify();

        $userId = (int)($_POST['user_id'] ?? 0);
        $newTarget = (float)($_POST['target_amount'] ?? 0);

        $cd = CdStatus::getActive($userId);

        if (!$cd) {
            flash('error', 'No active CD found for this user.');
            redirect('/?page=admin_user_view&id=' . $userId);
            return;
        }

        if ($newTarget <= 0) {
            flash('error', 'Target amount must be greater than zero.');
            redirect('/?page=admin_user_view&id=' . $userId);
            return;
        }

        if ($newTarget < (float)$cd['filled_amount']) {
            flash('error', 'New target cannot be less than the already filled amount (' . fmt_money((float)$cd['filled_amount']) . ').');
            redirect('/?page=admin_user_view&id=' . $userId);
            return;
        }

        CdStatus::updateTarget($userId, $newTarget);
        flash('success', 'CD target updated to ' . fmt_money($newTarget) . '.');
        redirect('/?page=admin_user_view&id=' . $userId);
    }
}
