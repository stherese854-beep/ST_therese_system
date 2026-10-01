<?php
// ============================================================
//  FOLLOW-UPS / TREATMENT PLANS  (includes/followups.php)
// ============================================================
//  For treatments that need the patient to come back: braces
//  adjustments, root canal sessions, implant checks, 6-month check-ups.
//
//  * The DENTIST ticks "Needs follow-up" when recording a treatment
//    (Records → Treatments): how often, and how many sessions (optional).
//  * Recording the same treatment again counts as the next session and
//    moves the due date forward; after the last session the plan is Completed.
//  * STAFF see "Follow-ups due" (Dashboard + bell): due within 7 days or
//    overdue, with no appointment booked yet — and a one-click Book button.
//  * The PATIENT sees "Braces adjustment — session 6 of 24 — next due Oct 15"
//    in My Records, and gets an email ~5 days before if they have not booked.
// ============================================================

const FOLLOWUP_INTERVALS = [
    7   => 'In 1 week',
    14  => 'In 2 weeks',
    28  => 'Every 4 weeks (e.g. braces adjustment)',
    90  => 'In 3 months',
    180 => 'In 6 months (check-up)',
];
define('FOLLOWUP_DUE_WINDOW', 7);        // staff list: due within this many days (or overdue)
define('FOLLOWUP_REMIND_DAYS', 5);       // patient email this many days before the due date

function ensure_followup_table($pdo) {
    static $done = false;
    if ($done || (defined('SCHEMA_CHECKED') && SCHEMA_CHECKED)) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS treatment_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INT NOT NULL,
            treatment_name VARCHAR(100) NOT NULL,
            tooth VARCHAR(40) DEFAULT NULL,
            dentist VARCHAR(100) DEFAULT NULL,
            interval_days INT DEFAULT NULL,
            total_sessions INT DEFAULT NULL,
            sessions_done INT NOT NULL DEFAULT 1,
            last_session_date DATE DEFAULT NULL,
            next_due DATE DEFAULT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'Active',
            created_by VARCHAR(100) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME DEFAULT NULL,
            last_reminder_for DATE DEFAULT NULL,
            INDEX idx_due (status, next_due), INDEX idx_patient (patient_id)
        )");
    } catch (Throwable $e) {}
}

function active_plans($pdo, $patientId) {
    ensure_followup_table($pdo);
    try {
        $q = $pdo->prepare("SELECT * FROM treatment_plans WHERE patient_id = ? AND status = 'Active' ORDER BY next_due");
        $q->execute([(int)$patientId]);
        return $q->fetchAll();
    } catch (Throwable $e) { return []; }
}

function all_plans($pdo, $patientId) {
    ensure_followup_table($pdo);
    try {
        $q = $pdo->prepare("SELECT * FROM treatment_plans WHERE patient_id = ?
                             ORDER BY status = 'Active' DESC, next_due, id DESC");
        $q->execute([(int)$patientId]);
        return $q->fetchAll();
    } catch (Throwable $e) { return []; }
}

// The patient's next booked (Pending/Approved) appointment from today, or null.
function next_booked_appointment($pdo, $patientId) {
    $q = $pdo->prepare("SELECT appointment_date, appointment_time, treatment FROM appointments
                         WHERE patient_id = ? AND status IN ('Pending','Confirmed') AND appointment_date >= CURDATE()
                         ORDER BY appointment_date, STR_TO_DATE(REPLACE(appointment_time,' ',''), '%h:%i%p') LIMIT 1");
    $q->execute([(int)$patientId]);
    return $q->fetch() ?: null;
}

// Keep patients.next_visit in step with the earliest active follow-up.
function sync_next_visit($pdo, $patientId) {
    try {
        $q = $pdo->prepare("SELECT MIN(next_due) FROM treatment_plans WHERE patient_id = ? AND status = 'Active'");
        $q->execute([(int)$patientId]);
        $d = $q->fetchColumn();
        if ($d) $pdo->prepare("UPDATE patients SET next_visit = ? WHERE id = ?")->execute([$d, (int)$patientId]);
    } catch (Throwable $e) {}
}

function followup_session_text($p) {
    return 'session ' . (int)$p['sessions_done'] . ($p['total_sessions'] ? ' of ' . (int)$p['total_sessions'] : '') . ' done';
}

/**
 * Called after a treatment is recorded (records.php). Returns a short message for the flash, or ''.
 *  - same treatment already has an Active plan -> one more session; due date moves forward
 *  - "Needs follow-up" ticked                  -> a new plan
 */
function followup_after_treatment($pdo, $patientId, $name, $tooth, $date, array $post, $dentist) {
    ensure_followup_table($pdo);
    $name = trim($name);
    if ($name === '') return '';
    $want   = !empty($post['followup']);
    $every  = (int)($post['followup_every'] ?? 0);
    $custom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $post['followup_date'] ?? '') ? $post['followup_date'] : '';
    $total  = (int)($post['followup_sessions'] ?? 0) ?: null;
    $dueFrom = function ($days) use ($date) { return date('Y-m-d', strtotime($date . " +$days days")); };

    $q = $pdo->prepare("SELECT * FROM treatment_plans WHERE patient_id = ? AND status = 'Active' AND LOWER(treatment_name) = LOWER(?) LIMIT 1");
    $q->execute([(int)$patientId, $name]);
    $plan = $q->fetch();

    if ($plan) {
        $done  = (int)$plan['sessions_done'] + 1;
        $int   = $every ?: (int)$plan['interval_days'];
        $total = $total ?: ($plan['total_sessions'] ? (int)$plan['total_sessions'] : null);
        if ($total && $done >= $total) {
            $pdo->prepare("UPDATE treatment_plans SET sessions_done = ?, total_sessions = ?, last_session_date = ?, next_due = NULL,
                                  status = 'Completed', completed_at = NOW() WHERE id = ?")
                ->execute([$done, $total, $date, (int)$plan['id']]);
            sync_next_visit($pdo, $patientId);
            return " Follow-up plan finished: all $total sessions of $name are done.";
        }
        $next = $custom ?: ($int ? $dueFrom($int) : null);
        $pdo->prepare("UPDATE treatment_plans SET sessions_done = ?, total_sessions = ?, interval_days = ?, last_session_date = ?,
                              next_due = ?, last_reminder_for = NULL WHERE id = ?")
            ->execute([$done, $total, $int ?: null, $date, $next, (int)$plan['id']]);
        sync_next_visit($pdo, $patientId);
        return " Session $done" . ($total ? " of $total" : '') . " of $name recorded" . ($next ? '; next due ' . date('M j, Y', strtotime($next)) : '') . '.';
    }

    if (!$want) return '';
    $next = $custom ?: ($every ? $dueFrom($every) : null);
    if (!$next) return ' (Follow-up not saved: please choose when they should come back.)';
    if ($total === 1) return '';                                   // a single session needs no follow-up
    $pdo->prepare("INSERT INTO treatment_plans (patient_id, treatment_name, tooth, dentist, interval_days, total_sessions,
                                                sessions_done, last_session_date, next_due, created_by)
                   VALUES (?,?,?,?,?,?,1,?,?,?)")
        ->execute([(int)$patientId, $name, trim((string)$tooth) ?: null, $dentist, $every ?: null, $total, $date, $next, $dentist]);
    sync_next_visit($pdo, $patientId);
    return ' Follow-up set: next ' . $name . ' due ' . date('M j, Y', strtotime($next)) . ($total ? " (session 1 of $total done)" : '') . '.';
}

/** Follow-ups due within the window (or overdue) with nothing booked. $dentist limits to one dentist's patients. */
function followups_due($pdo, $dentist = null) {
    ensure_followup_table($pdo);
    try {
        $p = [date('Y-m-d', strtotime('+' . FOLLOWUP_DUE_WINDOW . ' days'))];
        $sql = "SELECT tp.*, pt.name AS patient_name, pt.phone, pt.primary_dentist
                  FROM treatment_plans tp JOIN patients pt ON pt.id = tp.patient_id
                 WHERE tp.status = 'Active' AND tp.next_due IS NOT NULL AND tp.next_due <= ? AND pt.status <> 'Archived'
                   AND NOT EXISTS (SELECT 1 FROM appointments a WHERE a.patient_id = tp.patient_id
                                     AND a.status IN ('Pending','Confirmed') AND a.appointment_date >= CURDATE())";
        if ($dentist) { $sql .= " AND (tp.dentist = ? OR pt.primary_dentist = ?)"; $p[] = $dentist; $p[] = $dentist; }
        $sql .= " ORDER BY tp.next_due";
        $q = $pdo->prepare($sql); $q->execute($p);
        return $q->fetchAll();
    } catch (Throwable $e) { return []; }
}

// Link that opens the staff booking form with this follow-up already filled in.
function followup_book_link($plan) {
    if (!function_exists('booking_treatment_for')) require_once __DIR__ . '/dental_care.php';
    $date = max($plan['next_due'] ?: date('Y-m-d'), date('Y-m-d', strtotime('+1 day')));
    return 'appointments?' . http_build_query([
        'book' => (int)$plan['patient_id'], 'treatment' => booking_treatment_for($plan['treatment_name']),
        'date' => $date, 'dentist' => $plan['dentist'] ?: '', 'note' => 'Follow-up: ' . $plan['treatment_name'],
    ]);
}

// ---- Daily: email patients whose follow-up is near and not booked (once per due date) ----
function send_followup_reminders($pdo) {
    ensure_followup_table($pdo);
    if (!function_exists('mail_is_ready')) require_once __DIR__ . '/mailer.php';
    if (!function_exists('message_catalogue')) require_once __DIR__ . '/message_templates.php';
    if (!mail_is_ready($pdo)) return 0;
    $sent = 0;
    try {
        $q = $pdo->prepare("SELECT tp.*, pt.name AS patient_name,
                                   COALESCE(NULLIF(pt.email,''), NULLIF(g.email,''), u.email) AS email
                              FROM treatment_plans tp JOIN patients pt ON pt.id = tp.patient_id
                         LEFT JOIN patients g ON g.id = pt.guardian_patient_id
                         LEFT JOIN users u ON u.id = COALESCE(g.user_id, pt.user_id)
                             WHERE tp.status = 'Active' AND tp.next_due IS NOT NULL AND tp.next_due <= ?
                               AND (tp.last_reminder_for IS NULL OR tp.last_reminder_for <> tp.next_due)
                               AND NOT EXISTS (SELECT 1 FROM appointments a WHERE a.patient_id = tp.patient_id
                                                 AND a.status IN ('Pending','Confirmed') AND a.appointment_date >= CURDATE())");
        $q->execute([date('Y-m-d', strtotime('+' . FOLLOWUP_REMIND_DAYS . ' days'))]);
        foreach ($q->fetchAll() as $p) {
            $pdo->prepare("UPDATE treatment_plans SET last_reminder_for = next_due WHERE id = ?")->execute([(int)$p['id']]);
            if (empty($p['email'])) continue;
            $cat = message_catalogue()['followup_due'];
            [$subj, $body] = tpl_message($pdo, 'followup_due', $cat['subject'], $cat['body'], [
                'patient'   => $p['patient_name'],
                'treatment' => $p['treatment_name'],
                'date'      => date('l, F j, Y', strtotime($p['next_due'])),
                'session'   => ((int)$p['sessions_done'] + 1) . ($p['total_sessions'] ? ' of ' . (int)$p['total_sessions'] : ''),
                'dentist'   => $p['dentist'] ?: 'your dentist',
                'clinic'    => clinic_name($pdo),
            ]);
            $err = '';
            if (send_mail($pdo, $p['email'], $subj, $body, $err, 'followup_due')) $sent++;
        }
    } catch (Throwable $e) {}
    return $sent;
}

/**
 * Book the plan's next visit as a real appointment (already Approved), and set the plan's due date to it.
 * The patient sees it in My Appointments (with the slip), gets a pop-up + the confirmation email,
 * and can still reschedule or cancel it from the portal like any booking.
 * Returns [ok, message].
 */
function book_followup_appointment($pdo, $plan, $date, $time, $by) {
    if (!function_exists('clinic_time_slots')) require_once __DIR__ . '/treatments.php';
    if (!function_exists('booking_treatment_for')) require_once __DIR__ . '/dental_care.php';
    if (!function_exists('add_patient_notice')) require_once __DIR__ . '/patient_notices.php';
    if (!function_exists('appt_slot_is_open')) require_once __DIR__ . '/assign.php';
    if (!function_exists('mail_is_ready')) require_once __DIR__ . '/mailer.php';
    if (!function_exists('message_catalogue')) require_once __DIR__ . '/message_templates.php';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date) || $date <= date('Y-m-d')) return [false, 'Please choose a date from tomorrow onwards.'];
    if (!in_array($time, clinic_time_slots($pdo), true)) return [false, 'Please choose one of the clinic\'s time slots.'];

    $pq = $pdo->prepare("SELECT p.id, p.name, p.primary_dentist, p.guardian_patient_id,
                                COALESCE(NULLIF(p.email,''), NULLIF(g.email,''), u.email) AS email
                           FROM patients p LEFT JOIN patients g ON g.id = p.guardian_patient_id
                      LEFT JOIN users u ON u.id = COALESCE(g.user_id, p.user_id) WHERE p.id = ?");
    $pq->execute([(int)$plan['patient_id']]);
    $pt = $pq->fetch();
    if (!$pt) return [false, 'The patient could not be found.'];
    $dentist = $plan['dentist'] ?: $pt['primary_dentist'];
    if (!$dentist) return [false, 'This patient has no dentist yet. Please assign one first.'];
    if (!appt_slot_is_open($pdo, $date, $time, $dentist)) {
        return [false, $dentist . ' is not free on ' . date('M j, Y', strtotime($date)) . " at $time (day off, clinic closed, or already booked). Please pick another time."];
    }

    $session = ((int)$plan['sessions_done'] + 1) . ($plan['total_sessions'] ? ' of ' . (int)$plan['total_sessions'] : '');
    $treat   = booking_treatment_for($plan['treatment_name']);
    $note    = 'Follow-up: ' . $plan['treatment_name'] . " (session $session) — booked by $by";
    $pdo->prepare("INSERT INTO appointments (patient_id, patient_name, dentist, treatment, appointment_date, appointment_time,
                                             status, confirmed_at, notes, booked_for)
                   VALUES (?, ?, ?, ?, ?, ?, 'Confirmed', NOW(), ?, 'Myself')")
        ->execute([(int)$pt['id'], $pt['name'], $dentist, $treat, $date, $time, $note]);
    $pdo->prepare("UPDATE treatment_plans SET next_due = ?, last_reminder_for = ? WHERE id = ?")->execute([$date, $date, (int)$plan['id']]);
    sync_next_visit($pdo, (int)$pt['id']);

    $when = date('l, F j, Y', strtotime($date)) . " at $time";
    $holder = (int)($pt['guardian_patient_id'] ?: $pt['id']);
    add_patient_notice($pdo, $holder, 'followup_booked', 'good', '📅 Your next visit is booked',
        ($holder !== (int)$pt['id'] ? $pt['name'] . '’s' : 'Your') . ' next ' . $plan['treatment_name'] . " visit (session $session) is booked for $when with $dentist."
        . "\n\nYou can see it — and print the slip — under Appointments. If the time does not suit you, you can reschedule it there.");
    $mailNote = '';
    if (!empty($pt['email']) && mail_is_ready($pdo)) {
        $cat = message_catalogue()['appointment_confirmed'];
        [$subj, $body] = tpl_message($pdo, 'appointment_confirmed', $cat['subject'], $cat['body'], [
            'patient' => $pt['name'], 'date' => date('l, F j, Y', strtotime($date)), 'time' => $time,
            'treatment' => $treat, 'dentist' => $dentist, 'clinic' => clinic_name($pdo),
        ]);
        $err = '';
        $mailNote = send_mail($pdo, $pt['email'], $subj, $body, $err, 'appointment_confirmed') ? ' A confirmation was emailed.' : '';
    }
    log_activity($pdo, 'Booked follow-up visit', $pt['name'] . ' — ' . $plan['treatment_name'] . " (session $session), $when with $dentist");
    return [true, 'Next ' . $plan['treatment_name'] . " visit booked: $when with $dentist. The patient can see it in their portal." . $mailNote];
}
