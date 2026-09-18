<?php

declare(strict_types=1);

/**
 * Юнит-тесты чистых хелперов (без БД, детерминированы).
 * Фиксируют поведение, на которое опираются шаблоны и безопасность ссылок.
 */

// --- e(): экранирование вывода (защита от XSS) ---
test('e() экранирует html-спецсимволы и кавычки', function (): void {
    assert_same('&lt;script&gt;', e('<script>'));
    assert_same('&quot;x&quot;', e('"x"'));
    assert_same('&#039;y&#039;', e("'y'"));
    assert_same('a &amp; b', e('a & b'));
});

// --- file_link_href(): белый список схем (защита от javascript:/data: и пр.) ---
test('file_link_href пропускает только http/https/file/dpr-open', function (): void {
    assert_same('#', file_link_href(''), 'пустая строка');
    assert_same('#', file_link_href('   '), 'пробелы');
    assert_same('https://example.com', file_link_href('https://example.com'));
    assert_same('http://intranet/doc', file_link_href('http://intranet/doc'));
    assert_same('#', file_link_href('javascript:alert(1)'), 'javascript-схема должна блокироваться');
    assert_same('#', file_link_href('ftp://host/file'), 'ftp не в белом списке');
    assert_same('#', file_link_href('mailto:a@b.c'), 'mailto не в белом списке');
});

// --- task_status_label / task_type_label: маппинг + fallback ---
test('task_status_label маппит известные и возвращает вход для неизвестных', function (): void {
    assert_same('Закрыта', task_status_label('done'));
    assert_same('В работе', task_status_label('in_progress'));
    assert_same('Просрочена', task_status_label('overdue'));
    assert_same('неведомый', task_status_label('неведомый'), 'fallback на сам ключ');
    assert_same('', task_status_label(null), 'null → пустая строка');
});

test('task_type_label знает рабочие типы, включая заметки и bim_family_request', function (): void {
    assert_same('Работа', task_type_label('work'));
    assert_same('Задание', task_type_label('assignment'));
    assert_same('Выдача', task_type_label('issuance'));
    assert_same('Оценка трудозатрат', task_type_label('labor_estimate'));
    assert_same('Делегирование', task_type_label('delegation'));
    assert_same('Проверка', task_type_label('review'));
    assert_same('Заявка на семейство ТИМ', task_type_label('bim_family_request'));
    assert_same('Заметка', task_type_label('note'));
});

// --- task_regulation_refs: базовые §3/§6 + спецссылка по типу ---
test('task_regulation_refs добавляет ссылку под тип задачи', function (): void {
    $work = task_regulation_refs('work');
    assert_eq(2, count($work), 'обычная работа — только §3 и §6');
    assert_same('3', $work[0]['no']);
    assert_same('6', $work[1]['no']);

    $issuance = task_regulation_refs('issuance');
    assert_eq(3, count($issuance));
    assert_same('7', $issuance[2]['no'], 'выдача → §7');

    $assignment = task_regulation_refs('assignment');
    assert_same('8', $assignment[2]['no'], 'обмен заданиями → §8');

    $bim = task_regulation_refs('bim_family_request');
    assert_same('8', $bim[2]['no'], 'ТИМ-заявка → §8');
});

// --- task_id_list: парсинг id из произвольной строки, дедуп, >0 ---
test('task_id_list извлекает положительные уникальные id', function (): void {
    assert_eq([1, 2, 3], task_id_list('1, 2, 2, 3'));
    assert_eq([12, 5], task_id_list('#12 и #5, снова #12'));
    assert_eq([], task_id_list('0 0 00'), 'нули отбрасываются');
    assert_eq([], task_id_list(null));
    assert_eq([], task_id_list('нет цифр'));
});

// --- priority_label ---
test('priority_label маппит приоритеты', function (): void {
    assert_same('Низкая', priority_label('low'));
    assert_same('Средняя', priority_label('mid'));
    assert_same('Высокая', priority_label('high'));
    assert_same('', priority_label(null));
});

// --- progress_fill_class: пороги и клампинг ---
test('progress_fill_class по порогам с клампингом 0..100', function (): void {
    assert_same('', progress_fill_class(0));
    assert_same('', progress_fill_class(30));
    assert_same('prog-fill--mid', progress_fill_class(31));
    assert_same('prog-fill--mid', progress_fill_class(70));
    assert_same('prog-fill--done', progress_fill_class(71));
    assert_same('prog-fill--done', progress_fill_class(150), 'клампится до 100');
    assert_same('', progress_fill_class(-5), 'клампится до 0');
});

// --- deadline_state_class: красный/жёлтый/норма относительно опорной даты ---
test('deadline_state_class сравнивает срок с опорной датой', function (): void {
    $today = '2026-06-08';
    assert_same('date-empty', deadline_state_class('', $today));
    assert_same('date-empty', deadline_state_class(null, $today));
    assert_same('date-red', deadline_state_class('2026-06-01', $today), 'просрочка');
    assert_same('date-amber', deadline_state_class('2026-06-08', $today), 'сегодня → в зоне 3 дней');
    assert_same('date-amber', deadline_state_class('2026-06-11', $today), 'через 3 дня');
    assert_same('date-normal', deadline_state_class('2026-12-01', $today), 'далеко');
});

// --- working_hours: рабочие часы по будням (Пн-Пт × 8), детерминированно ---
test('working_hours считает будни × 8 часов', function (): void {
    // 2024-01-01 — понедельник; 2024-01-05 — пятница => 5 будней
    assert_same(40.0, working_hours('2024-01-01', '2024-01-05'));
    // 2024-01-06 (сб) и 2024-01-07 (вс) => 0 будней
    assert_same(0.0, working_hours('2024-01-06', '2024-01-07'));
    // включает оба конца: один будний день
    assert_same(8.0, working_hours('2024-01-01', '2024-01-01'));
    // конец раньше начала
    assert_same(0.0, working_hours('2024-01-05', '2024-01-01'));
    // нет дат
    assert_same(0.0, working_hours(null, '2024-01-05'));
    assert_same(0.0, working_hours('2024-01-05', null));
});
