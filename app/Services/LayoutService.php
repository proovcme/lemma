<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Throwable;

final class LayoutService
{
    private static ?array $cachedData = null;

    /**
     * Возвращает данные для отрисовки макета сайдбара и счетчиков.
     * Реализует кэширование внутри запроса и отказоустойчивость при сбоях БД.
     *
     * @return array
     */
    public static function getLayoutData(): array
    {
        if (self::$cachedData !== null) {
            return self::$cachedData;
        }

        $default = [
            'notificationCount' => 0,
            'reviewCount' => 0,
            'performanceReviewAvailable' => false,
            'managerReviewCount' => 0,
            'managerReviewReadyCount' => 0,
            'sidebarProjects' => [],
        ];

        $user = current_user();
        if (!$user) {
            self::$cachedData = $default;
            return $default;
        }

        try {
            $pdo = Database::pdo();

            // 1. Непрочитанные уведомления
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
            $stmt->execute([$user['id']]);
            $notificationCount = (int) $stmt->fetchColumn();

            // Счётчик и очередь используют один источник истины: закрытые задачи сюда не попадают.
            $reviewCount = (new TaskActionQueueService($pdo))->count($user);

            $performanceReviewAvailable = PermissionService::canManagePerformanceReviews($user);
            if (!$performanceReviewAvailable) {
                $stmt = $pdo->prepare('
                    SELECT 1
                    FROM performance_reviews r
                    JOIN performance_review_cycles c ON c.id = r.cycle_id
                    WHERE c.status = "active" AND (r.user_id = ? OR r.manager_id = ?)
                    LIMIT 1
                ');
                $stmt->execute([(int) $user['id'], (int) $user['id']]);
                $performanceReviewAvailable = (bool) $stmt->fetchColumn();
            }

            $stmt = $pdo->prepare('
                SELECT COUNT(*) AS total,
                       SUM(CASE WHEN r.self_matrix_submitted_at IS NOT NULL AND r.manager_matrix_submitted_at IS NULL THEN 1 ELSE 0 END) AS ready
                FROM performance_reviews r
                JOIN performance_review_cycles c ON c.id = r.cycle_id
                WHERE c.status = "active" AND r.status <> "draft" AND r.manager_id = ?
            ');
            $stmt->execute([(int) $user['id']]);
            $managerReviewSummary = $stmt->fetch() ?: [];
            $managerReviewCount = (int) ($managerReviewSummary['total'] ?? 0);
            $managerReviewReadyCount = (int) ($managerReviewSummary['ready'] ?? 0);

            // 3. Активные проекты для бокового меню
            $sidebarProjects = [];
            if (PermissionService::canSeeAllProjects($user)) {
                $sidebarProjects = $pdo->query('
                    SELECT p.id, p.code, p.title, COUNT(t.id) AS open_tasks
                    FROM projects p
                    LEFT JOIN tasks t ON t.project_id = p.id AND t.status != "done"
                    WHERE p.status = "active"
                      AND COALESCE(p.kind, "project") = "project"
                    GROUP BY p.id
                    ORDER BY p.code
                    LIMIT 12
                ')->fetchAll();
            } else {
                [$scopeSql, $scopeParams] = PermissionService::projectScopeWhere($user, 'p', 'project_scope_task');
                $stmt = $pdo->prepare('
                    SELECT DISTINCT p.id, p.code, p.title,
                           (SELECT COUNT(*) FROM tasks ot WHERE ot.project_id = p.id AND ot.status != "done") AS open_tasks
                    FROM projects p
                    WHERE p.status = "active" AND ' . $scopeSql . '
                      AND COALESCE(p.kind, "project") = "project"
                    ORDER BY p.code
                    LIMIT 12
                ');
                $stmt->execute($scopeParams);
                $sidebarProjects = $stmt->fetchAll();
            }

            self::$cachedData = [
                'notificationCount' => $notificationCount,
                'reviewCount' => $reviewCount,
                'performanceReviewAvailable' => $performanceReviewAvailable,
                'managerReviewCount' => $managerReviewCount,
                'managerReviewReadyCount' => $managerReviewReadyCount,
                'sidebarProjects' => $sidebarProjects,
            ];
        } catch (Throwable $e) {
            // Логируем ошибку, но не роняем сайт в тишине
            error_log('LayoutService error: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString());
            self::$cachedData = $default;
        }

        return self::$cachedData;
    }
}
