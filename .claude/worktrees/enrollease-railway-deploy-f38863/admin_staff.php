<?php
// ============================================================
//  STAFF  (admin_staff.php) -- admin only
// ============================================================
//  The front-desk team as cards, like the Dentists page:
//    + Add Staff      -> a strong temporary password is shown (and emailed)
//    👁 View / edit   -> their details, and a link to what they did
//    🗑               -> to the Archive (the admin can restore them)
//  The number on each card: the bookings and walk-ins they entered in the
//  last 30 days (from the activity log).
// ============================================================
require_once 'config/auth.php';
require_login(['admin']);
require_once 'includes/mailer.php';
require_once 'includes/message_templates.php';    // to email new staff their login details

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---------- Add, or save changes to, a staff member ----------
    if (in_array($action, ['add_staff', 'edit_staff'], true)) {
        $sid   = $action === 'edit_staff' ? (int)($_POST['id'] ?? 0) : 0;
        $title = trim($_POST['title'] ?? '');
        $first = trim($_POST['first_name'] ?? '');
        $last  = trim($_POST['last_name'] ?? '');
        $name  = trim(preg_replace('/\s+/', ' ', "$title $first $last"));
        $email = strtolower(trim($_POST['email'] ?? ''));
        $position = mb_substr(trim($_POST['position'] ?? ''), 0, 100);
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        [$contact, $phoneError] = validate_phone($_POST['contact'] ?? '');

        $err = '';
        if ($first === '' || $last === '')  $err = 'Please enter both a first name and a last name.';
        elseif ($phoneError !== '')         $err = $phoneError;
        else                                $err = validate_account_email($pdo, $email, $sid ?: null);   // real, and not taken
        if ($sid) {
            $was = $pdo->prepare("SELECT name, email, specialty, contact, status FROM users WHERE id = ? AND role = 'staff' AND status <> 'archived'");
            $was->execute([$sid]); $was = $was->fetch();
            if (!$was) $err = 'That staff member could not be found.';
        }
        if ($err !== '') { set_flash($err, 'error'); header("Location: admin_staff"); exit; }

        if ($sid) {
            $pdo->prepare("UPDATE users SET name = ?, email = ?, specialty = ?, contact = ?, status = ? WHERE id = ? AND role = 'staff'")
                ->execute([$name, $email, $position, $contact, $status, $sid]);
            save_name_parts($pdo, 'users', $sid, $first, $last);
            $chg = change_list($was, ['name' => $name, 'email' => $email, 'specialty' => $position, 'contact' => $contact, 'status' => $status],
                               ['name' => 'Name', 'email' => 'Email', 'specialty' => 'Position', 'contact' => 'Phone', 'status' => 'Status']);
            log_activity($pdo, 'Updated user', "$name (staff)" . ($chg ? ' — ' . $chg : ''));
            set_flash("$name's details were saved.");
            header("Location: admin_staff"); exit;
        }

        // New staff get a random Strong temporary password (shown once, emailed).
        $tempPass = generate_temp_password();
        try {
            $pdo->prepare("INSERT INTO users (name, email, password, role, specialty, contact, status) VALUES (?, ?, ?, 'staff', ?, ?, 'active')")
                ->execute([$name, $email, password_hash($tempPass, PASSWORD_DEFAULT), $position, $contact]);
            save_name_parts($pdo, 'users', $pdo->lastInsertId(), $first, $last);
        } catch (PDOException $ex) {
            set_flash('That email is already in use. Please use a different email.', 'error');
            header("Location: admin_staff"); exit;
        }
        $note = '';
        if (mail_is_ready($pdo)) {
            $cat = message_catalogue()['account_welcome'];
            [$wSubj, $body] = tpl_message($pdo, 'account_welcome', $cat['subject'], $cat['body'], [
                'name' => $name, 'email' => $email, 'password' => $tempPass, 'clinic' => clinic_name($pdo),
            ]);
            $mErr = '';
            $note = send_mail($pdo, $email, $wSubj, $body, $mErr, 'staff_welcome')
                  ? ' Their login details were emailed to them.'
                  : " (Note: the welcome email could not be sent — $mErr)";
        }
        log_activity($pdo, 'Created user', "$name (staff)");
        set_flash("$name added. Temporary password: $tempPass — they should change it after signing in." . $note);
        header("Location: admin_staff"); exit;
    }

    // ---------- Move to the Archive (one, or several ticked) ----------
    if ($action === 'delete_staff') {
        $n = 0;
        foreach (bulk_ids() as $sid) {
            $s = $pdo->prepare("SELECT name FROM users WHERE id = ? AND role = 'staff' AND status <> 'archived'");
            $s->execute([$sid]);
            if (!($sname = $s->fetchColumn())) continue;
            archive_user($pdo, $sid, $_SESSION['name'] ?? null);   // (logs "Archived user")
            $n++;
        }
        set_flash($n === 1 ? 'Staff member moved to the Archive.' : "$n staff members moved to the Archive.", 'info');
        header("Location: admin_staff"); exit;
    }
}

// ---------- The cards ----------
$staffList = $pdo->query("SELECT id, name, first_name, last_name, email, specialty, position, contact, status, last_login, photo
                            FROM users WHERE role = 'staff' AND status <> 'archived' ORDER BY name")->fetchAll();
// Bookings + walk-ins each one entered in the last 30 days.
$workDone = [];
try {
    foreach ($pdo->query("SELECT actor_user_id, COUNT(*) n FROM activity_log
                           WHERE action IN ('Booked appointment for patient', 'Walk-in visit')
                             AND created_at >= NOW() - INTERVAL 30 DAY GROUP BY actor_user_id") as $r) {
        $workDone[(int)$r['actor_user_id']] = (int)$r['n'];
    }
} catch (Throwable $e) {}

function staff_initials($name) {
    $parts = preg_split('/\s+/', trim(preg_replace('/^(Mr|Ms|Mrs|Dr|Dra)\.\s+/i', '', $name)));
    return strtoupper(substr($parts[0] ?? '', 0, 1) . substr($parts[1] ?? '', 0, 1));
}

$page_title = "Staff";
include 'includes/head.php';
$active = 'staff';
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div><h1>Staff</h1><div class="sub">The front-desk team: their details, and the bookings and walk-ins they handle</div></div>
            <div class="d-flex align-items-center gap-3">
                <div class="clock"><span class="time" id="clock"></span><br><span id="clock-date"></span></div>
                <button class="btn btn-teal" data-bs-toggle="modal" data-bs-target="#staffModal" onclick="staffForm(null)" data-keep-text>+ Add Staff</button>
            </div>
        </div>

        <div class="mb-3"><?= bulk_bar('bulk-staff', 'delete_staff', 'staff to the Archive', [], '🗑 Move selected to Archive', 'They can no longer sign in. You can restore them from the Archive.', 'Move') ?></div>
        <div class="staff-grid">
            <?php foreach ($staffList as $st):
                $position = $st['specialty'] ?: ($st['position'] ?: '');
                $done = $workDone[(int)$st['id']] ?? 0;
                $form = ['id' => (int)$st['id'], 'name' => $st['name'], 'first_name' => (string)$st['first_name'], 'last_name' => (string)$st['last_name'],
                         'email' => $st['email'], 'position' => $position,
                         'contact' => (string)$st['contact'], 'status' => $st['status']]; ?>
                <div class="card-box h-100 mb-0">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <?php if (!empty($st['photo']) && is_file(__DIR__ . '/' . $st['photo'])): ?>
                            <img src="<?= e($st['photo']) ?>" alt="" style="width:52px;height:52px;border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                            <span class="avatar" style="background:#5e8a86;width:52px;height:52px;font-size:1.1rem;"><?= e(staff_initials($st['name'])) ?></span>
                        <?php endif; ?>
                        <div>
                            <h6 class="mb-0"><?= e($st['name']) ?></h6>
                            <span class="badge-pill <?= $st['status'] === 'active' ? 'b-active' : 'b-inactive' ?>"><?= ucfirst($st['status']) ?></span>
                        </div>
                    </div>
                    <div class="text-muted2 mb-1" style="font-size:.85rem;">💼 <?= e($position ?: 'Front desk') ?></div>
                    <div class="text-muted2 mb-1" style="font-size:.85rem;">📧 <?= e($st['email']) ?></div>
                    <div class="text-muted2 mb-1" style="font-size:.85rem;">📞 <?= e($st['contact'] ?: '–') ?></div>
                    <div class="text-muted2 mb-3" style="font-size:.85rem;">🕒 Last sign-in: <?= $st['last_login'] ? date('M j, Y g:i A', strtotime($st['last_login'])) : 'not yet' ?></div>

                    <div class="flex-between" style="border-top:1px solid #eee;padding-top:10px;">
                        <span title="Bookings and walk-ins they entered in the last 30 days">
                            <strong style="font-size:1.3rem;color:var(--teal-mid);"><?= $done ?></strong>
                            <span class="text-muted2" style="font-size:.85rem;">bookings &amp; walk-ins · 30 days</span></span>
                        <div class="d-flex gap-1 align-items-center">
                            <?= bulk_pick('bulk-staff', $st['id'], 'Select ' . $st['name']) ?>
                            <button type="button" class="btn btn-sm btn-teal" data-bs-toggle="modal" data-bs-target="#staffModal"
                                    onclick='staffForm(<?= json_encode($form, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>👁 View</button>
                            <form method="POST" class="m-0" onsubmit="return confirm('Move <?= e(addslashes($st['name'])) ?> to the Archive? They can no longer sign in. You can restore them from the Archive.')">
                                <input type="hidden" name="action" value="delete_staff">
                                <input type="hidden" name="id" value="<?= (int)$st['id'] ?>">
                                <button class="btn btn-sm" style="background:#fbdcdc;color:#c0392b;" title="Move to Archive">🗑</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$staffList): ?>
                <div class="sg-full"><div class="card-box text-center text-muted2">No staff yet. Click <strong>+ Add Staff</strong> to create one.</div></div>
            <?php endif; ?>
        </div>

        <!-- Add / view / edit a staff member -->
        <div class="modal fade" id="staffModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" id="s-action" value="add_staff">
                <input type="hidden" name="id" id="s-id">
                <div class="modal-header"><h5 class="modal-title" id="s-title">Add Staff</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-4"><label class="field-label">Title</label>
                            <select name="title" id="s-title-sel" class="form-select mb-3">
                                <option value="">(none)</option><option>Mr.</option><option>Ms.</option><option>Mrs.</option>
                            </select></div>
                        <div class="col-8"><label class="field-label">First Name</label>
                            <input name="first_name" id="s-first" class="form-control mb-3" placeholder="Juan" required></div>
                    </div>
                    <label class="field-label">Last Name</label>
                    <input name="last_name" id="s-last" class="form-control mb-3" placeholder="Dela Cruz" required>

                    <label class="field-label">Email</label>
                    <input type="email" name="email" id="s-email" class="form-control mb-1" placeholder="name@gmail.com" required>
                    <div class="text-muted2 mb-3" style="font-size:.78rem;" id="s-email-help">Must be a real, working address — their login details are emailed here.</div>

                    <label class="field-label">Position</label>
                    <input name="position" id="s-position" class="form-control mb-3" placeholder="e.g. Receptionist, Front desk, Dental assistant">

                    <label class="field-label">Contact Number</label>
                    <input name="contact" id="s-contact" class="form-control mb-3" placeholder="09XX XXX XXXX" <?= phone_input_attrs() ?> required>

                    <div id="s-status-wrap">
                        <label class="field-label">Status</label>
                        <select name="status" id="s-status" class="form-select mb-3">
                            <option value="active">Active — can sign in</option><option value="inactive">Inactive — cannot sign in</option>
                        </select>
                        <a id="s-activity" href="admin_activity" class="small" style="color:var(--teal);">See what they did in the Activity Log →</a>
                    </div>
                    <div class="alert alert-secondary py-2 mb-0" id="s-new-note" style="font-size:.83rem;">
                        A strong temporary password is created and shown (and emailed) after saving. They can change it later.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-teal" id="s-save">Add Staff</button>
                </div>
            </form>
        </div></div></div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
<script>
startClock();
// null = a new staff member; otherwise their details, ready to change.
function staffForm(s) {
    var $ = function (id) { return document.getElementById(id); };
    var parts = s ? s.name.trim().split(/\s+/) : [], title = '';
    if (parts.length && ['Mr.', 'Ms.', 'Mrs.'].indexOf(parts[0]) !== -1) title = parts.shift();
    if (s && s.first_name) parts = [s.first_name].concat(s.last_name ? [s.last_name] : []);   // the saved first / last name
    $('s-action').value = s ? 'edit_staff' : 'add_staff';
    $('s-id').value = s ? s.id : '';
    $('s-title').textContent = s ? s.name : 'Add Staff';
    $('s-save').textContent = s ? 'Save changes' : 'Add Staff';
    $('s-title-sel').value = title;
    $('s-first').value = s ? (parts.shift() || '') : '';
    $('s-last').value = s ? parts.join(' ') : '';
    $('s-email').value = s ? s.email : '';
    $('s-position').value = s ? s.position : '';
    $('s-contact').value = s ? s.contact : '';
    $('s-status').value = s ? s.status : 'active';
    $('s-status-wrap').style.display = s ? '' : 'none';
    $('s-new-note').style.display = s ? 'none' : '';
    $('s-email-help').textContent = s ? 'This is their sign-in email.' : 'Must be a real, working address — their login details are emailed here.';
    $('s-activity').href = 'admin_activity?q=' + encodeURIComponent(s ? s.name : '');
}
</script>
<style>
/* Staff cards share the full width and re-flow as staff are added (like the Dentists page). */
.staff-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; }
.staff-grid .sg-full { grid-column: 1 / -1; }
@media (max-width: 400px) { .staff-grid { grid-template-columns: 1fr; } }
</style>
</body>
</html>
