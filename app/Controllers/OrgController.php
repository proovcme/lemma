<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\OrgService;
use App\Services\PermissionService;

final class OrgController extends BaseController
{
    public function index(): void
    {
        $user = require_auth();
        // Оргструктура полезна каждому — доступна любому авторизованному.
        if (!PermissionService::canOpenStructure($user)) {
            http_response_code(403);
            view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Структура организации доступна авторизованным пользователям.']);
            return;
        }

        $hasSvg = is_file(BASE_PATH . '/public/assets/org-structure.svg');
        $users = OrgService::activeUsers($this->db());
        $usersById = [];
        foreach ($users as $orgUser) {
            $usersById[(int) ($orgUser['id'] ?? 0)] = true;
        }
        $withManagerCount = 0;
        $managerIds = [];
        foreach ($users as $orgUser) {
            $managerId = (int) ($orgUser['manager_id'] ?? 0);
            if ($managerId > 0 && isset($usersById[$managerId])) {
                $withManagerCount++;
                $managerIds[$managerId] = true;
            }
        }
        $orgStats = [
            'total' => count($users),
            'with_manager' => $withManagerCount,
            'without_manager' => max(0, count($users) - $withManagerCount),
            'manager_count' => count($managerIds),
        ];
        $departmentCounts = [];
        $groupCounts = [];
        foreach ($users as $orgUser) {
            $departmentCode = trim((string) ($orgUser['department'] ?? ''));
            if ($departmentCode === '') {
                continue;
            }
            $departmentCounts[$departmentCode] = ($departmentCounts[$departmentCode] ?? 0) + 1;
            $groupId = (int) ($orgUser['group_id'] ?? 0);
            if ($groupId > 0) {
                $groupCounts[$groupId] = ($groupCounts[$groupId] ?? 0) + 1;
            }
        }
        $usersMap = [];
        foreach ($users as $orgUser) {
            $usersMap[(int) ($orgUser['id'] ?? 0)] = $orgUser;
        }
        [$profileScope, $profileParams] = PermissionService::employeeProfileScopeWhere($user, 'profile_user');
        $profileStmt = $this->db()->prepare('
            SELECT profile_user.id
            FROM users profile_user
            WHERE profile_user.is_active = 1 AND ' . $profileScope . '
        ');
        $profileStmt->execute($profileParams);
        $profileVisibleIds = [];
        foreach ($profileStmt->fetchAll() as $profileUser) {
            $profileVisibleIds[(int) ($profileUser['id'] ?? 0)] = true;
        }

        $this->render('reference/org_structure', [
            'title' => 'Структура организации',
            'subtitle' => 'Живая структура: руководители, должности и отделы',
            'hasSvg' => $hasSvg,
            'users' => $users,
            'tree' => OrgService::tree($users),
            'orgStats' => $orgStats,
            'canManageUsers' => PermissionService::canManageUsers($user),
            'departments' => $this->db()->query('SELECT * FROM departments ORDER BY code')->fetchAll(),
            'departmentCounts' => $departmentCounts,
            'groups' => $this->db()->query('
                SELECT g.*, d.name AS department_name, lead.name AS lead_name
                FROM department_groups g
                LEFT JOIN departments d ON d.code = g.department_code
                LEFT JOIN users lead ON lead.id = g.lead_user_id
                ORDER BY g.department_code, g.sort_order, g.name
            ')->fetchAll(),
            'groupCounts' => $groupCounts,
            'positions' => OrgService::positions($this->db()),
            'managers' => $users,
            'usersMap' => $usersMap,
            'profileVisibleIds' => $profileVisibleIds,
        ]);
    }
}
