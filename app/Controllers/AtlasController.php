<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ModelFolderService;

final class AtlasController extends BaseController
{
    public function viewer(): void
    {
        if (current_user() === null) {
            $target = (string) ($_SERVER['REQUEST_URI'] ?? '/atlas/');
            if (!str_starts_with($target, '/atlas')) {
                $target = '/atlas/';
            }
            redirect('/login?next=' . rawurlencode($target));
        }
        require_auth();

        $index = rtrim((string) config('app.atlas_public_path', BASE_PATH . '/public/locia-atlas'), '/\\') . '/index.html';
        if (!is_file($index) || !is_readable($index)) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Атлас временно недоступен';
            return;
        }

        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'wasm-unsafe-eval'; style-src 'self'; img-src 'self' data: blob:; connect-src 'self' blob:; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        readfile($index);
    }

    public function defaultModel(): void
    {
        require_auth();
        $folder = $this->defaultFolder();
        $service = new ModelFolderService();
        $models = [];

        foreach ($service->scan($folder, 50) as $model) {
            $source = $this->sourceFor((string) $model['rel'], (string) $model['kind'], (string) $model['name']);
            if ($source !== null) {
                $models[] = $source;
            }
        }

        if ($models === []) {
            json_response([
                'found' => false,
                'message' => 'В папке моделей по умолчанию нет IFC, IFCZIP или FRAG: ' . $folder,
                'folder' => $folder,
                'models' => [],
            ]);
        }

        json_response([
            'found' => true,
            'name' => count($models) === 1 ? (string) $models[0]['label'] : 'Модели по умолчанию',
            'kind' => !empty($models[0]['url']) ? 'ifc' : 'frag',
            'url' => (string) ($models[0]['url'] ?: ($models[0]['fragUrl'] ?? '')),
            'folder' => $folder,
            'models' => $models,
        ]);
    }

    public function defaultModelFile(): void
    {
        require_auth();
        $service = new ModelFolderService();
        $target = $service->resolve($this->defaultFolder(), (string) ($_GET['path'] ?? ''));
        if ($target === null) {
            http_response_code(404);
            echo 'Файл модели по умолчанию не найден';
            return;
        }

        $mime = $service->mimeFor($target);
        if ($mime === null) {
            http_response_code(415);
            echo 'Неподдерживаемый тип файла модели';
            return;
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($target));
        header('Content-Disposition: inline; filename="' . rawurlencode(basename($target)) . '"');
        header('Cache-Control: private, max-age=300');
        readfile($target);
    }

    public function defaultModelFragmentsStatus(): void
    {
        require_auth();
        [$cachePath, $target] = $this->defaultFragmentTarget();
        json_response([
            'ready' => $cachePath !== null && is_file($cachePath),
            'source_mtime' => $target !== null ? (int) @filemtime($target) : 0,
            'source_size' => $target !== null ? (int) @filesize($target) : 0,
        ]);
    }

    public function defaultModelFragmentsGet(): void
    {
        require_auth();
        [$cachePath] = $this->defaultFragmentTarget();
        if ($cachePath === null || !is_file($cachePath)) {
            http_response_code(404);
            echo 'нет кеша фрагментов';
            return;
        }

        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . (string) filesize($cachePath));
        header('Cache-Control: private, max-age=3600');
        readfile($cachePath);
    }

    public function defaultModelFragmentsPost(): void
    {
        require_auth();
        [$cachePath, $target, $rel] = $this->defaultFragmentTarget();
        if ($cachePath === null || $target === null || $rel === '') {
            http_response_code(204);
            return;
        }

        $body = file_get_contents('php://input');
        $len = $body === false ? 0 : strlen($body);
        if ($len < 8 || $len > 300 * 1024 * 1024) {
            http_response_code(400);
            echo 'некорректный размер фрагментов';
            return;
        }

        $dir = dirname($cachePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $cachePath . '.tmp' . getmypid();
        if (@file_put_contents($tmp, $body) === false) {
            http_response_code(500);
            echo 'не удалось сохранить кеш фрагментов';
            return;
        }
        @rename($tmp, $cachePath);
        (new ModelFolderService())->cleanOldFragmentCache('default-models', $rel, $cachePath);
        http_response_code(204);
    }

    private function sourceFor(string $rel, string $kind, string $name): ?array
    {
        if (!in_array($kind, ['frag', 'ifc', 'ifczip'], true) || $rel === '') {
            return null;
        }

        $file = '/locia-atlas/default-folder/file?path=' . rawurlencode($rel);
        $label = $name !== '' ? $name : basename($rel);
        $source = [
            'id' => 'default-model-' . substr(sha1($rel), 0, 12),
            'label' => $label,
            'url' => $kind === 'frag' ? '' : $file,
        ];
        if ($kind === 'frag') {
            $source['fragUrl'] = $file;
        } else {
            $fragment = '/locia-atlas/default-folder/fragments?path=' . rawurlencode($rel);
            $source['fragUrl'] = $fragment;
            $source['fragCacheUrl'] = $fragment;
        }

        return $source;
    }

    private function defaultFragmentTarget(): array
    {
        $rel = ltrim(str_replace('\\', '/', (string) ($_GET['path'] ?? '')), '/');
        $service = new ModelFolderService();
        $target = $service->resolve($this->defaultFolder(), $rel);
        if ($target === null || strtolower(pathinfo($target, PATHINFO_EXTENSION)) === 'frag') {
            return [null, $target, $rel];
        }

        return [$service->fragmentCachePath('default-models', $rel, $target), $target, $rel];
    }

    private function defaultFolder(): string
    {
        return (string) config('app.default_model_folder', BASE_PATH . '/models');
    }
}
