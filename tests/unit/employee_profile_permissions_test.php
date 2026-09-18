<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\PermissionService;

test('Профили сотрудников соблюдают личный, руководительский и глобальный scope', function (): void {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        role TEXT,
        department TEXT,
        manager_id INTEGER,
        is_active INTEGER NOT NULL DEFAULT 1
    )');
    $pdo->exec("INSERT INTO users (id, role, department, manager_id, is_active) VALUES
        (1, 'director', 'ДПР', NULL, 1),
        (10, 'group_lead', 'ОВ', 1, 1),
        (11, 'chief_specialist', 'ОВ', 10, 1),
        (12, 'engineer', 'ОВ', 11, 1),
        (13, 'engineer', 'ОВ', NULL, 1),
        (20, 'department_head', 'ВК', 1, 1),
        (21, 'engineer', 'ВК', 20, 1),
        (22, 'engineer', 'СС', 20, 1),
        (23, 'engineer', 'ВК', 20, 0),
        (30, 'department_head', '', 1, 1),
        (31, 'engineer', '', NULL, 1),
        (40, 'bim_manager', 'ТИМ', 1, 1)");
    Database::useConnection($pdo);

    $engineer = ['id' => 12, 'role' => 'engineer', 'department' => 'ОВ'];
    assert_true(PermissionService::canViewEmployeeProfile($engineer, 12), 'сотрудник должен видеть себя');
    assert_same(false, PermissionService::canViewEmployeeProfile($engineer, 11), 'сотрудник не должен видеть руководителя через профильную аналитику');
    assert_same(false, PermissionService::canBrowseEmployeeProfiles($engineer));

    $groupLead = ['id' => 10, 'role' => 'group_lead', 'department' => 'ОВ'];
    assert_true(PermissionService::canViewEmployeeProfile($groupLead, 11), 'руководитель должен видеть прямого подчинённого');
    assert_true(PermissionService::canViewEmployeeProfile($groupLead, 12), 'руководитель должен видеть нижнюю ступень своей оргветки');
    assert_same(false, PermissionService::canViewEmployeeProfile($groupLead, 13), 'совпадение отдела без подчинения недостаточно для руководителя группы');
    assert_true(PermissionService::canBrowseEmployeeProfiles($groupLead));

    $departmentHead = ['id' => 20, 'role' => 'department_head', 'department' => 'ВК'];
    assert_true(PermissionService::canViewEmployeeProfile($departmentHead, 21), 'руководитель отдела должен видеть сотрудника своего отдела');
    assert_same(false, PermissionService::canViewEmployeeProfile($departmentHead, 22), 'сотрудник другого отдела не входит в профильный scope');
    assert_same(false, PermissionService::canViewEmployeeProfile($departmentHead, 23), 'профили неактивных сотрудников не открываются');

    $headWithoutDepartment = ['id' => 30, 'role' => 'department_head', 'department' => ''];
    assert_true(PermissionService::canViewEmployeeProfile($headWithoutDepartment, 30), 'руководитель без отдела должен видеть себя');
    assert_same(false, PermissionService::canViewEmployeeProfile($headWithoutDepartment, 31), 'пустой отдел не должен открывать всех без отдела');

    $bimManager = ['id' => 40, 'role' => 'bim_manager', 'department' => 'ТИМ'];
    assert_true(PermissionService::canViewEmployeeProfile($bimManager, 40), 'BIM-менеджер должен видеть себя');
    assert_same(false, PermissionService::canViewEmployeeProfile($bimManager, 13), 'право на все проекты не должно открывать все профили');

    $director = ['id' => 1, 'role' => 'director', 'department' => 'ДПР'];
    assert_true(PermissionService::canViewEmployeeProfile($director, 13), 'директор должен видеть всех активных сотрудников');
    assert_same(false, PermissionService::canViewEmployeeProfile($director, 23), 'неактивный профиль закрыт и для директора');

    Database::reset();
});
