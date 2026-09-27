<?php
// ============================================================
//  LOGOUT  (logout.php)
// ============================================================
session_start();
session_destroy();              // forget the logged-in user
// Session is gone, so we pass the toast message in the URL instead of a flash.
header("Location: login.php?toast=loggedout");
exit;
?>
