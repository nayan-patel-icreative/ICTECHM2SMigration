<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MigrationRun;
use App\Models\Shop;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

class MigrationRunReportController extends Controller
{
    public function download(Request $request, MigrationRun $run)
    {
        $shop = $this->authorizedShop($request, $run);
        if (! $shop) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $path = $this->resolveCsvPath($run);

        if (! is_file($path)) {
            return response()->json(['message' => 'Report not found'], 404);
        }

        $name = sprintf('migration-%s-run-%d.csv', (string) $run->type, (int) $run->id);
        return response()->download($path, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function downloadPdf(Request $request, MigrationRun $run)
    {
        $shop = $this->authorizedShop($request, $run);
        if (! $shop) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! in_array((string) $run->status, ['finished', 'cancelled'], true)) {
            return response()->json(['message' => 'PDF report is available after run is finished or cancelled'], 409);
        }

        $csvPath = $this->resolveCsvPath($run);
        if (! is_file($csvPath)) {
            return response()->json(['message' => 'Report not found'], 404);
        }

        $rows = $this->readCsvRows($csvPath);
        $html = $this->buildPdfHtml($run, $shop, $rows);

        $baseDir = storage_path('app/migration-reports/shop_' . (int) $run->shop_id);
        if (! is_dir($baseDir)) {
            @mkdir($baseDir, 0775, true);
        }
        $htmlPath = $baseDir . '/run_' . (int) $run->id . '_report.html';
        $pdfPath = $baseDir . '/run_' . (int) $run->id . '_report.pdf';
        @file_put_contents($htmlPath, $html);

        $chrome = $this->chromeBinary();
        if ($chrome === null) {
            return response()->json(['message' => 'Chrome binary not available for PDF generation'], 500);
        }

        $process = new Process([
            $chrome,
            '--headless',
            '--disable-gpu',
            '--no-sandbox',
            '--print-to-pdf=' . $pdfPath,
            $htmlPath,
        ]);
        $process->setTimeout(40);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($pdfPath)) {
            return response()->json(['message' => 'Failed to generate PDF report'], 500);
        }

        $name = sprintf('migration-%s-run-%d.pdf', (string) $run->type, (int) $run->id);
        return response()->download($pdfPath, $name, ['Content-Type' => 'application/pdf']);
    }

    private function chromeBinary(): ?string
    {
        $candidates = [
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
        ];

        foreach ($candidates as $bin) {
            if (is_file($bin) && is_executable($bin)) {
                return $bin;
            }
        }

        return null;
    }

    private function authorizedShop(Request $request, MigrationRun $run): ?Shop
    {
        /** @var Shop|null $shop */
        $shop = $request->attributes->get('shop');
        if (! $shop || (int) $run->shop_id !== (int) $shop->id) {
            return null;
        }

        return $shop;
    }

    private function resolveCsvPath(MigrationRun $run): string
    {
        $path = is_string($run->report_path) ? trim($run->report_path) : '';
        if ($path !== '') {
            return $path;
        }

        return storage_path('app/migration-reports/shop_' . (int) $run->shop_id . '/run_' . (int) $run->id . '.csv');
    }

    /**
     * @return array{header: array<int,string>, rows: array<int, array<int,string>>, summary: array<string,string>}
     */
    private function readCsvRows(string $csvPath): array
    {
        $header = [];
        $rows = [];
        $summary = [];

        $fp = @fopen($csvPath, 'rb');
        if (! $fp) {
            return ['header' => $header, 'rows' => $rows, 'summary' => $summary];
        }

        while (($line = fgetcsv($fp)) !== false) {
            if (! is_array($line) || count($line) === 0) {
                continue;
            }

            $firstRaw = (string) ($line[0] ?? '');
            $firstNorm = preg_replace('/^\xEF\xBB\xBF/', '', $firstRaw) ?? $firstRaw;
            $firstNorm = trim($firstNorm);

            // Some readers keep a leading quote when BOM appears before enclosure.
            if (str_starts_with($firstNorm, '"')) {
                $firstNorm = ltrim($firstNorm, '"');
            }

            if (str_starts_with($firstNorm, '#')) {
                continue;
            }

            // Defensive skip for visual separator rows if they slip through.
            if (count($line) === 1) {
                $only = trim((string) $line[0]);
                $only = trim($only, "\"' ");
                if ($only !== '' && preg_match('/^[=\-]{6,}$/', $only)) {
                    continue;
                }
            }

            if (str_starts_with($firstNorm, '--- END OF REPORT ---')) {
                continue;
            }

            if ($firstNorm === '--- END OF REPORT ---') {
                // Parse key/value summary lines.
                while (($sLine = fgetcsv($fp)) !== false) {
                    if (! is_array($sLine) || count($sLine) < 2) {
                        continue;
                    }
                    $k = (string) $sLine[0];
                    $v = (string) $sLine[1];
                    if (str_starts_with($k, 'summary_')) {
                        $summary[$k] = $v;
                    }
                }
                break;
            }

            if ($header === []) {
                $header = array_map(static fn ($v) => (string) $v, $line);
                continue;
            }

            $rows[] = array_map(static fn ($v) => (string) $v, $line);
        }

        @fclose($fp);
        return ['header' => $header, 'rows' => $rows, 'summary' => $summary];
    }

    /**
     * @param array{header: array<int,string>, rows: array<int, array<int,string>>, summary: array<string,string>} $csv
     */
    private function buildPdfHtml(MigrationRun $run, Shop $shop, array $csv): string
    {
        $header = $csv['header'];
        $rows = $csv['rows'];
        $summary = $csv['summary'];

        $status = strtoupper((string) $run->status);
        $statusTone = match (strtolower((string) $run->status)) {
            'finished' => 'ok',
            'cancelled' => 'warn',
            'failed' => 'bad',
            default => 'info',
        };
        $startedAt = $run->started_at ? $run->started_at->toDateTimeString() : '-';
        $finishedAt = $run->finished_at ? $run->finished_at->toDateTimeString() : '-';
        $processed = (string) ($summary['summary_processed'] ?? (string) $run->processed);
        $succeeded = (string) ($summary['summary_succeeded'] ?? (string) $run->succeeded);
        $failed = (string) ($summary['summary_failed'] ?? (string) $run->failed);

        $thead = '';
        foreach ($header as $h) {
            $thead .= '<th>' . e(str_replace('_', ' ', $h)) . '</th>';
        }

        $tbody = '';
        foreach ($rows as $row) {
            $tbody .= '<tr>';
            foreach ($row as $cell) {
                $tbody .= '<td>' . e($cell) . '</td>';
            }
            $tbody .= '</tr>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><style>
            @page{size:A4;margin:22mm 14mm 20mm 14mm}
            body{font-family:"Segoe UI",Roboto,Arial,sans-serif;color:#1e293b;margin:0;background:#f8fafc}
            .sheet{background:#fff;border:1px solid #dbe6f4;border-radius:14px;padding:24px 24px 18px}
            .topline{font-size:11px;letter-spacing:.08em;color:#64748b;text-transform:uppercase}
            .title{margin-top:8px;font-size:29px;line-height:1.08;font-weight:700;color:#0b2447}
            .subtitle{font-size:12px;color:#5b6b84;margin-top:6px}
            .hero{margin-top:14px;display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
            .status{display:inline-block;padding:6px 11px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.04em}
            .status.info{background:#e0f2fe;color:#075985}
            .status.ok{background:#dcfce7;color:#166534}
            .status.warn{background:#fef3c7;color:#92400e}
            .status.bad{background:#fee2e2;color:#991b1b}
            .grid{display:grid;grid-template-columns:1.25fr 1fr;gap:12px;margin:16px 0 14px}
            .card{background:linear-gradient(180deg,#f8fbff 0%,#f1f6ff 100%);border:1px solid #d8e6fb;border-radius:12px;padding:12px 14px}
            .k{font-size:10px;letter-spacing:.05em;color:#64748b;text-transform:uppercase;margin-top:8px}
            .k:first-child{margin-top:0}
            .v{font-size:12px;color:#0f172a;font-weight:600;margin-top:2px}
            .metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:9px}
            .metric{background:#fff;border:1px solid #dbe6f4;border-radius:10px;padding:10px}
            .metric .mk{font-size:10px;letter-spacing:.04em;color:#64748b;text-transform:uppercase}
            .metric .mv{margin-top:4px;font-size:18px;font-weight:700;color:#0b2447}
            table{width:100%;border-collapse:separate;border-spacing:0;margin-top:8px;font-size:10.5px}
            th{background:#0f2a4d;color:#fff;padding:8px 7px;border-right:1px solid #1f3f68;text-transform:uppercase;letter-spacing:.03em;font-size:9.8px}
            th:first-child{border-top-left-radius:8px}
            th:last-child{border-right:0;border-top-right-radius:8px}
            td{padding:7px;border-right:1px solid #b9cde6;border-bottom:1px solid #c8d7ea;vertical-align:top;background:#fff}
            tr td:first-child{border-left:1px solid #b9cde6}
            tr td + td{border-left:1px solid #c2d3e8}
            tr th + th{border-left:1px solid #2e537e}
            tr:nth-child(even) td{background:#f8fbff}
            .summary{margin-top:14px;padding:12px;border-radius:12px;background:linear-gradient(180deg,#eff6ff 0%,#e7f0ff 100%);border:1px solid #bfdbfe}
            .summary-title{font-size:12px;font-weight:700;color:#0f2a4d}
            .summary-line{margin-top:6px;font-size:12px;color:#0f172a}
            .foot{margin-top:11px;font-size:10.5px;color:#64748b;border-top:1px dashed #cbd5e1;padding-top:8px}
        </style></head><body>
            <div class="sheet">
                <div class="topline">Migration Report</div>
                <div class="hero">
                    <div>
                        <div class="title">ICTECHS2SMigrator Statement</div>
                        <div class="subtitle">Store: ' . e((string) $shop->shop_domain) . ' | Run #' . e((string) $run->id) . ' | Type: ' . e((string) $run->type) . '</div>
                    </div>
                    <div><span class="status ' . e($statusTone) . '">Status: ' . e($status) . '</span></div>
                </div>

                <div class="grid">
                    <div class="card">
                        <div class="k">Started At (UTC)</div>
                        <div class="v">' . e($startedAt) . '</div>
                        <div class="k">Finished At (UTC)</div>
                        <div class="v">' . e($finishedAt) . '</div>
                    </div>
                    <div class="metrics">
                        <div class="metric"><div class="mk">Processed</div><div class="mv">' . e($processed) . '</div></div>
                        <div class="metric"><div class="mk">Succeeded</div><div class="mv">' . e($succeeded) . '</div></div>
                        <div class="metric"><div class="mk">Failed</div><div class="mv">' . e($failed) . '</div></div>
                    </div>
                </div>

                <table><thead><tr>' . $thead . '</tr></thead><tbody>' . $tbody . '</tbody></table>

                <div class="summary">
                    <div class="summary-title">Summary</div>
                    <div class="summary-line">Processed: ' . e($processed) . ' | Succeeded: ' . e($succeeded) . ' | Failed: ' . e($failed) . '</div>
                </div>

                <div class="foot">Generated by ICTECHS2SMigrator. This report is for operational/audit use.</div>
            </div>
        </body></html>';
    }
}
