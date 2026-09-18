<?php
$titles = [
    'schedule' => 'График выдачи РД',
    'sections' => 'Перечень разделов ПД / комплектов РД',
    'issues' => 'Текущие вопросы / блокеры',
    'data' => 'Реестр исходных данных',
    'exchange' => 'Обмен заданиями между инженерами',
    'costs' => 'Планирование затрат по СБЦ',
];
$volumeItems = $dictionaries['volume'] ?? [];
$sectionItems = $dictionaries['section'] ?? [];
$sectionCodeItems = $dictionaries['section_code'] ?? [];

$dictionaryOptions = static function (array $items): array {
    return array_map(static fn (array $item): array => [
        'value' => (string) $item['value'],
        'label' => (string) ($item['label'] ?: $item['value']),
    ], $items);
};
$valueOptions = static fn (array $values): array => array_map(static fn (string $value): array => [
    'value' => $value,
    'label' => $value,
], $values);
$usersOptions = array_map(static fn (array $user): array => [
    'value' => (string) $user['id'],
    'label' => (string) $user['name'],
], $users);
$counterpartyOptions = array_map(static fn (array $counterparty): array => [
    'value' => (string) $counterparty['id'],
    'label' => trim((string) $counterparty['company'] . ((string) ($counterparty['role'] ?? '') !== '' ? ' · ' . (string) $counterparty['role'] : '') . ((string) ($counterparty['representative'] ?? '') !== '' ? ' · ' . (string) $counterparty['representative'] : '')),
], $counterparties ?? []);
$sbcOptions = $sbcItems ?? [];
$canApproveLabor = (bool) ($canApproveLabor ?? false);
$taskOptions = array_map(static fn (array $task): array => [
    'value' => (string) $task['id'],
    'label' => '#' . (int) $task['id'] . ' · ' . (string) $task['title'],
], $projectTasks ?? []);
$laborMethodOptions = array_map(
    static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
    array_keys(\App\Services\CostPlanService::LABOR_METHODS),
    array_values(\App\Services\CostPlanService::LABOR_METHODS)
);

$columnsByKind = [
    'schedule' => [
        ['key' => 'task_id', 'label' => 'Задача', 'type' => 'task_select', 'options' => $taskOptions],
        ['key' => 'volume', 'label' => 'Том', 'type' => 'select', 'options' => $dictionaryOptions($volumeItems)],
        ['key' => 'section', 'label' => 'Раздел', 'type' => 'select', 'options' => $dictionaryOptions($sectionItems)],
        ['key' => 'rd_date_plan', 'label' => 'Плановая дата', 'type' => 'date'],
        ['key' => 'assignee_name', 'input' => 'assignee_id', 'label' => 'Кому', 'type' => 'select', 'options' => $usersOptions],
        ['key' => 'date_issued', 'label' => 'Факт выдачи', 'type' => 'date'],
        ['key' => 'issue_status', 'label' => 'Статус', 'type' => 'select', 'options' => $valueOptions(['Планируется', 'В работе', 'На проверке', 'Выдано', 'Выдана', 'Замечания', 'Принята', 'Проблема'])],
        ['key' => 'comments', 'label' => 'Комментарий', 'type' => 'textarea'],
    ],
    'sections' => [
        ['key' => 'task_id', 'label' => 'Задача', 'type' => 'task_select', 'options' => $taskOptions],
        ['key' => 'volume', 'label' => 'Том', 'type' => 'select', 'options' => $dictionaryOptions($volumeItems)],
        ['key' => 'code', 'label' => 'Шифр', 'type' => 'select', 'options' => $dictionaryOptions($sectionCodeItems)],
        ['key' => 'title', 'label' => 'Наименование', 'type' => 'text'],
        ['key' => 'status', 'label' => 'Статус', 'type' => 'text'],
        ['key' => 'date_start', 'label' => 'Дата начала', 'type' => 'date'],
        ['key' => 'date_end', 'label' => 'Дата окончания', 'type' => 'date'],
        ['key' => 'assignee_name', 'input' => 'assignee_id', 'label' => 'Ответственный', 'type' => 'select', 'options' => $usersOptions],
        ['key' => 'reviewer_name', 'input' => 'reviewer_id', 'label' => 'Проверяющий', 'type' => 'select', 'options' => $usersOptions],
        ['key' => 'comments', 'label' => 'Комментарий', 'type' => 'textarea'],
    ],
    'issues' => [
        ['key' => 'blocking_task_id', 'label' => 'Блокирует задачу', 'type' => 'task_select', 'options' => $taskOptions],
        ['key' => 'num', 'label' => '№', 'type' => 'number'],
        ['key' => 'section_code', 'label' => 'Шифр/марка', 'type' => 'select', 'options' => $dictionaryOptions($sectionCodeItems)],
        ['key' => 'issue', 'label' => 'Вопрос', 'type' => 'textarea', 'required' => true],
        ['key' => 'assignee_name', 'input' => 'assignee_id', 'label' => 'Ответственный', 'type' => 'select', 'options' => $usersOptions],
        ['key' => 'stage', 'label' => 'Стадия', 'type' => 'text'],
        ['key' => 'date_raised', 'label' => 'Дата вопроса', 'type' => 'date'],
        ['key' => 'answer', 'label' => 'Ответ', 'type' => 'textarea'],
        ['key' => 'notes', 'label' => 'Примечание', 'type' => 'textarea'],
        ['key' => 'status', 'label' => 'Статус', 'type' => 'select', 'options' => [
            ['value' => 'open', 'label' => 'Открыт'],
            ['value' => 'in_progress', 'label' => 'В работе'],
            ['value' => 'done', 'label' => 'Закрыт'],
        ]],
    ],
    'data' => [
        ['key' => 'blocking_task_ids', 'label' => 'Блокирует задачи', 'type' => 'text'],
        ['key' => 'num', 'label' => '№', 'type' => 'number'],
        ['key' => 'section_code', 'label' => 'Марка/шифр', 'type' => 'select', 'options' => $dictionaryOptions($sectionCodeItems)],
        ['key' => 'missing_data', 'label' => 'Отсутствующие ИД', 'type' => 'textarea'],
        ['key' => 'responsible', 'label' => 'Ответственный', 'type' => 'text'],
        ['key' => 'status', 'label' => 'Статус', 'type' => 'select', 'options' => [
            ['value' => 'waiting', 'label' => 'Ждём'],
            ['value' => 'received', 'label' => 'Получено'],
            ['value' => 'not_needed', 'label' => 'Не требуется'],
        ]],
        ['key' => 'date_requested', 'label' => 'Дата запроса', 'type' => 'date'],
        ['key' => 'date_received_plan', 'label' => 'Дата получения', 'type' => 'date'],
        ['key' => 'impact', 'label' => 'Влияние', 'type' => 'textarea'],
        ['key' => 'comments', 'label' => 'Комментарий', 'type' => 'textarea'],
    ],
    'exchange' => [
        ['key' => 'direction', 'label' => 'Тип', 'type' => 'exchange_direction', 'options' => [
            ['value' => 'outgoing', 'label' => 'Выдаём'],
            ['value' => 'incoming', 'label' => 'Ждём'],
        ]],
        ['key' => 'task_id', 'label' => 'Задача', 'type' => 'task_select', 'options' => $taskOptions],
        ['key' => 'num', 'label' => '№', 'type' => 'number'],
        ['key' => 'assignment', 'label' => 'Задание', 'type' => 'textarea'],
        ['key' => 'from_party_name', 'label' => 'От кого', 'type' => 'party', 'user_input' => 'from_user_id', 'counterparty_input' => 'from_counterparty_id', 'external_input' => 'from_external_name', 'users' => $usersOptions, 'counterparties' => $counterpartyOptions],
        ['key' => 'to_party_name', 'label' => 'Кому / от кого ждём', 'type' => 'party', 'user_input' => 'to_user_id', 'counterparty_input' => 'to_counterparty_id', 'external_input' => 'to_external_name', 'users' => $usersOptions, 'counterparties' => $counterpartyOptions],
        ['key' => 'from_section', 'label' => 'От раздела', 'type' => 'select', 'options' => $dictionaryOptions($sectionCodeItems)],
        ['key' => 'to_section', 'label' => 'К разделу', 'type' => 'select', 'options' => $dictionaryOptions($sectionCodeItems)],
        ['key' => 'file_url', 'label' => 'Samba', 'type' => 'text'],
        ['key' => 'date_issued', 'label' => 'Дата выдачи', 'type' => 'date'],
        ['key' => 'deadline', 'label' => 'Срок', 'type' => 'date'],
        ['key' => 'status', 'label' => 'Статус', 'type' => 'select', 'options' => [
            ['value' => 'pending', 'label' => 'Ожидает'],
            ['value' => 'in_progress', 'label' => 'В работе'],
            ['value' => 'done', 'label' => 'Готово'],
            ['value' => 'blocked', 'label' => 'Блокер'],
        ]],
        ['key' => 'comments', 'label' => 'Комментарий', 'type' => 'textarea'],
    ],
    'costs' => [
        ['key' => 'num', 'label' => '№', 'type' => 'number'],
        ['key' => 'section_code', 'label' => 'Раздел', 'type' => 'select', 'options' => $dictionaryOptions($sectionCodeItems)],
        ['key' => 'sbc_item_label', 'input' => 'sbc_item_id', 'label' => 'Пункт СБЦ', 'type' => 'sbc_select', 'options' => $sbcOptions],
        ['key' => 'sbc_collection', 'label' => 'Сборник СБЦ', 'type' => 'text'],
        ['key' => 'sbc_table', 'label' => 'Таблица/пункт', 'type' => 'text'],
        ['key' => 'work_name', 'label' => 'Работа', 'type' => 'textarea'],
        ['key' => 'unit', 'label' => 'Показатель', 'type' => 'text'],
        ['key' => 'labor_hours', 'label' => 'Чел-ч', 'type' => 'hours', 'step' => '0.25'],
        ['key' => 'labor_estimate_method', 'label' => 'Метод труд.', 'type' => 'labor_method', 'options' => $laborMethodOptions],
        ['key' => 'labor_executor_hours', 'label' => 'Исполнитель', 'type' => 'hours', 'step' => '0.25'],
        ['key' => 'labor_gip_hours', 'label' => 'ГИП', 'type' => 'hours', 'step' => '0.25'],
        ['key' => 'labor_adjustment_hours', 'label' => 'Корр.', 'type' => 'hours', 'step' => '0.25'],
        ['key' => 'labor_directive_hours', 'label' => 'Директива', 'type' => 'hours', 'step' => '0.25'],
        ['key' => 'labor_norm_hours', 'label' => 'Норма', 'type' => 'hours', 'step' => '0.25'],
        ['key' => 'labor_productivity_rate', 'label' => 'Выработка/день', 'type' => 'decimal', 'step' => '0.0001'],
        ['key' => 'labor_productivity_coeff', 'label' => 'К модели', 'type' => 'decimal', 'step' => '0.0001'],
        ['key' => 'labor_basis', 'label' => 'Обоснование труд.', 'type' => 'textarea'],
        ['key' => 'labor_approval_status', 'label' => 'Утв. труд.', 'type' => 'labor_approval'],
        ['key' => 'quantity', 'label' => 'Кол-во', 'type' => 'decimal', 'step' => '0.001'],
        ['key' => 'base_price', 'label' => 'Базовая цена', 'type' => 'money', 'step' => '0.01'],
        ['key' => 'stage_percent', 'label' => 'Стадия, %', 'type' => 'percent', 'step' => '0.01'],
        ['key' => 'complexity_coeff', 'label' => 'К сложн.', 'type' => 'decimal', 'step' => '0.0001'],
        ['key' => 'deflator_coeff', 'label' => 'Индекс', 'type' => 'decimal', 'step' => '0.0001'],
        ['key' => 'adjustment_coeff', 'label' => 'К проч.', 'type' => 'decimal', 'step' => '0.0001'],
        ['key' => 'planned_cost', 'label' => 'Деньги, тыс. руб.', 'type' => 'computed_money'],
        ['key' => 'price_level', 'label' => 'Уровень цен', 'type' => 'text'],
        ['key' => 'justification', 'label' => 'Обоснование', 'type' => 'textarea'],
        ['key' => 'comments', 'label' => 'Комментарий', 'type' => 'textarea'],
    ],
];
$columns = $columnsByKind[$kind];
$viewMode = $viewMode ?? 'table';
$costTotal = 0.0;
$costBaseTotal = 0.0;
$costLaborTotal = 0.0;
if ($kind === 'costs') {
    foreach ($rows as $row) {
        $costFactor = max(0.000001, ((float) ($row['stage_percent'] ?? 100) / 100)
            * (float) ($row['complexity_coeff'] ?? 1)
            * (float) ($row['deflator_coeff'] ?? 1)
            * (float) ($row['adjustment_coeff'] ?? 1));
        $costTotal += (float) ($row['planned_cost'] ?? 0);
        $costBaseTotal += (float) ($row['planned_cost'] ?? 0) / $costFactor;
        $costLaborTotal += (float) ($row['labor_hours'] ?? 0);
    }
}

$formatDecimal = static function (mixed $value, int $precision = 2): string {
    $formatted = number_format((float) $value, $precision, '.', ' ');
    if (str_contains($formatted, '.')) {
        $formatted = rtrim(rtrim($formatted, '0'), '.');
    }

    return $formatted;
};

$displayValue = static function (array $row, array $column) use ($formatDecimal): string {
    $value = (string) ($row[$column['key']] ?? '');
    if (($column['type'] ?? '') === 'date') {
        return format_date($value);
    }
    if (in_array(($column['type'] ?? ''), ['money', 'computed_money'], true)) {
        return $formatDecimal($value, 2);
    }
    if (($column['type'] ?? '') === 'percent') {
        return $formatDecimal($value, 2) . ' %';
    }
    if (($column['type'] ?? '') === 'hours') {
        return $formatDecimal($value, 2);
    }
    if (($column['type'] ?? '') === 'labor_method') {
        return \App\Services\CostPlanService::LABOR_METHODS[$value] ?? $value;
    }
    if (($column['type'] ?? '') === 'exchange_direction') {
        return $value === 'incoming' ? 'Ждём' : 'Выдаём';
    }
    if (($column['type'] ?? '') === 'labor_approval') {
        return labor_approval_status_label($value);
    }
    if (($column['type'] ?? '') === 'decimal') {
        return $formatDecimal($value, 4);
    }

    return $value;
};

$renderInput = static function (array $column): void {
    $name = $column['input'] ?? $column['key'];
    $required = !empty($column['required']) ? ' required' : '';
    $type = $column['type'] ?? 'text';
    if ($type === 'party') {
        echo '<div class="party-inputs">';
        echo '<select form="tab-add" name="' . e($column['user_input']) . '"><option value="">Пользователь</option>';
        foreach (($column['users'] ?? []) as $option) {
            echo '<option value="' . e($option['value']) . '">' . e($option['label']) . '</option>';
        }
        echo '</select>';
        echo '<select form="tab-add" name="' . e($column['counterparty_input']) . '"><option value="">Контрагент</option>';
        foreach (($column['counterparties'] ?? []) as $option) {
            echo '<option value="' . e($option['value']) . '">' . e($option['label']) . '</option>';
        }
        echo '</select>';
        echo '<input form="tab-add" type="text" name="' . e($column['external_input']) . '" placeholder="Внешняя сторона">';
        echo '</div>';
        return;
    }
    if ($type === 'select' || $type === 'task_select' || $type === 'sbc_select' || $type === 'labor_method' || $type === 'exchange_direction') {
        echo '<select form="tab-add" name="' . e($name) . '"' . $required . '><option value=""></option>';
        foreach (($column['options'] ?? []) as $option) {
            echo '<option value="' . e($option['value']) . '">' . e($option['label']) . '</option>';
        }
        echo '</select>';
        return;
    }
    if ($type === 'textarea') {
        echo '<textarea form="tab-add" name="' . e($name) . '" rows="1"' . $required . '></textarea>';
        return;
    }
    if ($type === 'computed_money' || $type === 'labor_approval') {
        echo '<span class="muted">авто</span>';
        return;
    }

    $htmlType = $type === 'date' ? 'date' : (in_array($type, ['number', 'decimal', 'money', 'percent', 'hours'], true) ? 'number' : 'text');
    $step = isset($column['step']) ? ' step="' . e($column['step']) . '"' : '';
    echo '<input form="tab-add" type="' . e($htmlType) . '" name="' . e($name) . '"' . $step . $required . '>';
};

$scheduleStatuses = ['Планируется', 'В работе', 'На проверке', 'Выдано', 'Выдана', 'Замечания', 'Принята', 'Проблема'];
$scheduleGroups = array_fill_keys($scheduleStatuses, []);
$canViewProjectFinance = (bool) ($canViewProjectFinance ?? false);
if ($kind === 'schedule') {
    foreach ($rows as $row) {
        $status = (string) (($row['issue_status'] ?? '') ?: ($row['rd_readiness_label'] ?? '') ?: 'Планируется');
        if (!array_key_exists($status, $scheduleGroups)) {
            $status = 'Планируется';
        }
        $scheduleGroups[$status][] = $row;
    }
}
?>
<section class="project-head project-head--tab">
    <div>
        <span class="muted"><?= e($project['code']) ?></span>
        <h2><?= e($titles[$kind] ?? 'Вкладка проекта') ?></h2>
    </div>
    <div class="toolbar__actions project-tab-actions">
        <a class="btn" href="<?= url('/projects/' . $project['id']) ?>">К проекту</a>
        <?php if ($kind === 'schedule'): ?>
            <a class="btn <?= $viewMode === 'table' ? 'is-active' : '' ?>" href="<?= url('/projects/' . $project['id'] . '/schedule') ?>">Таблица</a>
            <a class="btn <?= $viewMode === 'board' ? 'is-active' : '' ?>" href="<?= url('/projects/' . $project['id'] . '/schedule') ?>?view=board">Доска</a>
            <form method="post" action="<?= url('/projects/' . $project['id'] . '/schedule/sync') ?>">
                <?= csrf_field() ?>
                <button class="btn btn-outline" type="submit">Сформировать из задач</button>
            </form>
        <?php endif; ?>
        <?php if ($kind === 'sections'): ?>
            <form method="post" action="<?= url('/projects/' . $project['id'] . '/sections/sync') ?>">
                <?= csrf_field() ?>
                <button class="btn btn-outline" type="submit">Из задач/графика</button>
            </form>
        <?php endif; ?>
        <?php if ($kind === 'issues'): ?>
            <form method="post" action="<?= url('/projects/' . $project['id'] . '/issues/sync') ?>">
                <?= csrf_field() ?>
                <button class="btn btn-outline" type="submit">Из блокеров задач</button>
            </form>
        <?php endif; ?>
        <?php if ($kind === 'data'): ?>
            <form method="post" action="<?= url('/projects/' . $project['id'] . '/data/template') ?>">
                <?= csrf_field() ?>
                <button class="btn btn-outline" type="submit">Типовой перечень ИД</button>
            </form>
        <?php endif; ?>
        <?php if ($kind === 'exchange'): ?>
            <form method="post" action="<?= url('/projects/' . $project['id'] . '/exchange/sync') ?>">
                <?= csrf_field() ?>
                <button class="btn btn-outline" type="submit">Из задач</button>
            </form>
            <?php if (!empty($exchangeTemplateSets)): ?>
                <form method="post" action="<?= url('/projects/' . $project['id'] . '/exchange/templates/apply') ?>">
                    <?= csrf_field() ?>
                    <select name="template_set_id" required>
                        <option value="">Матрица</option>
                        <?php foreach ($exchangeTemplateSets as $set): ?>
                            <option value="<?= (int) $set['id'] ?>"><?= e($set['name']) ?> · <?= (int) ($set['items_count'] ?? 0) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline" type="submit">Добавить из матрицы</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($kind === 'costs'): ?>
            <form method="post" action="<?= url('/projects/' . $project['id'] . '/costs/sync') ?>">
                <?= csrf_field() ?>
                <button class="btn btn-outline" type="submit">Из разделов</button>
            </form>
        <?php endif; ?>
        <a class="btn" href="<?= url('/projects/' . $project['id'] . '/' . $kind) ?>?export=csv">Экспорт CSV</a>
        <a class="btn" href="<?= url('/projects/' . $project['id'] . '/' . $kind) ?>?template=csv">Шаблон CSV</a>
        <form class="csv-import-form" method="post" action="<?= url('/projects/' . $project['id'] . '/tabs/' . $kind . '/import') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="file" name="csv_file" accept=".csv,text/csv" required>
            <button class="btn btn-outline" type="submit">Импорт CSV</button>
        </form>
    </div>
</section>

<?php
$projectNavActive = in_array($kind, ['schedule', 'sections'], true) ? 'plan' : (in_array($kind, ['issues', 'data', 'exchange'], true) ? $kind : '');
require BASE_PATH . '/app/Views/projects/_navigation.php';
?>
<?php if (in_array($kind, ['schedule', 'sections'], true)): ?>
    <?php $projectPlanActive = $kind; require BASE_PATH . '/app/Views/projects/_plan_navigation.php'; ?>
<?php endif; ?>

<?php if ($kind === 'costs'): ?>
    <section class="metric-row project-summary-metrics cost-summary-metrics">
        <div class="metric"><span><?= count($rows) ?></span><strong>Позиций</strong></div>
        <div class="metric"><span><?= e($formatDecimal($costLaborTotal, 2)) ?></span><strong>Трудозатраты, чел-ч</strong></div>
        <div class="metric"><span><?= e($formatDecimal($costBaseTotal, 2)) ?></span><strong>База, тыс. руб.</strong></div>
        <div class="metric"><span><?= e($formatDecimal($costTotal, 2)) ?></span><strong>Деньги, тыс. руб.</strong></div>
        <div class="metric"><span><?= $costBaseTotal > 0 ? e($formatDecimal($costTotal / $costBaseTotal, 4)) : '0' ?></span><strong>Средний индекс</strong></div>
    </section>
<?php endif; ?>

<?php if ($kind === 'schedule' && $viewMode === 'board'): ?>
    <section class="schedule-board">
        <?php foreach ($scheduleGroups as $status => $statusRows): ?>
            <div class="schedule-board__column">
                <div class="schedule-board__head">
                    <h2><?= e($status) ?></h2>
                    <span><?= count($statusRows) ?></span>
                </div>
                <?php foreach ($statusRows as $row): ?>
                    <?php $isLate = $row['rd_date_plan'] && $row['rd_date_plan'] < date('Y-m-d') && empty($row['date_issued']); ?>
                    <article class="schedule-card <?= $isLate ? 'schedule-card--late' : '' ?>">
                        <strong><?= e($row['volume'] ?: $row['object']) ?></strong>
                        <span><?= e($row['section']) ?></span>
                        <small>План: <?= e(format_date($row['rd_date_plan'])) ?></small>
                        <small>Кому: <?= e($row['assignee_name'] ?: 'Не назначено') ?></small>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<form id="tab-add" method="post" action="<?= url('/projects/' . $project['id'] . '/tabs/' . $kind) ?>"></form>
<input form="tab-add" type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

<section class="panel sheet-panel">
    <div class="panel__head">
        <h2><?= e($titles[$kind] ?? '') ?></h2>
        <span class="muted"><?= count($rows) ?> строк</span>
    </div>
    <div class="table-wrap sheet-wrap">
        <table class="data-table sheet-table" data-no-column-filters>
            <thead>
            <tr>
                <?php foreach ($columns as $column): ?>
                    <th><?= e($column['label']) ?></th>
                <?php endforeach; ?>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $issueStatus = (string) ($row['issue_status'] ?? $row['status'] ?? '');
                $isLate = $kind === 'schedule' && $row['rd_date_plan'] && $row['rd_date_plan'] < date('Y-m-d') && empty($row['date_issued']);
                ?>
                <tr class="<?= $isLate ? 'row-danger' : '' ?>">
                    <?php foreach ($columns as $column): ?>
                        <td>
                            <?php if ($kind === 'schedule' && $column['key'] === 'issue_status'): ?>
                                <span class="status status--<?= in_array($issueStatus, ['Выдано', 'Принята'], true) ? 'done' : ($isLate || in_array($issueStatus, ['Проблема', 'Замечания'], true) ? 'overdue' : 'in_progress') ?>"><?= e($issueStatus ?: 'Планируется') ?></span>
                            <?php elseif (in_array($column['key'], ['task_id', 'blocking_task_id'], true)): ?>
                                <?php if (!empty($row[$column['key']])): ?>
                                    <a class="task-link" href="<?= url('/tasks/' . $row[$column['key']]) ?>" data-task-drawer-link>#<?= (int) $row[$column['key']] ?></a>
                                    <?php if (!empty($row['linked_task_status'])): ?>
                                        <span class="status status--<?= e($row['linked_task_status']) ?>"><?= e(task_status_label($row['linked_task_status'])) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($row['linked_task_title'])): ?><small><?= e($row['linked_task_title']) ?></small><?php endif; ?>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            <?php elseif ($column['key'] === 'blocking_task_ids'): ?>
                                <?php $blockingIds = task_id_list($row['blocking_task_ids'] ?? ''); ?>
                                <?php if ($blockingIds): ?>
                                    <div class="task-id-list">
                                        <?php foreach ($blockingIds as $blockingId): ?>
                                            <a class="task-link" href="<?= url('/tasks/' . $blockingId) ?>" data-task-drawer-link>#<?= (int) $blockingId ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            <?php elseif ($kind === 'data' && $column['key'] === 'status'): ?>
                                <span class="status status--<?= e(data_status_class($row['status'] ?? '')) ?>"><?= e(data_status_label($row['status'] ?? '')) ?></span>
                            <?php elseif ($kind === 'exchange' && $column['key'] === 'status'): ?>
                                <span class="status status--<?= e(exchange_status_class($row['status'] ?? '')) ?>"><?= e(exchange_status_label($row['status'] ?? '')) ?></span>
                            <?php elseif ($kind === 'exchange' && $column['key'] === 'file_url'): ?>
                                <?php if (!empty($row['file_url'])): ?>
                                    <a class="file-link" href="<?= e(file_link_href($row['file_url'])) ?>" target="_blank" rel="noreferrer">Открыть</a>
                                    <small><?= e($row['file_url']) ?></small>
                                <?php else: ?>
                                    <span class="muted">Нет ссылки</span>
                                <?php endif; ?>
                            <?php elseif ($kind === 'costs' && $column['key'] === 'labor_approval_status'): ?>
                                <?php
                                $laborStatus = (string) ($row['labor_approval_status'] ?? 'pending_director');
                                $laborComment = trim((string) ($row['labor_approval_comment'] ?? ''));
                                ?>
                                <div class="labor-approval-cell">
                                    <span class="status status--<?= e(labor_approval_status_class($laborStatus)) ?>"><?= e(labor_approval_status_label($laborStatus)) ?></span>
                                    <?php if (!empty($row['labor_approved_by_name']) || !empty($row['labor_approved_at'])): ?>
                                        <small><?= e(trim((string) ($row['labor_approved_by_name'] ?? '') . ' · ' . format_date($row['labor_approved_at'] ?? ''))) ?></small>
                                    <?php endif; ?>
                                    <?php if ($laborComment !== ''): ?><small><?= e($laborComment) ?></small><?php endif; ?>
                                    <?php if ($canApproveLabor): ?>
                                        <form method="post" action="<?= url('/projects/' . $project['id'] . '/costs/' . $row['id'] . '/labor-approval') ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="decision" value="approved">
                                            <button class="btn btn--red btn-sm" type="submit">Подтвердить</button>
                                        </form>
                                        <form class="labor-approval-reject" method="post" action="<?= url('/projects/' . $project['id'] . '/costs/' . $row['id'] . '/labor-approval') ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="decision" value="rejected">
                                            <textarea name="comment" rows="2" placeholder="Причина возврата" required></textarea>
                                            <button class="btn btn-outline btn-sm" type="submit">Вернуть</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <?= nl2br(e($displayValue($row, $column))) ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td></td>
                </tr>
            <?php endforeach; ?>
            <tr class="sheet-table__new-row">
                <?php foreach ($columns as $column): ?>
                    <td><?php $renderInput($column); ?></td>
                <?php endforeach; ?>
                <td><button class="btn btn--red" form="tab-add" type="submit">Добавить</button></td>
            </tr>
            </tbody>
        </table>
    </div>
</section>
