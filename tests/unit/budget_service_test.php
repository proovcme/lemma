<?php

declare(strict_types=1);

use App\Services\BudgetService;

test('общий бюджет связывает проекты, платежи, фактические затраты и закрытое ШР', function (): void {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, code TEXT, title TEXT, status TEXT, start_date TEXT, finish_date TEXT, budget_manual_thousand REAL, budget_cost_thousand REAL, budget_profit_thousand REAL, budget_bonus_thousand REAL, budget_comment TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, department TEXT)');
    $pdo->exec('CREATE TABLE time_entries (project_id INTEGER, user_id INTEGER, work_date TEXT, minutes INTEGER)');
    $pdo->exec('CREATE TABLE employee_rates (user_id INTEGER, hourly_rate REAL)');
    $pdo->exec('CREATE TABLE cfo_rates (dept_code TEXT, hourly_rate REAL)');
    $pdo->exec('CREATE TABLE project_uts_facts (project_id INTEGER, amount REAL)');
    $pdo->exec('CREATE TABLE staffing_periods (id INTEGER PRIMARY KEY, month_start TEXT, status TEXT, payroll_burden_pct REAL, overhead_pct REAL)');
    $pdo->exec('CREATE TABLE staffing_plan_rows (period_id INTEGER, status TEXT, monthly_fot REAL)');
    $pdo->exec('CREATE TABLE staffing_personal_rates (period_id INTEGER, user_id INTEGER, hourly_rate REAL)');
    $pdo->exec('CREATE TABLE staffing_group_rates (period_id INTEGER, department_code TEXT, hourly_rate REAL)');
    $pdo->exec('CREATE TABLE project_payment_schedule (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, payment_name TEXT, planned_date TEXT, planned_amount REAL, status TEXT, invoice_date TEXT, actual_date TEXT, actual_amount REAL, comment TEXT, sort_order INTEGER, created_by INTEGER, updated_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("INSERT INTO projects VALUES (1,'P-1','Проект','active','2026-01-01','2026-12-31',100,70,20,10,NULL,CURRENT_TIMESTAMP)");
    $pdo->exec("INSERT INTO users VALUES (1,'ОВ')");
    $pdo->exec("INSERT INTO time_entries VALUES (1,1,'2026-01-15',600)");
    $pdo->exec("INSERT INTO employee_rates VALUES (1,1000)");
    $pdo->exec("INSERT INTO cfo_rates VALUES ('ОВ',800)");
    $pdo->exec("INSERT INTO project_uts_facts VALUES (1,5000)");
    $pdo->exec("INSERT INTO staffing_periods VALUES (1,'2026-01-01','locked',30,10)");
    $pdo->exec("INSERT INTO staffing_plan_rows VALUES (1,'occupied',50000)");

    $service = new BudgetService($pdo);
    $paymentId = $service->savePayment(null, [
        'project_id' => 1,
        'payment_name' => 'Аванс',
        'planned_date' => '2026-01-20',
        'planned_amount' => '60000',
        'status' => 'received',
        'actual_date' => '2026-01-21',
        'actual_amount' => '40000',
    ], 1);
    assert_same(true, $paymentId > 0);

    $dashboard = $service->dashboard(2026);
    assert_same(100000.0, $dashboard['metrics']['portfolio_budget']);
    assert_same(70000.0, $dashboard['metrics']['planned_cost']);
    assert_same(20000.0, $dashboard['metrics']['planned_profit']);
    assert_same(10000.0, $dashboard['metrics']['planned_bonus']);
    assert_same(40000.0, $dashboard['metrics']['received_payments']);
    assert_same(15000.0, $dashboard['metrics']['actual_cost']);
    assert_same(70000.0, $dashboard['cashflow'][0]['staffing_cost']);
    assert_same(-30000.0, $dashboard['cashflow'][0]['actual_net']);
});

test('бюджет проекта складывается из затрат прибыли и премиальной части', function (): void {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, budget_manual_thousand REAL, budget_cost_thousand REAL, budget_profit_thousand REAL, budget_bonus_thousand REAL, budget_comment TEXT, updated_at TEXT)');
    $pdo->exec('INSERT INTO projects (id) VALUES (1)');
    (new BudgetService($pdo))->saveProjectBudget(1, 700, 200, 100, 'Версия 1');
    $row = $pdo->query('SELECT * FROM projects WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    assert_same(1000.0, (float) $row['budget_manual_thousand']);
    assert_same(700.0, (float) $row['budget_cost_thousand']);
    assert_same(200.0, (float) $row['budget_profit_thousand']);
    assert_same(100.0, (float) $row['budget_bonus_thousand']);
});

test('общий бюджет можно сохранить без разбивки и детализировать частично', function (): void {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, budget_manual_thousand REAL, budget_cost_thousand REAL, budget_profit_thousand REAL, budget_bonus_thousand REAL, budget_comment TEXT, updated_at TEXT)');
    $pdo->exec('INSERT INTO projects (id) VALUES (1)');
    $service = new BudgetService($pdo);
    $service->saveProjectBudget(1, '', '', '', 'Сначала договор', 1500);
    $row = $pdo->query('SELECT * FROM projects WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    assert_same(1500.0, (float) $row['budget_manual_thousand']);
    assert_same(0.0, (float) $row['budget_cost_thousand']);

    $service->saveProjectBudget(1, 600, 200, '', 'Частичная разбивка', 1500);
    $row = $pdo->query('SELECT * FROM projects WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    assert_same(1500.0, (float) $row['budget_manual_thousand']);
    assert_same(600.0, (float) $row['budget_cost_thousand']);
    assert_same(200.0, (float) $row['budget_profit_thousand']);
    assert_same(0.0, (float) $row['budget_bonus_thousand']);
    assert_throws(static fn () => $service->saveProjectBudget(1, 1000, 600, '', '', 1500), InvalidArgumentException::class);
});

test('полученный платёж требует фактическую дату и сумму', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY)');
    $pdo->exec('CREATE TABLE project_payment_schedule (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, payment_name TEXT, planned_date TEXT, planned_amount REAL, status TEXT, invoice_date TEXT, actual_date TEXT, actual_amount REAL, comment TEXT, sort_order INTEGER, created_by INTEGER, updated_by INTEGER, updated_at TEXT)');
    $pdo->exec('INSERT INTO projects VALUES (1)');
    assert_throws(fn () => (new BudgetService($pdo))->savePayment(null, [
        'project_id' => 1, 'payment_name' => 'Финал', 'planned_date' => '2026-12-01', 'planned_amount' => 100, 'status' => 'received',
    ], 1), InvalidArgumentException::class);
});
