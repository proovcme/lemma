<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use PDO;

final class WorkdayService
{
    private const ABSENCE_CATEGORIES = ['vacation', 'sick_leave', 'business_trip', 'learning', 'day_off'];

    public function __construct(private PDO $pdo)
    {
    }

    public function model(array $user, array $filters = []): array
    {
        $today = date('Y-m-d');
        $dateFrom = $this->dateOrDefault($filters['date_from'] ?? '', $today);
        $dateTo = $this->dateOrDefault($filters['date_to'] ?? '', (new DateTimeImmutable($dateFrom))->modify('+13 days')->format('Y-m-d'));
        if ($dateTo < $dateFrom) {
            $dateTo = $dateFrom;
        }

        $tasks = $this->tasksForUser($user, $today);
        $timeToday = $this->timeForUser((int) $user['id'], $today, $today);
        $team = $this->teamLoad($user, $dateFrom, $dateTo, (string) ($filters['department'] ?? ''));
        $managementProjects = $this->managementProjects($user);

        return [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'today' => $today,
            'waiting' => $tasks['waiting'],
            'inWork' => $tasks['inWork'],
            'review' => $tasks['review'],
            'due' => $tasks['due'],
            'timeToday' => $timeToday,
            'teamLoad' => $team,
            'departments' => $this->departmentsForUser($user),
            'canSeeTeamLoad' => PermissionService::canOpenReports($user),
            'managementProjects' => $managementProjects,
            'managementSummary' => [
                'employees' => count($team),
                'overloaded' => count(array_filter($team, static fn (array $row): bool => ($row['load_status'] ?? '') === 'overloaded')),
                'employee_overdue' => array_sum(array_map(static fn (array $row): int => (int) ($row['overdue_tasks'] ?? 0), $team)),
                'projects' => count($managementProjects),
                'projects_at_risk' => count(array_filter($managementProjects, static fn (array $row): bool => (int) ($row['overdue_tasks'] ?? 0) > 0)),
            ],
        ];
    }

    private function managementProjects(array $user): array
    {
        if (!PermissionService::canOpenReports($user)) {
            return [];
        }
        [$scope, $params] = PermissionService::projectScopeWhere($user, 'p', 'workday_scope_task');
        $stmt = $this->pdo->prepare('SELECT p.id, p.code, p.title,
                SUM(CASE WHEN t.id IS NOT NULL AND t.status <> \'done\' AND t.closed_at IS NULL THEN 1 ELSE 0 END) AS open_tasks,
                SUM(CASE WHEN t.id IS NOT NULL AND t.status <> \'done\' AND t.closed_at IS NULL
                    AND (t.status = \'overdue\' OR t.date_end < CURRENT_DATE) THEN 1 ELSE 0 END) AS overdue_tasks,
                COUNT(DISTINCT CASE WHEN t.status <> \'done\' AND t.closed_at IS NULL THEN t.assignee_id END) AS active_people
            FROM projects p
            LEFT JOIN tasks t ON t.project_id = p.id
            WHERE p.status = \'active\' AND COALESCE(p.kind, \'project\') = \'project\' AND ' . $scope . '
            GROUP BY p.id, p.code, p.title
            ORDER BY overdue_tasks DESC, open_tasks DESC, p.code
            LIMIT 8');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @return array{waiting: list<array<string, mixed>>, inWork: list<array<string, mixed>>, review: list<array<string, mixed>>, due: list<array<string, mixed>>}
     */
    private function tasksForUser(array $user, string $today): array
    {
        $userId = (int) $user['id'];
        $actionIds = (new TaskActionQueueService($this->pdo))->taskIds($user);
        $actionParams = [];
        $actionPlaceholders = [];
        foreach ($actionIds as $index => $actionId) {
            $key = 'workday_action_' . $index;
            $actionPlaceholders[] = ':' . $key;
            $actionParams[$key] = $actionId;
        }
        $actionSql = $actionPlaceholders ? ' OR t.id IN (' . implode(',', $actionPlaceholders) . ')' : '';
        $week = (new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d');
        $stmt = $this->pdo->prepare('
            SELECT DISTINCT t.id, t.title, t.status, t.task_type, t.priority, t.urgency,
                   t.date_start, t.date_end, t.planned_hours, t.actual_hours, t.progress,
                   t.assignee_id, t.author_id, t.reviewer_id, t.approval_stage, t.close_requested_at,
                   COALESCE(active_assignee_vacation.user_id, active_reviewer_vacation.user_id) AS substitute_for_user_id,
                   active_assignee_vacation.id AS substitute_for_assignee,
                   active_reviewer_vacation.id AS substitute_for_reviewer,
                   absent_user.name AS substitute_for_name,
                   p.code AS project_code, p.title AS project_title,
                   assignee.name AS assignee_name,
                   reviewer.name AS reviewer_name
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN users assignee ON assignee.id = t.assignee_id
            LEFT JOIN users reviewer ON reviewer.id = t.reviewer_id
            LEFT JOIN employee_vacations active_assignee_vacation
              ON active_assignee_vacation.user_id = t.assignee_id
             AND active_assignee_vacation.substitute_user_id = :substitute_assignee_user
             AND active_assignee_vacation.cancelled_at IS NULL
             AND :substitute_assignee_today BETWEEN active_assignee_vacation.date_from AND active_assignee_vacation.date_to
            LEFT JOIN employee_vacations active_reviewer_vacation
              ON active_reviewer_vacation.user_id = t.reviewer_id
             AND active_reviewer_vacation.substitute_user_id = :substitute_reviewer_user
             AND active_reviewer_vacation.cancelled_at IS NULL
             AND :substitute_reviewer_today BETWEEN active_reviewer_vacation.date_from AND active_reviewer_vacation.date_to
            LEFT JOIN users absent_user ON absent_user.id = COALESCE(active_assignee_vacation.user_id, active_reviewer_vacation.user_id)
            WHERE p.status = "active"
              AND COALESCE(p.kind, "project") IN ("project", "preproject")
              AND (t.closed_at IS NULL AND t.status != "done")
              AND (
                  t.assignee_id = :assignee_user
                  OR t.reviewer_id = :reviewer_user
                  OR active_assignee_vacation.id IS NOT NULL
                  OR active_reviewer_vacation.id IS NOT NULL
                  OR EXISTS (
                      SELECT 1 FROM task_participants tp_day
                      WHERE tp_day.task_id = t.id
                        AND tp_day.user_id = :participant_user
                        AND tp_day.role IN ("coauthor", "observer")
                  )' . $actionSql . '
              )
            ORDER BY t.date_end IS NULL, t.date_end, t.id
            LIMIT 160
        ');
        $stmt->execute([
            'assignee_user' => $userId,
            'reviewer_user' => $userId,
            'participant_user' => $userId,
            'substitute_assignee_user' => $userId,
            'substitute_assignee_today' => $today,
            'substitute_reviewer_user' => $userId,
            'substitute_reviewer_today' => $today,
        ] + $actionParams);

        $groups = ['waiting' => [], 'inWork' => [], 'review' => [], 'due' => []];
        foreach ($stmt->fetchAll() as $task) {
            $status = (string) ($task['status'] ?? '');
            $isAssignee = (int) ($task['assignee_id'] ?? 0) === $userId;
            $isSubstituteAssignee = (int) ($task['substitute_for_assignee'] ?? 0) > 0;
            $isSubstituteReviewer = (int) ($task['substitute_for_reviewer'] ?? 0) > 0;
            $handlesAssignee = $isAssignee || $isSubstituteAssignee;
            $isReviewer = (int) ($task['reviewer_id'] ?? 0) === $userId;
            $deadline = (string) ($task['date_end'] ?? '');
            $isDue = $deadline !== '' && $deadline <= $week;
            $isAction = in_array((int) $task['id'], $actionIds, true);

            if ($handlesAssignee && in_array($status, ['new', TaskWorkflowService::STATUS_CORRECTION], true) && (string) ($task['task_type'] ?? 'work') !== TaskWorkflowService::TASK_TYPE_REVIEW) {
                $groups['waiting'][] = $task;
            }
            if ($handlesAssignee && in_array($status, ['in_progress', 'overdue', TaskWorkflowService::STATUS_CORRECTION], true)) {
                $groups['inWork'][] = $task;
            }
            if ($isAction) {
                $groups['review'][] = $task;
            }
            if (($handlesAssignee || $isAction) && ($isDue || $status === 'overdue')) {
                $groups['due'][] = $task;
            }
        }

        foreach ($groups as &$rows) {
            $rows = array_slice($rows, 0, 24);
        }
        unset($rows);

        return $groups;
    }

    private function timeForUser(int $userId, string $dateFrom, string $dateTo): array
    {
        $stmt = $this->pdo->prepare('
            SELECT category, COALESCE(SUM(minutes), 0) AS minutes
            FROM time_entries
            WHERE user_id = ?
              AND work_date BETWEEN ? AND ?
            GROUP BY category
        ');
        $stmt->execute([$userId, $dateFrom, $dateTo]);

        $byCategory = [];
        $total = 0;
        foreach ($stmt->fetchAll() as $row) {
            $minutes = (int) ($row['minutes'] ?? 0);
            $byCategory[(string) ($row['category'] ?? '')] = $minutes;
            $total += $minutes;
        }

        return [
            'totalMinutes' => $total,
            'taskMinutes' => (int) ($byCategory['task'] ?? 0),
            'absenceMinutes' => array_sum(array_intersect_key($byCategory, array_flip(self::ABSENCE_CATEGORIES))),
            'byCategory' => $byCategory,
            'targetMinutes' => TimeService::targetMinutes($dateFrom),
        ];
    }

    private function teamLoad(array $user, string $dateFrom, string $dateTo, string $departmentFilter): array
    {
        if (!PermissionService::canOpenReports($user)) {
            return [];
        }

        $users = $this->teamUsers($user, $departmentFilter);
        if ($users === []) {
            return [];
        }

        $capacity = $this->capacityHours($dateFrom, $dateTo);
        $load = [];
        foreach ($users as $row) {
            $userId = (int) $row['id'];
            $load[$userId] = [
                'user_id' => $userId,
                'name' => (string) ($row['name'] ?? ''),
                'role' => (string) ($row['role'] ?? ''),
                'department' => (string) ($row['department'] ?? ''),
                'capacity_hours' => $capacity,
                'absence_hours' => 0.0,
                'available_hours' => $capacity,
                'planned_open_hours' => 0.0,
                'actual_hours' => 0.0,
                'remaining_hours' => 0.0,
                'open_tasks' => 0,
                'overdue_tasks' => 0,
                'due_week_tasks' => 0,
                'absence' => [],
                'load_percent' => 0,
                'load_status' => 'free',
                'load_label' => 'Есть резерв',
            ];
        }

        $this->applyAbsences($load, $dateFrom, $dateTo);
        $this->applyTaskLoad($load, $user, $dateFrom, $dateTo);

        foreach ($load as &$row) {
            $row['available_hours'] = max(0.0, (float) $row['capacity_hours'] - (float) $row['absence_hours']);
            $row['remaining_hours'] = max(0.0, (float) $row['planned_open_hours'] - (float) $row['actual_hours']);
            $row['load_percent'] = (float) $row['available_hours'] > 0 ? (int) round(((float) $row['remaining_hours'] / (float) $row['available_hours']) * 100) : 0;
            // Перегруз — только по часам. Просрочка больше не приравнивается к перегрузу
            // (она показывается отдельной меткой «проср. N» в колонке задач).
            if ((float) ($row['absence']['vacation'] ?? 0) > 0) {
                $row['load_status'] = 'vacation';
                $row['load_label'] = 'Отпуск';
            } elseif ((int) $row['load_percent'] > 110) {
                $row['load_status'] = 'overloaded';
                $row['load_label'] = 'Перегруз';
            } elseif ((int) $row['load_percent'] >= 75) {
                $row['load_status'] = 'busy';
                $row['load_label'] = 'Загружен';
            } elseif ((int) $row['load_percent'] >= 35) {
                $row['load_status'] = 'normal';
                $row['load_label'] = 'Нормально';
            } elseif ((int) $row['load_percent'] > 0 || (int) $row['open_tasks'] > 0) {
                // Есть задачи или часы, но загрузка низкая — резерв.
                $row['load_status'] = 'reserve';
                $row['load_label'] = 'Есть резерв';
            } else {
                // Ни запланированных часов, ни открытых задач.
                $row['load_status'] = 'free';
                $row['load_label'] = 'Свободен';
            }
        }
        unset($row);

        usort($load, static function (array $left, array $right): int {
            return ((int) $right['overdue_tasks'] <=> (int) $left['overdue_tasks'])
                ?: ((int) $right['load_percent'] <=> (int) $left['load_percent'])
                ?: strcmp((string) $left['name'], (string) $right['name']);
        });

        return array_values($load);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function teamUsers(array $user, string $departmentFilter): array
    {
        if (PermissionService::canSeeAllProjects($user)) {
            $params = [];
            $where = 'is_active = 1';
            if ($departmentFilter !== '') {
                $where .= ' AND department = :department';
                $params['department'] = $departmentFilter;
            }
            $stmt = $this->pdo->prepare('SELECT id, name, role, department FROM users WHERE ' . $where . ' ORDER BY department, name');
            $stmt->execute($params);
            return $stmt->fetchAll();
        }

        if (RoleService::isAny($user['role'] ?? null, [RoleService::DEPARTMENT_HEAD, RoleService::DEPUTY_DEPARTMENT_HEAD])) {
            $stmt = $this->pdo->prepare('SELECT id, name, role, department FROM users WHERE is_active = 1 AND department = ? ORDER BY name');
            $stmt->execute([(string) ($user['department'] ?? '')]);
            return $stmt->fetchAll();
        }

        $stmt = $this->pdo->prepare('SELECT id, name, role, department FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $user['id']]);

        return $stmt->fetchAll();
    }

    private function applyAbsences(array &$load, string $dateFrom, string $dateTo): void
    {
        if ($load === []) {
            return;
        }

        $userIds = array_keys($load);
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $categories = implode(',', array_fill(0, count(self::ABSENCE_CATEGORIES), '?'));
        $stmt = $this->pdo->prepare('
            SELECT user_id, work_date, category, COALESCE(SUM(minutes), 0) AS minutes
            FROM time_entries
            WHERE user_id IN (' . $placeholders . ')
              AND work_date BETWEEN ? AND ?
              AND category IN (' . $categories . ')
            GROUP BY user_id, work_date, category
        ');
        $stmt->execute([...$userIds, $dateFrom, $dateTo, ...self::ABSENCE_CATEGORIES]);
        $coveredDates = [];
        foreach ($stmt->fetchAll() as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            if (!isset($load[$userId])) {
                continue;
            }
            $category = (string) ($row['category'] ?? '');
            $hours = round(((int) ($row['minutes'] ?? 0)) / 60, 2);
            $load[$userId]['absence'][$category] = (float) ($load[$userId]['absence'][$category] ?? 0) + $hours;
            $load[$userId]['absence_hours'] += $hours;
            $coveredDates[$userId][(string) ($row['work_date'] ?? '')] = true;
        }

        try {
            $vacations = $this->pdo->prepare('
                SELECT user_id, date_from, date_to
                FROM employee_vacations
                WHERE user_id IN (' . $placeholders . ')
                  AND cancelled_at IS NULL
                  AND date_from <= ?
                  AND date_to >= ?
            ');
            $vacations->execute([...$userIds, $dateTo, $dateFrom]);
            foreach ($vacations->fetchAll() as $vacation) {
                $userId = (int) ($vacation['user_id'] ?? 0);
                if (!isset($load[$userId])) {
                    continue;
                }
                $cursor = new DateTimeImmutable(max($dateFrom, (string) ($vacation['date_from'] ?? $dateFrom)));
                $last = new DateTimeImmutable(min($dateTo, (string) ($vacation['date_to'] ?? $dateTo)));
                while ($cursor <= $last) {
                    $date = $cursor->format('Y-m-d');
                    $weekday = (int) $cursor->format('N');
                    if ($weekday <= 5 && empty($coveredDates[$userId][$date])) {
                        $hours = TimeService::targetMinutes($date) / 60;
                        $load[$userId]['absence']['vacation'] = (float) ($load[$userId]['absence']['vacation'] ?? 0) + $hours;
                        $load[$userId]['absence_hours'] += $hours;
                        $coveredDates[$userId][$date] = true;
                    }
                    $cursor = $cursor->modify('+1 day');
                }
            }
        } catch (\Throwable) {
            // During a staged update the application may briefly run before
            // migration 088 is applied. Logged absences remain available.
        }
    }

    private function applyTaskLoad(array &$load, array $user, string $dateFrom, string $dateTo): void
    {
        $taskWhere = 'p.status = "active" AND COALESCE(p.kind, "project") = "project" AND t.status != "done" AND t.closed_at IS NULL';
        $params = [];
        if (!PermissionService::canSeeAllProjects($user)) {
            if (RoleService::isAny($user['role'] ?? null, [RoleService::DEPARTMENT_HEAD, RoleService::DEPUTY_DEPARTMENT_HEAD])) {
                $taskWhere .= ' AND (
                    assignee.department = :department_assignee
                    OR reviewer.department = :department_reviewer
                    OR author.department = :department_author
                    OR p.gip_user_id = :project_gip
                    OR p.rp_user_id = :project_rp
                    OR EXISTS (
                        SELECT 1
                        FROM task_participants tp_scope
                        INNER JOIN users tp_user ON tp_user.id = tp_scope.user_id
                        WHERE tp_scope.task_id = t.id AND tp_user.department = :department_participant
                    )
                )';
                $params += [
                    'department_assignee' => (string) ($user['department'] ?? ''),
                    'department_reviewer' => (string) ($user['department'] ?? ''),
                    'department_author' => (string) ($user['department'] ?? ''),
                    'department_participant' => (string) ($user['department'] ?? ''),
                    'project_gip' => (int) $user['id'],
                    'project_rp' => (int) $user['id'],
                ];
            } else {
                $taskWhere .= ' AND (
                    t.assignee_id = :current_assignee
                    OR t.reviewer_id = :current_reviewer
                    OR EXISTS (
                        SELECT 1 FROM task_participants tp_current
                        WHERE tp_current.task_id = t.id AND tp_current.user_id = :current_participant
                    )
                )';
                $params += [
                    'current_assignee' => (int) $user['id'],
                    'current_reviewer' => (int) $user['id'],
                    'current_participant' => (int) $user['id'],
                ];
            }
        }

        $stmt = $this->pdo->prepare('
            SELECT t.id, t.assignee_id, t.reviewer_id, t.status, t.date_end,
                   COALESCE(t.planned_hours, 0) AS planned_hours,
                   COALESCE(t.actual_hours, 0) AS actual_hours
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN users assignee ON assignee.id = t.assignee_id
            LEFT JOIN users reviewer ON reviewer.id = t.reviewer_id
            LEFT JOIN users author ON author.id = t.author_id
            WHERE ' . $taskWhere . '
            LIMIT 5000
        ');
        $stmt->execute($params);
        $tasks = $stmt->fetchAll();
        $coauthors = $this->coauthorsByTask(array_map(static fn (array $task): int => (int) $task['id'], $tasks));
        foreach ($tasks as $task) {
            $recipients = [];
            foreach (['assignee_id', 'reviewer_id'] as $field) {
                $recipientId = (int) ($task[$field] ?? 0);
                if (isset($load[$recipientId])) {
                    $recipients[$recipientId] = true;
                }
            }
            foreach ($coauthors[(int) $task['id']] ?? [] as $coauthorId) {
                if (isset($load[$coauthorId])) {
                    $recipients[$coauthorId] = true;
                }
            }
            if ($recipients === []) {
                continue;
            }

            $deadline = (string) ($task['date_end'] ?? '');
            $isOverdue = (string) ($task['status'] ?? '') === 'overdue' || ($deadline !== '' && $deadline < date('Y-m-d'));
            $isDue = $deadline !== '' && $deadline >= $dateFrom && $deadline <= $dateTo;
            foreach (array_keys($recipients) as $recipientId) {
                $load[$recipientId]['open_tasks']++;
                $load[$recipientId]['planned_open_hours'] += (float) ($task['planned_hours'] ?? 0);
                $load[$recipientId]['actual_hours'] += (float) ($task['actual_hours'] ?? 0);
                if ($isOverdue) {
                    $load[$recipientId]['overdue_tasks']++;
                }
                if ($isDue) {
                    $load[$recipientId]['due_week_tasks']++;
                }
            }
        }
    }

    private function coauthorsByTask(array $taskIds): array
    {
        $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
        if ($taskIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = $this->pdo->prepare('SELECT task_id, user_id FROM task_participants WHERE role = "coauthor" AND task_id IN (' . $placeholders . ')');
        $stmt->execute($taskIds);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[(int) $row['task_id']][] = (int) $row['user_id'];
        }

        return $rows;
    }

    public function departmentsForUser(array $user): array
    {
        if (!PermissionService::canSeeAllProjects($user)) {
            $department = trim((string) ($user['department'] ?? ''));
            return $department !== '' ? [$department] : [];
        }

        return array_values(array_filter(array_map('strval', $this->pdo->query('SELECT DISTINCT department FROM users WHERE is_active = 1 AND COALESCE(department, "") != "" ORDER BY department')->fetchAll(PDO::FETCH_COLUMN))));
    }

    private function capacityHours(string $dateFrom, string $dateTo): float
    {
        $start = strtotime($dateFrom);
        $end = strtotime($dateTo);
        if ($start === false || $end === false || $end < $start) {
            return 0.0;
        }

        $days = 0;
        for ($time = $start; $time <= $end; $time = strtotime('+1 day', $time) ?: ($end + 1)) {
            if ((int) date('N', $time) <= 5) {
                $days++;
            }
        }

        return $days * (TimeService::DAILY_TARGET_MINUTES / 60);
    }

    private function dateOrDefault(mixed $value, string $default): string
    {
        $date = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : $default;
    }
}
