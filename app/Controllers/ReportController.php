<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\PermissionService;
use App\Services\PerformanceReviewService;
use App\Services\MonthlyWorkLedgerPreferenceService;
use App\Services\MonthlyWorkLedgerService;
use App\Services\RoleService;
use App\Services\RevitIntegrationService;
use App\Services\StaffingService;
use App\Services\TagService;
use App\Services\TimeService;

final class ReportController extends BaseController
{
    private const BASE_FIELDS = [
        'id' => 'ID',
        'title' => 'Название',
        'task_type' => 'Тип',
        'project' => 'Проект',
        'parent_id' => 'Родительская задача',
        'status' => 'Статус',
        'priority' => 'Важность',
        'urgency' => 'Срочность',
        'discipline' => 'Дисциплина',
        'assignee' => 'Исполнитель',
        'reviewer' => 'Проверяющий',
        'date_start' => 'Дата начала',
        'date_end' => 'Срок',
        'close_requested_at' => 'Отправлено на проверку',
        'progress' => 'Прогресс',
        'pp' => 'ПП',
        'btp' => 'БТП',
        'planned_hours' => 'План, ч',
        'actual_hours' => 'Факт, ч',
        'tags' => 'Теги',
    ];

    private const PROJECT_REPORT_FIELDS = [
        'id',
        'project',
        'title',
        'status',
        'discipline',
        'assignee',
        'reviewer',
        'date_start',
        'date_end',
        'progress',
        'planned_hours',
        'actual_hours',
        'pp',
        'btp',
        'tags',
    ];

    public function index(): void
    {
        $user = require_auth();
        $this->ensureReportsAccess($user);
        $input = $this->applyReportTemplate($this->reportInput());
        $rows = request_method() === 'POST' ? $this->rows($user, $input) : [];
        $projects = $this->projects($user);
        $timeReport = $this->timeReport($user, $input);

        $this->render('reports/index', [
            'title' => 'Отчёты',
            'projects' => $projects,
            'reportUsers' => $this->reportUsers($user),
            'fields' => self::BASE_FIELDS + $this->customFieldLabels(),
            'rows' => $rows,
            'selectedFields' => $input['fields'] ?? ['id', 'title', 'project', 'status', 'assignee', 'date_end', 'progress'],
            'filters' => $input,
            'analytics' => $this->analytics($user, $input, $projects),
            'timeReport' => $timeReport,
            'timeCategories' => TimeService::CATEGORIES,
            'timePhases' => TimeService::PHASES,
        ]);
    }

    public function monthly(): void
    {
        $user = require_auth();
        $this->ensureReportsAccess($user);
        $preferences = new MonthlyWorkLedgerPreferenceService($this->db());
        $saved = $preferences->load((int) $user['id']);
        $input = array_replace($saved['filters'], $_GET);
        if (!isset($_GET['group_by']) || $_GET['group_by'] === '') {
            $input['group_by'] = $saved['default_group_by'];
        }
        $report = (new MonthlyWorkLedgerService($this->db()))->report($user, $input);

        $this->render('reports/monthly', [
            'title' => 'Месячный реестр',
            'catalog' => MonthlyWorkLedgerPreferenceService::FIELD_CATALOG,
            'preferences' => $saved,
            'report' => $report,
            'filters' => $input,
        ]);
    }

    public function saveMonthlySettings(): void
    {
        $user = require_auth();
        $this->ensureReportsAccess($user);
        (new MonthlyWorkLedgerPreferenceService($this->db()))->save((int) $user['id'], [
            'visible_fields' => $_POST['visible_fields'] ?? [],
            'field_order' => $_POST['field_order'] ?? ($_POST['visible_fields'] ?? []),
            'default_group_by' => $_POST['default_group_by'] ?? '',
            'filters' => $_POST['filters'] ?? [],
        ]);
        flash('success', 'Настройки месячного реестра сохранены только для вас.');
        redirect('/reports/monthly');
    }

    public function exportMonthly(): void
    {
        $user = require_auth();
        $this->ensureReportsAccess($user);
        $preferences = (new MonthlyWorkLedgerPreferenceService($this->db()))->load((int) $user['id']);
        $input = array_replace($preferences['filters'], $_POST);
        if (!isset($_POST['group_by']) || $_POST['group_by'] === '') {
            $input['group_by'] = $preferences['default_group_by'];
        }
        $report = (new MonthlyWorkLedgerService($this->db()))->report($user, $input);
        $fields = $preferences['field_order'];
        $format = (string) ($_POST['format'] ?? 'csv');
        if ($format === 'xlsx') {
            $this->exportMonthlyXlsx($report, $fields);
        }
        $this->exportMonthlyCsv($report, $fields);
    }

    public function people(): void
    {
        $user = require_auth();
        $this->ensureReportsAccess($user);
        $input = $_GET;
        $employeeSearch = trim((string) ($input['employee_search'] ?? ''));
        $reportUsers = $this->reportUsers($user, $employeeSearch);
        $selectedUser = $this->selectedReportUser($reportUsers, (int) ($input['user_id'] ?? 0));
        $filters = array_replace($input, $this->reportPeriod($input));
        $filters['_task_date_filter'] = $this->hasExplicitPeriod($input) && (string) ($filters['period'] ?? '') !== 'all';

        $this->render('reports/people', [
            'title' => 'Отчёт по исполнителю',
            'subtitle' => 'Команда, задачи, списания времени и подтверждение руководителем',
            'projects' => $this->projects($user),
            'reportUsers' => $reportUsers,
            'filters' => $filters,
            'selectedUser' => $selectedUser,
            'employeeSearch' => $employeeSearch,
            'teamLoad' => $employeeSearch !== '' ? $this->peopleTeamLoad($user, $reportUsers, $filters) : [],
            'model' => $selectedUser ? $this->peopleModel($user, (int) $selectedUser['id'], $filters) : null,
            'canApproveTime' => $selectedUser ? $this->canApprovePeopleTime($user, (int) $selectedUser['id']) : false,
            'timeCategories' => TimeService::CATEGORIES,
            'timePhases' => TimeService::PHASES,
        ]);
    }

    public function profile(?int $id = null): void
    {
        $user = require_auth();
        $input = $_GET;
        $selectedUserId = $id !== null && $id > 0 ? $id : (int) ($user['id'] ?? 0);
        $selectedUser = $this->profileUser($user, $selectedUserId);
        if ($selectedUser === null) {
            http_response_code(403);
            $this->render('layouts/error', [
                'title' => 'Нет доступа',
                'message' => 'Профиль сотрудника не входит в доступную вам оргструктуру.',
            ]);
            return;
        }

        $employeeSearch = trim((string) ($input['employee_search'] ?? ''));
        $reportUsers = $this->reportUsers($user, $employeeSearch);
        $filters = array_replace($input, $this->reportPeriod($input));
        $filters['_task_date_filter'] = $this->hasExplicitPeriod($input) && (string) ($filters['period'] ?? '') !== 'all';
        $isOwnProfile = $selectedUserId === (int) ($user['id'] ?? 0);
        $canBrowseProfiles = PermissionService::canBrowseEmployeeProfiles($user);
        $performanceReviewService = new PerformanceReviewService($this->db());
        $profileReviews = $performanceReviewService->profileReviews($selectedUserId, $user);
        $managerReviews = $isOwnProfile ? $performanceReviewService->managerReviewsForUser($user) : [];
        $profileReviewMode = $isOwnProfile
            ? 'self'
            : (PermissionService::canManagePerformanceReviews($user) ? 'hr' : 'manager');

        $this->render('reports/people', [
            'title' => $isOwnProfile ? 'Мой профиль' : 'Профиль сотрудника',
            'subtitle' => $isOwnProfile
                ? 'Личные задачи, сроки, план-факт и трудозатраты'
                : 'Задачи, сроки, план-факт и трудозатраты сотрудника',
            'projects' => $this->projects($user),
            'reportUsers' => $reportUsers,
            'filters' => $filters,
            'selectedUser' => $selectedUser,
            'employeeSearch' => $employeeSearch,
            'teamLoad' => $employeeSearch !== '' && $canBrowseProfiles
                ? $this->peopleTeamLoad($user, $reportUsers, $filters)
                : [],
            'model' => $this->peopleModel($user, $selectedUserId, $filters),
            'canApproveTime' => false,
            'timeCategories' => TimeService::CATEGORIES,
            'timePhases' => TimeService::PHASES,
            'profileMode' => true,
            'isOwnProfile' => $isOwnProfile,
            'canBrowseProfiles' => $canBrowseProfiles,
            'canOpenReports' => PermissionService::canOpenReports($user),
            'profileReviews' => $profileReviews,
            'profileReviewMode' => $profileReviewMode,
            'managerQueue' => [
                'total' => count($managerReviews),
                'ready' => count(array_filter($managerReviews, static fn (array $review): bool => in_array((string) ($review['manager_state'] ?? ''), ['ready', 'meeting_ready'], true))),
            ],
            'revitTokens' => $isOwnProfile ? (new RevitIntegrationService($this->db()))->tokensForUser($selectedUserId) : [],
            'revitActivationCode' => $isOwnProfile ? ($_SESSION['revit_activation_code'] ?? '') : '',
            'revitActivationExpiresAt' => $isOwnProfile ? (int) ($_SESSION['revit_activation_expires_at'] ?? 0) : 0,
        ]);
    }

    public function approvePeopleTime(): void
    {
        $user = require_auth();
        $this->ensureReportsAccess($user);
        $input = array_replace($_POST, $this->reportPeriod($_POST));
        $selectedUserId = (int) ($input['user_id'] ?? 0);

        if (!$this->canApprovePeopleTime($user, $selectedUserId)) {
            flash('error', 'Нет доступа к подтверждению времени этого исполнителя.');
            redirect('/reports/people?' . http_build_query($this->peopleRedirectFilters($input)));
        }

        $updated = $this->approvePeopleTimeRows($user, $input);
        flash(
            $updated > 0 ? 'success' : 'error',
            $updated > 0 ? 'Время подтверждено: ' . $updated . ' строк.' : 'Нет открытых строк времени для подтверждения.'
        );
        redirect('/reports/people?' . http_build_query($this->peopleRedirectFilters($input)));
    }

    public function export(): void
    {
        $user = require_auth();
        $this->ensureReportsAccess($user);
        $input = $this->applyReportTemplate($this->normalizeExportInput($_POST));

        if (($input['report_type'] ?? '') === 'time') {
            $this->exportTimeReport($user, $input);
        }
        if (($input['report_type'] ?? '') === 'db') {
            $this->exportDbReport($user, $input);
        }

        $rows = $this->rows($user, $input);
        $fields = (array) ($input['fields'] ?? array_keys(self::BASE_FIELDS));
        $format = $input['format'] ?? 'csv';

        if ($format === 'xlsx') {
            $this->exportXlsx($rows, $fields);
        }

        $this->exportCsv($rows, $fields);
    }

    private function normalizeExportInput(array $input): array
    {
        $action = (string) ($input['report_action'] ?? '');
        if ($action === '') {
            return $input;
        }

        [$reportType, $format] = match ($action) {
            'time_xlsx' => ['time', 'xlsx'],
            'time_csv' => ['time', 'csv'],
            'db_xlsx' => ['db', 'xlsx'],
            'db_csv' => ['db', 'csv'],
            'project_xlsx' => ['', 'xlsx'],
            'project_csv' => ['', 'csv'],
            'tasks_xlsx' => ['', 'xlsx'],
            'tasks_csv' => ['', 'csv'],
            default => [(string) ($input['report_type'] ?? ''), (string) ($input['format'] ?? 'csv')],
        };

        $input['report_type'] = $reportType;
        $input['format'] = $format;
        if (str_starts_with($action, 'project_')) {
            $input['report_template'] = 'project';
        }

        return $input;
    }

    private function applyReportTemplate(array $input): array
    {
        if (($input['report_template'] ?? '') !== 'project') {
            return $input;
        }

        $input['fields'] = self::PROJECT_REPORT_FIELDS;
        $input['group_by'] = 'discipline';

        return $input;
    }

    private function ensureReportsAccess(array $user): void
    {
        if (PermissionService::canOpenReports($user)) {
            return;
        }

        http_response_code(403);
        view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Отчёты доступны только руководящим ролям.']);
        exit;
    }

    private function rows(array $user, array $input): array
    {
        [$where, $params] = $this->taskWhere($user, $input);

        $stmt = $this->db()->prepare('
            SELECT t.id, t.title, t.task_type, p.code AS project, t.parent_id, t.status, t.priority, t.urgency, t.discipline,
                   u.name AS assignee, reviewer.name AS reviewer, t.date_start, t.date_end, t.close_requested_at,
                   t.progress, t.btp AS btp_legacy, t.planned_hours, t.actual_hours,
                   pp.code AS pp_code, pp.title AS pp_title, btp.code AS btp_code, btp.title AS btp_title
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN users u ON u.id = t.assignee_id
            LEFT JOIN users reviewer ON reviewer.id = t.reviewer_id
            LEFT JOIN project_btp_codes btp ON btp.id = t.btp_code_id
            LEFT JOIN project_pp_codes pp ON pp.id = COALESCE(t.pp_code_id, btp.pp_code_id)
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY p.code, t.date_end IS NULL, t.date_end, t.id
            LIMIT 1000
        ');
        $stmt->execute($params);
        $rows = TagService::attachToTasks($stmt->fetchAll());
        foreach ($rows as &$row) {
            $row['task_type'] = task_type_label($row['task_type'] ?? 'work');
            $row['tags'] = $row['tag_names'] ?? '';
            $row['pp'] = $this->accountingLabel($row['pp_code'] ?? '', $row['pp_title'] ?? '');
            $row['btp'] = $this->accountingLabel($row['btp_code'] ?? '', $row['btp_title'] ?? '', $row['btp_legacy'] ?? '');
        }
        unset($row);

        $customFields = $this->customFieldLabels();
        if ($customFields && $rows) {
            $ids = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db()->prepare("
                SELECT cv.task_id, cf.name, cv.value
                FROM custom_values cv
                INNER JOIN custom_fields cf ON cf.id = cv.field_id
                WHERE cv.task_id IN ({$placeholders})
            ");
            $stmt->execute($ids);
            $values = [];
            foreach ($stmt->fetchAll() as $value) {
                $values[(int) $value['task_id']]['custom_' . $value['name']] = $value['value'];
            }
            foreach ($rows as &$row) {
                foreach ($customFields as $key => $label) {
                    $row[$key] = $values[(int) $row['id']][$key] ?? '';
                }
            }
            unset($row);
        }

        return $rows;
    }

    private function reportInput(): array
    {
        return request_method() === 'POST' ? $_POST : $_GET;
    }

    private function analytics(array $user, array $input, array $projects): array
    {
        return [
            'metrics' => $this->analyticsMetrics($user, $input),
            'byProject' => $this->analyticsByProject($user, $input),
            'byStatus' => $this->analyticsGroupedTasks($user, $input, 'status'),
            'byDiscipline' => $this->analyticsGroupedTasks($user, $input, 'discipline'),
            'byPp' => $this->analyticsGroupedAccounting($user, $input, 'pp'),
            'byBtp' => $this->analyticsGroupedAccounting($user, $input, 'btp'),
            'workload' => $this->analyticsWorkload($user, $input),
            'risks' => $this->analyticsRisks($user, $input),
            'visibleProjectCount' => count($projects),
        ];
    }

    private function analyticsMetrics(array $user, array $input): array
    {
        [$taskWhere, $taskParams] = $this->taskWhere($user, $input);
        $taskParams['today'] = date('Y-m-d');
        $taskParams['next_week'] = date('Y-m-d', strtotime('+7 days'));

        $stmt = $this->db()->prepare('
            SELECT COUNT(*) AS total_tasks,
                   SUM(CASE WHEN t.status = "done" OR t.closed_at IS NOT NULL THEN 1 ELSE 0 END) AS done_tasks,
                   SUM(CASE WHEN t.status != "done" AND t.closed_at IS NULL THEN 1 ELSE 0 END) AS open_tasks,
                   SUM(CASE
                       WHEN t.status = "overdue"
                         OR (t.date_end IS NOT NULL AND t.date_end < :today AND t.status != "done" AND t.closed_at IS NULL)
                       THEN 1 ELSE 0
                   END) AS overdue_tasks,
                   SUM(CASE
                       WHEN t.date_end IS NOT NULL
                         AND t.date_end >= :today
                         AND t.date_end <= :next_week
                         AND t.status != "done"
                         AND t.closed_at IS NULL
                       THEN 1 ELSE 0
                   END) AS due_week_tasks,
                   SUM(CASE WHEN t.task_type = "review" AND t.status != "done" THEN 1 ELSE 0 END) AS review_cycle_tasks,
                   SUM(CASE WHEN t.status = "correction" THEN 1 ELSE 0 END) AS correction_tasks,
                   COALESCE(ROUND(AVG(t.progress), 0), 0) AS avg_progress,
                   COALESCE(ROUND(SUM(CASE
                       WHEN NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id) THEN COALESCE(t.planned_hours, 0)
                       ELSE 0
                   END), 2), 0) AS planned_hours,
                   COALESCE(ROUND(SUM(CASE
                       WHEN NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id) THEN COALESCE(t.actual_hours, 0)
                       ELSE 0
                   END), 2), 0) AS actual_hours
            FROM tasks t
            WHERE ' . implode(' AND ', $taskWhere) . '
        ');
        $stmt->execute($taskParams);
        $tasks = $stmt->fetch() ?: [];

        $cost = $this->singleProjectMetric($user, $input, 'project_cost_plan', 'c', [
            'cost_items' => 'COUNT(*)',
            'planned_cost_total' => 'COALESCE(ROUND(SUM(c.planned_cost), 2), 0)',
            'planned_labor_hours_total' => 'COALESCE(ROUND(SUM(c.labor_hours), 2), 0)',
            'labor_pending_director' => 'COALESCE(SUM(CASE WHEN c.labor_approval_status IN ("pending_director", "rejected") THEN 1 ELSE 0 END), 0)',
        ]);
        $issues = $this->singleProjectMetric($user, $input, 'project_issues', 'i', [
            'open_issues' => 'SUM(CASE WHEN i.status != "done" THEN 1 ELSE 0 END)',
            'done_issues' => 'SUM(CASE WHEN i.status = "done" THEN 1 ELSE 0 END)',
        ], 'i.date_raised', 'issues');
        $exchange = $this->singleProjectMetric($user, $input, 'project_task_exchange', 'e', [
            'blocked_exchange' => 'SUM(CASE WHEN e.status = "blocked" THEN 1 ELSE 0 END)',
            'open_exchange' => 'SUM(CASE WHEN e.status != "done" THEN 1 ELSE 0 END)',
            'overdue_exchange' => 'SUM(CASE WHEN e.deadline IS NOT NULL AND e.deadline < :exchange_today AND e.status != "done" THEN 1 ELSE 0 END)',
        ], 'e.deadline', 'exchange', ['exchange_today' => date('Y-m-d')]);
        $data = $this->singleProjectMetric($user, $input, 'project_data_registry', 'd', [
            'waiting_data' => 'SUM(CASE WHEN d.status = "waiting" THEN 1 ELSE 0 END)',
            'overdue_data' => 'SUM(CASE WHEN d.status = "waiting" AND d.date_received_plan IS NOT NULL AND d.date_received_plan < :data_today THEN 1 ELSE 0 END)',
        ], 'd.date_received_plan', 'data', ['data_today' => date('Y-m-d')]);
        $schedule = $this->singleProjectMetric($user, $input, 'project_schedule', 's', [
            'schedule_rows' => 'COUNT(*)',
            'schedule_overdue' => 'SUM(CASE
                WHEN s.rd_date_plan IS NOT NULL
                 AND s.rd_date_plan < :schedule_today
                 AND COALESCE(s.date_issued, "") = ""
                 AND COALESCE(s.issue_status, "") NOT IN ("Выдано", "Выдана", "Принята")
                THEN 1 ELSE 0 END)',
            'schedule_next' => 'SUM(CASE
                WHEN s.rd_date_plan IS NOT NULL
                 AND s.rd_date_plan >= :schedule_today
                 AND s.rd_date_plan <= :schedule_next
                 AND COALESCE(s.date_issued, "") = ""
                THEN 1 ELSE 0 END)',
        ], 's.rd_date_plan', 'schedule', [
            'schedule_today' => date('Y-m-d'),
            'schedule_next' => date('Y-m-d', strtotime('+14 days')),
        ]);

        return array_map(static fn (mixed $value): float|int => is_numeric($value) ? (float) $value : 0, $tasks + $cost + $issues + $exchange + $data + $schedule);
    }

    private function analyticsByProject(array $user, array $input): array
    {
        [$where, $params] = $this->taskWhere($user, $input);
        $params['today'] = date('Y-m-d');

        $stmt = $this->db()->prepare('
            SELECT p.id,
                   p.code,
                   p.title,
                   COUNT(t.id) AS total_tasks,
                   SUM(CASE WHEN t.status != "done" AND t.closed_at IS NULL THEN 1 ELSE 0 END) AS open_tasks,
                   SUM(CASE WHEN t.status = "done" OR t.closed_at IS NOT NULL THEN 1 ELSE 0 END) AS done_tasks,
                   SUM(CASE
                       WHEN t.status = "overdue"
                         OR (t.date_end IS NOT NULL AND t.date_end < :today AND t.status != "done" AND t.closed_at IS NULL)
                       THEN 1 ELSE 0
                   END) AS overdue_tasks,
                   COALESCE(ROUND(AVG(t.progress), 0), 0) AS avg_progress,
                   COALESCE(ROUND(SUM(CASE
                       WHEN NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id) THEN COALESCE(t.planned_hours, 0)
                       ELSE 0
                   END), 2), 0) AS planned_hours,
                   COALESCE(ROUND(SUM(CASE
                       WHEN NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id) THEN COALESCE(t.actual_hours, 0)
                       ELSE 0
                   END), 2), 0) AS actual_hours,
                   COALESCE((SELECT ROUND(SUM(c.planned_cost), 2) FROM project_cost_plan c WHERE c.project_id = p.id), 0) AS planned_cost,
                   COALESCE((SELECT ROUND(SUM(c.labor_hours), 2) FROM project_cost_plan c WHERE c.project_id = p.id), 0) AS planned_labor_hours,
                   COALESCE((SELECT COUNT(*) FROM project_cost_plan c WHERE c.project_id = p.id AND c.labor_approval_status IN ("pending_director", "rejected")), 0) AS labor_pending_director,
                   COALESCE((SELECT COUNT(*) FROM project_issues i WHERE i.project_id = p.id AND i.status != "done"), 0) AS open_issues,
                   COALESCE((SELECT COUNT(*) FROM project_data_registry d WHERE d.project_id = p.id AND d.status = "waiting"), 0) AS waiting_data,
                   COALESCE((SELECT COUNT(*) FROM project_task_exchange e WHERE e.project_id = p.id AND e.status = "blocked"), 0) AS blocked_exchange
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY p.id, p.code, p.title
            ORDER BY overdue_tasks DESC, open_issues DESC, open_tasks DESC, p.code
            LIMIT 80
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function analyticsGroupedTasks(array $user, array $input, string $field): array
    {
        [$where, $params] = $this->taskWhere($user, $input);
        $params['today'] = date('Y-m-d');
        $expression = $field === 'discipline'
            ? 'COALESCE(NULLIF(t.discipline, ""), "Без дисциплины")'
            : 't.status';

        $stmt = $this->db()->prepare('
            SELECT ' . $expression . ' AS label,
                   COUNT(*) AS total_tasks,
                   SUM(CASE WHEN t.status != "done" AND t.closed_at IS NULL THEN 1 ELSE 0 END) AS open_tasks,
                   SUM(CASE WHEN t.status = "done" OR t.closed_at IS NOT NULL THEN 1 ELSE 0 END) AS done_tasks,
                   SUM(CASE
                       WHEN t.status = "overdue"
                         OR (t.date_end IS NOT NULL AND t.date_end < :today AND t.status != "done" AND t.closed_at IS NULL)
                       THEN 1 ELSE 0
                   END) AS overdue_tasks,
                   COALESCE(ROUND(SUM(CASE
                       WHEN NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id) THEN COALESCE(t.planned_hours, 0)
                       ELSE 0
                   END), 2), 0) AS planned_hours,
                   COALESCE(ROUND(SUM(CASE
                       WHEN NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id) THEN COALESCE(t.actual_hours, 0)
                       ELSE 0
                   END), 2), 0) AS actual_hours
            FROM tasks t
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY ' . $expression . '
            ORDER BY overdue_tasks DESC, open_tasks DESC, total_tasks DESC, label
            LIMIT 40
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function analyticsGroupedAccounting(array $user, array $input, string $field): array
    {
        [$where, $params] = $this->taskWhere($user, $input);
        $params['today'] = date('Y-m-d');
        $isBtp = $field === 'btp';
        $label = $isBtp
            ? 'COALESCE(NULLIF(btp.code, ""), NULLIF(t.btp, ""), "Без БТП")'
            : 'COALESCE(NULLIF(pp.code, ""), "Без ПП")';

        $stmt = $this->db()->prepare('
            SELECT ' . $label . ' AS label,
                   COUNT(*) AS total_tasks,
                   SUM(CASE WHEN t.status != "done" AND t.closed_at IS NULL THEN 1 ELSE 0 END) AS open_tasks,
                   SUM(CASE
                       WHEN t.status = "overdue"
                         OR (t.date_end IS NOT NULL AND t.date_end < :today AND t.status != "done" AND t.closed_at IS NULL)
                       THEN 1 ELSE 0
                   END) AS overdue_tasks,
                   COALESCE(ROUND(SUM(CASE
                       WHEN NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id) THEN COALESCE(t.planned_hours, 0)
                       ELSE 0
                   END), 2), 0) AS planned_hours,
                   COALESCE(ROUND(SUM(CASE
                       WHEN NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id) THEN COALESCE(t.actual_hours, 0)
                       ELSE 0
                   END), 2), 0) AS actual_hours
            FROM tasks t
            LEFT JOIN project_btp_codes btp ON btp.id = t.btp_code_id
            LEFT JOIN project_pp_codes pp ON pp.id = COALESCE(t.pp_code_id, btp.pp_code_id)
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY ' . $label . '
            ORDER BY open_tasks DESC, overdue_tasks DESC, planned_hours DESC, label
            LIMIT 40
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function analyticsWorkload(array $user, array $input): array
    {
        [$where, $params] = $this->taskWhere($user, $input);
        $params['today'] = date('Y-m-d');

        $stmt = $this->db()->prepare('
            SELECT COALESCE(u.name, "Не назначено") AS assignee,
                   u.id AS user_id,
                   COALESCE(u.department, "") AS department,
                   COUNT(t.id) AS total_tasks,
                   SUM(CASE WHEN t.status != "done" AND t.closed_at IS NULL THEN 1 ELSE 0 END) AS open_tasks,
                   SUM(CASE
                       WHEN t.status = "overdue"
                         OR (t.date_end IS NOT NULL AND t.date_end < :today AND t.status != "done" AND t.closed_at IS NULL)
                       THEN 1 ELSE 0
                   END) AS overdue_tasks,
                   COALESCE(ROUND(SUM(CASE
                       WHEN NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id) THEN COALESCE(t.planned_hours, 0)
                       ELSE 0
                   END), 2), 0) AS planned_hours,
                   COALESCE(ROUND(SUM(CASE
                       WHEN NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id) THEN COALESCE(t.actual_hours, 0)
                       ELSE 0
                   END), 2), 0) AS actual_hours
            FROM tasks t
            LEFT JOIN users u ON u.id = t.assignee_id
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY u.id, u.name, u.department
            ORDER BY overdue_tasks DESC, open_tasks DESC, planned_hours DESC, assignee
            LIMIT 60
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function analyticsRisks(array $user, array $input): array
    {
        $today = date('Y-m-d');
        $risks = [];

        [$taskWhere, $taskParams] = $this->taskWhere($user, $input);
        $taskParams['today'] = $today;
        $stmt = $this->db()->prepare('
            SELECT "Просроченная задача" AS kind,
                   p.code AS project,
                   t.title AS title,
                   COALESCE(u.name, "Не назначено") AS owner,
                   t.date_end AS due_date,
                   t.status AS status,
                   0 AS days
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN users u ON u.id = t.assignee_id
            WHERE ' . implode(' AND ', $taskWhere) . '
              AND t.date_end IS NOT NULL
              AND t.date_end < :today
              AND t.status != "done"
              AND t.closed_at IS NULL
            ORDER BY t.date_end ASC, t.id
            LIMIT 10
        ');
        $stmt->execute($taskParams);
        $risks = array_merge($risks, $stmt->fetchAll());

        $stmt = $this->db()->prepare('
            SELECT "Корректировка" AS kind,
                   p.code AS project,
                   t.title AS title,
                   COALESCE(u.name, "Не назначено") AS owner,
                   t.date_end AS due_date,
                   t.status AS status,
                   0 AS days
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN users u ON u.id = t.assignee_id
            WHERE ' . implode(' AND ', $taskWhere) . '
              AND t.status = "correction"
            ORDER BY t.updated_at ASC, t.id
            LIMIT 10
        ');
        $correctionParams = $taskParams;
        unset($correctionParams['today']);
        $stmt->execute($correctionParams);
        $risks = array_merge($risks, $stmt->fetchAll());

        $risks = array_merge($risks, $this->projectRiskRows($user, $input, [
            'table' => 'project_issues',
            'alias' => 'i',
            'kind' => 'Открытый вопрос',
            'title' => 'i.issue',
            'owner_join' => 'LEFT JOIN users u ON u.id = i.assignee_id',
            'owner' => 'COALESCE(u.name, "Не назначено")',
            'date' => 'i.date_raised',
            'status' => 'i.status',
            'where' => 'i.status != "done"',
            'date_filter' => 'i.date_raised',
            'days_direction' => 'age',
        ]));
        $risks = array_merge($risks, $this->projectRiskRows($user, $input, [
            'table' => 'project_data_registry',
            'alias' => 'd',
            'kind' => 'Исходные данные',
            'title' => 'd.missing_data',
            'owner_join' => '',
            'owner' => 'COALESCE(NULLIF(d.responsible, ""), "Не назначено")',
            'date' => 'd.date_received_plan',
            'status' => 'd.status',
            'where' => 'd.status = "waiting"',
            'date_filter' => 'd.date_received_plan',
            'days_direction' => 'due',
        ]));
        $risks = array_merge($risks, $this->projectRiskRows($user, $input, [
            'table' => 'project_task_exchange',
            'alias' => 'e',
            'kind' => 'Обмен заданиями',
            'title' => 'e.assignment',
            'owner_join' => 'LEFT JOIN users u ON u.id = e.to_user_id',
            'owner' => 'COALESCE(u.name, "Не назначено")',
            'date' => 'e.deadline',
            'status' => 'e.status',
            'where' => '(e.status = "blocked" OR (e.deadline IS NOT NULL AND e.deadline < :risk_today AND e.status != "done"))',
            'date_filter' => 'e.deadline',
            'days_direction' => 'due',
        ]));

        foreach ($risks as &$risk) {
            $risk['days'] = $this->daysSince($risk['due_date'] ?? null);
        }
        unset($risk);

        usort($risks, static function (array $left, array $right): int {
            return ((int) ($right['days'] ?? 0) <=> (int) ($left['days'] ?? 0))
                ?: strcmp((string) ($left['due_date'] ?? ''), (string) ($right['due_date'] ?? ''));
        });

        return array_slice($risks, 0, 16);
    }

    private function projectRiskRows(array $user, array $input, array $config): array
    {
        $alias = (string) $config['alias'];
        [$projectWhere, $params] = $this->projectWhere($user, $input, $alias . '.project_id', 'risk_' . $alias);
        [$dateWhere, $dateParams] = $this->dateWhere($input, (string) $config['date_filter'], 'risk_' . $alias);
        $params += $dateParams;
        if (str_contains((string) $config['where'], ':risk_today')) {
            $params['risk_today'] = date('Y-m-d');
        }

        $stmt = $this->db()->prepare('
            SELECT "' . $config['kind'] . '" AS kind,
                   p.code AS project,
                   ' . $config['title'] . ' AS title,
                   ' . $config['owner'] . ' AS owner,
                   ' . $config['date'] . ' AS due_date,
                   ' . $config['status'] . ' AS status,
                   0 AS days
            FROM ' . $config['table'] . ' ' . $alias . '
            INNER JOIN projects p ON p.id = ' . $alias . '.project_id
            ' . $config['owner_join'] . '
            WHERE ' . $projectWhere . '
              AND ' . $config['where'] . '
              ' . ($dateWhere !== '' ? 'AND ' . $dateWhere : '') . '
            ORDER BY days DESC, due_date ASC
            LIMIT 8
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function daysSince(mixed $date): int
    {
        $date = trim((string) $date);
        if ($date === '') {
            return 0;
        }

        $timestamp = strtotime(substr($date, 0, 10));
        if ($timestamp === false) {
            return 0;
        }

        return (int) floor((strtotime(date('Y-m-d')) - $timestamp) / 86400);
    }

    private function singleProjectMetric(array $user, array $input, string $table, string $alias, array $expressions, ?string $dateColumn = null, string $prefix = 'metric', array $extraParams = []): array
    {
        [$projectWhere, $params] = $this->projectWhere($user, $input, $alias . '.project_id', $prefix);
        if ($dateColumn !== null) {
            [$dateWhere, $dateParams] = $this->dateWhere($input, $dateColumn, $prefix);
            $params += $dateParams;
        } else {
            $dateWhere = '';
        }
        $params += $extraParams;

        $select = [];
        foreach ($expressions as $key => $expression) {
            $select[] = 'COALESCE(' . $expression . ', 0) AS ' . $key;
        }

        $stmt = $this->db()->prepare('
            SELECT ' . implode(",\n                   ", $select) . '
            FROM ' . $table . ' ' . $alias . '
            WHERE ' . $projectWhere . ($dateWhere !== '' ? ' AND ' . $dateWhere : '') . '
        ');
        $stmt->execute($params);

        return $stmt->fetch() ?: [];
    }

    private function taskWhere(array $user, array $input): array
    {
        [$scope, $params] = PermissionService::taskScopeWhere($user, 't');
        $where = [$scope];

        if (!empty($input['project_id'])) {
            $where[] = 't.project_id = :project_id';
            $params['project_id'] = (int) $input['project_id'];
        }
        [$dateWhere, $dateParams] = $this->dateWhere($input, 't.date_end', 'task');
        if ($dateWhere !== '') {
            $where[] = $dateWhere;
            $params += $dateParams;
        }

        return [$where, $params];
    }

    private function projectWhere(array $user, array $input, string $column, string $prefix): array
    {
        $selectedProjectId = (int) ($input['project_id'] ?? 0);
        $canSeeAll = PermissionService::canSeeAllProjects($user);
        $visibleIds = array_map(static fn (array $project): int => (int) $project['id'], $this->projects($user));

        if ($selectedProjectId > 0) {
            if (!$canSeeAll && !in_array($selectedProjectId, $visibleIds, true)) {
                return ['1=0', []];
            }

            return [$column . ' = :' . $prefix . '_project_id', [$prefix . '_project_id' => $selectedProjectId]];
        }

        if ($canSeeAll) {
            return ['1=1', []];
        }
        if ($visibleIds === []) {
            return ['1=0', []];
        }

        $params = [];
        $placeholders = [];
        foreach ($visibleIds as $index => $id) {
            $key = $prefix . '_project_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        return [$column . ' IN (' . implode(',', $placeholders) . ')', $params];
    }

    private function dateWhere(array $input, string $column, string $prefix): array
    {
        $where = [];
        $params = [];
        if (!empty($input['date_from'])) {
            $where[] = $column . ' >= :' . $prefix . '_date_from';
            $params[$prefix . '_date_from'] = $input['date_from'];
        }
        if (!empty($input['date_to'])) {
            $where[] = $column . ' <= :' . $prefix . '_date_to';
            $params[$prefix . '_date_to'] = $input['date_to'];
        }

        return [implode(' AND ', $where), $params];
    }

    private function projects(array $user): array
    {
        if (PermissionService::canSeeAllProjects($user)) {
            return $this->db()->query('SELECT id, code, title FROM projects ORDER BY code')->fetchAll();
        }

        [$scope, $params] = PermissionService::projectScopeWhere($user, 'p', 'report_project_scope_task');
        $stmt = $this->db()->prepare('
            SELECT DISTINCT p.id, p.code, p.title
            FROM projects p
            WHERE ' . $scope . '
            ORDER BY p.code
        ');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function reportUsers(array $user, string $search = ''): array
    {
        $search = trim($search);
        $searchClause = '';
        $searchParams = [];
        if ($search !== '') {
            $searchClause = ' AND (
                u.name LIKE :report_user_search_name
                OR u.department LIKE :report_user_search_department
            )';
            $searchParams['report_user_search_name'] = '%' . $search . '%';
            $searchParams['report_user_search_department'] = '%' . $search . '%';
        }

        [$scope, $params] = PermissionService::employeeProfileScopeWhere($user, 'u');
        $stmt = $this->db()->prepare('
            SELECT u.id,
                   u.name,
                   u.department
            FROM users u
            WHERE u.is_active = 1
              AND ' . $scope . $searchClause . '
            ORDER BY u.name
        ');
        $stmt->execute($params + $searchParams);
        return $stmt->fetchAll();
    }

    private function profileUser(array $user, int $selectedUserId): ?array
    {
        if (!PermissionService::canViewEmployeeProfile($user, $selectedUserId)) {
            return null;
        }

        $stmt = $this->db()->prepare('
            SELECT u.id,
                   u.tab_number,
                   u.name,
                   u.department,
                   u.role,
                   u.manager_id,
                   COALESCE(position.title, "") AS position_title,
                   COALESCE(position.grade, "") AS position_grade,
                   COALESCE(department.name, "") AS department_name,
                   COALESCE(manager.name, "") AS manager_name
            FROM users u
            LEFT JOIN positions position ON position.id = u.position_id
            LEFT JOIN departments department ON department.code = u.department
            LEFT JOIN users manager ON manager.id = u.manager_id
            WHERE u.id = :profile_user_id AND u.is_active = 1
            LIMIT 1
        ');
        $stmt->execute(['profile_user_id' => $selectedUserId]);

        return $stmt->fetch() ?: null;
    }

    private function selectedReportUser(array $reportUsers, int $selectedId): ?array
    {
        if ($reportUsers === []) {
            return null;
        }

        foreach ($reportUsers as $reportUser) {
            if ((int) $reportUser['id'] === $selectedId) {
                return $reportUser;
            }
        }

        return $reportUsers[0];
    }

    private function reportPeriod(array $input): array
    {
        if (($input['period'] ?? '') === 'all') {
            return ['date_from' => '', 'date_to' => '', 'period' => 'all'];
        }

        $dateFrom = trim((string) ($input['date_from'] ?? ''));
        $dateTo = trim((string) ($input['date_to'] ?? ''));
        if ($dateFrom === '') {
            $dateFrom = date('Y-m-01');
        }
        if ($dateTo === '') {
            $dateTo = date('Y-m-d');
        }

        return ['date_from' => $dateFrom, 'date_to' => $dateTo, 'period' => ''];
    }

    private function hasExplicitPeriod(array $input): bool
    {
        return array_key_exists('date_from', $input) || array_key_exists('date_to', $input);
    }

    private function peopleModel(array $user, int $selectedUserId, array $input): array
    {
        $taskInput = $input;
        unset($taskInput['user_id'], $taskInput['category']);
        if (empty($input['_task_date_filter'])) {
            unset($taskInput['date_from'], $taskInput['date_to']);
        }
        [$where, $params] = $this->taskWhere($user, $taskInput);
        $where[] = '(
            t.assignee_id = :people_user_id
            OR t.author_id = :people_user_id
            OR t.reviewer_id = :people_user_id
            OR EXISTS (
                SELECT 1 FROM task_participants tp_people
                WHERE tp_people.task_id = t.id AND tp_people.user_id = :people_user_id
            )
        )';
        $params['people_user_id'] = $selectedUserId;
        $params['people_today'] = date('Y-m-d');
        $params['people_week'] = date('Y-m-d', strtotime('+7 days'));

        $metricsStmt = $this->db()->prepare('
            SELECT COUNT(*) AS total_tasks,
                   SUM(CASE WHEN t.status != "done" AND t.closed_at IS NULL THEN 1 ELSE 0 END) AS open_tasks,
                   SUM(CASE WHEN t.status = "done" OR t.closed_at IS NOT NULL THEN 1 ELSE 0 END) AS done_tasks,
                   SUM(CASE
                       WHEN t.status = "overdue"
                         OR (t.date_end IS NOT NULL AND t.date_end < :people_today AND t.status != "done" AND t.closed_at IS NULL)
                       THEN 1 ELSE 0
                   END) AS overdue_tasks,
                   SUM(CASE
                       WHEN t.date_end IS NOT NULL
                         AND t.date_end >= :people_today
                         AND t.date_end <= :people_week
                         AND t.status != "done"
                         AND t.closed_at IS NULL
                       THEN 1 ELSE 0
                   END) AS due_week_tasks,
                   SUM(CASE WHEN t.status = "review" OR t.status = "pending_close" THEN 1 ELSE 0 END) AS review_tasks,
                   SUM(CASE WHEN t.status = "correction" THEN 1 ELSE 0 END) AS correction_tasks,
                   COALESCE(ROUND(SUM(COALESCE(t.planned_hours, 0)), 2), 0) AS planned_hours,
                   COALESCE(ROUND(SUM(COALESCE(t.actual_hours, 0)), 2), 0) AS actual_hours
            FROM tasks t
            WHERE ' . implode(' AND ', $where) . '
        ');
        $metricsStmt->execute($params);
        $metrics = $metricsStmt->fetch() ?: [];

        $roleParams = [
            'people_role_assignee_id' => $selectedUserId,
            'people_role_reviewer_id' => $selectedUserId,
            'people_role_coauthor_id' => $selectedUserId,
            'people_role_observer_id' => $selectedUserId,
            'people_role_author_id' => $selectedUserId,
        ];
        $roleExpr = 'CASE
            WHEN t.assignee_id = :people_role_assignee_id THEN "Исполнитель"
            WHEN t.reviewer_id = :people_role_reviewer_id THEN "Проверяющий"
            WHEN EXISTS (
                SELECT 1 FROM task_participants tp_role
                WHERE tp_role.task_id = t.id AND tp_role.user_id = :people_role_coauthor_id AND tp_role.role = "coauthor"
            ) THEN "Соавтор"
            WHEN EXISTS (
                SELECT 1 FROM task_participants tp_role
                WHERE tp_role.task_id = t.id AND tp_role.user_id = :people_role_observer_id AND tp_role.role = "observer"
            ) THEN "Наблюдатель"
            WHEN t.author_id = :people_role_author_id THEN "Постановщик"
            ELSE ""
        END';
        $tasksStmt = $this->db()->prepare('
            SELECT t.id,
                   t.title,
                   t.status,
                   t.task_type,
                   t.priority,
                   t.urgency,
                   t.assignee_id,
                   t.date_start,
                   t.date_end,
                   t.planned_hours,
                   t.actual_hours,
                   t.progress,
                   p.code AS project_code,
                   p.title AS project_title,
                   pp.code AS pp_code,
                   pp.title AS pp_title,
                   btp.code AS btp_code,
                   btp.title AS btp_title,
                   t.btp AS btp_legacy,
                   assignee.name AS assignee_name,
                   reviewer.name AS reviewer_name,
                   ' . $roleExpr . ' AS person_role
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN project_btp_codes btp ON btp.id = t.btp_code_id
            LEFT JOIN project_pp_codes pp ON pp.id = COALESCE(t.pp_code_id, btp.pp_code_id)
            LEFT JOIN users assignee ON assignee.id = t.assignee_id
            LEFT JOIN users reviewer ON reviewer.id = t.reviewer_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY
                CASE WHEN t.status != "done" AND t.closed_at IS NULL THEN 0 ELSE 1 END,
                t.date_end IS NULL,
                t.date_end,
                t.id
            LIMIT 200
        ');
        $taskListParams = $params;
        unset($taskListParams['people_today'], $taskListParams['people_week']);
        $tasksStmt->execute($taskListParams + $roleParams);
        $tasks = $tasksStmt->fetchAll();
        $taskIds = array_map(static fn (array $task): int => (int) $task['id'], $tasks);
        $personPlanByTask = $this->personTaskPlannedHours($selectedUserId, $tasks);
        $personFactByTask = $this->personTaskMinutes($user, $selectedUserId, $input, $taskIds);
        $personPlannedHours = 0.0;
        $personActualMinutes = 0;
        foreach ($tasks as &$task) {
            $taskId = (int) $task['id'];
            $task['person_planned_hours'] = $personPlanByTask[$taskId] ?? 0.0;
            $task['person_actual_minutes'] = $personFactByTask[$taskId] ?? 0;
            $task['person_actual_hours'] = round(((int) $task['person_actual_minutes']) / 60, 2);
            $personPlannedHours += (float) $task['person_planned_hours'];
            $personActualMinutes += (int) $task['person_actual_minutes'];
            $task['pp'] = $this->accountingLabel($task['pp_code'] ?? '', $task['pp_title'] ?? '');
            $task['btp'] = $this->accountingLabel($task['btp_code'] ?? '', $task['btp_title'] ?? '', $task['btp_legacy'] ?? '');
        }
        unset($task);
        $metrics['task_planned_hours'] = (float) ($metrics['planned_hours'] ?? 0);
        $metrics['task_actual_hours'] = (float) ($metrics['actual_hours'] ?? 0);
        $metrics['person_planned_hours'] = round($personPlannedHours, 2);
        $metrics['person_actual_hours'] = round($personActualMinutes / 60, 2);

        $timeInput = $input;
        $timeInput['user_id'] = $selectedUserId;
        [$timeWhere, $timeParams] = $this->timeWhere($user, $timeInput, 'te');
        $timeStmt = $this->db()->prepare('
            SELECT COALESCE(SUM(te.minutes), 0) AS total_minutes,
                   COALESCE(SUM(CASE WHEN te.category = "task" THEN te.minutes ELSE 0 END), 0) AS task_minutes,
                   COALESCE(SUM(CASE WHEN te.category = "overtime" THEN te.minutes ELSE 0 END), 0) AS overtime_minutes,
                   COUNT(DISTINCT te.work_date) AS work_days,
                   COUNT(DISTINCT te.task_id) AS time_tasks
            FROM time_entries te
            WHERE ' . implode(' AND ', $timeWhere) . '
        ');
        $timeStmt->execute($timeParams);
        $timeMetrics = $timeStmt->fetch() ?: [];

        return [
            'metrics' => $metrics,
            'timeMetrics' => $timeMetrics,
            'tasks' => $tasks,
            'timeByProject' => $this->peopleTimeAggregate($user, $timeInput, 'project'),
            'timeByTask' => $this->peopleTimeAggregate($user, $timeInput, 'task'),
            'timeByDay' => $this->peopleTimeAggregate($user, $timeInput, 'day'),
            'timeRows' => $this->timeRows($user, $timeInput, 120),
        ];
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return array<int, float>
     */
    private function personTaskPlannedHours(int $selectedUserId, array $tasks): array
    {
        $plans = [];
        $taskIds = array_map(static fn (array $task): int => (int) $task['id'], $tasks);
        $participantCounts = $this->taskWorkParticipantCounts($taskIds);

        foreach ($tasks as $task) {
            $taskId = (int) $task['id'];
            $plannedHours = (float) ($task['planned_hours'] ?? 0);
            if ($plannedHours <= 0) {
                $plans[$taskId] = 0.0;
                continue;
            }

            $isWorker = (int) ($task['assignee_id'] ?? 0) === $selectedUserId
                || in_array((string) ($task['person_role'] ?? ''), ['Исполнитель', 'Соавтор'], true);
            if (!$isWorker) {
                $plans[$taskId] = 0.0;
                continue;
            }

            $workers = max(1, (int) ($participantCounts[$taskId] ?? 1));
            $plans[$taskId] = round($plannedHours / $workers, 2);
        }

        return $plans;
    }

    /**
     * @param list<int> $taskIds
     * @return array<int, int>
     */
    private function taskWorkParticipantCounts(array $taskIds): array
    {
        $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
        if ($taskIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = $this->db()->prepare('
            SELECT t.id,
                   CASE
                       WHEN t.assignee_id IS NOT NULL AND t.assignee_id > 0 THEN 1
                       ELSE 0
                   END + COALESCE(tp.coauthors, 0) AS workers
            FROM tasks t
            LEFT JOIN (
                SELECT task_id, COUNT(DISTINCT user_id) AS coauthors
                FROM task_participants
                WHERE role = "coauthor" AND task_id IN (' . $placeholders . ')
                GROUP BY task_id
            ) tp ON tp.task_id = t.id
            WHERE t.id IN (' . $placeholders . ')
        ');
        $stmt->execute([...$taskIds, ...$taskIds]);
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['id']] = max(1, (int) ($row['workers'] ?? 1));
        }

        return $counts;
    }

    /**
     * @param list<int> $taskIds
     * @return array<int, int>
     */
    private function personTaskMinutes(array $user, int $selectedUserId, array $input, array $taskIds): array
    {
        $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
        if ($taskIds === []) {
            return [];
        }

        $timeInput = $input;
        $timeInput['user_id'] = $selectedUserId;
        unset($timeInput['category']);
        [$where, $params] = $this->timeWhere($user, $timeInput, 'te');
        $where[] = 'te.category = "task"';

        $placeholders = [];
        foreach ($taskIds as $index => $taskId) {
            $key = 'person_task_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $taskId;
        }
        $where[] = 'te.task_id IN (' . implode(',', $placeholders) . ')';

        $stmt = $this->db()->prepare('
            SELECT te.task_id, COALESCE(SUM(te.minutes), 0) AS minutes
            FROM time_entries te
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY te.task_id
        ');
        $stmt->execute($params);

        $minutes = [];
        foreach ($stmt->fetchAll() as $row) {
            $minutes[(int) $row['task_id']] = (int) ($row['minutes'] ?? 0);
        }

        return $minutes;
    }

    private function peopleTeamLoad(array $user, array $reportUsers, array $input): array
    {
        if ($reportUsers === []) {
            return [];
        }

        $userIds = array_map(static fn (array $row): int => (int) $row['id'], $reportUsers);
        $load = [];
        $capacityHours = $this->capacityHours((string) ($input['date_from'] ?? ''), (string) ($input['date_to'] ?? ''));
        foreach ($reportUsers as $reportUser) {
            $userId = (int) $reportUser['id'];
            $load[$userId] = [
                'user_id' => $userId,
                'name' => (string) ($reportUser['name'] ?? ''),
                'department' => (string) ($reportUser['department'] ?? ''),
                'capacity_hours' => $capacityHours,
                'planned_open_hours' => 0.0,
                'actual_hours' => 0.0,
                'remaining_hours' => 0.0,
                'time_minutes' => 0,
                'approved_minutes' => 0,
                'locked_minutes' => 0,
                'time_project_count' => 0,
                'time_project_codes' => '',
                'open_tasks' => 0,
                'overdue_tasks' => 0,
                'due_week_tasks' => 0,
                'load_percent' => 0,
                'load_status' => 'free',
                'load_label' => 'Есть резерв',
            ];
        }

        [$taskWhere, $taskParams] = $this->taskWhere($user, $input);
        $stmt = $this->db()->prepare('
            SELECT t.id,
                   t.assignee_id,
                   t.reviewer_id,
                   t.status,
                   t.closed_at,
                   t.date_end,
                   COALESCE(t.planned_hours, 0) AS planned_hours,
                   COALESCE(t.actual_hours, 0) AS actual_hours
            FROM tasks t
            WHERE ' . implode(' AND ', $taskWhere) . '
            LIMIT 5000
        ');
        $stmt->execute($taskParams);
        $tasks = $stmt->fetchAll();

        $coauthors = $this->coauthorsByTask(array_map(static fn (array $task): int => (int) $task['id'], $tasks));
        $idMap = array_fill_keys($userIds, true);
        foreach ($tasks as $task) {
            $taskId = (int) $task['id'];
            $recipients = [];
            foreach (['assignee_id', 'reviewer_id'] as $field) {
                $recipientId = (int) ($task[$field] ?? 0);
                if (isset($idMap[$recipientId])) {
                    $recipients[$recipientId] = true;
                }
            }
            foreach ($coauthors[$taskId] ?? [] as $coauthorId) {
                if (isset($idMap[$coauthorId])) {
                    $recipients[$coauthorId] = true;
                }
            }
            if ($recipients === []) {
                continue;
            }

            $isOpen = (string) ($task['status'] ?? '') !== 'done' && empty($task['closed_at']);
            $isOverdue = $isOpen && (
                (string) ($task['status'] ?? '') === 'overdue'
                || ((string) ($task['date_end'] ?? '') !== '' && (string) $task['date_end'] < date('Y-m-d'))
            );
            $isDueWeek = $isOpen
                && (string) ($task['date_end'] ?? '') !== ''
                && (string) $task['date_end'] >= date('Y-m-d')
                && (string) $task['date_end'] <= date('Y-m-d', strtotime('+7 days'));
            foreach (array_keys($recipients) as $recipientId) {
                if (!$isOpen) {
                    continue;
                }
                $load[$recipientId]['open_tasks']++;
                $load[$recipientId]['planned_open_hours'] += (float) ($task['planned_hours'] ?? 0);
                $load[$recipientId]['actual_hours'] += (float) ($task['actual_hours'] ?? 0);
                if ($isOverdue) {
                    $load[$recipientId]['overdue_tasks']++;
                }
                if ($isDueWeek) {
                    $load[$recipientId]['due_week_tasks']++;
                }
            }
        }

        $timeInput = $input;
        unset($timeInput['user_id']);
        [$timeWhere, $timeParams] = $this->timeWhere($user, $timeInput, 'te');
        $placeholders = [];
        foreach ($userIds as $index => $userId) {
            $key = 'team_time_user_' . $index;
            $placeholders[] = ':' . $key;
            $timeParams[$key] = $userId;
        }
        $timeWhere[] = 'te.user_id IN (' . implode(',', $placeholders) . ')';
        $stmt = $this->db()->prepare('
            SELECT te.user_id,
                   COALESCE(SUM(te.minutes), 0) AS time_minutes,
                   COALESCE(SUM(CASE WHEN te.status = "approved" THEN te.minutes ELSE 0 END), 0) AS approved_minutes,
                   COALESCE(SUM(CASE WHEN te.status = "locked" THEN te.minutes ELSE 0 END), 0) AS locked_minutes,
                   COUNT(DISTINCT COALESCE(te.project_id, t.project_id)) AS time_project_count,
                   GROUP_CONCAT(DISTINCT p.code) AS time_project_codes
            FROM time_entries te
            LEFT JOIN tasks t ON t.id = te.task_id
            LEFT JOIN projects p ON p.id = COALESCE(te.project_id, t.project_id)
            WHERE ' . implode(' AND ', $timeWhere) . '
            GROUP BY te.user_id
        ');
        $stmt->execute($timeParams);
        foreach ($stmt->fetchAll() as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            if (!isset($load[$userId])) {
                continue;
            }
            $load[$userId]['time_minutes'] = (int) ($row['time_minutes'] ?? 0);
            $load[$userId]['approved_minutes'] = (int) ($row['approved_minutes'] ?? 0);
            $load[$userId]['locked_minutes'] = (int) ($row['locked_minutes'] ?? 0);
            $load[$userId]['time_project_count'] = (int) ($row['time_project_count'] ?? 0);
            $load[$userId]['time_project_codes'] = (string) ($row['time_project_codes'] ?? '');
        }

        foreach ($load as &$row) {
            $row['remaining_hours'] = max(0.0, (float) $row['planned_open_hours'] - (float) $row['actual_hours']);
            $row['load_percent'] = $capacityHours > 0 ? (int) round(($row['remaining_hours'] / $capacityHours) * 100) : 0;
            if ((int) $row['overdue_tasks'] > 0 || (int) $row['load_percent'] > 110) {
                $row['load_status'] = 'overloaded';
                $row['load_label'] = 'Перегруз';
            } elseif ((int) $row['load_percent'] >= 75) {
                $row['load_status'] = 'busy';
                $row['load_label'] = 'Загружен';
            } elseif ((int) $row['load_percent'] >= 35) {
                $row['load_status'] = 'normal';
                $row['load_label'] = 'Нормально';
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

    private function coauthorsByTask(array $taskIds): array
    {
        $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
        if ($taskIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = $this->db()->prepare('
            SELECT task_id, user_id
            FROM task_participants
            WHERE role = "coauthor" AND task_id IN (' . $placeholders . ')
        ');
        $stmt->execute($taskIds);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[(int) $row['task_id']][] = (int) $row['user_id'];
        }

        return $rows;
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

    private function peopleTimeAggregate(array $user, array $input, string $kind): array
    {
        [$where, $params] = $this->timeWhere($user, $input, 'te');
        $driver = $this->db()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $select = match ($kind) {
            'task' => $driver === 'sqlite'
                ? 'COALESCE("#" || t.id || " " || t.title, "Непроектная строка") AS label, COALESCE(p.code, "") AS meta'
                : 'COALESCE(CONCAT("#", t.id, " ", t.title), "Непроектная строка") AS label, COALESCE(p.code, "") AS meta',
            'day' => 'te.work_date AS label, "" AS meta',
            default => 'COALESCE(p.code, "Без проекта") AS label, COALESCE(p.title, "") AS meta',
        };
        $group = match ($kind) {
            'task' => 't.id, t.title, p.code',
            'day' => 'te.work_date',
            default => 'p.id, p.code, p.title',
        };
        $order = $kind === 'day' ? 'label DESC' : 'minutes DESC, label';

        $stmt = $this->db()->prepare('
            SELECT ' . $select . ',
                   COALESCE(SUM(te.minutes), 0) AS minutes,
                   COALESCE(SUM(CASE WHEN te.category = "overtime" THEN te.minutes ELSE 0 END), 0) AS overtime_minutes,
                   COUNT(DISTINCT te.task_id) AS tasks_count
            FROM time_entries te
            LEFT JOIN tasks t ON t.id = te.task_id
            LEFT JOIN projects p ON p.id = COALESCE(te.project_id, t.project_id)
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY ' . $group . '
            ORDER BY ' . $order . '
            LIMIT 60
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function timeReport(array $user, array $input): array
    {
        [$where, $params] = $this->timeWhere($user, $input, 'te');
        $canSeeMoney = PermissionService::canManageEmployeeRates($user);
        [$rateExpr, $staffingJoins] = $this->timeRateSql();
        $costExpr = $canSeeMoney
            ? 'COALESCE(ROUND(SUM((te.minutes / 60) * ' . $rateExpr . ') / 1000, 2), 0)'
            : '0';

        $stmt = $this->db()->prepare('
            SELECT COALESCE(SUM(te.minutes), 0) AS total_minutes,
                   COALESCE(SUM(CASE WHEN te.category = "task" THEN te.minutes ELSE 0 END), 0) AS task_minutes,
                   COALESCE(SUM(CASE WHEN te.category != "task" THEN te.minutes ELSE 0 END), 0) AS non_task_minutes,
                   COALESCE(SUM(CASE WHEN te.category = "overtime" THEN te.minutes ELSE 0 END), 0) AS overtime_minutes,
                   COUNT(DISTINCT te.user_id) AS user_count,
                   COUNT(DISTINCT te.project_id) AS project_count,
                   ' . $costExpr . ' AS cost_thousand
            FROM time_entries te
            LEFT JOIN employee_rates er ON er.user_id = te.user_id
            LEFT JOIN users u ON u.id = te.user_id
            ' . $staffingJoins . '
            LEFT JOIN cfo_rates cfo ON cfo.dept_code = u.department
            WHERE ' . implode(' AND ', $where) . '
        ');
        $stmt->execute($params);
        $metrics = $stmt->fetch() ?: [];

        $byUser = $this->timeAggregate($user, $input, 'user', $canSeeMoney);
        $byProject = $this->timeAggregate($user, $input, 'project', $canSeeMoney);
        $byTask = $this->timeAggregate($user, $input, 'task', $canSeeMoney);
        $details = $this->timeRows($user, $input, 200);

        return [
            'canSeeMoney' => $canSeeMoney,
            'metrics' => $metrics,
            'byUser' => $byUser,
            'byProject' => $byProject,
            'byTask' => $byTask,
            'details' => $details,
        ];
    }

    private function timeAggregate(array $user, array $input, string $kind, bool $canSeeMoney): array
    {
        [$where, $params] = $this->timeWhere($user, $input, 'te');
        [$rateExpr, $staffingJoins] = $this->timeRateSql();
        $costExpr = $canSeeMoney
            ? 'COALESCE(ROUND(SUM((te.minutes / 60) * ' . $rateExpr . ') / 1000, 2), 0)'
            : '0';
        $driver = $this->db()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $select = match ($kind) {
            'project' => 'COALESCE(p.code, "Без проекта") AS label, COALESCE(p.title, "") AS meta',
            'task' => 'COALESCE(CONCAT("#", t.id, " ", t.title), "Непроектная строка") AS label, COALESCE(p.code, "") AS meta',
            default => 'COALESCE(u.name, "Не назначено") AS label, COALESCE(u.department, "") AS meta',
        };
        if ($driver === 'sqlite' && $kind === 'task') {
            $select = 'COALESCE("#" || t.id || " " || t.title, "Непроектная строка") AS label, COALESCE(p.code, "") AS meta';
        }

        $group = match ($kind) {
            'project' => 'p.id, p.code, p.title',
            'task' => 't.id, t.title, p.code',
            default => 'u.id, u.name, u.department',
        };

        $stmt = $this->db()->prepare('
            SELECT ' . $select . ',
                   COALESCE(SUM(te.minutes), 0) AS minutes,
                   COALESCE(SUM(CASE WHEN te.category = "overtime" THEN te.minutes ELSE 0 END), 0) AS overtime_minutes,
                   COUNT(DISTINCT te.user_id) AS users_count,
                   COUNT(DISTINCT te.task_id) AS tasks_count,
                   ' . $costExpr . ' AS cost_thousand
            FROM time_entries te
            INNER JOIN users u ON u.id = te.user_id
            LEFT JOIN tasks t ON t.id = te.task_id
            LEFT JOIN projects p ON p.id = COALESCE(te.project_id, t.project_id)
            LEFT JOIN employee_rates er ON er.user_id = te.user_id
            ' . $staffingJoins . '
            LEFT JOIN cfo_rates cfo ON cfo.dept_code = u.department
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY ' . $group . '
            ORDER BY minutes DESC, label
            LIMIT 80
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function timeRows(array $user, array $input, int $limit): array
    {
        [$where, $params] = $this->timeWhere($user, $input, 'te');
        $canSeeMoney = PermissionService::canManageEmployeeRates($user);
        [$rateExpr, $staffingJoins] = $this->timeRateSql();
        $costExpr = $canSeeMoney
            ? 'ROUND((te.minutes / 60) * ' . $rateExpr . ' / 1000, 2)'
            : '0';

        $stmt = $this->db()->prepare('
            SELECT te.id,
                   te.work_date,
                   u.name AS user_name,
                   COALESCE(u.department, "") AS department,
                   COALESCE(p.code, "") AS project_code,
                   COALESCE(p.title, "") AS project_title,
                   t.id AS task_id,
                   COALESCE(t.title, "") AS task_title,
                   COALESCE(pp.code, "") AS pp_code,
                   COALESCE(pp.title, "") AS pp_title,
                   COALESCE(btp.code, "") AS btp_code,
                   COALESCE(btp.title, "") AS btp_title,
                   te.category,
                   te.phase,
                   te.minutes,
                   te.status,
                   ' . $costExpr . ' AS cost_thousand
            FROM time_entries te
            INNER JOIN users u ON u.id = te.user_id
            LEFT JOIN tasks t ON t.id = te.task_id
            LEFT JOIN projects p ON p.id = COALESCE(te.project_id, t.project_id)
            LEFT JOIN project_btp_codes btp ON btp.id = t.btp_code_id
            LEFT JOIN project_pp_codes pp ON pp.id = COALESCE(t.pp_code_id, btp.pp_code_id)
            LEFT JOIN employee_rates er ON er.user_id = te.user_id
            ' . $staffingJoins . '
            LEFT JOIN cfo_rates cfo ON cfo.dept_code = u.department
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY te.work_date DESC, u.name, p.code, t.id
            LIMIT ' . max(1, $limit) . '
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function timeRateSql(): array
    {
        if (!StaffingService::rateSchemaAvailable($this->db())) {
            return ['COALESCE(er.hourly_rate, cfo.hourly_rate, 0)', ''];
        }
        return [
            'COALESCE(spr.hourly_rate, er.hourly_rate, sgr.hourly_rate, cfo.hourly_rate, 0)',
            "LEFT JOIN staffing_periods sp ON sp.status = 'locked' AND substr(sp.month_start, 1, 7) = substr(te.work_date, 1, 7)\n"
                . 'LEFT JOIN staffing_personal_rates spr ON spr.period_id = sp.id AND spr.user_id = te.user_id' . "\n"
                . 'LEFT JOIN staffing_group_rates sgr ON sgr.period_id = sp.id AND sgr.department_code = u.department',
        ];
    }

    private function timeWhere(array $user, array $input, string $alias): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($input['date_from'])) {
            $where[] = "{$alias}.work_date >= :time_date_from";
            $params['time_date_from'] = $input['date_from'];
        }
        if (!empty($input['date_to'])) {
            $where[] = "{$alias}.work_date <= :time_date_to";
            $params['time_date_to'] = $input['date_to'];
        }
        if (!empty($input['project_id'])) {
            $where[] = "{$alias}.project_id = :time_project_id";
            $params['time_project_id'] = (int) $input['project_id'];
        }
        if (!empty($input['user_id'])) {
            $where[] = "{$alias}.user_id = :time_user_id";
            $params['time_user_id'] = (int) $input['user_id'];
        }
        if (!empty($input['category'])) {
            $where[] = "{$alias}.category = :time_category";
            $params['time_category'] = (string) $input['category'];
        }

        if (PermissionService::canSeeAllProjects($user)) {
            return [$where, $params];
        }

        if (RoleService::isAny($user['role'] ?? null, [RoleService::DEPARTMENT_HEAD, RoleService::DEPUTY_DEPARTMENT_HEAD])) {
            $where[] = "(
                {$alias}.user_id IN (SELECT id FROM users WHERE department = :time_department)
                OR {$alias}.project_id IN (
                    SELECT id FROM projects
                    WHERE gip_user_id = :time_project_gip_user_id
                       OR rp_user_id = :time_project_rp_user_id
                )
            )";
            $params['time_department'] = $user['department'] ?? '';
            $params['time_project_gip_user_id'] = (int) $user['id'];
            $params['time_project_rp_user_id'] = (int) $user['id'];

            return [$where, $params];
        }

        if (RoleService::atLeast($user['role'] ?? null, RoleService::CHIEF_SPECIALIST)) {
            [$subordinateScope, $subordinateParams] = $this->subordinateUserScope($alias . '.user_id', 'time_subordinate_', (int) ($user['id'] ?? 0));
            $where[] = "({$alias}.user_id = :time_current_user_id OR {$subordinateScope})";
            $params['time_current_user_id'] = (int) $user['id'];
            $params += $subordinateParams;

            return [$where, $params];
        }

        $where[] = "{$alias}.user_id = :time_current_user_id";
        $params['time_current_user_id'] = (int) $user['id'];

        return [$where, $params];
    }

    private function approvePeopleTimeRows(array $user, array $input): int
    {
        $selectedUserId = (int) ($input['user_id'] ?? 0);
        if ($selectedUserId <= 0) {
            return 0;
        }

        $timeInput = $input;
        $timeInput['user_id'] = $selectedUserId;
        [$where, $params] = $this->timeWhere($user, $timeInput, 'te');
        $where[] = 'te.status IN ("draft", "submitted")';

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($input['time_entry_ids'] ?? [])))));
        if (($input['approve_scope'] ?? '') === 'all') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($input['visible_time_entry_ids'] ?? [])))));
        }
        if ($ids === []) {
            return 0;
        }
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $key = 'approve_time_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $where[] = 'te.id IN (' . implode(',', $placeholders) . ')';

        $stmt = $this->db()->prepare('
            UPDATE time_entries
            SET status = "approved",
                updated_at = CURRENT_TIMESTAMP
            WHERE id IN (
                SELECT id FROM (
                    SELECT te.id
                    FROM time_entries te
                    WHERE ' . implode(' AND ', $where) . '
                ) time_entries_to_approve
            )
        ');
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    private function canApprovePeopleTime(array $user, int $selectedUserId): bool
    {
        if ($selectedUserId <= 0 || !PermissionService::canOpenReports($user)) {
            return false;
        }
        if (PermissionService::canReviewTime($user) || RoleService::atLeast($user['role'] ?? null, RoleService::CHIEF_SPECIALIST)) {
            foreach ($this->reportUsers($user) as $reportUser) {
                if ((int) $reportUser['id'] === $selectedUserId && (int) $reportUser['id'] !== (int) ($user['id'] ?? 0)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function peopleRedirectFilters(array $input): array
    {
        return array_filter([
            'user_id' => (int) ($input['user_id'] ?? 0),
            'project_id' => (int) ($input['project_id'] ?? 0),
            'date_from' => (string) ($input['date_from'] ?? ''),
            'date_to' => (string) ($input['date_to'] ?? ''),
            'period' => (string) ($input['period'] ?? '') === 'all' ? 'all' : '',
            'category' => (string) ($input['category'] ?? ''),
            'employee_search' => (string) ($input['employee_search'] ?? ''),
        ], static fn (mixed $value): bool => $value !== '' && $value !== 0);
    }

    private function subordinateUserScope(string $managerColumn, string $prefix, int $managerId): array
    {
        $param = $prefix . 'manager_id';
        $params = [$param => $managerId];
        $placeholder = ':' . $param;
        $seed = 'SELECT id FROM users WHERE manager_id = ' . $placeholder;
        $clauses = [$managerColumn . ' = ' . $placeholder, $managerColumn . ' IN (' . $seed . ')'];
        $nested = $seed;
        for ($level = 3; $level <= 6; $level++) {
            $nested = 'SELECT id FROM users WHERE manager_id IN (' . $nested . ')';
            $clauses[] = $managerColumn . ' IN (' . $nested . ')';
        }

        return ['(' . implode(' OR ', $clauses) . ')', $params];
    }

    private function exportTimeReport(array $user, array $input): void
    {
        $rows = $this->timeRows($user, $input, 20000);
        $format = $input['format'] ?? 'csv';
        if ($format === 'xlsx') {
            $this->exportTimeXlsx($rows, PermissionService::canManageEmployeeRates($user));
        }

        $this->exportTimeCsv($rows, PermissionService::canManageEmployeeRates($user));
    }

    private function timeExportLabels(bool $includeMoney): array
    {
        $labels = [
            'work_date' => 'Дата',
            'user_name' => 'Сотрудник',
            'department' => 'Отдел',
            'project_code' => 'Код проекта',
            'project_title' => 'Проект',
            'task_id' => 'ID задачи',
            'task_title' => 'Задача',
            'pp_code' => 'ПП',
            'btp_code' => 'БТП',
            'category_label' => 'Категория',
            'phase_label' => 'Фаза',
            'hours' => 'Часы',
            'status' => 'Статус',
        ];
        if ($includeMoney) {
            $labels['cost_thousand'] = 'Сумма, тыс. руб.';
        }

        return $labels;
    }

    private function normalizedTimeRows(array $rows, bool $includeMoney): array
    {
        return array_map(static function (array $row) use ($includeMoney): array {
            $normalized = [
                'work_date' => (string) ($row['work_date'] ?? ''),
                'user_name' => (string) ($row['user_name'] ?? ''),
                'department' => (string) ($row['department'] ?? ''),
                'project_code' => (string) ($row['project_code'] ?? ''),
                'project_title' => (string) ($row['project_title'] ?? ''),
                'task_id' => (string) ($row['task_id'] ?? ''),
                'task_title' => (string) ($row['task_title'] ?? ''),
                'pp_code' => trim((string) ($row['pp_code'] ?? '') . (((string) ($row['pp_title'] ?? '') !== '') ? ' · ' . (string) $row['pp_title'] : '')),
                'btp_code' => trim((string) ($row['btp_code'] ?? '') . (((string) ($row['btp_title'] ?? '') !== '') ? ' · ' . (string) $row['btp_title'] : '')),
                'category_label' => TimeService::CATEGORIES[(string) ($row['category'] ?? '')] ?? (string) ($row['category'] ?? ''),
                'phase_label' => TimeService::PHASES[(string) ($row['phase'] ?? '')] ?? (string) ($row['phase'] ?? ''),
                'hours' => round(((int) ($row['minutes'] ?? 0)) / 60, 2),
                'status' => (string) ($row['status'] ?? ''),
            ];
            if ($includeMoney) {
                $normalized['cost_thousand'] = (float) ($row['cost_thousand'] ?? 0);
            }

            return $normalized;
        }, $rows);
    }

    private function exportTimeCsv(array $rows, bool $includeMoney): never
    {
        $labels = $this->timeExportLabels($includeMoney);
        $rows = $this->normalizedTimeRows($rows, $includeMoney);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="time-cost-report.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_values($labels), ';', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($field) => $row[$field] ?? '', array_keys($labels)), ';', '"', '\\');
        }
        fclose($out);
        exit;
    }

    private function exportTimeXlsx(array $rows, bool $includeMoney): never
    {
        $labels = $this->timeExportLabels($includeMoney);
        $rows = $this->normalizedTimeRows($rows, $includeMoney);
        if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $this->exportSimpleXlsx(
                'time-cost-report.xlsx',
                'Трудозатраты',
                array_values($labels),
                array_map(static fn (array $row): array => array_map(static fn ($field) => $row[$field] ?? '', array_keys($labels)), $rows)
            );
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Трудозатраты');
        foreach (array_values($labels) as $index => $label) {
            $sheet->setCellValueByColumnAndRow($index + 1, 1, $label);
        }
        foreach ($rows as $rowIndex => $row) {
            foreach (array_keys($labels) as $colIndex => $field) {
                $sheet->setCellValueByColumnAndRow($colIndex + 1, $rowIndex + 2, $row[$field] ?? '');
            }
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="time-cost-report.xlsx"');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    private function customFieldLabels(): array
    {
        $stmt = $this->db()->query('SELECT name, label FROM custom_fields ORDER BY sort_order, label');
        $fields = [];
        foreach ($stmt->fetchAll() as $field) {
            $fields['custom_' . $field['name']] = $field['label'];
        }
        return $fields;
    }

    /** @param array{period:array{date_from:string,date_to:string},rows:list<array<string,mixed>>} $report @param list<string> $fields */
    private function exportMonthlyCsv(array $report, array $fields): never
    {
        $labels = MonthlyWorkLedgerPreferenceService::FIELD_CATALOG;
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="monthly-work-ledger_' . $report['period']['date_from'] . '_' . $report['period']['date_to'] . '.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(static fn (string $field): string => $labels[$field] ?? $field, $fields), ';', '"', '\\');
        foreach ($report['rows'] as $row) {
            fputcsv($out, array_map(static fn (string $field): mixed => $row[$field] ?? '', $fields), ';', '"', '\\');
        }
        fclose($out);
        exit;
    }

    /** @param array{period:array{date_from:string,date_to:string},rows:list<array<string,mixed>>} $report @param list<string> $fields */
    private function exportMonthlyXlsx(array $report, array $fields): never
    {
        $labels = MonthlyWorkLedgerPreferenceService::FIELD_CATALOG;
        $this->exportSimpleXlsx(
            'monthly-work-ledger_' . $report['period']['date_from'] . '_' . $report['period']['date_to'] . '.xlsx',
            'Месячный реестр',
            array_map(static fn (string $field): string => $labels[$field] ?? $field, $fields),
            array_map(static fn (array $row): array => array_map(static fn (string $field): mixed => $row[$field] ?? '', $fields), $report['rows'])
        );
    }

    private function exportCsv(array $rows, array $fields): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tasks-report.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(fn ($field) => (self::BASE_FIELDS + $this->customFieldLabels())[$field] ?? $field, $fields), ';', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($field) => $row[$field] ?? '', $fields), ';', '"', '\\');
        }
        fclose($out);
        exit;
    }

    private function exportXlsx(array $rows, array $fields): never
    {
        $labels = self::BASE_FIELDS + $this->customFieldLabels();
        if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $this->exportSimpleXlsx(
                'tasks-report.xlsx',
                'Задачи',
                array_map(static fn ($field) => $labels[$field] ?? $field, $fields),
                array_map(static fn (array $row): array => array_map(static fn ($field) => $row[$field] ?? '', $fields), $rows)
            );
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($fields as $index => $field) {
            $sheet->setCellValueByColumnAndRow($index + 1, 1, $labels[$field] ?? $field);
        }
        foreach ($rows as $rowIndex => $row) {
            foreach ($fields as $colIndex => $field) {
                $sheet->setCellValueByColumnAndRow($colIndex + 1, $rowIndex + 2, $row[$field] ?? '');
            }
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="tasks-report.xlsx"');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    // ---- «ДБ-отчёт» (форма 02): построчный свод трудозатрат за период ----
    // Строка = сотрудник × задача × категория. Ключевые аналитики: РП, ГИП,
    // ПП (код проекта, может повторяться у проектов) и БТП (код задачи, может
    // повторяться у задач). Деньги — только ролям с правом просмотра ставок.

    private const DB_REPORT_HEADER = [
        'Таб.№', 'Сотрудник', 'Основное место работы', 'Совмещение', 'чч ВСС', 'чч АБИ',
        'Вид загрузки', 'Статья списания', 'Строка БТП', 'ПП', 'ПП-ВСС-АБИ', 'Статус ПП',
        'NICKNAME', 'Объект', '№ задачи', 'Статус задачи', 'Содержание задачи', 'Комментарий',
        'Менеджер проекта (РП)', 'ГИП', 'Раздел', 'Стоим. группа', 'Отдел', 'Раздел проекта',
        'Часы', 'Часы в вых', 'Доп. веч', 'Доп. час сумм', 'Общая трудоёмкость', 'Цена вопроса, чч',
        'Ставка чч по окладу', 'Ставка чч по сдельщине', 'Оплата по стоим. группе', 'Оплата по окладу',
        'Оплата вых.', 'Оплата доп. часов', 'Перс. надбавка', 'Доплаты', 'Сумма',
    ];

    private const DB_CATEGORY_LABELS = [
        'task' => 'Работа по задаче', 'meeting' => 'Совещание', 'admin' => 'Административная',
        'learning' => 'Обучение', 'vacation' => 'Отпуск', 'sick_leave' => 'Больничный',
        'business_trip' => 'Командировка', 'day_off' => 'Отгул', 'idle' => 'Простой',
        'absence' => 'Другое отсутствие', 'overtime' => 'Переработка', 'other' => 'Прочее',
    ];

    private function exportDbReport(array $user, array $input): void
    {
        $rows = $this->dbReportRows($user, $input);
        $format = $input['format'] ?? 'xlsx';
        if ($format === 'xlsx') {
            $this->exportDbXlsx($rows, $input);
        }
        $this->exportDbCsv($rows, $input);
    }

    private function dbReportRows(array $user, array $input): array
    {
        $from = trim((string) ($input['date_from'] ?? '')) ?: date('Y-m-01');
        $to = trim((string) ($input['date_to'] ?? '')) ?: date('Y-m-d');
        $canSeeMoney = PermissionService::canManageEmployeeRates($user);

        $where = ['te.work_date >= :db_date_from', 'te.work_date <= :db_date_to', 'te.status = "locked"'];
        $params = ['db_date_from' => $from, 'db_date_to' => $to];
        if (($input['project_id'] ?? '') !== '') {
            $where[] = 'COALESCE(te.project_id, t.project_id) = :db_project_id';
            $params['db_project_id'] = (int) $input['project_id'];
        }
        if (($input['user_id'] ?? '') !== '') {
            $where[] = 'te.user_id = :db_user_id';
            $params['db_user_id'] = (int) $input['user_id'];
        }
        if (($input['category'] ?? '') !== '') {
            $where[] = 'te.category = :db_category';
            $params['db_category'] = (string) $input['category'];
        }

        if (!PermissionService::canSeeAllProjects($user)) {
            if (RoleService::isAny($user['role'] ?? null, [RoleService::DEPARTMENT_HEAD, RoleService::DEPUTY_DEPARTMENT_HEAD])) {
                $where[] = '(
                    te.user_id IN (SELECT id FROM users WHERE department = :db_department)
                    OR COALESCE(te.project_id, t.project_id) IN (
                        SELECT id FROM projects
                        WHERE gip_user_id = :db_project_gip_user_id
                           OR rp_user_id = :db_project_rp_user_id
                    )
                )';
                $params['db_department'] = $user['department'] ?? '';
                $params['db_project_gip_user_id'] = (int) $user['id'];
                $params['db_project_rp_user_id'] = (int) $user['id'];
            } else {
                $where[] = 'te.user_id = :db_current_user_id';
                $params['db_current_user_id'] = (int) $user['id'];
            }
        }

        $driver = (string) $this->db()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $weekendCondition = $driver === 'sqlite'
            ? "CAST(strftime('%w', te.work_date) AS INTEGER) IN (0, 6)"
            : 'WEEKDAY(te.work_date) >= 5';

        // Свод за период: сотрудник × задача × категория (как строки формы «02»).
        $stmt = $this->db()->prepare('
            SELECT u.id AS user_id, u.tab_number, u.name AS employee, u.department,
                   te.category, SUM(te.minutes) AS minutes_sum, MAX(te.comment) AS entry_comment,
                   SUM(CASE WHEN NOT (' . $weekendCondition . ') AND te.category != "overtime" THEN te.minutes ELSE 0 END) AS regular_minutes,
                   SUM(CASE WHEN ' . $weekendCondition . ' THEN te.minutes ELSE 0 END) AS weekend_minutes,
                   SUM(CASE WHEN NOT (' . $weekendCondition . ') AND te.category = "overtime" THEN te.minutes ELSE 0 END) AS evening_minutes,
                   SUM(CASE WHEN te.category = "overtime" THEN te.minutes ELSE 0 END) AS overtime_minutes,
                   t.id AS task_id, t.btp AS btp_legacy, t.title AS task_title, t.status AS task_status,
                   t.discipline, t.section AS task_section,
                   COALESCE(NULLIF(btp.code, ""), NULLIF(t.btp, ""), "") AS btp_report,
                   COALESCE(NULLIF(pp.code, ""), NULLIF(p.pp, ""), "") AS pp_report,
                   p.id AS pid, p.pp, p.code AS project_code, p.`object` AS project_object, p.status AS project_status,
                   rp.name AS rp_name, gip.name AS gip_name
            FROM time_entries te
            INNER JOIN users u ON u.id = te.user_id
            LEFT JOIN tasks t ON t.id = te.task_id
            LEFT JOIN projects p ON p.id = COALESCE(te.project_id, t.project_id)
            LEFT JOIN project_btp_codes btp ON btp.id = t.btp_code_id
            LEFT JOIN project_pp_codes pp ON pp.id = COALESCE(t.pp_code_id, btp.pp_code_id)
            LEFT JOIN users rp ON rp.id = p.rp_user_id
            LEFT JOIN users gip ON gip.id = p.gip_user_id
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY u.id, te.task_id, COALESCE(te.project_id, t.project_id), te.category
            ORDER BY u.name, p.code, t.id
            LIMIT 20000
        ');
        $stmt->execute($params);
        $entries = $stmt->fetchAll();

        // Назначения сотрудник×юрлицо (ФОТ фаза 1): основное место, совмещение,
        // часы в день по ВСС/АБИ, стоим. группа и ставка по окладу.
        $assignments = [];
        try {
            $eleStmt = $this->db()->query('
                SELECT ele.user_id, le.code AS le_code, ele.is_primary, ele.daily_hours, ele.cost_group,
                       ele.base_oklad, ele.base_nadbavka, ele.premium, ele.project_nadbavka,
                       ele.is_piecework, ele.rate_override
                FROM employee_legal_entities ele
                INNER JOIN legal_entities le ON le.id = ele.legal_entity_id
                WHERE ele.is_active = 1
            ');
            foreach ($eleStmt->fetchAll() as $a) {
                $assignments[(int) $a['user_id']][] = $a;
            }
        } catch (\Throwable) {
            // Справочники ФОТ не настроены — колонки юрлиц/ставок останутся пустыми.
        }

        $rows = [];
        foreach ($entries as $e) {
            $uid = (int) $e['user_id'];
            $userAssignments = $assignments[$uid] ?? [];
            $primary = null;
            $secondary = null;
            $hoursVss = '';
            $hoursAbi = '';
            foreach ($userAssignments as $a) {
                if ((int) $a['is_primary'] === 1 && $primary === null) {
                    $primary = $a;
                } elseif ($secondary === null) {
                    $secondary = $a;
                }
                $code = mb_strtoupper(trim((string) $a['le_code']));
                if ($code === 'ВСС') {
                    $hoursVss = (float) $a['daily_hours'];
                }
                if ($code === 'АБИ') {
                    $hoursAbi = (float) $a['daily_hours'];
                }
            }
            $primary = $primary ?? ($userAssignments[0] ?? null);

            $minutes = (int) $e['minutes_sum'];
            $hours = round($minutes / 60, 2);
            $regularHours = $minutes > 0 && (int) $e['regular_minutes'] > 0 ? round(((int) $e['regular_minutes']) / 60, 2) : '';
            $weekendHours = (int) $e['weekend_minutes'] > 0 ? round(((int) $e['weekend_minutes']) / 60, 2) : '';
            $eveningHours = (int) $e['evening_minutes'] > 0 ? round(((int) $e['evening_minutes']) / 60, 2) : '';
            $overtimeHours = (int) $e['overtime_minutes'] > 0 ? round(((int) $e['overtime_minutes']) / 60, 2) : '';
            $rate = '';
            $ratePiece = '';
            $paySalary = '';
            $persNadbavka = '';
            $total = '';
            if ($canSeeMoney && $primary !== null) {
                $rateVal = \App\Services\PayrollService::hourlyRate($primary);
                $rate = round($rateVal, 2);
                if ((int) ($primary['is_piecework'] ?? 0) === 1 && $primary['rate_override'] !== null) {
                    $ratePiece = round((float) $primary['rate_override'], 2);
                }
                $paySalary = round($rateVal * $hours, 2);
                $persNadbavka = (float) ($primary['project_nadbavka'] ?? 0) ?: '';
                $total = $paySalary;
            }

            $isProject = !empty($e['pid']);
            $rows[] = [
                $e['tab_number'] ?? '', $e['employee'] ?? '',
                $primary['le_code'] ?? '', $secondary['le_code'] ?? '',
                $hoursVss, $hoursAbi,
                $isProject ? 'ПП' : 'НП',
                self::DB_CATEGORY_LABELS[(string) $e['category']] ?? (string) $e['category'],
                $e['btp_report'] ?? '', $e['pp_report'] ?? '', '',
                $isProject ? ((string) $e['project_status'] === 'archived' ? 'Архив' : 'В работе') : '',
                $e['project_code'] ?? '', $e['project_object'] ?? '',
                $e['task_id'] ?: '', $e['task_status'] ? task_status_label((string) $e['task_status']) : '',
                $e['task_title'] ?? '', $e['entry_comment'] ?? '',
                $e['rp_name'] ?? '', $e['gip_name'] ?? '',
                $e['discipline'] ?? '', $primary['cost_group'] ?? '', $e['department'] ?? '', $e['task_section'] ?? '',
                $regularHours, $weekendHours, $eveningHours, $overtimeHours, $hours, '',
                $rate, $ratePiece, '', $paySalary, '', '', $persNadbavka, '', $total,
            ];
        }

        return $rows;
    }

    private function accountingLabel(mixed $code, mixed $title, mixed $fallback = ''): string
    {
        $code = trim((string) $code);
        $title = trim((string) $title);
        if ($code === '') {
            return trim((string) $fallback);
        }

        return $title !== '' ? $code . ' · ' . $title : $code;
    }

    private function exportDbXlsx(array $rows, array $input): never
    {
        $from = trim((string) ($input['date_from'] ?? '')) ?: date('Y-m-01');
        $to = trim((string) ($input['date_to'] ?? '')) ?: date('Y-m-d');
        if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $this->exportSimpleXlsx('db-report_' . $from . '_' . $to . '.xlsx', 'ДБ-отчёт', self::DB_REPORT_HEADER, $rows);
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('ДБ-отчёт');
        foreach (self::DB_REPORT_HEADER as $i => $label) {
            $sheet->setCellValueByColumnAndRow($i + 1, 1, $label);
            $sheet->getStyleByColumnAndRow($i + 1, 1)->getFont()->setBold(true);
        }
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $sheet->setCellValueByColumnAndRow($c + 1, $r + 2, $value);
            }
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="db-report_' . $from . '_' . $to . '.xlsx"');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    private function exportDbCsv(array $rows, array $input): never
    {
        $from = trim((string) ($input['date_from'] ?? '')) ?: date('Y-m-01');
        $to = trim((string) ($input['date_to'] ?? '')) ?: date('Y-m-d');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="db-report_' . $from . '_' . $to . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::DB_REPORT_HEADER, ';', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($out, $row, ';', '"', '\\');
        }
        fclose($out);
        exit;
    }

    /**
     * Minimal XLSX writer for the standalone contour where Composer vendor may
     * be absent. Uses inline strings, so no sharedStrings table is required.
     */
    private function exportSimpleXlsx(string $filename, string $sheetTitle, array $headers, array $rows): never
    {
        if (!class_exists('\ZipArchive')) {
            flash('error', 'На сервере недоступно PHP-расширение zip, XLSX не может быть сформирован.');
            redirect($_SERVER['HTTP_REFERER'] ?? '/reports');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'locia-xlsx-');
        if ($tmp === false) {
            flash('error', 'Не удалось создать временный файл для XLSX.');
            redirect($_SERVER['HTTP_REFERER'] ?? '/reports');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            flash('error', 'Не удалось открыть XLSX-архив для записи.');
            redirect($_SERVER['HTTP_REFERER'] ?? '/reports');
        }

        $zip->addFromString('[Content_Types].xml', $this->xlsxContentTypes());
        $zip->addFromString('_rels/.rels', $this->xlsxRootRels());
        $zip->addFromString('xl/workbook.xml', $this->xlsxWorkbook($sheetTitle));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xlsxWorkbookRels());
        $zip->addFromString('xl/styles.xml', $this->xlsxStyles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->xlsxSheet($headers, $rows));
        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . (string) filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    private function xlsxSheet(array $headers, array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>';
        $xml .= $this->xlsxRow(1, $headers, true);
        $rowNumber = 2;
        foreach ($rows as $row) {
            $xml .= $this->xlsxRow($rowNumber++, array_values($row), false);
        }
        return $xml . '</sheetData></worksheet>';
    }

    private function xlsxRow(int $rowNumber, array $values, bool $header): string
    {
        $xml = '<row r="' . $rowNumber . '">';
        foreach (array_values($values) as $index => $value) {
            $cell = $this->xlsxColumnName($index + 1) . $rowNumber;
            $style = $header ? ' s="1"' : '';
            $xml .= '<c r="' . $cell . '" t="inlineStr"' . $style . '><is><t>'
                . $this->xlsxEscape((string) $value)
                . '</t></is></c>';
        }
        return $xml . '</row>';
    }

    private function xlsxColumnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }
        return $name;
    }

    private function xlsxEscape(string $value): string
    {
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '', ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function xlsxContentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function xlsxRootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function xlsxWorkbook(string $sheetTitle): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $this->xlsxEscape(mb_substr($sheetTitle, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function xlsxWorkbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function xlsxStyles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '</styleSheet>';
    }
}
