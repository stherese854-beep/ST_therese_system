<?php
// ============================================================
//  UNKNOWN ADDRESS  (not_found.php)
// ============================================================
//  .htaccess sends every page-like address that doesn't exist here
//  (e.g. someone types /admin/dashboard or /any-route). Instead of a
//  bare "Not Found" page:
//    - signed-in user -> their own home page (dashboard / portal)
//    - guest          -> the login page
//  The redirect uses the app's base path (see redirect_to() in
//  config/auth.php), so it works from nested paths without looping.
// ============================================================
require_once 'config/auth.php';

if (is_logged_in()) {
    redirect_to(home_page());
}
redirect_to('login');
