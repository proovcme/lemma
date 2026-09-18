<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class MotivationService
{
    private const DEFAULT_SETTINGS = [
        'monthly_kpi_max' => ['value' => 60000.0, 'label' => 'Максимальная месячная KPI-премия, ₽'],
        'weight_timesheet_locked' => ['value' => 0.25, 'label' => 'Вес KPI: закрытый табель'],
        'weight_timesheet_completeness' => ['value' => 0.25, 'label' => 'Вес KPI: полнота списаний'],
        'weight_deadline' => ['value' => 0.20, 'label' => 'Вес KPI: сроки'],
        'weight_rework' => ['value' => 0.15, 'label' => 'Вес KPI: возвраты'],
        'weight_plan_fact' => ['value' => 0.15, 'label' => 'Вес KPI: план/факт'],
    ];

    private const DEFAULT_GRADE_COEFFICIENTS = [
        'N-12' => 1.0,
        'N-11' => 1.0,
        'N-10' => 1.0,
        'N-9' => 1.0,
        'N-8' => 1.0,
        'N-7' => 1.2,
        'N-6' => 1.4,
        'N-5' => 1.6,
        'N-4' => 1.8,
        'N-3' => 0.0,
        'H' => 0.0,
        'Н' => 0.0,
        '0' => 0.0,
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

    public function seedDefaults(): void
    {
        $settingSql = $this->isSqlite()
            ? 'INSERT OR IGNORE INTO motivation_settings (setting_key, setting_value, label) VALUES (?, ?, ?)'
            : 'INSERT IGNORE INTO motivation_settings (setting_key, setting_value, label) VALUES (?, ?, ?)';
        $settingStmt = $this->pdo->prepare($settingSql);
        foreach (self::DEFAULT_SETTINGS as $key => $row) {
            $settingStmt->execute([$key, $row['value'], $row['label']]);
        }

        $gradeSql = $this->isSqlite()
            ? 'INSERT OR IGNORE INTO motivation_grade_coefficients (grade, coefficient, label) VALUES (?, ?, ?)'
            : 'INSERT IGNORE INTO motivation_grade_coefficients (grade, coefficient, label) VALUES (?, ?, ?)';
        $gradeStmt = $this->pdo->prepare($gradeSql);
        foreach (self::DEFAULT_GRADE_COEFFICIENTS as $grade => $coefficient) {
            $gradeStmt->execute([$grade, $coefficient, $grade === '0' ? 'Собственник' : $grade]);
        }
    }

    public function settings(): array
    {
        $this->seedDefaults();
        $rows = $this->pdo->query('SELECT * FROM motivation_settings ORDER BY setting_key')->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[(string) $row['setting_key']] = [
                'value' => (float) $row['setting_value'],
                'label' => (string) $row['label'],
            ];
        }

        return $settings;
    }

    public function gradeCoefficients(): array
    {
        $this->seedDefaults();
        $rows = $this->pdo->query('SELECT * FROM motivation_grade_coefficients ORDER BY grade')->fetchAll();
        $coefficients = [];
        foreach ($rows as $row) {
            $coefficients[(string) $row['grade']] = [
                'coefficient' => (float) $row['coefficient'],
                'label' => (string) ($row['label'] ?: $row['grade']),
            ];
        }

        return $coefficients;
    }

    public function updateSettings(array $input, int $userId): void
    {
        $this->seedDefaults();
        $stmt = $this->pdo->prepare('UPDATE motivation_settings SET setting_value = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE setting_key = ?');
        foreach (self::DEFAULT_SETTINGS as $key => $_row) {
            if (array_key_exists($key, $input)) {
                $stmt->execute([$this->decimal($input[$key]), $userId, $key]);
            }
        }

        $gradeStmt = $this->pdo->prepare('UPDATE motivation_grade_coefficients SET coefficient = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE grade = ?');
        foreach (array_keys(self::DEFAULT_GRADE_COEFFICIENTS) as $grade) {
            if (array_key_exists('grade_' . $grade, $input)) {
                $gradeStmt->execute([$this->decimal($input['grade_' . $grade]), $userId, $grade]);
            }
        }
    }

    public function projectSettings(): array
    {
        $stmt = $this->pdo->query('
            SELECT p.id AS project_id, p.code, p.title,
                   COALESCE(ms.project_fund, 0) AS project_fund,
                   ms.budget_hours, COALESCE(ms.is_paid, 0) AS is_paid,
                   ms.paid_at, ms.comment,
                   COALESCE(labor.approved_hours, 0) AS approved_hours
            FROM projects p
            LEFT JOIN project_motivation_settings ms ON ms.project_id = p.id
            LEFT JOIN (
                SELECT project_id, SUM(COALESCE(director_hours, 0)) AS approved_hours
                FROM project_labor_estimates
                WHERE status = "director_approved"
                GROUP BY project_id
            ) labor ON labor.project_id = p.id
            WHERE p.status != "archived"
            ORDER BY p.code
        ');

        return $stmt->fetchAll();
    }

    public function saveProjectSettings(array $input, int $userId): void
    {
        $projectId = (int) ($input['project_id'] ?? 0);
        if ($projectId <= 0) {
            return;
        }

        $fund = max(0.0, $this->decimal($input['project_fund'] ?? 0));
        $budget = trim((string) ($input['budget_hours'] ?? '')) === '' ? null : max(0.0, $this->decimal($input['budget_hours']));
        $paid = isset($input['is_paid']) ? 1 : 0;
        $paidAt = $paid ? $this->dateOrNull($input['paid_at'] ?? '') : null;
        $comment = trim((string) ($input['comment'] ?? '')) ?: null;

        if ($this->isSqlite()) {
            $stmt = $this->pdo->prepare('
                INSERT INTO project_motivation_settings (project_id, project_fund, budget_hours, is_paid, paid_at, comment, updated_by, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(project_id) DO UPDATE SET
                    project_fund = excluded.project_fund,
                    budget_hours = excluded.budget_hours,
                    is_paid = excluded.is_paid,
                    paid_at = excluded.paid_at,
                    comment = excluded.comment,
                    updated_by = excluded.updated_by,
                    updated_at = CURRENT_TIMESTAMP
            ');
        } else {
            $stmt = $this->pdo->prepare('
                INSERT INTO project_motivation_settings (project_id, project_fund, budget_hours, is_paid, paid_at, comment, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    project_fund = VALUES(project_fund),
                    budget_hours = VALUES(budget_hours),
                    is_paid = VALUES(is_paid),
                    paid_at = VALUES(paid_at),
                    comment = VALUES(comment),
                    updated_by = VALUES(updated_by),
                    updated_at = CURRENT_TIMESTAMP
            ');
        }
        $stmt->execute([$projectId, $fund, $budget, $paid, $paidAt, $comment, $userId]);
    }

    public function preview(string $monthStart): array
    {
        $monthStart = self::monthStart($monthStart);
        $monthEnd = self::monthEnd($monthStart);
        $settings = $this->settings();
        $grades = $this->gradeCoefficients();
        $users = $this->users();
        $time = $this->timeStats($monthStart, $monthEnd);
        $tasks = $this->taskStats($monthStart, $monthEnd);
        $reviews = $this->reviews($monthStart);
        $projectBonus = $this->projectBonusMap($monthStart, $monthEnd, $grades);
        $rows = [];
        $totals = ['kpi_amount' => 0.0, 'project_bonus_amount' => 0.0, 'total_amount' => 0.0, 'locked_hours' => 0.0];

        foreach ($users as $user) {
            $userId = (int) $user['id'];
            $grade = (string) ($user['position_grade'] ?? '');
            $gradeCoefficient = $this->coefficientForGrade($grade, $grades);
            $dailyHours = (float) ($user['daily_hours'] ?? 0);
            $employmentRatio = $dailyHours > 0 ? min(1.0, $dailyHours / 8.0) : 1.0;
            $expectedHours = $this->workingDays($monthStart, $monthEnd) * ($dailyHours > 0 ? $dailyHours : 8.0);
            $lockedHours = round((float) (($time[$userId]['locked_minutes'] ?? 0) / 60), 2);
            $taskHours = round((float) (($time[$userId]['task_minutes'] ?? 0) / 60), 2);
            $plannedHours = (float) ($tasks[$userId]['planned_hours'] ?? 0);
            $scores = $this->kpiScores($reviews[$userId]['status'] ?? 'open', $lockedHours, $expectedHours, $tasks[$userId] ?? [], $plannedHours, $taskHours);
            $kpiScore = $this->weightedScore($scores, $settings);
            $kpiAmount = round(($settings['monthly_kpi_max']['value'] ?? 0.0) * $kpiScore * $employmentRatio, 2);
            $projectAmount = round((float) ($projectBonus[$userId]['amount'] ?? 0), 2);
            $total = round($kpiAmount + $projectAmount, 2);

            $rows[] = [
                'user_id' => $userId,
                'name' => (string) $user['name'],
                'department' => (string) ($user['department'] ?? ''),
                'grade' => $grade,
                'grade_coefficient' => $gradeCoefficient,
                'employment_ratio' => round($employmentRatio, 4),
                'locked_hours' => $lockedHours,
                'expected_hours' => round($expectedHours, 2),
                'kpi_score' => round($kpiScore, 4),
                'kpi_amount' => $kpiAmount,
                'project_bonus_amount' => $projectAmount,
                'total_amount' => $total,
                'basis' => [
                    'scores' => $scores,
                    'review_status' => (string) ($reviews[$userId]['status'] ?? 'open'),
                    'task_hours' => $taskHours,
                    'planned_hours' => round($plannedHours, 2),
                    'project_bonus' => $projectBonus[$userId]['projects'] ?? [],
                ],
            ];
            $totals['kpi_amount'] += $kpiAmount;
            $totals['project_bonus_amount'] += $projectAmount;
            $totals['total_amount'] += $total;
            $totals['locked_hours'] += $lockedHours;
        }

        return [
            'period_start' => $monthStart,
            'period_end' => $monthEnd,
            'settings' => $settings,
            'rows' => $rows,
            'totals' => array_map(static fn (float $value): float => round($value, 2), $totals),
        ];
    }

    public function control(string $monthStart): array
    {
        $monthStart = self::monthStart($monthStart);
        $monthEnd = self::monthEnd($monthStart);
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $effectiveDate = min($monthEnd, max($monthStart, $today));
        $workingDaysMonth = max(1, $this->workingDays($monthStart, $monthEnd));
        $workingDaysToDate = max(1, $this->workingDays($monthStart, $effectiveDate));
        $elapsedRatio = min(1.0, $workingDaysToDate / $workingDaysMonth);

        $users = $this->controlUsers($monthStart);
        $time = $this->timeControlStats($monthStart, $monthEnd);
        $tasks = $this->weightedTaskControlStats($monthStart, $monthEnd, $effectiveDate);
        $plans = $this->userPlanControlStats($monthStart, $monthEnd);
        $rates = [];
        foreach ($users as $user) {
            $rates[(int) $user['id']] = (float) ($user['hourly_rate'] ?? 0);
        }
        $projects = $this->projectControlStats($monthStart, $monthEnd, $effectiveDate, $rates);

        $rows = [];
        $departments = [];
        $totals = [
            'actual_cost' => 0.0,
            'expected_cost_to_date' => 0.0,
            'entered_hours' => 0.0,
            'expected_hours_to_date' => 0.0,
            'task_actual_hours' => 0.0,
            'task_planned_hours' => 0.0,
            'behind_people' => 0,
            'risk_people' => 0,
            'ahead_people' => 0,
        ];

        foreach ($users as $user) {
            $userId = (int) $user['id'];
            $dailyHours = (float) ($user['daily_hours'] ?? 0);
            $dailyHours = $dailyHours > 0 ? $dailyHours : 8.0;
            $expectedHoursMonth = $workingDaysMonth * $dailyHours;
            $expectedHoursToDate = $workingDaysToDate * $dailyHours;
            $expectedTaskWeightToDate = max(1.0, ($expectedHoursMonth / 8.0) * 2.0 * $elapsedRatio);
            $hourlyRate = (float) ($user['hourly_rate'] ?? 0);
            $timeRow = $time[$userId] ?? [];
            $taskRow = $tasks[$userId] ?? [];
            $planRow = $plans[$userId] ?? [];

            $enteredHours = round(((float) ($timeRow['entered_minutes'] ?? 0)) / 60.0, 2);
            $lockedHours = round(((float) ($timeRow['locked_minutes'] ?? 0)) / 60.0, 2);
            $taskHours = round(((float) ($timeRow['task_minutes'] ?? 0)) / 60.0, 2);
            $plannedHours = round((float) ($planRow['planned_hours'] ?? 0), 2);
            $weightedClosed = round((float) ($taskRow['weighted_closed'] ?? 0), 2);
            $weightedOnTime = round((float) ($taskRow['weighted_on_time'] ?? 0), 2);
            $openTasks = (int) ($taskRow['open_tasks'] ?? 0);
            $overdueTasks = (int) ($taskRow['overdue_tasks'] ?? 0);
            $correctionTasks = (int) ($taskRow['correction_tasks'] ?? 0);
            $hoursProgress = $expectedHoursToDate > 0 ? $enteredHours / $expectedHoursToDate : 1.0;
            $taskProgress = $expectedTaskWeightToDate > 0 ? $weightedClosed / $expectedTaskWeightToDate : 1.0;
            $planFactRatio = $plannedHours > 0 && $taskHours > 0 ? $taskHours / $plannedHours : null;
            $qualityRatio = $weightedClosed > 0 ? $weightedOnTime / $weightedClosed : 1.0;
            $actualCost = round($enteredHours * $hourlyRate, 2);
            $expectedCostToDate = round($expectedHoursToDate * $hourlyRate, 2);
            $taskCost = round($taskHours * $hourlyRate, 2);
            $status = $this->controlStatus($hoursProgress, $taskProgress, $planFactRatio, $overdueTasks, $correctionTasks);
            $department = trim((string) ($user['department'] ?? '')) ?: 'Без отдела';

            $row = [
                'user_id' => $userId,
                'name' => (string) $user['name'],
                'department' => $department,
                'grade' => (string) ($user['position_grade'] ?? ''),
                'daily_hours' => round($dailyHours, 2),
                'hourly_rate' => round($hourlyRate, 2),
                'expected_hours_to_date' => round($expectedHoursToDate, 2),
                'expected_hours_month' => round($expectedHoursMonth, 2),
                'entered_hours' => $enteredHours,
                'locked_hours' => $lockedHours,
                'task_hours' => $taskHours,
                'planned_hours' => $plannedHours,
                'weighted_closed' => $weightedClosed,
                'expected_weight_to_date' => round($expectedTaskWeightToDate, 2),
                'task_progress' => round($taskProgress, 4),
                'hours_progress' => round($hoursProgress, 4),
                'plan_fact_ratio' => $planFactRatio === null ? null : round($planFactRatio, 4),
                'quality_ratio' => round($qualityRatio, 4),
                'open_tasks' => $openTasks,
                'overdue_tasks' => $overdueTasks,
                'correction_tasks' => $correctionTasks,
                'actual_cost' => $actualCost,
                'expected_cost_to_date' => $expectedCostToDate,
                'task_cost' => $taskCost,
                'cost_delta' => round($actualCost - $expectedCostToDate, 2),
                'status' => $status,
                'status_label' => $this->controlStatusLabel($status),
            ];
            $rows[] = $row;

            if (!isset($departments[$department])) {
                $departments[$department] = [
                    'department' => $department,
                    'people' => 0,
                    'behind_people' => 0,
                    'risk_people' => 0,
                    'expected_hours_to_date' => 0.0,
                    'entered_hours' => 0.0,
                    'task_hours' => 0.0,
                    'planned_hours' => 0.0,
                    'actual_cost' => 0.0,
                    'expected_cost_to_date' => 0.0,
                ];
            }
            $departments[$department]['people']++;
            $departments[$department]['behind_people'] += $status === 'behind' ? 1 : 0;
            $departments[$department]['risk_people'] += $status === 'risk' ? 1 : 0;
            $departments[$department]['expected_hours_to_date'] += $expectedHoursToDate;
            $departments[$department]['entered_hours'] += $enteredHours;
            $departments[$department]['task_hours'] += $taskHours;
            $departments[$department]['planned_hours'] += $plannedHours;
            $departments[$department]['actual_cost'] += $actualCost;
            $departments[$department]['expected_cost_to_date'] += $expectedCostToDate;

            $totals['actual_cost'] += $actualCost;
            $totals['expected_cost_to_date'] += $expectedCostToDate;
            $totals['entered_hours'] += $enteredHours;
            $totals['expected_hours_to_date'] += $expectedHoursToDate;
            $totals['task_actual_hours'] += $taskHours;
            $totals['task_planned_hours'] += $plannedHours;
            $totals['behind_people'] += $status === 'behind' ? 1 : 0;
            $totals['risk_people'] += $status === 'risk' ? 1 : 0;
            $totals['ahead_people'] += $status === 'ahead' ? 1 : 0;
        }

        foreach ($departments as &$department) {
            $department['hours_progress'] = $department['expected_hours_to_date'] > 0
                ? round($department['entered_hours'] / $department['expected_hours_to_date'], 4)
                : 1.0;
            $department['plan_fact_ratio'] = $department['planned_hours'] > 0 && $department['task_hours'] > 0
                ? round($department['task_hours'] / $department['planned_hours'], 4)
                : null;
            $department['actual_cost'] = round($department['actual_cost'], 2);
            $department['expected_cost_to_date'] = round($department['expected_cost_to_date'], 2);
            $department['cost_delta'] = round($department['actual_cost'] - $department['expected_cost_to_date'], 2);
            $department['entered_hours'] = round($department['entered_hours'], 2);
            $department['expected_hours_to_date'] = round($department['expected_hours_to_date'], 2);
            $department['task_hours'] = round($department['task_hours'], 2);
            $department['planned_hours'] = round($department['planned_hours'], 2);
        }
        unset($department);

        usort($rows, static function (array $a, array $b): int {
            $rank = ['behind' => 0, 'risk' => 1, 'on_track' => 2, 'ahead' => 3];
            return ($rank[$a['status']] ?? 4) <=> ($rank[$b['status']] ?? 4)
                ?: strcmp((string) $a['department'], (string) $b['department'])
                ?: strcmp((string) $a['name'], (string) $b['name']);
        });
        usort($projects, static function (array $a, array $b): int {
            return ($b['overdue_tasks'] <=> $a['overdue_tasks'])
                ?: (($b['plan_fact_ratio'] ?? 0) <=> ($a['plan_fact_ratio'] ?? 0))
                ?: strcmp((string) $a['code'], (string) $b['code']);
        });

        $bottlenecks = $this->controlBottlenecks($rows, array_values($departments), $projects);
        $totals['bottlenecks'] = count($bottlenecks);

        return [
            'period_start' => $monthStart,
            'period_end' => $monthEnd,
            'effective_date' => $effectiveDate,
            'working_days_month' => $workingDaysMonth,
            'working_days_to_date' => $workingDaysToDate,
            'elapsed_ratio' => round($elapsedRatio, 4),
            'rows' => $rows,
            'departments' => array_values($departments),
            'projects' => array_slice($projects, 0, 30),
            'bottlenecks' => $bottlenecks,
            'totals' => array_map(static fn (float|int $value): float|int => is_float($value) ? round($value, 2) : $value, $totals),
        ];
    }

    public function lockedRun(string $monthStart): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM motivation_runs WHERE period_start = ? AND state = "locked" LIMIT 1');
        $stmt->execute([self::monthStart($monthStart)]);
        $run = $stmt->fetch();
        if (!$run) {
            return null;
        }

        $rowsStmt = $this->pdo->prepare('
            SELECT r.*, u.name
            FROM motivation_run_rows r
            JOIN users u ON u.id = r.user_id
            WHERE r.run_id = ?
            ORDER BY r.total_amount DESC, u.name
        ');
        $rowsStmt->execute([(int) $run['id']]);
        $rows = $rowsStmt->fetchAll();
        foreach ($rows as &$row) {
            $row['basis'] = json_decode((string) ($row['basis_json'] ?? ''), true) ?: [];
        }
        unset($row);
        $run['rows'] = $rows;
        $run['totals'] = json_decode((string) ($run['totals_json'] ?? ''), true) ?: [];

        return $run;
    }

    public function lockRun(string $monthStart, int $userId): int
    {
        if ($this->lockedRun($monthStart) !== null) {
            throw new RuntimeException('Расчёт за этот месяц уже зафиксирован.');
        }

        $preview = $this->preview($monthStart);
        $monthStart = $preview['period_start'];
        $monthEnd = $preview['period_end'];

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM motivation_runs WHERE period_start = ? AND state = "draft"')->execute([$monthStart]);
            $stmt = $this->pdo->prepare('
                INSERT INTO motivation_runs (period_start, period_end, state, settings_snapshot, totals_json, created_by, locked_by, locked_at)
                VALUES (?, ?, "locked", ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ');
            $stmt->execute([
                $monthStart,
                $monthEnd,
                json_encode($preview['settings'], JSON_UNESCAPED_UNICODE),
                json_encode($preview['totals'], JSON_UNESCAPED_UNICODE),
                $userId,
                $userId,
            ]);
            $runId = (int) $this->pdo->lastInsertId();
            $rowStmt = $this->pdo->prepare('
                INSERT INTO motivation_run_rows (
                    run_id, user_id, department, grade, grade_coefficient, employment_ratio,
                    locked_hours, expected_hours, kpi_score, kpi_amount, project_bonus_amount, total_amount, basis_json
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            foreach ($preview['rows'] as $row) {
                $rowStmt->execute([
                    $runId,
                    $row['user_id'],
                    $row['department'],
                    $row['grade'],
                    $row['grade_coefficient'],
                    $row['employment_ratio'],
                    $row['locked_hours'],
                    $row['expected_hours'],
                    $row['kpi_score'],
                    $row['kpi_amount'],
                    $row['project_bonus_amount'],
                    $row['total_amount'],
                    json_encode($row['basis'], JSON_UNESCAPED_UNICODE),
                ]);
            }
            $this->pdo->commit();

            return $runId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function users(): array
    {
        return $this->pdo->query('
            SELECT u.id, u.name, u.department, p.grade AS position_grade,
                   COALESCE(ele.daily_hours_sum, 0) AS daily_hours
            FROM users u
            LEFT JOIN positions p ON p.id = u.position_id
            LEFT JOIN (
                SELECT user_id, SUM(daily_hours) AS daily_hours_sum
                FROM employee_legal_entities
                WHERE is_active = 1
                GROUP BY user_id
            ) ele ON ele.user_id = u.id
            WHERE u.is_active = 1
            ORDER BY u.department, u.name
        ')->fetchAll();
    }

    private function controlUsers(string $monthStart): array
    {
        $withStaffing = StaffingService::rateSchemaAvailable($this->pdo);
        $rate = $withStaffing
            ? 'COALESCE(spr.hourly_rate, er.hourly_rate, sgr.hourly_rate, cfo.hourly_rate, ele.hourly_rate_calc, 0)'
            : 'COALESCE(er.hourly_rate, cfo.hourly_rate, ele.hourly_rate_calc, 0)';
        $staffingJoins = $withStaffing ? "
            LEFT JOIN staffing_periods sp ON sp.status = 'locked' AND sp.month_start = ?
            LEFT JOIN staffing_personal_rates spr ON spr.period_id = sp.id AND spr.user_id = u.id
            LEFT JOIN staffing_group_rates sgr ON sgr.period_id = sp.id AND sgr.department_code = u.department" : '';
        $stmt = $this->pdo->prepare('
            SELECT u.id, u.name, u.department, p.grade AS position_grade,
                   COALESCE(ele.daily_hours_sum, 0) AS daily_hours,
                   ' . $rate . ' AS hourly_rate
            FROM users u
            LEFT JOIN positions p ON p.id = u.position_id
            ' . $staffingJoins . '
            LEFT JOIN employee_rates er ON er.user_id = u.id
            LEFT JOIN cfo_rates cfo ON cfo.dept_code = u.department
            LEFT JOIN (
                SELECT user_id,
                       SUM(daily_hours) AS daily_hours_sum,
                       SUM(COALESCE(base_oklad, 0) + COALESCE(base_nadbavka, 0) + COALESCE(premium, 0) + COALESCE(project_nadbavka, 0)) / 176.0 AS hourly_rate_calc
                FROM employee_legal_entities
                WHERE is_active = 1
                GROUP BY user_id
            ) ele ON ele.user_id = u.id
            WHERE u.is_active = 1
            ORDER BY u.department, u.name
        ');
        $stmt->execute($withStaffing ? [$monthStart] : []);
        return $stmt->fetchAll();
    }

    private function timeControlStats(string $monthStart, string $monthEnd): array
    {
        $stmt = $this->pdo->prepare('
            SELECT user_id,
                   SUM(minutes) AS entered_minutes,
                   SUM(CASE WHEN status = "locked" THEN minutes ELSE 0 END) AS locked_minutes,
                   SUM(CASE WHEN category = "task" THEN minutes ELSE 0 END) AS task_minutes,
                   SUM(CASE WHEN project_id IS NOT NULL THEN minutes ELSE 0 END) AS project_minutes
            FROM time_entries
            WHERE work_date BETWEEN ? AND ?
            GROUP BY user_id
        ');
        $stmt->execute([$monthStart, $monthEnd]);

        return $this->mapById($stmt->fetchAll(), 'user_id');
    }

    private function weightedTaskControlStats(string $monthStart, string $monthEnd, string $effectiveDate): array
    {
        $closedStmt = $this->pdo->prepare('
            SELECT assignee_id AS user_id,
                   COUNT(*) AS closed_tasks,
                   SUM(CASE priority
                       WHEN "critical" THEN 4
                       WHEN "high" THEN 3
                       WHEN "mid" THEN 2
                       WHEN "low" THEN 1
                       ELSE 2
                   END) AS weighted_closed,
                   SUM(CASE WHEN date_end IS NULL OR substr(COALESCE(closed_at, updated_at), 1, 10) <= date_end THEN
                       CASE priority
                           WHEN "critical" THEN 4
                           WHEN "high" THEN 3
                           WHEN "mid" THEN 2
                           WHEN "low" THEN 1
                           ELSE 2
                       END
                       ELSE 0
                   END) AS weighted_on_time
            FROM tasks
            WHERE assignee_id IS NOT NULL
              AND COALESCE(task_type, "work") != "delegation"
              AND (status = "done" OR closed_at IS NOT NULL)
              AND substr(COALESCE(closed_at, updated_at), 1, 10) BETWEEN ? AND ?
            GROUP BY assignee_id
        ');
        $closedStmt->execute([$monthStart, $monthEnd]);
        $stats = $this->mapById($closedStmt->fetchAll(), 'user_id');

        $openStmt = $this->pdo->prepare('
            SELECT assignee_id AS user_id,
                   COUNT(*) AS open_tasks,
                   SUM(CASE WHEN date_end IS NOT NULL AND date_end < ? THEN 1 ELSE 0 END) AS overdue_tasks,
                   SUM(CASE WHEN status = "correction" THEN 1 ELSE 0 END) AS correction_tasks,
                   SUM(CASE WHEN date_end IS NULL THEN 1 ELSE 0 END) AS no_due_tasks
            FROM tasks
            WHERE assignee_id IS NOT NULL
              AND COALESCE(task_type, "work") != "delegation"
              AND status != "done"
              AND closed_at IS NULL
            GROUP BY assignee_id
        ');
        $openStmt->execute([$effectiveDate]);
        foreach ($openStmt->fetchAll() as $row) {
            $userId = (int) $row['user_id'];
            $stats[$userId] = array_merge($stats[$userId] ?? ['user_id' => $userId], $row);
        }

        return $stats;
    }

    private function userPlanControlStats(string $monthStart, string $monthEnd): array
    {
        $stmt = $this->pdo->prepare('
            SELECT assignee_id AS user_id,
                   SUM(CASE WHEN COALESCE(task_type, "work") != "delegation" THEN COALESCE(planned_hours, 0) ELSE 0 END) AS planned_hours,
                   SUM(CASE WHEN COALESCE(task_type, "work") != "delegation" THEN COALESCE(actual_hours, 0) ELSE 0 END) AS task_actual_hours
            FROM tasks
            WHERE assignee_id IS NOT NULL
              AND (
                  (date_end IS NOT NULL AND date_end BETWEEN ? AND ?)
                  OR (closed_at IS NOT NULL AND substr(closed_at, 1, 10) BETWEEN ? AND ?)
                  OR (status != "done" AND closed_at IS NULL)
              )
            GROUP BY assignee_id
        ');
        $stmt->execute([$monthStart, $monthEnd, $monthStart, $monthEnd]);

        return $this->mapById($stmt->fetchAll(), 'user_id');
    }

    private function projectControlStats(string $monthStart, string $monthEnd, string $effectiveDate, array $ratesByUser): array
    {
        $budgetColumn = $this->hasColumn('projects', 'budget_manual_thousand') ? 'COALESCE(budget_manual_thousand, 0)' : '0';
        $projects = $this->mapById($this->pdo->query('
            SELECT id, code, title, ' . $budgetColumn . ' AS budget_manual_thousand
            FROM projects
            WHERE status != "archived"
        ')->fetchAll(), 'id');

        $taskStmt = $this->pdo->prepare('
            SELECT project_id,
                   COUNT(*) AS task_count,
                   SUM(CASE WHEN status != "done" AND closed_at IS NULL THEN 1 ELSE 0 END) AS open_tasks,
                   SUM(CASE WHEN status != "done" AND closed_at IS NULL AND date_end IS NOT NULL AND date_end < ? THEN 1 ELSE 0 END) AS overdue_tasks,
                   SUM(CASE WHEN COALESCE(task_type, "work") != "delegation" THEN COALESCE(planned_hours, 0) ELSE 0 END) AS planned_hours,
                   SUM(CASE WHEN COALESCE(task_type, "work") != "delegation" THEN COALESCE(actual_hours, 0) ELSE 0 END) AS task_actual_hours
            FROM tasks
            WHERE project_id IS NOT NULL
              AND (
                  (date_end IS NOT NULL AND date_end <= ?)
                  OR (closed_at IS NOT NULL AND substr(closed_at, 1, 10) BETWEEN ? AND ?)
                  OR (status != "done" AND closed_at IS NULL)
              )
            GROUP BY project_id
        ');
        $taskStmt->execute([$effectiveDate, $monthEnd, $monthStart, $monthEnd]);

        $rows = [];
        foreach ($taskStmt->fetchAll() as $row) {
            $projectId = (int) $row['project_id'];
            if (!isset($projects[$projectId])) {
                continue;
            }
            $project = $projects[$projectId];
            $rows[$projectId] = [
                'project_id' => $projectId,
                'code' => (string) $project['code'],
                'title' => (string) $project['title'],
                'budget_amount' => (float) $project['budget_manual_thousand'] * 1000.0,
                'task_count' => (int) ($row['task_count'] ?? 0),
                'open_tasks' => (int) ($row['open_tasks'] ?? 0),
                'overdue_tasks' => (int) ($row['overdue_tasks'] ?? 0),
                'planned_hours' => round((float) ($row['planned_hours'] ?? 0), 2),
                'actual_hours' => 0.0,
                'actual_cost' => 0.0,
                'task_actual_hours' => round((float) ($row['task_actual_hours'] ?? 0), 2),
            ];
        }

        $timeStmt = $this->pdo->prepare('
            SELECT project_id, user_id, SUM(minutes) / 60.0 AS hours
            FROM time_entries
            WHERE project_id IS NOT NULL
              AND work_date BETWEEN ? AND ?
            GROUP BY project_id, user_id
        ');
        $timeStmt->execute([$monthStart, $monthEnd]);
        foreach ($timeStmt->fetchAll() as $row) {
            $projectId = (int) $row['project_id'];
            if (!isset($projects[$projectId])) {
                continue;
            }
            if (!isset($rows[$projectId])) {
                $project = $projects[$projectId];
                $rows[$projectId] = [
                    'project_id' => $projectId,
                    'code' => (string) $project['code'],
                    'title' => (string) $project['title'],
                    'budget_amount' => (float) $project['budget_manual_thousand'] * 1000.0,
                    'task_count' => 0,
                    'open_tasks' => 0,
                    'overdue_tasks' => 0,
                    'planned_hours' => 0.0,
                    'actual_hours' => 0.0,
                    'actual_cost' => 0.0,
                    'task_actual_hours' => 0.0,
                ];
            }
            $hours = (float) ($row['hours'] ?? 0);
            $rate = (float) ($ratesByUser[(int) $row['user_id']] ?? 0);
            $rows[$projectId]['actual_hours'] += $hours;
            $rows[$projectId]['actual_cost'] += $hours * $rate;
        }

        foreach ($rows as &$row) {
            $row['actual_hours'] = round((float) $row['actual_hours'], 2);
            $row['actual_cost'] = round((float) $row['actual_cost'], 2);
            $row['plan_fact_ratio'] = $row['planned_hours'] > 0 && $row['actual_hours'] > 0
                ? round($row['actual_hours'] / $row['planned_hours'], 4)
                : null;
            $row['budget_burn'] = $row['budget_amount'] > 0
                ? round($row['actual_cost'] / $row['budget_amount'], 4)
                : null;
            $row['status'] = ((int) $row['overdue_tasks'] > 0 || (($row['plan_fact_ratio'] ?? 0) > 1.15) || (($row['budget_burn'] ?? 0) > 0.8))
                ? 'risk'
                : 'on_track';
        }
        unset($row);

        return array_values($rows);
    }

    private function controlStatus(float $hoursProgress, float $taskProgress, ?float $planFactRatio, int $overdueTasks, int $correctionTasks): string
    {
        if ($overdueTasks >= 3 || $hoursProgress < 0.75 || ($planFactRatio !== null && $planFactRatio > 1.3)) {
            return 'behind';
        }
        if ($overdueTasks > 0 || $correctionTasks > 0 || $hoursProgress < 0.9 || ($planFactRatio !== null && $planFactRatio > 1.15)) {
            return 'risk';
        }
        if ($taskProgress > 1.15 && $hoursProgress >= 0.95 && ($planFactRatio === null || $planFactRatio <= 1.0)) {
            return 'ahead';
        }

        return 'on_track';
    }

    private function controlStatusLabel(string $status): string
    {
        return match ($status) {
            'behind' => 'Отстаёт',
            'risk' => 'Риск',
            'ahead' => 'Обгоняет',
            default => 'В норме',
        };
    }

    private function controlBottlenecks(array $rows, array $departments, array $projects): array
    {
        $items = [];
        foreach ($rows as $row) {
            if (($row['status'] ?? '') === 'behind') {
                $items[] = [
                    'severity' => 'danger',
                    'type' => 'Сотрудник',
                    'title' => (string) $row['name'],
                    'metric' => $this->controlStatusLabel('behind'),
                    'reason' => 'часы ' . round(((float) $row['hours_progress']) * 100) . '%, просрочка ' . (int) $row['overdue_tasks'],
                ];
            } elseif (($row['status'] ?? '') === 'risk') {
                $items[] = [
                    'severity' => 'warning',
                    'type' => 'Сотрудник',
                    'title' => (string) $row['name'],
                    'metric' => $this->controlStatusLabel('risk'),
                    'reason' => 'часы ' . round(((float) $row['hours_progress']) * 100) . '%, возвраты ' . (int) $row['correction_tasks'],
                ];
            }
        }

        foreach ($departments as $department) {
            if ((int) ($department['behind_people'] ?? 0) > 0 || (int) ($department['risk_people'] ?? 0) > 1) {
                $items[] = [
                    'severity' => ((int) ($department['behind_people'] ?? 0) > 0) ? 'danger' : 'warning',
                    'type' => 'Отдел',
                    'title' => (string) $department['department'],
                    'metric' => (string) ((int) ($department['behind_people'] ?? 0) + (int) ($department['risk_people'] ?? 0)) . ' человек',
                    'reason' => 'контроль требует внимания руководителя',
                ];
            }
        }

        foreach ($projects as $project) {
            $ratio = $project['plan_fact_ratio'] ?? null;
            $burn = $project['budget_burn'] ?? null;
            if ((int) ($project['overdue_tasks'] ?? 0) > 0 || ($ratio !== null && $ratio > 1.15) || ($burn !== null && $burn > 0.8)) {
                $items[] = [
                    'severity' => ((int) ($project['overdue_tasks'] ?? 0) > 0 || ($ratio !== null && $ratio > 1.3)) ? 'danger' : 'warning',
                    'type' => 'Проект',
                    'title' => trim((string) $project['code'] . ' ' . (string) $project['title']),
                    'metric' => (int) ($project['overdue_tasks'] ?? 0) . ' проср.',
                    'reason' => 'план/факт ' . ($ratio === null ? '—' : (string) round($ratio * 100) . '%') . ', бюджет ' . ($burn === null ? '—' : (string) round($burn * 100) . '%'),
                ];
            }
        }

        usort($items, static function (array $a, array $b): int {
            $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];
            return ($rank[$a['severity']] ?? 3) <=> ($rank[$b['severity']] ?? 3);
        });

        return array_slice($items, 0, 12);
    }

    private function timeStats(string $monthStart, string $monthEnd): array
    {
        $stmt = $this->pdo->prepare('
            SELECT user_id,
                   SUM(CASE WHEN status = "locked" THEN minutes ELSE 0 END) AS locked_minutes,
                   SUM(CASE WHEN status = "locked" AND category = "task" THEN minutes ELSE 0 END) AS task_minutes
            FROM time_entries
            WHERE work_date BETWEEN ? AND ?
            GROUP BY user_id
        ');
        $stmt->execute([$monthStart, $monthEnd]);

        return $this->mapById($stmt->fetchAll(), 'user_id');
    }

    private function taskStats(string $monthStart, string $monthEnd): array
    {
        $stmt = $this->pdo->prepare('
            SELECT te.user_id,
                   COUNT(DISTINCT t.id) AS task_count,
                   SUM(CASE WHEN t.date_end IS NOT NULL AND t.date_end < ? AND t.status != "done" AND t.closed_at IS NULL THEN 1 ELSE 0 END) AS overdue_count,
                   SUM(CASE WHEN t.status = "correction" THEN 1 ELSE 0 END) AS correction_count,
                   SUM(COALESCE(t.planned_hours, 0)) AS planned_hours
            FROM time_entries te
            LEFT JOIN tasks t ON t.id = te.task_id
            WHERE te.status = "locked"
              AND te.category = "task"
              AND te.work_date BETWEEN ? AND ?
            GROUP BY te.user_id
        ');
        $stmt->execute([$monthEnd, $monthStart, $monthEnd]);

        return $this->mapById($stmt->fetchAll(), 'user_id');
    }

    private function reviews(string $monthStart): array
    {
        $stmt = $this->pdo->prepare('SELECT user_id, status FROM time_month_reviews WHERE period_start = ?');
        $stmt->execute([$monthStart]);

        return $this->mapById($stmt->fetchAll(), 'user_id');
    }

    private function projectBonusMap(string $monthStart, string $monthEnd, array $grades): array
    {
        $projects = $this->paidProjects();
        if ($projects === []) {
            return [];
        }

        $hoursStmt = $this->pdo->prepare('
            SELECT te.project_id, te.user_id, SUM(te.minutes) / 60.0 AS hours, p.grade
            FROM time_entries te
            JOIN users u ON u.id = te.user_id
            LEFT JOIN positions p ON p.id = u.position_id
            WHERE te.status = "locked"
              AND te.category = "task"
              AND te.project_id IS NOT NULL
              AND te.work_date BETWEEN ? AND ?
            GROUP BY te.project_id, te.user_id, p.grade
        ');
        $hoursStmt->execute([$monthStart, $monthEnd]);
        $byProject = [];
        foreach ($hoursStmt->fetchAll() as $row) {
            $projectId = (int) $row['project_id'];
            if (!isset($projects[$projectId])) {
                continue;
            }
            $coefficient = $this->coefficientForGrade((string) ($row['grade'] ?? ''), $grades);
            $hours = (float) ($row['hours'] ?? 0);
            $weighted = $hours * $coefficient;
            $byProject[$projectId][] = [
                'user_id' => (int) $row['user_id'],
                'hours' => $hours,
                'grade_coefficient' => $coefficient,
                'weighted' => $weighted,
            ];
        }

        $result = [];
        foreach ($projects as $projectId => $project) {
            $rows = $byProject[$projectId] ?? [];
            if ($rows === [] || (float) $project['project_fund'] <= 0) {
                continue;
            }
            $actual = array_sum(array_map(static fn (array $row): float => (float) $row['hours'], $rows));
            $weightedTotal = array_sum(array_map(static fn (array $row): float => (float) $row['weighted'], $rows));
            if ($weightedTotal <= 0) {
                continue;
            }
            $budget = (float) ($project['budget_hours'] ?: $project['approved_hours']);
            $overrunFactor = $budget > 0 && $actual > 0 ? min(1.0, $budget / $actual) : 1.0;
            foreach ($rows as $row) {
                $amount = round((float) $project['project_fund'] * ((float) $row['weighted'] / $weightedTotal) * $overrunFactor, 2);
                $userId = (int) $row['user_id'];
                $result[$userId]['amount'] = ($result[$userId]['amount'] ?? 0) + $amount;
                $result[$userId]['projects'][] = [
                    'project_id' => $projectId,
                    'code' => (string) $project['code'],
                    'hours' => round((float) $row['hours'], 2),
                    'amount' => $amount,
                    'overrun_factor' => round($overrunFactor, 4),
                ];
            }
        }

        return $result;
    }

    private function paidProjects(): array
    {
        $stmt = $this->pdo->query('
            SELECT p.id, p.code, p.title, ms.project_fund, ms.budget_hours,
                   COALESCE(labor.approved_hours, 0) AS approved_hours
            FROM project_motivation_settings ms
            JOIN projects p ON p.id = ms.project_id
            LEFT JOIN (
                SELECT project_id, SUM(COALESCE(director_hours, 0)) AS approved_hours
                FROM project_labor_estimates
                WHERE status = "director_approved"
                GROUP BY project_id
            ) labor ON labor.project_id = p.id
            WHERE ms.is_paid = 1 AND ms.project_fund > 0
        ');

        return $this->mapById($stmt->fetchAll(), 'id');
    }

    private function kpiScores(string $reviewStatus, float $lockedHours, float $expectedHours, array $taskStats, float $plannedHours, float $taskHours): array
    {
        $taskCount = max(0, (int) ($taskStats['task_count'] ?? 0));
        $overdue = max(0, (int) ($taskStats['overdue_count'] ?? 0));
        $correction = max(0, (int) ($taskStats['correction_count'] ?? 0));

        return [
            'timesheet_locked' => $reviewStatus === 'locked' ? 1.0 : ($reviewStatus === 'director_approved' ? 0.8 : 0.0),
            'timesheet_completeness' => $expectedHours > 0 ? min(1.0, $lockedHours / $expectedHours) : 0.0,
            'deadline' => $taskCount > 0 ? max(0.0, 1.0 - ($overdue / $taskCount)) : 1.0,
            'rework' => $taskCount > 0 ? max(0.0, 1.0 - ($correction / $taskCount)) : 1.0,
            'plan_fact' => $taskHours > 0 && $plannedHours > 0 ? min(1.0, $plannedHours / $taskHours) : ($taskHours > 0 ? 0.5 : 1.0),
        ];
    }

    private function weightedScore(array $scores, array $settings): float
    {
        $weights = [
            'timesheet_locked' => (float) ($settings['weight_timesheet_locked']['value'] ?? 0),
            'timesheet_completeness' => (float) ($settings['weight_timesheet_completeness']['value'] ?? 0),
            'deadline' => (float) ($settings['weight_deadline']['value'] ?? 0),
            'rework' => (float) ($settings['weight_rework']['value'] ?? 0),
            'plan_fact' => (float) ($settings['weight_plan_fact']['value'] ?? 0),
        ];
        $total = array_sum($weights);
        if ($total <= 0) {
            return 0.0;
        }

        $score = 0.0;
        foreach ($weights as $key => $weight) {
            $score += ((float) ($scores[$key] ?? 0)) * $weight;
        }

        return max(0.0, min(1.0, $score / $total));
    }

    private function coefficientForGrade(string $grade, array $grades): float
    {
        $grade = trim(mb_strtoupper($grade, 'UTF-8'));
        if ($grade === '') {
            return 1.0;
        }
        if (isset($grades[$grade])) {
            return (float) $grades[$grade]['coefficient'];
        }
        if (preg_match('/^[HН]/u', $grade) === 1) {
            return 0.0;
        }

        return 1.0;
    }

    private function workingDays(string $from, string $to): int
    {
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        $days = 0;
        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            if ((int) $date->format('N') <= 5) {
                $days++;
            }
        }

        return $days;
    }

    private function mapById(array $rows, string $key): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row[$key]] = $row;
        }

        return $map;
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            if ($this->isSqlite()) {
                $quotedTable = str_replace("'", "''", $table);
                $rows = $this->pdo->query("PRAGMA table_info('" . $quotedTable . "')")->fetchAll();
                foreach ($rows as $row) {
                    if ((string) ($row['name'] ?? '') === $column) {
                        return true;
                    }
                }

                return false;
            }

            $stmt = $this->pdo->prepare('
                SELECT COUNT(*) AS cnt
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND COLUMN_NAME = ?
            ');
            $stmt->execute([$table, $column]);

            return (int) ($stmt->fetch()['cnt'] ?? 0) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function decimal(mixed $value): float
    {
        return (float) str_replace([',', ' '], ['.', ''], (string) $value);
    }

    private function dateOrNull(mixed $value): ?string
    {
        $date = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
    }

    private function isSqlite(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }
}
