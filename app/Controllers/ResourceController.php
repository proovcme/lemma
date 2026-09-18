<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\OrgService;
use App\Services\PermissionService;
use App\Services\ResourceService;

final class ResourceController extends BaseController
{
    public function index(): void
    {
        $user = require_auth();
        $canSeeTeam = PermissionService::canOpenReports($user);
        $preset = $this->preset((string) ($_GET['preset'] ?? 'month'));
        $buckets = ResourceService::buckets($preset);
        $projects = $this->projects($user, $canSeeTeam);
        $projectId = ($_GET['project_id'] ?? '') !== '' ? (int) $_GET['project_id'] : null;
        $visibleProjectIds = array_map(static fn (array $project): int => (int) $project['id'], $projects);
        if ($projectId !== null && !in_array($projectId, $visibleProjectIds, true)) {
            $projectId = null;
        }
        $filters = [
            'preset' => $preset,
            'department' => trim((string) ($_GET['department'] ?? '')),
            'manager_id' => ($_GET['manager_id'] ?? '') !== '' ? (int) $_GET['manager_id'] : null,
            'project_id' => $projectId,
        ];

        $users = $this->filteredUsers($user, $canSeeTeam, $filters);
        $userIds = array_map(static fn (array $row): int => (int) $row['id'], $users);
        $capacity = ResourceService::capacity($userIds, $buckets, $this->db());
        $demandPayload = ResourceService::demand($userIds, $buckets, $this->db(), $projectId);
        $demand = $demandPayload['demand'];

        $rows = [];
        foreach ($users as $resourceUser) {
            $uid = (int) $resourceUser['id'];
            $cells = [];
            $totalCapacity = 0.0;
            $totalDemand = 0.0;
            foreach ($buckets as $bucket) {
                $key = (string) $bucket['key'];
                $cellCapacity = (float) ($capacity[$uid][$key] ?? 0.0);
                $cellDemand = (float) ($demand[$uid][$key] ?? 0.0);
                $totalCapacity += $cellCapacity;
                $totalDemand += $cellDemand;
                $cells[] = [
                    'bucket' => $bucket,
                    'capacity' => $cellCapacity,
                    'demand' => $cellDemand,
                    'pct' => $cellCapacity > 0 ? round($cellDemand / $cellCapacity * 100) : ($cellDemand > 0 ? 999 : 0),
                    'zone' => ResourceService::loadZone($cellDemand, $cellCapacity),
                    'tasks' => $demandPayload['tasks'][$uid][$key] ?? [],
                ];
            }
            $resourceUser['cells'] = $cells;
            $resourceUser['total_capacity'] = round($totalCapacity, 1);
            $resourceUser['total_demand'] = round($totalDemand, 1);
            $resourceUser['total_pct'] = $totalCapacity > 0 ? round($totalDemand / $totalCapacity * 100) : 0;
            $resourceUser['unplanned'] = (int) ($demandPayload['unplanned'][$uid] ?? 0);
            $rows[] = $resourceUser;
        }

        $this->render('resources/index', [
            'title' => 'Ресурсы',
            'subtitle' => $canSeeTeam ? 'Мощность и спрос по сотрудникам' : 'Моя загрузка по активным задачам',
            'buckets' => $buckets,
            'rows' => $rows,
            'filters' => $filters,
            'departments' => $this->db()->query('SELECT code, name FROM departments ORDER BY code')->fetchAll(),
            'managers' => $this->managers(),
            'projects' => $projects,
            'canSeeTeam' => $canSeeTeam,
        ]);
    }

    private function preset(string $value): string
    {
        return in_array($value, ['week', 'month', 'quarter', 'year'], true) ? $value : 'month';
    }

    private function filteredUsers(array $currentUser, bool $canSeeTeam, array $filters): array
    {
        if (!$canSeeTeam) {
            $stmt = $this->db()->prepare('
                SELECT u.*, p.title AS position_title, p.grade AS position_grade, manager.name AS manager_name, d.name AS department_name
                FROM users u
                LEFT JOIN positions p ON p.id = u.position_id
                LEFT JOIN users manager ON manager.id = u.manager_id
                LEFT JOIN departments d ON d.code = u.department
                WHERE u.id = ? AND u.is_active = 1
                LIMIT 1
            ');
            $stmt->execute([(int) $currentUser['id']]);
            $row = $stmt->fetch();
            return $row ? [$row] : [];
        }

        $users = OrgService::activeUsers($this->db());
        if (($filters['department'] ?? '') !== '') {
            $department = (string) $filters['department'];
            $users = array_values(array_filter($users, static fn (array $u): bool => (string) ($u['department'] ?? '') === $department));
        }
        if (!empty($filters['manager_id'])) {
            $managerId = (int) $filters['manager_id'];
            $users = array_values(array_filter($users, static fn (array $u): bool => (int) ($u['id'] ?? 0) === $managerId || (int) ($u['manager_id'] ?? 0) === $managerId));
        }

        return $users;
    }

    private function managers(): array
    {
        return $this->db()->query('
            SELECT DISTINCT manager.id, manager.name, manager.department
            FROM users u
            INNER JOIN users manager ON manager.id = u.manager_id
            WHERE u.is_active = 1 AND manager.is_active = 1
            ORDER BY manager.department, manager.name
        ')->fetchAll();
    }

    private function projects(array $user, bool $canSeeTeam): array
    {
        if ($canSeeTeam || PermissionService::canSeeAllProjects($user)) {
            return $this->db()->query('
                SELECT id, code, title
                FROM projects
                WHERE status != "archived"
                ORDER BY code
            ')->fetchAll();
        }

        [$where, $params] = PermissionService::taskScopeWhere($user);
        $stmt = $this->db()->prepare('
            SELECT DISTINCT p.id, p.code, p.title
            FROM projects p
            INNER JOIN tasks t ON t.project_id = p.id
            WHERE p.status != "archived"
              AND ' . $where . '
            ORDER BY p.code
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
