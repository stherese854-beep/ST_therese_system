<?php
// ============================================================
//  ADMIN "SYSTEM" PAGES  (includes/admin_pages.php)
// ============================================================
//  One list for both places they show:
//   - desktop: the row of tabs at the top of these pages (includes/admin_tabs.php)
//   - phone:   the "System" drop-down in the side menu (includes/sidebar.php)
//  key ($active on the page) => [link, icon, label]
// ============================================================
if (!function_exists('admin_system_pages')) {
    function admin_system_pages() {
        return [
            'users'         => ['admin_users',    '👤', 'User Management'],
            'archive'       => ['admin_archive',  '🗄', 'Archive'],
            'activity'      => ['admin_activity', '📋', 'Activity Log'],
            'announcements' => ['announcements',  '📣', 'Announcements'],
            'reviews'       => ['reviews',        '⭐', 'Patient Reviews'],
            'landing_edit'  => ['landing_edit',   '🎨', 'Edit Landing Page'],
            'templates'     => ['templates',      '📝', 'Message Templates'],
            'messaging'     => ['messaging',      '✉️', 'Messaging Config'],
            'backup'        => ['admin_backup',   '💾', 'Data Backup'],
            'settings'      => ['settings',       '⚙️', 'System'],
        ];
    }
}
