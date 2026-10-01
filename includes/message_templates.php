<?php
// ============================================================
//  MESSAGE CATALOGUE  (includes/message_templates.php)
// ============================================================
//  Every message the system can send, with its built-in wording and
//  the placeholders it understands. The editor page reads this list,
//  and each sender reads the same defaults — so the two can never
//  drift apart.
//
//  'vars' is what the admin may use in curly braces, e.g. {patient}.
// ============================================================

function message_catalogue() {
    return [

    // ---------- To the patient ----------
    'confirmation' => [
        'group'   => 'To the patient',
        'label'   => 'Booking received',
        'when'    => 'Sent the moment a patient books, before the clinic approves it.',
        'vars'    => ['patient','date','time','treatment','dentist','clinic'],
        'subject' => 'We received your appointment request',
        'body'    => "Hello {patient},\n\n"
                   . "Thank you for booking with {clinic}. We have received your request:\n\n"
                   . "Date: {date}\nTime: {time}\nTreatment: {treatment}\nDentist: {dentist}\n\n"
                   . "Your appointment is currently PENDING. We will confirm it shortly, and you "
                   . "will receive another message once it is final.",
    ],

    'appointment_confirmed' => [
        'group'   => 'To the patient',
        'label'   => 'Appointment confirmed',
        'when'    => 'Sent when the clinic approves a pending appointment.',
        'vars'    => ['patient','date','time','treatment','dentist','clinic'],
        'subject' => 'Your appointment is confirmed',
        'body'    => "Good news, {patient} — your appointment has been confirmed by the clinic.\n\n"
                   . "Date: {date}\nTime: {time}\nTreatment: {treatment}\nDentist: {dentist}\n\n"
                   . "Please arrive about 10 minutes early. If you cannot make it, you can cancel or "
                   . "move your appointment from your patient portal up to 24 hours before, or call the clinic.\n\n"
                   . "See you soon!",
    ],

    'appointment_cancelled' => [
        'group'   => 'To the patient',
        'label'   => 'Appointment cancelled by the clinic',
        'when'    => 'Sent when clinic staff cancel an appointment. The reason they typed is included.',
        'vars'    => ['patient','date','time','treatment','reason','clinic'],
        'subject' => 'Your appointment has been cancelled',
        'body'    => "We are very sorry, {patient}, but we have had to cancel your appointment.\n\n"
                   . "Date: {date}\nTime: {time}\nTreatment: {treatment}\n\n"
                   . "Reason: {reason}\n\n"
                   . "We sincerely apologise for the inconvenience. Please book another visit from your "
                   . "patient portal, or call the clinic and we will gladly find a new time for you.",
    ],

    'appointment_disapproved' => [
        'group'   => 'To the patient',
        'label'   => 'Booking request disapproved',
        'when'    => 'Sent when the clinic disapproves a Pending booking request. The reason they typed is included.',
        'vars'    => ['patient','date','time','treatment','reason','clinic'],
        'subject' => 'Your booking request was not approved',
        'body'    => "Hello {patient}, we are sorry but we could not approve your booking request.\n\n"
                   . "Date: {date}\nTime: {time}\nTreatment: {treatment}\n\n"
                   . "Reason: {reason}\n\n"
                   . "You are welcome to book another time from your patient portal, or call the clinic "
                   . "and we will gladly help you find a schedule.",
    ],

    'appointment_updated' => [
        'group'   => 'To the patient',
        'label'   => 'Appointment details changed',
        'when'    => 'Sent when staff edit the date, time, treatment or dentist.',
        'vars'    => ['patient','was','date','time','treatment','dentist','note','clinic'],
        'subject' => 'Your appointment details have changed',
        'body'    => "Hello {patient},\n\n"
                   . "The clinic has updated your appointment.\n\n"
                   . "Was: {was}\nNow: {date} at {time}\nTreatment: {treatment}\nDentist: {dentist}\n\n"
                   . "{note}\n\n"
                   . "Please print the updated slip from your patient portal. If this time does not suit "
                   . "you, contact the clinic and we will find another.",
    ],

    'reminder' => [
        'group'   => 'To the patient',
        'label'   => 'Reminder, one day before',
        'when'    => 'Sent the day before a confirmed appointment.',
        'vars'    => ['patient','date','time','treatment','dentist','clinic'],
        'subject' => 'Reminder: your dental appointment is tomorrow',
        'body'    => "Hello {patient},\n\n"
                   . "This is a friendly reminder of your appointment tomorrow at {clinic}.\n\n"
                   . "Date: {date}\nTime: {time}\nTreatment: {treatment}\nDentist: {dentist}\n\n"
                   . "Please arrive about 10 minutes early. If you cannot make it, please let us know "
                   . "as soon as possible so we can offer the slot to another patient.",
    ],

    'noshow_final' => [
        'group'   => 'To the patient',
        'label'   => 'Missed appointment — booking paused',
        'when'    => 'Sent when a patient reaches 3 missed visits (earlier misses only show an on-screen warning in the portal).',
        'vars'    => ['patient','date','time','missed','clinic'],
        'subject' => 'About your missed appointments',
        'body'    => "Hello {patient},\n\n"
                   . "We are sorry we missed you again on {date} at {time}.\n\n"
                   . "You have now missed {missed} scheduled appointments, so online booking is paused "
                   . "on your account.\n\n"
                   . "We encourage you to walk in to the clinic and talk to our staff. They will gladly "
                   . "help you book again and find a schedule that suits you better. There is no "
                   . "penalty — we just want to make sure the next visit works out.",
    ],

    'cancel_limit' => [
        'group'   => 'To the patient',
        'label'   => 'Cancellation limit reached — booking paused',
        'when'    => 'Sent when a patient reaches the cancellation limit (earlier cancellations only show an on-screen warning).',
        'vars'    => ['patient','for','date','time','treatment','reason','used','limit','clinic'],
        'subject' => 'Online booking paused — cancellation limit reached',
        'body'    => "Hello {patient},\n\n"
                   . "As you requested, the appointment{for} on {date} at {time} ({treatment}) has been cancelled.\n"
                   . "Reason given: {reason}\n\n"
                   . "You have now cancelled {used} appointments, which is the limit of {limit}. Online booking is "
                   . "paused on your account until our staff review it.\n\n"
                   . "To book your next visit, please call or visit the clinic — we will be happy to help you.",
    ],

    'account_invite' => [
        'group'   => 'To the patient',
        'label'   => 'Own-account invite',
        'when'    => 'Sent to someone booked under another person’s account when they are given their own account. {link} is required.',
        'vars'    => ['patient','by','link','hours','clinic'],
        'subject' => 'Your own patient account at {clinic}',
        'body'    => "Hello {patient},

"
                   . "{by} has been booking your dental appointments at {clinic}. You can now have your own patient account.

"
                   . "Open this link to set your password:
{link}

"
                   . "Your appointments, dental chart and records will move to your new account. "
                   . "The link works for {hours} hours. If you were not expecting this, you can ignore this email.",
    ],

    'email_change_confirm' => [
        'group'   => 'To the patient',
        'label'   => 'Login email change — confirm',
        'when'    => 'Sent to the NEW address when the clinic changes a patient’s login email. The change only happens after they open {link}.',
        'vars'    => ['patient','old','new','link','hours','clinic'],
        'subject' => 'Please confirm your new login email',
        'body'    => "Hello {patient},

"
                   . "{clinic} is changing the email you use to sign in to {new}.

"
                   . "To confirm, open this link:
{link}

"
                   . "The link works for {hours} hours. Until you confirm, you keep signing in with {old}.",
    ],

    'email_change_notice' => [
        'group'   => 'To the patient',
        'label'   => 'Login email change — notice to the old address',
        'when'    => 'Sent to the OLD address at the same time, so the patient knows about the change.',
        'vars'    => ['patient','old','new','hours','clinic'],
        'subject' => 'Your login email is being changed',
        'body'    => "Hello {patient},

"
                   . "{clinic} has started changing the email you sign in with from {old} to {new}. "
                   . "It changes once the new address is confirmed.

"
                   . "If you did not ask for this, please contact the clinic right away.",
    ],

    'password_reset' => [
        'group'   => 'To the patient',
        'label'   => 'Password reset code',
        'when'    => 'Sent when someone uses Forgot Password. {code} is required.',
        'vars'    => ['code','clinic'],
        'subject' => 'Your password reset code',
        'body'    => "We received a request to reset your password. Enter this code to continue:\n\n"
                   . "{code}\n\n"
                   . "This code expires in 15 minutes. If you did not request this, you can ignore "
                   . "this email.",
    ],

    // ---------- To the clinic ----------
    'cancellation_notice' => [
        'group'   => 'To the clinic',
        'label'   => 'A patient cancelled',
        'when'    => 'Sent to the clinic when a patient cancels online, so the free slot is noticed.',
        'vars'    => ['patient','date','time','treatment','dentist','reason'],
        'subject' => 'Appointment cancelled by patient',
        'body'    => "{patient} cancelled their appointment online.\n\n"
                   . "Patient: {patient}\nWhen: {date} at {time}\nTreatment: {treatment}\nDentist: {dentist}\n\n"
                   . "Reason the patient gave: {reason}\n\n"
                   . "This time slot is now free and can be offered to another patient.",
    ],

    'reschedule_notice' => [
        'group'   => 'To the clinic',
        'label'   => 'A patient moved their appointment',
        'when'    => 'Sent to the clinic when a patient reschedules online.',
        'vars'    => ['patient','was','date','time','treatment','dentist','reason'],
        'subject' => 'Appointment moved by patient',
        'body'    => "{patient} moved their appointment online.\n\n"
                   . "Was: {was}\nNow: {date} at {time}\nTreatment: {treatment}\nDentist: {dentist}\n\n"
                   . "Reason the patient gave: {reason}\n\n"
                   . "The appointment is now Pending and needs to be confirmed again. The original "
                   . "time slot is free.",
    ],

    // ---------- To staff ----------
    'account_welcome' => [
        'group'   => 'To staff',
        'label'   => 'New account created',
        'when'    => 'Sent to a new staff member, dentist or admin with their sign-in details.',
        'vars'    => ['name','email','password','clinic'],
        'subject' => 'Your clinic account is ready',
        'body'    => "Hello {name},\n\n"
                   . "An account has been created for you at {clinic}.\n\n"
                   . "Email: {email}\nTemporary password: {password}\n\n"
                   . "Please sign in and change your password from Profile & Settings.",
    ],

    ];
}

// ============================================================
//  APPOINTMENT SLIP
// ============================================================
//  The printable slip a patient takes away. Only the wording is
//  editable — the appointment details themselves come from the record.
// ============================================================
function slip_fields() {
    return [
        'slip_title'  => ['label' => 'Heading',
                          'help'  => 'The line printed above the details.',
                          'default' => 'APPOINTMENT CONFIRMATION'],
        'slip_note'   => ['label' => 'Reminder box',
                          'help'  => 'The highlighted note. Placeholders: {clinic}',
                          'default' => 'Please arrive at least 10 minutes before your scheduled time. '
                                     . 'To cancel or reschedule, contact the clinic at least 24 hours in advance.'],
        'slip_footer' => ['label' => 'Footer line',
                          'help'  => 'Small print at the bottom. Placeholders: {clinic}',
                          'default' => 'This slip was generated by the {clinic} appointment system.'],
    ];
}

// Reads one saved slip WORDING field. Returns '' when nothing has been saved.
// (Named distinctly so it cannot clash with slip.php's own settings reader.)
function slip_text($pdo, $key) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach ($pdo->query("SELECT setting_key, setting_value FROM settings
                                  WHERE setting_key LIKE 'slip\\_%'") as $r) {
                $cache[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Throwable $e) { $cache = []; }
    }
    return trim((string)($cache[$key] ?? ''));
}
