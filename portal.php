<?php
// ============================================================
//  PATIENT PORTAL  (portal.php)
// ============================================================
//  The logged-in patient's own dashboard.
//  Sections: Appointments / My Dental Chart / My Records /
//            My Profile / Announcements / My Activity /
//            Clinic Contact / My Archive.
//  (The old "Overview" section was removed.)
// ============================================================
require_once 'config/auth.php';
require_once 'includes/health_form.php';   // My Health Questionnaire
require_login(['patient']);
require_once 'includes/teeth.php';
require_once 'includes/mailer.php';   // clinic notifications
require_once 'includes/assign.php';   // appt_slot_is_open()

// Was this account JUST created (they landed here right after verifying
// their email)? If so, the greeting says "Welcome" instead of "Welcome
// back" — this flag is read once, then cleared, so it never shows again
// on later visits or other pages within the same session.
$justRegistered = !empty($_SESSION['just_registered']);
if ($justRegistered) unset($_SESSION['just_registered']);

// Show the "Welcome" pop-up once right after logging in — not on every
// page/view the patient navigates to afterwards.
$showWelcomePopup = !empty($_SESSION['show_welcome_popup']);
if ($showWelcomePopup) unset($_SESSION['show_welcome_popup']);

// Find this patient's record.
$stmt = $pdo->prepare("SELECT * FROM patients WHERE user_id=? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$me = $stmt->fetch();
$pid = $me['id'] ?? 0;
$firstName = explode(' ', $_SESSION['name'])[0];

// ---- My Archive ----
// "Delete" on a past appointment or a treatment record only hides it from
// THIS patient's portal (the clinic keeps the real record). Hidden items
// are listed under Clinic Contact > My Archive, where they can be restored.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS patient_archive (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        item_type ENUM('appointment','treatment') NOT NULL,
        item_id INT NOT NULL,
        archived_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_item (user_id, item_type, item_id)
    )");
} catch (Throwable $e) {}
$archivedIds = ['appointment' => [], 'treatment' => []];
try {
    $q = $pdo->prepare("SELECT item_type, item_id, archived_at FROM patient_archive WHERE user_id = ?");
    $q->execute([$_SESSION['user_id']]);
    foreach ($q->fetchAll() as $r) $archivedIds[$r['item_type']][(int)$r['item_id']] = $r['archived_at'];
} catch (Throwable $e) {}

// Their login account (for the verification badge + password change).
$acc = $pdo->prepare("SELECT * FROM users WHERE id=?");
$acc->execute([$_SESSION['user_id']]);
$account = $acc->fetch();

$pwError = '';

// ---------- Save profile / password changes ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Delete (move to My Archive) or restore a past appointment / treatment record.
    if (in_array($action, ['archive_item', 'restore_item'], true) && $pid) {
        $type = ($_POST['item_type'] ?? '') === 'treatment' ? 'treatment' : 'appointment';
        $id   = (int)($_POST['item_id'] ?? 0);
        $back = ($_POST['back'] ?? '') === 'archive' ? 'archive' : ($type === 'treatment' ? 'records' : 'appointments');
        // Only this account's own items, and only appointments that are over.
        if ($type === 'treatment') {
            $chk = $pdo->prepare("SELECT treatment_name AS label, treatment_date AS d FROM treatments WHERE id = ? AND patient_id = ?");
            $chk->execute([$id, $pid]);
        } else {
            $fam = family_patient_ids($pdo, $pid);
            $chk = $pdo->prepare("SELECT treatment AS label, appointment_date AS d FROM appointments
                                   WHERE id = ? AND patient_id IN (" . in_placeholders($fam) . ")
                                     AND (appointment_date < CURDATE()
                                          OR status IN ('Completed','Cancelled','No-show','Arrived','Expired','Needs Review'))");
            $chk->execute(array_merge([$id], $fam));
        }
        $item = $chk->fetch();
        if (!$item) {
            set_flash('That item could not be found.', 'error');
        } elseif ($action === 'archive_item') {
            $pdo->prepare("INSERT IGNORE INTO patient_archive (user_id, item_type, item_id) VALUES (?,?,?)")
                ->execute([$_SESSION['user_id'], $type, $id]);
            log_activity($pdo, 'Archived ' . $type, $item['label'] . ' (' . $item['d'] . ')');
            set_flash('Moved to My Archive. You can restore it from Clinic Contact › My Archive.');
        } else {
            $pdo->prepare("DELETE FROM patient_archive WHERE user_id = ? AND item_type = ? AND item_id = ?")
                ->execute([$_SESSION['user_id'], $type, $id]);
            log_activity($pdo, 'Restored ' . $type, $item['label'] . ' (' . $item['d'] . ')');
            set_flash('Restored.');
        }
        header("Location: portal?view=" . $back); exit;
    }

    // Patient edits their own personal information.
    // Patient updates their own health questionnaire.
    if ($action === 'save_health' && $pid) {
        [$hfNew, $hfErr] = health_form_from_post($_POST);
        if ($hfErr !== '') {
            set_flash($hfErr, 'error');
        } else {
            save_patient_health($pdo, $pid, $hfNew);
            log_activity($pdo, 'Updated health questionnaire', 'Own record');
            set_flash('Your health questionnaire was updated.');
        }
        header("Location: portal?view=profile#my-health"); exit;
    }

    if ($action === 'save_profile' && $pid) {
        [$cleanPhone, $phoneError] = validate_phone($_POST['phone'] ?? '');
        if ($phoneError === '') $phoneError = birth_date_error($_POST['dob'] ?? '');   // at least 2 years old
        if ($phoneError !== '') {
            set_flash($phoneError, 'error');
            header("Location: portal?view=profile"); exit;
        }
        $first = trim($_POST['first_name'] ?? '');
        $last  = trim($_POST['last_name'] ?? '');
        $name  = trim("$first $last");

        $pdo->prepare("UPDATE patients SET name=?, phone=?, age=?, date_of_birth=?, blood_type=?,
                       address=?, medical_history=?, medical_alert=? WHERE id=?")
            ->execute([
                $name,
                $cleanPhone,
                ($_POST['age'] !== '' ? (int)$_POST['age'] : null),
                ($_POST['dob'] ?: null),
                trim($_POST['blood_type']),
                trim($_POST['address']),
                trim($_POST['medical_history']),
                trim($_POST['medical_alert']),
                $pid
            ]);
        // keep the login account's name in step
        $pdo->prepare("UPDATE users SET name=? WHERE id=?")->execute([$name, $_SESSION['user_id']]);
        $_SESSION['name'] = $name;

        log_activity($pdo, 'Updated profile', 'Own profile');
        set_flash('Your profile has been updated.');
        header("Location: portal?view=profile"); exit;
    }

    // ---------- Upload a profile picture ----------
    if ($action === 'upload_avatar') {
        $dir = __DIR__ . '/uploads/avatars';
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                // Delete the old picture so the folder does not fill up.
                $old = $pdo->prepare("SELECT photo FROM users WHERE id=?");
                $old->execute([$_SESSION['user_id']]);
                $oldPath = $old->fetchColumn();
                if ($oldPath && is_file(__DIR__ . '/' . $oldPath)) @unlink(__DIR__ . '/' . $oldPath);

                $fname = 'user_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], "$dir/$fname")) {
                    $pdo->prepare("UPDATE users SET photo=? WHERE id=?")
                        ->execute(['uploads/avatars/' . $fname, $_SESSION['user_id']]);
                    log_activity($pdo, 'Changed profile picture', 'Own account');
                    set_flash('Profile picture updated.');
                } else {
                    set_flash('Could not save the picture.', 'error');
                }
            } else {
                set_flash('Please choose an image (jpg, png, gif, webp).', 'error');
            }
        } else {
            set_flash('Please choose a picture first.', 'error');
        }
        header("Location: portal?view=profile"); exit;
    }

    // ---------- Remove the profile picture ----------
    if ($action === 'remove_avatar') {
        $old = $pdo->prepare("SELECT photo FROM users WHERE id=?");
        $old->execute([$_SESSION['user_id']]);
        $oldPath = $old->fetchColumn();
        if ($oldPath && is_file(__DIR__ . '/' . $oldPath)) @unlink(__DIR__ . '/' . $oldPath);
        $pdo->prepare("UPDATE users SET photo=NULL WHERE id=?")->execute([$_SESSION['user_id']]);
        log_activity($pdo, 'Removed profile picture', 'Own account');
        set_flash('Profile picture removed.', 'info');
        header("Location: portal?view=profile"); exit;
    }

    // ---------- Leave a review about the clinic / system ----------
    // ---------- Patient cancels their own appointment ----------
    // The clinic's policy allows self-service cancellation up to 24 hours
    // before the visit, so the slot can still be offered to someone else.
    // Closer than that, the patient is asked to phone the clinic.
    // ---------- Patient moves their own appointment to another slot ----------
    // Same 24-hour rule as cancelling: close to the visit there is no longer
    // time to rearrange the clinic's day, so they must telephone instead.
    // A moved appointment returns to "Pending", because the clinic has to
    // approve the new slot just as it approved the first one.
    if ($action === 'reschedule_appointment') {
        $aid     = (int)($_POST['appointment_id'] ?? 0);
        $newDate = trim($_POST['new_date'] ?? '');
        $newTime = trim($_POST['new_time'] ?? '');
        $reason  = trim($_POST['reschedule_reason'] ?? '');

        $chk = $pdo->prepare(
            "SELECT id, appointment_date, appointment_time, treatment, dentist, patient_name, status
               FROM appointments WHERE id = ? AND patient_id IN (" . in_placeholders(family_patient_ids($pdo, $pid)) . ")"
        );
        $chk->execute(array_merge([$aid], family_patient_ids($pdo, $pid)));
        $appt = $chk->fetch();

        if (!$appt) {
            set_flash('That appointment could not be found.', 'error');
        } elseif (!in_array($appt['status'], ['Pending','Confirmed'])) {
            set_flash('That appointment can no longer be moved.', 'error');
        } elseif ($newDate === '' || $newTime === '') {
            set_flash('Please choose a new date and time.', 'error');
        } elseif ($reason === '') {
            set_flash('Please tell us why you need to move this appointment.', 'error');
        } else {
            $oldWhen = strtotime($appt['appointment_date'] . ' ' . $appt['appointment_time']);
            $newWhen = strtotime($newDate . ' ' . $newTime);
            $hours   = ($oldWhen - time()) / 3600;

            if ($hours < 24) {
                set_flash('Appointments can only be moved online up to 24 hours in advance. '
                        . 'Please call the clinic so we can help you.', 'error');
            } elseif ($newWhen <= time()) {
                set_flash('Please choose a date in the future.', 'error');
            } elseif (!appt_slot_is_open($pdo, $newDate, $newTime, $appt['dentist'], $aid)) {
                set_flash('That slot is not available. The clinic may be closed, the dentist may be away, '
                        . 'or someone already has that time. Please choose another.', 'error');
            } else {
                $movedFrom = date('M j, Y', $oldWhen) . ' ' . $appt['appointment_time'];
                $pdo->prepare(
                    "UPDATE appointments
                        SET appointment_date=?, appointment_time=?, status='Pending',
                            rescheduled_at=NOW(), rescheduled_from=?, reschedule_reason=?,
                            confirmed_at=NULL
                      WHERE id=?"
                )->execute([$newDate, $newTime, $movedFrom, $reason, $aid]);

                notify_clinic_of_reschedule($pdo, $appt, $me['name'] ?? 'A patient',
                                            $movedFrom, $newDate, $newTime, $reason);

                log_activity($pdo, 'Rescheduled appointment', $movedFrom . ' → ' . date('M j, Y', $newWhen) . ' ' . $newTime);
                set_flash('Your appointment was moved to ' . date('M j, Y', $newWhen) . ' at ' . $newTime
                        . '. It is now Pending until the clinic confirms the new time.');
            }
        }
        header("Location: portal?view=appointments"); exit;
    }

    if ($action === 'cancel_appointment') {
        $aid = (int)($_POST['appointment_id'] ?? 0);

        // It must be THIS patient's appointment, and still cancellable.
        $chk = $pdo->prepare(
            "SELECT id, appointment_date, appointment_time, treatment, dentist, patient_name, status
               FROM appointments
              WHERE id = ? AND patient_id IN (" . in_placeholders(family_patient_ids($pdo, $pid)) . ")"
        );
        $chk->execute(array_merge([$aid], family_patient_ids($pdo, $pid)));
        $appt = $chk->fetch();

        if (!$appt) {
            set_flash('That appointment could not be found.', 'error');
        } elseif (!in_array($appt['status'], ['Pending','Confirmed'])) {
            set_flash('That appointment can no longer be cancelled.', 'error');
        } else {
            // The patient must say why, so the clinic understands what happened
            // and can follow up if needed.
            $reason = trim($_POST['cancel_reason'] ?? '');
            $when   = strtotime($appt['appointment_date'] . ' ' . $appt['appointment_time']);
            $hours  = ($when - time()) / 3600;

            if ($reason === '') {
                set_flash('Please tell us why you are cancelling, so we can help you rebook.', 'error');
            } elseif ($hours < 24) {
                set_flash('Appointments can only be cancelled online up to 24 hours in advance. '
                        . 'Please call the clinic so we can help you.', 'error');
            } else {
                $pdo->prepare(
                    "UPDATE appointments
                        SET status='Cancelled', cancelled_at=NOW(), cancelled_by='patient', cancel_reason=?
                      WHERE id=?"
                )->execute([$reason, $aid]);

                // Tell the clinic, so a freed-up slot does not go unnoticed.
                notify_clinic_of_cancellation($pdo, $appt, $me['name'] ?? 'A patient', $reason);

                log_activity($pdo, 'Cancelled appointment', date('M j, Y', strtotime($appt['appointment_date'])) . ' ' . $appt['appointment_time'] . ($reason !== '' ? ' — ' . $reason : ''));
                set_flash('Your appointment was cancelled. The clinic has been notified.');
            }
        }
        header("Location: portal?view=appointments"); exit;
    }

    if ($action === 'save_review') {
        $rating  = max(1, min(5, (int)($_POST['rating'] ?? 5)));
        $comment = trim($_POST['comment'] ?? '');

        if (!patient_has_clinic_record($pdo, $pid)) {
            set_flash('You can leave a review after your first completed visit at the clinic.', 'error');
        } elseif ($comment === '') {
            set_flash('Please write your comment first.', 'error');
        } else {
            // One review per patient: update it if they already wrote one.
            $ex = $pdo->prepare("SELECT id FROM reviews WHERE user_id=?");
            $ex->execute([$_SESSION['user_id']]);
            if ($row = $ex->fetch()) {
                // Editing sends it back for approval, so nothing sneaks onto the public page.
                $pdo->prepare("UPDATE reviews SET rating=?, comment=?, status='Pending', created_at=NOW() WHERE id=?")
                    ->execute([$rating, $comment, $row['id']]);
            } else {
                $pdo->prepare("INSERT INTO reviews (user_id,patient_id,name,rating,comment,status)
                               VALUES (?,?,?,?,?, 'Pending')")
                    ->execute([$_SESSION['user_id'], $pid, $_SESSION['name'] ?? 'Patient', $rating, $comment]);
            }
            log_activity($pdo, 'Submitted review', $rating . ' star' . ($rating == 1 ? '' : 's'));
            set_flash('Thank you! Your review was sent to the clinic for approval.');
        }
        header("Location: portal?view=profile"); exit;
    }

    // Patient changes their own password.
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $account['password'])) {
            $pwError = 'Your current password is incorrect.';
        } elseif (($pwp = password_problem($new)) !== '') {
            $pwError = $pwp;
        } elseif ($new !== $confirm) {
            $pwError = 'The new passwords do not match.';
        } else {
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
            log_activity($pdo, 'Changed password', 'Own account');
            set_flash('Your password has been changed.');
            header("Location: portal?view=profile"); exit;
        }
    }
}

// Which section is open? Default is appointments so patients see their schedule first.
$view = $_GET['view'] ?? 'appointments';

// My Activity: only this patient's own entries.
$actQ    = trim($_GET['q'] ?? '');
$actDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : '';
$myActivity = ($view === 'activity') ? my_activity_rows($pdo, $actQ, $actDate, 100) : [];

// The patient's profile picture and their existing review (if any).
$avStmt = $pdo->prepare("SELECT photo FROM users WHERE id=?");
$avStmt->execute([$_SESSION['user_id']]);
$myPhoto = $avStmt->fetchColumn();

// May this patient leave a review? Only once they have a record at the
// clinic: a completed visit or a treatment on file.
$canReview = patient_has_clinic_record($pdo, $pid);

$rvStmt = $pdo->prepare("SELECT * FROM reviews WHERE user_id=?");
$rvStmt->execute([$_SESSION['user_id']]);
$myReview = $rvStmt->fetch();

// This patient's treatments + appointments.
$myTreatments = [];
$myAppts = [];
$upcomingAppts = [];   // today or later, still active
$pastAppts = [];       // before today, or completed/cancelled
if ($pid) {
    $t = $pdo->prepare("SELECT * FROM treatments WHERE patient_id=? ORDER BY treatment_date DESC");
    $t->execute([$pid]); $myTreatments = $t->fetchAll();

    // Their own appointments AND those of family members they booked for.
    $famIds = family_patient_ids($pdo, $pid);
    $a = $pdo->prepare("SELECT * FROM appointments WHERE patient_id IN (" . in_placeholders($famIds) . ")
                        ORDER BY appointment_date DESC, appointment_time ASC");
    $a->execute($famIds); $myAppts = $a->fetchAll();

    // Separate into "upcoming" and "past history" so the patient can see their
    // full visit history in one place (continuity of care).
    $today = date('Y-m-d');
    foreach ($myAppts as $a) {
        $isFuture = ($a['appointment_date'] >= $today);
        $isDone   = in_array($a['status'], ['Completed','Cancelled','No-show','Arrived','Expired','Needs Review'], true);
        if ($isFuture && !$isDone) {
            $upcomingAppts[] = $a;
        } else {
            $pastAppts[] = $a;
        }
    }
    // Upcoming should read soonest-first.
    usort($upcomingAppts, fn($x,$y) => strcmp($x['appointment_date'], $y['appointment_date']));
}

// Items the patient deleted go to My Archive instead of History / My Records.
$archivedAppts = array_values(array_filter($pastAppts, fn($a) => isset($archivedIds['appointment'][(int)$a['id']])));
$pastAppts     = array_values(array_filter($pastAppts, fn($a) => !isset($archivedIds['appointment'][(int)$a['id']])));
$archivedTreatments = array_values(array_filter($myTreatments, fn($t) => isset($archivedIds['treatment'][(int)$t['id']])));
$myTreatments       = array_values(array_filter($myTreatments, fn($t) => !isset($archivedIds['treatment'][(int)$t['id']])));

// Clinic contact details (the same ones shown on the public home page).
$clinicInfo = [];
try {
    foreach ($pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN
              ('clinic_name','clinic_phone','clinic_email','clinic_address','land_contact_phone',
               'land_contact_email','land_contact_address','land_contact_hours','operating_hours','land_contact_facebook')") as $r) {
        $clinicInfo[$r['setting_key']] = trim((string)$r['setting_value']);
    }
} catch (Throwable $e) {}
$ci = fn(...$keys) => array_reduce($keys, fn($c, $k) => $c !== '' ? $c : ($clinicInfo[$k] ?? ''), '');
$clinicName    = $ci('clinic_name') ?: 'St. Therese Dental Clinic';
$clinicPhone   = $ci('land_contact_phone', 'clinic_phone');
$clinicEmail   = $ci('land_contact_email', 'clinic_email');
$clinicAddress = $ci('land_contact_address', 'clinic_address');
$clinicHours   = $ci('land_contact_hours', 'operating_hours');
$clinicFacebook = facebook_url($ci('land_contact_facebook'))[0];

// A small "Delete" button that moves an item to My Archive (or restores it).
function archive_button($type, $id, $restore = false, $back = '') {
    $msg = $restore ? 'Put this back in your ' . ($type === 'treatment' ? 'records' : 'history') . '?'
                    : 'Delete this from your ' . ($type === 'treatment' ? 'records' : 'history') . "?\nIt will be moved to My Archive, where you can restore it.";
    return '<form method="POST" class="d-inline" onsubmit="return confirm(' . e(json_encode($msg)) . ')">'
         . '<input type="hidden" name="action" value="' . ($restore ? 'restore_item' : 'archive_item') . '">'
         . '<input type="hidden" name="item_type" value="' . e($type) . '">'
         . '<input type="hidden" name="item_id" value="' . (int)$id . '">'
         . ($back ? '<input type="hidden" name="back" value="' . e($back) . '">' : '')
         . ($restore ? '<button class="btn btn-sm btn-outline-success">↩ Restore</button>'
                     : '<button class="btn btn-sm btn-outline-danger" title="Move to My Archive">🗑 Delete</button>')
         . '</form>';
}

// ---------- Data for the reschedule dialog ----------
// Same half-hour slots the booking page offers, built from the clinic's hours.
$rsCfg = [];
try {
    foreach ($pdo->query("SELECT setting_key, setting_value FROM settings
                          WHERE setting_key IN ('clinic_open_days','clinic_open_time','clinic_close_time')") as $r) {
        $rsCfg[$r['setting_key']] = $r['setting_value'];
    }
} catch (Throwable $e) {}
$rsOpen  = $rsCfg['clinic_open_time']  ?? '09:00';
$rsClose = $rsCfg['clinic_close_time'] ?? '18:00';
$rsDays  = array_filter(array_map('trim', explode(',', $rsCfg['clinic_open_days'] ?? 'Mon,Tue,Wed,Thu,Fri,Sat')));

$rsSlots = [];
$t = strtotime($rsOpen); $endT = strtotime($rsClose);
while ($t + 60 * 60 <= $endT) { $rsSlots[] = date('h:i A', $t); $t += 60 * 60; }   // one-hour appointments
if (empty($rsSlots)) $rsSlots = ['09:00 AM'];

// The patient's dentist, their upcoming days off, and the slots already taken —
// so the dialog can grey out what is unavailable without asking the server again.
$rsDentist = $me['primary_dentist'] ?? '';
$rsDaysOff = [];
$rsTaken   = [];   // date => [times]
if ($rsDentist !== '') {
    try {
        $q = $pdo->prepare("SELECT off_date FROM dentist_daysoff
                             WHERE dentist_name = ? AND off_date >= CURDATE()");
        $q->execute([$rsDentist]);
        $rsDaysOff = $q->fetchAll(PDO::FETCH_COLUMN);

        $q = $pdo->prepare("SELECT appointment_date, appointment_time FROM appointments
                             WHERE dentist = ? AND status IN ('Pending','Confirmed','Arrived')
                               AND appointment_date >= CURDATE()");
        $q->execute([$rsDentist]);
        $busyByDate = [];
        foreach ($q->fetchAll() as $row) $busyByDate[$row['appointment_date']][] = $row['appointment_time'];
        foreach ($busyByDate as $d => $times) $rsTaken[$d] = slots_blocked_by($rsSlots, $times);   // one-hour gap
    } catch (Throwable $e) {}
}

// Clinic announcements (published ones) - the patient's notifications.
$news = $pdo->query("SELECT * FROM announcements WHERE status='Published' ORDER BY created_at DESC LIMIT 10")->fetchAll();

// Tooth map for the dental chart.
// ---------- Dental chart: the patient has ONE CHART PER VISIT ----------
// The patient can flip through every visit and see how their teeth improved.
$mySessions = $pid ? get_chart_sessions($pdo, $pid) : [];   // newest first

// Which visit is the patient looking at? Default = their latest.
$mySid = (int)($_GET['session'] ?? 0);
$myValid = array_map('intval', array_column($mySessions, 'id'));
if (!$mySid || !in_array($mySid, $myValid)) $mySid = $myValid[0] ?? 0;

$mySession = null;
foreach ($mySessions as $s) if ((int)$s['id'] === $mySid) $mySession = $s;

$toothMap = ($pid && $mySid) ? build_tooth_map($pdo, $pid, $mySid) : [];

// The visit before this one, so we can show what changed.
$myPrev = null;
foreach ($mySessions as $i => $s) {
    if ((int)$s['id'] === $mySid) { $myPrev = $mySessions[$i + 1] ?? null; break; }
}
$myPrevMap = $myPrev ? build_tooth_map($pdo, $pid, (int)$myPrev['id']) : [];

$myChanges = [];
if ($myPrev) {
    foreach (array_merge($UPPER_TEETH, $LOWER_TEETH) as $tt) {
        $was = $myPrevMap[$tt] ?? 'Healthy';
        $now = $toothMap[$tt]  ?? 'Healthy';
        if ($was !== $now) $myChanges[$tt] = [$was, $now];
    }
}
$myImproved = 0; $myWorsened = 0;
$badList = ['Decayed','Fractured','Impacted'];
foreach ($myChanges as $ch) {
    if (in_array($ch[0], $badList) && !in_array($ch[1], $badList)) $myImproved++;
    if (!in_array($ch[0], $badList) && in_array($ch[1], $badList)) $myWorsened++;
}

$counts = ['Healthy'=>0,'Decayed'=>0,'Filled'=>0,'Crowned'=>0,'Missing'=>0];
foreach (array_merge($UPPER_TEETH, $LOWER_TEETH) as $tt) {
    $s = $toothMap[$tt] ?? 'Healthy';
    if (isset($counts[$s])) $counts[$s]++;
}
$legendColors = ['Healthy'=>'#6bbf6b','Decayed'=>'#e0a92e','Filled'=>'#3b9ae0','Missing'=>'#e05b5b',
                 'Crowned'=>'#a06fd6','Extracted'=>'#999','Impacted'=>'#d99a00','Fractured'=>'#cc4444'];

$page_title = "Patient Portal";
include 'includes/head.php';
?>
<div class="app-wrap">
    <!-- Patient sidebar -->
    <aside class="sidebar">
        <div class="brand"><div class="logo">🦷</div><div>Patient Portal</div></div>
        <a class="nav-item <?= $view==='appointments'?'active':'' ?>" href="portal?view=appointments">📅 Appointments</a>
        <a class="nav-item <?= $view==='chart'?'active':'' ?>" href="portal?view=chart">🦷 My Dental Chart</a>
        <a class="nav-item <?= $view==='records'?'active':'' ?>" href="portal?view=records">📋 My Records</a>
        <a class="nav-item <?= $view==='news'?'active':'' ?>" href="portal?view=news">
            📣 Announcements <?php if ($news): ?><span class="badge-pill b-pending" style="font-size:.65rem;"><?= count($news) ?></span><?php endif; ?>
        </a>
        <div class="spacer"></div>
    </aside>

    <main class="main">
        <!-- Top bar -->
        <div class="card-box flex-between" style="padding:14px 20px;">
            <div class="clock"><span class="time" id="clock"></span><br><span id="clock-date"></span></div>
            <?php if ($view === 'appointments'): ?>
                <a href="book" class="btn btn-teal">+ Book Appointment</a>
            <?php endif; ?>
        </div>

        <!-- The clinic announcement banner only shows on the Announcements page
             itself now — it no longer follows the patient across every page. -->

        <?php if ($view === 'chart'): ?>
            <!-- Only appears on paper, so a printed chart identifies itself -->
            <div class="print-only" style="display:none;margin-bottom:14px;">
                <div style="font-weight:700;font-size:1.05rem;">
                    <?php
                        $cn = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='clinic_name'");
                        $cn->execute();
                        echo e($cn->fetchColumn() ?: 'St. Therese of Carmel Dental Clinic');
                    ?>
                </div>
                <div style="font-size:.9rem;">Dental Chart — <?= e($me['name'] ?? '') ?></div>
                <?php if ($mySession): ?>
                    <div style="font-size:.85rem;color:#555;">
                        Visit of <?= date('F j, Y', strtotime($mySession['visit_date'])) ?>
                        <?= $mySession['title'] ? ' · ' . e($mySession['title']) : '' ?>
                        <?= $mySession['created_by'] ? ' · ' . e($mySession['created_by']) : '' ?>
                    </div>
                <?php endif; ?>
                <div style="font-size:.78rem;color:#888;">Printed <?= date('M j, Y g:i A') ?></div>
            </div>
            <!-- ===== MY DENTAL CHART (read-only, one chart per visit) ===== -->
            <div class="row">
                <div class="col-lg-8">
                    <div class="card-box">
                        <div class="flex-between mb-1">
                            <h5 class="mb-0">My Dental Chart</h5>
                            <?php if ($mySessions): ?>
                                <button class="btn btn-sm btn-outline-teal no-print" onclick="printChart()"
                                        title="Print this visit's chart, or save it as a PDF">
                                    🖨 Print this chart
                                </button>
                            <?php endif; ?>
                        </div>

                        <?php if (count($mySessions) > 1): ?>
                            <p class="text-muted2 mb-2" style="font-size:.87rem;">
                                You have <strong><?= count($mySessions) ?> visits</strong> on record.
                                Tap a visit to see how your teeth looked that day.
                            </p>
                        <?php else: ?>
                            <p class="text-muted2 mb-2" style="font-size:.87rem;">Click any tooth to view its condition.</p>
                        <?php endif; ?>

                        <!-- Visit tabs -->
                        <?php if ($mySessions): ?>
                        <div class="sess-tabs no-print">
                            <?php $oldFirst = array_reverse($mySessions); ?>
                            <?php foreach ($oldFirst as $i => $s): ?>
                                <a class="sess-tab <?= (int)$s['id']===$mySid?'on':'' ?>"
                                   href="portal?view=chart&session=<?= $s['id'] ?>">
                                    <b>Visit <?= $i+1 ?><?= $i === count($oldFirst)-1 ? ' · latest' : '' ?></b>
                                    <small><?= date('M j, Y', strtotime($s['visit_date'])) ?></small>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <div class="text-center mb-1 mt-3"><span class="odo-section-label">Upper (Maxillary)</span></div>
                        <div class="odo-arch"><?php foreach ($UPPER_TEETH as $t) echo render_tooth($t, $toothMap[$t] ?? 'Healthy'); ?></div>
                        <div class="odo-midline">— MIDLINE —</div>
                        <div class="odo-arch"><?php foreach ($LOWER_TEETH as $t) echo render_tooth($t, $toothMap[$t] ?? 'Healthy'); ?></div>
                        <div class="text-center mt-1"><span class="odo-section-label">Lower (Mandibular)</span></div>
                        <div class="odo-legend">
                            <?php foreach ($TOOTH_STATUSES as $st): ?>
                                <span><i class="legend-dot" style="background:<?= $legendColors[$st] ?>"></i><?= $st ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- What changed since the previous visit -->
                    <?php if ($myPrev): ?>
                    <div class="card-box">
                        <h5 class="mb-1">📈 Your progress</h5>
                        <div class="text-muted2 mb-3" style="font-size:.84rem;">
                            Comparing <strong><?= date('M j, Y', strtotime($mySession['visit_date'])) ?></strong>
                            with your previous visit on <strong><?= date('M j, Y', strtotime($myPrev['visit_date'])) ?></strong>.
                        </div>

                        <?php if (empty($myChanges)): ?>
                            <p class="text-muted2 mb-0">Nothing changed between these two visits.</p>
                        <?php else: ?>
                            <div class="d-flex gap-2 mb-3 flex-wrap">
                                <?php if ($myImproved): ?>
                                    <span class="badge-pill b-completed">✅ <?= $myImproved ?> tooth/teeth treated</span>
                                <?php endif; ?>
                                <?php if ($myWorsened): ?>
                                    <span class="badge-pill b-cancelled">⚠️ <?= $myWorsened ?> need attention</span>
                                <?php endif; ?>
                                <span class="badge-pill b-progress"><?= count($myChanges) ?> change(s)</span>
                            </div>
                            <?php foreach ($myChanges as $tooth => $ch): ?>
                                <div class="chg-row">
                                    <span class="chg-t">#<?= e($tooth) ?></span>
                                    <span class="pill-sm" style="background:<?= $legendColors[$ch[0]] ?? '#999' ?>"><?= e($ch[0]) ?></span>
                                    <span class="text-muted2">→</span>
                                    <span class="pill-sm" style="background:<?= $legendColors[$ch[1]] ?? '#999' ?>"><?= e($ch[1]) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="col-lg-4">
                    <div class="card-box">
                        <h5>Select a tooth</h5>
                        <div id="tooth-panel"><p class="text-muted2">Click a tooth on the chart to view its status</p></div>
                    </div>

                    <!-- What the dentist recorded for this visit -->
                    <?php if ($mySession): ?>
                    <div class="card-box">
                        <h5>🗓 This Visit</h5>
                        <div class="flex-between py-1">
                            <span class="text-muted2">Date</span>
                            <strong><?= date('M j, Y', strtotime($mySession['visit_date'])) ?></strong>
                        </div>
                        <?php if ($mySession['title']): ?>
                            <div class="flex-between py-1">
                                <span class="text-muted2">Visit</span>
                                <strong><?= e($mySession['title']) ?></strong>
                            </div>
                        <?php endif; ?>
                        <?php if ($mySession['created_by']): ?>
                            <div class="flex-between py-1">
                                <span class="text-muted2">Dentist</span>
                                <strong><?= e($mySession['created_by']) ?></strong>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($mySession['notes'])): ?>
                            <div style="border-top:1px solid #eef2f2;margin-top:8px;padding-top:8px;">
                                <div class="text-muted2 mb-1" style="font-size:.78rem;">Dentist's notes</div>
                                <div style="font-size:.88rem;"><?= nl2br(e($mySession['notes'])) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="card-box">
                        <h5>Chart Summary</h5>
                        <?php foreach (['Healthy','Decayed','Filled','Crowned','Missing'] as $st): ?>
                            <div class="flex-between py-1"><span><i class="legend-dot" style="background:<?= $legendColors[$st] ?>"></i> <?= $st ?></span><strong><?= $counts[$st] ?></strong></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        <?php elseif ($view === 'appointments'): ?>
            <!-- ===== MY APPOINTMENTS: upcoming + full history ===== -->

            <!-- Clinic announcements with Hide/Show (shared with the staff dashboard) -->
            <?php $annList = $news; $annSeeAll = 'portal?view=news'; include 'includes/announcements_card.php'; ?>

            <!-- Upcoming -->
            <div class="card-box">
                <div class="flex-between mb-1">
                    <h5 class="mb-0">📅 Upcoming Appointments</h5>
                </div>
                <div class="text-muted2 mb-2" style="font-size:.85rem;">
                    ⏳ <strong>Pending</strong> means the dentist has not approved it yet. It becomes final once it shows <strong>Confirmed</strong>.
                </div>
                <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                <table class="data" style="min-width:620px;">
                    <thead><tr><th>Patient</th><th>Treatment</th><th>Date</th><th>Time</th><th>Dentist</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($upcomingAppts as $a): ?>
                        <tr>
                            <td><?= e($a['patient_name']) ?>
                                <?php if (!empty($a['booked_by'])): ?>
                                    <br><small class="text-muted2">booked by you<?= $a['relationship'] ? ' · '.e($a['relationship']) : '' ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= e($a['treatment']) ?>
                                <?php if (!empty($a['reason_for_visit'])): ?>
                                    <br><small class="text-muted2"><?= e($a['reason_for_visit']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= date('M j, Y', strtotime($a['appointment_date'])) ?></td>
                            <td class="date-blue"><?= e($a['appointment_time']) ?></td>
                            <td><?= e($a['dentist']) ?></td>
                            <td><span class="badge-pill b-<?= strtolower($a['status']) ?>"><?= e($a['status']) ?></span></td>
                            <td>
                                <?php
                                    // Cancelling online is allowed up to 24 hours before the visit.
                                    $apptTime = strtotime($a['appointment_date'] . ' ' . $a['appointment_time']);
                                    $hoursAway = ($apptTime - time()) / 3600;
                                    $canCancel = in_array($a['status'], ['Pending','Confirmed']) && $hoursAway >= 24;
                                ?>
                                <div class="d-flex gap-1 flex-wrap">
                                    <?php if ($a['status'] === 'Confirmed'): ?>
                                        <a href="slip?id=<?= $a['id'] ?>" target="_blank" class="btn btn-sm btn-outline-teal" style="white-space:nowrap;">🖨 Print</a>
                                    <?php endif; ?>

                                    <?php if ($canCancel): ?>
                                        <button type="button" class="btn btn-sm btn-light" style="color:#b8860b;white-space:nowrap;"
                                                onclick='openReschedule(<?= json_encode([
                                                    "id"    => $a["id"],
                                                    "date"  => $a["appointment_date"],
                                                    "time"  => $a["appointment_time"],
                                                    "treat" => $a["treatment"],
                                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Reschedule</button>
                                        <button type="button" class="btn btn-sm btn-light" style="color:#c0392b;white-space:nowrap;"
                                                onclick='openMyCancel(<?= json_encode([
                                                    "id"    => $a["id"],
                                                    "date"  => $a["appointment_date"],
                                                    "time"  => $a["appointment_time"],
                                                    "treat" => $a["treatment"],
                                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Cancel</button>
                                    <?php elseif (in_array($a['status'], ['Pending','Confirmed'])): ?>
                                        <span class="text-muted2" style="font-size:.74rem;white-space:nowrap;"
                                              title="Online changes close 24 hours before the visit">
                                            Call clinic to change
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($upcomingAppts)): ?>
                        <tr><td colspan="7" class="text-center text-muted2 py-4">
                            <div style="font-size:1.8rem;">📭</div>
                            No upcoming appointments.
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div><!-- /scroll-x -->
            </div>

            <!-- Past history -->
            <div class="card-box">
                <h5 class="mb-1">🗂 Appointment History</h5>
                <div class="text-muted2 mb-2" style="font-size:.85rem;">
                    A record of all your past visits<?= count($pastAppts) ? ' (' . count($pastAppts) . ')' : '' ?>.
                </div>
                <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                <table class="data" style="min-width:640px;">
                    <thead><tr><th>Patient</th><th>Treatment</th><th>Date</th><th>Time</th><th>Dentist</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($pastAppts as $a): ?>
                        <tr>
                            <td><?= e($a['patient_name']) ?></td>
                            <td><?= e($a['treatment']) ?>
                                <?php if ($a['status'] === 'Cancelled' && !empty($a['cancel_reason'])): ?>
                                    <br><small style="color:#c0392b;">
                                        We're sorry — this was cancelled by the clinic.<br>
                                        <em><?= e($a['cancel_reason']) ?></em>
                                    </small>
                                <?php elseif ($a['status'] === 'Cancelled' && ($a['cancelled_by'] ?? '') === 'patient'): ?>
                                    <br><small class="text-muted2">You cancelled this appointment.</small>
                                <?php endif; ?>
                            </td>
                            <td><?= date('M j, Y', strtotime($a['appointment_date'])) ?></td>
                            <td class="date-blue"><?= e($a['appointment_time']) ?></td>
                            <td><?= e($a['dentist']) ?></td>
                            <td><span class="badge-pill b-<?= strtolower($a['status']) ?>"><?= e($a['status']) ?></span></td>
                            <td class="text-end"><?= archive_button('appointment', $a['id']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($pastAppts)): ?>
                        <tr><td colspan="7" class="text-center text-muted2 py-4">
                            <div style="font-size:1.8rem;">🗂</div>
                            No past visits yet. Your visit history will appear here after your appointments.
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div><!-- /scroll-x -->
            </div>

        <?php elseif ($view === 'profile'): ?>
            <!-- ===== MY PROFILE (editable) ===== -->

            <!-- Profile picture -->
            <div class="card-box mb-3">
                <h5 class="mb-3">🖼️ Profile Picture</h5>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <?php if ($myPhoto && is_file(__DIR__ . '/' . $myPhoto)): ?>
                        <img src="<?= e($myPhoto) ?>" alt="Profile picture"
                             style="width:96px;height:96px;border-radius:50%;object-fit:cover;border:3px solid var(--teal-light);">
                    <?php else: ?>
                        <span class="avatar" style="width:96px;height:96px;font-size:2rem;background:var(--teal);">
                            <?= e(strtoupper(substr($_SESSION['name'] ?? 'P', 0, 1))) ?>
                        </span>
                    <?php endif; ?>

                    <div style="flex:1;min-width:250px;">
                        <form method="POST" enctype="multipart/form-data" class="mb-2">
                            <input type="hidden" name="action" value="upload_avatar">
                            <div class="d-flex gap-2">
                                <input type="file" name="avatar" class="form-control" accept="image/*" required>
                                <button class="btn btn-teal" style="white-space:nowrap;">⬆ Upload</button>
                            </div>
                        </form>
                        <?php if ($myPhoto): ?>
                            <form method="POST" onsubmit="return confirm('Remove your profile picture?')">
                                <input type="hidden" name="action" value="remove_avatar">
                                <button class="btn btn-sm btn-light" style="color:#c0392b;">🗑 Remove picture</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- My health questionnaire (editable) -->
            <?php [$myHf, $myHfAt] = $pid ? patient_health($pdo, $pid) : [[], null]; ?>
            <?= health_form_styles() ?>
            <div class="card-box mb-3" id="my-health">
                <div class="flex-between mb-1">
                    <h5 class="mb-0">🩺 My Health Questionnaire</h5>
                    <?php if ($myHfAt): ?><small class="text-muted2">Last updated <?= date('M j, Y', strtotime($myHfAt)) ?></small><?php endif; ?>
                </div>
                <div class="text-muted2 mb-2" style="font-size:.85rem;">
                    Keep this up to date so the dentist can treat you safely. It is also filled in for you when you book.
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="save_health">
                    <?= health_form_fields($myHf) ?>
                    <button class="btn btn-teal">💾 Save my health information</button>
                </form>
            </div>

            <!-- Leave a review -->
            <div class="card-box mb-3">
                <h5 class="mb-1">⭐ Rate Our Clinic</h5>
                <div class="text-muted2 mb-3" style="font-size:.85rem;">
                    Tell us about the clinic or this booking system. Once the clinic approves it,
                    your comment appears on our public homepage.
                </div>

                <?php if ($myReview): ?>
                    <div class="alert py-2 mb-3" style="font-size:.85rem;
                         background:<?= $myReview['status']==='Approved' ? '#eaf7ef' : '#fff6e0' ?>;
                         border:1px solid <?= $myReview['status']==='Approved' ? '#7fce9c' : 'var(--gold)' ?>;">
                        <?php if ($myReview['status'] === 'Approved'): ?>
                            ✅ Your review is <strong>live on the homepage</strong>. Thank you!
                        <?php elseif ($myReview['status'] === 'Hidden'): ?>
                            🚫 Your review is not being shown at the moment.
                        <?php else: ?>
                            ⏳ Your review is <strong>waiting for the clinic to approve it</strong>.
                        <?php endif; ?>
                        <div class="text-muted2 mt-1" style="font-size:.78rem;">Editing it below sends it for approval again.</div>
                    </div>
                <?php endif; ?>

                <?php if (!$canReview): ?>
                    <div class="alert alert-light border py-2 mb-0" style="font-size:.86rem;">
                        🦷 You can rate the clinic after your <strong>first completed visit</strong>.
                        Once the clinic marks an appointment as completed (or records a treatment), this form opens here.
                    </div>
                <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="save_review">

                    <label class="field-label">Your rating</label>
                    <select name="rating" class="form-select mb-3" style="max-width:240px;">
                        <?php $curR = (int)($myReview['rating'] ?? 5); ?>
                        <?php foreach ([5=>'★★★★★ Excellent',4=>'★★★★☆ Very good',3=>'★★★☆☆ Good',2=>'★★☆☆☆ Fair',1=>'★☆☆☆☆ Poor'] as $val=>$lbl): ?>
                            <option value="<?= $val ?>" <?= $curR===$val?'selected':'' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="field-label">Your comment</label>
                    <textarea name="comment" class="form-control mb-3" rows="4" required
                              placeholder="e.g. Booking online was so easy and the dentist was very gentle!"><?= e($myReview['comment'] ?? '') ?></textarea>

                    <button class="btn btn-teal"><?= $myReview ? '💾 Update My Review' : '⭐ Submit Review' ?></button>
                </form>
                <?php endif; ?>
            </div>

            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="card-box">
                        <h5 class="mb-3">👤 Personal Information</h5>
                        <form method="POST">
                            <input type="hidden" name="action" value="save_profile">
                            <div class="row g-3">
                                <div class="col-md-6"><label class="field-label">First Name</label>
                                    <input name="first_name" class="form-control" value="<?= e(explode(' ', $me['name'])[0] ?? '') ?>" required></div>
                                <div class="col-md-6"><label class="field-label">Last Name</label>
                                    <input name="last_name" class="form-control" value="<?= e(trim(substr(strstr($me['name'],' '),1))) ?>"></div>

                                <div class="col-md-6"><label class="field-label">Email (cannot be changed here)</label>
                                    <input class="form-control" value="<?= e($me['email']) ?>" readonly style="background:#eef7f6;"></div>
                                <div class="col-md-6"><label class="field-label">Contact Number</label>
                                    <input name="phone" class="form-control" value="<?= e($me['phone']) ?>" placeholder="09XX XXX XXXX" <?= phone_input_attrs() ?> required></div>

                                <div class="col-md-4"><label class="field-label">Date of Birth</label>
                                    <input type="date" name="dob" class="form-control" value="<?= e($me['date_of_birth']) ?>" min="1900-01-01" max="<?= birth_date_max() ?>"></div>
                                <div class="col-md-4"><label class="field-label">Age</label>
                                    <input type="number" name="age" class="form-control" min="0" max="120" step="1" data-digits value="<?= e($me['age']) ?>"></div>
                                <div class="col-md-4"><label class="field-label">Blood Type</label>
                                    <select name="blood_type" class="form-select">
                                        <option value="">Unknown</option>
                                        <?php foreach (['O+','O-','A+','A-','B+','B-','AB+','AB-'] as $bt): ?>
                                            <option <?= $me['blood_type']===$bt?'selected':'' ?>><?= $bt ?></option>
                                        <?php endforeach; ?>
                                    </select></div>

                                <div class="col-12"><label class="field-label">Home Address</label>
                                    <input name="address" class="form-control" value="<?= e($me['address']) ?>" placeholder="House no., street, barangay, city"></div>

                                <div class="col-12"><label class="field-label">Medical History</label>
                                    <textarea name="medical_history" class="form-control" rows="3"
                                              placeholder="Past conditions, surgeries, medication you take regularly..."><?= e($me['medical_history']) ?></textarea></div>

                                <div class="col-12"><label class="field-label">Allergies / Medical Alert</label>
                                    <input name="medical_alert" class="form-control" value="<?= e($me['medical_alert']) ?>" placeholder="e.g. Allergic to Penicillin"></div>
                            </div>
                            <button class="btn btn-teal mt-3">💾 Save My Information</button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-5">
                    <!-- Account status -->
                    <div class="card-box mb-3">
                        <h5 class="mb-3">🔎 Account Status</h5>
                        <div class="flex-between py-2 border-bottom"><span>Email address</span><strong><?= e($account['email']) ?></strong></div>
                        <div class="flex-between py-2 border-bottom"><span>Email verified</span>
                            <?php if (!empty($account['email_verified'])): ?>
                                <span class="badge-pill b-completed">✓ Verified</span>
                            <?php else: ?>
                                <span class="badge-pill b-pending">Not verified</span>
                            <?php endif; ?>
                        </div>
                        <div class="flex-between py-2 border-bottom"><span>Sign-in method</span>
                            <strong><?= !empty($account['google_id']) ? 'Google' : 'Email + password' ?></strong></div>
                        <div class="flex-between py-2 border-bottom"><span>Patient type</span><strong><?= e($me['patient_type']) ?></strong></div>
                        <div class="flex-between py-2 border-bottom"><span>Primary dentist</span><strong><?= e($me['primary_dentist'] ?: 'Not assigned') ?></strong></div>
                        <div class="flex-between py-2"><span>Last visit</span><strong><?= e($me['last_visit'] ?: '—') ?></strong></div>
                    </div>

                    <!-- Password -->
                    <div class="card-box">
                        <h5 class="mb-3">🔑 Change Password</h5>
                        <?php if (!empty($account['google_id'])): ?>
                            <div class="alert alert-light border py-2" style="font-size:.85rem;">
                                You sign in with Google, so you don't need a password here.
                            </div>
                        <?php endif; ?>
                        <?php if ($pwError): ?><div class="alert alert-danger py-2"><?= e($pwError) ?></div><?php endif; ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="change_password">
                            <label class="field-label">Current Password</label>
<div class="pw-wrap mb-3">
                    <input type="password" name="current_password" class="form-control" required>
                    <button type="button" class="pw-eye" aria-label="Show password">
                        <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
                            <label class="field-label">New Password</label>
<div class="pw-wrap mb-1">
                    <input type="password" name="new_password" class="form-control" required minlength="8" data-pw-meter>
                    <button type="button" class="pw-eye" aria-label="Show password">
                        <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
                            <div class="text-muted2 mb-3" style="font-size:.78rem;">At least 6 characters.</div>
                            <label class="field-label">Confirm New Password</label>
<div class="pw-wrap mb-3">
                    <input type="password" name="confirm_password" class="form-control" required>
                    <button type="button" class="pw-eye" aria-label="Show password">
                        <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
                            <button class="btn btn-teal">🔑 Update Password</button>
                        </form>
                    </div>
                </div>
            </div>

        <?php elseif ($view === 'news'): ?>
            <!-- ===== ANNOUNCEMENTS / NOTIFICATIONS ===== -->
            <div class="card-box">
                <h5 class="mb-3">📣 Clinic Announcements</h5>
                <?php foreach ($news as $n): ?>
                    <div class="py-3 border-bottom">
                        <div class="flex-between">
                            <strong><?= e($n['title']) ?></strong>
                            <small class="text-muted2"><?= date('M j, Y', strtotime($n['created_at'])) ?></small>
                        </div>
                        <div style="font-size:.92rem;color:#55606a;margin-top:4px;white-space:pre-line;"><?= format_announcement($n['content']) ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$news): ?><p class="text-muted2 text-center py-4">No announcements right now.</p><?php endif; ?>
            </div>

        <?php elseif ($view === 'activity'): ?>
            <!-- ===== MY ACTIVITY (only this patient's own actions) ===== -->
            <div class="card-box">
                <div class="flex-between mb-3 flex-wrap gap-2">
                    <h5 class="mb-0">🧾 My Activity
                        <small class="text-muted2 d-block" style="font-size:.75rem;">Things you have done in your account (most recent 100)</small>
                    </h5>
                    <form method="GET" class="d-flex gap-2 flex-wrap">
                        <input type="hidden" name="view" value="activity">
                        <input type="date" name="date" class="form-control form-control-sm" style="width:auto;" value="<?= e($actDate) ?>" onchange="this.form.submit()">
                        <input type="text" name="q" class="form-control form-control-sm" style="width:180px;" placeholder="Search..." value="<?= e($actQ) ?>">
                        <button class="btn btn-sm btn-teal" type="submit">Filter</button>
                        <?php if ($actQ !== '' || $actDate !== ''): ?><a href="portal?view=activity" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
                    </form>
                </div>
                <?php foreach ($myActivity as $log): ?>
                    <div class="flex-between py-2 border-bottom gap-2">
                        <div>
                            <span class="badge-pill <?= activity_badge($log['action']) ?>"><?= e($log['action']) ?></span>
                            <div style="font-size:.88rem;color:#55606a;margin-top:3px;"><?= e($log['details'] ?: '') ?></div>
                        </div>
                        <small class="text-muted2" style="white-space:nowrap;"><?= date('M j, Y g:i A', strtotime($log['created_at'])) ?></small>
                    </div>
                <?php endforeach; ?>
                <?php if (!$myActivity): ?><p class="text-muted2 text-center py-4">No activity <?= ($actQ !== '' || $actDate !== '') ? 'matches your filter.' : 'recorded yet.' ?></p><?php endif; ?>
            </div>

        <?php elseif ($view === 'contact'): ?>
            <!-- ===== CLINIC CONTACT ===== -->
            <div class="card-box mb-3">
                <h5 class="mb-1">📞 Clinic Contact</h5>
                <div class="text-muted2 mb-3" style="font-size:.85rem;">Questions about your appointment or treatment? Reach us here.</div>
                <div style="font-weight:700;font-size:1.05rem;color:var(--teal-dark);" class="mb-3"><?= e($clinicName) ?></div>
                <div class="row g-3">
                    <?php if ($clinicPhone !== ''): ?>
                    <div class="col-md-6"><div class="field-label">📱 Phone</div>
                        <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $clinicPhone)) ?>"><?= e($clinicPhone) ?></a></div>
                    <?php endif; ?>
                    <?php if ($clinicEmail !== ''): ?>
                    <div class="col-md-6"><div class="field-label">✉️ Email</div>
                        <a href="mailto:<?= e($clinicEmail) ?>"><?= e($clinicEmail) ?></a></div>
                    <?php endif; ?>
                    <?php if ($clinicAddress !== ''): ?>
                    <div class="col-md-6"><div class="field-label">📍 Address</div><?= e($clinicAddress) ?></div>
                    <?php endif; ?>
                    <?php if ($clinicHours !== ''): ?>
                    <div class="col-md-6"><div class="field-label">🕘 Clinic Hours</div><?= e($clinicHours) ?></div>
                    <?php endif; ?>
                    <?php if ($clinicFacebook !== ''): ?>
                    <div class="col-md-6"><div class="field-label">📘 Facebook</div>
                        <a href="<?= e($clinicFacebook) ?>" target="_blank" rel="noopener noreferrer">
                            <?= e(preg_replace('#^https?://(www\.)?#i', '', $clinicFacebook)) ?></a></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-box">
                <div class="flex-between flex-wrap gap-2">
                    <div>
                        <h5 class="mb-1">🗄 My Archive</h5>
                        <div class="text-muted2" style="font-size:.85rem;">
                            Appointments and records you deleted<?php $nArc = count($archivedAppts) + count($archivedTreatments); ?><?= $nArc ? ' (' . $nArc . ')' : '' ?>. You can restore them anytime.
                        </div>
                    </div>
                    <a href="portal?view=archive" class="btn btn-sm btn-teal">Open My Archive</a>
                </div>
            </div>

        <?php elseif ($view === 'archive'): ?>
            <!-- ===== MY ARCHIVE (items the patient deleted) ===== -->
            <div class="mb-2"><a href="portal?view=contact" class="text-muted2" style="font-size:.85rem;">‹ Back to Clinic Contact</a></div>
            <div class="card-box mb-3">
                <h5 class="mb-1">🗄 My Archive — Appointment History</h5>
                <div class="text-muted2 mb-2" style="font-size:.85rem;">Only hidden from your account — the clinic still keeps these for your care.</div>
                <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                <table class="data" style="min-width:640px;">
                    <thead><tr><th>Patient</th><th>Treatment</th><th>Date</th><th>Time</th><th>Dentist</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($archivedAppts as $a): ?>
                        <tr>
                            <td><?= e($a['patient_name']) ?></td>
                            <td><?= e($a['treatment']) ?></td>
                            <td><?= date('M j, Y', strtotime($a['appointment_date'])) ?></td>
                            <td class="date-blue"><?= e($a['appointment_time']) ?></td>
                            <td><?= e($a['dentist']) ?></td>
                            <td><span class="badge-pill b-<?= strtolower($a['status']) ?>"><?= e($a['status']) ?></span></td>
                            <td class="text-end"><?= archive_button('appointment', $a['id'], true, 'archive') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$archivedAppts): ?>
                        <tr><td colspan="7" class="text-center text-muted2 py-3">No archived appointments.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <div class="card-box">
                <h5 class="mb-2">🗄 My Archive — Treatment Records</h5>
                <?php foreach ($archivedTreatments as $t): ?>
                    <div class="flex-between py-3 border-bottom gap-2">
                        <div><strong><?= e($t['treatment_name']) ?></strong><br>
                            <small class="text-muted2"><?= e($t['treatment_date']) ?> • <?= e($t['dentist']) ?></small></div>
                        <?= archive_button('treatment', $t['id'], true, 'archive') ?>
                    </div>
                <?php endforeach; ?>
                <?php if (!$archivedTreatments): ?><p class="text-muted2 text-center py-3 mb-0">No archived records.</p><?php endif; ?>
            </div>

        <?php else: ?>
            <!-- ===== MY RECORDS (treatment history) ===== -->
            <div class="card-box">
                <h5>Treatment History</h5>
                <?php foreach ($myTreatments as $t): ?>
                    <div class="flex-between py-3 border-bottom">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:44px;height:44px;border-radius:10px;background:#e6f7f5;display:flex;align-items:center;justify-content:center;">🦷</div>
                            <div><strong><?= e($t['treatment_name']) ?></strong><br>
                                <small class="text-muted2"><?= e($t['treatment_date']) ?> • <?= e($t['dentist']) ?></small><br>
                                <small><?= e($t['notes']) ?></small></div>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                            <span class="badge-pill b-<?= $t['status']==='Completed'?'completed':'progress' ?>"><?= e($t['status']) ?></span>
                            <?= archive_button('treatment', $t['id']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$myTreatments): ?><p class="text-muted2 text-center py-3">No records yet.</p><?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<!-- ===== Cancel my appointment ===== -->
<div class="modal fade" id="myCancelModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content" onsubmit="return validateMyCancel()">
      <input type="hidden" name="action" value="cancel_appointment">
      <input type="hidden" name="appointment_id" id="mc-id">

      <div class="modal-header">
        <h5 class="modal-title">Cancel your appointment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="card-box mb-3" style="background:#f7fafa;padding:12px 14px;">
          <div class="text-muted2" style="font-size:.8rem;">You are cancelling</div>
          <div style="font-weight:600;" id="mc-when"></div>
          <div class="text-muted2" style="font-size:.82rem;" id="mc-treat"></div>
        </div>

        <div class="alert" style="background:#fff6e0;border:1px solid var(--gold);color:#8a6d2f;font-size:.82rem;">
          If you only need a different time, <strong>Reschedule</strong> keeps your place instead
          of cancelling. Cancelling means you would have to book again.
        </div>

        <label class="field-label">Why are you cancelling?</label>
        <textarea name="cancel_reason" id="mc-reason" class="form-control mb-1" rows="3"
                  placeholder="e.g. Something came up at work"></textarea>
        <div class="text-muted2" style="font-size:.78rem;">
          This helps the clinic understand and offer you another time.
        </div>
        <div id="mc-warn" class="text-danger small mt-1" style="display:none;">Please give a short reason.</div>

        <div class="mt-3">
          <div class="text-muted2 mb-1" style="font-size:.78rem;">Or pick one:</div>
          <div class="d-flex gap-1 flex-wrap">
            <?php foreach ([
                'Something came up at work.',
                'I am not feeling well.',
                'I have a family emergency.',
                'I cannot travel that day.',
            ] as $preset): ?>
              <button type="button" class="btn btn-sm btn-light" style="font-size:.74rem;"
                      onclick="document.getElementById('mc-reason').value=this.textContent.trim();document.getElementById('mc-warn').style.display='none';">
                <?= e($preset) ?>
              </button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep appointment</button>
        <button class="btn" style="background:#c0392b;color:#fff;">Cancel appointment</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== Reschedule dialog — same date-and-slot layout as the booking page ===== -->
<div class="modal fade" id="rescheduleModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content" onsubmit="return validateReschedule()">
      <input type="hidden" name="action" value="reschedule_appointment">
      <input type="hidden" name="appointment_id" id="rs-id">

      <div class="modal-header">
        <h5 class="modal-title">Move your appointment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="card-box mb-3" style="background:#f7fafa;padding:12px 14px;">
          <div class="text-muted2" style="font-size:.8rem;">Currently booked for</div>
          <div style="font-weight:600;" id="rs-current"></div>
          <div class="text-muted2" style="font-size:.8rem;" id="rs-treat"></div>
        </div>

        <div class="alert" style="background:#eef7f6;border:1px solid #cfe0dd;color:#3f5350;font-size:.8rem;">
          Clinic hours: <strong><?= date('g:i A', strtotime($rsOpen)) ?> – <?= date('g:i A', strtotime($rsClose)) ?></strong>,
          <?= e(implode(', ', $rsDays)) ?>. Your new time will be <strong>Pending</strong> until the clinic confirms it.
        </div>

        <label class="field-label">New date</label>
        <input type="date" name="new_date" id="rs-date" class="form-control mb-1"
               min="<?= date('Y-m-d', strtotime('+1 day')) ?>" onchange="rsDateChanged()" required>
        <div id="rs-date-warn" class="text-danger small mb-2" style="display:none;"></div>

        <label class="field-label">Available slots</label>
        <div class="slot-grid mb-2" id="rs-slots">
          <?php foreach ($rsSlots as $s): ?>
            <div class="slot" data-time="<?= e($s) ?>" onclick="rsPick(this)"><?= e($s) ?></div>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="new_time" id="rs-time" required>
        <div id="rs-slot-warn" class="text-danger small mb-2" style="display:none;">Please choose a time.</div>

        <label class="field-label">Why are you moving it?</label>
        <textarea name="reschedule_reason" id="rs-reason" class="form-control mb-1" rows="2"
                  placeholder="e.g. Something came up at work"></textarea>
        <div class="text-muted2" style="font-size:.78rem;">This helps the clinic confirm your new time.</div>
        <div id="rs-reason-warn" class="text-danger small mt-1" style="display:none;">Please give a short reason.</div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep current time</button>
        <button class="btn btn-teal">Move appointment</button>
      </div>
    </form>
  </div>
</div>

<?php if ($showWelcomePopup): ?>
<!-- One-time "Welcome" pop-up shown right after logging in — centered,
     dismissed with a single OK button, and never repeated on other pages. -->
<div class="modal fade" id="welcomeModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;overflow:hidden;">
      <div class="modal-body text-center py-4 px-4">
        <div style="width:64px;height:64px;background:#eef7f6;border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:1.8rem;">🦷</div>
        <h4 style="color:var(--teal-dark);"><?= $justRegistered ? 'Welcome' : 'Welcome back' ?>, <?= e($firstName) ?>!</h4>
        <p class="text-muted2 mb-4">Manage your dental health from your personal portal.</p>
        <button type="button" class="btn btn-teal px-4" data-bs-dismiss="modal">OK</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js"></script>
<script>
startClock();

<?php if ($showWelcomePopup): ?>
document.addEventListener('DOMContentLoaded', function () {
    new bootstrap.Modal(document.getElementById('welcomeModal')).show();
});
<?php endif; ?>

// Print the dental chart. The shared print stylesheet already removes the
// sidebar, buttons and widgets, so what remains is the chart itself.
function printChart(){ window.print(); }

// ---- Reschedule dialog ----
// Which dates the dentist is away, and which slots are already taken.
var RS_DAYSOFF = <?= json_encode(array_values($rsDaysOff)) ?>;
var RS_TAKEN   = <?= json_encode($rsTaken ?: new stdClass()) ?>;
var RS_DAYS    = <?= json_encode(array_values($rsDays)) ?>;
var RS_CURRENT = null;

function openReschedule(a){
    RS_CURRENT = a;
    document.getElementById('rs-id').value = a.id;
    var d = new Date(a.date + 'T00:00:00');
    document.getElementById('rs-current').textContent =
        d.toLocaleDateString(undefined,{weekday:'long',year:'numeric',month:'long',day:'numeric'}) + ' at ' + a.time;
    document.getElementById('rs-treat').textContent = a.treat || '';
    document.getElementById('rs-date').value = '';
    document.getElementById('rs-time').value = '';
    document.getElementById('rs-reason').value = '';
    document.getElementById('rs-date-warn').style.display = 'none';
    document.getElementById('rs-slot-warn').style.display = 'none';
    document.getElementById('rs-reason-warn').style.display = 'none';
    rsResetSlots();
    new bootstrap.Modal(document.getElementById('rescheduleModal')).show();
}

function rsResetSlots(){
    document.querySelectorAll('#rs-slots .slot').forEach(function(el){
        el.classList.remove('selected','taken');
    });
}

function rsDateChanged(){
    var val  = document.getElementById('rs-date').value;
    var warn = document.getElementById('rs-date-warn');
    document.getElementById('rs-time').value = '';
    rsResetSlots();
    if (!val) return;

    var d   = new Date(val + 'T00:00:00');
    var day = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d.getDay()];

    if (RS_DAYS.length && RS_DAYS.indexOf(day) === -1) {
        warn.textContent = 'The clinic is closed on ' + day + 'days. Please pick another date.';
        warn.style.display = 'block';
        document.querySelectorAll('#rs-slots .slot').forEach(function(el){ el.classList.add('taken'); });
        return;
    }
    if (RS_DAYSOFF.indexOf(val) !== -1) {
        warn.textContent = 'Your dentist is away that day. Please pick another date.';
        warn.style.display = 'block';
        document.querySelectorAll('#rs-slots .slot').forEach(function(el){ el.classList.add('taken'); });
        return;
    }
    warn.style.display = 'none';

    // Grey out slots that are already booked, but leave this appointment's own slot free.
    var taken = RS_TAKEN[val] || [];
    document.querySelectorAll('#rs-slots .slot').forEach(function(el){
        var t = el.getAttribute('data-time');
        var isOwn = RS_CURRENT && val === RS_CURRENT.date && t === RS_CURRENT.time;
        if (taken.indexOf(t) !== -1 && !isOwn) el.classList.add('taken');
    });
}

function rsPick(el){
    if (el.classList.contains('taken')) return;
    document.querySelectorAll('#rs-slots .slot').forEach(function(s){ s.classList.remove('selected'); });
    el.classList.add('selected');
    document.getElementById('rs-time').value = el.getAttribute('data-time');
    document.getElementById('rs-slot-warn').style.display = 'none';
}

function validateReschedule(){
    var d = document.getElementById('rs-date').value;
    var t = document.getElementById('rs-time').value;
    var r = document.getElementById('rs-reason').value.trim();
    if (!d) { document.getElementById('rs-date-warn').textContent = 'Please choose a date.';
              document.getElementById('rs-date-warn').style.display = 'block'; return false; }
    if (!t) { document.getElementById('rs-slot-warn').style.display = 'block'; return false; }
    if (!r) { document.getElementById('rs-reason-warn').style.display = 'block'; return false; }
    return confirm('Move your appointment to ' + d + ' at ' + t + '?\n\n' +
                   'It will be Pending until the clinic confirms the new time.');
}

// The patient tells us why they are cancelling. The clinic receives this by
// email so they know whether to follow up or offer another time.
function openMyCancel(a){
    document.getElementById('mc-id').value = a.id;
    var d = new Date(a.date + 'T00:00:00');
    document.getElementById('mc-when').textContent =
        d.toLocaleDateString(undefined,{weekday:'long',year:'numeric',month:'long',day:'numeric'}) + ' at ' + a.time;
    document.getElementById('mc-treat').textContent = a.treat || '';
    document.getElementById('mc-reason').value = '';
    document.getElementById('mc-warn').style.display = 'none';
    new bootstrap.Modal(document.getElementById('myCancelModal')).show();
}

function validateMyCancel(){
    var r = document.getElementById('mc-reason').value.trim();
    if (r === '') { document.getElementById('mc-warn').style.display = 'block'; return false; }
    return true;
}
</script>
</body>
</html>
