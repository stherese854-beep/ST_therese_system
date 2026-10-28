<?php
// ============================================================
//  SIGN-IN SECURITY, UPLOAD CHECKS, RECORD AUDIT  (includes/security.php)
// ============================================================
//  1. Account lockout — 5 wrong passwords in a row lock the ACCOUNT for
//     15 minutes (users.failed_attempts / users.locked_until). A correct
//     sign-in, a password reset or the admin's "Unlock" sets it back to 0.
//     (config/auth.php also limits failed sign-ins per connection.)
//  2. Two-step sign-in — when the admin turns it on (System → Security &
//     Access), the roles the admin ticks (admins, dentists, staff, patients)
//     get a 6-digit code by email after their password and are signed in
//     only after typing it. The admin can also let a device be remembered
//     for a number of days (table trusted_devices + a cookie per account):
//     on that browser the code is not asked again until the days are up,
//     the password changes, or the admin forgets all devices.
//  3. Image uploads — JPG / PNG only, checked by their content (finfo +
//     getimagesize, not just the name), at most 5 MB, saved under a random
//     file name.
//  4. Record audit — opening a patient's record is written to the Activity
//     Log ("Viewed patient record"), and log entries made on a patient's
//     page carry that patient's ID, so the admin can see a patient's whole
//     record history (Activity Log → ?patient=ID).
// ============================================================

const LOGIN_MAX_TRIES      = 5;
const LOGIN_LOCK_MINUTES   = 15;
const LOGIN_LOCKED_MESSAGE = 'Your account is locked. Try again after 15 minutes.';

const TWO_STEP_ROLE_NAMES    = ['admin' => 'Admins', 'dentist' => 'Dentists', 'staff' => 'Staff', 'patient' => 'Patients'];
const TWO_STEP_ROLES_DEFAULT = 'admin,dentist';   // before the admin picks roles (how it always worked)
const TWO_STEP_MINUTES = 10;     // a code works for 10 minutes
const TWO_STEP_TRIES   = 5;      // wrong codes before signing in again
const TWO_STEP_MAX_DAYS = 90;    // "remember this device" can be at most 90 days (0 = ask every sign-in)
const TWO_STEP_COOKIE   = 'st_trust_';   // + user ID: one cookie per account, so a shared computer can remember several

const UPLOAD_MAX_MB    = 5;

function ensure_login_security_schema($pdo) {
    try {
        if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'failed_attempts'")->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN failed_attempts INT NOT NULL DEFAULT 0,
                                          ADD COLUMN locked_until DATETIME NULL DEFAULT NULL");
        }
    } catch (Throwable $e) {}
    try {
        if (!$pdo->query("SHOW COLUMNS FROM activity_log LIKE 'patient_id'")->fetch()) {
            $pdo->exec("ALTER TABLE activity_log ADD COLUMN patient_id INT NULL DEFAULT NULL,
                                                 ADD INDEX idx_log_patient (patient_id, created_at)");
        }
    } catch (Throwable $e) {}
    try {
        // Devices where the two-step code was typed and "remember" was ticked.
        // Only a hash of the cookie's secret is kept; pw_tag ties it to the
        // password at that time, so changing the password forgets the device.
        $pdo->exec("CREATE TABLE IF NOT EXISTS trusted_devices (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        selector CHAR(16) NOT NULL,
                        token_hash CHAR(64) NOT NULL,
                        pw_tag CHAR(16) NOT NULL,
                        device VARCHAR(255) NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_trusted_selector (selector),
                        INDEX idx_trusted_user (user_id)
                    )");
    } catch (Throwable $e) {}
}

// ---------- 1. Account lockout ----------

function account_locked(array $user) {
    return !empty($user['locked_until']) && strtotime($user['locked_until']) > time();
}

/** A wrong password (or sign-in code). Returns [locked now?, tries left]. */
function login_failed($pdo, array $user) {
    // A lock that has already run out: counting starts again.
    $fails = (empty($user['locked_until']) ? (int)($user['failed_attempts'] ?? 0) : 0) + 1;
    try {
        if ($fails >= LOGIN_MAX_TRIES) {
            $pdo->prepare("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?")
                ->execute([$fails, date('Y-m-d H:i:s', time() + LOGIN_LOCK_MINUTES * 60), (int)$user['id']]);
            return [true, 0];
        }
        $pdo->prepare("UPDATE users SET failed_attempts = ?, locked_until = NULL WHERE id = ?")->execute([$fails, (int)$user['id']]);
    } catch (Throwable $e) {}
    return [false, LOGIN_MAX_TRIES - $fails];
}

/** Correct sign-in, password reset or the admin's Unlock: back to 0. */
function login_succeeded($pdo, $userId) {
    try { $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?")->execute([(int)$userId]); }
    catch (Throwable $e) {}
}

/**
 * Signs the user in (after the password, and the code when two-step is on)
 * and goes to their page. Does not return.
 */
function complete_sign_in($pdo, array $user, $how = '') {
    login_succeeded($pdo, $user['id']);
    unset($_SESSION['two_step']);
    session_regenerate_id(true);                          // a new session ID: an old (stolen) one is useless now
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['name']       = $user['name'];
    $_SESSION['role']       = $user['role'];
    $_SESSION['login_at']   = time();                     // when they signed in (see "sign everyone out" in config/auth.php)
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));  // a fresh form token for this sign-in

    // A blank last_login means this is the very first sign-in: "Welcome" instead of "Welcome back".
    $isFirstLogin = empty($user['last_login']);
    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
    log_activity($pdo, 'Logged in', ucfirst($user['role']) . ($how !== '' ? ' · ' . $how : ''));
    if ($user['role'] === 'patient') {                   // someone tried their password since last time?
        require_once __DIR__ . '/account_history.php';
        warn_failed_signins($pdo, $user);
    }
    // A one-time greeting right after signing in (portal / dashboard show it, then clear it).
    $_SESSION['show_welcome_popup'] = true;
    $_SESSION['just_registered']    = $isFirstLogin;
    redirect_after_login();                               // the page they wanted, else their home page
    exit;
}

// ---------- 2. Two-step sign-in (the roles the admin picks) ----------

function two_step_setting($pdo, $key, $default) {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : (string)$v;
    } catch (Throwable $e) { return $default; }
}

/** On when the admin turned it on AND email works (otherwise nobody could get a code). */
function two_step_on($pdo) {
    if (two_step_setting($pdo, 'sec_two_step', '0') !== '1') return false;
    if (!function_exists('mail_is_ready')) require_once __DIR__ . '/mailer.php';
    return mail_is_ready($pdo);
}

/** The roles that get a code, e.g. ['admin', 'dentist', 'staff']. */
function two_step_roles($pdo) {
    $roles = array_map('trim', explode(',', two_step_setting($pdo, 'sec_two_step_roles', TWO_STEP_ROLES_DEFAULT)));
    return array_values(array_intersect(array_keys(TWO_STEP_ROLE_NAMES), $roles));
}

/** How many days a device is remembered after the code (0 = ask at every sign-in). */
function two_step_days($pdo) {
    return max(0, min(TWO_STEP_MAX_DAYS, (int)two_step_setting($pdo, 'sec_two_step_days', '0')));
}

function two_step_needed($pdo, array $user) {
    return in_array($user['role'], two_step_roles($pdo), true) && two_step_on($pdo) && !two_step_device_trusted($pdo, $user);
}

/** Changes whenever the password changes, so a new password forgets every remembered device. */
function two_step_pw_tag(array $user) {
    return substr(hash('sha256', (string)$user['password']), 0, 16);
}

/**
 * This browser typed this account's code within the last N days (N = the
 * admin's setting today, so shortening it takes effect at once).
 */
function two_step_device_trusted($pdo, array $user) {
    $days = two_step_days($pdo);
    $raw  = $_COOKIE[TWO_STEP_COOKIE . (int)$user['id']] ?? '';
    if ($days === 0 || !preg_match('/^([a-f0-9]{16})\.([a-f0-9]{64})$/', $raw, $m)) return false;
    try {
        $st = $pdo->prepare("SELECT * FROM trusted_devices WHERE selector = ? AND user_id = ?");
        $st->execute([$m[1], (int)$user['id']]);
        $row = $st->fetch();
    } catch (Throwable $e) { return false; }
    return $row
        && hash_equals($row['token_hash'], hash('sha256', $m[2]))
        && hash_equals($row['pw_tag'], two_step_pw_tag($user))
        && strtotime($row['created_at']) + $days * 86400 > time();
}

/** After a right code with "remember this device" ticked: no code here for the next N days. */
function two_step_remember_device($pdo, array $user) {
    $days = two_step_days($pdo);
    if ($days === 0) return;
    $selector  = bin2hex(random_bytes(8));
    $validator = bin2hex(random_bytes(32));
    try {
        $pdo->prepare("DELETE FROM trusted_devices WHERE created_at < ?")
            ->execute([date('Y-m-d H:i:s', time() - TWO_STEP_MAX_DAYS * 86400)]);   // long-expired ones
        $pdo->prepare("INSERT INTO trusted_devices (user_id, selector, token_hash, pw_tag, device, created_at) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([(int)$user['id'], $selector, hash('sha256', $validator), two_step_pw_tag($user),
                       mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), date('Y-m-d H:i:s')]);
    } catch (Throwable $e) { return; }
    setcookie(TWO_STEP_COOKIE . (int)$user['id'], $selector . '.' . $validator, [
        'expires'  => time() + $days * 86400,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    ]);
}

/** The admin's "Forget all remembered devices": everyone gets a code at their next sign-in. */
function two_step_forget_all_devices($pdo) {
    try { return $pdo->exec("DELETE FROM trusted_devices"); } catch (Throwable $e) { return 0; }
}

/** "ma•••@gmail.com" — where the code went, without showing the whole address. */
function mask_email($email) {
    [$name, $domain] = array_pad(explode('@', (string)$email, 2), 2, '');
    return mb_substr($name, 0, 2) . str_repeat('•', max(3, mb_strlen($name) - 2)) . ($domain !== '' ? '@' . $domain : '');
}

/** Emails a new 6-digit code. Returns '' when it was sent, else what went wrong. */
function two_step_send($pdo, array $user) {
    if (!function_exists('send_mail')) require_once __DIR__ . '/mailer.php';
    $code = (string)random_int(100000, 999999);
    $body = mail_template('Your sign-in code',
        'Hi ' . e($user['name']) . ',<br><br>To finish signing in to the St. Therese Dental Clinic system, enter this code:
         <div style="font-size:30px;font-weight:700;letter-spacing:8px;color:#0f766e;background:#eef7f6;border-radius:10px;padding:14px;text-align:center;margin:14px 0;">'
        . $code . '</div>It works for ' . TWO_STEP_MINUTES . ' minutes.<br><br>
         If you did not just sign in, someone knows your password: change it right away and tell the clinic admin.');
    $err = '';
    if (!send_mail($pdo, $user['email'], 'Your sign-in code: ' . $code, $body, $err, 'two_step')) {
        unset($_SESSION['two_step']);
        log_activity_as($pdo, (int)$user['id'], $user['name'] . ' (' . $user['email'] . ')', $user['role'], 'Sign-in code not sent', $err);
        return 'We could not email your sign-in code. Please try again in a moment, or ask the admin to check Messaging Config.';
    }
    $_SESSION['two_step'] = ['uid' => (int)$user['id'], 'hash' => password_hash($code, PASSWORD_DEFAULT), 'email' => $user['email'],
                             'expires' => time() + TWO_STEP_MINUTES * 60, 'tries' => 0, 'sent' => time()];
    return '';
}

/** The code that was typed. Returns [user row, ''] when right, else [null, message]. */
function two_step_verify($pdo, $code) {
    $ts = $_SESSION['two_step'] ?? null;
    if (!$ts) return [null, 'Your sign-in expired. Please sign in again.'];
    if (time() > $ts['expires']) { unset($_SESSION['two_step']); return [null, 'That code has expired. Please sign in again.']; }
    $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$ts['uid']]);
    $user = $st->fetch();
    if (!$user || $user['status'] !== 'active') { unset($_SESSION['two_step']); return [null, 'This account can no longer sign in.']; }

    $code = preg_replace('/\D/', '', (string)$code);
    if (strlen($code) === 6 && password_verify($code, $ts['hash'])) return [$user, ''];

    // Wrong code: counts like a wrong password (5 in a row lock the account).
    rate_hit($pdo, 'login_fail');
    log_activity_as($pdo, (int)$user['id'], $user['name'] . ' (' . $user['email'] . ')', $user['role'], 'Failed sign-in', 'Wrong sign-in code (IP ' . client_ip() . ')');
    [$locked] = login_failed($pdo, $user);
    $_SESSION['two_step']['tries'] = ++$ts['tries'];
    if ($locked) {
        unset($_SESSION['two_step']);
        log_activity_as($pdo, (int)$user['id'], $user['name'] . ' (' . $user['email'] . ')', $user['role'], 'Account locked',
                        LOGIN_MAX_TRIES . ' wrong tries in a row — locked for ' . LOGIN_LOCK_MINUTES . ' minutes');
        return [null, LOGIN_LOCKED_MESSAGE];
    }
    if ($ts['tries'] >= TWO_STEP_TRIES) { unset($_SESSION['two_step']); return [null, 'Too many wrong codes. Please sign in again.']; }
    return [null, 'Wrong code. Please check the email and try again.'];
}

/** "Send a new code" (at most once a minute). Returns '' when sent. */
function two_step_resend($pdo) {
    $ts = $_SESSION['two_step'] ?? null;
    if (!$ts) return 'Your sign-in expired. Please sign in again.';
    if (time() - $ts['sent'] < 60) return 'Please wait a minute before asking for a new code.';
    $st = $pdo->prepare("SELECT * FROM users WHERE id = ? AND status = 'active'");
    $st->execute([$ts['uid']]);
    if (!($user = $st->fetch())) { unset($_SESSION['two_step']); return 'This account can no longer sign in.'; }
    return two_step_send($pdo, $user);
}

// ---------- 3. Image uploads ----------

/**
 * Checks one uploaded image ($_FILES['x']). JPG or PNG only, judged by the
 * file's content (finfo + getimagesize), at most UPLOAD_MAX_MB.
 * Returns [extension to save it with ('jpg' / 'png'), error message or ''].
 */
function check_image_upload($file) {
    $err = is_array($file) ? (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE || empty($file['name'])) return ['', 'Please choose an image first.'];
    if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int)$file['size'] > UPLOAD_MAX_MB * 1024 * 1024) {
        return ['', 'That image is too large. The limit is ' . UPLOAD_MAX_MB . ' MB.'];
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return ['', 'The upload did not finish. Please try again.'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) return ['', 'Only JPG and PNG images are allowed.'];

    // The content must really be a JPG / PNG picture — a renamed file (e.g. a .php
    // script called photo.jpg) is refused.
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string)finfo_file($fi, $file['tmp_name']);
        finfo_close($fi);
    }
    $info = @getimagesize($file['tmp_name']);
    $type = $info[2] ?? 0;
    $mimeType = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG];
    if (!$info || !in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
        || ($mime !== '' && ($mimeType[$mime] ?? -1) !== $type)) {
        return ['', 'That file is not a real JPG or PNG image.'];
    }
    return [$type === IMAGETYPE_PNG ? 'png' : 'jpg', ''];
}

/** A random file name for an upload, e.g. "3f9c…e1.jpg" (never the name it came with). */
function random_upload_name($ext, $prefix = '') {
    return $prefix . bin2hex(random_bytes(16)) . '.' . $ext;
}

// The "accept" list and the hint shown under image upload boxes.
const IMAGE_ACCEPT = '.jpg,.jpeg,.png,image/jpeg,image/png';
function image_upload_hint() { return 'JPG or PNG, up to ' . UPLOAD_MAX_MB . ' MB.'; }

// ---------- 4. Record audit ----------

/**
 * "Viewed patient record" in the Activity Log — once per patient per 30
 * minutes for each user, so moving between tabs does not fill the log.
 */
function log_record_view($pdo, $pid, $where) {
    $pid = (int)$pid;
    if (!$pid || !is_logged_in() || current_role() === 'patient') return;
    $GLOBALS['ACTIVITY_PATIENT_ID'] = $pid;               // what is done on this page is tagged with the patient too
    $seen = $_SESSION['viewed_records'] ?? [];
    if (isset($seen[$pid]) && time() - $seen[$pid] < 1800) return;
    $seen[$pid] = time();
    if (count($seen) > 60) $seen = array_slice($seen, -60, null, true);
    $_SESSION['viewed_records'] = $seen;
    log_activity($pdo, 'Viewed patient record', patient_name_of($pdo, $pid) . ' — ' . $where);
}
