<?php
// "Walk-ins today": visits without an appointment (includes/walkins.php).
$wiToday = walkins_today($pdo, current_role() === 'dentist' ? ($_SESSION['name'] ?? '') : '');
?>
<div class="card-box mb-3" id="walkins-today">
    <div class="flex-between mb-2">
        <h6 class="mb-0">🏥 Walk-ins today <span class="tab-count"><?= count($wiToday) ?></span></h6>
        <button type="button" class="btn btn-sm btn-outline-teal" data-keep-text onclick="wiOpen()">+ Walk-in</button>
    </div>
    <?php if (!$wiToday): ?>
        <div class="text-muted2" style="font-size:.85rem;">No walk-ins yet today. Use <b>+ Walk-in</b> for a patient who comes in without an appointment.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="data">
            <thead><tr><th>Patient</th><th>Time</th><th>Service</th><th>Dentist</th><th>Entered by</th></tr></thead>
            <tbody>
            <?php foreach ($wiToday as $w): ?>
                <tr>
                    <td><div class="d-flex align-items-center gap-2">
                        <strong><?= e($w['patient_name']) ?></strong>
                        <?php if (in_array(current_role(), ['admin', 'dentist'], true)): ?>
                            <!-- 👁 open their treatment records (the name itself is not a link) -->
                            <a href="records?patient=<?= (int)$w['patient_id'] ?>&tab=treatments" class="btn btn-sm icon-btn btn-outline-teal"
                               data-tip="View <?= e($w['patient_name']) ?>'s records" aria-label="View records">👁</a>
                        <?php endif; ?>
                    </div></td>
                    <td class="date-blue"><?= e($w['visit_time']) ?></td>
                    <td><?= e($w['services']) ?></td>
                    <td><?= e($w['dentist']) ?></td>
                    <td><small class="text-muted2"><?= e($w['created_by'] ?: '–') ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
