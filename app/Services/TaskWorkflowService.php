<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class TaskWorkflowService
{
    public const TASK_TYPE_REVIEW = 'review';
    public const TASK_TYPE_DELEGATION = 'delegation';
    public const STATUS_CORRECTION = 'correction';

    public static function markOverdue(): int
    {
        $pdo = Database::pdo();
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("
            INSERT INTO task_logs (task_id, user_id, field, old_val, new_val)
            SELECT t.id, NULL, 'status', t.status, 'overdue'
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.date_end < ?
              AND t.status IN ('new','in_progress','blocked')
              AND p.status = 'active'
        ");
        $stmt->execute([$today]);
        $stmt = $pdo->prepare("
            UPDATE tasks
            SET status = 'overdue'
            WHERE date_end < ?
              AND status IN ('new','in_progress','blocked')
              AND EXISTS (
                  SELECT 1 FROM projects p
                  WHERE p.id = tasks.project_id AND p.status = 'active'
              )
        ");
        $stmt->execute([$today]);
        $marked = $stmt->rowCount();

        // Обратный проход: если срок задачи перенесён вперёд (date_end >= сегодня
        // или снят), задача не должна оставаться «overdue». Восстанавливаем
        // доовердушный статус из последней записи task_logs (new_val='overdue' →
        // old_val = исходный статус: new/in_progress/blocked). Без этого перенос
        // срока не снимал «просрочено». Портируемо на SQLite и MySQL.
        $restoreLog = $pdo->prepare("
            INSERT INTO task_logs (task_id, user_id, field, old_val, new_val)
            SELECT t.id, NULL, 'status', 'overdue', (
                SELECT tl.old_val FROM task_logs tl
                WHERE tl.task_id = t.id AND tl.field = 'status' AND tl.new_val = 'overdue'
                ORDER BY tl.id DESC LIMIT 1
            )
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.status = 'overdue'
              AND (t.date_end IS NULL OR t.date_end >= ?)
              AND p.status = 'active'
              AND EXISTS (
                  SELECT 1 FROM task_logs tl2
                  WHERE tl2.task_id = t.id AND tl2.field = 'status' AND tl2.new_val = 'overdue'
              )
        ");
        $restoreLog->execute([$today]);

        $restore = $pdo->prepare("
            UPDATE tasks
            SET status = COALESCE((
                SELECT tl.old_val FROM task_logs tl
                WHERE tl.task_id = tasks.id AND tl.field = 'status' AND tl.new_val = 'overdue'
                ORDER BY tl.id DESC LIMIT 1
            ), 'in_progress')
            WHERE status = 'overdue'
              AND (date_end IS NULL OR date_end >= ?)
              AND EXISTS (
                  SELECT 1 FROM projects p
                  WHERE p.id = tasks.project_id AND p.status = 'active'
              )
              AND EXISTS (
                  SELECT 1 FROM task_logs tl3
                  WHERE tl3.task_id = tasks.id AND tl3.field = 'status' AND tl3.new_val = 'overdue'
              )
        ");
        $restore->execute([$today]);

        return $marked;
    }

    public static function log(int $taskId, ?int $userId, string $field, mixed $oldValue, mixed $newValue): void
    {
        if ((string) $oldValue === (string) $newValue) {
            return;
        }

        $stmt = Database::pdo()->prepare('
            INSERT INTO task_logs (task_id, user_id, field, old_val, new_val)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$taskId, $userId, $field, (string) $oldValue, (string) $newValue]);

        self::resolveActionNotifications($taskId, $field, (string) $oldValue, (string) $newValue);
        if ($field === 'status') {
            (new ProjectTaskExchangeService(Database::pdo()))->syncTask($taskId);
        }

        ActivityLogService::recordTask(
            $taskId,
            $userId,
            'task.changed',
            'Изменено поле задачи: ' . $field,
            (string) $oldValue . ' -> ' . (string) $newValue,
            [
                'field' => $field,
                'old' => (string) $oldValue,
                'new' => (string) $newValue,
            ]
        );
    }

    public static function notify(int $userId, ?int $taskId, string $type, string $body): void
    {
        $pdo = Database::pdo();
        if ($taskId !== null) {
            $pdo->prepare('UPDATE notifications SET read_at = CURRENT_TIMESTAMP WHERE user_id = ? AND task_id = ? AND type = ? AND read_at IS NULL')
                ->execute([$userId, $taskId, $type]);
        }
        $stmt = $pdo->prepare('
            INSERT INTO notifications (user_id, task_id, type, body)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$userId, $taskId, $type, $body]);
        $notificationId = (int) $pdo->lastInsertId();
        try {
            PushNotificationService::enqueue(
                $userId,
                $type,
                str_starts_with($type, 'deadline') ? 'Срок задачи' : 'Лемма',
                $body,
                $taskId ? '/tasks/' . $taskId : '/notifications',
                $taskId,
                'push:notification:' . $notificationId
            );
        } catch (\Throwable $e) {
            error_log('Push notification queue failed: ' . $e->getMessage());
        }

        $substitute = VacationService::activeSubstitute($userId, $pdo);
        $substituteId = (int) ($substitute['substitute_user_id'] ?? 0);
        if ($substituteId <= 0 || $substituteId === $userId) {
            return;
        }

        $substituteType = 'vacation_substitute_' . $type;
        $substituteBody = 'Замещение ' . (string) ($substitute['absent_name'] ?? 'сотрудника') . ': ' . $body;
        $stmt->execute([$substituteId, $taskId, $substituteType, $substituteBody]);
        $substituteNotificationId = (int) $pdo->lastInsertId();
        try {
            PushNotificationService::enqueue(
                $substituteId,
                $substituteType,
                'Замещение на время отпуска',
                $substituteBody,
                $taskId ? '/tasks/' . $taskId : '/notifications',
                $taskId,
                'push:notification:' . $substituteNotificationId
            );
        } catch (\Throwable $e) {
            error_log('Vacation substitute push queue failed: ' . $e->getMessage());
        }
    }

    private static function resolveActionNotifications(int $taskId, string $field, string $oldValue, string $newValue): void
    {
        $types = [];
        if ($field === 'status' && in_array($newValue, ['new', 'in_progress', 'correction', 'blocked', 'overdue', 'done'], true)) {
            $types[] = 'review_task_created';
        }
        if ($field === 'status' && $newValue === 'done') {
            $types = [...$types, 'approval_review_lead', 'approval_review_gip', 'close_gip_requested', 'deadline_shift_requested'];
        }
        if ($field === 'approval_stage' && $oldValue === 'review_lead' && $newValue !== 'review_lead') {
            $types[] = 'approval_review_lead';
        }
        if ($field === 'approval_stage' && $oldValue === 'review_gip' && $newValue !== 'review_gip') {
            $types[] = 'approval_review_gip';
        }
        $types = array_values(array_unique($types));
        if ($types === []) {
            return;
        }
        $stmt = Database::pdo()->prepare('UPDATE notifications SET read_at = CURRENT_TIMESTAMP
            WHERE task_id = ? AND read_at IS NULL AND type IN (' . implode(',', array_fill(0, count($types), '?')) . ')');
        $stmt->execute([$taskId, ...$types]);
    }

    public static function sendDeadlineReminders(int $daysAhead = 3): array
    {
        $pdo = Database::pdo();
        $today = date('Y-m-d');
        $until = date('Y-m-d', strtotime('+' . max(0, $daysAhead) . ' days'));
        $stmt = $pdo->prepare('
            SELECT t.id,
                   t.title,
                   t.date_end,
                   t.status,
                   t.assignee_id,
                   t.reviewer_id,
                   p.code AS project_code
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.date_end IS NOT NULL
              AND t.date_end <= ?
              AND t.status != "done"
              AND t.closed_at IS NULL
            ORDER BY t.date_end, t.id
            LIMIT 1000
        ');
        $stmt->execute([$until]);

        $participants = $pdo->prepare('
            SELECT user_id
            FROM task_participants
            WHERE task_id = ? AND role = "coauthor"
        ');
        $insertSql = Database::driver() === 'sqlite'
            ? 'INSERT OR IGNORE INTO deadline_reminders (task_id, user_id, reminder_date, kind) VALUES (?, ?, ?, ?)'
            : 'INSERT IGNORE INTO deadline_reminders (task_id, user_id, reminder_date, kind) VALUES (?, ?, ?, ?)';
        $remember = $pdo->prepare($insertSql);
        $notified = 0;
        $tasks = 0;

        foreach ($stmt->fetchAll() as $task) {
            $taskId = (int) $task['id'];
            $deadline = substr((string) $task['date_end'], 0, 10);
            $days = self::daysUntil($deadline, $today);
            $kind = $days < 0 ? 'deadline_overdue' : ($days === 0 ? 'deadline_today' : 'deadline_soon');
            $body = self::deadlineReminderBody($task, $days);
            $recipients = [];
            foreach (['assignee_id', 'reviewer_id'] as $field) {
                $recipientId = (int) ($task[$field] ?? 0);
                if ($recipientId > 0) {
                    $recipients[$recipientId] = $recipientId;
                }
            }
            $participants->execute([$taskId]);
            foreach ($participants->fetchAll() as $participant) {
                $recipientId = (int) ($participant['user_id'] ?? 0);
                if ($recipientId > 0) {
                    $recipients[$recipientId] = $recipientId;
                }
            }
            if ($recipients === []) {
                continue;
            }

            $tasks++;
            foreach ($recipients as $recipientId) {
                $remember->execute([$taskId, $recipientId, $today, $kind]);
                if ($remember->rowCount() < 1) {
                    continue;
                }
                self::notify($recipientId, $taskId, $kind, $body);
                $notified++;
            }
        }

        return ['tasks' => $tasks, 'notifications' => $notified];
    }

    private static function daysUntil(string $date, string $today): int
    {
        $dateTs = strtotime($date);
        $todayTs = strtotime($today);
        if ($dateTs === false || $todayTs === false) {
            return 0;
        }

        return (int) floor(($dateTs - $todayTs) / 86400);
    }

    private static function deadlineReminderBody(array $task, int $days): string
    {
        $title = mb_strimwidth((string) ($task['title'] ?? ''), 0, 120, '...');
        $project = (string) ($task['project_code'] ?? '');
        $deadline = (string) ($task['date_end'] ?? '');
        if ($days < 0) {
            $prefix = 'Просрочен срок задачи';
            $tail = abs($days) . ' дн. просрочки';
        } elseif ($days === 0) {
            $prefix = 'Сегодня срок задачи';
            $tail = 'срок сегодня';
        } else {
            $prefix = 'Скоро срок задачи';
            $tail = 'осталось ' . $days . ' дн.';
        }

        return $prefix . ' #' . (int) $task['id'] . ' (' . $project . '): ' . $title . '. Срок: ' . $deadline . ', ' . $tail . '.';
    }

    public static function recomputeParentProgress(?int $parentId): void
    {
        if (!$parentId) {
            return;
        }

        $pdo = Database::pdo();
        // Авто-создаваемые review-задачи (task_type='review') — это служебные
        // задачи цикла сдачи-проверки, а не реальные подзадачи. Их прогресс
        // (100 при закрытии) завышал AVG родителя — исключаем из расчёта.
        $stmt = $pdo->prepare("
            SELECT tasks.id, tasks.parent_id, tasks.task_type, tasks.progress,
                   COALESCE(ts.depends_on, '') AS depends_on
            FROM tasks
            LEFT JOIN task_smart ts ON ts.task_id = tasks.id
            WHERE tasks.parent_id = ?
            ORDER BY tasks.id ASC
        ");
        $stmt->execute([$parentId]);
        $progressValues = [];
        foreach ($stmt->fetchAll() as $row) {
            if ((string) ($row['task_type'] ?? '') === 'review'
                || TaskWorkflowIntegrityService::isAmbiguousLegacyRelation($row)
            ) {
                continue;
            }
            $progressValues[] = (int) ($row['progress'] ?? 0);
        }
        if ($progressValues === []) {
            return;
        }

        $average = (int) round(array_sum($progressValues) / count($progressValues));
        $pdo->prepare('UPDATE tasks SET progress = ? WHERE id = ?')->execute([$average, $parentId]);

        $next = $pdo->prepare('SELECT parent_id FROM tasks WHERE id = ?');
        $next->execute([$parentId]);
        $nextParent = $next->fetchColumn();
        self::recomputeParentProgress($nextParent ? (int) $nextParent : null);
    }

    public static function defaultReviewerId(int $assigneeId): ?int
    {
        $pdo = Database::pdo();
        $hasManager = self::usersTableHasColumn($pdo, 'manager_id');
        $stmt = $pdo->prepare('SELECT role, department' . ($hasManager ? ', manager_id' : '') . ' FROM users WHERE id = ?');
        $stmt->execute([$assigneeId]);
        $assignee = $stmt->fetch();
        if (!$assignee) {
            return null;
        }

        if ($hasManager) {
            $managerId = (int) ($assignee['manager_id'] ?? 0);
            $seen = [$assigneeId => true];
            while ($managerId > 0 && empty($seen[$managerId])) {
                $seen[$managerId] = true;
                $stmt = $pdo->prepare('SELECT id, role, manager_id, is_active FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([$managerId]);
                $manager = $stmt->fetch();
                if (!$manager) {
                    break;
                }
                if ((int) ($manager['is_active'] ?? 1) === 1 && PermissionService::canAcceptWork(['role' => (string) ($manager['role'] ?? '')])) {
                    return (int) $manager['id'];
                }
                $managerId = (int) ($manager['manager_id'] ?? 0);
            }
        }

        $assigneeRole = RoleService::normalize($assignee['role'] ?? null);
        $ladder = [
            RoleService::ENGINEER => [RoleService::CHIEF_SPECIALIST, RoleService::GROUP_LEAD, RoleService::DEPUTY_DEPARTMENT_HEAD, RoleService::DEPARTMENT_HEAD],
            RoleService::CHIEF_SPECIALIST => [RoleService::GROUP_LEAD, RoleService::DEPUTY_DEPARTMENT_HEAD, RoleService::DEPARTMENT_HEAD, RoleService::GIP],
            RoleService::GROUP_LEAD => [RoleService::DEPUTY_DEPARTMENT_HEAD, RoleService::DEPARTMENT_HEAD, RoleService::GIP],
            RoleService::DEPUTY_DEPARTMENT_HEAD => [RoleService::GIP, RoleService::DEPUTY_DIRECTOR],
            RoleService::DEPARTMENT_HEAD => [RoleService::GIP, RoleService::DEPUTY_DIRECTOR],
            RoleService::GIP => [RoleService::DEPUTY_DIRECTOR, RoleService::DIRECTOR],
            RoleService::PROJECT_MANAGER => [RoleService::DEPUTY_DIRECTOR, RoleService::DIRECTOR],
            RoleService::DEPUTY_DIRECTOR => [RoleService::DIRECTOR],
            RoleService::ADJACENT_DIRECTOR => [RoleService::DIRECTOR],
            RoleService::DIRECTOR => [RoleService::DIRECTOR],
            RoleService::ADMIN => [RoleService::DIRECTOR, RoleService::ADMIN],
        ][$assigneeRole] ?? [RoleService::DEPARTMENT_HEAD, RoleService::GIP];

        foreach ($ladder as $role) {
            $query = 'SELECT id FROM users WHERE role = :role AND is_active = 1';
            $params = ['role' => $role];
            if (!empty($assignee['department']) && in_array($role, [RoleService::CHIEF_SPECIALIST, RoleService::GROUP_LEAD, RoleService::DEPUTY_DEPARTMENT_HEAD, RoleService::DEPARTMENT_HEAD], true)) {
                $query .= ' AND department = :department';
                $params['department'] = $assignee['department'];
            }
            if ($hasManager) {
                $query .= ' AND (manager_id IS NULL OR manager_id != :assignee_manager_id)';
                $params['assignee_manager_id'] = (int) ($assignee['manager_id'] ?? 0);
            }
            $query .= ' ORDER BY id LIMIT 1';
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $id = $stmt->fetchColumn();
            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }

    private static function usersTableHasColumn(\PDO $pdo, string $column): bool
    {
        try {
            if ((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                foreach ($pdo->query('PRAGMA table_info(users)')->fetchAll() as $row) {
                    if ((string) ($row['name'] ?? '') === $column) {
                        return true;
                    }
                }
                return false;
            }

            $stmt = $pdo->prepare('SHOW COLUMNS FROM users LIKE ?');
            $stmt->execute([$column]);
            return (bool) $stmt->fetch();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function extractMentions(string $body): array
    {
        if (!preg_match_all('/@([0-9]+)/u', $body, $matches)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $matches[1])));
    }
}
