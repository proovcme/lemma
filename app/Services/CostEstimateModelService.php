<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class CostEstimateModelService
{
    public const FACTOR_DEFAULTS = [
        'complexity' => 1.0,
        'typicality' => 1.0,
        'bim' => 1.0,
        'urgency' => 1.0,
        'input_quality' => 1.0,
    ];

    public function suggestHours(PDO $pdo, array $context): array
    {
        $department = trim((string) ($context['department_code'] ?? ''));
        $sectionCode = trim((string) ($context['section_code'] ?? ''));
        $workTitle = trim((string) ($context['work_title'] ?? ''));
        $quantity = max(1.0, (float) ($context['quantity'] ?? 1));
        $coeff = $this->factorCoeff($context);

        $where = ['le.status = "director_approved"', 'COALESCE(le.director_hours, 0) > 0'];
        $params = [];
        if ($department !== '') {
            $where[] = 'COALESCE(le.department_code, executor.department, "") = ?';
            $params[] = $department;
        }
        if ($sectionCode !== '') {
            $where[] = 'COALESCE(s.code, "") = ?';
            $params[] = $sectionCode;
        }
        if ($workTitle !== '') {
            $where[] = 'LOWER(COALESCE(le.work_title, "")) LIKE ?';
            $params[] = '%' . mb_strtolower(mb_substr($workTitle, 0, 24)) . '%';
        }

        $stmt = $pdo->prepare('
            SELECT AVG(le.director_hours / CASE WHEN COALESCE(le.model_quantity, 0) > 0 THEN le.model_quantity ELSE 1 END) AS avg_hours,
                   COUNT(*) AS sample_count
            FROM project_labor_estimates le
            INNER JOIN project_sections s ON s.id = le.section_id
            INNER JOIN users executor ON executor.id = le.executor_id
            WHERE ' . implode(' AND ', $where) . '
        ');
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];
        $sampleCount = (int) ($row['sample_count'] ?? 0);
        $avgHours = (float) ($row['avg_hours'] ?? 0);
        if ($sampleCount > 0 && $avgHours > 0) {
            return [
                'hours' => round($avgHours * $quantity * $coeff, 2),
                'basis' => 'Статистическая подсказка по ' . $sampleCount . ' утвержденным строкам с учетом коэффициентов.',
            ];
        }

        $defaultLabor = (float) ($context['sbc_default_labor_hours'] ?? 0);
        if ($defaultLabor > 0) {
            return [
                'hours' => round($defaultLabor * $quantity * $coeff, 2),
                'basis' => 'Fallback от встроенной трудоемкости пункта СБЦ с учетом коэффициентов.',
            ];
        }

        return [
            'hours' => 0.0,
            'basis' => 'Недостаточно утвержденной истории и нет трудоемкости СБЦ. Заполните часы вручную.',
        ];
    }

    public function sbcCost(SbcCatalogService $catalog, ?array $item, ?array $index, array $context): array
    {
        if (!$item) {
            return ['cost' => 0.0, 'basis' => '', 'snapshot' => null];
        }

        $quantity = max(0.0, (float) ($context['quantity'] ?? 1));
        $stagePercent = max(0.0, (float) ($context['stage_percent'] ?? 100));
        $adjustment = max(0.0, (float) ($context['adjustment_coeff'] ?? 1));
        $indexValue = $index ? max(0.0, (float) ($index['index_value'] ?? 1)) : 1.0;
        $base = $catalog->baseCost($item, $quantity, (float) ($item['base_price'] ?? 0));
        $cost = round($base * $stagePercent / 100 * $indexValue * $adjustment, 2);
        $basis = $catalog->justification($item, [
            'quantity' => $quantity,
            'stage_percent' => $stagePercent,
            'deflator_coeff' => $indexValue,
            'adjustment_coeff' => $adjustment,
            'base_cost' => $base,
            'planned_cost' => $cost,
        ]);
        if ($index) {
            $basis .= ' Индекс: ' . trim((string) ($index['label'] ?? $index['period_key'] ?? '')) . ' = ' . number_format($indexValue, 4, '.', ' ') . '.';
        }

        return [
            'cost' => $cost,
            'basis' => $basis,
            'snapshot' => json_encode([
                'sbc_item_id' => (int) ($item['id'] ?? 0),
                'reference' => SbcCatalogService::referenceText($item),
                'quantity' => $quantity,
                'stage_percent' => $stagePercent,
                'index_id' => $index ? (int) ($index['id'] ?? 0) : null,
                'index_value' => $indexValue,
                'adjustment_coeff' => $adjustment,
                'cost' => $cost,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function factorCoeff(array $context): float
    {
        return max(0.01, (float) ($context['complexity_coeff'] ?? 1))
            * max(0.01, (float) ($context['typicality_coeff'] ?? 1))
            * max(0.01, (float) ($context['bim_coeff'] ?? 1))
            * max(0.01, (float) ($context['urgency_coeff'] ?? 1))
            * max(0.01, (float) ($context['input_quality_coeff'] ?? 1));
    }
}
