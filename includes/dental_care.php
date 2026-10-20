<?php
// ============================================================
//  MY DENTAL CARE  (includes/dental_care.php)  — patient portal, My Records
// ============================================================
//  MY VISITS — one card per visit day, everything from that day once:
//    what was done (treatment + tooth in plain words + status + time, and
//    which follow-up session it was, e.g. "Braces — session 2 of 10"),
//    what changed on the chart, the dentist's note for the patient, and
//    aftercare tips for completed treatments.
//  (The "What you need to do" card was removed: it repeated My Visits.)
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

// "Tue, Oct 20, 2026 · 5:29 PM" — the time only when the record was saved on that day.
function treatment_when($tr) {
    $when = date('D, M j, Y', strtotime($tr['treatment_date']));
    $at = (string)($tr['created_at'] ?? '');
    if ($at !== '' && substr($at, 0, 10) === $tr['treatment_date']) $when .= ' · ' . date('g:i A', strtotime($at));
    return $when;
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
 * $forMember = true: the account holder is viewing a person they book for (People I Book For),
 * so the wording says "their / Juan's", links point to that person, and nothing can be deleted.
 *
 * The tooth details (teeth needing attention, what changed on the chart, printed copy)
 * only appear when the clinic has turned the dental chart OFF for patients
 * (System → Patient Portal). When it is ON, the patient already sees every tooth on
 * "My Dental Chart", so My Records only shows treatments, notes and the next visit.
 */
function my_dental_care_html($pdo, $patient, array $treatments, $forMember = false) {
    global $UPPER_TEETH, $LOWER_TEETH;
    $pid = (int)$patient['id'];
    $first = trim(strtok((string)($patient['name'] ?? ''), ' ')) ?: 'This person';
    $whose = $forMember ? $first . '’s' : 'your';                                  // "Juan’s" / "your"
    $chartOn  = patient_chart_visible($pdo);
    $sessions = $chartOn ? [] : get_chart_sessions($pdo, $pid);   // tooth details only when the chart is hidden

    $req  = $chartOn ? null : open_chart_request($pdo, $pid);   // a printed chart asked for (chart hidden)

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
    // Walk-in visits (came in without an appointment) are visits too.
    require_once __DIR__ . '/walkins.php';
    foreach (walkins_for_patients($pdo, [$pid]) as $w) $days[$w['visit_date']]['walkin'][] = $w;

    // Which follow-up session each treatment was ("Braces — session 2 of 10"): the
    // sessions of that day, of the same kind, in order. A session counted at a walk-in
    // with no treatment record shows on its own, in place of the walk-in line.
    $sessFor = [];
    $sessByDate = [];
    foreach (plans_with_history($pdo, $pid) as $pl) {
        foreach ($pl['history'] as $h) $sessByDate[$h['session_date']][] = ['plan' => $pl, 'h' => $h];
    }
    foreach ($sessByDate as $date => $list) {
        usort($list, fn($x, $y) => (int)$x['h']['session_no'] <=> (int)$y['h']['session_no']);
        $byId = $days[$date]['treat'] ?? [];
        usort($byId, fn($x, $y) => (int)$x['id'] <=> (int)$y['id']);
        $used = [];
        foreach ($byId as $tr) {
            $fam = treatment_family($tr['treatment_name']);
            foreach ([false, true] as $walkInToo) {           // a treatment's own session first, then a walk-in's
                foreach ($list as $k => $x) {
                    if (isset($used[$k]) || (($x['h']['source'] === 'walk-in') !== $walkInToo)) continue;
                    if (treatment_family($x['plan']['treatment_name']) !== $fam && treatment_family((string)$x['h']['label']) !== $fam) continue;
                    $used[$k] = true; $sessFor[(int)$tr['id']] = $x; continue 3;
                }
            }
        }
        foreach ($list as $k => $x) if (!isset($used[$k]) && $x['h']['source'] === 'walk-in') $days[$date]['plansess'][] = $x;
    }
    // Newest first inside a day too.
    foreach ($days as &$dd) {
        if (!empty($dd['treat'])) usort($dd['treat'], fn($x, $y) => strcmp((string)$y['created_at'], (string)$x['created_at']) ?: (int)$y['id'] <=> (int)$x['id']);
    }
    unset($dd);
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
    .dc-sess { font-size: .9rem; color: #0f5e57; margin-top: 4px; }
    .dc-plan { background: #f7fafa; border-radius: 10px; padding: 10px 12px; margin-top: 8px; }
    .dc-plan-hist { list-style: none; margin: 8px 0 0; padding: 0 0 0 12px; border-left: 3px solid var(--teal-light); }
    .dc-plan-hist li { font-size: .9rem; padding: 3px 0; }
    </style>

    <!-- ===== My visits ===== -->
    <div class="card-box">
        <h5 class="mb-1">🦷 <?= $forMember ? e($whose) . ' Visits' : 'My Visits' ?></h5>
        <div class="text-muted2 mb-3" style="font-size:.85rem;">Everything from each visit in one place — newest first.</div>
        <?php if (!$chartOn && ($req || $sessions)):   /* the clinic hides the dental chart: a printed copy on request */ ?>
            <div class="mb-3">
                <?php if ($req): ?>
                    <span class="badge-pill b-pending">📄 Printed copy of <?= e($whose) ?> dental chart requested <?= date('M j', strtotime($req['created_at'])) ?> — the clinic will let you know when it is ready</span>
                <?php else: ?>
                    <form method="POST" class="m-0"><input type="hidden" name="action" value="request_chart_copy">
                        <input type="hidden" name="member_id" value="<?= $forMember ? $pid : 0 ?>"><input type="hidden" name="back" value="records">
                        <button class="btn btn-sm btn-light" data-keep-text>📄 Request a printed copy of <?= $forMember ? e($whose) : 'my' ?> dental chart</button></form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (!$forMember): /* a booked-for person's records are view only */ ?>
        <?= bulk_bar('bulk-treatment', 'archive_item', 'records', ['item_type' => 'treatment'], '🗑 Delete selected', 'They will be moved to My Archive, where you can restore them.') ?>
        <?php endif; ?>
        <?php foreach ($days as $date => $d):
            $dentists = array_unique(array_filter(array_merge(array_column($d['treat'] ?? [], 'dentist'),
                                                               array_column($d['walkin'] ?? [], 'dentist'),
                                                               array_map(fn($c) => $c['s']['created_by'], $d['chart'] ?? [])))); ?>
            <div class="dc-visit">
                <div class="dc-date"><?= date('l, F j, Y', strtotime($date)) ?></div>
                <?php if ($dentists): ?><div class="text-muted2" style="font-size:.85rem;">with <?= e(implode(', ', $dentists)) ?></div><?php endif; ?>

                <?php if (empty($d['treat'])):        /* the treatment records already say what was done */
                    $left = $d['plansess'] ?? [];
                    foreach ($d['walkin'] ?? [] as $w):
                        $match = null;                    // a session counted at this walk-in
                        foreach ($left as $k => $x) if (treatment_family($x['plan']['treatment_name']) === treatment_family($w['services'])) { $match = $x; unset($left[$k]); break; } ?>
                    <?php if ($match): ?>
                        <div class="dc-item"><b>🔁 <?= e($match['plan']['treatment_name']) ?></b> — session <?= (int)$match['h']['session_no'] ?><?= $match['plan']['total_sessions'] ? ' of ' . (int)$match['plan']['total_sessions'] : '' ?>
                            <span class="text-muted2" style="font-size:.85rem;">· <?= date('D, M j, Y', strtotime($date)) ?><?= $w['visit_time'] ? ' · ' . e(ltrim($w['visit_time'], '0')) : '' ?></span></div>
                    <?php else: ?>
                        <div class="dc-item"><b>🏥 Walk-in visit</b> — <?= e($w['services']) ?><?= $w['visit_time'] ? ' <span class="text-muted2">(' . e($w['visit_time']) . ')</span>' : '' ?></div>
                    <?php endif; ?>
                <?php endforeach; endif; ?>
                <?php foreach ($d['treat'] ?? [] as $tr):
                    $tip = $tr['status'] === 'Completed' ? aftercare_tip($tr['treatment_name']) : ''; ?>
                    <div class="dc-item">
                        <div class="flex-between flex-wrap gap-2">
                            <div><b>🛠 <?= e(ucfirst($tr['treatment_name'])) ?></b><?= $tr['tooth'] ? ' — ' . e(tooth_label($tr['tooth'])) : '' ?>
                                <span class="text-muted2" style="font-size:.85rem;">· <?= e(treatment_when($tr)) ?></span></div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge-pill b-<?= $tr['status']==='Completed'?'completed':($tr['status']==='Planned'?'pending':'progress') ?>"><?= e($tr['status']) ?></span>
                                <?= $forMember ? '' : archive_button('treatment', $tr['id']) ?>
                            </div>
                        </div>
                        <?php if ($sx = $sessFor[(int)$tr['id']] ?? null): ?>
                            <div class="dc-sess">🔁 <b><?= e($sx['plan']['treatment_name']) ?></b> — session <?= (int)$sx['h']['session_no'] ?><?= $sx['plan']['total_sessions'] ? ' of ' . (int)$sx['plan']['total_sessions'] : '' ?></div>
                        <?php endif; ?>
                        <?php if (trim((string)$tr['notes']) !== ''): ?>
                            <div class="dc-note">💬 <b>Note from <?= $forMember ? 'the' : 'your' ?> dentist:</b> <?= nl2br(e($tr['notes'])) ?></div>
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
            <p class="text-muted2 text-center py-3 mb-0">No visits recorded yet. They appear here after <?= e($whose) ?> first visit at the clinic.</p>
        <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}
