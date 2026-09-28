<?php
// ============================================================
//  CLINIC CONTACT  (clinic_contact.php) -- admin only
// ============================================================
//  The clinic's phone, email, address, hours and Facebook page.
//  Patients see these under "Clinic Contact" in their portal, and
//  the same details appear on the public home page.
// ============================================================
require_once 'config/auth.php';
require_login(['admin']);

$fields = [
    'land_contact_phone'    => 'Contact Number',
    'land_contact_email'    => 'Email Address',
    'land_contact_address'  => 'Clinic Address',
    'land_contact_hours'    => 'Clinic Hours',
    'land_contact_facebook' => 'Facebook Page',
];

// ---------- Save ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_contact') {
    $in = [];
    foreach ($fields as $k => $label) $in[$k] = trim((string)($_POST[$k] ?? ''));

    $error = '';
    [$phone, $phoneErr] = validate_phone($in['land_contact_phone'], true, true);
    [$fb, $fbErr]       = facebook_url($in['land_contact_facebook']);
    if ($phoneErr !== '') {
        $error = 'Contact number: ' . $phoneErr;
    } elseif (!filter_var($in['land_contact_email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($in['land_contact_address'] === '') {
        $error = 'Please enter the clinic address.';
    } elseif ($fbErr !== '') {
        $error = $fbErr;
    } else {
        $in['land_contact_facebook'] = $fb;
        foreach ($in as $k => $v) save_setting($pdo, $k, mb_substr($v, 0, 255));
        log_activity($pdo, 'Updated clinic contact', 'Phone, email, address, hours and Facebook');
        set_flash('Clinic contact updated. Patients and the home page now show the new details.');
        header('Location: clinic_contact'); exit;
    }
    set_flash($error, 'error');
    $_SESSION['cc_form'] = $in;                 // keep what they typed
    header('Location: clinic_contact'); exit;
}

// ---------- Current values ----------
$cur = [];
foreach ($pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN
          ('clinic_phone','clinic_email','clinic_address','operating_hours','" . implode("','", array_keys($fields)) . "')") as $r) {
    $cur[$r['setting_key']] = (string)$r['setting_value'];
}
$fallback = ['land_contact_phone' => 'clinic_phone', 'land_contact_email' => 'clinic_email',
             'land_contact_address' => 'clinic_address', 'land_contact_hours' => 'operating_hours'];
$val = [];
foreach ($fields as $k => $label) $val[$k] = ($cur[$k] ?? '') !== '' ? $cur[$k] : ($cur[$fallback[$k] ?? ''] ?? '');
if (!empty($_SESSION['cc_form'])) { $val = array_merge($val, $_SESSION['cc_form']); unset($_SESSION['cc_form']); }

$page_title = "Clinic Contact";
include 'includes/head.php';
$active = 'clinic_contact';
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div>
                <h1 style="color:var(--teal-light)">Clinic Contact</h1>
                <div class="sub">What patients see under "Clinic Contact" in their account (also shown on the home page)</div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="clock"><span class="time" id="clock">--:--</span><br><span id="clock-date"></span></div>
            </div>
        </div>

        <div class="card-box" style="max-width:820px;">
            <h5 class="mb-3">📞 Edit contact details</h5>
            <form method="POST">
                <input type="hidden" name="action" value="save_contact">
                <div class="row">
                    <div class="col-md-6"><label class="field-label">📱 Contact Number</label>
                        <input name="land_contact_phone" class="form-control mb-3" required type="tel" inputmode="numeric" data-digits maxlength="11"
                               placeholder="09171234567 or 0461234567" value="<?= e(preg_replace('/\D/', '', $val['land_contact_phone'])) ?>"></div>
                    <div class="col-md-6"><label class="field-label">✉️ Email Address</label>
                        <input name="land_contact_email" type="email" class="form-control mb-3" required maxlength="150"
                               value="<?= e($val['land_contact_email']) ?>"></div>
                    <div class="col-md-6"><label class="field-label">📍 Clinic Address</label>
                        <input name="land_contact_address" class="form-control mb-3" required maxlength="255"
                               value="<?= e($val['land_contact_address']) ?>"></div>
                    <div class="col-md-6"><label class="field-label">🕘 Clinic Hours</label>
                        <input name="land_contact_hours" class="form-control mb-3" maxlength="100" placeholder="Mon–Sat · 9:00 AM – 5:00 PM"
                               value="<?= e($val['land_contact_hours']) ?>"></div>
                    <div class="col-12"><label class="field-label">📘 Facebook Page</label>
                        <input name="land_contact_facebook" class="form-control mb-1" maxlength="255"
                               placeholder="https://www.facebook.com/YourClinicPage" value="<?= e($val['land_contact_facebook']) ?>">
                        <div class="text-muted2 mb-3" style="font-size:.8rem;">Paste the link of the clinic's Facebook page (or just the page name). Leave blank to hide it.</div></div>
                </div>
                <button class="btn btn-teal">Save changes</button>
            </form>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
</body></html>
