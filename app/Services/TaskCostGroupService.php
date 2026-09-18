<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class TaskCostGroupService
{
    public static function codeForUser(int $userId, ?PDO $pdo = null): string
    {
        if ($userId <= 0) {
            return '';
        }

        $pdo = $pdo ?? Database::pdo();
        $queries = [
            "SELECT spr.department_code
             FROM staffing_plan_rows spr
             INNER JOIN staffing_periods sp ON sp.id = spr.period_id
             WHERE spr.user_id = ?
               AND spr.status IN ('occupied', 'transfer')
               AND sp.status = 'locked'
             ORDER BY sp.month_start DESC, sp.revision DESC
             LIMIT 1",
            "SELECT ele.cost_group
             FROM employee_legal_entities ele
             WHERE ele.user_id = ?
               AND ele.is_active = 1
               AND ele.cost_group IS NOT NULL
               AND ele.cost_group <> ''
             ORDER BY ele.is_primary DESC, ele.id ASC
             LIMIT 1",
            'SELECT department FROM users WHERE id = ? LIMIT 1',
        ];
        foreach ($queries as $sql) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$userId]);
                $code = trim((string) ($stmt->fetchColumn() ?: ''));
                if ($code !== '') {
                    return $code;
                }
            } catch (\PDOException) {
                // Старые локальные базы могут не иметь HR/ШР-таблиц: отдел остаётся безопасным резервом.
            }
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $users
     * @return array<int, array<string, mixed>>
     */
    public static function attachCodes(array $users, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? Database::pdo();
        foreach ($users as &$user) {
            $user['cost_group_code'] = self::codeForUser((int) ($user['id'] ?? 0), $pdo);
        }
        unset($user);

        return $users;
    }
}
