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

require_once __DIR__ . '/db.php';
// Logins are kept in the database, so an update of the live site (a new
// container on Railway) no longer signs everyone out. See config/session_db.php.
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/session_db.php';
    session_set_save_handler(new DbSessionHandler($pdo), true);
}
session_start();               // turn on PHP sessions (remembers who is logged in)
require_once __DIR__ . '/../includes/assign.php';   // patient -> dentist auto-balancer

// ---- Self-healing database (new tables / columns) ----
// These checks only need to run once after each update of the system, not on
// every click: on Railway every query is a trip to the database server, and
// they were ~20 extra trips per page. The marker changes whenever this file is
// deployed again, so a new version always runs them once.
$__schemaKey = 'schema-' . @filemtime(__FILE__);
$__schemaOk  = false;
try {
    $__schemaOk = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_ok'")->fetchColumn() === $__schemaKey;
} catch (Throwable $e) {}
define('SCHEMA_CHECKED', $__schemaOk);
if (!$__schemaOk) {
    ensure_archive_schema($pdo);                    // self-heals the archive columns/table
    ensure_activity_log_schema($pdo);               // self-heals the activity_log table
    ensure_patient_archive_schema($pdo);            // self-heals the patients table's archive columns
    ensure_dependents_schema($pdo);                 // family members booked by a patient get their own record (needs assign.php)
    ensure_booking_review_schema($pdo);             // health questionnaire + cancellation review columns
    require_once __DIR__ . '/../includes/patient_notices.php';
    ensure_patient_notices_table($pdo);             // cancellation / no-show pop-ups
    require_once __DIR__ . '/../includes/account_transfer.php';
    ensure_account_transfer_tables($pdo);           // own-account invites, login email changes
    require_once __DIR__ . '/../includes/dental_summary.php';
    ensure_chart_requests_table($pdo);              // patients asking for a printed dental chart
    try { save_setting($pdo, 'schema_ok', $__schemaKey); } catch (Throwable $e) {}
}

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

// ============================================================
//  ROUTE GUARD  (runs at the top of every page)
// ============================================================
//  Every page starts with ONE of these:
//     require_login(['admin','staff'])   protected page, only these roles
//     require_login()                    protected page, any logged-in user
//     require_guest()                    guest-only page (login, forgot password)
//  and unknown addresses fall through to not_found.php (see .htaccess).
//
//  Rules
//   - Guest on a protected page      -> /login (then back to that page after signing in)
//   - Logged-in user on a guest page -> their own home page, no form, no message
//   - Logged-in user, wrong role     -> their own home page (attempt is logged)
//
//  Redirects use the app's own base path ("" on Railway, "/dental-clinic"
//  on XAMPP), never a full http://host URL — behind Railway's proxy that
//  would point at the wrong address, and a relative one could loop.
// ============================================================

// Where each role lands after signing in.
const ROLE_HOME = [
    'admin'   => 'dashboard',
    'dentist' => 'dashboard',
    'staff'   => 'dashboard',
    'patient' => 'portal',
];

// "/dental-clinic" on XAMPP, "" when the app sits at the site root.
function app_base() {
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return rtrim($dir, '/');
}
function app_url($page) {
    return app_base() . '/' . ltrim($page, '/');
}
function redirect_to($page, $status = 302) {
    header('Location: ' . app_url($page), true, $status);
    exit;
}

// The logged-in user's own home page.
function home_page() {
    return ROLE_HOME[current_role()] ?? 'login';
}

// Remember the page a guest asked for, so login can send them back to it.
// Only a bare page name (+ its query string) from this app is kept — never
// a full address — so it can't be abused to redirect somewhere else.
function remember_intended_page() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    $page = preg_replace('/\.php$/', '', basename($path));
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $page) || $page === 'login') return;
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $_SESSION['intended_page'] = $page . ($qs !== '' ? '?' . $qs : '');
}
function take_intended_page() {
    $p = $_SESSION['intended_page'] ?? '';
    unset($_SESSION['intended_page']);
    return preg_match('/^[A-Za-z0-9_-]+(\?[^\s]*)?$/', $p) ? $p : '';
}

// After a successful sign-in: the page they originally wanted, else home.
function redirect_after_login() {
    redirect_to(take_intended_page() ?: home_page());
}

// Guest-only pages (login, register, forgot password): a signed-in user
// is sent straight to their own home page instead.
function require_guest() {
    if (is_logged_in()) redirect_to(home_page());
}

// Protected pages. $allowed_roles is an optional list, e.g. ['admin'].
function require_login($allowed_roles = null) {
    // Private pages must never be cached, so pressing Back after logging out
    // cannot show a previous user's data.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if (!is_logged_in()) {
        remember_intended_page();
        redirect_to('login');
    }
    if ($allowed_roles !== null && !in_array(current_role(), $allowed_roles, true)) {
        // Signed in, but this page is not for their role (e.g. typed into the
        // address bar). Record the attempt and take them to their own home page.
        global $pdo;
        if (isset($pdo)) log_activity($pdo, 'Access denied', 'Tried to open ' . basename($_SERVER['SCRIPT_NAME'] ?? ''));
        redirect_to(home_page());
    }
}

// A RECORD they may not see (e.g. someone else's patient id in the URL).
// Logged, then back to their home page with a short notice. $notice=false
// for requests that are not a page (an image), where a toast makes no sense.
function deny_access($logDetails = '', $notice = true) {
    global $pdo;
    if ($logDetails !== '' && isset($pdo)) {
        log_activity($pdo, 'Access denied', $logDetails);
    }
    if ($notice) set_flash("You don't have access to that record.", 'error');
    redirect_to(home_page());
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
//  Returns 'weak', 'medium' or 'strong'. Only STRONG is accepted
//  (password_problem() below) — this is the check that actually
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
    return 'type="tel" inputmode="numeric" maxlength="11" autocomplete="tel" data-digits'
         . ' pattern="09[0-9]{9}"'
         . ' title="11-digit Philippine mobile number, e.g. 09171234567"';
}

// Only STRONG passwords are accepted anywhere a password is set.
// Returns an error message, or '' when the password is fine.
function password_problem($pass) {
    if (password_strength($pass) !== 'strong') {
        return 'Please choose a Strong password: at least 8 characters using upper- and lower-case letters, '
             . 'a number and a symbol (e.g. Smile#2026).';
    }
    return '';
}

// A random Strong password for accounts created by staff (shown once and
// emailed to the new user, who should change it after signing in).
function generate_temp_password() {
    $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '#@!%*?'];
    $chars = [];
    foreach ([3, 5, 2, 2] as $i => $n) for ($k = 0; $k < $n; $k++) $chars[] = $sets[$i][random_int(0, strlen($sets[$i]) - 1)];
    for ($i = count($chars) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]]; }
    return implode('', $chars);                         // 12 characters, all four kinds
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
// Is this a REAL email address? name@domain.tld (so "jomar@123" or
// "jomar@gmail" are refused), and the domain must actually receive mail.
// Returns an error message, or '' when it is fine.
function email_problem($email) {
    $email = trim((string)$email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)
        || !preg_match('/^[^@\s]+@([A-Za-z0-9-]+\.)+[A-Za-z]{2,}$/', $email)) {
        return 'Please enter a real email address, for example name@gmail.com.';
    }
    // Does the domain accept mail at all? This catches believable typos.
    $domain = substr(strrchr($email, '@'), 1);
    if ($domain && function_exists('checkdnsrr')
        && !checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A')) {
        return "No mail server found for \"$domain\". Please check the email address.";
    }
    return '';
}

function validate_account_email($pdo, $email, $ignoreUserId = null) {
    $email = trim($email);

    if ($email === '') {
        return 'Please enter an email address.';
    }
    if (($ep = email_problem($email)) !== '') return $ep;

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

// Announcement text for display: escaped first (safe), then the simple
// markup people type — **bold** and *italic*. Line breaks are kept by the
// caller's CSS (white-space: pre-line).
function format_announcement($text) {
    $html = e($text);
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
    $html = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $html);
    return $html;
}

// ============================================================
//  FAMILY MEMBERS ("Someone else" bookings)
// ============================================================
//  A patient can book for someone else (a child, a parent ...). That
//  person gets their OWN patient record — so they appear in the patient
//  list, odontogram and records with their own dentist — linked to the
//  booking patient through patients.guardian_patient_id. They have no
//  login: the guardian manages their appointments from the portal, and
//  clinic emails for them go to the guardian's address.
// ============================================================
function ensure_dependents_schema($pdo) {
    try {
        $has = $pdo->query("SHOW COLUMNS FROM patients LIKE 'guardian_patient_id'")->rowCount();
        if (!$has) {
            $pdo->exec("ALTER TABLE patients ADD COLUMN guardian_patient_id INT DEFAULT NULL");
            $pdo->exec("ALTER TABLE patients ADD COLUMN relationship VARCHAR(40) DEFAULT NULL");
            $pdo->exec("ALTER TABLE patients ADD INDEX idx_guardian (guardian_patient_id)");
        }
    } catch (Throwable $e) { return; }

    // One-time: older "Someone else" bookings were filed under the booking
    // patient's own record. Give each of those people a record of their own.
    try {
        $done = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='dependents_migrated_v1'")->fetchColumn();
        if ($done) return;
        $rows = $pdo->query(
            "SELECT a.id, a.patient_id, a.patient_name, a.relationship, a.dentist, a.notes, a.created_at,
                    p.name AS owner_name, p.phone AS owner_phone
               FROM appointments a JOIN patients p ON p.id = a.patient_id
              WHERE a.booked_for = 'Someone else' AND p.guardian_patient_id IS NULL
              ORDER BY a.appointment_date DESC, a.id DESC"
        )->fetchAll();
        foreach ($rows as $r) {
            if (person_name_key($r['patient_name']) === person_name_key($r['owner_name'])) continue;
            $dob = preg_match('/Patient DOB:\s*(\d{4}-\d{2}-\d{2})/', (string)$r['notes'], $m) ? $m[1] : null;
            $depId = find_or_create_dependent($pdo, (int)$r['patient_id'], $r['patient_name'],
                                              $r['relationship'], $dob, canonical_dentist_name($pdo, $r['dentist']),
                                              $r['owner_phone'], $r['created_at']);
            if ($depId) $pdo->prepare("UPDATE appointments SET patient_id=? WHERE id=?")->execute([$depId, $r['id']]);
        }
        save_setting($pdo, 'dependents_migrated_v1', date('Y-m-d H:i:s'));
    } catch (Throwable $e) { /* try again on the next page load */ }
}

function ensure_booking_review_schema($pdo) {
    try {
        if (!$pdo->query("SHOW COLUMNS FROM appointments LIKE 'health_form'")->rowCount()) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN health_form TEXT DEFAULT NULL");
        }
        $st = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'status'")->fetch();
        if ($st && strpos($st['Type'], "'Disapproved'") === false) {
            $pdo->exec("ALTER TABLE appointments MODIFY status ENUM('Pending','Confirmed','Cancelled','Completed',
                        'No-show','Rescheduled','Needs Review','Expired','Arrived','Disapproved') DEFAULT 'Pending'");
        }
        // Odontogram: a deleted visit chart goes to the Archive (restorable), not the bin.
        if (!$pdo->query("SHOW COLUMNS FROM chart_sessions LIKE 'archived_at'")->rowCount()) {
            $pdo->exec("ALTER TABLE chart_sessions ADD COLUMN archived_at DATETIME DEFAULT NULL, ADD COLUMN archived_by VARCHAR(100) DEFAULT NULL");
        }
        // Notification bell: the newest item of each kind this user has seen.
        if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'notif_seen_map'")->rowCount()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN notif_seen_map TEXT DEFAULT NULL");
        }
        // Each user's own text size (null = the clinic default).
        if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'text_scale'")->rowCount()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN text_scale SMALLINT DEFAULT NULL");
        }
        // ...and the items they clicked, which are removed from their bell.
        if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'notif_hidden_map'")->rowCount()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN notif_hidden_map TEXT DEFAULT NULL");
        }
        // Same-person identity: normalized name (+ date_of_birth) with an index,
        // so duplicate checks across accounts are one fast lookup.
        try {                                            // own try: never blocks the steps below
        if (!$pdo->query("SHOW COLUMNS FROM patients LIKE 'name_key'")->rowCount()) {
            $pdo->exec("ALTER TABLE patients ADD COLUMN name_key VARCHAR(150)
                        AS (LOWER(TRIM(REPLACE(REPLACE(REPLACE(name, '    ', ' '), '   ', ' '), '  ', ' ')))) STORED");
            $pdo->exec("ALTER TABLE patients ADD INDEX idx_identity (name_key, date_of_birth)");
        }
        if (!$pdo->query("SHOW INDEX FROM appointments WHERE Key_name = 'idx_patient_status'")->rowCount()) {
            $pdo->exec("ALTER TABLE appointments ADD INDEX idx_patient_status (patient_id, status)");
            $pdo->exec("ALTER TABLE appointments ADD INDEX idx_date_status (appointment_date, status)");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
                        id         INT AUTO_INCREMENT PRIMARY KEY,
                        action     VARCHAR(30) NOT NULL,
                        ip         VARCHAR(45) NOT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_rate (action, ip, created_at)
                    )");
        } catch (Throwable $e) { /* e.g. an older database without generated columns */ }
        if (!$pdo->query("SHOW COLUMNS FROM patients LIKE 'health_form'")->rowCount()) {
            $pdo->exec("ALTER TABLE patients ADD COLUMN health_form TEXT DEFAULT NULL");
            $pdo->exec("ALTER TABLE patients ADD COLUMN health_form_at DATETIME DEFAULT NULL");
            // Copy each patient's latest answers from their bookings onto their record.
            $pdo->exec("UPDATE patients p JOIN (
                            SELECT a.patient_id, a.health_form, a.created_at FROM appointments a
                             WHERE a.health_form IS NOT NULL
                               AND a.id = (SELECT a2.id FROM appointments a2 WHERE a2.patient_id = a.patient_id
                                            AND a2.health_form IS NOT NULL ORDER BY a2.created_at DESC, a2.id DESC LIMIT 1)
                        ) last ON last.patient_id = p.id
                        SET p.health_form = last.health_form, p.health_form_at = last.created_at");
        }
        if (!$pdo->query("SHOW COLUMNS FROM appointments LIKE 'arrived_at'")->rowCount()) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN patient_confirmed_at DATETIME DEFAULT NULL");
            $pdo->exec("ALTER TABLE appointments ADD COLUMN arrived_at DATETIME DEFAULT NULL");
            $pdo->exec("ALTER TABLE appointments ADD COLUMN arrived_by VARCHAR(100) DEFAULT NULL");
        }
        if (!$pdo->query("SHOW COLUMNS FROM patients LIKE 'cancel_reset_at'")->rowCount()) {
            $pdo->exec("ALTER TABLE patients ADD COLUMN cancel_reset_at DATETIME DEFAULT NULL");
            $pdo->exec("ALTER TABLE patients ADD COLUMN cancel_reset_by VARCHAR(100) DEFAULT NULL");
        }
    } catch (Throwable $e) { /* ignore */ }
}

// ---- Arrived -> Completed ----
// "Arrived" = the patient is at the clinic (it already left their upcoming
// list and no longer counts toward booking limits). As soon as treatment is
// recorded for them that day — a treatment, chart visit, note or X-ray — the
// visit becomes Completed.
function complete_arrived_visit($pdo, $patientId, $date = null) {
    try {
        $pdo->prepare("UPDATE appointments SET status = 'Completed'
                        WHERE patient_id = ? AND status = 'Arrived' AND appointment_date = ?")
            ->execute([(int)$patientId, $date ?: date('Y-m-d')]);
    } catch (Throwable $e) {}
}

// ============================================================
//  ANTI-SPAM (no SMS needed)
// ============================================================
//  1. SAME PERSON, ANY ACCOUNT — one active booking per real person:
//     a patient is identified by name + date of birth, whichever account
//     books them. same_person_active_booking() finds an active booking for
//     that identity outside the given patient records (the booking
//     account's own family), using the indexed patients.name_key.
//  2. RATE LIMITS per IP address for sign-ups, logins, reset codes and
//     booking attempts (rate_limited() / rate_hit()).
// ============================================================
function same_person_active_booking($pdo, $name, $dob, $excludePatientIds = []) {
    $key = person_name_key($name);
    if ($key === '' || !$dob) return null;              // no birth date on file: cannot tell people apart
    $sql = "SELECT a.id, a.appointment_date, a.appointment_time, a.patient_id
              FROM patients p JOIN appointments a ON a.patient_id = p.id
             WHERE p.name_key = ? AND p.date_of_birth = ?
               AND a.status IN ('Pending','Confirmed')";
    $prm = [$key, $dob];
    $ex = array_values(array_filter(array_map('intval', (array)$excludePatientIds)));
    if ($ex) { $sql .= " AND p.id NOT IN (" . implode(',', array_fill(0, count($ex), '?')) . ")"; $prm = array_merge($prm, $ex); }
    try {
        $st = $pdo->prepare($sql . " ORDER BY a.appointment_date LIMIT 1");
        $st->execute($prm);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }             // name_key not added yet
}

// The visitor's IP. Behind Railway's proxy the real one is the first
// X-Forwarded-For entry; locally it is REMOTE_ADDR.
function client_ip() {
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $ip  = $xff !== '' ? trim(explode(',', $xff)[0]) : ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}
// Has this IP done $action $max times in the last $seconds?
function rate_limited($pdo, $action, $max, $seconds) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE action = ? AND ip = ? AND created_at > (NOW() - INTERVAL ? SECOND)");
        $st->execute([$action, client_ip(), (int)$seconds]);
        return (int)$st->fetchColumn() >= $max;
    } catch (Throwable $e) { return false; }
}
function rate_hit($pdo, $action) {
    try {
        $pdo->prepare("INSERT INTO rate_limits (action, ip) VALUES (?, ?)")->execute([$action, client_ip()]);
        if (random_int(1, 50) === 1) $pdo->exec("DELETE FROM rate_limits WHERE created_at < NOW() - INTERVAL 2 DAY");
    } catch (Throwable $e) {}
}

// ---- Birth dates ----
// A real calendar date, and the patient must be at least MIN_PATIENT_AGE
// years old (so today, future dates and newborns are refused).
const MIN_PATIENT_AGE = 2;
function birth_date_max() { return date('Y-m-d', strtotime('-' . MIN_PATIENT_AGE . ' years')); }
function birth_date_error($v, $required = false) {
    $v = trim((string)$v);
    if ($v === '') return $required ? 'Please enter the date of birth.' : '';
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) return 'Please enter a valid date of birth.';
    if ($v > birth_date_max()) return 'The date of birth must be at least ' . MIN_PATIENT_AGE . ' years ago.';
    if ($v < '1900-01-01')     return 'Please enter a valid date of birth.';
    return '';
}

// Age in whole years from a birth date ("2008-09-08" -> 18), or null.
function age_from_dob($dob) {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', trim((string)$dob));
    return $d ? (int)$d->diff(new DateTimeImmutable('today'))->y : null;
}

// ============================================================
//  CHANGE PASSWORD WITH A CODE (every role)
// ============================================================
//  Step 1 (pw_change_start): check the current password and the new
//  one, then send a 6-digit code to the account's email. If email is
//  not set up, the code is shown on screen instead ("demo mode"), the
//  same as when creating an account.
//  Step 2 (pw_change_finish): the right code changes the password.
//  Nothing changes until the code is entered. A code lasts 15 minutes
//  and 5 wrong tries cancel it.
// ============================================================
function pw_change_start($pdo, $current, $new, $confirm) {
    $u = $pdo->prepare("SELECT name, email, password FROM users WHERE id = ?");
    $u->execute([$_SESSION['user_id'] ?? 0]);
    $row = $u->fetch();
    if (!$row || !password_verify($current, $row['password'])) return 'Your current password is incorrect.';
    if (($pwp = password_problem($new)) !== '')                  return $pwp;
    if ($new !== $confirm)                                         return 'The new passwords do not match.';
    if (password_verify($new, $row['password']))                   return 'The new password must be different from the current one.';

    $code = (string)random_int(100000, 999999);
    $_SESSION['pw_change'] = ['hash' => password_hash($new, PASSWORD_DEFAULT), 'code' => $code,
                              'email' => $row['email'], 'expires' => time() + 900, 'tries' => 0, 'sent' => false];
    try {
        require_once __DIR__ . '/../includes/mailer.php';
        $body = mail_template('Confirm your new password',
            'Hi ' . e(explode(' ', (string)$row['name'])[0]) . ', someone asked to change the password of your St. Therese Dental Clinic account.<br><br>
             Your confirmation code is:
             <div style="font-size:30px;font-weight:700;letter-spacing:8px;color:#0f766e;background:#eef7f6;border-radius:10px;padding:14px;text-align:center;margin:14px 0;">'
             . $code . '</div>
             Enter it to finish changing your password. If this was not you, ignore this email — your password stays the same.');
        $err = '';
        if ($row['email'] && send_mail($pdo, $row['email'], 'Your password change code: ' . $code, $body, $err)) {
            $_SESSION['pw_change']['sent'] = true;
        }
    } catch (Throwable $e) {}
    return '';
}
function pw_change_finish($pdo, $code) {
    $pc = $_SESSION['pw_change'] ?? null;
    if (!$pc || time() > $pc['expires']) { unset($_SESSION['pw_change']); return 'That code has expired. Please start again.'; }
    if (!hash_equals($pc['code'], trim((string)$code))) {
        $_SESSION['pw_change']['tries']++;
        if ($_SESSION['pw_change']['tries'] >= 5) { unset($_SESSION['pw_change']); return 'Too many wrong codes. Please start again.'; }
        return 'Incorrect code. Please try again.';
    }
    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$pc['hash'], $_SESSION['user_id']]);
    unset($_SESSION['pw_change']);
    log_activity($pdo, 'Changed password', 'Own account (confirmed with a code)');
    return '';
}
// The "enter your code" box shown in place of the password form while a change is waiting.
function pw_change_box() {
    $pc = $_SESSION['pw_change'] ?? [];
    ob_start(); ?>
    <p class="text-muted2 mb-2" style="font-size:.85rem;">Enter the 6-digit code we sent to <strong><?= e($pc['email'] ?? 'your email') ?></strong> to finish changing your password.</p>
    <?php if (!empty($pc['sent'])): ?>
        <div class="alert alert-success py-2" style="font-size:.85rem;">📧 Code emailed. Check your inbox (and the spam folder).</div>
    <?php else: ?>
        <div class="alert" style="background:#fff6e0;border:1px solid #e0b64a;color:#8a6d2f;font-size:.83rem;">
            🧪 <strong>Demo mode:</strong> your code is <strong style="font-size:1.1rem;letter-spacing:2px;"><?= e($pc['code'] ?? '------') ?></strong>.
            Email is not set up yet, so it is shown here. An admin can turn on real email in <em>Messaging Config</em>.
        </div>
    <?php endif; ?>
    <form method="POST" style="max-width:420px;">
        <input type="hidden" name="action" value="verify_password_code">
        <label class="field-label">Confirmation Code</label>
        <input name="code" class="form-control mb-3" placeholder="123456" maxlength="6" inputmode="numeric" data-digits required autofocus
               style="letter-spacing:6px;font-size:1.2rem;text-align:center;">
        <button class="btn btn-teal">✓ Confirm &amp; Change Password</button>
    </form>
    <form method="POST" class="mt-2">
        <input type="hidden" name="action" value="cancel_password_change">
        <button class="btn btn-link p-0" style="font-size:.85rem;" data-keep-text>Cancel — keep my current password</button>
    </form>
    <?php return ob_get_clean();
}

// "Maria  santos " and "maria Santos" are the same person.
function person_name_key($name) {
    return strtolower(preg_replace('/\s+/', ' ', trim((string)$name)));
}

// "Dr. Santos" (short form on old appointments) -> "Dr. Ana Santos" when that dentist exists.
function canonical_dentist_name($pdo, $name) {
    if (!$name) return null;
    foreach ($pdo->query("SELECT name FROM users WHERE role='dentist'")->fetchAll(PDO::FETCH_COLUMN) as $d) {
        if (in_array($name, dentist_name_variants($d), true)) return $d;
    }
    return $name;
}

// The family member's record under this guardian (matched by name), created
// the first time. Returns the patient id.
function find_or_create_dependent($pdo, $guardianPid, $name, $relationship = null, $dob = null,
                                  $dentist = null, $phone = null, $createdAt = null) {
    $name = trim(preg_replace('/\s+/', ' ', (string)$name));
    if ($name === '' || (int)$guardianPid <= 0) return null;
    $st = $pdo->prepare("SELECT id, name FROM patients WHERE guardian_patient_id = ?");
    $st->execute([(int)$guardianPid]);
    foreach ($st->fetchAll() as $r) {
        if (person_name_key($r['name']) === person_name_key($name)) {
            if ($dob) $pdo->prepare("UPDATE patients SET date_of_birth = COALESCE(date_of_birth, ?) WHERE id = ?")->execute([$dob, $r['id']]);
            return (int)$r['id'];
        }
    }
    $age = null;
    if ($dob && strtotime($dob)) $age = (int)(new DateTime($dob))->diff(new DateTime())->y;
    $pdo->prepare("INSERT INTO patients (name, phone, age, date_of_birth, patient_type, status, primary_dentist,
                                         guardian_patient_id, relationship, created_at)
                   VALUES (?, ?, ?, ?, 'New', 'Active', ?, ?, ?, COALESCE(?, NOW()))")
        ->execute([ucwords($name), $phone, $age, $dob ?: null, $dentist, (int)$guardianPid,
                   $relationship ?: null, $createdAt]);
    return (int)$pdo->lastInsertId();
}

// The patient's own record + every family member they book for.
function family_patient_ids($pdo, $pid) {
    $pid = (int)$pid;
    if ($pid <= 0) return [];
    $st = $pdo->prepare("SELECT id FROM patients WHERE guardian_patient_id = ?");
    $st->execute([$pid]);
    return array_merge([$pid], array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
}
// "?,?,?" for an IN (...) list of that many ids.
function in_placeholders($ids) { return implode(',', array_fill(0, max(1, count($ids)), '?')); }

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

// Has this patient actually been seen at the clinic? (A completed
// appointment or any treatment on file.) Needed before they may review.
function patient_has_clinic_record($pdo, $pid) {
    $pid = (int)$pid;
    if ($pid <= 0) return false;
    $st = $pdo->prepare("SELECT (EXISTS(SELECT 1 FROM appointments WHERE patient_id = ? AND status = 'Completed')
                              OR EXISTS(SELECT 1 FROM treatments  WHERE patient_id = ?))");
    $st->execute([$pid, $pid]);
    return (bool)$st->fetchColumn();
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
// The clinic's Facebook page: a facebook.com / fb.com link, or just the page
// name ("StThereseDental" -> https://www.facebook.com/StThereseDental).
// Returns [url, error]; a blank value is allowed (no Facebook shown).
function facebook_url($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return ['', ''];
    if (preg_match('/^@?[A-Za-z0-9.\-]{3,80}$/', $raw) && !preg_match('/facebook\.com|fb\.com/i', $raw)) {
        return ['https://www.facebook.com/' . ltrim($raw, '@'), ''];
    }
    $url  = preg_match('#^https?://#i', $raw) ? $raw : 'https://' . $raw;
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $ok   = $host !== '' && preg_match('/(^|\.)(facebook\.com|fb\.com|fb\.me)$/', $host)
            && filter_var($url, FILTER_VALIDATE_URL);
    if (!$ok) return ['', 'Facebook: please paste a facebook.com link (e.g. https://www.facebook.com/YourClinicPage).'];
    return [preg_replace('#^http://#i', 'https://', $url), ''];
}

// ============================================================
//  DELETE SEVERAL AT ONCE
// ============================================================
//  Every list with a delete button also gets a tick box per row and a
//  "Select all / Delete selected" bar (bulk_bar + bulk_pick). The bar is
//  its own form; the tick boxes join it through the form="" attribute,
//  so they can sit anywhere in the row. The handler then reads
//  bulk_ids(): the ticked ids[], or the single id from a row's button.
// ============================================================
function bulk_ids($single = 'id') {
    $ids = $_POST['ids'] ?? [$_POST[$single] ?? 0];
    if (!is_array($ids)) $ids = [$ids];
    return array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
}

// The bar: "☐ Select all · 3 selected · [🗑 Delete selected]".
// $noun is used in the question, e.g. "Delete 3 selected notes?".
function bulk_bar($formId, $action, $noun, $hidden = [], $button = '🗑 Delete selected', $warning = '', $verb = 'Delete') {
    $h = '<form method="POST" id="' . e($formId) . '" class="bulk-bar" data-noun="' . e($noun) . '" data-verb="' . e($verb) . '"'
       . ' data-warning="' . e($warning) . '" onsubmit="return bulkConfirm(this)">'
       . '<input type="hidden" name="action" value="' . e($action) . '">';
    foreach ($hidden as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    return $h . '<label class="bulk-all-wrap"><input type="checkbox" class="bulk-all" data-bulk="' . e($formId) . '"> Select all</label>'
         . '<span class="bulk-count"></span>'
         . '<button type="submit" class="btn btn-sm bulk-go" disabled>' . e($button) . '</button></form>';
}

// One row's tick box.
function bulk_pick($formId, $value, $label = 'Select') {
    return '<input type="checkbox" class="bulk-pick" form="' . e($formId) . '" name="ids[]" value="' . e($value) . '"'
         . ' title="' . e($label) . '" aria-label="' . e($label) . '">';
}

// How a status is SHOWN. The database keeps "Confirmed", but the clinic calls
// an accepted booking "Approved" everywhere on screen.
function status_label($status) {
    return ['Confirmed' => 'Approved'][$status] ?? $status;
}

function save_setting($pdo, $key, $value) {
    $pdo->prepare(
        "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = ?"
    )->execute([$key, $value, $value]);
}

// ============================================================
//  THEME COLOURS  (Edit Landing Page → System Colours)
// ============================================================
//  The admin picks two colours: the MAIN colour (green by default) and
//  the BACKGROUND colour (white by default). The darker/lighter shades
//  the design needs are worked out from the main colour, so one pick
//  re-colours the sidebar, buttons, headings and the landing page.
// ============================================================
define('THEME_DEFAULT_PRIMARY', '#0f766e');
define('THEME_DEFAULT_BG', '#ffffff');

function theme_hex_ok($h) { return is_string($h) && preg_match('/^#[0-9a-f]{6}$/i', $h); }

function theme_mix($hex, $with, $amount) {       // blend $hex toward $with by $amount (0..1)
    $a = sscanf($hex, '#%02x%02x%02x'); $b = sscanf($with, '#%02x%02x%02x');
    $o = '#';
    for ($i = 0; $i < 3; $i++) $o .= sprintf('%02x', (int)round($a[$i] + ($b[$i] - $a[$i]) * $amount));
    return $o;
}
function theme_luminance($hex) {
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
}

// [primary, background] — saved values, or the overrides given (used by the live preview).
function theme_colors($pdo, $primary = null, $bg = null) {
    static $saved = null;
    if ($saved === null) {
        $saved = [THEME_DEFAULT_PRIMARY, THEME_DEFAULT_BG];
        try {
            $q = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('theme_primary','theme_bg')");
            foreach ($q as $r) {
                if ($r['setting_key'] === 'theme_primary' && theme_hex_ok($r['setting_value'])) $saved[0] = strtolower($r['setting_value']);
                if ($r['setting_key'] === 'theme_bg'      && theme_hex_ok($r['setting_value'])) $saved[1] = strtolower($r['setting_value']);
            }
        } catch (Throwable $e) {}
    }
    return [theme_hex_ok($primary) ? strtolower($primary) : $saved[0], theme_hex_ok($bg) ? strtolower($bg) : $saved[1]];
}

// The CSS variables, for the system pages ($scope 'system') or the public
// landing page ($scope 'landing' — it names its shades differently). Empty when nothing changed.
function theme_style_tag($pdo, $scope = 'system', $primary = null, $bg = null, $force = false) {
    [$p, $w] = theme_colors($pdo, $primary, $bg);
    if (!$force && $p === THEME_DEFAULT_PRIMARY && $w === THEME_DEFAULT_BG) return '';
    $dark  = theme_mix($p, '#000000', .55);
    $deep  = theme_mix($p, '#000000', .30);
    $light = theme_mix($p, '#ffffff', .25);
    $soft  = theme_mix($p, $w, .92);             // pale tint of the main colour on the background
    $page  = theme_mix($w, '#000000', .05);      // a touch darker than the cards
    $vars = $scope === 'landing'
        ? "--blue:$p;--blue-600:$deep;--teal:$p;--teal-600:$deep;--teal-dark:$dark;--teal-light:$light;"
          . "--sky:$soft;--mint:$soft;--white:$w;--grad:linear-gradient(135deg,$dark 0%,$p 55%,$light 100%);"
        : "--teal-dark:$dark;--teal:$deep;--teal-mid:$p;--teal-light:$light;--card:$w;--bg:$page;";
    return "<style id=\"theme-colors\">:root{{$vars}--theme-p:$p;--theme-bg:$w;}</style>";
}

// ============================================================
//  TEXT SIZE  (readability)
// ============================================================
//  The whole system is sized in "rem", so one number on <html> makes
//  every text bigger or smaller. The admin sets the default for
//  everyone (Edit Landing Page → System Colours & Text Size); each
//  user can pick their own from the profile menu (Text size A− A A+),
//  which wins over the default — handy for anyone with blurry eyesight.
// ============================================================
const TEXT_SCALES = [100 => 'Small', 110 => 'Normal', 125 => 'Large', 140 => 'Extra large'];
define('TEXT_SCALE_DEFAULT', 110);

function text_scale_ok($v) { return isset(TEXT_SCALES[(int)$v]); }

function system_text_scale($pdo) {
    static $s = null;
    if ($s === null) {
        $s = TEXT_SCALE_DEFAULT;
        try {
            $v = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'ui_text_scale'")->fetchColumn();
            if (text_scale_ok($v)) $s = (int)$v;
        } catch (Throwable $e) {}
    }
    return $s;
}

// The logged-in user's own choice, or null to follow the clinic default.
function user_text_scale($pdo) {
    static $cache = [];
    if (empty($_SESSION['user_id'])) return null;
    $uid = (int)$_SESSION['user_id'];
    if (array_key_exists($uid, $cache)) return $cache[$uid];
    return $cache[$uid] = user_text_scale_load($pdo);
}
function user_text_scale_load($pdo) {
    try {
        $q = $pdo->prepare("SELECT text_scale FROM users WHERE id = ?");
        $q->execute([(int)$_SESSION['user_id']]);
        $v = $q->fetchColumn();
        return text_scale_ok($v) ? (int)$v : null;
    } catch (Throwable $e) { return null; }
}

function text_scale_style($pdo, $override = null) {
    $s = text_scale_ok($override) ? (int)$override : (user_text_scale($pdo) ?? system_text_scale($pdo));
    return '<style id="text-scale">html{font-size:' . $s . '%}</style>';
}

// ============================================================
//  SIGN EVERYONE OUT AFTER A SITE UPDATE
// ============================================================
//  When the admin saves the landing page / system look with "Sign everyone
//  else out" ticked, settings.force_logout_at is stamped. Anyone who signed
//  in BEFORE that moment is signed out on their next click and sees a short
//  apology on the login page (?toast=updated), so everybody gets the new look.
// ============================================================
if (php_sapi_name() !== 'cli' && !empty($_SESSION['user_id'])) {
    try {
        $__fl = (int)$pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'force_logout_at'")->fetchColumn();
    } catch (Throwable $e) { $__fl = 0; }
    if ($__fl > 0 && (int)($_SESSION['login_at'] ?? 0) < $__fl) {
        $_SESSION = [];
        session_regenerate_id(true);
        if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
            || strpos($_SERVER['SCRIPT_NAME'] ?? '', 'notif_seen') !== false || strpos($_SERVER['SCRIPT_NAME'] ?? '', 'text_size') !== false) {
            header('Content-Type: application/json'); echo json_encode(['ok' => false, 'signed_out' => true]); exit;
        }
        header('Location: ' . app_url('login?toast=updated'));
        exit;
    }
}
