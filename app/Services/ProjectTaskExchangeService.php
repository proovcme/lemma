<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class ProjectTaskExchangeService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function syncTask(int $taskId): bool
    {
        $type = $this->pdo->prepare('SELECT task_type FROM tasks WHERE id = ? LIMIT 1');
        $type->execute([$taskId]);
        if ((string) $type->fetchColumn() !== 'assignment') {
            return false;
        }

        $stmt = $this->pdo->prepare('
            SELECT t.*, ts.what AS smart_what,
                   a.name AS assignee_name, a.department AS assignee_department,
                   au.name AS author_name, au.department AS author_department,
                   r.name AS reviewer_name,
                   p.stage AS project_stage, p.file_folder_url,
                   ptask.assignee_id AS parent_assignee_id,
                   ptask.title AS parent_title,
                   ptask.section AS parent_section,
                   ptask.discipline AS parent_discipline,
                   ptask.volume AS parent_volume,
                   ps.linked_section_code,
                   ps.linked_section_title,
                   ps.linked_section_volume
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN task_smart ts ON ts.task_id = t.id
            LEFT JOIN users a ON a.id = t.assignee_id
            LEFT JOIN users au ON au.id = t.author_id
            LEFT JOIN users r ON r.id = t.reviewer_id
            LEFT JOIN tasks ptask ON ptask.id = t.parent_id
            LEFT JOIN (
                SELECT task_id,
                       MIN(code) AS linked_section_code,
                       MIN(title) AS linked_section_title,
                       MIN(volume) AS linked_section_volume
                FROM project_sections
                WHERE task_id IS NOT NULL
                GROUP BY task_id
            ) ps ON ps.task_id = t.id
            WHERE t.id = ? AND t.task_type = "assignment"
            LIMIT 1
        ');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$task) {
            return false;
        }

        $projectId = (int) $task['project_id'];
        $fromSection = $this->sourceLabel($task);
        $toSection = $this->targetLabel($task);
        $assignment = trim((string) ($task['smart_what'] ?? ''));
        $missingAssignment = $assignment === '';
        if ($missingAssignment) {
            $assignment = 'Нет задания: ' . trim((string) ($task['title'] ?? ''));
        }
        $missingSection = $toSection === '' || $toSection === 'Исполнитель';
        $status = ($missingAssignment || $missingSection)
            ? 'blocked'
            : $this->statusFromTask((string) ($task['status'] ?? ''));
        $values = [
            $this->directionFromTitle((string) ($task['title'] ?? '')),
            $this->sourceUserId($task),
            ((int) ($task['assignee_id'] ?? 0)) ?: null,
            $assignment,
            $fromSection,
            $toSection,
            $this->fileUrl($task, $toSection),
            $this->dateOrNull($task['date_start'] ?? null) ?: $this->dateOrNull(substr((string) ($task['created_at'] ?? ''), 0, 10)),
            $this->dateOrNull($task['date_end'] ?? null),
            $status,
            $this->comment($task, $missingAssignment, $missingSection),
        ];

        $existing = $this->pdo->prepare('SELECT id FROM project_task_exchange WHERE project_id = ? AND task_id = ? LIMIT 1');
        $existing->execute([$projectId, $taskId]);
        $exchangeId = $existing->fetchColumn();
        if ($exchangeId !== false) {
            $this->pdo->prepare('
                UPDATE project_task_exchange
                SET direction = ?, from_user_id = ?, to_user_id = ?, assignment = ?,
                    from_section = ?, to_section = ?, file_url = ?, date_issued = ?,
                    deadline = ?, status = ?, comments = ?
                WHERE id = ?
            ')->execute([...$values, (int) $exchangeId]);
            return true;
        }

        $numStmt = $this->pdo->prepare('SELECT COALESCE(MAX(num), 0) + 1 FROM project_task_exchange WHERE project_id = ?');
        $numStmt->execute([$projectId]);
        $num = (int) $numStmt->fetchColumn();
        $this->pdo->prepare('
            INSERT INTO project_task_exchange (
                project_id, task_id, direction, from_user_id, to_user_id, num,
                assignment, from_section, to_section, file_url, date_issued,
                deadline, status, comments
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([$projectId, $taskId, $values[0], $values[1], $values[2], $num, ...array_slice($values, 3)]);

        return true;
    }

    private function directionFromTitle(string $title): string
    {
        $title = mb_strtolower(trim($title), 'UTF-8');
        return str_starts_with($title, 'запрос')
            || str_contains($title, 'получить задание')
            || str_contains($title, 'ждём задание')
            || str_contains($title, 'ждем задание')
            ? 'incoming'
            : 'outgoing';
    }

    private function statusFromTask(string $status): string
    {
        return match ($status) {
            'done', 'issued' => 'done',
            'blocked', 'overdue', 'correction' => 'blocked',
            'new', 'review', 'pending_close' => 'pending',
            default => 'in_progress',
        };
    }

    private function sourceLabel(array $task): string
    {
        foreach (['parent_section', 'parent_discipline', 'parent_volume', 'author_department'] as $key) {
            if (($value = trim((string) ($task[$key] ?? ''))) !== '') {
                return $value;
            }
        }
        return 'Постановщик';
    }

    private function targetLabel(array $task): string
    {
        foreach (['linked_section_code', 'section', 'discipline', 'linked_section_volume', 'volume', 'assignee_department'] as $key) {
            if (($value = trim((string) ($task[$key] ?? ''))) !== '') {
                return $value;
            }
        }
        return 'Исполнитель';
    }

    private function sourceUserId(array $task): ?int
    {
        foreach (['parent_assignee_id', 'author_id'] as $key) {
            if (($id = (int) ($task[$key] ?? 0)) > 0) {
                return $id;
            }
        }
        return null;
    }

    private function comment(array $task, bool $missingAssignment, bool $missingSection): string
    {
        $parts = ['Из задачи #' . (int) $task['id']];
        if ($missingAssignment) {
            $parts[] = 'Блокер: не заполнена постановка "Что сделать"';
        }
        if ($missingSection) {
            $parts[] = 'Блокер: не указан раздел для папки задания';
        }
        if (!empty($task['parent_id'])) {
            $parts[] = 'источник #' . (int) $task['parent_id'] . ': ' . trim((string) ($task['parent_title'] ?? ''));
        }
        foreach (['author_name' => 'постановщик', 'assignee_name' => 'исполнитель', 'reviewer_name' => 'проверяющий'] as $key => $label) {
            if (($value = trim((string) ($task[$key] ?? ''))) !== '') {
                $parts[] = $label . ': ' . $value;
            }
        }
        return implode('; ', $parts);
    }

    private function fileUrl(array $task, string $section): string
    {
        $root = trim((string) ($task['file_folder_url'] ?? ''));
        if ($root === '' || $section === '' || $section === 'Исполнитель') {
            return '';
        }
        $stage = in_array((string) ($task['project_stage'] ?? ''), ['ПД', 'П'], true) ? 'Стадия_П' : 'Стадия_Р';
        $segment = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]+/u', '_', trim($section)) ?? '';
        $segment = trim((string) preg_replace('/\s+/u', '_', $segment), '._ ');
        return file_path_join($root, '02_Общие_данные (SHARED)/' . $stage . '/F_ЗАДАНИЯ_Исходящие/' . ($segment !== '' ? $segment : 'Без_раздела'));
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
