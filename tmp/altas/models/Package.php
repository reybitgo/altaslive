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

    public static function allPaginated(int $page = 1, int $perPage = 25): array
    {
        return paginate('SELECT * FROM packages ORDER BY entry_fee ASC', [], $page, $perPage);
    }

    /**
     * Return the Phase-4 indirect-referral percentages (pv_pct) per level.
     * Legacy fixed-peso `bonus` column is no longer used by the engine.
     */
    public static function getIndirectLevels(int $packageId): array
    {
        $st = db()->prepare(
            'SELECT level, pv_pct FROM package_indirect_levels WHERE package_id = ? ORDER BY level'
        );
        $st->execute([$packageId]);
        $rows   = $st->fetchAll();
        $result = [];
        foreach ($rows as $r) {
            $result[(int)$r['level']] = (float)$r['pv_pct'];
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
     * @param array $data Package data including v2/v3 fields:
     *   - name, entry_fee, package_pv_rate, binary_pv_pct, daily_pair_pv_cap,
     *     direct_ref_pv_pct, status
     *   - lifetime_cap_multiplier, reactivation_fee, reactivation_window_days
     *   - daily_fixed_income, daily_fixed_income_days, dfi_pv_pct
     *   - indirect_levels[1..10]
     * @param int|null $id Package ID for update, null for create
     */
    public static function save(array $data, ?int $id = null): int
    {
        $pdo = db();

        $fields = [
            'name'                     => $data['name'],
            'entry_fee'                => (float)($data['entry_fee'] ?? 0),
            'package_pv_rate'          => (float)($data['package_pv_rate'] ?? 10.00),
            'binary_pv_pct'            => (float)($data['binary_pv_pct'] ?? 20.00),
            'daily_pair_pv_cap'        => (float)($data['daily_pair_pv_cap'] ?? 0),
            'direct_ref_pv_pct'        => (float)($data['direct_ref_pv_pct'] ?? 0),
            // v2 fields
            'lifetime_cap_multiplier'  => (float)($data['lifetime_cap_multiplier'] ?? 3.00),
            'reactivation_fee'         => (float)($data['reactivation_fee'] ?? 0),
            'reactivation_window_days' => (int)($data['reactivation_window_days'] ?? 15),
            'daily_fixed_income'       => (float)($data['daily_fixed_income'] ?? 0),
            'daily_fixed_income_days'  => (int)($data['daily_fixed_income_days'] ?? 90),
            'dfi_pv_pct'               => (float)($data['dfi_pv_pct'] ?? 0),
            'personal_pv_requirement'  => (float)($data['personal_pv_requirement'] ?? 0.00),
            'pairing_bonus'            => 0.00, // legacy field
            'status'                   => $data['status'] ?? 'active',
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
        $st = $pdo->prepare("INSERT INTO package_indirect_levels (package_id, level, pv_pct) VALUES (?, ?, ?)");
        for ($lvl = 1; $lvl <= 10; $lvl++) {
            $pvPct = (float)($data['indirect_levels'][$lvl] ?? 0);
            $st->execute([$id, $lvl, $pvPct]);
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
     * Return the Package PV for a given package.
     * package_pv_rate now stores the absolute PV amount directly
     * (not a percentage of entry fee). Used as the basis for
     * direct/indirect/DFI and binary PV calculations.
     */
    public static function packagePv(int $packageId): float
    {
        $pkg = self::find($packageId);
        if (!$pkg) return 0.00;
        return (float)$pkg['package_pv_rate'];
    }

    /**
     * Calculate the Binary PV allocated for a given package.
     * Binary PV = Package PV × (binary_pv_pct / 100)
     * This is the amount that flows into the binary tree on registration.
     */
    public static function binaryPackagePv(int $packageId): float
    {
        return self::packagePv($packageId) * ((float)self::find($packageId)['binary_pv_pct'] / 100);
    }

    /**
     * Calculate the peso pairing bonus for a given paired PV amount.
     * Pairing now uses the global PV-per-peso conversion rate only.
     * Bonus = paired_pv × pv_per_peso_rate
     */
    public static function pairingBonus(float $pairedPv, int $packageId): float
    {
        if ($pairedPv <= 0.00) return 0.00;
        $rate = (float)setting('pv_per_peso_rate', '1.0000');
        return $pairedPv * $rate;
    }

    /**
     * Calculate the peso direct-referral bonus for a given package PV amount.
     * Bonus = package_pv × (direct_ref_pv_pct / 100) × pv_per_peso_rate
     */
    public static function directReferralBonus(float $packagePv, int $packageId): float
    {
        $pkg = self::find($packageId);
        if (!$pkg || (float)$pkg['direct_ref_pv_pct'] <= 0) return 0.00;
        $rate = (float)setting('pv_per_peso_rate', '1.0000');
        return $packagePv * ((float)$pkg['direct_ref_pv_pct'] / 100) * $rate;
    }

    /**
     * Calculate the peso indirect-referral bonus for a given package PV amount and level %.
     * Bonus = package_pv × (pv_pct / 100) × pv_per_peso_rate
     */
    public static function indirectReferralBonus(float $packagePv, float $pvPct): float
    {
        if ($pvPct <= 0) return 0.00;
        $rate = (float)setting('pv_per_peso_rate', '1.0000');
        return $packagePv * ($pvPct / 100) * $rate;
    }

    /**
     * Daily paired-PV cap for a package.
     */
    public static function dailyPairPvCap(int $packageId): float
    {
        $pkg = self::find($packageId);
        return $pkg ? (float)$pkg['daily_pair_pv_cap'] : 0.00;
    }

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
     * Calculate the peso Daily Fixed Income for a package.
     * If dfi_pv_pct > 0, use package_pv * pct * pv_per_peso_rate.
     * Otherwise fall back to the fixed daily_fixed_income amount.
     */
    public static function dailyFixedIncome(int $packageId): float
    {
        $pkg = self::find($packageId);
        if (!$pkg) return 0.00;

        $dfiPvPct = (float)$pkg['dfi_pv_pct'];
        if ($dfiPvPct > 0) {
            $packagePv = self::packagePv($packageId);
            $rate      = (float)setting('pv_per_peso_rate', '1.0000');
            return $packagePv * ($dfiPvPct / 100) * $rate;
        }

        return (float)$pkg['daily_fixed_income'];
    }

    /**
     * Check if a package has Daily Fixed Income enabled.
     */
    public static function hasDfi(int $packageId): bool
    {
        $pkg = self::find($packageId);
        if (!$pkg) return false;
        return self::dailyFixedIncome($packageId) > 0.00
            && (int)$pkg['daily_fixed_income_days'] > 0;
    }

    /**
     * Get DFI settings for a package.
     */
    public static function dfiSettings(int $packageId): array
    {
        $pkg = self::find($packageId);
        if (!$pkg) return ['enabled' => false, 'amount' => 0, 'days' => 0];
        return [
            'enabled' => self::hasDfi($packageId),
            'amount'  => self::dailyFixedIncome($packageId),
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
}
