<?php
// ============================================================
//  SAMPLE DATA  (System → 🧪 Sample data, admin only)
// ============================================================
//  One button fills the system with realistic practice data —
//  50 rows in each main table:
//    patients (with 2 families), user logins (42 patient-portal
//    accounts + 8 staff), appointments over the last 3 months and the
//    next 2 weeks, walk-ins, treatment records, dental charts (one
//    tooth entry each), clinical notes, follow-up plans (braces…) and
//    their sessions.
//  Everyone has a middle name (one has none), and one walk-in has the
//  same full name as an older patient: Patients → 👥 Same full name.
//  Another button removes all of it again.
//
//  Every row it adds is listed in `demo_records`, so "Remove" deletes
//  exactly those rows, plus anything later added to the sample
//  patients (a new appointment, a treatment…). Real records are never
//  touched. The sample logins use "@sample.test" addresses, which can
//  never receive mail, and send_mail() skips them; sample visits are
//  marked as already reminded, so no reminder email goes out for them.
// ============================================================

const DEMO_PASSWORD  = 'Sample#2026';
const DEMO_LOGINS    = ['demo1@sample.test', 'demo2@sample.test'];   // the 2 family accounts (shown on System → Sample data)
const DEMO_STAFF     = 'staff1@sample.test';                         // one of the 8 sample staff logins (shown there too)
const DEMO_PER_TABLE = 50;                                           // rows in each main table

// Tables that point at a patient — what "Remove" also clears for the sample patients.
const DEMO_PATIENT_TABLES = ['appointments', 'treatments', 'walk_in_visits', 'chart_sessions', 'odontogram',
                             'treatment_plan_sessions', 'treatment_plans', 'clinical_notes', 'xrays', 'chart_requests',
                             'patient_notices', 'reviews', 'archived_records', 'email_changes'];

function ensure_demo_table($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS demo_records (
                    tbl    VARCHAR(40) NOT NULL,
                    row_id INT NOT NULL,
                    PRIMARY KEY (tbl, row_id)
                ) DEFAULT CHARSET=utf8mb4");
}

/** null when there is no sample data, else ['at','by','counts' => [...]]. */
function demo_status($pdo) {
    try {
        ensure_demo_table($pdo);
        $counts = [];
        foreach ($pdo->query("SELECT tbl, COUNT(*) n FROM demo_records GROUP BY tbl") as $r) $counts[$r['tbl']] = (int)$r['n'];
        if (!$counts) return null;
        $info = json_decode((string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'sample_data_info'")->fetchColumn(), true) ?: [];
        return ['at' => $info['at'] ?? '', 'by' => $info['by'] ?? '', 'counts' => $counts];
    } catch (Throwable $e) { return null; }
}

/** "Jose Rizal Dela Cruz" -> "jose.delacruz@sample.test" (made unique with a number when needed). */
function demo_email($name, array &$used) {
    $w = preg_split('/\s+/', strtolower(trim($name)));
    $last = count($w) > 2 && in_array($w[count($w) - 2], ['dela', 'de', 'del', 'san', 'delos'], true)
          ? $w[count($w) - 2] . $w[count($w) - 1] : end($w);
    $base = preg_replace('/[^a-z]/', '', $w[0]) . '.' . preg_replace('/[^a-z]/', '', $last);
    $email = $base . '@sample.test';
    for ($n = 2; isset($used[$email]); $n++) $email = $base . $n . '@sample.test';
    $used[$email] = true;
    return $email;
}

/**
 * Adds the sample data. Returns [ok, message].
 */
function demo_add($pdo, $by) {
    ensure_demo_table($pdo);
    if (demo_status($pdo)) return [false, 'Sample data is already in the system. Remove it first to add a fresh set.'];
    $dentists = $pdo->query("SELECT name FROM users WHERE role = 'dentist' AND status = 'active' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    if (!$dentists) return [false, 'Add at least one dentist first (Admin → Dentists): sample appointments need a dentist.'];
    if ($pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE '%@sample.test'")->fetchColumn()) {
        return [false, 'Accounts with a "@sample.test" email already exist (left from an earlier sample set). Remove them first in User Management.'];
    }

    require_once __DIR__ . '/treatments.php';
    require_once __DIR__ . '/clinical.php';
    require_once __DIR__ . '/followups.php';
    ensure_plan_sessions_table($pdo);

    $N = DEMO_PER_TABLE;
    $pdo->beginTransaction();                             // all of it, or nothing
    try {
    mt_srand(20261020);                                   // the same set every time
    $pick  = fn(array $a) => $a[mt_rand(0, count($a) - 1)];
    $today = date('Y-m-d');
    $slots = clinic_time_slots($pdo);
    $open  = array_filter(array_map('trim', explode(',', (string)($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'clinic_open_days'")->fetchColumn() ?: 'Mon,Tue,Wed,Thu,Fri,Sat'))));
    $isOpen = fn($d) => in_array(date('D', strtotime($d)), $open, true);
    $openBefore = function ($d) use ($isOpen) {           // the date itself, or the open day before it
        for ($i = 0; $i < 7 && !$isOpen($d); $i++) $d = date('Y-m-d', strtotime("$d -1 day"));
        return $d;
    };

    // Times already taken (dentist + date + time), so samples never clash with real bookings.
    $taken = [];
    foreach ($pdo->query("SELECT dentist, appointment_date, appointment_time FROM appointments
                           WHERE appointment_date >= CURDATE() - INTERVAL 100 DAY AND status NOT IN ('Cancelled','Disapproved','Expired')") as $r) {
        $taken[$r['dentist'] . '|' . $r['appointment_date'] . '|' . $r['appointment_time']] = true;
    }
    $freeSlot = function ($dentist, $date, $after = null) use (&$taken, $slots) {
        $try = $slots;
        shuffle($try);
        foreach ($try as $t) {
            if ($after !== null && strtotime("$date $t") <= $after) continue;
            if (isset($taken["$dentist|$date|$t"])) continue;
            $taken["$dentist|$date|$t"] = true;
            return $t;
        }
        return null;
    };

    $added = [];                                          // table => [ids]
    $ins = function ($table, array $row) use ($pdo, &$added) {
        $cols = array_keys($row);
        $pdo->prepare("INSERT INTO $table (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")
            ->execute(array_values($row));
        $id = (int)$pdo->lastInsertId();
        $added[$table][] = $id;
        return $id;
    };
    $age = fn($dob) => (int)date_diff(date_create($dob), date_create($today))->y;
    $dob = fn($years) => date('Y-m-d', strtotime("-$years years -" . mt_rand(0, 360) . ' days'));
    $phone = fn() => '0917' . mt_rand(100, 999) . mt_rand(1000, 9999);
    $hash = password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT);
    $usedEmails = array_fill_keys(array_merge(DEMO_LOGINS, [DEMO_STAFF]), true);

    // ---------- People ----------
    // [name, age, blood, allergies, conditions, medications, alert]. Adults (18+) get a patient-portal login.
    $people = [
        ['Jose Rizal Dela Cruz', 52, 'B+', 'Penicillin', 'Hypertension', 'Amlodipine 5 mg', 'Allergic to penicillin · high blood pressure'],
        ['Maria Clara Reyes', 28, 'O+', '', '', '', ''],
        ['Antonio Bautista', 45, 'A+', '', 'Type 2 diabetes', 'Metformin', 'Diabetic — check sugar before extractions'],
        ['Rosalinda Garcia', 61, 'AB+', 'Latex', 'Heart condition', 'Aspirin (blood thinner)', 'Latex allergy · on blood thinner'],
        ['Mark Anthony Santos', 19, 'O+', '', '', '', ''],
        ['Kristine Joy Ramos', 24, 'B+', '', 'Asthma', 'Salbutamol inhaler', 'Asthma — bring inhaler'],
        ['Ramon Aquino', 38, 'O-', '', '', '', ''],
        ['Lorna Fernandez', 47, 'A-', 'Ibuprofen', '', '', 'Allergic to ibuprofen'],
        ['Paolo Castillo', 31, 'O+', '', '', '', ''],
        ['Jennifer Navarro', 35, 'B-', '', '', '', ''],
        ['Ernesto Mercado', 67, 'A+', '', 'Hypertension', 'Losartan', 'High blood pressure'],
        ['Angelica Torres', 22, 'O+', '', '', '', ''],
        ['Rogelio Flores', 56, 'AB-', 'Sulfa drugs', '', '', 'Allergic to sulfa drugs'],
        ['Bea Santiago', 16, 'O+', '', '', '', ''],
        ['Christian Domingo', 27, 'A+', '', '', '', ''],
        ['Teresita Lim', 58, 'B+', '', 'Osteoporosis', 'Alendronate', 'Takes alendronate — ask before extractions'],
        ['Noel Pascual', 42, 'O+', '', '', '', ''],
        ['Grace Valdez', 33, 'A+', '', 'Pregnant (2nd trimester)', 'Prenatal vitamins', 'Pregnant — no X-rays'],
        ['Francis Gonzales', 29, 'B+', '', '', '', ''],
        ['Michelle Tan', 26, 'O+', 'Amoxicillin', '', '', 'Allergic to amoxicillin'],
        ['Danilo Robles', 49, 'A-', '', '', '', ''],
        ['Patricia Morales', 39, 'O+', '', '', '', ''],
        ['Jerome Salazar', 14, 'B+', '', '', '', ''],
        ['Cecilia Ocampo', 72, 'A+', '', 'Diabetes, hypertension', 'Insulin, Amlodipine', 'Diabetic and high blood pressure'],
        ['Vincent Cruz', 21, 'O+', '', '', '', ''],
        ['Analyn Soriano', 30, 'AB+', '', '', '', ''],
        ['Eduardo Villareal', 44, 'O+', '', '', '', ''],
        ['Marites Dizon', 50, 'A+', '', 'Hypertension', 'Amlodipine', 'High blood pressure'],
        ['Ricardo Manalo', 36, 'B+', '', '', '', ''],
        ['Josephine Aguilar', 29, 'O+', 'Codeine', '', '', 'Allergic to codeine'],
        ['Benjamin Cortez', 62, 'A-', '', 'Type 2 diabetes', 'Metformin', 'Diabetic'],
        ['Liezl Javier', 27, 'O+', '', '', '', ''],
        ['Arnel Sarmiento', 41, 'B-', '', '', '', ''],
        ['Rowena Ilagan', 33, 'AB+', '', 'Asthma', 'Salbutamol inhaler', 'Asthma — bring inhaler'],
        ['Dennis Padilla', 48, 'O+', '', '', '', ''],
        ['Charisse Sy', 23, 'A+', '', '', '', ''],
        ['Ronaldo Esguerra', 55, 'O-', 'Penicillin', '', '', 'Allergic to penicillin'],
        ['Hazel Quiambao', 31, 'B+', '', '', '', ''],
        ['Gilbert Velasco', 39, 'O+', '', '', '', ''],
        ['Divina Tolentino', 66, 'A+', '', 'Heart condition', 'Clopidogrel (blood thinner)', 'On blood thinner'],
        ['Jayson Magbanua', 25, 'O+', '', '', '', ''],
        ['Katrina Lacson', 37, 'B+', '', '', '', ''],
    ];
    $patients = [];                                       // id => [name, dentist, account, rel, age]
    $pIds = [];
    $adultIds = [];                                       // patients with their own login (they book for themselves)
    foreach ($people as $i => [$name, $years, $blood, $allergy, $cond, $meds, $alert]) {
        $d = $dob($years);
        $since = date('Y-m-d H:i:s', strtotime('-' . mt_rand(95, 400) . ' days'));
        $uid = null; $email = null;
        if ($age($d) >= 18) {                             // adults: a patient-portal login
            $email = demo_email($name, $usedEmails);
            $uid = $ins('users', ['name' => $name, 'email' => $email, 'password' => $hash, 'role' => 'patient', 'status' => 'active',
                                  'email_verified' => 1, 'contact' => $phone(), 'created_at' => $since]);
        }
        $dentist = $dentists[$i % count($dentists)];
        $id = $ins('patients', [
            'user_id' => $uid, 'name' => $name, 'email' => $email, 'phone' => $phone(), 'age' => $age($d), 'date_of_birth' => $d,
            'blood_type' => $blood, 'patient_type' => 'New', 'status' => 'Active', 'primary_dentist' => $dentist,
            'medical_alert' => $alert ?: null, 'allergies' => $allergy ?: null, 'medical_conditions' => $cond ?: null,
            'medications' => $meds ?: null, 'created_at' => $since,
        ]);
        $patients[$id] = ['name' => $name, 'dentist' => $dentist, 'account' => null, 'rel' => null, 'age' => $age($d)];
        $pIds[] = $id;
        if ($uid) $adultIds[] = $id;
    }

    // Two families with a portal login each: the account holder + the people they book for.
    $families = [
        [DEMO_LOGINS[0], 'Andrea Villanueva', 34, [['Miguel Villanueva', 9, 'Child'], ['Sofia Villanueva', 6, 'Child'], ['Rosario Villanueva', 63, 'Parent']]],
        [DEMO_LOGINS[1], 'Carlo Mendoza', 41, [['Liza Mendoza', 39, 'Spouse'], ['Gabriel Mendoza', 13, 'Child']]],
    ];
    foreach ($families as $f => [$email, $name, $years, $members]) {
        $dentist = $dentists[$f % count($dentists)];
        $uid = $ins('users', ['name' => $name, 'email' => $email, 'password' => $hash, 'role' => 'patient', 'status' => 'active',
                              'email_verified' => 1, 'contact' => $phone(), 'created_at' => date('Y-m-d H:i:s', strtotime('-200 days'))]);
        $d = $dob($years);
        $hid = $ins('patients', ['user_id' => $uid, 'name' => $name, 'email' => $email, 'phone' => $phone(), 'age' => $age($d),
                                 'date_of_birth' => $d, 'patient_type' => 'New', 'status' => 'Active', 'primary_dentist' => $dentist,
                                 'created_at' => date('Y-m-d H:i:s', strtotime('-200 days'))]);
        $patients[$hid] = ['name' => $name, 'dentist' => $dentist, 'account' => null, 'rel' => null, 'age' => $age($d)];
        $pIds[] = $hid;
        $adultIds[] = $hid;
        foreach ($members as [$mName, $mYears, $rel]) {
            $md = $dob($mYears);
            $mid = $ins('patients', ['name' => $mName, 'phone' => $phone(), 'age' => $age($md), 'date_of_birth' => $md,
                                     'patient_type' => 'New', 'status' => 'Active', 'primary_dentist' => $dentist,
                                     'guardian_patient_id' => $hid, 'relationship' => $rel,
                                     'created_at' => date('Y-m-d H:i:s', strtotime('-190 days'))]);
            $patients[$mid] = ['name' => $mName, 'dentist' => $dentist, 'account' => $name, 'rel' => $rel, 'age' => $age($md)];
            $pIds[] = $mid;
        }
    }

    // ---------- Same full name (includes/identity.php) ----------
    // A new walk-in with the same full name as an older patient, another birthday:
    // waiting in Patients → 👥 Same full name for the clinic to check an ID.
    $ramon = 0;
    foreach ($patients as $rid => $rp) if ($rp['name'] === 'Ramon Aquino') $ramon = $rid;
    if ($ramon) {
        $d = $dob(24);
        $twin = $ins('patients', ['name' => 'Ramon Aquino', 'phone' => $phone(), 'age' => $age($d), 'date_of_birth' => $d,
                                  'patient_type' => 'New', 'status' => 'Active', 'primary_dentist' => $dentists[0],
                                  'created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))]);
        $patients[$twin] = ['name' => 'Ramon Aquino', 'dentist' => $dentists[0], 'account' => null, 'rel' => null, 'age' => $age($d)];
        $pIds[] = $twin;
        $ins('identity_checks', ['patient_id' => $twin, 'match_id' => $ramon, 'pair_key' => identity_pair_key($twin, $ramon),
                                 'status' => 'open', 'found_at' => date('Y-m-d H:i:s', strtotime('-2 days'))]);
    }

    // ---------- Staff logins: users fill up to 50 (receptionists, assistants…) ----------
    $staffPeople = [['Joanna Rivera', 'Receptionist'], ['Mylene Bautista', 'Dental Assistant'], ['Arvin Santos', 'Dental Assistant'],
                    ['Precious Cruz', 'Receptionist'], ['Kevin Ramos', 'Clinic Aide'], ['Lea Dominguez', 'Dental Assistant'],
                    ['Ryan Mercado', 'Cashier'], ['Shiela Gomez', 'Records Officer']];
    $staffNames = [];
    foreach ($staffPeople as $k => [$sName, $pos]) {
        if (count($added['users'] ?? []) >= $N) break;
        $ins('users', ['name' => $sName, 'email' => $k === 0 ? DEMO_STAFF : 'staff' . ($k + 1) . '@sample.test', 'password' => $hash,
                       'role' => 'staff', 'position' => $pos, 'status' => 'active', 'email_verified' => 1, 'contact' => $phone(),
                       'created_at' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(200, 500) . ' days'))]);
        $staffNames[] = $sName;
    }
    $staff = array_merge($pdo->query("SELECT name FROM users WHERE role IN ('staff','admin') AND status = 'active' AND email NOT LIKE '%@sample.test' ORDER BY role = 'staff' DESC, id")->fetchAll(PDO::FETCH_COLUMN),
                         $staffNames) ?: [$by];

    // ---------- Appointments: 50 (past 3 months, today, the next 2 weeks) ----------
    $treatList = array_keys(clinic_treatments());
    $weights   = ['Consultation' => 5, 'Cleaning' => 7, 'Dental Filling' => 5, 'Tooth Extraction' => 3,
                  'Root Canal' => 2, 'Dental Crown' => 1, 'Braces / Orthodontics' => 1];
    $pool = [];
    foreach ($weights as $t => $w) if (in_array($t, $treatList, true)) for ($k = 0; $k < $w; $k++) $pool[] = $t;
    if (!$pool) $pool = $treatList;
    $reasons = ['Toothache on the lower left', 'Routine check-up', 'Bleeding gums when brushing', 'Sensitive to cold drinks',
                'Broken filling', 'Wants teeth cleaned', 'Follow-up from last visit', 'Swelling near the back tooth', ''];
    $cancelWhy = ['Schedule conflict at work', 'Feeling sick', 'Out of town', 'Family emergency', 'Will rebook next month'];
    $notesFor = [
        'Consultation'          => ['Check-up done. Advised to floss daily.', 'Oral exam — no new cavities. Come back in 6 months.'],
        'Cleaning'              => ['Scaling and polishing done. Mild tartar on lower front teeth.', 'Cleaning done. Gums healthy.'],
        'Dental Filling'        => ['Composite filling placed.', 'Small cavity removed and filled. Avoid hard food for 24 hours.'],
        'Tooth Extraction'      => ['Extraction done, no complications. Bite on gauze for 30 minutes.', 'Tooth removed. Avoid rinsing hard for a day.'],
        'Root Canal'            => ['Canals cleaned and medicated. Temporary filling placed.', 'Root canal session done. Some soreness is normal.'],
        'Dental Crown'          => ['Tooth prepared and temporary crown placed.', 'Permanent crown cemented.'],
        'Braces / Orthodontics' => ['Braces adjusted, wires changed.', 'Braces adjustment — new rubber bands.'],
    ];
    $teethFor = ['Dental Filling' => [14, 15, 16, 24, 25, 26, 36, 37, 46, 47], 'Tooth Extraction' => [18, 28, 38, 48, 17, 46],
                 'Root Canal' => [16, 26, 36, 46], 'Dental Crown' => [16, 26, 36, 46, 21, 11]];

    $now = time();
    $apptIns = function ($pid, $date, $time, $dentist, $treat, $status, $extra = []) use ($ins, $patients, $pick, $reasons, $now) {
        $p = $patients[$pid];
        $made = min(strtotime("$date -" . mt_rand(3, 12) . ' days ' . mt_rand(8, 20) . ':' . mt_rand(10, 59)), $now - mt_rand(2, 48) * 3600);
        $row = ['patient_id' => $pid, 'patient_name' => $p['name'], 'dentist' => $dentist, 'treatment' => $treat,
                'appointment_date' => $date, 'appointment_time' => $time, 'status' => $status,
                'booked_for' => $p['account'] ? 'Someone else' : 'Myself', 'relationship' => $p['rel'],
                'booked_by' => $p['account'], 'reason_for_visit' => $pick($reasons), 'reminder_sent' => 1,
                'created_at' => date('Y-m-d H:i:s', $made)];
        if (in_array($status, ['Confirmed', 'Arrived', 'Completed', 'No-show', 'Needs Review'], true)) {
            $row['confirmed_at'] = date('Y-m-d H:i:s', min($made + mt_rand(1, 20) * 3600, $now - 600));
        }
        return $ins('appointments', array_merge($row, $extra));
    };

    // Who books online: adults with a login, and the family members (booked by their account holder).
    $bookers = array_values(array_filter($pIds, fn($id) => $patients[$id]['account'] !== null || in_array($id, $adultIds, true)));
    $nApp = 0;
    // Today: one already here, the rest later today.
    if ($isOpen($today)) {
        foreach (array_slice($bookers, 14, 4) as $k => $pid) {
            $dentist = $patients[$pid]['dentist'];
            if ($k === 0) {
                if (!($time = $freeSlot($dentist, $today))) continue;
                $apptIns($pid, $today, $time, $dentist, 'Cleaning', 'Arrived',
                         ['arrived_at' => date('Y-m-d H:i:s', min($now, strtotime("$today $time")) - 600), 'arrived_by' => $pick($staff)]);
            } else {
                if (!($time = $freeSlot($dentist, $today, $now)) && !($time = $freeSlot($dentist, $today))) continue;
                $apptIns($pid, $today, $time, $dentist, $pick($pool), $k === 3 ? 'Pending' : 'Confirmed');
            }
            $nApp++;
        }
    }
    // The next two weeks: 12 requests and confirmed visits.
    $aheadDays = [];
    for ($ahead = 1; $ahead <= 14; $ahead++) { $d = date('Y-m-d', strtotime("+$ahead days")); if ($isOpen($d)) $aheadDays[] = $d; }
    for ($k = 0, $tries = 0; $aheadDays && $k < 12 && $tries < 200; $tries++) {
        $date = $aheadDays[$tries % count($aheadDays)];
        $pid = $pick($bookers);
        $dentist = $patients[$pid]['dentist'];
        if (!($time = $freeSlot($dentist, $date))) continue;
        $apptIns($pid, $date, $time, $dentist, $pick($pool), mt_rand(1, 100) <= 60 ? 'Confirmed' : 'Pending');
        $nApp++; $k++;
    }
    // The last 3 months: the rest, up to 50.
    $pastDays = [];
    for ($back = 90; $back >= 1; $back--) { $d = date('Y-m-d', strtotime("-$back days")); if ($isOpen($d)) $pastDays[] = $d; }
    shuffle($pastDays);
    $noShowers = [$pIds[4], $pIds[8]];                    // who misses visits (Repeat Offenders / Frequent Cancels), on purpose
    $cancellers = [$pIds[11]];
    $done = [];                                           // completed visits: [pid, date, time, dentist, treat]
    $needsReview = 0;
    for ($tries = 0; $pastDays && $nApp < $N && $tries < 2000; $tries++) {
        $date = $pastDays[$tries % count($pastDays)];
        $pid = $pick($bookers);
        $dentist = $patients[$pid]['dentist'];
        if (!($time = $freeSlot($dentist, $date))) continue;
        $back = (int)round((strtotime($today) - strtotime($date)) / 86400);
        $treat = $pick($pool);
        $roll = mt_rand(1, 100);
        if (in_array($pid, $noShowers, true) && $roll <= 60) $status = 'No-show';
        elseif (in_array($pid, $cancellers, true) && $roll <= 70) $status = 'Cancelled';
        elseif ($back <= 7 && $needsReview < 3 && $roll <= 85) { $status = 'Needs Review'; $needsReview++; }   // confirmed, not marked yet
        elseif ($roll <= 70) $status = 'Completed';
        elseif ($roll <= 84) $status = 'Cancelled';
        elseif ($roll <= 94) $status = 'No-show';
        else $status = 'Expired';                         // a request nobody confirmed
        $extra = [];
        if ($status === 'Cancelled') {
            $extra = ['cancelled_at' => date('Y-m-d H:i:s', strtotime("$date -1 day 18:00")),
                      'cancelled_by' => mt_rand(0, 1) ? ($patients[$pid]['account'] ?? $patients[$pid]['name']) : $pick($staff), 'cancel_reason' => $pick($cancelWhy)];
        }
        if ($status === 'Completed') {
            $extra = ['arrived_at' => date('Y-m-d H:i:s', strtotime("$date $time") - mt_rand(5, 20) * 60), 'arrived_by' => $pick($staff)];
            $done[] = [$pid, $date, $time, $dentist, $treat];
        }
        $apptIns($pid, $date, $time, $dentist, $treat, $status, $extra);
        $nApp++;
    }

    // ---------- Walk-ins: 50 (came in without an appointment) ----------
    $walkDone = [];                                       // past walk-ins: [pid, date, time, dentist, service]
    for ($k = 0; $k < $N; $k++) {
        $pid = $pIds[$k % count($pIds)];
        $back = ($k < 2 && $isOpen($today)) ? 0 : ($pid === ($twin ?? 0) ? 2 : mt_rand(1, 120));   // the new "Ramon Aquino" came 2 days ago
        $date = $back === 0 ? $today : $openBefore(date('Y-m-d', strtotime("-$back days")));
        $dentist = $patients[$pid]['dentist'];
        $time = $back === 0 ? date('h:i A', max(strtotime("$today 09:00"), min($now, strtotime("$today 11:15")) - $k * 3600)) : $pick($slots);
        $svc = $pick(['Cleaning', 'Consultation', 'Tooth Extraction', 'Dental Filling', 'Consultation', 'Cleaning']);
        $ins('walk_in_visits', ['patient_id' => $pid, 'patient_name' => $patients[$pid]['name'], 'visit_date' => $date, 'visit_time' => $time,
                                'dentist' => $dentist, 'services' => $svc, 'notes' => $pick(['Came in with tooth pain', 'No appointment — had time today', '']),
                                'created_by' => $pick($staff), 'created_at' => date('Y-m-d H:i:s', strtotime("$date $time"))]);
        if ($back > 0) $walkDone[] = [$pid, $date, $time, $dentist, $svc];
    }

    // ---------- Treatment records: 50 — the completed appointments first, then walk-ins ----------
    // Each of these visits also gets a dental chart (one tooth entry) and a clinical note.
    $visits = array_slice(array_merge($done, $walkDone), 0, $N);
    usort($visits, fn($a, $b) => strcmp($a[1] . $a[2], $b[1] . $b[2]));
    $charted = [];
    $chartStatus = ['Dental Filling' => 'Filled', 'Tooth Extraction' => 'Extracted', 'Root Canal' => 'Filled', 'Dental Crown' => 'Crowned'];
    foreach ($visits as [$pid, $date, $time, $dentist, $treat]) {
        $treat = $treat === 'Braces / Orthodontics' ? 'Consultation' : $treat;      // braces get their own plan below
        $tooth = isset($teethFor[$treat]) ? (string)$pick($teethFor[$treat]) : '';
        $ins('treatments', [
            'patient_id' => $pid, 'patient_name' => $patients[$pid]['name'], 'treatment_name' => $treat, 'tooth' => $tooth,
            'dentist' => $dentist, 'treatment_date' => $date,
            'status' => in_array($treat, ['Root Canal', 'Dental Crown'], true) ? 'In Progress' : 'Completed',
            'notes' => $pick($notesFor[$treat] ?? ['Done.']),
            'created_at' => date('Y-m-d H:i:s', strtotime("$date $time") + 40 * 60),
        ]);
        // The dental chart of this visit: the tooth that was worked on, or one the dentist noted.
        $sid = $ins('chart_sessions', ['patient_id' => $pid, 'visit_date' => $date,
                                       'title' => isset($charted[$pid]) ? 'Follow-up' : 'Initial Chart',
                                       'notes' => $pick(['', 'Recheck the molars next visit.', 'Good oral hygiene overall.', 'Watch the gums on the lower left.']) ?: null,
                                       'created_by' => $dentist, 'created_at' => date('Y-m-d H:i:s', strtotime("$date $time") + 30 * 60)]);
        $charted[$pid] = true;
        $st = $chartStatus[$treat] ?? $pick(['Healthy', 'Decayed', 'Filled', 'Impacted', 'Fractured', 'Other']);
        $ins('odontogram', ['patient_id' => $pid, 'tooth_number' => $tooth !== '' ? $tooth : (string)$pick([11, 12, 14, 16, 17, 21, 24, 26, 27, 36, 37, 38, 46, 47, 48]),
                            'tooth_status' => $st, 'condition_other' => $st === 'Other' ? $pick(['Sensitive to cold', 'Gum recession', 'Discolored']) : null,
                            'notes' => mt_rand(0, 2) ? null : $pick(['Watch this tooth', 'Patient feels pain when chewing', 'Old filling, still fine']),
                            'session_id' => $sid]);
        $ins('clinical_notes', ['patient_id' => $pid, 'author' => $dentist, 'created_at' => date('Y-m-d H:i:s', strtotime("$date $time") + 50 * 60),
                                'note' => $pick(['Patient anxious — explain each step.', 'Prefers morning appointments.', 'Brushes too hard; showed soft brushing.',
                                                 'Recommended a night guard for grinding.', 'Advised to cut down on sweet drinks.', 'Bleeding gums — showed flossing.',
                                                 'Took the procedure well, no complaints.', 'Asked about whitening — discuss next visit.'])]);
    }

    // ---------- Follow-up plans: 50, one per patient, each with its first session done ----------
    // [treatment, teeth, every N days, total sessions, session label]
    $planTypes = [
        ['Braces / Orthodontics', null, 30, 12, 'Braces adjustment'],
        ['Root Canal', [16, 26, 36, 46], 7, 3, 'Root Canal'],
        ['Cleaning', null, 180, null, 'Cleaning'],
        ['Dental Crown', [16, 26, 36, 46, 11, 21], 14, 2, 'Dental Crown'],
        ['Consultation', null, 180, null, '6-month check-up'],
        ['Dental Filling', [14, 15, 24, 25, 36, 46], 14, 2, 'Dental Filling'],
    ];
    foreach (array_slice($pIds, 0, $N) as $i => $pid) {
        [$name, $teeth, $every, $total, $label] = ($patients[$pid]['age'] < 18 || $i % 10 === 0) ? $planTypes[0] : $planTypes[1 + $i % 5];
        $dentist = $patients[$pid]['dentist'];
        $tooth = $teeth ? (string)$pick($teeth) : null;
        $last = $openBefore(date('Y-m-d', strtotime('-' . mt_rand(1, (int)round($every * 1.3)) . ' days')));   // some are overdue
        $next = date('Y-m-d', strtotime("$last +$every days"));
        $planId = $ins('treatment_plans', [
            'patient_id' => $pid, 'treatment_name' => $name, 'tooth' => $tooth, 'dentist' => $dentist,
            'interval_days' => $every, 'total_sessions' => $total, 'sessions_done' => 1, 'last_session_date' => $last,
            'next_due' => $next, 'status' => 'Active', 'created_by' => $dentist,
            'created_at' => date('Y-m-d H:i:s', strtotime("$last 11:00")),
            'last_reminder_for' => $next,                 // no reminder email for samples
        ]);
        $ins('treatment_plan_sessions', ['plan_id' => $planId, 'patient_id' => $pid, 'session_no' => 1, 'session_date' => $last,
                                         'label' => $name === 'Braces / Orthodontics' ? 'Braces placed' : $label, 'source' => 'earlier',
                                         'created_at' => date('Y-m-d H:i:s', strtotime("$last 11:45"))]);
    }

    // First / last name next to each full name, and the middle name (in full; '' = no middle name).
    foreach ($added['patients'] ?? [] as $id) save_name_parts($pdo, 'patients', $id);
    foreach ($added['users'] ?? [] as $id) save_name_parts($pdo, 'users', $id);
    $middles = [
        'Jose Rizal Dela Cruz' => 'Mercado', 'Maria Clara Reyes' => 'Santos', 'Antonio Bautista' => 'Villareal',
        'Rosalinda Garcia' => 'Manalo', 'Mark Anthony Santos' => 'Rivera', 'Kristine Joy Ramos' => 'Aguilar',
        'Ramon Aquino' => 'Reyes', 'Lorna Fernandez' => 'Castro', 'Paolo Castillo' => 'Dizon', 'Jennifer Navarro' => 'Pineda',
        'Ernesto Mercado' => 'Lacson', 'Angelica Torres' => 'Bernardo', 'Rogelio Flores' => 'Medina', 'Bea Santiago' => 'Cortez',
        'Christian Domingo' => 'Javier', 'Teresita Lim' => 'Go', 'Noel Pascual' => 'Sarmiento', 'Grace Valdez' => 'Ilagan',
        'Francis Gonzales' => 'Padilla', 'Michelle Tan' => 'Sy', 'Danilo Robles' => 'Esguerra', 'Patricia Morales' => 'Quiambao',
        'Jerome Salazar' => 'Velasco', 'Cecilia Ocampo' => 'Tolentino', 'Vincent Cruz' => 'Magbanua', 'Analyn Soriano' => '',
        'Eduardo Villareal' => 'Ramos', 'Marites Dizon' => 'Cruz', 'Ricardo Manalo' => 'Bautista', 'Josephine Aguilar' => 'Navarro',
        'Benjamin Cortez' => 'Flores', 'Liezl Javier' => 'Ocampo', 'Arnel Sarmiento' => 'Domingo', 'Rowena Ilagan' => 'Pascual',
        'Dennis Padilla' => 'Robles', 'Charisse Sy' => 'Tan', 'Ronaldo Esguerra' => 'Mercado', 'Hazel Quiambao' => 'Garcia',
        'Gilbert Velasco' => 'Torres', 'Divina Tolentino' => 'Reyes', 'Jayson Magbanua' => 'Castillo', 'Katrina Lacson' => 'Morales',
        'Andrea Villanueva' => 'Lopez', 'Miguel Villanueva' => 'Lopez', 'Sofia Villanueva' => 'Lopez', 'Rosario Villanueva' => 'Dizon',
        'Carlo Mendoza' => 'Reyes', 'Liza Mendoza' => 'Ramos', 'Gabriel Mendoza' => 'Ramos',
        'Joanna Rivera' => 'Santos', 'Mylene Bautista' => 'Cruz', 'Arvin Santos' => 'Dela Paz', 'Precious Cruz' => 'Manalo',
        'Kevin Ramos' => 'Garcia', 'Lea Dominguez' => 'Reyes', 'Ryan Mercado' => 'Flores', 'Shiela Gomez' => 'Aquino',
    ];
    foreach (['patients', 'users'] as $t) {
        if (empty($added[$t])) continue;
        foreach ($pdo->query("SELECT id, name FROM `$t` WHERE id IN (" . implode(',', array_map('intval', $added[$t])) . ")") as $r) {
            if (array_key_exists($r['name'], $middles)) {
                save_middle_name($pdo, $t, $r['id'], [$middles[$r['name']], $middles[$r['name']] === '']);
            }
        }
    }

    // Remember every row, then the patients' last visit / New–Returning.
    $rec = $pdo->prepare("INSERT IGNORE INTO demo_records (tbl, row_id) VALUES (?, ?)");
    foreach ($added as $t => $ids) foreach ($ids as $id) $rec->execute([$t, $id]);
    foreach (array_keys($patients) as $pid) sync_visit_info($pdo, $pid);
    save_setting($pdo, 'sample_data_info', json_encode(['at' => date('Y-m-d H:i:s'), 'by' => $by]));
    $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        mt_srand();
        return [false, 'Could not add the sample data: ' . $e->getMessage()];
    }
    mt_srand();

    $c = fn($t) => count($added[$t] ?? []);
    return [true, $c('patients') . ' patients, ' . $c('users') . ' logins, ' . $nApp . ' appointments, ' . $c('walk_in_visits') . ' walk-ins, '
                . $c('treatments') . ' treatment records, ' . $c('chart_sessions') . ' dental charts, ' . $c('clinical_notes') . ' notes and '
                . $c('treatment_plans') . ' follow-up plans'];
}

/**
 * Removes every sample row, plus anything later added to the sample patients.
 * Returns the number of rows removed.
 */
function demo_remove($pdo) {
    ensure_demo_table($pdo);
    $tracked = [];
    foreach ($pdo->query("SELECT tbl, row_id FROM demo_records") as $r) $tracked[$r['tbl']][] = (int)$r['row_id'];
    if (!$tracked) return 0;

    $uids = $tracked['users'] ?? [];
    $pids = $tracked['patients'] ?? [];
    // People later added under a sample account (a new family member) go too.
    if ($pids || $uids) {
        $more = $pdo->query("SELECT id FROM patients WHERE "
                            . ($pids ? "guardian_patient_id IN (" . implode(',', $pids) . ")" : '0')
                            . ($uids ? " OR user_id IN (" . implode(',', $uids) . ")" : ''))->fetchAll(PDO::FETCH_COLUMN);
        $pids = array_values(array_unique(array_merge($pids, array_map('intval', $more))));
    }
    $in = fn(array $ids) => implode(',', array_map('intval', $ids)) ?: '0';

    $n = 0;
    $pdo->beginTransaction();
    try {
        foreach (DEMO_PATIENT_TABLES as $t) {
            if (!$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->rowCount()) continue;
            $sql = "DELETE FROM $t WHERE patient_id IN (" . $in($pids) . ")";
            if (!empty($tracked[$t])) $sql .= " OR id IN (" . $in($tracked[$t]) . ")";
            if ($t === 'treatment_plan_sessions' && !empty($tracked['treatment_plans'])) $sql .= " OR plan_id IN (" . $in($tracked['treatment_plans']) . ")";
            if ($t === 'odontogram' && !empty($tracked['chart_sessions'])) $sql .= " OR session_id IN (" . $in($tracked['chart_sessions']) . ")";
            if (in_array($t, ['reviews', 'email_changes'], true) && $uids) $sql .= " OR user_id IN (" . $in($uids) . ")";
            $n += $pdo->exec($sql);
        }
        if ($pdo->query("SHOW TABLES LIKE 'identity_checks'")->rowCount()) {      // same-full-name pairs
            $n += $pdo->exec("DELETE FROM identity_checks WHERE patient_id IN (" . $in($pids) . ") OR match_id IN (" . $in($pids) . ")"
                             . (!empty($tracked['identity_checks']) ? " OR id IN (" . $in($tracked['identity_checks']) . ")" : ''));
        }
        if ($pdo->query("SHOW TABLES LIKE 'account_invites'")->rowCount()) {
            $n += $pdo->exec("DELETE FROM account_invites WHERE patient_id IN (" . $in($pids) . ") OR guardian_patient_id IN (" . $in($pids) . ")");
        }
        if ($uids && $pdo->query("SHOW TABLES LIKE 'patient_archive'")->rowCount()) {
            $n += $pdo->exec("DELETE FROM patient_archive WHERE user_id IN (" . $in($uids) . ")");
        }
        $n += $pdo->exec("DELETE FROM patients WHERE id IN (" . $in($pids) . ")");
        // The sample patient-portal and staff logins (never an admin or dentist).
        if ($uids) $n += $pdo->exec("DELETE FROM users WHERE id IN (" . $in($uids) . ") AND role IN ('patient','staff')");
        $pdo->exec("DELETE FROM demo_records");
        $pdo->exec("DELETE FROM settings WHERE setting_key = 'sample_data_info'");
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $n;
}
