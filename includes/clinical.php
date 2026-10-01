<?php
// ============================================================
//  CLINICAL INFORMATION  (includes/clinical.php)
// ============================================================
//  Records → Overview is split in two cards:
//   👤 Patient details   — who the patient is (name, birthday, contact,
//                           dentist, emergency contact). Admin edits; the
//                           dentist sees it read-only. Type / last visit /
//                           next visit are worked out by the system.
//   🩺 Clinical information — what the dentist needs: blood type,
//                           ⚠️ allergies, 💊 medications, conditions (from
//                           the health questionnaire), medical alert,
//                           dental notes, the dentist's remarks.
//  Allergies + medical alert also show as a red banner on the Odontogram,
//  the Schedule and the "Add procedure" form.
// ============================================================

const GUM_CONDITIONS = ['Healthy', 'Gingivitis (mild gum disease)', 'Periodontitis (gum disease)'];
const ORAL_HYGIENE   = ['Good', 'Fair', 'Poor'];
const DENTAL_HABITS  = ['Smoker', 'Teeth grinding (bruxism)', 'Nail / pen biting', 'Mouth breathing'];
const DENTAL_ANXIETY = ['None', 'Mild', 'High — needs extra care'];

function ensure_clinical_columns($pdo) {
    $cols = [
        'allergies'           => 'TEXT DEFAULT NULL',
        'medications'         => 'TEXT DEFAULT NULL',
        'medical_conditions'  => 'TEXT DEFAULT NULL',
        'gum_condition'       => 'VARCHAR(40) DEFAULT NULL',
        'oral_hygiene'        => 'VARCHAR(20) DEFAULT NULL',
        'dental_habits'       => 'VARCHAR(200) DEFAULT NULL',
        'dental_anxiety'      => 'VARCHAR(40) DEFAULT NULL',
        'emergency_name'      => 'VARCHAR(100) DEFAULT NULL',
        'emergency_phone'     => 'VARCHAR(30) DEFAULT NULL',
        'clinical_updated_by' => 'VARCHAR(100) DEFAULT NULL',
        'clinical_updated_at' => 'DATETIME DEFAULT NULL',
    ];
    foreach ($cols as $c => $def) {
        try {
            if (!$pdo->query("SHOW COLUMNS FROM patients LIKE '$c'")->rowCount()) {
                $pdo->exec("ALTER TABLE patients ADD COLUMN $c $def");
            }
        } catch (Throwable $e) {}
    }
}

/**
 * Patient type and last visit come from the patient's real visits:
 * "New" until a visit is Completed, then "Returning"; last visit = latest completed one.
 */
function sync_visit_info($pdo, $patientId) {
    try {
        $q = $pdo->prepare("SELECT MAX(appointment_date) FROM appointments
                             WHERE patient_id = ? AND status = 'Completed' AND appointment_date <= CURDATE()");
        $q->execute([(int)$patientId]);
        $last = $q->fetchColumn() ?: null;
        $type = $last ? 'Returning' : 'New';
        $pdo->prepare("UPDATE patients SET patient_type = ?, last_visit = COALESCE(?, last_visit)
                        WHERE id = ? AND (patient_type <> ? OR (? IS NOT NULL AND (last_visit IS NULL OR last_visit < ?)))")
            ->execute([$type, $last, (int)$patientId, $type, $last, $last]);
    } catch (Throwable $e) {}
}

/** [date or null, where it comes from] — the next booked appointment, else the follow-up date. */
function next_visit_info($pdo, $patient) {
    $q = $pdo->prepare("SELECT appointment_date, appointment_time FROM appointments
                         WHERE patient_id = ? AND status IN ('Pending','Confirmed') AND appointment_date >= CURDATE()
                         ORDER BY appointment_date LIMIT 1");
    $q->execute([(int)$patient['id']]);
    if ($b = $q->fetch()) return [$b['appointment_date'], 'booked · ' . $b['appointment_time']];
    if (!empty($patient['next_visit']) && $patient['next_visit'] >= date('Y-m-d')) return [$patient['next_visit'], 'follow-up due · not booked'];
    return [null, ''];
}

// Conditions flagged in the latest health questionnaire (e.g. "Diabetes", "Pregnant").
function questionnaire_conditions($pdo, $patientId) {
    if (!function_exists('patient_health')) require_once __DIR__ . '/health_form.php';
    [$ans] = patient_health($pdo, $patientId);
    return $ans ? health_form_flags($ans) : [];
}

/** Red banner with allergies + medical alert, or '' when there is nothing to warn about. */
function patient_alert_banner($row, $compact = false) {
    $parts = [];
    if (trim((string)($row['allergies'] ?? '')) !== '')     $parts[] = '<b>Allergies:</b> ' . e($row['allergies']);
    if (trim((string)($row['medical_alert'] ?? '')) !== '') $parts[] = '<b>Alert:</b> ' . e($row['medical_alert']);
    if (!$parts) return '';
    if ($compact) return '<div class="pt-alert-sm" title="Check before treating">⚠️ ' . implode(' · ', $parts) . '</div>';
    return '<div class="pt-alert" role="alert">⚠️ ' . implode(' &nbsp;·&nbsp; ', $parts) . '</div>';
}

function clinical_styles() {
    return '<style>
    .pt-alert { background:#fdecea; border:1px solid #f5c2bd; border-left:5px solid #c0392b; color:#8e2a1f; border-radius:10px;
                padding:10px 14px; margin-bottom:12px; font-size:.95rem; }
    .pt-alert-sm { color:#c0392b; font-size:.78rem; font-weight:600; margin-top:2px; }
    .ro-field { background:#f3f6f7; border:1px solid #e3e9ec; border-radius:8px; padding:8px 12px; min-height:40px; color:#3f5350; }
    .ro-field small { color:#8aa0a0; }
    .chip-check { display:inline-flex; align-items:center; gap:6px; border:1px solid #dde5ea; border-radius:999px; padding:4px 12px; margin:0 6px 6px 0; cursor:pointer; font-size:.88rem; }
    .chip-check:has(input:checked) { border-color: var(--teal-mid); background:#eef7f6; }
    </style>';
}
