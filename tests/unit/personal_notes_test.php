<?php

declare(strict_types=1);

use App\Core\Database;
use App\Controllers\NotesController;
use App\Services\PersonalNoteService;

test('NotesController ограничивает проекты заметок пользовательским scope', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, code TEXT, title TEXT, status TEXT, gip_user_id INTEGER, rp_user_id INTEGER)');
    $pdo->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, project_id INTEGER, assignee_id INTEGER, author_id INTEGER, reviewer_id INTEGER)');
    $pdo->exec('CREATE TABLE task_participants (task_id INTEGER, user_id INTEGER)');
    $pdo->exec("INSERT INTO projects (id, code, title, status, gip_user_id, rp_user_id) VALUES
        (7, 'P7', 'Своя задача', 'active', NULL, NULL),
        (8, 'P8', 'Чужой проект', 'active', NULL, NULL),
        (9, 'P9', 'Проект ГИПа', 'active', 3, NULL),
        (10, 'P10', 'Архив', 'archived', 3, NULL)");
    $pdo->exec("INSERT INTO tasks (id, project_id, assignee_id, author_id, reviewer_id) VALUES
        (1, 7, 3, 4, NULL),
        (2, 8, 4, 4, NULL)");

    $method = new ReflectionMethod(NotesController::class, 'visibleProjectIds');
    $ids = $method->invoke(new NotesController(), ['id' => 3, 'role' => 'engineer', 'department' => 'ОВ']);
    sort($ids);

    assert_same([7, 9], $ids);

    Database::reset();
});

test('PersonalNoteService создаёт личную заметку и превращает её в задачу', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, code TEXT, title TEXT, status TEXT)');
    $pdo->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, task_type TEXT, project_id INTEGER, assignee_id INTEGER, author_id INTEGER, status TEXT, priority TEXT, urgency TEXT, date_start TEXT, date_end TEXT, date_end_original TEXT, planned_hours REAL, progress INTEGER)');
    $pdo->exec('CREATE TABLE task_smart (task_id INTEGER PRIMARY KEY, what TEXT, when_due TEXT, why TEXT, depends_on TEXT)');
    $pdo->exec('CREATE TABLE personal_notes (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INTEGER, project_id INTEGER, converted_task_id INTEGER, title TEXT, body TEXT, color TEXT, status TEXT DEFAULT "active", pinned INTEGER DEFAULT 0, converted_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("INSERT INTO projects (id, code, title, status) VALUES (7, 'P7', 'Проект', 'active')");

    $noteId = PersonalNoteService::create($pdo, 3, [
        'title' => 'Разобрать входящие',
        'body' => 'Проверить письма и выписать действия',
        'project_id' => 7,
        'color' => 'blue',
        'pinned' => 1,
    ]);
    $note = PersonalNoteService::find($pdo, $noteId, 3);

    assert_true(is_array($note));
    assert_same('Разобрать входящие', (string) $note['title']);

    $taskId = PersonalNoteService::convertToTask($pdo, $note, 3, [
        'project_id' => 7,
        'assignee_id' => 3,
        'date_end' => '2026-06-20',
        'planned_hours' => '2',
    ]);

    assert_same(1, $taskId);
    assert_same('converted', (string) $pdo->query('SELECT status FROM personal_notes WHERE id = 1')->fetchColumn());
    assert_same('work', (string) $pdo->query('SELECT task_type FROM tasks WHERE id = 1')->fetchColumn());
    assert_same('Проверить письма и выписать действия', (string) $pdo->query('SELECT what FROM task_smart WHERE task_id = 1')->fetchColumn());

    Database::reset();
});
