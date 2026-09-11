<?php

/**
 * @file   models/User.php
 * @brief  User management model
 */
class User
{
    // ── Registration ──────────────────────────────────────────────────────────

    public static function register(array $data): int
    {
        if (isSeatLimitReached()) {
            throw new RuntimeException('Registration is closed. The member seat limit has been reached.');
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $paymentMethod = $data['reg_payment_method'] ?? 'code';
            $entryFee      = 0.00;

            // ── E-Wallet payment: debit payer, credit admin ──
            if ($paymentMethod === 'ewallet') {
                $package = Package::find((int)$data['package_id']);
                if (!$package) {
                    throw new RuntimeException('Invalid package selected.');
                }
                $entryFee = (float)$package['entry_fee'];
                $payerId  = (int)($data['reg_paid_by'] ?? 0);

                if ($payerId <= 0) {
                    throw new RuntimeException('Invalid payer account.');
                }

                // Debit payer (non-withdrawable first, then withdrawable)
                $debitOk = Ewallet::debitInternal(
                    $payerId,
                    $entryFee,
                    0,
                    'registration',
                    "Entry fee for new member @" . $data['username']
                );
                if (!$debitOk) {
                    throw new RuntimeException('Insufficient e-wallet balance for entry fee.');
                }

                // Credit admin as revenue
                $adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1")
                    ->fetchColumn();
                if ($adminId) {
                    Ewallet::credit(
                        $adminId,
                        $entryFee,
                        0,
                        'registration',
                        "Entry fee from @" . $data['username']
                            . " (registered by @" . ($data['paid_by_username'] ?? 'admin') . ")"
                    );
                }
            }

            // Verify binary slot is free
            $slotCheck = $pdo->prepare("
                SELECT COUNT(*) FROM users
                WHERE binary_parent_id = ? AND binary_position = ?
            ");
            $slotCheck->execute([$data['binary_parent_id'], $data['binary_position']]);
            if ((int)$slotCheck->fetchColumn() > 0) {
                throw new RuntimeException('That binary position is already taken.');
            }

            // Insert the new member
            $hash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
            $status = !empty($data['pending']) ? 'pending' : ($data['status'] ?? 'active');
            $pdo->prepare("
                INSERT INTO users
                  (username, password_hash, package_id, reg_code_id,
                   reg_payment_method, reg_paid_by,
                   sponsor_id, binary_parent_id, binary_position, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $data['username'],
                $hash,
                (!empty($data['package_id']) ? $data['package_id'] : null),
                $data['reg_code_id'] ?? null,
                $paymentMethod,
                $data['reg_paid_by'] ?? null,
                $data['sponsor_id'],
                $data['binary_parent_id'],
                $data['binary_position'],
                $status,
            ]);
            $newId = (int)$pdo->lastInsertId();

            // Mark registration code as used (code payment only)
            if (!empty($data['reg_code_id'])) {
                $pdo->prepare("
                    UPDATE reg_codes SET status = 'used', used_by = ?, used_at = NOW()
                    WHERE id = ?
                ")->execute([$newId, $data['reg_code_id']]);

                // Auto-assign CD if the registration code was a CD code
                $codeType = $pdo->prepare("SELECT code_type FROM reg_codes WHERE id = ?");
                $codeType->execute([$data['reg_code_id']]);
                if ($codeType->fetchColumn() === 'cd') {
                    $pkg = Package::find((int)$data['package_id']);
                    try {
                        CdStatus::assign($newId, (float)($pkg['entry_fee'] ?? 0), 1);
                    } catch (RuntimeException $e) {
                        error_log("Auto-CD assignment failed for new user {$newId}: " . $e->getMessage());
                    }
                }
            }

            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        // ── Real-time commissions (outside transaction to prevent lock contention) ──
        // 1. Walk binary tree upward → increment leg counts (pairing bonuses only if active)
        //    Pending users: do NOT increment leg counts; counts will be incremented at activation.
        if ($data['binary_parent_id'] !== null) {
            Commission::processBinaryPlacement($newId, $data['binary_parent_id'], $data['binary_position'], $status !== 'pending');
        }

        if ($status !== 'pending' && $data['sponsor_id'] !== null) {
            // 2. Direct referral bonus → sponsor
            Commission::processDirectReferral($data['sponsor_id'], $newId, $data['package_id']);

            // 3. Indirect referral bonuses → up to 10 levels in sponsor chain
            if (Package::hasIndirectReferral((int)$data['package_id'])) {
                Commission::processIndirectReferral($data['sponsor_id'], $newId, $data['package_id']);
            }
        }

        return $newId;
    }

    /**
     * Activate a pending account.
     * Returns the user's data for commission firing.
     */
    public static function activate(int $userId, int $packageId, ?int $regCodeId, string $paymentMethod): array
    {
        $pdo = db();

        // Activate the user AND flush all binary volume that formed while pending.
        // This prevents retroactive pairing bonuses — only volume formed AFTER
        // activation will earn bonuses. Legacy count flush kept for audit.
        $pdo->prepare("
            UPDATE users
            SET status = 'active',
                package_id = ?,
                reg_code_id = COALESCE(?, reg_code_id),
                reg_payment_method = ?,
                joined_at = NOW(),
                pairs_flushed = LEAST(left_count, right_count),
                pairs_volume_flushed = LEAST(left_pair_volume, right_pair_volume)
            WHERE id = ? AND status = 'pending'
        ")->execute([$packageId, $regCodeId, $paymentMethod, $userId]);

        $user = self::find($userId);
        if (!$user || $user['status'] !== 'active') {
            throw new RuntimeException('Activation failed — user not found or not pending.');
        }

        // Auto-assign CD if activation uses a CD code
        if ($regCodeId) {
            $cType = $pdo->prepare("SELECT code_type FROM reg_codes WHERE id = ?");
            $cType->execute([$regCodeId]);
            if ($cType->fetchColumn() === 'cd') {
                $pkg = Package::find($packageId);
                if ($pkg) {
                    CdStatus::assign($userId, (float)$pkg['entry_fee'], 1);
                }
            }
        }

        // Fire commissions now that user is active.
        // 1. Pairing bonuses — increment leg counts now (pending registration skipped this)
        //    and calculate pairs for this single activation only.
        Commission::processBinaryPlacement($userId, (int)$user['binary_parent_id'], $user['binary_position'], true);

        // 2. Direct referral bonus → sponsor
        Commission::processDirectReferral((int)$user['sponsor_id'], $userId, $packageId);

        // 3. Indirect referral bonuses
        if (Package::hasIndirectReferral($packageId)) {
            Commission::processIndirectReferral((int)$user['sponsor_id'], $userId, $packageId);
        }

        return $user;
    }

    // ── Finders ───────────────────────────────────────────────────────────────

    public static function find(int $id): ?array
    {
        $st = db()->prepare("
            SELECT u.*,
                   p.name          AS package_name,
                   p.pairing_bonus,
                   p.daily_pair_cap,
                   p.direct_ref_bonus,
                   sp.username     AS sponsor_username,
                   bp.username     AS binary_parent_username
            FROM   users u
            LEFT JOIN packages p ON p.id = u.package_id
            LEFT JOIN users sp   ON sp.id = u.sponsor_id
            LEFT JOIN users bp   ON bp.id = u.binary_parent_id
            WHERE  u.id = ?
        ");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function findByUsername(string $username): ?array
    {
        $st = db()->prepare('SELECT * FROM users WHERE username = ?');
        $st->execute([$username]);
        return $st->fetch() ?: null;
    }

    public static function usernameExists(string $username): bool
    {
        $st = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $st->execute([$username]);
        return (bool)$st->fetchColumn();
    }

    // ── Profile Update ────────────────────────────────────────────────────────

    public static function updateProfile(int $id, array $data): bool
    {
        $allowed = ['full_name', 'email', 'mobile', 'gcash_number', 'maya_number', 'usdt_trc20_address', 'usdt_bep20_address', 'address', 'photo'];
        $fields  = [];
        $values  = [];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "{$f} = ?";
                $values[] = $data[$f];
            }
        }
        if (empty($fields)) return false;

        $values[] = $id;
        $st = db()->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?");
        return $st->execute($values);
    }

    public static function updatePassword(int $id, string $newPassword): bool
    {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $st   = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        return $st->execute([$hash, $id]);
    }

    public static function verifyPassword(int $id, string $password): bool
    {
        $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$id]);
        $hash = $st->fetchColumn();
        return $hash && password_verify($password, $hash);
    }

    public static function isPaidMember(int $userId): bool
    {
        $st = db()->prepare("
            SELECT u.reg_payment_method, COALESCE(c.code_type, 'registration') AS code_type
            FROM users u
            LEFT JOIN reg_codes c ON c.id = u.reg_code_id
            WHERE u.id = ?
        ");
        $st->execute([$userId]);
        $row = $st->fetch();
        if (!$row) return false;
        return $row['reg_payment_method'] !== 'pending'
            && !($row['reg_payment_method'] === 'code' && $row['code_type'] === 'cd');
    }

    // ── Package Upgrade ──────────────────────────────────────────────────────

    /**
     * Upgrade a member to a more expensive package.
     * Only the price difference is charged (ewallet debit or upgrade code).
     *
     * When moving from a non-binary package to a pairing-enabled package, the
     * member is placed as a new binary leg under the supplied upline.
     *
     * @return array Updated user row after upgrade
     */
    public static function upgrade(
        int $userId,
        int $newPackageId,
        string $paymentMethod,
        ?int $binaryParentId = null,
        ?string $binaryPosition = null,
        ?int $upgradeCodeId = null
    ): array {
        $pdo = db();

        $user = self::find($userId);
        if (!$user) {
            throw new RuntimeException('User not found.');
        }

        $curPkg = Package::find((int)$user['package_id']);
        $newPkg = Package::find($newPackageId);
        if (!$curPkg || !$newPkg) {
            throw new RuntimeException('Invalid package.');
        }
        if ($newPackageId === (int)$user['package_id']) {
            throw new RuntimeException('You already have this package.');
        }
        if ((float)$newPkg['entry_fee'] < (float)$curPkg['entry_fee']) {
            throw new RuntimeException('Downgrading is not allowed.');
        }

        $diff = (float)$newPkg['entry_fee'] - (float)$curPkg['entry_fee'];

        $curPairing = Package::hasPairing((int)$user['package_id']);
        $newPairing = Package::hasPairing($newPackageId);

        $pdo->beginTransaction();
        try {
            // ── Payment: diff only ──
            if ($paymentMethod === 'code' && $upgradeCodeId) {
                $codeRow = $pdo->prepare(
                    "SELECT * FROM reg_codes WHERE id = ? AND status = 'unused' AND code_type = 'upgrade'"
                );
                $codeRow->execute([$upgradeCodeId]);
                $codeRow = $codeRow->fetch();
                if (!$codeRow) {
                    throw new RuntimeException('Invalid upgrade code.');
                }
                if ((int)$codeRow['package_id'] !== $newPackageId) {
                    throw new RuntimeException('This upgrade code does not match the selected package.');
                }
                $pdo->prepare("UPDATE reg_codes SET status = 'used', used_by = ?, used_at = NOW() WHERE id = ?")
                    ->execute([$userId, $upgradeCodeId]);
            } elseif ($paymentMethod === 'ewallet') {
                if ($diff > 0) {
                    $debitOk = Ewallet::debitInternal(
                        $userId,
                        $diff,
                        0,
                        'upgrade',
                        "Package upgrade fee (@{$user['username']}) to " . $newPkg['name']
                    );
                    if (!$debitOk) {
                        throw new RuntimeException('Insufficient e-wallet balance for the upgrade fee.');
                    }
                    $adminId = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1")
                        ->fetchColumn();
                    if ($adminId) {
                        Ewallet::credit(
                            $adminId,
                            $diff,
                            $userId,
                            'upgrade',
                            "Upgrade fee from @{$user['username']} to " . $newPkg['name'],
                            false
                        );
                    }
                }
            } else {
                throw new RuntimeException('Invalid payment method.');
            }

            // ── Binary placement transition: non-binary → pairing ──
            if (!$curPairing && $newPairing) {
                if (!$binaryParentId || !in_array($binaryPosition, ['left', 'right'], true)) {
                    throw new RuntimeException('A binary position is required for this package.');
                }
                if ((int)$binaryParentId === $userId) {
                    throw new RuntimeException('Cannot place a member under themselves.');
                }
                $upl = self::find((int)$binaryParentId);
                if (!$upl || !Package::hasPairing((int)$upl['package_id'])) {
                    throw new RuntimeException('Selected binary upline is not available.');
                }
                if ($upl['status'] !== 'active') {
                    throw new RuntimeException('The binary upline must be an active member.');
                }
                if (!User::isSlotFree((int)$binaryParentId, $binaryPosition)) {
                    throw new RuntimeException('That binary position is already taken.');
                }
            }

            // ── Apply package + binary placement ──
            if (!$curPairing && $newPairing) {
                $pdo->prepare(
                    "UPDATE users SET package_id = ?, binary_parent_id = ?, binary_position = ? WHERE id = ?"
                )->execute([$newPackageId, $binaryParentId, $binaryPosition, $userId]);
            } else {
                $pdo->prepare("UPDATE users SET package_id = ? WHERE id = ?")
                    ->execute([$newPackageId, $userId]);
            }

            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        // ── Fire binary placement when joining the binary network (outside txn) ──
        if (!$curPairing && $newPairing) {
            Commission::processBinaryPlacement($userId, (int)$binaryParentId, $binaryPosition, true);
        }

        return self::find($userId);
    }

    /**
     * Get cap status for a user.
     * Delegates to CapEngine for consistency.
     */
    public static function getCapStatus(int $userId): array
    {
        return CapEngine::getCapStatus($userId);
    }

    /**
     * Check if user is active for binary pair counting.
     */
    public static function isCapActive(int $userId): bool
    {
        return CapEngine::isActiveForPairs($userId);
    }

    /**
     * Get cap-aware pairing status for dashboard.
     * Extends todayPairingStatus() with cap info.
     */
    public static function todayPairingStatus(int $userId): array
    {
        $pdo = db();
        $st = $pdo->prepare("
            SELECT
                u.pairs_paid,
                u.pairs_paid_today,
                u.pairs_flushed,
                u.left_count,
                u.right_count,
                u.left_pair_volume,
                u.right_pair_volume,
                u.pairs_volume_paid,
                u.pairs_volume_flushed,
                u.pairs_volume_today,
                p.pairing_bonus,
                p.daily_pair_cap,
                u.lifetime_earned,
                u.cap_status,
                (p.entry_fee * p.lifetime_cap_multiplier) AS lifetime_cap
            FROM users u
            LEFT JOIN packages p ON p.id = u.package_id
            WHERE u.id = ?
        ");
        $st->execute([$userId]);
        $row = $st->fetch();

        if (!$row) {
            return [
                'pairs_paid'          => 0,
                'pairs_paid_today'    => 0,
                'pairs_flushed'       => 0,
                'left_count'          => 0,
                'right_count'         => 0,
                'left_pair_volume'    => 0,
                'right_pair_volume'   => 0,
                'unpaired_volume'     => 0,
                'pairs_volume_paid'   => 0,
                'pairs_volume_flushed'=> 0,
                'pairs_volume_today'  => 0,
                'matched_volume'      => 0,
                'pairing_bonus'       => 0,
                'daily_cap'           => 0,
                'daily_cap_pesos'     => 0,
                'cap_percent'         => 0,
                'cap_remaining'       => 0,
                'earned_today'        => fmt_money(0),
                'lifetime_earned'     => 0,
                'lifetime_cap'        => 0,
                'cap_status'          => 'perminact',
            ];
        }

        $bonus        = (float)$row['pairing_bonus'];
        $matchedToday = (float)$row['pairs_volume_today'];
        $dailyCapPesos = (float)$row['daily_pair_cap'] * $bonus;
        $capPct       = $dailyCapPesos > 0 ? min(100, ($matchedToday / $dailyCapPesos) * 100) : 0;
        $capRem       = max(0, $dailyCapPesos - $matchedToday);

        return [
            'pairs_paid'          => (int)$row['pairs_paid'],
            'pairs_paid_today'    => (int)$row['pairs_paid_today'],
            'pairs_flushed'       => (int)$row['pairs_flushed'],
            'left_count'          => (int)$row['left_count'],
            'right_count'         => (int)$row['right_count'],
            'left_pair_volume'    => (float)$row['left_pair_volume'],
            'right_pair_volume'   => (float)$row['right_pair_volume'],
            'unpaired_volume'     => abs((float)$row['left_pair_volume'] - (float)$row['right_pair_volume']),
            'pairs_volume_paid'   => (float)$row['pairs_volume_paid'],
            'pairs_volume_flushed'=> (float)$row['pairs_volume_flushed'],
            'pairs_volume_today'  => $matchedToday,
            'matched_volume'      => (float)$row['pairs_volume_paid'] + (float)$row['pairs_volume_flushed'],
            'pairing_bonus'       => $bonus,
            'daily_cap'           => (int)$row['daily_pair_cap'],
            'daily_cap_pesos'     => $dailyCapPesos,
            'cap_percent'         => round($capPct, 1),
            'cap_remaining'       => $capRem,
            'earned_today'        => $matchedToday,  // Raw float — view calls fmt_money()
            'lifetime_earned'     => (float)$row['lifetime_earned'],
            'lifetime_cap'        => (float)$row['lifetime_cap'],
            'cap_status'          => $row['cap_status'],
        ];
    }

    // ── Binary Slot Check ─────────────────────────────────────────────────────

    public static function isSlotFree(int $parentId, string $position): bool
    {
        $st = db()->prepare("
            SELECT COUNT(*) FROM users
            WHERE binary_parent_id = ? AND binary_position = ?
        ");
        $st->execute([$parentId, $position]);
        return (int)$st->fetchColumn() === 0;
    }

    // ── Admin Queries ─────────────────────────────────────────────────────────

    public static function allMembers(int $page = 1, string $search = '', string $status = '', int $pkgId = 0, int $perPage = 10): array
    {
        $where  = "u.role = 'member'";
        $params = [];

        if ($search) {
            $where   .= " AND (u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
            $s        = "%{$search}%";
            $params   = array_merge($params, [$s, $s, $s]);
        }
        if ($status) {
            $where   .= ' AND u.status = ?';
            $params[] = $status;
        }
        if ($pkgId) {
            $where   .= ' AND u.package_id = ?';
            $params[] = $pkgId;
        }

        return paginate(
            "SELECT u.*, p.name AS package_name
             FROM   users u
             LEFT JOIN packages p ON p.id = u.package_id
             WHERE  {$where}
             ORDER BY u.joined_at DESC",
            $params,
            $page,
            $perPage
        );
    }

    public static function counts(): array
    {
        $row = db()->query("
            SELECT
              COUNT(*)                                                        AS total,
              COALESCE(SUM(CASE WHEN status='active'       THEN 1 ELSE 0 END),0) AS active,
              COALESCE(SUM(CASE WHEN status='suspended'    THEN 1 ELSE 0 END),0) AS suspended,
              COALESCE(SUM(CASE WHEN status='pending'      THEN 1 ELSE 0 END),0) AS pending,
              COALESCE(SUM(CASE WHEN status='deactivated'  THEN 1 ELSE 0 END),0) AS deactivated,
              COALESCE(SUM(CASE WHEN joined_at >= CURDATE() THEN 1 ELSE 0 END),0) AS joined_today
            FROM users WHERE role = 'member'
        ")->fetch();
        return $row ?: ['total' => 0, 'active' => 0, 'suspended' => 0, 'pending' => 0, 'deactivated' => 0, 'joined_today' => 0];
    }

    // ── Referral Chain (sponsor chain, not binary) ────────────────────────────

    /**
     * Get direct recruits by this member (sponsor_id = $userId).
     */
    public static function directReferrals(int $userId, int $page = 1, int $perPage = 10): array
    {
        return paginate(
            "SELECT u.*, p.name AS package_name
             FROM   users u
             LEFT JOIN packages p ON p.id = u.package_id
             WHERE  u.sponsor_id = ? AND u.role = 'member'
             ORDER BY u.joined_at DESC",
            [$userId],
            $page,
            $perPage
        );
    }

    /**
     * Get full indirect genealogy (up to 10 levels) for display.
     * Returns flat array with a `level` column.
     */
    public static function indirectReferralTree(int $userId, int $maxLevel = 10): array
    {
        $result = [];
        $queue  = [['id' => $userId, 'level' => 0]];
        $visited = [$userId => true];

        while (!empty($queue)) {
            $item = array_shift($queue);
            if ($item['level'] >= $maxLevel) continue;

            $st = db()->prepare("
                SELECT u.id, u.username, u.full_name, u.status,
                       u.joined_at, p.name AS package_name
                FROM   users u
                LEFT JOIN packages p ON p.id = u.package_id
                WHERE  u.sponsor_id = ? AND u.role = 'member'
            ");
            $st->execute([$item['id']]);
            $children = $st->fetchAll();

            foreach ($children as $child) {
                if (isset($visited[$child['id']])) continue;
                $visited[$child['id']] = true;
                $child['level'] = $item['level'] + 1;
                $result[] = $child;
                $queue[]  = ['id' => $child['id'], 'level' => $child['level']];
            }
        }

        return $result;
    }
}
