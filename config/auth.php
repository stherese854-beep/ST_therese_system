<?php
// ============================================================
//  AUTH HELPER  (config/auth.php)
// ============================================================
//  Include this at the TOP of any page that requires login.
//  It starts the session and gives small helper functions.
// ============================================================

// Old ".php" addresses (bookmarks, links in emails already sent) move to the
// clean ones: /login.php -> /login. GET only, so no form data is ever lost.
// Uses a RELATIVE redirect: behind Railway's proxy an absolute one built by
// Apache would point at http://...:8080. google_auth.php is left alone because
// Google must return to exactly the address registered with it.
if (php_sapi_name() !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $__path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    if (preg_match('~/([A-Za-z0-9_-]+)\.php$~', $__path, $__m) && $__m[1] !== 'google_auth') {
        $__qs = $_SERVER['QUERY_STRING'] ?? '';
        header('Location: ' . $__m[1] . ($__qs !== '' ? '?' . $__qs : ''), true, 301);
        exit;
    }
}

// Session cookie: not readable by JavaScript, not sent from other sites,
// and only over HTTPS when the site is served over HTTPS.
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                  || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
]);
ini_set('session.use_strict_mode', '1');   // refuse made-up session IDs
session_start();               // turn on PHP sessions (remembers who is logged in)

require_once __DIR__ . '/db.php';
ensure_archive_schema($pdo);                        // self-heals the archive columns/table
ensure_activity_log_schema($pdo);                   // self-heals the activity_log table
ensure_patient_archive_schema($pdo);                // self-heals the patients table's archive columns
require_once __DIR__ . '/../includes/assign.php';   // patient -> dentist auto-balancer

// ============================================================
//  CSRF PROTECTION  (forged form submissions)
// ============================================================
//  Every POST must carry this session's secret token. Another website
//  can make a logged-in user's browser submit a form to us, but it
//  cannot read the token, so its forged request is rejected here —
//  before any page code runs.
//
//  The token is added to every <form method="post"> automatically
//  (see csrf_inject_forms below), so pages need no changes. JavaScript
//  requests send it in an "X-CSRF-Token" header, read from the
//  <meta name="csrf-token"> tag in includes/head.php.
// ============================================================
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Adds the hidden token field right after each POST form's opening tag.
function csrf_inject_forms($html) {
    if (stripos($html, '<form') === false) return $html;
    $field = '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
    return preg_replace('/(<form\b[^>]*\bmethod\s*=\s*["\']?post\b[^>]*>)/i', '$1' . $field, $html);
}

if (php_sapi_name() !== 'cli') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($sent) || empty($_SESSION['csrf_token'])
            || !hash_equals($_SESSION['csrf_token'], $sent)) {
            http_response_code(403);
            echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Request blocked</title>
                  <meta name='viewport' content='width=device-width,initial-scale=1'></head>
                  <body style='font-family:system-ui,sans-serif;background:#f4f6f8;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;'>
                  <div style='background:#fff;padding:36px 40px;border-radius:14px;box-shadow:0 8px 28px rgba(0,0,0,.08);text-align:center;max-width:440px;'>
                    <div style='font-size:2.4rem;'>&#9888;&#65039;</div>
                    <h2 style='margin:.4em 0;'>Request blocked</h2>
                    <p style='color:#555;'>This form has expired or did not come from this site
                       (an uploaded file may also have been too large). Please go back, reload the page and try again.</p>
                    <a href='javascript:history.back()' style='display:inline-block;margin-top:10px;background:#0f766e;color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;'>Go back</a>
                  </div></body></html>";
            exit;
        }
    }
    csrf_token();                       // make sure one exists for this session
    ob_start('csrf_inject_forms');      // stamp the token into every POST form on the page
}

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
    // Private pages must never be cached, so pressing Back after logging out
    // cannot show a previous user's data.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if (!is_logged_in()) {
        header("Location: ./");          // not logged in -> go to the homepage
        exit;
    }
    if ($allowed_roles !== null && !in_array(current_role(), $allowed_roles, true)) {
        // Logged in but WRONG role (e.g. someone typed an admin page into the
        // address bar). Refuse it outright and record the attempt, instead of
        // quietly redirecting.
        deny_access('Tried to open ' . basename($_SERVER['SCRIPT_NAME'] ?? ''));
    }
}

// The logged-in user's own home page (patients live in the portal).
function home_page() {
    return current_role() === 'patient' ? 'portal' : 'dashboard';
}

// Stops the request with a 403 "Access denied" page and logs the attempt.
// Used whenever someone edits the URL to reach a page or record they are
// not allowed to see.
function deny_access($logDetails = '') {
    global $pdo;
    if ($logDetails !== '' && isset($pdo)) {
        log_activity($pdo, 'Access denied', $logDetails);
    }
    http_response_code(403);
    $home = e(home_page());
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Access denied</title>
          <meta name='viewport' content='width=device-width,initial-scale=1'></head>
          <body style='font-family:system-ui,sans-serif;background:#f4f6f8;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;'>
          <div style='background:#fff;padding:36px 40px;border-radius:14px;box-shadow:0 8px 28px rgba(0,0,0,.08);text-align:center;max-width:420px;'>
            <div style='font-size:2.4rem;'>&#128274;</div>
            <h2 style='margin:.4em 0;'>Access denied</h2>
            <p style='color:#555;'>You do not have permission to view this page or record.</p>
            <a href='$home' style='display:inline-block;margin-top:10px;background:#0f766e;color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;'>Back to my home page</a>
          </div></body></html>";
    exit;
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
//  PHONE NUMBER VALIDATION  (Philippine numbers)
// ============================================================
//  Accepts what people naturally type ("0917 123 4567", "+63 917-123-4567",
//  "639171234567") and turns it into the one stored format: 09171234567.
//  Rejects anything that can't be a real number and the usual fakes
//  (09999999999, 09123456789, 09170000000 ...).
//
//  $allowLandline: also accept landlines, e.g. (046) 123-4567 or
//  (02) 8123-4567 — only used for the clinic's own phone number.
//
//  Returns [clean number, error message]. The error is '' when valid.
//  An empty value is valid only when $required is false (returns ['', '']).
// ============================================================
function validate_phone($raw, $required = true, $allowLandline = false) {
    $raw = trim((string)$raw);
    $digits = preg_replace('/[\s\-().]/', '', $raw);          // drop spaces, dashes, brackets, dots

    if ($digits === '') {
        return ['', $required ? 'Please enter a contact number.' : ''];
    }
    if (!preg_match('/^\+?\d+$/', $digits)) {
        return ['', 'The contact number can only contain digits (spaces, dashes and +63 are fine).'];
    }
    $digits = ltrim($digits, '+');
    if (strpos($digits, '63') === 0 && strlen($digits) >= 11) {  // +63 917... / 63 2 8... -> 0917... / 028...
        $digits = '0' . substr($digits, 2);
    }

    $isMobile   = (bool)preg_match('/^09\d{9}$/', $digits);
    $isLandline = $allowLandline && (bool)preg_match('/^0[2-8]\d{8}$/', $digits);   // area code + local number
    if (!$isMobile && !$isLandline) {
        return ['', $allowLandline
            ? 'Please enter a real Philippine number, e.g. 0917 123 4567 or (046) 123-4567.'
            : 'Please enter a real Philippine mobile number: 11 digits starting with 09, e.g. 0917 123 4567.'];
    }

    // Obvious fakes: one digit repeated, a counting run, or a subscriber
    // part (last 7 digits) that is all the same digit.
    $body = substr($digits, 1);                                // without the leading 0
    $runs = '0123456789012345678';
    if (preg_match('/^(\d)\1+$/', substr($digits, 2))              // 09 + 999999999
        || preg_match('/^(\d)\1{6}$/', substr($digits, -7))        // ...0000000
        || strpos($runs, substr($body, 1)) !== false               // 9 + 123456789
        || strpos(strrev($runs), substr($body, 1)) !== false) {    // 9 + 876543210
        return ['', 'That does not look like a real contact number. Please enter your actual number.'];
    }

    return [$digits, ''];
}

// Attributes for a mobile-number <input>, so the browser also checks it
// before the form is sent (the server check above is the one that counts).
function phone_input_attrs() {
    return 'type="tel" inputmode="tel" maxlength="17" autocomplete="tel"'
         . ' pattern="\s*(\+?63|0)[\s\-]?9\d{2}[\s\-]?\d{3}[\s\-]?\d{4}\s*"'
         . ' title="Philippine mobile number, e.g. 0917 123 4567"';
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

    // Which ACCOUNT did it — so each person can see their own activity.
    // (Names can repeat or change; the account id cannot.)
    try {
        $has = $pdo->query("SHOW COLUMNS FROM activity_log LIKE 'actor_user_id'")->rowCount();
        if (!$has) {
            $pdo->exec("ALTER TABLE activity_log ADD COLUMN actor_user_id INT DEFAULT NULL AFTER id");
            $pdo->exec("ALTER TABLE activity_log ADD INDEX idx_actor_user (actor_user_id, created_at)");
        }
    } catch (Throwable $e) { /* ignore */ }
}

// Records one activity-log entry. $action is a short label (e.g. "Logged in",
// "Archived user"); $details is the human-readable specifics (e.g. the name
// of the account/appointment affected). Safe to call from anywhere — never
// throws, so a logging hiccup can't break the page that called it.
function log_activity($pdo, $action, $details = '') {
    try {
        $pdo->prepare("INSERT INTO activity_log (actor_user_id, actor_name, actor_role, action, details) VALUES (?,?,?,?,?)")
            ->execute([
                isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
                $_SESSION['name'] ?? 'System',
                $_SESSION['role'] ?? null,
                $action,
                $details,
            ]);
    } catch (Throwable $e) { /* logging never blocks the real action */ }
}

// ============================================================
//  "MY ACTIVITY" — the logged-in person's own entries only
// ============================================================
//  Matches on the account id. Entries written before the id was
//  recorded fall back to the same name AND role.
//  $q searches the action/details, $date is an exact day (Y-m-d).
// ============================================================
function my_activity_where(&$params) {
    $params[] = (int)($_SESSION['user_id'] ?? 0);
    $params[] = $_SESSION['name'] ?? '';
    $params[] = $_SESSION['role'] ?? '';
    return "(actor_user_id = ? OR (actor_user_id IS NULL AND actor_name = ? AND actor_role = ?))";
}
function my_activity_rows($pdo, $q = '', $date = '', $limit = 100) {
    $params = [];
    $sql = "SELECT * FROM activity_log WHERE " . my_activity_where($params);
    if ($q !== '')    { $sql .= " AND (action LIKE ? OR details LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
    if ($date !== '') { $sql .= " AND DATE(created_at) = ?"; $params[] = $date; }
    $sql .= " ORDER BY created_at DESC, id DESC LIMIT " . (int)$limit;
    $st = $pdo->prepare($sql); $st->execute($params);
    return $st->fetchAll();
}
function my_activity_count($pdo) {
    $params = [];
    $st = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE " . my_activity_where($params));
    $st->execute($params);
    return (int)$st->fetchColumn();
}

// Badge colour for an activity entry (shared by the admin log and My Activity).
function activity_badge($action) {
    $a = strtolower($action);
    if (strpos($a, 'delete') !== false || strpos($a, 'cancel') !== false || strpos($a, 'no-show') !== false) return 'b-cancelled';
    if (strpos($a, 'archiv') !== false)  return 'b-archived';
    if (strpos($a, 'restor') !== false || strpos($a, 'confirm') !== false || strpos($a, 'approv') !== false
        || strpos($a, 'created') !== false || strpos($a, 'added') !== false || strpos($a, 'booked') !== false) return 'b-confirmed';
    if (strpos($a, 'logged') !== false)  return 'b-progress';
    return 'b-pending';
}

// A patient's name for log details ("Patient #12" if it can't be found).
function patient_name_of($pdo, $pid) {
    $st = $pdo->prepare("SELECT name FROM patients WHERE id = ?");
    $st->execute([(int)$pid]);
    return $st->fetchColumn() ?: ('Patient #' . (int)$pid);
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
