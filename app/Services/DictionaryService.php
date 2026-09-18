<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class DictionaryService
{
    public static function kinds(): array
    {
        return [
            'volume' => 'Том',
            'section_code' => 'Шифр / комплект',
            'section' => 'Раздел / марка',
        ];
    }

    public static function forTaskForm(?int $projectId = null): array
    {
        $pdo = Database::pdo();
        $params = [];
        $where = 'di.active = 1';
        if ($projectId) {
            $where .= ' AND di.scope_project_id IN (0, :project_id)';
            $params['project_id'] = $projectId;
        } else {
            $where .= ' AND di.scope_project_id = 0';
        }

        $stmt = $pdo->prepare("
            SELECT di.*, p.code AS project_code
            FROM dictionary_items di
            LEFT JOIN projects p ON p.id = di.project_id
            WHERE {$where}
            ORDER BY di.kind, di.scope_project_id, di.sort_order, di.value
        ");
        $stmt->execute($params);

        $result = ['volume' => [], 'section_code' => [], 'section' => []];
        foreach ($stmt->fetchAll() as $item) {
            $result[$item['kind']][] = $item;
        }

        return $result;
    }

    public static function all(): array
    {
        return Database::pdo()->query('
            SELECT di.*, p.code AS project_code, p.title AS project_title
            FROM dictionary_items di
            LEFT JOIN projects p ON p.id = di.project_id
            ORDER BY di.scope_project_id, di.kind, di.sort_order, di.value
        ')->fetchAll();
    }

    public static function globalSections(): array
    {
        return Database::pdo()->query("\n            SELECT id, value, label, discipline, sort_order\n            FROM dictionary_items\n            WHERE scope_project_id = 0 AND kind = 'section' AND active = 1\n            ORDER BY sort_order, value\n        ")->fetchAll();
    }

    public static function addGlobalSection(string $code, string $label): void
    {
        $code = mb_strtoupper(trim($code), 'UTF-8');
        $label = trim($label);
        if ($code === '' || $label === '') {
            throw new \InvalidArgumentException('Укажите шифр и название раздела.');
        }

        $pdo = Database::pdo();
        $exists = $pdo->prepare("\n            SELECT 1\n            FROM dictionary_items\n            WHERE scope_project_id = 0 AND kind = 'section' AND value = ?\n            LIMIT 1\n        ");
        $exists->execute([$code]);
        if ($exists->fetchColumn()) {
            throw new \InvalidArgumentException('Раздел с таким шифром уже есть.');
        }

        $sortOrder = (int) $pdo->query("\n            SELECT COALESCE(MAX(sort_order), 0) + 10\n            FROM dictionary_items\n            WHERE scope_project_id = 0 AND kind = 'section'\n        ")->fetchColumn();
        $stmt = $pdo->prepare("\n            INSERT INTO dictionary_items (project_id, scope_project_id, kind, value, label, discipline, active, sort_order)\n            VALUES (NULL, 0, 'section', ?, ?, ?, 1, ?)\n        ");
        $stmt->execute([$code, $label, $code, $sortOrder]);
    }

    public static function forProjectPage(int $projectId): array
    {
        $stmt = Database::pdo()->prepare('
            SELECT di.*, p.code AS project_code
            FROM dictionary_items di
            LEFT JOIN projects p ON p.id = di.project_id
            WHERE di.scope_project_id IN (0, ?)
            ORDER BY di.scope_project_id, di.kind, di.sort_order, di.value
        ');
        $stmt->execute([$projectId]);

        return $stmt->fetchAll();
    }

    public static function save(array $data): void
    {
        $pdo = Database::pdo();
        $projectId = $data['project_id'] ? (int) $data['project_id'] : null;
        $scopeProjectId = $projectId ?: 0;

        $exists = $pdo->prepare('SELECT id FROM dictionary_items WHERE scope_project_id = ? AND kind = ? AND value = ? LIMIT 1');
        $exists->execute([$scopeProjectId, $data['kind'], $data['value']]);
        $id = $exists->fetchColumn();

        if ($id) {
            $stmt = $pdo->prepare('
                UPDATE dictionary_items
                SET project_id = ?, label = ?, discipline = ?, active = ?, sort_order = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ');
            $stmt->execute([$projectId, $data['label'], $data['discipline'], $data['active'], $data['sort_order'], $id]);
            return;
        }

        $stmt = $pdo->prepare('
            INSERT INTO dictionary_items (project_id, scope_project_id, kind, value, label, discipline, active, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $projectId,
            $scopeProjectId,
            $data['kind'],
            $data['value'],
            $data['label'],
            $data['discipline'],
            $data['active'],
            $data['sort_order'],
        ]);
    }

    public static function payload(array $source, ?int $forcedProjectId = null): array
    {
        $value = trim((string) ($source['value'] ?? ''));
        $label = trim((string) ($source['label'] ?? ''));

        return [
            'project_id' => $forcedProjectId ?? (($source['project_id'] ?? '') !== '' ? (int) $source['project_id'] : null),
            'kind' => array_key_exists((string) ($source['kind'] ?? ''), self::kinds()) ? (string) $source['kind'] : 'volume',
            'value' => $value,
            'label' => $label !== '' ? $label : $value,
            'discipline' => ($source['discipline'] ?? '') !== '' ? (string) $source['discipline'] : null,
            'active' => isset($source['active']) ? 1 : 1,
            'sort_order' => (int) ($source['sort_order'] ?? 0),
        ];
    }
}
