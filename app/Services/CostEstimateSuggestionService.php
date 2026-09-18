<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class CostEstimateSuggestionService
{
    public const OBJECT_TYPES = [
        'housing' => 'Жилой объект',
        'public' => 'Общественный объект',
        'office' => 'Офис / БЦ',
        'hotel' => 'Гостиница',
        'mall' => 'ТРЦ / МФК',
        'industrial' => 'Производственный объект',
        'datacenter' => 'ЦОД / дата-центр',
        'linear' => 'Линейный объект',
        'other' => 'Другое',
    ];

    private const SECTION_TEMPLATES = [
        'housing' => [
            ['code' => 'ГП', 'title' => 'Генеральный план'],
            ['code' => 'АР', 'title' => 'Архитектурные решения'],
            ['code' => 'КР', 'title' => 'Конструктивные решения'],
            ['code' => 'ОВ', 'title' => 'Отопление, вентиляция и кондиционирование'],
            ['code' => 'ВК', 'title' => 'Водоснабжение и канализация'],
            ['code' => 'ЭОМ', 'title' => 'Электрооборудование'],
            ['code' => 'СС', 'title' => 'Сети связи и сигнализация'],
            ['code' => 'ПОС', 'title' => 'Организация строительства'],
        ],
        'public' => [
            ['code' => 'ГП', 'title' => 'Генеральный план'],
            ['code' => 'АР', 'title' => 'Архитектурные решения'],
            ['code' => 'КР', 'title' => 'Конструктивные решения'],
            ['code' => 'ТХ', 'title' => 'Технологические решения'],
            ['code' => 'ОВ', 'title' => 'Отопление, вентиляция и кондиционирование'],
            ['code' => 'ВК', 'title' => 'Водоснабжение и канализация'],
            ['code' => 'ЭОМ', 'title' => 'Электрооборудование'],
            ['code' => 'СС', 'title' => 'Сети связи и сигнализация'],
        ],
        'office' => [
            ['code' => 'ГП', 'title' => 'Генеральный план'],
            ['code' => 'АР', 'title' => 'Архитектурные решения'],
            ['code' => 'КР', 'title' => 'Конструктивные решения'],
            ['code' => 'ОВ', 'title' => 'Отопление, вентиляция и кондиционирование'],
            ['code' => 'ВК', 'title' => 'Водоснабжение и канализация'],
            ['code' => 'ЭОМ', 'title' => 'Электрооборудование'],
            ['code' => 'СС', 'title' => 'Сети связи, СКС, безопасность'],
            ['code' => 'ПОС', 'title' => 'Организация строительства'],
        ],
        'hotel' => [
            ['code' => 'ГП', 'title' => 'Генеральный план'],
            ['code' => 'АР', 'title' => 'Архитектурные решения'],
            ['code' => 'КР', 'title' => 'Конструктивные решения'],
            ['code' => 'ТХ', 'title' => 'Технологические решения'],
            ['code' => 'ОВ', 'title' => 'Отопление, вентиляция и кондиционирование'],
            ['code' => 'ВК', 'title' => 'Водоснабжение и канализация'],
            ['code' => 'ЭОМ', 'title' => 'Электрооборудование'],
            ['code' => 'СС', 'title' => 'Сети связи и безопасность'],
        ],
        'mall' => [
            ['code' => 'ГП', 'title' => 'Генеральный план'],
            ['code' => 'АР', 'title' => 'Архитектурные решения'],
            ['code' => 'КР', 'title' => 'Конструктивные решения'],
            ['code' => 'ТХ', 'title' => 'Технологические решения'],
            ['code' => 'ОВ', 'title' => 'Отопление, вентиляция и кондиционирование'],
            ['code' => 'ВК', 'title' => 'Водоснабжение и канализация'],
            ['code' => 'ЭОМ', 'title' => 'Электрооборудование'],
            ['code' => 'СС', 'title' => 'Сети связи, СКУД, видеонаблюдение'],
        ],
        'industrial' => [
            ['code' => 'ГП', 'title' => 'Генеральный план'],
            ['code' => 'ТХ', 'title' => 'Технологические решения'],
            ['code' => 'КМ', 'title' => 'Металлические конструкции'],
            ['code' => 'КЖ', 'title' => 'Железобетонные конструкции'],
            ['code' => 'ОВ', 'title' => 'Отопление и вентиляция'],
            ['code' => 'ВК', 'title' => 'Водоснабжение и канализация'],
            ['code' => 'ЭОМ', 'title' => 'Электрооборудование'],
            ['code' => 'ПОС', 'title' => 'Организация строительства'],
        ],
        'datacenter' => [
            ['code' => 'ГП', 'title' => 'Генеральный план'],
            ['code' => 'АР', 'title' => 'Архитектурные решения'],
            ['code' => 'КР', 'title' => 'Конструктивные решения'],
            ['code' => 'ОВ', 'title' => 'Прецизионное охлаждение и вентиляция'],
            ['code' => 'ВК', 'title' => 'Водоснабжение и пожаротушение'],
            ['code' => 'ЭОМ', 'title' => 'Электроснабжение, ИБП, ДГУ'],
            ['code' => 'СС', 'title' => 'Сети связи, мониторинг, безопасность'],
            ['code' => 'АСУ', 'title' => 'Диспетчеризация и автоматизация'],
        ],
        'linear' => [
            ['code' => 'ПЗ', 'title' => 'Пояснительная записка'],
            ['code' => 'ГП', 'title' => 'Полоса отвода и план трассы'],
            ['code' => 'КР', 'title' => 'Конструктивные решения'],
            ['code' => 'ЭОМ', 'title' => 'Электроснабжение'],
            ['code' => 'СС', 'title' => 'Связь'],
            ['code' => 'ПОС', 'title' => 'Организация строительства'],
        ],
        'other' => [
            ['code' => 'ПЗ', 'title' => 'Пояснительная записка'],
            ['code' => 'ГП', 'title' => 'Генеральный план'],
            ['code' => 'АР', 'title' => 'Архитектурные решения'],
            ['code' => 'КР', 'title' => 'Конструктивные решения'],
            ['code' => 'ОВ', 'title' => 'Отопление и вентиляция'],
            ['code' => 'ВК', 'title' => 'Водоснабжение и канализация'],
            ['code' => 'ЭОМ', 'title' => 'Электрооборудование'],
        ],
    ];

    private const SECTION_KEYWORDS = [
        'ПЗ' => ['пояснительн', 'записк'],
        'ГП' => ['генплан', 'генеральн', 'план'],
        'АР' => ['архитектур'],
        'КР' => ['конструктив', 'строительн конструкц'],
        'КЖ' => ['железобетон', 'бетон'],
        'КМ' => ['металл'],
        'ОВ' => ['отоплен', 'вентиляц', 'кондиционир'],
        'ВК' => ['водоснаб', 'канализац'],
        'ЭОМ' => ['электро', 'электроснаб'],
        'СС' => ['связ', 'сигнализац', 'телеком'],
        'АСУ' => ['автоматизац', 'диспетчеризац', 'мониторинг', 'асу'],
        'ТХ' => ['технолог'],
        'ПОС' => ['организац строительств', 'пос'],
    ];

    public function objectTypeOptions(): array
    {
        return self::OBJECT_TYPES;
    }

    public function templateText(string $objectType): string
    {
        $sections = self::SECTION_TEMPLATES[$objectType] ?? self::SECTION_TEMPLATES['other'];

        return implode("\n", array_map(static fn (array $section): string => $section['code'] . ' - ' . $section['title'], $sections));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function suggestions(PDO $pdo, array $estimate): array
    {
        $sections = $this->sections((string) ($estimate['sections_text'] ?? ''), (string) ($estimate['object_type'] ?? 'other'));
        $items = [];
        foreach ($sections as $section) {
            $sbcItem = $this->findSbcItem($pdo, $section, (string) ($estimate['object_type'] ?? 'other'));
            $quantity = $this->quantityFor($sbcItem, (float) ($estimate['area_m2'] ?? 0));
            $mappingBasis = $this->mappingBasis($estimate, $section, $sbcItem);

            $items[] = [
                'section' => $section,
                'sbc_item' => $sbcItem,
                'source' => [
                    'section_code' => $section['code'],
                    'sbc_item_id' => $sbcItem['id'] ?? '',
                    'work_name' => $sbcItem ? '' : $section['title'],
                    'unit' => $sbcItem ? '' : ($quantity > 1 ? 'м2' : 'раздел'),
                    'labor_hours' => '',
                    'labor_estimate_method' => $sbcItem && (float) ($sbcItem['default_labor_hours'] ?? 0) > 0 ? 'norm' : 'manual',
                    'labor_executor_hours' => '',
                    'labor_gip_hours' => '',
                    'labor_adjustment_hours' => '',
                    'labor_directive_hours' => '',
                    'labor_norm_hours' => $sbcItem ? (string) ($sbcItem['default_labor_hours'] ?? '') : '',
                    'labor_productivity_rate' => '',
                    'labor_productivity_coeff' => '1',
                    'labor_basis' => '',
                    'quantity' => $quantity,
                    'base_price' => '',
                    'stage_percent' => $this->stagePercent($estimate),
                    'complexity_coeff' => '1',
                    'deflator_coeff' => $this->deflatorCoeff($estimate),
                    'adjustment_coeff' => '1',
                    'price_level' => (string) (($estimate['price_level'] ?? '') ?: 'база СБЦ'),
                    'justification' => $sbcItem ? '' : 'Раздел "' . $section['code'] . ' - ' . $section['title'] . '" включён в состав предпроектной оценки. Пункт СБЦ не подобран автоматически; требуется ручной выбор сборника и пункта.',
                    'comments' => $mappingBasis,
                ],
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array{code: string, title: string}>
     */
    public function sections(string $text, string $objectType): array
    {
        $text = trim($text);
        if ($text === '') {
            return self::SECTION_TEMPLATES[$objectType] ?? self::SECTION_TEMPLATES['other'];
        }

        $result = [];
        $lines = preg_split('/[\r\n;]+/u', $text) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $line = preg_replace('/^\s*[-*]\s*/u', '', $line) ?? $line;
            $code = '';
            $title = $line;
            if (preg_match('/^([A-ZА-Я0-9-]{1,8})\s*[-–—:]\s*(.+)$/u', $line, $matches)) {
                $code = mb_strtoupper($matches[1], 'UTF-8');
                $title = trim($matches[2]);
            } elseif (preg_match('/^([A-ZА-Я0-9-]{1,8})\s+(.+)$/u', $line, $matches)) {
                $code = mb_strtoupper($matches[1], 'UTF-8');
                $title = trim($matches[2]);
            } else {
                $code = $this->codeByTitle($line);
            }

            $key = $code !== '' ? $code : mb_strtoupper($title, 'UTF-8');
            if (isset($result[$key])) {
                continue;
            }
            $result[$key] = ['code' => $code !== '' ? $code : 'РАЗД', 'title' => $title];
        }

        return array_values($result);
    }

    private function findSbcItem(PDO $pdo, array $section, string $objectType): ?array
    {
        $keywords = $this->keywords($section, $objectType);
        if ($keywords === []) {
            return null;
        }

        $where = [];
        $params = [];
        foreach ($keywords as $keyword) {
            foreach (['work_name', 'collection_name', 'note', 'source_ref'] as $column) {
                $where[] = $column . ' LIKE ?';
                $params[] = '%' . $keyword . '%';
            }
        }

        $objectOrder = match ($objectType) {
            'housing' => "CASE WHEN collection_name LIKE '%жилищ%' OR collection_name LIKE '%граждан%' THEN 0 ELSE 1 END,",
            'public', 'office', 'hotel', 'mall' => "CASE WHEN collection_name LIKE '%граждан%' OR collection_name LIKE '%обществен%' THEN 0 ELSE 1 END,",
            'industrial' => "CASE WHEN collection_name LIKE '%производ%' OR collection_name LIKE '%промышлен%' THEN 0 ELSE 1 END,",
            default => '',
        };

        $sql = '
            SELECT *
            FROM sbc_items
            WHERE ' . implode(' OR ', $where) . '
            ORDER BY ' . $objectOrder . ' base_price DESC, id
            LIMIT 1
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() ?: null;
    }

    private function keywords(array $section, string $objectType): array
    {
        $keywords = self::SECTION_KEYWORDS[$section['code']] ?? [];
        foreach (preg_split('/\s+/u', mb_strtolower((string) $section['title'], 'UTF-8')) ?: [] as $word) {
            $word = trim($word);
            if (mb_strlen($word, 'UTF-8') >= 5) {
                $keywords[] = $word;
            }
        }

        return array_values(array_unique(array_filter($keywords)));
    }

    private function codeByTitle(string $title): string
    {
        $lower = mb_strtolower($title, 'UTF-8');
        foreach (self::SECTION_KEYWORDS as $code => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, mb_strtolower($keyword, 'UTF-8'))) {
                    return $code;
                }
            }
        }

        return '';
    }

    private function quantityFor(?array $sbcItem, float $area): float
    {
        $unit = mb_strtolower((string) ($sbcItem['unit'] ?? ''), 'UTF-8');
        if ($area > 0 && (str_contains($unit, 'м2') || str_contains($unit, 'м²') || str_contains($unit, 'площад'))) {
            return $area;
        }

        return 1.0;
    }

    private function stagePercent(array $estimate): float
    {
        $value = (float) ($estimate['default_stage_percent'] ?? 0);
        if ($value > 0) {
            return $value;
        }

        $stage = mb_strtolower((string) ($estimate['stage'] ?? ''), 'UTF-8');
        if (str_contains($stage, 'пред')) {
            return 15.0;
        }
        if ($stage === 'п' || str_contains($stage, 'проект')) {
            return 40.0;
        }
        if ($stage === 'р' || str_contains($stage, 'рабоч')) {
            return 60.0;
        }

        return 100.0;
    }

    private function deflatorCoeff(array $estimate): float
    {
        $value = (float) ($estimate['default_deflator_coeff'] ?? 0);

        return $value > 0 ? $value : 1.0;
    }

    private function mappingBasis(array $estimate, array $section, ?array $sbcItem): string
    {
        $parts = [
            'Маппинг: тип объекта "' . (self::OBJECT_TYPES[(string) ($estimate['object_type'] ?? '')] ?? 'Другое') . '"',
            'раздел "' . $section['code'] . ' - ' . $section['title'] . '"',
        ];
        if ((float) ($estimate['area_m2'] ?? 0) > 0) {
            $parts[] = 'площадь ' . number_format((float) $estimate['area_m2'], 2, '.', ' ') . ' м2';
        }
        if (!empty($estimate['start_date']) || !empty($estimate['finish_date'])) {
            $parts[] = 'срок ' . trim((string) ($estimate['start_date'] ?? '') . ' - ' . (string) ($estimate['finish_date'] ?? ''));
        }
        if ((float) ($estimate['duration_months'] ?? 0) > 0) {
            $parts[] = 'длительность ' . number_format((float) $estimate['duration_months'], 1, '.', ' ') . ' мес.';
        }
        $parts[] = $sbcItem
            ? 'подобран СБЦ #' . (int) $sbcItem['id'] . ' по ключевым словам раздела'
            : 'пункт СБЦ не найден автоматически';

        return implode('; ', $parts) . '.';
    }
}
