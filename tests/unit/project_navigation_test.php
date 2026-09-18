<?php

declare(strict_types=1);

test('карточка проекта использует компактный маршрут и оглавление сводки', function (): void {
    $navigation = file_get_contents(BASE_PATH . '/app/Views/projects/_navigation.php');
    $planNavigation = file_get_contents(BASE_PATH . '/app/Views/projects/_plan_navigation.php');
    $summary = file_get_contents(BASE_PATH . '/app/Views/projects/show.php');
    $layout = file_get_contents(BASE_PATH . '/app/Views/layouts/app.php');

    foreach (['Сводка', 'Задачи', 'План проекта', 'Структура и команда', 'Что у нас плохого', 'Вопросы', 'Исходные данные', 'Обмен заданиями'] as $label) {
        assert_same(true, str_contains($navigation, '>' . $label . '<'));
    }
    foreach (['Помощник', 'Гант', 'График РД', 'Разделы', 'План затрат', 'Справочники'] as $removedTopLevelLabel) {
        assert_same(false, str_contains($navigation, '>' . $removedTopLevelLabel . '<'));
    }
    foreach (['Гант', 'График РД', 'Разделы'] as $planView) {
        assert_same(true, str_contains($planNavigation, '>' . $planView . '<'));
    }

    assert_same(true, str_contains($navigation, '/structure'));
    assert_same(true, str_contains($navigation, '/health-report'));
    assert_same(true, str_contains($summary, 'aria-label="Оглавление сводки проекта"'));
    assert_same(true, str_contains($summary, 'Открыть структуру и команду'));
    assert_same(true, str_contains($summary, '>План затрат по СБЦ<'));
    assert_same(true, str_contains($layout, '$contextHelpHref'));
    assert_same(true, str_contains($layout, '>Справка<'));
});
