<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\ProcessControlService;

test('ProcessControlService считает локальные узкие места процесса', function (): void {
    Database::reset();
    $pdo = process_control_test_db();
    process_control_seed($pdo);

    $control = (new ProcessControlService($pdo))->project(10);

    assert_same(4, (int) $control['overall']['total_tasks'], 'delegation task must be excluded');
    assert_same(3, (int) $control['overall']['open_tasks']);
    assert_same(1, (int) $control['overall']['done_tasks']);
    assert_same(1, (int) $control['overall']['overdue_tasks']);
    assert_same(2, (int) $control['overall']['correction_loops']);
    assert_same(2.0, (float) $control['overall']['rework_hours']);
    assert_same(1, (int) $control['overall']['issuance_iterations']);
    assert_same(1, (int) $control['overall']['atlas_tasks']);
    assert_true(count($control['status_rows']) >= 3);
    assert_true(count($control['departments']) >= 1);
    assert_true(count($control['slow_tasks']) >= 2);
    assert_true(count($control['bottlenecks']) >= 2);

    Database::reset();
});

test('ProcessControlService уважает фильтры Штурмана', function (): void {
    Database::reset();
    $pdo = process_control_test_db();
    process_control_seed($pdo);

    $control = (new ProcessControlService($pdo))->dashboard(
        ['project_id' => '10', 'assignee_id' => '2', 'date_from' => '2020-01-01', 'date_to' => '2099-07-31'],
        ' AND p.id = :project_id',
        ['project_id' => 10]
    );

    assert_same(3, (int) $control['overall']['total_tasks']);
    assert_same(2, (int) $control['overall']['open_tasks']);
    assert_true(array_reduce(
        $control['slow_tasks'],
        static fn (bool $ok, array $row): bool => $ok && (int) ($row['project_id'] ?? 0) === 10,
        true
    ));

    Database::reset();
});

function process_control_test_db(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, code TEXT, title TEXT, status TEXT)');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, department TEXT)');
    $pdo->exec('CREATE TABLE tasks (
        id INTEGER PRIMARY KEY,
        project_id INTEGER,
        title TEXT,
        status TEXT,
        approval_stage TEXT,
        task_type TEXT,
        assignee_id INTEGER,
        created_at TEXT,
        updated_at TEXT,
        closed_at TEXT,
        close_requested_at TEXT,
        date_end TEXT
    )');
    $pdo->exec('CREATE TABLE task_logs (
        id INTEGER PRIMARY KEY,
        task_id INTEGER,
        field TEXT,
        old_val TEXT,
        new_val TEXT,
        created_at TEXT
    )');
    $pdo->exec('CREATE TABLE task_approvals (
        id INTEGER PRIMARY KEY,
        task_id INTEGER,
        decision TEXT,
        created_at TEXT
    )');
    $pdo->exec('CREATE TABLE task_issuances (
        id INTEGER PRIMARY KEY,
        task_id INTEGER,
        status TEXT
    )');
    $pdo->exec('CREATE TABLE time_entries (
        id INTEGER PRIMARY KEY,
        task_id INTEGER,
        minutes INTEGER,
        phase TEXT
    )');
    $pdo->exec('CREATE TABLE task_atlas_refs (
        id INTEGER PRIMARY KEY,
        task_id INTEGER
    )');

    return $pdo;
}

function process_control_seed(PDO $pdo): void
{
    $pdo->exec("INSERT INTO projects (id, code, title, status) VALUES
        (10, 'P-10', 'Проект', 'active'),
        (11, 'P-11', 'Другой проект', 'active')");
    $pdo->exec("INSERT INTO users (id, name, department) VALUES
        (2, 'Инженер ОВ', 'ОВ'),
        (3, 'Инженер ВК', 'ВК')");
    $pdo->exec("INSERT INTO tasks
        (id, project_id, title, status, approval_stage, task_type, assignee_id, created_at, updated_at, closed_at, close_requested_at, date_end)
        VALUES
        (1, 10, 'Закрытая работа', 'done', '', 'work', 2, '2026-06-01', '2026-06-03', '2026-06-03', NULL, '2026-06-04'),
        (2, 10, 'Ждет проверки', 'review', 'review_lead', 'work', 2, '2026-06-01', '2026-06-05', NULL, '2026-06-05', '2099-07-10'),
        (3, 10, 'Корректировка', 'correction', '', 'work', 2, '2026-06-02', '2026-06-07', NULL, NULL, '2020-01-01'),
        (4, 10, 'Выдача', 'in_progress', '', 'issuance', 3, '2026-06-01', '2026-06-08', NULL, NULL, '2099-07-20'),
        (5, 10, 'Делегирование', 'new', '', 'delegation', 3, '2026-06-01', '2026-06-01', NULL, NULL, '2099-07-20'),
        (6, 11, 'Чужая задача', 'in_progress', '', 'work', 2, '2026-06-01', '2026-06-01', NULL, NULL, '2099-07-20')");
    $pdo->exec("INSERT INTO task_logs (id, task_id, field, old_val, new_val, created_at) VALUES
        (1, 2, 'status', 'in_progress', 'review', '2026-06-05'),
        (2, 3, 'status', 'review', 'correction', '2026-06-06'),
        (3, 3, 'status', 'review', 'correction', '2026-06-07')");
    $pdo->exec("INSERT INTO task_approvals (id, task_id, decision, created_at) VALUES
        (1, 3, 'rejected', '2026-06-06')");
    $pdo->exec("INSERT INTO task_issuances (id, task_id, status) VALUES
        (1, 4, 'issued'),
        (2, 4, 'remarks')");
    $pdo->exec("INSERT INTO time_entries (id, task_id, minutes, phase) VALUES
        (1, 3, 120, 'correction'),
        (2, 3, 60, 'execution')");
    $pdo->exec('INSERT INTO task_atlas_refs (id, task_id) VALUES (1, 2)');
}
