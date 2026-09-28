<?php
// ============================================================
//  EDIT LANDING PAGE  (landing_edit.php)
// ============================================================
//  Lets the ADMIN edit every piece of text on the landing page.
//  The text is saved in the `settings` table using keys that all
//  start with "land_". If a key is blank/never saved, index.php
//  falls back to its built-in default, so the page always works.
//
//  LIST fields (Features, Services, FAQ, ...) use ONE LINE PER CARD
//  in this format:      Title | Description
// ============================================================
require_once 'config/auth.php';
require_login(['admin']);      // admin only

// Save one key/value into the settings table.
// save_setting() now lives in config/auth.php, shared by every page that needs it.

// ---------- Handle Save / Reset ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_landing') {
        // The phone shown on the homepage must be a real number (landline ok).
        if (isset($_POST['land_contact_phone'])) {
            [, $phoneError] = validate_phone($_POST['land_contact_phone'], false, true);
            if ($phoneError !== '') {
                set_flash('Contact phone: ' . $phoneError, 'error');
                header("Location: landing_edit"); exit;
            }
        }
        // Save every "land_*" field that was submitted.
        foreach ($_POST as $k => $v) {
            if (strpos($k, 'land_') === 0) {
                save_setting($pdo, $k, trim($v));
            }
        }
        log_activity($pdo, 'Updated landing page content');
        set_flash('Landing page updated.');
        header("Location: landing_edit"); exit;
    }

    if ($action === 'reset_landing') {
        // Delete all land_* rows -> index.php goes back to its defaults.
        $pdo->exec("DELETE FROM settings WHERE setting_key LIKE 'land_%'");
        set_flash('Landing page reset to the default text.', 'info');
        header("Location: landing_edit"); exit;
    }

    // ---- Upload an image used on the landing page ----
    if ($action === 'upload_image') {
        $slot = $_POST['slot'] ?? '';             // land_img_hero
        $dir  = __DIR__ . '/uploads/landing';
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp','svg'])) {
                $fname = $slot . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['image']['tmp_name'], "$dir/$fname")) {
                    save_setting($pdo, $slot, 'uploads/landing/' . $fname);
                    set_flash('Image uploaded.');
                } else {
                    set_flash('Could not save the file. Check the uploads/landing folder.', 'error');
                }
            } else {
                set_flash('Please choose an image (jpg, png, gif, webp, svg).', 'error');
            }
        } else {
            set_flash('Please choose a file first.', 'error');
        }
        header("Location: landing_edit"); exit;
    }

    // ---- Remove an uploaded image (back to the built-in illustration) ----
    if ($action === 'remove_image') {
        $slot = $_POST['slot'] ?? '';
        $q = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=?");
        $q->execute([$slot]);
        $old = $q->fetchColumn();
        if ($old && is_file(__DIR__ . '/' . $old)) @unlink(__DIR__ . '/' . $old);
        save_setting($pdo, $slot, '');
        set_flash('Image removed — the built-in illustration is back.', 'info');
        header("Location: landing_edit"); exit;
    }
}

// ---------- Load current values ----------
$LC = [];
foreach ($pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'land_%'") as $r) {
    $LC[$r['setting_key']] = $r['setting_value'];
}
// v() returns the saved value, or the default so the box is never empty.
function v($k, $def = '') { global $LC; return (isset($LC[$k]) && $LC[$k] !== '') ? $LC[$k] : $def; }

// Default list text (same defaults index.php uses)
$defFeatures = "Online Appointment Booking | Book a visit anytime from your phone or computer in just a few taps.
Real-Time Availability | See open slots instantly and pick the time that works best for you.
Appointment Reminders | Get timely confirmations and reminders so you never miss a visit.
Patient Account Management | Manage your profile, contact details, and preferences in one place.
Secure Login | Your account and health information are protected and private.
Easy Rescheduling | Plans changed? Reschedule or cancel in seconds without a phone call.
Appointment History | Look back on past visits and treatments whenever you need them.
Mobile-Friendly Access | A responsive design that looks and works great on any device.";

$defSteps = "Register | Create your free patient account with a few basic details.
Log In | Sign in securely to access your personal dashboard.
Book | Choose a service, pick an available date and time, and confirm.
Attend | Receive your confirmation and visit us at your scheduled time.";

$defServices = "Dental Check-up | Routine exams to keep your teeth and gums healthy.
Teeth Cleaning | Professional cleaning to remove plaque and tartar.
Tooth Extraction | Safe, gentle removal of damaged or problem teeth.
Dental Fillings | Restore decayed teeth with durable, natural-looking fillings.
Root Canal Treatment | Save an infected tooth and relieve pain effectively.
Braces Consultation | Straighten your smile with an orthodontic assessment.
Teeth Whitening | Brighten your smile with a professional whitening treatment.
Dental Implants | Replace missing teeth with strong, lasting implants.
Pediatric Dentistry | Friendly, specialized dental care for children.";

$defWhy = "24/7 Online Access | Book or manage appointments any time of day, from anywhere.
Less Waiting Time | Skip the phone queue and reserve your slot in seconds.
Convenient Management | Reschedule, cancel, and review visits from one dashboard.
Secure Information | Your personal and health data are kept private and safe.
Fast Confirmation | Get instant booking confirmations — no waiting to hear back.
User-Friendly Interface | A clean, simple design anyone can use with ease.";

$defFaqs = "How do I create an account? | Click \"Register\" at the top of the page, enter your name, email, and a password, then verify your email.
How do I book an appointment? | Log in, click \"Book Appointment,\" choose your service, pick an available date and time, and confirm.
Can I reschedule my appointment? | Yes. From your dashboard you can reschedule or cancel an appointment in a few clicks.
Is my information secure? | Absolutely. Your login is protected and your information is kept private and secure.
What happens after booking? | Your appointment is added to your dashboard and marked pending until the clinic confirms it.";

$defTestimonials = "Booking an appointment used to mean waiting on the phone. Now I do it in a minute from my couch! | Jasmine Dela Cruz | Patient since 2024
So easy to use. I rescheduled my cleaning in seconds when work got busy. | Mark Reyes | Patient since 2023
I booked my daughter's checkup and mine at the same time. Clean and fast. | Andrea Lim | Patient since 2025";

$defStats = "General | Dentistry
Ortho | dontics
Mon-Sat | 9AM - 5PM
Walk-in | & Booking";

$defTicks = "Experienced, caring dentist you can trust
General dentistry and orthodontic services
Open Monday to Saturday, easy to reach in Naic";

$defHeroStats = "24/7 | online access
100% | secure & private";

$defHmoTicks = "Accredited by leading HMO providers
Smooth and stress-free dental visits
Reliable care from trusted professionals";

$defHmoList = "Intellicare
ValuCare
MediCard
EastWest Healthcare
Cocolife
HealthAssist
MedAsia
Elite Group
Avega
WellCare";

$page_title = "Edit Landing Page";
include 'includes/head.php';
$active = 'landing_edit';
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div><h1>Edit Landing Page</h1><div class="sub">Change any text shown on the public homepage</div></div>
            <div class="d-flex gap-2">
                <a href="./" target="_blank" class="btn btn-light">👁 View Landing Page</a>
            </div>
        </div>

        <?php include 'includes/admin_tabs.php'; ?>

        <div class="alert" style="background:#eef7f6;border:1px solid var(--teal-light);color:var(--teal);font-size:.87rem;">
            💡 <strong>How the list boxes work:</strong> put <strong>one card per line</strong> in the form
            <code>Title | Description</code>. Leave a box blank to use the built-in default text.
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="save_landing">

            <!-- ===== HERO ===== -->
            <div class="card-box mb-3">
                <h5 class="mb-3">🏠 Hero (top of the page)</h5>
                <label class="field-label">Small label above the title</label>
                <input name="land_hero_eyebrow" class="form-control mb-3" value="<?= e(v('land_hero_eyebrow','🦷 Modern Dental Care, Simplified')) ?>">

                <div class="row">
                    <div class="col-md-6"><label class="field-label">Main title (white part)</label>
                        <input name="land_hero_title" class="form-control mb-3" value="<?= e(v('land_hero_title','Making Dental Appointments')) ?>"></div>
                    <div class="col-md-6"><label class="field-label">Highlighted title (gold part)</label>
                        <input name="land_hero_highlight" class="form-control mb-3" value="<?= e(v('land_hero_highlight','Simple, Fast & Convenient')) ?>"></div>
                </div>

                <label class="field-label">Subtitle</label>
                <textarea name="land_hero_subtitle" class="form-control mb-3" rows="2"><?= e(v('land_hero_subtitle','Our Dental Appointment System lets patients easily schedule appointments online, manage upcoming visits, and receive confirmations — all in one place.')) ?></textarea>

                <div class="row">
                    <div class="col-md-6"><label class="field-label">First trust line (with the tick)</label>
                        <input name="land_hero_trust1" class="form-control mb-3" value="<?= e(v('land_hero_trust1','Book in under 2 minutes')) ?>"></div>
                    <div class="col-md-6"><label class="field-label">Trust stats (Number | Label)</label>
                        <textarea name="land_hero_stats" class="form-control mb-3" rows="2"><?= e(v('land_hero_stats',$defHeroStats)) ?></textarea></div>
                </div>
            </div>

            <!-- ===== LOGIN / SIGN-IN PAGE ===== -->
            <div class="card-box mb-3">
                <h5 class="mb-3">🔐 Login &amp; Create Account Page</h5>
                <div class="text-muted2 mb-3" style="font-size:.82rem;">
                    The text on the left side of the login and create-account screens.
                </div>
                <div class="row">
                    <div class="col-md-3"><label class="field-label">Icon (emoji)</label>
                        <input name="land_auth_icon" class="form-control mb-3" maxlength="4" value="<?= e(v('land_auth_icon','🦷')) ?>"></div>
                    <div class="col-md-4"><label class="field-label">Title line 1 (white part)</label>
                        <input name="land_auth_title1" class="form-control mb-3" value="<?= e(v('land_auth_title1','Your Smile,')) ?>"></div>
                    <div class="col-md-5"><label class="field-label">Title line 2 (gold part)</label>
                        <input name="land_auth_title2" class="form-control mb-3" value="<?= e(v('land_auth_title2','Remembered.')) ?>"></div>
                </div>
                <label class="field-label">Subtitle</label>
                <textarea name="land_auth_subtitle" class="form-control mb-3" rows="2"><?= e(v('land_auth_subtitle','Sign in to access your records, appointments, and dental chart.')) ?></textarea>
            </div>

            <!-- ===== ABOUT ===== -->
            <div class="card-box mb-3">
                <h5 class="mb-3">ℹ️ About the System</h5>
                <div class="row">
                    <div class="col-md-4"><label class="field-label">Small label</label>
                        <input name="land_about_eyebrow" class="form-control mb-3" value="<?= e(v('land_about_eyebrow','About the Clinic')) ?>"></div>
                    <div class="col-md-4"><label class="field-label">Heading</label>
                        <input name="land_about_heading" class="form-control mb-3" value="<?= e(v('land_about_heading','Caring for your smile with')) ?>"></div>
                    <div class="col-md-4"><label class="field-label">Heading highlight</label>
                        <input name="land_about_highlight" class="form-control mb-3" value="<?= e(v('land_about_highlight','a gentle touch')) ?>"></div>
                </div>
                <label class="field-label">Body text</label>
                <textarea name="land_about_body" class="form-control mb-3" rows="3"><?= e(v('land_about_body','Led by Dr. Maricris Agbisit-Cuison, St. Therese of Carmel Dental Clinic offers general dentistry and orthodontics for the whole family. From routine cleanings to braces and tooth restorations, our team is dedicated to keeping your smile healthy and bright in a warm, welcoming clinic.')) ?></textarea>

                <label class="field-label">Tick list (one per line)</label>
                <textarea name="land_about_ticks" class="form-control mb-3" rows="3"><?= e(v('land_about_ticks',$defTicks)) ?></textarea>

                <div class="row">
                    <div class="col-md-6"><label class="field-label">Teal card title</label>
                        <input name="land_about_card_title" class="form-control mb-2" value="<?= e(v('land_about_card_title','Your trusted dental care in Naic, Cavite')) ?>">
                        <label class="field-label">Teal card text</label>
                        <textarea name="land_about_card_text" class="form-control mb-3" rows="2"><?= e(v('land_about_card_text',"St. Therese of Carmel Dental Clinic has cared for families in our community with gentle, professional, and affordable dental treatment.")) ?></textarea></div>
                    <div class="col-md-6"><label class="field-label">Card stats (Number | Label)</label>
                        <textarea name="land_about_stats" class="form-control mb-3" rows="4"><?= e(v('land_about_stats',$defStats)) ?></textarea></div>
                </div>
            </div>

            <!-- ===== FEATURES ===== -->
            <div class="card-box mb-3">
                <h5 class="mb-3">⭐ Features</h5>
                <div class="row">
                    <div class="col-md-4"><label class="field-label">Small label</label>
                        <input name="land_feat_eyebrow" class="form-control mb-3" value="<?= e(v('land_feat_eyebrow','Features')) ?>"></div>
                    <div class="col-md-4"><label class="field-label">Heading</label>
                        <input name="land_feat_heading" class="form-control mb-3" value="<?= e(v('land_feat_heading','Everything you need to')) ?>"></div>
                    <div class="col-md-4"><label class="field-label">Heading highlight</label>
                        <input name="land_feat_highlight" class="form-control mb-3" value="<?= e(v('land_feat_highlight','manage appointments')) ?>"></div>
                </div>
                <label class="field-label">Subtitle</label>
                <input name="land_feat_subtitle" class="form-control mb-3" value="<?= e(v('land_feat_subtitle','Thoughtful tools that make booking and managing dental visits effortless for everyone.')) ?>">
                <label class="field-label">Feature cards — one per line: <code>Title | Description</code></label>
                <textarea name="land_features" class="form-control" rows="8"><?= e(v('land_features',$defFeatures)) ?></textarea>
            </div>

            <!-- ===== HOW IT WORKS ===== -->
            <div class="card-box mb-3">
                <h5 class="mb-3">🔢 How It Works</h5>
                <div class="row">
                    <div class="col-md-4"><label class="field-label">Small label</label>
                        <input name="land_how_eyebrow" class="form-control mb-3" value="<?= e(v('land_how_eyebrow','How It Works')) ?>"></div>
                    <div class="col-md-4"><label class="field-label">Heading</label>
                        <input name="land_how_heading" class="form-control mb-3" value="<?= e(v('land_how_heading','Book your visit in')) ?>"></div>
                    <div class="col-md-4"><label class="field-label">Heading highlight</label>
                        <input name="land_how_highlight" class="form-control mb-3" value="<?= e(v('land_how_highlight','four simple steps')) ?>"></div>
                </div>
                <label class="field-label">Subtitle</label>
                <input name="land_how_subtitle" class="form-control mb-3" value="<?= e(v('land_how_subtitle','From sign-up to your scheduled visit — the whole process takes only minutes.')) ?>">
                <label class="field-label">Steps — one per line: <code>Title | Description</code></label>
                <textarea name="land_steps" class="form-control" rows="4"><?= e(v('land_steps',$defSteps)) ?></textarea>
            </div>

            <!-- ===== SERVICES ===== -->
            <div class="card-box mb-3">
                <h5 class="mb-3">🦷 Dental Services</h5>
                <div class="row">
                    <div class="col-md-4"><label class="field-label">Small label</label>
                        <input name="land_svc_eyebrow" class="form-control mb-3" value="<?= e(v('land_svc_eyebrow','Dental Services')) ?>"></div>
                    <div class="col-md-4"><label class="field-label">Heading</label>
                        <input name="land_svc_heading" class="form-control mb-3" value="<?= e(v('land_svc_heading','Comprehensive care for')) ?>"></div>
                    <div class="col-md-4"><label class="field-label">Heading highlight</label>
                        <input name="land_svc_highlight" class="form-control mb-3" value="<?= e(v('land_svc_highlight','every smile')) ?>"></div>
                </div>
                <label class="field-label">Subtitle</label>
                <input name="land_svc_subtitle" class="form-control mb-3" value="<?= e(v('land_svc_subtitle','Book any of our services online through the appointment system.')) ?>">
                <label class="field-label">Services — one per line: <code>Title | Description</code></label>
                <textarea name="land_services" class="form-control" rows="9"><?= e(v('land_services',$defServices)) ?></textarea>
            </div>

            <!-- ===== WHY CHOOSE ===== -->
            <div class="card-box mb-3">
                <h5 class="mb-3">✅ Why Choose Our System</h5>
                <div class="row">
                    <div class="col-md-4"><label class="field-label">Small label</label>
                        <input name="land_why_eyebrow" class="form-control mb-3" value="<?= e(v('land_why_eyebrow','Why Choose Our System')) ?>"></div>
                    <div class="col-md-4"><label class="field-label">Heading</label>
                        <input name="land_why_heading" class="form-control mb-3" value="<?= e(v('land_why_heading','Built for')) ?>"></div>
                    <div class="col-md-4"><label class="field-label">Heading highlight</label>
                        <input name="land_why_highlight" class="form-control mb-3" value="<?= e(v('land_why_highlight','convenience and trust')) ?>"></div>
                </div>
                <label class="field-label">Subtitle</label>
                <input name="land_why_subtitle" class="form-control mb-3" value="<?= e(v('land_why_subtitle','A better appointment experience from the very first click.')) ?>">
                <label class="field-label">Benefits — one per line: <code>Title | Description</code></label>
                <textarea name="land_why" class="form-control" rows="6"><?= e(v('land_why',$defWhy)) ?></textarea>
            </div>

            <!-- ===== HMO PROVIDERS ===== -->
            <?php $hmoOn = v('land_hmo_show','0') === '1'; ?>
            <div class="card-box mb-3">
                <div class="flex-between mb-1">
                    <h5 class="mb-0">🏥 Trusted by Major HMO Providers</h5>
                    <div class="form-check form-switch mb-0">
                        <!-- The hidden field makes an UNTICKED box save as 0 -->
                        <input type="hidden" name="land_hmo_show" value="0">
                        <input class="form-check-input" type="checkbox" name="land_hmo_show" value="1"
                               id="hmoSwitch" <?= $hmoOn ? 'checked' : '' ?> onchange="document.getElementById('hmoFields').style.opacity=this.checked?'1':'.45'">
                    </div>
                </div>
                <div class="text-muted2 mb-3" style="font-size:.85rem;">
                    <?= $hmoOn
                        ? '✅ This section is <strong>showing</strong> on the landing page.'
                        : '🚫 This section is <strong>hidden</strong>. Turn the switch on when the clinic has HMO partners.' ?>
                </div>

                <div id="hmoFields" style="opacity:<?= $hmoOn ? '1' : '.45' ?>;">
                <label class="field-label">Heading (shown in the teal bar)</label>
                <input name="land_hmo_heading" class="form-control mb-3" value="<?= e(v('land_hmo_heading','Trusted by Major HMO Providers')) ?>">

                <label class="field-label">Body text</label>
                <textarea name="land_hmo_body" class="form-control mb-3" rows="3"><?= e(v('land_hmo_body','At St. Therese Dental Clinic, we make dental care more accessible and worry-free through our partnerships with major HMO providers. Enjoy professional service, hassle-free transactions, and quality care tailored to your needs.')) ?></textarea>

                <label class="field-label">Tick list (one per line)</label>
                <textarea name="land_hmo_ticks" class="form-control mb-3" rows="3"><?= e(v('land_hmo_ticks',$defHmoTicks)) ?></textarea>

                <div class="row">
                    <div class="col-md-6"><label class="field-label">Button text</label>
                        <input name="land_hmo_btn" class="form-control mb-3" value="<?= e(v('land_hmo_btn','More Info')) ?>"></div>
                    <div class="col-md-6"><label class="field-label">Button link</label>
                        <input name="land_hmo_link" class="form-control mb-3" value="<?= e(v('land_hmo_link','#contact')) ?>" placeholder="#contact  or  book">
                    </div>
                </div>

                <hr>
                <div class="text-muted2 mb-2" style="font-size:.82rem;">
                    <strong>If you do NOT upload an HMO image</strong>, the page builds a card from the list below instead.
                </div>
                <div class="row">
                    <div class="col-md-6"><label class="field-label">Card title</label>
                        <input name="land_hmo_card_title" class="form-control mb-3" value="<?= e(v('land_hmo_card_title',"Accredited HMO's")) ?>"></div>
                    <div class="col-md-6"><label class="field-label">Card subtitle</label>
                        <input name="land_hmo_card_sub" class="form-control mb-3" value="<?= e(v('land_hmo_card_sub','Enjoy worry-free dental care with your HMO benefits.')) ?>"></div>
                </div>
                <label class="field-label">HMO providers (one per line)</label>
                <textarea name="land_hmo_list" class="form-control" rows="6"><?= e(v('land_hmo_list',$defHmoList)) ?></textarea>
                </div><!-- /hmoFields -->
            </div>

            <!-- ===== DENTISTS ===== -->
            <div class="card-box mb-3">
                <h5 class="mb-1">👨‍⚕️ Meet Our Dentists</h5>
                <div class="text-muted2 mb-3" style="font-size:.85rem;">The dentist cards are pulled automatically from your <a href="admin_dentists">Dentists</a> list. Only the section headings are edited here.</div>
                <div class="row">
                    <div class="col-md-4"><label class="field-label">Small label</label>
                        <input name="land_doc_eyebrow" class="form-control mb-3" value="<?= e(v('land_doc_eyebrow','Meet Our Dentists')) ?>"></div>
                    <div class="col-md-4"><label class="field-label">Heading</label>
                        <input name="land_doc_heading" class="form-control mb-3" value="<?= e(v('land_doc_heading','Caring professionals')) ?>"></div>
                    <div class="col-md-4"><label class="field-label">Heading highlight</label>
                        <input name="land_doc_highlight" class="form-control mb-3" value="<?= e(v('land_doc_highlight','you can trust')) ?>"></div>
                </div>
                <label class="field-label">Subtitle</label>
                <input name="land_doc_subtitle" class="form-control" value="<?= e(v('land_doc_subtitle','Experienced dentists dedicated to keeping your smile healthy.')) ?>">
            </div>

            <!-- ===== TESTIMONIALS ===== -->
            <div class="card-box mb-3">
                <h5 class="mb-3">💬 Testimonials</h5>
                <div class="row">
                    <div class="col-md-4"><label class="field-label">Small label</label>
                        <input name="land_test_eyebrow" class="form-control mb-3" value="<?= e(v('land_test_eyebrow','Testimonials')) ?>"></div>
                    <div class="col-md-4"><label class="field-label">Heading</label>
                        <input name="land_test_heading" class="form-control mb-3" value="<?= e(v('land_test_heading','Loved by')) ?>"></div>
                    <div class="col-md-4"><label class="field-label">Heading highlight</label>
                        <input name="land_test_highlight" class="form-control mb-3" value="<?= e(v('land_test_highlight','our patients')) ?>"></div>
                </div>
                <label class="field-label">Reviews — one per line: <code>Quote | Name | Subtitle</code></label>
                <textarea name="land_testimonials" class="form-control" rows="4"><?= e(v('land_testimonials',$defTestimonials)) ?></textarea>
                <div class="text-muted2 mt-2" style="font-size:.8rem;">
                    ℹ️ These are only shown while there are <strong>no approved patient reviews</strong>.
                    Patients write real reviews in their portal, and once you approve one in
                    <a href="reviews">⭐ Patient Reviews</a>, the real ones replace the samples above.
                </div>
            </div>

            <!-- ===== FAQ ===== -->
            <div class="card-box mb-3">
                <h5 class="mb-3">❓ FAQ</h5>
                <div class="row">
                    <div class="col-md-4"><label class="field-label">Small label</label>
                        <input name="land_faq_eyebrow" class="form-control mb-3" value="<?= e(v('land_faq_eyebrow','FAQ')) ?>"></div>
                    <div class="col-md-4"><label class="field-label">Heading</label>
                        <input name="land_faq_heading" class="form-control mb-3" value="<?= e(v('land_faq_heading','Frequently asked')) ?>"></div>
                    <div class="col-md-4"><label class="field-label">Heading highlight</label>
                        <input name="land_faq_highlight" class="form-control mb-3" value="<?= e(v('land_faq_highlight','questions')) ?>"></div>
                </div>
                <label class="field-label">Questions — one per line: <code>Question | Answer</code></label>
                <textarea name="land_faqs" class="form-control" rows="6"><?= e(v('land_faqs',$defFaqs)) ?></textarea>
            </div>

            <!-- ===== CONTACT ===== -->
            <div class="card-box mb-3">
                <h5 class="mb-3">📍 Contact</h5>
                <div class="row">
                    <div class="col-md-4"><label class="field-label">Small label</label>
                        <input name="land_contact_eyebrow" class="form-control mb-3" value="<?= e(v('land_contact_eyebrow','Contact')) ?>"></div>
                    <div class="col-md-4"><label class="field-label">Heading</label>
                        <input name="land_contact_heading" class="form-control mb-3" value="<?= e(v('land_contact_heading','Get in')) ?>"></div>
                    <div class="col-md-4"><label class="field-label">Heading highlight</label>
                        <input name="land_contact_highlight" class="form-control mb-3" value="<?= e(v('land_contact_highlight','touch')) ?>"></div>
                </div>
                <label class="field-label">Subtitle</label>
                <input name="land_contact_subtitle" class="form-control mb-3" value="<?= e(v('land_contact_subtitle',"Questions before booking? We're here to help.")) ?>">
                <div class="row">
                    <div class="col-md-6"><label class="field-label">Clinic Address</label>
                        <input name="land_contact_address" class="form-control mb-3" value="<?= e(v('land_contact_address','123 Dental St., Naic, Cavite, Philippines')) ?>"></div>
                    <div class="col-md-6"><label class="field-label">Contact Number</label>
                        <input name="land_contact_phone" class="form-control mb-3" value="<?= e(preg_replace('/\D/', '', v('land_contact_phone','0461234567'))) ?>" type="tel" inputmode="numeric" data-digits maxlength="11"></div>
                    <div class="col-md-6"><label class="field-label">Email Address</label>
                        <input name="land_contact_email" class="form-control mb-3" value="<?= e(v('land_contact_email','hello@stthereesedental.ph')) ?>"></div>
                    <div class="col-md-6"><label class="field-label">Office Hours</label>
                        <input name="land_contact_hours" class="form-control mb-3" value="<?= e(v('land_contact_hours','Mon–Sat · 9:00 AM – 5:00 PM')) ?>"></div>
                </div>

                <label class="field-label">📍 Google Map Location</label>
                <input name="land_map" class="form-control mb-1"
                       value="<?= e(v('land_map','14.2845224,120.9999204')) ?>"
                       placeholder="14.2845224,120.9999204">
                <div class="alert mb-0" style="background:#eef7f6;border:1px solid #cfe0dd;color:#3f5350;font-size:.8rem;">
                    You can put any of these here:
                    <ul class="mb-1 ps-3 mt-1">
                        <li><strong>Coordinates</strong> (most exact) — e.g. <code>14.2845224,120.9999204</code></li>
                        <li><strong>An address or place name</strong> — e.g. <code>St. Therese of Carmel Dental Clinic, General Mariano Alvarez, Cavite</code></li>
                        <li><strong>A Google embed link</strong> — in Google Maps click <em>Share → Embed a map</em>, then copy only the
                            <code>https://www.google.com/maps/embed?pb=...</code> part from inside the code it gives you.</li>
                    </ul>
                    💡 A short link like <code>share.google/…</code> or <code>maps.app.goo.gl/…</code> will <strong>not</strong> work —
                    Google blocks those from being embedded. Use one of the three above.
                    Leave the box empty to hide the map.
                </div>
            </div>

            <!-- ===== CTA + FOOTER ===== -->
            <div class="card-box mb-3">
                <h5 class="mb-3">📣 Call-to-Action &amp; Footer</h5>
                <label class="field-label">CTA heading</label>
                <input name="land_cta_heading" class="form-control mb-3" value="<?= e(v('land_cta_heading','Ready to schedule your dental appointment?')) ?>">
                <label class="field-label">CTA text</label>
                <textarea name="land_cta_text" class="form-control mb-3" rows="2"><?= e(v('land_cta_text','Create an account or log in to book your next appointment quickly and conveniently.')) ?></textarea>
                <label class="field-label">Footer description</label>
                <textarea name="land_footer_desc" class="form-control" rows="2"><?= e(v('land_footer_desc','Making dental appointments simple, fast, and convenient for patients and clinic staff alike.')) ?></textarea>
            </div>

            <div class="d-flex gap-2 mb-4">
                <button class="btn btn-teal">💾 Save Landing Page</button>
                <a href="./" target="_blank" class="btn btn-light">👁 Preview</a>
            </div>
        </form>

        <!-- ===== IMAGES ===== -->
        <div class="card-box mb-3">
            <h5 class="mb-1">🖼️ Images</h5>
            <div class="text-muted2 mb-3" style="font-size:.85rem;">
                Optional. Upload a photo to replace the built-in tooth illustration in the hero.
                Dentist photos are uploaded on the <a href="admin_dentists">Dentists</a> page.
            </div>

            <?php $heroImg = v('land_img_hero',''); ?>
            <div class="d-flex align-items-start gap-3 flex-wrap mb-4">
                <div style="width:190px;height:130px;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:#f4f8f8;display:grid;place-items:center;">
                    <?php if ($heroImg && is_file(__DIR__ . '/' . $heroImg)): ?>
                        <img src="<?= e($heroImg) ?>" style="width:100%;height:100%;object-fit:cover;" alt="Hero image">
                    <?php else: ?>
                        <span class="text-muted2" style="font-size:.8rem;text-align:center;padding:8px;">🦷<br>Built-in tooth<br>illustration</span>
                    <?php endif; ?>
                </div>
                <div style="flex:1;min-width:260px;">
                    <form method="POST" enctype="multipart/form-data" class="mb-2">
                        <input type="hidden" name="action" value="upload_image">
                        <input type="hidden" name="slot" value="land_img_hero">
                        <label class="field-label">Hero image <span class="text-muted2">(replaces the tooth illustration)</span></label>
                        <div class="d-flex gap-2">
                            <input type="file" name="image" class="form-control" accept="image/*" required>
                            <button class="btn btn-teal" style="white-space:nowrap;">⬆ Upload</button>
                        </div>
                    </form>
                    <?php if ($heroImg): ?>
                        <form method="POST" onsubmit="return confirm('Remove this image and go back to the tooth illustration?')">
                            <input type="hidden" name="action" value="remove_image">
                            <input type="hidden" name="slot" value="land_img_hero">
                            <button class="btn btn-sm btn-light" style="color:#c0392b;">🗑 Remove image</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php $hmoImg = v('land_img_hmo',''); ?>
            <div class="d-flex align-items-start gap-3 flex-wrap">
                <div style="width:190px;height:130px;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:#f4f8f8;display:grid;place-items:center;">
                    <?php if ($hmoImg && is_file(__DIR__ . '/' . $hmoImg)): ?>
                        <img src="<?= e($hmoImg) ?>" style="width:100%;height:100%;object-fit:cover;" alt="HMO image">
                    <?php else: ?>
                        <span class="text-muted2" style="font-size:.8rem;text-align:center;padding:8px;">🏥<br>Built-in HMO<br>list card</span>
                    <?php endif; ?>
                </div>
                <div style="flex:1;min-width:260px;">
                    <form method="POST" enctype="multipart/form-data" class="mb-2">
                        <input type="hidden" name="action" value="upload_image">
                        <input type="hidden" name="slot" value="land_img_hmo">
                        <label class="field-label">HMO poster <span class="text-muted2">("Accredited HMO's" image)</span></label>
                        <div class="d-flex gap-2">
                            <input type="file" name="image" class="form-control" accept="image/*" required>
                            <button class="btn btn-teal" style="white-space:nowrap;">⬆ Upload</button>
                        </div>
                    </form>
                    <?php if ($hmoImg): ?>
                        <form method="POST" onsubmit="return confirm('Remove this image? The built-in HMO list card comes back.')">
                            <input type="hidden" name="action" value="remove_image">
                            <input type="hidden" name="slot" value="land_img_hmo">
                            <button class="btn btn-sm btn-light" style="color:#c0392b;">🗑 Remove image</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Reset -->
        <div class="card-box mb-4">
            <h6 class="mb-1">↺ Reset to defaults</h6>
            <div class="text-muted2 mb-2" style="font-size:.85rem;">This clears all your edits and puts back the original landing page text.</div>
            <form method="POST" onsubmit="return confirm('Reset ALL landing page text back to the defaults?')">
                <input type="hidden" name="action" value="reset_landing">
                <button class="btn btn-light" style="color:#c0392b;">↺ Reset Landing Page</button>
            </form>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js"></script>
</body>
</html>
