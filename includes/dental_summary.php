<?php
// ============================================================
//  DENTAL SUMMARY + CHART COPY REQUESTS  (includes/dental_summary.php)
// ============================================================
//  For the patient portal:
//   - patient_chart_visible(): System → "Show the dental chart to patients"
//     (on by default). When off, patients do not see the odontogram.
//   - A plain-words summary of their teeth from the latest visit, e.g.
//     "Needs attention: Tooth 14 (upper left first premolar) — Decayed".
//     Shown in My Records whether the chart is on or off.
//   - "Request a printed copy": the clinic gets a bell notice, prints it
//     from Generate Reports → Patient Profile, and marks it done; the
//     patient then gets a pop-up that it is ready.
// ============================================================
require_once __DIR__ . '/teeth.php';

function patient_chart_visible($pdo) {
    static $v = null;
    if ($v === null) {
        try { $v = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'patient_chart_visible'")->fetchColumn(); }
        catch (Throwable $e) { $v = false; }
        $v = ($v === false || $v === null || $v === '') ? true : $v === '1';
    }
    return $v;
}

// FDI tooth number -> plain words. 1 = upper right, 2 = upper left, 3 = lower left, 4 = lower right.
function tooth_name($n) {
    $n = (string)$n;
    $side  = ['1' => 'upper right', '2' => 'upper left', '3' => 'lower left', '4' => 'lower right'][$n[0] ?? ''] ?? '';
    $kind  = ['1' => 'front tooth (central incisor)', '2' => 'front tooth (lateral incisor)', '3' => 'canine (eye tooth)',
              '4' => 'first premolar', '5' => 'second premolar', '6' => 'first molar', '7' => 'second molar',
              '8' => 'wisdom tooth'][$n[1] ?? ''] ?? 'tooth';
    return trim("$side $kind");
}

// What each condition means, for patients.
const TOOTH_PLAIN = [
    'Decayed'   => 'has a cavity (tooth decay)',
    'Fractured' => 'is cracked or chipped',
    'Impacted'  => 'is stuck under the gum',
    'Filled'    => 'has a filling',
    'Crowned'   => 'has a crown (cap)',
    'Missing'   => 'is missing',
    'Extracted' => 'was removed',
];

/** [latest visit row or null, groups ['attention'=>[], 'treated'=>[], 'missing'=>[]] of [tooth, status]] */
function dental_summary_data($pdo, $patientId) {
    global $UPPER_TEETH, $LOWER_TEETH;
    $sessions = get_chart_sessions($pdo, $patientId);          // newest first
    $latest = $sessions[0] ?? null;
    $groups = ['attention' => [], 'treated' => [], 'missing' => []];
    if (!$latest) return [null, $groups];
    $map = build_tooth_map($pdo, $patientId, (int)$latest['id']);
    foreach (array_merge($UPPER_TEETH, $LOWER_TEETH) as $t) {
        $st = $map[$t] ?? 'Healthy';
        if (in_array($st, ['Decayed', 'Fractured', 'Impacted'], true)) $groups['attention'][] = [$t, $st];
        elseif (in_array($st, ['Filled', 'Crowned'], true))           $groups['treated'][]   = [$t, $st];
        elseif (in_array($st, ['Missing', 'Extracted'], true))        $groups['missing'][]   = [$t, $st];
    }
    return [$latest, $groups];
}

// ---------- Printed copy requests ----------
function ensure_chart_requests_table($pdo) {
    static $done = false;
    if ($done || (defined('SCHEMA_CHECKED') && SCHEMA_CHECKED)) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS chart_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INT NOT NULL,
            holder_id INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            done_at DATETIME DEFAULT NULL,
            done_by VARCHAR(100) DEFAULT NULL,
            INDEX idx_open (done_at)
        )");
    } catch (Throwable $e) {}
}

function open_chart_request($pdo, $patientId) {
    ensure_chart_requests_table($pdo);
    try {
        $q = $pdo->prepare("SELECT * FROM chart_requests WHERE patient_id = ? AND done_at IS NULL ORDER BY id DESC LIMIT 1");
        $q->execute([(int)$patientId]);
        return $q->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

/** The summary card (HTML). $memberId = 0 for the account holder, else the booked-for person's id. */
function dental_summary_card($pdo, $patientId, $name, $memberId = 0, $compact = false) {
    [$latest, $g] = dental_summary_data($pdo, $patientId);
    $who   = $memberId ? $name : 'You';
    $req   = open_chart_request($pdo, $patientId);
    ob_start(); ?>
    <div class="ds-card <?= $compact ? 'ds-compact' : 'card-box mb-3' ?>" data-keep-text>
        <?php if (!$compact): ?>
            <h5 class="mb-1">🦷 <?= $memberId ? e($name) . '’s Dental Summary' : 'My Dental Summary' ?></h5>
        <?php else: ?>
            <div class="field-label mb-1">🦷 Dental summary</div>
        <?php endif; ?>
        <?php if (!$latest): ?>
            <p class="text-muted2 mb-2" style="font-size:.9rem;">No dental chart recorded yet — the dentist records it at the first visit.</p>
        <?php else: ?>
            <div class="text-muted2 mb-2" style="font-size:.85rem;">From the check-up on <b><?= date('F j, Y', strtotime($latest['visit_date'])) ?></b><?= $latest['created_by'] ? ' · ' . e($latest['created_by']) : '' ?></div>
            <?php if (!$g['attention'] && !$g['treated'] && !$g['missing']): ?>
                <div class="ds-row ds-good">✅ All teeth were healthy at the last check-up.</div>
            <?php endif; ?>
            <?php foreach ([['attention', '⚠️ Needs attention', 'ds-bad'], ['treated', '🛠 Treated', 'ds-mid'], ['missing', '➖ Missing / removed', 'ds-muted']] as [$k, $label, $cls]):
                if (!$g[$k]) continue; ?>
                <div class="ds-group <?= $cls ?>">
                    <div class="ds-head"><?= $label ?> <span>(<?= count($g[$k]) ?>)</span></div>
                    <?php foreach ($g[$k] as [$t, $st]): ?>
                        <div class="ds-row"><b>Tooth <?= e($t) ?></b> — <?= e(tooth_name($t)) ?> <?= e(TOOTH_PLAIN[$st] ?? strtolower($st)) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($g['attention']): ?>
                <div class="text-muted2" style="font-size:.82rem;">Please book a visit so the dentist can take care of the teeth that need attention.</div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="ds-actions">
            <?php if ($req): ?>
                <span class="badge-pill b-pending">📄 Printed copy requested <?= date('M j', strtotime($req['created_at'])) ?> — the clinic will let you know when it is ready</span>
            <?php else: ?>
                <form method="POST" class="m-0">
                    <input type="hidden" name="action" value="request_chart_copy">
                    <input type="hidden" name="member_id" value="<?= (int)$memberId ?>">
                    <button class="btn btn-sm btn-outline-teal">📄 Request a printed copy from the clinic</button>
                </form>
            <?php endif; ?>
            <?php if (patient_chart_visible($pdo)): ?>
                <a href="portal?view=chart<?= $memberId ? '&member=' . (int)$memberId : '' ?>" class="btn btn-sm btn-light">See the full dental chart →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php return ob_get_clean();
}

function dental_summary_styles() {
    return '<style>
    .ds-group { border-left: 4px solid; border-radius: 8px; padding: 8px 12px; margin-bottom: 10px; background: #f7fafa; }
    .ds-bad   { border-color: #c0392b; } .ds-bad .ds-head { color: #c0392b; }
    .ds-mid   { border-color: #3b9ae0; } .ds-mid .ds-head { color: #2a6fa8; }
    .ds-muted { border-color: #9aa9b0; } .ds-muted .ds-head { color: #5c6c74; }
    .ds-head  { font-weight: 700; font-size: .9rem; margin-bottom: 4px; } .ds-head span { font-weight: 400; color: #8aa0a0; }
    .ds-row   { font-size: .9rem; padding: 2px 0; color: #3f5350; }
    .ds-good  { background: #e8f7ef; color: #1f8a54; border-radius: 8px; padding: 8px 12px; font-weight: 600; margin-bottom: 10px; }
    .ds-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 10px; }
    .ds-compact { background: #f7fafa; border-radius: 10px; padding: 10px 12px; margin-top: 12px; }
    </style>';
}
