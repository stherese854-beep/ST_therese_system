<?php
// ============================================================
//  CONFIRM NEW LOGIN EMAIL  (email_confirm.php)  — no login needed
// ============================================================
//  Opened from the link sent to the NEW address when the admin
//  changes a patient's login email:  email_confirm?t=<token>
//  Opening the link changes nothing (email scanners open links on
//  their own) — the patient presses the button first.
// ============================================================
require_once 'config/auth.php';
require_once 'includes/account_transfer.php';

$token  = (string)($_REQUEST['t'] ?? '');
$result = null;
ensure_account_transfer_tables($pdo);
$q = $pdo->prepare("SELECT c.*, u.name FROM email_changes c JOIN users u ON u.id = c.user_id
                     WHERE c.token = ? AND c.confirmed_at IS NULL AND c.cancelled_at IS NULL AND c.expires_at > NOW()");
$q->execute([preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '']);
$change = $q->fetch();

if ($change && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = confirm_email_change($pdo, $token);
}

$page_title = 'Confirm your email';
$hide_hamburger = true;
include 'includes/head.php';
?>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background:var(--bg);">
  <div class="card-box" style="max-width:440px;width:100%;text-align:center;padding:28px 24px;">
    <div style="font-size:2.2rem;">🦷</div>
    <h4 style="color:var(--teal);margin:6px 0 14px;"><?= e(clinic_name($pdo)) ?></h4>

    <?php if ($result): ?>
        <div style="font-size:2rem;"><?= $result[0] ? '✅' : '⚠️' ?></div>
        <p><?= e($result[1]) ?></p>
        <a href="login" class="btn btn-teal" data-keep-text>Go to sign in</a>
    <?php elseif (!$change): ?>
        <p>This link has expired or was already used.</p>
        <p class="text-muted2" style="font-size:.88rem;">If your email still needs changing, please ask the clinic to send a new link.</p>
        <a href="login" class="btn btn-teal" data-keep-text>Go to sign in</a>
    <?php else: ?>
        <p>Hello <b><?= e($change['name']) ?></b>. Please confirm your new login email:</p>
        <div class="card-box mb-3" style="background:#f7fafa;padding:12px;">
            <div class="text-muted2" style="font-size:.8rem;">From</div><div><?= e($change['old_email']) ?></div>
            <div class="text-muted2 mt-2" style="font-size:.8rem;">To</div><div style="font-weight:600;"><?= e($change['new_email']) ?></div>
        </div>
        <form method="POST">
            <input type="hidden" name="t" value="<?= e($token) ?>">
            <button class="btn btn-teal w-100" data-keep-text>✔ Yes, use my new email</button>
        </form>
        <p class="text-muted2 mt-3 mb-0" style="font-size:.82rem;">Not you? Just close this page — nothing changes.</p>
    <?php endif; ?>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
