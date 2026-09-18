<?php
$rows = $rows ?? [];
$compact = (bool) ($compact ?? false);
$emptyText = (string) ($emptyText ?? 'Событий пока нет.');
$actionLabels = [
    'task.created' => 'Задача создана',
    'task.changed' => 'Задача изменена',
    'task.comment' => 'Комментарий',
    'task.assignment_accepted' => 'Задача принята',
    'task.assignment_rejected' => 'Задача отклонена',
    'task.delegation_taken' => 'Делегирование взято',
    'task.delegation_returned' => 'Делегирование возвращено',
    'task.review_submitted' => 'Отправлено на проверку',
    'task.review_accepted' => 'Проверка принята',
    'task.review_rejected' => 'Возврат с проверки',
    'task.close_accepted' => 'Работа принята',
    'task.close_rejected' => 'Закрытие возвращено',
    'time.task_logged' => 'Списано время',
    'time.distributed' => 'Время распределено',
    'time.week_saved' => 'Табель сохранён',
    'time.day_repeated' => 'День повторён',
    'time.absence_logged' => 'Отсутствие',
    'project.management_task_created' => 'Задача управления проектом',
    'project.management_task_updated' => 'ГИП управления обновлён',
    'project.preproject_created' => 'Предпроект создан',
    'project.preproject_updated' => 'Предпроект обновлён',
    'project.labor_estimate_created' => 'Строка оценки создана',
    'project.labor_estimate_submitted' => 'Оценка подана',
    'project.labor_estimate_gip_approved' => 'Оценка проверена ГИПом',
    'project.labor_estimate_director_approved' => 'Оценка утверждена директором',
    'project.labor_estimate_returned' => 'Оценка возвращена',
    'project.created' => 'Проект создан',
    'project.updated' => 'Проект изменён',
    'project.archived' => 'Проект в архиве',
    'project.restored' => 'Проект восстановлен',
    'project.contact_added' => 'Контакт добавлен',
    'project.contact_deleted' => 'Контакт удалён',
    'project.msp_imported' => 'Импорт MSP',
    'project.template_tasks' => 'Шаблон задач',
    'project.folders_created' => 'Папки проекта',
    'project.schedule_synced' => 'Синхронизация графика',
    'project.data_template' => 'Шаблон ИД',
    'project.sections_template' => 'Шаблон разделов',
    'project.sections_synced' => 'Синхронизация разделов',
    'project.issues_synced' => 'Синхронизация вопросов',
    'project.exchange_synced' => 'Синхронизация обмена',
    'project.exchange_template_applied' => 'Матрица обмена',
    'project.costs_synced' => 'Синхронизация затрат',
    'project.tab_row_added' => 'Строка проекта',
    'project.tab_csv_imported' => 'CSV импорт',
    'project.cost_labor_approved' => 'Трудозатраты подтверждены',
    'project.cost_labor_rejected' => 'Трудозатраты возвращены',
];
$actionLabel = static fn (string $action): string => $actionLabels[$action] ?? $action;
?>

<div class="activity-list<?= $compact ? ' activity-list--compact' : '' ?>">
    <?php foreach ($rows as $row): ?>
        <?php
        $taskId = (int) ($row['task_id'] ?? 0);
        $projectId = (int) ($row['project_id_resolved'] ?? ($row['project_id'] ?? 0));
        ?>
        <article class="activity-item">
            <div class="activity-item__time">
                <strong><?= e(format_date($row['created_at'] ?? '')) ?></strong>
                <span><?= e(substr((string) ($row['created_at'] ?? ''), 11, 5)) ?></span>
            </div>
            <div class="activity-item__body">
                <div class="activity-item__meta">
                    <span><?= e($actionLabel((string) ($row['action'] ?? 'event'))) ?></span>
                    <?php if (!empty($row['user_name'])): ?><span><?= e($row['user_name']) ?></span><?php endif; ?>
                    <?php if (!empty($row['project_code']) && $projectId > 0): ?><a href="<?= url('/projects/' . $projectId) ?>"><?= e($row['project_code']) ?></a><?php endif; ?>
                    <?php if ($taskId > 0): ?><a href="<?= url('/tasks/' . $taskId) ?>" data-task-drawer-link>#<?= $taskId ?></a><?php endif; ?>
                </div>
                <strong><?= e($row['title'] ?? '') ?></strong>
                <?php if (trim((string) ($row['body'] ?? '')) !== ''): ?>
                    <p><?= e($row['body']) ?></p>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <div class="empty-state empty-state--compact"><span class="empty-state__icon">—</span><strong><?= e($emptyText) ?></strong></div>
    <?php endif; ?>
</div>
