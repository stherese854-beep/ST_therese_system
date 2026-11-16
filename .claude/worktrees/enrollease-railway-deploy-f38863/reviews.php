<?php
// ============================================================
//  PATIENT REVIEWS  (reviews.php)  -- admin moderation
// ============================================================
//  Patients write a review from their portal. It arrives here as
//  "Pending". Nothing appears on the public landing page until an
//  admin APPROVES it, so nobody can post anything they like on the
//  clinic's homepage.
// ============================================================
require_once 'config/auth.php';
require_login(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    // Look up the reviewer's name up front so the log reads clearly
    // ("Deleted review — Maria Santos") instead of just an id number.
    $revInfo = $pdo->prepare("SELECT name FROM reviews WHERE id=?");
    $revInfo->execute([$id]);
    $revName = $revInfo->fetchColumn() ?: ('#' . $id);

    if ($action === 'approve') {
        $pdo->prepare("UPDATE reviews SET status='Approved' WHERE id=?")->execute([$id]);
        log_activity($pdo, 'Approved review', $revName);
        set_flash('Review approved — it now shows on the landing page.');
    }
    if ($action === 'hide') {
        $pdo->prepare("UPDATE reviews SET status='Hidden' WHERE id=?")->execute([$id]);
        log_activity($pdo, 'Hid review', $revName);
        set_flash('Review hidden from the landing page.', 'info');
    }
    if ($action === 'delete') {
        $done = 0;
        foreach (bulk_ids() as $rid) {             // one review, or several ticked ones
            $n = $pdo->prepare("SELECT name FROM reviews WHERE id=?"); $n->execute([$rid]);
            $rn = $n->fetchColumn();
            if ($rn === false) continue;
            $pdo->prepare("DELETE FROM reviews WHERE id=?")->execute([$rid]);
            log_activity($pdo, 'Deleted review', $rn ?: ('#' . $rid));
            $done++;
        }
        set_flash($done === 1 ? 'Review deleted.' : "$done reviews deleted.", 'info');
    }
    header("Location: reviews"); exit;
}

// Newest first, but show Pending ones at the very top so they get seen.
$reviews = $pdo->query(
    "SELECT r.*, u.photo
     FROM reviews r
     LEFT JOIN users u ON r.user_id = u.id
     ORDER BY (r.status='Pending') DESC, r.created_at DESC"
)->fetchAll();

$pending  = count(array_filter($reviews, fn($r) => $r['status'] === 'Pending'));
$approved = count(array_filter($reviews, fn($r) => $r['status'] === 'Approved'));

$page_title = "Patient Reviews";
include 'includes/head.php';
$active = 'reviews';
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div>
                <h1>Patient Reviews</h1>
                <div class="sub">Approve a review and it appears on the public landing page</div>
            </div>
            <a href="./#testimonials" target="_blank" class="btn btn-light">👁 View on landing page</a>
        </div>

        <?php include 'includes/admin_tabs.php'; ?>

        <div class="row g-3 mb-3">
            <div class="col-md-4"><div class="stat-card">
                <div class="stat-label">Waiting for approval</div>
                <div class="stat-value"><?= $pending ?></div>
            </div></div>
            <div class="col-md-4"><div class="stat-card">
                <div class="stat-label">Showing publicly</div>
                <div class="stat-value"><?= $approved ?></div>
            </div></div>
            <div class="col-md-4"><div class="stat-card">
                <div class="stat-label">Total reviews</div>
                <div class="stat-value"><?= count($reviews) ?></div>
            </div></div>
        </div>

        <?php if ($approved === 0): ?>
            <div class="alert" style="background:#fff6e0;border:1px solid var(--gold);color:#8a6d2f;font-size:.87rem;">
                ℹ️ No approved reviews yet, so the landing page is showing the sample testimonials
                you can edit in <a href="landing_edit">Edit Landing Page</a>. As soon as you approve
                a real review here, it replaces them.
            </div>
        <?php endif; ?>

        <div class="card-box">
            <h5 class="mb-3">All Reviews</h5>

            <?php if (!$reviews): ?>
                <p class="text-muted2 mb-0">No patient has written a review yet.
                   Patients can leave one from <strong>My Profile</strong> in their portal.</p>
            <?php endif; ?>

            <?= bulk_bar('bulk-reviews', 'delete', 'reviews', [], '🗑 Delete selected', 'This cannot be undone.') ?>
            <?php foreach ($reviews as $r): ?>
                <div class="py-3" style="border-bottom:1px solid #eef2f2;">
                    <div class="d-flex gap-3 align-items-start flex-wrap">
                        <?php if (!empty($r['photo']) && is_file(__DIR__ . '/' . $r['photo'])): ?>
                            <img src="<?= e($r['photo']) ?>" alt=""
                                 style="width:48px;height:48px;border-radius:50%;object-fit:cover;flex:none;">
                        <?php else: ?>
                            <span class="avatar" style="flex:none;"><?= e(strtoupper(substr($r['name'],0,1))) ?></span>
                        <?php endif; ?>

                        <div style="flex:1;min-width:240px;">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <strong><?= e($r['name']) ?></strong>
                                <span style="color:#d9a441;letter-spacing:1px;">
                                    <?= str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5 - (int)$r['rating']) ?>
                                </span>
                                <span class="badge-pill <?=
                                    $r['status']==='Approved' ? 'b-completed' :
                                   ($r['status']==='Hidden'   ? 'b-cancelled' : 'b-pending') ?>">
                                    <?= e($r['status']) ?>
                                </span>
                            </div>
                            <div class="text-muted2 mt-1" style="font-size:.9rem;"><?= e($r['comment']) ?></div>
                            <div class="text-muted2 mt-1" style="font-size:.75rem;">
                                <?= date('M j, Y g:i A', strtotime($r['created_at'])) ?>
                            </div>
                        </div>

                        <div class="d-flex gap-1 align-items-start">
                            <?php if ($r['status'] !== 'Approved'): ?>
                                <form method="POST">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button class="btn btn-sm btn-teal">✓ Approve</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($r['status'] !== 'Hidden'): ?>
                                <form method="POST">
                                    <input type="hidden" name="action" value="hide">
                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button class="btn btn-sm btn-light">🚫 Hide</button>
                                </form>
                            <?php endif; ?>

                            <?= bulk_pick('bulk-reviews', $r['id'], 'Select this review') ?>
                            <form method="POST" onsubmit="return confirm('⚠️ Permanently delete this review? This cannot be undone.')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <button class="btn btn-sm btn-light" style="color:#c0392b;">🗑</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
</body>
</html>
