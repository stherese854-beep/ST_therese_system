<?php
// ============================================================
//  BOOK APPOINTMENT  (book.php) -- 4-step wizard for patients
// ============================================================
require_once 'config/auth.php';
require_once 'includes/policies.php';   // Terms & Privacy text (edited by the admin)
require_once 'includes/mailer.php';
require_once 'includes/message_templates.php';   // editable message wording   // to email a booking confirmation
require_once 'includes/noshow_check.php';   // patient_noshow_count(), patient_cancel_count()
require_once 'includes/health_form.php';    // health questionnaire
require_once 'includes/treatments.php';     // clinic_treatments()
require_login(['patient']);   // only patients book through this page

// Get the logged-in patient's info to pre-fill the form.
$stmt = $pdo->prepare("SELECT * FROM patients WHERE user_id=? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$me = $stmt->fetch();
if (!$me) { $me = ['name'=>$_SESSION['name'],'email'=>'','phone'=>'','patient_type'=>'New','id'=>null]; }

// ---- Clinic open days + hours (set by the admin in Settings) ----
$cfg = [];
foreach ($pdo->query("SELECT setting_key,setting_value FROM settings") as $r) $cfg[$r['setting_key']] = $r['setting_value'];
$openTime    = $cfg['clinic_open_time']  ?? '09:00';
$closeTime   = $cfg['clinic_close_time'] ?? '17:00';
$openDays    = $cfg['clinic_open_days']  ?? 'Mon,Tue,Wed,Thu,Fri,Sat';
$openDaysArr = array_map('trim', explode(',', $openDays));

// Build the time slots from the clinic's opening hours (every 30 minutes).
$slots = [];
$t = strtotime($openTime); $endT = strtotime($closeTime);
while ($t + 60 * 60 <= $endT) { $slots[] = date('h:i A', $t); $t += 60 * 60; }   // one-hour appointments
if (empty($slots)) $slots = ['09:00 AM'];   // safety fallback

// ---- The patient's dentist + that dentist's UPCOMING days off ----
$myDentist = $me['primary_dentist'] ?? '';

// ---- One active booking per PERSON ----
// Each person (the account owner, or a family member booked by them) may
// hold only one Pending/Confirmed appointment at a time. Names are compared
// ignoring case and extra spaces.
function person_key($name) { return strtolower(preg_replace('/\s+/', ' ', trim((string)$name))); }
$familyIds  = $me['id'] ? family_patient_ids($pdo, $me['id']) : [];   // this patient + family members
$busyPeople = [];   // person_key => date of their active appointment
if ($me['id']) {
    $bp = $pdo->prepare("SELECT patient_name, appointment_date FROM appointments
                          WHERE patient_id IN (" . in_placeholders($familyIds) . ") AND status IN ('Pending','Confirmed')
                          ORDER BY appointment_date");
    $bp->execute($familyIds);
    foreach ($bp->fetchAll() as $b) {
        $k = person_key($b['patient_name']);
        if ($k !== '' && !isset($busyPeople[$k])) $busyPeople[$k] = $b['appointment_date'];
    }
}
$ownerBusyDate = $busyPeople[person_key($me['name'])] ?? null;   // owner already booked?

// ---- Is online booking paused for this account? ----
// Checked when the page OPENS, so a paused patient sees why straight away
// instead of filling in the whole form first. (The same checks run again
// when a booking is submitted.)
$pauseMessage = '';
if ($me['id']) {
    $mb = $pdo->prepare("SELECT booking_blocked, booking_block_reason FROM patients WHERE id=?");
    $mb->execute([$me['id']]);
    $mbRow = $mb->fetch();
    $missed = 0; $cancels = 0;
    foreach ($familyIds as $fid) {                                   // the whole family
        $missed  += patient_noshow_count($pdo, $fid);
        $cancels += patient_cancel_count($pdo, $fid);
    }
    $pc = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE patient_id IN (" . in_placeholders($familyIds) . ") AND status IN ('Pending','Confirmed')");
    $pc->execute($familyIds);
    $activeNow = (int)$pc->fetchColumn();

    if ($mbRow && !empty($mbRow['booking_blocked'])) {
        $pauseMessage = "<strong>Online booking is currently paused on your account.</strong><br><br>"
                      . (trim((string)$mbRow['booking_block_reason']) !== '' ? e($mbRow['booking_block_reason']) . "<br><br>" : '')
                      . "Please visit the clinic and our staff will be happy to help you.";
    } elseif ($missed >= 3) {
        $pauseMessage = "<strong>Online booking is paused on your account.</strong><br><br>"
                      . "You have missed <strong>$missed scheduled appointments</strong>. "
                      . "We encourage you to <strong>walk in to the clinic</strong> and talk to our staff — "
                      . "they will be happy to help you book again and find a schedule that works better for you.";
    } elseif ($cancels >= CANCEL_LIMIT) {
        $pauseMessage = "<strong>Online booking is paused while the clinic reviews your account.</strong><br><br>"
                      . "You have cancelled <strong>$cancels appointments</strong> recently. Our staff will look at your "
                      . "bookings and contact you — or you can <strong>visit or call the clinic</strong> to book your next visit.";
    } elseif ($activeNow >= 3) {
        $pauseMessage = "<strong>You already have 3 upcoming appointments</strong>, which is the most an account may hold at once.<br><br>"
                      . "Once one of them is completed or cancelled, you can book another.";
    }
}
if (empty($myDentist)) $myDentist = 'Dr. Ana Santos';
$doStmt = $pdo->prepare("SELECT off_date, reason FROM dentist_daysoff WHERE dentist_name=? AND off_date >= CURDATE() ORDER BY off_date");
$doStmt->execute([$myDentist]);
$myDaysOff      = $doStmt->fetchAll();
$myDaysOffDates = array_column($myDaysOff, 'off_date');   // e.g. ['2026-07-10', ...] for JS

$booked = false;
$bookError = '';

// Active dentists — the patient may pick one, or leave it as "No preference".
$dentistList = $pdo->query("SELECT name, specialty FROM users WHERE role='dentist' AND status='active' ORDER BY name")->fetchAll();

// ---------- When the wizard is submitted, save the appointment ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'book') {
    // Which dentist? The patient may pick one, or choose "No preference",
    // in which case we use their primary dentist (or auto-assign the least busy).
    // Their own dentist if free at that date + time; otherwise another free
    // dentist (fewest patients, random on a tie). See pick_dentist_for_slot().
    $chosen          = trim($_POST['pref_dentist'] ?? '');
    $ownDentist      = $chosen !== '' ? $chosen : ($me['primary_dentist'] ?? '');
    if (($_POST['for'] ?? 'myself') === 'other' && $me['id']) {
        // Someone else: their own dentist if they are already a patient here.
        // A NEW family member has no dentist yet, so they are auto-balanced
        // like any new patient (free dentist with the fewest patients).
        $ownDentist = '';
        $depName = trim(($_POST['fname'] ?? '') . ' ' . ($_POST['lname'] ?? ''));
        $dq = $pdo->prepare("SELECT name, primary_dentist FROM patients WHERE guardian_patient_id = ?");
        $dq->execute([$me['id']]);
        foreach ($dq->fetchAll() as $dr) {
            if (person_name_key($dr['name']) === person_name_key($depName) && $dr['primary_dentist']) {
                $ownDentist = $dr['primary_dentist']; break;
            }
        }
    }
    $bookDentist     = pick_dentist_for_slot($pdo, $_POST['date'] ?? '', $_POST['time'] ?? '', $ownDentist);
    $noDentistFree   = ($bookDentist === null);
    $reassignedFrom  = (!$noDentistFree && $ownDentist !== ''
                        && !in_array($bookDentist, dentist_name_variants($ownDentist), true)
                        && $bookDentist !== $ownDentist) ? $ownDentist : '';

    // Is the clinic even open on that weekday?
    $dayName = date('D', strtotime($_POST['date']));   // Mon, Tue, ...

    // The name on the appointment can be the account holder OR a dependent.
    $patientName = trim(($_POST['fname'] ?? '') . ' ' . ($_POST['lname'] ?? ''));
    if ($patientName === '') $patientName = $me['name'];

    // ---- Anti-spam checks ----
    // (a) How many ACTIVE appointments does this account already hold?
    // Both Pending and Confirmed count — three is the limit either way.
    // Once one is completed or cancelled it frees a place, so the patient
    // can book again without waiting for anything else.
    $activeCount = 0;
    if ($me['id']) {
        $pc = $pdo->prepare("SELECT COUNT(*) FROM appointments
                              WHERE patient_id IN (" . in_placeholders($familyIds) . ") AND status IN ('Pending','Confirmed')");
        $pc->execute($familyIds);
        $activeCount = (int)$pc->fetchColumn();
    }
    // (a2) How many appointments has this patient missed RECENTLY?
    // Uses the shared rule: only confirmed no-shows inside the rolling
    // window, and only those after any staff reset. See noshow_check.php.
    $noShowCount = 0;
    foreach ($familyIds as $fid) $noShowCount += patient_noshow_count($pdo, $fid);   // whole family
    // (a3) Has a staff member paused this patient's online booking by hand?
    $manualBlockReason = '';
    if ($me['id']) {
        $mb = $pdo->prepare("SELECT booking_blocked, booking_block_reason FROM patients WHERE id=?");
        $mb->execute([$me['id']]);
        $mbRow = $mb->fetch();
        if ($mbRow && !empty($mbRow['booking_blocked'])) {
            $manualBlockReason = trim((string)$mbRow['booking_block_reason']);
        }
    }
    // (b) Does this SAME person already have an active appointment (any date)?
    //     One booking per person: the next one must be for someone else.
    $dupDate     = $busyPeople[person_key($patientName)] ?? null;
    $isDuplicate = ($dupDate !== null);

    // (c) Scheduling conflicts (dentist away / already booked at that time) are
    //     handled above: pick_dentist_for_slot() only returns a dentist who is free.

    // Same-day booking is allowed; only a date or time that has already passed is refused.
    $minBookDate = date('Y-m-d');

    [$cleanPhone, $phoneError] = validate_phone($_POST['phone'] ?? '');

    // Anti-spam: at most 15 booking attempts per hour from one connection.
    $tooMany = rate_limited($pdo, 'booking', 15, 3600);
    rate_hit($pdo, 'booking');

    // Same real person (name + birth date) already booked through ANOTHER account?
    $idOther = ($_POST['for'] ?? 'myself') === 'other';
    $idName  = $idOther ? trim(($_POST['fname'] ?? '') . ' ' . ($_POST['lname'] ?? '')) : ($me['name'] ?? '');
    $idDob   = $idOther ? trim($_POST['patient_dob'] ?? '') : ($me['date_of_birth'] ?? '');
    $elsewhere = same_person_active_booking($pdo, $idName, $idDob, $familyIds);
    if ($elsewhere) {
        log_activity($pdo, 'Duplicate booking blocked', $idName . ' (born ' . $idDob . ') already booked for '
                     . $elsewhere['appointment_date'] . ' under another account');
    }

    [$healthForm, $healthError] = health_form_from_post($_POST);
    [$treatJoined, $treatError] = treatments_from_post($_POST);      // 1 to 3 treatments -> "A, B"
    if ($treatJoined !== '') $_POST['treatment'] = $treatJoined;
    $relationshipPosted = trim($_POST['relationship'] ?? '');
    if ($relationshipPosted === 'Other relative') $relationshipPosted = trim($_POST['relationship_other'] ?? '');

    if ($tooMany) {
        $bookError = "Too many booking attempts from this connection. Please wait a while and try again, or contact the clinic.";
    } elseif ($pauseMessage !== '') {
        $bookError = $pauseMessage;
    } elseif ($phoneError !== '') {
        $bookError = $phoneError;
    } elseif (($_POST['for'] ?? 'myself') === 'other' && $relationshipPosted === '') {
        $bookError = 'Please tell us your relationship to the patient.';
    } elseif ($treatError !== '') {
        $bookError = $treatError;
    } elseif ($healthError !== '') {
        $bookError = $healthError;
    } elseif (($_POST['date'] ?? '') < $minBookDate) {
        $bookError = "That date has already passed. Please pick today or a later date.";
    } elseif (($_POST['date'] ?? '') === $minBookDate && strtotime($minBookDate . ' ' . ($_POST['time'] ?? '')) <= time()) {
        $bookError = "That time has already passed today. Please choose a later time.";
    } elseif (($_POST['for'] ?? 'myself') === 'other' && ($dobErr = birth_date_error($_POST['patient_dob'] ?? '', true)) !== '') {
        $bookError = $dobErr;
    } elseif ($manualBlockReason !== '') {
        // Staff paused this account by hand and left a message for the patient.
        $bookError = "<strong>Online booking is currently paused on your account.</strong><br><br>"
                   . e($manualBlockReason) . "<br><br>"
                   . "Please visit the clinic and our staff will be happy to help you.";
    } elseif (!in_array($dayName, $openDaysArr)) {
        $bookError = "The clinic is closed on " . date('l', strtotime($_POST['date'])) . "s. Please pick another day.";
    } elseif ($noShowCount >= 3) {
        // Repeat no-shows: online booking pauses until the patient has spoken
        // to the clinic. We ask them to drop by rather than phone, because a
        // face-to-face chat is what actually sorts the problem out.
        $bookError = "<strong>Online booking is paused on your account.</strong><br><br>"
                   . "You have missed <strong>$noShowCount scheduled appointments</strong>. "
                   . "We encourage you to <strong>walk in to the clinic</strong> and talk to our staff — "
                   . "they will be happy to help you book again and find a schedule that works better for you.";
    } elseif ($noDentistFree) {
        // Every dentist is away or already booked at that date + time.
        $bookError = "No dentist is available at <strong>" . e($_POST['time']) . "</strong> on "
                   . date('M j, Y', strtotime($_POST['date'])) . ". Please choose a different time or date.";
    } elseif ($activeCount >= 3) {
        $bookError = "You already have 3 upcoming appointments, which is the most an account may hold at once. "
                   . "Once one of them is completed or cancelled, you can book another.";
    } elseif ($elsewhere) {
        // One active booking per real person, whichever account made it.
        $bookError = "<strong>" . e($idName) . " already has an appointment</strong> on "
                   . date('M j, Y', strtotime($elsewhere['appointment_date'])) . " (booked from another account).<br><br>"
                   . "Each person can only have one booking at a time. If this is a <strong>different person</strong> "
                   . "who happens to share the same name and birthday, please call or visit the clinic and our staff will book for you.";
    } elseif ($isDuplicate) {
        $isOwner   = (person_key($patientName) === person_key($me['name']));
        $bookError = "<strong>" . e($patientName) . " already has an appointment</strong> on "
                   . date('M j, Y', strtotime($dupDate)) . ".<br><br>"
                   . "Each person can only have one booking at a time. "
                   . ($isOwner
                        ? "To book another appointment now, choose <strong>Someone else</strong> and enter that person's name."
                        : "Please book for a different person, or wait until that visit is completed or cancelled.");
    } else {
        // Save the phone number the patient entered (keeps their record current).
        // Numbers only — strip out anything that isn't a digit.
        if ($me['id'] && $cleanPhone !== '') {
            $pdo->prepare("UPDATE patients SET phone=? WHERE id=?")->execute([$cleanPhone, $me['id']]);
        }

        $forWhom      = ($_POST['for'] ?? 'myself') === 'other' ? 'Someone else' : 'Myself';
        $relationship = ($forWhom === 'Someone else') ? mb_substr($relationshipPosted, 0, 40) : null;
        $bookedBy     = ($forWhom === 'Someone else') ? $me['name'] : null;
        $reason       = null;                     // replaced by the health questionnaire
        $bookingNotes = '';

        // When booking for someone else, record that person's birthday in the notes
        // so the dentist knows the patient's age (for yourself it is already on file).
        if ($forWhom === 'Someone else' && !empty($_POST['patient_dob'])) {
            $dobNote = 'Patient DOB: ' . trim($_POST['patient_dob']);
            $bookingNotes = $bookingNotes === '' ? $dobNote : ($dobNote . ' — ' . $bookingNotes);
        }

        // Booking for someone else: they get (or already have) their own patient
        // record, linked to this account, and the appointment is filed under it.
        $apptPatientId = $me['id'];
        if ($forWhom === 'Someone else' && $me['id']) {
            $apptPatientId = find_or_create_dependent($pdo, $me['id'], $patientName, $relationship,
                                                      trim($_POST['patient_dob'] ?? '') ?: null,
                                                      $bookDentist, $me['phone'] ?? null) ?: $me['id'];
        }

        $pdo->prepare("INSERT INTO appointments
                       (patient_id,patient_name,dentist,treatment,appointment_date,appointment_time,
                        status,notes,booked_for,relationship,booked_by,reason_for_visit,health_form)
                       VALUES (?,?,?,?,?,?, 'Pending', ?,?,?,?,?,?)")
            ->execute([
                $apptPatientId, $patientName,
                $bookDentist,
                $_POST['treatment'],
                $_POST['date'],
                $_POST['time'],
                $bookingNotes,
                $forWhom, $relationship, $bookedBy, $reason,
                json_encode($healthForm, JSON_UNESCAPED_UNICODE)
            ]);
        // The answers also go onto the patient's record (the family member's own
        // record when booking for someone else), where Records shows them.
        save_patient_health($pdo, $apptPatientId, $healthForm);

        // Email the patient a "request received" confirmation (if email is set up).
        if (!empty($me['email'])) {
            $who = ($forWhom === 'Someone else')
                 ? "<strong>" . e($patientName) . "</strong> (booked by you, relationship: " . e($relationship ?: 'n/a') . ")"
                 : "<strong>" . e($patientName) . "</strong>";
            $cat = message_catalogue()['confirmation'];
            [$mailSubj, $body] = tpl_message($pdo, 'confirmation', $cat['subject'], $cat['body'], [
                'patient'   => $patientName,
                'date'      => date('l, F j, Y', strtotime($_POST['date'])),
                'time'      => $_POST['time'],
                'treatment' => $_POST['treatment'],
                'dentist'   => $bookDentist ?: 'To be assigned',
                'clinic'    => clinic_name($pdo),
            ]);
            $mErr = '';
            send_mail($pdo, $me['email'], $mailSubj, $body, $mErr, 'confirmation');
        }

        log_activity($pdo, 'Booked appointment', $patientName . ' — ' . $_POST['treatment'] . ', '
                     . date('M j, Y', strtotime($_POST['date'])) . ' ' . $_POST['time']);

        $booked = true;
        $confirm = $_POST;   // keep details to show on the success screen
        $confirm['patient_name'] = $patientName;
        $confirm['dentist']      = $bookDentist;
        $confirm['reassigned_from'] = $reassignedFrom;
        $confirm['booked_by']    = $bookedBy;
        $confirm['relationship'] = $relationship;
    }
}

// Treatments, each with a plain-language explanation (shared with the clinic's booking form).
$treatments = clinic_treatments();

// Health questionnaire: start from the answers this patient gave last time
// (they only need to update what changed). After a failed submit, keep what
// they just typed.
$hfPrefill = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($healthForm)) {
    $hfPrefill = $healthForm;
} elseif ($me['id']) {
    [$hfPrefill] = patient_health($pdo, $me['id']);          // their record's latest answers
}
// Family members this account has booked for before — offered in a dropdown so
// their details don't have to be typed again.
$familyList = [];
if ($me['id']) {
    $fl = $pdo->prepare("SELECT id, name, relationship, date_of_birth FROM patients
                          WHERE guardian_patient_id = ? AND status <> 'Archived' ORDER BY name");
    $fl->execute([$me['id']]);
    foreach ($fl->fetchAll() as $f) {
        $parts = preg_split('/\s+/', trim($f['name']), 2);
        $familyList[] = [
            'first' => $parts[0] ?? '', 'last' => $parts[1] ?? '',
            'name'  => $f['name'], 'relationship' => (string)$f['relationship'], 'dob' => (string)$f['date_of_birth'],
            'busy'  => $busyPeople[person_key($f['name'])] ?? null,        // already has an active booking?
        ];
    }
}

// Saved answers of this account holder and of each family member they book for,
// so the questionnaire shows the answers of whoever the booking is for.
$hfMine = $me['id'] ? patient_health($pdo, $me['id'])[0] : [];
$hfFamily = [];                                           // person_key(name) => answers
if ($me['id']) {
    $fm = $pdo->prepare("SELECT id, name FROM patients WHERE guardian_patient_id = ?");
    $fm->execute([$me['id']]);
    foreach ($fm->fetchAll() as $f) { [$ans] = patient_health($pdo, $f['id']); if ($ans) $hfFamily[person_key($f['name'])] = $ans; }
}

// ---- What the date & time pickers treat as unavailable ----
// A booking goes to ANY free dentist, so a date or time is only blocked
// when EVERY active dentist is away or already booked then.
$activeDentists = $pdo->query("SELECT name FROM users WHERE role='dentist' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
$canon = [];                                          // any stored name shape -> dentist's full name
foreach ($activeDentists as $d) foreach (dentist_name_variants($d) as $v) $canon[$v] = $d;
$offBy = []; $busyBy = [];                            // [date][dentist] / [date][dentist][] = time
foreach ($pdo->query("SELECT dentist_name, off_date FROM dentist_daysoff WHERE off_date >= CURDATE()") as $r) {
    if (isset($canon[$r['dentist_name']])) $offBy[$r['off_date']][$canon[$r['dentist_name']]] = true;
}
foreach ($pdo->query("SELECT dentist, appointment_date, appointment_time FROM appointments
                       WHERE status IN ('Pending','Confirmed','Arrived') AND appointment_date >= CURDATE()") as $r) {
    if (isset($canon[$r['dentist']])) $busyBy[$r['appointment_date']][$canon[$r['dentist']]][] = $r['appointment_time'];
}
$nDentists = max(1, count($activeDentists));
$allOffDates = [];                                    // dates when every dentist is away
foreach ($offBy as $date => $who) if (count($who) >= $nDentists) $allOffDates[] = $date;
$takenByDate = [];                                    // times when every dentist is away or within an hour of a booking
foreach ($busyBy as $date => $perDentist) {
    foreach ($slots as $slot) {
        $unavailable = $offBy[$date] ?? [];
        foreach ($perDentist as $dn => $times) if (slots_blocked_by([$slot], $times)) $unavailable[$dn] = true;
        if (count($unavailable) >= $nDentists) $takenByDate[$date][] = $slot;
    }
}

// Past time slots computed in JS in real-time (so the page doesn't go stale)
$todayStr = date('Y-m-d');
$minBookDateStr = date('Y-m-d');   // earliest bookable date — today (passed times are greyed out)

$page_title = "Book Appointment";
$hide_hamburger = true;   // this page has no sidebar, so the menu button has nothing to open
include 'includes/head.php';
?>
<div class="booking-hero">
    <div class="eyebrow">ONLINE BOOKING</div>
    <h1>Book Your Appointment</h1>
    <div class="d-inline-flex align-items-center gap-2 px-3 py-2" style="background:rgba(255,255,255,.12);border-radius:30px;">
        <span class="avatar" style="background:var(--gold);width:26px;height:26px;font-size:.72rem;"><?= strtoupper(substr($me['name'],0,1)) ?></span>
        <strong style="font-size:.9rem;">Booking as <?= e($me['name']) ?></strong> <small>✓</small>
    </div>
</div>

<div class="booking-body">
<?php if ($booked): ?>
    <!-- ===== SUCCESS SCREEN ===== -->
    <div class="wizard-card text-center" style="position:relative;">
        <a href="portal" class="back-arrow" title="Back to Dashboard">&#8592;</a>
        <div style="width:70px;height:70px;background:#fff6e0;border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:2rem;">⏳</div>
        <h2 style="color:var(--teal)">Booking Request Submitted!</h2>

        <?php if (!empty($confirm['booked_by'])): ?>
            <p>Thank you, <b><?= e($confirm['booked_by']) ?></b>! You booked this appointment for
               <b><?= e($confirm['patient_name']) ?></b><?= $confirm['relationship'] ? ' (your ' . e(strtolower($confirm['relationship'])) . ')' : '' ?>.</p>
        <?php else: ?>
            <p>Thank you, <b><?= e($me['name']) ?></b>! Your booking request has been submitted.</p>
        <?php endif; ?>

        <div class="alert" style="background:#fff6e0;border:1px solid var(--gold);color:#8a6d2f;text-align:left;font-size:.9rem;">
            ⏳ <strong>Status: PENDING</strong> — waiting for the dentist to approve.<br>
            Your appointment is <strong>not final yet</strong>. You will be notified once it is confirmed.
        </div>

        <div class="card-box text-start" style="background:#f6f9fa;">
            <div class="flex-between py-1"><span>Patient</span><b><?= e($confirm['patient_name'] ?? $me['name']) ?></b></div>
            <?php if (!empty($confirm['booked_by'])): ?>
                <div class="flex-between py-1"><span>Booked by</span><b><?= e($confirm['booked_by']) ?><?= $confirm['relationship'] ? ' (' . e($confirm['relationship']) . ')' : '' ?></b></div>
            <?php endif; ?>
            <div class="flex-between py-1"><span>Treatment</span><b><?= e($confirm['treatment']) ?></b></div>
            <?php if (!empty($confirm['reason'])): ?>
                <div class="flex-between py-1"><span>Reason</span><b><?= e($confirm['reason']) ?></b></div>
            <?php endif; ?>
            <div class="flex-between py-1"><span>Date</span><b><?= date('M j, Y', strtotime($confirm['date'])) ?></b></div>
            <div class="flex-between py-1"><span>Time</span><b><?= e($confirm['time']) ?></b></div>
            <div class="flex-between py-1"><span>Dentist</span><b><?= e($confirm['dentist'] ?? '') ?></b></div>
            <?php if (!empty($confirm['reassigned_from'])): ?>
                <div class="text-muted2 pb-1" style="font-size:.8rem;">
                    ℹ️ <?= e($confirm['reassigned_from']) ?> is not available at that time, so
                    <?= e($confirm['dentist']) ?> will see you instead.
                </div>
            <?php endif; ?>
            <div class="flex-between py-1"><span>Status</span><span class="badge-pill b-pending">Pending</span></div>
        </div>

        <p class="text-muted2">
            <?php if (mail_is_ready($pdo) && !empty($me['email'])): ?>
                📧 A confirmation email was sent to <b><?= e($me['email']) ?></b>.
                You will also get a reminder one day before your visit.
            <?php else: ?>
                Track the status of this request on your dashboard.
            <?php endif; ?>
        </p>
        <a href="portal" class="btn btn-teal">&#8592; Back</a>
    </div>

<?php elseif ($pauseMessage): ?>
    <!-- ===== BOOKING PAUSED: explain right away, no form to fill in ===== -->
    <div class="wizard-card text-center" style="position:relative;">
        <a href="portal" class="back-arrow" title="Back to Dashboard">&#8592;</a>
        <div style="width:70px;height:70px;background:#fff0f0;border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:2rem;">⚠️</div>
        <h2 style="color:#c0392b;">Unable to Book Online</h2>
        <div class="alert text-start mt-3" style="background:#fff6f5;border:1px solid #f0c9c9;color:#5b2b27;font-size:.95rem;line-height:1.6;">
            <?= $pauseMessage /* built above from escaped text + intentional <strong>/<br> */ ?>
        </div>
        <a href="portal" class="btn btn-teal">&#8592; Back to my appointments</a>
    </div>

<?php else: ?>
    <!-- ===== THE WIZARD ===== -->
    <div class="wizard-card" style="position:relative;">
        <a href="portal" class="back-arrow" title="Back to Dashboard">&#8592;</a>

        <?php if ($bookError): ?>
        <!-- Booking error modal — shown automatically on page load -->
        <div class="modal fade" id="bookErrorModal" tabindex="-1" data-bs-backdrop="static">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header" style="background:#fff0f0;border-bottom:1px solid #f0c9c9;">
                <h5 class="modal-title" style="color:#c0392b;">⚠️ Unable to Book</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body" style="font-size:.95rem;line-height:1.6;">
                <?= $bookError /* already contains safe HTML — strong tags are intentional */ ?>
              </div>
              <div class="modal-footer">
                <a href="portal" class="btn btn-light">&#8592; Back</a>
                <button type="button" class="btn btn-teal" data-bs-dismiss="modal">OK</button>
              </div>
            </div>
          </div>
        </div>
        <script>
          // Show the error modal as soon as Bootstrap is ready
          document.addEventListener('DOMContentLoaded', function(){
              new bootstrap.Modal(document.getElementById('bookErrorModal')).show();
          });
        </script>
        <?php endif; ?>
        <!-- Step indicator -->
        <div class="steps">
            <div class="step active" id="ind-1"><div class="dot">1</div><small>Schedule</small></div>
            <div class="step"        id="ind-2"><div class="dot">2</div><small>Personal Info</small></div>
            <div class="step"        id="ind-3"><div class="dot">3</div><small>Service</small></div>
            <div class="step"        id="ind-4"><div class="dot">4</div><small>Confirm</small></div>
        </div>

        <form method="POST" id="bookForm">
            <input type="hidden" name="action" value="book">

            <!-- STEP 1: Schedule (shown first) -->
            <div class="wizard-step" id="step-1">
                <h3>Pick a Date &amp; Time</h3>
                <p class="text-muted2">Choose a convenient date and available time slot.</p>

                <?php if (!empty($myDaysOff)): ?>
                    <div class="alert" style="background:#fff6e0;border:1px solid var(--gold);color:#8a6d2f;font-size:.85rem;">
                        📅 Your dentist, <strong><?= e($myDentist) ?></strong>, is away on these dates.
                        You can still book them — another available dentist will see you:
                        <div class="mt-2 d-flex flex-wrap gap-1">
                            <?php foreach ($myDaysOff as $d): ?>
                                <span class="badge-pill b-cancelled"><?= date('M j, Y', strtotime($d['off_date'])) ?><?= $d['reason'] ? ' · '.e($d['reason']) : '' ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <label class="field-label">Date</label>
                <input type="date" name="date" id="sel-date" class="form-control mb-1"
                       value="<?= $minBookDateStr ?>" min="<?= $minBookDateStr ?>" onchange="checkDate()" required>
                <div class="text-muted2 mb-1" style="font-size:.76rem;">You can book for today — times that have already passed are greyed out.</div>
                <div id="date-warn" class="text-danger small mb-2" style="display:none;"></div>

                <label class="field-label">Available Slots</label>
                <div class="slot-grid mb-2" id="slotGrid">
                    <!-- Slots rendered by JS so past/booked logic is always fresh -->
                </div>
                <input type="hidden" name="time" id="sel-time" required>
                <div id="slot-warn" class="text-danger small mb-2" style="display:none;">Please select a time slot.</div>
                <div class="text-end">
                    <button type="button" class="btn btn-teal" id="schedule-continue-btn" onclick="validateSchedule()" disabled>Continue →</button>
                </div>
            </div>

            <!-- STEP 2: Service -->
            <div class="wizard-step d-none" id="step-3">
                <h3>Service</h3>
                <p class="text-muted2">Choose the treatment you would like, then tell us about your health.</p>

                <?= treatment_picker_assets() ?>
                <div class="field-label">Preferred treatment * <span class="text-muted2" style="text-transform:none;letter-spacing:0;">— choose 1 to <?= MAX_TREATMENTS ?></span></div>
                <?= treatment_picker(array_filter(explode(', ', (string)($_POST['treatment'] ?? ''))), 'bk') ?>
                <div id="tp-warn" class="text-danger small mb-1" style="display:none;">Please choose at least one treatment.</div>
                <div class="text-muted2 mb-3" style="font-size:.8rem;">
                    Not sure what you need? Choose <b>Consultation</b> — the dentist will check and confirm the right treatment.
                    Our clinic will assign an available dentist for your visit.
                </div>

                <input type="hidden" name="pref_dentist" value="">

                <?= health_form_styles() ?>
                <div id="hf-person-note" class="text-muted2 mb-1" style="font-size:.82rem;"><?= ($_SERVER['REQUEST_METHOD'] !== 'POST' && $hfMine) ? 'These are your saved answers — please review and update them.' : '' ?></div>
                <div id="hf-wrap"><?= health_form_fields($hfPrefill) ?></div>
                <div id="hf-warn" class="text-danger small mb-2" style="display:none;"></div>

                <div class="flex-between">
                    <button type="button" class="btn btn-outline-teal" onclick="goStep(2)">← Back</button>
                    <button type="button" class="btn btn-teal" onclick="validateStep2()">Continue →</button>
                </div>
            </div>

            <!-- STEP 3: Personal Info -->
            <div class="wizard-step d-none" id="step-2">
                <h3>Personal Information</h3>
                <p class="text-muted2">Your details are pre-filled. You can book for yourself or for someone else (e.g. your child).</p>

                <label class="field-label">Who is this appointment for?</label>
                <div class="d-flex gap-3 mb-3">
                    <label class="d-flex align-items-center gap-1" style="cursor:pointer;">
                        <input type="radio" name="for" value="myself" <?= $ownerBusyDate ? 'disabled' : 'checked' ?> onclick="toggleFor()"> Myself
                    </label>
                    <label class="d-flex align-items-center gap-1" style="cursor:pointer;">
                        <input type="radio" name="for" value="other" <?= $ownerBusyDate ? 'checked' : '' ?> onclick="toggleFor()"> Someone else (e.g. my child)
                    </label>
                </div>
                <?php if ($ownerBusyDate): ?>
                <div class="alert alert-warning py-2 mb-3" style="font-size:.83rem;">
                    📅 You already have an appointment on <strong><?= date('M j, Y', strtotime($ownerBusyDate)) ?></strong>.
                    Each person can only have one booking at a time, so this booking must be for <strong>someone else</strong>.
                </div>
                <?php endif; ?>
                <div id="dependent-note" class="alert alert-light border py-2 mb-3" style="display:none;font-size:.83rem;">
                    ℹ️ <?= $familyList ? 'Choose someone you booked for before, or pick <b>Someone new</b> and enter their name.' : "Enter the patient's name below." ?>
                    The appointment will still be under your account.
                </div>

                <div id="rel-wrap" style="display:none;">
                    <?php if ($familyList): ?>
                    <label class="field-label" for="sel-family">Who is it?</label>
                    <select id="sel-family" class="form-select mb-2" onchange="pickFamily()">
                        <option value="">➕ Someone new</option>
                        <?php foreach ($familyList as $i => $f): ?>
                            <option value="<?= $i ?>" <?= $f['busy'] ? 'disabled' : '' ?>>
                                <?= e($f['name']) ?><?= $f['relationship'] ? ' (' . e($f['relationship']) . ')' : '' ?><?= $f['busy'] ? ' — has an appointment on ' . date('M j, Y', strtotime($f['busy'])) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <label class="field-label">Your relationship to the patient *</label>
                    <select name="relationship" id="sel-rel" class="form-select mb-1" onchange="relOther()">
                        <option value="">— Please select —</option>
                        <option>Parent</option>
                        <option>Guardian</option>
                        <option>Spouse</option>
                        <option>Child</option>
                        <option>Sibling</option>
                        <option>Grandparent</option>
                        <option>Other relative</option>
                    </select>
                    <div id="rel-other-wrap" style="display:none;">
                        <input type="text" name="relationship_other" id="sel-rel-other" class="form-control mb-1" maxlength="40"
                               placeholder="Please specify, e.g. Cousin, Aunt, Nephew">
                    </div>
                    <div id="rel-warn" class="text-danger small mb-2" style="display:none;">Please choose your relationship to the patient.</div>

                    <label class="field-label">Patient's Date of Birth</label>
                    <input type="date" name="patient_dob" id="sel-dob" class="form-control mb-1"
                           min="1900-01-01" max="<?= birth_date_max() ?>">
                    <div class="text-muted2 mb-2" style="font-size:.8rem;">The date of birth of the person you are booking for (at least <?= MIN_PATIENT_AGE ?> years old).</div>
                </div>

                <div class="row">
                    <div class="col"><label class="field-label">First Name</label>
                        <input name="fname" id="sel-fname" class="form-control mb-1" value="<?= e(explode(' ',$me['name'])[0]) ?>" readonly>
                    </div>
                    <div class="col"><label class="field-label">Last Name</label>
                        <input name="lname" id="sel-lname" class="form-control mb-1" value="<?= e(trim(substr(strstr($me['name'],' '),1))) ?>" readonly>
                    </div>
                </div>
                <div id="name-warn" class="text-danger small mb-2" style="display:none;">Please enter the patient's first and last name.</div>

                <label class="field-label mt-2">Email Address (from your account)</label>
                <input class="form-control mb-3" value="<?= e($me['email']) ?>" readonly style="background:#eef7f6;color:var(--teal);">

                <label class="field-label">Phone Number *</label>
                <input name="phone" id="sel-phone" class="form-control mb-1" value="<?= e($me['phone']) ?>" placeholder="09XX XXX XXXX"
                       <?= phone_input_attrs() ?>>
                <div id="phone-warn" class="text-danger small mb-3" style="display:none;">Please enter your phone number.</div>

                <div class="flex-between">
                    <button type="button" class="btn btn-outline-teal" onclick="goStep(1)">← Back</button>
                    <button type="button" class="btn btn-teal" onclick="validateStep3()">Continue →</button>
                </div>
            </div>

            <!-- STEP 4: Confirm -->
            <div class="wizard-step d-none" id="step-4">
                <h3>Confirm Appointment</h3>
                <p class="text-muted2">Review your details before submitting.</p>
                <div class="card-box" style="background:#f6f9fa;">
                    <small class="text-muted2">PATIENT</small>
                    <p class="mb-0">👤 <span id="rev-name"><?= e($me['name']) ?></span><br>✉️ <?= e($me['email']) ?><br>📞 <span id="rev-phone"><?= e($me['phone']) ?></span></p>
                </div>
                <div class="card-box" style="background:#fdfaf4;">
                    <small class="text-muted2">APPOINTMENT</small>
                    <p class="mb-0">🦷 <span id="rev-treat"></span><br>📅 <span id="rev-date"></span><br>🕐 <span id="rev-time"></span><br>👨‍⚕️ <span id="rev-dentist"></span></p>
                </div>

                <div class="alert" style="background:#fff6e0;border:1px solid var(--gold);color:#8a6d2f;font-size:.85rem;">
                    ⏳ Your booking will be submitted as <strong>PENDING</strong> — it is not final until the
                    dentist approves it. You will be notified once it is confirmed.
                </div>

                <label class="d-flex gap-2 align-items-start mb-1">
                    <input type="checkbox" id="agree" class="mt-1">
                    <span class="small">
                        I confirm the information above is accurate and I agree to the
                        <a href="#" onclick="showPolicy('policy');return false;" style="text-decoration:underline;color:var(--teal);font-weight:600;">Appointment Policy</a>
                        and the
                        <a href="#" onclick="showPolicy('privacy');return false;" style="text-decoration:underline;color:var(--teal);font-weight:600;">Privacy Terms</a>. *
                    </span>
                </label>
                <div id="agree-warn" class="text-danger small mb-3" style="display:none;">You must agree to the Appointment Policy and Privacy Terms before booking.</div>

                <div class="flex-between">
                    <button type="button" class="btn btn-outline-teal" onclick="goStep(3)">← Back</button>
                    <button type="submit" class="btn btn-gold" onclick="return checkAgree()">✓ Submit Booking Request</button>
                </div>
            </div>
        </form>
    </div>
<?php endif; ?>

<!-- ===== Appointment Policy / Privacy Terms (real content) ===== -->
<div class="modal fade" id="policyModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="policy-title">Appointment Policy</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" style="font-size:.92rem;line-height:1.7;">

        <div id="policy-body-policy">
          <?= policy_html(policy_text($pdo, 'terms'), 'h6') ?>
        </div>

        <div id="policy-body-privacy" style="display:none;">
          <?= policy_html(policy_text($pdo, 'privacy'), 'h6') ?>
        </div>

      </div>
      <div class="modal-footer"><button class="btn btn-teal" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>
</div>

<style>
/* ── Back arrow inside wizard card ── */
.back-arrow {
    position: absolute;
    top: 14px;
    left: 16px;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    background: #f0f5f4;
    color: var(--teal-dark, #1a5c54);
    font-size: 1.1rem;
    text-decoration: none;
    transition: background .15s;
    line-height: 1;
}
.back-arrow:hover { background: #d6ecea; color: var(--teal-dark, #1a5c54); }

/* ── Compact hero ── */
.booking-hero          { padding: 20px 20px 30px !important; }
.booking-hero h1       { font-size: 1.8rem !important; margin: 4px 0 6px !important; }

/* ── Whole page beige ── */
body,
.main,
.app-wrap,
.booking-body          { background: #f6f1e9 !important; }
.main                  { padding: 0 !important; }
.booking-body          { min-height: calc(100vh - 120px) !important; padding: 20px 16px 40px !important; display: flex !important; align-items: flex-start !important; justify-content: center !important; }

/* ── Card: floats on green, separated from hero ── */
.wizard-card           { padding: 18px 20px !important; margin: 0 !important; max-width: 540px !important; width: 100% !important; }
.wizard-card h3        { font-size: 1.1rem; margin-top: 4px !important; margin-bottom: 2px; }
.wizard-card p.text-muted2 { font-size: .8rem; margin-bottom: 6px; }

/* ── Step indicator: smaller dots, shifted down to clear back arrow ── */
.steps                 { margin-bottom: 8px !important; margin-top: 28px !important; }
.steps .dot            { width: 28px !important; height: 28px !important; font-size: .82rem; }
.steps .step small     { font-size: .65rem; }

/* ── Slot grid: 3 columns, smaller slots ── */
.slot-grid             { grid-template-columns: 1fr 1fr 1fr !important; gap: 7px !important; }
.slot                  { padding: 8px 4px !important; font-size: .82rem !important; border-radius: 8px !important; }

/* ── Booked/passed label inside slot ── */
.slot.taken small {
    display: block;
    font-size: .58rem;
    letter-spacing: .03em;
    text-transform: uppercase;
    margin-top: 1px;
    opacity: .75;
}

/* ── Form fields: slightly less spacing ── */
.wizard-card .mb-3     { margin-bottom: .6rem !important; }
.wizard-card .mb-2     { margin-bottom: .4rem !important; }
.wizard-card .mb-1     { margin-bottom: .25rem !important; }
.wizard-card label.field-label { margin-top: 4px; }
.wizard-card .form-control,
.wizard-card .form-select { padding: .3rem .6rem; font-size: .88rem; }
</style>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Dates/days the patient must avoid (from the dentist's days off and clinic open days).
const DAYS_OFF   = <?= json_encode($allOffDates) ?>;   // dates when NO dentist is available   // e.g. ['2026-07-10', ...]
const OPEN_DAYS  = <?= json_encode($openDaysArr) ?>;      // e.g. ['Mon','Tue',...]
const SHORT_DAYS = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
const TODAY_STR  = <?= json_encode($todayStr) ?>;
const MIN_BOOK_DATE = <?= json_encode($minBookDateStr) ?>;   // earliest selectable date — today
const TAKEN_BY_DATE = <?= json_encode($takenByDate) ?>;   // times when every dentist is away or booked
const ALL_SLOTS  = <?= json_encode($slots) ?>;            // all clinic time slots

// A date as "YYYY-MM-DD" in LOCAL time. (toISOString() uses UTC, which in the
// Philippines, UTC+8, turns midnight of the 28th into the 27th — yesterday.)
function ymd(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

// The clinic's current time (Asia/Manila), whatever timezone the phone or
// computer is set to — so "passed" times always match the clinic's clock.
function clinicNowMinutes() {
    try {
        var p = new Intl.DateTimeFormat('en-GB', {timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', hour12: false})
                    .formatToParts(new Date());
        var h = 0, m = 0;
        p.forEach(function (x) { if (x.type === 'hour') h = parseInt(x.value, 10) % 24; if (x.type === 'minute') m = parseInt(x.value, 10); });
        return h * 60 + m;
    } catch (e) { var n = new Date(); return n.getHours() * 60 + n.getMinutes(); }
}

// Returns true if a slot string like "01:30 PM" is already past right now
function isSlotPast(slotStr) {
    var parts = slotStr.match(/^(\d+):(\d+)\s*(AM|PM)$/i);
    if (!parts) return false;
    var h = parseInt(parts[1], 10);
    var m = parseInt(parts[2], 10);
    var ampm = parts[3].toUpperCase();
    if (ampm === 'PM' && h !== 12) h += 12;
    if (ampm === 'AM' && h === 12) h = 0;
    var slotMinutes = h * 60 + m;
    return slotMinutes <= clinicNowMinutes();
}

// Step 2 (Personal Info): require phone, and the patient's name.
function validateStep3() {
    var phone = document.getElementById('sel-phone').value.trim();
    var fn = document.getElementById('sel-fname').value.trim();
    var ln = document.getElementById('sel-lname').value.trim();

    if (fn === '' || ln === '') {
        document.getElementById('name-warn').style.display = 'block';
        return;
    }
    document.getElementById('name-warn').style.display = 'none';

    // One booking per person: this name must not already have an active one.
    var busy = <?= json_encode($busyPeople, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
    var key = (fn + ' ' + ln).toLowerCase().replace(/\s+/g, ' ').trim();
    if (busy[key]) {
        var nw = document.getElementById('name-warn');
        nw.textContent = (fn + ' ' + ln) + ' already has an appointment (' + busy[key] + '). '
                       + 'Each person can only have one booking at a time — please book for a different person.';
        nw.style.display = 'block';
        return;
    }
    document.getElementById('name-warn').textContent = "Please enter the patient's first and last name.";

    // When booking for someone else, the relationship AND their birthday are required.
    var forOther = document.querySelector('input[name="for"]:checked').value === 'other';
    if (forOther && document.getElementById('sel-rel').value === '') {
        document.getElementById('rel-warn').textContent = 'Please choose your relationship to the patient.';
        document.getElementById('rel-warn').style.display = 'block';
        return;
    }
    if (forOther && document.getElementById('sel-rel').value === 'Other relative'
        && document.getElementById('sel-rel-other').value.trim() === '') {
        document.getElementById('rel-warn').textContent = 'Please type your relationship to the patient (e.g. Cousin, Aunt).';
        document.getElementById('rel-warn').style.display = 'block';
        document.getElementById('sel-rel-other').focus();
        return;
    }
    document.getElementById('rel-warn').style.display = 'none';

    if (forOther && document.getElementById('sel-dob').value === '') {
        alert("Please enter the patient's date of birth.");
        document.getElementById('sel-dob').focus();
        return;
    }
    if (forOther && document.getElementById('sel-dob').value > document.getElementById('sel-dob').max) {
        alert("The date of birth must be at least <?= MIN_PATIENT_AGE ?> years ago.");
        document.getElementById('sel-dob').focus();
        return;
    }

    var phoneMsg = phoneProblem(phone, true);
    if (phoneMsg) {
        document.getElementById('phone-warn').textContent = phoneMsg;
        document.getElementById('phone-warn').style.display = 'block';
        return;
    }
    document.getElementById('phone-warn').style.display = 'none';
    hfPrefillFor();          // show the health answers of the person this booking is for
    goStep(3);
}

// "Other relative": ask which relative.
function relOther() {
    var other = document.getElementById('sel-rel').value === 'Other relative';
    document.getElementById('rel-other-wrap').style.display = other ? '' : 'none';
    if (other) document.getElementById('sel-rel-other').focus();
}

// Step 2: every required health question answered (the browser marks the first one missing).
function validateStep2() {
    if (tpPicked('bk-list').length === 0) {
        document.getElementById('tp-warn').style.display = 'block';
        document.getElementById('bk-list').scrollIntoView({ block: 'center' });
        return;
    }
    document.getElementById('tp-warn').style.display = 'none';
    var wrap = document.getElementById('hf-wrap');
    var bad = [].slice.call(wrap.querySelectorAll('input[required]')).find(function (el) { return !el.checkValidity(); });
    var warn = document.getElementById('hf-warn');
    if (bad) {
        warn.textContent = 'Please answer every question marked * in the health questionnaire.';
        warn.style.display = 'block';
        bad.scrollIntoView({ block: 'center' }); bad.focus();
        return;
    }
    var need = [['allergy','which medicine or stuff you are allergic to'], ['anesthesia','what trouble you had with local anesthesia']];
    for (var i = 0; i < need.length; i++) {
        var yes = wrap.querySelector('[name="hf[' + need[i][0] + ']"][value="yes"]:checked');
        var det = wrap.querySelector('[name="hf[' + need[i][0] + '_detail]"]');
        if (yes && det && det.value.trim() === '') {
            warn.textContent = 'Please tell us ' + need[i][1] + '.';
            warn.style.display = 'block'; det.focus(); return;
        }
    }
    warn.style.display = 'none';
    goStep(4);
}

// ---- Health questionnaire follows the person the booking is for ----
// Myself -> my saved answers; someone already on file -> theirs; someone new -> blank.
// Only refilled when the person changes, so going back and forth keeps edits.
var HF_MINE   = <?= json_encode((object)$hfMine, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
var HF_FAMILY = <?= json_encode((object)$hfFamily, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
var hfPerson  = <?= json_encode($_SERVER['REQUEST_METHOD'] === 'POST' ? 'posted' : '__me') ?>;
function hfPrefillFor() {
    var other = document.querySelector('input[name="for"]:checked').value === 'other';
    var name  = (document.getElementById('sel-fname').value + ' ' + document.getElementById('sel-lname').value)
                  .toLowerCase().replace(/\s+/g, ' ').trim();
    var key   = other ? 'o:' + name : '__me';
    if (key === hfPerson) return;
    hfPerson = key;
    var known = other ? HF_FAMILY[name] : HF_MINE;
    hfFill(document.getElementById('hf-wrap'), known || {});
    var note = document.getElementById('hf-person-note');
    if (note) note.textContent = other
        ? (known ? 'These are ' + document.getElementById('sel-fname').value + "'s saved answers — please review them."
                 : 'Please answer for ' + (document.getElementById('sel-fname').value || 'the patient') + '.')
        : (Object.keys(HF_MINE).length ? 'These are your saved answers — please review and update them.' : '');
}

// "Who is it?" — someone booked before: fill in their details (name locked);
// "Someone new": empty fields to type in.
var FAMILY = <?= json_encode($familyList, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
function pickFamily() {
    var sel = document.getElementById('sel-family'), f = sel && sel.value !== '' ? FAMILY[+sel.value] : null;
    var fn = document.getElementById('sel-fname'), ln = document.getElementById('sel-lname');
    var rel = document.getElementById('sel-rel'), relOtherBox = document.getElementById('sel-rel-other');
    if (f) {
        fn.value = f.first; ln.value = f.last; fn.readOnly = true; ln.readOnly = true;
        var known = [].some.call(rel.options, function (o) { return o.value === f.relationship || o.text === f.relationship; });
        if (f.relationship && !known) { rel.value = 'Other relative'; relOtherBox.value = f.relationship; }
        else { rel.value = f.relationship; relOtherBox.value = ''; }
        document.getElementById('sel-dob').value = f.dob || '';
    } else {
        fn.value = ''; ln.value = ''; fn.readOnly = false; ln.readOnly = false;
        rel.value = ''; relOtherBox.value = ''; document.getElementById('sel-dob').value = '';
        fn.focus();
    }
    relOther();
}

// Toggle between booking for "myself" (name locked) and "someone else" (name editable).
<?php if ($ownerBusyDate): ?>
document.addEventListener('DOMContentLoaded', function () { toggleFor(); });
<?php endif; ?>
function toggleFor() {
    var forOther = document.querySelector('input[name="for"]:checked').value === 'other';
    var fn = document.getElementById('sel-fname');
    var ln = document.getElementById('sel-lname');
    document.getElementById('dependent-note').style.display = forOther ? 'block' : 'none';
    document.getElementById('rel-wrap').style.display       = forOther ? 'block' : 'none';
    var fam = document.getElementById('sel-family');
    if (forOther) {
        fn.readOnly = false; ln.readOnly = false;
        fn.value = ''; ln.value = '';
        if (fam) { fam.value = ''; pickFamily(); } else { fn.focus(); }
    } else {
        fn.readOnly = true; ln.readOnly = true;
        fn.value = <?= json_encode(explode(' ', $me['name'])[0]) ?>;
        ln.value = <?= json_encode(trim(substr(strstr($me['name'],' '),1))) ?>;
        document.getElementById('sel-rel').value = '';
    }
}

// Show the Appointment Policy or the Privacy Terms in a pop-up.
function showPolicy(which) {
    document.getElementById('policy-title').textContent =
        (which === 'privacy') ? 'Privacy Terms' : 'Appointment Policy';
    document.getElementById('policy-body-policy').style.display  = (which === 'policy')  ? 'block' : 'none';
    document.getElementById('policy-body-privacy').style.display = (which === 'privacy') ? 'block' : 'none';
    new bootstrap.Modal(document.getElementById('policyModal')).show();
}

// Re-render the slot grid based on the chosen date.
function renderSlots(dateVal) {
    var bookedOnDate = TAKEN_BY_DATE[dateVal] || [];
    var isToday = (dateVal === TODAY_STR);
    var grid = document.getElementById('slotGrid');
    grid.innerHTML = '';
    document.getElementById('sel-time').value = '';   // clear time on date change
    document.getElementById('schedule-continue-btn').disabled = true;   // no time chosen yet for this date
    ALL_SLOTS.forEach(function(s) {
        var isPast   = isToday && isSlotPast(s);
        var isBooked = bookedOnDate.indexOf(s) !== -1;
        var disabled = isPast || isBooked;
        var div = document.createElement('div');
        div.className = 'slot' + (disabled ? ' taken' : '');
        var label = isBooked ? 'booked' : '';
        div.innerHTML = s + (label ? '<small>' + label + '</small>' : '');
        if (!disabled) {
            // IIFE captures the correct slot value per iteration
            (function(slot){ div.onclick = function(){ pickSlot(this, slot); }; })(s);
        }
        grid.appendChild(div);
    });
}

// Check the chosen date is an open day and not a dentist day off.
function checkDate() {
    var v = document.getElementById('sel-date').value;
    var warn = document.getElementById('date-warn');
    if (!v) return true;
    var d  = new Date(v + 'T00:00:00');
    var wd = SHORT_DAYS[d.getDay()];

    // No past dates (today is fine; its passed times are greyed out in the grid).
    // Blocks it even if a browser lets someone type a date past the "min" attribute.
    if (v < MIN_BOOK_DATE) {
        warn.textContent = 'That date has already passed. Please pick ' +
            new Date(MIN_BOOK_DATE + 'T00:00:00').toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) +
            ' or a later date.';
        warn.style.display = 'block';
        return false;
    }

    // If the clinic is closed that weekday, or it's a dentist day off, don't
    // just show an error — skip straight to the next date that actually
    // works, so the patient never lands on a date they can't book.
    var closedDay  = OPEN_DAYS.indexOf(wd) === -1;
    var dentistOff = DAYS_OFF.indexOf(v) !== -1;
    if (closedDay || dentistOff) {
        var reason = closedDay ? ('The clinic is closed on ' + wd + 's')
                                : "No dentist is available that day";
        var nextDate = nextOpenDate(v);
        document.getElementById('sel-date').value = nextDate;
        warn.textContent = reason + ' — skipped ahead to the next available date, ' +
            new Date(nextDate + 'T00:00:00').toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) +
            '. Please pick a time slot below.';
        warn.style.display = 'block';
        renderSlots(nextDate);
        // Return false so validateSchedule() stops here instead of silently
        // carrying over a time slot that was chosen for the old (closed) date.
        return false;
    }

    warn.style.display = 'none';
    renderSlots(v);
    return true;
}
function goStep(n) {
    document.querySelectorAll('.wizard-step').forEach(s => s.classList.add('d-none'));
    document.getElementById('step-' + n).classList.remove('d-none');
    for (let i = 1; i <= 4; i++) {
        const ind = document.getElementById('ind-' + i);
        ind.classList.remove('active','done');
        if (i < n) ind.classList.add('done');
        if (i === n) ind.classList.add('active');
    }
    if (n === 4) fillReview();
}

// Find the next bookable date (open day, not a dentist day off, has at least 1
// free slot). Shared by the page-load auto-advance and by checkDate() when
// the patient manually picks a date that's closed / a dentist day off.
function nextOpenDate(fromStr) {
    var d = new Date(fromStr + 'T00:00:00');
    for (var i = 0; i < 60; i++) {
        var wd = SHORT_DAYS[d.getDay()];
        var ds = ymd(d);
        if (OPEN_DAYS.indexOf(wd) !== -1 && DAYS_OFF.indexOf(ds) === -1) {
            // Check if at least one slot is free
            var bookedHere = TAKEN_BY_DATE[ds] || [];
            var isTodayDs = (ds === TODAY_STR);
            var hasFree = ALL_SLOTS.some(function(s){
                var past = isTodayDs && isSlotPast(s);
                return !past && bookedHere.indexOf(s) === -1;
            });
            if (hasFree) return ds;
        }
        d.setDate(d.getDate() + 1);
    }
    return fromStr; // fallback — shouldn't happen
}

// On page load: render the slot grid properly and auto-advance date if all slots are gone.
(function(){
    var dateInput = document.getElementById('sel-date');
    var currentDate = dateInput.value || MIN_BOOK_DATE;
    if (currentDate < MIN_BOOK_DATE) currentDate = MIN_BOOK_DATE;

    // Check if current date still has available slots
    var wd = SHORT_DAYS[new Date(currentDate + 'T00:00:00').getDay()];
    var bookedToday = TAKEN_BY_DATE[currentDate] || [];
    var isTodayCur = (currentDate === TODAY_STR);
    var hasAvailable = ALL_SLOTS.some(function(s){
        var past = isTodayCur && isSlotPast(s);
        return !past && bookedToday.indexOf(s) === -1;
    }) && OPEN_DAYS.indexOf(wd) !== -1 && DAYS_OFF.indexOf(currentDate) === -1;

    if (!hasAvailable) {
        // Advance to next open day with free slots
        var tomorrow = new Date(currentDate + 'T00:00:00');
        tomorrow.setDate(tomorrow.getDate() + 1);
        var nextDate = nextOpenDate(ymd(tomorrow));
        dateInput.value = nextDate;
        currentDate = nextDate;
    }

    // Render slots for the resolved date
    renderSlots(currentDate);


})();

// Highlight the chosen time slot.
function pickSlot(el, time) {
    document.querySelectorAll('.slot').forEach(s => s.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('sel-time').value = time;
    document.getElementById('slot-warn').style.display = 'none';
    document.getElementById('schedule-continue-btn').disabled = false;
}

function validateSchedule() {
    // Save the selected time BEFORE checkDate() re-renders the grid (which clears sel-time)
    var chosenTime = document.getElementById('sel-time').value;
    if (!checkDate()) return;                       // block closed days / dentist days off
    // Restore the chosen time after re-render (it's still valid for this date)
    if (chosenTime) document.getElementById('sel-time').value = chosenTime;
    if (!document.getElementById('sel-time').value) {
        document.getElementById('slot-warn').style.display = 'block';
        return;
    }
    goStep(2);
}

// Copy chosen values into the confirmation screen.
function fillReview() {
    document.getElementById('rev-treat').textContent = tpPicked('bk-list').join(', ');
    document.getElementById('rev-date').textContent  = document.getElementById('sel-date').value;
    document.getElementById('rev-time').textContent  = document.getElementById('sel-time').value;
    document.getElementById('rev-name').textContent  =
        document.getElementById('sel-fname').value + ' ' + document.getElementById('sel-lname').value;
    document.getElementById('rev-phone').textContent = document.getElementById('sel-phone').value;
    document.getElementById('rev-dentist').textContent = 'To be assigned by the clinic';
}

function checkAgree() {
    if (!document.getElementById('agree').checked) {
        document.getElementById('agree-warn').style.display = 'block';
        return false;                       // stops the form from submitting
    }
    document.getElementById('agree-warn').style.display = 'none';
    return true;
}
</script>
</body>
</html>
