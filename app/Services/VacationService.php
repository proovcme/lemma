<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

final class VacationService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId, bool $includePast = false): array
    {
        $where = $includePast ? '' : ' AND v.date_to >= :today';
        $stmt = $this->pdo->prepare('SELECT v.*, substitute.name AS substitute_name
            FROM employee_vacations v
            INNER JOIN users substitute ON substitute.id = v.substitute_user_id
            WHERE v.user_id = :user_id AND v.cancelled_at IS NULL' . $where . '
            ORDER BY v.date_from ASC, v.id ASC');
        $params = ['user_id' => $userId];
        if (!$includePast) {
            $params['today'] = date('Y-m-d');
        }
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function substitutionsFor(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT v.*, absent.name AS absent_name
            FROM employee_vacations v
            INNER JOIN users absent ON absent.id = v.user_id
            WHERE v.substitute_user_id = ?
              AND v.cancelled_at IS NULL
              AND v.date_to >= ?
            ORDER BY v.date_from ASC, v.id ASC');
        $stmt->execute([$userId, date('Y-m-d')]);

        return $stmt->fetchAll();
    }

    public function create(int $userId, string $dateFrom, string $dateTo, int $substituteUserId, string $note, int $actorId): int
    {
        $dateFrom = $this->validDate($dateFrom, 'Укажите дату начала отпуска.');
        $dateTo = $this->validDate($dateTo, 'Укажите дату окончания отпуска.');
        if ($dateFrom > $dateTo) {
            throw new InvalidArgumentException('Дата окончания отпуска не может быть раньше даты начала.');
        }
        if ($dateFrom < date('Y-m-d')) {
            throw new InvalidArgumentException('Отпуск можно включить с сегодняшнего или будущего дня.');
        }
        if ($userId <= 0 || $substituteUserId <= 0 || $substituteUserId === $userId) {
            throw new InvalidArgumentException('Выберите другого действующего сотрудника на замену.');
        }
        $userStmt = $this->pdo->prepare('SELECT id, is_active FROM users WHERE id IN (?, ?)');
        $userStmt->execute([$userId, $substituteUserId]);
        $active = [];
        foreach ($userStmt->fetchAll() as $row) {
            $active[(int) $row['id']] = (int) ($row['is_active'] ?? 0) === 1;
        }
        if (empty($active[$userId]) || empty($active[$substituteUserId])) {
            throw new InvalidArgumentException('Сотрудник и его замена должны быть действующими.');
        }

        $overlap = $this->pdo->prepare('SELECT 1 FROM employee_vacations
            WHERE user_id = ? AND cancelled_at IS NULL AND date_from <= ? AND date_to >= ? LIMIT 1');
        $overlap->execute([$userId, $dateTo, $dateFrom]);
        if ($overlap->fetchColumn()) {
            throw new InvalidArgumentException('На этот период отпуск уже указан. Отмените прежнюю запись или выберите другие даты.');
        }

        $coveringAnother = $this->pdo->prepare('SELECT absent.name
            FROM employee_vacations v
            INNER JOIN users absent ON absent.id = v.user_id
            WHERE v.substitute_user_id = ? AND v.cancelled_at IS NULL
              AND v.date_from <= ? AND v.date_to >= ? LIMIT 1');
        $coveringAnother->execute([$userId, $dateTo, $dateFrom]);
        $coveredEmployee = $coveringAnother->fetchColumn();
        if ($coveredEmployee !== false) {
            throw new InvalidArgumentException('На этот период вы уже назначены заменой сотрудника: ' . $coveredEmployee . '. Сначала измените ту замену.');
        }

        $substituteAway = $this->pdo->prepare('SELECT 1 FROM employee_vacations
            WHERE user_id = ? AND cancelled_at IS NULL AND date_from <= ? AND date_to >= ? LIMIT 1');
        $substituteAway->execute([$substituteUserId, $dateTo, $dateFrom]);
        if ($substituteAway->fetchColumn()) {
            throw new InvalidArgumentException('Выбранный заместитель сам будет в отпуске в этот период.');
        }

        $stmt = $this->pdo->prepare('INSERT INTO employee_vacations
            (user_id, date_from, date_to, substitute_user_id, note, created_by)
            VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $dateFrom, $dateTo, $substituteUserId, trim($note) ?: null, $actorId]);

        return (int) $this->pdo->lastInsertId();
    }

    public function cancel(int $vacationId, int $userId, int $actorId): bool
    {
        $stmt = $this->pdo->prepare('UPDATE employee_vacations
            SET cancelled_at = CURRENT_TIMESTAMP, cancelled_by = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND user_id = ? AND cancelled_at IS NULL AND date_to >= ?');
        $stmt->execute([$actorId, $vacationId, $userId, date('Y-m-d')]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Adds the nearest current/future vacation and substitute to user rows.
     * Missing migration is treated as no availability data during staged updates.
     *
     * @param list<array<string, mixed>> $users
     * @return list<array<string, mixed>>
     */
    public function attachAvailability(array $users): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $users))));
        if ($ids === []) {
            return $users;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = $this->pdo->prepare('SELECT v.user_id, v.date_from, v.date_to, v.substitute_user_id,
                    substitute.name AS substitute_name
                FROM employee_vacations v
                INNER JOIN users substitute ON substitute.id = v.substitute_user_id
                WHERE v.user_id IN (' . $placeholders . ')
                  AND v.cancelled_at IS NULL
                  AND v.date_to >= ?
                ORDER BY v.user_id, v.date_from, v.id');
            $stmt->execute([...$ids, date('Y-m-d')]);
        } catch (\Throwable) {
            return $users;
        }
        $availability = [];
        foreach ($stmt->fetchAll() as $row) {
            $userId = (int) $row['user_id'];
            $availability[$userId] ??= $row;
        }
        foreach ($users as &$user) {
            $row = $availability[(int) ($user['id'] ?? 0)] ?? null;
            if (!$row) {
                continue;
            }
            $user['vacation_date_from'] = (string) $row['date_from'];
            $user['vacation_date_to'] = (string) $row['date_to'];
            $user['vacation_substitute_user_id'] = (int) $row['substitute_user_id'];
            $user['vacation_substitute_name'] = (string) $row['substitute_name'];
        }
        unset($user);

        return $users;
    }

    /** @return array<string, mixed>|null */
    public static function activeSubstitute(int $userId, ?PDO $pdo = null): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        try {
            $pdo ??= \App\Core\Database::pdo();
            $stmt = $pdo->prepare('SELECT v.substitute_user_id, substitute.name AS substitute_name, absent.name AS absent_name,
                    v.date_from, v.date_to
                FROM employee_vacations v
                INNER JOIN users substitute ON substitute.id = v.substitute_user_id AND substitute.is_active = 1
                INNER JOIN users absent ON absent.id = v.user_id
                WHERE v.user_id = ? AND v.cancelled_at IS NULL AND ? BETWEEN v.date_from AND v.date_to
                ORDER BY v.date_from DESC, v.id DESC LIMIT 1');
            $today = date('Y-m-d');
            $stmt->execute([$userId, $today]);
            return $stmt->fetch() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function isActiveSubstituteFor(int $substituteUserId, int $absentUserId, ?PDO $pdo = null): bool
    {
        if ($substituteUserId <= 0 || $absentUserId <= 0 || $substituteUserId === $absentUserId) {
            return false;
        }
        try {
            $pdo ??= \App\Core\Database::pdo();
            $stmt = $pdo->prepare('SELECT 1 FROM employee_vacations
                WHERE user_id = ? AND substitute_user_id = ? AND cancelled_at IS NULL
                  AND ? BETWEEN date_from AND date_to LIMIT 1');
            $stmt->execute([$absentUserId, $substituteUserId, date('Y-m-d')]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    private function validDate(string $value, string $message): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }
}
