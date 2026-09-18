<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class OrgService
{
    /**
     * @return array<int, array{title: string, grade: string, sort_order: int}>
     */
    public static function defaultPositions(): array
    {
        $matrixPath = BASE_PATH . '/config/competency_matrix.php';
        if (!is_file($matrixPath)) {
            return [];
        }
        $matrix = require $matrixPath;
        $positions = [];
        foreach (($matrix['positions'] ?? []) as $index => $position) {
            $title = trim((string) ($position['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $positions[] = [
                'title' => $title,
                'grade' => trim((string) ($position['grade'] ?? '')),
                'sort_order' => ((int) $index + 1) * 10,
            ];
        }
        $positions[] = ['title' => 'Директор Н2', 'grade' => 'Н2', 'sort_order' => 200];
        $positions[] = ['title' => 'Директор Н1', 'grade' => 'Н1', 'sort_order' => 210];
        $positions[] = ['title' => 'Собственник', 'grade' => '0', 'sort_order' => 220];

        return $positions;
    }

    public static function seedDefaultPositions(?PDO $pdo = null): void
    {
        $pdo = $pdo ?? Database::pdo();
        $positions = self::defaultPositions();
        if ($positions === []) {
            return;
        }

        if ((string) config('db.connection') === 'sqlite') {
            $stmt = $pdo->prepare('
                INSERT INTO positions (title, grade, sort_order)
                VALUES (?, ?, ?)
                ON CONFLICT(title) DO UPDATE SET grade = excluded.grade, sort_order = excluded.sort_order
            ');
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO positions (title, grade, sort_order)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE grade = VALUES(grade), sort_order = VALUES(sort_order)
            ');
        }
        foreach ($positions as $position) {
            $stmt->execute([$position['title'], $position['grade'], $position['sort_order']]);
        }
    }

    public static function positions(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? Database::pdo();
        return $pdo->query('SELECT * FROM positions ORDER BY sort_order, title')->fetchAll();
    }

    public static function activeUsers(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? Database::pdo();
        $users = $pdo->query('
            SELECT u.*,
                   p.title AS position_title,
                   p.grade AS position_grade,
                   p.sort_order AS position_sort_order,
                   g.name AS group_name,
                   g.lead_user_id AS group_lead_user_id,
                   manager.name AS manager_name,
                   d.name AS department_name
            FROM users u
            LEFT JOIN positions p ON p.id = u.position_id
            LEFT JOIN department_groups g ON g.id = u.group_id
            LEFT JOIN users manager ON manager.id = u.manager_id
            LEFT JOIN departments d ON d.code = u.department
            WHERE u.is_active = 1
            ORDER BY u.department, COALESCE(g.sort_order, 999), g.name, COALESCE(p.sort_order, 999), u.name
        ')->fetchAll();
        usort($users, self::nodeSorter());

        return $users;
    }

    /**
     * @param array<int, array<string,mixed>> $users
     * @return array<int, array<string,mixed>>
     */
    public static function tree(array $users): array
    {
        $nodes = [];
        foreach ($users as $user) {
            $id = (int) ($user['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $user['children'] = [];
            $nodes[$id] = $user;
        }

        $roots = [];
        foreach (array_keys($nodes) as $id) {
            $managerId = (int) ($nodes[$id]['manager_id'] ?? 0);
            if ($managerId <= 0 || $managerId === $id || !isset($nodes[$managerId]) || self::wouldCycle($id, $managerId, $nodes)) {
                $roots[] =& $nodes[$id];
                continue;
            }
            $nodes[$managerId]['children'][] =& $nodes[$id];
        }
        unset($nodes);

        usort($roots, self::nodeSorter());
        self::sortChildren($roots);

        return $roots;
    }

    /**
     * @param array<int, array<string,mixed>> $nodes
     */
    private static function wouldCycle(int $id, int $managerId, array $nodes): bool
    {
        $seen = [$id => true];
        while ($managerId > 0 && isset($nodes[$managerId])) {
            if (isset($seen[$managerId])) {
                return true;
            }
            $seen[$managerId] = true;
            $managerId = (int) ($nodes[$managerId]['manager_id'] ?? 0);
        }

        return false;
    }

    private static function sortChildren(array &$nodes): void
    {
        usort($nodes, self::nodeSorter());
        foreach ($nodes as &$node) {
            if (!empty($node['children']) && is_array($node['children'])) {
                self::sortChildren($node['children']);
            }
        }
        unset($node);
    }

    private static function nodeSorter(): callable
    {
        return static function (array $a, array $b): int {
            return (self::gradeRank((string) ($b['position_grade'] ?? '')) <=> self::gradeRank((string) ($a['position_grade'] ?? '')))
                ?: ((int) ($a['position_sort_order'] ?? 99999) <=> (int) ($b['position_sort_order'] ?? 99999))
                ?: ((int) ($a['position_id'] ?? 99999) <=> (int) ($b['position_id'] ?? 99999))
                ?: strcmp((string) ($a['department'] ?? ''), (string) ($b['department'] ?? ''))
                ?: strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        };
    }

    private static function gradeRank(string $grade): int
    {
        $grade = trim(mb_strtoupper($grade, 'UTF-8'));
        if ($grade === '') {
            return 0;
        }

        if ($grade === '0') {
            return 30000;
        }

        if (preg_match('/^[HН]\s*(\d+)$/u', $grade, $matches) === 1) {
            return 20000 - (int) $matches[1];
        }

        if (preg_match('/^[NН]\s*-\s*(\d+)$/u', $grade, $matches) === 1) {
            return 10000 + max(0, 100 - (int) $matches[1]);
        }

        if (preg_match('/^[NН]\s*-\s*(СТ|ST)$/u', $grade) === 1) {
            return 1;
        }

        return 0;
    }
}
