<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class MonthlyWorkLedgerService
{
    public const BASE_FIELDS = ['work_date', 'project', 'pp', 'btp', 'department', 'employee', 'tab_number', 'task', 'section', 'status', 'hours'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{period:array{date_from:string,date_to:string},fields:list<string>,group_by:string,rows:list<array<string,mixed>>,groups:list<array<string,mixed>>,metrics:array<string,mixed>} */
    public function report(array $viewer, array $input): array
    {
        $period = $this->period($input);
        $groupBy = in_array((string) ($input['group_by'] ?? ''), ['general', 'people', 'project', 'task'], true)
            ? (string) $input['group_by']
            : 'general';
        [$scope, $scopeParams] = PermissionService::canSeeAllProjects($viewer)
            ? ['1=1', []]
            : PermissionService::projectScopeWhere($viewer, 'p', 'ledger_scope_task');

        $taskSql = '
            SELECT t.id AS task_id, te.id AS time_entry_id, te.work_date, te.minutes,
                   p.code AS project_code, p.title AS project_title,
                   pp.code AS pp_code, pp.title AS pp_title,
                   btp.code AS btp_code, btp.title AS btp_title,
                   assignee.name AS assignee_name, assignee.tab_number AS assignee_tab_number, assignee.department AS assignee_department,
                   worker.name AS worker_name, worker.tab_number AS worker_tab_number, worker.department AS worker_department,
                   t.title AS task_title, t.section, t.discipline, t.status
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN users assignee ON assignee.id = t.assignee_id
            LEFT JOIN project_btp_codes btp ON btp.id = t.btp_code_id
            LEFT JOIN project_pp_codes pp ON pp.id = COALESCE(t.pp_code_id, btp.pp_code_id)
            LEFT JOIN time_entries te ON te.task_id = t.id
                AND te.work_date BETWEEN :date_from AND :date_to
            LEFT JOIN users worker ON worker.id = te.user_id
            WHERE ' . $scope . '
            ORDER BY p.code, t.id, te.work_date, te.id';
        $stmt = $this->pdo->prepare($taskSql);
        $stmt->execute(['date_from' => $period['date_from'], 'date_to' => $period['date_to']] + $scopeParams);
        $rows = array_map(fn (array $row): array => $this->row($row), $stmt->fetchAll());

        $legacySql = '
            SELECT NULL AS task_id, te.id AS time_entry_id, te.work_date, te.minutes,
                   p.code AS project_code, p.title AS project_title,
                   NULL AS pp_code, NULL AS pp_title, NULL AS btp_code, NULL AS btp_title,
                   NULL AS assignee_name, NULL AS assignee_tab_number, NULL AS assignee_department,
                   worker.name AS worker_name, worker.tab_number AS worker_tab_number, worker.department AS worker_department,
                   NULL AS task_title, NULL AS section, NULL AS discipline, NULL AS status
            FROM time_entries te
            LEFT JOIN tasks legacy_task ON legacy_task.id = te.task_id
            LEFT JOIN projects p ON p.id = COALESCE(te.project_id, legacy_task.project_id)
            LEFT JOIN users worker ON worker.id = te.user_id
            WHERE legacy_task.id IS NULL
              AND te.work_date BETWEEN :legacy_date_from AND :legacy_date_to
              AND ' . $scope . '
            ORDER BY p.code, te.work_date, te.id';
        $legacy = $this->pdo->prepare($legacySql);
        $legacy->execute(['legacy_date_from' => $period['date_from'], 'legacy_date_to' => $period['date_to']] + $scopeParams);
        foreach ($legacy->fetchAll() as $row) {
            $rows[] = $this->row($row);
        }
        $rows = $this->filterRows($rows, $input);
        $rows = $this->sortRows($rows, $groupBy);

        return [
            'period' => $period,
            'fields' => self::BASE_FIELDS,
            'group_by' => $groupBy,
            'rows' => $rows,
            'groups' => $this->groups($rows, $groupBy),
            'metrics' => [
                'task_count' => count(array_unique(array_filter(array_column($rows, 'task_id')))),
                'hours' => array_sum(array_map(static fn (array $row): float => (float) ($row['hours'] ?? 0), $rows)),
            ],
        ];
    }

    /** @return array{date_from:string,date_to:string} */
    private function period(array $input): array
    {
        $monthStart = date('Y-m-01');
        $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($input['date_from'] ?? '')) ? (string) $input['date_from'] : $monthStart;
        $dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($input['date_to'] ?? '')) ? (string) $input['date_to'] : date('Y-m-t', strtotime($dateFrom));

        return ['date_from' => $dateFrom, 'date_to' => $dateTo];
    }

    /** @return array<string,mixed> */
    private function row(array $row): array
    {
        $employee = $this->label($row['worker_name'] ?? '', '') ?: $this->label($row['assignee_name'] ?? '', 'Не назначен');
        $tabNumber = $this->label($row['worker_tab_number'] ?? '', '') ?: $this->label($row['assignee_tab_number'] ?? '', '');
        $department = $this->label($row['worker_department'] ?? '', '') ?: $this->label($row['assignee_department'] ?? '', '—');

        return [
            'task_id' => $row['task_id'] !== null ? (int) $row['task_id'] : null,
            'time_entry_id' => $row['time_entry_id'] !== null ? (int) $row['time_entry_id'] : null,
            'work_date' => $row['work_date'] ?? null,
            'project' => $this->accountingLabel($row['project_code'] ?? '', $row['project_title'] ?? '', 'Без проекта'),
            'pp' => $this->accountingLabel($row['pp_code'] ?? '', $row['pp_title'] ?? '', '—'),
            'btp' => $this->accountingLabel($row['btp_code'] ?? '', $row['btp_title'] ?? '', '—'),
            'department' => $department,
            'employee' => $employee,
            'tab_number' => $tabNumber,
            'task' => $row['task_id'] !== null ? '#' . (int) $row['task_id'] . ' · ' . (string) $row['task_title'] : 'Без задачи',
            'section' => $this->label($row['section'] ?? '', '') ?: $this->label($row['discipline'] ?? '', '—'),
            'status' => $row['task_id'] !== null ? task_status_label((string) ($row['status'] ?? '')) : '—',
            'hours' => $row['minutes'] !== null ? round((int) $row['minutes'] / 60, 2) : null,
        ];
    }

    private function label(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : $fallback;
    }

    private function accountingLabel(mixed $code, mixed $title, string $fallback): string
    {
        $code = trim((string) $code);
        $title = trim((string) $title);
        return $code !== '' && $title !== '' ? $code . ' · ' . $title : ($code !== '' ? $code : ($title !== '' ? $title : $fallback));
    }

    /** @return list<array<string,mixed>> */
    private function filterRows(array $rows, array $input): array
    {
        $filters = array_intersect_key($input, array_flip(['project', 'pp', 'btp', 'department', 'employee', 'task', 'section', 'status']));
        foreach ($filters as $field => $needle) {
            $needle = trim((string) $needle);
            if ($needle === '') {
                continue;
            }
            $rows = array_values(array_filter($rows, static fn (array $row): bool => mb_stripos((string) ($row[$field] ?? ''), $needle) !== false));
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function sortRows(array $rows, string $groupBy): array
    {
        if ($groupBy === 'general') {
            return $rows;
        }
        usort($rows, static function (array $left, array $right) use ($groupBy): int {
            $leftKey = match ($groupBy) {
                'people' => ($left['department'] ?? '') . '|' . ($left['employee'] ?? ''),
                'project' => $left['project'] ?? '',
                default => ($left['project'] ?? '') . '|' . ($left['task'] ?? ''),
            };
            $rightKey = match ($groupBy) {
                'people' => ($right['department'] ?? '') . '|' . ($right['employee'] ?? ''),
                'project' => $right['project'] ?? '',
                default => ($right['project'] ?? '') . '|' . ($right['task'] ?? ''),
            };
            return strcmp((string) $leftKey, (string) $rightKey)
                ?: strcmp((string) ($left['work_date'] ?? ''), (string) ($right['work_date'] ?? ''))
                ?: ((int) ($left['time_entry_id'] ?? 0) <=> (int) ($right['time_entry_id'] ?? 0));
        });
        return $rows;
    }

    /** @return list<array{key:string,label:string,hours:?float,row_count:int}> */
    private function groups(array $rows, string $groupBy): array
    {
        if ($groupBy === 'general') {
            return [];
        }
        $groups = [];
        foreach ($rows as $row) {
            [$key, $label] = match ($groupBy) {
                'people' => [$row['department'] . '|' . $row['employee'], $row['department'] . ' · ' . $row['employee']],
                'project' => [$row['project'], $row['project']],
                default => [$row['project'] . '|' . $row['task'], $row['project'] . ' · ' . $row['task']],
            };
            $groups[$key]['key'] = $key;
            $groups[$key]['label'] = $label;
            $groups[$key]['row_count'] = ($groups[$key]['row_count'] ?? 0) + 1;
            if ($row['hours'] !== null) {
                $groups[$key]['hours'] = ($groups[$key]['hours'] ?? 0.0) + (float) $row['hours'];
            }
        }
        return array_values(array_map(static function (array $group): array {
            $group['hours'] = array_key_exists('hours', $group) ? round((float) $group['hours'], 2) : null;
            return $group;
        }, $groups));
    }
}
