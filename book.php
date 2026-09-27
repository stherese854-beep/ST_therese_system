<?php
// ============================================================
//  BOOK APPOINTMENT  (book.php) -- 4-step wizard for patients
// ============================================================
require_once 'config/auth.php';
require_once 'includes/mailer.php';
require_once 'includes/message_templates.php';   // editable message wording   // to email a booking confirmation
require_once 'includes/noshow_check.php';   // patient_noshow_count()
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
while ($t < $endT) { $slots[] = date('h:i A', $t); $t += 30 * 60; }
if (empty($slots)) $slots = ['09:00 AM'];   // safety fallback

// ---- The patient's dentist + that dentist's UPCOMING days off ----
$myDentist = $me['primary_dentist'] ?? '';
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
    $chosen = trim($_POST['pref_dentist'] ?? '');
    if ($chosen !== '') {
        $bookDentist = $chosen;                     // patient picked a specific dentist
    } else {
        $bookDentist = $me['primary_dentist'] ?? '';
        if (empty($bookDentist)) {
            $bookDentist = pick_dentist_for_new_patient($pdo) ?: 'Dr. Ana Santos';
        }
    }

    // Is that dentist unavailable (day off) on the chosen date?
    $off = $pdo->prepare("SELECT reason FROM dentist_daysoff WHERE dentist_name=? AND off_date=?");
    $off->execute([$bookDentist, $_POST['date']]);
    $offRow = $off->fetch();

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
                              WHERE patient_id=? AND status IN ('Pending','Confirmed')");
        $pc->execute([$me['id']]);
        $activeCount = (int)$pc->fetchColumn();
    }
    // (a2) How many appointments has this patient missed RECENTLY?
    // Uses the shared rule: only confirmed no-shows inside the rolling
    // window, and only those after any staff reset. See noshow_check.php.
    $noShowCount = $me['id'] ? patient_noshow_count($pdo, $me['id']) : 0;
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
    // (b) Does this SAME person already have an appointment on this SAME date?
    $isDuplicate = false;
    if ($me['id']) {
        $dc = $pdo->prepare("SELECT COUNT(*) FROM appointments
                             WHERE patient_id=? AND patient_name=? AND appointment_date=? AND status IN ('Pending','Confirmed')");
        $dc->execute([$me['id'], $patientName, $_POST['date']]);
        $isDuplicate = ((int)$dc->fetchColumn()) > 0;
    }

    // (c) SCHEDULING CONFLICT: is that dentist already booked at that exact
    //     date + time by someone else? (a system warning, not just a message)
    $conflict = false;
    if ($bookDentist) {
        $cf = $pdo->prepare("SELECT COUNT(*) FROM appointments
                             WHERE dentist=? AND appointment_date=? AND appointment_time=?
                               AND status IN ('Pending','Confirmed')");
        $cf->execute([$bookDentist, $_POST['date'], $_POST['time']]);
        $conflict = ((int)$cf->fetchColumn()) > 0;
    }

    // Appointments must be booked at least a day ahead — no same-day booking.
    $minBookDate = date('Y-m-d', strtotime('+1 day'));

    if ($_POST['date'] < $minBookDate) {
        $bookError = "Appointments must be booked at least a day in advance. Please pick "
                   . date('M j, Y', strtotime($minBookDate)) . " or a later date.";
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
    } elseif ($offRow) {
        // Dentist marked this day as unavailable -> do NOT book; show an error.
        $bookError = $bookDentist . " is not available on " . date('M j, Y', strtotime($_POST['date']))
                   . ($offRow['reason'] ? " (" . $offRow['reason'] . ")" : "") . ". Please pick another date.";
    } elseif ($activeCount >= 3) {
        $bookError = "You already have 3 upcoming appointments, which is the most an account may hold at once. "
                   . "Once one of them is completed or cancelled, you can book another.";
    } elseif ($isDuplicate) {
        $bookError = $patientName . " already has an appointment on " . date('M j, Y', strtotime($_POST['date'])) . ". Please pick another date.";
    } elseif ($conflict) {
        $bookError = "⚠️ Scheduling conflict: " . $bookDentist . " is already booked at "
                   . $_POST['time'] . " on " . date('M j, Y', strtotime($_POST['date']))
                   . ". Please choose a different time slot.";
    } else {
        // Save the phone number the patient entered (keeps their record current).
        // Numbers only — strip out anything that isn't a digit.
        if ($me['id'] && !empty($_POST['phone'])) {
            $cleanPhone = preg_replace('/[^0-9]/', '', $_POST['phone']);
            $pdo->prepare("UPDATE patients SET phone=? WHERE id=?")->execute([$cleanPhone, $me['id']]);
        }

        $forWhom      = ($_POST['for'] ?? 'myself') === 'other' ? 'Someone else' : 'Myself';
        $relationship = ($forWhom === 'Someone else') ? trim($_POST['relationship'] ?? '') : null;
        $bookedBy     = ($forWhom === 'Someone else') ? $me['name'] : null;
        $reason       = trim($_POST['reason'] ?? '');
        $bookingNotes = trim($_POST['notes'] ?? '');

        // When booking for someone else, record that person's birthday in the notes
        // so the dentist knows the patient's age (for yourself it is already on file).
        if ($forWhom === 'Someone else' && !empty($_POST['patient_dob'])) {
            $dobNote = 'Patient DOB: ' . trim($_POST['patient_dob']);
            $bookingNotes = $bookingNotes === '' ? $dobNote : ($dobNote . ' — ' . $bookingNotes);
        }

        $pdo->prepare("INSERT INTO appointments
                       (patient_id,patient_name,dentist,treatment,appointment_date,appointment_time,
                        status,notes,booked_for,relationship,booked_by,reason_for_visit)
                       VALUES (?,?,?,?,?,?, 'Pending', ?,?,?,?,?)")
            ->execute([
                $me['id'], $patientName,
                $bookDentist,
                $_POST['treatment'],
                $_POST['date'],
                $_POST['time'],
                $bookingNotes,
                $forWhom, $relationship, $bookedBy, $reason
            ]);

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

        $booked = true;
        $confirm = $_POST;   // keep details to show on the success screen
        $confirm['patient_name'] = $patientName;
        $confirm['dentist']      = $bookDentist;
        $confirm['booked_by']    = $bookedBy;
        $confirm['relationship'] = $relationship;
    }
}

$treatments = ['Cleaning','Dental Filling','Tooth Extraction','Root Canal','Dental Crown','Consultation','Braces / Orthodontics'];

// Real taken slots — only CONFIRMED appointments block a slot (Pending stays available)
$takenByDate = [];
$ts = $pdo->prepare("SELECT appointment_date, appointment_time FROM appointments
                      WHERE dentist = ? AND status = 'Confirmed' AND appointment_date >= CURDATE()");
$ts->execute([$myDentist]);
foreach ($ts->fetchAll() as $row) {
    $takenByDate[$row['appointment_date']][] = $row['appointment_time'];
}

// Past time slots computed in JS in real-time (so the page doesn't go stale)
$todayStr = date('Y-m-d');
$minBookDateStr = date('Y-m-d', strtotime('+1 day'));   // earliest bookable date — no same-day booking

$page_title = "Book Appointment";
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
        <a href="portal.php" class="back-arrow" title="Back to Dashboard">&#8592;</a>
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
        <a href="portal.php" class="btn btn-teal">View My Dashboard →</a>
    </div>

<?php else: ?>
    <!-- ===== THE WIZARD ===== -->
    <div class="wizard-card" style="position:relative;">
        <a href="portal.php" class="back-arrow" title="Back to Dashboard">&#8592;</a>

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
                <a href="portal.php" class="btn btn-light">Go to My Portal</a>
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
            <div class="step"        id="ind-2"><div class="dot">2</div><small>Service</small></div>
            <div class="step"        id="ind-3"><div class="dot">3</div><small>Personal Info</small></div>
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
                        ⚠️ <strong><?= e($myDentist) ?></strong> is unavailable on these dates — please avoid them:
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
                <div class="text-muted2 mb-1" style="font-size:.76rem;">Appointments must be booked at least a day in advance.</div>
                <div id="date-warn" class="text-danger small mb-2" style="display:none;"></div>

                <label class="field-label">Available Slots</label>
                <div class="slot-grid mb-2" id="slotGrid">
                    <!-- Slots rendered by JS so past/booked logic is always fresh -->
                </div>
                <input type="hidden" name="time" id="sel-time" required>
                <div id="slot-warn" class="text-danger small mb-2" style="display:none;">Please select a time slot.</div>
                <div class="text-end">
                    <button type="button" class="btn btn-teal" onclick="validateSchedule()">Continue →</button>
                </div>
            </div>

            <!-- STEP 2: Service -->
            <div class="wizard-step d-none" id="step-2">
                <h3>Service</h3>
                <p class="text-muted2">Select the treatment you need.</p>

                <label class="field-label">Treatment *</label>
                <select name="treatment" id="sel-treatment" class="form-select mb-1" required>
                    <?php foreach ($treatments as $t): ?><option><?= $t ?></option><?php endforeach; ?>
                </select>
                <div class="text-muted2 mb-3" style="font-size:.8rem;">
                    Our clinic will assign an available dentist for your visit.
                </div>

                <input type="hidden" name="pref_dentist" value="">

                <label class="field-label">Reason for Visit <span class="text-muted2">(optional)</span></label>
                <input name="reason" class="form-control mb-3" placeholder="e.g. Routine cleaning, toothache on the lower right">

                <label class="field-label">Notes / Concerns</label>
                <textarea name="notes" class="form-control mb-3" rows="3"></textarea>

                <div class="flex-between">
                    <button type="button" class="btn btn-outline-teal" onclick="goStep(1)">← Back</button>
                    <button type="button" class="btn btn-teal" onclick="goStep(3)">Continue →</button>
                </div>
            </div>

            <!-- STEP 3: Personal Info -->
            <div class="wizard-step d-none" id="step-3">
                <h3>Personal Information</h3>
                <p class="text-muted2">Your details are pre-filled. You can book for yourself or for someone else (e.g. your child).</p>

                <label class="field-label">Who is this appointment for?</label>
                <div class="d-flex gap-3 mb-3">
                    <label class="d-flex align-items-center gap-1" style="cursor:pointer;">
                        <input type="radio" name="for" value="myself" checked onclick="toggleFor()"> Myself
                    </label>
                    <label class="d-flex align-items-center gap-1" style="cursor:pointer;">
                        <input type="radio" name="for" value="other" onclick="toggleFor()"> Someone else (e.g. my child)
                    </label>
                </div>
                <div id="dependent-note" class="alert alert-light border py-2 mb-3" style="display:none;font-size:.83rem;">
                    ℹ️ Enter the patient's name below. The appointment will still be under your account.
                </div>

                <div id="rel-wrap" style="display:none;">
                    <label class="field-label">Your relationship to the patient *</label>
                    <select name="relationship" id="sel-rel" class="form-select mb-1">
                        <option value="">— Please select —</option>
                        <option>Parent</option>
                        <option>Guardian</option>
                        <option>Spouse</option>
                        <option>Child</option>
                        <option>Sibling</option>
                        <option>Grandparent</option>
                        <option>Other relative</option>
                    </select>
                    <div id="rel-warn" class="text-danger small mb-2" style="display:none;">Please choose your relationship to the patient.</div>

                    <label class="field-label">Patient's Date of Birth</label>
                    <input type="date" name="patient_dob" id="sel-dob" class="form-control mb-1"
                           max="<?= date('Y-m-d') ?>">
                    <div class="text-muted2 mb-2" style="font-size:.8rem;">The date of birth of the person you are booking for.</div>
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
                <input name="phone" id="sel-phone" class="form-control mb-1" value="<?= e($me['phone']) ?>"
                       inputmode="numeric" maxlength="15" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                <div id="phone-warn" class="text-danger small mb-3" style="display:none;">Please enter your phone number.</div>

                <div class="flex-between">
                    <button type="button" class="btn btn-outline-teal" onclick="goStep(2)">← Back</button>
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
          <h6>1. Booking and approval</h6>
          <p>All online bookings are submitted as <strong>Pending</strong>. An appointment is only final
             once the clinic or the assigned dentist approves it. You will be notified when the status changes.</p>

          <h6>2. Arrival time</h6>
          <p>Please arrive at least <strong>10 minutes before</strong> your scheduled time. Arriving more than
             15 minutes late may mean your slot is given to the next patient and your visit is rescheduled.</p>

          <h6>3. Cancelling or rescheduling</h6>
          <p>You may cancel or reschedule from your patient dashboard. We ask that you do so at least
             <strong>24 hours before</strong> your appointment so the slot can be offered to someone else.</p>

          <h6>4. Missed appointments (no-shows)</h6>
          <p>Not arriving without cancelling is recorded as a <strong>no-show</strong>. Repeated no-shows
             (3 or more) may mean you are asked to book by phone instead of online.</p>

          <h6>5. Booking for another person</h6>
          <p>You may book on behalf of a family member or dependent. You must state your relationship to
             that patient, and you are responsible for the accuracy of the information you give.</p>

          <h6>6. Limits</h6>
          <p>To keep slots fair for everyone, each account may hold a maximum of <strong>3 upcoming
             appointments</strong> at any one time, and the same patient may not be booked twice on the
             same day. Once a visit is completed or cancelled, a place frees up.</p>

          <h6>7. Dentist assignment</h6>
          <p>The clinic assigns an available dentist for your visit. If you have been seen before,
             we try to keep you with your usual dentist for continuity of care.</p>
        </div>

        <div id="policy-body-privacy" style="display:none;">
          <h6>1. What we collect</h6>
          <p>We collect the information you give us: your name, email address, contact number, date of birth,
             and the details of your dental visits — including treatments, dental charts, X-ray images, and
             clinical notes recorded by your dentist.</p>

          <h6>2. Why we collect it</h6>
          <p>Your information is used only to provide dental care: to schedule appointments, keep your dental
             records, send confirmations and reminders, and issue reports your dentist needs for treatment.</p>

          <h6>3. Who can see it</h6>
          <p>Your records can be seen by the clinic's administrators and by the dentist assigned to you.
             Dentists can only view the records of their own assigned patients. We do <strong>not</strong> sell
             or share your information with advertisers or other third parties.</p>

          <h6>4. How it is protected</h6>
          <p>Access requires a login. Passwords are stored in encrypted (hashed) form and are never visible to
             staff. Only authorised staff accounts can open patient records.</p>

          <h6>5. Messages you will receive</h6>
          <p>By booking, you agree to receive appointment-related messages by email or SMS — a verification
             code, a booking confirmation, and a reminder one day before your visit. These are service
             messages, not marketing.</p>

          <h6>6. Keeping and deleting records</h6>
          <p>Dental records are kept for as long as needed for your care. You may ask the clinic to correct
             your personal details at any time from your dashboard, or request that your account be deleted.</p>

          <h6>7. Your rights</h6>
          <p>Under the Philippine <strong>Data Privacy Act of 2012 (RA 10173)</strong>, you have the right to be
             informed, to access, to correct, and to object to the processing of your personal data. To exercise
             these rights, contact the clinic directly.</p>
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
const DAYS_OFF   = <?= json_encode($myDaysOffDates) ?>;   // e.g. ['2026-07-10', ...]
const OPEN_DAYS  = <?= json_encode($openDaysArr) ?>;      // e.g. ['Mon','Tue',...]
const SHORT_DAYS = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
const TODAY_STR  = <?= json_encode($todayStr) ?>;
const MIN_BOOK_DATE = <?= json_encode($minBookDateStr) ?>;   // earliest selectable date — no same-day booking
const TAKEN_BY_DATE = <?= json_encode($takenByDate) ?>;   // confirmed bookings by date
const ALL_SLOTS  = <?= json_encode($slots) ?>;            // all clinic time slots

// Returns true if a slot string like "01:30 PM" is already past right now
function isSlotPast(slotStr) {
    var now = new Date();
    var parts = slotStr.match(/^(\d+):(\d+)\s*(AM|PM)$/i);
    if (!parts) return false;
    var h = parseInt(parts[1], 10);
    var m = parseInt(parts[2], 10);
    var ampm = parts[3].toUpperCase();
    if (ampm === 'PM' && h !== 12) h += 12;
    if (ampm === 'AM' && h === 12) h = 0;
    var slotMinutes = h * 60 + m;
    var nowMinutes  = now.getHours() * 60 + now.getMinutes();
    return slotMinutes <= nowMinutes;
}

// Step 3: require phone, and the patient's name.
function validateStep3() {
    var phone = document.getElementById('sel-phone').value.trim();
    var fn = document.getElementById('sel-fname').value.trim();
    var ln = document.getElementById('sel-lname').value.trim();

    if (fn === '' || ln === '') {
        document.getElementById('name-warn').style.display = 'block';
        return;
    }
    document.getElementById('name-warn').style.display = 'none';

    // When booking for someone else, the relationship AND their birthday are required.
    var forOther = document.querySelector('input[name="for"]:checked').value === 'other';
    if (forOther && document.getElementById('sel-rel').value === '') {
        document.getElementById('rel-warn').style.display = 'block';
        return;
    }
    document.getElementById('rel-warn').style.display = 'none';

    if (forOther && document.getElementById('sel-dob').value === '') {
        alert("Please enter the patient's date of birth.");
        document.getElementById('sel-dob').focus();
        return;
    }

    if (phone === '') {
        document.getElementById('phone-warn').style.display = 'block';
        return;
    }
    document.getElementById('phone-warn').style.display = 'none';
    goStep(4);
}

// Toggle between booking for "myself" (name locked) and "someone else" (name editable).
function toggleFor() {
    var forOther = document.querySelector('input[name="for"]:checked').value === 'other';
    var fn = document.getElementById('sel-fname');
    var ln = document.getElementById('sel-lname');
    document.getElementById('dependent-note').style.display = forOther ? 'block' : 'none';
    document.getElementById('rel-wrap').style.display       = forOther ? 'block' : 'none';
    if (forOther) {
        fn.readOnly = false; ln.readOnly = false;
        fn.value = ''; ln.value = ''; fn.focus();
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

    // No same-day (or past) booking — appointments need at least a day's notice.
    // Blocks it even if a browser lets someone type a date past the "min" attribute.
    if (v < MIN_BOOK_DATE) {
        warn.textContent = 'Appointments must be booked at least a day in advance. Please pick ' +
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
                                : "Your dentist is not available that day";
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
        var ds = d.toISOString().slice(0,10);
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
        var nextDate = nextOpenDate(tomorrow.toISOString().slice(0,10));
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
    document.getElementById('rev-treat').textContent = document.getElementById('sel-treatment').value;
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
