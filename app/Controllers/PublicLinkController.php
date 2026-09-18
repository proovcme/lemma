<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\ModelFolderService;
use App\Services\PublicLinkService;

final class PublicLinkController
{
    public function project(string $token): void
    {
        $link = $this->link('project', $token);
        $projectId = (int) ($link['project_id'] ?? 0);
        if ($projectId <= 0) {
            $this->notFound();
        }

        PublicLinkService::markAccess((int) $link['id']);
        redirect('/projects/' . $projectId);
    }

    public function task(string $token): void
    {
        $link = $this->link('task', $token);
        $taskId = (int) ($link['task_id'] ?? 0);
        if ($taskId <= 0) {
            $this->notFound();
        }

        PublicLinkService::markAccess((int) $link['id']);
        redirect('/tasks/' . $taskId);
    }

    public function model(string $token): void
    {
        $link = $this->link('model', $token);
        $projectId = (int) ($link['project_id'] ?? 0);
        if ($projectId <= 0) {
            $this->notFound();
        }

        $href = '';
        $modelLinkId = (int) ($link['model_link_id'] ?? 0);
        if ($modelLinkId > 0) {
            $href = $this->manualModelHref($projectId, $modelLinkId);
        } else {
            $modelPath = trim((string) ($link['model_path'] ?? ''));
            if ($modelPath !== '') {
                $href = $this->folderModelHref($projectId, $modelPath);
            }
        }

        if ($href === '') {
            $this->notFound();
        }

        PublicLinkService::markAccess((int) $link['id']);
        redirect($href);
    }

    /**
     * @return array<string,mixed>
     */
    private function link(string $kind, string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            $this->notFound();
        }

        $link = PublicLinkService::find($kind, $token);
        if ($link === null) {
            $this->notFound();
        }

        return $link;
    }

    private function manualModelHref(int $projectId, int $modelLinkId): string
    {
        $stmt = Database::pdo()->prepare('
            SELECT id, project_id, model_url, kind
            FROM project_model_links
            WHERE id = ? AND project_id = ?
            LIMIT 1
        ');
        $stmt->execute([$modelLinkId, $projectId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return '';
        }

        $url = trim((string) ($row['model_url'] ?? ''));
        $target = preg_match('#^https?://#i', $url)
            ? $url
            : '/projects/' . $projectId . '/models/' . $modelLinkId . '/file';

        return $this->atlasHref($projectId, (string) ($row['kind'] ?? ''), $target, '/projects/' . $projectId . '/models/' . $modelLinkId . '/fragments');
    }

    private function folderModelHref(int $projectId, string $modelPath): string
    {
        $normalized = strtolower(str_replace('\\', '/', $modelPath));
        $kind = str_ends_with($normalized, '.ifc.zip')
            ? 'ifczip'
            : strtolower(pathinfo($modelPath, PATHINFO_EXTENSION));

        $encoded = rawurlencode($modelPath);
        $version = $this->folderModelVersionToken($projectId, $modelPath);
        $target = $this->appendQueryParam('/projects/' . $projectId . '/model-folder/file?path=' . $encoded, 'v', $version);
        $fragmentUrl = $this->appendQueryParam('/projects/' . $projectId . '/model-folder/fragments?path=' . $encoded, 'v', $version);

        return $this->atlasHref($projectId, $kind, $target, $fragmentUrl);
    }

    private function atlasHref(int $projectId, string $kind, string $target, string $fragmentUrl): string
    {
        $query = [
            'locia_return' => '/projects/' . $projectId,
            'project_id' => (string) $projectId,
            'base' => app_url(''),
        ];

        if ($kind === 'frag') {
            $query['frag'] = $target;
        } elseif ($kind === 'ifc' || $kind === 'ifczip') {
            $query['ifc'] = $target;
            $query['frag'] = $fragmentUrl;
        } else {
            $query['source'] = $target;
        }

        return '/locia-atlas/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function folderModelVersionToken(int $projectId, string $relativePath): string
    {
        $stmt = Database::pdo()->prepare('SELECT model_folder_url FROM projects WHERE id = ? LIMIT 1');
        $stmt->execute([$projectId]);
        $folder = trim((string) ($stmt->fetchColumn() ?: ''));
        $rel = ltrim(str_replace('\\', '/', $relativePath), '/');
        $target = $folder !== '' ? (new ModelFolderService())->resolve($folder, $rel) : null;

        $sourceSignature = 'missing';
        if (is_string($target) && $target !== '' && is_file($target)) {
            $sourceSignature = (string) @filemtime($target) . '-' . (string) @filesize($target);
        }

        $versionPath = BASE_PATH . '/storage/atlas-fragments/project-' . $projectId . '.version';
        $cacheVersion = is_file($versionPath)
            ? (string) @filemtime($versionPath) . '-' . trim((string) @file_get_contents($versionPath))
            : '0';

        return substr(sha1($rel . '|' . $sourceSignature . '|' . $cacheVersion), 0, 16);
    }

    private function appendQueryParam(string $url, string $name, string $value): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . rawurlencode($name) . '=' . rawurlencode($value);
    }

    private function notFound(): never
    {
        http_response_code(404);
        echo 'Ссылка не найдена';
        exit;
    }
}
