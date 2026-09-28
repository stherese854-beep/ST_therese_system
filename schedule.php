<?php
// ============================================================
//  MY SCHEDULE / AVAILABILITY  (schedule.php)
// ============================================================
//  Lets a DENTIST disable specific days (days off / unavailable),
//  so the system knows they won't take appointments on that day.
//  Admins can also open this page and pick which dentist to manage.
//
//  Days off are stored in the `dentist_daysoff` table.
// ============================================================
require_once 'config/auth.php';
require_login(['admin','dentist']);

$role = current_role();

// ---------- Which dentist are we managing? ----------
if ($role === 'admin') {
    // Admin can choose any dentist from a dropdown.
    $allDentists = $pdo->query("SELECT name FROM users WHERE role='dentist' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $selectedDentist = $_GET['dentist'] ?? ($allDentists[0] ?? '');
} else {
    // A dentist manages their own schedule only.
    $selectedDentist = $_SESSION['name'] ?? '';
}

// ---------- Add / remove a day off ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_dayoff') {
        // A dentist can only mark THEIR OWN days off, whatever the form posts.
        $dentist = ($role === 'admin') ? ($_POST['dentist_name'] ?? '') : $selectedDentist;
        $date    = $_POST['off_date'];
        $reason  = trim($_POST['reason'] ?? '');

        if ($dentist && $date) {
            // Don't add the same date twice for the same dentist.
            $check = $pdo->prepare("SELECT id FROM dentist_daysoff WHERE dentist_name=? AND off_date=?");
            $check->execute([$dentist, $date]);
            if (!$check->fetch()) {
                $pdo->prepare("INSERT INTO dentist_daysoff (dentist_name, off_date, reason) VALUES (?,?,?)")
                    ->execute([$dentist, $date, $reason]);
                log_activity($pdo, 'Marked day off', $dentist . ' — ' . date('M j, Y', strtotime($date)) . ($reason !== '' ? ' (' . $reason . ')' : ''));
                set_flash(date('M j, Y', strtotime($date)) . ' marked as unavailable.');
            } else {
                set_flash('That day is already marked as unavailable.', 'info');
            }
        }
        header("Location: schedule" . ($role === 'admin' ? "?dentist=".urlencode($dentist) : "")); exit;
    }

    if ($action === 'remove_dayoff') {
        // A dentist may only remove their OWN days off; admin may remove any.
        if ($role === 'admin') {
            $pdo->prepare("DELETE FROM dentist_daysoff WHERE id=?")->execute([$_POST['id']]);
            log_activity($pdo, 'Removed day off', '#' . (int)$_POST['id']);
            set_flash('Day off removed.', 'info');
        } else {
            $del = $pdo->prepare("DELETE FROM dentist_daysoff WHERE id=? AND dentist_name=?");
            $del->execute([$_POST['id'], $selectedDentist]);
            if ($del->rowCount()) log_activity($pdo, 'Removed day off', $selectedDentist);
            set_flash($del->rowCount() ? 'Day off removed.' : 'That day off is not yours to remove.',
                      $del->rowCount() ? 'info' : 'error');
        }
        header("Location: schedule" . ($role === 'admin' && isset($_POST['back']) ? "?dentist=".urlencode($_POST['back']) : "")); exit;
    }
}

// ---------- Load this dentist's days off (today onward) ----------
$daysOff = [];
if ($selectedDentist !== '') {
    $stmt = $pdo->prepare("SELECT * FROM dentist_daysoff WHERE dentist_name=? ORDER BY off_date ASC");
    $stmt->execute([$selectedDentist]);
    $daysOff = $stmt->fetchAll();
}

$page_title = "My Schedule";
include 'includes/head.php';
$active = 'schedule';
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div>
                <h1>My Schedule</h1>
                <div class="sub">Mark the days you are unavailable — the clinic won't book you on those days.</div>
            </div>
            <div class="clock"><span class="time" id="clock"></span><br><span id="clock-date"></span></div>
        </div>

        <?php if ($role === 'admin'): ?>
            <!-- Admin picks which dentist's schedule to manage -->
            <form method="GET" class="card-box d-flex align-items-center gap-2 mb-3" style="padding:14px;">
                <label class="field-label mb-0">Managing schedule for:</label>
                <select name="dentist" class="form-select" style="max-width:280px;" onchange="this.form.submit()">
                    <?php foreach ($allDentists as $dn): ?>
                        <option value="<?= e($dn) ?>" <?= $dn===$selectedDentist?'selected':'' ?>><?= e($dn) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($allDentists)): ?><span class="text-muted2">No dentists yet.</span><?php endif; ?>
            </form>
        <?php endif; ?>

        <div class="row g-3">
            <!-- Add a day off -->
            <div class="col-lg-5">
                <div class="card-box">
                    <h5 class="mb-3">🚫 Disable a Day</h5>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_dayoff">
                        <input type="hidden" name="dentist_name" value="<?= e($selectedDentist) ?>">

                        <label class="field-label">Date to disable</label>
                        <input type="date" name="off_date" class="form-control mb-3" min="<?= date('Y-m-d') ?>" required>

                        <label class="field-label">Reason (optional)</label>
                        <input type="text" name="reason" class="form-control mb-3" placeholder="e.g. On leave, Seminar, Personal">

                        <button class="btn btn-teal w-100" <?= $selectedDentist===''?'disabled':'' ?>>+ Mark as Unavailable</button>
                    </form>
                    <div class="text-muted2 mt-2" style="font-size:.8rem;">
                        Tip: patients booking with <strong><?= e($selectedDentist ?: 'this dentist') ?></strong> will be blocked from choosing these dates.
                    </div>
                </div>
            </div>

            <!-- List of days off -->
            <div class="col-lg-7">
                <div class="card-box">
                    <h5 class="mb-3">📅 Your Unavailable Days</h5>
                    <div class="table-responsive">
                        <table class="data">
                            <thead><tr><th>Date</th><th>Day</th><th>Reason</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($daysOff as $d):
                                $isPast = ($d['off_date'] < date('Y-m-d'));
                            ?>
                                <tr style="<?= $isPast ? 'opacity:.5;' : '' ?>">
                                    <td><strong><?= date('M j, Y', strtotime($d['off_date'])) ?></strong></td>
                                    <td><?= date('l', strtotime($d['off_date'])) ?></td>
                                    <td><?= $d['reason'] ? e($d['reason']) : '<span class="text-muted2">—</span>' ?></td>
                                    <td>
                                        <form method="POST" onsubmit="return confirm('Remove this day off?')">
                                            <input type="hidden" name="action" value="remove_dayoff">
                                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                            <input type="hidden" name="back" value="<?= e($selectedDentist) ?>">
                                            <button class="btn btn-sm" style="background:#fbdcdc;color:#c0392b;">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($daysOff)): ?>
                                <tr><td colspan="4" class="text-center text-muted2 py-3">No days off set. You're available every day.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
<script>startClock();</script>
</body>
</html>
