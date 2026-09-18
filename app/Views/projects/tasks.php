<?php $isArchived = (bool) ($isArchived ?? false); ?>
<?php $canViewProjectFinance = (bool) ($canViewProjectFinance ?? false); ?>
<?php if ($isArchived): ?>
    <div class="archive-banner">
        Проект в архиве · <?= e(format_date($project['archived_at'] ?? '') ?: 'дата не указана') ?>
    </div>
<?php endif; ?>

<section class="project-head">
    <div>
        <span class="muted"><?= e($project['stage']) ?> · <?= e($project['object']) ?></span>
        <h2><?= e($project['code']) ?> · Задачи</h2>
    </div>
</section>

<?php $projectNavActive = 'tasks'; require BASE_PATH . '/app/Views/projects/_navigation.php'; ?>

<section class="panel project-task-panel">
    <div class="panel__head">
        <h2>Задачи проекта</h2>
        <span class="task-count-badge"><?= count($tasks) ?></span>
    </div>
    <div class="table-wrap">
        <table class="data-table project-task-table">
            <colgroup>
                <col class="project-task-table__id">
                <col class="project-task-table__title">
                <col class="project-task-table__status">
                <col class="project-task-table__discipline">
                <col class="project-task-table__deadline">
                <col class="project-task-table__responsible">
                <col class="project-task-table__progress">
            </colgroup>
            <thead>
            <tr>
                <th>ID</th>
                <th>Название</th>
                <th>Статус</th>
                <th>Дисциплина</th>
                <th>Срок</th>
                <th>Ответственный</th>
                <th>Прогресс</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($tasks as $task): ?>
                <?php
                $progress = max(0, min(100, (int) $task['progress']));
                $deadlineClass = deadline_state_class($task['date_end'] ?? null);
                $deadlineDisplay = (string) ($task['date_end'] ?? '') !== '' ? format_date($task['date_end']) : '—';
                $emptyCell = '<span class="cell-empty">—</span>';
                $discipline = trim((string) ($task['discipline'] ?? ''));
                $assigneeName = trim((string) ($task['assignee_name'] ?? ''));
                $outlineLevel = max(0, (int) ($task['msp_outline_level'] ?? 1) - 1);
                $isSubtask = !empty($task['parent_id']);
                $childCount = (int) ($task['child_count'] ?? 0);
                ?>
                <tr class="clickable<?= $isSubtask ? ' project-task-table__subtask-row' : '' ?>" data-href="<?= url('/tasks/' . $task['id']) ?>" data-task-drawer-href="<?= url('/tasks/' . $task['id']) ?>">
                    <td>#<?= (int) $task['id'] ?></td>
                    <td class="project-task-title" style="--task-outline-level: <?= (int) $outlineLevel ?>;">
                        <strong><?= e($task['title']) ?></strong>
                        <?php if ($isSubtask): ?>
                            <small class="subtask-note">подзадача к #<?= (int) $task['parent_id'] ?><?= trim((string) ($task['parent_title'] ?? '')) !== '' ? ' · ' . e($task['parent_title']) : '' ?></small>
                        <?php elseif ($childCount > 0): ?>
                            <small class="subtask-note">есть подзадачи: <?= $childCount ?></small>
                        <?php endif; ?>
                        <?php if (!empty($task['tags'])): ?>
                            <div class="task-tags task-tags--inline">
                                <?php foreach ($task['tags'] as $tag): ?>
                                    <span class="task-tag task-tag--custom" style="--task-tag-color: <?= e($tag['color']) ?>">#<?= e($tag['name']) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><span class="status status--<?= e($task['status']) ?>"><?= e(task_status_label($task['status'])) ?></span></td>
                    <td><?= $discipline !== '' ? e($discipline) : $emptyCell ?></td>
                    <td class="<?= e($deadlineClass) ?>"><?= e($deadlineDisplay) ?></td>
                    <td><?= $assigneeName !== '' ? e($assigneeName) : $emptyCell ?></td>
                    <td>
                        <?php if ($progress > 0): ?>
                            <div class="progress"><span class="prog-fill <?= e(progress_fill_class($progress)) ?>" style="width: <?= $progress ?>%"></span></div>
                        <?php else: ?>
                            <span class="progress-placeholder">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$tasks): ?>
                <tr><td colspan="7" class="muted">Задач пока нет.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
