<?php
$isArchived = (bool) ($isArchived ?? false);
$formatHours = static function (mixed $value): string {
    $formatted = number_format((float) $value, 1, '.', ' ');

    return str_ends_with($formatted, '.0') ? substr($formatted, 0, -2) : $formatted;
};
$formatMoney = static function (mixed $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    $formatted = number_format((float) $value, 2, '.', ' ');

    return rtrim(rtrim($formatted, '0'), '.');
};
$formatBudgetInput = static function (mixed $value): string {
    if ($value === null || $value === '') {
        return '';
    }
    return rtrim(rtrim(number_format((float) $value, 2, '.', ' '), '0'), '.');
};
$projectId = (int) ($project['id'] ?? 0);
$projectTasksHref = static fn (array $params = []): string => url('/tasks/all?' . http_build_query([
    'project_id' => $projectId,
] + $params));
$modelLinks = $modelLinks ?? [];
$folderModels = $folderModels ?? [];
$modelFolderScan = $modelFolderScan ?? [];
$modelFolderErrors = array_values(array_filter((array) ($modelFolderScan['errors'] ?? [])));
$projectTasks = $projectTasks ?? [];
$members = $members ?? [];
$users = $users ?? [];
$canViewProjectStats = (bool) ($canViewProjectStats ?? false);
$canViewProjectFinance = (bool) ($canViewProjectFinance ?? false);
$canManageDepartmentBudget = (bool) ($canManageDepartmentBudget ?? false);
$projectPayments = (array) ($projectPayments ?? []);
$financeSummary = $financeSummary ?? [];
$projectControl = $projectControl ?? [];
$projectQuality = (array) ($projectControl['quality'] ?? []);
$projectWorkControl = (array) ($projectControl['work'] ?? []);
$projectDataControl = (array) ($projectControl['data'] ?? []);
$projectBudgetControl = (array) ($projectControl['budget'] ?? []);
$projectControlRisks = (array) ($projectControl['risks'] ?? []);
$processControl = $processControl ?? [];
$processOverall = (array) ($processControl['overall'] ?? []);
$processStatusRows = (array) ($processControl['status_rows'] ?? []);
$processDepartments = (array) ($processControl['departments'] ?? []);
$processSlowTasks = (array) ($processControl['slow_tasks'] ?? []);
$processBottlenecks = (array) ($processControl['bottlenecks'] ?? []);
$today = date('Y-m-d');
$projectTaskAssignees = [];
foreach ($projectTasks as $task) {
    $assigneeId = (int) ($task['assignee_id'] ?? 0);
    $assigneeName = trim((string) ($task['assignee_name'] ?? ''));
    if ($assigneeId > 0 && $assigneeName !== '') {
        $projectTaskAssignees[$assigneeId] = $assigneeName;
    }
}
asort($projectTaskAssignees, SORT_NATURAL | SORT_FLAG_CASE);
$projectTaskStatuses = [
    'new' => 'Новые',
    'in_progress' => 'В работе',
    'review' => 'Проверка',
    'correction' => 'Корректировка',
    'done' => 'Готово',
    'blocked' => 'Блок',
];
$projectTasksByStatus = array_fill_keys(array_keys($projectTaskStatuses), []);
foreach ($projectTasks as $task) {
    $status = (string) ($task['status'] ?? 'new');
    $boardStatus = (string) ($task['board_status'] ?? $status);
    $column = array_key_exists($boardStatus, $projectTaskStatuses) ? $boardStatus : 'new';
    $projectTasksByStatus[$column][] = $task;
}
$datedTaskMonths = [];
foreach ($projectTasks as $task) {
    $date = (string) ($task['date_end'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $datedTaskMonths[] = $date;
    }
}
$requestedTaskMonth = (string) ($_GET['task_month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $requestedTaskMonth)) {
    $futureDates = array_values(array_filter($datedTaskMonths, static fn (string $date): bool => $date >= date('Y-m-d')));
    sort($futureDates);
    sort($datedTaskMonths);
    $requestedTaskMonth = substr($futureDates[0] ?? ($datedTaskMonths[0] ?? date('Y-m-d')), 0, 7);
}
$taskMonthStart = new DateTimeImmutable($requestedTaskMonth . '-01');
$taskCalendarStart = $taskMonthStart->modify('monday this week');
$taskCalendarEnd = $taskMonthStart->modify('last day of this month')->modify('sunday this week');
$taskCalendarDays = [];
for ($day = $taskCalendarStart; $day <= $taskCalendarEnd; $day = $day->modify('+1 day')) {
    $taskCalendarDays[] = $day;
}
$projectTasksByDate = [];
foreach ($projectTasks as $task) {
    $date = (string) ($task['date_end'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $projectTasksByDate[$date][] = $task;
    }
}
$taskCalendarHref = static fn (DateTimeImmutable $month): string => url('/projects/' . $projectId . '?' . http_build_query(['task_month' => $month->format('Y-m')]) . '#project-task-calendar');
$taskCalendarWeekdays = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
$atlasFederationHref = (string) ($atlasFederationHref ?? '');
$projectPublicUrl = (string) ($projectPublicUrl ?? '');
$modelCount = count($modelLinks) + count($folderModels);
$projectModelOpenHref = $atlasFederationHref;
if ($projectModelOpenHref === '') {
    foreach ($folderModels as $folderModel) {
        if (!empty($folderModel['atlas_href'])) {
            $projectModelOpenHref = (string) $folderModel['atlas_href'];
            break;
        }
    }
}
if ($projectModelOpenHref === '') {
    foreach ($modelLinks as $modelLink) {
        if (!empty($modelLink['atlas_href'])) {
            $projectModelOpenHref = (string) $modelLink['atlas_href'];
            break;
        }
    }
}
$autoPrepareModels = (bool) ($autoPrepareModels ?? false);
$pendingFolderModels = array_values(array_filter($folderModels, static fn (array $model): bool => ($model['fragment_status'] ?? '') === 'pending'));
$kindLabels = ['json' => 'JSON', 'ifc' => 'IFC', 'ifczip' => 'IFC ZIP', 'frag' => 'FRAG'];
$projectStatusLabels = ['active' => 'Активный', 'archived' => 'Архивный'];
$projectStatus = (string) ($project['status'] ?? 'active');
$projectDateRange = trim((format_date($project['start_date'] ?? '') ?: 'не задано') . ' — ' . (format_date($project['finish_date'] ?? '') ?: 'не задано'));
$requestedProjectSection = (string) ($_GET['section'] ?? '');
$showProjectTeam = $requestedProjectSection === 'team';
$showDemoProjectSettings = !app_is_demo_mode();
$showDemoProjectModels = !app_is_demo_mode() || $modelCount > 0 || !empty($folderModels) || $atlasFederationHref !== '';
?>
<?php if ($isArchived): ?>
    <div class="archive-banner">
        Проект в архиве · <?= e(format_date($project['archived_at'] ?? '') ?: 'дата не указана') ?>
    </div>
    <?php if (!empty($canArchive)): ?>
        <form id="project-clone" method="post" action="<?= url('/projects/' . $project['id'] . '/clone') ?>">
            <?= csrf_field() ?>
        </form>
    <?php endif; ?>
<?php endif; ?>

<section class="project-head project-passport-head">
    <div class="project-passport-title">
        <span class="project-eyebrow"><?= e($project['stage']) ?> · <?= e($project['object']) ?></span>
        <h2>Паспорт проекта</h2>
        <p><?= e($project['code']) ?> · <?= e($project['title']) ?></p>
    </div>
    <div class="project-roles" aria-label="Ключевые роли проекта">
        <span class="project-role-card project-role-card--lead">
            <small>ГИП</small>
            <strong><?= e($project['gip_name'] ?: 'не указан') ?></strong>
        </span>
        <span class="project-role-card project-role-card--lead">
            <small>РП</small>
            <strong><?= e($project['rp_name'] ?: 'не указан') ?></strong>
        </span>
        <span class="project-role-card">
            <small>Статус</small>
            <strong><?= e($projectStatusLabels[$projectStatus] ?? $projectStatus) ?></strong>
        </span>
        <span class="project-role-card project-role-card--wide">
            <small>Сроки</small>
            <strong><?= e($projectDateRange) ?></strong>
        </span>
    </div>
</section>

<?php if (!empty($canEdit) && !empty($editMode)): ?>
    <?php require BASE_PATH . '/app/Views/projects/form.php'; ?>
<?php endif; ?>

<?php
$hasProjectFiles = !empty($project['file_folder_url']);
if ($hasProjectFiles) {
    $renderOpenFolderForm = static function (string $relativePath, string $label, string $fullPath, string $class, ?string $small = null) use ($project): void {
        echo '<form class="folder-open-form" method="post" action="' . e(url('/projects/' . $project['id'] . '/folders/open')) . '" data-folder-open-form>';
        echo csrf_field();
        echo '<input type="hidden" name="path" value="' . e($relativePath) . '">';
        echo '<button class="' . e($class) . '" type="submit" title="' . e($fullPath) . '" data-folder-open-button data-folder-path="' . e($fullPath) . '" data-folder-open-url="' . e(file_link_href($fullPath)) . '">';
        echo '<span>' . e($label) . '</span>';
        if ($small !== null) {
            echo '<small>' . e($small) . '</small>';
        }
        echo '</button>';
        echo '</form>';
    };
    $renderFolderTree = static function (array $items, string $root) use (&$renderFolderTree, $renderOpenFolderForm): void {
        echo '<ul class="folder-tree">';
        foreach ($items as $item) {
            $fullPath = file_path_join($root, $item['path']);
            echo '<li>';
            $renderOpenFolderForm((string) $item['path'], (string) $item['label'], $fullPath, 'folder-tree__link', (string) $item['path']);
            if (!empty($item['children'])) {
                $renderFolderTree($item['children'], $root);
            }
            echo '</li>';
        }
        echo '</ul>';
    };
}
?>

<section class="panel project-model-menu project-visible-models" id="project-visible-models">
    <div>
        <strong>Модели проекта<?= $modelCount > 0 ? ' · ' . $modelCount : '' ?></strong>
        <span><?= app_is_demo_mode() ? 'Быстрый просмотр подключенной модели проекта.' : 'Быстрый просмотр модели проекта. Настройка папки и ссылок находится ниже.' ?></span>
    </div>
    <div class="actions-inline">
        <?php if ($projectModelOpenHref !== ''): ?>
            <a class="btn btn--red" href="<?= e($projectModelOpenHref) ?>" target="_blank" rel="noreferrer">Открыть модель</a>
        <?php elseif (!app_is_demo_mode()): ?>
            <a class="btn btn-outline" href="#project-models">Добавить папку с моделями</a>
        <?php endif; ?>
        <?php if ($projectPublicUrl !== ''): ?>
            <button class="btn btn-outline" type="button" data-copy-link="<?= e($projectPublicUrl) ?>">Ссылка на проект</button>
        <?php endif; ?>
        <?php if (!app_is_demo_mode()): ?>
            <a class="btn btn-outline" href="#project-models">Настроить модели</a>
        <?php endif; ?>
    </div>
</section>

<?php $projectNavActive = $showProjectTeam ? 'team' : 'summary'; require BASE_PATH . '/app/Views/projects/_navigation.php'; ?>

<nav class="project-summary-toc" aria-label="Оглавление сводки проекта">
    <strong>Содержание сводки</strong>
    <div>
        <?php if ($canViewProjectStats): ?><a href="#project-summary-kpi">Показатели</a><?php endif; ?>
        <?php if ($canViewProjectFinance): ?><a href="#project-budget">Бюджет</a><?php endif; ?>
        <?php if ($canViewProjectStats && $projectControl): ?><a href="#project-control">Контроль</a><?php endif; ?>
        <?php if ($canViewProjectStats && $processControl): ?><a href="#project-process-control">Процесс</a><?php endif; ?>
        <a href="#project-tasks">Задачи</a>
        <a href="#project-task-calendar">Календарь</a>
        <a href="#project-contacts">Контакты</a>
        <a href="<?= url('/projects/' . $projectId . '?section=team') ?>#project-team">Команда</a>
        <a href="#project-models">Модели</a>
        <a href="#project-history">История</a>
        <?php if ($showDemoProjectSettings): ?><a href="#project-settings">Настройки</a><?php endif; ?>
    </div>
</nav>

<div class="project-detail-stack">
    <div class="project-main-column">
        <?php if ($canViewProjectStats): ?>
            <section class="metric-row project-summary-metrics" id="project-summary-kpi">
                <a class="metric" href="<?= e($projectTasksHref()) ?>"><span><?= (int) ($summary['total'] ?? 0) ?></span><strong>Всего задач</strong></a>
                <a class="metric" href="<?= e($projectTasksHref(['status' => 'done'])) ?>"><span><?= (int) ($summary['done'] ?? 0) ?></span><strong>Закрыто</strong></a>
                <a class="metric" href="<?= e($projectTasksHref(['status' => 'blocked'])) ?>"><span><?= (int) ($summary['blocked'] ?? 0) ?></span><strong>Блокеры</strong></a>
                <a class="metric" href="<?= e($projectTasksHref(['deadline' => 'overdue'])) ?>"><span><?= (int) ($summary['overdue'] ?? 0) ?></span><strong>Просрочено</strong></a>
                <a class="metric" href="<?= url('/projects/' . $projectId . '/gantt') ?>"><span><?= (int) ($summary['avg_progress'] ?? 0) ?>%</span><strong>Средний прогресс</strong></a>
                <a class="metric" href="<?= e($projectTasksHref()) ?>"><span><?= e($formatHours($summary['planned_hours'] ?? 0)) ?></span><strong>План, ч</strong></a>
                <a class="metric" href="<?= e($projectTasksHref()) ?>"><span><?= e($formatHours($summary['actual_hours'] ?? 0)) ?></span><strong>Факт, ч</strong></a>
                <a class="metric" href="<?= e($projectTasksHref()) ?>"><span><?= (int) ($summary['hours_fact_percent'] ?? 0) ?>%</span><strong>Факт / план</strong></a>
                <a class="metric" href="<?= e($projectTasksHref()) ?>"><span><?= e($formatHours($summary['remaining_hours'] ?? 0)) ?></span><strong>Остаток, ч</strong></a>
                <?php if ($canViewProjectFinance): ?>
                    <a class="metric" href="<?= url('/projects/' . $projectId . '/costs') ?>"><span><?= e($formatHours($summary['planned_labor_hours_total'] ?? 0)) ?></span><strong>Оценка, чел-ч</strong></a>
                    <a class="metric" href="<?= url('/projects/' . $projectId . '/costs') ?>"><span><?= e($formatHours($summary['planned_cost_total'] ?? 0)) ?></span><strong>План затрат, тыс. руб.</strong></a>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($canViewProjectFinance): ?>
            <section class="panel project-budget-panel" id="project-budget">
                <div class="panel__head">
                    <div>
                        <h2>Бюджет проекта</h2>
                        <span class="muted">ручной бюджет и автофакт по списанному времени</span>
                    </div>
                    <a class="btn btn-outline" href="<?= url('/projects/' . $projectId . '/costs') ?>">План затрат по СБЦ</a>
                </div>
                <section class="metric-row project-summary-metrics cost-summary-metrics">
                    <div class="metric"><span><?= e($formatMoney($project['budget_cost_thousand'] ?? 0)) ?></span><strong>Затраты, тыс. ₽</strong></div>
                    <div class="metric"><span><?= e($formatMoney($project['budget_profit_thousand'] ?? 0)) ?></span><strong>Прибыль, тыс. ₽</strong></div>
                    <div class="metric"><span><?= e($formatMoney($project['budget_bonus_thousand'] ?? 0)) ?></span><strong>Премиальная часть, тыс. ₽</strong></div>
                    <div class="metric"><span><?= e($formatMoney($project['budget_manual_thousand'] ?? null)) ?></span><strong>Общий бюджет, тыс. ₽</strong></div>
                    <div class="metric"><span><?= e($formatMoney($financeSummary['actual_cost_thousand'] ?? 0)) ?></span><strong>Факт затрат, тыс. руб.</strong></div>
                    <div class="metric"><span><?= e($formatMoney($financeSummary['budget_remaining_thousand'] ?? null)) ?></span><strong>Остаток бюджета</strong></div>
                    <div class="metric"><span><?= e($formatHours($financeSummary['actual_hours'] ?? 0)) ?></span><strong>Факт, ч</strong></div>
                </section>
            </section>
        <?php endif; ?>

        <?php if ($canViewProjectStats && $projectControl): ?>
            <section class="panel project-control-panel" id="project-control">
                <div class="panel__head">
                    <div>
                        <h2>Контроль проекта</h2>
                        <span class="muted">качество, сроки, план/факт и узкие места</span>
                    </div>
                </div>
                <section class="metric-row project-summary-metrics cost-summary-metrics">
                    <div class="metric metric--<?= e($projectQuality['status'] ?? 'green') ?>"><span><?= (int) ($projectQuality['score'] ?? 100) ?>%</span><strong>Индекс качества</strong></div>
                    <div class="metric"><span><?= (int) ($projectQuality['first_pass_ratio'] ?? 100) ?>%</span><strong>Без возврата</strong></div>
                    <div class="metric"><span><?= e($formatHours($projectWorkControl['planned_hours'] ?? 0)) ?> / <?= e($formatHours($projectWorkControl['actual_hours'] ?? 0)) ?></span><strong>План / факт, ч</strong></div>
                    <div class="metric"><span><?= e($formatHours($projectWorkControl['remaining_hours'] ?? 0)) ?></span><strong>Остаток, ч</strong></div>
                    <div class="metric"><span><?= (int) ($projectQuality['overdue_tasks'] ?? 0) ?></span><strong>Просрочено</strong></div>
                    <div class="metric"><span><?= (int) ($projectDataControl['tasks_without_btp'] ?? 0) ?></span><strong>Без БТП</strong></div>
                    <?php if ($canViewProjectFinance && $projectBudgetControl): ?>
                        <div class="metric"><span><?= e($formatMoney($projectBudgetControl['actual_total_thousand'] ?? 0)) ?></span><strong>Факт затрат, тыс. руб.</strong></div>
                        <div class="metric"><span><?= e($formatMoney($projectBudgetControl['forecast_total_thousand'] ?? 0)) ?></span><strong>Прогноз, тыс. руб.</strong></div>
                        <div class="metric"><span><?= e($formatMoney($projectBudgetControl['forecast_remaining_thousand'] ?? null)) ?></span><strong>Остаток прогноза</strong></div>
                    <?php endif; ?>
                </section>
                <div class="project-control-grid">
                    <div>
                        <h3>Качество</h3>
                        <table class="data-table data-table--compact data-no-column-filters">
                            <tbody>
                                <tr><th>Сроки без просрочки</th><td><?= (int) ($projectQuality['deadline_score'] ?? 100) ?>%</td></tr>
                                <tr><th>План/факт без перерасхода</th><td><?= (int) ($projectQuality['plan_fact_score'] ?? 100) ?>%</td></tr>
                                <tr><th>Заполнение ПП/БТП</th><td><?= (int) ($projectQuality['data_score'] ?? 100) ?>%</td></tr>
                                <tr><th>Возвраты и проверки</th><td><?= (int) ($projectQuality['review_flow_score'] ?? 100) ?>%</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($canViewProjectFinance && $projectBudgetControl): ?>
                        <div>
                            <h3>Состав бюджета</h3>
                            <table class="data-table data-table--compact data-no-column-filters">
                                <tbody>
                                    <tr><th>Ручной бюджет</th><td><?= e($formatMoney($projectBudgetControl['manual_thousand'] ?? null)) ?> тыс. руб.</td></tr>
                                    <tr><th>Трудозатраты факт</th><td><?= e($formatMoney($projectBudgetControl['time_actual_thousand'] ?? 0)) ?> тыс. руб.</td></tr>
                                    <tr><th>УТС факт</th><td><?= e($formatMoney($projectBudgetControl['uts_actual_thousand'] ?? 0)) ?> тыс. руб.</td></tr>
                                    <tr><th>Прогноз до завершения</th><td><?= e($formatMoney($projectBudgetControl['forecast_total_thousand'] ?? 0)) ?> тыс. руб.</td></tr>
                                    <tr><th>Освоение бюджета</th><td><?= $projectBudgetControl['burn_percent'] !== null ? (int) $projectBudgetControl['burn_percent'] . '%' : '—' ?></td></tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($projectControlRisks): ?>
                    <div class="project-control-risks">
                        <h3>Узкие места</h3>
                        <ul>
                            <?php foreach ($projectControlRisks as $risk): ?>
                                <li class="risk-item risk-item--<?= e($risk['level'] ?? 'yellow') ?>">
                                    <strong><?= e($risk['title'] ?? '') ?></strong>
                                    <span><?= e($risk['detail'] ?? '') ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <div class="empty-state empty-state--compact">
                        <strong>Критичных узких мест не найдено</strong>
                        <p>Контроль не видит просрочек, возвратов, незаполненных БТП или бюджетного риска.</p>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($canViewProjectStats && $processControl): ?>
            <section class="panel project-control-panel process-control-panel" id="project-process-control">
                <div class="panel__head">
                    <div>
                        <h2>Процессный контроль</h2>
                        <span class="muted">очереди, возвраты и движение задач без внешних сервисов</span>
                    </div>
                </div>
                <section class="metric-row project-summary-metrics cost-summary-metrics">
                    <div class="metric"><span><?= (int) ($processOverall['open_tasks'] ?? 0) ?></span><strong>Открыто</strong></div>
                    <div class="metric"><span><?= e($formatHours($processOverall['avg_review_wait_days'] ?? 0)) ?></span><strong>Ожидание проверки, дн.</strong></div>
                    <div class="metric"><span><?= (int) ($processOverall['correction_loops'] ?? 0) ?></span><strong>Возвраты</strong></div>
                    <div class="metric"><span><?= e($formatHours($processOverall['rework_hours'] ?? 0)) ?></span><strong>Переделки, ч</strong></div>
                    <div class="metric"><span><?= (int) ($processOverall['issuance_iterations'] ?? 0) ?></span><strong>Повторные выдачи</strong></div>
                    <div class="metric"><span><?= (int) ($processOverall['atlas_tasks'] ?? 0) ?></span><strong>Из модели</strong></div>
                </section>
                <?php if ($processBottlenecks): ?>
                    <div class="project-control-risks process-control-bottlenecks">
                        <h3>Сигналы процесса</h3>
                        <ul>
                            <?php foreach ($processBottlenecks as $risk): ?>
                                <li class="risk-item risk-item--<?= e($risk['level'] ?? 'yellow') ?>">
                                    <strong><?= e($risk['title'] ?? '') ?></strong>
                                    <span><?= e($risk['detail'] ?? '') ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <div class="project-control-grid process-control-grid">
                    <div>
                        <h3>По статусам</h3>
                        <div class="table-wrap table-wrap--compact">
                            <table class="data-table data-table--compact data-no-column-filters">
                                <thead><tr><th>Статус</th><th>Задач</th><th>Среднее ожидание</th><th>Максимум</th></tr></thead>
                                <tbody>
                                    <?php foreach ($processStatusRows as $row): ?>
                                        <tr>
                                            <td><strong><?= e($row['label'] ?? $row['status'] ?? '—') ?></strong></td>
                                            <td><?= (int) ($row['count'] ?? 0) ?></td>
                                            <td><?= (int) ($row['avg_age_days'] ?? 0) ?> дн.</td>
                                            <td><?= (int) ($row['max_age_days'] ?? 0) ?> дн.</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$processStatusRows): ?>
                                        <tr><td colspan="4">Нет данных по статусам.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div>
                        <h3>По отделам</h3>
                        <div class="table-wrap table-wrap--compact">
                            <table class="data-table data-table--compact data-no-column-filters">
                                <thead><tr><th>Отдел</th><th>Открыто</th><th>Просрочено</th><th>Проверка</th><th>Возвраты</th><th>Переделки</th></tr></thead>
                                <tbody>
                                    <?php foreach ($processDepartments as $row): ?>
                                        <tr>
                                            <td><strong><?= e($row['department'] ?? 'Без отдела') ?></strong></td>
                                            <td><?= (int) ($row['open_tasks'] ?? 0) ?></td>
                                            <td><?= (int) ($row['overdue_tasks'] ?? 0) ?></td>
                                            <td><?= (int) ($row['review_tasks'] ?? 0) ?></td>
                                            <td><?= (int) ($row['correction_loops'] ?? 0) ?></td>
                                            <td><?= e($formatHours($row['rework_hours'] ?? 0)) ?> ч</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$processDepartments): ?>
                                        <tr><td colspan="6">Нет данных по отделам.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div>
                        <h3>Застрявшие задачи</h3>
                        <div class="table-wrap table-wrap--compact">
                            <table class="data-table data-table--compact data-no-column-filters">
                                <thead><tr><th>Задача</th><th>Статус</th><th>Исполнитель</th><th>Дней</th></tr></thead>
                                <tbody>
                                    <?php foreach ($processSlowTasks as $task): ?>
                                        <tr class="clickable" data-href="<?= url('/tasks/' . (int) $task['id']) ?>" data-task-drawer-href="<?= url('/tasks/' . (int) $task['id']) ?>">
                                            <td><strong>#<?= (int) $task['id'] ?> · <?= e($task['title'] ?? '') ?></strong><small><?= e($task['project_code'] ?? '') ?> · <?= e($task['department'] ?? '') ?></small></td>
                                            <td><?= e($task['status_label'] ?? '') ?></td>
                                            <td><?= e(($task['assignee_name'] ?? '') ?: 'не назначен') ?></td>
                                            <td><?= (int) ($task['age_days'] ?? 0) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$processSlowTasks): ?>
                                        <tr><td colspan="4">Застрявших задач не найдено.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section class="panel project-task-board-panel" id="project-tasks">
            <div class="panel__head">
                <div>
                    <h2>Задачи проекта</h2>
                    <span class="muted">канбан по статусам сразу на сводке</span>
                </div>
                <div class="actions-inline">
                    <a class="btn btn-outline" href="#project-task-calendar">Календарь</a>
                    <a class="btn btn-outline" href="<?= e($projectTasksHref()) ?>">Открыть задачи</a>
                </div>
            </div>
            <?php if ($projectTasks): ?>
                <div class="project-task-filterbar" data-project-task-filters>
                    <label>
                        <span>Поиск</span>
                        <input type="search" placeholder="ID, название, раздел" data-project-task-filter="search" autocomplete="off">
                    </label>
                    <label>
                        <span>Статус</span>
                        <select data-project-task-filter="status" data-no-search>
                            <option value="">Все статусы</option>
                            <?php foreach ($projectTaskStatuses as $status => $statusLabel): ?>
                                <option value="<?= e($status) ?>"><?= e($statusLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Исполнитель</span>
                        <select data-project-task-filter="assignee" data-no-search>
                            <option value="">Все исполнители</option>
                            <option value="0">Не назначен</option>
                            <?php foreach ($projectTaskAssignees as $assigneeId => $assigneeName): ?>
                                <option value="<?= (int) $assigneeId ?>"><?= e($assigneeName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Срок</span>
                        <select data-project-task-filter="deadline" data-no-search>
                            <option value="">Все сроки</option>
                            <option value="overdue">Просрочено</option>
                            <option value="today">Сегодня</option>
                            <option value="week">Ближайшие 7 дней</option>
                            <option value="no_deadline">Без срока</option>
                        </select>
                    </label>
                    <button class="btn btn-outline btn-sm" type="button" data-project-task-filter-reset>Сбросить</button>
                    <span class="project-task-filterbar__count" data-project-task-filter-count><?= count($projectTasks) ?></span>
                </div>
                <div class="kanban project-task-kanban" data-tour="task-board" data-project-task-board>
                    <?php foreach ($projectTaskStatuses as $status => $statusLabel): ?>
                        <?php $columnTasks = $projectTasksByStatus[$status] ?? []; ?>
                        <div class="kanban__column" data-project-task-column data-project-task-column-status="<?= e($status) ?>">
                            <div class="kanban__head">
                                <h2><?= e($statusLabel) ?></h2>
                                <span class="kanban__head-actions">
                                    <span data-project-task-column-count><?= count($columnTasks) ?></span>
                                    <a class="kanban__quick-create" href="<?= url('/tasks/new?project_id=' . $projectId) ?>" aria-label="Добавить задачу">+</a>
                                </span>
                            </div>
                            <div class="kanban__body">
                                <?php foreach ($columnTasks as $task): ?>
                                    <?php
                                    $progress = max(0, min(100, (int) ($task['progress'] ?? 0)));
                                    $deadlineClass = deadline_state_class($task['date_end'] ?? null, $today);
                                    $deadlineDisplay = (string) ($task['date_end'] ?? '') !== '' ? format_date($task['date_end']) : '—';
                                    $disciplineName = trim((string) ($task['discipline'] ?? ''));
                                    $sectionName = trim((string) ($task['section'] ?? ''));
                                    $assigneeName = trim((string) ($task['assignee_name'] ?? ''));
                                    $assigneeId = (int) ($task['assignee_id'] ?? 0);
                                    $isSubtask = !empty($task['parent_id']);
                                    $taskSearch = trim(implode(' ', [
                                        '#' . (int) $task['id'],
                                        (string) ($task['title'] ?? ''),
                                        $disciplineName,
                                        $sectionName,
                                        $assigneeName,
                                    ]));
                                    ?>
                                    <article class="task-card<?= $isSubtask ? ' task-card--subtask' : '' ?><?= ($task['status'] ?? '') === 'overdue' ? ' task-card--overdue' : '' ?>"
                                        data-project-task-item
                                        data-project-task-id="<?= (int) $task['id'] ?>"
                                        data-project-task-status="<?= e($status) ?>"
                                        data-project-task-assignee="<?= (int) $assigneeId ?>"
                                        data-project-task-deadline="<?= e((string) ($task['date_end'] ?? '')) ?>"
                                        data-project-task-search="<?= e(mb_strtolower($taskSearch)) ?>"
                                        data-task-drawer-href="<?= url('/tasks/' . (int) $task['id']) ?>">
                                        <a href="<?= url('/tasks/' . (int) $task['id']) ?>" data-task-drawer-link>
                                            <strong>#<?= (int) $task['id'] ?> <?= e($task['title'] ?? '') ?></strong>
                                            <span><?= e($disciplineName !== '' ? $disciplineName : 'Раздел не указан') ?><?= $sectionName !== '' && $sectionName !== $disciplineName ? ' · ' . e($sectionName) : '' ?></span>
                                        </a>
                                        <div class="card-meta">
                                            <?php if ($isSubtask): ?><span class="tag tag-subtask">подзадача к #<?= (int) $task['parent_id'] ?></span><?php endif; ?>
                                            <?php if (!$isSubtask && (int) ($task['child_count'] ?? 0) > 0): ?><span class="tag tag-subtask">подзадачи: <?= (int) $task['child_count'] ?></span><?php endif; ?>
                                            <span class="<?= e($deadlineClass) ?>"><?= e($deadlineDisplay) ?></span>
                                            <span class="status status--<?= e($task['status'] ?? 'new') ?> task-card__status"><?= e(task_status_label((string) ($task['status'] ?? 'new'))) ?></span>
                                            <span class="avatar avatar--small" title="<?= e($assigneeName !== '' ? $assigneeName : 'Не назначен') ?>"><?= e(initials($assigneeName)) ?></span>
                                        </div>
                                        <div class="project-task-kanban__hours">
                                            <span>План <?= e($formatHours($task['planned_hours'] ?? 0)) ?> ч</span>
                                            <span>Факт <?= e($formatHours($task['actual_hours'] ?? 0)) ?> ч</span>
                                        </div>
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
                </div>
            <?php else: ?>
                <div class="empty-state empty-state--compact">
                    <span class="empty-state__icon">+</span>
                    <strong>Задач пока нет</strong>
                    <span>Когда задачи появятся, они будут видны прямо на сводке проекта.</span>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel project-task-calendar-panel" id="project-task-calendar">
            <div class="panel__head">
                <div>
                    <h2>Календарь задач</h2>
                    <span class="muted">по срокам задач проекта</span>
                </div>
                <div class="actions-inline">
                    <a class="btn btn-outline" href="<?= e($taskCalendarHref($taskMonthStart->modify('-1 month'))) ?>">Назад</a>
                    <strong><?= e($taskMonthStart->format('m.Y')) ?></strong>
                    <a class="btn btn-outline" href="<?= e($taskCalendarHref($taskMonthStart->modify('+1 month'))) ?>">Вперёд</a>
                </div>
            </div>
            <div class="project-task-calendar">
                <?php foreach ($taskCalendarWeekdays as $weekday): ?>
                    <div class="project-task-calendar__weekday"><?= e($weekday) ?></div>
                <?php endforeach; ?>
                <?php foreach ($taskCalendarDays as $day): ?>
                    <?php
                    $dateKey = $day->format('Y-m-d');
                    $dayTasks = $projectTasksByDate[$dateKey] ?? [];
                    $isOutsideMonth = $day->format('Y-m') !== $taskMonthStart->format('Y-m');
                    ?>
                    <div class="project-task-calendar__day<?= $isOutsideMonth ? ' is-outside' : '' ?><?= $dateKey === $today ? ' is-today' : '' ?>">
                        <div class="project-task-calendar__date"><?= e($day->format('d')) ?></div>
                        <?php foreach (array_slice($dayTasks, 0, 4) as $task): ?>
                            <?php
                            $calendarStatus = (string) ($task['status'] ?? 'new');
                            $calendarBoardStatus = (string) ($task['board_status'] ?? $calendarStatus);
                            $calendarColumn = array_key_exists($calendarBoardStatus, $projectTaskStatuses) ? $calendarBoardStatus : 'new';
                            $calendarSearch = trim(implode(' ', [
                                '#' . (int) $task['id'],
                                (string) ($task['title'] ?? ''),
                                (string) ($task['discipline'] ?? ''),
                                (string) ($task['section'] ?? ''),
                                (string) ($task['assignee_name'] ?? ''),
                            ]));
                            ?>
                            <a class="project-task-calendar__task status-border--<?= e($calendarStatus) ?>"
                                href="<?= url('/tasks/' . (int) $task['id']) ?>"
                                data-task-drawer-link
                                data-project-task-calendar-item
                                data-project-task-id="<?= (int) $task['id'] ?>"
                                data-project-task-status="<?= e($calendarColumn) ?>"
                                data-project-task-assignee="<?= (int) ($task['assignee_id'] ?? 0) ?>"
                                data-project-task-deadline="<?= e((string) ($task['date_end'] ?? '')) ?>"
                                data-project-task-search="<?= e(mb_strtolower($calendarSearch)) ?>">
                                #<?= (int) $task['id'] ?> <?= e($task['title'] ?? '') ?>
                            </a>
                        <?php endforeach; ?>
                        <?php if (count($dayTasks) > 4): ?>
                            <a class="project-task-calendar__more" href="<?= e($projectTasksHref(['date_from' => $dateKey, 'date_to' => $dateKey])) ?>">+<?= count($dayTasks) - 4 ?></a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel project-contacts" id="project-contacts">
            <div class="panel__head">
                <h2>Контакт-лист</h2>
                <span><?= count($contacts ?? []) ?></span>
            </div>
            <div class="table-wrap">
                <table class="data-table contact-table">
                    <thead>
                    <tr>
                        <th>ФИО</th>
                        <th>Контакт</th>
                        <th>Организация</th>
                        <th>Должность</th>
                        <?php if ($canEdit): ?><th></th><?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($contacts ?? []) as $contact): ?>
                        <tr>
                            <td><strong><?= e($contact['full_name']) ?></strong></td>
                            <td><?= e($contact['contact']) ?></td>
                            <td><?= e($contact['organization']) ?></td>
                            <td><?= e($contact['position']) ?></td>
                            <?php if ($canEdit): ?>
                                <td>
                                    <form method="post" action="<?= url('/projects/' . $project['id'] . '/contacts/' . $contact['id'] . '/delete') ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-ghost btn-sm" type="submit">Удалить</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($contacts)): ?>
                        <tr>
                            <td colspan="<?= $canEdit ? 5 : 4 ?>">
                                <div class="empty-state empty-state--compact">
                                    <span class="empty-state__icon">+</span>
                                    <strong>Контактов пока нет</strong>
                                    <span>Добавьте людей заказчика, подрядчиков или внешних участников проекта.</span>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($canEdit): ?>
                <form class="project-contact-form" method="post" action="<?= url('/projects/' . $project['id'] . '/contacts') ?>">
                    <?= csrf_field() ?>
                    <input type="text" name="full_name" placeholder="ФИО" required>
                    <input type="text" name="contact" placeholder="телефон, email">
                    <input type="text" name="organization" placeholder="Организация">
                    <input type="text" name="position" placeholder="Должность">
                    <button class="btn btn--red" type="submit">Добавить</button>
                </form>
            <?php endif; ?>
        </section>

        <?php if (!empty($revitModels)): ?>
        <section class="panel" id="revit-models">
            <div class="panel__head">
                <div>
                    <h2>Модели из Revit</h2>
                    <span>Текущие IFC и неизменяемая история публикаций</span>
                </div>
                <span class="pill"><?= count($revitModels) ?></span>
            </div>
            <?php foreach ($revitModels as $revitModel): ?>
                <details class="details-panel"<?= !empty($revitModel['current_version_id']) ? ' open' : '' ?>>
                    <summary class="details-panel__summary">
                        <span><strong><?= e($revitModel['name']) ?></strong><?= $revitModel['discipline'] !== '' ? ' · ' . e($revitModel['discipline']) : '' ?></span>
                        <span><?= !empty($revitModel['current_version_number']) ? 'текущая v' . str_pad((string) $revitModel['current_version_number'], 3, '0', STR_PAD_LEFT) : 'версий нет' ?></span>
                    </summary>
                    <div class="table-wrap">
                        <table class="data-table data-table--compact">
                            <thead><tr><th>Версия</th><th>Автор и дата</th><th>Revit / вид</th><th>Профиль</th><th>Размер</th><th>Комментарий</th><th>Действия</th></tr></thead>
                            <tbody>
                            <?php foreach (($revitModel['versions'] ?? []) as $version): ?>
                                <?php
                                $isCurrentVersion = (int) ($revitModel['current_version_id'] ?? 0) === (int) $version['id'];
                                $versionFileUrl = url('/revit/versions/' . (int) $version['id'] . '/file');
                                $versionAtlasUrl = atlas_url('?ifc=' . rawurlencode($versionFileUrl) . '&locia_return=' . rawurlencode(url('/projects/' . $projectId)));
                                ?>
                                <tr>
                                    <td><strong class="mono">v<?= e(str_pad((string) $version['version_number'], 3, '0', STR_PAD_LEFT)) ?></strong><?php if ($isCurrentVersion): ?><br><span class="status-chip status-chip--done">текущая</span><?php endif; ?></td>
                                    <td><strong><?= e($version['created_by_name'] ?: '—') ?></strong><small><?= e(format_datetime($version['created_at'] ?? '')) ?></small></td>
                                    <td><strong><?= e($version['revit_version'] ?: '—') ?></strong><small><?= e($version['view_name'] ?: '—') ?></small></td>
                                    <td><?= e($version['ifc_profile'] ?: '—') ?></td>
                                    <td><?= e(number_format(((int) $version['byte_size']) / 1024 / 1024, 1, '.', ' ')) ?> МБ</td>
                                    <td><?= e($version['comment'] ?: '—') ?></td>
                                    <td><div class="actions-inline">
                                        <a class="btn btn-outline btn-sm" href="<?= e($versionAtlasUrl) ?>" target="_blank" rel="noreferrer">Открыть</a>
                                        <?php if (!empty($canManageModelLinks) && !$isCurrentVersion): ?>
                                            <form method="post" action="<?= url('/projects/' . $projectId . '/revit-models/' . (int) $revitModel['id'] . '/versions/' . (int) $version['id'] . '/current') ?>">
                                                <?= csrf_field() ?><button class="btn btn-outline btn-sm" type="submit">Сделать текущей</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (!empty($canManageModelLinks)): ?>
                                            <form method="post" action="<?= url('/projects/' . $projectId . '/revit-models/' . (int) $revitModel['id'] . '/versions/' . (int) $version['id'] . '/delete') ?>" data-confirm="Удалить эту версию IFC без возможности восстановления?">
                                                <?= csrf_field() ?><button class="btn btn-outline btn-sm" type="submit">Удалить</button>
                                            </form>
                                        <?php endif; ?>
                                    </div></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <details class="panel details-panel" id="project-history">
            <summary class="details-panel__summary">
                <span>История проекта</span>
                <span class="details-toggle-label" aria-hidden="true">
                    <span class="details-toggle-label__closed">Развернуть</span>
                    <span class="details-toggle-label__open">Свернуть</span>
                </span>
            </summary>
            <?php
            $rows = $activityLogs ?? [];
            $compact = true;
            $emptyText = 'История проекта начнёт заполняться после новых действий.';
            require BASE_PATH . '/app/Views/activity/_list.php';
            ?>
        </details>

    </div>

</div>

<?php if ($showDemoProjectSettings): ?>
<details class="panel project-settings-panel" id="project-settings"<?= $showProjectTeam ? ' open' : '' ?>>
    <summary class="project-models-summary">
        <div>
            <span>Настройки проекта</span>
            <span>Команда, ручной бюджет, импорт графика и служебные настройки. Просмотр проекта находится выше.</span>
        </div>
        <span class="details-toggle-label" aria-hidden="true">
            <span class="details-toggle-label__closed">Развернуть</span>
            <span class="details-toggle-label__open">Свернуть</span>
        </span>
    </summary>
    <div class="project-settings-panel__body">
        <section class="panel project-settings-links">
            <div class="panel__head">
                <h2>Справочники и служебные разделы</h2>
                <span>настройка</span>
            </div>
            <div class="actions-inline">
                <a class="btn btn-outline" href="<?= url('/projects/' . $project['id'] . '/dictionaries') ?>">ПП / БТП / УТС</a>
                <a class="btn btn-outline" href="#project-models">Настроить модели</a>
            </div>
        </section>

        <section class="panel project-team-panel" id="project-team">
            <div class="panel__head">
                <div>
                    <h2>Структура и команда</h2>
                    <span>Стадии, разделы, общие активности, исполнители и проверяющие собраны в одном реестре.</span>
                </div>
                <span class="badge badge--soft"><?= count($members) ?> чел.</span>
            </div>
            <div class="actions-inline">
                <a class="btn btn--red" href="<?= url('/projects/' . $projectId . '/structure') ?>">Открыть структуру и команду</a>
            </div>
        </section>

        <?php if ($canViewProjectFinance && !$isArchived): ?>
            <section class="panel project-budget-panel project-budget-settings" id="project-budget-settings">
                <div class="panel__head">
                    <div><h2>Бюджет проекта</h2><span>Общую сумму можно указать сразу, а части детализировать постепенно.</span></div>
                    <?php if ($canManageDepartmentBudget): ?><a class="btn btn-outline" href="<?= url('/director/budget?project_id=' . $projectId) ?>#payment-schedule">График платежей · <?= count($projectPayments) ?></a><?php endif; ?>
                </div>
                <form class="form-grid project-budget-form" method="post" action="<?= url('/projects/' . $projectId . '/budget') ?>">
                    <?= csrf_field() ?>
                    <label><span>Общий бюджет, тыс. ₽</span><input type="text" inputmode="decimal" name="budget_total_thousand" value="<?= e($formatBudgetInput($project['budget_manual_thousand'] ?? 0)) ?>" data-grouped-number data-project-budget-total></label>
                    <label><span>Затраты, тыс. ₽</span><input type="text" inputmode="decimal" name="budget_cost_thousand" value="<?= e($formatBudgetInput($project['budget_cost_thousand'] ?? 0)) ?>" data-grouped-number data-project-budget-part></label>
                    <label><span>Прибыль, тыс. ₽</span><input type="text" inputmode="decimal" name="budget_profit_thousand" value="<?= e($formatBudgetInput($project['budget_profit_thousand'] ?? 0)) ?>" data-grouped-number data-project-budget-part></label>
                    <label><span>Премиальная часть, тыс. ₽</span><input type="text" inputmode="decimal" name="budget_bonus_thousand" value="<?= e($formatBudgetInput($project['budget_bonus_thousand'] ?? 0)) ?>" data-grouped-number data-project-budget-part></label>
                    <p class="form-grid__full project-budget-remainder" data-project-budget-remainder></p>
                    <label class="form-grid__full">
                        <span>Примечание к бюджету</span>
                        <input name="budget_comment" value="<?= e($project['budget_comment'] ?? '') ?>" placeholder="Основание, версия бюджета, комментарий">
                    </label>
                    <div class="form-grid__full">
                        <button class="btn btn--red" type="submit">Сохранить бюджет</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if (!empty($canImportMsp)): ?>
            <section class="panel" id="msp-import">
                <form class="form-grid" method="post" enctype="multipart/form-data" action="<?= url('/projects/' . $project['id'] . '/msp-import') ?>">
                    <?= csrf_field() ?>
                    <div class="panel__head form-grid__full">
                        <h2>Импорт графика MS Project</h2>
                        <button class="btn btn--red" type="submit">Импортировать</button>
                    </div>
                    <label>
                        <span>Файл XML или MPP</span>
                        <input type="file" name="msp_file" accept=".xml,.mspdi,.mpp,text/xml,application/xml,application/vnd.ms-office" required>
                    </label>
                    <label>
                        <span>Режим повторной загрузки</span>
                        <input value="Обновить существующие задачи по MSP UID, новые добавить, отсутствующие не удалять" readonly>
                    </label>
                </form>
                <?php if (!empty($mspImportResult)): ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead><tr><th>MSP UID</th><th>ID задачи</th><th>Название</th></tr></thead>
                            <tbody>
                            <?php foreach (($mspImportResult['items'] ?? []) as $item): ?>
                                <tr>
                                    <td><?= e($item['uid'] ?? '') ?></td>
                                    <td>#<?= (int) ($item['task_id'] ?? 0) ?></td>
                                    <td><?= e($item['title'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="muted">Создано <?= (int) ($mspImportResult['created'] ?? 0) ?>, обновлено <?= (int) ($mspImportResult['updated'] ?? 0) ?>.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>
</div>
</details>
<?php endif; ?>

<?php if ($showDemoProjectModels): ?>
<details class="panel project-models-panel" id="project-models">
    <summary class="project-models-summary">
        <div>
            <span>Настройка моделей<?= $modelCount > 0 ? ' · ' . $modelCount : '' ?></span>
            <span>Папка с моделями, ручные ссылки, обновление FRAG и публичные ссылки.</span>
        </div>
        <span class="details-toggle-label" aria-hidden="true">
            <span class="details-toggle-label__closed">Развернуть</span>
            <span class="details-toggle-label__open">Свернуть</span>
        </span>
    </summary>
    <div class="project-models-panel__body">
        <?php if (!app_is_demo_mode() && !empty($canManageModelLinks)): ?>
            <form class="form-grid project-model-folder-form" method="post" action="<?= url('/projects/' . $project['id'] . '/model-folder') ?>">
                <?= csrf_field() ?>
                <label class="form-grid__full">
                    <span>Папка с моделями</span>
                    <input type="text" name="model_folder_url" value="<?= e($project['model_folder_url'] ?? '') ?>" placeholder="C:\Locia\models\project-01 или \\server\share\project-01\models">
                </label>
                <div class="form-actions form-grid__full">
                    <button class="btn btn--red" type="submit">Добавить папку с моделями</button>
                </div>
            </form>
        <?php elseif (!app_is_demo_mode() && empty($project['model_folder_url'])): ?>
            <div class="empty-state empty-state--compact">
                <span class="empty-state__icon">M</span>
                <strong>Папка моделей пока не задана</strong>
                <span>Попросите ГИПа, BIM-менеджера или администратора добавить сетевую папку проекта.</span>
            </div>
        <?php endif; ?>

        <?php if (!empty($project['model_folder_url'])): ?>
            <div class="project-model-folder-head">
                <div>
                    <strong>Папка проекта</strong>
                    <code class="code-inline"><?= e($project['model_folder_url']) ?></code>
                </div>
                <div class="actions-inline">
                    <?php if ($atlasFederationHref !== ''): ?>
                        <a class="btn btn-outline btn-sm" href="<?= e($atlasFederationHref) ?>" target="_blank" rel="noreferrer">Открыть все</a>
                    <?php endif; ?>
                    <?php if (!empty($canManageModelLinks)): ?>
                        <form method="post" action="<?= url('/projects/' . $project['id'] . '/model-folder/refresh') ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-outline btn-sm" type="submit">Обновить папку</button>
                        </form>
                    <?php else: ?>
                        <a class="btn btn-outline btn-sm" href="<?= url('/projects/' . $projectId) ?>#project-models">Обновить</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($folderModels)): ?>
            <div class="table-wrap">
                <table class="data-table data-table--compact">
                    <thead>
                    <tr>
                        <th>Модель</th>
                        <th>Тип</th>
                        <th>Изменён</th>
                        <th>FRAG</th>
                        <th>Действия</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($folderModels as $fm): ?>
                        <tr>
                            <td>
                                <strong><?= e($fm['name'] ?? '') ?></strong>
                                <?php if (($fm['rel'] ?? '') !== ($fm['name'] ?? '')): ?><br><small class="muted"><?= e($fm['rel'] ?? '') ?></small><?php endif; ?>
                            </td>
                            <td><?= e(strtoupper((string) ($fm['ext'] ?? ''))) ?></td>
                            <td><?= !empty($fm['mtime']) ? e(date('d.m.Y H:i', (int) $fm['mtime'])) : '—' ?></td>
                            <td>
                                <span class="status-chip status-chip--<?= ($fm['fragment_status'] ?? '') === 'ready' ? 'done' : 'pending' ?>" data-frag-status="<?= e(substr(sha1((string) ($fm['rel'] ?? '')), 0, 12)) ?>">
                                    <?= e($fm['fragment_status_label'] ?? 'готовится') ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions-inline">
                                    <a class="btn btn-sm btn-outline" href="<?= e($fm['atlas_href'] ?? '') ?>" target="_blank" rel="noreferrer">Открыть</a>
                                    <button class="btn btn-outline btn-sm" type="button" data-copy-link="<?= e($fm['public_url'] ?? ($fm['atlas_share_url'] ?? '')) ?>" title="Скопировать публичную ссылку">Публичная ссылка</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif (!empty($project['model_folder_url'])): ?>
            <?php
            $seenFiles = (int) ($modelFolderScan['files_seen'] ?? 0);
            $extensionCounts = (array) ($modelFolderScan['extension_counts'] ?? []);
            ksort($extensionCounts, SORT_NATURAL | SORT_FLAG_CASE);
            $extensionLabels = [];
            foreach ($extensionCounts as $extension => $count) {
                $extensionLabels[] = (string) $extension . ': ' . (int) $count;
            }
            $sampleFiles = array_slice((array) ($modelFolderScan['sample_files'] ?? []), 0, 8);
            $supportedFiles = array_slice((array) ($modelFolderScan['supported_files'] ?? []), 0, 8);
            $navisworksCount = 0;
            foreach (['nwc', 'nwf', 'nwd'] as $navisworksExt) {
                $navisworksCount += (int) ($extensionCounts[$navisworksExt] ?? 0);
            }
            ?>
            <div class="empty-state empty-state--compact">
                <span class="empty-state__icon">M</span>
                <?php if (empty($modelFolderScan['accessible'])): ?>
                    <strong>Папка недоступна серверу</strong>
                    <span>Лемма не смогла открыть путь из процесса Apache. Проверьте запуск Apache и права на сетевую папку.</span>
                <?php elseif (!empty($modelFolderErrors)): ?>
                    <strong>Сканирование папки прервано правами доступа</strong>
                    <span>Проверьте вложенные папки. Первые ошибки: <?= e(implode('; ', array_slice($modelFolderErrors, 0, 3))) ?></span>
                <?php elseif ($navisworksCount > 0 && $supportedFiles === []): ?>
                    <strong>Найдены файлы Navisworks, но не IFC/FRAG</strong>
                    <span>Атлас открывает IFC, IFCZIP и FRAG. Файлы NWC/NWF/NWD видны в папке, но сейчас не открываются web-viewer без отдельного конвертера.</span>
                <?php else: ?>
                    <strong>В папке не найдены модели</strong>
                    <span>Проверьте наличие файлов .ifc, .ifczip или .frag.</span>
                <?php endif; ?>
                <small class="muted">
                    Диагностика: файлов видно <?= $seenFiles ?><?= $extensionLabels !== [] ? '; расширения: ' . e(implode(', ', $extensionLabels)) : '' ?><?= $sampleFiles !== [] ? '; примеры: ' . e(implode(', ', $sampleFiles)) : '' ?>.
                    <?php if ($supportedFiles !== []): ?>
                        Поддерживаемые файлы: <?= e(implode(', ', $supportedFiles)) ?>.
                    <?php endif; ?>
                </small>
            </div>
        <?php endif; ?>

        <?php if ($autoPrepareModels && !empty($pendingFolderModels)): ?>
            <div class="project-model-frag-note" data-model-frag-queue>
                <strong>Фоновая подготовка FRAG запущена</strong>
                <span>Открывается скрытая очередь Атласа, модели готовятся по одной. Если файл тяжёлый, оставьте вкладку проекта открытой.</span>
                <?php foreach ($pendingFolderModels as $fm): ?>
                    <a hidden data-model-frag-job
                       data-frag-key="<?= e(substr(sha1((string) ($fm['rel'] ?? '')), 0, 12)) ?>"
                       data-prepare-url="<?= e($fm['prepare_href'] ?? $fm['atlas_href'] ?? '') ?>"
                       data-status-url="<?= e($fm['fragment_status_url'] ?? '') ?>"></a>
                <?php endforeach; ?>
            </div>
        <?php elseif (!empty($pendingFolderModels)): ?>
            <div class="project-model-frag-note">
                <strong>Есть IFC без готового FRAG</strong>
                <span>Нажмите «Обновить папку», чтобы сбросить старый кеш и запустить подготовку в фоне.</span>
            </div>
        <?php endif; ?>

        <?php if (!empty($modelLinks)): ?>
            <details class="project-model-legacy">
                <summary>Ручные ссылки · <?= count($modelLinks) ?></summary>
                <div class="table-wrap">
                    <table class="data-table data-table--compact">
                        <thead>
                        <tr>
                            <th>Модель</th>
                            <th>Раздел</th>
                            <th>Ревизия</th>
                            <th>Ссылка</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($modelLinks as $modelLink): ?>
                            <tr>
                                <td>
                                    <strong><?= e($modelLink['title'] ?? '') ?></strong>
                                    <small>
                                        <?= e($kindLabels[$modelLink['kind'] ?? 'json'] ?? strtoupper((string) ($modelLink['kind'] ?? ''))) ?>
                                        · <?= (($modelLink['model_scope'] ?? 'project') === 'public') ? 'публичная папка' : 'папки проекта' ?>
                                        <?= !empty($modelLink['is_primary']) ? ' · текущая' : '' ?>
                                        <?= !empty($modelLink['created_by_name']) ? ' · ' . e($modelLink['created_by_name']) : '' ?>
                                    </small>
                                    <?php if (($modelLink['notes'] ?? '') !== ''): ?><small><?= e($modelLink['notes']) ?></small><?php endif; ?>
                                </td>
                                <td><?= e(($modelLink['discipline'] ?? '') !== '' ? $modelLink['discipline'] : '—') ?></td>
                                <td><?= e(($modelLink['revision'] ?? '') !== '' ? $modelLink['revision'] : '—') ?></td>
                                <td><code class="code-inline"><?= e($modelLink['model_url'] ?? '') ?></code></td>
                                <td>
                                    <div class="actions-inline">
                                        <?php if (!empty($modelLink['can_open_in_atlas'])): ?>
                                            <a class="btn btn-sm btn-outline" href="<?= e($modelLink['atlas_href']) ?>" target="_blank" rel="noreferrer">Открыть</a>
                                            <button class="btn btn-outline btn-sm" type="button" data-copy-link="<?= e($modelLink['public_url'] ?? ($modelLink['atlas_share_url'] ?? $modelLink['atlas_href'])) ?>">Ссылка</button>
                                        <?php endif; ?>
                                        <button class="btn btn-outline btn-sm" type="button" data-copy-path="<?= e($modelLink['model_url'] ?? '') ?>">Путь</button>
                                        <?php if (!empty($canManageModelLinks)): ?>
                                            <form method="post" action="<?= url('/projects/' . $project['id'] . '/models/' . (int) $modelLink['id'] . '/delete') ?>">
                                                <?= csrf_field() ?>
                                                <button class="btn btn-ghost btn-sm" type="submit">Удалить</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endif; ?>

        <?php if (!empty($canManageModelLinks)): ?>
            <details class="project-model-legacy">
                <summary>Добавить отдельную ссылку вручную</summary>
                <form class="form-grid" id="project-model-add" method="post" action="<?= url('/projects/' . $project['id'] . '/models') ?>">
                    <?= csrf_field() ?>
                    <label>
                        <span>Название</span>
                        <input type="text" name="title" placeholder="Сводная модель / АР / ОВ">
                    </label>
                    <label>
                        <span>Тип</span>
                        <select name="kind">
                            <option value="json">CAD/BIM JSON</option>
                            <option value="ifc">IFC</option>
                            <option value="ifczip">IFC ZIP</option>
                            <option value="frag">FRAG</option>
                        </select>
                    </label>
                    <fieldset class="form-grid__full form-choice-group">
                        <legend>Область файла</legend>
                        <label class="form-check">
                            <input type="radio" name="model_scope" value="project" checked>
                            <span>Проект: файл должен лежать в папке моделей или файлов этого проекта</span>
                        </label>
                        <label class="form-check">
                            <input type="radio" name="model_scope" value="public">
                            <span>Паблик: файл должен лежать в общей папке Атласа</span>
                        </label>
                    </fieldset>
                    <label>
                        <span>Раздел</span>
                        <input type="text" name="discipline" placeholder="АР, КР, ОВ, ВК, ЭОМ">
                    </label>
                    <label>
                        <span>Ревизия</span>
                        <input type="text" name="revision" placeholder="изм. 0 / 2026-06-05">
                    </label>
                    <label class="form-grid__full">
                        <span>Постоянная ссылка или путь</span>
                        <input type="text" name="model_url" placeholder="путь к .ifc / .frag / .json или http://server/model.ifc">
                    </label>
                    <label class="form-grid__full">
                        <span>Комментарий</span>
                        <input type="text" name="notes" placeholder="Что это за модель и откуда она берётся">
                    </label>
                    <label class="form-check">
                        <input type="checkbox" name="is_primary" value="1"<?= empty($modelLinks) ? ' checked' : '' ?>>
                        <span>Сделать текущей</span>
                    </label>
                    <div class="form-actions">
                        <button class="btn btn-outline" type="submit">Добавить ссылку</button>
                    </div>
                </form>
            </details>
        <?php endif; ?>
    </div>
</details>
<?php endif; ?>
