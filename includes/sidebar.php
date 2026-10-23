<?php
// ============================================================
//  SIDEBAR  (includes/sidebar.php)
// ============================================================
//  The menu changes depending on the logged-in role:
//
//  admin   — full clinic management (appointments, patients,
//            odontogram, records, dentists, reports, system)
//  staff   — front desk only (appointments, patients, reports)
//  dentist — own patients only (schedule, chart, records)
// ============================================================

$active    = $active ?? '';
$role      = current_role();
$roleLabel = [
    'admin'   => 'Admin',
    'staff'   => 'Staff',
    'dentist' => 'Dentist',
    'patient' => 'Patient',
][$role] ?? ucfirst((string)$role);

function nav_active($page) {
    global $active;
    return $active === $page ? 'active' : '';
}
?>
<aside class="sidebar">
    <div class="brand">
        <div class="logo">🦷</div>
        <div>St. Therese<small>DENTAL CLINIC</small></div>
    </div>

    <div class="logged-as">
        LOGGED IN AS
        <strong><?= e($roleLabel) ?></strong>
    </div>

    <div class="nav-label">Main</div>
    <a class="nav-item <?= nav_active('dashboard') ?>" href="dashboard">Dashboard</a>

    <?php if ($role === 'admin'): ?>
        <!-- Admin: full clinic management -->
        <a class="nav-item <?= nav_active('dentists') ?>"     href="admin_dentists">Dentists</a>
        <a class="nav-item <?= nav_active('staff') ?>"        href="admin_staff">Staff</a>
        <a class="nav-item <?= nav_active('patients') ?>"     href="patients">Patients</a>
        <a class="nav-item <?= nav_active('appointments') ?>" href="appointments">Appointments</a>
        <a class="nav-item <?= nav_active('odontogram') ?>"   href="odontogram">Odontogram</a>
        <a class="nav-item <?= nav_active('records') ?>"      href="records">Records</a>
        <a class="nav-item <?= nav_active('schedule') ?>"     href="schedule">Dentist Schedules</a>
        <a class="nav-item <?= nav_active('clinic_contact') ?>" href="clinic_contact">Clinic Contact</a>

    <?php elseif ($role === 'staff'): ?>
        <!-- Staff: front desk — scheduling and patient contact only, no clinical access -->
        <a class="nav-item <?= nav_active('patients') ?>"     href="patients">Patients</a>
        <a class="nav-item <?= nav_active('appointments') ?>" href="appointments">Appointments</a>
        <!-- Opened with a dentist's approval PIN (includes/chart_access.php) -->
        <a class="nav-item <?= nav_active('odontogram') ?>"   href="odontogram">Odontogram 🔒</a>
        <a class="nav-item <?= nav_active('records') ?>"      href="records">Treatment Records 🔒</a>

    <?php elseif ($role === 'dentist'): ?>
        <!-- Dentist: own assigned patients only -->
        <a class="nav-item <?= nav_active('patients') ?>"     href="patients">My Patients</a>
        <a class="nav-item <?= nav_active('appointments') ?>" href="appointments">Schedule</a>
        <a class="nav-item <?= nav_active('schedule') ?>"     href="schedule">My Availability</a>

        <!-- The clinical work, grouped under its own heading -->
        <div class="nav-label">Treatment</div>
        <a class="nav-item <?= nav_active('odontogram') ?>"   href="odontogram">Odontogram</a>
        <a class="nav-item <?= nav_active('records') ?>"      href="records">Treatment Records</a>
    <?php endif; ?>

    <div class="nav-label">Reports</div>
    <?php require_once __DIR__ . '/report_types.php';
          $inReports   = $active === 'reports';
          $curReport   = $inReports ? (isset(report_types()[$_GET['type'] ?? '']) ? $_GET['type'] : 'profile') : '';
          $keepPatient = $inReports && !empty($_GET['patient_id']) ? '&patient_id=' . (int)$_GET['patient_id'] : ''; ?>
    <!-- Desktop: one link (the reports are cards on the page) -->
    <a class="nav-item nav-desktop-only <?= nav_active('reports') ?>"  href="reports">Generate Reports</a>
    <!-- Phone: the cards are hidden, so "Generate Reports" opens into the four reports here -->
    <details class="nav-group nav-phone-only" <?= $inReports ? 'open' : '' ?>>
        <summary class="nav-item <?= $inReports ? 'active' : '' ?>">Generate Reports</summary>
        <div class="nav-sub">
            <?php foreach (report_types() as $rk => [, $rl]): ?>
                <a class="nav-item <?= $curReport === $rk ? 'active' : '' ?>" href="reports?type=<?= $rk ?><?= $keepPatient ?>"><?= e($rl) ?></a>
            <?php endforeach; ?>
        </div>
    </details>
    <a class="nav-item <?= nav_active('noshow') ?>"   href="noshow">No-Show Report</a>
    <?php if ($role === 'admin'): ?>
        <a class="nav-item <?= nav_active('analytics') ?>" href="analytics">Analytics</a>
    <?php endif; ?>

    <?php if ($role === 'admin'):
        require_once __DIR__ . '/admin_pages.php';
        $sysPages = admin_system_pages();
        $inSystem = isset($sysPages[$active]); ?>
        <div class="nav-label">System</div>
        <!-- Desktop: one link (the pages are tabs at the top of each System page) -->
        <a class="nav-item nav-desktop-only <?= $inSystem ? 'active' : '' ?>" href="settings">System</a>
        <!-- Phone: the tabs are hidden, so "System" opens into the same list here -->
        <details class="nav-group nav-phone-only" <?= $inSystem ? 'open' : '' ?>>
            <summary class="nav-item <?= $inSystem ? 'active' : '' ?>">System</summary>
            <div class="nav-sub">
                <?php foreach ($sysPages as $key => [$href, , $label]): ?>
                    <a class="nav-item <?= nav_active($key) ?>" href="<?= $href ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>
    <!-- Dentists and staff open their settings from the profile menu (top right). -->

    <div class="spacer"></div>
</aside>
