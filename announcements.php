<?php
// ============================================================
//  ANNOUNCEMENTS  (announcements.php) -- admin only
// ============================================================
//  Image 10 in the design.
//  Lists clinic announcements with filter pills (All / Pinned /
//  Emailed / Drafts) and Add / Edit / Publish / Pin / Delete.
//  Data comes from the `announcements` table. Published ones show in
//  the patient portal (to the Audience picked); "📧 Email" also emails
//  them. SMS needs a paid SMS provider, which is not set up.
// ============================================================
require_once 'config/auth.php';
require_once 'includes/mailer.php';   // real SMTP sending for announcements
require_login(['admin']);

// ---------- Add / Edit / Publish / Delete ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id       = $_POST['id'] ?? '';
        $title    = trim($_POST['title']);
        $content  = trim($_POST['content']);
        $audience = in_array($_POST['audience'] ?? '', ['All Patients', 'Upcoming Appts', 'Returning Patients'], true) ? $_POST['audience'] : 'All Patients';
        $status   = ($_POST['status'] ?? '') === 'Draft' ? 'Draft' : 'Published';

        if ($id) {
            $pdo->prepare("UPDATE announcements SET title=?,content=?,audience=?,status=? WHERE id=?")
                ->execute([$title,$content,$audience,$status,$id]);
        } else {
            $pdo->prepare("INSERT INTO announcements (title,content,channel,audience,status) VALUES (?,?,'Dashboard',?,?)")
                ->execute([$title,$content,$audience,$status]);
        }
        log_activity($pdo, $id ? 'Updated announcement' : 'Created announcement', $title);
        set_flash($id ? 'Announcement updated.' : 'Announcement created.');
        header("Location: announcements"); exit;
    }

    if ($action === 'publish') {
        $pdo->prepare("UPDATE announcements SET status='Published' WHERE id=?")->execute([$_POST['id']]);
        log_activity($pdo, 'Published announcement', '#' . $_POST['id']);
        set_flash('Announcement published.');
        header("Location: announcements"); exit;
    }

    // ---------- 📌 Pin / unpin: pinned ones stay at the top (here and in the patient portal) ----------
    if ($action === 'pin') {
        $pq = $pdo->prepare("SELECT title, pinned FROM announcements WHERE id = ?");
        $pq->execute([(int)$_POST['id']]);
        if ($pr = $pq->fetch()) {
            $pdo->prepare("UPDATE announcements SET pinned = ? WHERE id = ?")->execute([$pr['pinned'] ? 0 : 1, (int)$_POST['id']]);
            log_activity($pdo, $pr['pinned'] ? 'Unpinned announcement' : 'Pinned announcement', $pr['title']);
            set_flash($pr['pinned'] ? 'Announcement unpinned.' : 'Announcement pinned — it stays at the top for patients too.');
        }
        header("Location: announcements"); exit;
    }

    // ---------- Send an announcement to patients (real email) ----------
    if ($action === 'send') {
        $aid     = (int)$_POST['id'];
        $via     = $_POST['via'] ?? 'email';       // admin chose 'email' or 'sms'
        $subject = trim($_POST['subject'] ?? '');  // editable (email only)
        $message = trim($_POST['message'] ?? '');  // the editable message body

        // Always publish it so it shows in the patient portal too.
        $pdo->prepare("UPDATE announcements SET status='Published' WHERE id=?")->execute([$aid]);

        if ($via === 'sms') {
            set_flash('SMS sending is not set up. The announcement was published, but no SMS was sent.', 'error');
            header("Location: announcements"); exit;
        }

        if (!mail_is_ready($pdo)) {
            set_flash('Email is not configured yet. The announcement was published, but no email was sent. Set it up in Messaging Config.', 'error');
            header("Location: announcements"); exit;
        }

        // The patients this announcement is for (its Audience), each with an email address.
        $aq = $pdo->prepare("SELECT audience FROM announcements WHERE id = ?");
        $aq->execute([$aid]);
        $audience   = (string)($aq->fetchColumn() ?: 'All Patients');
        $recipients = announcement_recipients($pdo, $audience);

        if ($subject === '') $subject = 'Announcement from your dental clinic';
        $html = mail_template($subject, nl2br(e($message)));

        $sent = 0; $failed = 0; $lastErr = '';
        foreach ($recipients as $to) {
            $to = trim($to);
            if ($to === '') continue;
            $err = '';
            if (send_mail($pdo, $to, $subject, $html, $err, 'announcement')) {
                $sent++;
            } else {
                $failed++; $lastErr = $err;
            }
        }

        if ($sent > 0) {                                 // the Emailed filter and the card's "Emailed to N"
            $pdo->prepare("UPDATE announcements SET emailed_at = NOW(), emailed_count = emailed_count + ? WHERE id = ?")->execute([$sent, $aid]);
        }
        log_activity($pdo, 'Sent announcement', "#$aid to $sent patient(s) ($audience)" . ($failed ? ", $failed failed" : ''));
        if ($sent > 0 && $failed === 0) {
            set_flash("Announcement emailed to $sent patient(s) and posted in the portal.");
        } elseif ($sent > 0) {
            set_flash("Announcement emailed to $sent patient(s); $failed failed. Last error: $lastErr", 'info');
        } elseif (count($recipients) === 0) {
            set_flash('Announcement published, but no patient in "' . $audience . '" has an email address on file.', 'info');
        } else {
            set_flash("Could not send any emails. Last error: $lastErr", 'error');
        }
        header("Location: announcements"); exit;
    }

    if ($action === 'delete') {
        $done = 0;
        foreach (bulk_ids() as $delId) {          // one announcement, or several ticked ones
            $delInfo = $pdo->prepare("SELECT title FROM announcements WHERE id=?");
            $delInfo->execute([$delId]);
            $delTitle = $delInfo->fetchColumn();
            if ($delTitle === false) continue;
            $pdo->prepare("DELETE FROM announcements WHERE id=?")->execute([$delId]);
            log_activity($pdo, 'Deleted announcement', $delTitle ?: ('#' . $delId));
            $done++;
        }
        set_flash($done === 1 ? 'Announcement deleted.' : "$done announcements deleted.", 'info');
        header("Location: announcements"); exit;
    }
}

// ---------- Filtering (the pills at the top) ----------
$filter = $_GET['filter'] ?? 'all';
$sql = "SELECT * FROM announcements";
if ($filter === 'pinned')  $sql .= " WHERE pinned = 1";
if ($filter === 'emailed') $sql .= " WHERE emailed_at IS NOT NULL";        // really emailed (📧 Email)
if ($filter === 'drafts')  $sql .= " WHERE status='Draft'";
$sql .= " ORDER BY pinned DESC, created_at DESC";
$announcements = $pdo->query($sql)->fetchAll();

// The numbers on the cards — all real.
$totalPosts = $pdo->query("SELECT COUNT(*) FROM announcements")->fetchColumn();
$emailsSent = 0;                                   // every announcement email that went out (email_log)
try { $emailsSent = (int)$pdo->query("SELECT COUNT(*) FROM email_log WHERE kind = 'announcement' AND status = 'sent'")->fetchColumn(); }
catch (Throwable $e) {}
// How many patients each audience reaches by email (shown before sending).
$audReach = [];
foreach (['All Patients', 'Upcoming Appts', 'Returning Patients'] as $au) $audReach[$au] = count(announcement_recipients($pdo, $au));

$page_title = "Announcements";
include 'includes/head.php';
$active = 'announcements';

// little helper to make a filter pill link
function pill($key, $label, $current) {
    $cls = ($current === $key) ? 'btn-outline-teal' : 'btn-light';
    echo "<a href='announcements?filter=$key' class='btn btn-sm $cls' data-keep-text>$label</a> ";   // filter tab: keeps its name
}
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div>
                <h1 style="color:var(--teal-light)">Announcements</h1>
                <div class="sub">Post updates to the patient portal, and email them to patients</div>
            </div>
        </div>

        <?php include 'includes/admin_tabs.php'; ?>

        <div class="flex-between mb-3">
            <div>
                <h4 class="mb-0">Clinic Announcements</h4>
                <div class="text-muted2" style="font-size:.85rem;">Published posts show in the patient portal (to the audience you pick); 📧 Email also sends them by email.</div>
            </div>
            <button class="btn btn-teal" data-bs-toggle="modal" data-bs-target="#annModal" onclick="openAdd()">+ New Announcement</button>
        </div>

        <!-- ===== Stat cards ===== -->
        <div class="stat-grid">
            <div class="stat-card"><div class="value">📣 <?= $totalPosts ?></div><div class="label">Total Posts</div></div>
            <div class="stat-card"><div class="value">📧 <?= $emailsSent ?></div><div class="label">Emails Sent</div></div>
            <div class="stat-card" title="Text messages need a paid SMS provider (e.g. Semaphore) and its API key in Messaging Config">
                <div class="value" style="color:#8aa0a0;">💬 Off</div><div class="label">SMS — not set up</div></div>
        </div>

        <!-- ===== Filter pills ===== -->
        <div class="mb-3">
            <?php
                pill('all',    'All',      $filter);
                pill('pinned',  '📌 Pinned',  $filter);
                pill('emailed', '📧 Emailed', $filter);
                pill('drafts',  '🗒 Drafts',  $filter);
            ?>
        </div>

        <!-- ===== Announcement cards ===== -->
        <?= bulk_bar('bulk-ann', 'delete', 'announcements') ?>
        <?php foreach ($announcements as $a):
            $isDraft = ($a['status'] === 'Draft');
        ?>
            <div class="card-box mb-3" style="<?= $isDraft ? '' : 'border-left:4px solid var(--gold);' ?><?= !empty($a['pinned']) ? 'background:#fffbf0;' : '' ?>">
                <div class="flex-between">
                    <div style="flex:1;">
                        <h6 class="mb-1">
                            <?= !empty($a['pinned']) ? '📌 ' : '' ?><?= e($a['title']) ?>
                            <span class="badge-pill <?= $isDraft ? 'b-pending' : 'b-active' ?>"><?= e($a['status']) ?></span>
                        </h6>
                        <div class="text-muted2 mb-2" style="font-size:.88rem;"><?= e($a['content']) ?></div>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php if (!$isDraft): ?><span class="badge-pill b-progress">📣 Patient portal</span><?php endif; ?>
                            <?php if (!empty($a['emailed_at'])): ?>
                                <span class="badge-pill b-completed">📧 Emailed to <?= (int)$a['emailed_count'] ?> · <?= date('M j', strtotime($a['emailed_at'])) ?></span>
                            <?php endif; ?>
                            <span class="badge-pill b-confirmed"><?= e($a['audience']) ?></span>
                            <span class="badge-pill b-inactive">📅 <?= date('M j, Y', strtotime($a['created_at'])) ?></span>
                        </div>
                    </div>
                    <div class="d-flex gap-1 align-items-start">
                        <button class="btn btn-sm btn-light" onclick='openEdit(<?= json_encode($a) ?>)' data-bs-toggle="modal" data-bs-target="#annModal">✏️ Edit</button>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="pin"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button class="btn btn-sm btn-light" data-keep-text title="<?= !empty($a['pinned']) ? 'Unpin' : 'Pin: keep it at the top (here and in the patient portal)' ?>"><?= !empty($a['pinned']) ? '📌 Unpin' : '📌 Pin' ?></button>
                        </form>
                        <?php if ($isDraft): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="publish"><input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <button class="btn btn-sm btn-teal">✓ Publish</button>
                            </form>
                        <?php endif; ?>
                        <!-- Send options: open a compose box where the admin can EDIT
                             the message before sending (Email or SMS). -->
                        <button class="btn btn-sm" style="background:#d6e4ff;color:#2563eb;"
                                onclick='openSend("email", <?= json_encode($a['id']) ?>, <?= json_encode($a['title']) ?>, <?= json_encode($a['content']) ?>, <?= json_encode($a['audience']) ?>, <?= (int)($audReach[$a['audience']] ?? 0) ?>)'
                                data-bs-toggle="modal" data-bs-target="#sendModal">📧 Email</button>
                        <!-- SMS needs a paid SMS provider (e.g. Semaphore) — not set up, so the button is off -->
                        <span title="SMS is not set up: it needs a paid SMS provider (e.g. Semaphore) and its API key in Messaging Config">
                            <button class="btn btn-sm btn-light" disabled data-keep-text>📱 SMS</button></span>
                        <?= bulk_pick('bulk-ann', $a['id'], 'Select this announcement') ?>
                        <form method="POST" class="d-inline" onsubmit="return confirmDelete('Delete this announcement?')">
                            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $a['id'] ?>">
                            <button class="btn btn-sm" style="background:#fbdcdc;color:#c0392b;">🗑</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (empty($announcements)): ?>
            <div class="card-box text-center text-muted2">No announcements found for this filter.</div>
        <?php endif; ?>
    </main>
</div>

<!-- Add/Edit announcement modal -->
<div class="modal fade" id="annModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
        <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="a-id">
        <div class="modal-header"><h5 class="modal-title" id="a-title">New Announcement</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <label class="field-label">Title</label>
            <input name="title" id="a-title-in" class="form-control mb-3" required>

            <label class="field-label">Content</label>
            <textarea name="content" id="a-content" class="form-control mb-3" rows="3"></textarea>

            <label class="field-label">Who is it for?</label>
            <select name="audience" id="a-audience" class="form-select mb-1">
                <option value="All Patients">All Patients</option>
                <option value="Upcoming Appts">Patients with an upcoming appointment</option>
                <option value="Returning Patients">Returning patients (visited before)</option>
            </select>
            <div class="text-muted2 mb-3" style="font-size:.78rem;">Only these patients see it in their portal, and 📧 Email sends it only to them.</div>

            <label class="field-label">Status</label>
            <select name="status" id="a-status" class="form-select">
                <option>Published</option><option>Draft</option>
            </select>
        </div>
        <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-teal">Save</button></div>
    </form>
</div></div></div>

<!-- Compose / edit message before sending (Email or SMS) -->
<div class="modal fade" id="sendModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="POST" onsubmit="return confirm('Send this email to ' + SEND_TO + '?')">
        <input type="hidden" name="action" value="send">
        <input type="hidden" name="via" id="send-via">
        <input type="hidden" name="id" id="send-id">
        <div class="modal-header"><h5 class="modal-title" id="send-heading">Send via Email</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="text-muted2 mb-3" style="font-size:.85rem;">Edit the message below before sending. <span id="send-to"></span></div>

            <!-- Subject only shows for Email -->
            <div id="send-subject-wrap">
                <label class="field-label">Subject</label>
                <input name="subject" id="send-subject" class="form-control mb-3">
            </div>

            <label class="field-label">Message</label>
            <textarea name="message" id="send-message" class="form-control" rows="5" oninput="countSms()"></textarea>
            <!-- Character counter only shows for SMS -->
            <div id="send-sms-counter" class="text-muted2 mt-1" style="font-size:.78rem;display:none;">
                <span id="send-sms-count">0</span> characters (about <span id="send-sms-parts">1</span> SMS)
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-teal" id="send-btn">📤 Send</button></div>
    </form>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
<script>
function openAdd(){
    document.getElementById('a-title').textContent='New Announcement';
    document.getElementById('a-id').value='';
    document.getElementById('a-title-in').value='';
    document.getElementById('a-content').value='';
    document.getElementById('a-audience').value='All Patients';
    document.getElementById('a-status').value='Published';
}
function openEdit(a){
    document.getElementById('a-title').textContent='Edit Announcement';
    document.getElementById('a-id').value=a.id;
    document.getElementById('a-title-in').value=a.title;
    document.getElementById('a-content').value=a.content;
    document.getElementById('a-audience').value=a.audience;
    document.getElementById('a-status').value=a.status;
}

// Opens the compose box, pre-filled from the announcement, so the admin can edit
// the message before sending. via = 'email' or 'sms'.
var SEND_TO = 'the matching patients';
function openSend(via, id, title, content, audience, reach){
    SEND_TO = reach + ' patient' + (reach === 1 ? '' : 's');
    document.getElementById('send-to').textContent = audience
        ? 'It goes to ' + SEND_TO + ' (' + audience + ', with an email address on file).' : '';
    document.getElementById('send-via').value = via;
    document.getElementById('send-id').value  = id;
    document.getElementById('send-message').value = content;

    var isEmail = (via === 'email');
    document.getElementById('send-heading').textContent = isEmail ? 'Send via Email 📧' : 'Send via SMS 📱';
    document.getElementById('send-btn').textContent = isEmail ? '📧 Send Email' : '📱 Send SMS';

    // Email shows a Subject; SMS shows a character counter instead.
    document.getElementById('send-subject-wrap').style.display   = isEmail ? 'block' : 'none';
    document.getElementById('send-sms-counter').style.display    = isEmail ? 'none'  : 'block';
    if (isEmail) document.getElementById('send-subject').value = title;
    countSms();
}

// Live character / SMS-parts counter (an SMS is ~160 characters).
function countSms(){
    var len = document.getElementById('send-message').value.length;
    document.getElementById('send-sms-count').textContent = len;
    document.getElementById('send-sms-parts').textContent = Math.max(1, Math.ceil(len / 160));
}
</script>
</body></html>
