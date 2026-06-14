<?php

namespace App\Services\Reports;

use Symfony\Component\HttpFoundation\Response;
use RuntimeException;

class ReportExportService
{
    public function __construct(private NativeXlsxExporter $xlsxExporter) {}

    public function download(array $report, string $format, string $orientation = 'landscape'): Response
    {
        $format = strtolower($format);
        $filename = $this->filename($report, $format);

        if ($format === 'xlsx') {
            $path = $this->xlsxExporter->export($report);
            return response()->download($path, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        if ($format === 'pdf') {
            if (!app()->bound('dompdf.wrapper')) {
                throw new RuntimeException('PDF export requires barryvdh/laravel-dompdf. Run: composer require barryvdh/laravel-dompdf');
            }

            $pdf = app('dompdf.wrapper');
            $pdf->loadView('reports.enterprise-report', [
                'report' => $report,
                'orientation' => $orientation,
            ])->setPaper('a4', $orientation === 'portrait' ? 'portrait' : 'landscape');

            return $pdf->download($filename);
        }

        throw new RuntimeException('Unsupported export format. Use xlsx or pdf.');
    }

    private function filename(array $report, string $extension): string
    {
        $key = preg_replace('/[^a-z0-9\-_]+/i', '-', (string)($report['key'] ?? 'pos-report')) ?: 'pos-report';
        return strtolower($key) . '-' . now()->format('Ymd-His') . '.' . $extension;
    }
}
