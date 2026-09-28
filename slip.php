<?php
// ============================================================
//  APPOINTMENT SLIP  (slip.php)
// ============================================================
//  A clean, printable confirmation slip for ONE confirmed
//  appointment. The patient opens it from their portal and can
//  print it or save it as a PDF using the browser's print dialog.
//
//  Rules:
//   - Only the patient who owns the appointment (or admin/staff/
//     the assigned dentist) may open it.
//   - Only CONFIRMED appointments get a slip.
// ============================================================
require_once 'config/auth.php';
require_login(['admin','dentist','staff','patient']);

$id = (int)($_GET['id'] ?? 0);

// Load the appointment.
$stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = ?");
$stmt->execute([$id]);
$appt = $stmt->fetch();

if (!$appt) {
    // Same answer as "not yours", so guessing IDs does not reveal which exist.
    deny_access("Appointment slip #$id");
}

// ---- Access control ----
$role = current_role();
$allowed = false;
if ($role === 'admin' || $role === 'staff') {
    $allowed = true;
} elseif ($role === 'patient') {
    // The appointment must belong to this patient's account.
    $pst = $pdo->prepare("SELECT id FROM patients WHERE user_id = ?");
    $pst->execute([$_SESSION['user_id'] ?? 0]);
    $mypid = (int)$pst->fetchColumn();
    $allowed = ($mypid && in_array((int)$appt['patient_id'], family_patient_ids($pdo, $mypid), true));
} elseif ($role === 'dentist') {
    // The assigned dentist of this patient may view it.
    $myName = $_SESSION['name'] ?? '';
    $pst = $pdo->prepare("SELECT primary_dentist FROM patients WHERE id = ?");
    $pst->execute([$appt['patient_id']]);
    $pd = $pst->fetchColumn();
    $allowed = ($pd === $myName || $appt['dentist'] === $myName);
}

if (!$allowed) {
    deny_access("Appointment slip #$id");
}

// Only confirmed appointments have a slip.
if ($appt['status'] !== 'Confirmed') {
    exit('A slip is only available once the appointment is confirmed by the clinic.');
}

// ---- Clinic details (from Settings, with sensible fallbacks) ----
function slip_setting($pdo, $key, $default = '') {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach ($pdo->query("SELECT setting_key, setting_value FROM settings") as $r) {
            $cache[$r['setting_key']] = $r['setting_value'];
        }
    }
    return ($cache[$key] ?? '') !== '' ? $cache[$key] : $default;
}
$clinicName    = slip_setting($pdo, 'clinic_name',    'St. Therese of Carmel Dental Clinic');
$clinicAddress = slip_setting($pdo, 'clinic_address', 'Congressional Road, Brgy. San Gabriel, GMA, Cavite');
$clinicPhone   = slip_setting($pdo, 'clinic_phone',   '0917-552-7714');

// Build the hours line from the open/close time settings (stored as 24h HH:MM).
$openT  = slip_setting($pdo, 'clinic_open_time',  '09:00');
$closeT = slip_setting($pdo, 'clinic_close_time', '18:00');
$openDays = slip_setting($pdo, 'clinic_open_days', 'Mon,Tue,Wed,Thu,Fri,Sat');
// Turn "Mon,Tue,...,Sat" into a short "Mon - Sat" range when the days are contiguous.
$daysArr = array_filter(array_map('trim', explode(',', $openDays)));
$daysLabel = count($daysArr) >= 2 ? (reset($daysArr) . ' - ' . end($daysArr)) : implode(', ', $daysArr);
$clinicHours = $daysLabel . ', ' . date('g:i A', strtotime($openT)) . ' - ' . date('g:i A', strtotime($closeT));

// A simple reference number so the slip looks official.
$refNo = 'STC-' . str_pad((string)$appt['id'], 5, '0', STR_PAD_LEFT);

// Editable slip wording (Message Templates -> Printed appointment slip).
require_once __DIR__ . '/includes/message_templates.php';
$slipCfg = [];
foreach (slip_fields() as $k => $f) {
    $v = slip_text($pdo, $k);
    $slipCfg[$k] = str_replace('{clinic}', $clinicName, ($v !== '' ? $v : $f['default']));
}

// Where "Back" should go, since this page normally opens in a new tab.
$backLink = ($role === 'patient') ? 'portal?view=appointments' : 'appointments';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Appointment Slip · <?= htmlspecialchars($refNo) ?></title>
<style>
    :root{ --teal:#0f766e; --teal-dark:#0d3b3b; --gold:#c79a5c; --ink:#11302d; }
    *{ box-sizing:border-box; margin:0; padding:0; }
    body{ font-family:'Segoe UI',system-ui,sans-serif; background:#eef6f5; color:var(--ink);
          padding:30px 16px; }
    .slip{ max-width:520px; margin:0 auto; background:#fff; border-radius:16px; overflow:hidden;
           box-shadow:0 20px 60px rgba(12,50,48,.18); }

    /* header */
    .slip-head{ background:linear-gradient(135deg,var(--teal-dark),var(--teal)); color:#fff;
                padding:26px 30px; }
    .slip-head .logo{ font-size:2rem; }
    .slip-head h1{ font-size:1.25rem; margin-top:6px; }
    .slip-head .info{ font-size:.8rem; opacity:.9; margin-top:6px; line-height:1.5; }

    /* confirmation badge row */
    .slip-title{ padding:18px 30px; border-bottom:1px dashed #d8e3e1;
                 display:flex; justify-content:space-between; align-items:center; }
    .slip-title h2{ font-size:1rem; letter-spacing:1px; color:var(--teal); }
    .badge{ background:#e7f6ee; color:#1f8a54; font-weight:700; font-size:.78rem;
            padding:6px 14px; border-radius:100px; }
    .ref{ font-size:.74rem; color:#8aa0a0; margin-top:2px; }

    /* details grid */
    .slip-body{ padding:24px 30px; }
    .row{ display:flex; padding:11px 0; border-bottom:1px solid #f1f5f5; }
    .row:last-child{ border-bottom:none; }
    .row .label{ width:130px; color:#8aa0a0; font-size:.85rem; flex:none; }
    .row .value{ font-weight:600; font-size:.95rem; }

    .note{ background:#fff8ec; border:1px solid var(--gold); border-radius:10px;
           padding:12px 16px; margin:6px 30px 24px; font-size:.82rem; color:#8a6d2f; }

    .slip-foot{ text-align:center; font-size:.74rem; color:#9fb2b0; padding:16px 30px 26px; }

    /* buttons (hidden when printing) */
    .actions{ max-width:520px; margin:20px auto 0; text-align:center; }
    .btn{ border:none; border-radius:10px; padding:12px 26px; font-weight:600; font-size:.95rem;
          cursor:pointer; text-decoration:none; display:inline-block; margin:0 4px; }
    .btn-teal{ background:var(--teal); color:#fff; }
    .btn-light{ background:#fff; color:var(--teal); border:1px solid #cfe0dd; }

    @media print {
        body{ background:#fff; padding:14mm; margin:0; -webkit-box-decoration-break:clone; box-decoration-break:clone;
              -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        .slip{ box-shadow:none; border-radius:0; max-width:100%; }
        .actions{ display:none !important; }
        @page{ margin:0; }   /* no room for the browser's own headers/footers */
    }
</style>
</head>
<body>

<div class="slip">
    <div class="slip-head">
        <div class="logo">🦷</div>
        <h1><?= htmlspecialchars($clinicName) ?></h1>
        <div class="info">
            <?= htmlspecialchars($clinicAddress) ?><br>
            📞 <?= htmlspecialchars($clinicPhone) ?> &nbsp;·&nbsp; 🕐 <?= htmlspecialchars($clinicHours) ?>
        </div>
    </div>

    <div class="slip-title">
        <div>
            <h2><?= htmlspecialchars($slipCfg['slip_title']) ?></h2>
            <div class="ref">Reference No: <?= htmlspecialchars($refNo) ?></div>
        </div>
        <span class="badge">✓ CONFIRMED</span>
    </div>

    <div class="slip-body">
        <div class="row"><div class="label">Patient</div><div class="value"><?= htmlspecialchars($appt['patient_name']) ?></div></div>
        <div class="row"><div class="label">Date</div><div class="value"><?= date('l, F j, Y', strtotime($appt['appointment_date'])) ?></div></div>
        <div class="row"><div class="label">Time</div><div class="value"><?= htmlspecialchars($appt['appointment_time']) ?></div></div>
        <div class="row"><div class="label">Dentist</div><div class="value"><?= htmlspecialchars($appt['dentist'] ?: 'To be assigned') ?></div></div>
        <div class="row"><div class="label">Treatment</div><div class="value"><?= htmlspecialchars($appt['treatment']) ?></div></div>
        <?php if (!empty($appt['reason_for_visit'])): ?>
        <div class="row"><div class="label">Reason</div><div class="value"><?= htmlspecialchars($appt['reason_for_visit']) ?></div></div>
        <?php endif; ?>
    </div>

    <div class="note">📌 <?= nl2br(htmlspecialchars($slipCfg['slip_note'])) ?></div>

    <div class="slip-foot">
        <?= htmlspecialchars($slipCfg['slip_footer']) ?><br>
        Printed on <?= date('M j, Y g:i A') ?>.
    </div>
</div>

<div class="actions">
    <button class="btn btn-teal" onclick="window.print()">🖨 Print / Save as PDF</button>
    <a class="btn btn-light" href="<?= $backLink ?>">← Back</a>
</div>

<script>
// The slip usually opens in a NEW TAB, so there is no page to go "back" to.
// If this really is a fresh tab, turn the button into a Close instead.
(function () {
    var btn = document.querySelector('.actions a');
    if (!btn) return;
    if (window.history.length <= 1) {
        btn.textContent = '✕ Close';
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            window.close();
            // Some browsers refuse to close a tab they did not open —
            // in that case fall back to the page the button points at.
            setTimeout(function () { window.location.href = btn.getAttribute('href'); }, 120);
        });
    }
})();
</script>

</body>
</html>
