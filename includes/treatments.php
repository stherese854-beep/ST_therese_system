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
