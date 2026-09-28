<?php
// ============================================================
//  ARCHIVE  (admin_archive.php) -- admin only
// ============================================================
//  Accounts land here when they're "deleted" from User Management.
//  Nothing is actually removed at that point — the account (and any
//  patient records attached to it) is just hidden until an admin
//  either Restores it or permanently Deletes it from here.
// ============================================================
require_once 'config/auth.php';
require_login(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    // "user"    = an archived login account (from User Management).
    // "patient" = a walk-in patient with no login account of their own,
    //             archived straight from the Patients page.
    $type   = ($_POST['type'] ?? 'user') === 'patient' ? 'patient' : 'user';

    if ($action === 'restore' && $id > 0) {
        if ($type === 'patient') {
            $info = $pdo->prepare("SELECT name FROM patients WHERE id = ? AND status = 'Archived'");
            $info->execute([$id]);
            $row = $info->fetch();
            if ($row) {
                restore_patient($pdo, $id);
                set_flash(($row['name'] ?: 'Patient') . ' restored.');
            } else {
                set_flash('That patient is no longer in the Archive.', 'error');
            }
        } else {
            $info = $pdo->prepare("SELECT name FROM users WHERE id = ? AND status = 'archived'");
            $info->execute([$id]);
            $u = $info->fetch();
            if ($u) {
                restore_user($pdo, $id);
                set_flash(($u['name'] ?: 'User') . ' restored.');
            } else {
                set_flash('That account is no longer in the Archive.', 'error');
            }
        }
        header("Location: admin_archive"); exit;
    }

    // Several ticked rows: run the same permanent delete for each one.
    if ($action === 'permadelete' && !empty($_POST['ids']) && is_array($_POST['ids'])) {
        $done = 0;
        foreach ($_POST['ids'] as $pick) {
            if (!preg_match('/^(user|patient):(\d+)$/', (string)$pick, $m)) continue;
            $bid = (int)$m[2];
            if ($m[1] === 'patient') {
                $q = $pdo->prepare("SELECT name FROM patients WHERE id = ? AND status = 'Archived'");
                $q->execute([$bid]); $nm = $q->fetchColumn();
                if ($nm === false) continue;
                log_activity($pdo, 'Permanently deleted patient', $nm ?: "#$bid");
                delete_patient_records($pdo, $bid);
            } else {
                $q = $pdo->prepare("SELECT name FROM users WHERE id = ? AND status = 'archived'");
                $q->execute([$bid]);
                if ($q->fetchColumn() === false) continue;
                delete_user_completely($pdo, $bid, $_SESSION['name'] ?? null);
            }
            $done++;
        }
        set_flash($done . ' item' . ($done === 1 ? '' : 's') . ' permanently deleted.', 'info');
        header("Location: admin_archive"); exit;
    }

    if ($action === 'permadelete' && $id > 0) {
        if ($type === 'patient') {
            $info = $pdo->prepare("SELECT name FROM patients WHERE id = ? AND status = 'Archived'");
            $info->execute([$id]);
            $row = $info->fetch();
            if ($row) {
                // Permanent: the patient record, dental chart, appointments,
                // treatments and X-rays are all removed for good.
                log_activity($pdo, 'Permanently deleted patient', $row['name'] ?: "#$id");
                delete_patient_records($pdo, $id);
                set_flash(($row['name'] ?: 'Patient') . ' permanently deleted.', 'info');
            } else {
                set_flash('That patient is no longer in the Archive.', 'error');
            }
        } else {
            $info = $pdo->prepare("SELECT name, role FROM users WHERE id = ? AND status = 'archived'");
            $info->execute([$id]);
            $u = $info->fetch();

            if ($u) {
                // This is permanent: the account, and (if it's a patient) their
                // patient record, dental chart and appointments, are removed for
                // good. A trace of the email is kept in deleted_accounts_log so
                // the login page can still recognize it later.
                delete_user_completely($pdo, $id, $_SESSION['name'] ?? null);

                $extra = ($u['role'] === 'patient')
                       ? ' Their patient record, dental chart and appointments were removed too.'
                       : '';
                set_flash(($u['name'] ?: 'User') . ' permanently deleted.' . $extra, 'info');
            } else {
                set_flash('That account is no longer in the Archive.', 'error');
            }
        }
        header("Location: admin_archive"); exit;
    }
}

// Archived login accounts (admin/dentist/staff/patient) from User Management...
$archivedUsers = $pdo->query("SELECT * FROM users WHERE status = 'archived' ORDER BY archived_at DESC")->fetchAll();

// ...plus walk-in patients archived straight from the Patients page, who have
// no login account of their own and so don't show up in `users` at all.
$archivedPatientsOnly = $pdo->query(
    "SELECT * FROM patients WHERE status = 'Archived' AND (user_id IS NULL OR user_id = 0) ORDER BY archived_at DESC"
)->fetchAll();

// Merge both into one list the table can loop over, newest first.
$archived = [];
foreach ($archivedUsers as $u) {
    $archived[] = [
        'type' => 'user', 'id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'],
        'role' => $u['role'], 'archived_by' => $u['archived_by'], 'archived_at' => $u['archived_at'],
    ];
}
foreach ($archivedPatientsOnly as $p) {
    $archived[] = [
        'type' => 'patient', 'id' => $p['id'], 'name' => $p['name'], 'email' => $p['email'],
        'role' => 'patient', 'archived_by' => $p['archived_by'], 'archived_at' => $p['archived_at'],
    ];
}
usort($archived, fn($a, $b) => strcmp($b['archived_at'] ?? '', $a['archived_at'] ?? ''));

$page_title = "Archive";
include 'includes/head.php';
$active = 'archive';
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div>
                <h1 style="color:var(--teal-light)">Archive</h1>
                <div class="sub">Accounts and patients moved here before being deleted for good. Restore them, or delete them permanently.</div>
            </div>
        </div>

        <!-- Top tabs shared across admin pages -->
        <?php include 'includes/admin_tabs.php'; ?>

        <div class="card-box">
            <div class="flex-between mb-3">
                <h5 class="mb-0">Archived <small class="text-muted2 d-block" style="font-size:.75rem;">Showing <?= count($archived) ?> archived item<?= count($archived) === 1 ? '' : 's' ?></small></h5>
                <div class="d-flex gap-2">
                    <a href="patients" class="btn btn-sm btn-outline-secondary">← Back to Patients</a>
                    <a href="admin_users" class="btn btn-sm btn-outline-secondary">← Back to User Management</a>
                </div>
            </div>

            <?php if (empty($archived)): ?>
                <div class="text-center text-muted2 py-5">
                    <div style="font-size:2.2rem;">🗄</div>
                    The Archive is empty.
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <?= bulk_bar('bulk-archive', 'permadelete', 'items permanently', [], '🗑 Delete selected permanently', 'Their records, dental charts and appointments are removed for good. This cannot be undone.') ?>
                <table class="data">
                    <thead><tr><th>User</th><th>Role</th><th>Archived By</th><th>Archived On</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($archived as $u):
                        $initials = strtoupper(substr($u['name'],0,1) . (strpos($u['name'],' ') ? substr(strstr($u['name'],' '),1,1) : ''));
                        $roleBadge = ['admin'=>'b-progress','staff'=>'b-confirmed','dentist'=>'b-progress','patient'=>'b-pending'][$u['role']] ?? 'b-pending';
                    ?>
                        <tr>
                            <td><div class="d-flex align-items-center gap-2">
                                <span class="avatar" style="background:#9a9a9a;"><?= e($initials) ?></span>
                                <div><strong><?= e($u['name']) ?></strong><br><small class="text-muted2"><?= e($u['email'] ?: 'No login account') ?></small></div>
                            </div></td>
                            <td><span class="badge-pill <?= $roleBadge ?>"><?= ucfirst($u['role']) ?></span></td>
                            <td><?= e($u['archived_by'] ?: '-') ?></td>
                            <td><small class="text-muted2"><?= $u['archived_at'] ? date('M j, Y g:i A', strtotime($u['archived_at'])) : '-' ?></small></td>
                            <td>
                                <div class="d-flex gap-1 align-items-center">
                                    <?= bulk_pick('bulk-archive', $u['type'] . ':' . $u['id'], 'Select ' . $u['name']) ?>
                                    <form method="POST" onsubmit="return confirmDelete('Restore ' + <?= json_encode($u['name']) ?> + '’s <?= $u['type'] === 'patient' ? 'record' : 'account' ?>?')">
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="type" value="<?= e($u['type']) ?>">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="icon-btn icon-btn-restore" title="Restore">↩</button>
                                    </form>
                                    <form method="POST" onsubmit="return confirmDelete('Permanently delete ' + <?= json_encode($u['name']) ?> + '’s <?= $u['type'] === 'patient' ? 'record' : 'account' ?>? This cannot be undone.')">
                                        <input type="hidden" name="action" value="permadelete">
                                        <input type="hidden" name="type" value="<?= e($u['type']) ?>">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="icon-btn icon-btn-delete" title="Delete Permanently">🗑</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<style>
.icon-btn {
    width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none;
    border-radius: 8px;
    font-size: 1rem;
    cursor: pointer;
    transition: filter .15s;
}
.icon-btn:hover { filter: brightness(.95); }
.icon-btn-restore { background: #d7f5e3; color: #138a4e; }
.icon-btn-delete  { background: #fbdcdc; color: #c0392b; }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js"></script>
</body></html>
