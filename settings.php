<?php
// ============================================================
//  SYSTEM SETTINGS  (settings.php)
// ============================================================
//  Image 12 in the design.
//  - Admins see this WITH the admin tab-bar at the top.
//  - Dentists/Staff see it as a plain "Settings" page.
//
//  Clinic info is stored in the `settings` table as simple
//  key/value rows, so we just read them all into one array
//  and write them back when the form is saved.
// ============================================================
require_once 'config/auth.php';
require_login(['admin','dentist','staff']);   // everyone except patients

// Small helper: save one key/value into the settings table.
// "INSERT ... ON DUPLICATE KEY UPDATE" means: add it if it's new,
// otherwise just update the existing row (the key is the primary key).
// save_setting() now lives in config/auth.php, shared by every page that needs it.

// ---------- Handle the Save buttons ----------
$pwError = '';   // password-change error message (shown only if something is wrong)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // These actions change clinic-wide settings and must be admin-only, even if
    // someone crafts the POST by hand. Personal actions (profile, avatar,
    // password) are allowed for everyone and are handled further down.
    $adminOnly = ['save_clinic','save_security','save_hours','save_google','save_portal'];
    if (in_array($action, $adminOnly) && current_role() !== 'admin') {
        set_flash('You do not have permission to change that.', 'error');
        header("Location: settings"); exit;
    }

    if ($action === 'save_clinic') {
        // The clinic's number may be a landline. Keep it as typed (it is shown
        // on the site and slips), but only if it is a real number.
        [, $phoneError] = validate_phone($_POST['clinic_phone'] ?? '', false, true);
        if ($phoneError !== '') {
            set_flash('Clinic phone: ' . $phoneError, 'error');
            header("Location: settings"); exit;
        }
        $infoKeys = ['clinic_name' => 'Name', 'clinic_tagline' => 'Tagline', 'clinic_phone' => 'Phone', 'clinic_email' => 'Email',
                     'clinic_address' => 'Address', 'operating_hours' => 'Hours text'];
        $infoWas = [];
        foreach ($pdo->query("SELECT setting_key, setting_value FROM settings") as $r) $infoWas[$r['setting_key']] = $r['setting_value'];
        $infoNew = [];
        foreach (array_keys($infoKeys) as $k) $infoNew[$k] = trim($_POST[$k] ?? '');
        save_setting($pdo, 'clinic_name',     trim($_POST['clinic_name']));
        save_setting($pdo, 'clinic_tagline',  trim($_POST['clinic_tagline']));
        save_setting($pdo, 'clinic_phone',    trim($_POST['clinic_phone']));
        save_setting($pdo, 'clinic_email',    trim($_POST['clinic_email']));
        save_setting($pdo, 'clinic_address',  trim($_POST['clinic_address']));
        save_setting($pdo, 'operating_hours', trim($_POST['operating_hours']));
        log_activity($pdo, 'Updated clinic info', change_list($infoWas, $infoNew, $infoKeys) ?: 'Saved with no changes');
        set_flash('Settings saved.'); header("Location: settings"); exit;
    }

    if ($action === 'save_security') {
        // Checkboxes only appear in $_POST when they are ticked,
        // so we use isset() to turn them into '1' or '0'.
        save_setting($pdo, 'sec_login_audit',      isset($_POST['login_audit'])      ? '1' : '0');
        save_setting($pdo, 'sec_session_timeout',  isset($_POST['session_timeout'])  ? '1' : '0');
        save_setting($pdo, 'sec_password_expiry',  isset($_POST['password_expiry'])  ? '1' : '0');
        set_flash('Settings saved.'); header("Location: settings"); exit;
    }

    // What patients see in their portal.
    if ($action === 'save_portal') {
        save_setting($pdo, 'patient_chart_visible', isset($_POST['patient_chart_visible']) ? '1' : '0');
        log_activity($pdo, 'Changed patient portal settings',
                     'Dental chart shown to patients: ' . (isset($_POST['patient_chart_visible']) ? 'yes' : 'no'));
        set_flash('Settings saved.'); header("Location: settings"); exit;
    }

    // Clinic OPEN DAYS + HOURS -- the online booking page reads these to know
    // which days/times patients are allowed to book.
    if ($action === 'save_hours') {
        $days = (isset($_POST['days']) && is_array($_POST['days'])) ? implode(',', $_POST['days']) : '';
        save_setting($pdo, 'clinic_open_days',  $days);
        save_setting($pdo, 'clinic_open_time',  $_POST['open_time']);
        save_setting($pdo, 'clinic_close_time', $_POST['close_time']);
        log_activity($pdo, 'Updated clinic hours', "Open: $days, $_POST[open_time]-$_POST[close_time]");
        set_flash('Settings saved.'); header("Location: settings"); exit;
    }

    // Google Sign-In credentials (created in the Google Cloud Console).
    if ($action === 'save_google') {
        save_setting($pdo, 'google_client_id', trim($_POST['google_client_id']));
        // Blank secret = keep the one already saved.
        if (trim($_POST['google_client_secret'] ?? '') !== '') {
            save_setting($pdo, 'google_client_secret', trim($_POST['google_client_secret']));
        }
        set_flash('Google Sign-In settings saved.');
        header("Location: settings"); exit;
    }

    // Change the logged-in user's OWN password (works for admin, dentist, staff).
    // ---- Update the logged-in user's own profile info (name, contact) ----
    if ($action === 'save_profile') {
        $uid = $_SESSION['user_id'];
        $newName = trim($_POST['my_name'] ?? '');
        [$newContact, $phoneError] = validate_phone($_POST['my_contact'] ?? '', false);
        if ($phoneError !== '') {
            set_flash($phoneError, 'error');
        } elseif ($newName !== '') {
            $pdo->prepare("UPDATE users SET name=?, contact=? WHERE id=?")
                ->execute([$newName, $newContact, $uid]);
            $_SESSION['name'] = $newName;   // keep the top-right widget in sync
            log_activity($pdo, 'Updated profile', 'Own profile');
            set_flash('Your profile was updated.');
        } else {
            set_flash('Name cannot be empty.', 'error');
        }
        header("Location: settings?view=profile"); exit;
    }

    // ---- Upload a profile picture (same feature patients have) ----
    if ($action === 'upload_avatar') {
        $dir = __DIR__ . '/uploads/avatars';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                $old = $pdo->prepare("SELECT photo FROM users WHERE id=?");
                $old->execute([$_SESSION['user_id']]);
                $oldPath = $old->fetchColumn();
                if ($oldPath && is_file(__DIR__ . '/' . $oldPath)) @unlink(__DIR__ . '/' . $oldPath);

                $fname = 'user_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], "$dir/$fname")) {
                    $pdo->prepare("UPDATE users SET photo=? WHERE id=?")
                        ->execute(['uploads/avatars/' . $fname, $_SESSION['user_id']]);
                    log_activity($pdo, 'Changed profile picture', 'Own account');
                    set_flash('Profile picture updated.');
                } else { set_flash('Could not save the picture.', 'error'); }
            } else { set_flash('Please choose an image (jpg, png, gif, webp).', 'error'); }
        } else { set_flash('Please choose a picture first.', 'error'); }
        header("Location: settings?view=profile"); exit;
    }

    // ---- Remove the profile picture ----
    if ($action === 'remove_avatar') {
        $old = $pdo->prepare("SELECT photo FROM users WHERE id=?");
        $old->execute([$_SESSION['user_id']]);
        $oldPath = $old->fetchColumn();
        if ($oldPath && is_file(__DIR__ . '/' . $oldPath)) @unlink(__DIR__ . '/' . $oldPath);
        $pdo->prepare("UPDATE users SET photo=NULL WHERE id=?")->execute([$_SESSION['user_id']]);
        log_activity($pdo, 'Removed profile picture', 'Own account');
        set_flash('Profile picture removed.', 'info');
        header("Location: settings?view=profile"); exit;
    }

    // Step 1: check the passwords, then send a code (see pw_change_start()).
    if ($action === 'change_password') {
        $pwError = pw_change_start($pdo, $_POST['current_password'] ?? '', $_POST['new_password'] ?? '', $_POST['confirm_password'] ?? '');
        if ($pwError === '') { header("Location: settings?view=profile#pw-card"); exit; }
        // otherwise fall through and show the error below
    }
    // Step 2: the right code changes the password.
    if ($action === 'verify_password_code') {
        $pwError = pw_change_finish($pdo, $_POST['code'] ?? '');
        if ($pwError === '') { set_flash('Your password has been changed.'); header("Location: settings?view=profile"); exit; }
    }
    if ($action === 'cancel_password_change') {
        unset($_SESSION['pw_change']);
        set_flash('Password change cancelled. Your password stays the same.', 'info');
        header("Location: settings?view=profile"); exit;
    }
}

// ---------- Read all settings into one easy array ----------
// e.g. $cfg['clinic_name']  ->  "St. Therese of Carmel Dental Clinic"
$cfg = [];
foreach ($pdo->query("SELECT setting_key, setting_value FROM settings") as $row) {
    $cfg[$row['setting_key']] = $row['setting_value'];
}
// helper so missing keys don't cause errors
function cfg($cfg, $key, $default = '') { return $cfg[$key] ?? $default; }

$isAdmin = (current_role() === 'admin');
// The admin's own profile + password live under the profile menu ("Profile & Settings"
// -> settings?view=profile); the System tab only shows clinic-wide settings.
// Dentists and staff have no System settings, so they always see their profile.
$profileView = !$isAdmin || ($_GET['view'] ?? '') === 'profile'
            || in_array($_POST['action'] ?? '', ['save_profile','upload_avatar','remove_avatar','change_password','verify_password_code'], true);

// The logged-in user's own account (for the My Profile card).
$meStmt = $pdo->prepare("SELECT name, email, contact, photo, role FROM users WHERE id=?");
$meStmt->execute([$_SESSION['user_id'] ?? 0]);
$meUser = $meStmt->fetch() ?: ['name'=>'','email'=>'','contact'=>'','photo'=>'','role'=>''];
$myHasPhoto = !empty($meUser['photo']) && is_file(__DIR__ . '/' . $meUser['photo']);
$myInitial  = strtoupper(substr(trim($meUser['name']), 0, 1)) ?: 'U';

$page_title = $profileView ? "My Profile & Settings" : "System Settings";
include 'includes/head.php';
$active = ($isAdmin && $profileView) ? '' : 'settings';   // the profile page is not the System menu item
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div>
                <h1 style="color:var(--teal-light)"><?= $profileView ? 'My Profile &amp; Settings' : 'System Settings' ?></h1>
                <div class="sub"><?= $profileView ? 'Your own account: picture, name, contact and password' : 'Clinic info, hours, security and sign-in' ?></div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="clock"><span class="time" id="clock">--:--</span><br><span id="clock-date"></span></div>
            </div>
        </div>

        <?php if ($isAdmin && !$profileView) include 'includes/admin_tabs.php'; ?>

        <?php if ($profileView): ?>
        <!-- ===== MY PROFILE (same feature patients have) ===== -->
        <div class="card-box mb-3">
            <h5 class="mb-3">👤 My Profile</h5>
            <div class="row g-4">
                <!-- Profile picture -->
                <div class="col-lg-5">
                    <div class="text-muted2 mb-2" style="font-size:.82rem;font-weight:600;">Profile Picture</div>
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <?php if ($myHasPhoto): ?>
                            <img src="<?= e($meUser['photo']) ?>" alt="Profile picture"
                                 style="width:88px;height:88px;border-radius:50%;object-fit:cover;border:3px solid var(--teal-light);">
                        <?php else: ?>
                            <span class="avatar" style="width:88px;height:88px;font-size:2rem;background:var(--teal);">
                                <?= e($myInitial) ?>
                            </span>
                        <?php endif; ?>
                        <div style="flex:1;min-width:210px;">
                            <form method="POST" enctype="multipart/form-data" class="mb-2">
                                <input type="hidden" name="action" value="upload_avatar">
                                <div class="d-flex gap-2">
                                    <input type="file" name="avatar" class="form-control form-control-sm" accept="image/*" required>
                                    <button class="btn btn-sm btn-teal" style="white-space:nowrap;">⬆ Upload</button>
                                </div>
                            </form>
                            <?php if ($myHasPhoto): ?>
                                <form method="POST" onsubmit="return confirm('Remove your profile picture?')">
                                    <input type="hidden" name="action" value="remove_avatar">
                                    <button class="btn btn-sm btn-light" style="color:#c0392b;">🗑 Remove picture</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Personal info -->
                <div class="col-lg-7">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_profile">
                        <div class="text-muted2 mb-2" style="font-size:.82rem;font-weight:600;">Personal Information</div>
                        <div class="row">
                            <div class="col-md-6">
                                <label class="field-label">Full Name</label>
                                <input name="my_name" class="form-control mb-3" value="<?= e($meUser['name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="field-label">Contact Number</label>
                                <input name="my_contact" class="form-control mb-3" value="<?= e($meUser['contact']) ?>" placeholder="09XX XXX XXXX" <?= phone_input_attrs() ?>>
                            </div>
                        </div>
                        <label class="field-label">Email <span class="text-muted2">(read-only)</span></label>
                        <input class="form-control mb-1" value="<?= e($meUser['email']) ?>" readonly style="background:#eef7f6;color:var(--teal);">
                        <div class="text-muted2 mb-3" style="font-size:.76rem;">Role: <strong><?= e(ucfirst($meUser['role'])) ?></strong></div>
                        <button class="btn btn-teal">💾 Save Profile</button>
                    </form>
                </div>
            </div>
        </div>

        <?php endif; /* $profileView */ ?>

        <?php if ($isAdmin && !$profileView): /* Clinic info, security, and backup are admin-only */ ?>
        <div class="row g-3">
            <!-- ===== Clinic Information ===== -->
            <div class="col-lg-6">
                <div class="card-box h-100">
                    <h5 class="mb-3">🏥 Clinic Information</h5>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_clinic">

                        <label class="field-label">Clinic Name</label>
                        <input name="clinic_name" class="form-control mb-3" value="<?= e(cfg($cfg,'clinic_name')) ?>">

                        <input name="clinic_tagline" class="form-control mb-3" value="<?= e(cfg($cfg,'clinic_tagline')) ?>" placeholder="Tagline">

                        <div class="row">
                            <div class="col-md-6">
                                <label class="field-label">Phone</label>
                                <input name="clinic_phone" class="form-control mb-3" value="<?= e(preg_replace('/\D/', '', (string)cfg($cfg,'clinic_phone'))) ?>" type="tel" inputmode="numeric" data-digits maxlength="11" placeholder="0461234567 or 09171234567">
                            </div>
                            <div class="col-md-6">
                                <label class="field-label">Email</label>
                                <input name="clinic_email" class="form-control mb-3" value="<?= e(cfg($cfg,'clinic_email')) ?>">
                            </div>
                        </div>

                        <label class="field-label">Address</label>
                        <input name="clinic_address" class="form-control mb-3" value="<?= e(cfg($cfg,'clinic_address')) ?>">

                        <label class="field-label">Operating Hours</label>
                        <input name="operating_hours" class="form-control mb-3" value="<?= e(cfg($cfg,'operating_hours')) ?>">

                        <button class="btn btn-teal w-100">💾 Save Changes</button>
                    </form>
                </div>
            </div>

            <!-- ===== Security & Access ===== -->
            <div class="col-lg-6">
                <div class="card-box h-100">
                    <h5 class="mb-3">🔒 Security & Access</h5>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_security">

                        <div class="flex-between py-2 border-bottom">
                            <div>
                                <strong>Login Audit Log</strong>
                                <div class="text-muted2" style="font-size:.8rem;">Record all login attempts</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="login_audit" <?= cfg($cfg,'sec_login_audit','1')==='1'?'checked':'' ?>>
                            </div>
                        </div>

                        <div class="flex-between py-2 border-bottom">
                            <div>
                                <strong>Session Timeout</strong>
                                <div class="text-muted2" style="font-size:.8rem;">Auto-logout after 30 minutes</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="session_timeout" <?= cfg($cfg,'sec_session_timeout','0')==='1'?'checked':'' ?>>
                            </div>
                        </div>

                        <div class="flex-between py-2 mb-3">
                            <div>
                                <strong>Password Expiry</strong>
                                <div class="text-muted2" style="font-size:.8rem;">Force password reset every 90 days</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="password_expiry" <?= cfg($cfg,'sec_password_expiry','1')==='1'?'checked':'' ?>>
                            </div>
                        </div>

                        <button class="btn btn-teal w-100">💾 Save Settings</button>
                    </form>
                </div>
            </div>
        </div>

        <?php endif; /* end admin-only clinic/security/backup */ ?>

        <?php if ($isAdmin && !$profileView): ?>
        <!-- ===== Clinic Hours & Open Days (controls what the booking page allows) ===== -->
        <?php
            $openDays  = cfg($cfg, 'clinic_open_days', 'Mon,Tue,Wed,Thu,Fri,Sat');
            $openDaysArr = array_map('trim', explode(',', $openDays));
            $allDays = ['Mon'=>'Monday','Tue'=>'Tuesday','Wed'=>'Wednesday','Thu'=>'Thursday','Fri'=>'Friday','Sat'=>'Saturday','Sun'=>'Sunday'];
        ?>
        <!-- ===== Patient Portal: what patients can see ===== -->
        <div class="card-box mt-3">
            <h5 class="mb-1">👤 Patient Portal</h5>
            <div class="text-muted2 mb-2" style="font-size:.85rem;">What patients can see in their own account.</div>
            <form method="POST">
                <input type="hidden" name="action" value="save_portal">
                <div class="flex-between py-2 border-bottom gap-3">
                    <div>
                        <strong>Show the dental chart (odontogram) to patients</strong>
                        <div class="text-muted2" style="font-size:.8rem;">When off, patients do not see the tooth chart. They still see a
                            plain-words <b>Dental Summary</b> in My Records (e.g. “Tooth 14 — has a cavity”) and can
                            <b>request a printed copy</b>, which you print from Generate Reports → Patient Profile.</div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="patient_chart_visible" <?= cfg($cfg,'patient_chart_visible','1')!=='0'?'checked':'' ?>>
                    </div>
                </div>
                <button class="btn btn-teal mt-3" data-keep-text>💾 Save</button>
            </form>
        </div>

        <div class="card-box mt-3">
            <h5 class="mb-1">🗓️ Clinic Hours &amp; Open Days</h5>
            <div class="text-muted2 mb-3" style="font-size:.85rem;">Patients can only book on the days and within the hours you set here.</div>
            <form method="POST">
                <input type="hidden" name="action" value="save_hours">

                <label class="field-label">Open Days</label>
                <div class="d-flex flex-wrap gap-3 mb-3">
                    <?php foreach ($allDays as $short => $long): ?>
                        <label class="d-flex align-items-center gap-1" style="cursor:pointer;">
                            <input type="checkbox" name="days[]" value="<?= $short ?>" <?= in_array($short, $openDaysArr)?'checked':'' ?>>
                            <?= $long ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="row" style="max-width:420px;">
                    <div class="col">
                        <label class="field-label">Opening Time</label>
                        <input type="time" name="open_time" class="form-control mb-3" value="<?= e(cfg($cfg,'clinic_open_time','09:00')) ?>">
                    </div>
                    <div class="col">
                        <label class="field-label">Closing Time</label>
                        <input type="time" name="close_time" class="form-control mb-3" value="<?= e(cfg($cfg,'clinic_close_time','17:00')) ?>">
                    </div>
                </div>

                <button class="btn btn-teal">💾 Save Clinic Hours</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
        <!-- ===== Google Sign-In ===== -->
        <?php
            $redirectUri = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                            ? 'https' : 'http')
                         . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                         . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/google_auth.php';
        ?>
        <div class="card-box mt-3">
            <h5 class="mb-1">🔐 Google Sign-In (log in with Gmail)</h5>
            <div class="text-muted2 mb-3" style="font-size:.85rem;">
                When these are filled in, a <strong>“Continue with Google”</strong> button appears on the login page.
            </div>

            <div class="alert" style="background:#fff6e0;border:1px solid var(--gold);color:#8a6d2f;font-size:.8rem;">
                <strong>Set-up (one time):</strong>
                <ol class="mb-1 ps-3 mt-1">
                    <li>Open <strong>console.cloud.google.com</strong> → create a project.</li>
                    <li><em>APIs &amp; Services → OAuth consent screen</em> → External → fill the basics.</li>
                    <li><em>Credentials → Create Credentials → OAuth client ID → Web application</em>.</li>
                    <li>Under <strong>Authorised redirect URIs</strong> paste exactly:</li>
                </ol>
                <code style="display:block;background:#fff;padding:6px 10px;border-radius:6px;margin:6px 0;"><?= e($redirectUri) ?></code>
                Then copy the Client ID + Secret into the boxes below.
            </div>

            <form method="POST" style="max-width:560px;">
                <input type="hidden" name="action" value="save_google">
                <label class="field-label">Client ID</label>
                <input name="google_client_id" class="form-control mb-3" value="<?= e(cfg($cfg,'google_client_id','')) ?>" placeholder="xxxxxxxx.apps.googleusercontent.com">

                <label class="field-label">Client Secret</label>
                <input type="password" name="google_client_secret" class="form-control mb-1"
                       placeholder="<?= cfg($cfg,'google_client_secret','')!=='' ? 'Saved — leave blank to keep it' : 'GOCSPX-...' ?>">
                <div class="text-muted2 mb-3" style="font-size:.78rem;">
                    <?= cfg($cfg,'google_client_secret','')!=='' ? '✅ A secret is saved.' : '⚠️ No secret saved yet — Google Sign-In is off.' ?>
                </div>
                <button class="btn btn-teal">💾 Save Google Settings</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($profileView): ?>
        <!-- ===== Change My Password (any logged-in user) ===== -->
        <div class="card-box mt-3" id="pw-card">
            <h5 class="mb-1">🔑 Change My Password</h5>
            <div class="text-muted2 mb-3" style="font-size:.85rem;">Update the password for your own account (<?= e($_SESSION['name'] ?? '') ?>).</div>
            <?php if ($pwError): ?><div class="alert alert-danger py-2"><?= e($pwError) ?></div><?php endif; ?>
            <?php if (!empty($_SESSION['pw_change'])): ?>
                <?= pw_change_box() ?>
            <?php else: ?>
            <form method="POST" style="max-width:420px;">
                <input type="hidden" name="action" value="change_password">

                <label class="field-label">Current Password</label>
                <div class="pw-wrap mb-3">
                    <input type="password" name="current_password" class="form-control" required>
                    <button type="button" class="pw-eye" aria-label="Show password">
                        <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>

                <label class="field-label">New Password</label>
<div class="pw-wrap mb-1">
                    <input type="password" name="new_password" class="form-control" required minlength="8" data-pw-meter>
                    <button type="button" class="pw-eye" aria-label="Show password">
                        <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
                <div class="text-muted2 mb-3" style="font-size:.78rem;">Strong: 8+ characters with upper- &amp; lower-case letters, a number and a symbol.</div>

                <label class="field-label">Confirm New Password</label>
<div class="pw-wrap mb-3">
                    <input type="password" name="confirm_password" class="form-control" required>
                    <button type="button" class="pw-eye" aria-label="Show password">
                        <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>

                <button class="btn btn-teal">🔑 Update Password</button>
                <div class="text-muted2 mt-2" style="font-size:.78rem;">We'll send a code to your email to confirm the change.</div>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; /* $profileView */ ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
<script>startClock();</script>
</body></html>
