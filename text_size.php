<?php
// ============================================================
//  SAVE MY TEXT SIZE  (text_size.php)
// ============================================================
//  Called in the background by the "Text size" buttons in the
//  profile menu. Saves the logged-in user's own text size
//  (users.text_scale); "default" goes back to the clinic default.
//  Returns a tiny JSON reply; no page is shown.
// ============================================================
require_once 'config/auth.php';
header('Content-Type: application/json');

if (!is_logged_in() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['ok' => false]); exit;
}
$v = $_POST['scale'] ?? '';
$scale = text_scale_ok($v) ? (int)$v : null;          // anything else = follow the clinic default
try {
    $pdo->prepare("UPDATE users SET text_scale = ? WHERE id = ?")->execute([$scale, (int)$_SESSION['user_id']]);
    echo json_encode(['ok' => true, 'scale' => $scale ?? system_text_scale($pdo)]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false]);
}
