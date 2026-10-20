<?php
// ============================================================
//  LOGOUT  (logout.php)
// ============================================================
require_once 'config/auth.php';                         // session + database
$idle = isset($_GET['idle']);   // the 15-minute idle timer (includes/head.php)
if (is_logged_in()) log_activity($pdo, $idle ? 'Logged out (inactive)' : 'Logged out', $idle ? 'No activity for ' . IDLE_SIGNOUT_MINUTES . ' minutes' : ucfirst(current_role() ?? ''));
session_unset();                // empty the session data
session_destroy();              // and delete the session itself (the user is signed out)
// Session is gone, so we pass the toast message in the URL instead of a flash.
header("Location: login?toast=" . ($idle ? 'idle' : 'loggedout'));
exit;
?>
