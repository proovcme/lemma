<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class ProjectPortfolioService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function dashboard(): array
    {
        $sql = 'SELECT p.id, p.kind, p.code, p.title, p.stage, p.status, p.start_date, p.finish_date,
                p.budget_manual_thousand, p.budget_cost_thousand, p.budget_profit_thousand, p.budget_bonus_thousand,
                gip.name AS gip_name, rp.name AS rp_name,
                COALESCE(pre.expected_thousand, 0) AS expected_thousand,
                COALESCE(task.tasks_total, 0) AS tasks_total, COALESCE(task.tasks_done, 0) AS tasks_done,
                COALESCE(task.tasks_overdue, 0) AS tasks_overdue
            FROM projects p
            LEFT JOIN users gip ON gip.id = p.gip_user_id
            LEFT JOIN users rp ON rp.id = p.rp_user_id
            LEFT JOIN (SELECT project_id, SUM(CASE WHEN status = "director_approved" THEN director_cost_thousand ELSE 0 END) AS expected_thousand FROM project_labor_estimates GROUP BY project_id) pre ON pre.project_id = p.id
            LEFT JOIN (SELECT project_id, COUNT(*) AS tasks_total, SUM(status = "done") AS tasks_done, SUM(status <> "done" AND date_end < CURRENT_DATE) AS tasks_overdue FROM tasks GROUP BY project_id) task ON task.project_id = p.id
            ORDER BY CASE WHEN COALESCE(p.kind, "project") = "preproject" THEN 0 ELSE 1 END, CASE WHEN p.status = "active" THEN 0 ELSE 1 END, p.finish_date, p.code';
        $rows = $this->pdo->query($sql)->fetchAll();
        $metrics = ['expected_count' => 0, 'live_count' => 0, 'expected_amount' => 0.0, 'live_budget' => 0.0, 'overdue' => 0];
        foreach ($rows as &$row) {
            $row['is_expected'] = (string) ($row['kind'] ?? 'project') === 'preproject';
            $row['source'] = $row['is_expected'] ? 'preproject' : 'project';
            $row['amount_thousand'] = $row['is_expected'] ? (float) $row['expected_thousand'] : (float) ($row['budget_manual_thousand'] ?? 0);
            if ($row['is_expected']) {
                $metrics['expected_count']++;
                $metrics['expected_amount'] += $row['amount_thousand'];
            } else {
                $metrics['live_count']++;
                $metrics['live_budget'] += $row['amount_thousand'];
            }
            $metrics['overdue'] += (int) $row['tasks_overdue'];
        }
        unset($row);

        $calculatorRows = $this->pdo->query('SELECT c.snapshot_id, c.title, c.amount_thousand, c.area_m2, c.start_date, c.finish_date,
                c.status, c.created_at, u.name AS creator_name
            FROM calculator_portfolio_entries c
            JOIN users u ON u.id = c.created_by
            WHERE c.status = "expected"
            ORDER BY COALESCE(c.finish_date, "9999-12-31"), c.created_at DESC')->fetchAll();
        foreach ($calculatorRows as $calculatorRow) {
            $rows[] = [
                'id' => (string) $calculatorRow['snapshot_id'],
                'kind' => 'calculator',
                'source' => 'calculator',
                'is_expected' => true,
                'code' => 'CALC',
                'title' => (string) $calculatorRow['title'],
                'stage' => null,
                'status' => (string) $calculatorRow['status'],
                'start_date' => $calculatorRow['start_date'],
                'finish_date' => $calculatorRow['finish_date'],
                'area_m2' => $calculatorRow['area_m2'],
                'gip_name' => (string) $calculatorRow['creator_name'],
                'rp_name' => null,
                'amount_thousand' => (float) $calculatorRow['amount_thousand'],
                'tasks_total' => 0,
                'tasks_done' => 0,
                'tasks_overdue' => 0,
            ];
            $metrics['expected_count']++;
            $metrics['expected_amount'] += (float) $calculatorRow['amount_thousand'];
        }

        usort($rows, static function (array $left, array $right): int {
            $leftExpected = !empty($left['is_expected']) ? 0 : 1;
            $rightExpected = !empty($right['is_expected']) ? 0 : 1;
            if ($leftExpected !== $rightExpected) {
                return $leftExpected <=> $rightExpected;
            }
            return strcmp((string) ($left['finish_date'] ?? '9999-12-31'), (string) ($right['finish_date'] ?? '9999-12-31'));
        });
        return ['rows' => $rows, 'metrics' => $metrics];
    }
}
