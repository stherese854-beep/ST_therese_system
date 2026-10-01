<?php
// ============================================================
//  MY DENTAL CARE  (includes/dental_care.php)  — patient portal, My Records
// ============================================================
//  Replaces "Dental Summary + Treatment History", which told the patient
//  the same thing twice. Two parts:
//
//   1. WHAT YOU NEED TO DO — a short note of things to act on:
//        teeth that need attention (latest chart), treatments the dentist
//        PLANNED, and the recommended next visit (plain notes, no buttons).
//   2. MY VISITS — one card per visit day, everything from that day once:
//        what was done (treatment + tooth in plain words + status),
//        what changed on the chart, the dentist's note for the patient,
//        and aftercare tips for completed treatments.
//
//  Clinic-only notes (treatments.clinic_notes) are never shown here.
// ============================================================
require_once __DIR__ . '/dental_summary.php';      // tooth_name(), TOOTH_PLAIN, chart visibility, copy requests
require_once __DIR__ . '/treatments.php';          // clinic_treatments()
require_once __DIR__ . '/followups.php';           // follow-up plans (braces, root canal sessions, check-ups)

// Built-in aftercare tips, matched on the treatment's name.
function aftercare_tip($treatment) {
    $t = strtolower((string)$treatment);
    $tips = [
        'extract'  => 'After an extraction: bite on gauze for 30–45 minutes, do not spit or use a straw for 24 hours, eat soft cool food, and avoid smoking.',
        'root'     => 'After a root canal: the tooth may feel tender for a few days. Avoid chewing hard food on it until it is fully restored with a crown or filling.',
        'fill'     => 'After a filling: wait until the numbness wears off before eating, and avoid very hard or sticky food on that side for 24 hours.',
        'crown'    => 'After a crown: avoid sticky and very hard food, and brush and floss around it like a normal tooth.',
        'clean'    => 'After a cleaning: your gums may feel a little sore for a day. Keep brushing twice a day and floss daily.',
        'brace'    => 'With braces: brush after every meal, avoid hard and sticky food, and keep your adjustment visits.',
        'orthod'   => 'With braces: brush after every meal, avoid hard and sticky food, and keep your adjustment visits.',
        'whiten'   => 'After whitening: avoid coffee, tea, red wine and smoking for 48 hours so the color lasts.',
        'implant'  => 'After an implant: eat soft food for a few days, keep the area clean, and do not smoke while it heals.',
    ];
    foreach ($tips as $k => $tip) if (strpos($t, $k) !== false) return $tip;
    return '';
}

// Which clinic service fits a tooth problem / a planned treatment (for the Book button).
function booking_treatment_for($text) {
    $t = strtolower((string)$text);
    $map = ['decay' => 'Dental Filling', 'fill' => 'Dental Filling', 'cavity' => 'Dental Filling', 'extract' => 'Tooth Extraction',
            'impact' => 'Tooth Extraction', 'root' => 'Root Canal', 'crown' => 'Dental Crown', 'fractur' => 'Dental Crown',
            'clean' => 'Cleaning', 'brace' => 'Braces / Orthodontics', 'orthod' => 'Braces / Orthodontics'];
    foreach ($map as $k => $svc) if (strpos($t, $k) !== false && isset(clinic_treatments()[$svc])) return $svc;
    return 'Consultation';
}

function tooth_label($tooth) {
    $tooth = trim((string)$tooth);
    if ($tooth === '') return '';
    $parts = array_filter(array_map('trim', preg_split('/[,\s]+/', $tooth)));
    $out = [];
    foreach ($parts as $p) $out[] = preg_match('/^[1-4][1-8]$/', $p) ? "Tooth $p (" . tooth_name($p) . ")" : "Tooth $p";
    return implode(', ', $out);
}

/**
 * The whole My Records "dental care" block for one patient. $treatments = their (non-archived) rows.
 *
 * The tooth details (teeth needing attention, what changed on the chart, printed copy)
 * only appear when the clinic has turned the dental chart OFF for patients
 * (System → Patient Portal). When it is ON, the patient already sees every tooth on
 * "My Dental Chart", so My Records only shows treatments, notes and the next visit.
 */
function my_dental_care_html($pdo, $patient, array $treatments) {
    global $UPPER_TEETH, $LOWER_TEETH;
    $pid = (int)$patient['id'];
    $chartOn  = patient_chart_visible($pdo);
    $sessions = $chartOn ? [] : get_chart_sessions($pdo, $pid);   // tooth details only when the chart is hidden
    $hasChart = $chartOn ? (bool)latest_session_id($pdo, $pid) : (bool)$sessions;

    // ---------- 1. What you need to do ----------
    $todo = [];
    if ($sessions) {
        $map = build_tooth_map($pdo, $pid, (int)$sessions[0]['id']);
        foreach (array_merge($UPPER_TEETH, $LOWER_TEETH) as $t) {
            $st = $map[$t] ?? 'Healthy';
            if (in_array($st, ['Decayed', 'Fractured', 'Impacted'], true)) {
                $todo[] = ['⚠️', "Tooth $t — " . tooth_name($t) . ' ' . (TOOTH_PLAIN[$st] ?? strtolower($st)), booking_treatment_for($st)];
            }
        }
    }
    foreach ($treatments as $tr) {
        if (in_array($tr['status'], ['Planned', 'In Progress'], true)) {
            $what = $tr['treatment_name'] . ($tr['tooth'] ? ' — ' . tooth_label($tr['tooth']) : '');
            $todo[] = [$tr['status'] === 'Planned' ? '🗓' : '⏳',
                       ($tr['status'] === 'Planned' ? 'Planned: ' : 'Not finished yet: ') . $what
                       . ($tr['notes'] ? ' · “' . $tr['notes'] . '”' : ''), booking_treatment_for($tr['treatment_name'])];
        }
    }
    // Follow-up plans: "Braces adjustment — session 6 of 24 done — next visit due Oct 15".
    $booked = next_booked_appointment($pdo, $pid);
    $planDue = [];
    foreach (active_plans($pdo, $pid) as $pl) {
        if (!$pl['next_due']) continue;
        $planDue[] = $pl['next_due'];
        $late = $pl['next_due'] < date('Y-m-d');
        $todo[] = ['🔁', $pl['treatment_name'] . ($pl['tooth'] ? ' (' . tooth_label($pl['tooth']) . ')' : '') . ' — '
                  . followup_session_text($pl) . ' — next visit ' . ($late ? 'was due ' : 'due ') . date('l, F j, Y', strtotime($pl['next_due']))
                  . ($booked ? ' · booked for ' . date('M j', strtotime($booked['appointment_date'])) . ' at ' . $booked['appointment_time']
                             : ($late ? ' · overdue — please book or call the clinic' : ' · not booked yet')), ''];
    }
    $next = (!empty($patient['next_visit']) && $patient['next_visit'] >= date('Y-m-d')
             && !in_array($patient['next_visit'], $planDue, true)) ? $patient['next_visit'] : null;   // not twice
    $req  = open_chart_request($pdo, $pid);

    // ---------- 2. Visits (one card per day) ----------
    $days = [];
    foreach ($treatments as $tr) $days[$tr['treatment_date']]['treat'][] = $tr;
    foreach ($sessions as $i => $s) {
        $prev = $sessions[$i + 1] ?? null;                 // the visit before this one
        $now  = build_tooth_map($pdo, $pid, (int)$s['id']);
        $was  = $prev ? build_tooth_map($pdo, $pid, (int)$prev['id']) : [];
        $chg  = [];
        foreach (array_merge($UPPER_TEETH, $LOWER_TEETH) as $t) {
            $a = $was[$t] ?? 'Healthy'; $b = $now[$t] ?? 'Healthy';
            if ($prev ? $a !== $b : $b !== 'Healthy') $chg[] = [$t, $prev ? $a : null, $b];
        }
        $days[$s['visit_date']]['chart'][] = ['s' => $s, 'chg' => $chg, 'first' => !$prev];
    }
    krsort($days);

    ob_start(); ?>
    <style>
    .dc-todo { background: #fff8e8; border-left: 4px solid var(--gold); border-radius: 8px; padding: 6px 14px; }   /* reads as a note */
    .dc-todo li { padding: 7px 0; border-bottom: 1px dashed #ecdcb8; font-size: .95rem; color: #3f5350; }
    .dc-todo li:last-child { border-bottom: 0; }
    .dc-todo-foot { font-size: .84rem; color: #6b7b8c; margin-top: 8px; }
    .dc-visit { border-left: 4px solid var(--teal-light); padding: 4px 0 4px 16px; margin-bottom: 22px; position: relative; }
    .dc-visit::before { content: ''; position: absolute; left: -8px; top: 6px; width: 12px; height: 12px; border-radius: 50%; background: var(--teal-mid); }
    .dc-date { font-weight: 700; font-size: 1.02rem; }
    .dc-item { background: #f7fafa; border-radius: 10px; padding: 10px 12px; margin-top: 8px; }
    .dc-note { background: #fff8e8; border-left: 3px solid var(--gold); border-radius: 6px; padding: 8px 10px; margin-top: 6px; font-size: .92rem; }
    .dc-tip  { background: #eef7f6; border-radius: 6px; padding: 8px 10px; margin-top: 6px; font-size: .88rem; color: #3f5350; }
    .dc-chg  { font-size: .9rem; padding: 2px 0; }
    </style>

    <!-- ===== 1. What you need to do ===== -->
    <div class="card-box mb-3" data-keep-text>
        <h5 class="mb-1">✅ What you need to do</h5>
        <?php if (!$todo && !$next): ?>
            <div class="ds-good mt-2">Nothing right now. 🎉 Keep brushing twice a day, floss daily, and visit for a check-up every 6 months.</div>
        <?php else: ?>
            <ul class="dc-todo list-unstyled mb-0 mt-2">
                <?php if ($next): ?>
                    <li>📅 Your dentist recommends your next visit on <b><?= date('l, F j, Y', strtotime($next)) ?></b>.</li>
                <?php endif; ?>
                <?php foreach ($todo as [$ic, $txt, $svc]): ?>
                    <li><?= $ic ?> <?= e($txt) ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="dc-todo-foot">To book, use <b>Book Appointment</b> or call the clinic.</div>
        <?php endif; ?>
        <div class="ds-actions">
            <?php if ($chartOn): ?>
                <?php if ($hasChart): ?><a href="portal?view=chart" class="btn btn-sm btn-light">🦷 See and print my dental chart →</a><?php endif; ?>
            <?php elseif ($req): ?>
                <span class="badge-pill b-pending">📄 Printed copy of your dental chart requested <?= date('M j', strtotime($req['created_at'])) ?> — the clinic will let you know when it is ready</span>
            <?php elseif ($sessions): ?>
                <form method="POST" class="m-0"><input type="hidden" name="action" value="request_chart_copy"><input type="hidden" name="member_id" value="0">
                    <button class="btn btn-sm btn-light">📄 Request a printed copy of my dental chart</button></form>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== 2. My visits ===== -->
    <div class="card-box">
        <h5 class="mb-1">🦷 My Visits</h5>
        <div class="text-muted2 mb-3" style="font-size:.85rem;">Everything from each visit in one place — newest first.</div>
        <?= bulk_bar('bulk-treatment', 'archive_item', 'records', ['item_type' => 'treatment'], '🗑 Delete selected', 'They will be moved to My Archive, where you can restore them.') ?>
        <?php foreach ($days as $date => $d):
            $dentists = array_unique(array_filter(array_merge(array_column($d['treat'] ?? [], 'dentist'),
                                                               array_map(fn($c) => $c['s']['created_by'], $d['chart'] ?? [])))); ?>
            <div class="dc-visit">
                <div class="dc-date"><?= date('l, F j, Y', strtotime($date)) ?></div>
                <?php if ($dentists): ?><div class="text-muted2" style="font-size:.85rem;">with <?= e(implode(', ', $dentists)) ?></div><?php endif; ?>

                <?php foreach ($d['treat'] ?? [] as $tr):
                    $tip = $tr['status'] === 'Completed' ? aftercare_tip($tr['treatment_name']) : ''; ?>
                    <div class="dc-item">
                        <div class="flex-between flex-wrap gap-2">
                            <div><b>🛠 <?= e(ucfirst($tr['treatment_name'])) ?></b><?= $tr['tooth'] ? ' — ' . e(tooth_label($tr['tooth'])) : '' ?></div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge-pill b-<?= $tr['status']==='Completed'?'completed':($tr['status']==='Planned'?'pending':'progress') ?>"><?= e($tr['status']) ?></span>
                                <?= archive_button('treatment', $tr['id']) ?>
                            </div>
                        </div>
                        <?php if (trim((string)$tr['notes']) !== ''): ?>
                            <div class="dc-note">💬 <b>Note from your dentist:</b> <?= nl2br(e($tr['notes'])) ?></div>
                        <?php endif; ?>
                        <?php if ($tip): ?><div class="dc-tip">💡 <?= e($tip) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($d['chart'] ?? [] as $c): ?>
                    <div class="dc-item">
                        <b>🦷 Dental chart <?= $c['first'] ? '(first check-up)' : 'updated' ?></b>
                        <?php if (!$c['chg']): ?>
                            <div class="dc-chg text-muted2"><?= $c['first'] ? 'All teeth healthy.' : 'No changes since the last visit.' ?></div>
                        <?php endif; ?>
                        <?php foreach ($c['chg'] as [$t, $a, $b]):
                            $good = in_array($a, ['Decayed','Fractured','Impacted'], true) && !in_array($b, ['Decayed','Fractured','Impacted'], true); ?>
                            <div class="dc-chg"><?= $good ? '✅' : (in_array($b, ['Decayed','Fractured','Impacted'], true) ? '⚠️' : '•') ?>
                                <b>Tooth <?= e($t) ?></b> (<?= e(tooth_name($t)) ?>)
                                <?= $a ? e(strtolower($a)) . ' → ' : '' ?><?= e(TOOTH_PLAIN[$b] ?? ($b === 'Healthy' ? 'is healthy again' : strtolower($b))) ?></div>
                        <?php endforeach; ?>
                        <?php if (trim((string)$c['s']['notes']) !== ''): ?>
                            <div class="dc-note">💬 <b>Visit note:</b> <?= nl2br(e($c['s']['notes'])) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php if (!$days): ?>
            <p class="text-muted2 text-center py-3 mb-0">No visits recorded yet. They appear here after your first visit at the clinic.</p>
        <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}
