<?php
// ============================================================
//  ADMIN TABS  (includes/admin_tabs.php)
// ============================================================
//  The row of tabs at the top of the admin pages (admin only).
// ============================================================
// The pages come from includes/admin_pages.php. On a phone this row is hidden:
// the same pages open from the "System" drop-down in the side menu.
require_once __DIR__ . '/admin_pages.php';
$adminTabs = current_role() === 'admin' ? admin_system_pages() : [];
?>
<div class="card-box admin-tabs" style="padding:6px;">
    <div class="d-flex gap-2 flex-wrap" data-keep-text><!-- page tabs keep their names -->
        <?php foreach ($adminTabs as $key => [$href, $icon, $label]): ?>
            <a href="<?= $href ?>"
               class="btn btn-sm <?= ($active===$key)?'btn-outline-teal':'btn-light' ?>"
               style="<?= ($active===$key)?'border-bottom:3px solid var(--teal-mid);border-radius:6px;':'' ?>">
               <?= $icon ?> <?= $label ?>
            </a>
        <?php endforeach; ?>
        <!-- Reminders send themselves once a day (includes/reminders.php), so no button is needed. -->
    </div>
</div>
