<?php
// ============================================================
//  X-RAY IMAGE VIEWER  (xray.php?id=N)
// ============================================================
//  X-ray images are private medical records, so the uploads/xrays
//  folder is locked (see its .htaccess) and images are only ever
//  delivered through this page, which checks who is asking:
//   - admin              -> any patient's X-ray
//   - dentist            -> only X-rays of their own assigned patients
//     (same rule as the Records page)
//   - patient            -> only their own X-rays
//  Everyone else gets "Access denied", and the attempt is logged.
// ============================================================
require_once 'config/auth.php';
require_login(['admin','dentist','patient']);

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT x.image_file, x.patient_id, p.primary_dentist, p.user_id
                       FROM xrays x JOIN patients p ON p.id = x.patient_id
                       WHERE x.id = ?");
$stmt->execute([$id]);
$xr = $stmt->fetch();

$role = current_role();
$allowed = false;
if ($xr) {
    if ($role === 'admin') {
        $allowed = true;
    } elseif ($role === 'dentist') {
        $allowed = ($xr['primary_dentist'] === ($_SESSION['name'] ?? ''));
    } elseif ($role === 'patient') {
        $allowed = ((int)$xr['user_id'] === (int)$_SESSION['user_id']);
    }
}
// Same answer whether the X-ray is missing or not theirs, so IDs can't be probed.
if (!$allowed) {
    deny_access("X-ray #$id", false);   // an image request: no on-screen notice
}

$path = __DIR__ . '/uploads/xrays/' . basename($xr['image_file']);
if (!is_file($path)) {
    http_response_code(404);
    exit('Image file not found.');
}

$types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
          'gif' => 'image/gif', 'webp' => 'image/webp'];
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if (!isset($types[$ext])) {
    http_response_code(415);
    exit('Unsupported file type.');
}

while (ob_get_level()) ob_end_clean();    // send the raw image, no page buffering
header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="xray_' . $id . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
