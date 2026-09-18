<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditService;
use App\Services\DictionaryService;
use App\Services\OrgService;
use App\Services\PermissionService;
use App\Services\PerformanceReviewService;
use App\Services\PositionService;
use App\Services\RoleService;
use App\Services\StaffingService;
use App\Services\StaffingExportService;
use App\Services\VacationService;

final class TeamController extends BaseController
{
    public function employees(): void
    {
        $user = $this->requireTeamAccess();
        [$scope, $params] = PermissionService::employeeProfileScopeWhere($user, 'u');
        $stmt = $this->db()->prepare('SELECT u.*, p.title AS position_title, p.grade AS position_grade,
                manager.name AS manager_name, d.name AS department_name,
                COALESCE(ts.open_tasks, 0) AS open_tasks,
                COALESCE(ts.overdue_tasks, 0) AS overdue_tasks
            FROM users u
            LEFT JOIN positions p ON p.id = u.position_id
            LEFT JOIN users manager ON manager.id = u.manager_id
            LEFT JOIN departments d ON d.code = u.department
            LEFT JOIN (
                SELECT assignee_id,
                       SUM(CASE WHEN status NOT IN (\'done\', \'closed\') THEN 1 ELSE 0 END) AS open_tasks,
                       SUM(CASE WHEN status = \'overdue\' OR (date_end < CURRENT_DATE AND status NOT IN (\'done\', \'closed\')) THEN 1 ELSE 0 END) AS overdue_tasks
                FROM tasks GROUP BY assignee_id
            ) ts ON ts.assignee_id = u.id
            WHERE u.role <> :technical_admin_role AND (' . $scope . ')
            ORDER BY u.is_active DESC, u.department, u.name');
        $stmt->execute(['technical_admin_role' => RoleService::ADMIN] + $params);
        $employees = (new VacationService($this->db()))->attachAvailability($stmt->fetchAll());

        $this->render('team/employees', [
            'title' => 'Управление командой',
            'subtitle' => 'Сотрудники, подчинённость и рабочая статистика',
            'teamTab' => 'employees',
            'employees' => $employees,
            'positions' => PositionService::all(false, $this->db()),
            'departments' => $this->db()->query('SELECT code, name FROM departments ORDER BY name')->fetchAll(),
            'groups' => $this->db()->query('SELECT id, department_code, name FROM department_groups ORDER BY department_code, sort_order, name')->fetchAll(),
            'managers' => $this->activeManagers(),
            'canManageUsers' => PermissionService::canManageUsers($user),
            'canManageSettings' => PermissionService::canManageSettings($user),
            'canManageRates' => PermissionService::canManageDepartmentBudget($user),
        ]);
    }

    public function newEmployee(): void
    {
        $user = $this->requireUsersAdmin();
        $this->render('team/employee_form', [
            'title' => 'Новый сотрудник',
            'subtitle' => 'Учётная запись и место в структуре',
            'teamTab' => 'employees',
            'employee' => null,
            'positions' => PositionService::all(false, $this->db()),
            'departments' => $this->db()->query('SELECT * FROM departments ORDER BY name')->fetchAll(),
            'managers' => $this->activeManagers(),
            'canManageRates' => PermissionService::canManageDepartmentBudget($user),
        ]);
    }

    public function editEmployee(int $id): void
    {
        $user = $this->requireUsersAdmin();
        $stmt = $this->db()->prepare('SELECT u.*, COALESCE(er.hourly_rate, 0) AS hourly_rate
            FROM users u LEFT JOIN employee_rates er ON er.user_id = u.id
            WHERE u.id = ? AND u.role <> ?');
        $stmt->execute([$id, RoleService::ADMIN]);
        $employee = $stmt->fetch();
        if (!$employee) {
            $this->notFound('Сотрудник не найден.');
            return;
        }
        $this->render('team/employee_form', [
            'title' => 'Карточка сотрудника',
            'subtitle' => (string) $employee['name'],
            'teamTab' => 'employees',
            'employee' => $employee,
            'positions' => PositionService::all(false, $this->db()),
            'departments' => $this->db()->query('SELECT * FROM departments ORDER BY name')->fetchAll(),
            'managers' => $this->activeManagers($id),
            'canManageRates' => PermissionService::canManageDepartmentBudget($user),
        ]);
    }

    public function updateEmployee(int $id): void
    {
        $actor = $this->requireUsersAdmin();
        $stmt = $this->db()->prepare('SELECT * FROM users WHERE id = ? AND role <> ?');
        $stmt->execute([$id, RoleService::ADMIN]);
        $before = $stmt->fetch();
        if (!$before) {
            $this->notFound('Сотрудник не найден.');
            return;
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $tabNumber = trim((string) ($_POST['tab_number'] ?? ''));
        $positionId = $this->nullableInt($_POST['position_id'] ?? null);
        $managerId = $this->nullableInt($_POST['manager_id'] ?? null);
        $department = trim((string) ($_POST['department'] ?? '')) ?: null;
        $roleKey = PositionService::roleKeyForPosition($positionId, $this->db());
        if ($name === '' || $tabNumber === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $roleKey === null) {
            flash('error', 'Заполните ФИО, табельный номер, корректный email и должность.');
            redirect('/team/employees/' . $id . '/edit');
        }
        if ($managerId === $id || ($managerId !== null && $this->createsManagerCycle($id, $managerId))) {
            flash('error', 'Такое назначение руководителя создаёт цикл.');
            redirect('/team/employees/' . $id . '/edit');
        }
        if (RoleService::normalize((string) ($before['role'] ?? '')) === RoleService::DIRECTOR
            && RoleService::normalize($roleKey) !== RoleService::DIRECTOR
            && $this->activeDirectorCount() <= 1) {
            flash('error', 'Нельзя снять должность с последнего активного директора.');
            redirect('/team/employees/' . $id . '/edit');
        }
        $duplicate = $this->db()->prepare('SELECT id FROM users WHERE (email = ? OR tab_number = ?) AND id <> ? LIMIT 1');
        $duplicate->execute([$email, $tabNumber, $id]);
        if ($duplicate->fetchColumn()) {
            flash('error', 'Email или табельный номер уже используется.');
            redirect('/team/employees/' . $id . '/edit');
        }
        $this->db()->prepare('UPDATE users SET tab_number = ?, name = ?, email = ?, position_id = ?, role = ?, department = ?, manager_id = ? WHERE id = ?')
            ->execute([$tabNumber, $name, $email, $positionId, $roleKey, $department, $managerId, $id]);
        AuditService::record('team_employee_updated', ['employee_id' => $id, 'before' => $before, 'position_id' => $positionId, 'manager_id' => $managerId]);
        flash('success', 'Карточка сотрудника обновлена.');
        redirect('/team/employees/' . $id . '/edit');
    }

    public function quickUpdateEmployee(int $id): void
    {
        $actor = $this->requireUsersAdmin();
        $stmt = $this->db()->prepare('SELECT id, role, position_id, department, group_id, manager_id, is_active FROM users WHERE id = ? AND role <> ?');
        $stmt->execute([$id, RoleService::ADMIN]);
        $before = $stmt->fetch();
        if (!$before) {
            $this->notFound('Сотрудник не найден.');
            return;
        }

        $positionId = $this->nullableInt($_POST['position_id'] ?? null);
        $department = trim((string) ($_POST['department'] ?? '')) ?: null;
        $groupId = $this->nullableInt($_POST['group_id'] ?? null);
        $managerId = $this->nullableInt($_POST['manager_id'] ?? null);
        $isActive = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
        $roleKey = PositionService::roleKeyForPosition($positionId, $this->db());

        if ($roleKey === null) {
            flash('error', 'Выберите действующую должность.');
            redirect('/team');
        }
        if ($department !== null) {
            $departmentStmt = $this->db()->prepare('SELECT 1 FROM departments WHERE code = ? LIMIT 1');
            $departmentStmt->execute([$department]);
            if (!$departmentStmt->fetchColumn()) {
                flash('error', 'Выбранный отдел не найден.');
                redirect('/team');
            }
        }
        if ($groupId !== null) {
            $groupStmt = $this->db()->prepare('SELECT department_code FROM department_groups WHERE id = ? LIMIT 1');
            $groupStmt->execute([$groupId]);
            $groupDepartment = $groupStmt->fetchColumn();
            if ($groupDepartment === false || $department === null || (string) $groupDepartment !== $department) {
                flash('error', 'Группа должна относиться к выбранному отделу.');
                redirect('/team');
            }
        }
        if ($managerId === $id || ($managerId !== null && $this->createsManagerCycle($id, $managerId))) {
            flash('error', 'Такое назначение руководителя создаёт цикл.');
            redirect('/team');
        }
        if ($managerId !== null) {
            $managerStmt = $this->db()->prepare('SELECT 1 FROM users WHERE id = ? AND is_active = 1 AND role <> ? LIMIT 1');
            $managerStmt->execute([$managerId, RoleService::ADMIN]);
            if (!$managerStmt->fetchColumn()) {
                flash('error', 'Выбранный руководитель недоступен.');
                redirect('/team');
            }
        }
        if ($isActive === 0 && (int) $actor['id'] === $id) {
            flash('error', 'Нельзя уволить самого себя.');
            redirect('/team');
        }
        $wasDirector = RoleService::normalize((string) ($before['role'] ?? '')) === RoleService::DIRECTOR;
        $willBeDirector = RoleService::normalize($roleKey) === RoleService::DIRECTOR && $isActive === 1;
        if ($wasDirector && !$willBeDirector && $this->activeDirectorCount() <= 1) {
            flash('error', 'Нельзя снять должность или уволить последнего активного директора.');
            redirect('/team');
        }

        $this->db()->prepare('UPDATE users SET position_id = ?, role = ?, department = ?, group_id = ?, manager_id = ?, is_active = ? WHERE id = ?')
            ->execute([$positionId, $roleKey, $department, $groupId, $managerId, $isActive, $id]);
        AuditService::record('team_employee_quick_updated', [
            'employee_id' => $id,
            'before' => $before,
            'after' => compact('positionId', 'department', 'groupId', 'managerId', 'isActive'),
        ]);
        flash('success', 'Данные сотрудника сохранены из таблицы.');
        redirect('/team');
    }

    public function positions(): void
    {
        $this->requireSettingsAdmin();
        $positions = PositionService::all(true, $this->db());
        $selectedId = max(0, (int) ($_GET['position'] ?? 0));
        $selected = $selectedId > 0 ? PositionService::find($selectedId, $this->db()) : ($positions[0] ?? null);
        if ($selected) {
            $selected['capabilities'] = PositionService::capabilities((int) $selected['id'], (string) $selected['base_role'], $this->db());
        }
        $this->render('team/positions', [
            'title' => 'Управление командой',
            'subtitle' => 'Должности одновременно определяют название и доступы',
            'teamTab' => 'positions',
            'positions' => $positions,
            'selectedPosition' => $selected,
            'baseRoles' => array_values(array_diff(RoleService::roles(), [RoleService::ADMIN])),
            'capabilityLabels' => RoleService::capabilityLabels(),
            'competencyProfiles' => (new PerformanceReviewService($this->db()))->competencyPositionProfiles(),
            'canManageSettings' => true,
            'canManageRates' => true,
        ]);
    }

    public function createPosition(): void
    {
        $actor = $this->requireSettingsAdmin();
        try {
            $id = PositionService::create($_POST, (int) $actor['id'], $this->db());
            flash('success', 'Должность создана.');
            redirect('/team/positions?position=' . $id);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/team/positions');
        }
    }

    public function updatePosition(int $id): void
    {
        $actor = $this->requireSettingsAdmin();
        try {
            PositionService::update($id, $_POST, (int) $actor['id'], $this->db());
            flash('success', 'Должность и доступы сохранены.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/team/positions?position=' . $id);
    }

    public function clonePosition(int $id): void
    {
        $actor = $this->requireSettingsAdmin();
        try {
            $newId = PositionService::clonePosition($id, (int) $actor['id'], $this->db());
            flash('success', 'Создана копия должности.');
            redirect('/team/positions?position=' . $newId);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/team/positions?position=' . $id);
        }
    }

    public function archivePosition(int $id): void
    {
        $actor = $this->requireSettingsAdmin();
        try {
            PositionService::archive($id, (int) $actor['id'], $this->db());
            flash('success', 'Должность архивирована.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/team/positions');
    }

    public function departments(): void
    {
        $this->requireUsersAdmin();
        $this->render('team/departments', [
            'title' => 'Управление командой',
            'subtitle' => 'Отделы, группы и руководители',
            'teamTab' => 'departments',
            'departments' => $this->db()->query('SELECT d.*, u.name AS head_name,
                (SELECT COUNT(*) FROM users member WHERE member.department=d.code AND member.is_active=1) AS people_count
                FROM departments d LEFT JOIN users u ON u.id=d.head_user_id ORDER BY d.code')->fetchAll(),
            'groups' => $this->db()->query('SELECT g.*, u.name AS lead_name,
                (SELECT COUNT(*) FROM users member WHERE member.group_id=g.id AND member.is_active=1) AS people_count
                FROM department_groups g LEFT JOIN users u ON u.id=g.lead_user_id ORDER BY g.department_code,g.sort_order,g.name')->fetchAll(),
            'users' => $this->db()->query("SELECT id,name,department FROM users WHERE is_active=1 AND role <> 'admin' ORDER BY name")->fetchAll(),
        ]);
    }

    public function sections(): void
    {
        $this->requireUsersAdmin();
        $this->render('team/sections', [
            'title' => 'Управление командой',
            'subtitle' => 'Общий справочник проектных разделов',
            'teamTab' => 'sections',
            'sections' => DictionaryService::globalSections(),
        ]);
    }

    public function storeSection(): void
    {
        $this->requireUsersAdmin();
        $code = (string) ($_POST['code'] ?? '');
        $label = (string) ($_POST['label'] ?? '');
        try {
            DictionaryService::addGlobalSection($code, $label);
            AuditService::record('global_section_created', [
                'code' => mb_strtoupper(trim($code), 'UTF-8'),
                'label' => trim($label),
            ]);
            flash('success', 'Раздел добавлен в общий справочник.');
        } catch (\InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        }
        redirect('/team/sections');
    }

    public function rates(): void
    {
        $this->requireBudgetDirector();
        redirect('/director/staffing');
    }

    public function legacyStaffing(): void
    {
        $this->requireBudgetDirector();
        redirect('/director/staffing');
    }

    public function staffing(): void
    {
        $user = $this->requireBudgetDirector();
        $this->privateNoStore();
        $service = new StaffingService($this->db());
        $periods = $service->periods();
        $periodId = max(0, (int) ($_GET['period'] ?? ($periods[0]['id'] ?? 0)));
        $dashboard = $periodId > 0 ? $service->dashboard($periodId) : null;
        $this->render('team/staffing', [
            'title' => 'Штатное расписание',
            'subtitle' => 'Бюджет департамента, ставки и стоимостные группы',
            'directorTab' => 'staffing',
            'periods' => $periods,
            'dashboard' => $dashboard,
            'departments' => $this->db()->query('SELECT code,name FROM departments ORDER BY code')->fetchAll(),
            'groups' => $this->db()->query('SELECT id,department_code,name FROM department_groups ORDER BY department_code,sort_order,name')->fetchAll(),
            'positions' => PositionService::all(false, $this->db()),
            'users' => $this->db()->query("SELECT id,name,tab_number,department,group_id,position_id FROM users WHERE is_active=1 AND role <> 'admin' ORDER BY name")->fetchAll(),
            'canManageRates' => true,
            'actor' => $user,
            'defaultMonth' => date('Y-m'),
        ]);
    }

    public function createStaffingPeriod(): void
    {
        $user = $this->requireBudgetDirector();
        try {
            $id = (new StaffingService($this->db()))->createPeriod(
                (string) ($_POST['month'] ?? ''),
                (float) ($_POST['working_days'] ?? 21),
                (float) ($_POST['working_hours'] ?? 168),
                $this->nullableInt($_POST['copy_from'] ?? null),
                (int) $user['id'],
                (float) ($_POST['payroll_burden_pct'] ?? 0),
                (float) ($_POST['overhead_pct'] ?? 0)
            );
            flash('success', 'Период создан. Заполните штатное расписание и зафиксируйте его после проверки.');
            redirect('/director/staffing?period=' . $id);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/director/staffing');
        }
    }

    public function updateStaffingPeriod(int $id): void
    {
        $this->requireBudgetDirector();
        try {
            (new StaffingService($this->db()))->updatePeriod(
                $id,
                (float) ($_POST['working_days'] ?? 0),
                (float) ($_POST['working_hours'] ?? 0),
                (float) ($_POST['payroll_burden_pct'] ?? 0),
                (float) ($_POST['overhead_pct'] ?? 0),
                (string) ($_POST['note'] ?? '')
            );
            flash('success', 'Параметры периода сохранены.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/director/staffing?period=' . $id);
    }

    public function saveStaffingRow(int $periodId, ?int $rowId = null): void
    {
        $this->requireBudgetDirector();
        try {
            (new StaffingService($this->db()))->saveRow($periodId, $_POST, $rowId);
            flash('success', $rowId ? 'Строка обновлена.' : 'Позиция добавлена.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/director/staffing?period=' . $periodId);
    }

    public function deleteStaffingRow(int $periodId, int $rowId): void
    {
        $this->requireBudgetDirector();
        try {
            (new StaffingService($this->db()))->deleteRow($periodId, $rowId);
            flash('success', 'Строка удалена.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/director/staffing?period=' . $periodId);
    }

    public function lockStaffingPeriod(int $id): void
    {
        $user = $this->requireBudgetDirector();
        try {
            (new StaffingService($this->db()))->lock($id, (int) $user['id']);
            flash('success', 'Период зафиксирован. Текущие ставки и стоимостные группы обновлены.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/director/staffing?period=' . $id);
    }

    public function correctStaffingPeriod(int $id): void
    {
        $user = $this->requireBudgetDirector();
        try {
            $newId = (new StaffingService($this->db()))->createCorrection($id, (int) $user['id']);
            flash('success', 'Создан черновик корректировки. Зафиксированная версия сохранена в истории.');
            redirect('/director/staffing?period=' . $newId);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/director/staffing?period=' . $id);
        }
    }

    public function printStaffing(int $id): void
    {
        $this->requireBudgetDirector();
        $this->privateNoStore();
        $dashboard = (new StaffingService($this->db()))->dashboard($id);
        $this->render('team/staffing_print', [
            'title' => 'Штатное расписание',
            'dashboard' => $dashboard,
        ]);
    }

    public function exportStaffing(int $id): void
    {
        $this->requireBudgetDirector();
        $dashboard = (new StaffingService($this->db()))->dashboard($id);
        (new StaffingExportService())->download($dashboard);
    }

    private function requireTeamAccess(): array
    {
        $user = require_auth();
        if (!PermissionService::canBrowseEmployeeProfiles($user) && !PermissionService::canManageUsers($user)) {
            $this->forbidden('Раздел доступен руководителям.');
            exit;
        }
        return $user;
    }

    private function requireUsersAdmin(): array
    {
        $user = require_auth();
        if (!PermissionService::canManageUsers($user)) {
            $this->forbidden('Изменять сотрудников может только директор.');
            exit;
        }
        return $user;
    }

    private function requireSettingsAdmin(): array
    {
        $user = require_auth();
        if (!PermissionService::canManageSettings($user)) {
            $this->forbidden('Должности и доступы может изменять только директор.');
            exit;
        }
        return $user;
    }

    private function requireBudgetDirector(): array
    {
        $user = require_auth();
        if (!PermissionService::canManageDepartmentBudget($user)) {
            $this->forbidden('Штатное расписание и бюджет доступны только директору департамента.');
            exit;
        }
        return $user;
    }

    private function activeManagers(int $exceptId = 0): array
    {
        $stmt = $this->db()->prepare('SELECT id, name, department FROM users WHERE is_active = 1 AND role <> ? AND id <> ? ORDER BY department, name');
        $stmt->execute([RoleService::ADMIN, $exceptId]);
        return $stmt->fetchAll();
    }

    private function createsManagerCycle(int $userId, int $managerId): bool
    {
        $parents = [];
        foreach ($this->db()->query('SELECT id, manager_id FROM users')->fetchAll() as $row) {
            $parents[(int) $row['id']] = (int) ($row['manager_id'] ?? 0);
        }
        $seen = [$userId => true];
        while ($managerId > 0) {
            if (isset($seen[$managerId])) {
                return true;
            }
            $seen[$managerId] = true;
            $managerId = $parents[$managerId] ?? 0;
        }
        return false;
    }

    private function activeDirectorCount(): int
    {
        $count = 0;
        foreach ($this->db()->query('SELECT role FROM users WHERE is_active = 1')->fetchAll() as $candidate) {
            if (RoleService::normalize((string) ($candidate['role'] ?? '')) === RoleService::DIRECTOR) {
                $count++;
            }
        }
        return $count;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = (int) $value;
        return $value > 0 ? $value : null;
    }

    private function privateNoStore(): void
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header('Cache-Control: no-store, private');
            header('Pragma: no-cache');
        }
    }

    private function forbidden(string $message): void
    {
        http_response_code(403);
        view('layouts/error', ['title' => 'Нет доступа', 'message' => $message]);
    }

    private function notFound(string $message): void
    {
        http_response_code(404);
        view('layouts/error', ['title' => 'Не найдено', 'message' => $message]);
    }
}
