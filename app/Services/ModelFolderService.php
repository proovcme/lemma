<?php

declare(strict_types=1);

namespace App\Services;

final class ModelFolderService
{
    private const MODEL_EXTENSIONS = ['frag', 'ifc', 'ifczip'];
    private const SERVE_MIME = [
        'ifc' => 'application/octet-stream',
        'ifczip' => 'application/zip',
        'json' => 'application/json',
        'frag' => 'application/octet-stream',
    ];

    public function scan(string $folder, int $limit = 500): array
    {
        return $this->scanDetailed($folder, $limit)['models'];
    }

    public function scanDetailed(string $folder, int $limit = 500): array
    {
        $root = $this->filesystemPath($folder);
        $result = [
            'folder' => $folder,
            'root' => $root,
            'accessible' => false,
            'models' => [],
            'errors' => [],
            'skipped' => 0,
            'limited' => false,
            'files_seen' => 0,
            'dirs_seen' => 0,
            'extension_counts' => [],
            'sample_files' => [],
            'supported_files' => [],
            '_seen_paths' => [],
        ];

        if ($root === '' || !is_dir($root)) {
            if ($root !== '') {
                $result['errors'][] = 'Папка недоступна серверу: ' . $root;
            }
            return $result;
        }

        $found = [];
        $result['accessible'] = true;
        $root = rtrim($root, "\\/");
        $this->scanDirectoryIterator($root, $found, $result, $limit);
        $this->scanDirectory($root, $root, $found, $result, $limit);

        $byBase = [];
        foreach ($found as $model) {
            $key = strtolower((string) $model['base']);
            if (!isset($byBase[$key]) || ($model['kind'] === 'frag' && $byBase[$key]['kind'] !== 'frag')) {
                $byBase[$key] = $model;
            }
        }

        $models = array_values($byBase);
        usort($models, static fn ($a, $b) => strcasecmp((string) $a['rel'], (string) $b['rel']));

        $result['models'] = $models;
        unset($result['_seen_paths']);
        return $result;
    }

    public function filesystemPath(string $folder): string
    {
        return (new ProjectFolderService())->filesystemPath($folder);
    }

    public function resolve(string $folder, string $relativePath): ?string
    {
        $root = $this->filesystemPath($folder);
        $realRoot = $root !== '' ? realpath($root) : false;
        if ($realRoot === false || !is_dir($realRoot)) {
            return null;
        }

        $relativePath = trim($relativePath);
        if ($relativePath === '' || preg_match('/[\x00-\x1F]/', $relativePath) || str_contains($relativePath, '..')) {
            return null;
        }
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

        $target = realpath($realRoot . DIRECTORY_SEPARATOR . $relativePath);
        if ($target === false || !is_file($target) || !str_starts_with($target, $realRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $this->modelKindForPath($target) !== null || isset(self::SERVE_MIME[strtolower(pathinfo($target, PATHINFO_EXTENSION))])
            ? $target
            : null;
    }

    public function mimeFor(string $path): ?string
    {
        $kind = $this->modelKindForPath($path);
        if ($kind !== null) {
            return self::SERVE_MIME[$kind] ?? null;
        }

        return self::SERVE_MIME[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    }

    public function fragmentCachePath(string $scope, string $relativePath, string $sourcePath): ?string
    {
        if ($relativePath === '' || !is_file($sourcePath)) {
            return null;
        }

        $scope = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($scope)) ?: 'model-folder';
        $relHash = substr(sha1(str_replace('\\', '/', $relativePath)), 0, 12);
        $signature = (string) @filemtime($sourcePath) . '-' . (string) @filesize($sourcePath);
        $fileHash = substr(sha1($sourcePath . '|' . $signature), 0, 16);

        return BASE_PATH . '/storage/atlas-fragments/' . $scope . '-' . $relHash . '-' . $fileHash . '.frag';
    }

    public function cleanOldFragmentCache(string $scope, string $relativePath, string $keepPath): void
    {
        $scope = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($scope)) ?: 'model-folder';
        $relHash = substr(sha1(str_replace('\\', '/', $relativePath)), 0, 12);
        $dir = dirname($keepPath);
        foreach (glob($dir . '/' . $scope . '-' . $relHash . '-*.frag') ?: [] as $old) {
            if ($old !== $keepPath) {
                @unlink($old);
            }
        }
    }

    private function scanDirectory(string $root, string $dir, array &$found, array &$result, int $limit): void
    {
        if (count($found) >= $limit) {
            $result['limited'] = true;
            return;
        }

        $items = @scandir($dir);
        if ($items === false) {
            $result['skipped']++;
            $result['errors'][] = 'Нет доступа к вложенной папке: ' . $this->relativeLabel($root, $dir);
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (@is_link($path)) {
                continue;
            }

            if (@is_dir($path)) {
                $result['dirs_seen']++;
                $this->scanDirectory($root, $path, $found, $result, $limit);
                if (count($found) >= $limit) {
                    $result['limited'] = true;
                    return;
                }
                continue;
            }

            if (!@is_file($path)) {
                continue;
            }

            $kind = $this->modelKindForPath($path);
            $this->noteSeenFile($root, $path, $result);
            if ($kind === null) {
                continue;
            }

            $this->appendModel($root, $path, $kind, $found);

            if (count($found) >= $limit) {
                $result['limited'] = true;
                return;
            }
        }
    }

    private function scanDirectoryIterator(string $root, array &$found, array &$result, int $limit): void
    {
        try {
            $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
            $iterator = new \RecursiveIteratorIterator(
                $directory,
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            foreach ($iterator as $file) {
                if (count($found) >= $limit) {
                    $result['limited'] = true;
                    return;
                }

                try {
                    if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                        continue;
                    }

                    $path = $file->getPathname();
                    $kind = $this->modelKindForPath($path);
                    $this->noteSeenFile($root, $path, $result);
                    if ($kind === null) {
                        continue;
                    }

                    $this->appendModel($root, $path, $kind, $found);
                } catch (\Throwable $e) {
                    $result['skipped']++;
                    $result['errors'][] = 'Не удалось прочитать файл модели: ' . $this->relativeLabel($root, (string) ($path ?? $root));
                }
            }
        } catch (\Throwable $e) {
            $result['skipped']++;
            $result['errors'][] = 'Итератор папки недоступен: ' . $this->relativeLabel($root, $root);
        }
    }

    private function appendModel(string $root, string $path, string $kind, array &$found): void
    {
        $rel = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
        if ($rel === '') {
            return;
        }

        $found[] = [
            'base' => preg_replace('/\.(frag|ifc|ifczip|ifc\.zip)$/i', '', $rel),
            'rel' => $rel,
            'ext' => $kind === 'ifczip' && preg_match('/\.ifc\.zip$/i', $rel) ? 'ifc.zip' : $kind,
            'kind' => $kind,
            'name' => basename($rel),
            'mtime' => (int) @filemtime($path),
            'size' => (int) @filesize($path),
        ];
    }

    private function modelKindForPath(string $path): ?string
    {
        $normalized = strtolower(str_replace('\\', '/', $path));
        if (str_ends_with($normalized, '.ifc.zip')) {
            return 'ifczip';
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, self::MODEL_EXTENSIONS, true) ? $ext : null;
    }

    private function noteSeenFile(string $root, string $path, array &$result): void
    {
        $realPath = @realpath($path);
        $fingerprintPath = is_string($realPath) && $realPath !== '' ? $realPath : $path;
        $fingerprint = mb_strtolower(str_replace('\\', '/', $fingerprintPath), 'UTF-8');
        if (isset($result['_seen_paths'][$fingerprint])) {
            return;
        }
        $result['_seen_paths'][$fingerprint] = true;

        $result['files_seen'] = (int) ($result['files_seen'] ?? 0) + 1;

        $rel = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
        $extension = $this->extensionLabel($path);
        $result['extension_counts'][$extension] = (int) ($result['extension_counts'][$extension] ?? 0) + 1;
        if (count($result['sample_files'] ?? []) < 8 && $rel !== '') {
            $result['sample_files'][] = $rel;
        }
        if ($this->modelKindForPath($path) !== null && count($result['supported_files'] ?? []) < 8 && $rel !== '') {
            $result['supported_files'][] = $rel;
        }
    }

    private function extensionLabel(string $path): string
    {
        $normalized = strtolower(str_replace('\\', '/', $path));
        if (str_ends_with($normalized, '.ifc.zip')) {
            return 'ifc.zip';
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $ext !== '' ? $ext : 'без расширения';
    }

    private function relativeLabel(string $root, string $path): string
    {
        $rel = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
        return $rel !== '' ? $rel : $root;
    }
}
