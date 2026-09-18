<?php
$filters = $filters ?? [];
$selectedProjectId = (string) ($filters['project_id'] ?? ($selectedProjectId ?? ''));
$selectedAssigneeId = (string) ($filters['assignee_id'] ?? '');
$dateFrom = (string) ($filters['date_from'] ?? '');
$dateTo = (string) ($filters['date_to'] ?? '');
$metrics = $metrics ?? [];
$peopleDistribution = $peopleDistribution ?? [];
$taskStatistics = $taskStatistics ?? ['overall' => [], 'by_discipline' => [], 'by_type' => []];
$processControl = $processControl ?? ['overall' => [], 'status_rows' => [], 'departments' => [], 'slow_tasks' => [], 'bottlenecks' => []];
$processOverall = (array) ($processControl['overall'] ?? []);
$processStatusRows = (array) ($processControl['status_rows'] ?? []);
$processDepartments = (array) ($processControl['departments'] ?? []);
$processSlowTasks = (array) ($processControl['slow_tasks'] ?? []);
$processBottlenecks = (array) ($processControl['bottlenecks'] ?? []);
$today = date('Y-m-d');
$viewer = current_user();
$canManageDashboard = $viewer !== null && !\App\Services\RoleService::isAny($viewer['role'] ?? null, [\App\Services\RoleService::ENGINEER]);

$taskListHref = static function (array $params = []) use ($selectedProjectId, $selectedAssigneeId, $dateFrom, $dateTo): string {
    $params = ['view' => 'table'] + $params;
    if ($selectedProjectId !== '') {
        $params['project_id'] = (int) $selectedProjectId;
    }
    if ($selectedAssigneeId !== '' && !isset($params['assignee_id'])) {
        $params['assignee_id'] = (int) $selectedAssigneeId;
    }
    if ($dateFrom !== '') {
        $params['date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $params['date_to'] = $dateTo;
    }

    return url('/tasks/all?' . http_build_query($params));
};

$dprHref = static function (string $anchor = '') use ($selectedProjectId, $selectedAssigneeId, $dateFrom, $dateTo): string {
    $params = [];
    if ($selectedProjectId !== '') {
        $params['project_id'] = (int) $selectedProjectId;
    }
    if ($selectedAssigneeId !== '') {
        $params['assignee_id'] = (int) $selectedAssigneeId;
    }
    if ($dateFrom !== '') {
        $params['date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $params['date_to'] = $dateTo;
    }
    $query = $params ? '?' . http_build_query($params) : '';

    return url('/shturman' . $query . ($anchor !== '' ? '#' . $anchor : ''));
};

$emptyRow = static function (int $colspan): void { ?>
    <tr class="dpr-empty-row"><td colspan="<?= $colspan ?>"><span class="dpr-empty">— нет данных</span></td></tr>
<?php };

$issuanceStatusLabel = static fn (?string $status): string => [
    'accepted' => 'Принята ✓',
    'remarks' => 'Замечания',
    'issued' => 'В работе',
][$status ?? ''] ?? (string) $status;

$issuanceStatusClass = static fn (?string $status): string => [
    'accepted' => 'dpr-state--green',
    'remarks' => 'dpr-state--red',
    'issued' => 'dpr-state--amber',
][$status ?? ''] ?? 'dpr-state--amber';

$daysLeftLabel = static fn (int $days): string => $days === 0 ? 'сегодня' : $days . ' дн.';
$daysLeftClass = static fn (int $days): string => $days <= 2 ? 'metric-value--bad' : ($days <= 7 ? 'metric-value--warn' : 'metric-value--good');
$formatHours = static function (mixed $value): string {
    $formatted = rtrim(rtrim(number_format((float) $value, 1, '.', ' '), '0'), '.');

    return $formatted === '' ? '0' : $formatted;
};
$formatPercent = static fn (mixed $value): string => number_format((float) $value, 1, '.', ' ') . ' %';
$taskStatsRows = static function (array $rows) use ($formatHours, $formatPercent, $emptyRow): void {
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td><strong>' . e($row['label'] ?? '—') . '</strong><small>' . e((int) ($row['total'] ?? 0)) . ' закрытых задач</small></td>';
        echo '<td>' . e($formatHours($row['avg_planned_hours'] ?? 0)) . ' ч</td>';
        echo '<td>' . e($formatHours($row['avg_actual_hours'] ?? 0)) . ' ч</td>';
        echo '<td>' . e($formatHours($row['avg_cycle_days'] ?? 0)) . ' дн.</td>';
        echo '<td>' . e($formatPercent($row['over_plan_percent'] ?? 0)) . '</td>';
        echo '</tr>';
    }
    if (!$rows) {
        $emptyRow(5);
    }
};

$processStatusTable = static function (array $rows) use ($emptyRow): void {
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td><strong>' . e($row['label'] ?? $row['status'] ?? '—') . '</strong></td>';
        echo '<td>' . e((int) ($row['count'] ?? 0)) . '</td>';
        echo '<td>' . e((int) ($row['avg_age_days'] ?? 0)) . ' дн.</td>';
        echo '<td>' . e((int) ($row['max_age_days'] ?? 0)) . ' дн.</td>';
        echo '</tr>';
    }
    if (!$rows) {
        $emptyRow(4);
    }
};

$processDepartmentTable = static function (array $rows) use ($formatHours, $emptyRow): void {
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td><strong>' . e($row['department'] ?? 'Без отдела') . '</strong></td>';
        echo '<td>' . e((int) ($row['open_tasks'] ?? 0)) . '</td>';
        echo '<td>' . e((int) ($row['overdue_tasks'] ?? 0)) . '</td>';
        echo '<td>' . e((int) ($row['review_tasks'] ?? 0)) . '</td>';
        echo '<td>' . e((int) ($row['correction_loops'] ?? 0)) . '</td>';
        echo '<td>' . e($formatHours($row['rework_hours'] ?? 0)) . ' ч</td>';
        echo '</tr>';
    }
    if (!$rows) {
        $emptyRow(6);
    }
};

$processSlowTaskTable = static function (array $rows) use ($emptyRow): void {
    foreach ($rows as $task) {
        $href = url('/tasks/' . (int) ($task['id'] ?? 0));
        echo '<tr class="clickable" data-href="' . e($href) . '" data-task-drawer-href="' . e($href) . '">';
        echo '<td><strong>#' . e((int) ($task['id'] ?? 0)) . ' · ' . e($task['title'] ?? '') . '</strong><small>' . e(($task['project_code'] ?? '') . ' · ' . ($task['department'] ?? '')) . '</small></td>';
        echo '<td>' . e($task['status_label'] ?? '') . '</td>';
        echo '<td>' . e(($task['assignee_name'] ?? '') ?: 'не назначен') . '</td>';
        echo '<td>' . e((int) ($task['age_days'] ?? 0)) . '</td>';
        echo '</tr>';
    }
    if (!$rows) {
        $emptyRow(4);
    }
};

$personReportHref = static function (int $userId, string $userName = '', array $params = []) use ($dateFrom, $dateTo): string {
    $params = [
        'user_id' => $userId,
        'employee_search' => $userName,
    ] + $params;
    if ($dateFrom !== '') {
        $params['date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $params['date_to'] = $dateTo;
    }

    return url('/reports/people?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
};

$distributionHref = static function (array $row) use ($personReportHref): string {
    return $personReportHref((int) $row['user_id'], (string) ($row['user_name'] ?? ''), [
        'project_id' => (int) $row['project_id'],
    ]);
};

$objectColumns = [];
$peopleMatrix = [];
foreach ($peopleDistribution as $row) {
    $objectId = (int) $row['project_id'];
    $objectName = trim((string) ($row['project_object'] ?? ''));
    if ($objectName === '') {
        $objectName = trim((string) ($row['project_title'] ?? ''));
    }
    if ($objectName === '') {
        $objectName = trim((string) ($row['project_code'] ?? 'Объект'));
    }
    $objectColumns[$objectId] ??= [
        'id' => $objectId,
        'name' => $objectName,
        'code' => (string) ($row['project_code'] ?? ''),
        'nearest_deadline' => $row['nearest_deadline'] ?? null,
    ];
    $currentNearest = trim((string) ($row['nearest_deadline'] ?? ''));
    $columnNearest = trim((string) ($objectColumns[$objectId]['nearest_deadline'] ?? ''));
    if ($currentNearest !== '' && ($columnNearest === '' || $currentNearest < $columnNearest)) {
        $objectColumns[$objectId]['nearest_deadline'] = $currentNearest;
    }

    $userId = (int) $row['user_id'];
    $peopleMatrix[$userId] ??= [
        'id' => $userId,
        'name' => (string) ($row['user_name'] ?? ''),
        'department' => (string) ($row['department'] ?? ''),
        'cells' => [],
    ];
    $peopleMatrix[$userId]['cells'][$objectId] = $row;
}
$peopleDepartments = array_values(array_filter(array_unique(array_map(
    static fn (array $person): string => trim((string) ($person['department'] ?? '')),
    $peopleMatrix
))));
sort($peopleDepartments, SORT_NATURAL | SORT_FLAG_CASE);
$peopleFilterOptions = array_values($peopleMatrix);
usort($peopleFilterOptions, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
uasort($objectColumns, static function (array $a, array $b): int {
    $aDate = trim((string) ($a['nearest_deadline'] ?? ''));
    $bDate = trim((string) ($b['nearest_deadline'] ?? ''));
    if ($aDate !== $bDate) {
        if ($aDate === '') {
            return 1;
        }
        if ($bDate === '') {
            return -1;
        }

        return strcmp($aDate, $bDate);
    }

    return strcmp((string) $a['name'], (string) $b['name']);
});

$reorderControls = static function () use ($canManageDashboard): void {
    if (!$canManageDashboard) {
        return;
    }
    ?>
    <button class="dpr-window-span" type="button" data-dashboard-span-toggle aria-label="Объединить ячейки" title="Объединить ячейки">↔</button>
    <button class="dpr-window-resize" type="button" data-dashboard-resize aria-label="Потянуть высоту окна" title="Потянуть высоту окна">↕</button>
    <button class="dpr-window-grip" type="button" data-dashboard-handle aria-label="Перенести окно" title="Перенести окно"></button>
<?php };

$metricCards = [
    [
        'label' => 'Проекты',
        'value' => (int) ($metrics['projects'] ?? 0),
        'class' => 'dpr-metric--projects',
        'href' => $selectedProjectId !== '' ? url('/projects/' . (int) $selectedProjectId) : url('/projects'),
    ],
    [
        'label' => 'Просрочено',
        'value' => (int) ($metrics['overdue'] ?? 0),
        'class' => 'dpr-metric--overdue',
        'href' => $taskListHref(['deadline' => 'overdue']),
    ],
    [
        'label' => 'На согласовании',
        'value' => (int) ($metrics['approvals'] ?? 0),
        'class' => 'dpr-metric--approval',
        'href' => $dprHref('dpr-approvals'),
    ],
    [
        'label' => 'Ждём ИД',
        'value' => (int) ($metrics['waiting_data'] ?? 0),
        'class' => 'dpr-metric--waiting',
        'href' => $dprHref('dpr-data'),
    ],
    [
        'label' => 'Вопросов',
        'value' => (int) ($metrics['issues'] ?? 0),
        'class' => 'dpr-metric--issues',
        'href' => $dprHref('dpr-issues'),
    ],
    [
        'label' => 'Закрыто',
        'value' => (int) ($metrics['closed'] ?? 0),
        'class' => 'dpr-metric--closed',
        'href' => $taskListHref(['status' => 'done']),
    ],
];
?>

<section class="toolbar dpr-toolbar">
    <form class="filterbar dpr-filter" method="get" action="<?= url('/shturman') ?>">
        <select name="project_id" aria-label="Фильтр по проекту">
            <option value="">Все проекты</option>
            <?php foreach ($projects as $project): ?>
                <option value="<?= (int) $project['id'] ?>"<?= selected($selectedProjectId, $project['id']) ?>><?= e($project['code'] . ' · ' . $project['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="assignee_id" aria-label="Фильтр по исполнителю">
            <option value="">Все исполнители</option>
            <?php foreach (($users ?? []) as $taskUser): ?>
                <option value="<?= (int) $taskUser['id'] ?>"<?= selected($selectedAssigneeId, $taskUser['id']) ?>><?= e($taskUser['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date_from" value="<?= e($dateFrom) ?>" aria-label="Дата от">
        <input type="date" name="date_to" value="<?= e($dateTo) ?>" aria-label="Дата до">
        <button class="btn btn-outline" type="submit">Показать</button>
        <?php if ($selectedProjectId !== '' || $selectedAssigneeId !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
            <a class="btn" href="<?= url('/shturman') ?>">Сбросить</a>
        <?php endif; ?>
	    </form>
	    <?php if ($canManageDashboard): ?>
	    <div class="dpr-dashboard-actions">
            <a class="btn btn-outline" href="<?= url('/reports/periodic') ?>">Периодический отчёт</a>
	        <button class="btn btn-outline" type="button" data-dashboard-toggle>Переставить</button>
	        <button class="btn" type="button" data-dashboard-reset hidden>Сброс</button>
	        <button class="btn btn--red" type="button" onclick="window.print()">Экспорт</button>
	    </div>
	    <?php endif; ?>
	</section>

	<section class="dpr-grid dpr-director" data-dashboard-grid data-dashboard-version="golem-v1">
    <section class="dpr-metrics dpr-span-12" aria-label="Метрики Штурмана">
        <?php foreach ($metricCards as $metric): ?>
            <a class="dpr-metric <?= e($metric['class']) ?>" href="<?= e($metric['href']) ?>">
                <strong><?= (int) $metric['value'] ?></strong>
                <span><?= e($metric['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </section>

        <section class="panel dpr-block dpr-block--full dpr-span-12" id="dpr-task-statistics" data-dashboard-widget="task-statistics">
            <div class="panel__head dpr-block__head">
                <h2>Статистика задач</h2>
                <span><?= e((int) ($taskStatistics['overall']['total'] ?? 0)) ?> закрытых</span>
                <?php $reorderControls(); ?>
            </div>
            <section class="metric-row project-summary-metrics cost-summary-metrics">
                <div class="metric"><span><?= e($formatHours($taskStatistics['overall']['avg_planned_hours'] ?? 0)) ?></span><strong>Средний план, ч</strong></div>
                <div class="metric"><span><?= e($formatHours($taskStatistics['overall']['avg_actual_hours'] ?? 0)) ?></span><strong>Средний факт, ч</strong></div>
                <div class="metric"><span><?= e($formatHours($taskStatistics['overall']['avg_cycle_days'] ?? 0)) ?></span><strong>Средний срок, дн.</strong></div>
                <div class="metric"><span><?= e($formatPercent($taskStatistics['overall']['over_plan_percent'] ?? 0)) ?></span><strong>Выше плана</strong></div>
            </section>
            <div class="analytics-grid">
                <div class="analytics-panel">
                    <div class="panel__head"><h2>По разделам</h2><span class="muted">текущий фильтр</span></div>
                    <div class="table-wrap table-wrap--compact dpr-table-wrap">
                        <table class="data-table data-table--compact dpr-table">
                            <thead><tr><th>Раздел</th><th>План</th><th>Факт</th><th>Срок</th><th>Выше плана</th></tr></thead>
                            <tbody><?php $taskStatsRows($taskStatistics['by_discipline'] ?? []); ?></tbody>
                        </table>
                    </div>
                </div>
                <div class="analytics-panel">
                    <div class="panel__head"><h2>По типам</h2><span class="muted">работа, выдачи, задания</span></div>
                    <div class="table-wrap table-wrap--compact dpr-table-wrap">
                        <table class="data-table data-table--compact dpr-table">
                            <thead><tr><th>Тип</th><th>План</th><th>Факт</th><th>Срок</th><th>Выше плана</th></tr></thead>
                            <tbody><?php $taskStatsRows($taskStatistics['by_type'] ?? []); ?></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <section class="panel dpr-block dpr-block--full dpr-span-12 process-control-panel" id="dpr-process-control" data-dashboard-widget="process-control">
            <div class="panel__head dpr-block__head">
                <h2>Процессный контроль</h2>
                <span>локально по задачам, проверкам и табелю</span>
                <?php $reorderControls(); ?>
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
            <div class="analytics-grid process-control-grid">
                <div class="analytics-panel">
                    <div class="panel__head"><h2>По статусам</h2><span class="muted">сколько висит в каждом этапе</span></div>
                    <div class="table-wrap table-wrap--compact dpr-table-wrap">
                        <table class="data-table data-table--compact dpr-table data-no-column-filters">
                            <thead><tr><th>Статус</th><th>Задач</th><th>Среднее</th><th>Максимум</th></tr></thead>
                            <tbody><?php $processStatusTable($processStatusRows); ?></tbody>
                        </table>
                    </div>
                </div>
                <div class="analytics-panel">
                    <div class="panel__head"><h2>По отделам</h2><span class="muted">очереди и возвраты</span></div>
                    <div class="table-wrap table-wrap--compact dpr-table-wrap">
                        <table class="data-table data-table--compact dpr-table data-no-column-filters">
                            <thead><tr><th>Отдел</th><th>Открыто</th><th>Проср.</th><th>Проверка</th><th>Возвраты</th><th>Перед.</th></tr></thead>
                            <tbody><?php $processDepartmentTable($processDepartments); ?></tbody>
                        </table>
                    </div>
                </div>
                <div class="analytics-panel">
                    <div class="panel__head"><h2>Застрявшие задачи</h2><span class="muted">первые кандидаты на разбор</span></div>
                    <div class="table-wrap table-wrap--compact dpr-table-wrap">
                        <table class="data-table data-table--compact dpr-table data-no-column-filters">
                            <thead><tr><th>Задача</th><th>Статус</th><th>Исполнитель</th><th>Дней</th></tr></thead>
                            <tbody><?php $processSlowTaskTable($processSlowTasks); ?></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <section class="panel dpr-block dpr-block--full dpr-span-12" id="dpr-people-distribution" data-dashboard-widget="people-distribution">
            <div class="panel__head dpr-block__head">
                <h2>Распределение людей</h2>
                <span><?= count($peopleMatrix) ?> × <?= count($objectColumns) ?></span>
                <?php $reorderControls(); ?>
            </div>
            <div class="dpr-matrix-filters" data-people-matrix-filters>
                <div class="dpr-picker" data-people-picker="person">
                    <label>
                        <span>Человек</span>
                        <input type="search" data-people-filter="person" data-people-picker-input="person" placeholder="Поиск по списку" autocomplete="off">
                    </label>
                    <div class="people-search-results dpr-picker__results" data-people-picker-results="person" aria-label="Список людей">
                        <div class="people-search-results__head">
                            <strong>Люди</strong>
                            <span data-people-picker-count="person"><?= count($peopleFilterOptions) ?></span>
                        </div>
                        <div class="people-search-results__list">
                            <?php foreach ($peopleFilterOptions as $personOption): ?>
                                <button class="people-search-result" type="button" data-people-picker-option="person" data-filter-value="<?= e(mb_strtolower((string) $personOption['name'], 'UTF-8')) ?>" data-display-value="<?= e($personOption['name']) ?>">
                                    <strong><?= e($personOption['name']) ?></strong>
                                    <span><?= e(($personOption['department'] ?? '') ?: 'без отдела') ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <p class="muted dpr-picker__empty" data-people-picker-empty="person" hidden>По этому поиску людей не найдено.</p>
                    </div>
                </div>
                <label>
                    <span>Отдел</span>
                    <select data-people-filter="department">
                        <option value="">Все отделы</option>
                        <?php foreach ($peopleDepartments as $department): ?>
                            <option value="<?= e(mb_strtolower($department, 'UTF-8')) ?>"><?= e($department) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="dpr-picker" data-people-picker="object">
                    <label>
                        <span>Объект</span>
                        <input type="search" data-people-filter="object" data-people-picker-input="object" placeholder="Поиск по списку" autocomplete="off">
                    </label>
                    <div class="people-search-results dpr-picker__results" data-people-picker-results="object" aria-label="Список объектов">
                        <div class="people-search-results__head">
                            <strong>Объекты</strong>
                            <span data-people-picker-count="object"><?= count($objectColumns) ?></span>
                        </div>
                        <div class="people-search-results__list">
                            <?php foreach ($objectColumns as $objectOption): ?>
                                <?php $objectText = trim(($objectOption['code'] !== '' ? $objectOption['code'] . ' ' : '') . $objectOption['name']); ?>
                                <button class="people-search-result" type="button" data-people-picker-option="object" data-filter-value="<?= e(mb_strtolower($objectText, 'UTF-8')) ?>" data-display-value="<?= e($objectText) ?>">
                                    <strong><?= e($objectOption['name']) ?></strong>
                                    <span><?= e($objectOption['code'] ?: 'без кода') ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <p class="muted dpr-picker__empty" data-people-picker-empty="object" hidden>По этому поиску объектов не найдено.</p>
                    </div>
                </div>
                <label>
                    <span>Состояние</span>
                    <select data-people-filter="state">
                        <option value="">Все</option>
                        <option value="bad">Просрочено</option>
                        <option value="warn">Риск</option>
                        <option value="ok">В графике</option>
                    </select>
                </label>
            </div>
        <div class="table-wrap dpr-table-wrap dpr-table-wrap--wide dpr-people-matrix-wrap">
            <table class="data-table data-table--compact dpr-table dpr-people-matrix">
                <thead>
                <tr>
                    <th class="dpr-matrix-person">Люди</th>
                    <?php foreach ($objectColumns as $object): ?>
                        <th class="dpr-matrix-object" data-matrix-object-id="<?= (int) $object['id'] ?>" data-matrix-object-text="<?= e(mb_strtolower(trim(($object['code'] !== '' ? $object['code'] . ' ' : '') . $object['name']), 'UTF-8')) ?>">
                            <?= e($object['name']) ?>
                            <?php if ($object['code'] !== ''): ?><small><?= e($object['code']) ?></small><?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($peopleMatrix as $person): ?>
                    <tr data-matrix-person-row data-matrix-person-text="<?= e(mb_strtolower($person['name'], 'UTF-8')) ?>" data-matrix-department="<?= e(mb_strtolower($person['department'] ?: '', 'UTF-8')) ?>">
                        <th class="dpr-matrix-person" scope="row">
                            <?= e($person['name']) ?>
                            <small><?= e($person['department'] ?: '—') ?></small>
                        </th>
                        <?php foreach ($objectColumns as $objectId => $object): ?>
                            <?php $row = $person['cells'][$objectId] ?? null; ?>
                            <?php if ($row === null): ?>
                                <td class="dpr-matrix-empty"></td>
                                <?php continue; ?>
                            <?php endif; ?>
                            <?php
                            $overdueTasks = (int) ($row['overdue_tasks'] ?? 0);
                            $unplannedTasks = (int) ($row['unplanned_tasks'] ?? 0);
                            $daysToNearest = $row['days_to_nearest'];
                            $stateLabel = 'В графике';
                            $cellClass = 'dpr-matrix-cell--ok';
                            if ($overdueTasks > 0) {
                                $stateLabel = 'Просрочено ' . $overdueTasks;
                                $cellClass = 'dpr-matrix-cell--bad';
                                $stateKey = 'bad';
                            } elseif ($daysToNearest !== null && (int) $daysToNearest <= 2) {
                                $stateLabel = 'Срок близко';
                                $cellClass = 'dpr-matrix-cell--warn';
                                $stateKey = 'warn';
                            } elseif ($unplannedTasks > 0) {
                                $stateLabel = 'Без плана ' . $unplannedTasks;
                                $cellClass = 'dpr-matrix-cell--warn';
                                $stateKey = 'warn';
                            } else {
                                $stateKey = 'ok';
                            }
                            $nearest = format_date($row['nearest_deadline'] ?? '') ?: 'без срока';
                            $latest = format_date($row['latest_deadline'] ?? '') ?: '—';
                            $deadlineLabel = $nearest === $latest || $latest === '—'
                                ? $nearest
                                : $nearest . ' — ' . $latest;
                            ?>
                            <td class="clickable dpr-matrix-cell <?= e($cellClass) ?>" data-href="<?= e($distributionHref($row)) ?>" data-matrix-object-id="<?= (int) $objectId ?>" data-matrix-state="<?= e($stateKey) ?>">
                                <strong><?= (int) $row['active_tasks'] ?> задач</strong>
                                <span>до <?= e($deadlineLabel) ?></span>
                                <small><?= e($formatHours($row['remaining_hours'] ?? 0)) ?> ч ост. · <?= e($formatHours($row['planned_hours'] ?? 0)) ?>/<?= e($formatHours($row['actual_hours'] ?? 0)) ?></small>
                                <em><?= e($stateLabel) ?></em>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$peopleMatrix): $emptyRow(max(1, count($objectColumns) + 1)); endif; ?>
                </tbody>
            </table>
        </div>
    </section>

        <section class="panel dpr-block dpr-block--full dpr-span-12" data-dashboard-widget="overdue">
            <div class="panel__head dpr-block__head">
                <h2>Просроченные без закрытия</h2>
                <span><?= count($overdueWithoutClose) ?></span>
                <?php $reorderControls(); ?>
            </div>
        <div class="table-wrap table-wrap--compact dpr-table-wrap dpr-table-wrap--wide">
            <table class="data-table data-table--compact dpr-table">
                <thead>
                <tr>
                    <th>Проект</th>
                    <th>ID</th>
                    <th>Задача</th>
                    <th>Исполнитель</th>
                    <th>Срок</th>
                    <th>Просрочено</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($overdueWithoutClose as $row): ?>
                    <tr class="clickable dpr-row-danger" data-href="<?= url('/tasks/' . $row['id']) ?>" data-task-drawer-href="<?= url('/tasks/' . $row['id']) ?>">
                        <td><?= e($row['project_code']) ?></td>
                        <td>#<?= (int) $row['id'] ?></td>
                        <td><?= e($row['title']) ?></td>
                        <td><?= e($row['assignee_name'] ?: '—') ?></td>
                        <td class="date-red"><?= e(format_date($row['date_end']) ?: '—') ?></td>
                        <td class="metric-value--bad"><?= (int) $row['overdue_days'] ?> дн.</td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$overdueWithoutClose): $emptyRow(6); endif; ?>
                </tbody>
            </table>
        </div>
    </section>

	    <section class="panel dpr-block dpr-span-6" id="dpr-approvals" data-dashboard-widget="approvals">
	                <div class="panel__head dpr-block__head">
	                    <h2>Реестр согласований</h2>
	                    <span><?= count($approvalRegistry) ?></span>
	                    <?php $reorderControls(); ?>
	                </div>
                <div class="table-wrap table-wrap--compact dpr-table-wrap">
                    <table class="data-table data-table--compact dpr-table">
                        <thead>
                        <tr>
                            <th>Проект</th>
                            <th>Задача</th>
                            <th>Этап</th>
                            <th>Ждёт от</th>
                            <th>Дней</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($approvalRegistry as $row): ?>
                            <tr class="clickable <?= (int) $row['wait_days'] > 3 ? 'dpr-row-warn' : '' ?>" data-href="<?= url('/tasks/' . $row['id']) ?>" data-task-drawer-href="<?= url('/tasks/' . $row['id']) ?>">
                                <td><?= e($row['project_code']) ?></td>
                                <td>#<?= (int) $row['id'] ?> · <?= e($row['title']) ?></td>
                                <td><?= e($row['stage_label']) ?></td>
                                <td><?= e($row['waiting_for']) ?></td>
                                <td class="<?= (int) $row['wait_days'] > 3 ? 'metric-value--warn' : '' ?>"><?= (int) $row['wait_days'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$approvalRegistry): $emptyRow(5); endif; ?>
                        </tbody>
	                    </table>
	                </div>
	            </section>

	    <section class="panel dpr-block dpr-span-6" id="dpr-data" data-dashboard-widget="data">
	                <div class="panel__head dpr-block__head">
	                    <h2>Реестр исходных данных</h2>
	                    <span><?= count($dataRegistry) ?></span>
	                    <?php $reorderControls(); ?>
	                </div>
                <div class="table-wrap table-wrap--compact dpr-table-wrap">
                    <table class="data-table data-table--compact dpr-table">
                        <thead>
                        <tr>
                            <th>Проект</th>
                            <th>Раздел</th>
                            <th>Что ждём</th>
                            <th>От кого</th>
                            <th>Срок</th>
                            <th>Блокирует</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($dataRegistry as $row): ?>
                            <tr class="clickable <?= !empty($row['is_overdue']) ? 'dpr-row-danger' : '' ?>" data-href="<?= url('/projects/' . $row['project_id'] . '/data') ?>">
                                <td><?= e($row['project_code']) ?></td>
                                <td><?= e($row['section_code'] ?: '—') ?></td>
                                <td><?= e($row['missing_data'] ?: '—') ?></td>
                                <td><?= e($row['responsible'] ?: '—') ?></td>
                                <td class="<?= !empty($row['is_overdue']) ? 'date-red' : e(deadline_state_class($row['date_received_plan'] ?? null, $today)) ?>"><?= e(format_date($row['date_received_plan']) ?: '—') ?></td>
                                <td><?= e($row['blocking_label']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$dataRegistry): $emptyRow(6); endif; ?>
                        </tbody>
	                    </table>
	                </div>
	            </section>

	    <section class="panel dpr-block dpr-span-6" id="dpr-issues" data-dashboard-widget="issues">
	                <div class="panel__head dpr-block__head">
	                    <h2>Открытые вопросы</h2>
	                    <span><?= count($openIssues) ?></span>
	                    <?php $reorderControls(); ?>
	                </div>
                <div class="table-wrap table-wrap--compact dpr-table-wrap">
                    <table class="data-table data-table--compact dpr-table">
                        <thead>
                        <tr>
                            <th>Проект</th>
                            <th>Шифр</th>
                            <th>Вопрос</th>
                            <th>Ответственный</th>
                            <th>Дней открыт</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($openIssues as $row): ?>
                            <?php $issueHref = !empty($row['blocking_task_id']) ? '/tasks/' . (int) $row['blocking_task_id'] : '/projects/' . (int) $row['project_id'] . '/issues'; ?>
                            <tr class="clickable <?= (int) $row['open_days'] > 14 ? 'dpr-row-danger' : '' ?>" data-href="<?= url($issueHref) ?>">
                                <td><?= e($row['project_code']) ?></td>
                                <td><?= e($row['code_label']) ?></td>
                                <td><?= e($row['issue']) ?></td>
                                <td><?= e($row['assignee_name'] ?: '—') ?></td>
                                <td class="<?= (int) $row['open_days'] > 14 ? 'metric-value--bad' : '' ?>"><?= (int) $row['open_days'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$openIssues): $emptyRow(5); endif; ?>
                        </tbody>
	                    </table>
	                </div>
	            </section>

	    <section class="panel dpr-block dpr-span-6" data-dashboard-widget="exchange">
	                <div class="panel__head dpr-block__head">
	                    <h2>Обмен заданиями — просроченные</h2>
	                    <span><?= count($exchangeOverdue) ?></span>
	                    <?php $reorderControls(); ?>
	                </div>
                <div class="table-wrap table-wrap--compact dpr-table-wrap">
                    <table class="data-table data-table--compact dpr-table">
                        <thead>
                        <tr>
                            <th>Проект</th>
                            <th>От</th>
                            <th>К</th>
                            <th>Задание</th>
                            <th>Срок</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($exchangeOverdue as $row): ?>
                            <tr class="clickable dpr-row-danger" data-href="<?= url('/projects/' . $row['project_id'] . '/exchange') ?>">
                                <td><?= e($row['project_code']) ?></td>
                                <td><?= e($row['from_section'] ?: '—') ?></td>
                                <td><?= e($row['to_section'] ?: '—') ?></td>
                                <td><?= e($row['assignment'] ?: '—') ?></td>
                                <td class="date-red"><?= e(format_date($row['deadline']) ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$exchangeOverdue): $emptyRow(5); endif; ?>
                        </tbody>
	                    </table>
	                </div>
	            </section>

	    <section class="panel dpr-block dpr-span-6" data-dashboard-widget="issuances">
	                <div class="panel__head dpr-block__head">
	                    <h2>Реестр выдач томов</h2>
	                    <span><?= count($volumeIssuances) ?></span>
	                    <?php $reorderControls(); ?>
	                </div>
                <div class="table-wrap table-wrap--compact dpr-table-wrap">
                    <table class="data-table data-table--compact dpr-table">
                        <thead>
                        <tr>
                            <th>Проект</th>
                            <th>Том/шифр</th>
                            <th>Выдач</th>
                            <th>Последняя</th>
                            <th>Статус</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($volumeIssuances as $row): ?>
                            <tr class="clickable" data-href="<?= url('/tasks/' . $row['task_id']) ?>" data-task-drawer-href="<?= url('/tasks/' . $row['task_id']) ?>">
                                <td><?= e($row['project_code']) ?></td>
                                <td><?= e($row['volume_label']) ?></td>
                                <td><?= (int) $row['issue_count'] ?></td>
                                <td><?= e(format_date($row['last_issued_at']) ?: '—') ?></td>
                                <td><span class="dpr-state <?= e($issuanceStatusClass($row['last_status'])) ?>"><?= e($issuanceStatusLabel($row['last_status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$volumeIssuances): $emptyRow(5); endif; ?>
                        </tbody>
	                    </table>
	                </div>
	            </section>

	    <section class="panel dpr-block dpr-span-6" data-dashboard-widget="schedule">
	                <div class="panel__head dpr-block__head">
	                    <h2>График выдачи — ближайшие 14 дней</h2>
	                    <span><?= count($upcomingSchedule) ?></span>
	                    <?php $reorderControls(); ?>
	                </div>
                <div class="table-wrap table-wrap--compact dpr-table-wrap">
                    <table class="data-table data-table--compact dpr-table">
                        <thead>
                        <tr>
                            <th>Проект</th>
                            <th>Том</th>
                            <th>Исполнитель</th>
                            <th>Дата</th>
                            <th>Осталось</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($upcomingSchedule as $row): ?>
                            <?php $daysLeft = (int) $row['days_left']; ?>
                            <tr class="clickable" data-href="<?= url('/projects/' . $row['project_id'] . '/schedule') ?>">
                                <td><?= e($row['project_code']) ?></td>
                                <td><?= e($row['volume_label']) ?></td>
                                <td><?= e($row['assignee_name'] ?: '—') ?></td>
                                <td><?= e(format_date($row['rd_date_plan']) ?: '—') ?></td>
                                <td class="<?= e($daysLeftClass($daysLeft)) ?>"><?= e($daysLeftLabel($daysLeft)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$upcomingSchedule): $emptyRow(5); endif; ?>
                        </tbody>
	                    </table>
	                </div>
	            </section>

	    <section class="panel dpr-block dpr-span-6" data-dashboard-widget="workload">
	                <div class="panel__head dpr-block__head">
	                    <h2>Нагрузка по исполнителям</h2>
	                    <span><?= count($workload) ?></span>
	                    <?php $reorderControls(); ?>
	                </div>
                <div class="table-wrap table-wrap--compact dpr-table-wrap">
                    <table class="data-table data-table--compact dpr-table">
                        <thead>
                        <tr>
                            <th>Исполнитель</th>
                            <th>Бар</th>
                            <th>Задач</th>
                            <th>Просрочено</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($workload as $row): ?>
                            <?php
                            $openTasks = (int) $row['open_tasks'];
                            $overdueTasks = (int) $row['overdue_tasks'];
                            $loadPercent = min(100, (int) round(($openTasks / 10) * 100));
                            $loadClass = $loadPercent <= 50 ? 'workload-fill--normal' : ($loadPercent <= 80 ? 'workload-fill--high' : 'workload-fill--over');
                            ?>
                            <tr class="clickable" data-href="<?= e($personReportHref((int) $row['id'], (string) ($row['name'] ?? ''))) ?>">
                                <td><?= e($row['name']) ?></td>
                                <td>
                                    <span class="workload-bar dpr-load-bar"><span class="<?= e($loadClass) ?>" style="width: <?= $loadPercent ?>%"></span></span>
                                </td>
                                <td><?= $openTasks ?></td>
                                <td class="<?= $overdueTasks > 0 ? 'metric-value--bad' : '' ?>"><?= $overdueTasks ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$workload): $emptyRow(4); endif; ?>
                        </tbody>
	                    </table>
	                </div>
	            </section>
	</section>
