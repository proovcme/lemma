<?php

declare(strict_types=1);

/**
 * Точка входа юнит-тестов: `php tests/run.php`
 * Подхватывает bootstrap + lib, затем все tests/unit/*_test.php, печатает итог
 * и завершается ненулевым кодом при падениях (для smoke/CI-парности).
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

$dir = __DIR__ . '/unit';
$files = glob($dir . '/*_test.php') ?: [];
sort($files);

echo 'Лемма — юнит-тесты (' . count($files) . ' файл(ов))' . PHP_EOL;

foreach ($files as $file) {
    require $file;
}

exit(TestRunner::summary());
