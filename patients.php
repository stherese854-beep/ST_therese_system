<?php
// ============================================================
//  PATIENTS  (patients.php)  -- list, search, add, edit, view
// ============================================================
require_once 'config/auth.php';
require_once 'includes/mailer.php';
require_once 'includes/noshow_check.php';   // patient_noshow_count()
require_login(['admin','dentist','staff']);

// ---------- Handle ADD / EDIT / DELETE (form actions) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id    = $_POST['id'] ?? '';
        // Build the full name from separate First + Last name fields.
        $first = trim($_POST['first_name'] ?? '');
        $last  = trim($_POST['last_name'] ?? '');
        $name  = trim($first . ' ' . $last);
        $email = trim($_POST['email']);
        [$phone, $phoneError] = validate_phone($_POST['phone'] ?? '', false);   // optional, but must be real
        if ($phoneError !== '') {
            set_flash($phoneError, 'error');
            header("Location: patients"); exit;
        }
        $status= $_POST['status'] ?? 'Active';
        $ptype = $_POST['patient_type'] ?? 'New';
        $vreason = trim($_POST['visit_reason'] ?? '');
        if ($email !== '' && ($ep = email_problem($email)) !== '') {          // a real address only
            set_flash($ep, 'error');
            header("Location: patients"); exit;
        }
        $newpass = $_POST['password'] ?? '';
        if ($newpass !== '' && ($pwp = password_problem($newpass)) !== '') {   // only Strong passwords
            set_flash($pwp, 'error');
            header("Location: patients"); exit;
        }
        if ($newpass !== '' && $newpass !== ($_POST['password_confirm'] ?? '')) {
            set_flash('The passwords do not match. Please type the same password twice.', 'error');
            header("Location: patients"); exit;
        }
        // Birthday / age: only admin and staff may set them (the age follows the birthday).
        $canDob = in_array(current_role(), ['admin','staff'], true);
        $dob    = $canDob ? trim($_POST['dob'] ?? '') : '';
        if ($canDob && ($de = birth_date_error($dob)) !== '') {
            set_flash($de, 'error');
            header("Location: patients"); exit;
        }
        $ageIn  = trim($_POST['age'] ?? '');
        $age    = $dob !== '' ? age_from_dob($dob) : ($ageIn !== '' ? (int)$ageIn : null);

        $emailNote = '';
        if ($id) {
            // A LOGIN email is protected: only the admin may change it, and only after the
            // new address is confirmed from a link (the old address is told). A person with
            // no login (booked by someone else) just has a contact email — saved as typed.
            $lu = $pdo->prepare("SELECT u.id, u.email FROM patients p JOIN users u ON u.id = p.user_id WHERE p.id = ?");
            $lu->execute([$id]);
            $login = $lu->fetch();
            if ($login && strcasecmp(trim($login['email']), $email) !== 0) {
                if ($email === '') {
                    $emailNote = ' The login email cannot be left empty, so it was kept.';
                } elseif (current_role() !== 'admin') {
                    $emailNote = ' The login email was NOT changed — only the admin can change it.';
                } else {
                    require_once 'includes/account_transfer.php';
                    [$okEc, $msgEc] = request_email_change($pdo, $login['id'], $id, $email, $_SESSION['name'] ?? 'Admin');
                    $emailNote = ' ' . $msgEc;
                    if (!$okEc) $emailNote = ' The login email was NOT changed: ' . $msgEc;
                }
                $email = $login['email'];           // stays as it is until confirmed
            }

            // UPDATE the patient record.
            // Age and blood type are left alone here — they are edited in Records.
            $pdo->prepare("UPDATE patients SET name=?, email=?, phone=?, status=?, patient_type=?, visit_reason=? WHERE id=?")
                ->execute([$name, $email, $phone, $status, $ptype, $vreason, $id]);
            if ($canDob) {
                $pdo->prepare("UPDATE patients SET date_of_birth=?, age=? WHERE id=?")->execute([$dob ?: null, $age, $id]);
            }

            // If this patient has a LOGIN account, keep it in sync (name + email),
            // and change the password if a new one was typed.
            $uidStmt = $pdo->prepare("SELECT user_id FROM patients WHERE id=?");
            $uidStmt->execute([$id]);
            $uid = $uidStmt->fetchColumn();
            if ($uid) {
                try {
                    $pdo->prepare("UPDATE users SET name=?, email=? WHERE id=?")->execute([$name, $email, $uid]);
                    if ($newpass !== '') {
                        $pdo->prepare("UPDATE users SET password=? WHERE id=?")
                            ->execute([password_hash($newpass, PASSWORD_DEFAULT), $uid]);
                    }
                } catch (PDOException $ex) {
                    set_flash('Patient saved, but that email is already used by another account.', 'error');
                    header("Location: patients"); exit;
                }
            }
        } else {
            // INSERT new patient. Also create a LOGIN ACCOUNT for them when an email
            // is given, so they can book appointments later even as a walk-in.
            // Left blank, a random Strong temporary password is created (shown once).
            $newUserId = null;
            if ($email !== '') {
                $accPass = ($newpass !== '') ? $newpass : generate_temp_password();
                try {
                    $pdo->prepare("INSERT INTO users (name,email,password,role,status) VALUES (?,?,?,'patient','active')")
                        ->execute([$name, $email, password_hash($accPass, PASSWORD_DEFAULT)]);
                    $newUserId = $pdo->lastInsertId();
                } catch (PDOException $ex) {
                    set_flash('That email is already used by another account. Patient not added.', 'error');
                    header("Location: patients"); exit;
                }
            }

            // If a DENTIST adds the patient, assign it to them (so it shows in
            // their "My Patients"). Otherwise auto-balance across dentists.
            $assignedDentist = (current_role() === 'dentist')
                ? ($_SESSION['name'] ?? null)
                : pick_dentist_for_new_patient($pdo);
            $pdo->prepare("INSERT INTO patients (user_id,name,email,phone,status,patient_type,primary_dentist,date_of_birth,age) VALUES (?,?,?,?,?,'New',?,?,?)")
                ->execute([$newUserId, $name, $email, $phone, $status, $assignedDentist, $dob ?: null, $age]);

            // Message that explains whether an account was created.
            if ($newUserId) {
                $newMsg = ($newpass !== '')
                    ? 'New patient added with a login account.'
                    : 'New patient added. Login account created — temporary password: ' . $accPass . ' (give it to the patient; they should change it after signing in).';
            } else {
                $newMsg = 'New patient added (no email given, so no login account yet).';
            }
        }
        // Emergency contact (front desk keeps it; the dentist sees it in Records).
        [$emPhone, $emErr] = validate_phone($_POST['emergency_phone'] ?? '', false);
        $emTarget = $id ? (int)$id : (int)$pdo->lastInsertId();
        if ($emTarget) {
            try {
                $pdo->prepare("UPDATE patients SET emergency_name = ?, emergency_phone = ? WHERE id = ?")
                    ->execute([trim($_POST['emergency_name'] ?? '') ?: null, $emErr === '' ? ($emPhone ?: null) : null, $emTarget]);
            } catch (Throwable $e) {}
        }
        log_activity($pdo, $id ? 'Updated patient' : 'Added patient', $name);
        set_flash($id ? 'Patient updated.' . $emailNote : $newMsg,
                  strpos($emailNote, 'NOT') !== false || strpos($emailNote, 'cannot') !== false ? 'warning' : 'success');
        header("Location: patients"); exit;
    }

    if ($action === 'delete') {
      $movedNames = [];
      foreach (bulk_ids() as $delId) {             // one patient, or several ticked ones

        // Deleting no longer removes the patient right away — it moves the
        // record to the Archive first (same as User Management), so nothing
        // is lost by mistake. An admin can restore it or permanently delete
        // it from the Archive page.
        $delNameStmt = $pdo->prepare("SELECT name FROM patients WHERE id=?");
        $delNameStmt->execute([$delId]);
        $delPatientName = $delNameStmt->fetchColumn();
        if ($delPatientName === false) continue;

        archive_patient($pdo, $delId, $_SESSION['name'] ?? null);
        $movedNames[] = $delPatientName ?: ('#' . $delId);
      }
        set_flash(count($movedNames) === 1 ? $movedNames[0] . ' moved to Archive.' : count($movedNames) . ' patients moved to Archive.', 'info');
        header("Location: patients"); exit;
    }

    // ---------- Send a PERSONAL Email/SMS to ONE patient ----------
    if ($action === 'message') {
        $pid     = (int)$_POST['patient_id'];
        $via     = $_POST['via'] ?? 'email';        // 'email' or 'sms'
        $subject = trim($_POST['subject'] ?? '');   // editable (email only)
        $message = trim($_POST['message'] ?? '');   // the editable message body

        // Look up this patient's real contact details.
        $stmt = $pdo->prepare("SELECT name, email, phone FROM patients WHERE id=?");
        $stmt->execute([$pid]);
        $pt = $stmt->fetch();

        if ($via === 'sms') {
            // SMS needs a paid gateway, which this system does not use.
            set_flash('SMS sending is not set up. Please use Email instead.', 'error');
            header("Location: patients"); exit;
        }

        $to = trim($pt['email'] ?? '');
        if ($to === '') {
            set_flash('That patient has no email address on file.', 'error');
        } elseif (!mail_is_ready($pdo)) {
            set_flash('Email is not configured yet. Set it up in Messaging Config first.', 'error');
        } else {
            if ($subject === '') $subject = 'A message from your dental clinic';
            // nl2br keeps the admin's line breaks in the HTML email.
            $html = mail_template($subject, nl2br(e($message)));
            $err  = '';
            if (send_mail($pdo, $to, $subject, $html, $err, 'patient_message')) {
                log_activity($pdo, 'Emailed patient', ($pt['name'] ?? 'Patient'));
                set_flash("Email sent to " . ($pt['name'] ?? 'patient') . " ($to).");
            } else {
                set_flash("Could not send the email: $err", 'error');
            }
        }
        header("Location: patients"); exit;
    }

    // ---------- Manually assign a dentist to a patient (admin) ----------
    // ---------- Pause / resume online booking by hand ----------
    // Staff may need to stop a patient booking online for reasons that have
    // nothing to do with missed visits (unpaid balance, repeated abuse of the
    // system, a case that needs to be handled in person, and so on).
    if ($action === 'toggle_booking_block') {
        if (!in_array(current_role(), ['admin','staff'])) {
            set_flash('Only admin and staff can pause online booking.', 'error');
            header("Location: patients"); exit;
        }
        $bid    = (int)($_POST['id'] ?? 0);
        $turnOn = ($_POST['block'] ?? '') === '1';
        $reason = trim($_POST['block_reason'] ?? '');

        $nm = $pdo->prepare("SELECT name FROM patients WHERE id=?");
        $nm->execute([$bid]);
        $pname = $nm->fetchColumn() ?: 'Patient';

        if ($turnOn) {
            if ($reason === '') {
                set_flash('Please give a reason — the patient will be shown this message.', 'error');
                header("Location: patients"); exit;
            }
            $pdo->prepare(
                "UPDATE patients
                    SET booking_blocked=1, booking_block_reason=?, booking_blocked_by=?, booking_blocked_at=NOW()
                  WHERE id=?"
            )->execute([$reason, $_SESSION['name'] ?? 'admin', $bid]);
            log_activity($pdo, 'Paused online booking', $pname);
            set_flash("Online booking paused for $pname.");
        } else {
            $pdo->prepare(
                "UPDATE patients
                    SET booking_blocked=0, booking_block_reason=NULL, booking_blocked_by=NULL, booking_blocked_at=NULL
                  WHERE id=?"
            )->execute([$bid]);
            log_activity($pdo, 'Resumed online booking', $pname);
            set_flash("$pname can book online again.");
        }
        header("Location: patients"); exit;
    }

    // ---------- Restore online booking after missed appointments ----------
    // Once staff have spoken to the patient, they can lift the block. The
    // missed visits stay on record; we only note the moment it was lifted,
    // so a NEW block can still build up from here on.
    // ---- Give a booked-for adult their own account (emailed invite) — admin / staff ----
    if (in_array($action, ['admin_invite_own', 'admin_cancel_invite'], true)) {
        require_once 'includes/account_transfer.php';
        $iid = (int)($_POST['id'] ?? 0);
        if (!in_array(current_role(), ['admin','staff'], true)) {
            set_flash('Only the admin or staff can do this.', 'error');
        } elseif ($action === 'admin_cancel_invite') {
            cancel_account_invite($pdo, $iid);
            set_flash('The invite was cancelled. The link no longer works.', 'info');
        } else {
            [$okI, $msgI] = send_account_invite($pdo, $iid, $_POST['invite_email'] ?? '', $_SESSION['name'] ?? 'Clinic');
            set_flash($msgI, $okI ? 'success' : 'error');
        }
        header("Location: patients"); exit;
    }

    // ---- Merge two records of the same person — admin only ----
    if ($action === 'merge_records') {
        require_once 'includes/account_transfer.php';
        if (current_role() !== 'admin') {
            set_flash('Only the admin can merge records.', 'error');
        } else {
            [$okM, $msgM] = merge_patient_records($pdo, $_POST['keep_id'] ?? 0, $_POST['dup_id'] ?? 0, $_SESSION['name'] ?? 'Admin');
            set_flash($msgM, $okM ? 'success' : 'error');
        }
        header("Location: patients"); exit;
    }

    if ($action === 'restore_booking') {
        if (!in_array(current_role(), ['admin','staff'])) {
            set_flash('Only admin and staff can restore online booking.', 'error');
            header("Location: patients"); exit;
        }
        $rid = (int)($_POST['id'] ?? 0);
        // Restoring clears both reasons for a pause (missed visits and cancellations).
        $pdo->prepare("UPDATE patients SET noshow_reset_at = NOW(), noshow_reset_by = ?,
                                           cancel_reset_at = NOW(), cancel_reset_by = ? WHERE id = ?")
            ->execute([$_SESSION['name'] ?? 'admin', $_SESSION['name'] ?? 'admin', $rid]);

        $nm = $pdo->prepare("SELECT name FROM patients WHERE id=?");
        $nm->execute([$rid]);
        $rname = $nm->fetchColumn() ?: 'Patient';
        log_activity($pdo, 'Restored online booking', $rname);
        set_flash($rname . ' can book online again. '
                . 'Their missed visits stay on record.');
        header("Location: patients"); exit;
    }

    if ($action === 'assign_dentist') {
        // Only admin and front-desk staff may move a patient to another dentist.
        // A dentist must not be able to reassign patients to themselves.
        if (!in_array(current_role(), ['admin','staff'])) {
            set_flash('You do not have permission to change a patient\'s dentist.', 'error');
            header("Location: patients"); exit;
        }
        $pdo->prepare("UPDATE patients SET primary_dentist=? WHERE id=?")
            ->execute([($_POST['dentist'] ?: null), (int)$_POST['id']]);
        log_activity($pdo, 'Assigned dentist', patient_name_of($pdo, (int)$_POST['id']) . ' → ' . ($_POST['dentist'] ?: 'Unassigned'));
        // Their upcoming appointments go to the new dentist too.
        $msg = $_POST['dentist'] ? ('Assigned to ' . $_POST['dentist'] . '.') : 'Patient unassigned.';
        $type = 'success';
        if ($_POST['dentist']) {
            [$movedN, $stuck] = move_upcoming_to_dentist($pdo, (int)$_POST['id'], $_POST['dentist']);
            if ($movedN) $msg .= " $movedN upcoming appointment" . ($movedN > 1 ? 's were' : ' was') . ' moved to ' . $_POST['dentist'] . ' (same date and time).';
            if ($stuck) {
                $msg .= ' Not moved — ' . $_POST['dentist'] . ' is off or already booked: ' . implode('; ', $stuck) . '. Please change those in Appointments.';
                $type = 'warning';
            }
        }
        set_flash($msg, $type);
        $back = !empty($_POST['q']) ? '?q=' . urlencode($_POST['q']) : '';
        header("Location: patients$back"); exit;
    }
}

// ---------- SEARCH + role filter ----------
// A DENTIST only sees the patients the system assigned to them
// (matched by primary_dentist = their name). Admin/staff see everyone.
$search    = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');   // '', 'active', 'inactive', etc.
$isDentist = (current_role() === 'dentist');
$myName    = $_SESSION['name'] ?? '';

$where = ["status <> 'Archived'"];   // archived patients live on the Archive page, not here
$params = [];
if ($isDentist) { $where[] = "primary_dentist = ?"; $params[] = $myName; }
if ($search !== '') { $where[] = "(name LIKE ? OR email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($statusFilter !== '') { $where[] = "status = ?"; $params[] = $statusFilter; }

// For the "Archive" link + badge near "+ Add Patient" (admin only).
$archivedPatientCount = (current_role() === 'admin')
    ? (int)$pdo->query("SELECT COUNT(*) FROM patients WHERE status = 'Archived'")->fetchColumn()
    : 0;

$sql = "SELECT * FROM patients";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll();

// ---- Family links (for the View window) ----
// A family member booked by another patient shows who booked them; a
// patient who books for family shows their family members.
$pNames = array_column($pdo->query("SELECT id, name FROM patients")->fetchAll(), 'name', 'id');
$familyOf = [];
foreach ($pdo->query("SELECT id, name, relationship, guardian_patient_id FROM patients
                       WHERE guardian_patient_id IS NOT NULL AND status <> 'Archived' ORDER BY name") as $f) {
    $familyOf[(int)$f['guardian_patient_id']][] = $f['name'] . ($f['relationship'] ? ' (' . $f['relationship'] . ')' : '');
}
foreach ($patients as &$pp) {
    $g = (int)($pp['guardian_patient_id'] ?? 0);
    $pp['booked_by_name'] = $g ? ($pNames[$g] ?? '') : '';
    $pp['family_members'] = $familyOf[(int)$pp['id']] ?? [];
}
unset($pp);

// ---- Own-account invites waiting to be accepted ----
require_once 'includes/account_transfer.php';
ensure_account_transfer_tables($pdo);
$openInvites = [];
foreach ($pdo->query("SELECT patient_id, email, expires_at FROM account_invites
                       WHERE accepted_at IS NULL AND cancelled_at IS NULL AND expires_at > NOW()") as $oi) {
    $openInvites[(int)$oi['patient_id']] = $oi;
}

// ---- Merge picker: every record that is not archived ----
$mergeList = [];
if (current_role() === 'admin') {
    $mergeList = $pdo->query("SELECT p.id, p.name, p.date_of_birth, p.email, p.user_id, p.guardian_patient_id
                                FROM patients p LEFT JOIN users u ON u.id = p.user_id
                               WHERE p.status <> 'Archived' AND (u.id IS NULL OR u.role = 'patient') ORDER BY p.name, p.id")->fetchAll();
}

// ---- Who is blocked from booking online? ----
// Same shared rule as the booking page: three missed visits inside the
// rolling window (and only those after any staff reset) pauses booking.
[$blockedCounts, $cancelCounts] = patient_counts_bulk($pdo, array_column($patients, 'id'));   // 2 queries for the whole list

// Active dentists (for the admin's manual "assign doctor" dropdown).
$dentistList = $pdo->query("SELECT name FROM users WHERE role='dentist' AND status='active' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

// Load every patient's dental chart (tooth conditions) so the read-only View
// modal can show it. We index by patient_id so the JavaScript can look up each
// patient's teeth quickly. (The general remarks are already inside each patient
// row as chart_remarks, so we don't need a separate query for those.)
$toothData = [];
foreach ($pdo->query("SELECT patient_id, tooth_number, tooth_status FROM odontogram") as $row) {
    $toothData[$row['patient_id']][$row['tooth_number']] = $row['tooth_status'];
}

$avatarColors = ['#f5a3a3','#f5d6a3','#a3d8f5','#c3a3f5','#a3f5c3'];

$page_title = "Patients";
include 'includes/head.php';
$active = 'patients';
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div><h1>Patients</h1><div class="sub">Patient records management</div></div>
            <div class="d-flex align-items-center gap-3">
                <div class="clock"><span class="time" id="clock"></span><br><span id="clock-date"></span></div>
                <?php if (current_role() === 'admin'): ?>
                    <a href="admin_archive" class="btn btn-outline-secondary position-relative" title="Archive">
                        🗄 Archive
                        <?php if ($archivedPatientCount > 0): ?>
                            <span class="badge-pill b-inactive" style="margin-left:4px;"><?= $archivedPatientCount ?></span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
                <?php if (current_role() === 'admin'): ?>
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mergeModal"
                            data-keep-text title="Two records for the same person? Move everything onto one">🔀 Merge records</button>
                <?php endif; ?>
                <!-- Opens the Add Patient modal -->
                <button class="btn btn-dark-navy" data-bs-toggle="modal" data-bs-target="#patientModal" onclick="openAdd()">+ Add Patient</button>
            </div>
        </div>

        <!-- Search bar + status filter -->
        <form method="GET" class="card-box d-flex gap-2 align-items-center flex-wrap" style="padding:14px;">
            <span>🔍</span>
            <input type="text" name="q" class="form-control border-0" placeholder="Search by name or email..." value="<?= e($search) ?>" style="flex:1;min-width:180px;">
            <select name="status" class="form-select" style="max-width:170px;" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <option value="active"   <?= $statusFilter==='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $statusFilter==='inactive'?'selected':'' ?>>Inactive</option>
            </select>
            <button class="btn btn-teal">Search</button>
            <?php if ($search !== '' || $statusFilter !== ''): ?>
                <a href="patients" class="btn btn-light">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Patients table -->
        <div class="card-box">
            <?php if (current_role() === 'admin'): ?>
                <?= bulk_bar('bulk-patients', 'delete', 'patients to the Archive', [], '🗑 Move selected to Archive', 'They can be restored from the Archive.', 'Move') ?>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="data">
                    <thead><tr>
                        <th>Patient</th><th>Age</th><th>Phone</th><?php if (!$isDentist): ?><th>Dentist</th><?php endif; /* a dentist only sees their own patients */ ?><th>Last Visit</th>
                        <th>Next Visit</th><th>Status</th><th>Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($patients)): ?>
                        <tr><td colspan="<?= $isDentist ? 7 : 8 ?>" style="text-align:center;padding:40px 12px;color:#8aa0a0;">
                            <div style="font-size:2.4rem;margin-bottom:8px;">👥</div>
                            <?php if ($search !== '' || $statusFilter !== ''): ?>
                                No patients match your search. <a href="patients" style="color:var(--teal);">Clear filters</a>
                            <?php else: ?>
                                No patients yet. Click <strong>+ Add Patient</strong> to add the first one.
                            <?php endif; ?>
                        </td></tr>
                    <?php endif; ?>
                    <?php foreach ($patients as $i => $p): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar" style="background:<?= $avatarColors[$i % 5] ?>"><?= strtoupper(substr($p['name'],0,1)) ?></span>
                                    <div>
                                        <strong><?= e($p['name']) ?></strong><br>
                                        <small class="text-muted2"><?= e($p['email']) ?></small>
                                        <?php if (!empty($p['guardian_patient_id']) && empty($p['user_id'])): ?>
                                            <br><small class="text-muted2" style="font-size:.7rem;">👪 Booked by <?= e($p['booked_by_name'] ?: '–') ?> · no login of their own</small>
                                            <?php if (in_array(current_role(), ['admin','staff'], true)): ?>
                                                <?php if (isset($openInvites[(int)$p['id']])): ?>
                                                    <br><span class="badge-pill b-pending" style="font-size:.66rem;">✉️ Invite sent to <?= e($openInvites[(int)$p['id']]['email']) ?></span>
                                                    <form method="POST" class="d-inline m-0" onsubmit="return confirm('Cancel this invite? The link in the email will stop working.')">
                                                        <input type="hidden" name="action" value="admin_cancel_invite">
                                                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                                        <button class="btn btn-sm btn-light" style="font-size:.66rem;padding:1px 7px;color:#c0392b;" data-keep-text>Cancel</button>
                                                    </form>
                                                <?php else: ?>
                                                    <br><button type="button" class="btn btn-sm btn-light" style="font-size:.66rem;padding:1px 7px;color:var(--teal);" data-keep-text
                                                            onclick='openOwnInvite(<?= json_encode(["id" => $p["id"], "name" => $p["name"], "email" => $p["email"] ?? "",
                                                                "age" => account_age($p)], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✉️ Give own account</button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php $ns = $blockedCounts[$p['id']] ?? 0; $nc = $cancelCounts[$p['id']] ?? 0; ?>
                                        <?php $manualBlock = !empty($p['booking_blocked']); ?>

                                        <?php if ($manualBlock): ?>
                                            <br>
                                            <span class="badge-pill b-cancelled" style="font-size:.68rem;">
                                                🚫 Booking paused by staff
                                            </span>
                                            <?php if (!empty($p['booking_block_reason'])): ?>
                                                <br><small class="text-muted2" style="font-size:.68rem;">
                                                    <em><?= e($p['booking_block_reason']) ?></em>
                                                </small>
                                            <?php endif; ?>
                                            <?php if (in_array(current_role(), ['admin','staff'])): ?>
                                                <form method="POST" class="d-inline m-0"
                                                      onsubmit="return confirm('Let <?= e($p['name']) ?> book online again?')">
                                                    <input type="hidden" name="action" value="toggle_booking_block">
                                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                                    <input type="hidden" name="block" value="0">
                                                    <button class="btn btn-sm btn-light" style="font-size:.68rem;padding:2px 8px;color:#1f8a54;">
                                                        Resume booking
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                        <?php elseif ($ns >= 3 || $nc >= CANCEL_LIMIT): ?>
                                            <br>
                                            <span class="badge-pill b-cancelled" style="font-size:.68rem;">
                                                🚫 Online booking paused · <?= $ns >= 3 ? $ns . ' missed' : $nc . ' cancelled' ?>
                                            </span>
                                            <?php if (in_array(current_role(), ['admin','staff'])): ?>
                                                <form method="POST" class="d-inline m-0"
                                                      onsubmit="return confirm('Restore online booking for <?= e($p['name']) ?>?\n\nOnly do this after talking to them. Their missed visits stay on record.')">
                                                    <input type="hidden" name="action" value="restore_booking">
                                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                                    <button class="btn btn-sm btn-light" style="font-size:.68rem;padding:2px 8px;color:#1f8a54;">
                                                        Restore booking
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                        <?php else: ?>
                                            <?php if ($ns > 0): ?>
                                                <br><small style="color:#b8860b;font-size:.7rem;"><?= $ns ?> missed appointment<?= $ns>1?'s':'' ?></small>
                                            <?php endif; ?>
                                            <?php if (in_array(current_role(), ['admin','staff'])): ?>
                                                <button type="button" class="btn btn-sm btn-light"
                                                        style="font-size:.66rem;padding:1px 7px;color:#8aa0a0;"
                                                        title="Stop this patient booking online"
                                                        onclick='openPauseBooking(<?= json_encode([
                                                            "id"   => $p["id"],
                                                            "name" => $p["name"],
                                                        ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Pause booking</button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td><?= e($p['age']) ?></td>
                            <td><?= e($p['phone']) ?></td>
                            <?php if (!$isDentist): ?>
                            <td>
                                <?php if (in_array(current_role(), ['admin','staff'], true)): ?>
                                    <form method="POST" class="m-0">
                                        <input type="hidden" name="action" value="assign_dentist">
                                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="q" value="<?= e($search) ?>">
                                        <select name="dentist" class="form-select form-select-sm" style="min-width:155px;" onchange="this.form.submit()">
                                            <option value="">— Unassigned —</option>
                                            <?php foreach ($dentistList as $dn): ?>
                                                <option <?= $p['primary_dentist']===$dn?'selected':'' ?>><?= e($dn) ?></option>
                                            <?php endforeach; ?>
                                            <?php if ($p['primary_dentist'] && !in_array($p['primary_dentist'], $dentistList)): ?>
                                                <option selected><?= e($p['primary_dentist']) ?></option>
                                            <?php endif; ?>
                                        </select>
                                    </form>
                                <?php else: ?>
                                    <?= $p['primary_dentist'] ? e($p['primary_dentist']) : '<span class="text-muted2">Unassigned</span>' ?>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td><?= e($p['last_visit']) ?></td>
                            <td class="date-blue"><?= e($p['next_visit']) ?></td>
                            <td><span class="badge-pill b-<?= strtolower($p['status']) ?>"><?= e($p['status']) ?></span></td>
                            <td>
                                <!-- View opens a read-only modal; Edit opens the form modal -->
                                <button class="btn btn-sm btn-outline-secondary"
                                    onclick='viewPatient(<?= json_encode($p) ?>)'
                                    data-bs-toggle="modal" data-bs-target="#viewModal">View</button>
                                <button class="btn btn-sm btn-teal"
                                    onclick='openEdit(<?= json_encode($p) ?>)'
                                    data-bs-toggle="modal" data-bs-target="#patientModal">Edit</button>
                                <a href="appointments?book=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-teal" title="Book an appointment for this patient">📅 Book</a>
                                <button class="btn btn-sm icon-btn" style="background:#e8f5f3;color:var(--teal-mid);"
                                    onclick='openMessage(<?= json_encode($p) ?>)'
                                    data-bs-toggle="modal" data-bs-target="#msgModal"
                                    title="Message">✉️</button>
                                <?php if (current_role() === 'admin'): ?>
                                    <?= bulk_pick('bulk-patients', $p['id'], 'Select ' . $p['name']) ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Move this patient to Archive?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                        <button class="btn btn-sm icon-btn" style="background:#fbdcdc;color:#c0392b;" title="Move to Archive">🗑</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$patients): ?>
                        <tr><td colspan="<?= $isDentist ? 7 : 8 ?>" class="text-center text-muted2 py-4">No patients found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- ============ ADD / EDIT MODAL ============ -->
<div class="modal fade" id="patientModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="f-id">
        <div class="modal-header"><h5 class="modal-title" id="modal-title">Add Patient</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="row">
                <div class="col"><label class="field-label">First Name</label>
                    <input type="text" name="first_name" id="f-first" class="form-control mb-3" required></div>
                <div class="col"><label class="field-label">Last Name</label>
                    <input type="text" name="last_name" id="f-last" class="form-control mb-3"></div>
            </div>
            <label class="field-label">Email</label>
            <input type="email" name="email" id="f-email" class="form-control mb-1">
            <div class="text-muted2 mb-3" style="font-size:.76rem;" id="f-email-help">For a patient with a login, this is their sign-in email: <?= current_role() === 'admin' ? 'a change is sent to the new address to confirm first, and the old address is told.' : 'only the admin can change it.' ?></div>
            <?php if (in_array(current_role(), ['admin','staff'], true)): ?>
            <!-- Birthday and age: only admin and staff can set them. The age fills in from the birthday. -->
            <div class="row">
                <div class="col"><label class="field-label">Date of Birth</label>
                    <input type="date" name="dob" id="f-dob" class="form-control mb-3" min="1900-01-01" max="<?= birth_date_max() ?>"
                           onchange="var a=document.getElementById('f-age'); if(this.value){var b=new Date(this.value+'T00:00:00'),n=new Date(),y=n.getFullYear()-b.getFullYear(); if(n.getMonth()<b.getMonth()||(n.getMonth()===b.getMonth()&&n.getDate()<b.getDate()))y--; a.value=y;}"></div>
                <div class="col"><label class="field-label">Age</label>
                    <input type="number" name="age" id="f-age" class="form-control mb-3" min="0" max="120" step="1" data-digits></div>
            </div>
            <?php endif; ?>
            <div class="row">
                <div class="col"><label class="field-label">Phone</label>
                    <input name="phone" id="f-phone" class="form-control mb-3" placeholder="09XX XXX XXXX"
                           <?= phone_input_attrs() ?>></div>
                <div class="col"><label class="field-label">Status</label>
                    <select name="status" id="f-status" class="form-select mb-3">
                        <option>Active</option><option>Inactive</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col"><label class="field-label">📞 Emergency contact</label>
                    <input name="emergency_name" id="f-em-name" class="form-control mb-3" placeholder="Name (e.g. Maria — mother)"></div>
                <div class="col"><label class="field-label">Emergency phone</label>
                    <input name="emergency_phone" id="f-em-phone" class="form-control mb-3" placeholder="09XX XXX XXXX" <?= phone_input_attrs() ?>></div>
            </div>

            <div class="row">
                <div class="col"><label class="field-label">Patient Type</label>
                    <select name="patient_type" id="f-ptype" class="form-select mb-3" onchange="toggleReason()">
                        <option value="New">New</option>
                        <option value="Returning">Returning</option>
                    </select>
                </div>
            </div>

            <div id="f-reason-wrap" style="display:none;">
                <label class="field-label">Reason for Visit <span class="text-muted2">(returning patient)</span></label>
                <input name="visit_reason" id="f-reason" class="form-control mb-1" placeholder="e.g. Follow-up on root canal, cleaning every 6 months">
                <div class="text-muted2 mb-3" style="font-size:.78rem;">Why this patient came back. Shows on their record.</div>
            </div>

            <div id="f-pass-wrap">
                <label class="field-label">Password</label>
                <div class="pw-wrap mb-1">
                    <input type="password" name="password" id="f-pass" class="form-control" data-pw-meter autocomplete="new-password"
                           placeholder="Blank = a strong temporary password is created (new) / kept (edit)">
                    <button type="button" class="pw-eye" aria-label="Show password">
                        <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
                <label class="field-label mt-2">Confirm Password</label>
                <div class="pw-wrap mb-1">
                    <input type="password" name="password_confirm" id="f-pass2" class="form-control" data-pw-match="f-pass" autocomplete="new-password"
                           placeholder="Type the same password again">
                    <button type="button" class="pw-eye" aria-label="Show password">
                        <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
                <div id="f-pass2-warn" class="text-danger small mb-2" style="display:none;">The passwords do not match.</div>
                <div class="text-muted2" style="font-size:.78rem;">New patients with an email automatically get a login account so they can book later. Leave the password blank and a strong temporary password is created and shown after saving (only Strong passwords are accepted).</div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-teal">Save Patient</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ============ VIEW MODAL (read-only) ============ -->
<div class="modal fade" id="viewModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Patient Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="view-body"></div>
    </div>
  </div>
</div>

<!-- ============ PERSONAL MESSAGE MODAL (Email / SMS) ============ -->
<div class="modal fade" id="msgModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" onsubmit="return confirm('Send this message to the patient?')">
        <input type="hidden" name="action" value="message">
        <input type="hidden" name="patient_id" id="msg-pid">
        <div class="modal-header"><h5 class="modal-title">Message <span id="msg-name"></span></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <label class="field-label">Send Via</label>
            <select name="via" id="msg-via" class="form-select mb-2" onchange="toggleMsg()">
                <option value="email">📧 Email</option>
                <option value="sms">📱 SMS (Phone)</option>
            </select>
            <div class="text-muted2 mb-3" id="msg-to" style="font-size:.85rem;"></div>

            <!-- Subject only shows for Email -->
            <div id="msg-subject-wrap">
                <label class="field-label">Subject</label>
                <input name="subject" id="msg-subject" class="form-control mb-3" value="A message from St. Therese Dental Clinic">
            </div>

            <label class="field-label">Message</label>
            <textarea name="message" id="msg-message" class="form-control" rows="5" oninput="msgCount()">Hello, this is a friendly reminder from St. Therese Dental Clinic regarding your dental care.</textarea>
            <div id="msg-sms-counter" class="text-muted2 mt-1" style="font-size:.78rem;display:none;">
                <span id="msg-sms-count">0</span> characters (about <span id="msg-sms-parts">1</span> SMS)
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-teal" id="msg-btn">📤 Send</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
<script>
    // True when the logged-in user is admin (front desk), who should not see
    // clinical health data (dental chart, remarks).
    var IS_FRONT_DESK = <?= (current_role() === 'admin') ? 'true' : 'false' ?>;

    startClock();

// Pausing a patient's online booking always needs a reason — the patient is
// shown this message when they try to book, so they know what to do next.
function openPauseBooking(p){
    document.getElementById('pb-id').value = p.id;
    document.getElementById('pb-name').textContent = p.name || 'this patient';
    document.getElementById('pb-reason').value = '';
    document.getElementById('pb-warn').style.display = 'none';
    new bootstrap.Modal(document.getElementById('pauseBookingModal')).show();
}

function validatePauseBooking(){
    var r = document.getElementById('pb-reason').value.trim();
    if (r === '') { document.getElementById('pb-warn').style.display = 'block'; return false; }
    return true;
}

    // Show the "Reason for Visit" box only for Returning patients.
    function toggleReason() {
        var t = document.getElementById('f-ptype').value;
        document.getElementById('f-reason-wrap').style.display = (t === 'Returning') ? 'block' : 'none';
    }

    // Reset the modal for adding a new patient
    function openAdd() {
        document.getElementById('modal-title').textContent = 'Add Patient';
        document.getElementById('f-id').value = '';
        document.getElementById('f-first').value = '';
        document.getElementById('f-last').value = '';
        document.getElementById('f-email').value = '';
        document.getElementById('f-phone').value = '';
        document.getElementById('f-em-name').value = '';
        document.getElementById('f-em-phone').value = '';
        document.getElementById('f-status').value = 'Active';
        document.getElementById('f-ptype').value = 'New';
        document.getElementById('f-reason').value = '';
        document.getElementById('f-pass').value = '';
        document.getElementById('f-pass2').value = '';
        if (document.getElementById('f-dob')) { document.getElementById('f-dob').value = ''; document.getElementById('f-age').value = ''; }
        toggleReason();
    }

    // Fill the modal with an existing patient's data for editing
    function openEdit(p) {
        document.getElementById('modal-title').textContent = 'Edit Patient';
        document.getElementById('f-id').value = p.id;
        // Split the full name into first + last (first word = first name).
        var parts = (p.name || '').trim().split(' ');
        document.getElementById('f-first').value = parts.shift() || '';
        document.getElementById('f-last').value  = parts.join(' ');
        document.getElementById('f-email').value = p.email || '';
        document.getElementById('f-phone').value = p.phone || '';
        document.getElementById('f-em-name').value = p.emergency_name || '';
        document.getElementById('f-em-phone').value = p.emergency_phone || '';
        document.getElementById('f-status').value = p.status;
        document.getElementById('f-ptype').value = p.patient_type || 'New';
        document.getElementById('f-reason').value = p.visit_reason || '';
        document.getElementById('f-pass').value = '';
        document.getElementById('f-pass2').value = '';
        if (document.getElementById('f-dob')) {           // admin / staff only
            document.getElementById('f-dob').value = p.date_of_birth || '';
            document.getElementById('f-age').value = (p.age === null || p.age === undefined) ? '' : p.age;
        }
        toggleReason();
    }

    // ----- Data + colors for the read-only dental chart in the View modal -----
    const TOOTH_DATA   = <?= json_encode($toothData ?: (object)[]) ?>;
    const TOOTH_COLORS = {Healthy:'#6bbf6b',Decayed:'#e0a92e',Filled:'#3b9ae0',Missing:'#e05b5b',
                          Crowned:'#a06fd6',Extracted:'#999999',Impacted:'#d99a00',Fractured:'#cc4444'};
    const UPPER_TEETH  = ['18','17','16','15','14','13','12','11','21','22','23','24','25','26','27','28'];
    const LOWER_TEETH  = ['48','47','46','45','44','43','42','41','31','32','33','34','35','36','37','38'];

    // Escape text so remarks with < > & don't break the HTML.
    function esc(s){ return (s==null?'':String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    // Draw one row of little coloured tooth boxes.
    function toothRow(teeth, map){
        return teeth.map(function(t){
            var st = (map && map[t]) ? map[t] : 'Healthy';
            var c  = TOOTH_COLORS[st] || '#6bbf6b';
            return '<div style="text-align:center;flex:0 0 auto;">'
                 + '<div title="Tooth '+t+': '+st+'" style="width:22px;height:26px;border-radius:4px;background:'+c+';border:1px solid rgba(0,0,0,.12);"></div>'
                 + '<div style="font-size:.56rem;color:#999;margin-top:2px;">'+t+'</div></div>';
        }).join('');
    }
    // Text summary of the teeth that are NOT healthy.
    function toothSummary(map){
        if(!map) return '<span class="text-muted2">No chart data — all teeth healthy.</span>';
        var items=[];
        Object.keys(map).forEach(function(t){ if(map[t] && map[t]!=='Healthy') items.push('Tooth '+t+': '+map[t]); });
        return items.length ? items.join(' · ') : '<span class="text-muted2">No chart data — all teeth healthy.</span>';
    }

    // Show patient details + read-only dental chart + remarks
    function viewPatient(p) {
        var map = TOOTH_DATA[p.id] || null;
        var legend = Object.keys(TOOTH_COLORS).map(function(k){
            return '<span style="margin-right:10px;white-space:nowrap;"><i style="display:inline-block;width:10px;height:10px;border-radius:2px;background:'+TOOTH_COLORS[k]+';margin-right:3px;"></i>'+k+'</span>';
        }).join('');

        // Front-desk staff see the contact details only. The dental chart and
        // clinical remarks are health data they do not need for scheduling.
        var clinical = !IS_FRONT_DESK
          ? '<hr><h6>🦷 Dental Chart <small class="text-muted2">(view only)</small></h6>'
          + '<div style="display:flex;gap:3px;justify-content:center;overflow-x:auto;padding:4px 0;">'+toothRow(UPPER_TEETH,map)+'</div>'
          + '<div style="text-align:center;color:#bbb;font-size:.6rem;margin:2px 0;">— — —</div>'
          + '<div style="display:flex;gap:3px;justify-content:center;overflow-x:auto;padding:4px 0;">'+toothRow(LOWER_TEETH,map)+'</div>'
          + '<div style="font-size:.7rem;margin-top:8px;line-height:1.8;">'+legend+'</div>'
          + '<div style="font-size:.85rem;margin-top:8px;"><b>Conditions:</b> '+toothSummary(map)+'</div>'
          + '<hr><h6>📝 General Remarks</h6>'
          + '<div style="white-space:pre-wrap;font-size:.9rem;">'+(esc(p.chart_remarks) || '<span class="text-muted2">No remarks recorded.</span>')+'</div>'
          : '';

        document.getElementById('view-body').innerHTML =
            '<div class="row">'
          +   '<div class="col-md-6">'
          +     '<p><b>Name:</b> '+esc(p.name)+'</p>'
          +     '<p><b>Email:</b> '+esc(p.email||'–')+'</p>'
          +     '<p><b>Phone:</b> '+esc(p.phone||'–')+'</p>'
          +     '<p><b>Age:</b> '+(p.age||'–')+'</p>'
          +     (p.booked_by_name ? '<p><b>Booked by:</b> '+esc(p.booked_by_name)+(p.relationship ? ' <span class="text-muted2">('+esc(p.relationship)+')</span>' : '')+'</p>' : '')
          +     (p.family_members && p.family_members.length ? '<p><b>Family members:</b> '+p.family_members.map(esc).join(', ')+'</p>' : '')
          +   '</div>'
          +   '<div class="col-md-6">'
          +     '<p><b>Blood Type:</b> '+esc(p.blood_type||'–')+'</p>'
          +     '<p><b>Patient Type:</b> '+esc(p.patient_type||'–')+'</p>'
          +     '<p><b>Last Visit:</b> '+esc(p.last_visit||'–')+'</p>'
          +     '<p><b>Next Visit:</b> '+esc(p.next_visit||'–')+'</p>'
          +   '</div>'
          + '</div>'
          + clinical;
    }

    // ----- Personal message (Email / SMS) to one patient -----
    var msgEmail = '', msgPhone = '';
    function openMessage(p) {
        document.getElementById('msg-pid').value = p.id;
        document.getElementById('msg-name').textContent = p.name;
        msgEmail = p.email || '';
        msgPhone = p.phone || '';
        document.getElementById('msg-via').value = 'email';
        toggleMsg();
        msgCount();
    }
    // Switch between Email (has Subject) and SMS (has char counter),
    // and show the matching contact as the recipient.
    function toggleMsg() {
        var isEmail = (document.getElementById('msg-via').value === 'email');
        document.getElementById('msg-subject-wrap').style.display = isEmail ? 'block' : 'none';
        document.getElementById('msg-sms-counter').style.display  = isEmail ? 'none'  : 'block';
        document.getElementById('msg-btn').textContent = isEmail ? '📧 Send Email' : '📱 Send SMS';
        var to = isEmail ? (msgEmail || '(no email on file)') : (msgPhone || '(no phone on file)');
        document.getElementById('msg-to').textContent = 'To: ' + to;
    }
    function msgCount() {
        var len = document.getElementById('msg-message').value.length;
        document.getElementById('msg-sms-count').textContent = len;
        document.getElementById('msg-sms-parts').textContent = Math.max(1, Math.ceil(len / 160));
    }
</script>
<!-- ===== Pause online booking ===== -->
<!-- ===== Give a booked-for adult their own account ===== -->
<div class="modal fade" id="ownInviteModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="admin_invite_own">
      <input type="hidden" name="id" id="oi-id">
      <div class="modal-header">
        <h5 class="modal-title">Give them their own account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="card-box mb-3" style="background:#f7fafa;padding:12px 14px;">
          <div class="text-muted2" style="font-size:.8rem;">Booked-for person</div>
          <div style="font-weight:600;" id="oi-name"></div>
        </div>
        <div class="alert" style="background:#eef7f6;border:1px solid #cfe0dd;color:#3f5350;font-size:.82rem;">
          An invite link is emailed to them (it works for <?= INVITE_HOURS ?> hours). When they set a password, this same record
          becomes their own account — appointments, dental chart, records and X-rays stay with it. The person who booked for
          them no longer sees them. Only for ages <?= MIN_ACCOUNT_AGE ?>+.
        </div>
        <div id="oi-age-warn" class="alert alert-danger py-2" style="font-size:.82rem;display:none;"></div>
        <label class="field-label">Their email address</label>
        <input type="email" name="invite_email" id="oi-email" class="form-control" required placeholder="their.email@example.com">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-teal" id="oi-send" data-keep-text>✉️ Send invite</button>
      </div>
    </form>
  </div>
</div>

<?php if (current_role() === 'admin'): ?>
<!-- ===== Merge two records of the same person ===== -->
<div class="modal fade" id="mergeModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <form method="POST" class="modal-content" onsubmit="return checkMerge()">
      <input type="hidden" name="action" value="merge_records">
      <div class="modal-header">
        <h5 class="modal-title">🔀 Merge two records of the same person</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert" style="background:#fff6e0;border:1px solid var(--gold);color:#8a6d2f;font-size:.82rem;">
          Use this when one person has two records — for example, they were booked by a relative and later signed up on
          their own. Everything on the second record (appointments, dental chart, treatments, notes, X-rays, reviews) moves
          onto the record you keep, and the second record goes to the Archive. If only the second record has a login,
          the login moves too. Records that <b>both</b> have a login cannot be merged.
        </div>
        <?php $mOpt = function ($m) {
            return '#' . $m['id'] . ' · ' . $m['name']
                 . ($m['date_of_birth'] ? ' · born ' . date('M j, Y', strtotime($m['date_of_birth'])) : '')
                 . ($m['email'] ? ' · ' . $m['email'] : '')
                 . (!empty($m['user_id']) ? ' · has login' : (!empty($m['guardian_patient_id']) ? ' · booked by someone' : ''));
        }; ?>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="field-label">✅ Keep this record</label>
            <select name="keep_id" id="mg-keep" class="form-select" data-search="Search a patient..." required>
              <option value="">Choose…</option>
              <?php foreach ($mergeList as $m): ?><option value="<?= $m['id'] ?>"><?= e($mOpt($m)) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="field-label">🗄 Move into it, then archive</label>
            <select name="dup_id" id="mg-dup" class="form-select" data-search="Search a patient..." required>
              <option value="">Choose…</option>
              <?php foreach ($mergeList as $m): ?><option value="<?= $m['id'] ?>"><?= e($mOpt($m)) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div id="mg-warn" class="text-danger small mt-2" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-teal" data-keep-text>🔀 Merge records</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="modal fade" id="pauseBookingModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content" onsubmit="return validatePauseBooking()">
      <input type="hidden" name="action" value="toggle_booking_block">
      <input type="hidden" name="id" id="pb-id">
      <input type="hidden" name="block" value="1">

      <div class="modal-header">
        <h5 class="modal-title">Pause online booking</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="card-box mb-3" style="background:#f7fafa;padding:12px 14px;">
          <div class="text-muted2" style="font-size:.8rem;">Patient</div>
          <div style="font-weight:600;" id="pb-name"></div>
        </div>

        <div class="alert" style="background:#fff6e0;border:1px solid var(--gold);color:#8a6d2f;font-size:.82rem;">
          The patient will see this message when they try to book online, so tell them
          what to do next. They can still be seen at the clinic.
        </div>

        <label class="field-label">Reason</label>
        <textarea name="block_reason" id="pb-reason" class="form-control mb-1" rows="3"
                  placeholder="e.g. Please visit the clinic to settle your account."></textarea>
        <div id="pb-warn" class="text-danger small" style="display:none;">A reason is required — the patient is shown this message.</div>

        <div class="mt-3">
          <div class="text-muted2 mb-1" style="font-size:.78rem;">Or pick one:</div>
          <div class="d-flex gap-1 flex-wrap">
            <button type="button" class="btn btn-sm btn-light" style="font-size:.74rem;"
                    onclick="document.getElementById('pb-reason').value=this.textContent.trim();document.getElementById('pb-warn').style.display='none';">Please visit the clinic to settle your account.</button>
            <button type="button" class="btn btn-sm btn-light" style="font-size:.74rem;"
                    onclick="document.getElementById('pb-reason').value=this.textContent.trim();document.getElementById('pb-warn').style.display='none';">Please see us in person to arrange your next visit.</button>
            <button type="button" class="btn btn-sm btn-light" style="font-size:.74rem;"
                    onclick="document.getElementById('pb-reason').value=this.textContent.trim();document.getElementById('pb-warn').style.display='none';">Kindly call the clinic before booking again.</button>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-teal">Pause booking</button>
      </div>
    </form>
  </div>
</div>

<script>
function openOwnInvite(p) {
    document.getElementById('oi-id').value = p.id;
    document.getElementById('oi-name').textContent = p.name;
    document.getElementById('oi-email').value = p.email || '';
    var warn = document.getElementById('oi-age-warn'), send = document.getElementById('oi-send'), msg = '';
    if (p.age === null) msg = 'Their birthday is not on file. Add it first (Edit), then send the invite.';
    else if (p.age < <?= MIN_ACCOUNT_AGE ?>) msg = 'They are ' + p.age + '. Only people aged <?= MIN_ACCOUNT_AGE ?> or older can have their own account.';
    warn.textContent = msg; warn.style.display = msg ? 'block' : 'none'; send.disabled = !!msg;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('ownInviteModal')).show();
}
function checkMerge() {
    var k = document.getElementById('mg-keep'), d = document.getElementById('mg-dup'), w = document.getElementById('mg-warn');
    if (!k.value || !d.value) { w.textContent = 'Choose both records.'; w.style.display = 'block'; return false; }
    if (k.value === d.value) { w.textContent = 'Choose two different records.'; w.style.display = 'block'; return false; }
    w.style.display = 'none';
    var kt = k.options[k.selectedIndex].text, dt = d.options[d.selectedIndex].text;
    return confirm('Merge records?\n\nKEEP: ' + kt + '\nMOVE + ARCHIVE: ' + dt + '\n\nEverything from the second record moves onto the first. Continue?');
}
</script>
</body>
</html>
