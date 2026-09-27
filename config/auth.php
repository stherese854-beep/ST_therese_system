<?php
// ============================================================
//  AUTH HELPER  (config/auth.php)
// ============================================================
//  Include this at the TOP of any page that requires login.
//  It starts the session and gives small helper functions.
// ============================================================

session_start();               // turn on PHP sessions (remembers who is logged in)

require_once __DIR__ . '/db.php';
ensure_archive_schema($pdo);                        // self-heals the archive columns/table
ensure_activity_log_schema($pdo);                   // self-heals the activity_log table
ensure_patient_archive_schema($pdo);                // self-heals the patients table's archive columns
require_once __DIR__ . '/../includes/assign.php';   // patient -> dentist auto-balancer

// Is someone logged in right now?
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// Get the logged-in user's role (admin / dentist / staff / patient).
function current_role() {
    return $_SESSION['role'] ?? null;
}

// Block access to a page unless the user is logged in.
// $allowed_roles is an optional list, e.g. ['admin'] or ['dentist','staff'].
function require_login($allowed_roles = null) {
    if (!is_logged_in()) {
        header("Location: index.php");   // not logged in -> go to login page
        exit;
    }
    if ($allowed_roles !== null && !in_array(current_role(), $allowed_roles)) {
        // Logged in but WRONG role -> send them to THEIR OWN home page.
        // (Patients live in the portal; everyone else uses the dashboard.)
        // Sending them to their own page avoids an endless redirect loop.
        if (current_role() === 'patient') {
            header("Location: portal.php");
        } else {
            header("Location: dashboard.php");
        }
        exit;
    }
}

// Small helper to safely print text (prevents broken HTML / XSS).
function e($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

// ---- Flash messages (for the pop-up "toast" notifications) ----
// set_flash() stores a message; the next page shows it once, then it's cleared.
function set_flash($msg, $type = 'success') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}
function take_flash() {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);   // show it only once
        return $f;
    }
    return null;
}

// ============================================================
//  PASSWORD STRENGTH  (server-side — the JS meter mirrors this)
// ============================================================
//  Returns 'weak', 'medium' or 'strong'. Only medium/strong are
//  allowed to create an account — this is the check that actually
//  enforces it (JS can be bypassed, this cannot).
// ============================================================
function password_strength($pass) {
    $len = strlen($pass);
    $score = 0;
    if ($len >= 8)  $score++;
    if ($len >= 12) $score++;
    if (preg_match('/[a-z]/', $pass) && preg_match('/[A-Z]/', $pass)) $score++;
    if (preg_match('/\d/', $pass)) $score++;
    if (preg_match('/[^A-Za-z0-9]/', $pass)) $score++;

    if ($len < 8 || $score <= 2) return 'weak';
    if ($score === 3) return 'medium';
    return 'strong';
}

// ============================================================
//  ACCOUNT EMAIL VALIDATION
// ============================================================
//  Checks that an email address is genuinely usable before an
//  account is created with it:
//    1. correct format
//    2. the domain actually has a mail server (catches "gmial.com")
//    3. not already taken by another account
//  Returns an error message, or '' when the address is fine.
// ============================================================
function validate_account_email($pdo, $email, $ignoreUserId = null) {
    $email = trim($email);

    if ($email === '') {
        return 'Please enter an email address.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'That email address is not valid. Please check the spelling.';
    }

    // Does the domain accept mail at all? This catches believable typos.
    $domain = substr(strrchr($email, '@'), 1);
    if ($domain && function_exists('checkdnsrr')
        && !checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A')) {
        return "No mail server found for \"$domain\". Please check the email address.";
    }

    // Already used by someone else?
    if ($ignoreUserId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?");
        $stmt->execute([$email, $ignoreUserId]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$email]);
    }
    if ((int)$stmt->fetchColumn() > 0) {
        return 'That email is already in use by another account.';
    }

    return '';   // all good
}

// ============================================================
//  ARCHIVE SYSTEM — self-healing schema
// ============================================================
//  Adds the columns/table the archive feature needs, the first time
//  it's missing. Safe to call on every page load — it only touches
//  the database once, on an existing install that predates this
//  feature. New installs get these straight from database.sql.
// ============================================================
function ensure_archive_schema($pdo) {
    try {
        $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'archived_at'")->rowCount();
        if (!$has) {
            $pdo->exec("ALTER TABLE users MODIFY status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active'");
            $pdo->exec("ALTER TABLE users ADD COLUMN archived_at DATETIME DEFAULT NULL");
            $pdo->exec("ALTER TABLE users ADD COLUMN archived_by VARCHAR(100) DEFAULT NULL");
            $pdo->exec("ALTER TABLE users ADD COLUMN pre_archive_status VARCHAR(20) DEFAULT NULL");
        }
    } catch (Throwable $e) { /* older MySQL / already applied — ignore */ }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS deleted_accounts_log (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(100) NOT NULL,
            email      VARCHAR(100) NOT NULL,
            role       VARCHAR(20)  DEFAULT NULL,
            deleted_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
            deleted_by VARCHAR(100) DEFAULT NULL
        )");
    } catch (Throwable $e) { /* ignore */ }
}

// ============================================================
//  ACTIVITY LOG — self-healing schema + logger
// ============================================================
//  A running record of who did what, for the admin's Activity Log
//  page. Every row is one event: who (name + role), what happened,
//  and when. It never blocks the page it's called from — if logging
//  fails for any reason, the real action still goes through.
// ============================================================
function ensure_activity_log_schema($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            actor_name   VARCHAR(100) DEFAULT NULL,
            actor_role   VARCHAR(20)  DEFAULT NULL,
            action       VARCHAR(60)  NOT NULL,
            details      VARCHAR(255) DEFAULT NULL,
            created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
            INDEX (created_at)
        )");
    } catch (Throwable $e) { /* ignore */ }
}

// Records one activity-log entry. $action is a short label (e.g. "Logged in",
// "Archived user"); $details is the human-readable specifics (e.g. the name
// of the account/appointment affected). Safe to call from anywhere — never
// throws, so a logging hiccup can't break the page that called it.
function log_activity($pdo, $action, $details = '') {
    try {
        $pdo->prepare("INSERT INTO activity_log (actor_name, actor_role, action, details) VALUES (?,?,?,?)")
            ->execute([
                $_SESSION['name'] ?? 'System',
                $_SESSION['role'] ?? null,
                $action,
                $details,
            ]);
    } catch (Throwable $e) { /* logging never blocks the real action */ }
}

// ============================================================
//  ARCHIVING AN ACCOUNT (soft delete)
// ============================================================
//  Moves the account to the Archive instead of deleting it right
//  away. Nothing is removed — the row (and any patient records
//  attached to it) is simply hidden until an admin restores it or
//  permanently deletes it from the Archive page.
// ============================================================
function archive_user($pdo, $userId, $archivedByName) {
    $userId = (int)$userId;
    if ($userId <= 0) return;
    $info = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $info->execute([$userId]);
    $name = $info->fetchColumn();

    $pdo->prepare("UPDATE users
                    SET pre_archive_status = status, status = 'archived',
                        archived_at = NOW(), archived_by = ?
                    WHERE id = ?")
        ->execute([$archivedByName, $userId]);

    log_activity($pdo, 'Archived user', $name ?: "user #$userId");
}

// Restores an archived account back to the status it had before archiving.
function restore_user($pdo, $userId) {
    $userId = (int)$userId;
    if ($userId <= 0) return;
    $info = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $info->execute([$userId]);
    $name = $info->fetchColumn();

    $pdo->prepare("UPDATE users
                    SET status = COALESCE(NULLIF(pre_archive_status,''), 'active'),
                        archived_at = NULL, archived_by = NULL, pre_archive_status = NULL
                    WHERE id = ?")
        ->execute([$userId]);

    log_activity($pdo, 'Restored user', $name ?: "user #$userId");
}

// ============================================================
//  ARCHIVING A PATIENT (soft delete)
// ============================================================
//  Same idea as archive_user(), but for the "🗑 Delete" button on the
//  Patients page: the patient record moves to the Archive instead of
//  being removed right away. This also covers walk-in patients who
//  have no login account of their own (archive_user() alone can't
//  touch those, since there is no `users` row to mark). If the
//  patient DOES have a login account, that account is archived too
//  so they can't sign in while archived.
// ============================================================
function ensure_patient_archive_schema($pdo) {
    try {
        $has = $pdo->query("SHOW COLUMNS FROM patients LIKE 'archived_at'")->rowCount();
        if (!$has) {
            $pdo->exec("ALTER TABLE patients MODIFY status ENUM('Active','Inactive','Archived') NOT NULL DEFAULT 'Active'");
            $pdo->exec("ALTER TABLE patients ADD COLUMN archived_at DATETIME DEFAULT NULL");
            $pdo->exec("ALTER TABLE patients ADD COLUMN archived_by VARCHAR(100) DEFAULT NULL");
            $pdo->exec("ALTER TABLE patients ADD COLUMN pre_archive_status VARCHAR(20) DEFAULT NULL");
        }
    } catch (Throwable $e) { /* older MySQL / already applied — ignore */ }
}

function archive_patient($pdo, $patientId, $archivedByName) {
    $patientId = (int)$patientId;
    if ($patientId <= 0) return;

    $info = $pdo->prepare("SELECT name, user_id, status FROM patients WHERE id = ?");
    $info->execute([$patientId]);
    $p = $info->fetch();
    if (!$p) return;

    $pdo->prepare("UPDATE patients
                    SET pre_archive_status = status, status = 'Archived',
                        archived_at = NOW(), archived_by = ?
                    WHERE id = ?")
        ->execute([$archivedByName, $patientId]);

    // Also archive their login account, if they have one, so they can't
    // sign in or book online while archived.
    if (!empty($p['user_id'])) {
        archive_user($pdo, $p['user_id'], $archivedByName);
    }

    log_activity($pdo, 'Archived patient', $p['name'] ?: "patient #$patientId");
}

// Restores an archived patient (and their login account, if any) back to
// how they were before archiving.
function restore_patient($pdo, $patientId) {
    $patientId = (int)$patientId;
    if ($patientId <= 0) return;

    $info = $pdo->prepare("SELECT name, user_id FROM patients WHERE id = ?");
    $info->execute([$patientId]);
    $p = $info->fetch();
    if (!$p) return;

    $pdo->prepare("UPDATE patients
                    SET status = COALESCE(NULLIF(pre_archive_status,''), 'Active'),
                        archived_at = NULL, archived_by = NULL, pre_archive_status = NULL
                    WHERE id = ?")
        ->execute([$patientId]);

    if (!empty($p['user_id'])) {
        restore_user($pdo, $p['user_id']);
    }

    log_activity($pdo, 'Restored patient', $p['name'] ?: "patient #$patientId");
}

// ============================================================
//  DELETING AN ACCOUNT COMPLETELY
// ============================================================
//  Removing a row from `users` is not enough. If that account is a
//  patient, their patient record and everything attached to it must
//  go too — otherwise the patient keeps showing up in the patient
//  list, odontogram and records even though the account is gone.
//
//  Order matters: children first, then the patient row, then the user.
// ============================================================
function delete_user_completely($pdo, $userId, $deletedByName = null) {
    $userId = (int)$userId;
    if ($userId <= 0) return;

    // Record the account in the permanent-deletion log BEFORE removing it,
    // so the login page can still recognize the email and explain what
    // happened (rather than just saying "wrong email or password").
    $info = $pdo->prepare("SELECT name, email, role FROM users WHERE id = ?");
    $info->execute([$userId]);
    if ($row = $info->fetch()) {
        $pdo->prepare("INSERT INTO deleted_accounts_log (name, email, role, deleted_by) VALUES (?,?,?,?)")
            ->execute([$row['name'], $row['email'], $row['role'], $deletedByName]);
        log_activity($pdo, 'Permanently deleted user', $row['name'] . ' (' . $row['role'] . ')');
    }

    // Find any patient record linked to this account.
    $stmt = $pdo->prepare("SELECT id FROM patients WHERE user_id = ?");
    $stmt->execute([$userId]);
    $patientIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($patientIds as $pid) {
        delete_patient_records($pdo, (int)$pid);
    }

    // Reviews are linked to the user account as well.
    try { $pdo->prepare("DELETE FROM reviews WHERE user_id = ?")->execute([$userId]); }
    catch (Throwable $e) { /* table may not exist on older databases */ }

    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
}

// Deletes a patient row and EVERY record that hangs off it.
// Used by both "delete patient" and "delete user".
function delete_patient_records($pdo, $patientId) {
    $patientId = (int)$patientId;
    if ($patientId <= 0) return;

    // Uploaded x-ray images are files on disk, so remove those too.
    try {
        $xr = $pdo->prepare("SELECT image_file FROM xrays WHERE patient_id = ?");
        $xr->execute([$patientId]);
        foreach ($xr->fetchAll(PDO::FETCH_COLUMN) as $img) {
            $path = __DIR__ . '/../' . $img;
            if ($img && is_file($path)) @unlink($path);
        }
    } catch (Throwable $e) { /* ignore */ }

    // Child tables first. Each is wrapped so an older database that is
    // missing a table cannot stop the rest of the clean-up.
    foreach (['treatments','xrays','clinical_notes','odontogram',
              'chart_sessions','appointments','reviews'] as $table) {
        try { $pdo->prepare("DELETE FROM $table WHERE patient_id = ?")->execute([$patientId]); }
        catch (Throwable $e) { /* table not present — skip */ }
    }

    $pdo->prepare("DELETE FROM patients WHERE id = ?")->execute([$patientId]);
}

// ============================================================
//  SAVING A SETTING
// ============================================================
//  Settings are stored as key/value rows. This writes one, creating
//  it if it does not exist yet. It lives here because several pages
//  need it — the landing editor, message templates, messaging config
//  and system settings.
// ============================================================
function save_setting($pdo, $key, $value) {
    $pdo->prepare(
        "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = ?"
    )->execute([$key, $value, $value]);
}
