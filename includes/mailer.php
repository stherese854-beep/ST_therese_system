<?php
require_once __DIR__ . '/message_templates.php';   // message_catalogue()
// ============================================================
//  MAILER  (includes/mailer.php)
// ============================================================
//  Sends REAL email from XAMPP.
//
//  WHY THIS FILE EXISTS
//  --------------------
//  XAMPP has no built-in mail server, so PHP's mail() does nothing.
//  The normal fix is a library like PHPMailer, but that needs Composer
//  and an internet download. Instead, this file talks to an SMTP server
//  directly (e.g. Gmail's), using only PHP features that XAMPP already
//  has. Nothing to install.
//
//  HOW TO TURN IT ON (one time)
//  ----------------------------
//  1. Use a Gmail account and turn ON 2-Step Verification.
//  2. Go to  Google Account > Security > App passwords  and create one.
//     Google gives you a 16-character password (not your normal password).
//  3. In the app: Admin > Messaging Config > fill in:
//        Host: smtp.gmail.com   Port: 587   Encryption: TLS
//        Username: your.address@gmail.com
//        App password: the 16-character code
//     Tick "Enable email sending" and Save.
//  4. Press "Send Test" to check it works.
//
//  SMTP is just a text conversation over a socket:
//     we say EHLO / STARTTLS / AUTH LOGIN / MAIL FROM / RCPT TO / DATA
//     and the server answers with a 3-digit code each time (250 = OK).
// ============================================================

/**
 * Load the email settings the admin saved in Messaging Config.
 */
function mail_config($pdo) {
    $cfg = [];
    try {
        foreach ($pdo->query("SELECT setting_key, setting_value FROM settings") as $r) {
            $cfg[$r['setting_key']] = $r['setting_value'];
        }
    } catch (Throwable $e) { /* database not ready - treat as "not configured" */ }

    // HOW mail leaves the server:
    //   'smtp'   - Gmail / any SMTP server (works on XAMPP; Railway blocks SMTP on its cheaper plans)
    //   'resend' - Resend's web API over HTTPS (works on Railway; needs your own domain verified)
    //   'brevo'  - Brevo's web API over HTTPS (works on Railway)
    // The API key comes from a Railway Variable (RESEND_API_KEY / BREVO_API_KEY)
    // when there is one, so it never has to live in the code or on GitHub;
    // otherwise from the key saved in Messaging Config.
    $envKey = ['resend' => getenv('RESEND_API_KEY') ?: '', 'brevo' => getenv('BREVO_API_KEY') ?: ''];
    $method = $cfg['mail_method'] ?? '';
    if (!in_array($method, ['smtp', 'resend', 'brevo'], true)) {
        $method = $envKey['resend'] !== '' ? 'resend' : ($envKey['brevo'] !== '' ? 'brevo' : 'smtp');
    }
    $apiKey  = $method === 'smtp' ? '' : ($envKey[$method] ?: ($cfg['mail_api_key'] ?? ''));

    return [
        'enabled'    => ($cfg['email_enabled'] ?? '0') === '1',
        'method'     => $method,
        'api_key'    => $apiKey,
        'key_from_env' => $method !== 'smtp' && $envKey[$method] !== '',
        'host'       => $cfg['smtp_host']       ?? 'smtp.gmail.com',
        'port'       => (int)($cfg['smtp_port'] ?? 587),
        'encryption' => strtolower($cfg['smtp_encryption'] ?? 'tls'),   // tls | ssl | none
        'username'   => $cfg['smtp_username']   ?? '',
        'password'   => $cfg['smtp_password']   ?? '',
        'from_email' => $cfg['smtp_from_email'] ?? ($cfg['smtp_username'] ?? ''),
        'from_name'  => $cfg['smtp_from_name']  ?? 'St. Therese Dental Clinic',
    ];
}

/**
 * Is email sending switched on AND filled in?
 */
function mail_is_ready($pdo) {
    $c = mail_config($pdo);
    if (!$c['enabled']) return false;
    if ($c['method'] !== 'smtp') return $c['api_key'] !== '' && $c['from_email'] !== '';
    return $c['host'] !== '' && $c['username'] !== '' && $c['password'] !== '';
}

/**
 * Send one email through an HTTPS email API (Resend or Brevo).
 * Railway does not block these (they use the normal web port 443).
 */
function api_deliver($pdo, $to, $subject, $htmlBody, &$error = '') {
    $c = mail_config($pdo);
    if (!$c['enabled'])            { $error = 'Email sending is turned off in Messaging Config.'; return false; }
    if ($c['api_key'] === '')      { $error = 'No ' . ucfirst($c['method']) . ' API key — add it in Railway Variables or Messaging Config.'; return false; }
    if ($c['from_email'] === '')   { $error = 'The "From Email" is empty in Messaging Config.'; return false; }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $error = 'That is not a valid email address.'; return false; }
    if (!function_exists('curl_init')) { $error = 'The PHP curl extension is not available on this server.'; return false; }

    if ($c['method'] === 'resend') {
        $url     = 'https://api.resend.com/emails';
        $headers = ['Authorization: Bearer ' . $c['api_key'], 'Content-Type: application/json'];
        $payload = ['from' => $c['from_name'] . ' <' . $c['from_email'] . '>', 'to' => [$to],
                    'subject' => $subject, 'html' => $htmlBody];
    } else {   // brevo
        $url     = 'https://api.brevo.com/v3/smtp/email';
        $headers = ['api-key: ' . $c['api_key'], 'Content-Type: application/json', 'Accept: application/json'];
        $payload = ['sender' => ['name' => $c['from_name'], 'email' => $c['from_email']],
                    'to' => [['email' => $to]], 'subject' => $subject, 'htmlContent' => $htmlBody];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        // XAMPP on Windows often has no certificate bundle; real servers (Railway) do.
        CURLOPT_SSL_VERIFYPEER => PHP_OS_FAMILY !== 'Windows',
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) { $error = 'Could not reach ' . ucfirst($c['method']) . ': ' . $cerr; return false; }
    if ($code >= 200 && $code < 300) return true;
    $j = json_decode((string)$resp, true);
    $error = ucfirst($c['method']) . ' said (' . $code . '): ' . ($j['message'] ?? $j['error'] ?? mb_substr((string)$resp, 0, 200));
    return false;
}

/**
 * Read one reply from the SMTP server.
 * A reply can span several lines ("250-..." means "more lines coming",
 * "250 ..." with a space means "this is the last line").
 */
function smtp_read($socket) {
    $data = '';
    while (($line = fgets($socket, 515)) !== false) {
        $data .= $line;
        // 4th character is a space on the LAST line of a reply
        if (isset($line[3]) && $line[3] === ' ') break;
    }
    return $data;
}

/**
 * Send one command and check the reply starts with the code we expect.
 */
function smtp_cmd($socket, $cmd, $expect, &$error) {
    if ($cmd !== null) fwrite($socket, $cmd . "\r\n");
    $reply = smtp_read($socket);
    $code  = substr(trim($reply), 0, 3);

    if (!in_array($code, (array)$expect)) {
        $error = "SMTP said: " . trim($reply);
        return false;
    }
    return true;
}

/**
 * Send an email.
 *
 * @param  PDO    $pdo
 * @param  string $to       recipient email address
 * @param  string $subject
 * @param  string $htmlBody the message (simple HTML is fine)
 * @param  string $error    filled in with the reason if it fails
 * @return bool   true if the server accepted the message
 */
function send_mail($pdo, $to, $subject, $htmlBody, &$error = '', $kind = 'general') {
    $ok = mail_config($pdo)['method'] === 'smtp'
        ? smtp_deliver($pdo, $to, $subject, $htmlBody, $error)
        : api_deliver($pdo, $to, $subject, $htmlBody, $error);    // Resend / Brevo over HTTPS

    // Keep a record of every attempt (useful proof that reminders went out).
    try {
        $pdo->prepare("INSERT INTO email_log (recipient,subject,kind,status,error) VALUES (?,?,?,?,?)")
            ->execute([$to, mb_substr($subject,0,200), $kind, $ok ? 'sent' : 'failed',
                       $ok ? null : mb_substr($error,0,255)]);
    } catch (Throwable $e) { /* log table may not exist yet - ignore */ }

    return $ok;
}

/**
 * The actual SMTP conversation (used by send_mail above).
 */
function smtp_deliver($pdo, $to, $subject, $htmlBody, &$error = '') {
    $c = mail_config($pdo);

    if (!$c['enabled'])   { $error = 'Email sending is turned off in Messaging Config.'; return false; }
    if ($c['username'] === '' || $c['password'] === '') {
        $error = 'SMTP username / app password is missing in Messaging Config.'; return false;
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $error = 'That is not a valid email address.'; return false; }

    // Port 465 talks SSL from the very first byte. Port 587 starts plain
    // and upgrades to TLS with the STARTTLS command.
    $useSsl   = ($c['encryption'] === 'ssl');
    $address  = ($useSsl ? 'ssl://' : '') . $c['host'] . ':' . $c['port'];

    $context = stream_context_create([
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]
    ]);

    $socket = @stream_socket_client($address, $errno, $errstr, 15,
                                    STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        $error = "Could not reach the mail server ($address): $errstr. "
               . "Check your internet connection, or that port {$c['port']} is not blocked.";
        return false;
    }
    stream_set_timeout($socket, 15);

    $ok = true;
    // 1. Server greets us
    $ok = $ok && smtp_cmd($socket, null, '220', $error);
    // 2. Say hello
    $ok = $ok && smtp_cmd($socket, 'EHLO localhost', '250', $error);

    // 3. Upgrade the plain connection to an encrypted one (port 587)
    if ($ok && $c['encryption'] === 'tls') {
        $ok = $ok && smtp_cmd($socket, 'STARTTLS', '220', $error);
        if ($ok) {
            $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$crypto) { $error = 'Could not start TLS encryption.'; $ok = false; }
        }
        // after STARTTLS we must say hello again
        $ok = $ok && smtp_cmd($socket, 'EHLO localhost', '250', $error);
    }

    // 4. Log in (AUTH LOGIN sends the username and password base64-encoded)
    $ok = $ok && smtp_cmd($socket, 'AUTH LOGIN', '334', $error);
    $ok = $ok && smtp_cmd($socket, base64_encode($c['username']), '334', $error);
    if ($ok && !smtp_cmd($socket, base64_encode($c['password']), '235', $error)) {
        $error = 'Login was rejected. For Gmail you must use a 16-character App Password, '
               . 'not your normal password. (' . $error . ')';
        $ok = false;
    }

    // 5. Who it is from / who it goes to
    $from = $c['from_email'] !== '' ? $c['from_email'] : $c['username'];
    $ok = $ok && smtp_cmd($socket, 'MAIL FROM:<' . $from . '>', '250', $error);
    $ok = $ok && smtp_cmd($socket, 'RCPT TO:<' . $to . '>', ['250','251'], $error);

    // 6. The message itself
    if ($ok && smtp_cmd($socket, 'DATA', '354', $error)) {
        $fromName = '=?UTF-8?B?' . base64_encode($c['from_name']) . '?=';
        $subjEnc  = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $headers  = "From: $fromName <$from>\r\n";
        $headers .= "To: <$to>\r\n";
        $headers .= "Subject: $subjEnc\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";

        // A line that is just "." would end the message early, so any line
        // starting with a dot gets an extra dot (this is called dot-stuffing).
        $body = preg_replace('/^\./m', '..', str_replace("\n", "\r\n", $htmlBody));

        fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
        $ok = smtp_cmd($socket, null, '250', $error);
    } else {
        $ok = false;
    }

    // 7. Say goodbye
    @fwrite($socket, "QUIT\r\n");
    @fclose($socket);

    return $ok;
}

/**
 * Wrap a message in the clinic's email design, so every email looks the same.
 */
function mail_template($title, $bodyHtml, $buttonText = '', $buttonLink = '') {
    $btn = '';
    if ($buttonText !== '' && $buttonLink !== '') {
        $btn = '<tr><td style="padding:8px 0 24px;">
                  <a href="' . htmlspecialchars($buttonLink) . '"
                     style="background:#0f766e;color:#ffffff;text-decoration:none;padding:12px 26px;
                            border-radius:100px;font-weight:600;display:inline-block;">'
                     . htmlspecialchars($buttonText) . '</a>
                </td></tr>';
    }

    return '
<div style="background:#f2f7f6;padding:28px 12px;font-family:Segoe UI,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
         style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;
                box-shadow:0 8px 24px rgba(12,50,48,.10);">
    <tr>
      <td style="background:linear-gradient(135deg,#0d3b3b,#0f766e);padding:22px 28px;color:#ffffff;">
        <div style="font-size:19px;font-weight:700;">🦷 St. Therese Dental Clinic</div>
        <div style="font-size:12px;color:#c79a5c;letter-spacing:2px;text-transform:uppercase;">Appointment System</div>
      </td>
    </tr>
    <tr>
      <td style="padding:28px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
          <tr><td style="font-size:19px;font-weight:700;color:#11302d;padding-bottom:12px;">'
              . htmlspecialchars($title) . '</td></tr>
          <tr><td style="font-size:14px;line-height:1.65;color:#44585a;padding-bottom:18px;">'
              . $bodyHtml . '</td></tr>'
          . $btn .
        '</table>
      </td>
    </tr>
    <tr>
      <td style="background:#f7fafa;padding:16px 28px;font-size:11px;color:#8aa0a0;border-top:1px solid #e6efee;">
        This is an automated message from the St. Therese Dental Clinic Appointment System.
      </td>
    </tr>
  </table>
</div>';
}

// ============================================================
//  TELL THE CLINIC A PATIENT CANCELLED
// ============================================================
//  When a patient cancels online, nobody is standing at the desk to
//  notice. This emails the clinic so the freed-up slot can be reused.
//  It never blocks the cancellation — if mail is off, it just returns.
// ============================================================
/**
 * After a PATIENT cancels (portal or reminder link): email the account holder how
 * many of the allowed cancellations they have used. The count covers the whole
 * account (their own + family members'), the same way booking is paused.
 * Wording: Message Templates → "Cancellation warning" / "Cancellation limit reached".
 */
function send_cancellation_warning($pdo, $appt, $reason = '') {
    if (!mail_is_ready($pdo)) return false;
    try {
        if (!function_exists('patient_cancel_count')) require_once __DIR__ . '/noshow_check.php';
        $pq = $pdo->prepare("SELECT p.id, p.name, p.guardian_patient_id FROM patients p WHERE p.id = ?");
        $pq->execute([(int)$appt['patient_id']]);
        $pat = $pq->fetch();
        if (!$pat) return false;
        $holderId = (int)($pat['guardian_patient_id'] ?: $pat['id']);        // the account holder
        $hq = $pdo->prepare("SELECT p.name, COALESCE(NULLIF(p.email,''), u.email) AS email
                               FROM patients p LEFT JOIN users u ON u.id = p.user_id WHERE p.id = ?");
        $hq->execute([$holderId]);
        $holder = $hq->fetch();
        if (!$holder || empty($holder['email'])) return false;

        $used = 0;
        foreach (family_patient_ids($pdo, $holderId) as $fid) $used += patient_cancel_count($pdo, $fid);
        $limit = CANCEL_LIMIT;
        $kind  = $used >= $limit ? 'cancel_limit' : 'cancel_warning';
        $cat   = message_catalogue()[$kind];
        [$subj, $body] = tpl_message($pdo, $kind, $cat['subject'], $cat['body'], [
            'patient'   => $holder['name'],
            'for'       => (int)$pat['id'] !== $holderId ? ' for ' . $pat['name'] : '',
            'date'      => date('l, F j, Y', strtotime($appt['appointment_date'])),
            'time'      => $appt['appointment_time'],
            'treatment' => $appt['treatment'],
            'reason'    => $reason !== '' ? $reason : 'N/A',
            'used'      => $used,
            'limit'     => $limit,
            'left'      => max(0, $limit - $used),
            'clinic'    => clinic_name($pdo),
        ]);
        $err = '';
        return send_mail($pdo, $holder['email'], $subj, $body, $err, $kind);
    } catch (Throwable $e) { return false; }
}

function notify_clinic_of_cancellation($pdo, $appt, $patientName, $reason = '') {
    if (!mail_is_ready($pdo)) return false;

    // Where should it go? Prefer the clinic's own address, then the sender.
    $to = '';
    try {
        $q = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key IN
                            ('clinic_email','smtp_from_email','smtp_username')
                            ORDER BY FIELD(setting_key,'clinic_email','smtp_from_email','smtp_username')");
        $q->execute();
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $candidate) {
            if (trim((string)$candidate) !== '') { $to = trim($candidate); break; }
        }
    } catch (Throwable $e) { return false; }
    if ($to === '') return false;

    $when = date('l, F j, Y', strtotime($appt['appointment_date']))
          . ' at ' . $appt['appointment_time'];

    $cat = message_catalogue()['cancellation_notice'];
    [$subj, $body] = tpl_message($pdo, 'cancellation_notice', $cat['subject'], $cat['body'], [
        'patient'   => $patientName,
        'date'      => date('l, F j, Y', strtotime($appt['appointment_date'])),
        'time'      => $appt['appointment_time'],
        'treatment' => $appt['treatment'],
        'dentist'   => $appt['dentist'] ?: 'Not assigned',
        'reason'    => trim($reason),
    ]);

    $err = '';
    return send_mail($pdo, $to, $subj, $body, $err, 'cancellation_notice');
}

// ============================================================
//  TELL THE CLINIC A PATIENT MOVED THEIR APPOINTMENT
// ============================================================
function notify_clinic_of_reschedule($pdo, $appt, $patientName, $movedFrom, $newDate, $newTime, $reason = '') {
    if (!mail_is_ready($pdo)) return false;

    $to = '';
    try {
        $q = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key IN
                            ('clinic_email','smtp_from_email','smtp_username')
                            ORDER BY FIELD(setting_key,'clinic_email','smtp_from_email','smtp_username')");
        $q->execute();
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $c) {
            if (trim((string)$c) !== '') { $to = trim($c); break; }
        }
    } catch (Throwable $e) { return false; }
    if ($to === '') return false;

    $newWhen = date('l, F j, Y', strtotime($newDate)) . ' at ' . $newTime;

    $cat = message_catalogue()['reschedule_notice'];
    [$subj, $body] = tpl_message($pdo, 'reschedule_notice', $cat['subject'], $cat['body'], [
        'patient'   => $patientName,
        'was'       => $movedFrom,
        'date'      => date('l, F j, Y', strtotime($newDate)),
        'time'      => $newTime,
        'treatment' => $appt['treatment'],
        'dentist'   => $appt['dentist'] ?: 'Not assigned',
        'reason'    => trim($reason),
    ]);

    $err = '';
    return send_mail($pdo, $to, $subj, $body, $err, 'reschedule_notice');
}

// ============================================================
//  EDITABLE MESSAGE TEMPLATES
// ============================================================
//  Every message the clinic sends has a built-in wording, but the
//  admin can rewrite it in Message Templates. A saved version always
//  wins; if nothing is saved, the built-in text is used.
//
//  Templates hold placeholders in curly braces, e.g. {patient} or
//  {date}. tpl_fill() swaps them for the real values.
// ============================================================

// Reads one saved template field, falling back to the built-in text.
function tpl($pdo, $kind, $field, $default) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach ($pdo->query("SELECT setting_key, setting_value FROM settings
                                  WHERE setting_key LIKE 'tpl\\_%'") as $r) {
                $cache[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Throwable $e) { $cache = []; }
    }
    $key = 'tpl_' . $kind . '_' . $field;
    $val = $cache[$key] ?? '';
    return (trim((string)$val) !== '') ? $val : $default;
}

// Replaces {placeholders} with real values. Anything not supplied is removed.
function tpl_fill($text, $vars) {
    foreach ($vars as $k => $v) {
        $text = str_replace('{' . $k . '}', (string)$v, $text);
    }
    // Drop any placeholder that had no value, so patients never see {something}.
    return preg_replace('/\{[a-z_]+\}/', '', $text);
}

// Convenience: build the subject and the HTML body of a message in one go.
// Line breaks typed by the admin become <br> so the email reads as written.
function tpl_message($pdo, $kind, $defSubject, $defBody, $vars) {
    $subject = tpl_fill(tpl($pdo, $kind, 'subject', $defSubject), $vars);
    $bodyRaw = tpl_fill(tpl($pdo, $kind, 'body',    $defBody),    $vars);
    return [$subject, mail_template($subject, nl2br($bodyRaw))];
}

// The clinic's name, used in message templates as {clinic}.
function clinic_name($pdo) {
    static $name = null;
    if ($name === null) {
        try {
            $q = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='clinic_name'");
            $q->execute();
            $name = $q->fetchColumn() ?: 'St. Therese of Carmel Dental Clinic';
        } catch (Throwable $e) { $name = 'St. Therese of Carmel Dental Clinic'; }
    }
    return $name;
}
