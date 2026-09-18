<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class WeeklyReportService
{
    public const SECTION_PROGRESS = 'progress';
    public const SECTION_DONE = 'done';
    public const SECTION_DEVIATIONS = 'deviations';
    public const SECTION_FINANCE = 'finance';
    public const SECTION_RISKS = 'risks';
    public const SECTION_REQUESTS = 'requests';
    public const SECTION_NEXT = 'next';

    public const STATUS_GREEN = 'green';
    public const STATUS_YELLOW = 'yellow';
    public const STATUS_RED = 'red';

    public const PERIOD_DAY = 'day';
    public const PERIOD_WEEK = 'week';
    public const PERIOD_MONTH = 'month';
    public const PERIOD_QUARTER = 'quarter';
    public const PERIOD_YEAR = 'year';
    public const PERIOD_CUSTOM = 'custom';

    public static function sectionLabels(): array
    {
        return [
            self::SECTION_PROGRESS => 'Прогресс по разделам/этапам',
            self::SECTION_DONE => 'Что сделано существенного',
            self::SECTION_DEVIATIONS => 'Что не сделано из плана и почему',
            self::SECTION_FINANCE => 'Финансы',
            self::SECTION_RISKS => 'Риски',
            self::SECTION_REQUESTS => 'Запросы / решения от адресата',
            self::SECTION_NEXT => 'План на следующий период',
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_GREEN => 'Зелёный',
            self::STATUS_YELLOW => 'Жёлтый',
            self::STATUS_RED => 'Красный',
        ];
    }

    public static function periodTypeLabels(): array
    {
        return [
            self::PERIOD_DAY => 'День',
            self::PERIOD_WEEK => 'Неделя',
            self::PERIOD_MONTH => 'Месяц',
            self::PERIOD_QUARTER => 'Квартал',
            self::PERIOD_YEAR => 'Год',
            self::PERIOD_CUSTOM => 'Период',
        ];
    }

    public static function defaultPeriodValues(string $periodType = self::PERIOD_WEEK): array
    {
        $service = new self();
        [$dateFrom, $dateTo] = $service->periodDates([
            'period_type' => $periodType,
            'date_from' => date('Y-m-d'),
            'date_to' => date('Y-m-d'),
        ]);

        return [
            'period_type' => $periodType,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    public static function severityLabels(): array
    {
        return [
            'info' => 'Инфо',
            'ok' => 'Норма',
            'warning' => 'Риск',
            'danger' => 'Критично',
        ];
    }

    public static function canEdit(array $user): bool
    {
        return RoleService::isAny($user['role'] ?? null, [
            RoleService::GIP,
            RoleService::PROJECT_MANAGER,
            RoleService::DEPUTY_DIRECTOR,
            RoleService::DIRECTOR,
            RoleService::ADMIN,
        ]);
    }

    public function visibleProjects(array $user): array
    {
        $db = Database::pdo();
        if (PermissionService::canSeeAllProjects($user)) {
            return $db->query('
                SELECT id, code, title
                FROM projects
                WHERE status = "active"
                ORDER BY code
            ')->fetchAll();
        }

        [$scope, $params] = PermissionService::taskScopeWhere($user, 't');
        $params['weekly_gip_user_id'] = (int) $user['id'];
        $params['weekly_rp_user_id'] = (int) $user['id'];

        $stmt = $db->prepare('
            SELECT DISTINCT p.id, p.code, p.title
            FROM projects p
            LEFT JOIN tasks t ON t.project_id = p.id
            WHERE p.status = "active"
              AND (
                  p.gip_user_id = :weekly_gip_user_id
                  OR p.rp_user_id = :weekly_rp_user_id
                  OR (' . $scope . ')
              )
            ORDER BY p.code
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function reportList(array $user): array
    {
        [$where, $params] = $this->reportScopeWhere($user, 'wr');
        $stmt = $this->db()->prepare('
            SELECT wr.*, u.name AS author_name
            FROM weekly_reports wr
            LEFT JOIN users u ON u.id = wr.author_id
            WHERE ' . $where . '
            ORDER BY wr.date_from DESC, wr.id DESC
            LIMIT 120
        ');
        $stmt->execute($params);
        $reports = $stmt->fetchAll();
        if (!$reports) {
            return [];
        }

        $projectMap = $this->reportProjectCodes(array_map(static fn (array $row): int => (int) $row['id'], $reports));
        foreach ($reports as &$report) {
            $report['project_codes'] = implode(', ', $projectMap[(int) $report['id']] ?? []);
        }
        unset($report);

        return $reports;
    }

    public function createDraft(array $user, array $input): int
    {
        if (!self::canEdit($user)) {
            throw new \RuntimeException('Недостаточно прав для создания отчёта.');
        }

        $periodType = $this->periodType($input);
        [$dateFrom, $dateTo] = $this->periodDates($input);
        $projectIds = $this->selectedProjectIds($user, (array) ($input['project_ids'] ?? []));
        if ($projectIds === []) {
            throw new \RuntimeException('Выберите хотя бы один доступный проект.');
        }

        $db = $this->db();
        $db->beginTransaction();
        try {
            $status = $this->suggestStatus($projectIds, $dateTo);
            $previousStatus = $this->previousLockedStatus($projectIds, $dateFrom);
            $summary = $this->summaryText($projectIds, $dateFrom, $dateTo, $status, $periodType);
            $finances = $this->financeText($projectIds);
            $title = $this->titleForPeriod($periodType);

            $stmt = $db->prepare('
                INSERT INTO weekly_reports (
                    author_id, recipient, period_type, date_from, date_to, portfolio_status, previous_status,
                    title, summary, finances_text, state, created_at, updated_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ');
            $stmt->execute([
                (int) $user['id'],
                trim((string) ($input['recipient'] ?? 'Куратор проектов / Заказчик')),
                $periodType,
                $dateFrom,
                $dateTo,
                $status,
                $previousStatus,
                $title,
                $summary,
                $finances,
            ]);
            $reportId = (int) $db->lastInsertId();

            $insertProject = $db->prepare('
                INSERT INTO weekly_report_projects (report_id, project_id, sort_order)
                VALUES (?, ?, ?)
            ');
            foreach ($projectIds as $index => $projectId) {
                $insertProject->execute([$reportId, $projectId, $index + 1]);
            }

            $this->insertItems($reportId, $this->draftItems($projectIds, $dateFrom, $dateTo, $periodType));
            ActivityLogService::recordLocia((int) $user['id'], 'periodic_report.created', 'Создан черновик отчёта', $this->periodLabel($periodType) . ': ' . $dateFrom . ' - ' . $dateTo);
            $db->commit();

            return $reportId;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function report(array $user, int $id): ?array
    {
        [$where, $params] = $this->reportScopeWhere($user, 'wr');
        $params['report_id'] = $id;
        $stmt = $this->db()->prepare('
            SELECT wr.*, u.name AS author_name
            FROM weekly_reports wr
            LEFT JOIN users u ON u.id = wr.author_id
            WHERE wr.id = :report_id AND ' . $where . '
            LIMIT 1
        ');
        $stmt->execute($params);
        $report = $stmt->fetch();
        if (!$report) {
            return null;
        }

        $report['projects'] = $this->projectsForReport($id);
        $report['items'] = $this->itemsForReport($id);

        return $report;
    }

    public function updateReport(array $user, int $id, array $input): void
    {
        $report = $this->report($user, $id);
        if (!$report) {
            throw new \RuntimeException('Отчёт не найден.');
        }
        if (!self::canEdit($user) || (string) $report['state'] !== 'draft') {
            throw new \RuntimeException('Этот отчёт нельзя редактировать.');
        }

        $periodType = $this->periodType($input);
        [$dateFrom, $dateTo] = $this->periodDates($input);
        $status = $this->enumValue((string) ($input['portfolio_status'] ?? ''), array_keys(self::statusLabels()), self::STATUS_YELLOW);
        $previous = trim((string) ($input['previous_status'] ?? ''));
        $previous = $previous !== '' ? $this->enumValue($previous, array_keys(self::statusLabels()), self::STATUS_YELLOW) : null;

        $db = $this->db();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('
                UPDATE weekly_reports
                SET recipient = ?,
                    period_type = ?,
                    date_from = ?,
                    date_to = ?,
                    portfolio_status = ?,
                    previous_status = ?,
                    title = ?,
                    summary = ?,
                    finances_text = ?,
                    conclusions_text = ?,
                    notes_text = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ');
            $stmt->execute([
                trim((string) ($input['recipient'] ?? '')),
                $periodType,
                $dateFrom,
                $dateTo,
                $status,
                $previous,
                trim((string) ($input['title'] ?? $this->titleForPeriod($periodType))),
                trim((string) ($input['summary'] ?? '')),
                trim((string) ($input['finances_text'] ?? '')),
                trim((string) ($input['conclusions_text'] ?? '')),
                trim((string) ($input['notes_text'] ?? '')),
                $id,
            ]);

            $items = (array) ($input['items'] ?? []);
            foreach ($items as $itemId => $payload) {
                $payload = (array) $payload;
                if (!empty($payload['delete'])) {
                    $delete = $db->prepare('DELETE FROM weekly_report_items WHERE id = ? AND report_id = ?');
                    $delete->execute([(int) $itemId, $id]);
                    continue;
                }

                $this->updateItem($id, (int) $itemId, $payload);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function addItem(array $user, int $id, array $input): void
    {
        $report = $this->report($user, $id);
        if (!$report) {
            throw new \RuntimeException('Отчёт не найден.');
        }
        if (!self::canEdit($user) || (string) $report['state'] !== 'draft') {
            throw new \RuntimeException('Этот отчёт нельзя редактировать.');
        }

        $section = $this->enumValue((string) ($input['section_key'] ?? ''), array_keys(self::sectionLabels()), self::SECTION_DONE);
        $projectId = (int) ($input['project_id'] ?? 0);
        $allowedProjectIds = array_map(static fn (array $project): int => (int) $project['id'], $report['projects']);
        $projectId = in_array($projectId, $allowedProjectIds, true) ? $projectId : null;
        $maxSortStmt = $this->db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM weekly_report_items WHERE report_id = ?');
        $maxSortStmt->execute([$id]);
        $maxSort = (int) $maxSortStmt->fetchColumn();

        $this->insertItems($id, [[
            'section_key' => $section,
            'project_id' => $projectId,
            'source_type' => 'manual',
            'source_id' => null,
            'item_title' => trim((string) ($input['item_title'] ?? 'Новая строка отчёта')),
            'plan_text' => trim((string) ($input['plan_text'] ?? '')),
            'fact_text' => trim((string) ($input['fact_text'] ?? '')),
            'deviation_text' => trim((string) ($input['deviation_text'] ?? '')),
            'comment_text' => trim((string) ($input['comment_text'] ?? '')),
            'severity' => $this->enumValue((string) ($input['severity'] ?? ''), array_keys(self::severityLabels()), 'info'),
            'sort_order' => $maxSort + 10,
        ]]);
    }

    public function deleteItem(array $user, int $id, int $itemId): void
    {
        $report = $this->report($user, $id);
        if (!$report) {
            throw new \RuntimeException('Отчёт не найден.');
        }
        if (!self::canEdit($user) || (string) $report['state'] !== 'draft') {
            throw new \RuntimeException('Этот отчёт нельзя редактировать.');
        }

        $stmt = $this->db()->prepare('DELETE FROM weekly_report_items WHERE id = ? AND report_id = ?');
        $stmt->execute([$itemId, $id]);
    }

    public function lock(array $user, int $id): void
    {
        $report = $this->report($user, $id);
        if (!$report) {
            throw new \RuntimeException('Отчёт не найден.');
        }
        if (!self::canEdit($user) || (string) $report['state'] !== 'draft') {
            throw new \RuntimeException('Этот отчёт нельзя зафиксировать.');
        }

        $stmt = $this->db()->prepare('
            UPDATE weekly_reports
            SET state = "locked", locked_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $stmt->execute([$id]);
        ActivityLogService::recordLocia((int) $user['id'], 'periodic_report.locked', 'Зафиксирован отчёт', (string) ($report['date_from'] . ' - ' . $report['date_to']));
    }

    private function draftItems(array $projectIds, string $dateFrom, string $dateTo, string $periodType): array
    {
        $items = [];
        $items = array_merge($items, $this->issuedScheduleItems($projectIds, $dateFrom, $dateTo));
        $items = array_merge($items, $this->doneTaskItems($projectIds, $dateFrom, $dateTo));
        $items = array_merge($items, $this->deviationItems($projectIds, $dateTo));
        $items = array_merge($items, $this->riskItems($projectIds, $dateTo));
        $items = array_merge($items, $this->requestItems($projectIds, $dateTo));
        $items = array_merge($items, $this->nextItems($projectIds, $dateFrom, $dateTo, $periodType));
        if ($items === []) {
            $items[] = [
                'section_key' => self::SECTION_DONE,
                'project_id' => null,
                'source_type' => 'manual',
                'source_id' => null,
                'item_title' => 'Существенные события периода не найдены автоматически',
                'plan_text' => '',
                'fact_text' => '',
                'deviation_text' => '',
                'comment_text' => 'Заполните этот раздел вручную.',
                'severity' => 'info',
            ];
        }

        foreach ($items as $index => &$item) {
            $item['sort_order'] = $item['sort_order'] ?? (($index + 1) * 10);
        }
        unset($item);

        return $items;
    }

    private function issuedScheduleItems(array $projectIds, string $dateFrom, string $dateTo): array
    {
        [$in, $params] = $this->inClause($projectIds, 'issued_project');
        $params['date_from'] = $dateFrom;
        $params['date_to'] = $dateTo;
        $stmt = $this->db()->prepare('
            SELECT s.*, p.code AS project_code
            FROM project_schedule s
            INNER JOIN projects p ON p.id = s.project_id
            WHERE s.project_id IN (' . $in . ')
              AND s.date_issued IS NOT NULL
              AND s.date_issued >= :date_from
              AND s.date_issued <= :date_to
            ORDER BY s.date_issued DESC, p.code, s.id
            LIMIT 30
        ');
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $title = $this->stageTitle($row, 'volume', 'section', 'График РД');
            $items[] = [
                'section_key' => self::SECTION_PROGRESS,
                'project_id' => (int) $row['project_id'],
                'source_type' => 'schedule',
                'source_id' => (int) $row['id'],
                'item_title' => $row['project_code'] . ': ' . $title,
                'plan_text' => $this->dateLabel('План', $row['rd_date_plan'] ?? ''),
                'fact_text' => $this->dateLabel('Передано', $row['date_issued'] ?? ''),
                'deviation_text' => $this->dateDeviation($row['rd_date_plan'] ?? '', $row['date_issued'] ?? ''),
                'comment_text' => (string) ($row['comments'] ?? ''),
                'severity' => $this->dateDeviationSeverity($row['rd_date_plan'] ?? '', $row['date_issued'] ?? ''),
            ];
        }

        return $items;
    }

    private function doneTaskItems(array $projectIds, string $dateFrom, string $dateTo): array
    {
        [$in, $params] = $this->inClause($projectIds, 'done_project');
        $params['date_from'] = $dateFrom . ' 00:00:00';
        $params['date_to'] = $dateTo . ' 23:59:59';
        $stmt = $this->db()->prepare('
            SELECT t.id, t.project_id, t.title, t.closed_at, t.date_end, p.code AS project_code
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.project_id IN (' . $in . ')
              AND t.closed_at IS NOT NULL
              AND t.closed_at >= :date_from
              AND t.closed_at <= :date_to
            ORDER BY t.closed_at DESC, p.code, t.id
            LIMIT 30
        ');
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'section_key' => self::SECTION_DONE,
                'project_id' => (int) $row['project_id'],
                'source_type' => 'task',
                'source_id' => (int) $row['id'],
                'item_title' => $row['project_code'] . ': ' . $row['title'],
                'plan_text' => $this->dateLabel('Срок', $row['date_end'] ?? ''),
                'fact_text' => $this->dateLabel('Закрыто', $row['closed_at'] ?? ''),
                'deviation_text' => $this->dateDeviation($row['date_end'] ?? '', $row['closed_at'] ?? ''),
                'comment_text' => 'Автоматически из закрытой задачи #' . (int) $row['id'] . '.',
                'severity' => 'ok',
            ];
        }

        return $items;
    }

    private function deviationItems(array $projectIds, string $dateTo): array
    {
        [$in, $params] = $this->inClause($projectIds, 'dev_project');
        $params['date_to'] = $dateTo;
        $stmt = $this->db()->prepare('
            SELECT t.id, t.project_id, t.title, t.date_end, t.status, p.code AS project_code, u.name AS assignee_name
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN users u ON u.id = t.assignee_id
            WHERE t.project_id IN (' . $in . ')
              AND t.date_end IS NOT NULL
              AND t.date_end <= :date_to
              AND t.status != "done"
              AND t.closed_at IS NULL
            ORDER BY t.date_end ASC, p.code, t.id
            LIMIT 30
        ');
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'section_key' => self::SECTION_DEVIATIONS,
                'project_id' => (int) $row['project_id'],
                'source_type' => 'task',
                'source_id' => (int) $row['id'],
                'item_title' => $row['project_code'] . ': ' . $row['title'],
                'plan_text' => $this->dateLabel('Плановый срок', $row['date_end'] ?? ''),
                'fact_text' => 'Не завершено',
                'deviation_text' => 'Просрочка',
                'comment_text' => 'Ответственный: ' . (($row['assignee_name'] ?? '') ?: 'не назначен') . '. Требуется уточнение статуса.',
                'severity' => 'danger',
            ];
        }

        return $items;
    }

    private function riskItems(array $projectIds, string $dateTo): array
    {
        $items = [];
        [$in, $params] = $this->inClause($projectIds, 'risk_issue_project');
        $params['date_to'] = $dateTo;
        $stmt = $this->db()->prepare('
            SELECT i.id, i.project_id, i.issue, i.date_raised, i.notes, p.code AS project_code, u.name AS assignee_name
            FROM project_issues i
            INNER JOIN projects p ON p.id = i.project_id
            LEFT JOIN users u ON u.id = i.assignee_id
            WHERE i.project_id IN (' . $in . ')
              AND i.status != "done"
              AND (i.date_raised IS NULL OR i.date_raised <= :date_to)
            ORDER BY i.date_raised IS NULL, i.date_raised ASC, p.code, i.id
            LIMIT 20
        ');
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'section_key' => self::SECTION_RISKS,
                'project_id' => (int) $row['project_id'],
                'source_type' => 'issue',
                'source_id' => (int) $row['id'],
                'item_title' => $row['project_code'] . ': открытый вопрос',
                'plan_text' => $this->dateLabel('Зафиксирован', $row['date_raised'] ?? ''),
                'fact_text' => $row['issue'],
                'deviation_text' => 'Не закрыто',
                'comment_text' => ($row['notes'] ?? '') ?: ('Ответственный: ' . (($row['assignee_name'] ?? '') ?: 'не назначен')),
                'severity' => 'warning',
            ];
        }

        [$in, $params] = $this->inClause($projectIds, 'risk_data_project');
        $stmt = $this->db()->prepare('
            SELECT d.id, d.project_id, d.missing_data, d.date_received_plan, d.impact, d.responsible, p.code AS project_code
            FROM project_data_registry d
            INNER JOIN projects p ON p.id = d.project_id
            WHERE d.project_id IN (' . $in . ')
              AND d.status = "waiting"
            ORDER BY d.date_received_plan IS NULL, d.date_received_plan ASC, p.code, d.id
            LIMIT 20
        ');
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $overdue = $this->isPast((string) ($row['date_received_plan'] ?? ''), $dateTo);
            $items[] = [
                'section_key' => self::SECTION_RISKS,
                'project_id' => (int) $row['project_id'],
                'source_type' => 'data',
                'source_id' => (int) $row['id'],
                'item_title' => $row['project_code'] . ': исходные данные',
                'plan_text' => $this->dateLabel('Получение', $row['date_received_plan'] ?? ''),
                'fact_text' => $row['missing_data'],
                'deviation_text' => $overdue ? 'Просрочка' : 'Ожидание',
                'comment_text' => ($row['impact'] ?? '') ?: ('Ответственный: ' . (($row['responsible'] ?? '') ?: 'не назначен')),
                'severity' => $overdue ? 'danger' : 'warning',
            ];
        }

        return $items;
    }

    private function requestItems(array $projectIds, string $dateTo): array
    {
        [$in, $params] = $this->inClause($projectIds, 'req_exchange_project');
        $params['date_to'] = $dateTo;
        $stmt = $this->db()->prepare('
            SELECT e.id, e.project_id, e.assignment, e.deadline, e.comments, p.code AS project_code
            FROM project_task_exchange e
            INNER JOIN projects p ON p.id = e.project_id
            WHERE e.project_id IN (' . $in . ')
              AND e.status != "done"
              AND (e.status = "blocked" OR (e.deadline IS NOT NULL AND e.deadline <= :date_to))
            ORDER BY e.deadline IS NULL, e.deadline ASC, p.code, e.id
            LIMIT 20
        ');
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'section_key' => self::SECTION_REQUESTS,
                'project_id' => (int) $row['project_id'],
                'source_type' => 'exchange',
                'source_id' => (int) $row['id'],
                'item_title' => 'Решение по ' . $row['project_code'] . ': ' . $row['assignment'],
                'plan_text' => $this->dateLabel('Срок решения', $row['deadline'] ?? ''),
                'fact_text' => 'Требуется решение адресата',
                'deviation_text' => 'Открыто',
                'comment_text' => (string) ($row['comments'] ?? ''),
                'severity' => 'warning',
            ];
        }

        return $items;
    }

    private function nextItems(array $projectIds, string $dateFrom, string $dateTo, string $periodType): array
    {
        [$in, $params] = $this->inClause($projectIds, 'next_project');
        [$nextFrom, $nextTo] = $this->nextPeriodDates($periodType, $dateFrom, $dateTo);
        $params['date_from'] = $nextFrom;
        $params['date_to'] = $nextTo;
        $stmt = $this->db()->prepare('
            SELECT t.id, t.project_id, t.title, t.date_end, p.code AS project_code
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.project_id IN (' . $in . ')
              AND t.date_end IS NOT NULL
              AND t.date_end >= :date_from
              AND t.date_end <= :date_to
              AND t.status != "done"
              AND t.closed_at IS NULL
            ORDER BY t.date_end ASC, p.code, t.id
            LIMIT 30
        ');
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'section_key' => self::SECTION_NEXT,
                'project_id' => (int) $row['project_id'],
                'source_type' => 'task',
                'source_id' => (int) $row['id'],
                'item_title' => $row['project_code'] . ': ' . $row['title'],
                'plan_text' => $this->dateLabel('Контрольная дата', $row['date_end'] ?? ''),
                'fact_text' => 'Запланировано',
                'deviation_text' => '',
                'comment_text' => 'Автоматически из ближайших сроков задач.',
                'severity' => 'info',
            ];
        }

        return $items;
    }

    private function insertItems(int $reportId, array $items): void
    {
        $stmt = $this->db()->prepare('
            INSERT INTO weekly_report_items (
                report_id, section_key, project_id, source_type, source_id, item_title,
                plan_text, fact_text, deviation_text, comment_text, severity, sort_order,
                created_at, updated_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ');
        foreach ($items as $index => $item) {
            $stmt->execute([
                $reportId,
                $item['section_key'] ?? self::SECTION_DONE,
                $item['project_id'] ?? null,
                $item['source_type'] ?? 'manual',
                $item['source_id'] ?? null,
                mb_substr((string) ($item['item_title'] ?? ''), 0, 500),
                (string) ($item['plan_text'] ?? ''),
                (string) ($item['fact_text'] ?? ''),
                (string) ($item['deviation_text'] ?? ''),
                (string) ($item['comment_text'] ?? ''),
                $this->enumValue((string) ($item['severity'] ?? ''), array_keys(self::severityLabels()), 'info'),
                (int) ($item['sort_order'] ?? (($index + 1) * 10)),
            ]);
        }
    }

    private function updateItem(int $reportId, int $itemId, array $payload): void
    {
        $section = $this->enumValue((string) ($payload['section_key'] ?? ''), array_keys(self::sectionLabels()), self::SECTION_DONE);
        $severity = $this->enumValue((string) ($payload['severity'] ?? ''), array_keys(self::severityLabels()), 'info');
        $stmt = $this->db()->prepare('
            UPDATE weekly_report_items
            SET section_key = ?,
                item_title = ?,
                plan_text = ?,
                fact_text = ?,
                deviation_text = ?,
                comment_text = ?,
                severity = ?,
                sort_order = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND report_id = ?
        ');
        $stmt->execute([
            $section,
            trim((string) ($payload['item_title'] ?? '')),
            trim((string) ($payload['plan_text'] ?? '')),
            trim((string) ($payload['fact_text'] ?? '')),
            trim((string) ($payload['deviation_text'] ?? '')),
            trim((string) ($payload['comment_text'] ?? '')),
            $severity,
            (int) ($payload['sort_order'] ?? 0),
            $itemId,
            $reportId,
        ]);
    }

    private function itemsForReport(int $reportId): array
    {
        $stmt = $this->db()->prepare('
            SELECT i.*, p.code AS project_code, p.title AS project_title
            FROM weekly_report_items i
            LEFT JOIN projects p ON p.id = i.project_id
            WHERE i.report_id = ?
            ORDER BY i.section_key, i.sort_order, i.id
        ');
        $stmt->execute([$reportId]);
        $items = $stmt->fetchAll();
        foreach ($items as &$item) {
            $item['source_href'] = $this->sourceHref($item);
        }
        unset($item);

        return $items;
    }

    private function sourceHref(array $item): string
    {
        $sourceType = (string) ($item['source_type'] ?? '');
        $sourceId = (int) ($item['source_id'] ?? 0);
        $projectId = (int) ($item['project_id'] ?? 0);
        if ($sourceType === 'task' && $sourceId > 0) {
            return '/tasks/' . $sourceId;
        }
        if ($projectId <= 0) {
            return '';
        }

        return match ($sourceType) {
            'schedule' => '/projects/' . $projectId . '/schedule',
            'issue' => '/projects/' . $projectId . '/issues',
            'data' => '/projects/' . $projectId . '/data',
            'exchange' => '/projects/' . $projectId . '/exchange',
            default => '/projects/' . $projectId,
        };
    }

    private function projectsForReport(int $reportId): array
    {
        $stmt = $this->db()->prepare('
            SELECT p.id, p.code, p.title
            FROM weekly_report_projects wrp
            INNER JOIN projects p ON p.id = wrp.project_id
            WHERE wrp.report_id = ?
            ORDER BY wrp.sort_order, p.code
        ');
        $stmt->execute([$reportId]);

        return $stmt->fetchAll();
    }

    private function reportProjectCodes(array $reportIds): array
    {
        if ($reportIds === []) {
            return [];
        }
        [$in, $params] = $this->inClause($reportIds, 'report_code');
        $stmt = $this->db()->prepare('
            SELECT wrp.report_id, p.code
            FROM weekly_report_projects wrp
            INNER JOIN projects p ON p.id = wrp.project_id
            WHERE wrp.report_id IN (' . $in . ')
            ORDER BY wrp.sort_order, p.code
        ');
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['report_id']][] = (string) $row['code'];
        }

        return $map;
    }

    private function selectedProjectIds(array $user, array $rawIds): array
    {
        $allowed = array_map(static fn (array $project): int => (int) $project['id'], $this->visibleProjects($user));
        $selected = [];
        foreach ($rawIds as $rawId) {
            $id = (int) $rawId;
            if ($id > 0 && in_array($id, $allowed, true)) {
                $selected[] = $id;
            }
        }

        return array_values(array_unique($selected));
    }

    private function reportScopeWhere(array $user, string $alias): array
    {
        if (PermissionService::canSeeAllProjects($user)) {
            return ['1=1', []];
        }

        $visibleIds = array_map(static fn (array $project): int => (int) $project['id'], $this->visibleProjects($user));
        if ($visibleIds === []) {
            return ['1=0', []];
        }

        [$in, $params] = $this->inClause($visibleIds, 'report_scope_project');

        return [
            'EXISTS (
                SELECT 1
                FROM weekly_report_projects wrp_scope
                WHERE wrp_scope.report_id = ' . $alias . '.id
                  AND wrp_scope.project_id IN (' . $in . ')
            )',
            $params,
        ];
    }

    private function periodType(array $input): string
    {
        return $this->enumValue((string) ($input['period_type'] ?? ''), array_keys(self::periodTypeLabels()), self::PERIOD_WEEK);
    }

    private function periodDates(array $input): array
    {
        $periodType = $this->periodType($input);
        $dateFrom = $this->validDate((string) ($input['date_from'] ?? '')) ?: date('Y-m-d', strtotime('-6 days'));
        $dateTo = $this->validDate((string) ($input['date_to'] ?? '')) ?: date('Y-m-d');

        if ($periodType !== self::PERIOD_CUSTOM) {
            $anchor = $this->validDate((string) ($input['date_from'] ?? '')) ?: date('Y-m-d');

            return match ($periodType) {
                self::PERIOD_DAY => [$anchor, $anchor],
                self::PERIOD_MONTH => [date('Y-m-01', strtotime($anchor)), date('Y-m-t', strtotime($anchor))],
                self::PERIOD_QUARTER => $this->quarterDates($anchor),
                self::PERIOD_YEAR => [date('Y-01-01', strtotime($anchor)), date('Y-12-31', strtotime($anchor))],
                default => [date('Y-m-d', strtotime('monday this week', strtotime($anchor))), date('Y-m-d', strtotime('sunday this week', strtotime($anchor)))],
            };
        }

        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [$dateFrom, $dateTo];
    }

    private function quarterDates(string $anchor): array
    {
        $timestamp = strtotime($anchor);
        $year = (int) date('Y', $timestamp);
        $month = (int) date('n', $timestamp);
        $quarterStartMonth = ((int) floor(($month - 1) / 3) * 3) + 1;
        $start = sprintf('%04d-%02d-01', $year, $quarterStartMonth);
        $end = date('Y-m-t', strtotime($start . ' +2 months'));

        return [$start, $end];
    }

    private function nextPeriodDates(string $periodType, string $dateFrom, string $dateTo): array
    {
        return match ($periodType) {
            self::PERIOD_DAY => [date('Y-m-d', strtotime($dateTo . ' +1 day')), date('Y-m-d', strtotime($dateTo . ' +1 day'))],
            self::PERIOD_WEEK => [date('Y-m-d', strtotime($dateFrom . ' +7 days')), date('Y-m-d', strtotime($dateTo . ' +7 days'))],
            self::PERIOD_MONTH => [date('Y-m-01', strtotime($dateFrom . ' +1 month')), date('Y-m-t', strtotime($dateFrom . ' +1 month'))],
            self::PERIOD_QUARTER => [date('Y-m-01', strtotime($dateFrom . ' +3 months')), date('Y-m-t', strtotime($dateFrom . ' +5 months'))],
            self::PERIOD_YEAR => [date('Y-01-01', strtotime($dateFrom . ' +1 year')), date('Y-12-31', strtotime($dateFrom . ' +1 year'))],
            default => $this->nextCustomPeriodDates($dateFrom, $dateTo),
        };
    }

    private function nextCustomPeriodDates(string $dateFrom, string $dateTo): array
    {
        $days = max(1, (int) floor((strtotime($dateTo) - strtotime($dateFrom)) / 86400) + 1);
        $nextFrom = date('Y-m-d', strtotime($dateTo . ' +1 day'));
        $nextTo = date('Y-m-d', strtotime($nextFrom . ' +' . ($days - 1) . ' days'));

        return [$nextFrom, $nextTo];
    }

    private function validDate(string $value): string
    {
        $value = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private function suggestStatus(array $projectIds, string $dateTo): string
    {
        [$in, $params] = $this->inClause($projectIds, 'status_task_project');
        $params['date_to'] = $dateTo;
        $stmt = $this->db()->prepare('
            SELECT COUNT(*)
            FROM tasks
            WHERE project_id IN (' . $in . ')
              AND date_end IS NOT NULL
              AND date_end <= :date_to
              AND status != "done"
              AND closed_at IS NULL
        ');
        $stmt->execute($params);
        $overdue = (int) $stmt->fetchColumn();

        [$in, $params] = $this->inClause($projectIds, 'status_issue_project');
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM project_issues WHERE project_id IN (' . $in . ') AND status != "done"');
        $stmt->execute($params);
        $issues = (int) $stmt->fetchColumn();

        [$in, $params] = $this->inClause($projectIds, 'status_data_project');
        $params['date_to'] = $dateTo;
        $stmt = $this->db()->prepare('
            SELECT COUNT(*)
            FROM project_data_registry
            WHERE project_id IN (' . $in . ')
              AND status = "waiting"
              AND date_received_plan IS NOT NULL
              AND date_received_plan <= :date_to
        ');
        $stmt->execute($params);
        $data = (int) $stmt->fetchColumn();

        [$in, $params] = $this->inClause($projectIds, 'status_exchange_project');
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM project_task_exchange WHERE project_id IN (' . $in . ') AND status = "blocked"');
        $stmt->execute($params);
        $blocked = (int) $stmt->fetchColumn();

        if ($overdue >= 5 || $blocked >= 3 || $data >= 3) {
            return self::STATUS_RED;
        }
        if ($overdue > 0 || $blocked > 0 || $data > 0 || $issues > 0) {
            return self::STATUS_YELLOW;
        }

        return self::STATUS_GREEN;
    }

    private function previousLockedStatus(array $projectIds, string $dateFrom): ?string
    {
        [$in, $params] = $this->inClause($projectIds, 'previous_project');
        $params['date_from'] = $dateFrom;
        $stmt = $this->db()->prepare('
            SELECT wr.portfolio_status
            FROM weekly_reports wr
            WHERE wr.state = "locked"
              AND wr.date_to < :date_from
              AND EXISTS (
                  SELECT 1
                  FROM weekly_report_projects wrp
                  WHERE wrp.report_id = wr.id
                    AND wrp.project_id IN (' . $in . ')
              )
            ORDER BY wr.date_to DESC, wr.id DESC
            LIMIT 1
        ');
        $stmt->execute($params);
        $status = $stmt->fetchColumn();

        return is_string($status) && $status !== '' ? $status : null;
    }

    private function summaryText(array $projectIds, string $dateFrom, string $dateTo, string $status, string $periodType): string
    {
        [$in, $params] = $this->inClause($projectIds, 'summary_project');
        $params['date_to'] = $dateTo;
        $stmt = $this->db()->prepare('
            SELECT p.code,
                   SUM(CASE WHEN t.date_end IS NOT NULL AND t.date_end <= :date_to AND t.status != "done" AND t.closed_at IS NULL THEN 1 ELSE 0 END) AS overdue_tasks,
                   (SELECT COUNT(*) FROM project_issues i WHERE i.project_id = p.id AND i.status != "done") AS open_issues
            FROM projects p
            LEFT JOIN tasks t ON t.project_id = p.id
            WHERE p.id IN (' . $in . ')
            GROUP BY p.id, p.code
            ORDER BY overdue_tasks DESC, open_issues DESC, p.code
            LIMIT 5
        ');
        $stmt->execute($params);
        $hot = [];
        foreach ($stmt->fetchAll() as $row) {
            $parts = [];
            if ((int) ($row['overdue_tasks'] ?? 0) > 0) {
                $parts[] = (int) $row['overdue_tasks'] . ' проср.';
            }
            if ((int) ($row['open_issues'] ?? 0) > 0) {
                $parts[] = (int) $row['open_issues'] . ' вопросов';
            }
            if ($parts) {
                $hot[] = $row['code'] . ' (' . implode(', ', $parts) . ')';
            }
        }

        $statusText = mb_strtolower(self::statusLabels()[$status] ?? 'жёлтый');
        if ($hot === []) {
            return 'Портфель проектов ' . $this->periodPhrase($periodType) . ' ' . $dateFrom . ' - ' . $dateTo . ' находится в статусе «' . $statusText . '». Критичные отклонения автоматически не найдены.';
        }

        return 'Портфель проектов ' . $this->periodPhrase($periodType) . ' ' . $dateFrom . ' - ' . $dateTo . ' находится в статусе «' . $statusText . '». Требуют внимания: ' . implode('; ', $hot) . '.';
    }

    private function titleForPeriod(string $periodType): string
    {
        return match ($periodType) {
            self::PERIOD_DAY => 'Дневной отчёт по проектам',
            self::PERIOD_WEEK => 'Недельный отчёт по проектам',
            self::PERIOD_MONTH => 'Месячный отчёт по проектам',
            self::PERIOD_QUARTER => 'Квартальный отчёт по проектам',
            self::PERIOD_YEAR => 'Годовой отчёт по проектам',
            default => 'Отчёт по проектам за период',
        };
    }

    private function periodLabel(string $periodType): string
    {
        return self::periodTypeLabels()[$periodType] ?? self::periodTypeLabels()[self::PERIOD_WEEK];
    }

    private function periodPhrase(string $periodType): string
    {
        return match ($periodType) {
            self::PERIOD_DAY => 'за день',
            self::PERIOD_WEEK => 'за неделю',
            self::PERIOD_MONTH => 'за месяц',
            self::PERIOD_QUARTER => 'за квартал',
            self::PERIOD_YEAR => 'за год',
            default => 'за период',
        };
    }

    private function financeText(array $projectIds): string
    {
        [$in, $params] = $this->inClause($projectIds, 'finance_project');
        $stmt = $this->db()->prepare('
            SELECT COUNT(*) AS rows_count,
                   COALESCE(ROUND(SUM(planned_cost), 2), 0) AS planned_cost,
                   COALESCE(ROUND(SUM(labor_hours), 2), 0) AS labor_hours
            FROM project_cost_plan
            WHERE project_id IN (' . $in . ')
        ');
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];
        if ((int) ($row['rows_count'] ?? 0) <= 0) {
            return 'Данные по бюджету не предоставлены в исходной информации. Раздел не заполняется.';
        }

        return 'План затрат: ' . $this->number((float) ($row['planned_cost'] ?? 0), 2) . ' тыс. руб.; трудозатраты: ' . $this->number((float) ($row['labor_hours'] ?? 0), 1) . ' чел-ч.';
    }

    private function enumValue(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function inClause(array $ids, string $prefix): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return ['NULL', []];
        }

        $params = [];
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $key = $prefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        return [implode(',', $placeholders), $params];
    }

    private function db(): PDO
    {
        return Database::pdo();
    }

    private function dateLabel(string $label, mixed $date): string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return '';
        }

        return $label . ': ' . substr($date, 0, 10);
    }

    private function dateDeviation(mixed $plan, mixed $fact): string
    {
        $plan = trim((string) $plan);
        $fact = trim((string) $fact);
        if ($plan === '' || $fact === '') {
            return '';
        }

        return substr($fact, 0, 10) > substr($plan, 0, 10) ? 'Сдвиг' : '0';
    }

    private function dateDeviationSeverity(mixed $plan, mixed $fact): string
    {
        return $this->dateDeviation($plan, $fact) === 'Сдвиг' ? 'warning' : 'ok';
    }

    private function isPast(string $date, string $reference): bool
    {
        return $date !== '' && substr($date, 0, 10) <= $reference;
    }

    private function stageTitle(array $row, string $first, string $second, string $fallback): string
    {
        $left = trim((string) ($row[$first] ?? ''));
        $right = trim((string) ($row[$second] ?? ''));
        $title = trim($left . ($left !== '' && $right !== '' ? ': ' : '') . $right);

        return $title !== '' ? $title : $fallback;
    }

    private function number(float $value, int $precision): string
    {
        $formatted = number_format($value, $precision, '.', ' ');

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}
