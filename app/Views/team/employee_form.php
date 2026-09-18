<?php require __DIR__ . '/_tabs.php';
$isEdit = is_array($employee);
?>

<section class="panel team-form-panel">
    <div class="panel__head"><div><h2><?= $isEdit ? 'Данные сотрудника' : 'Создание сотрудника' ?></h2><p class="muted">Должность одновременно назначает название и набор полномочий.</p></div></div>
    <form class="form-grid" method="post" action="<?= $isEdit ? url('/team/employees/' . (int) $employee['id']) : url('/admin/users') ?>">
        <?= csrf_field() ?>
        <?php if (!$isEdit): ?><input type="hidden" name="return_to" value="/team"><input type="hidden" name="role" value="engineer"><?php endif; ?>
        <label><span>Табельный номер</span><input name="tab_number" required value="<?= e($employee['tab_number'] ?? '') ?>"></label>
        <label><span>ФИО</span><input name="name" required value="<?= e($employee['name'] ?? '') ?>"></label>
        <label><span>Email</span><input type="email" name="email" required value="<?= e($employee['email'] ?? '') ?>"></label>
        <label><span>Должность</span><select name="position_id" required><option value="">Выберите должность</option><?php foreach ($positions as $position): if ((int) ($position['is_active'] ?? 1) !== 1) continue; ?><option value="<?= (int) $position['id'] ?>"<?= selected((string) ($employee['position_id'] ?? ''), (string) $position['id']) ?>><?= e($position['title']) ?><?= !empty($position['grade']) ? ' · ' . e($position['grade']) : '' ?></option><?php endforeach; ?></select></label>
        <label><span>Отдел</span><select name="department"><option value="">Без отдела</option><?php foreach ($departments as $department): ?><option value="<?= e($department['code']) ?>"<?= selected((string) ($employee['department'] ?? ''), (string) $department['code']) ?>><?= e($department['name']) ?> · <?= e($department['code']) ?></option><?php endforeach; ?></select></label>
        <label><span>Непосредственный руководитель</span><select name="manager_id"><option value="">Не назначен</option><?php foreach ($managers as $manager): ?><option value="<?= (int) $manager['id'] ?>"<?= selected((string) ($employee['manager_id'] ?? ''), (string) $manager['id']) ?>><?= e($manager['name']) ?><?= !empty($manager['department']) ? ' · ' . e($manager['department']) : '' ?></option><?php endforeach; ?></select></label>
        <div class="form-grid__full form-actions"><button class="btn btn--red" type="submit"><?= $isEdit ? 'Сохранить карточку' : 'Создать сотрудника' ?></button><a class="btn btn-outline" href="<?= url('/team') ?>">Отмена</a><?php if ($isEdit): ?><a class="btn btn-outline" href="<?= url('/profiles/' . (int) $employee['id']) ?>">Задачи и статистика</a><?php endif; ?></div>
    </form>
</section>

<?php if ($isEdit): ?>
<details class="panel">
    <summary class="panel__head"><span>Статус сотрудника</span><small>Развернуть / свернуть</small></summary>
    <form class="form-stack" method="post" action="<?= url('/admin/users/' . (int) $employee['id'] . '/active') ?>" onsubmit="return confirm('Изменить рабочий статус сотрудника?')">
        <?= csrf_field() ?><input type="hidden" name="return_to" value="/team/employees/<?= (int) $employee['id'] ?>/edit"><input type="hidden" name="is_active" value="<?= (int) ($employee['is_active'] ?? 1) === 1 ? 0 : 1 ?>">
        <p><?= (int) ($employee['is_active'] ?? 1) === 1 ? 'Сотрудник работает и может входить в систему.' : 'Сотрудник уволен, вход в систему заблокирован.' ?></p>
        <button class="btn btn-outline" type="submit"><?= (int) ($employee['is_active'] ?? 1) === 1 ? 'Уволить сотрудника' : 'Вернуть сотрудника' ?></button>
    </form>
</details>
<?php endif; ?>
