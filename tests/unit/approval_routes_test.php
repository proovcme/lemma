<?php

declare(strict_types=1);

use App\Controllers\TaskController;
use App\Core\Database;
use App\Services\RoleService;

test('маршрут выдачи берёт наблюдателя как проверяющего, если явный проверяющий не задан', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, department TEXT, name TEXT, is_active INTEGER DEFAULT 1)');
    $pdo->exec('CREATE TABLE task_participants (task_id INTEGER, user_id INTEGER, role TEXT)');
    $pdo->exec("INSERT INTO users (id, role, department, name, is_active) VALUES
        (1, 'engineer', 'ОВ', 'Исполнитель', 1),
        (2, 'group_lead', 'ОВ', 'Наблюдатель-руководитель отдела', 1),
        (3, 'gip', 'ГИП', 'ГИП', 1)");
    $pdo->exec("INSERT INTO task_participants (task_id, user_id, role) VALUES (10, 2, 'observer')");

    $method = new ReflectionMethod(TaskController::class, 'approvalLeadReviewerId');
    $reviewerId = $method->invoke(new TaskController(), [
        'id' => 10,
        'task_type' => 'issuance',
        'assignee_id' => 1,
        'reviewer_id' => null,
        'project_gip_user_id' => 3,
    ]);

    assert_same(2, $reviewerId);

    Database::reset();
});

test('маршрут выдачи пропускает РГ, если проверяющий совпадает с ГИПом', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, department TEXT, name TEXT, is_active INTEGER DEFAULT 1)');
    $pdo->exec('CREATE TABLE task_participants (task_id INTEGER, user_id INTEGER, role TEXT)');

    $method = new ReflectionMethod(TaskController::class, 'approvalLeadReviewerId');
    $reviewerId = $method->invoke(new TaskController(), [
        'id' => 11,
        'task_type' => 'issuance',
        'assignee_id' => 1,
        'reviewer_id' => 3,
        'project_gip_user_id' => 3,
    ]);

    assert_same(null, $reviewerId);

    Database::reset();
});

test('маршрут выдачи не назначает финального согласующего промежуточным проверяющим', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, department TEXT, name TEXT, is_active INTEGER DEFAULT 1)');
    $pdo->exec('CREATE TABLE task_participants (task_id INTEGER, user_id INTEGER, role TEXT)');
    $pdo->exec("INSERT INTO users (id, role, department, name, is_active) VALUES
        (1, 'engineer', 'ОВ', 'Исполнитель', 1),
        (3, 'gip', 'ГИП', 'ГИП-наблюдатель', 1)");
    $pdo->exec("INSERT INTO task_participants (task_id, user_id, role) VALUES (12, 3, 'observer')");

    $controller = new TaskController();
    $leadMethod = new ReflectionMethod(TaskController::class, 'approvalLeadReviewerId');
    $gipMethod = new ReflectionMethod(TaskController::class, 'approvalGipApproverId');
    $task = [
        'id' => 12,
        'task_type' => 'issuance',
        'assignee_id' => 1,
        'assignee_role' => RoleService::ENGINEER,
        'reviewer_id' => null,
        'reviewer_role' => null,
        'project_gip_user_id' => null,
    ];

    assert_same(null, $leadMethod->invoke($controller, $task));
    assert_same(3, $gipMethod->invoke($controller, $task));

    Database::reset();
});

test('маршрут выдачи ведёт через главспеца, руководителя группы и руководителя отдела по порядку', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, department TEXT, name TEXT, is_active INTEGER DEFAULT 1)');
    $pdo->exec('CREATE TABLE task_participants (task_id INTEGER, user_id INTEGER, role TEXT)');
    $pdo->exec('CREATE TABLE task_approvals (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER, stage TEXT, approved_by INTEGER, decision TEXT, comment TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE task_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER, user_id INTEGER, field TEXT, old_val TEXT, new_val TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("INSERT INTO users (id, role, department, name, is_active) VALUES
        (1, 'engineer', 'ОВ', 'Исполнитель', 1),
        (2, 'chief_specialist', 'ОВ', 'Главспец', 1),
        (3, 'group_lead', 'ОВ', 'Рук группы', 1),
        (4, 'department_head', 'ОВ', 'Рук отдела', 1),
        (5, 'gip', 'ГИП', 'ГИП', 1)");
    $pdo->exec("INSERT INTO task_participants (task_id, user_id, role) VALUES
        (13, 3, 'observer'),
        (13, 4, 'observer')");

    $controller = new TaskController();
    $leadMethod = new ReflectionMethod(TaskController::class, 'approvalLeadReviewerId');
    $nextMethod = new ReflectionMethod(TaskController::class, 'nextApprovalCentralReviewerId');
    $task = [
        'id' => 13,
        'task_type' => 'issuance',
        'assignee_id' => 1,
        'assignee_role' => RoleService::ENGINEER,
        'reviewer_id' => 2,
        'reviewer_role' => RoleService::CHIEF_SPECIALIST,
        'reviewer_name' => 'Главспец',
        'project_gip_user_id' => 5,
    ];
    $pdo->exec("INSERT INTO task_logs (task_id, user_id, field, old_val, new_val, created_at) VALUES
        (13, 1, 'approval_stage', 'draft', 'review_lead', '2026-02-01 09:00:00')");
    $pdo->exec("INSERT INTO task_approvals (task_id, stage, approved_by, decision, comment, created_at) VALUES
        (13, 'review_lead', 3, 'approved', 'старый цикл', '2026-01-01 09:00:00')");

    assert_same(2, $leadMethod->invoke($controller, $task));
    assert_same(3, $nextMethod->invoke($controller, $task, 2));

    $pdo->exec("INSERT INTO task_approvals (task_id, stage, approved_by, decision, comment) VALUES (13, 'review_lead', 2, 'approved', '')");
    assert_same(4, $nextMethod->invoke($controller, $task, 3));

    $pdo->exec("INSERT INTO task_approvals (task_id, stage, approved_by, decision, comment) VALUES (13, 'review_lead', 3, 'approved', '')");
    assert_same(null, $nextMethod->invoke($controller, $task, 4));

    Database::reset();
});

test('BIM-менеджер и ГИП могут быть собственным согласующим выдачи, инженер не может', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, department TEXT, name TEXT, is_active INTEGER DEFAULT 1)');
    $pdo->exec('CREATE TABLE task_approvals (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER, stage TEXT, approved_by INTEGER, decision TEXT, comment TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $controller = new TaskController();
    $approverMethod = new ReflectionMethod(TaskController::class, 'approvalGipApproverId');
    $canGipMethod = new ReflectionMethod(TaskController::class, 'canGipApprove');

    $bimTask = [
        'id' => 20,
        'task_type' => 'issuance',
        'project_status' => 'active',
        'assignee_id' => 5,
        'assignee_role' => RoleService::BIM_MANAGER,
        'project_gip_user_id' => null,
    ];
    assert_same(5, $approverMethod->invoke($controller, $bimTask));
    assert_same(true, $canGipMethod->invoke($controller, ['id' => 5, 'role' => RoleService::BIM_MANAGER], $bimTask));

    $bimTaskWithExternalGip = $bimTask;
    $bimTaskWithExternalGip['project_gip_user_id'] = 7;
    assert_same(7, $approverMethod->invoke($controller, $bimTaskWithExternalGip));
    assert_same(false, $canGipMethod->invoke($controller, ['id' => 5, 'role' => RoleService::BIM_MANAGER], $bimTaskWithExternalGip));
    assert_same(false, $canGipMethod->invoke($controller, ['id' => 8, 'role' => RoleService::GIP], $bimTaskWithExternalGip));
    assert_same(true, $canGipMethod->invoke($controller, ['id' => 7, 'role' => RoleService::GIP], $bimTaskWithExternalGip));

    $engineerTask = $bimTask;
    $engineerTask['assignee_id'] = 6;
    $engineerTask['assignee_role'] = RoleService::ENGINEER;
    assert_same(null, $approverMethod->invoke($controller, $engineerTask));
    assert_same(false, $canGipMethod->invoke($controller, ['id' => 6, 'role' => RoleService::ENGINEER], $engineerTask));

    Database::reset();
});
