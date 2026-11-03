<?php
// ============================================================
//  DATA BACKUP HELPERS  (includes/backup.php)
// ============================================================
//  Used by the Data Backup page (admin_backup.php) and by the
//  AUTOMATIC backup that runs on page load (includes/head.php).
//
//  MANUAL: the admin clicks "Download backup", types their password,
//  and the file goes straight to their computer (never kept here).
//
//  AUTOMATIC: the admin picks Every week / Every month / Off. The site
//  has no scheduler (like the reminders), so the first page a signed-in
//  user opens once a backup is due makes it. The file is SAVED on the
//  server in backups/ (closed to the web by its own .htaccess); only the
//  newest few are kept, older ones are deleted. The admin downloads a
//  saved one from the Data Backup page with their password.
// ============================================================

// Tables whose ROWS are left out (the table itself is still created):
// sign-in sessions and the anti-spam counters are not clinic data.
const BACKUP_SKIP_ROWS = ['php_sessions', 'rate_limits'];
const BACKUP_ROWS_PER_INSERT = 100;
const BACKUP_REMIND_DAYS = 7;          // "It has been N days since the last backup" after this
const BACKUP_FREQS = ['off' => 'Off — manual only', 'weekly' => 'Every week', 'monthly' => 'Every month'];
const BACKUP_KEEP_DEFAULT = 4;         // saved automatic backups kept on the server
const BACKUP_KEEP_MAX = 20;

function ensure_backup_log($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS backup_log (
                    id          INT AUTO_INCREMENT PRIMARY KEY,
                    file_name   VARCHAR(120) NOT NULL,
                    kind        VARCHAR(20)  NOT NULL DEFAULT 'sql',   -- sql / zip
                    size_bytes  BIGINT       DEFAULT NULL,
                    tables      INT          DEFAULT NULL,
                    row_count   INT          DEFAULT NULL,
                    note        VARCHAR(255) DEFAULT NULL,
                    created_by  VARCHAR(100) DEFAULT NULL,
                    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
                    is_auto     TINYINT(1)   NOT NULL DEFAULT 0,       -- 1 = made by the schedule
                    stored_file VARCHAR(255) DEFAULT NULL              -- saved on the server (automatic), else NULL
                ) DEFAULT CHARSET=utf8mb4");
    // Older installs: add the two automatic-backup columns.
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM backup_log")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_auto', $cols, true))     $pdo->exec("ALTER TABLE backup_log ADD is_auto TINYINT(1) NOT NULL DEFAULT 0");
        if (!in_array('stored_file', $cols, true)) $pdo->exec("ALTER TABLE backup_log ADD stored_file VARCHAR(255) DEFAULT NULL");
    } catch (Throwable $e) {}
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

/**
 * Makes a backup FILE at $destPath (sql or zip). Returns [tables, rows, size].
 * The .zip holds the .sql plus the uploads folder (X-rays, photos, landing images).
 */
function backup_build_file($pdo, $kind, $destPath, $base, $by, $note) {
    if ($kind === 'sql') {
        $fh = fopen($destPath, 'w');
        [$nTables, $nRows] = backup_write_sql($pdo, $fh, $by, $note);
        fclose($fh);
        return [$nTables, $nRows, filesize($destPath)];
    }
    $sqlTmp = tempnam(sys_get_temp_dir(), 'bk');
    $fh = fopen($sqlTmp, 'w');
    [$nTables, $nRows] = backup_write_sql($pdo, $fh, $by, $note);
    fclose($fh);
    $files = [$base . '.sql' => $sqlTmp];
    $up = realpath(__DIR__ . '/../uploads');
    if ($up) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($up, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $files['uploads/' . str_replace('\\', '/', substr($f->getPathname(), strlen($up) + 1))] = $f->getPathname();
        }
    }
    backup_make_zip($destPath, $files);
    @unlink($sqlTmp);
    clearstatcache(true, $destPath);
    return [$nTables, $nRows, filesize($destPath)];
}

// ---------------------------------------------------------------
//  AUTOMATIC BACKUPS
// ---------------------------------------------------------------

/** The folder the automatic backups are saved in (closed to the web). '' if none can be made. */
function backup_dir() {
    foreach ([__DIR__ . '/../backups', sys_get_temp_dir() . '/st-therese-backups'] as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        if (!is_dir($dir) || !is_writable($dir)) continue;
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "# Saved backups hold every patient's record: never served to the web.\n"
                . "Options -Indexes\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order Allow,Deny\n    Deny from all\n</IfModule>\n");
        }
        if (!is_file($dir . '/index.php')) @file_put_contents($dir . '/index.php', "<?php http_response_code(404);\n");
        return realpath($dir);
    }
    return '';
}

/** ['freq' => off|weekly|monthly, 'kind' => sql|zip, 'keep' => int, 'last' => 'Y-m-d H:i:s' or '', 'error' => ''] */
function backup_auto_settings($pdo) {
    $s = ['freq' => 'off', 'kind' => 'sql', 'keep' => BACKUP_KEEP_DEFAULT, 'last' => '', 'error' => ''];
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN
                             ('auto_backup_freq','auto_backup_kind','auto_backup_keep','auto_backup_last','auto_backup_error')")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) { return $s; }
    if (isset(BACKUP_FREQS[$rows['auto_backup_freq'] ?? ''])) $s['freq'] = $rows['auto_backup_freq'];
    if (($rows['auto_backup_kind'] ?? '') === 'zip')         $s['kind'] = 'zip';
    if (isset($rows['auto_backup_keep']))                     $s['keep'] = max(1, min(BACKUP_KEEP_MAX, (int)$rows['auto_backup_keep']));
    $s['last']  = (string)($rows['auto_backup_last'] ?? '');
    $s['error'] = (string)($rows['auto_backup_error'] ?? '');
    return $s;
}

/** When the next automatic backup is due (timestamp), or null when the schedule is off. */
function backup_next_due($s) {
    if ($s['freq'] === 'off') return null;
    if ($s['last'] === '' || !strtotime($s['last'])) return time();      // never made one yet: due now
    $day = strtotime(date('Y-m-d', strtotime($s['last'])));              // counted in days, whatever the hour
    return strtotime($s['freq'] === 'weekly' ? '+7 days' : '+1 month', $day);
}

/**
 * Called on page load (includes/head.php): makes the automatic backup when
 * one is due. Cheap when nothing is due (one settings query).
 * $force = true makes one now ("Save one now" on the Data Backup page).
 * Returns the backup_log id, or 0.
 */
function run_auto_backup($pdo, $force = false) {
    $s = backup_auto_settings($pdo);
    if (!$force) {
        $due = backup_next_due($s);
        if ($due === null || time() < $due) return 0;
    }
    try {
        ensure_backup_log($pdo);
        // Claim this run first, so two visitors at the same moment can't both make one.
        $prev = $s['last'];
        $now  = date('Y-m-d H:i:s');
        if (!$force) {
            $st = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'auto_backup_last' AND setting_value = ?");
            $st->execute([$now, $prev]);
            if ($st->rowCount() === 0) {
                if ($prev !== '') return 0;                                      // someone else claimed it
                $ins = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('auto_backup_last', ?)");
                $ins->execute([$now]);
                if ($ins->rowCount() === 0) return 0;
            }
        } else {
            save_setting($pdo, 'auto_backup_last', $now);
        }

        $dir = backup_dir();
        if ($dir === '') throw new RuntimeException('The server has no folder it can save backups in.');
        @set_time_limit(300);
        $base = 'st-therese-auto-backup_' . date('Y-m-d_His');
        $file = $base . '_' . bin2hex(random_bytes(4)) . '.' . $s['kind'];   // unguessable name, too
        $by   = $force ? (($_SESSION['name'] ?? 'Admin') . ' (saved now)') : 'Automatic (' . strtolower(BACKUP_FREQS[$s['freq']]) . ')';
        [$nTables, $nRows, $size] = backup_build_file($pdo, $s['kind'], $dir . DIRECTORY_SEPARATOR . $file, $base, $by, '');

        $pdo->prepare("INSERT INTO backup_log (file_name, kind, size_bytes, tables, row_count, note, created_by, is_auto, stored_file)
                       VALUES (?,?,?,?,?,?,?,1,?)")
            ->execute([$base . '.' . $s['kind'], $s['kind'], $size, $nTables, $nRows, null, $by, $file]);
        $id = (int)$pdo->lastInsertId();
        save_setting($pdo, 'auto_backup_error', '');
        log_activity_as($pdo, $force ? ($_SESSION['user_id'] ?? null) : null, $force ? ($_SESSION['name'] ?? 'Admin') : 'System',
                        $force ? ($_SESSION['role'] ?? 'admin') : null, 'Automatic data backup',
                        "Saved on the server: $base.{$s['kind']} · $nTables tables, " . number_format($nRows) . ' rows · ' . backup_size_text($size));
        backup_prune($pdo, $s['keep']);
        return $id;
    } catch (Throwable $e) {
        try {
            save_setting($pdo, 'auto_backup_error', date('M j, Y g:i A') . ' — ' . mb_substr($e->getMessage(), 0, 200));
            log_activity_as($pdo, null, 'System', null, 'Automatic data backup failed', mb_substr($e->getMessage(), 0, 300));
        } catch (Throwable $e2) {}
        return 0;
    }
}

/** Keeps only the newest $keep saved automatic backups; deletes the older files. */
function backup_prune($pdo, $keep) {
    $dir = backup_dir();
    $rows = $pdo->query("SELECT id, stored_file FROM backup_log WHERE stored_file IS NOT NULL ORDER BY created_at DESC, id DESC")->fetchAll();
    foreach (array_slice($rows, max(1, (int)$keep)) as $r) {
        if ($dir !== '' && basename($r['stored_file']) === $r['stored_file']) @unlink($dir . DIRECTORY_SEPARATOR . $r['stored_file']);
        $pdo->prepare("UPDATE backup_log SET stored_file = NULL WHERE id = ?")->execute([$r['id']]);
    }
}

/** Full path of a saved backup, or '' if it is gone (e.g. the server was redeployed). */
function backup_stored_path($storedFile) {
    if (!$storedFile || basename($storedFile) !== $storedFile) return '';
    $dir = backup_dir();
    $p = $dir !== '' ? $dir . DIRECTORY_SEPARATOR . $storedFile : '';
    return ($p !== '' && is_file($p)) ? $p : '';
}
