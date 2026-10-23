<?php
// ============================================================
//  REPORT TYPES  (includes/report_types.php)
// ============================================================
//  One list for both places they show:
//   - Generate Reports page: the "Choose a report" cards (computer)
//   - side menu: the "Generate Reports" drop-down (phone)
//  key (?type=) => [icon, title, description]
// ============================================================
if (!function_exists('report_types')) {
    function report_types() {
        return [
            'profile'      => ['👤', 'Patient Profile',   'Complete patient info, dental chart & medical history'],
            'treatment'    => ['💊', 'Treatment History', 'Detailed treatment records for a patient'],
            'appointments' => ['📅', 'Appointments List', 'All appointments by status and date'],
            'patients'     => ['👥', 'List of Patients',  'Complete patient registry with type and status'],
        ];
    }
}
