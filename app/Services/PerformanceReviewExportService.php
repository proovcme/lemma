<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class PerformanceReviewExportService
{
    public function downloadManager(array $report): never
    {
        $rows = [['Сотрудник', 'Отдел', 'Цикл', 'Период', 'Статус', 'Состояние руководителя', 'Самооценка', 'Оценка руководителя', 'Цель', 'Расхождение', 'Отклонение от цели']];
        foreach ((array) ($report['reviews'] ?? []) as $review) {
            $averages = (array) ($review['averages'] ?? []);
            $rows[] = [
                $review['employee_name'] ?? '',
                $review['employee_department'] ?? '',
                $review['cycle_title'] ?? '',
                trim((string) ($review['period_start'] ?? '') . ' — ' . (string) ($review['period_end'] ?? '')),
                PerformanceReviewService::REVIEW_STATUSES[$review['status'] ?? ''] ?? ($review['status'] ?? ''),
                $this->managerStateLabel((string) ($review['manager_state'] ?? 'waiting')),
                $averages['self'] ?? '',
                $averages['manager'] ?? '',
                $averages['target'] ?? '',
                $averages['delta'] ?? '',
                $averages['target_gap'] ?? '',
            ];
        }
        $this->download(['Реестр оценок' => $rows], 'performance-review-manager-report.xlsx');
    }

    public function downloadCycle(array $summary): never
    {
        $cycle = (array) ($summary['cycle'] ?? []);
        $participantRows = [['Сотрудник', 'Отдел', 'Должность', 'Грейд', 'Руководитель', 'Статус', 'Самооценка', 'Оценка руководителя', 'Цель', 'Расхождение', 'Отклонение от цели', 'Комментарий руководителя (этап 3)', 'Шаги на следующий год']];
        foreach ((array) ($summary['participants'] ?? []) as $row) {
            $avg = (array) ($row['averages'] ?? []);
            $participantRows[] = [
                $row['employee_name'] ?? '', $row['employee_department'] ?? '', $row['position_title_snapshot'] ?? '',
                $row['position_grade_snapshot'] ?? '', $row['manager_name'] ?? '',
                PerformanceReviewService::REVIEW_STATUSES[$row['status'] ?? ''] ?? ($row['status'] ?? ''),
                $avg['self'] ?? '', $avg['manager'] ?? '', $avg['target'] ?? '', $avg['delta'] ?? '', $avg['target_gap'] ?? '',
                $row['meeting_notes'] ?? '', $row['next_year_actions'] ?? '',
            ];
        }
        $competencyRows = [['Компетенция', 'Пар оценок', 'Средняя самооценка', 'Средняя оценка руководителя', 'Средняя цель', 'Расхождение', 'Ниже цели']];
        foreach ((array) ($summary['competencies'] ?? []) as $row) {
            $competencyRows[] = [$row['name'] ?? '', $row['paired_count'] ?? 0, $row['avg_self'] ?? '', $row['avg_manager'] ?? '', $row['avg_target'] ?? '', $row['delta'] ?? '', $row['below_target'] ?? 0];
        }
        $this->download([
            'Участники' => $participantRows,
            'Компетенции' => $competencyRows,
        ], 'performance-review-cycle-' . (int) ($cycle['id'] ?? 0) . '.xlsx');
    }

    public function downloadReview(array $review): never
    {
        if (empty($review['self_matrix_submitted_at']) || empty($review['manager_matrix_submitted_at'])) {
            throw new RuntimeException('Экспорт откроется после завершения обеих независимых оценок.');
        }
        $summaryRows = [
            ['Поле', 'Значение'],
            ['Сотрудник', $review['employee_name'] ?? ''],
            ['Должность', $review['position_title_snapshot'] ?? ''],
            ['Грейд', $review['position_grade_snapshot'] ?? ''],
            ['Цикл', $review['cycle_title'] ?? ''],
            ['Тип цикла', PerformanceReviewService::CYCLE_KINDS[$review['cycle_kind'] ?? 'annual'] ?? ''],
            ['Руководитель', $review['manager_name'] ?? ''],
            ['Статус', PerformanceReviewService::REVIEW_STATUSES[$review['status'] ?? ''] ?? ($review['status'] ?? '')],
            ['Итоги встречи', $review['meeting_notes'] ?? ''],
            ['Шаги на следующий год', $review['next_year_actions'] ?? ''],
        ];
        $answerRows = [['Раздел', 'Вопрос', 'Ответ сотрудника']];
        foreach ((array) ($review['questions'] ?? []) as $question) {
            if (!in_array((string) ($question['answer_scope'] ?? 'self'), ['self', 'both'], true)) continue;
            $id = (int) ($question['id'] ?? 0);
            $answerRows[] = [$question['section_label'] ?? 'Анкета', $question['label'] ?? '', $review['answers'][$id]['self']['answer_value'] ?? ''];
        }
        $competencyRows = [['Компетенция', 'Цель', 'Самооценка', 'Руководитель', 'Расхождение', 'Комментарий сотрудника', 'Комментарий руководителя']];
        foreach ((array) ($review['competency_matrix']['competencies'] ?? []) as $key => $competency) {
            $self = (array) ($review['competency_scores'][(string) $key]['self'] ?? []);
            $manager = (array) ($review['competency_scores'][(string) $key]['manager'] ?? []);
            $target = $manager['required_level_snapshot'] ?? $self['required_level_snapshot'] ?? '';
            $delta = isset($self['score'], $manager['score']) ? (int) $self['score'] - (int) $manager['score'] : '';
            $competencyRows[] = [$competency['name'] ?? '', $target, $self['score'] ?? '', $manager['score'] ?? '', $delta, $self['comment'] ?? '', $manager['comment'] ?? ''];
        }
        $this->download(['Итоги' => $summaryRows, 'Анкета' => $answerRows, 'Компетенции' => $competencyRows], 'performance-review-' . (int) ($review['id'] ?? 0) . '.xlsx');
    }

    private function download(array $sheets, string $filename): never
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $this->downloadMinimal($sheets, $filename);
        }
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheetIndex = 0;
        foreach ($sheets as $title => $rows) {
            $sheet = $sheetIndex === 0 ? $book->getActiveSheet() : $book->createSheet();
            $sheet->setTitle(mb_substr((string) $title, 0, 31));
            $sheet->fromArray($rows, null, 'A1');
            $lastColumn = $this->columnName(max(1, count((array) ($rows[0] ?? []))));
            $lastRow = max(1, count($rows));
            $sheet->freezePane('A2');
            $sheet->setAutoFilter('A1:' . $lastColumn . $lastRow);
            $sheet->getStyle('A1:' . $lastColumn . '1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '8F1624']],
            ]);
            $sheet->getStyle('A1:' . $lastColumn . $lastRow)->getAlignment()->setVertical('top')->setWrapText(true);
            for ($column = 1; $column <= count((array) ($rows[0] ?? [])); $column++) {
                $sheet->getColumnDimension($this->columnName($column))->setAutoSize(true);
            }
            $sheetIndex++;
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
        exit;
    }

    /**
     * Standalone has ZipArchive but deliberately does not ship Composer vendor.
     * Keep exports available there with a small multi-sheet XLSX writer.
     */
    private function downloadMinimal(array $sheets, string $filename): never
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('Для выгрузки XLSX требуется расширение PHP zip.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'locia-performance-review-');
        if ($tmp === false) {
            throw new RuntimeException('Не удалось создать временный XLSX-файл.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Не удалось сформировать XLSX-файл.');
        }

        $sheetNames = array_keys($sheets);
        $contentTypes = '';
        $workbookSheets = '';
        $relationships = '';
        foreach ($sheetNames as $index => $title) {
            $number = $index + 1;
            $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $number . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbookSheets .= '<sheet name="' . $this->xml(mb_substr((string) $title, 0, 31)) . '" sheetId="' . $number . '" r:id="rId' . $number . '"/>';
            $relationships .= '<Relationship Id="rId' . $number . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $number . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $number . '.xml', $this->sheetXml((array) $sheets[$title]));
        }
        $stylesRelationshipId = count($sheetNames) + 1;

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $contentTypes . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $workbookSheets . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relationships . '<Relationship Id="rId' . $stylesRelationshipId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font/><font><b/><color rgb="FFFFFFFF"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF8F1624"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf xfId="0"/><xf xfId="0" fontId="1" fillId="2" applyFont="1" applyFill="1"/></cellXfs></styleSheet>');
        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . (string) filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    private function sheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $xml .= '<row r="' . $rowNumber . '">';
            foreach (array_values((array) $row) as $columnIndex => $value) {
                $cell = $this->columnName($columnIndex + 1) . $rowNumber;
                if ($rowIndex > 0 && is_numeric($value) && $value !== '') {
                    $xml .= '<c r="' . $cell . '"><v>' . (float) $value . '</v></c>';
                } else {
                    $style = $rowIndex === 0 ? ' s="1"' : '';
                    $xml .= '<c r="' . $cell . '" t="inlineStr"' . $style . '><is><t>' . $this->xml((string) $value) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        return $xml . '</sheetData></worksheet>';
    }

    private function xml(string $value): string
    {
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '', ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function managerStateLabel(string $state): string
    {
        return [
            'waiting' => 'Ожидает сотрудника',
            'ready' => 'Можно оценивать',
            'meeting_ready' => 'Нужна итоговая встреча',
            'done' => 'Завершено',
        ][$state] ?? $state;
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }
        return $name;
    }
}
