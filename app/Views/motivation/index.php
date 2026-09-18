<?php
$formatMoney = static fn (mixed $value): string => number_format((float) $value, 0, '.', ' ');
$formatHours = static function (mixed $value): string {
    $formatted = number_format((float) $value, 2, '.', ' ');
    return rtrim(rtrim($formatted, '0'), '.');
};
$data = $lockedRun ?: $preview;
$rows = $lockedRun ? ($lockedRun['rows'] ?? []) : ($preview['rows'] ?? []);
$totals = $lockedRun ? ($lockedRun['totals'] ?? []) : ($preview['totals'] ?? []);
$isLocked = $lockedRun !== null;
$control = $control ?? [];
$controlTotals = $control['totals'] ?? [];
$controlRows = $control['rows'] ?? [];
$controlDepartments = $control['departments'] ?? [];
$controlProjects = $control['projects'] ?? [];
$bottlenecks = $control['bottlenecks'] ?? [];
$formatPercent = static function (mixed $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    return number_format(((float) $value) * 100, 0, '.', ' ') . '%';
};
?>

<section class="project-head project-head--tab">
    <div>
        <span class="muted">ФОТ / управленческая витрина</span>
        <h2>Мотивация за <?= e(format_date($month)) ?></h2>
    </div>
    <div class="toolbar__actions project-tab-actions">
        <a class="btn btn-outline" href="<?= url('/motivation?month=' . $prevMonth) ?>">← Месяц</a>
        <a class="btn btn-outline" href="<?= url('/motivation?month=' . $nextMonth) ?>">Месяц →</a>
        <a class="btn" href="<?= url('/motivation/projects') ?>">Фонды</a>
        <a class="btn" href="<?= url('/motivation/settings') ?>">Настройки</a>
    </div>
</section>

<section class="metric-row project-summary-metrics">
    <div class="metric"><span><?= e($formatMoney($totals['kpi_amount'] ?? 0)) ?></span><strong>KPI, ₽</strong></div>
    <div class="metric"><span><?= e($formatMoney($totals['project_bonus_amount'] ?? 0)) ?></span><strong>Проектная часть, ₽</strong></div>
    <div class="metric"><span><?= e($formatMoney($totals['total_amount'] ?? 0)) ?></span><strong>Итого, ₽</strong></div>
    <div class="metric"><span><?= e($formatHours($totals['locked_hours'] ?? 0)) ?></span><strong>Закрытые часы</strong></div>
</section>

<?php if ($control): ?>
    <section class="panel motivation-control">
        <div class="panel__head">
            <div>
                <h2>Контроль месяца</h2>
                <span class="muted">Факт на <?= e(format_date($control['effective_date'] ?? $month)) ?>: узкие места, деньги и трудочасы без записи в начисления</span>
            </div>
        </div>
        <div class="metric-row project-summary-metrics">
            <div class="metric"><span><?= e((string) (int) ($controlTotals['behind_people'] ?? 0)) ?></span><strong>Отстают</strong></div>
            <div class="metric"><span><?= e((string) (int) ($controlTotals['risk_people'] ?? 0)) ?></span><strong>В риске</strong></div>
            <div class="metric"><span><?= e($formatHours($controlTotals['entered_hours'] ?? 0)) ?></span><strong>Факт, ч</strong></div>
            <div class="metric"><span><?= e($formatHours($controlTotals['expected_hours_to_date'] ?? 0)) ?></span><strong>План на дату, ч</strong></div>
            <div class="metric"><span><?= e($formatMoney($controlTotals['actual_cost'] ?? 0)) ?></span><strong>Факт затрат, ₽</strong></div>
            <div class="metric"><span><?= e((string) (int) ($controlTotals['bottlenecks'] ?? 0)) ?></span><strong>Узкие места</strong></div>
        </div>
        <div class="motivation-bottlenecks">
            <?php foreach ($bottlenecks as $item): ?>
                <article class="motivation-bottleneck motivation-bottleneck--<?= e($item['severity'] ?? 'info') ?>">
                    <span><?= e($item['type'] ?? '') ?></span>
                    <strong><?= e($item['title'] ?? '') ?></strong>
                    <small><?= e(($item['metric'] ?? '') . ' · ' . ($item['reason'] ?? '')) ?></small>
                </article>
            <?php endforeach; ?>
            <?php if (!$bottlenecks): ?>
                <p class="muted">Критичных отклонений на текущую дату не видно.</p>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head">
            <div>
                <h2>Сотрудники: отстают или обгоняют</h2>
                <span class="muted">Сравнение фактических часов, закрытых задач, просрочки и план/факта</span>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table data-table--compact motivation-control-table">
                <thead>
                <tr>
                    <th>Сотрудник</th>
                    <th>Статус</th>
                    <th>Отдел</th>
                    <th>Часы</th>
                    <th>Задачи</th>
                    <th>План/факт</th>
                    <th>Качество</th>
                    <th>Затраты, ₽</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($controlRows as $row): ?>
                    <tr>
                        <td>
                            <strong><?= e($row['name'] ?? '') ?></strong>
                            <small><?= e($row['grade'] ?: 'грейд не указан') ?> · ставка <?= e($formatMoney($row['hourly_rate'] ?? 0)) ?></small>
                        </td>
                        <td><span class="motivation-status motivation-status--<?= e($row['status'] ?? 'on_track') ?>"><?= e($row['status_label'] ?? '') ?></span></td>
                        <td><?= e($row['department'] ?? '') ?></td>
                        <td>
                            <?= e($formatHours($row['entered_hours'] ?? 0)) ?> / <?= e($formatHours($row['expected_hours_to_date'] ?? 0)) ?>
                            <small><?= e($formatPercent($row['hours_progress'] ?? null)) ?> плана на дату</small>
                        </td>
                        <td>
                            <?= e($formatHours($row['weighted_closed'] ?? 0)) ?> / <?= e($formatHours($row['expected_weight_to_date'] ?? 0)) ?>
                            <small><?= e((string) (int) ($row['open_tasks'] ?? 0)) ?> открыто, <?= e((string) (int) ($row['overdue_tasks'] ?? 0)) ?> проср.</small>
                        </td>
                        <td>
                            <?= e($formatHours($row['task_hours'] ?? 0)) ?> / <?= e($formatHours($row['planned_hours'] ?? 0)) ?> ч
                            <small><?= e($formatPercent($row['plan_fact_ratio'] ?? null)) ?></small>
                        </td>
                        <td><?= e($formatPercent($row['quality_ratio'] ?? null)) ?></td>
                        <td>
                            <?= e($formatMoney($row['actual_cost'] ?? 0)) ?>
                            <small>дельта <?= e($formatMoney($row['cost_delta'] ?? 0)) ?></small>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$controlRows): ?>
                    <tr><td colspan="8" class="muted">Нет данных для контроля месяца.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head">
            <div>
                <h2>Отделы и проекты</h2>
                <span class="muted">Агрегация по трудочасам, просрочке, план/факту и бюджету</span>
            </div>
        </div>
        <div class="motivation-control-grids">
            <div class="table-wrap">
                <table class="data-table data-table--compact">
                    <thead>
                    <tr>
                        <th>Отдел</th>
                        <th>Люди</th>
                        <th>Часы</th>
                        <th>План/факт</th>
                        <th>Затраты, ₽</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($controlDepartments as $department): ?>
                        <tr>
                            <td><strong><?= e($department['department'] ?? '') ?></strong></td>
                            <td><?= e((string) (int) ($department['people'] ?? 0)) ?> <small>риск <?= e((string) ((int) ($department['behind_people'] ?? 0) + (int) ($department['risk_people'] ?? 0))) ?></small></td>
                            <td><?= e($formatHours($department['entered_hours'] ?? 0)) ?> / <?= e($formatHours($department['expected_hours_to_date'] ?? 0)) ?></td>
                            <td><?= e($formatPercent($department['plan_fact_ratio'] ?? null)) ?></td>
                            <td><?= e($formatMoney($department['actual_cost'] ?? 0)) ?> <small>дельта <?= e($formatMoney($department['cost_delta'] ?? 0)) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="table-wrap">
                <table class="data-table data-table--compact">
                    <thead>
                    <tr>
                        <th>Проект</th>
                        <th>Задачи</th>
                        <th>Часы</th>
                        <th>План/факт</th>
                        <th>Бюджет</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($controlProjects as $project): ?>
                        <tr>
                            <td><strong><?= e(trim(($project['code'] ?? '') . ' ' . ($project['title'] ?? ''))) ?></strong></td>
                            <td><?= e((string) (int) ($project['open_tasks'] ?? 0)) ?> открыто <small><?= e((string) (int) ($project['overdue_tasks'] ?? 0)) ?> проср.</small></td>
                            <td><?= e($formatHours($project['actual_hours'] ?? 0)) ?> / <?= e($formatHours($project['planned_hours'] ?? 0)) ?></td>
                            <td><?= e($formatPercent($project['plan_fact_ratio'] ?? null)) ?></td>
                            <td>
                                <?= e($formatMoney($project['actual_cost'] ?? 0)) ?>
                                <small><?= e($formatPercent($project['budget_burn'] ?? null)) ?> бюджета</small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$controlProjects): ?>
                        <tr><td colspan="5" class="muted">Нет проектных часов за период.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="panel__head">
        <div>
            <h2><?= $isLocked ? 'Зафиксированный расчёт' : 'Черновик расчёта' ?></h2>
            <span class="muted"><?= $isLocked ? 'Snapshot не меняется от последующих правок табеля и настроек' : 'Черновик пересчитывается из текущих табелей, задач и настроек' ?></span>
        </div>
        <?php if (!$isLocked): ?>
            <form method="post" action="<?= url('/motivation/lock') ?>" onsubmit="return confirm('Зафиксировать расчёт мотивации за месяц?')">
                <?= csrf_field() ?>
                <input type="hidden" name="month" value="<?= e($month) ?>">
                <button class="btn btn--red" type="submit">Зафиксировать</button>
            </form>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Сотрудник</th>
                <th>Отдел</th>
                <th>Грейд</th>
                <th>Часы</th>
                <th>KPI</th>
                <th>KPI, ₽</th>
                <th>Проект, ₽</th>
                <th>Итого, ₽</th>
                <th>Обоснование</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $basis = $row['basis'] ?? []; $scores = $basis['scores'] ?? []; ?>
                <tr>
                    <td><strong><?= e($row['name'] ?? '') ?></strong></td>
                    <td><?= e($row['department'] ?? '') ?></td>
                    <td>
                        <?= e($row['grade'] ?: '—') ?>
                        <small>×<?= e($formatHours($row['grade_coefficient'] ?? 0)) ?></small>
                    </td>
                    <td>
                        <?= e($formatHours($row['locked_hours'] ?? 0)) ?>
                        <small>план <?= e($formatHours($row['expected_hours'] ?? 0)) ?></small>
                    </td>
                    <td><?= e(number_format(((float) ($row['kpi_score'] ?? 0)) * 100, 0, '.', ' ')) ?>%</td>
                    <td><?= e($formatMoney($row['kpi_amount'] ?? 0)) ?></td>
                    <td><?= e($formatMoney($row['project_bonus_amount'] ?? 0)) ?></td>
                    <td><strong><?= e($formatMoney($row['total_amount'] ?? 0)) ?></strong></td>
                    <td>
                        <small>
                            табель <?= e(number_format(((float) ($scores['timesheet_locked'] ?? 0)) * 100, 0)) ?>%,
                            списания <?= e(number_format(((float) ($scores['timesheet_completeness'] ?? 0)) * 100, 0)) ?>%,
                            сроки <?= e(number_format(((float) ($scores['deadline'] ?? 0)) * 100, 0)) ?>%,
                            возвраты <?= e(number_format(((float) ($scores['rework'] ?? 0)) * 100, 0)) ?>%,
                            план/факт <?= e(number_format(((float) ($scores['plan_fact'] ?? 0)) * 100, 0)) ?>%
                        </small>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="9" class="muted">Нет активных сотрудников для расчёта.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
