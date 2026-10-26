<?php

declare(strict_types=1);
use App\Services\{ProjectCompositionTemplateService, ProjectTaskContextService, ProjectStructureService, ProjectSectionWorkspaceService, TaskActionQueueService};

function composition_template_test_pdo(): PDO
{
    $pdo = project_structure_health_test_pdo();
    $pdo->exec('CREATE TABLE project_composition_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_user_id INTEGER NOT NULL, name TEXT NOT NULL, items_json TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("INSERT INTO projects (id,code,title) VALUES (20,'P-20','Другой проект')");
    return $pdo;
}

test('шаблон переносит только выбранный состав и сохраняет существующую команду', function (): void {
    $pdo = composition_template_test_pdo(); $structure = new ProjectStructureService($pdo);
    $stage = $structure->addStage(10, 'ПД', 'Проектная документация', false);
    $one = $structure->addWorkItemWithAssignments(10, $stage, 'section', 'АКУ', 'Акустика', false, [1], [3]);
    $structure->addWorkItem(10, $stage, 'section', 'АР', 'Архитектура', false);
    $service = new ProjectCompositionTemplateService($pdo);
    $template = $service->save(10, 4, 'Жилой дом');
    assert_same(1, count($service->forUser(4))); assert_same([], $service->forUser(3));
    assert_throws(fn () => $service->preview($template, 3));
    $preview = $service->preview($template, 4);
    assert_same(['code','title','kind','stage_code','stage_title'], array_keys($preview['items'][0]));
    assert_same(['added'=>1,'skipped'=>0], $service->apply(20, $template, 4, ['0']));
    $target = (int) $pdo->query('SELECT id FROM project_sections WHERE project_id=20')->fetchColumn();
    $structure->syncAssignments(20, $target, [2], [3]);
    assert_same(['added'=>0,'skipped'=>1], $service->apply(20, $template, 4, ['0']));
    assert_same(2, (int) $pdo->query("SELECT COUNT(*) FROM project_section_assignments WHERE project_section_id=$target")->fetchColumn());
    (new ProjectSectionWorkspaceService($pdo))->setActive(20, $target, false);
    assert_same(['added'=>0,'skipped'=>1], $service->apply(20, $template, 4, ['0']));
    assert_same(0, (int) $pdo->query("SELECT active FROM project_sections WHERE id=$target")->fetchColumn());
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM tasks WHERE project_id=20')->fetchColumn());
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM dictionary_items WHERE value='АКУ'")->fetchColumn());
});

test('ошибка шаблона откатывает весь состав, чужие индексы и архив запрещены', function (): void {
    $pdo = composition_template_test_pdo(); $structure = new ProjectStructureService($pdo);
    $pd = $structure->addStage(10, 'ПД', 'ПД', false); $rd = $structure->addStage(10, 'РД', 'РД', false);
    $structure->addWorkItem(10, $pd, 'section', 'АКУ', 'Акустика', false);
    $structure->addWorkItem(10, $rd, 'section', 'АР', 'Архитектура', false);
    $inactive = $structure->addStage(20, 'РД', 'РД', false);
    $pdo->exec("UPDATE project_stages SET active=0 WHERE id=$inactive");
    $service = new ProjectCompositionTemplateService($pdo); $template = $service->save(10, 4, 'Две стадии');
    assert_throws(fn () => $service->apply(20, $template, 4, ['0','1']));
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM project_sections WHERE project_id=20')->fetchColumn());
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM project_stages WHERE project_id=20')->fetchColumn());
    assert_throws(fn () => $service->apply(20, $template, 4, []));
    assert_throws(fn () => $service->apply(20, $template, 4, ['999']));
    $pdo->exec("UPDATE projects SET status='archived' WHERE id=20");
    assert_throws(fn () => $service->apply(20, $template, 4, ['0']));
});

test('контекст задачи предлагает только однозначную активную команду и сохраняет отдельную учётную группу', function (): void {
    $pdo = composition_template_test_pdo(); $structure = new ProjectStructureService($pdo);
    $id = $structure->addWorkItemWithAssignments(10, null, 'section', 'АКУ', 'Акустика', false, [1], [3]);
    $context = new ProjectTaskContextService($pdo); $row = $context->resolve(10, $id);
    assert_same(1, $row['suggested_assignee_id']); assert_same(3, $row['suggested_reviewer_id']);
    $data = $context->bind(['project_id'=>10,'assignee_id'=>1,'reviewer_id'=>3,'cost_group_code'=>'ОВ','section'=>'ОВ'], $id);
    assert_same('АКУ', $data['section']); assert_same('ОВ', $data['cost_group_code']); assert_same($id, $data['project_section_id']);
    assert_throws(fn () => $context->bind(['project_id'=>10,'assignee_id'=>1,'reviewer_id'=>1], $id));
    $structure->syncAssignments(10, $id, [1,2], [3]);
    assert_same(null, $context->resolve(10, $id)['suggested_assignee_id']);
    $pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
    assert_same(2, $context->resolve(10, $id)['suggested_assignee_id']);
    assert_throws(fn () => $context->resolve(20, $id));
    (new ProjectSectionWorkspaceService($pdo))->setActive(10, $id, false);
    assert_throws(fn () => $context->resolve(10, $id));
});

test('несколько проверяющих требуют выбора, неактивная стадия не даёт контекст задачи', function (): void {
    $pdo = composition_template_test_pdo(); $structure = new ProjectStructureService($pdo);
    $stage = $structure->addStage(10, 'ПД', 'ПД', false);
    $id = $structure->addWorkItemWithAssignments(10, $stage, 'section', 'АКУ', 'Акустика', false, [1], [2,3]);
    $service = new ProjectTaskContextService($pdo);
    assert_same(null, $service->resolve(10, $id)['suggested_reviewer_id']);
    assert_throws(fn () => $service->bind(['project_id'=>10,'assignee_id'=>1,'reviewer_id'=>null], $id));
    $pdo->exec("UPDATE project_stages SET active=0 WHERE id=$stage");
    assert_throws(fn () => $service->resolve(10, $id));
});

test('директорское наблюдение не создаёт личного поручения проверить чужую задачу', function (): void {
    $pdo = composition_template_test_pdo();
    $pdo->exec("INSERT INTO users(id,name,department,role) VALUES(5,'Директор','Дирекция','director')");
    $pdo->exec("INSERT INTO tasks(id,title,project_id,assignee_id,author_id,reviewer_id,status,close_requested_at) VALUES (101,'Чужая проверка',10,1,2,3,'review',CURRENT_TIMESTAMP),(102,'Лично директору',10,1,2,5,'review',CURRENT_TIMESTAMP)");
    $pdo->exec("INSERT INTO tasks(id,title,project_id,assignee_id,author_id,reviewer_id,status,approval_stage) VALUES(103,'Без ГИПа',20,1,2,3,'review','review_gip')");
    $queue = new TaskActionQueueService($pdo);
    assert_same([102], $queue->taskIds(['id'=>5,'role'=>'director']));
    assert_same([101], $queue->taskIds(['id'=>3,'role'=>'group_lead']));
    assert_same([], $queue->taskIds(['id'=>6,'role'=>'gip']));
});
