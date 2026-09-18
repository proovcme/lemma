<?php

declare(strict_types=1);

use App\Services\ProjectTeamStructureService;

function project_team_structure_test_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, is_active INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, code TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE project_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        project_role TEXT,
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(project_id, user_id)
    )');
    $pdo->exec('CREATE TABLE project_sections (
        id INTEGER PRIMARY KEY,
        project_id INTEGER NOT NULL,
        volume TEXT,
        code TEXT,
        title TEXT,
        status TEXT DEFAULT "active",
        assignee_id INTEGER,
        reviewer_id INTEGER
    )');
    $pdo->exec('CREATE TABLE dictionary_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER,
        scope_project_id INTEGER NOT NULL DEFAULT 0,
        kind TEXT NOT NULL,
        value TEXT NOT NULL,
        label TEXT,
        discipline TEXT,
        active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(scope_project_id, kind, value)
    )');
    $pdo->exec("INSERT INTO dictionary_items (scope_project_id, kind, value, label, discipline) VALUES (0, 'section', 'ОВ', 'Отопление и вентиляция', 'ОВ')");
    $pdo->exec("INSERT INTO users (id, name, is_active) VALUES
        (1, 'Исполнитель', 1),
        (2, 'Проверяющий', 1),
        (3, 'Неактивный', 0)");
    $pdo->exec("INSERT INTO projects (id, code) VALUES (10, 'P-10'), (20, 'P-20')");
    $pdo->exec("INSERT INTO project_sections (id, project_id, volume, code, title) VALUES
        (101, 10, 'Том ОВ', 'ОВ', 'Отопление и вентиляция'),
        (102, 10, 'Том ВК', 'ВК', 'Водоснабжение'),
        (201, 20, 'Том АР', 'АР', 'Архитектура')");

    return $pdo;
}

test('структура команды назначает исполнителя и проверяющего по разделу', function (): void {
    $pdo = project_team_structure_test_pdo();
    $service = new ProjectTeamStructureService($pdo);

    assert_same(2, $service->sync(10, [101, 102], [101 => 1, 102 => 2], [101 => 2, 102 => '']));

    $assignment = $service->assignment(10, 101);
    assert_same(1, (int) ($assignment['assignee_id'] ?? 0));
    assert_same(2, (int) ($assignment['reviewer_id'] ?? 0));
    $rows = $service->forProject(10);
    $rowsById = array_column($rows, null, 'id');
    assert_same('Исполнитель', $rowsById[101]['assignee_name']);
    assert_same('Проверяющий', $rowsById[101]['reviewer_name']);

    $memberIds = array_map('intval', $pdo->query('SELECT user_id FROM project_members WHERE project_id = 10 AND active = 1 ORDER BY user_id')->fetchAll(PDO::FETCH_COLUMN));
    assert_same([1, 2], $memberIds);
});

test('сотрудник назначается на типовой раздел, а новый раздел попадает в общий справочник', function (): void {
    $pdo = project_team_structure_test_pdo();
    $service = new ProjectTeamStructureService($pdo);

    $sectionId = $service->assignPerson(10, 1, 2, 'ОВ', '', '');
    $assigned = $service->assignment(10, $sectionId);
    assert_same(1, (int) ($assigned['assignee_id'] ?? 0));
    assert_same(2, (int) ($assigned['reviewer_id'] ?? 0));

    $newSectionId = $service->assignPerson(10, 2, 0, '', 'НВК', 'Наружные сети ВК');
    assert_true($newSectionId > 0);
    assert_same('Наружные сети ВК', (string) $pdo->query("SELECT label FROM dictionary_items WHERE scope_project_id = 0 AND kind = 'section' AND value = 'НВК'")->fetchColumn());
    assert_same('2', (string) $pdo->query("SELECT assignee_id FROM project_sections WHERE project_id = 10 AND code = 'НВК'")->fetchColumn());
});

test('таблица команды назначает исполнителя и проверяющего одного раздела разным сотрудникам', function (): void {
    $pdo = project_team_structure_test_pdo();
    $service = new ProjectTeamStructureService($pdo);

    assert_same(1, $service->syncRosterAssignments(
        10,
        [1, 2],
        [1, 2],
        [1 => 'ОВ', 2 => 'ОВ'],
        [1 => 'executor', 2 => 'reviewer']
    ));

    assert_same('1', (string) $pdo->query("SELECT assignee_id FROM project_sections WHERE project_id = 10 AND code = 'ОВ'")->fetchColumn());
    assert_same('2', (string) $pdo->query("SELECT reviewer_id FROM project_sections WHERE project_id = 10 AND code = 'ОВ'")->fetchColumn());

    assert_same(0, $service->syncRosterAssignments(10, [1, 2], [1, 2], [1 => '', 2 => ''], [1 => '', 2 => '']));
    assert_same('', (string) $pdo->query("SELECT COALESCE(assignee_id, '') FROM project_sections WHERE project_id = 10 AND code = 'ОВ'")->fetchColumn());
    assert_same('', (string) $pdo->query("SELECT COALESCE(reviewer_id, '') FROM project_sections WHERE project_id = 10 AND code = 'ОВ'")->fetchColumn());
});

test('таблица команды отклоняет две одинаковые роли в одном разделе', function (): void {
    $service = new ProjectTeamStructureService(project_team_structure_test_pdo());

    assert_throws(fn () => $service->syncRosterAssignments(
        10,
        [1, 2],
        [1, 2],
        [1 => 'ОВ', 2 => 'ОВ'],
        [1 => 'executor', 2 => 'executor']
    ), InvalidArgumentException::class);
});

test('табличное редактирование одной строки не стирает другие разделы того же сотрудника', function (): void {
    $pdo = project_team_structure_test_pdo();
    $pdo->exec('UPDATE project_sections SET assignee_id = 1 WHERE id IN (101, 102)');
    $service = new ProjectTeamStructureService($pdo);

    $service->syncRosterAssignments(
        10,
        [1],
        [1],
        [1 => 'ОВ'],
        [1 => 'executor'],
        [1 => 'ОВ'],
        [1 => 'executor']
    );

    assert_same('1', (string) $pdo->query('SELECT assignee_id FROM project_sections WHERE id = 101')->fetchColumn());
    assert_same('1', (string) $pdo->query('SELECT assignee_id FROM project_sections WHERE id = 102')->fetchColumn());
});

test('структура команды отклоняет самопроверку, неактивных людей и чужие разделы', function (): void {
    $service = new ProjectTeamStructureService(project_team_structure_test_pdo());

    assert_throws(fn () => $service->sync(10, [101], [101 => 1], [101 => 1]), InvalidArgumentException::class);
    assert_throws(fn () => $service->sync(10, [101], [101 => 3], [101 => 2]), InvalidArgumentException::class);
    assert_throws(fn () => $service->sync(10, [201], [201 => 1], [201 => 2]), InvalidArgumentException::class);
    assert_same(null, $service->assignment(10, 201));
});
