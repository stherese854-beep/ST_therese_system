<?php
// ============================================================
//  TOP-RIGHT PROFILE WIDGET  (includes/topbar.php)
// ============================================================
//  Shows the logged-in user's name + a small round profile picture
//  in the top-right corner. Clicking it opens a dropdown with a link
//  to their profile/settings and a Sign Out button.
//
//  It is fixed to the corner with CSS, so it floats above every page
//  without changing any page's own layout.
//
//  Only shows when someone is logged in.
// ============================================================
if (!function_exists('is_logged_in') || !is_logged_in()) return;

$tbName  = $_SESSION['name'] ?? 'User';
$tbRole  = function_exists('current_role') ? current_role() : ($_SESSION['role'] ?? '');

// Where does "Profile / Settings" go? Patients have their own portal page;
// staff/admin/dentist use the shared settings page.
$tbProfileLink = ($tbRole === 'patient') ? 'portal?view=profile' : 'settings';

// The user's profile picture (users.photo). Falls back to their initial.
$tbPhoto = null;
try {
    $tbStmt = $pdo->prepare("SELECT photo FROM users WHERE id = ?");
    $tbStmt->execute([$_SESSION['user_id'] ?? 0]);
    $tbPhoto = $tbStmt->fetchColumn();
} catch (Throwable $e) { $tbPhoto = null; }

$tbInitial = strtoupper(substr(trim($tbName), 0, 1)) ?: 'U';
$tbHasPhoto = $tbPhoto && is_file(__DIR__ . '/../' . $tbPhoto);

// ---- Notifications (shown in the bell) ----
// Admin/staff: pending appointment requests + pending reviews awaiting moderation.
// Dentist: pending appointments for their own patients.
// Patient: their appointments that were recently confirmed or cancelled.
$notifs = [];
$unreadCount = null;   // patients use this for the red badge; null = use list count
try {
    if (in_array($tbRole, ['admin','staff'])) {
        $pc = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE status='Pending'")->fetchColumn();
        if ($pc > 0) $notifs[] = ['icon'=>'⏳','text'=>"$pc pending appointment".($pc>1?'s':'')." to review",'link'=>'appointments?filter=Pending'];

        // Appointments the PATIENT cancelled online — the clinic needs to know
        // so the freed-up slot can be reused. Only recent ones are shown.
        try {
            $cc = (int)$pdo->query(
                "SELECT COUNT(*) FROM appointments
                  WHERE cancelled_by='patient'
                    AND cancelled_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            )->fetchColumn();
            if ($cc > 0) $notifs[] = ['icon'=>'🚫','text'=>"$cc appointment".($cc>1?'s':'')." cancelled by patients",'link'=>'appointments?filter=Cancelled'];
        } catch (Throwable $e) {}

        // Appointments the system thinks were missed, waiting for a decision.
        try {
            $nr = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE status='Needs Review'")->fetchColumn();
            if ($nr > 0) $notifs[] = ['icon'=>'📋','text'=>"$nr missed appointment".($nr>1?'s':'')." to review",'link'=>'noshow?tab=review'];
        } catch (Throwable $e) {}

        // Patients paused for frequent cancellations, waiting for a review.
        try {
            if (!function_exists('patient_cancel_count')) require_once __DIR__ . '/noshow_check.php';
            $cr = 0;
            foreach ($pdo->query("SELECT DISTINCT patient_id FROM appointments WHERE status='Cancelled' AND cancelled_by='patient'")
                         ->fetchAll(PDO::FETCH_COLUMN) as $cpid) {
                if (patient_cancel_count($pdo, $cpid) >= CANCEL_LIMIT) $cr++;
            }
            if ($cr > 0) $notifs[] = ['icon'=>'🔁','text'=>"$cr patient".($cr>1?'s':'')." with frequent cancellations to review",'link'=>'noshow?tab=cancels'];
        } catch (Throwable $e) {}

        // reviews table may not exist on older DBs — guard it
        try {
            $rc = (int)$pdo->query("SELECT COUNT(*) FROM reviews WHERE status='Pending'")->fetchColumn();
            if ($rc > 0) $notifs[] = ['icon'=>'⭐','text'=>"$rc new review".($rc>1?'s':'')." to moderate",'link'=>'reviews'];
        } catch (Throwable $e) {}
    } elseif ($tbRole === 'dentist') {
        // A dentist should only be notified about pending appointments belonging to
        // THEIR OWN assigned patients. We match through the patient's primary_dentist
        // (their assigned doctor) and also accept appointments whose dentist field
        // already names this dentist, so nothing is missed either way.
        $pc = (int)$pdo->query(
            "SELECT COUNT(*) FROM appointments a
             LEFT JOIN patients p ON a.patient_id = p.id
             WHERE a.status='Pending'
               AND (p.primary_dentist = " . $pdo->quote($tbName) . "
                    OR a.dentist = " . $pdo->quote($tbName) . ")"
        )->fetchColumn();
        if ($pc > 0) $notifs[] = ['icon'=>'⏳','text'=>"$pc pending appointment".($pc>1?'s':'')." for your patients",'link'=>'appointments?filter=Pending'];
    } elseif ($tbRole === 'patient') {
        // The patient is notified when the clinic CONFIRMS an appointment.
        // Anything confirmed before they last opened the bell counts as already
        // seen, so the red badge clears itself once they have looked.
        $pst = $pdo->prepare("SELECT id FROM patients WHERE user_id=?");
        $pst->execute([$_SESSION['user_id'] ?? 0]);
        $mypid = $pst->fetchColumn();
        if ($mypid) {
            $seenStmt = $pdo->prepare("SELECT notif_seen_at FROM users WHERE id=?");
            $seenStmt->execute([$_SESSION['user_id'] ?? 0]);
            $seenAt = $seenStmt->fetchColumn();

            // Unread = confirmed after the last time they opened the bell.
            $famIds = family_patient_ids($pdo, $mypid);   // their own + family members' appointments
            $sqlUnread = "SELECT COUNT(*) FROM appointments
                          WHERE patient_id IN (" . in_placeholders($famIds) . ") AND status='Confirmed'";
            $prm = $famIds;
            if ($seenAt) { $sqlUnread .= " AND (confirmed_at IS NULL OR confirmed_at > ?)"; $prm[] = $seenAt; }
            $uStmt = $pdo->prepare($sqlUnread);
            $uStmt->execute($prm);
            $unread = (int)$uStmt->fetchColumn();

            // The dropdown always lists their confirmed appointments; only the
            // red badge depends on whether they are unread.
            $tq = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE patient_id IN (" . in_placeholders($famIds) . ") AND status='Confirmed'");
            $tq->execute($famIds);
            $tot = (int)$tq->fetchColumn();
            if ($tot > 0) {
                $notifs[] = ['icon'=>'✅','text'=>"$tot confirmed appointment".($tot>1?'s':''),'link'=>'portal?view=appointments'];
            }
            $unreadCount = $unread;   // drives the red badge for patients
        }
    }
} catch (Throwable $e) { $notifs = []; }
$notifCount = count($notifs);
// Patients track "read" state: the red badge shows unread items only, while the
// dropdown still lists everything. Other roles use a live to-do count instead.
$badgeCount = ($unreadCount === null) ? $notifCount : $unreadCount;
?>

<!-- Bell + profile sit in ONE row, so a long name can never cover the bell -->
<div id="topbarWidgets">
<div id="notifWidget">
    <button type="button" id="notifBtn" onclick="toggleNotif(event)" aria-label="Notifications">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="21" height="21">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        <?php if ($badgeCount > 0): ?><span class="notif-dot" id="notifDot"><?= $badgeCount ?></span><?php endif; ?>
    </button>
    <div id="notifMenu">
        <div class="notif-head">Notifications</div>
        <?php if ($notifCount === 0): ?>
            <div class="notif-empty">🔔 You're all caught up!</div>
        <?php else: ?>
            <?php foreach ($notifs as $n): ?>
                <a href="<?= e($n['link']) ?>" class="notif-item">
                    <span class="notif-ico"><?= $n['icon'] ?></span>
                    <span><?= e($n['text']) ?></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div id="profileWidget">
    <button type="button" id="pwBtn" onclick="toggleProfileMenu(event)">
        <span class="pw-name"><?= e($tbName) ?></span>
        <?php if ($tbHasPhoto): ?>
            <img class="pw-avatar" src="<?= e($tbPhoto) ?>" alt="Profile">
        <?php else: ?>
            <span class="pw-avatar pw-initial"><?= e($tbInitial) ?></span>
        <?php endif; ?>
    </button>

    <div id="pwMenu">
        <div class="pw-head">
            <?php if ($tbHasPhoto): ?>
                <img class="pw-avatar-lg" src="<?= e($tbPhoto) ?>" alt="">
            <?php else: ?>
                <span class="pw-avatar-lg pw-initial"><?= e($tbInitial) ?></span>
            <?php endif; ?>
            <div>
                <div class="pw-head-name"><?= e($tbName) ?></div>
                <div class="pw-head-role"><?= e(ucfirst($tbRole)) ?></div>
            </div>
        </div>
        <a href="<?= $tbProfileLink ?>" class="pw-item">⚙️ Profile &amp; Settings</a>
        <?php if ($tbRole !== 'admin'): ?>
            <a href="<?= $tbRole === 'patient' ? 'portal?view=activity' : 'my_activity' ?>" class="pw-item">🧾 My Activity</a>
        <?php endif; ?>
        <a href="logout" class="pw-item pw-signout" data-confirm="Are you sure you want to log out?" data-confirm-title="Log out?" data-confirm-ok="Log out" data-confirm-icon="⏻">⏻ Sign Out</a>
    </div>
</div>
</div><!-- /#topbarWidgets -->

<style>
/* One fixed row holds both widgets. They lay out side by side, so the
   profile pill grows to the LEFT and can never sit on top of the bell. */
#topbarWidgets{position:fixed;top:14px;right:22px;z-index:9000;font-family:inherit;
               display:flex;align-items:center;gap:10px;max-width:calc(100vw - 44px);}
#profileWidget{position:relative;flex:0 1 auto;min-width:0;}

/* Notification bell sits just left of the profile widget */
#notifWidget{position:relative;flex:none;}
#notifBtn{position:relative;width:46px;height:46px;border-radius:50%;background:#fff;
          border:1px solid #e3e9ee;cursor:pointer;display:grid;place-items:center;color:#44585a;
          box-shadow:0 4px 14px rgba(12,50,48,.10);transition:box-shadow .15s,border-color .15s;}
#notifBtn:hover{box-shadow:0 6px 20px rgba(12,50,48,.16);border-color:var(--teal-light,#14b8a6);color:var(--teal,#0f766e);}
.notif-dot{position:absolute;top:-2px;right:-2px;min-width:19px;height:19px;padding:0 5px;
           background:#e05b5b;color:#fff;border-radius:100px;font-size:.7rem;font-weight:700;
           display:grid;place-items:center;border:2px solid #fff;}
#notifMenu{position:absolute;top:calc(100% + 8px);right:0;width:280px;background:#fff;
           border:1px solid #e6efee;border-radius:14px;box-shadow:0 16px 40px rgba(12,50,48,.20);
           overflow:hidden;display:none;}
#notifMenu.open{display:block;}
.notif-head{padding:14px 16px;font-weight:700;color:var(--ink,#11302d);font-size:.9rem;
            background:#f7fafa;border-bottom:1px solid #eef2f2;}
.notif-empty{padding:26px 16px;text-align:center;color:#8aa0a0;font-size:.88rem;}
.notif-item{display:flex;align-items:center;gap:11px;padding:13px 16px;text-decoration:none;
            color:#3f5350;font-size:.88rem;border-bottom:1px solid #f2f6f6;transition:background .12s;}
.notif-item:last-child{border-bottom:none;}
.notif-item:hover{background:#f2f7f6;}
.notif-ico{font-size:1.1rem;flex:none;}

#pwBtn{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #e3e9ee;
       border-radius:100px;padding:5px 6px 5px 16px;cursor:pointer;box-shadow:0 4px 14px rgba(12,50,48,.10);
       transition:box-shadow .15s,border-color .15s;}
#pwBtn:hover{box-shadow:0 6px 20px rgba(12,50,48,.16);border-color:var(--teal-light,#14b8a6);}
.pw-name{font-weight:600;font-size:.9rem;color:var(--ink,#11302d);max-width:170px;min-width:0;
         white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.pw-avatar{width:38px;height:38px;border-radius:50%;object-fit:cover;flex:none;}
.pw-initial{display:grid;place-items:center;background:linear-gradient(135deg,#0d3b3b,#0f766e);
            color:#fff;font-weight:700;}
.pw-avatar.pw-initial{font-size:1rem;}

#pwMenu{position:absolute;top:calc(100% + 8px);right:0;width:230px;background:#fff;
        border:1px solid #e6efee;border-radius:14px;box-shadow:0 16px 40px rgba(12,50,48,.20);
        overflow:hidden;display:none;}
#pwMenu.open{display:block;}
.pw-head{display:flex;align-items:center;gap:12px;padding:16px;background:#f7fafa;
         border-bottom:1px solid #eef2f2;}
.pw-avatar-lg{width:46px;height:46px;border-radius:50%;object-fit:cover;flex:none;}
.pw-avatar-lg.pw-initial{font-size:1.2rem;}
.pw-head-name{font-weight:700;color:var(--ink,#11302d);font-size:.95rem;line-height:1.2;
              max-width:135px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.pw-head-role{font-size:.75rem;color:#8aa0a0;letter-spacing:.5px;text-transform:uppercase;}
.pw-item{display:block;padding:12px 16px;text-decoration:none;color:#3f5350;font-size:.9rem;
         font-weight:500;transition:background .12s;}
.pw-item:hover{background:#f2f7f6;}
.pw-signout{color:#c0392b;border-top:1px solid #eef2f2;}

/* Reserve a strip at the very top of the main area for the floating widget,
   so it sits ABOVE the page header instead of on top of its buttons. */
/* The widget floats at the top-right. Push the page's own content down so the
   widget never covers the page title, clock, or any top-right buttons. */
.main{padding-top:74px !important;}
/* The clock/buttons block in each page header sits at the top-right too, so give
   it room on the right for the fixed widget when the header is on one line. */
.main .page-head{padding-right:0;}
@media(max-width:560px){
    .pw-name{display:none;}
    #pwBtn{padding:5px;}
}
</style>

<script>
function toggleProfileMenu(e){
    e.stopPropagation();
    document.getElementById('pwMenu').classList.toggle('open');
    var nm = document.getElementById('notifMenu'); if (nm) nm.classList.remove('open');
}
function toggleNotif(e){
    e.stopPropagation();
    document.getElementById('notifMenu').classList.toggle('open');
    var pm = document.getElementById('pwMenu'); if (pm) pm.classList.remove('open');

    // Opening the bell counts as reading it: drop the red badge straight away
    // and tell the server, so it stays cleared on the next page too.
    var dot = document.getElementById('notifDot');
    if (dot) {
        dot.remove();
        var tk = document.querySelector('meta[name="csrf-token"]');
        fetch('notif_seen', { method: 'POST', credentials: 'same-origin',
              headers: { 'X-CSRF-Token': tk ? tk.content : '' } }).catch(function(){});
    }
}
// Click anywhere else closes both menus.
document.addEventListener('click', function(){
    var m = document.getElementById('pwMenu'); if (m) m.classList.remove('open');
    var n = document.getElementById('notifMenu'); if (n) n.classList.remove('open');
});
</script>
