<?php

namespace App\Services\Products;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Minimal, dependency-free reader for the first worksheet of an .xlsx file.
 *
 * This mirrors App\Services\Reports\NativeXlsxExporter (which hand-builds
 * OOXML without PhpSpreadsheet/Maatwebsite) so product import can accept
 * .xlsx uploads without adding a new Composer dependency. It supports the
 * common cell encodings Excel/Google Sheets/LibreOffice produce: shared
 * strings, inline strings, numeric values, and string formula results.
 */
class NativeXlsxReader
{
    /**
     * @return array<int, array<int, string>> rows of cell values, indexed
     *         from column 0 (A). Rows/columns are 0-indexed regardless of
     *         how far into the sheet the first populated cell is.
     */
    public function read(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive extension is required to read XLSX files.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open the uploaded XLSX file. It may be corrupted.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException('The uploaded XLSX file does not contain a readable worksheet.');
        }

        return $this->parseSheet($sheetXml, $sharedStrings);
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false || trim($xml) === '') {
            return [];
        }

        $doc = @simplexml_load_string($xml);
        if (!$doc instanceof SimpleXMLElement) {
            return [];
        }

        $strings = [];
        foreach ($doc->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
                continue;
            }

            // Rich text runs: concatenate each <r><t>...</t></r>.
            $text = '';
            foreach ($si->r as $run) {
                $text .= (string) $run->t;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    private function parseSheet(string $xml, array $sharedStrings): array
    {
        $doc = @simplexml_load_string($xml);
        if (!$doc instanceof SimpleXMLElement || !isset($doc->sheetData)) {
            throw new RuntimeException('The uploaded XLSX worksheet could not be parsed.');
        }

        $rows = [];

        foreach ($doc->sheetData->row as $rowEl) {
            $cellValues = [];
            $maxCol = -1;

            foreach ($rowEl->c as $cellEl) {
                $ref = (string) $cellEl['r'];
                $colIndex = $ref !== '' ? $this->columnIndexFromRef($ref) : (count($cellValues));
                $type = (string) $cellEl['t'];

                if ($type === 's') {
                    $idx = (int) $cellEl->v;
                    $value = $sharedStrings[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string) ($cellEl->is->t ?? '');
                } else {
                    // Numeric, boolean, or plain string formula result.
                    $value = (string) $cellEl->v;
                }

                $cellValues[$colIndex] = $value;
                $maxCol = max($maxCol, $colIndex);
            }

            $row = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $row[] = $cellValues[$i] ?? '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function columnIndexFromRef(string $ref): int
    {
        $letters = preg_replace('/[^A-Za-z]/', '', $ref) ?? '';
        $index = 0;
        foreach (str_split($letters) as $char) {
            $index = $index * 26 + (ord(strtoupper($char)) - 64);
        }

        return max(0, $index - 1);
    }
}
