<?php
// ============================================================
//  WEEKLY NO-SHOW REPORT  (noshow.php)
// ============================================================
//  Image 8 in the design.
//  Shows appointments that were missed (No-show), Cancelled, or
//  Rescheduled. All data is pulled live from the `appointments`
//  table by filtering on the status column.
// ============================================================
require_once 'config/auth.php';
require_once 'includes/mailer.php';
require_once 'includes/message_templates.php';
require_once 'includes/assign.php';
require_once 'includes/noshow_check.php';   // patient_noshow_count()
require_login(['admin','dentist','staff']);

// ---------- Staff decision on a flagged appointment ----------
// The scanner only ever sets "Needs Review". Nothing is sent and nothing
// is counted against the patient until a person confirms it here.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    // Frequent cancellations: staff reviewed the patient -> booking allowed again.
    if ($id && $action === 'restore_cancel' && in_array(current_role(), ['admin','staff'], true)) {
        $pdo->prepare("UPDATE patients SET cancel_reset_at = NOW(), cancel_reset_by = ? WHERE id = ?")
            ->execute([$_SESSION['name'] ?? 'staff', $id]);
        $nm = $pdo->prepare("SELECT name FROM patients WHERE id = ?"); $nm->execute([$id]);
        $rn = $nm->fetchColumn() ?: 'Patient';
        log_activity($pdo, 'Reviewed cancellations', $rn . ' — online booking restored');
        set_flash($rn . ' was reviewed and can book online again. Their cancellations stay on record.');
        header("Location: noshow?tab=cancels"); exit;
    }

    if ($id && $action === 'confirm_noshow') {
        $pdo->prepare("UPDATE appointments SET status='No-show' WHERE id=?")->execute([$id]);

        // Look up the patient so we can email them.
        $q = $pdo->prepare(
            "SELECT a.patient_name, a.appointment_date, a.appointment_time, a.treatment,
                    p.id AS pid, COALESCE(NULLIF(p.email,''), g.email) AS email
               FROM appointments a
          LEFT JOIN patients p ON a.patient_id = p.id
          LEFT JOIN patients g ON g.id = p.guardian_patient_id
              WHERE a.id = ?"
        );
        $q->execute([$id]);
        $row = $q->fetch();

        $note = '';
        if ($row && !empty($row['email']) && mail_is_ready($pdo)) {
            // How many missed visits count against this patient right now?
            // Same shared rule the booking block uses, so the email and the
            // block can never disagree.
            $missed = !empty($row['pid']) ? patient_noshow_count($pdo, $row['pid']) : 0;

            $when = date('F j, Y', strtotime($row['appointment_date'])) . ' at ' . $row['appointment_time'];

            $kind = ($missed >= 3) ? 'noshow_final' : 'noshow_notice';
            $cat  = message_catalogue()[$kind];
            [$subject, $bodyHtml] = tpl_message($pdo, $kind, $cat['subject'], $cat['body'], [
                'patient'   => $row['patient_name'],
                'date'      => date('l, F j, Y', strtotime($row['appointment_date'])),
                'time'      => $row['appointment_time'],
                'treatment' => $row['treatment'],
                'missed'    => $missed,
                'clinic'    => clinic_name($pdo),
            ]);

            $err = '';
            $note = send_mail($pdo, $row['email'], $subject, $bodyHtml, $err, 'noshow_notice')
                  ? ' A notice was emailed to them.'
                  : " (The email could not be sent — $err)";
        }
        log_activity($pdo, 'Confirmed no-show', ($row['patient_name'] ?? ('Appointment #' . $id)));
        set_flash(($row['patient_name'] ?? 'Appointment') . ' marked as a no-show.' . $note);
        header("Location: noshow?tab=review"); exit;
    }

    if ($id && $action === 'mark_attended') {
        // A false alarm — the visit simply was not encoded. Close it quietly.
        $pdo->prepare("UPDATE appointments SET status='Completed' WHERE id=?")->execute([$id]);
        $att = $pdo->prepare("SELECT patient_name FROM appointments WHERE id=?"); $att->execute([$id]);
        log_activity($pdo, 'Marked as attended', $att->fetchColumn() ?: ('Appointment #' . $id));
        set_flash('Marked as attended. No notice was sent and nothing was counted against the patient.', 'info');
        header("Location: noshow?tab=review"); exit;
    }
}

// Clinic name, used on the printed copy of this report.
$cn = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='clinic_name'");
$cn->execute();
$clinicNameForPrint = $cn->fetchColumn() ?: 'St. Therese of Carmel Dental Clinic';

// Which tab is selected? (all / review / noshow / cancelled / repeat)
$tab = $_GET['tab'] ?? 'review';

// ---------- Filters from the toolbar ----------
// A DENTIST is always locked to their own records and gets no dentist picker.
// Admin and staff may filter by any dentist.
$role = current_role();
$isDentistUser = ($role === 'dentist');

// ---------- Report period ----------
// Week (?period=week&w=2026-W39), month (&m=2026-09), day (&d=2026-09-28)
// or all time. Default: this month. Items that still NEED REVIEW are always
// listed, whatever the period, because they are open work for staff.
$period = in_array($_GET['period'] ?? '', ['week','month','day','all'], true) ? $_GET['period'] : 'month';
$validDate = function ($v, $fmt) { $d = DateTimeImmutable::createFromFormat('!' . $fmt, (string)$v); return $d && $d->format($fmt) === $v ? $d : null; };
$today = new DateTimeImmutable('today');
$pickW = preg_match('/^(\d{4})-W(\d{2})$/', $_GET['w'] ?? '', $wm) && (int)$wm[2] >= 1 && (int)$wm[2] <= 53
         ? $_GET['w'] : $today->format('o-\WW');
$pickM = $validDate($_GET['m'] ?? '', 'Y-m') ? $_GET['m'] : $today->format('Y-m');
$pickD = $validDate($_GET['d'] ?? '', 'Y-m-d') ? $_GET['d'] : $today->format('Y-m-d');
$pStart = $pEnd = null;
if ($period === 'week') {
    [$wy, $wn] = array_map('intval', explode('-W', $pickW));
    $pStart = $today->setISODate($wy, $wn);          // Monday
    $pEnd   = $pStart->modify('+6 days');            // Sunday
    $periodLabel = 'Week ' . $wn . ', ' . $wy . ' (' . $pStart->format('M j') . ' – ' . $pEnd->format('M j, Y') . ')';
} elseif ($period === 'month') {
    $pStart = new DateTimeImmutable("$pickM-01");
    $pEnd   = $pStart->modify('last day of this month');
    $periodLabel = $pStart->format('F Y');
} elseif ($period === 'day') {
    $pStart = $pEnd = new DateTimeImmutable($pickD);
    $periodLabel = $pStart->format('l, F j, Y');
} else {
    $periodLabel = 'All time';
}
$pS = $pStart ? $pStart->format('Y-m-d') : null;
$pE = $pEnd ? $pEnd->format('Y-m-d') : null;
// Query-string bits that keep the chosen period when switching tabs / filters.
$periodQS = 'period=' . $period . ($period === 'week' ? '&w=' . urlencode($pickW) : '')
          . ($period === 'month' ? '&m=' . urlencode($pickM) : '') . ($period === 'day' ? '&d=' . urlencode($pickD) : '');

$filterDentist = $isDentistUser ? ($_SESSION['name'] ?? '') : trim($_GET['dentist'] ?? '');
$filterStatus  = trim($_GET['status'] ?? '');
$searchName    = trim($_GET['q'] ?? '');

// Dentists to offer in the picker (admin/staff only).
$dentistOptions = $pdo->query(
    "SELECT name FROM users WHERE role='dentist' AND status <> 'archived' ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);

// ---------- Pull the missed appointments from the database ----------
// These statuses count as "missed" or "needs attention".
$conds  = ["status IN ('Needs Review','No-show','Cancelled','Rescheduled','Expired')"];
$params = [];
if ($pS) {                                   // the period (open reviews always stay listed)
    $conds[] = "(status = 'Needs Review' OR appointment_date BETWEEN ? AND ?)";
    $params[] = $pS; $params[] = $pE;
}

if ($filterDentist !== '') {
    // Match the dentist in either shape the data uses (full or short name).
    $dp = [];
    $conds[] = dentist_match_sql('dentist', $filterDentist, $dp);
    foreach ($dp as $v) $params[] = $v;
}
if (in_array($filterStatus, ['Needs Review','No-show','Cancelled','Rescheduled','Expired'])) {
    $conds[] = "status = ?";
    $params[] = $filterStatus;
}
if ($searchName !== '') {
    $conds[] = "patient_name LIKE ?";
    $params[] = '%' . $searchName . '%';
}

$sql = "SELECT * FROM appointments WHERE " . implode(' AND ', $conds)
     . " ORDER BY appointment_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$missed = $stmt->fetchAll();

// Totals for the period (same dentist scope): every appointment, and the
// ones that were due and resolved (Completed / No-show) for the rate.
$tw = ["1=1"]; $tp = [];
if ($filterDentist !== '') $tw[] = dentist_match_sql('dentist', $filterDentist, $tp);
if ($pS) { $tw[] = "appointment_date BETWEEN ? AND ?"; $tp[] = $pS; $tp[] = $pE; }
$tot = $pdo->prepare("SELECT COUNT(*) AS total,
                             SUM(status = 'Completed') AS completed,
                             SUM(status = 'No-show')   AS noshows
                        FROM appointments WHERE " . implode(' AND ', $tw));
$tot->execute($tp);
$tr = $tot->fetch();
$totalAppts     = (int)$tr['total'];
$completedDue   = (int)$tr['completed'];
$noShowsDue     = (int)$tr['noshows'];

// ---------- Count each type (for the stat cards) ----------
$countNoshow      = 0;
$countCancelled   = 0;
$countRescheduled = 0;
$countReview      = 0;
foreach ($missed as $m) {
    if ($m['status'] === 'No-show')      $countNoshow++;
    if ($m['status'] === 'Cancelled')    $countCancelled++;
    if ($m['status'] === 'Rescheduled')  $countRescheduled++;
    if ($m['status'] === 'Needs Review') $countReview++;
}
// Of the visits that were due and resolved, how many were missed?
// (Future, pending, cancelled and expired bookings were never "due", so
// counting them would make the rate look smaller than it is.)
$noShowRate = ($completedDue + $noShowsDue) > 0 ? round($noShowsDue * 100 / ($completedDue + $noShowsDue), 1) : 0;
$countExpired = count(array_filter($missed, fn($m) => $m['status'] === 'Expired'));

// ---------- Apply the tab filter to the table ----------
$rows = $missed;
if ($tab === 'review')    $rows = array_filter($missed, fn($m) => $m['status'] === 'Needs Review');
if ($tab === 'noshow')    $rows = array_filter($missed, fn($m) => $m['status'] === 'No-show');
if ($tab === 'cancelled') $rows = array_filter($missed, fn($m) => $m['status'] === 'Cancelled');
// "repeat" = the same PATIENT (by record, not by name) missed or cancelled
// more than once themselves. Clinic cancellations are not the patient's fault.
$patientCaused = fn($m) => $m['status'] === 'No-show' || ($m['status'] === 'Cancelled' && ($m['cancelled_by'] ?? '') === 'patient');
$perPatient = [];
foreach ($missed as $m) if ($patientCaused($m) && (int)$m['patient_id'] > 0) $perPatient[(int)$m['patient_id']] = ($perPatient[(int)$m['patient_id']] ?? 0) + 1;
$repeatIds = array_keys(array_filter($perPatient, fn($n) => $n > 1));
if ($tab === 'repeat') {
    $rows = array_filter($missed, fn($m) => $patientCaused($m) && in_array((int)$m['patient_id'], $repeatIds, true));
}
if ($tab === 'expired') $rows = array_filter($missed, fn($m) => $m['status'] === 'Expired');

$page_title = "No-Show Report";
include 'includes/head.php';
$active = 'noshow';

// helper for the status badge colour
function statusBadge($status) {
    $map = ['No-show'=>'b-noshow','Cancelled'=>'b-cancelled','Rescheduled'=>'b-confirmed','Needs Review'=>'b-pending','Expired'=>'b-expired'];
    return $map[$status] ?? 'b-pending';
}
// ---------- Patients paused for missed visits (for the warning banner) ----------
$pausedPatients = [];
$pp = "SELECT id, name FROM patients WHERE status <> 'Archived'"; $ppp = [];
if ($isDentistUser) { $pp .= " AND " . dentist_match_sql('primary_dentist', $filterDentist, $ppp); }
$pps = $pdo->prepare($pp); $pps->execute($ppp);
foreach ($pps->fetchAll() as $pr) if (patient_noshow_count($pdo, $pr['id']) >= 3) $pausedPatients[] = $pr['name'];

// ---------- Frequent cancellations waiting for review ----------
// Patients who cancelled CANCEL_LIMIT+ times themselves (same rolling window,
// counted since their last review). Their online booking is paused until
// an admin or staff member reviews them here.
$cancelReview = [];
$cq = "SELECT p.id, p.name, p.phone, p.primary_dentist, g.name AS guardian_name FROM patients p
        LEFT JOIN patients g ON g.id = p.guardian_patient_id WHERE p.status <> 'Archived'";
$cqp = [];
if ($isDentistUser) { $cq .= " AND " . dentist_match_sql('p.primary_dentist', $filterDentist, $cqp); }
$cqs = $pdo->prepare($cq); $cqs->execute($cqp);
foreach ($cqs->fetchAll() as $cp) {
    $n = patient_cancel_count($pdo, $cp['id']);
    if ($n < CANCEL_LIMIT) continue;
    $lr = $pdo->prepare("SELECT appointment_date, cancel_reason FROM appointments
                          WHERE patient_id = ? AND status='Cancelled' AND cancelled_by='patient'
                          ORDER BY COALESCE(cancelled_at, created_at) DESC LIMIT 3");
    $lr->execute([$cp['id']]);
    $cp['count'] = $n; $cp['recent'] = $lr->fetchAll();
    $cancelReview[] = $cp;
}

// helper for the filter tab links
function tabLink($key, $label, $count, $current) {
    $cls = ($current === $key) ? 'btn-dark-navy' : 'btn-light';
    // Carry the current filters across so switching tabs does not reset them.
    global $periodQS;
    $keep = '&' . $periodQS;
    foreach (['dentist','status','q'] as $p) {
        if (!empty($_GET[$p])) $keep .= '&' . $p . '=' . urlencode($_GET[$p]);
    }
    echo "<a href='noshow?tab=$key$keep' class='btn btn-sm $cls' data-keep-text>$label <span class='badge bg-light text-dark'>$count</span></a> ";
}
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div>
                <h1 style="color:var(--teal-light)">No-Show Report</h1>
                <!-- Only shown on paper, so a printed copy explains itself -->
                <div class="print-only" style="display:none;font-size:.85rem;color:#444;margin-top:4px;">
                    <?= e($clinicNameForPrint) ?> ·
                    <?= $filterDentist !== '' ? 'Dentist: ' . e($filterDentist) : 'All dentists' ?>
                    <?= $filterStatus !== ''  ? ' · Status: ' . e($filterStatus) : '' ?>
                    <?= $searchName !== ''    ? ' · Search: "' . e($searchName) . '"' : '' ?>
                    · <?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?>
                    · Printed <?= date('M j, Y g:i A') ?>
                </div>
                <div class="sub"><?= $role === 'dentist' ? 'Your patients who missed their scheduled appointments' : 'Patients who missed their scheduled appointments (all dentists)' ?></div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-teal" onclick="window.print()" title="Use your browser's print dialog — choose 'Save as PDF' to get a file">
                    🖨 Print / Save as PDF
                </button>
            </div>
        </div>

        <!-- ===== Warning banner (calculated) ===== -->
        <?php if ($pausedPatients): ?>
        <div class="alert" style="background:#fff6e0;border:1px solid var(--gold);color:#8a6d2f;">
            ⚠️ <strong><?= count($pausedPatients) ?> patient<?= count($pausedPatients) === 1 ? ' has' : 's have' ?></strong>
            missed <?= 3 ?> or more visits in the last <?= NOSHOW_WINDOW_MONTHS ?> months — their online booking is paused:
            <strong><?= e(implode(', ', $pausedPatients)) ?></strong>.
            Consider calling them before restoring booking from the Patients page.
        </div>
        <?php endif; ?>

        <!-- ===== Report period ===== -->
        <div class="card-box mb-3" style="background:#f7f4ee;">
            <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between">
                <div style="font-size:.9rem;">
                    <strong>Report period:</strong> <?= e($periodLabel) ?> ·
                    <strong>Generated:</strong> <?= date('F j, Y g:i A') ?> ·
                    <strong>By:</strong> <?= e($_SESSION['name'] ?? 'Admin') ?>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center no-print">
                    <form method="GET" class="d-flex gap-1 align-items-center m-0">
                        <input type="hidden" name="tab" value="<?= e($tab) ?>"><input type="hidden" name="period" value="week">
                        <label class="field-label mb-0 <?= $period === 'week' ? 'text-dark' : '' ?>" for="p-w">Week</label>
                        <input type="week" id="p-w" name="w" class="form-control form-control-sm" style="width:auto;" value="<?= e($pickW) ?>" onchange="this.form.submit()">
                    </form>
                    <form method="GET" class="d-flex gap-1 align-items-center m-0">
                        <input type="hidden" name="tab" value="<?= e($tab) ?>"><input type="hidden" name="period" value="month">
                        <label class="field-label mb-0" for="p-m">Month</label>
                        <input type="month" id="p-m" name="m" class="form-control form-control-sm" style="width:auto;" value="<?= e($pickM) ?>" onchange="this.form.submit()">
                    </form>
                    <form method="GET" class="d-flex gap-1 align-items-center m-0">
                        <input type="hidden" name="tab" value="<?= e($tab) ?>"><input type="hidden" name="period" value="day">
                        <label class="field-label mb-0" for="p-d">Day</label>
                        <input type="date" id="p-d" name="d" class="form-control form-control-sm" style="width:auto;" value="<?= e($pickD) ?>" onchange="this.form.submit()">
                    </form>
                    <a href="noshow?tab=<?= e($tab) ?>&period=all" class="btn btn-sm <?= $period === 'all' ? 'btn-dark-navy' : 'btn-light' ?>">All time</a>
                </div>
            </div>
            <div class="text-muted2 mt-1" style="font-size:.78rem;">Appointments that still need review are always listed, whatever the period.</div>
        </div>

        <!-- ===== Filters (these actually work) ===== -->
        <div class="card-box mb-3 no-print">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="tab" value="<?= e($tab) ?>">
                <input type="hidden" name="period" value="<?= e($period) ?>">
                <?php if ($period === 'week'): ?><input type="hidden" name="w" value="<?= e($pickW) ?>"><?php endif; ?>
                <?php if ($period === 'month'): ?><input type="hidden" name="m" value="<?= e($pickM) ?>"><?php endif; ?>
                <?php if ($period === 'day'): ?><input type="hidden" name="d" value="<?= e($pickD) ?>"><?php endif; ?>

                <?php if (!$isDentistUser): ?>
                    <!-- Only admin and staff choose a dentist. A dentist is always
                         locked to their own records, so no picker is shown. -->
                    <div class="col-md-3">
                        <label class="field-label">Dentist</label>
                        <select name="dentist" class="form-select form-select-sm">
                            <option value="">All dentists</option>
                            <?php foreach ($dentistOptions as $dn): ?>
                                <option value="<?= e($dn) ?>" <?= ($filterDentist === $dn)?'selected':'' ?>><?= e($dn) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="col-md-3">
                    <label class="field-label">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All statuses</option>
                        <?php foreach (['Needs Review','No-show','Cancelled','Rescheduled','Expired'] as $st): ?>
                            <option value="<?= $st ?>" <?= ($filterStatus === $st)?'selected':'' ?>><?= $st ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-<?= $isDentistUser ? '6' : '4' ?>">
                    <label class="field-label">Search Patient</label>
                    <input name="q" class="form-control form-control-sm"
                           placeholder="Patient name..." value="<?= e($searchName) ?>">
                </div>

                <div class="col-md-2 d-flex gap-1">
                    <button class="btn btn-teal btn-sm w-100">Apply</button>
                    <?php if ($filterDentist !== '' && !$isDentistUser || $filterStatus !== '' || $searchName !== ''): ?>
                        <a href="noshow?tab=<?= e($tab) ?>&<?= e($periodQS) ?>" class="btn btn-light btn-sm">Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- ===== Stat cards ===== -->
        <div class="stat-grid stat-scroll" style="grid-template-columns:repeat(5,1fr);">
            <div class="stat-card"><div class="value">📅 <?= $totalAppts ?></div><div class="label">Total Appts</div></div>
            <div class="stat-card"><div class="value" style="color:#c0392b;">🚫 <?= $countNoshow ?></div><div class="label">No-Shows</div></div>
            <div class="stat-card"><div class="value">❌ <?= $countCancelled ?></div><div class="label">Cancelled</div></div>
            <div class="stat-card"><div class="value">🔁 <?= $countRescheduled ?></div><div class="label">Rescheduled</div></div>
            <div class="stat-card" title="No-shows ÷ (completed + no-shows) in this period"><div class="value" style="color:#c0392b;"><?= $noShowRate ?>%</div><div class="label">No-Show Rate</div>
                <div class="change"><?= $noShowsDue ?> of <?= $completedDue + $noShowsDue ?> due visits</div></div>
        </div>

        <div class="row g-3">
            <!-- ===== Table + tabs ===== -->
            <div class="col-lg-9">
                <div class="mb-2">
                    <?php
                        tabLink('review',    'Needs Review',    $countReview,           $tab);
                        tabLink('all',       'All Missed',      count($missed),         $tab);
                        tabLink('noshow',    'No-Shows',        $countNoshow,           $tab);
                        tabLink('cancelled', 'Cancelled',       $countCancelled,        $tab);
                        tabLink('repeat',    'Repeat Offenders', count($repeatIds),     $tab);
                        tabLink('expired',   'Expired (never confirmed)', $countExpired, $tab);
                        tabLink('cancels',   'Frequent Cancellations', count($cancelReview), $tab);
                    ?>
                </div>
                <?php if ($tab === 'cancels'): ?>
                <!-- ===== Frequent cancellations: one row per patient ===== -->
                <div class="card-box">
                    <div class="text-muted2 mb-2" style="font-size:.85rem;">
                        Patients who cancelled <?= CANCEL_LIMIT ?> or more appointments themselves in the last
                        <?= NOSHOW_WINDOW_MONTHS ?> months. Their online booking is <b>paused</b> until you review them.
                    </div>
                    <div class="table-responsive">
                        <table class="data">
                            <thead><tr><th>Patient</th><th>Cancellations</th><th>Recent cancellations (reason)</th><th>Dentist</th><th class="no-print">Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($cancelReview as $cp): ?>
                                <tr>
                                    <td><strong><?= e($cp['name']) ?></strong>
                                        <?php if ($cp['guardian_name']): ?><br><small class="text-muted2">booked by <?= e($cp['guardian_name']) ?></small><?php endif; ?>
                                        <?php if ($cp['phone']): ?><br><small class="text-muted2">📞 <?= e($cp['phone']) ?></small><?php endif; ?></td>
                                    <td><span class="badge-pill b-cancelled"><?= (int)$cp['count'] ?> cancelled</span></td>
                                    <td style="font-size:.82rem;">
                                        <?php foreach ($cp['recent'] as $rc): ?>
                                            <div><?= date('M j, Y', strtotime($rc['appointment_date'])) ?>
                                                <span class="text-muted2">— <?= e($rc['cancel_reason'] ?: 'no reason given') ?></span></div>
                                        <?php endforeach; ?>
                                    </td>
                                    <td><?= e($cp['primary_dentist'] ?: 'N/A') ?></td>
                                    <td class="no-print">
                                        <?php if (in_array(current_role(), ['admin','staff'], true)): ?>
                                            <form method="POST" class="m-0" onsubmit="return confirm('Mark <?= e(addslashes($cp['name'])) ?> as reviewed and let them book online again?')">
                                                <input type="hidden" name="action" value="restore_cancel">
                                                <input type="hidden" name="id" value="<?= (int)$cp['id'] ?>">
                                                <button class="btn btn-sm btn-teal">✓ Reviewed — restore booking</button>
                                            </form>
                                        <?php else: ?>
                                            <small class="text-muted2">Admin/staff review</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$cancelReview): ?>
                                <tr><td colspan="5" style="text-align:center;padding:34px 12px;color:#8aa0a0;">
                                    <div style="font-size:2rem;margin-bottom:6px;">✅</div>
                                    No patient needs a cancellation review right now.
                                </td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="card-box">
                    <div class="table-responsive">
                        <table class="data">
                            <thead><tr><th>Patient</th><th>Date</th><th>Time</th><th>Dentist</th><th>Procedure</th><th>Status</th><th class="no-print">Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $r):
                                $initials = strtoupper(substr($r['patient_name'],0,1) . (strpos($r['patient_name'],' ') ? substr(strstr($r['patient_name'],' '),1,1) : ''));
                            ?>
                                <tr>
                                    <td><div class="d-flex align-items-center gap-2">
                                        <span class="avatar" style="background:#c98b8b;"><?= e($initials) ?></span>
                                        <strong><?= e($r['patient_name']) ?></strong>
                                    </div></td>
                                    <td><?= date('M j', strtotime($r['appointment_date'])) ?></td>
                                    <td><?= e($r['appointment_time']) ?></td>
                                    <td><?= e($r['dentist']) ?></td>
                                    <td><?= e($r['treatment']) ?></td>
                                    <td><span class="badge-pill <?= statusBadge(status_label($r['status'])) ?>"><?= e(status_label($r['status'])) ?></span></td>
                                    <td class="no-print">
                                        <?php if ($r['status'] === 'Needs Review'): ?>
                                            <div class="d-flex gap-1 flex-wrap">
                                                <form method="POST" class="m-0"
                                                      onsubmit="return confirm('Confirm that <?= e($r['patient_name']) ?> did NOT attend? They will be emailed a notice.')">
                                                    <input type="hidden" name="action" value="confirm_noshow">
                                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                                    <button class="btn btn-sm btn-light" style="color:#c0392b;white-space:nowrap;">Confirm no-show</button>
                                                </form>
                                                <form method="POST" class="m-0">
                                                    <input type="hidden" name="action" value="mark_attended">
                                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                                    <button class="btn btn-sm btn-light" style="color:#1f8a54;white-space:nowrap;">Did attend</button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted2" style="font-size:.8rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="7" style="text-align:center;padding:34px 12px;color:#8aa0a0;">
                                    <div style="font-size:2rem;margin-bottom:6px;">📋</div>
                                    <?php if ($filterStatus !== '' || $searchName !== '' || (!$isDentistUser && $filterDentist !== '')): ?>
                                        Nothing matches your filters.
                                        <a href="noshow?tab=<?= e($tab) ?>" style="color:var(--teal);">Clear filters</a>
                                    <?php elseif ($tab === 'review'): ?>
                                        Nothing needs reviewing — every past appointment is accounted for.
                                    <?php else: ?>
                                        No records in this category.
                                    <?php endif; ?>
                                </td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ===== Status breakdown ===== -->
            <div class="col-lg-3">
                <div class="card-box">
                    <h6 class="mb-3">Status Breakdown</h6>
                    <div class="flex-between py-2 border-bottom"><span>🔴 No-show</span><strong><?= $countNoshow ?></strong></div>
                    <div class="flex-between py-2 border-bottom"><span>🟠 Cancelled</span><strong><?= $countCancelled ?></strong></div>
                    <div class="flex-between py-2 border-bottom"><span>🔵 Late Cancel</span><strong>2</strong></div>
                    <div class="flex-between py-2"><span>🟢 Rescheduled</span><strong><?= $countRescheduled ?></strong></div>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
</body></html>
