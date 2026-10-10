<?php
// ============================================================
//  ARCHIVED RECORDS  (includes/record_archive.php)
// ============================================================
//  Treatment records, X-rays, clinical notes and appointments are not
//  erased when someone deletes them: the row is copied into
//  archived_records (as JSON) and taken out of its own table, so every
//  list, report and chart stops showing it straight away. The admin can
//  then Restore it (put back with the same id) or delete it for good
//  from the Archive page.
//
//  An X-ray's image file stays on disk until it is deleted for good.
// ============================================================

// item type => [table, label shown in the Archive]
const RECORD_ARCHIVE_TYPES = [
    'treatment'   => ['treatments',     'Treatment record'],
    'xray'        => ['xrays',          'X-ray'],
    'note'        => ['clinical_notes', 'Clinical note'],
    'appointment' => ['appointments',   'Appointment'],
];

function ensure_record_archive_table($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS archived_records (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            item_type   VARCHAR(20)  NOT NULL,
            item_id     INT          NOT NULL,
            patient_id  INT          DEFAULT NULL,
            summary     VARCHAR(255) DEFAULT NULL,
            row_data    LONGTEXT     NOT NULL,
            archived_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
            archived_by VARCHAR(100) DEFAULT NULL,
            KEY (item_type), KEY (patient_id)
        )");
    } catch (Throwable $e) { /* ignore */ }
}

// One line that tells the admin what the item was.
function record_archive_summary($type, $r) {
    $d = function ($v) { return $v ? date('M j, Y', strtotime($v)) : ''; };
    switch ($type) {
        case 'treatment':
            return trim(($r['treatment_name'] ?? 'Treatment')
                 . (!empty($r['tooth']) ? ' · tooth ' . $r['tooth'] : '')
                 . (!empty($r['treatment_date']) ? ' · ' . $d($r['treatment_date']) : ''));
        case 'xray':
            return trim(($r['caption'] ?: 'X-ray image') . (!empty($r['xray_date']) ? ' · ' . $d($r['xray_date']) : ''));
        case 'note':
            $n = trim(preg_replace('/\s+/', ' ', (string)($r['note'] ?? '')));
            return mb_strlen($n) > 90 ? mb_substr($n, 0, 90) . '…' : $n;
        case 'appointment':
            return trim($d($r['appointment_date'] ?? '')
                 . (!empty($r['appointment_time']) ? ' ' . date('g:i A', strtotime($r['appointment_time'])) : '')
                 . (!empty($r['treatment']) ? ' · ' . $r['treatment'] : '')
                 . (!empty($r['status']) ? ' (' . $r['status'] . ')' : ''));
    }
    return '';
}

// Moves one row into the Archive. $where adds a safety check, e.g.
// ['patient_id' => 5] so a record can only be archived from its own patient.
// Returns the archived row, or null if it was not found.
function archive_record($pdo, $type, $id, $where = []) {
    if (!isset(RECORD_ARCHIVE_TYPES[$type])) return null;
    $table = RECORD_ARCHIVE_TYPES[$type][0];
    $sql = "SELECT * FROM $table WHERE id = ?";
    $args = [(int)$id];
    foreach ($where as $col => $val) { $sql .= " AND `" . str_replace('`', '', $col) . "` = ?"; $args[] = $val; }
    $q = $pdo->prepare($sql);
    $q->execute($args);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    ensure_record_archive_table($pdo);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO archived_records (item_type, item_id, patient_id, summary, row_data, archived_by)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$type, (int)$row['id'], isset($row['patient_id']) ? (int)$row['patient_id'] : null,
                       mb_substr(record_archive_summary($type, $row), 0, 255),
                       json_encode($row, JSON_UNESCAPED_UNICODE), $_SESSION['name'] ?? null]);
        $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([(int)$row['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $row;
}

// Puts an archived row back in its table (same id when it is still free).
// Returns [ok, message].
function restore_record($pdo, $archiveId) {
    $q = $pdo->prepare("SELECT * FROM archived_records WHERE id = ?");
    $q->execute([(int)$archiveId]);
    $a = $q->fetch(PDO::FETCH_ASSOC);
    if (!$a || !isset(RECORD_ARCHIVE_TYPES[$a['item_type']])) return [false, 'That item is no longer in the Archive.'];
    $table = RECORD_ARCHIVE_TYPES[$a['item_type']][0];
    $row = json_decode($a['row_data'], true) ?: [];

    if (!empty($row['patient_id'])) {
        $p = $pdo->prepare("SELECT status FROM patients WHERE id = ?");
        $p->execute([(int)$row['patient_id']]);
        $st = $p->fetchColumn();
        if ($st === false) return [false, 'The patient this belonged to no longer exists, so it cannot be restored.'];
    }

    // Only the columns the table still has (the table may have changed since).
    $cols = $pdo->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_COLUMN);
    $row  = array_intersect_key($row, array_flip($cols));
    $taken = $pdo->prepare("SELECT 1 FROM $table WHERE id = ?");
    $taken->execute([(int)($row['id'] ?? 0)]);
    if ($taken->fetchColumn()) unset($row['id']);    // id reused meanwhile: give it a new one

    $names = array_keys($row);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO $table (`" . implode('`,`', $names) . "`) VALUES (" . rtrim(str_repeat('?,', count($names)), ',') . ")")
            ->execute(array_values($row));
        $pdo->prepare("DELETE FROM archived_records WHERE id = ?")->execute([(int)$a['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, 'Could not restore it: ' . $e->getMessage()];
    }
    return [true, RECORD_ARCHIVE_TYPES[$a['item_type']][1] . ' restored.'];
}

// Deletes an archived item for good (and an X-ray's image file).
function purge_archived_record($pdo, $archiveId) {
    $q = $pdo->prepare("SELECT * FROM archived_records WHERE id = ?");
    $q->execute([(int)$archiveId]);
    $a = $q->fetch(PDO::FETCH_ASSOC);
    if (!$a) return null;
    if ($a['item_type'] === 'xray') {
        $row = json_decode($a['row_data'], true) ?: [];
        $f = __DIR__ . '/../uploads/xrays/' . basename((string)($row['image_file'] ?? ''));
        if (!empty($row['image_file']) && is_file($f)) @unlink($f);
    }
    $pdo->prepare("DELETE FROM archived_records WHERE id = ?")->execute([(int)$a['id']]);
    return $a;
}

// When a patient is deleted for good, their archived records go too.
function purge_patient_archived_records($pdo, $patientId) {
    try {
        $q = $pdo->prepare("SELECT id FROM archived_records WHERE patient_id = ?");
        $q->execute([(int)$patientId]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $aid) purge_archived_record($pdo, $aid);
    } catch (Throwable $e) { /* table not created yet */ }
}
