<?php
// ============================================================
//  DATA BACKUP  (admin_backup.php) -- admin only
// ============================================================
//  MANUAL: the admin clicks "Download backup", types their password
//  again, and gets one .sql file with every table of the database
//  (structure + rows), or a .zip that also holds the uploaded files
//  (X-rays, photos). It goes straight to their computer and is never
//  kept on the server.
//
//  AUTOMATIC: Every week / Every month / Off (the default). A due backup
//  is made by the first page a signed-in user opens (no scheduler is
//  needed — see run_auto_backup in includes/backup.php) and SAVED on
//  the server; the newest few are kept. The admin downloads a saved one
//  here, with their password.
//
//  Every backup is listed under "Past backups" and in the Activity Log.
//
//  To restore: import the .sql file in phpMyAdmin (Import tab) or
//  with  mysql -u root dental_clinic < backup.sql
// ============================================================
require_once 'config/auth.php';
require_login(['admin']);

require_once 'includes/backup.php';   // the backup helpers + the automatic schedule

ensure_backup_log($pdo);
$error = '';
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : '';

/** The backup holds every patient's record, so the password is asked again. '' when it is right. */
function backup_check_password($pdo, $what) {
    $me = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $me->execute([$_SESSION['user_id']]);
    $hash = $me->fetchColumn();
    if (rate_limited($pdo, 'backup_pw', 5, 900)) return 'Too many wrong passwords. Please wait 15 minutes and try again.';
    if (!$hash || !password_verify($_POST['password'] ?? '', $hash)) {
        rate_hit($pdo, 'backup_pw');
        log_activity($pdo, 'Access denied', 'Data backup: wrong password');
        return "Wrong password. The $what was not downloaded.";
    }
    return '';
}

/** Sends a file on disk to the browser as a download. */
function backup_send_file($path, $fileName, $kind) {
    while (ob_get_level()) ob_end_clean();          // no page output (or CSRF buffer) in the file
    header('Content-Type: ' . ($kind === 'zip' ? 'application/zip' : 'application/sql; charset=utf-8'));
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
}

// ---------- Download a backup made now ----------
if ($action === 'backup') {
    $kind  = ($_POST['kind'] ?? 'sql') === 'zip' ? 'zip' : 'sql';
    $note  = mb_substr(trim($_POST['note'] ?? ''), 0, 200);
    $error = backup_check_password($pdo, 'backup');
    if ($error === '') {
        @set_time_limit(300);
        $by   = $_SESSION['name'] ?? 'Admin';
        $base = 'st-therese-backup_' . date('Y-m-d_His');
        $fileName = $base . '.' . $kind;
        $tmp = tempnam(sys_get_temp_dir(), 'bkd');
        [$nTables, $nRows, $size] = backup_build_file($pdo, $kind, $tmp, $base, $by, $note);
        backup_send_file($tmp, $fileName, $kind);
        @unlink($tmp);
        $pdo->prepare("INSERT INTO backup_log (file_name, kind, size_bytes, tables, row_count, note, created_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$fileName, $kind, $size, $nTables, $nRows, $note !== '' ? $note : null, $by]);
        log_activity($pdo, 'Exported data backup', "$fileName · $nTables tables, " . number_format($nRows) . ' rows · ' . backup_size_text($size));
        exit;
    }
}

// ---------- Download a saved automatic backup ----------
if ($action === 'download_saved') {
    $row = $pdo->prepare("SELECT * FROM backup_log WHERE id = ? AND stored_file IS NOT NULL");
    $row->execute([(int)($_POST['backup_id'] ?? 0)]);
    $row  = $row->fetch();
    $path = $row ? backup_stored_path($row['stored_file']) : '';
    if (!$row || $path === '') {
        $error = 'That saved backup is no longer on the server. Please choose another one, or download a new backup.';
    } else {
        $error = backup_check_password($pdo, 'saved backup');
        if ($error === '') {
            backup_send_file($path, $row['file_name'], $row['kind']);
            log_activity($pdo, 'Exported data backup', 'Downloaded the saved automatic backup ' . $row['file_name']
                         . ' (made ' . date('M j, Y g:i A', strtotime($row['created_at'])) . ')');
            exit;
        }
    }
}

// ---------- The automatic schedule ----------
if ($action === 'schedule') {
    $old  = backup_auto_settings($pdo);
    $freq = isset(BACKUP_FREQS[$_POST['freq'] ?? '']) ? $_POST['freq'] : 'off';
    $kind = ($_POST['auto_kind'] ?? 'sql') === 'zip' ? 'zip' : 'sql';
    $keep = max(1, min(BACKUP_KEEP_MAX, (int)($_POST['keep'] ?? BACKUP_KEEP_DEFAULT)));
    save_setting($pdo, 'auto_backup_freq', $freq);
    save_setting($pdo, 'auto_backup_kind', $kind);
    save_setting($pdo, 'auto_backup_keep', (string)$keep);
    if ($old['freq'] !== $freq || $old['kind'] !== $kind || $old['keep'] !== $keep) {
        log_activity($pdo, 'Changed backup schedule', 'Automatic backup: ' . BACKUP_FREQS[$freq]
                     . ($freq !== 'off' ? ' · ' . ($kind === 'zip' ? 'database + uploaded files (.zip)' : 'database only (.sql)')
                                          . " · keep the newest $keep" : ''));
    }
    if ($keep < $old['keep']) backup_prune($pdo, $keep);
    $msg = $freq === 'off' ? 'Automatic backups are off. Backups are made only when you click Download backup.'
                           : 'Saved. A backup will be made ' . strtolower(BACKUP_FREQS[$freq]) . ' and kept on the server.';
    set_flash($msg);
    header('Location: admin_backup');
    exit;
}

// ---------- Save one on the server now (same as the schedule does) ----------
if ($action === 'save_now') {
    $id = run_auto_backup($pdo, true);
    set_flash($id ? 'A backup was saved on the server. You can download it below.' : 'The backup could not be saved — see the message below.',
              $id ? 'success' : 'danger');
    header('Location: admin_backup');
    exit;
}

// ---------- The page ----------
run_auto_backup($pdo);   // a due backup is made before the page reads the list (head.php would do it later)
$tables  = backup_tables($pdo);
$total   = array_sum($tables);
$history = $pdo->query("SELECT * FROM backup_log ORDER BY created_at DESC, id DESC LIMIT 20")->fetchAll();
$last    = $history[0] ?? null;
$daysAgo = $last ? (int)floor((time() - strtotime($last['created_at'])) / 86400) : null;
$auto    = backup_auto_settings($pdo);
$nextDue = backup_next_due($auto);
$saved   = array_values(array_filter(
    $pdo->query("SELECT * FROM backup_log WHERE stored_file IS NOT NULL ORDER BY created_at DESC, id DESC")->fetchAll(),
    fn($r) => backup_stored_path($r['stored_file']) !== ''));
$onRailway = getenv('RAILWAY_ENVIRONMENT') !== false || getenv('RAILWAY_PROJECT_ID') !== false;

$page_title = "Data Backup";
include 'includes/head.php';
$active = 'backup';
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div>
                <h1 style="color:var(--teal-light)">Data Backup</h1>
                <div class="sub">Download a copy of all the clinic's data now, or let the system make one every week or month</div>
            </div>
        </div>

        <?php include 'includes/admin_tabs.php'; ?>

        <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
        <?php if ($auto['error'] !== '' && $auto['freq'] !== 'off'): ?>
            <div class="alert alert-danger py-2">⚠ The last automatic backup failed: <?= e($auto['error']) ?></div>
        <?php endif; ?>

        <?php if (!$last): ?>
            <div class="alert py-2" style="background:#fff6e0;border:1px solid var(--gold);color:#8a6d2f;">⚠ No backup has been made yet. Make one now and keep it somewhere safe.</div>
        <?php elseif ($daysAgo >= BACKUP_REMIND_DAYS && $auto['freq'] === 'off'): ?>
            <div class="alert py-2" style="background:#fff6e0;border:1px solid var(--gold);color:#8a6d2f;">⚠ The last backup was <?= $daysAgo ?> days ago. Consider making a new one, or turn on automatic backups.</div>
        <?php endif; ?>

        <div class="row g-3 bk-row">
            <!-- 1. Manual backup -->
            <div class="col-xl-4 col-lg-6">
                <div class="card-box h-100">
                    <h5 class="mb-1">💾 Make a backup now</h5>
                    <div class="text-muted2 mb-2" style="font-size:.82rem;">
                        One file with every table: patients, appointments, records, dental charts, accounts, settings and the logs.
                        It goes straight to your computer.
                    </div>
                    <form method="POST" autocomplete="off" id="bk-manual">
                        <input type="hidden" name="action" value="backup">
                        <label class="field-label">What to include</label>
                        <div class="mb-2">
                            <label class="d-flex gap-2 align-items-start mb-1" style="cursor:pointer;">
                                <input type="radio" name="kind" value="sql" class="form-check-input mt-1" checked>
                                <span><b>Database only</b> <span class="text-muted2">(.sql)</span> <small class="text-muted2">— all the data, opens in phpMyAdmin</small></span>
                            </label>
                            <label class="d-flex gap-2 align-items-start" style="cursor:pointer;">
                                <input type="radio" name="kind" value="zip" class="form-check-input mt-1">
                                <span><b>Database + uploaded files</b> <span class="text-muted2">(.zip)</span> <small class="text-muted2">— adds the X-ray images and photos</small></span>
                            </label>
                        </div>
                        <label class="field-label">Note <span class="text-muted2">(optional)</span></label>
                        <input name="note" class="form-control mb-2" maxlength="200" placeholder="e.g. Before the system update">
                        <label class="field-label">Your password</label>
                        <input type="password" name="password" class="form-control mb-1" required autocomplete="current-password">
                        <div class="text-muted2 mb-2" style="font-size:.76rem;">🔒 The backup holds every patient's record, so your password is asked again.</div>
                        <button class="btn btn-teal" data-keep-text>⬇ Download backup</button>
                    </form>
                    <div class="alert alert-light border mt-3 mb-0 py-2" style="font-size:.78rem;">
                        <b>Keep the file private</b> — a USB drive or a private folder, not email or chat.<br>
                        <b>To restore:</b> phpMyAdmin → <code>dental_clinic</code> → <i>Import</i> → the .sql file (it replaces the current data).
                    </div>
                </div>
            </div>

            <!-- 2. Automatic backups -->
            <div class="col-xl-4 col-lg-6">
                <div class="card-box h-100">
                    <h5 class="mb-1">⏱ Automatic backup</h5>
                    <div class="text-muted2 mb-2" style="font-size:.82rem;">
                        The system makes a backup by itself and saves it on the server. Turn it off to back up only by hand.
                    </div>
                    <form method="POST" id="bk-schedule">
                        <input type="hidden" name="action" value="schedule">
                        <label class="field-label">How often</label>
                        <div class="bk-freq mb-2">
                            <?php foreach (BACKUP_FREQS as $k => $label): ?>
                                <label class="bk-freq-opt"><input type="radio" name="freq" value="<?= $k ?>" <?= $auto['freq'] === $k ? 'checked' : '' ?>>
                                    <span><?= e($k === 'off' ? 'Off (manual)' : $label) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="bk-auto-opts" <?= $auto['freq'] === 'off' ? 'hidden' : '' ?>>
                            <div class="row g-2 mb-2">
                                <div class="col-7">
                                    <label class="field-label">Include</label>
                                    <select name="auto_kind" class="form-select form-select-sm">
                                        <option value="sql" <?= $auto['kind'] === 'sql' ? 'selected' : '' ?>>Database only (.sql)</option>
                                        <option value="zip" <?= $auto['kind'] === 'zip' ? 'selected' : '' ?>>Database + uploaded files (.zip)</option>
                                    </select>
                                </div>
                                <div class="col-5">
                                    <label class="field-label">Keep the newest</label>
                                    <select name="keep" class="form-select form-select-sm">
                                        <?php foreach ([2, 3, 4, 6, 8, 12] as $n): ?>
                                            <option value="<?= $n ?>" <?= $auto['keep'] === $n ? 'selected' : '' ?>><?= $n ?> backups</option>
                                        <?php endforeach; ?>
                                        <?php if (!in_array($auto['keep'], [2, 3, 4, 6, 8, 12], true)): ?>
                                            <option value="<?= $auto['keep'] ?>" selected><?= $auto['keep'] ?> backups</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <button class="btn btn-teal btn-sm">Save schedule</button>
                    </form>

                    <div class="bk-status mt-2">
                        <?php if ($auto['freq'] === 'off'): ?>
                            ⏸ Off — nothing is made by itself.
                        <?php else: ?>
                            ✅ On · <?= e(BACKUP_FREQS[$auto['freq']]) ?><br>
                            Last automatic: <b><?= $auto['last'] ? e(date('M j, Y g:i A', strtotime($auto['last']))) : 'none yet' ?></b><br>
                            Next: <b><?= $nextDue <= time() ? 'on the next page anyone opens' : e(date('M j, Y', $nextDue)) ?></b>
                        <?php endif; ?>
                    </div>

                    <hr class="my-2">
                    <label class="field-label">Saved on the server <span class="text-muted2">(<?= count($saved) ?>)</span></label>
                    <?php if (!$saved): ?>
                        <div class="text-muted2" style="font-size:.8rem;">None yet.</div>
                    <?php else: ?>
                        <form method="POST" autocomplete="off" id="bk-saved">
                            <input type="hidden" name="action" value="download_saved">
                            <select name="backup_id" class="form-select form-select-sm mb-1">
                                <?php foreach ($saved as $sv): ?>
                                    <option value="<?= (int)$sv['id'] ?>"><?= e(date('M j, Y g:i A', strtotime($sv['created_at']))) ?> · <?= e(backup_size_text((int)$sv['size_bytes'])) ?><?= $sv['kind'] === 'zip' ? ' · + files' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="d-flex gap-2">
                                <input type="password" name="password" class="form-control form-control-sm" placeholder="Your password" required autocomplete="current-password">
                                <button class="btn btn-outline-teal btn-sm text-nowrap" data-keep-text>⬇ Download</button>
                            </div>
                        </form>
                    <?php endif; ?>
                    <form method="POST" class="mt-1">
                        <input type="hidden" name="action" value="save_now">
                        <button class="btn btn-link btn-sm p-0" style="font-size:.78rem;">Save one on the server now</button>
                    </form>
                    <?php if ($onRailway): ?>
                        <div class="text-muted2 mt-1" style="font-size:.74rem;">ℹ Saved backups are removed when the site is updated (redeployed), so download the ones you want to keep.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 3. What is in the database -->
            <div class="col-xl-4 col-lg-12">
                <div class="card-box h-100 d-flex flex-column">
                    <h5 class="mb-1">📦 What is in the database now</h5>
                    <div class="text-muted2 mb-2" style="font-size:.85rem;"><?= count($tables) ?> tables · <?= number_format($total) ?> rows</div>
                    <div class="table-responsive bk-tables">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Table</th><th class="text-end">Rows</th></tr></thead>
                            <tbody>
                            <?php foreach ($tables as $t => $n): ?>
                                <tr><td><code><?= e($t) ?></code><?= in_array($t, BACKUP_SKIP_ROWS, true) ? ' <small class="text-muted2">(rows not backed up)</small>' : '' ?></td>
                                    <td class="text-end"><?= number_format($n) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-box mt-3">
            <h5 class="mb-1">🕘 Past backups</h5>
            <div class="text-muted2 mb-2" style="font-size:.85rem;">Every backup, manual or automatic. Manual ones are only on the computer they were downloaded to.</div>
            <?php if (!$history): ?>
                <div class="text-muted2">No backups yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>When</th><th>Type</th><th>File</th><th>Size</th><th>Contents</th><th>By</th><th>Note</th></tr></thead>
                        <tbody>
                        <?php foreach ($history as $h): ?>
                            <tr>
                                <td style="white-space:nowrap;"><?= e(date('M j, Y g:i A', strtotime($h['created_at']))) ?></td>
                                <td style="white-space:nowrap;"><?php if (!empty($h['is_auto'])): ?>
                                        <span class="badge-pill b-confirmed">Automatic</span><?= backup_stored_path($h['stored_file'] ?? '') !== '' ? ' <small class="text-muted2">saved</small>' : '' ?>
                                    <?php else: ?><span class="badge-pill b-pending">Manual</span><?php endif; ?></td>
                                <td><code><?= e($h['file_name']) ?></code></td>
                                <td style="white-space:nowrap;"><?= e(backup_size_text($h['size_bytes'] !== null ? (int)$h['size_bytes'] : null)) ?></td>
                                <td style="white-space:nowrap;"><?= (int)$h['tables'] ?> tables, <?= number_format((int)$h['row_count']) ?> rows<?= $h['kind'] === 'zip' ? ' + files' : '' ?></td>
                                <td><?= e($h['created_by']) ?></td>
                                <td><?= e($h['note'] ?? '') ?></td>
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
.bk-freq { display: flex; gap: 6px; flex-wrap: wrap; }
.bk-freq-opt { flex: 1 1 0; min-width: 96px; cursor: pointer; margin: 0; }
.bk-freq-opt input { position: absolute; opacity: 0; pointer-events: none; }
.bk-freq-opt span { display: block; text-align: center; border: 1px solid #d5e1e4; border-radius: 9px; padding: 7px 6px; font-size: .86rem; font-weight: 600; background: #fff; }
.bk-freq-opt input:checked + span { border-color: var(--teal); background: #eefaf8; color: var(--teal); box-shadow: inset 0 0 0 1px var(--teal); }
.bk-freq-opt input:focus-visible + span { outline: 2px solid var(--teal); outline-offset: 2px; }
.bk-status { font-size: .8rem; background: #f4f8f8; border-radius: 8px; padding: 7px 10px; line-height: 1.55; }
.bk-tables { flex: 1 1 auto; max-height: 360px; overflow-y: auto; }
@media (min-width: 1200px) { .bk-tables { max-height: none; height: 0; min-height: 240px; } }  /* as tall as the cards beside it */
</style>
<script>
// Manual download: the file downloads and this page stays — clear the password and refresh "Past backups" a moment later.
['bk-manual', 'bk-saved'].forEach(function (id) {
    var f = document.getElementById(id);
    if (f) f.addEventListener('submit', function () {
        var pw = f.querySelector('input[name="password"]');
        setTimeout(function () { pw.value = ''; location.replace('admin_backup'); }, 4000);
    });
});
// The "Include / Keep" choices only matter when the schedule is on.
document.querySelectorAll('#bk-schedule input[name="freq"]').forEach(function (r) {
    r.addEventListener('change', function () {
        document.querySelector('.bk-auto-opts').hidden = document.querySelector('#bk-schedule input[name="freq"]:checked').value === 'off';
    });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
</body></html>
