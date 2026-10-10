<?php
// ============================================================
//  LOG A PRINT / PDF  (log_event.php)
// ============================================================
//  The "Print / PDF" menu (print_menu() in config/auth.php) calls this
//  when someone prints a page or downloads it as a PDF, so the Activity
//  Log shows who took patient data out of the system, and when.
//  Only signed-in users; POST with the CSRF token (checked in auth.php).
// ============================================================
require_once 'config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_logged_in()) {
    http_response_code(403); exit;
}
$kind  = ($_POST['kind'] ?? '') === 'pdf' ? 'Downloaded PDF' : 'Printed page';
$page  = trim(mb_substr((string)($_POST['page'] ?? ''), 0, 120));
$file  = trim(mb_substr((string)($_POST['file'] ?? ''), 0, 120));
log_activity($pdo, $kind, $page . ($file !== '' && $kind === 'Downloaded PDF' ? ' — ' . $file . '.pdf' : ''));
http_response_code(204);
