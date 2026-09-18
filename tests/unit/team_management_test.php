<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\PositionService;
use App\Services\RoleService;

test('должность объединяет название, уровень поведения и собственные доступы', function (): void {
    Database::reset();
    RoleService::resetCache();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE TABLE positions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        role_key TEXT UNIQUE,
        base_role TEXT NOT NULL DEFAULT 'engineer',
        title TEXT UNIQUE,
        grade TEXT,
        description TEXT,
        sort_order INTEGER DEFAULT 100,
        is_system INTEGER DEFAULT 0,
        is_protected INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, position_id INTEGER, role TEXT, is_active INTEGER DEFAULT 1)");
    $pdo->exec("CREATE TABLE position_access_permissions (position_id INTEGER, capability TEXT, enabled INTEGER, updated_by INTEGER, updated_at TEXT, PRIMARY KEY(position_id, capability))");
    $pdo->exec("CREATE TABLE role_access_permissions (role TEXT, capability TEXT, enabled INTEGER, PRIMARY KEY(role, capability))");
    $pdo->exec("INSERT INTO positions (role_key, base_role, title, is_active) VALUES ('senior_ov', 'engineer', 'Ведущий инженер ОВ', 1)");
    $positionId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO position_access_permissions (position_id, capability, enabled) VALUES ({$positionId}, 'locia', 1), ({$positionId}, 'projects', 1)");
    Database::useConnection($pdo);
    RoleService::resetCache();

    assert_same(true, RoleService::exists('senior_ov'));
    assert_same('Инженер', RoleService::label(RoleService::ENGINEER));
    assert_same('Ведущий инженер ОВ', RoleService::label('senior_ov'));
    assert_same(RoleService::ENGINEER, RoleService::normalize('senior_ov'));
    assert_same(true, RoleService::has('senior_ov', RoleService::CAP_PROJECTS));
    assert_same(false, RoleService::has('senior_ov', RoleService::CAP_SETTINGS));

    Database::reset();
    RoleService::resetCache();
});

test('защищённая должность директора всегда сохраняет полный доступ', function (): void {
    Database::reset();
    RoleService::resetCache();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE TABLE positions (id INTEGER PRIMARY KEY, role_key TEXT, base_role TEXT, title TEXT, is_active INTEGER)");
    $pdo->exec("CREATE TABLE position_access_permissions (position_id INTEGER, capability TEXT, enabled INTEGER)");
    $pdo->exec("CREATE TABLE role_access_permissions (role TEXT, capability TEXT, enabled INTEGER)");
    $pdo->exec("INSERT INTO positions VALUES (1, 'director', 'director', 'Директор департамента', 1)");
    $pdo->exec("INSERT INTO position_access_permissions VALUES (1, 'settings', 0)");
    Database::useConnection($pdo);
    RoleService::resetCache();

    assert_same(true, RoleService::has(RoleService::DIRECTOR, RoleService::CAP_SETTINGS));
    assert_same(true, RoleService::has(RoleService::DIRECTOR, RoleService::CAP_BIM));
    assert_same(true, RoleService::has(RoleService::DIRECTOR, RoleService::CAP_COMPETENCY));

    Database::reset();
    RoleService::resetCache();
});

test('новая навигация не возвращает отдельные Мой профиль, Мои задачи и Гант', function (): void {
    $layout = file_get_contents(BASE_PATH . '/app/Views/layouts/app.php');
    assert_same(true, str_contains($layout, '>База знаний<'));
    assert_same(true, str_contains($layout, '>Работа<'));
    assert_same(true, str_contains($layout, '>ТИМ<'));
    assert_same(true, str_contains($layout, '>Команда<'));
    assert_same(true, str_contains($layout, '>Управление<'));
    assert_same(true, str_contains($layout, '>Дашборд<'));
    assert_same(false, str_contains($layout, '>Мой профиль<'));
    assert_same(false, str_contains($layout, '>Мои задачи<'));
    assert_same(false, str_contains($layout, '>Гант<'));
    assert_same(false, str_contains($layout, '>Отделы и структура<'));

    $tabs = file_get_contents(BASE_PATH . '/app/Views/team/_tabs.php');
    assert_same(true, str_contains($tabs, '>Отделы и группы<'));
    assert_same(false, str_contains($tabs, '>Ставки<'));
    assert_same(false, str_contains($tabs, '>Штатное расписание<'));
    assert_same(true, str_contains($layout, '>Директор<'));
    assert_same(true, str_contains($layout, '>Бюджет<'));
});

test('таблица сотрудников поддерживает безопасное быстрое редактирование', function (): void {
    $routes = file_get_contents(BASE_PATH . '/public/index.php');
    $view = file_get_contents(BASE_PATH . '/app/Views/team/employees.php');
    $controller = file_get_contents(BASE_PATH . '/app/Controllers/TeamController.php');
    assert_same(true, str_contains($routes, "'/team/employees/{id}/quick-update'"));
    assert_same(true, str_contains($view, 'data-team-inline-form'));
    assert_same(true, str_contains($view, 'data-team-inline-department'));
    assert_same(true, str_contains($view, 'data-team-inline-group'));
    assert_same(true, str_contains($controller, 'team_employee_quick_updated'));
    assert_same(true, str_contains($controller, 'Нельзя уволить самого себя'));
});

test('штатное расписание вынесено в блок директора и не раскрывает ставки администратору', function (): void {
    $routes = file_get_contents(BASE_PATH . '/public/index.php');
    $tabs = file_get_contents(BASE_PATH . '/app/Views/team/_tabs.php');
    $staffing = file_get_contents(BASE_PATH . '/app/Views/team/staffing.php');
    $layout = file_get_contents(BASE_PATH . '/app/Views/layouts/app.php');
    $migration = file_get_contents(BASE_PATH . '/database/migrations/079_staffing_schedule.sql');

    assert_same(true, str_contains($routes, "'/director/staffing'"));
    assert_same(true, str_contains($routes, "'/director/staffing/periods/{id}/export'"));
    assert_same(true, str_contains($routes, "'/director/staffing/periods/{id}/print'"));
    assert_same(false, str_contains($tabs, '>Штатное расписание<'));
    assert_same(true, str_contains($staffing, 'Полный бюджет'));
    assert_same(true, str_contains($staffing, 'Создать корректировку'));
    assert_same(true, str_contains($staffing, 'Сотрудник назначен'));
    assert_same(false, str_contains($staffing, "'occupied' => 'Занято'"));
    assert_same(false, str_contains($staffing, '<th>Статус</th>'));
    assert_same(false, str_contains($staffing, "e(\$item['status'])"));
    assert_same(false, str_contains($layout, '>Отделы и структура<'));
    assert_same(true, str_contains($migration, 'revision INT UNSIGNED'));
    assert_same(true, str_contains($migration, 'staffing_personal_rates'));
    assert_same(true, str_contains($migration, 'staffing_group_rates'));
});

test('кумулятивные миграции сначала расширяют роли и нормализуют старые стадии', function (): void {
    $teamMigration = file_get_contents(BASE_PATH . '/database/migrations/078_team_management.sql');
    $roleWiden = strpos($teamMigration, 'MODIFY COLUMN role VARCHAR(100)');
    $customRoleWrite = strpos($teamMigration, 'UPDATE users u');
    assert_true($roleWiden !== false, 'старая роль ENUM должна расширяться в migration 078');
    assert_true($customRoleWrite !== false && $roleWiden < $customRoleWrite, 'расширение роли должно предшествовать записи пользовательских ключей');

    $financeMigration = file_get_contents(BASE_PATH . '/database/migrations/083_project_finance_online_stages.sql');
    $normalize = strpos($financeMigration, "stage NOT IN ('ПД', 'РД')");
    $newEnum = strpos($financeMigration, "ENUM('ПД','РД','ПД-РД','АН')");
    assert_true($normalize !== false, 'старые стадии должны нормализоваться');
    assert_true($newEnum !== false && $normalize < $newEnum, 'нормализация должна предшествовать сужению ENUM');
});

test('факт проектов использует месячный снимок персональной ставки с резервом legacy', function (): void {
    $projectControl = file_get_contents(BASE_PATH . '/app/Services/ProjectControlService.php');
    $projectController = file_get_contents(BASE_PATH . '/app/Controllers/ProjectController.php');
    $reports = file_get_contents(BASE_PATH . '/app/Controllers/ReportController.php');
    $planning = file_get_contents(BASE_PATH . '/app/Controllers/CostEstimateController.php');

    foreach ([$projectControl, $projectController, $reports] as $source) {
        assert_same(true, str_contains($source, 'staffing_personal_rates'));
        assert_same(true, str_contains($source, 'COALESCE(spr.hourly_rate, er.hourly_rate, sgr.hourly_rate, cfo.hourly_rate, 0)'));
    }
    assert_same(true, str_contains($planning, 'staffing_personal_rates'));
    assert_same(true, str_contains($planning, 'COALESCE(staff_personal.hourly_rate, rate.hourly_rate, staff_group.hourly_rate, cfo.hourly_rate, 0)'));
});
