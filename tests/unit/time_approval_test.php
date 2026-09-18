<?php

declare(strict_types=1);

use App\Core\Database;
use App\Controllers\ReportController;
use App\Services\TimeApprovalService;

test('TimeApprovalService снимает месячный срез и блокирует время только при закрытии', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT,
            email TEXT,
            role TEXT,
            department TEXT,
            is_active INTEGER DEFAULT 1
        )
    ");
    $pdo->exec("
        CREATE TABLE projects (
            id INTEGER PRIMARY KEY,
            code TEXT,
            title TEXT,
            rp_user_id INTEGER,
            gip_user_id INTEGER
        )
    ");
    $pdo->exec("
        CREATE TABLE time_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            period_start TEXT,
            period_end TEXT,
            status TEXT,
            mode TEXT,
            total_minutes INTEGER
        )
    ");
    $pdo->exec("
        CREATE TABLE time_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_id INTEGER,
            user_id INTEGER,
            project_id INTEGER,
            task_id INTEGER,
            work_date TEXT,
            minutes INTEGER,
            category TEXT,
            phase TEXT,
            status TEXT,
            updated_at TEXT
        )
    ");
    $pdo->exec("
        CREATE TABLE time_month_reviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            period_start TEXT NOT NULL,
            period_end TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'draft',
            submitted_at TEXT,
            submitted_by INTEGER,
            gip_approved_at TEXT,
            gip_approved_by INTEGER,
            department_approved_at TEXT,
            department_approved_by INTEGER,
            director_approved_at TEXT,
            director_approved_by INTEGER,
            returned_at TEXT,
            returned_by INTEGER,
            return_comment TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, period_start)
        )
    ");
    $pdo->exec("
        INSERT INTO users (id, name, email, role, department) VALUES
            (10, 'Инженер', 'engineer@example.local', 'engineer', 'ОВ'),
            (20, 'ГИП', 'gip@example.local', 'gip', 'ГИП'),
            (30, 'Директор', 'director@example.local', 'director', 'ДПР'),
            (40, 'Руководитель отдела', 'head@example.local', 'department_head', 'ОВ')
    ");
    $pdo->exec("INSERT INTO projects (id, code, title, rp_user_id, gip_user_id) VALUES (100, 'P100', 'Проект', 20, 20)");
    $pdo->exec("INSERT INTO time_batches (id, user_id, period_start, period_end, status, mode, total_minutes) VALUES (1, 10, '2026-06-01', '2026-06-01', 'draft', 'manual_week', 120)");
    $pdo->exec("INSERT INTO time_entries (batch_id, user_id, project_id, task_id, work_date, minutes, category, phase, status) VALUES (1, 10, 100, NULL, '2026-06-01', 120, 'task', 'execution', 'draft')");

    $service = new TimeApprovalService($pdo);
    $rows = $service->reviewsForUser(['id' => 30, 'role' => 'director'], '2026-06-01');
    assert_same(4, count($rows), 'директор видит активных людей до подачи месяца');
    assert_same('open', (string) $rows[0]['status'], 'срез открыт без отдельной подачи пользователем');
    assert_same('Открыт', TimeApprovalService::reviewStatusLabel($rows[0]));

    $service->gipApproveSnapshot(10, '2026-06-01', ['id' => 20, 'role' => 'gip']);
    assert_same('gip_approved', (string) $pdo->query('SELECT status FROM time_month_reviews')->fetchColumn());
    assert_same('draft', (string) $pdo->query('SELECT status FROM time_entries')->fetchColumn(), 'приемка ГИПом не блокирует правки пользователя');
    $review = $service->reviewForUser(10, '2026-06-01');
    assert_same('ГИП подтвердил', TimeApprovalService::reviewStatusLabel($review));

    $service->departmentApproveSnapshot(10, '2026-06-01', ['id' => 40, 'role' => 'department_head', 'department' => 'ОВ']);
    assert_true((string) $pdo->query('SELECT COALESCE(department_approved_at, "") FROM time_month_reviews')->fetchColumn() !== '');
    assert_same('ГИП подтвердил', TimeApprovalService::reviewStatusLabel($service->reviewForUser(10, '2026-06-01')));

    $service->closeMonthSnapshot(10, '2026-06-01', ['id' => 30, 'role' => 'director']);
    assert_same('locked', (string) $pdo->query('SELECT status FROM time_month_reviews')->fetchColumn());
    assert_same('locked', (string) $pdo->query('SELECT status FROM time_entries')->fetchColumn());
    assert_same('locked', (string) $pdo->query('SELECT status FROM time_batches')->fetchColumn());
    assert_same('Месяц закрыт', TimeApprovalService::reviewStatusLabel($service->reviewForUser(10, '2026-06-01')));

    assert_throws(
        static fn () => $service->reopenLockedMonthForCorrection(10, '2026-06-01', ['id' => 20, 'role' => 'gip'], 'правка'),
        'ГИП не открывает закрытый месяц'
    );
    assert_throws(
        static fn () => $service->reopenLockedMonthForCorrection(10, '2026-06-01', ['id' => 30, 'role' => 'director'], ''),
        'корректировка закрытого месяца требует комментарий'
    );

    $service->reopenLockedMonthForCorrection(10, '2026-06-01', ['id' => 30, 'role' => 'director'], 'ошибка в часах');
    assert_same('returned', (string) $pdo->query('SELECT status FROM time_month_reviews')->fetchColumn());
    assert_same('', (string) $pdo->query('SELECT COALESCE(director_approved_at, "") FROM time_month_reviews')->fetchColumn());
    assert_same('draft', (string) $pdo->query('SELECT status FROM time_entries')->fetchColumn());
    assert_same('draft', (string) $pdo->query('SELECT status FROM time_batches')->fetchColumn());
    assert_true(str_contains((string) $pdo->query('SELECT return_comment FROM time_month_reviews')->fetchColumn(), 'ошибка в часах'));
    assert_same('Возвращено', TimeApprovalService::reviewStatusLabel($service->reviewForUser(10, '2026-06-01')));

    Database::reset();
});

test('TimeApprovalService показывает отдельный статус подтверждения руководителем отдела', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT,
            email TEXT,
            role TEXT,
            department TEXT,
            is_active INTEGER DEFAULT 1
        )
    ");
    $pdo->exec("
        CREATE TABLE projects (
            id INTEGER PRIMARY KEY,
            code TEXT,
            title TEXT,
            rp_user_id INTEGER,
            gip_user_id INTEGER
        )
    ");
    $pdo->exec("
        CREATE TABLE time_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            project_id INTEGER,
            work_date TEXT,
            minutes INTEGER,
            status TEXT,
            updated_at TEXT
        )
    ");
    $pdo->exec("
        CREATE TABLE time_month_reviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            period_start TEXT NOT NULL,
            period_end TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'draft',
            submitted_at TEXT,
            submitted_by INTEGER,
            gip_approved_at TEXT,
            gip_approved_by INTEGER,
            department_approved_at TEXT,
            department_approved_by INTEGER,
            director_approved_at TEXT,
            director_approved_by INTEGER,
            returned_at TEXT,
            returned_by INTEGER,
            return_comment TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, period_start)
        )
    ");
    $pdo->exec("
        INSERT INTO users (id, name, email, role, department) VALUES
            (10, 'Инженер', 'engineer@example.local', 'engineer', 'ОВ'),
            (40, 'Руководитель отдела', 'head@example.local', 'department_head', 'ОВ')
    ");
    $pdo->exec("INSERT INTO time_entries (user_id, project_id, work_date, minutes, status) VALUES (10, NULL, '2026-06-01', 120, 'draft')");

    $service = new TimeApprovalService($pdo);
    $service->departmentApproveSnapshot(10, '2026-06-01', ['id' => 40, 'role' => 'department_head', 'department' => 'ОВ']);

    assert_same('Руководитель отдела подтвердил', TimeApprovalService::reviewStatusLabel($service->reviewForUser(10, '2026-06-01')));

    Database::reset();
});

test('ДБ-отчёт берёт только закрытый факт времени', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            tab_number TEXT,
            name TEXT,
            email TEXT,
            role TEXT,
            department TEXT
        )
    ");
    $pdo->exec("
        CREATE TABLE projects (
            id INTEGER PRIMARY KEY,
            code TEXT,
            title TEXT,
            pp TEXT,
            object TEXT,
            status TEXT,
            rp_user_id INTEGER,
            gip_user_id INTEGER
        )
    ");
    $pdo->exec("
        CREATE TABLE tasks (
            id INTEGER PRIMARY KEY,
            project_id INTEGER,
            pp_code_id INTEGER,
            btp_code_id INTEGER,
            btp TEXT,
            title TEXT,
            status TEXT,
            discipline TEXT,
            section TEXT
        )
    ");
    $pdo->exec("
        CREATE TABLE project_pp_codes (
            id INTEGER PRIMARY KEY,
            project_id INTEGER,
            code TEXT,
            title TEXT
        )
    ");
    $pdo->exec("
        CREATE TABLE project_btp_codes (
            id INTEGER PRIMARY KEY,
            project_id INTEGER,
            pp_code_id INTEGER,
            code TEXT,
            title TEXT
        )
    ");
    $pdo->exec("
        CREATE TABLE time_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            project_id INTEGER,
            task_id INTEGER,
            work_date TEXT,
            minutes INTEGER,
            category TEXT,
            phase TEXT,
            comment TEXT,
            status TEXT
        )
    ");
    $pdo->exec("INSERT INTO users (id, tab_number, name, email, role, department) VALUES
        (1, '001', 'Директор', 'director@example.local', 'director', 'ДПР'),
        (10, '010', 'Инженер', 'engineer@example.local', 'engineer', 'ОВ')
    ");
    $pdo->exec("INSERT INTO projects (id, code, title, pp, object, status, rp_user_id, gip_user_id) VALUES (100, 'P100', 'Проект', 'ПП-1', 'Объект', 'active', 1, 1)");
    $pdo->exec("INSERT INTO project_pp_codes (id, project_id, code, title) VALUES (300, 100, 'ПП-NEW', 'Сделка')");
    $pdo->exec("INSERT INTO project_btp_codes (id, project_id, pp_code_id, code, title) VALUES (400, 100, 300, 'БТП-NEW', 'Статья')");
    $pdo->exec("INSERT INTO tasks (id, project_id, pp_code_id, btp_code_id, btp, title, status, discipline, section) VALUES (200, 100, 300, 400, 'БТП-OLD', 'Работа', 'in_progress', 'ОВ', '1')");
    $pdo->exec("INSERT INTO time_entries (user_id, project_id, task_id, work_date, minutes, category, phase, comment, status) VALUES
        (10, 100, 200, '2026-06-01', 120, 'task', 'execution', 'закрытый факт', 'locked'),
        (10, 100, 200, '2026-06-02', 180, 'task', 'execution', 'живой ввод', 'draft')
    ");

    $controller = new ReportController();
    $method = new ReflectionMethod(ReportController::class, 'dbReportRows');
    $rows = $method->invoke($controller, ['id' => 1, 'role' => 'director'], [
        'date_from' => '2026-06-01',
        'date_to' => '2026-06-30',
    ]);

    assert_same(1, count($rows));
    assert_same(2.0, $rows[0][28], 'в ДБ-отчёт попали только locked-часы');
    assert_same('БТП-NEW', $rows[0][8]);
    assert_same('ПП-NEW', $rows[0][9]);
    assert_same('закрытый факт', $rows[0][17]);

    $pdo->exec('UPDATE time_entries SET status = "draft"');
    $rows = $method->invoke($controller, ['id' => 1, 'role' => 'director'], [
        'date_from' => '2026-06-01',
        'date_to' => '2026-06-30',
    ]);
    assert_same(0, count($rows), 'после открытия корректировки часы уходят из ДБ-отчёта до повторного закрытия');

    Database::reset();
});

test('Отчёт по исполнителю подтверждает только доступные открытые строки времени', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT,
            email TEXT,
            role TEXT,
            department TEXT,
            manager_id INTEGER,
            is_active INTEGER DEFAULT 1
        )
    ");
    $pdo->exec("
        CREATE TABLE projects (
            id INTEGER PRIMARY KEY,
            code TEXT,
            title TEXT,
            status TEXT,
            rp_user_id INTEGER,
            gip_user_id INTEGER
        )
    ");
    $pdo->exec("
        CREATE TABLE tasks (
            id INTEGER PRIMARY KEY,
            project_id INTEGER
        )
    ");
    $pdo->exec("
        CREATE TABLE time_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            project_id INTEGER,
            task_id INTEGER,
            work_date TEXT,
            minutes INTEGER,
            category TEXT,
            phase TEXT,
            status TEXT,
            updated_at TEXT
        )
    ");
    $pdo->exec("INSERT INTO users (id, name, email, role, department, manager_id, is_active) VALUES
        (1, 'Директор', 'director@example.local', 'director', 'ДПР', NULL, 1),
        (10, 'Инженер', 'engineer@example.local', 'engineer', 'ОВ', 40, 1),
        (11, 'Другой инженер', 'other@example.local', 'engineer', 'СС', NULL, 1),
        (40, 'Руководитель отдела', 'head@example.local', 'department_head', 'ОВ', NULL, 1)
    ");
    $pdo->exec("INSERT INTO projects (id, code, title, status, rp_user_id, gip_user_id) VALUES (100, 'P100', 'Проект', 'active', 1, 1)");
    $pdo->exec("INSERT INTO tasks (id, project_id) VALUES (200, 100)");
    $pdo->exec("INSERT INTO time_entries (user_id, project_id, task_id, work_date, minutes, category, phase, status) VALUES
        (10, 100, 200, '2026-06-01', 120, 'task', 'execution', 'draft'),
        (10, 100, 200, '2026-06-02', 60, 'task', 'execution', 'locked'),
        (11, 100, 200, '2026-06-01', 90, 'task', 'execution', 'draft')
    ");

    $controller = new ReportController();
    $method = new ReflectionMethod(ReportController::class, 'approvePeopleTimeRows');
    $updated = $method->invoke($controller, ['id' => 40, 'role' => 'department_head', 'department' => 'ОВ'], [
        'user_id' => 10,
        'date_from' => '2026-06-01',
        'date_to' => '2026-06-30',
        'approve_scope' => 'all',
        'visible_time_entry_ids' => [1, 2, 3],
    ]);

    assert_same(1, $updated);
    assert_same('approved', (string) $pdo->query('SELECT status FROM time_entries WHERE id = 1')->fetchColumn());
    assert_same('locked', (string) $pdo->query('SELECT status FROM time_entries WHERE id = 2')->fetchColumn());
    assert_same('draft', (string) $pdo->query('SELECT status FROM time_entries WHERE id = 3')->fetchColumn());

    Database::reset();
});

test('Отчёт по исполнителю показывает личный план и личный факт отдельно от факта задачи', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT,
            email TEXT,
            role TEXT,
            department TEXT,
            manager_id INTEGER,
            is_active INTEGER DEFAULT 1
        )
    ");
    $pdo->exec("
        CREATE TABLE projects (
            id INTEGER PRIMARY KEY,
            code TEXT,
            title TEXT,
            status TEXT,
            rp_user_id INTEGER,
            gip_user_id INTEGER
        )
    ");
    $pdo->exec("
        CREATE TABLE tasks (
            id INTEGER PRIMARY KEY,
            project_id INTEGER,
            pp_code_id INTEGER,
            btp_code_id INTEGER,
            btp TEXT,
            title TEXT,
            status TEXT,
            task_type TEXT,
            priority TEXT,
            urgency TEXT,
            assignee_id INTEGER,
            author_id INTEGER,
            reviewer_id INTEGER,
            date_start TEXT,
            date_end TEXT,
            closed_at TEXT,
            planned_hours REAL,
            actual_hours REAL,
            progress INTEGER
        )
    ");
    $pdo->exec('CREATE TABLE task_participants (task_id INTEGER, user_id INTEGER, role TEXT)');
    $pdo->exec('CREATE TABLE project_pp_codes (id INTEGER PRIMARY KEY, project_id INTEGER, code TEXT, title TEXT)');
    $pdo->exec('CREATE TABLE project_btp_codes (id INTEGER PRIMARY KEY, project_id INTEGER, pp_code_id INTEGER, code TEXT, title TEXT)');
    $pdo->exec('CREATE TABLE employee_rates (user_id INTEGER, hourly_rate REAL)');
    $pdo->exec('CREATE TABLE cfo_rates (dept_code TEXT, hourly_rate REAL)');
    $pdo->exec("
        CREATE TABLE time_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            project_id INTEGER,
            task_id INTEGER,
            work_date TEXT,
            minutes INTEGER,
            category TEXT,
            phase TEXT,
            status TEXT
        )
    ");
    $pdo->exec("INSERT INTO users (id, name, email, role, department, manager_id, is_active) VALUES
        (1, 'Директор', 'director@example.local', 'director', 'ДПР', NULL, 1),
        (10, 'Инженер ОВ', 'engineer@example.local', 'engineer', 'ОВ', 40, 1),
        (11, 'Инженер СС', 'other@example.local', 'engineer', 'СС', NULL, 1),
        (40, 'Руководитель ОВ', 'head@example.local', 'department_head', 'ОВ', NULL, 1)
    ");
    $pdo->exec("INSERT INTO projects (id, code, title, status, rp_user_id, gip_user_id) VALUES (100, 'P100', 'Проект', 'active', 1, 1)");
    $pdo->exec("INSERT INTO project_pp_codes (id, project_id, code, title) VALUES (300, 100, 'ПП-1', 'Сделка')");
    $pdo->exec("INSERT INTO project_btp_codes (id, project_id, pp_code_id, code, title) VALUES (400, 100, 300, 'БТП-1', 'Статья')");
    $pdo->exec("INSERT INTO tasks (id, project_id, pp_code_id, btp_code_id, btp, title, status, task_type, priority, urgency, assignee_id, author_id, reviewer_id, date_start, date_end, closed_at, planned_hours, actual_hours, progress) VALUES
        (200, 100, 300, 400, '', 'Совместная задача', 'in_progress', 'work', 'normal', 'normal', 10, 1, NULL, '2026-06-01', '2026-06-30', NULL, 16, 16, 50),
        (201, 100, 300, 400, '', 'Будущая задача', 'in_progress', 'work', 'normal', 'normal', 10, 1, NULL, '2026-06-01', '2026-07-01', NULL, 4, 0, 0)
    ");
    $pdo->exec("INSERT INTO task_participants (task_id, user_id, role) VALUES (200, 11, 'coauthor')");
    $pdo->exec("INSERT INTO time_entries (user_id, project_id, task_id, work_date, minutes, category, phase, status) VALUES
        (10, 100, 200, '2026-06-03', 480, 'task', 'execution', 'approved'),
        (11, 100, 200, '2026-06-03', 480, 'task', 'execution', 'approved')
    ");

    $controller = new ReportController();
    $people = new ReflectionMethod(ReportController::class, 'peopleModel');
    $model = $people->invoke($controller, ['id' => 1, 'role' => 'director', 'department' => 'ДПР'], 10, [
        'date_from' => '2026-06-01',
        'date_to' => '2026-06-30',
        '_task_date_filter' => true,
    ]);

    assert_same(1, count($model['tasks']));
    assert_same(8.0, (float) $model['tasks'][0]['person_planned_hours'], 'личный план не должен равняться всему плану задачи');
    assert_same(8.0, (float) $model['tasks'][0]['person_actual_hours'], 'личный факт берется только из строк выбранного сотрудника');
    assert_same(16.0, (float) $model['tasks'][0]['actual_hours'], 'общий факт задачи остается видим отдельно');
    assert_same(8.0, (float) $model['metrics']['person_planned_hours']);
    assert_same(8.0, (float) $model['metrics']['person_actual_hours']);

    $defaultPeriodModel = $people->invoke($controller, ['id' => 1, 'role' => 'director', 'department' => 'ДПР'], 10, [
        'date_from' => '2026-06-01',
        'date_to' => '2026-06-30',
        '_task_date_filter' => false,
    ]);
    assert_same(2, count($defaultPeriodModel['tasks']), 'дефолтный период табеля не должен скрывать будущие задачи сотрудника');

    $period = new ReflectionMethod(ReportController::class, 'reportPeriod');
    assert_same(['date_from' => '', 'date_to' => '', 'period' => 'all'], $period->invoke($controller, ['period' => 'all']), 'в отчёте по исполнителю должен быть настоящий сброс периода');

    $reportUsers = new ReflectionMethod(ReportController::class, 'reportUsers');
    $found = $reportUsers->invoke($controller, ['id' => 1, 'role' => 'director'], 'ОВ');
    assert_same(2, count($found), 'поиск ищет по ФИО и отделу');

    Database::reset();
});
