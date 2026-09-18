<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\DocumentRevisionService;

test('DocumentRevisionService создаёт изм. 0 для первой выдачи и требует основание для повторной', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);
    $pdo->exec('CREATE TABLE task_issuances (id INTEGER PRIMARY KEY, issue_number INTEGER, issued_at TEXT, status TEXT)');
    $pdo->exec('CREATE TABLE document_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, task_id INTEGER, issuance_id INTEGER, revision_no INTEGER, reason TEXT, summary TEXT, created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("INSERT INTO task_issuances (id, issue_number, issued_at, status) VALUES (10, 1, '2026-06-11', 'issued'), (11, 2, '2026-06-12', 'remarks')");

    $task = ['id' => 5, 'project_id' => 7];
    $revisionId = DocumentRevisionService::createForIssuance($pdo, $task, 10, 1, 3, '', '');

    assert_same(1, $revisionId);
    assert_same(0, (int) $pdo->query('SELECT revision_no FROM document_revisions WHERE id = 1')->fetchColumn());
    assert_same('Первичная выдача', (string) $pdo->query('SELECT reason FROM document_revisions WHERE id = 1')->fetchColumn());

    assert_throws(static function () use ($pdo, $task): void {
        DocumentRevisionService::createForIssuance($pdo, $task, 11, 2, 3, '', '');
    }, 'повторная выдача без основания должна падать');

    DocumentRevisionService::createForIssuance($pdo, $task, 11, 2, 3, 'Замечания заказчика', 'Уточнен состав листов');
    assert_same(1, (int) $pdo->query('SELECT revision_no FROM document_revisions WHERE id = 2')->fetchColumn());

    Database::reset();
});
