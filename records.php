<?php
// ============================================================
//  CLINICAL RECORDS  (records.php)
// ============================================================
//  Per-patient records with THREE working tabs:
//    - Treatments : add / list / delete treatment records
//    - X-rays     : upload an X-ray photo, view it, delete it
//    - Notes      : add / list / delete dated clinical notes
//
//  Records pile up over time (each has its own date) and every
//  entry has a Delete button.
//
//  X-ray images are saved as files in  uploads/xrays/  (served only through
//  xray.php, which checks who is asking) and the
//  file name is stored in the `xrays` table.
// ============================================================
require_once 'config/auth.php';
require_login(['admin','dentist']);   // clinical records - not front-desk staff
require_once 'includes/teeth.php';     // for the read-only dental chart in Overview
require_once 'includes/followups.php'; // follow-up plans (braces, root canal sessions, check-ups)
require_once 'includes/clinical.php';  // allergies, medications, dental notes, alert banner
require_once 'includes/treatments.php'; // clinic_time_slots() for booking the next visit
require_once 'includes/health_form.php';   // latest health questionnaire in Overview

// Only REAL patients (exclude staff/dentist/admin accounts).
// A DENTIST only sees their own assigned patients; admin/staff see all.
$recSql = "SELECT p.id, p.name FROM patients p
           LEFT JOIN users u ON p.user_id = u.id
           WHERE (u.id IS NULL OR u.role = 'patient')";
$recPrm = [];
if (current_role() === 'dentist') { $recSql .= " AND p.primary_dentist = ?"; $recPrm[] = $_SESSION['name'] ?? ''; }
$recSql .= " ORDER BY p.name";
$recStmt = $pdo->prepare($recSql); $recStmt->execute($recPrm);
$patients = $recStmt->fetchAll();

$pid = (int)($_GET['patient'] ?? ($patients[0]['id'] ?? 0));
$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, ['overview', 'treatments', 'xrays', 'notes', 'health', 'chart'], true)) $tab = 'overview';

// Only patients in the list above may be opened. Stops a dentist from
// editing ?patient=ID in the URL to read someone else's patient.
$allowedPids = array_map('intval', array_column($patients, 'id'));
if ($pid && !in_array($pid, $allowedPids, true)) {
    deny_access("Records of patient #$pid");
}

// Name of the selected patient (saved into the treatments table).
$patientName = '';
foreach ($patients as $p) { if ($p['id'] == $pid) $patientName = $p['name']; }

// Folder where X-ray images are stored.
$XRAY_DIR = __DIR__ . '/uploads/xrays';
require_once 'includes/record_archive.php';   // deleting a record moves it to the Archive
$ARCH_NOTE = 'It is hidden from the records, and the admin can restore it from the Archive.';

// ---------- Handle add / delete actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pid    = (int)($_POST['patient_id'] ?? $pid);
    if (!in_array($pid, $allowedPids, true)) {      // same check for form posts
        deny_access("Changed records of patient #$pid");
    }

    // ----- Overview: edit the health questionnaire -----
    if ($action === 'update_health') {
        [$hfNew, $hfErr] = health_form_from_post($_POST);
        if ($hfErr !== '') {
            set_flash($hfErr, 'error');
        } else {
            save_patient_health($pdo, $pid, $hfNew);
            log_activity($pdo, 'Updated health questionnaire', patient_name_of($pdo, $pid));
            set_flash('Health questionnaire updated.');
        }
        header("Location: records?patient=$pid&tab=health"); exit;
    }

    // ----- Overview: 👤 Patient details — the ADMIN only (a dentist sees them read-only) -----
    if ($action === 'update_patient_details') {
        $back = "Location: records?patient=$pid&tab=overview";
        if (current_role() !== 'admin') { set_flash('Only the admin or the front desk can change patient details.', 'error'); header($back); exit; }
        [$cleanPhone, $phoneError] = validate_phone($_POST['phone'] ?? '', false);
        [$emPhone, $emErr]         = validate_phone($_POST['emergency_phone'] ?? '', false);
        $email = trim($_POST['email'] ?? '');
        $err = $phoneError ?: ($emErr ? 'Emergency contact: ' . $emErr : '');
        if (!$err && $email !== '') $err = email_problem($email);
        $dob = trim($_POST['dob'] ?? '');
        if (!$err) $err = birth_date_error($dob);
        if (trim($_POST['name'] ?? '') === '') $err = 'Please enter the patient\'s name.';
        if ($err) { set_flash($err, 'error'); header($back); exit; }

        $cur = $pdo->prepare("SELECT p.primary_dentist, p.user_id, u.email AS login_email FROM patients p LEFT JOIN users u ON u.id = p.user_id WHERE p.id = ?");
        $cur->execute([$pid]); $cur = $cur->fetch();
        $msg = 'Patient details saved.'; $type = 'success';
        // A login email is protected: it changes only after the new address is confirmed.
        if (!empty($cur['user_id']) && strcasecmp((string)$cur['login_email'], $email) !== 0) {
            if ($email === '') { $msg .= ' The login email cannot be empty, so it was kept.'; }
            else {
                require_once 'includes/account_transfer.php';
                [$okE, $msgE] = request_email_change($pdo, (int)$cur['user_id'], $pid, $email, $_SESSION['name'] ?? 'Admin');
                $msg .= ' ' . ($okE ? $msgE : 'The login email was NOT changed: ' . $msgE);
                if (!$okE) $type = 'warning';
            }
            $email = (string)$cur['login_email'];
        }
        $ageIn = trim($_POST['age'] ?? '');
        $newDentist = trim($_POST['primary_dentist'] ?? '');
        $pdWas = $pdo->prepare("SELECT name, date_of_birth, phone, email, primary_dentist, emergency_name, emergency_phone FROM patients WHERE id = ?");
        $pdWas->execute([$pid]); $pdWas = $pdWas->fetch() ?: [];
        $pdo->prepare("UPDATE patients SET name = ?, date_of_birth = ?, age = ?, phone = ?, email = ?, primary_dentist = ?,
                              emergency_name = ?, emergency_phone = ? WHERE id = ?")
            ->execute([trim($_POST['name']), $dob ?: null, $dob !== '' ? age_from_dob($dob) : ($ageIn !== '' ? (int)$ageIn : null),
                       $cleanPhone, $email, $newDentist ?: null,
                       trim($_POST['emergency_name'] ?? '') ?: null, $emPhone ?: null, $pid]);
        $pdChg = change_list($pdWas, ['name' => trim($_POST['name']), 'date_of_birth' => $dob, 'phone' => $cleanPhone, 'email' => $email,
                                      'primary_dentist' => $newDentist, 'emergency_name' => trim($_POST['emergency_name'] ?? ''), 'emergency_phone' => $emPhone],
                             ['name' => 'Name', 'date_of_birth' => 'Birthday', 'phone' => 'Phone', 'email' => 'Email', 'primary_dentist' => 'Dentist',
                              'emergency_name' => 'Emergency contact', 'emergency_phone' => 'Emergency phone']);
        log_activity($pdo, 'Updated patient details', patient_name_of($pdo, $pid) . ($pdChg ? ' — ' . $pdChg : ''));
        // A new dentist: the patient's upcoming appointments go to them too (same date and time).
        if ($newDentist !== '' && $newDentist !== (string)$cur['primary_dentist']) {
            log_activity($pdo, 'Assigned dentist', patient_name_of($pdo, $pid) . ' → ' . $newDentist);
            [$movedN, $stuck] = move_upcoming_to_dentist($pdo, $pid, $newDentist);
            if ($movedN) $msg .= " $movedN upcoming appointment" . ($movedN > 1 ? 's were' : ' was') . " moved to $newDentist (same date and time).";
            if ($stuck) { $msg .= " Not moved — $newDentist is off or already booked: " . implode('; ', $stuck) . '. Please change those in Appointments.'; $type = 'warning'; }
        }
        set_flash($msg, $type);
        header($back); exit;
    }

    // ----- Overview: 🩺 Clinical information — the dentist (and admin) -----
    if ($action === 'update_clinical') {
        $ciWas = $pdo->prepare("SELECT blood_type, allergies, medications, medical_conditions, medical_alert, gum_condition, oral_hygiene,
                                       dental_habits, dental_anxiety, chart_remarks FROM patients WHERE id = ?");
        $ciWas->execute([$pid]); $ciWas = $ciWas->fetch() ?: [];
        $pick = fn($v, $list) => in_array($v, $list, true) ? $v : null;
        $habits = array_values(array_intersect(DENTAL_HABITS, array_map('strval', (array)($_POST['dental_habits'] ?? []))));
        $pdo->prepare("UPDATE patients SET blood_type = ?, allergies = ?, medications = ?, medical_conditions = ?, medical_alert = ?,
                              gum_condition = ?, oral_hygiene = ?, dental_habits = ?, dental_anxiety = ?, chart_remarks = ?,
                              clinical_updated_by = ?, clinical_updated_at = NOW() WHERE id = ?")
            ->execute([trim($_POST['blood_type'] ?? '') ?: null,
                       trim($_POST['allergies'] ?? '') ?: null, trim($_POST['medications'] ?? '') ?: null,
                       trim($_POST['medical_conditions'] ?? '') ?: null, trim($_POST['medical_alert'] ?? '') ?: null,
                       $pick($_POST['gum_condition'] ?? '', GUM_CONDITIONS), $pick($_POST['oral_hygiene'] ?? '', ORAL_HYGIENE),
                       $habits ? implode(', ', $habits) : null, $pick($_POST['dental_anxiety'] ?? '', DENTAL_ANXIETY),
                       trim($_POST['chart_remarks'] ?? '') ?: null, $_SESSION['name'] ?? '', $pid]);
        $ciNow = $pdo->prepare("SELECT blood_type, allergies, medications, medical_conditions, medical_alert, gum_condition, oral_hygiene,
                                       dental_habits, dental_anxiety, chart_remarks FROM patients WHERE id = ?");
        $ciNow->execute([$pid]); $ciNow = $ciNow->fetch() ?: [];
        $ciChg = change_list($ciWas, $ciNow, ['blood_type' => 'Blood type', 'allergies' => 'Allergies', 'medications' => 'Medications',
                     'medical_conditions' => 'Conditions', 'medical_alert' => 'Alert', 'gum_condition' => 'Gums', 'oral_hygiene' => 'Hygiene',
                     'dental_habits' => 'Habits', 'dental_anxiety' => 'Anxiety', 'chart_remarks' => 'Remarks']);
        log_activity($pdo, 'Updated clinical information', patient_name_of($pdo, $pid) . ($ciChg ? ' — ' . $ciChg : ' — no changes'));
        set_flash('Clinical information saved.');
        header("Location: records?patient=$pid&tab=overview"); exit;
    }

    // ----- Treatments -----
    if ($action === 'add_treatment') {
        $pdo->prepare(
            "INSERT INTO treatments (patient_id,patient_name,treatment_name,tooth,dentist,treatment_date,status,notes,clinic_notes)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([
            $pid, $_POST['patient_name'], trim($_POST['treatment_name']), trim($_POST['tooth'] ?? ''),
            $_SESSION['name'] ?? '', $_POST['treatment_date'],
            in_array($_POST['status'] ?? '', ['Completed','In Progress','Planned'], true) ? $_POST['status'] : 'Completed',
            trim($_POST['notes'] ?? ''), trim($_POST['clinic_notes'] ?? '') ?: null
        ]);
        complete_arrived_visit($pdo, $pid, $_POST['treatment_date'] ?? null);   // arrived today -> Completed
        log_activity($pdo, 'Added treatment', patient_name_of($pdo, $pid) . ' — ' . trim($_POST['treatment_name']));
        // Follow-up: a session of an existing plan, or a new plan ("Needs follow-up" ticked).
        $fuMsg = ($_POST['status'] ?? '') === 'Planned' ? '' : followup_after_treatment($pdo, $pid, $_POST['treatment_name'], $_POST['tooth'] ?? '',
                     $_POST['treatment_date'] ?: date('Y-m-d'), $_POST, $_SESSION['name'] ?? '');
        // "📅 Also book the next session now"
        if (!empty($_POST['book_next'])) {
            $pq = $pdo->prepare("SELECT * FROM treatment_plans WHERE patient_id = ? AND status = 'Active' AND LOWER(treatment_name) = LOWER(?) LIMIT 1");
            $pq->execute([$pid, trim($_POST['treatment_name'])]);
            if ($plan = $pq->fetch()) {
                [$okB, $msgB] = book_followup_appointment($pdo, $plan, $_POST['book_date'] ?? '', $_POST['book_time'] ?? '', $_SESSION['name'] ?? 'Clinic');
                $fuMsg .= ' ' . ($okB ? $msgB : 'The next session was NOT booked: ' . $msgB);
            } else {
                $fuMsg .= ' (The next session was not booked: tick "Needs follow-up" to start a plan for this treatment first.)';
            }
        }
        set_flash('Treatment record added.' . $fuMsg);
        header("Location: records?patient=$pid&tab=treatments"); exit;
    }
    // ---- Follow-up plan: finish, stop, or change the next due date ----
    if (in_array($action, ['plan_complete', 'plan_stop', 'plan_due', 'plan_book'], true)) {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $pl = $pdo->prepare("SELECT * FROM treatment_plans WHERE id = ? AND patient_id = ?");
        $pl->execute([$planId, $pid]);
        if ($plan = $pl->fetch()) {
            if ($action === 'plan_book') {
                // 📅 Book next visit: a real (approved) appointment the patient sees in their portal.
                [$okB, $msgB] = book_followup_appointment($pdo, $plan, $_POST['book_date'] ?? '', $_POST['book_time'] ?? '', $_SESSION['name'] ?? 'Clinic');
                set_flash($msgB, $okB ? 'success' : 'error');
                header("Location: records?patient=$pid&tab=treatments#plans"); exit;
            } elseif ($action === 'plan_due') {
                $nd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['next_due'] ?? '') ? $_POST['next_due'] : null;
                $pdo->prepare("UPDATE treatment_plans SET next_due = ?, last_reminder_for = NULL WHERE id = ?")->execute([$nd, $planId]);
                set_flash('Next ' . $plan['treatment_name'] . ' visit moved to ' . ($nd ? date('M j, Y', strtotime($nd)) : '–') . '.');
            } else {
                $pdo->prepare("UPDATE treatment_plans SET status = ?, completed_at = NOW(), next_due = NULL WHERE id = ?")
                    ->execute([$action === 'plan_complete' ? 'Completed' : 'Stopped', $planId]);
                set_flash($plan['treatment_name'] . ($action === 'plan_complete' ? ' plan marked as finished.' : ' plan stopped.'), 'info');
            }
            sync_next_visit($pdo, $pid);
            log_activity($pdo, 'Updated follow-up plan', patient_name_of($pdo, $pid) . ' — ' . $plan['treatment_name'] . ' (' . $action . ')');
        }
        header("Location: records?patient=$pid&tab=treatments#plans"); exit;
    }

    if ($action === 'delete_treatment') {
        $done = 0;
        foreach (bulk_ids() as $tid) {             // one record, or several ticked ones
            $tRow = archive_record($pdo, 'treatment', $tid, ['patient_id' => $pid]);   // to the Archive, not erased
            if (!$tRow) continue;
            log_activity($pdo, 'Archived treatment record', $tRow['patient_name'] . ' — ' . $tRow['treatment_name']);
            $done++;
        }
        set_flash(($done === 1 ? 'Treatment record' : "$done treatment records") . ' moved to the Archive.', 'info');
        header("Location: records?patient=$pid&tab=treatments"); exit;
    }

    // ----- X-rays (image upload) -----
    if ($action === 'add_xray') {
        if (!empty($_FILES['xray']['name']) && $_FILES['xray']['error'] === UPLOAD_ERR_OK) {
            $ext     = strtolower(pathinfo($_FILES['xray']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];
            if (in_array($ext, $allowed)) {
                if (!is_dir($XRAY_DIR)) mkdir($XRAY_DIR, 0777, true);
                // Make a unique file name so uploads never overwrite each other.
                $fname = 'xray_' . $pid . '_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
                if (move_uploaded_file($_FILES['xray']['tmp_name'], "$XRAY_DIR/$fname")) {
                    $pdo->prepare("INSERT INTO xrays (patient_id,image_file,caption,xray_date,uploaded_by) VALUES (?,?,?,?,?)")
                        ->execute([$pid, $fname, trim($_POST['caption'] ?? ''), ($_POST['xray_date'] ?: null), $_SESSION['name'] ?? '']);
                    complete_arrived_visit($pdo, $pid);
                    log_activity($pdo, 'Uploaded X-ray', patient_name_of($pdo, $pid));
                    set_flash('X-ray uploaded.');
                } else {
                    set_flash('Could not save the image. Check the uploads/xrays folder permissions.', 'error');
                }
            } else {
                set_flash('Please upload an image file (jpg, png, gif, webp).', 'error');
            }
        } else {
            set_flash('Please choose an image to upload.', 'error');
        }
        header("Location: records?patient=$pid&tab=xrays"); exit;
    }
    if ($action === 'delete_xray') {
        $xrPatientName = 'Patient #' . $pid;
        foreach ($patients as $pRow) { if ((int)$pRow['id'] === $pid) { $xrPatientName = $pRow['name']; break; } }
        $done = 0;
        foreach (bulk_ids() as $xid) {             // one X-ray, or several ticked ones
            // The image file stays on disk until the admin deletes it for good.
            if (archive_record($pdo, 'xray', $xid, ['patient_id' => $pid])) $done++;
        }
        if ($done) log_activity($pdo, 'Archived X-ray', $xrPatientName . ($done > 1 ? " ($done images)" : ''));
        set_flash(($done === 1 ? 'X-ray' : "$done X-rays") . ' moved to the Archive.', 'info');
        header("Location: records?patient=$pid&tab=xrays"); exit;
    }

    // ----- Notes -----
    if ($action === 'add_note') {
        $pdo->prepare("INSERT INTO clinical_notes (patient_id,note,author) VALUES (?,?,?)")
            ->execute([$pid, trim($_POST['note']), $_SESSION['name'] ?? '']);
        complete_arrived_visit($pdo, $pid);
        log_activity($pdo, 'Added clinical note', patient_name_of($pdo, $pid));
        set_flash('Note added.');
        header("Location: records?patient=$pid&tab=notes"); exit;
    }
    if ($action === 'delete_note') {
        $nPatientName = 'Patient #' . $pid;
        foreach ($patients as $pRow) { if ((int)$pRow['id'] === $pid) { $nPatientName = $pRow['name']; break; } }
        $done = 0;
        foreach (bulk_ids() as $nid) {             // one note, or several ticked ones
            if (archive_record($pdo, 'note', $nid, ['patient_id' => $pid])) $done++;
        }
        if ($done) log_activity($pdo, 'Archived clinical note', $nPatientName . ($done > 1 ? " ($done notes)" : ''));
        set_flash(($done === 1 ? 'Note' : "$done notes") . ' moved to the Archive.', 'info');
        header("Location: records?patient=$pid&tab=notes"); exit;
    }
}

// ---------- Load this patient's records ----------
$treatments = $xrays = $notes = [];
$patientRow = null;
$toothMap   = [];
if ($pid) {
    sync_visit_info($pdo, $pid);                     // New / Returning + last visit come from real visits
    $pr = $pdo->prepare("SELECT * FROM patients WHERE id=?");
    $pr->execute([$pid]); $patientRow = $pr->fetch();

    // The patient has one dental chart PER VISIT. Let the record show every visit,
    // not just the latest, so both admin and dentist can see the full history.
    $recSessions = get_chart_sessions($pdo, $pid);              // newest first
    $recSid = (int)($_GET['session'] ?? 0);
    $recValid = array_map('intval', array_column($recSessions, 'id'));
    if (!$recSid || !in_array($recSid, $recValid)) $recSid = $recValid[0] ?? 0;   // default: latest
    $recSession = null;
    foreach ($recSessions as $s) if ((int)$s['id'] === $recSid) $recSession = $s;
    $toothMap = ($pid && $recSid) ? build_tooth_map($pdo, $pid, $recSid) : build_tooth_map($pdo, $pid);

    $t = $pdo->prepare("SELECT * FROM treatments WHERE patient_id=? ORDER BY treatment_date DESC, id DESC");
    $t->execute([$pid]); $treatments = $t->fetchAll();

    $x = $pdo->prepare("SELECT * FROM xrays WHERE patient_id=? ORDER BY created_at DESC");
    $x->execute([$pid]); $xrays = $x->fetchAll();

    $n = $pdo->prepare("SELECT * FROM clinical_notes WHERE patient_id=? ORDER BY created_at DESC");
    $n->execute([$pid]); $notes = $n->fetchAll();
}

$page_title = "Records";
include 'includes/head.php';
$active = 'records';
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div><h1>Clinical Records</h1><div class="sub">Treatments, X-rays, and notes for each patient</div></div>
            <div class="clock"><span class="time" id="clock"></span><br><span id="clock-date"></span></div>
        </div>

        <!-- Patient selector -->
        <div class="card-box mb-3 d-flex align-items-center gap-2" style="padding:14px;">
            <label class="field-label mb-0">Patient:</label>
            <form method="GET" class="m-0">
                <input type="hidden" name="tab" value="<?= e($tab) ?>">
                <select name="patient" class="form-select" style="min-width:280px;" onchange="this.form.submit()">
                    <?php foreach ($patients as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $p['id']==$pid?'selected':'' ?>><?= e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php if (empty($patients)): ?><span class="text-muted2">No patients yet.</span><?php endif; ?>
            <?php if ($pid && $patientRow):
                  [$nvDate, $nvFrom] = next_visit_info($pdo, $patientRow); ?>
                <!-- Worked out by the system from real visits and bookings (not edited by hand) -->
                <div class="pt-facts ms-auto">
                    <span class="badge-pill <?= ($patientRow['patient_type'] ?? '') === 'Returning' ? 'b-completed' : 'b-pending' ?>"><?= e($patientRow['patient_type'] ?: 'New') ?></span>
                    <span><span class="text-muted2">Last visit</span> <?= $patientRow['last_visit'] ? date('M j, Y', strtotime($patientRow['last_visit'])) : '–' ?></span>
                    <span><span class="text-muted2">Next visit</span> <?= $nvDate ? date('M j, Y', strtotime($nvDate)) . ' <small class="text-muted2">· ' . e($nvFrom) . '</small>' : '–' ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tabs -->
        <div class="mb-3 d-flex gap-2 flex-wrap" data-keep-text>
            <?php
            $recTabs = ['overview'=>'📋 Overview','treatments'=>'🦷 Treatments','xrays'=>'📷 X-rays','notes'=>'📝 Notes',
                        'health'=>'🩺 Health form','chart'=>'🗂 Dental chart'];
            foreach ($recTabs as $k=>$label): ?>
                <a href="records?patient=<?= $pid ?>&tab=<?= $k ?>" class="btn btn-sm <?= $tab===$k?'btn-dark-navy':'btn-light' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <?php if ($pid && $patientRow): ?>
            <?= clinical_styles() ?>
            <?= patient_alert_banner($patientRow) ?>
        <?php endif; ?>

        <?php if (!$pid): ?>
            <div class="card-box text-center text-muted2 py-4">Add a patient first, then you can record their treatments here.</div>

        <?php elseif ($tab === 'health'): ?>
            <!-- ===== HEALTH FORM: the latest health questionnaire ===== -->
            <?php [$hfAns, $hfAt] = patient_health($pdo, $pid); ?>
            <?= health_form_styles() ?>
            <div class="card-box mb-3">
                <div class="flex-between mb-2">
                    <h6 class="mb-0">🩺 Health Questionnaire
                        <small class="text-muted2"><?= $hfAt ? '(latest, updated ' . date('M j, Y', strtotime($hfAt)) . ')' : '' ?></small></h6>
                    <button type="button" class="btn btn-sm btn-outline-teal" id="hf-edit-btn"
                            onclick="document.getElementById('hf-view').hidden = true; document.getElementById('hf-edit').hidden = false; this.hidden = true; hfToggle();">✏️ Edit</button>
                </div>
                <div id="hf-view"><?= health_form_view($hfAns) ?></div>
                <form method="POST" id="hf-edit" hidden>
                    <input type="hidden" name="action" value="update_health">
                    <input type="hidden" name="patient_id" value="<?= (int)$pid ?>">
                    <?= health_form_fields($hfAns, true) ?>
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-light btn-sm"
                                onclick="document.getElementById('hf-edit').hidden = true; document.getElementById('hf-view').hidden = false; document.getElementById('hf-edit-btn').hidden = false;">Cancel</button>
                        <button class="btn btn-teal btn-sm">💾 Save questionnaire</button>
                    </div>
                </form>
            </div>

        <?php elseif ($tab === 'chart'): ?>
            <!-- ===== DENTAL CHART: view only (edited on the Odontogram page) ===== -->
            <div class="card-box">
                <h6 class="mb-1">🦷 Dental Chart (Odontogram) <small class="text-muted2">— view only (edit on the Odontogram page)</small></h6>

                <?php if (count($recSessions) > 1): ?>
                    <div class="text-muted2 mb-2" style="font-size:.82rem;">
                        This patient has <strong><?= count($recSessions) ?> visits</strong> on record. Click a visit to view its chart.
                    </div>
                <?php endif; ?>

                <!-- Visit tabs (same history the Odontogram page shows) -->
                <?php if ($recSessions): ?>
                <div class="sess-tabs mb-2">
                    <?php $recOldFirst = array_reverse($recSessions); ?>
                    <?php foreach ($recOldFirst as $i => $s): ?>
                        <a class="sess-tab <?= (int)$s['id']===$recSid?'on':'' ?>"
                           href="records?patient=<?= $pid ?>&tab=chart&session=<?= $s['id'] ?>">
                            <b>Visit <?= $i+1 ?><?= $i === count($recOldFirst)-1 ? ' · latest' : '' ?></b>
                            <small><?= date('M j, Y', strtotime($s['visit_date'])) ?><?= $s['title'] ? ' · '.e($s['title']) : '' ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="text-center" style="font-size:.68rem;color:#777;">Upper</div>
                <div class="odo-arch" style="margin-bottom:4px;">
                    <?php foreach ($UPPER_TEETH as $t) echo render_tooth($t, $toothMap[$t] ?? 'Healthy'); ?>
                </div>
                <div class="odo-arch">
                    <?php foreach ($LOWER_TEETH as $t) echo render_tooth($t, $toothMap[$t] ?? 'Healthy'); ?>
                </div>
                <div class="text-center" style="font-size:.68rem;color:#777;">Lower</div>

                <?php $toothLegend = ['Healthy'=>'#6bbf6b','Decayed'=>'#e0a92e','Filled'=>'#3b9ae0','Missing'=>'#e05b5b','Crowned'=>'#a06fd6','Extracted'=>'#999999','Impacted'=>'#d99a00','Fractured'=>'#cc4444']; ?>
                <div style="display:flex;flex-wrap:wrap;gap:6px 14px;font-size:.72rem;margin-top:8px;border-top:1px dashed #ddd;padding-top:6px;">
                    <?php foreach ($toothLegend as $cond => $color): ?>
                        <span style="white-space:nowrap;"><span style="display:inline-block;width:11px;height:11px;border-radius:2px;background:<?= $color ?>;border:1px solid rgba(0,0,0,.15);vertical-align:middle;"></span> <?= $cond ?></span>
                    <?php endforeach; ?>
                </div>
                <?php
                    $conds = [];
                    foreach (array_merge($UPPER_TEETH, $LOWER_TEETH) as $t) {
                        $s = $toothMap[$t] ?? 'Healthy';
                        if ($s !== 'Healthy') $conds[] = "Tooth $t: $s";
                    }
                ?>
                <div style="font-size:.82rem;margin-top:8px;"><strong>Conditions:</strong> <?= $conds ? e(implode(' · ', $conds)) : 'All teeth healthy' ?></div>

                <?php if ($recSession && !empty($recSession['notes'])): ?>
                    <div style="border-top:1px dashed #ddd;margin-top:8px;padding-top:8px;font-size:.82rem;">
                        <strong>Visit notes:</strong> <?= nl2br(e($recSession['notes'])) ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($tab === 'overview'): ?>
            <?php
                $isAdminR   = current_role() === 'admin';
                $qConds     = questionnaire_conditions($pdo, $pid);
                $myHabits   = array_filter(array_map('trim', explode(',', (string)($patientRow['dental_habits'] ?? ''))));
                $rDentists  = $pdo->query("SELECT name FROM users WHERE role='dentist' AND status='active' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
                $ageNow     = $patientRow['date_of_birth'] ? age_from_dob($patientRow['date_of_birth']) : $patientRow['age'];
                $dobTxt     = trim(($patientRow['date_of_birth'] ? date('M j, Y', strtotime($patientRow['date_of_birth'])) : '')
                                   . ($ageNow !== null && $ageNow !== '' ? ($patientRow['date_of_birth'] ? ' (' . $ageNow . ' yrs)' : $ageNow . ' yrs') : ''));
                $emTxt      = trim(($patientRow['emergency_name'] ?? '') . (!empty($patientRow['emergency_phone']) ? ' · ' . $patientRow['emergency_phone'] : ''));
                // One read-only item: a small label over its value ("–" when empty).
                $item = fn($label, $value, $cls = 'col-md-4') => '<div class="' . $cls . '"><div class="field-label">' . $label . '</div>'
                        . '<div class="ov-val">' . ($value !== '' && $value !== null ? $value : '–') . '</div></div>';
                $none = '<span class="text-muted2">Not checked yet</span>';
            ?>
            <!-- ===== PATIENT DETAILS: shown as text; the admin presses Edit to change them ===== -->
            <div class="card-box mb-3 ov-card" id="ov-details">
                <div class="flex-between mb-3">
                    <h6 class="mb-0">👤 Patient details</h6>
                    <?php if ($isAdminR): ?>
                        <button type="button" class="btn btn-sm btn-outline-teal ov-edit-btn" data-keep-text onclick="ovEdit('ov-details', true)">✏️ Edit</button>
                    <?php else: ?>
                        <small class="text-muted2">Only the admin can change these</small>
                    <?php endif; ?>
                </div>
                <div class="row g-3 ov-view">
                    <?= $item('Full name', e($patientRow['name'])) ?>
                    <?= $item('Date of birth / Age', e($dobTxt)) ?>
                    <?= $item('Primary dentist', e($patientRow['primary_dentist'] ?? '')) ?>
                    <?= $item('Phone', e($patientRow['phone'] ?? '')) ?>
                    <?= $item('Email' . (!empty($patientRow['user_id']) ? ' <span class="text-muted2">(login)</span>' : ''), e($patientRow['email'] ?? '')) ?>
                    <?= $item('📞 Emergency contact', e($emTxt)) ?>
                </div>
                <?php if ($isAdminR): ?>
                <form method="POST" class="ov-form" hidden>
                    <input type="hidden" name="action" value="update_patient_details">
                    <input type="hidden" name="patient_id" value="<?= $pid ?>">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="field-label">Full name</label>
                            <input name="name" class="form-control" value="<?= e($patientRow['name']) ?>" required></div>
                        <div class="col-md-4"><label class="field-label">Date of birth</label>
                            <input type="date" name="dob" id="ov-dob" class="form-control" value="<?= e($patientRow['date_of_birth']) ?>" min="1900-01-01" max="<?= birth_date_max() ?>">
                            <!-- The age follows the birthday; it is typed only when the birthday is not known. -->
                            <div id="ov-age-wrap" class="mt-2" <?= $patientRow['date_of_birth'] ? 'hidden' : '' ?>>
                                <input type="number" name="age" class="form-control" placeholder="Age, if the birthday is not known" min="0" max="120" step="1" data-digits value="<?= e($patientRow['age']) ?>">
                            </div></div>
                        <div class="col-md-4"><label class="field-label">Primary dentist</label>
                            <select name="primary_dentist" class="form-select">
                                <option value="">— Unassigned —</option>
                                <?php foreach ($rDentists as $dn): ?><option <?= $patientRow['primary_dentist'] === $dn ? 'selected' : '' ?>><?= e($dn) ?></option><?php endforeach; ?>
                                <?php if ($patientRow['primary_dentist'] && !in_array($patientRow['primary_dentist'], $rDentists, true)): ?><option selected><?= e($patientRow['primary_dentist']) ?></option><?php endif; ?>
                            </select></div>

                        <div class="col-md-4"><label class="field-label">Phone</label>
                            <input name="phone" class="form-control" value="<?= e($patientRow['phone']) ?>" placeholder="09XX XXX XXXX" <?= phone_input_attrs() ?>></div>
                        <div class="col-md-4"><label class="field-label">Email <?= !empty($patientRow['user_id']) ? '<span class="text-muted2">(login)</span>' : '' ?></label>
                            <input type="email" name="email" class="form-control" value="<?= e($patientRow['email']) ?>">
                            <?php if (!empty($patientRow['user_id'])): ?><div class="text-muted2" style="font-size:.74rem;">A new login email must be confirmed from that address first.</div><?php endif; ?></div>
                        <div class="col-md-4"><label class="field-label">📞 Emergency contact</label>
                            <div class="d-flex gap-2">
                                <input name="emergency_name" class="form-control" placeholder="Name" value="<?= e($patientRow['emergency_name'] ?? '') ?>">
                                <input name="emergency_phone" class="form-control" style="max-width:150px;" placeholder="Phone" value="<?= e($patientRow['emergency_phone'] ?? '') ?>" <?= phone_input_attrs() ?>>
                            </div></div>
                    </div>
                    <div class="d-flex gap-2 justify-content-end mt-3">
                        <button type="button" class="btn btn-light btn-sm" data-keep-text onclick="ovEdit('ov-details', false)">Cancel</button>
                        <button class="btn btn-teal btn-sm" data-keep-text>💾 Save details</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>

            <!-- ===== CLINICAL INFORMATION: shown as text; Edit opens the form ===== -->
            <div class="card-box mb-3 ov-card" id="ov-clinical">
                <div class="flex-between mb-3">
                    <h6 class="mb-0">🩺 Clinical information</h6>
                    <button type="button" class="btn btn-sm btn-outline-teal ov-edit-btn" data-keep-text onclick="ovEdit('ov-clinical', true)">✏️ Edit</button>
                </div>
                <div class="row g-3 ov-view">
                    <?= $item('Blood type', e($patientRow['blood_type'] ?? '') ?: '<span class="text-muted2">Unknown</span>', 'col-md-3') ?>
                    <?= $item('<span style="color:#c0392b;">⚠️ Allergies</span>', !empty($patientRow['allergies']) ? '<span style="color:#c0392b;font-weight:600;">' . e($patientRow['allergies']) . '</span>' : '<span class="text-muted2">None recorded</span>', 'col-md-9') ?>
                    <?= $item('💊 Current medications', nl2br(e($patientRow['medications'] ?? '')), 'col-md-6') ?>
                    <?php
                        $condHtml = '';
                        foreach ($qConds as $qc) $condHtml .= '<span class="badge-pill b-cancelled" style="font-size:.72rem;margin:0 3px 3px 0;">' . e($qc) . '</span>';
                        if (!empty($patientRow['medical_conditions'])) $condHtml .= ($condHtml ? '<br>' : '') . nl2br(e($patientRow['medical_conditions']));
                    ?>
                    <?= $item('🩺 Medical conditions', $condHtml, 'col-md-6') ?>
                    <?php if (!empty($patientRow['medical_alert'])): ?>
                        <?= $item('Medical alert', '<span style="color:#c0392b;font-weight:600;">' . e($patientRow['medical_alert']) . '</span>', 'col-12') ?>
                    <?php endif; ?>
                    <?= $item('🦷 Gum condition', e($patientRow['gum_condition'] ?? '') ?: $none) ?>
                    <?= $item('🪥 Oral hygiene', e($patientRow['oral_hygiene'] ?? '') ?: $none) ?>
                    <?= $item('😟 Dental anxiety', e($patientRow['dental_anxiety'] ?? '') ?: '<span class="text-muted2">Not asked yet</span>') ?>
                    <?= $item('Habits', $myHabits ? e(implode(', ', $myHabits)) : '', 'col-12') ?>
                    <?= $item('🔒 Dentist\'s remarks <span class="text-muted2">(the patient does not see this)</span>', nl2br(e($patientRow['chart_remarks'] ?? '')), 'col-12') ?>
                </div>
                <form method="POST" class="ov-form" hidden>
                    <input type="hidden" name="action" value="update_clinical">
                    <input type="hidden" name="patient_id" value="<?= $pid ?>">
                    <div class="row g-3">
                        <div class="col-md-3"><label class="field-label">Blood type</label>
                            <select name="blood_type" class="form-select">
                                <option value="">Unknown</option>
                                <?php foreach (['O+','O-','A+','A-','B+','B-','AB+','AB-'] as $bt): ?>
                                    <option <?= $patientRow['blood_type']===$bt?'selected':'' ?>><?= $bt ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div class="col-md-9"><label class="field-label" style="color:#c0392b;">⚠️ Allergies <span class="text-muted2">(shown in red on the chart and schedule)</span></label>
                            <input name="allergies" class="form-control" value="<?= e($patientRow['allergies'] ?? '') ?>" placeholder="e.g. Penicillin, latex, lidocaine — leave empty if none"></div>

                        <div class="col-md-6"><label class="field-label">💊 Current medications</label>
                            <textarea name="medications" class="form-control" rows="2" placeholder="e.g. Aspirin (blood thinner), Metformin"><?= e($patientRow['medications'] ?? '') ?></textarea></div>
                        <div class="col-md-6"><label class="field-label">🩺 Medical conditions</label>
                            <?php if ($qConds): ?>
                                <div class="mb-1" style="font-size:.84rem;"><span class="text-muted2">From the health questionnaire:</span>
                                    <?php foreach ($qConds as $qc): ?><span class="badge-pill b-cancelled" style="font-size:.72rem;margin:0 3px 3px 0;"><?= e($qc) ?></span><?php endforeach; ?></div>
                            <?php endif; ?>
                            <textarea name="medical_conditions" class="form-control" rows="2" placeholder="Anything else, e.g. high blood pressure (controlled)"><?= e($patientRow['medical_conditions'] ?? '') ?></textarea></div>

                        <div class="col-12"><label class="field-label">Medical alert <span class="text-muted2">(shown in red with the allergies)</span></label>
                            <input name="medical_alert" class="form-control" value="<?= e($patientRow['medical_alert']) ?>" placeholder="e.g. Needs antibiotics before treatment"></div>

                        <div class="col-md-4"><label class="field-label">🦷 Gum condition</label>
                            <select name="gum_condition" class="form-select"><option value="">Not checked yet</option>
                                <?php foreach (GUM_CONDITIONS as $g): ?><option <?= ($patientRow['gum_condition'] ?? '') === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-md-4"><label class="field-label">🪥 Oral hygiene</label>
                            <select name="oral_hygiene" class="form-select"><option value="">Not checked yet</option>
                                <?php foreach (ORAL_HYGIENE as $g): ?><option <?= ($patientRow['oral_hygiene'] ?? '') === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-md-4"><label class="field-label">😟 Dental anxiety</label>
                            <select name="dental_anxiety" class="form-select"><option value="">Not asked yet</option>
                                <?php foreach (DENTAL_ANXIETY as $g): ?><option <?= ($patientRow['dental_anxiety'] ?? '') === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-12"><label class="field-label">Habits</label><div>
                            <?php foreach (DENTAL_HABITS as $hb): ?>
                                <label class="chip-check"><input type="checkbox" name="dental_habits[]" value="<?= e($hb) ?>" class="form-check-input m-0" <?= in_array($hb, $myHabits, true) ? 'checked' : '' ?>> <?= e($hb) ?></label>
                            <?php endforeach; ?></div></div>

                        <div class="col-12"><label class="field-label">🔒 Dentist's remarks <span class="text-muted2">(clinic only — the patient does not see this)</span></label>
                            <textarea name="chart_remarks" class="form-control" rows="3" placeholder="General remarks about this patient's dental chart..."><?= e($patientRow['chart_remarks']) ?></textarea></div>
                    </div>
                    <div class="d-flex gap-2 justify-content-end mt-3">
                        <button type="button" class="btn btn-light btn-sm" data-keep-text onclick="ovEdit('ov-clinical', false)">Cancel</button>
                        <button class="btn btn-teal btn-sm" data-keep-text>💾 Save clinical info</button>
                    </div>
                </form>
                <?php if (!empty($patientRow['clinical_updated_at'])): ?>
                    <div class="text-muted2 mt-2" style="font-size:.78rem;">Last updated by <?= e($patientRow['clinical_updated_by'] ?: '–') ?> on <?= date('M j, Y g:i A', strtotime($patientRow['clinical_updated_at'])) ?></div>
                <?php endif; ?>
            </div>

        <?php elseif ($tab === 'treatments'): ?>
            <!-- ===== TREATMENTS ===== -->
            <?php // The patient is at the clinic today -> add the procedure to that visit (keeps the consent record).
                  $hereQ = $pdo->prepare("SELECT id, treatment, appointment_time FROM appointments WHERE patient_id = ? AND appointment_date = CURDATE()
                                           AND arrived_at IS NOT NULL AND status IN ('Arrived','Completed') ORDER BY id DESC LIMIT 1");
                  $hereQ->execute([$pid]); $here = $hereQ->fetch(); ?>
            <?php if ($here): ?>
                <div class="card-box mb-3 flex-between flex-wrap gap-2" style="border-left:5px solid var(--teal-light);">
                    <div>🟢 <b>The patient is at the clinic today</b> for <?= e($here['treatment']) ?> (<?= e($here['appointment_time']) ?>).
                        <div class="text-muted2" style="font-size:.84rem;">Doing something extra? Add it to this visit, with the patient's agreement.</div></div>
                    <a href="appointments?filter=All&addproc=<?= (int)$here['id'] ?>" class="btn btn-teal btn-sm" data-keep-text>➕ Add procedure to today's visit</a>
                </div>
            <?php endif; ?>
            <div class="card-box mb-3">
                <h6 class="mb-3">➕ Add Treatment Record</h6>
                <form method="POST">
                    <input type="hidden" name="action" value="add_treatment">
                    <input type="hidden" name="patient_id" value="<?= $pid ?>">
                    <input type="hidden" name="patient_name" value="<?= e($patientName) ?>">
                    <div class="row g-2">
                        <div class="col-md-4"><label class="field-label">Treatment</label>
                            <input name="treatment_name" class="form-control" placeholder="e.g. Cleaning, Filling" required></div>
                        <div class="col-md-2"><label class="field-label">Tooth #</label>
                            <input name="tooth" class="form-control" placeholder="e.g. 16"></div>
                        <div class="col-md-3"><label class="field-label">Date</label>
                            <input type="date" name="treatment_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                        <div class="col-md-3"><label class="field-label">Status</label>
                            <select name="status" class="form-select">
                                <option>Completed</option><option>In Progress</option>
                                <option value="Planned">Planned (to do at a next visit)</option>
                            </select></div>
                    </div>
                    <label class="field-label mt-2">💬 Note for the patient <span class="text-muted2">(they see this in their portal)</span></label>
                    <textarea name="notes" class="form-control mb-1" rows="2"
                              placeholder="e.g. Avoid chewing on the right side for 24 hours. Come back in 2 weeks to check the filling."></textarea>
                    <label class="field-label mt-2">🔒 Clinic-only note <span class="text-muted2">(the patient does not see this)</span></label>
                    <input name="clinic_notes" class="form-control mb-3" placeholder="Optional, for the clinic team only">

                    <!-- Follow-up: braces adjustments, root canal sessions, check-ups -->
                    <div class="fu-box mb-3">
                        <label class="d-flex align-items-center gap-2 m-0" style="cursor:pointer;font-weight:600;">
                            <input type="checkbox" name="followup" value="1" class="form-check-input m-0"
                                   onchange="document.getElementById('fu-more').style.display = this.checked ? '' : 'none'">
                            🔁 Needs follow-up — the patient must come back
                        </label>
                        <div class="text-muted2" style="font-size:.8rem;margin:2px 0 0 26px;">
                            Recording the same treatment again later counts as the next session automatically.</div>
                        <div id="fu-more" class="row g-2 mt-1" style="display:none;">
                            <div class="col-md-5"><label class="field-label">Come back</label>
                                <select name="followup_every" class="form-select">
                                    <?php foreach (FOLLOWUP_INTERVALS as $fd => $fl): ?>
                                        <option value="<?= $fd ?>" <?= $fd === 28 ? 'selected' : '' ?>><?= e($fl) ?></option>
                                    <?php endforeach; ?>
                                </select></div>
                            <div class="col-md-4"><label class="field-label">…or on this date</label>
                                <input type="date" name="followup_date" class="form-control" min="<?= date('Y-m-d') ?>"></div>
                            <div class="col-md-3"><label class="field-label">Total sessions</label>
                                <input type="number" name="followup_sessions" class="form-control" min="2" max="99" placeholder="e.g. 24"></div>
                        </div>
                        <!-- Book the next session right away, while the patient is still at the clinic -->
                        <label class="d-flex align-items-center gap-2 mt-2 m-0" style="cursor:pointer;">
                            <input type="checkbox" name="book_next" value="1" class="form-check-input m-0"
                                   onchange="document.getElementById('fu-book').style.display = this.checked ? '' : 'none'">
                            📅 Also book the next session now <span class="text-muted2" style="font-size:.8rem;">(for a follow-up plan — the patient sees it in their portal)</span>
                        </label>
                        <div id="fu-book" class="row g-2 mt-1" style="display:none;">
                            <div class="col-md-5"><label class="field-label">Date</label>
                                <input type="date" name="book_date" class="form-control" min="<?= date('Y-m-d', strtotime('+1 day')) ?>"></div>
                            <div class="col-md-4"><label class="field-label">Time</label>
                                <select name="book_time" class="form-select"><?php foreach (clinic_time_slots($pdo) as $sl): ?><option><?= e($sl) ?></option><?php endforeach; ?></select></div>
                        </div>
                    </div>
                    <button class="btn btn-teal">Add Record</button>
                </form>
            </div>

            <?php $plans = all_plans($pdo, $pid); $nextBooked = next_booked_appointment($pdo, $pid); ?>
            <?php if ($plans): ?>
            <div class="card-box mb-3" id="plans">
                <h6 class="mb-2">🔁 Follow-up plans</h6>
                <?php foreach ($plans as $pl):
                    $overdue = $pl['status'] === 'Active' && $pl['next_due'] && $pl['next_due'] < date('Y-m-d'); ?>
                    <div class="flex-between flex-wrap gap-2 py-2 border-bottom">
                        <div>
                            <strong><?= e($pl['treatment_name']) ?></strong><?= $pl['tooth'] ? ' · Tooth #' . e($pl['tooth']) : '' ?>
                            <span class="badge-pill <?= $pl['status']==='Active' ? ($overdue ? 'b-cancelled' : 'b-pending') : 'b-completed' ?>"><?= e($overdue ? 'Overdue' : $pl['status']) ?></span><br>
                            <small class="text-muted2"><?= e(followup_session_text($pl)) ?>
                                <?= $pl['interval_days'] ? ' · every ' . (int)$pl['interval_days'] . ' days' : '' ?>
                                <?php if ($pl['status'] === 'Active'): ?>
                                    · next due <b><?= $pl['next_due'] ? date('M j, Y', strtotime($pl['next_due'])) : '–' ?></b>
                                    · <?= $nextBooked ? 'booked ' . date('M j', strtotime($nextBooked['appointment_date'])) . ' ' . e($nextBooked['appointment_time']) : '<span style="color:#c0392b;">not booked</span>' ?>
                                <?php endif; ?></small>
                        </div>
                        <?php if ($pl['status'] === 'Active'): ?>
                        <div class="d-flex gap-1 flex-wrap align-items-center" data-keep-text>
                            <?php $bkDefault = max((string)$pl['next_due'], date('Y-m-d', strtotime('+1 day'))); ?>
                            <!-- 📅 Book next visit: creates the appointment (Approved); the patient sees it in their portal -->
                            <form method="POST" class="d-flex gap-1 m-0 flex-wrap" title="Books a real appointment the patient sees in their portal">
                                <input type="hidden" name="action" value="plan_book"><input type="hidden" name="patient_id" value="<?= $pid ?>">
                                <input type="hidden" name="plan_id" value="<?= (int)$pl['id'] ?>">
                                <input type="date" name="book_date" class="form-control form-control-sm" style="width:150px;" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" value="<?= e($bkDefault) ?>" required>
                                <select name="book_time" class="form-select form-select-sm" style="width:118px;" required>
                                    <?php foreach (clinic_time_slots($pdo) as $sl): ?><option><?= e($sl) ?></option><?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-teal">📅 Book next visit</button>
                            </form>
                            <details class="fu-only-date">
                                <summary class="text-muted2" style="font-size:.78rem;cursor:pointer;">Only change the due date</summary>
                                <form method="POST" class="d-flex gap-1 m-0 mt-1">
                                    <input type="hidden" name="action" value="plan_due"><input type="hidden" name="patient_id" value="<?= $pid ?>">
                                    <input type="hidden" name="plan_id" value="<?= (int)$pl['id'] ?>">
                                    <input type="date" name="next_due" class="form-control form-control-sm" style="width:150px;" value="<?= e($pl['next_due']) ?>">
                                    <button class="btn btn-sm btn-light" title="No appointment is made — the patient is reminded to book">Save date</button>
                                </form>
                            </details>
                            <form method="POST" class="m-0"><input type="hidden" name="action" value="plan_complete"><input type="hidden" name="patient_id" value="<?= $pid ?>">
                                <input type="hidden" name="plan_id" value="<?= (int)$pl['id'] ?>"><button class="btn btn-sm btn-light" style="color:#1f8a54;">✔ Finished</button></form>
                            <form method="POST" class="m-0" onsubmit="return confirm('Stop this follow-up plan? The patient will no longer be reminded.')">
                                <input type="hidden" name="action" value="plan_stop"><input type="hidden" name="patient_id" value="<?= $pid ?>">
                                <input type="hidden" name="plan_id" value="<?= (int)$pl['id'] ?>"><button class="btn btn-sm btn-light" style="color:#c0392b;">Stop</button></form>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="card-box">
                <h6 class="mb-2">History <span class="text-muted2" style="font-size:.85rem;">(<?= count($treatments) ?> record<?= count($treatments)==1?'':'s' ?>)</span></h6>
                <?= bulk_bar('bulk-treat', 'delete_treatment', 'treatment records', ['patient_id' => $pid], '🗑 Move selected to Archive', $ARCH_NOTE, 'Archive') ?>
                <?php foreach ($treatments as $t): ?>
                    <div class="flex-between py-3 border-bottom">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:44px;height:44px;border-radius:10px;background:#e6f7f5;display:flex;align-items:center;justify-content:center;">🦷</div>
                            <div>
                                <strong><?= e($t['treatment_name']) ?><?= $t['tooth'] ? ' (Tooth #'.e($t['tooth']).')' : '' ?></strong><br>
                                <small class="text-muted2"><?= e($t['treatment_date']) ?> • <?= e($t['dentist']) ?></small>
                                <?php if ($t['notes']): ?><br><small>💬 <span class="text-muted2">To patient:</span> <?= e($t['notes']) ?></small><?php endif; ?>
                                <?php if (!empty($t['clinic_notes'])): ?><br><small>🔒 <span class="text-muted2">Clinic only:</span> <?= e($t['clinic_notes']) ?></small><?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge-pill b-<?= $t['status']==='Completed'?'completed':($t['status']==='Planned'?'pending':'progress') ?>"><?= e($t['status']) ?></span>
                            <?= bulk_pick('bulk-treat', $t['id']) ?>
                            <form method="POST" class="m-0" onsubmit="return confirm('Move this treatment record to the Archive?\n\n<?= $ARCH_NOTE ?>')">
                                <input type="hidden" name="action" value="delete_treatment">
                                <input type="hidden" name="patient_id" value="<?= $pid ?>">
                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                <button class="btn btn-sm" style="background:#fbdcdc;color:#c0392b;">🗑</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$treatments): ?><p class="text-muted2 text-center py-4">No treatment records yet for this patient.</p><?php endif; ?>
            </div>

        <?php elseif ($tab === 'xrays'): ?>
            <!-- ===== X-RAYS ===== -->
            <div class="card-box mb-3">
                <h6 class="mb-3">📷 Upload X-ray</h6>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_xray">
                    <input type="hidden" name="patient_id" value="<?= $pid ?>">
                    <div class="row g-2">
                        <div class="col-md-5"><label class="field-label">X-ray image</label>
                            <!-- accept="image/*" + capture lets phones open the camera -->
                            <input type="file" name="xray" class="form-control" accept="image/*" capture="environment" required></div>
                        <div class="col-md-4"><label class="field-label">Caption</label>
                            <input name="caption" class="form-control" placeholder="e.g. Upper molar, panoramic"></div>
                        <div class="col-md-3"><label class="field-label">Date taken</label>
                            <input type="date" name="xray_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                    </div>
                    <button class="btn btn-teal mt-3">Upload X-ray</button>
                    <div class="text-muted2 mt-2" style="font-size:.8rem;">On a phone this can open the camera. On a computer, choose an image file.</div>
                </form>
            </div>

            <div class="card-box">
                <h6 class="mb-3">X-ray Images <span class="text-muted2" style="font-size:.85rem;">(<?= count($xrays) ?>)</span></h6>
                <?= bulk_bar('bulk-xray', 'delete_xray', 'X-rays', ['patient_id' => $pid], '🗑 Move selected to Archive', $ARCH_NOTE, 'Archive') ?>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ($xrays as $xr): ?>
                        <div style="width:180px;border:1px solid #e3e9ee;border-radius:10px;overflow:hidden;">
                            <a href="xray?id=<?= (int)$xr['id'] ?>" target="_blank">
                                <img src="xray?id=<?= (int)$xr['id'] ?>" alt="X-ray" style="width:100%;height:130px;object-fit:cover;background:#000;">
                            </a>
                            <div style="padding:8px;">
                                <div class="d-flex align-items-center gap-2" style="font-size:.82rem;font-weight:600;"><?= bulk_pick('bulk-xray', $xr['id']) ?><span><?= $xr['caption'] ? e($xr['caption']) : 'X-ray' ?></span></div>
                                <div class="text-muted2" style="font-size:.72rem;"><?= e($xr['xray_date'] ?: date('M j, Y', strtotime($xr['created_at']))) ?></div>
                                <form method="POST" onsubmit="return confirm('Move this X-ray to the Archive?\n\n<?= $ARCH_NOTE ?>')" class="mt-1">
                                    <input type="hidden" name="action" value="delete_xray">
                                    <input type="hidden" name="patient_id" value="<?= $pid ?>">
                                    <input type="hidden" name="id" value="<?= $xr['id'] ?>">
                                    <button class="btn btn-sm w-100" style="background:#fbdcdc;color:#c0392b;">🗑 Move to Archive</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!$xrays): ?><p class="text-muted2 text-center py-4">No X-rays uploaded yet for this patient.</p><?php endif; ?>
            </div>

        <?php else: /* notes */ ?>
            <!-- ===== NOTES ===== -->
            <div class="card-box mb-3">
                <h6 class="mb-3">📝 Add Note</h6>
                <form method="POST">
                    <input type="hidden" name="action" value="add_note">
                    <input type="hidden" name="patient_id" value="<?= $pid ?>">
                    <textarea name="note" class="form-control mb-3" rows="3" placeholder="Write a clinical note for this patient..." required></textarea>
                    <button class="btn btn-teal">Add Note</button>
                </form>
            </div>

            <div class="card-box">
                <h6 class="mb-3">Notes History <span class="text-muted2" style="font-size:.85rem;">(<?= count($notes) ?>)</span></h6>
                <?= bulk_bar('bulk-notes', 'delete_note', 'notes', ['patient_id' => $pid], '🗑 Move selected to Archive', $ARCH_NOTE, 'Archive') ?>
                <?php foreach ($notes as $nt): ?>
                    <div class="flex-between py-3 border-bottom">
                        <div style="flex:1;">
                            <div style="white-space:pre-wrap;font-size:.92rem;"><?= e($nt['note']) ?></div>
                            <small class="text-muted2"><?= date('M j, Y g:i A', strtotime($nt['created_at'])) ?> • <?= e($nt['author']) ?></small>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                        <?= bulk_pick('bulk-notes', $nt['id']) ?>
                        <form method="POST" class="m-0" onsubmit="return confirm('Move this note to the Archive?\n\n<?= $ARCH_NOTE ?>')">
                            <input type="hidden" name="action" value="delete_note">
                            <input type="hidden" name="patient_id" value="<?= $pid ?>">
                            <input type="hidden" name="id" value="<?= $nt['id'] ?>">
                            <button class="btn btn-sm" style="background:#fbdcdc;color:#c0392b;">🗑</button>
                        </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$notes): ?><p class="text-muted2 text-center py-4">No notes yet for this patient.</p><?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
<script>startClock();</script>
<style>
.pt-facts { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 16px; font-size: .88rem; }
.ov-val { color: #1f2d2b; font-size: .95rem; line-height: 1.45; word-break: break-word; }
.ov-card.editing .ov-view, .ov-card.editing .ov-edit-btn { display: none; }
</style>
<script>
// Records > Overview: each card shows text; Edit swaps in its form, Cancel puts the text back.
function ovEdit(id, on) {
    var card = document.getElementById(id);
    card.classList.toggle('editing', on);
    card.querySelector('.ov-form').hidden = !on;
    if (on) { var f = card.querySelector('.ov-form input:not([type=hidden]), .ov-form select'); if (f) f.focus(); }
    else card.querySelector('.ov-form').reset();
}
// The age is typed only when the birthday is not known.
(function () {
    var dob = document.getElementById('ov-dob'), wrap = document.getElementById('ov-age-wrap');
    if (dob && wrap) dob.addEventListener('input', function () { wrap.hidden = !!dob.value; });
})();
</script>
<style>.fu-box { background: #f7fafa; border: 1px dashed #cfe0dd; border-radius: 10px; padding: 10px 12px; }</style>
</body>
</html>
