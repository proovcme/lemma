<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class TaskAttachmentService
{
    public const MAX_FILE_BYTES = 20 * 1024 * 1024;
    public const MAX_REQUEST_BYTES = 60 * 1024 * 1024;
    public const MAX_FILES_PER_REQUEST = 8;
    public const MAX_FILES_PER_TASK = 30;

    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif',
        'pdf', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'zip', '7z', 'dwg', 'dxf', 'ifc', 'ifczip', 'frag', 'nwc', 'nwd', 'nwf', 'rvt',
    ];

    private const INLINE_IMAGE_MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif',
    ];

    /**
     * @return list<array{name:string,tmp_name:string,size:int,extension:string,mime_type:string}>
     */
    public static function validateIncoming(?array $bag): array
    {
        $files = self::normalizeBag($bag);
        if (count($files) > self::MAX_FILES_PER_REQUEST) {
            throw new InvalidArgumentException('За один раз можно прикрепить не больше ' . self::MAX_FILES_PER_REQUEST . ' файлов.');
        }

        $prepared = [];
        $totalBytes = 0;
        foreach ($files as $file) {
            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException(self::uploadErrorMessage($error));
            }

            $tmpName = (string) ($file['tmp_name'] ?? '');
            if ($tmpName === '' || !is_file($tmpName)) {
                throw new InvalidArgumentException('Загруженный файл не найден во временной папке. Выберите его ещё раз.');
            }
            if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmpName)) {
                throw new InvalidArgumentException('Файл не прошёл проверку безопасной загрузки.');
            }

            $name = self::safeOriginalName((string) ($file['name'] ?? 'file'));
            $extension = mb_strtolower((string) pathinfo($name, PATHINFO_EXTENSION), 'UTF-8');
            if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                throw new InvalidArgumentException('Формат .' . ($extension !== '' ? $extension : '?') . ' не поддерживается. Разрешены фото, PDF, офисные документы, архивы и файлы проектных моделей.');
            }

            $size = (int) ($file['size'] ?? 0);
            if ($size <= 0) {
                $size = (int) (filesize($tmpName) ?: 0);
            }
            if ($size <= 0) {
                throw new InvalidArgumentException('Файл «' . $name . '» пустой.');
            }
            if ($size > self::MAX_FILE_BYTES) {
                throw new InvalidArgumentException('Файл «' . $name . '» больше 20 МБ. Уменьшите его или приложите ссылку.');
            }
            $totalBytes += $size;
            if ($totalBytes > self::MAX_REQUEST_BYTES) {
                throw new InvalidArgumentException('Общий размер вложений больше 60 МБ. Загрузите файлы несколькими порциями.');
            }

            $mime = self::detectMime($tmpName);
            self::validateMime($extension, $mime, $name);
            $prepared[] = [
                'name' => $name,
                'tmp_name' => $tmpName,
                'size' => $size,
                'extension' => $extension,
                'mime_type' => $mime,
            ];
        }

        return $prepared;
    }

    /**
     * @param list<array{name:string,tmp_name:string,size:int,extension:string,mime_type:string}> $prepared
     * @return list<array<string,mixed>>
     */
    public static function storePrepared(int $taskId, int $userId, array $prepared, ?PDO $pdo = null): array
    {
        if ($prepared === []) {
            return [];
        }
        $pdo = $pdo ?? Database::pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM attachments WHERE task_id = ?');
        $count->execute([$taskId]);
        if ((int) $count->fetchColumn() + count($prepared) > self::MAX_FILES_PER_TASK) {
            throw new InvalidArgumentException('В одной задаче можно хранить не больше ' . self::MAX_FILES_PER_TASK . ' вложений.');
        }

        $relativeDir = 'tasks/' . $taskId;
        $targetDir = self::root() . '/' . $relativeDir;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0700, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Не удалось создать защищённую папку вложений.');
        }

        $storedPaths = [];
        $rows = [];
        $insert = $pdo->prepare('INSERT INTO attachments (task_id, user_id, filename, path, size) VALUES (?, ?, ?, ?, ?)');
        try {
            foreach ($prepared as $file) {
                $storedName = bin2hex(random_bytes(18)) . '.' . $file['extension'];
                $relativePath = $relativeDir . '/' . $storedName;
                $target = self::root() . '/' . $relativePath;
                $moved = PHP_SAPI === 'cli'
                    ? @copy($file['tmp_name'], $target)
                    : @move_uploaded_file($file['tmp_name'], $target);
                if (!$moved) {
                    throw new RuntimeException('Сервер не смог сохранить файл «' . $file['name'] . '».');
                }
                @chmod($target, 0600);
                $storedPaths[] = $target;
                $insert->execute([$taskId, $userId ?: null, $file['name'], $relativePath, $file['size']]);
                $rows[] = [
                    'id' => (int) $pdo->lastInsertId(),
                    'task_id' => $taskId,
                    'user_id' => $userId,
                    'filename' => $file['name'],
                    'path' => $relativePath,
                    'size' => $file['size'],
                    'mime_type' => $file['mime_type'],
                ];
            }
        } catch (\Throwable $e) {
            foreach ($storedPaths as $path) {
                @unlink($path);
            }
            throw $e;
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public static function forTask(int $taskId, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? Database::pdo();
        $stmt = $pdo->prepare('SELECT a.*, u.name AS user_name FROM attachments a LEFT JOIN users u ON u.id = a.user_id WHERE a.task_id = ? ORDER BY a.created_at DESC, a.id DESC');
        $stmt->execute([$taskId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['mime_type'] = self::detectMime(self::absolutePath((string) $row['path']));
            $row['is_image'] = self::isInlineImage((string) $row['mime_type']);
        }
        unset($row);

        return $rows;
    }

    public static function findForTask(int $taskId, int $attachmentId, ?PDO $pdo = null): ?array
    {
        $pdo = $pdo ?? Database::pdo();
        $stmt = $pdo->prepare('SELECT a.*, u.name AS user_name FROM attachments a LEFT JOIN users u ON u.id = a.user_id WHERE a.task_id = ? AND a.id = ? LIMIT 1');
        $stmt->execute([$taskId, $attachmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['absolute_path'] = self::absolutePath((string) $row['path']);
        $row['mime_type'] = self::detectMime((string) $row['absolute_path']);
        $row['is_image'] = self::isInlineImage((string) $row['mime_type']);

        return $row;
    }

    public static function delete(int $taskId, int $attachmentId, ?PDO $pdo = null): ?array
    {
        $pdo = $pdo ?? Database::pdo();
        $row = self::findForTask($taskId, $attachmentId, $pdo);
        if ($row === null) {
            return null;
        }
        $pdo->prepare('DELETE FROM attachments WHERE id = ? AND task_id = ?')->execute([$attachmentId, $taskId]);
        @unlink((string) $row['absolute_path']);

        return $row;
    }

    /** @param list<int> $taskIds */
    public static function deleteTaskFiles(array $taskIds, ?PDO $pdo = null): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
        if ($ids === []) {
            return;
        }
        $pdo = $pdo ?? Database::pdo();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare('SELECT path FROM attachments WHERE task_id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $relativePath) {
            @unlink(self::absolutePath((string) $relativePath));
        }
    }

    public static function isInlineImage(string $mime): bool
    {
        return in_array($mime, self::INLINE_IMAGE_MIMES, true);
    }

    public static function absolutePath(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', trim($relativePath));
        if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, '../') || !preg_match('#^tasks/\d+/[a-f0-9]{36}\.[a-z0-9]+$#', $relativePath)) {
            throw new RuntimeException('Некорректный путь вложения.');
        }

        return self::root() . '/' . $relativePath;
    }

    private static function root(): string
    {
        return BASE_PATH . '/storage/uploads';
    }

    /** @return list<array<string,mixed>> */
    private static function normalizeBag(?array $bag): array
    {
        if (!$bag || !array_key_exists('name', $bag)) {
            return [];
        }
        if (!is_array($bag['name'])) {
            return [$bag];
        }

        $files = [];
        foreach ($bag['name'] as $index => $name) {
            $files[] = [
                'name' => $name,
                'type' => $bag['type'][$index] ?? '',
                'tmp_name' => $bag['tmp_name'][$index] ?? '',
                'error' => $bag['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $bag['size'][$index] ?? 0,
            ];
        }

        return $files;
    }

    private static function safeOriginalName(string $name): string
    {
        $name = trim(str_replace(["\0", '/', '\\'], '', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?: 'file';
        if (mb_strlen($name, 'UTF-8') > 180) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $stem = pathinfo($name, PATHINFO_FILENAME);
            $name = mb_substr($stem, 0, 160, 'UTF-8') . ($extension !== '' ? '.' . $extension : '');
        }

        return $name !== '' ? $name : 'file';
    }

    private static function detectMime(string $path): string
    {
        if (!is_file($path)) {
            return 'application/octet-stream';
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string) finfo_file($finfo, $path);
                if (PHP_VERSION_ID < 80500) {
                    finfo_close($finfo);
                }
                if ($mime !== '') {
                    return $mime;
                }
            }
        }

        return 'application/octet-stream';
    }

    private static function validateMime(string $extension, string $mime, string $name): void
    {
        $imageExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'];
        if (in_array($extension, $imageExtensions, true) && !str_starts_with($mime, 'image/') && $mime !== 'application/octet-stream') {
            throw new InvalidArgumentException('Файл «' . $name . '» имеет расширение изображения, но его содержимое не похоже на фото.');
        }
        if ($extension === 'pdf' && $mime !== 'application/pdf') {
            throw new InvalidArgumentException('Файл «' . $name . '» не является корректным PDF.');
        }
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл превышает лимит сервера. Максимум для одного вложения — 20 МБ.',
            UPLOAD_ERR_PARTIAL => 'Файл загрузился не полностью. Проверьте связь и повторите попытку.',
            UPLOAD_ERR_NO_TMP_DIR => 'На сервере недоступна временная папка загрузок. Сообщите администратору.',
            UPLOAD_ERR_CANT_WRITE => 'Сервер не смог записать файл. Сообщите администратору.',
            UPLOAD_ERR_EXTENSION => 'Сервер остановил загрузку файла из-за проверки расширения.',
            default => 'Не удалось загрузить файл (код загрузки ' . $error . '). Повторите попытку.',
        };
    }
}
