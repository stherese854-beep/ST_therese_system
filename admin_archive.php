<?php
// ============================================================
//  ARCHIVE  (admin_archive.php) -- admin only
// ============================================================
//  Accounts land here when they're "deleted" from User Management.
//  Nothing is actually removed at that point — the account (and any
//  patient records attached to it) is just hidden until an admin
//  either Restores it or permanently Deletes it from here.
//
//  Deleted treatment records, X-rays, clinical notes and appointments
//  wait here too (includes/record_archive.php).
// ============================================================
require_once 'config/auth.php';
require_login(['admin']);
require_once 'includes/record_archive.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    // "user"    = an archived login account (from User Management).
    // "patient" = a walk-in patient with no login account of their own,
    //             archived straight from the Patients page.
    $type   = ($_POST['type'] ?? 'user') === 'patient' ? 'patient' : 'user';

    // ---- Archived treatment records, X-rays, notes and appointments ----
    if ($action === 'restore_record' && $id > 0) {
        $q = $pdo->prepare("SELECT item_type, summary FROM archived_records WHERE id = ?");
        $q->execute([$id]); $a = $q->fetch();
        [$ok, $msg] = restore_record($pdo, $id);
        if ($ok && $a) log_activity($pdo, 'Restored ' . strtolower(RECORD_ARCHIVE_TYPES[$a['item_type']][1] ?? 'record'), $a['summary']);
        set_flash($msg, $ok ? 'success' : 'error');
        header("Location: admin_archive#records"); exit;
    }
    if ($action === 'purge_record') {
        $ids = ($id > 0) ? [$id] : array_map('intval', (array)($_POST['ids'] ?? []));
        $done = 0;
        foreach ($ids as $aid) {
            if ($a = purge_archived_record($pdo, $aid)) {
                log_activity($pdo, 'Permanently deleted ' . strtolower(RECORD_ARCHIVE_TYPES[$a['item_type']][1] ?? 'record'), $a['summary']);
                $done++;
            }
        }
        set_flash($done . ' item' . ($done === 1 ? '' : 's') . ' permanently deleted.', 'info');
        header("Location: admin_archive#records"); exit;
    }

    // ---- Archived dental chart visits (from the Odontogram) ----
    if (in_array($action, ['restore_visit', 'permadelete_visit'], true) && $id > 0) {
        $v = $pdo->prepare("SELECT cs.*, p.name AS patient_name FROM chart_sessions cs
                              LEFT JOIN patients p ON p.id = cs.patient_id
                             WHERE cs.id = ? AND cs.archived_at IS NOT NULL");
        $v->execute([$id]);
        $visit = $v->fetch();
        if (!$visit) {
            set_flash('That visit is no longer in the Archive.', 'error');
        } elseif ($action === 'restore_visit') {
            $pdo->prepare("UPDATE chart_sessions SET archived_at = NULL, archived_by = NULL WHERE id = ?")->execute([$id]);
            log_activity($pdo, 'Restored visit chart', ($visit['patient_name'] ?: '#' . $visit['patient_id']) . ' — ' . $visit['visit_date']);
            set_flash('Visit restored to ' . ($visit['patient_name'] ?: 'the patient') . '’s chart.');
        } else {
            $pdo->prepare("DELETE FROM odontogram WHERE patient_id = ? AND session_id = ?")->execute([$visit['patient_id'], $id]);
            $pdo->prepare("DELETE FROM chart_sessions WHERE id = ?")->execute([$id]);
            log_activity($pdo, 'Permanently deleted visit chart', ($visit['patient_name'] ?: '#' . $visit['patient_id']) . ' — ' . $visit['visit_date']);
            set_flash('Visit chart permanently deleted.', 'info');
        }
        header("Location: admin_archive"); exit;
    }

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

// Dental chart visits a dentist moved to the Archive (newest first).
$archivedVisits = [];
try {
    $archivedVisits = $pdo->query(
        "SELECT cs.*, p.name AS patient_name,
                (SELECT COUNT(*) FROM odontogram o WHERE o.session_id = cs.id) AS teeth
           FROM chart_sessions cs LEFT JOIN patients p ON p.id = cs.patient_id
          WHERE cs.archived_at IS NOT NULL ORDER BY cs.archived_at DESC"
    )->fetchAll();
} catch (Throwable $e) {}

// Treatment records, X-rays, notes and appointments (newest first), optionally one kind only.
$recType = $_GET['rec'] ?? '';
if (!isset(RECORD_ARCHIVE_TYPES[$recType])) $recType = '';
$archivedRecords = []; $recCounts = [];
try {
    foreach ($pdo->query("SELECT item_type, COUNT(*) FROM archived_records GROUP BY item_type")->fetchAll(PDO::FETCH_NUM) as $c) $recCounts[$c[0]] = (int)$c[1];
    $rq = $pdo->prepare(
        "SELECT ar.*, p.name AS patient_name, p.status AS patient_status
           FROM archived_records ar LEFT JOIN patients p ON p.id = ar.patient_id
          WHERE (? = '' OR ar.item_type = ?) ORDER BY ar.archived_at DESC, ar.id DESC");
    $rq->execute([$recType, $recType]);
    $archivedRecords = $rq->fetchAll();
} catch (Throwable $e) {}

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
                <div class="sub">Accounts, patients, records and appointments moved here before being deleted for good. Restore them, or delete them permanently.</div>
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
                            <td><?= e($u['archived_by'] ?: '–') ?></td>
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

        <!-- ===== Archived treatment records, X-rays, notes and appointments ===== -->
        <div class="card-box mt-3" id="records">
            <h5 class="mb-1">📋 Archived records &amp; appointments</h5>
            <div class="text-muted2 mb-2" style="font-size:.8rem;">Treatment records, X-rays, clinical notes and appointments someone deleted. Restore puts them back exactly where they were.</div>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <a href="admin_archive#records" class="btn btn-sm <?= $recType === '' ? 'btn-dark-navy' : 'btn-light' ?>" data-keep-text>All <span class="badge bg-secondary"><?= array_sum($recCounts) ?></span></a>
                <?php foreach (RECORD_ARCHIVE_TYPES as $k => $info): ?>
                    <a href="admin_archive?rec=<?= $k ?>#records" class="btn btn-sm <?= $recType === $k ? 'btn-dark-navy' : 'btn-light' ?>" data-keep-text><?= e($info[1]) ?>s <span class="badge bg-secondary"><?= $recCounts[$k] ?? 0 ?></span></a>
                <?php endforeach; ?>
            </div>
            <?php if (!$archivedRecords): ?>
                <div class="text-center text-muted2 py-3">Nothing archived here.</div>
            <?php else: ?>
            <div class="table-responsive">
                <?= bulk_bar('bulk-records', 'purge_record', 'items permanently', [], '🗑 Delete selected permanently', 'They are removed for good (X-ray images too). This cannot be undone.') ?>
                <table class="data">
                    <thead><tr><th>Type</th><th>Patient</th><th>Details</th><th>Archived By</th><th>Archived On</th><th class="no-print">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($archivedRecords as $r):
                        $row = json_decode($r['row_data'], true) ?: [];
                        $who = $r['patient_name'] ?: ($row['patient_name'] ?? '');
                        $typeBadge = ['treatment' => 'b-progress', 'xray' => 'b-confirmed', 'note' => 'b-pending', 'appointment' => 'b-completed'][$r['item_type']] ?? 'b-pending';
                    ?>
                        <tr>
                            <td><span class="badge-pill <?= $typeBadge ?>"><?= e(RECORD_ARCHIVE_TYPES[$r['item_type']][1] ?? $r['item_type']) ?></span></td>
                            <td><strong><?= e($who ?: '–') ?></strong>
                                <?php if ($r['patient_status'] === 'Archived'): ?><br><small class="text-muted2">Patient is archived too</small><?php elseif ($r['patient_id'] && $r['patient_name'] === null): ?><br><small class="text-muted2">Patient deleted</small><?php endif; ?></td>
                            <td><?= e($r['summary'] ?: '–') ?>
                                <?php if ($r['item_type'] === 'xray' && !empty($row['image_file'])): ?><br><small class="text-muted2">Image kept until deleted for good</small><?php endif; ?>
                                <?php if (!empty($row['dentist'])): ?><br><small class="text-muted2"><?= e($row['dentist']) ?></small><?php endif; ?></td>
                            <td><?= e($r['archived_by'] ?: '–') ?></td>
                            <td><small class="text-muted2"><?= date('M j, Y g:i A', strtotime($r['archived_at'])) ?></small></td>
                            <td class="no-print">
                                <div class="d-flex gap-1 align-items-center">
                                    <?= bulk_pick('bulk-records', $r['id'], 'Select') ?>
                                    <form method="POST" onsubmit="return confirmDelete('Restore this <?= e(strtolower(RECORD_ARCHIVE_TYPES[$r['item_type']][1] ?? 'item')) ?>?')">
                                        <input type="hidden" name="action" value="restore_record">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="icon-btn icon-btn-restore" title="Restore">↩</button>
                                    </form>
                                    <form method="POST" onsubmit="return confirmDelete('Permanently delete this <?= e(strtolower(RECORD_ARCHIVE_TYPES[$r['item_type']][1] ?? 'item')) ?>? This cannot be undone.')">
                                        <input type="hidden" name="action" value="purge_record">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
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

        <!-- ===== Archived dental chart visits (from the Odontogram) ===== -->
        <div class="card-box mt-3">
            <h5 class="mb-1">🦷 Archived dental chart visits</h5>
            <div class="text-muted2 mb-3" style="font-size:.8rem;">Visits a dentist removed from a patient's chart. Restore puts the visit back in the chart history.</div>
            <?php if (!$archivedVisits): ?>
                <div class="text-center text-muted2 py-3">No archived visits.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="data">
                    <thead><tr><th>Patient</th><th>Visit</th><th>Archived By</th><th>Archived On</th><th class="no-print">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($archivedVisits as $v): ?>
                        <tr>
                            <td><strong><?= e($v['patient_name'] ?: '–') ?></strong></td>
                            <td><?= date('M j, Y', strtotime($v['visit_date'])) ?><?= $v['title'] ? ' · ' . e($v['title']) : '' ?>
                                <br><small class="text-muted2"><?= (int)$v['teeth'] ?> tooth record<?= (int)$v['teeth'] === 1 ? '' : 's' ?></small></td>
                            <td><?= e($v['archived_by'] ?: '–') ?></td>
                            <td><small class="text-muted2"><?= date('M j, Y g:i A', strtotime($v['archived_at'])) ?></small></td>
                            <td class="no-print">
                                <div class="d-flex gap-1">
                                    <form method="POST" onsubmit="return confirmDelete('Restore this visit to the patient\'s chart?')">
                                        <input type="hidden" name="action" value="restore_visit">
                                        <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                                        <button type="submit" class="icon-btn icon-btn-restore" title="Restore">↩</button>
                                    </form>
                                    <form method="POST" onsubmit="return confirmDelete('Permanently delete this visit chart? Every tooth recorded on it is removed. This cannot be undone.')">
                                        <input type="hidden" name="action" value="permadelete_visit">
                                        <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
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
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
</body></html>
