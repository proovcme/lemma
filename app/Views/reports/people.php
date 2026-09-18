<?php
$filters = $filters ?? [];
$projects = $projects ?? [];
$reportUsers = $reportUsers ?? [];
$selectedUser = $selectedUser ?? null;
$model = $model ?? null;
$teamLoad = $teamLoad ?? [];
$canApproveTime = (bool) ($canApproveTime ?? false);
$timeCategories = $timeCategories ?? [];
$timePhases = $timePhases ?? [];
$employeeSearch = (string) ($employeeSearch ?? ($filters['employee_search'] ?? ''));
$profileMode = (bool) ($profileMode ?? false);
$isOwnProfile = (bool) ($isOwnProfile ?? false);
$canBrowseProfiles = (bool) ($canBrowseProfiles ?? false);
$canOpenReports = (bool) ($canOpenReports ?? true);
$revitTokens = $revitTokens ?? [];
$revitActivationCode = (string) ($revitActivationCode ?? '');
$revitActivationExpiresAt = (int) ($revitActivationExpiresAt ?? 0);
$formatNumber = static function (mixed $value, int $precision = 0): string {
    $formatted = number_format((float) $value, $precision, '.', ' ');
    return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
};
$hoursFromMinutes = static fn (mixed $minutes): string => $formatNumber(((int) $minutes) / 60, 2);
$timeStatusLabels = [
    'draft' => 'Открыто',
    'submitted' => 'На проверке',
    'approved' => 'Окнуто',
    'locked' => 'Закрыто',
];
$openTimeRows = array_filter((array) ($model['timeRows'] ?? []), static fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['draft', 'submitted'], true));
$metrics = $model['metrics'] ?? [];
$timeMetrics = $model['timeMetrics'] ?? [];
$taskCards = [
    ['label' => 'Открыто', 'value' => $formatNumber($metrics['open_tasks'] ?? 0), 'hint' => 'активные задачи'],
    ['label' => 'Просрочено', 'value' => $formatNumber($metrics['overdue_tasks'] ?? 0), 'hint' => 'горит по сроку'],
    ['label' => '7 дней', 'value' => $formatNumber($metrics['due_week_tasks'] ?? 0), 'hint' => 'ближайшие сроки'],
    ['label' => 'Проверка', 'value' => $formatNumber($metrics['review_tasks'] ?? 0), 'hint' => 'ждёт проверки'],
    ['label' => 'Корректировка', 'value' => $formatNumber($metrics['correction_tasks'] ?? 0), 'hint' => 'возвращено'],
    ['label' => 'План/факт', 'value' => $formatNumber($metrics['person_planned_hours'] ?? 0, 1) . ' / ' . $formatNumber($metrics['person_actual_hours'] ?? 0, 1), 'hint' => 'сотрудника, ч'],
];
$timeCards = [
    ['label' => 'Списано', 'value' => $hoursFromMinutes($timeMetrics['total_minutes'] ?? 0), 'hint' => 'всего часов'],
    ['label' => 'По задачам', 'value' => $hoursFromMinutes($timeMetrics['task_minutes'] ?? 0), 'hint' => 'проектные часы'],
    ['label' => 'Переработка', 'value' => $hoursFromMinutes($timeMetrics['overtime_minutes'] ?? 0), 'hint' => 'категория табеля'],
    ['label' => 'Дней', 'value' => $formatNumber($timeMetrics['work_days'] ?? 0), 'hint' => 'есть списания'],
];
$peopleResultLimit = 40;
$peopleResults = $employeeSearch !== '' ? array_slice($reportUsers, 0, $peopleResultLimit) : [];
$peopleResultHref = static function (array $reportUser) use ($filters, $employeeSearch, $profileMode): string {
    $userId = (int) ($reportUser['id'] ?? 0);
    $base = $profileMode ? url('/profiles/' . $userId) : url('/reports/people');
    $query = array_filter([
        'user_id' => $profileMode ? 0 : $userId,
        'project_id' => $filters['project_id'] ?? '',
        'date_from' => $filters['date_from'] ?? '',
        'date_to' => $filters['date_to'] ?? '',
        'period' => ($filters['period'] ?? '') === 'all' ? 'all' : '',
        'category' => $filters['category'] ?? '',
        'employee_search' => $employeeSearch,
    ], static fn (mixed $value): bool => $value !== '' && $value !== 0);

    return $base . ($query !== [] ? '?' . http_build_query($query) : '');
};
$selectedUserId = (int) ($selectedUser['id'] ?? 0);
$profileBaseHref = $profileMode ? url('/profiles/' . $selectedUserId) : url('/reports/people');
$periodResetQuery = array_filter([
    'user_id' => $profileMode ? 0 : $selectedUserId,
    'project_id' => $filters['project_id'] ?? '',
    'period' => 'all',
    'category' => $filters['category'] ?? '',
    'employee_search' => $employeeSearch,
], static fn (mixed $value): bool => $value !== '' && $value !== 0);
$periodResetHref = $profileBaseHref . ($periodResetQuery !== [] ? '?' . http_build_query($periodResetQuery) : '');
$periodLabel = ($filters['period'] ?? '') === 'all'
    ? 'весь период'
    : trim((format_date($filters['date_from'] ?? '') ?: '-') . ' - ' . (format_date($filters['date_to'] ?? '') ?: '-'));
?>

<?php if ($profileMode && $isOwnProfile && (bool) config('revit.enabled', true)): ?>
<section class="panel" id="revit-integration">
    <div class="panel__head">
        <div>
            <h2>Revit → Лемма</h2>
            <span>Подключение плагина для публикации версий IFC</span>
        </div>
        <form method="post" action="<?= url('/profile/revit/code') ?>">
            <?= csrf_field() ?>
            <button class="btn btn--red" type="submit">Создать одноразовый код</button>
        </form>
    </div>
    <?php if ($revitActivationCode !== '' && $revitActivationExpiresAt >= time()): ?>
        <div class="empty-state empty-state--compact">
            <strong class="mono"><?= e($revitActivationCode) ?></strong>
            <span>Введите этот код в плагине Revit. Код одноразовый и действует до <?= e(date('H:i', $revitActivationExpiresAt)) ?>.</span>
        </div>
    <?php endif; ?>
    <div class="table-wrap" tabindex="0" aria-label="Подключения Revit">
        <table class="data-table data-table--compact">
            <thead><tr><th>Устройство</th><th>Плагин</th><th>Создано</th><th>Последнее обращение</th><th>Статус</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($revitTokens as $token): ?>
                <tr>
                    <td><strong><?= e($token['device_name'] ?: 'Revit') ?></strong></td>
                    <td><?= e($token['plugin_version'] ?: '—') ?></td>
                    <td><?= e(format_datetime($token['created_at'] ?? '')) ?></td>
                    <td><?= e(format_datetime($token['last_used_at'] ?? '')) ?: '—' ?></td>
                    <td><span class="status-chip status-chip--<?= empty($token['revoked_at']) ? 'done' : 'pending' ?>"><?= empty($token['revoked_at']) ? 'подключено' : 'отозвано' ?></span></td>
                    <td>
                        <?php if (empty($token['revoked_at'])): ?>
                            <form method="post" action="<?= url('/profile/revit/tokens/' . (int) $token['id'] . '/revoke') ?>" data-confirm="Отозвать подключение Revit?">
                                <?= csrf_field() ?>
                                <button class="btn btn-outline btn-sm" type="submit">Отозвать</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($revitTokens === []): ?><tr><td colspan="6"><span class="muted">Подключённых Revit пока нет.</span></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<form class="panel form-grid" method="get" action="<?= e($profileBaseHref) ?>">
    <div class="panel__head form-grid__full">
        <h2>Сотрудник и период</h2>
        <div class="toolbar__actions">
            <?php if ($profileMode && !$isOwnProfile): ?>
                <a class="btn btn-outline" href="<?= url('/profile') ?>">Мой профиль</a>
            <?php endif; ?>
            <?php if ($canOpenReports): ?>
                <a class="btn btn-outline" href="<?= url('/reports') ?>">Все отчёты</a>
            <?php endif; ?>
            <a class="btn btn-outline" href="<?= e($periodResetHref) ?>">Весь период</a>
            <button class="btn" type="submit">Показать</button>
        </div>
    </div>
    <?php if (!$profileMode || $canBrowseProfiles): ?>
        <label>
            <span>Поиск сотрудника</span>
            <input type="search" name="employee_search" value="<?= e($employeeSearch) ?>" placeholder="ФИО или отдел">
        </label>
    <?php endif; ?>
    <?php if (!$profileMode): ?>
        <input type="hidden" name="user_id" value="<?= $selectedUserId ?>">
    <?php endif; ?>
    <label>
        <span>Проект</span>
        <select name="project_id">
            <option value="">Все проекты</option>
            <?php foreach ($projects as $project): ?>
                <option value="<?= (int) $project['id'] ?>"<?= selected($filters['project_id'] ?? '', $project['id']) ?>><?= e($project['code'] . ' · ' . $project['title']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label><span>Дата начала</span><input type="date" name="date_from" value="<?= e($filters['date_from'] ?? '') ?>"></label>
    <label><span>Дата конца</span><input type="date" name="date_to" value="<?= e($filters['date_to'] ?? '') ?>"></label>
    <label>
        <span>Категория времени</span>
        <select name="category">
            <option value="">Все категории</option>
            <?php foreach ($timeCategories as $category => $label): ?>
                <option value="<?= e($category) ?>"<?= selected($filters['category'] ?? '', $category) ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php if (!$profileMode || $canBrowseProfiles): ?>
    <div class="people-search-results form-grid__full">
        <div class="people-search-results__head">
            <strong>Найденные сотрудники</strong>
            <span><?= $employeeSearch !== '' ? count($reportUsers) . ' совпад.' : 'введите имя или отдел' ?></span>
        </div>
        <?php if ($employeeSearch === ''): ?>
            <p class="muted">Начните вводить ФИО или отдел, затем нажмите «Показать». Список найденных сотрудников появится здесь.</p>
        <?php elseif ($peopleResults): ?>
            <div class="people-search-results__list">
                <?php foreach ($peopleResults as $reportUser): ?>
                    <?php $isCurrent = (int) ($reportUser['id'] ?? 0) === (int) ($selectedUser['id'] ?? 0); ?>
                    <a class="people-search-result<?= $isCurrent ? ' is-current' : '' ?>" href="<?= e($peopleResultHref($reportUser)) ?>">
                        <strong><?= e($reportUser['name'] ?? '') ?></strong>
                        <span><?= e(($reportUser['department'] ?? '') ?: 'без отдела') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php if (count($reportUsers) > $peopleResultLimit): ?>
                <p class="muted">Показаны первые <?= (int) $peopleResultLimit ?>. Уточните поиск, чтобы сузить список.</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="muted">По этому поиску сотрудников не найдено.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</form>

<?php if (!$selectedUser || !$model): ?>
    <section class="panel">
        <p class="muted"><?= $employeeSearch !== '' ? 'По этому поиску сотрудников не найдено.' : 'Нет доступных сотрудников для отчёта.' ?></p>
    </section>
<?php else: ?>
    <?php if (!$profileMode || $canBrowseProfiles): ?>
    <details class="panel people-team-summary"<?= $employeeSearch !== '' ? ' open' : '' ?>>
        <summary>
            <span><?= $profileMode ? 'Доступные сотрудники' : 'Исполнители руководителя' ?></span>
            <small><?= $employeeSearch !== '' ? 'найдено: ' . count($teamLoad) : 'скрыто по умолчанию' ?></small>
        </summary>
        <div class="table-wrap">
            <table class="data-table analytics-table">
                <thead>
                <tr>
                    <th>Сотрудник</th><th>Отдел</th><th>Открыто</th><th>Проср.</th><th>План остатка</th><th>Списано</th><th>Окнуто</th><th>Проекты</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($teamLoad as $row): ?>
                    <?php
                    $rowUserId = (int) ($row['user_id'] ?? 0);
                    $teamBase = $profileMode ? url('/profiles/' . $rowUserId) : url('/reports/people');
                    $teamQuery = array_filter([
                        'user_id' => $profileMode ? 0 : $rowUserId,
                        'project_id' => $filters['project_id'] ?? '',
                        'date_from' => $filters['date_from'] ?? '',
                        'date_to' => $filters['date_to'] ?? '',
                        'period' => ($filters['period'] ?? '') === 'all' ? 'all' : '',
                        'category' => $filters['category'] ?? '',
                        'employee_search' => $employeeSearch,
                    ], static fn (mixed $value): bool => $value !== '' && $value !== 0);
                    $teamHref = $teamBase . ($teamQuery !== [] ? '?' . http_build_query($teamQuery) : '');
                    ?>
                    <tr class="<?= $rowUserId === (int) ($selectedUser['id'] ?? 0) ? 'is-current' : '' ?>">
                        <td><strong><a href="<?= e($teamHref) ?>"><?= e($row['name'] ?? '') ?></a></strong></td>
                        <td><?= e(($row['department'] ?? '') ?: '-') ?></td>
                        <td><?= e($formatNumber($row['open_tasks'] ?? 0)) ?></td>
                        <td class="<?= (int) ($row['overdue_tasks'] ?? 0) > 0 ? 'cell-danger' : '' ?>"><?= e($formatNumber($row['overdue_tasks'] ?? 0)) ?></td>
                        <td>
                            <strong><?= e($formatNumber($row['remaining_hours'] ?? 0, 1)) ?> ч</strong>
                            <small><?= e($row['load_label'] ?? '') ?> · <?= e($formatNumber($row['load_percent'] ?? 0)) ?>%</small>
                        </td>
                        <td><?= e($hoursFromMinutes($row['time_minutes'] ?? 0)) ?> ч</td>
                        <td><?= e($hoursFromMinutes((int) ($row['approved_minutes'] ?? 0) + (int) ($row['locked_minutes'] ?? 0))) ?> ч</td>
                        <td><small><?= e($row['time_project_codes'] ?: (($row['time_project_count'] ?? 0) ? $formatNumber($row['time_project_count']) . ' проектов' : '-')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($teamLoad)): ?>
                    <tr><td colspan="8"><span class="muted"><?= $employeeSearch === '' ? 'Введите поиск, чтобы показать список исполнителей.' : 'Исполнителей по поиску нет.' ?></span></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </details>
    <?php endif; ?>

    <section class="analytics-module people-drilldown">
        <div class="analytics-head">
            <div class="employee-profile-heading">
                <span class="avatar employee-profile-heading__avatar"><?= e(initials($selectedUser['name'] ?? '')) ?></span>
                <div>
                    <span class="muted"><?= e(($selectedUser['position_title'] ?? '') ?: role_label($selectedUser['role'] ?? '')) ?></span>
                    <h2><?= e($selectedUser['name'] ?? '') ?></h2>
                    <small>
                        <?= e(($selectedUser['department_name'] ?? '') ?: (($selectedUser['department'] ?? '') ?: 'без отдела')) ?>
                        <?= ($selectedUser['manager_name'] ?? '') !== '' ? ' · руководитель: ' . e($selectedUser['manager_name']) : '' ?>
                        <?= ($selectedUser['tab_number'] ?? '') !== '' ? ' · таб. ' . e($selectedUser['tab_number']) : '' ?>
                    </small>
                </div>
            </div>
            <div class="employee-profile-heading__facts"><span class="pill">Грейд: <?= e(($selectedUser['position_grade'] ?? '') ?: 'не указан') ?></span><span class="pill"><?= e($periodLabel) ?></span></div>
        </div>

        <?php view('hr/profile-card', [
            'profileReviews' => $profileReviews ?? [],
            'profileReviewMode' => $profileReviewMode ?? 'self',
            'managerQueue' => $managerQueue ?? ['total' => 0, 'ready' => 0],
        ], ''); ?>

        <div class="analytics-metrics">
            <?php foreach (array_merge($taskCards, $timeCards) as $card): ?>
                <article class="metric analytics-metric">
                    <span><?= e($card['value']) ?></span>
                    <strong><?= e($card['label']) ?></strong>
                    <small><?= e($card['hint']) ?></small>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="analytics-grid people-report-stack">
            <section class="panel analytics-panel analytics-panel--wide">
                <div class="panel__head">
                    <h2>Задачи сотрудника</h2>
                    <span class="muted">личный план, личный факт и общий факт задачи</span>
                </div>
                <div class="table-wrap" tabindex="0" aria-label="Таблица задач сотрудника">
                    <table class="data-table analytics-table">
                        <thead>
                        <tr>
                            <th>Задача</th><th>Проект</th><th>ПП / БТП</th><th>Роль</th><th>Статус</th><th>Срок</th><th>Личный план</th><th>Факт сотрудника</th><th>Факт задачи</th><th>Прогресс</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($model['tasks'] ?? []) as $task): ?>
                            <?php $deadlineClass = deadline_state_class($task['date_end'] ?? null); ?>
                            <tr class="clickable" data-href="<?= url('/tasks/' . (int) $task['id']) ?>">
                                <td>
                                    <strong>#<?= (int) $task['id'] ?> <?= e($task['title'] ?? '') ?></strong>
                                    <small><?= e(task_type_label((string) ($task['task_type'] ?? 'work'))) ?> · <?= e($task['assignee_name'] ?? 'Не назначено') ?><?= ($task['reviewer_name'] ?? '') ? ' · проверяет ' . e($task['reviewer_name']) : '' ?></small>
                                </td>
                                <td><strong><?= e($task['project_code'] ?? '') ?></strong><small><?= e($task['project_title'] ?? '') ?></small></td>
                                <td><strong><?= e($task['pp'] ?: '-') ?></strong><small><?= e($task['btp'] ?: '-') ?></small></td>
                                <td><?= e($task['person_role'] ?? '') ?></td>
                                <td><span class="status status--<?= e($task['status'] ?? '') ?>"><?= e(task_status_label((string) ($task['status'] ?? ''))) ?></span></td>
                                <td class="<?= e($deadlineClass) ?>"><?= e(format_date($task['date_end'] ?? '') ?: '-') ?></td>
                                <td><?= e($formatNumber($task['person_planned_hours'] ?? 0, 1)) ?> ч</td>
                                <td><strong><?= e($formatNumber($task['person_actual_hours'] ?? 0, 1)) ?> ч</strong></td>
                                <td><?= e($formatNumber($task['actual_hours'] ?? 0, 1)) ?> ч</td>
                                <td><?= e($formatNumber($task['progress'] ?? 0)) ?> %</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($model['tasks'])): ?>
                            <tr><td colspan="10"><span class="muted">Задач в выбранном фильтре нет.</span></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel analytics-panel">
                <div class="panel__head"><h2>Время по проектам</h2><span class="muted">табель</span></div>
                <div class="analytics-list">
                    <?php foreach (($model['timeByProject'] ?? []) as $row): ?>
                        <article class="analytics-row">
                            <div><strong><?= e($row['label'] ?? '') ?></strong><small><?= e($row['meta'] ?? '') ?></small></div>
                            <span><?= e($hoursFromMinutes($row['minutes'] ?? 0)) ?> ч</span>
                            <?php if ((int) ($row['overtime_minutes'] ?? 0) > 0): ?><span class="cell-danger"><?= e($hoursFromMinutes($row['overtime_minutes'])) ?> сверх</span><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                    <?php if (empty($model['timeByProject'])): ?><p class="muted">Списаний нет.</p><?php endif; ?>
                </div>
            </section>

            <section class="panel analytics-panel">
                <div class="panel__head"><h2>Время по задачам</h2><span class="muted">топ строк</span></div>
                <div class="analytics-list">
                    <?php foreach (($model['timeByTask'] ?? []) as $row): ?>
                        <article class="analytics-row">
                            <div><strong><?= e($row['label'] ?? '') ?></strong><small><?= e($row['meta'] ?? '') ?></small></div>
                            <span><?= e($hoursFromMinutes($row['minutes'] ?? 0)) ?> ч</span>
                        </article>
                    <?php endforeach; ?>
                    <?php if (empty($model['timeByTask'])): ?><p class="muted">Задачных списаний нет.</p><?php endif; ?>
                </div>
            </section>

            <section class="panel analytics-panel">
                <div class="panel__head"><h2>По дням</h2><span class="muted">ритм периода</span></div>
                <div class="analytics-list">
                    <?php foreach (($model['timeByDay'] ?? []) as $row): ?>
                        <article class="analytics-row">
                            <div><strong><?= e(format_date($row['label'] ?? '')) ?></strong><small><?= e($formatNumber($row['tasks_count'] ?? 0)) ?> задач</small></div>
                            <span><?= e($hoursFromMinutes($row['minutes'] ?? 0)) ?> ч</span>
                        </article>
                    <?php endforeach; ?>
                    <?php if (empty($model['timeByDay'])): ?><p class="muted">В периоде нет списаний.</p><?php endif; ?>
                </div>
            </section>

            <section class="panel analytics-panel analytics-panel--wide">
                <?php if ($canApproveTime): ?>
                <form method="post" action="<?= url('/reports/people/time-approve') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= (int) ($selectedUser['id'] ?? 0) ?>">
                    <input type="hidden" name="project_id" value="<?= e($filters['project_id'] ?? '') ?>">
                    <input type="hidden" name="date_from" value="<?= e($filters['date_from'] ?? '') ?>">
                    <input type="hidden" name="date_to" value="<?= e($filters['date_to'] ?? '') ?>">
                    <input type="hidden" name="period" value="<?= e(($filters['period'] ?? '') === 'all' ? 'all' : '') ?>">
                    <input type="hidden" name="category" value="<?= e($filters['category'] ?? '') ?>">
                    <input type="hidden" name="employee_search" value="<?= e($employeeSearch) ?>">
                <?php else: ?>
                <div>
                <?php endif; ?>
                    <div class="panel__head">
                        <div>
                            <h2>Списания времени</h2>
                            <span class="muted">проект, задача, ПП, БТП и статус строки</span>
                        </div>
                        <?php if ($canApproveTime): ?>
                            <div class="toolbar__actions">
                                <button class="btn btn-outline" type="submit" name="approve_scope" value="selected"<?= empty($openTimeRows) ? ' disabled' : '' ?>>Окнуть выбранные</button>
                                <button class="btn" type="submit" name="approve_scope" value="all"<?= empty($openTimeRows) ? ' disabled' : '' ?>>Окнуть видимые</button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="table-wrap" tabindex="0" aria-label="Таблица списаний времени сотрудника">
                        <table class="data-table analytics-table">
                            <thead>
                            <tr>
                                <?php if ($canApproveTime): ?><th></th><?php endif; ?>
                                <th>Дата</th><th>Проект</th><th>Задача</th><th>ПП / БТП</th><th>Категория</th><th>Фаза</th><th>Часы</th><th>Статус</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach (($model['timeRows'] ?? []) as $row): ?>
                                <?php $isOpenTime = in_array((string) ($row['status'] ?? ''), ['draft', 'submitted'], true); ?>
                                <tr>
                                    <?php if ($canApproveTime): ?>
                                        <td>
                                            <?php if ($isOpenTime): ?>
                                                <input type="hidden" name="visible_time_entry_ids[]" value="<?= (int) ($row['id'] ?? 0) ?>">
                                                <input type="checkbox" name="time_entry_ids[]" value="<?= (int) ($row['id'] ?? 0) ?>">
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td><?= e(format_date($row['work_date'] ?? '')) ?></td>
                                    <td><strong><?= e($row['project_code'] ?: '-') ?></strong><small><?= e($row['project_title'] ?? '') ?></small></td>
                                    <td>
                                        <?php if (!empty($row['task_id'])): ?>
                                            <strong><a href="<?= url('/tasks/' . (int) $row['task_id']) ?>">#<?= (int) $row['task_id'] ?></a></strong>
                                            <small><?= e($row['task_title'] ?? '') ?></small>
                                        <?php else: ?>
                                            <span class="muted">Без задачи</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?= e(($row['pp_code'] ?? '') ?: '-') ?></strong><small><?= e(($row['btp_code'] ?? '') ?: '-') ?><?= ($row['btp_title'] ?? '') ? ' · ' . e($row['btp_title']) : '' ?></small></td>
                                    <td><?= e($timeCategories[$row['category'] ?? ''] ?? ($row['category'] ?? '')) ?></td>
                                    <td><?= e($timePhases[$row['phase'] ?? ''] ?? ($row['phase'] ?? '')) ?></td>
                                    <td><?= e($hoursFromMinutes($row['minutes'] ?? 0)) ?></td>
                                    <td><span class="pill"><?= e($timeStatusLabels[$row['status'] ?? ''] ?? ($row['status'] ?? '')) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($model['timeRows'])): ?>
                                <tr><td colspan="<?= $canApproveTime ? 9 : 8 ?>"><span class="muted">Списаний времени в выбранном фильтре нет.</span></td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php if ($canApproveTime): ?>
                </form>
                <?php else: ?>
                </div>
                <?php endif; ?>
            </section>
        </div>
    </section>
<?php endif; ?>
