<?php

declare(strict_types=1);

use App\Services\PermissionService;
use App\Services\StaffingService;

test('бюджет департамента доступен директору, но не техническому администратору', function (): void {
    assert_same(true, PermissionService::canManageDepartmentBudget(['role' => 'director']));
    assert_same(false, PermissionService::canManageDepartmentBudget(['role' => 'admin']));
    assert_same(false, PermissionService::canManageDepartmentBudget(['role' => 'deputy_director']));
    assert_same(false, PermissionService::canManageEmployeeRates(['role' => 'admin']));
    assert_same(true, PermissionService::canManageEmployeeRates(['role' => 'director']));
});

test('общий аудит редактирует персональные ставки и суммы ФОТ', function (): void {
    $audit = file_get_contents(BASE_PATH . '/app/Services/AuditService.php');
    foreach (['hourly_rate', 'monthly_fot', 'change_amount', 'payroll_burden_pct', 'overhead_pct'] as $key) {
        assert_same(true, str_contains($audit, "'{$key}'"));
    }
});

test('фиксация штатного расписания рассчитывает ставки и блокирует изменения', function (): void {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, tab_number TEXT, role TEXT, department TEXT, group_id INTEGER, position_id INTEGER, is_active INTEGER)');
    $pdo->exec('CREATE TABLE departments (id INTEGER PRIMARY KEY, code TEXT UNIQUE, name TEXT)');
    $pdo->exec('CREATE TABLE department_groups (id INTEGER PRIMARY KEY, department_code TEXT, name TEXT)');
    $pdo->exec('CREATE TABLE positions (id INTEGER PRIMARY KEY, title TEXT)');
    $pdo->exec('CREATE TABLE employee_rates (user_id INTEGER PRIMARY KEY, hourly_rate REAL, updated_by INTEGER, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE cfo_rates (dept_code TEXT PRIMARY KEY, hourly_rate REAL, label TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("CREATE TABLE staffing_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, month_start TEXT, revision INTEGER DEFAULT 1, status TEXT DEFAULT 'draft', working_days REAL, working_hours REAL, payroll_burden_pct REAL DEFAULT 0, overhead_pct REAL DEFAULT 0, note TEXT, created_by INTEGER, locked_by INTEGER, locked_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(month_start,revision))");
    $pdo->exec("CREATE TABLE staffing_plan_rows (id INTEGER PRIMARY KEY AUTOINCREMENT, period_id INTEGER, department_code TEXT, department_name TEXT, group_id INTEGER, group_name TEXT, position_id INTEGER, position_title TEXT, user_id INTEGER, employee_name TEXT, tab_number TEXT, fte REAL DEFAULT 1, monthly_fot REAL, status TEXT, change_type TEXT DEFAULT 'none', change_amount REAL, comment TEXT, sort_order INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(period_id,user_id))");
    $pdo->exec('CREATE TABLE staffing_personal_rates (period_id INTEGER, user_id INTEGER, hourly_rate REAL, PRIMARY KEY(period_id,user_id))');
    $pdo->exec('CREATE TABLE staffing_group_rates (period_id INTEGER, department_code TEXT, hourly_rate REAL, total_fte REAL, total_fot REAL, PRIMARY KEY(period_id,department_code))');
    $pdo->exec("INSERT INTO users VALUES (1,'Технический администратор','D-1','admin',NULL,NULL,NULL,1),(2,'Иванов Иван','E-2','engineer','ОВ',1,1,1)");
    $pdo->exec("INSERT INTO departments VALUES (1,'ОВ','Отопление и вентиляция')");
    $pdo->exec("INSERT INTO department_groups VALUES (1,'ОВ','Группа 1')");
    $pdo->exec("INSERT INTO positions VALUES (1,'Инженер')");

    $service = new StaffingService($pdo);
    $periodId = $service->createPeriod('2026-07', 21, 168, null, 1, 30, 10);
    $employeeRowId = (int) $pdo->query('SELECT id FROM staffing_plan_rows WHERE user_id=2')->fetchColumn();
    $service->saveRow($periodId, [
        'department_code' => 'ОВ', 'group_id' => 1, 'position_id' => 1, 'user_id' => 2,
        'monthly_fot' => '168000', 'fte' => 1, 'status' => 'occupied',
    ], $employeeRowId);
    $service->saveRow($periodId, [
        'department_code' => 'ОВ', 'position_title' => 'Ведущий инженер',
        'employee_name' => 'Вакансия', 'monthly_fot' => '84000', 'status' => 'vacancy',
    ]);

    $dashboard = $service->dashboard($periodId);
    assert_same(2, $dashboard['positions']);
    assert_same(1, $dashboard['occupied']);
    assert_same(252000.0, (float) $dashboard['total_fot']);
    assert_same(352800.0, (float) $dashboard['full_budget']);
    assert_same(750.0, (float) $dashboard['groups'][0]['avg_hourly']);

    $service->lock($periodId, 1);
    assert_same('locked', $service->period($periodId)['status']);
    assert_same(1000.0, (float) $pdo->query('SELECT hourly_rate FROM employee_rates WHERE user_id=2')->fetchColumn());
    assert_same(750.0, (float) $pdo->query("SELECT hourly_rate FROM cfo_rates WHERE dept_code='ОВ'")->fetchColumn());
    assert_same(1000.0, (float) $service->personalRate(2, '2026-07-15'));
    assert_same(1000.0, (float) $service->personalRate(2, '2026-08-15'));
    assert_same(750.0, (float) $service->groupRate('ОВ', '2026-08-15'));

    $blocked = false;
    try {
        $service->updatePeriod($periodId, 20, 160, 0, 0, 'нельзя');
    } catch (RuntimeException $e) {
        $blocked = str_contains($e->getMessage(), 'нельзя изменять');
    }
    assert_same(true, $blocked);

    $correctionId = $service->createCorrection($periodId, 1);
    assert_same(2, (int) $service->period($correctionId)['revision']);
    assert_same('draft', $service->period($correctionId)['status']);
    assert_same(2, count($service->rows($correctionId)));
});
