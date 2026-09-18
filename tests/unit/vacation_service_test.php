<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\TaskWorkflowService;
use App\Services\VacationService;
use App\Services\WorkdayService;

function vacation_test_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, is_active INTEGER, department TEXT)');
    $pdo->exec('CREATE TABLE employee_vacations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        date_from TEXT NOT NULL,
        date_to TEXT NOT NULL,
        substitute_user_id INTEGER NOT NULL,
        note TEXT,
        created_by INTEGER NOT NULL,
        cancelled_at TEXT,
        cancelled_by INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, task_id INTEGER, type TEXT, body TEXT, read_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE push_subscriptions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, is_active INTEGER DEFAULT 1)');
    $pdo->exec('CREATE TABLE time_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, work_date TEXT, category TEXT, minutes INTEGER)');
    $pdo->exec("INSERT INTO users (id, name, is_active, department) VALUES
        (1, 'Сотрудник', 1, 'ОВ'),
        (2, 'Заместитель', 1, 'ОВ'),
        (3, 'Недоступный', 0, 'ОВ')");

    return $pdo;
}

test('сотрудник задаёт отпуск с обязательной действующей заменой', function (): void {
    $pdo = vacation_test_pdo();
    $service = new VacationService($pdo);
    $from = date('Y-m-d', strtotime('+2 days'));
    $to = date('Y-m-d', strtotime('+8 days'));

    $id = $service->create(1, $from, $to, 2, 'Передать текущие задачи', 1);
    assert_true($id > 0);
    $rows = $service->forUser(1);
    assert_same(1, count($rows));
    assert_same('Заместитель', $rows[0]['substitute_name']);
    assert_same('Передать текущие задачи', $rows[0]['note']);

    assert_throws(fn () => $service->create(1, $from, $to, 2, '', 1), InvalidArgumentException::class);
    assert_throws(fn () => $service->create(1, date('Y-m-d'), date('Y-m-d'), 1, '', 1), InvalidArgumentException::class);
    assert_throws(fn () => $service->create(1, date('Y-m-d'), date('Y-m-d'), 3, '', 1), InvalidArgumentException::class);
});

test('плановый отпуск уменьшает доступность команды и не дублирует табельное отсутствие', function (): void {
    $pdo = vacation_test_pdo();
    $pdo->exec("INSERT INTO employee_vacations (user_id, date_from, date_to, substitute_user_id, created_by)
        VALUES (1, '2026-08-03', '2026-08-07', 2, 1)");
    $pdo->exec("INSERT INTO time_entries (user_id, work_date, category, minutes)
        VALUES (1, '2026-08-03', 'vacation', 240)");

    $load = [1 => [
        'absence' => [],
        'absence_hours' => 0.0,
    ]];
    $method = new ReflectionMethod(WorkdayService::class, 'applyAbsences');
    $method->invokeArgs(new WorkdayService($pdo), [&$load, '2026-08-03', '2026-08-07']);

    assert_same(36.0, (float) $load[1]['absence']['vacation']);
    assert_same(36.0, (float) $load[1]['absence_hours']);
});

test('активный отпуск находится для уведомлений заместителю и может быть отменён владельцем', function (): void {
    $pdo = vacation_test_pdo();
    Database::useConnection($pdo);
    $service = new VacationService($pdo);
    $id = $service->create(1, date('Y-m-d'), date('Y-m-d', strtotime('+3 days')), 2, '', 1);

    $active = VacationService::activeSubstitute(1, $pdo);
    assert_same(2, (int) ($active['substitute_user_id'] ?? 0));
    assert_same('Сотрудник', $active['absent_name']);
    assert_same(true, VacationService::isActiveSubstituteFor(2, 1, $pdo));
    TaskWorkflowService::notify(1, 42, 'task_changed', 'Изменена задача #42.');
    $notifications = $pdo->query('SELECT user_id, type, body FROM notifications ORDER BY id')->fetchAll();
    assert_same(2, count($notifications));
    assert_same(1, (int) $notifications[0]['user_id']);
    assert_same(2, (int) $notifications[1]['user_id']);
    assert_same('vacation_substitute_task_changed', $notifications[1]['type']);
    assert_true(str_contains($notifications[1]['body'], 'Замещение Сотрудник'));
    assert_same(true, $service->cancel($id, 1, 1));
    assert_same(null, VacationService::activeSubstitute(1, $pdo));
    assert_same(false, VacationService::isActiveSubstituteFor(2, 1, $pdo));
    assert_same(false, $service->cancel($id, 1, 1));

    Database::reset();
});
