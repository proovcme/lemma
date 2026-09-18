<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class ActivityLogService
{
    public const SCOPE_LOCIA = 'locia';
    public const SCOPE_PROJECT = 'project';
    public const SCOPE_TASK = 'task';

    public static function recordTask(int $taskId, ?int $userId, string $action, string $title, ?string $body = null, array $meta = []): void
    {
        $task = self::taskContext($taskId);
        self::record([
            'scope' => self::SCOPE_TASK,
            'project_id' => $task['project_id'] ?? null,
            'task_id' => $taskId,
            'user_id' => $userId,
            'action' => $action,
            'title' => $title,
            'body' => $body,
            'meta' => $meta + [
                'task_title' => $task['title'] ?? '',
                'project_code' => $task['project_code'] ?? '',
            ],
        ]);
    }

    public static function recordProject(int $projectId, ?int $userId, string $action, string $title, ?string $body = null, array $meta = []): void
    {
        $project = self::projectContext($projectId);
        self::record([
            'scope' => self::SCOPE_PROJECT,
            'project_id' => $projectId,
            'task_id' => null,
            'user_id' => $userId,
            'action' => $action,
            'title' => $title,
            'body' => $body,
            'meta' => $meta + [
                'project_code' => $project['code'] ?? '',
                'project_title' => $project['title'] ?? '',
            ],
        ]);
    }

    public static function recordLocia(?int $userId, string $action, string $title, ?string $body = null, array $meta = []): void
    {
        self::record([
            'scope' => self::SCOPE_LOCIA,
            'project_id' => null,
            'task_id' => null,
            'user_id' => $userId,
            'action' => $action,
            'title' => $title,
            'body' => $body,
            'meta' => $meta,
        ]);
    }

    public static function forProject(int $projectId, int $limit = 80): array
    {
        $stmt = Database::pdo()->prepare(self::baseSelect() . '
            WHERE a.project_id = :project_id
               OR t.project_id = :project_task_project_id
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT ' . max(1, $limit)
        );
        $stmt->execute([
            'project_id' => $projectId,
            'project_task_project_id' => $projectId,
        ]);

        return $stmt->fetchAll();
    }

    public static function global(array $user, array $filters = [], int $limit = 200): array
    {
        [$where, $params] = self::globalWhere($user, $filters);
        $stmt = Database::pdo()->prepare(self::baseSelect() . '
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function actions(): array
    {
        $stmt = Database::pdo()->query('SELECT DISTINCT action FROM activity_logs ORDER BY action');

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function record(array $payload): void
    {
        try {
            $meta = $payload['meta'] ?? [];
            $stmt = Database::pdo()->prepare('
                INSERT INTO activity_logs (scope, project_id, task_id, user_id, action, title, body, meta_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $payload['scope'] ?? self::SCOPE_LOCIA,
                $payload['project_id'] ?? null,
                $payload['task_id'] ?? null,
                $payload['user_id'] ?? null,
                mb_substr((string) ($payload['action'] ?? 'event'), 0, 80),
                mb_substr((string) ($payload['title'] ?? 'Событие'), 0, 255),
                $payload['body'] !== null ? (string) $payload['body'] : null,
                $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]);
        } catch (Throwable $e) {
            error_log('ActivityLogService record failed: ' . $e->getMessage());
        }
    }

    private static function taskContext(int $taskId): array
    {
        try {
            $stmt = Database::pdo()->prepare('
                SELECT t.project_id, t.title, p.code AS project_code
                FROM tasks t
                LEFT JOIN projects p ON p.id = t.project_id
                WHERE t.id = ?
                LIMIT 1
            ');
            $stmt->execute([$taskId]);

            return $stmt->fetch() ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function projectContext(int $projectId): array
    {
        try {
            $stmt = Database::pdo()->prepare('SELECT code, title FROM projects WHERE id = ? LIMIT 1');
            $stmt->execute([$projectId]);

            return $stmt->fetch() ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function baseSelect(): string
    {
        return '
            SELECT a.*,
                   u.name AS user_name,
                   COALESCE(p_direct.id, p_task.id) AS project_id_resolved,
                   COALESCE(p_direct.code, p_task.code) AS project_code,
                   COALESCE(p_direct.title, p_task.title) AS project_title,
                   t.title AS task_title
            FROM activity_logs a
            LEFT JOIN users u ON u.id = a.user_id
            LEFT JOIN tasks t ON t.id = a.task_id
            LEFT JOIN projects p_task ON p_task.id = t.project_id
            LEFT JOIN projects p_direct ON p_direct.id = a.project_id
        ';
    }

    private static function globalWhere(array $user, array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        if (!PermissionService::canSeeAllProjects($user)) {
            [$scopeSql, $scopeParams] = PermissionService::taskScopeWhere($user, 't');
            [$projectScopeSql, $projectScopeParams] = PermissionService::projectScopeWhere($user, 'p_direct', 'activity_project_scope_task');
            $where[] = '(
                a.user_id = :activity_user_id
                OR (a.task_id IS NOT NULL AND ' . $scopeSql . ')
                OR (a.project_id IS NOT NULL AND ' . $projectScopeSql . ')
            )';
            $params += $scopeParams;
            $params += $projectScopeParams;
            $params['activity_user_id'] = (int) $user['id'];
        }

        if (!empty($filters['project_id'])) {
            $where[] = 'COALESCE(a.project_id, t.project_id) = :activity_project_id';
            $params['activity_project_id'] = (int) $filters['project_id'];
        }
        if (!empty($filters['task_id'])) {
            $where[] = 'a.task_id = :activity_task_id';
            $params['activity_task_id'] = (int) $filters['task_id'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'a.action = :activity_action';
            $params['activity_action'] = (string) $filters['action'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'a.created_at >= :activity_date_from';
            $params['activity_date_from'] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'a.created_at <= :activity_date_to';
            $params['activity_date_to'] = (string) $filters['date_to'] . ' 23:59:59';
        }
        if (trim((string) ($filters['q'] ?? '')) !== '') {
            $where[] = '(a.title LIKE :activity_q OR a.body LIKE :activity_q_body OR t.title LIKE :activity_q_task)';
            $query = '%' . trim((string) $filters['q']) . '%';
            $params['activity_q'] = $query;
            $params['activity_q_body'] = $query;
            $params['activity_q_task'] = $query;
        }

        return [$where, $params];
    }
}
