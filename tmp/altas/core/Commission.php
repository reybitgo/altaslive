<?php

/**
 * @file   core/Commission.php
 * @brief  Commission management class (v2 with Lifetime Income Capping)
 */
class Commission
{
    // ══════════════════════════════════════════════════════════════════════════
    //  BINARY PLACEMENT ENGINE (PV-based)
    //  Called immediately after a new member is inserted or activated.
    //  Walks the binary tree upward, adding the new member's package PV to each
    //  ancestor's leg PV and firing pairing bonuses based on matched PV.
    //  Capped/perminact members are SKIPPED — they earn no pairs themselves,
    //      but active ancestors above them continue to earn normally.
    // ══════════════════════════════════════════════════════════════════════════

    public static function processBinaryPlacement(
        int $newUserId,
        int $parentId,
        string $position,          // 'left' | 'right'
        bool $incrementCounts = true
    ): void {
        if (setting('binary_enabled', '1') !== '1') {
            return;
        }
        if ($parentId <= 0) return;
        $pdo  = db();
        $cur  = $parentId;
        $side = $position;
        $visited = [$newUserId => true];

        // New member's package PV determines how much PV is added to each ancestor leg.
        $newUserRow = $pdo->prepare('SELECT status, package_id FROM users WHERE id = ?');
        $newUserRow->execute([$newUserId]);
        $newUser = $newUserRow->fetch();
        if (!$newUser) return;
        $newUserIsActive = ($newUser['status'] ?? '') === 'active';
        // CD-sourced and pending users are NOT paid members — they still build the
        // binary tree (leg counts/PV) but do not trigger pairing payouts.
        $newUserIsPaid = $newUserIsActive && User::isPaidMember($newUserId);
        $binaryPackagePv = Package::binaryPackagePv((int)($newUser['package_id'] ?? 0));

        while ($cur !== null) {
            if (isset($visited[$cur])) break;
            $visited[$cur] = true;

            // 1. Add binary package PV to the correct ancestor leg.
            //    Legacy left_count/right_count are kept in sync for reporting only.
            if ($incrementCounts) {
                $countCol = ($side === 'left') ? 'left_count' : 'right_count';
                $pdo->prepare("UPDATE users SET {$countCol} = {$countCol} + 1 WHERE id = ?")
                    ->execute([$cur]);

                if ($binaryPackagePv > 0) {
                    self::applyBinaryPv($cur, $side, $binaryPackagePv, $newUserId, 'registration', $newUserIsPaid);
                }
            }

            // Move to this ancestor's own parent
            $upRow = $pdo->prepare(
                'SELECT binary_parent_id, binary_position FROM users WHERE id = ?'
            );
            $upRow->execute([$cur]);
            $up = $upRow->fetch();

            $side = $up['binary_position'] ?? null;
            $cur  = isset($up['binary_parent_id']) ? (int)$up['binary_parent_id'] : null;
            if (!$cur) break;
        }
    }

    /**
     * Phase 5: Add arbitrary PV to the binary tree from a source member
     * (used for product/repeat-purchase PV) and trigger pairing bonuses.
     */
    public static function processBinaryPV(int $sourceUserId, float $pvAmount): void
    {
        if (setting('binary_enabled', '1') !== '1') {
            return;
        }
        if ($pvAmount <= 0.00) return;

        $pdo = db();
        $st  = $pdo->prepare('SELECT binary_parent_id, binary_position FROM users WHERE id = ?');
        $st->execute([$sourceUserId]);
        $row = $st->fetch();
        if (!$row) return;

        $cur  = isset($row['binary_parent_id']) ? (int)$row['binary_parent_id'] : null;
        $side = $row['binary_position'] ?? null;
        $visited = [$sourceUserId => true];

        while ($cur !== null) {
            if (isset($visited[$cur])) break;
            $visited[$cur] = true;

            // Only ancestors who meet the Personal PV gate receive binary PV from
            // repeat purchases. The PV still flows upward past non-qualifying ancestors.
            if (self::meetsPersonalPvRequirement($cur)) {
                self::applyBinaryPv($cur, $side, $pvAmount, $sourceUserId, 'repeat_purchase');
            }

            $st->execute([$cur]);
            $row = $st->fetch();
            $side = $row['binary_position'] ?? null;
            $cur  = isset($row['binary_parent_id']) ? (int)$row['binary_parent_id'] : null;
            if (!$cur) break;
        }
    }

    /**
     * Add PV to one ancestor leg, then pair any newly matched PV.
     * Records pv_transactions for binary leg, paired and flushed amounts.
     */
    private static function applyBinaryPv(
        int $ancestorId,
        ?string $side,
        float $pvAmount,
        int $sourceUserId,
        string $sourceType,
        bool $sourceIsPaid = true
    ): void {
        if ($ancestorId <= 0 || !$side || $pvAmount <= 0.00) {
            return;
        }

        $pdo = db();
        $pvCol = ($side === 'left') ? 'left_pv' : 'right_pv';
        $pdo->prepare("UPDATE users SET {$pvCol} = {$pvCol} + :pv WHERE id = :id")
            ->execute([':pv' => $pvAmount, ':id' => $ancestorId]);

        self::recordPvTransaction(
            $ancestorId,
            ($side === 'left') ? 'binary_left' : 'binary_right',
            $pvAmount,
            $sourceUserId,
            $sourceType
        );

        // CD-sourced / pending sources build leg PV but do NOT trigger pairing.
        if (!$sourceIsPaid) {
            return;
        }

        // Capped/perminact members earn no pairs, but their leg PV still accumulates.
        if (!CapEngine::isActiveForPairs($ancestorId)) {
            return;
        }

        $st = $pdo->prepare("
            SELECT u.id, u.package_id, u.left_pv, u.right_pv,
                   u.paired_pv, u.paired_pv_today,
                   u.daily_cap_bypass,
                   p.daily_pair_pv_cap
            FROM   users u
            LEFT JOIN packages p ON p.id = u.package_id
            WHERE  u.id = ? AND u.status = 'active'
        ");
        $st->execute([$ancestorId]);
        $ancestor = $st->fetch();
        if (!$ancestor) return;

        $available = min((float)$ancestor['left_pv'], (float)$ancestor['right_pv']);
        $processed = (float)$ancestor['paired_pv'];
        $newPaired = max(0.00, $available - $processed);

        if ($newPaired > 0.00) {
            if (!empty($ancestor['daily_cap_bypass'])) {
                $capRemaining = $newPaired;
            } else {
                $capRemaining = (float)$ancestor['daily_pair_pv_cap'] - (float)$ancestor['paired_pv_today'];
            }
            $payNow   = min($newPaired, max(0.00, $capRemaining));
            $flushNow = $newPaired - $payNow;

            if ($payNow > 0.00) {
                $bonus = Package::pairingBonus($payNow, (int)$ancestor['package_id']);
                self::creditPairing($ancestorId, $bonus, $payNow, $sourceUserId);
                self::recordPvTransaction($ancestorId, 'binary_paired', $payNow, $sourceUserId, $sourceType);
            }

            if ($flushNow > 0.00) {
                self::recordFlushPV($ancestorId, $flushNow, $sourceUserId);
                self::recordPvTransaction($ancestorId, 'binary_flushed', $flushNow, $sourceUserId, $sourceType);
            }

            $pdo->prepare("
                UPDATE users
                SET paired_pv       = paired_pv       + :pay,
                    flushed_pv      = flushed_pv      + :flush,
                    paired_pv_today = paired_pv_today + :pay2
                WHERE id = :id
            ")->execute([
                ':pay'   => $payNow,
                ':flush' => $flushNow,
                ':pay2'  => $payNow,
                ':id'    => $ancestorId,
            ]);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  DIRECT REFERRAL BONUS (% of Package PV)
    //  Fires immediately to the sponsor when their direct recruit registers.
    //  v2: Now subject to lifetime income cap.
    // ══════════════════════════════════════════════════════════════════════════

    public static function processDirectReferral(
        int $sponsorId,
        int $newUserId,
        int $packageId
    ): void {
        // Skip if sponsor is not active (e.g., pending activation)
        $sponsorStatus = db()->query("SELECT status FROM users WHERE id = {$sponsorId}")->fetchColumn();
        if ($sponsorStatus !== 'active') {
            return;
        }

        // Skip if the new member is CD-sourced — only paid members earn direct referral
        if (!User::isPaidMember($newUserId)) {
            return;
        }

        $pkg = Package::find($packageId);
        if (!$pkg || (float)$pkg['direct_ref_pv_pct'] <= 0) return;

        $packagePv = Package::packagePv($packageId);
        $bonus     = Package::directReferralBonus($packagePv, $packageId);

        // 1. CD split BEFORE lifetime cap
        $cdSplit = CdStatus::fillBucket($sponsorId, $bonus);
        $cdPortion = $cdSplit['cd'];
        $walletPortion = $cdSplit['wallet'];
        $cdStatusId = $cdSplit['cd_status_id'] ?? null;

        // 2. Lifetime cap on wallet overflow
        $capBlocked = 0.00;
        $actualWallet = 0.00;
        if ($walletPortion > 0) {
            $capCheck = CapEngine::canEarn($sponsorId, $walletPortion);
            $actualWallet = $capCheck['allowed'];
            $capBlocked = $capCheck['blocked'];

            if ($capBlocked > 0) {
                self::recordCapBlocked($sponsorId, $capBlocked, 'direct_referral', $newUserId);
            }
        }

        // 3. Record commission with GROSS amount
        $desc = 'Direct referral bonus';
        if ($cdPortion > 0) {
            $desc .= sprintf(' — %s to CD', fmt_money($cdPortion));
            if ($actualWallet > 0) {
                $desc .= sprintf(', %s to wallet', fmt_money($actualWallet));
            }
        }

        $pdo = db();
        $pdo->prepare("
            INSERT INTO commissions
              (user_id, type, amount, cap_deduction, source_user_id, description, status)
            VALUES (?, 'direct_referral', ?, ?, ?, ?, 'credited')
        ")->execute([$sponsorId, $bonus, $capBlocked, $newUserId, $desc]);

        $commId = (int)$pdo->lastInsertId();

        // 4. Credit e-wallet + cap blocked
        if ($actualWallet > 0) {
            Ewallet::credit($sponsorId, $actualWallet, $commId, 'commission', 'Direct referral bonus');
        }
        if ($capBlocked > 0) {
            self::recordCapBlocked($sponsorId, $capBlocked, 'direct_referral', $newUserId);
        }

        // 5. CD ledger
        if ($cdPortion > 0 && $cdStatusId) {
            CdStatus::recordLedger(
                $sponsorId, $cdStatusId, $commId, 'direct_referral',
                $bonus, $cdPortion, $actualWallet, $newUserId
            );
        }

        // 6. Record cap
        if ($actualWallet > 0) {
            CapEngine::recordEarning($sponsorId, $actualWallet, 'direct_referral');
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  UNILEVEL GENERATIONAL REFERRAL BONUSES (% of Package PV)
    //  Pure Sponsor Chain — No Binary Tree involvement at all
    //  v2: Now subject to lifetime income cap.
    // ══════════════════════════════════════════════════════════════════════════

    public static function processIndirectReferral(
        int $directSponsorId,
        int $newUserId,
        int $packageId
    ): void {
        if (setting('indirect_referral_enabled', '1') !== '1') {
            return;
        }

        // Skip if the new member is CD-sourced — only paid members earn indirect referral
        if (!User::isPaidMember($newUserId)) {
            return;
        }

        $levels = Package::getIndirectLevels($packageId);
        if (empty($levels)) return;

        $packagePv = Package::packagePv($packageId);
        $pdo = db();
        $cur = $directSponsorId;
        $visited = [$directSponsorId => true];

        for ($lvl = 1; $lvl <= 10; $lvl++) {

            // Skip if this upline is not active
            $uplineStatus = $pdo->query("SELECT status FROM users WHERE id = {$cur}")->fetchColumn();
            if ($uplineStatus !== 'active') {
                // Move up but do NOT pay this level
                $row = $pdo->prepare('SELECT sponsor_id FROM users WHERE id = ?');
                $row->execute([$cur]);
                $upRow = $row->fetch();
                if (!$upRow || empty($upRow['sponsor_id'])) {
                    break;
                }
                $next = (int)$upRow['sponsor_id'];
                if (isset($visited[$next])) {
                    break;
                }
                $visited[$next] = true;
                $cur = $next;
                continue;
            }

            $pvPct = (float)($levels[$lvl] ?? 0);
            $bonus = Package::indirectReferralBonus($packagePv, $pvPct);

            if ($bonus > 0) {
                // 1. CD split BEFORE lifetime cap
                $cdSplit = CdStatus::fillBucket($cur, $bonus);
                $cdPortion = $cdSplit['cd'];
                $walletPortion = $cdSplit['wallet'];
                $cdStatusId = $cdSplit['cd_status_id'] ?? null;

                // 2. Lifetime cap on wallet overflow
                $capBlocked = 0.00;
                $actualWallet = 0.00;
                if ($walletPortion > 0) {
                    $capCheck = CapEngine::canEarn($cur, $walletPortion);
                    $actualWallet = $capCheck['allowed'];
                    $capBlocked = $capCheck['blocked'];

                    if ($capBlocked > 0) {
                        self::recordCapBlocked($cur, $capBlocked, 'indirect_referral', $newUserId, $lvl);
                    }
                }

                // 3. Record commission with GROSS amount
                $desc = "Unilevel Level {$lvl} Bonus";
                if ($cdPortion > 0) {
                    $desc .= sprintf(' — %s to CD', fmt_money($cdPortion));
                    if ($actualWallet > 0) {
                        $desc .= sprintf(', %s to wallet', fmt_money($actualWallet));
                    }
                }

                $pdo->prepare("
                    INSERT INTO commissions
                      (user_id, type, amount, cap_deduction, source_user_id, level, description, status)
                    VALUES (?, 'indirect_referral', ?, ?, ?, ?, ?, 'credited')
                ")->execute([
                    $cur,
                    $bonus,
                    $capBlocked,
                    $newUserId,
                    $lvl,
                    $desc
                ]);

                $commId = (int)$pdo->lastInsertId();

                // 4. Credit e-wallet + cap blocked
                if ($actualWallet > 0) {
                    Ewallet::credit($cur, $actualWallet, $commId, 'commission', "Unilevel Level {$lvl} Bonus");
                }
                if ($capBlocked > 0) {
                    self::recordCapBlocked($cur, $capBlocked, 'indirect_referral', $newUserId, $lvl);
                }

                // 5. CD ledger
                if ($cdPortion > 0 && $cdStatusId) {
                    CdStatus::recordLedger(
                        $cur, $cdStatusId, $commId, 'indirect_referral',
                        $bonus, $cdPortion, $actualWallet, $newUserId
                    );
                }

                // 6. Record cap
                if ($actualWallet > 0) {
                    CapEngine::recordEarning($cur, $actualWallet, 'indirect_referral');
                }
            }

            // Move up using ONLY sponsor_id
            $row = $pdo->prepare('SELECT sponsor_id FROM users WHERE id = ?');
            $row->execute([$cur]);
            $upRow = $row->fetch();

            if (!$upRow || empty($upRow['sponsor_id'])) {
                break;
            }

            $next = (int)$upRow['sponsor_id'];

            if (isset($visited[$next])) {
                break;
            }

            $visited[$next] = true;
            $cur = $next;
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  PRODUCT / REPEAT-PURCHASE PV
    //  Distributes product PV to personal, group and binary PV on approval.
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Check whether a member satisfies the optional Personal PV gate.
     * A setting of 0 disables the gate.
     */
    private static function meetsPersonalPvRequirement(int $userId): bool
    {
        $user = User::find($userId);
        if (!$user) return false;
        $pkg = Package::find((int)$user['package_id']);
        $req = (float)($pkg['personal_pv_requirement'] ?? 0);
        if ($req <= 0) {
            return true;
        }
        return (float)$user['personal_pv'] >= $req;
    }

    public static function processProductPV(int $orderId): void
    {
        $order = RepeatPurchaseOrder::find($orderId);
        if (!$order || $order['status'] !== 'approved') return;

        $memberId = (int)$order['member_id'];
        $totalPv  = (float)$order['total_pv'];
        if ($totalPv <= 0.00) return;

        $buyer = User::find($memberId);
        if (!$buyer || $buyer['status'] !== 'active') return;

        $pdo = db();

        // 1. Buyer receives Personal PV
        $pdo->prepare('UPDATE users SET personal_pv = personal_pv + ? WHERE id = ?')
            ->execute([$totalPv, $memberId]);
        self::recordPvTransaction($memberId, 'product_personal', $totalPv, $memberId, 'repeat_purchase');

        // 2. Group PV flows up the sponsor chain to active uplines who meet the
        //    optional Personal PV requirement (read from each upline's own package).
        $cur = (int)$buyer['sponsor_id'];
        $visited = [$memberId => true];
        while ($cur > 0 && !isset($visited[$cur])) {
            $upline = User::find($cur);
            if (!$upline) break;

            if ($upline['status'] === 'active' && self::meetsPersonalPvRequirement($cur)) {
                $pdo->prepare('UPDATE users SET group_pv = group_pv + ? WHERE id = ?')
                    ->execute([$totalPv, $cur]);
                self::recordPvTransaction($cur, 'product_group', $totalPv, $memberId, 'repeat_purchase');
            }

            $visited[$cur] = true;
            $cur = (int)$upline['sponsor_id'];
        }

        // 3. Binary PV — only if binary repeat purchase is enabled
        if (setting('binary_repeat_enabled', '1') === '1') {
            // 3a. Pay yourself first: buyer receives binary PV on their chosen leg
            $buyerSide = $order['binary_position'];
            self::applyBinaryPv($memberId, $buyerSide, $totalPv, $memberId, 'repeat_purchase');

            // 3b. Product PV also flows up the binary tree (reads each user's fixed binary_position)
            self::processBinaryPV($memberId, $totalPv);
        }

        // ★ Step 4: Unilevel Product Bonus (10 levels via sponsor chain, PV-gated)
        self::processProductUnilevel($orderId);

        // ★ Step 5: Royalty Bonus — Leadership Ranks (real-time on each purchase)
        $orderTotal = (float)$order['total_price'];
        Royalty::processRepeatPurchase($memberId, $totalPv, $orderTotal);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  UNILEVEL PRODUCT BONUS (10 Levels, per line item, PV-gated)
    // ══════════════════════════════════════════════════════════════════════════

    public static function processProductUnilevel(int $orderId): void
    {
        if (setting('unilevel_product_enabled', '1') !== '1') {
            return;
        }

        $order = RepeatPurchaseOrder::find($orderId);
        if (!$order || $order['status'] !== 'approved') return;

        $buyerId = (int)$order['member_id'];
        $buyer   = User::find($buyerId);
        if (!$buyer || $buyer['status'] !== 'active') return;

        $pdo = db();

        // Load order items to process per-product
        $st = $pdo->prepare(
            'SELECT product_id, total_pv FROM repeat_purchase_order_items WHERE order_id = ?'
        );
        $st->execute([$orderId]);
        $items = $st->fetchAll();
        if (empty($items)) return;

        $rate = (float)setting('pv_per_peso_rate', '1.0000');

        foreach ($items as $item) {
            $productId = (int)$item['product_id'];
            $effPv     = (float)$item['total_pv'];
            if ($effPv <= 0) continue;

            $levels = Product::getUnilevelLevels($productId);
            if (empty($levels)) continue;

            $cur = (int)$buyer['sponsor_id'];
            $visited = [$buyerId => true];

            for ($lvl = 1; $lvl <= 10; $lvl++) {
                if ($cur <= 0 || isset($visited[$cur])) break;
                $visited[$cur] = true;

                $upline = User::find($cur);
                if (!$upline) break;

                if ($upline['status'] !== 'active') {
                    $cur = (int)$upline['sponsor_id'];
                    continue;
                }

                if (!self::meetsPersonalPvRequirement($cur)) {
                    $cur = (int)$upline['sponsor_id'];
                    continue;
                }

                $pvPct = (float)($levels[$lvl] ?? 0);
                if ($pvPct <= 0) {
                    $cur = (int)$upline['sponsor_id'];
                    continue;
                }

                $bonus = $effPv * ($pvPct / 100) * $rate;

                // 1. CD split BEFORE lifetime cap
                $cdSplit = CdStatus::fillBucket($cur, $bonus);
                $cdPortion = $cdSplit['cd'];
                $walletPortion = $cdSplit['wallet'];
                $cdStatusId = $cdSplit['cd_status_id'] ?? null;

                // 2. Lifetime cap on wallet overflow
                $capBlocked = 0.00;
                $actualWallet = 0.00;
                if ($walletPortion > 0) {
                    $capCheck = CapEngine::canEarn($cur, $walletPortion);
                    $actualWallet = $capCheck['allowed'];
                    $capBlocked = $capCheck['blocked'];
                }

                // 3. Build description
                $desc = "Unilevel Product L{$lvl} Bonus";
                if ($cdPortion > 0) {
                    $desc .= sprintf(' — %s to CD', fmt_money($cdPortion));
                    if ($actualWallet > 0) {
                        $desc .= sprintf(', %s to wallet', fmt_money($actualWallet));
                    }
                }

                // 4. INSERT commission (GROSS)
                $pdo->prepare("
                    INSERT INTO commissions
                      (user_id, type, amount, cap_deduction, source_user_id, level, description, status)
                    VALUES (?, 'unilevel_product', ?, ?, ?, ?, ?, 'credited')
                ")->execute([$cur, $bonus, $capBlocked, $buyerId, $lvl, $desc]);

                $commId = (int)$pdo->lastInsertId();

                // 5. Credit e-wallet + cap blocked
                if ($actualWallet > 0) {
                    Ewallet::credit($cur, $actualWallet, $commId, 'commission', "Unilevel Product L{$lvl} Bonus");
                }
                if ($capBlocked > 0) {
                    self::recordCapBlocked($cur, $capBlocked, 'unilevel_product', $buyerId, $lvl);
                }

                // 6. CD ledger
                if ($cdPortion > 0 && $cdStatusId) {
                    CdStatus::recordLedger(
                        $cur, $cdStatusId, $commId, 'unilevel_product',
                        $bonus, $cdPortion, $actualWallet, $buyerId
                    );
                }

                // 7. Record cap earning
                if ($actualWallet > 0) {
                    CapEngine::recordEarning($cur, $actualWallet, 'unilevel_product');
                }

                $cur = (int)$upline['sponsor_id'];
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    private static function recordPvTransaction(
        int $userId,
        string $type,
        float $amount,
        int $sourceUserId,
        string $sourceType
    ): void {
        db()->prepare("
            INSERT INTO pv_transactions
              (user_id, type, amount, source_user_id, source_type)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$userId, $type, $amount, $sourceUserId, $sourceType]);
    }

    private static function creditPairing(
        int $userId,
        float $amount,
        float $pairedPv,
        int $sourceId
    ): void {
        $pdo = db();

        // 1. CD split happens BEFORE lifetime cap
        $cdSplit = CdStatus::fillBucket($userId, $amount);
        $cdPortion = $cdSplit['cd'];
        $walletPortion = $cdSplit['wallet'];
        $cdStatusId = $cdSplit['cd_status_id'] ?? null;

        // 2. Lifetime cap check on wallet overflow only
        $capBlocked = 0.00;
        $actualWallet = 0.00;
        if ($walletPortion > 0) {
            $capCheck = CapEngine::canEarn($userId, $walletPortion);
            $actualWallet = $capCheck['allowed'];
            $capBlocked = $capCheck['blocked'];
        }

        // 3. Build description
        $desc = number_format($pairedPv, 2) . ' PV paired → ' . fmt_money($amount);
        if ($cdPortion > 0) {
            $desc .= sprintf(' — %s to CD', fmt_money($cdPortion));
            if ($actualWallet > 0) {
                $desc .= sprintf(', %s to wallet', fmt_money($actualWallet));
            }
        }

        // 4. Record commission with GROSS amount
        $pdo->prepare("
            INSERT INTO commissions
              (user_id, type, amount, cap_deduction, source_user_id, pairs_count, description, status)
            VALUES (?, 'pairing', ?, ?, ?, ?, ?, 'credited')
        ")->execute([
            $userId,
            $amount,        // GROSS amount recorded
            $capBlocked,
            $sourceId,
            1,              // One pairing event per ancestor per placement
            $desc
        ]);

        $commId = (int)$pdo->lastInsertId();

        // 5. Credit e-wallet + cap blocked
        if ($actualWallet > 0) {
            Ewallet::credit($userId, $actualWallet, $commId, 'commission', number_format($pairedPv, 2) . ' PV pairing bonus');
        }
        if ($capBlocked > 0) {
            self::recordCapBlocked($userId, $capBlocked, 'pairing', $sourceId, null, 1);
        }

        // 6. CD ledger audit trail
        if ($cdPortion > 0 && $cdStatusId) {
            CdStatus::recordLedger(
                $userId, $cdStatusId, $commId, 'pairing',
                $amount, $cdPortion, $actualWallet, $sourceId
            );
        }

        // 7. Record cap against lifetime cap (on what actually reached wallet)
        if ($actualWallet > 0) {
            CapEngine::recordEarning($userId, $actualWallet, 'pairing');
        }
    }

    private static function recordFlush(int $userId, int $pairs, int $sourceId): void
    {
        db()->prepare("
            INSERT INTO commissions
              (user_id, type, amount, source_user_id, pairs_count, description, status)
            VALUES (?, 'pairing', 0.00, ?, ?, ?, 'flushed')
        ")->execute([
            $userId,
            $sourceId,
            $pairs,
            "{$pairs} pair(s) flushed — daily cap reached"
        ]);
    }

    /**
     * v3: Record PV that was flushed because the daily paired-PV cap was reached.
     */
    private static function recordFlushPV(int $userId, float $flushedPv, int $sourceId): void
    {
        db()->prepare("
            INSERT INTO commissions
              (user_id, type, amount, source_user_id, pairs_count, description, status)
            VALUES (?, 'pairing', 0.00, ?, ?, ?, 'flushed')
        ")->execute([
            $userId,
            $sourceId,
            0,
            number_format($flushedPv, 2) . ' PV flushed — daily cap reached'
        ]);
    }

    /**
     * v2: Record commission blocked by lifetime cap (audit trail).
     */
    private static function recordCapBlocked(
        int $userId,
        float $amount,
        string $type,
        int $sourceId,
        ?int $level = null,
        ?int $pairs = null
    ): void {
        $desc = match ($type) {
            'pairing' => ($pairs ?? 0) . " pair(s) blocked — lifetime cap reached",
            'direct_referral' => "Direct referral blocked — lifetime cap reached",
            'indirect_referral' => "Unilevel L{$level} blocked — lifetime cap reached",
            'unilevel_product' => "Unilevel Product L{$level} blocked — lifetime cap reached",
            default => "Commission blocked — lifetime cap reached",
        };

        db()->prepare("
            INSERT INTO commissions
              (user_id, type, amount, cap_deduction, source_user_id, level, pairs_count, description, status)
            VALUES (?, ?, 0.00, ?, ?, ?, ?, ?, 'flushed')
        ")->execute([
            $userId,
            $type,
            $amount,
            $sourceId,
            $level,
            $pairs,
            $desc,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  QUERY HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    public static function summary(int $userId): array
    {
        $st = db()->prepare("
            SELECT
              COALESCE(SUM(CASE WHEN type='pairing'            AND status='credited' THEN amount END), 0) AS total_pairing,
              COALESCE(SUM(CASE WHEN type='direct_referral'    AND status='credited' THEN amount END), 0) AS total_direct,
              COALESCE(SUM(CASE WHEN type='indirect_referral'  AND status='credited' THEN amount END), 0) AS total_indirect,
              COALESCE(SUM(CASE WHEN type='daily_fixed_income' AND status='credited' THEN amount END), 0) AS total_dfi,
              COALESCE(SUM(CASE WHEN type='unilevel_product'   AND status='credited' THEN amount END), 0) AS total_unilevel_product,
              COALESCE(SUM(CASE WHEN type='royalty'            AND status='credited' THEN amount END), 0) AS total_royalty,
              COALESCE(SUM(CASE WHEN status='credited'                              THEN amount END), 0) AS total_earned,
              COALESCE(SUM(CASE WHEN type='pairing' AND status='flushed' THEN pairs_count END), 0)       AS total_flushed_pairs,
              COALESCE(SUM(cap_deduction), 0) AS total_cap_blocked
            FROM commissions
            WHERE user_id = ?
        ");
        $st->execute([$userId]);
        return $st->fetch();
    }

    public static function recent(int $userId, int $limit = 10): array
    {
        $st = db()->prepare("
            SELECT c.*,
                   u.username AS source_username
            FROM   commissions c
            LEFT JOIN users u ON u.id = c.source_user_id
            WHERE  c.user_id = ?
            ORDER BY c.created_at DESC
            LIMIT  {$limit}
        ");
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    public static function history(int $userId, int $page = 1, int $perPage = 20, string $type = ''): array
    {
        $where  = 'c.user_id = ?';
        $params = [$userId];

        if ($type && in_array($type, ['pairing', 'direct_referral', 'indirect_referral', 'daily_fixed_income', 'unilevel_product', 'royalty'])) {
            $where  .= ' AND c.type = ?';
            $params[] = $type;
        }

        return paginate(
            "SELECT c.*, u.username AS source_username
             FROM   commissions c
             LEFT JOIN users u ON u.id = c.source_user_id
             WHERE  {$where}
             ORDER BY c.created_at DESC",
            $params,
            $page,
            $perPage
        );
    }

    /**
     * Get paginated cap-blocked commission records for a user.
     */
    public static function capBlockedHistory(int $userId, int $page = 1, int $perPage = 20): array
    {
        return paginate(
            "SELECT c.*, u.username AS source_username
             FROM commissions c
             LEFT JOIN users u ON u.id = c.source_user_id
             WHERE c.user_id = ? AND c.cap_deduction > 0
             ORDER BY c.created_at DESC",
            [$userId],
            $page,
            $perPage
        );
    }
}
