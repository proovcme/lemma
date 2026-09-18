<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class CostPlanService
{
    public const LABOR_METHODS = [
        'manual' => 'Ручная оценка',
        'executor' => 'Оценка исполнителя',
        'gip' => 'Оценка ГИПа',
        'adjustment' => 'Корректировка',
        'directive' => 'Директива',
        'norm' => 'По нормам',
        'productivity' => 'По средней выработке',
    ];

    public function buildItem(PDO $pdo, array $source): array
    {
        $sbcService = new SbcCatalogService();
        $sbcItem = $sbcService->findForCostSource($pdo, $source);

        $workName = trim((string) ($source['work_name'] ?? '')) ?: (string) ($sbcItem['work_name'] ?? '');
        $justification = trim((string) ($source['justification'] ?? ''));
        if ($workName === '') {
            throw new \InvalidArgumentException('Для позиции плана затрат укажите работу.');
        }

        $quantity = $this->decimalValue($source['quantity'] ?? 1, 1.0);
        $basePrice = $this->sourceHasValue($source, 'base_price') ? $this->decimalValue($source['base_price']) : (float) ($sbcItem['base_price'] ?? 0);
        $stagePercent = $this->decimalValue($source['stage_percent'] ?? 100, 100.0);
        $complexityCoeff = $this->coefficientValue($source['complexity_coeff'] ?? 1);
        $deflatorCoeff = $this->coefficientValue($source['deflator_coeff'] ?? 1);
        $adjustmentCoeff = $this->coefficientValue($source['adjustment_coeff'] ?? 1);
        $laborModel = $this->laborModel($source, $sbcItem, $quantity);
        $laborHours = $laborModel['labor_hours'];
        $normativeBaseCost = $sbcItem && !$this->sourceHasValue($source, 'base_price')
            ? $sbcService->baseCost($sbcItem, $quantity, $basePrice)
            : max(0.0, $basePrice * $quantity);
        $plannedCost = round($normativeBaseCost * ($stagePercent / 100) * $complexityCoeff * $deflatorCoeff * $adjustmentCoeff, 2);

        if ($justification === '' && $sbcItem) {
            $justification = $sbcService->justification($sbcItem, [
                'labor_hours' => $laborHours,
                'quantity' => $quantity,
                'base_price' => $basePrice,
                'normative_base_cost' => $normativeBaseCost,
                'stage_percent' => $stagePercent,
                'complexity_coeff' => $complexityCoeff,
                'deflator_coeff' => $deflatorCoeff,
                'adjustment_coeff' => $adjustmentCoeff,
                'planned_cost' => $plannedCost,
                'formula' => (string) ($sbcItem['formula'] ?? ''),
            ]);
        }
        if ($justification === '') {
            throw new \InvalidArgumentException('Для позиции плана затрат обязательно обоснование.');
        }

        return [
            'num' => trim((string) ($source['num'] ?? '')),
            'section_code' => trim((string) ($source['section_code'] ?? '')),
            'sbc_item_id' => $sbcItem['id'] ?? null,
            'sbc_collection' => trim((string) ($source['sbc_collection'] ?? '')) ?: $this->sbcCollectionText($sbcItem),
            'sbc_table' => trim((string) ($source['sbc_table'] ?? '')) ?: $this->sbcTableText($sbcItem),
            'work_name' => $workName,
            'unit' => trim((string) ($source['unit'] ?? '')) ?: (string) ($sbcItem['unit'] ?? ''),
            'labor_hours' => $laborHours,
            'labor_estimate_method' => $laborModel['method'],
            'labor_executor_hours' => $laborModel['executor_hours'],
            'labor_gip_hours' => $laborModel['gip_hours'],
            'labor_adjustment_hours' => $laborModel['adjustment_hours'],
            'labor_directive_hours' => $laborModel['directive_hours'],
            'labor_norm_hours' => $laborModel['norm_hours'],
            'labor_productivity_rate' => $laborModel['productivity_rate'],
            'labor_productivity_coeff' => $laborModel['productivity_coeff'],
            'labor_basis' => $laborModel['basis'],
            'quantity' => $quantity,
            'base_price' => $basePrice,
            'stage_percent' => $stagePercent,
            'complexity_coeff' => $complexityCoeff,
            'deflator_coeff' => $deflatorCoeff,
            'adjustment_coeff' => $adjustmentCoeff,
            'planned_cost' => $plannedCost,
            'price_level' => trim((string) ($source['price_level'] ?? '')) ?: (string) (($sbcItem['price_level'] ?? '') ?: 'база СБЦ'),
            'justification' => $justification,
            'comments' => trim((string) ($source['comments'] ?? '')),
        ];
    }

    public function totals(array $rows): array
    {
        $total = 0.0;
        $baseTotal = 0.0;
        $laborTotal = 0.0;
        foreach ($rows as $row) {
            $factor = max(0.000001, ((float) ($row['stage_percent'] ?? 100) / 100)
                * (float) ($row['complexity_coeff'] ?? 1)
                * (float) ($row['deflator_coeff'] ?? 1)
                * (float) ($row['adjustment_coeff'] ?? 1));
            $plannedCost = (float) ($row['planned_cost'] ?? 0);
            $total += $plannedCost;
            $baseTotal += $plannedCost / $factor;
            $laborTotal += (float) ($row['labor_hours'] ?? 0);
        }

        return [
            'items' => count($rows),
            'labor_hours' => $laborTotal,
            'base_cost' => $baseTotal,
            'planned_cost' => $total,
            'avg_index' => $baseTotal > 0 ? $total / $baseTotal : 0,
        ];
    }

    private function decimalValue(mixed $value, float $default = 0.0): float
    {
        $text = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], trim((string) $value));
        if ($text === '') {
            return $default;
        }

        return is_numeric($text) ? (float) $text : $default;
    }

    private function coefficientValue(mixed $value): float
    {
        $number = $this->decimalValue($value, 1.0);

        return $number > 0 ? $number : 1.0;
    }

    /**
     * @return array<string, mixed>
     */
    private function laborModel(array $source, ?array $sbcItem, float $quantity): array
    {
        $manualHours = $this->sourceHasValue($source, 'labor_hours') ? $this->decimalValue($source['labor_hours']) : null;
        $executorHours = $this->nullableDecimal($source['labor_executor_hours'] ?? null);
        $gipHours = $this->nullableDecimal($source['labor_gip_hours'] ?? null);
        $adjustmentHours = $this->nullableDecimal($source['labor_adjustment_hours'] ?? null);
        $directiveHours = $this->nullableDecimal($source['labor_directive_hours'] ?? null);
        $normHours = $this->sourceHasValue($source, 'labor_norm_hours')
            ? $this->decimalValue($source['labor_norm_hours'])
            : (float) ($sbcItem['default_labor_hours'] ?? 0);
        $productivityRate = $this->nullableDecimal($source['labor_productivity_rate'] ?? null);
        $productivityCoeff = $this->coefficientValue($source['labor_productivity_coeff'] ?? 1);
        $productivityHours = $productivityRate !== null && $productivityRate > 0
            ? round(($quantity / $productivityRate) * 8 * $productivityCoeff, 2)
            : null;

        $method = trim((string) ($source['labor_estimate_method'] ?? ''));
        if (!array_key_exists($method, self::LABOR_METHODS)) {
            $method = $manualHours !== null ? 'manual' : ($normHours > 0 ? 'norm' : 'manual');
        }

        $selected = match ($method) {
            'executor' => $executorHours,
            'gip' => $gipHours,
            'adjustment' => $adjustmentHours,
            'directive' => $directiveHours,
            'norm' => $normHours,
            'productivity' => $productivityHours,
            default => $manualHours ?? $normHours,
        };
        $selected = max(0.0, (float) ($selected ?? 0));

        $basis = trim((string) ($source['labor_basis'] ?? ''));
        if ($basis === '') {
            $basis = $this->laborBasisText($method, $selected, [
                'quantity' => $quantity,
                'norm_hours' => $normHours,
                'productivity_rate' => $productivityRate,
                'productivity_coeff' => $productivityCoeff,
            ]);
        }

        return [
            'method' => $method,
            'labor_hours' => $selected,
            'executor_hours' => $executorHours,
            'gip_hours' => $gipHours,
            'adjustment_hours' => $adjustmentHours,
            'directive_hours' => $directiveHours,
            'norm_hours' => $normHours > 0 ? $normHours : null,
            'productivity_rate' => $productivityRate,
            'productivity_coeff' => $productivityCoeff,
            'basis' => $basis,
        ];
    }

    private function laborBasisText(string $method, float $selected, array $context): string
    {
        $hours = number_format($selected, 2, '.', ' ');
        if ($method === 'productivity') {
            if ((float) ($context['productivity_rate'] ?? 0) <= 0) {
                return 'Модель выработки не рассчитана: укажите среднюю выработку в единицах показателя на чел-день.';
            }
            $quantity = number_format((float) ($context['quantity'] ?? 0), 3, '.', ' ');
            $rate = number_format((float) ($context['productivity_rate'] ?? 0), 4, '.', ' ');
            $coeff = number_format((float) ($context['productivity_coeff'] ?? 1), 4, '.', ' ');

            return "Модель выработки: {$quantity} / {$rate} ед. на чел-день x 8 ч x K {$coeff} = {$hours} чел-ч.";
        }
        if ($method === 'norm') {
            return "Нормативная оценка: типовые трудозатраты справочника = {$hours} чел-ч.";
        }

        $label = self::LABOR_METHODS[$method] ?? self::LABOR_METHODS['manual'];

        return "Экспертная оценка: {$label} = {$hours} чел-ч.";
    }

    private function nullableDecimal(mixed $value): ?float
    {
        if (!$this->sourceHasValue(['value' => $value], 'value')) {
            return null;
        }

        return $this->decimalValue($value);
    }

    private function sourceHasValue(array $source, string $key): bool
    {
        return array_key_exists($key, $source) && trim((string) $source[$key]) !== '';
    }

    private function sbcCollectionText(?array $item): string
    {
        if (!$item) {
            return '';
        }

        return trim(implode(' ', array_filter([
            (string) ($item['collection_code'] ?? ''),
            (string) ($item['collection_name'] ?? ''),
            (string) ($item['edition'] ?? ''),
        ])));
    }

    private function sbcTableText(?array $item): string
    {
        if (!$item) {
            return '';
        }

        return trim(implode(' ', array_filter([
            ($item['table_code'] ?? '') !== '' ? 'табл. ' . $item['table_code'] : '',
            ($item['item_code'] ?? '') !== '' ? 'п. ' . $item['item_code'] : '',
        ])));
    }
}
