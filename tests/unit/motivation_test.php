<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\MotivationService;
use App\Services\PermissionService;

function motivation_test_pdo(): PDO
{
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('
        CREATE TABLE positions (id INTEGER PRIMARY KEY, grade TEXT);
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT,
            email TEXT,
            role TEXT,
            department TEXT,
            position_id INTEGER,
            is_active INTEGER DEFAULT 1
        );
        CREATE TABLE employee_legal_entities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            daily_hours REAL,
            base_oklad REAL DEFAULT 0,
            base_nadbavka REAL DEFAULT 0,
            premium REAL DEFAULT 0,
            project_nadbavka REAL DEFAULT 0,
            is_active INTEGER DEFAULT 1
        );
        CREATE TABLE employee_rates (
            user_id INTEGER PRIMARY KEY,
            hourly_rate REAL
        );
        CREATE TABLE cfo_rates (
            dept_code TEXT PRIMARY KEY,
            hourly_rate REAL
        );
        CREATE TABLE projects (
            id INTEGER PRIMARY KEY,
            code TEXT,
            title TEXT,
            budget_manual_thousand REAL DEFAULT 0,
            status TEXT
        );
        CREATE TABLE tasks (
            id INTEGER PRIMARY KEY,
            project_id INTEGER,
            assignee_id INTEGER,
            title TEXT,
            task_type TEXT DEFAULT "work",
            priority TEXT DEFAULT "mid",
            status TEXT,
            date_end TEXT,
            closed_at TEXT,
            planned_hours REAL,
            actual_hours REAL DEFAULT 0,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE project_labor_estimates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER,
            director_hours REAL,
            status TEXT
        );
        CREATE TABLE time_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            project_id INTEGER,
            task_id INTEGER,
            work_date TEXT,
            minutes INTEGER,
            category TEXT,
            status TEXT
        );
        CREATE TABLE time_month_reviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            period_start TEXT,
            period_end TEXT,
            status TEXT
        );
        CREATE TABLE motivation_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value REAL NOT NULL DEFAULT 0,
            label TEXT NOT NULL,
            updated_by INTEGER,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE motivation_grade_coefficients (
            grade TEXT PRIMARY KEY,
            coefficient REAL NOT NULL DEFAULT 1,
            label TEXT,
            updated_by INTEGER,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE project_motivation_settings (
            project_id INTEGER PRIMARY KEY,
            project_fund REAL NOT NULL DEFAULT 0,
            budget_hours REAL,
            is_paid INTEGER NOT NULL DEFAULT 0,
            paid_at TEXT,
            comment TEXT,
            updated_by INTEGER,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE motivation_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            period_start TEXT NOT NULL,
            period_end TEXT NOT NULL,
            state TEXT NOT NULL DEFAULT "draft",
            settings_snapshot TEXT,
            totals_json TEXT,
            created_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            locked_by INTEGER,
            locked_at TEXT,
            UNIQUE(period_start, state)
        );
        CREATE TABLE motivation_run_rows (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            department TEXT,
            grade TEXT,
            grade_coefficient REAL NOT NULL DEFAULT 1,
            employment_ratio REAL NOT NULL DEFAULT 1,
            locked_hours REAL NOT NULL DEFAULT 0,
            expected_hours REAL NOT NULL DEFAULT 0,
            kpi_score REAL NOT NULL DEFAULT 0,
            kpi_amount REAL NOT NULL DEFAULT 0,
            project_bonus_amount REAL NOT NULL DEFAULT 0,
            total_amount REAL NOT NULL DEFAULT 0,
            basis_json TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(run_id, user_id)
        );
    ');
    $pdo->exec("INSERT INTO positions (id, grade) VALUES (1, 'N-8'), (2, 'N-4')");
    $pdo->exec("INSERT INTO users (id, name, email, role, department, position_id, is_active) VALUES
        (1, 'Директор', 'director@example.local', 'director', 'ДПР', 1, 1),
        (10, 'Инженер 1', 'u1@example.local', 'engineer', 'ОВ', 1, 1),
        (20, 'Инженер 2', 'u2@example.local', 'engineer', 'АР', 2, 1)
    ");
    $pdo->exec("INSERT INTO employee_legal_entities (user_id, daily_hours, base_oklad, base_nadbavka, is_active) VALUES (10, 8, 80000, 0, 1), (20, 8, 80000, 0, 1)");
    $pdo->exec("INSERT INTO employee_rates (user_id, hourly_rate) VALUES (10, 1000), (20, 2000)");
    $pdo->exec("INSERT INTO cfo_rates (dept_code, hourly_rate) VALUES ('ОВ', 900), ('АР', 1800)");
    $pdo->exec("INSERT INTO projects (id, code, title, budget_manual_thousand, status) VALUES (100, 'P100', 'Проект', 500, 'active')");
    $pdo->exec("INSERT INTO tasks (id, project_id, assignee_id, title, priority, status, date_end, planned_hours, actual_hours, updated_at) VALUES
        (1000, 100, 10, 'Работа 1', 'mid', 'in_progress', '2026-06-30', 40, 0, '2026-06-10'),
        (1001, 100, 20, 'Работа 2', 'mid', 'in_progress', '2026-06-30', 40, 0, '2026-06-10')
    ");

    return $pdo;
}

test('MotivationService считает KPI выше для закрытого табеля', function (): void {
    $pdo = motivation_test_pdo();
    $pdo->exec("INSERT INTO time_entries (user_id, project_id, task_id, work_date, minutes, category, status) VALUES
        (10, 100, 1000, '2026-06-03', 480, 'task', 'locked'),
        (20, 100, 1001, '2026-06-03', 480, 'task', 'locked')
    ");
    $pdo->exec("INSERT INTO time_month_reviews (user_id, period_start, period_end, status) VALUES
        (10, '2026-06-01', '2026-06-30', 'locked'),
        (20, '2026-06-01', '2026-06-30', 'draft')
    ");

    $rows = (new MotivationService($pdo))->preview('2026-06-01')['rows'];
    $byId = [];
    foreach ($rows as $row) {
        $byId[(int) $row['user_id']] = $row;
    }

    assert_true((float) $byId[10]['kpi_score'] > (float) $byId[20]['kpi_score'], 'закрытый табель повышает KPI');
    assert_true((float) $byId[10]['kpi_score'] <= 1.0 && (float) $byId[10]['kpi_score'] >= 0.0, 'KPI ограничен 0..1');
    Database::reset();
});

test('MotivationService распределяет только оплаченный фонд и снижает его при перерасходе', function (): void {
    $pdo = motivation_test_pdo();
    $pdo->exec("INSERT INTO time_entries (user_id, project_id, task_id, work_date, minutes, category, status) VALUES
        (10, 100, 1000, '2026-06-03', 3000, 'task', 'locked'),
        (20, 100, 1001, '2026-06-03', 3000, 'task', 'locked')
    ");
    $service = new MotivationService($pdo);
    $noPaid = $service->preview('2026-06-01');
    assert_same(0.0, (float) $noPaid['totals']['project_bonus_amount'], 'без оплаты проектная часть нулевая');

    $pdo->exec("INSERT INTO project_motivation_settings (project_id, project_fund, budget_hours, is_paid, paid_at) VALUES (100, 1000, 50, 1, '2026-06-10')");
    $paid = $service->preview('2026-06-01');
    assert_same(500.0, (float) $paid['totals']['project_bonus_amount'], 'перерасход 100 ч при бюджете 50 ч режет фонд пополам');
    Database::reset();
});

test('MotivationService фиксирует snapshot и не перезаписывает locked run', function (): void {
    $pdo = motivation_test_pdo();
    $pdo->exec("INSERT INTO time_entries (user_id, project_id, task_id, work_date, minutes, category, status) VALUES (10, 100, 1000, '2026-06-03', 480, 'task', 'locked')");
    $pdo->exec("INSERT INTO time_month_reviews (user_id, period_start, period_end, status) VALUES (10, '2026-06-01', '2026-06-30', 'locked')");

    $service = new MotivationService($pdo);
    $service->lockRun('2026-06-01', 1);
    $lockedBefore = $service->lockedRun('2026-06-01');
    $amountBefore = (float) $lockedBefore['rows'][0]['kpi_amount'];

    $pdo->exec("UPDATE motivation_settings SET setting_value = 1 WHERE setting_key = 'monthly_kpi_max'");
    $pdo->exec("INSERT INTO time_entries (user_id, project_id, task_id, work_date, minutes, category, status) VALUES (10, 100, 1000, '2026-06-04', 480, 'task', 'locked')");
    $lockedAfter = $service->lockedRun('2026-06-01');
    assert_same($amountBefore, (float) $lockedAfter['rows'][0]['kpi_amount'], 'зафиксированная строка не меняется после правок настроек и времени');
    assert_throws(static fn () => $service->lockRun('2026-06-01', 1), 'повторная фиксация locked run запрещена');
    Database::reset();
});

test('MotivationService показывает контроль отставания и затрат без фиксации расчёта', function (): void {
    $pdo = motivation_test_pdo();
    $pdo->exec("INSERT INTO tasks (id, project_id, assignee_id, title, priority, status, date_end, planned_hours, actual_hours, updated_at) VALUES
        (1002, 100, 10, 'Закрытая работа', 'high', 'done', '2026-06-06', 8, 8, '2026-06-06'),
        (1003, 100, 20, 'Просроченная работа', 'mid', 'in_progress', '2026-06-02', 8, 0, '2026-06-02'),
        (1004, 100, 20, 'Просроченная работа 2', 'mid', 'in_progress', '2026-06-03', 8, 0, '2026-06-03'),
        (1005, 100, 20, 'Просроченная работа 3', 'mid', 'in_progress', '2026-06-04', 8, 0, '2026-06-04')
    ");
    $pdo->exec("INSERT INTO time_entries (user_id, project_id, task_id, work_date, minutes, category, status) VALUES
        (10, 100, 1002, '2026-06-06', 480, 'task', 'draft'),
        (20, 100, 1003, '2026-06-06', 120, 'task', 'draft')
    ");

    $control = (new MotivationService($pdo))->control('2026-06-01');
    $byId = [];
    foreach ($control['rows'] as $row) {
        $byId[(int) $row['user_id']] = $row;
    }

    assert_same('behind', $byId[20]['status'], 'три просрочки переводят сотрудника в отставание');
    assert_true((float) $byId[10]['actual_cost'] > 0, 'контроль считает факт затрат по ставке');
    assert_true((int) $control['totals']['bottlenecks'] > 0, 'узкие места выводятся в сводку');
    Database::reset();
});

test('Мотивацию видят только директор и админ', function (): void {
    assert_same(true, PermissionService::canManageMotivation(['role' => 'director']));
    assert_same(true, PermissionService::canManageMotivation(['role' => 'admin']));
    assert_same(false, PermissionService::canManageMotivation(['role' => 'department_head']));
    assert_same(false, PermissionService::canManageMotivation(['role' => 'engineer']));
});
