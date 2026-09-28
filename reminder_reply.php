<?php
// ============================================================
//  REMINDER REPLY  (reminder_reply.php)  — no login needed
// ============================================================
//  Opened from the buttons in the day-before reminder email:
//     reminder_reply?id=12&do=confirm&t=<token>
//     reminder_reply?id=12&do=cancel&t=<token>
//  The signed token (includes/reminders.php) proves the link came from
//  our email. Opening the link changes NOTHING — the patient presses a
//  button on this page first (email scanners open links automatically).
//    confirm -> appointments.patient_confirmed_at is set
//    cancel  -> the appointment is cancelled by the patient (counts like
//               a portal cancellation) and the clinic is emailed
// ============================================================
require_once 'config/auth.php';
require_once 'includes/reminders.php';

$id  = (int)($_REQUEST['id'] ?? 0);
$do  = ($_REQUEST['do'] ?? '') === 'cancel' ? 'cancel' : 'confirm';
$tok = (string)($_REQUEST['t'] ?? '');

$appt = null; $state = 'invalid'; $msg = '';
if ($id > 0 && reminder_token_ok($pdo, $id, $do, $tok)) {
    $q = $pdo->prepare("SELECT a.*, COALESCE(g.name, p.name) AS account_name FROM appointments a
                          LEFT JOIN patients p ON p.id = a.patient_id
                          LEFT JOIN patients g ON g.id = p.guardian_patient_id WHERE a.id = ?");
    $q->execute([$id]);
    $appt = $q->fetch();
    if (!$appt) {
        $state = 'invalid';
    } elseif (!in_array($appt['status'], ['Pending','Confirmed'], true)
              || strtotime($appt['appointment_date'] . ' ' . $appt['appointment_time']) < time()) {
        $state = 'closed';            // already cancelled, completed, or the time has passed
    } else {
        $state = 'ask';
    }
}

if ($state === 'ask' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($do === 'confirm') {
        $pdo->prepare("UPDATE appointments SET patient_confirmed_at = COALESCE(patient_confirmed_at, NOW()) WHERE id = ?")
            ->execute([$id]);
        log_activity($pdo, 'Patient confirmed attendance', $appt['patient_name'] . ' — '
                     . date('M j, Y', strtotime($appt['appointment_date'])) . ' ' . $appt['appointment_time'] . ' (reminder email)');
        $state = 'confirmed';
    } else {
        $reason = trim(mb_substr((string)($_POST['reason'] ?? ''), 0, 200)) ?: 'Cancelled from the reminder email';
        $pdo->prepare("UPDATE appointments SET status='Cancelled', cancelled_at=NOW(), cancelled_by='patient', cancel_reason=?
                        WHERE id = ? AND status IN ('Pending','Confirmed')")->execute([$reason, $id]);
        try { notify_clinic_of_cancellation($pdo, $appt, $appt['account_name'] ?: $appt['patient_name'], $reason); } catch (Throwable $e) {}
        log_activity($pdo, 'Cancelled appointment', $appt['patient_name'] . ' — '
                     . date('M j, Y', strtotime($appt['appointment_date'])) . ' ' . $appt['appointment_time'] . ' (reminder email) — ' . $reason);
        $state = 'cancelled';
    }
}

$page_title = 'Your appointment';
$hide_hamburger = true;
include 'includes/head.php';
$when = $appt ? date('l, F j, Y', strtotime($appt['appointment_date'])) . ' at ' . $appt['appointment_time'] : '';
?>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background:#eef2f5;">
  <div class="card-box" style="max-width:460px;width:100%;text-align:center;padding:30px 26px;">
    <div style="font-size:2.2rem;">🦷</div>
    <h4 style="color:var(--teal);margin:6px 0 14px;"><?= e(clinic_name($pdo)) ?></h4>

    <?php if ($state === 'invalid'): ?>
        <p>This link is not valid. Please use the button from your latest reminder email, or contact the clinic.</p>

    <?php elseif ($state === 'closed'): ?>
        <p>This appointment can no longer be changed here (status: <b><?= e(status_label($appt['status'])) ?></b>).</p>
        <p class="text-muted2">If you need help, please contact the clinic.</p>

    <?php elseif ($state === 'ask' && $do === 'confirm'): ?>
        <p>Please confirm that <b><?= e($appt['patient_name']) ?></b> will come to the appointment on</p>
        <p style="font-size:1.1rem;"><b><?= e($when) ?></b><br><span class="text-muted2"><?= e($appt['treatment']) ?> · <?= e($appt['dentist'] ?: 'To be assigned') ?></span></p>
        <form method="POST">
            <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="confirm"><input type="hidden" name="t" value="<?= e($tok) ?>">
            <button class="btn btn-teal w-100 mb-2">✓ Yes, I'll be there</button>
        </form>
        <a href="<?= e(app_url('reminder_reply?id=' . $id . '&do=cancel&t=' . reminder_token($pdo, $id, 'cancel'))) ?>" class="small" style="color:#c0392b;">I can't make it</a>

    <?php elseif ($state === 'ask' && $do === 'cancel'): ?>
        <p>Cancel <b><?= e($appt['patient_name']) ?></b>'s appointment on</p>
        <p style="font-size:1.1rem;"><b><?= e($when) ?></b><br><span class="text-muted2"><?= e($appt['treatment']) ?></span></p>
        <form method="POST" class="text-start">
            <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="cancel"><input type="hidden" name="t" value="<?= e($tok) ?>">
            <label class="field-label" for="rr-reason">Reason <span class="text-muted2">(optional — helps us rebook you)</span></label>
            <input id="rr-reason" name="reason" class="form-control mb-3" maxlength="200" placeholder="e.g. I'm sick, schedule conflict">
            <button class="btn w-100 mb-2" style="background:#c0392b;color:#fff;">Yes, cancel this appointment</button>
        </form>
        <div class="text-muted2 small">Your slot will be offered to another patient. You can book again from your patient portal.</div>

    <?php elseif ($state === 'confirmed'): ?>
        <div style="font-size:2rem;">✅</div>
        <p><b>Thank you!</b> We'll see <?= e($appt['patient_name']) ?> on <b><?= e($when) ?></b>.</p>
        <p class="text-muted2">Please arrive about 10 minutes early.</p>

    <?php elseif ($state === 'cancelled'): ?>
        <div style="font-size:2rem;">🗓️</div>
        <p>The appointment on <b><?= e($when) ?></b> has been <b>cancelled</b>, and the clinic has been told.</p>
        <p class="text-muted2">Thank you for letting us know. You can book a new time from your patient portal.</p>
    <?php endif; ?>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
