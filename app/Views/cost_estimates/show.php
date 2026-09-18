<?php
$formatDecimal = static function (mixed $value, int $precision = 2): string {
    $formatted = number_format((float) $value, $precision, '.', ' ');
    return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
};
$formatMoney = static fn (mixed $value): string => number_format((float) $value, 2, '.', ' ');
$objectTypes = $objectTypes ?? [];
$sbcItems = $sbcItems ?? [];
$sbcIndices = $sbcIndices ?? [];
$departments = $departments ?? [];
$users = $users ?? [];
$filters = $filters ?? [];
$laborAllocations = $laborAllocations ?? [];
$laborResponsibleTotals = $laborResponsibleTotals ?? [];
$laborAssigneeTotals = $laborAssigneeTotals ?? [];
$planningSummary = $planningSummary ?? $totals ?? [];
$managerTaskStats = $managerTaskStats ?? ['overall' => [], 'by_discipline' => [], 'by_type' => []];
$sectionPlanningRows = $sectionPlanningRows ?? [];
$laborStatuses = ['draft', 'department_submitted', 'returned_to_department', 'gip_adjusted', 'director_approved'];
$currentUser = current_user() ?: [];
$currentUserId = (int) ($currentUser['id'] ?? 0);
$daysFromHours = static fn (mixed $hours): string => $formatDecimal(((float) $hours) / 8, 2);
$formatDateRu = static function (mixed $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $date ? $date->format('d.m.Y') : $value;
};
$formatPercent = static fn (mixed $value): string => $value === null ? '—' : number_format((float) $value, 1, '.', ' ') . ' %';
$taskStatsRows = static function (array $rows) use ($formatDecimal, $formatPercent): void {
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td><strong>' . e($row['label'] ?? '—') . '</strong><small>' . e((int) ($row['total'] ?? 0)) . ' закрытых задач</small></td>';
        echo '<td>' . e($formatDecimal($row['avg_planned_hours'] ?? 0, 2)) . ' ч</td>';
        echo '<td>' . e($formatDecimal($row['avg_actual_hours'] ?? 0, 2)) . ' ч</td>';
        echo '<td>' . e($formatDecimal($row['avg_cycle_days'] ?? 0, 1)) . ' дн.</td>';
        echo '<td>' . e($formatPercent($row['over_plan_percent'] ?? 0)) . '</td>';
        echo '</tr>';
    }
};
$sbcSelect = static function (string $formId, mixed $selected) use ($sbcItems): void {
    $form = $formId !== '' ? ' form="' . e($formId) . '"' : '';
    echo '<select' . $form . ' name="sbc_item_id"><option value=""></option>';
    foreach ($sbcItems as $option) {
        $isSelected = (string) $selected !== '' && (string) $option['value'] === (string) $selected;
        echo '<option value="' . e($option['value']) . '"' . ($isSelected ? ' selected' : '') . '>' . e($option['label']) . '</option>';
    }
    echo '</select>';
};
$indexSelect = static function (string $formId, mixed $selected) use ($sbcIndices): void {
    $form = $formId !== '' ? ' form="' . e($formId) . '"' : '';
    echo '<select' . $form . ' name="sbc_index_id"><option value=""></option>';
    foreach ($sbcIndices as $index) {
        $isSelected = (string) $selected !== '' && (int) $selected === (int) $index['id'];
        echo '<option value="' . (int) $index['id'] . '"' . ($isSelected ? ' selected' : '') . '>' . e(($index['label'] ?: $index['period_key']) . ' · ' . number_format((float) $index['index_value'], 4, '.', ' ')) . '</option>';
    }
    echo '</select>';
};
$userSelect = static function (string $name, mixed $selected, string $formId = '') use ($users): void {
    $form = $formId !== '' ? ' form="' . e($formId) . '"' : '';
    echo '<select' . $form . ' name="' . e($name) . '"><option value=""></option>';
    foreach ($users as $user) {
        $isSelected = (string) $selected !== '' && (int) $selected === (int) $user['id'];
        echo '<option value="' . (int) $user['id'] . '"' . ($isSelected ? ' selected' : '') . '>' . e($user['name'] . ' · ' . role_label($user['role'] ?? '')) . '</option>';
    }
    echo '</select>';
};
?>

<section class="project-head project-head--tab">
    <div>
        <span class="muted">Предпроект · <?= e($preproject['stage'] ?: 'стадия не задана') ?></span>
        <h2><?= e($preproject['code']) ?> · <?= e($preproject['title']) ?></h2>
    </div>
    <div class="toolbar__actions project-tab-actions">
        <a class="btn" href="<?= url('/cost-estimates') ?>">К оценке</a>
        <?php if ($canManageRates): ?><a class="btn btn-outline" href="<?= url('/cost-estimates/rates') ?>">Ставки</a><?php endif; ?>
    </div>
</section>

<section class="metric-row project-summary-metrics cost-summary-metrics">
    <div class="metric"><span><?= (int) ($planningSummary['labor_rows'] ?? 0) ?></span><strong>Строки</strong></div>
    <div class="metric"><span><?= e($formatDecimal($planningSummary['hours'] ?? 0, 2)) ?></span><strong>План, ч</strong></div>
    <div class="metric"><span><?= e($formatDecimal($planningSummary['days'] ?? 0, 2)) ?></span><strong>План, дн.</strong></div>
    <div class="metric"><span><?= e(($planningSummary['calendar_days'] ?? null) !== null ? (string) $planningSummary['calendar_days'] : '—') ?></span><strong>Срок, дней</strong></div>
    <div class="metric"><span><?= count($laborAssigneeTotals) ?></span><strong>Исполнители</strong></div>
    <?php if ($canSeeMoney): ?>
        <div class="metric"><span><?= e($formatMoney($planningSummary['money'] ?? 0)) ?></span><strong>Расчёт, тыс. руб.</strong></div>
        <div class="metric"><span><?= e($formatMoney($planningSummary['sbc'] ?? 0)) ?></span><strong>СБЦ справочно</strong></div>
        <div class="metric"><span><?= e($formatMoney($planningSummary['delta'] ?? 0)) ?></span><strong>Отклонение</strong></div>
    <?php endif; ?>
</section>

<section class="panel cost-planning-summary">
    <div class="panel__head">
        <h2>Планирование и сверка с СБЦ</h2>
        <span><?= e((int) ($planningSummary['approved_rows'] ?? 0)) ?> утверждено · <?= e((int) ($planningSummary['pending_rows'] ?? 0)) ?> в работе</span>
    </div>
    <dl class="stat-list">
        <div><dt>Период</dt><dd><?= e($formatDateRu($planningSummary['date_start'] ?? null)) ?> — <?= e($formatDateRu($planningSummary['date_end'] ?? null)) ?><small><?= e($planningSummary['date_source'] ?? 'не задан') ?></small></dd></div>
        <div><dt>Трудоёмкость</dt><dd><?= e($formatDecimal($planningSummary['hours'] ?? 0, 2)) ?> ч<small><?= e($formatDecimal($planningSummary['days'] ?? 0, 2)) ?> чел.-дн. по 8 часов</small></dd></div>
        <div><dt>Покрытие СБЦ</dt><dd><?= e($formatPercent($planningSummary['sbc_coverage_percent'] ?? null)) ?><small><?= e((int) ($planningSummary['sbc_missing_rows'] ?? 0)) ?> строк без СБЦ</small></dd></div>
        <?php if ($canSeeMoney): ?>
            <div><dt>Сверка</dt><dd><?= e($formatMoney($planningSummary['money'] ?? 0)) ?> / <?= e($formatMoney($planningSummary['sbc'] ?? 0)) ?><small>отклонение <?= e($formatMoney($planningSummary['delta'] ?? 0)) ?> тыс. руб. · <?= e($formatPercent($planningSummary['delta_percent'] ?? null)) ?></small></dd></div>
        <?php endif; ?>
    </dl>
</section>

<?php if ((int) ($managerTaskStats['overall']['total'] ?? 0) > 0): ?>
<details class="panel task-fold cost-task-stats" open>
    <summary class="task-fold__summary"><span>Статистика задач для руководителей</span><strong><?= e((int) ($managerTaskStats['overall']['total'] ?? 0)) ?> закрытых</strong></summary>
    <div class="task-fold__body">
        <section class="metric-row project-summary-metrics cost-summary-metrics">
            <div class="metric"><span><?= e($formatDecimal($managerTaskStats['overall']['avg_planned_hours'] ?? 0, 2)) ?></span><strong>Средний план, ч</strong></div>
            <div class="metric"><span><?= e($formatDecimal($managerTaskStats['overall']['avg_actual_hours'] ?? 0, 2)) ?></span><strong>Средний факт, ч</strong></div>
            <div class="metric"><span><?= e($formatDecimal($managerTaskStats['overall']['avg_cycle_days'] ?? 0, 1)) ?></span><strong>Средний срок, дн.</strong></div>
            <div class="metric"><span><?= e($formatPercent($managerTaskStats['overall']['over_plan_percent'] ?? 0)) ?></span><strong>Выше плана</strong></div>
        </section>
        <div class="analytics-grid">
            <div class="analytics-panel">
                <div class="panel__head"><h2>По дисциплинам</h2><span class="muted">история закрытых задач</span></div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Категория</th><th>План</th><th>Факт</th><th>Срок</th><th>Выше плана</th></tr></thead>
                        <tbody>
                            <?php $taskStatsRows($managerTaskStats['by_discipline'] ?? []); ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="analytics-panel">
                <div class="panel__head"><h2>По типам задач</h2><span class="muted">работа, выдача, задания</span></div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Категория</th><th>План</th><th>Факт</th><th>Срок</th><th>Выше плана</th></tr></thead>
                        <tbody>
                            <?php $taskStatsRows($managerTaskStats['by_type'] ?? []); ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</details>
<?php endif; ?>

<?php if ($canEdit): ?>
    <section class="panel cost-estimate-suggest" id="quick-planning">
        <div class="panel__head"><h2>Быстрая оценка по разделам</h2><span><?= count($sectionPlanningRows) ?> разделов</span></div>
        <form class="form-grid form-grid--compact" method="post" action="<?= url('/cost-estimates/' . $preproject['id'] . '/sections/bulk-labor') ?>">
            <?= csrf_field() ?>
            <label>Ответственный для новых строк<?php $userSelect('executor_id', ''); ?></label>
            <label>Отдел
                <select name="department_code">
                    <option value="">по ответственному</option>
                    <?php foreach ($departments as $department): ?><option value="<?= e($department) ?>"><?= e($department) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Пункт СБЦ<?php $sbcSelect('', ''); ?></label>
            <label>Индекс<?php $indexSelect('', ''); ?></label>
            <label>Кол-во СБЦ<input type="number" step="0.0001" name="sbc_quantity" value="1"></label>
            <label>Стадия, %<input type="number" step="0.01" name="sbc_stage_percent" value="100"></label>
            <label>Индекс вручную<input type="number" step="0.0001" name="sbc_deflator_coeff" value="1"></label>
            <label>К проч.<input type="number" step="0.0001" name="sbc_adjustment_coeff" value="1"></label>
            <label class="form-grid__full">Комментарий СБЦ<input name="sbc_comment" placeholder="Основание, письмо, допущение"></label>
            <div class="table-wrap form-grid__full">
                <table class="data-table data-table--compact">
                    <thead>
                    <tr>
                        <th>Выбор</th>
                        <th>Раздел</th>
                        <th>История задач</th>
                        <th>Часы</th>
                        <th>В оценке</th>
                        <?php if ($canSeeMoney): ?><th>СБЦ</th><?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sectionPlanningRows as $planningRow): ?>
                        <?php
                        $section = $planningRow['section'] ?? [];
                        $sectionId = (int) ($section['id'] ?? 0);
                        $stats = $planningRow['task_stats'] ?? [];
                        $suggested = (float) ($planningRow['suggested_hours'] ?? 0);
                        ?>
                        <tr>
                            <td><input type="checkbox" name="section_ids[]" value="<?= $sectionId ?>" aria-label="Выбрать раздел <?= e($section['title'] ?? '') ?>"></td>
                            <td>
                                <strong><?= e(($section['code'] ?: 'Раздел') . ' · ' . $section['title']) ?></strong>
                                <small><?= e(($section['volume'] ?? '') ?: 'без тома') ?><?= !empty($section['assignee_name']) ? ' · ' . e($section['assignee_name']) : '' ?></small>
                            </td>
                            <td>
                                <strong><?= e((int) ($stats['total'] ?? 0)) ?> закрытых</strong>
                                <small>план <?= e($formatDecimal($stats['avg_planned_hours'] ?? 0, 2)) ?> ч · факт <?= e($formatDecimal($stats['avg_actual_hours'] ?? 0, 2)) ?> ч · срок <?= e($formatDecimal($stats['avg_cycle_days'] ?? 0, 1)) ?> дн.</small>
                            </td>
                            <td>
                                <input type="hidden" name="suggested_hours[<?= $sectionId ?>]" value="<?= e($formatDecimal($suggested, 2)) ?>">
                                <input type="number" min="0" step="0.25" name="section_hours[<?= $sectionId ?>]" value="<?= e($formatDecimal($suggested, 2)) ?>" aria-label="Часы для раздела <?= e($section['title'] ?? '') ?>">
                            </td>
                            <td>
                                <strong><?= e((int) ($planningRow['labor_rows'] ?? 0)) ?> строк</strong>
                                <small><?= e($formatDecimal($planningRow['labor_hours'] ?? 0, 2)) ?> ч</small>
                            </td>
                            <?php if ($canSeeMoney): ?>
                                <td>
                                    <strong><?= e($formatMoney($section['sbc_reference_cost'] ?? 0)) ?></strong>
                                    <small><?= !empty($planningRow['has_sbc']) ? 'назначен' : 'не задан' ?></small>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$sectionPlanningRows): ?>
                        <tr><td colspan="<?= $canSeeMoney ? 6 : 5 ?>"><span class="muted">Сначала добавьте разделы в справочник ниже.</span></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <button class="btn btn--red" type="submit" formaction="<?= url('/cost-estimates/' . $preproject['id'] . '/sections/bulk-labor') ?>">Создать строки по выбранным</button>
            <button class="btn btn-outline" type="submit" formaction="<?= url('/cost-estimates/' . $preproject['id'] . '/sections/bulk-sbc') ?>">Назначить СБЦ выбранным</button>
        </form>
    </section>

    <details class="panel task-fold cost-estimate-suggest">
        <summary class="task-fold__summary"><span>Добавить одну строку вручную</span><strong>точный режим</strong></summary>
        <div class="task-fold__body">
        <form class="form-grid form-grid--compact" method="post" action="<?= url('/cost-estimates/' . $preproject['id'] . '/labor-estimates') ?>">
            <?= csrf_field() ?>
            <label>Раздел<input name="section_title" required placeholder="Например: Отопление и вентиляция"></label>
            <label>Вид работ<input name="work_title" required placeholder="Например: Расчёт воздухообмена"></label>
            <label>Ответственный<?php $userSelect('executor_id', ''); ?></label>
            <label>Отдел
                <select name="department_code">
                    <option value=""></option>
                    <?php foreach ($departments as $department): ?><option value="<?= e($department) ?>"><?= e($department) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Шифр<input name="section_code" placeholder="ОВ"></label>
            <label>Том<input name="section_volume" placeholder="Том 1"></label>
            <label>Часы отдела<input type="number" min="0" step="0.25" name="executor_hours"></label>
            <label>Кол-во модели<input type="number" min="0" step="0.0001" name="model_quantity" value="1"></label>
            <label>Сложность<input type="number" min="0" step="0.0001" name="model_complexity_coeff" value="1"></label>
            <label>Типовость<input type="number" min="0" step="0.0001" name="model_typicality_coeff" value="1"></label>
            <label>BIM/ТИМ<input type="number" min="0" step="0.0001" name="model_bim_coeff" value="1"></label>
            <label>Срочность<input type="number" min="0" step="0.0001" name="model_urgency_coeff" value="1"></label>
            <label>Исходные данные<input type="number" min="0" step="0.0001" name="model_input_quality_coeff" value="1"></label>
            <label>Пункт СБЦ<?php $sbcSelect('', ''); ?></label>
            <label>Кол-во СБЦ<input type="number" step="0.0001" name="sbc_quantity" value="1"></label>
            <label>Стадия, %<input type="number" step="0.01" name="sbc_stage_percent" value="100"></label>
            <label>Индекс<?php $indexSelect('', ''); ?></label>
            <label>К проч.<input type="number" step="0.0001" name="sbc_adjustment_coeff" value="1"></label>
            <label class="form-grid__full">Описание<textarea name="work_description" rows="2" placeholder="Исходные данные, допущения, границы оценки"></textarea></label>
            <label class="form-grid__full">Комментарий постановщика<textarea name="comment" rows="2"></textarea></label>
            <button class="btn btn--red" type="submit">Добавить строку оценки</button>
        </form>
        </div>
    </details>
<?php endif; ?>

<details class="panel task-fold" open>
    <summary class="task-fold__summary"><span>Параметры модели</span><strong>подсказка, не решение</strong></summary>
    <div class="task-fold__body">
        <dl class="stat-list">
            <div><dt>Тип объекта</dt><dd><?= e($objectTypes[$preproject['object_type'] ?? ''] ?? ($preproject['object_type'] ?: '—')) ?></dd></div>
            <div><dt>Стадия</dt><dd><?= e($preproject['stage'] ?: '—') ?></dd></div>
            <div><dt>Площадь</dt><dd><?= (float) ($preproject['area_m2'] ?? 0) > 0 ? e($formatDecimal($preproject['area_m2'], 2)) . ' м2' : '—' ?></dd></div>
        </dl>
    </div>
</details>

<details class="panel task-fold cost-passport-fold">
    <summary class="task-fold__summary"><span>Паспорт предпроекта</span><strong><?= e($preproject['object'] ?: $preproject['stage'] ?: 'данные') ?></strong></summary>
    <div class="task-fold__body">
        <?php if ($canEdit): ?>
            <form class="form-grid form-grid--compact cost-estimate-meta" method="post" action="<?= url('/cost-estimates/' . $preproject['id']) ?>">
                <?= csrf_field() ?>
                <label>Код<input name="code" maxlength="20" required value="<?= e($preproject['code']) ?>"></label>
                <label>Название<input name="title" required value="<?= e($preproject['title']) ?>"></label>
                <label>Объект<input name="object" value="<?= e($preproject['object']) ?>"></label>
                <label>Адрес<input name="address" value="<?= e($preproject['address']) ?>"></label>
                <label>Тип объекта
                    <select name="object_type">
                        <option value=""></option>
                        <?php foreach ($objectTypes as $value => $label): ?>
                            <option value="<?= e($value) ?>"<?= selected($preproject['object_type'] ?? '', $value) ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Площадь, м2<input type="number" step="0.01" name="area_m2" value="<?= e($preproject['area_m2']) ?>"></label>
                <label>Стадия<input name="stage" value="<?= e($preproject['stage']) ?>"></label>
                <label>Цвет<input type="color" name="color" value="<?= e($preproject['color'] ?: '#9A6A00') ?>"></label>
                <label class="form-grid__full">Стадии / состав<textarea name="stages_text" rows="2"><?= e($preproject['stages_text']) ?></textarea></label>
                <button class="btn btn--red" type="submit">Сохранить паспорт</button>
            </form>
        <?php else: ?>
            <dl class="stat-list">
                <div><dt>Объект</dt><dd><?= e($preproject['object'] ?: '—') ?></dd></div>
                <div><dt>Адрес</dt><dd><?= e($preproject['address'] ?: '—') ?></dd></div>
                <div><dt>Тип</dt><dd><?= e($objectTypes[$preproject['object_type'] ?? ''] ?? ($preproject['object_type'] ?: '—')) ?></dd></div>
                <div><dt>Площадь</dt><dd><?= (float) ($preproject['area_m2'] ?? 0) > 0 ? e($formatDecimal($preproject['area_m2'], 2)) . ' м2' : '—' ?></dd></div>
            </dl>
        <?php endif; ?>
    </div>
</details>

<section class="panel sheet-panel">
    <div class="panel__head"><h2>Реестр оценки</h2><span><?= count($laborRows) ?> строк</span></div>
    <form class="form-grid" method="get" action="<?= url('/cost-estimates/' . $preproject['id']) ?>">
        <label>Статус
            <select name="status">
                <option value="">Все статусы</option>
                <?php foreach ($laborStatuses as $status): ?>
                    <option value="<?= e($status) ?>"<?= selected($filters['status'] ?? '', $status) ?>><?= e(labor_estimate_status_label($status)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Раздел
            <select name="section_id">
                <option value="">Все разделы</option>
                <?php foreach ($sections as $section): ?>
                    <option value="<?= (int) $section['id'] ?>"<?= selected($filters['section_id'] ?? '', $section['id']) ?>><?= e(($section['code'] ?: 'Раздел') . ' · ' . $section['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Ответственный<?php $userSelect('executor_id', $filters['executor_id'] ?? ''); ?></label>
        <label>Исполнитель<?php $userSelect('allocation_user_id', $filters['allocation_user_id'] ?? ''); ?></label>
        <button class="btn" type="submit">Фильтр</button>
        <a class="btn btn-outline" href="<?= url('/cost-estimates/' . $preproject['id']) ?>">Сбросить</a>
    </form>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Раздел / работа</th>
                <th>Отдел</th>
                <th>Исполнители</th>
                <th>Статус</th>
                <th>План</th>
                <th>Ответственный</th>
                <th>ГИП</th>
                <th>Директор</th>
                <?php if ($canSeeMoney): ?><th>Деньги</th><th>СБЦ</th><th>Отклонение</th><?php endif; ?>
                <th>Следующее действие</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($laborRows as $row): ?>
                <?php
                $rowAllocations = $laborAllocations[(int) $row['id']] ?? [];
                $workTitle = (string) ($row['work_title'] ?? '') !== '' ? (string) $row['work_title'] : (string) ($row['task_title'] ?? '');
                $status = (string) ($row['status'] ?? 'draft');
                $sameDepartment = (string) ($currentUser['department'] ?? '') !== '' && (string) ($currentUser['department'] ?? '') === (string) ($row['executor_department'] ?? '');
                $canResponsibleAct = ($canEdit || $sameDepartment) && in_array($status, ['draft', 'returned_to_department', 'assigned', 'returned_to_responsible'], true);
                $canGipAct = !empty($canGipApprove) && in_array($status, ['department_submitted', 'submitted', 'returned_to_gip'], true);
                $canDirectorAct = !empty($canDirectorApprove) && in_array($status, ['gip_adjusted', 'gip_approved'], true);
                $defaultGipHours = (float) ($row['gip_hours'] ?: $row['executor_hours'] ?: $row['effective_hours'] ?: 0);
                $defaultDirectorHours = (float) ($row['director_hours'] ?: $row['gip_hours'] ?: $row['executor_hours'] ?: 0);
                $nextHint = match ($status) {
                    'draft', 'assigned', 'returned_to_responsible', 'returned_to_department' => 'Отдел заполняет часы и подаёт ГИПу.',
                    'department_submitted', 'submitted' => 'ГИП корректирует или возвращает отделу.',
                    'gip_adjusted', 'gip_approved' => 'Директор утверждает финально.',
                    'director_approved' => 'Оценка утверждена.',
                    default => 'Строка оценки в реестре.',
                };
                ?>
                <tr id="labor-<?= (int) $row['id'] ?>">
                    <td>
                        <strong><?= e(($row['section_code'] ?: 'Раздел') . ' · ' . $workTitle) ?></strong>
                        <small><?= e($row['work_description'] ?: $row['section_title']) ?></small>
                        <?php if (($row['return_comment'] ?? '') !== ''): ?><small class="cell-danger">Возврат: <?= e($row['return_comment']) ?></small><?php endif; ?>
                    </td>
                    <td><?= e($row['department_code'] ?: $row['executor_department']) ?><small><?= e($row['executor_name']) ?></small></td>
                    <td>
                        <?php foreach ($rowAllocations as $allocation): ?>
                            <span class="tag"><?= e($allocation['user_name']) ?> · <?= e($formatDecimal($allocation['hours'], 2)) ?> ч / <?= e($formatDecimal($allocation['days'], 2)) ?> дн.</span>
                        <?php endforeach; ?>
                        <?php if (!$rowAllocations): ?><span class="muted">не назначены</span><?php endif; ?>
                    </td>
                    <td><span class="status status--<?= e(labor_estimate_status_class($row['status'])) ?>"><?= e(labor_estimate_status_label($row['status'])) ?></span></td>
                    <td><strong><?= e($formatDecimal($row['planning_hours'] ?? 0, 2)) ?> ч</strong><small><?= e($formatDecimal($row['planning_days'] ?? 0, 2)) ?> дн. · <?= e($row['planning_source_label'] ?? 'Не задано') ?></small></td>
                    <td><?= e($formatDecimal($row['executor_hours'] ?? 0, 2)) ?> ч<small><?= e($formatDecimal($row['executor_days'] ?? $daysFromHours($row['executor_hours'] ?? 0), 2)) ?> дн.</small></td>
                    <td><?= e($formatDecimal($row['gip_hours'] ?? 0, 2)) ?> ч<small><?= e($formatDecimal($row['gip_days'] ?? $daysFromHours($row['gip_hours'] ?? 0), 2)) ?> дн.</small></td>
                    <td><?= e($formatDecimal($row['director_hours'] ?? 0, 2)) ?> ч<small><?= e($formatDecimal($row['director_days'] ?? $daysFromHours($row['director_hours'] ?? 0), 2)) ?> дн.</small></td>
                    <?php if ($canSeeMoney): ?>
                        <td><strong><?= e($formatMoney($row['planning_money_thousand'] ?? 0)) ?></strong><small>тыс. руб.</small></td>
                        <td><?= e($formatMoney($row['planning_sbc_thousand'] ?? 0)) ?><small><?= e($row['sbc_index_label'] ?? '') ?></small></td>
                        <td><strong><?= e($formatMoney($row['planning_delta_thousand'] ?? 0)) ?></strong><small><?= e($formatPercent($row['planning_delta_percent'] ?? null)) ?></small></td>
                    <?php endif; ?>
                    <td class="cost-action-cell">
                        <small><?= e($nextHint) ?></small>
                        <?php if ($canResponsibleAct): ?>
                            <form class="cost-inline-action" method="post" action="<?= url('/cost-estimates/' . $preproject['id'] . '/labor-estimates/' . (int) $row['id'] . '/department-submit') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="work_title" value="<?= e($workTitle) ?>">
                                <input type="number" min="0.25" step="0.25" name="executor_hours" value="<?= e($formatDecimal($row['executor_hours'] ?: $row['model_suggested_hours'] ?: 0, 2)) ?>" aria-label="Часы отдела" required>
                                <input name="executor_comment" placeholder="Комментарий отдела" aria-label="Комментарий отдела">
                                <button class="btn btn--red btn-sm" type="submit">Отправить ГИПу</button>
                            </form>
                        <?php elseif ($canGipAct): ?>
                            <form class="cost-inline-action" method="post" action="<?= url('/cost-estimates/' . $preproject['id'] . '/labor-estimates/' . (int) $row['id'] . '/gip') ?>">
                                <?= csrf_field() ?>
                                <input type="number" min="0.25" step="0.25" name="gip_hours" value="<?= e($formatDecimal($defaultGipHours, 2)) ?>" aria-label="Часы ГИПа" required>
                                <input name="gip_comment" placeholder="Комментарий" aria-label="Комментарий ГИПа">
                                <button class="btn btn--red btn-sm" type="submit">ГИП скорректировал</button>
                            </form>
                            <form class="cost-inline-action" method="post" action="<?= url('/cost-estimates/' . $preproject['id'] . '/labor-estimates/' . (int) $row['id'] . '/gip-return') ?>">
                                <?= csrf_field() ?>
                                <input name="return_comment" required placeholder="Причина возврата" aria-label="Причина возврата">
                                <button class="btn btn-outline btn-sm" type="submit">Вернуть отделу</button>
                            </form>
                        <?php elseif ($canDirectorAct): ?>
                            <form class="cost-inline-action" method="post" action="<?= url('/cost-estimates/' . $preproject['id'] . '/labor-estimates/' . (int) $row['id'] . '/director') ?>">
                                <?= csrf_field() ?>
                                <input type="number" min="0.25" step="0.25" name="director_hours" value="<?= e($formatDecimal($defaultDirectorHours, 2)) ?>" aria-label="Часы директора" required>
                                <input name="director_comment" placeholder="Комментарий" aria-label="Комментарий директора">
                                <button class="btn btn--red btn-sm" type="submit">Утвердить</button>
                            </form>
                        <?php else: ?>
                            <?php if (!empty($row['task_id'])): ?><a class="task-link" href="<?= url('/tasks/' . (int) $row['task_id']) ?>">Старая задача #<?= (int) $row['task_id'] ?></a><?php else: ?><span class="muted">служебной задачи нет</span><?php endif; ?>
                        <?php endif; ?>
                        <small>Модель: <?= e($formatDecimal($row['model_suggested_hours'] ?? 0, 2)) ?> ч</small>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$laborRows): ?>
                <tr><td colspan="<?= 9 + ($canSeeMoney ? 3 : 0) ?>"><div class="empty-state empty-state--compact"><span class="empty-state__icon">—</span><strong>Оценки не назначены</strong><span>Добавьте строки оценки по разделам и ответственным.</span></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($laborResponsibleTotals || $laborAssigneeTotals): ?>
    <section class="analytics-grid">
        <?php if ($laborResponsibleTotals): ?>
            <div class="panel analytics-panel">
                <div class="panel__head"><h2>Итоги по ответственным</h2><span class="muted"><?= count($laborResponsibleTotals) ?></span></div>
                <div class="analytics-list">
                    <?php foreach ($laborResponsibleTotals as $row): ?>
                        <article class="analytics-row"><div><strong><?= e($row['name']) ?></strong><small><?= e($row['department']) ?></small></div><span><?= e($formatDecimal($row['hours'], 2)) ?> ч</span><span><?= e($formatDecimal($row['days'], 2)) ?> дн.</span></article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($laborAssigneeTotals): ?>
            <div class="panel analytics-panel">
                <div class="panel__head"><h2>Предварительные исполнители</h2><span class="muted"><?= count($laborAssigneeTotals) ?></span></div>
                <div class="analytics-list">
                    <?php foreach ($laborAssigneeTotals as $row): ?>
                        <article class="analytics-row"><div><strong><?= e($row['name']) ?></strong><small><?= e($row['department']) ?></small></div><span><?= e($formatDecimal($row['hours'], 2)) ?> ч</span><span><?= e($formatDecimal($row['days'], 2)) ?> дн.</span></article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<details class="panel task-fold cost-sections-fold">
    <summary class="task-fold__summary"><span>СБЦ и справочник разделов</span><strong><?= count($sections) ?></strong></summary>
    <div class="task-fold__body">
    <?php if ($canEdit): ?>
        <form class="form-grid form-grid--compact cost-section-add" method="post" action="<?= url('/cost-estimates/' . $preproject['id'] . '/sections') ?>">
            <?= csrf_field() ?>
            <label>Том<input name="volume"></label>
            <label>Шифр<input name="code"></label>
            <label>Наименование<input name="title" required></label>
            <label>Ответственный<?php $userSelect('assignee_id', ''); ?></label>
            <label>Пункт СБЦ<?php $sbcSelect('', ''); ?></label>
            <label>Кол-во<input type="number" step="0.0001" name="sbc_quantity" value="1"></label>
            <label>Стадия, %<input type="number" step="0.01" name="sbc_stage_percent" value="100"></label>
            <label>Индекс<input type="number" step="0.0001" name="sbc_deflator_coeff" value="1"></label>
            <label>К проч.<input type="number" step="0.0001" name="sbc_adjustment_coeff" value="1"></label>
            <label class="form-grid__full">Комментарий<textarea name="comments" rows="2"></textarea></label>
            <button class="btn btn--red" type="submit">Добавить раздел</button>
        </form>
    <?php endif; ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Раздел</th>
                <th>Ответственный</th>
                <th>СБЦ справочно</th>
                <th>Комментарий</th>
                <?php if ($canEdit): ?><th></th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($sections as $section): ?>
                <?php $formId = 'section-' . (int) $section['id']; ?>
                <tr>
                    <td>
                        <?php if ($canEdit): ?>
                            <form id="<?= e($formId) ?>" method="post" action="<?= url('/cost-estimates/' . $preproject['id'] . '/sections/' . $section['id']) ?>"></form>
                            <input form="<?= e($formId) ?>" type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input form="<?= e($formId) ?>" name="volume" value="<?= e($section['volume']) ?>" placeholder="Том">
                            <input form="<?= e($formId) ?>" name="code" value="<?= e($section['code']) ?>" placeholder="Шифр">
                            <input form="<?= e($formId) ?>" name="title" value="<?= e($section['title']) ?>" required>
                        <?php else: ?>
                            <strong><?= e(($section['code'] ?: 'Раздел') . ' · ' . $section['title']) ?></strong>
                            <small><?= e($section['volume']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?php $canEdit ? $userSelect('assignee_id', $section['assignee_id'], $formId) : print e($section['assignee_name'] ?: '—'); ?></td>
                    <td>
                        <?php if ($canEdit): ?>
                            <?php $sbcSelect($formId, $section['sbc_item_id']); ?>
                            <input form="<?= e($formId) ?>" type="number" step="0.0001" name="sbc_quantity" value="<?= e($formatDecimal($section['sbc_quantity'] ?? 1, 4)) ?>" placeholder="Кол-во">
                            <input form="<?= e($formId) ?>" type="number" step="0.01" name="sbc_stage_percent" value="<?= e($formatDecimal($section['sbc_stage_percent'] ?? 100, 2)) ?>" placeholder="Стадия %">
                            <input form="<?= e($formId) ?>" type="number" step="0.0001" name="sbc_deflator_coeff" value="<?= e($formatDecimal($section['sbc_deflator_coeff'] ?? 1, 4)) ?>" placeholder="Индекс">
                            <input form="<?= e($formId) ?>" type="number" step="0.0001" name="sbc_adjustment_coeff" value="<?= e($formatDecimal($section['sbc_adjustment_coeff'] ?? 1, 4)) ?>" placeholder="К проч.">
                            <textarea form="<?= e($formId) ?>" name="sbc_comment" rows="2" placeholder="Основание СБЦ"><?= e($section['sbc_comment'] ?? '') ?></textarea>
                        <?php else: ?>
                            <strong><?= e($formatMoney($section['sbc_reference_cost'] ?? 0)) ?> тыс. руб.</strong>
                            <small><?= e(trim((string) (($section['sbc_table_code'] ?? '') . ' ' . ($section['sbc_item_code'] ?? '') . ' ' . ($section['sbc_work_name'] ?? '')))) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($canEdit): ?>
                            <textarea form="<?= e($formId) ?>" name="comments" rows="2"><?= e($section['comments']) ?></textarea>
                        <?php else: ?>
                            <?= e($section['comments']) ?>
                        <?php endif; ?>
                    </td>
                    <?php if ($canEdit): ?>
                        <td>
                            <input form="<?= e($formId) ?>" type="hidden" name="status" value="<?= e($section['status'] ?: 'draft') ?>">
                            <button class="btn btn--red btn-sm" form="<?= e($formId) ?>" type="submit">Сохранить</button>
                            <form method="post" action="<?= url('/cost-estimates/' . $preproject['id'] . '/sections/' . $section['id'] . '/delete') ?>" onsubmit="return confirm('Удалить раздел?')">
                                <?= csrf_field() ?>
                                <button class="btn btn-ghost btn-sm" type="submit">Удалить</button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$sections): ?>
                <tr><td colspan="<?= $canEdit ? 5 : 4 ?>"><span class="muted">Разделы появятся после создания строк оценки.</span></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    </div>
</details>

<?php if ($canDirectorApprove): ?>
<details id="sbc-indices" class="panel task-fold" open>
    <summary class="task-fold__summary"><span>Индексы СБЦ/ПИР</span><strong><?= count($sbcIndices) ?></strong></summary>
    <div class="task-fold__body">
        <form class="form-grid form-grid--compact" method="post" action="<?= url('/cost-estimates/' . $preproject['id'] . '/sbc-indices') ?>">
            <?= csrf_field() ?>
            <label>Квартал<input name="period_key" required placeholder="2026-Q2"></label>
            <label>Название<input name="label" placeholder="II квартал 2026"></label>
            <label>Коэффициент<input type="number" step="0.0001" min="0" name="index_value" value="1" required></label>
            <label>Дата источника<input type="date" name="source_date"></label>
            <label class="form-grid__full">Источник / письмо<input name="source_ref" placeholder="Письмо Минстроя ..."></label>
            <label class="form-grid__full">Комментарий<textarea name="comment" rows="2"></textarea></label>
            <label><input type="checkbox" name="is_active" value="1" checked> Активен</label>
            <button class="btn btn--red" type="submit">Сохранить индекс</button>
        </form>
        <form method="post" action="<?= url('/cost-estimates/' . $preproject['id'] . '/sbc-seed') ?>">
            <?= csrf_field() ?>
            <button class="btn btn-outline" type="submit">Обновить встроенный СБЦ</button>
        </form>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Квартал</th><th>Коэффициент</th><th>Источник</th><th>Правка</th></tr></thead>
                <tbody>
                <?php foreach ($sbcIndices as $index): ?>
                    <tr>
                        <td><strong><?= e($index['label'] ?: $index['period_key']) ?></strong><small><?= e($index['period_key']) ?><?= empty($index['is_active']) ? ' · выключен' : '' ?></small></td>
                        <td><?= e(number_format((float) $index['index_value'], 4, '.', ' ')) ?></td>
                        <td><?= e($index['source_ref'] ?? '') ?><small><?= e($index['comment'] ?? '') ?></small></td>
                        <td>
                            <form class="cost-inline-action" method="post" action="<?= url('/cost-estimates/' . $preproject['id'] . '/sbc-indices') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="index_id" value="<?= (int) $index['id'] ?>">
                                <input type="hidden" name="period_key" value="<?= e($index['period_key']) ?>">
                                <input name="label" value="<?= e($index['label']) ?>" aria-label="Название индекса">
                                <input type="number" step="0.0001" min="0" name="index_value" value="<?= e(number_format((float) $index['index_value'], 4, '.', '')) ?>" aria-label="Коэффициент индекса">
                                <input name="source_ref" value="<?= e($index['source_ref'] ?? '') ?>" aria-label="Источник индекса">
                                <input type="date" name="source_date" value="<?= e($index['source_date'] ?? '') ?>" aria-label="Дата источника индекса">
                                <input name="comment" value="<?= e($index['comment'] ?? '') ?>" aria-label="Комментарий индекса">
                                <label><input type="checkbox" name="is_active" value="1"<?= !empty($index['is_active']) ? ' checked' : '' ?>> Активен</label>
                                <button class="btn btn-sm" type="submit">OK</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$sbcIndices): ?><tr><td colspan="4"><span class="muted">Индексы ещё не заведены.</span></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</details>
<?php endif; ?>

<?php if ($canEdit): ?>
    <section class="panel form-grid">
        <div class="panel__head form-grid__full"><h2>Перевести в проект</h2><span>после предпроектной оценки</span></div>
        <form id="convert-preproject" method="post" action="<?= url('/cost-estimates/' . $preproject['id'] . '/convert') ?>"></form>
        <input form="convert-preproject" type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <label>Стадия
            <select form="convert-preproject" name="stage">
                <?php foreach (['ПД','РД','ПД-РД','АН'] as $stage): ?><option value="<?= e($stage) ?>"><?= e($stage) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>ГИП<?php $userSelect('gip_user_id', '', 'convert-preproject'); ?></label>
        <label>РП<?php $userSelect('rp_user_id', '', 'convert-preproject'); ?></label>
        <label>Затраты, тыс. ₽<input form="convert-preproject" type="number" name="budget_cost_thousand" min="0" step="0.01" required></label>
        <label>Прибыль, тыс. ₽<input form="convert-preproject" type="number" name="budget_profit_thousand" min="0" step="0.01" required></label>
        <label>Премиальная часть, тыс. ₽<input form="convert-preproject" type="number" name="budget_bonus_thousand" min="0" step="0.01" required></label>
        <button class="btn btn--red" form="convert-preproject" type="submit">Перевести в проект</button>
    </section>
<?php endif; ?>
