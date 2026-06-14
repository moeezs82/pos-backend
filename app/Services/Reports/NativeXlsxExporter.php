<?php

namespace App\Services\Reports;

use RuntimeException;
use ZipArchive;

class NativeXlsxExporter
{
    public function export(array $report): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive extension is required to generate XLSX exports.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pos_report_');
        if ($tmp === false) {
            throw new RuntimeException('Unable to create temporary XLSX file.');
        }

        $file = $tmp . '.xlsx';
        rename($tmp, $file);

        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to open XLSX archive for writing.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheet($report));
        $zip->close();

        return $file;
    }

    private function worksheet(array $report): string
    {
        $columns = $report['columns'] ?? [];
        $rows = $report['rows'] ?? [];
        $filters = $report['filters'] ?? [];
        $totals = $report['totals'] ?? [];
        $title = (string)($report['title'] ?? 'POS Report');
        $generatedAt = (string)($report['generated_at'] ?? now()->toDateTimeString());

        $sheetRows = [];
        $r = 1;
        $lastCol = max(1, count($columns));
        $sheetRows[] = $this->row($r++, [['value' => $title, 'style' => 1, 'type' => 's']]);
        $sheetRows[] = $this->row($r++, [['value' => 'Generated At', 'style' => 2, 'type' => 's'], ['value' => $generatedAt, 'type' => 's']]);

        foreach (['from', 'to', 'customer_id', 'vendor_id', 'product_id', 'category_id', 'brand_id', 'salesman_id', 'delivery_boy_id', 'status', 'method', 'sale_type'] as $filterKey) {
            if (array_key_exists($filterKey, $filters) && $filters[$filterKey] !== null && $filters[$filterKey] !== '') {
                $sheetRows[] = $this->row($r++, [
                    ['value' => ucwords(str_replace('_', ' ', $filterKey)), 'style' => 2, 'type' => 's'],
                    ['value' => (string)$filters[$filterKey], 'type' => 's'],
                ]);
            }
        }

        $r++;
        $headerCells = [];
        foreach ($columns as $column) {
            $headerCells[] = ['value' => $column['label'] ?? $column['key'], 'style' => 1, 'type' => 's'];
        }
        $sheetRows[] = $this->row($r++, $headerCells);

        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $key = $column['key'];
                $value = data_get($row, $key, '');
                $cells[] = $this->cellValue($value);
            }
            $sheetRows[] = $this->row($r++, $cells);
        }

        if (!empty($totals)) {
            $r++;
            $sheetRows[] = $this->row($r++, [['value' => 'Totals', 'style' => 1, 'type' => 's']]);
            foreach ($totals as $key => $value) {
                if (is_array($value)) {
                    continue;
                }
                $sheetRows[] = $this->row($r++, [
                    ['value' => ucwords(str_replace('_', ' ', (string)$key)), 'style' => 2, 'type' => 's'],
                    $this->cellValue($value),
                ]);
            }
        }

        $merge = $lastCol > 1 ? '<mergeCells count="1"><mergeCell ref="A1:' . $this->col($lastCol) . '1"/></mergeCells>' : '';
        $dimension = 'A1:' . $this->col($lastCol) . max(1, $r);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<dimension ref="' . $dimension . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . max(1, $r - count($rows) - 1) . '" topLeftCell="A' . max(2, $r - count($rows)) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
            . $merge
            . '</worksheet>';
    }

    private function row(int $rowNumber, array $cells): string
    {
        $xml = '<row r="' . $rowNumber . '">';
        foreach ($cells as $index => $cell) {
            $ref = $this->col($index + 1) . $rowNumber;
            $style = isset($cell['style']) ? ' s="' . (int)$cell['style'] . '"' : '';
            $value = $cell['value'] ?? '';
            $type = $cell['type'] ?? (is_numeric($value) ? 'n' : 's');

            if ($value === null) {
                $value = '';
            }

            if ($type === 'n' && $value !== '') {
                $xml .= '<c r="' . $ref . '"' . $style . '><v>' . $this->num($value) . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t>' . $this->e((string)$value) . '</t></is></c>';
            }
        }
        return $xml . '</row>';
    }

    private function cellValue(mixed $value): array
    {
        if ($value === null) {
            return ['value' => '', 'type' => 's'];
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value) && !str_starts_with($value, '0'))) {
            return ['value' => $value, 'type' => 'n'];
        }
        if (is_bool($value)) {
            return ['value' => $value ? 'Yes' : 'No', 'type' => 's'];
        }
        if (is_array($value) || is_object($value)) {
            return ['value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'type' => 's'];
        }
        return ['value' => (string)$value, 'type' => 's'];
    }

    private function col(int $index): string
    {
        $letters = '';
        while ($index > 0) {
            $index--;
            $letters = chr(65 + ($index % 26)) . $letters;
            $index = intdiv($index, 26);
        }
        return $letters;
    }

    private function num(mixed $value): string
    {
        return rtrim(rtrim(number_format((float)$value, 6, '.', ''), '0'), '.');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function rels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '</styleSheet>';
    }
}
