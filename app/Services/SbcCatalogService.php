<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class SbcCatalogService
{
    private const MAX_ROWS = 20000;
    public const BUILTIN_SBCP_HOUSING_URL = 'https://meganorm.ru/Data2/1/4293817/4293817119.htm';

    private const BUILTIN_COLLECTION_CODE = 'СБЦП 81-02-03-2001';
    private const BUILTIN_COLLECTION_NAME = 'Справочник базовых цен на проектные работы в строительстве. Объекты жилищно-гражданского строительства';
    private const BUILTIN_EDITION = 'Приказ Минрегиона РФ от 28.05.2010 N 260';
    private const BUILTIN_PRICE_LEVEL = '01.01.2001';

    public const TEMPLATE_COLUMNS = [
        'ID СБЦ',
        'Код сборника',
        'Сборник',
        'Редакция',
        'Таблица',
        'Пункт',
        'Работа',
        'Показатель',
        'Базовая цена',
        'Уровень цен',
        'Трудозатраты',
        'Формула',
        'Примечание',
        'Источник',
        'Обоснование',
    ];

    /**
     * @return array{created: int, updated: int, skipped: int, items: array<int, array<string, mixed>>}
     */
    public function import(PDO $pdo, string $path, string $filename): array
    {
        return $this->importRows($pdo, $this->rows($path, $filename));
    }

    /**
     * @return array{created: int, updated: int, skipped: int, items: array<int, array<string, mixed>>}
     */
    public function importBuiltinSbcpHousing(PDO $pdo): array
    {
        $html = $this->fetch(self::BUILTIN_SBCP_HOUSING_URL);

        return $this->importRows($pdo, $this->builtinRows($html, self::BUILTIN_SBCP_HOUSING_URL));
    }

    public function importBundled(PDO $pdo, ?int $userId = null): array
    {
        $seed = require dirname(__DIR__, 2) . '/config/sbc_seed.php';
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $result = $this->importRows($pdo, $seed['items'] ?? []);
            $indexResult = $this->importBundledIndices($pdo, $userId);
            $result['indices'] = $indexResult;

            if ($startedTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $result;
    }

    public function importBundledIndices(PDO $pdo, ?int $userId = null): array
    {
        $seed = require dirname(__DIR__, 2) . '/config/sbc_seed.php';
        $result = ['created' => 0, 'updated' => 0];
        foreach (($seed['indices'] ?? []) as $row) {
            $payload = $this->indexPayload($row, $userId);
            $stmt = $pdo->prepare('SELECT id FROM sbc_indices WHERE period_key = ? LIMIT 1');
            $stmt->execute([$payload['period_key']]);
            $existingId = $stmt->fetchColumn();
            if ($existingId) {
                $pdo->prepare('
                    UPDATE sbc_indices
                    SET label = ?, index_value = ?, source_ref = ?, source_date = ?, comment = ?, is_active = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ')->execute([
                    $payload['label'],
                    $payload['index_value'],
                    $payload['source_ref'],
                    $payload['source_date'],
                    $payload['comment'],
                    $payload['is_active'],
                    $payload['updated_by'],
                    (int) $existingId,
                ]);
                $result['updated']++;
                continue;
            }

            $pdo->prepare('
                INSERT INTO sbc_indices (period_key, label, index_value, source_ref, source_date, comment, is_active, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $payload['period_key'],
                $payload['label'],
                $payload['index_value'],
                $payload['source_ref'],
                $payload['source_date'],
                $payload['comment'],
                $payload['is_active'],
                $payload['updated_by'],
            ]);
            $result['created']++;
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{created: int, updated: int, skipped: int, items: array<int, array<string, mixed>>}
     */
    public function importRows(PDO $pdo, array $rows): array
    {
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $prepared = $this->prepareStatements($pdo);

            $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'items' => []];
            foreach ($rows as $row) {
                $payload = $this->payload($row);
                if ($payload === null) {
                    $result['skipped']++;
                    continue;
                }

                $prepared['find']->execute([$payload['reference_hash']]);
                $existingId = $prepared['find']->fetchColumn();
                if ($existingId) {
                    $prepared['update']->execute([
                        $payload['collection_code'],
                        $payload['collection_name'],
                        $payload['edition'],
                        $payload['table_code'],
                        $payload['item_code'],
                        $payload['work_name'],
                        $payload['unit'],
                        $payload['base_price'],
                        $payload['price_level'],
                        $payload['default_labor_hours'],
                        $payload['formula'],
                        $payload['note'],
                        $payload['source_ref'],
                        $payload['justification_template'],
                        (int) $existingId,
                    ]);
                    $payload['id'] = (int) $existingId;
                    $result['updated']++;
                } else {
                    $prepared['insert']->execute([
                        $payload['reference_hash'],
                        $payload['collection_code'],
                        $payload['collection_name'],
                        $payload['edition'],
                        $payload['table_code'],
                        $payload['item_code'],
                        $payload['work_name'],
                        $payload['unit'],
                        $payload['base_price'],
                        $payload['price_level'],
                        $payload['default_labor_hours'],
                        $payload['formula'],
                        $payload['note'],
                        $payload['source_ref'],
                        $payload['justification_template'],
                    ]);
                    $payload['id'] = (int) $pdo->lastInsertId();
                    $result['created']++;
                }

                if (count($result['items']) < 20) {
                    $result['items'][] = $payload;
                }
            }

            if ($startedTransaction) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function stats(PDO $pdo): array
    {
        $stmt = $pdo->query('
            SELECT COUNT(*) AS total,
                   COUNT(DISTINCT collection_code) AS collections,
                   MAX(updated_at) AS last_updated
            FROM sbc_items
        ');

        return $stmt->fetch() ?: ['total' => 0, 'collections' => 0, 'last_updated' => null];
    }

    public function recent(PDO $pdo, int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $pdo->query('
            SELECT *
            FROM sbc_items
            ORDER BY updated_at DESC, id DESC
            LIMIT ' . $limit . '
        ');

        return $stmt->fetchAll();
    }

    public function options(PDO $pdo, int $limit = 1000): array
    {
        $limit = max(1, min(5000, $limit));
        $stmt = $pdo->query('
            SELECT *
            FROM sbc_items
            ORDER BY collection_code, edition, table_code, item_code, work_name
            LIMIT ' . $limit . '
        ');

        return array_map(fn (array $item): array => [
            'value' => (string) $item['id'],
            'label' => self::label($item),
        ], $stmt->fetchAll());
    }

    public function find(PDO $pdo, ?int $id): ?array
    {
        if (!$id) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM sbc_items WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function indices(PDO $pdo, bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM sbc_indices';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY period_key DESC, id DESC';

        return $pdo->query($sql)->fetchAll();
    }

    public function indexById(PDO $pdo, ?int $id): ?array
    {
        if (!$id) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM sbc_indices WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function saveIndex(PDO $pdo, array $source, ?int $userId = null): int
    {
        $payload = $this->indexPayload($source, $userId);
        $id = (int) ($source['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('
                UPDATE sbc_indices
                SET period_key = ?, label = ?, index_value = ?, source_ref = ?, source_date = ?, comment = ?, is_active = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ')->execute([
                $payload['period_key'],
                $payload['label'],
                $payload['index_value'],
                $payload['source_ref'],
                $payload['source_date'],
                $payload['comment'],
                $payload['is_active'],
                $payload['updated_by'],
                $id,
            ]);

            return $id;
        }

        $pdo->prepare('
            INSERT INTO sbc_indices (period_key, label, index_value, source_ref, source_date, comment, is_active, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $payload['period_key'],
            $payload['label'],
            $payload['index_value'],
            $payload['source_ref'],
            $payload['source_date'],
            $payload['comment'],
            $payload['is_active'],
            $payload['updated_by'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function findForCostSource(PDO $pdo, array $source): ?array
    {
        $id = (int) ($source['sbc_item_id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT * FROM sbc_items WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            return $stmt->fetch() ?: null;
        }

        return null;
    }

    public static function label(array $item): string
    {
        $reference = trim(implode(' ', array_filter([
            (string) ($item['collection_code'] ?? ''),
            (string) ($item['edition'] ?? ''),
            ($item['table_code'] ?? '') !== '' ? 'табл. ' . $item['table_code'] : '',
            ($item['item_code'] ?? '') !== '' ? 'п. ' . $item['item_code'] : '',
        ])));

        $workName = trim((string) ($item['work_name'] ?? ''));
        $basePrice = number_format((float) ($item['base_price'] ?? 0), 2, '.', ' ');
        $prefix = $reference !== '' ? $reference . ' - ' : '';

        return '#' . (int) ($item['id'] ?? 0) . ' · ' . $prefix . $workName . ' · ' . $basePrice;
    }

    public static function labelFromCostRow(array $row): string
    {
        if (empty($row['sbc_item_id'])) {
            return '';
        }

        return self::label([
            'id' => $row['sbc_item_id'],
            'collection_code' => $row['sbc_ref_collection_code'] ?? '',
            'edition' => $row['sbc_ref_edition'] ?? '',
            'table_code' => $row['sbc_ref_table_code'] ?? '',
            'item_code' => $row['sbc_ref_item_code'] ?? '',
            'work_name' => $row['sbc_ref_work_name'] ?? '',
            'base_price' => $row['sbc_ref_base_price'] ?? 0,
        ]);
    }

    public function baseCost(array $item, float $quantity, float $fallbackBasePrice): float
    {
        $formula = (string) ($item['formula'] ?? '');
        $a = $this->formulaValue($formula, 'a');
        $b = $this->formulaValue($formula, 'b');
        if ($a !== null && $b !== null) {
            return max(0.0, $a + $b * $quantity);
        }

        if ($a === null && $b !== null && preg_match('/\bC\s*=\s*b\s*\*?\s*x\b/i', $formula)) {
            return max(0.0, $b * $quantity);
        }

        return max(0.0, $fallbackBasePrice * $quantity);
    }

    public function justification(array $item, array $context = []): string
    {
        $parts = [];
        $reference = trim(implode(', ', array_filter([
            (string) ($item['collection_name'] ?? ''),
            ($item['collection_code'] ?? '') !== '' ? 'код ' . $item['collection_code'] : '',
            ($item['edition'] ?? '') !== '' ? 'ред. ' . $item['edition'] : '',
            ($item['table_code'] ?? '') !== '' ? 'табл. ' . $item['table_code'] : '',
            ($item['item_code'] ?? '') !== '' ? 'п. ' . $item['item_code'] : '',
        ])));
        if ($reference !== '') {
            $parts[] = 'СБЦ: ' . $reference . '.';
        }
        if (!empty($item['work_name'])) {
            $parts[] = 'Работа: ' . trim((string) $item['work_name']) . '.';
        }
        if (!empty($item['unit'])) {
            $parts[] = 'Показатель: ' . trim((string) $item['unit']) . '.';
        }
        $parts[] = 'Базовая цена: ' . number_format((float) ($item['base_price'] ?? 0), 2, '.', ' ') . ' тыс. руб.'
            . (!empty($item['price_level']) ? ' Уровень цен: ' . trim((string) $item['price_level']) . '.' : '');
        if (!empty($item['justification_template'])) {
            $parts[] = trim((string) $item['justification_template']);
        }
        if (!empty($item['formula'])) {
            $parts[] = 'Формула/условие: ' . trim((string) $item['formula']) . '.';
        }
        if (!empty($item['note'])) {
            $parts[] = 'Примечание: ' . trim((string) $item['note']) . '.';
        }
        if (!empty($item['source_ref'])) {
            $parts[] = 'Источник: ' . trim((string) $item['source_ref']) . '.';
        }
        if ($context !== []) {
            $parts[] = 'Расчёт: ' . $this->calculationText($context) . '.';
        }

        return trim(implode(' ', array_filter($parts)));
    }

    public static function referenceText(array $item): string
    {
        return trim(implode(' ', array_filter([
            (string) ($item['collection_code'] ?? ''),
            ($item['table_code'] ?? '') !== '' ? 'табл. ' . $item['table_code'] : '',
            ($item['item_code'] ?? '') !== '' ? 'п. ' . $item['item_code'] : '',
        ])));
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function builtinRows(string $html, string $sourceUrl): array
    {
        $rows = [];
        if (!preg_match_all('/<table\b[\s\S]*?<\/table>/iu', $html, $tables, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException('В источнике СБЦ не найдены табличные данные.');
        }

        foreach ($tables[0] as $tableMatch) {
            [$tableHtml, $offset] = $tableMatch;
            $tableTitle = $this->builtinTableTitle($html, (int) $offset);
            if ($tableTitle === null) {
                continue;
            }
            [$tableCode, $tableName] = $tableTitle;
            $matrix = $this->htmlTableRows($tableHtml);
            $tableText = implode(' ', array_map(static fn (array $row): string => implode(' ', $row), $matrix));
            if (!str_contains($tableText, 'Постоянные величины базовой цены')) {
                continue;
            }

            $group = '';
            $lastUnit = '';
            foreach ($matrix as $cells) {
                $cells = array_map(fn (string $value): string => $this->cleanBuiltinText($value), $cells);
                if (implode('', $cells) === '') {
                    continue;
                }
                if (count($cells) < 2) {
                    continue;
                }
                if ($this->isBuiltinNumberedHeader($cells)) {
                    continue;
                }

                $itemCode = $cells[0] ?? '';
                $workName = $cells[1] ?? '';
                $unit = $cells[2] ?? '';
                $baseA = $cells[3] ?? '';
                $baseB = $cells[4] ?? '';

                if (!$this->isBuiltinItemCode($itemCode)) {
                    if ($workName !== '' && !$this->isNumericText($baseA) && !$this->isNumericText($baseB)) {
                        $group = $workName;
                        if ($unit !== '' && $unit !== '«') {
                            $lastUnit = $unit;
                        }
                    }
                    continue;
                }

                if ($workName === '' || (!$this->isNumericText($baseA) && !$this->isNumericText($baseB))) {
                    continue;
                }

                if ($unit === '' || $unit === '«') {
                    $unit = $lastUnit;
                } else {
                    $lastUnit = $unit;
                }

                $a = $this->decimalText($baseA);
                $b = $this->decimalText($baseB);
                $formula = '';
                $basePrice = $a !== '' ? $a : $b;
                if ($a !== '' && $b !== '') {
                    $formula = 'C=a+b*x; a=' . $a . '; b=' . $b;
                } elseif ($a === '' && $b !== '') {
                    $formula = 'C=b*x; b=' . $b;
                } elseif ($a !== '') {
                    $formula = 'C=a*x; a=' . $a;
                }

                $title = $this->builtinWorkTitle($group, $workName);
                $sourceRef = $sourceUrl . ' table ' . $tableCode . ' item ' . $itemCode;
                $rows[] = [
                    'id' => 'SBCP-81-02-03-2001-T' . $tableCode . '-' . $itemCode,
                    'collection_code' => self::BUILTIN_COLLECTION_CODE,
                    'collection_name' => self::BUILTIN_COLLECTION_NAME,
                    'edition' => self::BUILTIN_EDITION,
                    'table_code' => $tableCode,
                    'item_code' => $itemCode,
                    'work_name' => $title,
                    'unit' => $unit,
                    'base_price' => $basePrice,
                    'price_level' => self::BUILTIN_PRICE_LEVEL,
                    'default_labor_hours' => '0',
                    'formula' => $formula,
                    'note' => trim('Таблица ' . $tableCode . '. ' . $tableName . '. x - основной показатель объекта. Трудозатраты СБЦ не нормируются и заполняются отдельно.'),
                    'source_ref' => $sourceRef,
                    'justification_template' => trim(self::BUILTIN_COLLECTION_CODE . ', табл. ' . $tableCode . ', п. ' . $itemCode . '. ' . ($formula !== '' ? 'Базовая цена определяется по формуле ' . $formula . '.' : 'Базовая цена принята по таблице.')),
                ];
                if (count($rows) >= self::MAX_ROWS) {
                    throw new RuntimeException('Источник СБЦ содержит слишком много строк.');
                }
            }
        }

        if ($rows === []) {
            throw new RuntimeException('Не удалось извлечь позиции СБЦ из источника.');
        }

        return $rows;
    }

    private function indexPayload(array $row, ?int $userId): array
    {
        $period = trim((string) ($row['period_key'] ?? ''));
        if ($period === '') {
            throw new RuntimeException('Укажите квартал индекса СБЦ/ПИР.');
        }

        return [
            'period_key' => mb_substr($period, 0, 20),
            'label' => trim((string) ($row['label'] ?? $period)),
            'index_value' => max(0.0, (float) str_replace(',', '.', (string) ($row['index_value'] ?? 1))),
            'source_ref' => trim((string) ($row['source_ref'] ?? '')),
            'source_date' => $this->dateOrNull($row['source_date'] ?? null),
            'comment' => trim((string) ($row['comment'] ?? '')),
            'is_active' => !empty($row['is_active']) ? 1 : 0,
            'updated_by' => $userId,
        ];
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function builtinTableTitle(string $html, int $offset): ?array
    {
        $context = $this->stripHtml(substr($html, max(0, $offset - 2500), 2500));
        if (!preg_match_all('/Таблица\s*№\s*([0-9]+[а-я]?)\.\s*(.+?)(?=№\s*п\/п|$)/ui', $context, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $match = end($matches);
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($match[2] ?? '')) ?? '');

        return [(string) $match[1], $name];
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function htmlTableRows(string $tableHtml): array
    {
        if (!preg_match_all('/<tr\b[\s\S]*?<\/tr>/iu', $tableHtml, $rowMatches)) {
            return [];
        }

        $rows = [];
        foreach ($rowMatches[0] as $rowHtml) {
            if (!preg_match_all('/<td\b[\s\S]*?<\/td>/iu', $rowHtml, $cellMatches)) {
                continue;
            }
            $cells = array_map(fn (string $cell): string => $this->stripHtml($cell), $cellMatches[0]);
            if (implode('', $cells) !== '') {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    private function builtinWorkTitle(string $group, string $workName): string
    {
        $workName = trim($workName);
        $group = trim($group);
        if ($group === '' || str_contains($workName, $group)) {
            return $workName;
        }
        if (preg_match('/^(до|от|свыше|малой|средней|нормальной|большой)\b/ui', $workName)) {
            return rtrim($group, ':') . ': ' . $workName;
        }

        return $workName;
    }

    private function isBuiltinItemCode(string $value): bool
    {
        return (bool) preg_match('/^\d+(?:\.\d+)?\.?$/u', trim($value));
    }

    private function cleanBuiltinText(string $value): string
    {
        $value = str_replace(['&times;', '×'], 'x', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value);
    }

    private function isBuiltinNumberedHeader(array $cells): bool
    {
        return ($cells[0] ?? '') === '1'
            && ($cells[1] ?? '') === '2'
            && ($cells[2] ?? '') === '3'
            && ($cells[3] ?? '') === '4'
            && ($cells[4] ?? '') === '5';
    }

    private function stripHtml(string $html): string
    {
        $html = preg_replace('/<sup\b[^>]*>(.*?)<\/sup>/isu', '^{$1}', $html) ?? $html;
        $html = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = str_replace(["\xEF\xBB\xBF", "\xc2\xa0"], ' ', $html);
        $html = preg_replace('/\s+/u', ' ', $html) ?? '';

        return trim($html);
    }

    private function isNumericText(string $value): bool
    {
        return $this->decimalText($value) !== '';
    }

    private function decimalText(string $value): string
    {
        $value = trim(str_replace(["\xc2\xa0", ' '], '', $value));
        if ($value === '' || $value === '-' || $value === '�') {
            return '';
        }
        $value = str_replace(',', '.', $value);
        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            return '';
        }

        return $value;
    }

    private function fetch(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 25,
                'header' => "User-Agent: Locia/1.0\r\n",
            ],
            'https' => [
                'timeout' => 25,
                'header' => "User-Agent: Locia/1.0\r\n",
            ],
        ]);
        $content = @file_get_contents($url, false, $context);
        if (!is_string($content) || $content === '') {
            throw new RuntimeException('Не удалось загрузить источник СБЦ: ' . $url);
        }

        return $this->decodeText($content);
    }

    private function rows(string $path, string $filename): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, ['xlsx', 'xls'], true)) {
            return $this->spreadsheetRows($path);
        }

        return $this->csvRows($path);
    }

    private function spreadsheetRows(string $path): array
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new RuntimeException('Для XLS/XLSX импорта не установлена библиотека PhpSpreadsheet.');
        }

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        } catch (\Throwable $e) {
            throw new RuntimeException('Не удалось прочитать XLS/XLSX файл СБЦ: ' . $e->getMessage(), previous: $e);
        }
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        return $this->rowsFromMatrix(array_map(static fn (array $row): array => array_values($row), $rows));
    }

    private function csvRows(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('Не удалось открыть файл СБЦ.');
        }

        $firstLine = (string) fgets($handle);
        $firstLine = $this->decodeText($firstLine);
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);

        $matrix = [];
        while (($values = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $matrix[] = array_map(fn (mixed $value): string => $this->decodeText((string) $value), $values);
            if (count($matrix) > self::MAX_ROWS + 1) {
                fclose($handle);
                throw new RuntimeException('Файл СБЦ содержит слишком много строк.');
            }
        }

        fclose($handle);

        return $this->rowsFromMatrix($matrix);
    }

    private function rowsFromMatrix(array $matrix): array
    {
        $header = null;
        foreach ($matrix as $row) {
            $values = array_map(fn (mixed $value): string => $this->text($value), $row);
            if (implode('', $values) === '') {
                continue;
            }
            $header = $values;
            break;
        }
        if ($header === null) {
            return [];
        }

        $keys = $this->headerKeys($header);
        $rows = [];
        $headerSeen = false;
        foreach ($matrix as $row) {
            $values = array_map(fn (mixed $value): string => $this->text($value), $row);
            if (implode('', $values) === '') {
                continue;
            }
            if (!$headerSeen) {
                $headerSeen = true;
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw new RuntimeException('Файл СБЦ содержит слишком много строк.');
            }

            $assoc = [];
            foreach ($values as $index => $value) {
                $key = $keys[$index] ?? null;
                if ($key !== null) {
                    $assoc[$key] = $value;
                }
            }
            $rows[] = $assoc;
        }

        return $rows;
    }

    private function headerKeys(array $headers): array
    {
        $aliases = [
            'id' => ['id', 'id сбц', 'сбц id'],
            'collection_code' => ['код сборника', 'шифр сборника', 'collection_code', 'collection code'],
            'collection_name' => ['сборник', 'наименование сборника', 'collection', 'collection_name', 'сбц'],
            'edition' => ['редакция', 'год', 'издание', 'edition'],
            'table_code' => ['таблица', 'табл', 'table', 'table_code'],
            'item_code' => ['пункт', 'п', 'позиция', 'item', 'item_code'],
            'work_name' => ['работа', 'наименование работы', 'наименование', 'вид работ', 'work_name'],
            'unit' => ['показатель', 'единица', 'ед изм', 'ед. изм.', 'unit', 'измеритель'],
            'base_price' => ['базовая цена', 'цена', 'стоимость', 'base_price', 'тыс. руб.'],
            'price_level' => ['уровень цен', 'цены', 'price_level', 'база цен'],
            'default_labor_hours' => ['трудозатраты', 'чел-ч', 'нормочасы', 'labor_hours', 'default_labor_hours'],
            'formula' => ['формула', 'расчет', 'расчёт', 'formula'],
            'note' => ['примечание', 'коэффициенты', 'note'],
            'source_ref' => ['источник', 'страница', 'лист', 'source', 'source_ref'],
            'justification_template' => ['обоснование', 'шаблон обоснования', 'justification', 'justification_template'],
        ];
        $map = [];
        foreach ($aliases as $key => $values) {
            foreach ($values as $value) {
                $map[$this->normalizeHeader($value)] = $key;
            }
        }

        return array_map(fn (string $header): ?string => $map[$this->normalizeHeader($header)] ?? null, $headers);
    }

    private function payload(array $row): ?array
    {
        $collectionName = $this->text($row['collection_name'] ?? '');
        $collectionCode = $this->text($row['collection_code'] ?? '');
        if ($collectionName === '' && $collectionCode !== '') {
            $collectionName = $collectionCode;
        }
        $workName = $this->text($row['work_name'] ?? '');
        if ($collectionName === '' || $workName === '') {
            return null;
        }

        $payload = [
            'collection_code' => $collectionCode,
            'collection_name' => $collectionName,
            'edition' => $this->text($row['edition'] ?? ''),
            'table_code' => $this->text($row['table_code'] ?? ''),
            'item_code' => $this->text($row['item_code'] ?? ''),
            'work_name' => $workName,
            'unit' => $this->text($row['unit'] ?? ''),
            'base_price' => $this->decimal($row['base_price'] ?? 0),
            'price_level' => $this->text($row['price_level'] ?? ''),
            'default_labor_hours' => $this->decimal($row['default_labor_hours'] ?? 0),
            'formula' => $this->text($row['formula'] ?? ''),
            'note' => $this->text($row['note'] ?? ''),
            'source_ref' => $this->text($row['source_ref'] ?? ''),
            'justification_template' => $this->text($row['justification_template'] ?? ''),
        ];
        $payload['reference_hash'] = hash('sha256', implode('|', [
            mb_strtolower($payload['collection_code'], 'UTF-8'),
            mb_strtolower($payload['collection_name'], 'UTF-8'),
            mb_strtolower($payload['edition'], 'UTF-8'),
            mb_strtolower($payload['table_code'], 'UTF-8'),
            mb_strtolower($payload['item_code'], 'UTF-8'),
            mb_strtolower($payload['work_name'], 'UTF-8'),
        ]));

        return $payload;
    }

    private function prepareStatements(PDO $pdo): array
    {
        return [
            'find' => $pdo->prepare('SELECT id FROM sbc_items WHERE reference_hash = ? LIMIT 1'),
            'insert' => $pdo->prepare('
                INSERT INTO sbc_items (
                    reference_hash, collection_code, collection_name, edition, table_code, item_code,
                    work_name, unit, base_price, price_level, default_labor_hours, formula, note,
                    source_ref, justification_template
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            '),
            'update' => $pdo->prepare('
                UPDATE sbc_items
                SET collection_code = ?,
                    collection_name = ?,
                    edition = ?,
                    table_code = ?,
                    item_code = ?,
                    work_name = ?,
                    unit = ?,
                    base_price = ?,
                    price_level = ?,
                    default_labor_hours = ?,
                    formula = ?,
                    note = ?,
                    source_ref = ?,
                    justification_template = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            '),
        ];
    }

    private function calculationText(array $context): string
    {
        $basePrice = number_format((float) ($context['base_price'] ?? 0), 2, '.', ' ');
        $baseCost = number_format((float) ($context['normative_base_cost'] ?? ((float) ($context['base_price'] ?? 0) * (float) ($context['quantity'] ?? 1))), 2, '.', ' ');
        $quantity = number_format((float) ($context['quantity'] ?? 1), 3, '.', ' ');
        $stage = number_format((float) ($context['stage_percent'] ?? 100), 2, '.', ' ');
        $complexity = number_format((float) ($context['complexity_coeff'] ?? 1), 4, '.', ' ');
        $deflator = number_format((float) ($context['deflator_coeff'] ?? 1), 4, '.', ' ');
        $adjustment = number_format((float) ($context['adjustment_coeff'] ?? 1), 4, '.', ' ');
        $cost = number_format((float) ($context['planned_cost'] ?? 0), 2, '.', ' ');
        $labor = (float) ($context['labor_hours'] ?? 0);
        $laborText = $labor > 0 ? ' Трудозатраты: ' . number_format($labor, 2, '.', ' ') . ' чел-ч.' : '';
        $formulaText = trim((string) ($context['formula'] ?? ''));
        $baseText = $formulaText !== ''
            ? "нормативная база {$baseCost} тыс. руб. по формуле {$formulaText}"
            : "{$basePrice} x {$quantity} = {$baseCost} тыс. руб.";

        return "{$baseText}; {$stage}% x {$complexity} x {$deflator} x {$adjustment} = {$cost} тыс. руб.{$laborText}";
    }

    private function formulaValue(string $formula, string $name): ?float
    {
        if (!preg_match('/(?:^|[;\s])' . preg_quote($name, '/') . '\s*=\s*([-+]?[0-9]+(?:[.,][0-9]+)?)/iu', $formula, $match)) {
            return null;
        }

        return (float) str_replace(',', '.', $match[1]);
    }

    private function decimal(mixed $value): float
    {
        $text = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], $this->text($value));
        $text = preg_replace('/[^0-9.\-]+/', '', $text) ?? '';
        if ($text === '' || $text === '-' || !is_numeric($text)) {
            return 0.0;
        }

        return (float) $text;
    }

    private function normalizeHeader(string $value): string
    {
        $value = str_replace("\xEF\xBB\xBF", '', $this->text($value));
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value);
    }

    private function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function decodeText(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'Windows-1251');
    }
}
