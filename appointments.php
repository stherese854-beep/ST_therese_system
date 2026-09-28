<?php
// ============================================================
//  APPOINTMENTS / SCHEDULE  (appointments.php)
// ============================================================
require_once 'config/auth.php';
require_once 'includes/assign.php';   // dentist_match_sql() for role scoping
require_once 'includes/mailer.php';     // confirmation / cancellation emails
require_once 'includes/message_templates.php';  // editable message wording
require_once 'includes/health_form.php';        // health questionnaire view
require_once 'includes/treatments.php';         // clinic_treatments(), clinic_time_slots()
require_login(['admin','dentist','staff']);

// ---------- Remove an old, finished appointment ----------
// Cancelled and completed appointments older than a week just clutter the
// list. Admin and staff may clear them out. The patient's own history keeps
// nothing hidden — a deleted row is genuinely gone, so we only allow it for
// appointments that are already settled and at least a week old.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_appointment') {
    if (!in_array(current_role(), ['admin','staff'])) {
        set_flash('Only admin and staff can remove old appointments.', 'error');
        header("Location: appointments"); exit;
    }
    $id = (int)($_POST['id'] ?? 0);

    $q = $pdo->prepare(
        "SELECT patient_name, appointment_date, status,
                DATEDIFF(CURDATE(), appointment_date) AS days_old
           FROM appointments WHERE id = ?"
    );
    $q->execute([$id]);
    $row = $q->fetch();

    if (!$row) {
        set_flash('That appointment could not be found.', 'error');
    } elseif ((int)$row['days_old'] < 7) {
        set_flash('Only appointments more than a week old can be removed.', 'error');
    } elseif (!in_array($row['status'], ['Cancelled','Completed','No-show'])) {
        set_flash('Only cancelled, completed or missed appointments can be removed.', 'error');
    } else {
        $pdo->prepare("DELETE FROM appointments WHERE id = ?")->execute([$id]);
        log_activity($pdo, 'Deleted appointment', $row['patient_name'] . ' — '
                . date('M j, Y', strtotime($row['appointment_date'])) . ' (' . $row['status'] . ')');
        set_flash($row['patient_name'] . "'s appointment from "
                . date('M j, Y', strtotime($row['appointment_date'])) . ' was removed.', 'info');
    }
    header("Location: appointments" . (isset($_POST['filter']) ? "?filter=".urlencode($_POST['filter']) : "")); exit;
}

// ---------- Edit an appointment's details ----------
// Changing the date, time, treatment or dentist here also changes what the
// patient's printed slip shows, so the patient is emailed the new details.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_appointment') {
    $id = (int)($_POST['id'] ?? 0);

    // Admin and staff may edit any appointment. A dentist may edit only the
    // appointments of their own assigned patients — the same ownership rule
    // used for approving and cancelling.
    $mayEdit = in_array(current_role(), ['admin','staff']);
    if (!$mayEdit && current_role() === 'dentist' && $id) {
        $myName = $_SESSION['name'] ?? '';
        $gp = [$id];
        $g1 = dentist_match_sql('p.primary_dentist', $myName, $gp);
        $g2 = dentist_match_sql('a.dentist',         $myName, $gp);
        $chk = $pdo->prepare(
            "SELECT COUNT(*) FROM appointments a
             LEFT JOIN patients p ON a.patient_id = p.id
             WHERE a.id = ? AND ($g1 OR $g2)"
        );
        $chk->execute($gp);
        $mayEdit = ((int)$chk->fetchColumn() > 0);
    }
    if (!$mayEdit) {
        set_flash('You can only edit appointments for your own patients.', 'error');
        header("Location: appointments"); exit;
    }

    $newDate = trim($_POST['appointment_date'] ?? '');
    $newTime = trim($_POST['appointment_time'] ?? '');
    $dent    = trim($_POST['dentist'] ?? '');
    $note    = trim($_POST['edit_note'] ?? '');

    $cur = $pdo->prepare(
        "SELECT a.*, COALESCE(NULLIF(p.email,''), g.email) AS patient_email
           FROM appointments a LEFT JOIN patients p ON a.patient_id = p.id
      LEFT JOIN patients g ON g.id = p.guardian_patient_id
          WHERE a.id = ?"
    );
    $cur->execute([$id]);
    $ap = $cur->fetch();

    // Treatments: 1 to 3 ticked; none ticked keeps what the appointment already has
    // (older bookings may use names that are not on the list, e.g. "Check-up").
    $treatErr = '';
    if ($ap) {
        if (empty($_POST['treatments'])) { $treat = $ap['treatment']; }
        else { [$treat, $treatErr] = treatments_from_post($_POST); }
    }

    if (!$ap) {
        set_flash('That appointment could not be found.', 'error');
    } elseif ($treatErr !== '') {
        set_flash($treatErr, 'error');
    } elseif ($newDate === '' || $newTime === '') {
        set_flash('Please give a date and a time.', 'error');
    } elseif (!appt_slot_is_open($pdo, $newDate, $newTime, $dent, $id)) {
        set_flash('That slot is not available — the clinic may be closed, the dentist away, '
                . 'or the time already taken.', 'error');
    } else {
        $moved = ($newDate !== $ap['appointment_date'] || $newTime !== $ap['appointment_time']);
        $movedFrom = $moved
            ? date('M j, Y', strtotime($ap['appointment_date'])) . ' ' . $ap['appointment_time']
            : $ap['rescheduled_from'];

        $pdo->prepare(
            "UPDATE appointments
                SET appointment_date=?, appointment_time=?, treatment=?, dentist=?,
                    rescheduled_at=" . ($moved ? "NOW()" : "rescheduled_at") . ",
                    rescheduled_from=?, reschedule_reason=?
              WHERE id=?"
        )->execute([$newDate, $newTime, $treat, $dent, $movedFrom, $note ?: $ap['reschedule_reason'], $id]);

        // Let the patient know, since their slip is now out of date.
        $mailNote = '';
        if (!empty($ap['patient_email']) && mail_is_ready($pdo)) {
            $when = date('l, F j, Y', strtotime($newDate)) . ' at ' . $newTime;
            $cat = message_catalogue()['appointment_updated'];
            [$subj, $body] = tpl_message($pdo, 'appointment_updated', $cat['subject'], $cat['body'], [
                'patient'   => $ap['patient_name'],
                'was'       => $moved ? $movedFrom : '',
                'date'      => date('l, F j, Y', strtotime($newDate)),
                'time'      => $newTime,
                'treatment' => $treat,
                'dentist'   => $dent ?: 'To be assigned',
                'note'      => $note,
                'clinic'    => clinic_name($pdo),
            ]);
            $err = '';
            $mailNote = send_mail($pdo, $ap['patient_email'], $subj, $body, $err, 'appointment_updated')
                      ? ' The patient was emailed the new details.'
                      : " (The email could not be sent — $err)";
        }
        log_activity($pdo, 'Edited appointment', ($ap['patient_name'] ?? 'Appointment') . ' → ' . ($_POST['appointment_date'] ?? '') . ' ' . ($_POST['appointment_time'] ?? ''));
        set_flash('Appointment updated.' . $mailNote);
    }
    header("Location: appointments" . (isset($_POST['filter']) ? "?filter=".urlencode($_POST['filter']) : "")); exit;
}

// ---------- The clinic books an appointment for a patient ----------
// Admin, staff and dentists can book for ANY patient — with or without an
// account (e.g. a walk-in who needs to come back) — or register a new
// walk-in patient on the spot. A booking made by the clinic is Confirmed
// straight away (the clinic chose the time with the patient).
function staff_bookable_patients($pdo) {
    $sql = "SELECT p.id, p.name, p.phone, p.email, p.user_id, p.primary_dentist, g.name AS guardian_name, g.email AS guardian_email
              FROM patients p
         LEFT JOIN users u ON u.id = p.user_id
         LEFT JOIN patients g ON g.id = p.guardian_patient_id
             WHERE p.status <> 'Archived' AND (u.id IS NULL OR u.role = 'patient')";
    $prm = [];
    if (current_role() === 'dentist') { $sql .= " AND " . dentist_match_sql('p.primary_dentist', $_SESSION['name'] ?? '', $prm); }
    $st = $pdo->prepare($sql . " ORDER BY p.name"); $st->execute($prm);
    return $st->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'staff_book') {
    $role    = current_role();
    $back    = "Location: appointments";
    $date    = trim($_POST['date'] ?? '');
    $time    = trim($_POST['time'] ?? '');
    [$treat, $treatErr] = treatments_from_post($_POST);             // 1 to 3 treatments -> "A, B"
    $notes   = mb_substr(trim($_POST['notes'] ?? ''), 0, 500);
    $mode    = ($_POST['patient_mode'] ?? '') === 'new' ? 'new' : 'existing';
    $dateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $err = '';
    $patient = null;

    if ($mode === 'existing') {
        $pid = (int)($_POST['patient_id'] ?? 0);
        foreach (staff_bookable_patients($pdo) as $bp) if ((int)$bp['id'] === $pid) $patient = $bp;
        if (!$patient) $err = 'Please choose a patient from the list.';
    } else {
        $first = trim($_POST['first_name'] ?? ''); $last = trim($_POST['last_name'] ?? '');
        [$wPhone, $phoneErr] = validate_phone($_POST['phone'] ?? '');
        $wEmail = trim($_POST['email'] ?? '');
        $wDob   = trim($_POST['dob'] ?? '');
        if ($first === '' || $last === '')                                  $err = 'Please enter the walk-in patient\'s first and last name.';
        elseif ($phoneErr !== '')                                           $err = $phoneErr;
        elseif ($wEmail !== '' && !filter_var($wEmail, FILTER_VALIDATE_EMAIL)) $err = 'That email address is not valid.';
        elseif ($wDob !== '' && !DateTimeImmutable::createFromFormat('!Y-m-d', $wDob)) $err = 'Please enter a valid date of birth.';
    }

    [$sbHealth, $sbHealthErr] = health_form_from_post($_POST);
    if ($err === '' && $sbHealthErr !== '') $err = $sbHealthErr;

    if ($err === '') {
        if (!$dateObj || $dateObj->format('Y-m-d') !== $date)               $err = 'Please choose a valid date.';
        elseif ($date < date('Y-m-d'))                                      $err = 'The date has already passed.';
        elseif (!in_array($time, clinic_time_slots($pdo), true))            $err = 'Please choose one of the clinic\'s time slots.';
        elseif ($date === date('Y-m-d') && strtotime("$date $time") <= time()) $err = 'That time has already passed today.';
        elseif ($treatErr !== '')                                           $err = $treatErr;
    }

    // Which dentist?
    $dentist = null;
    if ($err === '') {
        $choice = trim($_POST['dentist'] ?? 'auto');
        if ($role === 'dentist') {
            $dentist = $_SESSION['name'] ?? '';                            // a dentist books for themselves
            if (!appt_slot_is_open($pdo, $date, $time, $dentist)) $err = "You are not available at $time on " . date('M j, Y', strtotime($date)) . '.';
        } elseif ($choice === '' || $choice === 'auto') {
            $dentist = pick_dentist_for_slot($pdo, $date, $time, $patient['primary_dentist'] ?? '');
            if (!$dentist) $err = "No dentist is free at $time on " . date('M j, Y', strtotime($date)) . '. Please choose another time.';
        } else {
            $ok = in_array($choice, $pdo->query("SELECT name FROM users WHERE role='dentist' AND status='active'")->fetchAll(PDO::FETCH_COLUMN), true);
            if (!$ok) $err = 'Please choose a dentist from the list.';
            elseif (!appt_slot_is_open($pdo, $date, $time, $choice)) $err = "$choice is not available at $time on " . date('M j, Y', strtotime($date)) . '.';
            else $dentist = $choice;
        }
    }

    // Same patient already booked that day?
    if ($err === '' && $patient) {
        $dq = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND appointment_date = ? AND status IN ('Pending','Confirmed')");
        $dq->execute([$patient['id'], $date]);
        if ((int)$dq->fetchColumn() > 0) $err = $patient['name'] . ' already has an appointment on ' . date('M j, Y', strtotime($date)) . '.';
    }

    if ($err !== '') {
        set_flash($err, 'error');
        header($back . '?book=' . ($mode === 'existing' && $patient ? (int)$patient['id'] : '1')); exit;
    }

    // New walk-in: create their patient record (no login needed).
    if ($mode === 'new') {
        $age = null;
        if ($wDob !== '') $age = (int)(new DateTime($wDob))->diff(new DateTime())->y;
        $pdo->prepare("INSERT INTO patients (name, phone, email, date_of_birth, age, patient_type, status, primary_dentist)
                       VALUES (?, ?, ?, ?, ?, 'New', 'Active', ?)")
            ->execute([ucwords(trim("$first $last")), $wPhone, $wEmail ?: null, $wDob ?: null, $age, $dentist]);
        $newId = (int)$pdo->lastInsertId();
        $patient = ['id' => $newId, 'name' => ucwords(trim("$first $last")), 'email' => $wEmail, 'guardian_email' => null, 'primary_dentist' => $dentist];
        log_activity($pdo, 'Added patient', $patient['name'] . ' (walk-in)');
    } elseif (empty($patient['primary_dentist'])) {
        $pdo->prepare("UPDATE patients SET primary_dentist = ? WHERE id = ?")->execute([$dentist, $patient['id']]);
    }

    $bookedNote = 'Booked by the clinic (' . ($_SESSION['name'] ?? 'staff') . ')' . ($notes !== '' ? ' — ' . $notes : '');
    $pdo->prepare("INSERT INTO appointments (patient_id, patient_name, dentist, treatment, appointment_date, appointment_time,
                                             status, confirmed_at, notes, booked_for, health_form)
                   VALUES (?, ?, ?, ?, ?, ?, 'Confirmed', NOW(), ?, 'Myself', ?)")
        ->execute([$patient['id'], $patient['name'], $dentist, $treat, $date, $time, $bookedNote,
                   json_encode($sbHealth, JSON_UNESCAPED_UNICODE)]);
    save_patient_health($pdo, $patient['id'], $sbHealth);              // onto the patient's record too
    log_activity($pdo, 'Booked appointment for patient', $patient['name'] . ' — ' . $treat . ', ' . date('M j, Y', strtotime($date)) . " $time with $dentist");

    // Email the patient (or the family member's guardian) the confirmation.
    $mailNote = '';
    $to = trim((string)($patient['email'] ?: ($patient['guardian_email'] ?? '')));
    if ($to !== '' && mail_is_ready($pdo)) {
        $cat = message_catalogue()['appointment_confirmed'];
        [$subj, $body] = tpl_message($pdo, 'appointment_confirmed', $cat['subject'], $cat['body'], [
            'patient' => $patient['name'], 'date' => date('l, F j, Y', strtotime($date)), 'time' => $time,
            'treatment' => $treat, 'dentist' => $dentist, 'clinic' => clinic_name($pdo),
        ]);
        $e2 = '';
        $mailNote = send_mail($pdo, $to, $subj, $body, $e2, 'appointment_confirmed') ? ' A confirmation was emailed.' : " (The email could not be sent — $e2)";
    }
    set_flash('Appointment booked for ' . $patient['name'] . ' — ' . date('M j, Y', strtotime($date)) . " at $time with $dentist." . $mailNote);
    header($back); exit;
}

// ---------- Arrived / undo (today's confirmed appointments) ----------
// Marks that the patient is physically here. The no-show scan then treats
// the visit as attended for certain, instead of guessing from records.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['arrived','undo_arrived'], true)) {
    $aid = (int)($_POST['id'] ?? 0);
    $q = $pdo->prepare("SELECT a.*, p.primary_dentist FROM appointments a LEFT JOIN patients p ON p.id = a.patient_id WHERE a.id = ?");
    $q->execute([$aid]); $ap = $q->fetch();
    $mine = true;
    if ($ap && current_role() === 'dentist') {
        $mine = in_array($_SESSION['name'] ?? '', array_merge(dentist_name_variants($ap['dentist'] ?? ''), dentist_name_variants($ap['primary_dentist'] ?? '')), true)
             || ($ap['dentist'] ?? '') === ($_SESSION['name'] ?? '') || ($ap['primary_dentist'] ?? '') === ($_SESSION['name'] ?? '');
    }
    if (!$ap || !$mine) {
        set_flash('That appointment could not be found.', 'error');
    } elseif ($_POST['action'] === 'arrived') {
        if ($ap['status'] !== 'Confirmed' || $ap['appointment_date'] !== date('Y-m-d')) {
            set_flash('Only today\'s confirmed appointments can be marked as arrived.', 'error');
        } else {
            // Arrived: out of the patient's upcoming list and booking limits;
            // becomes Completed when treatment is recorded (or the next day).
            $pdo->prepare("UPDATE appointments SET status = 'Arrived', arrived_at = NOW(), arrived_by = ? WHERE id = ?")->execute([$_SESSION['name'] ?? '', $aid]);
            log_activity($pdo, 'Marked arrived', $ap['patient_name'] . ' — ' . $ap['appointment_time']);
            set_flash($ap['patient_name'] . ' marked as arrived. It becomes Completed once treatment is recorded.');
        }
    } else {
        $pdo->prepare("UPDATE appointments SET arrived_at = NULL, arrived_by = NULL,
                                               status = IF(status = 'Arrived', 'Confirmed', status) WHERE id = ?")->execute([$aid]);
        log_activity($pdo, 'Undid arrival', $ap['patient_name'] . ' — ' . $ap['appointment_time']);
        set_flash('Arrival undone for ' . $ap['patient_name'] . '.', 'info');
    }
    header("Location: appointments" . (isset($_POST['filter']) ? "?filter=" . urlencode($_POST['filter']) : "")); exit;
}

// ---------- Handle status changes (Approve / Cancel / Complete) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $newStatus = $_POST['status'] ?? '';
    $allowed = ['Confirmed','Cancelled','Completed','Pending'];

    // A dentist may only change appointments that belong to their own patients.
    $canEdit = true;
    if (current_role() === 'dentist' && $id) {
        $myName = $_SESSION['name'] ?? '';
        $gp = [$id];
        $g1 = dentist_match_sql('p.primary_dentist', $myName, $gp);
        $g2 = dentist_match_sql('a.dentist',         $myName, $gp);
        $chk = $pdo->prepare(
            "SELECT COUNT(*) FROM appointments a
             LEFT JOIN patients p ON a.patient_id = p.id
             WHERE a.id = ? AND ($g1 OR $g2)"
        );
        $chk->execute($gp);
        $canEdit = ((int)$chk->fetchColumn() > 0);
    }

    if ($id && in_array($newStatus, $allowed) && $canEdit) {
        // Load the appointment + the patient's email so we can write to them.
        $info = $pdo->prepare(
            "SELECT a.*, COALESCE(NULLIF(p.email,''), g.email) AS patient_email
               FROM appointments a
          LEFT JOIN patients p ON a.patient_id = p.id
          LEFT JOIN patients g ON g.id = p.guardian_patient_id
              WHERE a.id = ?"
        );
        $info->execute([$id]);
        $ap = $info->fetch();

        $when = $ap
            ? date('l, F j, Y', strtotime($ap['appointment_date'])) . ' at ' . $ap['appointment_time']
            : '';
        $mailNote = '';

        if ($newStatus === 'Confirmed') {
            // Stamp the moment it becomes Confirmed — the patient's notification
            // bell uses this to know the confirmation is new (and unread).
            $pdo->prepare("UPDATE appointments SET status=?, confirmed_at=NOW() WHERE id=?")
                ->execute([$newStatus, $id]);

            // Tell the patient their booking is now final.
            if ($ap && !empty($ap['patient_email']) && mail_is_ready($pdo)) {
                $cat = message_catalogue()['appointment_confirmed'];
                [$subj, $body] = tpl_message($pdo, 'appointment_confirmed', $cat['subject'], $cat['body'], [
                    'patient'   => $ap['patient_name'],
                    'date'      => date('l, F j, Y', strtotime($ap['appointment_date'])),
                    'time'      => $ap['appointment_time'],
                    'treatment' => $ap['treatment'],
                    'dentist'   => $ap['dentist'] ?: 'To be assigned',
                    'clinic'    => clinic_name($pdo),
                ]);
                $err = '';
                $mailNote = send_mail($pdo, $ap['patient_email'], $subj, $body, $err, 'appointment_confirmed')
                          ? ' The patient was emailed.'
                          : " (The email could not be sent — $err)";
            }

        } elseif ($newStatus === 'Cancelled') {
            // The clinic must say WHY. The patient sees this in their portal and
            // receives it by email, so an appointment never just disappears.
            $reason = trim($_POST['cancel_reason'] ?? '');
            if ($reason === '') {
                set_flash('Please give a reason for cancelling — the patient will be told.', 'error');
                header("Location: appointments" . (isset($_POST['filter']) ? "?filter=".$_POST['filter'] : "")); exit;
            }

            $pdo->prepare(
                "UPDATE appointments
                    SET status=?, cancelled_at=NOW(), cancelled_by=?, cancel_reason=?
                  WHERE id=?"
            )->execute([$newStatus, ($_SESSION['name'] ?? 'clinic'), $reason, $id]);

            if ($ap && !empty($ap['patient_email']) && mail_is_ready($pdo)) {
                $cat = message_catalogue()['appointment_cancelled'];
                [$subj, $body] = tpl_message($pdo, 'appointment_cancelled', $cat['subject'], $cat['body'], [
                    'patient'   => $ap['patient_name'],
                    'date'      => date('l, F j, Y', strtotime($ap['appointment_date'])),
                    'time'      => $ap['appointment_time'],
                    'treatment' => $ap['treatment'],
                    'reason'    => $reason,
                    'clinic'    => clinic_name($pdo),
                ]);
                $err = '';
                $mailNote = send_mail($pdo, $ap['patient_email'], $subj, $body, $err, 'appointment_cancelled')
                          ? ' The patient was emailed the reason.'
                          : " (The email could not be sent — $err)";
            }

        } else {
            $pdo->prepare("UPDATE appointments SET status=? WHERE id=?")->execute([$newStatus, $id]);
        }
        $patientLabel = $ap['patient_name'] ?? ('#' . $id);
        log_activity($pdo, "Appointment $newStatus", $patientLabel . ($when ? " ($when)" : ''));
        set_flash("Appointment marked as $newStatus." . $mailNote);
    } elseif (!$canEdit) {
        set_flash("You can only manage appointments for your own patients.", 'error');
    }
    header("Location: appointments" . (isset($_POST['filter']) ? "?filter=".$_POST['filter'] : "")); exit;
}

// ---------- Filter tabs + name search ----------
$filter = $_GET['filter'] ?? 'All';
$search = trim($_GET['q'] ?? '');

// A dentist may only see appointments for THEIR OWN assigned patients (matched
// through the patient's primary_dentist, or an appointment that already names
// this dentist). Admin and staff see every appointment.
$isDentist = (current_role() === 'dentist');
$myName    = $_SESSION['name'] ?? '';

$conds = [];
$params = [];
if (in_array($filter, ['Confirmed','Pending','Cancelled','Completed','Arrived'])) {
    $conds[] = "a.status = ?"; $params[] = $filter;
}
if ($search !== '') {
    $conds[] = "(a.patient_name LIKE ? OR a.dentist LIKE ? OR a.treatment LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($isDentist) {
    // Match this dentist in either shape the data may use:
    //   patients.primary_dentist -> "Dr. Ana Santos"   (full name)
    //   appointments.dentist     -> "Dr. Santos"       (short name)
    $dp = [];
    $byPatient = dentist_match_sql('p.primary_dentist', $myName, $dp);
    $byAppt    = dentist_match_sql('a.dentist',         $myName, $dp);
    $conds[] = "($byPatient OR $byAppt)";
    foreach ($dp as $v) $params[] = $v;
}

$sql = "SELECT a.* FROM appointments a LEFT JOIN patients p ON a.patient_id = p.id";
if ($conds) $sql .= " WHERE " . implode(" AND ", $conds);
$sql .= " ORDER BY a.appointment_date DESC, a.appointment_time ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$appts = $stmt->fetchAll();

$tabs = ['All','Confirmed','Pending','Arrived','Cancelled'];

// For the "+ Book" form.
$bookPatients = staff_bookable_patients($pdo);
$bookDentists = $pdo->query("SELECT name FROM users WHERE role='dentist' AND status='active' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$bookSlots    = clinic_time_slots($pdo);
$bookPrefill  = (int)($_GET['book'] ?? 0);           // ?book=<patient id> opens the form with that patient chosen
$bookHealth   = [];                                  // patient id => their latest questionnaire (prefill)
foreach ($bookPatients as $bp) { [$ans] = patient_health($pdo, $bp['id']); if ($ans) $bookHealth[(int)$bp['id']] = $ans; }

$page_title = "Appointments";
include 'includes/head.php';
$active = 'appointments';
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div><h1>Appointments</h1><div class="sub">Schedule management</div></div>
            <div class="d-flex align-items-center gap-3">
                <div class="clock"><span class="time" id="clock"></span><br><span id="clock-date"></span></div>
                <button type="button" class="btn btn-teal" onclick="openStaffBook()">+ Book Appointment</button>
            </div>
        </div>

        <!-- Filter tabs + search -->
        <div class="mb-3 d-flex gap-2 flex-wrap align-items-center">
            <?php foreach ($tabs as $t): ?>
                <a href="appointments?filter=<?= $t ?><?= $search!==''?'&q='.urlencode($search):'' ?>"
                   class="btn btn-sm <?= $filter===$t ? 'btn-dark-navy' : 'btn-light' ?>"><?= $t ?></a>
            <?php endforeach; ?>
            <form method="GET" class="d-flex gap-2 align-items-center ms-auto" style="flex:1;max-width:340px;min-width:200px;">
                <input type="hidden" name="filter" value="<?= e($filter) ?>">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="🔍 Search patient, dentist, treatment..." value="<?= e($search) ?>">
                <?php if ($search !== ''): ?><a href="appointments?filter=<?= e($filter) ?>" class="btn btn-sm btn-light">Clear</a><?php endif; ?>
            </form>
        </div>

        <div class="card-box">
            <div class="table-responsive">
                <table class="data">
                    <thead><tr>
                        <th>Patient</th><th>Dentist</th><th>Date</th><th>Time</th>
                        <th>Treatment</th><th>Status</th><th>Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($appts)): ?>
                        <tr><td colspan="7" style="text-align:center;padding:40px 12px;color:#8aa0a0;">
                            <div style="font-size:2.4rem;margin-bottom:8px;">📅</div>
                            <?php if ($search !== '' || $filter !== 'All'): ?>
                                No appointments match. <a href="appointments" style="color:var(--teal);">Clear filters</a>
                            <?php else: ?>
                                No appointments yet.
                            <?php endif; ?>
                        </td></tr>
                    <?php endif; ?>
                    <?php foreach ($appts as $a): ?>
                        <tr>
                            <td><strong><?= e($a['patient_name']) ?></strong></td>
                            <td><?= e($a['dentist']) ?></td>
                            <td><?= e($a['appointment_date']) ?></td>
                            <td class="date-blue"><?= e($a['appointment_time']) ?></td>
                            <td><?= e($a['treatment']) ?>
                                <?php if ($a['status'] === 'Cancelled' && !empty($a['cancel_reason'])): ?>
                                    <br><small style="color:#8aa0a0;">
                                        <?php if (($a['cancelled_by'] ?? '') === 'patient'): ?>
                                            <span style="color:#c0392b;">Cancelled by patient:</span>
                                        <?php else: ?>
                                            <span>Cancelled by <?= e($a['cancelled_by'] ?: 'clinic') ?>:</span>
                                        <?php endif; ?>
                                        <em><?= e($a['cancel_reason']) ?></em>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge-pill b-<?= strtolower($a['status']) ?>"><?= e($a['status']) ?></span>
                                <?php if (!empty($a['arrived_at'])): ?>
                                    <br><small style="color:#138a4e;font-weight:600;" title="Marked by <?= e($a['arrived_by']) ?>">🟢 Arrived <?= date('g:i A', strtotime($a['arrived_at'])) ?></small>
                                <?php elseif (!empty($a['patient_confirmed_at']) && in_array($a['status'], ['Pending','Confirmed'], true)): ?>
                                    <br><small style="color:#0f766e;" title="Confirmed from the reminder email on <?= date('M j, g:i A', strtotime($a['patient_confirmed_at'])) ?>">✓ Patient confirmed</small>
                                <?php endif; ?></td>
                            <td>
                                <div class="d-flex gap-1 align-items-center">
                                <?php if (in_array($a['status'], ['Confirmed','Arrived'], true) && $a['appointment_date'] === date('Y-m-d')): ?>
                                    <form method="POST" class="d-inline m-0">
                                        <input type="hidden" name="action" value="<?= empty($a['arrived_at']) ? 'arrived' : 'undo_arrived' ?>">
                                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                        <input type="hidden" name="filter" value="<?= e($filter) ?>">
                                        <?php if (empty($a['arrived_at'])): ?>
                                            <button class="btn btn-sm" style="background:#d7f5e3;color:#138a4e;font-weight:600;white-space:nowrap;" title="The patient is here">✓ Arrived</button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-light" style="font-size:.72rem;white-space:nowrap;" title="Undo the arrival mark">Undo</button>
                                        <?php endif; ?>
                                    </form>
                                <?php endif; ?>
                                <?php if ($a['status'] === 'Pending'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                        <input type="hidden" name="status" value="Confirmed">
                                        <input type="hidden" name="filter" value="<?= e($filter) ?>">
                                        <button class="btn btn-sm icon-btn" style="background:#d7f5e3;color:#138a4e;" title="Approve">✓</button>
                                    </form>
                                <?php endif; ?>

                                <?php if (current_role() !== 'staff' && !empty($a['health_form'])):
                                    $hfA = json_decode($a['health_form'], true) ?: []; $hfFlag = health_form_flags($hfA); ?>
                                    <button type="button" class="btn btn-sm icon-btn"
                                            style="background:<?= $hfFlag ? '#fdecec' : '#eaf7ef' ?>;color:<?= $hfFlag ? '#c0392b' : '#1f8a54' ?>;"
                                            title="Health questionnaire<?= $hfFlag ? ' — ' . e(implode(', ', $hfFlag)) : '' ?>"
                                            onclick="showHealthForm(<?= (int)$a['id'] ?>)">🩺</button>
                                    <template id="hf-<?= (int)$a['id'] ?>"><?= '<h6 class="mb-2">' . e($a['patient_name']) . '</h6>' . health_form_view($hfA) ?></template>
                                <?php endif; ?>

                                <?php if ($a['status'] !== 'Cancelled'): ?>
                                    <button class="btn btn-sm icon-btn" style="background:#e8f0fe;color:#185FA5;"
                                            title="Edit"
                                            onclick='openEditAppt(<?= json_encode([
                                                "id"        => $a["id"],
                                                "name"      => $a["patient_name"],
                                                "date"      => $a["appointment_date"],
                                                "time"      => $a["appointment_time"],
                                                "treatment" => $a["treatment"],
                                                "dentist"   => $a["dentist"],
                                            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✎</button>
                                <?php endif; ?>

                                <?php if (!in_array($a['status'], ['Cancelled','Arrived'], true)): ?>
                                    <button type="button" class="btn btn-sm icon-btn" style="background:#fbdcdc;color:#c0392b;"
                                            title="Cancel"
                                            onclick='openCancelAppt(<?= json_encode([
                                                "id"    => $a["id"],
                                                "name"  => $a["patient_name"],
                                                "date"  => $a["appointment_date"],
                                                "time"  => $a["appointment_time"],
                                                "treat" => $a["treatment"],
                                            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✕</button>
                                <?php endif; ?>

                                <?php
                                    $daysOld = (strtotime('today') - strtotime($a['appointment_date'])) / 86400;
                                    $canRemove = in_array(current_role(), ['admin','staff'])
                                              && $daysOld >= 7
                                              && in_array($a['status'], ['Cancelled','Completed','No-show']);
                                ?>
                                <?php if ($canRemove): ?>
                                    <form method="POST" class="d-inline"
                                          onsubmit="return confirm('Remove this appointment from <?= e($a['patient_name']) ?> on <?= date('M j, Y', strtotime($a['appointment_date'])) ?>?\n\nThis cannot be undone.')">
                                        <input type="hidden" name="action" value="delete_appointment">
                                        <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                        <input type="hidden" name="filter" value="<?= e($filter) ?>">
                                        <button class="btn btn-sm icon-btn" style="background:#f0f0f0;color:#8aa0a0;" title="Remove">🗑</button>
                                    </form>
                                <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$appts): ?>
                        <tr><td colspan="7" class="text-center text-muted2 py-4">No appointments in this view.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- ===== Cancel appointment — the reason is emailed to the patient ===== -->
<div class="modal fade" id="cancelApptModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content" onsubmit="return validateCancelAppt()">
      <input type="hidden" name="id" id="ca-id">
      <input type="hidden" name="status" value="Cancelled">
      <input type="hidden" name="filter" value="<?= e($filter) ?>">

      <div class="modal-header">
        <h5 class="modal-title">Cancel this appointment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="card-box mb-3" style="background:#f7fafa;padding:12px 14px;">
          <div class="text-muted2" style="font-size:.8rem;">You are cancelling</div>
          <div style="font-weight:600;" id="ca-name"></div>
          <div class="text-muted2" style="font-size:.82rem;" id="ca-when"></div>
        </div>

        <div class="alert" style="background:#fdeeee;border:1px solid #f0c9c9;color:#8a3d3d;font-size:.82rem;">
          The patient will be <strong>emailed this reason</strong>, and it will appear in their
          appointment history. Please write something they will understand.
        </div>

        <label class="field-label">Reason for cancelling</label>
        <textarea name="cancel_reason" id="ca-reason" class="form-control mb-1" rows="3"
                  placeholder="e.g. The dentist is unwell that day, so we cannot see you as planned."></textarea>
        <div id="ca-warn" class="text-danger small" style="display:none;">Please give a reason — the patient is told why.</div>

        <div class="mt-3">
          <div class="text-muted2 mb-1" style="font-size:.78rem;">Or pick a common reason:</div>
          <div class="d-flex gap-1 flex-wrap">
            <?php foreach ([
                'The dentist is unwell that day.',
                'The clinic is closed for an emergency.',
                'We need to move this to another schedule.',
                'The equipment for this treatment is unavailable.',
            ] as $preset): ?>
              <button type="button" class="btn btn-sm btn-light" style="font-size:.74rem;"
                      onclick="document.getElementById('ca-reason').value=this.textContent.trim();document.getElementById('ca-warn').style.display='none';">
                <?= e($preset) ?>
              </button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep appointment</button>
        <button class="btn" style="background:#c0392b;color:#fff;">Cancel and notify patient</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== Edit appointment (admin and staff) ===== -->
<div class="modal fade" id="editApptModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="edit_appointment">
      <input type="hidden" name="id" id="ea-id">
      <input type="hidden" name="filter" value="<?= e($filter) ?>">

      <div class="modal-header">
        <h5 class="modal-title">Edit Appointment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="text-muted2 mb-3" style="font-size:.85rem;">
          Patient: <strong id="ea-name"></strong>. Changing these details also changes the
          slip the patient prints, so they will be emailed the new information.
        </div>

        <div class="row">
          <div class="col-md-6">
            <label class="field-label">Date</label>
            <input type="date" name="appointment_date" id="ea-date" class="form-control mb-3" required>
          </div>
          <div class="col-md-6">
            <label class="field-label">Time</label>
            <input name="appointment_time" id="ea-time" class="form-control mb-3" placeholder="10:00 AM" required>
          </div>
        </div>

        <div class="field-label">Treatment <span class="text-muted2" style="text-transform:none;letter-spacing:0;">— choose 1 to <?= MAX_TREATMENTS ?></span></div>
        <div class="text-muted2 mb-1" style="font-size:.78rem;">Currently: <b id="ea-treatment-now"></b> · leave all unticked to keep it</div>
        <div class="mb-3"><?= treatment_picker([], 'ea') ?></div>

        <label class="field-label">Dentist</label>
        <select name="dentist" id="ea-dentist" class="form-select mb-3">
          <option value="">To be assigned</option>
          <?php foreach ($pdo->query("SELECT name FROM users WHERE role='dentist' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $dn): ?>
            <option value="<?= e($dn) ?>"><?= e($dn) ?></option>
          <?php endforeach; ?>
        </select>

        <label class="field-label">Note to the patient <span class="text-muted2">(optional)</span></label>
        <textarea name="edit_note" class="form-control mb-1" rows="2"
                  placeholder="e.g. We moved you to the morning so Dr. Lagbas can see you."></textarea>
        <div class="text-muted2" style="font-size:.78rem;">Included in the email sent to the patient.</div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-teal">Save changes</button>
      </div>
    </form>
  </div>
</div>

<?= health_form_styles() ?>
<?= treatment_picker_assets() ?>
<!-- ===== The clinic books for a patient ===== -->
<div class="modal fade" id="staffBookModal" tabindex="-1" aria-labelledby="sbTitle">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form method="POST" class="modal-content" onsubmit="return sbValidate()">
      <input type="hidden" name="action" value="staff_book">
      <div class="modal-header">
        <h5 class="modal-title" id="sbTitle">📅 Book an appointment for a patient</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex gap-3 mb-2">
          <label><input type="radio" name="patient_mode" value="existing" checked onchange="sbMode()"> Existing patient</label>
          <label><input type="radio" name="patient_mode" value="new" onchange="sbMode()"> New walk-in patient</label>
        </div>

        <div id="sb-existing">
          <input type="text" id="sb-search" class="form-control form-control-sm mb-1" placeholder="Search by name or phone..." oninput="sbFilter()">
          <select name="patient_id" id="sb-patient" class="form-select" size="6" onchange="sbHealth()">
            <?php foreach ($bookPatients as $bp): ?>
              <option value="<?= (int)$bp['id'] ?>" data-dentist="<?= e($bp['primary_dentist']) ?>" <?= $bookPrefill === (int)$bp['id'] ? 'selected' : '' ?>>
                <?= e($bp['name']) ?><?= $bp['phone'] ? ' · ' . e($bp['phone']) : '' ?> —
                <?= $bp['user_id'] ? 'has an account' : ($bp['guardian_name'] ? 'family of ' . e($bp['guardian_name']) : 'no account (walk-in)') ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="text-muted2 mt-1" style="font-size:.78rem;">Patients without an account are fine — the clinic manages their bookings.</div>
        </div>

        <div id="sb-new" style="display:none;">
          <div class="row g-2">
            <div class="col-md-6"><label class="field-label">First name *</label><input name="first_name" class="form-control"></div>
            <div class="col-md-6"><label class="field-label">Last name *</label><input name="last_name" class="form-control"></div>
            <div class="col-md-4"><label class="field-label">Phone *</label><input name="phone" class="form-control" placeholder="09XX XXX XXXX" <?= phone_input_attrs() ?>></div>
            <div class="col-md-4"><label class="field-label">Date of birth</label><input type="date" name="dob" class="form-control" max="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-4"><label class="field-label">Email <span class="text-muted2">(optional)</span></label><input type="email" name="email" class="form-control" placeholder="for reminders"></div>
          </div>
          <div class="text-muted2 mt-1" style="font-size:.78rem;">A patient record is created (no login). They can register online later with the same email.</div>
        </div>

        <hr>
        <div class="row g-2">
          <div class="col-12">
            <div id="sb-hf-note" class="text-muted2 mb-1" style="font-size:.8rem;"></div>
            <div id="sb-hf"><?= health_form_fields([], true) ?></div>
          </div>
          <div class="col-12">
            <div class="field-label">Treatment * <span class="text-muted2" style="text-transform:none;letter-spacing:0;">— choose 1 to <?= MAX_TREATMENTS ?></span></div>
            <?= treatment_picker([], 'sb') ?>
          </div>
          <div class="col-md-3">
            <label class="field-label">Date *</label>
            <input type="date" name="date" id="sb-date" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" required onchange="sbTimes()">
          </div>
          <div class="col-md-3">
            <label class="field-label">Time *</label>
            <select name="time" id="sb-time" class="form-select" required>
              <?php foreach ($bookSlots as $sl): ?><option><?= e($sl) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="field-label">Dentist</label>
            <?php if (current_role() === 'dentist'): ?>
              <input class="form-control" value="<?= e($_SESSION['name'] ?? '') ?> (you)" readonly>
            <?php else: ?>
              <select name="dentist" class="form-select">
                <option value="auto">Automatic — their own dentist if free, otherwise the least busy</option>
                <?php foreach ($bookDentists as $dn): ?><option value="<?= e($dn) ?>"><?= e($dn) ?></option><?php endforeach; ?>
              </select>
            <?php endif; ?>
          </div>
          <div class="col-md-6">
            <label class="field-label">Notes <span class="text-muted2">(optional)</span></label>
            <input name="notes" class="form-control" maxlength="500" placeholder="e.g. follow-up after extraction">
          </div>
        </div>
        <div id="sb-warn" class="text-danger small mt-2" style="display:none;"></div>
        <div class="text-muted2 mt-2" style="font-size:.78rem;">Booked by the clinic, so it is <b>Confirmed</b> right away; the patient is emailed if they have an email.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-teal">📅 Book appointment</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js"></script>
<script>
startClock();

// ---- "+ Book Appointment" (the clinic books for a patient) ----
function openStaffBook() {
    sbMode(); sbTimes();
    var m = document.getElementById('staffBookModal');
    m.addEventListener('shown.bs.modal', function () {       // bring a pre-chosen patient into view
        var s = document.getElementById('sb-patient');
        if (s.selectedIndex >= 0) s.scrollTop = s.options[s.selectedIndex].offsetTop - s.clientHeight / 2;
    }, { once: true });
    new bootstrap.Modal(m).show();
}
function sbMode() {
    var isNew = document.querySelector('[name="patient_mode"]:checked').value === 'new';
    document.getElementById('sb-existing').style.display = isNew ? 'none' : '';
    document.getElementById('sb-new').style.display      = isNew ? '' : 'none';
    sbHealth();
}
// Health questionnaire: an existing patient's last answers are filled in to
// review with them; a new walk-in starts blank.
var SB_HEALTH = <?= json_encode((object)$bookHealth, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
function sbHealth() {
    var isNew = document.querySelector('[name="patient_mode"]:checked').value === 'new';
    var pid = document.getElementById('sb-patient').value, a = (!isNew && pid && SB_HEALTH[pid]) || null;
    hfFill(document.getElementById('sb-hf'), a || {});
    document.getElementById('sb-hf-note').textContent = isNew ? 'Fill in the health questionnaire with the patient.'
        : (a ? 'Their last answers are filled in — please review them with the patient.' : (pid ? 'No questionnaire on file yet — please fill it in with the patient.' : ''));
}
function sbFilter() {
    var q = document.getElementById('sb-search').value.toLowerCase();
    [].forEach.call(document.getElementById('sb-patient').options, function (o) { o.hidden = q !== '' && o.text.toLowerCase().indexOf(q) === -1; });
}
// Today: times that have already passed are not offered.
function sbTimes() {
    var d = document.getElementById('sb-date').value, now = new Date(), today = now.toISOString().slice(0, 10);
    today = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    var sel = document.getElementById('sb-time'), firstOk = null;
    [].forEach.call(sel.options, function (o) {
        var m = o.value.match(/(\d+):(\d+) (AM|PM)/), h = (+m[1] % 12) + (m[3] === 'PM' ? 12 : 0);
        var past = d === today && (h * 60 + +m[2]) <= (now.getHours() * 60 + now.getMinutes());
        o.disabled = past; if (!past && firstOk === null) firstOk = o.value;
    });
    if (sel.selectedOptions[0] && sel.selectedOptions[0].disabled && firstOk) sel.value = firstOk;
}
function sbValidate() {
    var warn = document.getElementById('sb-warn'), isNew = document.querySelector('[name="patient_mode"]:checked').value === 'new';
    var msg = '';
    if (!isNew && !document.getElementById('sb-patient').value) msg = 'Please choose a patient from the list.';
    if (isNew) {
        var f = document.querySelector('#sb-new [name="first_name"]').value.trim(), l = document.querySelector('#sb-new [name="last_name"]').value.trim();
        var p = document.querySelector('#sb-new [name="phone"]').value;
        if (!f || !l) msg = "Please enter the walk-in patient's first and last name.";
        else if (typeof phoneProblem === 'function' && phoneProblem(p, true)) msg = phoneProblem(p, true);
    }
    if (!msg && tpPicked('sb-list').length === 0) msg = 'Please choose at least one treatment.';
    if (!msg) {
        var bad = [].slice.call(document.querySelectorAll('#sb-hf input[required]')).find(function (el) { return !el.checkValidity(); });
        if (bad) { msg = 'Please answer every question marked * in the health questionnaire.'; bad.scrollIntoView({ block: 'center' }); }
    }
    if (msg) { warn.textContent = msg; warn.style.display = 'block'; return false; }
    return true;
}
// Opened from the Patients list (?book=<id>) or after a booking problem (?book=1).
<?php if (isset($_GET['book'])): ?>document.addEventListener('DOMContentLoaded', openStaffBook);<?php endif; ?>

// Fill and open the edit dialog with the appointment's current details.
// Health questionnaire (admin / dentist): read-only view in a modal.
function showHealthForm(id) {
    var t = document.getElementById('hf-' + id);
    if (!t) return;
    document.getElementById('hfModalBody').innerHTML = t.innerHTML;
    new bootstrap.Modal(document.getElementById('hfModal')).show();
}

function openEditAppt(a){
    document.getElementById('ea-id').value        = a.id;
    document.getElementById('ea-name').textContent= a.name || '';
    document.getElementById('ea-date').value      = a.date || '';
    document.getElementById('ea-time').value      = a.time || '';
    // Tick the appointment's current treatments (up to 3), then apply the limit.
    var now = (a.treatment || '').split(',').map(function (t) { return t.trim(); });
    document.getElementById('ea-treatment-now').textContent = a.treatment || '—';
    document.querySelectorAll('#ea-list input').forEach(function (b) { b.checked = now.indexOf(b.value) !== -1; b.disabled = false; });
    var first = document.querySelector('#ea-list input'); if (first) tpLimit(first);
    document.getElementById('ea-dentist').value   = a.dentist || '';
    new bootstrap.Modal(document.getElementById('editApptModal')).show();
}

// Cancelling must always come with a reason — the patient is told why,
// so an appointment never simply disappears from their portal.
function openCancelAppt(a){
    document.getElementById('ca-id').value = a.id;
    document.getElementById('ca-name').textContent = a.name || '';
    var d = new Date(a.date + 'T00:00:00');
    document.getElementById('ca-when').textContent =
        d.toLocaleDateString(undefined,{weekday:'long',year:'numeric',month:'long',day:'numeric'})
        + ' at ' + a.time + (a.treat ? ' · ' + a.treat : '');
    document.getElementById('ca-reason').value = '';
    document.getElementById('ca-warn').style.display = 'none';
    new bootstrap.Modal(document.getElementById('cancelApptModal')).show();
}

function validateCancelAppt(){
    var r = document.getElementById('ca-reason').value.trim();
    if (r === '') { document.getElementById('ca-warn').style.display = 'block'; return false; }
    return true;
}
</script>
<div class="modal fade" id="hfModal" tabindex="-1" aria-labelledby="hfModalTitle">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="hfModalTitle">🩺 Health Questionnaire</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body" id="hfModalBody"></div>
    </div>
  </div>
</div>
</body>
</html>
