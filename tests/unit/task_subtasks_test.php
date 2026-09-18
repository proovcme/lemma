<?php

declare(strict_types=1);

use App\Controllers\TaskController;

test('создание подзадачи принимает parent_id как fallback для старой/скрытой формы', function (): void {
    $controller = new TaskController();
    $method = new ReflectionMethod(TaskController::class, 'taskPayload');

    $previousPost = $_POST;
    $_POST = [
        'title' => 'Подзадача',
        'task_type' => 'work',
        'project_id' => '10',
        'parent_id' => '77',
        'assignee_id' => '5',
        'reviewer_id' => '',
        'priority' => 'mid',
        'urgency' => 'high',
        'when_due' => '2026-06-30',
        'planned_hours' => '4',
        'progress' => '0',
        'what' => 'Сделать часть работы',
        'why' => 'Чтобы закрыть родительскую задачу',
    ];

    try {
        $payload = $method->invoke($controller);
        assert_same(77, $payload['parent_id']);
        assert_same('', $payload['depends_on']);
        assert_same(null, $payload['reviewer_id']);
    } finally {
        $_POST = $previousPost;
    }
});

test('связанная задача хранится как зависимость и не становится структурным родителем', function (): void {
    $controller = new TaskController();
    $method = new ReflectionMethod(TaskController::class, 'taskPayload');

    $previousPost = $_POST;
    $_POST = [
        'title' => 'Подзадача',
        'task_type' => 'work',
        'project_id' => '10',
        'parent_id' => '77',
        'dependency_task_id' => '88',
        'assignee_id' => '5',
        'reviewer_id' => '',
        'priority' => 'mid',
        'urgency' => 'high',
        'when_due' => '2026-06-30',
        'planned_hours' => '4',
        'progress' => '0',
        'what' => 'Сделать часть работы',
        'why' => 'Чтобы закрыть родительскую задачу',
    ];

    try {
        $payload = $method->invoke($controller);
        assert_same(null, $payload['parent_id']);
        assert_same('88', $payload['depends_on']);
    } finally {
        $_POST = $previousPost;
    }
});

test('срок задачи берётся из when_due, если date_end пришёл пустым из формы', function (): void {
    $controller = new TaskController();
    $method = new ReflectionMethod(TaskController::class, 'taskPayload');

    $previousPost = $_POST;
    $_POST = [
        'title' => 'Задача со сроком',
        'task_type' => 'work',
        'project_id' => '10',
        'date_end' => '',
        'when_due' => '2026-07-05',
        'assignee_id' => '5',
        'reviewer_id' => '',
        'priority' => 'mid',
        'urgency' => 'high',
        'planned_hours' => '4',
        'progress' => '0',
        'what' => 'Проверить сохранение срока',
        'why' => 'Срок не должен теряться из-за пустого date_end',
    ];

    try {
        $payload = $method->invoke($controller);
        assert_same('2026-07-05', $payload['date_end']);
        assert_same('2026-07-05', $payload['when_due']);
    } finally {
        $_POST = $previousPost;
    }
});

test('новая задача хранит выбранный раздел канонически и зеркалит его для legacy-отчётов', function (): void {
    $controller = new TaskController();
    $method = new ReflectionMethod(TaskController::class, 'taskPayload');

    $previousPost = $_POST;
    $_POST = [
        'title' => 'Проверить раздел',
        'task_type' => 'work',
        'project_id' => '10',
        'assignee_id' => '5',
        'priority' => 'mid',
        'urgency' => 'mid',
        'when_due' => '2026-07-05',
        'planned_hours' => '4',
        'section' => 'ОВ',
        'discipline' => '',
        'volume' => 'Том 4',
        'what' => 'Проверить единое поле раздела',
    ];

    try {
        $payload = $method->invoke($controller);
        assert_same('ОВ', $payload['section']);
        assert_same('ОВ', $payload['discipline']);
        assert_same('', $payload['volume']);
        assert_same(null, $payload['project_section_id']);
    } finally {
        $_POST = $previousPost;
    }
});

test('форма делегирования сохраняет руководителя как ответственного за распределение', function (): void {
    $controller = new TaskController();
    $method = new ReflectionMethod(TaskController::class, 'taskPayload');

    $previousPost = $_POST;
    $_POST = [
        'title' => 'Распределить раздел ОВ',
        'task_intent' => 'delegate_department',
        'task_type' => 'delegation',
        'project_id' => '10',
        'assignee_id' => '6',
        'reviewer_id' => '',
        'priority' => 'high',
        'urgency' => 'mid',
        'when_due' => '2026-07-10',
        'planned_hours' => '24',
        'progress' => '0',
        'discipline' => 'ОВ',
        'section' => 'ОВ',
        'what' => 'Распределить работу по исполнителям отдела',
        'why' => 'ГИПу нужен управляемый результат по разделу',
    ];

    try {
        $payload = $method->invoke($controller);
        assert_same('delegation', $payload['task_type']);
        assert_same('delegate_department', $payload['task_intent']);
        assert_same(6, $payload['assignee_id']);
        assert_same(null, $payload['reviewer_id']);
        assert_same('2026-07-10', $payload['date_end']);
    } finally {
        $_POST = $previousPost;
    }
});

test('контекст Атласа сохраняет точку обзора и overlay как JSON', function (): void {
    $controller = new TaskController();
    $method = new ReflectionMethod(TaskController::class, 'atlasPayload');

    $payload = $method->invoke($controller, [
        'atlas_url' => '/locia-atlas/?ifc=model.ifc',
        'atlas_model_id' => 'model',
        'atlas_element_id' => '42',
        'atlas_element_name' => 'Клапан',
        'atlas_viewpoint' => '{"position":[1,2,3],"target":[4,5,6]}',
        'atlas_overlay' => '{"kind":"ifc","local_id":42}',
    ]);

    assert_same('/locia-atlas/?ifc=model.ifc', $payload['atlas_url']);
    assert_same('42', $payload['element_id']);
    assert_true(str_contains((string) $payload['viewpoint_json'], '"position"'));
    assert_true(str_contains((string) $payload['overlay_json'], '"local_id":42'));
});
