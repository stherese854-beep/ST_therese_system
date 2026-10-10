<?php
// ============================================================
//  ACCOUNT HISTORY  (includes/account_history.php)
// ============================================================
//  The patient portal's "Account History" (portal?view=activity):
//  one plain-words timeline of
//    - their appointments, and those of family members they book for:
//      booked, approved, moved, cancelled, checked in, completed,
//      missed — whether THEY did it or the CLINIC did;
//    - treatments recorded at the clinic;
//    - their own account actions (profile, health form, reviews...);
//    - security: failed sign-ins on their account, in red.
//  Built from the appointments / treatments tables (so the clinic's side
//  is complete) plus this account's own Activity Log entries. Plain
//  "Logged in / Logged out" pairs are left out; the page shows the last
//  sign-in instead.
// ============================================================

// One event: [time, group ('appts' | 'account'), icon, html sentence, level ('' | 'good' | 'warn' | 'danger')]
function ah_event($time, $group, $icon, $html, $level = '') {
    return ['time' => $time, 'group' => $group, 'icon' => $icon, 'html' => $html, 'level' => $level];
}
function ah_when($date, $time = '') {
    $s = date('M j, Y', strtotime($date));
    if ($time !== '' && $time !== null) $s .= ', ' . date('g:i A', strtotime($time));
    return $s;
}
// Was there an entry of $action in the patient's own log within 10 minutes of $time?
function ah_did_it(array $own, $action, $time) {
    if (!$time) return false;
    $t = strtotime($time);
    foreach ($own as $o) if ($o['action'] === $action && abs(strtotime($o['created_at']) - $t) <= 600) return true;
    return false;
}

// Everything for this account, newest first.
function account_history($pdo, $userId, $pid) {
    $events = [];

    // ---- This account's own log entries ----
    $p = [];
    $st = $pdo->prepare("SELECT * FROM activity_log WHERE " . my_activity_where($p) . " ORDER BY created_at DESC, id DESC LIMIT 1500");
    $st->execute($p);
    $own = $st->fetchAll();

    $apptActions = ['Booked appointment', 'Cancelled appointment', 'Rescheduled appointment', 'Patient confirmed attendance',
                    'Duplicate booking blocked'];
    $plain = [
        'Created account'                   => ['🎉', 'You created your account.'],
        'Changed password'                  => ['🔑', 'You changed your password.'],
        'Updated profile'                   => ['👤', 'You updated your profile details.'],
        'Changed profile picture'           => ['🖼️', 'You changed your profile picture.'],
        'Removed profile picture'           => ['🖼️', 'You removed your profile picture.'],
        'Updated health questionnaire'      => ['🩺', 'You updated your health questionnaire.'],
        'Updated booked-for person'         => ['👪', 'You updated the details of someone you book for.'],
        'Submitted review'                  => ['⭐', 'You wrote a review of the clinic.'],
        'Requested a printed dental chart'  => ['🗂', 'You asked the clinic for a printed copy of your dental chart.'],
        'Sent own-account invite'           => ['✉️', 'You invited a family member to have their own account.'],
        'Cancelled own-account invite'      => ['✉️', 'You cancelled an invitation for a family member\'s own account.'],
        'Requested login email change'      => ['📧', 'You asked to change your sign-in email.'],
        'Login email changed'               => ['📧', 'Your sign-in email was changed.'],
        'Downloaded PDF'                    => ['📄', 'You downloaded a PDF.'],
        'Printed page'                      => ['🖨', 'You printed a page.'],
    ];
    $lastSignIns = [];
    foreach ($own as $o) {
        $a = $o['action']; $d = trim((string)$o['details']);
        if ($a === 'Logged in')  { $lastSignIns[] = $o['created_at']; continue; }
        if ($a === 'Logged out' || in_array($a, $apptActions, true)) continue;
        if ($a === 'Failed sign-in') {
            $events[] = ah_event($o['created_at'], 'account', '⚠️',
                '<b>Someone tried to sign in to your account with a wrong password.</b> '
                . ($d !== '' ? '<span class="text-muted2">(' . e(preg_replace('/^Wrong password \((.*)\)$/', '$1', $d)) . ')</span> ' : '')
                . 'If this wasn\'t you, <a href="portal?view=profile#pw-card">change your password</a>.', 'danger');
            continue;
        }
        if (isset($plain[$a])) {
            $events[] = ah_event($o['created_at'], 'account', $plain[$a][0], e($plain[$a][1])
                . (in_array($a, ['Downloaded PDF', 'Printed page'], true) && $d !== '' ? ' <span class="text-muted2">' . e($d) . '</span>' : ''));
            continue;
        }
        // Anything else: the label and its details, as they are.
        $events[] = ah_event($o['created_at'], 'account', '•', e($a) . ($d !== '' ? ' <span class="text-muted2">— ' . e($d) . '</span>' : ''));
    }

    // ---- Appointments of this account (and family members) ----
    $fam = $pid ? family_patient_ids($pdo, $pid) : [];
    if ($fam) {
        $st = $pdo->prepare("SELECT * FROM appointments WHERE patient_id IN (" . in_placeholders($fam) . ")");
        $st->execute($fam);
        foreach ($st->fetchAll() as $a) {
            $for   = (int)$a['patient_id'] !== (int)$pid ? ' for <b>' . e($a['patient_name']) . '</b>' : '';
            $what  = '<b>' . e($a['treatment'] ?: 'visit') . '</b>';
            $when  = '<b>' . ah_when($a['appointment_date'], $a['appointment_time']) . '</b>';
            $dent  = $a['dentist'] ? ' with ' . e($a['dentist']) : '';
            $byClinic = $a['confirmed_at'] && $a['created_at'] && abs(strtotime($a['confirmed_at']) - strtotime($a['created_at'])) <= 60;

            // Booked
            if ($a['created_at']) {
                $events[] = $byClinic
                    ? ah_event($a['created_at'], 'appts', '📅', "The clinic booked a $what$for on $when$dent.", 'good')
                    : ah_event($a['created_at'], 'appts', '📅', "You booked a $what$for on $when$dent.");
            }
            // Approved
            if ($a['confirmed_at'] && !$byClinic) {
                $events[] = ah_event($a['confirmed_at'], 'appts', '✅', "The clinic <b>approved</b> your $what$for on $when$dent.", 'good');
            }
            // You confirmed from the reminder email
            if (!empty($a['patient_confirmed_at'])) {
                $events[] = ah_event($a['patient_confirmed_at'], 'appts', '👍', "You confirmed you will come to the $what$for on $when.");
            }
            // Moved
            if ($a['rescheduled_at']) {
                $mine   = ah_did_it($own, 'Rescheduled appointment', $a['rescheduled_at']);
                $from   = $a['rescheduled_from'] ? ' from ' . e($a['rescheduled_from']) : '';
                $reason = trim((string)$a['reschedule_reason']) !== '' ? ' <span class="text-muted2">— ' . e($a['reschedule_reason']) . '</span>' : '';
                $events[] = $mine
                    ? ah_event($a['rescheduled_at'], 'appts', '🔁', "You moved your $what$for$from to $when.$reason")
                    : ah_event($a['rescheduled_at'], 'appts', '🔁', "The clinic <b>moved</b> your $what$for$from to $when.$reason", 'warn');
            }
            // Cancelled / not accepted
            if ($a['cancelled_at']) {
                $reason = trim((string)$a['cancel_reason']) !== '' ? ' <span class="text-muted2">— ' . e($a['cancel_reason']) . '</span>' : '';
                if ($a['status'] === 'Disapproved') {
                    $events[] = ah_event($a['cancelled_at'], 'appts', '🚫', "The clinic could not accept your $what$for on $when.$reason", 'danger');
                } elseif ($a['cancelled_by'] === 'patient') {
                    $events[] = ah_event($a['cancelled_at'], 'appts', '❌', "You cancelled your $what$for on $when.$reason");
                } else {
                    $events[] = ah_event($a['cancelled_at'], 'appts', '❌', "The clinic <b>cancelled</b> your $what$for on $when.$reason", 'danger');
                }
            }
            // At the clinic
            if (!empty($a['arrived_at'])) {
                $events[] = ah_event($a['arrived_at'], 'appts', '🏥', ($for ? 'Checked in at the clinic' . $for : 'You checked in at the clinic') . " for the $what.");
            }
            $visitTime = $a['appointment_date'] . ' ' . ($a['appointment_time'] ?: '00:00:00');
            if ($a['status'] === 'Completed') {
                $events[] = ah_event($a['arrived_at'] ? date('Y-m-d H:i:s', strtotime($a['arrived_at']) + 1) : $visitTime,
                                     'appts', '🦷', "Your $what$for on $when was <b>completed</b>.", 'good');
            } elseif ($a['status'] === 'No-show') {
                $events[] = ah_event($visitTime, 'appts', '⚠️', "You were marked as <b>missed</b> (no-show) for the $what$for on $when.", 'danger');
            } elseif ($a['status'] === 'Expired') {
                $events[] = ah_event($visitTime, 'appts', '⏳', "Your request for a $what$for on $when <b>expired</b> — it was not approved before the date.", 'warn');
            }
        }

        // ---- Treatments recorded at the clinic ----
        try {
            $st = $pdo->prepare("SELECT * FROM treatments WHERE patient_id IN (" . in_placeholders($fam) . ")");
            $st->execute($fam);
            foreach ($st->fetchAll() as $t) {
                if (!$t['treatment_date']) continue;
                $for   = (int)$t['patient_id'] !== (int)$pid ? ' for <b>' . e($t['patient_name']) . '</b>' : '';
                $tooth = $t['tooth'] ? ' (tooth ' . e($t['tooth']) . ')' : '';
                $by    = $t['dentist'] ? ' by ' . e($t['dentist']) : '';
                $note  = trim((string)$t['notes']) !== '' ? '<br><span class="text-muted2">Note from your dentist: ' . e($t['notes']) . '</span>' : '';
                $txt   = $t['status'] === 'Planned'
                    ? "Your dentist <b>planned</b> a <b>" . e($t['treatment_name']) . "</b>$tooth$for."
                    : "Treatment recorded: <b>" . e($t['treatment_name']) . "</b>$tooth$for$by.";
                $events[] = ah_event($t['treatment_date'] . ' 23:59:00', 'appts', '🪥', $txt . $note, $t['status'] === 'Planned' ? '' : 'good');
            }
        } catch (Throwable $e) {}
    }

    usort($events, fn($x, $y) => strcmp($y['time'], $x['time']));
    // The sign-in before this one (this visit's own sign-in is the newest).
    $lastSignIn = $lastSignIns[1] ?? null;
    return [$events, $lastSignIn];
}

// "Today" / "Yesterday" / "Oct 8, 2026"
function ah_day_label($time) {
    $d = date('Y-m-d', strtotime($time));
    if ($d === date('Y-m-d')) return 'Today';
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    return date('l, M j, Y', strtotime($d));
}

// Called right after a successful sign-in: if someone tried a wrong password
// on this account since the last time they signed in, warn the patient once
// (pop-up in the portal) so they can change it.
function warn_failed_signins($pdo, array $user) {
    try {
        $since = $user['last_login'] ?: '1970-01-01';
        $q = $pdo->prepare("SELECT COUNT(*), MAX(created_at) FROM activity_log
                             WHERE actor_user_id = ? AND action = 'Failed sign-in' AND created_at > ?");
        $q->execute([(int)$user['id'], $since]);
        [$n, $lastAt] = $q->fetch(PDO::FETCH_NUM);
        if (!(int)$n) return;
        $pq = $pdo->prepare("SELECT id FROM patients WHERE user_id = ? LIMIT 1");
        $pq->execute([(int)$user['id']]);
        $pid = (int)$pq->fetchColumn();
        if (!$pid) return;
        require_once __DIR__ . '/patient_notices.php';
        add_patient_notice($pdo, $pid, 'security', 'danger', '⚠️ Someone tried to sign in to your account',
            ((int)$n === 1 ? 'There was 1 attempt' : "There were $n attempts") . ' to sign in with a wrong password since your last visit'
            . ' (latest: ' . date('M j, Y g:i A', strtotime($lastAt)) . ').' . "\n\n"
            . 'If this was not you, please change your password in My Profile. You can see the details in Account History.');
    } catch (Throwable $e) {}
}
