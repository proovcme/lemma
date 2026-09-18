<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\MonthlyWorkLedgerPreferenceService;
use App\Services\MonthlyWorkLedgerService;

function monthly_ledger_test_pdo(): PDO
{
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, tab_number TEXT, name TEXT, department TEXT, role TEXT, is_active INTEGER DEFAULT 1, manager_id INTEGER);');
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, code TEXT, title TEXT, gip_user_id INTEGER, rp_user_id INTEGER);');
    $pdo->exec('CREATE TABLE project_pp_codes (id INTEGER PRIMARY KEY, project_id INTEGER, code TEXT, title TEXT);');
    $pdo->exec('CREATE TABLE project_btp_codes (id INTEGER PRIMARY KEY, project_id INTEGER, pp_code_id INTEGER, code TEXT, title TEXT);');
    $pdo->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, title TEXT, project_id INTEGER, assignee_id INTEGER, section TEXT, discipline TEXT, status TEXT, pp_code_id INTEGER, btp_code_id INTEGER, btp TEXT);');
    $pdo->exec('CREATE TABLE time_entries (id INTEGER PRIMARY KEY, user_id INTEGER, project_id INTEGER, task_id INTEGER, work_date TEXT, minutes INTEGER, status TEXT DEFAULT "approved");');
    $pdo->exec('CREATE TABLE monthly_work_ledger_preferences (user_id INTEGER PRIMARY KEY, visible_fields TEXT NOT NULL, field_order TEXT NOT NULL, default_group_by TEXT NOT NULL, filters_json TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);');
    $pdo->exec("INSERT INTO users (id, tab_number, name, department, role) VALUES (1, '0001', 'Директор', 'Дирекция', 'director'), (2, '0025', 'Иванов', 'ОВ', 'engineer'), (3, '0042', 'Петров', 'АР', 'engineer')");
    $pdo->exec("INSERT INTO projects (id, code, title) VALUES (10, 'P-10', 'Проект 10')");
    $pdo->exec("INSERT INTO project_pp_codes (id, project_id, code, title) VALUES (100, 10, 'ПП-1', 'Договор')");
    $pdo->exec("INSERT INTO project_btp_codes (id, project_id, pp_code_id, code, title) VALUES (200, 10, 100, 'БТП-1', 'Разработка')");
    $pdo->exec("INSERT INTO tasks (id, title, project_id, assignee_id, section, discipline, status, pp_code_id, btp_code_id) VALUES (1000, 'Задача с фактом', 10, 2, 'ОВ', 'ОВ', 'in_progress', 100, 200), (1001, 'Задача без факта', 10, 3, 'АР', 'АР', 'new', NULL, NULL)");
    $pdo->exec("INSERT INTO time_entries (id, user_id, project_id, task_id, work_date, minutes) VALUES (1, 2, 10, 1000, '2026-08-05', 120), (2, 2, 10, 1000, '2026-08-06', 60), (3, 2, 10, NULL, '2026-08-07', 30)");

    return $pdo;
}

test('месячный реестр сохраняет задачу без списаний и каждую строку факта', function (): void {
    $service = new MonthlyWorkLedgerService(monthly_ledger_test_pdo());
    $report = $service->report(['id' => 1, 'role' => 'director'], ['date_from' => '2026-08-01', 'date_to' => '2026-08-31']);

    $withoutTime = array_values(array_filter($report['rows'], static fn (array $row): bool => (int) $row['task_id'] === 1001));
    $withTime = array_values(array_filter($report['rows'], static fn (array $row): bool => (int) $row['task_id'] === 1000));
    assert_same(1, count($withoutTime));
    assert_same(null, $withoutTime[0]['hours']);
    assert_same(2, count($withTime));
    assert_same([2.0, 1.0], array_column($withTime, 'hours'));
});

test('настройки реестра персональны и принимают только разрешенные поля', function (): void {
    $preferences = new MonthlyWorkLedgerPreferenceService(monthly_ledger_test_pdo());
    $saved = $preferences->save(2, [
        'visible_fields' => ['employee', 'hours', 'not_a_database_column'],
        'field_order' => ['hours', 'employee', 'not_a_database_column'],
        'default_group_by' => 'people',
        'filters' => ['department' => 'ОВ', 'unsafe' => 'ignored'],
    ]);

    assert_same(['employee', 'hours'], $saved['visible_fields']);
    assert_same(['hours', 'employee'], $saved['field_order']);
    assert_same('people', $saved['default_group_by']);
    assert_same(['department' => 'ОВ'], $saved['filters']);
    assert_same(MonthlyWorkLedgerPreferenceService::defaults(), $preferences->load(3));
    assert_same($saved, $preferences->load(2));
});

test('группировка не скрывает детализацию, а фильтр применяется после безопасного контура доступа', function (): void {
    $service = new MonthlyWorkLedgerService(monthly_ledger_test_pdo());
    $report = $service->report(['id' => 1, 'role' => 'director'], [
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
        'group_by' => 'people',
        'department' => 'ОВ',
    ]);

    assert_same('people', $report['group_by']);
    assert_same(3, count($report['rows']));
    assert_same(1, count($report['groups']));
    assert_same('ОВ · Иванов', $report['groups'][0]['label']);
    assert_same([1000, 1000, null], array_column($report['rows'], 'task_id'));
});

test('месячный экран и выгрузки подключены отдельными маршрутами', function (): void {
    $routes = (string) file_get_contents(BASE_PATH . '/public/index.php');
    $controller = (string) file_get_contents(BASE_PATH . '/app/Controllers/ReportController.php');
    assert_true(str_contains($routes, "'/reports/monthly'"));
    assert_true(str_contains($routes, "'/reports/monthly/export'"));
    assert_true(str_contains($controller, 'function exportMonthly'));
    assert_true(str_contains($controller, 'function saveMonthlySettings'));
});

test('миграция личных настроек совместима с unsigned ключом пользователей MariaDB', function (): void {
    $migration = (string) file_get_contents(BASE_PATH . '/database/migrations/100_monthly_work_ledger_preferences.sql');
    assert_true(str_contains($migration, 'user_id BIGINT UNSIGNED NOT NULL'));
    assert_true(str_contains($migration, 'REFERENCES users(id) ON DELETE CASCADE'));
});

test('месячный реестр сохраняет ПП БТП статус и legacy факт без задачи', function (): void {
    $service = new MonthlyWorkLedgerService(monthly_ledger_test_pdo());
    $report = $service->report(['id' => 1, 'role' => 'director'], ['date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'group_by' => 'project']);
    $task = array_values(array_filter($report['rows'], static fn (array $row): bool => (int) $row['task_id'] === 1000))[0];
    $legacy = array_values(array_filter($report['rows'], static fn (array $row): bool => $row['task'] === 'Без задачи'))[0];
    assert_same('ПП-1 · Договор', $task['pp']);
    assert_same('БТП-1 · Разработка', $task['btp']);
    assert_same('В работе', $task['status']);
    assert_same(0.5, $legacy['hours']);
    assert_same('P-10 · Проект 10', $legacy['project']);
});

test('месячный реестр показывает табельный номер списавшего или назначенного сотрудника', function (): void {
    $service = new MonthlyWorkLedgerService(monthly_ledger_test_pdo());
    $report = $service->report(['id' => 1, 'role' => 'director'], ['date_from' => '2026-08-01', 'date_to' => '2026-08-31']);

    $withFact = array_values(array_filter($report['rows'], static fn (array $row): bool => (int) $row['task_id'] === 1000))[0];
    $withoutFact = array_values(array_filter($report['rows'], static fn (array $row): bool => (int) $row['task_id'] === 1001))[0];

    assert_same('0025', $withFact['tab_number'] ?? null);
    assert_same('0042', $withoutFact['tab_number'] ?? null);
    assert_same('Табельный номер', MonthlyWorkLedgerPreferenceService::FIELD_CATALOG['tab_number'] ?? null);
    assert_true(in_array('tab_number', MonthlyWorkLedgerService::BASE_FIELDS, true));
});
