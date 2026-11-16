<?php
// ============================================================
//  RELATIONSHIPS  (includes/relationships.php)
// ============================================================
//  "Relationship" always describes the PATIENT, seen from the account
//  holder who books for them:   Raquel -> "Child"  =  Raquel is your child.
//
//  The booking form used to ask the opposite ("YOUR relationship to the
//  patient"), so a parent booking for a 5-year-old saved "Parent" — and the
//  other pages then showed a 5-year-old as the account holder's parent.
//  migrate_relationship_direction() turned the saved answers around once
//  (the old value is kept in relationship_before), and the booking form now
//  asks "Who is the patient to you?" with an age check.
// ============================================================

// Saved value => [what the booking form shows, age rule].
//   ['younger', N] the patient is at least N years younger than the account holder
//   ['older',   N] the patient is at least N years older
//   ['adult', 18]  the patient is 18 or older
const RELATIONSHIPS = [
    'Child'         => ['My child',                                     ['younger', 12]],
    'Grandchild'    => ['My grandchild',                                ['younger', 24]],
    'Spouse'        => ['My spouse / partner',                          ['adult', 18]],
    'Parent'        => ['My parent',                                    ['older', 12]],
    'Grandparent'   => ['My grandparent',                               ['older', 24]],
    'Sibling'       => ['My brother / sister',                          null],
    'Under my care' => ['Someone under my care (I am their guardian)',  null],
    'Friend'        => ['A friend',                                     null],
];

/** "your child", "under your care", "their cousin" — $who: 'your' or 'their'. */
function relationship_phrase($rel, $who = 'your') {
    $rel = trim((string)$rel);
    if ($rel === '') return '';
    if ($rel === 'Under my care') return "under $who care";
    $word = ['Spouse' => 'spouse / partner', 'Sibling' => 'brother / sister'][$rel] ?? mb_strtolower($rel);
    return "$who $word";
}

/**
 * '' when the relationship fits the two birthdays, otherwise what is wrong.
 * $patientDob / $holderDob: 'Y-m-d' or empty (a missing birthday is not checked against).
 */
function relationship_age_problem($rel, $patientDob, $holderDob, $patientName = 'This person') {
    $rule = RELATIONSHIPS[$rel][1] ?? null;
    if (!$rule || !$patientDob || !strtotime($patientDob)) return '';
    [$kind, $n] = $rule;
    $age  = age_from_dob($patientDob);
    $what = relationship_phrase($rel, 'your');
    $hasHolder = $holderDob && strtotime($holderDob);
    $gap  = $hasHolder ? (int)(new DateTime($patientDob))->diff(new DateTime($holderDob))->format('%r%y') : null;   // + = patient is older
    if ($kind === 'adult' && $age < $n) {
        return "$patientName is $age — your spouse / partner must be 18 or older. Please choose who they are to you.";
    }
    if ($kind === 'older') {
        if ($hasHolder ? $gap < $n : $age < $n + 6) {
            return "$patientName is $age — too young to be $what" . ($hasHolder ? " (they would be at least $n years older than you)" : '') . ". Please choose who they are to you.";
        }
    }
    if ($kind === 'younger' && $hasHolder) {
        $diff = (int)(new DateTime($holderDob))->diff(new DateTime($patientDob))->format('%r%y');      // + = patient is younger
        if ($diff < $n) {
            return "$patientName is $age — too old to be $what (they would be at least $n years younger than you). Please choose who they are to you.";
        }
    }
    return '';
}

// One saved answer, turned to the new meaning ("who the patient is to you").
// Birthdays decide where they are known; otherwise the old booking form's
// meaning is assumed ("Parent" = I am their parent -> the patient is my Child).
function relationship_turned($rel, $patientDob, $holderDob) {
    if ($rel === 'Guardian') return 'Under my care';
    $pair = ['Parent' => ['Child', 'Parent'], 'Child' => ['Child', 'Parent'],
             'Grandparent' => ['Grandchild', 'Grandparent'], 'Grandchild' => ['Grandchild', 'Grandparent']];
    if (!isset($pair[$rel])) return $rel;                                   // Spouse, Sibling, Friend, typed
    [$young, $old] = $pair[$rel];
    if ($patientDob && $holderDob) return $patientDob > $holderDob ? $young : $old;   // born later = younger
    if ($patientDob && age_from_dob($patientDob) < 18) return $young;                 // a child cannot be your parent
    return ['Parent' => 'Child', 'Child' => 'Parent', 'Grandparent' => 'Grandchild', 'Grandchild' => 'Grandparent'][$rel];
}

// ONE TIME: turn every saved relationship around to the new meaning.
function migrate_relationship_direction($pdo) {
    try {
        if ($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'relationship_dir_v1'")->fetchColumn()) return;
        foreach (['patients', 'appointments'] as $t) {
            if (!$pdo->query("SHOW COLUMNS FROM $t LIKE 'relationship_before'")->fetch()) {
                $pdo->exec("ALTER TABLE $t ADD COLUMN relationship_before VARCHAR(40) DEFAULT NULL");   // the answer as it was saved
            }
        }
        $n = 0;
        // The people an account books for ...
        $up = $pdo->prepare("UPDATE patients SET relationship = ?, relationship_before = ? WHERE id = ?");
        foreach ($pdo->query("SELECT d.id, d.relationship, d.date_of_birth AS dob, h.date_of_birth AS hdob
                                FROM patients d LEFT JOIN patients h ON h.id = d.guardian_patient_id
                               WHERE d.relationship IS NOT NULL AND d.relationship <> ''")->fetchAll() as $r) {
            $new = relationship_turned($r['relationship'], $r['dob'], $r['hdob']);
            if ($new !== $r['relationship']) { $up->execute([$new, $r['relationship'], $r['id']]); $n++; }
        }
        // ... and the copy saved with each of their appointments.
        $ua = $pdo->prepare("UPDATE appointments SET relationship = ?, relationship_before = ? WHERE id = ?");
        foreach ($pdo->query("SELECT a.id, a.relationship, p.date_of_birth AS dob, h.date_of_birth AS hdob
                                FROM appointments a
                                LEFT JOIN patients p ON p.id = a.patient_id
                                LEFT JOIN patients h ON h.id = p.guardian_patient_id
                               WHERE a.relationship IS NOT NULL AND a.relationship <> ''")->fetchAll() as $r) {
            $new = relationship_turned($r['relationship'], $r['dob'], $r['hdob']);
            if ($new !== $r['relationship']) { $ua->execute([$new, $r['relationship'], $r['id']]); $n++; }
        }
        save_setting($pdo, 'relationship_dir_v1', date('Y-m-d H:i:s') . " ($n turned around)");
    } catch (Throwable $e) { /* try again after the next update */ }
}
