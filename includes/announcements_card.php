<?php
// ============================================================
//  ANNOUNCEMENTS CARD  (includes/announcements_card.php)
// ============================================================
//  The "📣 Clinic Announcements" card with a Hide / Show button.
//  Used on the patient portal (Appointments page) and on the
//  dentist / staff dashboard.
//
//  Set before including:
//     $annList    - published announcements, newest first (id, title, content, created_at)
//     $annSeeAll  - optional link to the full list ('' = no link)
//
//  Hiding collapses the card to a slim bar. The choice is remembered
//  in this browser PER ACCOUNT, and the card opens again by itself
//  when a newer announcement is published.
// ============================================================
$annList   = $annList   ?? [];
$annSeeAll = $annSeeAll ?? '';
if (!$annList) return;

$annTop    = array_slice($annList, 0, 3);
$annLatest = max(array_map(fn($x) => (int)$x['id'], $annTop));
?>
<style>
/* Hidden state: a slim one-line bar instead of a full card */
#ann-card.ann-collapsed { padding: 7px 14px !important; }
#ann-card.ann-collapsed h5 { font-size: .9rem; font-weight: 600; }
#ann-card.ann-collapsed #ann-toggle { padding: 1px 10px; font-size: .78rem; }
</style>
<div class="card-box mb-3" id="ann-card" data-latest="<?= $annLatest ?>" data-user="<?= (int)($_SESSION['user_id'] ?? 0) ?>"
     style="border-left:4px solid var(--gold);">
    <div class="flex-between gap-2">
        <h5 class="mb-0">📣 Clinic Announcements
            <small id="ann-hidden-note" class="text-muted2" style="display:none;font-size:.78rem;font-weight:400;">
                · <?= count($annTop) ?> hidden
            </small>
        </h5>
        <div class="d-flex gap-2">
            <?php if ($annSeeAll !== '' && count($annList) > 3): ?>
                <a href="<?= e($annSeeAll) ?>" class="btn btn-sm btn-light">See all</a>
            <?php endif; ?>
            <button type="button" id="ann-toggle" class="btn btn-sm btn-light" aria-expanded="true" aria-controls="ann-body">Hide ▲</button>
        </div>
    </div>
    <div id="ann-body" class="mt-1">
    <?php foreach ($annTop as $i => $n): ?>
        <div class="py-2 <?= $i < count($annTop) - 1 ? 'border-bottom' : '' ?>">
            <div class="flex-between">
                <strong><?= e($n['title']) ?></strong>
                <small class="text-muted2"><?= date('M j, Y', strtotime($n['created_at'])) ?></small>
            </div>
            <div style="font-size:.92rem;color:#55606a;margin-top:4px;white-space:pre-line;"><?= format_announcement($n['content']) ?></div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<script>
(function () {
    var card = document.getElementById('ann-card'), body = document.getElementById('ann-body'),
        btn = document.getElementById('ann-toggle'), note = document.getElementById('ann-hidden-note');
    var KEY = 'annHiddenUpTo_' + card.dataset.user,          // per account on this browser
        latest = parseInt(card.dataset.latest, 10) || 0;
    function set(hidden) {
        body.style.display = hidden ? 'none' : '';
        note.style.display = hidden ? '' : 'none';
        card.classList.toggle('ann-collapsed', hidden);
        btn.textContent = hidden ? 'Show ▼' : 'Hide ▲';
        btn.setAttribute('aria-expanded', hidden ? 'false' : 'true');
    }
    var hiddenUpTo = 0;
    try { hiddenUpTo = parseInt(localStorage.getItem(KEY) || '0', 10) || 0; } catch (e) {}
    set(hiddenUpTo >= latest);                               // a newer announcement re-opens it
    btn.addEventListener('click', function () {
        var hide = body.style.display !== 'none';
        set(hide);
        try { hide ? localStorage.setItem(KEY, String(latest)) : localStorage.removeItem(KEY); } catch (e) {}
    });
})();
</script>
