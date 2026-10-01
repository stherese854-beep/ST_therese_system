<?php
// ============================================================
//  PATIENT POP-UP NOTICES  (includes/patient_notices.php)
// ============================================================
//  Warnings the patient must SEE, shown as a pop-up in their portal:
//
//   - after they cancel  -> "cancellation X of 3" (right away)
//   - after staff confirm a no-show -> "missed visit X of 3"
//     (the next time the patient opens the system)
//
//  Only the LAST one (the limit, when online booking is paused) is
//  also sent by email — see send_cancellation_warning() and noshow.php.
//
//  A notice is stored for the ACCOUNT HOLDER (the person who logs in),
//  since family members booked under the account have no login.
//  It shows once, then is marked seen.
// ============================================================

if (!function_exists('patient_cancel_count')) require_once __DIR__ . '/noshow_check.php';

function ensure_patient_notices_table($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS patient_notices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INT NOT NULL,
            kind VARCHAR(30) NOT NULL,
            level VARCHAR(10) NOT NULL DEFAULT 'warning',
            title VARCHAR(150) NOT NULL,
            body TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            seen_at DATETIME DEFAULT NULL,
            INDEX idx_unseen (patient_id, seen_at)
        )");
    } catch (Throwable $e) { /* table could not be made — notices are skipped */ }
}

// The patient who logs in for this record (a relative's record points to its guardian).
function account_holder_id($pdo, $patientId) {
    $q = $pdo->prepare("SELECT id, guardian_patient_id FROM patients WHERE id = ?");
    $q->execute([(int)$patientId]);
    $r = $q->fetch();
    if (!$r) return 0;
    return (int)($r['guardian_patient_id'] ?: $r['id']);
}

// Totals for the whole account — the same way book.php decides to pause booking.
function account_cancel_total($pdo, $holderId) {
    $n = 0;
    foreach (family_patient_ids($pdo, $holderId) as $fid) $n += patient_cancel_count($pdo, $fid);
    return $n;
}
function account_noshow_total($pdo, $holderId) {
    $n = 0;
    foreach (family_patient_ids($pdo, $holderId) as $fid) $n += patient_noshow_count($pdo, $fid);
    return $n;
}

function add_patient_notice($pdo, $holderId, $kind, $level, $title, $body) {
    if ((int)$holderId <= 0) return;
    ensure_patient_notices_table($pdo);
    try {
        $pdo->prepare("INSERT INTO patient_notices (patient_id, kind, level, title, body) VALUES (?,?,?,?,?)")
            ->execute([(int)$holderId, $kind, $level, $title, $body]);
    } catch (Throwable $e) {}
}

// "1st", "2nd", "3rd"...
function ordinal_word($n) {
    $n = (int)$n;
    if ($n % 100 >= 11 && $n % 100 <= 13) return $n . 'th';
    return $n . (['th','st','nd','rd'][$n % 10] ?? 'th');
}

// ---- Wording ----

// Shown BEFORE cancelling (inside the cancel dialog). $used = cancellations so far.
function cancel_prewarning($used) {
    $limit = CANCEL_LIMIT;
    $next  = $used + 1;
    if ($used >= $limit) {
        return ['level' => 'danger', 'title' => '⛔ Online booking is blocked',
                'body'  => "You have already used all $limit cancellations, so online booking on your account is "
                         . "blocked until the clinic reviews it. You can still cancel this appointment."];
    }
    if ($next >= $limit) {
        return ['level' => 'danger', 'title' => "⛔ Last cancellation ($next of $limit)",
                'body'  => "This is your last allowed cancellation. If you cancel, online booking on your account "
                         . "will be blocked until the clinic reviews it, and we will email you about it. "
                         . "If you only need another time, please use Reschedule instead."];
    }
    if ($used === 0) {
        return ['level' => 'info', 'title' => "⚠️ Before you cancel",
                'body'  => "You can cancel up to $limit appointments online (this counts every person you book for). "
                         . "This will be your 1st. After $limit cancellations, online booking is blocked until the "
                         . "clinic reviews your account. If you only need another time, use Reschedule instead."];
    }
    $left = $limit - $next;
    return ['level' => 'warning', 'title' => "⚠️ Cancellation $next of $limit",
            'body'  => "You have already cancelled $used time" . ($used === 1 ? '' : 's') . ". After this one you will have "
                     . "$left cancellation" . ($left === 1 ? '' : 's') . " left before online booking is blocked."];
}

// Shown AFTER cancelling (pop-up). $used includes the one just cancelled.
function cancel_notice($used) {
    $limit = CANCEL_LIMIT;
    if ($used > $limit) {
        return ['level' => 'danger', 'title' => '⛔ Online booking is still blocked',
                'body'  => "Your appointment was cancelled and the clinic has been notified.

"
                         . "Online booking on your account stays blocked until the clinic reviews it. "
                         . "Please call or visit the clinic to book your next visit."];
    }
    if ($used >= $limit) {
        return ['level' => 'danger', 'title' => '⛔ Online booking blocked',
                'body'  => "Your appointment was cancelled. You have now used all $limit of $limit cancellations, so your "
                         . "account is blocked from booking online. We have sent you an email about this.\n\n"
                         . "To book your next visit, please call or visit the clinic — our staff will gladly help you."];
    }
    $left = $limit - $used;
    if ($used === 1) {
        return ['level' => 'info', 'title' => "⚠️ Appointment cancelled (1 of $limit)",
                'body'  => "Your appointment was cancelled and the clinic has been notified.\n\n"
                         . "Please note: each account can cancel up to $limit appointments online. This was your 1st, "
                         . "so you have $left left. After $limit, online booking is blocked until the clinic reviews your "
                         . "account. Next time, if you only need another time, please use Reschedule."];
    }
    return ['level' => 'warning', 'title' => "⚠️ Cancellation $used of $limit",
            'body'  => "Your appointment was cancelled and the clinic has been notified.\n\n"
                     . "You have used $used of $limit cancellations — only $left left. "
                     . ($left === 1 ? 'One more cancellation will block online booking on your account.'
                                    : "After $limit, online booking is blocked until the clinic reviews your account.")];
}

// Shown after staff confirm a no-show. $missed includes this one.
function noshow_notice($missed, $who, $when) {
    $limit = 3;
    if ($missed > $limit) {
        return ['level' => 'danger', 'title' => '⛔ Online booking is still blocked',
                'body'  => "$who missed the appointment on $when. Online booking on your account stays blocked — "
                         . "please call or walk in to the clinic so our staff can help you book again."];
    }
    if ($missed >= $limit) {
        return ['level' => 'danger', 'title' => '⛔ Online booking blocked',
                'body'  => "$who missed the appointment on $when. This is the " . ordinal_word($missed)
                         . " missed appointment on your account, so online booking is now blocked. We have sent you an "
                         . "email about this.\n\nPlease call or walk in to the clinic — our staff will gladly help you book again."];
    }
    $left = $limit - $missed;
    return ['level' => $missed === 1 ? 'info' : 'warning',
            'title' => "⚠️ Missed appointment ($missed of $limit)",
            'body'  => "$who missed the appointment on $when. This is the " . ordinal_word($missed) . " missed appointment "
                     . "on your account.\n\n"
                     . ($left === 1 ? 'One more missed appointment will block online booking on your account.'
                                    : "After $limit missed appointments, online booking is blocked until the clinic reviews your account.")
                     . " If you cannot come, please cancel or reschedule at least 24 hours ahead."];
}

// ---- Showing them ----

// Unseen notices for the logged-in patient (marks them seen — each shows once).
function take_patient_notices($pdo, $userId) {
    ensure_patient_notices_table($pdo);
    try {
        $q = $pdo->prepare("SELECT n.id, n.level, n.title, n.body FROM patient_notices n
                              JOIN patients p ON p.id = n.patient_id
                             WHERE p.user_id = ? AND n.seen_at IS NULL ORDER BY n.id");
        $q->execute([(int)$userId]);
        $rows = $q->fetchAll();
        if ($rows) {
            $ids = array_column($rows, 'id');
            $pdo->prepare("UPDATE patient_notices SET seen_at = NOW() WHERE id IN (" . in_placeholders($ids) . ")")
                ->execute($ids);
        }
        return $rows;
    } catch (Throwable $e) { return []; }
}
