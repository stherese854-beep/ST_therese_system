<?php
// ============================================================
//  LOGIN + REGISTER  (login.php)  -- this is the FIRST page
// ============================================================
require_once 'config/auth.php';
require_once 'includes/mailer.php';   // to email the verification code

// If already logged in, skip the login page.
if (is_logged_in()) { header("Location: dashboard.php"); exit; }

$error = '';
$emailTaken = false;    // true clears just the email field on re-render (see register handler)
$deletionNotice = '';   // '' | 'archived' | 'permadeleted' -- which popup to show, if any
$mode  = $_GET['mode'] ?? 'signin';   // 'signin', 'register' or 'verify'

// Is Google Sign-In set up? (admin adds the keys in Settings)
$googleReady = false;
try {
    $g = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='google_client_id'")->fetchColumn();
    $googleReady = !empty($g);
} catch (Throwable $e) {}

// Left-panel branding text — editable by the admin in "Edit Landing Page"
// (falls back to the default wording if never changed).
$authIcon     = '🦷';
$authTitle1   = 'Your Smile,';
$authTitle2   = 'Remembered.';
$authSubtitle = 'Sign in to access your records, appointments, and dental chart.';
try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM settings
                          WHERE setting_key IN ('land_auth_icon','land_auth_title1','land_auth_title2','land_auth_subtitle')")->fetchAll();
    foreach ($rows as $r) {
        if ($r['setting_value'] === '' || $r['setting_value'] === null) continue;
        if ($r['setting_key'] === 'land_auth_icon')     $authIcon     = $r['setting_value'];
        if ($r['setting_key'] === 'land_auth_title1')   $authTitle1   = $r['setting_value'];
        if ($r['setting_key'] === 'land_auth_title2')   $authTitle2   = $r['setting_value'];
        if ($r['setting_key'] === 'land_auth_subtitle') $authSubtitle = $r['setting_value'];
    }
} catch (Throwable $e) {}

// ---------- Handle the form when it is submitted ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ===== SIGN IN =====
    if (($_POST['action'] ?? '') === 'signin') {
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';

        // Find the user by email.
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // password_verify checks the typed password against the stored hash.
        if ($user && password_verify($pass, $user['password'])) {
            if ($user['status'] === 'archived') {
                // The account still exists (nothing was deleted yet) but an
                // admin moved it to the Archive — let them know plainly.
                $deletionNotice = 'archived';
            } elseif ($user['status'] !== 'active') {
                $error = "This account is inactive. Please contact the clinic.";
            } else {
                // Save who is logged in inside the session.
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name']    = $user['name'];
                $_SESSION['role']    = $user['role'];

                // A blank last_login means this is the very first time this
                // account has ever signed in — greet them as "Welcome" instead
                // of "Welcome back" (matches how a brand-new patient is greeted).
                $isFirstLogin = empty($user['last_login']);

                // Update last login time.
                $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
                    ->execute([$user['id']]);
                log_activity($pdo, 'Logged in', ucfirst($user['role']));

                // A one-time pop-up greeting, shown once right after logging in
                // (the patient portal and the staff/dentist/admin dashboard each
                // show their own version of it, then it clears itself).
                $_SESSION['show_welcome_popup'] = true;
                $_SESSION['just_registered']    = $isFirstLogin;

                // Patients go to the patient portal; everyone else to the dashboard.
                if ($user['role'] === 'patient') {
                    header("Location: portal.php");
                } else {
                    header("Location: dashboard.php");
                }
                exit;
            }
        } elseif (!$user && $email !== '') {
            // No live account under that email — but it may have been
            // permanently deleted from the Archive. Check the log so we can
            // tell the person what actually happened instead of just saying
            // "wrong email or password".
            $log = $pdo->prepare("SELECT id FROM deleted_accounts_log WHERE email = ? ORDER BY deleted_at DESC LIMIT 1");
            $log->execute([$email]);
            if ($log->fetch()) {
                $deletionNotice = 'permadeleted';
            } else {
                $error = "Wrong email or password.";
            }
        } else {
            $error = "Wrong email or password.";
        }
    }

    // ===== REGISTER (step 1): validate, then send a verification code =====
    if (($_POST['action'] ?? '') === 'register') {
        // Build the full name from separate First + Last name fields.
        $first = trim($_POST['first_name'] ?? '');
        $last  = trim($_POST['last_name'] ?? '');
        $name  = trim($first . ' ' . $last);
        $email = trim($_POST['email'] ?? '');
        $phone = preg_replace('/[^0-9]/', '', trim($_POST['contact'] ?? ''));   // numbers only
        $pass  = $_POST['password'] ?? '';
        $pass2 = $_POST['confirm_password'] ?? '';
        $dob   = trim($_POST['dob'] ?? '');
        $mode  = 'register';

        // ---- Password checks (server-side, so they cannot be bypassed) ----
        // Only Medium and Strong passwords are accepted.
        if ($pass !== $pass2) {
            $error = "The passwords do not match. Please re-type them.";
        } elseif (strlen($pass) < 4) {
            $error = "Your password must be at least 4 characters.";
        } elseif (password_strength($pass) === 'weak') {
            $error = "That password is too weak. Please choose a Medium or Strong password.";
        } elseif ($phone === '') {
            $error = "Please enter your phone number.";
        } elseif (strlen($phone) < 10) {
            $error = "Please enter a real, complete phone number (at least 10 digits).";
        }

        // ---- AGE CHECK: account holders must be 18 or older ----
        // (A child can still be a patient — a parent/guardian makes the account
        //  and books for them using "booking for someone else".)
        $age = null;
        if (!$error) {
        if ($dob === '') {
            $error = "Please enter your date of birth.";
        } else {
            $birth = date_create($dob);
            $today = date_create('today');
            if (!$birth || $birth > $today) {
                $error = "Please enter a valid date of birth.";
            } else {
                $age = (int) date_diff($birth, $today)->y;
                if ($age < 18) {
                    $error = "You must be at least 18 years old to create an account. "
                           . "If you are under 18, please ask a parent or guardian to make the account "
                           . "and book the appointment for you.";
                }
            }
        }
        }

        // Check the email is not already used.
        if (!$error) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($name === '') {
            $error = "Please enter your first and last name.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif ($stmt->fetch()) {
            // Only the email (and the password, which is never re-filled) is
            // cleared — the rest of what they typed is kept so they don't
            // have to retype it, they just need a different email address.
            $error = "That email is already registered.";
            $emailTaken = true;
        } elseif (strlen($pass) < 4) {
            $error = "Password must be at least 4 characters.";
        } else {
            // Make a 6-digit verification code and remember the pending sign-up.
            $code = (string) random_int(100000, 999999);
            $_SESSION['pending'] = [
                'name'  => $name,
                'email' => $email,
                'phone' => $phone,
                'hash'  => password_hash($pass, PASSWORD_DEFAULT),
                'code'  => $code,
                'sent'  => false,
                'dob'   => $dob,
                'age'   => $age,
            ];

            // Try to EMAIL the code for real. If the admin has set up SMTP in
            // Messaging Config, the patient receives it in their inbox and the
            // code is never shown on screen. If not, we fall back to demo mode.
            $body = mail_template(
                'Verify your email',
                'Hi ' . e($first) . ', thanks for registering with St. Therese Dental Clinic.<br><br>
                 Your verification code is:
                 <div style="font-size:30px;font-weight:700;letter-spacing:8px;color:#0f766e;
                             background:#eef7f6;border-radius:10px;padding:14px;text-align:center;margin:14px 0;">'
                 . $code . '</div>
                 Enter this code on the verification page to activate your account.
                 If you did not request this, you can ignore this email.'
            );
            $mailErr = '';
            if (send_mail($pdo, $email, 'Your verification code: ' . $code, $body, $mailErr)) {
                $_SESSION['pending']['sent'] = true;
                set_flash("We've emailed a 6-digit verification code to $email.", 'info');
            } else {
                set_flash("Enter the verification code to continue.", 'info');
            }
            header("Location: login.php?mode=verify");
            exit;
        }
        }   // end of the age-check guard
    }

    // ===== VERIFY (step 2): check the code, then create the account =====
    if (($_POST['action'] ?? '') === 'verify') {
        $mode    = 'verify';
        $entered = trim($_POST['code'] ?? '');

        if (empty($_SESSION['pending'])) {
            $error = "Your sign-up session expired. Please register again.";
            $mode  = 'register';
        } elseif ($entered !== $_SESSION['pending']['code']) {
            $error = "Incorrect verification code. Please try again.";
        } else {
            $p = $_SESSION['pending'];
            $pdo->prepare("INSERT INTO users (name,email,password,role,status,email_verified,contact) VALUES (?,?,?,'patient','active',1,?)")
                ->execute([$p['name'], $p['email'], $p['hash'], $p['phone'] ?? null]);
            $newId = $pdo->lastInsertId();

            // Also create a matching patient record + auto-assign a dentist.
            $assignedDentist = pick_dentist_for_new_patient($pdo);
            $pdo->prepare("INSERT INTO patients (user_id,name,email,phone,patient_type,status,primary_dentist,date_of_birth,age) VALUES (?,?,?,?,'New','Active',?,?,?)")
                ->execute([$newId, $p['name'], $p['email'], $p['phone'] ?? null, $assignedDentist,
                           $p['dob'] ?: null, $p['age'] ?? null]);

            unset($_SESSION['pending']);
            $_SESSION['user_id'] = $newId;
            $_SESSION['name']    = $p['name'];
            $_SESSION['role']    = 'patient';
            $_SESSION['just_registered']    = true;   // portal.php greets them differently, once
            $_SESSION['show_welcome_popup'] = true;   // portal.php shows a one-time pop-up greeting
            log_activity($pdo, 'Created account', $p['name'] . ' (patient, self-registered)');
            set_flash('Email verified — welcome, ' . $p['name'] . '!');
            header("Location: portal.php");
            exit;
        }
    }
}

$page_title = "Login";
include 'includes/head.php';
?>
<style>
/* ── Compact auth card so it fits without feeling oversized ── */
.auth-card              { padding: 20px 24px !important; max-width: 400px !important; }
.auth-card h2           { font-size: 1.3rem !important; margin-bottom: 2px !important; }
.auth-card .muted       { font-size: .82rem !important; margin-bottom: 12px !important; }
.auth-card .field-label { margin-bottom: 2px !important; }
.auth-card .form-control,
.auth-card .form-select { padding: .4rem .7rem !important; font-size: .88rem !important; }
.auth-card .mb-3        { margin-bottom: .65rem !important; }
.auth-card .mb-1        { margin-bottom: .3rem !important; }
.auth-tabs              { margin-bottom: 12px !important; padding: 4px !important; }
.auth-tabs button       { padding: 6px !important; font-size: .86rem !important; }
.auth-card .pw-strength,
#pw-strength            { margin: -4px 0 10px !important; }
</style>

<div class="auth-page">
    <!-- Close (X) button: goes back to the landing page -->
    <a href="index.php" class="auth-close" aria-label="Back to homepage" title="Back to homepage">&times;</a>

    <!-- LEFT: brand panel -->
    <div class="auth-left">
      <div class="auth-brand">
        <div class="tooth"><?= e($authIcon) ?></div>
        <h1><?= e($authTitle1) ?><br><span class="gold"><?= e($authTitle2) ?></span></h1>
        <p><?= e($authSubtitle) ?></p>
      </div>
    </div>

    <!-- RIGHT: the form card -->
    <div class="auth-right">
        <div class="auth-card">
            <div class="d-flex align-items-center gap-2 mb-3">
                <div class="logo" style="width:34px;height:34px;border-radius:8px;background:var(--teal);color:#fff;display:flex;align-items:center;justify-content:center;">🦷</div>
                <strong style="color:var(--teal)">St. Therese Dental Clinic</strong>
            </div>

            <!-- Tabs to switch between Login and Create Account -->
            <div class="auth-tabs">
                <button class="<?= $mode==='signin'?'active':'' ?>" onclick="location.href='login.php?mode=signin'">Login</button>
                <button class="<?= $mode==='register'?'active':'' ?>" onclick="location.href='login.php?mode=register'">Create Account</button>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($mode === 'signin'): ?>
                <!-- ============ LOGIN FORM ============ -->
                <h2>Welcome back</h2>
                <p class="muted">Enter your credentials to continue.</p>
                <form method="POST" action="login.php">
                    <input type="hidden" name="action" value="signin">
                    <label class="field-label">Email Address</label>
                    <input type="email" name="email" class="form-control mb-3" placeholder="your@email.com" required>

                    <label class="field-label">Password</label>
<div class="pw-wrap mb-3">
                    <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                    <button type="button" class="pw-eye" aria-label="Show password">
                        <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>

                    <div class="text-end" style="margin-top:-8px;margin-bottom:12px;">
                        <a href="forgot_password.php" style="color:var(--teal);font-size:.85rem;text-decoration:none;">Forgot password?</a>
                    </div>

                    <button type="submit" class="btn btn-teal w-100 py-2">Login →</button>
                </form>

                <?php if ($googleReady): ?>
                    <div class="d-flex align-items-center gap-2 my-3">
                        <div style="flex:1;height:1px;background:#e3e9ee;"></div>
                        <span class="muted" style="font-size:.8rem;">or</span>
                        <div style="flex:1;height:1px;background:#e3e9ee;"></div>
                    </div>
                    <a href="google_auth.php" class="btn w-100 py-2 d-flex align-items-center justify-content-center gap-2"
                       style="border:1px solid #dadce0;background:#fff;color:#3c4043;font-weight:600;">
                        <svg width="18" height="18" viewBox="0 0 48 48">
                            <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.6l6.7-6.7C35.6 2.6 30.2 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.8 6.1C12.3 13.2 17.7 9.5 24 9.5z"/>
                            <path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.2-.4-4.7H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8c4.4-4 6.9-10 6.9-17.3z"/>
                            <path fill="#FBBC05" d="M10.4 28.7c-.5-1.5-.8-3-.8-4.7s.3-3.2.8-4.7l-7.8-6.1C1 16.4 0 20.1 0 24s1 7.6 2.6 10.8l7.8-6.1z"/>
                            <path fill="#34A853" d="M24 48c6.5 0 12-2.1 16-5.8l-7.5-5.8c-2.1 1.4-4.8 2.2-8.5 2.2-6.3 0-11.7-3.7-13.6-9.9l-7.8 6.1C6.5 42.6 14.6 48 24 48z"/>
                        </svg>
                        Continue with Google
                    </a>
                <?php endif; ?>
                <p class="text-center mt-3 muted">Don't have an account?
                    <a href="login.php?mode=register" style="color:var(--teal);font-weight:600;">Create one now →</a>
                </p>
                <div class="alert alert-light border mt-2 py-2 text-center" style="font-size:.85rem;">
                    🔒 An account is required to book an appointment.
                </div>

            <?php elseif ($mode === 'verify'): ?>
                <!-- ============ VERIFY EMAIL ============ -->
                <h2>Verify your email</h2>
                <p class="muted">Enter the 6-digit code we sent to <strong><?= e($_SESSION['pending']['email'] ?? 'your email') ?></strong>.</p>
                <?php if (!empty($_SESSION['pending']['sent'])): ?>
                    <div class="alert alert-success py-2" style="font-size:.85rem;">
                        📧 Code emailed. Check your inbox (and the spam folder).
                    </div>
                <?php else: ?>
                    <div class="alert" style="background:#fff6e0;border:1px solid var(--gold);color:#8a6d2f;font-size:.83rem;">
                        🧪 <strong>Demo mode:</strong> your code is <strong style="font-size:1.1rem;letter-spacing:2px;"><?= e($_SESSION['pending']['code'] ?? '------') ?></strong>.
                        Email is not set up yet, so it is shown here. An admin can turn on real email in
                        <em>Messaging Config</em>.
                    </div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="action" value="verify">
                    <label class="field-label">Verification Code</label>
                    <input name="code" class="form-control mb-3" placeholder="123456" maxlength="6" inputmode="numeric" autofocus required
                           style="letter-spacing:6px;font-size:1.3rem;text-align:center;">
                    <button type="submit" class="btn btn-teal w-100 py-2">Verify &amp; Create Account →</button>
                </form>
                <p class="text-center mt-3 muted"><a href="login.php?mode=register" style="color:var(--teal);">← Back to register</a></p>

            <?php else: ?>
                <!-- ============ REGISTER FORM ============ -->
                <h2>Create account</h2>
                <p class="muted">Register as a patient to book appointments.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="register">
                    <div class="row">
                        <div class="col"><label class="field-label">First Name</label>
                            <input type="text" name="first_name" class="form-control mb-3" placeholder="Juan" value="<?= e($_POST['first_name'] ?? '') ?>" required></div>
                        <div class="col"><label class="field-label">Last Name</label>
                            <input type="text" name="last_name" class="form-control mb-3" placeholder="dela Cruz" value="<?= e($_POST['last_name'] ?? '') ?>" required></div>
                    </div>

                    <label class="field-label">Email Address</label>
                    <input type="email" name="email" class="form-control mb-3" placeholder="your@email.com" value="<?= $emailTaken ? '' : e($_POST['email'] ?? '') ?>" <?= $emailTaken ? 'autofocus' : '' ?> required>

                    <label class="field-label">Phone Number</label>
                    <input type="text" name="contact" id="reg-phone" class="form-control mb-1" placeholder="09XX XXX XXXX" value="<?= e($_POST['contact'] ?? '') ?>"
                           inputmode="numeric" minlength="10" maxlength="15" oninput="this.value=this.value.replace(/[^0-9]/g,''); document.getElementById('reg-phone-warn').style.display='none';" required>
                    <div id="reg-phone-warn" class="text-danger mb-2" style="display:none;font-size:.82rem;">Please enter a real, complete phone number (at least 10 digits).</div>
                    <div class="mb-2"></div>

                    <label class="field-label">Date of Birth</label>
                    <input type="date" name="dob" class="form-control mb-1" required
                           max="<?= date('Y-m-d', strtotime('-18 years')) ?>"
                           value="<?= e($_POST['dob'] ?? '') ?>">
                    <div class="muted mb-3" style="font-size:.78rem;">
                        You must be <strong>18 or older</strong> to create an account.
                        Under 18? A parent or guardian can make the account and book for you.
                    </div>

                    <label class="field-label">Password</label>
                    <div class="muted mb-1" style="font-size:.74rem;">💡 8+ characters · upper &amp; lower case · a number · a symbol</div>
<div class="pw-wrap mb-3">
                    <input type="password" name="password" id="reg-pass" class="form-control" placeholder="At least 4 characters" required onkeyup="checkStrength()">
                    <button type="button" class="pw-eye" aria-label="Show password">
                        <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>

                    <!-- Password strength meter -->
                    <div id="pw-strength" style="display:none;margin:-8px 0 14px;">
                        <div style="height:6px;border-radius:4px;background:#e9eef0;overflow:hidden;">
                            <div id="pw-strength-bar" style="height:100%;width:0;transition:width .2s,background .2s;"></div>
                        </div>
                        <div id="pw-strength-text" style="font-size:.76rem;margin-top:4px;color:#8aa0a0;"></div>
                    </div>

                    <label class="field-label">Confirm Password</label>
<div class="pw-wrap mb-1">
                    <input type="password" name="confirm_password" id="reg-pass2" class="form-control" placeholder="Re-type your password" required>
                    <button type="button" class="pw-eye" aria-label="Show password">
                        <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
                    <div id="reg-pass-warn" class="text-danger mb-3" style="display:none;font-size:.82rem;">The passwords do not match.</div>

                    <button type="submit" class="btn btn-teal w-100 py-2" onclick="return checkRegPass()">Create Account →</button>
                </form>

                <?php if ($googleReady): ?>
                    <div class="d-flex align-items-center gap-2 my-3">
                        <div style="flex:1;height:1px;background:#e3e9ee;"></div>
                        <span class="muted" style="font-size:.8rem;">or</span>
                        <div style="flex:1;height:1px;background:#e3e9ee;"></div>
                    </div>
                    <a href="google_auth.php" class="btn w-100 py-2 d-flex align-items-center justify-content-center gap-2"
                       style="border:1px solid #dadce0;background:#fff;color:#3c4043;font-weight:600;">
                        <svg width="18" height="18" viewBox="0 0 48 48">
                            <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.6l6.7-6.7C35.6 2.6 30.2 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.8 6.1C12.3 13.2 17.7 9.5 24 9.5z"/>
                            <path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.2-.4-4.7H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8c4.4-4 6.9-10 6.9-17.3z"/>
                            <path fill="#FBBC05" d="M10.4 28.7c-.5-1.5-.8-3-.8-4.7s.3-3.2.8-4.7l-7.8-6.1C1 16.4 0 20.1 0 24s1 7.6 2.6 10.8l7.8-6.1z"/>
                            <path fill="#34A853" d="M24 48c6.5 0 12-2.1 16-5.8l-7.5-5.8c-2.1 1.4-4.8 2.2-8.5 2.2-6.3 0-11.7-3.7-13.6-9.9l-7.8 6.1C6.5 42.6 14.6 48 24 48z"/>
                        </svg>
                        Continue with Google
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($deletionNotice): ?>
<!-- Account-deleted pop-up: shown when the email/password entered belongs
     to an account that was archived (temporary) or permanently deleted. -->
<div class="modal fade" id="deletionModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;overflow:hidden;">
      <div class="modal-body text-center py-4 px-4" style="position:relative;">
        <button type="button" class="btn-close position-absolute" style="top:16px;right:16px;" data-bs-dismiss="modal" aria-label="Close"></button>

        <?php if ($deletionNotice === 'archived'): ?>
          <div style="width:64px;height:64px;background:#fff6e0;border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:1.8rem;">⏳</div>
          <h4 style="color:#8a6d2f;">Account Temporarily Deleted</h4>
          <p class="muted mb-0">Your account has been moved to the Archive by an administrator and can no longer sign in. This is usually temporary — please contact the clinic if you believe this was a mistake.</p>
        <?php else: ?>
          <div style="width:64px;height:64px;background:#fbdcdc;border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:1.8rem;">🗑️</div>
          <h4 style="color:#c0392b;">Account Permanently Deleted</h4>
          <p class="muted mb-0">This account has been permanently deleted and no longer exists. If you believe this was a mistake, please contact the clinic directly.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    new bootstrap.Modal(document.getElementById('deletionModal')).show();
});
</script>
<?php endif; ?>

<script>
// Check the two password boxes match before the register form submits.
// Live password strength meter on the register form. This mirrors
// password_strength() in config/auth.php exactly — only Medium and
// Strong are allowed through; Weak blocks the form.
function passwordStrength(pass){
    var len = pass.length;
    var score = 0;
    if (len >= 8)  score++;
    if (len >= 12) score++;
    if (/[a-z]/.test(pass) && /[A-Z]/.test(pass)) score++;
    if (/\d/.test(pass)) score++;
    if (/[^A-Za-z0-9]/.test(pass)) score++;

    if (len < 8 || score <= 2) return 'weak';
    if (score === 3) return 'medium';
    return 'strong';
}

function checkStrength(){
    var pass = document.getElementById('reg-pass').value;
    var box  = document.getElementById('pw-strength');
    var bar  = document.getElementById('pw-strength-bar');
    var txt  = document.getElementById('pw-strength-text');
    if (!pass) { box.style.display = 'none'; return; }
    box.style.display = 'block';

    var level = passwordStrength(pass);
    var levels = {
        weak:   {w:'33%',  c:'#e05b5b', t:'Weak — not allowed, please make it stronger'},
        medium: {w:'66%',  c:'#e0a92e', t:'Medium — good to go'},
        strong: {w:'100%', c:'#34d399', t:'Strong — good to go'}
    };
    var lvl = levels[level];
    bar.style.width = lvl.w;
    bar.style.background = lvl.c;
    txt.textContent = 'Password strength: ' + lvl.t;
    txt.style.color = lvl.c;
}

function checkRegPass(){
  var p1 = document.getElementById('reg-pass').value;
  var p2 = document.getElementById('reg-pass2').value;
  var warn = document.getElementById('reg-pass-warn');

  // A real phone number, not just one or two stray digits.
  var phone = document.getElementById('reg-phone').value;
  var phoneWarn = document.getElementById('reg-phone-warn');
  if (phone.length < 10) {
    phoneWarn.style.display = 'block';
    document.getElementById('reg-phone').focus();
    return false;   // stop the form
  }
  phoneWarn.style.display = 'none';

  if (passwordStrength(p1) === 'weak') {
    warn.textContent = 'That password is too weak — please choose a Medium or Strong password (see the tip above).';
    warn.style.display = 'block';
    document.getElementById('reg-pass').focus();
    return false;   // stop the form
  }
  if (p1 !== p2) {
    warn.textContent = 'The passwords do not match.';
    warn.style.display = 'block';
    document.getElementById('reg-pass2').focus();
    return false;   // stop the form
  }
  warn.style.display = 'none';
  return true;
}
</script>
</body>
</html>
