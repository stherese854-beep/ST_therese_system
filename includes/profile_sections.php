<?php
// ============================================================
//  PROFILE & SETTINGS SECTIONS  (includes/profile_sections.php)
// ============================================================
//  "Profile & Settings" is split into parts, so each opens on its own
//  instead of one long page. The top-right menu's "Profile & Settings"
//  opens into this list (and has the text-size buttons).
//  Patients: portal?view=profile&section=…   Everyone else: settings?view=profile&section=…
// ============================================================
if (!function_exists('profile_sections')) {
    /** key => [icon, label, also in the top-right menu?] */
    function profile_sections($role) {
        if ($role === 'patient') {
            return [
                'info'     => ['👤', 'My Information',        true],    // picture + personal information + account status
                'password' => ['🔑', 'Change Password',       true],
                'health'   => ['🩺', 'Health Questionnaire',  true],
                'review'   => ['⭐', 'Rate Our Clinic',       true],
            ];                                                        // (text size: the buttons in the same menu)
        }
        return ['info'     => ['👤', 'My Profile', true],                 // picture, name, contact
                // a dentist's approval PIN sits beside the password (one part)
                'password' => $role === 'dentist' ? ['🔑', 'Password & PIN', true] : ['🔑', 'Change Password', true]];
    }

    function profile_section_link($role, $key) {
        return ($role === 'patient' ? 'portal?view=profile' : 'settings?view=profile') . '&section=' . $key;
    }

    /** The part asked for (?section=), else the first one. */
    function current_profile_section($role) {
        $k = $_GET['section'] ?? '';
        return isset(profile_sections($role)[$k]) ? $k : 'info';
    }
}
