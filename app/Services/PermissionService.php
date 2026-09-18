<?php

declare(strict_types=1);

namespace App\Services;

final class PermissionService
{
    public static function canSeeAllProjects(array $user): bool
    {
        if (RoleService::isAny($user['role'] ?? null, [RoleService::ENGINEER])) {
            return false;
        }

        return RoleService::has($user['role'] ?? null, RoleService::CAP_PROJECTS_ALL);
    }

    public static function canOpenProjects(array $user): bool
    {
        if (RoleService::isAny($user['role'] ?? null, [RoleService::ENGINEER])) {
            return false;
        }

        return RoleService::has($user['role'] ?? null, RoleService::CAP_PROJECTS);
    }

    public static function canOpenDpr(array $user): bool
    {
        if (RoleService::isAny($user['role'] ?? null, [RoleService::ENGINEER])) {
            return false;
        }

        return RoleService::has($user['role'] ?? null, RoleService::CAP_DPR);
    }

    public static function canOpenReports(array $user): bool
    {
        if (RoleService::isAny($user['role'] ?? null, [RoleService::ENGINEER])) {
            return false;
        }

        return RoleService::has($user['role'] ?? null, RoleService::CAP_REPORTS);
    }

    public static function canBrowseEmployeeProfiles(array $user): bool
    {
        return self::canSeeAllEmployeeProfiles($user)
            || RoleService::isAny($user['role'] ?? null, [RoleService::DEPARTMENT_HEAD, RoleService::DEPUTY_DEPARTMENT_HEAD])
            || self::isEmployeeProfileLineManager($user);
    }

    /**
     * Server-side scope for employee profiles. A user always sees their own
     * profile; managers see only their org branch/department, while explicitly
     * allowed director-level roles see all active employees.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    public static function employeeProfileScopeWhere(array $user, string $alias = 'u'): array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return ['0=1', []];
        }

        if (self::canSeeAllEmployeeProfiles($user)) {
            return ['1=1', []];
        }

        if (RoleService::isAny($user['role'] ?? null, [RoleService::DEPARTMENT_HEAD, RoleService::DEPUTY_DEPARTMENT_HEAD])) {
            $department = trim((string) ($user['department'] ?? ''));
            if ($department === '') {
                return [
                    "{$alias}.id = :profile_current_user_id",
                    ['profile_current_user_id' => $userId],
                ];
            }

            return [
                "({$alias}.id = :profile_current_user_id OR {$alias}.department = :profile_department)",
                [
                    'profile_current_user_id' => $userId,
                    'profile_department' => $department,
                ],
            ];
        }

        if (self::isEmployeeProfileLineManager($user)) {
            [$subordinateScope, $params] = self::subordinateUserScope(
                $alias . '.manager_id',
                'profile_subordinate_',
                $userId
            );

            return [
                "({$alias}.id = :profile_current_user_id OR {$subordinateScope})",
                ['profile_current_user_id' => $userId] + $params,
            ];
        }

        return [
            "{$alias}.id = :profile_current_user_id",
            ['profile_current_user_id' => $userId],
        ];
    }

    public static function canViewEmployeeProfile(array $user, int $employeeId): bool
    {
        if ($employeeId <= 0) {
            return false;
        }

        [$scope, $params] = self::employeeProfileScopeWhere($user, 'profile_user');
        $stmt = \App\Core\Database::pdo()->prepare('
            SELECT 1
            FROM users profile_user
            WHERE profile_user.id = :profile_employee_id
              AND profile_user.is_active = 1
              AND ' . $scope . '
            LIMIT 1
        ');
        $stmt->execute(['profile_employee_id' => $employeeId] + $params);

        return (bool) $stmt->fetchColumn();
    }

    private static function canSeeAllEmployeeProfiles(array $user): bool
    {
        return RoleService::isAny($user['role'] ?? null, [
            RoleService::DEPUTY_DIRECTOR,
            RoleService::ADJACENT_DIRECTOR,
            RoleService::DIRECTOR,
            RoleService::ADMIN,
        ]);
    }

    private static function isEmployeeProfileLineManager(array $user): bool
    {
        return RoleService::isAny($user['role'] ?? null, [
            RoleService::CHIEF_SPECIALIST,
            RoleService::GROUP_LEAD,
            RoleService::GIP,
            RoleService::PROJECT_MANAGER,
            RoleService::BIM_MANAGER,
        ]);
    }

    public static function canOpenIntegrations(array $user): bool
    {
        if (RoleService::isAny($user['role'] ?? null, [RoleService::ENGINEER])) {
            return false;
        }

        return RoleService::has($user['role'] ?? null, RoleService::CAP_INTEGRATIONS);
    }

    public static function canManageUsers(array $user): bool
    {
        return RoleService::has($user['role'] ?? null, RoleService::CAP_USERS);
    }

    // Оргструктура — это просто схема организации, полезная каждому: доступна
    // любому авторизованному пользователю (развязана с правом «Пользователи»).
    public static function canOpenStructure(array $user): bool
    {
        if (RoleService::isAny($user['role'] ?? null, [RoleService::ENGINEER])) {
            return false;
        }

        return !empty($user);
    }

    // Матрица компетенций — отдельное право CAP_COMPETENCY (настраивается в
    // матрице доступов). Фолбэк на управление пользователями, чтобы у текущих
    // админов доступ не пропал после развязки.
    public static function canOpenCompetency(array $user): bool
    {
        return RoleService::has($user['role'] ?? null, RoleService::CAP_COMPETENCY)
            || self::canManageUsers($user);
    }

    public static function canManageSettings(array $user): bool
    {
        return RoleService::has($user['role'] ?? null, RoleService::CAP_SETTINGS);
    }

    public static function canCreateProject(array $user): bool
    {
        return RoleService::has($user['role'] ?? null, RoleService::CAP_PROJECTS_CREATE);
    }

    public static function canManagePreprojects(array $user): bool
    {
        return RoleService::isAny($user['role'] ?? null, [
            RoleService::ADMIN,
            RoleService::GIP,
            RoleService::DEPUTY_DIRECTOR,
            RoleService::DIRECTOR,
        ]);
    }

    /** Оценка трудозатрат (предпроект) — не для ролей ниже руководителя отдела. */
    public static function canAccessLaborEstimates(array $user): bool
    {
        return RoleService::atLeast($user['role'] ?? null, RoleService::DEPARTMENT_HEAD);
    }

    /** Выдача тома (issuance) — не для ролей ниже руководителя отдела. */
    public static function canCreateIssuance(array $user): bool
    {
        return RoleService::atLeast($user['role'] ?? null, RoleService::DEPARTMENT_HEAD);
    }

    /** Делегирование отдела ставит ГИП и выше: руководитель отдела потом распределяет работу. */
    public static function canCreateDelegationTask(array $user): bool
    {
        return RoleService::atLeast($user['role'] ?? null, RoleService::GIP)
            || RoleService::isAny($user['role'] ?? null, [RoleService::ADMIN]);
    }

    /** Какие типы задач роль вправе создавать (issuance/labor_estimate — только рук. отдела+). */
    public static function canCreateTaskType(array $user, string $taskType): bool
    {
        if (!self::canCreateTasks($user)) {
            return false;
        }
        if ($taskType === \App\Services\TaskWorkflowService::TASK_TYPE_REVIEW) {
            return false;
        }
        if ($taskType === 'issuance') {
            return self::canCreateIssuance($user);
        }
        if ($taskType === 'labor_estimate') {
            return self::canAccessLaborEstimates($user);
        }
        if ($taskType === \App\Services\TaskWorkflowService::TASK_TYPE_DELEGATION) {
            return self::canCreateDelegationTask($user);
        }

        return true;
    }

    public static function canSeeLaborMoney(array $user): bool
    {
        return self::canManageDepartmentBudget($user);
    }

    public static function canManageEmployeeRates(array $user): bool
    {
        return self::canManageDepartmentBudget($user);
    }

    /** Штатное расписание и бюджет департамента — только директор департамента, не технический администратор. */
    public static function canManageDepartmentBudget(array $user): bool
    {
        return RoleService::normalize($user['role'] ?? null) === RoleService::DIRECTOR;
    }

    public static function canManagePayroll(array $user): bool
    {
        return in_array(RoleService::normalize($user['role'] ?? null), [RoleService::DIRECTOR, RoleService::ADMIN], true);
    }

    public static function canManageMotivation(array $user): bool
    {
        return self::canManagePayroll($user);
    }

    public static function canOpenHr(array $user): bool
    {
        return RoleService::has($user['role'] ?? null, RoleService::CAP_HR);
    }

    public static function canManagePerformanceReviews(array $user): bool
    {
        return RoleService::isAny($user['role'] ?? null, [RoleService::HR, RoleService::DIRECTOR, RoleService::ADMIN]);
    }

    public static function canReviewTime(array $user): bool
    {
        return RoleService::isAny($user['role'] ?? null, [
            RoleService::GIP,
            RoleService::PROJECT_MANAGER,
            RoleService::DEPARTMENT_HEAD,
            RoleService::DEPUTY_DEPARTMENT_HEAD,
            RoleService::DIRECTOR,
            RoleService::ADMIN,
        ]);
    }

    public static function canDirectorApproveTime(array $user): bool
    {
        return in_array(RoleService::normalize($user['role'] ?? null), [RoleService::DIRECTOR, RoleService::ADMIN], true);
    }

    public static function canGipApproveLaborEstimates(array $user): bool
    {
        return RoleService::isAny($user['role'] ?? null, [
            RoleService::GIP,
            RoleService::DEPUTY_DIRECTOR,
            RoleService::DIRECTOR,
        ]);
    }

    public static function canDirectorApproveLaborEstimates(array $user): bool
    {
        return RoleService::isAny($user['role'] ?? null, [RoleService::DIRECTOR, RoleService::ADMIN]);
    }

    public static function canEditProjectTabs(array $user, array $project): bool
    {
        return self::canCreateProject($user)
            && (string) ($project['status'] ?? 'active') !== 'archived';
    }

    public static function canManageProjectModels(array $user, array $project): bool
    {
        if ((string) ($project['status'] ?? 'active') === 'archived') {
            return false;
        }

        // BIM/модели — отдельное право CAP_BIM (настраивается в матрице доступов;
        // это базовая функция, а не интеграция). Фолбэк на текущий список ролей,
        // чтобы поведение не менялось до настройки матрицы.
        return RoleService::has($user['role'] ?? null, RoleService::CAP_BIM)
            || RoleService::isAny($user['role'] ?? null, [
                RoleService::DEPARTMENT_HEAD,
                RoleService::DEPUTY_DEPARTMENT_HEAD,
                RoleService::GIP,
                RoleService::BIM_MANAGER,
                RoleService::DEPUTY_DIRECTOR,
                RoleService::DIRECTOR,
                RoleService::ADMIN,
            ]);
    }

    public static function canViewProjectStats(array $user, array $project): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId > 0 && in_array($userId, [
            (int) ($project['gip_user_id'] ?? 0),
            (int) ($project['rp_user_id'] ?? 0),
        ], true)) {
            return true;
        }

        return RoleService::isAny($user['role'] ?? null, [
            RoleService::DEPARTMENT_HEAD,
            RoleService::DEPUTY_DEPARTMENT_HEAD,
            RoleService::GIP,
            RoleService::PROJECT_MANAGER,
            RoleService::DEPUTY_DIRECTOR,
            RoleService::ADJACENT_DIRECTOR,
            RoleService::DIRECTOR,
            RoleService::ADMIN,
        ]);
    }

    public static function canViewProjectFinance(array $user, array $project): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId > 0 && in_array($userId, [
            (int) ($project['gip_user_id'] ?? 0),
            (int) ($project['rp_user_id'] ?? 0),
        ], true)) {
            return true;
        }

        return RoleService::isAny($user['role'] ?? null, [
            RoleService::GIP,
            RoleService::PROJECT_MANAGER,
            RoleService::DEPUTY_DIRECTOR,
            RoleService::ADJACENT_DIRECTOR,
            RoleService::DIRECTOR,
            RoleService::ADMIN,
        ]);
    }

    public static function canSelfApproveIssuance(array $user): bool
    {
        return self::canSelfApproveIssuanceRole($user['role'] ?? null);
    }

    public static function canSelfApproveIssuanceRole(?string $role): bool
    {
        return RoleService::atLeast($role, RoleService::GIP)
            || RoleService::isAny($role, [RoleService::BIM_MANAGER]);
    }

    public static function canApproveLaborEstimates(array $user): bool
    {
        return RoleService::isAny($user['role'] ?? null, [RoleService::DIRECTOR]);
    }

    public static function canEditAnyTask(array $user): bool
    {
        return RoleService::has($user['role'] ?? null, RoleService::CAP_TASKS_EDIT_ALL);
    }

    public static function canDelete(array $user): bool
    {
        return RoleService::has($user['role'] ?? null, RoleService::CAP_DELETE);
    }

    public static function canAdministerTasks(array $user): bool
    {
        return in_array(RoleService::normalize($user['role'] ?? null), [RoleService::DIRECTOR, RoleService::ADMIN], true);
    }

    public static function canCreateTasks(array $user): bool
    {
        return RoleService::atLeast($user['role'] ?? null, RoleService::CHIEF_SPECIALIST)
            || RoleService::isAny($user['role'] ?? null, [RoleService::ADMIN]);
    }

    public static function canAcceptWork(array $user): bool
    {
        return RoleService::atLeast($user['role'] ?? null, RoleService::CHIEF_SPECIALIST);
    }

    public static function taskScopeWhere(array $user, string $alias = 't'): array
    {
        if (self::canSeeAllProjects($user)) {
            return ['1=1', []];
        }

        [$subordinateUserWhere, $subordinateParams] = self::subordinateUserScope('manager_id', 'scope_subordinate_', (int) ($user['id'] ?? 0));
        [$subordinateParticipantWhere] = self::subordinateUserScope('tp_user_subordinate.manager_id', 'scope_subordinate_', (int) ($user['id'] ?? 0));
        $subordinateTaskScope = "
                OR {$alias}.assignee_id IN (SELECT id FROM users WHERE {$subordinateUserWhere})
                OR {$alias}.author_id IN (SELECT id FROM users WHERE {$subordinateUserWhere})
                OR {$alias}.reviewer_id IN (SELECT id FROM users WHERE {$subordinateUserWhere})
                OR EXISTS (
                    SELECT 1
                    FROM task_participants tp_scope_subordinate
                    INNER JOIN users tp_user_subordinate ON tp_user_subordinate.id = tp_scope_subordinate.user_id
                    WHERE tp_scope_subordinate.task_id = {$alias}.id
                      AND {$subordinateParticipantWhere}
                )";
        $vacationTaskScope = '';
        $vacationParams = [];
        if (self::scopeTableExists('employee_vacations')) {
            $vacationTaskScope = "
                    OR EXISTS (
                        SELECT 1
                        FROM employee_vacations vacation_scope
                        WHERE vacation_scope.user_id IN ({$alias}.assignee_id, {$alias}.author_id, {$alias}.reviewer_id)
                          AND vacation_scope.substitute_user_id = :vacation_substitute_user_id
                          AND vacation_scope.cancelled_at IS NULL
                          AND CURRENT_DATE BETWEEN vacation_scope.date_from AND vacation_scope.date_to
                    )";
            $vacationParams['vacation_substitute_user_id'] = (int) $user['id'];
        }
        if (RoleService::isAny($user['role'] ?? null, [RoleService::DEPARTMENT_HEAD, RoleService::DEPUTY_DEPARTMENT_HEAD])) {
            return [
                "(
                    {$alias}.assignee_id IN (SELECT id FROM users WHERE department = :department_assignee)
                    OR {$alias}.author_id IN (SELECT id FROM users WHERE department = :department_author)
                    OR {$alias}.reviewer_id IN (SELECT id FROM users WHERE department = :department_reviewer)
                    OR EXISTS (
                        SELECT 1
                        FROM task_participants tp_scope_department
                        INNER JOIN users tp_user_department ON tp_user_department.id = tp_scope_department.user_id
                        WHERE tp_scope_department.task_id = {$alias}.id
                          AND tp_user_department.department = :department_participant
                    )
                    {$subordinateTaskScope}
                    {$vacationTaskScope}
                    OR {$alias}.project_id IN (
                        SELECT id FROM projects
                        WHERE gip_user_id = :project_gip_user_id
                           OR rp_user_id = :project_rp_user_id
                    )
                )",
                [
                    'department_assignee' => $user['department'] ?? '',
                    'department_author' => $user['department'] ?? '',
                    'department_reviewer' => $user['department'] ?? '',
                    'department_participant' => $user['department'] ?? '',
                    'project_gip_user_id' => (int) $user['id'],
                    'project_rp_user_id' => (int) $user['id'],
                ] + $vacationParams + $subordinateParams,
            ];
        }

        $canSeeSubordinateTasks = RoleService::atLeast($user['role'] ?? null, RoleService::CHIEF_SPECIALIST);
        return [
            "(
                {$alias}.assignee_id = :user_id
                OR {$alias}.author_id = :user_id
                OR {$alias}.reviewer_id = :user_id
                OR EXISTS (
                    SELECT 1
                    FROM task_participants tp_scope_user
                    WHERE tp_scope_user.task_id = {$alias}.id
                      AND tp_scope_user.user_id = :participant_user_id
                )
                " . ($canSeeSubordinateTasks ? $subordinateTaskScope : '') . "
                {$vacationTaskScope}
                OR {$alias}.project_id IN (
                    SELECT id FROM projects
                    WHERE gip_user_id = :project_gip_user_id
                       OR rp_user_id = :project_rp_user_id
                )
            )",
            [
                'user_id' => (int) $user['id'],
                'participant_user_id' => (int) $user['id'],
                'project_gip_user_id' => (int) $user['id'],
                'project_rp_user_id' => (int) $user['id'],
            ] + $vacationParams + ($canSeeSubordinateTasks ? $subordinateParams : []),
        ];
    }

    public static function projectScopeWhere(array $user, string $projectAlias = 'p', string $taskAlias = 'project_scope_task'): array
    {
        if (self::canSeeAllProjects($user)) {
            return ['1=1', []];
        }

        $params = [
            'project_scope_user_id' => (int) ($user['id'] ?? 0),
        ];
        $clauses = [
            "{$projectAlias}.gip_user_id = :project_scope_user_id",
            "{$projectAlias}.rp_user_id = :project_scope_user_id",
        ];
        $userId = (int) ($user['id'] ?? 0);

        if (self::scopeTableExists('project_members')) {
            $clauses[] = "EXISTS (
                    SELECT 1
                    FROM project_members project_scope_member
                    WHERE project_scope_member.project_id = {$projectAlias}.id
                      AND project_scope_member.user_id = :project_scope_member_user_id
                      AND project_scope_member.active = 1
                )";
            $params['project_scope_member_user_id'] = $userId;
        }

        if (self::scopeTableExists('tasks')) {
            [$taskScopeSql, $taskScopeParams] = self::taskScopeWhere($user, $taskAlias);
            $clauses[] = "EXISTS (
                    SELECT 1
                    FROM tasks {$taskAlias}
                    WHERE {$taskAlias}.project_id = {$projectAlias}.id
                      AND {$taskScopeSql}
                )";
            $params += $taskScopeParams;
        }

        if (self::scopeTableExists('project_issues')) {
            $clauses[] = "EXISTS (
                    SELECT 1
                    FROM project_issues project_scope_issue
                    WHERE project_scope_issue.project_id = {$projectAlias}.id
                      AND project_scope_issue.assignee_id = :project_scope_issue_user_id
                      AND project_scope_issue.status != \"done\"
                )";
            $params['project_scope_issue_user_id'] = $userId;
        }

        return ['(' . implode(' OR ', $clauses) . ')', $params];
    }

    private static function scopeTableExists(string $table): bool
    {
        try {
            \App\Core\Database::pdo()->query('SELECT 1 FROM ' . $table . ' LIMIT 0');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Portable subordinate scope for SQLite/MariaDB without relying on a recursive CTE.
     * Six levels cover the practical org tree while preserving a safe fallback when
     * manager_id is empty.
     */
    private static function subordinateUserScope(string $managerColumn, string $prefix, int $managerId): array
    {
        $param = $prefix . 'manager_id';
        $params = [$param => $managerId];
        $placeholder = ':' . $param;
        $seed = 'SELECT id FROM users WHERE manager_id = ' . $placeholder;
        $clauses = [$managerColumn . ' = ' . $placeholder, $managerColumn . ' IN (' . $seed . ')'];
        $nested = $seed;
        for ($level = 3; $level <= 6; $level++) {
            $nested = 'SELECT id FROM users WHERE manager_id IN (' . $nested . ')';
            $clauses[] = $managerColumn . ' IN (' . $nested . ')';
        }

        return ['(' . implode(' OR ', $clauses) . ')', $params];
    }

    public static function canEditTask(array $user, array $task): bool
    {
        if ((string) ($task['project_status'] ?? 'active') === 'archived') {
            return false;
        }

        if (self::canEditAnyTask($user)) {
            return true;
        }

        if (VacationService::isActiveSubstituteFor(
            (int) ($user['id'] ?? 0),
            (int) ($task['assignee_id'] ?? 0)
        )) {
            return true;
        }

        if ((string) ($task['task_type'] ?? 'work') === 'labor_estimate') {
            return self::canManagePreprojects($user);
        }

        if ((int) ($task['reviewer_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
            return true;
        }

        if (RoleService::isAny($user['role'] ?? null, [RoleService::DEPARTMENT_HEAD, RoleService::DEPUTY_DEPARTMENT_HEAD])) {
            return self::sameDepartment($user, $task['assignee_department'] ?? null)
                || self::sameDepartment($user, $task['author_department'] ?? null)
                || self::sameDepartment($user, $task['reviewer_department'] ?? null);
        }

        if (RoleService::isAny($user['role'] ?? null, [RoleService::CHIEF_SPECIALIST, RoleService::GROUP_LEAD])) {
            return (int) ($task['author_id'] ?? 0) === (int) $user['id']
                || (int) ($task['reviewer_id'] ?? 0) === (int) $user['id']
                || (int) ($task['assignee_id'] ?? 0) === (int) $user['id']
                || (int) ($task['current_user_is_assignee_participant'] ?? 0) === 1
                || (int) ($task['current_user_is_coauthor'] ?? 0) === 1;
        }

        if (RoleService::isAny($user['role'] ?? null, [RoleService::ENGINEER])) {
            return false;
        }

        return (int) ($task['assignee_id'] ?? 0) === (int) $user['id']
            || (int) ($task['current_user_is_assignee_participant'] ?? 0) === 1
            || (int) ($task['current_user_is_coauthor'] ?? 0) === 1;
    }

    public static function canUpdateTaskExecution(array $user, array $task): bool
    {
        if ((string) ($task['project_status'] ?? 'active') === 'archived') {
            return false;
        }

        if (self::canEditTask($user, $task)) {
            return true;
        }

        return RoleService::isAny($user['role'] ?? null, [RoleService::ENGINEER])
            && (
                (int) ($task['assignee_id'] ?? 0) === (int) $user['id']
                || (int) ($task['current_user_is_assignee_participant'] ?? 0) === 1
            )
            && (string) ($task['task_type'] ?? 'work') !== 'labor_estimate';
    }

    private static function sameDepartment(array $user, ?string $department): bool
    {
        return trim((string) ($user['department'] ?? '')) !== ''
            && trim((string) $department) === trim((string) $user['department']);
    }
}
