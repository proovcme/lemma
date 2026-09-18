<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $db = config('db');
        if (($db['connection'] ?? 'mysql') === 'sqlite') {
            self::$pdo = new PDO('sqlite:' . $db['sqlite_path'], null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA foreign_keys = ON');

            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['database'],
            $db['charset']
        );

        self::$pdo = new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Shared scope clauses reuse named placeholders; MySQL native prepares reject that pattern.
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);

        return self::$pdo;
    }

    public static function driver(): string
    {
        return (string) config('db.connection', 'mysql');
    }

    /**
     * Внедрить готовое PDO-подключение (для тестов или явной конфигурации).
     * Обратносовместимо: рантайм по-прежнему использует pdo() с ленивой инициализацией;
     * этот метод лишь позволяет подменить соединение, не трогая существующие вызовы.
     */
    public static function useConnection(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /**
     * Сбросить закешированное соединение (тесты изолируют состояние между кейсами).
     */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
