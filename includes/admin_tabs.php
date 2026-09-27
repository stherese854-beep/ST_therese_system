<?php
// ============================================================
//  ADMIN TABS  (includes/admin_tabs.php)
// ============================================================
//  The row of tabs at the top of the admin pages.
//  Admin sees all tabs. Admin sees only their tabs.
// ============================================================
$_curRole = current_role();
$adminTabs = [
    'users'         => ['admin_users',    '👤 User Management',   ['admin']],
    'archive'       => ['admin_archive',  '🗄 Archive',           ['admin']],
    'activity'      => ['admin_activity', '📋 Activity Log',      ['admin']],
    'announcements' => ['announcements',   '📣 Announcements',     ['admin']],
    'reviews'       => ['reviews',         '⭐ Patient Reviews',   ['admin']],
    'landing_edit'  => ['landing_edit',    '🎨 Edit Landing Page', ['admin']],
    'templates'     => ['templates',        '📝 Message Templates', ['admin']],
    'messaging'     => ['messaging',       '✉️ Messaging Config',  ['admin']],
    'settings'      => ['settings',        '⚙️ System',            ['admin']],
];
?>
<div class="card-box" style="padding:6px;">
    <div class="d-flex gap-2 flex-wrap">
        <?php foreach ($adminTabs as $key => $tab): ?>
            <?php if (in_array($_curRole, $tab[2])): ?>
                <a href="<?= $tab[0] ?>"
                   class="btn btn-sm <?= ($active===$key)?'btn-outline-teal':'btn-light' ?>"
                   style="<?= ($active===$key)?'border-bottom:3px solid var(--teal-mid);border-radius:6px;':'' ?>">
                   <?= $tab[1] ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($_curRole === 'admin'): ?>
            <a href="cron_reminders" target="_blank" class="btn btn-sm btn-light">⏰ Run 24h Reminders</a>
        <?php endif; ?>
    </div>
</div>
