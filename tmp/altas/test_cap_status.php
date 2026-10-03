<?php
require_once 'config/db.php';
require_once 'core/CapEngine.php';

$userId = 2; // Replace with actual ID
$status = CapEngine::getCapStatus($userId);

echo "Cap Status for User {$userId}:
";
echo "  lifetime_earned:      " . $status['lifetime_earned'] . "
";
echo "  lifetime_cap:         " . $status['lifetime_cap'] . "
";
echo "  remaining:            " . $status['remaining'] . "
";
echo "  cap_status:           " . $status['cap_status'] . "
";
echo "  capped_at:            " . ($status['capped_at'] ?? 'NULL') . "
";
echo "  dfi_days_used:        " . $status['dfi_days_used'] . "
";
echo "  dfi_active:           " . $status['dfi_active'] . "
";
echo "  reactivation_fee:     " . $status['reactivation_fee'] . "
";
echo "  reactivation_window:  " . $status['reactivation_window'] . "
";
