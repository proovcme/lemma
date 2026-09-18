<?php

declare(strict_types=1);

use App\Services\ProjectTaskExchangeService;

test('assignment-задача автоматически создаёт и обновляет одну строку обмена', function (): void {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, stage TEXT, file_folder_url TEXT)');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, department TEXT)');
    $pdo->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, project_id INTEGER, task_type TEXT, title TEXT, parent_id INTEGER, section TEXT, discipline TEXT, volume TEXT, status TEXT, date_start TEXT, date_end TEXT, created_at TEXT, author_id INTEGER, assignee_id INTEGER, reviewer_id INTEGER)');
    $pdo->exec('CREATE TABLE task_smart (task_id INTEGER PRIMARY KEY, what TEXT)');
    $pdo->exec('CREATE TABLE project_sections (id INTEGER PRIMARY KEY, task_id INTEGER, code TEXT, title TEXT, volume TEXT)');
    $pdo->exec('CREATE TABLE project_task_exchange (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, task_id INTEGER, direction TEXT, from_user_id INTEGER, to_user_id INTEGER, num INTEGER, assignment TEXT, from_section TEXT, to_section TEXT, file_url TEXT, date_issued TEXT, deadline TEXT, status TEXT, comments TEXT, UNIQUE(project_id, task_id))');
    $pdo->exec("INSERT INTO projects VALUES (3, 'РД', 'D:/P-3')");
    $pdo->exec("INSERT INTO users VALUES (10, 'Автор', 'АР'), (11, 'Исполнитель', 'ОВ')");
    $pdo->exec("INSERT INTO tasks VALUES (20, 3, 'assignment', 'Выдать задание ОВ', NULL, 'ОВ', '', '', 'new', '2026-08-21', '2026-08-30', '2026-08-21 10:00:00', 10, 11, NULL)");
    $pdo->exec("INSERT INTO task_smart VALUES (20, 'Подготовить исходные данные')");

    $service = new ProjectTaskExchangeService($pdo);
    assert_same(true, $service->syncTask(20));
    $row = $pdo->query('SELECT * FROM project_task_exchange WHERE task_id = 20')->fetch();
    assert_same('outgoing', $row['direction']);
    assert_same('Подготовить исходные данные', $row['assignment']);
    assert_same('АР', $row['from_section']);
    assert_same('ОВ', $row['to_section']);
    assert_same('pending', $row['status']);
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM project_task_exchange WHERE task_id = 20')->fetchColumn());

    $pdo->exec("UPDATE tasks SET status = 'done' WHERE id = 20");
    assert_same(true, $service->syncTask(20));
    assert_same('done', (string) $pdo->query('SELECT status FROM project_task_exchange WHERE task_id = 20')->fetchColumn());
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM project_task_exchange WHERE task_id = 20')->fetchColumn());
});

test('обычная задача не создаёт строку обмена', function (): void {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, task_type TEXT)');
    $pdo->exec("INSERT INTO tasks VALUES (1, 'work')");
    assert_same(false, (new ProjectTaskExchangeService($pdo))->syncTask(1));
});
