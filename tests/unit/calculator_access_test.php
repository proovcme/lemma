<?php

declare(strict_types=1);

use App\Controllers\CalculatorController;
use App\Services\RoleService;

test('CalculatorController разрешает только директорские роли и администратора', function (): void {
    foreach ([
        RoleService::ADMIN,
        RoleService::DIRECTOR,
        RoleService::ADJACENT_DIRECTOR,
        RoleService::DEPUTY_DIRECTOR,
    ] as $role) {
        assert_true(CalculatorController::canAccessRole($role), 'Ожидался доступ роли ' . $role);
    }
});

test('CalculatorController закрыт для остальных ролей', function (): void {
    foreach ([
        RoleService::ENGINEER,
        RoleService::CHIEF_SPECIALIST,
        RoleService::DEPARTMENT_HEAD,
        RoleService::GIP,
        RoleService::PROJECT_MANAGER,
        RoleService::HR,
        null,
    ] as $role) {
        assert_true(!CalculatorController::canAccessRole($role), 'Не ожидался доступ роли ' . (string) $role);
    }
});

test('Внешний калькулятор не включён в самостоятельную Лемму', function (): void {
    assert_true(!is_file((string) config('app.calculator_bundle_path') . '/index.html'));
});
