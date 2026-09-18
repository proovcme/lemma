<?php
$filters = $filters ?? [];
$analytics = $analytics ?? [];
$timeReport = $timeReport ?? [];
$reportUsers = $reportUsers ?? [];
$timeCategories = $timeCategories ?? [];
$timePhases = $timePhases ?? [];
$metrics = $analytics['metrics'] ?? [];
$selectedFields = array_values((array) $selectedFields);
$formatNumber = static function (mixed $value, int $precision = 0): string {
    $formatted = number_format((float) $value, $precision, '.', ' ');
    return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
};
$metricCards = [
    ['label' => 'Задач всего', 'value' => $formatNumber($metrics['total_tasks'] ?? 0), 'hint' => 'в текущем фильтре'],
    ['label' => 'Открыто', 'value' => $formatNumber($metrics['open_tasks'] ?? 0), 'hint' => 'в работе и на проверке'],
    ['label' => 'Просрочено', 'value' => $formatNumber($metrics['overdue_tasks'] ?? 0), 'hint' => 'по срокам задач'],
    ['label' => '7 дней', 'value' => $formatNumber($metrics['due_week_tasks'] ?? 0), 'hint' => 'сроки на неделе'],
    ['label' => 'Проверки', 'value' => $formatNumber($metrics['review_cycle_tasks'] ?? 0), 'hint' => 'задачи проверки в цикле'],
    ['label' => 'Корректировки', 'value' => $formatNumber($metrics['correction_tasks'] ?? 0), 'hint' => 'возвраты от проверяющих'],
    ['label' => 'Прогресс', 'value' => $formatNumber($metrics['avg_progress'] ?? 0) . ' %', 'hint' => 'средний по задачам'],
    ['label' => 'План/факт', 'value' => $formatNumber($metrics['planned_hours'] ?? 0, 1) . ' / ' . $formatNumber($metrics['actual_hours'] ?? 0, 1), 'hint' => 'часы по листовым задачам'],
    ['label' => 'Деньги', 'value' => $formatNumber($metrics['planned_cost_total'] ?? 0, 2), 'hint' => 'тыс. руб. по плану затрат'],
    ['label' => 'Труд', 'value' => $formatNumber($metrics['planned_labor_hours_total'] ?? 0, 1), 'hint' => 'чел-ч по плану затрат'],
    ['label' => 'Труд к утв.', 'value' => $formatNumber($metrics['labor_pending_director'] ?? 0), 'hint' => 'строк ждут директора'],
    ['label' => 'Вопросы', 'value' => $formatNumber($metrics['open_issues'] ?? 0), 'hint' => 'открытые блокеры'],
    ['label' => 'Задания', 'value' => $formatNumber($metrics['blocked_exchange'] ?? 0), 'hint' => 'заблокированный обмен'],
    ['label' => 'ИД', 'value' => $formatNumber($metrics['waiting_data'] ?? 0), 'hint' => 'ожидаем исходные данные'],
    ['label' => 'График РД', 'value' => $formatNumber($metrics['schedule_overdue'] ?? 0), 'hint' => 'просроченные выдачи'],
];
$barWidth = static function (mixed $value, mixed $total): string {
    $total = max(1.0, (float) $total);
    return max(2, min(100, (int) round(((float) $value / $total) * 100))) . '%';
};
$hoursFromMinutes = static fn (mixed $minutes): string => $formatNumber(((int) $minutes) / 60, 2);
$timeMetrics = $timeReport['metrics'] ?? [];
$timeCanSeeMoney = (bool) ($timeReport['canSeeMoney'] ?? false);
?>

<form class="reports-workbench" method="post" action="<?= url('/reports') ?>">
    <?= csrf_field() ?>
    <section class="reports-hero panel">
        <div class="reports-hero__main">
            <span class="muted">Отчёты</span>
            <h2>Фильтры и выгрузки</h2>
            <p>Выберите период, проект и сотрудника один раз. Все кнопки ниже используют эти же параметры.</p>
        </div>
        <div class="reports-hero__actions">
            <a class="btn btn-outline" href="<?= url('/reports/periodic') ?>">Периодические отчёты</a>
        </div>
    </section>

    <section class="panel reports-filter-panel">
        <div class="reports-filter-grid">
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
                <span>Сотрудник</span>
                <select name="user_id">
                    <option value="">Все сотрудники</option>
                    <?php foreach ($reportUsers as $reportUser): ?>
                        <option value="<?= (int) $reportUser['id'] ?>"<?= selected($filters['user_id'] ?? '', $reportUser['id']) ?>><?= e($reportUser['name'] . (($reportUser['department'] ?? '') ? ' · ' . $reportUser['department'] : '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Категория времени</span>
                <select name="category">
                    <option value="">Все категории</option>
                    <?php foreach ($timeCategories as $category => $label): ?>
                        <option value="<?= e($category) ?>"<?= selected($filters['category'] ?? '', $category) ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Группировка</span>
                <select name="group_by">
                    <option value="assignee"<?= selected($filters['group_by'] ?? '', 'assignee') ?>>По исполнителю</option>
                    <option value="discipline"<?= selected($filters['group_by'] ?? '', 'discipline') ?>>По дисциплине</option>
                    <option value="pp"<?= selected($filters['group_by'] ?? '', 'pp') ?>>По ПП</option>
                    <option value="btp"<?= selected($filters['group_by'] ?? '', 'btp') ?>>По БТП</option>
                    <option value="status"<?= selected($filters['group_by'] ?? '', 'status') ?>>По статусу</option>
                    <option value="project"<?= selected($filters['group_by'] ?? '', 'project') ?>>По проекту</option>
                </select>
            </label>
        </div>
        <div class="reports-filter-actions">
            <button class="btn" type="submit">Обновить страницу</button>
            <button class="btn btn-outline" type="submit" name="report_template" value="project">Шаблон: отчёт по проекту</button>
        </div>
        <details class="reports-fields">
            <summary>Поля отчёта по задачам</summary>
            <div class="checkbox-grid">
                <?php foreach ($fields as $key => $label): ?>
                    <label><input type="checkbox" name="fields[]" value="<?= e($key) ?>"<?= checked(in_array($key, $selectedFields, true)) ?>> <?= e($label) ?></label>
                <?php endforeach; ?>
            </div>
        </details>
    </section>

    <section class="reports-export-grid">
        <article class="reports-export-card reports-export-card--primary">
            <div>
                <span>Месяц</span>
                <strong>Месячный реестр</strong>
                <small>Все доступные люди, проекты и задачи: ПП, БТП, отдел, статус и списанные часы. Задачи без факта не скрываются.</small>
            </div>
            <div class="reports-export-card__actions">
                <a class="btn btn--red" href="<?= url('/reports/monthly') ?>">Открыть реестр</a>
            </div>
        </article>
        <article class="reports-export-card reports-export-card--primary">
            <div>
                <span>Форма 02</span>
                <strong>ДБ-отчёт</strong>
                <small>Построчный свод: табельный, юрлица, ПП, БТП, РП/ГИП, часы и доступные суммы.</small>
            </div>
            <div class="reports-export-card__actions">
                <button class="btn btn--red" formaction="<?= url('/reports/export') ?>" name="report_action" value="db_xlsx">ДБ Excel</button>
                <button class="btn" formaction="<?= url('/reports/export') ?>" name="report_action" value="db_csv">ДБ CSV</button>
            </div>
        </article>
        <article class="reports-export-card">
            <div>
                <span>Табель</span>
                <strong>Трудозатраты</strong>
                <small>Часы за период по сотрудникам, проектам, задачам и категориям времени.</small>
            </div>
            <div class="reports-export-card__actions">
                <button class="btn btn--red" formaction="<?= url('/reports/export') ?>" name="report_action" value="time_xlsx">Табель Excel</button>
                <button class="btn" formaction="<?= url('/reports/export') ?>" name="report_action" value="time_csv">Табель CSV</button>
            </div>
        </article>
        <article class="reports-export-card">
            <div>
                <span>Исполнители</span>
                <strong>Отчёт по исполнителю</strong>
                <small>Команда руководителя, задачи, ПП/БТП, списания времени и подтверждение открытых строк.</small>
            </div>
            <div class="reports-export-card__actions">
                <a class="btn btn--red" href="<?= url('/reports/people') ?>?<?= e(http_build_query(array_filter([
                    'user_id' => $filters['user_id'] ?? '',
                    'project_id' => $filters['project_id'] ?? '',
                    'date_from' => $filters['date_from'] ?? '',
                    'date_to' => $filters['date_to'] ?? '',
                    'category' => $filters['category'] ?? '',
                ], static fn (mixed $value): bool => $value !== '' && $value !== 0))) ?>">Открыть</a>
            </div>
        </article>
        <article class="reports-export-card">
            <div>
                <span>Контур задач</span>
                <strong>Настраиваемый отчёт</strong>
                <small>Список задач по выбранным полям, проекту, сотруднику и периоду.</small>
            </div>
            <div class="reports-export-card__actions">
                <button class="btn btn--red" formaction="<?= url('/reports/export') ?>" name="report_action" value="tasks_xlsx">Задачи Excel</button>
                <button class="btn" formaction="<?= url('/reports/export') ?>" name="report_action" value="tasks_csv">Задачи CSV</button>
            </div>
        </article>
        <article class="reports-export-card">
            <div>
                <span>Шаблон</span>
                <strong>Отчёт по проекту</strong>
                <small>Проект, задачи, разделы, исполнители, проверяющие, сроки, прогресс и план/факт.</small>
            </div>
            <div class="reports-export-card__actions">
                <button class="btn btn--red" formaction="<?= url('/reports/export') ?>" name="report_action" value="project_xlsx">Проект Excel</button>
                <button class="btn" formaction="<?= url('/reports/export') ?>" name="report_action" value="project_csv">Проект CSV</button>
            </div>
        </article>
    </section>

<section class="analytics-module">
    <div class="analytics-head">
        <div>
            <span class="muted">Аналитика</span>
            <h2>Контроль проектного контура</h2>
        </div>
        <span class="pill"><?= (int) ($analytics['visibleProjectCount'] ?? 0) ?> проектов доступно</span>
    </div>

    <div class="analytics-metrics">
        <?php foreach ($metricCards as $card): ?>
            <article class="metric analytics-metric">
                <span><?= e($card['value']) ?></span>
                <strong><?= e($card['label']) ?></strong>
                <small><?= e($card['hint']) ?></small>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="analytics-grid">
        <section class="panel analytics-panel analytics-panel--wide">
            <div class="panel__head">
                <h2>Проекты</h2>
                <span class="muted">сроки, труд, деньги и блокеры</span>
            </div>
            <div class="table-wrap">
                <table class="data-table analytics-table">
                    <thead>
                    <tr>
                        <th>Проект</th><th>Задачи</th><th>Открыто</th><th>Проср.</th><th>Прогресс</th><th>План/факт, ч</th><th>Деньги</th><th>Труд к утв.</th><th>Блокеры</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($analytics['byProject'] ?? []) as $row): ?>
                        <?php $total = (float) ($row['total_tasks'] ?? 0); ?>
                        <tr>
                            <td><strong><?= e($row['code'] ?? '') ?></strong><small><?= e($row['title'] ?? '') ?></small></td>
                            <td><?= e($formatNumber($total)) ?></td>
                            <td><?= e($formatNumber($row['open_tasks'] ?? 0)) ?></td>
                            <td class="<?= (int) ($row['overdue_tasks'] ?? 0) > 0 ? 'cell-danger' : '' ?>"><?= e($formatNumber($row['overdue_tasks'] ?? 0)) ?></td>
                            <td>
                                <div class="analytics-bar"><span style="width: <?= e($barWidth($row['avg_progress'] ?? 0, 100)) ?>"></span></div>
                                <small><?= e($formatNumber($row['avg_progress'] ?? 0)) ?> %</small>
                            </td>
                            <td><?= e($formatNumber($row['planned_hours'] ?? 0, 1)) ?> / <?= e($formatNumber($row['actual_hours'] ?? 0, 1)) ?></td>
                            <td><?= e($formatNumber($row['planned_cost'] ?? 0, 2)) ?></td>
                            <td class="<?= (int) ($row['labor_pending_director'] ?? 0) > 0 ? 'cell-danger' : '' ?>"><?= e($formatNumber($row['labor_pending_director'] ?? 0)) ?></td>
                            <td><?= e($formatNumber((int) ($row['open_issues'] ?? 0) + (int) ($row['waiting_data'] ?? 0) + (int) ($row['blocked_exchange'] ?? 0))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($analytics['byProject'])): ?>
                        <tr><td colspan="9"><span class="muted">Нет данных в выбранном фильтре.</span></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel analytics-panel">
            <div class="panel__head"><h2>Загрузка</h2><span class="muted">по исполнителям</span></div>
            <div class="analytics-list">
                <?php foreach (($analytics['workload'] ?? []) as $row): ?>
                    <article class="analytics-row">
                        <div>
                            <?php if (!empty($row['user_id'])): ?>
                                <strong><a href="<?= url('/reports/people') ?>?user_id=<?= (int) $row['user_id'] ?>&project_id=<?= e($filters['project_id'] ?? '') ?>&date_from=<?= e($filters['date_from'] ?? '') ?>&date_to=<?= e($filters['date_to'] ?? '') ?>"><?= e($row['assignee'] ?? '') ?></a></strong>
                            <?php else: ?>
                                <strong><?= e($row['assignee'] ?? '') ?></strong>
                            <?php endif; ?>
                            <small><?= e(($row['department'] ?? '') ?: 'без отдела') ?></small>
                        </div>
                        <span><?= e($formatNumber($row['open_tasks'] ?? 0)) ?> откр.</span>
                        <span class="<?= (int) ($row['overdue_tasks'] ?? 0) > 0 ? 'cell-danger' : '' ?>"><?= e($formatNumber($row['overdue_tasks'] ?? 0)) ?> проср.</span>
                    </article>
                <?php endforeach; ?>
                <?php if (empty($analytics['workload'])): ?><p class="muted">Нет назначенных задач.</p><?php endif; ?>
            </div>
        </section>

        <section class="panel analytics-panel">
            <div class="panel__head"><h2>Риски</h2><span class="muted">что требует внимания</span></div>
            <div class="analytics-list">
                <?php foreach (($analytics['risks'] ?? []) as $risk): ?>
                    <article class="analytics-risk">
                        <span class="risk-kind"><?= e($risk['kind'] ?? '') ?></span>
                        <strong><?= e($risk['project'] ?? '') ?> · <?= e(mb_strimwidth((string) ($risk['title'] ?? ''), 0, 86, '...')) ?></strong>
                        <small><?= e($risk['owner'] ?? '') ?> · <?= e(format_date($risk['due_date'] ?? '')) ?> · <?= e($formatNumber($risk['days'] ?? 0)) ?> дн.</small>
                    </article>
                <?php endforeach; ?>
                <?php if (empty($analytics['risks'])): ?><p class="muted">Критичных хвостов не найдено.</p><?php endif; ?>
            </div>
        </section>

        <section class="panel analytics-panel">
            <div class="panel__head"><h2>Статусы</h2><span class="muted">распределение задач</span></div>
            <div class="analytics-list">
                <?php foreach (($analytics['byStatus'] ?? []) as $row): ?>
                    <article class="analytics-row analytics-row--bar">
                        <div>
                            <strong><?= e(task_status_label((string) ($row['label'] ?? ''))) ?></strong>
                            <div class="analytics-bar"><span style="width: <?= e($barWidth($row['total_tasks'] ?? 0, $metrics['total_tasks'] ?? 1)) ?>"></span></div>
                        </div>
                        <span><?= e($formatNumber($row['total_tasks'] ?? 0)) ?></span>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel analytics-panel">
            <div class="panel__head"><h2>Дисциплины</h2><span class="muted">объём и просрочка</span></div>
            <div class="analytics-list">
                <?php foreach (($analytics['byDiscipline'] ?? []) as $row): ?>
                    <article class="analytics-row analytics-row--bar">
                        <div>
                            <strong><?= e($row['label'] ?? '') ?></strong>
                            <small><?= e($formatNumber($row['planned_hours'] ?? 0, 1)) ?> план ч</small>
                        </div>
                        <span><?= e($formatNumber($row['total_tasks'] ?? 0)) ?></span>
                        <span class="<?= (int) ($row['overdue_tasks'] ?? 0) > 0 ? 'cell-danger' : '' ?>"><?= e($formatNumber($row['overdue_tasks'] ?? 0)) ?></span>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel analytics-panel">
            <div class="panel__head"><h2>ПП</h2><span class="muted">сделки по задачам</span></div>
            <div class="analytics-list">
                <?php foreach (($analytics['byPp'] ?? []) as $row): ?>
                    <article class="analytics-row analytics-row--bar">
                        <div>
                            <strong><?= e($row['label'] ?? '') ?></strong>
                            <small><?= e($formatNumber($row['planned_hours'] ?? 0, 1)) ?> / <?= e($formatNumber($row['actual_hours'] ?? 0, 1)) ?> ч</small>
                        </div>
                        <span><?= e($formatNumber($row['total_tasks'] ?? 0)) ?></span>
                        <span class="<?= (int) ($row['overdue_tasks'] ?? 0) > 0 ? 'cell-danger' : '' ?>"><?= e($formatNumber($row['overdue_tasks'] ?? 0)) ?></span>
                    </article>
                <?php endforeach; ?>
                <?php if (empty($analytics['byPp'])): ?><p class="muted">ПП в задачах пока не указаны.</p><?php endif; ?>
            </div>
        </section>

        <section class="panel analytics-panel">
            <div class="panel__head"><h2>БТП</h2><span class="muted">строки списания</span></div>
            <div class="analytics-list">
                <?php foreach (($analytics['byBtp'] ?? []) as $row): ?>
                    <article class="analytics-row analytics-row--bar">
                        <div>
                            <strong><?= e($row['label'] ?? '') ?></strong>
                            <small><?= e($formatNumber($row['planned_hours'] ?? 0, 1)) ?> / <?= e($formatNumber($row['actual_hours'] ?? 0, 1)) ?> ч</small>
                        </div>
                        <span><?= e($formatNumber($row['total_tasks'] ?? 0)) ?></span>
                        <span class="<?= (int) ($row['overdue_tasks'] ?? 0) > 0 ? 'cell-danger' : '' ?>"><?= e($formatNumber($row['overdue_tasks'] ?? 0)) ?></span>
                    </article>
                <?php endforeach; ?>
                <?php if (empty($analytics['byBtp'])): ?><p class="muted">БТП в задачах пока не указаны.</p><?php endif; ?>
            </div>
        </section>
    </div>
</section>

<section class="analytics-module">
    <div class="analytics-head">
        <div>
            <span class="muted">Периодический отчёт</span>
            <h2>Трудозатраты за период</h2>
        </div>
    </div>

    <div class="analytics-metrics">
        <article class="metric analytics-metric">
            <span><?= e($hoursFromMinutes($timeMetrics['total_minutes'] ?? 0)) ?></span>
            <strong>Всего, ч</strong>
            <small>по табелю</small>
        </article>
        <article class="metric analytics-metric">
            <span><?= e($hoursFromMinutes($timeMetrics['task_minutes'] ?? 0)) ?></span>
            <strong>Проектные, ч</strong>
            <small>строки задач</small>
        </article>
        <article class="metric analytics-metric">
            <span><?= e($hoursFromMinutes($timeMetrics['non_task_minutes'] ?? 0)) ?></span>
            <strong>Непроектные, ч</strong>
            <small>совещания, простой, другое</small>
        </article>
        <article class="metric analytics-metric">
            <span><?= e($hoursFromMinutes($timeMetrics['overtime_minutes'] ?? 0)) ?></span>
            <strong>Переработка, ч</strong>
            <small>категория табеля</small>
        </article>
        <article class="metric analytics-metric">
            <span><?= e($formatNumber($timeMetrics['user_count'] ?? 0)) ?></span>
            <strong>Сотрудников</strong>
            <small>в выбранном периоде</small>
        </article>
        <?php if ($timeCanSeeMoney): ?>
            <article class="metric analytics-metric">
                <span><?= e($formatNumber($timeMetrics['cost_thousand'] ?? 0, 2)) ?></span>
                <strong>Сумма, тыс. руб.</strong>
                <small>по ставкам сотрудников</small>
            </article>
        <?php endif; ?>
    </div>

    <div class="analytics-grid">
        <section class="panel analytics-panel">
            <div class="panel__head"><h2>По сотрудникам</h2><span class="muted">табельные часы</span></div>
            <div class="analytics-list">
                <?php foreach (($timeReport['byUser'] ?? []) as $row): ?>
                    <article class="analytics-row">
                        <div>
                            <strong><?= e($row['label'] ?? '') ?></strong>
                            <small><?= e(($row['meta'] ?? '') ?: 'без отдела') ?></small>
                        </div>
                        <span><?= e($hoursFromMinutes($row['minutes'] ?? 0)) ?> ч</span>
                        <span><?= e($hoursFromMinutes($row['overtime_minutes'] ?? 0)) ?> перераб.</span>
                        <?php if ($timeCanSeeMoney): ?><span><?= e($formatNumber($row['cost_thousand'] ?? 0, 2)) ?> тыс.</span><?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <?php if (empty($timeReport['byUser'])): ?><p class="muted">Нет списанного времени в выбранном периоде.</p><?php endif; ?>
            </div>
        </section>

        <section class="panel analytics-panel">
            <div class="panel__head"><h2>По проектам</h2><span class="muted">проектные и общие строки</span></div>
            <div class="analytics-list">
                <?php foreach (($timeReport['byProject'] ?? []) as $row): ?>
                    <article class="analytics-row">
                        <div>
                            <strong><?= e($row['label'] ?? '') ?></strong>
                            <small><?= e($row['meta'] ?? '') ?></small>
                        </div>
                        <span><?= e($hoursFromMinutes($row['minutes'] ?? 0)) ?> ч</span>
                        <span><?= e($formatNumber($row['users_count'] ?? 0)) ?> чел.</span>
                    </article>
                <?php endforeach; ?>
                <?php if (empty($timeReport['byProject'])): ?><p class="muted">Нет данных по проектам.</p><?php endif; ?>
            </div>
        </section>

        <section class="panel analytics-panel analytics-panel--wide">
            <div class="panel__head"><h2>По задачам</h2><span class="muted">топ строк по трудозатратам</span></div>
            <div class="table-wrap">
                <table class="data-table analytics-table">
                    <thead><tr><th>Задача</th><th>Проект</th><th>Часы</th><th>Перераб.</th><th>Сотрудники</th><?php if ($timeCanSeeMoney): ?><th>Сумма</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach (($timeReport['byTask'] ?? []) as $row): ?>
                        <tr>
                            <td><?= e($row['label'] ?? '') ?></td>
                            <td><?= e($row['meta'] ?? '') ?></td>
                            <td><?= e($hoursFromMinutes($row['minutes'] ?? 0)) ?></td>
                            <td><?= e($hoursFromMinutes($row['overtime_minutes'] ?? 0)) ?></td>
                            <td><?= e($formatNumber($row['users_count'] ?? 0)) ?></td>
                            <?php if ($timeCanSeeMoney): ?><td><?= e($formatNumber($row['cost_thousand'] ?? 0, 2)) ?></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($timeReport['byTask'])): ?><tr><td colspan="<?= $timeCanSeeMoney ? 6 : 5 ?>"><span class="muted">Нет данных по задачам.</span></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</section>
</form>

<?php if ($rows): ?>
    <section class="panel">
        <div class="panel__head"><h2>Предпросмотр</h2><span><?= count($rows) ?></span></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><?php foreach ($selectedFields as $field): ?><th><?= e($fields[$field] ?? $field) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr><?php foreach ($selectedFields as $field): ?><td><?= e($row[$field] ?? '') ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
