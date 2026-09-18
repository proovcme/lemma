<?php
$hasSvg = (bool) ($hasSvg ?? false);
$tree = $tree ?? [];
$users = $users ?? [];
$departments = $departments ?? [];
$departmentCounts = $departmentCounts ?? [];
$groups = $groups ?? [];
$groupCounts = $groupCounts ?? [];
$positions = $positions ?? [];
$managers = $managers ?? $users;
$usersMap = $usersMap ?? [];
$profileVisibleIds = $profileVisibleIds ?? [];
$orgStats = $orgStats ?? ['total' => count($users), 'with_manager' => 0, 'without_manager' => count($users), 'manager_count' => 0];
$canManageUsers = (bool) ($canManageUsers ?? false);
$isFlatStructure = (int) ($orgStats['total'] ?? 0) > 1 && (int) ($orgStats['with_manager'] ?? 0) === 0;
$hasManyUnassigned = (int) ($orgStats['total'] ?? 0) > 1 && (int) ($orgStats['without_manager'] ?? 0) > 1;
$isTeamStructure = request_path() === '/team/structure';
$manageStructureInline = $canManageUsers && !$isTeamStructure;
$structureBase = $isTeamStructure ? '/team/structure' : '/admin/org-structure';
$returnToDepartments = $structureBase . '#org-departments';
$returnToGroups = $structureBase . '#org-groups';
$returnToPeople = $structureBase . '#org-people';
$departmentRoute = $isTeamStructure ? '/team/departments' : '/admin/departments';
$groupRoute = $isTeamStructure ? '/team/department-groups' : '/admin/department-groups';
$renderNode = static function (array $node) use (&$renderNode, $profileVisibleIds): void {
    $children = $node['children'] ?? [];
    $hasChildren = is_array($children) && count($children) > 0;
    $nodeId = (int) ($node['id'] ?? 0);
    $canOpenProfile = isset($profileVisibleIds[$nodeId]);
    ?>
    <li class="org-node" data-org-node data-org-search="<?= e(mb_strtolower(trim((string) ($node['name'] ?? '') . ' ' . (string) ($node['department'] ?? '') . ' ' . (string) ($node['group_name'] ?? '') . ' ' . (string) ($node['position_title'] ?? '') . ' ' . (string) ($node['position_grade'] ?? '')), 'UTF-8')) ?>">
        <?php if ($hasChildren): ?>
        <details open>
            <summary>
                <span class="avatar avatar--small"><?= e(initials($node['name'] ?? '')) ?></span>
                <span class="org-node__main">
                    <strong><?= e($node['name'] ?? '') ?></strong>
                    <small>
                        <?= e(($node['position_title'] ?? '') !== '' ? $node['position_title'] : role_label($node['role'] ?? '')) ?>
                        <?= ($node['position_grade'] ?? '') !== '' ? ' · ' . e($node['position_grade']) : '' ?>
                        <?= ($node['department'] ?? '') !== '' ? ' · ' . e($node['department']) : '' ?>
                        <?= ($node['group_name'] ?? '') !== '' ? ' · ' . e($node['group_name']) : '' ?>
                    </small>
                </span>
                <span class="status-pill"><?= count($children) ?></span>
            </summary>
            <?php if ($canOpenProfile): ?><a class="org-node__profile-link" href="<?= url('/profiles/' . $nodeId) ?>">Открыть профиль</a><?php endif; ?>
            <ul class="org-tree">
                <?php foreach ($children as $child): $renderNode($child); endforeach; ?>
            </ul>
        </details>
        <?php else: ?>
        <div class="org-node__leaf">
            <span class="avatar avatar--small"><?= e(initials($node['name'] ?? '')) ?></span>
            <span class="org-node__main">
                <strong>
                    <?php if ($canOpenProfile): ?>
                        <a href="<?= url('/profiles/' . $nodeId) ?>"><?= e($node['name'] ?? '') ?></a>
                    <?php else: ?>
                        <?= e($node['name'] ?? '') ?>
                    <?php endif; ?>
                </strong>
                <small>
                    <?= e(($node['position_title'] ?? '') !== '' ? $node['position_title'] : role_label($node['role'] ?? '')) ?>
                    <?= ($node['position_grade'] ?? '') !== '' ? ' · ' . e($node['position_grade']) : '' ?>
                    <?= ($node['department'] ?? '') !== '' ? ' · ' . e($node['department']) : '' ?>
                    <?= ($node['group_name'] ?? '') !== '' ? ' · ' . e($node['group_name']) : '' ?>
                </small>
            </span>
        </div>
        <?php endif; ?>
    </li>
    <?php
};
?>

<?php if ($isTeamStructure): $teamTab = 'structure'; require BASE_PATH . '/app/Views/team/_tabs.php'; endif; ?>

<section class="analytics-module">
    <div class="analytics-head">
        <div>
            <span class="muted">Организация</span>
            <h2>Структура организации</h2>
        </div>
        <div class="toolbar__actions">
            <?php if ($canManageUsers): ?>
                <?php if ($isTeamStructure): ?>
                    <a class="btn btn-outline" href="<?= url('/team/departments') ?>">Отделы и группы</a>
                    <a class="btn btn-outline" href="<?= url('/team/employees') ?>">Карточки сотрудников</a>
                <?php else: ?>
                    <a class="btn btn-outline" href="#org-departments">Отделы</a>
                    <a class="btn btn-outline" href="#org-groups">Группы</a>
                    <a class="btn btn-outline" href="#org-people">Распределить людей</a>
                    <a class="btn btn-outline" href="<?= url('/admin/users') ?>">Пользователи</a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($hasSvg): ?>
                <a class="btn btn-outline" href="<?= url('/assets/org-structure.svg') ?>" target="_blank" rel="noopener">Открыть в полном размере</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="org-status-grid">
    <div class="org-status-card">
        <span>Сотрудники</span>
        <strong><?= (int) ($orgStats['total'] ?? 0) ?></strong>
    </div>
    <div class="org-status-card">
        <span>С руководителем</span>
        <strong><?= (int) ($orgStats['with_manager'] ?? 0) ?></strong>
    </div>
    <div class="org-status-card<?= $hasManyUnassigned ? ' org-status-card--warn' : '' ?>">
        <span>Без руководителя</span>
        <strong><?= (int) ($orgStats['without_manager'] ?? 0) ?></strong>
    </div>
    <div class="org-status-card">
        <span>Руководители</span>
        <strong><?= (int) ($orgStats['manager_count'] ?? 0) ?></strong>
    </div>
</section>

<?php if ($isFlatStructure): ?>
    <section class="panel org-setup-notice">
        <div>
            <h2>Структура ещё не собрана</h2>
            <p>Сейчас у активных сотрудников не назначены руководители, поэтому система показывает плоский список фамилий. Дерево появится после заполнения поля «Руководитель» у сотрудников.</p>
        </div>
        <ol>
            <li>Откройте блок «Распределение сотрудников» ниже.</li>
            <li>Выберите отдел, должность и руководителя для сотрудника.</li>
            <li>Оставьте без руководителя только верхний уровень: директора или владельца структуры.</li>
        </ol>
        <?php if ($canManageUsers): ?>
            <a class="btn btn-red" href="<?= url($isTeamStructure ? '/team/employees' : '/admin/org-structure#org-people') ?>">Перейти к настройке</a>
        <?php else: ?>
            <p class="muted">Настраивать руководителей может пользователь с правом «Пользователи».</p>
        <?php endif; ?>
    </section>
<?php elseif ($hasManyUnassigned): ?>
    <section class="panel org-setup-notice org-setup-notice--compact">
        <p><strong>Проверьте верхний уровень.</strong> Без руководителя сейчас <?= (int) ($orgStats['without_manager'] ?? 0) ?> сотрудников. Обычно без руководителя остается только директор или несколько равноправных руководителей верхнего уровня.</p>
        <?php if ($canManageUsers): ?><a class="btn btn-outline" href="<?= url($isTeamStructure ? '/team/employees' : '/admin/users') ?>">Донастроить</a><?php endif; ?>
    </section>
<?php endif; ?>

<section class="panel org-toolbar" data-org-structure>
    <div class="panel__head">
        <h2>Сотрудники</h2>
        <span><?= count($users) ?></span>
    </div>
    <div class="admin-user-filterbar">
        <label>
            <span>Поиск</span>
            <input type="search" data-org-search-input placeholder="ФИО, табельный, должность, отдел, группа">
        </label>
        <button class="btn btn-outline" type="button" data-org-expand>Раскрыть</button>
        <button class="btn btn-outline" type="button" data-org-collapse>Свернуть</button>
    </div>
</section>

<section class="org-layout<?= $manageStructureInline ? '' : ' org-layout--single' ?>">
    <div class="panel org-layout__main">
        <div class="panel__head">
            <h2>Подчинённость</h2>
            <span class="muted">по полю руководитель</span>
        </div>
        <?php if ($tree): ?>
            <ul class="org-tree org-tree--root">
                <?php foreach ($tree as $node): $renderNode($node); endforeach; ?>
            </ul>
            <div class="empty-state empty-state--compact" data-org-empty hidden>
                <strong>Сотрудники не найдены</strong>
                <p>Измените поиск по ФИО, табельному, должности, отделу или группе.</p>
            </div>
        <?php else: ?>
            <div class="empty">Активные сотрудники не найдены.</div>
        <?php endif; ?>
    </div>

    <?php if ($manageStructureInline): ?>
        <details class="panel org-layout__side" id="org-departments">
            <summary class="org-layout__summary">
                <span>Отделы и группы</span>
                <strong><?= count($departments) ?> / <?= count($groups) ?></strong>
            </summary>
            <div class="panel__head">
                <h2>Отделы</h2>
                <span><?= count($departments) ?></span>
            </div>
            <form class="org-department-create" method="post" action="<?= url($departmentRoute) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="return_to" value="<?= e($returnToDepartments) ?>">
                <label>
                    <span>Код</span>
                    <input name="code" pattern="[a-zA-Z0-9_А-Яа-яЁё\-]+" placeholder="ОВ" required>
                </label>
                <label>
                    <span>Название</span>
                    <input name="name" placeholder="Отдел вентиляции" required>
                </label>
                <label>
                    <span>Начальник</span>
                    <select name="head_user_id">
                        <option value="">Не назначен</option>
                        <?php foreach ($managers as $manager): ?>
                            <option value="<?= (int) $manager['id'] ?>"><?= e($manager['name']) ?><?= ($manager['department'] ?? '') !== '' ? ' · ' . e($manager['department']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn btn-red" type="submit">Добавить отдел</button>
            </form>
            <div class="org-department-list">
                <?php foreach ($departments as $department): ?>
                    <?php
                    $departmentId = (int) ($department['id'] ?? 0);
                    $departmentCode = (string) ($department['code'] ?? '');
                    $departmentHeadId = (int) ($department['head_user_id'] ?? 0);
                    $count = (int) ($departmentCounts[$departmentCode] ?? 0);
                    $departmentFormId = 'department-' . $departmentId;
                    ?>
                    <div class="org-department-item">
                        <div class="org-department-item__code">
                            <strong><?= e($departmentCode) ?></strong>
                            <span class="status-pill"><?= $count ?></span>
                        </div>
                        <form id="<?= e($departmentFormId) ?>" method="post" action="<?= url($departmentRoute . '/' . $departmentId) ?>"></form>
                        <input type="hidden" form="<?= e($departmentFormId) ?>" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" form="<?= e($departmentFormId) ?>" name="return_to" value="<?= e($returnToDepartments) ?>">
                        <label>
                            <span>Название</span>
                            <input form="<?= e($departmentFormId) ?>" name="name" value="<?= e($department['name'] ?? '') ?>" required>
                        </label>
                        <label>
                            <span>Начальник</span>
                            <select form="<?= e($departmentFormId) ?>" name="head_user_id">
                                <option value="">Не назначен</option>
                                <?php foreach ($managers as $manager): ?>
                                    <option value="<?= (int) $manager['id'] ?>"<?= selected((string) $departmentHeadId, (string) $manager['id']) ?>>
                                        <?= e($manager['name']) ?><?= ($manager['department'] ?? '') !== '' ? ' · ' . e($manager['department']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="org-department-item__actions">
                            <button class="btn btn-sm" form="<?= e($departmentFormId) ?>" type="submit">Сохранить</button>
                            <form method="post" action="<?= url($departmentRoute . '/' . $departmentId . '/delete') ?>" onsubmit="return confirm(<?= e(json_encode('Удалить отдел ' . $departmentCode . '? Распределенные в нем пользователи останутся в системе, но их отдел будет сброшен.', JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)) ?>)">
                                <?= csrf_field() ?>
                                <input type="hidden" name="return_to" value="<?= e($returnToDepartments) ?>">
                                <button class="btn btn-sm btn-outline" type="submit">Удалить</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="panel__head org-side-subhead" id="org-groups">
                <h2>Группы</h2>
                <span><?= count($groups) ?></span>
            </div>
            <form class="org-department-create" method="post" action="<?= url($groupRoute) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="return_to" value="<?= e($returnToGroups) ?>">
                <label>
                    <span>Отдел</span>
                    <select name="department_code" required>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= e($department['code']) ?>"><?= e($department['code']) ?> · <?= e($department['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Название группы</span>
                    <input name="name" placeholder="Группа 1" required>
                </label>
                <label>
                    <span>Руководитель группы</span>
                    <select name="lead_user_id">
                        <option value="">Не назначен</option>
                        <?php foreach ($managers as $manager): ?>
                            <option value="<?= (int) $manager['id'] ?>"><?= e($manager['name']) ?><?= ($manager['department'] ?? '') !== '' ? ' · ' . e($manager['department']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Сортировка</span>
                    <input type="number" name="sort_order" value="100" min="0" step="10">
                </label>
                <button class="btn btn-red" type="submit">Добавить группу</button>
            </form>
            <div class="org-department-list">
                <?php foreach ($groups as $group): ?>
                    <?php
                    $groupId = (int) ($group['id'] ?? 0);
                    $groupFormId = 'department-group-' . $groupId;
                    $count = (int) ($groupCounts[$groupId] ?? 0);
                    $leadUserId = (int) ($group['lead_user_id'] ?? 0);
                    ?>
                    <div class="org-department-item org-department-item--group">
                        <div class="org-department-item__code">
                            <strong><?= e($group['department_code'] ?? '') ?></strong>
                            <span class="status-pill"><?= $count ?></span>
                        </div>
                        <form id="<?= e($groupFormId) ?>" method="post" action="<?= url($groupRoute . '/' . $groupId) ?>"></form>
                        <input type="hidden" form="<?= e($groupFormId) ?>" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" form="<?= e($groupFormId) ?>" name="return_to" value="<?= e($returnToGroups) ?>">
                        <label>
                            <span>Отдел</span>
                            <select form="<?= e($groupFormId) ?>" name="department_code">
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?= e($department['code']) ?>"<?= selected((string) ($group['department_code'] ?? ''), (string) $department['code']) ?>>
                                        <?= e($department['code']) ?> · <?= e($department['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Название</span>
                            <input form="<?= e($groupFormId) ?>" name="name" value="<?= e($group['name'] ?? '') ?>" required>
                        </label>
                        <label>
                            <span>Руководитель</span>
                            <select form="<?= e($groupFormId) ?>" name="lead_user_id">
                                <option value="">Не назначен</option>
                                <?php foreach ($managers as $manager): ?>
                                    <option value="<?= (int) $manager['id'] ?>"<?= selected((string) $leadUserId, (string) $manager['id']) ?>>
                                        <?= e($manager['name']) ?><?= ($manager['department'] ?? '') !== '' ? ' · ' . e($manager['department']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Сортировка</span>
                            <input form="<?= e($groupFormId) ?>" type="number" name="sort_order" value="<?= (int) ($group['sort_order'] ?? 100) ?>" min="0" step="10">
                        </label>
                        <div class="org-department-item__actions">
                            <button class="btn btn-sm" form="<?= e($groupFormId) ?>" type="submit">Сохранить</button>
                            <form method="post" action="<?= url($groupRoute . '/' . $groupId . '/delete') ?>" onsubmit="return confirm(<?= e(json_encode('Удалить группу ' . (string) ($group['name'] ?? '') . '? У сотрудников группа будет сброшена.', JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)) ?>)">
                                <?= csrf_field() ?>
                                <input type="hidden" name="return_to" value="<?= e($returnToGroups) ?>">
                                <button class="btn btn-sm btn-outline" type="submit">Удалить</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$groups): ?>
                    <div class="empty org-empty-message">Группы ещё не заведены.</div>
                <?php endif; ?>
            </div>
        </details>
    <?php endif; ?>
</section>

<?php if ($manageStructureInline): ?>
    <section class="panel org-people-panel" id="org-people">
        <div class="panel__head">
            <div>
                <h2>Распределение сотрудников</h2>
                <p class="muted">Один рабочий список для отдела, должности и прямого руководителя.</p>
            </div>
            <span><?= count($users) ?></span>
        </div>
        <div class="table-wrap org-people-table-wrap">
            <table class="data-table org-people-table">
                <thead>
                    <tr>
                        <th>Сотрудник</th>
                        <th>Отдел</th>
                        <th>Группа</th>
                        <th>Должность</th>
                        <th>Руководитель</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $person): ?>
                    <?php
                    $personId = (int) ($person['id'] ?? 0);
                    $placementFormId = 'org-placement-' . $personId;
                    $personSearch = mb_strtolower(trim((string) ($person['tab_number'] ?? '') . ' ' . (string) ($person['name'] ?? '') . ' ' . (string) ($person['email'] ?? '') . ' ' . (string) ($person['department'] ?? '') . ' ' . (string) ($person['department_name'] ?? '') . ' ' . (string) ($person['group_name'] ?? '') . ' ' . (string) ($person['position_title'] ?? '') . ' ' . (string) ($person['position_grade'] ?? '') . ' ' . (string) ($person['manager_name'] ?? '')), 'UTF-8');
                    ?>
                    <tr data-org-person-row data-org-search="<?= e($personSearch) ?>">
                        <td>
                            <form id="<?= e($placementFormId) ?>" method="post" action="<?= url('/admin/users/' . $personId . '/org-placement') ?>"></form>
                            <input type="hidden" form="<?= e($placementFormId) ?>" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" form="<?= e($placementFormId) ?>" name="return_to" value="<?= e($returnToPeople) ?>">
                            <strong><a href="<?= url('/profiles/' . $personId) ?>"><?= e($person['name'] ?? '') ?></a></strong>
                            <small><?= e(($person['tab_number'] ?? '') ?: 'без табельного') ?><?= ($person['email'] ?? '') !== '' ? ' · ' . e($person['email']) : '' ?></small>
                        </td>
                        <td>
                            <select form="<?= e($placementFormId) ?>" name="department" aria-label="Отдел для <?= e($person['name'] ?? 'сотрудника') ?>">
                                <option value="">Без отдела</option>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?= e($department['code']) ?>"<?= selected((string) ($person['department'] ?? ''), (string) $department['code']) ?>>
                                        <?= e($department['code']) ?> · <?= e($department['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select form="<?= e($placementFormId) ?>" name="group_id" aria-label="Группа для <?= e($person['name'] ?? 'сотрудника') ?>">
                                <option value="">Без группы</option>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?= (int) $group['id'] ?>"<?= selected((string) ($person['group_id'] ?? ''), (string) $group['id']) ?>>
                                        <?= e($group['department_code']) ?> · <?= e($group['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select form="<?= e($placementFormId) ?>" name="position_id" aria-label="Должность для <?= e($person['name'] ?? 'сотрудника') ?>">
                                <option value="">Без должности</option>
                                <?php foreach ($positions as $position): ?>
                                    <option value="<?= (int) $position['id'] ?>"<?= selected((string) ($person['position_id'] ?? ''), (string) $position['id']) ?>>
                                        <?= e($position['title']) ?><?= ($position['grade'] ?? '') !== '' ? ' · ' . e($position['grade']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select form="<?= e($placementFormId) ?>" name="manager_id" aria-label="Руководитель для <?= e($person['name'] ?? 'сотрудника') ?>">
                                <option value="">Верхний уровень</option>
                                <?php foreach ($managers as $manager): ?>
                                    <?php if ((int) $manager['id'] === $personId) continue; ?>
                                    <option value="<?= (int) $manager['id'] ?>"<?= selected((string) ($person['manager_id'] ?? ''), (string) $manager['id']) ?>>
                                        <?= e($manager['name']) ?><?= ($manager['department'] ?? '') !== '' ? ' · ' . e($manager['department']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="actions-cell">
                            <button class="btn btn-sm" form="<?= e($placementFormId) ?>" type="submit">Сохранить</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                    <tr data-org-people-empty hidden>
                        <td colspan="6">
                            <div class="empty-state empty-state--compact">
                                <strong>Сотрудники не найдены</strong>
                                <p>Измените поиск по оргструктуре.</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
