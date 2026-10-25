<?php
// ============================================================
//  LATE ARRIVALS  (includes/late.php)
// ============================================================
//  A confirmed patient is LATE once their appointment time plus the grace
//  period has passed and they are not marked as arrived yet. The grace
//  period is set in System → Clinic Hours (default 15 minutes).
//
//  Today's Appointments (Dashboard / Appointments) then show "⏰ 18 min late"
//  so the front desk knows it may see the patients already waiting, plus:
//     ✓ Arrived (late)          — the minutes late are kept (appointments.late_minutes)
//     ⏱ Move to later today     — a free time with the same dentist
//     ✗ No-show                 — counted like a confirmed no-show
//  Late arrivals have their own tab in the No-Show Report, and the rule is
//  in the booking confirmation, the "confirmed" email and the reminder.
// ============================================================

const LATE_GRACE_DEFAULT = 15;

function ensure_late_schema($pdo) {
    try {
        if (!$pdo->query("SHOW COLUMNS FROM appointments LIKE 'late_minutes'")->fetch()) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN late_minutes INT NULL DEFAULT NULL");
        }
    } catch (Throwable $e) {}
}

/** The grace period in minutes (System → Clinic Hours). */
function late_grace_minutes($pdo) {
    static $g = null;
    if ($g === null) {
        $v = false;
        try { $v = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'late_grace_minutes'")->fetchColumn(); }
        catch (Throwable $e) {}
        $g = ($v !== false && ctype_digit(trim((string)$v))) ? max(5, min(60, (int)$v)) : LATE_GRACE_DEFAULT;
    }
    return $g;
}

/** The rule as patients read it (emails, booking page). */
function late_rule_text($pdo) {
    return 'Please arrive on time. If you are more than ' . late_grace_minutes($pdo) . ' minutes late, we may see the next '
         . 'patient first, and you may need to wait or reschedule.';
}

/** Minutes past the appointment time right now (any day/status), or null if the time can't be read. */
function minutes_past_appointment(array $a) {
    $t = strtotime(($a['appointment_date'] ?? '') . ' ' . ($a['appointment_time'] ?? ''));
    return $t ? (int)floor((time() - $t) / 60) : null;
}

/** How late a patient is now: today, confirmed, not arrived, past the grace period. 0 = not late. */
function minutes_late($pdo, array $a) {
    if (($a['appointment_date'] ?? '') !== date('Y-m-d') || ($a['status'] ?? '') !== 'Confirmed' || !empty($a['arrived_at'])) return 0;
    $m = minutes_past_appointment($a);
    return ($m !== null && $m >= late_grace_minutes($pdo)) ? $m : 0;
}

/** "⏰ 18 min late" (or "⏰ 1 h 5 min late"). */
function late_label($m) {
    return '⏰ ' . ($m >= 60 ? intdiv($m, 60) . ' h ' . ($m % 60 ? ($m % 60) . ' min ' : '') : $m . ' min ') . 'late';
}
function late_badge($pdo, array $a) {
    $m = minutes_late($pdo, $a);
    return $m ? ' <span class="badge-pill late-badge" title="Past the ' . late_grace_minutes($pdo)
              . '-minute grace period — you may see the patients who are already waiting">' . late_label($m) . '</span>' : '';
}

/** Free times later today with the same dentist (one-hour visits). */
function free_times_later_today($pdo, array $a) {
    if (!function_exists('clinic_time_slots')) require_once __DIR__ . '/treatments.php';
    if (!function_exists('slots_blocked_by'))  require_once __DIR__ . '/assign.php';
    $q = $pdo->prepare("SELECT appointment_time FROM appointments WHERE dentist = ? AND appointment_date = CURDATE()
                          AND status IN ('Pending','Confirmed','Arrived') AND id <> ?");
    $q->execute([(string)$a['dentist'], (int)$a['id']]);
    $slots   = clinic_time_slots($pdo);
    $blocked = slots_blocked_by($slots, $q->fetchAll(PDO::FETCH_COLUMN));
    $out = [];
    foreach ($slots as $s) {
        if (in_array($s, $blocked, true)) continue;
        if (strtotime(date('Y-m-d') . ' ' . $s) <= time()) continue;     // already started
        $out[] = $s;
    }
    return $out;
}

/**
 * The "⋯" menu beside a late patient's Arrived button: move to a later free
 * time today, or mark as a no-show. $back: where to return ('dashboard' / 'appointments').
 */
function late_actions_html($pdo, array $a, $back = 'dashboard') {
    if (!minutes_late($pdo, $a)) return '';
    $id   = (int)$a['id'];
    $name = htmlspecialchars($a['patient_name'], ENT_QUOTES, 'UTF-8');
    $free = free_times_later_today($pdo, $a);
    $h  = '<details class="late-menu" onclick="event.stopPropagation()"><summary class="btn btn-sm btn-light" title="Late: move to later today, or no-show" data-keep-text>⋯</summary><div class="late-pop">';
    $h .= '<div class="late-pop-title">' . $name . ' is ' . mb_substr(late_label(minutes_late($pdo, $a)), 2) . '</div>';
    if ($free) {
        $h .= '<form method="POST" action="appointments" class="m-0 mb-2"><input type="hidden" name="action" value="move_today">'
            . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="goto" value="' . $back . '">'
            . '<label class="late-pop-label">⏱ Move to a later time today</label><div class="d-flex gap-1">'
            . '<select name="new_time" class="form-select form-select-sm">';
        foreach ($free as $t) $h .= '<option>' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</option>';
        $h .= '</select><button class="btn btn-sm btn-teal" data-keep-text>Move</button></div></form>';
    } else {
        $h .= '<div class="late-pop-label mb-2">No free time later today with ' . htmlspecialchars((string)$a['dentist'], ENT_QUOTES, 'UTF-8')
            . ' — reschedule from Appointments if needed.</div>';
    }
    $h .= '<form method="POST" action="appointments" class="m-0" onsubmit="return confirm(\'Mark ' . addslashes($name)
        . ' as a no-show? It counts against the patient, the same as a confirmed no-show.\')">'
        . '<input type="hidden" name="action" value="late_noshow"><input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="goto" value="' . $back . '">'
        . '<button class="btn btn-sm w-100" style="background:#fbdcdc;color:#c0392b;" data-keep-text>✗ No-show</button></form>';
    return $h . '</div></details>';
}

/** Styles for the late badge and menu (print once per page). */
function late_styles() {
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<style>
.late-badge { background: #fde8e4; color: #c0392b; font-weight: 600; white-space: nowrap; }
.late-menu { position: relative; display: inline-block; vertical-align: middle; }
.late-menu > summary { list-style: none; padding: 2px 9px; font-weight: 700; }
.late-menu > summary::-webkit-details-marker { display: none; }
/* fixed (placed by the script below), so a scrolling table cannot cut it off */
.late-pop { position: fixed; z-index: 9500; width: 250px; background: #fff; border: 1px solid #e3e9ee;
            border-radius: 12px; box-shadow: 0 12px 30px rgba(12,50,48,.16); padding: 12px; text-align: left; white-space: normal; animation: latePop .15s ease-out; }
@keyframes latePop { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
.late-pop-title { font-weight: 700; font-size: .85rem; color: #c0392b; margin-bottom: 8px; }
.late-pop-label { display: block; font-size: .76rem; color: #6b7a86; margin-bottom: 4px; }
</style>
<script>
(function () {
    function closeAll(except) {
        document.querySelectorAll("details.late-menu[open]").forEach(function (d) { if (d !== except) d.removeAttribute("open"); });
    }
    document.addEventListener("click", function (e) {      /* tapping elsewhere closes an open late menu */
        document.querySelectorAll("details.late-menu[open]").forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute("open"); });
    });
    /* place the menu under its "⋯" button (or above it near the bottom of the screen) */
    document.addEventListener("toggle", function (e) {
        var d = e.target;
        if (!d.matches || !d.matches("details.late-menu") || !d.open) return;
        closeAll(d);
        var pop = d.querySelector(".late-pop"), r = d.querySelector("summary").getBoundingClientRect();
        var w = pop.offsetWidth || 250, h = pop.offsetHeight || 200;
        var left = Math.max(8, Math.min(window.innerWidth - w - 8, r.right - w));
        var top = r.bottom + 6;
        if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 6);
        pop.style.left = left + "px"; pop.style.top = top + "px";
    }, true);
    /* scrolling or resizing moves the button away: close the menu */
    window.addEventListener("scroll", function (e) {
        if (e.target && e.target.closest && e.target.closest(".late-pop")) return;
        closeAll(null);
    }, true);
    window.addEventListener("resize", function () { closeAll(null); });
})();
</script>';
}

/**
 * Confirmed no-show: status, the patient's portal warning ("missed X of 3"),
 * and on the 3rd the email that online booking is blocked. Used by the
 * No-Show Report (Needs Review) and by "✗ No-show" for a late patient.
 * Returns [patient name, note for the message].
 */
function confirm_noshow($pdo, $id) {
    if (!function_exists('mail_is_ready'))     require_once __DIR__ . '/mailer.php';
    if (!function_exists('message_catalogue')) require_once __DIR__ . '/message_templates.php';
    require_once __DIR__ . '/patient_notices.php';
    $pdo->prepare("UPDATE appointments SET status='No-show' WHERE id=?")->execute([(int)$id]);
    $q = $pdo->prepare(
        "SELECT a.patient_name, a.appointment_date, a.appointment_time, a.treatment,
                p.id AS pid, COALESCE(NULLIF(p.email,''), g.email) AS email
           FROM appointments a
      LEFT JOIN patients p ON a.patient_id = p.id
      LEFT JOIN patients g ON g.id = p.guardian_patient_id
          WHERE a.id = ?");
    $q->execute([(int)$id]);
    $row = $q->fetch();
    $note = '';
    if ($row && !empty($row['pid'])) {
        $holderId = account_holder_id($pdo, $row['pid']);
        $missed   = account_noshow_total($pdo, $holderId);
        $whenTxt  = date('F j, Y', strtotime($row['appointment_date'])) . ' at ' . $row['appointment_time'];
        $isSelf   = ($holderId === (int)$row['pid']);
        $n = noshow_notice($missed, $isSelf ? 'You' : $row['patient_name'], $whenTxt);
        add_patient_notice($pdo, $holderId, 'noshow', $n['level'], $n['title'], $n['body']);
        $note = " They will see a warning ($missed of 3) when they open their portal.";
        if ($missed === 3 && !empty($row['email']) && mail_is_ready($pdo)) {
            $cat = message_catalogue()['noshow_final'];
            [$subject, $bodyHtml] = tpl_message($pdo, 'noshow_final', $cat['subject'], $cat['body'], [
                'patient'   => $row['patient_name'],
                'date'      => date('l, F j, Y', strtotime($row['appointment_date'])),
                'time'      => $row['appointment_time'],
                'treatment' => $row['treatment'],
                'missed'    => $missed,
                'clinic'    => clinic_name($pdo),
            ]);
            $err = '';
            $note .= send_mail($pdo, $row['email'], $subject, $bodyHtml, $err, 'noshow_final')
                   ? ' Online booking is now blocked and they were emailed.'
                   : " Online booking is now blocked (the email could not be sent — $err).";
        }
    }
    return [$row['patient_name'] ?? ('Appointment #' . (int)$id), $note];
}
