<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

final class TimeApprovalService
{
    public const STATUS_LABELS = [
        'open' => 'Открыт',
        'draft' => 'Открыт',
        'returned' => 'Возвращено',
        'gip_approved' => 'ГИП подтвердил',
        'director_approved' => 'Месяц закрыт',
        'locked' => 'Месяц закрыт',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public static function monthStart(?string $date): string
    {
        $date = trim((string) $date);
        $base = $date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            ? new DateTimeImmutable($date)
            : new DateTimeImmutable('today');

        return $base->modify('first day of this month')->format('Y-m-d');
    }

    public static function monthEnd(string $monthStart): string
    {
        return (new DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');
    }

    public static function reviewStatusLabel(?array $review): string
    {
        if (!$review) {
            return self::STATUS_LABELS['draft'];
        }

        $status = (string) ($review['status'] ?? 'draft');
        if ($status === 'locked' || !empty($review['director_approved_at'])) {
            return self::STATUS_LABELS['locked'];
        }
        if ($status === 'returned') {
            return self::STATUS_LABELS['returned'];
        }
        if (!empty($review['department_approved_at']) && empty($review['gip_approved_at'])) {
            return 'Руководитель отдела подтвердил';
        }
        if (!empty($review['gip_approved_at'])) {
            return self::STATUS_LABELS['gip_approved'];
        }

        return self::STATUS_LABELS[$status] ?? $status;
    }

    public function reviewForUser(int $userId, string $monthStart): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT r.*, gip.name AS gip_approved_by_name, department.name AS department_approved_by_name,
                   director.name AS director_approved_by_name, returned.name AS returned_by_name
            FROM time_month_reviews r
            LEFT JOIN users gip ON gip.id = r.gip_approved_by
            LEFT JOIN users department ON department.id = r.department_approved_by
            LEFT JOIN users director ON director.id = r.director_approved_by
            LEFT JOIN users returned ON returned.id = r.returned_by
            WHERE r.user_id = ? AND r.period_start = ?
            LIMIT 1
        ');
        $stmt->execute([$userId, $monthStart]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function reviewsForUser(array $viewer, string $monthStart): array
    {
        $monthEnd = self::monthEnd($monthStart);
        $params = [
            'summary_start' => $monthStart,
            'summary_end' => $monthEnd,
            'period_start' => $monthStart,
        ];
        [$scopeSql, $scopeParams, $summaryProjectSql] = $this->scopeSql($viewer, $monthStart, $monthEnd);
        $params = array_merge($params, $scopeParams);
        $orderExpr = $this->isSqlite()
            ? "CASE COALESCE(r.status, 'open') WHEN 'returned' THEN 1 WHEN 'open' THEN 2 WHEN 'draft' THEN 2 WHEN 'gip_approved' THEN 3 WHEN 'director_approved' THEN 4 WHEN 'locked' THEN 5 ELSE 6 END"
            : "FIELD(COALESCE(r.status, 'open'), 'returned', 'open', 'draft', 'gip_approved', 'director_approved', 'locked')";

        $stmt = $this->pdo->prepare("
            SELECT u.id AS user_id, u.name, u.department, u.email,
                   r.id, COALESCE(r.status, 'open') AS status,
                   r.period_start, r.period_end,
                   r.gip_approved_at, r.gip_approved_by,
                   r.department_approved_at, r.department_approved_by,
                   r.director_approved_at, r.director_approved_by,
                   r.returned_at, r.returned_by, r.return_comment,
                   gip.name AS gip_approved_by_name,
                   department.name AS department_approved_by_name,
                   director.name AS director_approved_by_name,
                   returned.name AS returned_by_name,
                   COALESCE(summary.total_minutes, 0) AS total_minutes,
                   COALESCE(summary.locked_minutes, 0) AS locked_minutes,
                   summary.project_codes
            FROM users u
            LEFT JOIN time_month_reviews r ON r.user_id = u.id AND r.period_start = :period_start
            LEFT JOIN users gip ON gip.id = r.gip_approved_by
            LEFT JOIN users department ON department.id = r.department_approved_by
            LEFT JOIN users director ON director.id = r.director_approved_by
            LEFT JOIN users returned ON returned.id = r.returned_by
            LEFT JOIN (
                SELECT te.user_id,
                       COALESCE(SUM(te.minutes), 0) AS total_minutes,
                       COALESCE(SUM(CASE WHEN te.status = 'locked' THEN te.minutes ELSE 0 END), 0) AS locked_minutes,
                       GROUP_CONCAT(DISTINCT p.code) AS project_codes
                FROM time_entries te
                LEFT JOIN projects p ON p.id = te.project_id
                WHERE te.work_date BETWEEN :summary_start AND :summary_end
                  {$summaryProjectSql}
                GROUP BY te.user_id
            ) summary ON summary.user_id = u.id
            WHERE u.is_active = 1
              AND {$scopeSql}
            ORDER BY {$orderExpr}, u.department, u.name
        ");
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function gipApproveSnapshot(int $userId, string $monthStart, array $viewer): void
    {
        if (!$this->canGipApproveUserMonth($userId, $monthStart, $viewer)) {
            throw new InvalidArgumentException('Нет доступа к приемке этого среза.');
        }
        $reviewId = $this->ensureReview($userId, $monthStart);
        $this->pdo->prepare('
            UPDATE time_month_reviews
            SET status = CASE WHEN status = "locked" THEN "locked" ELSE "gip_approved" END,
                gip_approved_at = CURRENT_TIMESTAMP,
                gip_approved_by = ?,
                returned_at = NULL,
                returned_by = NULL,
                return_comment = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([(int) $viewer['id'], $reviewId]);
    }

    public function departmentApproveSnapshot(int $userId, string $monthStart, array $viewer): void
    {
        if (!$this->canDepartmentApproveUserMonth($userId, $viewer)) {
            throw new InvalidArgumentException('Нет доступа к приемке этого отдела.');
        }
        $reviewId = $this->ensureReview($userId, $monthStart);
        $this->pdo->prepare('
            UPDATE time_month_reviews
            SET status = CASE WHEN status = "locked" THEN "locked" WHEN status = "gip_approved" THEN "gip_approved" ELSE "draft" END,
                department_approved_at = CURRENT_TIMESTAMP,
                department_approved_by = ?,
                returned_at = NULL,
                returned_by = NULL,
                return_comment = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([(int) $viewer['id'], $reviewId]);
    }

    public function closeMonthSnapshot(int $userId, string $monthStart, array $viewer): void
    {
        if (!PermissionService::canDirectorApproveTime($viewer)) {
            throw new InvalidArgumentException('Закрывать месяц может только директор или администратор.');
        }
        $reviewId = $this->ensureReview($userId, $monthStart);
        $monthEnd = self::monthEnd($monthStart);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('
                UPDATE time_month_reviews
                SET status = "locked",
                    director_approved_at = CURRENT_TIMESTAMP,
                    director_approved_by = ?,
                    returned_at = NULL,
                    returned_by = NULL,
                    return_comment = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ')->execute([(int) $viewer['id'], $reviewId]);
            $this->updateEntryStatus($userId, $monthStart, $monthEnd, ['draft', 'submitted', 'approved'], 'locked');
            $this->updateBatchStatus($userId, $monthStart, $monthEnd, ['draft', 'submitted', 'approved'], 'locked');
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function returnSnapshot(int $userId, string $monthStart, array $viewer, string $comment): void
    {
        $review = $this->reviewForUser($userId, $monthStart);
        if ($review && (string) $review['status'] === 'locked') {
            throw new InvalidArgumentException('Закрытый месяц открывает на корректировку директор или администратор.');
        }
        if (
            !PermissionService::canDirectorApproveTime($viewer)
            && !$this->canDepartmentApproveUserMonth($userId, $viewer)
            && !$this->canGipApproveUserMonth($userId, $monthStart, $viewer)
        ) {
            throw new InvalidArgumentException('Нет доступа к возврату этого среза.');
        }

        $reviewId = $this->ensureReview($userId, $monthStart);
        $this->pdo->prepare('
            UPDATE time_month_reviews
            SET status = "returned",
                gip_approved_at = NULL,
                gip_approved_by = NULL,
                department_approved_at = NULL,
                department_approved_by = NULL,
                returned_at = CURRENT_TIMESTAMP,
                returned_by = ?,
                return_comment = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ')->execute([(int) $viewer['id'], mb_substr(trim($comment), 0, 1000) ?: null, $reviewId]);
    }

    public function reopenLockedMonthForCorrection(int $userId, string $monthStart, array $viewer, string $comment): void
    {
        if (!PermissionService::canDirectorApproveTime($viewer)) {
            throw new InvalidArgumentException('Открывать закрытый месяц может только директор или администратор.');
        }
        $comment = mb_substr(trim($comment), 0, 1000);
        if ($comment === '') {
            throw new InvalidArgumentException('Укажите причину корректировки закрытого месяца.');
        }

        $review = $this->reviewForUser($userId, $monthStart);
        if (!$review || (string) $review['status'] !== 'locked') {
            throw new InvalidArgumentException('Открыть на корректировку можно только закрытый месяц.');
        }
        $monthEnd = self::monthEnd($monthStart);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('
                UPDATE time_month_reviews
                SET status = "returned",
                    gip_approved_at = NULL,
                    gip_approved_by = NULL,
                    department_approved_at = NULL,
                    department_approved_by = NULL,
                    director_approved_at = NULL,
                    director_approved_by = NULL,
                    returned_at = CURRENT_TIMESTAMP,
                    returned_by = ?,
                    return_comment = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ')->execute([(int) $viewer['id'], 'Корректировка закрытого месяца: ' . $comment, (int) $review['id']]);
            $this->updateEntryStatus($userId, $monthStart, $monthEnd, ['locked'], 'draft');
            $this->updateBatchStatus($userId, $monthStart, $monthEnd, ['locked'], 'draft');
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function ensureReview(int $userId, string $monthStart): int
    {
        $monthEnd = self::monthEnd($monthStart);
        $existing = $this->reviewForUser($userId, $monthStart);
        if ($existing) {
            return (int) $existing['id'];
        }

        if ($this->isSqlite()) {
            $stmt = $this->pdo->prepare('
                INSERT INTO time_month_reviews (user_id, period_start, period_end, status, updated_at)
                VALUES (?, ?, ?, "draft", CURRENT_TIMESTAMP)
            ');
        } else {
            $stmt = $this->pdo->prepare('
                INSERT INTO time_month_reviews (user_id, period_start, period_end, status)
                VALUES (?, ?, ?, "draft")
            ');
        }
        try {
            $stmt->execute([$userId, $monthStart, $monthEnd]);
        } catch (\PDOException $e) {
            if ($this->isDuplicateReviewConflict($e)) {
                $created = $this->reviewForUser($userId, $monthStart);
                if ($created) {
                    return (int) $created['id'];
                }
            }
            throw $e;
        }

        $insertedId = (int) $this->pdo->lastInsertId();
        if ($insertedId > 0) {
            return $insertedId;
        }

        $created = $this->reviewForUser($userId, $monthStart);
        if ($created) {
            return (int) $created['id'];
        }

        throw new InvalidArgumentException('Не удалось создать месячный срез времени.');
    }

    private function isDuplicateReviewConflict(\PDOException $e): bool
    {
        return $e->getCode() === '23000' || str_contains(strtolower($e->getMessage()), 'unique');
    }

    private function canGipApproveUserMonth(int $userId, string $monthStart, array $viewer): bool
    {
        if (PermissionService::canDirectorApproveTime($viewer)) {
            return true;
        }
        if (!RoleService::isAny($viewer['role'] ?? null, [RoleService::GIP, RoleService::PROJECT_MANAGER])) {
            return false;
        }
        $stmt = $this->pdo->prepare('
            SELECT 1
            FROM time_entries te
            INNER JOIN projects p ON p.id = te.project_id
            WHERE te.user_id = ?
              AND te.work_date BETWEEN ? AND ?
              AND (p.gip_user_id = ? OR p.rp_user_id = ?)
            LIMIT 1
        ');
        $stmt->execute([$userId, $monthStart, self::monthEnd($monthStart), (int) $viewer['id'], (int) $viewer['id']]);

        return (bool) $stmt->fetchColumn();
    }

    private function canDepartmentApproveUserMonth(int $userId, array $viewer): bool
    {
        if (PermissionService::canDirectorApproveTime($viewer)) {
            return true;
        }
        if (!RoleService::isAny($viewer['role'] ?? null, [RoleService::DEPARTMENT_HEAD, RoleService::DEPUTY_DEPARTMENT_HEAD])) {
            return false;
        }
        $department = trim((string) ($viewer['department'] ?? ''));
        if ($department === '') {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE id = ? AND department = ? LIMIT 1');
        $stmt->execute([$userId, $department]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return array{0:string,1:array<string,mixed>,2:string}
     */
    private function scopeSql(array $viewer, string $monthStart, string $monthEnd): array
    {
        if (PermissionService::canDirectorApproveTime($viewer)) {
            return ['1=1', [], ''];
        }

        if (RoleService::isAny($viewer['role'] ?? null, [RoleService::DEPARTMENT_HEAD, RoleService::DEPUTY_DEPARTMENT_HEAD])) {
            return [
                'u.department = :viewer_department',
                ['viewer_department' => (string) ($viewer['department'] ?? '')],
                '',
            ];
        }

        return [
            'EXISTS (
                SELECT 1
                FROM time_entries te_scope
                  INNER JOIN projects p_scope ON p_scope.id = te_scope.project_id
                WHERE te_scope.user_id = u.id
                  AND te_scope.work_date BETWEEN :scope_start AND :scope_end
                  AND (p_scope.gip_user_id = :scope_viewer_id OR p_scope.rp_user_id = :scope_viewer_id)
            )',
            [
                'scope_start' => $monthStart,
                'scope_end' => $monthEnd,
                'scope_viewer_id' => (int) $viewer['id'],
                'summary_viewer_id' => (int) $viewer['id'],
            ],
            'AND (p.gip_user_id = :summary_viewer_id OR p.rp_user_id = :summary_viewer_id)',
        ];
    }

    private function updateEntryStatus(int $userId, string $monthStart, string $monthEnd, array $fromStatuses, string $toStatus): void
    {
        $placeholders = implode(',', array_fill(0, count($fromStatuses), '?'));
        $stmt = $this->pdo->prepare("
            UPDATE time_entries
            SET status = ?, updated_at = CURRENT_TIMESTAMP
            WHERE user_id = ?
              AND work_date BETWEEN ? AND ?
              AND status IN ({$placeholders})
        ");
        $stmt->execute([$toStatus, $userId, $monthStart, $monthEnd, ...$fromStatuses]);
    }

    private function updateBatchStatus(int $userId, string $monthStart, string $monthEnd, array $fromStatuses, string $toStatus): void
    {
        $placeholders = implode(',', array_fill(0, count($fromStatuses), '?'));
        $stmt = $this->pdo->prepare("
            UPDATE time_batches
            SET status = ?
            WHERE user_id = ?
              AND period_start >= ?
              AND period_end <= ?
              AND status IN ({$placeholders})
        ");
        $stmt->execute([$toStatus, $userId, $monthStart, $monthEnd, ...$fromStatuses]);
    }

    private function isSqlite(): bool
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }
}
