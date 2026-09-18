<?php

declare(strict_types=1);

namespace App\Services;

final class ProjectFolderService
{
    public function create(string $root): array
    {
        $rootPath = $this->filesystemPath($root);
        if ($rootPath === '') {
            throw new \RuntimeException('Папка проекта не указана.');
        }

        $result = ['created' => 0, 'existing' => 0];
        $this->ensureDirectory($rootPath, $result);
        foreach ($this->templatePaths(project_folder_template()) as $relativePath) {
            $this->ensureDirectory(file_path_join($rootPath, $relativePath), $result);
        }

        return $result;
    }

    public function open(string $root, string $relative = ''): string
    {
        $targetPath = $this->targetPath($root, $relative);
        if (!is_dir($targetPath)) {
            throw new \RuntimeException('Папка не найдена: ' . $targetPath . '.');
        }

        return $targetPath;
    }

    public function targetPath(string $root, string $relative = ''): string
    {
        $rootPath = rtrim($this->filesystemPath($root), "\\/");
        if ($rootPath === '') {
            throw new \RuntimeException('Папка проекта не указана.');
        }

        $relativePath = $this->relativePath($relative);
        $targetPath = $relativePath === '' ? $rootPath : file_path_join($rootPath, $relativePath);
        $this->assertInsideRoot($rootPath, $targetPath);

        return $targetPath;
    }

    public function filesystemPath(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '\\\\') || preg_match('#^[a-zA-Z]:[\\\\/]#', $value) || str_starts_with($value, '/')) {
            return $value;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        if ($scheme === null) {
            return $value;
        }

        if (strtolower($scheme) !== 'file') {
            throw new \RuntimeException('Для создания папок нужен локальный путь, UNC-путь или file:// URL.');
        }

        if (str_starts_with($value, 'file:////')) {
            return '\\\\' . str_replace('/', '\\', substr($value, 9));
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            throw new \RuntimeException('Не удалось разобрать file:// путь папки проекта.');
        }

        $decoded = rawurldecode($path);
        if (preg_match('#^/[a-zA-Z]:/#', $decoded)) {
            $decoded = substr($decoded, 1);
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $decoded);
    }

    private function relativePath(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('#^[a-zA-Z]:[\\\\/]#', $value) || str_starts_with($value, '\\\\') || str_starts_with($value, '/')) {
            throw new \RuntimeException('Можно открыть только папку внутри проекта.');
        }

        $segments = preg_split('#[\\\\/]+#', $value) ?: [];
        $clean = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..' || preg_match('/[\x00-\x1F]/', $segment)) {
                throw new \RuntimeException('Некорректный путь папки.');
            }
            $clean[] = $segment;
        }

        return implode('/', $clean);
    }

    private function assertInsideRoot(string $rootPath, string $targetPath): void
    {
        $root = $this->comparePath($rootPath);
        $target = $this->comparePath($targetPath);
        if ($target === $root || str_starts_with($target, $root . '\\')) {
            return;
        }

        throw new \RuntimeException('Путь выходит за пределы папки проекта.');
    }

    private function comparePath(string $path): string
    {
        $path = str_replace('/', '\\', trim($path));
        $path = rtrim($path, '\\');

        return mb_strtolower($path, 'UTF-8');
    }

    private function ensureDirectory(string $path, array &$result): void
    {
        if (is_dir($path)) {
            $result['existing']++;
            return;
        }

        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            $error = error_get_last();
            throw new \RuntimeException('Не удалось создать папку "' . $path . '"' . (!empty($error['message']) ? ': ' . $error['message'] : '.'));
        }

        $result['created']++;
    }

    private function templatePaths(array $items): array
    {
        $paths = [];
        foreach ($items as $item) {
            $path = trim((string) ($item['path'] ?? ''), "\\/");
            if ($path !== '') {
                $paths[] = $path;
            }
            if (!empty($item['children']) && is_array($item['children'])) {
                array_push($paths, ...$this->templatePaths($item['children']));
            }
        }

        return array_values(array_unique($paths));
    }
}
