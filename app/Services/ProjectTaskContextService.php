<?php

declare(strict_types=1);
namespace App\Services;

use PDO;
use InvalidArgumentException;

final class ProjectTaskContextService
{
    public function __construct(private PDO $pdo) {}

    public function sections(int $projectId): array
    {
        $rows = [];
        $stages = $this->pdo->prepare('SELECT id FROM project_stages WHERE project_id = ? AND active = 1');
        $stages->execute([$projectId]);
        $activeStages = array_map('intval', $stages->fetchAll(PDO::FETCH_COLUMN));
        foreach ((new ProjectStructureService($this->pdo))->structure($projectId) as $group) {
            foreach ($group['sections'] as $row) {
                if (!empty($row['stage_id']) && !in_array((int) $row['stage_id'], $activeStages, true)) continue;
                $rows[] = $row;
            }
        }
        return $rows;
    }

    public function resolve(int $projectId, int $sectionId): array
    {
        $stmt = $this->pdo->prepare('SELECT status FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        $status = $stmt->fetchColumn();
        if ($status === false || $status === 'archived') throw new InvalidArgumentException('Создавать задачу можно только в активном проекте.');
        foreach ($this->sections($projectId) as $row) {
            if ((int) $row['id'] !== $sectionId) continue;
            $executors = $this->activePeople($row['executors']);
            $reviewers = $this->activePeople($row['reviewers']);
            // Legacy single-person assignments are read only when there is no modern role list.
            if (!$row['executors'] && !empty($row['assignee_id'])) $executors = $this->legacyPerson((int) $row['assignee_id']);
            if (!$row['reviewers'] && !empty($row['reviewer_id'])) $reviewers = $this->legacyPerson((int) $row['reviewer_id']);
            $assigneeId = count($executors) === 1 ? (int) $executors[0]['user_id'] : null;
            $reviewerId = count($reviewers) === 1 ? (int) $reviewers[0]['user_id'] : null;
            if ($assigneeId === $reviewerId) $reviewerId = null;
            return $row + ['suggested_assignee_id' => $assigneeId, 'suggested_reviewer_id' => $reviewerId,
                'suggested_executors' => $executors, 'suggested_reviewers' => $reviewers];
        }
        throw new InvalidArgumentException('Раздел убран из состава или не относится к выбранному проекту. Откройте состав проекта и выберите действующий раздел.');
    }

    /** Keep business composition separate from the employee accounting group. */
    public function bind(array $data, int $sectionId): array
    {
        $row = $this->resolve((int) $data['project_id'], $sectionId);
        if (count($row['suggested_reviewers']) > 1 && empty($data['reviewer_id']) && ($data['task_type'] ?? '') !== TaskWorkflowService::TASK_TYPE_DELEGATION) throw new InvalidArgumentException('В разделе несколько проверяющих. Выберите проверяющего результата в форме задачи.');
        if (!empty($data['reviewer_id']) && (int) $data['reviewer_id'] === (int) ($data['assignee_id'] ?? 0)) throw new InvalidArgumentException('Исполнитель и проверяющий должны быть разными людьми.');
        $data['project_section_id'] = (int) $row['id'];
        $data['section'] = (string) $row['code'];
        $data['discipline'] = (string) $row['code'];
        return $data;
    }

    private function activePeople(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => $this->legacyPerson((int) $row['user_id']) !== []));
    }

    private function legacyPerson(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT id AS user_id, name, department FROM users WHERE id = ? AND is_active = 1 AND role <> "admin"');
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    }
}
