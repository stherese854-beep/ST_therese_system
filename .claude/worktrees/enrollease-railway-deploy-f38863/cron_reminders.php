<?php
// ============================================================
//  24-HOUR APPOINTMENT REMINDERS  (cron_reminders.php)
// ============================================================
//  Emails every patient whose appointment is TOMORROW.
//
//  It only ever sends ONE reminder per appointment: after sending,
//  the row is marked  reminder_sent = 1  so running this twice does
//  not spam anybody.
//
//  HOW TO RUN IT AUTOMATICALLY EVERY DAY
//  -------------------------------------
//  Windows (XAMPP) - Task Scheduler:
//     1. Open "Task Scheduler" > Create Basic Task
//     2. Trigger: Daily, e.g. 8:00 AM
//     3. Action: Start a program
//          Program:   C:\xampp\php\php.exe
//          Arguments: C:\xampp\htdocs\dental-clinic\cron_reminders.php
//
//  Linux / macOS - cron:
//     crontab -e   then add:
//     0 8 * * *  /usr/bin/php /path/to/htdocs/dental-clinic/cron_reminders.php
//
//  An ADMIN can also run it by hand from the browser (must be logged in):
//     http://localhost/dental-clinic/cron_reminders.php
// ============================================================

if (php_sapi_name() !== 'cli') {
    // From the web, only a logged-in admin may trigger the mailer.
    require_once __DIR__ . '/config/auth.php';
    require_login(['admin']);
}
require_once __DIR__ . '/config/db.php';
if (php_sapi_name() === 'cli') { require_once __DIR__ . '/config/auth.php'; }   // schema + helpers
require_once __DIR__ . '/includes/reminders.php';

$isCli = (php_sapi_name() === 'cli');
// Same sender the site uses once a day on page load (includes/reminders.php).
[$sent, $failed, $skipped, $log] = send_due_reminders($pdo);
if ($isCli) echo implode(PHP_EOL, $log) . PHP_EOL;

// When opened in a browser, print a small readable report.
if (!$isCli) {
    echo '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:640px;margin:40px auto;
                     background:#fff;border:1px solid #dbe8e6;border-radius:14px;padding:24px;">
            <h2 style="margin:0 0 4px;color:#0f766e;">24-Hour Reminder Run</h2>
            <div style="color:#5c706e;font-size:.9rem;margin-bottom:16px;">' . date('D, M j, Y g:i A') . '</div>
            <pre style="background:#f4f8f8;padding:14px;border-radius:8px;font-size:.85rem;
                        white-space:pre-wrap;">' . htmlspecialchars(implode("\n", $log)) . '</pre>
            <a href="dashboard" style="color:#0f766e;font-weight:600;">← Back to dashboard</a>
          </div>';
}
