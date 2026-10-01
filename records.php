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
        header("Location: records?patient=$pid&tab=overview"); exit;
    }

    // ----- Overview: save edited patient info -----
    if ($action === 'update_patient_info') {
        [$cleanPhone, $phoneError] = validate_phone($_POST['phone'] ?? '', false);
        if ($phoneError === '' && trim($_POST['email'] ?? '') !== '') $phoneError = email_problem($_POST['email']);   // a real address only
        if ($phoneError !== '') {
            set_flash($phoneError, 'error');
            header("Location: records?patient=$pid&tab=overview"); exit;
        }
        // Only admin and staff may change the patient's dentist; for anyone
        // else the dentist on file is kept, whatever the form sends.
        if (!in_array(current_role(), ['admin','staff'])) {
            $keep = $pdo->prepare("SELECT primary_dentist FROM patients WHERE id=?");
            $keep->execute([$pid]);
            $_POST['primary_dentist'] = (string)$keep->fetchColumn();
        }
        // Birthday / age: only admin and staff may change them.
        if (in_array(current_role(), ['admin','staff'], true)) {
            $dob = trim($_POST['dob'] ?? '');
            if (($de = birth_date_error($dob)) !== '') { set_flash($de, 'error'); header("Location: records?patient=$pid&tab=overview"); exit; }
            $ageIn = trim($_POST['age'] ?? '');
            $pdo->prepare("UPDATE patients SET date_of_birth=?, age=? WHERE id=?")
                ->execute([$dob ?: null, $dob !== '' ? age_from_dob($dob) : ($ageIn !== '' ? (int)$ageIn : null), $pid]);
        }
        $pdo->prepare(
            "UPDATE patients SET name=?, blood_type=?, phone=?, email=?, patient_type=?,
             primary_dentist=?, last_visit=?, next_visit=?, medical_alert=?, chart_remarks=?
             WHERE id=?"
        )->execute([
            trim($_POST['name']),
            trim($_POST['blood_type']),
            $cleanPhone,
            trim($_POST['email']),
            $_POST['patient_type'],
            trim($_POST['primary_dentist']),
            ($_POST['last_visit'] ?: null),
            ($_POST['next_visit'] ?: null),
            trim($_POST['medical_alert']),
            trim($_POST['chart_remarks']),
            $pid
        ]);
        log_activity($pdo, 'Updated patient info', patient_name_of($pdo, $pid));
        set_flash('Patient information updated.');
        header("Location: records?patient=$pid&tab=overview"); exit;
    }

    // ----- Treatments -----
    if ($action === 'add_treatment') {
        $pdo->prepare(
            "INSERT INTO treatments (patient_id,patient_name,treatment_name,tooth,dentist,treatment_date,status,notes)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([
            $pid, $_POST['patient_name'], trim($_POST['treatment_name']), trim($_POST['tooth'] ?? ''),
            $_SESSION['name'] ?? '', $_POST['treatment_date'], $_POST['status'], trim($_POST['notes'] ?? '')
        ]);
        complete_arrived_visit($pdo, $pid, $_POST['treatment_date'] ?? null);   // arrived today -> Completed
        log_activity($pdo, 'Added treatment', patient_name_of($pdo, $pid) . ' — ' . trim($_POST['treatment_name']));
        set_flash('Treatment record added.');
        header("Location: records?patient=$pid&tab=treatments"); exit;
    }
    if ($action === 'delete_treatment') {
        $done = 0;
        foreach (bulk_ids() as $tid) {             // one record, or several ticked ones
            $t = $pdo->prepare("SELECT patient_name, treatment_name FROM treatments WHERE id=? AND patient_id=?");
            $t->execute([$tid, $pid]);
            $tRow = $t->fetch();
            if (!$tRow) continue;
            $pdo->prepare("DELETE FROM treatments WHERE id=? AND patient_id=?")->execute([$tid, $pid]);
            log_activity($pdo, 'Deleted treatment record', $tRow['patient_name'] . ' — ' . $tRow['treatment_name']);
            $done++;
        }
        set_flash($done === 1 ? 'Treatment record deleted.' : "$done treatment records deleted.", 'info');
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
            $x = $pdo->prepare("SELECT image_file FROM xrays WHERE id=? AND patient_id=?");
            $x->execute([$xid, $pid]);
            $row = $x->fetch();
            if (!$row) continue;
            if (is_file("$XRAY_DIR/" . basename($row['image_file']))) @unlink("$XRAY_DIR/" . basename($row['image_file']));
            $pdo->prepare("DELETE FROM xrays WHERE id=? AND patient_id=?")->execute([$xid, $pid]);
            $done++;
        }
        if ($done) log_activity($pdo, 'Deleted X-ray', $xrPatientName . ($done > 1 ? " ($done images)" : ''));
        set_flash($done === 1 ? 'X-ray deleted.' : "$done X-rays deleted.", 'info');
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
            $del = $pdo->prepare("DELETE FROM clinical_notes WHERE id=? AND patient_id=?");
            $del->execute([$nid, $pid]);
            $done += $del->rowCount();
        }
        if ($done) log_activity($pdo, 'Deleted clinical note', $nPatientName . ($done > 1 ? " ($done notes)" : ''));
        set_flash($done === 1 ? 'Note deleted.' : "$done notes deleted.", 'info');
        header("Location: records?patient=$pid&tab=notes"); exit;
    }
}

// ---------- Load this patient's records ----------
$treatments = $xrays = $notes = [];
$patientRow = null;
$toothMap   = [];
if ($pid) {
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
        </div>

        <!-- Tabs -->
        <div class="mb-3 d-flex gap-2 flex-wrap" data-keep-text>
            <?php
            $recTabs = ['overview'=>'📋 Overview','treatments'=>'🦷 Treatments','xrays'=>'📷 X-rays','notes'=>'📝 Notes'];
            foreach ($recTabs as $k=>$label): ?>
                <a href="records?patient=<?= $pid ?>&tab=<?= $k ?>" class="btn btn-sm <?= $tab===$k?'btn-dark-navy':'btn-light' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <?php if (!$pid): ?>
            <div class="card-box text-center text-muted2 py-4">Add a patient first, then you can record their treatments here.</div>

        <?php elseif ($tab === 'overview'): ?>
            <!-- ===== OVERVIEW: editable patient info + dental chart + remarks ===== -->
            <?php [$hfAns, $hfAt] = patient_health($pdo, $pid); ?>
            <?= health_form_styles() ?>
            <!-- Overview navbar: show everything, or just the part you need -->
            <nav class="rv-nav" id="rv-nav" aria-label="Overview sections" data-keep-text>
                <button type="button" data-rv="all" class="on">📋 All</button>
                <button type="button" data-rv="health">🩺 Health Questionnaire</button>
                <button type="button" data-rv="info">👤 Patient Information</button>
                <button type="button" data-rv="chart">🦷 Dental Chart</button>
            </nav>
            <div class="card-box mb-3 rv-sec" data-rv="health">
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
            <form method="POST" class="rv-sec" data-rv="info">
                <input type="hidden" name="action" value="update_patient_info">
                <input type="hidden" name="patient_id" value="<?= $pid ?>">
                <div class="card-box mb-3">
                    <div class="flex-between mb-3">
                        <h6 class="mb-0">👤 Patient Information <small class="text-muted2">(editable)</small></h6>
                        <button class="btn btn-teal btn-sm">💾 Save Changes</button>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4"><label class="field-label">Full Name</label>
                            <input name="name" class="form-control" value="<?= e($patientRow['name']) ?>" required></div>
                        <?php if (in_array(current_role(), ['admin','staff'], true)): ?>
                            <div class="col-md-4"><label class="field-label">Date of Birth / Age</label>
                                <div class="d-flex gap-2">
                                    <input type="date" name="dob" class="form-control" value="<?= e($patientRow['date_of_birth']) ?>" min="1900-01-01" max="<?= birth_date_max() ?>">
                                    <input type="number" name="age" class="form-control" style="max-width:90px;" min="0" max="120" step="1" data-digits value="<?= e($patientRow['age']) ?>" title="Filled in from the date of birth">
                                </div></div>
                        <?php else: ?>
                            <!-- Only admin and staff can change the birthday / age. -->
                            <div class="col-md-4"><label class="field-label">Date of Birth / Age</label>
                                <input class="form-control" readonly style="background:#eef3f3;" title="Only the admin or staff can change this"
                                       value="<?= e(trim(($patientRow['date_of_birth'] ? date('M j, Y', strtotime($patientRow['date_of_birth'])) . ' · ' : '') . ($patientRow['age'] !== null && $patientRow['age'] !== '' ? $patientRow['age'] . ' yrs' : ''))) ?>"></div>
                        <?php endif; ?>
                        <div class="col-md-4"><label class="field-label">Blood Type</label>
                            <select name="blood_type" class="form-select">
                                <option value="">Unknown</option>
                                <?php foreach (['O+','O-','A+','A-','B+','B-','AB+','AB-'] as $bt): ?>
                                    <option <?= $patientRow['blood_type']===$bt?'selected':'' ?>><?= $bt ?></option>
                                <?php endforeach; ?>
                            </select></div>

                        <div class="col-md-4"><label class="field-label">Phone</label>
                            <input name="phone" class="form-control" value="<?= e($patientRow['phone']) ?>" placeholder="09XX XXX XXXX" <?= phone_input_attrs() ?>></div>
                        <div class="col-md-4"><label class="field-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= e($patientRow['email']) ?>"></div>
                        <div class="col-md-4"><label class="field-label">Patient Type</label>
                            <select name="patient_type" class="form-select">
                                <?php foreach (['New','Returning','Regular'] as $pt): ?>
                                    <option <?= $patientRow['patient_type']===$pt?'selected':'' ?>><?= $pt ?></option>
                                <?php endforeach; ?>
                            </select></div>

                        <div class="col-md-4"><label class="field-label">Primary Dentist</label>
                            <?php if (in_array(current_role(), ['admin','staff'])): ?>
                                <input name="primary_dentist" class="form-control" value="<?= e($patientRow['primary_dentist']) ?>">
                            <?php else: ?>
                                <!-- Only admin and staff can change a patient's dentist. -->
                                <input class="form-control" value="<?= e($patientRow['primary_dentist']) ?>" readonly style="background:#eef3f3;"
                                       title="Only the admin or staff can change a patient's dentist">
                            <?php endif; ?></div>
                        <div class="col-md-4"><label class="field-label">Last Visit</label>
                            <input type="date" name="last_visit" class="form-control" value="<?= e($patientRow['last_visit']) ?>"></div>
                        <div class="col-md-4"><label class="field-label">Next Visit</label>
                            <input type="date" name="next_visit" class="form-control" value="<?= e($patientRow['next_visit']) ?>"></div>

                        <div class="col-12"><label class="field-label">Medical Alert</label>
                            <input name="medical_alert" class="form-control" value="<?= e($patientRow['medical_alert']) ?>" placeholder="e.g. Allergic to Penicillin"></div>

                        <div class="col-12"><label class="field-label">General Remarks</label>
                            <textarea name="chart_remarks" class="form-control" rows="3" placeholder="General remarks about this patient's dental chart..."><?= e($patientRow['chart_remarks']) ?></textarea></div>
                    </div>
                </div>
            </form>

            <div class="card-box rv-sec" data-rv="chart">
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
                           href="records?patient=<?= $pid ?>&tab=overview&session=<?= $s['id'] ?>">
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

        <?php elseif ($tab === 'treatments'): ?>
            <!-- ===== TREATMENTS ===== -->
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
                            <select name="status" class="form-select"><option>Completed</option><option>In Progress</option></select></div>
                    </div>
                    <label class="field-label mt-2">Notes</label>
                    <input name="notes" class="form-control mb-3" placeholder="Optional notes about this treatment">
                    <button class="btn btn-teal">Add Record</button>
                </form>
            </div>

            <div class="card-box">
                <h6 class="mb-2">History <span class="text-muted2" style="font-size:.85rem;">(<?= count($treatments) ?> record<?= count($treatments)==1?'':'s' ?>)</span></h6>
                <?= bulk_bar('bulk-treat', 'delete_treatment', 'treatment records', ['patient_id' => $pid]) ?>
                <?php foreach ($treatments as $t): ?>
                    <div class="flex-between py-3 border-bottom">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:44px;height:44px;border-radius:10px;background:#e6f7f5;display:flex;align-items:center;justify-content:center;">🦷</div>
                            <div>
                                <strong><?= e($t['treatment_name']) ?><?= $t['tooth'] ? ' (Tooth #'.e($t['tooth']).')' : '' ?></strong><br>
                                <small class="text-muted2"><?= e($t['treatment_date']) ?> • <?= e($t['dentist']) ?><?= $t['notes'] ? ' • '.e($t['notes']) : '' ?></small>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge-pill b-<?= $t['status']==='Completed'?'completed':'progress' ?>"><?= e($t['status']) ?></span>
                            <?= bulk_pick('bulk-treat', $t['id']) ?>
                            <form method="POST" class="m-0" onsubmit="return confirm('Delete this treatment record?')">
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
                <?= bulk_bar('bulk-xray', 'delete_xray', 'X-rays', ['patient_id' => $pid]) ?>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ($xrays as $xr): ?>
                        <div style="width:180px;border:1px solid #e3e9ee;border-radius:10px;overflow:hidden;">
                            <a href="xray?id=<?= (int)$xr['id'] ?>" target="_blank">
                                <img src="xray?id=<?= (int)$xr['id'] ?>" alt="X-ray" style="width:100%;height:130px;object-fit:cover;background:#000;">
                            </a>
                            <div style="padding:8px;">
                                <div class="d-flex align-items-center gap-2" style="font-size:.82rem;font-weight:600;"><?= bulk_pick('bulk-xray', $xr['id']) ?><span><?= $xr['caption'] ? e($xr['caption']) : 'X-ray' ?></span></div>
                                <div class="text-muted2" style="font-size:.72rem;"><?= e($xr['xray_date'] ?: date('M j, Y', strtotime($xr['created_at']))) ?></div>
                                <form method="POST" onsubmit="return confirm('Delete this X-ray?')" class="mt-1">
                                    <input type="hidden" name="action" value="delete_xray">
                                    <input type="hidden" name="patient_id" value="<?= $pid ?>">
                                    <input type="hidden" name="id" value="<?= $xr['id'] ?>">
                                    <button class="btn btn-sm w-100" style="background:#fbdcdc;color:#c0392b;">🗑 Delete</button>
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
                <?= bulk_bar('bulk-notes', 'delete_note', 'notes', ['patient_id' => $pid]) ?>
                <?php foreach ($notes as $nt): ?>
                    <div class="flex-between py-3 border-bottom">
                        <div style="flex:1;">
                            <div style="white-space:pre-wrap;font-size:.92rem;"><?= e($nt['note']) ?></div>
                            <small class="text-muted2"><?= date('M j, Y g:i A', strtotime($nt['created_at'])) ?> • <?= e($nt['author']) ?></small>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                        <?= bulk_pick('bulk-notes', $nt['id']) ?>
                        <form method="POST" class="m-0" onsubmit="return confirm('Delete this note?')">
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
.rv-nav { display: flex; gap: 4px; overflow-x: auto; background: var(--card, #fff); border-radius: 14px; padding: 6px;
          box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 14px; position: sticky; top: 8px; z-index: 20; }
.rv-nav button { border: 0; background: transparent; color: #52606b; font-size: .9rem; font-weight: 600;
                 padding: 8px 14px; border-radius: 10px; white-space: nowrap; flex: 0 0 auto; }
.rv-nav button:hover { background: #eef7f6; color: var(--teal-mid); }
.rv-nav button.on { background: var(--teal-mid); color: #fff; }
.rv-sec.rv-hide { display: none; }
@media print { .rv-nav { display: none; } .rv-sec.rv-hide { display: block; } }
</style>
<script>
// Records > Overview navbar: "All" shows every part; the others show just one. Remembered per browser.
(function () {
    var nav = document.getElementById('rv-nav');
    if (!nav) return;
    function show(k) {
        document.querySelectorAll('.rv-sec').forEach(function (s) { s.classList.toggle('rv-hide', k !== 'all' && s.dataset.rv !== k); });
        nav.querySelectorAll('button').forEach(function (b) {
            var on = b.dataset.rv === k; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        try { localStorage.setItem('records_overview', k); } catch (e) {}
    }
    nav.addEventListener('click', function (e) { var b = e.target.closest('button[data-rv]'); if (b) show(b.dataset.rv); });
    var saved = 'all';
    try { saved = localStorage.getItem('records_overview') || 'all'; } catch (e) {}
    show(nav.querySelector('[data-rv="' + saved + '"]') ? saved : 'all');
})();
</script>
</body>
</html>
