<?php

declare(strict_types=1);

test('форма задачи показывает разделы из общего справочника', function (): void {
    $_GET = [];
    $task = [
        'id' => 77,
        'task_type' => 'work',
        'project_id' => 5,
        'assignee_id' => 10,
        'section' => 'АСУ',
        'status' => 'new',
    ];
    $smart = [];
    $accounting = ['pp' => [], 'btp' => []];
    $dictionaries = [
        'volume' => [],
        'section_code' => [],
        'section' => [
            [
                'scope_project_id' => 0,
                'value' => 'АСУ',
                'label' => 'Автоматизированные системы управления',
            ],
        ],
    ];
    $projects = [['id' => 5, 'code' => 'PRJ', 'title' => 'Проект']];
    $users = [['id' => 10, 'name' => 'Иванов Иван', 'department' => 'АСУ']];
    $customFields = [];
    $customValues = [];
    $taskTags = [];
    $tagOptions = [];
    $participants = ['assignee' => [], 'coauthor' => [], 'observer' => []];
    $relationTasks = [];

    ob_start();
    require BASE_PATH . '/app/Views/tasks/form.php';
    $html = (string) ob_get_clean();

    assert_true(
        str_contains($html, '<option value="АСУ" selected>АСУ · Автоматизированные системы управления</option>'),
        'добавленный глобальный раздел должен быть доступен и выбран в форме задачи'
    );
});

test('технические справочники не дублируют управление разделами', function (): void {
    $items = [
        [
            'scope_project_id' => 0,
            'kind' => 'section',
            'value' => 'АСУ',
            'label' => 'Автоматизированные системы управления',
            'discipline' => 'АСУ',
            'sort_order' => 125,
            'active' => 1,
            'project_code' => null,
        ],
        [
            'scope_project_id' => 0,
            'kind' => 'volume',
            'value' => 'Том 1',
            'label' => 'Том 1',
            'discipline' => null,
            'sort_order' => 10,
            'active' => 1,
            'project_code' => null,
        ],
    ];
    $projects = [];
    $kinds = [
        'volume' => 'Том',
        'section_code' => 'Шифр / комплект',
        'section' => 'Раздел / марка',
    ];
    $sections = [
        ['value' => 'ОВ', 'label' => 'Отопление и вентиляция'],
        ['value' => 'АСУ', 'label' => 'Автоматизированные системы управления'],
    ];

    ob_start();
    require BASE_PATH . '/app/Views/admin/dictionaries.php';
    $html = (string) ob_get_clean();

    assert_true(str_contains($html, 'href="/team/sections"'), 'страница должна вести в единый справочник разделов');
    assert_true(!str_contains($html, '<option value="section">'), 'старый универсальный тип не должен повторно добавлять разделы');
    assert_true(!str_contains($html, '>АСУ</strong>'), 'строка раздела не должна дублироваться в техническом реестре');
    assert_true(str_contains($html, '>Том 1</strong>'), 'остальные технические записи должны сохраниться');
    assert_true(str_contains($html, '<span>Раздел</span>'), 'legacy-привязка технической записи должна называться разделом');
    assert_true(str_contains($html, '<option value="АСУ">АСУ · Автоматизированные системы управления</option>'), 'привязка должна читать тот же каталог разделов');
});

test('фильтр задач показывает новые разделы из справочника', function (): void {
    $statuses = [];
    $tasks = [];
    $reviewTasks = [];
    $projects = [];
    $users = [];
    $filters = [];
    $scope = 'mine';
    $viewMode = 'table';
    $sections = [
        ['value' => 'АСУ', 'label' => 'Автоматизированные системы управления'],
    ];

    ob_start();
    require BASE_PATH . '/app/Views/tasks/index.php';
    $html = (string) ob_get_clean();

    assert_true(str_contains($html, '<option value="">Раздел</option>'), 'фильтр должен использовать актуальное название поля');
    assert_true(str_contains($html, '<option value="АСУ">АСУ · Автоматизированные системы управления</option>'), 'новый раздел должен быть доступен в фильтре задач');
});

test('проектные технические справочники не создают второй каталог разделов', function (): void {
    $project = ['id' => 5, 'code' => 'PRJ', 'title' => 'Проект'];
    $accounting = ['pp' => [], 'btp' => [], 'uts' => []];
    $canEdit = true;
    $canViewProjectFinance = false;
    $items = [
        [
            'scope_project_id' => 0,
            'kind' => 'section',
            'value' => 'АСУ',
            'label' => 'Автоматизированные системы управления',
            'discipline' => 'АСУ',
            'sort_order' => 125,
        ],
        [
            'scope_project_id' => 5,
            'kind' => 'section_code',
            'value' => 'PRJ-ОВ',
            'label' => 'Комплект ОВ',
            'discipline' => 'ОВ',
            'sort_order' => 10,
        ],
    ];
    $kinds = [
        'volume' => 'Том',
        'section_code' => 'Шифр / комплект',
        'section' => 'Раздел / марка',
    ];
    $sections = [
        ['value' => 'АСУ', 'label' => 'Автоматизированные системы управления'],
    ];

    ob_start();
    require BASE_PATH . '/app/Views/projects/dictionaries.php';
    $html = (string) ob_get_clean();

    assert_true(str_contains($html, 'href="/team/sections"'), 'проектный экран должен вести в единый справочник разделов');
    assert_true(!str_contains($html, '<option value="section">'), 'проектный экран не должен создавать параллельный раздел');
    assert_true(!str_contains($html, '>АСУ</strong>'), 'глобальный раздел не должен повторяться в техническом реестре проекта');
    assert_true(str_contains($html, '>PRJ-ОВ</strong>'), 'проектные технические значения должны сохраниться');
    assert_true(str_contains($html, '<span>Раздел</span>'), 'legacy-привязка должна называться разделом');
    assert_true(str_contains($html, '<option value="АСУ">АСУ · Автоматизированные системы управления</option>'), 'проектная привязка должна читать общий каталог разделов');
});
