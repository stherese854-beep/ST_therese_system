<?php
// ============================================================
//  MY SCHEDULE / AVAILABILITY  (schedule.php)
// ============================================================
//  Lets a DENTIST disable specific days (days off / unavailable),
//  so the system knows they won't take appointments on that day.
//  Admins can also open this page and pick which dentist to manage.
//
//  Days off are stored in the `dentist_daysoff` table.
// ============================================================
require_once 'config/auth.php';
require_login(['admin','dentist']);
require_once 'includes/assign.php';    // dentist_match_sql(), pick_dentist_for_slot()
require_once 'includes/mailer.php';    // emails to patients whose booking moves / is disapproved

$role = current_role();

// ---------- Which dentist are we managing? ----------
if ($role === 'admin') {
    // Admin can choose any dentist from a dropdown.
    $allDentists = $pdo->query("SELECT name FROM users WHERE role='dentist' AND status <> 'archived' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $selectedDentist = $_GET['dentist'] ?? ($allDentists[0] ?? '');
} else {
    // A dentist manages their own schedule only.
    $selectedDentist = $_SESSION['name'] ?? '';
}

// ---------- Add / remove a day off ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_dayoff') {
        // A dentist can only mark THEIR OWN days off, whatever the form posts.
        $dentist = ($role === 'admin') ? ($_POST['dentist_name'] ?? '') : $selectedDentist;
        $date    = $_POST['off_date'];
        $reason  = trim($_POST['reason'] ?? '');

        $back = "Location: schedule" . ($role === 'admin' ? "?dentist=" . urlencode($dentist) : "");
        $dateOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date) && strtotime($date) !== false;

        if (!$dentist || !$dateOk) {
            set_flash('Please choose a valid date.', 'error');
            header($back); exit;
        }
        // 1. At least one day ahead — today (or a past day) cannot be disabled.
        if ($date <= date('Y-m-d')) {
            set_flash('You can only disable a day from tomorrow onwards — not today or a past day.', 'error');
            header($back); exit;
        }
        // Already disabled?
        $check = $pdo->prepare("SELECT id FROM dentist_daysoff WHERE dentist_name=? AND off_date=?");
        $check->execute([$dentist, $date]);
        if ($check->fetch()) {
            set_flash('That day is already marked as unavailable.', 'info');
            header($back); exit;
        }

        // This dentist's appointments that day (matched in either name shape).
        $dp = [$date];
        $apq = $pdo->prepare("SELECT a.*, COALESCE(NULLIF(p.email,''), g.email) AS patient_email
                                FROM appointments a
                           LEFT JOIN patients p ON p.id = a.patient_id
                           LEFT JOIN patients g ON g.id = p.guardian_patient_id
                               WHERE a.appointment_date = ? AND " . dentist_match_sql('a.dentist', $dentist, $dp) . "
                                 AND a.status IN ('Pending','Confirmed','Arrived')
                            ORDER BY STR_TO_DATE(REPLACE(a.appointment_time, ' ', ''), '%h:%i%p')");
        $apq->execute($dp);
        $dayAppts = $apq->fetchAll();

        // 2. An APPROVED (or arrived) appointment that day blocks the day off.
        $approved = array_filter($dayAppts, fn($a) => in_array($a['status'], ['Confirmed','Arrived'], true));
        if ($approved) {
            $list = implode(', ', array_map(fn($a) => $a['patient_name'] . ' at ' . $a['appointment_time'], $approved));
            set_flash('You cannot disable ' . date('M j, Y', strtotime($date)) . ' — you have ' . count($approved)
                      . ' approved appointment' . (count($approved) > 1 ? 's' : '') . " that day ($list). "
                      . 'Please ask the clinic to move or cancel them first.', 'error');
            header($back); exit;
        }

        // Save the day off FIRST, so this dentist is no longer "free" that day.
        $pdo->prepare("INSERT INTO dentist_daysoff (dentist_name, off_date, reason) VALUES (?,?,?)")
            ->execute([$dentist, $date, $reason]);
        log_activity($pdo, 'Marked day off', $dentist . ' — ' . date('M j, Y', strtotime($date)) . ($reason !== '' ? ' (' . $reason . ')' : ''));

        // 3. PENDING requests that day: hand each to another free dentist at the
        //    same time; if nobody is free, disapprove it. The patient is emailed either way.
        $moved = 0; $disapproved = 0; $mailReady = mail_is_ready($pdo);
        foreach ($dayAppts as $ap) {
            if ($ap['status'] !== 'Pending') continue;
            $when = date('l, F j, Y', strtotime($ap['appointment_date']));
            $other = pick_dentist_for_slot($pdo, $ap['appointment_date'], $ap['appointment_time']);
            if ($other && !in_array($other, dentist_name_variants($dentist), true) && $other !== $dentist) {
                $pdo->prepare("UPDATE appointments SET dentist = ? WHERE id = ?")->execute([$other, $ap['id']]);
                log_activity($pdo, 'Appointment moved to another dentist', $ap['patient_name'] . " ($when " . $ap['appointment_time'] . ") → $other");
                $moved++;
                if ($mailReady && !empty($ap['patient_email'])) {
                    $cat = message_catalogue()['appointment_updated'];
                    [$subj, $body] = tpl_message($pdo, 'appointment_updated', $cat['subject'], $cat['body'], [
                        'patient' => $ap['patient_name'], 'was' => $when . ' at ' . $ap['appointment_time'] . ' with ' . $dentist,
                        'date' => $when, 'time' => $ap['appointment_time'], 'treatment' => $ap['treatment'], 'dentist' => $other,
                        'note' => 'Your dentist is not available that day, so ' . $other . ' will see you at the same time instead.',
                        'clinic' => clinic_name($pdo),
                    ]);
                    $err = ''; send_mail($pdo, $ap['patient_email'], $subj, $body, $err, 'appointment_updated');
                }
            } else {
                $why = 'The dentist is not available that day and no other dentist is free at that time. Please book another schedule.';
                $pdo->prepare("UPDATE appointments SET status = 'Disapproved', cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ? WHERE id = ?")
                    ->execute([$_SESSION['name'] ?? 'clinic', $why, $ap['id']]);
                log_activity($pdo, 'Appointment Disapproved', $ap['patient_name'] . " ($when " . $ap['appointment_time'] . ') — dentist day off, no other dentist free');
                $disapproved++;
                if ($mailReady && !empty($ap['patient_email'])) {
                    $cat = message_catalogue()['appointment_disapproved'];
                    [$subj, $body] = tpl_message($pdo, 'appointment_disapproved', $cat['subject'], $cat['body'], [
                        'patient' => $ap['patient_name'], 'date' => $when, 'time' => $ap['appointment_time'],
                        'treatment' => $ap['treatment'], 'reason' => $why, 'clinic' => clinic_name($pdo),
                    ]);
                    $err = ''; send_mail($pdo, $ap['patient_email'], $subj, $body, $err, 'appointment_disapproved');
                }
            }
        }

        $msg = date('M j, Y', strtotime($date)) . ' marked as unavailable.';
        if ($moved)       $msg .= " $moved pending request" . ($moved > 1 ? 's were' : ' was') . ' moved to another dentist.';
        if ($disapproved) $msg .= " $disapproved pending request" . ($disapproved > 1 ? 's were' : ' was') . ' disapproved (no other dentist free)'
                                . ($mailReady ? ' — the patient' . ($disapproved > 1 ? 's were' : ' was') . ' emailed.' : ' — email is not set up, so please tell the patient.');
        set_flash($msg, $disapproved ? 'info' : 'success');
        header($back); exit;
    }

    if ($action === 'remove_dayoff') {
        // A dentist may only remove their OWN days off; admin may remove any.
        if ($role === 'admin') {
            $pdo->prepare("DELETE FROM dentist_daysoff WHERE id=?")->execute([$_POST['id']]);
            log_activity($pdo, 'Removed day off', '#' . (int)$_POST['id']);
            set_flash('Day off removed.', 'info');
        } else {
            $del = $pdo->prepare("DELETE FROM dentist_daysoff WHERE id=? AND dentist_name=?");
            $del->execute([$_POST['id'], $selectedDentist]);
            if ($del->rowCount()) log_activity($pdo, 'Removed day off', $selectedDentist);
            set_flash($del->rowCount() ? 'Day off removed.' : 'That day off is not yours to remove.',
                      $del->rowCount() ? 'info' : 'error');
        }
        header("Location: schedule" . ($role === 'admin' && isset($_POST['back']) ? "?dentist=".urlencode($_POST['back']) : "")); exit;
    }
}

// ---------- Load this dentist's days off (today onward) ----------
$daysOff = [];
if ($selectedDentist !== '') {
    $stmt = $pdo->prepare("SELECT * FROM dentist_daysoff WHERE dentist_name=? ORDER BY off_date ASC");
    $stmt->execute([$selectedDentist]);
    $daysOff = $stmt->fetchAll();
}

$page_title = "My Schedule";
include 'includes/head.php';
$active = 'schedule';
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div>
                <h1>My Schedule</h1>
                <div class="sub">Mark the days you are unavailable — the clinic won't book you on those days.</div>
            </div>
            <div class="clock"><span class="time" id="clock"></span><br><span id="clock-date"></span></div>
        </div>

        <?php if ($role === 'admin'): ?>
            <!-- Admin picks which dentist's schedule to manage -->
            <form method="GET" class="card-box d-flex align-items-center gap-2 mb-3" style="padding:14px;">
                <label class="field-label mb-0">Managing schedule for:</label>
                <select name="dentist" class="form-select" style="max-width:280px;" onchange="this.form.submit()">
                    <?php foreach ($allDentists as $dn): ?>
                        <option value="<?= e($dn) ?>" <?= $dn===$selectedDentist?'selected':'' ?>><?= e($dn) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($allDentists)): ?><span class="text-muted2">No dentists yet.</span><?php endif; ?>
            </form>
        <?php endif; ?>

        <div class="row g-3 row-match"><!-- the list card is as tall as "Disable a Day"; only the list scrolls -->
            <!-- Add a day off -->
            <div class="col-lg-5">
                <div class="card-box">
                    <h5 class="mb-3">🚫 Disable a Day</h5>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_dayoff">
                        <input type="hidden" name="dentist_name" value="<?= e($selectedDentist) ?>">

                        <label class="field-label">Date to disable</label>
                        <input type="date" name="off_date" class="form-control mb-1" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                        <div class="text-muted2 mb-3" style="font-size:.78rem;">From tomorrow onwards. A day with <b>approved</b> appointments can't be disabled;
                            pending requests that day are moved to another dentist (or disapproved if nobody is free).</div>

                        <label class="field-label">Reason (optional)</label>
                        <input type="text" name="reason" class="form-control mb-3" placeholder="e.g. On leave, Seminar, Personal">

                        <button class="btn btn-teal w-100" <?= $selectedDentist===''?'disabled':'' ?>>+ Mark as Unavailable</button>
                    </form>
                    <div class="text-muted2 mt-2" style="font-size:.8rem;">
                        Tip: patients booking with <strong><?= e($selectedDentist ?: 'this dentist') ?></strong> will be blocked from choosing these dates.
                    </div>
                </div>
            </div>

            <!-- List of days off -->
            <div class="col-lg-7">
                <div class="card-box">
                    <h5 class="mb-3">📅 Your Unavailable Days</h5>
                    <div class="match-scroll">
                        <table class="data">
                            <thead><tr><th>Date</th><th>Day</th><th>Reason</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($daysOff as $d):
                                $isPast = ($d['off_date'] < date('Y-m-d'));
                            ?>
                                <tr style="<?= $isPast ? 'opacity:.5;' : '' ?>">
                                    <td><strong><?= date('M j, Y', strtotime($d['off_date'])) ?></strong></td>
                                    <td><?= date('l', strtotime($d['off_date'])) ?></td>
                                    <td><?= $d['reason'] ? e($d['reason']) : '<span class="text-muted2">—</span>' ?></td>
                                    <td>
                                        <form method="POST" onsubmit="return confirm('Remove this day off?')">
                                            <input type="hidden" name="action" value="remove_dayoff">
                                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                            <input type="hidden" name="back" value="<?= e($selectedDentist) ?>">
                                            <button class="btn btn-sm" style="background:#fbdcdc;color:#c0392b;">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($daysOff)): ?>
                                <tr><td colspan="4" class="text-center text-muted2 py-3">No days off set. You're available every day.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
<script>startClock();</script>
</body>
</html>
