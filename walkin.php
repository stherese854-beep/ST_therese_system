<?php
// ============================================================
//  SAVE A WALK-IN VISIT  (walkin.php)
// ============================================================
//  Receives the "+ Walk-in" form (includes/walkins.php). Creates the
//  patient if they are new (or reuses the record of the same person),
//  saves the health questionnaire on their record, and saves today's
//  visit. No appointment is made.
//  A dentist is then taken to the patient's Records to write up the
//  visit; staff / admin go back to the page they came from.
// ============================================================
require_once 'config/auth.php';
require_login(['admin', 'staff', 'dentist']);
require_once 'includes/walkins.php';
require_once 'includes/health_form.php';
require_once 'includes/treatments.php';

$back = preg_replace('/[^a-z_?=&0-9-]/i', '', (string)($_POST['back'] ?? '')) ?: 'dashboard';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'walkin') { header("Location: $back"); exit; }
ensure_walkin_table($pdo);

$role   = current_role();
$mode   = ($_POST['patient_mode'] ?? '') === 'new' ? 'new' : 'existing';
$err    = '';
$patient = null;

if ($mode === 'existing') {
    $pid = (int)($_POST['patient_id'] ?? 0);
    foreach (walkin_patients($pdo) as $p) if ((int)$p['id'] === $pid) $patient = $p;
    if (!$patient) $err = 'Please choose the patient from the list.';
} else {
    $first = trim($_POST['first_name'] ?? ''); $last = trim($_POST['last_name'] ?? '');
    [$phone, $phoneErr] = validate_phone($_POST['phone'] ?? '');
    $dob = trim($_POST['dob'] ?? '');
    if ($first === '' || $last === '')               $err = "Please enter the patient's first and last name.";
    elseif ($phoneErr !== '')                       $err = $phoneErr;
    elseif (($dobErr = birth_date_error($dob)) !== '') $err = $dobErr;
}
[$health, $healthErr] = health_form_from_post($_POST);
[$services, $servErr] = treatments_from_post($_POST);
$dentist = $role === 'dentist' ? ($_SESSION['name'] ?? '') : trim($_POST['dentist'] ?? '');
if ($err === '' && $healthErr !== '') $err = $healthErr;
if ($err === '' && $servErr !== '')   $err = 'Please choose the service done today.';
if ($err === '' && $dentist === '')   $err = 'Please choose the dentist who sees them.';
if ($err === '' && $role !== 'dentist') {
    $ok = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'dentist' AND status = 'active' AND name = ?");
    $ok->execute([$dentist]);
    if (!(int)$ok->fetchColumn()) $err = 'Please choose a dentist from the list.';
}
if ($err !== '') { set_flash($err, 'error'); header("Location: $back"); exit; }

// New patient: reuse the record if it is clearly the same person (same name + birthday).
if ($mode === 'new') {
    $name = ucwords(trim("$first $last"));
    if ($dob !== '') {
        $same = $pdo->prepare("SELECT id, name, phone, primary_dentist, status FROM patients WHERE date_of_birth = ? AND status <> 'Archived'");
        $same->execute([$dob]);
        foreach ($same->fetchAll() as $s) if (person_name_key($s['name']) === person_name_key($name)) { $patient = $s; break; }
    }
    if ($patient) {
        $note = ' (' . $patient['name'] . ' was already a patient, so the visit was added to their record.)';
    } else {
        $pdo->prepare("INSERT INTO patients (name, phone, date_of_birth, age, patient_type, status, primary_dentist)
                       VALUES (?, ?, ?, ?, 'New', 'Active', ?)")
            ->execute([$name, $phone, $dob ?: null, $dob !== '' ? age_from_dob($dob) : null, $dentist]);
        $patient = ['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'primary_dentist' => $dentist];
        log_activity($pdo, 'Added patient', $name . ' (walk-in)');
        $note = '';
    }
}
$pid = (int)$patient['id'];
if (empty($patient['primary_dentist'])) $pdo->prepare("UPDATE patients SET primary_dentist = ? WHERE id = ?")->execute([$dentist, $pid]);

save_patient_health($pdo, $pid, $health);                       // their record keeps the latest answers
$pdo->prepare("INSERT INTO walk_in_visits (patient_id, patient_name, visit_date, visit_time, dentist, services, health_form, notes, created_by)
               VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?)")
    ->execute([$pid, $patient['name'], date('h:i A'), $dentist, $services,
               json_encode($health, JSON_UNESCAPED_UNICODE), mb_substr(trim($_POST['notes'] ?? ''), 0, 1000) ?: null,
               $_SESSION['name'] ?? null]);
require_once 'includes/clinical.php';
sync_visit_info($pdo, $pid);                                    // last visit + New / Returning
log_activity($pdo, 'Walk-in visit', $patient['name'] . ' — ' . $services . ' with ' . $dentist);

$msg = 'Walk-in visit saved for ' . $patient['name'] . ' (' . $services . ').' . ($note ?? '');
$ownPatient = $role !== 'dentist' || empty($patient['primary_dentist']) || $patient['primary_dentist'] === $dentist;
if (($role === 'dentist' || $role === 'admin') && $ownPatient) {
    set_flash($msg . ' Record the treatment below.');
    header("Location: records?patient=$pid&tab=treatments"); exit;
}
set_flash($msg . " $dentist will see them.");
header("Location: $back"); exit;
