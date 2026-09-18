<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ActivityLogService;
use App\Services\PermissionService;
use App\Services\ProjectStructureService;

final class ProjectStructureController extends BaseController
{
    public function show(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) $this->notFound();
        $service = new ProjectStructureService($this->db());
        $this->render('projects/structure', [
            'title' => 'Структура и команда',
            'project' => $project,
            'teamRows' => $service->departmentTeamRows($id),
            'users' => $this->users(),
            'canEdit' => $this->canEdit($user, $project),
            'canManageDepartments' => PermissionService::canManageUsers($user),
            'isArchived' => (string) ($project['status'] ?? '') === 'archived',
        ]);
    }

    public function addStage(int $id): void
    {
        [$user, $project] = $this->editable($id);
        try {
            $stageId = (new ProjectStructureService($this->db()))->addStage($id, (string) ($_POST['code'] ?? ''), (string) ($_POST['title'] ?? ''), !empty($_POST['save_to_catalog']));
            ActivityLogService::recordProject($id, (int) $user['id'], 'project.structure_stage_created', 'Добавлена стадия проекта', 'Стадия #' . $stageId);
            flash('success', 'Стадия добавлена.');
        } catch (\InvalidArgumentException $error) { flash('error', $error->getMessage()); }
        redirect('/projects/' . $id . '/structure');
    }

    public function addItem(int $id): void
    {
        [$user, $project] = $this->editable($id);
        try {
            $service = new ProjectStructureService($this->db());
            $kind = (string) ($_POST['work_kind'] ?? 'section');
            $code = (string) ($_POST['code'] ?? '');
            $title = (string) ($_POST['title'] ?? '');
            $catalogCode = trim((string) ($_POST['catalog_code'] ?? ''));
            if ($kind === 'section' && $catalogCode !== '') {
                $catalogSections = [];
                $catalog = $service->catalog();
                foreach ([...$catalog['templates']['pp87'], ...$catalog['templates']['rd'], ...$catalog['sections'], ...$catalog['activities']] as $option) {
                    $catalogSections[(string) ($option['value'] ?? '')] = (string) ($option['label'] ?? '');
                }
                if (!array_key_exists($catalogCode, $catalogSections)) {
                    throw new \InvalidArgumentException('Выбранный раздел отсутствует в справочнике.');
                }
                $code = $catalogCode;
                $title = $catalogSections[$catalogCode];
            }
            $itemId = $service->addWorkItemWithAssignments(
                $id,
                ($_POST['stage_id'] ?? '') !== '' ? (int) $_POST['stage_id'] : null,
                $kind,
                $code,
                $title,
                !empty($_POST['save_to_catalog']),
                (array) ($_POST['new_executor_ids'] ?? $_POST['executor_ids'] ?? []),
                (array) ($_POST['new_reviewer_ids'] ?? $_POST['reviewer_ids'] ?? [])
            );
            ActivityLogService::recordProject($id, (int) $user['id'], 'project.structure_item_created', 'Добавлена строка структуры проекта', 'Строка #' . $itemId);
            flash('success', 'Раздел добавлен в проект и доступен для привязки к задачам.');
        } catch (\InvalidArgumentException $error) { flash('error', $error->getMessage()); }
        redirect('/projects/' . $id . '/structure#project-team-table');
    }

    public function assignmentsTable(int $id): void
    {
        [$user, $project] = $this->editable($id);
        try {
            $count = (new ProjectStructureService($this->db()))->syncDepartmentTeamTable(
                $id,
                (array) ($_POST['department_codes'] ?? []),
                (array) ($_POST['executor_ids'] ?? []),
                (array) ($_POST['reviewer_id'] ?? [])
            );
            ActivityLogService::recordProject($id, (int) $user['id'], 'project.structure_assignments_updated', 'Обновлена таблица команды проекта', 'Разделов: ' . $count);
            flash('success', 'Таблица команды сохранена: ' . $count . ' разделов.');
        } catch (\InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        }
        redirect('/projects/' . $id . '/structure#project-team-table');
    }

    public function assignments(int $id, int $sectionId): void
    {
        [$user, $project] = $this->editable($id);
        try {
            (new ProjectStructureService($this->db()))->syncAssignments($id, $sectionId, (array) ($_POST['executor_ids'] ?? []), (array) ($_POST['reviewer_ids'] ?? []));
            ActivityLogService::recordProject($id, (int) $user['id'], 'project.structure_assignments_updated', 'Обновлены разработчики и проверяющие', 'Строка #' . $sectionId);
            flash('success', 'Назначения сохранены.');
        } catch (\InvalidArgumentException $error) { flash('error', $error->getMessage()); }
        redirect('/projects/' . $id . '/structure#work-item-' . $sectionId);
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function editable(int $id): array
    {
        $user = require_auth(); $project = $this->project($id, $user);
        if (!$project) $this->notFound();
        if (!$this->canEdit($user, $project) || (string) ($project['status'] ?? '') === 'archived') {
            http_response_code(403); view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Структуру активного проекта меняет ГИП, РП или руководство.']); exit;
        }
        return [$user, $project];
    }

    private function project(int $id, array $user): ?array
    {
        $select = 'SELECT p.*, gip.name AS gip_name, rp.name AS rp_name FROM projects p LEFT JOIN users gip ON gip.id = p.gip_user_id LEFT JOIN users rp ON rp.id = p.rp_user_id';
        if (PermissionService::canSeeAllProjects($user)) { $stmt = $this->db()->prepare($select . ' WHERE p.id = ?'); $stmt->execute([$id]); return $stmt->fetch() ?: null; }
        [$where, $params] = PermissionService::projectScopeWhere($user, 'p', 'structure_scope_task');
        $stmt = $this->db()->prepare($select . ' WHERE p.id = :project_id AND ' . $where . ' LIMIT 1'); $stmt->execute(['project_id' => $id] + $params); return $stmt->fetch() ?: null;
    }

    private function canEdit(array $user, array $project): bool
    {
        return PermissionService::canCreateProject($user) || (int) $user['id'] === (int) ($project['gip_user_id'] ?? 0) || (int) $user['id'] === (int) ($project['rp_user_id'] ?? 0);
    }

    /** @return list<array<string,mixed>> */
    private function users(): array
    {
        return $this->db()->query('SELECT id, name, department, role, is_active FROM users WHERE is_active = 1 AND role <> "admin" ORDER BY department, name')->fetchAll();
    }

    private function notFound(): never
    {
        http_response_code(404); view('layouts/error', ['title' => 'Проект не найден', 'message' => 'Проект не найден или недоступен.']); exit;
    }
}
