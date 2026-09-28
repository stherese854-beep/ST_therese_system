<?php
// ============================================================
//  MY ACTIVITY  (my_activity.php) -- dentist / staff
// ============================================================
//  The same kind of trail as the admin Activity Log, but showing
//  ONLY what the logged-in person did themselves. Read-only: an
//  entry cannot be edited or deleted from here. (Patients see
//  theirs in the portal, under "My Activity".)
// ============================================================
require_once 'config/auth.php';
require_login(['admin','dentist','staff']);

// ---------- Filters ----------
$q     = trim($_GET['q'] ?? '');
$date  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : '';
$limit = 100;

$logs       = my_activity_rows($pdo, $q, $date, $limit);
$totalCount = my_activity_count($pdo);

$page_title = "My Activity";
include 'includes/head.php';
$active = 'my_activity';
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div>
                <h1 style="color:var(--teal-light)">My Activity</h1>
                <div class="sub">Everything you have done in the system — only your own actions are shown</div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="clock"><span class="time" id="clock">--:--</span><br><span id="clock-date"></span></div>
            </div>
        </div>

        <div class="card-box">
            <div class="flex-between mb-3 flex-wrap gap-2">
                <h5 class="mb-0">
                    Recent Activity
                    <small class="text-muted2 d-block" style="font-size:.75rem;">
                        Showing <?= count($logs) ?> of <?= $totalCount ?> entr<?= $totalCount === 1 ? 'y' : 'ies' ?> (most recent <?= $limit ?>)
                    </small>
                </h5>
                <form method="GET" class="d-flex gap-2 flex-wrap">
                    <input type="date" name="date" class="form-control form-control-sm" style="width:auto;"
                           title="Show only this exact date" value="<?= e($date) ?>" onchange="this.form.submit()">
                    <input type="text" name="q" class="form-control form-control-sm" style="width:200px;"
                           placeholder="Search action or details..." value="<?= e($q) ?>">
                    <button class="btn btn-sm btn-teal" type="submit">Filter</button>
                    <?php if ($q !== '' || $date !== ''): ?>
                        <a href="my_activity" class="btn btn-sm btn-outline-secondary">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (empty($logs)): ?>
                <div class="text-center text-muted2 py-5">
                    <div style="font-size:2.2rem;">🧾</div>
                    <?= ($q !== '' || $date !== '') ? 'Nothing matches your filter.' : 'No activity recorded yet.' ?>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="data">
                    <thead><tr><th>When</th><th>Action</th><th>Details</th></tr></thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><small class="text-muted2"><?= date('M j, Y g:i A', strtotime($log['created_at'])) ?></small></td>
                            <td><span class="badge-pill <?= activity_badge($log['action']) ?>"><?= e($log['action']) ?></span></td>
                            <td class="text-muted2"><?= e($log['details'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
</body></html>
