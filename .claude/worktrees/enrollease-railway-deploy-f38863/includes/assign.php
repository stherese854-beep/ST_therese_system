<?php
// ============================================================
//  PATIENT → DENTIST AUTO-BALANCER  (includes/assign.php)
// ============================================================
//  Decides which dentist a NEW patient should be assigned to.
//
//  THE RULES (exactly what you asked for):
//    • 0 active dentists  -> return NULL (nobody to assign yet)
//    • 1 active dentist   -> just return that one dentist.
//                            (Auto-balance does NOT kick in — there is
//                             nothing to balance with only one doctor.)
//    • 2+ active dentists -> AUTO-BALANCE turns on: assign the patient
//                            to the dentist who currently has the
//                            FEWEST patients, so the load stays even.
//
//  It returns the dentist's NAME (this matches the text stored in
//  patients.primary_dentist), or NULL if there are no active dentists.
// ============================================================

function pick_dentist_for_new_patient($pdo) {
    // Get every ACTIVE dentist (inactive ones are skipped).
    $dentists = $pdo->query(
        "SELECT name FROM users WHERE role='dentist' AND status='active' ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);

    $howMany = count($dentists);

    // --- Case 1: no dentists at all ---
    if ($howMany === 0) {
        return null;                // leave the patient unassigned for now
    }

    // --- Case 2: only ONE dentist ---
    // Auto-balance is OFF here. Just hand the patient to the lone dentist.
    if ($howMany === 1) {
        return $dentists[0];
    }

    // --- Case 3: TWO OR MORE dentists -> AUTO-BALANCE ---
    // Look at each dentist, count how many patients they already have,
    // and choose the one with the smallest count. If there is a tie,
    // the first one alphabetically wins (because we loop in name order).
    $chosenDentist = null;
    $lowestCount   = null;

    foreach ($dentists as $dentistName) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE primary_dentist = ? AND status <> 'Archived'");
        $stmt->execute([$dentistName]);
        $patientCount = (int)$stmt->fetchColumn();

        // First loop, or found someone with fewer patients -> remember them.
        if ($lowestCount === null || $patientCount < $lowestCount) {
            $lowestCount   = $patientCount;
            $chosenDentist = $dentistName;
        }
    }

    return $chosenDentist;
}

// ============================================================
//  DENTIST NAME MATCHING
// ============================================================
//  The system stores a dentist's name in two shapes:
//     users.name / patients.primary_dentist -> "Dr. Ana Santos"  (full)
//     appointments.dentist                  -> "Dr. Santos"      (short)
//  This returns every shape a given dentist may appear as, so a
//  filter can match all of that dentist's records reliably.
// ============================================================
function dentist_name_variants($fullName) {
    $fullName = trim($fullName);
    if ($fullName === '') return [''];

    $variants = [$fullName];

    // Build the short form: keep the title (Dr.) + the LAST word (surname).
    $parts = preg_split('/\s+/', $fullName);
    if (count($parts) >= 3) {
        $short = $parts[0] . ' ' . end($parts);      // "Dr." + "Santos"
        if (!in_array($short, $variants)) $variants[] = $short;
    }
    // Also allow the bare surname on its own, just in case.
    $last = end($parts);
    if ($last && !in_array($last, $variants)) $variants[] = $last;

    return $variants;
}

// Builds an SQL fragment like "(col = ? OR col = ? OR col = ?)" plus its params.
// Appointments, patients and schedules point to a dentist by NAME, so two dentists
// may not share one. "Dr. Ana Santos" and "Ana Santos" count as the same name.
// Returns an error message, or '' when the name is free.
function dentist_name_problem($pdo, $name, $exceptUserId = 0) {
    $bare = fn($n) => preg_replace('/^(dr|dra|doc|doctor)\.?\s+/i', '', person_name_key($n));
    $st = $pdo->prepare("SELECT name FROM users WHERE role = 'dentist' AND id <> ?");
    $st->execute([(int)$exceptUserId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) {
        if ($bare($d) === $bare($name)) {
            return 'There is already a dentist named "' . $d . '". Please add a middle initial or a suffix '
                 . '(e.g. "Ana B. Santos" or "Ana Santos Jr.") so their patients and schedules do not mix.';
        }
    }
    return '';
}

// A dentist's name changed: their appointments, patients, walk-ins, follow-up plans,
// treatment records and days off follow the new name (they are linked by name).
function rename_dentist_links($pdo, $old, $new) {
    if ($old === '' || $old === $new) return;
    foreach (['appointments' => 'dentist', 'patients' => 'primary_dentist', 'walk_in_visits' => 'dentist',
              'treatment_plans' => 'dentist', 'treatments' => 'dentist', 'dentist_daysoff' => 'dentist_name'] as $t => $col) {
        try { $pdo->prepare("UPDATE `$t` SET `$col` = ? WHERE `$col` = ?")->execute([$new, $old]); }
        catch (Throwable $e) { /* table not there on an old database */ }
    }
}

function dentist_match_sql($column, $fullName, &$params) {
    $variants = dentist_name_variants($fullName);
    $marks = [];
    foreach ($variants as $v) { $marks[] = "$column = ?"; $params[] = $v; }
    return '(' . implode(' OR ', $marks) . ')';
}

// ============================================================
//  WHICH PATIENTS MAY A DENTIST OPEN?
// ============================================================
//  Their own patients (primary dentist, any spelling of their name)
//  PLUS anyone they are seeing: an appointment with them, or a walk-in
//  visit with them, from 30 days ago to 30 days ahead. So a dentist who
//  covers for a colleague (day off, auto-assigned, walk-in) can open the
//  chart and records of the patient in their chair.
//  $idCol / $primaryCol: e.g. 'p.id', 'p.primary_dentist'.
// ============================================================
const DENTIST_COVER_DAYS = 30;
function dentist_patient_sql($idCol, $primaryCol, $fullName, &$params) {
    $own  = dentist_match_sql($primaryCol, $fullName, $params);
    $appt = dentist_match_sql('ca.dentist', $fullName, $params);
    $sql  = "($own OR $idCol IN (SELECT ca.patient_id FROM appointments ca WHERE $appt
                AND ca.appointment_date BETWEEN CURDATE() - INTERVAL " . DENTIST_COVER_DAYS . " DAY AND CURDATE() + INTERVAL " . DENTIST_COVER_DAYS . " DAY
                AND ca.status NOT IN ('Cancelled','Disapproved','Expired'))";
    try {   // walk-in visits (table may not exist yet on an old database)
        global $pdo;
        if ($pdo && $pdo->query("SHOW TABLES LIKE 'walk_in_visits'")->rowCount()) {
            $wk  = dentist_match_sql('cw.dentist', $fullName, $params);
            $sql .= " OR $idCol IN (SELECT cw.patient_id FROM walk_in_visits cw WHERE $wk
                         AND cw.visit_date >= CURDATE() - INTERVAL " . DENTIST_COVER_DAYS . " DAY)";
        }
    } catch (Throwable $e) {}
    return $sql . ')';
}

// ============================================================
//  IS THIS SLOT AVAILABLE?
// ============================================================
//  Used when an appointment is booked or moved. Checks three things:
//    1. the clinic is open that day and at that hour
//    2. the dentist has not marked the day off
//    3. nobody else already holds that slot with that dentist
//  $ignoreId lets an appointment ignore itself when being moved.
// ============================================================
function appt_slot_is_open($pdo, $date, $time, $dentist, $ignoreId = 0, $checkClinicHours = true) {
    // ---- 1. Is the clinic open that day? ----
    // (Skipped when moving an EXISTING appointment to another dentist: the
    //  booking already exists, only the dentist's own availability matters.)
    if ($checkClinicHours) try {
        $cfg = [];
        foreach ($pdo->query("SELECT setting_key, setting_value FROM settings
                              WHERE setting_key IN ('clinic_open_days','clinic_open_time','clinic_close_time')") as $r) {
            $cfg[$r['setting_key']] = $r['setting_value'];
        }
        $openDays = array_filter(array_map('trim', explode(',', $cfg['clinic_open_days'] ?? 'Mon,Tue,Wed,Thu,Fri,Sat')));
        if ($openDays && !in_array(date('D', strtotime($date)), $openDays)) return false;

        // ---- within opening hours? ----
        $t = date('H:i', strtotime($time));
        if (!empty($cfg['clinic_open_time'])  && $t < date('H:i', strtotime($cfg['clinic_open_time'])))  return false;
        if (!empty($cfg['clinic_close_time']) && $t > date('H:i', strtotime($cfg['clinic_close_time']))) return false;
    } catch (Throwable $e) { /* settings missing — allow */ }

    // ---- 2. Has the dentist marked that day off? ----
    if ($dentist) {
        try {
            $p = [$date];
            $sql = "SELECT COUNT(*) FROM dentist_daysoff WHERE off_date = ? AND "
                 . dentist_match_sql('dentist_name', $dentist, $p);
            $q = $pdo->prepare($sql); $q->execute($p);
            if ((int)$q->fetchColumn() > 0) return false;
        } catch (Throwable $e) {}
    }

    // ---- 3. Does this dentist already have an appointment within the hour? ----
    // Every appointment takes APPT_MINUTES, so another one must start at least
    // that long before or after (older half-hour bookings are respected too).
    try {
        $p = [$date];
        $sql = "SELECT appointment_time FROM appointments
                 WHERE appointment_date = ? AND status IN ('Pending','Confirmed','Arrived')";
        if ($dentist) { $sql .= " AND " . dentist_match_sql('dentist', $dentist, $p); }
        if ($ignoreId) { $sql .= " AND id <> ?"; $p[] = (int)$ignoreId; }
        $q = $pdo->prepare($sql); $q->execute($p);
        $want = appt_minutes($time);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $m = appt_minutes($t);
            if ($want !== null && $m !== null && abs($m - $want) < APPT_MINUTES) return false;
        }
    } catch (Throwable $e) {}

    return true;
}

// ============================================================
//  WHICH DENTIST TAKES THIS BOOKING?  (date + time)
// ============================================================
//  1. The patient's own dentist, if they are free at that date + time.
//  2. Otherwise another dentist who IS free — the one with the FEWEST
//     assigned patients; if several tie, one of them at random.
//  "Free" = the clinic is open, the dentist has not marked the day off,
//  and they have no Pending/Confirmed appointment at that time
//  (see appt_slot_is_open()). Returns the dentist's name, or NULL when
//  no dentist at all is free at that time.
// ============================================================
function dentist_patient_count($pdo, $dentist) {
    $p = [];
    $st = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE status <> 'Archived' AND " . dentist_match_sql('primary_dentist', $dentist, $p));
    $st->execute($p);
    return (int)$st->fetchColumn();
}

function pick_dentist_for_slot($pdo, $date, $time, $preferred = '') {
    $active = $pdo->query("SELECT name FROM users WHERE role='dentist' AND status='active' ORDER BY name")
                  ->fetchAll(PDO::FETCH_COLUMN);

    // 1. Keep the patient's own dentist when they are free.
    if ($preferred !== '' && $preferred !== null) {
        foreach ($active as $d) {
            if (in_array($d, dentist_name_variants($preferred), true) || $d === $preferred) {
                if (appt_slot_is_open($pdo, $date, $time, $d)) return $d;
                break;
            }
        }
    }

    // 2. Otherwise the free dentist with the fewest patients (random on a tie).
    $best = []; $lowest = null;
    foreach ($active as $d) {
        if (!appt_slot_is_open($pdo, $date, $time, $d)) continue;
        $n = dentist_patient_count($pdo, $d);
        if ($lowest === null || $n < $lowest) { $lowest = $n; $best = [$d]; }
        elseif ($n === $lowest)               { $best[] = $d; }
    }
    return $best ? $best[random_int(0, count($best) - 1)] : null;
}

// ============================================================
//  ONE APPOINTMENT = ONE HOUR
// ============================================================
//  Slots are hourly (see clinic_time_slots()), and a dentist's
//  appointments must start at least an hour apart.
// ============================================================
if (!defined('APPT_MINUTES')) define('APPT_MINUTES', 60);

// "09:30 AM" -> 570 (minutes after midnight), or null if it can't be read.
function appt_minutes($time) {
    $t = strtotime('2000-01-01 ' . trim((string)$time));
    return $t === false ? null : (int)date('G', $t) * 60 + (int)date('i', $t);
}

// Which of $slots clash with any of $busyTimes (i.e. start less than an hour away)?
function slots_blocked_by($slots, $busyTimes) {
    $busy = array_filter(array_map('appt_minutes', $busyTimes), fn($m) => $m !== null);
    $out = [];
    foreach ($slots as $s) {
        $m = appt_minutes($s);
        foreach ($busy as $b) if (abs($b - $m) < APPT_MINUTES) { $out[] = $s; break; }
    }
    return $out;
}

// ============================================================
//  PATIENT MOVED TO ANOTHER DENTIST -> MOVE THEIR UPCOMING VISITS TOO
// ============================================================
//  When staff change a patient's dentist (Patients page, or a dentist is
//  archived), their upcoming Pending / Approved appointments follow, so the
//  new dentist sees them on their schedule. An appointment stays with the old
//  dentist only if the new one is off that day or already booked at that time
//  — those are listed so staff can sort them out. Each moved patient is emailed.
//  Returns [moved count, [list of "Oct 5, 9:00 AM" that could not move]].
// ============================================================
function move_upcoming_to_dentist($pdo, $patientId, $newDentist) {
    $newDentist = trim((string)$newDentist);
    if ($newDentist === '' || (int)$patientId <= 0) return [0, []];
    if (!function_exists('mail_is_ready'))     require_once __DIR__ . '/mailer.php';
    if (!function_exists('message_catalogue')) require_once __DIR__ . '/message_templates.php';
    $q = $pdo->prepare("SELECT a.*, COALESCE(NULLIF(p.email,''), g.email, u.email) AS patient_email
                          FROM appointments a
                          JOIN patients p ON p.id = a.patient_id
                     LEFT JOIN patients g ON g.id = p.guardian_patient_id
                     LEFT JOIN users u ON u.id = COALESCE(g.user_id, p.user_id)
                         WHERE a.patient_id = ? AND a.status IN ('Pending','Confirmed') AND a.appointment_date >= CURDATE()
                         ORDER BY a.appointment_date, a.appointment_time");
    $q->execute([(int)$patientId]);
    $moved = 0; $stuck = [];
    $same = function_exists('dentist_name_variants') ? dentist_name_variants($newDentist) : [$newDentist];
    $mailReady = function_exists('mail_is_ready') && mail_is_ready($pdo);
    foreach ($q->fetchAll() as $ap) {
        if (in_array((string)$ap['dentist'], $same, true) || $ap['dentist'] === $newDentist) continue;   // already theirs
        $when = date('M j', strtotime($ap['appointment_date'])) . ', ' . $ap['appointment_time'];
        if (!appt_slot_is_open($pdo, $ap['appointment_date'], $ap['appointment_time'], $newDentist, (int)$ap['id'], false)) {
            $stuck[] = $when;
            continue;
        }
        $pdo->prepare("UPDATE appointments SET dentist = ? WHERE id = ?")->execute([$newDentist, (int)$ap['id']]);
        log_activity($pdo, 'Appointment moved to another dentist',
                     $ap['patient_name'] . " ($when) " . ($ap['dentist'] ?: 'unassigned') . " → $newDentist (patient's dentist changed)");
        $moved++;
        if ($mailReady && !empty($ap['patient_email'])) {
            $day = date('l, F j, Y', strtotime($ap['appointment_date']));
            $cat = message_catalogue()['appointment_updated'];
            [$subj, $body] = tpl_message($pdo, 'appointment_updated', $cat['subject'], $cat['body'], [
                'patient' => $ap['patient_name'], 'was' => $day . ' at ' . $ap['appointment_time'] . ' with ' . ($ap['dentist'] ?: 'the clinic'),
                'date' => $day, 'time' => $ap['appointment_time'], 'treatment' => $ap['treatment'], 'dentist' => $newDentist,
                'note' => 'Your dentist has been changed to ' . $newDentist . '. The date and time stay the same.',
                'clinic' => clinic_name($pdo),
            ]);
            $err = ''; send_mail($pdo, $ap['patient_email'], $subj, $body, $err, 'appointment_updated');
        }
    }
    return [$moved, $stuck];
}
