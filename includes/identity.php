<?php
// ============================================================
//  MIDDLE NAME + SAME FULL NAME CHECK  (includes/identity.php)
// ============================================================
//  Every patient and user has a middle name, saved IN FULL (middle_name),
//  or "no middle name" (no_middle_name = 1) — some people have none (a child
//  who carries only the mother's surname, many foreigners, some Muslim and
//  indigenous names). Profiles show only the initial: "Juan M. Dela Cruz".
//  `name` stays First + Last, as before (appointments and schedules use it).
//  Names saved before this have no middle name yet (both empty = not known).
//
//  SAME FULL NAME: two patients with the same first + FULL middle + last name
//  (the birthday and phone do not matter) may be one person with two records,
//  or someone using another person's name. When a record gets such a name:
//    - the pair is listed for the clinic (Patients → 👥 Same full name), with
//      "Same person → merge" and "Different people" (never flagged again);
//    - the person's account is asked (pop-up + email) to visit the clinic with
//      a valid ID, and online booking is paused for that account until then.
//  No middle name vs no middle name: compared on first + last name only.
//  A record whose middle name is not known yet is skipped, and members of the
//  same family (one account) are never paired.
// ============================================================

function ensure_identity_schema($pdo) {
    foreach (['patients', 'users'] as $t) {
        try {
            if (!$pdo->query("SHOW COLUMNS FROM `$t` LIKE 'middle_name'")->fetch()) {
                $pdo->exec("ALTER TABLE `$t` ADD COLUMN middle_name VARCHAR(60) NULL DEFAULT NULL AFTER first_name,
                                             ADD COLUMN no_middle_name TINYINT(1) NOT NULL DEFAULT 0 AFTER middle_name");
            }
        } catch (Throwable $e) {}
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS identity_checks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INT NOT NULL,                      -- the record that got the name (its account is asked to visit)
            match_id INT NOT NULL,                        -- the record that already had it
            pair_key VARCHAR(30) NOT NULL,                -- 'smaller-bigger' id: one row per pair, ever
            status VARCHAR(12) NOT NULL DEFAULT 'open',   -- open / different / same
            found_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            checked_by VARCHAR(120) NULL,
            checked_at DATETIME NULL,
            UNIQUE KEY uq_pair (pair_key),
            INDEX idx_status (status)
        )");
    } catch (Throwable $e) {}
}

// ---- The middle name on forms ----

/** "  maria   clara " -> "maria clara" (at most 60 characters). */
function clean_name_part($s) {
    return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$s)), 0, 60);
}

/** The middle name a form sent: [middle, none] ("No middle name" ticked), or null when the form had no middle-name box. */
function posted_middle_name($field = 'middle_name') {
    if (!array_key_exists($field, $_POST) && !isset($_POST['no_' . $field])) return null;
    $none = !empty($_POST['no_' . $field]);
    return [$none ? '' : clean_name_part($_POST[$field] ?? ''), $none];
}

/** '' or why the middle name cannot be saved. $required: it must be typed, or "No middle name" ticked. */
function middle_name_problem($posted, $required = false) {
    if ($posted === null) $posted = ['', false];
    [$m, $none] = $posted;
    if ($none) return '';
    if ($m === '') return $required ? 'Please type the full middle name, or tick the “no middle name” box.' : '';
    if (mb_strlen(str_replace(['.', ' '], '', $m)) < 2) return 'Please type the full middle name, not just the initial (e.g. Santos, not S.).';
    if (!preg_match("/^\\p{L}[\\p{L}\\p{M} .'’-]*$/u", $m)) return 'The middle name can only have letters, spaces, dots, apostrophes and dashes.';
    return '';
}

/** Saves the middle name of a patient / user (nothing happens when the form had no middle-name box). */
function save_middle_name($pdo, $table, $id, $posted) {
    if ($posted === null || !in_array($table, ['patients', 'users'], true) || !(int)$id) return;
    [$m, $none] = $posted;
    try {
        $pdo->prepare("UPDATE `$table` SET middle_name = ?, no_middle_name = ? WHERE id = ?")
            ->execute([$none || $m === '' ? null : $m, $none ? 1 : 0, (int)$id]);
    } catch (Throwable $e) { /* never blocks the save itself */ }
}

/** Is the middle name of this row known (typed, or "no middle name")? */
function middle_name_known(array $row) {
    return !empty($row['no_middle_name']) || trim((string)($row['middle_name'] ?? '')) !== '';
}

/** "Santos" -> "S." */
function middle_initial($m) {
    $m = trim((string)$m);
    return $m === '' ? '' : mb_strtoupper(mb_substr($m, 0, 1)) . '.';
}

/** "Juan M. Dela Cruz" — the initial only, as profiles show it (just the name when no middle name is known). */
function name_with_initial(array $row) {
    $mi = middle_initial($row['middle_name'] ?? '');
    if ($mi === '' || empty($row['name'])) return (string)($row['name'] ?? '');
    [$f, $l] = name_parts_of($row);
    if ($f === '') return (string)$row['name'];
    [$title] = split_full_name($row['name']);               // "Dr." stays in front
    return trim(preg_replace('/\s+/', ' ', "$title $f $mi $l"));
}

/** "Juan Santos Dela Cruz" — with the FULL middle name (for the clinic's same-name check). */
function name_with_middle(array $row) {
    $m = trim((string)($row['middle_name'] ?? ''));
    if ($m === '' || empty($row['name'])) return (string)($row['name'] ?? '');
    [$f, $l] = name_parts_of($row);
    [$title] = split_full_name($row['name']);
    return trim(preg_replace('/\s+/', ' ', "$title $f $m $l"));
}

/**
 * The Middle Name box + "No middle name" tick. $field is the box's name; the tick is 'no_' . $field.
 * Options: id, required (the box or the tick is needed), label (false = none), tick (its words),
 * class, placeholder. The little script that greys the box out is printed once per page.
 */
function middle_name_input($field, $middle = '', $none = false, array $o = []) {
    $id  = $o['id'] ?? ('mid-' . $field);
    $req = !empty($o['required']);
    $h   = '';
    if (($o['label'] ?? true) !== false) {
        $h .= '<label class="field-label" for="' . e($id) . '">' . e($o['label'] ?? 'Middle Name') . ($req ? ' *' : '')
            . ' <span class="text-muted2" style="font-weight:400;">(in full)</span></label>';
    }
    $h .= '<input name="' . e($field) . '" id="' . e($id) . '" class="' . e($o['class'] ?? 'form-control') . '" maxlength="60"'
        . ' value="' . e($none ? '' : (string)$middle) . '" placeholder="' . e($o['placeholder'] ?? 'Full middle name, e.g. Santos') . '"'
        . ' autocomplete="additional-name" data-mid-box' . ($req ? ' data-mid-req="1" required' : '') . ($none ? ' disabled' : '') . '>'
        . '<label class="mid-none"><input type="checkbox" name="no_' . e($field) . '" value="1" data-mid-none="' . e($id) . '"'
        . ($none ? ' checked' : '') . '> ' . e($o['tick'] ?? 'No middle name') . '</label>';
    return $h . middle_name_script();
}

/**
 * On a profile: the middle name shows as its initial only (read-only — the clinic changes it).
 * Not known yet: the box to type it in once (required).
 */
function middle_name_profile_field($field, array $row, array $o = []) {
    if (!middle_name_known($row)) return middle_name_input($field, '', false, $o + ['required' => true, 'placeholder' => 'e.g. Santos']);
    $h = (($o['label'] ?? true) !== false) ? '<label class="field-label">Middle Initial</label>' : '';
    return $h . '<input class="' . e($o['class'] ?? 'form-control') . '" value="' . e(!empty($row['no_middle_name']) ? 'None' : middle_initial($row['middle_name'])) . '"'
         . ' readonly disabled style="background:#eef3f3;" title="' . e($o['locked'] ?? 'Only the clinic can change your middle name') . '">';
}

function middle_name_script() {
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<style>.mid-none{display:flex;align-items:center;gap:6px;font-size:.8rem;color:#55606a;margin:5px 0 0;cursor:pointer;font-weight:400;}
.mid-none input{width:15px;height:15px;margin:0;accent-color:var(--teal,#0f766e);}
input[data-mid-box]:disabled{background:#f1f4f5;}</style>
<script>
(function () {
    var MSG = "Please type the full middle name, not just the initial.";
    function sync(t) {                                  /* "No middle name" ticked: the box is not needed */
        var b = document.getElementById(t.getAttribute("data-mid-none"));
        if (!b) return;
        b.disabled = t.checked;
        if (t.checked) { b.value = ""; b.setCustomValidity(""); }
        if (b.getAttribute("data-mid-req") === "1") b.required = !t.checked;
    }
    function check(b) { b.setCustomValidity(b.value.replace(/[.\s]/g, "").length === 1 ? MSG : ""); }
    document.addEventListener("change", function (e) { if (e.target.matches && e.target.matches("[data-mid-none]")) sync(e.target); });
    document.addEventListener("input", function (e) { if (e.target.matches && e.target.matches("[data-mid-box]")) check(e.target); });
    function all() { document.querySelectorAll("[data-mid-none]").forEach(sync); }
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", all); else all();
    /* Fill a box from a script (edit dialogs): midSet("u-middle", "Santos", 0) */
    window.midSet = function (id, middle, none) {
        var b = document.getElementById(id), t = document.querySelector("[data-mid-none=\"" + id + "\"]");
        if (!b) return;
        b.value = middle || ""; check(b);
        if (t) { t.checked = !!+none; sync(t); }
    };
})();
</script>';
}

// ---- Same full name ----

/** The key two patients must share to have the same full name; null when the middle name is not known. */
function full_name_key(array $row) {
    $k = fn($s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$s)));
    [$f, $l] = name_parts_of($row);
    $f = $k($f); $l = $k($l);
    if ($f === '' || $l === '') return null;
    if (!empty($row['no_middle_name'])) return "$f|-|$l";
    $m = $k($row['middle_name'] ?? '');
    if (mb_strlen(str_replace(['.', ' '], '', $m)) < 2) return null;     // not known (or only an initial)
    return "$f|$m|$l";
}

function identity_pair_key($a, $b) {
    $a = (int)$a; $b = (int)$b;
    return min($a, $b) . '-' . max($a, $b);
}

/**
 * Looks for other patients with the same full name as this one and saves each new pair for
 * the clinic. $notify: the person's account holder gets the pop-up + email (the patient did it
 * themselves, online). When the clinic adds the record, staff are told on screen instead.
 * Returns how many NEW pairs were found.
 */
function identity_check_patient($pdo, $pid, $notify = true) {
    $pid = (int)$pid;
    try {
        $q = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
        $q->execute([$pid]);
        $me = $q->fetch();
        if (!$me || ($me['status'] ?? '') === 'Archived' || ($key = full_name_key($me)) === null) return 0;
        $holder = (int)($me['guardian_patient_id'] ?: $me['id']);
        // only records whose middle name is known can match
        $c = $pdo->prepare("SELECT * FROM patients WHERE id <> ? AND status <> 'Archived'
                              AND (no_middle_name = 1 OR (middle_name IS NOT NULL AND middle_name <> ''))");
        $c->execute([$pid]);
        $ins = $pdo->prepare("INSERT IGNORE INTO identity_checks (patient_id, match_id, pair_key) VALUES (?, ?, ?)");
        $new = 0; $with = [];
        foreach ($c->fetchAll() as $o) {
            if (full_name_key($o) !== $key) continue;
            if ((int)($o['guardian_patient_id'] ?: $o['id']) === $holder) continue;     // the same family
            $ins->execute([$pid, (int)$o['id'], identity_pair_key($pid, $o['id'])]);    // a pair checked before is not added again
            if ($ins->rowCount()) { $new++; $with[] = '#' . (int)$o['id']; }
        }
    } catch (Throwable $e) { return 0; }
    if ($new) {
        log_activity($pdo, 'Same full name found', name_with_middle($me) . " (#$pid) — same full name as " . implode(', ', $with)
                     . ($notify ? '; asked to visit the clinic, online booking paused' : ''));
        if ($notify) identity_notify($pdo, $pid);
    }
    return $new;
}

/** Pop-up + email to the account holder: please visit the clinic with a valid ID. */
function identity_notify($pdo, $pid) {
    require_once __DIR__ . '/patient_notices.php';
    if (!function_exists('mail_is_ready'))     require_once __DIR__ . '/mailer.php';
    if (!function_exists('message_catalogue')) require_once __DIR__ . '/message_templates.php';
    $holderId = account_holder_id($pdo, $pid);
    if (!$holderId) return;
    $q = $pdo->prepare("SELECT p.name, h.name AS holder_name, COALESCE(NULLIF(h.email, ''), u.email) AS email
                          FROM patients p JOIN patients h ON h.id = ? LEFT JOIN users u ON u.id = h.user_id WHERE p.id = ?");
    $q->execute([$holderId, (int)$pid]);
    $r = $q->fetch();
    if (!$r) return;
    $isSelf = ($holderId === (int)$pid);
    $who    = $isSelf ? 'yours' : $r['name'] . "'s";
    add_patient_notice($pdo, $holderId, 'samename', 'warning', '⚠️ Please visit the clinic',
        "We found another patient record with the same full name as $who (first, middle and last name).\n\n"
        . "To keep everyone's dental records safe and correct, please visit the clinic with a valid ID so our staff "
        . "can check it. It only takes a few minutes at the front desk.\n\n"
        . "Online booking is paused on your account until then.");
    if (!empty($r['email']) && mail_is_ready($pdo)) {
        $cat = message_catalogue()['same_name_check'];
        [$subject, $bodyHtml] = tpl_message($pdo, 'same_name_check', $cat['subject'], $cat['body'], [
            'patient' => $r['holder_name'],
            'who'     => $isSelf ? 'yours' : $r['name'] . "'s",
            'clinic'  => clinic_name($pdo),
        ]);
        $err = '';
        send_mail($pdo, $r['email'], $subject, $bodyHtml, $err, 'same_name_check');
    }
}

/** Is online booking paused for this account (the holder or a family member has a same-name check open)? */
function identity_hold($pdo, $holderId) {
    $ids = family_patient_ids($pdo, $holderId);
    if (!$ids) return false;
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM identity_checks WHERE status = 'open' AND patient_id IN (" . in_placeholders($ids) . ")");
        $q->execute($ids);
        return (int)$q->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

/** The booking page's message while it is paused. */
function identity_hold_message() {
    return "<strong>Online booking is paused: please visit the clinic.</strong><br><br>"
         . "We found another patient record with the same full name (first, middle and last name) as you or someone you book for. "
         . "Please visit the clinic with a <strong>valid ID</strong> so our staff can check it — it only takes a few minutes. "
         . "After that, you can book online again.";
}

/** Patient ids in an open pair (both sides): [id => true]. */
function identity_open_ids($pdo) {
    $ids = [];
    try {
        foreach ($pdo->query("SELECT patient_id, match_id FROM identity_checks WHERE status = 'open'") as $r) {
            $ids[(int)$r['patient_id']] = true; $ids[(int)$r['match_id']] = true;
        }
    } catch (Throwable $e) {}
    return $ids;
}

/** Open pairs, newest first, with both records. */
function identity_open_pairs($pdo) {
    try {
        $rows = $pdo->query("SELECT * FROM identity_checks WHERE status = 'open' ORDER BY found_at DESC, id DESC")->fetchAll();
    } catch (Throwable $e) { return []; }
    if (!$rows) return [];
    $ids = [];
    foreach ($rows as $r) { $ids[] = (int)$r['patient_id']; $ids[] = (int)$r['match_id']; }
    $ids = array_values(array_unique($ids));
    $q = $pdo->prepare("SELECT p.*, g.name AS guardian_name,
                               (SELECT COUNT(*) FROM appointments a WHERE a.patient_id = p.id) AS appt_count
                          FROM patients p LEFT JOIN patients g ON g.id = p.guardian_patient_id
                         WHERE p.id IN (" . in_placeholders($ids) . ")");
    $q->execute($ids);
    $p = [];
    foreach ($q->fetchAll() as $r) $p[(int)$r['id']] = $r;
    $out = [];
    foreach ($rows as $r) {
        if (isset($p[(int)$r['patient_id']], $p[(int)$r['match_id']])) $out[] = $r + ['a' => $p[(int)$r['patient_id']], 'b' => $p[(int)$r['match_id']]];
    }
    return $out;
}

/**
 * The clinic checked a pair: 'different' (two people — never flagged again) or 'same' (merged).
 * When nothing is open any more for that account, it is told it can book online again.
 * Returns the pair row, or null.
 */
function identity_resolve($pdo, $checkId, $status, $by) {
    if (!in_array($status, ['different', 'same'], true)) return null;
    $q = $pdo->prepare("SELECT * FROM identity_checks WHERE id = ? AND status = 'open'");
    $q->execute([(int)$checkId]);
    $c = $q->fetch();
    if (!$c) return null;
    $pdo->prepare("UPDATE identity_checks SET status = ?, checked_by = ?, checked_at = NOW() WHERE id = ?")
        ->execute([$status, mb_substr((string)$by, 0, 120), (int)$checkId]);
    identity_tell_lifted($pdo, (int)$c['patient_id']);
    return $c;
}

/** Nothing open any more for this record's account: "you can book online again". */
function identity_tell_lifted($pdo, $pid) {
    require_once __DIR__ . '/patient_notices.php';
    $holderId = account_holder_id($pdo, $pid);
    if ($holderId && !identity_hold($pdo, $holderId)) {
        add_patient_notice($pdo, $holderId, 'samename', 'good', '✓ Your record is checked',
            "Thank you for visiting the clinic. Your record is checked, and you can book online again.");
    }
}

/** After a merge: pairs with the archived record are closed, and the kept record is checked again. */
function identity_after_merge($pdo, $keepId, $dupId, $by) {
    try {
        $q = $pdo->prepare("SELECT id, patient_id FROM identity_checks WHERE status = 'open' AND (patient_id = ? OR match_id = ?)");
        $q->execute([(int)$dupId, (int)$dupId]);
        $rows = $q->fetchAll();
        if (!$rows) return;
        $pdo->prepare("UPDATE identity_checks SET status = 'same', checked_by = ?, checked_at = NOW()
                        WHERE status = 'open' AND (patient_id = ? OR match_id = ?)")
            ->execute([mb_substr($by . ' (merged)', 0, 120), (int)$dupId, (int)$dupId]);
        identity_check_patient($pdo, $keepId, false);
        $tell = [];                                           // the accounts that were asked to visit
        foreach ($rows as $r) $tell[(int)$r['patient_id'] === (int)$dupId ? (int)$keepId : (int)$r['patient_id']] = true;
        foreach (array_keys($tell) as $t) identity_tell_lifted($pdo, $t);
    } catch (Throwable $e) {}
}

/** Added to the staff's "saved" message when the record they saved has the same full name as another. */
function identity_staff_note($found) {
    return $found ? ' ⚠ Another patient has the same full name — please check it with the patient (Patients → 👥 Same full name).' : '';
}
