<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class TaskActionQueueService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<int> */
    public function taskIds(array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) return [];
        $canManage = RoleService::isAny($user['role'] ?? null, [RoleService::DEPUTY_DIRECTOR, RoleService::DIRECTOR, RoleService::ADMIN]) ? 1 : 0;
        $isGip = RoleService::isAny($user['role'] ?? null, [RoleService::GIP, RoleService::DEPUTY_DIRECTOR, RoleService::DIRECTOR]) ? 1 : 0;
        $stmt = $this->pdo->prepare('SELECT DISTINCT t.id
            FROM tasks t INNER JOIN projects p ON p.id = t.project_id
            WHERE p.status = "active" AND t.status <> "done" AND t.closed_at IS NULL AND COALESCE(t.task_type, "") <> "review"
              AND (
                (t.approval_stage = "review_lead" AND (
                    t.reviewer_id = :lead_reviewer
                    OR EXISTS (
                        SELECT 1 FROM employee_vacations lead_vacation
                        WHERE lead_vacation.user_id = t.reviewer_id
                          AND lead_vacation.substitute_user_id = :lead_substitute
                          AND lead_vacation.cancelled_at IS NULL
                          AND :today_lead BETWEEN lead_vacation.date_from AND lead_vacation.date_to
                    )
                ))
                OR (t.approval_stage = "review_gip" AND (p.gip_user_id = :project_gip OR ((p.gip_user_id IS NULL OR p.gip_user_id = 0) AND :is_gip = 1)))
                OR (t.task_type = "issuance" AND t.approval_stage = "review_gip" AND t.assignee_id = :issuance_assignee AND :self_issuance = 1)
                OR (t.status IN ("review", "pending_close") AND t.close_requested_at IS NOT NULL
                    AND (t.reviewer_id = :close_reviewer OR t.author_id = :close_author OR p.gip_user_id = :close_gip OR :can_manage = 1
                        OR EXISTS (
                            SELECT 1 FROM employee_vacations close_vacation
                            WHERE close_vacation.user_id IN (t.reviewer_id, t.author_id)
                              AND close_vacation.substitute_user_id = :close_substitute
                              AND close_vacation.cancelled_at IS NULL
                              AND :today_close BETWEEN close_vacation.date_from AND close_vacation.date_to
                        ))
                    AND NOT EXISTS (SELECT 1 FROM task_approvals ra WHERE ra.task_id = t.id AND ra.stage = "review_task" AND ra.decision IN ("approved", "rejected") AND ra.created_at >= t.close_requested_at))
              )
            ORDER BY t.id');
        $stmt->execute([
            'lead_reviewer' => $userId, 'lead_substitute' => $userId, 'today_lead' => date('Y-m-d'),
            'project_gip' => $userId, 'is_gip' => $isGip,
            'issuance_assignee' => $userId, 'self_issuance' => PermissionService::canSelfApproveIssuance($user) ? 1 : 0,
            'close_reviewer' => $userId, 'close_author' => $userId, 'close_gip' => $userId, 'can_manage' => $canManage,
            'close_substitute' => $userId, 'today_close' => date('Y-m-d'),
        ]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function count(array $user): int
    {
        return count($this->taskIds($user));
    }
}
