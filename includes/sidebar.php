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
    <a class="nav-item <?= nav_active('dashboard') ?>" href="dashboard">📊 Dashboard</a>

    <?php if ($role === 'admin'): ?>
        <!-- Admin: full clinic management -->
        <a class="nav-item <?= nav_active('dentists') ?>"     href="admin_dentists">🩺 Dentists</a>
        <a class="nav-item <?= nav_active('patients') ?>"     href="patients">👥 Patients</a>
        <a class="nav-item <?= nav_active('appointments') ?>" href="appointments">📅 Appointments</a>
        <a class="nav-item <?= nav_active('odontogram') ?>"   href="odontogram">🦷 Odontogram</a>
        <a class="nav-item <?= nav_active('records') ?>"      href="records">📋 Records</a>
        <a class="nav-item <?= nav_active('schedule') ?>"     href="schedule">🗓 Dentist Schedules</a>
        <a class="nav-item <?= nav_active('clinic_contact') ?>" href="clinic_contact">📞 Clinic Contact</a>

    <?php elseif ($role === 'staff'): ?>
        <!-- Staff: front desk — scheduling and patient contact only, no clinical access -->
        <a class="nav-item <?= nav_active('patients') ?>"     href="patients">👥 Patients</a>
        <a class="nav-item <?= nav_active('appointments') ?>" href="appointments">📅 Appointments</a>
        <a class="nav-item <?= nav_active('clinic_contact') ?>" href="clinic_contact">📞 Clinic Contact</a>

    <?php elseif ($role === 'dentist'): ?>
        <!-- Dentist: own assigned patients only -->
        <a class="nav-item <?= nav_active('patients') ?>"     href="patients">👥 My Patients</a>
        <a class="nav-item <?= nav_active('appointments') ?>" href="appointments">📅 Schedule</a>
        <a class="nav-item <?= nav_active('schedule') ?>"     href="schedule">🗓 My Availability</a>
        <a class="nav-item <?= nav_active('odontogram') ?>"   href="odontogram">🦷 Odontogram</a>
        <a class="nav-item <?= nav_active('records') ?>"      href="records">📋 Treatment Records</a>
    <?php endif; ?>

    <div class="nav-label">Reports</div>
    <a class="nav-item <?= nav_active('reports') ?>"  href="reports">📑 Generate Reports</a>
    <a class="nav-item <?= nav_active('noshow') ?>"   href="noshow">📝 No-Show Report</a>
    <?php if ($role === 'admin'): ?>
        <a class="nav-item <?= nav_active('analytics') ?>" href="analytics">📈 Analytics</a>
    <?php endif; ?>

    <?php if ($role === 'admin'): ?>
        <div class="nav-label">System</div>
        <a class="nav-item <?= nav_active('settings') ?>" href="settings">⚙️ System</a>
    <?php endif; ?>
    <!-- Dentists and staff open their settings from the profile menu (top right). -->

    <div class="spacer"></div>
</aside>
