<?php

declare(strict_types=1);

use App\Services\CalculatorRateService;
use App\Services\CalculatorPortfolioService;
use App\Services\ProjectPortfolioService;

test('калькулятор получает месячные ставки из последнего закрытого штатного расписания', function (): void {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE staffing_periods (id INTEGER, month_start TEXT, revision INTEGER, status TEXT, working_hours REAL)');
    $pdo->exec('CREATE TABLE staffing_group_rates (period_id INTEGER, department_code TEXT, hourly_rate REAL, total_fte REAL, total_fot REAL)');
    $pdo->exec('CREATE TABLE cfo_rates (dept_code TEXT, hourly_rate REAL)');
    $pdo->exec("INSERT INTO staffing_periods VALUES (1,'2026-07-01',1,'locked',176)");
    $pdo->exec("INSERT INTO staffing_group_rates VALUES (1,'КС-СКС',500,2,180000)");
    $result = (new CalculatorRateService($pdo))->current();
    assert_same('staffing', $result['source']);
    assert_same('СКС', $result['rates'][0]['code']);
    assert_same(90000.0, (float) $result['rates'][0]['monthlySalaryMedian']);
});

test('директорский портфель объединяет ожидаемые и живые проекты', function (): void {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, kind TEXT, code TEXT, title TEXT, stage TEXT, status TEXT, start_date TEXT, finish_date TEXT, budget_manual_thousand REAL, budget_cost_thousand REAL, budget_profit_thousand REAL, budget_bonus_thousand REAL, gip_user_id INTEGER, rp_user_id INTEGER)');
    $pdo->exec('CREATE TABLE project_labor_estimates (project_id INTEGER, status TEXT, director_cost_thousand REAL)');
    $pdo->exec('CREATE TABLE tasks (project_id INTEGER, status TEXT, date_end TEXT)');
    $pdo->exec('CREATE TABLE calculator_portfolio_entries (snapshot_id TEXT PRIMARY KEY, title TEXT, amount_thousand REAL, area_m2 REAL, start_date TEXT, finish_date TEXT, status TEXT, created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("INSERT INTO users VALUES (1,'ГИП'),(2,'РП')");
    $pdo->exec("INSERT INTO projects VALUES (10,'preproject','EXP-1','Ожидаемый','ПД','active',NULL,NULL,0,0,0,0,NULL,NULL),(20,'project','LIVE-1','Живой','РД','active','2026-01-01','2026-12-31',1000,700,200,100,1,2)");
    $pdo->exec("INSERT INTO project_labor_estimates VALUES (10,'director_approved',250)");
    $pdo->exec("INSERT INTO calculator_portfolio_entries (snapshot_id,title,amount_thousand,area_m2,start_date,finish_date,status,created_by) VALUES ('calc-smoke-123','Расчёт из калькулятора',450,2500,'2026-02-01','2026-06-30','expected',1)");
    $result = (new ProjectPortfolioService($pdo))->dashboard();
    assert_same(2, $result['metrics']['expected_count']);
    assert_same(1, $result['metrics']['live_count']);
    assert_same(700.0, (float) $result['metrics']['expected_amount']);
    assert_same(1000.0, (float) $result['metrics']['live_budget']);
    assert_true(count(array_filter($result['rows'], static fn (array $row): bool => ($row['source'] ?? '') === 'calculator')) === 1);
});

test('серверный снимок калькулятора сохраняется и удаляется только владельцем', function (): void {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE calculator_portfolio_entries (snapshot_id TEXT PRIMARY KEY, title TEXT, amount_thousand REAL, area_m2 REAL, start_date TEXT, finish_date TEXT, status TEXT, created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $service = new CalculatorPortfolioService($pdo);
    $saved = $service->save(7, [
        'snapshot_id' => 'calc-owner-1234',
        'title' => 'Новый объект',
        'amount_thousand' => 1250.5,
        'area_m2' => 8000,
        'start_date' => '2026-08-01',
        'finish_date' => '2027-01-31',
    ]);
    assert_same(true, $saved['saved']);
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM calculator_portfolio_entries')->fetchColumn());
    assert_same(false, $service->delete(8, 'calc-owner-1234'));
    assert_same(true, $service->delete(7, 'calc-owner-1234'));
});

test('форма проекта принимает общий бюджет и постепенную разбивку, не требует папку', function (): void {
    $form = file_get_contents(BASE_PATH . '/app/Views/projects/form.php');
    $controller = file_get_contents(BASE_PATH . '/app/Controllers/ProjectController.php');
    $routes = file_get_contents(BASE_PATH . '/public/index.php');
    assert_true(str_contains($form, 'name="budget_total_thousand"') && str_contains($form, 'budget_cost_thousand') && str_contains($form, 'budget_profit_thousand') && str_contains($form, 'budget_bonus_thousand'));
    assert_true(!str_contains($form, 'name="budget_cost_thousand" required') && str_contains($form, 'data-project-budget-remainder'));
    assert_true(!str_contains($form, ' novalidate') && str_contains($form, 'required-mark') && str_contains($form, 'formErrors'));
    assert_true(str_contains($controller, 'rememberProjectFormState') && str_contains($controller, 'введённые данные сохранены'));
    assert_true(!str_contains($form, 'name="file_folder_url"'));
    assert_true(!str_contains($routes, '/folders/create'));
    foreach (['ПД', 'РД', 'ПД-РД', 'АН'] as $stage) assert_true(str_contains($form, "'{$stage}'"));
});
