<?php
// ============================================================
//  OWN ACCOUNT / MERGE / LOGIN-EMAIL CHANGE  (includes/account_transfer.php)
// ============================================================
//  1. "Give them their own account"
//     A person booked under someone else's account ("People I Book For")
//     gets an emailed invite link (48 hours). When they open it and set a
//     password, THEIR EXISTING patient record becomes their own account.
//     Nothing is copied: appointments, dental chart, treatment records,
//     notes, X-rays and the health form already belong to that record.
//     The person who booked for them no longer sees or books for them.
//     Only adults (MIN_ACCOUNT_AGE+) — a child stays under the parent.
//
//  2. Merge two records of the same person (admin)
//     e.g. the relative had already signed up on their own. Everything
//     moves onto the record that is kept; the other goes to the Archive.
//
//  3. Changing a patient's LOGIN email (admin only)
//     The new address must be confirmed from a link sent to it before it
//     takes effect, and the old address is told about it. A person with
//     no login (booked by someone else) is just a contact address — the
//     clinic may edit that freely; it never gives access to anything.
// ============================================================

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/reminders.php';   // site_base_url()

define('MIN_ACCOUNT_AGE', 18);
define('INVITE_HOURS', 48);

// Every table whose rows belong to a patient record.
const PATIENT_LINKED_TABLES = ['appointments', 'chart_sessions', 'clinical_notes', 'odontogram',
                               'treatments', 'xrays', 'reviews', 'patient_notices'];

function ensure_account_transfer_tables($pdo) {
    static $done = false;
    if ($done || (defined('SCHEMA_CHECKED') && SCHEMA_CHECKED)) return;   // already checked after this update
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS account_invites (
            id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INT NOT NULL,
            guardian_patient_id INT DEFAULT NULL,
            email VARCHAR(100) NOT NULL,
            token CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            invited_by VARCHAR(100) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            accepted_at DATETIME DEFAULT NULL,
            cancelled_at DATETIME DEFAULT NULL,
            UNIQUE KEY uq_token (token), INDEX idx_patient (patient_id)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS email_changes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            patient_id INT DEFAULT NULL,
            old_email VARCHAR(100) NOT NULL,
            new_email VARCHAR(100) NOT NULL,
            token CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            requested_by VARCHAR(100) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            confirmed_at DATETIME DEFAULT NULL,
            cancelled_at DATETIME DEFAULT NULL,
            UNIQUE KEY uq_token (token), INDEX idx_user (user_id)
        )");
    } catch (Throwable $e) {}
}

function account_age($row) {
    if (!empty($row['date_of_birth'])) return (int)(new DateTime($row['date_of_birth']))->diff(new DateTime())->y;
    return ($row['age'] ?? '') !== '' && $row['age'] !== null ? (int)$row['age'] : null;
}

function email_in_use($pdo, $email, $exceptUserId = 0) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(?) AND id <> ?");
    $q->execute([trim($email), (int)$exceptUserId]);
    return (int)$q->fetchColumn() > 0;
}

// ---------------------------------------------------------------
//  1. Own-account invite
// ---------------------------------------------------------------

// The open (unused, unexpired) invite for a booked-for person, if any.
function pending_account_invite($pdo, $patientId) {
    ensure_account_transfer_tables($pdo);
    $q = $pdo->prepare("SELECT * FROM account_invites WHERE patient_id = ? AND accepted_at IS NULL
                          AND cancelled_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $q->execute([(int)$patientId]);
    return $q->fetch() ?: null;
}

// Why this person cannot get their own account yet ('' = they can).
function own_account_problem($pdo, $person) {
    if (!$person) return 'That person could not be found.';
    if (!empty($person['user_id']) || empty($person['guardian_patient_id'])) return 'This person already has their own account.';
    $age = account_age($person);
    if ($age === null) return 'Their birthday is not on file. Please ask the clinic to add it first.';
    if ($age < MIN_ACCOUNT_AGE) return 'Only people aged ' . MIN_ACCOUNT_AGE . ' or older can have their own account. '
                                     . 'A child stays under the parent or guardian who books for them.';
    return '';
}

/** Sends the invite. Returns [ok, message]. */
function send_account_invite($pdo, $patientId, $email, $invitedBy) {
    ensure_account_transfer_tables($pdo);
    $email = trim($email);
    $q = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
    $q->execute([(int)$patientId]);
    $person = $q->fetch();
    if (($p = own_account_problem($pdo, $person)) !== '') return [false, $p];
    if (($ep = email_problem($email)) !== '') return [false, $ep];
    if (email_in_use($pdo, $email)) {
        return [false, 'That email already has an account. If it is the same person, the clinic can merge the two records '
                     . '(Patients → Merge records).'];
    }
    if (!mail_is_ready($pdo)) return [false, 'Email sending is turned off, so the invite cannot be sent. Please contact the clinic.'];

    // Only one open invite at a time.
    $pdo->prepare("UPDATE account_invites SET cancelled_at = NOW() WHERE patient_id = ? AND accepted_at IS NULL AND cancelled_at IS NULL")
        ->execute([(int)$patientId]);
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO account_invites (patient_id, guardian_patient_id, email, token, expires_at, invited_by)
                   VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL " . INVITE_HOURS . " HOUR), ?)")
        ->execute([(int)$patientId, (int)$person['guardian_patient_id'], $email, $token, $invitedBy]);

    $g = $pdo->prepare("SELECT name FROM patients WHERE id = ?");
    $g->execute([(int)$person['guardian_patient_id']]);
    $cat = message_catalogue()['account_invite'];
    [$subj, $body] = tpl_message($pdo, 'account_invite', $cat['subject'], $cat['body'], [
        'patient' => $person['name'],
        'by'      => $g->fetchColumn() ?: 'the person who booked for you',
        'link'    => site_base_url($pdo) . '/claim_account?t=' . $token,
        'hours'   => INVITE_HOURS,
        'clinic'  => clinic_name($pdo),
    ]);
    $err = '';
    if (!send_mail($pdo, $email, $subj, $body, $err, 'account_invite')) {
        $pdo->prepare("UPDATE account_invites SET cancelled_at = NOW() WHERE token = ?")->execute([$token]);
        return [false, 'The invite email could not be sent' . ($err ? " ($err)" : '') . '.'];
    }
    log_activity($pdo, 'Sent own-account invite', $person['name'] . ' → ' . $email);
    return [true, 'An invite was emailed to ' . $email . '. The link works for ' . INVITE_HOURS . ' hours.'];
}

function cancel_account_invite($pdo, $patientId) {
    ensure_account_transfer_tables($pdo);
    $pdo->prepare("UPDATE account_invites SET cancelled_at = NOW() WHERE patient_id = ? AND accepted_at IS NULL AND cancelled_at IS NULL")
        ->execute([(int)$patientId]);
}

// A usable invite for this token, joined with the person — or null.
function find_account_invite($pdo, $token) {
    ensure_account_transfer_tables($pdo);
    if (!preg_match('/^[a-f0-9]{64}$/', (string)$token)) return null;
    $q = $pdo->prepare("SELECT i.*, p.name, p.user_id, p.guardian_patient_id AS cur_guardian, p.date_of_birth, p.age, p.status AS pstatus
                          FROM account_invites i JOIN patients p ON p.id = i.patient_id
                         WHERE i.token = ? AND i.accepted_at IS NULL AND i.cancelled_at IS NULL AND i.expires_at > NOW()");
    $q->execute([$token]);
    $inv = $q->fetch();
    if (!$inv || !empty($inv['user_id']) || empty($inv['cur_guardian']) || $inv['pstatus'] === 'Archived') return null;
    return $inv;
}

/** Turns the booked-for record into the person's own account. Returns [userId, error]. */
function accept_account_invite($pdo, $inv, $password, $phone) {
    if (email_in_use($pdo, $inv['email'])) return [0, 'That email already has an account. Please contact the clinic.'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO users (name, email, password, role, status, contact, email_verified, created_at)
                       VALUES (?, ?, ?, 'patient', 'active', ?, 1, NOW())")
            ->execute([$inv['name'], $inv['email'], password_hash($password, PASSWORD_DEFAULT), $phone]);
        $uid = (int)$pdo->lastInsertId();
        // Same record, new owner: every appointment, chart, note and X-ray stays attached.
        $pdo->prepare("UPDATE patients SET user_id = ?, guardian_patient_id = NULL, relationship = NULL, email = ?,
                              phone = COALESCE(NULLIF(?, ''), phone) WHERE id = ?")
            ->execute([$uid, $inv['email'], $phone, (int)$inv['patient_id']]);
        $pdo->prepare("UPDATE account_invites SET accepted_at = NOW() WHERE id = ?")->execute([(int)$inv['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [0, 'Something went wrong while creating the account. Please try again.'];
    }
    // Tell the person who used to book for them (pop-up in their portal).
    if (!function_exists('add_patient_notice')) require_once __DIR__ . '/patient_notices.php';
    add_patient_notice($pdo, (int)$inv['guardian_patient_id'], 'own_account', 'good',
        $inv['name'] . ' now has their own account',
        $inv['name'] . " accepted the invite and now signs in with their own account. Their appointments and dental "
        . "records moved with them, so they no longer appear under People I Book For.");
    log_activity($pdo, 'Booked-for person got their own account', $inv['name'] . ' (' . $inv['email'] . ')');
    return [$uid, ''];
}

// ---------------------------------------------------------------
//  2. Merge two records of the same person (admin)
// ---------------------------------------------------------------
/** Moves everything from $dupId onto $keepId and archives $dupId. Returns [ok, message]. */
function merge_patient_records($pdo, $keepId, $dupId, $by) {
    $keepId = (int)$keepId; $dupId = (int)$dupId;
    if ($keepId <= 0 || $dupId <= 0 || $keepId === $dupId) return [false, 'Please choose two different records.'];
    $q = $pdo->prepare("SELECT * FROM patients WHERE id IN (?, ?)");
    $q->execute([$keepId, $dupId]);
    $rows = [];
    foreach ($q->fetchAll() as $r) $rows[(int)$r['id']] = $r;
    if (count($rows) < 2) return [false, 'One of the records could not be found.'];
    $keep = $rows[$keepId]; $dup = $rows[$dupId];
    if (!empty($keep['user_id']) && !empty($dup['user_id'])) {
        return [false, 'Both records have their own login. Archive one of the accounts first (User Management), then merge.'];
    }
    $pdo->beginTransaction();
    try {
        foreach (PATIENT_LINKED_TABLES as $t) {
            try { $pdo->prepare("UPDATE `$t` SET patient_id = ? WHERE patient_id = ?")->execute([$keepId, $dupId]); }
            catch (Throwable $e) { /* table not created on this install */ }
        }
        // People that the duplicate booked for now belong to the kept record.
        $pdo->prepare("UPDATE patients SET guardian_patient_id = ? WHERE guardian_patient_id = ? AND id <> ?")
            ->execute([$keepId, $dupId, $keepId]);
        // The login moves to the kept record if only the duplicate had one.
        if (empty($keep['user_id']) && !empty($dup['user_id'])) {
            $pdo->prepare("UPDATE patients SET user_id = NULL WHERE id = ?")->execute([$dupId]);
            $pdo->prepare("UPDATE patients SET user_id = ?, guardian_patient_id = NULL, relationship = NULL WHERE id = ?")
                ->execute([(int)$dup['user_id'], $keepId]);
        }
        // Fill blanks on the kept record from the duplicate.
        foreach (['email','phone','date_of_birth','age','blood_type','address','medical_history','medical_alert','health_form','health_form_at'] as $f) {
            if ((string)($keep[$f] ?? '') === '' && (string)($dup[$f] ?? '') !== '') {
                $pdo->prepare("UPDATE patients SET `$f` = ? WHERE id = ?")->execute([$dup[$f], $keepId]);
            }
        }
        $pdo->prepare("UPDATE patients SET pre_archive_status = status, status = 'Archived', archived_at = NOW(), archived_by = ?,
                              guardian_patient_id = NULL WHERE id = ?")
            ->execute([$by . ' (merged into #' . $keepId . ')', $dupId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, 'The merge could not be completed. Nothing was changed.'];
    }
    log_activity($pdo, 'Merged patient records', $dup['name'] . " (#$dupId) → " . $keep['name'] . " (#$keepId)");
    return [true, $dup['name'] . "'s records were moved onto " . $keep['name'] . " (#$keepId). The duplicate is in the Archive."];
}

// ---------------------------------------------------------------
//  3. Login-email change (admin)
// ---------------------------------------------------------------
function pending_email_change($pdo, $userId) {
    ensure_account_transfer_tables($pdo);
    $q = $pdo->prepare("SELECT * FROM email_changes WHERE user_id = ? AND confirmed_at IS NULL AND cancelled_at IS NULL
                          AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $q->execute([(int)$userId]);
    return $q->fetch() ?: null;
}

/** Starts a change: link to the new address, notice to the old one. Returns [ok, message]. */
function request_email_change($pdo, $userId, $patientId, $newEmail, $by) {
    ensure_account_transfer_tables($pdo);
    $newEmail = trim($newEmail);
    $u = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ?");
    $u->execute([(int)$userId]);
    $user = $u->fetch();
    if (!$user) return [false, 'That account could not be found.'];
    if (strcasecmp($user['email'], $newEmail) === 0) return [true, ''];
    if (($ep = email_problem($newEmail)) !== '') return [false, $ep];
    if (email_in_use($pdo, $newEmail, $userId)) return [false, 'That email is already used by another account.'];
    if (!mail_is_ready($pdo)) return [false, 'Email sending is turned off, so the new address cannot be confirmed. The login email was not changed.'];

    $pdo->prepare("UPDATE email_changes SET cancelled_at = NOW() WHERE user_id = ? AND confirmed_at IS NULL AND cancelled_at IS NULL")
        ->execute([(int)$userId]);
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO email_changes (user_id, patient_id, old_email, new_email, token, expires_at, requested_by)
                   VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL " . INVITE_HOURS . " HOUR), ?)")
        ->execute([(int)$userId, $patientId ? (int)$patientId : null, $user['email'], $newEmail, $token, $by]);

    $vars = ['patient' => $user['name'], 'old' => $user['email'], 'new' => $newEmail, 'hours' => INVITE_HOURS,
             'link' => site_base_url($pdo) . '/email_confirm?t=' . $token, 'clinic' => clinic_name($pdo)];
    $cat = message_catalogue()['email_change_confirm'];
    [$s, $b] = tpl_message($pdo, 'email_change_confirm', $cat['subject'], $cat['body'], $vars);
    $err = '';
    if (!send_mail($pdo, $newEmail, $s, $b, $err, 'email_change_confirm')) {
        $pdo->prepare("UPDATE email_changes SET cancelled_at = NOW() WHERE token = ?")->execute([$token]);
        return [false, 'The confirmation email could not be sent' . ($err ? " ($err)" : '') . '. The login email was not changed.'];
    }
    if ($user['email'] !== '') {
        $cat = message_catalogue()['email_change_notice'];
        [$s, $b] = tpl_message($pdo, 'email_change_notice', $cat['subject'], $cat['body'], $vars);
        send_mail($pdo, $user['email'], $s, $b, $err, 'email_change_notice');
    }
    log_activity($pdo, 'Requested login email change', $user['name'] . ': ' . $user['email'] . ' → ' . $newEmail . ' (waiting for confirmation)');
    return [true, 'A confirmation link was sent to ' . $newEmail . '. The login email changes once they open it (within '
                . INVITE_HOURS . ' hours). The old address was told about the change.'];
}

/** Applies a confirmed change. Returns [ok, message]. */
function confirm_email_change($pdo, $token) {
    ensure_account_transfer_tables($pdo);
    if (!preg_match('/^[a-f0-9]{64}$/', (string)$token)) return [false, 'This link is not valid.'];
    $q = $pdo->prepare("SELECT * FROM email_changes WHERE token = ? AND confirmed_at IS NULL AND cancelled_at IS NULL AND expires_at > NOW()");
    $q->execute([$token]);
    $c = $q->fetch();
    if (!$c) return [false, 'This link has expired or was already used. Please ask the clinic to send a new one.'];
    if (email_in_use($pdo, $c['new_email'], $c['user_id'])) return [false, 'That email is now used by another account. Please contact the clinic.'];
    $pdo->prepare("UPDATE users SET email = ?, email_verified = 1 WHERE id = ?")->execute([$c['new_email'], (int)$c['user_id']]);
    $pdo->prepare("UPDATE patients SET email = ? WHERE user_id = ?")->execute([$c['new_email'], (int)$c['user_id']]);
    $pdo->prepare("UPDATE email_changes SET confirmed_at = NOW() WHERE id = ?")->execute([(int)$c['id']]);
    log_activity($pdo, 'Login email changed', $c['old_email'] . ' → ' . $c['new_email'] . ' (confirmed from the new address)');
    return [true, 'Your login email is now ' . $c['new_email'] . '. Use it the next time you sign in.'];
}
