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
}
