<?php
$teamUser = current_user();
$teamCanManageSettings = $teamUser ? \App\Services\PermissionService::canManageSettings($teamUser) : false;
$teamCanManageUsers = $teamUser ? \App\Services\PermissionService::canManageUsers($teamUser) : false;
$teamTab = $teamTab ?? (request_path() === '/team/structure' ? 'structure' : 'employees');
?>
<nav class="tabs team-tabs" aria-label="Разделы управления командой">
    <a class="<?= $teamTab === 'employees' ? 'is-active' : '' ?>" href="<?= url('/team') ?>">Сотрудники</a>
    <a class="<?= $teamTab === 'structure' ? 'is-active' : '' ?>" href="<?= url('/team/structure') ?>">Оргструктура</a>
    <?php if ($teamCanManageSettings): ?>
        <a class="<?= $teamTab === 'departments' ? 'is-active' : '' ?>" href="<?= url('/team/departments') ?>">Отделы и группы</a>
    <?php endif; ?>
    <?php if ($teamCanManageUsers): ?>
        <a class="<?= $teamTab === 'sections' ? 'is-active' : '' ?>" href="<?= url('/team/sections') ?>">Разделы</a>
    <?php endif; ?>
    <?php if ($teamCanManageSettings): ?>
        <a class="<?= $teamTab === 'positions' ? 'is-active' : '' ?>" href="<?= url('/team/positions') ?>">Должности и доступы</a>
    <?php endif; ?>
</nav>
