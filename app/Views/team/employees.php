<?php require __DIR__ . '/_tabs.php'; ?>

<section class="panel">
    <div class="panel__head">
        <div>
            <h2>Сотрудники</h2>
            <p class="muted">Видимость списка определяется оргструктурой. Техническая учётка здесь не показывается.</p>
        </div>
        <?php if ($canManageUsers): ?>
            <a class="btn btn--red" href="<?= url('/team/employees/new') ?>">+ Сотрудник</a>
        <?php endif; ?>
        <div class="filterbar team-filterbar">
            <label><span>Поиск</span><input type="search" placeholder="ФИО, почта, должность" data-team-search></label>
            <label><span>Отдел</span><select data-team-department><option value="">Все отделы</option><?php
                $filterDepartments = [];
                foreach ($employees as $employee) {
                    $code = trim((string) ($employee['department'] ?? ''));
                    if ($code !== '') { $filterDepartments[$code] = true; }
                }
                foreach (array_keys($filterDepartments) as $code): ?><option value="<?= e(mb_strtolower($code, 'UTF-8')) ?>"><?= e($code) ?></option><?php endforeach; ?></select></label>
            <label><span>Статус</span><select data-team-status><option value="">Все</option><option value="active">Работает</option><option value="inactive">Уволен</option></select></label>
        </div>
    </div>

    <div class="table-wrapper team-table-wrapper search-results" aria-live="polite">
        <table class="data-table team-table" data-no-column-filters>
            <thead><tr><th>Сотрудник</th><th>Должность</th><th>Подразделение</th><th>Задачи</th><th>Статус</th><th><span class="sr-only">Действия</span></th></tr></thead>
            <tbody>
            <?php foreach ($employees as $employee):
                $search = mb_strtolower(trim(implode(' ', [
                    $employee['name'] ?? '', $employee['email'] ?? '', $employee['tab_number'] ?? '',
                    $employee['position_title'] ?? '', $employee['department'] ?? '', $employee['manager_name'] ?? '',
                ])), 'UTF-8');
                $active = (int) ($employee['is_active'] ?? 0) === 1;
            ?>
                <tr data-team-row data-search="<?= e($search) ?>" data-department="<?= e(mb_strtolower((string) ($employee['department'] ?? ''), 'UTF-8')) ?>" data-status="<?= $active ? 'active' : 'inactive' ?>">
                    <td data-label="Сотрудник"><a class="team-person" href="<?= url('/profiles/' . (int) $employee['id']) ?>"><strong><?= e($employee['name']) ?></strong><small><?= e($employee['email']) ?> · <span class="mono"><?= e($employee['tab_number']) ?></span></small></a></td>
                    <td data-label="Должность">
                        <?php if ($canManageUsers): ?>
                            <label class="sr-only" for="employee-position-<?= (int) $employee['id'] ?>">Должность сотрудника <?= e($employee['name']) ?></label>
                            <select class="team-inline-select" id="employee-position-<?= (int) $employee['id'] ?>" name="position_id" form="employee-quick-<?= (int) $employee['id'] ?>" data-team-inline-control>
                                <?php foreach ($positions as $position): ?><option value="<?= (int) $position['id'] ?>"<?= selected((string) ($employee['position_id'] ?? ''), (string) $position['id']) ?>><?= e($position['title']) ?><?= !empty($position['grade']) ? ' · ' . e($position['grade']) : '' ?></option><?php endforeach; ?>
                            </select>
                        <?php else: ?><strong><?= e($employee['position_title'] ?: 'Не назначена') ?></strong><?php if (!empty($employee['position_grade'])): ?><small><?= e($employee['position_grade']) ?></small><?php endif; ?><?php endif; ?>
                    </td>
                    <td data-label="Структура">
                        <?php if ($canManageUsers): ?>
                            <div class="team-inline-stack">
                                <label><span class="sr-only">Отдел сотрудника <?= e($employee['name']) ?></span><select name="department" form="employee-quick-<?= (int) $employee['id'] ?>" data-team-inline-control data-team-inline-department><option value="">Без отдела</option><?php foreach ($departments as $department): ?><option value="<?= e($department['code']) ?>"<?= selected((string) ($employee['department'] ?? ''), (string) $department['code']) ?>><?= e($department['code']) ?> · <?= e($department['name']) ?></option><?php endforeach; ?></select></label>
                                <label><span class="sr-only">Группа сотрудника <?= e($employee['name']) ?></span><select name="group_id" form="employee-quick-<?= (int) $employee['id'] ?>" data-team-inline-control data-team-inline-group><option value="">Без группы</option><?php foreach ($groups as $group): ?><option value="<?= (int) $group['id'] ?>" data-department="<?= e($group['department_code']) ?>"<?= selected((string) ($employee['group_id'] ?? ''), (string) $group['id']) ?>><?= e($group['name']) ?></option><?php endforeach; ?></select></label>
                                <label><span class="sr-only">Руководитель сотрудника <?= e($employee['name']) ?></span><select name="manager_id" form="employee-quick-<?= (int) $employee['id'] ?>" data-team-inline-control><option value="">Без руководителя</option><?php foreach ($managers as $manager): if ((int) $manager['id'] === (int) $employee['id']) continue; ?><option value="<?= (int) $manager['id'] ?>"<?= selected((string) ($employee['manager_id'] ?? ''), (string) $manager['id']) ?>><?= e($manager['name']) ?><?= !empty($manager['department']) ? ' · ' . e($manager['department']) : '' ?></option><?php endforeach; ?></select></label>
                            </div>
                        <?php else: ?><?= e($employee['department_name'] ?: ($employee['department'] ?: '—')) ?><small><?= !empty($employee['manager_name']) ? 'Руководитель: ' . e($employee['manager_name']) : 'Руководитель не назначен' ?></small><?php endif; ?>
                    </td>
                    <td data-label="Задачи"><span class="team-task-count"><?= (int) $employee['open_tasks'] ?> открыто</span><?php if ((int) $employee['overdue_tasks'] > 0): ?><small class="text-danger"><?= (int) $employee['overdue_tasks'] ?> просрочено</small><?php endif; ?></td>
                    <td data-label="Статус"><?php if ($canManageUsers): ?><label class="sr-only" for="employee-active-<?= (int) $employee['id'] ?>">Статус сотрудника <?= e($employee['name']) ?></label><select id="employee-active-<?= (int) $employee['id'] ?>" name="is_active" form="employee-quick-<?= (int) $employee['id'] ?>" data-team-inline-control data-team-inline-active><option value="1"<?= selected((string) (int) $active, '1') ?>>Работает</option><option value="0"<?= selected((string) (int) $active, '0') ?>>Уволен</option></select><?php else: ?><span class="status-badge <?= $active ? 'status-badge--ok' : 'status-badge--muted' ?>"><?= $active ? 'Работает' : 'Уволен' ?></span><?php endif; ?><?php if (!empty($employee['vacation_date_from'])): ?><small class="team-vacation-status">Отпуск <?= e(format_date($employee['vacation_date_from'])) ?>–<?= e(format_date($employee['vacation_date_to'])) ?><br>замена: <?= e($employee['vacation_substitute_name']) ?></small><?php endif; ?></td>
                    <td class="team-row-actions"><?php if ($canManageUsers): ?><form id="employee-quick-<?= (int) $employee['id'] ?>" method="post" action="<?= url('/team/employees/' . (int) $employee['id'] . '/quick-update') ?>" data-team-inline-form data-original-active="<?= $active ? '1' : '0' ?>"><?= csrf_field() ?></form><div class="team-inline-actions"><button class="btn btn-sm btn--red" type="submit" form="employee-quick-<?= (int) $employee['id'] ?>" data-team-inline-save disabled>Сохранить</button><a class="btn btn-sm btn-outline" href="<?= url('/team/employees/' . (int) $employee['id'] . '/edit') ?>">Карточка</a></div><?php else: ?><a class="btn btn-sm btn-outline" href="<?= url('/profiles/' . (int) $employee['id']) ?>">Профиль</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
                <tr class="is-hidden" data-team-empty><td colspan="6">По этому поиску сотрудников не найдено.</td></tr>
            </tbody>
        </table>
    </div>
</section>
