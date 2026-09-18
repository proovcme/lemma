<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PermissionService;
use App\Services\ProcessControlService;
use App\Services\RoleService;
use App\Services\CostEstimatePlanningService;
use App\Services\TaskWorkflowService;

final class DprController extends BaseController
{
    public function legacy(): void
    {
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        redirect('/shturman' . ($query !== '' ? '?' . $query : ''));
    }

    public function index(): void
    {
        $user = require_auth();
        if (!PermissionService::canOpenDpr($user)) {
            http_response_code(403);
            view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Штурман доступен руководящим ролям.']);
            return;
        }

        TaskWorkflowService::markOverdue();
        $filters = $this->filters($user);
        $projectId = $filters['project_id'];
        $metrics = $this->metrics($filters, $user);

        $this->render('dpr/index', [
            'title' => 'Штурман',
            'subtitle' => format_date(date('Y-m-d')) . ' · Полная картина · ' . $this->activeProjectCount($user) . ' проектов активно',
            'projects' => $this->projects($user),
            'users' => $this->activeUsers($user),
            'filters' => $filters,
            'selectedProjectId' => $projectId,
            'metrics' => $metrics,
            'overdueWithoutClose' => $this->overdueWithoutClose($filters, $user),
            'approvalRegistry' => $this->approvalRegistry($filters, $user),
            'dataRegistry' => $this->dataRegistry($filters, $user),
            'openIssues' => $this->openIssues($filters, $user),
            'exchangeOverdue' => $this->exchangeOverdue($filters, $user),
            'volumeIssuances' => $this->volumeIssuances($filters, $user),
            'upcomingSchedule' => $this->upcomingSchedule($filters, $user),
            'peopleDistribution' => $this->peopleDistribution($filters, $user),
            'workload' => $this->workload($filters, $user),
            'taskStatistics' => $this->taskStatistics($filters, $user),
            'processControl' => $this->processControl($filters, $user),
        ]);
    }

    private function projects(array $user): array
    {
        [$filter, $params] = $this->projectFilter('', $user);
        $stmt = $this->db()->prepare('SELECT id, code, title FROM projects p WHERE 1=1 ' . $filter . ' ORDER BY code');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function activeUsers(array $user): array
    {
        if (PermissionService::canSeeAllProjects($user)) {
            return $this->db()->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();
        }

        [$filter, $params] = $this->projectFilter('', $user);
        $params['current_user_id'] = (int) $user['id'];

        $stmt = $this->db()->prepare('
            SELECT DISTINCT u.id, u.name
            FROM users u
            WHERE u.is_active = 1
              AND (
                  u.id = :current_user_id
                  OR EXISTS (
                      SELECT 1
                      FROM tasks dpr_user_task
                      INNER JOIN projects p ON p.id = dpr_user_task.project_id
                      WHERE (
                          dpr_user_task.assignee_id = u.id
                          OR dpr_user_task.author_id = u.id
                          OR dpr_user_task.reviewer_id = u.id
                          OR EXISTS (
                              SELECT 1
                              FROM task_participants dpr_user_participant
                              WHERE dpr_user_participant.task_id = dpr_user_task.id
                                AND dpr_user_participant.user_id = u.id
                          )
                      ) ' . $filter . '
                  )
                  OR EXISTS (
                      SELECT 1
                      FROM project_issues dpr_user_issue
                      INNER JOIN projects p ON p.id = dpr_user_issue.project_id
                      WHERE dpr_user_issue.assignee_id = u.id ' . $filter . '
                  )
              )
            ORDER BY u.name
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function activeProjectCount(array $user): int
    {
        [$filter, $params] = $this->projectFilter('', $user);

        return $this->count('SELECT COUNT(*) FROM projects p WHERE 1=1 ' . $filter, $params);
    }

    private function filters(array $user): array
    {
        $dateFrom = $this->dateFilterValue($_GET['date_from'] ?? '');
        $dateTo = $this->dateFilterValue($_GET['date_to'] ?? '');
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [
            'project_id' => ($_GET['project_id'] ?? '') !== '' ? (string) (int) $_GET['project_id'] : '',
            'assignee_id' => ($_GET['assignee_id'] ?? '') !== '' ? (string) (int) $_GET['assignee_id'] : '',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    private function dateFilterValue(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private function projectFilter(array|string|int|null $filters, array $user, string $alias = 'p'): array
    {
        $projectId = is_array($filters) ? ($filters['project_id'] ?? '') : $filters;
        [$activeFilter, $params] = $this->projectScope($user, $alias);
        if ($projectId === '' || $projectId === null) {
            return [$activeFilter, $params];
        }

        $params['project_id'] = (int) $projectId;

        return [$activeFilter . " AND {$alias}.id = :project_id", $params];
    }

    private function projectScope(array $user, string $alias = 'p'): array
    {
        $sql = " AND {$alias}.status = \"active\"";
        if (PermissionService::canSeeAllProjects($user)) {
            return [$sql, []];
        }

        [$taskScope, $taskParams] = PermissionService::taskScopeWhere($user, 'dpr_scope_task');
        [$taskScope, $taskParams] = $this->prefixNamedParams($taskScope, $taskParams, 'dpr_scope_');

        $params = $taskParams + [
            'dpr_project_gip_user_id' => (int) $user['id'],
            'dpr_project_rp_user_id' => (int) $user['id'],
            'dpr_issue_user_id' => (int) $user['id'],
        ];
        $issueScope = 'dpr_scope_issue.assignee_id = :dpr_issue_user_id';
        $department = trim((string) ($user['department'] ?? ''));
        if ($department !== '') {
            $params['dpr_issue_department'] = $department;
            $issueScope .= ' OR dpr_scope_issue.assignee_id IN (SELECT id FROM users WHERE department = :dpr_issue_department)';
        }

        $sql .= " AND (
            {$alias}.gip_user_id = :dpr_project_gip_user_id
            OR {$alias}.rp_user_id = :dpr_project_rp_user_id
            OR {$alias}.id IN (
                SELECT DISTINCT dpr_scope_task.project_id
                FROM tasks dpr_scope_task
                WHERE {$taskScope}
            )
            OR {$alias}.id IN (
                SELECT DISTINCT dpr_scope_issue.project_id
                FROM project_issues dpr_scope_issue
                WHERE {$issueScope}
            )
        )";

        return [$sql, $params];
    }

    private function prefixNamedParams(string $sql, array $params, string $prefix): array
    {
        $prefixed = [];
        foreach ($params as $name => $value) {
            $prefixed[$prefix . $name] = $value;
        }

        $sql = preg_replace_callback(
            '/:([A-Za-z_][A-Za-z0-9_]*)/',
            static fn (array $match): string => ':' . $prefix . $match[1],
            $sql
        ) ?? $sql;

        return [$sql, $prefixed];
    }

    private function dashboardFilter(array $filters, array $user, string $projectAlias = 'p', ?string $dateColumn = null, ?string $assigneeColumn = null): array
    {
        [$sql, $params] = $this->projectFilter($filters, $user, $projectAlias);

        if ($assigneeColumn !== null && $filters['assignee_id'] !== '') {
            $sql .= " AND {$assigneeColumn} = :assignee_id";
            $params['assignee_id'] = (int) $filters['assignee_id'];
        }
        if ($dateColumn !== null && $filters['date_from'] !== '') {
            $sql .= " AND {$dateColumn} >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        if ($dateColumn !== null && $filters['date_to'] !== '') {
            $sql .= " AND {$dateColumn} <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        return [$sql, $params];
    }

    private function metrics(array $filters, array $user): array
    {
        [$projectFilter, $projectParams] = $this->projectFilter($filters, $user);
        [$taskFilter, $taskParams] = $this->dashboardFilter($filters, $user, 'p', 't.date_end', 't.assignee_id');
        [$dataFilter, $dataParams] = $this->dashboardFilter($filters, $user, 'p', 'd.date_received_plan');
        [$issueFilter, $issueParams] = $this->dashboardFilter($filters, $user, 'p', 'i.date_raised', 'i.assignee_id');

        return [
            'projects' => $this->count('
                SELECT COUNT(*)
                FROM projects p
                WHERE 1=1 ' . $projectFilter,
                $projectParams
            ),
            'overdue' => $this->count('
                SELECT COUNT(*)
                FROM tasks t
                INNER JOIN projects p ON p.id = t.project_id
                WHERE t.status = "overdue" ' . $taskFilter,
                $taskParams
            ),
            'approvals' => $this->count('
                SELECT COUNT(*)
                FROM tasks t
                INNER JOIN projects p ON p.id = t.project_id
                WHERE (t.approval_stage IN ("review_lead", "review_gip")
                   OR t.status IN ("review", "pending_close"))
                  AND t.status <> "done"
                  AND t.closed_at IS NULL
                  AND (t.status NOT IN ("review", "pending_close") OR t.close_requested_at IS NOT NULL)
                  AND COALESCE(t.task_type, "") NOT IN ("review", "delegation") ' . $taskFilter,
                $taskParams
            ),
            'waiting_data' => $this->count('
                SELECT COUNT(*)
                FROM project_data_registry d
                INNER JOIN projects p ON p.id = d.project_id
                WHERE d.status = "waiting" ' . $dataFilter,
                $dataParams
            ),
            'issues' => $this->count('
                SELECT COUNT(*)
                FROM project_issues i
                INNER JOIN projects p ON p.id = i.project_id
                WHERE i.status = "open" ' . $issueFilter,
                $issueParams
            ),
            'closed' => $this->count('
                SELECT COUNT(*)
                FROM tasks t
                INNER JOIN projects p ON p.id = t.project_id
                WHERE t.status = "done" ' . $taskFilter,
                $taskParams
            ),
        ];
    }

    private function processControl(array $filters, array $user): array
    {
        [$projectFilter, $projectParams] = $this->projectFilter($filters, $user);

        return (new ProcessControlService($this->db()))->dashboard($filters, $projectFilter, $projectParams);
    }

    private function count(string $sql, array $params = []): int
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function overdueWithoutClose(array $filters, array $user): array
    {
        [$filter, $params] = $this->dashboardFilter($filters, $user, 'p', 't.date_end', 't.assignee_id');
        $params['today'] = date('Y-m-d');

        $stmt = $this->db()->prepare('
            SELECT t.id, t.title, t.date_end, p.code AS project_code, u.name AS assignee_name
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN users u ON u.id = t.assignee_id
            WHERE t.date_end IS NOT NULL
              AND t.date_end < :today
              AND t.closed_at IS NULL
              AND t.status != "done" ' . $filter . '
            ORDER BY t.date_end ASC, p.code ASC, t.id ASC
            LIMIT 100
        ');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['overdue_days'] = $this->daysBetween($row['date_end'] ?? '', $params['today']);
        }
        unset($row);

        usort($rows, static fn (array $a, array $b): int => (int) $b['overdue_days'] <=> (int) $a['overdue_days']);

        return $rows;
    }

    private function approvalRegistry(array $filters, array $user): array
    {
        [$filter, $params] = $this->dashboardFilter($filters, $user, 'p', 't.date_end', 't.assignee_id');

        $stmt = $this->db()->prepare('
            SELECT t.id,
                   t.title,
                   t.task_type,
                   t.status,
                   t.approval_stage,
                   t.close_requested_at,
                   t.updated_at,
                   p.code AS project_code,
	                   assignee_user.name AS assignee_name,
	                   author_user.name AS author_name,
	                   reviewer_user.name AS lead_name,
	                   reviewer_user.role AS lead_role,
	                   gip.name AS gip_name,
                   MAX(CASE WHEN a.stage = "close_author" AND a.decision = "approved" AND (t.close_requested_at IS NULL OR a.created_at >= t.close_requested_at) THEN a.created_at ELSE NULL END) AS close_author_at,
                   MAX(a.created_at) AS last_approval_at,
                   MAX(l.created_at) AS stage_changed_at
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN users assignee_user ON assignee_user.id = t.assignee_id
            LEFT JOIN users author_user ON author_user.id = t.author_id
            LEFT JOIN users reviewer_user ON reviewer_user.id = t.reviewer_id
            LEFT JOIN users gip ON gip.id = p.gip_user_id
            LEFT JOIN task_approvals a ON a.task_id = t.id
            LEFT JOIN task_logs l ON l.task_id = t.id
                AND l.field = "approval_stage"
                AND l.new_val = t.approval_stage
            WHERE (t.approval_stage IN ("review_lead", "review_gip")
               OR t.status IN ("review", "pending_close"))
              AND t.status <> "done"
              AND t.closed_at IS NULL
              AND (t.status NOT IN ("review", "pending_close") OR t.close_requested_at IS NOT NULL)
              AND COALESCE(t.task_type, "") NOT IN ("review", "delegation") ' . $filter . '
	            GROUP BY t.id, t.title, t.task_type, t.status, t.approval_stage, t.close_requested_at, t.updated_at, p.code, assignee_user.name, author_user.name, reviewer_user.name, reviewer_user.role, gip.name
            LIMIT 120
        ');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $isCloseReview = in_array((string) ($row['status'] ?? ''), ['review', 'pending_close'], true)
                && !in_array((string) ($row['approval_stage'] ?? ''), ['review_lead', 'review_gip'], true);
            if ($isCloseReview) {
                $closeAuthorDone = trim((string) ($row['close_author_at'] ?? '')) !== '';
                $row['waiting_since'] = $closeAuthorDone ? (string) $row['close_author_at'] : (string) (($row['close_requested_at'] ?? '') ?: ($row['updated_at'] ?? ''));
                $row['wait_days'] = $this->daysBetween($row['waiting_since'] ?? '');
                $row['stage_label'] = $closeAuthorDone && (string) ($row['task_type'] ?? '') === 'issuance'
                    ? 'Приёмка ГИП'
                    : 'Приёмка постановщиком';
                $row['waiting_for'] = $closeAuthorDone && (string) ($row['task_type'] ?? '') === 'issuance'
                    ? (string) ($row['gip_name'] ?: 'ГИП не назначен')
                    : (string) ($row['author_name'] ?: 'Постановщик не указан');
                continue;
            }

            $since = $this->latestDate([$row['last_approval_at'] ?? '', $row['stage_changed_at'] ?? '']);
            $row['waiting_since'] = $since ?: ($row['updated_at'] ?? '');
            $row['wait_days'] = $this->daysBetween($row['waiting_since'] ?? '');
	            $row['stage_label'] = $row['approval_stage'] === 'review_gip'
	                ? 'ГИП'
	                : (RoleService::label((string) ($row['lead_role'] ?? '')) ?: 'Промежуточное согласование');
            $row['waiting_for'] = $row['approval_stage'] === 'review_gip'
                ? (string) ($row['gip_name'] ?: 'ГИП не назначен')
                : (string) ($row['lead_name'] ?: 'Согласующий не назначен');
        }
        unset($row);

        usort($rows, static fn (array $a, array $b): int => (int) $b['wait_days'] <=> (int) $a['wait_days']);

        return $rows;
    }

    private function dataRegistry(array $filters, array $user): array
    {
        [$filter, $params] = $this->dashboardFilter($filters, $user, 'p', 'd.date_received_plan');
        $today = date('Y-m-d');

        $stmt = $this->db()->prepare('
            SELECT d.*, p.id AS project_id, p.code AS project_code
            FROM project_data_registry d
            INNER JOIN projects p ON p.id = d.project_id
            WHERE d.status IN ("waiting", "overdue") ' . $filter . '
            ORDER BY d.date_received_plan IS NULL, d.date_received_plan ASC, p.code ASC, d.id ASC
            LIMIT 120
        ');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $ids = task_id_list($row['blocking_task_ids'] ?? '');
            $row['blocking_label'] = $ids
                ? '#' . implode(', #', $ids)
                : (string) (($row['impact'] ?? '') ?: '—');
            $row['is_overdue'] = (string) ($row['status'] ?? '') === 'overdue'
                || $this->isPastDate($row['date_received_plan'] ?? '', $today);
        }
        unset($row);

        return $rows;
    }

    private function openIssues(array $filters, array $user): array
    {
        [$filter, $params] = $this->dashboardFilter($filters, $user, 'p', 'i.date_raised', 'i.assignee_id');

        $stmt = $this->db()->prepare('
            SELECT i.*, p.id AS project_id, p.code AS project_code, u.name AS assignee_name
            FROM project_issues i
            INNER JOIN projects p ON p.id = i.project_id
            LEFT JOIN users u ON u.id = i.assignee_id
            WHERE i.status = "open" ' . $filter . '
            ORDER BY i.date_raised IS NULL, i.date_raised ASC, p.code ASC, i.id ASC
            LIMIT 120
        ');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['code_label'] = (string) (($row['section_code'] ?? '') ?: (!empty($row['num']) ? '№' . (int) $row['num'] : '—'));
            $row['open_days'] = $this->daysBetween($row['date_raised'] ?? '');
        }
        unset($row);

        usort($rows, static fn (array $a, array $b): int => (int) $b['open_days'] <=> (int) $a['open_days']);

        return $rows;
    }

    private function exchangeOverdue(array $filters, array $user): array
    {
        [$filter, $params] = $this->dashboardFilter($filters, $user, 'p', 'x.deadline', 'x.to_user_id');
        $params['today'] = date('Y-m-d');

        $stmt = $this->db()->prepare('
            SELECT x.*, p.id AS project_id, p.code AS project_code
            FROM project_task_exchange x
            INNER JOIN projects p ON p.id = x.project_id
            WHERE x.status != "done"
              AND x.deadline IS NOT NULL
              AND x.deadline < :today ' . $filter . '
            ORDER BY x.deadline ASC, p.code ASC, x.id ASC
            LIMIT 120
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function volumeIssuances(array $filters, array $user): array
    {
        [$filter, $params] = $this->dashboardFilter($filters, $user, 'p', 'i.issued_at', 't.assignee_id');

        $stmt = $this->db()->prepare('
            SELECT t.id AS task_id,
                   t.title,
                   t.volume,
                   t.section,
                   t.discipline,
                   p.code AS project_code,
                   i.id AS issuance_id,
                   i.issue_number,
                   i.issued_at,
                   i.status
            FROM task_issuances i
            INNER JOIN tasks t ON t.id = i.task_id
            INNER JOIN projects p ON p.id = t.project_id
            WHERE 1=1 ' . $filter . '
            ORDER BY p.code ASC, t.id ASC, i.issue_number ASC, i.issued_at ASC, i.id ASC
            LIMIT 1000
        ');
        $stmt->execute($params);

        $groups = [];
        foreach ($stmt->fetchAll() as $row) {
            $taskId = (int) $row['task_id'];
            if (!isset($groups[$taskId])) {
                $volume = trim((string) (($row['volume'] ?? '') ?: ($row['section'] ?? '') ?: ($row['discipline'] ?? '')));
                $groups[$taskId] = [
                    'task_id' => $taskId,
                    'project_code' => $row['project_code'],
                    'volume_label' => $volume !== '' ? $volume : 'Задача #' . $taskId,
                    'issue_count' => 0,
                    'last_issue_number' => 0,
                    'last_issued_at' => '',
                    'last_status' => '',
                ];
            }

            $groups[$taskId]['issue_count']++;
            $groups[$taskId]['last_issue_number'] = (int) $row['issue_number'];
            $groups[$taskId]['last_issued_at'] = $row['issued_at'];
            $groups[$taskId]['last_status'] = $row['status'];
        }

        $rows = array_values($groups);
        usort($rows, static function (array $a, array $b): int {
            $projectCompare = strcmp((string) $a['project_code'], (string) $b['project_code']);
            if ($projectCompare !== 0) {
                return $projectCompare;
            }

            return strcmp((string) $a['volume_label'], (string) $b['volume_label']);
        });

        return array_slice($rows, 0, 120);
    }

    private function upcomingSchedule(array $filters, array $user): array
    {
        [$filter, $params] = $this->projectFilter($filters, $user);
        $params['today'] = $filters['date_from'] !== '' ? $filters['date_from'] : date('Y-m-d');
        $params['horizon'] = $filters['date_to'] !== '' ? $filters['date_to'] : date('Y-m-d', strtotime('+14 days'));
        if ($filters['assignee_id'] !== '') {
            $filter .= ' AND s.assignee_id = :assignee_id';
            $params['assignee_id'] = (int) $filters['assignee_id'];
        }
        $notIssuedFilter = $this->db()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'AND COALESCE(s.date_issued, "") = ""'
            : 'AND s.date_issued IS NULL';

        $stmt = $this->db()->prepare('
            SELECT s.*,
                   p.id AS project_id,
                   p.code AS project_code,
                   u.name AS assignee_name
            FROM project_schedule s
            INNER JOIN projects p ON p.id = s.project_id
            LEFT JOIN users u ON u.id = s.assignee_id
            WHERE s.rd_date_plan BETWEEN :today AND :horizon
              ' . $notIssuedFilter . ' ' . $filter . '
            ORDER BY s.rd_date_plan ASC, p.code ASC, s.id ASC
            LIMIT 120
        ');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['volume_label'] = (string) (($row['volume'] ?? '') ?: ($row['section'] ?? '') ?: ($row['object'] ?? '') ?: '—');
            $row['days_left'] = $this->daysBetween($params['today'], $row['rd_date_plan'] ?? $params['today']);
        }
        unset($row);

        return $rows;
    }

    private function workload(array $filters, array $user): array
    {
        [$filter, $params] = $this->dashboardFilter($filters, $user, 'p', 't.date_end', 't.assignee_id');
        $params['today'] = date('Y-m-d');

        $stmt = $this->db()->prepare('
            SELECT u.id,
                   u.name,
                   COUNT(t.id) AS open_tasks,
                   SUM(CASE
                       WHEN t.status = "overdue"
                         OR (t.date_end IS NOT NULL AND t.date_end < :today AND t.closed_at IS NULL AND t.status != "done")
                       THEN 1 ELSE 0
                   END) AS overdue_tasks
            FROM users u
            INNER JOIN tasks t ON t.assignee_id = u.id
                AND t.closed_at IS NULL
                AND t.status != "done"
            INNER JOIN projects p ON p.id = t.project_id
            WHERE u.is_active = 1 ' . $filter . '
            GROUP BY u.id, u.name
            ORDER BY overdue_tasks DESC, open_tasks DESC, u.name ASC
            LIMIT 100
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function taskStatistics(array $filters, array $user): array
    {
        [$filter, $params] = $this->dashboardFilter($filters, $user, 'p', 'COALESCE(t.closed_at, t.date_end)', 't.assignee_id');
        $stmt = $this->db()->prepare('
            SELECT t.id, t.task_type, t.discipline, t.planned_hours, t.actual_hours,
                   t.date_start, t.date_end, t.closed_at, t.updated_at
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE (t.status = "done" OR t.closed_at IS NOT NULL)
              AND COALESCE(t.task_type, "work") NOT IN ("review", "delegation")
              AND NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id)
              ' . $filter . '
            ORDER BY COALESCE(t.closed_at, t.date_end, t.updated_at) DESC, t.id DESC
            LIMIT 5000
        ');
        $stmt->execute($params);

        return (new CostEstimatePlanningService())->taskStatisticsFromRows($stmt->fetchAll());
    }

    private function peopleDistribution(array $filters, array $user): array
    {
        [$filter, $params] = $this->projectFilter($filters, $user);
        $params['today'] = date('Y-m-d');
        if ($filters['assignee_id'] !== '') {
            $filter .= ' AND actor.user_id = :people_assignee_id';
            $params['people_assignee_id'] = (int) $filters['assignee_id'];
        }
        if ($filters['date_from'] !== '') {
            $filter .= ' AND t.date_end >= :people_date_from';
            $params['people_date_from'] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $filter .= ' AND t.date_end <= :people_date_to';
            $params['people_date_to'] = $filters['date_to'];
        }

        $stmt = $this->db()->prepare('
            SELECT u.id AS user_id,
                   u.name AS user_name,
                   u.department,
                   p.id AS project_id,
                   p.code AS project_code,
                   p.title AS project_title,
                   p.object AS project_object,
                   COUNT(DISTINCT t.id) AS active_tasks,
                   SUM(CASE
                       WHEN t.status = "overdue"
                         OR (t.date_end IS NOT NULL AND t.date_end < :today AND t.closed_at IS NULL AND t.status != "done")
                       THEN 1 ELSE 0
                   END) AS overdue_tasks,
                   SUM(CASE WHEN t.planned_hours IS NULL OR t.planned_hours <= 0 THEN 1 ELSE 0 END) AS unplanned_tasks,
                   MIN(t.date_end) AS nearest_deadline,
                   MAX(t.date_end) AS latest_deadline,
                   SUM(COALESCE(t.planned_hours, 0) / COALESCE(NULLIF(actor_count.actor_count, 0), 1)) AS planned_hours,
                   SUM(COALESCE(actor_time.actual_hours, 0)) AS actual_hours,
                   SUM(CASE
                       WHEN (COALESCE(t.planned_hours, 0) / COALESCE(NULLIF(actor_count.actor_count, 0), 1)) > COALESCE(actor_time.actual_hours, 0)
                       THEN (COALESCE(t.planned_hours, 0) / COALESCE(NULLIF(actor_count.actor_count, 0), 1)) - COALESCE(actor_time.actual_hours, 0)
                       ELSE 0
                   END) AS remaining_hours
            FROM tasks t
            INNER JOIN (
                SELECT id AS task_id, assignee_id AS user_id
                FROM tasks
                WHERE assignee_id IS NOT NULL
                UNION
                SELECT task_id, user_id
                FROM task_participants
                WHERE role IN ("assignee", "coauthor")
            ) actor ON actor.task_id = t.id
            LEFT JOIN (
                SELECT task_id, COUNT(*) AS actor_count
                FROM (
                    SELECT id AS task_id, assignee_id AS user_id
                    FROM tasks
                    WHERE assignee_id IS NOT NULL
                    UNION
                    SELECT task_id, user_id
                    FROM task_participants
                    WHERE role IN ("assignee", "coauthor")
                ) actor_count_source
                GROUP BY task_id
            ) actor_count ON actor_count.task_id = t.id
            LEFT JOIN (
                SELECT task_id, user_id, SUM(minutes) / 60.0 AS actual_hours
                FROM time_entries
                GROUP BY task_id, user_id
            ) actor_time ON actor_time.task_id = t.id AND actor_time.user_id = actor.user_id
            INNER JOIN users u ON u.id = actor.user_id
            INNER JOIN projects p ON p.id = t.project_id
            WHERE u.is_active = 1
              AND t.closed_at IS NULL
              AND t.status != "done" ' . $filter . '
            GROUP BY u.id, u.name, u.department, p.id, p.code, p.title, p.object
            ORDER BY u.name ASC,
                     CASE WHEN MIN(t.date_end) IS NULL THEN 1 ELSE 0 END,
                     MIN(t.date_end) ASC,
                     p.code ASC
            LIMIT 160
        ');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['days_to_nearest'] = trim((string) ($row['nearest_deadline'] ?? '')) !== ''
                ? $this->daysBetween($params['today'], (string) $row['nearest_deadline'])
                : null;
        }
        unset($row);

        return $rows;
    }

    private function daysBetween(?string $from, ?string $to = null): int
    {
        $from = trim((string) $from);
        if ($from === '') {
            return 0;
        }

        $fromTs = strtotime(substr($from, 0, 10));
        $toTs = strtotime(substr((string) ($to ?: date('Y-m-d')), 0, 10));
        if ($fromTs === false || $toTs === false) {
            return 0;
        }

        return max(0, (int) floor(($toTs - $fromTs) / 86400));
    }

    private function isPastDate(?string $date, ?string $today = null): bool
    {
        $date = trim((string) $date);
        if ($date === '') {
            return false;
        }

        return substr($date, 0, 10) < ($today ?: date('Y-m-d'));
    }

    private function latestDate(array $dates): string
    {
        $latest = '';
        $latestTs = 0;
        foreach ($dates as $date) {
            $date = trim((string) $date);
            if ($date === '') {
                continue;
            }
            $ts = strtotime($date);
            if ($ts !== false && $ts >= $latestTs) {
                $latest = $date;
                $latestTs = $ts;
            }
        }

        return $latest;
    }
}
