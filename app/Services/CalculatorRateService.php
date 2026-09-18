<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class CalculatorRateService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function current(): array
    {
        $period = null;
        try {
            $period = $this->pdo->query("SELECT id, month_start, working_hours FROM staffing_periods WHERE status = 'locked' ORDER BY month_start DESC, revision DESC LIMIT 1")->fetch();
        } catch (\Throwable) {
            $period = null;
        }

        if ($period) {
            $stmt = $this->pdo->prepare('SELECT department_code, hourly_rate, total_fte, total_fot FROM staffing_group_rates WHERE period_id = ? ORDER BY department_code');
            $stmt->execute([(int) $period['id']]);
            $rates = [];
            foreach ($stmt->fetchAll() as $row) {
                $monthly = (float) $row['total_fte'] > 0 ? (float) $row['total_fot'] / (float) $row['total_fte'] : (float) $row['hourly_rate'] * (float) $period['working_hours'];
                if ($monthly > 0) $rates[] = $this->rate((string) $row['department_code'], $monthly);
            }
            if ($rates) return ['rates' => $rates, 'source' => 'staffing', 'period' => (string) $period['month_start'], 'working_hours' => (float) $period['working_hours']];
        }

        $hours = $period ? (float) $period['working_hours'] : 168.0;
        $rates = [];
        try {
            foreach ($this->pdo->query('SELECT dept_code, hourly_rate FROM cfo_rates WHERE hourly_rate > 0 ORDER BY dept_code')->fetchAll() as $row) {
                $rates[] = $this->rate((string) $row['dept_code'], (float) $row['hourly_rate'] * $hours);
            }
        } catch (\Throwable) {
            $rates = [];
        }
        return ['rates' => $rates, 'source' => $rates ? 'cfo_rates' : 'none', 'period' => $period['month_start'] ?? null, 'working_hours' => $hours];
    }

    private function rate(string $code, float $monthly): array
    {
        $normalized = $code === 'КС-СКС' ? 'СКС' : $code;
        return ['code' => $normalized, 'group' => $normalized, 'monthlySalaryMedian' => round($monthly, 2), 'comment' => 'Единая ставка из Лемме'];
    }
}
