<?php
// ============================================================
//  USER MANAGEMENT  (admin_users.php) -- admin only
// ============================================================
require_once 'config/auth.php';
require_once 'includes/mailer.php';
require_once 'includes/message_templates.php';   // to email new accounts their login details
require_login(['admin']);   // <-- only admins can open this page

// ---------- Add / Edit / Delete users ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id    = $_POST['id'] ?? '';
        $role  = $_POST['role'];
        $spec  = trim($_POST['specialty'] ?? '');
        [$contact, $phoneError] = validate_phone($_POST['contact'] ?? '', false);   // optional, but must be real
        if ($phoneError !== '') {
            set_flash($phoneError, 'error');
            header("Location: admin_users"); exit;
        }
        $status  = $_POST['status'];
        $email = strtolower(trim($_POST['email']));

        // The form asks for the name in parts, but we store ONE name because
        // patients.primary_dentist and appointments.dentist are matched on it.
        $title = trim($_POST['title'] ?? '');
        $first = trim($_POST['first_name'] ?? '');
        $last  = trim($_POST['last_name'] ?? '');
        $name  = trim(preg_replace('/\s+/', ' ', "$title $first $last"));

        if ($first === '' || $last === '') {
            set_flash('Please enter both a first name and a last name.', 'error');
            header("Location: admin_users"); exit;
        }

        // Make sure the email is real and not already taken.
        $emailError = validate_account_email($pdo, $email, $id ?: null);
        if ($emailError !== '') {
            set_flash($emailError, 'error');
            header("Location: admin_users"); exit;
        }

        if ($id) {
            $pdo->prepare("UPDATE users SET name=?,email=?,role=?,specialty=?,contact=?,status=? WHERE id=?")
                ->execute([$name,$email,$role,$spec,$contact,$status,$id]);
            log_activity($pdo, 'Updated user', "$name ($role)");
            set_flash('User updated.');
        } else {
            // New users get a random Strong temporary password (shown once, emailed).
            $tempPass = generate_temp_password();
            $hash = password_hash($tempPass, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO users (name,email,password,role,specialty,contact,status) VALUES (?,?,?,?,?,?,?)")
                ->execute([$name,$email,$hash,$role,$spec,$contact,$status]);

            // Email them their login details — useful, and it proves the address works.
            $note = '';
            if (mail_is_ready($pdo)) {
                $cat = message_catalogue()['account_welcome'];
                [$wSubj, $body] = tpl_message($pdo, 'account_welcome', $cat['subject'], $cat['body'], [
                    'name'     => $name,
                    'email'    => $email,
                    'password' => $tempPass,
                    'clinic'   => clinic_name($pdo),
                ]);
                $err = '';
                $note = send_mail($pdo, $email, $wSubj, $body, $err, 'account_welcome')
                      ? ' Their login details were emailed to them.'
                      : " (Note: the welcome email could not be sent — $err)";
            }
            log_activity($pdo, 'Created user', "$name ($role)");
            set_flash("$name added. Temporary password: $tempPass — please give it to them; they should change it after signing in." . $note);
        }
        header("Location: admin_users"); exit;
    }

    if ($action === 'delete') {
        $moved = []; $skippedSelf = false;
        foreach (bulk_ids() as $delId) {          // one user, or several ticked ones
            // Don't let an admin delete their own account while logged in.
            if ($delId === (int)($_SESSION['user_id'] ?? 0)) { $skippedSelf = true; continue; }

            // Look up what we are about to move so the message can be honest.
            $info = $pdo->prepare("SELECT name, role FROM users WHERE id=?");
            $info->execute([$delId]);
            $u = $info->fetch();
            if (!$u) continue;

            // Deleting no longer removes the account right away — it moves it to
            // the Archive first. An admin can restore it or permanently delete it
            // from there.
            archive_user($pdo, $delId, $_SESSION['name'] ?? null);
            $moved[] = $u['name'];
        }
        $msg = count($moved) === 1 ? $moved[0] . ' moved to Archive.' : (count($moved) . ' users moved to Archive.');
        if ($skippedSelf) $msg = (count($moved) ? $msg . ' ' : '') . 'You cannot delete the account you are signed in with.';
        set_flash($msg, $skippedSelf && !$moved ? 'error' : 'info');
        header("Location: admin_users"); exit;
    }
}

// ---------- Stats ----------
$totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$doctors    = $pdo->query("SELECT COUNT(*) FROM users WHERE role='dentist'")->fetchColumn();
$staff      = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
$patientsU  = $pdo->query("SELECT COUNT(*) FROM users WHERE role='patient'")->fetchColumn();

$users = $pdo->query("SELECT * FROM users WHERE status <> 'archived' ORDER BY role, name")->fetchAll();
$archivedCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'archived'")->fetchColumn();

$page_title = "User Management";
include 'includes/head.php';
$active = 'users';
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div><h1 style="color:var(--teal-light)">User Management</h1><div class="sub">Manage doctors, staff, and patient accounts</div></div>
        </div>

        <!-- Top tabs shared across admin pages -->
        <?php include 'includes/admin_tabs.php'; ?>

        <!-- Stat cards -->
        <div class="stat-grid">
            <div class="stat-card"><div class="value"><?= $totalUsers ?></div><div class="label">Total Users</div><div class="bar"><span style="width:100%"></span></div></div>
            <div class="stat-card"><div class="value"><?= $doctors ?></div><div class="label">Doctors</div><div class="bar"><span style="width:40%;background:#a06fd6"></span></div></div>
            <div class="stat-card"><div class="value"><?= $staff ?></div><div class="label">Staff</div><div class="bar"><span style="width:30%;background:#14b8a6"></span></div></div>
            <div class="stat-card"><div class="value"><?= $patientsU ?></div><div class="label">Patients</div><div class="bar"><span style="width:80%;background:#ec4899"></span></div></div>
        </div>

        <div class="card-box">
            <div class="flex-between mb-3">
                <h5 class="mb-0">System Users <small class="text-muted2 d-block" style="font-size:.75rem;">Showing <?= count($users) ?> users</small></h5>
                <div class="d-flex gap-2">
                    <a href="admin_archive" class="btn btn-sm btn-outline-secondary position-relative" title="Archive" data-keep-text>
                        🗄 Archive
                        <?php if ($archivedCount > 0): ?>
                            <span class="badge-pill b-inactive" style="margin-left:4px;"><?= $archivedCount ?></span>
                        <?php endif; ?>
                    </a>
                    <button class="btn btn-teal" data-bs-toggle="modal" data-bs-target="#userModal" onclick="openAddUser()">+ Add User</button>
                </div>
            </div>
            <?= bulk_bar('bulk-users', 'delete', 'users to the Archive', [], '🗑 Move selected to Archive', 'They can be restored from the Archive.', 'Move') ?>
            <div class="table-responsive">
                <table class="data">
                    <thead><tr><th>User</th><th>Role</th><th>Specialty / Position</th><th>Contact</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $u):
                        $initials = strtoupper(substr($u['name'],0,1) . (strpos($u['name'],' ') ? substr(strstr($u['name'],' '),1,1) : ''));
                        $roleBadge = ['admin'=>'b-progress','staff'=>'b-confirmed','dentist'=>'b-progress','patient'=>'b-pending'][$u['role']] ?? 'b-pending';
                    ?>
                        <tr>
                            <td><div class="d-flex align-items-center gap-2">
                                <span class="avatar" style="background:#5e8a86;"><?= e($initials) ?></span>
                                <div><strong><?= e($u['name']) ?></strong><br><small class="text-muted2"><?= e($u['email']) ?></small></div>
                            </div></td>
                            <td><span class="badge-pill <?= $roleBadge ?>"><?= ucfirst($u['role']) ?></span></td>
                            <td><?= e($u['specialty'] ?: $u['position'] ?: '-') ?></td>
                            <td><?= e($u['contact']) ?></td>
                            <td><span class="badge-pill b-<?= $u['status'] ?>"><?= ucfirst($u['status']) ?></span></td>
                            <td><small class="text-muted2"><?= $u['last_login'] ? date('M j, g:i A', strtotime($u['last_login'])) : '-' ?></small></td>
                            <td>
                                <?php if ((int)$u['id'] !== (int)($_SESSION['user_id'] ?? 0)) echo bulk_pick('bulk-users', $u['id'], 'Select ' . $u['name']); ?>
                                <button class="btn btn-sm btn-outline-secondary" onclick='openEditUser(<?= json_encode($u) ?>)' data-bs-toggle="modal" data-bs-target="#userModal">✏️ Edit</button>
                                <form method="POST" class="d-inline" onsubmit="return confirmDelete('Move this user to Archive?')">
                                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <button class="btn btn-sm" style="background:#fbdcdc;color:#c0392b;" title="Move to Archive">🗑</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- Add/Edit user modal -->
<div class="modal fade" id="userModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
        <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="u-id">
        <div class="modal-header"><h5 class="modal-title" id="u-title">Add User</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="row">
                <div class="col-4">
                    <label class="field-label">Title</label>
                    <select name="title" id="u-title-sel" class="form-select mb-3">
                        <option value="">(none)</option>
                        <option value="Dr.">Dr.</option>
                        <option value="Dra.">Dra.</option>
                        <option value="Mr.">Mr.</option>
                        <option value="Ms.">Ms.</option>
                        <option value="Mrs.">Mrs.</option>
                    </select>
                </div>
                <div class="col-8">
                    <label class="field-label">First Name</label>
                    <input name="first_name" id="u-first" class="form-control mb-3" placeholder="Juan" required>
                </div>
            </div>
            <label class="field-label">Last Name</label>
            <input name="last_name" id="u-last" class="form-control mb-3" placeholder="Dela Cruz" required>

            <label class="field-label">Email</label>
            <input type="email" name="email" id="u-email" class="form-control mb-1" placeholder="name@gmail.com" required>
            <div class="text-muted2 mb-3" style="font-size:.78rem;">
                Must be real — we check the domain and email their login details here.
            </div>

            <label class="field-label">Role</label>
            <select name="role" id="u-role" class="form-select mb-3"><option value="admin">Admin</option><option value="staff">Staff</option><option value="dentist">Dentist</option><option value="patient">Patient</option></select>
            <label class="field-label">Specialty / Position</label><input name="specialty" id="u-spec" class="form-control mb-3">
            <label class="field-label">Contact</label><input name="contact" id="u-contact" class="form-control mb-3" placeholder="09XX XXX XXXX"
                   <?= phone_input_attrs() ?>>
            <label class="field-label">Status</label><select name="status" id="u-status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select>
        </div>
        <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-teal">Save</button></div>
    </form>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js"></script>
<script>
function openAddUser(){
    u_title.textContent='Add User'; document.getElementById('u-id').value='';
    ['u-first','u-last','u-email','u-spec','u-contact'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('u-title-sel').value='';
    document.getElementById('u-role').value='dentist'; document.getElementById('u-status').value='active';
}

// The name is stored as one string, so when editing we split it back into
// Title / First / Last to fill the three boxes.
function splitName(full){
    var parts = (full || '').trim().split(/\s+/);
    var titles = ['Dr.','Dra.','Mr.','Ms.','Mrs.'];
    var title = '';
    if (parts.length && titles.indexOf(parts[0]) !== -1) { title = parts.shift(); }
    var first = parts.length ? parts.shift() : '';
    var last  = parts.join(' ');          // anything left is the surname
    if (last === '') { last = first; first = ''; }   // single-word name
    return { title: title, first: first, last: last };
}

function openEditUser(u){
    u_title.textContent='Edit User';
    document.getElementById('u-id').value=u.id;
    var n = splitName(u.name);
    document.getElementById('u-title-sel').value = n.title;
    document.getElementById('u-first').value = n.first;
    document.getElementById('u-last').value  = n.last;
    document.getElementById('u-email').value=u.email;
    document.getElementById('u-role').value=u.role;
    document.getElementById('u-spec').value=u.specialty||u.position||'';
    document.getElementById('u-contact').value=u.contact||'';
    document.getElementById('u-status').value=u.status;
}
</script>
</body></html>
