<?php

declare(strict_types=1);

namespace App\Services;

final class CostEstimateBenchmarkService
{
    private const REGIONS = [
        'moscow' => ['label' => 'г. Москва', 'k_per' => 1.02],
        'moscow_obl' => ['label' => 'Московская область', 'k_per' => 1.00],
        'spb' => ['label' => 'г. Санкт-Петербург', 'k_per' => 1.00],
        'tatarstan' => ['label' => 'Республика Татарстан', 'k_per' => 0.81],
        'ural' => ['label' => 'Свердловская область', 'k_per' => 0.94],
        'south' => ['label' => 'Краснодарский край', 'k_per' => 0.84],
        'khmao' => ['label' => 'Ханты-Мансийский АО', 'k_per' => 1.12],
        'yanao' => ['label' => 'Ямало-Ненецкий АО', 'k_per' => 1.44],
        'sakha' => ['label' => 'Республика Саха (Якутия)', 'k_per' => 1.60],
    ];

    private const WORK_TYPES = [
        'new' => ['label' => 'Новое строительство', 'coeff' => 1.00],
        'reconstruction' => ['label' => 'Реконструкция', 'coeff' => 1.15],
        'overhaul' => ['label' => 'Капитальный ремонт', 'coeff' => 0.70],
        'modernization' => ['label' => 'Техническое перевооружение', 'coeff' => 0.55],
    ];

    private const OBJECT_CLASSES = [
        'economy' => 'Базовый',
        'standard' => 'Стандарт',
        'premium' => 'Повышенная сложность',
    ];

    private const PROFILES = [
        'housing' => [
            'title' => 'Жилой объект',
            'class_labels' => ['economy' => 'Эконом', 'standard' => 'Комфорт', 'premium' => 'Бизнес-класс'],
            'cost' => ['economy' => 65000, 'standard' => 80000, 'premium' => 100000],
            'source' => 'НЦС 81-02-01-2026: жилые здания',
            'confidence' => 'средняя',
        ],
        'public' => [
            'title' => 'Общественный объект',
            'class_labels' => ['economy' => 'Базовый', 'standard' => 'Стандарт', 'premium' => 'Повышенная сложность'],
            'cost' => ['economy' => 70000, 'standard' => 95000, 'premium' => 120000],
            'source' => 'НЦС 81-02-02-2026: административные и общественные здания',
            'confidence' => 'средняя',
        ],
        'office' => [
            'title' => 'Офис / БЦ',
            'class_labels' => ['economy' => 'Класс C', 'standard' => 'Класс B', 'premium' => 'Класс A'],
            'cost' => ['economy' => 70000, 'standard' => 95000, 'premium' => 120000],
            'source' => 'НЦС 81-02-02-2026: административные здания',
            'confidence' => 'средняя',
        ],
        'hotel' => [
            'title' => 'Гостиница',
            'class_labels' => ['economy' => '3 звезды', 'standard' => '4 звезды', 'premium' => '5 звезд'],
            'cost' => ['economy' => 80000, 'standard' => 110000, 'premium' => 145000],
            'source' => 'Расчетный аналог НЦС 2026: гостиницы',
            'confidence' => 'низкая',
        ],
        'mall' => [
            'title' => 'ТРЦ / МФК',
            'class_labels' => ['economy' => 'Районный', 'standard' => 'Городской', 'premium' => 'Флагман / МФК'],
            'cost' => ['economy' => 80000, 'standard' => 108000, 'premium' => 135000],
            'source' => 'Расчетный аналог НЦС 2026: торгово-развлекательные центры',
            'confidence' => 'низкая',
        ],
        'industrial' => [
            'title' => 'Производственный объект',
            'class_labels' => ['economy' => 'Легкая категория', 'standard' => 'Средняя категория', 'premium' => 'Специальная категория'],
            'cost' => ['economy' => 45000, 'standard' => 65000, 'premium' => 88000],
            'source' => 'Расчетный аналог НЦС 2026: производственные здания',
            'confidence' => 'низкая',
        ],
        'datacenter' => [
            'title' => 'ЦОД / дата-центр',
            'class_labels' => ['economy' => 'Edge / N', 'standard' => 'N+1', 'premium' => '2N'],
            'cost' => ['economy' => 220000, 'standard' => 320000, 'premium' => 460000],
            'source' => 'Локальный бенчмарк ЦОД: shell + инженерная инфраструктура без IT-оборудования',
            'confidence' => 'низкая',
            'benchmark_type' => 'local',
        ],
        'linear' => [
            'title' => 'Линейный объект',
            'class_labels' => ['economy' => 'Базовый', 'standard' => 'Стандарт', 'premium' => 'Повышенная сложность'],
            'cost' => ['economy' => 0, 'standard' => 0, 'premium' => 0],
            'source' => 'Для линейных объектов нужен профильный сборник и натуральный показатель',
            'confidence' => 'низкая',
        ],
        'other' => [
            'title' => 'Другое',
            'class_labels' => ['economy' => 'Базовый', 'standard' => 'Стандарт', 'premium' => 'Повышенная сложность'],
            'cost' => ['economy' => 60000, 'standard' => 75000, 'premium' => 95000],
            'source' => 'Локальная укрупненная матрица для предпроектной сверки',
            'confidence' => 'низкая',
            'benchmark_type' => 'local',
        ],
    ];

    public function regionOptions(): array
    {
        $options = [];
        foreach (self::REGIONS as $key => $region) {
            $options[$key] = $region['label'];
        }

        return $options;
    }

    public function objectClassOptions(): array
    {
        return self::OBJECT_CLASSES;
    }

    public function workTypeOptions(): array
    {
        $options = [];
        foreach (self::WORK_TYPES as $key => $type) {
            $options[$key] = $type['label'];
        }

        return $options;
    }

    public function hasRegion(string $value): bool
    {
        return array_key_exists($value, self::REGIONS);
    }

    public function hasObjectClass(string $value): bool
    {
        return array_key_exists($value, self::OBJECT_CLASSES);
    }

    public function hasWorkType(string $value): bool
    {
        return array_key_exists($value, self::WORK_TYPES);
    }

    /**
     * @return array<string, mixed>
     */
    public function benchmark(array $estimate, array $totals): array
    {
        $objectType = (string) ($estimate['object_type'] ?? 'other');
        $profile = self::PROFILES[$objectType] ?? self::PROFILES['other'];
        $objectClass = $this->hasObjectClass((string) ($estimate['object_class'] ?? ''))
            ? (string) $estimate['object_class']
            : 'standard';
        $regionCode = $this->hasRegion((string) ($estimate['region_code'] ?? ''))
            ? (string) $estimate['region_code']
            : 'moscow_obl';
        $workType = $this->hasWorkType((string) ($estimate['work_type'] ?? ''))
            ? (string) $estimate['work_type']
            : 'new';

        $area = (float) ($estimate['area_m2'] ?? 0);
        $baseRate = (float) ($profile['cost'][$objectClass] ?? 0);
        $stagePercent = $this->stagePercent($estimate);
        $deflatorCoeff = $this->positiveNumber($estimate['default_deflator_coeff'] ?? 0, 1.0);
        $floors = (float) ($estimate['floors'] ?? 0);
        $floorCoeff = $this->floorCoeff($floors);
        $regionCoeff = (float) self::REGIONS[$regionCode]['k_per'];
        $workCoeff = (float) self::WORK_TYPES[$workType]['coeff'];
        $plannedCost = (float) ($totals['planned_cost'] ?? 0);

        $base = [
            'available' => false,
            'profile_title' => $profile['title'],
            'profile_source' => $profile['source'],
            'confidence' => $profile['confidence'],
            'class_label' => $profile['class_labels'][$objectClass] ?? self::OBJECT_CLASSES[$objectClass],
            'region_label' => self::REGIONS[$regionCode]['label'],
            'work_type_label' => self::WORK_TYPES[$workType]['label'],
            'base_rate' => $baseRate,
            'stage_percent' => $stagePercent,
            'deflator_coeff' => $deflatorCoeff,
            'region_coeff' => $regionCoeff,
            'work_coeff' => $workCoeff,
            'floor_coeff' => $floorCoeff,
            'planned_cost' => $plannedCost,
            'reason' => '',
            'formula_lines' => [],
            'note' => '',
        ];

        if ($area <= 0) {
            $base['reason'] = 'Укажите площадь объекта, чтобы получить укрупнённый ориентир НЦС/ПИР.';
            return $base;
        }
        if ($baseRate <= 0) {
            $base['reason'] = 'Для выбранного типа объекта нет м2-бенчмарка; используйте строки СБЦ с профильным натуральным показателем.';
            return $base;
        }

        $smrBenchmark = $area * $baseRate * $workCoeff * $floorCoeff * $regionCoeff * $deflatorCoeff;
        $pirRate = $this->pirRate($smrBenchmark);
        $fullDesignCost = $smrBenchmark * $pirRate;
        $stageDesignCost = $fullDesignCost * ($stagePercent / 100);
        $stageDesignCostThousand = $stageDesignCost / 1000;
        $deviationPercent = $stageDesignCostThousand > 0 && $plannedCost > 0
            ? (($plannedCost - $stageDesignCostThousand) / $stageDesignCostThousand) * 100
            : null;
        $isLocal = ($profile['benchmark_type'] ?? '') === 'local' || $workType === 'modernization';

        return array_merge($base, [
            'available' => true,
            'area' => $area,
            'smr_benchmark' => round($smrBenchmark, 2),
            'pir_rate' => $pirRate,
            'full_design_cost' => round($fullDesignCost, 2),
            'stage_design_cost' => round($stageDesignCost, 2),
            'smr_benchmark_thousand' => round($smrBenchmark / 1000, 2),
            'full_design_cost_thousand' => round($fullDesignCost / 1000, 2),
            'stage_design_cost_thousand' => round($stageDesignCostThousand, 2),
            'deviation_percent' => $deviationPercent !== null ? round($deviationPercent, 1) : null,
            'note' => $isLocal
                ? 'Это локальный предпроектный бенчмарк, не нормативный лимит. Итоговые строки СБЦ остаются основанием расчёта.'
                : 'НЦС используется как верхнеуровневая сверка. Итоговые строки СБЦ остаются основанием расчёта.',
            'formula_lines' => [
                'СМР = площадь ' . $this->decimal($area, 2) . ' м2 x ' . $this->money($baseRate) . ' руб./м2 x Kвид ' . $this->decimal($workCoeff, 2) . ' x Kэтаж ' . $this->decimal($floorCoeff, 2) . ' x Kрег ' . $this->decimal($regionCoeff, 2) . ' x Kпрог ' . $this->decimal($deflatorCoeff, 4) . ' = ' . $this->money($smrBenchmark / 1000) . ' тыс. руб.',
                'ПИР полный = СМР x ' . $this->decimal($pirRate * 100, 1) . '% = ' . $this->money($fullDesignCost / 1000) . ' тыс. руб.',
                'ПИР стадии = ПИР полный x ' . $this->decimal($stagePercent, 2) . '% = ' . $this->money($stageDesignCostThousand) . ' тыс. руб.',
            ],
        ]);
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

    private function floorCoeff(float $floors): float
    {
        if ($floors > 16) {
            return 1.15;
        }
        if ($floors > 9) {
            return 1.05;
        }

        return 1.0;
    }

    private function pirRate(float $costSmrRub): float
    {
        $mln = $costSmrRub / 1000000;
        if ($mln < 50) {
            return 0.12;
        }
        if ($mln < 200) {
            return 0.08;
        }
        if ($mln < 500) {
            return 0.065;
        }
        if ($mln < 1000) {
            return 0.055;
        }

        return 0.045;
    }

    private function positiveNumber(mixed $value, float $default): float
    {
        $number = (float) $value;

        return $number > 0 ? $number : $default;
    }

    private function money(float $value): string
    {
        return number_format($value, 0, '.', ' ');
    }

    private function decimal(float $value, int $precision): string
    {
        $formatted = number_format($value, $precision, '.', ' ');

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}
