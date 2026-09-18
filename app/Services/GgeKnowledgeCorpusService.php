<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class GgeKnowledgeCorpusService
{
    private const CORPUS_KEY = 'gge-public-20260820';

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('Снимок корпуса ГГЭ не найден: ' . $path);
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Снимок корпуса ГГЭ содержит некорректный JSON.', 0, $e);
        }
        if (!is_array($data)) {
            throw new InvalidArgumentException('Снимок корпуса ГГЭ должен быть JSON-объектом.');
        }
        return new self($data);
    }

    /** @return array<string, int> */
    public function validate(): array
    {
        if (($this->data['schema'] ?? null) !== 1 || ($this->data['corpus_key'] ?? null) !== self::CORPUS_KEY) {
            throw new InvalidArgumentException('Неизвестная схема или ключ корпуса ГГЭ.');
        }

        $canonical = $this->rows('canonical');
        $occurrences = $this->rows('occurrences');
        $checklists = $this->rows('checklists');
        $catalog = $this->rows('checklist_catalog');
        $sources = $this->rows('sources');

        $this->assertCount($canonical, 349, 'канонических карточек');
        $this->assertCount($occurrences, 349, 'исходных вхождений');
        $this->assertCount($checklists, 132, 'пунктов чек-листа');
        $this->assertCount($catalog, 12, 'позиций каталога чек-листов');
        $this->assertCount($sources, 68, 'источников');

        $canonicalById = $this->uniqueRows($canonical, 'canonical_id', 'канонической карточки');
        $occurrencesByCanonical = [];
        foreach ($occurrences as $row) {
            $this->required($row, 'occurrence_id', 'вхождение');
            $canonicalId = $this->required($row, 'canonical_id', 'вхождение');
            $this->required($row, 'original_text', 'вхождение ' . $canonicalId);
            if (!isset($canonicalById[$canonicalId])) {
                throw new InvalidArgumentException('Вхождение ссылается на неизвестную карточку: ' . $canonicalId);
            }
            if (isset($occurrencesByCanonical[$canonicalId])) {
                throw new InvalidArgumentException('Для карточки ожидается ровно одно исходное вхождение: ' . $canonicalId);
            }
            $this->assertHttps((string) ($row['source_url'] ?? ''), 'вхождение ' . $canonicalId);
            $occurrencesByCanonical[$canonicalId] = $row;
        }

        $central = 0;
        $regional = 0;
        $reference = 0;
        foreach ($canonicalById as $canonicalId => $row) {
            $this->required($row, 'canonical_error', 'карточка ' . $canonicalId);
            $this->required($row, 'discipline', 'карточка ' . $canonicalId);
            if (!isset($occurrencesByCanonical[$canonicalId])) {
                throw new InvalidArgumentException('У карточки нет исходного вхождения: ' . $canonicalId);
            }
            $occurrence = $occurrencesByCanonical[$canonicalId];
            $evidence = trim((string) ($row['evidence_class'] ?? ''));
            if ($evidence !== trim((string) ($occurrence['evidence_class'] ?? ''))) {
                throw new InvalidArgumentException('Класс доказательства расходится у карточки: ' . $canonicalId);
            }
            if ($evidence === 'D' && str_starts_with((string) ($occurrence['source_id'] ?? ''), 'REG-')) {
                $regional++;
            } elseif ($evidence === 'D') {
                $central++;
            } else {
                $reference++;
            }
        }

        foreach ($sources as $source) {
            $this->required($source, 'source_id', 'источник');
            $this->assertHttps((string) ($source['url'] ?? ''), 'источник ' . ($source['source_id'] ?? ''));
        }
        foreach ($checklists as $item) {
            $this->required($item, 'checklist_id', 'пункт чек-листа');
            $this->required($item, 'section_no', 'пункт чек-листа');
            $this->required($item, 'item_text', 'пункт чек-листа');
            $this->assertHttps((string) ($item['source_url'] ?? ''), 'пункт ' . ($item['checklist_id'] ?? ''));
        }

        $sections = array_unique(array_map(static fn (array $row): string => trim((string) $row['section_no']), $checklists));
        $stats = [
            'canonical' => count($canonical),
            'occurrences' => count($occurrences),
            'central_direct' => $central,
            'regional_direct' => $regional,
            'reference' => $reference,
            'checklist_items' => count($checklists),
            'checklist_sections' => count($sections),
            'checklist_catalog' => count($catalog),
            'sources' => count($sources),
        ];
        $expected = ['central_direct' => 124, 'regional_direct' => 39, 'reference' => 186, 'checklist_sections' => 8];
        foreach ($expected as $name => $count) {
            if ($stats[$name] !== $count) {
                throw new InvalidArgumentException("Нарушен состав корпуса {$name}: ожидалось {$count}, получено {$stats[$name]}.");
            }
        }
        return $stats;
    }

    /**
     * Read-only verification of the committed corpus projection in a Locia database.
     *
     * @return array{error_count: int, errors: array<int, array{code: string, expected: int, actual: int}>, counts: array{folders: int, documents: int, revisions: int}}
     */
    public function audit(PDO $pdo): array
    {
        $this->validate();
        $expectedFolders = $this->expectedFolderKeys();
        $expectedDocuments = $this->expectedDocumentKeys();

        $folderKeys = $this->databaseSourceKeys($pdo, 'knowledge_folders');
        $documentKeys = $this->databaseSourceKeys($pdo, 'knowledge_documents');
        $revisionStmt = $pdo->prepare('SELECT COUNT(*) AS revision_count, COUNT(DISTINCT d.id) AS document_count
            FROM knowledge_document_revisions r
            JOIN knowledge_documents d ON d.id = r.document_id
            WHERE d.source_key LIKE ?');
        $revisionStmt->execute([self::CORPUS_KEY . ':%']);
        $revisionRow = $revisionStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $revisionCount = (int) ($revisionRow['revision_count'] ?? 0);
        $revisionedDocuments = (int) ($revisionRow['document_count'] ?? 0);

        $missingFolders = array_diff($expectedFolders, $folderKeys);
        $missingDocuments = array_diff($expectedDocuments, $documentKeys);
        $unexpectedFolders = array_diff($folderKeys, $expectedFolders);
        $unexpectedDocuments = array_diff($documentKeys, $expectedDocuments);
        $errors = [];
        foreach ([
            ['missing_folders', count($expectedFolders), count($expectedFolders) - count($missingFolders)],
            ['missing_documents', count($expectedDocuments), count($expectedDocuments) - count($missingDocuments)],
            ['unexpected_folders', 0, count($unexpectedFolders)],
            ['unexpected_documents', 0, count($unexpectedDocuments)],
            ['missing_revisions', count($expectedDocuments), $revisionedDocuments],
        ] as [$code, $expected, $actual]) {
            if ($expected !== $actual) {
                $errors[] = ['code' => $code, 'expected' => $expected, 'actual' => $actual];
            }
        }

        return [
            'error_count' => count($errors),
            'errors' => $errors,
            'counts' => [
                'folders' => count($folderKeys),
                'documents' => count($documentKeys),
                'revisions' => $revisionCount,
            ],
        ];
    }

    /** @return array{folders: int, documents: int, revisions: int, skipped: int} */
    public function seed(PDO $pdo): array
    {
        $stats = $this->validate();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        $result = ['folders' => 0, 'documents' => 0, 'revisions' => 0, 'skipped' => 0];
        try {
            $root = $this->folder($pdo, self::CORPUS_KEY . ':folder:root', null, 'Экспертиза · публичный корпус', 30, $result);
            $branches = [
                'central' => $this->folder($pdo, self::CORPUS_KEY . ':folder:central', $root, 'ГГЭ · прямые замечания', 10, $result),
                'regional' => $this->folder($pdo, self::CORPUS_KEY . ':folder:regional', $root, 'Региональные госэкспертизы', 20, $result),
                'reference' => $this->folder($pdo, self::CORPUS_KEY . ':folder:reference', $root, 'Справочные и вторичные материалы', 30, $result),
                'checklists' => $this->folder($pdo, self::CORPUS_KEY . ':folder:checklists', $root, 'Чек-листы', 40, $result),
            ];

            $occurrences = [];
            foreach ($this->rows('occurrences') as $row) {
                $occurrences[(string) $row['canonical_id']] = $row;
            }

            $disciplines = [];
            foreach ($this->rows('canonical') as $index => $card) {
                $canonicalId = (string) $card['canonical_id'];
                $occurrence = $occurrences[$canonicalId];
                $category = $this->category($card, $occurrence);
                $discipline = trim((string) $card['discipline']);
                $folderKey = $category . '|' . $discipline;
                if (!isset($disciplines[$folderKey])) {
                    $disciplineKey = self::CORPUS_KEY . ':folder:' . $category . ':discipline:' . substr(hash('sha256', $discipline), 0, 16);
                    $disciplines[$folderKey] = $this->folder(
                        $pdo,
                        $disciplineKey,
                        $branches[$category],
                        $discipline,
                        100 + count($disciplines),
                        $result
                    );
                }
                $this->document(
                    $pdo,
                    self::CORPUS_KEY . ':canonical:' . $canonicalId,
                    $disciplines[$folderKey],
                    $this->clip($canonicalId . ' · ' . (string) $card['canonical_error'], 240),
                    $this->canonicalSummary($card, $category),
                    $this->canonicalBody($card, $occurrence, $category),
                    100 + $index,
                    $result
                );
            }

            $checklistGroups = [];
            foreach ($this->rows('checklists') as $item) {
                $checklistGroups[(string) $item['section_no']][] = $item;
            }
            uksort($checklistGroups, 'strnatcmp');
            foreach ($checklistGroups as $sectionNo => $items) {
                $first = $items[0];
                $this->document(
                    $pdo,
                    self::CORPUS_KEY . ':checklist-section:' . $sectionNo,
                    $branches['checklists'],
                    $this->clip((string) $first['section_name'], 240),
                    'Официальный чек-лист · раздел ' . $sectionNo . ' · ' . count($items) . ' пунктов. Не является перечнем типовых замечаний.',
                    $this->checklistBody($items),
                    100 + (int) $sectionNo,
                    $result
                );
            }

            $this->document(
                $pdo,
                self::CORPUS_KEY . ':checklist-catalog',
                $branches['checklists'],
                'Каталог 12 чек-листов Главгосэкспертизы',
                'Подтверждённый публичный каталог; наличие позиции не означает, что её полный текст извлечён.',
                $this->catalogBody(),
                10,
                $result
            );
            $this->document(
                $pdo,
                self::CORPUS_KEY . ':methodology',
                $root,
                'Как читать публичный корпус экспертизы',
                'Происхождение, доказательственные классы, охват и ограничения публичной реконструкции.',
                $this->methodologyBody($stats),
                1,
                $result
            );

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $result;
    }

    /** @param array<string, int> $result */
    private function folder(PDO $pdo, string $sourceKey, ?int $parentId, string $name, int $sortOrder, array &$result): int
    {
        $find = $pdo->prepare('SELECT id FROM knowledge_folders WHERE source_key = ? LIMIT 1');
        $find->execute([$sourceKey]);
        $existing = $find->fetchColumn();
        if ($existing !== false) {
            $result['skipped']++;
            return (int) $existing;
        }
        $insert = $pdo->prepare('INSERT INTO knowledge_folders (source_key, parent_id, name, sort_order) VALUES (?, ?, ?, ?)');
        $insert->execute([$sourceKey, $parentId, $name, $sortOrder]);
        $result['folders']++;
        return (int) $pdo->lastInsertId();
    }

    /** @param array<string, int> $result */
    private function document(
        PDO $pdo,
        string $sourceKey,
        int $folderId,
        string $title,
        string $summary,
        string $bodyHtml,
        int $sortOrder,
        array &$result
    ): void {
        $find = $pdo->prepare('SELECT id FROM knowledge_documents WHERE source_key = ? LIMIT 1');
        $find->execute([$sourceKey]);
        if ($find->fetchColumn() !== false) {
            $result['skipped']++;
            return;
        }

        $bodyHtml = KnowledgeHtmlSanitizer::sanitize($bodyHtml);
        $insert = $pdo->prepare("INSERT INTO knowledge_documents (
            source_key, folder_id, title, summary, body_html, status, is_pinned, sort_order, current_version,
            draft_folder_id, draft_title, draft_summary, draft_body_html, draft_is_pinned,
            draft_updated_at, published_at
        ) VALUES (?, ?, ?, ?, ?, 'published', 0, ?, 1, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $insert->execute([
            $sourceKey, $folderId, $title, $summary, $bodyHtml, $sortOrder,
            $folderId, $title, $summary, $bodyHtml,
        ]);
        $documentId = (int) $pdo->lastInsertId();
        $revision = $pdo->prepare('INSERT INTO knowledge_document_revisions (
            document_id, version_no, folder_id, title, summary, body_html, is_pinned
        ) VALUES (?, 1, ?, ?, ?, ?, 0)');
        $revision->execute([$documentId, $folderId, $title, $summary, $bodyHtml]);
        $result['documents']++;
        $result['revisions']++;
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(string $key): array
    {
        $rows = $this->data[$key] ?? null;
        if (!is_array($rows)) {
            throw new InvalidArgumentException('В снимке корпуса отсутствует массив: ' . $key);
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Строка корпуса должна быть объектом: ' . $key);
            }
        }
        return array_values($rows);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function assertCount(array $rows, int $expected, string $label): void
    {
        if (count($rows) !== $expected) {
            throw new InvalidArgumentException("Неверное количество {$label}: ожидалось {$expected}, получено " . count($rows) . '.');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function uniqueRows(array $rows, string $key, string $label): array
    {
        $result = [];
        foreach ($rows as $row) {
            $value = $this->required($row, $key, $label);
            if (isset($result[$value])) {
                throw new InvalidArgumentException('Повторяется ключ ' . $label . ': ' . $value);
            }
            $result[$value] = $row;
        }
        return $result;
    }

    /** @param array<string, mixed> $row */
    private function required(array $row, string $key, string $label): string
    {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value === '') {
            throw new InvalidArgumentException("Не заполнено поле {$key}: {$label}.");
        }
        return $value;
    }

    private function assertHttps(string $url, string $label): void
    {
        if ($url === '' || !preg_match('#^https://#i', $url)) {
            throw new InvalidArgumentException('Ожидается прямая HTTPS-ссылка: ' . $label);
        }
    }

    /** @param array<string, mixed> $card @param array<string, mixed> $occurrence */
    private function category(array $card, array $occurrence): string
    {
        if (($card['evidence_class'] ?? '') !== 'D') {
            return 'reference';
        }
        return str_starts_with((string) ($occurrence['source_id'] ?? ''), 'REG-') ? 'regional' : 'central';
    }

    /** @return array<int, string> */
    private function expectedFolderKeys(): array
    {
        $keys = [
            self::CORPUS_KEY . ':folder:root',
            self::CORPUS_KEY . ':folder:central',
            self::CORPUS_KEY . ':folder:regional',
            self::CORPUS_KEY . ':folder:reference',
            self::CORPUS_KEY . ':folder:checklists',
        ];
        $occurrences = [];
        foreach ($this->rows('occurrences') as $row) {
            $occurrences[(string) $row['canonical_id']] = $row;
        }
        $disciplines = [];
        foreach ($this->rows('canonical') as $card) {
            $discipline = (string) $card['discipline'];
            $category = $this->category($card, $occurrences[(string) $card['canonical_id']]);
            $disciplines[$category . '|' . $discipline] = self::CORPUS_KEY . ':folder:' . $category
                . ':discipline:' . substr(hash('sha256', $discipline), 0, 16);
        }
        return [...$keys, ...array_values($disciplines)];
    }

    /** @return array<int, string> */
    private function expectedDocumentKeys(): array
    {
        $keys = [];
        foreach ($this->rows('canonical') as $card) {
            $keys[] = self::CORPUS_KEY . ':canonical:' . (string) $card['canonical_id'];
        }
        $sections = [];
        foreach ($this->rows('checklists') as $item) {
            $sections[(string) $item['section_no']] = true;
        }
        foreach (array_keys($sections) as $section) {
            $keys[] = self::CORPUS_KEY . ':checklist-section:' . $section;
        }
        $keys[] = self::CORPUS_KEY . ':checklist-catalog';
        $keys[] = self::CORPUS_KEY . ':methodology';
        return $keys;
    }

    /** @return array<int, string> */
    private function databaseSourceKeys(PDO $pdo, string $table): array
    {
        if (!in_array($table, ['knowledge_folders', 'knowledge_documents'], true)) {
            throw new RuntimeException('Unsupported GGE audit table.');
        }
        $stmt = $pdo->prepare('SELECT source_key FROM ' . $table . ' WHERE source_key LIKE ? ORDER BY source_key');
        $stmt->execute([self::CORPUS_KEY . ':%']);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<string, mixed> $card */
    private function canonicalSummary(array $card, string $category): string
    {
        $labels = [
            'central' => 'Прямое публичное замечание ГГЭ',
            'regional' => 'Региональная государственная экспертиза',
            'reference' => 'Справочный или вторичный материал',
        ];
        $parts = [$labels[$category], (string) $card['discipline']];
        if (trim((string) ($card['subdiscipline'] ?? '')) !== '') {
            $parts[] = (string) $card['subdiscipline'];
        }
        return $this->clip(implode(' · ', $parts), 600);
    }

    /** @param array<string, mixed> $card @param array<string, mixed> $occurrence */
    private function canonicalBody(array $card, array $occurrence, string $category): string
    {
        $scope = match ($category) {
            'central' => 'Центральный публичный источник Главгосэкспертизы России',
            'regional' => 'Региональная государственная экспертиза; не смешивать с центральной ГГЭ',
            default => 'Справочный или вторичный материал; не считать прямым замечанием ГГЭ',
        };
        $html = '<blockquote>' . $this->h($scope) . '</blockquote>'
            . '<h2>Исходная формулировка</h2><p>' . $this->h((string) $occurrence['original_text']) . '</p>'
            . '<h2>Нормализованная карточка</h2><p>' . $this->h((string) $card['canonical_error']) . '</p>';
        if (trim((string) ($card['required_action'] ?? '')) !== '') {
            $html .= '<h3>Что проверить или исправить</h3><p>' . $this->h((string) $card['required_action']) . '</p>';
        }
        if (trim((string) ($card['normative_basis'] ?? '')) !== '') {
            $html .= '<h3>Нормативное основание</h3><p>' . $this->h((string) $card['normative_basis']) . '</p>';
        }
        $html .= '<h3>Паспорт доказательства</h3><ul>'
            . '<li><strong>Класс:</strong> ' . $this->h((string) ($card['evidence_class'] ?? '')) . '</li>'
            . '<li><strong>Уверенность:</strong> ' . $this->h((string) ($card['confidence'] ?? '')) . '</li>'
            . '<li><strong>Источник:</strong> ' . $this->h((string) ($occurrence['source_title'] ?? '')) . '</li>'
            . '<li><strong>Дата:</strong> ' . $this->h((string) ($occurrence['source_date'] ?? '')) . '</li>';
        if (trim((string) ($occurrence['source_page'] ?? '')) !== '') {
            $html .= '<li><strong>Страница:</strong> ' . $this->h((string) $occurrence['source_page']) . '</li>';
        }
        $url = (string) $occurrence['source_url'];
        $html .= '</ul><p><a href="' . $this->h($url) . '">Открыть первоисточник</a></p>';
        return $html;
    }

    /** @param array<int, array<string, mixed>> $items */
    private function checklistBody(array $items): string
    {
        $html = '<blockquote>Пункты чек-листа — правила предварительной проверки, а не перечень типовых замечаний.</blockquote><ol>';
        foreach ($items as $item) {
            $html .= '<li><strong>' . $this->h((string) $item['item_no']) . '.</strong> ' . $this->h((string) $item['item_text']) . '</li>';
        }
        $url = (string) $items[0]['source_url'];
        return $html . '</ol><p><a href="' . $this->h($url) . '">Открыть официальный чек-лист</a></p>';
    }

    private function catalogBody(): string
    {
        $html = '<blockquote>Каталог подтверждает существование 12 чек-листов. Полные пункты опубликованы в корпусе только для одного извлечённого документа.</blockquote>'
            . '<table><thead><tr><th>№</th><th>Чек-лист</th><th>Тип</th><th>Использование</th></tr></thead><tbody>';
        foreach ($this->rows('checklist_catalog') as $row) {
            $html .= '<tr><td>' . $this->h((string) ($row['№'] ?? '')) . '</td>'
                . '<td>' . $this->h((string) ($row['Чек-лист / назначение'] ?? '')) . '</td>'
                . '<td>' . $this->h((string) ($row['Тип'] ?? '')) . '</td>'
                . '<td>' . $this->h((string) ($row['Как использовать в нашем корпусе'] ?? '')) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    /** @param array<string, int> $stats */
    private function methodologyBody(array $stats): string
    {
        return '<blockquote>Это публичная реконструкция, а не официальный внутренний каталог БТЗ Главгосэкспертизы.</blockquote>'
            . '<h2>Состав снимка</h2><ul>'
            . '<li>Канонических карточек: ' . $stats['canonical'] . '.</li>'
            . '<li>Прямых замечаний центральной ГГЭ: ' . $stats['central_direct'] . '.</li>'
            . '<li>Региональных замечаний: ' . $stats['regional_direct'] . '.</li>'
            . '<li>Справочных и вторичных карточек: ' . $stats['reference'] . '.</li>'
            . '<li>Пунктов полностью извлечённого чек-листа: ' . $stats['checklist_items'] . '.</li>'
            . '</ul><h2>Правила чтения</h2><ol>'
            . '<li>Всегда проверяйте исходную формулировку и ссылку.</li>'
            . '<li>Не переносите региональное замечание на центральную ГГЭ.</li>'
            . '<li>Не называйте пункт чек-листа типовым замечанием без независимого подтверждения.</li>'
            . '<li>Проверяйте актуальность нормативного основания на дату проекта.</li>'
            . '</ol>';
    }

    private function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function clip(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }
}
