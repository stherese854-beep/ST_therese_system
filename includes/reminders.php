<?php
// ============================================================
//  APPOINTMENT REMINDERS  (includes/reminders.php)
// ============================================================
//  Emails each patient the day before their appointment, with
//  "Yes, I'll be there" and "I can't make it" buttons.
//
//  WHY IT RUNS ON PAGE LOAD
//  The site has no scheduler (neither XAMPP nor Railway runs a nightly
//  job here), so — like the no-show scan — the first page opened each
//  day sends that day's reminders. Each appointment is reminded once
//  (appointments.reminder_sent). cron_reminders.php can still run it
//  by hand or from a real scheduler.
//
//  THE BUTTONS
//  Each link carries a signed token (HMAC with a secret kept in
//  settings), so nobody can confirm or cancel someone else's visit by
//  changing the id. The link only OPENS reminder_reply.php; the patient
//  then presses a button there. (Email apps and virus scanners "click"
//  links to preview them — that must never cancel an appointment.)
// ============================================================
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/message_templates.php';

// A random secret made once for this installation.
function app_secret($pdo) {
    static $s = null;
    if ($s !== null) return $s;
    try {
        $s = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='app_secret'")->fetchColumn();
        if (!$s) {
            $s = bin2hex(random_bytes(32));
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('app_secret', ?)")->execute([$s]);
        }
    } catch (Throwable $e) { $s = ''; }
    return $s;
}

function reminder_token($pdo, $apptId, $action) {
    return substr(hash_hmac('sha256', (int)$apptId . '|' . $action, app_secret($pdo)), 0, 32);
}
function reminder_token_ok($pdo, $apptId, $action, $token) {
    $secret = app_secret($pdo);
    return $secret !== '' && is_string($token) && hash_equals(reminder_token($pdo, $apptId, $action), $token);
}

// The site's public address, for links in emails. Learned from real web
// requests (so it is right on XAMPP and on Railway) and remembered for
// runs without one (command line).
function site_base_url($pdo) {
    if (!empty($_SERVER['HTTP_HOST'])) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $url = ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
             . (function_exists('app_base') ? app_base() : rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/'));
        try {                                           // remember it (only write when it changes)
            $known = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='site_base_url'")->fetchColumn();
            if ($known !== $url) save_setting($pdo, 'site_base_url', $url);
        } catch (Throwable $e) {}
        return $url;
    }
    try { return (string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key='site_base_url'")->fetchColumn(); }
    catch (Throwable $e) { return ''; }
}

// Sends every reminder that is due now. Returns [sent, failed, skipped, log lines].
//   due = tomorrow's active appointments, plus today's later ones that were
//         never reminded (e.g. nobody opened the site yesterday).
function send_due_reminders($pdo) {
    $log = []; $sent = $failed = $skipped = 0;
    if (!mail_is_ready($pdo)) {
        return [0, 0, 0, ['Email is not set up yet (Messaging Config), so no reminders were sent.']];
    }
    $today    = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $base     = site_base_url($pdo);

    $st = $pdo->prepare(
        "SELECT a.*, COALESCE(NULLIF(p.email,''), g.email) AS patient_email, COALESCE(g.name, p.name) AS account_name
           FROM appointments a
      LEFT JOIN patients p ON a.patient_id = p.id
      LEFT JOIN patients g ON g.id = p.guardian_patient_id
          WHERE a.appointment_date IN (?, ?)
            AND a.status IN ('Pending','Confirmed')
            AND (a.reminder_sent = 0 OR a.reminder_sent IS NULL)"
    );
    $st->execute([$today, $tomorrow]);

    foreach ($st->fetchAll() as $a) {
        // Today's appointment whose time has already passed: too late to remind.
        if ($a['appointment_date'] === $today && strtotime($today . ' ' . $a['appointment_time']) <= time()) continue;

        $to = $a['patient_email'] ?? '';
        if (!$to) { $skipped++; $log[] = "SKIP {$a['patient_name']}: no email address on file."; continue; }

        $cat  = message_catalogue()['reminder'];
        $vars = [
            'patient'   => $a['account_name'] ?: $a['patient_name'],
            'date'      => date('l, F j, Y', strtotime($a['appointment_date'])),
            'time'      => $a['appointment_time'],
            'treatment' => $a['treatment'] ?: 'Consultation',
            'dentist'   => $a['dentist'] ?: 'To be assigned',
            'clinic'    => clinic_name($pdo),
        ];
        $subject = tpl_fill(tpl($pdo, 'reminder', 'subject', $cat['subject']), $vars);
        $bodyTxt = tpl_fill(tpl($pdo, 'reminder', 'body', $cat['body']), $vars);
        if (($a['booked_for'] ?? '') === 'Someone else') {
            $bodyTxt = "Patient: " . $a['patient_name'] . ($a['relationship'] ? ' (your ' . strtolower($a['relationship']) . ')' : '')
                     . "\n\n" . $bodyTxt;
        }
        $buttons = '';
        if ($base !== '') {
            $link = fn($do) => $base . '/reminder_reply?id=' . (int)$a['id'] . '&do=' . $do . '&t=' . reminder_token($pdo, $a['id'], $do);
            $buttons = '<div style="margin:22px 0 4px;">'
                . '<a href="' . htmlspecialchars($link('confirm')) . '" style="background:#0f766e;color:#fff;text-decoration:none;padding:12px 22px;border-radius:100px;font-weight:600;display:inline-block;margin:0 8px 8px 0;">✓ Yes, I\'ll be there</a>'
                . '<a href="' . htmlspecialchars($link('cancel')) . '" style="background:#ffffff;color:#c0392b;border:1px solid #e3b4ae;text-decoration:none;padding:11px 22px;border-radius:100px;font-weight:600;display:inline-block;">✗ I can\'t make it</a>'
                . '</div>';
        }
        $body = mail_template($subject, nl2br(htmlspecialchars($bodyTxt)) . $buttons);

        $err = '';
        if (send_mail($pdo, $to, $subject, $body, $err, 'reminder')) {
            $pdo->prepare("UPDATE appointments SET reminder_sent = 1 WHERE id = ?")->execute([$a['id']]);
            $sent++; $log[] = "✓ Reminder sent to $to ({$a['patient_name']}, {$a['appointment_date']} {$a['appointment_time']})";
        } else {
            $failed++; $log[] = "✗ FAILED for $to: $err";
        }
    }
    $log[] = "Done. Sent: $sent | Failed: $failed | Skipped (no email): $skipped";
    return [$sent, $failed, $skipped, $log];
}

// Once a day, on the first page load (see includes/head.php).
function run_daily_reminders($pdo) {
    if (!empty($_SERVER['HTTP_HOST'])) site_base_url($pdo);   // keep the address for email links up to date
    $today = date('Y-m-d');
    try {
        $last = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='reminders_last_run'")->fetchColumn();
        if ($last === $today) return;
        save_setting($pdo, 'reminders_last_run', $today);   // mark first, so two visitors can't both send
    } catch (Throwable $e) { return; }
    @set_time_limit(60);
    try { send_due_reminders($pdo); } catch (Throwable $e) { /* never break the page */ }
}
