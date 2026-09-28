<?php
// ============================================================
//  TREATMENTS + TIME SLOTS  (includes/treatments.php)
// ============================================================
//  Shared by the patient's online booking (book.php) and the clinic's
//  "+ Book" form on the Appointments page, so both always offer the
//  same services and the same times.
// ============================================================

// Each treatment with a plain-language explanation for patients.
function clinic_treatments() {
    return [
        'Consultation'          => 'check-up and advice from the dentist',
        'Cleaning'              => 'removing plaque and tartar to keep teeth and gums healthy',
        'Dental Filling'        => 'filling a small hole or cavity in a tooth',
        'Tooth Extraction'      => 'pulling out a damaged or painful tooth',
        'Root Canal'            => 'cleaning an infected tooth from the inside to save it',
        'Dental Crown'          => 'a cap placed over a weak or broken tooth',
        'Braces / Orthodontics' => 'straightening crooked teeth',
    ];
}

// Every 30 minutes from opening to closing time (Settings > clinic hours).
function clinic_time_slots($pdo) {
    $open = '09:00'; $close = '17:00';
    try {
        foreach ($pdo->query("SELECT setting_key, setting_value FROM settings
                               WHERE setting_key IN ('clinic_open_time','clinic_close_time')") as $r) {
            if ($r['setting_key'] === 'clinic_open_time'  && $r['setting_value']) $open  = $r['setting_value'];
            if ($r['setting_key'] === 'clinic_close_time' && $r['setting_value']) $close = $r['setting_value'];
        }
    } catch (Throwable $e) {}
    $slots = [];
    $t = strtotime($open); $end = strtotime($close);
    while ($t < $end) { $slots[] = date('h:i A', $t); $t += 30 * 60; }
    return $slots ?: ['09:00 AM'];
}

// ---- Preferred treatments: tick 1 to 3 ----
// Stored on the appointment as one line, e.g. "Cleaning, Dental Filling".
const MAX_TREATMENTS = 3;

function treatment_picker($selected = [], $idPrefix = 'tp') {
    $h = '<div class="tp-list" id="' . $idPrefix . '-list" data-max="' . MAX_TREATMENTS . '">';
    foreach (clinic_treatments() as $t => $plain) {
        $id = $idPrefix . '-' . preg_replace('/[^a-z0-9]+/i', '', $t);
        $h .= '<label class="tp-item" for="' . $id . '"><input type="checkbox" id="' . $id . '" name="treatments[]" value="'
            . htmlspecialchars($t) . '"' . (in_array($t, $selected, true) ? ' checked' : '') . ' onchange="tpLimit(this)"> '
            . '<b>' . htmlspecialchars($t) . '</b> <span>(' . htmlspecialchars($plain) . ')</span></label>';
    }
    return $h . '</div>';
}

// [ "Cleaning, Dental Filling", error ]
function treatments_from_post($post) {
    $all = array_keys(clinic_treatments());
    $picked = array_values(array_intersect($all, array_map('strval', (array)($post['treatments'] ?? []))));
    if (!$picked && !empty($post['treatment']) && in_array($post['treatment'], $all, true)) $picked = [$post['treatment']];
    if (!$picked) return ['', 'Please choose at least one treatment.'];
    if (count($picked) > MAX_TREATMENTS) return ['', 'Please choose up to ' . MAX_TREATMENTS . ' treatments.'];
    return [implode(', ', $picked), ''];
}

// Styles + the 3-item limit (print once per page).
function treatment_picker_assets() { return <<<HTML
<style>
.tp-list { display: grid; gap: 6px; margin-bottom: 4px; }
.tp-item { display: flex; gap: 8px; align-items: baseline; border: 1px solid #dde5ea; border-radius: 10px; padding: 8px 10px; cursor: pointer; font-size: .9rem; background: #fff; }
.tp-item span { color: #6b7b8c; font-size: .82rem; }
.tp-item:has(input:checked) { border-color: #0f766e; background: #eefaf8; }
.tp-item:has(input:disabled) { opacity: .5; cursor: not-allowed; }
</style>
<script>
// At most N ticked: once N are chosen, the rest are disabled until one is unticked.
function tpLimit(box) {
    var list = box.closest('.tp-list'), max = +list.dataset.max;
    var boxes = list.querySelectorAll('input[type=checkbox]'), n = list.querySelectorAll('input:checked').length;
    boxes.forEach(function (b) { b.disabled = !b.checked && n >= max; });
}
function tpPicked(listId) {
    return [].map.call(document.querySelectorAll('#' + listId + ' input:checked'), function (b) { return b.value; });
}
</script>
HTML; }
