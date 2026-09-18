<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

final class TimeService
{
    public const DAILY_TARGET_MINUTES = 480;

    public const CATEGORIES = [
        'meeting' => 'Совещания',
        'admin' => 'Администрирование',
        'learning' => 'Обучение',
        'vacation' => 'Отпуск',
        'sick_leave' => 'Больничный',
        'business_trip' => 'Командировка',
        'day_off' => 'Отгул',
        'idle' => 'Простой',
        'absence' => 'Другое отсутствие',
        'overtime' => 'Переработка',
        'other' => 'Другое',
    ];

    public const PHASES = [
        'execution' => 'Выполнение',
        'review' => 'Проверка',
        'correction' => 'Корректировка',
        'repeat_review' => 'Повторная проверка',
        'management' => 'Управление',
        'other' => 'Другое',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public static function weekStart(?string $date): string
    {
        $date = trim((string) $date);
        $base = $date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            ? new DateTimeImmutable($date)
            : new DateTimeImmutable('today');

        return $base->modify('monday this week')->format('Y-m-d');
    }

    /**
     * @return list<string>
     */
    public static function weekDates(string $weekStart): array
    {
        $start = new DateTimeImmutable($weekStart);
        $dates = [];
        for ($i = 0; $i < 7; $i++) {
            $dates[] = $start->modify('+' . $i . ' days')->format('Y-m-d');
        }

        return $dates;
    }

    public static function targetMinutes(string $date): int
    {
        return (int) (new DateTimeImmutable($date))->format('N') <= 5 ? self::DAILY_TARGET_MINUTES : 0;
    }

    public static function minutesToHours(int $minutes): string
    {
        if ($minutes === 0) {
            return '';
        }

        $hours = $minutes / 60;
        $formatted = number_format($hours, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    public static function parseHours(mixed $value): int
    {
        $text = str_replace(',', '.', trim((string) $value));
        if ($text === '') {
            return 0;
        }

        if (!is_numeric($text)) {
            throw new InvalidArgumentException('Часы должны быть числом.');
        }

        $hours = (float) $text;
        if ($hours < 0 || $hours > 24) {
            throw new InvalidArgumentException('В одной ячейке укажите от 0 до 24 часов.');
        }

        return (int) round($hours * 60);
    }

    public static function phaseForTask(array $task): string
    {
        $status = (string) ($task['status'] ?? '');
        $type = (string) ($task['task_type'] ?? '');

        if ($status === 'correction') {
            return 'correction';
        }
        if ($type === 'review' || in_array($status, ['review', 'pending_close'], true)) {
            return 'review';
        }

        return 'execution';
    }

    public function weekModel(array $user, string $weekStart): array
    {
        $dates = self::weekDates($weekStart);
        $weekEnd = $dates[6];
        $tasks = $this->suggestedTasks($user, $weekStart, $weekEnd);
        $entries = $this->weekEntries((int) $user['id'], $weekStart, $weekEnd);
        $tasksById = [];
        foreach ($tasks as $task) {
            $tasksById[(int) $task['id']] = $task;
        }

        $rows = [];
        $totals = array_fill_keys($dates, 0);
        foreach ($entries as $entry) {
            $date = (string) $entry['work_date'];
            $minutes = (int) $entry['minutes'];
            $totals[$date] = ($totals[$date] ?? 0) + $minutes;

            if ((string) $entry['category'] === 'task' && !empty($entry['task_id'])) {
                $taskId = (int) $entry['task_id'];
                $phase = (string) ($entry['phase'] ?: 'execution');
                $key = 'task:' . $taskId . ':' . $phase;
                $task = $tasksById[$taskId] ?? $this->taskFromEntry($entry);
                $tasksById[$taskId] = $task;
                if (!isset($rows[$key])) {
                    $rows[$key] = $this->taskRow($key, $task, $phase, $dates);
                }
                $rows[$key]['minutes'][$date] = ($rows[$key]['minutes'][$date] ?? 0) + $minutes;
                continue;
            }

            $category = (string) $entry['category'];
            $key = 'category:' . $category;
            if (!isset($rows[$key])) {
                $rows[$key] = $this->categoryRow($category, $dates);
            }
            $rows[$key]['minutes'][$date] = ($rows[$key]['minutes'][$date] ?? 0) + $minutes;
        }

        foreach ($tasksById as $task) {
            $phase = self::phaseForTask($task);
            $key = 'task:' . (int) $task['id'] . ':' . $phase;
            $rows[$key] ??= $this->taskRow($key, $task, $phase, $dates);
        }
        foreach (array_keys(self::CATEGORIES) as $category) {
            $key = 'category:' . $category;
            $rows[$key] ??= $this->categoryRow($category, $dates);
        }

        uasort($rows, static function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'task' ? -1 : 1;
            }

            return strcmp((string) $a['label'], (string) $b['label']);
        });

        $target = [];
        foreach ($dates as $date) {
            $target[$date] = self::targetMinutes($date);
        }

        return [
            'dates' => $dates,
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'prevWeek' => (new DateTimeImmutable($weekStart))->modify('-7 days')->format('Y-m-d'),
            'nextWeek' => (new DateTimeImmutable($weekStart))->modify('+7 days')->format('Y-m-d'),
            'rows' => array_values($rows),
            'tasks' => array_values($tasksById),
            'totals' => $totals,
            'target' => $target,
            'weekMinutes' => array_sum($totals),
            'weekTargetMinutes' => array_sum($target),
            'breakdown' => $this->weekBreakdown((int) $user['id'], $weekStart, $weekEnd),
        ];
    }

    public function saveWeek(array $user, string $weekStart, array $payload): int
    {
        $dates = self::weekDates($weekStart);
        $weekEnd = $dates[6];
        $hours = is_array($payload['hours'] ?? null) ? $payload['hours'] : [];
        $phases = is_array($payload['phase'] ?? null) ? $payload['phase'] : [];
        $comment = mb_substr(trim((string) ($payload['comment'] ?? '')), 0, 500);
        $userId = (int) $user['id'];
        $affectedTaskIds = $this->taskIdsForUserPeriod($userId, $weekStart, $weekEnd);
        $entries = [];

        foreach ($hours as $key => $byDate) {
            if (!is_array($byDate)) {
                continue;
            }
            $row = $this->rowTarget((string) $key, $user, $phases[(string) $key] ?? null);
            foreach ($dates as $date) {
                $minutes = self::parseHours($byDate[$date] ?? '');
                if ($minutes <= 0) {
                    continue;
                }
                $lockedMinutes = $this->lockedMinutesForEntry(
                    $userId,
                    $row['project_id'] !== null ? (int) $row['project_id'] : null,
                    $row['task_id'] !== null ? (int) $row['task_id'] : null,
                    $date,
                    (string) $row['category'],
                    (string) $row['phase']
                );
                if ($lockedMinutes >= $minutes) {
                    continue;
                }
                $minutes -= $lockedMinutes;
                $entries[] = [
                    'user_id' => $userId,
                    'project_id' => $row['project_id'],
                    'task_id' => $row['task_id'],
                    'work_date' => $date,
                    'minutes' => $minutes,
                    'category' => $row['category'],
                    'phase' => $row['phase'],
                    'comment' => $comment,
                ];
                if ($row['task_id']) {
                    $affectedTaskIds[] = (int) $row['task_id'];
                }
            }
        }

        $this->pdo->beginTransaction();
        try {
            $this->deleteUserPeriod($userId, $weekStart, $weekEnd);
            $batchId = $this->createBatch($userId, $weekStart, $weekEnd, 'manual_week', array_sum(array_column($entries, 'minutes')), $comment);
            $this->insertEntries($batchId, $entries);
            $this->recalculateTasks($affectedTaskIds);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $written = array_sum(array_column($entries, 'minutes'));
        $this->recordTimeActivities($userId, $entries, 'time.week_saved');

        return $written;
    }

    public function distributeDay(array $user, string $date, array $taskIds, int $totalMinutes, string $method, string $phase): int
    {
        if ($totalMinutes <= 0) {
            throw new InvalidArgumentException('Укажите часы для распределения.');
        }

        $tasks = [];
        foreach (array_values(array_unique(array_map('intval', $taskIds))) as $taskId) {
            if ($taskId <= 0) {
                continue;
            }
            $tasks[] = $this->taskForUser($taskId, $user);
        }
        if ($tasks === []) {
            throw new InvalidArgumentException('Выберите задачи для пакетного списания.');
        }

        $weights = [];
        foreach ($tasks as $task) {
            $planned = max(0.0, (float) ($task['planned_hours'] ?? 0));
            $actual = max(0.0, (float) ($task['actual_hours'] ?? 0));
            $weights[(int) $task['id']] = $method === 'planned' ? max(1.0, $planned - $actual, $planned) : 1.0;
        }
        $minutesByTask = $this->splitMinutes($totalMinutes, $weights);
        $entries = [];
        $affectedTaskIds = array_keys($minutesByTask);
        $phase = isset(self::PHASES[$phase]) ? $phase : 'auto';
        foreach ($tasks as $task) {
            $taskId = (int) $task['id'];
            $minutes = $minutesByTask[$taskId] ?? 0;
            if ($minutes <= 0) {
                continue;
            }
            $entryPhase = $phase === 'auto' ? self::phaseForTask($task) : $phase;
            $protectedMinutes = $this->lockedMinutesForEntry((int) $user['id'], (int) $task['project_id'], $taskId, $date, 'task', $entryPhase);
            if ($protectedMinutes >= $minutes) {
                continue;
            }
            $minutes -= $protectedMinutes;
            $entries[] = [
                'user_id' => (int) $user['id'],
                'project_id' => (int) $task['project_id'],
                'task_id' => $taskId,
                'work_date' => $date,
                'minutes' => $minutes,
                'category' => 'task',
                'phase' => $entryPhase,
                'comment' => '',
            ];
        }
        if ($entries === []) {
            throw new InvalidArgumentException('Выбранный день уже подан на приемку или закрыт.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->deleteTaskEntriesForDate((int) $user['id'], $date, $affectedTaskIds);
            $batchId = $this->createBatch((int) $user['id'], $date, $date, 'distribute_day', array_sum($minutesByTask), '');
            $this->insertEntries($batchId, $entries);
            $this->recalculateTasks($affectedTaskIds);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $written = array_sum($minutesByTask);
        $this->recordTimeActivities((int) $user['id'], $entries, 'time.distributed');

        return $written;
    }

    public function repeatPreviousDay(array $user, string $date): int
    {
        $target = new DateTimeImmutable($date);
        $sourceDate = $target->modify('-1 day')->format('Y-m-d');
        $userId = (int) $user['id'];
        $entries = $this->rawEntriesForDate($userId, $sourceDate);
        if ($entries === []) {
            throw new InvalidArgumentException('Во вчерашнем дне нет списаний.');
        }

        $oldTaskIds = $this->taskIdsForUserPeriod($userId, $date, $date);
        $newEntries = [];
        foreach ($entries as $entry) {
            $protectedMinutes = $this->lockedMinutesForEntry(
                $userId,
                $entry['project_id'] !== null ? (int) $entry['project_id'] : null,
                $entry['task_id'] !== null ? (int) $entry['task_id'] : null,
                $date,
                (string) $entry['category'],
                (string) $entry['phase']
            );
            if ($protectedMinutes >= (int) $entry['minutes']) {
                continue;
            }
            $newEntries[] = [
                'user_id' => $userId,
                'project_id' => $entry['project_id'] !== null ? (int) $entry['project_id'] : null,
                'task_id' => $entry['task_id'] !== null ? (int) $entry['task_id'] : null,
                'work_date' => $date,
                'minutes' => (int) $entry['minutes'] - $protectedMinutes,
                'category' => (string) $entry['category'],
                'phase' => (string) $entry['phase'],
                'comment' => (string) ($entry['comment'] ?? ''),
            ];
        }
        if ($newEntries === []) {
            throw new InvalidArgumentException('Целевой день уже подан или закрыт.');
        }

        $affectedTaskIds = array_merge($oldTaskIds, array_filter(array_map(static fn (array $entry): ?int => $entry['task_id'], $newEntries)));
        $this->pdo->beginTransaction();
        try {
            $this->deleteUserPeriod($userId, $date, $date);
            $batchId = $this->createBatch($userId, $date, $date, 'repeat_day', array_sum(array_column($newEntries, 'minutes')), '');
            $this->insertEntries($batchId, $newEntries);
            $this->recalculateTasks($affectedTaskIds);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $written = array_sum(array_column($newEntries, 'minutes'));
        $this->recordTimeActivities($userId, $newEntries, 'time.day_repeated');

        return $written;
    }

    public function saveTaskEntry(array $user, int $taskId, string $date, int $minutes, string $phase): int
    {
        if ($minutes <= 0) {
            throw new InvalidArgumentException('Укажите часы.');
        }
        $task = $this->taskForUser($taskId, $user);
        $phase = isset(self::PHASES[$phase]) ? $phase : self::phaseForTask($task);
        if ($this->lockedMinutesForEntry((int) $user['id'], (int) $task['project_id'], $taskId, $date, 'task', $phase) > 0) {
            throw new InvalidArgumentException('Этот день уже подан на приемку или закрыт.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->deleteTaskEntriesForDate((int) $user['id'], $date, [$taskId], $phase);
            $batchId = $this->createBatch((int) $user['id'], $date, $date, 'task_quick', $minutes, '');
            $this->insertEntries($batchId, [[
                'user_id' => (int) $user['id'],
                'project_id' => (int) $task['project_id'],
                'task_id' => $taskId,
                'work_date' => $date,
                'minutes' => $minutes,
                'category' => 'task',
                'phase' => $phase,
                'comment' => '',
            ]]);
            $this->recalculateTasks([$taskId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->recordTimeActivities((int) $user['id'], [[
            'user_id' => (int) $user['id'],
            'project_id' => (int) $task['project_id'],
            'task_id' => $taskId,
            'work_date' => $date,
            'minutes' => $minutes,
            'category' => 'task',
            'phase' => $phase,
        ]], 'time.task_logged');

        return $minutes;
    }

    /**
     * Quick "+N ч" buttons in «Мой день»: add the preset minutes to whatever is already
     * logged for this task/date/phase, then upsert the running total. Returns the new total.
     */
    public function addTaskQuickMinutes(array $user, int $taskId, string $date, int $addMinutes, string $phase): int
    {
        if ($addMinutes <= 0) {
            throw new InvalidArgumentException('Укажите часы.');
        }
        $task = $this->taskForUser($taskId, $user);
        $phase = isset(self::PHASES[$phase]) ? $phase : self::phaseForTask($task);
        if ($this->lockedMinutesForEntry((int) $user['id'], (int) $task['project_id'], $taskId, $date, 'task', $phase) > 0) {
            throw new InvalidArgumentException('Этот день уже подан на приемку или закрыт.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->lockTaskRow($taskId);

            $stmt = $this->pdo->prepare('
                SELECT COALESCE(SUM(minutes), 0)
                FROM time_entries
                WHERE user_id = ?
                  AND task_id = ?
                  AND work_date = ?
                  AND phase = ?
                  AND status != "locked"
            ');
            $stmt->execute([(int) $user['id'], $taskId, $date, $phase]);
            $minutes = (int) $stmt->fetchColumn() + $addMinutes;

            $this->deleteTaskEntriesForDate((int) $user['id'], $date, [$taskId], $phase);
            $batchId = $this->createBatch((int) $user['id'], $date, $date, 'task_quick', $minutes, '');
            $entry = [
                'user_id' => (int) $user['id'],
                'project_id' => (int) $task['project_id'],
                'task_id' => $taskId,
                'work_date' => $date,
                'minutes' => $minutes,
                'category' => 'task',
                'phase' => $phase,
                'comment' => '',
            ];
            $this->insertEntries($batchId, [$entry]);
            $this->recalculateTasks([$taskId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->recordTimeActivities((int) $user['id'], [$entry], 'time.task_logged');

        return $minutes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function suggestedTasks(array $user, string $weekStart, string $weekEnd): array
    {
        $stmt = $this->pdo->prepare('
            SELECT DISTINCT t.id, t.title, t.status, t.task_type, t.project_id, t.planned_hours, t.actual_hours,
                   p.code AS project_code, p.title AS project_title,
                   pp.code AS pp_code, pp.title AS pp_title,
                   btp.code AS btp_code, btp.title AS btp_title
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN project_btp_codes btp ON btp.id = t.btp_code_id
            LEFT JOIN project_pp_codes pp ON pp.id = COALESCE(t.pp_code_id, btp.pp_code_id)
            WHERE p.status = \'active\'
              AND COALESCE(t.task_type, \'\') != \'delegation\'
              AND (
                  t.assignee_id = :user_assignee
                  OR t.reviewer_id = :user_reviewer
                  OR EXISTS (
                      SELECT 1
                      FROM task_participants tp_time_coauthor
                      WHERE tp_time_coauthor.task_id = t.id
                        AND tp_time_coauthor.user_id = :user_coauthor
                        AND tp_time_coauthor.role = "coauthor"
                  )
                  OR EXISTS (
                      SELECT 1 FROM time_entries te
                      WHERE te.task_id = t.id
                        AND te.user_id = :entry_user
                        AND te.work_date BETWEEN :week_start AND :week_end
                  )
              )
              AND (t.status != "done" OR EXISTS (
                  SELECT 1 FROM time_entries te2
                  WHERE te2.task_id = t.id
                    AND te2.user_id = :entry_user_done
                    AND te2.work_date BETWEEN :week_start_done AND :week_end_done
              ))
            ORDER BY p.code, t.date_end IS NULL, t.date_end, t.id
            LIMIT 80
        ');
        $stmt->execute([
            'user_assignee' => (int) $user['id'],
            'user_reviewer' => (int) $user['id'],
            'user_coauthor' => (int) $user['id'],
            'entry_user' => (int) $user['id'],
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'entry_user_done' => (int) $user['id'],
            'week_start_done' => $weekStart,
            'week_end_done' => $weekEnd,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function weekEntries(int $userId, string $weekStart, string $weekEnd): array
    {
        $stmt = $this->pdo->prepare('
            SELECT te.task_id, te.project_id, te.work_date, te.category, te.phase, SUM(te.minutes) AS minutes,
                   t.title AS task_title, t.status AS task_status, t.task_type,
                   t.planned_hours, t.actual_hours,
                   p.code AS project_code, p.title AS project_title,
                   pp.code AS pp_code, pp.title AS pp_title,
                   btp.code AS btp_code, btp.title AS btp_title
            FROM time_entries te
            LEFT JOIN tasks t ON t.id = te.task_id
            LEFT JOIN projects p ON p.id = COALESCE(te.project_id, t.project_id)
            LEFT JOIN project_btp_codes btp ON btp.id = t.btp_code_id
            LEFT JOIN project_pp_codes pp ON pp.id = COALESCE(t.pp_code_id, btp.pp_code_id)
            WHERE te.user_id = ?
              AND te.work_date BETWEEN ? AND ?
            GROUP BY te.task_id, te.project_id, te.work_date, te.category, te.phase,
                     t.title, t.status, t.task_type, t.planned_hours, t.actual_hours,
                     p.code, p.title, pp.code, pp.title, btp.code, btp.title
            ORDER BY te.work_date, p.code, t.id
        ');
        $stmt->execute([$userId, $weekStart, $weekEnd]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function weekBreakdown(int $userId, string $weekStart, string $weekEnd): array
    {
        $stmt = $this->pdo->prepare('
            SELECT COALESCE(p.code, "") AS project_code,
                   COALESCE(p.title, "") AS project_title,
                   t.id AS task_id,
                   COALESCE(t.title, "") AS task_title,
                   COALESCE(pp.code, "") AS pp_code,
                   COALESCE(pp.title, "") AS pp_title,
                   COALESCE(btp.code, "") AS btp_code,
                   COALESCE(btp.title, "") AS btp_title,
                   te.category,
                   te.phase,
                   COALESCE(SUM(te.minutes), 0) AS minutes,
                   COUNT(DISTINCT te.work_date) AS days_count
            FROM time_entries te
            LEFT JOIN tasks t ON t.id = te.task_id
            LEFT JOIN projects p ON p.id = COALESCE(te.project_id, t.project_id)
            LEFT JOIN project_btp_codes btp ON btp.id = t.btp_code_id
            LEFT JOIN project_pp_codes pp ON pp.id = COALESCE(t.pp_code_id, btp.pp_code_id)
            WHERE te.user_id = ?
              AND te.work_date BETWEEN ? AND ?
            GROUP BY p.id, p.code, p.title, t.id, t.title,
                     pp.code, pp.title, btp.code, btp.title, te.category, te.phase
            ORDER BY minutes DESC, p.code, t.id
        ');
        $stmt->execute([$userId, $weekStart, $weekEnd]);

        return $stmt->fetchAll();
    }

    private function taskRow(string $key, array $task, string $phase, array $dates): array
    {
        $meta = array_values(array_filter([
            (string) ($task['project_code'] ?? ''),
            task_status_label((string) ($task['status'] ?? 'new')),
            (string) ($task['pp_code'] ?? ''),
            (string) ($task['btp_code'] ?? ''),
        ], static fn (string $value): bool => $value !== ''));

        return [
            'key' => $key,
            'type' => 'task',
            'task_id' => (int) $task['id'],
            'project_id' => (int) $task['project_id'],
            'label' => '#' . (int) $task['id'] . ' · ' . (string) $task['title'],
            'meta' => implode(' · ', $meta),
            'phase' => $phase,
            'minutes' => array_fill_keys($dates, 0),
        ];
    }

    private function categoryRow(string $category, array $dates): array
    {
        return [
            'key' => 'category:' . $category,
            'type' => 'category',
            'task_id' => null,
            'project_id' => null,
            'label' => self::CATEGORIES[$category] ?? $category,
            'meta' => 'Без проектной задачи',
            'phase' => $category === 'meeting' || $category === 'admin' ? 'management' : 'other',
            'minutes' => array_fill_keys($dates, 0),
        ];
    }

    private function taskFromEntry(array $entry): array
    {
        return [
            'id' => (int) $entry['task_id'],
            'title' => (string) ($entry['task_title'] ?? 'Задача'),
            'status' => (string) ($entry['task_status'] ?? 'new'),
            'task_type' => (string) ($entry['task_type'] ?? 'work'),
            'project_id' => (int) ($entry['project_id'] ?? 0),
            'planned_hours' => $entry['planned_hours'] ?? null,
            'actual_hours' => $entry['actual_hours'] ?? null,
            'project_code' => (string) ($entry['project_code'] ?? ''),
            'project_title' => (string) ($entry['project_title'] ?? ''),
            'pp_code' => (string) ($entry['pp_code'] ?? ''),
            'btp_code' => (string) ($entry['btp_code'] ?? ''),
        ];
    }

    private function rowTarget(string $key, array $user, mixed $phaseValue): array
    {
        if (preg_match('/^task:(\d+):([a-z_]+)$/', $key, $matches)) {
            $task = $this->taskForUser((int) $matches[1], $user);
            $phase = isset(self::PHASES[(string) $phaseValue]) ? (string) $phaseValue : (string) $matches[2];
            if (!isset(self::PHASES[$phase])) {
                $phase = self::phaseForTask($task);
            }

            return [
                'project_id' => (int) $task['project_id'],
                'task_id' => (int) $task['id'],
                'category' => 'task',
                'phase' => $phase,
            ];
        }

        if (preg_match('/^category:([a-z_]+)$/', $key, $matches) && isset(self::CATEGORIES[$matches[1]])) {
            return [
                'project_id' => null,
                'task_id' => null,
                'category' => $matches[1],
                'phase' => $matches[1] === 'meeting' || $matches[1] === 'admin' ? 'management' : 'other',
            ];
        }

        throw new InvalidArgumentException('Неизвестная строка табеля.');
    }

    private function taskForUser(int $taskId, array $user): array
    {
        $stmt = $this->pdo->prepare('
            SELECT t.id, t.title, t.status, t.task_type, t.project_id, t.planned_hours, t.actual_hours,
                   p.code AS project_code, p.title AS project_title,
                   pp.code AS pp_code, pp.title AS pp_title,
                   btp.code AS btp_code, btp.title AS btp_title
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN project_btp_codes btp ON btp.id = t.btp_code_id
            LEFT JOIN project_pp_codes pp ON pp.id = COALESCE(t.pp_code_id, btp.pp_code_id)
            WHERE t.id = :task_id
              AND p.status = \'active\'
              AND COALESCE(t.task_type, \'\') != \'delegation\'
              AND (
                  t.assignee_id = :time_user_id
                  OR t.reviewer_id = :time_reviewer_id
                  OR EXISTS (
                      SELECT 1
                      FROM task_participants tp_time_coauthor
                      WHERE tp_time_coauthor.task_id = t.id
                        AND tp_time_coauthor.user_id = :time_coauthor_id
                        AND tp_time_coauthor.role = "coauthor"
                  )
              )
            LIMIT 1
        ');
        $stmt->execute([
            'task_id' => $taskId,
            'time_user_id' => (int) $user['id'],
            'time_reviewer_id' => (int) $user['id'],
            'time_coauthor_id' => (int) $user['id'],
        ]);
        $task = $stmt->fetch();
        if (!$task) {
            throw new InvalidArgumentException('Задача недоступна для списания времени.');
        }

        return $task;
    }

    /**
     * @param array<int, float> $weights
     * @return array<int, int>
     */
    private function splitMinutes(int $totalMinutes, array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum <= 0) {
            $sum = count($weights);
            $weights = array_fill_keys(array_keys($weights), 1.0);
        }

        $parts = [];
        $fractions = [];
        $allocated = 0;
        foreach ($weights as $id => $weight) {
            $exact = ($totalMinutes * (float) $weight) / $sum;
            $minutes = (int) floor($exact);
            $parts[(int) $id] = $minutes;
            $fractions[(int) $id] = $exact - $minutes;
            $allocated += $minutes;
        }

        arsort($fractions);
        $remainder = $totalMinutes - $allocated;
        foreach (array_keys($fractions) as $id) {
            if ($remainder <= 0) {
                break;
            }
            $parts[(int) $id]++;
            $remainder--;
        }

        return $parts;
    }

    private function createBatch(int $userId, string $periodStart, string $periodEnd, string $mode, int $totalMinutes, string $comment): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO time_batches (user_id, period_start, period_end, mode, status, total_minutes, comment)
            VALUES (?, ?, ?, ?, "draft", ?, ?)
        ');
        $stmt->execute([$userId, $periodStart, $periodEnd, $mode, $totalMinutes, $comment !== '' ? $comment : null]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function insertEntries(int $batchId, array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO time_entries (batch_id, user_id, project_id, task_id, work_date, minutes, category, phase, comment, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "draft")
        ');
        foreach ($entries as $entry) {
            $stmt->execute([
                $batchId,
                (int) $entry['user_id'],
                $entry['project_id'],
                $entry['task_id'],
                (string) $entry['work_date'],
                (int) $entry['minutes'],
                (string) $entry['category'],
                (string) $entry['phase'],
                trim((string) ($entry['comment'] ?? '')) !== '' ? mb_substr(trim((string) $entry['comment']), 0, 500) : null,
            ]);
        }
    }

    private function deleteUserPeriod(int $userId, string $dateStart, string $dateEnd): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM time_entries WHERE user_id = ? AND work_date BETWEEN ? AND ? AND status != "locked"');
        $stmt->execute([$userId, $dateStart, $dateEnd]);
    }

    private function lockedMinutesForEntry(int $userId, ?int $projectId, ?int $taskId, string $date, string $category, string $phase): int
    {
        $stmt = $this->pdo->prepare('
            SELECT COALESCE(SUM(minutes), 0)
            FROM time_entries
            WHERE user_id = ?
              AND COALESCE(project_id, 0) = ?
              AND COALESCE(task_id, 0) = ?
              AND work_date = ?
              AND category = ?
              AND phase = ?
              AND status = "locked"
        ');
        $stmt->execute([$userId, $projectId ?? 0, $taskId ?? 0, $date, $category, $phase]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<int> $taskIds
     */
    private function deleteTaskEntriesForDate(int $userId, string $date, array $taskIds, ?string $phase = null): void
    {
        $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
        if ($taskIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $params = [$userId, $date, ...$taskIds];
        $phaseSql = '';
        if ($phase !== null) {
            $phaseSql = ' AND phase = ?';
            $params[] = $phase;
        }
        $stmt = $this->pdo->prepare("DELETE FROM time_entries WHERE user_id = ? AND work_date = ? AND task_id IN ({$placeholders}) AND status != \"locked\"{$phaseSql}");
        $stmt->execute($params);
    }

    private function lockTaskRow(int $taskId): void
    {
        if ($this->isSqlite()) {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT id FROM tasks WHERE id = ? FOR UPDATE');
        $stmt->execute([$taskId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawEntriesForDate(int $userId, string $date): array
    {
        $stmt = $this->pdo->prepare('
            SELECT user_id, project_id, task_id, work_date, minutes, category, phase, comment
            FROM time_entries
            WHERE user_id = ?
              AND work_date = ?
              AND status != "locked"
            ORDER BY id
        ');
        $stmt->execute([$userId, $date]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<int>
     */
    private function taskIdsForUserPeriod(int $userId, string $dateStart, string $dateEnd): array
    {
        $stmt = $this->pdo->prepare('
            SELECT DISTINCT task_id
            FROM time_entries
            WHERE user_id = ?
              AND work_date BETWEEN ? AND ?
              AND task_id IS NOT NULL
        ');
        $stmt->execute([$userId, $dateStart, $dateEnd]);

        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * @param list<int|null> $taskIds
     */
    private function recalculateTasks(array $taskIds): void
    {
        $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
        if ($taskIds === []) {
            return;
        }

        $sum = $this->pdo->prepare('SELECT ROUND(COALESCE(SUM(minutes), 0) / 60.0, 2) FROM time_entries WHERE task_id = ? AND category = "task"');
        $update = $this->pdo->prepare('UPDATE tasks SET actual_hours = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        foreach ($taskIds as $taskId) {
            $sum->execute([$taskId]);
            $hours = (float) $sum->fetchColumn();
            $update->execute([$hours > 0 ? $hours : null, $taskId]);
        }
    }

    private function isSqlite(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function recordTimeActivities(int $userId, array $entries, string $action): void
    {
        if ($entries === []) {
            return;
        }

        $byTask = [];
        $byAbsence = [];
        foreach ($entries as $entry) {
            $minutes = (int) ($entry['minutes'] ?? 0);
            if ($minutes <= 0) {
                continue;
            }
            if ((string) ($entry['category'] ?? '') === 'task' && !empty($entry['task_id'])) {
                $taskId = (int) $entry['task_id'];
                $byTask[$taskId]['minutes'] = ($byTask[$taskId]['minutes'] ?? 0) + $minutes;
                $byTask[$taskId]['dates'][] = (string) ($entry['work_date'] ?? '');
                $byTask[$taskId]['phase'] = (string) ($entry['phase'] ?? 'execution');
                continue;
            }

            $category = (string) ($entry['category'] ?? '');
            if (in_array($category, ['vacation', 'sick_leave', 'business_trip', 'learning', 'day_off'], true)) {
                $byAbsence[$category]['minutes'] = ($byAbsence[$category]['minutes'] ?? 0) + $minutes;
                $byAbsence[$category]['dates'][] = (string) ($entry['work_date'] ?? '');
            }
        }

        foreach ($byTask as $taskId => $row) {
            $dates = array_values(array_filter(array_unique($row['dates'] ?? [])));
            sort($dates);
            $period = $this->periodLabel($dates);
            $phaseLabel = self::PHASES[(string) ($row['phase'] ?? 'execution')] ?? 'Работа';
            ActivityLogService::recordTask(
                (int) $taskId,
                $userId,
                $action,
                'Списано время',
                $phaseLabel . ' · ' . self::minutesToHours((int) ($row['minutes'] ?? 0)) . ' ч' . ($period !== '' ? ' · ' . $period : '')
            );
        }

        foreach ($byAbsence as $category => $row) {
            $dates = array_values(array_filter(array_unique($row['dates'] ?? [])));
            sort($dates);
            $label = self::CATEGORIES[$category] ?? $category;
            ActivityLogService::recordLocia(
                $userId,
                'time.absence_logged',
                'Записано отсутствие: ' . $label,
                self::minutesToHours((int) ($row['minutes'] ?? 0)) . ' ч' . (($period = $this->periodLabel($dates)) !== '' ? ' · ' . $period : '')
            );
        }
    }

    /**
     * @param list<string> $dates
     */
    private function periodLabel(array $dates): string
    {
        if ($dates === []) {
            return '';
        }
        $first = $dates[0];
        $last = $dates[count($dates) - 1];

        return $first === $last ? format_date($first) : format_date($first) . ' - ' . format_date($last);
    }
}
