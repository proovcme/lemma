<?php
$catalog = $catalog ?? [];
$preferences = $preferences ?? [];
$report = $report ?? ['period' => [], 'rows' => [], 'groups' => [], 'metrics' => []];
$filters = $filters ?? [];
$fields = array_values((array) ($preferences['field_order'] ?? array_keys($catalog)));
$visible = array_values((array) ($preferences['visible_fields'] ?? array_keys($catalog)));
$filtersForUrl = array_filter([
    'date_from' => $report['period']['date_from'] ?? '',
    'date_to' => $report['period']['date_to'] ?? '',
    'group_by' => $report['group_by'] ?? 'general',
    'project' => $filters['project'] ?? '',
    'employee' => $filters['employee'] ?? '',
    'department' => $filters['department'] ?? '',
    'task' => $filters['task'] ?? '',
    'status' => $filters['status'] ?? '',
], static fn (mixed $value): bool => $value !== '');
$groupLabels = ['general' => 'Общий', 'people' => 'По людям', 'project' => 'По проектам', 'task' => 'По задачам'];
$formatHours = static function (mixed $hours): string {
    return $hours === null || $hours === '' ? '' : rtrim(rtrim(number_format((float) $hours, 2, '.', ' '), '0'), '.');
};
?>

<section class="reports-hero panel">
    <div class="reports-hero__main">
        <span class="muted">Месячный реестр</span>
        <h2>Люди, проекты, задачи и списанное время</h2>
        <p>По умолчанию открыт текущий календарный месяц. В реестре остаются все доступные задачи, включая задачи без списаний.</p>
    </div>
    <div class="reports-hero__actions"><a class="btn btn-outline" href="<?= url('/reports') ?>">Все отчёты</a></div>
</section>

<form class="panel reports-filter-panel" method="get" action="<?= url('/reports/monthly') ?>">
    <div class="reports-filter-grid">
        <label><span>С</span><input type="date" name="date_from" value="<?= e($report['period']['date_from'] ?? '') ?>"></label>
        <label><span>По</span><input type="date" name="date_to" value="<?= e($report['period']['date_to'] ?? '') ?>"></label>
        <label><span>Группировка</span><select name="group_by"><?php foreach ($groupLabels as $key => $label): ?><option value="<?= e($key) ?>"<?= selected($report['group_by'] ?? 'general', $key) ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label><span>Проект</span><input name="project" value="<?= e($filters['project'] ?? '') ?>" placeholder="Код или название"></label>
        <label><span>Сотрудник</span><input name="employee" value="<?= e($filters['employee'] ?? '') ?>" placeholder="Фамилия"></label>
        <label><span>Отдел</span><input name="department" value="<?= e($filters['department'] ?? '') ?>" placeholder="Отдел"></label>
        <label><span>Задача</span><input name="task" value="<?= e($filters['task'] ?? '') ?>" placeholder="Номер или название"></label>
        <label><span>Статус</span><input name="status" value="<?= e($filters['status'] ?? '') ?>" placeholder="Например, В работе"></label>
    </div>
    <div class="reports-filter-actions"><button class="btn" type="submit">Собрать реестр</button></div>
</form>

<section class="reports-export-grid">
    <article class="reports-export-card reports-export-card--primary">
        <div><span>Одна кнопка</span><strong>Месячный реестр</strong><small><?= (int) ($report['metrics']['task_count'] ?? 0) ?> задач · <?= e($formatHours($report['metrics']['hours'] ?? 0)) ?> ч списано</small></div>
        <div class="reports-export-card__actions">
            <form method="post" action="<?= url('/reports/monthly/export') ?>"><?= csrf_field() ?><?php foreach ($filtersForUrl as $key => $value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e((string) $value) ?>"><?php endforeach; ?><button class="btn btn--red" name="format" value="xlsx">Excel</button></form>
            <form method="post" action="<?= url('/reports/monthly/export') ?>"><?= csrf_field() ?><?php foreach ($filtersForUrl as $key => $value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e((string) $value) ?>"><?php endforeach; ?><button class="btn" name="format" value="csv">CSV</button></form>
        </div>
    </article>
</section>

<details class="panel reports-fields">
    <summary>Настройки моего реестра</summary>
    <form method="post" action="<?= url('/reports/monthly/settings') ?>">
        <?= csrf_field() ?>
        <p class="muted">Набор полей и группировка сохраняются только для вашей учётной записи. Доступны только проверенные поля реестра; произвольные поля БД не выполняются как запросы.</p>
        <div class="checkbox-grid">
            <?php foreach ($catalog as $key => $label): ?>
                <label><input type="checkbox" name="visible_fields[]" value="<?= e($key) ?>"<?= checked(in_array($key, $visible, true)) ?>> <?= e($label) ?></label>
            <?php endforeach; ?>
        </div>
        <?php foreach ($fields as $field): ?><input type="hidden" name="field_order[]" value="<?= e($field) ?>"><?php endforeach; ?>
        <label><span>Группировка по умолчанию</span><select name="default_group_by"><?php foreach ($groupLabels as $key => $label): ?><option value="<?= e($key) ?>"<?= selected($preferences['default_group_by'] ?? 'general', $key) ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <div class="reports-filter-actions"><button class="btn btn-outline" type="submit">Сохранить мои настройки</button></div>
    </form>
</details>

<?php if (($report['group_by'] ?? 'general') !== 'general'): ?>
    <section class="panel">
        <div class="panel__head"><h2><?= e($groupLabels[$report['group_by']] ?? 'Группы') ?></h2><span class="muted">детальные строки ниже не скрываются</span></div>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Группа</th><th>Строк</th><th>Списано, ч</th></tr></thead><tbody>
            <?php foreach ($report['groups'] as $group): ?><tr><td><?= e($group['label'] ?? '') ?></td><td><?= (int) ($group['row_count'] ?? 0) ?></td><td><?= e($formatHours($group['hours'] ?? null)) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="panel__head"><h2>Детализация</h2><span class="muted">пустое поле «Списано, ч» означает, что у задачи нет факта за период</span></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><?php foreach ($fields as $field): ?><th><?= e($catalog[$field] ?? $field) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($report['rows'] as $row): ?><tr><?php foreach ($fields as $field): ?><td><?= e($field === 'hours' ? $formatHours($row[$field] ?? null) : (string) ($row[$field] ?? '')) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        <?php if (($report['rows'] ?? []) === []): ?><tr><td colspan="<?= max(1, count($fields)) ?>"><span class="muted">Нет доступных задач и списаний за выбранный период.</span></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
