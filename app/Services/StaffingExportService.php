<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class StaffingExportService
{
    public function download(array $dashboard): never
    {
        $period = $dashboard['period'];
        $filename = 'staffing-' . substr((string) $period['month_start'], 0, 7) . '-r' . (int) $period['revision'] . '.xlsx';
        if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $this->downloadWithPhpSpreadsheet($dashboard, $filename);
        }
        $this->downloadMinimal($dashboard, $filename);
    }

    private function downloadWithPhpSpreadsheet(array $dashboard, string $filename): never
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $summary = $book->getActiveSheet();
        $summary->setTitle('Средние по разделам');
        $summaryRows = $this->summaryRows($dashboard);
        $summary->fromArray($summaryRows, null, 'A1');
        $staff = $book->createSheet();
        $staff->setTitle('Штатное расписание');
        $staffRows = $this->staffRows($dashboard);
        $staff->fromArray($staffRows, null, 'A1');

        foreach ([[$summary, count($summaryRows), 8], [$staff, count($staffRows), 14]] as [$sheet, $rowCount, $columnCount]) {
            $sheet->freezePane('A2');
            $sheet->setAutoFilter('A1:' . $this->columnName($columnCount) . max(1, $rowCount));
            $sheet->getStyle('A1:' . $this->columnName($columnCount) . '1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '8F1624']],
                'alignment' => ['vertical' => 'center'],
            ]);
            $sheet->getStyle('A2:' . $this->columnName($columnCount) . max(2, $rowCount))->getAlignment()->setVertical('top');
            $sheet->getStyle('A2:' . $this->columnName($columnCount) . max(2, $rowCount))->getBorders()->getBottom()->setBorderStyle('hair');
            for ($column = 1; $column <= $columnCount; $column++) {
                $sheet->getColumnDimension($this->columnName($column))->setAutoSize(true);
            }
        }
        $summary->getStyle('C2:H' . max(2, count($summaryRows)))->getNumberFormat()->setFormatCode('#,##0.00');
        $staff->getStyle('F2:I' . max(2, count($staffRows)))->getNumberFormat()->setFormatCode('#,##0.00');
        $staff->getStyle('J2:J' . max(2, count($staffRows)))->getNumberFormat()->setFormatCode('0.00');

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
        exit;
    }

    private function summaryRows(array $dashboard): array
    {
        $rows = [['Раздел', 'Позиций', 'Ставок', 'ФОТ руб/мес', 'Средняя руб/мес', 'Средняя руб/день', 'Средняя руб/час', 'Состав раздела']];
        foreach ($dashboard['groups'] as $group) {
            $composition = [];
            foreach ($dashboard['rows'] as $row) {
                if ($row['department_code'] === $group['department_code'] && $row['status'] !== 'reduction') {
                    $composition[] = $row['position_title'];
                }
            }
            $rows[] = [
                $group['department_code'],
                (int) $group['positions_count'],
                (float) $group['total_fte'],
                (float) $group['total_fot'],
                (float) $group['avg_monthly'],
                (float) $group['avg_daily'],
                (float) $group['avg_hourly'],
                implode(', ', array_values(array_unique($composition))),
            ];
        }
        return $rows;
    }

    private function staffRows(array $dashboard): array
    {
        $period = $dashboard['period'];
        $rows = [['Раздел', 'Структурное подразделение', 'Должность', 'ФИО / позиция', 'Таб. №', 'ФОТ руб/мес', 'Ставка руб/день', 'Ставка руб/час', 'Полный бюджет руб/мес', 'Ставок', 'Статус', 'Изменение', 'Сумма изменения', 'Комментарий']];
        foreach ($dashboard['rows'] as $row) {
            $fte = max(0.01, (float) $row['fte']);
            $monthly = (float) $row['monthly_fot'];
            $factor = 1 + ((float) $period['payroll_burden_pct'] + (float) $period['overhead_pct']) / 100;
            $rows[] = [
                $row['department_code'],
                $row['group_name'] ?: $row['department_name'],
                $row['position_title'],
                $row['employee_name'],
                $row['tab_number'],
                $monthly,
                round($monthly / ((float) $period['working_days'] * $fte), 2),
                round($monthly / ((float) $period['working_hours'] * $fte), 2),
                $row['status'] === 'reduction' ? 0 : round($monthly * $factor, 2),
                $fte,
                $row['status'],
                $row['change_type'],
                $row['change_amount'],
                $row['comment'],
            ];
        }
        return $rows;
    }

    private function downloadMinimal(array $dashboard, string $filename): never
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('Для выгрузки XLSX требуется расширение PHP zip.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'locia-staffing-');
        if ($tmp === false) {
            throw new RuntimeException('Не удалось создать временный XLSX-файл.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Не удалось сформировать XLSX-файл.');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Средние по разделам" sheetId="1" r:id="rId1"/><sheet name="Штатное расписание" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font/><font><b/><color rgb="FFFFFFFF"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF8F1624"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf xfId="0"/><xf xfId="0" fontId="1" fillId="2" applyFont="1" applyFill="1"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml($this->summaryRows($dashboard)));
        $zip->addFromString('xl/worksheets/sheet2.xml', $this->sheetXml($this->staffRows($dashboard)));
        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
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
            $number = $rowIndex + 1;
            $xml .= '<row r="' . $number . '">';
            foreach (array_values($row) as $columnIndex => $value) {
                $ref = $this->columnName($columnIndex + 1) . $number;
                if ($rowIndex > 0 && is_numeric($value) && $value !== '') {
                    $xml .= '<c r="' . $ref . '"><v>' . (float) $value . '</v></c>';
                } else {
                    $style = $rowIndex === 0 ? ' s="1"' : '';
                    $xml .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t>' . $this->xml((string) $value) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        return $xml . '</sheetData></worksheet>';
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

    private function xml(string $value): string
    {
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '', ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
