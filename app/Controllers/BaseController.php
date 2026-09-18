<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\RoleService;
use PDO;

abstract class BaseController
{
    protected function db(): PDO
    {
        return Database::pdo();
    }

    protected function render(string $view, array $data = []): void
    {
        view($view, $data);
    }

    protected function requireRole(array $roles): array
    {
        $user = require_auth();
        if (!RoleService::isAny($user['role'] ?? null, $roles)) {
            http_response_code(403);
            view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Недостаточно прав для этого раздела.']);
            exit;
        }

        return $user;
    }

    protected function validatedUploadPath(?array $file, array $allowedExtensions, array $allowedMimeTypes, int $maxBytes, string $label): string
    {
        if (!$file) {
            throw new \InvalidArgumentException('Выберите файл: ' . $label . '.');
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException($this->uploadErrorMessage($error, $label, $maxBytes));
        }
        if (empty($file['tmp_name'])) {
            throw new \InvalidArgumentException('Выберите файл: ' . $label . '.');
        }

        $tmpName = (string) $file['tmp_name'];
        if (!is_file($tmpName) || !is_uploaded_file($tmpName)) {
            throw new \InvalidArgumentException('Файл не прошёл проверку загрузки.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            $size = (int) (filesize($tmpName) ?: 0);
        }
        if ($size <= 0) {
            throw new \InvalidArgumentException('Файл пустой.');
        }
        if ($size > $maxBytes) {
            throw new \InvalidArgumentException('Файл больше допустимого размера.');
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new \InvalidArgumentException('Недопустимое расширение файла.');
        }

        $mimeType = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mimeType = (string) finfo_file($finfo, $tmpName);
                if (PHP_VERSION_ID < 80500) {
                    finfo_close($finfo);
                }
            }
        }

        if ($mimeType !== '' && !in_array($mimeType, $allowedMimeTypes, true)) {
            throw new \InvalidArgumentException('Недопустимый тип файла: ' . $mimeType . '.');
        }

        return $tmpName;
    }

    private function uploadErrorMessage(int $error, string $label, int $maxBytes): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $label . ' больше допустимого размера. Лимит приложения: ' . $this->humanBytes($maxBytes) . '; проверьте upload_max_filesize и post_max_size в PHP.',
            UPLOAD_ERR_PARTIAL => $label . ' загрузился не полностью. Повторите загрузку.',
            UPLOAD_ERR_NO_FILE => 'Выберите файл: ' . $label . '.',
            UPLOAD_ERR_NO_TMP_DIR => 'На сервере не настроена временная папка для загрузок.',
            UPLOAD_ERR_CANT_WRITE => 'Сервер не смог записать загруженный файл.',
            UPLOAD_ERR_EXTENSION => 'PHP-расширение остановило загрузку файла.',
            default => 'Ошибка загрузки файла: ' . $label . ' (код ' . $error . ').',
        };
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return (int) floor($bytes / 1024 / 1024) . ' МБ';
        }

        return (int) ceil($bytes / 1024) . ' КБ';
    }
}
