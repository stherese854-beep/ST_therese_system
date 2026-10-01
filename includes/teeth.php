<?php
// ============================================================
//  ODONTOGRAM HELPER  (includes/teeth.php)
// ============================================================
//  Draws the interactive dental chart. Shared by the dentist
//  Odontogram page and the patient's "My Dental Chart".
// ============================================================

// FDI tooth numbers, in the order shown in the chart.
$UPPER_TEETH = ['18','17','16','15','14','13','12','11','21','22','23','24','25','26','27','28'];
$LOWER_TEETH = ['48','47','46','45','44','43','42','41','31','32','33','34','35','36','37','38'];

// All possible conditions (used for the legend and the dropdown).
$TOOTH_STATUSES = ['Healthy','Decayed','Filled','Missing','Crowned','Extracted','Impacted','Fractured'];

// Draws ONE tooth as a small SVG. $status sets its color via CSS class.
function render_tooth($num, $status) {
    $cls = 't-' . $status;
    // For "Missing" we draw an X over the tooth.
    $extra = '';
    if ($status === 'Missing' || $status === 'Extracted') {
        $extra = '<path d="M10 8 L30 30 M30 8 L10 30" stroke="#e05b5b" stroke-width="2" fill="none"/>';
    }
    return "
    <div class='tooth' onclick=\"selectTooth('$num','$status')\">
        <svg width='40' height='42' viewBox='0 0 40 42'>
            <path class='$cls' d='M8 6 Q20 0 32 6 Q36 18 30 30 Q26 40 20 40 Q14 40 10 30 Q4 18 8 6 Z' stroke-width='1.6'/>
            $extra
        </svg>
        <div class='num'>$num</div>
    </div>";
}

// Builds a "patient_id => 'Status'" lookup from the odontogram rows.
// ============================================================
//  CHART SESSIONS  (one dental chart per visit)
// ============================================================
// A patient's chart is no longer a single drawing that gets overwritten.
// Each VISIT is its own session, so you can open the first visit and the
// latest one side by side and see how the patient improved.

/** All of a patient's chart sessions, newest visit first. */
function get_chart_sessions($pdo, $patient_id) {
    $stmt = $pdo->prepare(
        "SELECT * FROM chart_sessions WHERE patient_id=? AND archived_at IS NULL   -- archived visits live in the Archive
         ORDER BY visit_date DESC, id DESC"
    );
    $stmt->execute([$patient_id]);
    return $stmt->fetchAll();
}

/** The patient's CURRENT chart (their most recent visit). Null if they have none. */
function latest_session_id($pdo, $patient_id) {
    $stmt = $pdo->prepare(
        "SELECT id FROM chart_sessions WHERE patient_id=? AND archived_at IS NULL
         ORDER BY visit_date DESC, id DESC LIMIT 1"
    );
    $stmt->execute([$patient_id]);
    $id = $stmt->fetchColumn();
    return $id ?: null;
}

/**
 * Make sure the patient has at least one session, and give back its id.
 * (Called the first time somebody opens a brand-new patient's chart.)
 */
function ensure_chart_session($pdo, $patient_id, $who = 'System') {
    $id = latest_session_id($pdo, $patient_id);
    if ($id) return $id;

    $pdo->prepare("INSERT INTO chart_sessions (patient_id,visit_date,title,created_by)
                   VALUES (?,CURDATE(),'Initial Chart',?)")
        ->execute([$patient_id, $who]);
    return (int)$pdo->lastInsertId();
}

/**
 * Build a tooth -> status lookup for ONE chart.
 *
 * @param int|null $session_id  Which visit to read. Leave it out and you get
 *                              the patient's LATEST chart, which is what every
 *                              other page (reports, records, portal) wants.
 */
function build_tooth_map($pdo, $patient_id, $session_id = null) {
    if ($session_id === null) {
        $session_id = latest_session_id($pdo, $patient_id);
    }

    if ($session_id) {
        $stmt = $pdo->prepare("SELECT tooth_number, tooth_status FROM odontogram
                               WHERE patient_id=? AND session_id=?");
        $stmt->execute([$patient_id, $session_id]);
    } else {
        // No sessions at all (e.g. an old database before the update):
        // fall back to any rows that were never assigned to a session.
        $stmt = $pdo->prepare("SELECT tooth_number, tooth_status FROM odontogram
                               WHERE patient_id=? AND session_id IS NULL");
        $stmt->execute([$patient_id]);
    }

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[$row['tooth_number']] = $row['tooth_status'];
    }
    return $map;
}

/**
 * ONE-TIME CLEAN-UP: the Odontogram page used to create an "Initial Chart" visit
 * just by being opened. Remove those blank ones — no teeth marked, no notes —
 * for patients who have never actually been seen (no Completed/Arrived visit),
 * so their chart history is empty until the dentist records a real visit.
 */
function cleanup_auto_chart_sessions($pdo) {
    try {
        if ($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'chart_autoclean_v1'")->fetchColumn()) return;
        $n = $pdo->exec(
            "DELETE s FROM chart_sessions s
              WHERE s.archived_at IS NULL AND s.title = 'Initial Chart' AND COALESCE(s.notes, '') = ''
                AND NOT EXISTS (SELECT 1 FROM odontogram o WHERE o.session_id = s.id)
                AND NOT EXISTS (SELECT 1 FROM appointments a WHERE a.patient_id = s.patient_id AND a.status IN ('Completed','Arrived'))");
        save_setting($pdo, 'chart_autoclean_v1', date('Y-m-d H:i:s') . " ($n removed)");
    } catch (Throwable $e) { /* try again after the next update */ }
}
