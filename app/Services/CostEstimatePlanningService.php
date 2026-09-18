<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class CostEstimatePlanningService
{
    private const HOURS_PER_DAY = 8.0;

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function enrichRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $hours = $this->planningHours($row);
            $money = $this->planningMoney($row, $hours['hours']);
            $sbc = round((float) ($row['sbc_reference_cost'] ?? 0), 2);
            $delta = round($money - $sbc, 2);

            $row['planning_hours'] = $hours['hours'];
            $row['planning_days'] = round($hours['hours'] / self::HOURS_PER_DAY, 2);
            $row['planning_source'] = $hours['source'];
            $row['planning_source_label'] = $hours['label'];
            $row['planning_money_thousand'] = $money;
            $row['planning_sbc_thousand'] = $sbc;
            $row['planning_delta_thousand'] = $delta;
            $row['planning_delta_percent'] = $sbc > 0 ? round($delta / $sbc * 100, 1) : null;
            $row['planning_sbc_state'] = $this->sbcState($sbc, $delta);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<string, mixed> $preproject
     * @param array<int, array<string, mixed>> $sections
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function summary(array $preproject, array $sections, array $rows): array
    {
        $hours = 0.0;
        $money = 0.0;
        $sbc = 0.0;
        $approved = 0;
        $missingSbc = 0;
        $coveredRows = 0;

        $rowSectionIds = [];
        foreach ($rows as $row) {
            $sectionId = (int) ($row['section_id'] ?? 0);
            if ($sectionId > 0) {
                $rowSectionIds[$sectionId] = true;
            }
        }

        foreach ($sections as $section) {
            $sectionId = (int) ($section['id'] ?? 0);
            if ($sectionId > 0 && isset($rowSectionIds[$sectionId])) {
                continue;
            }
            $sbc += (float) ($section['sbc_reference_cost'] ?? 0);
        }

        foreach ($rows as $row) {
            $hours += (float) ($row['planning_hours'] ?? 0);
            $money += (float) ($row['planning_money_thousand'] ?? 0);
            $rowSbc = (float) ($row['planning_sbc_thousand'] ?? $row['sbc_reference_cost'] ?? 0);
            $sbc += $rowSbc;
            if ((string) ($row['status'] ?? '') === 'director_approved') {
                $approved++;
            }
            if ($rowSbc > 0) {
                $coveredRows++;
            } else {
                $missingSbc++;
            }
        }

        $dates = $this->dateRange($preproject, $sections);
        $rowCount = count($rows);
        $delta = round($money - $sbc, 2);
        $coverage = $rowCount > 0 ? round($coveredRows / $rowCount * 100, 1) : 0.0;

        return [
            'sections' => count($sections),
            'labor_rows' => $rowCount,
            'approved_rows' => $approved,
            'pending_rows' => max(0, $rowCount - $approved),
            'hours' => round($hours, 2),
            'days' => round($hours / self::HOURS_PER_DAY, 2),
            'money' => round($money, 2),
            'sbc' => round($sbc, 2),
            'delta' => $delta,
            'delta_percent' => $sbc > 0 ? round($delta / $sbc * 100, 1) : null,
            'sbc_coverage_percent' => $coverage,
            'sbc_missing_rows' => $missingSbc,
            'date_start' => $dates['start'],
            'date_end' => $dates['end'],
            'calendar_days' => $dates['days'],
            'date_source' => $dates['source'],
            'health' => $this->health($rowCount, $coverage, $sbc, $delta),
        ];
    }

    /**
     * @return array{overall: array<string, mixed>, by_discipline: array<int, array<string, mixed>>, by_type: array<int, array<string, mixed>>}
     */
    public function taskStatistics(PDO $pdo, array $user, int $limit = 5000): array
    {
        $limit = max(100, min(20000, $limit));
        $where = [
            "(t.status = 'done' OR t.closed_at IS NOT NULL)",
            "COALESCE(t.task_type, 'work') NOT IN ('review', 'delegation')",
            'NOT EXISTS (SELECT 1 FROM tasks child WHERE child.parent_id = t.id)',
        ];
        $params = [];

        if (RoleService::isAny($user['role'] ?? null, [RoleService::DEPARTMENT_HEAD, RoleService::DEPUTY_DEPARTMENT_HEAD])
            && !PermissionService::canManagePreprojects($user)
        ) {
            $where[] = 'assignee.department = ?';
            $params[] = (string) ($user['department'] ?? '');
        }

        $stmt = $pdo->prepare('
            SELECT t.id, t.task_type, t.discipline, t.planned_hours, t.actual_hours,
                   t.date_start, t.date_end, t.closed_at
            FROM tasks t
            LEFT JOIN users assignee ON assignee.id = t.assignee_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY COALESCE(t.closed_at, t.date_end, t.updated_at) DESC, t.id DESC
            LIMIT ' . $limit . '
        ');
        $stmt->execute($params);
        return $this->taskStatisticsFromRows($stmt->fetchAll());
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{overall: array<string, mixed>, by_discipline: array<int, array<string, mixed>>, by_type: array<int, array<string, mixed>>}
     */
    public function taskStatisticsFromRows(array $rows): array
    {
        return [
            'overall' => $this->aggregateTaskRows($rows),
            'by_discipline' => $this->aggregateTaskGroups($rows, 'discipline'),
            'by_type' => $this->aggregateTaskGroups($rows, 'task_type'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     * @param array<int, array<string, mixed>> $laborRows
     * @param array{overall?: array<string, mixed>, by_discipline?: array<int, array<string, mixed>>, by_type?: array<int, array<string, mixed>>} $taskStats
     * @return array<int, array<string, mixed>>
     */
    public function sectionPlanningRows(array $sections, array $laborRows, array $taskStats): array
    {
        $laborBySection = [];
        foreach ($laborRows as $row) {
            $sectionId = (int) ($row['section_id'] ?? 0);
            if ($sectionId <= 0) {
                continue;
            }
            $laborBySection[$sectionId] ??= [
                'rows' => 0,
                'hours' => 0.0,
            ];
            $laborBySection[$sectionId]['rows']++;
            $laborBySection[$sectionId]['hours'] += (float) ($row['planning_hours'] ?? $row['effective_hours'] ?? 0);
        }

        $statsByLabel = [];
        foreach (($taskStats['by_discipline'] ?? []) as $row) {
            $label = $this->normalizeLabel($row['label'] ?? '');
            if ($label !== '') {
                $statsByLabel[$label] = $row;
            }
        }

        $result = [];
        foreach ($sections as $section) {
            $sectionId = (int) ($section['id'] ?? 0);
            $stats = $this->statsForSection($section, $statsByLabel);
            $suggestedHours = (float) ($stats['avg_actual_hours'] ?? 0);
            if ($suggestedHours <= 0) {
                $suggestedHours = (float) ($stats['avg_planned_hours'] ?? 0);
            }
            if ($suggestedHours <= 0 && (float) ($section['sbc_default_labor_hours'] ?? 0) > 0) {
                $suggestedHours = (float) ($section['sbc_default_labor_hours'] ?? 0);
            }

            $result[] = [
                'section' => $section,
                'labor_rows' => (int) ($laborBySection[$sectionId]['rows'] ?? 0),
                'labor_hours' => round((float) ($laborBySection[$sectionId]['hours'] ?? 0), 2),
                'task_stats' => $stats,
                'suggested_hours' => round(max(0.0, $suggestedHours), 2),
                'has_sbc' => (float) ($section['sbc_reference_cost'] ?? 0) > 0,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{hours: float, source: string, label: string}
     */
    private function planningHours(array $row): array
    {
        $status = (string) ($row['status'] ?? '');
        $candidates = match ($status) {
            'director_approved' => [
                ['director', 'Директор', $row['director_hours'] ?? 0],
                ['gip', 'ГИП', $row['gip_hours'] ?? 0],
                ['department', 'Отдел', $row['executor_hours'] ?? 0],
            ],
            'gip_adjusted', 'gip_approved', 'returned_to_gip' => [
                ['gip', 'ГИП', $row['gip_hours'] ?? 0],
                ['department', 'Отдел', $row['executor_hours'] ?? 0],
            ],
            'department_submitted', 'submitted', 'returned_to_department', 'returned_to_responsible' => [
                ['department', 'Отдел', $row['executor_hours'] ?? 0],
            ],
            default => [
                ['department', 'Отдел', $row['executor_hours'] ?? 0],
            ],
        };

        $candidates[] = ['model', 'Модель', $row['model_suggested_hours'] ?? 0];

        foreach ($candidates as [$source, $label, $hours]) {
            $value = round(max(0.0, (float) $hours), 2);
            if ($value > 0) {
                return ['hours' => $value, 'source' => $source, 'label' => $label];
            }
        }

        return ['hours' => 0.0, 'source' => 'empty', 'label' => 'Не задано'];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function aggregateTaskRows(array $rows, string $label = 'Все закрытые задачи'): array
    {
        $count = count($rows);
        $planned = 0.0;
        $actual = 0.0;
        $cycleDays = 0.0;
        $cycleCount = 0;
        $actualCount = 0;
        $overPlan = 0;

        foreach ($rows as $row) {
            $plannedHours = max(0.0, (float) ($row['planned_hours'] ?? 0));
            $actualHours = max(0.0, (float) ($row['actual_hours'] ?? 0));
            $planned += $plannedHours;
            $actual += $actualHours;
            if ($actualHours > 0) {
                $actualCount++;
            }
            if ($plannedHours > 0 && $actualHours > $plannedHours) {
                $overPlan++;
            }
            $days = $this->taskCycleDays($row);
            if ($days !== null) {
                $cycleDays += $days;
                $cycleCount++;
            }
        }

        return [
            'label' => $label,
            'total' => $count,
            'with_actual' => $actualCount,
            'avg_planned_hours' => $count > 0 ? round($planned / $count, 2) : 0.0,
            'avg_actual_hours' => $actualCount > 0 ? round($actual / $actualCount, 2) : 0.0,
            'avg_cycle_days' => $cycleCount > 0 ? round($cycleDays / $cycleCount, 1) : 0.0,
            'over_plan_percent' => $count > 0 ? round($overPlan / $count * 100, 1) : 0.0,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function aggregateTaskGroups(array $rows, string $field): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row[$field] ?? ''));
            if ($label === '') {
                $label = $field === 'discipline' ? 'Без дисциплины' : 'Без типа';
            }
            if ($field === 'task_type' && function_exists('task_type_label')) {
                $label = task_type_label($label);
            }
            $groups[$label][] = $row;
        }

        $result = [];
        foreach ($groups as $label => $groupRows) {
            $result[] = $this->aggregateTaskRows($groupRows, (string) $label);
        }
        usort($result, static fn (array $a, array $b): int => ((int) $b['total'] <=> (int) $a['total']) ?: strcmp((string) $a['label'], (string) $b['label']));

        return array_slice($result, 0, 12);
    }

    /**
     * @param array<string, mixed> $section
     * @param array<string, array<string, mixed>> $statsByLabel
     * @return array<string, mixed>
     */
    private function statsForSection(array $section, array $statsByLabel): array
    {
        foreach ([$section['code'] ?? '', $section['title'] ?? ''] as $candidate) {
            $key = $this->normalizeLabel($candidate);
            if ($key !== '' && isset($statsByLabel[$key])) {
                return $statsByLabel[$key];
            }
        }

        return [
            'label' => trim((string) (($section['code'] ?? '') ?: ($section['title'] ?? 'Раздел'))),
            'total' => 0,
            'with_actual' => 0,
            'avg_planned_hours' => 0.0,
            'avg_actual_hours' => 0.0,
            'avg_cycle_days' => 0.0,
            'over_plan_percent' => 0.0,
        ];
    }

    private function normalizeLabel(mixed $value): string
    {
        $value = mb_strtolower(trim((string) $value), 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function taskCycleDays(array $row): ?int
    {
        $start = $this->dateOrNull($row['date_start'] ?? null);
        if ($start === null) {
            return null;
        }
        $end = $this->dateOrNull($row['closed_at'] ?? null)
            ?? $this->dateOrNull($row['date_end'] ?? null);
        if ($end === null) {
            return null;
        }

        return $this->calendarDays($start, $end);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function planningMoney(array $row, float $hours): float
    {
        if ((string) ($row['status'] ?? '') === 'director_approved' && (float) ($row['director_cost_thousand'] ?? 0) > 0) {
            return round((float) $row['director_cost_thousand'], 2);
        }

        return round(($hours * (float) ($row['hourly_rate'] ?? 0)) / 1000, 2);
    }

    private function sbcState(float $sbc, float $delta): string
    {
        if ($sbc <= 0) {
            return 'missing';
        }
        if (abs($delta) <= max(1.0, $sbc * 0.05)) {
            return 'aligned';
        }

        return $delta > 0 ? 'above' : 'below';
    }

    /**
     * @param array<string, mixed> $preproject
     * @param array<int, array<string, mixed>> $sections
     * @return array{start: ?string, end: ?string, days: ?int, source: string}
     */
    private function dateRange(array $preproject, array $sections): array
    {
        $start = null;
        $end = null;
        foreach ($sections as $section) {
            $start = $this->minDate($start, $section['date_start'] ?? null);
            $end = $this->maxDate($end, $section['date_end'] ?? null);
        }
        $source = 'по датам разделов';

        if ($start === null && $end === null) {
            $start = $this->dateOrNull($preproject['start_date'] ?? null);
            $end = $this->dateOrNull($preproject['finish_date'] ?? $preproject['end_date'] ?? null);
            $source = $start !== null || $end !== null ? 'по паспорту' : 'не задан';
        }

        return [
            'start' => $start,
            'end' => $end,
            'days' => $this->calendarDays($start, $end),
            'source' => $source,
        ];
    }

    private function minDate(?string $current, mixed $candidate): ?string
    {
        $candidate = $this->dateOrNull($candidate);
        if ($candidate === null) {
            return $current;
        }

        return $current === null || $candidate < $current ? $candidate : $current;
    }

    private function maxDate(?string $current, mixed $candidate): ?string
    {
        $candidate = $this->dateOrNull($candidate);
        if ($candidate === null) {
            return $current;
        }

        return $current === null || $candidate > $current ? $candidate : $current;
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = mb_substr(trim((string) $value), 0, 10);
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    private function calendarDays(?string $start, ?string $end): ?int
    {
        if ($start === null || $end === null) {
            return null;
        }

        $startDate = new \DateTimeImmutable($start);
        $endDate = new \DateTimeImmutable($end);
        if ($endDate < $startDate) {
            return null;
        }

        return $startDate->diff($endDate)->days + 1;
    }

    private function health(int $rowCount, float $coverage, float $sbc, float $delta): string
    {
        if ($rowCount === 0 || $coverage < 80.0) {
            return 'attention';
        }
        if ($sbc > 0 && abs($delta / $sbc) > 0.2) {
            return 'risk';
        }

        return 'ok';
    }
}
