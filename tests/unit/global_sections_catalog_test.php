<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\DictionaryService;

function global_sections_catalog_test_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
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
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(scope_project_id, kind, value)
    )');
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, code TEXT)');
    $pdo->exec("INSERT INTO dictionary_items (scope_project_id, kind, value, label, discipline, sort_order)
        VALUES (0, 'section', 'ОВ', 'Отопление и вентиляция', 'ОВ', 40)");
    Database::useConnection($pdo);

    return $pdo;
}

test('общий справочник добавляет новый раздел и не переписывает существующий', function (): void {
    $pdo = global_sections_catalog_test_pdo();

    DictionaryService::addGlobalSection(' асу ', 'Автоматизированные системы управления');

    $asu = $pdo->query("SELECT * FROM dictionary_items WHERE scope_project_id = 0 AND kind = 'section' AND value = 'АСУ'")->fetch();
    assert_same('Автоматизированные системы управления', (string) ($asu['label'] ?? ''));
    assert_same('АСУ', (string) ($asu['discipline'] ?? ''));
    assert_same(1, (int) ($asu['active'] ?? 0));
    assert_same(50, (int) ($asu['sort_order'] ?? 0));

    assert_throws(
        fn () => DictionaryService::addGlobalSection('АСУ', 'Другое название'),
        'повторное добавление не должно превращаться в скрытое редактирование'
    );
    assert_same(
        'Автоматизированные системы управления',
        (string) $pdo->query("SELECT label FROM dictionary_items WHERE scope_project_id = 0 AND kind = 'section' AND value = 'АСУ'")->fetchColumn()
    );

    Database::reset();
});

test('форма без выбранного проекта не смешивает разделы разных проектов', function (): void {
    $pdo = global_sections_catalog_test_pdo();
    $pdo->exec("INSERT INTO projects (id, code) VALUES (7, 'PRJ-7')");
    $pdo->exec("INSERT INTO dictionary_items (project_id, scope_project_id, kind, value, label, discipline, sort_order)
        VALUES (7, 7, 'section', 'ЛОК', 'Локальный раздел проекта', 'ЛОК', 10)");

    $global = DictionaryService::forTaskForm();
    assert_same(['ОВ'], array_values(array_column($global['section'], 'value')));

    $project = DictionaryService::forTaskForm(7);
    assert_same(['ОВ', 'ЛОК'], array_values(array_column($project['section'], 'value')));

    Database::reset();
});
