<?php

declare(strict_types=1);

use App\Services\PermissionService;
use App\Services\RoleService;

test('demo-режим скрывает HR-навигацию даже для директора', function (): void {
    $user = ['id' => 1, 'role' => RoleService::DIRECTOR];
    $canOpenHrInDemo = false && PermissionService::canOpenHr($user);

    assert_same(false, $canOpenHrInDemo);
});

test('публичное демо закрывает внутренние и служебные маршруты', function (): void {
    $router = file_get_contents(__DIR__ . '/../../app/Core/Router.php');
    $publicRouter = file_get_contents(__DIR__ . '/../../public/router.php');
    $layout = file_get_contents(__DIR__ . '/../../app/Views/layouts/app.php');

    assert_true(is_string($router));
    foreach (['/locia', '/team', '/manual', '/admin', '/hr', '/settings', '/performance-review', '/competencies', '/payroll', '/motivation'] as $path) {
        assert_true(str_contains($router, $path), "demo router must hide {$path}");
    }

    assert_true(is_string($publicRouter) && str_contains($publicRouter, '/locia-atlas'), 'demo static router must hide bundled viewer files');
    assert_true(is_string($layout) && str_contains($layout, '!app_is_demo_mode() && ($canBrowseProfiles || $canManageUsers || $canOpenHr)'), 'layout must hide Team and HR nav in demo');
    assert_true(str_contains($layout, '!app_is_demo_mode() && ($canManageUsers || $canManageSettings)'), 'layout must hide admin nav in demo');
});

test('инженер не получает глобальную кнопку создания задачи в рабочей шапке', function (): void {
    $user = ['id' => 2, 'role' => RoleService::ENGINEER];
    $canCreateGlobalTask = !RoleService::isAny($user['role'], [RoleService::ENGINEER])
        && PermissionService::canCreateTasks($user);

    assert_same(false, $canCreateGlobalTask);
});

test('инженерская навигация ограничена задачами и временем', function (): void {
    $engineer = ['id' => 2, 'role' => RoleService::ENGINEER];
    $chief = ['id' => 3, 'role' => RoleService::CHIEF_SPECIALIST];

    assert_same(false, PermissionService::canCreateTasks($engineer));
    assert_same(false, PermissionService::canOpenProjects($engineer));
    assert_same(false, PermissionService::canOpenDpr($engineer));
    assert_same(false, PermissionService::canOpenReports($engineer));
    assert_same(true, PermissionService::canCreateTasks($chief));
});

test('Мой день и Заметки видны всем, а профиль открывается по карточке пользователя', function (): void {
    $layout = file_get_contents(__DIR__ . '/../../app/Views/layouts/app.php');

    assert_true(is_string($layout) && str_contains($layout, "href=\"<?= url('/my-day') ?>\""), 'layout must keep My day in the main nav');
    assert_true(is_string($layout) && str_contains($layout, 'aria-label="Открыть мой профиль"'), 'layout must keep profile on the user card');
    assert_same(false, str_contains($layout, '>Мой профиль<'), 'layout must not duplicate profile in the main nav');
    assert_true(is_string($layout) && str_contains($layout, "href=\"<?= url('/notes') ?>\""), 'layout must keep Notes in the main nav');
    assert_same(false, str_contains($layout, '<?php if (!$isEngineer): ?>'), 'engineer guard must not hide personal nav items');
});

test('ГИП и РП видят свои проекты, но не получают Все проекты по роли', function (): void {
    $gip = ['id' => 3, 'role' => RoleService::GIP];
    $rp = ['id' => 4, 'role' => RoleService::PROJECT_MANAGER];
    $director = ['id' => 5, 'role' => RoleService::DIRECTOR];

    assert_same(false, PermissionService::canSeeAllProjects($gip));
    assert_same(false, PermissionService::canSeeAllProjects($rp));
    assert_same(true, PermissionService::canSeeAllProjects($director));
});

test('управленческие действия Штурмана не предназначены инженеру', function (): void {
    $engineer = ['id' => 2, 'role' => RoleService::ENGINEER];
    $head = ['id' => 3, 'role' => RoleService::DEPARTMENT_HEAD];

    assert_same(false, !RoleService::isAny($engineer['role'], [RoleService::ENGINEER]));
    assert_same(true, !RoleService::isAny($head['role'], [RoleService::ENGINEER]));
});
