<?php

class Royalty
{
    /**
     * Process royalty bonus after a repeat purchase.
     * For each qualifying upline, pays:
     *   group_bonus  = rank%_group  × totalPv × pv_per_peso_rate
     *   repeat_bonus = rank%_repeat × purchaseAmount
     *
     * This correctly handles incremental payouts because each purchase adds
     * exactly totalPv to each upline's group_pv (if they meet the gate) and
     * contributes purchaseAmount to the repeat net income pool.
     */
    public static function processRepeatPurchase(
        int $buyerId,
        float $totalPv,
        float $purchaseAmount
    ): void {
        if (setting('royalty_enabled', '0') !== '1') return;
        if ($totalPv <= 0) return;

        $pdo  = db();
        $rate = (float)setting('pv_per_peso_rate', '1000.0000');

        $qaDirects  = (int)setting('royalty_qa_directs', '3');
        $qaPersonal = (float)setting('royalty_qa_personal_pv', '200');
        $qaGroup    = (float)setting('royalty_qa_group_pv', '1000');

        $pcts = [
            'supervisor' => ['g' => (float)setting('royalty_supervisor_group_pct', '3'),  'r' => (float)setting('royalty_supervisor_repeat_pct', '5')],
            'manager'    => ['g' => (float)setting('royalty_manager_group_pct', '5'),     'r' => (float)setting('royalty_manager_repeat_pct', '10')],
            'director'   => ['g' => (float)setting('royalty_director_group_pct', '10'),   'r' => (float)setting('royalty_director_repeat_pct', '15')],
            'chairman'   => ['g' => (float)setting('royalty_chairman_group_pct', '12'),   'r' => (float)setting('royalty_chairman_repeat_pct', '20')],
        ];

        // Walk sponsor chain upward from buyer
        $uplineIds = [];
        $cur = (int)$pdo->query("SELECT sponsor_id FROM users WHERE id = {$buyerId}")->fetchColumn();
        $visited = [$buyerId => true];
        while ($cur > 0 && !isset($visited[$cur])) {
            $uplineIds[] = $cur;
            $visited[$cur] = true;
            $cur = (int)$pdo->query("SELECT sponsor_id FROM users WHERE id = {$cur}")->fetchColumn();
        }
        // Include buyer (their personal_pv changed)
        array_unshift($uplineIds, $buyerId);

        foreach ($uplineIds as $uid) {
            $member = $pdo->prepare("
                SELECT u.id, u.status, u.personal_pv, u.group_pv,
                       u.capping_bypass, u.cap_status,
                       (SELECT COUNT(*) FROM users WHERE sponsor_id = ? AND role = 'member') AS direct_count
                FROM users u WHERE u.id = ?
            ");
            $member->execute([$uid, $uid]);
            $m = $member->fetch();
            if (!$m || $m['status'] !== 'active') continue;

            $directCount = (int)$m['direct_count'];
            if ($directCount < $qaDirects) continue;

            $personalPv = (float)$m['personal_pv'];
            $groupPv    = (float)$m['group_pv'];

            // QA gate: personal OR group
            if ($personalPv < $qaPersonal && $groupPv < $qaGroup) continue;

            // Determine highest rank
            $rank = self::highestRank($uid, $directCount);
            if (!$rank || $rank === 'qa') continue;

            $pct = $pcts[$rank] ?? null;
            if (!$pct) continue;

            // Calculate incremental bonus for this purchase
            $groupBonus  = ($pct['g'] / 100) * $totalPv * $rate;
            $repeatBonus = ($pct['r'] / 100) * $purchaseAmount;
            $totalBonus  = $groupBonus + $repeatBonus;

            if ($totalBonus <= 0) continue;

            // Cap check
            $capCheck = CapEngine::canEarn($uid, $totalBonus);
            $actualWallet = $capCheck['allowed'];
            $capBlocked   = $capCheck['blocked'];
            if ($actualWallet <= 0) continue;

            // Record commission
            $pdo->prepare("
                INSERT INTO commissions
                  (user_id, type, amount, cap_deduction, source_user_id, description, status)
                VALUES (?, 'royalty', ?, ?, ?, ?, 'credited')
            ")->execute([
                $uid, $totalBonus, $capBlocked, $buyerId,
                "Royalty {$rank}: {$pct['g']}% group × {$totalPv} PV + {$pct['r']}% repeat"
            ]);

            $commId = (int)$pdo->lastInsertId();

            Ewallet::credit($uid, $actualWallet, $commId, 'commission', "Royalty bonus ({$rank})");

            if ($capBlocked > 0) {
                self::recordCapBlocked($uid, $capBlocked, $buyerId, $rank);
            }

            CapEngine::recordEarning($uid, $actualWallet, 'royalty');
            $pdo->prepare("UPDATE users SET rank_royalty = ? WHERE id = ?")->execute([$rank, $uid]);
        }
    }

    /**
     * Determine the highest royalty rank for a member.
     * Uses dynamic qualification (not stored rank_royalty).
     */
    private static function highestRank(int $userId, int $directCount): ?string
    {
        $qaDirects = (int)setting('royalty_qa_directs', '3');
        if ($directCount < $qaDirects) return null;

        $pdo = db();
        $qaPersonal = (float)setting('royalty_qa_personal_pv', '200');
        $qaGroup    = (float)setting('royalty_qa_group_pv', '1000');

        // Count QA legs among direct referrals
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM users
            WHERE sponsor_id = ? AND role = 'member' AND status = 'active'
            AND (personal_pv >= ? OR group_pv >= ?)
        ");
        $st->execute([$userId, $qaPersonal, $qaGroup]);
        $qaLegs = (int)$st->fetchColumn();

        // Count Supervisor legs
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM users
            WHERE sponsor_id = ? AND role = 'member' AND status = 'active'
            AND (SELECT COUNT(*) FROM users WHERE sponsor_id = users.id AND role = 'member') >= 10
            AND (
                SELECT COUNT(*) FROM users sub
                WHERE sub.sponsor_id = users.id AND sub.role = 'member' AND sub.status = 'active'
                AND (sub.personal_pv >= ? OR sub.group_pv >= ?)
            ) >= 5
        ");
        $st->execute([$userId, $qaPersonal, $qaGroup]);
        $supLegs = (int)$st->fetchColumn();

        // Check ranks from highest to lowest
        // Chairman: 3 Director legs
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM users
            WHERE sponsor_id = ? AND role = 'member' AND status = 'active'
            AND (
                SELECT COUNT(*) FROM users mgr
                WHERE mgr.sponsor_id = users.id AND mgr.role = 'member' AND mgr.status = 'active'
                AND (
                    SELECT COUNT(*) FROM users dir
                    WHERE dir.sponsor_id = mgr.id AND dir.role = 'member' AND dir.status = 'active'
                    AND (
                        SELECT COUNT(*) FROM users ch
                        WHERE ch.sponsor_id = dir.id AND ch.role = 'member' AND ch.status = 'active'
                        AND (ch.personal_pv >= ? OR ch.group_pv >= ?)
                    ) >= 3
                    AND (
                        SELECT COUNT(*) FROM users sup2
                        WHERE sup2.sponsor_id = mgr.id AND sup2.role = 'member' AND sup2.status = 'active'
                        AND (
                            SELECT COUNT(*) FROM users qa3
                            WHERE qa3.sponsor_id = sup2.id AND qa3.role = 'member' AND qa3.status = 'active'
                            AND (qa3.personal_pv >= ? OR qa3.group_pv >= ?)
                        ) >= 5
                        AND (SELECT COUNT(*) FROM users qa3 WHERE qa3.sponsor_id = sup2.id) >= 10
                    ) >= 3
                )
                AND (
                    SELECT COUNT(*) FROM users sup
                    WHERE sup.sponsor_id = users.id AND sup.role = 'member' AND sup.status = 'active'
                    AND (
                        SELECT COUNT(*) FROM users qa2
                        WHERE qa2.sponsor_id = sup.id AND qa2.role = 'member' AND qa2.status = 'active'
                        AND (qa2.personal_pv >= ? OR qa2.group_pv >= ?)
                    ) >= 5
                    AND (SELECT COUNT(*) FROM users qa2 WHERE qa2.sponsor_id = sup.id) >= 10
                ) >= 3
            )
        ");
        $st->execute([$userId, $qaPersonal, $qaGroup, $qaPersonal, $qaGroup, $qaPersonal, $qaGroup]);
        $chairmanLegs = (int)$st->fetchColumn();
        if ($chairmanLegs >= 3) return 'chairman';

        // Director: 3 Manager legs
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM users
            WHERE sponsor_id = ? AND role = 'member' AND status = 'active'
            AND (
                SELECT COUNT(*) FROM users sup2
                WHERE sup2.sponsor_id = users.id AND sup2.role = 'member' AND sup2.status = 'active'
                AND (
                    SELECT COUNT(*) FROM users qa3
                    WHERE qa3.sponsor_id = sup2.id AND qa3.role = 'member' AND qa3.status = 'active'
                    AND (qa3.personal_pv >= ? OR qa3.group_pv >= ?)
                ) >= 5
                AND (SELECT COUNT(*) FROM users qa3 WHERE qa3.sponsor_id = sup2.id) >= 10
            ) >= 3
        ");
        $st->execute([$userId, $qaPersonal, $qaGroup]);
        $mgrLegs = (int)$st->fetchColumn();
        if ($mgrLegs >= 3) return 'director';

        // Manager: 3 Supervisor legs
        if ($supLegs >= 3) return 'manager';

        // Supervisor: 10 directs + 5 QA legs
        if ($directCount >= 10 && $qaLegs >= 5) return 'supervisor';

        // QA
        return 'qa';
    }

    public static function rankLabel(?string $rank): string
    {
        return match ($rank) {
            'qa'         => 'Qualified Associate',
            'supervisor' => 'Supervisor',
            'manager'    => 'Manager',
            'director'   => 'Director',
            'chairman'   => 'Chairman',
            default      => '—',
        };
    }

    public static function rankStyle(?string $rank): array
    {
        return match ($rank) {
            'qa'         => ['badge' => 'bg-secondary-subtle text-secondary', 'icon' => '🟡'],
            'supervisor' => ['badge' => 'bg-info-subtle text-info',          'icon' => '🥉'],
            'manager'    => ['badge' => 'bg-primary-subtle text-primary',    'icon' => '🥈'],
            'director'   => ['badge' => 'bg-warning-subtle text-warning',    'icon' => '🥇'],
            'chairman'   => ['badge' => 'bg-danger-subtle text-danger',      'icon' => '👑'],
            default      => ['badge' => 'bg-light text-muted',               'icon' => '⚪'],
        };
    }

    private static function recordCapBlocked(int $userId, float $amount, int $sourceId, string $rank): void
    {
        db()->prepare("
            INSERT INTO commissions
              (user_id, type, amount, cap_deduction, source_user_id, description, status)
            VALUES (?, 'royalty', 0.00, ?, ?, ?, 'flushed')
        ")->execute([$userId, $amount, $sourceId, "Royalty {$rank} blocked — lifetime cap reached"]);
    }
}
