<?php
// ============================================================
//  NO-SHOW SCANNER  (includes/noshow_check.php)
// ============================================================
//  Finds appointments that look like the patient never turned up,
//  and flags them for a staff member to confirm.
//
//  WHY IT RUNS ON PAGE LOAD, NOT ON A TIMER
//  ----------------------------------------
//  This system runs on XAMPP on the clinic's own computer. A
//  scheduled task at 11 PM would never fire, because the computer
//  is switched off at night. So instead the scan runs the FIRST
//  time anyone opens the system on a new day, and it catches up on
//  every day that was missed while the computer was off.
//
//  HOW IT DECIDES
//  --------------
//  The system cannot actually see who walked in. It can only look
//  at what was recorded, so it looks for EVIDENCE of the visit:
//     - a chart session dated that day (even just "Check-up")
//     - a treatment recorded that day
//     - a clinical note written that day
//
//  Evidence found  -> the patient clearly came; quietly mark it
//                     Completed. Nobody is bothered.
//  No evidence     -> mark it "Needs Review". NO email is sent and
//                     NO penalty is applied. A staff member decides.
// ============================================================

function run_noshow_scan($pdo, $graceDays = 1) {
    // ---- Only scan once a day ----
    // We remember the last scan date in settings so that opening ten
    // pages in a row does not run ten scans.
    $today = date('Y-m-d');
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='noshow_last_scan'");
        $st->execute();
        if ($st->fetchColumn() === $today) return 0;   // already done today
    } catch (Throwable $e) { return 0; }

    // ---- Which appointments look missed? ----
    // Still 'Confirmed' even though the date passed (plus a grace period
    // so the dentist has time to encode yesterday's visits).
    try {
        $stmt = $pdo->prepare(
            "SELECT id, patient_id, appointment_date
               FROM appointments
              WHERE status = 'Confirmed'
                AND appointment_date < DATE_SUB(CURDATE(), INTERVAL ? DAY)"
        );
        $stmt->execute([(int)$graceDays]);
        $candidates = $stmt->fetchAll();
    } catch (Throwable $e) { return 0; }

    $flagged = 0;
    foreach ($candidates as $a) {
        $pid  = (int)$a['patient_id'];
        $date = $a['appointment_date'];

        $attended = ($pid > 0) ? noshow_has_evidence($pdo, $pid, $date) : false;

        if ($attended) {
            // The visit was clearly recorded — close it quietly.
            $pdo->prepare("UPDATE appointments SET status='Completed' WHERE id=?")
                ->execute([$a['id']]);
        } else {
            // Nothing on record. Ask a human before doing anything.
            $pdo->prepare("UPDATE appointments SET status='Needs Review' WHERE id=?")
                ->execute([$a['id']]);
            $flagged++;
        }
    }

    // Remember that today's scan is done.
    try {
        $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('noshow_last_scan', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([$today]);
    } catch (Throwable $e) { /* ignore */ }

    return $flagged;
}

// Was anything at all recorded for this patient on this date?
// Any one of these proves the patient was actually in the clinic.
function noshow_has_evidence($pdo, $patientId, $date) {
    // A dental chart session dated that day — this is the main one.
    // The dentist creates one per visit, even if no tooth changed
    // (e.g. titled "Check-up" or "Just cleaning").
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM chart_sessions WHERE patient_id=? AND visit_date=?");
        $q->execute([$patientId, $date]);
        if ((int)$q->fetchColumn() > 0) return true;
    } catch (Throwable $e) {}

    // A treatment recorded that day.
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM treatments WHERE patient_id=? AND treatment_date=?");
        $q->execute([$patientId, $date]);
        if ((int)$q->fetchColumn() > 0) return true;
    } catch (Throwable $e) {}

    // A clinical note written that day.
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM clinical_notes WHERE patient_id=? AND DATE(created_at)=?");
        $q->execute([$patientId, $date]);
        if ((int)$q->fetchColumn() > 0) return true;
    } catch (Throwable $e) {}

    return false;
}

// ============================================================
//  HOW MANY MISSED VISITS COUNT AGAINST A PATIENT?
// ============================================================
//  Two rules apply, and both make the count fairer:
//
//  1. ROLLING WINDOW — only misses within the last N months count.
//     A patient of five years who missed three visits across all of
//     them is not a problem patient, so old misses age out on their
//     own. Real clinics use "3 missed visits in the last 12 months".
//
//  2. STAFF RESET — if staff have spoken to the patient and restored
//     their booking, only misses AFTER that moment count.
//
//  Everything (booking block, patient list badge, warning emails)
//  goes through this one function, so the rule can never drift apart
//  between screens.
// ============================================================
define('NOSHOW_WINDOW_MONTHS', 12);

function patient_noshow_count($pdo, $patientId, $months = NOSHOW_WINDOW_MONTHS) {
    $patientId = (int)$patientId;
    if ($patientId <= 0) return 0;

    try {
        // Where does the counting start? The later of:
        //   - the rolling window, and
        //   - the last time staff restored this patient's booking.
        $cutoff = date('Y-m-d', strtotime("-$months months"));

        $rs = $pdo->prepare("SELECT noshow_reset_at FROM patients WHERE id = ?");
        $rs->execute([$patientId]);
        $resetAt = $rs->fetchColumn();
        if ($resetAt) {
            $resetDay = substr($resetAt, 0, 10);
            if ($resetDay > $cutoff) $cutoff = $resetDay;
        }

        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM appointments
              WHERE patient_id = ? AND status = 'No-show' AND appointment_date > ?"
        );
        $q->execute([$patientId, $cutoff]);
        return (int)$q->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

// ============================================================
//  FREQUENT CANCELLATIONS
// ============================================================
//  Appointments the PATIENT cancelled inside the same rolling window,
//  counted only after staff last reviewed/restored the patient
//  (patients.cancel_reset_at). CANCEL_LIMIT of them pauses online
//  booking until an admin or staff member reviews the patient on the
//  No-Show Report ("Frequent Cancellations" tab).
// ============================================================
define('CANCEL_LIMIT', 3);

function patient_cancel_count($pdo, $patientId, $months = NOSHOW_WINDOW_MONTHS) {
    $patientId = (int)$patientId;
    if ($patientId <= 0) return 0;
    try {
        $cutoff = date('Y-m-d H:i:s', strtotime("-$months months"));
        $rs = $pdo->prepare("SELECT cancel_reset_at FROM patients WHERE id = ?");
        $rs->execute([$patientId]);
        $resetAt = $rs->fetchColumn();
        if ($resetAt && $resetAt > $cutoff) $cutoff = $resetAt;
        $q = $pdo->prepare("SELECT COUNT(*) FROM appointments
                             WHERE patient_id = ? AND status = 'Cancelled' AND cancelled_by = 'patient'
                               AND COALESCE(cancelled_at, created_at) > ?");
        $q->execute([$patientId, $cutoff]);
        return (int)$q->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

