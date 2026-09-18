<?php

declare(strict_types=1);

use App\Core\Database;
use App\Controllers\AdminController;
use App\Controllers\ResourceController;
use App\Services\OrgService;
use App\Services\PermissionService;
use App\Services\ResourceService;

test('ResourceService раскладывает просрочку и задачи без дат в текущую корзину', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('CREATE TABLE employee_legal_entities (user_id INTEGER, daily_hours REAL, is_active INTEGER)');
    $pdo->exec('CREATE TABLE time_entries (user_id INTEGER, task_id INTEGER, work_date TEXT, minutes INTEGER, category TEXT)');
    $pdo->exec('CREATE TABLE task_participants (task_id INTEGER, user_id INTEGER, role TEXT)');
    $pdo->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, title TEXT, task_type TEXT DEFAULT "work", assignee_id INTEGER, planned_hours REAL, actual_hours REAL, date_start TEXT, date_end TEXT, status TEXT, project_id INTEGER)');

    $today = new DateTimeImmutable('today');
    $yesterday = $today->modify('-1 day')->format('Y-m-d');
    $pdo->exec("INSERT INTO tasks (id, title, assignee_id, planned_hours, actual_hours, date_start, date_end, status, project_id) VALUES
        (1, 'Просроченная задача', 10, 12, 2, NULL, '{$yesterday}', 'overdue', 1),
        (2, 'Без дат', 10, 8, 0, NULL, NULL, 'in_progress', 1),
        (3, 'Без оценки', 10, 0, 0, NULL, NULL, 'new', 1)");
    $pdo->exec("INSERT INTO time_entries (user_id, task_id, work_date, minutes, category) VALUES (10, 1, '{$yesterday}', 120, 'task')");

    $buckets = ResourceService::buckets('month', $today);
    $result = ResourceService::demand([10], $buckets, $pdo);
    $todayBucket = null;
    foreach ($buckets as $bucket) {
        if ($bucket['from'] <= $today->format('Y-m-d') && $today->format('Y-m-d') <= $bucket['to']) {
            $todayBucket = $bucket['key'];
            break;
        }
    }

    assert_true($todayBucket !== null, 'текущая корзина должна существовать');
    assert_same(18.0, (float) ($result['demand'][10][$todayBucket] ?? 0.0));
    assert_same(1, (int) ($result['unplanned'][10] ?? 0));

    Database::reset();
});

test('ResourceService делит плановый спрос между исполнителем и соавторами и не списывает чужой факт с исполнителя', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('CREATE TABLE time_entries (user_id INTEGER, task_id INTEGER, work_date TEXT, minutes INTEGER, category TEXT)');
    $pdo->exec('CREATE TABLE task_participants (task_id INTEGER, user_id INTEGER, role TEXT)');
    $pdo->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, title TEXT, task_type TEXT DEFAULT "work", assignee_id INTEGER, planned_hours REAL, actual_hours REAL, date_start TEXT, date_end TEXT, status TEXT, project_id INTEGER)');
    $today = new DateTimeImmutable('today');
    $start = $today->format('Y-m-d');
    $end = $today->modify('+2 weekday')->format('Y-m-d');
    $pdo->exec("INSERT INTO tasks (id, title, assignee_id, planned_hours, actual_hours, date_start, date_end, status, project_id) VALUES
        (10, 'Совместная задача', 10, 12, 4, '{$start}', '{$end}', 'in_progress', 1)");
    $pdo->exec("INSERT INTO task_participants (task_id, user_id, role) VALUES (10, 11, 'coauthor')");
    $pdo->exec("INSERT INTO time_entries (user_id, task_id, work_date, minutes, category) VALUES (11, 10, '{$start}', 240, 'task')");

    $buckets = [['key' => 'w', 'label' => 'w', 'from' => $start, 'to' => $end]];
    $result = ResourceService::demand([10, 11], $buckets, $pdo);

    assert_same(6.0, (float) ($result['demand'][10]['w'] ?? 0.0));
    assert_same(2.0, (float) ($result['demand'][11]['w'] ?? 0.0));

    Database::reset();
});

test('ResourceService capacity учитывает дневную норму и absence-категории', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('CREATE TABLE employee_legal_entities (user_id INTEGER, daily_hours REAL, is_active INTEGER)');
    $pdo->exec('CREATE TABLE time_entries (user_id INTEGER, work_date TEXT, minutes INTEGER, category TEXT)');
    $pdo->exec("INSERT INTO employee_legal_entities (user_id, daily_hours, is_active) VALUES (10, 6, 1)");
    $pdo->exec("INSERT INTO time_entries (user_id, work_date, minutes, category) VALUES (10, '2026-06-08', 180, 'vacation')");

    $buckets = [['key' => 'w', 'label' => 'w', 'from' => '2026-06-08', 'to' => '2026-06-12']];
    $capacity = ResourceService::capacity([10], $buckets, $pdo);

    assert_same(27.0, (float) $capacity[10]['w']);

    Database::reset();
});

test('ResourceController не отдаёт чужие проекты в фильтр обычному пользователю', function (): void {
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
        (1, 'OWN', 'Свой проект', 'active', NULL, NULL),
        (2, 'FOREIGN', 'Чужой проект', 'active', NULL, NULL),
        (3, 'ARCH', 'Архив', 'archived', NULL, NULL)");
    $pdo->exec("INSERT INTO tasks (id, project_id, assignee_id, author_id, reviewer_id) VALUES
        (1, 1, 10, 20, NULL),
        (2, 2, 20, 20, NULL),
        (3, 3, 10, 20, NULL)");

    $method = new ReflectionMethod(ResourceController::class, 'projects');
    $projects = $method->invoke(new ResourceController(), ['id' => 10, 'role' => 'engineer', 'department' => 'ОВ'], false);

    assert_same(['OWN'], array_column($projects, 'code'));

    Database::reset();
});

test('PermissionService даёт руководителю задачи подчинённых по оргцепочке', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, department TEXT, manager_id INTEGER)');
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, gip_user_id INTEGER, rp_user_id INTEGER)');
    $pdo->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, project_id INTEGER, assignee_id INTEGER, author_id INTEGER, reviewer_id INTEGER)');
    $pdo->exec('CREATE TABLE task_participants (task_id INTEGER, user_id INTEGER, role TEXT)');
    $pdo->exec("INSERT INTO users (id, role, department, manager_id) VALUES
        (10, 'group_lead', 'ОВ', NULL),
        (11, 'engineer', 'ОВ', 10),
        (12, 'engineer', 'ОВ', 11),
        (13, 'engineer', 'ВК', NULL)");
    $pdo->exec('INSERT INTO projects (id, gip_user_id, rp_user_id) VALUES (1, NULL, NULL)');
    $pdo->exec('INSERT INTO tasks (id, project_id, assignee_id, author_id, reviewer_id) VALUES
        (1, 1, 11, 13, NULL),
        (2, 1, 12, 13, NULL),
        (3, 1, 13, 13, NULL)');
    $pdo->exec("INSERT INTO task_participants (task_id, user_id, role) VALUES (2, 12, 'coauthor')");

    [$scope, $params] = PermissionService::taskScopeWhere(['id' => 10, 'role' => 'group_lead', 'department' => 'ОВ']);
    $stmt = $pdo->prepare('SELECT id FROM tasks t WHERE ' . $scope . ' ORDER BY id');
    $stmt->execute($params);
    assert_same([1, 2], array_map('intval', array_column($stmt->fetchAll(), 'id')));

    [$scope, $params] = PermissionService::taskScopeWhere(['id' => 10, 'role' => 'engineer', 'department' => 'ОВ']);
    $stmt = $pdo->prepare('SELECT id FROM tasks t WHERE ' . $scope . ' ORDER BY id');
    $stmt->execute($params);
    assert_same([], array_map('intval', array_column($stmt->fetchAll(), 'id')));

    Database::reset();
});

test('PermissionService даёт руководителю отдела задачи сотрудников отдела и своих проектов', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, department TEXT, manager_id INTEGER)');
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, gip_user_id INTEGER, rp_user_id INTEGER)');
    $pdo->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, project_id INTEGER, assignee_id INTEGER, author_id INTEGER, reviewer_id INTEGER)');
    $pdo->exec('CREATE TABLE task_participants (task_id INTEGER, user_id INTEGER, role TEXT)');
    $pdo->exec("INSERT INTO users (id, role, department, manager_id) VALUES
        (20, 'department_head', 'ОВ', NULL),
        (21, 'engineer', 'ОВ', 20),
        (22, 'engineer', 'СС', NULL),
        (23, 'engineer', 'ОВ', NULL)");
    $pdo->exec('INSERT INTO projects (id, gip_user_id, rp_user_id) VALUES
        (1, NULL, NULL),
        (2, NULL, 20)');
    $pdo->exec('INSERT INTO tasks (id, project_id, assignee_id, author_id, reviewer_id) VALUES
        (1, 1, 21, 22, NULL),
        (2, 1, 22, 23, NULL),
        (3, 1, 22, 22, 21),
        (4, 1, 22, 22, NULL),
        (5, 2, 22, 22, NULL)');
    $pdo->exec("INSERT INTO task_participants (task_id, user_id, role) VALUES (6, 21, 'coauthor')");
    $pdo->exec('INSERT INTO tasks (id, project_id, assignee_id, author_id, reviewer_id) VALUES (6, 1, 22, 22, NULL)');

    [$scope, $params] = PermissionService::taskScopeWhere(['id' => 20, 'role' => 'department_head', 'department' => 'ОВ']);
    $stmt = $pdo->prepare('SELECT id FROM tasks t WHERE ' . $scope . ' ORDER BY id');
    $stmt->execute($params);

    assert_same([1, 2, 3, 5, 6], array_map('intval', array_column($stmt->fetchAll(), 'id')));

    Database::reset();
});

test('PermissionService разводит оргподчинение и роль доступа', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, department TEXT, manager_id INTEGER)');
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, gip_user_id INTEGER, rp_user_id INTEGER)');
    $pdo->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, project_id INTEGER, assignee_id INTEGER, author_id INTEGER, reviewer_id INTEGER)');
    $pdo->exec('CREATE TABLE task_participants (task_id INTEGER, user_id INTEGER, role TEXT)');
    $pdo->exec("INSERT INTO users (id, role, department, manager_id) VALUES
        (30, 'engineer', 'ОВ', NULL),
        (31, 'engineer', 'ОВ', 30),
        (32, 'director', 'ДПР', NULL)");
    $pdo->exec('INSERT INTO projects (id, gip_user_id, rp_user_id) VALUES (1, NULL, NULL)');
    $pdo->exec('INSERT INTO tasks (id, project_id, assignee_id, author_id, reviewer_id) VALUES
        (1, 1, 30, 31, NULL),
        (2, 1, 31, 31, NULL)');

    [$engineerScope, $engineerParams] = PermissionService::taskScopeWhere(['id' => 30, 'role' => 'engineer', 'department' => 'ОВ']);
    $engineerStmt = $pdo->prepare('SELECT id FROM tasks t WHERE ' . $engineerScope . ' ORDER BY id');
    $engineerStmt->execute($engineerParams);
    assert_same([1], array_map('intval', array_column($engineerStmt->fetchAll(), 'id')), 'manager_id alone must not grant subordinate task scope');

    [$directorScope, $directorParams] = PermissionService::taskScopeWhere(['id' => 32, 'role' => 'director', 'department' => 'ДПР']);
    $directorStmt = $pdo->prepare('SELECT id FROM tasks t WHERE ' . $directorScope . ' ORDER BY id');
    $directorStmt->execute($directorParams);
    assert_same([1, 2], array_map('intval', array_column($directorStmt->fetchAll(), 'id')), 'director role must keep all-task scope without org-tree dependency');

    Database::reset();
});

test('OrgService строит дерево и не зацикливается на ошибочном manager_id', function (): void {
    $users = [
        ['id' => 1, 'name' => 'Директор', 'department' => 'ДПР', 'position_id' => 1, 'position_grade' => 'N-3', 'manager_id' => null],
        ['id' => 2, 'name' => 'Руководитель', 'department' => 'ОВ', 'position_id' => 2, 'position_grade' => 'N-7', 'manager_id' => 1],
        ['id' => 3, 'name' => 'Инженер', 'department' => 'ОВ', 'position_id' => 3, 'position_grade' => 'N-11', 'manager_id' => 2],
        ['id' => 4, 'name' => 'Сам себе', 'department' => 'ВК', 'position_id' => 3, 'position_grade' => 'N-11', 'manager_id' => 4],
    ];

    $tree = OrgService::tree($users);

    assert_same(2, count($tree), 'директор и самоссылка должны стать корнями');
    assert_same('Директор', (string) $tree[0]['name']);
    assert_same('Руководитель', (string) $tree[0]['children'][0]['name']);
    assert_same('Инженер', (string) $tree[0]['children'][0]['children'][0]['name']);
});

test('OrgService сортирует соседей в структуре по старшинству грейда', function (): void {
    $users = [
        ['id' => 1, 'name' => 'Инженер', 'department' => 'ОВ', 'position_id' => 10, 'position_grade' => 'N-11', 'manager_id' => null],
        ['id' => 2, 'name' => 'Директор Н2', 'department' => 'ДПР', 'position_id' => 20, 'position_grade' => 'Н2', 'manager_id' => null],
        ['id' => 3, 'name' => 'Директор Н1', 'department' => 'ДПР', 'position_id' => 30, 'position_grade' => 'Н1', 'manager_id' => null],
        ['id' => 4, 'name' => 'Директор департамента', 'department' => 'ДПР', 'position_id' => 40, 'position_grade' => 'N-3', 'manager_id' => null],
        ['id' => 5, 'name' => 'Стажер', 'department' => 'ОВ', 'position_id' => 50, 'position_grade' => 'N-ст', 'manager_id' => null],
        ['id' => 6, 'name' => 'Собственник', 'department' => 'ДПР', 'position_id' => 60, 'position_grade' => '0', 'manager_id' => null],
    ];

    $tree = OrgService::tree($users);

    assert_same(
        ['Собственник', 'Директор Н1', 'Директор Н2', 'Директор департамента', 'Инженер', 'Стажер'],
        array_map(static fn (array $node): string => (string) $node['name'], $tree)
    );
});

test('AdminController отклоняет циклы руководителей перед сохранением оргструктуры', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, manager_id INTEGER)');
    $pdo->exec('INSERT INTO users (id, manager_id) VALUES (1, NULL), (2, 1), (3, 2), (4, 5), (5, 4)');

    $method = new ReflectionMethod(AdminController::class, 'managerAssignmentCreatesCycle');
    $controller = new AdminController();

    assert_same(true, $method->invoke($controller, 1, 3), '1 -> 3 замкнёт цепочку 1 -> 3 -> 2 -> 1');
    assert_same(false, $method->invoke($controller, 3, 1), '3 -> 1 остаётся валидной иерархией');
    assert_same(true, $method->invoke($controller, 1, 4), 'нельзя присоединять сотрудника к уже циклической ветке');

    Database::reset();
});
