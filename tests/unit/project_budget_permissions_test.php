<?php

declare(strict_types=1);

use App\Services\PermissionService;
use App\Services\RoleService;

test('рядовой участник проекта не видит статистику и бюджет проекта', function (): void {
    $user = ['id' => 10, 'role' => RoleService::ENGINEER];
    $project = ['gip_user_id' => 1, 'rp_user_id' => 2, 'status' => 'active'];

    assert_same(false, PermissionService::canViewProjectStats($user, $project));
    assert_same(false, PermissionService::canViewProjectFinance($user, $project));
});

test('руководитель отдела видит статистику проекта без финансовой вкладки', function (): void {
    $user = ['id' => 20, 'role' => RoleService::DEPARTMENT_HEAD];
    $project = ['gip_user_id' => 1, 'rp_user_id' => 2, 'status' => 'active'];

    assert_same(true, PermissionService::canViewProjectStats($user, $project));
    assert_same(false, PermissionService::canViewProjectFinance($user, $project));
});

test('ГИП и РП проекта видят бюджет и план затрат', function (): void {
    $project = ['gip_user_id' => 7, 'rp_user_id' => 8, 'status' => 'active'];

    assert_same(true, PermissionService::canViewProjectStats(['id' => 7, 'role' => RoleService::GIP], $project));
    assert_same(true, PermissionService::canViewProjectFinance(['id' => 7, 'role' => RoleService::GIP], $project));
    assert_same(true, PermissionService::canViewProjectStats(['id' => 8, 'role' => RoleService::ENGINEER], $project));
    assert_same(true, PermissionService::canViewProjectFinance(['id' => 8, 'role' => RoleService::ENGINEER], $project));
});
