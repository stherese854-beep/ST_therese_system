<?php
// ============================================================
//  MESSAGE TEMPLATES  (templates.php) -- admin only
// ============================================================
//  One place to reword every message the system sends, and the
//  printable appointment slip. Anything left blank falls back to
//  the built-in wording, so the clinic can never break a message
//  by clearing a box.
// ============================================================
require_once 'config/auth.php';
require_once 'includes/mailer.php';
require_once 'includes/message_templates.php';
require_login(['admin']);

$catalogue = message_catalogue();
$slip      = slip_fields();

// ---------- Save ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_templates') {
        foreach ($catalogue as $kind => $t) {
            foreach (['subject','body'] as $field) {
                $posted = trim($_POST["tpl_{$kind}_{$field}"] ?? '');
                // Storing the built-in text is the same as storing nothing —
                // keep the row empty so future default changes still apply.
                $value  = ($posted === trim($t[$field])) ? '' : $posted;
                save_setting($pdo, "tpl_{$kind}_{$field}", $value);
            }
        }
        foreach ($slip as $key => $f) {
            $posted = trim($_POST[$key] ?? '');
            save_setting($pdo, $key, ($posted === trim($f['default'])) ? '' : $posted);
        }
        set_flash('Message templates saved.');
        header("Location: templates"); exit;
    }

    if ($action === 'reset_one') {
        $kind = $_POST['kind'] ?? '';
        if (isset($catalogue[$kind])) {
            save_setting($pdo, "tpl_{$kind}_subject", '');
            save_setting($pdo, "tpl_{$kind}_body", '');
            set_flash('"' . $catalogue[$kind]['label'] . '" was restored to its original wording.', 'info');
        }
        header("Location: templates"); exit;
    }

    if ($action === 'reset_all') {
        foreach ($catalogue as $kind => $t) {
            save_setting($pdo, "tpl_{$kind}_subject", '');
            save_setting($pdo, "tpl_{$kind}_body", '');
        }
        foreach ($slip as $key => $f) save_setting($pdo, $key, '');
        set_flash('All messages were restored to their original wording.', 'info');
        header("Location: templates"); exit;
    }
}

// ---------- Current values ----------
$saved = [];
foreach ($pdo->query("SELECT setting_key, setting_value FROM settings
                      WHERE setting_key LIKE 'tpl\\_%' OR setting_key LIKE 'slip\\_%'") as $r) {
    $saved[$r['setting_key']] = $r['setting_value'];
}
function cur($saved, $key, $default) {
    return (trim((string)($saved[$key] ?? '')) !== '') ? $saved[$key] : $default;
}
function isEdited($saved, $key) {
    return trim((string)($saved[$key] ?? '')) !== '';
}

// Group the catalogue for display
$groups = [];
foreach ($catalogue as $kind => $t) { $groups[$t['group']][$kind] = $t; }

$active = 'templates';
$page_title = "Message Templates";
include 'includes/head.php';
?>
<div class="app-wrap">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div>
                <h1 style="color:var(--teal-light)">Message Templates</h1>
                <div class="sub">Reword the emails the system sends, and the printed appointment slip</div>
            </div>
            <div class="clock"><span class="time" id="clock">--:--</span><br><span id="clock-date"></span></div>
        </div>

        <?php include 'includes/admin_tabs.php'; ?>

        <div class="alert" style="background:#eef7f6;border:1px solid #cfe0dd;color:#3f5350;font-size:.85rem;">
            <strong>How this works.</strong> Text in curly braces is replaced with the real value when the
            message is sent — <code>{patient}</code> becomes the patient's name, <code>{date}</code> becomes
            the appointment date, and so on. Each box lists the ones it understands.
            <br>
            Leave a box <strong>empty</strong> to go back to the original wording. Nothing you delete is lost.
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="save_templates">

            <div class="tpl-layout">
            <!-- Navbar: one entry per message; the selected one shows on the right -->
            <nav class="tpl-nav" id="tpl-nav" aria-label="Messages">
                <?php foreach ($groups as $groupName => $items): ?>
                    <div class="tpl-nav-group"><?= e($groupName) ?></div>
                    <?php foreach ($items as $kind => $t):
                        $navEdited = isEdited($saved, "tpl_{$kind}_subject") || isEdited($saved, "tpl_{$kind}_body"); ?>
                        <button type="button" data-pane="<?= e($kind) ?>"><?= e($t['label']) ?><?= $navEdited ? ' <i class="tpl-dot" title="edited"></i>' : '' ?></button>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <div class="tpl-nav-group">Printed</div>
                <button type="button" data-pane="slip">Appointment slip</button>
            </nav>

            <div class="tpl-panes">
            <?php foreach ($groups as $groupName => $items): ?>
                <?php foreach ($items as $kind => $t):
                    $sKey = "tpl_{$kind}_subject";
                    $bKey = "tpl_{$kind}_body";
                    $edited = isEdited($saved, $sKey) || isEdited($saved, $bKey);
                ?>
                <div class="card-box mb-3 tpl-pane" data-pane="<?= e($kind) ?>">
                    <div class="text-muted2" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;"><?= e($groupName) ?></div>
                    <div class="flex-between mb-1">
                        <h6 class="mb-0">
                            <?= e($t['label']) ?>
                            <?php if ($edited): ?>
                                <span class="badge-pill b-progress" style="font-size:.66rem;">edited</span>
                            <?php endif; ?>
                        </h6>
                        <?php if ($edited): ?>
                            <button type="button" class="btn btn-sm btn-light" style="font-size:.72rem;color:#8aa0a0;"
                                    onclick="resetOne('<?= e($kind) ?>', '<?= e($t['label']) ?>')">Restore original</button>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted2 mb-2" style="font-size:.8rem;"><?= e($t['when']) ?></div>

                    <label class="field-label">Subject</label>
                    <input name="<?= $sKey ?>" class="form-control mb-2"
                           value="<?= e(cur($saved, $sKey, $t['subject'])) ?>">

                    <label class="field-label">Message</label>
                    <textarea name="<?= $bKey ?>" class="form-control mb-1" rows="7"><?= e(cur($saved, $bKey, $t['body'])) ?></textarea>

                    <div class="text-muted2" style="font-size:.76rem;">
                        You can use:
                        <?php foreach ($t['vars'] as $v): ?><code>{<?= $v ?>}</code> <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <!-- ===== Appointment slip ===== -->
            <div class="card-box mb-3 tpl-pane" data-pane="slip">
                <h6 class="mb-2">Printed appointment slip</h6>
                <div class="text-muted2 mb-3" style="font-size:.8rem;">
                    The slip a patient prints after their appointment is confirmed. The date, time, dentist
                    and reference number come from the appointment itself — only the wording below is editable.
                </div>
                <?php foreach ($slip as $key => $f): ?>
                    <label class="field-label">
                        <?= e($f['label']) ?>
                        <?php if (isEdited($saved, $key)): ?>
                            <span class="badge-pill b-progress" style="font-size:.62rem;">edited</span>
                        <?php endif; ?>
                    </label>
                    <textarea name="<?= $key ?>" class="form-control mb-1" rows="2"><?= e(cur($saved, $key, $f['default'])) ?></textarea>
                    <div class="text-muted2 mb-3" style="font-size:.76rem;"><?= e($f['help']) ?></div>
                <?php endforeach; ?>
            </div>
            </div><!-- /.tpl-panes -->
            </div><!-- /.tpl-layout -->

            <div class="d-flex gap-2 mb-4 flex-wrap">
                <button class="btn btn-teal">💾 Save all messages</button>
                <button type="button" class="btn btn-light" onclick="resetAll()">↺ Restore everything</button>
                <a href="messaging" class="btn btn-light">✉️ Email settings</a>
            </div>
        </form>

        <!-- Hidden forms for the restore actions -->
        <form method="POST" id="resetOneForm" style="display:none;">
            <input type="hidden" name="action" value="reset_one">
            <input type="hidden" name="kind" id="resetKind">
        </form>
        <form method="POST" id="resetAllForm" style="display:none;">
            <input type="hidden" name="action" value="reset_all">
        </form>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
<style>
.tpl-layout { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 16px; align-items: start; margin-top: 12px; }
.tpl-nav { background: #fff; border-radius: 14px; padding: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.06);
           position: sticky; top: 12px; display: flex; flex-direction: column; gap: 2px; }
.tpl-nav-group { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
                 color: #8aa0a0; padding: 10px 10px 4px; }
.tpl-nav button { border: 0; background: transparent; text-align: left; padding: 8px 10px; border-radius: 9px;
                  font-size: .86rem; color: #3f5350; line-height: 1.3; }
.tpl-nav button:hover { background: #eef7f6; color: var(--teal); }
.tpl-nav button.on { background: var(--teal); color: #fff; font-weight: 600; }
.tpl-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: var(--gold); vertical-align: 1px; margin-left: 3px; }
.tpl-pane { display: none; }
.tpl-pane.on { display: block; }
@media (max-width: 860px) {
    /* Phones/tablets: the navbar becomes one scrollable row above the message */
    .tpl-layout { grid-template-columns: 1fr; }
    .tpl-nav { position: static; flex-direction: row; overflow-x: auto; padding: 6px; }
    .tpl-nav-group { display: none; }
    .tpl-nav button { white-space: nowrap; flex: 0 0 auto; }
}
</style>
<script>
startClock();

// Message navbar: show the chosen message; remembered per browser.
(function () {
    var nav = document.getElementById('tpl-nav');
    if (!nav) return;
    function show(k) {
        var found = false;
        document.querySelectorAll('.tpl-pane').forEach(function (p) {
            var on = p.dataset.pane === k; p.classList.toggle('on', on); found = found || on;
        });
        if (!found) return false;
        nav.querySelectorAll('button').forEach(function (b) {
            var on = b.dataset.pane === k;
            b.classList.toggle('on', on);
            b.setAttribute('aria-current', on ? 'true' : 'false');
            if (on && window.innerWidth <= 860) b.scrollIntoView({ block: 'nearest', inline: 'center' });
        });
        try { localStorage.setItem('tpl_pane', k); } catch (e) {}
        return true;
    }
    nav.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-pane]');
        if (b) show(b.dataset.pane);
    });
    var saved = null;
    try { saved = localStorage.getItem('tpl_pane'); } catch (e) {}
    if (!saved || !show(saved)) show(nav.querySelector('button[data-pane]').dataset.pane);
})();

function resetOne(kind, label){
    askConfirm({ title: 'Restore original wording?', danger: true, okText: 'Yes, restore',
                 message: 'Restore "' + label + '" to its original wording?\n\nYour edited version will be discarded.' })
        .then(function (ok) {
            if (!ok) return;
            document.getElementById('resetKind').value = kind;
            document.getElementById('resetOneForm').submit();
        });
}
function resetAll(){
    askConfirm({ title: 'Restore all messages?', danger: true, okText: 'Yes, restore all',
                 message: 'Restore EVERY message to its original wording?\n\nAll of your edits will be discarded.' })
        .then(function (ok) { if (ok) document.getElementById('resetAllForm').submit(); });
}
</script>
</body>
</html>
