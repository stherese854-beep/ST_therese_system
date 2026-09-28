<?php
// ============================================================
//  MARK NOTIFICATIONS AS SEEN  (notif_seen.php)
// ============================================================
//  The notification bell calls this in the background (fetch) the
//  moment the user opens it. It stamps users.notif_seen_at with the
//  current time, which makes the red badge disappear until something
//  newer happens.
//
//  It returns a tiny JSON reply; no page is shown.
// ============================================================
require_once 'config/auth.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['ok' => false, 'reason' => 'not logged in']);
    exit;
}

try {
    $pdo->prepare("UPDATE users SET notif_seen_at = NOW() WHERE id = ?")
        ->execute([$_SESSION['user_id']]);

    // Remember the newest item of each kind that was shown in the bell.
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($in['marks'] ?? null)) {
        $q = $pdo->prepare("SELECT notif_seen_map FROM users WHERE id = ?");
        $q->execute([$_SESSION['user_id']]);
        $map = json_decode((string)$q->fetchColumn(), true) ?: [];
        foreach ($in['marks'] as $kind => $mark) {
            if (!preg_match('/^[a-z]{2,20}$/', (string)$kind) || !is_scalar($mark) || strlen((string)$mark) > 30) continue;
            $old = $map[$kind] ?? null;
            $newer = $old === null || ((is_numeric($mark) && is_numeric($old)) ? $mark + 0 > $old + 0 : strcmp((string)$mark, (string)$old) > 0);
            if ($newer) $map[$kind] = (string)$mark;
        }
        $pdo->prepare("UPDATE users SET notif_seen_map = ? WHERE id = ?")
            ->execute([json_encode($map), $_SESSION['user_id']]);
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    // The column may not exist yet if update.sql has not been run.
    echo json_encode(['ok' => false, 'reason' => 'db']);
}
