<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PermissionService;

final class SearchController extends BaseController
{
    private const LIKE_ESCAPE = '!';
    private const MIN_QUERY_LENGTH = 2;
    private const TASK_LIMIT = 40;
    private const PROJECT_LIMIT = 20;
    private const RECORD_LIMIT = 12;
    private const USER_LIMIT = 20;

    public function index(): void
    {
        $user = require_auth();
        $query = trim((string) ($_GET['q'] ?? ''));
        $results = [
            'tasks' => [],
            'projects' => [],
            'records' => [],
            'users' => [],
        ];

        if (mb_strlen($query, 'UTF-8') >= self::MIN_QUERY_LENGTH) {
            $results['tasks'] = $this->tasks($user, $query);
            $results['projects'] = PermissionService::canOpenProjects($user) ? $this->projects($user, $query) : [];
            $results['records'] = PermissionService::canOpenProjects($user) ? $this->projectRecords($user, $query) : [];
            $results['users'] = PermissionService::canManageUsers($user) ? $this->users($query) : [];
        }

        $this->render('search/index', [
            'title' => 'Поиск',
            'query' => $query,
            'minQueryLength' => self::MIN_QUERY_LENGTH,
            'results' => $results,
        ]);
    }

    private function tasks(array $user, string $query): array
    {
        [$scopeSql, $scopeParams] = PermissionService::taskScopeWhere($user);
        $params = $scopeParams;
        $clauses = $this->likeClauses([
            'CAST(t.id AS CHAR)',
            't.title',
            't.discipline',
            't.section',
            't.volume',
            't.msp_task_uid',
            'CAST(t.msp_task_id AS CHAR)',
            'p.code',
            'p.title',
            'u.name',
            's.what',
            's.why',
        ], $params, $query, 'task');
        $clauses[] = "EXISTS (
            SELECT 1
            FROM task_tags tt
            INNER JOIN tags tg ON tg.id = tt.tag_id
            WHERE tt.task_id = t.id
              AND (tg.name LIKE :task_tag_name_like ESCAPE '!' OR tg.slug LIKE :task_tag_slug_like ESCAPE '!')
        )";
        $clauses[] = "EXISTS (
            SELECT 1
            FROM comments c
            WHERE c.task_id = t.id AND c.body LIKE :task_comment_like ESCAPE '!'
        )";
        $params['task_tag_name_like'] = $this->likePattern($query);
        $params['task_tag_slug_like'] = $this->likePattern($query);
        $params['task_comment_like'] = $this->likePattern($query);
        $params['task_prefix'] = $this->prefixPattern($query);
        $taskNumber = ltrim($query, '#');
        if (ctype_digit($taskNumber)) {
            $clauses[] = 't.id = :task_id_exact';
            $clauses[] = 't.msp_task_id = :task_msp_id_exact';
            $params['task_id_exact'] = (int) $taskNumber;
            $params['task_msp_id_exact'] = (int) $taskNumber;
        }

        $stmt = $this->db()->prepare("
            SELECT t.id, t.title, t.status, t.discipline, t.section, t.volume, t.date_end,
                   p.id AS project_id, p.code AS project_code, p.title AS project_title,
                   u.name AS assignee_name
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN users u ON u.id = t.assignee_id
            LEFT JOIN task_smart s ON s.task_id = t.id
            WHERE {$scopeSql}
              AND (" . implode(' OR ', $clauses) . ")
            ORDER BY
                CASE WHEN t.title LIKE :task_prefix ESCAPE '!' THEN 0 ELSE 1 END,
                t.date_end IS NULL,
                t.date_end,
                t.id DESC
            LIMIT " . self::TASK_LIMIT);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function projects(array $user, string $query): array
    {
        [$visibilitySql, $visibilityParams] = $this->projectVisibility($user, 'p');
        $params = $visibilityParams;
        $clauses = $this->likeClauses([
            'p.code',
            'p.title',
            'p.`object`',
            'p.stage',
            'p.status',
            'p.file_folder_url',
            'gu.name',
            'ru.name',
        ], $params, $query, 'project');
        $params['project_prefix'] = $this->prefixPattern($query);

        $stmt = $this->db()->prepare("
            SELECT p.id, p.code, p.title, p.`object`, p.stage, p.status,
                   gu.name AS gip_name, ru.name AS rp_name
            FROM projects p
            LEFT JOIN users gu ON gu.id = p.gip_user_id
            LEFT JOIN users ru ON ru.id = p.rp_user_id
            WHERE {$visibilitySql}
              AND (" . implode(' OR ', $clauses) . ")
            ORDER BY
                CASE WHEN p.code LIKE :project_prefix ESCAPE '!' THEN 0 ELSE 1 END,
                p.status = 'archived',
                p.code
            LIMIT " . self::PROJECT_LIMIT);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function projectRecords(array $user, string $query): array
    {
        $records = [];
        $configs = [
            [
                'kind' => 'schedule',
                'label' => 'График РД',
                'table' => 'project_schedule',
                'href' => 'schedule',
                'title' => "COALESCE(NULLIF(r.`object`, ''), NULLIF(r.volume, ''), NULLIF(r.section, ''), 'Строка графика')",
                'meta' => "COALESCE(NULLIF(r.issue_status, ''), NULLIF(r.rd_readiness_label, ''), NULLIF(r.rd_date_plan, ''), '')",
                'columns' => ['r.volume', 'r.`object`', 'r.section', 'r.object_type', 'r.rd_readiness_label', 'r.issue_status', 'r.rd_correction', 'r.comments'],
            ],
            [
                'kind' => 'sections',
                'label' => 'Раздел',
                'table' => 'project_sections',
                'href' => 'sections',
                'title' => "COALESCE(NULLIF(r.title, ''), NULLIF(r.code, ''), 'Раздел')",
                'meta' => "COALESCE(NULLIF(r.status, ''), NULLIF(r.date_end, ''), '')",
                'columns' => ['r.volume', 'r.code', 'r.title', 'r.status', 'r.comments'],
            ],
            [
                'kind' => 'issues',
                'label' => 'Вопрос',
                'table' => 'project_issues',
                'href' => 'issues',
                'title' => 'r.issue',
                'meta' => "COALESCE(NULLIF(r.status, ''), NULLIF(r.stage, ''), NULLIF(r.section_code, ''), '')",
                'columns' => ['r.section_code', 'r.issue', 'r.stage', 'r.answer', 'r.notes', 'r.status'],
            ],
            [
                'kind' => 'data',
                'label' => 'Исходные данные',
                'table' => 'project_data_registry',
                'href' => 'data',
                'title' => 'r.missing_data',
                'meta' => "COALESCE(NULLIF(r.status, ''), NULLIF(r.responsible, ''), NULLIF(r.section_code, ''), '')",
                'columns' => ['r.section_code', 'r.missing_data', 'r.responsible', 'r.status', 'r.impact', 'r.comments'],
            ],
            [
                'kind' => 'exchange',
                'label' => 'Обмен заданиями',
                'table' => 'project_task_exchange',
                'href' => 'exchange',
                'title' => 'r.assignment',
                'meta' => "COALESCE(NULLIF(r.status, ''), NULLIF(r.deadline, ''), NULLIF(r.to_section, ''), '')",
                'columns' => ['r.assignment', 'r.from_section', 'r.to_section', 'r.file_url', 'r.status', 'r.comments'],
            ],
            [
                'kind' => 'costs',
                'label' => 'План затрат',
                'table' => 'project_cost_plan',
                'href' => 'costs',
                'title' => 'r.work_name',
                'meta' => "COALESCE(NULLIF(r.sbc_collection, ''), NULLIF(r.sbc_table, ''), NULLIF(r.section_code, ''), '')",
                'columns' => ['r.section_code', 'r.sbc_collection', 'r.sbc_table', 'r.work_name', 'r.unit', 'r.price_level', 'r.justification', 'r.comments'],
            ],
        ];

        foreach ($configs as $config) {
            [$visibilitySql, $visibilityParams] = $this->projectVisibility($user, 'p');
            $params = $visibilityParams;
            if (($config['kind'] ?? '') === 'costs' && !PermissionService::canViewProjectFinance($user, ['gip_user_id' => 0, 'rp_user_id' => 0])) {
                $visibilitySql .= ' AND (p.gip_user_id = :cost_finance_user_id OR p.rp_user_id = :cost_finance_user_id)';
                $params['cost_finance_user_id'] = (int) ($user['id'] ?? 0);
            }
            $matches = $this->likeClauses($config['columns'], $params, $query, (string) $config['kind']);
            $stmt = $this->db()->prepare("
                SELECT r.id, p.id AS project_id, p.code AS project_code, p.title AS project_title,
                       {$config['title']} AS result_title,
                       {$config['meta']} AS result_meta
                FROM {$config['table']} r
                INNER JOIN projects p ON p.id = r.project_id
                WHERE {$visibilitySql}
                  AND (" . implode(' OR ', $matches) . ")
                ORDER BY r.id DESC
                LIMIT " . self::RECORD_LIMIT);
            $stmt->execute($params);

            foreach ($stmt->fetchAll() as $row) {
                $row['kind'] = $config['kind'];
                $row['label'] = $config['label'];
                $row['href'] = '/projects/' . (int) $row['project_id'] . '/' . $config['href'];
                $records[] = $row;
            }
        }

        return array_slice($records, 0, 50);
    }

    private function users(string $query): array
    {
        $params = [];
        $clauses = $this->likeClauses([
            'tab_number',
            'name',
            'email',
            'role',
            'department',
        ], $params, $query, 'user');

        $stmt = $this->db()->prepare("
            SELECT id, tab_number, name, email, role, department, is_active
            FROM users
            WHERE " . implode(' OR ', $clauses) . "
            ORDER BY is_active DESC, department, name
            LIMIT " . self::USER_LIMIT);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function projectVisibility(array $user, string $projectAlias): array
    {
        if (PermissionService::canSeeAllProjects($user)) {
            return ['1=1', []];
        }

        return PermissionService::projectScopeWhere($user, $projectAlias, 'search_scope_task');
    }

    private function likeClauses(array $columns, array &$params, string $query, string $prefix): array
    {
        $clauses = [];
        foreach ($columns as $index => $column) {
            $name = $prefix . '_like_' . $index;
            $params[$name] = $this->likePattern($query);
            $clauses[] = "{$column} LIKE :{$name} ESCAPE '" . self::LIKE_ESCAPE . "'";
        }

        return $clauses;
    }

    private function likePattern(string $query): string
    {
        return '%' . $this->escapeLike($query) . '%';
    }

    private function prefixPattern(string $query): string
    {
        return $this->escapeLike($query) . '%';
    }

    private function escapeLike(string $query): string
    {
        return strtr($query, [
            self::LIKE_ESCAPE => self::LIKE_ESCAPE . self::LIKE_ESCAPE,
            '%' => self::LIKE_ESCAPE . '%',
            '_' => self::LIKE_ESCAPE . '_',
        ]);
    }
}
