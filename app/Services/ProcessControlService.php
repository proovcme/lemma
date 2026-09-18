<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use PDO;

final class ProcessControlService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function project(int $projectId): array
    {
        return $this->build(' AND p.id = :process_project_id', ['process_project_id' => $projectId], []);
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $projectParams
     * @return array<string,mixed>
     */
    public function dashboard(array $filters, string $projectFilterSql, array $projectParams): array
    {
        $sql = $projectFilterSql;
        $params = $projectParams;

        if (($filters['assignee_id'] ?? '') !== '') {
            $sql .= ' AND t.assignee_id = :process_assignee_id';
            $params['process_assignee_id'] = (int) $filters['assignee_id'];
        }
        if (($filters['date_from'] ?? '') !== '') {
            $sql .= ' AND DATE(COALESCE(t.closed_at, t.date_end, t.updated_at, t.created_at)) >= :process_date_from';
            $params['process_date_from'] = (string) $filters['date_from'];
        }
        if (($filters['date_to'] ?? '') !== '') {
            $sql .= ' AND DATE(COALESCE(t.closed_at, t.date_end, t.updated_at, t.created_at)) <= :process_date_to';
            $params['process_date_to'] = (string) $filters['date_to'];
        }

        return $this->build($sql, $params, []);
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function build(string $projectSql, array $params, array $options): array
    {
        $tasks = $this->tasks($projectSql, $params, (int) ($options['limit'] ?? 5000));
        $taskIds = array_map(static fn (array $row): int => (int) $row['id'], $tasks);
        $logs = $this->logsByTask($taskIds);
        $approvalRejects = $this->approvalRejectsByTask($taskIds);
        $issuanceCounts = $this->issuanceCountsByTask($taskIds);
        $reworkHours = $this->reworkHoursByTask($taskIds);
        $atlasTasks = $this->atlasTaskIds($taskIds);

        $today = new DateTimeImmutable('today');
        $statusRows = [];
        $departmentRows = [];
        $slowTasks = [];
        $reviewWaitDays = [];
        $closedCycleDays = [];
        $correctionLoopsTotal = 0;
        $issuanceIterations = 0;
        $totalReworkHours = 0.0;
        $open = 0;
        $done = 0;
        $overdue = 0;

        foreach ($tasks as $task) {
            $taskId = (int) $task['id'];
            $status = (string) ($task['status'] ?? 'new');
            $createdAt = $this->date((string) ($task['created_at'] ?? '')) ?? $today;
            $closedAt = $this->date((string) ($task['closed_at'] ?? ''));
            $statusSince = $this->statusSince($task, $logs[$taskId] ?? []) ?? $createdAt;
            $ageDays = $this->days($statusSince, $closedAt ?? $today);
            $projectCode = (string) ($task['project_code'] ?? '');
            $department = trim((string) ($task['assignee_department'] ?? '')) ?: 'Без отдела';
            $isDone = $status === 'done' || $closedAt !== null;
            $isOpen = !$isDone;
            $isOverdue = $isOpen && $this->isOverdue($task, $today);
            $correctionLoops = max(
                $this->statusTransitions($logs[$taskId] ?? [], 'correction'),
                (int) ($approvalRejects[$taskId] ?? 0)
            );
            $taskReworkHours = (float) ($reworkHours[$taskId] ?? 0.0);
            $issueCount = (int) ($issuanceCounts[$taskId] ?? 0);

            $open += $isOpen ? 1 : 0;
            $done += $isDone ? 1 : 0;
            $overdue += $isOverdue ? 1 : 0;
            $correctionLoopsTotal += $correctionLoops;
            $totalReworkHours += $taskReworkHours;
            $issuanceIterations += max(0, $issueCount - 1);

            $statusRows[$status] ??= ['status' => $status, 'label' => $this->statusLabel($status), 'count' => 0, 'age_sum' => 0, 'max_age_days' => 0];
            $statusRows[$status]['count']++;
            $statusRows[$status]['age_sum'] += $ageDays;
            $statusRows[$status]['max_age_days'] = max((int) $statusRows[$status]['max_age_days'], $ageDays);

            $departmentRows[$department] ??= [
                'department' => $department,
                'open_tasks' => 0,
                'overdue_tasks' => 0,
                'review_tasks' => 0,
                'correction_tasks' => 0,
                'correction_loops' => 0,
                'rework_hours' => 0.0,
            ];
            $departmentRows[$department]['open_tasks'] += $isOpen ? 1 : 0;
            $departmentRows[$department]['overdue_tasks'] += $isOverdue ? 1 : 0;
            $departmentRows[$department]['review_tasks'] += in_array($status, ['review', 'pending_close'], true) ? 1 : 0;
            $departmentRows[$department]['correction_tasks'] += $status === 'correction' ? 1 : 0;
            $departmentRows[$department]['correction_loops'] += $correctionLoops;
            $departmentRows[$department]['rework_hours'] += $taskReworkHours;

            if ($isDone && $closedAt !== null) {
                $closedCycleDays[] = $this->days($createdAt, $closedAt);
            }
            if ($isOpen && (in_array($status, ['review', 'pending_close'], true) || in_array((string) ($task['approval_stage'] ?? ''), ['review_lead', 'review_gip'], true))) {
                $reviewWaitDays[] = $ageDays;
            }
            if ($isOpen && ($isOverdue || $ageDays >= 3 || $correctionLoops > 0 || $taskReworkHours > 0.0)) {
                $slowTasks[] = [
                    'id' => $taskId,
                    'title' => (string) ($task['title'] ?? ''),
                    'project_id' => (int) ($task['project_id'] ?? 0),
                    'project_code' => $projectCode,
                    'status' => $status,
                    'status_label' => $this->statusLabel($status),
                    'assignee_name' => (string) ($task['assignee_name'] ?? ''),
                    'department' => $department,
                    'age_days' => $ageDays,
                    'overdue' => $isOverdue,
                    'correction_loops' => $correctionLoops,
                    'rework_hours' => round($taskReworkHours, 1),
                ];
            }
        }

        foreach ($statusRows as &$row) {
            $row['avg_age_days'] = (int) round(((int) $row['age_sum']) / max(1, (int) $row['count']));
            unset($row['age_sum']);
        }
        unset($row);

        foreach ($departmentRows as &$row) {
            $row['rework_hours'] = round((float) $row['rework_hours'], 1);
        }
        unset($row);

        usort($statusRows, static fn (array $a, array $b): int => (int) $b['count'] <=> (int) $a['count']);
        usort($departmentRows, static function (array $a, array $b): int {
            return ((int) $b['overdue_tasks'] <=> (int) $a['overdue_tasks'])
                ?: ((int) $b['review_tasks'] <=> (int) $a['review_tasks'])
                ?: ((int) $b['correction_loops'] <=> (int) $a['correction_loops'])
                ?: strcmp((string) $a['department'], (string) $b['department']);
        });
        usort($slowTasks, static function (array $a, array $b): int {
            return ((int) $b['overdue'] <=> (int) $a['overdue'])
                ?: ((int) $b['age_days'] <=> (int) $a['age_days'])
                ?: ((int) $b['correction_loops'] <=> (int) $a['correction_loops']);
        });

        return [
            'overall' => [
                'total_tasks' => count($tasks),
                'open_tasks' => $open,
                'done_tasks' => $done,
                'overdue_tasks' => $overdue,
                'avg_cycle_days' => $this->avg($closedCycleDays),
                'avg_review_wait_days' => $this->avg($reviewWaitDays),
                'correction_loops' => $correctionLoopsTotal,
                'rework_hours' => round($totalReworkHours, 1),
                'issuance_iterations' => $issuanceIterations,
                'atlas_tasks' => count($atlasTasks),
            ],
            'status_rows' => array_values($statusRows),
            'departments' => array_slice(array_values($departmentRows), 0, 12),
            'slow_tasks' => array_slice($slowTasks, 0, 12),
            'bottlenecks' => $this->bottlenecks($open, $overdue, $reviewWaitDays, $correctionLoopsTotal, $totalReworkHours, $departmentRows, $slowTasks),
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    private function tasks(string $projectSql, array $params, int $limit): array
    {
        $stmt = $this->pdo->prepare('
            SELECT t.id, t.project_id, t.title, t.status, t.approval_stage, t.task_type,
                   t.created_at, t.updated_at, t.closed_at, t.close_requested_at, t.date_end,
                   p.code AS project_code,
                   u.name AS assignee_name,
                   u.department AS assignee_department
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN users u ON u.id = t.assignee_id
            WHERE COALESCE(t.task_type, "work") NOT IN ("review", "delegation")
              ' . $projectSql . '
            ORDER BY COALESCE(t.closed_at, t.date_end, t.updated_at, t.created_at) DESC, t.id DESC
            LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<int> $taskIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function logsByTask(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = $this->pdo->prepare('
            SELECT task_id, field, old_val, new_val, created_at
            FROM task_logs
            WHERE task_id IN (' . $placeholders . ')
              AND field IN ("status", "approval_stage")
            ORDER BY task_id ASC, created_at ASC, id ASC
        ');
        $stmt->execute($taskIds);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['task_id']][] = $row;
        }

        return $result;
    }

    /**
     * @param list<int> $taskIds
     * @return array<int,int>
     */
    private function approvalRejectsByTask(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = $this->pdo->prepare('
            SELECT task_id, COUNT(*) AS cnt
            FROM task_approvals
            WHERE task_id IN (' . $placeholders . ')
              AND decision = "rejected"
            GROUP BY task_id
        ');
        $stmt->execute($taskIds);

        return $this->intMap($stmt->fetchAll(PDO::FETCH_ASSOC), 'task_id', 'cnt');
    }

    /**
     * @param list<int> $taskIds
     * @return array<int,int>
     */
    private function issuanceCountsByTask(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = $this->pdo->prepare('
            SELECT task_id, COUNT(*) AS cnt
            FROM task_issuances
            WHERE task_id IN (' . $placeholders . ')
            GROUP BY task_id
        ');
        $stmt->execute($taskIds);

        return $this->intMap($stmt->fetchAll(PDO::FETCH_ASSOC), 'task_id', 'cnt');
    }

    /**
     * @param list<int> $taskIds
     * @return array<int,float>
     */
    private function reworkHoursByTask(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = $this->pdo->prepare('
            SELECT task_id, ROUND(SUM(minutes) / 60.0, 2) AS hours
            FROM time_entries
            WHERE task_id IN (' . $placeholders . ')
              AND phase IN ("correction", "repeat_review")
            GROUP BY task_id
        ');
        $stmt->execute($taskIds);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['task_id']] = (float) $row['hours'];
        }

        return $result;
    }

    /**
     * @param list<int> $taskIds
     * @return list<int>
     */
    private function atlasTaskIds(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = $this->pdo->prepare('SELECT DISTINCT task_id FROM task_atlas_refs WHERE task_id IN (' . $placeholders . ')');
        $stmt->execute($taskIds);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<array<string,mixed>> $logs
     */
    private function statusSince(array $task, array $logs): ?DateTimeImmutable
    {
        $current = (string) ($task['status'] ?? '');
        $latest = null;
        foreach ($logs as $log) {
            if ((string) ($log['field'] ?? '') === 'status' && (string) ($log['new_val'] ?? '') === $current) {
                $latest = (string) ($log['created_at'] ?? '');
            }
        }

        return $this->date($latest ?: (string) (($task['updated_at'] ?? '') ?: ($task['created_at'] ?? '')));
    }

    /**
     * @param list<array<string,mixed>> $logs
     */
    private function statusTransitions(array $logs, string $status): int
    {
        $count = 0;
        foreach ($logs as $log) {
            if ((string) ($log['field'] ?? '') === 'status' && (string) ($log['new_val'] ?? '') === $status) {
                $count++;
            }
        }

        return $count;
    }

    private function isOverdue(array $task, DateTimeImmutable $today): bool
    {
        $date = $this->date((string) ($task['date_end'] ?? ''));

        return $date !== null && $date < $today;
    }

    private function date(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function days(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if ($to < $from) {
            return 0;
        }

        return (int) $from->diff($to)->days;
    }

    /**
     * @param list<int> $values
     */
    private function avg(array $values): float
    {
        return $values === [] ? 0.0 : round(array_sum($values) / count($values), 1);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,int>
     */
    private function intMap(array $rows, string $keyColumn, string $valueColumn): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row[$keyColumn]] = (int) $row[$valueColumn];
        }

        return $map;
    }

    /**
     * @param list<int> $reviewWaitDays
     * @param list<array<string,mixed>> $departmentRows
     * @param list<array<string,mixed>> $slowTasks
     * @return list<array{level:string,title:string,detail:string}>
     */
    private function bottlenecks(int $open, int $overdue, array $reviewWaitDays, int $correctionLoops, float $reworkHours, array $departmentRows, array $slowTasks): array
    {
        $items = [];
        if ($overdue > 0) {
            $items[] = ['level' => 'red', 'title' => 'Просрочка в потоке', 'detail' => 'Открытых просроченных задач: ' . $overdue];
        }
        $avgReview = $this->avg($reviewWaitDays);
        if ($avgReview >= 3.0) {
            $items[] = ['level' => 'yellow', 'title' => 'Очередь проверки', 'detail' => 'Среднее ожидание проверки: ' . $avgReview . ' дн.'];
        }
        if ($correctionLoops > 0) {
            $items[] = ['level' => 'yellow', 'title' => 'Повторные возвраты', 'detail' => 'Циклов возврата/отклонения: ' . $correctionLoops];
        }
        if ($reworkHours > 0.0) {
            $items[] = ['level' => 'yellow', 'title' => 'Часы переделок', 'detail' => 'Списано на корректировки: ' . round($reworkHours, 1) . ' ч'];
        }

        $topDepartment = $departmentRows[0] ?? null;
        if ($topDepartment && ((int) $topDepartment['overdue_tasks'] > 0 || (int) $topDepartment['review_tasks'] > 0)) {
            $items[] = [
                'level' => (int) $topDepartment['overdue_tasks'] > 0 ? 'red' : 'yellow',
                'title' => 'Отдел в фокусе',
                'detail' => $topDepartment['department'] . ': просрочено ' . (int) $topDepartment['overdue_tasks'] . ', на проверке ' . (int) $topDepartment['review_tasks'],
            ];
        }
        if ($open > 0 && $slowTasks === []) {
            $items[] = ['level' => 'green', 'title' => 'Поток без явного стопора', 'detail' => 'Открытые задачи есть, но критичных задержек по статусам не видно.'];
        }

        return array_slice($items, 0, 6);
    }

    private function statusLabel(string $status): string
    {
        return [
            'new' => 'Новая',
            'in_progress' => 'В работе',
            'review' => 'На проверке',
            'pending_close' => 'На закрытии',
            'correction' => 'Корректировка',
            'blocked' => 'Блокирована',
            'overdue' => 'Просрочена',
            'done' => 'Закрыта',
        ][$status] ?? $status;
    }
}
