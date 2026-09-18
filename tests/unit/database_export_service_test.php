<?php

declare(strict_types=1);

use App\Services\DatabaseExportService;

test('выгрузка содержит полный дамп, manifest и файловые логи', function (): void {
    if (!class_exists(ZipArchive::class)) {
        assert_true(true, 'ZipArchive отсутствует в runtime, тест выгрузки пропущен');
        return;
    }

    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE sample (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
    $pdo->exec("INSERT INTO sample (title) VALUES ('Проверка')");
    $dir = sys_get_temp_dir() . '/locia-db-export-' . bin2hex(random_bytes(5));
    mkdir($dir, 0700, true);
    $path = $dir . '/database.zip';
    $logs = $dir . '/logs';
    mkdir($logs, 0700, true);
    file_put_contents($logs . '/application.log', "test log\n");

    try {
        $result = (new DatabaseExportService())->export($pdo, $path, $logs);
        assert_same(DatabaseExportService::FORMAT, (string) $result['manifest']['format']);
        assert_same('sqlite', (string) $result['manifest']['database_driver']);

        $zip = new ZipArchive();
        assert_same(true, $zip->open($path) === true);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = (string) $zip->getNameIndex($i);
        }
        sort($entries);
        assert_same(['database/locia.sqlite', 'logs/application.log', 'manifest.json'], $entries);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $dump = (string) $zip->getFromName('database/locia.sqlite');
        assert_same(hash('sha256', $dump), (string) $manifest['sha256']);
        assert_same(hash('sha256', "test log\n"), (string) $manifest['logs']['logs/application.log']['sha256']);
        $zip->close();
    } finally {
        @unlink($path);
        @unlink($logs . '/application.log');
        @rmdir($logs);
        @rmdir($dir);
    }
});
