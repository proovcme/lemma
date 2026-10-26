<?php

declare(strict_types=1);
namespace App\Controllers;

use App\Services\{ActivityLogService, PermissionService, ProjectStructureService, ProjectSectionWorkspaceService};

final class ProjectTeamWorkspaceController extends BaseController
{
    public function show(int $id): void
    {
        [$user, $project] = $this->access($id);
        $service = new ProjectSectionWorkspaceService($this->db());
        view('projects/team_workspace', [
            'title' => 'Разделы и команда', 'project' => $project, 'user' => $user,
            'structure' => (new ProjectStructureService($this->db()))->structure($id),
            'removed' => $service->removed($id), 'catalogOptions' => $service->catalogOptions(),
            'users' => $this->db()->query('SELECT id, name, department, is_active FROM users WHERE role <> "admin" ORDER BY name')->fetchAll(),
            'canEdit' => $this->canEdit($user, $project) && $project['status'] !== 'archived',
            'canManageCatalog' => PermissionService::canManageUsers($user),
        ], 'layouts/workspace');
    }

    public function add(int $id): void
    {
        [$user, $project] = $this->access($id, true);
        try {
            $sectionId = (new ProjectSectionWorkspaceService($this->db()))->add($id,
                (int) ($_POST['stage_id'] ?? 0) ?: null, trim((string) ($_POST['catalog_code'] ?? '')),
                (string) ($_POST['code'] ?? ''), (string) ($_POST['title'] ?? ''),
                !empty($_POST['save_to_catalog']), PermissionService::canManageUsers($user));
            ActivityLogService::recordProject($id, (int) $user['id'], 'project.structure_item_created', 'Добавлен раздел проекта', 'Раздел #' . $sectionId);
            flash('success', 'Раздел добавлен. Теперь можно назначить команду.');
        } catch (\InvalidArgumentException $error) { flash('error', $error->getMessage()); }
        redirect('/projects/' . $id . '/structure');
    }

    public function state(int $id, int $sectionId): void
    {
        [$user, $project] = $this->access($id, true);
        try {
            if (!in_array((string) ($_POST['active'] ?? ''), ['0', '1'], true)) throw new \InvalidArgumentException('Укажите действие: убрать или вернуть раздел.');
            $active = (string) $_POST['active'] === '1';
            (new ProjectSectionWorkspaceService($this->db()))->setActive($id, $sectionId, $active);
            ActivityLogService::recordProject($id, (int) $user['id'], $active ? 'project.section_restored' : 'project.section_removed', $active ? 'Раздел возвращён в проект' : 'Раздел убран из состава проекта', 'Раздел #' . $sectionId);
            flash('success', $active ? 'Раздел возвращён вместе с назначениями.' : 'Раздел убран из состава. Задачи, назначения и история сохранены; раздел можно вернуть ниже.');
        } catch (\InvalidArgumentException $error) { flash('error', $error->getMessage()); }
        redirect('/projects/' . $id . '/structure');
    }

    private function canEdit(array $user, array $project): bool
    {
        return PermissionService::canCreateProject($user) || in_array((int) $user['id'], [(int) ($project['gip_user_id'] ?? 0), (int) ($project['rp_user_id'] ?? 0)], true);
    }

    private function access(int $id, bool $write = false): array
    {
        $user = require_auth();
        [$where, $params] = PermissionService::canSeeAllProjects($user) ? ['1=1', []] : PermissionService::projectScopeWhere($user, 'p', 'team_scope_task');
        $stmt = $this->db()->prepare('SELECT p.* FROM projects p WHERE p.id = :project_id AND ' . $where);
        $stmt->execute(['project_id' => $id] + $params);
        $project = $stmt->fetch();
        if (!$project || ($write && (!$this->canEdit($user, $project) || $project['status'] === 'archived'))) {
            http_response_code($project ? 403 : 404);
            view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Состав активного проекта меняет ГИП, РП или руководство.']);
            exit;
        }
        return [$user, $project];
    }
}
