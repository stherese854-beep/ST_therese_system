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
        $tplBefore = [];
        foreach ($pdo->query("SELECT setting_key, setting_value FROM settings") as $r)
            $tplBefore[$r['setting_key']] = (string)$r['setting_value'];
        $changed = [];
        foreach ($catalogue as $kind => $t) {
            foreach (['subject','body'] as $field) {
                $posted = trim($_POST["tpl_{$kind}_{$field}"] ?? '');
                // Storing the built-in text is the same as storing nothing —
                // keep the row empty so future default changes still apply.
                $value  = ($posted === trim($t[$field])) ? '' : $posted;
                if ($value !== ($tplBefore["tpl_{$kind}_{$field}"] ?? '')) $changed[$t['label']] = true;
                save_setting($pdo, "tpl_{$kind}_{$field}", $value);
            }
        }
        foreach ($slip as $key => $f) {
            $posted = trim($_POST[$key] ?? '');
            $value  = ($posted === trim($f['default'])) ? '' : $posted;
            if ($value !== ($tplBefore[$key] ?? '')) $changed['Appointment slip'] = true;
            save_setting($pdo, $key, $value);
        }
        log_activity($pdo, 'Updated message templates', $changed ? 'Changed: ' . implode(', ', array_keys($changed)) : 'Saved with no changes');
        set_flash('Message templates saved.');
        header("Location: templates"); exit;
    }

    if ($action === 'reset_one') {
        $kind = $_POST['kind'] ?? '';
        if (isset($catalogue[$kind])) {
            save_setting($pdo, "tpl_{$kind}_subject", '');
            save_setting($pdo, "tpl_{$kind}_body", '');
            log_activity($pdo, 'Reset message template', $catalogue[$kind]['label'] . ' — back to the original wording');
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
        log_activity($pdo, 'Reset message template', 'All messages — back to the original wording');
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

        <form method="POST">
            <input type="hidden" name="action" value="save_templates">

            <div class="tpl-layout">
            <!-- Pick a message from the drop-down; only that one shows below -->
            <div class="tpl-pick card-box">
                <label class="field-label mb-1" for="tpl-select">📝 Choose a message to edit</label>
                <select id="tpl-select" class="form-select">
                    <?php foreach ($groups as $groupName => $items): ?>
                        <optgroup label="<?= e($groupName) ?>">
                        <?php foreach ($items as $kind => $t):
                            $navEdited = isEdited($saved, "tpl_{$kind}_subject") || isEdited($saved, "tpl_{$kind}_body"); ?>
                            <option value="<?= e($kind) ?>"><?= e($t['label']) ?><?= $navEdited ? '  • edited' : '' ?></option>
                        <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                    <optgroup label="Printed">
                        <option value="slip">Appointment slip</option>
                    </optgroup>
                </select>
            </div>

            <!-- Only the message scrolls; the Save buttons below always stay on screen -->
            <div class="tpl-panes" data-fit-screen="72">
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

            <div class="d-flex gap-2 mb-4 mt-3 flex-wrap tpl-actions">
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
.tpl-layout { margin-top: 12px; }
.tpl-pick { padding: 14px 18px; margin-bottom: 14px; }
.tpl-pick select { max-width: 520px; font-weight: 600; }
.tpl-panes { border-radius: 14px; }
/* Save / Restore / Email settings stay pinned at the bottom of the screen */
.tpl-actions { position: sticky; bottom: 0; z-index: 10; background: var(--card, #fff); padding: 10px 12px;
               border-radius: 14px; box-shadow: 0 -4px 18px rgba(0,0,0,.08); }
.tpl-panes .tpl-pane { margin-bottom: 0 !important; }
.tpl-pane { display: none; }
.tpl-pane.on { display: block; }
/* Message boxes grow to fit their text, so nothing needs scrolling inside them */
.tpl-pane textarea { overflow: hidden; resize: none; min-height: 90px; }
</style>
<script>
startClock();

// Message drop-down: show the chosen message; remembered per browser.
function tplFit(t) { t.style.height = 'auto'; t.style.height = (t.scrollHeight + 2) + 'px'; }
(function () {
    var sel = document.getElementById('tpl-select');
    if (!sel) return;
    function show(k) {
        var found = false;
        document.querySelectorAll('.tpl-pane').forEach(function (p) {
            var on = p.dataset.pane === k; p.classList.toggle('on', on); found = found || on;
            if (on) p.querySelectorAll('textarea').forEach(tplFit);
        });
        if (!found) return false;
        sel.value = k;
        if (window.fitScreen) window.fitScreen();
        try { localStorage.setItem('tpl_pane', k); } catch (e) {}
        return true;
    }
    sel.addEventListener('change', function () { show(sel.value); });
    document.querySelectorAll('.tpl-pane textarea').forEach(function (t) {
        t.addEventListener('input', function () { tplFit(t); });
    });
    window.addEventListener('resize', function () { document.querySelectorAll('.tpl-pane.on textarea').forEach(tplFit); });
    var saved = null;
    try { saved = localStorage.getItem('tpl_pane'); } catch (e) {}
    if (!saved || !show(saved)) show(sel.options[0].value);
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
