<?php
// ============================================================
//  GENERATE REPORTS  (reports.php)
// ============================================================
//  Image 7 in the design.
//  Pick a report type (the 4 cards at the top), choose a patient,
//  and the "Live Preview" on the right fills with REAL data from
//  the database. The Print button uses the browser's print dialog
//  (which can "Save as PDF").
// ============================================================
require_once 'config/auth.php';
require_login(['admin','dentist','staff']);
require_once 'includes/teeth.php';     // for drawing the dental chart in the report
require_once 'includes/assign.php';    // dentist_match_sql() for the dentist filter

// ---------- Save editable clinical info (medical alert, last/next visit) ----------
// This lets the dentist add a medical alert and set visit dates right here, and
// those values then appear in the printed report.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_clinical') {
    $pid = (int)$_POST['patient_id'];
    if (current_role() === 'dentist') {
        $own = [];
        $ownSql = "SELECT COUNT(*) FROM patients p WHERE p.id = ? AND " .
                  dentist_match_sql('p.primary_dentist', $_SESSION['name'] ?? '', $own);
        $chk = $pdo->prepare($ownSql); $chk->execute(array_merge([$pid], $own));
        if (!(int)$chk->fetchColumn()) deny_access("Changed clinical info of patient #$pid");
    }
    $pdo->prepare("UPDATE patients SET medical_alert=?, last_visit=?, next_visit=? WHERE id=?")
        ->execute([
            trim($_POST['medical_alert']),
            $_POST['last_visit']  ?: null,
            $_POST['next_visit']  ?: null,
            $pid
        ]);
    header("Location: reports?type=profile&patient_id=$pid&saved=1"); exit;
}

// ---- A patient's request for a printed dental chart is done ----
// The account holder gets a pop-up in their portal that the copy is ready.
require_once 'includes/dental_summary.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'chart_request_done') {
    ensure_chart_requests_table($pdo);
    $rq = $pdo->prepare("SELECT r.*, p.name FROM chart_requests r JOIN patients p ON p.id = r.patient_id WHERE r.id = ? AND r.done_at IS NULL");
    $rq->execute([(int)($_POST['request_id'] ?? 0)]);
    if ($req = $rq->fetch()) {
        $pdo->prepare("UPDATE chart_requests SET done_at = NOW(), done_by = ? WHERE id = ?")->execute([$_SESSION['name'] ?? 'Clinic', (int)$req['id']]);
        require_once 'includes/patient_notices.php';
        $forSelf = (int)$req['patient_id'] === (int)$req['holder_id'];
        add_patient_notice($pdo, (int)$req['holder_id'], 'chart_copy', 'good', '📄 Your printed dental chart is ready',
            'The printed copy of ' . ($forSelf ? 'your' : $req['name'] . '’s') . ' dental chart is ready. '
            . 'You can pick it up at the clinic on your next visit.');
        log_activity($pdo, 'Printed dental chart copy ready', $req['name']);
        set_flash($req['name'] . '’s request is marked done. The patient was told it is ready.');
    }
    header("Location: reports?type=profile#chart-requests"); exit;
}
$chartRequests = [];
try {
    ensure_chart_requests_table($pdo);
    $chartRequests = $pdo->query("SELECT r.id, r.patient_id, r.created_at, p.name, h.name AS holder
                                    FROM chart_requests r JOIN patients p ON p.id = r.patient_id
                               LEFT JOIN patients h ON h.id = r.holder_id
                                   WHERE r.done_at IS NULL ORDER BY r.created_at")->fetchAll();
} catch (Throwable $e) {}

// Which report + which patient are we showing?
$type      = $_GET['type'] ?? 'profile';                 // profile / treatment / appointments / patients
$patientId = (int)($_GET['patient_id'] ?? 0);

// Everyone who can open this page may run every report. A DENTIST is still
// limited to their own patients further down; admin and staff see all patients.
$allowedTypes = ['profile','treatment','appointments','patients'];

// only allow known report types (protects against typos in the URL)
if (!in_array($type, $allowedTypes)) {
    $type = $allowedTypes[0];
}

// Optional "show only this dentist's patients" filter. A dentist is always
// locked to their own name; admin and staff may pick any dentist (or All).
$isDentistUser = (current_role() === 'dentist');
$myDentistName = $_SESSION['name'] ?? '';
$filterDentist = $isDentistUser ? $myDentistName : trim($_GET['dentist'] ?? '');

// Appointments List: optionally only one status (e.g. only Approved, only Cancelled).
$reportStatuses = ['Confirmed','Pending','Arrived','Completed','Rescheduled','Cancelled','Disapproved','No-show','Expired','Needs Review'];
$filterStatus   = in_array($_GET['status'] ?? '', $reportStatuses, true) ? $_GET['status'] : '';

// The list of dentists for the filter dropdown.
$dentistOptions = $pdo->query(
    "SELECT name FROM users WHERE role='dentist' AND status <> 'archived' ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);

// All patients (used for the dropdown and the "List of Patients" report).
// Only REAL patients — exclude staff/dentist/admin accounts.
$repSql = "SELECT p.* FROM patients p
           LEFT JOIN users u ON p.user_id = u.id
           WHERE (u.id IS NULL OR u.role = 'patient')";
$repPrm = [];
if ($filterDentist !== '') {
    // Match the dentist in either shape the data uses (full or short name).
    $repSql .= " AND " . dentist_match_sql('p.primary_dentist', $filterDentist, $repPrm);
}
$repSql .= " ORDER BY p.name";
$repStmt = $pdo->prepare($repSql); $repStmt->execute($repPrm);
$patients = $repStmt->fetchAll();

// Keep the selection valid: if the dentist filter no longer includes the chosen
// patient, fall back to the first one in the filtered list.
$visibleIds = array_map('intval', array_column($patients, 'id'));
if ($patientId !== 0 && !in_array($patientId, $visibleIds)) {
    $patientId = 0;
}
if ($patientId === 0 && count($patients) > 0) {
    $patientId = $patients[0]['id'];
}

// Load the selected patient + their treatments + appointments + dental chart
$patient = null;
$pTreatments = $pAppointments = $pChart = [];
if ($patientId) {
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id=?");
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch();

    if ($patient) {
        $t = $pdo->prepare("SELECT * FROM treatments WHERE patient_id=? ORDER BY treatment_date DESC");
        $t->execute([$patientId]); $pTreatments = $t->fetchAll();

        $a = $pdo->prepare("SELECT * FROM appointments WHERE patient_id=? ORDER BY appointment_date DESC");
        $a->execute([$patientId]); $pAppointments = $a->fetchAll();

        $c = $pdo->prepare("SELECT * FROM odontogram WHERE patient_id=?");
        $c->execute([$patientId]); $pChart = $c->fetchAll();
    }
}

// ---------- Which dental chart (visit) goes into the report? ----------
// The chart is versioned per visit. The report defaults to the LATEST visit,
// but the user may pick any earlier visit from the dropdown.
$chartSessions = $patientId ? get_chart_sessions($pdo, $patientId) : [];   // newest first
$chartSid = (int)($_GET['session'] ?? 0);
$validSids = array_map('intval', array_column($chartSessions, 'id'));
if (!$chartSid || !in_array($chartSid, $validSids)) {
    $chartSid = $validSids[0] ?? 0;      // default = latest visit
}
$chartSession = null;
$chartIndex = 0;                          // "visit N of M" for the printed label
foreach (array_reverse($chartSessions) as $i => $s) {
    if ((int)$s['id'] === $chartSid) { $chartSession = $s; $chartIndex = $i + 1; }
}
$chartMap = ($patientId && $chartSid) ? build_tooth_map($pdo, $patientId, $chartSid)
                                      : ($patientId ? build_tooth_map($pdo, $patientId) : []);

// Small helper: turn a status word into the matching badge CSS class.
// (Our CSS only has: active/confirmed/completed=green, pending=orange,
//  cancelled/noshow=red, progress=blue. We map everything onto those.)
function badge_for($status) {
    $map = [
        'Completed'   => 'b-completed',
        'In Progress' => 'b-progress',
        'Pending'     => 'b-pending',
        'Confirmed'   => 'b-confirmed',
        'Cancelled'   => 'b-cancelled',
        'Disapproved' => 'b-disapproved',
        'No-show'     => 'b-noshow',
        'Rescheduled' => 'b-progress',
        'Active'      => 'b-active',
        'Inactive'    => 'b-inactive',
    ];
    return $map[$status] ?? 'b-pending';
}

$page_title = "Generate Reports";
include 'includes/head.php';
$active = 'reports';

// The 4 report cards (key, emoji, title, description)
$reportTypes = [
    'profile'      => ['👤', 'Patient Profile',   'Complete patient info, dental chart & medical history'],
    'treatment'    => ['💊', 'Treatment History', 'Detailed treatment records for a patient'],
    'appointments' => ['📅', 'Appointments List', 'All appointments by status and date'],
    'patients'     => ['👥', 'List of Patients',  'Complete patient registry with type and status'],
];
// Front-desk staff only see the reports they are allowed to run.
$reportTypes = array_intersect_key($reportTypes, array_flip($allowedTypes));
?>
<style>
/* The four report choices (inside one container, laid out by Bootstrap's grid). */
.report-type-card { display: flex; gap: 12px; align-items: flex-start; height: 100%; padding: 14px 16px;
                    border: 2px solid #e3e9ee; border-radius: 14px; background: #fff; color: var(--ink, #1d2b33);
                    text-decoration: none; transition: border-color .15s, background .15s, box-shadow .15s; }
.report-type-card:hover { border-color: var(--teal-light, #14b8a6); box-shadow: 0 4px 14px rgba(15,118,110,.10); color: inherit; }
.report-type-card.is-active { border-color: var(--teal-mid, #0f766e); background: #e8f5f3; }
.report-type-card .rt-icon { font-size: 1.7rem; line-height: 1; flex: none; }
.report-type-card .rt-title { font-weight: 700; margin-bottom: 2px; }
.report-type-card .rt-desc { font-size: .78rem; color: var(--muted, #6b7a86); line-height: 1.35; }

/* Quick counts inside the report: a small row of four, never a swipe strip. */
.report-counts { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 14px; }
.report-counts > div { border: 1px solid #e3e9ee; border-radius: 8px; padding: 6px 4px; text-align: center; background: #fbfdfd; }
.report-counts strong { display: block; font-size: 1.15rem; line-height: 1.2; }
.report-counts span { display: block; font-size: .62rem; letter-spacing: .6px; text-transform: uppercase; color: var(--muted); }

@media print {
  /* Print ONLY the report. Everything else is REMOVED (display:none), not just
     made invisible — invisible things still take up space and push the report
     onto a second page. The wrappers around the report are flattened. */
  body *:not(:has(#report-area)):not(#report-area):not(#report-area *) { display: none !important; }
  body *:has(#report-area) {
    margin: 0 !important; padding: 0 !important; border: 0 !important; box-shadow: none !important;
    background: none !important; width: auto !important; max-width: none !important; min-height: 0 !important;
    flex: none !important; display: block !important; position: static !important;
    break-inside: auto !important; page-break-inside: auto !important;
  }
  #report-area {
    border: none !important; box-shadow: none !important; border-radius: 0 !important; padding: 0 !important;
    -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important;
  }
  @page { margin: 0; }                                     /* no room for browser headers/footers */
  body { padding: 1cm !important; -webkit-box-decoration-break: clone; box-decoration-break: clone; }
  /* List reports: compact rows, and dates / times / phones never split over two lines */
  #report-area table.data th, #report-area table.data td { padding: 5px 7px !important; font-size: 10.5px !important; }
  #report-area table.data td.nw { white-space: nowrap; }
  #report-area table.data tr { break-inside: avoid; }
}

/* Compact print sizing. Switched on (body.print-compact) right before printing
   and off afterwards, so the one-page check below measures exactly what prints. */
body.print-compact #report-area { font-size: 11px; padding: 0 !important; border: none !important; }
/* Compact everything so a full profile fits on one page */
body.print-compact #report-area h4 { font-size: 15px !important; }
body.print-compact #report-area h6 { font-size: 12px !important; margin-top: 7px !important; margin-bottom: 3px !important; }
body.print-compact #report-area table { margin-bottom: 4px !important; font-size: 10px !important; }
body.print-compact #report-area table td, body.print-compact #report-area table th { padding: 2px 5px !important; font-size: 10px !important; line-height: 1.25 !important; }
body.print-compact #report-area .badge-pill { padding: 1px 6px !important; font-size: 9px !important; }
body.print-compact #report-area .report-counts { gap: 5px; margin-bottom: 6px; }
body.print-compact #report-area .report-counts > div { padding: 3px 2px; }
body.print-compact #report-area .report-counts strong { font-size: 13px; }
body.print-compact #report-area .report-counts span { font-size: 8px; }
/* Shrink the dental chart */
body.print-compact #report-area .odo-arch { padding: 4px 3px !important; gap: 2px !important; margin: 3px 0 !important; }
body.print-compact #report-area .tooth { width: 20px !important; }
body.print-compact #report-area .tooth svg { width: 18px !important; height: 19px !important; }
body.print-compact #report-area .tooth .num { font-size: .48rem !important; margin-top: 0 !important; }
body.print-compact #report-area .report-signature { margin-top: 10px !important; padding-top: 6px !important; }
body.print-compact #report-area .report-signature-name { margin-bottom: 22px !important; }
body.print-compact #report-area .report-signature > div:last-child { gap: 20px !important; flex-wrap: nowrap !important; }
body.print-compact #report-area .report-signature > div:last-child > div { min-width: 0 !important; flex: 1 1 0; font-size: 10px; }
body.print-compact #report-area .report-signature > div:last-child > div > div { font-size: 10px !important; }
body.print-compact #report-area .text-muted2, body.print-compact #report-area [style*="font-size:.85rem"] { font-size: 10.5px !important; }
</style>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div>
                <h1>Generate Reports</h1>
                <div class="sub">Create and export clinical reports for patients, appointments, and treatments.</div>
            </div>
            <?php $repPdfName = pdf_name($reportTypes[$type][1] ?? 'Report', $patient['name'] ?? '', date('Y-m-d')); ?>
            <div class="d-flex align-items-center gap-2">
                <?= print_menu('#report-area', $repPdfName) ?>
            </div>
        </div>

        <!-- ===== Report type cards — one container, Bootstrap grid:
             1 per row on small phones, 2 on larger phones / tablets, 4 on wide screens ===== -->
        <div class="card-box report-types mb-3">
            <h6 class="mb-3">Choose a report</h6>
            <div class="row g-3">
                <?php foreach ($reportTypes as $key => $rt):
                    $isActive = ($type === $key);
                ?>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <a href="reports?type=<?= $key ?>&patient_id=<?= $patientId ?><?= $filterDentist !== '' ? '&dentist='.urlencode($filterDentist) : '' ?>"
                           class="report-type-card<?= $isActive ? ' is-active' : '' ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
                            <div class="rt-icon"><?= $rt[0] ?></div>
                            <div>
                                <div class="rt-title"><?= $rt[1] ?></div>
                                <div class="rt-desc"><?= $rt[2] ?></div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($type === 'profile' && $chartRequests): ?>
        <!-- ===== Patients who asked for a printed copy of their dental chart ===== -->
        <div class="card-box mt-3" id="chart-requests" style="border-left:5px solid var(--gold);">
            <h6 class="mb-1">📄 Printed dental chart requests <span class="badge-pill b-pending"><?= count($chartRequests) ?></span></h6>
            <div class="text-muted2 mb-2" style="font-size:.85rem;">Open the patient's report, print it, then press <b>Done</b> — the patient is told it is ready to pick up.</div>
            <?php foreach ($chartRequests as $cr): ?>
                <div class="flex-between flex-wrap gap-2 py-2 border-bottom">
                    <div><strong><?= e($cr['name']) ?></strong>
                        <small class="text-muted2">· requested <?= date('M j, g:i A', strtotime($cr['created_at'])) ?>
                            <?= $cr['holder'] && $cr['holder'] !== $cr['name'] ? ' by ' . e($cr['holder']) : '' ?></small></div>
                    <div class="d-flex gap-2">
                        <a href="reports?type=profile&patient_id=<?= (int)$cr['patient_id'] ?>" class="btn btn-sm btn-outline-teal" data-keep-text>🖨 Open report</a>
                        <form method="POST" class="m-0">
                            <input type="hidden" name="action" value="chart_request_done">
                            <input type="hidden" name="request_id" value="<?= (int)$cr['id'] ?>">
                            <button class="btn btn-sm btn-teal" data-keep-text>✔ Done</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="row g-3 mt-1">
            <!-- ===== Left: configuration (scrolls on its own) ===== -->
            <div class="col-lg-4">
                <div data-fit-screen class="rep-left">
                <div class="card-box mb-3">
                    <h5 class="mb-1"><?= e($reportTypes[$type][1]) ?> Report</h5>
                    <div class="text-muted2 mb-3" style="font-size:.85rem;">Configure the report options below.</div>

                    <!-- Filter the patient list by dentist (admin & staff only;
                         a dentist is always locked to their own patients) -->
                    <?php if (!$isDentistUser && count($dentistOptions) > 0): ?>
                    <form method="GET">
                        <input type="hidden" name="type" value="<?= e($type) ?>">
                        <?php if ($filterStatus !== ''): ?><input type="hidden" name="status" value="<?= e($filterStatus) ?>"><?php endif; ?>
                        <label class="field-label">Dentist</label>
                        <select name="dentist" class="form-select mb-1" data-search="🔍 Search dentist…" onchange="this.form.submit()">
                            <option value="">All dentists</option>
                            <?php foreach ($dentistOptions as $dn): ?>
                                <option value="<?= e($dn) ?>" <?= ($filterDentist === $dn)?'selected':'' ?>><?= e($dn) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-muted2 mb-3" style="font-size:.78rem;">
                            Pick a dentist to work with only that dentist's patients.
                        </div>
                    </form>
                    <?php endif; ?>

                    <?php if ($type === 'appointments'): ?>
                        <!-- Appointments List: only one status (reloads the preview) -->
                        <form method="GET">
                            <input type="hidden" name="type" value="appointments">
                            <input type="hidden" name="dentist" value="<?= e($filterDentist) ?>">
                            <label class="field-label">Status</label>
                            <select name="status" class="form-select mb-1" data-search="🔍 Search status…" onchange="this.form.submit()">
                                <option value="">All statuses</option>
                                <?php foreach ($reportStatuses as $st): ?>
                                    <option value="<?= e($st) ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= e(status_label($st)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="text-muted2 mb-3" style="font-size:.78rem;">
                                Print only Approved, Pending, Cancelled, Rescheduled… appointments.
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if ($type === 'profile' || $type === 'treatment'): ?>
                        <!-- choose a patient (reloads the preview) -->
                        <form method="GET">
                            <input type="hidden" name="type" value="<?= e($type) ?>">
                            <input type="hidden" name="dentist" value="<?= e($filterDentist) ?>">
                            <label class="field-label">Select Patient</label>
                            <?php if (count($patients) === 0): ?>
                                <div class="text-muted2 mb-3" style="font-size:.85rem;">
                                    No patients found for this dentist.
                                </div>
                            <?php else: ?>
                            <select name="patient_id" class="form-select mb-3" data-search="🔍 Search patient, e.g. Bi…" onchange="this.form.submit()">
                                <?php foreach ($patients as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= ($p['id']==$patientId)?'selected':'' ?>>
                                        <?= e($p['name']) ?> · Age <?= e($p['age']) ?> · <?= e($p['patient_type']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                        </form>

                        <!-- choose WHICH visit's dental chart goes into the report -->
                        <?php if ($type === 'profile' && count($chartSessions) > 0): ?>
                        <form method="GET">
                            <input type="hidden" name="type" value="<?= e($type) ?>">
                            <input type="hidden" name="patient_id" value="<?= (int)$patientId ?>">
                            <input type="hidden" name="dentist" value="<?= e($filterDentist) ?>">
                            <label class="field-label">Dental Chart to Include</label>
                            <select name="session" class="form-select mb-1" data-search="🔍 Search visit…" onchange="this.form.submit()">
                                <?php $oldFirst = array_reverse($chartSessions); ?>
                                <?php foreach ($oldFirst as $i => $s): ?>
                                    <option value="<?= $s['id'] ?>" <?= ((int)$s['id']===$chartSid)?'selected':'' ?>>
                                        Visit <?= $i+1 ?><?= ($i === count($oldFirst)-1) ? ' (latest)' : '' ?>
                                        · <?= date('M j, Y', strtotime($s['visit_date'])) ?><?= $s['title'] ? ' · '.e($s['title']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="text-muted2 mb-3" style="font-size:.78rem;">
                                Defaults to the most recent visit. Pick an earlier one to print that chart instead.
                            </div>
                        </form>
                        <?php endif; ?>

                        <?php if ($type === 'profile' && $patient): ?>
                        <!-- Tick / untick to show or hide that part of the report (preview AND print). -->
                        <label class="field-label">Include Sections</label>
                        <?php foreach (['summary' => 'Patient Summary', 'chart' => 'Dental Chart Summary',
                                        'alerts' => 'Medical Alerts', 'appointments' => 'Appointment Summary'] as $sec => $secLabel): ?>
                            <div class="form-check<?= $sec === 'appointments' ? ' mb-3' : '' ?>">
                                <input class="form-check-input report-section-toggle" type="checkbox" id="sec-<?= $sec ?>" data-section="<?= $sec ?>" checked>
                                <label class="form-check-label" for="sec-<?= $sec ?>"><?= $secLabel ?></label>
                            </div>
                        <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if ($type === 'profile' && $patient): ?>
                            <hr>
                            <label class="field-label" style="color:var(--teal-mid);font-weight:600;">✏️ Clinical Info (editable — shows in the report &amp; print)</label>
                            <?php if (isset($_GET['saved'])): ?><div class="alert alert-success py-1 px-2 mt-1" style="font-size:.8rem;">Saved ✓</div><?php endif; ?>
                            <form method="POST" class="mt-2">
                                <input type="hidden" name="action" value="update_clinical">
                                <input type="hidden" name="patient_id" value="<?= $patientId ?>">
                                <label class="field-label">Medical Alert</label>
                                <textarea name="medical_alert" class="form-control mb-2" rows="2" placeholder="e.g. Allergic to Penicillin"><?= e($patient['medical_alert']) ?></textarea>
                                <div class="row">
                                    <div class="col"><label class="field-label">Last Visit</label>
                                        <input type="date" name="last_visit" class="form-control mb-2" value="<?= e($patient['last_visit']) ?>"></div>
                                    <div class="col"><label class="field-label">Next Visit</label>
                                        <input type="date" name="next_visit" class="form-control mb-2" value="<?= e($patient['next_visit']) ?>"></div>
                                </div>
                                <button class="btn btn-teal btn-sm w-100">💾 Save Clinical Info</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <!-- Search inside the list: only the matching rows show (and print). -->
                        <label class="field-label">Search in this list</label>
                        <input type="search" class="form-control mb-1" autocomplete="off" placeholder="🔍 Type a name, e.g. Bi…"
                               data-filter-rows="#report-area table.data">
                        <div class="text-muted2 mb-2" style="font-size:.78rem;" data-filter-count></div>
                        <div class="text-muted2" style="font-size:.9rem;">This report lists all records in the system. Use Print / Export PDF to save it.</div>
                    <?php endif; ?>
                </div>

                <div class="card-box">
                    <h6 class="mb-2">Export Options</h6>
                    <div class="d-flex gap-2 mb-3" data-keep-text>
                        <button class="btn btn-light w-100" onclick="window.print()">🖨 Print</button>
                        <button class="btn btn-dark-navy w-100" onclick="downloadPdf('#report-area', <?= e(json_encode($repPdfName)) ?>)">📄 Download PDF</button>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input report-section-toggle" type="checkbox" id="sec-letterhead" data-section="letterhead" checked>
                        <label class="form-check-label" for="sec-letterhead">Include clinic letterhead</label>
                    </div>
                </div>
                </div><!-- /.rep-left -->
            </div>

            <!-- ===== Right: live preview (only the report scrolls) ===== -->
            <div class="col-lg-8">
                <div class="card-box">
                    <div class="flex-between mb-3">
                        <div>
                            <h5 class="mb-0">Live Preview</h5>
                            <div class="text-muted2" style="font-size:.82rem;">Updates as you configure</div>
                        </div>
                        <?= print_menu('#report-area', $repPdfName, 'window.print()', 'btn-sm btn-outline-teal') ?>
                    </div>

                    <!-- the printable report area -->
                    <div id="report-area" data-fit-screen<?= in_array($type, ['profile','treatment'], true) ? ' data-fit-one-page' : '' ?> style="border:1px solid #e3e9ee;border-radius:10px;padding:22px;background:#fff;">

                        <!-- letterhead -->
                        <div data-section="letterhead" class="flex-between" style="border-bottom:2px solid var(--teal-dark);padding-bottom:10px;margin-bottom:16px;">
                            <div>
                                <h4 style="color:var(--teal-dark);margin:0;">St. Therese Dental Clinic</h4>
                                <div class="text-muted2" style="font-size:.78rem;">123 Dental St., Naic, Cavite · (046) 123-4567</div>
                            </div>
                            <div style="text-align:right;color:var(--teal-mid);font-size:.8rem;">
                                <strong><?= e($reportTypes[$type][1]) ?> Report</strong><br>
                                Generated: <?= date('M j, Y') ?>
                            </div>
                        </div>

                        <?php if ($type === 'profile' && $patient): ?>
                            <!-- ===== PATIENT PROFILE ===== -->
                            <div data-section="summary">
                            <div style="background:var(--teal-dark);color:#fff;border-radius:8px;padding:12px;display:flex;gap:24px;flex-wrap:wrap;font-size:.82rem;margin-bottom:16px;">
                                <div><div style="opacity:.7;font-size:.7rem;">PATIENT NAME</div><strong><?= e($patient['name']) ?></strong></div>
                                <div><div style="opacity:.7;font-size:.7rem;">DATE OF BIRTH</div><strong><?= e($patient['date_of_birth']) ?></strong></div>
                                <div><div style="opacity:.7;font-size:.7rem;">AGE</div><strong><?= e($patient['age']) ?> yrs</strong></div>
                                <div><div style="opacity:.7;font-size:.7rem;">BLOOD TYPE</div><strong><?= e($patient['blood_type']) ?></strong></div>
                                <div><div style="opacity:.7;font-size:.7rem;">TYPE</div><strong><?= e($patient['patient_type']) ?></strong></div>
                            </div>

                            <!-- quick counts -->
                            <div class="report-counts">
                                <div><strong><?= count($pTreatments) + count($pAppointments) ?></strong><span>Total Visits</span></div>
                                <div><strong><?= count($pTreatments) ?></strong><span>Treatments</span></div>
                                <div><strong><?= count($pAppointments) ?></strong><span>Appointments</span></div>
                                <div><strong><?= $patient['medical_alert'] ? 1 : 0 ?></strong><span>Alerts</span></div>
                            </div>

                            <!-- contact info -->
                            <h6>Contact Information</h6>
                            <table class="table table-sm" style="font-size:.85rem;">
                                <tr><td><strong>Phone</strong></td><td><?= e($patient['phone']) ?></td><td><strong>Email</strong></td><td><?= e($patient['email']) ?></td></tr>
                                <tr><td><strong>Primary Dentist</strong></td><td><?= e($patient['primary_dentist']) ?></td><td><strong>Patient Type</strong></td><td><span class="badge-pill b-active"><?= e($patient['patient_type']) ?></span></td></tr>
                                <tr><td><strong>Last Visit</strong></td><td><?= e($patient['last_visit']) ?></td><td><strong>Next Visit</strong></td><td><?= e($patient['next_visit']) ?></td></tr>
                            </table>
                            </div><!-- /summary -->

                            <!-- medical alerts -->
                            <div data-section="alerts">
                            <h6 class="mt-3">Medical Alerts</h6>
                            <?php if ($patient['medical_alert']): ?>
                                <div style="background:#fdecec;border:1px solid #f5b5b5;color:#c0392b;border-radius:6px;padding:8px 12px;font-size:.85rem;">
                                    ⚠️ <?= e($patient['medical_alert']) ?>
                                </div>
                            <?php else: ?>
                                <div class="text-muted2" style="font-size:.85rem;">No known medical alerts.</div>
                            <?php endif; ?>
                            </div><!-- /alerts -->

                            <!-- dental chart (odontogram) -->
                            <div data-section="chart">
                            <h6 class="mt-3">Dental Chart (Odontogram)</h6>
                            <?php if ($chartSession): ?>
                                <div style="font-size:.72rem;color:#666;margin:-4px 0 6px;">
                                    Chart as of <strong><?= date('M j, Y', strtotime($chartSession['visit_date'])) ?></strong>
                                    <?= $chartSession['title'] ? ' — ' . e($chartSession['title']) : '' ?>
                                    <?= count($chartSessions) > 1 ? ' (visit ' . $chartIndex . ' of ' . count($chartSessions) . ')' : '' ?>
                                </div>
                            <?php endif; ?>
                            <div style="text-align:center;font-size:.68rem;color:#777;">Upper</div>
                            <div class="odo-arch" style="margin-bottom:4px;">
                                <?php foreach ($UPPER_TEETH as $t) echo render_tooth($t, $chartMap[$t] ?? 'Healthy'); ?>
                            </div>
                            <div class="odo-arch">
                                <?php foreach ($LOWER_TEETH as $t) echo render_tooth($t, $chartMap[$t] ?? 'Healthy'); ?>
                            </div>
                            <div style="text-align:center;font-size:.68rem;color:#777;">Lower</div>

                            <!-- Color legend: what each tooth color means -->
                            <?php $toothLegend = ['Healthy'=>'#6bbf6b','Decayed'=>'#e0a92e','Filled'=>'#3b9ae0','Missing'=>'#e05b5b','Crowned'=>'#a06fd6','Extracted'=>'#999999','Impacted'=>'#d99a00','Fractured'=>'#cc4444']; ?>
                            <div style="display:flex;flex-wrap:wrap;gap:6px 14px;font-size:.72rem;margin-top:8px;border-top:1px dashed #ddd;padding-top:6px;">
                                <?php foreach ($toothLegend as $cond => $color): ?>
                                    <span style="white-space:nowrap;"><span style="display:inline-block;width:11px;height:11px;border-radius:2px;background:<?= $color ?>;border:1px solid rgba(0,0,0,.15);vertical-align:middle;"></span> <?= $cond ?></span>
                                <?php endforeach; ?>
                            </div>

                            <?php
                                $conds = [];
                                foreach (array_merge($UPPER_TEETH, $LOWER_TEETH) as $t) {
                                    $s = $chartMap[$t] ?? 'Healthy';
                                    if ($s !== 'Healthy') $conds[] = "Tooth $t: $s";
                                }
                            ?>
                            <div style="font-size:.8rem;margin-top:6px;"><strong>Conditions:</strong> <?= $conds ? e(implode(' · ', $conds)) : 'All teeth healthy' ?></div>

                            <!-- notes recorded for THIS visit (dated, unlike the old general remarks) -->
                            <h6 class="mt-3">Visit Notes</h6>
                            <?php $vNotes = ($chartSession && trim((string)$chartSession['notes']) !== '')
                                            ? trim($chartSession['notes']) : ''; ?>
                            <div style="font-size:.85rem;border:1px solid #eee;border-radius:6px;padding:8px 12px;background:#fafafa;<?= $vNotes !== '' ? 'white-space:pre-wrap;' : '' ?>"><?php
                                if ($vNotes !== '') {
                                    echo e($vNotes);
                                } else {
                                    echo '<span style="color:#999;">No notes recorded for this visit.</span>';
                                }
                            ?></div>
                            </div><!-- /chart -->

                            <!-- treatment summary -->
                            <h6 class="mt-3">Treatment Summary</h6>
                            <table class="data" style="font-size:.82rem;">
                                <thead><tr><th>Date</th><th>Treatment</th><th>Tooth/Area</th><th>Dentist</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($pTreatments as $tr): ?>
                                    <tr>
                                        <td><?= e($tr['treatment_date']) ?></td>
                                        <td><?= e($tr['treatment_name']) ?></td>
                                        <td><?= e($tr['tooth']) ?></td>
                                        <td><?= e($tr['dentist']) ?></td>
                                        <td><span class="badge-pill <?= badge_for($tr['status']) ?>"><?= e(status_label($tr['status'])) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($pTreatments)): ?><tr><td colspan="5" class="text-muted2 text-center">No treatments on record.</td></tr><?php endif; ?>
                                </tbody>
                            </table>

                            <!-- appointment summary -->
                            <div data-section="appointments">
                            <h6 class="mt-3">Appointment Summary</h6>
                            <?php
                                $apptCounts = array_count_values(array_map(fn($a) => $a['status'], $pAppointments));
                                ksort($apptCounts);
                            ?>
                            <?php if ($apptCounts): ?>
                                <div style="font-size:.8rem;margin-bottom:6px;">
                                    <?php foreach ($apptCounts as $st => $n): ?>
                                        <span class="badge-pill <?= badge_for($st) ?>" style="margin-right:4px;"><?= e($st) ?>: <?= $n ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <table class="data" style="font-size:.82rem;">
                                <thead><tr><th>Date</th><th>Time</th><th>Treatment</th><th>Dentist</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($pAppointments as $ap): ?>
                                    <tr>
                                        <td><?= e($ap['appointment_date']) ?></td>
                                        <td><?= e($ap['appointment_time']) ?></td>
                                        <td><?= e($ap['treatment']) ?></td>
                                        <td><?= e($ap['dentist']) ?></td>
                                        <td><span class="badge-pill <?= badge_for($ap['status']) ?>"><?= e(status_label($ap['status'])) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($pAppointments)): ?><tr><td colspan="5" class="text-muted2 text-center">No appointments on record.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                            </div><!-- /appointments -->

                        <?php elseif ($type === 'treatment' && $patient): ?>
                            <!-- ===== TREATMENT HISTORY ===== -->
                            <h6><?= e($patient['name']) ?> — Treatment History</h6>
                            <table class="data" style="font-size:.85rem;">
                                <thead><tr><th>Date</th><th>Treatment</th><th>Tooth</th><th>Dentist</th><th>Status</th><th>Notes</th></tr></thead>
                                <tbody>
                                <?php foreach ($pTreatments as $tr): ?>
                                    <tr>
                                        <td><?= e($tr['treatment_date']) ?></td>
                                        <td><?= e($tr['treatment_name']) ?></td>
                                        <td><?= e($tr['tooth']) ?></td>
                                        <td><?= e($tr['dentist']) ?></td>
                                        <td><span class="badge-pill <?= badge_for($tr['status']) ?>"><?= e(status_label($tr['status'])) ?></span></td>
                                        <td><?= e($tr['notes']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($pTreatments)): ?><tr><td colspan="6" class="text-muted2 text-center">No treatments on record.</td></tr><?php endif; ?>
                                </tbody>
                            </table>

                        <?php elseif ($type === 'appointments'): ?>
                            <!-- ===== APPOINTMENTS LIST ===== -->
                            <?php
                            // Only the chosen dentist's appointments (a dentist is always
                            // limited to their own) and, if picked, only one status.
                            $apSql = "SELECT * FROM appointments WHERE 1=1"; $apPrm = [];
                            if ($filterDentist !== '') $apSql .= " AND " . dentist_match_sql('dentist', $filterDentist, $apPrm);
                            if ($filterStatus !== '') { $apSql .= " AND status = ?"; $apPrm[] = $filterStatus; }
                            $apStmt = $pdo->prepare($apSql . " ORDER BY appointment_date DESC, STR_TO_DATE(REPLACE(appointment_time, ' ', ''), '%h:%i%p')");
                            $apStmt->execute($apPrm);
                            $allAppts = $apStmt->fetchAll();
                            ?>
                            <h6><?= $filterStatus !== '' ? e(status_label($filterStatus)) . ' Appointments' : 'All Appointments' ?></h6>
                            <div style="font-size:.85rem;margin-bottom:8px;">
                                <strong><?= $filterDentist !== '' ? 'Dentist: ' . e($filterDentist) : 'All dentists' ?></strong>
                                · <?= $filterStatus !== '' ? e(status_label($filterStatus)) . ' only' : 'all statuses' ?>
                                · <?= count($allAppts) ?> appointment<?= count($allAppts) === 1 ? '' : 's' ?>
                            </div>
                            <table class="data" style="font-size:.85rem;">
                                <thead><tr><th>Patient</th><th>Dentist</th><th>Date</th><th>Time</th><th>Treatment</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($allAppts as $ap): ?>
                                    <tr>
                                        <td><?= e($ap['patient_name']) ?></td>
                                        <td><?= e($ap['dentist']) ?></td>
                                        <td class="nw"><?= e($ap['appointment_date']) ?></td>
                                        <td class="nw"><?= e($ap['appointment_time']) ?></td>
                                        <td><?= e($ap['treatment']) ?></td>
                                        <td class="nw"><span class="badge-pill <?= badge_for($ap['status']) ?>"><?= e(status_label($ap['status'])) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$allAppts): ?>
                                    <tr><td colspan="6" style="text-align:center;padding:20px;color:#888;">No appointments match these choices.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>

                        <?php else: /* patients */ ?>
                            <!-- ===== LIST OF PATIENTS ===== -->
                            <h6>Patient Registry</h6>
                            <?php if ($filterDentist !== ''): ?>
                                <div style="font-size:.85rem;margin-bottom:8px;">
                                    <strong>Patients of <?= e($filterDentist) ?></strong>
                                    · <?= count($patients) ?> patient<?= count($patients) === 1 ? '' : 's' ?>
                                </div>
                            <?php else: ?>
                                <div style="font-size:.85rem;margin-bottom:8px;">
                                    <strong>All patients</strong> · <?= count($patients) ?> total
                                </div>
                            <?php endif; ?>
                            <table class="data" style="font-size:.85rem;">
                                <thead><tr><th>Name</th><th>Age</th><th>Phone</th><th>Type</th><th>Primary Dentist</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($patients as $p): ?>
                                    <tr>
                                        <td><?= e($p['name']) ?></td>
                                        <td><?= e($p['age']) ?></td>
                                        <td class="nw"><?= e($p['phone']) ?></td>
                                        <td><?= e($p['patient_type']) ?></td>
                                        <td><?= e($p['primary_dentist']) ?></td>
                                        <td><span class="badge-pill <?= badge_for($p['status']) ?>"><?= e(status_label($p['status'])) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (count($patients) === 0): ?>
                                    <tr><td colspan="6" style="text-align:center;padding:20px;color:#888;">No patients found.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                        <!-- ===== Handling dentist + signature line (appears on every report) ===== -->
                        <?php
                            // Who handled this? For a patient report it is their primary dentist.
                            // Otherwise it is the dentist running the report (or "—" for admin/staff).
                            $handling = $patient['primary_dentist'] ?? '';
                            if ($handling === '' && current_role() === 'dentist') $handling = $_SESSION['name'];
                            if ($handling === '') $handling = '____________________';
                            $signatory = $_SESSION['name'] ?? '';
                        ?>
                        <div class="report-signature" style="margin-top:26px;padding-top:14px;border-top:1px solid #e3e9ee;">
                            <div class="report-signature-name" style="font-size:.82rem;color:#555;margin-bottom:34px;">
                                <strong>Handling Dentist:</strong> <?= e($handling) ?>
                            </div>
                            <div style="display:flex;gap:48px;flex-wrap:wrap;">
                                <div style="min-width:230px;">
                                    <div style="border-top:1px solid #333;padding-top:5px;font-size:.8rem;">
                                        <strong><?= e($handling) ?></strong><br>
                                        <span style="color:#777;">Attending Dentist — Signature over Printed Name</span>
                                    </div>
                                </div>
                                <div style="min-width:230px;">
                                    <div style="border-top:1px solid #333;padding-top:5px;font-size:.8rem;">
                                        <strong><?= e($signatory) ?></strong><br>
                                        <span style="color:#777;">Prepared by — Signature over Printed Name</span>
                                    </div>
                                </div>
                                <div style="min-width:150px;">
                                    <div style="border-top:1px solid #333;padding-top:5px;font-size:.8rem;">
                                        <strong><?= date('M j, Y') ?></strong><br>
                                        <span style="color:#777;">Date</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div><!-- /report-area -->
                </div>
            </div>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
<script>
// Fit a single-patient report on ONE printed page. Just before printing, lay the report
// out at printed-page width, measure it, and scale it down if it would run
// longer than a page. Sized for the smaller of A4 / Letter with 1 cm margins
// (~718 x 979 CSS px), so it fits either paper.
(function () {
    var PAGE_W = 718, PAGE_H = 975;
    var area = document.getElementById('report-area');
    // Only single-patient reports are squeezed onto one page; the long
    // clinic-wide lists (all appointments / all patients) print across pages.
    if (!area || !area.hasAttribute('data-fit-one-page')) return;
    window.addEventListener('beforeprint', function () {
        document.body.classList.add('print-compact');
        area.style.zoom = '';
        var oldW = area.style.width;
        area.style.width = PAGE_W + 'px';
        var h = area.scrollHeight;
        area.style.width = oldW;
        if (h > PAGE_H) area.style.zoom = Math.max(0.55, PAGE_H / h).toFixed(3);
    });
    window.addEventListener('afterprint', function () {
        document.body.classList.remove('print-compact');
        area.style.zoom = '';
    });
})();

// "Include Sections" + "Include clinic letterhead": each checkbox shows or
// hides the matching [data-section] part of the report. Hidden parts are
// display:none, so Print / PDF match the preview exactly. The choice is
// remembered in this browser, so it survives switching patients.
(function () {
    var KEY = 'reportSections';
    var saved = {};
    try { saved = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) {}

    function apply(box) {
        var sec = box.dataset.section;
        document.querySelectorAll('#report-area [data-section="' + sec + '"]').forEach(function (el) {
            el.style.display = box.checked ? '' : 'none';
        });
    }

    document.querySelectorAll('.report-section-toggle').forEach(function (box) {
        if (saved.hasOwnProperty(box.dataset.section)) box.checked = !!saved[box.dataset.section];
        apply(box);
        box.addEventListener('change', function () {
            apply(box);
            saved[box.dataset.section] = box.checked;
            try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch (e) {}
        });
    });
})();
</script>
</body></html>
