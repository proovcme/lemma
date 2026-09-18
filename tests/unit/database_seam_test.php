<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Проверяет DI-шов Database::useConnection()/reset() на in-memory SQLite.
 * Это фундамент под будущие сервис-тесты: позволяет инжектить изолированную БД,
 * не трогая статический синглтон рантайма.
 */

test('Database::useConnection инжектит соединение, pdo() его возвращает', function (): void {
    Database::reset();
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    Database::useConnection($pdo);

    assert_true(Database::pdo() === $pdo, 'pdo() должен вернуть тот же объект, что инжектнули');

    // и на нём реально работают запросы
    Database::pdo()->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
    Database::pdo()->exec("INSERT INTO t (name) VALUES ('a'), ('b')");
    $count = (int) Database::pdo()->query('SELECT COUNT(*) FROM t')->fetchColumn();
    assert_same(2, $count);

    Database::reset();
});

test('Database::reset очищает закешированное соединение', function (): void {
    $pdo = new PDO('sqlite::memory:');
    Database::useConnection($pdo);
    assert_true(Database::pdo() === $pdo);

    Database::reset();
    // после reset инжектим другое — и видим именно его (старое не залипло)
    $other = new PDO('sqlite::memory:');
    Database::useConnection($other);
    assert_true(Database::pdo() === $other, 'после reset() возвращается новое соединение');

    Database::reset();
});
