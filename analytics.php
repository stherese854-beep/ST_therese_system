<?php
// ============================================================
//  ANALYTICS  (analytics.php) -- admin only
// ============================================================
//  Every clinic graph on one page: appointment volume over time,
//  outcomes, treatments, dentists, busiest days and hours,
//  cancellations, patients and reviews.
//
//  One date-range filter (top) applies to every appointment chart.
//  Each chart has a hover tooltip and a "View as table" fallback,
//  so no number depends on reading a colour. Charts are drawn with
//  Chart.js; all of them show a single measure in the clinic teal,
//  so none needs a legend (the title names what is plotted).
// ============================================================
require_once 'config/auth.php';
require_login(['admin']);

// ---------- Date range ----------
$ranges = ['30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last 12 months', 'all' => 'All time'];
$range  = array_key_exists($_GET['range'] ?? '', $ranges) ? $_GET['range'] : '365';

$today = new DateTimeImmutable('today');
if ($range === 'all') {
    $minDate = $pdo->query("SELECT MIN(appointment_date) FROM appointments")->fetchColumn();
    $start   = $minDate ? new DateTimeImmutable($minDate) : $today;
} else {
    $start = $today->modify('-' . ((int)$range - 1) . ' days');
}
$startStr = $start->format('Y-m-d');

// Appointments in range (upcoming ones included, so pending demand shows).
$ap = $pdo->prepare("SELECT appointment_date, appointment_time, status, treatment, dentist, cancelled_by
                       FROM appointments WHERE appointment_date >= ?");
$ap->execute([$startStr]);
$appts = $ap->fetchAll();
$total = count($appts);

// ---------- Headline numbers ----------
$byStatus = [];
foreach ($appts as $a) $byStatus[$a['status']] = ($byStatus[$a['status']] ?? 0) + 1;
$completed  = $byStatus['Completed'] ?? 0;
$noShows    = $byStatus['No-show'] ?? 0;
$cancelled  = $byStatus['Cancelled'] ?? 0;
$attendBase = $completed + $noShows;                         // visits that were due and resolved
$pct = fn($n, $d) => $d > 0 ? round($n * 100 / $d) . '%' : '—';

$patientCount = (int)$pdo->query("SELECT COUNT(*) FROM patients p LEFT JOIN users u ON p.user_id = u.id
                                   WHERE (u.id IS NULL OR u.role = 'patient') AND p.status <> 'Archived'")->fetchColumn();
$rev = $pdo->query("SELECT COUNT(*) n, AVG(rating) avg FROM reviews")->fetch();

// ---------- Chart data ----------
$charts = [];   // id => [title, subtitle, labels, values, unit, orientation]

// Appointments per month (every month in range, zeros included)
$months = [];
$cursor = $start->modify('first day of this month');
$lastMonth = $today->modify('first day of this month');
foreach ($appts as $a) {                                      // stretch to upcoming months
    $m = (new DateTimeImmutable($a['appointment_date']))->modify('first day of this month');
    if ($m > $lastMonth) $lastMonth = $m;
}
while ($cursor <= $lastMonth) { $months[$cursor->format('Y-m')] = 0; $cursor = $cursor->modify('+1 month'); }
foreach ($appts as $a) { $k = substr($a['appointment_date'], 0, 7); if (isset($months[$k])) $months[$k]++; }
$charts['perMonth'] = ['Appointments per month', 'By appointment date · includes upcoming',
    array_map(fn($k) => date('M Y', strtotime("$k-01")), array_keys($months)), array_values($months), 'appointment', 'line'];

// New patients per month
$pm = $pdo->prepare("SELECT DATE_FORMAT(p.created_at, '%Y-%m') m, COUNT(*) n FROM patients p
                       LEFT JOIN users u ON p.user_id = u.id
                      WHERE (u.id IS NULL OR u.role = 'patient') AND p.created_at >= ? GROUP BY m");
$pm->execute([$start->modify('first day of this month')->format('Y-m-d')]);
$newPts = array_fill_keys(array_keys($months), 0);
foreach ($pm->fetchAll() as $r) if (isset($newPts[$r['m']])) $newPts[$r['m']] = (int)$r['n'];
$charts['newPatients'] = ['New patients per month', 'Patient records created',
    array_map(fn($k) => date('M Y', strtotime("$k-01")), array_keys($newPts)), array_values($newPts), 'patient', 'line'];

// Helper: count by a key, sorted largest first
$countBy = function ($rows, $fn) {
    $c = [];
    foreach ($rows as $r) { $k = $fn($r); if ($k === null || $k === '') continue; $c[$k] = ($c[$k] ?? 0) + 1; }
    arsort($c);
    return $c;
};

$STATUS_COLORS = [                     // categorical slots 1-6, in this fixed order
    'Completed'    => '#2a78d6',
    'Confirmed'    => '#eb6834',
    'Pending'      => '#1baf7a',
    'Needs Review' => '#eda100',
    'Cancelled'    => '#e87ba4',
    'No-show'      => '#008300',
];
$st = []; $other = []; $stColors = [];
foreach ($STATUS_COLORS as $name => $col) {
    $n = $byStatus[$name] ?? 0;
    if ($n > 0) { $st[$name] = $n; $stColors[] = $col; }
}
foreach ($byStatus as $name => $n) if (!isset($STATUS_COLORS[$name]) && $n > 0) $other[] = "$name $n";
if ($other) { $st['Other'] = array_sum(array_map(fn($x) => (int)preg_replace('/.* /', '', $x), $other)); $stColors[] = '#a8a7a0'; }
$charts['status'] = ['Appointments by status', 'Share of booked appointments' . ($other ? ' · Other = ' . implode(', ', $other) : ''),
    array_keys($st), array_values($st), 'appointment', 'donut', $stColors];

$tr = $countBy($appts, fn($a) => $a['treatment']);
$charts['treatments'] = ['Top treatments', 'Most-booked services', array_keys($tr), array_values($tr), 'appointment', 'h'];

$dn = $countBy($appts, fn($a) => $a['dentist'] ?: 'To be assigned');
$charts['dentists'] = ['Appointments per dentist', 'Workload across the team', array_keys($dn), array_values($dn), 'appointment', 'h'];

// Busiest weekdays (fixed Mon → Sun order)
$wd = array_fill_keys(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], 0);
foreach ($appts as $a) $wd[date('D', strtotime($a['appointment_date']))]++;
$charts['weekdays'] = ['Busiest days of the week', 'Appointments by weekday', array_keys($wd), array_values($wd), 'appointment', 'v'];

// Busiest hours (e.g. "09:30 AM" → 9 AM), in clock order
$hr = [];
foreach ($appts as $a) {
    $t = strtotime('2000-01-01 ' . $a['appointment_time']);
    if ($t === false) continue;
    $h = (int)date('G', $t);
    $hr[$h] = ($hr[$h] ?? 0) + 1;
}
ksort($hr);
$charts['hours'] = ['Busiest times of day', 'Appointments by starting hour',
    array_map(fn($h) => date('g A', mktime($h, 0)), array_keys($hr)), array_values($hr), 'appointment', 'v'];

// Who cancels
$cb = ['Patient' => 0, 'Clinic' => 0];      // anyone other than the patient counts as the clinic
foreach ($appts as $a) if ($a['status'] === 'Cancelled') $cb[$a['cancelled_by'] === 'patient' ? 'Patient' : 'Clinic']++;
$charts['cancelledBy'] = ['Who cancels', 'Share of cancelled appointments', array_keys($cb), array_values($cb), 'cancellation', 'split', ['#2a78d6', '#eb6834']];

// Patient age groups (all active patients — not date-filtered)
$ages = array_fill_keys(['0–12','13–17','18–29','30–44','45–59','60+','Unknown'], 0);
$ageRows = $pdo->query("SELECT p.age, p.date_of_birth FROM patients p LEFT JOIN users u ON p.user_id = u.id
                         WHERE (u.id IS NULL OR u.role = 'patient') AND p.status <> 'Archived'")->fetchAll();
foreach ($ageRows as $r) {
    $age = $r['date_of_birth'] ? (int)(new DateTime($r['date_of_birth']))->diff(new DateTime())->y
                               : ($r['age'] !== null && $r['age'] !== '' ? (int)$r['age'] : null);
    $g = $age === null ? 'Unknown' : ($age <= 12 ? '0–12' : ($age <= 17 ? '13–17' : ($age <= 29 ? '18–29'
       : ($age <= 44 ? '30–44' : ($age <= 59 ? '45–59' : '60+')))));
    $ages[$g]++;
}
if ($ages['Unknown'] === 0) unset($ages['Unknown']);
$charts['ages'] = ['Patient age groups', 'All active patients (not date-filtered)', array_keys($ages), array_values($ages), 'patient', 'v'];

// Review ratings 1–5 (all reviews — not date-filtered)
$rt = array_fill_keys([5,4,3,2,1], 0);
foreach ($pdo->query("SELECT rating FROM reviews")->fetchAll(PDO::FETCH_COLUMN) as $r) if (isset($rt[(int)$r])) $rt[(int)$r]++;
$charts['ratings'] = ['Review ratings', 'All patient reviews (not date-filtered)',
    array_map(fn($s) => $s . ' ★', array_keys($rt)), array_values($rt), 'review', 'h'];

$chartOrder = ['perMonth', 'newPatients', 'status', 'treatments', 'dentists', 'weekdays', 'hours', 'ages', 'ratings', 'cancelledBy'];

$page_title = "Analytics";
include 'includes/head.php';
$active = 'analytics';
?>
<style>
/* Chart chrome — one teal series, recessive grid/axes (see the dataviz rules) */
.viz { --series-1: #0d9488; --series-1-hover: #0a7a70; --grid: #e6ecef; --axis: #c9d3d9;
       --ink: #1d2b33; --ink-2: #52606b; --muted: #7d8a95; }
.viz-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.viz-grid .wide { grid-column: 1 / -1; }
@media (max-width: 900px) { .viz-grid { grid-template-columns: 1fr; } }
.viz-card { background: #fff; border-radius: 16px; padding: 18px 18px 12px; box-shadow: 0 1px 3px rgba(0,0,0,.06); min-width: 0; }
.viz-card h6 { margin: 0; font-weight: 700; color: var(--ink); }
.viz-card .sub { font-size: .78rem; color: var(--muted); margin: 2px 0 10px; }
.viz-canvas { position: relative; height: 240px; }
.viz-canvas.tall { height: 280px; }
.viz-canvas.short { height: 64px; }
.viz-legend { list-style: none; padding: 0; margin: 8px 0 0; display: flex; flex-wrap: wrap; gap: 4px 14px; font-size: .8rem; color: var(--ink-2); }
.viz-legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 5px; vertical-align: -1px; }
.viz-legend b { color: var(--ink); font-weight: 600; margin-left: 3px; }
.viz-legend span { color: var(--muted); }
.viz-empty { height: 240px; display: flex; align-items: center; justify-content: center; color: var(--muted); font-size: .9rem; }
.viz-card details { margin-top: 6px; font-size: .82rem; }
.viz-card summary { cursor: pointer; color: var(--ink-2); }
.viz-card details table { width: 100%; margin-top: 6px; border-collapse: collapse; font-variant-numeric: tabular-nums; }
.viz-card details td, .viz-card details th { padding: 4px 6px; border-bottom: 1px solid var(--grid); text-align: left; }
.viz-card details td:last-child, .viz-card details th:last-child { text-align: right; }
.kpi-row { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
@media (max-width: 1100px) { .kpi-row { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 560px)  { .kpi-row { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.kpi { background: #fff; border-radius: 14px; padding: 14px 16px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.kpi .label { font-size: .74rem; color: var(--muted); }
.kpi .value { font-size: 1.6rem; font-weight: 700; color: var(--ink); line-height: 1.2; margin-top: 2px; }
.kpi .note { font-size: .72rem; color: var(--muted); }
.range-row { display: flex; gap: 6px; flex-wrap: wrap; }
.range-row a { padding: 6px 12px; border-radius: 999px; font-size: .82rem; text-decoration: none; color: var(--ink-2); background: #fff; border: 1px solid #dde5ea; }
.range-row a.on { background: var(--series-1); border-color: var(--series-1); color: #fff; font-weight: 600; }
</style>

<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main viz">
        <div class="page-head">
            <div>
                <h1 style="color:var(--teal-light)">Analytics</h1>
                <div class="sub">All clinic graphs in one place · <?= e($ranges[$range]) ?> (from <?= $start->format('M j, Y') ?>)</div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="clock"><span class="time" id="clock">--:--</span><br><span id="clock-date"></span></div>
            </div>
        </div>

        <!-- One filter row, above all charts -->
        <nav class="range-row mb-3" aria-label="Date range">
            <?php foreach ($ranges as $k => $lbl): ?>
                <a href="analytics?range=<?= $k ?>" class="<?= $k === $range ? 'on' : '' ?>" <?= $k === $range ? 'aria-current="page"' : '' ?>><?= $lbl ?></a>
            <?php endforeach; ?>
        </nav>

        <!-- Headline numbers -->
        <div class="kpi-row">
            <div class="kpi"><div class="label">Appointments</div><div class="value"><?= number_format($total) ?></div><div class="note">in this range</div></div>
            <div class="kpi"><div class="label">Completion rate</div><div class="value"><?= $pct($completed, $attendBase) ?></div><div class="note"><?= $completed ?> of <?= $attendBase ?> due visits</div></div>
            <div class="kpi"><div class="label">No-show rate</div><div class="value"><?= $pct($noShows, $attendBase) ?></div><div class="note"><?= $noShows ?> missed</div></div>
            <div class="kpi"><div class="label">Cancellation rate</div><div class="value"><?= $pct($cancelled, $total) ?></div><div class="note"><?= $cancelled ?> cancelled</div></div>
            <div class="kpi"><div class="label">Active patients</div><div class="value"><?= number_format($patientCount) ?></div><div class="note">all time</div></div>
            <div class="kpi"><div class="label">Average rating</div><div class="value"><?= $rev['n'] ? number_format((float)$rev['avg'], 1) . ' ★' : '—' ?></div><div class="note"><?= (int)$rev['n'] ?> review<?= (int)$rev['n'] === 1 ? '' : 's' ?></div></div>
        </div>

        <div class="viz-grid">
            <?php foreach ($chartOrder as $id):
                [$title, $sub, $labels, $values, $unit, $orient] = $charts[$id];
                $colors = $charts[$id][6] ?? [];
                $isWide = in_array($id, ['perMonth', 'cancelledBy'], true);   // cancelledBy: one short bar, full width
                $hasData = array_sum($values) > 0;
                $tall = $orient === 'h' && count($labels) > 5;
                $sum = max(1, array_sum($values));
            ?>
            <section class="viz-card <?= $isWide ? 'wide' : '' ?>" aria-labelledby="t-<?= $id ?>">
                <h6 id="t-<?= $id ?>"><?= e($title) ?></h6>
                <div class="sub"><?= e($sub) ?></div>
                <?php if ($hasData): ?>
                    <div class="viz-canvas <?= $tall ? 'tall' : '' ?> <?= $orient === 'split' ? 'short' : '' ?>">
                        <canvas id="c-<?= $id ?>" role="img"
                                aria-label="<?= e($title) ?>: <?= e(implode(', ', array_map(fn($l, $v) => "$l $v", $labels, $values))) ?>"></canvas>
                    </div>
                    <?php if ($colors): ?>
                        <!-- Legend with counts + %: identity never rests on colour alone -->
                        <ul class="viz-legend">
                            <?php foreach ($labels as $i => $l): if ((int)$values[$i] === 0 && $orient === 'split') continue; ?>
                                <li><i style="background:<?= e($colors[$i]) ?>"></i><?= e($l) ?>
                                    <b><?= number_format($values[$i]) ?></b> <span>(<?= round($values[$i] * 100 / $sum) ?>%)</span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="viz-empty">No data in this range yet.</div>
                <?php endif; ?>
                <details>
                    <summary>View as table</summary>
                    <table>
                        <thead><tr><th><?= in_array($id, ['perMonth','newPatients'], true) ? 'Month' : 'Category' ?></th><th><?= ucfirst($unit) ?>s</th></tr></thead>
                        <tbody>
                        <?php foreach ($labels as $i => $l): ?>
                            <tr><td><?= e($l) ?></td><td><?= number_format($values[$i]) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$labels): ?><tr><td colspan="2" class="text-muted2">No data</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </details>
            </section>
            <?php endforeach; ?>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="js/app.js"></script>
<script>
if (document.getElementById('clock')) startClock();

(function () {
    if (!window.Chart) return;                       // CDN blocked: the tables still carry every number
    var css   = getComputedStyle(document.querySelector('.viz'));
    var v     = function (n) { return css.getPropertyValue(n).trim(); };
    var TEAL  = v('--series-1'), TEAL_HOVER = v('--series-1-hover');
    Chart.defaults.font.family = 'system-ui, -apple-system, "Segoe UI", sans-serif';
    Chart.defaults.font.size   = 12;
    Chart.defaults.color       = v('--muted');

    var charts = <?= json_encode(array_map(fn($c) => ['labels' => $c[2], 'values' => $c[3], 'unit' => $c[4], 'form' => $c[5], 'colors' => $c[6] ?? []], $charts), JSON_HEX_TAG) ?>;
    var GRID = v('--grid'), AXIS = v('--axis'), INK2 = v('--ink-2');
    var tooltipBase = { backgroundColor: '#1d2b33', padding: 10, displayColors: false };

    Object.keys(charts).forEach(function (id) {
        var el = document.getElementById('c-' + id);
        if (!el) return;
        var c = charts[id];
        var plural = function (n) { return n + ' ' + c.unit + (n === 1 ? '' : 's'); };
        var total  = c.values.reduce(function (a, b) { return a + b; }, 0);
        var pct    = function (n) { return total ? Math.round(n * 100 / total) + '%' : '0%'; };

        // ---- Trend over time: 2px line, soft area wash, ringed markers ----
        if (c.form === 'line') {
            new Chart(el, {
                type: 'line',
                data: { labels: c.labels, datasets: [{
                    data: c.values, borderColor: TEAL, borderWidth: 2,
                    cubicInterpolationMode: 'monotone',                          // smooth, but never dips below a real value
                    fill: true, backgroundColor: 'rgba(13,148,136,0.10)',
                    pointRadius: 4, pointHoverRadius: 6, pointBackgroundColor: TEAL,
                    pointBorderColor: '#fff', pointBorderWidth: 2                  // surface ring
                }]},
                options: {
                    maintainAspectRatio: false, animation: { duration: 300 },
                    interaction: { mode: 'index', intersect: false },               // crosshair-style hover
                    plugins: { legend: { display: false },
                               tooltip: Object.assign({}, tooltipBase, { callbacks: { label: function (ctx) { return plural(ctx.parsed.y); } } }) },
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: GRID, drawTicks: false }, border: { display: false } },
                        x: { grid: { display: false }, border: { color: AXIS }, ticks: { color: INK2, maxRotation: 0, autoSkip: true } }
                    }
                }
            });
            return;
        }

        // ---- Part-to-whole at a glance: donut with the total in the middle ----
        if (c.form === 'donut') {
            new Chart(el, {
                type: 'doughnut',
                data: { labels: c.labels, datasets: [{
                    data: c.values, backgroundColor: c.colors,
                    borderColor: '#fff', borderWidth: 2,                            // 2px surface gap between slices
                    hoverOffset: 6
                }]},
                options: {
                    maintainAspectRatio: false, cutout: '62%', animation: { duration: 300 },
                    plugins: { legend: { display: false },                          // HTML legend below carries labels + %
                               tooltip: Object.assign({}, tooltipBase, { callbacks: { label: function (ctx) {
                                   return ctx.label + ': ' + plural(ctx.parsed) + ' (' + pct(ctx.parsed) + ')'; } } }) }
                },
                plugins: [{
                    id: 'centerTotal',
                    afterDraw: function (chart) {
                        var m = chart.getDatasetMeta(0).data[0]; if (!m) return;
                        var ctx = chart.ctx; ctx.save(); ctx.textAlign = 'center';
                        ctx.fillStyle = v('--ink'); ctx.font = '700 22px system-ui, sans-serif';
                        ctx.fillText(total, m.x, m.y + 4);
                        ctx.fillStyle = v('--muted'); ctx.font = '12px system-ui, sans-serif';
                        ctx.fillText('total', m.x, m.y + 22); ctx.restore();
                    }
                }]
            });
            return;
        }

        // ---- Two-part share: one 100% bar split in two (not a 2-slice pie) ----
        if (c.form === 'split') {
            new Chart(el, {
                type: 'bar',
                data: { labels: [''], datasets: c.labels.map(function (l, i) {
                    return { label: l, data: [c.values[i]], backgroundColor: c.colors[i], borderColor: '#fff', borderWidth: { left: i ? 2 : 0 },
                             borderSkipped: false, borderRadius: 4, barThickness: 24 };
                }) },
                options: {
                    indexAxis: 'y', maintainAspectRatio: false, animation: { duration: 300 },
                    plugins: { legend: { display: false },
                               tooltip: Object.assign({}, tooltipBase, { callbacks: { title: function () { return ''; },
                                   label: function (ctx) { return ctx.dataset.label + ': ' + plural(ctx.parsed.x) + ' (' + pct(ctx.parsed.x) + ')'; } } }) },
                    scales: { x: { stacked: true, display: false, max: total }, y: { stacked: true, display: false } }
                }
            });
            return;
        }

        // ---- Magnitude / ranking: thin bars ----
        var horizontal = c.form === 'h';
        new Chart(el, {
            type: 'bar',
            data: { labels: c.labels, datasets: [{
                data: c.values,
                backgroundColor: TEAL, hoverBackgroundColor: TEAL_HOVER,
                maxBarThickness: 24,                               // thin marks, never fill the slot
                borderRadius: horizontal ? { topRight: 4, bottomRight: 4 } : { topLeft: 4, topRight: 4 },
                borderSkipped: 'start'                             // square at the baseline
            }]},
            options: {
                indexAxis: horizontal ? 'y' : 'x',
                maintainAspectRatio: false,
                animation: { duration: 300 },
                interaction: { mode: 'index', intersect: false },  // hover target = whole band, not just the bar
                plugins: {
                    legend: { display: false },                    // single series: the title names it
                    tooltip: Object.assign({}, tooltipBase, { callbacks: { label: function (ctx) { return plural(ctx.parsed[horizontal ? 'x' : 'y']); } } })
                },
                scales: {
                    [horizontal ? 'x' : 'y']: {
                        beginAtZero: true,
                        ticks: { precision: 0 },                   // counts: whole numbers only
                        grid: { color: GRID, drawTicks: false },
                        border: { display: false }
                    },
                    [horizontal ? 'y' : 'x']: {
                        grid: { display: false },
                        border: { color: AXIS },
                        ticks: { color: INK2, autoSkip: !horizontal, maxRotation: 0 }
                    }
                }
            }
        });
    });
})();
</script>
</body></html>
