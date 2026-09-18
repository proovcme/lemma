<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class MonthlyWorkLedgerPreferenceService
{
    /** @var array<string,string> */
    public const FIELD_CATALOG = [
        'work_date' => 'Дата списания',
        'project' => 'Проект',
        'pp' => 'ПП',
        'btp' => 'БТП',
        'department' => 'Отдел',
        'employee' => 'Сотрудник',
        'tab_number' => 'Табельный номер',
        'task' => 'Задача',
        'section' => 'Раздел',
        'status' => 'Статус задачи',
        'hours' => 'Списано, ч',
    ];

    private const FILTER_KEYS = ['project', 'pp', 'btp', 'department', 'employee', 'task', 'section', 'status'];
    private const GROUPS = ['general', 'people', 'project', 'task'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{visible_fields:list<string>,field_order:list<string>,default_group_by:string,filters:array<string,string>} */
    public static function defaults(): array
    {
        return [
            'visible_fields' => array_keys(self::FIELD_CATALOG),
            'field_order' => array_keys(self::FIELD_CATALOG),
            'default_group_by' => 'general',
            'filters' => [],
        ];
    }

    /** @return array{visible_fields:list<string>,field_order:list<string>,default_group_by:string,filters:array<string,string>} */
    public function load(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT visible_fields, field_order, default_group_by, filters_json FROM monthly_work_ledger_preferences WHERE user_id = ?');
        $stmt->execute([$userId]);
        $stored = $stmt->fetch();
        if (!$stored) {
            return self::defaults();
        }

        return $this->normalize([
            'visible_fields' => $this->decode((string) $stored['visible_fields']),
            'field_order' => $this->decode((string) $stored['field_order']),
            'default_group_by' => $stored['default_group_by'],
            'filters' => $this->decode((string) $stored['filters_json']),
        ]);
    }

    /** @return array{visible_fields:list<string>,field_order:list<string>,default_group_by:string,filters:array<string,string>} */
    public function save(int $userId, array $input): array
    {
        $value = $this->normalize($input);
        $sql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INSERT INTO monthly_work_ledger_preferences (user_id, visible_fields, field_order, default_group_by, filters_json) VALUES (?, ?, ?, ?, ?) ON CONFLICT(user_id) DO UPDATE SET visible_fields = excluded.visible_fields, field_order = excluded.field_order, default_group_by = excluded.default_group_by, filters_json = excluded.filters_json, updated_at = CURRENT_TIMESTAMP'
            : 'INSERT INTO monthly_work_ledger_preferences (user_id, visible_fields, field_order, default_group_by, filters_json) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE visible_fields = VALUES(visible_fields), field_order = VALUES(field_order), default_group_by = VALUES(default_group_by), filters_json = VALUES(filters_json), updated_at = CURRENT_TIMESTAMP';
        $this->pdo->prepare($sql)->execute([
            $userId,
            json_encode($value['visible_fields'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($value['field_order'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $value['default_group_by'],
            json_encode($value['filters'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);

        return $value;
    }

    /** @return array{visible_fields:list<string>,field_order:list<string>,default_group_by:string,filters:array<string,string>} */
    private function normalize(array $input): array
    {
        $defaults = self::defaults();
        $visible = $this->fieldList($input['visible_fields'] ?? $defaults['visible_fields']);
        $order = $this->fieldList($input['field_order'] ?? $visible);
        $order = array_values(array_filter($order, static fn (string $field): bool => in_array($field, $visible, true)));
        foreach ($visible as $field) {
            if (!in_array($field, $order, true)) {
                $order[] = $field;
            }
        }
        $filters = [];
        foreach ((array) ($input['filters'] ?? []) as $key => $value) {
            if (!in_array($key, self::FILTER_KEYS, true) || is_array($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '') {
                $filters[$key] = mb_substr($value, 0, 200);
            }
        }

        return [
            'visible_fields' => $visible,
            'field_order' => $order,
            'default_group_by' => in_array((string) ($input['default_group_by'] ?? ''), self::GROUPS, true) ? (string) $input['default_group_by'] : 'general',
            'filters' => $filters,
        ];
    }

    /** @return list<string> */
    private function fieldList(mixed $value): array
    {
        $result = [];
        foreach ((array) $value as $field) {
            $field = (string) $field;
            if (isset(self::FIELD_CATALOG[$field]) && !in_array($field, $result, true)) {
                $result[] = $field;
            }
        }
        return $result === [] ? array_keys(self::FIELD_CATALOG) : $result;
    }

    /** @return array<mixed> */
    private function decode(string $json): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($value) ? $value : [];
        } catch (\JsonException) {
            return [];
        }
    }
}
