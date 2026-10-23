<?php
// ============================================================
//  PROFILE & SETTINGS SECTIONS  (includes/profile_sections.php)
// ============================================================
//  "Profile & Settings" is split into parts, so each opens on its own
//  instead of one long page:
//   - the top-right menu: "Profile & Settings" opens into this list
//   - the page itself: a row of the same parts at the top (desktop)
//  Patients: portal?view=profile&section=…   Everyone else: settings?view=profile&section=…
// ============================================================
if (!function_exists('profile_sections')) {
    /** key => [icon, label, also in the top-right menu?] */
    function profile_sections($role) {
        if ($role === 'patient') {
            return [
                'info'     => ['👤', 'My Information',        true],    // personal information + account status
                'photo'    => ['🖼️', 'Profile Picture',       true],
                'password' => ['🔑', 'Change Password',       true],
                'health'   => ['🩺', 'Health Questionnaire',  true],
                'review'   => ['⭐', 'Rate Our Clinic',       true],
                'textsize' => ['🔠', 'Text Size',             false],   // the menu has its own text-size buttons
            ];
        }
        $s = ['info'     => ['👤', 'My Profile',      true],              // picture, name, contact
              'password' => ['🔑', 'Change Password', true]];
        if ($role === 'dentist') $s['pin'] = ['🔐', 'Approval PIN', true];
        return $s;
    }

    function profile_section_link($role, $key) {
        return ($role === 'patient' ? 'portal?view=profile' : 'settings?view=profile') . '&section=' . $key;
    }

    /** The part asked for (?section=), else the first one. */
    function current_profile_section($role) {
        $k = $_GET['section'] ?? '';
        return isset(profile_sections($role)[$k]) ? $k : 'info';
    }

    /** The row of parts at the top of the page (hidden on a phone: the top-right menu has them). */
    function profile_section_tabs($role, $current) {
        $h = '<div class="card-box mb-3 prof-tabs" style="padding:6px;"><div class="d-flex gap-2 flex-wrap" data-keep-text>';
        foreach (profile_sections($role) as $k => [$icon, $label]) {
            $on = $k === $current;
            $h .= '<a href="' . profile_section_link($role, $k) . '" class="btn btn-sm ' . ($on ? 'btn-outline-teal' : 'btn-light') . '"'
                . ($on ? ' style="border-bottom:3px solid var(--teal-mid);border-radius:6px;" aria-current="page"' : '') . '>'
                . $icon . ' ' . e($label) . '</a>';
        }
        return $h . '</div></div><style>@media (max-width: 800px) { .prof-tabs { display: none !important; } }</style>';
    }
}
