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
$tbProfileLink = ($tbRole === 'patient') ? 'portal?view=profile' : 'settings?view=profile';

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
// Admin/staff: pending appointment requests, patient cancellations, missed
//   visits and reviews waiting for them.
// Dentist: pending appointments for their own patients.
// Patient: their confirmed appointments.
// Everyone: new clinic announcements.
//
// Each item has a KIND and a MARK (the newest thing behind it: an id or a
// time). Opening the bell stores the marks it showed (users.notif_seen_map),
// so the red badge only counts what is NEW since then — once read, it stays
// cleared until something newer arrives.
// Clicking an item removes it from the bell (users.notif_hidden_map); it only
// comes back when something newer of that kind arrives (e.g. another booking).
$notifs = [];
$tbSeen = []; $tbHidden = [];
try {
    $sm = $pdo->prepare("SELECT notif_seen_map, notif_hidden_map FROM users WHERE id=?");
    $sm->execute([$_SESSION['user_id'] ?? 0]);
    $row = $sm->fetch() ?: [];
    $tbSeen   = json_decode((string)($row['notif_seen_map'] ?? ''), true) ?: [];
    $tbHidden = json_decode((string)($row['notif_hidden_map'] ?? ''), true) ?: [];
} catch (Throwable $e) {}
$tbNewer = fn($mark, $old) => $old === null
    || ((is_numeric($mark) && is_numeric($old)) ? $mark + 0 > $old + 0 : strcmp((string)$mark, (string)$old) > 0);
$tbAdd = function ($kind, $mark, $icon, $text, $link) use (&$notifs, $tbSeen, $tbHidden, $tbNewer) {
    if ($mark === null || $mark === false || $mark === '') return;
    // Each announcement is its own item; the other kinds are one item each.
    $key = $kind === 'ann' ? 'ann' . $mark : $kind;
    if (!$tbNewer($mark, $tbHidden[$key] ?? null)) return;          // clicked already, nothing newer
    $new = $tbNewer($mark, $tbSeen[$kind] ?? null);
    $notifs[] = ['kind' => $kind, 'key' => $key, 'mark' => (string)$mark, 'icon' => $icon, 'text' => $text, 'link' => $link, 'new' => $new];
};
try {
    if (in_array($tbRole, ['admin','staff'])) {
        $r = $pdo->query("SELECT COUNT(*) c, MAX(id) m FROM appointments WHERE status='Pending'")->fetch();
        if ($r['c'] > 0) $tbAdd('pending', $r['m'], '⏳', "{$r['c']} pending appointment" . ($r['c'] > 1 ? 's' : '') . " to review", 'appointments?filter=Pending');

        // Appointments the PATIENT cancelled online (last 7 days) — the slot can be reused.
        try {
            $r = $pdo->query("SELECT COUNT(*) c, MAX(cancelled_at) m FROM appointments
                               WHERE cancelled_by='patient' AND cancelled_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch();
            if ($r['c'] > 0) $tbAdd('pcancel', $r['m'], '🚫', "{$r['c']} appointment" . ($r['c'] > 1 ? 's' : '') . " cancelled by patients", 'appointments?filter=Cancelled');
        } catch (Throwable $e) {}

        // Appointments the system thinks were missed, waiting for a decision.
        try {
            $r = $pdo->query("SELECT COUNT(*) c, MAX(id) m FROM appointments WHERE status='Needs Review'")->fetch();
            if ($r['c'] > 0) $tbAdd('missed', $r['m'], '📋', "{$r['c']} missed appointment" . ($r['c'] > 1 ? 's' : '') . " to review", 'noshow?tab=review');
        } catch (Throwable $e) {}

        // Patients paused for frequent cancellations, waiting for a review.
        try {
            if (!function_exists('patient_cancel_count')) require_once __DIR__ . '/noshow_check.php';
            $cr = 0;
            foreach ($pdo->query("SELECT DISTINCT patient_id FROM appointments WHERE status='Cancelled' AND cancelled_by='patient'")
                         ->fetchAll(PDO::FETCH_COLUMN) as $cpid) {
                if (patient_cancel_count($pdo, $cpid) >= CANCEL_LIMIT) $cr++;
            }
            if ($cr > 0) $tbAdd('freqcancel', $cr, '🔁', "$cr patient" . ($cr > 1 ? 's' : '') . " with frequent cancellations to review", 'noshow?tab=cancels');
        } catch (Throwable $e) {}

        // reviews table may not exist on older DBs — guard it
        try {
            $r = $pdo->query("SELECT COUNT(*) c, MAX(id) m FROM reviews WHERE status='Pending'")->fetch();
            if ($r['c'] > 0) $tbAdd('reviews', $r['m'], '⭐', "{$r['c']} new review" . ($r['c'] > 1 ? 's' : '') . " to moderate", 'reviews');
        } catch (Throwable $e) {}
    } elseif ($tbRole === 'dentist') {
        // Only pending appointments for THIS dentist's patients (matched by the
        // patient's primary dentist or the appointment's dentist).
        $r = $pdo->query(
            "SELECT COUNT(*) c, MAX(a.id) m FROM appointments a
             LEFT JOIN patients p ON a.patient_id = p.id
             WHERE a.status='Pending'
               AND (p.primary_dentist = " . $pdo->quote($tbName) . "
                    OR a.dentist = " . $pdo->quote($tbName) . ")"
        )->fetch();
        if ($r['c'] > 0) $tbAdd('pending', $r['m'], '⏳', "{$r['c']} pending appointment" . ($r['c'] > 1 ? 's' : '') . " for your patients", 'appointments?filter=Pending');
    } elseif ($tbRole === 'patient') {
        // Their own + family members' confirmed appointments.
        $pst = $pdo->prepare("SELECT id FROM patients WHERE user_id=?");
        $pst->execute([$_SESSION['user_id'] ?? 0]);
        $mypid = $pst->fetchColumn();
        if ($mypid) {
            $famIds = family_patient_ids($pdo, $mypid);
            $q = $pdo->prepare("SELECT COUNT(*) c, MAX(COALESCE(confirmed_at, '2000-01-01 00:00:00')) m FROM appointments
                                 WHERE patient_id IN (" . in_placeholders($famIds) . ") AND status='Confirmed'");
            $q->execute($famIds);
            $r = $q->fetch();
            if ($r['c'] > 0) $tbAdd('confirmed', $r['m'], '✅', "{$r['c']} confirmed appointment" . ($r['c'] > 1 ? 's' : ''), 'portal?view=appointments');
        }
    }

    // Everyone: announcements from the last 30 days (newest 3).
    $annLink = ['admin' => 'announcements', 'patient' => 'portal?view=news'][$tbRole] ?? 'dashboard';
    foreach ($pdo->query("SELECT id, title FROM announcements WHERE status='Published'
                           AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                         ORDER BY created_at DESC LIMIT 3")->fetchAll() as $an) {
        $tbAdd('ann', (int)$an['id'], '📣', 'Announcement: ' . $an['title'], $annLink);
    }
} catch (Throwable $e) { $notifs = []; }
$notifCount = count($notifs);
$badgeCount = count(array_filter($notifs, fn($n) => $n['new']));   // only what is new since the bell was last opened
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
        <div class="notif-empty"<?= $notifCount ? ' style="display:none;"' : '' ?>>🔔 You're all caught up!</div>
        <?php if ($notifCount > 0): ?>
            <?php foreach ($notifs as $n): ?>
                <a href="<?= e($n['link']) ?>" class="notif-item<?= $n['new'] ? ' is-new' : '' ?>" onclick="openNotif(event, this)"
                   data-kind="<?= e($n['kind']) ?>" data-key="<?= e($n['key']) ?>" data-mark="<?= e($n['mark']) ?>">
                    <span class="notif-ico"><?= $n['icon'] ?></span>
                    <span class="notif-txt"><?= e($n['text']) ?></span>
                    <?php if ($n['new']): ?><span class="notif-new-dot" aria-label="new"></span><?php endif; ?>
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
            <?php if ($tbRole === 'patient'): ?>
                <a href="portal?view=contact" class="pw-item">📞 Clinic Contact</a>
                <a href="portal?view=archive" class="pw-item" style="padding-left:34px;font-size:.9em;">🗄 My Archive</a>
            <?php endif; ?>
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
.notif-txt{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;}
.notif-item.is-new{background:#f0faf8;font-weight:600;}
.notif-new-dot{width:8px;height:8px;border-radius:50%;background:#e74c3c;flex:none;}

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
    /* Phones: the bell / profile menus open under the top bar, with a small gap
       on both sides, so they can never run off the edge of a narrow screen. */
    #notifMenu, #pwMenu{position:fixed;top:68px;left:12px;right:12px;width:auto;max-width:none;}
    #notifMenu .notif-item{padding:12px 14px;}
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
    // and tell the server which items were shown (the newest of each kind),
    // so the badge stays cleared on every page until something newer arrives.
    var dot = document.getElementById('notifDot');
    if (dot) {
        dot.remove();
        var marks = {};
        document.querySelectorAll('#notifMenu .notif-item[data-kind]').forEach(function (a) {
            var k = a.dataset.kind, m = a.dataset.mark;
            if (!(k in marks) || (isFinite(m) && isFinite(marks[k]) ? +m > +marks[k] : m > marks[k])) marks[k] = m;
        });
        var tk = document.querySelector('meta[name="csrf-token"]');
        fetch('notif_seen', { method: 'POST', credentials: 'same-origin',
              headers: { 'X-CSRF-Token': tk ? tk.content : '', 'Content-Type': 'application/json' },
              body: JSON.stringify({ marks: marks }) }).catch(function(){});
    }
}
// Clicking a notification removes it from the bell (remembered on the
// server), then opens its page.
function openNotif(e, a){
    e.preventDefault();
    e.stopPropagation();
    var hide = {}; hide[a.dataset.key] = a.dataset.mark;
    var tk = document.querySelector('meta[name="csrf-token"]');
    var done = function(){ window.location.href = a.getAttribute('href'); };
    a.remove();
    if (!document.querySelector('#notifMenu .notif-item')) {
        var em = document.querySelector('#notifMenu .notif-empty'); if (em) em.style.display = '';
    }
    fetch('notif_seen', { method: 'POST', credentials: 'same-origin', keepalive: true,
          headers: { 'X-CSRF-Token': tk ? tk.content : '', 'Content-Type': 'application/json' },
          body: JSON.stringify({ hide: hide }) }).then(done, done);
}
// Click anywhere else closes both menus.
document.addEventListener('click', function(){
    var m = document.getElementById('pwMenu'); if (m) m.classList.remove('open');
    var n = document.getElementById('notifMenu'); if (n) n.classList.remove('open');
});
</script>
