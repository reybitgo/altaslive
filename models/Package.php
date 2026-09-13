<?php

/**
 * @file   models/Package.php
 * @brief  Package management model
 */
class Package
{
    public static function find(int $id): ?array
    {
        $st = db()->prepare('SELECT * FROM packages WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM packages';
        if ($activeOnly) $sql .= " WHERE status = 'active'";
        $sql .= ' ORDER BY entry_fee ASC';
        return db()->query($sql)->fetchAll();
    }

    public static function getIndirectLevels(int $packageId): array
    {
        $st = db()->prepare(
            'SELECT level, bonus FROM package_indirect_levels WHERE package_id = ? ORDER BY level'
        );
        $st->execute([$packageId]);
        $rows   = $st->fetchAll();
        $result = [];
        foreach ($rows as $r) {
            $result[(int)$r['level']] = (float)$r['bonus'];
        }
        return $result;
    }

    public static function withLevels(int $id): ?array
    {
        $pkg = self::find($id);
        if (!$pkg) return null;
        $pkg['indirect_levels'] = self::getIndirectLevels($id);
        return $pkg;
    }

    /**
     * Save or update a package with all v2 fields.
     *
     * @param array $data Package data including v2 fields:
     *   - name, entry_fee, pairing_bonus, daily_pair_cap, direct_ref_bonus, status
     *   - lifetime_cap_multiplier, reactivation_fee, reactivation_window_days
     *   - daily_fixed_income, daily_fixed_income_days
     *   - indirect_levels[1..10]
     * @param int|null $id Package ID for update, null for create
     */
    public static function save(array $data, ?int $id = null): int
    {
        $pdo = db();

        $fields = [
            'name'                     => $data['name'],
            'entry_fee'                => (float)($data['entry_fee'] ?? 0),
            'pairing_bonus'            => (float)($data['pairing_bonus'] ?? 0),
            'daily_pair_cap'           => (int)($data['daily_pair_cap'] ?? 3),
            'direct_ref_bonus'         => (float)($data['direct_ref_bonus'] ?? 0),
            // v2 fields
            'lifetime_cap_multiplier'  => (float)($data['lifetime_cap_multiplier'] ?? 3.00),
            'reactivation_fee'         => (float)($data['reactivation_fee'] ?? 0),
            'reactivation_window_days' => (int)($data['reactivation_window_days'] ?? 15),
            'daily_fixed_income'       => (float)($data['daily_fixed_income'] ?? 0),
            'daily_fixed_income_days'  => (int)($data['daily_fixed_income_days'] ?? 90),
            'status'                   => $data['status'] ?? 'active',
            'indirect_referral_enabled' => (int)($data['indirect_referral_enabled'] ?? 1),
            'dfi_enabled'               => (int)($data['dfi_enabled'] ?? 1),
            'pairing_enabled'           => (int)($data['pairing_enabled'] ?? 1),
        ];

        if ($id) {
            // Update
            $sets = [];
            $vals = [];
            foreach ($fields as $k => $v) {
                $sets[] = "{$k} = ?";
                $vals[] = $v;
            }
            $vals[] = $id;
            $pdo->prepare("UPDATE packages SET " . implode(', ', $sets) . " WHERE id = ?")
                ->execute($vals);
        } else {
            // Insert
            $cols = array_keys($fields);
            $placeholders = array_fill(0, count($cols), '?');
            $pdo->prepare("INSERT INTO packages (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")")
                ->execute(array_values($fields));
            $id = (int)$pdo->lastInsertId();
        }

        // Save indirect levels
        $pdo->prepare("DELETE FROM package_indirect_levels WHERE package_id = ?")
            ->execute([$id]);
        $st = $pdo->prepare("INSERT INTO package_indirect_levels (package_id, level, bonus) VALUES (?, ?, ?)");
        for ($lvl = 1; $lvl <= 10; $lvl++) {
            $bonus = (float)($data['indirect_levels'][$lvl] ?? 0);
            $st->execute([$id, $lvl, $bonus]);
        }

        return $id;
    }

    public static function delete(int $id): bool
    {
        // Only allow deletion if no members use this package
        $inUse = db()->query("SELECT COUNT(*) FROM users WHERE package_id = {$id}")->fetchColumn();
        if ($inUse > 0) return false;
        db()->prepare('DELETE FROM packages WHERE id = ?')->execute([$id]);
        return true;
    }

    // ── v2 Helpers ─────────────────────────────────────────────────────────

    /**
     * Calculate the lifetime income cap for a user based on their package.
     */
    public static function lifetimeCap(int $packageId): float
    {
        $pkg = self::find($packageId);
        if (!$pkg) return 0;
        return (float)$pkg['entry_fee'] * (float)$pkg['lifetime_cap_multiplier'];
    }

    /**
     * Check if a package has Daily Fixed Income enabled.
     */
    public static function hasDfi(int $packageId): bool
    {
        $pkg = self::find($packageId);
        return $pkg && (float)$pkg['daily_fixed_income'] > 0;
    }

    /**
     * Get DFI settings for a package.
     */
    public static function dfiSettings(int $packageId): array
    {
        $pkg = self::find($packageId);
        if (!$pkg) return ['enabled' => false, 'amount' => 0, 'days' => 0];
        return [
            'enabled' => (float)$pkg['daily_fixed_income'] > 0,
            'amount'  => (float)$pkg['daily_fixed_income'],
            'days'    => (int)$pkg['daily_fixed_income_days'],
        ];
    }

    /**
     * Get reactivation settings for a package.
     */
    public static function reactivationSettings(int $packageId): array
    {
        $pkg = self::find($packageId);
        if (!$pkg) return ['fee' => 0, 'window' => 0];
        return [
            'fee'    => (float)$pkg['reactivation_fee'],
            'window' => (int)$pkg['reactivation_window_days'],
        ];
    }

    /**
     * Check if members on this package earn indirect-referral commissions.
     */
    public static function hasIndirectReferral(int $packageId): bool
    {
        $pkg = self::find($packageId);
        return $pkg ? (int)$pkg['indirect_referral_enabled'] === 1 : false;
    }

    /**
     * Check if members on this package earn Daily Fixed Income.
     */
    public static function hasDfiToggle(int $packageId): bool
    {
        $pkg = self::find($packageId);
        return $pkg ? (int)$pkg['dfi_enabled'] === 1 : false;
    }

    /**
     * Check if members on this package participate in the binary network.
     */
    public static function hasPairing(int $packageId): bool
    {
        $pkg = self::find($packageId);
        return $pkg ? (int)$pkg['pairing_enabled'] === 1 : false;
    }

    /**
     * Get the set of compensation plans enabled on a package, as a normalized
     * mask combining the per-package toggles. DFI counts as active only when
     * both the toggle is on and the daily amount is above zero.
     */
    public static function plans(int $packageId): array
    {
        $pkg = self::find($packageId);
        if (!$pkg) {
            return ['binary' => false, 'indirect' => false, 'dfi' => false];
        }
        return [
            'binary'   => (int)$pkg['pairing_enabled'] === 1,
            'indirect' => (int)$pkg['indirect_referral_enabled'] === 1,
            'dfi'      => (int)$pkg['dfi_enabled'] === 1 && (float)$pkg['daily_fixed_income'] > 0,
        ];
    }

    /**
     * A target package is a valid upgrade only if it keeps every plan the
     * current package already has enabled (source plans ⊆ target plans).
     */
    public static function upgradeCompatible(int $fromId, int $toId): bool
    {
        $fromPlans = self::plans($fromId);
        $toPlans   = self::plans($toId);
        foreach ($fromPlans as $plan => $on) {
            if ($on && empty($toPlans[$plan])) {
                return false;
            }
        }
        return true;
    }
}
