<?php
// ============================================================
//  DASHBOARD  (dashboard.php)
// ============================================================
require_once 'config/auth.php';
require_login(['admin','dentist','staff']);   // any logged-in staff/dentist/admin can view

// ---------- Pull live numbers from the database ----------
// Dentists should only see their OWN patients/appointments; admin & staff see all.
$role = current_role();
$isDentist = ($role === 'dentist');
$myName = $_SESSION['name'] ?? '';

// Appointment times are stored as text ("09:30 AM", some older ones "9:30AM"),
// so sort them as real times — otherwise "01:00 PM" comes before "11:30 AM".
const DASH_TIME_ORDER = "STR_TO_DATE(REPLACE(appointment_time, ' ', ''), '%h:%i%p')";

// ---------- Calendar <-> appointments (answered as JSON for the page's script) ----------
// ?day=YYYY-MM-DD   -> that day's appointments (a dentist only gets their own)
// ?month=YYYY-MM    -> how many appointments each day of that month has (for the dots)
if (isset($_GET['day']) || isset($_GET['month'])) {
    header('Content-Type: application/json');
    $scopeSql = $isDentist ? " AND dentist = ?" : "";
    $scopePrm = $isDentist ? [$myName] : [];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day'] ?? '')) {
        $q = $pdo->prepare("SELECT patient_name, appointment_date, appointment_time, treatment, status FROM appointments
                             WHERE appointment_date = ?$scopeSql ORDER BY " . DASH_TIME_ORDER . ", id");
        $q->execute(array_merge([$_GET['day']], $scopePrm));
        echo json_encode($q->fetchAll(PDO::FETCH_ASSOC));
    } elseif (preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '')) {
        $q = $pdo->prepare("SELECT appointment_date d, COUNT(*) c FROM appointments
                             WHERE DATE_FORMAT(appointment_date, '%Y-%m') = ?$scopeSql
                               AND status NOT IN ('Cancelled','Disapproved','Expired')
                          GROUP BY appointment_date");
        $q->execute(array_merge([$_GET['month']], $scopePrm));
        echo json_encode($q->fetchAll(PDO::FETCH_KEY_PAIR) ?: new stdClass);
    } else {
        echo '[]';
    }
    exit;
}

// A one-time pop-up greeting, shown once right after logging in
// (set by login.php, cleared here so it never shows again this session).
$justRegistered   = !empty($_SESSION['just_registered']);
if ($justRegistered) unset($_SESSION['just_registered']);
$showWelcomePopup = !empty($_SESSION['show_welcome_popup']);
if ($showWelcomePopup) unset($_SESSION['show_welcome_popup']);
$firstName = explode(' ', $myName)[0];

// A reusable WHERE fragment so a dentist's numbers are scoped to their patients.
$apptScope = $isDentist ? " AND dentist = " . $pdo->quote($myName) : "";

$totalPatients = $isDentist
    ? $pdo->query("SELECT COUNT(*) FROM patients WHERE primary_dentist = " . $pdo->quote($myName))->fetchColumn()
    : $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();

// "Today's Appointments" must count TODAY only (was counting every date before).
$todaysAppts = $pdo->query(
    "SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()$apptScope"
)->fetchColumn();

$pendingRequests = $pdo->query(
    "SELECT COUNT(*) FROM appointments WHERE status='Pending'$apptScope"
)->fetchColumn();

$treatmentsDone = $pdo->query("SELECT COUNT(*) FROM treatments WHERE status='Completed'")->fetchColumn();

// Today's appointment list (today only, earliest time first).
$appts = $pdo->query(
    "SELECT * FROM appointments
     WHERE appointment_date = CURDATE()$apptScope
     ORDER BY " . DASH_TIME_ORDER . ", id ASC"
)->fetchAll();

// Recent patients
$recent = $isDentist
    ? $pdo->query("SELECT * FROM patients WHERE primary_dentist = " . $pdo->quote($myName) . " ORDER BY id DESC LIMIT 4")->fetchAll()
    : $pdo->query("SELECT * FROM patients ORDER BY id DESC LIMIT 4")->fetchAll();

// Treatment breakdown (count per treatment type)
$breakdown = $pdo->query("SELECT treatment, COUNT(*) AS cnt FROM appointments WHERE 1=1$apptScope GROUP BY treatment ORDER BY cnt DESC")->fetchAll();

// Real sub-figures for the stat cards (replaces the old hard-coded numbers).
$newPatientsMonth = $isDentist
    ? $pdo->query("SELECT COUNT(*) FROM patients WHERE primary_dentist = " . $pdo->quote($myName) . " AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn()
    : (int)$pdo->query("SELECT COUNT(*) FROM patients WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
$confirmedToday = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date=CURDATE() AND status='Confirmed'$apptScope")->fetchColumn();
$completedMonth = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE status='Completed' AND MONTH(appointment_date)=MONTH(CURDATE()) AND YEAR(appointment_date)=YEAR(CURDATE())$apptScope")->fetchColumn();
$maxCnt = 1;
foreach ($breakdown as $b) { if ($b['cnt'] > $maxCnt) $maxCnt = $b['cnt']; }

// colors used for avatars / bars
$avatarColors = ['#f5a3a3','#f5d6a3','#a3d8f5','#c3a3f5','#a3f5c3'];

// ============================================================
//  STAT-CARD TREND SPARKLINES
// ============================================================
// The stat cards used to show a decorative bar at a fixed, made-up width.
// This replaces that with a real 7-day trend (daily counts) for each metric,
// drawn as a tiny bar-sparkline — the right form for "a headline number plus
// its recent trend" (a full chart would be overkill for 4 numbers).

// Counts rows in $table, grouped by day, for the last 7 days (today included).
// Missing days come back as 0 so every sparkline always has exactly 7 bars.
function seven_day_counts($pdo, $table, $dateCol, $extraWhere = '', $params = []) {
    $days = [];
    for ($i = 6; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-$i day"))] = 0;

    $stmt = $pdo->prepare(
        "SELECT DATE($dateCol) AS d, COUNT(*) AS c FROM $table
         WHERE DATE($dateCol) >= CURDATE() - INTERVAL 6 DAY $extraWhere
         GROUP BY DATE($dateCol)"
    );
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        if (isset($days[$row['d']])) $days[$row['d']] = (int)$row['c'];
    }
    return array_values($days);
}

// Draws a small bar-sparkline as inline SVG. Thin rounded bars, one hue —
// the value under each bar is available on hover (native tooltip) so nothing
// important is color-only.
function render_sparkline($values, $color, $height = 30, $barW = 8, $gap = 4) {
    $max = max(1, max($values));
    $width = count($values) * $barW + (count($values) - 1) * $gap;
    $bars = '';
    foreach ($values as $i => $v) {
        $h = $v > 0 ? max(3, (int)round(($v / $max) * ($height - 4))) : 2;
        $x = $i * ($barW + $gap);
        $y = $height - $h;
        $bars .= "<rect x='$x' y='$y' width='$barW' height='$h' rx='2' fill='$color'><title>$v</title></rect>";
    }
    return "<svg width='$width' height='$height' viewBox='0 0 $width $height' role='img' aria-label='7-day trend'>$bars</svg>";
}

// ============================================================
//  TOTAL-PATIENTS GROWTH LINE (day-by-day, folding to month-by-month)
// ============================================================
// "Total Patients" is a running total, so a growth LINE fits it better than
// a bar-sparkline of daily counts. While the clinic's whole patient history
// fits inside one month, the line plots day by day; once that history spans
// more than a month, a daily line would get too dense to read, so it folds
// to one point per month instead — same idea, coarser grain.
function patient_growth_series($pdo, $isDentist, $myName) {
    $where  = $isDentist ? "WHERE primary_dentist = ?" : "";
    $params = $isDentist ? [$myName] : [];

    $earliestStmt = $pdo->prepare("SELECT MIN(created_at) FROM patients $where");
    $earliestStmt->execute($params);
    $earliest = $earliestStmt->fetchColumn();

    if (!$earliest) {
        return ['mode' => 'daily', 'labels' => [date('M j')], 'values' => [0]];
    }

    $spanDays = (int)((strtotime('today') - strtotime(date('Y-m-d', strtotime($earliest)))) / 86400);

    if ($spanDays <= 31) {
        // ---- Daily cumulative total, from the first patient to today ----
        $stmt = $pdo->prepare(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM patients $where GROUP BY DATE(created_at)"
        );
        $stmt->execute($params);
        $perDay = [];
        foreach ($stmt->fetchAll() as $row) $perDay[$row['d']] = (int)$row['c'];

        $labels = []; $values = []; $running = 0;
        $start = strtotime(date('Y-m-d', strtotime($earliest)));
        for ($t = $start; $t <= strtotime('today'); $t += 86400) {
            $d = date('Y-m-d', $t);
            $running += $perDay[$d] ?? 0;
            $labels[] = date('M j', $t);
            $values[] = $running;
        }
        return ['mode' => 'daily', 'labels' => $labels, 'values' => $values];
    }

    // ---- Monthly cumulative total, from the first patient's month to this month ----
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(created_at,'%Y-%m') AS m, COUNT(*) AS c FROM patients $where GROUP BY m"
    );
    $stmt->execute($params);
    $perMonth = [];
    foreach ($stmt->fetchAll() as $row) $perMonth[$row['m']] = (int)$row['c'];

    $labels = []; $values = []; $running = 0;
    $cursor = strtotime(date('Y-m-01', strtotime($earliest)));
    $end    = strtotime(date('Y-m-01'));
    while ($cursor <= $end) {
        $m = date('Y-m', $cursor);
        $running += $perMonth[$m] ?? 0;
        $labels[] = date('M \'y', $cursor);
        $values[] = $running;
        $cursor = strtotime('+1 month', $cursor);
    }
    return ['mode' => 'monthly', 'labels' => $labels, 'values' => $values];
}

// Draws a thin line graph as inline SVG — a 2px rounded line, a filled dot
// on the last point, and a larger invisible hit-target circle per point so
// hovering any point shows its date/value as a native tooltip.
function render_linechart($labels, $values, $color, $width = 220, $height = 46) {
    $n = count($values);
    if ($n < 2) { $labels[] = $labels[0] ?? ''; $values[] = $values[0] ?? 0; $n = 2; }

    $max = max(1, max($values));
    $min = min(0, min($values));
    $range = max(1, $max - $min);
    $padX = 4; $padY = 6;
    $plotW = $width - $padX * 2;
    $plotH = $height - $padY * 2;

    $pts = [];
    foreach ($values as $i => $v) {
        $x = $padX + ($n === 1 ? 0 : ($i / ($n - 1)) * $plotW);
        $y = $padY + $plotH - (($v - $min) / $range) * $plotH;
        $pts[] = [round($x, 1), round($y, 1)];
    }

    $poly = implode(' ', array_map(fn($p) => "$p[0],$p[1]", $pts));

    $dots = '';
    foreach ($pts as $i => $p) {
        $isLast = ($i === $n - 1);
        $label = htmlspecialchars($labels[$i] ?? '', ENT_QUOTES);
        $dots .= "<circle cx='$p[0]' cy='$p[1]' r='9' fill='transparent'><title>$label: {$values[$i]}</title></circle>";
        if ($isLast) {
            $dots .= "<circle cx='$p[0]' cy='$p[1]' r='3' fill='$color'/>";
        }
    }

    return "<svg width='$width' height='$height' viewBox='0 0 $width $height' role='img' aria-label='patient growth'>
        <polyline points='$poly' fill='none' stroke='$color' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/>
        $dots
    </svg>";
}

$patientsGrowth = patient_growth_series($pdo, $isDentist, $myName);

$apptsTrend = $isDentist
    ? seven_day_counts($pdo, 'appointments', 'appointment_date', ' AND dentist = ?', [$myName])
    : seven_day_counts($pdo, 'appointments', 'appointment_date');

$pendingTrend = $isDentist
    ? seven_day_counts($pdo, 'appointments', 'appointment_date', " AND status='Pending' AND dentist = ?", [$myName])
    : seven_day_counts($pdo, 'appointments', 'appointment_date', " AND status='Pending'");

$treatTrend = seven_day_counts($pdo, 'treatments', 'treatment_date', " AND status='Completed'");

$page_title = "Dashboard";
include 'includes/head.php';
$active = 'dashboard';
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main">
        <!-- Page header with live clock -->
        <div class="page-head">
            <div>
                <h1>Dashboard</h1>
                <div class="sub">Overview of clinic activity</div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="clock"><span class="time" id="clock">--:--</span><br><span id="clock-date"></span></div>
                <a href="appointments?book=1" class="btn btn-teal">+ Book Appointment</a>
                <a href="patients" class="btn btn-dark-navy">+ New Patient</a>
            </div>
        </div>

        <!-- ===== Clinic announcements (dentists & staff; the admin writes them
             on the Announcements page, so they are not repeated here).
             Same card as the patient portal, with Hide / Show. ===== -->
        <?php
        $annList = current_role() === 'admin' ? [] : $pdo->query(
            "SELECT id, title, content, created_at FROM announcements
             WHERE status='Published' ORDER BY created_at DESC LIMIT 10"
        )->fetchAll();
        $annSeeAll = '';
        include 'includes/announcements_card.php';
        ?>

        <!-- row-match: the appointments card is exactly as tall as the calendar; its list scrolls inside -->
        <div class="row row-match">
            <!-- ===== Today's appointments table ===== -->
            <div class="col-lg-8">
                <div class="card-box">
                    <div class="flex-between mb-2">
                        <h5 class="mb-0" id="day-title">Today's Appointments</h5>
                        <a href="appointments" class="btn btn-sm btn-outline-teal">View All →</a>
                    </div>
                    <div class="match-scroll">
                        <table class="data">
                            <thead><tr><th>Patient</th><th>Date</th><th>Time</th><th>Treatment</th><th>Status</th></tr></thead>
                            <tbody id="day-rows">
                            <?php if (empty($appts)): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center;padding:34px 12px;color:#8aa0a0;">
                                        <div style="font-size:2.2rem;margin-bottom:6px;">📭</div>
                                        No appointments scheduled for today.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($appts as $a): ?>
                                    <tr>
                                        <td><strong><?= e($a['patient_name']) ?></strong></td>
                                        <td><?= e($a['appointment_date']) ?></td>
                                        <td class="date-blue"><?= e($a['appointment_time']) ?></td>
                                        <td><?= e($a['treatment']) ?></td>
                                        <td><span class="badge-pill b-<?= strtolower($a['status']) ?>"><?= e(status_label($a['status'])) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ===== Mini calendar ===== -->
            <div class="col-lg-4">
                <div class="card-box">
                    <h5>Calendar</h5>
                    <div id="calendar"></div>
                    <div class="text-muted2 mt-2" style="font-size:.75rem;">Click a date to see its appointments. <span class="cal-dot" style="position:static;display:inline-block;transform:none;vertical-align:middle;"></span> = has appointments</div>
                </div>
            </div>
        </div>

        <div class="row row-match">
            <!-- ===== Recent patients ===== -->
            <div class="col-lg-6">
                <div class="card-box">
                    <h5>Recent Patients</h5>
                    <?php foreach ($recent as $i => $p): ?>
                        <div class="flex-between py-2 border-bottom">
                            <div class="d-flex align-items-center gap-2">
                                <span class="avatar" style="background:<?= $avatarColors[$i % 5] ?>"><?= strtoupper(substr($p['name'],0,1)) ?></span>
                                <div>
                                    <?php // Clicking the name opens their treatment record (clinical: admin and dentists);
                                          // front-desk staff, who have no Records access, get the patient's row instead.
                                          $recentLink = in_array($role, ['admin','dentist'], true)
                                              ? 'records?patient=' . (int)$p['id'] . '&tab=treatments'
                                              : 'patients?q=' . urlencode($p['name']); ?>
                                    <a href="<?= e($recentLink) ?>" class="recent-name" title="Open <?= e($p['name']) ?>’s <?= $role === 'staff' ? 'details' : 'treatment record' ?>"><strong><?= e($p['name']) ?></strong></a><br>
                                    <small class="text-muted2">Age <?= !empty($p['age']) ? e($p['age']) : 'N/A' ?> · Last visit <?= e($p['last_visit'] ?: 'N/A') ?></small>
                                </div>
                            </div>
                            <span class="badge-pill b-<?= strtolower($p['status']) ?>"><?= e(status_label($p['status'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ===== Treatment breakdown ===== -->
            <div class="col-lg-6">
                <div class="card-box">
                    <h5>Treatment Breakdown</h5>
                    <div class="match-scroll" style="padding-right:6px;">
                    <?php
                    $barColors = ['#3b82f6','#22c55e','#ec4899','#14b8a6','#f59e0b'];
                    foreach ($breakdown as $i => $b):
                        $pct = round(($b['cnt'] / $maxCnt) * 100);
                    ?>
                        <div class="mb-3">
                            <div class="flex-between"><span><?= e($b['treatment']) ?></span><small class="text-muted2"><?= $b['cnt'] ?> cases</small></div>
                            <div class="bar" style="height:8px;background:#eef2f5;border-radius:5px;margin-top:4px;">
                                <span style="display:block;height:100%;border-radius:5px;width:<?= $pct ?>%;background:<?= $barColors[$i % 5] ?>"></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php if ($showWelcomePopup): ?>
<?php
    $roleBlurb = [
        'admin'   => 'Manage the clinic, staff and patients from your dashboard.',
        'dentist' => 'Here\'s an overview of your patients and today\'s schedule.',
        'staff'   => 'Here\'s an overview of today\'s appointments and patients.',
    ][$role] ?? 'Here\'s an overview of the clinic.';
?>
<div class="modal fade" id="welcomeModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;overflow:hidden;">
      <div class="modal-body text-center py-4 px-4">
        <div style="width:64px;height:64px;background:#eef7f6;border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:1.8rem;">🦷</div>
        <h4 style="color:var(--teal-dark);"><?= $justRegistered ? 'Welcome' : 'Welcome back' ?>, <?= e($firstName) ?>!</h4>
        <p class="text-muted2 mb-4"><?= e($roleBlurb) ?></p>
        <button type="button" class="btn btn-teal px-4" data-bs-dismiss="modal">OK</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($showWelcomePopup): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php endif; ?>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
<script>
    startClock();          // live clock (top right)
    // ---- Calendar <-> appointments table ----
    // Clicking a date loads that day's appointments into the table on the left;
    // days that have appointments get a small dot.
    var DASH_TODAY = <?= json_encode(date('Y-m-d')) ?>;
    function dashEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    window.calendarPick = function (ds) {
        var title = document.getElementById('day-title'), rows = document.getElementById('day-rows');
        var nice = new Date(ds + 'T00:00:00').toLocaleDateString('en-US', {weekday:'short', month:'short', day:'numeric', year:'numeric'});
        title.textContent = ds === DASH_TODAY ? "Today's Appointments" : 'Appointments · ' + nice;
        rows.innerHTML = '<tr><td colspan="5" class="text-center text-muted2 py-4">Loading…</td></tr>';
        fetch('dashboard?day=' + ds, {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (list) {
            if (!list.length) {
                rows.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:34px 12px;color:#8aa0a0;">'
                    + '<div style="font-size:2.2rem;margin-bottom:6px;">📭</div>No appointments on ' + dashEsc(nice) + '.</td></tr>';
                return;
            }
            rows.innerHTML = list.map(function (a) {
                return '<tr><td><strong>' + dashEsc(a.patient_name) + '</strong></td><td>' + dashEsc(a.appointment_date) + '</td>'
                     + '<td class="date-blue">' + dashEsc(a.appointment_time) + '</td><td>' + dashEsc(a.treatment) + '</td>'
                     + '<td><span class="badge-pill b-' + dashEsc(String(a.status).toLowerCase()) + '">' + dashEsc(a.status === 'Confirmed' ? 'Approved' : a.status) + '</span></td></tr>';
            }).join('');
        }).catch(function () { rows.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">Could not load that day. Please try again.</td></tr>'; });
    };
    window.calendarMonthLoaded = function (year, month) {
        var ym = year + '-' + String(month + 1).padStart(2, '0');
        fetch('dashboard?month=' + ym, {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (counts) {
            Object.keys(counts).forEach(function (ds) {
                var td = document.querySelector('#calendar td[data-date="' + ds + '"]');
                if (td) { td.classList.add('has-appts'); td.title = counts[ds] + ' appointment' + (counts[ds] > 1 ? 's' : ''); }
            });
        }).catch(function () {});
    };
    buildCalendar();       // month calendar (js/app.js)
    <?php if ($showWelcomePopup): ?>
    document.addEventListener('DOMContentLoaded', function () {
        new bootstrap.Modal(document.getElementById('welcomeModal')).show();
    });
    <?php endif; ?>
</script>
</body>
</html>
