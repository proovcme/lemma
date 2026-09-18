<?php

declare(strict_types=1);

use App\Services\TaskWorkflowIntegrityService;
use App\Services\TaskWorkflowService;
use App\Core\Database;
use App\Controllers\TaskController;
use App\Services\TaskWorkflowAuditService;

test('закрытие и активное согласование не могут идти параллельно', function (): void {
    assert_same(false, TaskWorkflowIntegrityService::canStartClose([
        'status' => 'in_progress',
        'approval_stage' => 'review_gip',
        'close_requested_at' => null,
    ]));
    assert_same(false, TaskWorkflowIntegrityService::canContinueApproval([
        'status' => 'review',
        'approval_stage' => 'review_gip',
        'close_requested_at' => '2026-08-21 09:00:00',
    ]));
    assert_same(false, TaskWorkflowIntegrityService::canContinueApproval([
        'status' => 'done',
        'approval_stage' => 'review_lead',
        'close_requested_at' => null,
    ]));
});

test('согласование можно начать только вне проверки закрытия и терминального состояния', function (): void {
    assert_same(true, TaskWorkflowIntegrityService::canStartApproval([
        'status' => 'in_progress',
        'approval_stage' => 'draft',
        'close_requested_at' => null,
    ]));
    assert_same(false, TaskWorkflowIntegrityService::canStartApproval([
        'status' => 'review',
        'approval_stage' => 'draft',
        'close_requested_at' => '2026-08-21 09:00:00',
    ]));
    assert_same(false, TaskWorkflowIntegrityService::canStartApproval([
        'status' => 'done',
        'approval_stage' => 'issued',
        'close_requested_at' => null,
    ]));
});

test('перенос срока отклоняется для закрытой задачи и устаревшей исходной даты', function (): void {
    assert_same('task_terminal', TaskWorkflowIntegrityService::deadlineShiftBlockReason(
        ['status' => 'done', 'date_end' => '2026-08-21'],
        ['status' => 'pending', 'date_old' => '2026-08-21', 'date_new' => '2026-08-30']
    ));
    assert_same('stale_deadline', TaskWorkflowIntegrityService::deadlineShiftBlockReason(
        ['status' => 'in_progress', 'date_end' => '2026-08-25'],
        ['status' => 'pending', 'date_old' => '2026-08-21', 'date_new' => '2026-08-30']
    ));
    assert_same(null, TaskWorkflowIntegrityService::deadlineShiftBlockReason(
        ['status' => 'in_progress', 'date_end' => '2026-08-21'],
        ['status' => 'pending', 'date_old' => '2026-08-21', 'date_new' => '2026-08-30']
    ));
});

test('legacy-зависимость распознаётся в PHP без сравнения текстовых колонок СУБД', function (): void {
    assert_same(true, TaskWorkflowIntegrityService::isAmbiguousLegacyRelation([
        'parent_id' => 17,
        'task_type' => 'work',
        'depends_on' => ' 17 ',
    ]));
    assert_same(false, TaskWorkflowIntegrityService::isAmbiguousLegacyRelation([
        'parent_id' => 17,
        'task_type' => 'work',
        'depends_on' => '18',
    ]));
    assert_same(false, TaskWorkflowIntegrityService::isAmbiguousLegacyRelation([
        'parent_id' => 17,
        'task_type' => 'delegation',
        'depends_on' => '17',
    ]));
});

test('финальное закрытие атомарно разрешает остальные активные цепочки', function (): void {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE tasks (
        id INTEGER PRIMARY KEY,
        status TEXT,
        progress INTEGER,
        approval_stage TEXT,
        close_requested_at TEXT,
        closed_at TEXT,
        closed_by INTEGER,
        updated_at TEXT
    )');
    $pdo->exec('CREATE TABLE task_deadline_shifts (
        id INTEGER PRIMARY KEY,
        task_id INTEGER,
        status TEXT,
        reviewed_by INTEGER,
        reviewed_at TEXT,
        review_comment TEXT
    )');
    $pdo->exec('CREATE TABLE notifications (
        id INTEGER PRIMARY KEY,
        task_id INTEGER,
        type TEXT,
        read_at TEXT
    )');
    $pdo->exec("INSERT INTO tasks VALUES (7, 'review', 80, 'review_gip', '2026-08-21 09:00:00', NULL, NULL, '2026-08-21 09:00:00')");
    $pdo->exec("INSERT INTO task_deadline_shifts VALUES (3, 7, 'pending', NULL, NULL, NULL)");
    $pdo->exec("INSERT INTO notifications VALUES (4, 7, 'approval_review_gip', NULL)");
    $pdo->exec("INSERT INTO notifications VALUES (5, 7, 'deadline_shift_requested', NULL)");

    assert_same(true, TaskWorkflowIntegrityService::finalizeTask($pdo, 7, 42));
    $task = $pdo->query('SELECT * FROM tasks WHERE id = 7')->fetch();
    assert_same('done', $task['status']);
    assert_same(100, (int) $task['progress']);
    assert_same('approved', $task['approval_stage']);
    assert_same(null, $task['close_requested_at']);
    assert_same(42, (int) $task['closed_by']);
    assert_true(trim((string) $task['closed_at']) !== '');
    assert_same('rejected', (string) $pdo->query('SELECT status FROM task_deadline_shifts WHERE id = 3')->fetchColumn());
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM notifications WHERE task_id = 7 AND read_at IS NULL')->fetchColumn());
    assert_same(false, TaskWorkflowIntegrityService::finalizeTask($pdo, 7, 42));
});

test('прогресс родителя считает только структурных детей, а не legacy-зависимости', function (): void {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, parent_id INTEGER, task_type TEXT, progress INTEGER)');
    $pdo->exec('CREATE TABLE task_smart (task_id INTEGER PRIMARY KEY, depends_on TEXT)');
    $pdo->exec("INSERT INTO tasks VALUES (1, NULL, 'work', 5), (2, 1, 'work', 100), (3, 1, 'work', 40)");
    $pdo->exec("INSERT INTO task_smart VALUES (2, '1'), (3, '')");
    Database::useConnection($pdo);
    try {
        TaskWorkflowService::recomputeParentProgress(1);
        assert_same(40, (int) $pdo->query('SELECT progress FROM tasks WHERE id = 1')->fetchColumn());
    } finally {
        Database::reset();
    }
});

test('каскад подзадач не принимает legacy-зависимость за структурного потомка', function (): void {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, parent_id INTEGER, task_type TEXT)');
    $pdo->exec('CREATE TABLE task_smart (task_id INTEGER PRIMARY KEY, depends_on TEXT)');
    $pdo->exec("INSERT INTO tasks VALUES (1, NULL, 'work'), (2, 1, 'work'), (3, 1, 'work'), (4, 3, 'work')");
    $pdo->exec("INSERT INTO task_smart VALUES (2, '1'), (3, ''), (4, '')");
    Database::useConnection($pdo);
    try {
        $method = new ReflectionMethod(TaskController::class, 'taskDescendantIds');
        assert_same([3, 4], $method->invoke(new TaskController(), 1));
    } finally {
        Database::reset();
    }
});

test('аудит перечисляет невозможные активные цепочки и не считает legacy-связь потерей данных', function (): void {
    $report = TaskWorkflowAuditService::fromRows([
        'tasks' => [
            ['id' => 1, 'status' => 'done', 'approval_stage' => 'review_gip', 'close_requested_at' => null, 'closed_at' => '2026-08-21', 'date_end' => '2026-08-21', 'assignee_id' => 5, 'task_type' => 'work', 'parent_id' => null],
            ['id' => 2, 'status' => 'review', 'approval_stage' => 'review_lead', 'close_requested_at' => '2026-08-21', 'closed_at' => null, 'date_end' => '2026-08-25', 'assignee_id' => 6, 'task_type' => 'work', 'parent_id' => 1],
        ],
        'task_smart' => [
            ['task_id' => 2, 'depends_on' => '1'],
        ],
        'task_deadline_shifts' => [
            ['id' => 8, 'task_id' => 1, 'status' => 'pending', 'date_old' => '2026-08-21'],
            ['id' => 9, 'task_id' => 2, 'status' => 'pending', 'date_old' => '2026-08-20'],
        ],
        'task_participants' => [
            ['task_id' => 1, 'user_id' => 5, 'role' => 'coauthor'],
        ],
        'notifications' => [],
        'project_task_exchange' => [],
    ]);

    assert_same([1], $report['errors']['done_active_approval']);
    assert_same([2], $report['errors']['parallel_close_approval']);
    assert_same([8], $report['errors']['pending_shift_terminal']);
    assert_same([9], $report['errors']['pending_shift_stale']);
    assert_same(['1:5'], $report['errors']['assignee_participant_duplicate']);
    assert_same([2], $report['warnings']['ambiguous_legacy_relations']);
});
