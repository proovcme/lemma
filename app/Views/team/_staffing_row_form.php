<?php
$row = $staffingRow ?? null;
$periodId = (int) ($period['id'] ?? 0);
$action = '/director/staffing/periods/' . $periodId . '/rows' . ($row ? '/' . (int) $row['id'] : '');
?>
<form class="form-grid staffing-row-form" method="post" action="<?= url($action) ?>">
    <?= csrf_field() ?>
    <label><span>Раздел</span><select name="department_code" required><option value="">Выберите</option><?php foreach ($departments as $item): ?><option value="<?= e($item['code']) ?>"<?= selected((string) ($row['department_code'] ?? ''), (string) $item['code']) ?>><?= e($item['code'] . ' — ' . $item['name']) ?></option><?php endforeach; ?></select></label>
    <label><span>Группа</span><select name="group_id"><option value="">Без группы</option><?php foreach ($groups as $item): ?><option value="<?= (int) $item['id'] ?>"<?= selected((string) ($row['group_id'] ?? ''), (string) $item['id']) ?>><?= e($item['department_code'] . ' — ' . $item['name']) ?></option><?php endforeach; ?></select></label>
    <label><span>Должность</span><select name="position_id"><option value="">Указать текстом</option><?php foreach ($positions as $item): ?><option value="<?= (int) $item['id'] ?>"<?= selected((string) ($row['position_id'] ?? ''), (string) $item['id']) ?>><?= e($item['title']) ?></option><?php endforeach; ?></select></label>
    <label><span>Название должности</span><input name="position_title" value="<?= e($row['position_title'] ?? '') ?>" required></label>
    <label><span>Сотрудник</span><select name="user_id"><option value="">Без сотрудника</option><?php foreach ($users as $item): ?><option value="<?= (int) $item['id'] ?>"<?= selected((string) ($row['user_id'] ?? ''), (string) $item['id']) ?>><?= e($item['name'] . (($item['department'] ?? '') ? ' · ' . $item['department'] : '')) ?></option><?php endforeach; ?></select></label>
    <label><span>Название вакансии</span><input name="employee_name" value="<?= e($row['employee_name'] ?? '') ?>" placeholder="Вакансия или подбор"></label>
    <label><span>Ставок</span><input type="number" min="0.01" max="2" step="0.01" name="fte" value="<?= e((string) ($row['fte'] ?? 1)) ?>" required></label>
    <label><span>ФОТ, ₽/мес</span><input type="number" min="0" step="0.01" name="monthly_fot" value="<?= e((string) ($row['monthly_fot'] ?? 0)) ?>" required></label>
    <label><span>Состояние позиции</span><select name="status"><?php foreach ($statusLabels as $key => $label): ?><option value="<?= e($key) ?>"<?= selected((string) ($row['status'] ?? 'vacancy'), $key) ?>><?= e($label) ?></option><?php endforeach; ?></select><small class="field-hint">В реестре отмечаются только вакансии и плановые кадровые изменения.</small></label>
    <label><span>Изменение в месяце</span><select name="change_type"><?php foreach ($changeLabels as $key => $label): ?><option value="<?= e($key) ?>"<?= selected((string) ($row['change_type'] ?? 'none'), $key) ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label><span>Сумма изменения, ₽</span><input type="number" step="0.01" name="change_amount" value="<?= e((string) ($row['change_amount'] ?? '')) ?>"></label>
    <label><span>Порядок</span><input type="number" name="sort_order" value="<?= (int) ($row['sort_order'] ?? 100) ?>"></label>
    <label class="form-grid__full"><span>Комментарий</span><textarea name="comment" rows="2"><?= e($row['comment'] ?? '') ?></textarea></label>
    <div class="form-grid__full staffing-row-actions"><button class="btn btn-red" type="submit"><?= $row ? 'Сохранить позицию' : 'Добавить позицию' ?></button></div>
</form>
<?php if ($row): ?><form class="staffing-delete-row" method="post" action="<?= url('/director/staffing/periods/' . $periodId . '/rows/' . (int) $row['id'] . '/delete') ?>" onsubmit="return confirm('Удалить позицию из черновика?')"><?= csrf_field() ?><button class="btn btn-sm btn-outline" type="submit">Удалить</button></form><?php endif; ?>
