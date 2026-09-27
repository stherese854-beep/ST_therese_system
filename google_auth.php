<?php
// ============================================================
//  GOOGLE SIGN-IN  (google_auth.php)
// ============================================================
//  Lets a patient log in / register with their Gmail account.
//
//  HOW IT WORKS (OAuth 2.0, no library needed)
//  -------------------------------------------
//   1. We send the user to Google's sign-in page.
//   2. Google sends them back here with a one-time ?code=...
//   3. We swap that code for an access token (a POST to Google).
//   4. We use the token to read their name + email.
//   5. If that email already has an account we log them in;
//      if not, we create a patient account for them.
//
//  HOW TO TURN IT ON (one time)
//  ----------------------------
//   1. Go to https://console.cloud.google.com/  and create a project.
//   2. APIs & Services > OAuth consent screen > External > fill the basics.
//   3. APIs & Services > Credentials > Create Credentials > OAuth client ID
//      - Application type: Web application
//      - Authorised redirect URI:
//            http://localhost/dental-clinic/google_auth.php
//   4. Copy the Client ID and Client Secret.
//   5. In the app: Admin > Settings > Google Sign-In > paste them > Save.
// ============================================================
require_once 'config/auth.php';
require_once 'includes/assign.php';

// ---- Read the Google settings the admin saved ----
$cfg = [];
foreach ($pdo->query("SELECT setting_key, setting_value FROM settings") as $r) {
    $cfg[$r['setting_key']] = $r['setting_value'];
}
$clientId     = $cfg['google_client_id']     ?? '';
$clientSecret = $cfg['google_client_secret'] ?? '';

// The address Google sends the user back to. It must EXACTLY match the one
// you typed in the Google Console.
$scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$redirectUri = $scheme . '://' . $_SERVER['HTTP_HOST']
             . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/google_auth.php';

if ($clientId === '' || $clientSecret === '') {
    set_flash('Google Sign-In is not set up yet. An admin can add the Client ID and Secret in Settings.', 'error');
    header("Location: login"); exit;
}

// ============================================================
//  STEP 1 - no ?code yet, so send the user to Google
// ============================================================
if (!isset($_GET['code'])) {
    $_SESSION['g_state'] = bin2hex(random_bytes(8));   // guards against fake callbacks

    $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $_SESSION['g_state'],
        'prompt'        => 'select_account',
    ]);
    header("Location: $url");
    exit;
}

// ============================================================
//  STEP 2 - Google sent us back with a code
// ============================================================
if (($_GET['state'] ?? '') !== ($_SESSION['g_state'] ?? 'x')) {
    set_flash('Google sign-in failed (bad state). Please try again.', 'error');
    header("Location: login"); exit;
}
unset($_SESSION['g_state']);

// --- Swap the code for an access token ---
$post = http_build_query([
    'code'          => $_GET['code'],
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code',
]);

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $post,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,   // XAMPP often has no CA bundle configured
]);
$tokenJson = curl_exec($ch);
$curlErr   = curl_error($ch);
curl_close($ch);

$token = json_decode($tokenJson, true);
if (empty($token['access_token'])) {
    set_flash('Google sign-in failed: ' . ($token['error_description'] ?? $curlErr ?: 'no token returned'), 'error');
    header("Location: login"); exit;
}

// --- Use the token to read the user's profile ---
$ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token['access_token']],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$profile = json_decode(curl_exec($ch), true);
curl_close($ch);

$gEmail = $profile['email'] ?? '';
$gName  = $profile['name']  ?? '';
$gId    = $profile['id']    ?? '';

if ($gEmail === '') {
    set_flash('Google did not return an email address.', 'error');
    header("Location: login"); exit;
}

// ============================================================
//  STEP 3 - log them in, or create their account
// ============================================================
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$gEmail]);
$user = $stmt->fetch();

if ($user) {
    // Existing account -> just log in. Google has already verified the email.
    $pdo->prepare("UPDATE users SET google_id=?, email_verified=1, last_login=NOW() WHERE id=?")
        ->execute([$gId, $user['id']]);
    session_regenerate_id(true);   // fresh session ID on sign-in
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['name']    = $user['name'];
    $_SESSION['role']    = $user['role'];
    set_flash('Welcome back, ' . $user['name'] . '!');
    header("Location: " . ($user['role'] === 'patient' ? 'portal' : 'dashboard'));
    exit;
}

// New user -> create a patient account. There is no password (they use Google),
// so we store a random one they will never need.
$randomPass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (name,email,password,role,status,email_verified,google_id)
               VALUES (?,?,?,'patient','active',1,?)")
    ->execute([$gName, $gEmail, $randomPass, $gId]);
$newId = $pdo->lastInsertId();

$assignedDentist = pick_dentist_for_new_patient($pdo);
$pdo->prepare("INSERT INTO patients (user_id,name,email,patient_type,status,primary_dentist)
               VALUES (?,?,?,'New','Active',?)")
    ->execute([$newId, $gName, $gEmail, $assignedDentist]);

session_regenerate_id(true);   // fresh session ID on sign-in

$_SESSION['user_id'] = $newId;
$_SESSION['name']    = $gName;
$_SESSION['role']    = 'patient';
set_flash('Account created with Google — welcome, ' . $gName . '!');
header("Location: portal");
exit;
