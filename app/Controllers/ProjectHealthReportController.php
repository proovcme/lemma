<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PermissionService;
use App\Services\ProjectHealthReportService;
use App\Services\RoleService;

final class ProjectHealthReportController extends BaseController
{
    public function show(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project) {
            http_response_code(404);
            view('layouts/error', ['title' => 'Проект не найден', 'message' => 'Проект не найден или недоступен.']);
            return;
        }
        if (!PermissionService::canViewProjectStats($user, $project)) {
            http_response_code(403);
            view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Отчёт доступен ГИПу, РП и руководителям проекта.']);
            return;
        }
        $period = ProjectHealthReportService::period($_GET);
        $report = (new ProjectHealthReportService($this->db()))->build($project, $period);
        $this->render('projects/health_report', [
            'title' => 'Что у нас плохого', 'project' => $project, 'period' => $period, 'report' => $report,
            'canComment' => $this->canComment($user, $project),
        ]);
    }

    public function comment(int $id): void
    {
        $user = require_auth();
        $project = $this->project($id, $user);
        if (!$project || !$this->canComment($user, $project)) {
            http_response_code(403);
            view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Оставлять комментарии может ГИП, РП или руководство.']);
            return;
        }
        $period = ProjectHealthReportService::period($_POST);
        try {
            (new ProjectHealthReportService($this->db()))->saveComment($id, $period, (string) ($_POST['entity_type'] ?? ''), (int) ($_POST['entity_id'] ?? 0), (string) ($_POST['comment_text'] ?? ''), (int) $user['id']);
            flash('success', 'Комментарий сохранён.');
        } catch (\InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        }
        redirect('/projects/' . $id . '/health-report?' . http_build_query($period));
    }

    private function project(int $id, array $user): ?array
    {
        $select = 'SELECT p.*, gip.name AS gip_name, rp.name AS rp_name FROM projects p LEFT JOIN users gip ON gip.id = p.gip_user_id LEFT JOIN users rp ON rp.id = p.rp_user_id';
        if (PermissionService::canSeeAllProjects($user)) {
            $stmt = $this->db()->prepare($select . ' WHERE p.id = ?'); $stmt->execute([$id]); return $stmt->fetch() ?: null;
        }
        [$where, $params] = PermissionService::projectScopeWhere($user, 'p', 'health_scope_task');
        $stmt = $this->db()->prepare($select . ' WHERE p.id = :project_id AND ' . $where . ' LIMIT 1');
        $stmt->execute(['project_id' => $id] + $params); return $stmt->fetch() ?: null;
    }

    private function canComment(array $user, array $project): bool
    {
        $id = (int) $user['id'];
        return $id === (int) ($project['gip_user_id'] ?? 0) || $id === (int) ($project['rp_user_id'] ?? 0)
            || RoleService::isAny($user['role'] ?? null, [RoleService::DEPUTY_DIRECTOR, RoleService::DIRECTOR, RoleService::ADMIN]);
    }
}
