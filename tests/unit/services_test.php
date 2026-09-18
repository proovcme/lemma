<?php

declare(strict_types=1);

use App\Core\Database;
use App\Controllers\ProjectController;
use App\Services\PermissionService;
use App\Services\ProjectAccountingService;
use App\Services\RoleService;
use App\Services\TaskWorkflowService;
use App\Services\TimeService;

/**
 * Сервис-тесты бизнес-логики. RoleService — чистые методы (без БД);
 * TaskWorkflowService::defaultReviewerId — через инжект in-memory SQLite
 * (минимальная таблица users), демонстрирует паттерн DB-backed теста.
 */

// --- RoleService: нормализация алиасов, уровни, лестница ---
test('RoleService::normalize разворачивает алиасы', function (): void {
    assert_same(RoleService::ENGINEER, RoleService::normalize('designer'));
    assert_same(RoleService::GROUP_LEAD, RoleService::normalize('lead'));
    assert_same(RoleService::DEPARTMENT_HEAD, RoleService::normalize('head'));
    assert_same(RoleService::GIP, RoleService::normalize('gip'), 'неалиас остаётся как есть');
    assert_same('', RoleService::normalize(null), 'null → пустая строка');
});

test('RoleService::level и atLeast соблюдают иерархию', function (): void {
    assert_same(10, RoleService::level('engineer'));
    assert_same(30, RoleService::level('group_lead'));
    assert_same(50, RoleService::level('gip'));
    assert_same(35, RoleService::level('bim_manager'));
    assert_same(70, RoleService::level('director'));
    assert_same(10, RoleService::level('designer'), 'через алиас');

    assert_same(true, RoleService::atLeast('gip', 'group_lead'), 'ГИП ≥ рук. группы');
    assert_same(true, RoleService::atLeast('director', 'director'), 'равенство проходит');
    assert_same(false, RoleService::atLeast('engineer', 'gip'), 'инженер < ГИП');
    assert_same(false, RoleService::atLeast(null, 'engineer'), 'null роль не проходит');
});

test('RoleService::isAny учитывает алиасы', function (): void {
    assert_same(true, RoleService::isAny('designer', [RoleService::ENGINEER]));
    assert_same(true, RoleService::isAny('gip', [RoleService::DIRECTOR, RoleService::GIP]));
    assert_same(false, RoleService::isAny('engineer', [RoleService::DIRECTOR]));
});

test('BIM-менеджер есть в ролевой модели и может самосогласовать собственную выдачу', function (): void {
    assert_same(true, RoleService::exists(RoleService::BIM_MANAGER));
    assert_same('BIM-менеджер', RoleService::label(RoleService::BIM_MANAGER));
    assert_same(true, PermissionService::canSelfApproveIssuance(['role' => RoleService::BIM_MANAGER]));
    assert_same(true, PermissionService::canSelfApproveIssuance(['role' => RoleService::GIP]));
    assert_same(false, PermissionService::canSelfApproveIssuance(['role' => RoleService::GROUP_LEAD]));
});

test('служебную задачу проверки нельзя создать обычной формой', function (): void {
    assert_same(false, PermissionService::canCreateTaskType(['role' => RoleService::DIRECTOR], TaskWorkflowService::TASK_TYPE_REVIEW));
    assert_same(true, PermissionService::canCreateTaskType(['role' => RoleService::DIRECTOR], 'work'));
});

test('делегирующую задачу создаёт ГИП и выше', function (): void {
    assert_same(false, PermissionService::canCreateTaskType(['role' => RoleService::DEPARTMENT_HEAD], TaskWorkflowService::TASK_TYPE_DELEGATION));
    assert_same(true, PermissionService::canCreateTaskType(['role' => RoleService::GIP], TaskWorkflowService::TASK_TYPE_DELEGATION));
    assert_same(true, PermissionService::canCreateTaskType(['role' => RoleService::DIRECTOR], TaskWorkflowService::TASK_TYPE_DELEGATION));
});

test('назначенный проверяющий может редактировать обычную активную задачу', function (): void {
    $task = [
        'project_status' => 'active',
        'task_type' => 'work',
        'reviewer_id' => 7,
        'assignee_id' => 9,
        'author_id' => 10,
    ];

    assert_same(true, PermissionService::canEditTask(['id' => 7, 'role' => RoleService::ENGINEER], $task));
    assert_same(false, PermissionService::canEditTask(['id' => 7, 'role' => RoleService::ENGINEER], array_merge($task, ['project_status' => 'archived'])));
});

test('участник команды проекта видит проект без права Все проекты', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, code TEXT, gip_user_id INTEGER, rp_user_id INTEGER)');
    $pdo->exec('CREATE TABLE project_members (id INTEGER PRIMARY KEY, project_id INTEGER, user_id INTEGER, active INTEGER DEFAULT 1)');
    $pdo->exec("INSERT INTO projects (id, code, gip_user_id, rp_user_id) VALUES
        (10, 'P-10', NULL, NULL),
        (11, 'P-11', NULL, NULL)");
    $pdo->exec('INSERT INTO project_members (project_id, user_id, active) VALUES (10, 5, 1)');
    Database::useConnection($pdo);

    [$where, $params] = PermissionService::projectScopeWhere(['id' => 5, 'role' => RoleService::ENGINEER], 'p', 'scope_task');
    $stmt = $pdo->prepare('SELECT id FROM projects p WHERE ' . $where . ' ORDER BY id');
    $stmt->execute($params);

    assert_same([10], array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

    Database::reset();
});

test('ProjectController bulk-сохранение команды активирует отмеченных и снимает отсутствующих', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE project_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER,
        user_id INTEGER,
        project_role TEXT,
        allocation_percent REAL,
        date_start TEXT,
        date_end TEXT,
        notes TEXT,
        active INTEGER DEFAULT 1,
        updated_at TEXT,
        UNIQUE(project_id, user_id)
    )');
    $pdo->exec("INSERT INTO project_members (project_id, user_id, project_role, allocation_percent, active) VALUES
        (10, 5, 'ОВ', 50, 1),
        (10, 6, 'СС', 30, 1)");
    Database::useConnection($pdo);

    $controller = new ProjectController();
    $method = new ReflectionMethod(ProjectController::class, 'syncProjectMembers');
    $method->invoke($controller, 10, [5, 7], [
        'member_role' => [5 => 'ГАП', 7 => 'BIM'],
        'member_allocation_percent' => [5 => '80', 7 => '25'],
        'member_date_start' => [5 => '2026-06-01', 7 => '2026-06-02'],
        'member_date_end' => [5 => '', 7 => '2026-06-30'],
        'member_notes' => [5 => 'ядро', 7 => 'модель'],
    ]);

    $rows = $pdo->query('SELECT user_id, project_role, allocation_percent, date_start, date_end, notes, active FROM project_members WHERE project_id = 10 ORDER BY user_id')->fetchAll();
    assert_same([5, 6, 7], array_map('intval', array_column($rows, 'user_id')));
    assert_same(1, (int) $rows[0]['active']);
    assert_same('ГАП', (string) $rows[0]['project_role']);
    assert_same(80.0, (float) $rows[0]['allocation_percent']);
    assert_same(0, (int) $rows[1]['active'], 'неотмеченный участник становится неактивным');
    assert_same(1, (int) $rows[2]['active']);
    assert_same('BIM', (string) $rows[2]['project_role']);
    assert_same('2026-06-30', (string) $rows[2]['date_end']);

    Database::reset();
});

test('ProjectAccountingService связывает БТП с ПП и отдаёт выбранную БТП в задачу', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE project_pp_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        code TEXT NOT NULL,
        title TEXT,
        notes TEXT,
        active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT,
        UNIQUE(project_id, code)
    )');
    $pdo->exec('CREATE TABLE project_btp_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        pp_code_id INTEGER NOT NULL,
        code TEXT NOT NULL,
        title TEXT,
        notes TEXT,
        active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT,
        UNIQUE(project_id, pp_code_id, code)
    )');
    $pdo->exec('CREATE TABLE project_uts_facts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        pp_code_id INTEGER NOT NULL,
        btp_code_id INTEGER,
        fact_date TEXT,
        amount REAL NOT NULL DEFAULT 0,
        description TEXT,
        document_ref TEXT,
        created_by INTEGER
    )');
    Database::useConnection($pdo);

    ProjectAccountingService::savePp(10, ProjectAccountingService::ppPayload([
        'code' => 'PP-01',
        'title' => 'Сделка 01',
    ]));
    ProjectAccountingService::savePp(10, ProjectAccountingService::ppPayload([
        'code' => 'PP-02',
        'title' => 'Сделка 02',
    ]));
    $ppId = (int) $pdo->query("SELECT id FROM project_pp_codes WHERE code = 'PP-01'")->fetchColumn();
    $otherPpId = (int) $pdo->query("SELECT id FROM project_pp_codes WHERE code = 'PP-02'")->fetchColumn();

    ProjectAccountingService::saveBtp(10, ProjectAccountingService::btpPayload([
        'pp_code_id' => $ppId,
        'code' => 'BTP-01',
        'title' => 'Статья 01',
    ]));
    $btpId = (int) $pdo->query("SELECT id FROM project_btp_codes WHERE code = 'BTP-01'")->fetchColumn();

    $selection = ProjectAccountingService::resolveTaskSelection(10, null, $btpId, 'ручная строка');
    assert_same($ppId, $selection['pp_code_id']);
    assert_same($btpId, $selection['btp_code_id']);
    assert_same('BTP-01', $selection['btp']);
    assert_throws(static fn () => ProjectAccountingService::resolveTaskSelection(10, $otherPpId, $btpId, ''));

    ProjectAccountingService::saveUts(10, ProjectAccountingService::utsPayload([
        'pp_code_id' => $ppId,
        'btp_code_id' => $btpId,
        'fact_date' => '2026-06-19',
        'amount' => '123,45',
        'description' => 'Факт затрат',
        'document_ref' => 'Акт-1',
    ]), 5);
    $fact = $pdo->query('SELECT pp_code_id, btp_code_id, amount FROM project_uts_facts')->fetch();
    assert_same($ppId, (int) $fact['pp_code_id']);
    assert_same($btpId, (int) $fact['btp_code_id']);
    assert_same(123.45, (float) $fact['amount']);

    Database::reset();
});

test('TimeService быстрым списанием наращивает часы задачи атомарным upsert', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, code TEXT, title TEXT, status TEXT)');
    $pdo->exec('CREATE TABLE project_pp_codes (id INTEGER PRIMARY KEY, project_id INTEGER, code TEXT, title TEXT)');
    $pdo->exec('CREATE TABLE project_btp_codes (id INTEGER PRIMARY KEY, project_id INTEGER, pp_code_id INTEGER, code TEXT, title TEXT)');
    $pdo->exec('CREATE TABLE task_participants (task_id INTEGER, user_id INTEGER, role TEXT)');
    $pdo->exec('CREATE TABLE tasks (
        id INTEGER PRIMARY KEY,
        title TEXT,
        status TEXT,
        task_type TEXT,
        project_id INTEGER,
        pp_code_id INTEGER,
        btp_code_id INTEGER,
        planned_hours REAL,
        actual_hours REAL,
        assignee_id INTEGER,
        reviewer_id INTEGER,
        updated_at TEXT
    )');
    $pdo->exec('CREATE TABLE time_batches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        period_start TEXT,
        period_end TEXT,
        mode TEXT,
        status TEXT,
        total_minutes INTEGER,
        comment TEXT
    )');
    $pdo->exec('CREATE TABLE time_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        batch_id INTEGER,
        user_id INTEGER,
        project_id INTEGER,
        task_id INTEGER,
        work_date TEXT,
        minutes INTEGER,
        category TEXT,
        phase TEXT,
        comment TEXT,
        status TEXT
    )');
    $pdo->exec('CREATE TABLE activity_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        scope TEXT,
        project_id INTEGER,
        task_id INTEGER,
        user_id INTEGER,
        action TEXT,
        title TEXT,
        body TEXT,
        meta_json TEXT
    )');
    $pdo->exec("INSERT INTO projects (id, code, title, status) VALUES (10, 'P-10', 'Проект', 'active')");
    $pdo->exec("INSERT INTO tasks (id, title, status, task_type, project_id, planned_hours, actual_hours, assignee_id, reviewer_id) VALUES
        (77, 'Рабочая задача', 'in_progress', 'work', 10, 8, NULL, 5, NULL)");
    Database::useConnection($pdo);

    $service = new TimeService($pdo);
    $user = ['id' => 5, 'role' => RoleService::ENGINEER];

    assert_same(30, $service->addTaskQuickMinutes($user, 77, '2026-06-19', 30, 'execution'));
    assert_same(90, $service->addTaskQuickMinutes($user, 77, '2026-06-19', 60, 'execution'));

    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM time_entries WHERE task_id = 77 AND status != "locked"')->fetchColumn());
    assert_same(90, (int) $pdo->query('SELECT minutes FROM time_entries WHERE task_id = 77')->fetchColumn());
    assert_same(1.5, (float) $pdo->query('SELECT actual_hours FROM tasks WHERE id = 77')->fetchColumn());
    assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM time_batches')->fetchColumn());
    assert_same(2, (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'time.task_logged'")->fetchColumn());

    Database::reset();
});

// --- TaskWorkflowService::defaultReviewerId на инжектнутой in-memory БД ---
test('defaultReviewerId поднимается по лестнице и уважает отдел', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, department TEXT, is_active INTEGER DEFAULT 1)');
    // инженер в ОВ; рук. группы в ОВ; начальник отдела в ОВ; ГИП без отдела
    $pdo->exec("INSERT INTO users (id, role, department, is_active) VALUES
        (1, 'engineer', 'ОВ', 1),
        (2, 'group_lead', 'ОВ', 1),
        (3, 'department_head', 'ОВ', 1),
        (4, 'gip', NULL, 1)");
    Database::useConnection($pdo);

    // для инженера в ОВ ближайший проверяющий по лестнице — рук. группы в его отделе (id=2)
    assert_same(2, TaskWorkflowService::defaultReviewerId(1));

    // если рук. группы неактивен — поднимаемся до начальника отдела (id=3)
    $pdo->exec('UPDATE users SET is_active = 0 WHERE id = 2');
    assert_same(3, TaskWorkflowService::defaultReviewerId(1));

    // несуществующий исполнитель → null
    assert_same(null, TaskWorkflowService::defaultReviewerId(999));

    Database::reset();
});

test('defaultReviewerId возвращает null, когда некому проверять', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, department TEXT, is_active INTEGER DEFAULT 1)');
    // только сам инженер — выше по лестнице никого нет
    $pdo->exec("INSERT INTO users (id, role, department, is_active) VALUES (1, 'engineer', 'ОВ', 1)");
    Database::useConnection($pdo);

    assert_same(null, TaskWorkflowService::defaultReviewerId(1));

    Database::reset();
});

test('defaultReviewerId предпочитает руководителя, а не коллегу с тем же manager_id', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, department TEXT, manager_id INTEGER, is_active INTEGER DEFAULT 1)');
    $pdo->exec("INSERT INTO users (id, role, department, manager_id, is_active) VALUES
        (1, 'engineer', 'ОВ', 10, 1),
        (2, 'group_lead', 'ОВ', 10, 1),
        (10, 'department_head', 'ОВ', NULL, 1)");
    Database::useConnection($pdo);

    assert_same(10, TaskWorkflowService::defaultReviewerId(1));

    Database::reset();
});
