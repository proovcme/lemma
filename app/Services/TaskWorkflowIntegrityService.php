<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class TaskWorkflowIntegrityService
{
    private const ACTIVE_APPROVAL_STAGES = ['review_lead', 'review_gip'];
    private const ACTION_NOTIFICATION_TYPES = [
        'review_task_created',
        'approval_review_lead',
        'approval_review_gip',
        'close_gip_requested',
        'deadline_shift_requested',
    ];

    public static function canStartClose(array $task): bool
    {
        return !self::isTerminal($task)
            && !in_array((string) ($task['approval_stage'] ?? 'draft'), self::ACTIVE_APPROVAL_STAGES, true);
    }

    public static function canStartApproval(array $task): bool
    {
        return self::canContinueApproval($task)
            && in_array((string) ($task['approval_stage'] ?? 'draft'), ['draft', 'issued'], true);
    }

    public static function canContinueApproval(array $task): bool
    {
        return !self::isTerminal($task)
            && !in_array((string) ($task['status'] ?? ''), ['review', 'pending_close'], true)
            && trim((string) ($task['close_requested_at'] ?? '')) === '';
    }

    public static function deadlineShiftBlockReason(array $task, array $shift): ?string
    {
        if (self::isTerminal($task)) {
            return 'task_terminal';
        }
        if ((string) ($shift['status'] ?? '') !== 'pending') {
            return 'shift_decided';
        }
        if (self::dateOrNull($task['date_end'] ?? null) !== self::dateOrNull($shift['date_old'] ?? null)) {
            return 'stale_deadline';
        }

        return null;
    }

    public static function isAmbiguousLegacyRelation(array $task): bool
    {
        $parentId = (int) ($task['parent_id'] ?? 0);
        if ($parentId <= 0 || in_array((string) ($task['task_type'] ?? 'work'), ['review', 'delegation'], true)) {
            return false;
        }

        return trim((string) ($task['depends_on'] ?? '')) === (string) $parentId;
    }

    public static function finalizeTask(PDO $pdo, int $taskId, int $userId): bool
    {
        $stmt = $pdo->prepare("
            UPDATE tasks
            SET status = 'done',
                progress = 100,
                approval_stage = CASE
                    WHEN approval_stage IN ('review_lead', 'review_gip') THEN 'approved'
                    ELSE approval_stage
                END,
                close_requested_at = NULL,
                closed_at = CURRENT_TIMESTAMP,
                closed_by = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND status != 'done'
        ");
        $stmt->execute([$userId, $taskId]);
        if ($stmt->rowCount() === 0) {
            return false;
        }

        $pdo->prepare("
            UPDATE task_deadline_shifts
            SET status = 'rejected',
                reviewed_by = ?,
                reviewed_at = CURRENT_TIMESTAMP,
                review_comment = 'Заявка закрыта автоматически: задача завершена.'
            WHERE task_id = ? AND status = 'pending'
        ")->execute([$userId, $taskId]);

        $placeholders = implode(',', array_fill(0, count(self::ACTION_NOTIFICATION_TYPES), '?'));
        $pdo->prepare("
            UPDATE notifications
            SET read_at = CURRENT_TIMESTAMP
            WHERE task_id = ? AND read_at IS NULL AND type IN ($placeholders)
        ")->execute([$taskId, ...self::ACTION_NOTIFICATION_TYPES]);

        return true;
    }

    private static function isTerminal(array $task): bool
    {
        return (string) ($task['status'] ?? '') === 'done'
            || trim((string) ($task['closed_at'] ?? '')) !== '';
    }

    private static function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
