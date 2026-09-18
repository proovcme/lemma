<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use PDO;

final class PersonalNoteService
{
    /** @return array<int, array<string, mixed>> */
    public static function list(PDO $pdo, int $authorId, array $filters = []): array
    {
        $where = ['n.author_id = :author_id'];
        $params = ['author_id' => $authorId];

        $status = (string) ($filters['status'] ?? 'active');
        if (in_array($status, ['active', 'archived', 'converted'], true)) {
            $where[] = 'n.status = :status';
            $params['status'] = $status;
        } else {
            $where[] = 'n.status != "archived"';
        }

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $where[] = '(n.title LIKE :q OR n.body LIKE :q)';
            $params['q'] = '%' . $query . '%';
        }

        $projectId = (int) ($filters['project_id'] ?? 0);
        if ($projectId > 0) {
            $where[] = 'n.project_id = :project_id';
            $params['project_id'] = $projectId;
        }

        $stmt = $pdo->prepare('
            SELECT n.*, p.code AS project_code, p.title AS project_title, t.status AS converted_task_status
            FROM personal_notes n
            LEFT JOIN projects p ON p.id = n.project_id
            LEFT JOIN tasks t ON t.id = n.converted_task_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY n.pinned DESC, n.updated_at DESC, n.id DESC
        ');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function find(PDO $pdo, int $id, int $authorId): ?array
    {
        $stmt = $pdo->prepare('
            SELECT n.*, p.code AS project_code, p.title AS project_title
            FROM personal_notes n
            LEFT JOIN projects p ON p.id = n.project_id
            WHERE n.id = ? AND n.author_id = ?
        ');
        $stmt->execute([$id, $authorId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public static function create(PDO $pdo, int $authorId, array $data): int
    {
        $payload = self::payload($data);
        $stmt = $pdo->prepare('
            INSERT INTO personal_notes (author_id, project_id, title, body, color, pinned)
            VALUES (:author_id, :project_id, :title, :body, :color, :pinned)
        ');
        $stmt->execute([
            'author_id' => $authorId,
            'project_id' => $payload['project_id'],
            'title' => $payload['title'],
            'body' => $payload['body'],
            'color' => $payload['color'],
            'pinned' => $payload['pinned'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    public static function update(PDO $pdo, int $id, int $authorId, array $data): bool
    {
        $payload = self::payload($data);
        $stmt = $pdo->prepare('
            UPDATE personal_notes
            SET project_id = :project_id,
                title = :title,
                body = :body,
                color = :color,
                pinned = :pinned,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND author_id = :author_id AND status != "converted"
        ');
        $stmt->execute([
            'project_id' => $payload['project_id'],
            'title' => $payload['title'],
            'body' => $payload['body'],
            'color' => $payload['color'],
            'pinned' => $payload['pinned'],
            'id' => $id,
            'author_id' => $authorId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public static function setStatus(PDO $pdo, int $id, int $authorId, string $status): bool
    {
        if (!in_array($status, ['active', 'archived'], true)) {
            throw new InvalidArgumentException('Некорректный статус заметки.');
        }

        $stmt = $pdo->prepare('
            UPDATE personal_notes
            SET status = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND author_id = ? AND status != "converted"
        ');
        $stmt->execute([$status, $id, $authorId]);

        return $stmt->rowCount() > 0;
    }

    public static function convertToTask(PDO $pdo, array $note, int $authorId, array $data): int
    {
        if ((string) ($note['status'] ?? 'active') === 'converted') {
            throw new InvalidArgumentException('Заметка уже превращена в задачу.');
        }

        $projectId = (int) ($data['project_id'] ?? ($note['project_id'] ?? 0));
        $assigneeId = (int) ($data['assignee_id'] ?? $authorId);
        $requestedTaskType = (string) ($data['task_type'] ?? 'work');
        $taskType = in_array($requestedTaskType, ['work', 'assignment', 'bim_family_request'], true)
            ? $requestedTaskType
            : 'work';
        $dateEnd = self::dateOrNull((string) ($data['date_end'] ?? ''));
        $plannedHours = trim((string) ($data['planned_hours'] ?? '')) !== '' ? max(0.0, (float) $data['planned_hours']) : null;

        if ($projectId <= 0) {
            throw new InvalidArgumentException('Выберите проект для задачи.');
        }

        $project = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE id = ? AND status = "active"');
        $project->execute([$projectId]);
        if ((int) $project->fetchColumn() === 0) {
            throw new InvalidArgumentException('Проект не найден или архивный.');
        }

        if ($assigneeId <= 0) {
            $assigneeId = $authorId;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('
                INSERT INTO tasks (
                    title, task_type, project_id, assignee_id, author_id,
                    status, priority, urgency, date_start, date_end, date_end_original,
                    planned_hours, progress
                )
                VALUES (
                    :title, :task_type, :project_id, :assignee_id, :author_id,
                    "new", "mid", "mid", NULL, :date_end, :date_end_original,
                    :planned_hours, 0
                )
            ');
            $stmt->execute([
                'title' => (string) ($note['title'] ?? 'Задача из заметки'),
                'task_type' => $taskType,
                'project_id' => $projectId,
                'assignee_id' => $assigneeId,
                'author_id' => $authorId,
                'date_end' => $dateEnd,
                'date_end_original' => $dateEnd,
                'planned_hours' => $plannedHours,
            ]);
            $taskId = (int) $pdo->lastInsertId();

            $smart = $pdo->prepare('INSERT INTO task_smart (task_id, what, when_due, why, depends_on) VALUES (?, ?, ?, ?, NULL)');
            $smart->execute([
                $taskId,
                trim((string) ($note['body'] ?? '')) !== '' ? (string) $note['body'] : (string) ($note['title'] ?? ''),
                $dateEnd ?: '',
                'Создано из личной заметки #' . (int) ($note['id'] ?? 0),
            ]);

            $updated = $pdo->prepare('
                UPDATE personal_notes
                SET status = "converted", converted_task_id = ?, converted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND author_id = ?
            ');
            $updated->execute([$taskId, (int) ($note['id'] ?? 0), $authorId]);

            $pdo->commit();
            return $taskId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function payload(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));

        if ($title === '') {
            throw new InvalidArgumentException('Название заметки обязательно.');
        }
        if ($body === '') {
            throw new InvalidArgumentException('Текст заметки обязателен.');
        }

        $color = trim((string) ($data['color'] ?? ''));
        if (!in_array($color, ['yellow', 'blue', 'green', 'red', 'gray'], true)) {
            $color = 'yellow';
        }

        $projectId = (int) ($data['project_id'] ?? 0);

        return [
            'title' => $title,
            'body' => $body,
            'project_id' => $projectId > 0 ? $projectId : null,
            'color' => $color,
            'pinned' => !empty($data['pinned']) ? 1 : 0,
        ];
    }

    private static function dateOrNull(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
