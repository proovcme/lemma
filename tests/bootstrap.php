<?php

declare(strict_types=1);

/**
 * Тест-бутстрап. Поднимает ровно то, что нужно юнит-тестам:
 * автозагрузчик App\, хелперы и конфиг — БЕЗ старта сессии, обработчиков ошибок
 * и отправки заголовков (всё это живёт в app/bootstrap.php и не нужно в CLI).
 *
 * Намеренно НЕ читаем .env: тесты не должны зависеть от dev-окружения машины.
 * config/config.php сам подставляет безопасные дефолты, а тесты, которым нужна
 * БД, инжектят in-memory SQLite через Database::useConnection().
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/app/helpers.php';

$GLOBALS['config'] = require BASE_PATH . '/config/config.php';

date_default_timezone_set('Europe/Moscow');

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = BASE_PATH . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
