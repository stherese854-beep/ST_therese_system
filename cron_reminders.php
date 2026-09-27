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
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/message_templates.php';

$isCli = (php_sapi_name() === 'cli');
$log   = [];
function out($msg) { global $log, $isCli; $log[] = $msg; if ($isCli) echo $msg . PHP_EOL; }

// Tomorrow's date, e.g. 2026-07-13
$tomorrow = date('Y-m-d', strtotime('+1 day'));
out("Looking for appointments on $tomorrow ...");

if (!mail_is_ready($pdo)) {
    out("STOPPED: email is not set up. An admin must fill in Messaging Config first.");
    if (!$isCli) { echo "<pre>" . implode("\n", $log) . "</pre>"; }
    exit;
}

// Appointments tomorrow that are still active and have NOT been reminded yet.
$stmt = $pdo->prepare(
    "SELECT a.*, p.email AS patient_email, p.name AS account_name
     FROM appointments a
     LEFT JOIN patients p ON a.patient_id = p.id
     WHERE a.appointment_date = ?
       AND a.status IN ('Pending','Confirmed')
       AND (a.reminder_sent = 0 OR a.reminder_sent IS NULL)"
);
$stmt->execute([$tomorrow]);
$rows = $stmt->fetchAll();

out("Found " . count($rows) . " appointment(s) needing a reminder.");

$sent = 0; $failed = 0; $skipped = 0;

foreach ($rows as $a) {
    $to = $a['patient_email'] ?? '';
    if (!$to) {
        out("  - SKIP {$a['patient_name']}: no email address on file.");
        $skipped++;
        continue;
    }

    // If the booking was made for someone else, say so in the email.
    $forLine = '';
    if (($a['booked_for'] ?? '') === 'Someone else') {
        $forLine = "<strong>Patient:</strong> " . htmlspecialchars($a['patient_name'])
                 . ($a['relationship'] ? " (your " . htmlspecialchars(strtolower($a['relationship'])) . ")" : '')
                 . "<br>";
    }

    $statusNote = ($a['status'] === 'Pending')
        ? '<span style="color:#c08a2e;font-weight:700;">PENDING</span> — the clinic will confirm this shortly.'
        : '<span style="color:#138a4e;font-weight:700;">CONFIRMED</span>';

    $cat = message_catalogue()['reminder'];
    [$remSubj, $body] = tpl_message($pdo, 'reminder', $cat['subject'], $cat['body'], [
        'patient'   => $a['account_name'] ?: $a['patient_name'],
        'date'      => date('l, F j, Y', strtotime($a['appointment_date'])),
        'time'      => $a['appointment_time'],
        'treatment' => $a['treatment'] ?: 'Consultation',
        'dentist'   => $a['dentist'] ?: 'To be assigned',
        'clinic'    => clinic_name($pdo),
    ]);

    $err = '';
    if (send_mail($pdo, $to, $remSubj, $body, $err, 'reminder')) {
        $pdo->prepare("UPDATE appointments SET reminder_sent = 1 WHERE id = ?")->execute([$a['id']]);
        out("  ✓ Reminder sent to $to ({$a['patient_name']})");
        $sent++;
    } else {
        out("  ✗ FAILED for $to: $err");
        $failed++;
    }
}

out("");
out("Done. Sent: $sent | Failed: $failed | Skipped (no email): $skipped");

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
