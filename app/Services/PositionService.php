<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class PositionService
{
    private const TITLE_BASE_ROLES = [
        'Инженер-проектировщик' => RoleService::ENGINEER,
        'Главный специалист' => RoleService::CHIEF_SPECIALIST,
        'Руководитель группы' => RoleService::GROUP_LEAD,
        'Начальник отдела' => RoleService::DEPARTMENT_HEAD,
        'Зам. начальника отдела' => RoleService::DEPUTY_DEPARTMENT_HEAD,
        'Главный инженер проекта' => RoleService::GIP,
        'Менеджер ТИМ' => RoleService::BIM_MANAGER,
        'Руководитель проекта' => RoleService::PROJECT_MANAGER,
        'Зам. директора департамента / нач. бюро ГИП' => RoleService::DEPUTY_DIRECTOR,
        'Директор смежного направления' => RoleService::ADJACENT_DIRECTOR,
        'Специалист HR' => RoleService::HR,
        'Директор департамента' => RoleService::DIRECTOR,
    ];

    public static function all(bool $includeArchived = false, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? Database::pdo();
        self::ensureMetadata($pdo);
        $where = $includeArchived ? '' : 'WHERE p.is_active = 1';
        return $pdo->query('SELECT p.*, COUNT(u.id) AS employee_count
            FROM positions p
            LEFT JOIN users u ON u.position_id = p.id AND u.is_active = 1
            ' . $where . '
            GROUP BY p.id
            ORDER BY p.is_active DESC, p.sort_order, p.title')->fetchAll();
    }

    public static function find(int $id, ?PDO $pdo = null): ?array
    {
        $pdo = $pdo ?? Database::pdo();
        self::ensureMetadata($pdo);
        $stmt = $pdo->prepare('SELECT p.*, COUNT(u.id) AS employee_count
            FROM positions p
            LEFT JOIN users u ON u.position_id = p.id AND u.is_active = 1
            WHERE p.id = ? GROUP BY p.id');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function capabilities(int $id, string $baseRole, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? Database::pdo();
        if ($baseRole === RoleService::DIRECTOR) {
            return RoleService::capabilityKeys();
        }
        $stmt = $pdo->prepare('SELECT capability FROM position_access_permissions WHERE position_id = ? AND enabled = 1');
        $stmt->execute([$id]);
        $stored = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        return $stored !== [] ? array_values(array_intersect(RoleService::capabilityKeys(), $stored)) : RoleService::defaultCapabilities($baseRole);
    }

    public static function create(array $payload, int $actorId, ?PDO $pdo = null): int
    {
        $pdo = $pdo ?? Database::pdo();
        $title = trim((string) ($payload['title'] ?? ''));
        $baseRole = self::validBaseRole((string) ($payload['base_role'] ?? RoleService::ENGINEER));
        if ($title === '') {
            throw new \InvalidArgumentException('Укажите название должности.');
        }
        $roleKey = self::uniqueRoleKey($title, $pdo);
        $stmt = $pdo->prepare('INSERT INTO positions
            (role_key, base_role, title, grade, competency_position_index, description, sort_order, is_system, is_protected, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 1)');
        $stmt->execute([
            $roleKey,
            $baseRole,
            $title,
            trim((string) ($payload['grade'] ?? '')) ?: null,
            self::competencyPositionIndex($payload['competency_position_index'] ?? null),
            trim((string) ($payload['description'] ?? '')) ?: null,
            (int) ($payload['sort_order'] ?? 100),
        ]);
        $id = (int) $pdo->lastInsertId();
        self::saveCapabilities($id, (array) ($payload['capabilities'] ?? RoleService::defaultCapabilities($baseRole)), $actorId, $pdo);
        AuditService::record('position_created', ['position_id' => $id, 'title' => $title, 'base_role' => $baseRole]);
        return $id;
    }

    public static function update(int $id, array $payload, int $actorId, ?PDO $pdo = null): void
    {
        $pdo = $pdo ?? Database::pdo();
        $position = self::find($id, $pdo);
        if (!$position) {
            throw new \InvalidArgumentException('Должность не найдена.');
        }
        $protected = (int) ($position['is_protected'] ?? 0) === 1;
        $baseRole = $protected ? RoleService::DIRECTOR : self::validBaseRole((string) ($payload['base_role'] ?? $position['base_role']));
        $title = trim((string) ($payload['title'] ?? $position['title']));
        if ($title === '') {
            throw new \InvalidArgumentException('Укажите название должности.');
        }
        $stmt = $pdo->prepare('UPDATE positions SET title = ?, grade = ?, competency_position_index = ?, description = ?, sort_order = ?, base_role = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([
            $title,
            trim((string) ($payload['grade'] ?? '')) ?: null,
            self::competencyPositionIndex($payload['competency_position_index'] ?? null),
            trim((string) ($payload['description'] ?? '')) ?: null,
            (int) ($payload['sort_order'] ?? 100),
            $baseRole,
            $id,
        ]);
        $capabilities = $protected ? RoleService::capabilityKeys() : (array) ($payload['capabilities'] ?? []);
        self::saveCapabilities($id, $capabilities, $actorId, $pdo);
        $pdo->prepare('UPDATE users SET role = (SELECT role_key FROM positions WHERE id = ?) WHERE position_id = ?')->execute([$id, $id]);
        AuditService::record('position_updated', ['position_id' => $id, 'title' => $title, 'base_role' => $baseRole]);
        RoleService::resetCache();
    }

    public static function clonePosition(int $id, int $actorId, ?PDO $pdo = null): int
    {
        $pdo = $pdo ?? Database::pdo();
        $source = self::find($id, $pdo);
        if (!$source) {
            throw new \InvalidArgumentException('Должность не найдена.');
        }
        return self::create([
            'title' => (string) $source['title'] . ' — копия',
            'grade' => (string) ($source['grade'] ?? ''),
            'competency_position_index' => $source['competency_position_index'] ?? null,
            'description' => (string) ($source['description'] ?? ''),
            'sort_order' => (int) ($source['sort_order'] ?? 100) + 1,
            'base_role' => (string) ($source['base_role'] ?? RoleService::ENGINEER),
            'capabilities' => self::capabilities($id, (string) ($source['base_role'] ?? RoleService::ENGINEER), $pdo),
        ], $actorId, $pdo);
    }

    public static function archive(int $id, int $actorId, ?PDO $pdo = null): void
    {
        $pdo = $pdo ?? Database::pdo();
        $position = self::find($id, $pdo);
        if (!$position) {
            throw new \InvalidArgumentException('Должность не найдена.');
        }
        if ((int) ($position['is_protected'] ?? 0) === 1) {
            throw new \InvalidArgumentException('Защищённую должность директора архивировать нельзя.');
        }
        if ((int) ($position['employee_count'] ?? 0) > 0) {
            throw new \InvalidArgumentException('Сначала переназначьте сотрудников с этой должности.');
        }
        $pdo->prepare('UPDATE positions SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
        AuditService::record('position_archived', ['position_id' => $id, 'title' => (string) $position['title']]);
    }

    public static function roleKeyForPosition(?int $id, ?PDO $pdo = null): ?string
    {
        if (!$id) {
            return null;
        }
        $pdo = $pdo ?? Database::pdo();
        self::ensureMetadata($pdo);
        $stmt = $pdo->prepare('SELECT role_key FROM positions WHERE id = ? AND is_active = 1');
        $stmt->execute([$id]);
        $key = $stmt->fetchColumn();
        return $key !== false && $key !== '' ? (string) $key : null;
    }

    public static function ensureMetadata(?PDO $pdo = null): void
    {
        $pdo = $pdo ?? Database::pdo();
        try {
            $rows = $pdo->query('SELECT id, title, role_key, base_role FROM positions')->fetchAll();
        } catch (\Throwable) {
            return;
        }
        $stmt = $pdo->prepare('UPDATE positions SET role_key = ?, base_role = ?, is_system = ?, is_protected = ? WHERE id = ?');
        foreach ($rows as $row) {
            $title = (string) ($row['title'] ?? '');
            $baseRole = self::TITLE_BASE_ROLES[$title] ?? (string) ($row['base_role'] ?? RoleService::ENGINEER);
            $roleKey = trim((string) ($row['role_key'] ?? ''));
            if ($roleKey === '') {
                $preferred = self::TITLE_BASE_ROLES[$title] ?? ('position_' . (int) $row['id']);
                $roleKey = self::roleKeyAvailable($preferred, (int) $row['id'], $pdo) ? $preferred : 'position_' . (int) $row['id'];
            }
            $protected = $baseRole === RoleService::DIRECTOR ? 1 : 0;
            $stmt->execute([$roleKey, $baseRole, isset(self::TITLE_BASE_ROLES[$title]) ? 1 : 0, $protected, (int) $row['id']]);
        }
        $titlesByRole = array_flip(self::TITLE_BASE_ROLES);
        foreach (array_values(array_diff(RoleService::roles(), [RoleService::ADMIN])) as $baseRole) {
            $exists = $pdo->prepare('SELECT id FROM positions WHERE role_key = ? LIMIT 1');
            $exists->execute([$baseRole]);
            if ($exists->fetchColumn()) {
                continue;
            }
            $title = (string) ($titlesByRole[$baseRole] ?? RoleService::label($baseRole));
            $insert = $pdo->prepare('INSERT INTO positions (role_key, base_role, title, sort_order, is_system, is_protected, is_active) VALUES (?, ?, ?, ?, 1, ?, 1)');
            $insert->execute([$baseRole, $baseRole, $title, RoleService::level($baseRole) * 10, $baseRole === RoleService::DIRECTOR ? 1 : 0]);
        }
    }

    private static function saveCapabilities(int $id, array $capabilities, int $actorId, PDO $pdo): void
    {
        $enabled = array_fill_keys(array_values(array_intersect(RoleService::capabilityKeys(), array_map('strval', $capabilities))), true);
        $driver = (string) config('db.connection');
        $sql = $driver === 'sqlite'
            ? 'INSERT INTO position_access_permissions (position_id, capability, enabled, updated_by, updated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
               ON CONFLICT(position_id, capability) DO UPDATE SET enabled = excluded.enabled, updated_by = excluded.updated_by, updated_at = CURRENT_TIMESTAMP'
            : 'INSERT INTO position_access_permissions (position_id, capability, enabled, updated_by) VALUES (?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP';
        $stmt = $pdo->prepare($sql);
        foreach (RoleService::capabilityKeys() as $capability) {
            $stmt->execute([$id, $capability, isset($enabled[$capability]) ? 1 : 0, $actorId]);
        }
        RoleService::resetCache();
    }

    private static function validBaseRole(string $role): string
    {
        $role = RoleService::normalize($role);
        return in_array($role, array_values(array_diff(RoleService::roles(), [RoleService::ADMIN])), true) ? $role : RoleService::ENGINEER;
    }

    private static function competencyPositionIndex(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $index = filter_var($value, FILTER_VALIDATE_INT);
        $matrixFile = dirname(__DIR__, 2) . '/config/performance_review_matrix.php';
        $matrix = is_file($matrixFile) ? require $matrixFile : [];
        if ($index === false || !isset($matrix['positions'][(int) $index])) {
            throw new \InvalidArgumentException('Выберите существующий целевой профиль Performance Review.');
        }

        return (int) $index;
    }

    private static function uniqueRoleKey(string $title, PDO $pdo): string
    {
        $slug = mb_strtolower($title, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9]+/u', '_', $slug) ?? '';
        $slug = trim($slug, '_');
        $slug = $slug !== '' ? 'position_' . substr($slug, 0, 70) : 'position';
        $candidate = $slug;
        $index = 2;
        while (!self::roleKeyAvailable($candidate, 0, $pdo)) {
            $candidate = substr($slug, 0, 88) . '_' . $index++;
        }
        return $candidate;
    }

    private static function roleKeyAvailable(string $roleKey, int $exceptId, PDO $pdo): bool
    {
        $stmt = $pdo->prepare('SELECT id FROM positions WHERE role_key = ? AND id <> ? LIMIT 1');
        $stmt->execute([$roleKey, $exceptId]);
        return !$stmt->fetchColumn();
    }
}
