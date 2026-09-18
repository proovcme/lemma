<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class ProjectControlService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(int $projectId, bool $includeFinance): array
    {
        $tasks = $this->taskStats($projectId);
        $time = $this->timeStats($projectId);
        $quality = $this->quality($tasks, $time);
        $work = $this->work($tasks, $time);
        $result = [
            'quality' => $quality,
            'work' => $work,
            'data' => [
                'tasks_without_pp' => (int) ($tasks['tasks_without_pp'] ?? 0),
                'tasks_without_btp' => (int) ($tasks['tasks_without_btp'] ?? 0),
                'non_delegation_tasks' => (int) ($tasks['non_delegation_tasks'] ?? 0),
            ],
            'risks' => $this->risks($tasks, $time, $quality, null),
        ];

        if ($includeFinance) {
            $budget = $this->budget($projectId, $tasks, $time);
            $result['budget'] = $budget;
            $result['risks'] = $this->risks($tasks, $time, $quality, $budget);
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function taskStats(int $projectId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT
                COUNT(*) AS total_tasks,
                SUM(CASE WHEN COALESCE(t.task_type, "work") <> "delegation" THEN 1 ELSE 0 END) AS non_delegation_tasks,
                SUM(CASE WHEN t.status = "done" THEN 1 ELSE 0 END) AS done_tasks,
                SUM(CASE WHEN t.status IN ("new", "in_progress", "review", "correction", "blocked", "overdue") THEN 1 ELSE 0 END) AS open_tasks,
                SUM(CASE WHEN t.status = "review" THEN 1 ELSE 0 END) AS review_tasks,
                SUM(CASE WHEN t.status = "correction" THEN 1 ELSE 0 END) AS correction_tasks,
                SUM(CASE WHEN t.status = "blocked" THEN 1 ELSE 0 END) AS blocked_tasks,
                SUM(CASE WHEN t.status = "overdue" OR (t.status <> "done" AND t.date_end IS NOT NULL AND t.date_end < CURRENT_DATE) THEN 1 ELSE 0 END) AS overdue_tasks,
                SUM(CASE WHEN COALESCE(t.task_type, "work") <> "delegation" AND NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id) THEN COALESCE(t.planned_hours, 0) ELSE 0 END) AS planned_hours,
                SUM(CASE WHEN COALESCE(t.task_type, "work") <> "delegation" AND NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id) THEN COALESCE(t.actual_hours, 0) ELSE 0 END) AS task_actual_hours,
                SUM(CASE WHEN COALESCE(t.task_type, "work") <> "delegation" AND t.pp_code_id IS NULL THEN 1 ELSE 0 END) AS tasks_without_pp,
                SUM(CASE WHEN COALESCE(t.task_type, "work") <> "delegation" AND t.btp_code_id IS NULL THEN 1 ELSE 0 END) AS tasks_without_btp,
                COALESCE(ROUND(AVG(COALESCE(t.progress, 0)), 0), 0) AS avg_progress
            FROM tasks t
            WHERE t.project_id = ?
        ');
        $stmt->execute([$projectId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string,mixed>
     */
    private function timeStats(int $projectId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT
                COALESCE(ROUND(SUM(te.minutes) / 60.0, 2), 0) AS time_hours,
                COALESCE(ROUND(SUM(CASE WHEN te.phase IN ("correction", "repeat_review") THEN te.minutes ELSE 0 END) / 60.0, 2), 0) AS rework_hours,
                COALESCE(ROUND(SUM(CASE WHEN te.task_id IS NULL THEN te.minutes ELSE 0 END) / 60.0, 2), 0) AS no_task_hours,
                COALESCE(ROUND(SUM(CASE WHEN COALESCE(te.status, "draft") IN ("approved", "locked") THEN te.minutes ELSE 0 END) / 60.0, 2), 0) AS approved_hours
            FROM time_entries te
            WHERE te.project_id = ?
        ');
        $stmt->execute([$projectId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed> $tasks
     * @param array<string,mixed> $time
     * @return array<string,mixed>
     */
    private function work(array $tasks, array $time): array
    {
        $planned = (float) ($tasks['planned_hours'] ?? 0);
        $taskActual = (float) ($tasks['task_actual_hours'] ?? 0);
        $timeHours = (float) ($time['time_hours'] ?? 0);
        $actual = max($taskActual, $timeHours);
        $remaining = max(0.0, $planned - $actual);

        return [
            'planned_hours' => round($planned, 1),
            'task_actual_hours' => round($taskActual, 1),
            'time_hours' => round($timeHours, 1),
            'actual_hours' => round($actual, 1),
            'remaining_hours' => round($remaining, 1),
            'plan_fact_ratio' => $planned > 0 ? round($actual / $planned, 2) : null,
            'open_tasks' => (int) ($tasks['open_tasks'] ?? 0),
            'done_tasks' => (int) ($tasks['done_tasks'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $tasks
     * @param array<string,mixed> $time
     * @return array<string,mixed>
     */
    private function quality(array $tasks, array $time): array
    {
        $total = max(0, (int) ($tasks['non_delegation_tasks'] ?? 0));
        $open = max(0, (int) ($tasks['open_tasks'] ?? 0));
        $done = max(0, (int) ($tasks['done_tasks'] ?? 0));
        $overdue = max(0, (int) ($tasks['overdue_tasks'] ?? 0));
        $correction = max(0, (int) ($tasks['correction_tasks'] ?? 0));
        $review = max(0, (int) ($tasks['review_tasks'] ?? 0));
        $withoutBtp = max(0, (int) ($tasks['tasks_without_btp'] ?? 0));
        $planned = (float) ($tasks['planned_hours'] ?? 0);
        $actual = max((float) ($tasks['task_actual_hours'] ?? 0), (float) ($time['time_hours'] ?? 0));

        $firstPassRatio = ($done + $correction) > 0 ? $done / ($done + $correction) : 1.0;
        $deadlineScore = $open > 0 ? max(0.0, 1.0 - ($overdue / $open)) : 1.0;
        $planFactScore = 1.0;
        if ($planned > 0 && $actual > $planned) {
            $planFactScore = max(0.0, 1.0 - (($actual / $planned) - 1.0) / 0.5);
        }
        $dataScore = $total > 0 ? max(0.0, 1.0 - ($withoutBtp / $total)) : 1.0;
        $reviewFlowScore = $open > 0 ? max(0.0, 1.0 - (($review + $correction) / max(1, $open)) * 0.5) : 1.0;
        $score = (int) round(($firstPassRatio * 0.25 + $deadlineScore * 0.25 + $planFactScore * 0.25 + $dataScore * 0.15 + $reviewFlowScore * 0.10) * 100);

        return [
            'score' => max(0, min(100, $score)),
            'status' => $score >= 80 ? 'green' : ($score >= 60 ? 'yellow' : 'red'),
            'first_pass_ratio' => round($firstPassRatio * 100, 0),
            'deadline_score' => round($deadlineScore * 100, 0),
            'plan_fact_score' => round($planFactScore * 100, 0),
            'data_score' => round($dataScore * 100, 0),
            'review_flow_score' => round($reviewFlowScore * 100, 0),
            'overdue_tasks' => $overdue,
            'correction_tasks' => $correction,
            'review_tasks' => $review,
            'rework_hours' => round((float) ($time['rework_hours'] ?? 0), 1),
        ];
    }

    /**
     * @param array<string,mixed> $tasks
     * @param array<string,mixed> $time
     * @return array<string,mixed>
     */
    private function budget(int $projectId, array $tasks, array $time): array
    {
        $budgetThousand = $this->manualBudget($projectId);
        $timeCost = $this->timeCost($projectId);
        $utsCost = $this->utsCost($projectId);
        $plannedHours = (float) ($tasks['planned_hours'] ?? 0);
        $actualHours = max((float) ($tasks['task_actual_hours'] ?? 0), (float) ($time['time_hours'] ?? 0));
        $remainingHours = max(0.0, $plannedHours - $actualHours);
        $avgRate = $actualHours > 0 ? ($timeCost / $actualHours) : 0.0;
        $forecastTimeCost = $timeCost + ($remainingHours * $avgRate);
        $actualTotal = ($timeCost + $utsCost) / 1000.0;
        $forecastTotal = ($forecastTimeCost + $utsCost) / 1000.0;

        return [
            'manual_thousand' => $budgetThousand,
            'time_actual_thousand' => round($timeCost / 1000.0, 2),
            'uts_actual_thousand' => round($utsCost / 1000.0, 2),
            'actual_total_thousand' => round($actualTotal, 2),
            'forecast_total_thousand' => round($forecastTotal, 2),
            'remaining_thousand' => $budgetThousand !== null ? round($budgetThousand - $actualTotal, 2) : null,
            'forecast_remaining_thousand' => $budgetThousand !== null ? round($budgetThousand - $forecastTotal, 2) : null,
            'burn_percent' => $budgetThousand !== null && $budgetThousand > 0 ? round($actualTotal * 100 / $budgetThousand, 0) : null,
        ];
    }

    private function manualBudget(int $projectId): ?float
    {
        try {
            $stmt = $this->pdo->prepare('SELECT budget_manual_thousand FROM projects WHERE id = ? LIMIT 1');
            $stmt->execute([$projectId]);
            $value = $stmt->fetchColumn();
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'budget_manual_thousand') !== false) {
                return null;
            }
            throw $e;
        }

        return $value === false || $value === null || $value === '' ? null : (float) $value;
    }

    private function timeCost(int $projectId): float
    {
        $withStaffing = StaffingService::rateSchemaAvailable($this->pdo);
        $rate = $withStaffing
            ? 'COALESCE(spr.hourly_rate, er.hourly_rate, sgr.hourly_rate, cfo.hourly_rate, 0)'
            : 'COALESCE(er.hourly_rate, cfo.hourly_rate, 0)';
        $staffingJoins = $withStaffing ? "
            LEFT JOIN staffing_periods sp ON sp.status = 'locked' AND substr(sp.month_start, 1, 7) = substr(te.work_date, 1, 7)
            LEFT JOIN staffing_personal_rates spr ON spr.period_id = sp.id AND spr.user_id = te.user_id
            LEFT JOIN staffing_group_rates sgr ON sgr.period_id = sp.id AND sgr.department_code = u.department" : '';
        $stmt = $this->pdo->prepare('
            SELECT COALESCE(SUM((te.minutes / 60.0) * ' . $rate . '), 0) AS cost
            FROM time_entries te
            LEFT JOIN users u ON u.id = te.user_id
            ' . $staffingJoins . '
            LEFT JOIN employee_rates er ON er.user_id = te.user_id
            LEFT JOIN cfo_rates cfo ON cfo.dept_code = u.department
            WHERE te.project_id = ?
        ');
        $stmt->execute([$projectId]);

        return (float) $stmt->fetchColumn();
    }

    private function utsCost(int $projectId): float
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM project_uts_facts WHERE project_id = ?');
            $stmt->execute([$projectId]);
            return (float) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'project_uts_facts') !== false) {
                return 0.0;
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $tasks
     * @param array<string,mixed> $time
     * @param array<string,mixed> $quality
     * @param array<string,mixed>|null $budget
     * @return list<array{level:string,title:string,detail:string}>
     */
    private function risks(array $tasks, array $time, array $quality, ?array $budget): array
    {
        $risks = [];
        if ((int) ($tasks['overdue_tasks'] ?? 0) > 0) {
            $risks[] = ['level' => 'red', 'title' => 'Сроки горят', 'detail' => 'Просрочено задач: ' . (int) $tasks['overdue_tasks']];
        }
        if ((int) ($tasks['correction_tasks'] ?? 0) > 0 || (float) ($time['rework_hours'] ?? 0) > 0) {
            $risks[] = ['level' => 'yellow', 'title' => 'Есть возвраты', 'detail' => 'Корректировки: ' . (int) ($tasks['correction_tasks'] ?? 0) . ', часы переделок: ' . round((float) ($time['rework_hours'] ?? 0), 1)];
        }
        if ((int) ($tasks['tasks_without_btp'] ?? 0) > 0) {
            $risks[] = ['level' => 'yellow', 'title' => 'Не заполнены БТП', 'detail' => 'Задач без строки списания: ' . (int) $tasks['tasks_without_btp']];
        }
        if ($budget !== null && $budget['forecast_remaining_thousand'] !== null && (float) $budget['forecast_remaining_thousand'] < 0) {
            $risks[] = ['level' => 'red', 'title' => 'Прогноз выше бюджета', 'detail' => 'Прогнозный перерасход: ' . abs((float) $budget['forecast_remaining_thousand']) . ' тыс. руб.'];
        }
        if ((int) ($quality['score'] ?? 100) < 60) {
            $risks[] = ['level' => 'red', 'title' => 'Низкий индекс качества', 'detail' => 'Индекс контроля: ' . (int) $quality['score'] . '%'];
        }

        return $risks;
    }
}
