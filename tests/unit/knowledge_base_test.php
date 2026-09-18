<?php

declare(strict_types=1);

use App\Services\KnowledgeBaseService;
use App\Services\KnowledgeHtmlSanitizer;

test('база знаний разделяет чтение и административное редактирование', function (): void {
    assert_true(KnowledgeBaseService::canEdit(['role' => 'admin']));
    assert_true(KnowledgeBaseService::canEdit(['role' => 'director']));
    assert_same(false, KnowledgeBaseService::canEdit(['role' => 'adjacent_director']));
    assert_same(false, KnowledgeBaseService::canEdit(['role' => 'engineer']));
    assert_same(false, KnowledgeBaseService::canEdit(null));
});

test('редактор базы знаний очищает исполняемый HTML и опасные ссылки', function (): void {
    $html = KnowledgeHtmlSanitizer::sanitize(
        '<h2 onclick="alert(1)">Раздел</h2>'
        . '<script>alert(2)</script>'
        . '<p><img src=x onerror=alert(3)>Текст</p>'
        . '<a href="javascript:alert(4)" style="color:red">Плохо</a>'
        . '<a href="https://example.org/manual">Хорошо</a>'
        . '<blockquote data-x="1">Важно</blockquote>'
    );

    assert_true(!str_contains($html, '<script'));
    assert_true(!str_contains($html, '<img'));
    assert_true(!str_contains($html, 'onclick'));
    assert_true(!str_contains($html, 'javascript:'));
    assert_true(!str_contains($html, 'style='));
    assert_true(str_contains($html, 'https://example.org/manual'));
    assert_true(str_contains($html, 'rel="noopener noreferrer"'));
    assert_true(str_contains($html, 'class="knowledge-callout"'));
});

test('папки базы знаний ограничены четырьмя уровнями и защищены от циклов', function (): void {
    $pdo = knowledge_test_pdo();
    $root = KnowledgeBaseService::createFolder($pdo, ['name' => 'Уровень 1'], 1);
    $level2 = KnowledgeBaseService::createFolder($pdo, ['name' => 'Уровень 2', 'parent_id' => $root], 1);
    $level3 = KnowledgeBaseService::createFolder($pdo, ['name' => 'Уровень 3', 'parent_id' => $level2], 1);
    $level4 = KnowledgeBaseService::createFolder($pdo, ['name' => 'Уровень 4', 'parent_id' => $level3], 1);

    assert_throws(static function () use ($pdo, $level4): void {
        KnowledgeBaseService::createFolder($pdo, ['name' => 'Лишний уровень', 'parent_id' => $level4], 1);
    }, 'пятый уровень должен быть запрещён');

    assert_throws(static function () use ($pdo, $root, $level4): void {
        KnowledgeBaseService::updateFolder($pdo, $root, ['name' => 'Уровень 1', 'parent_id' => $level4], 1);
    }, 'перемещение родителя внутрь потомка должно быть запрещено');
});

test('публикация не смешивает рабочий черновик с видимой версией', function (): void {
    $pdo = knowledge_test_pdo();
    $folderId = KnowledgeBaseService::createFolder($pdo, ['name' => 'Инструкции'], 1);
    $documentId = KnowledgeBaseService::createDocument($pdo, [
        'folder_id' => $folderId,
        'title' => 'Первая инструкция',
        'summary' => 'Краткое описание',
        'body_html' => '<h2>Шаг 1</h2><p onclick="bad()">Безопасный текст</p><script>bad()</script>',
        'is_pinned' => '1',
    ], 1);

    $version = KnowledgeBaseService::publish($pdo, $documentId, [
        'folder_id' => $folderId,
        'title' => 'Первая инструкция',
        'summary' => 'Краткое описание',
        'body_html' => '<h2>Шаг 1</h2><p onclick="bad()">Безопасный текст</p><script>bad()</script>',
        'is_pinned' => '1',
    ], 1);
    assert_same(1, $version);

    $published = KnowledgeBaseService::document($pdo, $documentId, false);
    assert_same('published', $published['status']);
    assert_same('Первая инструкция', $published['title']);
    assert_true(!str_contains((string) $published['body_html'], '<script'));
    assert_same(1, count(KnowledgeBaseService::revisions($pdo, $documentId)));

    KnowledgeBaseService::saveDraft($pdo, $documentId, [
        'folder_id' => $folderId,
        'title' => 'Новая редакция',
        'summary' => 'Ещё не опубликовано',
        'body_html' => '<h2>Новый текст</h2><p>Только в черновике.</p>',
    ], 1);
    $stillPublished = KnowledgeBaseService::document($pdo, $documentId, false);
    $draft = KnowledgeBaseService::document($pdo, $documentId, true);
    assert_same('Первая инструкция', $stillPublished['title']);
    assert_same('Новая редакция', $draft['draft_title']);

    $revision = KnowledgeBaseService::revisions($pdo, $documentId)[0];
    KnowledgeBaseService::restoreRevision($pdo, $documentId, (int) $revision['id'], 1);
    $restoredDraft = KnowledgeBaseService::document($pdo, $documentId, true);
    assert_same('Первая инструкция', $restoredDraft['draft_title']);
});

function knowledge_test_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Администратор')");
    $pdo->exec('CREATE TABLE knowledge_folders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id INTEGER,
        name TEXT NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 100,
        created_by INTEGER,
        updated_by INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        archived_at TEXT
    )');
    $pdo->exec("CREATE TABLE knowledge_documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        folder_id INTEGER,
        title TEXT NOT NULL,
        summary TEXT NOT NULL DEFAULT '',
        body_html TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'draft',
        current_version INTEGER NOT NULL DEFAULT 0,
        is_pinned INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 100,
        draft_folder_id INTEGER,
        draft_title TEXT NOT NULL DEFAULT '',
        draft_summary TEXT NOT NULL DEFAULT '',
        draft_body_html TEXT NOT NULL DEFAULT '',
        draft_is_pinned INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER,
        updated_by INTEGER,
        published_at TEXT,
        draft_updated_at TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec('CREATE TABLE knowledge_document_revisions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        document_id INTEGER NOT NULL,
        version_no INTEGER NOT NULL,
        folder_id INTEGER,
        title TEXT NOT NULL,
        summary TEXT NOT NULL,
        body_html TEXT NOT NULL,
        is_pinned INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    return $pdo;
}
