<?php
// ============================================================
//  FORGOT PASSWORD  (forgot_password.php)
// ============================================================
//  Three steps, all on this one page:
//    1. request  - user types their email; we email a 6-digit code
//    2. verify   - user types the code
//    3. reset    - user sets a new password
//  The code is stored in users.reset_code with a 15-minute expiry.
// ============================================================
require_once 'config/auth.php';
require_once 'includes/mailer.php';
require_once 'includes/message_templates.php';

if (is_logged_in()) { header("Location: dashboard.php"); exit; }

$step  = $_GET['step'] ?? 'request';
$error = '';
$notice = '';

// ---------- STEP 1: user asks for a reset code ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request') {
    $email = trim($_POST['email'] ?? '');
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Always show the same message whether or not the email exists — this avoids
    // telling a stranger which emails are registered (a small security win).
    if ($user) {
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE users SET reset_code = ?, reset_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?")
            ->execute([$code, $user['id']]);

        if (mail_is_ready($pdo)) {
            $cat = message_catalogue()['password_reset'];
            [$rsSubj, $body] = tpl_message($pdo, 'password_reset', $cat['subject'], $cat['body'], [
                'code'   => '<div style="font-size:32px;font-weight:bold;letter-spacing:8px;color:#0f766e;'
                          . 'text-align:center;margin:24px 0;">' . $code . '</div>',
                'clinic' => clinic_name($pdo),
            ]);
            $err = '';
            send_mail($pdo, $email, $rsSubj, $body, $err, 'password_reset');
        }
        // Move to the verify step, remembering the email in the session.
        $_SESSION['reset_email'] = $email;
        header("Location: forgot_password.php?step=verify"); exit;
    } else {
        // Pretend success even if the email is unknown.
        $_SESSION['reset_email'] = $email;
        header("Location: forgot_password.php?step=verify"); exit;
    }
}

// ---------- STEP 2: user types the code ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify') {
    $email = $_SESSION['reset_email'] ?? '';
    $code  = trim($_POST['code'] ?? '');
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND reset_code = ? AND reset_expires > NOW()");
    $stmt->execute([$email, $code]);
    if ($stmt->fetch()) {
        $_SESSION['reset_verified'] = true;
        header("Location: forgot_password.php?step=reset"); exit;
    } else {
        $error = "That code is incorrect or has expired. Please try again.";
        $step = 'verify';
    }
}

// ---------- STEP 3: user sets a new password ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset') {
    $email = $_SESSION['reset_email'] ?? '';
    $verified = $_SESSION['reset_verified'] ?? false;
    $pass  = $_POST['password'] ?? '';
    $pass2 = $_POST['confirm_password'] ?? '';

    if (!$verified) {
        $error = "Please verify your reset code first.";
        $step = 'request';
    } elseif ($pass !== $pass2) {
        $error = "The passwords do not match.";
        $step = 'reset';
    } elseif (strlen($pass) < 4) {
        $error = "Your password must be at least 4 characters.";
        $step = 'reset';
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ?, reset_code = NULL, reset_expires = NULL WHERE email = ?")
            ->execute([$hash, $email]);
        unset($_SESSION['reset_email'], $_SESSION['reset_verified']);
        header("Location: login.php?toast=pwreset");
        exit;
    }
}

$page_title = "Reset Password";
include 'includes/head.php';
?>

<div class="auth-page">
    <a href="login.php" class="auth-close" aria-label="Back to login" title="Back to login">&times;</a>

    <div class="auth-left">
      <div class="auth-brand">
        <div class="tooth">🔑</div>
        <h1>Reset your<br><span class="gold">password.</span></h1>
        <p>We'll email you a code to verify it's really you.</p>
      </div>
    </div>

    <div class="auth-right">
      <div class="auth-card">
        <div class="brand-row mb-3">
            <div class="logo-badge">🦷</div>
            <div><strong>St. Therese Dental Clinic</strong></div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger" style="font-size:.88rem;"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($step === 'request'): ?>
            <h3 class="mb-1">Forgot Password</h3>
            <p class="muted mb-3" style="font-size:.9rem;">Enter the email address on your account and we'll send you a reset code.</p>
            <form method="POST">
                <input type="hidden" name="action" value="request">
                <label class="field-label">Email Address</label>
                <input type="email" name="email" class="form-control mb-3" placeholder="you@email.com" required autofocus>
                <button class="btn btn-teal w-100 py-2">Send Reset Code →</button>
            </form>
            <p class="text-center mt-3 muted"><a href="login.php" style="color:var(--teal);">← Back to login</a></p>

        <?php elseif ($step === 'verify'): ?>
            <h3 class="mb-1">Check your email</h3>
            <p class="muted mb-3" style="font-size:.9rem;">
                If <strong><?= e($_SESSION['reset_email'] ?? 'your email') ?></strong> is registered, a 6-digit code is on its way.
                Enter it below. (Check the spam folder too.)
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="verify">
                <label class="field-label">Reset Code</label>
                <input type="text" name="code" class="form-control mb-3" placeholder="6-digit code" maxlength="6"
                       style="letter-spacing:6px;text-align:center;font-size:1.2rem;" required autofocus>
                <button class="btn btn-teal w-100 py-2">Verify Code →</button>
            </form>
            <p class="text-center mt-3 muted"><a href="forgot_password.php?step=request" style="color:var(--teal);">← Use a different email</a></p>

        <?php elseif ($step === 'reset'): ?>
            <h3 class="mb-1">Set a new password</h3>
            <p class="muted mb-3" style="font-size:.9rem;">Almost done! Choose a new password for your account.</p>
            <form method="POST" onsubmit="return checkResetPass()">
                <input type="hidden" name="action" value="reset">
                <label class="field-label">New Password</label>
                <div class="pw-wrap mb-3">
                    <input type="password" name="password" id="rp1" class="form-control" placeholder="At least 4 characters" required>
                    <button type="button" class="pw-eye" aria-label="Show password">
                        <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
                <label class="field-label">Confirm New Password</label>
                <div class="pw-wrap mb-1">
                    <input type="password" name="confirm_password" id="rp2" class="form-control" placeholder="Re-type password" required>
                    <button type="button" class="pw-eye" aria-label="Show password">
                        <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
                <div id="rp-warn" class="text-danger mb-3" style="display:none;font-size:.82rem;">The passwords do not match.</div>
                <button class="btn btn-teal w-100 py-2">Reset Password →</button>
            </form>
        <?php endif; ?>
      </div>
    </div>
</div>

<script>
function checkResetPass(){
    var a = document.getElementById('rp1').value;
    var b = document.getElementById('rp2').value;
    var w = document.getElementById('rp-warn');
    if (a !== b) { w.style.display='block'; document.getElementById('rp2').focus(); return false; }
    w.style.display='none'; return true;
}
</script>
</body>
</html>
