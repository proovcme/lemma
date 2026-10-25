<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\PermissionService;

/** Public-demo presentation only. Caller supplies already authorized project/tasks. */
final class DemoProjectWorkspaceController extends BaseController
{
    public function show(array $project, array $user, array $tasks): void
    {
        if (!app_is_demo_mode()) {
            http_response_code(404);
            return;
        }
        $stmt = $this->db()->prepare('SELECT id, missing_data, section_code, status, date_received_plan FROM project_data_registry WHERE project_id = ? ORDER BY num, id');
        $stmt->execute([(int) $project['id']]);
        $payload = [
            'project' => array_intersect_key($project, array_flip(['id', 'code', 'title', 'object', 'stage', 'finish_date', 'status'])),
            'user' => ['name' => (string) $user['name']],
            'canEdit' => PermissionService::canEditProjectTabs($user, $project),
            'canOpenProjects' => PermissionService::canOpenProjects($user),
            'tasks' => array_map(static fn (array $task): array => array_intersect_key($task, array_flip(['id', 'title', 'status', 'date_end', 'section', 'assignee_name', 'reviewer_name'])), $tasks),
            'documents' => $stmt->fetchAll(),
            'base' => url(''),
        ];
        view('projects/demo_workspace', ['payload' => $payload, 'project' => $project, 'user' => $user], '');
    }
}
