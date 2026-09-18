<?php

declare(strict_types=1);

use App\Services\ProjectHealthReportService;
use App\Services\ProjectStructureService;
use App\Services\TaskActionQueueService;

function project_structure_health_test_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, department TEXT, role TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1)');
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, code TEXT NOT NULL, title TEXT NOT NULL, status TEXT NOT NULL DEFAULT "active", gip_user_id INTEGER)');
    $pdo->exec('CREATE TABLE project_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        project_role TEXT, active INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(project_id, user_id)
    )');
    $pdo->exec('CREATE TABLE departments (
        id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE dictionary_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, scope_project_id INTEGER NOT NULL DEFAULT 0,
        kind TEXT NOT NULL, value TEXT NOT NULL, label TEXT, discipline TEXT, active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(scope_project_id, kind, value)
    )');
    $pdo->exec('CREATE TABLE project_stages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, code TEXT NOT NULL, title TEXT NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(project_id, code)
    )');
    $pdo->exec('CREATE TABLE project_sections (
        id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, stage_id INTEGER, work_kind TEXT NOT NULL DEFAULT "section",
        sort_order INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, volume TEXT, code TEXT, title TEXT,
        status TEXT DEFAULT "active", assignee_id INTEGER, reviewer_id INTEGER
    )');
    $pdo->exec('CREATE TABLE project_section_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT, project_section_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        assignment_role TEXT NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(project_section_id, user_id, assignment_role)
    )');
    $pdo->exec('CREATE TABLE tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, task_type TEXT DEFAULT "work", project_id INTEGER NOT NULL,
        project_section_id INTEGER, assignee_id INTEGER, author_id INTEGER, reviewer_id INTEGER, status TEXT NOT NULL DEFAULT "new",
        approval_stage TEXT NOT NULL DEFAULT "draft", date_start TEXT, date_end TEXT, planned_hours REAL, actual_hours REAL,
        progress INTEGER NOT NULL DEFAULT 0, close_requested_at TEXT, closed_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE task_approvals (
        id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL, stage TEXT NOT NULL, approved_by INTEGER NOT NULL,
        decision TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE employee_vacations (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, date_from TEXT NOT NULL, date_to TEXT NOT NULL,
        substitute_user_id INTEGER NOT NULL, cancelled_at TEXT
    )');
    $pdo->exec('CREATE TABLE project_health_comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, date_from TEXT NOT NULL, date_to TEXT NOT NULL,
        entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL DEFAULT 0, comment_text TEXT NOT NULL, author_id INTEGER NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(project_id, date_from, date_to, entity_type, entity_id)
    )');
    $pdo->exec("INSERT INTO users (id, name, department, role) VALUES
        (1, 'Первый исполнитель', 'ОВ', 'engineer'),
        (2, 'Второй исполнитель', 'ОВ', 'engineer'),
        (3, 'Проверяющий', 'ОВ', 'group_lead'),
        (4, 'ГИП', 'ГИП', 'gip')");
    $pdo->exec("INSERT INTO projects (id, code, title, gip_user_id) VALUES (10, 'P-10', 'Тестовый проект', 4)");
    $pdo->exec("INSERT INTO departments (code, name) VALUES
        ('АР', 'Отдел архитектурных решений'),
        ('ГИП', 'Служба главных инженеров проектов (ГИП)'),
        ('ОВ', 'Отдел отопления и вентиляции'),
        ('ТИМ', 'Отдел технологий информационного моделирования')");
    $pdo->exec("INSERT INTO dictionary_items (scope_project_id, kind, value, label, sort_order) VALUES
        (0, 'project_stage', 'ПД', 'Проектная документация', 10),
        (0, 'project_stage', 'РД', 'Рабочая документация', 20),
        (0, 'section_pp87', 'АР', 'Архитектурные решения', 10),
        (0, 'section_pp87', 'ОВ', 'Отопление и вентиляция', 20),
        (0, 'section_rd', 'ОВ', 'Отопление и вентиляция', 10),
        (0, 'project_activity', 'ТИМ', 'ТИМ-координация', 10),
        (0, 'project_activity', 'УПРАВЛЕНИЕ', 'Управление проектом', 20)");

    return $pdo;
}

test('структура проекта создаёт стадии, типовые разделы и отдельные общие активности', function (): void {
    $pdo = project_structure_health_test_pdo();
    $service = new ProjectStructureService($pdo);
    $service->createForProject(10, [
        'stage_codes' => ['ПД', 'РД'],
        'stage_templates' => ['ПД' => 'pp87', 'РД' => 'rd'],
        'activity_codes' => ['ТИМ', 'УПРАВЛЕНИЕ'],
    ]);

    assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM project_stages')->fetchColumn());
    assert_same(3, (int) $pdo->query("SELECT COUNT(*) FROM project_sections WHERE work_kind = 'section'")->fetchColumn());
    assert_same(2, (int) $pdo->query("SELECT COUNT(*) FROM project_sections WHERE work_kind = 'activity' AND stage_id IS NULL")->fetchColumn());

    $sectionId = (int) $pdo->query("SELECT id FROM project_sections WHERE work_kind = 'section' AND code = 'ОВ' ORDER BY id LIMIT 1")->fetchColumn();
    $service->syncAssignments(10, $sectionId, [1, 2], [3]);
    assert_same(2, (int) $pdo->query("SELECT COUNT(*) FROM project_section_assignments WHERE assignment_role = 'executor'")->fetchColumn());
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM project_section_assignments WHERE assignment_role = 'reviewer'")->fetchColumn());
    assert_same('1', (string) $pdo->query("SELECT assignee_id FROM project_sections WHERE id = {$sectionId}")->fetchColumn());
    assert_same('3', (string) $pdo->query("SELECT reviewer_id FROM project_sections WHERE id = {$sectionId}")->fetchColumn());
});

test('таблица команды создаёт пользовательский раздел с множественными ролями и привязкой задачи', function (): void {
    $pdo = project_structure_health_test_pdo();
    $service = new ProjectStructureService($pdo);
    $sectionId = $service->addWorkItemWithAssignments(10, null, 'section', 'НВК', 'Наружные сети водоснабжения', true, [1, 2], [3]);

    assert_same('НВК', (string) $pdo->query("SELECT code FROM project_sections WHERE id = {$sectionId}")->fetchColumn());
    assert_same(2, (int) $pdo->query("SELECT COUNT(*) FROM project_section_assignments WHERE project_section_id = {$sectionId} AND assignment_role = 'executor'")->fetchColumn());
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM project_section_assignments WHERE project_section_id = {$sectionId} AND assignment_role = 'reviewer'")->fetchColumn());
    assert_same(3, (int) $pdo->query('SELECT COUNT(*) FROM project_members WHERE project_id = 10 AND active = 1')->fetchColumn());
    assert_same('Наружные сети водоснабжения', (string) $pdo->query("SELECT label FROM dictionary_items WHERE kind = 'section' AND value = 'НВК'")->fetchColumn());

    $pdo->prepare('INSERT INTO tasks (title, project_id, project_section_id, assignee_id) VALUES (?, 10, ?, 1)')->execute(['Задача НВК', $sectionId]);
    assert_same((string) $sectionId, (string) $pdo->query("SELECT project_section_id FROM tasks WHERE title = 'Задача НВК'")->fetchColumn());
});

test('табличное сохранение обновляет несколько разделов одной транзакцией', function (): void {
    $pdo = project_structure_health_test_pdo();
    $service = new ProjectStructureService($pdo);
    $firstId = $service->addWorkItem(10, null, 'section', 'АР', 'Архитектурные решения', false);
    $secondId = $service->addWorkItem(10, null, 'section', 'ОВ', 'Отопление и вентиляция', false);

    $saved = $service->syncAssignmentTable(10, [$firstId, $secondId], [$firstId => [1], $secondId => [2]], [$firstId => [3], $secondId => [3]]);

    assert_same(2, $saved);
    assert_same(4, (int) $pdo->query('SELECT COUNT(*) FROM project_section_assignments')->fetchColumn());
    assert_same('1', (string) $pdo->query("SELECT assignee_id FROM project_sections WHERE id = {$firstId}")->fetchColumn());
    assert_same('2', (string) $pdo->query("SELECT assignee_id FROM project_sections WHERE id = {$secondId}")->fetchColumn());
});

test('таблица команды показывает все подразделения базы даже у проекта без структуры', function (): void {
    $pdo = project_structure_health_test_pdo();
    $service = new ProjectStructureService($pdo);

    $rows = $service->departmentTeamRows(10);

    assert_same(['АР', 'ГИП', 'ОВ', 'ТИМ'], array_column($rows, 'code'));
    assert_same([], $rows[1]['executors']);
    assert_same(null, $rows[1]['reviewer']);

    $pdo->exec("INSERT INTO departments (code, name) VALUES ('Новый', 'Новый раздел из справочника')");
    assert_true(in_array('НОВЫЙ', array_column($service->departmentTeamRows(10), 'code'), true));
});

test('таблица подразделений создаёт связи проекта и хранит несколько исполнителей и одного проверяющего', function (): void {
    $pdo = project_structure_health_test_pdo();
    $service = new ProjectStructureService($pdo);

    $saved = $service->syncDepartmentTeamTable(
        10,
        ['АР', 'ГИП', 'ОВ', 'ТИМ'],
        ['ГИП' => [2], 'ОВ' => [1, 2]],
        ['ГИП' => 3, 'ОВ' => 3]
    );

    assert_same(4, $saved);
    assert_same(4, (int) $pdo->query('SELECT COUNT(*) FROM project_sections WHERE project_id = 10 AND active = 1')->fetchColumn());
    $sectionId = (int) $pdo->query("SELECT id FROM project_sections WHERE project_id = 10 AND code = 'ОВ'")->fetchColumn();
    assert_same(2, (int) $pdo->query("SELECT COUNT(*) FROM project_section_assignments WHERE project_section_id = {$sectionId} AND assignment_role = 'executor'")->fetchColumn());
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM project_section_assignments WHERE project_section_id = {$sectionId} AND assignment_role = 'reviewer'")->fetchColumn());

    $rows = $service->departmentTeamRows(10);
    $ov = array_values(array_filter($rows, static fn (array $row): bool => $row['code'] === 'ОВ'))[0];
    assert_same([1, 2], array_column($ov['executors'], 'user_id'));
    assert_same(3, (int) $ov['reviewer']['user_id']);
    assert_same(4, (int) $pdo->query('SELECT gip_user_id FROM projects WHERE id = 10')->fetchColumn());
});

test('очередь действий не показывает закрытые и неполные проверки-призраки', function (): void {
    $pdo = project_structure_health_test_pdo();
    $pdo->exec("INSERT INTO tasks (id, title, project_id, reviewer_id, status, approval_stage, close_requested_at, closed_at) VALUES
        (1, 'Живая проверка', 10, 3, 'review', 'draft', CURRENT_TIMESTAMP, NULL),
        (2, 'Закрытый призрак', 10, 3, 'done', 'review_lead', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
        (3, 'Проверка без запроса', 10, 3, 'review', 'draft', NULL, NULL)");

    $ids = (new TaskActionQueueService($pdo))->taskIds(['id' => 3, 'role' => 'group_lead']);
    assert_same([1], $ids);
});

test('единая очередь сохраняет действие за действующим заместителем проверяющего', function (): void {
    $pdo = project_structure_health_test_pdo();
    $pdo->exec("INSERT INTO tasks (id, title, project_id, reviewer_id, status, approval_stage, close_requested_at) VALUES
        (1, 'Проверка на отпуске', 10, 3, 'review', 'draft', CURRENT_TIMESTAMP)");
    $stmt = $pdo->prepare('INSERT INTO employee_vacations (user_id, date_from, date_to, substitute_user_id) VALUES (3, ?, ?, 2)');
    $stmt->execute([date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+1 day'))]);

    $ids = (new TaskActionQueueService($pdo))->taskIds(['id' => 2, 'role' => 'engineer']);
    assert_same([1], $ids);
});

test('отчёт Что у нас плохого группирует задачи и хранит комментарии по уровню', function (): void {
    $pdo = project_structure_health_test_pdo();
    $structure = new ProjectStructureService($pdo);
    $structure->createForProject(10, [
        'stage_codes' => ['ПД'],
        'stage_templates' => ['ПД' => 'pp87'],
        'activity_codes' => ['ТИМ'],
    ]);
    $sectionId = (int) $pdo->query("SELECT id FROM project_sections WHERE work_kind = 'section' AND code = 'ОВ'")->fetchColumn();
    $pdo->prepare('INSERT INTO tasks (title, project_id, project_section_id, assignee_id, status, date_end, progress) VALUES (?, 10, ?, 1, "in_progress", ?, 60)')
        ->execute(['Просроченная ОВ', $sectionId, date('Y-m-d', strtotime('-2 days'))]);
    $pdo->exec("INSERT INTO tasks (title, project_id, status, date_end, progress) VALUES ('Без раздела', 10, 'blocked', DATE('now', '+2 day'), 20)");

    $service = new ProjectHealthReportService($pdo);
    $period = ProjectHealthReportService::period([]);
    $report = $service->build(['id' => 10], $period);
    assert_same(2, (int) $report['summary']['problem']);
    assert_same(1, (int) $report['summary']['unlinked']);

    $service->saveComment(10, $period, 'section', $sectionId, 'Нужны исходные данные', 4);
    $updated = $service->build(['id' => 10], $period);
    assert_same('Нужны исходные данные', $updated['comments']['section:' . $sectionId] ?? '');
});
