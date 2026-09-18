<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\ProjectControlService;

test('ProjectControlService скрывает деньги без финансового права и показывает риски качества', function (): void {
    Database::reset();
    $pdo = project_control_test_db();
    project_control_seed($pdo);

    $control = (new ProjectControlService($pdo))->build(7, false);

    assert_true(!array_key_exists('budget', $control), 'budget block must not be returned without finance access');
    assert_same(3, (int) $control['work']['open_tasks']);
    assert_same(2, (int) $control['data']['tasks_without_btp']);
    assert_true((int) $control['quality']['score'] < 100);
    assert_true(count($control['risks']) >= 2);

    Database::reset();
});

test('ProjectControlService считает бюджет из времени, УТС и ручного бюджета', function (): void {
    Database::reset();
    $pdo = project_control_test_db();
    project_control_seed($pdo);

    $control = (new ProjectControlService($pdo))->build(7, true);

    assert_same(100.0, (float) $control['budget']['manual_thousand']);
    assert_same(10.0, (float) $control['budget']['time_actual_thousand']);
    assert_same(5.0, (float) $control['budget']['uts_actual_thousand']);
    assert_same(15.0, (float) $control['budget']['actual_total_thousand']);
    assert_same(85.0, (float) $control['budget']['remaining_thousand']);

    Database::reset();
});

function project_control_test_db(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, budget_manual_thousand REAL)');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, department TEXT)');
    $pdo->exec('CREATE TABLE employee_rates (user_id INTEGER PRIMARY KEY, hourly_rate REAL)');
    $pdo->exec('CREATE TABLE cfo_rates (dept_code TEXT PRIMARY KEY, hourly_rate REAL)');
    $pdo->exec('CREATE TABLE tasks (
        id INTEGER PRIMARY KEY,
        project_id INTEGER,
        parent_id INTEGER,
        status TEXT,
        task_type TEXT,
        planned_hours REAL,
        actual_hours REAL,
        progress INTEGER,
        date_end TEXT,
        pp_code_id INTEGER,
        btp_code_id INTEGER
    )');
    $pdo->exec('CREATE TABLE time_entries (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        project_id INTEGER,
        task_id INTEGER,
        minutes INTEGER,
        phase TEXT,
        status TEXT
    )');
    $pdo->exec('CREATE TABLE project_uts_facts (
        id INTEGER PRIMARY KEY,
        project_id INTEGER,
        pp_code_id INTEGER,
        btp_code_id INTEGER,
        amount REAL
    )');

    return $pdo;
}

function project_control_seed(PDO $pdo): void
{
    $pdo->exec('INSERT INTO projects (id, budget_manual_thousand) VALUES (7, 100)');
    $pdo->exec("INSERT INTO users (id, name, department) VALUES (2, 'Инженер', 'ОВ')");
    $pdo->exec('INSERT INTO employee_rates (user_id, hourly_rate) VALUES (2, 1000)');
    $pdo->exec("INSERT INTO cfo_rates (dept_code, hourly_rate) VALUES ('ОВ', 800)");
    $pdo->exec("INSERT INTO tasks
        (id, project_id, parent_id, status, task_type, planned_hours, actual_hours, progress, date_end, pp_code_id, btp_code_id)
        VALUES
        (1, 7, NULL, 'done', 'work', 8, 8, 100, '2026-06-20', 1, 1),
        (2, 7, NULL, 'correction', 'work', 8, 2, 40, '2026-06-21', 1, NULL),
        (3, 7, NULL, 'in_progress', 'work', 8, 0, 20, '2020-01-01', NULL, NULL),
        (4, 7, NULL, 'new', 'delegation', 100, 0, 0, '2026-06-30', NULL, NULL)");
    $pdo->exec("INSERT INTO time_entries (id, user_id, project_id, task_id, minutes, phase, status) VALUES
        (1, 2, 7, 1, 480, 'execution', 'approved'),
        (2, 2, 7, 2, 120, 'correction', 'draft')");
    $pdo->exec('INSERT INTO project_uts_facts (id, project_id, pp_code_id, btp_code_id, amount) VALUES (1, 7, 1, 1, 5000)');
}
