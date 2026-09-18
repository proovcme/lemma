<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class PublicLinkService
{
    private const KIND_PROJECT = 'project';
    private const KIND_TASK = 'task';
    private const KIND_MODEL = 'model';

    /**
     * @return array<string,mixed>
     */
    public static function ensureProjectLink(int $projectId, string $label, ?int $createdBy = null): array
    {
        return self::ensure([
            'kind' => self::KIND_PROJECT,
            'project_id' => $projectId,
            'task_id' => null,
            'model_link_id' => null,
            'model_path' => null,
            'label' => $label,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public static function ensureTaskLink(int $taskId, string $label, ?int $createdBy = null): array
    {
        return self::ensure([
            'kind' => self::KIND_TASK,
            'project_id' => null,
            'task_id' => $taskId,
            'model_link_id' => null,
            'model_path' => null,
            'label' => $label,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public static function ensureModelLink(int $projectId, int $modelLinkId, string $label, ?int $createdBy = null): array
    {
        return self::ensure([
            'kind' => self::KIND_MODEL,
            'project_id' => $projectId,
            'task_id' => null,
            'model_link_id' => $modelLinkId,
            'model_path' => null,
            'label' => $label,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public static function ensureFolderModelLink(int $projectId, string $modelPath, string $label, ?int $createdBy = null): array
    {
        return self::ensure([
            'kind' => self::KIND_MODEL,
            'project_id' => $projectId,
            'task_id' => null,
            'model_link_id' => null,
            'model_path' => $modelPath,
            'label' => $label,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(string $kind, string $token): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM public_links WHERE kind = ? AND token = ? LIMIT 1');
        $stmt->execute([$kind, $token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function markAccess(int $id): void
    {
        Database::pdo()->prepare(
            'UPDATE public_links SET access_count = access_count + 1, last_accessed_at = ? WHERE id = ?'
        )->execute([date('Y-m-d H:i:s'), $id]);
    }

    /**
     * @param array<string,mixed> $link
     */
    public static function publicUrl(array $link): string
    {
        $token = trim((string) ($link['token'] ?? ''));
        if ($token === '') {
            return '';
        }

        $prefix = match ((string) ($link['kind'] ?? '')) {
            self::KIND_PROJECT => '/p/',
            self::KIND_TASK => '/t/',
            self::KIND_MODEL => '/m/',
            default => '/s/',
        };

        return app_url($prefix . $token);
    }

    /**
     * @param array{kind:string,project_id:?int,task_id:?int,model_link_id:?int,model_path:?string,label:string,created_by:?int} $payload
     * @return array<string,mixed>
     */
    private static function ensure(array $payload): array
    {
        $existing = self::findExisting($payload);
        if ($existing !== null) {
            return $existing;
        }

        $label = trim($payload['label']) !== '' ? trim($payload['label']) : $payload['kind'];
        $base = self::slug($label);
        $pdo = Database::pdo();

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $token = $base . '-' . self::suffix();
            try {
                $stmt = $pdo->prepare('
                    INSERT INTO public_links
                        (kind, token, project_id, task_id, model_link_id, model_path, label, created_by, created_at, updated_at)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $now = date('Y-m-d H:i:s');
                $stmt->execute([
                    $payload['kind'],
                    $token,
                    $payload['project_id'],
                    $payload['task_id'],
                    $payload['model_link_id'],
                    $payload['model_path'],
                    $label,
                    $payload['created_by'],
                    $now,
                    $now,
                ]);

                return self::findById((int) $pdo->lastInsertId()) ?? [
                    'id' => (int) $pdo->lastInsertId(),
                    'kind' => $payload['kind'],
                    'token' => $token,
                    'label' => $label,
                ];
            } catch (\PDOException $e) {
                if (!self::isDuplicate($e)) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Не удалось создать публичную ссылку.');
    }

    /**
     * @param array{kind:string,project_id:?int,task_id:?int,model_link_id:?int,model_path:?string,label:string,created_by:?int} $payload
     * @return array<string,mixed>|null
     */
    private static function findExisting(array $payload): ?array
    {
        $sql = 'SELECT * FROM public_links WHERE kind = ?';
        $params = [$payload['kind']];
        foreach (['project_id', 'task_id', 'model_link_id', 'model_path'] as $column) {
            if ($payload[$column] === null || $payload[$column] === '') {
                $sql .= " AND {$column} IS NULL";
            } else {
                $sql .= " AND {$column} = ?";
                $params[] = $payload[$column];
            }
        }
        $sql .= ' LIMIT 1';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM public_links WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function isDuplicate(\PDOException $e): bool
    {
        $info = $e->errorInfo;
        return (string) ($info[0] ?? '') === '23000' || (string) ($info[1] ?? '') === '1062';
    }

    private static function suffix(): string
    {
        return strtolower(bin2hex(random_bytes(2)));
    }

    private static function slug(string $value): string
    {
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
            'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];
        $value = mb_strtolower($value, 'UTF-8');
        $value = strtr($value, $map);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        $value = $value === '' ? 'link' : $value;

        return substr($value, 0, 72);
    }
}
