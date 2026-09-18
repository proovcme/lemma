<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class TagService
{
    /**
     * @return list<string>
     */
    public static function parseNames(mixed $raw): array
    {
        if (is_array($raw)) {
            $raw = implode(',', array_map(static fn (mixed $value): string => (string) $value, $raw));
        }

        $parts = preg_split('/[,;\r\n]+/u', (string) $raw) ?: [];
        $names = [];
        $seen = [];
        foreach ($parts as $part) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) $part) ?: '');
            $name = ltrim($name, '#');
            if ($name === '') {
                continue;
            }

            $name = mb_substr($name, 0, 40, 'UTF-8');
            $slug = self::slug($name);
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }

            $seen[$slug] = true;
            $names[] = $name;
            if (count($names) >= 20) {
                break;
            }
        }

        return $names;
    }

    public static function slug(string $name): string
    {
        $slug = mb_strtolower(trim($name), 'UTF-8');
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug) ?: '';
        return trim($slug, '-');
    }

    public static function syncTaskTags(int $taskId, int $projectId, array $tagNames, ?int $userId): void
    {
        $pdo = Database::pdo();
        $tagIds = [];
        foreach ($tagNames as $tagName) {
            $slug = self::slug((string) $tagName);
            if ($slug === '') {
                continue;
            }

            $tagIds[] = self::findOrCreateProjectTag($projectId, (string) $tagName, $slug, $userId);
        }

        $tagIds = array_values(array_unique(array_filter($tagIds)));
        if (!$tagIds) {
            $pdo->prepare('DELETE FROM task_tags WHERE task_id = ?')->execute([$taskId]);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
        $delete = $pdo->prepare("DELETE FROM task_tags WHERE task_id = ? AND tag_id NOT IN ({$placeholders})");
        $delete->execute([$taskId, ...$tagIds]);

        $exists = $pdo->prepare('SELECT COUNT(*) FROM task_tags WHERE task_id = ? AND tag_id = ?');
        $insert = $pdo->prepare('INSERT INTO task_tags (task_id, tag_id) VALUES (?, ?)');
        foreach ($tagIds as $tagId) {
            $exists->execute([$taskId, $tagId]);
            if ((int) $exists->fetchColumn() === 0) {
                $insert->execute([$taskId, $tagId]);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function tagsForTask(int $taskId): array
    {
        $stmt = Database::pdo()->prepare('
            SELECT tg.*
            FROM tags tg
            INNER JOIN task_tags tt ON tt.tag_id = tg.id
            WHERE tt.task_id = ?
            ORDER BY tg.name
        ');
        $stmt->execute([$taskId]);

        return $stmt->fetchAll();
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return list<array<string, mixed>>
     */
    public static function attachToTasks(array $tasks): array
    {
        if (!$tasks) {
            return [];
        }

        $ids = array_values(array_unique(array_map(static fn (array $task): int => (int) $task['id'], $tasks)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::pdo()->prepare("
            SELECT tt.task_id, tg.*
            FROM task_tags tt
            INNER JOIN tags tg ON tg.id = tt.tag_id
            WHERE tt.task_id IN ({$placeholders})
            ORDER BY tg.name
        ");
        $stmt->execute($ids);

        $tagsByTask = [];
        foreach ($stmt->fetchAll() as $tag) {
            $tagsByTask[(int) $tag['task_id']][] = $tag;
        }

        foreach ($tasks as &$task) {
            $task['tags'] = $tagsByTask[(int) $task['id']] ?? [];
            $task['tag_names'] = implode(', ', array_map(static fn (array $tag): string => (string) $tag['name'], $task['tags']));
        }
        unset($task);

        return $tasks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function tagsForProject(?int $projectId): array
    {
        if (!$projectId) {
            return Database::pdo()->query('SELECT * FROM tags ORDER BY name LIMIT 200')->fetchAll();
        }

        $stmt = Database::pdo()->prepare('
            SELECT *
            FROM tags
            WHERE project_id IS NULL OR project_id = ?
            ORDER BY project_id IS NULL DESC, name
            LIMIT 200
        ');
        $stmt->execute([$projectId]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function visibleTagsForUser(array $user, string $scope = 'all'): array
    {
        $params = [];
        if ($scope === 'mine') {
            $where = '(t.assignee_id = :tag_user_id OR t.author_id = :tag_user_id)';
            $params['tag_user_id'] = (int) $user['id'];
        } else {
            [$where, $params] = PermissionService::taskScopeWhere($user, 't');
        }

        $stmt = Database::pdo()->prepare('
            SELECT DISTINCT tg.*
            FROM tags tg
            INNER JOIN task_tags tt ON tt.tag_id = tg.id
            INNER JOIN tasks t ON t.id = tt.task_id
            INNER JOIN projects p ON p.id = t.project_id
            WHERE p.status = "active" AND ' . $where . '
            ORDER BY tg.name
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private static function findOrCreateProjectTag(int $projectId, string $name, string $slug, ?int $userId): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id FROM tags WHERE project_id = ? AND slug = ? LIMIT 1');
        $stmt->execute([$projectId, $slug]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            return (int) $existing;
        }

        $insert = $pdo->prepare('INSERT INTO tags (project_id, name, slug, color, created_by) VALUES (?, ?, ?, ?, ?)');
        $insert->execute([$projectId, $name, $slug, self::colorForSlug($slug), $userId]);

        return (int) $pdo->lastInsertId();
    }

    private static function colorForSlug(string $slug): string
    {
        $palette = ['#A33A3A', '#1D5F8A', '#2F7A56', '#7A5A1E', '#6F4B8B', '#5B6472', '#8A4F2B', '#2E6F73'];
        $index = (int) (hexdec(substr(hash('crc32b', $slug), 0, 8)) % count($palette));

        return $palette[$index];
    }
}
