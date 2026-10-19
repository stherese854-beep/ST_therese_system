<?php
// ============================================================
//  STAFF CHARTING WITH A DENTIST'S PIN  (includes/chart_access.php)
// ============================================================
//  Front-desk staff cannot open a patient's Odontogram or Treatment
//  Records on their own — not even to look. A dentist unlocks ONE patient
//  for them by typing the dentist's own approval PIN (each dentist creates
//  it in Profile & Settings; it is stored hashed, like a password).
//
//   * It lasts until the staff member closes it, or 30 minutes without
//     activity (their own 15-minute sign-out ends it sooner).
//   * While open, staff may: mark teeth and tooth notes on the latest
//     visit, start today's chart, change the latest visit's details, and
//     add treatment records (which also counts follow-up sessions).
//     Nothing can be deleted or archived; past visits stay as they are.
//   * Everything saved goes under the DENTIST's name, with
//     "entered by <staff>" (treatments / odontogram / chart_sessions
//     .entered_by), and every step is in the Activity Log.
//   * 5 wrong PINs lock that dentist's PIN for 15 minutes.
// ============================================================
const CHART_ACCESS_MINUTES = 30;
const PIN_MAX_TRIES        = 5;
const PIN_LOCK_MINUTES     = 15;

// Self-healing: the PIN columns and "entered by" columns.
function ensure_chart_access_schema($pdo) {
    $add = [
        'users'          => ['approval_pin' => 'VARCHAR(255) DEFAULT NULL', 'pin_fail_count' => 'INT NOT NULL DEFAULT 0',
                             'pin_locked_until' => 'DATETIME DEFAULT NULL'],
        'treatments'     => ['entered_by' => 'VARCHAR(100) DEFAULT NULL'],
        'odontogram'     => ['entered_by' => 'VARCHAR(100) DEFAULT NULL'],
        'chart_sessions' => ['entered_by' => 'VARCHAR(100) DEFAULT NULL'],
    ];
    foreach ($add as $t => $cols) {
        foreach ($cols as $c => $def) {
            try {
                if (!$pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->fetch()) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
            } catch (Throwable $e) { /* try again after the next update */ }
        }
    }
}

/** '' when the PIN is acceptable, else why not. */
function pin_problem($pin) {
    $pin = (string)$pin;
    if (!preg_match('/^\d{4,6}$/', $pin))   return 'The PIN must be 4 to 6 digits (numbers only).';
    if (preg_match('/^(\d)\1+$/', $pin))    return 'That PIN is too easy to guess (the same digit only, like 0000). Please choose another.';
    if (strpos('01234567890123', $pin) !== false || strpos('98765432109876', $pin) !== false)
                                            return 'That PIN is too easy to guess (numbers in a row, like 1234). Please choose another.';
    if (in_array($pin, ['1212', '6969', '2580', '0852', '1122', '121212', '112233', '123123', '696969'], true))
                                            return 'That PIN is too easy to guess. Please choose another.';
    return '';
}

/** Dentists who may unlock this patient: those who may open them (own patient, or seeing them). */
function chart_access_dentists($pdo, $pid) {
    $all = $pdo->query("SELECT id, name, approval_pin, pin_locked_until FROM users
                         WHERE role = 'dentist' AND status = 'active' ORDER BY name")->fetchAll();
    $out = [];
    foreach ($all as $d) {
        $prm = [];
        $q = $pdo->prepare("SELECT COUNT(*) FROM patients p WHERE p.id = ? AND " . dentist_patient_sql('p.id', 'p.primary_dentist', $d['name'], $prm));
        $q->execute(array_merge([(int)$pid], $prm));
        if ((int)$q->fetchColumn()) $out[(int)$d['id']] = $d;
    }
    if (!$out) foreach ($all as $d) $out[(int)$d['id']] = $d;     // nobody linked to them yet: any dentist
    return $out;
}

/**
 * The staff member's open access for this patient, or null. Each use counts as
 * activity; 30 minutes without any ends it (keepalive.php also counts activity).
 */
function staff_chart_access($pid, $touch = true) {
    $a = $_SESSION['chart_access'] ?? null;
    if (!$a || (int)$a['patient_id'] !== (int)$pid || current_role() !== 'staff') return null;
    if (time() - (int)$a['last'] > CHART_ACCESS_MINUTES * 60) {
        unset($_SESSION['chart_access']);
        $_SESSION['chart_access_ended'] = (int)$pid;              // say why it is locked again
        return null;
    }
    if ($touch) $_SESSION['chart_access']['last'] = time();
    return $_SESSION['chart_access'];
}

/**
 * The unlock / close forms (staff, on the Odontogram and Treatment Records).
 * Returns [flash type, message], or null when the action is something else.
 */
function chart_access_post($pdo, $pid) {
    $action = $_POST['action'] ?? '';
    if ($action === 'close_chart') {
        $a = $_SESSION['chart_access'] ?? null;
        unset($_SESSION['chart_access'], $_SESSION['chart_access_ended']);
        if ($a) log_activity($pdo, 'Closed chart access', patient_name_of($pdo, (int)$a['patient_id']) . ' — approved by ' . $a['dentist_name']);
        return ['info', 'Closed — this patient\'s chart and records are locked again.'];
    }
    if ($action !== 'unlock_chart') return null;

    $did  = (int)($_POST['dentist_id'] ?? 0);
    $pin  = trim((string)($_POST['pin'] ?? ''));
    $list = chart_access_dentists($pdo, $pid);
    if (!isset($list[$did])) return ['error', 'Please choose the dentist who approves.'];
    $d = $list[$did];
    $who = patient_name_of($pdo, $pid);
    if (empty($d['approval_pin'])) return ['error', $d['name'] . ' has not set up an approval PIN yet (Profile & Settings).'];
    if ($d['pin_locked_until'] && strtotime($d['pin_locked_until']) > time()) {
        $mins = (int)ceil((strtotime($d['pin_locked_until']) - time()) / 60);
        return ['error', $d['name'] . "'s PIN is locked for $mins more minute" . ($mins === 1 ? '' : 's') . ' after too many wrong tries.'];
    }
    if (!password_verify($pin, $d['approval_pin'])) {
        $pdo->prepare("UPDATE users SET pin_fail_count = pin_fail_count + 1 WHERE id = ?")->execute([$did]);
        $n = (int)$pdo->query("SELECT pin_fail_count FROM users WHERE id = " . $did)->fetchColumn();
        if ($n >= PIN_MAX_TRIES) {
            $pdo->prepare("UPDATE users SET pin_fail_count = 0, pin_locked_until = NOW() + INTERVAL " . PIN_LOCK_MINUTES . " MINUTE WHERE id = ?")->execute([$did]);
            log_activity($pdo, 'Approval PIN locked', $d['name'] . ' — ' . PIN_MAX_TRIES . ' wrong tries (patient ' . $who . ')');
            return ['error', 'Wrong PIN. ' . $d['name'] . "'s PIN is now locked for " . PIN_LOCK_MINUTES . ' minutes.'];
        }
        log_activity($pdo, 'Wrong approval PIN', $d['name'] . ' — patient ' . $who);
        $left = PIN_MAX_TRIES - $n;
        return ['error', "Wrong PIN. $left tr" . ($left === 1 ? 'y' : 'ies') . ' left before it locks.'];
    }
    $pdo->prepare("UPDATE users SET pin_fail_count = 0, pin_locked_until = NULL WHERE id = ?")->execute([$did]);
    $_SESSION['chart_access'] = ['patient_id' => (int)$pid, 'dentist_id' => $did, 'dentist_name' => $d['name'], 'since' => time(), 'last' => time()];
    unset($_SESSION['chart_access_ended']);
    log_activity($pdo, 'Chart access approved', $who . ' — approved by ' . $d['name'] . ' (PIN)');
    return ['success', 'Unlocked by ' . $d['name'] . '. Everything you record goes under their name.'];
}

/** The lock card shown to staff instead of the chart / records. */
function chart_access_card($pdo, $pid, $patientName) {
    $list  = chart_access_dentists($pdo, $pid);
    $ended = (int)($_SESSION['chart_access_ended'] ?? 0) === (int)$pid;
    ob_start(); ?>
    <div class="card-box mb-3" style="max-width:560px;border-top:4px solid var(--teal-mid);">
        <h5 class="mb-1">🔒 <?= e($patientName) ?>'s chart and records</h5>
        <p class="text-muted2 mb-3" style="font-size:.9rem;">
            Staff can open them with a dentist's approval: the dentist types their own approval PIN.
            Everything you record then goes under the dentist's name, with your name as the one who entered it.
        </p>
        <?php if ($ended): ?>
            <div class="alert alert-warning py-2" style="font-size:.86rem;">The last approval ended after <?= CHART_ACCESS_MINUTES ?> minutes without activity.</div>
        <?php endif; ?>
        <form method="POST" autocomplete="off" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="unlock_chart">
            <input type="hidden" name="patient_id" value="<?= (int)$pid ?>">
            <div class="col-sm-6">
                <label class="field-label">Dentist</label>
                <select name="dentist_id" class="form-select" required>
                    <?php foreach ($list as $id => $d):
                        $locked = $d['pin_locked_until'] && strtotime($d['pin_locked_until']) > time(); ?>
                        <option value="<?= (int)$id ?>" <?= empty($d['approval_pin']) || $locked ? 'disabled' : '' ?>>
                            <?= e($d['name']) ?><?= empty($d['approval_pin']) ? ' — no PIN set up yet' : ($locked ? ' — PIN locked for now' : '') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-3">
                <label class="field-label">Dentist's PIN</label>
                <input type="password" name="pin" class="form-control" inputmode="numeric" maxlength="6" pattern="\d{4,6}" data-digits
                       autocomplete="one-time-code" placeholder="••••" required>
            </div>
            <div class="col-sm-3"><button class="btn btn-teal w-100" data-keep-text>🔓 Unlock</button></div>
        </form>
        <div class="text-muted2 mt-2" style="font-size:.78rem;">
            Lasts until you close it, or <?= CHART_ACCESS_MINUTES ?> minutes without activity. Dentists create their PIN in Profile &amp; Settings.
        </div>
    </div>
    <?php return ob_get_clean();
}

/** The bar shown while a staff member is working with a dentist's approval. */
function chart_access_bar($access) {
    ob_start(); ?>
    <div class="no-print mb-3 d-flex flex-wrap align-items-center gap-2"
         style="background:#eef7f6;border:1px solid #cfe0dd;border-left:5px solid var(--teal-mid);border-radius:10px;padding:9px 14px;font-size:.9rem;">
        <span>🔓 Opened with <b><?= e($access['dentist_name']) ?></b>'s approval — what you record goes under their name
            (entered by you). Deleting and past visits stay with the dentist.</span>
        <form method="POST" class="m-0 ms-auto">
            <input type="hidden" name="action" value="close_chart">
            <input type="hidden" name="patient_id" value="<?= (int)$access['patient_id'] ?>">
            <button class="btn btn-sm btn-light" data-keep-text>🔒 Close</button>
        </form>
    </div>
    <?php return ob_get_clean();
}
