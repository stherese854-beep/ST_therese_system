<?php
// ============================================================
//  X-RAY IMAGE VIEWER  (xray.php?id=N)
// ============================================================
//  X-ray images are private medical records, so the uploads/xrays
//  folder is locked (see its .htaccess) and images are only ever
//  delivered through this page, which checks who is asking:
//   - admin              -> any patient's X-ray
//   - dentist            -> X-rays of the patients they may open
//     (their own + anyone they are seeing — the same rule as the Records page)
//   - staff              -> only the patient a dentist unlocked for them with
//     their approval PIN (includes/chart_access.php)
//   - patient            -> only their own X-rays
//  Everyone else gets "Access denied", and the attempt is logged.
// ============================================================
require_once 'config/auth.php';
require_login(['admin','dentist','staff','patient']);

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
        $prm = [];
        $q = $pdo->prepare("SELECT COUNT(*) FROM patients p WHERE p.id = ? AND " . dentist_patient_sql('p.id', 'p.primary_dentist', $_SESSION['name'] ?? '', $prm));
        $q->execute(array_merge([(int)$xr['patient_id']], $prm));
        $allowed = (bool)$q->fetchColumn();
    } elseif ($role === 'staff') {
        $allowed = (bool)staff_chart_access((int)$xr['patient_id'], false);
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
