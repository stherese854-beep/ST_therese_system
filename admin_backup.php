<?php
// ============================================================
//  DATA BACKUP  (admin_backup.php) -- admin only
// ============================================================
//  A MANUAL backup: nothing runs on a schedule. The admin clicks
//  "Download backup", types their password again, and gets one
//  .sql file with every table of the database (structure + rows).
//  The uploaded files (X-rays, photos) can be added too, in one
//  .zip (see backup_make_zip: no PHP zip module needed).
//
//  The file is made on the spot and sent straight to the admin's
//  computer — it is never kept on the server. Each download is
//  listed under "Past backups" and in the Activity Log, so the
//  clinic can see when the last one was taken.
//
//  To restore: import the .sql file in phpMyAdmin (Import tab) or
//  with  mysql -u root dental_clinic < backup.sql
// ============================================================
require_once 'config/auth.php';
require_login(['admin']);

// Tables whose ROWS are left out (the table itself is still created):
// sign-in sessions and the anti-spam counters are not clinic data.
const BACKUP_SKIP_ROWS = ['php_sessions', 'rate_limits'];
const BACKUP_ROWS_PER_INSERT = 100;
const BACKUP_REMIND_DAYS = 7;   // "It has been N days since the last backup" after this

function ensure_backup_log($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS backup_log (
                    id         INT AUTO_INCREMENT PRIMARY KEY,
                    file_name  VARCHAR(120) NOT NULL,
                    kind       VARCHAR(20)  NOT NULL DEFAULT 'sql',   -- sql / zip
                    size_bytes BIGINT       DEFAULT NULL,
                    tables     INT          DEFAULT NULL,
                    row_count  INT          DEFAULT NULL,
                    note       VARCHAR(255) DEFAULT NULL,
                    created_by VARCHAR(100) DEFAULT NULL,
                    created_at DATETIME     DEFAULT CURRENT_TIMESTAMP
                ) DEFAULT CHARSET=utf8mb4");
}

/** The database's tables (not views): [name => row count]. */
function backup_tables($pdo) {
    $out = [];
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $r) {
        $out[$r[0]] = (int)$pdo->query("SELECT COUNT(*) FROM `" . str_replace('`', '``', $r[0]) . "`")->fetchColumn();
    }
    return $out;
}

/**
 * Writes the whole database as SQL to $fh (a file or php://output).
 * Returns [tables, rows].
 */
function backup_write_sql($pdo, $fh, $by, $note) {
    $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $tables = backup_tables($pdo);
    $rows   = 0;
    fwrite($fh, "-- ============================================================\n"
              . "--  St. Therese Dental Clinic - DATA BACKUP\n"
              . "--  Database : $dbName\n"
              . "--  Made     : " . date('Y-m-d H:i:s') . " (Asia/Manila)\n"
              . "--  By       : " . str_replace("\n", ' ', $by) . "\n"
              . ($note !== '' ? "--  Note     : " . str_replace("\n", ' ', $note) . "\n" : '')
              . "--  Tables   : " . count($tables) . "\n"
              . "--\n"
              . "--  Restore: phpMyAdmin > the database > Import > this file,\n"
              . "--  or:  mysql -u USER -p DATABASE < this-file.sql\n"
              . "--  Restoring REPLACES the tables below with this copy.\n"
              . "-- ============================================================\n\n"
              . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+08:00';\n\n");

    foreach ($tables as $table => $count) {
        $q = '`' . str_replace('`', '``', $table) . '`';
        $create = $pdo->query("SHOW CREATE TABLE $q")->fetch(PDO::FETCH_NUM)[1];
        fwrite($fh, "-- ------------------------------------------------------------\n"
                  . "-- Table $table" . (in_array($table, BACKUP_SKIP_ROWS, true) ? " (rows not included)" : " ($count rows)") . "\n"
                  . "-- ------------------------------------------------------------\n"
                  . "DROP TABLE IF EXISTS $q;\n$create;\n\n");
        if (in_array($table, BACKUP_SKIP_ROWS, true) || $count === 0) continue;

        // Column names once, then the rows in batches (a big table never sits in memory at once).
        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM $q") as $c) $cols[] = '`' . str_replace('`', '``', $c['Field']) . '`';
        $head = "INSERT INTO $q (" . implode(', ', $cols) . ") VALUES\n";
        for ($offset = 0; ; $offset += 1000) {
            $batch = $pdo->query("SELECT * FROM $q LIMIT 1000 OFFSET $offset")->fetchAll(PDO::FETCH_NUM);
            if (!$batch) break;
            foreach (array_chunk($batch, BACKUP_ROWS_PER_INSERT) as $chunk) {
                $vals = [];
                foreach ($chunk as $row) {
                    $vals[] = '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row)) . ')';
                }
                fwrite($fh, $head . implode(",\n", $vals) . ";\n");
                $rows += count($chunk);
            }
            if (count($batch) < 1000) break;
        }
        fwrite($fh, "\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n-- End of backup\n");
    return [count($tables), $rows];
}

/**
 * Makes a .zip at $zipPath from $files [name inside the zip => file on disk].
 * Uses PHP's zip module when the server has it; otherwise writes the zip
 * itself (plain ZIP format, compressed with zlib when available), so the
 * .zip backup works on every server — XAMPP and Railway included.
 */
function backup_make_zip($zipPath, array $files) {
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::OVERWRITE | ZipArchive::CREATE) === true) {
            foreach ($files as $name => $path) $zip->addFile($path, $name);
            if ($zip->close()) return;
        }
    }
    $out = fopen($zipPath, 'wb');
    $central = '';
    $offset = 0;
    foreach ($files as $name => $path) {
        $data = (string)file_get_contents($path);
        $crc  = crc32($data);
        $size = strlen($data);
        $packed = function_exists('gzdeflate') ? gzdeflate($data, 6) : false;
        $method = ($packed !== false && strlen($packed) < $size) ? 8 : 0;   // 8 = deflate, 0 = stored as is
        if ($method === 0) $packed = $data;
        $t = getdate(filemtime($path) ?: time());                            // MS-DOS date and time
        $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
        $dosDate = ((max($t['year'], 1980) - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
        $common = pack('vvvvvVVV', 20, 0x0800, $method, $dosTime, $dosDate, $crc, strlen($packed), $size)   // 0x0800: UTF-8 names
                . pack('v', strlen($name));
        fwrite($out, "PK\x03\x04" . $common . pack('v', 0) . $name);
        fwrite($out, $packed);
        $central .= "PK\x01\x02" . pack('v', 20) . $common . pack('vvvvVV', 0, 0, 0, 0, 0, $offset) . $name;
        $offset += 30 + strlen($name) + strlen($packed);
        unset($data, $packed);
    }
    fwrite($out, $central);
    fwrite($out, "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($files), count($files), strlen($central), $offset, 0));
    fclose($out);
}

function backup_size_text($bytes) {
    if ($bytes === null) return '—';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return number_format($bytes / 1024, 0) . ' KB';
    return $bytes . ' bytes';
}

ensure_backup_log($pdo);
$error = '';

// ---------- Download a backup (only when the admin asks for one) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup') {
    $kind = ($_POST['kind'] ?? 'sql') === 'zip' ? 'zip' : 'sql';
    $note = mb_substr(trim($_POST['note'] ?? ''), 0, 200);
    $me   = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $me->execute([$_SESSION['user_id']]);
    $me   = $me->fetch();

    // The backup holds every patient's record, so the password is asked again.
    if (rate_limited($pdo, 'backup_pw', 5, 900)) {
        $error = 'Too many wrong passwords. Please wait 15 minutes and try again.';
    } elseif (!$me || !password_verify($_POST['password'] ?? '', $me['password'])) {
        rate_hit($pdo, 'backup_pw');
        log_activity($pdo, 'Access denied', 'Data backup: wrong password');
        $error = 'Wrong password. The backup was not downloaded.';
    } else {
        @set_time_limit(300);
        $by    = $_SESSION['name'] ?? 'Admin';
        $stamp = date('Y-m-d_His');
        $base  = 'st-therese-backup_' . $stamp;
        while (ob_get_level()) ob_end_clean();          // no page output (or CSRF buffer) in the file

        if ($kind === 'sql') {
            $fileName = $base . '.sql';
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Cache-Control: no-store');
            $out = fopen('php://output', 'w');
            // Built in a temp stream first (memory, then disk if large) so the size is known.
            $tmp = fopen('php://temp/maxmemory:' . (8 * 1048576), 'w+');
            [$nTables, $nRows] = backup_write_sql($pdo, $tmp, $by, $note);
            $size = ftell($tmp);
            rewind($tmp);
            header('Content-Length: ' . $size);
            stream_copy_to_stream($tmp, $out);
            fclose($tmp);
            fclose($out);
        } else {
            // .zip: the SQL file + the uploads folder (X-rays, photos, landing images).
            $fileName = $base . '.zip';
            $sqlTmp = tempnam(sys_get_temp_dir(), 'bk');
            $zipTmp = tempnam(sys_get_temp_dir(), 'bkz');
            $fh = fopen($sqlTmp, 'w');
            [$nTables, $nRows] = backup_write_sql($pdo, $fh, $by, $note);
            fclose($fh);
            $files = [$base . '.sql' => $sqlTmp];
            $up = realpath(__DIR__ . '/uploads');
            if ($up) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($up, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if (!$f->isFile()) continue;
                    $files['uploads/' . str_replace('\\', '/', substr($f->getPathname(), strlen($up) + 1))] = $f->getPathname();
                }
            }
            backup_make_zip($zipTmp, $files);
            $size = filesize($zipTmp);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Content-Length: ' . $size);
            header('Cache-Control: no-store');
            readfile($zipTmp);
            @unlink($sqlTmp);
            @unlink($zipTmp);
        }

        $pdo->prepare("INSERT INTO backup_log (file_name, kind, size_bytes, tables, row_count, note, created_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$fileName, $kind, $size, $nTables, $nRows, $note !== '' ? $note : null, $by]);
        log_activity($pdo, 'Exported data backup', "$fileName · $nTables tables, " . number_format($nRows) . ' rows · ' . backup_size_text($size));
        exit;
    }
}

// ---------- The page ----------
$tables  = backup_tables($pdo);
$total   = array_sum($tables);
$history = $pdo->query("SELECT * FROM backup_log ORDER BY created_at DESC, id DESC LIMIT 20")->fetchAll();
$last    = $history[0] ?? null;
$daysAgo = $last ? (int)floor((time() - strtotime($last['created_at'])) / 86400) : null;

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
                <div class="sub">Download a copy of all the clinic's data — only when you click the button (manual, never automatic)</div>
            </div>
        </div>

        <?php include 'includes/admin_tabs.php'; ?>

        <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>

        <?php if (!$last): ?>
            <div class="alert py-2" style="background:#fff6e0;border:1px solid var(--gold);color:#8a6d2f;">⚠ No backup has been downloaded yet. Make one now and keep it somewhere safe.</div>
        <?php elseif ($daysAgo >= BACKUP_REMIND_DAYS): ?>
            <div class="alert py-2" style="background:#fff6e0;border:1px solid var(--gold);color:#8a6d2f;">⚠ The last backup was <?= $daysAgo ?> days ago. Consider making a new one.</div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card-box h-100">
                    <h5 class="mb-1">💾 Make a backup now</h5>
                    <div class="text-muted2 mb-3" style="font-size:.85rem;">
                        One file with every table: patients, appointments, records, dental charts, accounts, settings and the logs.
                        It is made the moment you click and goes straight to your computer — nothing is kept on the server.
                    </div>
                    <form method="POST" autocomplete="off">
                        <input type="hidden" name="action" value="backup">
                        <label class="field-label">What to include</label>
                        <div class="mb-3">
                            <label class="d-flex gap-2 align-items-start mb-1" style="cursor:pointer;">
                                <input type="radio" name="kind" value="sql" class="form-check-input mt-1" checked>
                                <span><b>Database only</b> <span class="text-muted2">(.sql)</span><br>
                                    <small class="text-muted2">All the data. Opens in phpMyAdmin.</small></span>
                            </label>
                            <label class="d-flex gap-2 align-items-start" style="cursor:pointer;">
                                <input type="radio" name="kind" value="zip" class="form-check-input mt-1">
                                <span><b>Database + uploaded files</b> <span class="text-muted2">(.zip)</span><br>
                                    <small class="text-muted2">Adds the panoramic X-ray images and photos. Open it with any unzip tool.</small></span>
                            </label>
                        </div>
                        <label class="field-label">Note <span class="text-muted2">(optional)</span></label>
                        <input name="note" class="form-control mb-3" maxlength="200" placeholder="e.g. Before the system update">
                        <label class="field-label">Your password</label>
                        <input type="password" name="password" class="form-control mb-1" required autocomplete="current-password">
                        <div class="text-muted2 mb-3" style="font-size:.78rem;">🔒 The backup holds every patient's record, so your password is asked again.</div>
                        <button class="btn btn-teal" data-keep-text>⬇ Download backup</button>
                    </form>
                    <div class="alert alert-light border mt-3 mb-0 py-2" style="font-size:.82rem;">
                        <b>Keep the file private</b> — store it on a USB drive or a private folder, not in email or chat.<br>
                        <b>To restore:</b> phpMyAdmin → the <code>dental_clinic</code> database → <i>Import</i> → choose the .sql file.
                        Restoring replaces the current data with the copy.
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card-box h-100">
                    <h5 class="mb-1">📦 What is in the database now</h5>
                    <div class="text-muted2 mb-2" style="font-size:.85rem;"><?= count($tables) ?> tables · <?= number_format($total) ?> rows</div>
                    <div class="table-responsive" style="max-height:360px;overflow-y:auto;">
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
            <div class="text-muted2 mb-2" style="font-size:.85rem;">Who downloaded a backup, and when. The files themselves are only on the computer they were downloaded to.</div>
            <?php if (!$history): ?>
                <div class="text-muted2">No backups yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>When</th><th>File</th><th>Size</th><th>Contents</th><th>By</th><th>Note</th></tr></thead>
                        <tbody>
                        <?php foreach ($history as $h): ?>
                            <tr>
                                <td style="white-space:nowrap;"><?= e(date('M j, Y g:i A', strtotime($h['created_at']))) ?></td>
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
<script>
// The file downloads and this page stays: clear the password and refresh "Past backups" a moment later.
document.querySelector('form input[name="action"][value="backup"]').form.addEventListener('submit', function () {
    var pw = this.querySelector('input[name="password"]');
    setTimeout(function () { pw.value = ''; location.replace('admin_backup'); }, 4000);
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
</body></html>
