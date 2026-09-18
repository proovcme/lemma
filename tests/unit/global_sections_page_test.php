<?php

declare(strict_types=1);

test('страница разделов позволяет только добавлять записи', function (): void {
    $sections = [
        ['value' => 'ОВ', 'label' => 'Отопление и вентиляция'],
        ['value' => 'АСУ', 'label' => 'Автоматизированные системы управления'],
    ];
    $teamTab = 'sections';

    ob_start();
    require BASE_PATH . '/app/Views/team/sections.php';
    $html = (string) ob_get_clean();

    assert_true(str_contains($html, 'action="/team/sections"'), 'форма должна добавлять раздел в общий справочник');
    assert_true(str_contains($html, 'Добавить раздел'), 'действие добавления должно быть явным');
    assert_true(str_contains($html, 'АСУ') && str_contains($html, 'Автоматизированные системы управления'), 'список должен показывать шифр и название');
    assert_true(!str_contains($html, 'Удалить') && !str_contains($html, 'Отключить') && !str_contains($html, 'Сохранить изменения'), 'на странице не должно быть изменения или удаления существующих разделов');
});
