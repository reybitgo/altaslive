-- Migration 008: Fix withdrawable_balance for existing data
-- Before this feature, all e-wallet funds came from commissions (withdrawable).
-- After transfer/top-up was introduced, those new credits are non-withdrawable.
-- This migration computes the correct withdrawable_balance for all users.

-- Step 1: Assume all existing balance is withdrawable (commissions/DFI only existed before)
UPDATE users SET withdrawable_balance = ewallet_balance;

-- Step 2: Subtract all top-up amounts from recipients (top-ups are non-withdrawable)
UPDATE users u
SET u.withdrawable_balance = u.withdrawable_balance - (
    SELECT COALESCE(SUM(amount), 0)
    FROM ewallet_admin_topups
    WHERE recipient_id = u.id
)
WHERE EXISTS (
    SELECT 1 FROM ewallet_admin_topups WHERE recipient_id = u.id
);

-- Step 3: Subtract all transfer-in amounts from recipients (transfers in are non-withdrawable)
UPDATE users u
SET u.withdrawable_balance = u.withdrawable_balance - (
    SELECT COALESCE(SUM(amount), 0)
    FROM ewallet_transfers
    WHERE recipient_id = u.id AND status = 'completed'
)
WHERE EXISTS (
    SELECT 1 FROM ewallet_transfers WHERE recipient_id = u.id AND status = 'completed'
);

-- Step 4: Safety clamp — ensure withdrawable_balance never exceeds ewallet_balance and never goes negative
UPDATE users
SET withdrawable_balance = LEAST(withdrawable_balance, ewallet_balance)
WHERE withdrawable_balance > ewallet_balance;

UPDATE users
SET withdrawable_balance = 0
WHERE withdrawable_balance < 0;
