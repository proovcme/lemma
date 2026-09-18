<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class ProjectManagementTaskService
{
    private const TITLE = 'Управление проектом';
    private const SECTION = 'Управление проектом';

    public function __construct(private PDO $pdo)
    {
    }

    public function ensure(int $projectId, int $gipUserId, int $authorId, ?int $reviewerId = null): array
    {
        if ($projectId <= 0 || $gipUserId <= 0) {
            return ['id' => null, 'created' => false];
        }

        $managementSectionId = (new ProjectStructureService($this->pdo))->managementActivityId($projectId);
        $stmt = $this->pdo->prepare('
            SELECT id
            FROM tasks
            WHERE project_id = ?
              AND title = ?
              AND COALESCE(section, "") = ?
            ORDER BY id
            LIMIT 1
        ');
        $stmt->execute([$projectId, self::TITLE, self::SECTION]);
        $taskId = (int) ($stmt->fetchColumn() ?: 0);

        if ($taskId > 0) {
            $this->pdo->prepare('
                UPDATE tasks
                SET assignee_id = ?, reviewer_id = ?, project_section_id = COALESCE(?, project_section_id), updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ')->execute([$gipUserId, $reviewerId, $managementSectionId, $taskId]);
            $this->ensureSmart($taskId);
            ActivityLogService::recordTask($taskId, $authorId > 0 ? $authorId : null, 'project.management_task_updated', 'Задача управления проектом переназначена', 'Назначенный ГИП обновлён.');

            return ['id' => $taskId, 'created' => false];
        }

        $this->pdo->prepare('
            INSERT INTO tasks (
                title, task_type, project_id, project_section_id, parent_id, assignee_id, author_id, reviewer_id,
                discipline, volume, section, status, priority, urgency, date_start, date_end,
                date_end_original, planned_hours, progress, btp, speckle_stream_url
            )
            VALUES (
                ?, "work", ?, ?, NULL, ?, ?, ?,
                "ГИП", "", ?, "new", "mid", "mid", ?, NULL,
                NULL, NULL, 0, "", ""
            )
        ')->execute([
            self::TITLE,
            $projectId,
            $managementSectionId,
            $gipUserId,
            $authorId > 0 ? $authorId : $gipUserId,
            $reviewerId,
            self::SECTION,
            date('Y-m-d'),
        ]);
        $taskId = (int) $this->pdo->lastInsertId();
        $this->ensureSmart($taskId);
        ActivityLogService::recordTask($taskId, $authorId > 0 ? $authorId : null, 'project.management_task_created', 'Создана задача управления проектом', 'Система назначила проектное управление на ГИПа.');

        return ['id' => $taskId, 'created' => true];
    }

    private function ensureSmart(int $taskId): void
    {
        $stmt = $this->pdo->prepare('SELECT task_id FROM task_smart WHERE task_id = ? LIMIT 1');
        $stmt->execute([$taskId]);
        if ($stmt->fetchColumn()) {
            $this->pdo->prepare('
                UPDATE task_smart
                SET what = ?, when_due = ?, why = ?, depends_on = COALESCE(depends_on, "")
                WHERE task_id = ?
            ')->execute([$this->what(), 'Весь проект', 'Проектное управление', $taskId]);
            return;
        }

        $this->pdo->prepare('
            INSERT INTO task_smart (task_id, what, when_due, why, depends_on)
            VALUES (?, ?, ?, ?, "")
        ')->execute([$taskId, $this->what(), 'Весь проект', 'Проектное управление']);
    }

    private function what(): string
    {
        return 'Координация проекта, совещания, коммуникации, контроль сроков, управление изменениями и организационная работа ГИПа.';
    }
}
