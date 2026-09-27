<?php
// ============================================================
//  MESSAGING CONFIGURATION  (messaging.php) -- admin only
// ============================================================
//  Image 11 in the design.
//  Two side-by-side cards: Email (SMTP) settings and SMS (API)
//  settings. Like settings.php, everything is stored in the
//  `settings` table as key/value pairs.
//
//  NOTE: This page only SAVES the settings. Actually sending
//  real email/SMS would need extra libraries (e.g. PHPMailer)
//  and a paid SMS provider, which is beyond this simple demo.
// ============================================================
require_once 'config/auth.php';
require_login(['admin']);
require_once 'includes/mailer.php';   // real SMTP sending

// save_setting() now lives in config/auth.php, shared by every page that needs it.

// ---------- Handle saving ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_email') {
        save_setting($pdo, 'email_enabled',   isset($_POST['email_enabled']) ? '1':'0');
        save_setting($pdo, 'smtp_host',       trim($_POST['smtp_host']));
        save_setting($pdo, 'smtp_port',       trim($_POST['smtp_port']));
        save_setting($pdo, 'smtp_encryption', strtolower(trim($_POST['smtp_encryption'])));
        save_setting($pdo, 'smtp_from_email', trim($_POST['from_email']));
        save_setting($pdo, 'smtp_from_name',  trim($_POST['from_name']));
        save_setting($pdo, 'smtp_username',   trim($_POST['smtp_username']));

        // Only overwrite the password when a new one is typed (blank = keep current).
        if (trim($_POST['smtp_password'] ?? '') !== '') {
            // Google SHOWS the app password with spaces ("abcd efgh ijkl mnop"),
            // but the real password has none. People paste it with the spaces and
            // then login fails, so we strip every space here.
            $appPass = preg_replace('/\s+/', '', $_POST['smtp_password']);
            save_setting($pdo, 'smtp_password', $appPass);
        }

        // The settings just changed, so any earlier "it works" result is no longer
        // proof. The admin has to press Send Test again.
        save_setting($pdo, 'smtp_verified', '0');
        save_setting($pdo, 'smtp_verified_at', '');

        set_flash('Email configuration saved. Now press "Send Test" to check it actually works.');
        header("Location: messaging"); exit;
    }

    // ---- Really send a test email ----
    if ($action === 'send_test') {
        $to = trim($_POST['test_to'] ?? '');
        $err = '';
        $body = mail_template(
            'Your email settings work! 🎉',
            'If you are reading this, the St. Therese Dental Clinic system can now send
             real email — verification codes, appointment confirmations, and 24-hour
             reminders will all be delivered to your patients.'
        );

        if (send_mail($pdo, $to, 'Test email from St. Therese Dental Clinic', $body, $err, 'test')) {
            // It really went out, so the settings are PROVEN to work.
            save_setting($pdo, 'smtp_verified', '1');
            save_setting($pdo, 'smtp_verified_at', date('Y-m-d H:i:s'));
            set_flash("✅ Test email sent to $to. Check the inbox (and the spam folder).");
        } else {
            save_setting($pdo, 'smtp_verified', '0');
            save_setting($pdo, 'smtp_verified_at', '');
            set_flash("❌ Could not send: $err", 'error');
        }
        header("Location: messaging"); exit;
    }

    if ($action === 'save_sms') {
        save_setting($pdo, 'sms_enabled',   isset($_POST['sms_enabled']) ? '1':'0');
        save_setting($pdo, 'sms_provider',  trim($_POST['sms_provider']));
        save_setting($pdo, 'sms_sender',    trim($_POST['sms_sender']));
        save_setting($pdo, 'sms_country',   trim($_POST['sms_country']));
        save_setting($pdo, 'sms_max_day',   trim($_POST['sms_max_day']));
    }

    set_flash('Messaging configuration saved.');
    header("Location: messaging");
    exit;
}

// ---------- Read settings ----------
$cfg = [];
foreach ($pdo->query("SELECT setting_key, setting_value FROM settings") as $row) {
    $cfg[$row['setting_key']] = $row['setting_value'];
}
function cfg($cfg, $key, $default = '') { return $cfg[$key] ?? $default; }

$page_title = "Messaging Configuration";
include 'includes/head.php';
$active = 'messaging';
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div>
                <h1 style="color:var(--teal-light)">Messaging Configuration</h1>
                <div class="sub">Configure Email and SMS delivery settings</div>
            </div>
        </div>

        <?php include 'includes/admin_tabs.php'; ?>

        <div class="row g-3">
            <!-- ===== Email Configuration ===== -->
            <div class="col-lg-6">
                <div class="card-box h-100">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_email">
                        <div class="flex-between mb-1">
                            <h5 class="mb-0">📧 Email Configuration</h5>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="email_enabled" <?= cfg($cfg,'email_enabled','0')==='1'?'checked':'' ?>>
                            </div>
                        </div>
                        <div class="text-muted2 mb-3" style="font-size:.8rem;">Turn the switch on to send real email (verification codes, confirmations, reminders).</div>

                        <div class="alert" style="background:#fff6e0;border:1px solid var(--gold);color:#8a6d2f;font-size:.8rem;">
                            <strong>Gmail setup (one time):</strong>
                            <ol class="mb-1 ps-3 mt-1">
                                <li>Turn on <strong>2-Step Verification</strong> on your Google account.</li>
                                <li>Go to Google Account → Security → <strong>App passwords</strong> and create one.</li>
                                <li>Paste that <strong>16-character code</strong> into the Password box below (not your normal Gmail password).</li>
                            </ol>
                            <div style="border-top:1px solid #e6cfa0;padding-top:6px;margin-top:6px;">
                                💡 Google shows it with spaces like <code>abcd efgh ijkl mnop</code> — you can paste it
                                either way, the spaces are removed automatically.
                                <br>Only <strong>Send Test</strong> proves the password is right; saving does not.
                            </div>
                        </div>

                        <label class="field-label">SMTP Host</label>
                        <input name="smtp_host" class="form-control mb-3" value="<?= e(cfg($cfg,'smtp_host','smtp.gmail.com')) ?>">

                        <div class="row">
                            <div class="col-md-6">
                                <label class="field-label">Port</label>
                                <input name="smtp_port" class="form-control mb-3" value="<?= e(cfg($cfg,'smtp_port','587')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="field-label">Encryption</label>
                                <select name="smtp_encryption" class="form-select mb-3">
                                    <?php foreach (['tls'=>'TLS (port 587)','ssl'=>'SSL (port 465)','none'=>'None'] as $val=>$lbl): ?>
                                        <option value="<?= $val ?>" <?= cfg($cfg,'smtp_encryption','tls')===$val?'selected':'' ?>><?= $lbl ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <label class="field-label">From Email</label>
                        <input name="from_email" class="form-control mb-3" value="<?= e(cfg($cfg,'smtp_from_email','')) ?>" placeholder="same as your Gmail address">

                        <label class="field-label">From Name</label>
                        <input name="from_name" class="form-control mb-3" value="<?= e(cfg($cfg,'smtp_from_name','St. Therese Dental Clinic')) ?>">

                        <label class="field-label">Username (your Gmail address)</label>
                        <input name="smtp_username" class="form-control mb-3" value="<?= e(cfg($cfg,'smtp_username','')) ?>" placeholder="yourclinic@gmail.com">

                        <label class="field-label">App Password</label>
                        <input type="password" name="smtp_password" class="form-control mb-1"
                               placeholder="<?= cfg($cfg,'smtp_password','')!=='' ? 'Saved — leave blank to keep it' : 'e.g. abcd efgh ijkl mnop' ?>">

                        <?php
                            $hasPass  = cfg($cfg,'smtp_password','') !== '';
                            $verified = cfg($cfg,'smtp_verified','0') === '1';
                            $when     = cfg($cfg,'smtp_verified_at','');
                        ?>
                        <div class="mb-3" style="font-size:.78rem;">
                            <?php if ($verified): ?>
                                <span style="color:#1f8a54;font-weight:600;">
                                    ✅ Verified — a real test email was sent successfully
                                    <?= $when ? 'on ' . date('M j, Y g:i A', strtotime($when)) : '' ?>.
                                </span>
                            <?php elseif ($hasPass): ?>
                                <span style="color:#b8860b;font-weight:600;">
                                    ⚠️ A password is saved, but it has <u>not been proven to work</u> yet.
                                </span><br>
                                <span class="text-muted2">
                                    Saving does not check the password — Gmail only checks it when we actually send.
                                    Press <strong>Send Test</strong> below to find out if it is correct.
                                </span>
                            <?php else: ?>
                                <span style="color:#c0392b;font-weight:600;">🚫 No password saved — email cannot send.</span>
                            <?php endif; ?>
                        </div>

                        <button class="btn btn-teal">💾 Save Email Settings</button>
                    </form>

                    <hr class="my-3">
                    <form method="POST">
                        <input type="hidden" name="action" value="send_test">
                        <label class="field-label">Send a real test email to:</label>
                        <div class="d-flex gap-2">
                            <input name="test_to" type="email" class="form-control" required
                                   value="<?= e($_SESSION['email'] ?? '') ?>" placeholder="you@gmail.com">
                            <button class="btn btn-dark-navy" style="white-space:nowrap;">✉️ Send Test</button>
                        </div>
                        <div class="text-muted2 mt-1" style="font-size:.78rem;">Save your settings first, then send a test.</div>
                    </form>
                </div>
            </div>

            <!-- ===== SMS Configuration ===== -->
            <div class="col-lg-6">
                <div class="card-box h-100">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_sms">
                        <div class="flex-between mb-1">
                            <h5 class="mb-0">💬 SMS Configuration</h5>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="sms_enabled" <?= cfg($cfg,'sms_enabled','1')==='1'?'checked':'' ?>>
                            </div>
                        </div>
                        <div class="text-muted2 mb-3" style="font-size:.8rem;">Semaphore / Vonage API for SMS blasts</div>

                        <label class="field-label">SMS Provider</label>
                        <select name="sms_provider" class="form-select mb-3">
                            <?php foreach (['Semaphore (PH)','Vonage','Twilio'] as $opt): ?>
                                <option <?= cfg($cfg,'sms_provider','Semaphore (PH)')===$opt?'selected':'' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label class="field-label">API Key</label>
                        <input name="sms_apikey" class="form-control mb-3" value="sk_live_********************">

                        <label class="field-label">Sender Name / Number</label>
                        <input name="sms_sender" class="form-control mb-3" value="<?= e(cfg($cfg,'sms_sender','STTHERESE')) ?>">

                        <label class="field-label">Credits Remaining</label>
                        <input class="form-control mb-3 text-success fw-bold" value="1,248 credits" readonly>

                        <label class="field-label">Default Country Code</label>
                        <input name="sms_country" class="form-control mb-3" value="<?= e(cfg($cfg,'sms_country','+63')) ?>">

                        <label class="field-label">Max SMS Per Day</label>
                        <input name="sms_max_day" class="form-control mb-3" value="<?= e(cfg($cfg,'sms_max_day','200')) ?>">

                        <div class="d-flex gap-2">
                            <button class="btn btn-teal">💾 Save</button>
                            <button type="button" class="btn btn-light" onclick="alert('Test SMS would be sent here.')">💬 Send Test</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js"></script>
</body></html>
