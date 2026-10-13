<?php
// ============================================================
//  STILL HERE?  (keepalive.php)
// ============================================================
//  Called by the idle timer in includes/head.php (dentist, staff, admin).
//   keepalive          -> counts as activity (config/auth.php stamps it)
//   keepalive?peek=1   -> only asks how long the account has been idle,
//                         across all tabs, without counting as activity
//  The 15-minute sign-out itself happens in config/auth.php.
// ============================================================
require_once 'config/auth.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!is_logged_in()) { echo json_encode(['signed_out' => true]); exit; }
echo json_encode(['idle' => max(0, time() - (int)($_SESSION['last_active'] ?? time())), 'limit' => IDLE_SIGNOUT_MINUTES * 60]);
