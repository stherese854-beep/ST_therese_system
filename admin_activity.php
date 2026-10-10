<?php
// ============================================================
//  ACTIVITY LOG  (admin_activity.php) -- admin only
// ============================================================
//  A read-only trail of the meaningful things that happen in the
//  system: sign-ins (and failed ones), account changes, appointments,
//  patient records, settings, and security events.
//
//  Nobody can delete an entry from here, not even the admin, so the
//  log can be trusted. Entries older than ACTIVITY_KEEP_MONTHS clear
//  themselves (checked once a day).
//
//  Filters: category, role, date range and a search. 100 entries per
//  page; "Download CSV" exports everything that matches.
// ============================================================
require_once 'config/auth.php';
require_login(['admin']);

const ACTIVITY_KEEP_MONTHS = 12;
const ACTIVITY_PER_PAGE    = 100;

// ---------- Old entries clear themselves (once a day) ----------
try {
    $today = date('Y-m-d');
    if ($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'activity_cleanup_on'")->fetchColumn() !== $today) {
        $pdo->exec("DELETE FROM activity_log WHERE created_at < (NOW() - INTERVAL " . ACTIVITY_KEEP_MONTHS . " MONTH)");
        save_setting($pdo, 'activity_cleanup_on', $today);
    }
} catch (Throwable $e) { /* never block the page */ }

// ---------- Filters ----------
$roleFilter = trim($_GET['role'] ?? '');
$q          = trim($_GET['q'] ?? '');
$cat        = isset(ACTIVITY_CATEGORIES[$_GET['cat'] ?? '']) ? $_GET['cat'] : '';
$from       = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$to         = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : '';
if (!empty($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) { $from = $to = $_GET['date']; }   // old one-day links
if ($from !== '' && $to !== '' && $from > $to) [$from, $to] = [$to, $from];
$page = max(1, (int)($_GET['page'] ?? 1));

// Everything except the category: the chips count inside these.
$where = []; $params = [];
if ($roleFilter !== '') { $where[] = "actor_role = ?"; $params[] = $roleFilter; }
if ($q !== '') {
    $where[] = "(action LIKE ? OR details LIKE ? OR actor_name LIKE ?)";
    $like = '%' . $q . '%'; array_push($params, $like, $like, $like);
}
if ($from !== '') { $where[] = "created_at >= ?"; $params[] = $from . ' 00:00:00'; }
if ($to !== '')   { $where[] = "created_at <= ?"; $params[] = $to . ' 23:59:59'; }
$baseWhere = $where ? implode(' AND ', $where) : '1=1';

// Count per category (one query).
$catCounts = array_fill_keys(array_keys(ACTIVITY_CATEGORIES), 0);
$caseSql = "CASE";
foreach (array_keys(ACTIVITY_CATEGORIES) as $k) $caseSql .= " WHEN " . activity_category_sql($k) . " THEN '$k'";
$caseSql .= " END";
$cc = $pdo->prepare("SELECT $caseSql AS c, COUNT(*) AS n FROM activity_log WHERE $baseWhere GROUP BY c");
$cc->execute($params);
foreach ($cc as $r) if (isset($catCounts[$r['c']])) $catCounts[$r['c']] = (int)$r['n'];
$allCount = array_sum($catCounts);

$fullWhere = $baseWhere . ($cat !== '' ? ' AND ' . activity_category_sql($cat) : '');
$matchCount = $cat !== '' ? $catCounts[$cat] : $allCount;

// ---------- CSV download (everything that matches, oldest kept order: newest first) ----------
if (($_GET['export'] ?? '') === 'csv') {
    $st = $pdo->prepare("SELECT created_at, actor_name, actor_role, action, details FROM activity_log
                          WHERE $fullWhere ORDER BY created_at DESC, id DESC LIMIT 50000");
    $st->execute($params);
    log_activity($pdo, 'Exported activity log', 'CSV download (' . $matchCount . ' entries)');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity-log-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                                  // so Excel reads the dashes and accents
    fputcsv($out, ['When', 'Who', 'Role', 'Category', 'Action', 'Details']);
    foreach ($st as $r) {
        fputcsv($out, [$r['created_at'], $r['actor_name'] ?: 'System', $r['actor_role'] ?: '',
                       ACTIVITY_CATEGORIES[activity_category($r['action'])], $r['action'], $r['details']]);
    }
    fclose($out);
    exit;
}

// ---------- This page of entries ----------
$pages = max(1, (int)ceil($matchCount / ACTIVITY_PER_PAGE));
$page  = min($page, $pages);
$st = $pdo->prepare("SELECT * FROM activity_log WHERE $fullWhere ORDER BY created_at DESC, id DESC
                      LIMIT " . ACTIVITY_PER_PAGE . " OFFSET " . (($page - 1) * ACTIVITY_PER_PAGE));
$st->execute($params);
$logs = $st->fetchAll();

$roles = $pdo->query("SELECT DISTINCT actor_role FROM activity_log WHERE actor_role IS NOT NULL AND actor_role <> '' ORDER BY actor_role")
             ->fetchAll(PDO::FETCH_COLUMN);

// A link that keeps the current filters, changing only $set.
function activity_url(array $set = []) {
    global $roleFilter, $q, $cat, $from, $to;
    $p = array_merge(['cat' => $cat, 'role' => $roleFilter, 'from' => $from, 'to' => $to, 'q' => $q], $set);
    return 'admin_activity?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null && $v !== 1));
}
$filtered = $roleFilter !== '' || $q !== '' || $from !== '' || $to !== '' || $cat !== '';

$page_title = "Activity Log";
include 'includes/head.php';
$active = 'activity';
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div>
                <h1 style="color:var(--teal-light)">Activity Log</h1>
                <div class="sub">A record of what happened in the system, and who did it</div>
            </div>
            <div class="d-flex align-items-center gap-2 no-print">
                <a href="<?= e(activity_url(['export' => 'csv'])) ?>" class="btn btn-light" data-keep-text>⬇ Download CSV</a>
                <?= print_menu('main', pdf_name('Activity-Log', date('Y-m-d'))) ?>
            </div>
        </div>

        <!-- Top tabs shared across admin pages -->
        <?php include 'includes/admin_tabs.php'; ?>

        <!-- Categories (counts follow the other filters) -->
        <nav class="act-cats mb-2 no-print" aria-label="Categories">
            <a href="<?= e(activity_url(['cat' => '', 'page' => 1])) ?>" class="<?= $cat === '' ? 'on' : '' ?>" data-keep-text>All <span class="act-n"><?= $allCount ?></span></a>
            <?php foreach (ACTIVITY_CATEGORIES as $k => $label): ?>
                <a href="<?= e(activity_url(['cat' => $k, 'page' => 1])) ?>" class="<?= $cat === $k ? 'on' : '' ?> <?= $k === 'security' && $catCounts[$k] ? 'act-sec' : '' ?>" data-keep-text>
                    <?= e($label) ?> <span class="act-n"><?= $catCounts[$k] ?></span></a>
            <?php endforeach; ?>
        </nav>

        <div class="card-box">
            <form method="GET" class="row g-2 align-items-center mb-3 no-print">
                <input type="hidden" name="cat" value="<?= e($cat) ?>">
                <div class="col-6 col-md-2">
                    <select name="role" class="form-select form-select-sm" aria-label="Role" onchange="this.form.submit()">
                        <option value="">All roles</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= e($r) ?>" <?= $roleFilter === $r ? 'selected' : '' ?>><?= ucfirst(e($r)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3 d-flex align-items-center gap-1">
                    <input type="date" name="from" class="form-control form-control-sm" aria-label="From" title="From" value="<?= e($from) ?>">
                    <span class="text-muted2">–</span>
                    <input type="date" name="to" class="form-control form-control-sm" aria-label="To" title="To" value="<?= e($to) ?>">
                </div>
                <div class="col-12 col-md">
                    <input type="text" name="q" class="form-control form-control-sm" aria-label="Search"
                           placeholder="Search action, name, details..." value="<?= e($q) ?>">
                </div>
                <div class="col-auto d-flex gap-1">
                    <button class="btn btn-sm btn-teal" type="submit">Filter</button>
                    <?php if ($filtered): ?><a href="admin_activity" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
                </div>
            </form>

            <div class="flex-between mb-2 flex-wrap gap-2" style="font-size:.82rem;">
                <span class="text-muted2">
                    <?= $matchCount ?> entr<?= $matchCount === 1 ? 'y' : 'ies' ?><?= $cat !== '' ? ' in ' . e(ACTIVITY_CATEGORIES[$cat]) : '' ?>
                    <?= $from !== '' || $to !== '' ? ' · ' . e($from ? date('M j, Y', strtotime($from)) : 'start') . ' – ' . e($to ? date('M j, Y', strtotime($to)) : 'today') : '' ?>
                    · kept for <?= ACTIVITY_KEEP_MONTHS ?> months
                </span>
                <?php if ($pages > 1): ?><span class="text-muted2">Page <?= $page ?> of <?= $pages ?></span><?php endif; ?>
            </div>

            <?php if (empty($logs)): ?>
                <div class="text-center text-muted2 py-5">
                    <div style="font-size:2.2rem;">📋</div>
                    <?= $filtered ? 'Nothing matches these filters.' : 'No activity recorded yet.' ?>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="data act-table">
                    <thead><tr><th>When</th><th>Who</th><th>Role</th><th>Action</th><th>Details</th></tr></thead>
                    <tbody>
                    <?php foreach ($logs as $log): $sec = activity_category($log['action']) === 'security'; ?>
                        <tr class="<?= $sec ? 'act-row-sec' : '' ?>">
                            <td><small class="text-muted2" style="white-space:nowrap;"><?= date('M j, Y g:i A', strtotime($log['created_at'])) ?></small></td>
                            <td><strong><?= e($log['actor_name'] ?: 'System') ?></strong></td>
                            <td><?= $log['actor_role'] ? '<span class="badge-pill b-pending">' . ucfirst(e($log['actor_role'])) . '</span>' : '–' ?></td>
                            <td><span class="badge-pill <?= $sec ? 'b-cancelled' : activity_badge($log['action']) ?>"><?= $sec ? '⚠️ ' : '' ?><?= e($log['action']) ?></span></td>
                            <td class="act-details"><?= e($log['details'] ?: '–') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pages > 1): ?>
                <nav class="d-flex justify-content-center align-items-center gap-2 mt-3 no-print" aria-label="Pages">
                    <a class="btn btn-sm btn-light <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(activity_url(['page' => $page - 1])) ?>" data-keep-text>← Newer</a>
                    <span class="text-muted2" style="font-size:.85rem;">Page <?= $page ?> of <?= $pages ?></span>
                    <a class="btn btn-sm btn-light <?= $page >= $pages ? 'disabled' : '' ?>" href="<?= e(activity_url(['page' => $page + 1])) ?>" data-keep-text>Older →</a>
                </nav>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<style>
.act-cats { display: flex; gap: 2px; overflow-x: auto; background: var(--card, #fff); border-radius: 12px; padding: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.act-cats a { flex: 0 0 auto; white-space: nowrap; padding: 6px 12px; border-radius: 9px; font-size: .86rem; font-weight: 600;
              color: #52606b; text-decoration: none; }
.act-cats a:hover { background: #eef7f6; color: var(--teal-mid); }
.act-cats a.on { background: #143a4a; color: #fff; }
.act-cats a.act-sec:not(.on) { color: #c0392b; }
.act-n { display: inline-block; min-width: 20px; padding: 0 6px; margin-left: 4px; border-radius: 10px; font-size: .72rem;
         line-height: 18px; text-align: center; background: #eef2f4; color: #3f5350; }
.act-cats a.on .act-n { background: rgba(255,255,255,.22); color: #fff; }
.act-table tr.act-row-sec td { background: #fdf0ee; }
.act-table tr.act-row-sec td:first-child { box-shadow: inset 4px 0 0 #c0392b; }
.act-details { color: #52606b; font-size: .86rem; max-width: 520px; }
</style>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
</body></html>
