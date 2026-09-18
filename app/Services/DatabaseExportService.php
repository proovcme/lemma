<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;
use ZipArchive;

final class DatabaseExportService
{
    public const FORMAT = 'locia-database-backup-v1';

    /**
     * @return array{path:string,manifest:array<string,mixed>}
     */
    public function export(?PDO $pdo = null, ?string $targetPath = null, ?string $logsRoot = null): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('На сервере не установлен PHP-модуль zip. Выгрузка базы недоступна.');
        }

        $pdo = $pdo ?? Database::pdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Выгрузка базы не поддерживает драйвер ' . $driver . '.');
        }

        $storage = rtrim((string) config('database_export.storage_dir', BASE_PATH . '/storage/database-exports'), '/\\');
        $this->ensurePrivateDir($storage);
        $targetPath = $targetPath ?: $storage . '/locia-database-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
        $dumpPath = $storage . '/.' . basename($targetPath, '.zip') . ($driver === 'sqlite' ? '.sqlite' : '.sql');
        $logStage = $storage . '/.logs-' . bin2hex(random_bytes(8));
        $entry = $driver === 'sqlite' ? 'database/locia.sqlite' : 'database/locia.sql';
        $logsRoot = $logsRoot ?? BASE_PATH . '/storage/logs';

        try {
            if ($driver === 'sqlite') {
                $quoted = str_replace("'", "''", $dumpPath);
                $pdo->exec("VACUUM INTO '{$quoted}'");
            } else {
                $this->dumpMysql($dumpPath);
            }

            if (!is_file($dumpPath) || (int) filesize($dumpPath) <= 0) {
                throw new RuntimeException('Выгрузка базы получилась пустой. ZIP не создан.');
            }
            @chmod($dumpPath, 0600);

            $manifest = [
                'format' => self::FORMAT,
                'created_at' => date(DATE_ATOM),
                'source_version' => (string) config('app.version.version', 'unknown'),
                'database_driver' => $driver,
                'database_entry' => $entry,
                'bytes' => (int) filesize($dumpPath),
                'sha256' => hash_file('sha256', $dumpPath),
                'logs' => [],
            ];

            $zip = new ZipArchive();
            if ($zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Не удалось создать ZIP. Проверьте свободное место и права storage.');
            }
            try {
                if (!$zip->addFile($dumpPath, $entry)) {
                    throw new RuntimeException('Не удалось добавить дамп базы в ZIP.');
                }
                foreach ($this->logFiles($logsRoot) as $log) {
                    $logEntry = 'logs/' . $log['relative'];
                    $stagedLog = $logStage . '/' . $log['relative'];
                    $this->ensurePrivateDir(dirname($stagedLog));
                    if (!copy($log['path'], $stagedLog)) {
                        throw new RuntimeException('Не удалось зафиксировать журнал для ZIP: ' . $log['relative'] . '.');
                    }
                    @chmod($stagedLog, 0600);
                    if (!$zip->addFile($stagedLog, $logEntry)) {
                        throw new RuntimeException('Не удалось добавить журнал в ZIP: ' . $log['relative'] . '.');
                    }
                    $manifest['logs'][$logEntry] = [
                        'bytes' => (int) filesize($stagedLog),
                        'sha256' => hash_file('sha256', $stagedLog),
                    ];
                }
                ksort($manifest['logs']);
                $zip->addFromString(
                    'manifest.json',
                    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                );
            } finally {
                $zip->close();
            }
            @chmod($targetPath, 0600);

            return ['path' => $targetPath, 'manifest' => $manifest];
        } catch (\Throwable $e) {
            @unlink($targetPath);
            throw $e;
        } finally {
            @unlink($dumpPath);
            $this->removeTree($logStage);
        }
    }

    private function dumpMysql(string $target): void
    {
        $password = (string) config('db.password', '');
        $command = [
            $this->findMysqlDump(),
            '--host=' . (string) config('db.host', '127.0.0.1'),
            '--port=' . (string) config('db.port', '3306'),
            '--default-character-set=' . (string) config('db.charset', 'utf8mb4'),
            '--single-transaction',
            '--quick',
            '--skip-add-locks',
            '--hex-blob',
            '--routines',
            '--triggers',
            '--events',
            '-u',
            (string) config('db.username', ''),
            (string) config('db.database', ''),
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $target, 'wb'],
            2 => ['pipe', 'w'],
        ];

        $previousMysqlPwd = getenv('MYSQL_PWD');
        if ($password !== '') {
            putenv('MYSQL_PWD=' . $password);
        }
        try {
            $process = proc_open($command, $descriptors, $pipes, BASE_PATH, null, ['bypass_shell' => true]);
        } finally {
            $previousMysqlPwd === false ? putenv('MYSQL_PWD') : putenv('MYSQL_PWD=' . $previousMysqlPwd);
        }
        if (!is_resource($process)) {
            throw new RuntimeException('Не удалось запустить утилиту выгрузки MariaDB.');
        }

        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            @unlink($target);
            $details = trim((string) $stderr);
            throw new RuntimeException('Не удалось выгрузить MariaDB' . ($details !== '' ? ': ' . $details : '.') );
        }
    }

    private function findMysqlDump(): string
    {
        $candidates = [
            BASE_PATH . '/deploy/standalone/mariadb/bin/mysqldump.exe',
            BASE_PATH . '/deploy/standalone/mariadb/bin/mariadb-dump.exe',
            BASE_PATH . '/deploy/standalone/mysql/bin/mysqldump.exe',
            BASE_PATH . '/deploy/standalone/mysql/bin/mariadb-dump.exe',
            'C:/laragon/bin/mysql/mariadb-10.6/bin/mysqldump.exe',
            'C:/laragon/bin/mysql/mariadb-10.6/bin/mariadb-dump.exe',
            'C:/laragon/bin/mysql/mysql-8.0/bin/mysqldump.exe',
            'C:/laragon/bin/mysql/mysql-5.7/bin/mysqldump.exe',
        ];
        foreach (glob('C:/laragon/bin/mysql/*/bin/{mysqldump,mariadb-dump}.exe', GLOB_BRACE) ?: [] as $path) {
            $candidates[] = $path;
        }
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return 'mysqldump';
    }

    private function ensurePrivateDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать закрытую папку выгрузки базы.');
        }
        @chmod($dir, 0700);
    }

    /**
     * @return list<array{path:string,relative:string}>
     */
    private function logFiles(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }
        $root = rtrim($root, '/\\');
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }
            $path = $item->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            if ($relative === '' || str_starts_with($relative, '/') || preg_match('#(^|/)\.\.(/|$)#', $relative)
                || str_contains($relative, "\0")) {
                throw new RuntimeException('В папке журналов найден небезопасный путь.');
            }
            $files[] = ['path' => $path, 'relative' => $relative];
        }
        usort($files, static fn (array $a, array $b): int => strcmp($a['relative'], $b['relative']));
        return $files;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
