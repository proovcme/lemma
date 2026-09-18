<?php
$statuses = ['new', 'in_progress', 'review', 'correction', 'pending_close', 'done', 'blocked', 'overdue'];
$taskTypes = ['work', 'assignment', 'issuance', 'labor_estimate', 'delegation', 'bim_family_request', 'review'];
$sectionOptions = [];
foreach (($sections ?? []) as $section) {
    $sectionValue = trim((string) ($section['value'] ?? ''));
    if ($sectionValue === '' || isset($sectionOptions[$sectionValue])) {
        continue;
    }
    $sectionLabel = trim((string) ($section['label'] ?? ''));
    $sectionOptions[$sectionValue] = $sectionLabel !== '' ? $sectionLabel : $sectionValue;
}
$priorities = ['low', 'mid', 'high'];
$basePath = $basePath ?? ($scope === 'mine' ? '/tasks' : '/tasks/all');
$statusClasses = [
    'new' => 's-new',
    'in_progress' => 's-work',
    'review' => 's-review',
    'correction' => 's-correction',
    'pending_close' => 's-pclose',
    'done' => 's-done',
    'blocked' => 's-block',
    'overdue' => 's-overdue',
];
$priorityDots = ['low' => 'p-low', 'mid' => 'p-mid', 'high' => 'p-high'];
$currentUser = current_user();
$canCreateTasks = \App\Services\PermissionService::canCreateTasks($currentUser ?? []);
$assignedIssues = $assignedIssues ?? [];
$tagOptions = $tagOptions ?? [];
$dailyPicture = $dailyPicture ?? null;
$today = date('Y-m-d');
$filterValues = array_filter($filters ?? [], static fn ($value, $key): bool => !in_array($key, ['view', 'show_tags'], true) && !is_array($value) && (string) $value !== '', ARRAY_FILTER_USE_BOTH);
$showTags = !empty($filters['show_tags']);
$projectLabels = [];
foreach ($projects as $project) {
    $projectLabels[(string) $project['id']] = (string) $project['code'];
}
$userLabels = [];
foreach ($users as $taskUser) {
    $userLabels[(string) $taskUser['id']] = (string) $taskUser['name'];
}
$tagLabels = [];
foreach ($tagOptions as $tagOption) {
    $tagLabels[(string) $tagOption['slug']] = '#' . (string) $tagOption['name'];
}
$deadlineLabels = ['overdue' => 'Просрочено', 'today' => 'Сегодня', 'week' => '7 дней'];
$activeFilterLabels = [];
foreach ($filterValues as $key => $value) {
    $value = (string) $value;
    $activeFilterLabels[] = match ($key) {
        'status' => task_status_label($value),
        'task_type' => task_type_label($value),
        'priority' => 'Важность: ' . priority_label($value),
        'urgency' => 'Срочность: ' . priority_label($value),
        'discipline' => 'Раздел: ' . $value,
        'tag' => $tagLabels[$value] ?? ('#' . $value),
        'project_id' => 'Проект: ' . ($projectLabels[$value] ?? $value),
        'deadline' => 'Срок: ' . ($deadlineLabels[$value] ?? $value),
        'date_from' => 'Срок от: ' . (format_date($value) ?: $value),
        'date_to' => 'Срок до: ' . (format_date($value) ?: $value),
        'assignee_id' => 'Исполнитель: ' . ($userLabels[$value] ?? $value),
        'from_me' => 'От меня',
        'needs_review' => 'Проверка',
        'blocked_by_id' => 'Есть блокеры',
        default => $value,
    };
}
$filterCount = count($activeFilterLabels);
$viewHref = static function (string $view) use ($basePath, $filters): string {
    $query = array_filter($filters ?? [], static fn ($value): bool => (string) $value !== '');
    $query['view'] = $view;

    return url($basePath) . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
};
$filterHref = static function (array $overrides) use ($basePath, $filters, $viewMode): string {
    $query = array_filter($filters ?? [], static fn ($value): bool => !is_array($value) && (string) $value !== '');
    $query['view'] = $viewMode;
    foreach ($overrides as $key => $value) {
        if ((string) $value === '') {
            unset($query[$key]);
            continue;
        }
        $query[$key] = $value;
    }

    return url($basePath) . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
};
$projectGroups = [];
foreach ($tasks as $task) {
    $projectId = (int) ($task['project_id'] ?? 0);
    if (!isset($projectGroups[$projectId])) {
        $projectGroups[$projectId] = [
            'code' => (string) ($task['project_code'] ?? 'Без проекта'),
            'title' => (string) ($task['project_title'] ?? ''),
            'total' => 0,
            'open' => 0,
            'overdue' => 0,
            'review' => 0,
            'tasks' => [],
            'done_tasks' => [],
        ];
    }
    $projectGroups[$projectId]['total']++;
    if (($task['status'] ?? '') !== 'done') {
        $projectGroups[$projectId]['open']++;
    }
    if (($task['status'] ?? '') === 'overdue') {
        $projectGroups[$projectId]['overdue']++;
    }
    if (in_array((string) ($task['status'] ?? ''), ['review', 'pending_close', 'correction'], true)) {
        $projectGroups[$projectId]['review']++;
    }
    if (($task['status'] ?? '') === 'done') {
        $projectGroups[$projectId]['done_tasks'][] = $task;
    } else {
        $projectGroups[$projectId]['tasks'][] = $task;
    }
}
uasort($projectGroups, static fn (array $left, array $right): int => strnatcasecmp($left['code'], $right['code']));
?>
<section class="tasks-screen" data-tour-surface="task-list">
    <?php $overdueCount = count(array_filter($tasks, fn ($task) => $task['status'] === 'overdue')); ?>
    <?php if ($overdueCount > 0): ?>
        <div class="overdue-banner">
            <span>Просроченные без закрытия</span>
            <strong><?= $overdueCount ?></strong>
            <a class="overdue-action" href="<?= url($basePath) ?>?deadline=overdue&view=<?= e($viewMode) ?>">Показать</a>
        </div>
    <?php endif; ?>

    <?php if ($dailyPicture): ?>
        <?php
        $dayHref = static fn (array $query = []): string => url($basePath) . '?' . http_build_query($query + ['view' => $viewMode], '', '&', PHP_QUERY_RFC3986);
        $dayCards = [
            ['label' => 'В работе', 'value' => $dailyPicture['active'] ?? 0, 'tone' => 'blue', 'href' => $dayHref()],
            ['label' => 'Горит', 'value' => $dailyPicture['overdue'] ?? 0, 'tone' => 'red', 'href' => $dayHref(['deadline' => 'overdue'])],
            ['label' => 'Сегодня', 'value' => $dailyPicture['today'] ?? 0, 'tone' => 'amber', 'href' => $dayHref(['deadline' => 'today'])],
            ['label' => '7 дней', 'value' => $dailyPicture['week'] ?? 0, 'tone' => 'gray', 'href' => $dayHref(['deadline' => 'week'])],
            ['label' => 'Проверка', 'value' => $dailyPicture['review'] ?? 0, 'tone' => 'amber', 'href' => $dayHref(['needs_review' => 1])],
            ['label' => 'Корректировка', 'value' => $dailyPicture['correction'] ?? 0, 'tone' => 'amber', 'href' => $dayHref(['status' => 'correction'])],
            ['label' => 'Блокеры', 'value' => $dailyPicture['blocked'] ?? 0, 'tone' => 'red', 'href' => $dayHref(['status' => 'blocked'])],
            ['label' => 'Задания мне', 'value' => $dailyPicture['assignments_in'] ?? 0, 'tone' => 'blue', 'href' => $dayHref(['task_type' => 'assignment', 'assignee_id' => (int) ($currentUser['id'] ?? 0)])],
            ['label' => 'От меня', 'value' => $dailyPicture['assignments_out'] ?? 0, 'tone' => 'green', 'href' => $dayHref(['task_type' => 'assignment', 'from_me' => 1])],
        ];
        ?>
        <section class="metric-row project-summary-metrics task-day-metrics" data-tour="day-picture" aria-label="Картина дня">
            <?php foreach ($dayCards as $card): ?>
                <a class="metric metric--<?= e($card['tone']) ?>" href="<?= e($card['href']) ?>">
                    <span><?= (int) $card['value'] ?></span>
                    <strong><?= e($card['label']) ?></strong>
                </a>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <section class="task-commandbar">
        <div class="task-commandbar__main">
            <button class="btn btn-outline task-filter-toggle" type="button" data-task-filters-toggle aria-expanded="false" aria-controls="task-filter-panel">
                Фильтры<?= $filterCount > 0 ? ' · ' . $filterCount : '' ?>
            </button>
            <?php if ($activeFilterLabels): ?>
                <div class="task-filter-chips" aria-label="Активные фильтры">
                    <?php foreach (array_slice($activeFilterLabels, 0, 4) as $filterLabel): ?>
                        <span class="task-filter-chip"><?= e($filterLabel) ?></span>
                    <?php endforeach; ?>
                    <?php if ($filterCount > 4): ?>
                        <span class="task-filter-chip">+<?= $filterCount - 4 ?></span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <span class="task-commandbar__hint">Все задачи в рабочем списке</span>
            <?php endif; ?>
        </div>
        <?php if ($tagOptions): ?>
            <form class="task-tag-filter" method="get" action="<?= url($basePath) ?>">
                <?php foreach (($filters ?? []) as $key => $value): ?>
                    <?php if (in_array($key, ['tag', 'view'], true) || is_array($value) || (string) $value === ''): continue; endif; ?>
                    <input type="hidden" name="<?= e((string) $key) ?>" value="<?= e((string) $value) ?>">
                <?php endforeach; ?>
                <input type="hidden" name="view" value="<?= e($viewMode) ?>">
                <select name="tag" aria-label="Фильтр по тегу" onchange="this.form.submit()">
                    <option value="">Все теги</option>
                    <?php foreach ($tagOptions as $tagOption): ?>
                        <option value="<?= e($tagOption['slug']) ?>"<?= selected($filters['tag'] ?? '', $tagOption['slug']) ?>>#<?= e($tagOption['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <a class="btn btn-outline task-tags-toggle<?= $showTags ? ' is-active' : '' ?>" href="<?= e($filterHref(['show_tags' => $showTags ? '' : '1'])) ?>" aria-pressed="<?= $showTags ? 'true' : 'false' ?>">Теги</a>
        <?php endif; ?>
        <span class="view-switch" data-tour="task-view" data-task-view-memory data-task-view-scope="<?= e($basePath) ?>">
            <a class="view-btn<?= $viewMode === 'table' ? ' active' : '' ?>" href="<?= e($viewHref('table')) ?>" data-task-view-choice="table">Таблица</a>
            <a class="view-btn<?= $viewMode === 'board' ? ' active' : '' ?>" href="<?= e($viewHref('board')) ?>" data-task-view-choice="board">Доска</a>
        </span>
        <?php if ($canCreateTasks): ?><a class="btn btn-red" href="<?= url('/tasks/new') ?>" data-tour="task-create">+ Задача</a><?php endif; ?>
    </section>

    <form id="task-filter-panel" class="filterbar task-filter-panel" method="get" action="<?= url($basePath) ?>" data-task-filters data-tour="task-filters" aria-hidden="true">
        <select name="status">
            <option value="">Все статусы</option>
            <?php foreach ($statuses as $status): ?>
                <option value="<?= e($status) ?>"<?= selected($filters['status'] ?? '', $status) ?>><?= e(task_status_label($status)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="task_type">
            <option value="">Все типы</option>
            <?php foreach ($taskTypes as $taskType): ?>
                <option value="<?= e($taskType) ?>"<?= selected($filters['task_type'] ?? '', $taskType) ?>><?= e(task_type_label($taskType)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="priority">
            <option value="">Важность</option>
            <?php foreach ($priorities as $priority): ?>
                <option value="<?= e($priority) ?>"<?= selected($filters['priority'] ?? '', $priority) ?>><?= e(priority_label($priority)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="urgency">
            <option value="">Срочность</option>
            <?php foreach ($priorities as $urgency): ?>
                <option value="<?= e($urgency) ?>"<?= selected($filters['urgency'] ?? '', $urgency) ?>><?= e(priority_label($urgency)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="discipline">
            <option value="">Раздел</option>
            <?php foreach ($sectionOptions as $sectionValue => $sectionLabel): ?>
                <?php $sectionCaption = $sectionLabel !== $sectionValue ? $sectionValue . ' · ' . $sectionLabel : $sectionValue; ?>
                <option value="<?= e($sectionValue) ?>"<?= selected($filters['discipline'] ?? '', $sectionValue) ?>><?= e($sectionCaption) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="tag">
            <option value="">Теги</option>
            <?php foreach ($tagOptions as $tagOption): ?>
                <option value="<?= e($tagOption['slug']) ?>"<?= selected($filters['tag'] ?? '', $tagOption['slug']) ?>>#<?= e($tagOption['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="project_id">
            <option value="">Все проекты</option>
            <?php foreach ($projects as $project): ?>
                <option value="<?= (int) $project['id'] ?>"<?= selected($filters['project_id'] ?? '', $project['id']) ?>><?= e($project['code']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="deadline">
            <option value="">Все сроки</option>
            <option value="overdue"<?= selected($filters['deadline'] ?? '', 'overdue') ?>>Просрочено</option>
            <option value="today"<?= selected($filters['deadline'] ?? '', 'today') ?>>Сегодня</option>
            <option value="week"<?= selected($filters['deadline'] ?? '', 'week') ?>>7 дней</option>
        </select>
        <input type="hidden" name="view" value="<?= e($viewMode) ?>">
        <?php if (!empty($filters['show_tags'])): ?><input type="hidden" name="show_tags" value="1"><?php endif; ?>
        <?php if (!empty($filters['assignee_id'])): ?><input type="hidden" name="assignee_id" value="<?= (int) $filters['assignee_id'] ?>"><?php endif; ?>
        <?php if (!empty($filters['from_me'])): ?><input type="hidden" name="from_me" value="1"><?php endif; ?>
        <?php if (!empty($filters['needs_review'])): ?><input type="hidden" name="needs_review" value="1"><?php endif; ?>
        <?php if (!empty($filters['blocked_by_id'])): ?><input type="hidden" name="blocked_by_id" value="1"><?php endif; ?>
        <span class="task-filter-actions">
            <button class="btn btn-outline" type="submit">Фильтр</button>
            <?php if ($filterCount > 0): ?>
                <a class="btn btn-outline" href="<?= url($basePath) ?>?view=<?= e($viewMode) ?>">Сброс</a>
            <?php endif; ?>
        </span>
    </form>

<?php if ($assignedIssues): ?>
    <details class="panel assigned-issues task-queue">
        <summary class="panel__head task-queue__summary">
            <h2>Вопросы на мне</h2>
            <span class="pill pill--red"><?= count($assignedIssues) ?></span>
        </summary>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Проект</th>
                    <th>Раздел</th>
                    <th>Вопрос</th>
                    <th>Дата</th>
                    <th>Статус</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($assignedIssues as $issue): ?>
                    <tr class="clickable" data-href="<?= url('/projects/' . $issue['project_id'] . '/issues') ?>">
                        <td><?= e($issue['project_code']) ?></td>
                        <td><?= e($issue['section_code']) ?></td>
                        <td><?= e($issue['issue']) ?></td>
                        <td><?= e(format_date($issue['date_raised'])) ?></td>
                        <td><span class="status status--<?= e($issue['status']) ?>"><?= e(issue_status_label($issue['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
<?php endif; ?>

<?php if ($reviewTasks): ?>
    <details class="panel task-queue">
        <summary class="panel__head task-queue__summary">
            <h2>Ожидают проверки</h2>
            <span class="pill pill--red"><?= count($reviewTasks) ?></span>
        </summary>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Название</th>
                    <th>Проект</th>
                    <th>Статус</th>
                    <th>Этап</th>
                    <th>Исполнитель</th>
                    <th>Срок</th>
                    <th>Факт</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($reviewTasks as $task): ?>
                    <?php
                    $deadlineClass = deadline_state_class($task['date_end'] ?? null, $today);
                    $deadlineDisplay = (string) ($task['date_end'] ?? '') !== '' ? format_date($task['date_end']) : '—';
                    ?>
                    <tr class="clickable" data-href="<?= url('/tasks/' . $task['id']) ?>" data-task-drawer-href="<?= url('/tasks/' . $task['id']) ?>">
                        <td>#<?= (int) $task['id'] ?></td>
                        <td><?= e($task['title']) ?></td>
                        <td><?= e($task['project_code']) ?></td>
                        <td><span class="status status--<?= e($task['status']) ?>"><?= e(task_status_label($task['status'])) ?></span></td>
                        <td><span class="approval-stage approval-stage--<?= e($task['approval_stage'] ?? 'draft') ?>"><?= e(task_approval_stage_label($task['approval_stage'] ?? 'draft')) ?></span></td>
                        <td><?= e($task['assignee_name']) ?></td>
                        <td class="<?= e($deadlineClass) ?>"><?= e($deadlineDisplay) ?></td>
                        <td><?= $task['actual_hours'] !== null && $task['actual_hours'] !== '' ? e($task['actual_hours']) . ' ч' : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
<?php endif; ?>

<?php if ($viewMode === 'board'): ?>
    <section class="kanban" data-kanban-board data-tour="task-board" tabindex="0" aria-label="Канбан задач">
        <?php foreach (['new', 'in_progress', 'review', 'correction', 'done', 'blocked'] as $status): ?>
            <?php $columnTasks = array_values(array_filter($tasks, fn ($task) => ($task['board_status'] ?? $task['status']) === $status)); ?>
            <div class="kanban__column" data-status="<?= e($status) ?>" data-status-label="<?= e($status === 'blocked' ? 'Блок' : task_status_label($status)) ?>">
                <div class="kanban__head">
                    <h2><?= e($status === 'blocked' ? 'Блок' : task_status_label($status)) ?></h2>
                    <span class="kanban__head-actions">
                        <span data-kanban-count><?= count($columnTasks) ?></span>
                        <?php if ($canCreateTasks): ?><a class="kanban__quick-create" href="<?= url('/tasks/new') ?>" aria-label="Добавить задачу">+</a><?php endif; ?>
                    </span>
                </div>
                <div class="kanban__body" data-kanban-body>
                    <?php foreach ($columnTasks as $task): ?>
                        <?php
                        $progress = max(0, min(100, (int) $task['progress']));
                        $deadlineClass = deadline_state_class($task['date_end'] ?? null, $today);
                        $deadlineDisplay = (string) ($task['date_end'] ?? '') !== '' ? format_date($task['date_end']) : '—';
                        $isSubtask = !empty($task['parent_id']);
                        ?>
                        <article
                            class="task-card<?= $isSubtask ? ' task-card--subtask' : '' ?><?= $task['status'] === 'overdue' ? ' task-card--overdue' : '' ?>"
                            draggable="false"
                            data-task-id="<?= (int) $task['id'] ?>"
                            data-task-status="<?= e($task['status']) ?>"
                            data-task-drawer-href="<?= url('/tasks/' . $task['id']) ?>"
                            data-tour="task-card"
                            aria-grabbed="false"
                        >
                            <a href="<?= url('/tasks/' . $task['id']) ?>" data-task-drawer-link>
                                <strong>#<?= (int) $task['id'] ?> <?= e($task['title']) ?></strong>
                                <span><?= e($task['project_code']) ?> · <?= e($task['discipline']) ?> · <?= e($task['section']) ?></span>
                            </a>
                            <div class="card-meta">
                                <?php if ($isSubtask): ?><span class="tag tag-subtask">подзадача к #<?= (int) $task['parent_id'] ?></span><?php endif; ?>
                                <?php if (!$isSubtask && (int) ($task['child_count'] ?? 0) > 0): ?><span class="tag tag-subtask">подзадачи: <?= (int) $task['child_count'] ?></span><?php endif; ?>
                                <span class="<?= e($deadlineClass) ?>"><?= e($deadlineDisplay) ?></span>
                                <span class="status status--<?= e($task['status']) ?> task-card__status" data-task-status-label><?= e(task_status_label($task['status'])) ?></span>
                                <?php if (($task['task_type'] ?? 'work') === 'assignment'): ?><span class="tag tag-assignment">задание</span><?php endif; ?>
                                <?php if (($task['task_type'] ?? 'work') === 'issuance'): ?><span class="tag tag-issuance">выдача</span><?php endif; ?>
                                <?php if (($task['task_type'] ?? 'work') === 'labor_estimate'): ?><span class="tag tag-assignment">оценка</span><?php endif; ?>
                                <?php if (($task['task_type'] ?? 'work') === 'delegation'): ?><span class="tag tag-delegation">делегирование</span><?php endif; ?>
                                <?php if (($task['task_type'] ?? 'work') === 'bim_family_request'): ?><span class="tag tag-bim-family">ТИМ</span><?php endif; ?>
                                <?php if (($task['task_type'] ?? 'work') === 'review'): ?><span class="tag tag-assignment">проверка</span><?php endif; ?>
                                <?php if ($currentUser && (int) $task['author_id'] === (int) $currentUser['id'] && (int) ($task['assignee_id'] ?? 0) !== (int) $currentUser['id']): ?><span class="tag tag-from-me">от меня</span><?php endif; ?>
                                <span class="avatar avatar--small"><?= e(initials($task['assignee_name'])) ?></span>
                                <?php if ((int) $task['unread_comments'] > 0): ?><span class="pill"><?= (int) $task['unread_comments'] ?></span><?php endif; ?>
                            </div>
                            <?php if ($showTags && !empty($task['tags'])): ?>
                                <div class="task-card__tags">
                                    <?php foreach ($task['tags'] as $tag): ?>
                                        <a class="task-tag task-tag--custom" href="<?= e($filterHref(['tag' => $tag['slug']])) ?>" style="--task-tag-color: <?= e($tag['color']) ?>">#<?= e($tag['name']) ?></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($progress > 0): ?>
                                <div class="progress"><span class="prog-fill <?= e(progress_fill_class($progress)) ?>" style="width: <?= $progress ?>%"></span></div>
                            <?php else: ?>
                                <div class="progress-placeholder">—</div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php else: ?>
    <section class="task-table task-table--projects" data-tour="task-list-table">
        <?php if (!$tasks): ?>
            <div class="empty-state empty-state--table" data-tour="task-empty">
                <strong><?= $scope === 'mine' ? 'Нет задач для вас или от вас' : 'Задач пока нет' ?></strong>
                <span><?= $scope === 'mine' ? 'Откройте общий список или измените фильтры.' : 'Измените фильтры или создайте задачу верхней кнопкой.' ?></span>
                <?php if ($scope === 'mine'): ?><a class="btn btn-outline" href="<?= url('/tasks/all') ?>">Все задачи</a><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="project-task-list__filters" data-task-register-filters>
                <label>
                    <span>Задача</span>
                    <input type="search" placeholder="ID, название, метка" data-task-register-filter="search" autocomplete="off">
                </label>
                <label>
                    <span>Статус</span>
                    <select data-task-register-filter="status" data-no-search>
                        <option value="">Все</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?= e($status) ?>"><?= e(task_status_label($status)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Тип</span>
                    <select data-task-register-filter="type" data-no-search>
                        <option value="">Все</option>
                        <?php foreach ($taskTypes as $taskTypeOption): ?>
                            <option value="<?= e($taskTypeOption) ?>"><?= e(task_type_label($taskTypeOption)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Раздел</span>
                    <input type="search" placeholder="Код, том, раздел" data-task-register-filter="section" autocomplete="off">
                </label>
                <label>
                    <span>Исполнитель</span>
                    <input type="search" placeholder="ФИО" data-task-register-filter="assignee" autocomplete="off">
                </label>
                <label>
                    <span>Срок</span>
                    <input type="search" placeholder="дд.мм.гггг" data-task-register-filter="deadline" autocomplete="off">
                </label>
                <button class="btn btn-outline btn-sm" type="button" data-task-register-filter-reset>Сбросить</button>
                <span class="project-task-list__filters-count" data-task-register-filter-count><?= count($tasks) ?></span>
            </div>
        <?php endif; ?>
        <?php foreach ($projectGroups as $projectGroup): ?>
            <details class="project-task-group" open data-task-register-group>
                <summary class="project-task-group__head">
                    <span class="project-task-group__code"><?= e($projectGroup['code']) ?></span>
                    <span class="project-task-group__title"><?= e($projectGroup['title']) ?></span>
                    <span class="project-task-group__meta">
                        <b><?= (int) $projectGroup['open'] ?></b> открыто
                        <?php if ((int) $projectGroup['overdue'] > 0): ?><em><?= (int) $projectGroup['overdue'] ?> горит</em><?php endif; ?>
                        <?php if ((int) $projectGroup['review'] > 0): ?><em><?= (int) $projectGroup['review'] ?> проверка</em><?php endif; ?>
                    </span>
                </summary>
                <div class="project-task-list">
                    <div class="project-task-list__head" aria-hidden="true">
                        <span>Задача</span>
                        <span>Статус</span>
                        <span>Тип</span>
                        <span>Раздел</span>
                        <span>Исполнитель</span>
                        <span>Срок</span>
                    </div>
                <?php foreach ($projectGroup['tasks'] as $task): ?>
                    <?php
                    $progress = max(0, min(100, (int) $task['progress']));
                    $progressClass = progress_fill_class($progress);
                    $deadlineClass = deadline_state_class($task['date_end'] ?? null, $today);
                    $deadlineDisplay = (string) ($task['date_end'] ?? '') !== '' ? format_date($task['date_end']) : '—';
                    $assigneeName = (string) ($task['assignee_name'] ?? '');
                    $taskType = (string) ($task['task_type'] ?? 'work');
                    $taskTypeClass = preg_replace('/[^a-z0-9_-]+/i', '-', $taskType) ?: 'work';
                    $taskTypeLabel = task_type_label($taskType);
                    $sectionName = trim((string) ($task['section'] ?? ''));
                    $disciplineName = trim((string) ($task['discipline'] ?? ''));
                    $volumeName = trim((string) ($task['volume'] ?? ''));
                    $sectionLabel = ($sectionName !== '' && $sectionName !== $disciplineName)
                        ? $sectionName
                        : ($disciplineName === '' && $volumeName === '' ? 'Без раздела' : '');
                    $isSubtask = !empty($task['parent_id']);
                    $rowClasses = [
                        'task-row',
                        'clickable',
                        $isSubtask ? 'project-task-row--subtask' : '',
                        $task['status'] === 'overdue' ? 'overdue-row' : '',
                        in_array($task['status'], ['review', 'pending_close', 'correction'], true) ? 'review-row' : '',
                    ];
                    ?>
                    <article
                        class="<?= e(trim(implode(' ', $rowClasses))) ?> project-task-row"
                        data-href="<?= url('/tasks/' . $task['id']) ?>"
                        data-task-drawer-href="<?= url('/tasks/' . $task['id']) ?>"
                        data-tour="task-row"
                        data-task-register-row
                        data-task-register-search="<?= e(mb_strtolower(trim('#' . (int) $task['id'] . ' ' . (string) $task['title'] . ' ' . (string) ($task['parent_title'] ?? '') . ' ' . implode(' ', array_map(static fn ($tag): string => (string) ($tag['name'] ?? ''), $task['tags'] ?? []))), 'UTF-8')) ?>"
                        data-task-register-status="<?= e((string) $task['status']) ?>"
                        data-task-register-type="<?= e($taskType) ?>"
                        data-task-register-section="<?= e(mb_strtolower(trim($disciplineName . ' ' . $sectionLabel . ' ' . $volumeName), 'UTF-8')) ?>"
                        data-task-register-assignee="<?= e(mb_strtolower($assigneeName !== '' ? $assigneeName : 'Не назначен', 'UTF-8')) ?>"
                        data-task-register-deadline="<?= e(mb_strtolower($deadlineDisplay, 'UTF-8')) ?>"
                    >
                        <div class="project-task-row__main">
                            <a class="task-name" href="<?= url('/tasks/' . $task['id']) ?>" data-task-drawer-link>#<?= (int) $task['id'] ?> <?= e($task['title']) ?></a>
                            <div class="task-meta">
                                <?php if ($isSubtask): ?><span class="tag tag-subtask">подзадача к #<?= (int) $task['parent_id'] ?><?= trim((string) ($task['parent_title'] ?? '')) !== '' ? ' · ' . e($task['parent_title']) : '' ?></span><?php endif; ?>
                                <?php if (!$isSubtask && (int) ($task['child_count'] ?? 0) > 0): ?><span class="tag tag-subtask">подзадачи: <?= (int) $task['child_count'] ?></span><?php endif; ?>
                                <?php if ((int) ($task['blocking_data_waiting_count'] ?? 0) > 0): ?><span class="tag tag-blocked">есть блокеры</span><?php endif; ?>
                                <?php if ($showTags): ?>
                                    <?php foreach (array_slice(($task['tags'] ?? []), 0, 2) as $tag): ?>
                                        <a class="task-tag task-tag--custom" href="<?= e($filterHref(['tag' => $tag['slug']])) ?>" style="--task-tag-color: <?= e($tag['color']) ?>">#<?= e($tag['name']) ?></a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php if ($currentUser && (int) $task['author_id'] === (int) $currentUser['id'] && (int) ($task['assignee_id'] ?? 0) !== (int) $currentUser['id']): ?><span class="tag tag-from-me">от меня</span><?php endif; ?>
                                <?php if ((int) $task['unread_comments'] > 0): ?><span class="nav-badge"><?= (int) $task['unread_comments'] ?></span><?php endif; ?>
                            </div>
                        </div>
                        <div class="project-task-row__status">
                            <span class="task-status-chip <?= e($statusClasses[$task['status']] ?? 's-new') ?>"><?= e(task_status_label($task['status'])) ?></span>
                        </div>
                        <div class="project-task-row__type">
                            <span class="task-type-chip task-type-chip--<?= e($taskTypeClass) ?>"><?= e($taskTypeLabel) ?></span>
                        </div>
                        <div class="project-task-row__section">
                            <?php if ($disciplineName !== ''): ?><span class="section-code"><?= e($disciplineName) ?></span><?php endif; ?>
                            <?php if ($sectionLabel !== ''): ?><span><?= e($sectionLabel) ?></span><?php endif; ?>
                            <?php if ($volumeName !== ''): ?><small><?= e($volumeName) ?></small><?php endif; ?>
                        </div>
                        <div class="project-task-row__people">
                            <span class="mini-ava" style="--mini-ava-bg: <?= e(avatar_color($assigneeName)) ?>" title="<?= e($assigneeName !== '' ? $assigneeName : 'Не назначен') ?>"><?= e(initials($assigneeName)) ?></span>
                            <span><?= e($assigneeName !== '' ? $assigneeName : 'Не назначен') ?></span>
                        </div>
                        <div class="project-task-row__date <?= e($deadlineClass) ?>"><?= e($deadlineDisplay) ?></div>
                        <div class="project-task-row__progress prog-wrap<?= $progress > 0 ? '' : ' is-empty' ?>">
                            <?php if ($progress > 0): ?>
                                <span class="prog-label"><?= $progress ?>%</span>
                                <span class="prog-bar"><span class="prog-fill <?= e($progressClass) ?>" style="width: <?= $progress ?>%"></span></span>
                            <?php else: ?>
                                <span class="progress-placeholder">—</span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$projectGroup['tasks']): ?>
                    <div class="project-task-empty">Открытых задач нет</div>
                <?php endif; ?>
                <?php if ($projectGroup['done_tasks']): ?>
                    <details class="project-task-done">
                        <summary>Готово · <?= count($projectGroup['done_tasks']) ?></summary>
                        <?php foreach ($projectGroup['done_tasks'] as $task): ?>
                            <?php
                            $progress = max(0, min(100, (int) $task['progress']));
                            $progressClass = progress_fill_class($progress);
                            $deadlineClass = deadline_state_class($task['date_end'] ?? null, $today);
                            $deadlineDisplay = (string) ($task['date_end'] ?? '') !== '' ? format_date($task['date_end']) : '—';
                            $assigneeName = (string) ($task['assignee_name'] ?? '');
                            $taskType = (string) ($task['task_type'] ?? 'work');
                            $taskTypeClass = preg_replace('/[^a-z0-9_-]+/i', '-', $taskType) ?: 'work';
                            $taskTypeLabel = task_type_label($taskType);
                            $sectionName = trim((string) ($task['section'] ?? ''));
                            $disciplineName = trim((string) ($task['discipline'] ?? ''));
                            $volumeName = trim((string) ($task['volume'] ?? ''));
                            $sectionLabel = ($sectionName !== '' && $sectionName !== $disciplineName)
                                ? $sectionName
                                : ($disciplineName === '' && $volumeName === '' ? 'Без раздела' : '');
                            $isSubtask = !empty($task['parent_id']);
                            ?>
                            <article
                                class="task-row clickable project-task-row project-task-row--done<?= $isSubtask ? ' project-task-row--subtask' : '' ?>"
                                data-href="<?= url('/tasks/' . $task['id']) ?>"
                                data-task-drawer-href="<?= url('/tasks/' . $task['id']) ?>"
                                data-task-register-row
                                data-task-register-search="<?= e(mb_strtolower(trim('#' . (int) $task['id'] . ' ' . (string) $task['title'] . ' ' . (string) ($task['parent_title'] ?? '') . ' ' . implode(' ', array_map(static fn ($tag): string => (string) ($tag['name'] ?? ''), $task['tags'] ?? []))), 'UTF-8')) ?>"
                                data-task-register-status="<?= e((string) $task['status']) ?>"
                                data-task-register-type="<?= e($taskType) ?>"
                                data-task-register-section="<?= e(mb_strtolower(trim($disciplineName . ' ' . $sectionLabel . ' ' . $volumeName), 'UTF-8')) ?>"
                                data-task-register-assignee="<?= e(mb_strtolower($assigneeName !== '' ? $assigneeName : 'Не назначен', 'UTF-8')) ?>"
                                data-task-register-deadline="<?= e(mb_strtolower($deadlineDisplay, 'UTF-8')) ?>"
                            >
                                <div class="project-task-row__main">
                                    <a class="task-name" href="<?= url('/tasks/' . $task['id']) ?>" data-task-drawer-link>#<?= (int) $task['id'] ?> <?= e($task['title']) ?></a>
                                    <div class="task-meta">
                                        <?php if ($isSubtask): ?><span class="tag tag-subtask">подзадача к #<?= (int) $task['parent_id'] ?><?= trim((string) ($task['parent_title'] ?? '')) !== '' ? ' · ' . e($task['parent_title']) : '' ?></span><?php endif; ?>
                                        <?php if (!$isSubtask && (int) ($task['child_count'] ?? 0) > 0): ?><span class="tag tag-subtask">подзадачи: <?= (int) $task['child_count'] ?></span><?php endif; ?>
                                    </div>
                                </div>
                                <div class="project-task-row__status">
                                    <span class="task-status-chip <?= e($statusClasses[$task['status']] ?? 's-done') ?>"><?= e(task_status_label($task['status'])) ?></span>
                                </div>
                                <div class="project-task-row__type">
                                    <span class="task-type-chip task-type-chip--<?= e($taskTypeClass) ?>"><?= e($taskTypeLabel) ?></span>
                                </div>
                                <div class="project-task-row__section">
                                    <?php if ($disciplineName !== ''): ?><span class="section-code"><?= e($disciplineName) ?></span><?php endif; ?>
                                    <?php if ($sectionLabel !== ''): ?><span><?= e($sectionLabel) ?></span><?php endif; ?>
                                    <?php if ($volumeName !== ''): ?><small><?= e($volumeName) ?></small><?php endif; ?>
                                </div>
                                <div class="project-task-row__people">
                                    <span class="mini-ava" style="--mini-ava-bg: <?= e(avatar_color($assigneeName)) ?>" title="<?= e($assigneeName !== '' ? $assigneeName : 'Не назначен') ?>"><?= e(initials($assigneeName)) ?></span>
                                    <span><?= e($assigneeName !== '' ? $assigneeName : 'Не назначен') ?></span>
                                </div>
                                <div class="project-task-row__date <?= e($deadlineClass) ?>"><?= e($deadlineDisplay) ?></div>
                                <div class="project-task-row__progress prog-wrap<?= $progress > 0 ? '' : ' is-empty' ?>">
                                    <?php if ($progress > 0): ?>
                                        <span class="prog-label"><?= $progress ?>%</span>
                                        <span class="prog-bar"><span class="prog-fill <?= e($progressClass) ?>" style="width: <?= $progress ?>%"></span></span>
                                    <?php else: ?>
                                        <span class="progress-placeholder">—</span>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </details>
                <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
        <?php if ($tasks): ?>
            <div class="project-task-register-empty" data-task-register-empty hidden>По фильтрам задач не найдено</div>
        <?php endif; ?>
    </section>
<?php endif; ?>
</section>
