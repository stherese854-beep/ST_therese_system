<?php
// ============================================================
//  WALK-IN VISITS  (includes/walkins.php)
// ============================================================
//  A patient who simply comes in (e.g. for a check-up) is NOT an
//  appointment: nobody booked, nothing to approve, nothing to miss.
//  The clinic fills one short form — the patient (or a new one: name,
//  phone), the health questionnaire and the service — and it is saved
//  here as a visit.
//
//  "Did this patient visit?" is answered by clinic_visits_sql(): the
//  completed appointments PLUS these walk-ins. Last visit, New /
//  Returning, Records, Reports, Analytics, the Dashboard and the
//  patient's Account History all use it. The Appointments pages and
//  the no-show / cancel limits stay appointment-only.
// ============================================================

function ensure_walkin_table($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS walk_in_visits (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            patient_id   INT          NOT NULL,
            patient_name VARCHAR(100) DEFAULT NULL,
            visit_date   DATE         NOT NULL,
            visit_time   VARCHAR(20)  DEFAULT NULL,
            dentist      VARCHAR(100) DEFAULT NULL,
            services     VARCHAR(255) DEFAULT NULL,
            health_form  TEXT         DEFAULT NULL,
            notes        TEXT         DEFAULT NULL,
            created_by   VARCHAR(100) DEFAULT NULL,
            created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
            KEY (patient_id), KEY (visit_date)
        )");
    } catch (Throwable $e) { /* ignore */ }
}

// Every real visit, one row each: completed appointments + walk-ins.
// Columns: patient_id, visit_date, visit_time, dentist, services, source ('appointment' | 'walk-in'), ref_id
function clinic_visits_sql() {
    return "(SELECT patient_id, appointment_date AS visit_date, appointment_time AS visit_time, dentist,
                    treatment AS services, 'appointment' AS source, id AS ref_id
               FROM appointments WHERE status = 'Completed'
             UNION ALL
             SELECT patient_id, visit_date, visit_time, dentist, services, 'walk-in' AS source, id AS ref_id
               FROM walk_in_visits)";
}

function walkins_for_patients($pdo, array $ids) {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    try {
        $q = $pdo->prepare("SELECT * FROM walk_in_visits WHERE patient_id IN (" . in_placeholders($ids) . ")
                             ORDER BY visit_date DESC, id DESC");
        $q->execute($ids);
        return $q->fetchAll();
    } catch (Throwable $e) { return []; }
}

// Today's walk-ins (a dentist sees their own).
function walkins_today($pdo, $dentist = '') {
    try {
        $sql = "SELECT * FROM walk_in_visits WHERE visit_date = CURDATE()"; $prm = [];
        if ($dentist !== '') { $sql .= " AND dentist = ?"; $prm[] = $dentist; }
        $q = $pdo->prepare($sql . " ORDER BY id DESC"); $q->execute($prm);
        return $q->fetchAll();
    } catch (Throwable $e) { return []; }
}

// Patients the signed-in person may record a walk-in for (a dentist: their own patients).
function walkin_patients($pdo) {
    $sql = "SELECT p.id, p.name, p.phone, p.date_of_birth, p.age, p.primary_dentist FROM patients p
              LEFT JOIN users u ON u.id = p.user_id
             WHERE p.status <> 'Archived' AND (u.id IS NULL OR u.role = 'patient')";
    $prm = [];
    if (current_role() === 'dentist') { $sql .= " AND " . dentist_patient_sql('p.id', 'p.primary_dentist', $_SESSION['name'] ?? '', $prm); }
    $st = $pdo->prepare($sql . " ORDER BY p.name"); $st->execute($prm);
    return $st->fetchAll();
}

// ---- The "+ Walk-in" form (a pop-up). $assets: also print the questionnaire /
//      treatment-list styles (false on a page that already prints them). ----
function walkin_form($pdo, $assets = true) {
    require_once __DIR__ . '/health_form.php';
    require_once __DIR__ . '/treatments.php';
    require_once __DIR__ . '/followups.php';
    $isDentist = current_role() === 'dentist';
    $pts = walkin_patients($pdo);
    // Active follow-up plans of these patients (braces adjustments, root canal sessions…):
    // a walk-in for the same kind of treatment counts as the plan's next session.
    $wiPlans = [];
    $today = date('Y-m-d');
    if ($pts) {
        try {
            $ids = array_map(fn($p) => (int)$p['id'], $pts);
            $pq = $pdo->prepare("SELECT * FROM treatment_plans WHERE status = 'Active' AND patient_id IN (" . in_placeholders($ids) . ") ORDER BY next_due");
            $pq->execute($ids);
            foreach ($pq->fetchAll() as $pl) {
                $wiPlans[(int)$pl['patient_id']][] = [
                    'id' => (int)$pl['id'], 'name' => $pl['treatment_name'], 'fam' => treatment_family($pl['treatment_name']),
                    'next' => (int)$pl['sessions_done'] + 1, 'total' => $pl['total_sessions'] ? (int)$pl['total_sessions'] : null,
                    'due' => $pl['next_due'] ? ($pl['next_due'] < $today ? 'was due ' : 'due ') . date('M j', strtotime($pl['next_due'])) : '',
                    'today' => (string)$pl['last_session_date'] === $today,
                ];
            }
        } catch (Throwable $e) {}
    }
    $svcFam = [];
    foreach (array_keys(clinic_treatments()) as $sv) $svcFam[$sv] = treatment_family($sv);
    $health = [];
    foreach ($pts as $p) { [$a] = patient_health($pdo, $p['id']); if ($a) $health[(int)$p['id']] = $a; }
    $dentists = $pdo->query("SELECT name FROM users WHERE role = 'dentist' AND status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    ob_start(); ?>
<?php if ($assets) { echo health_form_styles(); echo treatment_picker_assets(); } ?>
<div id="wi-modal" class="wi-modal" hidden role="dialog" aria-modal="true" aria-labelledby="wi-title">
  <form method="POST" action="walkin" class="wi-box" onsubmit="return wiCheck()">
    <input type="hidden" name="action" value="walkin">
    <input type="hidden" name="back" id="wi-back">
    <div class="flex-between mb-2">
      <h5 class="mb-0" id="wi-title">Walk-in visit</h5>
      <button type="button" class="btn-close" aria-label="Close" onclick="wiClose()"></button>
    </div>
    <p class="text-muted2 mb-3" style="font-size:.85rem;">For a patient who is here now (no appointment). It is saved as today's visit.</p>

    <div class="field-label">1. Patient</div>
    <div class="d-flex gap-3 mb-2" style="font-size:.9rem;">
      <label><input type="radio" name="patient_mode" value="existing" checked onchange="wiMode()"> Existing patient</label>
      <label><input type="radio" name="patient_mode" value="new" onchange="wiMode()"> New patient</label>
    </div>
    <div id="wi-existing" class="mb-3">
      <!-- One box: click it to see the list, type to narrow it (see includes/head.php) -->
      <select name="patient_id" id="wi-patient" class="form-select" data-search="Search name or phone…" data-search-combo onchange="wiPick()">
        <option value="" hidden>Search name or phone…</option>
        <?php $dupNames = same_name_keys($pts);
              foreach ($pts as $p): ?>
          <option value="<?= (int)$p['id'] ?>" data-dentist="<?= e($p['primary_dentist'] ?? '') ?>"><?= e(patient_option_text($p, $dupNames, true)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div id="wi-new" class="row g-2 mb-3" style="display:none;">
      <div class="col-6"><input name="first_name" class="form-control" placeholder="First name"></div>
      <div class="col-6"><input name="last_name" class="form-control" placeholder="Last name"></div>
      <div class="col-6"><input name="phone" class="form-control" placeholder="Phone 09XX XXX XXXX" <?= phone_input_attrs() ?>></div>
      <div class="col-6"><input type="date" name="dob" class="form-control" title="Birthday (optional)" min="1900-01-01" max="<?= birth_date_max() ?>"></div>
      <div class="col-12"><small class="text-muted2">Birthday is optional, but needed later to give them their own online account.</small></div>
    </div>

    <div class="field-label">2. Health questionnaire</div>
    <div class="text-muted2 mb-1" id="wi-hf-note" style="font-size:.8rem;"></div>
    <div id="wi-hf" class="mb-3"><?= health_form_fields([], true) ?></div>

    <div class="field-label">3. Today's visit</div>
    <div class="mb-2"><?= treatment_picker([], 'wi') ?></div>
    <!-- 🔁 The patient has a follow-up plan: count this visit as its next session -->
    <div id="wi-plans" class="mb-2" style="display:none;background:#eef7f6;border-radius:8px;padding:8px 12px;"></div>
    <?php if ($isDentist): ?>
      <input type="hidden" name="dentist" value="<?= e($_SESSION['name'] ?? '') ?>">
      <div class="text-muted2 mb-2" style="font-size:.85rem;">Dentist: <b><?= e($_SESSION['name'] ?? '') ?></b> (you)</div>
    <?php else: ?>
      <select name="dentist" id="wi-dentist" class="form-select mb-2" required>
        <option value="">Dentist who sees them…</option>
        <?php foreach ($dentists as $d): ?><option value="<?= e($d) ?>"><?= e($d) ?></option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <textarea name="notes" class="form-control mb-2" rows="2" placeholder="Note (optional)"></textarea>
    <div id="wi-warn" class="alert alert-danger py-2 mb-2" style="display:none;font-size:.85rem;"></div>
    <div class="d-flex justify-content-end gap-2">
      <button type="button" class="btn btn-light" data-keep-text onclick="wiClose()">Cancel</button>
      <button class="btn btn-teal" data-keep-text>Save</button>
    </div>
  </form>
</div>
<style>
.wi-modal { position: fixed; inset: 0; z-index: 100000; background: rgba(15,30,30,.5); display: flex; align-items: flex-start; justify-content: center; padding: 24px 12px; overflow-y: auto; }
.wi-modal[hidden] { display: none; }
.wi-box { background: #fff; border-radius: 14px; padding: 18px 20px; width: 100%; max-width: 620px; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
</style>
<script>
var WI_HEALTH = <?= json_encode((object)$health, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
var WI_PLANS = <?= json_encode((object)$wiPlans, JSON_HEX_TAG | JSON_HEX_APOS) ?>, WI_SVC_FAM = <?= json_encode($svcFam, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
var wiPlanSet = {}, wiPlanFor = '';
function wiEsc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
// Ticked when the service chosen is the same kind as the plan (e.g. Braces / Orthodontics);
// the person entering it can tick or untick it.
function wiPlans() {
    var box = document.getElementById('wi-plans'), pid = wiIsNew() ? '' : document.getElementById('wi-patient').value;
    if (pid !== wiPlanFor) { wiPlanSet = {}; wiPlanFor = pid; }
    var plans = (pid && WI_PLANS[pid]) || [];
    if (!plans.length) { box.style.display = 'none'; box.innerHTML = ''; return; }
    var fams = {};
    [].forEach.call(document.querySelectorAll('#wi-modal input[name="treatments[]"]:checked'), function (c) { fams[WI_SVC_FAM[c.value] || c.value.toLowerCase()] = 1; });
    box.innerHTML = '<div class="field-label mb-1">🔁 Follow-up plan</div>' + plans.map(function (p) {
        var on = !p.today && (p.id in wiPlanSet ? wiPlanSet[p.id] : !!fams[p.fam]);
        return '<label class="d-flex align-items-start gap-2 py-1" style="cursor:pointer;font-size:.88rem;">'
             + '<input type="checkbox" class="form-check-input mt-1" name="plan_sessions[]" value="' + p.id + '"' + (on ? ' checked' : '') + (p.today ? ' disabled' : '')
             + ' onchange="wiPlanSet[' + p.id + '] = this.checked">'
             + '<span>Count this visit as <b>session ' + p.next + (p.total ? ' of ' + p.total : '') + '</b> of <b>' + wiEsc(p.name) + '</b>'
             + (p.today ? ' <span class="text-muted2">(already counted today)</span>' : (p.due ? ' <span class="text-muted2">(' + wiEsc(p.due) + ')</span>' : ''))
             + '</span></label>';
    }).join('');
    box.style.display = '';
}
document.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'treatments[]' && e.target.closest && e.target.closest('#wi-modal')) wiPlans();
});
function wiOpen() { document.getElementById('wi-back').value = location.pathname.replace(/^.*\//, '') + location.search;
                    document.getElementById('wi-modal').hidden = false; document.body.style.overflow = 'hidden'; wiMode(); }
function wiClose() { document.getElementById('wi-modal').hidden = true; document.body.style.overflow = ''; }
function wiIsNew() { return document.querySelector('#wi-modal [name="patient_mode"]:checked').value === 'new'; }
function wiMode() {
    var isNew = wiIsNew();
    document.getElementById('wi-existing').style.display = isNew ? 'none' : '';
    document.getElementById('wi-new').style.display = isNew ? '' : 'none';
    wiPick();
}
function wiPick() {
    var sel = document.getElementById('wi-patient'), pid = wiIsNew() ? '' : sel.value, a = (pid && WI_HEALTH[pid]) || null;
    hfFill(document.getElementById('wi-hf'), a || {});
    document.getElementById('wi-hf-note').textContent = wiIsNew() ? 'Fill it in with the patient.'
        : (a ? 'Their last answers are filled in — review them with the patient.' : (pid ? 'No questionnaire on file yet — fill it in with the patient.' : 'Choose the patient first.'));
    var d = document.getElementById('wi-dentist'), o = sel.selectedOptions[0];
    if (d && o && o.dataset.dentist && !wiIsNew()) d.value = o.dataset.dentist;
    wiPlans();
}
function wiCheck() {
    var f = document.querySelector('#wi-modal form'), w = document.getElementById('wi-warn'), msg = '';
    if (wiIsNew()) {
        if (!f.first_name.value.trim() || !f.last_name.value.trim()) msg = 'Please enter the first and last name.';
        else if (!f.phone.value.trim()) msg = 'Please enter a phone number.';
    } else if (!document.getElementById('wi-patient').value) msg = 'Please choose the patient.';
    if (!msg && !f.querySelector('input[name="treatments[]"]:checked')) msg = 'Please choose the service done today.';
    if (!msg && document.getElementById('wi-dentist') && !document.getElementById('wi-dentist').value) msg = 'Please choose the dentist.';
    w.textContent = msg; w.style.display = msg ? 'block' : 'none';
    if (msg) return false;
    if (typeof hfMark === 'function' && hfMark(document.getElementById('wi-hf'))) {   // returns the first unanswered question
        w.textContent = 'Please answer the health questions marked in red.'; w.style.display = 'block'; return false;
    }
    return true;
}
</script>
<?php return ob_get_clean();
}
