<?php
// ============================================================
//  HEALTH QUESTIONNAIRE  (includes/health_form.php)
// ============================================================
//  The clinic's medical history form, filled in by the patient when
//  booking online (it replaces the old "Reason / Notes" fields).
//  The answers are stored as JSON on the appointment
//  (appointments.health_form) and shown to the admin and dentist on
//  the Appointments page and in Records. Front-desk staff do not see
//  it — it is health data.
//
//    health_form_fields($prefill)  -> the form (booking wizard, step 2)
//    health_form_from_post($post)  -> [answers, error message]
//    health_form_view($answers)    -> read-only HTML
//    health_form_flags($answers)   -> short list of things to watch for
// ============================================================

const HEALTH_CONDITIONS = [
    'Cardiovascular disease', 'Pacemaker, artificial heart valve', 'High blood pressure',
    'Blood disease, anemia', 'Bleeding disorder', 'Diabetes', 'Rheumatoid arthritis',
    'Rheumatic fever', 'Joint prosthesis, vascular prosthesis', 'Thyroid disease',
    'Lung disease, asthma', 'Stomach ulcer', 'Kidney disorders', 'Liver disease, hepatitis',
    'HIV infection', 'Epilepsy', 'Recurrent headache', 'Psychiatric disease',
];

// Yes/No questions: key => [question, label for the "which / how many" box, show box when]
const HEALTH_YESNO = [
    'anesthesia' => ['Have you had serious troubles caused by local anesthesia?', 'Yes, which?', 'yes'],
    'allergy'    => ['Are you allergic to any medicine or other stuff (e.g. penicillin, aspirin, rubber, any foodstuff)?', 'Yes, which?', 'yes'],
    'smoke'      => ['Do you smoke regularly?', 'Yes, how many (per day)?', 'yes'],
    'good_health'=> ['Are you in good health?', 'If not, please tell us more', 'no'],
];

function health_form_fields($p = [], $byStaff = false) {
    $v   = fn($k) => e($p[$k] ?? '');
    $chk = fn($k, $val) => (($p[$k] ?? '') === $val) ? 'checked' : '';
    ob_start(); ?>
    <div class="hf">
      <div class="hf-title">🩺 Health Questionnaire <span class="text-muted2">— helps the dentist treat you safely</span></div>

      <div class="hf-q">
        <div class="hf-label">Are you pregnant? *</div>
        <label><input type="radio" name="hf[pregnant]" value="yes" <?= $chk('pregnant','yes') ?> required onchange="hfToggle()"> Yes</label>
        <label><input type="radio" name="hf[pregnant]" value="no"  <?= $chk('pregnant','no') ?> onchange="hfToggle()"> No</label>
        <label><input type="radio" name="hf[pregnant]" value="na"  <?= $chk('pregnant','na') ?> onchange="hfToggle()"> Not applicable</label>
        <div class="hf-more" data-when="pregnant=yes">
          <span>Estimated date of delivery</span>
          <input type="date" name="hf[due_date]" class="form-control form-control-sm" value="<?= $v('due_date') ?>">
        </div>
      </div>

      <?php foreach (HEALTH_YESNO as $k => [$q, $more, $when]): ?>
      <div class="hf-q">
        <div class="hf-label"><?= e($q) ?> *</div>
        <label><input type="radio" name="hf[<?= $k ?>]" value="<?= $k === 'good_health' ? 'yes' : 'no' ?>" <?= $chk($k, $k === 'good_health' ? 'yes' : 'no') ?> required onchange="hfToggle()"> <?= $k === 'good_health' ? 'Yes' : 'No' ?></label>
        <label><input type="radio" name="hf[<?= $k ?>]" value="<?= $k === 'good_health' ? 'no' : 'yes' ?>" <?= $chk($k, $k === 'good_health' ? 'no' : 'yes') ?> onchange="hfToggle()"> <?= $k === 'good_health' ? 'No' : 'Yes' ?></label>
        <div class="hf-more" data-when="<?= $k ?>=<?= $when ?>">
          <span><?= e($more) ?></span>
          <input type="text" name="hf[<?= $k ?>_detail]" class="form-control form-control-sm" maxlength="200" value="<?= $v($k . '_detail') ?>">
        </div>
      </div>
      <?php endforeach; ?>

      <div class="hf-q">
        <div class="hf-label">Please tick the diseases or symptoms you have or have had</div>
        <div class="hf-grid">
          <?php foreach (HEALTH_CONDITIONS as $i => $c): ?>
            <label><input type="checkbox" name="hf[conditions][]" value="<?= e($c) ?>" <?= in_array($c, $p['conditions'] ?? [], true) ? 'checked' : '' ?>> <?= e($c) ?></label>
          <?php endforeach; ?>
        </div>
        <div class="hf-inline">
          <span>Other general disease, which?</span>
          <input type="text" name="hf[other_disease]" class="form-control form-control-sm" maxlength="200" value="<?= $v('other_disease') ?>">
        </div>
      </div>

      <div class="hf-q">
        <div class="hf-label">Regular medication</div>
        <label><input type="checkbox" name="hf[no_medication]" value="1" <?= !empty($p['no_medication']) ? 'checked' : '' ?> onchange="hfToggle()"> No regular medication</label>
        <div class="hf-more" data-when="no_medication=">
          <span>Which medicines do you take regularly?</span>
          <input type="text" name="hf[medication]" class="form-control form-control-sm" maxlength="300" value="<?= $v('medication') ?>">
        </div>
      </div>

      <div class="hf-q hf-two">
        <div><div class="hf-label">Attending physician <span class="text-muted2">(if any)</span></div>
          <input type="text" name="hf[physician]" class="form-control form-control-sm" maxlength="120" value="<?= $v('physician') ?>"></div>
        <div><div class="hf-label">Further information</div>
          <input type="text" name="hf[further]" class="form-control form-control-sm" maxlength="300" value="<?= $v('further') ?>"
                 placeholder="e.g. toothache on the lower right, anything the dentist should know"></div>
      </div>

      <div class="hf-privacy">
        <b>Data privacy:</b> Under the Data Privacy Act of 2012 (Republic Act No. 10173), your personal and health
        information is kept confidential in our patient records and used only for your dental care. You may ask
        the clinic to see or correct your information at any time.
      </div>

      <div class="hf-q">
        <div class="hf-label">Do you permit us to give information about your care to the dental or other health care provider you are referred to? *</div>
        <label><input type="radio" name="hf[share_consent]" value="yes" <?= $chk('share_consent','yes') ?> required> Yes</label>
        <label><input type="radio" name="hf[share_consent]" value="no"  <?= $chk('share_consent','no') ?>> No</label>
      </div>

      <label class="hf-confirm"><input type="checkbox" name="hf[confirm]" value="1" required>
        <?= $byStaff ? 'The patient confirmed that these answers are true and complete. *'
                     : 'I confirm that the information above is true and complete to the best of my knowledge. *' ?></label>
    </div>
    <?php return ob_get_clean();
}

// Reads and checks the posted answers. Returns [answers, error].
function health_form_from_post($post) {
    $in  = is_array($post['hf'] ?? null) ? $post['hf'] : [];
    $txt = fn($k, $n = 200) => mb_substr(trim((string)($in[$k] ?? '')), 0, $n);
    $pick = fn($k, $ok) => in_array($in[$k] ?? '', $ok, true) ? $in[$k] : '';

    $a = [
        'pregnant'      => $pick('pregnant', ['yes','no','na']),
        'due_date'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['due_date'] ?? '') ? $in['due_date'] : '',
        'conditions'    => array_values(array_intersect(HEALTH_CONDITIONS, (array)($in['conditions'] ?? []))),
        'other_disease' => $txt('other_disease'),
        'no_medication' => !empty($in['no_medication']),
        'medication'    => $txt('medication', 300),
        'physician'     => $txt('physician', 120),
        'further'       => $txt('further', 300),
        'share_consent' => $pick('share_consent', ['yes','no']),
        'filled_at'     => date('Y-m-d H:i'),
    ];
    foreach (array_keys(HEALTH_YESNO) as $k) {
        $a[$k] = $pick($k, ['yes','no']);
        $a[$k . '_detail'] = $txt($k . '_detail');
    }
    if ($a['pregnant'] !== 'yes') $a['due_date'] = '';
    if ($a['no_medication']) $a['medication'] = '';

    if ($a['pregnant'] === '' || $a['anesthesia'] === '' || $a['allergy'] === '' || $a['smoke'] === ''
        || $a['good_health'] === '' || $a['share_consent'] === '') {
        return [$a, 'Please answer every question marked * in the health questionnaire.'];
    }
    if ($a['allergy'] === 'yes' && $a['allergy_detail'] === '') {
        return [$a, 'Please tell us what you are allergic to.'];
    }
    if ($a['anesthesia'] === 'yes' && $a['anesthesia_detail'] === '') {
        return [$a, 'Please tell us what trouble you had with local anesthesia.'];
    }
    if (empty($in['confirm'])) {
        return [$a, 'Please confirm that your health information is true and complete.'];
    }
    return [$a, ''];
}

// ---- The patient's record keeps their latest answers ----
// Saved from every booking (online or by the clinic). If something is
// flagged and the patient's Medical Alert is still empty, the flags fill it,
// so they also show on reports. An alert typed by staff is never replaced.
function save_patient_health($pdo, $patientId, $answers) {
    $patientId = (int)$patientId;
    if ($patientId <= 0 || !$answers) return;
    try {
        $pdo->prepare("UPDATE patients SET health_form = ?, health_form_at = NOW() WHERE id = ?")
            ->execute([json_encode($answers, JSON_UNESCAPED_UNICODE), $patientId]);
        $flags = health_form_flags($answers);
        if ($flags) {
            $pdo->prepare("UPDATE patients SET medical_alert = ? WHERE id = ? AND (medical_alert IS NULL OR TRIM(medical_alert) = '')")
                ->execute([mb_substr(implode(' · ', $flags), 0, 250), $patientId]);
        }
    } catch (Throwable $e) { /* columns not added yet */ }
}

// [answers, when] — the patient's record first, else their latest booking.
function patient_health($pdo, $patientId) {
    try {
        $st = $pdo->prepare("SELECT health_form, health_form_at FROM patients WHERE id = ?");
        $st->execute([(int)$patientId]);
        $r = $st->fetch();
        if ($r && $r['health_form']) return [json_decode($r['health_form'], true) ?: [], $r['health_form_at']];
        $st = $pdo->prepare("SELECT health_form, created_at FROM appointments WHERE patient_id = ? AND health_form IS NOT NULL
                              ORDER BY created_at DESC, id DESC LIMIT 1");
        $st->execute([(int)$patientId]);
        $r = $st->fetch();
        if ($r) return [json_decode($r['health_form'], true) ?: [], $r['created_at']];
    } catch (Throwable $e) {}
    return [[], null];
}

// Things the dentist should notice at a glance.
function health_form_flags($a) {
    if (!$a) return [];
    $f = [];
    if (($a['allergy'] ?? '') === 'yes')    $f[] = 'Allergy: ' . ($a['allergy_detail'] ?: 'yes');
    if (($a['anesthesia'] ?? '') === 'yes') $f[] = 'Anesthesia trouble: ' . ($a['anesthesia_detail'] ?: 'yes');
    if (($a['pregnant'] ?? '') === 'yes')   $f[] = 'Pregnant' . (!empty($a['due_date']) ? ' (due ' . date('M j, Y', strtotime($a['due_date'])) . ')' : '');
    foreach (($a['conditions'] ?? []) as $c) $f[] = $c;
    if (!empty($a['other_disease']))        $f[] = $a['other_disease'];
    return $f;
}

// Read-only view for the admin / dentist.
function health_form_view($a) {
    if (!$a) return '<div class="text-muted2">No health questionnaire on file.</div>';
    $yn = fn($v) => $v === 'yes' ? 'Yes' : ($v === 'no' ? 'No' : ($v === 'na' ? 'Not applicable' : '—'));
    $row = fn($q, $ans) => '<tr><th>' . e($q) . '</th><td>' . $ans . '</td></tr>';
    $flags = health_form_flags($a);
    $h  = $flags ? '<div class="alert py-2 mb-2" style="background:#fdecec;border:1px solid #f5b5b5;color:#9b2c2c;font-size:.85rem;">⚠️ '
                   . e(implode(' · ', $flags)) . '</div>' : '<div class="alert alert-success py-2 mb-2" style="font-size:.85rem;">✅ Nothing flagged.</div>';
    $h .= '<table class="table table-sm hf-view" style="font-size:.85rem;">';
    $h .= $row('Pregnant', e($yn($a['pregnant'] ?? '')) . (!empty($a['due_date']) ? ' · due ' . e(date('M j, Y', strtotime($a['due_date']))) : ''));
    foreach (HEALTH_YESNO as $k => [$q]) {
        $d = trim((string)($a[$k . '_detail'] ?? ''));
        $h .= $row($q, e($yn($a[$k] ?? '')) . ($d !== '' ? ' — ' . e($d) : ''));
    }
    $conds = array_merge($a['conditions'] ?? [], !empty($a['other_disease']) ? ['Other: ' . $a['other_disease']] : []);
    $h .= $row('Diseases / symptoms', $conds ? e(implode(', ', $conds)) : 'None ticked');
    $h .= $row('Regular medication', !empty($a['no_medication']) ? 'No regular medication' : e($a['medication'] ?: '—'));
    $h .= $row('Attending physician', e($a['physician'] ?: '—'));
    $h .= $row('Further information', e($a['further'] ?: '—'));
    $h .= $row('May share info with other providers', e($yn($a['share_consent'] ?? '')));
    $h .= $row('Filled in', e(!empty($a['filled_at']) ? date('M j, Y g:i A', strtotime($a['filled_at'])) : '—'));
    return $h . '</table>';
}

// Styles for the form (included once, next to the form).
function health_form_styles() { return <<<CSS
<style>
.hf { border: 1px solid #e3e9ee; border-radius: 12px; padding: 14px 16px; margin: 6px 0 14px; background: #fbfdfd; }
.hf-title { font-weight: 700; margin-bottom: 8px; }
.hf-q { padding: 9px 0; border-top: 1px solid #eef2f5; font-size: .9rem; }
.hf-q:first-of-type { border-top: 0; }
.hf-label { font-weight: 600; color: #34434c; margin-bottom: 4px; }
.hf-q > label { margin-right: 16px; cursor: pointer; }
.hf-more, .hf-inline { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
.hf-more span, .hf-inline span { font-size: .82rem; color: #52606b; white-space: nowrap; }
.hf-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 3px 16px; }
.hf-grid label { cursor: pointer; font-size: .86rem; }
.hf-two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.hf-privacy { font-size: .76rem; color: #52606b; background: #f1f5f7; border-radius: 8px; padding: 8px 10px; margin: 8px 0; }
.hf-confirm { display: block; font-size: .88rem; font-weight: 600; margin-top: 8px; cursor: pointer; }
@media (max-width: 640px) { .hf-grid, .hf-two { grid-template-columns: 1fr; } .hf-more, .hf-inline { flex-wrap: wrap; } }
.hf-view th { width: 46%; font-weight: 600; color: #52606b; }
</style>
<script>
// Show the "which? / how many?" boxes only when they apply.
function hfToggle() {
    document.querySelectorAll('.hf-more[data-when]').forEach(function (box) {
        var parts = box.dataset.when.split('='), name = 'hf[' + parts[0] + ']', want = parts[1];
        var el = document.querySelector('[name="' + name + '"]:checked');
        var val = el ? (el.type === 'checkbox' ? el.value : el.value) : '';
        box.style.display = (val === want) ? '' : 'none';
    });
}
document.addEventListener('DOMContentLoaded', hfToggle);

// Fill the form inside `root` with saved answers ({} clears it).
function hfFill(root, a) {
    a = a || {};
    root.querySelectorAll('input').forEach(function (el) {
        var m = (el.name || '').match(/^hf\[([a-z_]+)\](\[\])?$/);
        if (!m) return;
        var k = m[1];
        if (el.type === 'radio')         el.checked = (a[k] === el.value);
        else if (el.type === 'checkbox') el.checked = m[2] ? (a[k] || []).indexOf(el.value) !== -1 : (k === 'confirm' ? false : !!a[k]);
        else                             el.value = a[k] || '';
    });
    hfToggle();
}
</script>
CSS; }
