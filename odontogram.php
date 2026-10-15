<?php
// ============================================================
//  ODONTOGRAM  (odontogram.php) -- interactive dental chart
// ============================================================
//  A patient now has ONE CHART PER VISIT ("chart sessions").
//  The first visit's chart stays saved and editable, and you can add
//  a new chart for the next session, so you can see the patient's
//  progress over time.
// ============================================================
require_once 'config/auth.php';
require_login(['admin','dentist']);   // clinical charting - not front-desk staff
require_once 'includes/teeth.php';
require_once 'includes/clinical.php';   // allergy / medical alert banner

// Helper: after a change, go back to the same patient + visit.
function odo_back($pid, $sid) {
    header("Location: odontogram?patient=$pid&session=$sid");
    exit;
}

// If a DENTIST charts a patient who has no primary dentist yet, they become it.
// (A dentist covering for a colleague does NOT take the patient over.)
function touch_primary_dentist($pdo, $pid) {
    if (current_role() === 'dentist') {
        $pdo->prepare("UPDATE patients SET primary_dentist=? WHERE id=? AND (primary_dentist IS NULL OR primary_dentist = '')")
            ->execute([$_SESSION['name'] ?? '', $pid]);
    }
}

// Save the teeth the dentist changed. They are sent TOGETHER when the dentist
// clicks "Done" (no page reload after every tooth).
//   $json: {"16": {"status": "Decayed", "other": "", "notes": "..."}, ...}
// Returns [visit id, ["#16 Decayed", ...]]. A first visit is made if there is none.
function odo_save_teeth($pdo, $pid, $sid, $json) {
    global $UPPER_TEETH, $LOWER_TEETH, $TOOTH_STATUSES;
    $data = json_decode((string)$json, true);
    if (!is_array($data) || !$data) return [$sid, []];
    $valid = array_merge($UPPER_TEETH, $LOWER_TEETH);
    $done  = [];
    foreach ($data as $tooth => $t) {
        $tooth = (string)$tooth;
        if (!in_array($tooth, $valid, true) || !is_array($t) || !in_array($t['status'] ?? '', $TOOTH_STATUSES, true)) continue;
        $status = $t['status'];
        $other  = $status === 'Other' ? mb_substr(trim((string)($t['other'] ?? '')), 0, 100) : '';
        $notes  = mb_substr(trim((string)($t['notes'] ?? '')), 0, 255);
        if (!$sid) {
            // First time this patient is charted: this is their first visit (today).
            $pdo->prepare("INSERT INTO chart_sessions (patient_id,visit_date,title,created_by) VALUES (?,CURDATE(),'Initial Chart',?)")
                ->execute([$pid, $_SESSION['name'] ?? '']);
            $sid = (int)$pdo->lastInsertId();
            log_activity($pdo, 'Added visit chart', patient_name_of($pdo, $pid) . ' — first visit');
        }
        // Does this tooth already have a row in THIS visit? Update it, else insert.
        $check = $pdo->prepare("SELECT id FROM odontogram WHERE patient_id=? AND session_id=? AND tooth_number=?");
        $check->execute([$pid, $sid, $tooth]);
        if ($row = $check->fetch()) {
            $pdo->prepare("UPDATE odontogram SET tooth_status=?, condition_other=?, notes=? WHERE id=?")
                ->execute([$status, $other ?: null, $notes ?: null, $row['id']]);
        } else {
            $pdo->prepare("INSERT INTO odontogram (patient_id,session_id,tooth_number,tooth_status,condition_other,notes) VALUES (?,?,?,?,?,?)")
                ->execute([$pid, $sid, $tooth, $status, $other ?: null, $notes ?: null]);
        }
        $done[] = "#$tooth " . tooth_condition_label($status, $other) . ($notes !== '' ? ' (note)' : '');
    }
    if ($done) {
        touch_primary_dentist($pdo, $pid);
        complete_arrived_visit($pdo, $pid);
        log_activity($pdo, 'Updated dental chart', patient_name_of($pdo, $pid) . ' — ' . implode(', ', $done));
    }
    return [$sid, $done];
}

// Patients this user may chart: only real patients, and a DENTIST only
// their own assigned ones. Used to reject a tampered ?patient= / patient_id.
$odoSql = "SELECT p.id, p.name FROM patients p
           LEFT JOIN users u ON p.user_id = u.id
           WHERE (u.id IS NULL OR u.role = 'patient')";
$odoPrm = [];
if (current_role() === 'dentist') { $odoSql .= " AND " . dentist_patient_sql('p.id', 'p.primary_dentist', $_SESSION['name'] ?? '', $odoPrm); }
$odoSql .= " ORDER BY p.name";
$odoStmt = $pdo->prepare($odoSql); $odoStmt->execute($odoPrm);
$patients = $odoStmt->fetchAll();
$allowedPids = array_map('intval', array_column($patients, 'id'));

// ============================================================
//  ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pid    = (int)($_POST['patient_id'] ?? 0);
    if (!in_array($pid, $allowedPids, true)) {
        deny_access("Changed dental chart of patient #$pid");
    }
    // Only the DENTIST changes a dental chart. The admin can look, not edit.
    if (current_role() !== 'dentist') {
        set_flash('Only the dentist can change a dental chart. The admin can view it.', 'error');
        header("Location: odontogram?patient=$pid"); exit;
    }

    // Only the LATEST visit's chart can be changed. Past visits are a record of
    // how the teeth were at that time, so they are view only.
    $latestQ = $pdo->prepare("SELECT id FROM chart_sessions WHERE patient_id = ? AND archived_at IS NULL
                               ORDER BY visit_date DESC, id DESC LIMIT 1");
    $latestQ->execute([$pid]);
    $latestSid = (int)$latestQ->fetchColumn();
    if (in_array($action, ['save_teeth', 'save_session'], true)) {
        $postSid = (int)($_POST['session_id'] ?? 0);
        if ($postSid && $postSid !== $latestSid) {
            set_flash('That is a past visit, so its chart cannot be changed. Make changes in the latest visit, or start a + New Chart.', 'error');
            odo_back($pid, $postSid);
        }
    }

    // ---------- Save the changed teeth (all at once, when "Done" is clicked) ----------
    if ($action === 'save_teeth') {
        [$sid, $done] = odo_save_teeth($pdo, $pid, (int)($_POST['session_id'] ?? 0), $_POST['teeth'] ?? '');
        if ($done) set_flash(count($done) === 1 ? 'Saved: tooth ' . $done[0] . '.' : count($done) . ' teeth saved.');
        odo_back($pid, $sid);
    }

    // ---------- Add a NEW chart for a new visit ----------
    if ($action === 'new_session') {
        $visitDate = $_POST['visit_date'] ?: date('Y-m-d');
        $title     = trim($_POST['title'] ?? '') ?: 'Follow-up Visit';
        $copyFrom  = (int)($_POST['copy_from'] ?? 0);   // 0 = start blank

        $pdo->prepare("INSERT INTO chart_sessions (patient_id,visit_date,title,created_by) VALUES (?,?,?,?)")
            ->execute([$pid, $visitDate, $title, $_SESSION['name'] ?? '']);
        $newId = (int)$pdo->lastInsertId();

        // Copying the last chart means the dentist only has to mark what CHANGED
        // since the previous visit, instead of re-entering all 32 teeth.
        if ($copyFrom) {
            $pdo->prepare(
                "INSERT INTO odontogram (patient_id, session_id, tooth_number, tooth_status, condition_other)
                 SELECT patient_id, ?, tooth_number, tooth_status, condition_other
                 FROM odontogram WHERE patient_id=? AND session_id=?"
            )->execute([$newId, $pid, $copyFrom]);
        }

        touch_primary_dentist($pdo, $pid);
        complete_arrived_visit($pdo, $pid, $visitDate);
        log_activity($pdo, 'Added visit chart', patient_name_of($pdo, $pid) . ' — ' . $visitDate . ($title ? ' · ' . $title : ''));
        set_flash($copyFrom
            ? 'New chart added, carried over from the previous visit. Mark only what changed.'
            : 'New blank chart added for this visit.');
        odo_back($pid, $newId);
    }

    // ---------- Save the visit details (date, label, notes) ----------
    if ($action === 'save_session') {
        $sid = (int)$_POST['session_id'];
        $pdo->prepare("UPDATE chart_sessions SET visit_date=?, title=?, notes=? WHERE id=? AND patient_id=?")
            ->execute([$_POST['visit_date'], trim($_POST['title']), trim($_POST['notes']), $sid, $pid]);
        touch_primary_dentist($pdo, $pid);
        log_activity($pdo, 'Edited visit chart', patient_name_of($pdo, $pid));
        [, $done] = odo_save_teeth($pdo, $pid, $sid, $_POST['teeth'] ?? '');   // teeth changed but not saved yet
        set_flash('Visit details saved.' . ($done ? ' ' . (count($done) === 1 ? '1 tooth' : count($done) . ' teeth') . ' saved too.' : ''));
        odo_back($pid, $sid);
    }

    // ---------- Delete a visit chart → moves it to the Archive ----------
    // One visit, or several ticked ones — but the patient always keeps at least one
    // chart. Nothing is erased: the admin can restore it (or delete it for good)
    // from the Archive page.
    if ($action === 'delete_session') {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM chart_sessions WHERE patient_id=? AND archived_at IS NULL");
        $cnt->execute([$pid]);
        $left = (int)$cnt->fetchColumn();
        $done = 0;
        foreach (bulk_ids('session_id') as $sid) {
            if ($left <= 1) break;
            $arc = $pdo->prepare("UPDATE chart_sessions SET archived_at = NOW(), archived_by = ?
                                   WHERE id=? AND patient_id=? AND archived_at IS NULL");
            $arc->execute([$_SESSION['name'] ?? '', $sid, $pid]);
            if (!$arc->rowCount()) continue;
            $left--; $done++;
        }
        if ($done) log_activity($pdo, 'Archived visit chart', patient_name_of($pdo, $pid) . ($done > 1 ? " ($done visits)" : ''));
        set_flash($done === 1 ? 'That visit chart was moved to the Archive.'
                 : ($done ? "$done visit charts were moved to the Archive." : 'A patient must keep at least one visit chart.'),
                  $done ? 'info' : 'error');
        header("Location: odontogram?patient=$pid"); exit;
    }
}

// ============================================================
//  LOAD THE PAGE
// ============================================================
// ($patients / $allowedPids were loaded above, before the actions.)
$pid = (int)($_GET['patient'] ?? ($patients[0]['id'] ?? 0));
if ($pid && !in_array($pid, $allowedPids, true)) {
    deny_access("Dental chart of patient #$pid");
}

// No visit is created just by opening the page: a patient who has not been
// seen yet has an EMPTY chart history. The first visit is made when the
// dentist starts it ("+ Start first visit") or marks the first tooth.

// All of this patient's visits (newest first).
$sessions = $pid ? get_chart_sessions($pdo, $pid) : [];
$canEditChart = (current_role() === 'dentist');   // the admin can view, only the dentist edits

// Which visit are we looking at? Default = the newest one.
$sid = (int)($_GET['session'] ?? 0);
$validIds = array_map('intval', array_column($sessions, 'id'));
if (!$sid || !in_array($sid, $validIds)) $sid = $validIds[0] ?? 0;

$session = null;
foreach ($sessions as $s) if ((int)$s['id'] === $sid) $session = $s;

// Only the latest visit (or a patient with no visit yet) can be edited.
$isLatestVisit = !$sessions || $sid === ($validIds[0] ?? 0);
$canEditVisit  = $canEditChart && $isLatestVisit;

// The chart we are editing (+ each tooth's typed condition and note).
$toothMap  = $pid ? build_tooth_map($pdo, $pid, $sid) : [];
$toothInfo = tooth_details($pdo, $pid, $sid);

// The chart from the visit BEFORE this one, so we can show what changed.
// $sessions is newest-first, so the previous visit is the NEXT item in the list.
$prevSession = null;
foreach ($sessions as $i => $s) {
    if ((int)$s['id'] === $sid) { $prevSession = $sessions[$i + 1] ?? null; break; }
}
$prevMap = $prevSession ? build_tooth_map($pdo, $pid, (int)$prevSession['id']) : [];

// Work out the differences between this visit and the previous one.
$changes = [];
if ($prevSession) {
    foreach (array_merge($UPPER_TEETH, $LOWER_TEETH) as $t) {
        $was = $prevMap[$t]  ?? 'Healthy';
        $now = $toothMap[$t] ?? 'Healthy';
        if ($was !== $now) $changes[$t] = [$was, $now];
    }
}

// Count the teeth in each condition (for the summary panel).
$counts = ['Healthy'=>0,'Decayed'=>0,'Filled'=>0,'Crowned'=>0,'Missing'=>0,'Extracted'=>0,'Impacted'=>0,'Fractured'=>0,'Other'=>0];
foreach (array_merge($UPPER_TEETH, $LOWER_TEETH) as $t) {
    $st = $toothMap[$t] ?? 'Healthy';
    $counts[$st] = ($counts[$st] ?? 0) + 1;
}

$legendColors = ['Healthy'=>'#6bbf6b','Decayed'=>'#e0a92e','Filled'=>'#3b9ae0','Missing'=>'#e05b5b',
                 'Crowned'=>'#a06fd6','Extracted'=>'#999','Impacted'=>'#d99a00','Fractured'=>'#cc4444','Other'=>'#d6589c'];

// "Improved" = a bad tooth became good (e.g. Decayed -> Filled). Nice to show.
$improved = 0; $worsened = 0;
$badList = ['Decayed','Fractured','Impacted'];
foreach ($changes as $ch) {
    $was = $ch[0]; $now = $ch[1];
    if (in_array($was, $badList) && !in_array($now, $badList)) $improved++;
    if (!in_array($was, $badList) && in_array($now, $badList)) $worsened++;
}

// ---- For the printed / PDF chart ----
$printPatient = null;
if ($pid) { $pp = $pdo->prepare("SELECT name, age, date_of_birth, primary_dentist FROM patients WHERE id=?"); $pp->execute([$pid]); $printPatient = $pp->fetch(); }
$printClinic = [];
foreach ($pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('clinic_name','land_contact_address','clinic_address','land_contact_phone','clinic_phone')") as $r) $printClinic[$r['setting_key']] = trim((string)$r['setting_value']);
$printFindings = array_filter($toothMap, fn($st) => $st !== 'Healthy');   // teeth that are not healthy
ksort($printFindings);

$page_title = "Odontogram";
include 'includes/head.php';
$active = 'odontogram';
?>

<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <!-- ===== Only on paper / PDF: clinic letterhead + whose chart this is ===== -->
        <div class="print-only odo-print-head">
            <div class="d-flex justify-content-between align-items-end">
                <div>
                    <div style="font-size:18px;font-weight:700;color:#0d3b3b;"><?= e($printClinic['clinic_name'] ?? 'St. Therese Dental Clinic') ?></div>
                    <div style="font-size:11px;color:#555;"><?= e(($printClinic['land_contact_address'] ?? '') ?: ($printClinic['clinic_address'] ?? '')) ?>
                        · <?= e(($printClinic['land_contact_phone'] ?? '') ?: ($printClinic['clinic_phone'] ?? '')) ?></div>
                </div>
                <div style="text-align:right;font-size:11px;color:#555;"><strong style="font-size:14px;color:#0d3b3b;">Dental Chart (Odontogram)</strong><br>Printed <?= date('M j, Y g:i A') ?></div>
            </div>
            <table class="odo-print-info">
                <tr><td><b>Patient:</b> <?= e($printPatient['name'] ?? '–') ?></td>
                    <td><b>Age:</b> <?= !empty($printPatient['age']) ? e($printPatient['age']) : '–' ?></td>
                    <td><b>Date of birth:</b> <?= !empty($printPatient['date_of_birth']) ? date('M j, Y', strtotime($printPatient['date_of_birth'])) : '–' ?></td></tr>
                <tr><td><b>Visit:</b> <?= $session ? date('M j, Y', strtotime($session['visit_date'])) . ($session['title'] ? ' · ' . e($session['title']) : '') : '–' ?></td>
                    <td colspan="2"><b>Dentist:</b> <?= e(($printPatient['primary_dentist'] ?? '') ?: '–') ?></td></tr>
            </table>
        </div>

        <div class="page-head no-print">
            <div><h1>Odontogram</h1><div class="sub">One dental chart per visit — track the patient's progress</div></div>
            <div class="clock"><span class="time" id="clock"></span><br><span id="clock-date"></span></div>
        </div>

        <div class="row" id="odo-row">
            <div class="col-lg-12" id="odo-left-col">
                <div class="card-box">
                    <div class="flex-between">
                        <h5 class="mb-0">Dental Chart
                            <small class="text-muted2 d-block no-print" style="font-size:.75rem;"><?= $canEditVisit ? 'Click a tooth to set its condition and note — then ✔ Done saves them all' : ($canEditChart ? 'Past visit — view only' : 'View only — only the dentist can change this chart') ?></small>
                        </h5>
                        <div class="d-flex gap-2">
                            <?php if ($canEditVisit): ?>
                            <button type="button" id="odo-edit-btn" class="btn btn-outline-teal btn-sm" onclick="toggleOdoEdit(true)">✏️ Edit Chart</button>
                            <!-- Saves every tooth changed since "Edit Chart" in one go -->
                            <button type="button" id="odo-done-btn" class="btn btn-teal btn-sm" style="display:none;white-space:nowrap;" onclick="odoDone()">✔ Done<span id="odo-done-count"></span></button>
                            <?php endif; ?>
                            <!-- Print this chart, or download it as a PDF file -->
                            <?= print_menu('main', pdf_name('Dental-Chart', $printPatient['name'] ?? '', isset($session['visit_date']) ? $session['visit_date'] : date('Y-m-d')), 'window.print()', 'btn-gold btn-sm') ?>
                        </div>
                    </div>

                    <!-- Choose which patient -->
                    <form method="GET" class="mt-3 no-print">
                        <label class="field-label">Patient</label>
                        <select name="patient" class="form-select" style="max-width:280px;" onchange="this.form.submit()" data-search="Search patient…">
                            <?php foreach ($patients as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $p['id']==$pid?'selected':'' ?>><?= e($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php // ⚠️ Allergies / medical alert — check before treating
                          if ($pid) { $alq = $pdo->prepare("SELECT allergies, medical_alert FROM patients WHERE id = ?"); $alq->execute([$pid]);
                                      $alRow = $alq->fetch(); if ($alRow && ($alB = patient_alert_banner($alRow))) echo '<div class="mt-2">' . clinical_styles() . $alB . '</div>'; } ?>

                    <!-- ===== VISIT TABS (the chart history) ===== -->
                    <label class="field-label mt-3 no-print">Visits</label>
                    <?php if (!$sessions): ?>
                        <div class="no-print" style="background:#f7fafa;border:1px dashed #cfe0dd;border-radius:10px;padding:12px 14px;font-size:.9rem;color:#52606b;margin-bottom:8px;">
                            📭 <b>No visits yet.</b> This patient has not been charted, so the chart is empty.
                            <?= $canEditChart ? 'Start the first visit when you see the patient — or just click a tooth and the first visit (today) is created.' : 'The dentist starts it at the first visit.' ?>
                        </div>
                    <?php endif; ?>
                    <div class="sess-tabs no-print">
                        <?php $oldestFirst = array_reverse($sessions); ?>
                        <?php foreach ($oldestFirst as $i => $s): ?>
                            <a class="sess-tab <?= (int)$s['id']===$sid?'on':'' ?>"
                               href="odontogram?patient=<?= $pid ?>&session=<?= $s['id'] ?>">
                                <b>Visit <?= $i + 1 ?><?= $i === count($oldestFirst)-1 ? ' · latest' : '' ?></b>
                                <small><?= date('M j, Y', strtotime($s['visit_date'])) ?><?= $s['title'] ? ' · '.e($s['title']) : '' ?></small>
                            </a>
                        <?php endforeach; ?>

                        <?php if ($canEditChart): ?>
                        <button class="btn btn-teal btn-sm" data-bs-toggle="modal" data-bs-target="#newSessionModal" title="Add a chart for a new visit" data-keep-text>
                            <?= $sessions ? '+ New Chart' : '+ Start first visit' ?>
                        </button>
                        <?php if (count($sessions) > 1 && $session && $isLatestVisit): ?>
                            <!-- Move the visit being viewed to the Archive (at least one visit always stays) -->
                            <form method="POST" class="d-inline m-0"
                                  onsubmit="return confirm('Move this visit (<?= e(date('M j, Y', strtotime($session['visit_date']))) ?>) to the Archive? It disappears from the chart history, but the admin can restore it from the Archive.')">
                                <input type="hidden" name="action" value="delete_session">
                                <input type="hidden" name="patient_id" value="<?= $pid ?>">
                                <input type="hidden" name="session_id" value="<?= $sid ?>">
                                <button class="btn btn-sm" style="background:#fbdcdc;color:#c0392b;" title="Delete this visit (moves it to the Archive)" data-keep-text>🗄 Archive this visit</button>
                            </form>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted2 mb-3 no-print" style="font-size:.78rem;">
                        Each visit keeps its own chart. Only the latest visit can be changed — past visits stay exactly as they were.
                    </div>
                    <?php if ($canEditChart && !$isLatestVisit && $sessions): ?>
                        <div class="no-print mb-3" style="background:#f7fafa;border:1px solid #cfe0dd;border-left:4px solid #8aa0a0;border-radius:10px;padding:10px 14px;font-size:.88rem;color:#3f5350;">
                            🔒 <b>Past visit — view only.</b> This is how the teeth were on <?= date('M j, Y', strtotime($session['visit_date'])) ?>.
                            To record changes, go to the <a href="odontogram?patient=<?= $pid ?>&session=<?= (int)$validIds[0] ?>">latest visit</a> or start a <b>+ New Chart</b>.
                        </div>
                    <?php endif; ?>

                    <?php if ($canEditVisit): ?>
                        <div id="odo-pending" class="no-print mb-2" hidden
                             style="background:#fff8e8;border-left:4px solid var(--gold);border-radius:8px;padding:8px 12px;font-size:.88rem;">
                            <span id="odo-pending-text"></span>
                            <button type="button" class="btn btn-sm btn-light ms-2" onclick="odoUndo()" data-keep-text>Undo changes</button>
                        </div>
                    <?php endif; ?>
                    <!-- UPPER ARCH -->
                    <div class="text-center mb-1"><span class="odo-section-label">Upper (Maxillary)</span></div>
                    <div class="odo-arch">
                        <?php foreach ($UPPER_TEETH as $t) echo render_tooth($t, $toothMap[$t] ?? 'Healthy', $toothInfo[$t] ?? []); ?>
                    </div>

                    <div class="odo-midline">— MIDLINE —</div>

                    <!-- LOWER ARCH -->
                    <div class="odo-arch">
                        <?php foreach ($LOWER_TEETH as $t) echo render_tooth($t, $toothMap[$t] ?? 'Healthy', $toothInfo[$t] ?? []); ?>
                    </div>
                    <div class="text-center mt-1"><span class="odo-section-label">Lower (Mandibular)</span></div>

                    <!-- Legend -->
                    <div class="odo-legend">
                        <?php foreach ($TOOTH_STATUSES as $st): ?>
                            <span><i class="legend-dot" style="background:<?= $legendColors[$st] ?>"></i><?= $st ?></span>
                        <?php endforeach; ?>
                    </div>

                    <?php $notedTeeth = array_filter(array_merge($UPPER_TEETH, $LOWER_TEETH), fn($t) => trim($toothInfo[$t]['notes'] ?? '') !== ''); ?>
                    <?php if ($notedTeeth): ?>
                        <!-- The dentist's note on each tooth (clinic only — not shown in the patient portal) -->
                        <div class="mt-3" style="border-top:1px dashed #d9e3e1;padding-top:10px;">
                            <div class="field-label mb-1">📝 Tooth notes</div>
                            <?php foreach ($notedTeeth as $t): $ti = $toothInfo[$t]; ?>
                                <div class="d-flex flex-wrap align-items-center gap-2 py-1" style="font-size:.9rem;">
                                    <b>#<?= e($t) ?></b>
                                    <span class="pill-sm" style="background:<?= $legendColors[$ti['status']] ?? '#999' ?>;color:#fff;"><?= e(tooth_condition_label($ti['status'], $ti['other'])) ?></span>
                                    <span><?= e($ti['notes']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Only on paper / PDF: the teeth that need attention, and the visit notes -->
                    <div class="print-only odo-print-findings">
                        <b>Findings on this visit:</b>
                        <?php if ($printFindings): ?>
                            <?php foreach ($printFindings as $tn => $st): ?>
                                <span class="pill-sm" style="background:<?= $legendColors[$st] ?? '#999' ?>;color:#fff;">#<?= e($tn) ?> <?= e(tooth_condition_label($st, $toothInfo[$tn]['other'] ?? '')) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            All teeth healthy.
                        <?php endif; ?>
                        <?php if ($session && trim($session['notes'] ?? '') !== ''): ?>
                            <div class="mt-2"><b>Visit notes:</b> <?= nl2br(e($session['notes'])) ?></div>
                        <?php endif; ?>
                        <div class="odo-print-sign">Dentist's signature: ______________________________</div>
                    </div>
                </div>

                <!-- ===== PROGRESS: what changed since the previous visit ===== -->
                <?php if ($prevSession): ?>
                <div class="card-box">
                    <h5 class="mb-1">📈 Progress since the previous visit</h5>
                    <div class="text-muted2 mb-3" style="font-size:.83rem;">
                        Comparing <strong><?= date('M j, Y', strtotime($session['visit_date'])) ?></strong>
                        with <strong><?= date('M j, Y', strtotime($prevSession['visit_date'])) ?></strong>.
                    </div>

                    <?php if (empty($changes)): ?>
                        <p class="text-muted2 mb-0">No teeth have changed since the previous visit.</p>
                    <?php else: ?>
                        <div class="d-flex gap-2 mb-3 flex-wrap">
                            <?php if ($improved): ?>
                                <span class="badge-pill b-completed">✅ <?= $improved ?> improved</span>
                            <?php endif; ?>
                            <?php if ($worsened): ?>
                                <span class="badge-pill b-cancelled">⚠️ <?= $worsened ?> got worse</span>
                            <?php endif; ?>
                            <span class="badge-pill b-progress"><?= count($changes) ?> change(s) in total</span>
                        </div>

                        <?php foreach ($changes as $tooth => $ch): ?>
                            <div class="chg-row">
                                <span class="chg-t">#<?= e($tooth) ?></span>
                                <span class="pill-sm" style="background:<?= $legendColors[$ch[0]] ?>"><?= e($ch[0]) ?></span>
                                <span class="text-muted2">→</span>
                                <span class="pill-sm" style="background:<?= $legendColors[$ch[1]] ?>"><?= e($ch[1]) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- ===== RIGHT COLUMN (hidden until "Edit Chart" is clicked) — dentist, latest visit only ===== -->
            <?php if ($canEditVisit): ?>
            <div class="col-lg-4 no-print" id="odo-right-col" style="display:none;">
                <div class="card-box">
                    <h5>Select a Tooth</h5>
                    <div id="tooth-panel"><p class="text-muted2">Click a tooth on the chart to view or change its condition and note.</p></div>
                    <div class="text-muted2 mt-2" style="font-size:.76rem;">
                        Click <strong>✔ Done</strong> to save your changes into the <strong><?= $session ? date('M j, Y', strtotime($session['visit_date'])) : 'first visit' ?></strong> chart.
                    </div>
                </div>

                <!-- Visit details -->
                <?php if ($session): ?>
                <div class="card-box">
                    <h5>🗓 This Visit</h5>

                    <!-- Read-only view — this is what shows by default. -->
                    <div id="visit-view">
                        <div class="flex-between py-1">
                            <span class="text-muted2">Visit date</span>
                            <strong><?= date('M j, Y', strtotime($session['visit_date'])) ?></strong>
                        </div>
                        <div class="flex-between py-1">
                            <span class="text-muted2">Label</span>
                            <strong><?= e($session['title'] ?: '–') ?></strong>
                        </div>
                        <?php if (trim($session['notes'] ?? '') !== ''): ?>
                        <div class="py-1">
                            <span class="text-muted2 d-block mb-1">Notes</span>
                            <?= nl2br(e($session['notes'])) ?>
                        </div>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline-teal w-100 mt-2" onclick="toggleVisitEdit(true)">✏️ Edit Visit Details</button>
                    </div>

                    <!-- Edit form — hidden until "Edit Visit Details" is clicked. -->
                    <div id="visit-edit" style="display:none;">
                        <form method="POST" onsubmit="odoBeforeSubmit(this)">
                            <input type="hidden" name="action" value="save_session">
                            <input type="hidden" name="teeth" value=""><!-- teeth changed but not saved yet -->
                            <input type="hidden" name="patient_id" value="<?= $pid ?>">
                            <input type="hidden" name="session_id" value="<?= $sid ?>">

                            <label class="field-label">Visit date</label>
                            <input type="date" name="visit_date" class="form-control mb-2" value="<?= e($session['visit_date']) ?>">

                            <label class="field-label">Label</label>
                            <input name="title" class="form-control mb-2" value="<?= e($session['title']) ?>"
                                   placeholder="e.g. Initial Chart, Follow-up, Cleaning">

                            <label class="field-label">Visit notes</label>
                            <textarea name="notes" class="form-control mb-2" rows="3"
                                      placeholder="What was done during this visit..."><?= e($session['notes']) ?></textarea>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-teal w-100 mb-2">💾 Save Visit Details</button>
                                <button type="button" class="btn btn-light w-100 mb-2" onclick="toggleVisitEdit(false)">Cancel</button>
                            </div>
                        </form>

                        <?php if (count($sessions) > 1): ?>
                            <form method="POST" onsubmit="return confirm('Move this visit chart to the Archive? It disappears from the chart history, but the admin can restore it from the Archive.')">
                                <input type="hidden" name="action" value="delete_session">
                                <input type="hidden" name="patient_id" value="<?= $pid ?>">
                                <input type="hidden" name="session_id" value="<?= $sid ?>">
                                <button class="btn btn-light w-100" style="color:#c0392b;">🗄 Archive This Visit</button>
                            </form>
                            <!-- Delete several visits at once (at least one always stays) -->
                            <details class="mt-2">
                                <summary class="text-muted2" style="font-size:.82rem;cursor:pointer;">Archive several visits…</summary>
                                <div class="mt-2">
                                    <?= bulk_bar('bulk-visits', 'delete_session', 'visit charts', ['patient_id' => $pid], '🗄 Archive selected',
                                                 'They move to the Archive, where the admin can restore them. At least one visit always stays.') ?>
                                    <?php foreach (array_reverse($sessions) as $vi => $vs): ?>
                                        <label class="d-flex align-items-center gap-2 py-1" style="font-size:.85rem;cursor:pointer;">
                                            <?= bulk_pick('bulk-visits', $vs['id']) ?>
                                            <span>Visit <?= $vi + 1 ?> · <?= date('M j, Y', strtotime($vs['visit_date'])) ?><?= $vs['title'] ? ' · ' . e($vs['title']) : '' ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endif; ?>
                    </div>

                    <div class="text-muted2 mt-2" style="font-size:.76rem;">
                        Recorded by <?= e($session['created_by'] ?: '–') ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card-box">
                    <h5>Chart Summary</h5>
                    <?php foreach (['Healthy','Decayed','Filled','Crowned','Missing'] as $st): ?>
                        <div class="flex-between py-1">
                            <span><i class="legend-dot" style="background:<?= $legendColors[$st] ?>"></i> <?= $st ?></span>
                            <strong data-sum="<?= $st ?>"><?= $counts[$st] ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>

            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php if ($canEditVisit): /* the admin only views; a past visit is view only — no tooth editing pop-up */ ?>
<!-- ============ TOOTH CONDITION MODAL ============ -->
<!-- Opens when a tooth is clicked. OK only changes the tooth ON SCREEN (it glows
     gold = not saved yet); "✔ Done" saves every changed tooth at once. -->
<div class="modal fade" id="toothModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title">Tooth #<span id="modal-tooth-label">--</span></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <!-- The tooth itself: its color follows the condition chosen below -->
            <div class="text-center mb-3">
                <svg width="64" height="67" viewBox="0 0 40 42" aria-hidden="true">
                    <path id="modal-tooth-shape" class="t-Healthy" d="M8 6 Q20 0 32 6 Q36 18 30 30 Q26 40 20 40 Q14 40 10 30 Q4 18 8 6 Z" stroke-width="1.6"/>
                    <path id="modal-tooth-x" d="M10 8 L30 30 M30 8 L10 30" stroke="#e05b5b" stroke-width="2" fill="none" style="display:none;"/>
                </svg>
                <div id="modal-tooth-cond" class="text-muted2" style="font-size:.85rem;">Healthy</div>
            </div>
            <label class="field-label">Condition</label>
            <select id="modal-status" class="form-select" onchange="odoOtherToggle()">
                <?php foreach ($TOOTH_STATUSES as $st): ?>
                    <option value="<?= $st ?>"><?= $st === 'Other' ? 'Other (type it)' : $st ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" id="modal-other" class="form-control mt-2" maxlength="100" style="display:none;" oninput="odoPreview()"
                   placeholder="Type the condition, e.g. Sensitive, Root stump, Discolored">
            <label class="field-label mt-3">Note <span class="text-muted2">(optional)</span></label>
            <textarea id="modal-notes" class="form-control" rows="2" maxlength="255"
                      placeholder="e.g. Small crack on the side — check again next visit"></textarea>
            <div id="modal-warn" class="text-danger mt-2" style="display:none;font-size:.85rem;"></div>
            <div class="text-muted2 mt-2" style="font-size:.78rem;">
                Not saved yet — when you finish, click <b>✔ Done</b> above the chart to save all your changes at once.
                Tooth notes are for the clinic; patients don't see them in their portal.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-teal" onclick="odoApply()">OK</button>
        </div>
    </div>
  </div>
</div>

<!-- Sent by "✔ Done": every changed tooth, as JSON -->
<form method="POST" id="odo-save-form" class="d-none">
    <input type="hidden" name="action" value="save_teeth">
    <input type="hidden" name="patient_id" value="<?= $pid ?>">
    <input type="hidden" name="session_id" value="<?= $sid ?>">
    <input type="hidden" name="teeth" id="odo-save-teeth">
</form>
<?php endif; ?>

<!-- ============ NEW CHART MODAL ============ -->
<div class="modal fade" id="newSessionModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="new_session">
        <input type="hidden" name="patient_id" value="<?= $pid ?>">
        <div class="modal-header">
            <h5 class="modal-title"><?= $sessions ? 'Add a chart for a new visit' : 'Start the first visit chart' ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <label class="field-label">Visit date</label>
            <input type="date" name="visit_date" class="form-control mb-3" value="<?= date('Y-m-d') ?>">

            <label class="field-label">Label</label>
            <input name="title" class="form-control mb-3" value="<?= $sessions ? 'Follow-up Visit' : 'Initial Chart' ?>"
                   placeholder="e.g. Follow-up, Cleaning, Second Session">

            <?php if (!$sessions): ?>
                <input type="hidden" name="copy_from" value="0">
                <div class="text-muted2" style="font-size:.85rem;">The chart starts with every tooth as Healthy — mark what you find.</div>
            <?php else: ?>
            <label class="field-label">Start this chart from...</label>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="copy_from" id="cp1"
                       value="<?= $sessions[0]['id'] ?? 0 ?>" checked>
                <label class="form-check-label" for="cp1">
                    <strong>The latest chart</strong> (recommended)<br>
                    <span class="text-muted2" style="font-size:.8rem;">
                        Carries over the teeth from the last visit, so you only mark what changed.
                    </span>
                </label>
            </div>
            <div class="form-check mt-2">
                <input class="form-check-input" type="radio" name="copy_from" id="cp0" value="0">
                <label class="form-check-label" for="cp0">
                    <strong>A blank chart</strong><br>
                    <span class="text-muted2" style="font-size:.8rem;">Every tooth starts as Healthy.</span>
                </label>
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-teal">+ Add Chart</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
<script>startClock();</script>
<?php if ($canEditVisit): ?>
<script>
// ===== Odontogram editing: change teeth on screen, save them all with "✔ Done" =====
(function () {
    var saved   = <?= json_encode((object)$toothInfo, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>;  // tooth -> {status, other, notes}
    var pending = {};          // teeth changed but not saved yet
    var leaving = false, openNum = null;
    var HEALTHY = { status: 'Healthy', other: '', notes: '' };
    function cur(n)  { return pending[n] || saved[n] || HEALTHY; }
    function same(a, b) { return a.status === b.status && (a.other || '') === (b.other || '') && (a.notes || '') === (b.notes || ''); }
    function label(c) { return c.status === 'Other' && c.other ? 'Other: ' + c.other : c.status; }
    function count() { return Object.keys(pending).length; }
    var $ = function (id) { return document.getElementById(id); };

    function showPanel(n) {
        var c = cur(n), panel = $('tooth-panel');
        if (!panel) return;
        panel.innerHTML = '<h5 class="mb-1">Tooth #' + escHtml(n) + '</h5><p class="text-muted2 mb-2">Condition</p>'
            + '<span class="badge-pill b-' + (c.status === 'Healthy' ? 'active' : 'pending') + '">' + escHtml(label(c)) + '</span>'
            + (c.notes ? '<div class="mt-2" style="font-size:.88rem;"><b>Note:</b> ' + escHtml(c.notes) + '</div>' : '')
            + (pending[n] ? '<div class="mt-2" style="font-size:.8rem;color:#b7791f;">Not saved yet — click ✔ Done.</div>' : '');
    }
    function drawTooth(n) {
        var el = document.querySelector('.tooth[data-tooth="' + n + '"]'); if (!el) return;
        var c = cur(n), shape = el.querySelector('.tooth-shape'), x = el.querySelector('.tooth-x');
        shape.setAttribute('class', 'tooth-shape t-' + c.status);
        var needX = c.status === 'Missing' || c.status === 'Extracted';
        if (needX && !x) {
            x = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            x.setAttribute('class', 'tooth-x'); x.setAttribute('d', 'M10 8 L30 30 M30 8 L10 30');
            x.setAttribute('stroke', '#e05b5b'); x.setAttribute('stroke-width', '2'); x.setAttribute('fill', 'none');
            shape.parentNode.appendChild(x);
        } else if (!needX && x) x.remove();
        el.classList.toggle('unsaved', !!pending[n]);
        el.classList.toggle('has-note', !!c.notes);
        el.title = 'Tooth ' + n + ' — ' + label(c) + (c.notes ? ' · Note: ' + c.notes : '') + (pending[n] ? ' (not saved yet)' : '');
    }
    function refresh() {
        var n = count();
        $('odo-done-count').textContent = n ? ' — save ' + n : '';
        $('odo-pending').hidden = !n;
        $('odo-pending-text').innerHTML = '<b>' + n + '</b> ' + (n === 1 ? 'tooth' : 'teeth') + ' changed but <b>not saved yet</b> — click <b>✔ Done</b> to save.';
        var sums = {};
        document.querySelectorAll('.tooth[data-tooth]').forEach(function (el) { var st = cur(el.dataset.tooth).status; sums[st] = (sums[st] || 0) + 1; });
        document.querySelectorAll('[data-sum]').forEach(function (el) { el.textContent = sums[el.dataset.sum] || 0; });
    }

    // A tooth was clicked: open the pop-up with its condition and note.
    window.odoSelect = function (n) {
        toggleOdoEdit(true);
        openNum = String(n);
        var c = cur(openNum);
        $('modal-tooth-label').textContent = openNum;
        $('modal-status').value = c.status;
        $('modal-other').value  = c.other || '';
        $('modal-notes').value  = c.notes || '';
        $('modal-warn').style.display = 'none';
        odoOtherToggle();
        showPanel(openNum);
        bootstrap.Modal.getOrCreateInstance($('toothModal')).show();
    };
    // The tooth picture in the pop-up follows the chosen condition.
    window.odoPreview = function () {
        var st = $('modal-status').value;
        $('modal-tooth-shape').setAttribute('class', 't-' + st);
        $('modal-tooth-x').style.display = (st === 'Missing' || st === 'Extracted') ? '' : 'none';
        $('modal-tooth-cond').textContent = label({ status: st, other: st === 'Other' ? $('modal-other').value.trim() : '' });
    };
    window.odoOtherToggle = function () {
        odoPreview();
        var on = $('modal-status').value === 'Other';
        $('modal-other').style.display = on ? '' : 'none';
        if (on) setTimeout(function () { $('modal-other').focus(); }, 50);
    };
    // OK: change the tooth on screen only.
    window.odoApply = function () {
        var st = $('modal-status').value, other = st === 'Other' ? $('modal-other').value.trim() : '';
        if (st === 'Other' && !other) {
            $('modal-warn').textContent = 'Please type the condition.'; $('modal-warn').style.display = 'block';
            $('modal-other').focus(); return;
        }
        var next = { status: st, other: other, notes: $('modal-notes').value.trim() };
        if (same(next, saved[openNum] || HEALTHY)) delete pending[openNum]; else pending[openNum] = next;
        drawTooth(openNum); refresh(); showPanel(openNum);
        hideModal();
    };
    // OK pressed while the pop-up is still opening: close it as soon as it is open.
    var modalOpen = false, closeWhenOpen = false;
    $('toothModal').addEventListener('show.bs.modal', function () { modalOpen = false; closeWhenOpen = false; });
    $('toothModal').addEventListener('shown.bs.modal', function () { modalOpen = true; if (closeWhenOpen) hideModal(); });
    function hideModal() {
        if (!modalOpen) { closeWhenOpen = true; return; }
        closeWhenOpen = false;
        bootstrap.Modal.getOrCreateInstance($('toothModal')).hide();
    }
    $('modal-other').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); odoApply(); } });
    // ✔ Done: save every changed tooth at once (or just close the editing panel).
    window.odoDone = function () {
        if (!count()) { toggleOdoEdit(false); return; }
        $('odo-save-teeth').value = JSON.stringify(pending);
        leaving = true;
        $('odo-save-form').submit();
    };
    window.odoUndo = function () {
        if (!confirm('Undo the changes that are not saved yet?')) return;
        var ks = Object.keys(pending); pending = {};
        ks.forEach(drawTooth); refresh();
        if (openNum) showPanel(openNum);
    };
    // "Save Visit Details" also saves the teeth that were changed.
    window.odoBeforeSubmit = function (f) { f.querySelector('[name="teeth"]').value = count() ? JSON.stringify(pending) : ''; leaving = true; };
    // Leaving the page (another visit, another patient) with unsaved teeth: ask first.
    window.addEventListener('beforeunload', function (e) { if (!leaving && count()) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>
<?php endif; ?>
</body>
</html>
