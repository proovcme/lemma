<?php
$dashboard = $dashboard ?? null;
$period = $dashboard['period'] ?? null;
$money = static fn (float|int|string|null $value): string => number_format((float) $value, 2, ',', ' ');
$statusLabels = [
    'occupied' => 'Сотрудник назначен',
    'vacancy' => 'Вакансия',
    'hiring' => 'Идёт подбор',
    'transfer' => 'Планируется перевод',
    'reduction' => 'К сокращению',
];
$periodStatusLabels = ['draft' => 'Черновик', 'locked' => 'Зафиксировано', 'superseded' => 'Заменено'];
$changeLabels = ['none' => 'Без изменения', 'hire' => 'Приём', 'transfer' => 'Перевод', 'reduction' => 'Сокращение', 'other' => 'Другое'];
?>

<?php require BASE_PATH . '/app/Views/director/_tabs.php'; ?>

<section class="staffing-intro" aria-label="О штатном расписании">
    <p><strong>Директорский контур.</strong> ФОТ задаётся за месяц; дневная и часовая ставки, начисления и полный бюджет рассчитываются автоматически.</p>
    <div class="staffing-intro__actions">
        <?php if ($period): ?>
            <a class="btn btn-outline" href="<?= url('/director/staffing/periods/' . (int) $period['id'] . '/print') ?>" target="_blank" rel="noopener">Печать</a>
            <a class="btn btn-outline" href="<?= url('/director/staffing/periods/' . (int) $period['id'] . '/export') ?>">Excel</a>
        <?php endif; ?>
    </div>
</section>

<section class="panel staffing-period-bar">
    <div class="panel__head">
        <div>
            <h2>Учётный период</h2>
            <p class="muted">Зафиксированная версия не редактируется. Исправления выпускаются новой ревизией.</p>
        </div>
        <?php if ($period): ?>
            <span class="status-pill status-pill--<?= e($period['status']) ?>"><?= e($periodStatusLabels[$period['status']] ?? $period['status']) ?></span>
        <?php endif; ?>
    </div>
    <div class="filterbar staffing-period-filter">
        <form method="get" action="<?= url('/director/staffing') ?>">
            <label>
                <span>Версия</span>
                <select name="period" onchange="this.form.submit()">
                    <?php foreach ($periods as $item): ?>
                        <option value="<?= (int) $item['id'] ?>"<?= selected((string) ($period['id'] ?? ''), (string) $item['id']) ?>>
                            <?= e(date('m.Y', strtotime($item['month_start']))) ?> · версия <?= (int) $item['revision'] ?> · <?= e($periodStatusLabels[$item['status']] ?? $item['status']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
        <details class="staffing-create-period">
            <summary class="btn btn-outline">Новый месяц</summary>
            <form class="form-grid" method="post" action="<?= url('/director/staffing/periods') ?>">
                <?= csrf_field() ?>
                <label><span>Месяц</span><input type="month" name="month" value="<?= e($defaultMonth ?? date('Y-m')) ?>" required></label>
                <label><span>Рабочих дней</span><input type="number" min="1" max="31" step="0.01" name="working_days" value="21" required></label>
                <label><span>Рабочих часов</span><input type="number" min="1" max="744" step="0.01" name="working_hours" value="168" required></label>
                <label><span>Нагрузка на ФОТ, %</span><input type="number" min="0" max="500" step="0.01" name="payroll_burden_pct" value="0"></label>
                <label><span>Накладные, %</span><input type="number" min="0" max="500" step="0.01" name="overhead_pct" value="0"></label>
                <label><span>Копировать</span><select name="copy_from"><option value="">Без строк</option><?php foreach ($periods as $item): ?><option value="<?= (int) $item['id'] ?>"><?= e(date('m.Y', strtotime($item['month_start']))) ?> · v<?= (int) $item['revision'] ?></option><?php endforeach; ?></select></label>
                <div class="form-grid__full"><button class="btn btn-red" type="submit">Создать черновик</button></div>
            </form>
        </details>
    </div>
</section>

<?php if (!$dashboard): ?>
    <section class="panel empty-state">
        <strong>Штатное расписание ещё не создано</strong>
        <p>Создайте первый месяц. Система не импортирует ФИО и ФОТ из Excel автоматически.</p>
    </section>
<?php else: ?>
    <section class="metric-row project-summary-metrics staffing-metrics" aria-label="Сводка бюджета">
        <div class="metric"><span><?= $money($dashboard['total_fot']) ?></span><strong>Прямой ФОТ, ₽/мес</strong></div>
        <div class="metric"><span><?= $money($dashboard['full_budget']) ?></span><strong>Полный бюджет, ₽/мес</strong></div>
        <div class="metric"><span><?= $money($dashboard['total_fte']) ?></span><strong>Штатных единиц</strong></div>
        <div class="metric"><span><?= (int) $dashboard['vacancies'] ?></span><strong>Вакансии и подбор</strong></div>
        <div class="metric"><span><?= $dashboard['delta'] === null ? '—' : (($dashboard['delta'] >= 0 ? '+' : '') . $money($dashboard['delta'])) ?></span><strong>К прошлому месяцу, ₽</strong></div>
    </section>

    <details class="panel staffing-settings"<?= $period['status'] === 'draft' ? ' open' : '' ?>>
        <summary class="staffing-section-summary">
            <span class="staffing-section-summary__copy"><strong>Расчётные параметры</strong><small><?= $money($period['working_days']) ?> рабочих дней · <?= $money($period['working_hours']) ?> часов · нагрузка <?= $money($period['payroll_burden_pct']) ?>% · накладные <?= $money($period['overhead_pct']) ?>%</small></span>
            <span class="staffing-section-summary__action"><?= $period['status'] === 'draft' ? 'Изменить' : 'Посмотреть' ?></span>
        </summary>
        <form class="form-grid" method="post" action="<?= url('/director/staffing/periods/' . (int) $period['id']) ?>">
            <?= csrf_field() ?>
            <label><span>Рабочих дней</span><input type="number" min="1" max="31" step="0.01" name="working_days" value="<?= e((string) $period['working_days']) ?>"<?= $period['status'] !== 'draft' ? ' readonly' : '' ?> required></label>
            <label><span>Рабочих часов</span><input type="number" min="1" max="744" step="0.01" name="working_hours" value="<?= e((string) $period['working_hours']) ?>"<?= $period['status'] !== 'draft' ? ' readonly' : '' ?> required></label>
            <label><span>Нагрузка на ФОТ, %</span><input type="number" min="0" max="500" step="0.01" name="payroll_burden_pct" value="<?= e((string) $period['payroll_burden_pct']) ?>"<?= $period['status'] !== 'draft' ? ' readonly' : '' ?>></label>
            <label><span>Накладные, %</span><input type="number" min="0" max="500" step="0.01" name="overhead_pct" value="<?= e((string) $period['overhead_pct']) ?>"<?= $period['status'] !== 'draft' ? ' readonly' : '' ?>></label>
            <label class="form-grid__full"><span>Комментарий к версии</span><textarea name="note" rows="2"<?= $period['status'] !== 'draft' ? ' readonly' : '' ?>><?= e($period['note'] ?? '') ?></textarea></label>
            <?php if ($period['status'] === 'draft'): ?><div class="form-grid__full"><button class="btn btn-red" type="submit">Сохранить параметры</button></div><?php endif; ?>
        </form>
    </details>

    <section class="panel">
        <div class="panel__head"><div><h2>Стоимостные группы</h2><p class="muted">Группа равна коду отдела. Ставка = ФОТ группы / (норма часов × количество ставок).</p></div></div>
        <div class="table-wrap" tabindex="0" aria-label="Стоимостные группы">
            <table class="data-table data-table--compact staffing-table" data-table>
                <thead><tr><th>Раздел</th><th>Позиций</th><th>Ставок</th><th>ФОТ, ₽/мес</th><th>Средняя, ₽/мес</th><th>₽/день</th><th>₽/час</th></tr></thead>
                <tbody><?php foreach ($dashboard['groups'] as $group): ?><tr>
                    <td data-label="Раздел"><strong><?= e($group['department_code']) ?></strong><small><?= e($group['department_name']) ?></small></td>
                    <td data-label="Позиций"><?= (int) $group['positions_count'] ?></td>
                    <td data-label="Ставок"><?= $money($group['total_fte']) ?></td>
                    <td data-label="ФОТ"><?= $money($group['total_fot']) ?></td>
                    <td data-label="Средняя"><?= $money($group['avg_monthly']) ?></td>
                    <td data-label="В день"><?= $money($group['avg_daily']) ?></td>
                    <td data-label="В час"><?= $money($group['avg_hourly']) ?></td>
                </tr><?php endforeach; ?></tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head"><div><h2>Штатные позиции</h2><p class="muted">Сокращения остаются в истории, но не входят в бюджет.</p></div><span><?= count($dashboard['rows']) ?></span></div>
        <div class="table-wrap" tabindex="0" aria-label="Штатные позиции">
            <table class="data-table data-table--compact staffing-table" data-table>
                <thead><tr><th>Раздел</th><th>Должность / сотрудник</th><th>Ставок</th><th>ФОТ, ₽/мес</th><th>₽/день</th><th>₽/час</th><th>Изменение</th></tr></thead>
                <tbody>
                <?php foreach ($dashboard['rows'] as $row): $fte = max(0.01, (float) $row['fte']); ?>
                    <tr class="<?= $row['status'] === 'reduction' ? 'is-muted' : '' ?>">
                        <td data-label="Раздел"><strong><?= e($row['department_code']) ?></strong><small><?= e($row['group_name'] ?: $row['department_name']) ?></small></td>
                        <td data-label="Позиция"><strong><?= e($row['position_title']) ?></strong><small><?= e($row['employee_name']) ?><?= $row['tab_number'] ? ' · ' . e($row['tab_number']) : '' ?></small><?php if ($row['status'] !== 'occupied'): ?><span class="staffing-position-state staffing-position-state--<?= e($row['status']) ?>"><?= e($statusLabels[$row['status']] ?? $row['status']) ?></span><?php endif; ?></td>
                        <td data-label="Ставок"><?= $money($row['fte']) ?></td>
                        <td data-label="ФОТ"><?= $money($row['monthly_fot']) ?></td>
                        <td data-label="В день"><?= $money((float) $row['monthly_fot'] / ((float) $period['working_days'] * $fte)) ?></td>
                        <td data-label="В час"><?= $money((float) $row['monthly_fot'] / ((float) $period['working_hours'] * $fte)) ?></td>
                        <td data-label="Изменение"><?= e($changeLabels[$row['change_type']] ?? $row['change_type']) ?></td>
                    </tr>
                    <?php if ($period['status'] === 'draft'): ?><tr class="staffing-edit-row"><td colspan="7"><details><summary>Изменить позицию</summary><?php $staffingRow = $row; require BASE_PATH . '/app/Views/team/_staffing_row_form.php'; ?></details></td></tr><?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($period['status'] === 'draft'): ?>
            <details class="staffing-add-row"><summary class="btn btn-outline">Добавить позицию</summary><?php $staffingRow = null; require BASE_PATH . '/app/Views/team/_staffing_row_form.php'; ?></details>
        <?php endif; ?>
    </section>

    <section class="panel staffing-finalize">
        <div><h2><?= $period['status'] === 'draft' ? 'Зафиксировать версию' : 'Версия закрыта от изменений' ?></h2><p><?= $period['status'] === 'draft' ? 'После фиксации ставки попадут в проектные расчёты. Исправить данные можно будет только новой ревизией.' : 'История сохранена. Для исправления создайте корректировку.' ?></p></div>
        <?php if ($period['status'] === 'draft'): ?>
            <form method="post" action="<?= url('/director/staffing/periods/' . (int) $period['id'] . '/lock') ?>" onsubmit="return confirm('Зафиксировать штатное расписание? После этого редактирование будет недоступно.')"><?= csrf_field() ?><button class="btn btn-red" type="submit">Зафиксировать</button></form>
        <?php else: ?>
            <form method="post" action="<?= url('/director/staffing/periods/' . (int) $period['id'] . '/correct') ?>"><?= csrf_field() ?><button class="btn btn-outline" type="submit">Создать корректировку</button></form>
        <?php endif; ?>
    </section>
<?php endif; ?>
