<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class TaskWorkflowAuditService
{
    private const ACTION_NOTIFICATION_TYPES = [
        'review_task_created',
        'approval_review_lead',
        'approval_review_gip',
        'close_gip_requested',
        'deadline_shift_requested',
    ];

    public static function fromPdo(PDO $pdo): array
    {
        $tables = [];
        foreach (['tasks', 'task_smart', 'task_deadline_shifts', 'task_participants', 'notifications', 'project_task_exchange'] as $table) {
            $tables[$table] = $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
        }

        return self::fromRows($tables);
    }

    public static function fromRows(array $tables): array
    {
        $errors = [
            'done_active_approval' => [],
            'parallel_close_approval' => [],
            'pending_shift_terminal' => [],
            'pending_shift_stale' => [],
            'stale_close_request' => [],
            'assignee_participant_duplicate' => [],
            'stale_action_notifications' => [],
        ];
        $warnings = [
            'ambiguous_legacy_relations' => [],
            'assignment_tasks_unlinked' => [],
        ];

        $tasks = [];
        foreach ((array) ($tables['tasks'] ?? []) as $task) {
            $taskId = (int) ($task['id'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }
            $tasks[$taskId] = $task;
            $status = (string) ($task['status'] ?? '');
            $approval = (string) ($task['approval_stage'] ?? 'draft');
            $closeRequested = trim((string) ($task['close_requested_at'] ?? '')) !== '';
            if ($status === 'done' && in_array($approval, ['review_lead', 'review_gip'], true)) {
                $errors['done_active_approval'][] = $taskId;
            }
            if (in_array($status, ['review', 'pending_close'], true)
                && $closeRequested
                && in_array($approval, ['review_lead', 'review_gip'], true)
            ) {
                $errors['parallel_close_approval'][] = $taskId;
            }
            if ($closeRequested && !in_array($status, ['review', 'pending_close'], true)) {
                $errors['stale_close_request'][] = $taskId;
            }
        }

        $validPendingShiftByTask = [];
        foreach ((array) ($tables['task_deadline_shifts'] ?? []) as $shift) {
            if ((string) ($shift['status'] ?? '') !== 'pending') {
                continue;
            }
            $taskId = (int) ($shift['task_id'] ?? 0);
            $task = $tasks[$taskId] ?? null;
            if (!$task) {
                continue;
            }
            $shiftId = (int) ($shift['id'] ?? 0);
            if ((string) ($task['status'] ?? '') === 'done' || trim((string) ($task['closed_at'] ?? '')) !== '') {
                $errors['pending_shift_terminal'][] = $shiftId;
                continue;
            }
            if (self::dateOrNull($task['date_end'] ?? null) !== self::dateOrNull($shift['date_old'] ?? null)) {
                $errors['pending_shift_stale'][] = $shiftId;
                continue;
            }
            $validPendingShiftByTask[$taskId] = true;
        }

        foreach ((array) ($tables['task_participants'] ?? []) as $participant) {
            $taskId = (int) ($participant['task_id'] ?? 0);
            $userId = (int) ($participant['user_id'] ?? 0);
            if ($taskId > 0 && $userId > 0 && (int) ($tasks[$taskId]['assignee_id'] ?? 0) === $userId) {
                $errors['assignee_participant_duplicate'][] = $taskId . ':' . $userId;
            }
        }

        $smartByTask = [];
        foreach ((array) ($tables['task_smart'] ?? []) as $smart) {
            $smartByTask[(int) ($smart['task_id'] ?? 0)] = $smart;
        }
        foreach ($tasks as $taskId => $task) {
            $task['depends_on'] = (string) ($smartByTask[$taskId]['depends_on'] ?? '');
            if (TaskWorkflowIntegrityService::isAmbiguousLegacyRelation($task)) {
                $warnings['ambiguous_legacy_relations'][] = $taskId;
            }
        }

        $linkedExchangeTasks = [];
        foreach ((array) ($tables['project_task_exchange'] ?? []) as $exchange) {
            $taskId = (int) ($exchange['task_id'] ?? 0);
            if ($taskId > 0) {
                $linkedExchangeTasks[$taskId] = true;
            }
        }
        foreach ($tasks as $taskId => $task) {
            if ((string) ($task['task_type'] ?? '') === 'assignment' && !isset($linkedExchangeTasks[$taskId])) {
                $warnings['assignment_tasks_unlinked'][] = $taskId;
            }
        }

        foreach ((array) ($tables['notifications'] ?? []) as $notification) {
            if (trim((string) ($notification['read_at'] ?? '')) !== '') {
                continue;
            }
            $type = (string) ($notification['type'] ?? '');
            if (!in_array($type, self::ACTION_NOTIFICATION_TYPES, true)) {
                continue;
            }
            $taskId = (int) ($notification['task_id'] ?? 0);
            $task = $tasks[$taskId] ?? null;
            if (!$task || !self::notificationIsActionable($type, $task, isset($validPendingShiftByTask[$taskId]))) {
                $errors['stale_action_notifications'][] = (int) ($notification['id'] ?? 0);
            }
        }

        foreach ($errors as &$ids) {
            $ids = array_values(array_unique($ids));
            sort($ids, SORT_REGULAR);
        }
        unset($ids);
        foreach ($warnings as &$ids) {
            $ids = array_values(array_unique($ids));
            sort($ids, SORT_REGULAR);
        }
        unset($ids);

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'error_count' => array_sum(array_map('count', $errors)),
            'warning_count' => array_sum(array_map('count', $warnings)),
        ];
    }

    private static function notificationIsActionable(string $type, array $task, bool $hasValidPendingShift): bool
    {
        $status = (string) ($task['status'] ?? '');
        $approval = (string) ($task['approval_stage'] ?? 'draft');
        $closeRequested = trim((string) ($task['close_requested_at'] ?? '')) !== '';
        if ($status === 'done' || trim((string) ($task['closed_at'] ?? '')) !== '') {
            return false;
        }

        return match ($type) {
            'approval_review_lead' => $approval === 'review_lead' && !in_array($status, ['review', 'pending_close'], true) && !$closeRequested,
            'approval_review_gip' => $approval === 'review_gip' && !in_array($status, ['review', 'pending_close'], true) && !$closeRequested,
            'review_task_created' => in_array($status, ['review', 'pending_close'], true) && $closeRequested,
            'close_gip_requested' => $status === 'pending_close' && $closeRequested,
            'deadline_shift_requested' => $hasValidPendingShift,
            default => false,
        };
    }

    private static function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
