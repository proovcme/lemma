<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

final class ProjectHealthReportService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{date_from:string,date_to:string} */
    public static function period(array $input): array
    {
        $today = new DateTimeImmutable('today');
        $from = self::date((string) ($input['date_from'] ?? '')) ?: $today->modify('monday this week')->format('Y-m-d');
        $to = self::date((string) ($input['date_to'] ?? '')) ?: $today->format('Y-m-d');
        if ($from > $to) [$from, $to] = [$to, $from];
        return ['date_from' => $from, 'date_to' => $to];
    }

    /** @return array<string,mixed> */
    public function build(array $project, array $period): array
    {
        $projectId = (int) $project['id'];
        $today = date('Y-m-d');
        $stmt = $this->pdo->prepare('SELECT t.id, t.project_section_id, t.title, t.task_type, t.status, t.approval_stage,
                t.date_start, t.date_end, t.planned_hours, t.actual_hours, t.progress, t.close_requested_at, t.closed_at,
                t.assignee_id, t.reviewer_id, t.created_at, t.updated_at,
                assignee.name AS assignee_name, reviewer.name AS reviewer_name
            FROM tasks t
            LEFT JOIN users assignee ON assignee.id = t.assignee_id
            LEFT JOIN users reviewer ON reviewer.id = t.reviewer_id
            WHERE t.project_id = ? AND COALESCE(t.task_type, "") <> "review"
              AND (t.status <> "done" OR DATE(COALESCE(t.closed_at, t.updated_at)) BETWEEN ? AND ? OR DATE(t.created_at) BETWEEN ? AND ?)
            ORDER BY t.status = "done", t.date_end IS NULL, t.date_end, t.id');
        $stmt->execute([$projectId, $period['date_from'], $period['date_to'], $period['date_from'], $period['date_to']]);
        $tasks = $stmt->fetchAll();

        $taskMap = [];
        $summary = ['total' => 0, 'open' => 0, 'done_period' => 0, 'problem' => 0, 'overdue' => 0, 'blocked' => 0, 'review' => 0, 'unlinked' => 0];
        foreach ($tasks as &$task) {
            $task['problems'] = $this->taskProblems($task, $today);
            $task['is_problem'] = $task['problems'] !== [];
            $sectionId = (int) ($task['project_section_id'] ?? 0);
            $taskMap[$sectionId][] = $task;
            $summary['total']++;
            if ((string) $task['status'] === 'done') $summary['done_period']++; else $summary['open']++;
            if ($task['is_problem']) $summary['problem']++;
            if ((string) $task['status'] === 'blocked') $summary['blocked']++;
            if ((string) $task['status'] !== 'done' && (string) ($task['date_end'] ?? '') !== '' && (string) $task['date_end'] < $today) $summary['overdue']++;
            if (in_array((string) $task['status'], ['review', 'pending_close'], true) || in_array((string) $task['approval_stage'], ['review_lead', 'review_gip'], true)) $summary['review']++;
            if ($sectionId <= 0) $summary['unlinked']++;
        }
        unset($task);

        $structure = (new ProjectStructureService($this->pdo))->structure($projectId);
        foreach ($structure as &$stage) {
            $stageStats = ['total' => 0, 'open' => 0, 'problem' => 0, 'done_period' => 0];
            foreach ($stage['sections'] as &$section) {
                $section['tasks'] = $taskMap[(int) $section['id']] ?? [];
                $section['stats'] = $this->stats($section['tasks']);
                foreach ($section['stats'] as $key => $value) $stageStats[$key] += $value;
            }
            unset($section);
            $stage['stats'] = $stageStats;
        }
        unset($stage);

        $unlinked = $taskMap[0] ?? [];
        $comments = $this->comments($projectId, $period);
        return compact('summary', 'structure', 'unlinked', 'comments');
    }

    public function saveComment(int $projectId, array $period, string $entityType, int $entityId, string $text, int $authorId): void
    {
        if (!in_array($entityType, ['project', 'stage', 'section', 'task'], true)) throw new InvalidArgumentException('Неизвестный уровень комментария.');
        if ($entityType === 'project') $entityId = 0;
        $text = trim($text);
        if (mb_strlen($text) > 4000) throw new InvalidArgumentException('Комментарий должен быть короче 4000 символов.');
        $this->assertEntity($projectId, $entityType, $entityId);
        if ($text === '') {
            $this->pdo->prepare('DELETE FROM project_health_comments WHERE project_id = ? AND date_from = ? AND date_to = ? AND entity_type = ? AND entity_id = ?')
                ->execute([$projectId, $period['date_from'], $period['date_to'], $entityType, $entityId]);
            return;
        }
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $sql = 'INSERT INTO project_health_comments (project_id, date_from, date_to, entity_type, entity_id, comment_text, author_id)
                VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT(project_id, date_from, date_to, entity_type, entity_id) DO UPDATE SET comment_text = excluded.comment_text, author_id = excluded.author_id, updated_at = CURRENT_TIMESTAMP';
        } else {
            $sql = 'INSERT INTO project_health_comments (project_id, date_from, date_to, entity_type, entity_id, comment_text, author_id)
                VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE comment_text = VALUES(comment_text), author_id = VALUES(author_id), updated_at = CURRENT_TIMESTAMP';
        }
        $this->pdo->prepare($sql)->execute([$projectId, $period['date_from'], $period['date_to'], $entityType, $entityId, $text, $authorId]);
    }

    /** @param list<array<string,mixed>> $tasks @return array{total:int,open:int,problem:int,done_period:int} */
    private function stats(array $tasks): array
    {
        $stats = ['total' => count($tasks), 'open' => 0, 'problem' => 0, 'done_period' => 0];
        foreach ($tasks as $task) {
            if ((string) $task['status'] === 'done') $stats['done_period']++; else $stats['open']++;
            if (!empty($task['is_problem'])) $stats['problem']++;
        }
        return $stats;
    }

    /** @return list<string> */
    private function taskProblems(array $task, string $today): array
    {
        $result = [];
        $status = (string) ($task['status'] ?? '');
        if ($status === 'blocked') $result[] = 'Заблокирована';
        if ($status === 'correction') $result[] = 'Возвращена на корректировку';
        if ($status !== 'done' && (string) ($task['date_end'] ?? '') !== '' && (string) $task['date_end'] < $today) $result[] = 'Просрочена';
        if ($status !== 'done' && empty($task['assignee_id'])) $result[] = 'Нет исполнителя';
        if ((string) ($task['date_start'] ?? '') !== '' && (string) ($task['date_end'] ?? '') !== '' && (string) $task['date_start'] > (string) $task['date_end']) $result[] = 'Дата начала позже срока';
        if ($status === 'done' && (int) ($task['progress'] ?? 0) !== 100) $result[] = 'Закрыта с прогрессом не 100%';
        if ($status !== 'done' && (int) ($task['progress'] ?? 0) >= 100) $result[] = '100%, но не закрыта';
        if (in_array($status, ['review', 'pending_close'], true) && empty($task['close_requested_at'])) $result[] = 'Проверка без запроса на закрытие';
        if ($status !== 'done' && in_array((string) ($task['approval_stage'] ?? ''), ['review_lead', 'review_gip'], true)) $result[] = 'Ожидает согласования';
        return $result;
    }

    /** @return array<string,string> */
    private function comments(int $projectId, array $period): array
    {
        $stmt = $this->pdo->prepare('SELECT entity_type, entity_id, comment_text FROM project_health_comments WHERE project_id = ? AND date_from = ? AND date_to = ?');
        $stmt->execute([$projectId, $period['date_from'], $period['date_to']]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) $result[(string) $row['entity_type'] . ':' . (int) $row['entity_id']] = (string) $row['comment_text'];
        return $result;
    }

    private function assertEntity(int $projectId, string $type, int $id): void
    {
        if ($type === 'project') return;
        $table = $type === 'stage' ? 'project_stages' : ($type === 'section' ? 'project_sections' : 'tasks');
        $stmt = $this->pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE id = ? AND project_id = ? LIMIT 1');
        $stmt->execute([$id, $projectId]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Строка комментария не относится к проекту.');
    }

    private static function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        return $date && $date->format('Y-m-d') === trim($value) ? trim($value) : '';
    }
}
