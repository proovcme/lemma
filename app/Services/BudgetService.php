<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class BudgetService
{
    private const PAYMENT_STATUSES = ['planned', 'invoiced', 'received', 'cancelled'];

    public function __construct(private PDO $pdo)
    {
    }

    public function dashboard(int $year, int $projectId = 0): array
    {
        $year = max(2000, min(2100, $year));
        $projects = $this->projects();
        $payments = $this->payments($projectId);
        $cashflow = $this->cashflow($year, $payments);
        $portfolioBudget = array_sum(array_column($projects, 'budget_amount'));
        $plannedCost = array_sum(array_column($projects, 'planned_cost'));
        $plannedProfit = array_sum(array_column($projects, 'planned_profit'));
        $plannedBonus = array_sum(array_column($projects, 'planned_bonus'));
        $actualCost = array_sum(array_column($projects, 'actual_cost'));
        $plannedPayments = array_sum(array_column($projects, 'planned_payments'));
        $receivedPayments = array_sum(array_column($projects, 'received_payments'));

        return [
            'year' => $year,
            'projects' => $projects,
            'payments' => $payments,
            'cashflow' => $cashflow,
            'metrics' => [
                'portfolio_budget' => round($portfolioBudget, 2),
                'planned_cost' => round($plannedCost, 2),
                'planned_profit' => round($plannedProfit, 2),
                'planned_bonus' => round($plannedBonus, 2),
                'planned_payments' => round($plannedPayments, 2),
                'received_payments' => round($receivedPayments, 2),
                'receivable' => round(max(0, $portfolioBudget - $receivedPayments), 2),
                'actual_cost' => round($actualCost, 2),
                'budget_margin' => round($portfolioBudget - $actualCost, 2),
            ],
        ];
    }

    public function payments(int $projectId = 0): array
    {
        $sql = 'SELECT ps.*, p.code AS project_code, p.title AS project_title
            FROM project_payment_schedule ps
            INNER JOIN projects p ON p.id = ps.project_id';
        $params = [];
        if ($projectId > 0) {
            $sql .= ' WHERE ps.project_id = ?';
            $params[] = $projectId;
        }
        $sql .= ' ORDER BY ps.planned_date, ps.sort_order, ps.id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function saveProjectBudget(int $projectId, mixed $costThousand, mixed $profitThousand, mixed $bonusThousand, string $comment, mixed $totalThousand = ''): void
    {
        $cost = $this->optionalDecimal($costThousand);
        $profit = $this->optionalDecimal($profitThousand);
        $bonus = $this->optionalDecimal($bonusThousand);
        $explicitTotal = trim((string) $totalThousand) === '' ? null : $this->nullableDecimal($totalThousand);
        if ($cost === null || $profit === null || $bonus === null || (trim((string) $totalThousand) !== '' && $explicitTotal === null)) {
            throw new \InvalidArgumentException('В бюджете можно вводить только неотрицательные числа.');
        }
        $partsTotal = $cost + $profit + $bonus;
        $budget = $explicitTotal ?? $partsTotal;
        if ($projectId <= 0 || min($cost, $profit, $bonus, $budget) < 0 || $budget <= 0 || $partsTotal > $budget + 0.00001 || !$this->projectExists($projectId)) {
            throw new \InvalidArgumentException($partsTotal > $budget ? 'Сумма частей не может быть больше общего бюджета.' : 'Укажите общий бюджет или заполните хотя бы одну его часть.');
        }
        $stmt = $this->pdo->prepare('UPDATE projects SET budget_manual_thousand = ?, budget_cost_thousand = ?, budget_profit_thousand = ?, budget_bonus_thousand = ?, budget_comment = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$budget, $cost, $profit, $bonus, trim($comment) ?: null, $projectId]);
    }

    private function optionalDecimal(mixed $value): ?float
    {
        if (trim((string) $value) === '') {
            return 0.0;
        }
        return $this->nullableDecimal($value);
    }

    public function savePayment(?int $id, array $input, int $actorId): int
    {
        $projectId = (int) ($input['project_id'] ?? 0);
        $name = trim((string) ($input['payment_name'] ?? ''));
        $plannedDate = $this->date((string) ($input['planned_date'] ?? ''));
        $plannedAmount = $this->decimal($input['planned_amount'] ?? 0);
        $status = (string) ($input['status'] ?? 'planned');
        $invoiceDate = $this->date((string) ($input['invoice_date'] ?? ''), true);
        $actualDate = $this->date((string) ($input['actual_date'] ?? ''), true);
        $actualAmount = $this->decimal($input['actual_amount'] ?? 0);
        if (!$this->projectExists($projectId) || $name === '' || $plannedDate === null || $plannedAmount <= 0) {
            throw new \InvalidArgumentException('Укажите проект, этап платежа, плановую дату и сумму больше нуля.');
        }
        if (!in_array($status, self::PAYMENT_STATUSES, true)) {
            throw new \InvalidArgumentException('Неизвестный статус платежа.');
        }
        if ($status === 'received' && ($actualDate === null || $actualAmount <= 0)) {
            throw new \InvalidArgumentException('Для полученного платежа укажите фактическую дату и сумму.');
        }
        if ($actualAmount < 0) {
            throw new \InvalidArgumentException('Фактическая сумма не может быть отрицательной.');
        }
        $values = [$projectId, $name, $plannedDate, $plannedAmount, $status, $invoiceDate, $actualDate, $actualAmount, trim((string) ($input['comment'] ?? '')) ?: null, (int) ($input['sort_order'] ?? 100), $actorId];
        if ($id && $id > 0) {
            $stmt = $this->pdo->prepare('UPDATE project_payment_schedule SET project_id=?, payment_name=?, planned_date=?, planned_amount=?, status=?, invoice_date=?, actual_date=?, actual_amount=?, comment=?, sort_order=?, updated_by=?, updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $stmt->execute([...$values, $id]);
            if ($stmt->rowCount() === 0 && !$this->paymentExists($id)) {
                throw new \InvalidArgumentException('Платёж не найден.');
            }
            return $id;
        }
        $stmt = $this->pdo->prepare('INSERT INTO project_payment_schedule (project_id,payment_name,planned_date,planned_amount,status,invoice_date,actual_date,actual_amount,comment,sort_order,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([...$values, $actorId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function deletePayment(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM project_payment_schedule WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            throw new \InvalidArgumentException('Платёж не найден.');
        }
    }

    private function projects(): array
    {
        $withStaffing = StaffingService::rateSchemaAvailable($this->pdo);
        $rate = $withStaffing
            ? 'COALESCE(spr.hourly_rate, er.hourly_rate, sgr.hourly_rate, cfo.hourly_rate, 0)'
            : 'COALESCE(er.hourly_rate, cfo.hourly_rate, 0)';
        $staffingJoins = $withStaffing ? "
            LEFT JOIN staffing_periods sp ON sp.status = 'locked' AND substr(sp.month_start, 1, 7) = substr(te.work_date, 1, 7)
            LEFT JOIN staffing_personal_rates spr ON spr.period_id = sp.id AND spr.user_id = te.user_id
            LEFT JOIN staffing_group_rates sgr ON sgr.period_id = sp.id AND sgr.department_code = u.department" : '';
        $sql = 'SELECT p.id, p.code, p.title, p.status, p.start_date, p.finish_date, p.budget_manual_thousand, p.budget_cost_thousand, p.budget_profit_thousand, p.budget_bonus_thousand, p.budget_comment,
                COALESCE(pay.planned_payments, 0) AS planned_payments,
                COALESCE(pay.received_payments, 0) AS received_payments,
                pay.next_payment_date,
                COALESCE(labor.labor_cost, 0) + COALESCE(uts.uts_cost, 0) AS actual_cost
            FROM projects p
            LEFT JOIN (
                SELECT project_id,
                    SUM(CASE WHEN status <> "cancelled" THEN planned_amount ELSE 0 END) AS planned_payments,
                    SUM(CASE WHEN status = "received" THEN actual_amount ELSE 0 END) AS received_payments,
                    MIN(CASE WHEN status IN ("planned", "invoiced") THEN planned_date ELSE NULL END) AS next_payment_date
                FROM project_payment_schedule GROUP BY project_id
            ) pay ON pay.project_id = p.id
            LEFT JOIN (
                SELECT te.project_id, SUM((te.minutes / 60.0) * ' . $rate . ') AS labor_cost
                FROM time_entries te
                LEFT JOIN users u ON u.id = te.user_id
                ' . $staffingJoins . '
                LEFT JOIN employee_rates er ON er.user_id = te.user_id
                LEFT JOIN cfo_rates cfo ON cfo.dept_code = u.department
                WHERE te.project_id IS NOT NULL GROUP BY te.project_id
            ) labor ON labor.project_id = p.id
            LEFT JOIN (SELECT project_id, SUM(amount) AS uts_cost FROM project_uts_facts GROUP BY project_id) uts ON uts.project_id = p.id
            ORDER BY CASE WHEN p.status = "active" THEN 0 ELSE 1 END, p.code';
        $rows = $this->pdo->query($sql)->fetchAll();
        foreach ($rows as &$row) {
            $row['budget_amount'] = (float) ($row['budget_manual_thousand'] ?? 0) * 1000;
            $row['planned_cost'] = (float) ($row['budget_cost_thousand'] ?? 0) * 1000;
            $row['planned_profit'] = (float) ($row['budget_profit_thousand'] ?? 0) * 1000;
            $row['planned_bonus'] = (float) ($row['budget_bonus_thousand'] ?? 0) * 1000;
            $row['planned_payments'] = (float) $row['planned_payments'];
            $row['received_payments'] = (float) $row['received_payments'];
            $row['actual_cost'] = (float) $row['actual_cost'];
            $row['receivable'] = max(0, $row['budget_amount'] - $row['received_payments']);
            $row['budget_remaining'] = $row['budget_amount'] - $row['actual_cost'];
        }
        unset($row);
        return $rows;
    }

    private function cashflow(int $year, array $payments): array
    {
        $rows = [];
        for ($month = 1; $month <= 12; $month++) {
            $key = sprintf('%04d-%02d', $year, $month);
            $rows[$key] = ['month' => $key, 'planned_income' => 0.0, 'actual_income' => 0.0, 'staffing_cost' => 0.0];
        }
        foreach ($payments as $payment) {
            $plannedKey = substr((string) $payment['planned_date'], 0, 7);
            if (isset($rows[$plannedKey]) && (string) $payment['status'] !== 'cancelled') {
                $rows[$plannedKey]['planned_income'] += (float) $payment['planned_amount'];
            }
            $actualKey = substr((string) ($payment['actual_date'] ?? ''), 0, 7);
            if (isset($rows[$actualKey]) && (string) $payment['status'] === 'received') {
                $rows[$actualKey]['actual_income'] += (float) $payment['actual_amount'];
            }
        }
        $stmt = $this->pdo->prepare('SELECT sp.month_start, sp.payroll_burden_pct, sp.overhead_pct,
                COALESCE(SUM(CASE WHEN r.status <> "reduction" THEN r.monthly_fot ELSE 0 END), 0) AS direct_fot
            FROM staffing_periods sp
            LEFT JOIN staffing_plan_rows r ON r.period_id = sp.id
            WHERE sp.status = "locked" AND sp.month_start >= ? AND sp.month_start <= ?
            GROUP BY sp.id, sp.month_start, sp.payroll_burden_pct, sp.overhead_pct');
        $stmt->execute([sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)]);
        foreach ($stmt->fetchAll() as $staffing) {
            $key = substr((string) $staffing['month_start'], 0, 7);
            if (!isset($rows[$key])) continue;
            $direct = (float) $staffing['direct_fot'];
            $rows[$key]['staffing_cost'] = $direct
                + $direct * (float) $staffing['payroll_burden_pct'] / 100
                + $direct * (float) $staffing['overhead_pct'] / 100;
        }
        $max = 0.0;
        $cumulative = 0.0;
        foreach ($rows as &$row) {
            $row['plan_net'] = $row['planned_income'] - $row['staffing_cost'];
            $row['actual_net'] = $row['actual_income'] - $row['staffing_cost'];
            $cumulative += $row['actual_net'];
            $row['cumulative'] = $cumulative;
            $max = max($max, $row['planned_income'], $row['actual_income'], $row['staffing_cost']);
        }
        unset($row);
        foreach ($rows as &$row) {
            $row['planned_percent'] = $max > 0 ? round($row['planned_income'] * 100 / $max, 2) : 0;
            $row['actual_percent'] = $max > 0 ? round($row['actual_income'] * 100 / $max, 2) : 0;
            $row['cost_percent'] = $max > 0 ? round($row['staffing_cost'] * 100 / $max, 2) : 0;
        }
        unset($row);
        return array_values($rows);
    }

    private function projectExists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM projects WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    private function paymentExists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM project_payment_schedule WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    private function date(string $value, bool $nullable = false): ?string
    {
        $value = trim($value);
        if ($value === '' && $nullable) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function decimal(mixed $value): float
    {
        return (float) str_replace([' ', ','], ['', '.'], trim((string) $value));
    }

    private function nullableDecimal(mixed $value): ?float
    {
        return trim((string) $value) === '' ? null : $this->decimal($value);
    }
}
