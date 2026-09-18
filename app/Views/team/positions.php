<?php require __DIR__ . '/_tabs.php';
$selectedCapabilities = array_fill_keys((array) ($selectedPosition['capabilities'] ?? []), true);
$competencyProfiles = $competencyProfiles ?? [];
$capabilityGroups = [
    'Рабочий контур' => ['locia', 'projects', 'projects_all', 'projects_create', 'tasks_edit_all', 'dpr', 'reports'],
    'Специальные разделы' => ['bim', 'competency', 'hr', 'integrations'],
    'Администрирование' => ['users', 'settings', 'delete'],
];
?>

<section class="panel">
    <div class="panel__head"><div><h2>Справочник должностей</h2><p class="muted">Одна должность — одно название, уровень полномочий и профиль доступа.</p></div><a class="btn btn--red" href="#new-position">+ Должность</a></div>
    <div class="team-position-list">
        <?php foreach ($positions as $position): ?>
            <a class="team-position-row<?= (int) ($selectedPosition['id'] ?? 0) === (int) $position['id'] ? ' is-current' : '' ?><?= (int) ($position['is_active'] ?? 1) !== 1 ? ' is-archived' : '' ?>" href="<?= url('/team/positions?position=' . (int) $position['id']) ?>">
                <span><strong><?= e($position['title']) ?></strong><small><?= e($position['grade'] ?: role_label($position['base_role'])) ?></small></span>
                <span class="team-position-count"><?= (int) $position['employee_count'] ?> чел.</span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($selectedPosition): ?>
<section class="panel">
    <div class="panel__head"><div><h2><?= e($selectedPosition['title']) ?></h2><p class="muted">Изменение прав сразу затронет <?= (int) $selectedPosition['employee_count'] ?> сотрудников.</p></div></div>
    <form class="form-grid" method="post" action="<?= url('/team/positions/' . (int) $selectedPosition['id']) ?>" onsubmit="return confirm('Сохранить должность и применить доступы ко всем назначенным сотрудникам?')">
        <?= csrf_field() ?>
        <label><span>Название</span><input name="title" required value="<?= e($selectedPosition['title']) ?>"></label>
        <label><span>Грейд сотрудника</span><input name="grade" value="<?= e($selectedPosition['grade'] ?? '') ?>"><small>Показывается отдельным полем в профиле сотрудника.</small></label>
        <label><span>Целевой профиль Performance Review</span><select name="competency_position_index"><option value="">Не настроен</option><?php foreach ($competencyProfiles as $profile): ?><option value="<?= (int) $profile['index'] ?>"<?= selected((string) ($selectedPosition['competency_position_index'] ?? ''), (string) $profile['index']) ?>><?= e($profile['title']) ?> · <?= e($profile['grade']) ?></option><?php endforeach; ?></select><small>Из этого профиля фиксируются целевые уровни компетенций при запуске ревью.</small></label>
        <label><span>Уровень полномочий</span><select name="base_role"<?= (int) ($selectedPosition['is_protected'] ?? 0) === 1 ? ' disabled' : '' ?>><?php foreach ($baseRoles as $baseRole): ?><option value="<?= e($baseRole) ?>"<?= selected((string) $selectedPosition['base_role'], $baseRole) ?>><?= e(role_label($baseRole)) ?></option><?php endforeach; ?></select><?php if ((int) ($selectedPosition['is_protected'] ?? 0) === 1): ?><input type="hidden" name="base_role" value="director"><small>Директор всегда имеет полный доступ.</small><?php endif; ?></label>
        <label><span>Порядок</span><input type="number" name="sort_order" value="<?= (int) $selectedPosition['sort_order'] ?>"></label>
        <label class="form-grid__full"><span>Описание</span><textarea name="description" rows="3"><?= e($selectedPosition['description'] ?? '') ?></textarea></label>
        <div class="form-grid__full team-permissions">
            <?php foreach ($capabilityGroups as $groupLabel => $keys): ?>
                <fieldset class="team-permission-group"><legend><?= e($groupLabel) ?></legend><?php foreach ($keys as $key): if (!isset($capabilityLabels[$key])) continue; ?><label class="check-row"><input type="checkbox" name="capabilities[]" value="<?= e($key) ?>"<?= isset($selectedCapabilities[$key]) ? ' checked' : '' ?><?= (int) ($selectedPosition['is_protected'] ?? 0) === 1 ? ' disabled' : '' ?>><span><?= e($capabilityLabels[$key]) ?></span></label><?php endforeach; ?></fieldset>
            <?php endforeach; ?>
        </div>
        <div class="form-grid__full form-actions"><button class="btn btn--red" type="submit">Сохранить должность</button></div>
    </form>
    <div class="team-position-actions">
        <form method="post" action="<?= url('/team/positions/' . (int) $selectedPosition['id'] . '/clone') ?>"><?= csrf_field() ?><button class="btn btn-outline" type="submit">Создать копию</button></form>
        <?php if ((int) ($selectedPosition['is_protected'] ?? 0) !== 1): ?><form method="post" action="<?= url('/team/positions/' . (int) $selectedPosition['id'] . '/archive') ?>" onsubmit="return confirm('Архивировать эту должность?')"><?= csrf_field() ?><button class="btn btn-outline" type="submit">Архивировать</button></form><?php endif; ?>
    </div>
</section>
<?php endif; ?>

<details class="panel" id="new-position">
    <summary class="panel__head"><span>Новая должность</span><small>Развернуть / свернуть</small></summary>
    <form class="form-grid" method="post" action="<?= url('/team/positions') ?>">
        <?= csrf_field() ?>
        <label><span>Название</span><input name="title" required></label><label><span>Грейд сотрудника</span><input name="grade"></label>
        <label><span>Целевой профиль Performance Review</span><select name="competency_position_index"><option value="">Не настроен</option><?php foreach ($competencyProfiles as $profile): ?><option value="<?= (int) $profile['index'] ?>"><?= e($profile['title']) ?> · <?= e($profile['grade']) ?></option><?php endforeach; ?></select></label>
        <label><span>Уровень полномочий</span><select name="base_role"><?php foreach ($baseRoles as $baseRole): ?><option value="<?= e($baseRole) ?>"><?= e(role_label($baseRole)) ?></option><?php endforeach; ?></select></label>
        <label><span>Порядок</span><input type="number" name="sort_order" value="100"></label>
        <label class="form-grid__full"><span>Описание</span><textarea name="description" rows="3"></textarea></label>
        <div class="form-grid__full"><p class="muted">Новая должность получает безопасные права выбранного уровня. После создания их можно уточнить.</p></div>
        <div class="form-grid__full form-actions"><button class="btn btn--red" type="submit">Создать должность</button></div>
    </form>
</details>
