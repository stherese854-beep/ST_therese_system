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

            <?php foreach ($groups as $groupName => $items): ?>
                <h5 class="mt-4 mb-2" style="color:var(--teal);"><?= e($groupName) ?></h5>

                <?php foreach ($items as $kind => $t):
                    $sKey = "tpl_{$kind}_subject";
                    $bKey = "tpl_{$kind}_body";
                    $edited = isEdited($saved, $sKey) || isEdited($saved, $bKey);
                ?>
                <div class="card-box mb-3">
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
            <h5 class="mt-4 mb-2" style="color:var(--teal);">Printed appointment slip</h5>
            <div class="card-box mb-3">
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

            <div class="d-flex gap-2 mb-4">
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
<script src="js/app.js"></script>
<script>
startClock();

function resetOne(kind, label){
    if (!confirm('Restore "' + label + '" to its original wording?\n\nYour edited version will be discarded.')) return;
    document.getElementById('resetKind').value = kind;
    document.getElementById('resetOneForm').submit();
}
function resetAll(){
    if (!confirm('Restore EVERY message to its original wording?\n\nAll of your edits will be discarded.')) return;
    document.getElementById('resetAllForm').submit();
}
</script>
</body>
</html>
