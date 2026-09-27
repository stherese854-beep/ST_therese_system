<?php
// ============================================================
//  LOGOUT  (logout.php)
// ============================================================
require_once 'config/auth.php';                         // session + database
if (is_logged_in()) log_activity($pdo, 'Logged out', ucfirst(current_role() ?? ''));
session_destroy();              // forget the logged-in user
// Session is gone, so we pass the toast message in the URL instead of a flash.
header("Location: login?toast=loggedout");
exit;
?>
