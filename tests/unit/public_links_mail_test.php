<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\NotificationOutboxService;
use App\Services\PublicLinkService;

test('PublicLinkService создаёт стабильные красивые ссылки', function (): void {
    Database::reset();
    $pdo = public_links_test_db();

    $project = PublicLinkService::ensureProjectLink(7, 'SHTAB-119296 Кампус', 1);
    $again = PublicLinkService::ensureProjectLink(7, 'SHTAB-119296 Кампус', 1);
    $task = PublicLinkService::ensureTaskLink(42, '#42 Управление проектом', 1);
    $model = PublicLinkService::ensureFolderModelLink(7, 'models/Кампус.ifc', 'Кампус IFC', 1);

    assert_same((string) $project['token'], (string) $again['token']);
    assert_true(str_starts_with((string) $project['token'], 'shtab-119296-kampus-'));
    assert_true(str_contains(PublicLinkService::publicUrl($project), '/p/'));
    assert_true(str_contains(PublicLinkService::publicUrl($task), '/t/'));
    assert_true(str_contains(PublicLinkService::publicUrl($model), '/m/'));

    PublicLinkService::markAccess((int) $project['id']);
    assert_same(1, (int) $pdo->query('SELECT access_count FROM public_links WHERE id = 1')->fetchColumn());

    Database::reset();
});

test('NotificationOutboxService ставит письмо о новой задаче в очередь с короткой ссылкой', function (): void {
    Database::reset();
    $pdo = public_links_test_db();
    $pdo->exec("INSERT INTO users (id, name, email) VALUES
        (1, 'Постановщик', 'author@example.test'),
        (2, 'Исполнитель', 'assignee@example.test'),
        (3, 'Проверяющий', 'reviewer@example.test')");
    $pdo->exec("INSERT INTO projects (id, code, title) VALUES (7, 'SHTAB-119296', 'Кампус')");
    $pdo->exec("INSERT INTO tasks
        (id, project_id, title, status, task_type, assignee_id, author_id, reviewer_id, date_end, planned_hours)
        VALUES
        (42, 7, 'Управление проектом', 'new', 'work', 2, 1, 3, '2026-06-30', 8)");

    NotificationOutboxService::queueTaskCreated(42, 1);
    NotificationOutboxService::queueTaskCreated(42, 1);

    $rows = $pdo->query('SELECT * FROM notification_outbox')->fetchAll(PDO::FETCH_ASSOC);
    assert_same(1, count($rows), 'повторный вызов не дублирует письмо');
    assert_same('assignee@example.test', (string) $rows[0]['recipient_email']);
    assert_true(str_contains((string) $rows[0]['subject'], 'Новая задача #42'));
    assert_true(str_contains((string) $rows[0]['body'], '/t/'));
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM public_links WHERE kind = "task" AND task_id = 42')->fetchColumn());

    Database::reset();
});

test('NotificationOutboxService ставит письмо о проверке и согласовании без дублей', function (): void {
    Database::reset();
    $pdo = public_links_test_db();
    $pdo->exec("INSERT INTO users (id, name, email) VALUES
        (1, 'Постановщик', 'author@example.test'),
        (2, 'Исполнитель', 'assignee@example.test'),
        (3, 'Проверяющий', 'reviewer@example.test')");
    $pdo->exec("INSERT INTO projects (id, code, title) VALUES (7, 'SHTAB-119296', 'Кампус')");
    $pdo->exec("INSERT INTO tasks
        (id, project_id, title, status, task_type, assignee_id, author_id, reviewer_id, date_end, planned_hours)
        VALUES
        (42, 7, 'Управление проектом', 'review', 'issuance', 2, 1, 3, '2026-06-30', 8)");

    NotificationOutboxService::queueTaskReviewSubmitted(42, 3, 1);
    NotificationOutboxService::queueTaskReviewSubmitted(42, 3, 1);
    NotificationOutboxService::queueTaskApprovalRequested(42, 3, 1, 'ГИП');
    NotificationOutboxService::queueTaskApprovalRequested(42, 3, 1, 'ГИП');

    $rows = $pdo->query('SELECT type, recipient_email, subject, body FROM notification_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    assert_same(2, count($rows), 'review and approval events are deduped independently');
    assert_same('task_review_submitted', (string) $rows[0]['type']);
    assert_same('task_approval_requested', (string) $rows[1]['type']);
    assert_same('reviewer@example.test', (string) $rows[1]['recipient_email']);
    assert_true(str_contains((string) $rows[1]['body'], '/t/'));

    Database::reset();
});

function public_links_test_db(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, code TEXT, title TEXT)');
    $pdo->exec('CREATE TABLE project_model_links (id INTEGER PRIMARY KEY, project_id INTEGER, title TEXT, model_url TEXT, kind TEXT, model_scope TEXT NOT NULL DEFAULT "project")');
    $pdo->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, project_id INTEGER, title TEXT, status TEXT, task_type TEXT, assignee_id INTEGER, author_id INTEGER, reviewer_id INTEGER, date_end TEXT, planned_hours REAL)');
    $pdo->exec('CREATE TABLE public_links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        kind TEXT NOT NULL,
        token TEXT NOT NULL UNIQUE,
        project_id INTEGER,
        task_id INTEGER,
        model_link_id INTEGER,
        model_path TEXT,
        label TEXT NOT NULL,
        created_by INTEGER,
        access_count INTEGER NOT NULL DEFAULT 0,
        last_accessed_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
    $pdo->exec('CREATE TABLE notification_outbox (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL,
        entity_id INTEGER,
        recipient_email TEXT NOT NULL,
        subject TEXT NOT NULL,
        body TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "pending",
        attempts INTEGER NOT NULL DEFAULT 0,
        dedupe_key TEXT NOT NULL UNIQUE,
        last_error TEXT,
        sent_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    return $pdo;
}
