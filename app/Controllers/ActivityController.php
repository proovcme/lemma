<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ActivityLogService;
use App\Services\PermissionService;

final class ActivityController extends BaseController
{
    public function index(): void
    {
        $user = require_auth();
        if (!PermissionService::canOpenReports($user)) {
            http_response_code(403);
            view('layouts/error', ['title' => 'Нет доступа', 'message' => 'История Лемме доступна руководящим ролям.']);
            return;
        }

        $this->render('activity/index', [
            'title' => 'История Лемме',
            'subtitle' => 'События задач, проектов и системных действий',
            'rows' => ActivityLogService::global($user, $_GET, 250),
            'projects' => $this->projects($user),
            'actions' => ActivityLogService::actions(),
            'filters' => $_GET,
        ]);
    }

    private function projects(array $user): array
    {
        if (PermissionService::canSeeAllProjects($user)) {
            return $this->db()->query('SELECT id, code, title FROM projects ORDER BY code')->fetchAll();
        }

        [$scope, $params] = PermissionService::taskScopeWhere($user);
        $stmt = $this->db()->prepare('
            SELECT DISTINCT p.id, p.code, p.title
            FROM projects p
            INNER JOIN tasks t ON t.project_id = p.id
            WHERE ' . $scope . '
               OR p.gip_user_id = :activity_project_gip_user_id
               OR p.rp_user_id = :activity_project_rp_user_id
            ORDER BY p.code
        ');
        $params['activity_project_gip_user_id'] = (int) $user['id'];
        $params['activity_project_rp_user_id'] = (int) $user['id'];
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
