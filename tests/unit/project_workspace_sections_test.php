<?php

declare(strict_types=1);
use App\Services\ProjectSectionWorkspaceService;
use App\Services\ProjectStructureService;

// Reuse the full project/task fixture so retention checks exercise real references.

test('убранный раздел возвращается с прежними задачами и назначениями', function (): void {
    $pdo = project_structure_health_test_pdo();
    $structure = new ProjectStructureService($pdo);
    $id = $structure->addWorkItem(10, null, 'section', 'АКУ', 'Акустика', false);
    $structure->syncAssignments(10, $id, [1], [3]);
    $pdo->exec("INSERT INTO tasks (title,project_id,project_section_id,status) VALUES ('Сохранить связь',10,$id,'in_progress')");
    $before = $pdo->query("SELECT * FROM project_section_assignments WHERE project_section_id=$id ORDER BY id")->fetchAll();
    $service = new ProjectSectionWorkspaceService($pdo);
    $service->setActive(10, $id, false);
    assert_same($id, (int) $pdo->query("SELECT project_section_id FROM tasks WHERE title='Сохранить связь'")->fetchColumn());
    assert_same($before, $pdo->query("SELECT * FROM project_section_assignments WHERE project_section_id=$id ORDER BY id")->fetchAll());
    assert_true(in_array($id, array_map('intval', array_column($service->removed(10), 'id')), true));
    $service->setActive(10, $id, true);
    assert_same(1, (int) $pdo->query("SELECT active FROM project_sections WHERE id=$id")->fetchColumn());
    assert_same($before, $pdo->query("SELECT * FROM project_section_assignments WHERE project_section_id=$id ORDER BY id")->fetchAll());
});

test('новый раздел проекта не меняет справочник и повторное добавление не затирает команду', function (): void {
    $pdo = project_structure_health_test_pdo();
    $service = new ProjectSectionWorkspaceService($pdo);
    $id = $service->add(10, null, '', 'аку', 'Акустика', false, false);
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM dictionary_items WHERE value='АКУ'")->fetchColumn());
    (new ProjectStructureService($pdo))->syncAssignments(10, $id, [1], [3]);
    assert_throws(fn () => $service->add(10, null, '', 'АКУ', 'Другое название', false, false), InvalidArgumentException::class);
    assert_same(2, (int) $pdo->query("SELECT COUNT(*) FROM project_section_assignments WHERE project_section_id=$id")->fetchColumn());
    $service->setActive(10, $id, false);
    assert_throws(fn () => $service->add(10, null, '', 'АКУ', 'Другое название', false, false), InvalidArgumentException::class);
});

test('общий справочник защищён правами и ошибка стадии не оставляет записи', function (): void {
    $pdo = project_structure_health_test_pdo();
    $service = new ProjectSectionWorkspaceService($pdo);
    assert_throws(fn () => $service->add(10, null, '', 'АКУ', 'Акустика', true, false), InvalidArgumentException::class);
    assert_throws(fn () => $service->add(10, 9999, '', 'АКУ', 'Акустика', true, true), InvalidArgumentException::class);
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM dictionary_items WHERE value='АКУ'")->fetchColumn());
    $service->add(10, null, '', 'АКУ', 'Акустика', true, true);
    assert_same('Акустика', $service->catalogOptions()['АКУ']);
});

test('чужой проект и архив не допускают изменения состава', function (): void {
    $pdo = project_structure_health_test_pdo();
    $id = (new ProjectStructureService($pdo))->addWorkItem(10, null, 'section', 'АКУ', 'Акустика', false);
    $service = new ProjectSectionWorkspaceService($pdo);
    assert_throws(fn () => $service->setActive(999, $id, false), InvalidArgumentException::class);
    $pdo->exec("INSERT INTO projects (id,code,title) VALUES (20,'OTHER','Другой')");
    assert_throws(fn () => $service->setActive(20, $id, false), InvalidArgumentException::class);
    $pdo->exec("UPDATE projects SET status='archived' WHERE id=10");
    assert_throws(fn () => $service->setActive(10, $id, false), InvalidArgumentException::class);
    assert_throws(fn () => $service->add(10, null, '', 'NEW', 'Новый', false, false), InvalidArgumentException::class);
    assert_same(1, (int) $pdo->query("SELECT active FROM project_sections WHERE id=$id")->fetchColumn());
});
