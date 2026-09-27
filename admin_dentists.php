<?php
// ============================================================
//  DENTISTS (admin drill-down)  (admin_dentists.php) -- admin only
// ============================================================
//  A 3-level view for the admin:
//    LEVEL 1  (no parameters)           -> list of all dentists + profile
//    LEVEL 2  (?dentist=ID)             -> that dentist's patients
//    LEVEL 3  (?dentist=ID&patient=PID) -> that patient's dental chart,
//                                          remarks, and treatment notes
//
//  How dentists are linked to patients:
//    Each patient row has a `primary_dentist` text field that holds
//    the dentist's full name (e.g. "Dr. Ana Santos"). We match that
//    against the dentist's `name` in the users table.
// ============================================================
require_once 'config/auth.php';
require_login(['admin']);              // only admins can open this page
require_once 'includes/teeth.php';     // for the read-only dental chart
require_once 'includes/mailer.php';
require_once 'includes/message_templates.php';    // to email new dentists their login details

// ---------- Add / Delete a dentist (used on the dentist list) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_dentist') {
        // The form now asks for the parts separately, but we still store ONE name
        // because patients.primary_dentist and appointments.dentist are matched
        // against users.name.
        $title = trim($_POST['title'] ?? '');
        $first = trim($_POST['first_name'] ?? '');
        $last  = trim($_POST['last_name'] ?? '');
        $name  = trim(preg_replace('/\s+/', ' ', "$title $first $last"));

        $email   = strtolower(trim($_POST['email']));
        $spec    = trim($_POST['specialty'] ?? '');
        [$contact, $phoneError] = validate_phone($_POST['contact'] ?? '');

        // ---- Make sure the email is a REAL, usable address ----
        $emailError = '';
        if ($first === '' || $last === '') {
            $emailError = 'Please enter both a first name and a last name.';
        } elseif ($phoneError !== '') {
            $emailError = $phoneError;
        } else {
            // Shared check: format, the domain really accepts mail, and not taken.
            $emailError = validate_account_email($pdo, $email);
        }
        if ($emailError !== '') {
            set_flash($emailError, 'error');
            header("Location: admin_dentists"); exit;
        }

        // New dentists get the default password 'password123' (they change it later).
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        try {
            $pdo->prepare("INSERT INTO users (name,email,password,role,specialty,contact,status)
                           VALUES (?,?,?,'dentist',?,?,'active')")
                ->execute([$name,$email,$hash,$spec,$contact]);

            // Email their login details. This is useful for them AND it proves the
            // address really works — if it bounces, we say so.
            $note = '';
            if (mail_is_ready($pdo)) {
                $cat = message_catalogue()['account_welcome'];
                [$wSubj, $body] = tpl_message($pdo, 'account_welcome', $cat['subject'], $cat['body'], [
                    'name'     => $name,
                    'email'    => $email,
                    'password' => 'password123',
                    'clinic'   => clinic_name($pdo),
                ]);
                $err = '';
                $note = send_mail($pdo, $email, $wSubj, $body, $err, 'dentist_welcome')
                      ? ' Their login details were emailed to them.'
                      : " (Note: the welcome email could not be sent — $err)";
            }
            set_flash("$name added. Their password is password123." . $note);
            header("Location: admin_dentists"); exit;
        } catch (PDOException $ex) {
            set_flash('That email is already in use. Please use a different email.', 'error');
            header("Location: admin_dentists"); exit;
        }
    }

    // ---- Upload a photo for a dentist (shown on the landing page) ----
    if ($action === 'upload_photo') {
        $did = (int)$_POST['id'];
        $dir = __DIR__ . '/uploads/landing';
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                $fname = 'dentist_' . $did . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], "$dir/$fname")) {
                    $pdo->prepare("UPDATE users SET photo=? WHERE id=? AND role='dentist'")
                        ->execute(['uploads/landing/' . $fname, $did]);
                    set_flash('Photo uploaded — it now shows on the landing page.');
                } else {
                    set_flash('Could not save the photo.', 'error');
                }
            } else {
                set_flash('Please choose an image file (jpg, png, gif, webp).', 'error');
            }
        } else {
            set_flash('Please choose a photo first.', 'error');
        }
        header("Location: admin_dentists"); exit;
    }

    if ($action === 'delete_dentist') {
        // Get the dentist's name first (needed to find their patients).
        $d = $pdo->prepare("SELECT name FROM users WHERE id=? AND role='dentist'");
        $d->execute([$_POST['id']]);
        $dname = $d->fetchColumn();

        // Delete the dentist account.
        $pdo->prepare("DELETE FROM users WHERE id=? AND role='dentist'")->execute([$_POST['id']]);

        // Auto-transfer this dentist's patients to the REMAINING dentists.
        // We reassign one patient at a time using the same auto-balancer, so the
        // patients get spread evenly (each goes to whoever has the fewest).
        $moved = 0;
        if ($dname) {
            $orphans = $pdo->prepare("SELECT id FROM patients WHERE primary_dentist = ?");
            $orphans->execute([$dname]);
            foreach ($orphans->fetchAll(PDO::FETCH_COLUMN) as $opid) {
                $newDentist = pick_dentist_for_new_patient($pdo);   // least-loaded remaining dentist (or null if none left)
                $pdo->prepare("UPDATE patients SET primary_dentist = ? WHERE id = ?")->execute([$newDentist, $opid]);
                $moved++;
            }
        }

        log_activity($pdo, 'Deleted dentist', ($dname ?: '#' . $_POST['id']) . ($moved ? " ($moved patient(s) reassigned)" : ''));

        if ($moved > 0) {
            set_flash("Dentist removed. $moved patient(s) transferred to another dentist.", 'info');
        } else {
            set_flash('Dentist removed.', 'info');
        }
        header("Location: admin_dentists"); exit;
    }
}

// Which level are we on?
$did = (int)($_GET['dentist'] ?? 0);
$pid = (int)($_GET['patient'] ?? 0);

// Small helper: status word -> badge CSS class (same idea as reports.php).
function badge_for($status) {
    $map = [
        'Completed'=>'b-completed','In Progress'=>'b-progress','Pending'=>'b-pending',
        'Confirmed'=>'b-confirmed','Cancelled'=>'b-cancelled','No-show'=>'b-noshow',
        'Rescheduled'=>'b-progress','Active'=>'b-active','Inactive'=>'b-inactive',
    ];
    return $map[$status] ?? 'b-pending';
}
// Small helper: initials for the round avatar.
function initials($name) {
    return strtoupper(substr($name,0,1) . (strpos($name,' ') ? substr(strstr($name,' '),1,1) : ''));
}

$page_title = "Dentists";
include 'includes/head.php';
$active = 'dentists';
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">

    <?php
    // ==========================================================
    //  LEVEL 3 : one patient's clinical detail
    // ==========================================================
    if ($did && $pid):
        $d = $pdo->prepare("SELECT * FROM users WHERE id=? AND role='dentist'"); $d->execute([$did]); $dentist = $d->fetch();
        $p = $pdo->prepare("SELECT * FROM patients WHERE id=?");                 $p->execute([$pid]); $patient = $p->fetch();

        // dental chart data
        $toothMap = build_tooth_map($pdo, $pid);
        $legendColors = ['Healthy'=>'#6bbf6b','Decayed'=>'#e0a92e','Filled'=>'#3b9ae0','Missing'=>'#e05b5b',
                         'Crowned'=>'#a06fd6','Extracted'=>'#999','Impacted'=>'#d99a00','Fractured'=>'#cc4444'];
        // count each condition
        $counts = [];
        foreach (array_merge($UPPER_TEETH,$LOWER_TEETH) as $t) { $s=$toothMap[$t]??'Healthy'; $counts[$s]=($counts[$s]??0)+1; }

        // treatments for this patient
        $tr = $pdo->prepare("SELECT * FROM treatments WHERE patient_id=? ORDER BY treatment_date DESC"); $tr->execute([$pid]);
        $treatments = $tr->fetchAll();
    ?>
        <!-- breadcrumb -->
        <div class="mb-2" style="font-size:.9rem;">
            <a href="admin_dentists">Dentists</a> ›
            <a href="admin_dentists?dentist=<?= $did ?>"><?= e($dentist['name'] ?? 'Dentist') ?></a> ›
            <span class="text-muted2"><?= e($patient['name'] ?? 'Patient') ?></span>
        </div>

        <div class="page-head">
            <div><h1><?= e($patient['name']) ?></h1><div class="sub">Dental chart &amp; treatment records — under <?= e($dentist['name']) ?></div></div>
            <a href="admin_dentists?dentist=<?= $did ?>" class="btn btn-light">← Back to patients</a>
        </div>

        <!-- patient info strip -->
        <div class="card-box mb-3">
            <div class="d-flex flex-wrap gap-4" style="font-size:.9rem;">
                <div><div class="field-label">Age</div><?= e($patient['age']) ?> yrs</div>
                <div><div class="field-label">Blood Type</div><?= e($patient['blood_type']) ?></div>
                <div><div class="field-label">Phone</div><?= e($patient['phone']) ?></div>
                <div><div class="field-label">Type</div><span class="badge-pill <?= badge_for($patient['patient_type']) ?>"><?= e($patient['patient_type']) ?></span></div>
                <div><div class="field-label">Status</div><span class="badge-pill <?= badge_for($patient['status']) ?>"><?= e($patient['status']) ?></span></div>
                <?php if ($patient['medical_alert']): ?>
                    <div><div class="field-label">Medical Alert</div><span style="color:#c0392b;">⚠️ <?= e($patient['medical_alert']) ?></span></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <!-- read-only dental chart -->
            <div class="col-lg-8">
                <div class="card-box">
                    <h5 class="mb-3">🦷 Dental Chart <small class="text-muted2 d-block" style="font-size:.75rem;">Read-only view</small></h5>

                    <div class="text-center mb-1"><span class="odo-section-label">Upper (Maxillary)</span></div>
                    <div class="odo-arch">
                        <?php foreach ($UPPER_TEETH as $t) echo render_tooth($t, $toothMap[$t] ?? 'Healthy'); ?>
                    </div>
                    <div class="odo-midline">— MIDLINE —</div>
                    <div class="odo-arch">
                        <?php foreach ($LOWER_TEETH as $t) echo render_tooth($t, $toothMap[$t] ?? 'Healthy'); ?>
                    </div>
                    <div class="text-center mt-1"><span class="odo-section-label">Lower (Mandibular)</span></div>

                    <div class="odo-legend">
                        <?php foreach ($TOOTH_STATUSES as $st): ?>
                            <span><i class="legend-dot" style="background:<?= $legendColors[$st] ?>"></i><?= $st ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- summary + remarks -->
            <div class="col-lg-4">
                <div class="card-box">
                    <h5>Select a Tooth</h5>
                    <div id="tooth-panel"><p class="text-muted2">Click a tooth to see its condition</p></div>
                </div>
                <div class="card-box">
                    <h5>Chart Summary</h5>
                    <?php foreach (['Healthy','Decayed','Filled','Crowned','Missing'] as $st): ?>
                        <div class="flex-between py-1">
                            <span><i class="legend-dot" style="background:<?= $legendColors[$st] ?>"></i> <?= $st ?></span>
                            <strong><?= $counts[$st] ?? 0 ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="card-box">
                    <h5>📝 General Remarks</h5>
                    <?php if (!empty($patient['chart_remarks'])): ?>
                        <p style="font-size:.9rem;white-space:pre-wrap;"><?= e($patient['chart_remarks']) ?></p>
                    <?php else: ?>
                        <p class="text-muted2" style="font-size:.9rem;">No remarks recorded yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- treatment notes -->
        <div class="card-box">
            <h5 class="mb-3">📋 Treatment Records &amp; Notes</h5>
            <div class="table-responsive">
                <table class="data">
                    <thead><tr><th>Date</th><th>Treatment</th><th>Tooth</th><th>Dentist</th><th>Status</th><th>Notes</th></tr></thead>
                    <tbody>
                    <?php foreach ($treatments as $t): ?>
                        <tr>
                            <td><?= e($t['treatment_date']) ?></td>
                            <td><strong><?= e($t['treatment_name']) ?></strong></td>
                            <td><?= e($t['tooth']) ?></td>
                            <td><?= e($t['dentist']) ?></td>
                            <td><span class="badge-pill <?= badge_for($t['status']) ?>"><?= e($t['status']) ?></span></td>
                            <td><?= e($t['notes']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($treatments)): ?>
                        <tr><td colspan="6" class="text-center text-muted2">No treatment records for this patient yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php
    // ==========================================================
    //  LEVEL 2 : one dentist's patients
    // ==========================================================
    elseif ($did):
        $d = $pdo->prepare("SELECT * FROM users WHERE id=? AND role='dentist'"); $d->execute([$did]); $dentist = $d->fetch();

        // patients whose primary_dentist matches this dentist's name
        $ps = $pdo->prepare("SELECT * FROM patients WHERE primary_dentist = ? ORDER BY name");
        $ps->execute([$dentist['name'] ?? '']);
        $docPatients = $ps->fetchAll();
    ?>
        <div class="mb-2" style="font-size:.9rem;">
            <a href="admin_dentists">Dentists</a> › <span class="text-muted2"><?= e($dentist['name'] ?? 'Dentist') ?></span>
        </div>

        <div class="page-head">
            <div><h1><?= e($dentist['name']) ?></h1><div class="sub"><?= e($dentist['specialty'] ?: 'Dentist') ?></div></div>
            <a href="admin_dentists" class="btn btn-light">← Back to dentists</a>
        </div>

        <!-- dentist profile card -->
        <div class="card-box mb-3">
            <div class="d-flex align-items-center gap-3">
                <span class="avatar" style="background:#5e8a86;width:56px;height:56px;font-size:1.2rem;"><?= e(initials($dentist['name'])) ?></span>
                <div>
                    <h5 class="mb-0"><?= e($dentist['name']) ?> <span class="badge-pill <?= badge_for($dentist['status']) ?>"><?= ucfirst($dentist['status']) ?></span></h5>
                    <div class="text-muted2" style="font-size:.9rem;">
                        <?= e($dentist['specialty'] ?: 'General Dentistry') ?> · 📧 <?= e($dentist['email']) ?> · 📞 <?= e($dentist['contact']) ?>
                    </div>
                </div>
                <div class="ms-auto text-center">
                    <div style="font-size:1.8rem;font-weight:700;color:var(--teal-mid);"><?= count($docPatients) ?></div>
                    <div class="field-label">Patients</div>
                </div>
            </div>
        </div>

        <div class="card-box">
            <h5 class="mb-3">Patients under <?= e($dentist['name']) ?></h5>
            <div class="table-responsive">
                <table class="data">
                    <thead><tr><th>Patient</th><th>Age</th><th>Type</th><th>Last Visit</th><th>Next Visit</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($docPatients as $pt): ?>
                        <tr>
                            <td><div class="d-flex align-items-center gap-2">
                                <span class="avatar" style="background:#7a9bd6;"><?= e(initials($pt['name'])) ?></span>
                                <div><strong><?= e($pt['name']) ?></strong><br><small class="text-muted2"><?= e($pt['email']) ?></small></div>
                            </div></td>
                            <td><?= e($pt['age']) ?></td>
                            <td><span class="badge-pill <?= badge_for($pt['patient_type']) ?>"><?= e($pt['patient_type']) ?></span></td>
                            <td><?= e($pt['last_visit']) ?></td>
                            <td><?= e($pt['next_visit']) ?></td>
                            <td><span class="badge-pill <?= badge_for($pt['status']) ?>"><?= e($pt['status']) ?></span></td>
                            <td><a href="admin_dentists?dentist=<?= $did ?>&patient=<?= $pt['id'] ?>" class="btn btn-sm btn-teal">View Chart &amp; Records →</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($docPatients)): ?>
                        <tr><td colspan="7" class="text-center text-muted2">This dentist has no assigned patients yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php
    // ==========================================================
    //  LEVEL 1 : list of all dentists
    // ==========================================================
    else:
        $dentists = $pdo->query("SELECT * FROM users WHERE role='dentist' ORDER BY name")->fetchAll();
    ?>
        <div class="page-head">
            <div><h1>Dentists</h1><div class="sub">View each dentist's profile and the patients assigned to them</div></div>
            <div class="d-flex align-items-center gap-3">
                <div class="clock"><span class="time" id="clock"></span><br><span id="clock-date"></span></div>
                <button class="btn btn-teal" data-bs-toggle="modal" data-bs-target="#dentistModal">+ Add Dentist</button>
            </div>
        </div>

        <div class="row g-3">
            <?php foreach ($dentists as $doc):
                // how many patients are assigned to this dentist?
                $cnt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE primary_dentist = ?");
                $cnt->execute([$doc['name']]);
                $patientCount = $cnt->fetchColumn();
            ?>
                <div class="col-md-6 col-xl-4">
                    <div class="card-box h-100">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <?php if (!empty($doc['photo']) && is_file(__DIR__ . '/' . $doc['photo'])): ?>
                                <img src="<?= e($doc['photo']) ?>" alt="" style="width:52px;height:52px;border-radius:50%;object-fit:cover;">
                            <?php else: ?>
                                <span class="avatar" style="background:#5e8a86;width:52px;height:52px;font-size:1.1rem;"><?= e(initials($doc['name'])) ?></span>
                            <?php endif; ?>
                            <div>
                                <h6 class="mb-0"><?= e($doc['name']) ?></h6>
                                <span class="badge-pill <?= badge_for($doc['status']) ?>"><?= ucfirst($doc['status']) ?></span>
                            </div>
                        </div>
                        <div class="text-muted2 mb-1" style="font-size:.85rem;">🦷 <?= e($doc['specialty'] ?: 'General Dentistry') ?></div>
                        <div class="text-muted2 mb-1" style="font-size:.85rem;">📧 <?= e($doc['email']) ?></div>
                        <div class="text-muted2 mb-3" style="font-size:.85rem;">📞 <?= e($doc['contact'] ?: '—') ?></div>

                        <form method="POST" enctype="multipart/form-data" class="mb-3">
                            <input type="hidden" name="action" value="upload_photo">
                            <input type="hidden" name="id" value="<?= $doc['id'] ?>">
                            <label class="field-label" style="font-size:.72rem;">Photo (shows on the landing page)</label>
                            <div class="d-flex gap-1">
                                <input type="file" name="photo" class="form-control form-control-sm" accept="image/*" required>
                                <button class="btn btn-sm btn-light" style="white-space:nowrap;">⬆</button>
                            </div>
                        </form>

                        <div class="flex-between" style="border-top:1px solid #eee;padding-top:10px;">
                            <span><strong style="font-size:1.3rem;color:var(--teal-mid);"><?= $patientCount ?></strong> <span class="text-muted2" style="font-size:.85rem;">patients</span></span>
                            <div class="d-flex gap-1">
                                <a href="admin_dentists?dentist=<?= $doc['id'] ?>" class="btn btn-sm btn-teal">View →</a>
                                <form method="POST" onsubmit="return confirm('Delete <?= e(addslashes($doc['name'])) ?>? Their patients will be transferred to another dentist.')">
                                    <input type="hidden" name="action" value="delete_dentist">
                                    <input type="hidden" name="id" value="<?= $doc['id'] ?>">
                                    <button class="btn btn-sm" style="background:#fbdcdc;color:#c0392b;">🗑</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (empty($dentists)): ?>
                <div class="col-12"><div class="card-box text-center text-muted2">No dentists yet. Click <strong>+ Add Dentist</strong> to create one.</div></div>
            <?php endif; ?>
        </div>

        <!-- Add Dentist modal (only shown on the dentist list) -->
        <div class="modal fade" id="dentistModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_dentist">
                <div class="modal-header"><h5 class="modal-title">Add Dentist</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-4">
                            <label class="field-label">Title</label>
                            <select name="title" class="form-select mb-3">
                                <option value="Dr." selected>Dr.</option>
                                <option value="Dra.">Dra.</option>
                                <option value="">(none)</option>
                            </select>
                        </div>
                        <div class="col-8">
                            <label class="field-label">First Name</label>
                            <input name="first_name" class="form-control mb-3" placeholder="Juan" required>
                        </div>
                    </div>

                    <label class="field-label">Last Name</label>
                    <input name="last_name" class="form-control mb-1" placeholder="Dela Cruz" required>
                    <div class="text-muted2 mb-3" style="font-size:.78rem;">
                        Saved as one name, e.g. <strong>Dr. Juan Dela Cruz</strong> — this is what patients and appointments are matched against.
                    </div>

                    <label class="field-label">Email</label>
                    <input type="email" name="email" class="form-control mb-1" placeholder="dentist@gmail.com" required>
                    <div class="text-muted2 mb-3" style="font-size:.78rem;">
                        Must be a real, working address — we check the domain and email their login details here.
                    </div>

                    <label class="field-label">Specialty / Expertise</label>
                    <input name="specialty" class="form-control mb-1" placeholder="e.g. Orthodontics, Oral Surgery" required>
                    <div class="text-muted2 mb-3" style="font-size:.78rem;">Shown on the public landing page under "Meet Our Dentists".</div>

                    <label class="field-label">Contact Number</label>
                    <input name="contact" class="form-control mb-3" placeholder="09XX XXX XXXX" <?= phone_input_attrs() ?> required>

                    <div class="alert alert-secondary py-2 mb-0" style="font-size:.83rem;">
                        The new dentist's password will be <strong>password123</strong>. They can change it later.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-teal">Add Dentist</button>
                </div>
            </form>
        </div></div></div>
    <?php endif; ?>

    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js"></script>
<script>if (document.getElementById('clock')) startClock();</script>
</body>
</html>
