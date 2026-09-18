<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;

/**
 * Ресурсное планирование: МОЩНОСТЬ (сколько часов человек может) против
 * СПРОСА (сколько требуют его активные задачи) по корзинам периода.
 *
 * Считается на лету поверх существующих данных (tasks, time_entries,
 * employee_legal_entities) — без собственных таблиц. Дизайн и решения:
 * docs/RESOURCE_PLANNING_PLAN.md. Деньги в модуль не входят.
 */
final class ResourceService
{
    /** Статусы задач, создающие спрос на ресурс. */
    private const DEMAND_STATUSES = ['new', 'in_progress', 'review', 'correction', 'overdue'];

    /** Категории табеля, уменьшающие доступность (отсутствия). */
    private const ABSENCE_CATEGORIES = ['vacation', 'sick_leave', 'business_trip', 'day_off', 'learning', 'absence'];

    /**
     * Корзины периода. Пресеты (решение §8 плана): неделя→рабочие дни,
     * месяц→ISO-недели, квартал→месяцы, год→месяцы.
     *
     * @return array<int, array{key: string, label: string, from: string, to: string}>
     */
    public static function buckets(string $preset, ?DateTimeImmutable $anchor = null): array
    {
        $today = $anchor ?? new DateTimeImmutable('today');
        $buckets = [];
        switch ($preset) {
            case 'week':
                $monday = $today->modify('monday this week');
                for ($i = 0; $i < 5; $i++) {
                    $day = $monday->modify("+{$i} day");
                    $d = $day->format('Y-m-d');
                    $buckets[] = ['key' => $d, 'label' => $day->format('d.m'), 'from' => $d, 'to' => $d];
                }
                break;
            case 'quarter':
            case 'year':
                $months = $preset === 'quarter' ? 3 : 12;
                $start = $preset === 'quarter'
                    ? $today->setDate((int) $today->format('Y'), ((int) ceil(((int) $today->format('n')) / 3) - 1) * 3 + 1, 1)
                    : $today->setDate((int) $today->format('Y'), 1, 1);
                for ($i = 0; $i < $months; $i++) {
                    $m = $start->modify("+{$i} month");
                    $buckets[] = [
                        'key' => $m->format('Y-m'),
                        'label' => $m->format('m.Y'),
                        'from' => $m->format('Y-m-01'),
                        'to' => $m->format('Y-m-t'),
                    ];
                }
                break;
            case 'month':
            default:
                $first = $today->modify('first day of this month')->modify('monday this week');
                $lastDay = $today->modify('last day of this month');
                for ($w = $first; $w <= $lastDay; $w = $w->modify('+1 week')) {
                    $buckets[] = [
                        'key' => $w->format('o-\WW'),
                        'label' => 'нед ' . $w->format('W') . ' (' . $w->format('d.m') . ')',
                        'from' => $w->format('Y-m-d'),
                        'to' => $w->modify('+6 day')->format('Y-m-d'),
                    ];
                }
                break;
        }

        return $buckets;
    }

    /** Рабочие дни (Пн–Пт) интервала включительно. @return string[] Y-m-d */
    public static function workdays(string $from, string $to): array
    {
        $out = [];
        try {
            $d = new DateTimeImmutable($from);
            $end = new DateTimeImmutable($to);
        } catch (\Throwable) {
            return [];
        }
        for (; $d <= $end; $d = $d->modify('+1 day')) {
            if ((int) $d->format('N') <= 5) {
                $out[] = $d->format('Y-m-d');
            }
        }

        return $out;
    }

    /**
     * Мощность: часы по корзинам на пользователя.
     * День = персональная норма (Σ daily_hours юрлиц из ФОТ-1, иначе 8 ч) − отсутствия из табеля.
     * Будущие плановые отсутствия — R2 (planned_absences), пока не учитываются.
     *
     * @param int[] $userIds
     * @return array<int, array<string, float>> [userId][bucketKey] => часы
     */
    public static function capacity(array $userIds, array $buckets, ?\PDO $pdo = null): array
    {
        if ($userIds === [] || $buckets === []) {
            return [];
        }
        $pdo = $pdo ?? Database::pdo();
        $from = $buckets[0]['from'];
        $to = $buckets[count($buckets) - 1]['to'];
        $ph = implode(',', array_fill(0, count($userIds), '?'));

        // Персональная дневная норма из назначений ФОТ-1 (сумма по юрлицам).
        $daily = [];
        try {
            $stmt = $pdo->prepare("SELECT user_id, SUM(daily_hours) AS h FROM employee_legal_entities WHERE is_active = 1 AND user_id IN ($ph) GROUP BY user_id");
            $stmt->execute($userIds);
            foreach ($stmt->fetchAll() as $r) {
                $h = (float) $r['h'];
                if ($h > 0) {
                    $daily[(int) $r['user_id']] = min($h, 12.0);
                }
            }
        } catch (\Throwable) {
            /* справочник ФОТ не настроен — у всех норма 8 ч */
        }

        // Отсутствия из табеля по дням (минуты absence-категорий).
        $absence = [];
        $cph = implode(',', array_fill(0, count(self::ABSENCE_CATEGORIES), '?'));
        $stmt = $pdo->prepare("SELECT user_id, work_date, SUM(minutes) AS m FROM time_entries WHERE user_id IN ($ph) AND work_date >= ? AND work_date <= ? AND category IN ($cph) GROUP BY user_id, work_date");
        $stmt->execute([...$userIds, $from, $to, ...self::ABSENCE_CATEGORIES]);
        foreach ($stmt->fetchAll() as $r) {
            $absence[(int) $r['user_id']][(string) $r['work_date']] = ((int) $r['m']) / 60;
        }

        $out = [];
        foreach ($buckets as $b) {
            $days = self::workdays($b['from'], $b['to']);
            foreach ($userIds as $uid) {
                $norm = $daily[$uid] ?? (TimeService::DAILY_TARGET_MINUTES / 60);
                $hours = 0.0;
                foreach ($days as $day) {
                    $hours += max(0.0, $norm - ($absence[$uid][$day] ?? 0.0));
                }
                $out[$uid][$b['key']] = round($hours, 1);
            }
        }

        return $out;
    }

    /**
     * Спрос: остатки плановых часов активных задач, размазанные по рабочим дням
     * окна задачи. Решения §8: просрочка/без дат → корзина с СЕГОДНЯ целиком;
     * задачи без planned_hours не дают часов, считаются отдельно (unplanned).
     *
     * @param int[] $userIds
     * @return array{demand: array<int, array<string, float>>, unplanned: array<int, int>, tasks: array<int, array<string, array<int, array{id: int, title: string, hours: float}>>>}
     */
    public static function demand(array $userIds, array $buckets, ?\PDO $pdo = null, ?int $projectId = null): array
    {
        if ($userIds === [] || $buckets === []) {
            return ['demand' => [], 'unplanned' => [], 'tasks' => []];
        }
        $pdo = $pdo ?? Database::pdo();
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $sph = implode(',', array_fill(0, count(self::DEMAND_STATUSES), '?'));
        $sql = "SELECT DISTINCT t.id, t.title, t.assignee_id, t.planned_hours, t.date_start, t.date_end
                FROM tasks t
                WHERE t.status IN ($sph)
                  AND COALESCE(t.task_type, '') != 'delegation'
                  AND (
                    t.assignee_id IN ($ph)
                    OR EXISTS (
                        SELECT 1 FROM task_participants tp
                        WHERE tp.task_id = t.id
                          AND tp.user_id IN ($ph)
                          AND tp.role IN ('assignee', 'coauthor')
                    )
                  )";
        $params = [...self::DEMAND_STATUSES, ...$userIds, ...$userIds];
        if ($projectId !== null) {
            $sql .= ' AND t.project_id = ?';
            $params[] = $projectId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $taskRows = $stmt->fetchAll();

        $taskIds = array_values(array_unique(array_map(static fn (array $task): int => (int) $task['id'], $taskRows)));
        $participantActors = [];
        $actualByTaskUser = [];
        if ($taskIds !== []) {
            $taskPh = implode(',', array_fill(0, count($taskIds), '?'));
            $userPh = implode(',', array_fill(0, count($userIds), '?'));
            $participantStmt = $pdo->prepare("
                SELECT task_id, user_id
                FROM task_participants
                WHERE task_id IN ($taskPh)
                  AND user_id IN ($userPh)
                  AND role IN ('assignee', 'coauthor')
            ");
            $participantStmt->execute([...$taskIds, ...$userIds]);
            foreach ($participantStmt->fetchAll() as $participant) {
                $participantActors[(int) $participant['task_id']][] = (int) $participant['user_id'];
            }

            $actualStmt = $pdo->prepare("
                SELECT task_id, user_id, SUM(minutes) AS minutes_sum
                FROM time_entries
                WHERE task_id IN ($taskPh)
                  AND user_id IN ($userPh)
                GROUP BY task_id, user_id
            ");
            $actualStmt->execute([...$taskIds, ...$userIds]);
            foreach ($actualStmt->fetchAll() as $actual) {
                $actualByTaskUser[(int) $actual['task_id']][(int) $actual['user_id']] = ((int) $actual['minutes_sum']) / 60;
            }
        }

        $todayBucket = null;
        foreach ($buckets as $b) {
            if ($b['from'] <= $today && $today <= $b['to']) {
                $todayBucket = $b['key'];
                break;
            }
        }

        $demand = [];
        $unplanned = [];
        $tasks = [];
        $add = static function (int $uid, string $key, float $hours, array $t) use (&$demand, &$tasks): void {
            $demand[$uid][$key] = round(($demand[$uid][$key] ?? 0.0) + $hours, 2);
            $tasks[$uid][$key][] = ['id' => (int) $t['id'], 'title' => (string) $t['title'], 'hours' => round($hours, 1)];
        };

        $userIdLookup = array_fill_keys(array_map('intval', $userIds), true);
        foreach ($taskRows as $t) {
            $actors = [];
            $primaryAssigneeId = (int) ($t['assignee_id'] ?? 0);
            if ($primaryAssigneeId > 0 && isset($userIdLookup[$primaryAssigneeId])) {
                $actors[$primaryAssigneeId] = true;
            }
            foreach ($participantActors[(int) $t['id']] ?? [] as $actorId) {
                if ($actorId > 0 && isset($userIdLookup[$actorId])) {
                    $actors[$actorId] = true;
                }
            }
            $actorIds = array_keys($actors);
            if ($actorIds === []) {
                continue;
            }

            if ((float) $t['planned_hours'] <= 0) {
                foreach ($actorIds as $uid) {
                    $unplanned[(int) $uid] = ($unplanned[(int) $uid] ?? 0) + 1;
                }
                continue;
            }
            $plannedShare = ((float) $t['planned_hours']) / count($actorIds);
            $start = max((string) ($t['date_start'] ?: $today), $today);
            $end = (string) ($t['date_end'] ?: '');
            $window = ($end !== '' && $end >= $start) ? self::workdays($start, $end) : [];

            foreach ($actorIds as $uid) {
                $uid = (int) $uid;
                $actualHours = (float) ($actualByTaskUser[(int) $t['id']][$uid] ?? 0.0);
                $remaining = max(0.0, $plannedShare - $actualHours);
                if ($remaining <= 0) {
                    continue;
                }
                if ($window === []) {
                    // Просрочка или нет дат — весь остаток в корзину с «сегодня».
                    if ($todayBucket !== null) {
                        $add($uid, $todayBucket, $remaining, $t);
                    }
                    continue;
                }
                $perDay = $remaining / count($window);
                foreach ($buckets as $b) {
                    $hours = 0.0;
                    foreach ($window as $day) {
                        if ($b['from'] <= $day && $day <= $b['to']) {
                            $hours += $perDay;
                        }
                    }
                    if ($hours > 0.01) {
                        $add($uid, $b['key'], $hours, $t);
                    }
                }
            }
        }

        return ['demand' => $demand, 'unplanned' => $unplanned, 'tasks' => $tasks];
    }

    /** Зона загрузки для ячейки. */
    public static function loadZone(float $demand, float $capacity): string
    {
        if ($capacity <= 0) {
            return $demand > 0 ? 'over' : 'idle';
        }
        $pct = $demand / $capacity * 100;
        if ($pct > 100) {
            return 'over';
        }
        if ($pct >= 70) {
            return 'ok';
        }

        return $pct > 0 ? 'free' : 'idle';
    }
}
