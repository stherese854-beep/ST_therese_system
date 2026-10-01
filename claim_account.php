<?php
// ============================================================
//  CLAIM OWN ACCOUNT  (claim_account.php)  — no login needed
// ============================================================
//  Opened from the "your own patient account" invite email:
//     claim_account?t=<token>
//  The person booked under someone else's account sets a password,
//  and their existing patient record (appointments, dental chart,
//  records) becomes their own account. See includes/account_transfer.php.
// ============================================================
require_once 'config/auth.php';
require_once 'includes/account_transfer.php';

$token = (string)($_REQUEST['t'] ?? '');
$inv   = find_account_invite($pdo, $token);
$error = '';

if ($inv && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass  = (string)($_POST['password'] ?? '');
    [$phone, $phoneErr] = validate_phone($_POST['phone'] ?? '', false);
    if (($pp = password_problem($pass)) !== '')                 $error = $pp;
    elseif ($pass !== (string)($_POST['password_confirm'] ?? '')) $error = 'The passwords do not match. Please type the same password twice.';
    elseif ($phoneErr !== '')                                    $error = $phoneErr;
    elseif (empty($_POST['agree']))                              $error = 'Please agree to the Terms and Privacy Policy.';
    else {
        [$uid, $error] = accept_account_invite($pdo, $inv, $pass, $phone);
        if ($uid) {
            // Signed straight in to the new account.
            session_regenerate_id(true);
            $_SESSION = [];
            $_SESSION['user_id'] = $uid;
            $_SESSION['name']    = $inv['name'];
            $_SESSION['role']    = 'patient';
            $_SESSION['login_at'] = time();   // when they signed in (see "sign everyone out" in config/auth.php)
            $_SESSION['show_welcome_popup'] = true;
            $_SESSION['just_registered']    = true;
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$uid]);
            set_flash('Welcome! This is now your own account — your appointments and dental records are here.');
            header('Location: portal'); exit;
        }
    }
}

$page_title = 'Your own account';
$hide_hamburger = true;
include 'includes/head.php';
?>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background:var(--bg);">
  <div class="card-box" style="max-width:460px;width:100%;padding:28px 24px;">
    <div style="text-align:center;">
        <div style="font-size:2.2rem;">🦷</div>
        <h4 style="color:var(--teal);margin:6px 0 4px;"><?= e(clinic_name($pdo)) ?></h4>
    </div>

    <?php if (!$inv): ?>
        <p class="text-center mt-3">This invite link has expired, was already used, or is not valid.</p>
        <p class="text-muted2 text-center" style="font-size:.88rem;">Ask the person who books for you to send a new invite
            from <b>People I Book For</b>, or contact the clinic.</p>
        <div class="text-center"><a href="login" class="btn btn-teal" data-keep-text>Go to sign in</a></div>
    <?php else: ?>
        <p class="text-center mb-3">Hello <b><?= e($inv['name']) ?></b>! Set a password to get your own patient account.</p>
        <div class="alert" style="background:#eef7f6;border:1px solid #cfe0dd;color:#3f5350;font-size:.84rem;">
            Your appointments, dental chart and records move to your new account.
            <b>You will sign in with <?= e($inv['email']) ?>.</b>
            After this, the person who booked for you will no longer see or manage your appointments.
        </div>
        <?php if ($error): ?><div class="alert alert-danger py-2" style="font-size:.88rem;"><?= e($error) ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="t" value="<?= e($token) ?>">
            <label class="field-label">Email (your login)</label>
            <input class="form-control mb-3" value="<?= e($inv['email']) ?>" readonly style="background:#eef7f6;">

            <label class="field-label">Phone number <span class="text-muted2">(optional)</span></label>
            <input name="phone" class="form-control mb-3" placeholder="09XX XXX XXXX" value="<?= e($_POST['phone'] ?? '') ?>" <?= phone_input_attrs() ?>>

            <label class="field-label">New password</label>
            <div class="pw-wrap mb-1">
                <input type="password" name="password" class="form-control" required minlength="8" data-pw-meter autocomplete="new-password">
                <button type="button" class="pw-eye" aria-label="Show password">👁</button>
            </div>
            <div class="text-muted2 mb-3" style="font-size:.78rem;">Strong: 8+ characters with upper- &amp; lower-case letters, a number and a symbol.</div>

            <label class="field-label">Confirm password</label>
            <div class="pw-wrap mb-3">
                <input type="password" name="password_confirm" class="form-control" required autocomplete="new-password">
                <button type="button" class="pw-eye" aria-label="Show password">👁</button>
            </div>

            <label class="d-flex gap-2 align-items-start mb-3" style="font-size:.85rem;cursor:pointer;">
                <input type="checkbox" name="agree" value="1" class="form-check-input mt-1" required>
                <span>I agree to the clinic's <a href="./#contact" target="_blank">Terms and Privacy Policy</a>.</span>
            </label>
            <button class="btn btn-teal w-100" data-keep-text>✔ Create my account</button>
        </form>
    <?php endif; ?>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
</body></html>
