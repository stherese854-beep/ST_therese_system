<?php
// ============================================================
//  TERMS & CONDITIONS / PRIVACY POLICY  (includes/policies.php)
// ============================================================
//  One copy of the clinic's Terms & Conditions (the Appointment
//  Policy) and Privacy Policy, shown on the home page footer and on
//  the booking page. The admin edits them in Landing Page Edit
//  (saved as settings land_terms / land_privacy).
//
//  Text format: sections are separated by a blank line. The first
//  line of a section is its heading, the rest is the paragraph.
//  **word** shows in bold.
// ============================================================

function policy_defaults() {
    return [
        'terms' => "1. Booking and approval\n"
                 . "All online bookings are submitted as **Pending**. An appointment is only final once the clinic or the assigned dentist approves it. You are notified when the status changes.\n\n"
                 . "2. Arrival time\n"
                 . "Please arrive at least **10 minutes before** your scheduled time. Arriving more than 15 minutes late may mean your slot is given to the next patient and your visit is rescheduled.\n\n"
                 . "3. Cancelling or rescheduling\n"
                 . "You may cancel or reschedule from your patient dashboard, ideally at least **24 hours before** your appointment so the slot can be offered to someone else.\n\n"
                 . "4. Missed appointments (no-shows)\n"
                 . "Not arriving without cancelling is recorded as a **no-show**. Repeated no-shows (3 or more) may mean you are asked to book by phone instead of online.\n\n"
                 . "5. Booking for another person\n"
                 . "You may book on behalf of a family member or dependent. You must state your relationship to that patient, and you are responsible for the accuracy of the information you give.\n\n"
                 . "6. Limits\n"
                 . "To keep slots fair, each account may hold a maximum of **3 upcoming appointments** at any one time, and the same patient may not be booked twice on the same day.\n\n"
                 . "7. Dentist assignment\n"
                 . "The clinic assigns an available dentist for your visit. If you have been seen before, we try to keep you with your usual dentist for continuity of care.",
        'privacy' => "1. What we collect\n"
                 . "We collect the information you give us: your name, email address, contact number, date of birth, and the details of your dental visits — treatments, dental charts, X-ray images, and clinical notes recorded by your dentist.\n\n"
                 . "2. Why we collect it\n"
                 . "Your information is used only to provide dental care: to schedule appointments, keep your dental records, send confirmations and reminders, and issue reports your dentist needs for treatment.\n\n"
                 . "3. Who can see it\n"
                 . "Your records can be seen by the clinic's administrators and by the dentist assigned to you. Dentists can only view the records of their own assigned patients. We do **not** sell or share your information with advertisers or other third parties.\n\n"
                 . "4. How it is protected\n"
                 . "Access requires a login. Passwords are stored in encrypted (hashed) form and are never visible to staff. Only authorised staff accounts can open patient records.\n\n"
                 . "5. Messages you will receive\n"
                 . "By registering or booking, you agree to receive appointment-related messages by email — a verification code, a booking confirmation, and a reminder one day before your visit. These are service messages, not marketing.\n\n"
                 . "6. Keeping and deleting records\n"
                 . "Dental records are kept for as long as needed for your care. You may ask the clinic to correct your personal details at any time, or request that your account be deleted.\n\n"
                 . "7. Your rights\n"
                 . "Under the Philippine **Data Privacy Act of 2012 (RA 10173)**, you have the right to be informed, to access, to correct, and to object to the processing of your personal data. To exercise these rights, contact the clinic directly.",
    ];
}

// The saved text (or the default). $which = 'terms' or 'privacy'.
function policy_text($pdo, $which) {
    $def = policy_defaults()[$which] ?? '';
    if (!$pdo) return $def;
    try {
        $q = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $q->execute(['land_' . $which]);
        $v = trim((string)$q->fetchColumn());
        return $v !== '' ? $v : $def;
    } catch (Throwable $e) { return $def; }
}

// Text -> safe HTML (everything is escaped; only **bold** becomes <strong>).
function policy_html($text, $headTag = 'h6') {
    $bold = fn($s) => preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
    $out = '';
    foreach (preg_split("/\R\s*\R/", trim(str_replace("\r", '', (string)$text))) as $block) {
        $lines = preg_split("/\R/", trim($block));
        $head  = array_shift($lines);
        if (!$lines) { $out .= '<p>' . $bold($head) . '</p>'; continue; }   // a lone line is just a paragraph
        $out .= "<$headTag>" . $bold($head) . "</$headTag>"
              . '<p>' . implode('<br>', array_map($bold, $lines)) . '</p>';
    }
    return $out;
}
